<?php
/**
 * 装载顺序探针 —— OA-2452（2026-10-30 生产事故固化）。
 *
 * ## 事故（实测）
 *
 * 生产「启用插件」请求（`/wp-admin/plugins.php?action=activate&plugin=tcpc-oa/tcpc-oa.php`）整站 500：
 *
 *   PHP Fatal error: Cannot redeclare zw_policy_platform_clause_review_core()
 *     (previously declared in themes/tcpc/inc/admin/policy-platform-admin.php:303)
 *     in plugins/tcpc-oa/includes/policy/policy-platform-admin.php:289
 *
 * 根因是**装载顺序反转**：正常请求里插件先于主题（`wp-settings.php` 先 require 插件，后 require 主题
 * `functions.php`）；而 `activate_plugin()` 是在 wp-settings **之后**才 include 插件文件的 ——
 * 于是「主题先、插件后」，主题同名副本先声明，插件未加守卫的顶层声明随即 `Cannot redeclare`。
 *
 * ## 判据（本探针 = 唯一实现）
 *
 * 子进程隔离跑两条顺序，任一条 fatal 或让位结论不符即 rc=1：
 *   ① theme-first（激活请求顺序）：主题先声明 ⇒ 插件必须**按 declared 让位**，无 redeclare；
 *   ② plugin-first（正常请求顺序）：插件先声明 ⇒ 主题必须让位（`ZW_POLICY_ADMIN_LOADED_FROM=plugin`）。
 *
 * 用子进程是因为 `Cannot redeclare` 是 E_COMPILE_ERROR，同进程 try/catch 抓不到。
 *
 * ## 用法
 *
 *   php scripts/probe-load-order.php                          # 默认主题根：ZW_THEME_PATH 或 dev 主题仓
 *   php scripts/probe-load-order.php --theme <主题根目录>
 *   php scripts/probe-load-order.php --child theme-first      # 内部子进程入口
 *
 * 退出码：0 = 两条顺序都安全；1 = 有 fatal 或让位结论不符；2 = 用法/路径错误。
 *
 * @package tcpc-oa
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$plugin_file = dirname(__DIR__) . '/includes/policy/policy-platform-admin.php';

/**
 * 解析 --key value。
 *
 * @param string $name  参数名（不含 --）。
 * @param string $default 默认值。
 * @return string
 */
function zw_plo_arg($name, $default = '') {
    global $argv;
    $out = $default;
    foreach ((array) $argv as $i => $a) {
        if ($a === '--' . $name) {
            $out = isset($argv[$i + 1]) ? (string) $argv[$i + 1] : '';
        } elseif (strpos($a, '--' . $name . '=') === 0) {
            $out = substr($a, strlen($name) + 3);
        }
    }
    return $out;
}

/**
 * 子进程：按指定顺序装载主题与插件，然后打印让位结论。
 *
 * 只提供声明期需要的最小 WP stub（本文件不模拟 WP 行为）。
 *
 * @param string $theme_file  主题侧 policy-platform-admin.php。
 * @param string $plugin_file 插件侧同名文件。
 * @param string $order       `theme-first` 或 `plugin-first`。
 * @return void
 */
