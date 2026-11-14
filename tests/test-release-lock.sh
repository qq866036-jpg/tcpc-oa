#!/usr/bin/env bash
# 回归：oa 发版互斥接入（OA-2494 / 口径 docs/ops/plugin-release-lock.md）
#
# 为什么这单要有判据：oa 的发版脚本有**两条生产写入路径**（正常发版 ④⑤、回滚 `--rollback`）。
# 只给正常路径加锁等于漏一半；而「回滚本身是降级」，版本守卫必须只对正常路径生效。
# 所以这里既测**行为**（锁语义），也测**接线**（两条路径都在写入前取锁、权限回读真能判红）。
#
# 覆盖：
#   ① 行为：取得 / 释放 / 版本记录 / 锁目录 0700 与记录 0600 / 降级 rc=4 / 放开降级 /
#            并发第二者 rc=3（打印持有者）/ 持有者被 kill -9 后锁自动释放；
#   ② 接线：source 唯一出处副本；正常路径 acquire 早于「③ 构建产物」「④ 备份」「⑤ 交换」；
#            回滚路径也取锁（且显式放开降级）且早于还原动作；成功路径 zwrel_release；
#            两个逃生口存在；权限归一 + 回读被调用，回读失败走还原；
#   ③ 权限归一本体：755 / 644 归一 + 回读能对 700 文件判红。
#
# 约定：`ZW_WP_CLI=/nonexistent` ⇒ 基线只来自 `.released` 记录（不读生产，完全离线）。
# 用法：bash tests/test-release-lock.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SWITCH="$ROOT/scripts/plugin-switch-oa.sh"

# shellcheck source=scripts/lib/release-lock.sh
. "$ROOT/scripts/lib/release-lock.sh"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export ZW_RELEASE_LOCK_DIR="$TMP/locks"
export ZW_WP_CLI=/nonexistent
unset ZW_RELEASE_ALLOW_DOWNGRADE 2>/dev/null || true

pass=0
fail=0
ck() {
  local name="$1" rc="$2"
  if [ "$rc" -eq 0 ]; then echo "  ✓ $name"; pass=$((pass + 1));
  else echo "  ✗ $name"; fail=$((fail + 1)); fi
}

echo "== ① 取得 / 释放 / 版本记录 / 目录与文件权限"
( zwrel_acquire demo 1.0.0 T1 && zwrel_release demo 1.0.0 T1 ) >/dev/null 2>&1
[ -f "$TMP/locks/demo.released" ]; ck "成功发布后写入 .released" $?
grep -q '"version":"1.0.0"' "$TMP/locks/demo.released" 2>/dev/null; ck "版本记录 = 1.0.0" $?
[ "$(stat -c %a "$TMP/locks")" = "700" ]; ck "锁目录权限 700" $?
[ "$(stat -c %a "$TMP/locks/demo.released")" = "600" ]; ck "版本记录权限 600" $?

echo "== ② 版本降级被拒（1.4.9 < 1.5.0）"
( zwrel_acquire demo2 1.5.0 T2 && zwrel_release demo2 1.5.0 T2 ) >/dev/null 2>&1
( zwrel_acquire demo2 1.4.9 T2 ) >/dev/null 2>&1
rc=$?; [ "$rc" -eq 4 ]; ck "降级返回 rc=4（实际 $rc）" $?
grep -q '"version":"1.5.0"' "$TMP/locks/demo2.released"; ck "降级失败不改写已发布版本" $?

echo "== ③ 放开降级后可控放行（回滚通道用同一开关）"
( ZW_RELEASE_ALLOW_DOWNGRADE=1 zwrel_acquire demo3 1.0.0 T3 && zwrel_release demo3 1.0.0 T3 ) >/dev/null 2>&1
( ZW_RELEASE_ALLOW_DOWNGRADE=1 zwrel_acquire demo3 0.9.0 T3 ) >/dev/null 2>&1
rc=$?; [ "$rc" -eq 0 ]; ck "ZW_RELEASE_ALLOW_DOWNGRADE=1 时 rc=0（实际 $rc）" $?

