<?php
/**
 * 收文来文单位（issuer）审计 —— **只读**（OA-2479）。
 *
 * ## 为什么需要
 *
 * 生产实测：`zw_policy_source.issuer` 同库并存三种形态 ——
 *   key（`cssw`）、中文名（`民政部`）、中文名带后缀（`中央社会工作部办公厅`）。
 * 仓里已有 canonical 反查 `zw_receipt_org_key_from_issuer()`（TASK-1297），
 * 但缺一个能**一眼看到覆盖情况**的工具：哪些来文单位还没进字典、各有多少件。
 *
 * 本脚本只读：SELECT distinct issuer + count，按 canonical 反查给出 key/未命中，
 * 不写任何表、不改任何 option。
 *
 * ## 用法
 *
 *   wp eval-file scripts/receipt-issuer-audit.php            # 表格（CLI）
 *   wp eval-file scripts/receipt-issuer-audit.php --csv      # CSV（便于贴进表格）
 *
 * 退出码：0 = 全部命中；1 = 存在未命中（提示去补字典）。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "必须通过 wp eval-file 运行（需要 WP 环境）\n");
    exit(2);
}

if (!function_exists('zw_receipt_org_key_from_issuer')) {
    fwrite(STDERR, "缺少 zw_receipt_org_key_from_issuer()：收文组织模块未加载。\n");
    exit(2);
}

global $wpdb;
$table = function_exists('zw_receipt_ledger_source_table') ? zw_receipt_ledger_source_table() : '';
if ($table === '') {
    fwrite(STDERR, "找不到收文来源表。\n");
    exit(2);
}

$rows = $wpdb->get_results(
    "SELECT issuer, COUNT(*) cnt FROM {$table} WHERE origin = 'incoming' GROUP BY issuer ORDER BY cnt DESC",
    ARRAY_A
);
$rows    = is_array($rows) ? $rows : array();
$labels  = function_exists('zw_receipt_org_options') ? (array) zw_receipt_org_options() : array();
$as_csv  = in_array('--csv', isset($argv) ? (array) $argv : array(), true);
$missing = 0;

if ($as_csv) {
    echo "issuer,件数,解析出的部委key,中文名\n";
} else {
    printf("%-28s %6s  %-14s %s\n", 'issuer（原值）', '件数', '解析 key', '中文名');
    printf("%s\n", str_repeat('-', 78));
}

foreach ($rows as $one) {
    $issuer = isset($one['issuer']) ? (string) $one['issuer'] : '';
    $count  = isset($one['cnt']) ? (int) $one['cnt'] : 0;
    $key    = (string) zw_receipt_org_key_from_issuer($issuer);
    $label  = ($key !== '' && isset($labels[$key])) ? (string) $labels[$key] : '';
    if ($key === '') {
        $missing++;
        $label = '（未登记，建议去「收文管理 → 来文单位」补名或加别名）';
    }
    if ($as_csv) {
        printf("\"%s\",%d,%s,\"%s\"\n", str_replace('"', '""', $issuer), $count, $key, str_replace('"', '""', $label));
    } else {
        printf("%-28s %6d  %-14s %s\n", $issuer === '' ? '(空)' : $issuer, $count, $key === '' ? '-' : $key, $label);
    }
}

if (!$as_csv) {
    printf("\n共 %d 个来文单位取值；未命中 %d 个。\n", count($rows), $missing);
}
exit($missing > 0 ? 1 : 0);
