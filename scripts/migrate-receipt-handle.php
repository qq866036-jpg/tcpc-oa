<?php
/**
 * 收文管理 · 办理后半段迁移（OA-2464）。
 *
 * 用法（**先 dry-run，再 execute，最后 verify**）：
 *
 *   wp eval-file scripts/migrate-receipt-handle.php dry-run
 *   wp eval-file scripts/migrate-receipt-handle.php execute
 *   wp eval-file scripts/migrate-receipt-handle.php verify
 *
 * 做什么（全部幂等，**不做** DROP / 改类型 / 改数据）：
 *   ① 建两张新表：`{prefix}zw_receipt_handle_log`（拟办/批示/催办，append-only）、
 *      `{prefix}zw_receipt_circulation`（传阅/签阅，`(receipt,target)` 唯一）；
 *   ② 给政策源表加 3 列：`page_count` / `archive_box_no` / `archive_year`（只 ADD COLUMN）。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "✗ 必须在 WordPress 环境内运行：wp eval-file scripts/migrate-receipt-handle.php <dry-run|execute|verify>\n");
    exit(1);
}

if (!function_exists('zw_receipt_handle_tables') || !function_exists('zw_receipt_handle_new_columns')) {
    fwrite(STDERR, "✗ 未加载收文办理模块（确认 tcpc-oa 已启用、收文模块开关已开、includes/receipt/receipt-handle.php 已部署）。\n");
    exit(1);
}

global $wpdb;

$mode  = isset($args[0]) ? (string) $args[0] : 'dry-run';
$valid = array('dry-run', 'execute', 'verify');
if (!in_array($mode, $valid, true)) {
    fwrite(STDERR, '✗ 未知模式：' . $mode . '（可用：' . implode(' / ', $valid) . "）\n");
    exit(2);
}

$tables = zw_receipt_handle_tables();
foreach ($tables as $name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $name)) {
        fwrite(STDERR, "✗ 表名非法：{$name}\n");
        exit(1);
    }
}

$source = function_exists('zw_receipt_ledger_source_table') ? (string) zw_receipt_ledger_source_table() : '';
if ($source === '' || !preg_match('/^[A-Za-z0-9_]+$/', $source)) {
    fwrite(STDERR, "✗ 政策源表名非法：{$source}\n");
    exit(1);
}

$charset = $wpdb->get_charset_collate();

$sql = array();
$sql[] = "CREATE TABLE {$tables['log']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_id VARCHAR(64) NOT NULL,
  action VARCHAR(16) NOT NULL,
  author_login VARCHAR(64) NOT NULL DEFAULT '',
  author_name VARCHAR(64) NOT NULL DEFAULT '',
  dept_key VARCHAR(64) NOT NULL DEFAULT '',
  content TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY receipt_action (receipt_id, action),
  KEY action_created (action, created_at)
) {$charset};";
$sql[] = "CREATE TABLE {$tables['circ']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_id VARCHAR(64) NOT NULL,
  target_type VARCHAR(8) NOT NULL,
  target_id VARCHAR(64) NOT NULL,
  read_at DATETIME NULL,
  note VARCHAR(255) NULL,
  created_by VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY receipt_target (receipt_id, target_type, target_id),
  KEY receipt_created (receipt_id, created_at)
) {$charset};";

$columns = array();
foreach (zw_receipt_handle_new_columns() as $col => $def) {
    $columns[$col] = "ALTER TABLE {$source} ADD COLUMN {$col} {$def}";
}

$missing = zw_receipt_handle_missing();
echo "== 收文办理后半段迁移（{$mode}）\n";
echo "   新表：" . implode(' / ', array_values($tables)) . "\n";
echo "   缺表：" . ($missing['tables'] ? implode('、', $missing['tables']) : '无') . "\n";
echo "   缺列：" . ($missing['columns'] ? implode('、', $missing['columns']) : '无') . "\n";

if ($mode === 'verify') {
    if ($missing['tables'] === array() && $missing['columns'] === array()) {
        echo "✓ verify：表与列全部就绪。\n";
        exit(0);
    }
    echo "✗ verify：仍未就绪（先跑 execute）。\n";
    exit(1);
}

if ($mode === 'dry-run') {
    foreach ($sql as $one) {
        if ($missing['tables'] !== array()) {
            echo "   [dry-run] " . preg_replace('/\s+/', ' ', trim($one)) . "\n";
        }
    }
    foreach ($missing['columns'] as $col) {
        if (isset($columns[$col])) {
            echo "   [dry-run] {$columns[$col]};\n";
        }
    }
    echo "✓ dry-run 完成：未执行任何写入。\n";
    exit(0);
}

// execute
if (!function_exists('dbDelta')) {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
}

$failed = 0;
if ($missing['tables'] !== array()) {
    $result = dbDelta($sql);
    foreach ($result as $line) {
        echo "   dbDelta：{$line}\n";
    }
}
foreach ($missing['columns'] as $col) {
    if (!isset($columns[$col])) {
        continue;
    }
    if ($wpdb->query($columns[$col]) === false) {
        $failed++;
        echo "✗ 失败：{$col} —— " . (string) $wpdb->last_error . "\n";
    } else {
        echo "✓ 已新增列：{$col}\n";
    }
}

$after = zw_receipt_handle_missing(true);
if ($failed > 0 || $after['tables'] !== array() || $after['columns'] !== array()) {
    echo "✗ 迁移未完成：缺表 " . implode('、', $after['tables']) . "；缺列 " . implode('、', $after['columns']) . "\n";
    exit(1);
}
echo "✓ execute 完成：两张表 + 3 列全部就绪。\n";
exit(0);
