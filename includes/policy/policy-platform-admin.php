<?php
/**
 * 中传网主题 · AI法务部后台入口（原政策法规与规则中心，内部标识不变）
 *
 * 定位：政策法规与统一控制平台是 tcpc 主题后台功能。
 * 本文件只新增主题后台菜单与只读视图，不修改会员、支付、物流、机构等已开发功能。
 *
 * 数据来源：当前站点数据库中的 {$wpdb->prefix}zw_policy_* 表。
 * 权限：仅 manage_options。
 */

if (!defined('ABSPATH')) { exit; }

// TASK-954：数据访问层（表名 / 存在性 / 内容哈希）抽到前端后台共用文件，
// 避免仅在 is_admin() 时加载导致前端签署判定取不到哈希。
// TASK-2320 · W1-1（迁入修正）：本文件原在主题 `inc/admin/`，用 `__DIR__ . '/../policy-platform/'`
// 懒加载数据层；迁入本插件后同一表达式解析到 `includes/policy-platform/` —— 该目录**不存在**
// ⇒ `enabled=true` 那一刻 `require_once` 直接 fatal（后台白屏）。
// 按 Q-A2 口径，数据访问层 `inc/policy-platform/policy-platform-data.php` **仍留主题**，并且由主题
// `functions.php` 在装载阶段**无条件**加载（前端+后台都加载）⇒ 本插件**不需要也不得**自行拼主题
// 内部路径（`scripts/ci-check.sh` ③ 反向依赖守卫：插件内出现主题目录系函数或模板路径常量即判红，
// 只允许调主题契约函数）。数据层函数在本文件**被调用时**（后台菜单/页面渲染）必然已就绪：
// 插件装载早于主题，但**调用晚于**主题装载。
// 若主题后续提供契约自举函数，则优先调用，保持单点维护（当前主题未提供，故为空操作）。
if (function_exists('zw_oa_policy_data_bootstrap')) {
    zw_oa_policy_data_bootstrap();
}

// TASK-1598 / SPEC-93 · T7（R-12①）：适用模块的**键校验 / 长度 fail-closed**唯一实现在
// `scripts/migrate-policy-rule-modules.php` 的库函数内（`zw_policy_rule_modules_*`），
// 后台页复用同一实现（禁止第二套口径漂移）。`ZW_POLICY_RULE_MODULES_LIB` 抑制其 CLI 包装。
// 文件缺失 ⇒ `zw_policy_applicability_lib_ready()` 为假 ⇒ 页面 fail-closed 到「只读 + 禁止保存」。
if (!function_exists('zw_policy_rule_modules_validate')) {
    $zw_policy_appl_lib = dirname(__DIR__, 2) . '/scripts/migrate-policy-rule-modules.php';
    if (is_file($zw_policy_appl_lib)) {
        if (!defined('ZW_POLICY_RULE_MODULES_LIB')) { define('ZW_POLICY_RULE_MODULES_LIB', true); }
        require_once $zw_policy_appl_lib;
    }
    unset($zw_policy_appl_lib);
}

if (!defined('ZW_AI_LEGAL_NAME')) {
    define('ZW_AI_LEGAL_NAME', 'AI法务部');
}

if (!function_exists('zw_policy_platform_table')) {
function zw_policy_platform_table($name) {
    global $wpdb;
    return $wpdb->prefix . 'zw_' . $name;
}
}

if (!function_exists('zw_policy_platform_exists')) {
function zw_policy_platform_exists($name) {
    global $wpdb;
    $table = zw_policy_platform_table($name);
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}
}

if (!function_exists('zw_policy_platform_ready')) {
function zw_policy_platform_ready() {
    return zw_policy_platform_exists('policy_source')
        && zw_policy_platform_exists('policy_clause')
        && zw_policy_platform_exists('policy_rule');
}
}

if (!function_exists('zw_policy_platform_missing_notice')) {
function zw_policy_platform_missing_notice() {
    echo '<div class="notice notice-warning"><p>政策平台数据表尚未初始化。请先完成 <code>{$wpdb->prefix}zw_policy_*</code> 数据表迁移，再使用本功能。</p></div>';
}
}

if (!function_exists('zw_policy_platform_page_header')) {
function zw_policy_platform_page_header($title, $desc = '') {
    echo '<div class="wrap"><div class="zw-adm-page-head"><h1>' . esc_html($title) . '</h1>';
    if ($desc !== '') echo '<p>' . esc_html($desc) . '</p>';
    echo '</div>';
}
}

if (!function_exists('zw_policy_platform_page_footer')) {
function zw_policy_platform_page_footer() {
    echo '</div>';
}
}

if (!function_exists('zw_policy_platform_esc')) {
function zw_policy_platform_esc($value) {
    return esc_html((string) $value);
}
}

if (!function_exists('zw_policy_platform_badge')) {
function zw_policy_platform_badge($type, $label) {
    $type = in_array($type, array('on', 'wait', 'err', 'info', 'off'), true) ? $type : 'off';
    return '<span class="zw-adm-badge ' . esc_attr($type) . '">' . esc_html((string) $label) . '</span>';
}
}

if (!function_exists('zw_policy_platform_status_type')) {
function zw_policy_platform_status_type($status) {
    $status = (string) $status;
    if (in_array($status, array('已确认', '已启用', '成功', '已生效', '是'), true)) return 'on';
    if (in_array($status, array('待复核', '待初审', '待终审', '待处理', '未开始', '整改中', '待复核', '高'), true)) return 'wait';
    if (in_array($status, array('逾期', '重大'), true)) return 'err';
    return 'off';
}
}

if (!function_exists('zw_policy_platform_badge_cell')) {
function zw_policy_platform_badge_cell($type, $label) {
    return array('html' => zw_policy_platform_badge($type, $label));
}
}

if (!function_exists('zw_policy_platform_audit_insert')) {
function zw_policy_platform_audit_insert($action, $target_type, $target_ids, $before, $after, $operator, $detail = '') {
    global $wpdb;
    if (!zw_policy_platform_exists('policy_audit_log')) return;
    $wpdb->insert(zw_policy_platform_table('policy_audit_log'), array(
        'id' => 'AUDIT_' . wp_generate_uuid4(),
        'action' => $action,
        'target_type' => $target_type,
        'target_ids' => wp_json_encode(array_values((array) $target_ids)),
        'before_json' => wp_json_encode($before),
        'after_json' => wp_json_encode($after),
        'operator' => $operator,
        'detail' => $detail,
    ));
}
}

if (!function_exists('zw_policy_platform_rule_action_link')) {
function zw_policy_platform_rule_action_link($id, $action, $label, $decision = '', $class = 'button button-small') {
    $url = admin_url('admin-post.php?action=' . rawurlencode($action) . '&id=' . rawurlencode($id));
    if ($decision !== '') $url .= '&decision=' . rawurlencode($decision);
    $url = wp_nonce_url($url, 'zw_policy_rule_action_' . $id);
    return '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
}
}

if (!function_exists('zw_policy_platform_rule_approval')) {
function zw_policy_platform_rule_approval($rule_id) {
    global $wpdb;
    if (!zw_policy_platform_exists('policy_rule_approval')) return array();
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . zw_policy_platform_table('policy_rule_approval') . ' WHERE rule_id=%s', $rule_id), ARRAY_A);
    return is_array($row) ? $row : array();
}
}

if (!function_exists('zw_policy_platform_upsert_rule_approval')) {
function zw_policy_platform_upsert_rule_approval($rule_id, $data) {
    global $wpdb;
    $table = zw_policy_platform_table('policy_rule_approval');
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE rule_id=%s", $rule_id));
    if ($exists) {
        $wpdb->update($table, $data, array('rule_id' => $rule_id));
    } else {
        $wpdb->insert($table, array_merge(array('id' => 'RAP_' . wp_generate_uuid4(), 'rule_id' => $rule_id), $data));
    }
}
}

if (!function_exists('zw_policy_platform_review_link')) {
function zw_policy_platform_review_link($id, $decision, $label, $class = 'button button-small') {
    $action = 'zw_policy_clause_review';
    $url = admin_url('admin-post.php?action=' . $action . '&id=' . rawurlencode($id) . '&decision=' . rawurlencode($decision));
    $url = wp_nonce_url($url, $action . '_' . $id);
    return '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
}
}

if (!function_exists('zw_policy_platform_pager')) {
function zw_policy_platform_pager($total, $per_page, $paged, $base_url, $args = array()) {
    $pages = max(1, (int) ceil($total / max(1, $per_page)));
    if ($pages <= 1) return;
    $base = $args ? add_query_arg($args, $base_url) : $base_url;
    $links = paginate_links(array(
        'base' => add_query_arg('paged', '%#%', $base),
        'format' => '',
        'current' => max(1, (int) $paged),
        'total' => $pages,
        'prev_text' => '‹',
        'next_text' => '›',
        'type' => 'array',
    ));
    if (empty($links)) return;
    echo '<div class="tablenav"><div class="tablenav-pages">' . implode(' ', $links) . '</div></div>';
}
}

if (!function_exists('zw_policy_platform_render_table')) {
function zw_policy_platform_render_table($headers, $rows) {
    // TASK-1633（TASK-1579 P1-6 第四批）：宽表落 canonical 横滑包裹。
    // 本助手自身不输出 <style>（横滑规则由 ai法务部外壳下沉：slug 前缀 zw-policy ⇒ 命中 zw_mc_adm_is_screen()）。
    echo '<div class="zw-adm-card" style="overflow-x:auto"><div class="zw-adm-table-wrap"><table class="zw-adm-tbl"><thead><tr>';
    foreach ($headers as $h) {
        if (is_array($h) && isset($h['raw'])) { echo '<th>' . $h['raw'] . '</th>'; }
        elseif (is_array($h) && isset($h['html'])) { echo '<th>' . wp_kses_post($h['html']) . '</th>'; }
        else { echo '<th>' . esc_html($h) . '</th>'; }
    }
    echo '</tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="' . count($headers) . '" style="text-align:center;color:#999;padding:28px 0">暂无数据。</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                if (is_array($cell) && isset($cell['raw'])) {
                    echo '<td>' . $cell['raw'] . '</td>';
                } elseif (is_array($cell) && isset($cell['html'])) {
                    echo '<td>' . wp_kses_post($cell['html']) . '</td>';
                } else {
                    echo '<td>' . esc_html((string) $cell) . '</td>';
                }
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table></div></div>';
}
}

/* ───────────── 政策条款复核写操作 ───────────── */
add_action('admin_post_zw_policy_clause_review', 'zw_policy_platform_handle_clause_review');
add_action('admin_post_zw_policy_clause_batch_review', 'zw_policy_platform_handle_clause_batch_review');

/* ───────────── TASK-1559 第二阶段：受控 CSV 导出「派生对账差异清单」 ───────────── */
add_action('admin_post_zw_policy_recon_export', 'zw_policy_platform_handle_recon_export');
if (!function_exists('zw_policy_platform_handle_recon_export')) {
function zw_policy_platform_handle_recon_export() {
    // 安全四件套：能力位 → nonce → 受控列（白名单，无个人信息）→ 公式注入中和（唯一出处 zw_branch_csv_export_cell）。
    if (!current_user_can('manage_options') && !current_user_can('tcpc_perm_legal')) { wp_die('无权限'); }
    check_admin_referer('zw_policy_recon_export');
    if (!function_exists('zw_branch_csv_export_cell')) { wp_die('导出依赖未加载（CSV 单元格中和器不可用），已拒绝导出。'); }
    if (!function_exists('zw_legal_text_verify_clauses') || !function_exists('zw_legal_text_documents')) { wp_die('对账模块未加载，已拒绝导出。'); }

    $rows = array(array('文档ID', '文档名', '生效版本', '正文解析', '差异项', '失败明细'));
    foreach ((array) zw_legal_text_documents() as $doc_row) {
        $did = (string) ($doc_row['id'] ?? '');
        if ($did === '') { continue; }
        $report   = zw_legal_text_verify_clauses($did);
        $failures = array();
        foreach ((array) ($report['failures'] ?? array()) as $one) {
            $one = trim((string) $one);
            if ($one !== '') { $failures[] = $one; }
        }
        if (empty($failures)) { continue; }   // 受控导出：只导出「有差异」的行（与页面"仅有差异"视图同口径）
        $rows[] = array(
            $did,
            (string) ($doc_row['title'] ?? ''),
            (string) ($report['version'] ?? ''),
            (string) (int) ($report['count'] ?? 0),
            (string) count($failures),
            implode(' | ', $failures),
        );
    }

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="policy-recon-diff-' . gmdate('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    foreach ($rows as $row) {
        $cells = array();
        foreach ($row as $cell) {
            $v = zw_branch_csv_export_cell((string) $cell);
            $cells[] = '"' . str_replace('"', '""', $v) . '"';
        }
        echo implode(',', $cells) . "\r\n";
    }
    if (function_exists('zw_policy_platform_audit_insert')) {
        zw_policy_platform_audit_insert(
            'RECON_DIFF_EXPORT', 'policy_clause', array(), array(),
            array('rows' => max(0, count($rows) - 1)),
            (function_exists('wp_get_current_user') && wp_get_current_user()) ? wp_get_current_user()->user_login : 'system',
            '导出派生对账差异清单 CSV（TASK-1559 第二阶段）'
        );
    }
    exit;
}
}

function zw_policy_platform_clause_review_core($id, $decision, $operator = 'system') {
    global $wpdb;
    $decision = strtoupper((string) $decision);
    if (!in_array($decision, array('CONFIRMED', 'REJECTED'), true)) return new WP_Error('clause_decision', '复核结论必须为 CONFIRMED 或 REJECTED');
    $table = zw_policy_platform_table('policy_clause');
    $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%s", $id), ARRAY_A);
    if (!$before) return new WP_Error('clause_not_found', '政策条款不存在');
    $status = $decision === 'CONFIRMED' ? '已确认' : '已退回';
    $opinion = $decision === 'CONFIRMED' ? '后台确认通过' : '后台退回补充';
    $now = gmdate('Y-m-d H:i:s');
    $after = array_merge($before, array(
        'status' => $status, 'reviewer' => $operator, 'review_date' => $now,
        'review_opinion' => $opinion, 'update_time' => $now,
    ));
    $wpdb->update($table, array(
        'status' => $status, 'reviewer' => $operator, 'review_date' => $now,
        'review_opinion' => $opinion, 'update_time' => $now,
    ), array('id' => $id));
    zw_policy_platform_audit_insert('CLAUSE_REVIEW', 'policy_clause', array($id), $before, $after, $operator, $opinion);
    return array('ok' => true, 'status' => $status);
}

function zw_policy_platform_clause_batch_review_core($ids, $decision, $operator = 'system') {
    global $wpdb;
    $decision = strtoupper((string) $decision);
    if (!is_array($ids) || empty($ids) || !in_array($decision, array('CONFIRMED', 'REJECTED'), true)) {
        return new WP_Error('clause_batch_params', '批量复核参数错误');
    }
    $table = zw_policy_platform_table('policy_clause');
    $status = $decision === 'CONFIRMED' ? '已确认' : '已退回';
    $opinion = $decision === 'CONFIRMED' ? '后台批量确认通过' : '后台批量退回补充';
    $now = gmdate('Y-m-d H:i:s');
    $updated = 0; $failed = array();
    foreach ($ids as $id) {
        $before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%s", $id), ARRAY_A);
        if (!$before) { $failed[] = $id; continue; }
        $wpdb->update($table, array(
            'status' => $status, 'reviewer' => $operator, 'review_date' => $now,
            'review_opinion' => $opinion, 'update_time' => $now,
        ), array('id' => $id));
        $updated++;
    }
    zw_policy_platform_audit_insert('CLAUSE_BATCH_REVIEW', 'policy_clause', $ids, array(), array(
        'decision' => $decision, 'status' => $status, 'updated' => $updated, 'failed' => $failed,
    ), $operator, $opinion);
    return array('ok' => true, 'updated' => $updated, 'failed' => $failed);
}

