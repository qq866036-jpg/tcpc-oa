#!/usr/bin/env bash
# 插件发版互斥 —— **唯一出处**（AGENT-0362；P1 实现见 AGENT-0356，立单 AGENT-0355）。
# 唯一出处：scripts/lib/plugin-release-lock.sh（各仓副本必须含本行并逐字一致，判据 tests/test-release-lock-parity.sh）
#
# 治什么：同一插件被两个会话同时发版 ⇒ 后发覆盖先发。实证：2026-11-09 03:35 发 workbench 1.1.0，
# 03:38 另一会话从更早分支发 1.0.1 直接覆盖生产目录（备份 `pre-switch-20261109-033855` 内为 1.1.0）。
#
# 三件事：
#   ① **单实例**：`flock -n` 锁一个**仓外**文件（默认 `/root/tcpc-release-locks/<slug>.lock`，目录 0700 /
#      文件 0600）。第二个会话 fail-closed，并打印持有者（task / pid / host / 起始时间 / 目标版本）。
#   ② **版本占用（禁止降级）**：记录「最近一次成功发布的版本」（`<slug>.released`）。目标版本低于它即拒绝
#      —— 这一条单独就能拦住上面那次覆盖。无记录时以生产 `wp plugin get <slug> --field=version` 为基线。
#   ③ **进程退出自愈**：`flock` 随进程退出释放（`kill -9` 亦然），不需要 TTL 轮询；持有者 JSON 只供人读。
#
# 用法（各仓 `scripts/plugin-switch*.sh` 里）：
#   . <本文件>                                     # 各仓 `scripts/lib/release-lock.sh` 是**与本文件逐字一致**的副本
#   zwrel_acquire "$SLUG" "$VER" "$TASK_ID" || exit 3
#   trap 'zwrel_unlock "$SLUG"' EXIT INT TERM
#   ... 导出 / 备份 / 交换 / 回读 ...
#   zwrel_release "$SLUG" "$VER" "$TASK_ID"        # 成功：写版本记录 + 解锁
#
# rc 语义：0 取得 / 2 环境错误 / 3 锁被占（并发）/ 4 版本降级被拒。
#
# 环境变量：
#   ZW_RELEASE_LOCK_DIR           锁目录（默认 `/root/tcpc-release-locks`）
#   ZW_RELEASE_LOCK=0             跳过互斥（打印告警；须在台账写明理由）
#   ZW_RELEASE_ALLOW_DOWNGRADE=1  放开版本降级（打印告警；须写明理由）
#   ZW_WP_PATH / ZW_WP_CLI        读生产版本用（默认生产站路径 / `wp`）
#
# 唯一性判据：`tests/test-release-lock-parity.sh`（各仓副本必须与本文件逐字一致）；
#            改本文件后必须同步各仓副本，否则该测试判红。

zwrel_dir() { printf '%s' "${ZW_RELEASE_LOCK_DIR:-/root/tcpc-release-locks}"; }

# 初始化目录与两个文件路径（幂等）。成功后设置 ZWREL_DIR / ZWREL_LOCK / ZWREL_VER。
zwrel_init() {
  local slug="$1" d
  d="$(zwrel_dir)"
  if ! mkdir -p "$d" 2>/dev/null; then
    echo "✗ 发版互斥：无法创建锁目录 $d" >&2
    return 2
  fi
  chmod 700 "$d" 2>/dev/null || true
  ZWREL_DIR="$d"
  ZWREL_LOCK="$d/$slug.lock"
  ZWREL_VER="$d/$slug.released"
  return 0
}

# a < b（点分版本，`sort -V` 语义）
_zwrel_ver_lt() {
  local a="$1" b="$2" first
  [ "$a" = "$b" ] && return 1
  first="$(printf '%s\n%s\n' "$a" "$b" | sort -V | head -n1)"
  [ "$first" = "$a" ]
}

_zwrel_holder() {
  if [ -s "${ZWREL_LOCK:-}" ]; then
    tr -d '\n' < "$ZWREL_LOCK" | tr -s ' '
  else
    printf '（无持有者信息）'
  fi
}

# 生产在跑版本（首次运行时的基线；拿不到就返回空，守卫降级为「无基线」）。
_zwrel_deployed_ver() {
  local cli="${ZW_WP_CLI:-wp}" slug="$1"
  command -v "$cli" >/dev/null 2>&1 || return 0
  "$cli" plugin get "$slug" --path="${ZW_WP_PATH:-/www/wwwroot/tcpc.org.cn}" --allow-root --field=version 2>/dev/null || true
}

