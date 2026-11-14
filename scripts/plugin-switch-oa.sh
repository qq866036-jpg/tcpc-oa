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
#   bash scripts/plugin-switch-oa.sh --allow-downgrade  # 放开版本降级（回退旧构建）
#   ZW_RELEASE_LOCK=0 bash scripts/plugin-switch-oa.sh  # 应急跳过发版互斥（须在台账写明理由）
#
# 发版互斥（OA-2494）：唯一实现 `scripts/lib/release-lock.sh`（flock 单实例 + 版本占用禁降级 + 进程退出自愈）。
#   本脚本有**两条生产写入路径**（正常发版 ④⑤、回滚 `--rollback`），两条都要取锁；
#   版本守卫只对正常路径生效（回滚本身就是降级，内部放开）。锁被占 / 降级被拒均**未改动生产目录**。
#
# 退出码：0 成功；1 任一判据失败；2 用法/环境错误；3 锁被占或版本降级被拒。
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
ALLOW_DOWNGRADE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY=1; shift ;;
    --no-smoke) NO_SMOKE=1; shift ;;
    --rollback) ROLLBACK="${2:-}"; shift 2 ;;
    --allow-downgrade|--force-unlock) ALLOW_DOWNGRADE=1; shift ;;
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

OWNER="${ZW_OA_OWNER:-www:www}"
TASK_ID="${ZW_TASK:-未记}"

# ── 发版互斥唯一实现（OA-2494 / 口径 docs/ops/plugin-release-lock.md）─────────────
# 为什么 source 而不是复刻：锁语义只有一份（flock 单实例 + 版本占用禁降级 + 进程退出自愈）；
# 本仓 `scripts/lib/release-lock.sh` 是**逐字副本**，判据：主题 `tests/test-release-lock-parity.sh`。
. "$ROOT/scripts/lib/release-lock.sh"

# ── 属主/权限归一 + 回读（OA-2494：回读由「只告警」改为 fail-closed）──────────────
# 治什么：安装目录不可读 ⇒ 插件「active 却不加载」；CLI 以 root 跑 `wp eval` **看不出来**
# （learning 仓 `root:root 700` 事故同源）。所以不只要 chown，还要 chmod + **回读**。
oa_normalize_perms() {
  local dir="$1"
  [[ -d "$dir" ]] || return 0
  chown -R "$OWNER" "$dir" 2>/dev/null || echo "  ⚠ 属主归一失败（$OWNER），请手工 chown -R" >&2
  find "$dir" -type d -exec chmod 755 {} \; 2>/dev/null || true
  find "$dir" -type f -exec chmod 644 {} \; 2>/dev/null || true
}

oa_verify_perms() {
  local dir="$1" mode bad
  [[ -d "$dir" ]] || return 1
  mode="$(stat -c '%a %U:%G' "$dir" 2>/dev/null || echo '?')"
  bad="$(find "$dir" -type f ! -perm -o+r 2>/dev/null | wc -l | tr -d ' ')"
  echo "  属主/权限：${mode}（期望 755 ${OWNER}）｜非 o+r 文件：${bad}"
  [[ "$mode" = "755 ${OWNER}" && "$bad" = "0" ]]
}

# 回读失败 ⇒ 用**本次备份**自动还原（对称恢复；不依赖人工记得跑 --rollback）
oa_restore_backup() {
  [[ -d "$BACKUP_DIR/$PLUGIN_SLUG" ]] || { echo "  ⚠ 无可用备份（$BACKUP_DIR），请人工处置" >&2; return 1; }
  wp plugin deactivate "$PLUGIN_SLUG" --path="$WP_PATH" --allow-root >/dev/null 2>&1 || true
  [[ -d "$PLUGIN_DIR" ]] && mv "$PLUGIN_DIR" "$PLUGIN_DIR.failed-$STAMP"
  cp -a "$BACKUP_DIR/$PLUGIN_SLUG" "$PLUGIN_DIR"
  oa_normalize_perms "$PLUGIN_DIR"
  wp plugin activate "$PLUGIN_SLUG" --path="$WP_PATH" --allow-root >/dev/null 2>&1 || true
  rm -rf "$PLUGIN_DIR.failed-$STAMP"
  echo "  ✓ 已还原备份：$BACKUP_DIR/$PLUGIN_SLUG"
}

# 发布成功：**先写版本记录再解锁**（失败/回滚路径只解锁，由 trap 兜）。
oa_publish_ok() {
  [[ "${ZW_RELEASE_LOCK:-1}" = "0" ]] && return 0
  [[ "${DRY:-0}" -eq 1 ]] && return 0      # dry-run 没有发布，不写版本记录（trap 会解锁）
  zwrel_release "$PLUGIN_SLUG" "$VERSION" "$TASK_ID"
  trap - EXIT INT TERM
  echo "  ✓ 已释放发版锁并记录版本 $VERSION（后续更低版本会被拒）"
}

