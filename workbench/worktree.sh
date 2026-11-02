#!/usr/bin/env bash
# OA-2458：本仓（卫星插件仓）的**任务工作树入口**。
#
# 为什么不用主题的 `workbench-worktree.sh`：它把 `WORKTREE_BASE` 写死 `/srv/git/worktrees/dev-tcpc`
# 且建完会跑主题 `agents-sync` ⇒ 在插件仓里用会在**主题仓**建出 `task-OA-xxxx`（错仓），
# `scripts/workbench-lifecycle.py` 的注释也自证「卫星仓不能硬套」。故本仓自带一份入口，
# 号段与基座全部从**主题 `docs/ops/repos.yml`** 派生（不认仓名、不写死路径）。
#
# 用法：
#   bash workbench/worktree.sh create OA-xxxx [--base <ref>] [--path <dir>]
#   bash workbench/worktree.sh list
#   bash workbench/worktree.sh path OA-xxxx
#   bash workbench/worktree.sh remove OA-xxxx [--force] [--delete-branch]
#
# 行为约定（与主题 TASK-1469 防误写口径对齐）：
#   `create` 在「分支已存在」或「目录已存在」时 **rc=1 且不创建任何东西**，并显式提示
#   「不要向该路径写入」（那可能是其他会话的工作区，PIT-006）。
#
# 退出码：0=成功；1=占用/不存在等可处置错误；2=用法或环境错误。
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

say()  { printf '%s\n' "$*"; }
bad()  { printf '✗ %s\n' "$*" >&2; }
usage() { sed -n '2,20p' "$0"; exit "${1:-0}"; }

# ── 号段（唯一出处：主题仓）────────────────────────────────────────────────
# shellcheck source=workbench/task-space.sh
. "$ROOT/workbench/task-space.sh"
# shellcheck source=workbench/repo-space.sh
. "$ROOT/workbench/repo-space.sh"

