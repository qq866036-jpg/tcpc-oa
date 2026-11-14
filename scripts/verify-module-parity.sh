#!/usr/bin/env bash
# 模块迁入 parity 校验（唯一判据）—— TASK-2320 P2；OA-2418 R2 扩展为数据化登记 + 文件清单模式。
#
# 迁入纪律：**新仓模块必须与主题仓对应实现逐字节一致**，否则拒绝提交/发布。
# 判据同时防两种事故：
#   ① 迁入时"顺手改两行"（新仓与主题分叉，之后谁也说不清哪份是对的）；
#   ② 主题侧后来又改了而同批没同步（主题悄悄变成第二份实现）。
#
# 两种比对模式（在下方 `registry` 登记表里声明，不写 if-else 长链）：
#   dir  —— 主题侧独占目录 ⇄ 插件侧目录，`diff -r` 整目录比对；
#   list —— **文件清单模式**：主题侧按显式文件名 / glob 展开到**每个文件**，逐个 `cmp` 字节比对。
#           任一侧缺文件都显式报 `missing-theme:` / `missing-plugin:`，**不静默跳过**。
#           为什么需要：`policy` 的实现散在主题 `inc/admin/`（13 个 php）与 `inc/policy-platform/`，
#           `files` 只占 `inc/admin/org/` 8 个中的 2 个 ⇒ 目录对目录无法表达。
#           `policy` 口径已按 OA-2419 R3-2 收窄：主题侧**只**把 3 个管理面副本当「应迁/应删」，
#           内核读侧 3 个文件按 Q-A2「读侧留主题」不进清单（详见 registry 前的 policy 口径段）。
#
# 用法：
#   bash scripts/verify-module-parity.sh receipt
#   bash scripts/verify-module-parity.sh receipt --theme /srv/git/worktrees/dev-tcpc/main
#   bash scripts/verify-module-parity.sh receipt --expect-theme-absent
#   bash scripts/verify-module-parity.sh org --exclude org-permissions.php
#
# 选项：
#   --theme <dir>          主题工作区（默认 $ZW_OA_THEME 或 /srv/git/worktrees/dev-tcpc/main）
#   --plugin <dir>         覆盖插件侧模块目录（/tmp 夹具自测用；生产判定不要传）
#   --exclude <basename>   比对时排除该 basename，可多次；与登记表的「默认 exclude」叠加
#   --expect-theme-absent  迁入完成模式：主题侧只允许剩 shim，不得留实现副本（缺 shim ⇒ 红）
#
# 退出码：0 = 一致；1 = 有差异 / 缺目录 / 缺文件 / 缺 shim；2 = 用法错误。
set -uo pipefail

usage() {
  cat <<'USAGE'
用法：bash scripts/verify-module-parity.sh <module> [选项]
  <module>                receipt | policy | org | files | doc | secretariat
  --theme <dir>           主题工作区（默认 $ZW_OA_THEME 或 /srv/git/worktrees/dev-tcpc/main）
  --plugin <dir>          覆盖插件侧模块目录（夹具自测用）
  --exclude <basename>    排除该 basename（可多次；与模块默认 exclude 叠加）
  --expect-theme-absent   迁入完成模式：主题侧只允许剩 shim（缺 shim ⇒ rc=1，fail-closed）
  -h, --help              本帮助
退出码：0 = 一致；1 = 差异/缺目录/缺文件/缺 shim；2 = 用法错误。
USAGE
}

MODULE="${1:-}"
shift || true

if [[ "$MODULE" == "-h" || "$MODULE" == "--help" ]]; then
  usage
  exit 0
fi

EXPECT_ABSENT=0
ONLY_FILES=()   # OA-2439（R10a）：子批判据 —— 只比较列出的文件（其余 missing 不计失败）
THEME="${ZW_OA_THEME:-/srv/git/worktrees/dev-tcpc/main}"
PLUGIN_OVERRIDE=""
EXTRA_EXCLUDES=()

