<?php
/**
 * OA-2528（C1）：一级标题字重默认改「常规」—— 黑体不再叠加加粗。
 *
 * 判据：
 *  1. 默认 `fonts.h1.weight = normal`（黑体本身即粗面，GB §7.3.3 只要求「黑体字」）；
 *  2. 渲染层 `.tco-doc-h1` 与版头标记 `.tco-doc-mark`（共用 h1 字重槽）随之变常规；
 *  3. **标题 `.tco-doc-title` 保持加粗**（小标宋口径另表，不在本单范围）；
 *  4. 版式页仍可一键改回「加粗」（改配置即生效，不改代码）。
 *
 * 运行：php tests/test-doc-h1-weight-default.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

$GLOBALS['zw_hw_ov'] = array();
function zw_doc_layout_get_overrides() {
    return $GLOBALS['zw_hw_ov'];
}
function zw_doc_layout_apply_overrides($spec) {
    // 支持任意层级键（本测试要注入的是 3 层：fonts.h1.weight）
    foreach ((array) $GLOBALS['zw_hw_ov'] as $path => $value) {
        $parts = explode('.', (string) $path);
        if (count($parts) < 2) {
            continue;
        }
        $ref = &$spec;
        $ok  = true;
        foreach ($parts as $i => $seg) {
            if ($i === count($parts) - 1) {
                break;
            }
            if (!is_array($ref) || !array_key_exists($seg, $ref) || !is_array($ref[$seg])) {
                $ok = false;
                break;
            }
            $ref = &$ref[$seg];
        }
        if (!$ok) {
            unset($ref);
            continue;
        }
        $leaf = end($parts);
        if (is_array($ref)) {
            $ref[$leaf] = $value;
        }
        unset($ref);
    }

    return $spec;
}

foreach (array(
    'esc_html' => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    'esc_attr' => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
) as $zw_hw_name => $zw_hw_fn) {
    if (!function_exists($zw_hw_name)) {
        $GLOBALS['zw_hw_stubs'][$zw_hw_name] = $zw_hw_fn;
        eval('function ' . $zw_hw_name . '($v) { return call_user_func($GLOBALS["zw_hw_stubs"]["' . $zw_hw_name . '"], $v); }');
    }
}
unset($zw_hw_name, $zw_hw_fn);

require_once ABSPATH . 'includes/doc/doc-layout-gb9704.php';
require_once ABSPATH . 'includes/doc/doc-body-model.php';
require_once ABSPATH . 'includes/doc/doc-render-html.php';

$GLOBALS['oa_hw_pass'] = 0;
$GLOBALS['oa_hw_fail'] = array();

function oa_hw_ok($msg) {
    $GLOBALS['oa_hw_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_hw_bad($msg) {
    $GLOBALS['oa_hw_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_hw_chk($cond, $msg) {
    if ($cond) {
        oa_hw_ok($msg);
    } else {
        oa_hw_bad($msg);
    }
}

echo "== 1) 默认值：h1 = 常规（黑体不加粗）==\n";
$GLOBALS['zw_hw_ov'] = array();
oa_hw_chk((string) zw_doc_gb9704_get('fonts.h1.weight', '') === 'normal', '版式表 fonts.h1.weight 默认 normal');
oa_hw_chk(zw_doc_render_font_weight('h1') === 'normal', '字重取值函数返回 normal');
$css = zw_doc_render_style_block('');
oa_hw_chk(strpos($css, '.tco-doc-h1{font-family:"ZW-DOC-H1",serif;font-weight:normal') !== false,
    '一级标题 CSS 为 font-weight:normal（不再双重加粗）');

echo "\n== 2) 影响面：版头标记随 h1 变常规；标题保持加粗 ==\n";
oa_hw_chk(strpos($css, '.tco-doc-mark{font-family:"ZW-DOC-H1",serif;font-size:16pt;line-height:28pt;font-weight:normal') !== false,
    '版头标记（份号/密级/紧急）随 h1 槽变常规（已在任务条目登记影响面）');
oa_hw_chk(strpos($css, '.tco-doc-title{') !== false && strpos($css, 'font-weight:bold;text-align:center') !== false,
    '标题仍加粗（title 字重口径不动）');
oa_hw_chk(zw_doc_render_font_weight('title') === 'bold', 'title 字重仍为 bold');

echo "\n== 3) 可配性：版式页仍能改回「加粗」==\n";
$GLOBALS['zw_hw_ov'] = array('fonts.h1.weight' => 'bold');
$css_bold = zw_doc_render_style_block('');
oa_hw_chk(strpos($css_bold, '.tco-doc-h1{font-family:"ZW-DOC-H1",serif;font-weight:bold') !== false,
    '把版式「一级标题字重」改成加粗后立刻生效（无需改代码）');
$GLOBALS['zw_hw_ov'] = array('fonts.h1.weight' => '正常');
oa_hw_chk(zw_doc_render_font_weight('h1') === 'normal', '非法字重值回落 normal（不写脏值进 CSS）');

echo "\n== 4) 纸面回归：正文字体与字号不受影响 ==\n";
$GLOBALS['zw_hw_ov'] = array();
$css2 = zw_doc_render_style_block('');
oa_hw_chk(strpos($css2, '.tco-doc-body{font-family:"ZW-DOC-BODY",serif;font-size:16pt') !== false,
    '正文仍是 3 号仿宋 16pt（未受影响）');
oa_hw_chk(strpos($css2, '.tco-doc-h2{font-family:"ZW-DOC-H2",serif;font-weight:normal') !== false,
    '二级标题（楷体）仍 normal（未受影响）');

echo "\n";
if (empty($GLOBALS['oa_hw_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_hw_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_hw_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_hw_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