# ── 本仓基座：主工作树的父目录 ──────────────────────────────────────────────
# 用 `--git-common-dir` 取**仓级** .git（task 工作树里也指向主工作树的 .git），
# 因而在主工作树与任意任务工作树里运行，得到的 BASE 一致。
resolve_base() {
  local common main
  common="$(git rev-parse --git-common-dir 2>/dev/null || true)"
  [[ -n "$common" ]] || { bad "无法定位 git 目录（不在仓库内？）"; exit 2; }
  [[ "$common" = /* ]] || common="$ROOT/$common"
  common="$(cd "$common" && pwd)" || exit 2
  main="$(dirname "$common")"
  [[ -d "$main" ]] || { bad "主工作树不存在：$main"; exit 2; }
  printf '%s\n' "$(dirname "$main")"
}
BASE="$(resolve_base)"

default_path() { printf '%s/task-%s\n' "${BASE%/}" "$1"; }
branch_of()    { printf 'task/%s\n' "$1"; }

# 号段归属：任务号前缀必须等于本仓在 repos.yml 的 space（OA），否则 rc=1（fail-closed）
require_own_space() {
  local task="$1" rc=0
  zw_assert_task_space "$ROOT" "$task" || rc=$?
  case "$rc" in
    0) return 0 ;;
    1) bad "$task 不属于本仓号段（repos.yml：本仓 space=OA）—— 换本仓号或去对应仓做"; exit 1 ;;
    *) bad "号段归属无法判定（rc=$rc）：先确认主题仓 scripts/lib/zw-repo-space.sh 可读"; exit 2 ;;
  esac
}

arg_value() {
  local name="$1" default="${2:-}"
  shift 2 || true
  local out="$default"
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --"${name}") out="${2:-}"; shift 2 ;;
      --"${name}"=*) out="${1#--"${name}"=}"; shift ;;
      *) shift ;;
    esac
  done
  printf '%s\n' "$out"
}

has_flag() {
  local flag="$1"; shift || true
  local a
  for a in "$@"; do [[ "$a" == "$flag" ]] && return 0; done
  return 1
}

cmd_create() {
  local task="${1:-}"
  [[ -n "$task" ]] || usage 2
  shift || true
  require_own_space "$task"

  local base path branch
  base="$(arg_value base dev "$@")"
  path="$(arg_value path "$(default_path "$task")" "$@")"
  branch="$(branch_of "$task")"

  if git show-ref --verify --quiet "refs/heads/$branch"; then
    bad "未创建任何目录/分支：$task 的分支 $branch 已存在 —— 该号已被（可能正在运行的）会话占用。"
    printf '  ⚠️ 不要向 %s 写入：那可能是其他会话的工作区。\n' "$path" >&2
    printf '  查看占用：git worktree list | grep -F %s    （或：git branch --list %s）\n' "'$task'" "'$branch'" >&2
    exit 1
  fi
  if [[ -e "$path" ]]; then
    bad "未创建任何目录/分支：路径已存在：$path"
    printf '  ⚠️ 不要向该路径写入：它可能是其他会话的工作区，或上次失败留下的残骸。\n' >&2
    printf '  若确认是残骸：bash workbench/worktree.sh remove %s --force\n' "$task" >&2
    exit 1
  fi

  git worktree add -b "$branch" "$path" "$base" || { bad "git worktree add 失败"; exit 1; }

  say ""
  say "✓ 任务工作树已创建（插件仓）"
  say "  任务：$task"
  say "  分支：$branch"
  say "  路径：$path"
  say "  基线：$base"
  say ""
  say "下一步："
  say "  1) 主题侧先备好**载体单**（TASK 号）并跑到 marker："
  say "       cd /srv/git/worktrees/dev-tcpc/task-TASK-xxxx && bash scripts/workbench-preflight.sh TASK-xxxx \"role:依据;…\""
  say "  2) 本工作树开工门禁（把主题 marker 指到载体单）："
  say "       cd $path"
  say "       export ZW_WORKBENCH_THEME=/srv/git/worktrees/dev-tcpc/task-TASK-xxxx"
  say "       export ZW_WORKBENCH_THEME_TASK=TASK-xxxx"
  say "       bash workbench/preflight.sh $task \"role:依据;…\""
  say "  3) 收口：bash workbench/finish.sh \"说明\" → git commit（提交信息带 $task）"
  say "  4) 合并：见 workbench/README.md §1 ⑦（dev-merge --repo … --space OA）"
  say "  5) 同步远端：bash scripts/sync-remotes.sh --apply"
}

cmd_list() {
  local path="" branch="" n=0
  say "本仓任务工作树（基座：$BASE）"
  while IFS= read -r line; do
    case "$line" in
      "worktree "*) path="${line#worktree }" ;;
      "branch refs/heads/"*) branch="${line#branch refs/heads/}" ;;
      "")
        if [[ -n "$path" && "$path" == "$BASE"/* ]]; then
          n=$((n+1))
          say "  ${path##*/}   分支=$branch   $path"
        fi
        path=""; branch=""
        ;;
    esac
  done < <(git worktree list --porcelain; echo)
  [[ "$n" -eq 0 ]] && say "  （无；用 bash workbench/worktree.sh create OA-xxxx 建一个）"
  return 0
}

cmd_path() {
  local task="${1:-}"
  [[ -n "$task" ]] || usage 2
  require_own_space "$task"
  default_path "$task"
}

cmd_remove() {
  local task="${1:-}"
  [[ -n "$task" ]] || usage 2
  shift || true
  require_own_space "$task"

  local path branch
  path="$(default_path "$task")"
  branch="$(branch_of "$task")"

  if ! git worktree list --porcelain | grep -qx "worktree $path"; then
    bad "该任务没有工作树：$path（可能已清理）"
    exit 1
  fi
  if has_flag --force "$@"; then
    git worktree remove --force "$path" || exit 1
  else
    git worktree remove "$path" || {
      bad "工作树不干净，拒绝删除；确认后加 --force（会丢弃该工作树里未提交的改动）"
      exit 1
    }
  fi
  say "✓ 已移除工作树：$path"

  if has_flag --delete-branch "$@"; then
    # 用 -d（非 -D）：未并入就拒绝，防止把未合并的活丢掉
    if git branch -d "$branch"; then
      say "✓ 已删除分支：$branch"
    else
      bad "分支 $branch 未并入 dev，拒绝删除（确认要丢：git branch -D $branch）"
      exit 1
    fi
  else
    say "  （分支 $branch 保留；要一并删除：加 --delete-branch）"
  fi
}

cmd="${1:-}"
[[ -n "$cmd" ]] || usage 2
shift || true
case "$cmd" in
  create) cmd_create "$@" ;;
  list)   cmd_list ;;
  path)   cmd_path "$@" ;;
  remove) cmd_remove "$@" ;;
  -h|--help|help) usage 0 ;;
  *) bad "未知子命令：$cmd"; usage 2 ;;
esac
