<?php
/**
 * 发文管理公网访问防护（TASK-446）。
 *
 * 发文管理仅供后台仪表盘使用；任何前台通过 post_type / p / attachment_id
 * 指向 zw_official_doc 的查询统一返回 404。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_public_404')) {
    /**
     * 输出 404 并结束请求。
     *
     * @return void
     */
    function zw_doc_public_404() {
        status_header(404);
        nocache_headers();
        wp_die('页面不存在。', '404 Not Found', array('response' => 404));
    }
}

if (!function_exists('zw_doc_block_public_query')) {
    /**
     * 阻止公网查询发文 CPT。
     *
     * @return void
     */
    function zw_doc_block_public_query() {
        if (is_admin()) {
            return;
        }

        $types = get_query_var('post_type');
        if (is_array($types)) {
            foreach ($types as $type) {
                if (sanitize_key((string) $type) === 'zw_official_doc') {
                    zw_doc_public_404();
                }
            }
        } elseif (sanitize_key((string) $types) === 'zw_official_doc') {
            zw_doc_public_404();
        }

        foreach (array('p', 'page_id', 'attachment_id') as $query_var) {
            $post_id = (int) get_query_var($query_var);
            if ($post_id > 0 && get_post_type($post_id) === 'zw_official_doc') {
                zw_doc_public_404();
            }
        }

        if (isset($_GET['post_type'])) {
            $get_type = sanitize_key(wp_unslash($_GET['post_type']));
            if ($get_type === 'zw_official_doc') {
                zw_doc_public_404();
            }
        }

        if (isset($_GET['p'])) {
            $post_id = absint(wp_unslash($_GET['p']));
            if ($post_id > 0 && get_post_type($post_id) === 'zw_official_doc') {
                zw_doc_public_404();
            }
        }
    }
}

add_action('template_redirect', 'zw_doc_block_public_query', 0);
