<?php
/**
 * 发文附件统一下载入口与下载日志（TASK-433）。
 *
 * 说明：
 * - 用户已确认附件不做参与人隔离，但仍要求记录下载日志；
 * - 本入口统一记录“从系统页面下载”的行为；
 * - 公开直链被直接访问的行为无法由本入口覆盖。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_log_download')) {
    /**
     * 写下载日志。
     *
     * @param int    $document_id   发文 ID。
     * @param int    $attachment_id 附件 ID。
     * @param string $file_name     文件名。
     * @param string $result        success|failed。
     * @return void
     */
    function zw_doc_log_download($document_id, $attachment_id, $file_name, $result = 'success') {
        global $wpdb;
        $settings = zw_doc_flow_settings();
        if (empty($settings['download_log_enabled'])) {
            return;
        }
        $wpdb->insert(
            zw_doc_table('download_logs'),
            array(
                'document_id'   => (int) $document_id,
                'attachment_id' => (int) $attachment_id,
                'user_id'       => get_current_user_id(),
                'file_name'     => sanitize_file_name((string) $file_name),
                'result'        => in_array($result, array('success', 'failed'), true) ? $result : 'failed',
                'downloaded_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s')
        );
    }
}

if (!function_exists('zw_doc_handle_download')) {
    /**
     * admin-post 下载处理。
     *
     * @return void
     */
    function zw_doc_handle_download() {
        if (!zw_doc_is_enabled()) {
            wp_die('发文管理未启用。', '禁止访问', array('response' => 403));
        }
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }

        $document_id   = isset($_GET['doc_id']) ? absint(wp_unslash($_GET['doc_id'])) : 0;
        $attachment_id = isset($_GET['attachment_id']) ? absint(wp_unslash($_GET['attachment_id'])) : 0;
        $nonce         = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';

        if (!$document_id || !$attachment_id || !wp_verify_nonce($nonce, 'zw_doc_download_' . $document_id . '_' . $attachment_id)) {
            wp_die('下载链接无效或已过期。', '下载失败', array('response' => 403));
        }

        $doc = zw_doc_get_document($document_id);
        if (!$doc || !in_array($attachment_id, $doc['attachment_ids'], true)) {
            wp_die('附件不属于该发文，或已被移除。', '下载失败', array('response' => 404));
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !is_readable($file) || is_dir($file)) {
            zw_doc_log_download($document_id, $attachment_id, $file ? wp_basename($file) : '', 'failed');
            wp_die('附件不存在或不可读。', '下载失败', array('response' => 404));
        }

        $uploads = wp_upload_dir();
        $basedir = isset($uploads['basedir']) ? realpath($uploads['basedir']) : false;
        $real    = realpath($file);
        if ($basedir === false || $real === false || strpos($real, $basedir) !== 0) {
            zw_doc_log_download($document_id, $attachment_id, wp_basename($file), 'failed');
            wp_die('附件路径不合法。', '下载失败', array('response' => 403));
        }

        zw_doc_log_download($document_id, $attachment_id, wp_basename($file), 'success');

        nocache_headers();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode(wp_basename($file)) . '"');
        header('Content-Length: ' . (string) filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($real);
        exit;
    }
}
