#!/usr/bin/env bash
# tcpc-oa 插件仓 CI 判据（唯一出处）。本地与 GitHub Actions 跑**同一份**脚本。
#
# 为什么抽成脚本：写在 ci.yml 里会出现「CI 的 shell」与「本地验过的 shell」两份实现 ⇒ 漂移。
# 抽出后 `bash scripts/ci-check.sh` 通过 ⇒ CI 必然通过。
#
# 六项判据（都能真红，不是「跑通即绿」）：
#   ① php -l 全量（语法）
#   ② 版本三方一致：VERSION / TCPC_OA_VERSION 常量 / 插件头 Version
#   ③ 反向依赖守卫：本仓**不得**用 get_stylesheet_directory / get_template_directory / TEMPLATEPATH
#      直接拼主题内部路径（只能调主题契约函数）—— 骨架期业务为空，故判据应为 0 命中
#   ④ 骨架完整性：AGENTS.md / workbench 七件套 / docs/contract-tcpc-oa.md / MIN_THEME 均在
#   ⑤ 构建产物校验：git archive HEAD 必含 VERSION / MIN_THEME / 入口 / 契约（--precommit 跳过）
#   ⑥ 契约自洽：docs/contract-tcpc-oa.md 必含 P-1/P-2/P-3/W-1 四个符号 + 「唯一出处」声明
#
# 用法：
#   bash scripts/ci-check.sh             # 六项全跑（本地收口 / CI）
#   bash scripts/ci-check.sh --precommit # 跳过 ⑤（提交前新增文件尚未入 HEAD）
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PRECOMMIT=0
[[ "${1:-}" == "--precommit" ]] && PRECOMMIT=1

fail=0
note() { printf '  · %s\n' "$*"; }
bad()  { printf '  ✗ %s\n' "$*" >&2; fail=$((fail+1)); }
ok()   { printf '  ✓ %s\n' "$*"; }

echo "== ① php -l 全量"
php_files="$(find . -path ./vendor -prune -o -path ./node_modules -prune -o -path ./.git -prune -o -type f -name '*.php' -print | sort)"
lint_fail=0
while IFS= read -r f; do
  [ -z "$f" ] && continue
  if ! php -l "$f" >/dev/null 2>&1; then bad "语法错误：$f"; lint_fail=1; fi
done <<< "$php_files"
[ "$lint_fail" -eq 0 ] && ok "全部 PHP 语法通过（$(printf '%s\n' "$php_files" | grep -c . ) 个文件）"

echo "== ② 版本三方一致"
ver_file="$(tr -d ' \t\r\n' < VERSION 2>/dev/null || true)"
ver_const="$(grep -m1 -oE "define\('TCPC_OA_VERSION', *'[0-9][0-9.]*'" tcpc-oa.php 2>/dev/null | grep -oE "[0-9][0-9.]*" | head -1)"
ver_head="$(awk '/^ \* Version:/ {print $3; exit}' tcpc-oa.php 2>/dev/null)"
if [ -n "$ver_file" ] && [ "$ver_file" = "$ver_const" ] && [ "$ver_file" = "$ver_head" ]; then
  ok "VERSION = 常量 = 插件头 = $ver_file"
else
  bad "版本不一致：VERSION=[$ver_file] 常量=[$ver_const] 头=[$ver_head]"
fi

echo "== ③ 反向依赖守卫（不得直拼主题内部路径）"
hits="$(grep -rnE "get_stylesheet_directory|get_template_directory|TEMPLATEPATH" --include='*.php' . 2>/dev/null | grep -v '^./vendor/' | grep -v '^./.git/' || true)"
if [ -z "$hits" ]; then
  ok "0 命中（只用主题契约函数）"
else
  printf '%s\n' "$hits" | while IFS= read -r l; do note "$l"; done
  bad "命中 $(printf '%s\n' "$hits" | wc -l) 处主题路径直拼 ⇒ 必须改走契约"
fi

echo "== ④ 骨架完整性"
missing=0
for f in AGENTS.md workbench/load.sh workbench/preflight.sh workbench/finish.sh workbench/install-hooks.sh \
         workbench/pre-commit.sh workbench/commit-msg.sh workbench/task-space.sh \
         docs/contract-tcpc-oa.md MIN_THEME README.md CHANGELOG.md tcpc-oa.php scripts/plugin-switch-oa.sh; do
  if [ ! -f "$f" ]; then bad "缺文件：$f"; missing=1; fi
done
[ "$missing" -eq 0 ] && ok "骨架文件齐全"
min_file="$(tr -d ' \t\r\n' < MIN_THEME 2>/dev/null || true)"
min_const="$(grep -m1 -oE "define\('TCPC_OA_MIN_THEME', *'[0-9][0-9.]*'" tcpc-oa.php | grep -oE "[0-9][0-9.]*" | head -1)"
if [ -n "$min_file" ] && [ "$min_file" = "$min_const" ]; then ok "MIN_THEME = 常量 = $min_file"; else bad "MIN_THEME 不一致：文件=[$min_file] 常量=[$min_const]"; fi

echo "== ⑤ 构建产物校验"
if [ "$PRECOMMIT" -eq 1 ]; then
  note "跳过（--precommit：新增文件尚未入 HEAD）"
else
  tmp="$(mktemp -d)"
  if git archive HEAD | tar -x -C "$tmp" 2>/dev/null; then
    miss=0
    for f in VERSION MIN_THEME tcpc-oa.php docs/contract-tcpc-oa.md; do
      [ -f "$tmp/$f" ] || { bad "归档缺 $f"; miss=1; }
    done
    arc_ver="$(tr -d ' \t\r\n' < "$tmp/VERSION" 2>/dev/null || true)"
    [ "$arc_ver" = "$ver_file" ] || bad "归档 VERSION=[$arc_ver] 与工作区=[$ver_file] 不一致"
    [ "$miss" -eq 0 ] && ok "归档内容与版本一致"
  else
    bad "git archive 失败（仓库尚无提交？）"
  fi
  rm -rf "$tmp"
fi

echo "== ⑥ 契约自洽"
contract="docs/contract-tcpc-oa.md"
miss=0
for sym in 'zw_oa_legal_clause_snapshot' 'zw_oa_legal_pinned_clause_ids' 'zw_oa_branch_audit_records' 'zw_oa_branch_review_decide'; do
  grep -q "$sym" "$contract" 2>/dev/null || { bad "契约缺符号：$sym"; miss=1; }
done
grep -q "唯一出处" "$contract" 2>/dev/null || { bad "契约缺「唯一出处」声明"; miss=1; }
[ "$miss" -eq 0 ] && ok "契约面完整（4 符号 + 唯一出处声明）"

echo "----------------------------------------"
if [ "$fail" -gt 0 ]; then
  printf '✗ ci-check 未通过：%d 项判红\n' "$fail" >&2
  exit 1
fi
echo "✓ ci-check 六项全绿"
exit 0
