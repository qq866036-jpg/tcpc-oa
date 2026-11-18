<?php
/**
 * OA-2516：分页估算补标题上下间距 + 抄送悬挂对齐 + 字重由「公文版式」决定。
 *
 * 三件事各自的判据：
 *  1. **分页**：首页占用必须含「红线→标题」与（无主送时）「标题→正文」的间距行数。
 *     本测试用**差分法**证明它真的接了这两个键：同内容、只改 `gap_*`（2 vs 0）⇒ 首页块数必须相差 4；
 *     修复前两个键根本没被读 ⇒ 差值为 0（必红）。
 *  2. **抄送悬挂**：`.tco-doc-record-cc{padding-left:3em;text-indent:-3em}` + 抄送行带该类
 *     （GB §7.4.1「回行与冒号后的首字对齐」）。
 *  3. **字重**：`zw_doc_render_font_weight()` 读 `fonts.<slot>.weight` ——
 *     版式页把「一级标题字重」改成「常规」后，CSS 必须变成 `font-weight:normal`（修复前写死 bold ⇒ 不生效）。
 *
 * 运行：php tests/test-doc-layout-paginate-weight.php
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
) as $zw_lw_name => $zw_lw_fn) {
    if (!function_exists($zw_lw_name)) {
        $GLOBALS['zw_lw_stubs'][$zw_lw_name] = $zw_lw_fn;
        eval('function ' . $zw_lw_name . '($v) { return call_user_func($GLOBALS["zw_lw_stubs"]["' . $zw_lw_name . '"], $v); }');
    }
}
unset($zw_lw_name, $zw_lw_fn);

// —— 「公文版式」覆盖层桩：只注入本测试关心的键（值由 $GLOBALS['zw_lw_ov'] 给）——
$GLOBALS['zw_lw_ov'] = array();
function zw_doc_layout_get_overrides() {
    return $GLOBALS['zw_lw_ov'];
}
function zw_doc_layout_apply_overrides($spec) {
    // OA-2528：支持**任意层级**键（`fonts.h1.weight` 是 3 层）；旧桩只写 2 层会静默失效。
    foreach ((array) $GLOBALS['zw_lw_ov'] as $path => $value) {
        $parts = explode('.', (string) $path);
        if (count($parts) < 2) {
            continue;
        }
        $ref = &$spec;
        $ok  = true;
        $n   = count($parts);
        foreach ($parts as $i => $seg) {
            if ($i === $n - 1) {
                break;
            }
            if (!is_array($ref) || !array_key_exists($seg, $ref) || !is_array($ref[$seg])) {
                $ok = false;
                break;
            }
            $ref = &$ref[$seg];
        }
        if ($ok && is_array($ref)) {
            $ref[end($parts)] = $value;
        }
        unset($ref);
    }

    return $spec;
}

require_once ABSPATH . 'includes/doc/doc-layout-gb9704.php';
require_once ABSPATH . 'includes/doc/doc-body-model.php';
require_once ABSPATH . 'includes/doc/doc-render-html.php';

$GLOBALS['oa_lw_pass'] = 0;
$GLOBALS['oa_lw_fail'] = array();

function oa_lw_ok($msg) {
    $GLOBALS['oa_lw_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_lw_bad($msg) {
    $GLOBALS['oa_lw_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_lw_chk($cond, $msg) {
    if ($cond) {
        oa_lw_ok($msg);
    } else {
        oa_lw_bad($msg);
    }
}

/**
 * 一份「无主送机关」的样本文档（这样 `title.gap_below_title_lines` 才参与首页占用）。
 *
 * @param int $block_count 正文段数（每段一行）。
 * @return array 正文 AST。
 */
function oa_lw_body($block_count) {
    $blocks = array();
    for ($i = 0; $i < $block_count; $i++) {
        $blocks[] = array('t' => 'p', 'text' => '第' . ($i + 1) . '段');
    }

    return array(
        'v'      => 1,
        'meta'   => array('title' => '关于测试分页的批复', 'sign_org' => '中国传统文化促进会', 'date' => '2026年11月18日'),
        'blocks' => $blocks,
    );
}

/**
 * 取首页承载的块数。
 *
 * @param array $body 正文 AST。
 * @return int
 */
function oa_lw_first_page_blocks($body) {
    $pages = zw_doc_render_paginate($body, array('doc_number' => '中传函〔2026〕98号'));

    return isset($pages[0]) ? count($pages[0]) : 0;
}

echo "== 1) 分页：首页占用真的读了标题上/下间距（差分判据）==\n";
$blocks = 40;
$GLOBALS['zw_lw_ov'] = array('title.gap_below_rule_lines' => 2, 'title.gap_below_title_lines' => 2);
$first_big_gap = oa_lw_first_page_blocks(oa_lw_body($blocks));
$GLOBALS['zw_lw_ov'] = array('title.gap_below_rule_lines' => 0, 'title.gap_below_title_lines' => 0);
$first_no_gap = oa_lw_first_page_blocks(oa_lw_body($blocks));
oa_lw_chk($first_no_gap - $first_big_gap === 4, '间距 2+2 与 0+0 的首页块数差 = 4（修复前为 0）—— 实测 ' . $first_big_gap . ' vs ' . $first_no_gap);