# 基线：最近成功发布记录优先；没有则用生产在跑版本。
_zwrel_baseline() {
  local slug="$1" v=""
  if [ -f "${ZWREL_VER:-}" ]; then
    v="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1])).get("version",""))' "$ZWREL_VER" 2>/dev/null || true)"
  fi
  [ -n "$v" ] || v="$(_zwrel_deployed_ver "$slug")"
  printf '%s' "$v"
}

# 取得发版锁 + 版本守卫。成功返回 0 并置 ZWREL_HELD=1。
zwrel_acquire() {
  local slug="$1" ver="$2" task="${3:-未记}" base
  zwrel_init "$slug" || return 2

  # `>>` 而不是 `>`：**不能用 O_TRUNC 打开**，否则第二个会话在拿不到锁之前就把持有者信息清空了。
  exec 9>>"$ZWREL_LOCK" || { echo "✗ 发版互斥：无法打开锁文件 $ZWREL_LOCK" >&2; return 2; }

  if ! flock -n 9; then
    echo "✗ 发版互斥：$slug 正被另一个会话发布" >&2
    echo "   持有者：$(_zwrel_holder)" >&2
    echo "   处置：等其结束（进程退出即释放锁）；确需跳过用 ZW_RELEASE_LOCK=0（须在台账写明理由）。" >&2
    { exec 9>&-; } 2>/dev/null || true
    return 3
  fi

  chmod 600 "$ZWREL_LOCK" 2>/dev/null || true
  : > "$ZWREL_LOCK" 2>/dev/null || true      # 已持锁，可以安全清空后写持有者信息
  {
    printf '{\n'
    printf '  "slug": "%s",\n' "$slug"
    printf '  "version": "%s",\n' "$ver"
    printf '  "task": "%s",\n' "$task"
    printf '  "pid": %s,\n' "$$"
    printf '  "host": "%s",\n' "$(hostname 2>/dev/null || echo -)"
    printf '  "started_at": "%s",\n' "$(date '+%F %T %z')"
    printf '  "cwd": "%s"\n' "$PWD"
    printf '}\n'
  } >&9 2>/dev/null || true

  if [ "${ZW_RELEASE_ALLOW_DOWNGRADE:-0}" = "1" ]; then
    echo "   ⚠ 已放开版本降级（ZW_RELEASE_ALLOW_DOWNGRADE=1）" >&2
  else
    base="$(_zwrel_baseline "$slug")"
    if [ -n "$base" ] && _zwrel_ver_lt "$ver" "$base"; then
      echo "✗ 版本降级被拒：目标 $ver < 最近发布/在跑 $base（防「后发覆盖先发」）" >&2
      echo "   处置：把版本升到 ≥ $base；确需回退旧构建用 ZW_RELEASE_ALLOW_DOWNGRADE=1（须在台账写明理由）。" >&2
      { exec 9>&-; } 2>/dev/null || true
      return 4
    fi
    [ -n "$base" ] && echo "   版本守卫：$ver ≥ $base（通过）"
  fi

  ZWREL_HELD=1
  return 0
}

# 只解锁（失败路径 / trap 用），不写版本记录。
zwrel_unlock() {
  [ "${ZWREL_HELD:-0}" = "1" ] || return 0
  flock -u 9 2>/dev/null || true
  { exec 9>&-; } 2>/dev/null || true
  ZWREL_HELD=0
  return 0
}

# 成功收尾：**先写版本记录再解锁**（供后续降级守卫使用）。
zwrel_release() {
  local slug="$1" ver="$2" task="${3:-未记}"
  [ "${ZWREL_HELD:-0}" = "1" ] || return 0
  [ -n "${ZWREL_VER:-}" ] || zwrel_init "$slug" || true
  if [ -n "${ZWREL_VER:-}" ]; then
    printf '{"slug":"%s","version":"%s","task":"%s","released_at":"%s","pid":%s}\n' \
      "$slug" "$ver" "$task" "$(date '+%F %T %z')" "$$" > "$ZWREL_VER" 2>/dev/null || true
    chmod 600 "$ZWREL_VER" 2>/dev/null || true
  fi
  zwrel_unlock "$slug"
  return 0
}
