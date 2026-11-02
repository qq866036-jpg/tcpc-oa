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
# OA-2428（多仓开工接缝）：卫星仓（本仓 space=OA）的任务号**不可能**在主题仓过 preflight ——
# 主题仓 `zw_assert_task_space` 严格比前缀（repos.yml: theme=TASK），`OA-xxxx` 必然 rc=1。
# 因此主题侧开工 marker 只能来自**主题号载体单**（TASK-xxxx）：
#   `ZW_WORKBENCH_THEME_TASK=<TASK 号>` 显式指定载体单；未提供时行为与改动前逐字一致（= 同号）；
#   提供了但载体工作树/marker 不存在 ⇒ fail-closed（不静默回退）。
THEME_TASK="${ZW_WORKBENCH_THEME_TASK:-$TASK}"
if [ -n "${ZW_WORKBENCH_THEME_TASK:-}" ] && ! [[ "$ZW_WORKBENCH_THEME_TASK" =~ ^TASK-[0-9]+$ ]]; then
  echo "✗ ZW_WORKBENCH_THEME_TASK=$ZW_WORKBENCH_THEME_TASK 不合法（须形如 TASK-123 的主题载体单号）" >&2
  exit 2
fi
REPO="$(git rev-parse --show-toplevel)"

# ── ⓪ 工作树归属断言（OA-2458）──────────────────────────────────────────────
# 为什么：本仓的任务工作树约定是 `<本仓基座>/task-<本任务号>`（基座＝主工作树的父目录），
# 但历史上没有任何判据守着 —— 判据缺位时最容易出现的不是"建错"，而是"根本没建"：
# 直接在 main 上改、或在主题仓的 `task-OA-xxxx` 里改（主题 `workbench-worktree.sh` 的
# `WORKTREE_BASE` 写死 `dev-tcpc`，跨仓套用会建到主题仓），两种都曾把改动放错仓。
# 口径：
#   · 主工作区（`<基座>/main`）放行 —— TASK-2306 §5 允许的首期形态（改动量小、单写者）；
#   · 任务工作树必须在 `<基座>/task-*` 下，且分支必须是 `task/<本任务号>`（或 `int/<本任务号>`）；
#   · 逃生口 `ZW_WORKTREE_LAYOUT_ALLOW=1`（须在台账写明理由；禁止裸豁免）。
COMMON_DIR="$(git rev-parse --git-common-dir 2>/dev/null || true)"
if [ -n "$COMMON_DIR" ]; then
  [ "${COMMON_DIR#/}" = "$COMMON_DIR" ] && COMMON_DIR="$REPO/$COMMON_DIR"
  COMMON_DIR="$(cd "$COMMON_DIR" 2>/dev/null && pwd || true)"
