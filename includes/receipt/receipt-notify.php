<?php
/**
 * 收文管理 · 通知（站内提示 + 主题通知中心企微事件）· OA-2476。
 *
 * ## 口径：复用主题通知中心，不另建一套
 *
 * - **Webhook 名称/地址/默认群/事件绑定只在主题侧**（`inc/notification-center/wecom-webhooks.php`，
 *   option `zw_notify_wecom_webhooks` / `zw_notify_wecom_event_webhook_map`）。**本插件不存任何密钥或地址。**
 * - 收文只做两件事：
 *   ① 通过过滤器 `zw_notify_wecom_events` **自登记事件** ⇒ 后台「通知中心 → 企业微信 → 名称对应管理」
 *      里就能为这些事件选「Webhook 名称」（没绑就走默认群）；
 *   ② 到点调用 `zw_notify_wecom_send_event()`（`function_exists` 守卫 ⇒ 主题缺席时静默降级为只做站内）。
 * - **幂等**：同一事件同一自然日只发一次（transient 24h）；摘要类事件把多件合并成一条，不按件刷屏。
 * - **默认不发**：事件登记后由管理员在通知中心逐个绑定并启用；本插件开关 `auto_notify` 默认关。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_receipt_notify_events')) {
    /**
     * 收文企微事件定义（唯一出处；供通知中心注册表与发送共用）。
     *
     * @return array<string,array<string,string|array>>
     */
    function zw_receipt_notify_events() {
        return array(
            'oa_receipt_pending'  => array(
                'label'    => '收文待批示',
                'module'   => '收文管理',
                'template' => "### 收文待批示\n- **待批示**：{count} 件\n{list}\n- **时间**：{time}\n- 请前往后台「收文管理 → 收文台账」处理",
                'vars'     => array('count' => '待批示件数', 'list' => '清单（前 5 件）', 'time' => '发送时间'),
            ),
            'oa_receipt_overdue'  => array(
                'label'    => '收文办理逾期',
                'module'   => '收文管理',
                'template' => "### 收文办理逾期\n- **逾期**：{count} 件\n{list}\n- **时间**：{time}\n- 请前往后台「收文管理 → 收文台账 → 收文办理」督办",
                'vars'     => array('count' => '逾期件数', 'list' => '清单（前 5 件）', 'time' => '发送时间'),
            ),
            'oa_receipt_urge'     => array(
                'label'    => '收文自动催办',
                'module'   => '收文管理',
                'template' => "### 收文办理催办\n- **收文**：{receipt_no}\n- **办理单**：{issue_no}\n- **时限**：{due}\n- **时间**：{time}\n- 系统已写入催办留痕",
                'vars'     => array('receipt_no' => '收文编号', 'issue_no' => '办理单号', 'due' => '办理时限', 'time' => '发送时间'),
            ),
            'oa_receipt_archived' => array(
                'label'    => '收文自动归档',
                'module'   => '收文管理',
                'template' => "### 收文自动归档\n- **收文**：{receipt_no}\n- **盒号**：{box}\n- **年度**：{year}\n- **时间**：{time}",
                'vars'     => array('receipt_no' => '收文编号', 'box' => '归档盒号', 'year' => '归档年度', 'time' => '发送时间'),
            ),
        );
    }
}

if (function_exists('add_filter')) {
    /**
     * 把收文事件挂进通知中心注册表（不改主题文件）。
     */
    $zw_receipt_notify_register = function ($events) {
        $events = is_array($events) ? $events : array();
        foreach (zw_receipt_notify_events() as $key => $meta) {
            if (!isset($events[$key])) {
                $events[$key] = $meta;
            }
        }
        return $events;
    };
    add_filter('zw_notify_wecom_events', $zw_receipt_notify_register, 20);
}

if (!function_exists('zw_receipt_notify_enabled')) {
    /**
     * 总开关（`auto_notify`，默认关）。
     *
     * @return bool
     */
    function zw_receipt_notify_enabled() {
        if (!function_exists('zw_receipt_auto_enabled')) {
            return false;
        }
        return (bool) zw_receipt_auto_enabled('auto_notify');
    }
}

