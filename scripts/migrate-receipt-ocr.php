<?php
/**
 * 收文管理 · OCR 数据列迁移（OA-2463）。
 *
 * 用法（**生产只读纪律**：先 dry-run，再 execute，最后 verify）：
 *
 *   wp eval-file scripts/migrate-receipt-ocr.php dry-run
 *   wp eval-file scripts/migrate-receipt-ocr.php execute
 *   wp eval-file scripts/migrate-receipt-ocr.php verify
 *
 * 行为：
 *   · 只 **ADD COLUMN**（幂等：已存在列跳过），不做 UPDATE / 删列 / 改类型；
 *   · 只增 `zw_policy_source` 的 5 个 OCR 专用列（`ocr_text` / `ocr_confidence` / `ocr_ms` /
 *     `ocr_at` / `ocr_message`）；`ocr_status` 与 `extraction_method` 是既有列，不改；
 *   · dry-run **零写入**（只打印将要执行的 SQL）；
 *   · 任何一步失败立即以非 0 退出并打印原因（不静默继续）。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "✗ 必须在 WordPress 环境内运行：wp eval-file scripts/migrate-receipt-ocr.php <dry-run|execute|verify>\n");
    exit(1);
}

if (!function_exists('zw_ocr_new_columns') || !function_exists('zw_ocr_source_table')) {
    fwrite(STDERR, "✗ 未加载收文 OCR 模块（确认 tcpc-oa 已启用、收文模块开关已开、includes/ocr 已部署）。\n");
    exit(1);
}

global $wpdb;

$mode  = isset($args[0]) ? (string) $args[0] : 'dry-run';
$valid = array('dry-run', 'execute', 'verify');
if (!in_array($mode, $valid, true)) {
    fwrite(STDERR, '✗ 未知模式：' . $mode . '（可用：' . implode(' / ', $valid) . "）\n");
    exit(2);
}

$table = (string) zw_ocr_source_table();
if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
    fwrite(STDERR, "✗ 政策源表名非法：" . $table . "\n");
    exit(1);
}

$missing = zw_ocr_missing_columns();
$new     = zw_ocr_new_columns();

echo "== 收文 OCR 迁移（{$mode}）\n";
echo "   表：{$table}\n";

if (!$missing) {
    echo "✓ 全部 OCR 列已就绪，无需迁移。\n";
    exit(0);
}

$todo = array();
foreach ($missing as $col) {
    if (isset($new[$col])) {
        $todo[$col] = $new[$col];
    }
}

if ($todo === array()) {
    // 缺的是既有列（ocr_status / extraction_method）⇒ 说明政策源表本身不是本模块预期的结构。
    echo "✗ 缺少**既有列**：" . implode('、', $missing) . "\n";
    echo "  这不是本脚本能补的（本脚本只 ADD 本任务新增列）；请先核对 policy_source 表结构与迁移前置。\n";
    exit(1);
}

echo "   待新增列：" . implode('、', array_keys($todo)) . "\n";
if ($missing !== array_keys($todo)) {
    echo "   注意：另有既有列缺失，需人工核对：" . implode('、', array_diff($missing, array_keys($todo))) . "\n";
}

if ($mode === 'dry-run') {
    foreach ($todo as $col => $def) {
        echo "   [dry-run] ALTER TABLE {$table} ADD COLUMN {$col} {$def};\n";
    }
    echo "✓ dry-run 完成：未执行任何写入。\n";
    exit(0);
}

if ($mode === 'verify') {
    echo "✗ verify：仍缺列 " . implode('、', $missing) . "（先跑 execute）。\n";
    exit(1);
}

$failed = 0;
foreach ($todo as $col => $def) {
    $sql = "ALTER TABLE {$table} ADD COLUMN {$col} {$def}";
    $ok  = $wpdb->query($sql);
    if ($ok === false) {
        $failed++;
        echo "✗ 失败：{$col} —— " . (string) $wpdb->last_error . "\n";
    } else {
        echo "✓ 已新增：{$col}\n";
    }
}

$after = zw_ocr_missing_columns(true);
if ($failed > 0 || $after !== array()) {
    echo "✗ 迁移未完成：仍缺列 " . implode('、', $after) . "\n";
    exit(1);
}

echo "✓ execute 完成：OCR 列全部就绪。\n";
exit(0);
