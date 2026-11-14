#!/usr/bin/env bash
# tcpc-oa 健康检查与告警（OA-2508）—— 只读巡检，覆盖三条告警面：
#   ① 插件缺失/不可用（PLUGIN_MISSING / MENU_MISSING / SYMBOL_MISSING / REST_DOWN）
#   ② 定时任务未注册（CRON_MISSING）
#   ③ 发布漂移（VERSION_DRIFT / RELEASE_DRIFT / BHZC_CHANGED / PUBLIC_URL_DOWN）
#
# 用法：
#   bash scripts/health-oa.sh                 # 人读；全绿 rc=0，任一红 rc=1
#   bash scripts/health-oa.sh --json          # 机器读（每行一个 JSON 对象）
#   bash scripts/health-oa.sh --quiet         # 只输出失败项与告警
#   bash scripts/health-oa.sh --no-alert      # 不触发外部告警命令（仅打印 ALERT 行）
#   bash scripts/health-oa.sh --self-test     # 夹具自测（不碰生产）
#
# 告警通道：设置 `ZW_OA_ALERT_CMD`（例如 `mail -s '[OA] 告警' ops@example.com` 或 webhook 脚本），
#   失败时把告警正文从 stdin 传入；未设置则只打印 `ALERT|<TYPE>|<detail>` 到 stderr。
#
# 环境变量（均可覆盖，便于 CI/预发/测试）：
#   ZW_OA_HEALTH_WP_PATH        生产 WordPress 根（默认 /www/wwwroot/tcpc.org.cn）
#   ZW_OA_HEALTH_BASE           站点 base URL（默认 https://www.tcpc.org.cn）
#   ZW_OA_HEALTH_REPO           插件仓根（默认脚本上级目录）
#   ZW_OA_HEALTH_BHZC_BASELINE  /bhzc 正文哈希基线文件（默认 /root/tcpc-release-locks/oa-bhzc.sha256）
#   ZW_OA_HEALTH_RELEASE_RECORD 发版记录 JSON（默认 /root/tcpc-release-locks/tcpc-oa.released）
#   ZW_OA_HEALTH_FAKE=1         夹具模式（所有检测项走 FAKE_* 环境变量，不发外部命令）
#
# 退出码：0 全绿；1 有失败；2 用法/环境错误。
set -uo pipefail

ROOT="${ZW_OA_HEALTH_REPO:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
WP_PATH="${ZW_OA_HEALTH_WP_PATH:-/www/wwwroot/tcpc.org.cn}"
BASE="${ZW_OA_HEALTH_BASE:-https://www.tcpc.org.cn}"
BHZC_BASELINE="${ZW_OA_HEALTH_BHZC_BASELINE:-/root/tcpc-release-locks/oa-bhzc.sha256}"
RELEASE_RECORD="${ZW_OA_HEALTH_RELEASE_RECORD:-/root/tcpc-release-locks/tcpc-oa.released}"
ALERT_CMD="${ZW_OA_ALERT_CMD:-}"
FAKE="${ZW_OA_HEALTH_FAKE:-0}"

JSON=0; QUIET=0; NO_ALERT=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --json) JSON=1 ;;
    --quiet) QUIET=1 ;;
    --no-alert) NO_ALERT=1 ;;
    --self-test) SELF_TEST=1 ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "未知参数：$1" >&2; exit 2 ;;
  esac
  shift
done

FAILS=0
declare -a ALERTS=()

emit() { # emit <id> <ok|fail> <detail>
  local id="$1" st="$2" detail="$3"
  if [[ "$st" == "fail" ]]; then
    FAILS=$((FAILS + 1))
    case "$id" in
      plugin_active) ALERTS+=("PLUGIN_MISSING|$detail") ;;
      version) ALERTS+=("VERSION_DRIFT|$detail") ;;
      menus) ALERTS+=("MENU_MISSING|$detail") ;;
      symbols) ALERTS+=("SYMBOL_MISSING|$detail") ;;
      rest) ALERTS+=("REST_DOWN|$detail") ;;
      urls) ALERTS+=("PUBLIC_URL_DOWN|$detail") ;;
      bhzc) ALERTS+=("BHZC_CHANGED|$detail") ;;
      release) ALERTS+=("RELEASE_DRIFT|$detail") ;;
      cron) ALERTS+=("CRON_MISSING|$detail") ;;
      *) ALERTS+=("CHECK_FAILED|$detail") ;;
    esac
  fi
  if [[ "$JSON" -eq 1 ]]; then
    printf '{"check":"%s","ok":%s,"detail":"%s"}\n' "$id" "$([[ "$st" == ok ]] && echo true || echo false)" "$(printf '%s' "$detail" | sed 's/\\/\\\\/g; s/"/\\"/g')"
  elif [[ "$QUIET" -eq 0 || "$st" == "fail" ]]; then
    printf '[%s] %s %s\n' "$([[ "$st" == ok ]] && echo '✓' || echo '✗')" "$id" "$detail"
  fi
}