if (!function_exists('zw_receipt_notify_once')) {
    /**
     * 幂等闸：同一事件同一自然日只发一次。
     *
     * @param string $key 事件键。
     * @param string $suffix 附加标识（如收文 ID），空=全局每日一次。
     * @return bool true=本次可发（并已占位）；false=今天已发过。
     */
    function zw_receipt_notify_once($key, $suffix = '') {
        $day  = function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d');
        $name = 'zw_receipt_notify_' . md5((string) $key . '|' . (string) $suffix . '|' . $day);
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return true;
        }
        if (get_transient($name)) {
            return false;
        }
        set_transient($name, 1, DAY_IN_SECONDS);
        return true;
    }
}

if (!function_exists('zw_receipt_notify_send')) {
    /**
     * 发一条企微事件（主题缺席时静默返回 false，站内提示不受影响）。
     *
     * @param string $key  事件键。
     * @param array  $vars 模板变量。
     * @param array  $ctx  上下文（biz_type/biz_id）。
     * @return bool
     */
    function zw_receipt_notify_send($key, $vars = array(), $ctx = array()) {
        if (!function_exists('zw_notify_wecom_send_event')) {
            return false;
        }
        if (!zw_receipt_notify_enabled()) {
            return false;
        }
        return (bool) zw_notify_wecom_send_event((string) $key, (array) $vars, (array) $ctx);
    }
}

if (!function_exists('zw_receipt_notify_scan')) {
    /**
     * 每日摘要扫描：待批示 + 逾期，各合并成**一条**消息发出（幂等：每天一次）。
     *
     * @return array{pending:int,overdue:int,sent:array<string,bool>}
     */
    function zw_receipt_notify_scan() {
        $out = array('pending' => 0, 'overdue' => 0, 'sent' => array());
        $now = function_exists('current_time') ? (string) current_time('mysql') : date('Y-m-d H:i:s');
        $today = function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d');

        // ① 待批示：有拟办无批示
        if (function_exists('zw_receipt_handle_ready') && zw_receipt_handle_ready() && function_exists('zw_receipt_handle_list')) {
            global $wpdb;
            $src = function_exists('zw_receipt_ledger_source_table') ? zw_receipt_ledger_source_table() : '';
            $ids = $src !== '' ? (array) $wpdb->get_col("SELECT id FROM {$src} WHERE origin = 'incoming'") : array();
            $lines = array();
            foreach ($ids as $id) {
                $has_s = false;
                $has_i = false;
                foreach ((array) zw_receipt_handle_list((string) $id) as $log) {
                    $a = isset($log['action']) ? (string) $log['action'] : '';
                    if ($a === 'suggest') {
                        $has_s = true;
                    } elseif ($a === 'instruction') {
                        $has_i = true;
                    }
                }
                if ($has_s && !$has_i) {
                    $out['pending']++;
                    if (count($lines) < 5) {
                        $lines[] = '  - ' . (string) $id;
                    }
                }
            }
            if ($out['pending'] > 0 && zw_receipt_notify_once('oa_receipt_pending')) {
                $out['sent']['pending'] = zw_receipt_notify_send('oa_receipt_pending', array(
                    'count' => (string) $out['pending'],
                    'list'  => implode("\n", $lines),
                    'time'  => $now,
                ), array('biz_type' => 'receipt', 'biz_id' => 0));
            }
        }

        // ② 逾期：未收口且时限早于今天
        if (function_exists('zw_receipt_issue_table')) {
            global $wpdb;
            $table = zw_receipt_issue_table();
            if ($table !== '' && is_object($wpdb) && method_exists($wpdb, 'get_results')) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, source_id, due_date FROM {$table} WHERE source_type = %s"
                    . " AND status NOT IN (%s, %s) AND due_date <> '' AND due_date < %s ORDER BY due_date ASC LIMIT 200",
                    'incoming',
                    '已办结',
                    '归档',
                    $today
                ), 'ARRAY_A');
                $lines = array();
                foreach (is_array($rows) ? $rows : array() as $row) {
                    $out['overdue']++;
                    if (count($lines) < 5) {
                        $lines[] = '  - ' . (isset($row['id']) ? (string) $row['id'] : '') . '（时限 ' . (isset($row['due_date']) ? (string) $row['due_date'] : '') . '）';
                    }
                }
                if ($out['overdue'] > 0 && zw_receipt_notify_once('oa_receipt_overdue')) {
                    $out['sent']['overdue'] = zw_receipt_notify_send('oa_receipt_overdue', array(
                        'count' => (string) $out['overdue'],
                        'list'  => implode("\n", $lines),
                        'time'  => $now,
                    ), array('biz_type' => 'receipt', 'biz_id' => 0));
                }
            }
        }

        return $out;
    }
}

