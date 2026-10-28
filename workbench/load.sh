#!/usr/bin/env bash
# 中传网主题智能体工作台 · 摘要加载器（tcpc-oa 工作区 · 中传网办公协同）
#
# 用途：在插件工作区（dev-tcpc-oa）一条命令加载主题工作台现状 —— 角色、执行计数、
#       最近任务、在办任务、活动 marker、本仓状态。只读，不写主题仓任何文件。
#
# 用法：
#   bash workbench/load.sh            # 人读摘要
#   bash workbench/load.sh --json     # 机器读（供脚本消费）
#
# 环境变量：
#   ZW_WORKBENCH_THEME  主题仓主工作区（默认 /srv/git/worktrees/dev-tcpc/main）
set -euo pipefail

THEME="${ZW_WORKBENCH_THEME:-/srv/git/worktrees/dev-tcpc/main}"
REPO="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
MODE="${1:-text}"

if [[ ! -f "$THEME/AUTONOMOUS_TASKS.md" || ! -f "$THEME/agents/registry.yml" || ! -f "$THEME/agents/metrics.json" ]]; then
  echo "✗ 主题工作台数据不完整：$THEME" >&2
  echo "  需要 AUTONOMOUS_TASKS.md / agents/registry.yml / agents/metrics.json" >&2
  echo "  可用 ZW_WORKBENCH_THEME=<主题仓主工作区> 覆盖。" >&2
  exit 2
fi

python3 - "$THEME" "$REPO" "$MODE" <<'PY'
import json
import os
import re
import subprocess
import sys
from datetime import datetime

theme, repo, mode = sys.argv[1], sys.argv[2], sys.argv[3]


def git(cwd, *args):
    try:
        return subprocess.run(
            ["git", "-C", cwd, *args],
            check=False, capture_output=True, text=True,
        ).stdout.strip()
    except OSError:
        return ""


def read(path):
    try:
        with open(path, encoding="utf-8", errors="replace") as fh:
            return fh.read()
    except OSError:
        return ""


# ---- 角色 ----
role_ids = []
for line in read(os.path.join(theme, "agents", "registry.yml")).splitlines():
    m = re.match(r"^\s*-\s+id:\s*(\S+)", line)
    if m:
        role_ids.append(m.group(1))

# ---- 执行计数 ----
metrics = {}
try:
    metrics = json.loads(read(os.path.join(theme, "agents", "metrics.json")) or "{}")
except ValueError:
    metrics = {}
counters = metrics.get("counters", {})
totals = {rid: int(v.get("tasks", 0)) for rid, v in counters.items()}
total_runs = sum(totals.values())
top = sorted(totals.items(), key=lambda kv: -kv[1])[:5]

# ---- 台账：最近任务 + 在办 ----
ledger = os.path.join(theme, "AUTONOMOUS_TASKS.md")
recent = []
active = []
block = None


def flush():
    if not block:
        return
    heading, status = block
    recent.append((heading, status))
    if status.startswith("进行中") or status.startswith("排队"):
        active.append((heading, status))


status_re = re.compile(r"^-\s*状态：\s*(.*)$")
with open(ledger, encoding="utf-8", errors="replace") as fh:
    for line in fh:
        if line.startswith("## "):
            flush()
            block = [line[3:].strip(), ""]
        elif block is not None and not block[1]:
            m = status_re.match(line.strip())
            if m:
                block[1] = m.group(1).strip()
flush()

# ---- 本仓状态 ----
branch = git(repo, "rev-parse", "--abbrev-ref", "HEAD") or "?"
head = git(repo, "rev-parse", "--short", "HEAD") or "?"
dirty = len([l for l in git(repo, "status", "--porcelain").splitlines() if l])
version = read(os.path.join(repo, "VERSION")).strip() or "?"
theme_head = git(theme, "rev-parse", "--short", "HEAD") or "?"
theme_branch = git(theme, "rev-parse", "--abbrev-ref", "HEAD") or "?"

# ---- 活动 marker（本仓）----
marker = None
git_dir = git(repo, "rev-parse", "--absolute-git-dir")
if git_dir:
    mp = os.path.join(git_dir, "zw-workbench-active.json")
    if os.path.isfile(mp):
        try:
            marker = json.loads(read(mp))
        except ValueError:
            marker = {"raw": read(mp)[:200]}

data = {
    "theme": {
        "root": theme, "branch": theme_branch, "head": theme_head,
        "roles": len(role_ids), "role_ids": role_ids,
        "metrics_updated_at": metrics.get("updated_at", ""),
        "total_runs": total_runs,
        "top_roles": [{"id": k, "tasks": v} for k, v in top],
    },
    "ledger": {
        "recent": [{"title": h, "status": s} for h, s in recent[-6:]],
        "active_count": len(active),
        "active": [{"title": h, "status": s} for h, s in active[-5:]],
    },
    "repo": {
        "root": repo, "branch": branch, "head": head,
        "dirty_files": dirty, "version": version,
    },
    "marker": marker,
    "workbench_page": os.path.join(theme, "docs", "prototypes", "agents", "index.html"),
}

if mode == "--json":
    print(json.dumps(data, ensure_ascii=False, indent=2))
    raise SystemExit(0)

print("== 中传网主题智能体工作台 · 摘要 ==")
print(f"加载时间：{datetime.now().astimezone().strftime('%Y-%m-%d %H:%M:%S %z')}")
print()
print(f"主题仓：{theme}")
print(f"  分支/HEAD：{theme_branch} @ {theme_head}")
print(f"  角色：{len(role_ids)} 个（agents/registry.yml）")
print(f"  执行计数：{total_runs} 次（updated_at={metrics.get('updated_at', '?')}）")
if top:
    print("  Top：" + "，".join(f"{rid}={n}" for rid, n in top))
print(f"  在办任务：{len(active)} 条（进行中/排队）")
for title, status in active[-5:]:
    print(f"    - {title[:60]} [{status[:16]}]")
print("  最近任务：")
for title, status in recent[-6:]:
    print(f"    - {title[:60]} [{status[:16] or '状态未记'}]")
print(f"  工作台页：{data['workbench_page']}")
print()
print(f"插件仓：{repo}")
print(f"  分支/HEAD：{branch} @ {head}（VERSION {version}，未提交 {dirty} 个文件）")
if marker:
    print(f"  活动 marker：{marker.get('task', '?')}（{marker.get('roles_spec', '')[:60]}）")
else:
    print("  活动 marker：无（未走 workbench/preflight.sh）")
print()
print("注意：任务必须在主题台账登记；开工/收口走主题仓 workbench-preflight / workbench-finish。")
print("详见本仓 AGENTS.md 与 workbench/README.md。")
PY
