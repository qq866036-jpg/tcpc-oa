#!/usr/bin/env bash
# 插件装载面 × 主题读侧「同名符号」碰撞检查（唯一判据）—— OA-2438 事故固化。
#
# ## 为什么需要（真实事故，2026-10-29）
#
# 发 `tcpc-oa 0.1.1` 时，插件 `policy` 模块按 R3-2 载入了 **7 个**文件，其中
# `policy-module-map.php` / `policy-platform-cron.php` / `policy-rule-engine.php` 三个是**读侧**，
# 主题 `inc/policy-platform/` 里有一份**同名同函数**的实现（Q-A2 拍板「读侧留主题」）。
# 插件先声明、主题读侧再声明 ⇒ 生产直接
# `Cannot redeclare zw_policy_platform_cron_schedules()`（整站 500，已回滚）。
#
# 这类问题**源码级可判**：把「插件会装载的符号」× 「主题读侧会声明的符号」求交集即可。
#
# ## 判据
#
#   · 插件 `includes/**/*.php` 里 `function NAME(` 的集合 A
#   · 主题 `inc/policy-platform/**/*.php`（读侧，必须留主题）里 `function NAME(` 的集合 B
#   · **A ∩ B ≠ ∅ ⇒ rc=1**（fail-closed）：这些符号会在生产撞 `Cannot redeclare`
#
# 另有 `--warn-admin`（默认开）：主题 `inc/admin/**` 里同名符号**只告警不判红**——那是
# R8 要删的管理面副本，迁移窗口内本来就会重叠。
#
# ## 用法
#
#   bash scripts/check-kernel-symbol-collisions.sh                    # 默认 --theme /srv/git/worktrees/dev-tcpc/main
#   bash scripts/check-kernel-symbol-collisions.sh --theme <主题工作区>
#   bash scripts/check-kernel-symbol-collisions.sh --no-warn-admin
#
# 退出码：0 = 无碰撞；1 = 有碰撞（读侧）；2 = 用法/路径错误。
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
THEME="${ZW_OA_THEME:-/srv/git/worktrees/dev-tcpc/main}"
WARN_ADMIN=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --theme) THEME="${2:-}"; shift 2 ;;
    --no-warn-admin) WARN_ADMIN=0; shift ;;
    -h|--help) sed -n '2,28p' "$0"; exit 0 ;;
    *) echo "未知参数：$1" >&2; exit 2 ;;
  esac
done

[[ -d "$ROOT/includes" ]] || { echo "✗ 插件目录不存在：$ROOT/includes" >&2; exit 2; }
READ_SIDE="$THEME/inc/policy-platform"
[[ -d "$READ_SIDE" ]] || { echo "✗ 主题读侧目录不存在：$READ_SIDE（--theme 对吗？）" >&2; exit 2; }

# 提取 function 名（去重排序）：跳注释用 grep 粗筛已足够——同名函数声明必然出现在 function 行。
symbols_of() {
  grep -rhoE '^[[:space:]]*function[[:space:]]+[A-Za-z_][A-Za-z0-9_]*' "$@" 2>/dev/null \
    | sed -E 's/^[[:space:]]*function[[:space:]]+//' | sort -u
}

# 只扫**会被装载**的插件文件：`includes/policy/` 下 3 个读侧副本仍留在仓内（parity DIFF=0 需要），
# 但**不在装载面**（见 policy-platform-admin.php 的装载段）⇒ 不能算碰撞，否则误报。
PLUGIN_LOADED_FILES=()
while IFS= read -r f; do
  case "$(basename "$f")" in
    policy-module-map.php|policy-platform-cron.php|policy-rule-engine.php) continue ;;
  esac
  PLUGIN_LOADED_FILES+=("$f")
done < <(find "$ROOT/includes" -type f -name '*.php' | sort)

# 只看项目命名惯例的 `zw_` 前缀 PHP 函数（排除内联 <script> 里的 JS 函数造成的假阳）。
symbols_of() {
  grep -rhoE '^[[:space:]]*function[[:space:]]+zw_[a-z0-9_]+' "$@" 2>/dev/null \
    | sed -E 's/^[[:space:]]*function[[:space:]]+//' | sort -u
}

PLUGIN_SYMS="$(mktemp)"; READ_SYMS="$(mktemp)"; ADMIN_SYMS="$(mktemp)"
trap 'rm -f "$PLUGIN_SYMS" "$READ_SYMS" "$ADMIN_SYMS"' EXIT

