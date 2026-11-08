<?php
/**
 * 发文管理后台「渲染冒烟」—— TASK-2444（W3 发文启用轮前置）。
 *
 * ## 为什么单独一个探针（而不是复用 org 那个）
 *
 * 发文共 **9 个后台页**（slug → 回调来自 `doc-bootstrap.php` 的 add_*_page 实参，逐个枚举，
 * 不是猜的）：
 *
 *   zw-doc            → zw_doc_page_list
 *   zw-doc-create     → zw_doc_page_create
 *   zw-doc-ledger     → zw_doc_page_ledger
 *   tco-doc-numbers    → zw_doc_page_numbers
 *   zw-doc-flow       → zw_doc_page_flow
 *   zw-doc-dict       → zw_doc_page_dict
 *   zw-doc-layout     → zw_doc_layout_render_page
 *   zw-doc-assets     → zw_doc_page_assets
 *   zw-doc-legal      → zw_doc_page_legal
 *
 * ## 判据（每页四条，缺一即失败）
 *
 *   ① 无 PHP Warning / Notice / Deprecated（set_error_handler 捕获）
 *   ② 输出无 `WordPress database error`
 *   ③ 有 `wrap` 骨架类（`class="wrap` 后接引号或空格——发文页会带附加类，
 *      精确匹配 `class="wrap"` 会假红，W2 已踩过）
 *   ④ 输出长度 ≥ 下限（默认 800，可用 `ZW_DOC_RENDER_MIN_LEN` 覆盖）
 *
 * ## 用法（必须跑在 WP 内）
 *
 *   wp eval-file scripts/oa-doc-render-smoke.php --allow-root --path=/www/wwwroot/tcpc.org.cn
 *
 * ## 退出码
 *
 *   `DOC_RENDER=ok pages=N`          rc=0
 *   `DOC_RENDER=fail …`              rc=1
 *   `DOC_RENDER=unavailable …`       rc=2（模块未启用/未部署；**不回填为通过**）
 *
 * @package tcpc
 */

if (PHP_SAPI !== 'cli') {
    echo "仅 CLI 可运行\n";
    exit(2);
}

if (!function_exists('current_user_can')) {
    echo "DOC_RENDER=unavailable 必须在 WP 内运行（wp eval-file），当前不是 WP 环境\n";
    exit(2);
}

/**
 * 待探页面：slug => 回调函数名。
 *
 * @return array<string,string>
 */
function zw_oa_doc_render_pages() {
    return array(
        'zw-doc'         => 'zw_doc_page_list',
        'zw-doc-create'  => 'zw_doc_page_create',
        'zw-doc-ledger'  => 'zw_doc_page_ledger',
        'tco-doc-numbers' => 'zw_doc_page_numbers',
        'zw-doc-flow'    => 'zw_doc_page_flow',
        'zw-doc-dict'    => 'zw_doc_page_dict',
        'zw-doc-layout'  => 'zw_doc_layout_render_page',
        'zw-doc-assets'  => 'zw_doc_page_assets',
        'zw-doc-legal'   => 'zw_doc_page_legal',
    );
}

$zw_doc_render_min = (int) (getenv('ZW_DOC_RENDER_MIN_LEN') ?: 800);
$zw_doc_fail = array();
$zw_doc_ok = 0;
$zw_doc_missing = array();

$zw_doc_backup = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
if (function_exists('wp_set_current_user')) {
    wp_set_current_user(1);
}

foreach (zw_oa_doc_render_pages() as $slug => $cb) {
    if (!function_exists($cb)) {
        $zw_doc_missing[] = "{$slug}（{$cb}）";
        continue;
    }

    $warnings = array();
    $prev = set_error_handler(function ($no, $str, $file, $line) use (&$warnings) {
        $warnings[] = $str . ' @ ' . basename((string) $file) . ':' . $line;
        return true;
    });

    $_GET['page'] = $slug;
    ob_start();
    try {
        call_user_func($cb);
    } catch (Throwable $e) {
        ob_end_clean();
        restore_error_handler();
        $zw_doc_fail[] = "{$slug}：抛出异常 " . $e->getMessage();
        continue;
    }
    $html = (string) ob_get_clean();
    restore_error_handler();
    if ($prev !== null) {
        set_error_handler($prev);
        restore_error_handler();
    }

    $why = array();
    if (!empty($warnings)) {
        $why[] = 'PHP 告警：' . implode(' / ', array_slice($warnings, 0, 3));
    }
    if (stripos($html, 'WordPress database error') !== false) {
        $why[] = '出现 WordPress database error';
    }
    if (!preg_match('/class="wrap(?:"|\s)/', $html)) {
        $why[] = '缺 wrap 骨架类';
    }
    if (strlen($html) < $zw_doc_render_min) {
        $why[] = '输出过短（' . strlen($html) . ' < ' . $zw_doc_render_min . '）';
    }
    if ($why) {
        $zw_doc_fail[] = "{$slug}：" . implode('；', $why);
    } else {
        $zw_doc_ok++;
    }
}

if ($zw_doc_backup !== null && function_exists('wp_set_current_user') && isset($zw_doc_backup->ID)) {
    wp_set_current_user((int) $zw_doc_backup->ID);
}

$total = count(zw_oa_doc_render_pages());

if ($zw_doc_missing) {
    echo 'DOC_RENDER=unavailable 缺回调 ' . count($zw_doc_missing) . '/' . $total . '：'
        . implode('、', $zw_doc_missing) . "\n";
    echo "（发文模块未启用或未部署 ⇒ 本探针不可判定，**不回填为通过**）\n";
    exit(2);
}

if ($zw_doc_fail) {
    echo 'DOC_RENDER=fail ' . count($zw_doc_fail) . '/' . $total . "：\n";
    foreach ($zw_doc_fail as $line) {
        echo '  ✗ ' . $line . "\n";
    }
    exit(1);
}

echo 'DOC_RENDER=ok pages=' . $zw_doc_ok . "\n";
exit(0);
