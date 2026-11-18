<?php
/**
 * OA-2514：新建发文补齐「附注」「签发人」两项公文要素的端到端断言。
 *
 * 背景（用户 2026-11-18「再次检查，新建发文还有哪些版式与 国标和公文版式不一致，包括附件，正文等」）：
 * 渲染层与 DOCX 早就支持这两项（附注自动圆括号+左空二字；签发人与发文字号同行、文号左空一字、
 * 签发人右空一字），版式侧还有 `header.show_signer` 开关 —— 但**撰写页没有输入控件**，
 * 且 `zw_doc_composer_body_from_post()` 的 meta 白名单里**漏了 `signer` 映射**
 * ⇒ 这两项在「新建发文」里永远为空，纸面永远缺。GB/T 9704-2012 §7.3.5 / §7.2.4。
 *
 * 本测试走**真实链路**：POST → `zw_doc_composer_body_from_post()` → 再归一（落库口径）→ 渲染 HTML，
 * 并对「映射行被删」这种回归做**源码级反向控制**（判别力：删那一行本测试必红）。
 *
 * 运行：php tests/test-doc-note-signer.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// —— 最小 WP 桩（本测试只走纯逻辑 + 渲染，不碰数据库/会话）——
foreach (array(
    'sanitize_key'      => function ($v) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $v)); },
    'sanitize_text_field' => function ($v) { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $v))); },
    'sanitize_textarea_field' => function ($v) { return trim(strip_tags((string) $v)); },
    'wp_unslash'        => function ($v) { return $v; },
    'wp_json_encode'    => function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); },
    'esc_html'          => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    'esc_attr'          => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    'esc_textarea'      => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
) as $zw_ns_name => $zw_ns_fn) {
    if (!function_exists($zw_ns_name)) {
        eval('function ' . $zw_ns_name . '($v) { return call_user_func($GLOBALS["zw_ns_stubs"]["' . $zw_ns_name . '"], $v); }');
        $GLOBALS['zw_ns_stubs'][$zw_ns_name] = $zw_ns_fn;
    }
}
unset($zw_ns_name, $zw_ns_fn);

require_once ABSPATH . 'includes/doc/doc-layout-gb9704.php';
require_once ABSPATH . 'includes/doc/doc-body-model.php';
require_once ABSPATH . 'includes/doc/doc-render-html.php';
require_once ABSPATH . 'includes/doc/doc-composer.php';

$GLOBALS['oa_ns_pass'] = 0;
$GLOBALS['oa_ns_fail'] = array();

function oa_ns_ok($msg) {
    $GLOBALS['oa_ns_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_ns_bad($msg) {
    $GLOBALS['oa_ns_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_ns_chk($cond, $msg) {
    if ($cond) {
        oa_ns_ok($msg);
    } else {
        oa_ns_bad($msg);
    }
}

/**
 * 模拟一次「新建发文」提交：POST → body_from_post → 落库前再归一（与 `zw_doc_update_body()` 同口径）。
 *
 * @param array $overrides 覆盖默认 POST 字段。
 * @return array 归一后的正文 AST。
 */
function oa_ns_save($overrides = array()) {
    $post = array_merge(array(
        'doc_title'          => '关于同意成立孔子文化传承工作委员会的批复',
        'num_type'           => '函',
        'zwc_org_name'       => '中国传统文化促进会',
        'zwc_sign_org'       => '中国传统文化促进会',
        'zwc_date'           => '2026-11-18',
        'doc_department'     => '中国传统文化促进会',
        'doc_issue_date'     => '2026-11-18',
        'zw_doc_block_type'  => array('p'),
        'zw_doc_block_text'  => array('正文一段。'),
    ), $overrides);

    $body = zw_doc_composer_body_from_post($post, array(
        'title'    => (string) $post['doc_title'],
        'date'     => '2026年11月18日',
        'org_name' => '中国传统文化促进会',
    ));

    // 写库口径：`zw_doc_update_body()` / 建单 都会再归一一次。
    return zw_doc_body_normalize($body);
}

