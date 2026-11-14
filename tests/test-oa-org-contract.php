<?php
/**
 * OA-2500 / OA-2501：组织契约消费层回归。
 *  - OA-2500：字典契约（契约优先 / 旧主题回退 / 不新增 option）；
 *  - OA-2501：人员 meta 写口（set/delete 契约优先、旧主题回退、标量/前缀/ID 校验）。
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

echo "== 4) 人员 meta 写口：旧主题回退（无 person_meta_set 契约）==\n";
$GLOBALS['oaorg_meta'] = array();
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        $GLOBALS['oaorg_meta'][(int) $post_id][(string) $key] = $value;
        return true;
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key) {
        unset($GLOBALS['oaorg_meta'][(int) $post_id][(string) $key]);
        return true;
    }
}
oac_chk(zw_oa_org_person_meta_contract_available() === false, '无写口契约 ⇒ available=false');
oac_chk(zw_oa_org_person_meta_set(5, 'zw_person_duty', '会长') === true, '回退写入成功');
oac_chk(($GLOBALS['oaorg_meta'][5]['zw_person_duty'] ?? null) === '会长', '回退写到 zw_person_* meta');
oac_chk(zw_oa_org_person_meta_set(5, 'zw_person_phone', '') === true, '空字符串是标量 ⇒ 允许（清空语义）');
oac_chk(zw_oa_org_person_meta_set(5, 'zw_person_user_id', 0) === true, '整数 0 是标量 ⇒ 允许（解绑语义）');
oac_chk(zw_oa_org_person_meta_set(0, 'zw_person_duty', 'v') === false, 'person_id <= 0 ⇒ 拒绝');
oac_chk(zw_oa_org_person_meta_set(5, 'zw_file_person_id', 1) === false, '非 zw_person_ 前缀 ⇒ 拒绝');
oac_chk(zw_oa_org_person_meta_set(5, 'zw_person_duty', array('x')) === false, '数组值 ⇒ 拒绝（非标量）');
oac_chk(zw_oa_org_person_meta_set(5, 'zw_person_duty', new stdClass()) === false, '对象值 ⇒ 拒绝（非标量）');
oac_chk(!isset($GLOBALS['oaorg_meta'][0]) && !isset($GLOBALS['oaorg_meta'][5]['zw_file_person_id']),
    '拒绝路径零写入（fail-closed）');
oac_chk(zw_oa_org_person_meta_delete(5, 'zw_person_duty') === true, '回退删除成功');
oac_chk(!isset($GLOBALS['oaorg_meta'][5]['zw_person_duty']), '回退删除已生效');
oac_chk(zw_oa_org_person_meta_delete(5, 'zw_file_person_id') === false, '删除非 zw_person_ 前缀 ⇒ 拒绝');

echo "== 5) 人员 meta 写口：契约在场（契约优先）==\n";
$GLOBALS['oaorg_meta_contract'] = array();
if (!function_exists('zw_oa_org_data_person_meta_set')) {
    function zw_oa_org_data_person_meta_set($person_id, $key, $value) {
        $GLOBALS['oaorg_meta_contract'][] = array('set', (int) $person_id, (string) $key, $value);
        return true;
    }
}
if (!function_exists('zw_oa_org_data_person_meta_delete')) {
    function zw_oa_org_data_person_meta_delete($person_id, $key) {
        $GLOBALS['oaorg_meta_contract'][] = array('delete', (int) $person_id, (string) $key);
        return true;
    }
}
oac_chk(zw_oa_org_person_meta_contract_available() === true, '契约在场 ⇒ available=true');
oac_chk(zw_oa_org_person_meta_set(7, 'zw_person_email', 'a@b.c') === true, '写走契约');
oac_chk(($GLOBALS['oaorg_meta_contract'][0] ?? array()) === array('set', 7, 'zw_person_email', 'a@b.c'),
    '契约收到 person_id/key/value');
oac_chk(!isset($GLOBALS['oaorg_meta'][7]['zw_person_email']), '契约在场时不走 update_post_meta 回退');
oac_chk(zw_oa_org_person_meta_delete(7, 'zw_person_org_role') === true, '删除走契约删口');
oac_chk(($GLOBALS['oaorg_meta_contract'][1] ?? array()) === array('delete', 7, 'zw_person_org_role'),
    '契约删口收到 person_id/key');
oac_chk(zw_oa_org_person_meta_set(7, 'zw_person_x', array()) === false, '契约在场时标量校验仍生效');

echo "== 6) 源码级：org-repository 人员 meta 写口零直连 ==\n";
oac_chk(preg_match_all('/\bupdate_post_meta\(/', $repo) === 0, 'org-repository 无 update_post_meta(');
oac_chk(preg_match_all('/\bdelete_post_meta\(/', $repo) === 0, 'org-repository 无 delete_post_meta(');
oac_chk(substr_count($repo, 'zw_oa_org_person_meta_set(') === 30, '30 处写入走契约消费层');
oac_chk(substr_count($repo, 'zw_oa_org_person_meta_delete(') === 1, '1 处删除走契约消费层');
$contract_pm = (string) file_get_contents(dirname(__DIR__) . '/includes/org/org-contract.php');
oac_chk(strpos($contract_pm, 'function zw_oa_org_person_meta_set(') !== false
    && strpos($contract_pm, 'function zw_oa_org_person_meta_delete(') !== false, '消费层提供 set/delete 两函数');

if ($GLOBALS['oac_fail']) {
    fwrite(STDERR, "FAIL: OA-2500 组织契约断言未通过（{$GLOBALS['oac_pass']} 通过 / " . count($GLOBALS['oac_fail']) . " 失败）\n");
    exit(1);
}
echo "OA-2500 / OA-2501 组织契约通过（{$GLOBALS['oac_pass']} 项断言全绿）\n";
exit(0);
