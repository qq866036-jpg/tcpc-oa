<?php
/**
 * OA-2521：配置漂移门禁的**判别力**回归 + 两条死配置接线后的行为断言。
 *
 * 三部分：
 *  1. 门禁在本仓**通过**（`CONFIG_DRIFT_OK`）；
 *  2. **反向控制**：用 `--root` 指向临时夹具仓（故意写错字典 reader / 塞一个无读取的版式 path）
 *     ⇒ 门禁必须判红并指出具体项（证明它不是"跑通即绿"）；
 *  3. 接线后的行为：`sign.date_no_leading_zero` 关掉后月/日补零；`footer.default_mode` 作为页码默认模式。
 *
 * 运行：php tests/test-config-drift-gate.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

$GLOBALS['oa_cd_pass'] = 0;
$GLOBALS['oa_cd_fail'] = array();

function oa_cd_ok($msg) {
    $GLOBALS['oa_cd_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_cd_bad($msg) {
    $GLOBALS['oa_cd_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_cd_chk($cond, $msg) {
    if ($cond) {
        oa_cd_ok($msg);
    } else {
        oa_cd_bad($msg);
    }
}

/**
 * 跑门禁，返回 [rc, 输出]。
 *
 * @param string $root 仓库根（空 = 本仓）。
 * @return array{0:int,1:string}
 */
function oa_cd_run_gate($root = '') {
    $cmd = 'python3 ' . escapeshellarg(ABSPATH . 'scripts/config-drift-gate.py');
    if ($root !== '') {
        $cmd .= ' --root ' . escapeshellarg($root);
    }
    $out = array();
    $rc  = 0;
    exec($cmd . ' 2>&1', $out, $rc);

    return array($rc, implode("\n", $out));
}

echo "== 1) 本仓门禁通过 ==\n";
list($rc_ok, $out_ok) = oa_cd_run_gate();
oa_cd_chk($rc_ok === 0, 'config-drift-gate 本仓 rc=0');
oa_cd_chk(strpos($out_ok, 'CONFIG_DRIFT_OK') !== false, '输出 CONFIG_DRIFT_OK');
oa_cd_chk(strpos($out_ok, '数据字典行：48') !== false, '字典行数可读（48 行）');
oa_cd_chk(strpos($out_ok, '生效豁免：0') !== false, '当前 0 条豁免（不靠豁免过线）');

echo "\n== 2) 反向控制：夹具仓必须被判红 ==\n";
$fixture = sys_get_temp_dir() . '/zw-oa-config-drift-' . getmypid();
$inc_doc = $fixture . '/includes/doc';
@mkdir($inc_doc, 0777, true);
@mkdir($fixture . '/scripts', 0777, true);
// 夹具 1：字典 reader 指向不存在的函数（缩进对齐真实字典：行首 8 空格 + `array(`）
file_put_contents($inc_doc . '/doc-admin-dict.php', <<<'PHP'
<?php
$rows = array(
        array(
                'field' => '夹具字段', 'control' => '`input[type=text]`（`name=zwc_fixture`）',
                'reader' => '`zw_fixture_reader_absent()`',
        ),
);
PHP
);
// 夹具 2：控件名在源码里不存在
file_put_contents($inc_doc . '/doc-composer.php', "<?php\n// 故意不提供 name=\"zwc_fixture\" 控件\n");
// 夹具 3：版式页登记了一个没人读的 path
file_put_contents($inc_doc . '/doc-layout-designer.php', <<<'PHP'
<?php
$fields = array(
    array('path' => 'fixture.dead_key', 'label' => '死配置'),
);
PHP
);
list($rc_bad, $out_bad) = oa_cd_run_gate($fixture);
oa_cd_chk($rc_bad === 1, '夹具仓 rc=1（判红）');
oa_cd_chk(strpos($out_bad, 'zw_fixture_reader_absent') !== false, '能指出失效 reader 的函数名');
oa_cd_chk(strpos($out_bad, 'zwc_fixture') !== false, '能指出声明了但源码不存在的控件');
oa_cd_chk(strpos($out_bad, 'fixture.dead_key') !== false, '能指出「版式页有入口但没人读」的死配置');
// 夹具清理（只删自己建的临时目录）
foreach (array($inc_doc . '/doc-admin-dict.php', $inc_doc . '/doc-composer.php', $inc_doc . '/doc-layout-designer.php') as $f) {
    @unlink($f);
}
@rmdir($inc_doc);
@rmdir($fixture . '/includes');
@rmdir($fixture . '/scripts');
@rmdir($fixture);

