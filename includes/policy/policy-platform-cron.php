<?php
/**
 * AI法务部（原政策法规与规则中心） · WordPress 定时任务
 *
 * - 政策待办通知：仅在政策触发/待办变化时发送企业微信 Webhook；
 * - 审计日志归档：热表 365 天，归档表 1825 天，每日 02:30 执行；
 * - 不占用独立端口，不依赖独立 Node 服务。
 */

if (!defined('ABSPATH')) { exit; }

if (!function_exists('zw_policy_platform_table')) {
    require_once ZHONGCHUANWANG_THEME_DIR . '/inc/admin/policy-platform-admin.php';
}

if (!function_exists('zw_policy_platform_cron_mask_webhook')) {
function zw_policy_platform_cron_mask_webhook($url) {
    if (!$url) return '';
    $parts = wp_parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) return '***';
    $query = array();
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    foreach (array('key', 'access_token', 'token', 'secret') as $k) {
        if (isset($query[$k])) $query[$k] = '***';
    }
    $out = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['path'])) $out .= $parts['path'];
    if (!empty($query)) $out .= '?' . http_build_query($query);
    return $out;
}
}

if (!function_exists('zw_policy_platform_cron_pending')) {
function zw_policy_platform_cron_pending() {
    global $wpdb;
    $messages = array();
    if (zw_policy_platform_exists('policy_clause')) {
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_clause') . " WHERE status='待复核'");
        if ($n > 0) $messages[] = array('type' => 'CLAUSE_REVIEW', 'level' => 'MEDIUM', 'message' => $n . ' 条政策条款待复核');
    }
    if (zw_policy_platform_exists('policy_rule_approval')) {
        $first = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_rule_approval') . " WHERE approval_status='待初审'");
        $second = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_rule_approval') . " WHERE approval_status='待终审'");
        if ($first > 0) $messages[] = array('type' => 'RULE_FIRST_REVIEW', 'level' => 'HIGH', 'message' => $first . ' 条执行规则待初审');
        if ($second > 0) $messages[] = array('type' => 'RULE_SECOND_REVIEW', 'level' => 'HIGH', 'message' => $second . ' 条执行规则待终审');
    }
    if (zw_policy_platform_exists('policy_review_task')) {
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_review_task') . " WHERE status='待处理'");
        if ($n > 0) $messages[] = array('type' => 'REVIEW_TASK', 'level' => 'MEDIUM', 'message' => $n . ' 条审核改写任务待处理');
    }
    if (zw_policy_platform_exists('policy_issue')) {
        $open = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_issue') . " WHERE status<>'已关闭'");
        $overdue = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_issue') . " WHERE status='逾期'");
        $impact = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_issue') . " WHERE status<>'已关闭' AND source_type='政策更新影响分析'");
        $version = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_issue') . " WHERE status<>'已关闭' AND source_type='政策版本差异'");
        if ($open > 0) $messages[] = array('type' => 'ISSUE_OPEN', 'level' => 'MEDIUM', 'message' => $open . ' 条整改工单未关闭');
        if ($overdue > 0) $messages[] = array('type' => 'ISSUE_OVERDUE', 'level' => 'HIGH', 'message' => $overdue . ' 条整改工单已逾期');
        if ($impact > 0) $messages[] = array('type' => 'POLICY_IMPACT_TASK', 'level' => 'MEDIUM', 'message' => $impact . ' 条政策更新影响任务待处理');
        if ($version > 0) $messages[] = array('type' => 'POLICY_VERSION_TASK', 'level' => 'MEDIUM', 'message' => $version . ' 条政策版本差异任务待处理');
    }
    return array('generatedAt' => gmdate('c'), 'total' => count($messages), 'messages' => $messages);
}
}

if (!function_exists('zw_policy_platform_cron_webhook')) {
function zw_policy_platform_cron_webhook() {
    global $wpdb;
    if (!zw_policy_platform_exists('policy_notification_channel')) return '';
    $url = $wpdb->get_var("SELECT webhook_url FROM " . zw_policy_platform_table('policy_notification_channel') . " WHERE enabled=1 AND webhook_url<>'' ORDER BY update_time DESC LIMIT 1");
    return $url ? (string) $url : '';
}
}