fakev() { # fakev <name> <default>
  local n="FAKE_$1"; printf '%s' "${!n:-$2}"
}

# ── ① 插件激活 + 版本三方 ────────────────────────────────────────────────
check_plugin_active() {
  local active
  if [[ "$FAKE" = "1" ]]; then
    active="$(fakev ACTIVE 1)"
  else
    active="$(wp --path="$WP_PATH" --allow-root plugin is-active tcpc-oa >/dev/null 2>&1 && echo 1 || echo 0)"
  fi
  if [[ "$active" = "1" ]]; then emit plugin_active ok "active"; else emit plugin_active fail "插件未激活或不存在"; fi
}

check_version() {
  local live header repo
  if [[ "$FAKE" = "1" ]]; then
    live="$(fakev LIVE_VERSION 0.1.28)"; header="$(fakev HEADER_VERSION "${live}")"; repo="$(fakev REPO_VERSION "${live}")"
  else
    live="$(wp --path="$WP_PATH" --allow-root eval 'echo defined("TCPC_OA_VERSION") ? TCPC_OA_VERSION : "";' 2>/dev/null || true)"
    header="$(awk '/^ \* Version:/ {print $3; exit}' "$WP_PATH/wp-content/plugins/tcpc-oa/tcpc-oa.php" 2>/dev/null || true)"
    repo="$(tr -d ' \t\r\n' < "$ROOT/VERSION" 2>/dev/null || true)"
  fi
  local bad=""
  [[ -n "$live" ]] || bad="运行时常量为空"
  [[ -n "$header" && "$header" = "$live" ]] || bad="${bad}${bad:+; }头(${header:-空})≠运行时常量(${live:-空})"
  [[ -z "$repo" || "$repo" = "$live" ]] || bad="${bad}${bad:+; }仓($repo)≠运行时常量($live)"
  if [[ -z "$bad" ]]; then emit version ok "live=$live"; else emit version fail "$bad"; fi
}

# ── ② 六菜单 / 关键符号 / REST ───────────────────────────────────────────
check_menus() {
  local out
  if [[ "$FAKE" = "1" ]]; then
    out="$(fakev MENUS 'zw-doc,zw-receipt,zw-org,zw-policy-platform,zw-org-files,zw-secretariat')"
  else
    out="$(wp --path="$WP_PATH" --allow-root --user=1 eval 'require_once ABSPATH."wp-admin/includes/admin.php"; do_action("admin_menu"); global $menu,$submenu; $s=array(); foreach((array)$menu as $m){$s[]=$m[2];} foreach((array)$submenu as $i){foreach((array)$i as $it){$s[]=$it[2];}} echo implode(",", array_values(array_unique($s)));' 2>/dev/null || true)"
  fi
  local miss=""
  for slug in zw-doc zw-receipt zw-org zw-policy-platform zw-org-files zw-secretariat; do
    case ",$out," in *",$slug,"*) ;; *) miss="${miss}${miss:+, }$slug" ;; esac
  done
  if [[ -z "$miss" ]]; then emit menus ok "6/6"; else emit menus fail "缺失：$miss"; fi
}

