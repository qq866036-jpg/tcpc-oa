# 收文 OCR 本地服务（tcpc-ocr）

> OA-2463 交付物。给 WordPress 插件 `tcpc-oa` 的「收文管理」提供**本机私有化** OCR：
> 上级来文（扫描 PDF / JPG / PNG）→ 文本，供**收文详情页**展示与 **AI 文秘「步骤一通读」** 使用。
> 公文不出服务器：服务只监听 `127.0.0.1`，只读私有存储根内的文件。

## 1. 组成

| 文件 | 作用 |
| --- | --- |
| `app.py` | 单文件 HTTP 服务（标准库 `http.server`，无 Web 框架）：`GET /health`、`POST /ocr` |
| `requirements.txt` | Python 依赖（RapidOCR ONNX + pypdfium2 + pillow） |
| `tcpc-ocr.service.example` | systemd 单元模板（开机自启、崩溃重启、日志进 journal） |

**引擎（两档，同一 HTTP 契约，切换只改环境变量）**：

| 后端 | 装什么 | star | 许可 | 适用 |
| --- | --- | --- | --- | --- |
| **PaddleOCR**（调研首选） | `requirements-paddle.txt`，`TCPC_OCR_ENGINE=paddleocr` | 92,195 | Apache-2.0 | 中文公文质量最好；带 PP-StructureV3 版面/表格；依赖重（paddlepaddle + 数百 MB 模型） |
| **RapidOCR**（保底/默认） | `requirements.txt`，`TCPC_OCR_ENGINE=rapidocr`（或缺省） | 8,431 | Apache-2.0 | 依赖最轻（onnxruntime + opencv），CPU 秒级；跑的是 PP-OCR 转 ONNX 的模型 |

不指定 `TCPC_OCR_ENGINE` 时：**能 import paddleocr 就用 PaddleOCR，否则用 RapidOCR**（自动降级，不报错）。
本机（宝塔 CentOS + py3.13）已自带 `onnxruntime` ⇒ RapidOCR 可零额外系统依赖跑起来；
PaddleOCR 的 paddlepaddle CPU wheel 兼容性**尚未实测**（见 `docs/research/OA-2463-…md` 未验证项）。
换引擎只需实现 `PaddleEngine`/`Engine` 的 `scan()`，PHP 侧完全不用改。

## 2. 安装（生产机 = 本机；**由发布窗口执行，不随插件自动装**）

```bash
# ① 目录与 venv（独立于系统 python，避免和宝塔面板的 python 冲突）
install -d -m 0755 /opt/tcpc-ocr
python3 -m venv /opt/tcpc-ocr/venv
/opt/tcpc-ocr/venv/bin/pip install -U pip
/opt/tcpc-ocr/venv/bin/pip install -r /path/to/tcpc-oa/scripts/ocr-service/requirements.txt

# ② 部署服务文件
install -m 0755 /path/to/tcpc-oa/scripts/ocr-service/app.py /opt/tcpc-ocr/app.py

# ③ systemd（把示例里的 ROOTS 改成**实际收文存储根**）
install -m 0644 tcpc-ocr.service.example /etc/systemd/system/tcpc-ocr.service
systemctl daemon-reload && systemctl enable --now tcpc-ocr
systemctl status tcpc-ocr --no-pager

# ④ 自检（必须 200 且 models_ready=true）
curl -s http://127.0.0.1:8848/health | python3 -m json.tool
```

存储根的取值：插件侧 `zw_receipt_storage_root()` = `{私有文件根}/receipts`，
生产私有根见 `zw_member_files_base_dir()` 的解析链（option `zw_office_files_base_dir`
→ 常量 `ZW_OFFICE_FILES_DIR` → `ZW_MEMBER_FILES_DIR` → `WP_CONTENT_DIR/zw-office-files`）。
**两端必须是同一个真实目录**（服务用 `realpath` 校验前缀），否则 `/ocr` 一律 403。

## 3. 配置（环境变量）

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `TCPC_OCR_HOST` | `127.0.0.1` | **不要**改成 `0.0.0.0`（公文不出服务器） |
| `TCPC_OCR_PORT` | `8848` | 与插件设置页的端点一致 |
| `TCPC_OCR_ROOTS` | 空（=全部拒绝） | 允许读取的存储根，冒号分隔；生产填 `/www/zw-office-files/receipts` 这类真实路径 |
| `TCPC_OCR_TOKEN` | 空 | 共享令牌；设置后请求必须带 `X-TCPC-OCR-Token`（PHP 侧在设置页填同一个值） |
| `TCPC_OCR_MAX_BYTES` | `20971520` | 单文件上限（与登记上传同口径 20MB） |
| `TCPC_OCR_PDF_DPI` | `200` | PDF 渲染 DPI（越高越准、越慢/越吃内存） |
| `TCPC_OCR_MAX_PAGES` | 不设（=全部页） | 长 PDF 限页，防单次请求过久 |

## 4. 插件侧接线

1. 后台「办公协同 → 收文管理 → **OCR 识别设置**」：打开开关、填端点（默认 `http://127.0.0.1:8848/ocr`）、
   需要时填令牌、点「测试连接」看到引擎名与版本即通。
2. 先跑迁移（只 ADD COLUMN，幂等）：
   ```bash
   wp eval-file scripts/migrate-receipt-ocr.php dry-run
   wp eval-file scripts/migrate-receipt-ocr.php execute
   wp eval-file scripts/migrate-receipt-ocr.php verify
   ```
3. 之后**新登记**的收文会自动排队识别（WP-Cron，20 秒后执行）；历史收文在详情页点「重新识别」。

## 5. 运维与排障

| 症状 | 排查 |
| --- | --- |
| 插件「测试连接」报不可达 | `systemctl status tcpc-ocr`；`ss -lntp \| grep 8848`；端点是否写成公网地址（会被白名单拒绝） |
| 403 `path_outside_root` | 服务 `TCPC_OCR_ROOTS` 与插件 `zw_receipt_storage_root()` 不是同一真实目录 |
| 503 `engine_unavailable` | venv 里 `python3 -c "from rapidocr_onnxruntime import RapidOCR"` 是否成功；磁盘是否放得下模型 |
| 识别很慢 | PDF 页数多/DPI 高；调 `TCPC_OCR_MAX_PAGES`、降 `TCPC_OCR_PDF_DPI` |
| 状态一直 `pending` | WP-Cron 是否被禁用（`DISABLE_WP_CRON`）；手动点「重新识别」可同步触发 |

日志只记「文件名/大小/页数/耗时」，**不记**识别文本与完整路径（`journalctl -u tcpc-ocr -n 50`）。

## 6. 卸载 / 回滚

```bash
systemctl disable --now tcpc-ocr && rm -f /etc/systemd/system/tcpc-ocr.service && systemctl daemon-reload
# 插件侧把「OCR 识别设置」开关关掉即可（识别数据列可保留，不回滚不影响收文功能）
```
