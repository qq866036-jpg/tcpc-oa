<?php
/**
 * 收文管理 · OCR 设置（OA-2463）。
 *
 * 挂载形态：**不新增子菜单**，而是收文台账页的 `view=ocr` 分支（`receipt-ledger.php` 分发）。
 * 原因：新增子页会改变权限功能树 ID 集与「子页 slug 与登记表一致」这两条既有判据
 * （主题 `tests/test-receipt-permissions.php` 逐项断言 5 个 slug），而本单是**纯插件任务**、
 * 不改主题测试口径。挂既有页面既满足用户「要有设置入口」，也不动任何既有契约。
 *
 * 安全口径：
 * - 只有 `tcpc_perm_doc` 能看与改；保存走 `admin-post` + nonce；
 * - 端点保存前过白名单（只允许回环/内网，见 `zw_ocr_validate_endpoint()`）；
 * - 页面**不回显**令牌明文（只显示「已设置 / 未设置」+ 掩码尾 4 位）；
 * - 自检只显示引擎名/版本/模型是否就绪，**不显示**任何文件内容。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_OCR_SAVE_NONCE')) {
    define('ZW_OCR_SAVE_NONCE', 'zw_ocr_save_settings');
}

if (!function_exists('zw_ocr_settings_url')) {
    /**
     * 设置视图地址（收文台账页的 `view=ocr`）。
     *
     * @return string
     */
    function zw_ocr_settings_url() {
        $base = function_exists('admin_url') ? (string) admin_url('admin.php') : 'admin.php';
        $args = array('page' => 'zw-receipt-ledger', 'view' => 'ocr');
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($args, $base);
        }
        return $base . '?' . http_build_query($args);
    }
}

if (!function_exists('zw_ocr_status_counts')) {
    /**
     * 各识别状态计数（设置视图概览；缺列/DB 不可用返回空数组，不发退化查询）。
     *
     * @return array<string,int>
     */
    function zw_ocr_status_counts() {
        if (!zw_ocr_schema_ready()) {
            return array();
        }
        global $wpdb;
        $table = function_exists('zw_ocr_source_table') ? zw_ocr_source_table() : '';
        if ($table === '' || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return array();
        }
        $rows = $wpdb->get_results(
            "SELECT ocr_status, COUNT(*) AS c FROM {$table} WHERE origin = 'incoming' GROUP BY ocr_status",
            'ARRAY_A'
        );
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $key = isset($row['ocr_status']) ? (string) $row['ocr_status'] : '';
            $out[$key] = isset($row['c']) ? (int) $row['c'] : 0;
        }
        return $out;
    }
}