// 有主送机关时，标题下间距由主送机关自身承担 ⇒ 不应再重复计 1 行（差分 = 2）。
$GLOBALS['zw_lw_ov'] = array('title.gap_below_rule_lines' => 2, 'title.gap_below_title_lines' => 2);
$recv_big = zw_doc_render_paginate(array(
    'v'      => 1,
    'meta'   => array('title' => '关于测试分页的批复', 'receiver' => '各会员单位：', 'sign_org' => '中国传统文化促进会', 'date' => '2026年11月18日'),
    'blocks' => array_fill(0, $blocks, array('t' => 'p', 'text' => '正文一段')),
), array());
$GLOBALS['zw_lw_ov'] = array('title.gap_below_rule_lines' => 0, 'title.gap_below_title_lines' => 0);
$recv_no = zw_doc_render_paginate(array(
    'v'      => 1,
    'meta'   => array('title' => '关于测试分页的批复', 'receiver' => '各会员单位：', 'sign_org' => '中国传统文化促进会', 'date' => '2026年11月18日'),
    'blocks' => array_fill(0, $blocks, array('t' => 'p', 'text' => '正文一段')),
), array());
oa_lw_chk(count($recv_no[0]) - count($recv_big[0]) === 2, '有主送机关时只差「红线→标题」2 行（标题下间距不重复计）');

echo "\n== 2) 抄送：悬挂对齐（GB §7.4.1）==\n";
$GLOBALS['zw_lw_ov'] = array();
$css = zw_doc_render_style_block('');
oa_lw_chk(strpos($css, '.tco-doc-record-cc{padding-left:3em;text-indent:-3em;}') !== false, '抄送行有「左内边距 3em + 首行 -3em」悬挂规则');
$html = zw_doc_render_document_html(array(
    'v'      => 1,
    'meta'   => array('title' => '关于测试抄送的批复', 'sign_org' => '中国传统文化促进会', 'date' => '2026年11月18日', 'cc' => '各分支机构、各专业委员会'),
    'blocks' => array(array('t' => 'p', 'text' => '正文一段')),
), array('doc_number' => '中传函〔2026〕98号'));
oa_lw_chk(strpos($html, '<p class="tco-doc-record-cc">抄送：') !== false, '抄送行带 .tco-doc-record-cc（两处分支同源）');

echo "\n== 3) 字重：由「公文版式」决定，不再写死 ==\n";
$GLOBALS['zw_lw_ov'] = array();
$css_default = zw_doc_render_style_block('');
// OA-2528（C1）后默认值改为「常规」：断言随之更新为 normal —— 判据仍是「默认值来自版式表」，
// 不是「永远是 bold」。要验可配性，看下面显式注入 bold 的那两条。
oa_lw_chk(strpos($css_default, '.tco-doc-h1{font-family:"ZW-DOC-H1",serif;font-weight:normal') !== false, '默认（C1 后 h1 字重 = 常规）输出 normal');
$GLOBALS['zw_lw_ov'] = array('fonts.h1.weight' => 'bold');
oa_lw_chk(strpos(zw_doc_render_style_block(''), '.tco-doc-h1{font-family:"ZW-DOC-H1",serif;font-weight:bold') !== false, '版式改成「加粗」后 h1 输出 bold（配置真的生效）');
$GLOBALS['zw_lw_ov'] = array('fonts.h1.weight' => 'normal', 'fonts.title.weight' => 'normal');
$css_normal = zw_doc_render_style_block('');
oa_lw_chk(strpos($css_normal, '.tco-doc-h1{font-family:"ZW-DOC-H1",serif;font-weight:normal') !== false, '版式改成「常规」后 h1 输出 normal（修复前写死 bold ⇒ 必红）');
oa_lw_chk(strpos($css_normal, 'font-weight:normal;text-align:center;text-indent:0;margin:') !== false, '标题字重同样跟随版式');
oa_lw_chk(zw_doc_render_font_weight('h1') === 'normal' && zw_doc_render_font_weight('body') === 'normal', '未知/缺省槽位回落 normal（不输出第三态）');
$GLOBALS['zw_lw_ov'] = array('fonts.h1.weight' => 'black');
oa_lw_chk(zw_doc_render_font_weight('h1') === 'normal', '非法字重值回落 normal（不把脏值写进 CSS）');

echo "\n== 4) 源码级守卫：不得再出现写死的字重 ==\n";
$src = (string) file_get_contents(ABSPATH . 'includes/doc/doc-render-html.php');
oa_lw_chk(strpos($src, '.tco-doc-h1{font-family:\' . zw_doc_render_font_family(\'h1\') . \';font-weight:bold;}') === false, 'h1 的 font-weight:bold 写死已移除');
oa_lw_chk(substr_count($src, "zw_doc_render_font_weight(") >= 4, '字重读数经唯一函数（title / h1 / mark + 定义处）');

echo "\n";
if (empty($GLOBALS['oa_lw_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_lw_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_lw_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_lw_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