symbols_of "${PLUGIN_LOADED_FILES[@]}" > "$PLUGIN_SYMS"
symbols_of "$READ_SIDE" > "$READ_SYMS"
[[ -d "$THEME/inc/admin" ]] && symbols_of "$THEME/inc/admin" > "$ADMIN_SYMS"

COLLIDE_READ="$(comm -12 "$PLUGIN_SYMS" "$READ_SYMS")"
COLLIDE_ADMIN="$(comm -12 "$PLUGIN_SYMS" "$ADMIN_SYMS")"

# ── allow 清单（`<符号>|<理由≥8 非空白>`）：只放行「**主题侧已带 function_exists 守卫**」的共享函数。
# 语义：插件先装载（WP 先 plugins 后 theme）⇒ 内核侧守卫让位 ⇒ 不 fatal。理由缺失/过短 ⇒ fail-closed。
ALLOW_FILE="$ROOT/scripts/kernel-symbol-allow.txt"
declare -A ALLOW=()
ALLOW_BAD=0
if [[ -f "$ALLOW_FILE" ]]; then
  while IFS='|' read -r sym why; do
    [[ -z "$sym" || "$sym" == \#* ]] && continue
    if [[ -z "${why//[[:space:]]/}" || ${#why} -lt 8 ]]; then
      echo "✗ allow 缺理由或过短（≥8 非空白）：$sym" >&2; ALLOW_BAD=1; continue
    fi
    ALLOW["$sym"]="$why"
  done < "$ALLOW_FILE"
fi

READ_UNALLOWED=""
ALLOW_USED=""
while IFS= read -r sym; do
  [[ -z "$sym" ]] && continue
  if [[ -n "${ALLOW[$sym]:-}" ]]; then
    ALLOW_USED+="$sym"$'\n'
  else
    READ_UNALLOWED+="$sym"$'\n'
  fi
done <<< "$COLLIDE_READ"

# 陈旧豁免：登记了但本次并无碰撞 ⇒ 判红（防 allow 沉淀成黑洞，口径同 parity 的陈旧豁免）
STALE_ALLOW=""
for sym in "${!ALLOW[@]}"; do
  grep -qx "$sym" <<< "$COLLIDE_READ" || STALE_ALLOW+="$sym"$'\n'
done

echo "== 插件装载面 × 主题读侧 同名符号检查"
echo "   插件符号：$(wc -l < "$PLUGIN_SYMS") 个（$ROOT/includes）"
echo "   读侧符号：$(wc -l < "$READ_SYMS") 个（$READ_SIDE）"

rc=0
rc="$ALLOW_BAD"
if [[ -n "$READ_UNALLOWED" ]]; then
  echo "✗ 与主题读侧同名且**未登记 allow**（生产会 Cannot redeclare，fail-closed）：" >&2
  printf '%s\n' "$READ_UNALLOWED" | sed 's/^/    /' >&2
  echo "  处置：这些符号归主题读侧（Q-A2），插件不得声明；把对应文件从插件装载面移除。" >&2
  rc=1
fi

if [[ -n "$STALE_ALLOW" ]]; then
  echo "✗ 陈旧豁免（allow 登记了但本次并无碰撞 ⇒ 判红，请删除）：" >&2
  printf '%s\n' "$STALE_ALLOW" | sed 's/^/    /' >&2
  rc=1
fi

if [[ -n "$ALLOW_USED" ]]; then
  echo "· 已登记的内核共享符号（主题侧带 function_exists 守卫，插件先装载 ⇒ 内核让位）："
  printf '%s\n' "$ALLOW_USED" | sed 's/^/    /'
fi

if [[ "$WARN_ADMIN" == "1" && -n "$COLLIDE_ADMIN" ]]; then
  echo "⚠ 与主题 inc/admin 同名（$(printf '%s\n' "$COLLIDE_ADMIN" | wc -l) 个）—— 迁移窗口内预期，R8 删除主题副本后应归零："
  printf '%s\n' "$COLLIDE_ADMIN" | head -8 | sed 's/^/    /'
fi

if [[ "$rc" -eq 0 ]]; then
  echo "✓ 无致命读侧符号碰撞（插件可安全装载）"
fi
exit "$rc"
