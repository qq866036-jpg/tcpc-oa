#!/usr/bin/env bash
# OA-2508：health-oa.sh 回归（夹具自测 + 静态判据；不碰生产）。
#
# 覆盖：
#  ① `--self-test` 11 项全绿（含 9 类告警各自的判别力）；
#  ② `--help` rc=0；未知参数 rc=2；
#  ③ 静态：9 个 check id 与 9 类告警齐全；
#  ④ 静态：脚本**只读**（不含写 WordPress 数据的命令、不含写插件目录的动作）；
#  ⑤ `--json` 在夹具下可被解析（用 python3 json 逐行校验）。
#
# 运行：bash tests/test-health-oa.sh
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
S="$ROOT/scripts/health-oa.sh"
PASS=0; FAIL=0
ok() { PASS=$((PASS+1)); printf '  ✓ %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf '  ✗ %s\n' "$1" >&2; }
chk() { if [ "$1" -eq 0 ]; then ok "$2"; else bad "$2"; fi; }

[ -f "$S" ] || { echo "✗ 缺脚本：$S" >&2; exit 1; }

echo "== ① 夹具自测 =="
bash "$S" --self-test >/tmp/oa-health-selftest.log 2>&1; RC=$?
chk "$RC" "self-test rc=0（实际 $RC）"
grep -q "11 通过 / 0 失败" /tmp/oa-health-selftest.log; chk $? "self-test 11/11"

echo "== ② CLI 契约 =="
bash "$S" --help >/dev/null 2>&1; RC=$?
chk "$RC" "--help rc=0（实际 $RC）"
bash "$S" --bogus >/dev/null 2>&1; RC=$?
[ "$RC" -eq 2 ]; chk $? "未知参数 rc=2（实际 $RC）"

echo "== ③ 9 个检查项 / 9 类告警齐全 =="
for id in plugin_active version menus symbols rest urls bhzc release cron; do
  grep -q "emit $id " "$S"; chk $? "check：$id"
done
for t in PLUGIN_MISSING VERSION_DRIFT MENU_MISSING SYMBOL_MISSING REST_DOWN PUBLIC_URL_DOWN BHZC_CHANGED RELEASE_DRIFT CRON_MISSING; do
  grep -q "$t|" "$S"; chk $? "alert：$t"
done

echo "== ④ 只读断言（不得写 WordPress 数据 / 不得改插件目录）=="
BAD=''
for p in "option update" "post update" "plugin activate" "plugin deactivate" "wp_insert_post" "update_option(" "update_post_meta("; do
  if grep -qF "$p" "$S"; then BAD="${BAD}${BAD:+, }$p"; fi
done
[ -z "$BAD" ]; chk $? "无写库/改插件动作（命中：${BAD:-无}）"
grep -q 'BHZC_BASELINE' "$S" && grep -q 'printf .%s.*> "\$BHZC_BASELINE"' "$S"; chk $? "唯一写盘点 = /bhzc 基线（首次）"

echo "== ⑤ --json 可解析 =="
OUT="$(ZW_OA_HEALTH_FAKE=1 bash "$S" --json 2>/dev/null)"
printf '%s\n' "$OUT" | python3 -c 'import sys,json; rows=[json.loads(l) for l in sys.stdin if l.strip()]; assert len(rows)==9, len(rows); assert all("check" in r and "ok" in r for r in rows); print("ok")' >/tmp/oa-health-json.out 2>&1
chk $? "--json 9 行且字段齐全（$(cat /tmp/oa-health-json.out)）"

if [ "$FAIL" -gt 0 ]; then
  echo "✗ OA-2508 health-oa 回归失败：${PASS} 通过 / ${FAIL} 失败" >&2
  exit 1
fi
echo "✓ OA-2508 health-oa 回归通过：${PASS} 项"
