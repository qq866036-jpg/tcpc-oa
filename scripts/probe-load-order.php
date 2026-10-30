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
    foreach ($files as $file) {
        require $file;
    }

    $sentinel = function_exists('zw_policy_platform_clause_review_core');
    $overview = function_exists('zw_policy_platform_page_overview');
    $from     = defined('ZW_POLICY_ADMIN_LOADED_FROM') ? ZW_POLICY_ADMIN_LOADED_FROM : 'undefined';

    echo 'ORDER=' . $order . ' sentinel=' . ($sentinel ? 'yes' : 'no')
        . ' overview=' . ($overview ? 'yes' : 'no')
        . ' loaded_from=' . $from . "\n";

    if (!$sentinel || !$overview) {
        fwrite(STDERR, "✗ {$order}：管理面符号未声明（哨兵/入口缺失）\n");
        exit(1);
    }
    if ($order === 'plugin-first' && $from !== 'plugin') {
        fwrite(STDERR, "✗ plugin-first：主题未让位（loaded_from={$from}，应为 plugin）\n");
        exit(1);
    }
    if ($order === 'theme-first' && $from !== 'theme') {
        // theme-first 时主题先声明 ⇒ 主题侧必须判定为自己提供（loaded_from=theme）
        fwrite(STDERR, "✗ theme-first：让位结论不符（loaded_from={$from}，应为 theme）\n");
        exit(1);
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
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' --child ' . escapeshellarg($order)
        . ' --theme-file ' . escapeshellarg($theme_file);
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
