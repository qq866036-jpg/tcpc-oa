#!/usr/bin/env python3
"""中传网 · 收文 OCR 本地服务（OA-2463）。

单文件、标准库 HTTP 服务，**只监听回环地址**，给 WordPress 插件提供两个端点：

    GET  /health   → {"ok":true,"engine":"…","engine_version":"…","models_ready":true}
    POST /ocr      → 请求 {"path":"<存储根内绝对路径>","engine":"rapidocr","request_id":"…","pages":0}
                     成功 {"ok":true,"text":"…","confidence":93.2,"engine":"rapidocr",
                           "engine_version":"1.4.4","ms":1234,"pages":2,"lines":57}
                     失败 {"ok":false,"code":"…","error":"<给人看的原因>"}（HTTP 400/403/503）

安全边界（与 PHP 侧 `ocr-client.php` 同口径，双端都校验）：
  · 只绑定 127.0.0.1（默认），端口 8848；公网不可达。
  · `TCPC_OCR_ROOTS` 指定允许读取的存储根（冒号分隔多个）；`path` 必须 realpath 落在根内。
  · 扩展名白名单 + 单文件 ≤ 20MB。
  · 可选共享令牌 `TCPC_OCR_TOKEN`（请求头 `X-TCPC-OCR-Token`，常量时间比较）。
  · 只记「文件名/大小/耗时/页数」到 stderr（journal），**不记识别文本、不记完整路径**。

引擎：默认 RapidOCR（ONNX Runtime，CPU；模型为 PP-OCR 系列的中文识别/检测模型）。
换引擎只需实现 `Engine.scan()` 并改 `build_engine()` —— HTTP 契约不变。

用法：
    python3 app.py                    # 前台（开发）
    TCPC_OCR_ROOTS=… python3 app.py
"""

from __future__ import annotations

import hmac
import json
import os
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse

HOST = os.environ.get("TCPC_OCR_HOST", "127.0.0.1")
PORT = int(os.environ.get("TCPC_OCR_PORT", "8848"))
TOKEN = os.environ.get("TCPC_OCR_TOKEN", "")
ROOTS = [p for p in os.environ.get("TCPC_OCR_ROOTS", "").split(":") if p]
MAX_BYTES = int(os.environ.get("TCPC_OCR_MAX_BYTES", str(20 * 1024 * 1024)))
EXTS = {".pdf", ".jpg", ".jpeg", ".png", ".tif", ".tiff", ".bmp", ".webp"}
PDF_DPI = int(os.environ.get("TCPC_OCR_PDF_DPI", "200"))


def log(msg: str) -> None:
    """只输出运维元数据（不输出识别文本）。"""
    sys.stderr.write("[%s] %s\n" % (time.strftime("%Y-%m-%d %H:%M:%S"), msg))
    sys.stderr.flush()


class OcrError(Exception):
    def __init__(self, code: str, message: str, status: int = 400) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.status = status