if (!function_exists('zw_policy_platform_cron_push_notifications')) {
function zw_policy_platform_cron_push_notifications() {
    global $wpdb;
    $pending = zw_policy_platform_cron_pending();
    if (empty($pending['total'])) return array('skipped' => true, 'reason' => 'NO_PENDING_TODO');

    $fingerprint = hash('sha256', wp_json_encode($pending['messages']));
    if (get_option('zw_policy_platform_notification_last_fingerprint') === $fingerprint) {
        return array('skipped' => true, 'reason' => 'DUPLICATE_NOTIFICATION');
    }

    $webhook = zw_policy_platform_cron_webhook();
    if ($webhook === '') return array('skipped' => true, 'reason' => 'NO_WEBHOOK');

    $lines = array('**政策法规待办提醒**');
    foreach ($pending['messages'] as $m) $lines[] = '- [' . $m['level'] . '] ' . $m['message'];
    $payload = array('msgtype' => 'markdown', 'markdown' => array('content' => implode("\n", $lines)));
    $response = wp_remote_post($webhook, array(
        'timeout' => 8,
        'headers' => array('Content-Type' => 'application/json'),
        'body' => wp_json_encode($payload),
    ));
    $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
    $body = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
    $ok = ($code >= 200 && $code < 300 && strpos((string) $body, '"errcode":0') !== false);

    $wpdb->insert(zw_policy_platform_table('policy_notification'), array(
        'id' => 'NOTIFY_' . wp_generate_uuid4(),
        'channel' => 'wecom',
        'webhook_url' => zw_policy_platform_cron_mask_webhook($webhook),
        'total' => (int) $pending['total'],
        'messages_json' => wp_json_encode($pending['messages']),
        'payload_json' => wp_json_encode($payload),
        'status' => $ok ? '成功' : '失败',
        'error' => $ok ? '' : ('HTTP ' . $code),
        'response_status' => $code,
        'response_body' => is_string($body) ? $body : '',
        'created_by' => 'wp_cron',
    ));
    if ($ok) update_option('zw_policy_platform_notification_last_fingerprint', $fingerprint, false);
    return array('ok' => $ok, 'status' => $ok ? '成功' : '失败', 'total' => (int) $pending['total'], 'response_status' => $code);
}
}

if (!function_exists('zw_policy_platform_cron_archive_audit_logs')) {
function zw_policy_platform_cron_archive_audit_logs($dryRun = false) {
    global $wpdb;
    if (!zw_policy_platform_exists('policy_audit_log') || !zw_policy_platform_exists('policy_audit_log_archive')) {
        return array('skipped' => true, 'reason' => 'NO_TABLE');
    }
    $hot = zw_policy_platform_table('policy_audit_log');
    $archive = zw_policy_platform_table('policy_audit_log_archive');
    $batch = 500;
    $hotBefore = gmdate('Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS);
    $archiveBefore = gmdate('Y-m-d H:i:s', time() - 1825 * DAY_IN_SECONDS);

    $hotIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$hot} WHERE create_time < %s ORDER BY create_time ASC LIMIT %d", $hotBefore, $batch));
    $archiveIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$archive} WHERE archive_time < %s ORDER BY archive_time ASC LIMIT %d", $archiveBefore, $batch));
    if ($dryRun) {
        return array('dryRun' => true, 'hotCandidate' => count($hotIds), 'archiveCandidate' => count($archiveIds), 'hotBefore' => $hotBefore, 'archiveBefore' => $archiveBefore);
    }

    $archived = 0;
    if (!empty($hotIds)) {
        $ph = implode(',', array_fill(0, count($hotIds), '%s'));
        $sql = $wpdb->prepare(
            "INSERT INTO {$archive} (id, original_id, action, target_type, target_ids, before_json, after_json, operator, detail, original_create_time, archive_time, archived_by)
             SELECT CONCAT('AUDARC_', REPLACE(UUID(),'-','')), id, action, target_type, target_ids, before_json, after_json, operator, detail, create_time, NOW(), 'wp_cron'
             FROM {$hot} WHERE id IN ({$ph})",
            ...$hotIds
        );
        $wpdb->query($sql);
        $archived = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$hot} WHERE id IN ({$ph})", ...$hotIds));
    }

    $pruned = 0;
    if (!empty($archiveIds)) {
        $ph = implode(',', array_fill(0, count($archiveIds), '%s'));
        $pruned = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$archive} WHERE id IN ({$ph})", ...$archiveIds));
    }

    if ($archived > 0 || $pruned > 0) {
        $wpdb->insert($hot, array(
            'id' => 'AUDIT_' . wp_generate_uuid4(),
            'action' => 'POLICY_AUDIT_ARCHIVE_CRON',
            'target_type' => 'policy_audit_log',
            'target_ids' => wp_json_encode(array()),
            'before_json' => wp_json_encode(array()),
            'after_json' => wp_json_encode(array('archived' => $archived, 'pruned' => $pruned, 'hotBefore' => $hotBefore, 'archiveBefore' => $archiveBefore)),
            'operator' => 'wp_cron',
            'detail' => "WordPress 定时任务：归档 {$archived} 条，清理 {$pruned} 条",
        ));
    }
    return array('archived' => $archived, 'pruned' => $pruned, 'hotCandidate' => count($hotIds), 'archiveCandidate' => count($archiveIds));
}
}

