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

# ── AGENT-0254：marker **finally 语义** ─────────────────────────────────────────
# 背景（今天实测咬 4 次）：本脚本在**提交后**重跑时会在长期记忆判据处失败（`dev...HEAD` 已空），
# 却把 marker 留着 ⇒ 下次任务 preflight 直接拒「本仓已有其它任务的活动 marker」。
# 判据：
#   · **本仓质量门通过之后**的任何失败（含长期记忆、跨仓验证）⇒ 清 marker（工作已过验证点，不该拦后手）；
#   · 质量门**之前**失败（真没干完）⇒ 保留 marker（在制语义不变，不放宽）。
ZW_MARKER_CLEARED=0
zw_finish_cleanup() {
    local rc=$?
    if [ "${ZW_MARKER_CLEAR_ON_EXIT:-0}" = "1" ] && [ "$ZW_MARKER_CLEARED" = "0" ] && [ -n "${MARKER:-}" ]; then
        rm -f "$MARKER" 2>/dev/null || true
        ZW_MARKER_CLEARED=1
        echo "   已清 marker（收口中止于验证点之后，避免残留拦住下一单）"
    fi
    return $rc
}
trap zw_finish_cleanup EXIT
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

# ── TASK-2429 / CHG-435：长期记忆三层（收口必写）──────────────────────────
# 判据唯一实现在主题仓：scripts/long-term-memory-gate.py finish --task <号> --repo oa
THEME_WT="${MARKER_THEME_WORKTREE:-}"
LTM_GATE="$THEME_WT/scripts/long-term-memory-gate.py"
if [[ -n "$THEME_WT" && -f "$LTM_GATE" ]]; then
  echo "==> 长期记忆三层（dsh / 主题 / 插件仓）"
  # 载体单：卫星仓的台账在主题载体工作树里（由 ZW_WORKBENCH_THEME_TASK 指定），
  # 缺省时回退到「与任务号同名的主题工作树」（旧的单仓行为）。
  LTM_TASK="${ZW_WORKBENCH_THEME_TASK:-$MARKER_TASK}"
  LTM_LEDGER_WT="${ZW_WORKBENCH_BASE:-/srv/git/worktrees/dev-tcpc}/task-$LTM_TASK"
  [[ -d "$LTM_LEDGER_WT" ]] || LTM_LEDGER_WT="$THEME_WT"
  if ! ZW_LTM_LEDGER="$LTM_LEDGER_WT/AUTONOMOUS_TASKS.md" \
       python3 "$LTM_GATE" finish --task "$MARKER_TASK" --repo oa; then
    echo "✗ 长期记忆三层门禁未通过：$MARKER_TASK（marker 保留；口径见主题仓 docs/ops/long-term-memory.yml）" >&2
    exit 1
  fi
else
  echo "  （主题仓尚未提供 long-term-memory-gate.py —— 跳过；并入后自动生效）"
fi

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
ZW_MARKER_CLEAR_ON_EXIT=1   # 质量门已过：此后任何失败都清 marker

# 铁律 33（AGENT-0402 复用）：界面文案 / 适老化门禁 —— 判据在主题仓唯一出处，本仓只传可见面清单。
# 缺「界面文案增量」结论、新增可见文字带方案代号 / 占位词 / zw_* 函数名 ⇒ 判红中止（marker 保留，可修后重跑）。
THEME_UX="${ZW_WORKBENCH_THEME:-/srv/git/worktrees/dev-tcpc/main}"
if [[ -f "$THEME_UX/scripts/ux-guard-sync-check.sh" ]]; then
  echo "==> 界面文案 · 适老化门禁（铁律 33）"
  if ! ZW_UX_GUARD_TASKS_FILE="${LTM_LEDGER_WT:-$THEME_UX}/AUTONOMOUS_TASKS.md" \
       bash "$THEME_UX/scripts/ux-guard-sync-check.sh" \
         --task "$MARKER_TASK" --roles "$MARKER_ROLES_SPEC" \
         --repo-path "$REPO" --visible-paths '^(includes|assets|templates)/'; then
    echo "✗ 界面文案/适老化门禁未通过，收口中止（marker 保留）" >&2
    exit 1
  fi
fi