class Engine:
    """RapidOCR 封装（懒加载模型；线程安全初始化）。"""

    name = "rapidocr"

    def __init__(self) -> None:
        self._engine = None
        self._lock = threading.Lock()
        self.version = ""
        self.error = ""

    def _load(self):
        if self._engine is not None:
            return self._engine
        with self._lock:
            if self._engine is not None:
                return self._engine
            try:
                from importlib.metadata import version as _v

                try:
                    self.version = _v("rapidocr_onnxruntime")
                except Exception:
                    try:
                        self.version = _v("rapidocr")
                    except Exception:
                        self.version = "unknown"
                try:  # 旧包名（<=1.4.x）
                    from rapidocr_onnxruntime import RapidOCR

                    self._engine = RapidOCR()
                except ImportError:  # 新包名（>=2.x）
                    from rapidocr import RapidOCR

                    self._engine = RapidOCR()
                log("engine loaded: %s %s" % (self.name, self.version))
            except Exception as exc:  # 模型/依赖缺失：不崩，健康检查里报原因
                self.error = "%s: %s" % (type(exc).__name__, exc)
                log("engine load failed: %s" % self.error)
                raise OcrError("engine_unavailable", "OCR 引擎未就绪：" + self.error, 503)
        return self._engine

    @staticmethod
    def _run(engine, image_path: str):
        """兼容两代 API：旧版返回 (result, elapse)，新版返回带 .txts/.scores 的对象。"""
        out = engine(image_path)
        if isinstance(out, tuple) and len(out) == 2:
            result, _elapse = out
            lines = []
            for item in result or []:
                if isinstance(item, (list, tuple)) and len(item) >= 3:
                    lines.append((str(item[1]), float(item[2])))
            return lines
        lines = []
        txts = getattr(out, "txts", None)
        scores = getattr(out, "scores", None)
        if txts is not None:
            for idx, text in enumerate(txts):
                score = float(scores[idx]) if scores is not None and idx < len(scores) else 0.0
                lines.append((str(text), score))
            return lines
        raise OcrError("engine_bad_output", "引擎返回结构无法解析（既非 tuple 也无 txts）。", 500)

    def scan(self, path: Path) -> dict:
        started = time.time()
        engine = self._load()
        images = self._render(path)
        texts: list[str] = []
        lines = 0
        score_sum = 0.0
        score_n = 0
        for image_path in images:
            for text, score in self._run(engine, image_path):
                if not text:
                    continue
                texts.append(text)
                lines += 1
                score_sum += score
                score_n += 1
        confidence = round(score_sum / score_n * 100, 2) if score_n else None
        return {
            "text": "\n".join(texts),
            "confidence": confidence,
            "engine": self.name,
            "engine_version": self.version,
            "ms": int((time.time() - started) * 1000),
            "pages": len(images),
            "lines": lines,
        }

    @staticmethod
    def _render(path: Path) -> list[str]:
        """PDF → 逐页 PNG；图片 → 原路径。渲染失败抛 OcrError。"""
        if path.suffix.lower() != ".pdf":
            return [str(path)]
        try:
            import pypdfium2 as pdfium
        except Exception as exc:
            raise OcrError("pdf_renderer_missing", "缺少 PDF 渲染依赖 pypdfium2（pip install pypdfium2）。") from exc
        try:
            doc = pdfium.PdfDocument(str(path))
        except Exception as exc:
            raise OcrError("pdf_open_failed", "PDF 打不开（可能加密或损坏）：%s" % exc) from exc
        pages = []
        try:
            total = len(doc)
            limit = total if os.environ.get("TCPC_OCR_MAX_PAGES", "") == "" else int(os.environ["TCPC_OCR_MAX_PAGES"])
            for idx in range(min(total, limit)):
                page = doc[idx]
                bitmap = page.render(scale=PDF_DPI / 72.0)
                image = bitmap.to_pil()
                tmp = Path("/dev/shm") / ("tcpc-ocr-%d-%d.png" % (os.getpid(), idx))
                image.save(str(tmp), "PNG")
                pages.append(str(tmp))
        finally:
            try:
                doc.close()
            except Exception:
                pass
        if not pages:
            raise OcrError("pdf_empty", "PDF 没有可识别的页。")
        return pages


class PaddleEngine(Engine):
    """PaddleOCR 后端（**调研首选**：star 最高、中文公文质量最好、Apache-2.0）。

    未实测说明：本机（宝塔 CentOS + py3.13）未装 paddlepaddle，故本适配器**未在真机跑过**；
    RapidOCR 是同一契约下的保底后端（已实测可加载）。装好 paddlepaddle 后设
    `TCPC_OCR_ENGINE=paddleocr` 即可切过来；若导入或调用形态不符，服务会返回
    `engine_unavailable` / `engine_bad_output` 并在日志里给出原因（不会静默出错）。
    """

    name = "paddleocr"

    def _load(self):
        if self._engine is not None:
            return self._engine
        with self._lock:
            if self._engine is not None:
                return self._engine
            try:
                from importlib.metadata import version as _v

                self.version = _v("paddleocr")
            except Exception:
                self.version = "unknown"
            try:
                from paddleocr import PaddleOCR

                self._engine = PaddleOCR(use_doc_orientation_classify=False, use_doc_unwarping=False)
                log("engine loaded: paddleocr %s" % self.version)
            except Exception as exc:
                self.error = "%s: %s" % (type(exc).__name__, exc)
                log("engine load failed: %s" % self.error)
                raise OcrError("engine_unavailable", "PaddleOCR 未就绪：" + self.error, 503)
        return self._engine

    def _lines(self, image_path: str) -> list:
        engine = self._engine
        # v3：predict() 返回 OCRResult 列表（.json 里有 rec_texts/rec_scores）
        if hasattr(engine, "predict"):
            out = engine.predict(str(image_path))
            lines = []
            for item in out or []:
                data = getattr(item, "json", None)
                if isinstance(data, dict):
                    data = data.get("res", data)
                    texts = data.get("rec_texts") or []
                    scores = data.get("rec_scores") or []
                    for idx, text in enumerate(texts):
                        score = float(scores[idx]) if idx < len(scores) else 0.0
                        lines.append((str(text), score))
            return lines
        # v2：ocr(img, cls=True) → [[[box, (text, score)], ...]]
        out = engine.ocr(str(image_path), cls=True)
        lines = []
        for page in out or []:
            for item in page or []:
                if isinstance(item, (list, tuple)) and len(item) >= 2 and isinstance(item[1], (list, tuple)):
                    text, score = item[1][0], item[1][1]
                    lines.append((str(text), float(score)))
        return lines

    def scan(self, path: Path) -> dict:
        started = time.time()
        self._load()
        images = self._render(path)
        texts: list[str] = []
        lines = 0
        score_sum = 0.0
        score_n = 0
        for image_path in images:
            for text, score in self._lines(image_path):
                if not text:
                    continue
                texts.append(text)
                lines += 1
                score_sum += score
                score_n += 1
        if lines == 0:
            raise OcrError("engine_bad_output", "PaddleOCR 返回 0 行文本（形态不符或图片无文字）。", 500)
        confidence = round(score_sum / score_n * 100, 2) if score_n else None
        return {
            "text": "\n".join(texts),
            "confidence": confidence,
            "engine": self.name,
            "engine_version": self.version,
            "ms": int((time.time() - started) * 1000),
            "pages": len(images),
            "lines": lines,
        }