[[ -d "$WP_PATH" ]] || fail "生产路径不存在：$WP_PATH"
command -v wp >/dev/null 2>&1 || fail "缺 wp-cli"
command -v curl >/dev/null 2>&1 || fail "缺 curl"

# ── 回滚通道（与部署顺序相反：先 deactivate 再还原）──────────────────────────
if [[ -n "$ROLLBACK" ]]; then
  src="$BACKUP_ROOT/pre-switch-$ROLLBACK/$PLUGIN_SLUG"
  [[ -d "$src" ]] || fail "备份不存在：$src"
  say "回滚到 $ROLLBACK"
  RB_VER="$(tr -d ' \t\r\n' < "$src/VERSION" 2>/dev/null || true)"
  if [[ -z "$RB_VER" ]]; then
    # VERSION 是 `export-ignore` 治理文件（不随产物上线）⇒ 线上/备份目录里没有它。
    # 2026-11-14 实测：原实现读不到 VERSION 就把版本记录写成 0.0.0（等于给之后所有版本放行降级）。
    RB_VER="$(awk '/^ \* Version:/ {print $3; exit}' "$src/tcpc-oa.php" 2>/dev/null || true)"
  fi
  # 回滚也是一次**生产写入** ⇒ 同样要单实例锁；版本守卫显式放开（回滚本身就是降级）。
  if [[ "${ZW_RELEASE_LOCK:-1}" != "0" ]]; then
    if ! ZW_RELEASE_ALLOW_DOWNGRADE=1 zwrel_acquire "$PLUGIN_SLUG" "${RB_VER:-0.0.0}" "$TASK_ID"; then
      fail "发版互斥未通过（锁被占）；**未改动生产目录**"
    fi
    trap 'zwrel_unlock "$PLUGIN_SLUG"' EXIT INT TERM
  fi
  run wp plugin deactivate "$PLUGIN_SLUG" --path="$WP_PATH" --allow-root || true
  if [[ -d "$PLUGIN_DIR" ]]; then
    run mv "$PLUGIN_DIR" "$PLUGIN_DIR.rollback-$STAMP"
  fi
  run cp -a "$src" "$PLUGIN_DIR"
  run rm -rf "$PLUGIN_DIR.rollback-$STAMP"
  run wp plugin activate "$PLUGIN_SLUG" --path="$WP_PATH" --allow-root
  if [[ "${ZW_RELEASE_LOCK:-1}" != "0" && "$DRY" -eq 0 ]]; then
    if [[ -n "$RB_VER" ]]; then
      zwrel_release "$PLUGIN_SLUG" "$RB_VER" "$TASK_ID"
      trap - EXIT INT TERM
      echo "  ✓ 已释放发版锁并记录回滚到的版本 $RB_VER"
    else
      zwrel_unlock "$PLUGIN_SLUG"
      trap - EXIT INT TERM
      echo "  ⚠ 取不到回滚版本号（备份缺 VERSION 且插件头解析失败）⇒ 仅解锁，**不写版本记录**" >&2
    fi
  fi
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

# ── ②a 主题兼容门禁（OA-2502 事故沉淀：0.1.27 首次发版即 fatal 并回滚）────────────
# 背景（2026-11-14 实测）：0.1.27 起插件 `policy` 模块与主题 `inc/policy-platform/` 回退副本会声明同名函数
# （首例 `zw_policy_platform_cron_schedules()`，两处都在第 172 行）。只要在线主题还是**旧版**（functions.php
# 无条件 require 主题副本），交换后首次加载即 `Cannot redeclare` ⇒ 整站 500，冒烟前就挂。
# 正确形态：主题必须先是「插件优先 / 停用回退」的条件加载版本（`function_exists` 守卫），再发本插件；
# 反过来（新主题 + 旧插件）是安全的 —— 旧插件缺的符号由主题副本补齐。
# OA-2503 放宽一档（仍 fail-closed）：主题旧版但 **policy 模块已关**时允许发版 ——
#   policy 模块的 14 个文件全部经 `includes/load-list.php` 的双钥匙装载（option `tcpc_oa_enable_policy`），
#   关掉后插件不声明任何 policy 符号 ⇒ 与主题副本不再冲突，功能继续由主题副本提供（与发版前一致）。
#   这样插件（API/组织契约/收发文/网盘/秘书处）可以先上线，policy 迁移等主题发版后再开。
# 判据放在**任何生产写入之前**，不满足直接拒发，不交换目录。
THEME_FUNCTIONS="$THEME_DIR/functions.php"
[[ -f "$THEME_FUNCTIONS" ]] || fail "读不到在线主题入口：$THEME_FUNCTIONS"
if ! grep -q "function_exists('zw_policy_platform_cron_mask_webhook'" "$THEME_FUNCTIONS"; then
  POLICY_LIVE="$(wp option get tcpc_oa_enable_policy --path="$WP_PATH" --allow-root 2>/dev/null | tr -d ' \t\r\n' || true)"
  if [[ "$POLICY_LIVE" != "0" ]]; then
    fail "在线主题不兼容（缺插件优先守卫）且插件 policy 模块开启（tcpc_oa_enable_policy=${POLICY_LIVE:-<未设置>}）⇒ 交换后会 Cannot redeclare；先 wp option update tcpc_oa_enable_policy 0（policy 由主题副本提供）或先发主题"
  fi
  echo "  ⚠ 在线主题旧版：policy 模块已关（option=0）⇒ 允许发版；policy 由主题副本继续提供，主题发版后再开启"