check_symbols() {
  local out
  if [[ "$FAKE" = "1" ]]; then
    out="$(fakev SYMBOLS '1,1,1,1,1,1')"
  else
    out="$(wp --path="$WP_PATH" --allow-root eval '$fs=array("tcpc_oa_api_manifest","zw_legal_text_current","zw_policy_check","zw_legal_consent_enforce_enabled","zw_oa_org_person_meta_set","zw_oa_storage_get"); $r=array(); foreach($fs as $f){$r[]=function_exists($f)?"1":"0";} echo implode(",", $r);' 2>/dev/null || true)"
  fi
  [[ "$out" = "1,1,1,1,1,1" ]] && emit symbols ok "6/6" || emit symbols fail "关键符号缺失（$out）"
}

check_rest() {
  local out
  if [[ "$FAKE" = "1" ]]; then
    out="$(fakev REST ok)"
  else
    out="$(wp --path="$WP_PATH" --allow-root --user=1 eval '$r=rest_do_request(new WP_REST_Request("GET","/tcpc-oa/v1/health")); if(is_wp_error($r)){echo "err:".$r->get_error_code();} else {$d=$r->get_data(); echo (isset($d["ok"])&&$d["ok"])?"ok":"bad";}' 2>/dev/null || true)"
  fi
  [[ "$out" = "ok" ]] && emit rest ok "health ok" || emit rest fail "health=$out"
}

# ── ③ 公开页 + /bhzc 哈希 ────────────────────────────────────────────────
check_urls() {
  local codes
  if [[ "$FAKE" = "1" ]]; then
    codes="$(fakev URLS '200,200,200,200')"
  else
    codes=""
    for u in /login /privacy-policy/ /userra/ /bhzc; do
      c="$(curl -s -L -o /dev/null -w '%{http_code}' --max-time 20 "${BASE%/}${u}" 2>/dev/null || echo 000)"
      codes="${codes}${codes:+,}$c"
    done
  fi
  [[ "$codes" = "200,200,200,200" ]] && emit urls ok "$codes" || emit urls fail "URL 状态码=$codes（期望 200,200,200,200）"
}

check_bhzc() {
  local cur base
  if [[ "$FAKE" = "1" ]]; then
    cur="$(fakev BHZC abc)"; base="$(fakev BHZC_BASE abc)"
  else
    cur="$(wp --path="$WP_PATH" --allow-root eval 'echo md5((string) get_post(64)->post_content);' 2>/dev/null || true)"
    base=""
    if [[ -f "$BHZC_BASELINE" ]]; then
      base="$(tr -d ' \t\r\n' < "$BHZC_BASELINE" 2>/dev/null || true)"
    fi
    if [[ -n "$cur" && -z "$base" ]]; then
      mkdir -p "$(dirname "$BHZC_BASELINE")" 2>/dev/null || true
      printf '%s\n' "$cur" > "$BHZC_BASELINE" 2>/dev/null || true
      base="$cur"
    fi
  fi
  if [[ -n "$cur" && "$cur" = "$base" ]]; then emit bhzc ok "$cur"; else emit bhzc fail "正文哈希变化：当前=${cur:-空} 基线=${base:-空}（铁律 19）"; fi
}

# ── ④ 发布记录 / 定时任务 ────────────────────────────────────────────────
check_release() {
  local live recorded
  if [[ "$FAKE" = "1" ]]; then
    live="$(fakev LIVE_VERSION 0.1.28)"; recorded="$(fakev RELEASE "${live}")"
  else
    live="$(wp --path="$WP_PATH" --allow-root eval 'echo defined("TCPC_OA_VERSION") ? TCPC_OA_VERSION : "";' 2>/dev/null || true)"
    recorded="$(grep -oE '"version":"[0-9.]+"' "$RELEASE_RECORD" 2>/dev/null | head -1 | cut -d'"' -f4 || true)"
  fi
  [[ -n "$live" && "$recorded" = "$live" ]] && emit release ok "record=$recorded" || emit release fail "发版记录=${recorded:-空} 生产=${live:-空}"
}

check_cron() {
  local out
  if [[ "$FAKE" = "1" ]]; then
    out="$(fakev CRON 'zw_policy_platform_notification_event,zw_legal_consent_notify_event,zw_policy_platform_archive_event,zw_policy_platform_recon_event,zw_policy_platform_age_event')"
  else
    out="$(wp --path="$WP_PATH" --allow-root cron event list --fields=hook --format=csv 2>/dev/null | tail -n +2 | tr '\n' ',' || true)"
  fi
  local miss=""
  for h in zw_policy_platform_notification_event zw_legal_consent_notify_event zw_policy_platform_archive_event zw_policy_platform_recon_event zw_policy_platform_age_event; do
    case ",$out," in *",$h,"*) ;; *) miss="${miss}${miss:+, }$h" ;; esac
  done
  if [[ -z "$miss" ]]; then emit cron ok "5/5"; else emit cron fail "缺失事件：$miss"; fi
}