echo "\n== 3) 死配置接线后真的生效 ==\n";
// 3a `sign.date_no_leading_zero`：关掉 ⇒ 补零
$GLOBALS['zw_cd_ov'] = array();
function zw_doc_layout_get_overrides() {
    return $GLOBALS['zw_cd_ov'];
}
function zw_doc_layout_apply_overrides($spec) {
    foreach ((array) $GLOBALS['zw_cd_ov'] as $path => $value) {
        $parts = explode('.', (string) $path);
        if (count($parts) < 2 || !isset($spec[$parts[0]]) || !is_array($spec[$parts[0]])) {
            continue;
        }
        $spec[$parts[0]][$parts[1]] = $value;
    }

    return $spec;
}
foreach (array(
    'esc_html' => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    'esc_attr' => function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); },
    // doc-helpers.php 顶层挂 hook ⇒ CLI 下需要最小桩（测试只走纯函数）
    'add_action' => function ($a = '', $b = '', $c = 10, $d = 1) { return true; },
    'add_filter' => function ($a = '', $b = '', $c = 10, $d = 1) { return true; },
    'do_action' => function ($a = '') { return null; },
    'apply_filters' => function ($a = '', $b = null) { return $b; },
) as $zw_cd_name => $zw_cd_fn) {
    if (!function_exists($zw_cd_name)) {
        $GLOBALS['zw_cd_stubs'][$zw_cd_name] = $zw_cd_fn;
        eval('function ' . $zw_cd_name . '($v) { return call_user_func($GLOBALS["zw_cd_stubs"]["' . $zw_cd_name . '"], $v); }');
    }
}
unset($zw_cd_name, $zw_cd_fn);
require_once ABSPATH . 'includes/doc/doc-layout-gb9704.php';
require_once ABSPATH . 'includes/doc/doc-body-model.php';
require_once ABSPATH . 'includes/doc/doc-helpers.php';
require_once ABSPATH . 'includes/doc/doc-render-html.php';

$GLOBALS['zw_cd_ov'] = array();
oa_cd_chk(zw_doc_iso_to_cn_date('2026-10-04') === '2026年10月4日', '默认（不补零）→ 2026年10月4日');
$GLOBALS['zw_cd_ov'] = array('sign.date_no_leading_zero' => false);
oa_cd_chk(zw_doc_iso_to_cn_date('2026-10-04') === '2026年10月04日', '版式关掉「月日不补零」→ 2026年10月04日（接线生效）');

// 3b `footer.default_mode`：作为未登记时的页码模式
$body = array(
    'v'      => 1,
    'meta'   => array('title' => '关于页码默认模式的批复', 'sign_org' => '中国传统文化促进会', 'date' => '2026年11月18日'),
    'blocks' => array(array('t' => 'p', 'text' => '正文')),
);
// 页码只在**多页**时输出（单页自动不编页码），故这里给足正文行数
$many = array();
for ($i = 0; $i < 60; $i++) {
    $many[] = array('t' => 'p', 'text' => '第' . $i . '段');
}
$body_multi = $body;
$body_multi['blocks'] = $many;
$GLOBALS['zw_cd_ov'] = array('footer.default_mode' => 'center');
$html_center = zw_doc_render_document_html($body_multi, array('doc_number' => '中传函〔2026〕98号'));
oa_cd_chk(strpos($html_center, 'tco-doc-pageno is-center') !== false, '版式默认=居中 ⇒ 未登记 page_mode 时页码居中（接线生效）');
$GLOBALS['zw_cd_ov'] = array('footer.default_mode' => '非法值');
$html_bad = zw_doc_render_document_html($body_multi, array('doc_number' => '中传函〔2026〕98号'));
oa_cd_chk(strpos($html_bad, 'tco-doc-pageno') === false || strpos($html_bad, 'is-center') === false, '非法默认值回落标准（不把脏值写进纸面）');

echo "\n";
if (empty($GLOBALS['oa_cd_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_cd_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_cd_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_cd_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
