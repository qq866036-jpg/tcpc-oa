<?php
/**
 * 契约 C1~C3 · 发文读面（骨架，零行为变更）—— TASK-2320。
 *
 * 方向：**插件提供 → 主题消费**。`我的收文`（`user/action/inbox.php`）留主题，
 * 只经本契约取数，避免直调发文内部实现（D6 实测 13 处调用 / 7 个函数）。
 *
 * 骨架期纪律：
 *   - 只委托主题既有 `zw_doc_*`，不复制查询与判权逻辑；
 *   - 判权 `can_view` **fail-closed**（不可用/缺实现 ⇒ false）；
 *   - `pdf_url` 只回受控地址（不开放任意 URL 拼接）；
 *   - 默认不加载：`TCPC_OA_LOAD_CONTRACT_SKELETON` 为真时才 require。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_OA_DOC_API_VERSION')) {
    define('ZW_OA_DOC_API_VERSION', 1);
}

if (!function_exists('zw_oa_doc_api_version')) {
    /**
     * 版本握手。
     *
     * @return int
     */
    function zw_oa_doc_api_version() {
        return ZW_OA_DOC_API_VERSION;
    }
}

if (!function_exists('zw_oa_doc_available')) {
    /**
     * 可用性：发文访问器在 ⇒ available。
     *
     * @return array{available:bool,reason:string,source:string}
     */
    function zw_oa_doc_available() {
        if (!function_exists('zw_doc_query_documents')) {
            return array('available' => false, 'reason' => 'accessor_missing', 'source' => 'plugin');
        }
        return array('available' => true, 'reason' => '', 'source' => 'plugin');
    }
}

if (!function_exists('zw_oa_doc_inbox_query')) {
    /**
     * 「我的收文」列表（C1）。
     *
     * @param int $user_id  用户 ID（0=当前）。
     * @param int $page     页码。
     * @param int $per_page 每页。
     * @return array{available:bool,reason:string,items:array,total:int}
     */
    function zw_oa_doc_inbox_query($user_id = 0, $page = 1, $per_page = 10) {
        $state = zw_oa_doc_available();
        if (empty($state['available']) || !function_exists('zw_doc_query_inbox_documents')) {
            return array(
                'available' => false,
                'reason'    => $state['reason'] !== '' ? $state['reason'] : 'accessor_missing',
                'items'     => array(),
                'total'     => 0,
            );
        }
        $r = zw_doc_query_inbox_documents((int) $user_id, (int) $page, (int) $per_page);
        return array(
            'available' => true,
            'reason'    => '',
            'items'     => is_array($r) && isset($r['items']) ? (array) $r['items'] : (is_array($r) ? $r : array()),
            'total'     => is_array($r) && isset($r['total']) ? (int) $r['total'] : 0,
        );
    }
}

if (!function_exists('zw_oa_doc_can_view')) {
    /**
     * 单份判权（C2）：不可用或缺实现一律 **false**（fail-closed）。
     *
     * @param array $doc     文档。
     * @param int   $user_id 用户。
     * @return bool
     */
    function zw_oa_doc_can_view($doc, $user_id = 0) {
        if (empty($doc) || !is_array($doc)) {
            return false;
        }
        if (!function_exists('zw_doc_can_view_doc')) {
            return false;
        }
        return (bool) zw_doc_can_view_doc($doc, (int) $user_id);
    }
}

if (!function_exists('zw_oa_doc_pdf_url')) {
    /**
     * 受控读取地址（C3）。
     *
     * @param int  $doc_id 文档 ID。
     * @param bool $inline 是否内联。
     * @return string
     */
    function zw_oa_doc_pdf_url($doc_id, $inline = false) {
        if (!function_exists('zw_doc_pdf_download_url')) {
            return '';
        }
        return (string) zw_doc_pdf_download_url((int) $doc_id, (bool) $inline);
    }
}

if (!function_exists('zw_oa_doc_diagnostics')) {
    /**
     * 诊断（恒可用）。
     *
     * @return array
     */
    function zw_oa_doc_diagnostics() {
        $state = zw_oa_doc_available();
        return array(
            'api_version' => ZW_OA_DOC_API_VERSION,
            'available'   => (bool) $state['available'],
            'reason'      => (string) $state['reason'],
            'source'      => 'plugin',
            'capabilities' => array(
                'inbox_query' => function_exists('zw_doc_query_inbox_documents'),
                'can_view'    => function_exists('zw_doc_can_view_doc'),
                'pdf_url'     => function_exists('zw_doc_pdf_download_url'),
            ),
        );
    }
}