add_filter('cron_schedules', 'zw_policy_platform_cron_schedules');
function zw_policy_platform_cron_schedules($schedules) {
    $schedules['zw_policy_platform_5min'] = array('interval' => 300, 'display' => '每 5 分钟（政策平台）');
    return $schedules;
}

add_action('init', 'zw_policy_platform_cron_schedule_events');
function zw_policy_platform_cron_schedule_events() {
    if (!wp_next_scheduled('zw_policy_platform_notification_event')) {
        wp_schedule_event(time() + 60, 'zw_policy_platform_5min', 'zw_policy_platform_notification_event');
    }
    if (!wp_next_scheduled('zw_policy_platform_archive_event')) {
        wp_schedule_event(strtotime('tomorrow 02:30'), 'daily', 'zw_policy_platform_archive_event');
    }
    // TASK-1562：对账巡检（每日 03:10；幂等，已有事件不重复排程）。
    if (!wp_next_scheduled('zw_policy_platform_recon_event')) {
        wp_schedule_event(strtotime('tomorrow 03:10'), 'daily', 'zw_policy_platform_recon_event');
    }
    // TASK-1598 / T9：到龄扫描（每日 03:40；幂等排程；**开关 off ⇒ 回调内零查询**）。
    if (!wp_next_scheduled('zw_policy_platform_age_event')) {
        wp_schedule_event(strtotime('tomorrow 03:40'), 'daily', 'zw_policy_platform_age_event');
    }
}

add_action('zw_policy_platform_notification_event', 'zw_policy_platform_cron_push_notifications');
add_action('zw_policy_platform_archive_event', 'zw_policy_platform_cron_archive_audit_logs');

/* ───────────── TASK-1562：条款派生对账「每日巡检 + 站内提醒」（只读；不外呼） ───────────── */
add_action('zw_policy_platform_recon_event', 'zw_policy_platform_cron_recon_watch');
if (!function_exists('zw_policy_platform_cron_recon_watch')) {
/**
 * 每日只读巡检：逐文档跑 canonical 对账，把结果写进一个状态项；有差异时写审计。
 *
 * 口径：**只读**——不写任何条款行/正文/哈希；**不外呼**（无 AI、无 HTTP 请求）；
 * 《章程》按铁律 19 显式排除；依赖未加载时安静退出（不写状态，避免污染）。
 *
 * @return array 本次巡检状态（docs/diff_docs/diff_items/items/at）。
 */
function zw_policy_platform_cron_recon_watch() {
    if (!function_exists('zw_legal_text_documents') || !function_exists('zw_legal_text_verify_clauses')) {
        return array();
    }
    $docs = 0; $diff_docs = 0; $diff_items = 0; $items = array();
    foreach ((array) zw_legal_text_documents() as $doc_row) {
        $did = (string) ($doc_row['id'] ?? '');
        if ($did === '') { continue; }
        $report = zw_legal_text_verify_clauses($did);
        if (!empty($report['excluded'])) { continue; }   // 《章程》：铁律 19 显式排除
        $docs++;
        $failures = array_values(array_filter(array_map('trim', (array) ($report['failures'] ?? array())), 'strlen'));
        if ($failures) {
            $diff_docs++;
            $diff_items += count($failures);
            $items[] = array(
                'doc'      => $did,
                'title'    => (string) ($doc_row['title'] ?? ''),
                'version'  => (string) ($report['version'] ?? ''),
                'failures' => count($failures),
            );
        }
    }
    $state = array('at' => time(), 'docs' => $docs, 'diff_docs' => $diff_docs, 'diff_items' => $diff_items, 'items' => $items);
    update_option('zw_policy_recon_watch_state', $state, false);
    if ($diff_docs > 0 && function_exists('zw_policy_platform_audit_insert')) {
        zw_policy_platform_audit_insert(
            'RECON_WATCH_DIFF', 'policy_clause', array(), array(), $state, 'system-cron',
            '每日对账巡检发现差异：' . (int) $diff_docs . ' 篇 / ' . (int) $diff_items . ' 项（TASK-1562）'
        );
    }
    return $state;
}
}

