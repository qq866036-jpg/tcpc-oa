<?php
/**
 * OA-2522：冗余字段收口 —— doc_type 统一写入 / 密级期限结构化 / 留痕可见化。
 *
 * 判据：
 *  1. `meta.doc_type` 线上新建也写（与导入件同口径；任命类型判定原有「文号兜底」不动）；
 *  2. `meta.secret_level` / `meta.secret_period` 结构化双写，`meta.secret` 合成串仍是纸面口径；
 *     回填**优先结构化**、仅存量回落解析合成串；
 *  3. 法务取证 `_zw_doc_legal_clause_*` 与同版本解锁 `_zw_doc_edit_unlocked_*` **在详情页可见**
 *     （此前只写不读 ⇒ 写而不读等于没留痕），且无数据时不输出空壳。
 *
 * 运行：php tests/test-doc-field-consistency.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

foreach (array(
    'sanitize_key'      => function ($v) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $v)); },
    'sanitize_text_field' => function ($v) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $v))); },
    'sanitize_textarea_field' => function ($v) { return trim(strip_tags((string) $v)); },
    'wp_unslash'        => function ($v) { return $v; },
    'wp_json_encode'    => function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); },
    'esc_html'          => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    'esc_attr'          => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    'esc_textarea'      => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
) as $zw_fc_name => $zw_fc_fn) {
    if (!function_exists($zw_fc_name)) {
        $GLOBALS['zw_fc_stubs'][$zw_fc_name] = $zw_fc_fn;
        eval('function ' . $zw_fc_name . '($v) { return call_user_func($GLOBALS["zw_fc_stubs"]["' . $zw_fc_name . '"], $v); }');
    }
}
unset($zw_fc_name, $zw_fc_fn);

require_once ABSPATH . 'includes/doc/doc-layout-gb9704.php';
require_once ABSPATH . 'includes/doc/doc-body-model.php';
require_once ABSPATH . 'includes/doc/doc-render-html.php';
require_once ABSPATH . 'includes/doc/doc-composer.php';

$GLOBALS['oa_fc_pass'] = 0;
$GLOBALS['oa_fc_fail'] = array();

function oa_fc_ok($msg) {
    $GLOBALS['oa_fc_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_fc_bad($msg) {
    $GLOBALS['oa_fc_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_fc_chk($cond, $msg) {
    if ($cond) {
        oa_fc_ok($msg);
    } else {
        oa_fc_bad($msg);
    }
}

/**
 * 模拟一次新建发文提交（只关心本单三个字段）。
 *
 * @param array $overrides 覆盖默认 POST。
 * @return array 归一后的 AST（落库口径）。
 */
function oa_fc_save($overrides = array()) {
    $post = array_merge(array(
        'doc_title'         => '关于同意成立孔子文化传承工作委员会的批复',
        'num_type'          => '任',
        'zwc_org_name'      => '中国传统文化促进会',
        'zwc_date'          => '2026-11-18',
        'doc_department'    => '中国传统文化促进会',
        'doc_issue_date'    => '2026-11-18',
        'zw_doc_block_type' => array('p'),
        'zw_doc_block_text' => array('正文一段。'),
    ), $overrides);

    $body = zw_doc_composer_body_from_post($post, array(
        'title'    => (string) $post['doc_title'],
        'date'     => '2026年11月18日',
        'org_name' => '中国传统文化促进会',
    ));

    return zw_doc_body_normalize($body);
}

echo "== 1) doc_type：线上新建也写（与导入件同口径）==\n";
$saved = oa_fc_save(array('num_type' => '任'));
oa_fc_chk((string) $saved['meta']['doc_type'] === '任', 'meta.doc_type = 文种简称「任」');
$saved2 = oa_fc_save(array('num_type' => '函'));
oa_fc_chk((string) $saved2['meta']['doc_type'] === '函', '换成「函」也随文种写入（不是写死）');

echo "\n== 2) 密级/期限：结构化双写，合成串仍供纸面 ==\n";
$sec = oa_fc_save(array('zwc_secret_level' => '机密', 'zwc_secret_period' => '1年'));
oa_fc_chk((string) $sec['meta']['secret_level'] === '机密', 'meta.secret_level 落库');
oa_fc_chk((string) $sec['meta']['secret_period'] === '1年', 'meta.secret_period 落库');
oa_fc_chk((string) $sec['meta']['secret'] === '机密★1年', 'meta.secret 合成串保持（纸面口径不变）');
$html = zw_doc_render_document_html($sec, array('doc_number' => '中传任〔2026〕5号'));
oa_fc_chk(strpos($html, '机密★1年') !== false, '纸面仍渲染「密级★期限」（渲染未改口径）');

echo "\n== 3) 回填：优先结构化，存量回落解析合成串 ==\n";
$src_composer = (string) file_get_contents(ABSPATH . 'includes/doc/doc-composer.php');
oa_fc_chk(strpos($src_composer, "\$body['meta']['secret_level']") !== false, '回填读结构化 secret_level');
oa_fc_chk(strpos($src_composer, "\$body['meta']['secret_period']") !== false, '回填读结构化 secret_period');
$legacy = zw_doc_composer_secret_parse('秘密★3年');
oa_fc_chk($legacy['level'] === '秘密' && $legacy['period'] === '3年', '存量合成串仍能解析（兼容读法保留）');

echo "\n== 4) 留痕：法务取证 / 同版本解锁 在详情页可见 ==\n";
$src_list = (string) file_get_contents(ABSPATH . 'includes/doc/doc-admin-list.php');
oa_fc_chk(strpos($src_list, '_zw_doc_legal_clause_sources') !== false, '详情页读法务条款取证（参与条款源）');
oa_fc_chk(strpos($src_list, '_zw_doc_legal_clause_missed_count') !== false, '详情页读「未取到条款」数');
oa_fc_chk(strpos($src_list, '法务取证：') !== false, '有「法务取证」可见标签');
oa_fc_chk(strpos($src_list, '_zw_doc_edit_unlocked_at') !== false && strpos($src_list, '_zw_doc_edit_unlocked_by') !== false, '详情页读同版本解锁的时间与操作人');
oa_fc_chk(strpos($src_list, '同版本解锁记录：') !== false, '有「同版本解锁记录」可见标签');
// 无数据时不输出空壳：两段都在 if 里
oa_fc_chk(strpos($src_list, "if (\$zw_clause_sources > 0 || \$zw_clause_missed > 0") !== false, '法务取证段按「无数据不输出」渲染');
oa_fc_chk(strpos($src_list, "if (\$zw_unlock_at !== '')") !== false, '解锁留痕段按「无数据不输出」渲染');

echo "\n== 5) 字典与白名单同步（新增键不得成为新的漂移）==\n";
$src_model = (string) file_get_contents(ABSPATH . 'includes/doc/doc-body-model.php');
oa_fc_chk(strpos($src_model, "'secret_level', 'secret_period'") !== false, '正文模型白名单含两个新键');
$src_dict = (string) file_get_contents(ABSPATH . 'includes/doc/doc-admin-dict.php');
oa_fc_chk(strpos($src_dict, 'body.meta.secret_level') !== false && strpos($src_dict, 'body.meta.secret_period') !== false, '数据字典登记指向结构化键（不再是「合成串的某部分」）');

echo "\n";
if (empty($GLOBALS['oa_fc_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_fc_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_fc_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_fc_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