function zw_policy_platform_handle_clause_review() {
    if (!current_user_can('manage_options')) { wp_die('无权限'); }
    $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
    $decision = isset($_GET['decision']) ? sanitize_key(wp_unslash($_GET['decision'])) : '';
    if ($id === '') wp_die('参数错误');
    check_admin_referer('zw_policy_clause_review_' . $id);
    $result = zw_policy_platform_clause_review_core($id, $decision, wp_get_current_user()->user_login);
    if (is_wp_error($result)) wp_die($result->get_error_message());
    wp_safe_redirect(add_query_arg(array('page' => 'zw-policy-clauses', 'zw_msg' => 'clause_reviewed'), admin_url('admin.php')));
    exit;
}

function zw_policy_platform_handle_clause_batch_review() {
    if (!current_user_can('manage_options')) { wp_die('无权限'); }
    check_admin_referer('zw_policy_clause_batch_review');
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('sanitize_text_field', wp_unslash($_POST['ids'])) : array();
    $decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
    $result = zw_policy_platform_clause_batch_review_core($ids, $decision, wp_get_current_user()->user_login);
    if (is_wp_error($result)) {
        wp_safe_redirect(add_query_arg(array('page' => 'zw-policy-clauses', 'zw_msg' => 'batch_invalid'), admin_url('admin.php')));
        exit;
    }
    wp_safe_redirect(add_query_arg(array('page' => 'zw-policy-clauses', 'zw_msg' => 'batch_reviewed'), admin_url('admin.php')));
    exit;
}

/* ───────────── 执行规则审批写操作 ───────────── */
add_action('admin_post_zw_policy_rule_request_enable', function () { zw_policy_platform_handle_rule_action('request_enable'); });
add_action('admin_post_zw_policy_rule_first_review', function () { zw_policy_platform_handle_rule_action('first_review'); });
add_action('admin_post_zw_policy_rule_second_review', function () { zw_policy_platform_handle_rule_action('second_review'); });
add_action('admin_post_zw_policy_rule_disable', function () { zw_policy_platform_handle_rule_action('disable'); });
add_action('admin_post_zw_policy_rule_batch', 'zw_policy_platform_handle_rule_batch');

function zw_policy_platform_rule_action_core($id, $action, $decision = '', $operator = 'system') {
    global $wpdb;
    $rule_table = zw_policy_platform_table('policy_rule');
    $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rule_table} WHERE id=%s", $id), ARRAY_A);
    if (!$rule) return new WP_Error('rule_not_found', '执行规则不存在');
    $approval = zw_policy_platform_rule_approval($id);
    $status = isset($approval['approval_status']) ? (string) $approval['approval_status'] : '草稿';
    $decision = strtoupper((string) $decision);
    $now = gmdate('Y-m-d H:i:s');

    if ($action === 'request_enable') {
        if ((int) $rule['enabled'] === 1) return new WP_Error('rule_enabled', '规则已启用，无需申请');
        if (in_array($status, array('待初审', '待终审'), true)) return new WP_Error('rule_in_review', '规则已在审批流程中');
        $clause_ids = json_decode((string) $rule['policy_rule_ids'], true);
        $clause_ids = is_array($clause_ids) ? $clause_ids : array();
        if (!empty($clause_ids) && zw_policy_platform_exists('policy_clause')) {
            $ph = implode(',', array_fill(0, count($clause_ids), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare('SELECT id,status FROM ' . zw_policy_platform_table('policy_clause') . " WHERE id IN ({$ph})", ...$clause_ids), ARRAY_A);
            $unconfirmed = array_filter($rows, function ($r) { return $r['status'] !== '已确认'; });
            if (!empty($unconfirmed)) return new WP_Error('rule_clause_unconfirmed', '关联条款未全部确认：' . implode(', ', array_column($unconfirmed, 'id')));
        }
        $data = array(
            'approval_status' => '待初审',
            'requested_by' => $operator,
            'request_reason' => '后台申请启用',
            'requested_at' => $now,
            'first_reviewer' => null, 'first_review_decision' => null, 'first_review_at' => null, 'first_review_opinion' => '',
            'second_reviewer' => null, 'second_review_decision' => null, 'second_review_at' => null, 'second_review_opinion' => '',
            'enabled_by' => null, 'enabled_at' => null, 'disabled_by' => null, 'disabled_at' => null, 'disable_reason' => '',
        );
        zw_policy_platform_upsert_rule_approval($id, $data);
        $wpdb->update($rule_table, array('enabled' => 0, 'update_time' => $now), array('id' => $id));
        zw_policy_platform_audit_insert('RULE_REQUEST_ENABLE', 'policy_rule', array($id), $rule, array_merge($rule, $data, array('enabled' => 0)), $operator, '后台申请启用');
        return array('ok' => true);
    }

    if ($action === 'first_review') {
        if ($status !== '待初审') return new WP_Error('rule_status', '规则不在待初审状态');
        if (!in_array($decision, array('APPROVE', 'REJECT'), true)) return new WP_Error('rule_decision', '初审结论错误');
        $next = $decision === 'APPROVE' ? '待终审' : '已驳回';
        $data = array('approval_status' => $next, 'first_reviewer' => $operator, 'first_review_decision' => $decision, 'first_review_at' => $now, 'first_review_opinion' => '后台初审');
        zw_policy_platform_upsert_rule_approval($id, $data);
        zw_policy_platform_audit_insert('RULE_FIRST_REVIEW', 'policy_rule', array($id), $rule, array_merge($rule, $approval, $data), $operator, $decision);
        return array('ok' => true);
    }

    if ($action === 'second_review') {
        if ($status !== '待终审') return new WP_Error('rule_status', '规则不在待终审状态');
        if (!in_array($decision, array('APPROVE', 'REJECT'), true)) return new WP_Error('rule_decision', '终审结论错误');
        $first_reviewer = isset($approval['first_reviewer']) ? (string) $approval['first_reviewer'] : '';
        if ($first_reviewer !== '' && $first_reviewer === $operator) return new WP_Error('rule_reviewer_same', '终审人与初审人不能为同一人');
        $enabled = $decision === 'APPROVE' ? 1 : 0;
        $next = $decision === 'APPROVE' ? '已启用' : '已驳回';
        $data = array(
            'approval_status' => $next, 'second_reviewer' => $operator, 'second_review_decision' => $decision, 'second_review_at' => $now, 'second_review_opinion' => '后台终审',
            'enabled_by' => $enabled ? $operator : null, 'enabled_at' => $enabled ? $now : null,
        );
        zw_policy_platform_upsert_rule_approval($id, $data);
        $wpdb->update($rule_table, array('enabled' => $enabled, 'update_time' => $now), array('id' => $id));
        zw_policy_platform_audit_insert('RULE_SECOND_REVIEW', 'policy_rule', array($id), $rule, array_merge($rule, $approval, $data, array('enabled' => $enabled)), $operator, $decision);
        return array('ok' => true);
    }

    if ($action === 'disable') {
        if ((int) $rule['enabled'] !== 1) return new WP_Error('rule_disabled', '规则未启用，无需停用');
        $data = array('approval_status' => '已停用', 'disabled_by' => $operator, 'disabled_at' => $now, 'disable_reason' => '后台停用');
        zw_policy_platform_upsert_rule_approval($id, $data);
        $wpdb->update($rule_table, array('enabled' => 0, 'update_time' => $now), array('id' => $id));
        zw_policy_platform_audit_insert('RULE_DISABLE', 'policy_rule', array($id), $rule, array_merge($rule, $approval, $data, array('enabled' => 0)), $operator, '后台停用');
        return array('ok' => true);
    }

    return new WP_Error('rule_action', '未知规则操作');
}

function zw_policy_platform_handle_rule_action($action) {
    if (!current_user_can('manage_options')) { wp_die('无权限'); }
    $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash($_GET['id'])) : '';
    if ($id === '') wp_die('规则参数错误');
    check_admin_referer('zw_policy_rule_action_' . $id);
    $decision = isset($_GET['decision']) ? sanitize_key(wp_unslash($_GET['decision'])) : '';
    $result = zw_policy_platform_rule_action_core($id, $action, $decision, wp_get_current_user()->user_login);
    if (is_wp_error($result)) wp_die($result->get_error_message());
    wp_safe_redirect(add_query_arg(array('page' => 'zw-policy-rules', 'zw_msg' => 'rule_saved'), admin_url('admin.php')));
    exit;
}

function zw_policy_platform_handle_rule_batch() {
    if (!current_user_can('manage_options')) { wp_die('无权限'); }
    check_admin_referer('zw_policy_rule_batch');
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('sanitize_text_field', wp_unslash($_POST['ids'])) : array();
    $op = isset($_POST['batch_operation']) ? sanitize_text_field(wp_unslash($_POST['batch_operation'])) : '';
    if (empty($ids) || $op === '') {
        wp_safe_redirect(add_query_arg(array('page' => 'zw-policy-rules', 'zw_msg' => 'rule_batch_invalid'), admin_url('admin.php')));
        exit;
    }
    $parts = explode('|', $op, 2);
    $action = sanitize_key($parts[0]);
    $decision = isset($parts[1]) ? sanitize_key($parts[1]) : '';
    $allowed = array('request_enable', 'first_review', 'second_review', 'disable');
    if (!in_array($action, $allowed, true)) wp_die('不支持的批量动作');
    $operator = wp_get_current_user()->user_login;
    $success = array(); $failed = array();
    foreach ($ids as $id) {
        $result = zw_policy_platform_rule_action_core($id, $action, $decision, $operator);
        if (is_wp_error($result)) { $failed[] = array('id' => $id, 'error' => $result->get_error_message()); }
        else { $success[] = $id; }
    }
    if (!empty($ids)) {
        zw_policy_platform_audit_insert('RULE_BATCH_OPERATION', 'policy_rule', $ids, array(), array('action' => $action, 'decision' => $decision, 'success' => count($success), 'failed' => count($failed)), $operator, '批量规则操作');
    }
    wp_safe_redirect(add_query_arg(array(
        'page' => 'zw-policy-rules', 'zw_msg' => 'rule_batch_done',
        'zw_ok' => count($success), 'zw_fail' => count($failed),
    ), admin_url('admin.php')));
    exit;
}

/* ───────────── 后台菜单 ───────────── */
add_action('admin_menu', 'zw_policy_platform_admin_menu', 61);
function zw_policy_platform_admin_menu() {
    add_menu_page(
        ZW_AI_LEGAL_NAME,
        ZW_AI_LEGAL_NAME,
        'tcpc_perm_legal',
        'zw-policy-platform',
        'zw_policy_platform_page_overview',
        'dashicons-shield-alt',
        60
    );

    $items = array(
        'zw-policy-sources'        => array('政策源文件', 'zw_policy_platform_page_sources'),
        'zw-policy-content-review' => array('内容审核', 'zw_policy_platform_page_content_review'),
        'zw-policy-clauses'        => array('政策条款库', 'zw_policy_platform_page_clauses'),
        'zw-policy-topics'         => array('政策主题与关系', 'zw_policy_platform_page_topics'),
        'zw-policy-applicability'  => array('适用性配置', 'zw_policy_platform_page_applicability'),
        'zw-policy-rules'          => array('执行规则库', 'zw_policy_platform_page_rules'),
        'zw-policy-assets'         => array('派生资产库', 'zw_policy_platform_page_assets'),
        'zw-policy-issues'         => array('整改台账', 'zw_policy_platform_page_issues'),
        'zw-policy-supervision'    => array('监督看板', 'zw_policy_platform_page_supervision'),
    );
    foreach ($items as $slug => $item) {
        add_submenu_page('zw-policy-platform', $item[0], $item[0], 'tcpc_perm_legal', $slug, $item[1]);
    }
}

/* ───────────── 首页总览 ───────────── */
function zw_policy_platform_page_overview() {
    global $wpdb;
    zw_policy_platform_page_header(ZW_AI_LEGAL_NAME, '本平台是 tcpc 主题后台功能；当前为后台菜单与只读总览第一阶段，写操作与智能体能力后续逐步迁入，不修改已开发功能。');
    if (!zw_policy_platform_ready()) {
        zw_policy_platform_missing_notice();
        zw_policy_platform_page_footer();
        return;
    }
    $sources = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_source'));
    $clauses = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_clause'));
    $confirmed = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_clause') . " WHERE status='已确认'");
    $rules = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_rule'));
    $enabled = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_rule') . ' WHERE enabled=1');
    $issues = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_issue'));
    $openIssues = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_issue') . " WHERE status<>'已关闭'");
    $audit = zw_policy_platform_exists('policy_audit_log') ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_audit_log')) : 0;
    $control = zw_policy_platform_exists('policy_control_log') ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_control_log')) : 0;
    ?>
    <div class="zw-adm-grid zw-adm-g4" style="margin:16px 0 18px">
        <div class="zw-adm-kpi red"><div class="l">政策源文件</div><div class="n"><?php echo esc_html($sources); ?></div></div>
        <div class="zw-adm-kpi blue"><div class="l">政策条款</div><div class="n"><?php echo esc_html($clauses); ?><small style="font-size:13px;color:#646970"> / 已确认 <?php echo esc_html($confirmed); ?></small></div></div>
        <div class="zw-adm-kpi green"><div class="l">执行规则</div><div class="n"><?php echo esc_html($rules); ?><small style="font-size:13px;color:#646970"> / 已启用 <?php echo esc_html($enabled); ?></small></div></div>
        <div class="zw-adm-kpi warn"><div class="l">整改工单</div><div class="n"><?php echo esc_html($issues); ?><small style="font-size:13px;color:#646970"> / 未关闭 <?php echo esc_html($openIssues); ?></small></div></div>
        <div class="zw-adm-kpi"><div class="l">审计日志</div><div class="n"><?php echo esc_html($audit); ?></div></div>
        <div class="zw-adm-kpi"><div class="l">控制点日志</div><div class="n"><?php echo esc_html($control); ?></div></div>
    </div>
    <?php
    $risk = $wpdb->get_results('SELECT risk_level, redline_flag, COUNT(*) AS c FROM ' . zw_policy_platform_table('policy_clause') . ' GROUP BY risk_level, redline_flag ORDER BY c DESC', ARRAY_A);
    $recent = $wpdb->get_results('SELECT action, target_type, operator, create_time FROM ' . zw_policy_platform_table('policy_audit_log') . ' ORDER BY create_time DESC LIMIT 10', ARRAY_A);
    echo '<div class="zw-adm-sec">风险 / 红线分布</div>';
    zw_policy_platform_render_table(array('风险等级', '红线', '数量'), array_map(function ($r) {
        return array($r['risk_level'], $r['redline_flag'], $r['c']);
    }, $risk));
    echo '<div class="zw-adm-sec" style="margin-top:22px">最近操作审计</div>';
    zw_policy_platform_render_table(array('动作', '对象', '操作人', '时间'), array_map(function ($r) {
        return array($r['action'], $r['target_type'], $r['operator'], $r['create_time']);
    }, $recent));
    zw_policy_platform_page_footer();
}

