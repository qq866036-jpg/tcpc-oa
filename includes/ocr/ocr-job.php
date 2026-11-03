<?php
/**
 * 收文管理 · OCR 任务（排队 / 执行 / 重试 · OA-2463）。
 *
 * ## 设计要点
 *
 * 1. **异步**：登记成功后只把 `ocr_status` 置 `pending` 并排一个 WP-Cron 单次事件
 *    （20 秒后），识别在 cron 里跑 —— 登记页不因引擎慢/不可用而超时。
 * 2. **单行写**：每次只 `$wpdb->update()` 一行（`WHERE id = %s`），**无**批量 UPDATE，
 *    不复用 `zw_policy_issue` 的任何状态语义（两套时钟不互写）。
 * 3. **失败可读**：失败写 `ocr_status='failed'` + `ocr_message`（≤255 字，只写原因不写文件内容），
 *    详情页直接展示并提供「重新识别」。
 * 4. **幂等**：同一记录重复排队只保留一个 cron 事件（`wp_clear_scheduled_hook` 先清）。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_OCR_CRON_HOOK')) {
    define('ZW_OCR_CRON_HOOK', 'zw_ocr_process_event');
}

if (!function_exists('zw_ocr_source_table')) {
    /**
     * 政策源表名（复用收文台账 canonical，不新起第二套表名）。
     *
     * @return string
     */
    function zw_ocr_source_table() {
        return function_exists('zw_receipt_ledger_source_table') ? (string) zw_receipt_ledger_source_table() : '';
    }
}

if (!function_exists('zw_ocr_write_row')) {
    /**
     * 单行写 OCR 字段（只允许白名单列）。
     *
     * @param string $id     收文记录 ID。
     * @param array  $fields 列 => 值。
     * @return bool
     */
    function zw_ocr_write_row($id, $fields) {
        $id = trim((string) $id);
        if ($id === '' || !is_array($fields) || !$fields) {
            return false;
        }
        $allowed = array('ocr_status', 'extraction_method', 'ocr_text', 'ocr_confidence', 'ocr_ms', 'ocr_at', 'ocr_message');
        $data    = array();
        foreach ($fields as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $data[$key] = $value;
            }
        }
        if (!$data) {
            return false;
        }
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'update')) {
            return false;
        }
        $table = zw_ocr_source_table();
        if ($table === '') {
            return false;
        }
        $result = $wpdb->update($table, $data, array('id' => $id));
        return $result !== false;
    }
}

if (!function_exists('zw_ocr_now')) {
    /**
     * 当前时间（`Y-m-d H:i:s`；无 WP 环境退化 gmdate）。
     *
     * @return string
     */
    function zw_ocr_now() {
        return function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('zw_ocr_queue')) {
    /**
     * 排一次识别任务。
     *
     * @param string $id    收文记录 ID。
     * @param bool   $force 已识别过是否重排（「重新识别」按钮 = true）。
     * @return bool 是否已排队。
     */
    function zw_ocr_queue($id, $force = false) {
        $id = trim((string) $id);
        if ($id === '' || !zw_ocr_is_enabled() || !zw_ocr_schema_ready()) {
            return false;
        }
        if (!$force) {
            global $wpdb;
            $table = zw_ocr_source_table();
            if ($table !== '' && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $status = (string) $wpdb->get_var($wpdb->prepare("SELECT ocr_status FROM {$table} WHERE id = %s", $id));
                if ($status === 'done' || $status === 'pending' || $status === 'running') {
                    return false;
                }
            }
        }

        zw_ocr_write_row($id, array('ocr_status' => 'pending', 'ocr_message' => ''));
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(ZW_OCR_CRON_HOOK, array($id));
        }
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 20, ZW_OCR_CRON_HOOK, array($id));
            return true;
        }
        // 无 cron 通道（CLI / 测试）⇒ 当场执行，保证行为可验证。
        return zw_ocr_process($id);
    }
}

if (!function_exists('zw_ocr_process')) {
    /**
     * 执行一次识别（cron 回调 / 同步兜底）。
     *
     * @param string $id 收文记录 ID。
     * @return bool 是否识别成功。
     */
    function zw_ocr_process($id) {
        $id = trim((string) $id);
        if ($id === '' || !zw_ocr_is_enabled() || !zw_ocr_schema_ready()) {
            return false;
        }
        global $wpdb;
        $table = zw_ocr_source_table();
        if ($table === '' || !is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            return false;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, source_file, origin FROM {$table} WHERE id = %s AND origin = 'incoming' LIMIT 1",
                $id
            ),
            'ARRAY_A'
        );
        if (!is_array($row)) {
            return false;
        }

        zw_ocr_write_row($id, array('ocr_status' => 'running', 'ocr_message' => ''));

        $relative = isset($row['source_file']) ? (string) $row['source_file'] : '';
        $started  = function_exists('microtime') ? microtime(true) : 0.0;
        $result   = zw_ocr_client_scan($relative, $id);
        if (is_wp_error($result)) {
            $message = (string) $result->get_error_message();
            if (function_exists('mb_substr')) {
                $message = mb_substr($message, 0, 250);
            } else {
                $message = substr($message, 0, 250);
            }
            zw_ocr_write_row($id, array('ocr_status' => 'failed', 'ocr_message' => $message));
            return false;
        }

        $method = 'ocr:' . $result['engine'];
        if ($result['engine_version'] !== '') {
            $method .= '@' . $result['engine_version'];
        }
        $ok = zw_ocr_write_row($id, array(
            'ocr_status'        => 'done',
            'extraction_method' => $method,
            'ocr_text'          => $result['text'],
            'ocr_confidence'    => $result['confidence'],
            'ocr_ms'            => (int) $result['ms'],
            'ocr_at'            => zw_ocr_now(),
            'ocr_message'       => '',
        ));
        return (bool) $ok;
    }
}

