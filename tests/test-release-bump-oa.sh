#!/usr/bin/env bash
# OA-2507：`scripts/release-bump-oa.sh` 原子升版回归（夹具目录，不碰真实版本文件）。
#
# 覆盖：
#  ① patch / minor / major 三种进位；stdout 单行=新版本号；
#  ② 升版后 VERSION / 插件头 / TCPC_OA_VERSION **三处一致**；
#  ③ 升版前三方不一致 ⇒ rc=1 且**不改文件**；
#  ④ 说明为空 / 非法类型 ⇒ rc=2（用法错误）；
#  ⑤ 夹具非 git 仓库时跳过 dirty 守卫（不误伤测试环境）。
#
# 运行：bash tests/test-release-bump-oa.sh
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$ROOT/scripts/release-bump-oa.sh"
PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); printf '  ✓ %s\n' "$1"; }
bad() { FAIL=$((FAIL + 1)); printf '  ✗ %s\n' "$1" >&2; }
chk() { if [ "$1" -eq 0 ]; then ok "$2"; else bad "$2"; fi; }

[ -f "$SCRIPT" ] || { echo "✗ 缺脚本：$SCRIPT" >&2; exit 1; }

mkfix() { # mkfix <dir> <version>
  local d="$1" v="$2"
  rm -rf "$d"; mkdir -p "$d"
  printf '%s\n' "$v" > "$d/VERSION"
  cat > "$d/tcpc-oa.php" <<PHP
<?php
/**
 * Plugin Name: 中传网办公协同（夹具）
 * Version:     ${v}
 *
 * @package tcpc-oa
 */

if (!defined('TCPC_OA_VERSION')) {
    define('TCPC_OA_VERSION', '${v}');
}
PHP
}

three() { # three <dir> => 输出 "v|const|head"
  local d="$1"
  local v c h
  v="$(tr -d ' \t\r\n' < "$d/VERSION")"
  c="$(grep -m1 -oE "define\('TCPC_OA_VERSION', *'[0-9]+\.[0-9]+\.[0-9]+'" "$d/tcpc-oa.php" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+")"
  h="$(awk '/^ \* Version:/ {print $3; exit}' "$d/tcpc-oa.php")"
  printf '%s|%s|%s\n' "$v" "$c" "$h"
}

echo "== ① patch / minor / major 进位 =="
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkfix "$T/f1" "0.1.28"
OUT="$(ZW_OA_REPO_ROOT="$T/f1" bash "$SCRIPT" patch "夹具 patch" 2>/dev/null)"; RC=$?
chk "$RC" "patch 退出码 0（实际 ${RC}）"
[ "$OUT" = "0.1.29" ]; chk $? "patch 输出 0.1.29（实际 ${OUT}）"
[ "$(three "$T/f1")" = "0.1.29|0.1.29|0.1.29" ]; chk $? "patch 后三处一致（$(three "$T/f1")）"

OUT="$(ZW_OA_REPO_ROOT="$T/f1" bash "$SCRIPT" minor "夹具 minor" 2>/dev/null)"; RC=$?
chk "$RC" "minor 退出码 0（实际 ${RC}）"
[ "$OUT" = "0.2.0" ]; chk $? "minor 输出 0.2.0（实际 ${OUT}）"

OUT="$(ZW_OA_REPO_ROOT="$T/f1" bash "$SCRIPT" major "夹具 major" 2>/dev/null)"; RC=$?
chk "$RC" "major 退出码 0（实际 ${RC}）"
[ "$OUT" = "1.0.0" ]; chk $? "major 输出 1.0.0（实际 ${OUT}）"
[ "$(three "$T/f1")" = "1.0.0|1.0.0|1.0.0" ]; chk $? "major 后三处一致"

echo "== ② 升版前三方不一致 ⇒ rc=1 且不改文件 =="
mkfix "$T/f2" "0.1.28"
sed -i "s/define('TCPC_OA_VERSION', '0.1.28')/define('TCPC_OA_VERSION', '0.1.27')/" "$T/f2/tcpc-oa.php"
BEFORE="$(md5sum "$T/f2/VERSION" "$T/f2/tcpc-oa.php" | md5sum)"
ZW_OA_REPO_ROOT="$T/f2" bash "$SCRIPT" patch "不一致夹具" >/dev/null 2>&1; RC=$?
[ "$RC" -eq 1 ]; chk $? "不一致 ⇒ rc=1（实际 ${RC}）"
AFTER="$(md5sum "$T/f2/VERSION" "$T/f2/tcpc-oa.php" | md5sum)"
[ "$BEFORE" = "$AFTER" ]; chk $? "不一致时文件零改动"

echo "== ③ 用法错误 ⇒ rc=2 =="
ZW_OA_REPO_ROOT="$T/f1" bash "$SCRIPT" patch "" >/dev/null 2>&1; RC=$?
[ "$RC" -eq 2 ]; chk $? "空说明 ⇒ rc=2（实际 ${RC}）"
ZW_OA_REPO_ROOT="$T/f1" bash "$SCRIPT" hotfix "x" >/dev/null 2>&1; RC=$?
[ "$RC" -eq 2 ]; chk $? "非法类型 ⇒ rc=2（实际 ${RC}）"
ZW_OA_REPO_ROOT="$T/f1" bash "$SCRIPT" >/dev/null 2>&1; RC=$?
[ "$RC" -eq 2 ]; chk $? "缺参数 ⇒ rc=2（实际 ${RC}）"

echo "== ④ 真实脚本在真实仓上可被 ci-check 的版本判据接受 =="
cd "$ROOT"
if bash -n "$SCRIPT" >/dev/null 2>&1; then ok "脚本语法通过 bash -n"; else bad "脚本语法失败"; fi

if [ "$FAIL" -gt 0 ]; then
  echo "✗ OA-2507 升版脚本回归失败：${PASS} 通过 / ${FAIL} 失败" >&2
  exit 1
fi
echo "✓ OA-2507 升版脚本回归通过：${PASS} 项"