fi
if [ -n "$COMMON_DIR" ]; then
  MAIN_WT="$(dirname "$COMMON_DIR")"
  WT_BASE="$(dirname "$MAIN_WT")"
  CUR_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '?')"
  if [ "${ZW_WORKTREE_LAYOUT_ALLOW:-0}" = "1" ]; then
    echo "⚠ 工作树归属断言已放行（ZW_WORKTREE_LAYOUT_ALLOW=1）：$REPO（须在台账写明理由）"
  elif [ "$REPO" = "$MAIN_WT" ]; then
    echo "==> ⓪ 工作树归属：主工作区（$REPO）—— 首期允许形态"
  elif [[ "$REPO" != "$WT_BASE"/* ]]; then
    echo "✗ 任务工作树位置不合规：$REPO" >&2
    echo "  规则（OA-2458）：任务工作树必须位于 <本仓基座>/task-<本任务号>，当前基座＝$WT_BASE" >&2
    echo "  处置：bash workbench/worktree.sh create $TASK（会建 $WT_BASE/task-$TASK）" >&2
    echo "  逃生口：ZW_WORKTREE_LAYOUT_ALLOW=1（须在台账写明理由）" >&2
    exit 2
  elif [ "$CUR_BRANCH" != "task/$TASK" ] || [ "${REPO##*/}" != "task-$TASK" ]; then
    echo "✗ 任务工作树归属不合规：路径=${REPO##*/}，分支=$CUR_BRANCH（本任务要求 task-$TASK / task/$TASK）" >&2
    echo "  规则（OA-2458）：一个号一个工作树一个写者；禁止在他人工作树/分支上开工与收口" >&2
    echo "  处置：bash workbench/worktree.sh create $TASK" >&2
    echo "  逃生口：ZW_WORKTREE_LAYOUT_ALLOW=1（须在台账写明理由）" >&2
    exit 2
  else
    echo "==> ⓪ 工作树归属：任务工作树 $REPO（分支 $CUR_BRANCH）✓"
  fi
fi

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

echo "==> ②b 号段归属校验（分仓管理 · TASK-2398 / CHG-431）"
# 判定唯一实现在主题仓；本仓只做来源加载（workbench/repo-space.sh）。
# 口径：任务号前缀必须等于 docs/ops/repos.yml 里本仓的 space（oa = OA）。
# shellcheck source=workbench/repo-space.sh
. "$(dirname "${BASH_SOURCE[0]}")/repo-space.sh"
if ! zw_assert_task_space "$REPO" "$TASK"; then
  echo "✗ 开工门禁拒绝：号段与本仓不符（fail-closed）。" >&2
  exit 1
fi
echo "   号段归属：$(zw_repo_space_for_path "$REPO")（本仓 root=$REPO）"

# ── TASK-2429 / CHG-435：长期记忆三层（开工必读）────────────────────────────
# 判据唯一实现在主题仓（本仓只调用，不复制规则）；台账用主题任务工作树那一份。
LTM_GATE="$THEME/scripts/long-term-memory-gate.py"
if [[ -f "$LTM_GATE" ]]; then
  echo "==> ②c 长期记忆三层（dsh / 主题 / 插件仓）"
  # OA-2439 修：卫星仓（OA-*）的台账在**载体单**的主题工作树里（task-<TASK-xxxx>），
  # 不存在 task-OA-xxxx 这样的主题工作树；原实现按 $TASK 拼路径 ⇒ 台账读不到 ⇒ 恒红。
  LTM_TASK="${ZW_WORKBENCH_THEME_TASK:-$TASK}"
  if ! ZW_LTM_LEDGER="$BASE/task-$LTM_TASK/AUTONOMOUS_TASKS.md" \
       python3 "$LTM_GATE" check --task "$TASK"; then
    echo "✗ 长期记忆三层门禁未通过：$TASK（口径见主题仓 docs/ops/long-term-memory.yml）" >&2
    exit 1
  fi
else
  echo "  （主题仓尚未提供 long-term-memory-gate.py —— 跳过；并入后自动生效）"
fi

echo "==> ③ 主题开工 marker 交叉校验（载体单：$THEME_TASK）"
WT="$BASE/task-$THEME_TASK"
if [[ ! -d "$WT" ]]; then
  echo "✗ 主题载体工作树不存在：$WT" >&2
  echo "  先创建：bash $THEME/scripts/workbench-worktree.sh create $THEME_TASK" >&2
  echo "  再在 $WT 里补台账条目并跑：bash scripts/workbench-preflight.sh $THEME_TASK \"...\"" >&2
  echo "  （卫星仓任务 $TASK 的主题侧 marker 由载体单 $THEME_TASK 提供；可用 ZW_WORKBENCH_THEME_TASK 显式指定）" >&2
  exit 1
fi
THEME_GD="$(git -C "$WT" rev-parse --absolute-git-dir)"
THEME_MARKER="$THEME_GD/zw-workbench-active.json"
if [[ ! -f "$THEME_MARKER" ]]; then
  echo "✗ 主题侧尚未跑开工门禁（缺 marker）：$THEME_MARKER" >&2
  echo "  在 $WT 执行：bash scripts/workbench-preflight.sh $THEME_TASK \"$ROLES_SPEC\"" >&2
  exit 1
fi
python3 - "$THEME_MARKER" "$THEME_TASK" <<'PY'
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
