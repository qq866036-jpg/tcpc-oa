#!/usr/bin/env bash
# 中传网主题智能体工作台 · 插件侧收口（tcpc-oa 工作区 · 中传网办公协同）
#
# 作用：读本仓活动 marker → 跑本仓质量门 → 记一条插件侧收口流水 → 清 marker →
#       给出主题侧收口命令（metrics/台账/工作台页仍由主题 workbench-finish.sh 统一写）。
#
# 用法：
#   bash workbench/finish.sh "任务说明"
#
# 退出码：0 通过；1 marker 缺失/门禁失败；2 用法错误。
set -euo pipefail

NOTE="${1:-}"
if [[ -z "$NOTE" ]]; then
  echo "用法：bash workbench/finish.sh \"任务说明\"" >&2
  exit 2
fi

REPO="$(git rev-parse --show-toplevel)"
GD="$(git -C "$REPO" rev-parse --absolute-git-dir)"
MARKER="$GD/zw-workbench-active.json"
[[ -f "$MARKER" ]] || {
  echo "✗ 没有活动 marker：$MARKER" >&2
  echo "  先跑：bash workbench/preflight.sh TASK-xxxx \"...\"" >&2
  exit 1
}

eval "$(python3 - "$MARKER" <<'PY'
import json, shlex, sys
d = json.load(open(sys.argv[1], encoding="utf-8"))
for k in ("task", "roles_spec", "theme_worktree", "started_at"):
    print(f"MARKER_{k.upper()}={shlex.quote(str(d.get(k, '')))}")
PY
)"

echo "==> 任务：$MARKER_TASK"
echo "==> 角色：$MARKER_ROLES_SPEC"
echo "==> 本仓质量门：bash scripts/ci-check.sh"
cd "$REPO"
OUT="$(mktemp)"
if ! bash scripts/ci-check.sh 2>&1 | tee "$OUT"; then
  echo "✗ ci-check.sh 未通过，收口中止（marker 保留）" >&2
  if grep -q "归档文件数与已跟踪数不一致" "$OUT"; then
    echo "  提示：第 ⑤ 项用 HEAD 构建归档、与索引比对；**新文件必须先提交**再收口。" >&2
    echo "  顺序：git add <file> → git commit → bash scripts/ci-check.sh → bash workbench/finish.sh" >&2
  fi
  rm -f "$OUT"
  exit 1
fi
rm -f "$OUT"

echo "==> 记录插件侧收口流水"
python3 - "$GD/zw-workbench-history.jsonl" "$MARKER_TASK" "$MARKER_ROLES_SPEC" "$NOTE" "$REPO" "$MARKER_THEME_WORKTREE" <<'PY'
import json, subprocess, sys, time
path, task, roles, note, repo, wt = sys.argv[1:7]
head = subprocess.run(["git", "-C", repo, "rev-parse", "--short", "HEAD"],
                      capture_output=True, text=True).stdout.strip()
row = {
    "task": task,
    "roles_spec": roles,
    "note": note,
    "repo": repo,
    "head": head,
    "theme_worktree": wt,
    "finished_at": time.strftime("%Y-%m-%d %H:%M:%S%z"),
    "harness": "dsh",
}
with open(path, "a", encoding="utf-8") as fh:
    fh.write(json.dumps(row, ensure_ascii=False) + "\n")
print("   " + path)
PY

rm -f "$MARKER"
echo "   已清 marker"

echo
echo "✓ 插件侧收口完成：$MARKER_TASK"
echo "  下一步（主题侧，必做）："
if [[ -n "$MARKER_THEME_WORKTREE" && -d "$MARKER_THEME_WORKTREE" ]]; then
  echo "    cd $MARKER_THEME_WORKTREE && bash scripts/workbench-finish.sh \"$NOTE\""
  echo "  主题侧收口会把 metrics/台账/工作台页写回主题仓；插件提交信息里带 $MARKER_TASK。"
else
  echo "   主题任务工作树不存在：$MARKER_THEME_WORKTREE —— 补建后再跑主题 workbench-finish.sh"
fi
