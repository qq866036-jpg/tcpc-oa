#!/usr/bin/env bash
# OA-2457：插件仓双远端同步（origin 权威裸库 + GitHub 私有镜像）。
#
# 为什么需要：`scripts/dev-merge.sh` 收口时只对**本地** `refs/heads/dev` 做 CAS `update-ref`，
# **不含任何 push**。于是「合并进 dev」与「远端拿到 dev」之间没有任何机器动作 —— 实测 2026-11-02：
#   local dev=58ffb39（86 提交）｜github/dev=507fc0d（22）⇒ 落后 64｜origin/dev=0452221（17）⇒ 落后 69。
# 本脚本只做一件事：把**指定分支**（默认 dev）快进推到各远端；不做 force、不做 rebase、不 resolve 冲突。
#
# 用法：
#   bash scripts/sync-remotes.sh                  # dry-run（默认）：只打印每个远端的待推清单
#   bash scripts/sync-remotes.sh --apply          # 真推（快进）
#   bash scripts/sync-remotes.sh --apply --branch dev --remote github
#   bash scripts/sync-remotes.sh --check          # 只判「是否已同步」，不打印细节；落后 ⇒ rc=1
#
# 判据（fail-closed，任一不满足即拒推该远端）：
#   ① 本地分支存在；
#   ② 远端分支是本地分支的祖先（fast-forward）；**非快进一律拒绝**（说明远端有本地没有的提交，
#      必须先人工 `git fetch` 判断，禁止用 --force 掩盖）；
#   ③ 工作区干净（脏工作区下推送容易把「未提交但已合并」的错觉带出去；与 ci-check ⑤ 同口径）。
#
# 退出码：0=成功/已同步；1=有远端落后且未 --apply（--check 时落后）；2=非法调用或被拒。
set -uo pipefail

BRANCH="dev"
APPLY=0
CHECK=0
ONLY_REMOTE=""

usage() { sed -n '2,22p' "$0"; exit "${1:-0}"; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --apply) APPLY=1; shift ;;
    --check) CHECK=1; shift ;;
    --branch) BRANCH="${2:-}"; shift 2 ;;
    --branch=*) BRANCH="${1#--branch=}"; shift ;;
    --remote) ONLY_REMOTE="${2:-}"; shift 2 ;;
    --remote=*) ONLY_REMOTE="${1#--remote=}"; shift ;;
    -h|--help) usage 0 ;;
    *) echo "✗ 未知参数：$1" >&2; usage 2 ;;
  esac
done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

say() { printf '%s\n' "$*"; }
bad() { printf '✗ %s\n' "$*" >&2; }

git rev-parse --git-dir >/dev/null 2>&1 || { bad "不在 git 工作树内：$ROOT"; exit 2; }
git show-ref --verify --quiet "refs/heads/$BRANCH" || { bad "本地分支不存在：$BRANCH"; exit 2; }

LOCAL_SHA="$(git rev-parse "$BRANCH")"

if [[ -n "$ONLY_REMOTE" ]]; then
  git remote get-url "$ONLY_REMOTE" >/dev/null 2>&1 || { bad "远端不存在：$ONLY_REMOTE"; exit 2; }
  REMOTES=("$ONLY_REMOTE")
else
  mapfile -t REMOTES < <(git remote)
fi
[[ ${#REMOTES[@]} -gt 0 ]] || { bad "本仓未配置任何远端"; exit 2; }

say "==> 同步 $BRANCH @ ${LOCAL_SHA:0:8}（$((${#REMOTES[@]})) 个远端）"
[[ "$APPLY" -eq 1 ]] && say "    模式：APPLY（真推）" || say "    模式：dry-run（只打印；加 --apply 才推）"

DIRTY="$(git status --porcelain --untracked-files=no | head -20)"
if [[ -n "$DIRTY" && "$APPLY" -eq 1 ]]; then
  bad "工作区有未提交改动，拒绝推送（先提交或用 --check 只读核对）："
  printf '%s\n' "$DIRTY" | sed 's/^/    /' >&2
  exit 2
fi

rc=0
for r in "${REMOTES[@]}"; do
  # 只读探测远端当前 ref（ls-remote 不写远端、不建 refs）
  REMOTE_SHA="$(git ls-remote --heads "$r" "refs/heads/$BRANCH" 2>/dev/null | awk '{print $1}' | head -1)"
  if [[ -z "$REMOTE_SHA" ]]; then
    say "  [$r] 远端无 $BRANCH ⇒ 将新建"
    if [[ "$APPLY" -eq 1 ]]; then
      git push "$r" "$BRANCH:refs/heads/$BRANCH" 2>&1 | tail -2 | sed 's/^/      /' || { bad "[$r] 推送失败"; rc=2; }
    fi
    continue
  fi
  if [[ "$REMOTE_SHA" == "$LOCAL_SHA" ]]; then
    say "  [$r] 已同步（${REMOTE_SHA:0:8}）"
    continue
  fi
  # 快进判定：远端必须是本地的祖先
  if git cat-file -e "$REMOTE_SHA^{commit}" 2>/dev/null && git merge-base --is-ancestor "$REMOTE_SHA" "$LOCAL_SHA"; then
    AHEAD="$(git rev-list --count "$REMOTE_SHA..$LOCAL_SHA")"
    say "  [$r] 落后 $AHEAD 提交（${REMOTE_SHA:0:8} → ${LOCAL_SHA:0:8}）：可快进"
    if [[ "$APPLY" -eq 1 ]]; then
      git push "$r" "$BRANCH:refs/heads/$BRANCH" 2>&1 | tail -2 | sed 's/^/      /' || { bad "[$r] 推送失败"; rc=2; }
    else
      rc=1
    fi
  else
    bad "[$r] 非快进（远端 ${REMOTE_SHA:0:8} 不是本地 $BRANCH 的祖先）⇒ 拒绝推送"
    bad "     处置：先 git fetch $r，人工比对后再决定（禁止 --force 掩盖）"
    rc=2
  fi
done

if [[ "$CHECK" -eq 1 && "$rc" -eq 0 ]]; then
  say "✓ 所有远端均已同步 $BRANCH"
fi
if [[ "$APPLY" -eq 0 && "$rc" -eq 1 ]]; then
  say "（dry-run：以上为待推清单；确认后跑 bash scripts/sync-remotes.sh --apply）"
fi
exit "$rc"