/** 读取最近一次巡检状态；无记录 ⇒ 空数组（向后兼容：本次上线前无该选项）。 */
if (!function_exists('zw_policy_recon_watch_state')) {
function zw_policy_recon_watch_state() {
    $state = function_exists('get_option') ? get_option('zw_policy_recon_watch_state') : array();
    return is_array($state) ? $state : array();
}
}

/* ───────────── TASK-1598 / T9 / R-13：到龄扫描「派生标记 + 幂等工单」（每日 03:40） ───────────── */

if (!function_exists('zw_policy_platform_age_scan_state')) {
/**
 * 读取最近一次到龄扫描状态；无记录 ⇒ 空数组（T10 仪表盘待办据此显示）。
 *
 * @return array `{at,scanned,over,approaching,unverified,issue_opened,issue_existing,items}`
 */
function zw_policy_platform_age_scan_state() {
    $state = function_exists('get_option') ? get_option('zw_policy_age_retire_scan_state') : array();
    return is_array($state) ? $state : array();
}
}

if (!function_exists('zw_policy_age_retire_scan_enabled')) {
/**
 * 到龄扫描总开关（**独立开关、默认 off**；沿用注册表 `zw_policy_option_is_on()` 唯一判定）。
 *
 * 为什么独立于三层灰度：到龄扫描是**只读派生 + 幂等开单**的日任务，与「提交时判定」不同批灰度，
 * 单独开关才能在 T11 灰度时「最后开到龄扫描」（开发计划 §2 T11 / §9 R7）。
 *
 * @return bool
 */
function zw_policy_age_retire_scan_enabled() {
    $raw = function_exists('get_option') ? get_option('zw_policy_age_retire_scan', '0') : '0';
    if (function_exists('zw_policy_option_is_on')) {
        return (bool) zw_policy_option_is_on($raw);
    }
    return in_array(strtolower(trim((string) $raw)), array('1', 'on', 'yes', 'true', 'enabled'), true);
}
}

