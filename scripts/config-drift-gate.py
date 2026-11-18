#!/usr/bin/env python3
"""配置漂移门禁（OA-2521）—— 判据唯一出处。

解决两类「登记与源码不一致」，此前只能靠人工盘点（2026-11-18 盘点实测各 3~5 处）：

判据 1｜**数据字典 ⇄ 源码**
  · 每行的 `control` 里出现的 `name=xxx`，必须在源码里以**真实控件标签**出现（`name="xxx"`）；
    —— 病史：字典写「公文标题 `input[type=text]`」，实际是 3 行 `<textarea>`；附件说明同理；
       字典写「页码 `radio`」，实际是 `<select>`。
  · 每行的 `reader` 里反引号标注的 `zw_*()` 函数，必须在源码里存在；
    —— 病史：两行 reader 仍指向已删除的 `zw_doc_render_html()`。

判据 2｜**版式页 ⇄ 渲染层**
  · `doc-layout-designer.php` 里登记的每个 `path`（用户在「公文版式」能改的键），必须命中下列之一：
      ① `zw_doc_gb9704_get('<path>')` / `zw_doc_gb9704_gap_lines('<path>')` 的**整键读取**；
      ② 三个渲染通道里按**末段键**数组取值（`['leaf']`）；
      ③ `scripts/config-drift-allow.txt` 的**具名豁免**（Tab 分隔，理由 ≥8 字）。
    —— 病史：`sign.date_no_leading_zero`（成文日期月日不补零）全仓 0 处读取；
       `footer.default_mode`（页码默认）有键、无入口、0 处读取。
  · 豁免清单**只降不升**：已能被判据命中的条目仍留在清单里 ⇒ 判「陈旧豁免」同样红灯（防豁免长成常态）。

用法：
  python3 scripts/config-drift-gate.py            # 人读
  python3 scripts/config-drift-gate.py --json     # 机器读
退出码：0 通过；1 判红；2 环境错误。
"""

from __future__ import annotations

import fnmatch
import json
import re
import sys
from pathlib import Path

_argv = sys.argv[1:]
ROOT = Path(__file__).resolve().parent.parent
if "--root" in _argv:
    ROOT = Path(_argv[_argv.index("--root") + 1]).resolve()
INC = ROOT / "includes"
ALLOW_FILE = ROOT / "scripts" / "config-drift-allow.txt"
# 「消费方」= 三个出件通道 + 版式常量表（字体族由 font-face 组装读取）+ 公共助手 + 印章/红头参数。
CHANNELS = (
    "doc-render-html.php",
    "doc-export-docx.php",
    "doc-pdf.php",
    "doc-layout-gb9704.php",
    "doc-helpers.php",
    "doc-seal.php",
    "doc-letterhead.php",
)
MIN_REASON = 8

problems: list[str] = []
warnings: list[str] = []


def load_sources() -> dict[Path, str]:
    out: dict[Path, str] = {}
    for path in INC.rglob("*.php"):
        out[path] = path.read_text(encoding="utf-8", errors="replace")
    return out


def gate_dict(src: dict[Path, str], blob: str) -> int:
    """判据 1：数据字典 control / reader 与源码一致。返回检查行数。"""
    dict_path = INC / "doc" / "doc-admin-dict.php"
    text = src.get(dict_path, "")
    if not text:
        problems.append("取不到数据字典文件 includes/doc/doc-admin-dict.php")
        return 0

    rows = 0
    for chunk in re.split(r"\n\s{8,}array\(", text):
        field = re.search(r"'field'\s*=>\s*'([^']+)'", chunk)
        control = re.search(r"'control'\s*=>\s*'([^']*)'", chunk)
        reader = re.search(r"'reader'\s*=>\s*'([^']*)'", chunk)
        if not field:
            continue
        rows += 1
        name = field.group(1)

        # ① control 里的 name=xxx 必须在源码里真的是控件（名字里允许带 `[]`，但不吃紧随其后的 `]` 等噪声）
        if control:
            for ctl in re.findall(r"name=([A-Za-z0-9_]+(?:\[\])?)", control.group(1)):
                needle = 'name="' + ctl + '"'
                if needle not in blob:
                    problems.append(
                        f"[字典] {name}：control 声明的控件 {ctl} 在源码里找不到（缺 {needle}）"
                    )

        # ② reader 里反引号标注的函数必须存在
        if reader:
            for fn in re.findall(r"`(zw_[a-z0-9_]+)\s*\(", reader.group(1)):
                if f"function {fn}(" not in blob:
                    problems.append(
                        f"[字典] {name}：reader 指向不存在的函数 {fn}()"
                    )
    return rows


