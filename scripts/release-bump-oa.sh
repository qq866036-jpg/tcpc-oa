#!/usr/bin/env bash
# tcpc-oa 原子升版入口（OA-2507）—— VERSION / 插件头 / TCPC_OA_VERSION **三处同升**的唯一脚本入口。
#
# 为什么需要：
#   OA 仓的历史发版（0.1.26→0.1.27→0.1.28）都是「手工三处同改 + 提交」，漏改/写错只能靠 `ci-check` ②
#   事后发现；且没有回读，写坏了会把半升状态带进构建。本脚本把「升版」变成一个原子动作：
#     ① 先校验升版前三方一致（不一致 ⇒ 拒升，避免把既有坏状态再放大）；
#     ② 计算下一个版本（patch/minor/major），三处一次性写入；
#     ③ 写后**回读**三处并校验一致，失败整体回滚（临时文件 + 原子 mv）。
#
# 用法：
#   bash scripts/release-bump-oa.sh patch "说明"
#   bash scripts/release-bump-oa.sh minor "说明"
#   bash scripts/release-bump-oa.sh major "说明"
#
# 说明不可为空（会打印到标准输出前的提示里；台账留痕由调用方负责）。
# 退出码：0 成功（stdout 单行 = 新版本号）；1 状态不符/回读失败；2 用法/环境错误。
#
# 测试接缝：`ZW_OA_REPO_ROOT` 指向夹具目录（含 VERSION + tcpc-oa.php），供 tests/test-release-bump-oa.sh 使用。
set -euo pipefail

ROOT="${ZW_OA_REPO_ROOT:-$(git rev-parse --show-toplevel 2>/dev/null || pwd)}"
cd "$ROOT"

KIND="${1:-}"
NOTE="${2:-}"

die() { printf '✗ %s\n' "$*" >&2; exit 2; }
fail() { printf '✗ %s\n' "$*" >&2; exit 1; }

case "$KIND" in
  patch|minor|major) ;;
  -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
  *) die "用法：bash scripts/release-bump-oa.sh patch|minor|major \"说明\"" ;;
esac
if [[ -z "${NOTE// /}" ]]; then
  die "说明不能为空：bash scripts/release-bump-oa.sh ${KIND} \"本次升版说明\""
fi

[[ -f VERSION ]] || fail "未找到 VERSION（root=${ROOT}）"
[[ -f tcpc-oa.php ]] || fail "未找到 tcpc-oa.php（root=${ROOT}）"

CUR="$(tr -d ' \t\r\n' < VERSION)"
if [[ ! "$CUR" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
  fail "VERSION 形态非法（应为 MAJOR.MINOR.PATCH）：${CUR}"
fi
MAJOR="${BASH_REMATCH[1]}"; MINOR="${BASH_REMATCH[2]}"; PATCH="${BASH_REMATCH[3]}"

CONST="$(grep -m1 -oE "define\('TCPC_OA_VERSION', *'[0-9]+\.[0-9]+\.[0-9]+'" tcpc-oa.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" || true)"
HEAD_VER="$(awk '/^ \* Version:/ {print $3; exit}' tcpc-oa.php)"
[[ -n "$CONST" && -n "$HEAD_VER" ]] || fail "读不到插件常量或 Version 头（常量=${CONST:-空} 头=${HEAD_VER:-空}）"
if [[ "$CUR" != "$CONST" || "$CUR" != "$HEAD_VER" ]]; then
  fail "升版前三方不一致：VERSION=${CUR} / 常量=${CONST} / 头=${HEAD_VER} ⇒ 先修复再升版"
fi

# 有 git 且是工作区时，要求 VERSION / tcpc-oa.php 无未提交改动（避免把 WIP 混进升版）。
if command -v git >/dev/null 2>&1 && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  DIRTY="$(git status --porcelain -- VERSION tcpc-oa.php || true)"
  [[ -z "$DIRTY" ]] || fail "VERSION / tcpc-oa.php 存在未提交改动 ⇒ 先提交或还原再升版"
fi

case "$KIND" in
  patch) PATCH=$((PATCH + 1)) ;;
  minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
  major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
esac
NEW="${MAJOR}.${MINOR}.${PATCH}"

TMP_V="$(mktemp)"
TMP_P="$(mktemp)"
cleanup() { rm -f "$TMP_V" "$TMP_P"; }
trap cleanup EXIT

printf '%s\n' "$NEW" > "$TMP_V"
sed -e "s/^ \* Version:     .*/ * Version:     ${NEW}/" \
    -e "s/define('TCPC_OA_VERSION', '[0-9][0-9.]*')/define('TCPC_OA_VERSION', '${NEW}')/" \
    tcpc-oa.php > "$TMP_P"

# 回读：新文件必须三处都是 NEW，且插件头/常量各只出现一次目标值（防 sed 误伤其它数字）。
NEW_CONST="$(grep -m1 -oE "define\('TCPC_OA_VERSION', *'[0-9]+\.[0-9]+\.[0-9]+'" "$TMP_P" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" || true)"
NEW_HEAD="$(awk '/^ \* Version:/ {print $3; exit}' "$TMP_P")"
if [[ "$(tr -d ' \t\r\n' < "$TMP_V")" != "$NEW" || "$NEW_CONST" != "$NEW" || "$NEW_HEAD" != "$NEW" ]]; then
  fail "回读失败（新版本=${NEW} / 常量=${NEW_CONST:-空} / 头=${NEW_HEAD:-空}）⇒ 未落盘，原文件未变"
fi

mv "$TMP_V" VERSION
mv "$TMP_P" tcpc-oa.php

# 落盘后再回读一次（防止并发/文件系统异常）
FINAL_V="$(tr -d ' \t\r\n' < VERSION)"
FINAL_CONST="$(grep -m1 -oE "define\('TCPC_OA_VERSION', *'[0-9]+\.[0-9]+\.[0-9]+'" tcpc-oa.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+" || true)"
FINAL_HEAD="$(awk '/^ *\* Version:/ {print $3; exit}' tcpc-oa.php)"
if [[ "$FINAL_V" != "$NEW" || "$FINAL_CONST" != "$NEW" || "$FINAL_HEAD" != "$NEW" ]]; then
  printf '✗ 落盘后回读不一致：VERSION=%s 常量=%s 头=%s\n' "$FINAL_V" "${FINAL_CONST:-空}" "${FINAL_HEAD:-空}" >&2
  exit 1
fi

printf 'ℹ 升版：%s → %s（%s）—— %s\n' "$CUR" "$NEW" "$KIND" "$NOTE" >&2
printf '%s\n' "$NEW"