/* ───────────── 政策源文件 ───────────── */
function zw_policy_platform_page_sources() {
    global $wpdb;
    zw_policy_platform_page_header('政策源文件', '政策源文件列表（只读，支持类型/状态筛选）。');
    if (!zw_policy_platform_exists('policy_source')) { zw_policy_platform_missing_notice(); zw_policy_platform_page_footer(); return; }

    $f_type = isset($_GET['source_type']) ? sanitize_text_field(wp_unslash($_GET['source_type'])) : '';
    $f_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    $base = admin_url('admin.php?page=zw-policy-sources');
    $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $per = 20;
    $offset = ($paged - 1) * $per;
    $where = array(); $params = array();
    if ($f_type !== '') { $where[] = 'source_type=%s'; $params[] = $f_type; }
    if ($f_status !== '') { $where[] = 'status=%s'; $params[] = $f_status; }
    $where_sql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
    $table = zw_policy_platform_table('policy_source');
    $count_sql = 'SELECT COUNT(*) FROM ' . $table . $where_sql;
    $total = (int) (!empty($params) ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : $wpdb->get_var($count_sql));
    $sql = 'SELECT id, doc_name, issuer, doc_no, source_type, confidentiality_level, status, review_status, update_time FROM ' . $table . $where_sql . ' ORDER BY update_time DESC LIMIT %d OFFSET %d';
    $query_params = array_merge($params, array($per, $offset));
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$query_params), ARRAY_A);

    $types = array('' => '全部类型', '公开法规' => '公开法规', '内部文件' => '内部文件', '内部制度' => '内部制度');
    $statuses = array('' => '全部状态', '已生效' => '已生效', '已废止' => '已废止', '草案' => '草案');
    echo '<form method="get" class="zw-adm-card" style="padding:12px 14px;margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">';
    echo '<input type="hidden" name="page" value="zw-policy-sources">';
    echo '<label style="font-size:12px;color:#666">类型<br><select name="source_type">';
    foreach ($types as $k => $v) echo '<option value="' . esc_attr($k) . '"' . selected($f_type, $k, false) . '>' . esc_html($v) . '</option>';
    echo '</select></label>';
    echo '<label style="font-size:12px;color:#666">状态<br><select name="status">';
    foreach ($statuses as $k => $v) echo '<option value="' . esc_attr($k) . '"' . selected($f_status, $k, false) . '>' . esc_html($v) . '</option>';
    echo '</select></label>';
    echo '<button class="button button-primary">查询</button>';
    echo '<a class="button" href="' . esc_url($base) . '">重置</a>';
    echo '<span style="flex:1"></span><span style="font-size:12px;color:#999">共 ' . (int) $total . ' 条</span>';
    echo '</form>';

    zw_policy_platform_render_table(array('ID', '文件名称', '发布机关', '文号', '类型', '密级', '状态', '复核状态', '更新时间'), array_map(function ($r) {
        return array(
            $r['id'], $r['doc_name'], $r['issuer'], $r['doc_no'], $r['source_type'], $r['confidentiality_level'],
            zw_policy_platform_badge_cell(zw_policy_platform_status_type($r['status']), $r['status']),
            zw_policy_platform_badge_cell(zw_policy_platform_status_type($r['review_status']), $r['review_status']),
            $r['update_time']
        );
    }, $rows));
    zw_policy_platform_pager($total, $per, $paged, $base, array_filter(array('source_type' => $f_type, 'status' => $f_status)));
    zw_policy_platform_page_footer();
}

/* ───────────── 政策条款库 ───────────── */
function zw_policy_platform_page_clauses() {
    global $wpdb;
    zw_policy_platform_page_header('政策条款库', '政策条款列表（写操作第一阶段：单条确认/退回；最多显示 300 条）。');
    if (!zw_policy_platform_exists('policy_clause')) { zw_policy_platform_missing_notice(); zw_policy_platform_page_footer(); return; }
    // TASK-1019：条款表已降级为「生效正文的派生视图」，本页提示口径（样式用既有 zw-adm-card，不新增全局 CSS）。
    echo '<div class="zw-adm-card" style="padding:12px 14px;margin-bottom:14px;border-left:3px solid var(--primary-color,#d80505)">'
        . '<p style="margin:0 0 6px"><strong>条款为派生视图</strong>：正文以 <strong>AI 法务部 → 文档中心</strong> 的<b>生效版本</b>为准；'
        . '如需修改正文，请在文档中心<strong>新建版本</strong>并<strong>确认生效</strong>，生效后条款会自动派生同步。</p>'
        . '<p style="margin:0;color:#666">本页的「确认 / 退回」仅用于 T1b 登记入口（条款初稿评审），<strong>不会改变已生效正文</strong>；'
        . '派生方向为单向：正文 → 条款，禁止反写。</p>'
        . '</div>';

    // ── TASK-1548：派生对账（解析 N / 已确认 M / 差异 K）—— 让覆盖率缺口一眼可见（TASK-1537 登记的后续项）。
    // 复用 canonical 对账 `zw_legal_text_verify_clauses()`，本页**不新造第二套判定**；只读展示，不写任何数据。
    if (function_exists('zw_legal_text_verify_clauses') && function_exists('zw_legal_text_documents')) {
        // TASK-1559：只读筛选（'' = 全部；'diff' = 仅有差异）—— 只影响渲染，不影响对账判定。
        $recon_filter = isset($_GET['recon']) ? sanitize_key((string) wp_unslash($_GET['recon'])) : '';
        if (!in_array($recon_filter, array('', 'diff'), true)) { $recon_filter = ''; }
        $confirmed_by_source = array();
        $cc_rows = $wpdb->get_results($wpdb->prepare("SELECT source_id, COUNT(*) AS n FROM {$table} WHERE status=%s GROUP BY source_id", '已确认'), ARRAY_A);
        foreach ((array) $cc_rows as $cc) {
            $confirmed_by_source[(string) ($cc['source_id'] ?? '')] = (int) ($cc['n'] ?? 0);
        }
        $recon_rows    = array();
        $recon_all     = 0;                     // 全部文档数（不受筛选影响）
        $recon_diff_docs = 0;                   // 有差异的文档数
        $recon_failure_text = array();          // 只读差异清单文本（供整段复制）
        $sum_parsed    = 0;
        $sum_confirmed = 0;
        $sum_diff      = 0;
        foreach ((array) zw_legal_text_documents() as $doc_row) {
            $did = (string) ($doc_row['id'] ?? '');
            if ($did === '') { continue; }
            $report    = zw_legal_text_verify_clauses($did);
            $parsed    = (int) ($report['count'] ?? 0);
            $confirmed = (int) ($confirmed_by_source[$did] ?? 0);
            $diff      = count((array) ($report['failures'] ?? array()));
            $excluded  = !empty($report['excluded']);
            $ok        = !empty($report['ok']);
            // TASK-1559：收集差异文本（只读；供"可整段复制"块）+ 统计 + 支持"仅有差异"筛选。
            $doc_failures = array();
            foreach ((array) ($report['failures'] ?? array()) as $zw_cr_one) {
                $zw_cr_one = trim((string) $zw_cr_one);
                if ($zw_cr_one !== '') { $doc_failures[] = $zw_cr_one; }
            }
            $recon_all++;
            if (!$excluded && $doc_failures) {
                $recon_diff_docs++;
                $recon_failure_text[] = '【' . (string) ($doc_row['title'] ?? $did) . '（' . $did . '，版本 ' . (string) ($report['version'] ?? '—') . '）】';
                foreach ($doc_failures as $zw_cr_one) { $recon_failure_text[] = '  - ' . $zw_cr_one; }
            }
            if ($recon_filter === 'diff' && ($excluded || !$doc_failures)) { continue; }
            $sum_parsed    += $parsed;
            $sum_confirmed += $confirmed;
            if (!$excluded) { $sum_diff += $diff; }
            if ($excluded) {
                $verdict = zw_policy_platform_badge_cell('off', '不适用（显式排除）');
            } elseif ($ok) {
                $verdict = zw_policy_platform_badge_cell('on', '一致');
            } else {
                // TASK-1554：差异明细 —— 直接展开 canonical 对账给出的失败项（本页**不新造第二套判定**）。
                $zw_cr_failures = array();
                foreach ((array) ($report['failures'] ?? array()) as $zw_cr_f) {
                    $zw_cr_f = trim((string) $zw_cr_f);
                    if ($zw_cr_f !== '') { $zw_cr_failures[] = $zw_cr_f; }
                }
                $zw_cr_badge = zw_policy_platform_badge_cell('err', '差异 ' . $diff);
                $verdict = array('html' => (string) ($zw_cr_badge['html'] ?? '')
                    . '<details style="margin-top:4px;max-width:420px"><summary style="cursor:pointer;color:#c62828;font-size:12px">查看差异明细（' . count($zw_cr_failures) . ' 项）</summary>'
                    . '<ol style="margin:6px 0 0 18px;color:#50575e;font-size:12px">'
                    . implode('', array_map(function ($zw_cr_one) { return '<li>' . esc_html($zw_cr_one) . '</li>'; }, array_slice($zw_cr_failures, 0, 20)))
                    . '</ol>'
                    . (count($zw_cr_failures) > 20 ? '<p style="margin:4px 0 0;color:#999;font-size:12px">仅显示前 20 项，共 ' . count($zw_cr_failures) . ' 项。</p>' : '')
                    . '</details>');
            }
            $recon_rows[] = array(
                array('html' => '<strong>' . esc_html((string) ($doc_row['title'] ?? $did)) . '</strong> <code style="font-size:11px;color:#888">' . esc_html($did) . '</code>'),
                esc_html($excluded ? '—' : (string) ($report['version'] ?? '—')),
                $excluded ? '—' : (string) $parsed,
                (string) $confirmed,
                $excluded ? '—' : (string) $diff,
                $verdict,
            );
        }
        echo '<div class="zw-adm-sec" style="margin:18px 0 8px">派生对账（解析 N / 已确认 M / 差异 K）</div>';
        // TASK-1559：只读筛选 tab + 只读差异清单（可整段复制贴工单）；不新增写端点、不改对账判定。
        $recon_base = admin_url('admin.php?page=zw-policy-clauses');
        echo '<div class="zw-adm-tabbar" style="margin:0 0 10px">'
            . '<a' . ($recon_filter === '' ? ' class="on"' : '') . ' href="' . esc_url($recon_base) . '">全部 (' . (int) $recon_all . ')</a>'
            . '<a' . ($recon_filter === 'diff' ? ' class="on"' : '') . ' href="' . esc_url(add_query_arg('recon', 'diff', $recon_base)) . '">仅有差异 (' . (int) $recon_diff_docs . ')</a>'
            . '</div>';
        echo '<div class="zw-adm-card" style="padding:12px 14px;margin-bottom:16px;overflow-x:auto">';
        echo '<p style="margin:0 0 8px;color:#666;font-size:12.5px">口径：对账 = 生效正文解析条数 vs 条款表「已确认」条数（同一稳定主键逐条比对文本与版本号）；'
            . '《章程》按铁律 19 显式排除（显示「不适用」）。合计：正文解析 <strong>' . (int) $sum_parsed . '</strong> 条 · 已确认 <strong>'
            . (int) $sum_confirmed . '</strong> 条 · 差异 <strong>' . (int) $sum_diff . '</strong> 项。</p>';
        // TASK-1562：每日巡检状态（只读展示；无记录时提示首次巡检时间）。
        $zw_recon_watch = function_exists('zw_policy_recon_watch_state') ? zw_policy_recon_watch_state() : array();
        if ($zw_recon_watch) {
            $zw_w_at = (int) ($zw_recon_watch['at'] ?? 0);
            echo '<p style="margin:0 0 8px;font-size:12.5px;color:' . (!empty($zw_recon_watch['diff_docs']) ? '#c62828' : '#2e7d32') . '">'
                . '每日巡检（TASK-1562）：最近一次 ' . esc_html($zw_w_at ? date_i18n('Y-m-d H:i', $zw_w_at) : '—')
                . ' · 文档 ' . (int) ($zw_recon_watch['docs'] ?? 0) . ' 篇 · 有差异 ' . (int) ($zw_recon_watch['diff_docs'] ?? 0)
                . ' 篇 / ' . (int) ($zw_recon_watch['diff_items'] ?? 0) . ' 项'
                . (!empty($zw_recon_watch['diff_docs']) ? ' ⇒ 请据下方差异明细同步条款' : '（无需处理）') . '</p>';
        } else {
            echo '<p style="margin:0 0 8px;font-size:12.5px;color:#999">每日巡检（TASK-1562）：尚无巡检记录（WP-Cron 每日 03:10 触发）。</p>';
        }
        if (function_exists('zw_policy_platform_render_table')) {
            zw_policy_platform_render_table(array('文档', '生效版本', '正文解析', '已确认', '差异', '结论'), $recon_rows);
        }
        if ($recon_failure_text) {
            echo '<p style="margin:10px 0 6px;color:#666;font-size:12.5px">差异清单（只读，可整段复制贴工单）：</p>'
                . '<textarea readonly rows="6" style="width:100%;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px">'
                . esc_textarea(implode("\n", $recon_failure_text)) . '</textarea>';
            // TASK-1559 第二阶段：受控 CSV 导出（admin_post + nonce；列白名单，无个人信息；写审计）。
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:8px 0 0">'
                . '<input type="hidden" name="action" value="zw_policy_recon_export">'
                . wp_nonce_field('zw_policy_recon_export', '_wpnonce', true, false)
                . '<button type="submit" class="button button-small">导出差异清单 CSV</button>'
                . ' <span style="color:#666;font-size:12px">仅含文档标识与差异文案，不含任何个人信息字段</span></form>';
        } elseif ($recon_filter === 'diff') {
            echo '<p style="margin:10px 0 0;color:#2e7d32;font-size:12.5px">当前没有差异（所有生效正文与条款表一致）。</p>';
        }
        echo '</div>';
    }
    if (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'clause_reviewed') {
        echo '<div class="notice notice-success is-dismissible"><p>政策条款复核已保存。</p></div>';
    }
    if (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'batch_reviewed') {
        echo '<div class="notice notice-success is-dismissible"><p>政策条款批量复核已保存。</p></div>';
    }
    if (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'batch_invalid') {
        echo '<div class="notice notice-error is-dismissible"><p>请选择条款并指定批量动作。</p></div>';
    }

    $f_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    $f_risk   = isset($_GET['risk']) ? sanitize_text_field(wp_unslash($_GET['risk'])) : '';
    $f_redline= isset($_GET['redline']) ? sanitize_text_field(wp_unslash($_GET['redline'])) : '';
    $base = admin_url('admin.php?page=zw-policy-clauses');
    $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $per = 20;
    $offset = ($paged - 1) * $per;

    echo '<div class="zw-adm-tabbar">';
    foreach (array('' => '全部', '已确认' => '已确认', '待复核' => '待复核') as $k => $label) {
        $url = $k === '' ? $base : add_query_arg('status', $k, $base);
        echo '<a' . ((string) $f_status === (string) $k ? ' class="on"' : '') . ' href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';

    $where = array();
    $params = array();
    if ($f_status !== '') { $where[] = 'status=%s'; $params[] = $f_status; }
    if ($f_risk !== '') { $where[] = 'risk_level=%s'; $params[] = $f_risk; }
    if ($f_redline !== '') { $where[] = 'redline_flag=%s'; $params[] = $f_redline; }
    $where_sql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
    $table = zw_policy_platform_table('policy_clause');
    $count_sql = 'SELECT COUNT(*) FROM ' . $table . $where_sql;
    $total = (int) (!empty($params) ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : $wpdb->get_var($count_sql));
    $sql = 'SELECT id, source_id, article_no, business_area, risk_level, redline_flag, responsible_dept, status, LEFT(original_text,80) AS summary FROM ' . $table . $where_sql . ' ORDER BY source_id, article_no LIMIT %d OFFSET %d';
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, array($per, $offset))), ARRAY_A);

    $riskOptions = array('' => '全部风险', '重大' => '重大', '高' => '高', '中' => '中', '低' => '低');
    $redOptions = array('' => '全部红线', '是' => '是', '否' => '否');
    echo '<form method="get" class="zw-adm-card" style="padding:12px 14px;margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">';
    echo '<input type="hidden" name="page" value="zw-policy-clauses">';
    echo '<label style="font-size:12px;color:#666">风险等级<br><select name="risk">';
    foreach ($riskOptions as $k => $v) echo '<option value="' . esc_attr($k) . '"' . selected($f_risk, $k, false) . '>' . esc_html($v) . '</option>';
    echo '</select></label>';
    echo '<label style="font-size:12px;color:#666">红线<br><select name="redline">';
    foreach ($redOptions as $k => $v) echo '<option value="' . esc_attr($k) . '"' . selected($f_redline, $k, false) . '>' . esc_html($v) . '</option>';
    echo '</select></label>';
    echo '<button class="button button-primary">查询</button>';
    echo '<a class="button" href="' . esc_url($base) . '">重置</a>';
    echo '<span style="flex:1"></span><span style="font-size:12px;color:#999">共 ' . (int) $total . ' 条</span>';
    echo '</form>';

    $nonce = wp_create_nonce('zw_policy_clause_batch_review');
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="zw_policy_clause_batch_review">';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
    echo '<div class="zw-adm-card" style="padding:12px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
    echo '<select name="decision"><option value="">批量动作</option><option value="CONFIRMED">批量确认</option><option value="REJECTED">批量退回</option></select>';
    echo '<button class="button button-primary">执行</button>';
    echo '<span style="font-size:12px;color:#999">勾选下方条款后执行批量复核</span>';
    echo '</div>';
    $check_all = '<label class="zw-adm-touch"><input type="checkbox" onclick="var c=this;document.querySelectorAll(\'.zw-policy-cb\').forEach(function(x){x.checked=c.checked;})"></label>';
    zw_policy_platform_render_table(array(array('raw' => $check_all), 'ID', '来源', '条号', '业务领域', '风险', '红线', '责任部门', '状态', '摘要', '操作'), array_map(function ($r) {
        $cb = '<label class="zw-adm-touch"><input type="checkbox" class="zw-policy-cb" name="ids[]" value="' . esc_attr($r['id']) . '"></label>';
        return array(
            array('raw' => $cb), $r['id'], $r['source_id'], $r['article_no'], $r['business_area'],
            zw_policy_platform_badge_cell(zw_policy_platform_status_type($r['risk_level']), $r['risk_level']),
            zw_policy_platform_badge_cell(zw_policy_platform_status_type($r['redline_flag']), $r['redline_flag']),
            $r['responsible_dept'],
            zw_policy_platform_badge_cell(zw_policy_platform_status_type($r['status']), $r['status']),
            $r['summary'],
            array('html' => zw_policy_platform_review_link($r['id'], 'CONFIRMED', '确认') . ' ' . zw_policy_platform_review_link($r['id'], 'REJECTED', '退回', 'button button-small button-link-delete'))
        );
    }, $rows));
    echo '</form>';
    zw_policy_platform_pager($total, $per, $paged, $base, array_filter(array('status' => $f_status, 'risk' => $f_risk, 'redline' => $f_redline)));
    zw_policy_platform_page_footer();
}