while [[ $# -gt 0 ]]; do
  case "$1" in
    --theme)
      [[ -n "${2:-}" ]] || { echo "✗ --theme 需要目录参数" >&2; exit 2; }
      THEME="$2"; shift 2 ;;
    --plugin)
      [[ -n "${2:-}" ]] || { echo "✗ --plugin 需要目录参数" >&2; exit 2; }
      PLUGIN_OVERRIDE="$2"; shift 2 ;;
    --exclude)
      [[ -n "${2:-}" ]] || { echo "✗ --exclude 需要 basename 参数" >&2; exit 2; }
      EXTRA_EXCLUDES+=("$2"); shift 2 ;;
    --only)
      [[ -n "${2:-}" ]] || { echo "✗ --only 需要逗号分隔的文件名列表" >&2; exit 2; }
      IFS=',' read -r -a ONLY_FILES <<< "$2"; shift 2 ;;
    --expect-theme-absent) EXPECT_ABSENT=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "未知参数：$1" >&2; exit 2 ;;
  esac
done

if [[ -z "$MODULE" ]]; then
  usage >&2
  exit 2
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# ════════════════════════════════════════════════════════════════════════════
# 模块登记表（唯一出处；与 `includes/load-list.php` 的 `theme_path` 同口径，
# 新增/改动模块必须**两处同步**）。
#
# 行格式（`|` 分隔）：
#   module|<模块>|<dir|list>|<插件侧目录，相对插件根>|<shim 文件名>|<默认 exclude，空格分隔>
#   theme|<模块>|<主题侧匹配项，相对主题根>          # 显式文件名或 glob；可多行
#   require|<模块>|<插件侧必须存在的文件名>          # 仅 --expect-theme-absent 用；可多行
#
# shim = 主题侧**有意差异**：插件接管入口只能存在于主题侧（插件副本照抄会自我 require 递归），
#        因此按 basename 从两侧比对中排除，并反向断言插件副本不含该 shim。
#        未登记 shim 的模块走 --expect-theme-absent ⇒ 直接判红（fail-closed，不放宽）。
#
# policy 口径（OA-2419 R3-2 / Q-A2「读侧留主题」）：
#   主题侧**只登记 3 个管理面副本** `inc/admin/policy-platform-{consent,content-review,document-center}.php`
#   —— 它们才是要删的「第二套实现」，仍逐个 `cmp` 逐字节比对（口径收窄 ≠ 放松比对）；
#   内核读侧 3 个 `inc/policy-platform/{policy-module-map,policy-platform-cron,policy-rule-engine}.php`
#   拍板**留主题** ⇒ 不登记 theme 行（否则 `--expect-theme-absent` 会把内核文件误报成「残留副本」，
#   实测 R3-1：删除主题 3 个 `inc/admin` 副本后仍被点名 3 次 ⇒ 假红）；
#   插件内同名副本改登记为**默认 exclude**：反向扫描（插件有、主题无对应 ⇒ missing-theme）必须放行它们，
#   否则收窄口径的当天就会误判 3 个 missing-theme。shim 仍为 `inc/admin/policy-platform-admin.php`
#   （主题侧有意差异，两侧比对都排除）。
#
# org 口径（Q2 拍板：RBAC 内核 `org-permissions.php` 不迁）：
#   `inc/admin/org/org-*.php` 中 `org-files.php` 属 files 模块 ⇒ 登记为默认 exclude；
#   `file-center.php` 同属 files 模块（不匹配 `org-*.php`，但反向扫描会看到它）⇒ 同样默认 exclude；
#   `org-permissions.php` **默认仍参与比对**（防它被悄悄改成第二份实现，也防"排除"被写成死代码）；
#   标准判定调用带 `--exclude org-permissions.php` ⇒ 口径剩下 org-admin*/org-bootstrap/org-repository。
# ════════════════════════════════════════════════════════════════════════════
registry() {
  cat <<'OA_REGISTRY'
module|receipt|dir|includes/receipt|receipt-bootstrap.php|
theme|receipt|inc/receipt
require|receipt|receipt-helpers.php
require|receipt|receipt-create.php
require|receipt|receipt-ledger.php
require|receipt|receipt-bootstrap.php
require|receipt|receipt-storage.php
module|policy|list|includes/policy|policy-platform-admin.php|policy-module-map.php policy-platform-cron.php policy-rule-engine.php
theme|policy|inc/admin/policy-platform-consent.php
theme|policy|inc/admin/policy-platform-content-review.php
theme|policy|inc/admin/policy-platform-document-center.php
require|policy|policy-module-map.php
require|policy|policy-platform-admin.php
require|policy|policy-platform-consent.php
require|policy|policy-platform-content-review.php
require|policy|policy-platform-cron.php
require|policy|policy-platform-document-center.php
require|policy|policy-rule-engine.php
# OA-2499（补 OA-2498 收口）：读源/版本模型/桥接/内容规则 + 判定引擎/签署/拦截/通知/定时进本仓。
require|policy|policy-platform-data.php
require|policy|legal-text-source.php
require|policy|legal-admin-bridge.php
require|policy|legal-content-rules.php
require|policy|legal-consent.php
require|policy|legal-consent-gate.php
require|policy|legal-consent-notify.php
# OA-2437（R8-1）：org / files 的**主题侧 shim 登记**（用户拍板③）。
# 形态与 W1 的 policy 一致：主题只留一个薄 shim（插件优先 / 插件缺席 fail-closed），
# 其余实现副本删除。shim 只允许**存在且不参与比对**，不降低其它文件的判据。
module|org|list|includes/org|org-bootstrap.php|org-files.php file-center.php org-permissions.php
theme|org|inc/admin/org/org-*.php
# OA-2499 O1（Q2 延续）：`org-permissions.php` 是全站 RBAC 内核，留主题不迁；
# module 行 exclude ⇒ 不算主题实现副本、也不要求插件有副本。插件内出现同名副本即判红。
# OA-2499：R6b 的 org-admin allow 已随删除轮消失，按陈旧豁免规则删除（原先的 allow 行不再保留）。
# OA-2437（R8）插件侧齐全性清单：R8 删主题副本后，插件必须齐备这 5 个文件（org）与 2 个（files）
require|org|org-bootstrap.php
require|org|org-repository.php
require|org|org-admin.php
require|org|org-admin-people.php
require|org|org-admin-roles.php
# OA-2500：组织字典契约消费层（P-8）；主题侧数据契约见 inc/oa/contract-org-data.php。
require|org|org-contract.php
require|files|file-center.php
require|files|org-files.php
module|files|list|includes/files|org-files.php|
theme|files|inc/admin/org/file-center.php
theme|files|inc/admin/org/org-files.php
# OA-2504（D 批）：`doc-appoint-cert.php` 的旧 allow 行已退役——主题侧文件已删除（迁移完成），
# 继续保留会触发「陈旧豁免」判红；该文件现在只在插件侧，属正常迁入结果（不再需要有意差异登记）。
# OA-2504（D 批）：doc / secretariat 的**主题侧 shim 登记**（此前为空 ⇒ `--expect-theme-absent` 判红）。
# 主题侧各只留一个薄 shim：插件在时不声明符号；插件缺/未启用 ⇒ 零菜单 + 管理员提示（fail-closed）。
module|doc|list|includes/doc|doc-bootstrap.php|
theme|doc|inc/admin/doc/*.php
require|doc|doc-admin-dict.php
require|doc|doc-admin-flow.php
require|doc|doc-admin-list.php
require|doc|doc-admin-numbers.php
require|doc|doc-admin-ui.php
require|doc|doc-appoint-cert.php
require|doc|doc-appoint.php
require|doc|doc-auto-flow.php
require|doc|doc-body-model.php
require|doc|doc-bootstrap.php
require|doc|doc-composer.php
require|doc|doc-copy-number-repository.php
require|doc|doc-download.php
require|doc|doc-export-docx.php
require|doc|doc-helpers.php
require|doc|doc-import-docx.php
require|doc|doc-import.php
require|doc|doc-layout-designer.php
require|doc|doc-layout-gb9704.php
require|doc|doc-legal.php
require|doc|doc-letterhead.php
require|doc|doc-number-repository.php
require|doc|doc-pdf.php
require|doc|doc-public-guard.php
require|doc|doc-render-html.php
require|doc|doc-repository.php
require|doc|doc-seal.php
module|secretariat|list|includes/secretariat|secretariat-bootstrap.php|
theme|secretariat|inc/secretariat/*.php
require|secretariat|secretariat-admin.php
require|secretariat|secretariat-bootstrap.php
require|secretariat|secretariat-gates.php
require|secretariat|secretariat-helpers.php
require|secretariat|secretariat-missions.php
require|secretariat|secretariat-qa.php
require|secretariat|secretariat-repository.php
OA_REGISTRY
}

declare -A MOD_MODE=() MOD_PLUGIN=() MOD_SHIM=() MOD_EXCLUDES=() MOD_THEME=() MOD_REQUIRE=() MOD_ALLOW=()
while IFS='|' read -r kind a b c d e; do
  [[ -z "$kind" || "$kind" == \#* ]] && continue
  case "$kind" in
    module)  MOD_MODE["$a"]="$b"; MOD_PLUGIN["$a"]="$c"; MOD_SHIM["$a"]="$d"; MOD_EXCLUDES["$a"]="${e:-}" ;;
    theme)   MOD_THEME["$a"]+="${b}"$'\n' ;;
    require) MOD_REQUIRE["$a"]+="${b}"$'\n' ;;
    # OA-2435（R7）：按文件、带理由的「有意差异」豁免（**不是**整模块 exclude）。
    # 行格式：allow|<模块>|<主题侧 basename>|<理由，≥8 个非空白字符>
    allow)   MOD_ALLOW["$a"]+="${b}|${c}"$'\n' ;;
    *) echo "✗ 登记表格式未知行：$kind|$a|$b" >&2; exit 2 ;;
  esac
done < <(registry)

if [[ -z "${MOD_MODE[$MODULE]+x}" ]]; then
  echo "✗ 未登记模块：$MODULE" >&2
  echo "  已登记：$(printf '%s\n' "${!MOD_MODE[@]}" | sort | tr '\n' ' ')" >&2
  echo "  新增模块须在本脚本的 registry 与 includes/load-list.php 两处同步" >&2
  exit 2
fi

MODE="${MOD_MODE[$MODULE]}"
SHIM="${MOD_SHIM[$MODULE]:-}"
PLUGIN_DIR="$ROOT/${MOD_PLUGIN[$MODULE]}"
[[ -n "$PLUGIN_OVERRIDE" ]] && PLUGIN_DIR="$PLUGIN_OVERRIDE"

PATTERNS=()
while IFS= read -r p; do
  [[ -n "$p" ]] && PATTERNS+=("$p")
done <<< "${MOD_THEME[$MODULE]:-}"

# basename 是否被排除（模块默认 exclude + 命令行 --exclude 叠加）
excluded() {
  local base="$1" e
  for e in ${MOD_EXCLUDES[$MODULE]:-} "${EXTRA_EXCLUDES[@]:-}"; do
    [[ -n "$e" && "$base" == "$e" ]] && return 0
  done
  return 1
}

# 主题侧匹配项的可选父目录（按 shim 定位用；去重）
theme_dirs() {
  local pat dir
  local -A seen=()
  while IFS= read -r pat; do
    [[ -z "$pat" ]] && continue
    if [[ "$pat" == *[\*\?\[]* ]]; then
      dir="$(dirname "$pat")"
    elif [[ -d "$THEME/$pat" ]]; then
      dir="$pat"
    else
      dir="$(dirname "$pat")"
    fi
    [[ -n "${seen[$dir]:-}" ]] && continue
    seen["$dir"]=1
    printf '%s\n' "$dir"
  done <<< "${MOD_THEME[$MODULE]:-}"
}

# 展开主题侧匹配项 → 绝对路径（逐行输出）。
# 缺文件/零匹配时向 stderr 报 `missing-theme:` 并返回 1（--allow-missing 时静默返回 0）。
theme_matches() {
  local allow_missing=0
  [[ "${1:-}" == "--allow-missing" ]] && allow_missing=1
  local rc=0 pat f
  local -a hits=()
  local -A seen=()
  while IFS= read -r pat; do
    [[ -z "$pat" ]] && continue
    hits=()
    if [[ "$pat" == *[\*\?\[]* ]]; then
      while IFS= read -r f; do [[ -n "$f" ]] && hits+=("$f"); done < <(compgen -G "$THEME/$pat" 2>/dev/null || true)
      if [[ ${#hits[@]} -eq 0 && "$allow_missing" -eq 0 ]]; then
        echo "✗ missing-theme: $pat（主题侧无匹配文件）" >&2
        rc=1
        continue
      fi
    else
      if [[ ! -f "$THEME/$pat" ]]; then
        if [[ "$allow_missing" -eq 0 ]]; then
          echo "✗ missing-theme: $pat（主题侧文件不存在）" >&2
          rc=1
        fi
        continue
      fi
      hits=("$THEME/$pat")
    fi
    for f in "${hits[@]}"; do
      [[ -n "$f" && -z "${seen[$f]:-}" ]] || continue
      seen["$f"]=1
      printf '%s\n' "$f"
    done
  done <<< "${MOD_THEME[$MODULE]:-}"
  return "$rc"
}

echo "== 模块 parity：$MODULE（$MODE 模式）"
echo "   插件侧：$PLUGIN_DIR"
if [[ "$MODE" == "dir" ]]; then
  echo "   主题侧：$THEME/${PATTERNS[0]:-}"
else
  while IFS= read -r p; do
    [[ -n "$p" ]] && echo "   主题侧匹配：$p"
  done <<< "${MOD_THEME[$MODULE]:-}"
fi
[[ -n "$SHIM" ]] && echo "   · 主题侧 shim：$SHIM（有意差异；插件副本不得含 shim）"


# ── dir 模式：整目录 diff -r（收文这类独占目录） ───────────────────────────────
verify_dir() {
  local theme_dir="$THEME/${PATTERNS[0]:-}" out
  if [[ ! -d "$theme_dir" ]]; then
    echo "✗ 主题目录不存在：$theme_dir（主题工作区路径对吗？--theme）" >&2
    return 1
  fi
  local -a args=(-r --brief)
  local e
  for e in ${MOD_EXCLUDES[$MODULE]:-} "${EXTRA_EXCLUDES[@]:-}"; do
    [[ -n "$e" ]] && args+=(--exclude="$e")
  done
  [[ -n "$SHIM" ]] && args+=(--exclude="$SHIM")
  if out="$(diff "${args[@]}" "$theme_dir" "$PLUGIN_DIR" 2>&1)"; then
    local n
    n="$(find "$PLUGIN_DIR" -type f | wc -l)"
    echo "✓ DIFF=0（$n 个文件逐字节一致$( [[ -n "$SHIM" ]] && echo "；已排除 1 个主题 shim" )）"
    return 0
  fi
  printf '%s\n' "$out" | sed 's/^/  /' >&2
  echo "✗ 存在差异（迁入必须 diff=0；差异要么补齐，要么明确记录为「迁入时的有意改动」并走 CHG）" >&2
  return 1
}

# ── list 模式：主题侧文件清单 ⇄ 插件侧同名文件，逐个 cmp ────────────────────────
verify_list() {
  local rc=0 total=0 diff_files=0 miss_files=0
  local matches="" f base pf
  local -a theme_files=()
  local -A base_seen=()

  # OA-2435（R7）：「有意差异」按文件豁免 —— 只对**登记过 allow + 有理由**的文件放行，
  # 理由必须是 ≥8 个非空白字符；空/过短 ⇒ fail-closed（禁止裸豁免）。
  local -A allow_map=() allow_used=()
  local ap areason
  while IFS='|' read -r ap areason; do
    [[ -z "$ap" ]] && continue
    if [[ -z "${areason//[[:space:]]/}" || ${#areason} -lt 8 ]]; then
      echo "  ✗ allow 豁免缺理由或理由过短（需 ≥8 个非空白字符）：$ap" >&2
      rc=1; continue
    fi
    allow_map["$ap"]="$areason"
  done <<< "${MOD_ALLOW[$MODULE]:-}"

  # OA-2439（R10a）：--only 子批判据 —— 只比较列出的文件；列出的文件若两侧都不存在 ⇒ fail-closed。
  if [[ ${#ONLY_FILES[@]} -gt 0 ]]; then
    local only f_only hit
    for only in "${ONLY_FILES[@]}"; do
      hit=0
      [[ -f "$THEME/$only" || -f "$PLUGIN_DIR/$only" ]] && hit=1
      # 也允许只给 basename（主题侧路径未知时）
      if [[ "$hit" -eq 0 ]]; then
        while IFS= read -r f_only; do
          [[ -n "$f_only" && "${f_only##*/}" == "$only" ]] && hit=1
        done <<< "$(theme_matches 2>/dev/null || true)"
      fi
      if [[ "$hit" -eq 0 ]]; then
        echo "  ✗ --only 指定的文件在主题侧与插件侧都不存在：$only ⇒ fail-closed（不把「找不到」当通过）" >&2
        return 1
      fi
    done
  fi

  if ! matches="$(theme_matches)"; then
    rc=1
  fi
  while IFS= read -r f; do
    [[ -z "$f" ]] && continue
    base="${f##*/}"
    [[ "$base" == "$SHIM" ]] && continue
    excluded "$base" && continue
    theme_files+=("$f")
  done <<< "$matches"
  total=${#theme_files[@]}
  if [[ "$total" -eq 0 ]]; then
    echo "✗ 主题侧可比对文件为 0（登记表 theme 行缺失，或全部被 exclude 掉）⇒ fail-closed" >&2
    return 1
  fi

  for f in "${theme_files[@]}"; do
    base="${f##*/}"
    if [[ ${#ONLY_FILES[@]} -gt 0 ]]; then
      local keep=0 oo
      for oo in "${ONLY_FILES[@]}"; do [[ "$oo" == "$base" || "$oo" == "${f#"$THEME"/}" ]] && keep=1; done
      [[ "$keep" -eq 0 ]] && continue
    fi
    base_seen["$base"]=1
    pf="$PLUGIN_DIR/$base"
    if [[ ! -f "$pf" ]]; then
      echo "  ✗ missing-plugin: $base（主题侧有 $f，插件侧缺 $pf）" >&2
      miss_files=$((miss_files + 1)); rc=1
      continue
    fi
    if cmp -s "$f" "$pf"; then
      echo "  ✓ cmp $base"
    else
      if [[ -n "${allow_map[$base]:-}" ]]; then
        echo "  · ALLOWED-DIFF $base（已登记有意差异：${allow_map[$base]}）"
        allow_used["$base"]=1
      else
        echo "  ✗ DIFF: $base（内容不一致：$f ⇄ $pf）" >&2
        diff_files=$((diff_files + 1)); rc=1
      fi
    fi
  done

  # 反向：插件侧多出来的 php（主题侧无对应）同样不许静默存在（否则删码后残留副本永远查不出）
  for pf in "$PLUGIN_DIR"/*.php; do
    [[ -e "$pf" ]] || continue
    base="${pf##*/}"
    [[ "$base" == "$SHIM" ]] && continue
    excluded "$base" && continue
    if [[ ${#ONLY_FILES[@]} -gt 0 ]]; then
      local keep2=0 oo2
      for oo2 in "${ONLY_FILES[@]}"; do [[ "$oo2" == "$base" ]] && keep2=1; done
      [[ "$keep2" -eq 0 ]] && continue
    fi
    [[ -n "${base_seen[$base]:-}" ]] && continue
    echo "  ✗ missing-theme: $base（插件侧有 $pf，主题侧无对应文件）" >&2
    miss_files=$((miss_files + 1)); rc=1
  done

  # 陈旧豁免判红：登记了 allow 但该文件实测**无差异** ⇒ 判红（防豁免沉淀成黑洞，口径同 K2 陈旧白名单）
  for ap in "${!allow_map[@]}"; do
    if [[ -z "${allow_used[$ap]:-}" ]]; then
      echo "  ✗ 陈旧豁免：$ap 已登记 allow 但本次实测无差异 ⇒ 判红（请删除该 allow 行）" >&2
      rc=1
    fi
  done

  if [[ "$rc" -eq 0 ]]; then
    # OA-2435 措辞修正：豁免命中时不能说「逐个 cmp 字节一致」（那 2 个文件并不一致）。
    if [[ ${#ONLY_FILES[@]} -gt 0 ]]; then
      echo "✓ DIFF=0（list 模式：--only 指定 ${#ONLY_FILES[@]} 个文件逐一比对，其余未迁文件不计入本次判定）"
      return 0
    fi
    echo "✓ DIFF=0（list 模式：$total 个文件逐一比对$( [[ ${#allow_used[@]} -gt 0 ]] && echo "，其中 ${#allow_used[@]} 个为已登记有意差异（见 ALLOWED-DIFF 行）" || echo "，逐字节一致" )$( [[ -n "$SHIM" ]] && echo "；已排除 shim $SHIM" )）"
    return 0
  fi
  echo "✗ 存在差异（list 模式：$total 个声明文件里 $diff_files 个内容不一致 / $miss_files 个缺失；不做「只绿不红」豁免）" >&2
  return 1
}

# ── --expect-theme-absent：主题侧只允许剩 shim（缺 shim ⇒ 红，不放宽） ───────────
verify_expect_absent() {
  local rc=0 f base shim_path="" leftover_names=()
  local -a reqs=() leftover=()
  local -A seen=()

  # ① 模块必须登记 shim（未登记 ⇒ 无法判定"只允许剩 shim"，fail-closed）
  if [[ -z "$SHIM" ]]; then
    echo "✗ 模块 $MODULE 未登记 shim ⇒ --expect-theme-absent 不可判定" >&2
    echo "  fail-closed：不放宽成「主题侧没文件就算通过」；迁入的 shim 步骤完成后再登记" >&2
    return 1
  fi

  # ② 插件侧目录必须存在（否则"齐全性"无从判起）
  if [[ ! -d "$PLUGIN_DIR" ]]; then
    echo "✗ 插件侧模块目录不存在：$PLUGIN_DIR" >&2
    echo "  该模块尚未迁入本仓 ⇒ 预期红，不是 POST-MIGRATION OK。" >&2
    return 1
  fi

  # ③ 插件侧文件必须齐全
  while IFS= read -r f; do [[ -n "$f" ]] && reqs+=("$f"); done <<< "${MOD_REQUIRE[$MODULE]:-}"
  if [[ ${#reqs[@]} -eq 0 ]]; then
    echo "✗ 模块 $MODULE 未登记 require 清单 ⇒ 无法判定插件侧齐全性（fail-closed）" >&2
    return 1
  fi
  for f in "${reqs[@]}"; do
    if [[ ! -f "$PLUGIN_DIR/$f" ]]; then
      echo "✗ 插件侧缺文件：$PLUGIN_DIR/$f" >&2
      rc=1
    fi
  done

  # ④ 主题侧 shim 必须在（shim 同时是 fail-closed 提示的唯一落点，不能删）
  while IFS= read -r d; do
    [[ -z "$d" ]] && continue
    if [[ -f "$THEME/$d/$SHIM" ]]; then shim_path="$THEME/$d/$SHIM"; break; fi
  done < <(theme_dirs)
  if [[ -z "$shim_path" ]]; then
    echo "✗ 主题侧 shim 缺失：$SHIM（候选目录：$(theme_dirs | tr '\n' ' ')；fail-closed 提示不能删）" >&2
    rc=1
  fi

  # ⑤ 主题侧除 shim 外不得留实现副本
  while IFS= read -r f; do
    [[ -z "$f" ]] && continue
    base="${f##*/}"
    [[ "$base" == "$SHIM" ]] && continue
    excluded "$base" && continue
    [[ -n "${seen[$f]:-}" ]] && continue
    seen["$f"]=1
    leftover+=("$f")
  done < <(theme_matches --allow-missing)
  for f in "${leftover[@]:-}"; do
    [[ -n "$f" ]] && leftover_names+=("${f##*/}")
  done
  if [[ ${#leftover_names[@]} -gt 0 ]]; then
    echo "✗ 主题侧仍有模块实现副本：${leftover_names[*]}（迁入完成后必须删除，否则重新变成双份实现）" >&2
    rc=1
  fi

  if [[ "$rc" -eq 0 ]]; then
    local n
    n="$(find "$PLUGIN_DIR" -maxdepth 1 -type f -name '*.php' | wc -l)"
    echo "✓ POST-MIGRATION OK：插件侧 $n 个文件齐全；主题侧仅剩 shim（$SHIM），无实现副本"
    return 0
  fi
  return 1
}

if [[ "$EXPECT_ABSENT" -eq 1 ]]; then
  verify_expect_absent
  exit $?
fi

if [[ ! -d "$PLUGIN_DIR" ]]; then
  echo "✗ 插件侧模块目录不存在：$PLUGIN_DIR" >&2
  echo "  该模块尚未迁入本仓（或本轮登记与 includes/load-list.php 不一致）⇒ 预期红，不是 DIFF=0。" >&2
  exit 1
fi

if [[ -n "$SHIM" && -f "$PLUGIN_DIR/$SHIM" ]] && grep -q "TCPC_OA_DIR" "$PLUGIN_DIR/$SHIM" 2>/dev/null; then
  echo "✗ 插件副本含主题 shim（会自我 require 递归）：$PLUGIN_DIR/$SHIM" >&2
  exit 1
fi

case "$MODE" in
  dir)  verify_dir; exit $? ;;
  list) verify_list; exit $? ;;
  *) echo "✗ 登记表模式未知：$MODE" >&2; exit 2 ;;
esac