else
  echo "  ✓ 主题兼容：functions.php 含插件优先条件加载守卫"
fi

# ── ②b 发版互斥（OA-2494）——「前置校验之后、任何生产写入（构建目录 / 备份 / 交换）之前」
# 两道守卫：① 单实例（flock 仓外锁，第二者 rc=3 并打印持有者，**不动生产目录**）；
#          ② 版本占用（目标 < 最近成功发布 ⇒ 拒绝，防「从更早分支发低版本覆盖」）。
# 失败/回滚只解锁；只有**成功发布**才写版本记录（见 oa_publish_ok）。
if [[ "${ZW_RELEASE_LOCK:-1}" = "0" ]]; then
  echo "  ⚠ 已跳过发版互斥（ZW_RELEASE_LOCK=0）：并发发版可能互相覆盖（须在台账写明理由）" >&2
else
  if [[ "$ALLOW_DOWNGRADE" -eq 1 ]]; then
    export ZW_RELEASE_ALLOW_DOWNGRADE=1
    echo "  ⚠ 已请求放开版本降级（--allow-downgrade / --force-unlock）" >&2
  fi
  if ! zwrel_acquire "$PLUGIN_SLUG" "$VERSION" "$TASK_ID"; then
    fail "发版互斥未通过（锁被占 / 版本降级被拒）；**未改动生产目录**"
  fi
  trap 'zwrel_unlock "$PLUGIN_SLUG"' EXIT INT TERM
  echo "  ✓ 已取得 $PLUGIN_SLUG 发版锁（task=$TASK_ID，target=$VERSION）"
fi

# ── ③ 构建产物（只取 HEAD）───────────────────────────────────────────────────
say "构建产物（git archive HEAD）"
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || fail "工作区有未提交改动（构建必须来自 HEAD）"
STAGING="$PLUGINS_ROOT/.${PLUGIN_SLUG}-staging-${STAMP}"
run mkdir -p "$STAGING"
if [[ "$DRY" -eq 0 ]]; then
  git archive HEAD | tar -x -C "$STAGING" || fail "构建失败"
  # 产物只应含运行时（.gitattributes 已 export-ignore 治理/发版物料）；
  # 这里同时做正向与反向断言，防"漏排除"把 .sh/.md 又发上线（实测曾公网可读）。
  for f in tcpc-oa.php uninstall.php includes/load-list.php; do
    [[ -f "$STAGING/$f" ]] || fail "构建缺运行时文件：$f"
  done
  for f in AGENTS.md README.md workbench scripts docs .githooks tests VERSION MIN_THEME; do
    [[ -e "$STAGING/$f" ]] && fail "构建含治理文件（不应上线）：$f"
  done
  echo "  ✓ 产物齐全：$(find "$STAGING" -type f | wc -l) 个文件（仅运行时代码）"
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

# ── ⑤b 属主/权限归一 + 回读（2026-11-06 事故教训；OA-2494 起回读 fail-closed）────────
# 背景：同源的 learning 插件曾因安装目录变成 `root:root 700` ⇒ PHP-FPM(www) 连目录都进不去 ⇒
#   插件"显示 active 却从不加载"；而 CLI 以 root 运行、`wp eval` **看不出来**。本仓实测曾是
#   `root:root 755`（靠 o+r 兜着），一旦某次复制带来 600 文件就会复现。这里强制归一 + **回读**。
if [[ "$DRY" -eq 1 ]]; then
  echo "  [dry-run] 跳过属主/权限归一与回读"
else
  oa_normalize_perms "$PLUGIN_DIR"
  if ! oa_verify_perms "$PLUGIN_DIR"; then
    echo "✗ 属主/权限回读失败（期望 755 $OWNER / 文件 644）⇒ 用本次备份自动还原" >&2
    oa_restore_backup || true
    fail "权限回读失败，已尝试还原（备份 $BACKUP_DIR）；请人工复核"
  fi