if (!function_exists('zw_policy_age_retire_scan_leader')) {
/**
 * 单个负责人行的到龄派生（**横切**用：扫描与展示面共用，口径唯一）。
 *
 * 岗位场景词与周岁**都走 canonical**：
 *  - 岗位 ⇄ 场景：`zw_branch_leader_policy_position()`（词汇派生自 `zw_policy_age_rules()` 的 `scopes`）；
 *  - 周岁：`zw_control_age_from_birthday()`（SPEC-85 唯一出处），拿不到出生日期则回落行内 `age`；
 * 两者都拿不到 ⇒ `age=0` ⇒ 引擎按「未实名 / 无身份证」返回 `unverified`（**不误判为超龄**）。
 *
 * @param array  $leader 负责人行（`zw_branch_leaders()` 形状）。
 * @param string $as_of  判定日（默认今天）。
 * @return array `zw_policy_age_retire_state()` 的结果。
 */
function zw_policy_age_retire_scan_leader($leader, $as_of = '') {
    $leader = is_array($leader) ? $leader : array();
    $as_of  = $as_of !== '' ? (string) $as_of : (function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d'));

    $role   = isset($leader['role']) ? (string) $leader['role'] : '';
    $other  = isset($leader['role_other']) ? (string) $leader['role_other'] : '';
    $labels = array();
    if (function_exists('zw_branch_role_label')) {
        $label = (string) zw_branch_role_label($role, $other);
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    if ($other !== '' && !in_array($other, $labels, true)) {
        $labels[] = $other;
    }
    $position = function_exists('zw_branch_leader_policy_position')
        ? (string) zw_branch_leader_policy_position($labels)
        : '';

    $age   = 0;
    $birth = isset($leader['birth_date']) ? trim((string) $leader['birth_date']) : '';
    if ($birth !== '' && function_exists('zw_control_age_from_birthday')) {
        $calc = zw_control_age_from_birthday($birth, $as_of);
        $age  = ($calc === '' || $calc === false) ? 0 : (int) $calc;
    }
    if ($age <= 0 && isset($leader['age']) && is_numeric($leader['age'])) {
        $age = (int) $leader['age'];
    }

    return (array) zw_policy_age_retire_state(array(
        'module_key'  => 'branch',
        'feature_key' => 'branch.leaders',
        'position'    => $position,
        'as_of'       => $as_of,
        'age'         => $age,
    ), $as_of);
}
}

if (!function_exists('zw_policy_issue_exists')) {
/**
 * 按确定性主键回查整改工单是否已存在（**只读**；幂等计量用，判定口径与 `zw_policy_issue_id()` 同一出处）。
 *
 * @param array $context
 * @param array $hit
 * @return bool
 */
function zw_policy_issue_exists($context, $hit) {
    global $wpdb;
    if (!function_exists('zw_policy_issue_id') || !zw_policy_table_exists('policy_issue')) {
        return false;
    }
    if (!is_object($wpdb) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) {
        return false;
    }
    $id = (string) zw_policy_issue_id($context, $hit);
    if ($id === '') {
        return false;
    }
    $found = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . zw_policy_table('policy_issue') . ' WHERE id=%s LIMIT 1', $id));
    return ($found !== null && $found !== '');
}
}

if (!function_exists('zw_policy_control_log_write')) {
/**
 * 控制台账 `zw_policy_control_log` 的**通用条目写入口**（`zw_policy_log()` 之外的旁路）。
 *
 * 为什么需要：`zw_policy_log()` 的 `context_json` 契约面向「提交时判定」，恒取
 * `feature_key` 作为 `business_module`；到龄扫描是**日任务派生**（同一行要同时带 岗位 / 周岁 /
 * 差额），不便改造既有契约 ⇒ 新增本函数，仍**先探列、缺列即降级 + warning**（fail-open，与
 * `zw_policy_log()` 同口径），并且**只有 INSERT，没有任何 UPDATE / DELETE**。
 *
 * @param array $row `id/domain_type/business_domain/business_module/trigger_event/context_json/
 *                   decision/matched_count/matched_rules_json/create_time`
 * @return bool 是否落库成功
 */
function zw_policy_control_log_write($row) {
    global $wpdb;
    $row = is_array($row) ? $row : array();
    if (!is_object($wpdb) || !method_exists($wpdb, 'insert')) {
        zw_policy_engine_warn('无 $wpdb ⇒ 到龄台账未写入（fail-open）');
        return false;
    }
    if (!zw_policy_table_exists('policy_control_log')) {
        zw_policy_engine_warn('zw_policy_control_log 表缺失 ⇒ 到龄台账未写入（fail-open）');
        return false;
    }
    $columns = zw_policy_table_columns('policy_control_log');
    if (!$columns) {
        zw_policy_engine_warn('zw_policy_control_log 列集不可读 ⇒ 到龄台账未写入（fail-open）');
        return false;
    }
    $payload = array_intersect_key($row, array_flip($columns));
    foreach (array('id', 'decision', 'context_json') as $required) {
        if (!isset($payload[$required]) || (string) $payload[$required] === '') {
            zw_policy_engine_warn('zw_policy_control_log 必需列缺失（' . $required . '）⇒ 到龄台账未写入（fail-open）');
            return false;
        }
    }
    if (function_exists('apply_filters')) {
        $filtered = apply_filters('zw_policy_control_log_row', $payload, array('source' => 'age_watch'), array());
        if (is_array($filtered)) {
            $payload = $filtered;
        }
    }
    $inserted = $wpdb->insert(zw_policy_table('policy_control_log'), $payload);
    if ($inserted === false) {
        $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
        zw_policy_engine_warn('到龄台账写入失败 ⇒ 未落痕（fail-open）：' . $error);
        return false;
    }
    return true;
}
}

