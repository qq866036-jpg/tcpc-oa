#!/usr/bin/env bash
# OA-0001 / TASK-2398：**分仓号段**加载器（只解析来源，不含判定逻辑）。
#
# 与 `workbench/task-space.sh` 同构：卫星仓只做「去哪里取」，判定的唯一实现仍在主题仓
# （`scripts/lib/zw-repo-space.sh`，读 `docs/ops/repos.yml`、**不认仓名**）。cert / oa 共用
# 同一份实现，杜绝两套口径。
#
# 用法（由 preflight / commit-msg source）：
#   . "$(dirname "${BASH_SOURCE[0]}")/repo-space.sh"
#   zw_assert_task_space "$REPO_ROOT" "$TASK"     # 0=匹配；1=号段不属于本仓；2=无法判定
#
# 环境变量：
#   ZW_WORKBENCH_THEME        主题仓工作区（默认 /srv/git/worktrees/dev-tcpc/main）
#   ZW_SPACE_MISMATCH_ALLOW=1 应急通道（**须在台账写明理由**）
set -uo pipefail

ZW_SPACE_THEME="${ZW_WORKBENCH_THEME:-/srv/git/worktrees/dev-tcpc/main}"
ZW_SPACE_LIB="$ZW_SPACE_THEME/scripts/lib/zw-repo-space.sh"

if [[ ! -f "$ZW_SPACE_LIB" ]]; then
  echo "✗ 缺主题仓分仓号段库：$ZW_SPACE_LIB" >&2
  echo "  处置：① 确认 ZW_WORKBENCH_THEME 指向主题仓工作区；② 确认主题仓已并入 TASK-2398。" >&2
  echo "  本仓**不保留第二份判定**（禁止分叉）；本检查 fail-closed。" >&2
  exit 2
fi

# shellcheck disable=SC1090
. "$ZW_SPACE_LIB"