if (!function_exists('zw_ocr_retry_nonce')) {
    /**
     * 「重新识别」nonce action。
     *
     * @param string $id 收文记录 ID。
     * @return string
     */
    function zw_ocr_retry_nonce($id) {
        return 'zw_ocr_retry_' . (string) $id;
    }
}

if (!function_exists('zw_ocr_retry_url')) {
    /**
     * 「重新识别」提交地址（admin-post）。
     *
     * @return string
     */
    function zw_ocr_retry_url() {
        return function_exists('admin_url') ? (string) admin_url('admin-post.php') : 'admin-post.php';
    }
}

if (!function_exists('zw_ocr_handle_retry_post')) {
    /**
     * admin-post：重新识别（能力位 + nonce + 只对 incoming 行生效）。
     *
     * @return void
     */
    function zw_ocr_handle_retry_post() {
        $id = isset($_POST['zw_ocr_id']) ? trim((string) wp_unslash($_POST['zw_ocr_id'])) : '';
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            wp_die('收文记录 ID 非法。', '参数错误', array('response' => 400));
        }
        if (function_exists('current_user_can') && !current_user_can('tcpc_perm_doc')) {
            wp_die('无权限操作收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
        }
        check_admin_referer(zw_ocr_retry_nonce($id), 'zw_ocr_nonce');

        $queued = zw_ocr_queue($id, true);
        $target = function_exists('zw_receipt_ledger_detail_url')
            ? zw_receipt_ledger_detail_url($id)
            : (function_exists('admin_url') ? admin_url('admin.php?page=zw-receipt-ledger') : '');
        $target = add_query_arg(
            array('zw_msg' => $queued ? 'ocr_queued' : 'ocr_not_queued'),
            $target
        );
        wp_safe_redirect($target);
        exit;
    }
}

if (!function_exists('zw_ocr_register_hooks')) {
    /**
     * 注册 cron 与 admin-post 入口（只在 OCR 启用时挂，未启用时零入口）。
     *
     * @return void
     */
    function zw_ocr_register_hooks() {
        if (!zw_ocr_is_enabled()) {
            return;
        }
        if (function_exists('add_action')) {
            add_action(ZW_OCR_CRON_HOOK, 'zw_ocr_process', 10, 1);
            add_action('admin_post_zw_ocr_retry', 'zw_ocr_handle_retry_post');
        }
    }
}

if (!function_exists('zw_ocr_record')) {
    /**
     * 读一条收文的 OCR 字段（**独立小查询**：不改 `zw_receipt_ledger_query()` /
     * `zw_receipt_ledger_row_by_id()` 的 SQL 形状——那是主题契约面与断言面）。
     *
     * @param string $id 收文记录 ID。
     * @return array ocr_status / ocr_text / ocr_confidence / ocr_ms / ocr_at / ocr_message / extraction_method
     */
    function zw_ocr_record($id) {
        $id = trim((string) $id);
        $empty = array(
            'ocr_status'        => '',
            'ocr_text'          => '',
            'ocr_confidence'    => null,
            'ocr_ms'            => 0,
            'ocr_at'            => '',
            'ocr_message'       => '',
            'extraction_method' => '',
        );
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) || !zw_ocr_schema_ready()) {
            return $empty;
        }
        global $wpdb;
        $table = zw_ocr_source_table();
        if ($table === '' || !is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            return $empty;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT ocr_status, ocr_text, ocr_confidence, ocr_ms, ocr_at, ocr_message, extraction_method"
                . " FROM {$table} WHERE id = %s AND origin = 'incoming' LIMIT 1",
                $id
            ),
            'ARRAY_A'
        );
        if (!is_array($row)) {
            return $empty;
        }
        return array(
            'ocr_status'        => isset($row['ocr_status']) ? (string) $row['ocr_status'] : '',
            'ocr_text'          => isset($row['ocr_text']) ? (string) $row['ocr_text'] : '',
            'ocr_confidence'    => isset($row['ocr_confidence']) ? $row['ocr_confidence'] : null,
            'ocr_ms'            => isset($row['ocr_ms']) ? (int) $row['ocr_ms'] : 0,
            'ocr_at'            => isset($row['ocr_at']) ? (string) $row['ocr_at'] : '',
            'ocr_message'       => isset($row['ocr_message']) ? (string) $row['ocr_message'] : '',
            'extraction_method' => isset($row['extraction_method']) ? (string) $row['extraction_method'] : '',
        );
    }
}