fi

# ── ⑥ 冒烟 ───────────────────────────────────────────────────────────────────
if [[ "$NO_SMOKE" -eq 1 ]]; then
  oa_publish_ok
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

# ── OA-2502：结构冒烟扩面（TASK-2306 §10 G3）────────────────────────────────
# 旧口径只验 /login、/privacy-policy/、/bhzc —— 发版后哪怕六个办公菜单全没注册、API 清单缺失、
# 模块符号没加载，也会「冒烟通过」。本段把 G3 的「6 菜单 + 模块面」落成可判据：
#   ① 六个后台菜单 slug 真实注册（以 admin 用户 1 触发 admin_menu，回避 CLI 无 caps 的假阴）；
#   ② 关键模块符号在场（发文页回调 / 条款读源 / 签署判定 / OA API / 组织写口 / 政策引擎）；
#   ③ API 清单结构（11 符号 / version=1）与 REST health 可路由；
#   ④ /userra/ 200（用户协议）。
# 判别力：旧构建（0.1.26 / OA-2491）没有 `tcpc_oa_api_manifest` ⇒ 本段必红（已实测）。
say "结构冒烟（六菜单 / 模块符号 / API 清单 / REST）"
SMOKE_PHP="$(mktemp /tmp/zw-oa-smoke-php.XXXXXX.php)"
cat > "$SMOKE_PHP" <<'PHP'
<?php
$errors = array();
$need_menus = array('zw-doc', 'zw-receipt', 'zw-org', 'zw-policy-platform', 'zw-org-files', 'zw-secretariat');
$need_funcs = array(
    'zw_doc_page_list',
    'zw_legal_text_current',
    'zw_legal_consent_enforce_enabled',
    'tcpc_oa_api_manifest',
    'zw_oa_org_person_meta_set',
    'zw_policy_check',
);
$need_files = array(
    'includes/api/oa-api.php',
    'includes/org/org-contract.php',
    'includes/policy/legal-text-source.php',
);
require_once ABSPATH . 'wp-admin/includes/admin.php';
do_action('admin_menu');
global $menu, $submenu;
$slugs = array();
foreach ((array) $menu as $m) {
    if (!empty($m[2])) {
        $slugs[] = (string) $m[2];
    }
}
foreach ((array) $submenu as $items) {
    foreach ((array) $items as $it) {
        if (!empty($it[2])) {
            $slugs[] = (string) $it[2];
        }
    }
}
foreach ($need_menus as $slug) {
    if (!in_array($slug, $slugs, true)) {
        $errors[] = '菜单缺失：' . $slug;
    }
}
foreach ($need_funcs as $fn) {
    if (!function_exists($fn)) {
        $errors[] = '函数缺失：' . $fn;
    }
}
foreach ($need_files as $rel) {
    if (!is_file(TCPC_OA_DIR . '/' . $rel)) {
        $errors[] = '文件缺失：' . $rel;
    }
}
if (function_exists('tcpc_oa_api_manifest')) {
    $manifest = tcpc_oa_api_manifest();
    $count = isset($manifest['symbols']) && is_array($manifest['symbols']) ? count($manifest['symbols']) : 0;
    if ($count !== 11) {
        $errors[] = 'API 清单符号数=' . $count . '（期望 11）';
    }
    if ((int) ($manifest['version'] ?? 0) !== 1) {
        $errors[] = 'API 清单版本不是 1';
    }
}
if (function_exists('rest_do_request')) {
    $resp = rest_do_request(new WP_REST_Request('GET', '/tcpc-oa/v1/health'));
    if (is_wp_error($resp)) {
        $errors[] = 'REST health 错误：' . $resp->get_error_message();
    }
}
if ($errors) {
    echo "OA_SMOKE_FAIL\n" . implode("\n", $errors) . "\n";
    exit(1);
}
echo 'OA_SMOKE_OK menus=' . count($need_menus) . ' funcs=' . count($need_funcs) . ' files=' . count($need_files) . "\n";
PHP
if ! wp eval-file "$SMOKE_PHP" --user="${ZW_OA_SMOKE_USER:-1}" --path="$WP_PATH" --allow-root; then
  rm -f "$SMOKE_PHP"
  fail "结构冒烟失败：六菜单 / 模块符号 / API 清单 / REST 未全部就绪"
fi
rm -f "$SMOKE_PHP"
check_url "/userra/"

oa_publish_ok
say "发版完成：v$VERSION（备份 $BACKUP_DIR）"
say "回滚命令：bash scripts/plugin-switch-oa.sh --rollback $STAMP"
exit 0
