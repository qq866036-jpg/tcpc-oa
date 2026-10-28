#!/usr/bin/env bash
# 中传网主题智能体工作台 · 插件侧开工门禁（tcpc-oa 工作区 · 中传网办公协同）
#
# 作用：把「在插件工作区做任务」锚定到主题工作台 ——
#   ① 任务必须已登记在主题仓 AUTONOMOUS_TASKS.md，且未 done；
#   ② 角色必须来自主题仓 agents/registry.yml；
#   ③ 主题任务工作树里必须已跑过 workbench-preflight.sh（校验其 marker 的 task 一致）；
#   ④ 通过后在本仓 .git 写活动 marker，供 workbench/finish.sh 与提交门禁读取。
#
# 用法：
#   bash workbench/preflight.sh TASK-xxxx "tcpc-developer:依据;tcpc-test-engineer:依据"
#
# 环境变量：
#   ZW_WORKBENCH_THEME  主题仓主工作区（默认 /srv/git/worktrees/dev-tcpc/main）
#   ZW_WORKBENCH_BASE   主题任务工作树根（默认 /srv/git/worktrees/dev-tcpc）
set -euo pipefail

TASK="${1:-}"
ROLES_SPEC="${2:-}"
if [[ -z "$TASK" || -z "$ROLES_SPEC" ]]; then
  echo "用法：bash workbench/preflight.sh TASK-xxxx \"role:触发依据;...\"" >&2
  exit 2
fi
THEME="${ZW_WORKBENCH_THEME:-/srv/git/worktrees/dev-tcpc/main}"
# TASK-2270（M0b）：号段校验走主题仓唯一出处（默认只认 TASK；ZW_TASK_SPACES 可含 OA）
# shellcheck source=workbench/task-space.sh
. "$(dirname "${BASH_SOURCE[0]}")/task-space.sh"
if ! zw_is_task_id "$TASK"; then
  echo "✗ 非法任务号：$TASK（号段：$(zw_task_spaces)；形如 TASK-123 / OA-0001）" >&2
  exit 2
fi
BASE="${ZW_WORKBENCH_BASE:-/srv/git/worktrees/dev-tcpc}"
REPO="$(git rev-parse --show-toplevel)"
LEDGER="$THEME/AUTONOMOUS_TASKS.md"
REGISTRY="$THEME/agents/registry.yml"

[[ -f "$LEDGER" && -f "$REGISTRY" ]] || {
  echo "✗ 主题工作台数据缺失：$LEDGER / $REGISTRY" >&2
  exit 2
}

echo "==> ① 主题台账登记检查"
TASK_STATUS="$(python3 - "$LEDGER" "$TASK" <<'PY'
import re, sys
path, task = sys.argv[1], sys.argv[2]
status = None
in_block = False
for line in open(path, encoding="utf-8", errors="replace"):
    if line.startswith("## "):
        if in_block:
            break
        if line[3:].strip().split(" ", 1)[0] == task:
            in_block = True
        continue
    if in_block and status is None:
        m = re.match(r"^-\s*状态：\s*(.*)$", line.strip())
        if m:
            status = m.group(1).strip()
print(status or "")
PY
)"
if [[ -z "$TASK_STATUS" ]]; then
  echo "✗ $TASK 未登记到主题台账：$LEDGER" >&2
  echo "  先在主题任务工作树补条目（模板见 workbench/README.md）。" >&2
  exit 1
fi
if [[ "$TASK_STATUS" == done* ]]; then
  echo "✗ $TASK 状态为 done（$TASK_STATUS），不能开工；请用新任务号。" >&2
  exit 1
fi
echo "   $TASK 状态：${TASK_STATUS:-未记}"

echo "==> ② 角色校验（主题 agents/registry.yml）"
python3 - "$REGISTRY" "$ROLES_SPEC" <<'PY'
import re, sys
path, spec = sys.argv[1], sys.argv[2]
known = set()
for line in open(path, encoding="utf-8", errors="replace"):
    m = re.match(r"^\s*-\s+id:\s*(\S+)", line)
    if m:
        known.add(m.group(1))