/* ───────────── 政策主题与关系 ───────────── */
function zw_policy_platform_page_topics() {
    global $wpdb;
    zw_policy_platform_page_header('政策主题与关系', '政策主题与关系数据（只读）。');
    if (!zw_policy_platform_exists('policy_topic') || !zw_policy_platform_exists('policy_relation')) { zw_policy_platform_missing_notice(); zw_policy_platform_page_footer(); return; }
    $topics = $wpdb->get_results('SELECT id, name, parent_id, status, update_time FROM ' . zw_policy_platform_table('policy_topic') . ' ORDER BY update_time DESC LIMIT 200', ARRAY_A);
    $relations = $wpdb->get_results('SELECT id, from_type, from_id, to_type, to_id, relation_type, status FROM ' . zw_policy_platform_table('policy_relation') . ' ORDER BY update_time DESC LIMIT 200', ARRAY_A);
    echo '<div class="zw-adm-sec">政策主题</div>';
    zw_policy_platform_render_table(array('ID', '主题名称', '父级', '状态', '更新时间'), array_map(function ($r) {
        return array($r['id'], $r['name'], $r['parent_id'], $r['status'], $r['update_time']);
    }, $topics));
    echo '<div class="zw-adm-sec" style="margin-top:22px">政策关系</div>';
    zw_policy_platform_render_table(array('ID', '起点', '终点', '关系', '状态'), array_map(function ($r) {
        return array($r['id'], $r['from_type'] . ':' . $r['from_id'], $r['to_type'] . ':' . $r['to_id'], $r['relation_type'], $r['status']);
    }, $relations));
    zw_policy_platform_page_footer();
}

/* ───────────── 适用性配置（TASK-1598 / SPEC-93 · T7 / R-12①） ───────────── */

if (!function_exists('zw_policy_applicability_lib_ready')) {
    /**
     * 键校验库是否可用（唯一出处：T7 迁移脚本的 `zw_policy_rule_modules_*`）。
     *
     * 缺任一 ⇒ 页面 fail-closed 到「只读 + 禁止保存」，绝不降级为「无校验写入」。
     *
     * @return bool
     */
    function zw_policy_applicability_lib_ready() {
        return function_exists('zw_policy_rule_modules_validate')
            && function_exists('zw_policy_rule_modules_decode')
            && function_exists('zw_policy_module_keys')
            && function_exists('zw_policy_feature_keys');
    }
}

if (!function_exists('zw_policy_applicability_can_manage')) {
    /**
     * 配置适用模块的权限判定（唯一出处；`admin_post` 处理器与页面共用）。
     *
     * @return bool
     */
    function zw_policy_applicability_can_manage() {
        if (!function_exists('current_user_can')) { return false; }
        return (bool) (current_user_can('manage_options') || current_user_can('tcpc_perm_legal'));
    }
}

if (!function_exists('zw_policy_applicability_registry_groups')) {
    /**
     * 可选键分组（域键 → 该域下的功能点键）：唯一出处 T2 注册表。
     *
     * @return array<string,array{label:string,features:array<string,string>}>
     */
    function zw_policy_applicability_registry_groups() {
        $groups = array();
        foreach ((array) zw_policy_module_keys() as $key => $label) {
            $key = strtolower(trim((string) $key));
            if ($key !== '') { $groups[$key] = array('label' => (string) $label, 'features' => array()); }
        }
        foreach ((array) zw_policy_feature_keys() as $key => $label) {
            $key    = strtolower(trim((string) $key));
            $dot    = strpos($key, '.');
            $domain = $dot === false ? '' : substr($key, 0, $dot);
            if ($key === '' || $domain === '') { continue; }
            if (!isset($groups[$domain])) { $groups[$domain] = array('label' => $domain, 'features' => array()); }
            $groups[$domain]['features'][$key] = (string) $label;
        }
        return $groups;
    }
}

if (!function_exists('zw_policy_applicability_validate')) {
    /**
     * 校验一组键（**fail-closed**）—— 薄封装，唯一实现在 T7 迁移脚本库内。
     *
     * @param mixed $keys
     * @return array 见 `zw_policy_rule_modules_validate()`。
     */
    function zw_policy_applicability_validate($keys) {
        if (!zw_policy_applicability_lib_ready()) {
            return array(
                'ok' => false, 'keys' => array(), 'unknown' => array(), 'json' => '', 'length' => 0,
                'max' => (defined('ZW_POLICY_RULE_MODULES_MAX_CHARS') ? (int) ZW_POLICY_RULE_MODULES_MAX_CHARS : 128),
                'error_code' => 'lib_missing',
                'error' => '键校验模块未加载（scripts/migrate-policy-rule-modules.php），已禁止保存（fail-closed）。',
            );
        }
        return zw_policy_rule_modules_validate($keys);
    }
}

if (!function_exists('zw_policy_applicability_decode')) {
    /**
     * 把 `business_module` 原值解码为合法键（唯一判定出处：引擎 `zw_policy_business_module_tokens()`）。
     *
     * @param mixed $raw
     * @return array<int,string>
     */
    function zw_policy_applicability_decode($raw) {
        if (function_exists('zw_policy_rule_modules_decode')) {
            return array_values((array) zw_policy_rule_modules_decode($raw));
        }
        return array();
    }
}