# ── AGENT-0244 / OA-2486（方案 §7 H）：**跨仓验证** —— 本仓收口同时跑「主题里断言本仓源码的测试」。
# 为什么 oa 优先：四个卫星仓里 oa 是**唯一没有任何跨仓兜底**的，「oa 绿、主题红」此前无机制可拦。
# 唯一入口在主题仓（本段只调用，不复制测试清单）；红 ⇒ 中断收口（marker 保留）。
THEME_MAIN="${ZW_WORKBENCH_THEME:-/srv/git/worktrees/dev-tcpc/main}"
if [[ -f "$THEME_MAIN/scripts/plugin-gate.sh" ]]; then
  # AGENT-0342 / OA-2492（P1-A）：必须传 `--repo-path "$REPO"` —— 不传时 `plugin-gate` 按
  # `docs/ops/repos.yml` 的登记路径（= 主工作区 dev）求值：`PG_CI` 跑 dev 的 ci-check、
  # `PG_SUBSET` 对着 dev 的源码 ⇒ 收口查不出「本单自己会引入的违规」；
  # 一旦并入把 dev 弄红，下一单 finish 的 PG_CI 必红 ⇒ 死锁。
  echo "==> 跨仓验证：plugin-gate dev-tcpc-oa --repo-path $REPO（对待并分支求值）"
  if ! bash "$THEME_MAIN/scripts/plugin-gate.sh" dev-tcpc-oa --repo-path "$REPO"; then
    echo "✗ 跨仓验证未通过（本仓绿 ≠ 主题绿），收口中止（marker 保留）" >&2
    echo "  回看：bash $THEME_MAIN/scripts/plugin-gate.sh dev-tcpc-oa --repo-path $REPO" >&2
    exit 1
  fi
else
  echo "  （主题仓尚无 scripts/plugin-gate.sh —— 跳过跨仓验证；主题侧并入后自动生效）"
fi

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

# ── OA-2456：清 marker **之前**落 `zw-finished-task`（提交期兜底解析任务号）──────────
# 为什么：钩子要求"提交时 marker 仍在"，而收口后 marker 必被清 ⇒ 按 README 顺序（先 finish 再 commit）
# 提交必被拒（OA-2455 只能用 --no-verify）。主题侧 AGENT-0023 已用同一接缝解决，这里做卫星版；
# pre-commit / commit-msg 在 marker 缺失时读本文件（含 2 小时 TTL，过期仍拒 —— 不放宽）。
GD="$(git rev-parse --absolute-git-dir)"
python3 - "$GD/zw-finished-task" "$MARKER_TASK" <<'PYFIN'
import json, sys, time
path, task = sys.argv[1], sys.argv[2]
with open(path, "w", encoding="utf-8") as fh:
    json.dump({"task": task, "finished_at": time.strftime("%Y-%m-%d %H:%M:%S%z")}, fh, ensure_ascii=False)
print("   已落 zw-finished-task：" + path)
PYFIN

rm -f "$MARKER"
echo "   已清 marker"

echo
echo "✓ 插件侧收口完成：$MARKER_TASK"
# TASK-2320 / CHG-430（审计发现）：原先打印的命令**不带 --repo**，导致主题 metrics 的 repo 字段永远是空的
# （实测 2436 条记录 0 条含 repo）。此处按本仓工作区目录名自动推导仓名并带上，避免靠人记得手敲。
OA_REPO_NAME="${ZW_WORKBENCH_REPO:-$(basename "$(dirname "$(git rev-parse --show-toplevel 2>/dev/null || pwd)")")}"
if [[ -f "$MARKER_THEME_WORKTREE/../main/docs/ops/repos.yml" ]] && ! grep -q "name: $OA_REPO_NAME" "$MARKER_THEME_WORKTREE/../main/docs/ops/repos.yml" 2>/dev/null; then
  echo "  ⚠ 仓名 ${OA_REPO_NAME} 未登记在主题 docs/ops/repos.yml（仍会带上 --repo，请补登记）"
fi
echo "  下一步（主题侧，必做）："
if [[ -n "$MARKER_THEME_WORKTREE" && -d "$MARKER_THEME_WORKTREE" ]]; then
  echo "    cd $MARKER_THEME_WORKTREE && bash scripts/workbench-finish.sh \"$NOTE\" --repo $OA_REPO_NAME"
  echo "  主题侧收口会把 metrics/台账/工作台页写回主题仓；插件提交信息里带 $MARKER_TASK。"
else
  echo "   主题任务工作树不存在：$MARKER_THEME_WORKTREE —— 补建后再跑主题 workbench-finish.sh --repo $OA_REPO_NAME"
fi
