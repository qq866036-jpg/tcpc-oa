<?php
/**
 * OA-2515：附件说明国标化（GB/T 9704-2012 §7.3.4）—— 行计划 + 两通道渲染断言。
 *
 * 判据（本单要修的三件事）：
 *  1. 「附件：」与**阿拉伯数字顺序号**由系统生成（此前靠作者手打，漏打就少「附件：」、跳号就与清单不一致）；
 *  2. **多条**附件时，各条序号左缘对齐；名称较长**回行时与名称首字对齐**（悬挂，HTML 用 mark/name 两段，
 *     DOCX 用「左缩进 + 负首行缩进」表达）；
 *  3. 「正文下空一行」**只发生一次**（旧实现每行都吃一次 `margin-top` ⇒ 多条附件之间多出整行空白）。
 *
 * 兼容口径：存量 `meta.attach_note` 每项是作者手打的一整行（含「附件：」「1.」），解析后**逐字还原**。
 *
 * 运行：php tests/test-doc-attach-note-gb.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

foreach (array(
    'esc_html'       => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    'esc_attr'       => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    'wp_json_encode' => function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); },
) as $zw_an_name => $zw_an_fn) {
    if (!function_exists($zw_an_name)) {
        $GLOBALS['zw_an_stubs'][$zw_an_name] = $zw_an_fn;
        eval('function ' . $zw_an_name . '($v) { return call_user_func($GLOBALS["zw_an_stubs"]["' . $zw_an_name . '"], $v); }');
    }
}
unset($zw_an_name, $zw_an_fn);

require_once ABSPATH . 'includes/doc/doc-layout-gb9704.php';
require_once ABSPATH . 'includes/doc/doc-body-model.php';
require_once ABSPATH . 'includes/doc/doc-render-html.php';
require_once ABSPATH . 'includes/doc/doc-export-docx.php';

$GLOBALS['oa_an_pass'] = 0;
$GLOBALS['oa_an_fail'] = array();

function oa_an_ok($msg) {
    $GLOBALS['oa_an_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_an_bad($msg) {
    $GLOBALS['oa_an_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_an_chk($cond, $msg) {
    if ($cond) {
        oa_an_ok($msg);
    } else {
        oa_an_bad($msg);
    }
}

/**
 * 构造一份最小公文（仅附件说明相关字段），用于两通道渲染。
 *
 * @param array $notes 附件说明行。
 * @return array 正文 AST。
 */
function oa_an_body($notes) {
    return zw_doc_body_normalize(array(
        'v'      => 1,
        'meta'   => array(
            'org_name'    => '中国传统文化促进会',
            'title'       => '关于同意成立孔子文化传承工作委员会的批复',
            'sign_org'    => '中国传统文化促进会',
            'date'        => '2026年11月18日',
            'attach_note' => $notes,
        ),
        'blocks' => array(array('t' => 'p', 'text' => '正文一段。')),
    ));
}

echo "== 1) 行计划：自动「附件：」与顺序号（存量手打前缀被剥掉）==\n";
$plan_single = zw_doc_body_attach_plan(array('附件：1.视频拍摄方案'));
oa_an_chk(count($plan_single) === 1, '单条只有一行');
oa_an_chk($plan_single[0]['mark'] === '附件：', '单条不加顺序号（GB：单附件不编号）');
oa_an_chk($plan_single[0]['name'] === '视频拍摄方案', '剥掉手打的「附件：1.」');
$plan_plain = zw_doc_body_attach_plan(array('视频拍摄方案'));
oa_an_chk($plan_plain[0]['mark'] === '附件：', '纯名称旧行被补出「附件：」（本单要修的可见变化）');

$plan_multi = zw_doc_body_attach_plan(array('附件：1.视频拍摄方案', '2.预算明细表', '3、时间安排'));
oa_an_chk(count($plan_multi) === 3, '三条 = 三行');
oa_an_chk($plan_multi[0]['mark'] === '附件：1.', '第 1 条带「附件：」和序号');
oa_an_chk($plan_multi[1]['mark'] === '　　　2.', '第 2 条用全角空白补位 ⇒ 序号左缘对齐');
oa_an_chk($plan_multi[2]['name'] === '时间安排', '中文顿号序号也被剥离');
oa_an_chk(zw_doc_body_attach_plan(array('附件：1.甲', '字母序号乙'))[1]['mark'] === '　　　2.', '手打序号缺失时按位置重编号');