if (!function_exists('zw_policy_platform_cron_age_watch')) {
/**
 * 每日到龄扫描（T9 · R-13）：**只派生标记 + 幂等开单**，绝不批量改写业务状态。
 *
 * 硬口径（一票否决，需求稿 §5.4 红线）：
 *  - **不随任命动作**：独立日任务扫描在任负责人；
 *  - **禁止批量 UPDATE 负责人 / 人员状态**：本文件只有 `$wpdb->insert()`（源码守卫断言）；
 *  - `over` 只派生标记；可选 `zw_policy_issue_open()` 开整改工单（**同人 + 同条款 + 同日只开一单**）；
 *  - 留痕 `zw_policy_control_log`（扫描时间 + 命中人 + 依据条款 + 周岁 + 差额）。
 *
 * 开关：`zw_policy_age_retire_scan` 默认 off ⇒ 本函数**零查询零写入**（直接返回 skipped）。
 * 分支列表口径沿用站内既有扫描（`category_name=fzjg` + `meta_key` 只取有负责人的机构；
 * 归档 / 已撤销机构与**非在任**负责人跳过）。
 *
 * @param string $as_of 判定日（默认今天；测试可注入）。
 * @return array `{skipped,reason,scanned,over,approaching,unverified,issue_opened,issue_existing,at}`
 */
function zw_policy_platform_cron_age_watch($as_of = '') {
    $as_of = $as_of !== '' ? (string) $as_of : (function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d'));

    if (!zw_policy_age_retire_scan_enabled()) {
        return array('skipped' => true, 'reason' => 'SWITCH_OFF');
    }
    if (!function_exists('zw_policy_age_retire_state') || !function_exists('zw_control_age_from_birthday')) {
        return array('skipped' => true, 'reason' => 'ENGINE_MISSING');
    }

    $summary = array(
        'skipped'        => false,
        'reason'         => '',
        'scanned'        => 0,
        'over'           => 0,
        'approaching'    => 0,
        'unverified'     => 0,
        'issue_opened'   => 0,
        'issue_existing' => 0,
        'at'             => time(),
    );

    if (!function_exists('get_posts') || !function_exists('get_post_meta') || !function_exists('zw_branch_leaders')) {
        return $summary; // 依赖未加载 ⇒ 安静退出（不写状态，避免污染）
    }

    $posts = get_posts(array(
        'post_type'      => 'post',
        'post_status'    => array('publish', 'draft', 'pending', 'private'),
        'category_name'  => 'fzjg',
        'posts_per_page' => -1,
        'meta_key'       => zw_branch_leader_meta_key(),
    ));
    if (!is_array($posts)) {
        return $summary;
    }

    foreach ($posts as $post) {
        $branch_id = isset($post->ID) ? (int) $post->ID : 0;
        if ($branch_id <= 0) {
            continue;
        }
        if (function_exists('zw_branch_is_archive_record') && zw_branch_is_archive_record($branch_id)) {
            continue;
        }
        $branch_status = (string) get_post_meta($branch_id, 'zw_branch_status', true);
        if (in_array($branch_status, array('archived', 'merged', 'revoked'), true)) {
            continue;
        }
        foreach ((array) zw_branch_leaders($branch_id) as $leader) {
            if (!is_array($leader) || !zw_branch_leader_is_active($leader, $as_of)) {
                continue;
            }
            $summary['scanned']++;
            $state = zw_policy_age_retire_scan_leader($leader, $as_of);
            $label = (string) ($state['state'] ?? 'ok');
            if ($label === 'ok' && (string) ($state['reason'] ?? '') === 'unverified') {
                $summary['unverified']++;
                continue; // 未做年龄校验：不进到龄台账 / 不开单，避免把「未知」当「超龄」
            }
            if ($label === 'ok') {
                continue;
            }
            if ($label === 'over') {
                $summary['over']++;
            } elseif ($label === 'approaching') {
                $summary['approaching']++;
            }

            $person_id = (string) ($leader['person_id'] ?? '');
            $uid       = isset($leader['user_id']) ? (int) $leader['user_id'] : 0;
            zw_policy_control_log_write(array(
                'id'                 => function_exists('wp_generate_uuid4') ? (string) wp_generate_uuid4() : ('PCL-AGE-' . md5($branch_id . '|' . $person_id . '|' . $as_of)),
                'domain_type'        => '业务控制',
                'business_domain'    => 'branch',
                'business_module'    => 'branch.leaders',
                'trigger_event'      => '到龄扫描',
                'context_json'       => function_exists('wp_json_encode') ? (string) wp_json_encode(array(
                    'branch_id'    => $branch_id,
                    'person_id'    => $person_id,
                    'uid'          => $uid,
                    'age'          => $state['age'],
                    'remain_years' => $state['remain_years'],
                    'as_of'        => $as_of,
                    'clause'       => (string) ($state['clause'] ?? ''),
                    'scan'         => 'age_watch',
                )) : '',
                'decision'           => $label === 'over' ? 'OVER' : 'APPROACHING',
                'matched_count'      => 1,
                'matched_rules_json' => function_exists('wp_json_encode') ? (string) wp_json_encode(array(array(
                    'clause'       => (string) ($state['clause'] ?? ''),
                    'threshold'    => $state['threshold'],
                    'age'          => $state['age'],
                    'remain_years' => $state['remain_years'],
                    'state'        => $label,
                ))) : '',
                'create_time'        => function_exists('current_time') ? (string) current_time('mysql') : date('Y-m-d H:i:s'),
            ));

            // `over` 才开单（approaching = 信息级，只标记不转人工），幂等由 `zw_policy_issue_open()` 保证。
            if ($label !== 'over' || !function_exists('zw_policy_issue_open')) {
                continue;
            }
            $context = array(
                'module_key'  => 'branch',
                'feature_key' => 'branch.leaders',
                'as_of'       => $as_of,
                'fields'      => array('uid' => $uid, 'person_id' => $person_id, 'age' => (int) ($state['age'] ?? 0)),
            );
            $hit = array(
                'clause'    => (string) ($state['clause'] ?? ''),
                'threshold' => $state['threshold'],
                'age'       => $state['age'],
                'rule_code' => '',
            );
            // 幂等计量：`zw_policy_issue_open()` 命中既有行时**同样返回既有 ID**，故不能用返回值判「新开」；
            // 先按确定性主键回查（与 `zw_policy_issue_open()` 同一出处）⇒ 第二次扫描 opened=0 / existing=N。
            $before                   = zw_policy_issue_exists($context, $hit);
            $issue_id                 = zw_policy_issue_open($context, $hit);
            if ($issue_id !== null && $issue_id !== '' && !$before) {
                $summary['issue_opened']++;
            }
        }
    }

    $summary['issue_existing'] = max(0, (int) $summary['over'] - (int) $summary['issue_opened']);
    if (function_exists('update_option')) {
        update_option('zw_policy_age_retire_scan_state', $summary, false);
    }
    if (function_exists('zw_policy_platform_audit_insert') && ((int) $summary['over'] > 0 || (int) $summary['approaching'] > 0)) {
        zw_policy_platform_audit_insert(
            'AGE_RETIRE_SCAN',
            'branch_leader',
            array(),
            array(),
            $summary,
            'system-cron',
            '到龄扫描：在任 ' . (int) $summary['scanned'] . ' 人 / 超限 ' . (int) $summary['over']
            . ' / 临近 ' . (int) $summary['approaching'] . ' / 新开单 ' . (int) $summary['issue_opened'] . '（TASK-1598 · T9）'
        );
    }
    return $summary;
}
}

add_action('zw_policy_platform_age_event', 'zw_policy_platform_cron_age_watch');
