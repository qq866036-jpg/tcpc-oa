<?php
/**
 * OA-2517：「标题上/下间距」进「公文版式」页 —— 字段存在性 + 改设置后真的生效。
 *
 * 背景（用户 2026-11-18「再次检查…」审计 C1）：`title.gap_below_rule_lines` 与
 * `title.gap_below_title_lines` 在版式表里**一直存在且被渲染 CSS 与分页估算读取**，
 * 但版式页没有入口 ⇒ 想按国标「红线下空二行」只能改代码；且规范写 2 行、实现 1 行无人知晓原因。
 * 本单把它们做成可配（默认沿用 1 行），并由 TASK-3003 同步规范口径。
 *
 * 判据：① 两个字段在版式页字段表里；② 默认值仍是 1 行（观感零回归）；
 *       ③ 通过覆盖层把上间距设成 2 行后，标题 CSS 的上外边距从 28pt 变 56pt（说明不只是加了个输入框）。
 *
 * 运行：php tests/test-doc-layout-title-gap.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// —— 最小 WP 桩：版式覆盖层读写 option ——
$GLOBALS['zw_lg_options'] = array();
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['zw_lg_options']) ? $GLOBALS['zw_lg_options'][$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value) {
        $GLOBALS['zw_lg_options'][$name] = $value;

        return true;
    }
}
foreach (array(
    'esc_html'    => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    'esc_attr'    => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    'sanitize_key' => function ($v) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $v)); },
    'wp_json_encode' => function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); },
) as $zw_lg_name => $zw_lg_fn) {
    if (!function_exists($zw_lg_name)) {
        $GLOBALS['zw_lg_stubs'][$zw_lg_name] = $zw_lg_fn;
        eval('function ' . $zw_lg_name . '($v) { return call_user_func($GLOBALS["zw_lg_stubs"]["' . $zw_lg_name . '"], $v); }');
    }
}
unset($zw_lg_name, $zw_lg_fn);

require_once ABSPATH . 'includes/doc/doc-layout-gb9704.php';
require_once ABSPATH . 'includes/doc/doc-layout-designer.php';
require_once ABSPATH . 'includes/doc/doc-body-model.php';
require_once ABSPATH . 'includes/doc/doc-render-html.php';

$GLOBALS['oa_lg_pass'] = 0;
$GLOBALS['oa_lg_fail'] = array();

function oa_lg_ok($msg) {
    $GLOBALS['oa_lg_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_lg_bad($msg) {
    $GLOBALS['oa_lg_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_lg_chk($cond, $msg) {
    if ($cond) {
        oa_lg_ok($msg);
    } else {
        oa_lg_bad($msg);
    }
}

/**
 * 渲染一份最小公文（仅标题 + 一段正文），返回样式块 + 分页结果。
 *
 * @return array{css:string, pages:array}
 */
function oa_lg_render() {
    $body = array(
        'v'      => 1,
        'meta'   => array('title' => '关于测试标题间距的批复', 'sign_org' => '中国传统文化促进会', 'date' => '2026年11月18日'),
        'blocks' => array(array('t' => 'p', 'text' => '正文一段。')),
    );

    return array(
        'css'   => zw_doc_render_style_block(''),
        'pages' => zw_doc_render_paginate($body, array('doc_number' => '中传函〔2026〕98号')),
    );
}

echo "== 1) 版式页字段：两个间距都已进配置表 ==\n";
$paths = array();
foreach ((array) zw_doc_layout_fields() as $row) {
    if (isset($row['path'])) {
        $paths[(string) $row['path']] = $row;
    }
}
oa_lg_chk(isset($paths['title.gap_below_rule_lines']), '存在「标题上间距（红线→标题）」字段');
oa_lg_chk(isset($paths['title.gap_below_title_lines']), '存在「标题下间距（标题→正文）」字段');
foreach (array('title.gap_below_rule_lines', 'title.gap_below_title_lines') as $path) {
    $row = isset($paths[$path]) ? $paths[$path] : array();
    oa_lg_chk(($row['type'] ?? '') === 'number' && (int) ($row['min'] ?? -1) === 0 && (int) ($row['max'] ?? -1) === 4,
        $path . '：数值型、范围 0~4 行');
    oa_lg_chk(($row['unit'] ?? '') === '行', $path . '：单位为「行」（正文行）');
    oa_lg_chk(strpos((string) ($row['gb'] ?? ''), 'GB') !== false, $path . '：标注了国标出处');
}

echo "\n== 2) 默认值：仍是 1 行（观感零回归）==\n";
$GLOBALS['zw_lg_options'] = array();
oa_lg_chk((int) zw_doc_gb9704_get('title.gap_below_rule_lines', -1) === 1, '标题上间距默认 1 行');
oa_lg_chk((int) zw_doc_gb9704_get('title.gap_below_title_lines', -1) === 1, '标题下间距默认 1 行');
$rendered = oa_lg_render();
oa_lg_chk(strpos($rendered['css'], '.tco-doc-title{') !== false && strpos($rendered['css'], 'margin:28pt 0 28pt;') !== false,
    '默认渲染：标题 margin 为 28pt / 28pt（1 行 / 1 行）');

echo "\n== 3) 改设置后真的生效（上间距 2 行 ⇒ 56pt）==\n";
$GLOBALS['zw_lg_options'][zw_doc_layout_option_key()] = array(
    'title.gap_below_rule_lines'     => 2,
    'title.gap_below_title_lines'    => 2,
);
oa_lg_chk((int) zw_doc_gb9704_get('title.gap_below_rule_lines', -1) === 2, '覆盖层读到 2 行');
$rendered2 = oa_lg_render();
oa_lg_chk(strpos($rendered2['css'], 'margin:56pt 0 56pt;') !== false, '标题上下外边距随之变为 56pt（不只是加了个输入框）');
// 分页同步：同一份内容、只改间距 ⇒ 首页承载块数必须变少（OA-2516 的差分口径在本单仍成立）
$many = array();
for ($i = 0; $i < 40; $i++) {
    $many[] = array('t' => 'p', 'text' => '段' . $i);
}
$base = array('v' => 1, 'meta' => array('title' => '关于测试标题间距的批复', 'sign_org' => '中国传统文化促进会', 'date' => '2026年11月18日'), 'blocks' => $many);
$pages_big = zw_doc_render_paginate($base, array());
$GLOBALS['zw_lg_options'][zw_doc_layout_option_key()] = array('title.gap_below_rule_lines' => 0, 'title.gap_below_title_lines' => 0);
$pages_small = zw_doc_render_paginate($base, array());
oa_lg_chk(count($pages_small[0]) - count($pages_big[0]) === 4, '间距 2+2 与 0+0 的首页块数差 4（分页与版式同源）');

echo "\n== 4) 源码级守卫：说明里写清了「国标 2 行 / 协会默认 1 行」==\n";
$src = (string) file_get_contents(ABSPATH . 'includes/doc/doc-layout-designer.php');
oa_lg_chk(strpos($src, '国标为 2 行；协会口径默认 1 行') !== false, '字段说明写明国标 2 行与协会默认 1 行的差异（防重复报缺陷）');
oa_lg_chk(strpos($src, '仅**无主送机关**时生效') !== false, '下间距说明写明适用范围');

echo "\n";
if (empty($GLOBALS['oa_lg_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_lg_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_lg_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_lg_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