function zw_plo_child($theme_file, $plugin_file, $order) {
    // 主题形态由父进程静态判定（TASK-2481 后主题侧是 fail-closed shim：不再声明管理面、也不再定义
    // ZW_POLICY_ADMIN_LOADED_FROM）。形态不同 ⇒ 断言不同，不能一刀切。
    $theme_shim = (int) zw_plo_arg('theme-shim') === 1;
    define('ABSPATH', sys_get_temp_dir() . '/');
    $GLOBALS['zw_plo_hooks'] = array();
    function add_action($h, $c, $p = 10, $a = 1) {
        $GLOBALS['zw_plo_hooks'][] = $h;
        return true;
    }
    function add_filter($h, $c, $p = 10, $a = 1) {
        return add_action($h, $c, $p, $a);
    }
    function is_admin() {
        return true;
    }
    function get_option($k, $d = false) {
        return $d;
    }
    function wp_json_encode($v) {
        return json_encode($v);
    }
    function wp_generate_uuid4() {
        return 'probe-uuid';
    }
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public function __construct($code = '', $message = '') {
            }
        }
    }

    $files = $order === 'theme-first' ? array($theme_file, $plugin_file) : array($plugin_file, $theme_file);
    $theme_declared = false;
    foreach ($files as $idx => $file) {
        require $file;
        if ($idx === 0 && $order === 'theme-first') {
            // 主题侧形态两态（TASK-2481 前是回退实现，之后是 fail-closed shim）：
            // 这里记录「主题自己是否声明了管理面」，用于判定由谁提供能力。
            $theme_declared = function_exists('zw_policy_platform_page_overview');
        }
    }

    $sentinel = function_exists('zw_policy_platform_clause_review_core');
    $overview = function_exists('zw_policy_platform_page_overview');
    $from     = defined('ZW_POLICY_ADMIN_LOADED_FROM') ? ZW_POLICY_ADMIN_LOADED_FROM : 'undefined';

    echo 'ORDER=' . $order . ' sentinel=' . ($sentinel ? 'yes' : 'no')
        . ' overview=' . ($overview ? 'yes' : 'no')
        . ' theme_declared=' . ($theme_declared ? 'yes' : 'no')
        . ' loaded_from=' . $from . "\n";

    // ① 两条顺序都必须「有且只有一份」管理面：符号存在且无 redeclare（有 redeclare 会在 require 时直接 fatal）。
    if (!$sentinel || !$overview) {
        fwrite(STDERR, "✗ {$order}：管理面符号未声明（哨兵/入口缺失）\n");
        exit(1);
    }
    // ② plugin-first（正常请求）：必须由插件提供 ⇒ 主题不得抢先声明（若声明，说明主题回退实现回来了）。
    if ($order === 'plugin-first' && !$theme_shim && $from !== 'plugin') {
        fwrite(STDERR, "✗ plugin-first：主题未让位（loaded_from={$from}，应为 plugin；主题侧可能恢复了回退实现）\n");
        exit(1);
    }
    // ③ theme-first（激活请求）：两种主题形态都只要求「不崩 + 最终可用」——
    //    · 主题是回退实现（TASK-2481 前的生产形态）⇒ 主题提供，插件让位（loaded_from=theme）；
    //    · 主题是 fail-closed shim（TASK-2481 后的形态）⇒ 主题零声明，由插件补上（loaded_from 不再定义）。
    if ($order === 'theme-first') {
        if ($theme_declared && !$theme_shim && $from !== 'theme') {
            fwrite(STDERR, "✗ theme-first：主题已声明管理面但让位结论不符（loaded_from={$from}，应为 theme）\n");
            exit(1);
        }
        if ((!$theme_declared || $theme_shim) && $from !== 'undefined') {
            fwrite(STDERR, "✗ theme-first：主题为 shim（零声明）但 loaded_from={$from}（应为 undefined）\n");
            exit(1);
        }
    }
    exit(0);
}

// 子进程入口
if (zw_plo_arg('child') !== '') {
    $theme_file = zw_plo_arg('theme-file');
    if (!is_file($theme_file) || !is_file($plugin_file)) {
        fwrite(STDERR, "✗ 子进程缺少文件：theme={$theme_file} plugin={$plugin_file}\n");
        exit(2);
    }
    zw_plo_child($theme_file, $plugin_file, (string) zw_plo_arg('child'));
}

// 父进程：定位主题
$theme_root = zw_plo_arg('theme');
if ($theme_root === '') {
    $theme_root = getenv('ZW_THEME_PATH') ?: '';
}
if ($theme_root === '') {
    $theme_root = '/srv/git/worktrees/dev-tcpc/main';
}
$theme_file = rtrim($theme_root, '/') . '/inc/admin/policy-platform-admin.php';
if (!is_file($theme_file)) {
    fwrite(STDERR, "✗ 主题侧 policy-platform-admin.php 不存在：{$theme_file}\n");
    fwrite(STDERR, "  处置：--theme <主题根目录> 或设置 ZW_THEME_PATH\n");
    exit(2);
}
if (!is_file($plugin_file)) {
    fwrite(STDERR, "✗ 插件侧 policy-platform-admin.php 不存在：{$plugin_file}\n");
    exit(2);
}

echo "== 装载顺序探针（OA-2452）\n";
echo "   主题：{$theme_file}\n";
echo "   插件：{$plugin_file}\n";

$failures = 0;
foreach (array('theme-first', 'plugin-first') as $order) {
    $theme_src = (string) file_get_contents($theme_file);
    $theme_shim = strpos($theme_src, 'function zw_policy_platform_page_overview') === false ? 1 : 0;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' --child ' . escapeshellarg($order)
        . ' --theme-file ' . escapeshellarg($theme_file)
        . ' --theme-shim ' . $theme_shim;
    $output = array();
    $rc = 0;
    exec($cmd . ' 2>&1', $output, $rc);
    $text = implode("\n", $output);
    if ($rc === 0) {
        echo "  ✓ {$order}：{$text}\n";
    } else {
        $failures++;
        echo "  ✗ {$order}（rc={$rc}）：{$text}\n";
    }
}

if ($failures > 0) {
    echo "✗ 装载顺序探针失败：{$failures}/2 —— 插件顶层声明必须加 function_exists 守卫（声明层让位）\n";
    exit(1);
}
echo "✓ 装载顺序探针通过：两条顺序均无 Cannot redeclare，且让位结论一致\n";
exit(0);
