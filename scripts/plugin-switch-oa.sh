#!/usr/bin/env bash
# tcpc-oa 发版通道（P3 · 唯一入口）—— 中传网办公协同插件
#
# 设计口径（与主题 production-switch / 证书 plugin-switch 同源，但独立实现，避免互相回归）：
#   ① 版本三方一致：VERSION == TCPC_OA_VERSION == 插件头 Version
#   ② 兼容矩阵：MIN_THEME <= 在线主题 VERSION（低于则拒发）
#   ③ 构建产物只来自 **git archive HEAD**（不带工作区脏文件）
#   ④ 备份：/root/tcpc-oa-plugin-backups/pre-switch-<ts>/tcpc-oa
#   ⑤ 原子交换：plugins/.tcpc-oa-staging-<ts> → plugins/tcpc-oa
#   ⑥ 冒烟（可 --no-smoke 跳过，但**发布场景不建议**）：插件已激活 / 版本常量 / 三个公开 URL 200 /
#      **/bhzc 正文哈希与发版前一致**（铁律 19：章程官网页只读）
#   ⑦ 回滚：--rollback <ts> 先 deactivate 再还原备份（与部署顺序相反）
#
# 用法：
#   bash scripts/plugin-switch-oa.sh --dry-run          # 只打印将要做什么
#   bash scripts/plugin-switch-oa.sh                    # 正式发版（含冒烟）
#   bash scripts/plugin-switch-oa.sh --no-smoke         # 仅交换（不推荐）
#   bash scripts/plugin-switch-oa.sh --rollback 20261028-1400
#
# 退出码：0 成功；1 任一判据失败；2 用法/环境错误。
set -uo pipefail

WP_PATH="/www/wwwroot/tcpc.org.cn"
PLUGIN_SLUG="tcpc-oa"
PLUGINS_ROOT="$WP_PATH/wp-content/plugins"
PLUGIN_DIR="$PLUGINS_ROOT/$PLUGIN_SLUG"
THEME_DIR="$WP_PATH/wp-content/themes/tcpc"
BACKUP_ROOT="/root/tcpc-oa-plugin-backups"
SMOKE_BASE="${ZW_OA_SMOKE_BASE:-https://www.tcpc.org.cn}"
SMOKE_HOST="${ZW_OA_SMOKE_HOST:-}"

DRY=0
NO_SMOKE=0
ROLLBACK=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY=1; shift ;;
    --no-smoke) NO_SMOKE=1; shift ;;
    --rollback) ROLLBACK="${2:-}"; shift 2 ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "未知参数：$1" >&2; exit 2 ;;
  esac
done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

STAMP="$(date +%Y%m%d-%H%M)"
say()  { printf '==> %s\n' "$*"; }
fail() { printf '✗ %s\n' "$*" >&2; exit 1; }
run()  { if [[ "$DRY" -eq 1 ]]; then printf '  [dry-run] %s\n' "$*"; else "$@"; fi; }

[[ -d "$WP_PATH" ]] || fail "生产路径不存在：$WP_PATH"
command -v wp >/dev/null 2>&1 || fail "缺 wp-cli"
command -v curl >/dev/null 2>&1 || fail "缺 curl"

# ── 回滚通道（与部署顺序相反：先 deactivate 再还原）──────────────────────────
if [[ -n "$ROLLBACK" ]]; then
  src="$BACKUP_ROOT/pre-switch-$ROLLBACK/$PLUGIN_SLUG"
  [[ -d "$src" ]] || fail "备份不存在：$src"
  say "回滚到 $ROLLBACK"
  run wp plugin deactivate "$PLUGIN_SLUG" --path="$WP_PATH" --allow-root || true
  if [[ -d "$PLUGIN_DIR" ]]; then
    run mv "$PLUGIN_DIR" "$PLUGIN_DIR.rollback-$STAMP"
  fi
  run cp -a "$src" "$PLUGIN_DIR"
  run rm -rf "$PLUGIN_DIR.rollback-$STAMP"
  run wp plugin activate "$PLUGIN_SLUG" --path="$WP_PATH" --allow-root
  say "回滚完成：$( [[ "$DRY" -eq 1 ]] && echo '(dry-run)' || echo "$PLUGIN_DIR" )"
  exit 0