if (!function_exists('zw_ocr_handle_save_post')) {
    /**
     * 保存设置（admin-post）。
     *
     * @return void
     */
    function zw_ocr_handle_save_post() {
        if (function_exists('current_user_can') && !current_user_can('tcpc_perm_doc')) {
            wp_die('无权限操作收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
        }
        check_admin_referer(ZW_OCR_SAVE_NONCE, 'zw_ocr_settings_nonce');

        $enabled  = isset($_POST['zw_ocr_enabled']) && (string) wp_unslash($_POST['zw_ocr_enabled']) === '1';
        $endpoint = isset($_POST['zw_ocr_endpoint']) ? trim((string) wp_unslash($_POST['zw_ocr_endpoint'])) : '';
        $engine   = isset($_POST['zw_ocr_engine_name']) ? sanitize_key((string) wp_unslash($_POST['zw_ocr_engine_name'])) : 'rapidocr';
        $timeout  = isset($_POST['zw_ocr_timeout']) ? (int) wp_unslash($_POST['zw_ocr_timeout']) : 30;
        $token    = isset($_POST['zw_ocr_token']) ? trim((string) wp_unslash($_POST['zw_ocr_token'])) : '';
        $clear    = isset($_POST['zw_ocr_clear_token']) && (string) wp_unslash($_POST['zw_ocr_clear_token']) === '1';

        $check = zw_ocr_validate_endpoint($endpoint);
        if (is_wp_error($check)) {
            wp_safe_redirect(add_query_arg(array('zw_msg' => 'ocr_bad_endpoint'), zw_ocr_settings_url()));
            exit;
        }

        update_option('zw_ocr_enabled', $enabled ? '1' : '0');
        update_option('zw_ocr_endpoint', $endpoint);
        update_option('zw_ocr_engine_name', $engine !== '' ? $engine : 'rapidocr');
        update_option('zw_ocr_timeout', (string) max(5, min(120, $timeout)));
        if ($clear) {
            update_option('zw_ocr_token', '');
        } elseif ($token !== '') {
            update_option('zw_ocr_token', $token);
        }

        wp_safe_redirect(add_query_arg(array('zw_msg' => 'ocr_saved'), zw_ocr_settings_url()));
        exit;
    }
}

if (!function_exists('zw_ocr_render_settings_body')) {
    /**
     * 设置视图主体（页面壳由调用方给：台账页 `view=ocr` 分支）。
     *
     * @return void
     */
    function zw_ocr_render_settings_body() {
        if (!function_exists('esc_html')) {
            return;
        }

        $enabled  = zw_ocr_is_enabled();
        $endpoint = zw_ocr_endpoint();
        $engine   = zw_ocr_engine_name();
        $timeout  = zw_ocr_timeout();
        $token    = zw_ocr_token();
        $token_on = $token !== '';
        $masked   = $token_on ? str_repeat('•', 8) . substr($token, -4) : '未设置';
        $counts   = zw_ocr_status_counts();
        $msg      = isset($_GET['zw_msg']) ? sanitize_key((string) wp_unslash($_GET['zw_msg'])) : '';

        $notices = array(
            'ocr_saved'        => array('success', 'OCR 设置已保存。'),
            'ocr_bad_endpoint' => array('error', '端点非法：只允许本机回环或内网地址，且必须以 http(s):// 开头。'),
        );
        if (isset($notices[$msg])) {
            echo '<div class="notice notice-' . esc_attr($notices[$msg][0]) . ' is-dismissible"><p>'
                . esc_html($notices[$msg][1]) . '</p></div>';
        }

        // —— 连接自检（只读 GET，无副作用）——
        if (isset($_GET['zw_ocr_check']) && (string) wp_unslash($_GET['zw_ocr_check']) === '1') {
            $health = zw_ocr_client_health();
            if (is_wp_error($health)) {
                echo '<div class="notice notice-error"><p><strong>自检失败：</strong>'
                    . esc_html($health->get_error_message()) . '</p></div>';
            } else {
                $parts = array();
                if (isset($health['engine'])) {
                    $parts[] = '引擎：' . (string) $health['engine'];
                }
                if (isset($health['engine_version'])) {
                    $parts[] = '版本：' . (string) $health['engine_version'];
                }
                $parts[] = !empty($health['models_ready']) ? '模型：已就绪' : '模型：未加载';
                echo '<div class="notice notice-success"><p><strong>自检通过：</strong>'
                    . esc_html(implode('；', $parts)) . '</p></div>';
            }
        }

        echo '<div class="zw-adm-card" style="margin:0 0 16px">';
        echo '<h2>开关与端点</h2>';
        if (!zw_ocr_schema_ready()) {
            echo '<div class="notice notice-warning inline"><p>OCR 数据列尚未迁移：先执行 '
                . '<code>wp eval-file scripts/migrate-receipt-ocr.php execute</code>，否则识别结果无处可写。</p></div>';
        }
        echo '<form method="post" action="' . esc_url(function_exists('admin_url') ? admin_url('admin-post.php') : 'admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="zw_ocr_save">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(ZW_OCR_SAVE_NONCE, 'zw_ocr_settings_nonce');
        }
        echo '<div class="zw-adm-grid is-2">';
        echo '<div class="zw-adm-field"><label for="zw-ocr-enabled">启用 OCR</label>'
            . '<label style="display:flex;gap:8px;align-items:center;font-size:14px">'
            . '<input type="checkbox" id="zw-ocr-enabled" name="zw_ocr_enabled" value="1"'
            . ($enabled ? ' checked' : '') . '> 开启后，收文登记成功即排队识别</label></div>';
        echo '<div class="zw-adm-field"><label for="zw-ocr-endpoint">服务端点</label>'
            . '<input type="text" id="zw-ocr-endpoint" name="zw_ocr_endpoint" value="' . esc_attr($endpoint) . '">'
            . '<p class="description">只允许 <code>127.0.0.1</code> / <code>localhost</code> / 内网地址（公文不出服务器）。</p></div>';
        echo '<div class="zw-adm-field"><label for="zw-ocr-token">共享令牌（可选）</label>'
            . '<input type="password" id="zw-ocr-token" name="zw_ocr_token" value="" placeholder="' . esc_attr($masked) . '" autocomplete="new-password">'
            . '<p class="description">留空 = 不修改；当前：' . esc_html($token_on ? '已设置（' . $masked . '）' : '未设置')
            . ' <label style="margin-left:8px"><input type="checkbox" name="zw_ocr_clear_token" value="1"> 清除</label></p></div>';
        echo '<div class="zw-adm-field"><label for="zw-ocr-engine">引擎标识</label>'
            . '<input type="text" id="zw-ocr-engine" name="zw_ocr_engine_name" value="' . esc_attr($engine) . '">'
            . '<p class="description">写进 <code>extraction_method</code>（如 <code>ocr:rapidocr@1.4.4</code>），换引擎后仍可区分来源。</p></div>';
        echo '<div class="zw-adm-field"><label for="zw-ocr-timeout">单次超时（秒）</label>'
            . '<input type="number" id="zw-ocr-timeout" name="zw_ocr_timeout" min="5" max="120" value="' . esc_attr((string) $timeout) . '"></div>';
        echo '</div>';
        echo '<div class="zw-adm-foot">'
            . '<a class="button" href="' . esc_url(add_query_arg(array('zw_ocr_check' => '1'), zw_ocr_settings_url())) . '">测试连接</a>'
            . '<button type="submit" class="button button-primary">保存设置</button>'
            . '</div>';
        echo '</form>';
        echo '</div>';

        echo '<div class="zw-adm-card" style="margin:0 0 16px">';
        echo '<h2>识别概览</h2>';
        if (!$counts) {
            echo '<p class="zw-doc-muted">暂无识别记录（或数据列未迁移）。</p>';
        } else {
            echo '<div class="zw-adm-grid is-2">';
            foreach ($counts as $status => $count) {
                echo zw_receipt_ledger_detail_field(
                    zw_ocr_status_label((string) $status),
                    (string) (int) $count . ' 件'
                );
            }
            echo '</div>';
        }
        echo '<p class="zw-doc-muted">部署与运维见 <code>scripts/ocr-service/README.md</code>：'
            . '本机常驻服务 + 回环端口 + 私有存储路径白名单；识别结果只落 OCR 专用列，**不改进来文正文**。</p>';
        echo '</div>';
    }
}

if (!function_exists('zw_ocr_page_settings')) {
    /**
     * 独立页面形态（保留给未来「独立子页」用；当前由台账 `view=ocr` 分支调用 `body`）。
     *
     * @return void
     */
    function zw_ocr_page_settings() {
        if (function_exists('current_user_can') && !current_user_can('tcpc_perm_doc')) {
            if (function_exists('wp_die')) {
                wp_die('无权限访问收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
            }
            return;
        }
        if (function_exists('zw_receipt_admin_ui_page_open')) {
            zw_receipt_admin_ui_page_open(
                'OCR 识别设置',
                '收文附件在本机 OCR 引擎上识别为文本（不出服务器）。识别是**异步**的：登记成功后排队，'
                . '在 WP-Cron 里执行；失败只影响 OCR 字段，不影响收文登记与办理。',
                'zw-receipt-ledger'
            );
        } else {
            echo '<div class="wrap"><h1>OCR 识别设置</h1>';
        }
        zw_ocr_render_settings_body();
        if (function_exists('zw_receipt_admin_ui_page_close')) {
            zw_receipt_admin_ui_page_close();
        } else {
            echo '</div>';
        }
    }
}

if (function_exists('add_action')) {
    add_action('admin_post_zw_ocr_save', 'zw_ocr_handle_save_post');
}
