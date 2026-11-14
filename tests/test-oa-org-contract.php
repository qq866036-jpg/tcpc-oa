<?php
/**
 * OA-2500：组织字典契约消费层回归（契约优先 / 旧主题回退 / 不新增 option）。
 *
 * 运行：php tests/test-oa-org-contract.php
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

$GLOBALS['oaorg_opts'] = array(
    'zw_org_departments' => array(array('key' => 'legacy-d1')),
);
$GLOBALS['oaorg_contract_saved'] = null;

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['oaorg_opts']) ? $GLOBALS['oaorg_opts'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['oaorg_opts'][$name] = $value;
        return true;
    }
}

require_once dirname(__DIR__) . '/includes/org/org-contract.php';

$GLOBALS['oac_pass'] = 0;
$GLOBALS['oac_fail'] = array();
function oac_ok($m) { $GLOBALS['oac_pass']++; echo "  ✓ {$m}\n"; }
function oac_bad($m) { $GLOBALS['oac_fail'][] = $m; fwrite(STDERR, "  ✗ {$m}\n"); }
function oac_chk($c, $m) { $c ? oac_ok($m) : oac_bad($m); }

echo "== 1) 旧主题（无 P-8 契约）：回退直读既有 option ==\n";
oac_chk(zw_oa_org_contract_available() === false, '无契约 ⇒ available=false');
$d = zw_oa_org_dict_get('departments');
oac_chk(is_array($d) && ($d[0]['key'] ?? '') === 'legacy-d1', '回退读到既有 option');
oac_chk(zw_oa_org_dict_get('no_such', 'X') === 'X', '未登记键 ⇒ 回落值');
oac_chk(zw_oa_org_dict_save('duties', array(array('key' => 'd'))) === true, '回退写入成功');
oac_chk(($GLOBALS['oaorg_opts']['zw_org_duties'][0]['key'] ?? '') === 'd', '回退写到既有 option 名');
oac_chk(zw_oa_org_dict_save('duties', 'scalar') === false, '标量 ⇒ 拒绝');

echo "== 2) 新主题（P-8 契约在场）：契约优先 ==\n";
if (!function_exists('zw_oa_org_data_version')) {
    function zw_oa_org_data_version() { return 1; }
}
if (!function_exists('zw_oa_org_data_dict_get')) {
    function zw_oa_org_data_dict_get($key, $fallback = null) {
        return $key === 'departments' ? array(array('key' => 'contract-d1')) : $fallback;
    }
}
if (!function_exists('zw_oa_org_data_dict_save')) {
    function zw_oa_org_data_dict_save($key, $rows) {
        $GLOBALS['oaorg_contract_saved'] = array($key, $rows);
        return true;
    }
}
oac_chk(zw_oa_org_contract_available() === true, '契约在场 ⇒ available=true');
$d = zw_oa_org_dict_get('departments');
oac_chk(($d[0]['key'] ?? '') === 'contract-d1', '读走契约（不再读 option）');
oac_chk(zw_oa_org_dict_save('roles', array(array('key' => 'r'))) === true, '写走契约');
oac_chk(($GLOBALS['oaorg_contract_saved'][0] ?? '') === 'roles', '契约收到 key');
oac_chk(!isset($GLOBALS['oaorg_opts']['zw_org_roles']), '契约在场时不直写 option');

echo "== 3) 源码级：插件内无直连组织 option ==\n";
$repo = (string) file_get_contents(dirname(__DIR__) . '/includes/org/org-repository.php');
$hits = preg_match_all('/\b(?:get_option|update_option|delete_option)\(\s*ZW_ORG_/u', $repo);
oac_chk($hits === 0, 'org-repository 无 get/update/delete_option(ZW_ORG_*)');
$contract = (string) file_get_contents(dirname(__DIR__) . '/includes/org/org-contract.php');
oac_chk(substr_count($contract, 'zw_org_departments') === 1 && substr_count($contract, 'zw_org_duties') === 1,
    'option 名只在回退表单点出现');

if ($GLOBALS['oac_fail']) {
    fwrite(STDERR, "FAIL: OA-2500 组织契约断言未通过（{$GLOBALS['oac_pass']} 通过 / " . count($GLOBALS['oac_fail']) . " 失败）\n");
    exit(1);
}
echo "OA-2500 组织契约通过（{$GLOBALS['oac_pass']} 项断言全绿）\n";
exit(0);