ENGINES = {"rapidocr": Engine, "paddleocr": PaddleEngine}


def build_engine():
    """按 `TCPC_OCR_ENGINE` 选后端；未指定时**优先 PaddleOCR**（调研首选），装不上再退 RapidOCR。"""
    choice = os.environ.get("TCPC_OCR_ENGINE", "").strip().lower()
    if choice in ENGINES:
        return ENGINES[choice]()
    try:
        import paddleocr  # noqa: F401

        return PaddleEngine()
    except Exception:
        return Engine()


ENGINE = build_engine()



def _check_path(raw: str) -> Path:
    if not raw:
        raise OcrError("missing_path", "缺少 path 字段。")
    if not ROOTS:
        raise OcrError("server_misconfigured", "服务未配置 TCPC_OCR_ROOTS（允许读取的存储根）。", 500)
    candidate = Path(raw)
    try:
        real = candidate.resolve(strict=True)
    except FileNotFoundError as exc:
        raise OcrError("file_missing", "文件不存在：%s" % candidate.name) from exc
    except OSError as exc:
        raise OcrError("path_unresolvable", "路径无法解析：%s" % exc) from exc
    if not real.is_file():
        raise OcrError("not_a_file", "path 不是文件。")
    inside = False
    for root in ROOTS:
        try:
            root_real = Path(root).resolve(strict=True)
        except OSError:
            continue
        if root_real == real or root_real in real.parents:
            inside = True
            break
    if not inside:
        raise OcrError("path_outside_root", "文件不在允许的存储根内，拒绝识别。", 403)
    if real.suffix.lower() not in EXTS:
        raise OcrError("ext_unsupported", "不支持的文件类型：%s" % real.suffix)
    size = real.stat().st_size
    if size <= 0:
        raise OcrError("file_empty", "空文件。")
    if size > MAX_BYTES:
        raise OcrError("file_too_large", "文件超过上限 %d 字节。" % MAX_BYTES, 413)
    return real


class Handler(BaseHTTPRequestHandler):
    server_version = "tcpc-ocr/1"
    protocol_version = "HTTP/1.1"

    def _json(self, payload: dict, status: int = 200) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _authorized(self) -> bool:
        if not TOKEN:
            return True
        got = self.headers.get("X-TCPC-OCR-Token", "")
        return hmac.compare_digest(got, TOKEN)

    def log_message(self, fmt, *a):  # 覆盖默认访问日志：不落 URL 查询串/路径
        log("http %s" % (fmt % a))

    def do_GET(self):  # noqa: N802
        if urlparse(self.path).path != "/health":
            self._json({"ok": False, "code": "not_found", "error": "只有 /health 与 /ocr。"}, 404)
            return
        models_ready = True
        try:
            ENGINE._load()
            models_ready = True
        except OcrError:
            models_ready = False
        self._json({
            "ok": True,
            "engine": ENGINE.name,
            "engine_version": ENGINE.version,
            "models_ready": models_ready,
            "error": ENGINE.error,
        })

    def do_POST(self):  # noqa: N802
        if urlparse(self.path).path != "/ocr":
            self._json({"ok": False, "code": "not_found", "error": "只有 /health 与 /ocr。"}, 404)
            return
        if not self._authorized():
            self._json({"ok": False, "code": "unauthorized", "error": "令牌不正确。"}, 401)
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length <= 0 or length > 65536:
                raise OcrError("bad_request", "请求体为空或过大。", 413)
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
            path = _check_path(str(payload.get("path", "")))
        except OcrError as exc:
            self._json({"ok": False, "code": exc.code, "error": exc.message}, exc.status)
            return
        except Exception as exc:
            self._json({"ok": False, "code": "bad_request", "error": str(exc)}, 400)
            return

        try:
            result = ENGINE.scan(path)
        except OcrError as exc:
            self._json({"ok": False, "code": exc.code, "error": exc.message}, exc.status)
            return
        except Exception as exc:  # 引擎内部错误：不吞，回 500 让人能定位
            log("scan failed: %s" % exc)
            self._json({"ok": False, "code": "scan_failed", "error": "%s: %s" % (type(exc).__name__, exc)}, 500)
            return

        result["ok"] = True
        log("scan ok: %s size=%d pages=%d ms=%d" % (path.name, path.stat().st_size, result["pages"], result["ms"]))
        self._json(result)


def main() -> int:
    if not ROOTS:
        log("warn: TCPC_OCR_ROOTS 未设置 ⇒ /ocr 一律拒绝（只 /health 可用）")
    log("listening on %s:%d roots=%d token=%s" % (HOST, PORT, len(ROOTS), "on" if TOKEN else "off"))
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