if (!function_exists('zw_policy_applicability_rules')) {
    /**
     * 规则行（供逐条配置与待办计数）。
     *
     * @return array<int,array>
     */
    function zw_policy_applicability_rules() {
        global $wpdb;
        if (!zw_policy_platform_exists('policy_rule')) { return array(); }
        $rows = $wpdb->get_results(
            'SELECT id, rule_code, rule_name, rule_type, enabled, business_module, update_time FROM '
            . zw_policy_platform_table('policy_rule') . ' ORDER BY rule_type, rule_code',
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('zw_policy_applicability_summary')) {
    /**
     * 聚合：①每个键被多少条规则引用；②「未配置适用模块」的规则清单（R-12① 待办）。
     *
     * 口径：`zw_policy_applicability_decode()` 为空 ⇒ 未配置（含中文旧值 / 越界键 / 空值）。
     *
     * @param array<int,array> $rows 规则行。
     * @return array{usage:array<string,int>,unconfigured:array<int,array>,total:int}
     */
    function zw_policy_applicability_summary($rows) {
        $usage = array();
        $unconfigured = array();
        $total = 0;
        foreach ((array) $rows as $row) {
            $row = (array) $row;
            $total++;
            $keys = zw_policy_applicability_decode($row['business_module'] ?? '');
            if (!$keys) {
                $unconfigured[] = array(
                    'id'        => (string) ($row['id'] ?? ''),
                    'rule_code' => (string) ($row['rule_code'] ?? ''),
                    'rule_name' => (string) ($row['rule_name'] ?? ''),
                    'raw'       => (string) ($row['business_module'] ?? ''),
                );
                continue;
            }
            foreach ($keys as $key) {
                $usage[$key] = isset($usage[$key]) ? $usage[$key] + 1 : 1;
            }
        }
        return array('usage' => $usage, 'unconfigured' => $unconfigured, 'total' => $total);
    }
}

if (!function_exists('zw_policy_applicability_scope_label')) {
    /**
     * 「当前生效范围」的中文（人话）标签 —— TASK-1655 · A / D。
     *
     * 口径：**只出中文名**，键名由调用方放进 `title` 属性 / 高级模式（D）。
     * 未配置（解不出任何合法键）⇒ 固定文案「未配置 ⇒ 不触发」（与引擎 `zw_policy_rule_module_match()`
     * 的「未配置 = 不触发」硬口径一致，不臆造）。
     *
     * @param array<int,string> $keys 已解码的合法键。
     * @return string
     */
    function zw_policy_applicability_scope_label($keys) {
        $keys = (array) $keys;
        if (!$keys) {
            return '未配置 ⇒ 不触发';
        }
        $domains  = function_exists('zw_policy_module_keys') ? (array) zw_policy_module_keys() : array();
        $features = function_exists('zw_policy_feature_keys') ? (array) zw_policy_feature_keys() : array();
        $labels   = array();
        foreach ($keys as $key) {
            $key = strtolower(trim((string) $key));
            if ($key === '') { continue; }
            if (isset($features[$key])) {
                $labels[] = (string) $features[$key];
            } elseif (isset($domains[$key])) {
                $labels[] = (string) $domains[$key] . '（整个域）';
            }
        }
        if (!$labels) {
            return '未配置 ⇒ 不触发';
        }
        return implode(' / ', array_values(array_unique($labels)));
    }
}

if (!function_exists('zw_policy_applicability_recommendation')) {
    /**
     * 某条规则的推荐配置（TASK-1655 · B）—— 薄封装，唯一出处 `zw_policy_recommended_modules_for_rule()`。
     *
     * 返回形状固定为 `{state,keys,hint}`：
     *  - `state = 'ready'`   推荐非空 ⇒ 渲染【应用推荐配置】按钮（只填表单、不提交）；
     *  - `state = 'empty'`   已知但无推荐（占位规则）⇒ 提示「建议先指派到具体模块」，**不渲染按钮**；
     *  - `state = 'unknown'` 未核实 ⇒ 提示「未核实推荐映射（不臆造）」，**不渲染按钮**；
     *  - `state = 'unavailable'` 推荐注册表不可用 ⇒ 提示「推荐映射不可用」，**不渲染按钮**。
     *
     * @param array|object $row 规则行。
     * @return array{state:string,keys:array<int,string>,hint:string}
     */
    function zw_policy_applicability_recommendation($row) {
        if (!function_exists('zw_policy_recommended_modules_for_rule')) {
            return array('state' => 'unavailable', 'keys' => array(), 'hint' => '推荐映射注册表不可用（`inc/policy-platform/policy-module-map.php` 未加载），已隐藏推荐入口。');
        }
        $rec = zw_policy_recommended_modules_for_rule($row);
        if ($rec === null) {
            return array('state' => 'unknown', 'keys' => array(), 'hint' => '该规则的推荐映射未核实（不臆造）：请人工指派到具体模块。');
        }
        if (!$rec) {
            return array('state' => 'empty', 'keys' => array(), 'hint' => '该规则的业务场景仍是占位串（待业务场景映射）：建议先指派到具体模块，再回来勾选。');
        }
        return array('state' => 'ready', 'keys' => array_values($rec), 'hint' => '');
    }
}

/* ───────────── 总开关与灰度（TASK-1655 · E） ───────────── */

if (!function_exists('zw_policy_gate_key_choices')) {
    /**
     * 总开关区的可选键（唯一出处 = 本文件的域键 / 功能点键注册表；**禁止**在界面里写死键名）。
     *
     * @return array{modules:array<string,string>,features:array<string,string>}
     */
    function zw_policy_gate_key_choices() {
        $modules  = function_exists('zw_policy_module_keys') ? (array) zw_policy_module_keys() : array();
        $features = function_exists('zw_policy_feature_keys') ? (array) zw_policy_feature_keys() : array();
        return array('modules' => $modules, 'features' => $features);
    }
}

if (!function_exists('zw_policy_gate_level')) {
    /**
     * 灰度层级推导（SPEC-93 §十四 · 纯函数，只读；**不改判定语义**）。
     *
     * 阶梯（互斥，取最高命中）：
     *  - **L0** 未开：全局开关 off（含未设置）⇒ 引擎与到龄扫描零查询零写入；
     *  - **L4** 含到龄扫描：全局开 **且** `zw_policy_age_retire_scan` on（R-13 到龄派生标记同时生效）；
     *  - **L3** 多功能点：全局开，功能点白名单 ≥ 2 项；
     *  - **L2** 单功能点：全局开，功能点白名单恰 1 项；
     *  - **L1** 全局开 · 无功能点白名单：全局开，功能点白名单为空（`narrowed=true` 表示另设了域白名单收窄）。
     *
     * 白名单 token 归一复用 canonical `zw_policy_option_tokens()`（数组 / JSON 串 / 逗号或空白串三形态）。
     *
     * @param mixed $enabled  全局开关原值。
     * @param mixed $modules  域白名单原值。
     * @param mixed $features 功能点白名单原值。
     * @param mixed $age_scan 到龄扫描开关原值。
     * @return array{level:string,label:string,detail:string,narrowed:bool}
     */
    function zw_policy_gate_level($enabled, $modules = array(), $features = array(), $age_scan = false) {
        $is_on = function ($value) {
            return function_exists('zw_policy_option_is_on') && zw_policy_option_is_on($value);
        };
        $tokens = function ($value) {
            return function_exists('zw_policy_option_tokens') ? zw_policy_option_tokens($value) : array();
        };

        if (!$is_on($enabled)) {
            return array(
                'level'    => 'L0',
                'label'    => 'L0 未开',
                'detail'   => '全局开关 off（或未设置）⇒ 引擎与到龄扫描零查询零写入，这是默认态。',
                'narrowed' => false,
            );
        }

        $mods = $tokens($modules);
        $feat = $tokens($features);

        if ($is_on($age_scan)) {
            return array(
                'level'    => 'L4',
                'label'    => 'L4 含到龄扫描',
                'detail'   => '全局开 + 到龄扫描 on ⇒ 全域规则与到龄派生标记同时生效（R-13）。',
                'narrowed' => false,
            );
        }
        if (count($feat) >= 2) {
            return array(
                'level'    => 'L3',
                'label'    => 'L3 多功能点',
                'detail'   => '全局开 + 功能点白名单 ' . count($feat) . ' 项（' . implode('、', $feat) . '）⇒ 仅这些功能点生效。',
                'narrowed' => false,
            );
        }
        if (count($feat) === 1) {
            return array(
                'level'    => 'L2',
                'label'    => 'L2 单功能点',
                'detail'   => '全局开 + 功能点白名单 1 项（' . $feat[0] . '）⇒ 单功能点先行。',
                'narrowed' => false,
            );
        }
        return array(
            'level'    => 'L1',
            'label'    => 'L1 全局开 · 无功能点白名单',
            'detail'   => $mods
                ? '全局开 + 域白名单 ' . count($mods) . ' 项（' . implode('、', $mods) . '），未设功能点白名单 ⇒ 这些域全域生效。'
                : '全局开，域 / 功能点白名单均为空 ⇒ 全域规则生效（不推荐直接跳到本级）。',
            'narrowed' => (bool) $mods,
        );
    }
}

if (!function_exists('zw_policy_gate_option_state')) {
    /**
     * 四个 canonical option 的**当前状态**（只读；含层级推导与「每个键当前 ON/off」的展示串）。
     *
     * @return array<string,mixed>
     */
    function zw_policy_gate_option_state() {
        $get = function ($name, $default) {
            return function_exists('get_option') ? get_option($name, $default) : $default;
        };
        $raw = array(
            'zw_policy_gate_enabled'      => $get('zw_policy_gate_enabled', '0'),
            'zw_policy_gate_modules'      => $get('zw_policy_gate_modules', array()),
            'zw_policy_gate_features'     => $get('zw_policy_gate_features', array()),
            'zw_policy_age_retire_scan'   => $get('zw_policy_age_retire_scan', '0'),
        );

        $enabled  = zw_policy_option_is_on($raw['zw_policy_gate_enabled']);
        $age_scan = zw_policy_option_is_on($raw['zw_policy_age_retire_scan']);
        $modules  = zw_policy_option_tokens($raw['zw_policy_gate_modules']);
        $features = zw_policy_option_tokens($raw['zw_policy_gate_features']);

        $display_value = function ($value) {
            if (is_array($value)) {
                return $value ? (string) wp_json_encode(array_values($value)) : '（空）';
            }
            $text = trim((string) $value);
            return $text === '' ? '（空）' : $text;
        };

        return array(
            'enabled'  => $enabled,
            'age_scan' => $age_scan,
            'modules'  => $modules,
            'features' => $features,
            'display'  => array(
                'zw_policy_gate_enabled'    => array('on' => $enabled,  'label' => $enabled ? 'ON' : 'off',  'value' => $display_value($raw['zw_policy_gate_enabled'])),
                'zw_policy_gate_modules'    => array('on' => (bool) $modules, 'label' => $modules ? 'ON（' . count($modules) . ' 项）' : 'off', 'value' => $display_value($raw['zw_policy_gate_modules'])),
                'zw_policy_gate_features'   => array('on' => (bool) $features, 'label' => $features ? 'ON（' . count($features) . ' 项）' : 'off', 'value' => $display_value($raw['zw_policy_gate_features'])),
                'zw_policy_age_retire_scan' => array('on' => $age_scan, 'label' => $age_scan ? 'ON' : 'off', 'value' => $display_value($raw['zw_policy_age_retire_scan'])),
            ),
            'level'    => zw_policy_gate_level($raw['zw_policy_gate_enabled'], $raw['zw_policy_gate_modules'], $raw['zw_policy_gate_features'], $raw['zw_policy_age_retire_scan']),
        );
    }
}

if (!function_exists('zw_policy_gate_filter_keys')) {
    /**
     * 白名单键白名单化（**fail-closed**）：只保留 canonical 键表内的键，其余**静默丢弃**（不写入）。
     *
     * @param mixed                $keys  提交的键（数组）。
     * @param array<string,string> $legal canonical 键表。
     * @return array<int,string> 去重 + 升序（升序保证幂等比较稳定）。
     */
    function zw_policy_gate_filter_keys($keys, $legal) {
        $legal = array_change_key_case((array) $legal, CASE_LOWER);
        $out   = array();
        foreach ((array) $keys as $key) {
            if (!is_scalar($key)) { continue; }
            $token = strtolower(trim((string) $key));
            if ($token === '' || !isset($legal[$token]) || in_array($token, $out, true)) { continue; }
            $out[] = $token;
        }
        sort($out);
        return $out;
    }
}

if (!function_exists('zw_policy_gate_save')) {
    /**
     * 保存总开关与灰度（**核心**，可测；TASK-1655 · E）。
     *
     * 安全与纪律：
     *  1. 能力位（`manage_options` 或 `tcpc_perm_legal`）—— 不足 ⇒ `WP_Error`；
     *  2. 注册表缺失 ⇒ **fail-closed**（`WP_Error`，绝不把空键表当成「全域」写入）；
     *  3. 白名单键**只从 canonical 键表取**（越界键静默丢弃，不写入、不报错刷屏）；
     *  4. **默认仍 off**：本函数只在用户显式保存时写 option，且未勾选 = `'0'`；
     *     **代码里不存在任何「默认开」的写入**（由 `tests/test-policy-applicability.php` 守卫）；
     *  5. 幂等：四项与原值等价 ⇒ **零写入、零审计**；
     *  6. 留痕：复用既有 canonical 审计写入函数 `zw_policy_platform_audit_insert()`
     *     （`action = POLICY_GATE_UPDATE`，改前 / 改后逐项）；不可用时**如实返回未写原因**。
     *
     * @param array  $input    形状 `{enabled:bool, age_scan:bool, modules:array, features:array}`。
     * @param string $operator 操作者登录名。
     * @return array|WP_Error 成功：`{ok,changed,audit_written,audit_reason,level,before,after}`。
     */
    function zw_policy_gate_save($input, $operator = 'system') {
        if (!zw_policy_applicability_can_manage()) {
            return new WP_Error('gate_forbidden', '无权限');
        }
        if (!function_exists('zw_policy_module_keys') || !function_exists('zw_policy_feature_keys')) {
            return new WP_Error('gate_registry_missing', '域键 / 功能点键注册表未加载，已拒绝保存（fail-closed）。');
        }
        if (!function_exists('update_option')) {
            return new WP_Error('gate_no_update_option', 'update_option 不可用，已拒绝保存。');
        }

        $input  = (array) $input;
        $before = zw_policy_gate_option_state();

        $enabled  = !empty($input['enabled'])  ? '1' : '0';
        $age_scan = !empty($input['age_scan']) ? '1' : '0';
        $modules  = zw_policy_gate_filter_keys($input['modules'] ?? array(), zw_policy_module_keys());
        $features = zw_policy_gate_filter_keys($input['features'] ?? array(), zw_policy_feature_keys());

        $after_level = zw_policy_gate_level($enabled, $modules, $features, $age_scan);
        $before_raw  = array(
            'enabled'  => $before['enabled'] ? '1' : '0',
            'modules'  => $before['modules'],
            'features' => $before['features'],
            'age_scan' => $before['age_scan'] ? '1' : '0',
            'level'    => (string) $before['level']['level'],
        );
        $after_raw = array(
            'enabled'  => $enabled,
            'modules'  => $modules,
            'features' => $features,
            'age_scan' => $age_scan,
            'level'    => (string) $after_level['level'],
        );

        $same = ($before_raw['enabled'] === $after_raw['enabled'])
            && ($before_raw['age_scan'] === $after_raw['age_scan'])
            && ($before_raw['modules'] === $after_raw['modules'])
            && ($before_raw['features'] === $after_raw['features']);
        if ($same) {
            return array(
                'ok' => true, 'changed' => false, 'audit_written' => false, 'audit_reason' => '无变化，未写审计',
                'level' => (string) $after_level['level'], 'before' => $before_raw, 'after' => $after_raw,
            );
        }

        update_option('zw_policy_gate_enabled', $enabled);
        update_option('zw_policy_gate_modules', $modules);
        update_option('zw_policy_gate_features', $features);
        update_option('zw_policy_age_retire_scan', $age_scan);

        // 留痕：复用既有 canonical 审计写入函数；函数或审计表缺失 ⇒ 如实返回未写原因（不静默假装写过）。
        $audit_written = false;
        $audit_reason  = '无审计函数，未写';
        if (function_exists('zw_policy_platform_audit_insert')) {
            if (zw_policy_platform_exists('policy_audit_log')) {
                zw_policy_platform_audit_insert(
                    'POLICY_GATE_UPDATE',
                    'policy_option',
                    array('zw_policy_gate_enabled', 'zw_policy_gate_modules', 'zw_policy_gate_features', 'zw_policy_age_retire_scan'),
                    $before_raw,
                    $after_raw,
                    (string) $operator,
                    'TASK-1655 / SPEC-93 §十五：总开关与灰度更新（层级 ' . $before_raw['level'] . ' → ' . $after_raw['level'] . '）'
                );
                $audit_written = true;
                $audit_reason  = '';
            } else {
                $audit_reason = '审计表缺失，未写';
            }
        }

        return array(
            'ok' => true, 'changed' => true, 'audit_written' => $audit_written, 'audit_reason' => $audit_reason,
            'level' => (string) $after_level['level'], 'before' => $before_raw, 'after' => $after_raw,
        );
    }
}

if (!function_exists('zw_policy_applicability_save_rule')) {
    /**
     * 保存一条规则的适用模块（fail-closed + 留痕 + 双人审批复位）。
     *
     * 步骤（逐条对应 T7 卡「页面改动 / 校验 / 变更治理」）：
     *  1. 校验（键必须在 T2 注册表内；JSON > 128 字符、空集、越界键一律**拒绝**，禁止截断）；
     *  2. 目标与现状**等价 ⇒ 幂等跳过**（不写库、不重复审计）；
     *  3. `UPDATE business_module + update_time`（**不动** `enabled` / 表结构 / 其他字段）；
     *  4. 写 `zw_policy_audit_log`（改前 / 改后逐字段）；
     *  5. 原为**已启用**的规则 ⇒ 复用既有双人审批链路 `zw_policy_platform_rule_action_core()` 停用
     *     （此后须「申请启用 → 初审 → 终审」才能再次生效 ⇒ 单人无法自改自生效）。
     *
     * @param string $id       规则 ID。
     * @param mixed  $keys     勾选的键（数组）。
     * @param string $operator 操作者登录名。
     * @return array|WP_Error 成功：`{ok,changed,json,keys,reapproval}`。
     */
    function zw_policy_applicability_save_rule($id, $keys, $operator = 'system') {
        global $wpdb;
        $id = (string) $id;
        if ($id === '') {
            return new WP_Error('applicability_id', '规则参数错误');
        }
        if (!zw_policy_applicability_can_manage()) {
            return new WP_Error('applicability_forbidden', '无权限');
        }
        $table = zw_policy_platform_table('policy_rule');
        $rule  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%s", $id), ARRAY_A);
        if (!$rule) {
            return new WP_Error('applicability_rule_not_found', '执行规则不存在');
        }

        $check = zw_policy_applicability_validate($keys);
        if (empty($check['ok'])) {
            return new WP_Error('applicability_invalid', (string) $check['error']);
        }

        $before = zw_policy_applicability_decode($rule['business_module'] ?? '');
        $after  = array_values((array) $check['keys']);
        $same   = (count($before) === count($after) && !array_diff($before, $after) && !array_diff($after, $before));
        if ($same) {
            return array('ok' => true, 'changed' => false, 'json' => (string) $check['json'], 'keys' => $after, 'reapproval' => false);
        }

        $now  = gmdate('Y-m-d H:i:s');
        $data = array('business_module' => (string) $check['json'], 'update_time' => $now);
        $wpdb->update($table, $data, array('id' => $id));
        zw_policy_platform_audit_insert(
            'RULE_APPLICABILITY_UPDATE',
            'policy_rule',
            array($id),
            array('business_module' => (string) ($rule['business_module'] ?? '')),
            array('business_module' => (string) $check['json']),
            $operator,
            'TASK-1598 / SPEC-93 T7：适用模块由「' . (string) ($rule['business_module'] ?? '') . '」改为 ' . (string) $check['json']
        );

        // 已启用规则 ⇒ 复位到双人审批链路（停用；重新启用须 申请启用 → 初审 → 终审）。
        $reapproval = false;
        if ((int) ($rule['enabled'] ?? 0) === 1 && function_exists('zw_policy_platform_rule_action_core')) {
            $res = zw_policy_platform_rule_action_core($id, 'disable', '', $operator);
            $reapproval = !is_wp_error($res);
        }

        return array('ok' => true, 'changed' => true, 'json' => (string) $check['json'], 'keys' => $after, 'reapproval' => $reapproval);
    }
}

if (!function_exists('zw_policy_platform_handle_applicability_save')) {
    /**
     * `admin_post` 处理器：批量保存适用模块（能力位 → nonce → 逐条 fail-closed 校验 → 审计/审批）。
     *
     * @return void
     */
    function zw_policy_platform_handle_applicability_save() {
        // 安全四件套：能力位（403）→ nonce → 输入白名单（只收注册表内合法键）→ 逐条审计。
        if (!zw_policy_applicability_can_manage()) {
            wp_die('无权限', '', array('response' => 403));
            return;
        }
        check_admin_referer('zw_policy_applicability_save');

        $posted = isset($_POST['modules']) && is_array($_POST['modules']) ? wp_unslash($_POST['modules']) : array();
        $operator = (function_exists('wp_get_current_user') && wp_get_current_user())
            ? (string) wp_get_current_user()->user_login
            : 'system';

        $ok_count = 0; $skip_count = 0; $errors = array();
        foreach ($posted as $rule_id => $keys) {
            $rule_id = sanitize_text_field((string) $rule_id);
            if ($rule_id === '') { continue; }
            // 只收标量键；非数组（单键）按单元素处理。
            $keys = is_array($keys) ? $keys : array($keys);
            $clean = array();
            foreach ($keys as $key) {
                if (!is_scalar($key)) { continue; }
                $clean[] = sanitize_text_field((string) $key);
            }
            $res = zw_policy_applicability_save_rule($rule_id, $clean, $operator);
            if (is_wp_error($res)) {
                $errors[] = $rule_id . '：' . $res->get_error_message();
                continue;
            }
            if (!empty($res['changed'])) { $ok_count++; } else { $skip_count++; }
        }

        $args = array('page' => 'zw-policy-applicability');
        if ($errors) {
            $args['zw_msg'] = 'applicability_error';
            $args['zw_detail'] = rawurlencode(implode(' | ', array_slice($errors, 0, 5)));
        } else {
            $args['zw_msg'] = 'applicability_saved';
            $args['zw_ok']  = $ok_count;
            $args['zw_skip'] = $skip_count;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
add_action('admin_post_zw_policy_applicability_save', 'zw_policy_platform_handle_applicability_save');

if (!function_exists('zw_policy_platform_handle_gate_save')) {
    /**
     * `admin_post` 处理器：保存总开关与灰度（TASK-1655 · E）。
     *
     * 安全四件套：能力位（403，**先于** nonce）→ `check_admin_referer` → 输入白名单（只收 canonical 键）
     * → 幂等 + 审计留痕。
     *
     * @return void
     */
    function zw_policy_platform_handle_gate_save() {
        if (!zw_policy_applicability_can_manage()) {
            wp_die('无权限', '', array('response' => 403));
            return;
        }
        check_admin_referer('zw_policy_gate_save');

        $input = array(
            'enabled'  => !empty($_POST['zw_gate_enabled']),
            'age_scan' => !empty($_POST['zw_gate_age_scan']),
            'modules'  => isset($_POST['zw_gate_modules']) && is_array($_POST['zw_gate_modules']) ? wp_unslash($_POST['zw_gate_modules']) : array(),
            'features' => isset($_POST['zw_gate_features']) && is_array($_POST['zw_gate_features']) ? wp_unslash($_POST['zw_gate_features']) : array(),
        );
        $operator = (function_exists('wp_get_current_user') && wp_get_current_user())
            ? (string) wp_get_current_user()->user_login
            : 'system';

        $res  = zw_policy_gate_save($input, $operator);
        $args = array('page' => 'zw-policy-applicability');
        if (is_wp_error($res)) {
            $args['zw_msg']    = 'gate_error';
            $args['zw_detail'] = rawurlencode($res->get_error_message());
        } else {
            $args['zw_msg']   = !empty($res['changed']) ? 'gate_saved' : 'gate_same';
            $args['zw_level'] = (string) $res['level'];
            $args['zw_audit'] = !empty($res['audit_written']) ? '1' : '0';
            if (empty($res['audit_written'])) {
                $args['zw_audit_reason'] = rawurlencode((string) ($res['audit_reason'] ?? ''));
            }
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
add_action('admin_post_zw_policy_gate_save', 'zw_policy_platform_handle_gate_save');

if (!function_exists('zw_policy_applicability_scope_open')) {
    /**
     * 适用性配置页「高级模式作用域」的**开标签唯一出处**（TASK-1655 · D）。
     *
     * 为什么做成函数：页面有「`policy_rule` 表缺失」的**早退路径**，闭合层必须两处都输出；
     * 若两条路径各写一遍闭合层字面标签，静态配平检查（`tests/test-admin-h5-gate.php` P1-6 第四批）
     * 会把早退分支的闭合层算成「多补一层」⇒ 故开 / 闭合各有且仅有一处字面标签。
     *
     * @return string
     */
    function zw_policy_applicability_scope_open() {
        return '<div id="zw-adm-policy-scope" class="zw-adm-scope">';
    }
}

if (!function_exists('zw_policy_applicability_scope_close')) {
    /**
     * 与 `zw_policy_applicability_scope_open()` 唯一配对的闭合层（**两条路径都经此输出**）。
     *
     * @return void
     */
    function zw_policy_applicability_scope_close() {
        echo '</div>';
    }
}

function zw_policy_platform_page_applicability() {
    global $wpdb;
    zw_policy_platform_page_header('适用性配置', '为每条执行规则配置适用的业务模块（未配置 ⇒ 不触发）。默认只展开「未配置」的待办规则，已配置规则收起；另设「总开关与灰度」统一管理全局开关与白名单。');

    $gate_state  = zw_policy_gate_option_state();
    $gate_keys   = zw_policy_gate_key_choices();
    $gate_ready  = (bool) ($gate_keys['modules'] && $gate_keys['features']);

    $lib_ready = zw_policy_applicability_lib_ready();
    if (!$lib_ready) {
        echo '<div class="notice notice-error"><p><strong>键校验模块未加载</strong>：'
            . '<code>scripts/migrate-policy-rule-modules.php</code> 不可读 ⇒ 页面 fail-closed 为<strong>只读</strong>，'
            . '保存入口已禁用（绝不降级为「无校验写入」）。</p></div>';
    } elseif (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'applicability_saved') {
        $ok = isset($_GET['zw_ok']) ? (int) $_GET['zw_ok'] : 0;
        $skip = isset($_GET['zw_skip']) ? (int) $_GET['zw_skip'] : 0;
        echo '<div class="notice notice-success is-dismissible"><p>适用模块已保存：写入 ' . (int) $ok . ' 条，无变化跳过 '
            . (int) $skip . ' 条。改动的<strong>已启用</strong>规则已复位到「申请启用 → 初审 → 终审」双人审批链路。</p></div>';
    } elseif (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'applicability_error') {
        $detail = isset($_GET['zw_detail']) ? rawurldecode((string) wp_unslash($_GET['zw_detail'])) : '';
        echo '<div class="notice notice-error is-dismissible"><p>适用模块保存被拒绝（fail-closed，未做任何截断）：'
            . esc_html($detail) . '</p></div>';
    }

    /* ── 总开关与灰度：保存结果提示（TASK-1655 · E；与上面适用模块提示相互独立，可同帧共存） ── */
    if (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'gate_saved') {
        $g_level = isset($_GET['zw_level']) ? sanitize_text_field((string) wp_unslash($_GET['zw_level'])) : '';
        $g_audit = (isset($_GET['zw_audit']) && (string) $_GET['zw_audit'] === '1');
        $g_reason = isset($_GET['zw_audit_reason']) ? rawurldecode((string) wp_unslash($_GET['zw_audit_reason'])) : '';
        echo '<div class="notice notice-success is-dismissible"><p>总开关与灰度已保存：当前层级 <strong>' . esc_html($g_level) . '</strong>。'
            . ($g_audit
                ? '已写入审计日志（<code>POLICY_GATE_UPDATE</code>）。'
                : '审计未写入：' . esc_html($g_reason !== '' ? $g_reason : '无审计函数，未写'))
            . '</p></div>';
    } elseif (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'gate_same') {
        $g_level = isset($_GET['zw_level']) ? sanitize_text_field((string) wp_unslash($_GET['zw_level'])) : '';
        echo '<div class="notice notice-info is-dismissible"><p>总开关与灰度无变化（幂等跳过，零写入零审计）：当前层级 <strong>'
            . esc_html($g_level) . '</strong>。</p></div>';
    } elseif (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'gate_error') {
        $g_detail = isset($_GET['zw_detail']) ? rawurldecode((string) wp_unslash($_GET['zw_detail'])) : '';
        echo '<div class="notice notice-error is-dismissible"><p>总开关保存被拒绝（fail-closed，未写入任何 option）：'
            . esc_html($g_detail) . '</p></div>';
    }

    /* D：高级模式作用域（键名默认隐藏，只显示中文名）。 */
    echo zw_policy_applicability_scope_open();
    echo '<div class="zw-adm-mode-bar"><label class="zw-adm-mode-toggle zw-adm-touch"><input type="checkbox" id="zw-adm-advanced-mode" value="1"> '
        . '<strong>高级模式</strong>（显示内部键名与原始值）</label>'
        . '<span class="zw-adm-mode-hint">默认只显示中文名；把鼠标停在选项上可看到对应键名，需要成片查看时打开高级模式。</span></div>';

    /* ── 总开关与灰度（TASK-1655 · E） ── */
    echo '<div class="zw-adm-sec" title="zw_policy_gate_enabled()">总开关与灰度（判定唯一出处：SPEC-93 §十四）</div>';
    if (!$gate_ready) {
        echo '<div class="zw-adm-card zw-adm-gate-panel" style="padding:12px 14px;margin-bottom:14px">'
            . '<p style="margin:0"><strong>域键 / 功能点键注册表未加载</strong>：<code>inc/policy-platform/policy-module-map.php</code> 未加载 ⇒ '
            . '总开关区 fail-closed 为<strong>只读</strong>，保存入口已禁用（绝不写入来源不明的键）。</p></div>';
    } else {
        echo '<div class="zw-adm-card zw-adm-gate-panel">';
        echo '<div class="zw-adm-gate-level">当前灰度层级：' . zw_policy_platform_badge('info', (string) $gate_state['level']['label'])
            . '<span class="zw-adm-gate-level-detail">' . esc_html((string) $gate_state['level']['detail']) . '</span></div>';

        /* 每个开关键的当前 ON/off + 人话摘要（原始键名收进 title / 高级模式，D：默认不留空白列） */
        $gate_opt_labels = array(
            'zw_policy_gate_enabled'    => '全局开关',
            'zw_policy_gate_modules'    => '域白名单',
            'zw_policy_gate_features'   => '功能点白名单',
            'zw_policy_age_retire_scan' => '到龄扫描',
        );
        $gate_opt_rows = array();
        foreach ($gate_opt_labels as $gate_opt => $gate_opt_label) {
            $cell  = $gate_state['display'][$gate_opt];
            $count = ($gate_opt === 'zw_policy_gate_modules') ? count((array) $gate_state['modules'])
                : (($gate_opt === 'zw_policy_gate_features') ? count((array) $gate_state['features']) : 0);
            $summary = $count > 0 ? ('已选 ' . $count . ' 项') : ($cell['on'] ? '已开' : '（空 / 未设置）');
            $gate_opt_rows[] = array(
                array('html' => '<span title="' . esc_attr($gate_opt) . '">' . esc_html($gate_opt_label) . '</span> '
                    . '<code class="zw-adm-keychip">' . esc_html($gate_opt) . '</code>'),
                array('html' => zw_policy_platform_badge($cell['on'] ? 'on' : 'off', (string) $cell['label'])),
                array('html' => esc_html($summary) . ' <code class="zw-adm-keychip">' . esc_html((string) $cell['value']) . '</code>'),
            );
        }
        zw_policy_platform_render_table(array('开关键', '当前', '当前值'), $gate_opt_rows);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="zw-adm-gate-form">';
        echo '<input type="hidden" name="action" value="zw_policy_gate_save">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('zw_policy_gate_save')) . '">';

        echo '<div class="zw-adm-gate-opts zw-adm-gate-opts-lg">';
        echo '<label class="zw-adm-gate-opt zw-adm-touch" title="zw_policy_gate_enabled"><input type="checkbox" name="zw_gate_enabled" value="1"'
            . ($gate_state['enabled'] ? ' checked' : '') . '> <strong>全局开关</strong> '
            . zw_policy_platform_badge($gate_state['enabled'] ? 'on' : 'off', $gate_state['enabled'] ? 'ON' : 'off')
            . '<code class="zw-adm-keychip">zw_policy_gate_enabled</code></label>';
        echo '<label class="zw-adm-gate-opt zw-adm-touch" title="zw_policy_age_retire_scan"><input type="checkbox" name="zw_gate_age_scan" value="1"'
            . ($gate_state['age_scan'] ? ' checked' : '') . '> <strong>到龄扫描</strong>（R-13 到龄派生标记） '
            . zw_policy_platform_badge($gate_state['age_scan'] ? 'on' : 'off', $gate_state['age_scan'] ? 'ON' : 'off')
            . '<code class="zw-adm-keychip">zw_policy_age_retire_scan</code></label>';
        echo '</div>';
        echo '<p class="zw-adm-gate-note">默认 <strong>off</strong>：未勾选即关闭；本页任何操作都<strong>不写入「默认开」</strong>的开关值，也不改动判定语义。</p>';

        echo '<div class="zw-adm-gate-group"><div class="zw-adm-gate-group-title" title="zw_policy_gate_modules">域白名单（全不勾 = 不按域收窄）'
            . '<code class="zw-adm-keychip">zw_policy_gate_modules</code></div><div class="zw-adm-gate-opts">';
        foreach ((array) $gate_keys['modules'] as $gate_key => $gate_label) {
            $gate_on = in_array(strtolower((string) $gate_key), (array) $gate_state['modules'], true);
            echo '<label class="zw-adm-gate-opt zw-adm-touch" title="' . esc_attr((string) $gate_key) . '">'
                . '<input type="checkbox" name="zw_gate_modules[]" value="' . esc_attr((string) $gate_key) . '"' . ($gate_on ? ' checked' : '') . '> '
                . esc_html((string) $gate_label) . ' '
                . zw_policy_platform_badge($gate_on ? 'on' : 'off', $gate_on ? 'ON' : 'off')
                . '<code class="zw-adm-keychip">' . esc_html((string) $gate_key) . '</code></label>';
        }
        echo '</div></div>';

        echo '<div class="zw-adm-gate-group"><div class="zw-adm-gate-group-title" title="zw_policy_gate_features">功能点白名单（全不勾 = 不按功能点收窄）'
            . '<code class="zw-adm-keychip">zw_policy_gate_features</code></div><div class="zw-adm-gate-opts">';
        foreach ((array) $gate_keys['features'] as $gate_key => $gate_label) {
            $gate_on = in_array(strtolower((string) $gate_key), (array) $gate_state['features'], true);
            echo '<label class="zw-adm-gate-opt zw-adm-touch" title="' . esc_attr((string) $gate_key) . '">'
                . '<input type="checkbox" name="zw_gate_features[]" value="' . esc_attr((string) $gate_key) . '"' . ($gate_on ? ' checked' : '') . '> '
                . esc_html((string) $gate_label) . ' '
                . zw_policy_platform_badge($gate_on ? 'on' : 'off', $gate_on ? 'ON' : 'off')
                . '<code class="zw-adm-keychip">' . esc_html((string) $gate_key) . '</code></label>';
        }
        echo '</div></div>';

        echo '<p><button class="button button-primary zw-adm-gate-save">保存总开关与灰度</button> '
            . '<span class="zw-adm-gate-note">白名单键<strong>只从注册表取</strong>（越界键静默丢弃）；保存后立即影响运行时判定。</span></p>';
        echo '</form>';
        echo '</div>';
    }

    /* ── 键表（唯一出处 T2 注册表；D：默认隐藏，高级模式才显示） ── */
    $groups = $lib_ready ? zw_policy_applicability_registry_groups() : array();
    if ($groups) {
        echo '<div class="zw-adm-advanced-block">';
        echo '<div class="zw-adm-sec">可选键（唯一出处：T2 注册表）</div>';
        $key_rows = array();
        foreach ($groups as $domain => $group) {
            $key_rows[] = array(
                array('html' => '<code class="zw-adm-keychip">' . esc_html($domain) . '</code>'),
                (string) $group['label'],
                $group['features']
                    ? implode('、', array_map(function ($k, $v) { return $k . '（' . $v . '）'; }, array_keys($group['features']), $group['features']))
                    : '—（只配域级 = 整个域）',
            );
        }
        zw_policy_platform_render_table(array('域键', '中文名', '功能点键'), $key_rows);
        echo '</div>';
    }

    if (!zw_policy_platform_exists('policy_rule')) {
        zw_policy_platform_missing_notice();
        zw_policy_applicability_scope_close();
        zw_policy_platform_applicability_assets();
        zw_policy_platform_page_footer();
        return;
    }

    $rows   = zw_policy_applicability_rules();
    $sum    = zw_policy_applicability_summary($rows);
    $unconf = $sum['unconfigured'];

    /* ── 首屏聚焦（C）：默认只展开「未配置适用模块」的待办规则；已配置规则收起，可一键全部展开 ── */
    echo '<div class="zw-adm-sec" style="margin-top:22px">未配置适用模块的规则（待办 N=' . count($unconf) . ' / 共 ' . (int) $sum['total'] . ' 条）</div>';
    if ($unconf) {
        zw_policy_platform_render_table(array('规则编号', '规则名称', '当前值（未命中任何合法键）'), array_map(function ($r) {
            return array($r['rule_code'], $r['rule_name'], $r['raw'] !== '' ? $r['raw'] : '（空）');
        }, $unconf));
    } else {
        echo '<div class="zw-adm-card" style="padding:12px 14px;margin-bottom:14px;border-left:3px solid var(--primary-color,#d80505)">'
            . '<p style="margin:0">全部规则均已配置适用模块（待办 N=0）。</p></div>';
    }

    /* ── 逐规则配置（A 折叠卡片 / B 一键推荐 / D 只说人话） ── */
    echo '<div class="zw-adm-sec" style="margin-top:22px">逐规则配置（每张卡片默认收起；待办规则默认展开）</div>';
    if (!$lib_ready) {
        echo '<div class="zw-adm-card" style="padding:12px 14px;color:#666">键校验模块不可用，保存入口已禁用。</div>';
    } else {
        echo '<div class="zw-adm-toolbar">'
            . '<button type="button" class="button" data-zw-expand="all">全部展开</button>'
            . '<button type="button" class="button" data-zw-expand="none">全部收起</button>'
            . '<span class="zw-adm-toolbar-note">共 ' . count($rows) . ' 条规则；键名默认隐藏（鼠标悬停可看，或用上方「高级模式」）。</span>'
            . '</div>';

        $nonce = wp_create_nonce('zw_policy_applicability_save');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="zw_policy_applicability_save">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        foreach ($rows as $row) {
            $rid      = (string) ($row['id'] ?? '');
            $keys     = zw_policy_applicability_decode($row['business_module'] ?? '');
            $is_todo  = !$keys;
            $scope    = zw_policy_applicability_scope_label($keys);
            $rec      = zw_policy_applicability_recommendation($row);
            $raw      = (string) ($row['business_module'] ?? '');

            // A：既有契约（测试断言）<details class="zw-adm-card zw-adm-rule-card"...>；待办规则默认展开（C）。
            $card_class = 'zw-adm-card zw-adm-rule-card' . ($is_todo ? ' is-todo' : '');
            echo '<details class="' . esc_attr($card_class) . '"' . ($is_todo ? ' open' : '') . '>';
            echo '<summary class="zw-adm-rule-summary">';
            echo '<span class="zw-adm-rule-code">' . esc_html((string) ($row['rule_code'] ?? '')) . '</span>';
            echo '<span class="zw-adm-rule-name">' . esc_html((string) ($row['rule_name'] ?? '')) . '</span>';
            echo '<span class="zw-adm-rule-status">'
                . ((int) ($row['enabled'] ?? 0) === 1
                    ? zw_policy_platform_badge('on', '已启用')
                    : zw_policy_platform_badge('off', '未启用'))
                . ($is_todo ? zw_policy_platform_badge('wait', '待配置') : '')
                . '</span>';
            echo '<span class="zw-adm-rule-scope" title="' . esc_attr(implode('、', $keys)) . '">当前生效范围：<strong>'
                . esc_html($scope) . '</strong></span>';
            echo '</summary>';

            echo '<div class="zw-adm-rule-body">';
            /* B：一键推荐（只填表单、不提交）。
               已配置且推荐未核实的规则**不显示噪声提示**（只在待办规则或推荐注册表不可用时提示）。 */
            if ($rec['state'] === 'ready') {
                echo '<div class="zw-adm-rule-recommend">'
                    . '<button type="button" class="button zw-adm-recommend-btn" data-zw-recommend="'
                    . esc_attr((string) wp_json_encode(array_values($rec['keys']))) . '">应用推荐配置</button>'
                    . '<span class="zw-adm-rule-recommend-hint">推荐：' . esc_html(zw_policy_applicability_scope_label($rec['keys']))
                    . '（<strong>只填表单、不提交</strong>，可再微调后点「保存全部改动」）</span>'
                    . '<span class="zw-adm-recommend-state" role="status" aria-live="polite"></span></div>';
            } elseif ($is_todo || $rec['state'] === 'unavailable') {
                // 待办规则（占位 / 未核实）与推荐注册表不可用 ⇒ 明确提示；已配置 + 未核实不提示（避免噪声）。
                echo '<div class="zw-adm-rule-recommend"><span class="zw-adm-rule-recommend-hint zw-adm-rule-recommend-empty">'
                    . esc_html((string) $rec['hint']) . '</span></div>';
            }
            /* 当前值：默认模式给人话（中文范围），原始值收进高级模式（D）。 */
            echo '<p class="zw-adm-rule-raw">当前值：' . ($keys
                    ? '<strong>' . esc_html($scope) . '</strong><span class="zw-adm-rule-raw-note">（合法键 ' . count($keys) . ' 个）</span>'
                    : '<strong>（未配置 ⇒ 不触发）</strong>')
                . ' <code class="zw-adm-keychip">' . esc_html($raw) . '</code></p>';

            foreach ($groups as $domain => $group) {
                echo '<div class="zw-adm-rule-group">';
                echo '<div class="zw-adm-rule-group-title" title="' . esc_attr((string) $domain) . '">'
                    . esc_html((string) $group['label']) . '</div><div class="zw-adm-rule-opts">';
                $cb = function ($key, $label) use ($rid, $keys) {
                    $checked = in_array((string) $key, $keys, true) ? ' checked' : '';
                    return '<label class="zw-adm-rule-opt zw-adm-touch" title="' . esc_attr((string) $key) . '">'
                        . '<input type="checkbox" name="modules[' . esc_attr($rid) . '][]" value="' . esc_attr((string) $key) . '"' . $checked . '> '
                        . '<span class="zw-adm-rule-opt-label">' . esc_html((string) $label) . '</span>'
                        . '<code class="zw-adm-keychip">' . esc_html((string) $key) . '</code></label>';
                };
                echo $cb($domain, (string) $group['label'] . '（整个域）');
                foreach ($group['features'] as $fkey => $flabel) {
                    echo $cb($fkey, $flabel);
                }
                echo '</div></div>';
            }
            echo '</div>';
            echo '</details>';
        }
        echo '<p><button class="button button-primary">保存全部改动</button> '
            . '<span style="color:#666;font-size:12px">键必须在 T2 注册表内；JSON 编码后 &gt;128 字符一律<strong>拒绝保存</strong>'
            . '（提示「映射项过多，需启用②加列（备选）」），<strong>禁止静默截断</strong>。'
            . '未勾选任何键的规则视为「不修改」（不会清空既有配置）。</span></p>';
        echo '</form>';
    }

    zw_policy_applicability_scope_close();   /* /#zw-adm-policy-scope */

    /* ── 只读聚合：条款业务领域分布（原只读视图保留） ── */
    if (zw_policy_platform_exists('policy_clause')) {
        $rows2 = $wpdb->get_results('SELECT business_area, COUNT(*) AS c FROM ' . zw_policy_platform_table('policy_clause') . ' GROUP BY business_area ORDER BY c DESC', ARRAY_A);
        echo '<div class="zw-adm-sec" style="margin-top:22px">条款业务领域分布（只读）</div>';
        zw_policy_platform_render_table(array('业务领域', '条款数'), array_map(function ($r) {
            return array($r['business_area'], $r['c']);
        }, is_array($rows2) ? $rows2 : array()));
    }

    zw_policy_platform_applicability_assets();
    zw_policy_platform_page_footer();
}

if (!function_exists('zw_policy_platform_applicability_assets')) {
    /**
     * 适用性配置页的**内联**样式与脚本（TASK-1655 · A–E）。
     *
     * 纪律：**不新增全局 CSS 文件**（AGENTS.md 铁律 2）；类族前缀 `zw-adm-*`；主色
     * `var(--primary-color,#d80505)`；不使用任何图标字体（折叠指示用 `<details>` 原生 marker，
     * 故无需 ceofont 图标）；动效只用 `transform` / `opacity`，且带 `prefers-reduced-motion` 兜底、
     * hover 包 `@media(hover:hover)`、无 `transition:all`（SPEC-11）。
     *
     * @return void
     */
    function zw_policy_platform_applicability_assets() {
        ?>
<style>
#zw-adm-policy-scope .zw-adm-keychip{display:none}
#zw-adm-policy-scope.zw-adm-advanced .zw-adm-keychip{display:inline;font-size:11px;background:#f0f0f1;border-radius:3px;padding:0 4px}
#zw-adm-policy-scope .zw-adm-advanced-block{display:none}
#zw-adm-policy-scope.zw-adm-advanced .zw-adm-advanced-block{display:block}
.zw-adm-mode-bar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;background:#fff;border:1px solid #dcdfe3;border-left:3px solid var(--primary-color,#d80505);border-radius:4px;padding:10px 14px;margin:0 0 14px}
.zw-adm-mode-toggle{display:inline-flex;align-items:center;gap:6px;cursor:pointer}
.zw-adm-mode-hint,.zw-adm-toolbar-note,.zw-adm-gate-note{color:#666;font-size:12px}
.zw-adm-gate-panel{padding:14px;margin-bottom:16px}
.zw-adm-gate-level{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 10px;font-size:13px}
.zw-adm-gate-level-detail{color:#555;font-size:12.5px}
.zw-adm-gate-form{margin-top:12px;border-top:1px solid #ececec;padding-top:12px}
.zw-adm-gate-opts{display:flex;flex-wrap:wrap;gap:6px 16px;align-items:center}
.zw-adm-gate-opts-lg{margin:0 0 6px}
.zw-adm-gate-opt{display:inline-flex;align-items:center;gap:6px;min-height:32px;white-space:nowrap;cursor:pointer}
.zw-adm-gate-group{margin:10px 0}
.zw-adm-gate-group-title{font-weight:600;font-size:12.5px;margin:0 0 4px}
.zw-adm-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 12px}
.zw-adm-rule-card{margin:0 0 12px;padding:0}
.zw-adm-rule-summary{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:12px 14px;cursor:pointer;list-style:none}
.zw-adm-rule-summary::-webkit-details-marker{display:none}
.zw-adm-rule-summary::marker{content:""}
.zw-adm-rule-card.is-todo>.zw-adm-rule-summary{border-left:3px solid var(--primary-color,#d80505)}
.zw-adm-rule-card[open]>.zw-adm-rule-summary{border-bottom:1px solid #ececec}
.zw-adm-rule-code{font-family:Menlo,Consolas,monospace;font-size:11.5px;color:#777}
.zw-adm-rule-name{font-weight:600}
.zw-adm-rule-scope{margin-left:auto;color:#555;font-size:12.5px}
.zw-adm-rule-body{padding:12px 14px}
.zw-adm-rule-recommend{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 8px}
.zw-adm-rule-recommend-hint{color:#555;font-size:12.5px}
.zw-adm-rule-recommend-empty{color:#8a6d00}
.zw-adm-recommend-state{color:var(--primary-color,#d80505);font-size:12.5px}
.zw-adm-rule-raw{margin:0 0 8px;color:#666;font-size:12px}
.zw-adm-rule-group{margin:0 0 8px}
.zw-adm-rule-group-title{font-weight:600;font-size:12.5px;margin:0 0 2px}
.zw-adm-rule-opts{display:flex;flex-wrap:wrap;align-items:center}
.zw-adm-rule-opt{display:inline-flex;align-items:center;gap:5px;min-height:32px;margin:0 14px 2px 0;white-space:nowrap;cursor:pointer}
.zw-adm-gate-save{min-height:32px}
@media(hover:hover){.zw-adm-recommend-btn:hover,.zw-adm-gate-save:hover{border-color:var(--primary-color,#d80505);color:var(--primary-color,#d80505)}}
.zw-adm-recommend-btn:active,.zw-adm-gate-save:active{transform:translateY(1px)}
.zw-adm-recommend-btn{transition:color .15s ease,border-color .15s ease,transform .1s ease}
.zw-adm-gate-save{transition:color .15s ease,border-color .15s ease,transform .1s ease}
@media(prefers-reduced-motion:reduce){.zw-adm-recommend-btn,.zw-adm-gate-save{transition:none}}
</style>
<script>
(function () {
    var scope = document.getElementById('zw-adm-policy-scope');
    if (!scope) { return; }

    var mode = document.getElementById('zw-adm-advanced-mode');
    if (mode) {
        mode.addEventListener('change', function () {
            if (mode.checked) { scope.classList.add('zw-adm-advanced'); }
            else { scope.classList.remove('zw-adm-advanced'); }
        });
    }

    scope.addEventListener('click', function (event) {
        var node = event.target;
        if (!node || typeof node.closest !== 'function') { return; }

        var btn = node.closest('[data-zw-recommend]');
        if (btn) {
            event.preventDefault();
            var card = btn.closest('.zw-adm-rule-card');
            var keys = [];
            try { keys = JSON.parse(btn.getAttribute('data-zw-recommend') || '[]'); } catch (err) { keys = []; }
            if (!keys.length) { return; }
            if (card) {
                var boxes = card.querySelectorAll('input[type="checkbox"][name^="modules["]');
                for (var i = 0; i < boxes.length; i++) {
                    boxes[i].checked = keys.indexOf(boxes[i].value) !== -1;
                }
                if (card.tagName === 'DETAILS') { card.open = true; }
                var state = card.querySelector('.zw-adm-recommend-state');
                if (state) { state.textContent = '已填入推荐配置（未提交），请复核后点「保存全部改动」。'; }
            }
            return;
        }

        var tool = node.closest('[data-zw-expand]');
        if (tool) {
            event.preventDefault();
            var wantOpen = tool.getAttribute('data-zw-expand') === 'all';
            var cards = scope.querySelectorAll('details.zw-adm-rule-card');
            for (var j = 0; j < cards.length; j++) {
                if (wantOpen) { cards[j].setAttribute('open', 'open'); }
                else { cards[j].removeAttribute('open'); }
            }
        }
    });
})();
</script>
<?php
    }
}

/* ───────────── 执行规则库 ───────────── */
function zw_policy_platform_page_rules() {
    global $wpdb;
    zw_policy_platform_page_header('执行规则库', '执行规则列表（支持申请启用、初审、终审、停用；按规则子类查看）。');
    if (!zw_policy_platform_exists('policy_rule')) { zw_policy_platform_missing_notice(); zw_policy_platform_page_footer(); return; }
    if (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'rule_saved') {
        echo '<div class="notice notice-success is-dismissible"><p>执行规则审批操作已保存。</p></div>';
    }
    if (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'rule_batch_done') {
        $ok = isset($_GET['zw_ok']) ? (int) $_GET['zw_ok'] : 0;
        $fail = isset($_GET['zw_fail']) ? (int) $_GET['zw_fail'] : 0;
        echo '<div class="notice notice-success is-dismissible"><p>批量规则操作完成：成功 ' . esc_html($ok) . ' 条，失败 ' . esc_html($fail) . ' 条。</p></div>';
    }
    if (isset($_GET['zw_msg']) && $_GET['zw_msg'] === 'rule_batch_invalid') {
        echo '<div class="notice notice-error is-dismissible"><p>请选择规则并指定批量动作。</p></div>';
    }

    $types = array(
        ''             => '全部',
        '自查评分规则' => '自查评分规则',
        '业务控制规则' => '业务控制规则',
        'AI识别规则'   => 'AI识别规则',
        '舆情监测规则' => '舆情监测规则',
        '时限周期任务' => '时限周期任务',
    );
    $type_values = array(
        ''             => '',
        '自查评分规则' => '自查评分规则',
        '业务控制规则' => '业务控制',
        'AI识别规则'   => 'AI识别规则',
        '舆情监测规则' => '舆情监测规则',
        '时限周期任务' => '时限周期',
    );
    $current = isset($_GET['rt']) ? sanitize_text_field(wp_unslash($_GET['rt'])) : '';
    if (!array_key_exists($current, $types)) { $current = ''; }
    $filter_value = isset($type_values[$current]) ? $type_values[$current] : '';
    $f_approval = isset($_GET['approval']) ? sanitize_text_field(wp_unslash($_GET['approval'])) : '';
    $base = admin_url('admin.php?page=zw-policy-rules');
    $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $per = 20;
    $offset = ($paged - 1) * $per;
    echo '<div class="zw-adm-tabbar">';
    foreach ($types as $key => $label) {
        $url = $key === '' ? $base : add_query_arg('rt', $key, $base);
        $on = ((string) $current === (string) $key) ? ' class="on"' : '';
        echo '<a' . $on . ' href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';

    $rule_table = zw_policy_platform_table('policy_rule');
    $approval_table = zw_policy_platform_table('policy_rule_approval');
    $join_sql = " FROM {$rule_table} r LEFT JOIN {$approval_table} a ON a.rule_id=r.id";
    $where = array(); $params = array();
    if ($filter_value !== '') { $where[] = 'r.rule_type=%s'; $params[] = $filter_value; }
    if ($f_approval === '草稿') { $where[] = "(a.approval_status IS NULL OR a.approval_status='' OR a.approval_status='草稿')"; }
    elseif ($f_approval !== '') { $where[] = 'a.approval_status=%s'; $params[] = $f_approval; }
    $where_sql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
    $count_sql = 'SELECT COUNT(*)' . $join_sql . $where_sql;
    $total = (int) (!empty($params) ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : $wpdb->get_var($count_sql));
    $sql = 'SELECT r.id, r.rule_code, r.rule_name, r.rule_type, r.domain_type, r.business_domain, r.business_module, r.trigger_event, r.enabled, r.update_time, a.approval_status, a.first_reviewer, a.second_reviewer'
         . $join_sql . $where_sql . ' ORDER BY r.rule_type, r.rule_code LIMIT %d OFFSET %d';
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, array($per, $offset))), ARRAY_A);

    $approvalOptions = array('' => '全部审批状态', '草稿' => '草稿', '待初审' => '待初审', '待终审' => '待终审', '已启用' => '已启用', '已驳回' => '已驳回', '已停用' => '已停用');
    echo '<form method="get" class="zw-adm-card" style="padding:10px 14px;margin:0 0 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
    echo '<input type="hidden" name="page" value="zw-policy-rules">';
    if ($current !== '') echo '<input type="hidden" name="rt" value="' . esc_attr($current) . '">';
    echo '<label style="font-size:12px;color:#666">审批状态<br><select name="approval">';
    foreach ($approvalOptions as $k => $v) echo '<option value="' . esc_attr($k) . '"' . selected($f_approval, $k, false) . '>' . esc_html($v) . '</option>';
    echo '</select></label>';
    echo '<button class="button button-primary">查询</button>';
    echo '<a class="button" href="' . esc_url($base) . '">重置</a>';
    echo '<span style="flex:1"></span><span style="font-size:12px;color:#999">共 ' . (int) $total . ' 条</span>';
    echo '</form>';

    $nonce = wp_create_nonce('zw_policy_rule_batch');
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="zw_policy_rule_batch">';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
    echo '<div class="zw-adm-card" style="padding:12px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
    echo '<select name="batch_operation"><option value="">批量动作</option>'
       . '<option value="request_enable">批量申请启用</option>'
       . '<option value="first_review|APPROVE">批量初审通过</option>'
       . '<option value="first_review|REJECT">批量初审驳回</option>'
       . '<option value="second_review|APPROVE">批量终审通过</option>'
       . '<option value="second_review|REJECT">批量终审驳回</option>'
       . '<option value="disable">批量停用</option></select>';
    echo '<button class="button button-primary">执行</button>';
    echo '<span style="font-size:12px;color:#999">勾选下方规则后执行批量审批操作</span>';
    echo '</div>';
    $check_all = '<label class="zw-adm-touch"><input type="checkbox" onclick="var c=this;document.querySelectorAll(\'.zw-policy-rule-cb\').forEach(function(x){x.checked=c.checked;})"></label>';
    zw_policy_platform_render_table(array(array('raw' => $check_all), 'ID', '规则编号', '规则名称', '类型', '业务域', '模块', '触发事件', '审批状态', '启用', '更新时间', '操作'), array_map(function ($r) {
        $rule_status = !empty($r['approval_status']) ? $r['approval_status'] : ((int) $r['enabled'] ? '已启用' : '草稿');
        $actions = array();
        if ((int) $r['enabled'] === 1) {
            $actions[] = zw_policy_platform_rule_action_link($r['id'], 'zw_policy_rule_disable', '停用', '', 'button button-small button-link-delete');
        } elseif ($rule_status === '待初审') {
            $actions[] = zw_policy_platform_rule_action_link($r['id'], 'zw_policy_rule_first_review', '初审通过', 'APPROVE');
            $actions[] = zw_policy_platform_rule_action_link($r['id'], 'zw_policy_rule_first_review', '初审驳回', 'REJECT', 'button button-small button-link-delete');
        } elseif ($rule_status === '待终审') {
            $actions[] = zw_policy_platform_rule_action_link($r['id'], 'zw_policy_rule_second_review', '终审通过', 'APPROVE');
            $actions[] = zw_policy_platform_rule_action_link($r['id'], 'zw_policy_rule_second_review', '终审驳回', 'REJECT', 'button button-small button-link-delete');
        } else {
            $actions[] = zw_policy_platform_rule_action_link($r['id'], 'zw_policy_rule_request_enable', '申请启用');
        }
        $cb = '<label class="zw-adm-touch"><input type="checkbox" class="zw-policy-rule-cb" name="ids[]" value="' . esc_attr($r['id']) . '"></label>';
        return array(
            array('raw' => $cb), $r['id'], $r['rule_code'], $r['rule_name'], $r['rule_type'], $r['domain_type'], $r['business_module'], $r['trigger_event'],
            zw_policy_platform_badge_cell(zw_policy_platform_status_type($rule_status), $rule_status),
            zw_policy_platform_badge_cell((int) $r['enabled'] ? 'on' : 'off', (int) $r['enabled'] ? '已启用' : '未启用'),
            $r['update_time'],
            array('html' => implode(' ', $actions))
        );
    }, $rows));
    echo '</form>';
    zw_policy_platform_pager($total, $per, $paged, $base, array_filter(array('rt' => $current, 'approval' => $f_approval)));
    zw_policy_platform_page_footer();
}

/* ───────────── 派生资产库 ───────────── */
function zw_policy_platform_page_assets() {
    global $wpdb;
    zw_policy_platform_page_header('派生资产库', '规范类正文与规则已归并到统一文本源；本页保留历史只读列表。');
    if (!zw_policy_platform_exists('policy_spec')) { zw_policy_platform_missing_notice(); zw_policy_platform_page_footer(); return; }
    $table = zw_policy_platform_table('policy_spec');
    $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
    // TASK-1022：`policy_spec` 已归并（正文 → 文档中心版本链；规则 → 执行规则库），本页仅历史只读。
    // 样式沿用既有 zw-adm-card + 内联 style，不新增全局 CSS；不新增任何写入口。
    echo '<div class="zw-adm-card" style="padding:12px 14px;margin-bottom:14px;border-left:3px solid var(--primary-color,#d80505)">'
        . '<p style="margin:0 0 6px"><strong>`policy_spec` 已归并</strong>：规范类<strong>正文</strong>请在 '
        . '<strong>AI 法务部 → 文档中心</strong> 管理（版本链 / 生效 / 回退 / 派生），<strong>规则定义</strong>在 '
        . '<strong>执行规则库</strong>；本页仅保留<strong>历史只读列表</strong>（当前 ' . $total . ' 行）。</p>'
        . '<p style="margin:0;color:#666">本页<strong>不提供任何写入口</strong>；如需迁移存量行，请执行 '
        . '<code>wp eval-file scripts/migrate-policy-spec-to-documents.php</code>（默认 dry-run，逐行转换前先预演）。</p>'
        . '</div>';
    $specs = $wpdb->get_results('SELECT id, name, version_no, spec_type, status, update_time FROM ' . $table . ' ORDER BY update_time DESC LIMIT 100', ARRAY_A);
    zw_policy_platform_render_table(array('ID', '名称', '版本', '类型', '状态', '更新时间'), array_map(function ($r) {
        return array($r['id'], $r['name'], $r['version_no'], $r['spec_type'], $r['status'], $r['update_time']);
    }, $specs));
    zw_policy_platform_page_footer();
}

/* ───────────── 整改台账 ───────────── */
function zw_policy_platform_page_issues() {
    global $wpdb;
    zw_policy_platform_page_header('整改台账', '整改工单列表（只读，支持状态/风险筛选）。');
    if (!zw_policy_platform_exists('policy_issue')) { zw_policy_platform_missing_notice(); zw_policy_platform_page_footer(); return; }

    $f_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    $f_risk = isset($_GET['risk']) ? sanitize_text_field(wp_unslash($_GET['risk'])) : '';
    $base = admin_url('admin.php?page=zw-policy-issues');
    $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $per = 20;
    $offset = ($paged - 1) * $per;
    $where = array(); $params = array();
    if ($f_status !== '') { $where[] = 'status=%s'; $params[] = $f_status; }
    if ($f_risk !== '') { $where[] = 'risk_level=%s'; $params[] = $f_risk; }
    $where_sql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';
    $table = zw_policy_platform_table('policy_issue');
    $count_sql = 'SELECT COUNT(*) FROM ' . $table . $where_sql;
    $total = (int) (!empty($params) ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : $wpdb->get_var($count_sql));
    $sql = 'SELECT id, source_type, title, risk_level, owner_dept, owner, due_date, status, update_time FROM ' . $table . $where_sql . ' ORDER BY update_time DESC LIMIT %d OFFSET %d';
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, array($per, $offset))), ARRAY_A);

    $statuses = array('' => '全部状态', '未开始' => '未开始', '整改中' => '整改中', '待复核' => '待复核', '逾期' => '逾期', '已关闭' => '已关闭');
    $risks = array('' => '全部风险', '重大' => '重大', '高' => '高', '中' => '中', '低' => '低');
    echo '<form method="get" class="zw-adm-card" style="padding:12px 14px;margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">';
    echo '<input type="hidden" name="page" value="zw-policy-issues">';
    echo '<label style="font-size:12px;color:#666">状态<br><select name="status">';
    foreach ($statuses as $k => $v) echo '<option value="' . esc_attr($k) . '"' . selected($f_status, $k, false) . '>' . esc_html($v) . '</option>';
    echo '</select></label>';
    echo '<label style="font-size:12px;color:#666">风险<br><select name="risk">';
    foreach ($risks as $k => $v) echo '<option value="' . esc_attr($k) . '"' . selected($f_risk, $k, false) . '>' . esc_html($v) . '</option>';
    echo '</select></label>';
    echo '<button class="button button-primary">查询</button>';
    echo '<a class="button" href="' . esc_url($base) . '">重置</a>';
    echo '<span style="flex:1"></span><span style="font-size:12px;color:#999">共 ' . (int) $total . ' 条</span>';
    echo '</form>';

    zw_policy_platform_render_table(array('ID', '来源', '标题', '风险', '责任部门', '责任人', '期限', '状态', '更新时间'), array_map(function ($r) {
        return array(
            $r['id'], $r['source_type'], $r['title'],
            zw_policy_platform_badge_cell(zw_policy_platform_status_type($r['risk_level']), $r['risk_level']),
            $r['owner_dept'], $r['owner'], $r['due_date'],
            zw_policy_platform_badge_cell(zw_policy_platform_status_type($r['status']), $r['status']),
            $r['update_time']
        );
    }, $rows));
    zw_policy_platform_pager($total, $per, $paged, $base, array_filter(array('status' => $f_status, 'risk' => $f_risk)));
    zw_policy_platform_page_footer();
}

/* ───────────── 监督看板 ───────────── */
function zw_policy_platform_page_supervision() {
    global $wpdb;
    zw_policy_platform_page_header('监督看板', '政策、规则、整改与控制点指标汇总（只读）。');
    if (!zw_policy_platform_exists('policy_clause')) { zw_policy_platform_missing_notice(); zw_policy_platform_page_footer(); return; }
    $clauseTotal = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_clause'));
    $redline = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_clause') . " WHERE redline_flag='是'");
    $ruleTotal = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_rule'));
    $ruleEnabled = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_rule') . ' WHERE enabled=1');
    $issueOpen = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . zw_policy_platform_table('policy_issue') . " WHERE status<>'已关闭'");
    $controlTotal = zw_policy_platform_exists('policy_control_log') ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . zw_policy_platform_table('policy_control_log')) : 0;
    zw_policy_platform_render_table(array('指标', '数值'), array(
        array('政策条款总数', $clauseTotal),
        array('红线条款数', $redline),
        array('执行规则总数', $ruleTotal),
        array('已启用规则数', $ruleEnabled),
        array('未关闭整改数', $issueOpen),
        array('控制点执行日志数', $controlTotal),
    ));
    zw_policy_platform_page_footer();
}