def load_allow() -> dict[str, str]:
    allow: dict[str, str] = {}
    if not ALLOW_FILE.exists():
        return allow
    for raw in ALLOW_FILE.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        path, _, reason = line.partition("\t")
        path, reason = path.strip(), reason.strip()
        if not path:
            continue
        if len(reason) < MIN_REASON:
            problems.append(f"[豁免] {path}：理由不足 {MIN_REASON} 字（当前 {len(reason)}）—— 禁止裸豁免")
            continue
        allow[path] = reason
    return allow


def gate_layout(src: dict[Path, str], blob: str) -> tuple[int, dict[str, str]]:
    """判据 2：版式页每个 path 必须真实被读取。返回 (字段数, 生效豁免)。"""
    designer = src.get(INC / "doc" / "doc-layout-designer.php", "")
    if not designer:
        problems.append("取不到版式页文件 includes/doc/doc-layout-designer.php")
        return 0, {}

    paths = sorted(set(re.findall(r"'path'\s*=>\s*'([a-z0-9_.]+)'", designer)))
    whole = set(re.findall(r"zw_doc_gb9704_(?:get|gap_lines)\(\s*'([a-z0-9_.]+)'", blob))
    channel_blob = "\n".join(src.get(INC / "doc" / f, "") for f in CHANNELS)
    leaf = set(re.findall(r"\['([a-z0-9_]+)'\]", channel_blob))
    # 动态拼键（如 `'fonts.' . $slot . '.weight'`）无法静态命中整键，按「前缀.*.后缀」模式计入读取。
    dyn_patterns = [f"{pre}.*.{suf}" for pre, suf in re.findall(
        r"'([a-z0-9_]+)\.'\s*\.\s*\$[a-z0-9_]+\s*\.\s*'\.([a-z0-9_]+)'", blob)]

    allow = load_allow()
    used_allow: dict[str, str] = {}

    for path in paths:
        dyn_hit = any(fnmatch.fnmatch(path, pat) for pat in dyn_patterns)
        if path in whole or path.rsplit(".", 1)[-1] in leaf or dyn_hit:
            if path in allow:
                problems.append(f"[豁免] {path}：源码已能命中读取 ⇒ 陈旧豁免，请从 config-drift-allow.txt 删除")
            continue
        if path in allow:
            used_allow[path] = allow[path]
            continue
        problems.append(
            f"[版式] {path}：版式页有入口，但渲染/导出/PDF 三通道都没读它（死配置）—— 接线，或在 config-drift-allow.txt 具名豁免"
        )

    for path in allow:
        if path not in paths:
            problems.append(f"[豁免] {path}：已不在版式页字段表里 ⇒ 陈旧豁免，请删除")
    return len(paths), used_allow


def main() -> int:
    as_json = "--json" in sys.argv[1:]
    if not INC.is_dir():
        print(f"✗ 环境错误：取不到 {INC}", file=sys.stderr)
        return 2

    src = load_sources()
    blob = "\n".join(src.values())
    # 判据 1 的「控件是否真的存在」必须看**代码**：整行注释先剔掉（否则注释里写一句
    # `name="xxx"` 就能骗过门禁 —— 夹具实测踩到过）。
    code_only = "\n".join(
        line for line in blob.splitlines()
        if not line.lstrip().startswith(("//", "*", "/*", "*/", "#"))
    )
    rows = gate_dict(src, code_only)
    field_count, used_allow = gate_layout(src, blob)

    result = {
        "dict_rows": rows,
        "layout_fields": field_count,
        "allow_count": len(used_allow),
        "problems": problems,
        "warnings": warnings,
        "ok": not problems,
    }
    if as_json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    else:
        print("== 配置漂移门禁（OA-2521）")
        print(f"  数据字典行：{rows} ｜ 版式页字段：{field_count} ｜ 生效豁免：{len(used_allow)}")
        if problems:
            for p in problems:
                print(f"  ✗ {p}", file=sys.stderr)
            print(f"✗ 判红：{len(problems)} 处配置/登记与源码不一致", file=sys.stderr)
            print("  处置：① 修源码或修登记（首选）；② 确属合理（如字体族由 font-face 组装读取）就在 scripts/config-drift-allow.txt 具名豁免。", file=sys.stderr)
        else:
            print("  ✓ 数据字典与控件/读取函数一致")
            print("  ✓ 版式页字段全部有读取方（或已具名豁免）")
            print("✓ CONFIG_DRIFT_OK")
    return 0 if not problems else 1


if __name__ == "__main__":
    sys.exit(main())
