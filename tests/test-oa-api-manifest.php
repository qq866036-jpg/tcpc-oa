<?php
/**
 * OA-2499：OA API 清单 / 版本握手 / 降级语义 — 纯函数回归（不需要 WP）。
 *
 * 运行：php tests/test-oa-api-manifest.php
 * 退出码：0 全绿；1 失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/includes/api/oa-api.php';

$GLOBALS['oaapi_pass'] = 0;
$GLOBALS['oaapi_fail'] = array();

function oaapi_ok($m) { $GLOBALS['oaapi_pass']++; echo "  ✓ {$m}\n"; }
function oaapi_bad($m) { $GLOBALS['oaapi_fail'][] = $m; fwrite(STDERR, "  ✗ {$m}\n"); }
function oaapi_chk($c, $m) { $c ? oaapi_ok($m) : oaapi_bad($m); }

echo "== 1) 版本与清单结构 ==\n";
oaapi_chk(is_int(tcpc_oa_api_version()) && tcpc_oa_api_version() >= 1, 'API 版本 >= 1');
$m = tcpc_oa_api_manifest();
oaapi_chk(is_array($m) && !empty($m['symbols']), 'manifest 含 symbols');
oaapi_chk(($m['namespace'] ?? '') === 'tcpc-oa/v1', 'REST 命名空间 = tcpc-oa/v1');
oaapi_chk(count($m['symbols']) >= 10, '清单至少 10 个符号（实测 ' . count($m['symbols']) . '）');

$bad = array();
foreach ($m['symbols'] as $key => $e) {
    if (!is_string($key) || $key === '') { $bad[] = 'key'; continue; }
    if (!in_array(($e['provider'] ?? ''), array('oa', 'theme', 'branch'), true)) { $bad[] = $key . ':provider'; }
    if (!is_int($e['version'] ?? null) || ($e['version'] ?? 0) < 1) { $bad[] = $key . ':version'; }
    if (!in_array(($e['degrade'] ?? ''), array('empty', 'fail_closed', 'deny'), true)) { $bad[] = $key . ':degrade'; }
    if (!is_array($e['consumers'] ?? null) || !$e['consumers']) { $bad[] = $key . ':consumers'; }
    if (($e['php'] ?? '') !== '' && !preg_match('/^(zw_|tcpc_)[a-z0-9_]+$/', (string) $e['php'])) { $bad[] = $key . ':php'; }
}
oaapi_chk(!$bad, '每个符号 provider/version/degrade/consumers/php 合法' . ($bad ? '（问题：' . implode(',', $bad) . '）' : ''));

echo "== 2) 支持 / 可用 / 降级 ==\n";
oaapi_chk(tcpc_oa_api_supports('legal.text.current'), '已登记符号 supports=true');
oaapi_chk(!tcpc_oa_api_supports('no.such.symbol'), '未登记符号 supports=false');
oaapi_chk(!tcpc_oa_api_available('legal.text.render'), '未声明 PHP 符号 ⇒ available=false');
oaapi_chk(tcpc_oa_api_degrade('legal.consent.enforce') === 'fail_closed', '签署拦截降级 = fail_closed');
oaapi_chk(tcpc_oa_api_degrade('legal.text.current') === 'empty', '文本读源降级 = empty');
oaapi_chk(tcpc_oa_api_degrade('no.such') === 'none', '未知符号降级 = none');

echo "== 3) call：未知 / 不可用 / 可用 ==\n";
$r = tcpc_oa_api_call('no.such.symbol');
oaapi_chk($r['ok'] === false && $r['code'] === 'unknown', '未知符号 ⇒ ok=false / code=unknown');
$r = tcpc_oa_api_call('legal.text.render');
oaapi_chk($r['ok'] === false && $r['code'] === 'unavailable' && $r['degrade'] === 'empty', '不可用 ⇒ ok=false / code=unavailable + degrade');
if (!function_exists('zw_legal_text_current')) {
    function zw_legal_text_current($doc_id) { return array('doc_id' => $doc_id, 'body_html' => '<p>x</p>'); }
}
$r = tcpc_oa_api_call('legal.text.current', array('SRC-PRIVACY-POLICY'));
oaapi_chk($r['ok'] === true && $r['code'] === 'ok' && is_array($r['data']), '可用符号 ⇒ ok=true 且透传参数');
oaapi_chk(($r['data']['doc_id'] ?? '') === 'SRC-PRIVACY-POLICY', '参数按位置透传');

echo "== 4) health ==\n";
$h = tcpc_oa_api_health();
oaapi_chk(($h['ok'] ?? false) === true, 'health ok=true');
oaapi_chk(($h['api_version'] ?? 0) === tcpc_oa_api_version(), 'health 带 API 版本');
oaapi_chk(($h['symbols_total'] ?? 0) === count($m['symbols']), 'health 符号总数与清单一致');
oaapi_chk(is_int($h['symbols_available'] ?? null), 'health 带可用符号数');

echo "== 5) REST 只读约束（源码级） ==\n";
$src = (string) file_get_contents(dirname(__DIR__) . '/includes/api/oa-api.php');
oaapi_chk(strpos($src, "register_rest_route('tcpc-oa/v1', '/manifest'") !== false, '注册 GET /manifest');
oaapi_chk(strpos($src, "register_rest_route('tcpc-oa/v1', '/health'") !== false, '注册 GET /health');
oaapi_chk(strpos($src, "register_rest_route('tcpc-oa/v1', '/legal/") !== false, '注册 GET /legal/{doc}（OA-2505）');
oaapi_chk(strpos($src, "register_rest_route('tcpc-oa/v1', '/doc/inbox'") !== false, '注册 GET /doc/inbox（OA-2505）');
oaapi_chk(strpos($src, "register_rest_route('tcpc-oa/v1', '/files/") !== false, '注册 GET /files/{id}（OA-2505）');
oaapi_chk(strpos($src, "'methods'             => 'POST'") === false && strpos($src, "'methods' => 'POST'") === false, '无任何 POST 路由');
oaapi_chk(substr_count($src, 'tcpc_oa_api_rest_permission') >= 5, '五个路由都带权限回调');
// OA-2505：files 端点只回白名单字段，绝不返回物理路径/直链（越权与脱敏）。
// 判据落在**函数体**上（整文件断言会被别处的 path/url 误伤/误过）。
$file_body = '';
if (preg_match('/function tcpc_oa_api_rest_file\(.*?\n\}/s', $src, $fm)) {
    $file_body = (string) $fm[0];
}
oaapi_chk($file_body !== '', '定位到 files 端点函数体');
oaapi_chk($file_body !== '' && strpos($file_body, "'path'") === false, 'files 响应不含 path 字段');
oaapi_chk($file_body !== '' && strpos($file_body, "'url'") === false, 'files 响应不含 url 字段');
oaapi_chk($file_body !== '' && strpos($file_body, "'owner_user_id' =>") === false, 'files 响应不外泄 owner_user_id（只允许作为入参读取）');
oaapi_chk($file_body !== '' && strpos($file_body, '$owner !== $uid') !== false, 'files 端点有「非本人拒绝」判据');
oaapi_chk($file_body !== '' && strpos($file_body, "current_user_can('manage_options')") !== false, '管理员可读（显式能力位）');

if ($GLOBALS['oaapi_fail']) {
    fwrite(STDERR, "FAIL: OA-2499 API 清单断言未通过（{$GLOBALS['oaapi_pass']} 通过 / " . count($GLOBALS['oaapi_fail']) . " 失败）\n");
    exit(1);
}
echo "OA-2499 OA API 清单通过（{$GLOBALS['oaapi_pass']} 项断言全绿）\n";
exit(0);
