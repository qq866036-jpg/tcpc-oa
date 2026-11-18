<?php
/**
 * OA-2518：详情页预览必须与打印**同源同脚本**，且后台页误打印有防护。
 *
 * 判据（对应诊断里的三条呈现层缺陷）：
 *  1. 旧 `zw_doc_render_preview_html()`（62% 固定单页盒子）**已删除**，且详情页不再引用；
 *     ⇒ `transform:scale()` 不改变布局高度，盒子只等于一页 ⇒ 多页必然溢出（这就是"预览乱"）。
 *  2. 详情页预览走 `?embed=1` 的**同一个打印端点**（同一 nonce、同一 `zw_doc_build_render_args()`
 *     + 同一 `zw_doc_render_standalone_html()`），并在服务端注入**同一份** `zw_doc_preview_fit_html()`
 *     缩放脚本（按宽等比 + 多页纵向连续 + 页间留缝）。
 *  3. 后台详情页有 `@media print` 防护：直接 Ctrl+P 不会打印后台卡片，只留「请用打印按钮（A4 1:1）」。
 *
 * 运行：php tests/test-doc-preview-print-parity.php
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
    'esc_url'        => function ($v) { return (string) $v; },
    'admin_url'      => function ($path) { return 'https://example.test/wp-admin/' . ltrim((string) $path, '/'); },
    'wp_json_encode' => function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); },
) as $zw_pp_name => $zw_pp_fn) {
    if (!function_exists($zw_pp_name)) {
        $GLOBALS['zw_pp_stubs'][$zw_pp_name] = $zw_pp_fn;
        eval('function ' . $zw_pp_name . '($a = null, $b = null) { return call_user_func($GLOBALS["zw_pp_stubs"]["' . $zw_pp_name . '"], $a, $b); }');
    }
}
// add_query_arg / wp_nonce_url 只需要能拼出可断言的 URL。
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url = '') {
        $sep = strpos((string) $url, '?') === false ? '?' : '&';

        return (string) $url . $sep . http_build_query((array) $args);
    }
}
if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url($url, $action = '') {
        return add_query_arg(array('_wpnonce' => 'test'), $url);
    }
}
unset($zw_pp_name, $zw_pp_fn);

require_once ABSPATH . 'includes/doc/doc-layout-gb9704.php';
require_once ABSPATH . 'includes/doc/doc-body-model.php';
require_once ABSPATH . 'includes/doc/doc-render-html.php';
require_once ABSPATH . 'includes/doc/doc-composer.php';

$GLOBALS['oa_pp_pass'] = 0;
$GLOBALS['oa_pp_fail'] = array();

function oa_pp_ok($msg) {
    $GLOBALS['oa_pp_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_pp_bad($msg) {
    $GLOBALS['oa_pp_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_pp_chk($cond, $msg) {
    if ($cond) {
        oa_pp_ok($msg);
    } else {
        oa_pp_bad($msg);
    }
}

$src_render = (string) file_get_contents(ABSPATH . 'includes/doc/doc-render-html.php');
$src_list   = (string) file_get_contents(ABSPATH . 'includes/doc/doc-admin-list.php');

echo "== 1) 旧 62% 单页预览已彻底退场 ==\n";
oa_pp_chk(!function_exists('zw_doc_render_preview_html'), 'zw_doc_render_preview_html() 已删除');
oa_pp_chk(strpos($src_list, 'zw_doc_render_preview_html') === false, '详情页不再引用旧预览函数');
oa_pp_chk(strpos($src_render, 'tco-doc-preview-box') === false, '不再生成固定单页高盒子（多页溢出的根因）');
oa_pp_chk(strpos($src_render, 'transform:scale(62%)') === false && strpos($src_render, "'tco-doc-scaled'") === false, '不再用 62% transform 包裹整篇');

echo "\n== 2) 预览 = 同一个打印端点 + 同一 fit 脚本 ==\n";
oa_pp_chk(strpos($src_list, 'zw_doc_embed_url($doc[\'id\'])') !== false, '详情页 iframe 指向 embed 预览 URL');
oa_pp_chk(strpos($src_list, '<iframe') !== false && strpos($src_list, 'tco-doc-prev-frame') !== false, '预览用 iframe 承载（与后台页样式隔离）');
$embed_url = zw_doc_embed_url(123);
oa_pp_chk(strpos($embed_url, 'action=zw_doc_print') !== false && strpos($embed_url, 'embed=1') !== false, 'embed URL 指向既有打印端点（不新开鉴权通路）');
oa_pp_chk(strpos($embed_url, '_wpnonce=test') !== false, '沿用打印端点同一 nonce');
oa_pp_chk(strpos($src_render, "'show_toolbar'   => !\$embed") !== false, 'embed 模式下不出工具栏');
oa_pp_chk(strpos($src_render, "'auto_print'     => !\$embed") !== false, 'embed 模式下不自动弹打印框');
oa_pp_chk(strpos($src_render, 'zw_doc_preview_fit_html($zw_print_html)') !== false, 'embed 输出前注入 fit 脚本（与撰写页同一份）');

$sample = '<!doctype html><html><body><div class="tco-doc-sheet">P1</div></body></html>';
$fitted = function_exists('zw_doc_preview_fit_html') ? zw_doc_preview_fit_html($sample) : '';
oa_pp_chk(strpos($fitted, 'zw-pdf-view') !== false, 'fit 脚本确实注入（含 PDF 阅读器式容器 zw-pdf-view）');
oa_pp_chk(strpos($fitted, '.zw-pdf-page') !== false, 'fit 脚本含逐页缩放（`.zw-pdf-page`）');
oa_pp_chk(strpos($fitted, '</body>') !== false && strpos($fitted, 'id="zw-pdf-view"') < strpos($fitted, '</body>'), 'fit 脚本注入在 </body> 之前（不破坏文档结构）');

echo "\n== 3) 打印与预览的纸面参数同源 ==\n";
oa_pp_chk(substr_count($src_render, 'zw_doc_build_render_args($doc') === 2, '打印与 embed 两处都经 `zw_doc_build_render_args($doc)`（唯一构造器）');
oa_pp_chk(strpos($src_list, 'zw_doc_render_document_html(') === false, '详情页不再自行渲染纸面（只交给打印端点）');
oa_pp_chk(strpos($src_list, 'zw_doc_render_paginate(') !== false, '详情页用同一分页估算给出「共 N 页」');
oa_pp_chk(strpos($src_list, 'A4 1:1') !== false && strpos($src_list, '共 ') !== false, '预览标题写明「A4 1:1 · 共 N 页」');

echo "\n== 4) 后台页误打印防护 ==\n";
oa_pp_chk(strpos($src_list, '.tco-doc-print-guard{display:none}') !== false, '防护提示块默认隐藏');
oa_pp_chk(strpos($src_list, '@media print{') !== false && strpos($src_list, '.tco-doc-detail > *{display:none') !== false, '@media print 隐藏全部后台内容');
oa_pp_chk(strpos($src_list, '不要直接对本后台页按 Ctrl+P') !== false, '预览区就地提示「不要 Ctrl+P」');
oa_pp_chk(strpos($src_list, 'tco-doc-print-guard" role="note"') !== false, '打印时改为输出一句可读提示（不是空白页）');

echo "\n";
if (empty($GLOBALS['oa_pp_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_pp_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_pp_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_pp_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
