#!/usr/bin/env bash
# 中传网电子证书插件 · 提交信息门禁（工作台硬门禁）
#
# 由 .githooks/commit-msg 调用；安装：bash workbench/install-hooks.sh
#
# 判据：
#   ① 提交信息必须含 TASK-xxxx（rebase 的 fixup!/squash!/amend! 除外）
#   ② 若本仓有活动 marker，信息里的 TASK 必须包含 marker 的任务号（防串任务提交）
#
# 逃生口：git commit --no-verify。
set -euo pipefail

MSG_FILE="${1:-}"
if [[ -z "$MSG_FILE" || ! -f "$MSG_FILE" ]]; then
  echo "✗ commit-msg：缺少提交信息文件" >&2
  exit 1
fi

# 去掉注释行与空行，取首行作为标题
SUBJECT="$(grep -v '^#' "$MSG_FILE" | sed '/^[[:space:]]*$/d' | head -1)"
# TASK-2270（M0b）：抓号正则走主题仓唯一出处
# shellcheck source=workbench/task-space.sh
. "$(dirname "${BASH_SOURCE[0]}")/task-space.sh"
TASKS="$(grep -oE "$(zw_task_grep_re)" "$MSG_FILE" | sort -u | tr '\n' ' ' || true)"

# ① rebase 自动生成的信息放行（最终仍会被 squash 进带 TASK 的提交）
if [[ "$SUBJECT" =~ ^(fixup!|squash!|amend!) ]]; then
  exit 0
fi

if [[ -z "${TASKS// /}" ]]; then
  cat >&2 <<EOF
✗ commit-msg：提交信息缺少 TASK 号。

当前标题：${SUBJECT:-<空>}
要求：提交信息（标题或正文）含 TASK-xxxx，例如
    feat(oa): TASK-2260 描述……

任务号必须先在主题台账登记，并走 workbench/preflight.sh 开工。
EOF
  exit 1
fi

# ② 有 marker 时，提交里的 TASK 必须包含它（防「A 任务开工、提交写 B」）
GD="$(git rev-parse --absolute-git-dir)"
MARKER="$GD/zw-workbench-active.json"
if [[ -f "$MARKER" ]]; then
  MTASK="$(python3 - "$MARKER" <<'PY'
import json, sys
try:
    print(json.load(open(sys.argv[1], encoding="utf-8")).get("task", ""))
except Exception:
    print("")
PY
)"
  if [[ -n "$MTASK" && " $TASKS " != *" $MTASK "* ]]; then
    echo "✗ commit-msg：提交信息未包含当前任务号 $MTASK（信息里是：$TASKS）" >&2
    echo "  若确属另一任务，先收口当前 marker（workbench/finish.sh）再开工。" >&2
    exit 1
  fi
fi

echo "==> commit-msg 通过：$TASKS"