/**
 * 按「公开件」渲染（不带印章，落款走无章口径 ⇒ 附注紧随成文日期）。
 *
 * @param array $body 正文 AST。
 * @return string HTML。
 */
function oa_ns_render($body) {
    return zw_doc_render_document_html($body, array(
        'doc_number' => '中传函〔2026〕98号',
    ));
}

echo "== 1) 附注与签发人在链路里存活（读得出来、落得进去）==\n";
$saved = oa_ns_save(array(
    'zwc_note'   => '此件公开发布',
    'zwc_signer' => '张三',
));
oa_ns_chk($saved['meta']['note'] === '此件公开发布', '附注落进 meta.note');
oa_ns_chk($saved['meta']['signer'] === '张三', '签发人落进 meta.signer（此前白名单漏映射 ⇒ 必红）');

echo "\n== 2) 渲染：附注自动圆括号、签发人补「签发人：」==\n";
$html = oa_ns_render($saved);
oa_ns_chk(strpos($html, '<p class="tco-doc-note">（此件公开发布）</p>') !== false, '附注渲染为圆括号包裹的 .tco-doc-note');
oa_ns_chk(strpos($html, '签发人：<span class="tco-doc-signer-name">张三</span>') !== false, '签发人渲染为「签发人：姓名」且姓名用 h2（楷体）槽');
oa_ns_chk(strpos($html, 'tco-doc-number-row is-above-rule') !== false, '签发人与发文字号同一行（number-row）');

echo "\n== 3) 纸面顺序：附注在成文日期之后 ==\n";
$pos_date = strpos($html, '2026年11月18日');
$pos_note = strpos($html, '（此件公开发布）');
oa_ns_chk($pos_date !== false && $pos_note !== false && $pos_note > $pos_date, '附注出现在成文日期之后（GB §7.3.5）');

echo "\n== 4) 反向控制：两项留空时纸面零变化 ==\n";
$empty = oa_ns_save();
$html_empty = oa_ns_render($empty);
oa_ns_chk($empty['meta']['note'] === '' && $empty['meta']['signer'] === '', '留空落库为空串');
oa_ns_chk(strpos($html_empty, 'tco-doc-note') === false, '无附注时不输出附注段落');
oa_ns_chk(strpos($html_empty, 'tco-doc-signer') === false, '无签发人时不输出签发人');
oa_ns_chk(strpos($html_empty, '签发人：') === false, '无签发人时不残留「签发人：」冒号');

echo "\n== 5) 源码级守卫：表单控件与 meta 映射都在（删任一处必红）==\n";
$src = (string) file_get_contents(ABSPATH . 'includes/doc/doc-composer.php');
oa_ns_chk(strpos($src, 'name="zwc_note"') !== false, '表单有附注输入（name=zwc_note）');
oa_ns_chk(strpos($src, 'name="zwc_signer"') !== false, '表单有签发人输入（name=zwc_signer）');
oa_ns_chk(strpos($src, "'signer'      => \$get('zwc_signer', '')") !== false, 'body_from_post 有 signer 映射（缺此行则 §1 必红）');
oa_ns_chk(strpos($src, "'note'        => \$get('zwc_note', '')") !== false, 'body_from_post 的 note 映射未被破坏');

echo "\n== 6) 脏值与边界：HTML 被剥标签、超长被截断 ==\n";
$dirty = oa_ns_save(array(
    'zwc_note'   => '<b>此件</b>公开发布<script>alert(1)</script>',
    'zwc_signer' => str_repeat('张', 120),
));
oa_ns_chk(strpos($dirty['meta']['note'], '<') === false, '附注里的标签被剥掉（模型层第二道闸）');
oa_ns_chk(mb_strlen($dirty['meta']['signer'], 'UTF-8') <= 200, '签发人受 meta 长度上限约束');

echo "\n";
if (empty($GLOBALS['oa_ns_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_ns_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_ns_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_ns_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