roles = []
prob = []
for part in re.split(r"[;,]", spec):
    part = part.strip()
    if not part:
        continue
    rid, sep, why = part.partition(":")
    rid, why = rid.strip(), why.strip()
    if not rid:
        continue
    if not why:
        prob.append(f"{rid}（缺触发依据）")
    if rid not in known:
        prob.append(f"{rid}（不在 registry.yml）")
    roles.append(rid)
if prob:
    print("✗ 角色不合规：" + "；".join(prob), file=sys.stderr)
    sys.exit(1)
if not roles:
    print("✗ 至少需要一个角色且写触发依据", file=sys.stderr)
    sys.exit(1)
print("   角色：" + "，".join(roles))
PY

echo "==> ③ 主题开工 marker 交叉校验"
WT="$BASE/task-$TASK"
if [[ ! -d "$WT" ]]; then
  echo "✗ 主题任务工作树不存在：$WT" >&2
  echo "  先创建：bash $THEME/scripts/workbench-worktree.sh create $TASK" >&2
  echo "  再在 $WT 里补台账条目并跑：bash scripts/workbench-preflight.sh $TASK \"...\"" >&2
  exit 1
fi
THEME_GD="$(git -C "$WT" rev-parse --absolute-git-dir)"
THEME_MARKER="$THEME_GD/zw-workbench-active.json"
if [[ ! -f "$THEME_MARKER" ]]; then
  echo "✗ 主题侧尚未跑开工门禁（缺 marker）：$THEME_MARKER" >&2
  echo "  在 $WT 执行：bash scripts/workbench-preflight.sh $TASK \"$ROLES_SPEC\"" >&2
  exit 1
fi
python3 - "$THEME_MARKER" "$TASK" <<'PY'
import json, sys
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except (OSError, ValueError):
    print("✗ 主题 marker 不可读/不是 JSON：" + sys.argv[1], file=sys.stderr)
    sys.exit(1)
if str(d.get("task", "")) != sys.argv[2]:
    print(f"✗ 主题 marker 的 task={d.get('task')} 与本次 {sys.argv[2]} 不一致（可能被其它会话占用）", file=sys.stderr)
    sys.exit(1)
PY

echo "==> ④ 写本仓活动 marker"
GD="$(git -C "$REPO" rev-parse --absolute-git-dir)"
PMARKER="$GD/zw-workbench-active.json"
if [[ -f "$PMARKER" ]]; then
  CUR="$(python3 - "$PMARKER" <<'PY'
import json, sys
try:
    print(json.load(open(sys.argv[1], encoding="utf-8")).get("task", ""))
except Exception:
    print("")
PY
)"
  if [[ -n "$CUR" && "$CUR" != "$TASK" ]]; then
    echo "✗ 本仓已有其它任务的活动 marker：$CUR（先收口或清理 $PMARKER）" >&2
    exit 1
  fi
fi
python3 - "$PMARKER" "$TASK" "$ROLES_SPEC" "$REPO" "$WT" <<'PY'
import json, sys, time
path, task, roles, repo, wt = sys.argv[1:6]
json.dump({
    "task": task,
    "roles_spec": roles,
    "repo": repo,
    "theme_worktree": wt,
    "started_at": time.strftime("%Y-%m-%d %H:%M:%S%z"),
    "harness": "dsh",
}, open(path, "w", encoding="utf-8"), ensure_ascii=False, indent=2)
print("   marker：" + path)
PY

echo
echo "✓ 插件侧开工门禁通过：$TASK"
echo "  代码改动在本仓（分支建议 task/$TASK）；提交前跑：bash scripts/ci-check.sh"
echo "  收口：bash workbench/finish.sh \"任务说明\"，再回主题工作树跑 workbench-finish.sh"
