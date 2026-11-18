<?php
/**
 * OA-2525：任命归档留痕可见化（B2）—— 三个「只写不读」的 meta 必须在详情页可见。
 *
 * 病史：`_zw_doc_appoint_doc_media`（归档媒体 URL 数组）、`_zw_doc_appoint_archive_error`、
 * `_zw_doc_appoint_media_error` 由 `doc-appoint-cert.php` 写入，但详情页「任命同步状态」卡只读
 * `zw_doc_appoint_sync_status()` ⇒ 出事时无法区分「归档失败」与「媒体失败」。
 * `_zw_doc_pdf_updated_at` 维持不展示（与完成文件版本表的「生成时间」同信息冗余）。
 *
 * 运行：php tests/test-doc-appoint-trace-visible.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

$src = (string) file_get_contents(ABSPATH . 'includes/doc/doc-admin-list.php');
$GLOBALS['oa_at_pass'] = 0;
$GLOBALS['oa_at_fail'] = array();

function oa_at_ok($msg) {
    $GLOBALS['oa_at_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_at_bad($msg) {
    $GLOBALS['oa_at_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_at_chk($cond, $msg) {
    if ($cond) {
        oa_at_ok($msg);
    } else {
        oa_at_bad($msg);
    }
}

echo "== 1) 三个留痕键都在详情页被读取 ==\n";
oa_at_chk(strpos($src, "_zw_doc_appoint_doc_media") !== false, '读归档媒体（_zw_doc_appoint_doc_media）');
oa_at_chk(strpos($src, "_zw_doc_appoint_archive_error") !== false, '读归档问题（_zw_doc_appoint_archive_error）');
oa_at_chk(strpos($src, "_zw_doc_appoint_media_error") !== false, '读媒体问题（_zw_doc_appoint_media_error）');

echo "\n== 2) 展示口径：可读标签 + 媒体份数 + 错误文本 ==\n";
oa_at_chk(strpos($src, '归档留痕：') !== false, '有「归档留痕」可见标签');
oa_at_chk(strpos($src, '归档媒体 ') !== false && strpos($src, ' 份') !== false, '显示归档媒体份数');
oa_at_chk(strpos($src, '归档问题：') !== false, '显示归档问题（有值才显示）');
oa_at_chk(strpos($src, '媒体问题：') !== false, '显示媒体问题（有值才显示）');

echo "\n== 3) 无数据不输出空壳 / 输出转义 ==\n";
oa_at_chk(strpos($src, "if (\$ap_media_count > 0 || \$ap_archive_err !== '' || \$ap_media_err !== '')") !== false,
    '三项全空 ⇒ 整段不输出');
oa_at_chk(strpos($src, 'esc_html($ap_archive_err)') !== false && strpos($src, 'esc_html($ap_media_err)') !== false,
    '两个错误文本都经 esc_html（写入侧已 sanitize，这里二次转义）');
oa_at_chk(strpos($src, "json_decode(\$ap_media_json, true)") !== false, '媒体列表按 JSON 解析（不是把 JSON 原文怼到页面）');

echo "\n== 4) 不放宽既有判定：只读既有值，不改同步/归档逻辑 ==\n";
$src_cert = (string) file_get_contents(ABSPATH . 'includes/doc/doc-appoint-cert.php');
oa_at_chk(strpos($src, 'zw_doc_appoint_sync_status($doc_id, $ap_meta)') !== false, '同步状态仍走唯一取值函数');
oa_at_chk(substr_count($src_cert, "update_post_meta(\$doc_id, '_zw_doc_appoint_archive_error'") === 1,
    '归档问题仍只有唯一写入口（详情页不写它）');
oa_at_chk(strpos($src, "_zw_doc_pdf_updated_at") === false, 'pdf_updated_at 仍不展示（同信息冗余，已登记）');

echo "\n";
if (empty($GLOBALS['oa_at_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_at_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_at_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_at_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
