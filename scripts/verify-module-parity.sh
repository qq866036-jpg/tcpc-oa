#!/usr/bin/env bash
# 模块迁入的 diff=0 校验（唯一判据）—— TASK-2320 P2。
#
# 迁入纪律：**新仓模块目录必须与主题仓对应目录逐字节一致**（diff -r 为空），否则拒绝提交/发布。
# 这条判据同时防两种事故：
#   ① 迁入时"顺手改两行"（新仓与主题分叉，之后谁也说不清哪份是对的）；
#   ② 主题侧后来又改了而同批没同步（主题悄悄变成第二份实现）。
#
# 用法：
#   bash scripts/verify-module-parity.sh receipt
#   bash scripts/verify-module-parity.sh receipt --theme /srv/git/worktrees/dev-tcpc/main
#   bash scripts/verify-module-parity.sh receipt --expect-theme-absent   # 迁入完成后：主题侧不得再有实现
#
# 退出码：0 = 一致；1 = 有差异 / 缺目录；2 = 用法错误。
set -uo pipefail

MODULE="${1:-}"
shift || true

EXPECT_ABSENT=0

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
THEME="${ZW_OA_THEME:-/srv/git/worktrees/dev-tcpc/main}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --theme) THEME="${2:-}"; shift 2 ;;
    --expect-theme-absent) EXPECT_ABSENT=1; shift ;;
    -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
    *) echo "未知参数：$1" >&2; exit 2 ;;
  esac
done

[[ -n "$MODULE" ]] || { echo "用法：bash scripts/verify-module-parity.sh <module> [--theme DIR]" >&2; exit 2; }

# 模块 → 本仓目录 / 主题目录（与 includes/load-list.php 同源；新增模块须两处同步）
case "$MODULE" in
  receipt)      OA_DIR="$ROOT/includes/receipt";                 THEME_DIR="$THEME/inc/receipt" ;;
  secretariat)  OA_DIR="$ROOT/includes/secretariat";             THEME_DIR="$THEME/inc/secretariat" ;;
  doc)          OA_DIR="$ROOT/includes/doc";                     THEME_DIR="$THEME/inc/admin/doc" ;;
  *) echo "✗ 未登记模块：$MODULE（先在本脚本与 includes/load-list.php 登记）" >&2; exit 2 ;;
esac

# 主题侧**有意差异**（shim）：插件接管入口只能存在于主题侧；若照抄进插件会自我 require 递归。
# 因此 parity 时排除，并反向断言插件副本**不含**该 shim。
THEME_SHIM=""
case "$MODULE" in
  receipt) THEME_SHIM="receipt-bootstrap.php" ;;
esac

[[ -d "$OA_DIR" ]]    || { echo "✗ 本仓模块目录不存在：$OA_DIR" >&2; exit 1; }
[[ -d "$THEME_DIR" ]] || { echo "✗ 主题目录不存在：$THEME_DIR（主题工作区路径对吗？--theme）" >&2; exit 1; }

echo "== 模块 parity：$MODULE"
echo "   本仓：$OA_DIR"
echo "   主题：$THEME_DIR"
if [[ -n "$THEME_SHIM" ]]; then
  echo "   · 排除主题 shim：$THEME_SHIM（有意差异；插件副本不得含 shim）"
  if grep -q "TCPC_OA_DIR" "$OA_DIR/$THEME_SHIM" 2>/dev/null; then
    echo "✗ 插件副本含主题 shim（会自我 require 递归）：$OA_DIR/$THEME_SHIM" >&2
    exit 1
  fi
fi

DIFF_ARGS=(-r --brief)
[[ -n "$THEME_SHIM" ]] && DIFF_ARGS+=(--exclude="$THEME_SHIM")

# TASK-2320「迁入完成」模式：主题侧不得再存在该模块的实现副本（防"回退逻辑复活"），
# 只允许留 shim 一个文件。检查顺序：先确认插件侧齐全，再确认主题侧已清空。
if [[ "$EXPECT_ABSENT" -eq 1 ]]; then
  miss=0
  for f in receipt-helpers.php receipt-create.php receipt-ledger.php receipt-bootstrap.php receipt-storage.php; do
    [[ -f "$OA_DIR/$f" ]] || { echo "✗ 插件侧缺文件：$OA_DIR/$f" >&2; miss=1; }
  done
  [[ -n "$THEME_SHIM" && -f "$THEME_DIR/$THEME_SHIM" ]] || { echo "✗ 主题侧 shim 缺失：$THEME_DIR/$THEME_SHIM（fail-closed 提示不能删）" >&2; miss=1; }
  leftover=""
  while IFS= read -r f; do
    base="$(basename "$f")"
    [[ "$base" == "$THEME_SHIM" ]] && continue
    leftover+="$base "
  done < <(find "$THEME_DIR" -maxdepth 1 -type f -name '*.php' 2>/dev/null)
  if [[ -n "$leftover" ]]; then
    echo "✗ 主题侧仍有模块实现副本：$leftover（迁入完成后必须删除，否则重新变成双份实现）" >&2
    miss=1
  fi
  if [[ "$miss" -eq 0 ]]; then
    n="$(find "$OA_DIR" -type f | wc -l)"
    echo "✓ POST-MIGRATION OK：插件侧 $n 个文件齐全；主题侧仅剩 shim（$THEME_SHIM），无实现副本"
    exit 0
  fi
  exit 1
fi

if out="$(diff "${DIFF_ARGS[@]}" "$THEME_DIR" "$OA_DIR" 2>&1)"; then
  n="$(find "$OA_DIR" -type f | wc -l)"
  echo "✓ DIFF=0（$n 个文件逐字节一致$( [[ -n "$THEME_SHIM" ]] && echo "；已排除 1 个主题 shim" )）"
  exit 0
fi

printf '%s\n' "$out" | sed 's/^/  /' >&2
echo "✗ 存在差异（迁入必须 diff=0；差异要么补齐，要么明确记录为"迁入时的有意改动"并走 CHG）" >&2
exit 1