echo "== ④ 并发：第二个会话必须被拒（rc=3 且打印持有者）"
( zwrel_acquire demo4 2.0.0 A >/dev/null 2>&1; sleep 2 ) &
holder=$!
sleep 0.6
( zwrel_acquire demo4 2.0.1 B ) >"$TMP/t4.log" 2>&1
rc=$?
wait "$holder" 2>/dev/null
[ "$rc" -eq 3 ]; ck "并发第二者返回 rc=3（实际 $rc）" $?
grep -q "正被另一个会话发布" "$TMP/t4.log"; ck "错误信息点名「正被另一个会话发布」" $?
grep -q '"task": "A"' "$TMP/t4.log"; ck "打印持有者任务号（task=A）" $?

echo "== ⑤ 进程退出即释放（kill -9 后不再占用）"
( zwrel_acquire demo5 3.0.0 C >/dev/null 2>&1; exec sleep 30 ) 2>/dev/null &
victim=$!
sleep 0.6
kill -9 "$victim" 2>/dev/null
sleep 0.4
( zwrel_acquire demo5 3.0.1 D ) >/dev/null 2>&1
rc=$?; [ "$rc" -eq 0 ]; ck "持有者被 kill -9 后锁自动释放（rc=0，实际 $rc）" $?

echo "== ⑥ 接线：正常路径的守卫必须早于任何生产写入"
grep -qF '. "$ROOT/scripts/lib/release-lock.sh"' "$SWITCH"; ck "plugin-switch-oa.sh source 唯一出处副本" $?
grep -qF 'zwrel_acquire "$PLUGIN_SLUG" "$VERSION" "$TASK_ID"' "$SWITCH"; ck "正常路径调用 zwrel_acquire（slug/版本/任务号）" $?
grep -qF 'trap '"'"'zwrel_unlock "$PLUGIN_SLUG"'"'"' EXIT INT TERM' "$SWITCH"; ck "trap 里解锁（失败/中断也释放）" $?
grep -qF 'zwrel_release "$PLUGIN_SLUG" "$VERSION" "$TASK_ID"' "$SWITCH"; ck "成功路径 zwrel_release（先写记录再解锁）" $?
grep -qF 'ZW_RELEASE_LOCK' "$SWITCH"; ck "逃生口 ZW_RELEASE_LOCK=0 存在" $?
grep -qF -- '--allow-downgrade|--force-unlock' "$SWITCH"; ck "逃生口 --allow-downgrade / --force-unlock" $?

ln_acquire="$(grep -n 'zwrel_acquire "$PLUGIN_SLUG" "$VERSION" "$TASK_ID"' "$SWITCH" | head -1 | cut -d: -f1)"
ln_build="$(grep -n '# ── ③ 构建产物' "$SWITCH" | head -1 | cut -d: -f1)"
ln_backup="$(grep -n '# ── ④ 备份' "$SWITCH" | head -1 | cut -d: -f1)"
ln_swap="$(grep -n '# ── ⑤ 原子交换' "$SWITCH" | head -1 | cut -d: -f1)"
[ -n "$ln_acquire" ] && [ -n "$ln_build" ] && [ "$ln_acquire" -lt "$ln_build" ]
ck "acquire 在「③ 构建产物」之前（${ln_acquire:-?} < ${ln_build:-?}）" $?
[ -n "$ln_backup" ] && [ "$ln_acquire" -lt "$ln_backup" ]
ck "acquire 在「④ 备份」之前（${ln_acquire:-?} < ${ln_backup:-?}）" $?
[ -n "$ln_swap" ] && [ "$ln_acquire" -lt "$ln_swap" ]
ck "acquire 在「⑤ 原子交换」之前（${ln_acquire:-?} < ${ln_swap:-?}）" $?