dispatch_alerts() {
  [[ ${#ALERTS[@]} -eq 0 ]] && return 0
  local msg
  msg="[tcpc-oa health] $(date '+%F %T') 失败 ${#ALERTS[@]} 项"
  for a in "${ALERTS[@]}"; do msg="${msg}"$'\n'"  - ${a}"; done
  printf '%s\n' "$msg" >&2
  if [[ "$NO_ALERT" -eq 0 && -n "$ALERT_CMD" ]]; then
    printf '%s\n' "$msg" | eval "$ALERT_CMD" || true
  fi
}

run_checks() {
  check_plugin_active
  check_version
  check_menus
  check_symbols
  check_rest
  check_urls
  check_bhzc
  check_release
  check_cron
}

# ── 自测（夹具，不碰生产）────────────────────────────────────────────────
if [[ "${SELF_TEST:-0}" = "1" ]]; then
  echo "== health-oa 夹具自测 =="
  fail=0; pass=0
  t() { # t <name> <expect_rc> <expect_type|-> <env...>
    local name="$1" erc="$2" etype="$3"; shift 3
    local out rc
    out="$(env ZW_OA_HEALTH_FAKE=1 ZW_OA_HEALTH_REPO="$ROOT" "$@" bash "$0" --no-alert 2>&1)"; rc=$?
    if [[ "$rc" -ne "$erc" ]]; then echo "  ✗ $name（期望 rc=$erc 实际 $rc）"; fail=$((fail+1)); return 1; fi
    if [[ "$etype" != "-" ]] && ! printf '%s' "$out" | grep -q "$etype|"; then
      echo "  ✗ $name（期望 $etype）"; fail=$((fail+1)); return 1
    fi
    echo "  ✓ $name"; pass=$((pass+1)); return 0
  }
  base=( )
  ok=0
  out="$(ZW_OA_HEALTH_FAKE=1 bash "$0" --no-alert 2>&1)"; rc=$?
  [[ "$rc" -eq 0 ]] && { echo "  ✓ 全绿 rc=0"; pass=$((pass+1)); } || { echo "  ✗ 全绿 rc=$rc"; fail=$((fail+1)); }
  t "插件未激活" 1 PLUGIN_MISSING FAKE_ACTIVE=0
  t "版本漂移" 1 VERSION_DRIFT FAKE_HEADER_VERSION=0.1.27 FAKE_REPO_VERSION=0.1.27
  t "菜单缺失" 1 MENU_MISSING FAKE_MENUS="zw-doc,zw-receipt"
  t "符号缺失" 1 SYMBOL_MISSING FAKE_SYMBOLS="1,0,1,1,1,1"
  t "REST 异常" 1 REST_DOWN FAKE_REST=err
  t "公开页 500" 1 PUBLIC_URL_DOWN FAKE_URLS="200,500,200,200"
  t "bhzc 变化" 1 BHZC_CHANGED FAKE_BHZC=zzz
  t "发布记录漂移" 1 RELEASE_DRIFT FAKE_RELEASE=0.1.27
  t "cron 缺失" 1 CRON_MISSING FAKE_CRON="zw_policy_platform_notification_event"
  out="$(ZW_OA_HEALTH_FAKE=1 bash "$0" --json 2>/dev/null)"; rc=$?
  if [[ "$rc" -eq 0 ]] && echo "$out" | head -1 | grep -q '"check"'; then echo "  ✓ --json 输出可解析形状"; pass=$((pass+1)); else echo "  ✗ --json 输出异常"; fail=$((fail+1)); fi
  echo "自测结果：${pass} 通过 / ${fail} 失败"
  [[ "$fail" -eq 0 ]] && exit 0 || exit 1
fi

run_checks
dispatch_alerts
[[ "$FAILS" -eq 0 ]] && exit 0 || exit 1