fi

# ── ① 版本三方一致 ───────────────────────────────────────────────────────────
say "版本校验"
VERSION="$(tr -d ' \t\r\n' < VERSION)"
[[ -n "$VERSION" ]] || fail "VERSION 为空"
CONST="$(grep -m1 -oE "define\('TCPC_OA_VERSION', *'[0-9][0-9.]*'" tcpc-oa.php | grep -oE "[0-9][0-9.]*" | head -1)"
HEAD_VER="$(awk '/^ \* Version:/ {print $3; exit}' tcpc-oa.php)"
[[ "$VERSION" = "$CONST" ]] || fail "VERSION($VERSION) != 常量($CONST)"
[[ "$VERSION" = "$HEAD_VER" ]] || fail "VERSION($VERSION) != 插件头($HEAD_VER)"
echo "  ✓ VERSION = 常量 = 头 = $VERSION"

# ── ② 兼容矩阵 ───────────────────────────────────────────────────────────────
MIN_THEME_FILE="$(tr -d ' \t\r\n' < MIN_THEME 2>/dev/null || true)"
MIN_THEME_CONST="$(grep -m1 -oE "define\('TCPC_OA_MIN_THEME', *'[0-9][0-9.]*'" tcpc-oa.php | grep -oE "[0-9][0-9.]*" | head -1)"
[[ -n "$MIN_THEME_FILE" && "$MIN_THEME_FILE" = "$MIN_THEME_CONST" ]] || fail "MIN_THEME 文件($MIN_THEME_FILE) != 常量($MIN_THEME_CONST)"
LIVE_THEME="$(tr -d ' \t\r\n' < "$THEME_DIR/VERSION" 2>/dev/null || true)"
[[ -n "$LIVE_THEME" ]] || fail "读不到在线主题版本：$THEME_DIR/VERSION"
if [[ "$(printf '%s\n%s\n' "$MIN_THEME_FILE" "$LIVE_THEME" | sort -V | head -1)" != "$MIN_THEME_FILE" ]]; then
  fail "在线主题 $LIVE_THEME 低于插件要求的 $MIN_THEME_FILE（拒发）"
fi
echo "  ✓ 在线主题 $LIVE_THEME >= MIN_THEME $MIN_THEME_FILE"

# ── ③ 构建产物（只取 HEAD）───────────────────────────────────────────────────
say "构建产物（git archive HEAD）"
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || fail "工作区有未提交改动（构建必须来自 HEAD）"
STAGING="$PLUGINS_ROOT/.${PLUGIN_SLUG}-staging-${STAMP}"
run mkdir -p "$STAGING"
if [[ "$DRY" -eq 0 ]]; then
  git archive HEAD | tar -x -C "$STAGING" || fail "构建失败"
  for f in tcpc-oa.php VERSION MIN_THEME; do
    [[ -f "$STAGING/$f" ]] || fail "构建缺 $f"
  done
  echo "  ✓ 产物齐全：$(find "$STAGING" -type f | wc -l) 个文件"
fi

# ── ④ 备份 ───────────────────────────────────────────────────────────────────
say "备份在线版本"
# 铁律 19：先取《章程》官网页正文哈希（**发版前**），发版后必须一致。
BHZC_BEFORE=""
if [[ "$DRY" -eq 0 ]]; then
  # 用**页面正文（DB post_content）**的哈希，而不是整页 HTML 哈希：整页含 nonce/统计/时间戳等动态片段，
  # 两次抓取必然不同（首次演练实测：同一秒内两次 md5 就不同）⇒ 那样的判据只会恒红。
  BHZC_BEFORE="$(wp eval 'echo md5((string) get_post(64)->post_content);' --path="$WP_PATH" --allow-root 2>/dev/null || true)"
  echo "  · /bhzc 正文（DB）发版前哈希：${BHZC_BEFORE:-<取不到>}"
fi
BACKUP_DIR="$BACKUP_ROOT/pre-switch-$STAMP"
if [[ -d "$PLUGIN_DIR" ]]; then
  run mkdir -p "$BACKUP_DIR"
  run cp -a "$PLUGIN_DIR" "$BACKUP_DIR/$PLUGIN_SLUG"
  echo "  ✓ 已备份 → $BACKUP_DIR/$PLUGIN_SLUG"
else
  echo "  · 在线无该插件目录（首次安装）⇒ 无需备份"
fi

# ── ⑤ 原子交换 ───────────────────────────────────────────────────────────────
say "原子交换"
if [[ -d "$PLUGIN_DIR" ]]; then
  run mv "$PLUGIN_DIR" "$PLUGIN_DIR.old-$STAMP"
fi
run mv "$STAGING" "$PLUGIN_DIR"
run rm -rf "$PLUGIN_DIR.old-$STAMP"
echo "  ✓ 已落盘：$PLUGIN_DIR（v$VERSION）"

# ── ⑥ 冒烟 ───────────────────────────────────────────────────────────────────
if [[ "$NO_SMOKE" -eq 1 ]]; then
  say "冒烟已跳过（--no-smoke，不作为放行依据）"
  exit 0
fi
[[ "$DRY" -eq 1 ]] && { say "dry-run：跳过冒烟"; exit 0; }

say "冒烟"
if ! wp plugin is-active "$PLUGIN_SLUG" --path="$WP_PATH" --allow-root >/dev/null 2>&1; then
  wp plugin activate "$PLUGIN_SLUG" --path="$WP_PATH" --allow-root >/dev/null || fail "插件激活失败"
fi
echo "  ✓ 插件已激活"

LIVE_CONST="$(wp eval 'echo defined("TCPC_OA_VERSION") ? TCPC_OA_VERSION : "";' --path="$WP_PATH" --allow-root 2>/dev/null)"
[[ "$LIVE_CONST" = "$VERSION" ]] || fail "运行时常量($LIVE_CONST) != 发版版本($VERSION)"
echo "  ✓ 运行时常量 = $VERSION"

# -L：生产有 apex→www 与 slug 规范化的 301（如 /privacy-policy/ → /privacy-policy），
# 不跟随会把 301 误判成"冒烟失败"（首次演练实测踩过）。
CURL=(-sS -L --max-time 20 -o /tmp/zw-oa-smoke.body -w '%{http_code}')
[[ -n "$SMOKE_HOST" ]] && CURL+=(-H "Host: $SMOKE_HOST")
check_url() { # check_url <path> <must-contain>
  local path="$1" needle="${2:-}" code
  code="$(curl "${CURL[@]}" "${SMOKE_BASE%/}${path}" 2>/dev/null || true)"
  [[ "$code" = "200" ]] || fail "冒烟失败：$path → HTTP $code"
  if [[ -n "$needle" ]] && ! grep -qF -- "$needle" /tmp/zw-oa-smoke.body 2>/dev/null; then
    fail "冒烟失败：$path 缺少 «$needle»"
  fi
  echo "  ✓ $path 200"
}
check_url "/login" "登录"
check_url "/privacy-policy/"
# 铁律 19：章程官网页正文不得变化（发版前后哈希对比）
check_url "/bhzc"
BHZC_AFTER="$(wp eval 'echo md5((string) get_post(64)->post_content);' --path="$WP_PATH" --allow-root 2>/dev/null || true)"
if [[ -n "$BHZC_BEFORE" && "$BHZC_BEFORE" != "$BHZC_AFTER" ]]; then
  fail "/bhzc 正文（DB）哈希变化（铁律 19 违规）：before=$BHZC_BEFORE after=$BHZC_AFTER"
fi
if [[ -z "$BHZC_BEFORE" ]]; then
  echo "  ⚠ /bhzc 正文哈希取不到（wp eval 失败）⇒ 未验证，**不作为放行依据**"
else
  echo "  ✓ /bhzc 正文（DB）未变化（$BHZC_AFTER）"
fi

say "发版完成：v$VERSION（备份 $BACKUP_DIR）"
say "回滚命令：bash scripts/plugin-switch-oa.sh --rollback $STAMP"
exit 0