if (!function_exists('zw_receipt_notify_urge')) {
    /**
     * 催办后发一条（每收文每天最多一次）。
     *
     * @param string $receipt_id 收文 ID。
     * @param string $issue_id   办理单号。
     * @param string $due        时限。
     * @return bool
     */
    function zw_receipt_notify_urge($receipt_id, $issue_id, $due) {
        if (!zw_receipt_notify_once('oa_receipt_urge', (string) $receipt_id)) {
            return false;
        }
        $now = function_exists('current_time') ? (string) current_time('mysql') : date('Y-m-d H:i:s');
        return zw_receipt_notify_send('oa_receipt_urge', array(
            'receipt_no' => (string) $receipt_id,
            'issue_no'   => (string) $issue_id,
            'due'        => (string) $due,
            'time'       => $now,
        ), array('biz_type' => 'receipt', 'biz_id' => 0));
    }
}

if (!function_exists('zw_receipt_notify_after_archive')) {
    /**
     * 归档后发一条（每收文每天最多一次）。
     *
     * @param string $receipt_id 收文 ID。
     * @param string $box        盒号。
     * @param int    $year       年度。
     * @return bool
     */
    function zw_receipt_notify_after_archive($receipt_id, $box, $year) {
        if (!zw_receipt_notify_once('oa_receipt_archived', (string) $receipt_id)) {
            return false;
        }
        $now = function_exists('current_time') ? (string) current_time('mysql') : date('Y-m-d H:i:s');
        return zw_receipt_notify_send('oa_receipt_archived', array(
            'receipt_no' => (string) $receipt_id,
            'box'        => (string) $box,
            'year'       => (string) (int) $year,
            'time'       => $now,
        ), array('biz_type' => 'receipt', 'biz_id' => 0));
    }
}

if (!function_exists('zw_receipt_notify_counts')) {
    /**
     * 站内提示用的两个数（纯只读）：待批示、逾期。
     *
     * @return array{pending:int,overdue:int}
     */
    function zw_receipt_notify_counts() {
        $out = array('pending' => 0, 'overdue' => 0);
        if (function_exists('zw_receipt_handle_pending_instruction')) {
            $out['pending'] = (int) zw_receipt_handle_pending_instruction();
        }
        if (function_exists('zw_receipt_issue_table')) {
            global $wpdb;
            $table = zw_receipt_issue_table();
            if ($table !== '' && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $today = function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d');
                $out['overdue'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE source_type = %s AND status NOT IN (%s, %s)"
                    . " AND due_date <> '' AND due_date < %s",
                    'incoming',
                    '已办结',
                    '归档',
                    $today
                ));
            }
        }
        return $out;
    }
}

if (function_exists('add_action')) {
    add_action('zw_receipt_notify_daily_event', 'zw_receipt_notify_scan', 10, 0);
}

if (!function_exists('zw_receipt_notify_schedule')) {
    /**
     * 排每日摘要（08:40）；总开关关闭时不排。
     *
     * @return void
     */
    function zw_receipt_notify_schedule() {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (!zw_receipt_notify_enabled()) {
            return;
        }
        if (!wp_next_scheduled('zw_receipt_notify_daily_event')) {
            wp_schedule_event(strtotime('tomorrow 08:40'), 'daily', 'zw_receipt_notify_daily_event');
        }
    }
}

if (function_exists('add_action')) {
    add_action('init', 'zw_receipt_notify_schedule', 22);
}