echo "== ⑦ 接线：回滚通道（另一条生产写入路径）同样取锁"
grep -qF 'ZW_RELEASE_ALLOW_DOWNGRADE=1 zwrel_acquire "$PLUGIN_SLUG" "${RB_VER:-0.0.0}" "$TASK_ID"' "$SWITCH"
ck "回滚路径取锁（显式放开降级）" $?
ln_rb_lock="$(grep -n 'ZW_RELEASE_ALLOW_DOWNGRADE=1 zwrel_acquire' "$SWITCH" | head -1 | cut -d: -f1)"
ln_rb_restore="$(grep -n 'run cp -a "$src" "$PLUGIN_DIR"' "$SWITCH" | head -1 | cut -d: -f1)"
[ -n "$ln_rb_lock" ] && [ -n "$ln_rb_restore" ] && [ "$ln_rb_lock" -lt "$ln_rb_restore" ]
ck "回滚取锁在「还原备份」之前（${ln_rb_lock:-?} < ${ln_rb_restore:-?}）" $?
grep -qF 'zwrel_release "$PLUGIN_SLUG" "$RB_VER" "$TASK_ID"' "$SWITCH"
ck "回滚成功后记录「回滚到的版本」（供后续降级守卫）" $?
# OA-2502：备份目录无 VERSION（export-ignore）⇒ 必须回退到插件头解析，且取不到时**不得**写 0.0.0
grep -qF 'RB_VER="$(awk ' "$SWITCH"
grep -qF 'Version:/ {print $3; exit}' "$SWITCH"
ck "回滚版本号回退到插件头解析（备份无 VERSION）" $?
grep -qF '仅解锁，**不写版本记录**' "$SWITCH"
ck "取不到版本时只解锁、不写版本记录（禁止 0.0.0）" $?
if grep -qF 'zwrel_release "$PLUGIN_SLUG" "${RB_VER:-0.0.0}"' "$SWITCH"; then
  ck "回滚不得再把版本记录写成 0.0.0" 1
else
  ck "回滚不得再把版本记录写成 0.0.0" 0
fi

echo "== ⑦b 接线：主题兼容门禁（OA-2502 事故：直发 fatal）"
grep -qF "function_exists('zw_policy_platform_cron_mask_webhook'" "$SWITCH"
ck "发版通道含主题守卫探针（插件优先条件加载）" $?
grep -qF '在线主题不兼容' "$SWITCH"
ck "主题不兼容时拒发（任何生产写入之前）" $?
grep -qF 'wp option get tcpc_oa_enable_policy' "$SWITCH"
ck "主题旧版时按 policy 开关判定（模块可关则放行）" $?
grep -qF 'policy 模块已关' "$SWITCH"
ck "policy=0 时放行并提示由主题副本提供" $?
ln_theme_gate="$(grep -n 'THEME_FUNCTIONS=' "$SWITCH" | head -1 | cut -d: -f1)"
ln_build_swap="$(grep -n 'STAGING="\$PLUGINS_ROOT' "$SWITCH" | head -1 | cut -d: -f1)"
[ -n "$ln_theme_gate" ] && [ -n "$ln_build_swap" ] && [ "$ln_theme_gate" -lt "$ln_build_swap" ]
ck "主题兼容门禁在「构建/交换」之前（${ln_theme_gate:-?} < ${ln_build_swap:-?}）" $?

echo "== ⑧ 接线：权限回读 fail-closed（不再是只告警）"
grep -qF 'oa_normalize_perms "$PLUGIN_DIR"' "$SWITCH"; ck "落地后做属主/权限归一" $?
grep -qF 'if ! oa_verify_perms "$PLUGIN_DIR"' "$SWITCH"; ck "回读失败进入阻断分支" $?
grep -qF 'oa_restore_backup' "$SWITCH"; ck "回读失败用本次备份还原" $?

echo "== ⑨ 权限归一本体（755 / 644 + 回读可判红）"
# 从**发货脚本本体**取函数求值（不复制第二套实现）；属主目标用当前用户，避免依赖 www。
eval "$(sed -n '/^oa_normalize_perms()/,/^}/p' "$SWITCH")"
eval "$(sed -n '/^oa_verify_perms()/,/^}/p' "$SWITCH")"
OWNER="$(id -un):$(id -gn)"
d="$TMP/perm/plugin"; mkdir -p "$d/includes"
echo '<?php' > "$d/includes/a.php"
chmod 700 "$d/includes"; chmod 600 "$d/includes/a.php"
oa_normalize_perms "$d" >/dev/null 2>&1
[ "$(stat -c %a "$d/includes")" = "755" ]; ck "子目录归一为 755" $?
[ "$(stat -c %a "$d/includes/a.php")" = "644" ]; ck "文件归一为 644" $?
oa_verify_perms "$d" >/dev/null 2>&1; ck "归一后回读通过" $?
chmod 700 "$d/includes/a.php"
oa_verify_perms "$d" >/dev/null 2>&1
[ $? -ne 0 ]; ck "文件不可读（700）时回读**判红**（fail-closed）" $?

echo "TOTAL: $pass 通过 / $fail 失败"
[ "$fail" -eq 0 ]
