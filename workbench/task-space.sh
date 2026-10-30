#!/usr/bin/env bash
# TASK-2270（M0b）：插件仓的**号段唯一出处**加载器。
#
# 问题：插件仓三钩子（preflight / pre-commit / commit-msg）各自写死 `TASK-[0-9]{3,}`，
# 与主题仓的号段定义分叉 —— 扩号段（CERT）时必然漏改（TASK-2264 核验报告 §4 的核心结论）。
# 做法：统一从主题仓的 `scripts/lib/zw-task-space.sh` 取，**取不到就 fail-closed**，
# 绝不在这里复制第二份正则。
#
# 用法（由三钩子 source）：
#   . "$(dirname "${BASH_SOURCE[0]}")/task-space.sh"
#   zw_is_task_id "$TASK"      # 校验
#   zw_task_grep_re            # 从文本抓号
#   zw_task_re                 # 锚定正则（python 侧用 $ZW_TASK_ID_RE）
#
# 环境变量：
#   ZW_WORKBENCH_THEME   主题仓工作区（默认 /srv/git/worktrees/dev-tcpc/main）
#   ZW_TASK_SPACES      逗号分隔号段白名单（默认 TASK；含 CERT 时才认 CERT 号）
set -uo pipefail

ZW_THEME="${ZW_WORKBENCH_THEME:-/srv/git/worktrees/dev-tcpc/main}"
ZW_LIB="$ZW_THEME/scripts/lib/zw-task-space.sh"

if [[ ! -f "$ZW_LIB" ]]; then
  echo "✗ 缺主题仓号段库：$ZW_LIB" >&2
  echo "  处置：① 确认主题仓工作区路径正确（ZW_WORKBENCH_THEME）；② 确认主题仓已含 TASK-2265 的 scripts/lib/zw-task-space.sh。" >&2
  echo "  本仓不保留第二份号段正则（禁止分叉）。" >&2
  exit 2
fi

# shellcheck disable=SC1090
. "$ZW_LIB"

# ── OA-2455：默认号段取**本仓**在主题 `docs/ops/repos.yml` 的登记值 ───────────────────
# 旧行为：`zw_task_re()` 用主题默认号段（TASK,AGENT,XCX,…，不含 OA）⇒ 插件仓三钩子必须手工
# `ZW_TASK_SPACES=OA,task`，否则本仓任务号被判"非法"（TASK-2409 遗留债）。
# 现在：未显式设置时调主题库的 `zw_task_spaces_for_repo <本仓根>`（AGENT-0024 提供的清单驱动入口），
# OA 仓 ⇒ `OA`；函数缺失/派生失败 ⇒ **保持旧默认**并告警（fail-soft：不让老主题仓直接不可用）。
if [[ -z "${ZW_TASK_SPACES:-}" ]] && declare -F zw_task_spaces_for_repo >/dev/null 2>&1; then
  _zw_repo_root="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
  if _zw_auto_spaces="$(zw_task_spaces_for_repo "$_zw_repo_root" 2>/dev/null)" && [[ -n "$_zw_auto_spaces" ]]; then
    export ZW_TASK_SPACES="$_zw_auto_spaces"
  else
    echo "⚠ 未能按 docs/ops/repos.yml 派生本仓号段（回落主题默认）；如号段校验失败请显式设 ZW_TASK_SPACES" >&2
  fi
fi

# 找不到/配置非法 ⇒ fail-closed（zw_task_re 内部对未知号段返回 2）
ZW_TASK_ID_RE="$(zw_task_re)" || exit 2
export ZW_TASK_ID_RE
