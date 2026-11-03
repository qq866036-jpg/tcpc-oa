<?php
/**
 * 迁移：建表 `zw_receipt_disposal`（收文档案处置：保管期限 / 清退 / 销毁）· OA-2483。
 *
 * 用法（在**仓库检出**里跑，生产用 wp eval-file）：
 *   wp eval-file scripts/migrate-receipt-disposal.php dry-run
 *   wp eval-file scripts/migrate-receipt-disposal.php execute
 *   wp eval-file scripts/migrate-receipt-disposal.php verify
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) { fwrite(STDERR, "必须通过 wp eval-file 运行\n"); exit(2); }

global $wpdb;
$table = $wpdb->prefix . 'zw_receipt_disposal';
$mode  = isset($args[0]) ? (string) $args[0] : 'dry-run';

$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
echo "表：{$table}　存在：" . ($exists ? '是' : '否') . "　模式：{$mode}\n";

if ($mode === 'verify') {
    if (!$exists) { echo "✗ 未迁移\n"; exit(1); }
    $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    $need = array('id','receipt_id','kind','years','until','state','approver','note','operator','created_at');
    $miss = array_diff($need, (array) $cols);
    echo $miss ? ('✗ 缺列：' . implode('、', $miss) . "\n") : "✓ 列齐全（" . count($cols) . " 列）\n";
    exit($miss ? 1 : 0);
}

// OA-2484：新建表必须**跟随主表排序规则**，否则 `s.id = d.receipt_id` 这类 JOIN 会报
// Illegal mix of collations（0.1.23 实测：处置表被建成了 utf8mb4_unicode_520_ci）。
$src_table   = $wpdb->prefix . 'zw_policy_source';
$src_coll    = $wpdb->get_var($wpdb->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $src_table));
$src_coll    = $src_coll ? (string) $src_coll : 'utf8mb4_general_ci';
$src_charset = strpos($src_coll, 'utf8mb4') === 0 ? 'utf8mb4' : 'utf8';
echo "主表排序规则：{$src_coll}\n";

if ($exists) {
    $cur = $wpdb->get_var($wpdb->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
    if ((string) $cur !== $src_coll) {
        $fix = "ALTER TABLE {$table} CONVERT TO CHARACTER SET {$src_charset} COLLATE {$src_coll}";
        if ($mode === 'dry-run') { echo "将执行（修排序规则）：\n{$fix}\n（dry-run，未写入）\n"; exit(0); }
        $wpdb->query($fix);
        $now = $wpdb->get_var($wpdb->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
        echo "✓ 已修排序规则：{$cur} → {$now}\n";
        exit((string) $now === $src_coll ? 0 : 1);
    }
    echo "已存在且排序规则一致（{$cur}），无需处理。\n";
    exit(0);
}

$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_id VARCHAR(64) NOT NULL,
  kind VARCHAR(16) NOT NULL DEFAULT 'retention',
  years SMALLINT NOT NULL DEFAULT 0,
  until DATE NULL,
  state VARCHAR(16) NOT NULL DEFAULT '',
  approver VARCHAR(64) NOT NULL DEFAULT '',
  note VARCHAR(255) NOT NULL DEFAULT '',
  operator VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY receipt_id (receipt_id),
  KEY until (until)
) DEFAULT CHARACTER SET {$src_charset} COLLATE {$src_coll};";

if ($mode === 'dry-run') {
    echo "将执行：\n{$sql}\n（dry-run，未写入）\n";
    exit(0);
}

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta($sql);
$after = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
echo $after ? "✓ 已建表 {$table}\n" : "✗ 建表失败\n";
exit($after ? 0 : 1);