echo "\n== 2) 脏值与边界 ==\n";
oa_an_chk(zw_doc_body_attach_plan(array('  ', '附件：', '1.', '')) === array(), '空行/只有前缀/只有序号 ⇒ 不产生行');
$tenth = array();
for ($i = 1; $i <= 10; $i++) {
    $tenth[] = $i . '.附件' . $i;
}
$plan_tenth = zw_doc_body_attach_plan($tenth);
oa_an_chk(count($plan_tenth) === 10 && $plan_tenth[9]['mark'] === '　　　10.', '第 10 条仍是两位序号且补位一致');
oa_an_chk($plan_tenth[9]['prefix_chars'] >= 5, '两位序号的悬挂宽度随之增加（不会与名称重叠）');

echo "\n== 3) HTML 通道：结构与「空一行只一次」==\n";
$html = zw_doc_render_document_html(oa_an_body(array('附件：1.视频拍摄方案', '2.预算明细表')), array('doc_number' => '中传函〔2026〕98号'));
oa_an_chk(strpos($html, '<span class="tco-doc-attach-mark">附件：1.</span>') !== false, '第 1 条 mark 由系统生成');
oa_an_chk(strpos($html, '<span class="tco-doc-attach-name">预算明细表</span>') !== false, '第 2 条名称正确');
oa_an_chk(substr_count($html, 'tco-doc-attach is-first') === 1, '「is-first」只出现一次 ⇒ 空一行只发生一次');
$css = zw_doc_render_style_block('');
oa_an_chk(strpos($css, 'p.tco-doc-attach{margin-top:') === false, '旧规则（每行都吃 margin-top）已移除');
oa_an_chk(strpos($css, '.tco-doc-attach-mark{flex:0 0 auto') !== false, 'mark 不参与伸缩（宽度即前缀宽）');
oa_an_chk(strpos($css, '.tco-doc-attach-name{flex:1 1 auto;}') !== false, 'name 自成列 ⇒ 回行落在名称首字列内');
oa_an_chk(strpos($css, 'p.tco-doc-attach.is-first{margin-top:') !== false, '空一行挂在 is-first 上（只一次）');
oa_an_chk(strpos($css, 'padding-left:' . (int) zw_doc_gb9704_get('main.attach_indent_chars', 2) . 'em;display:flex') !== false, '左空二字取版式常量（不硬编码）');

echo "\n== 4) DOCX 通道：悬挂缩进（左缩进 + 负首行）==\n";
$body_xml = zw_doc_docx_body_xml(oa_an_body(array('附件：1.视频拍摄方案', '2.预算明细表')), array('doc_number' => '中传函〔2026〕98号'));
oa_an_chk(strpos($body_xml, '附件：1.视频拍摄方案') !== false, 'DOCX 段落文本含自动前缀');
oa_an_chk(strpos($body_xml, 'w:firstLineChars="-400"') !== false, '首行为负值（悬挂缩进 4 字）⇒ 回行对齐名称首字');
oa_an_chk(strpos($body_xml, 'w:leftChars="600"') !== false, '左缩进 = 左空二字(2) + 前缀(4) 字');
$para_neg = zw_doc_docx_para('测试', array('indent_chars' => -2));
oa_an_chk(strpos($para_neg, 'w:firstLineChars="-200"') !== false, '段落助手支持负缩进（悬挂）');

echo "\n== 5) 数量一致性提示（建单路径）==\n";
$src_list = (string) file_get_contents(ABSPATH . 'includes/doc/doc-admin-list.php');
oa_an_chk(strpos($src_list, '附件说明写了 ') !== false && strpos($src_list, '实际上传') !== false, '建单后有「说明条数 ⇄ 实际附件数」提示');
oa_an_chk(strpos($src_list, '请在编辑页核对后再用印') !== false, '提示写明下一步（去哪改、何时核对）');

echo "\n== 6) 撰写页提示口径与实现一致 ==\n";
$src_composer = (string) file_get_contents(ABSPATH . 'includes/doc/doc-composer.php');
oa_an_chk(strpos($src_composer, '「附件：」与序号由系统生成') !== false, '提示写明前缀/序号由系统生成（不再靠手打）');
oa_an_chk(strpos($src_composer, '名称回行时与名称首字对齐') !== false, '提示写明回行对齐');

echo "\n";
if (empty($GLOBALS['oa_an_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_an_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_an_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_an_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
