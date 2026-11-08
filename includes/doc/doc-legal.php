<?php
/**
 * 公文 AI 法务接入 —— TASK-771 / SPEC-87 §19。
 *
 * 设计原则：
 * 1. **复用不重造**：AI 调用、结果归一、政策条款快照全部复用 AI 法务部既有纯函数
 *    （`zw_branch_content_review_ai_request()` / `_normalize_ai_result()` / `_policy_snapshot()`）；
 * 2. **边界隔离**：**不**调用 `zw_branch_content_review_save()`、**不**写分支机构那套 meta
 *    （既有链路存在"非白名单字段被静默直写"的缺陷，公文走自己的提交路径即可规避，避免动已上线模块）；
 * 3. **层级不丢**：正文 AST 按块拆成 `fields[]` 并保留块类型与层次序数，
 *    **不使用 `wp_strip_all_tags()` 把全文拍平**（拍平后「一、（一）」层级无法判定）；
 * 4. **隐私红线**：只存**结论与 issues 摘要**，**不存正文全文**（与既有链路刻意不同）；
 * 5. **失败不自动放行**：无密钥 / HTTP 异常 / JSON 不可解析 → 状态 `error`，转人工且**继续阻断用印**。
 *
 * 状态机：`none → pending_ai → pass | reject | manual | error`；`manual`/`error` 需人工结论；
 * 人工结论覆盖为 `pass` / `reject`（并记录 reviewer 类型为 human）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_LEGAL_VERSION')) {
    define('ZW_DOC_LEGAL_VERSION', '1');
}

if (!function_exists('zw_doc_legal_statuses')) {
    /**
     * 法务状态集合与展示名。
     *
     * @return array
     */
    function zw_doc_legal_statuses() {
        return array(
            'none'       => '未送审',
            'pending_ai' => '待 AI 审核',
            'pass'       => '已通过',
            'reject'     => '已驳回',
            'manual'     => '待人工判定',
            'error'      => '审核异常（转人工）',
        );
    }
}

if (!function_exists('zw_doc_legal_status_label')) {
    /**
     * 状态中文名。
     *
     * @param string $status 状态。
     * @return string
     */
    function zw_doc_legal_status_label($status) {
        $map = zw_doc_legal_statuses();

        return isset($map[(string) $status]) ? $map[(string) $status] : (string) $status;
    }
}

if (!function_exists('zw_doc_legal_sync_enabled')) {
    /**
     * 是否同步送审（默认同步：提交即出结论，用印门禁需要结论才能放行）。
     *
     * @return bool
     */
    function zw_doc_legal_sync_enabled() {
        return !empty(get_option('zw_doc_legal_sync', 1));
    }
}

if (!function_exists('zw_doc_legal_can_review')) {
    /**
     * 是否具备法务判定权（AI法务部角色或管理员）。
     *
     * @return bool
     */
    function zw_doc_legal_can_review() {
        if (!function_exists('current_user_can')) {
            return true;
        }
        if (current_user_can('manage_options')) {
            return true;
        }

        return (bool) current_user_can('tcpc_perm_legal');
    }
}

if (!function_exists('zw_doc_legal_blocks_to_fields')) {
    /**
     * 正文 AST → 法务 `fields[]`（**保留层次语义**）。
     *
     * 每块一个字段：`p-1` / `h1-2` …；值带上该层级序数（与成品可见文本一致），
     * 使「一、」「（一）」层级可被判定；另附标题、主送机关、署名、日期与附件说明。
     *
     * @param mixed $body 正文 AST。
     * @return array 每项 array{field,label,value}。
     */
    function zw_doc_legal_blocks_to_fields($body) {
        if (!is_array($body)) {
            return array();
        }
        $meta   = (isset($body['meta']) && is_array($body['meta'])) ? $body['meta'] : array();
        $fields = array();

        $type_labels = array(
            'h1' => '一级标题（一、）',
            'h2' => '二级标题（（一））',
            'h3' => '三级标题（1.）',
            'h4' => '四级标题（（1））',
            'p'  => '正文段',
        );

        if (!empty($meta['title'])) {
            $fields[] = array('field' => 'title', 'label' => '公文标题', 'value' => (string) $meta['title']);
        }
        if (!empty($meta['receiver'])) {
            $fields[] = array('field' => 'receiver', 'label' => '主送机关', 'value' => (string) $meta['receiver']);
        }

        $counters = array();
        $paragraphs = function_exists('zw_doc_body_paragraphs') ? zw_doc_body_paragraphs($body) : array();
        foreach ($paragraphs as $para) {
            $type = (string) $para['t'];
            $counters[$type] = isset($counters[$type]) ? ($counters[$type] + 1) : 1;
            $fields[] = array(
                'field' => $type . '-' . $counters[$type],
                'label' => isset($type_labels[$type]) ? $type_labels[$type] : '正文段',
                'value' => (string) $para['display'],
            );
        }

        if (!empty($meta['sign_org'])) {
            $fields[] = array('field' => 'sign_org', 'label' => '发文机关署名', 'value' => (string) $meta['sign_org']);
        }
        if (!empty($meta['date'])) {
            $fields[] = array('field' => 'date', 'label' => '成文日期', 'value' => (string) $meta['date']);
        }
        if (!empty($meta['attach_note']) && is_array($meta['attach_note'])) {
            $index = 0;
            foreach ($meta['attach_note'] as $note) {
                $index++;
                $fields[] = array(
                    'field' => 'attach-' . $index,
                    'label' => '附件说明',
                    'value' => (string) $note,
                );
            }
        }

        return $fields;
    }
}

if (!function_exists('zw_doc_legal_build_payload')) {
    /**
     * 组装送审载荷（结构与分支机构链路对齐，使其可直接喂给既有审核入口）。
     *
     * @param array $doc 发文记录。
     * @return array
     */
    function zw_doc_legal_build_payload($doc) {
        $policy = function_exists('zw_branch_content_review_policy_snapshot')
            ? zw_branch_content_review_policy_snapshot()
            : array('version' => '', 'clauses' => array());
        $clauses = isset($policy['clauses']) && is_array($policy['clauses']) ? $policy['clauses'] : array();

        // TASK-1598（R-11 第 1 步 · SPEC-93 §七）：**删除本模块自造的第二套截断**（原 `array_slice(…, 0, 50)`），
        // 改走 canonical 选取（红线必进 + 规则绑定必进 + 数值化序 + 来源轮转配额），与分支机构链路同一口径。
        $pinned = function_exists('zw_branch_content_review_policy_pinned_clause_ids')
            ? zw_branch_content_review_policy_pinned_clause_ids()
            : array();
        $selected = function_exists('zw_branch_content_review_select_clauses')
            ? zw_branch_content_review_select_clauses($clauses, null, null, $pinned)
            : array(
                'items'        => $clauses,
                'total'        => count($clauses),
                'truncated'    => false,
                'sources'      => 0,
                'missed'       => array(),
                'missed_count' => 0,
                'forced'       => 0,
            );
        // TASK-1598（R-11 第 4 步）：缺口告警文案由 canonical 出处生成，本模块不另造措辞。
        $gap_note = function_exists('zw_branch_content_review_gap_note')
            ? (string) zw_branch_content_review_gap_note((array) ($selected['missed'] ?? array()), (int) ($selected['missed_count'] ?? 0))
            : '';

        return array(
            'branch_id'           => 0,
            'branch_title'        => '中国传统文化促进会公文',
            'fields'              => zw_doc_legal_blocks_to_fields(isset($doc['body']) ? $doc['body'] : array()),
            'policy_version'      => isset($policy['version']) ? (string) $policy['version'] : '',
            'policy_clauses'      => (array) ($selected['items'] ?? array()),
            'policy_total'        => (int) ($selected['total'] ?? 0),
            'policy_selected'     => count((array) ($selected['items'] ?? array())),
            'policy_truncated'    => !empty($selected['truncated']),
            'policy_sources'      => (int) ($selected['sources'] ?? 0),
            'policy_missed'       => (array) ($selected['missed'] ?? array()),
            'policy_missed_count' => (int) ($selected['missed_count'] ?? 0),
            'policy_forced'       => (int) ($selected['forced'] ?? 0),
            'policy_gap_note'     => $gap_note,
            'content_version'     => current_time('mysql'),
        );
    }
}

if (!function_exists('zw_doc_legal_ai_request')) {
    /**
     * 调用 AI 法务审核（**复用既有入口**，不在本模块内再造 HTTP 与提示词）。
     *
     * @param array $payload 载荷。
     * @return array|WP_Error
     */
    function zw_doc_legal_ai_request($payload) {
        if (!function_exists('zw_branch_content_review_ai_request')) {
            return new WP_Error('zw_doc_legal_no_engine', 'AI 法务审核引擎未加载，已转人工审核。');
        }

        return zw_branch_content_review_ai_request($payload);
    }
}

if (!function_exists('zw_doc_legal_sanitize_issues')) {
    /**
     * 清洗 issues（**只保留结构化摘要**，不保留被审文本片段）。
     *
     * @param mixed $issues 原始 issues。
     * @return array
     */
    function zw_doc_legal_sanitize_issues($issues) {
        $out = array();
        foreach ((array) $issues as $item) {
            if (!is_array($item)) {
                continue;
            }
            $severity = isset($item['severity']) ? sanitize_key((string) $item['severity']) : 'warn';
            if (!in_array($severity, array('block', 'warn', 'info'), true)) {
                $severity = 'warn';
            }
            $out[] = array(
                'field'      => isset($item['field']) ? sanitize_text_field((string) $item['field']) : '',
                'label'      => isset($item['label']) ? sanitize_text_field((string) $item['label']) : '',
                'severity'   => $severity,
                'message'    => isset($item['message']) ? mb_substr(sanitize_text_field((string) $item['message']), 0, 300, 'UTF-8') : '',
                'suggestion' => isset($item['suggestion']) ? mb_substr(sanitize_text_field((string) $item['suggestion']), 0, 300, 'UTF-8') : '',
            );
            if (count($out) >= 50) {
                break;
            }
        }

        return $out;
    }
}

if (!function_exists('zw_doc_legal_store_result')) {
    /**
     * 落库审核结论（**只存结论与 issues 摘要，不存正文**）。
     *
     * @param int    $doc_id    发文 ID。
     * @param array  $result    归一后的结果 {status,summary,issues,engine}。
     * @param string $reviewer  ai|human。
     * @param int    $actor     操作人。
     * @param string $note      备注。
     * @return string 最终状态。
     */
    function zw_doc_legal_store_result($doc_id, $result, $reviewer = 'ai', $actor = 0, $note = '') {
        $doc_id   = (int) $doc_id;
        $status   = isset($result['status']) ? (string) $result['status'] : 'manual';
        if (!array_key_exists($status, zw_doc_legal_statuses())) {
            $status = 'manual';
        }
        $actor  = $actor ? (int) $actor : get_current_user_id();
        $stored = array(
            'status'   => $status,
            'summary'  => isset($result['summary']) ? mb_substr(sanitize_text_field((string) $result['summary']), 0, 500, 'UTF-8') : '',
            'issues'   => zw_doc_legal_sanitize_issues(isset($result['issues']) ? $result['issues'] : array()),
            'engine'   => isset($result['engine']) ? sanitize_text_field((string) $result['engine']) : '',
            'reviewer' => in_array($reviewer, array('ai', 'human'), true) ? $reviewer : 'ai',
        );

        update_post_meta($doc_id, '_zw_doc_legal_status', $status);
        update_post_meta($doc_id, '_zw_doc_legal_result', $stored);
        update_post_meta($doc_id, '_zw_doc_legal_reviewer_type', $stored['reviewer']);
        update_post_meta($doc_id, '_zw_doc_legal_reviewer_id', $actor);
        update_post_meta($doc_id, '_zw_doc_legal_reviewed_at', current_time('mysql'));

        if (function_exists('zw_doc_log_event')) {
            zw_doc_log_event(
                $doc_id,
                'legal_' . $status,
                $actor,
                '',
                $status,
                '法务' . ($stored['reviewer'] === 'ai' ? 'AI 初审' : '人工判定') . '：' . zw_doc_legal_status_label($status)
                . ($stored['summary'] !== '' ? '（' . $stored['summary'] . '）' : '')
                . ($note !== '' ? '｜' . $note : '')
            );
        }

        return $status;
    }
}

if (!function_exists('zw_doc_legal_submit')) {
    /**
     * 送法务审核（默认同步；失败一律转人工且**不自动放行**）。
     *
     * @param int  $doc_id 发文 ID。
     * @param int  $actor  操作人。
     * @param bool $sync   是否同步执行（false 时挂 WP-Cron）。
     * @return array|WP_Error 成功返回 array{status,summary,issues,policy_version}
     */
    function zw_doc_legal_submit($doc_id, $actor = 0, $sync = null) {
        $doc_id = (int) $doc_id;
        $doc    = function_exists('zw_doc_get_document') ? zw_doc_get_document($doc_id) : null;
        if (!$doc) {
            return new WP_Error('zw_doc_legal_no_doc', '发文不存在。');
        }
        if (empty($doc['body']) || (function_exists('zw_doc_body_is_empty') && zw_doc_body_is_empty($doc['body']))) {
            return new WP_Error('zw_doc_legal_no_body', '正文为空，无法送法务审核。');
        }

        $actor = $actor ? (int) $actor : get_current_user_id();
        $sync  = ($sync === null) ? zw_doc_legal_sync_enabled() : (bool) $sync;

        $payload = zw_doc_legal_build_payload($doc);
        // TASK-1598（R-11 第 4 步）：投喂条数/缺口一律以 payload 的 canonical 计数为准，
        // 后台文案与落库 meta 逐字一致（禁止一处 count(policy_clauses)、一处 policy_total 各算各的）。
        $clause_selected = (int) ($payload['policy_selected'] ?? count((array) $payload['policy_clauses']));
        $clause_total    = (int) ($payload['policy_total'] ?? 0);
        $gap_note        = (string) ($payload['policy_gap_note'] ?? '');
        $missed_sources  = array();
        foreach ((array) ($payload['policy_missed'] ?? array()) as $sid => $n) {
            $missed_sources[] = (string) $sid . ':' . (int) $n;
        }
        update_post_meta($doc_id, '_zw_doc_legal_submitted_at', current_time('mysql'));
        update_post_meta($doc_id, '_zw_doc_legal_policy_version', (string) $payload['policy_version']);
        update_post_meta($doc_id, '_zw_doc_legal_clause_sent', $clause_selected);
        update_post_meta($doc_id, '_zw_doc_legal_clause_total', $clause_total);
        update_post_meta($doc_id, '_zw_doc_legal_clause_sources', (int) ($payload['policy_sources'] ?? 0));
        update_post_meta($doc_id, '_zw_doc_legal_clause_truncated', !empty($payload['policy_truncated']) ? 1 : 0);
        update_post_meta($doc_id, '_zw_doc_legal_clause_missed_count', (int) ($payload['policy_missed_count'] ?? 0));
        update_post_meta($doc_id, '_zw_doc_legal_clause_missed_sources', implode(',', $missed_sources));
        update_post_meta($doc_id, '_zw_doc_legal_clause_gap_note', $gap_note);
        update_post_meta($doc_id, '_zw_doc_legal_status', 'pending_ai');

        if (function_exists('zw_doc_log_event')) {
            $note = '送法务审核（条款 ' . $clause_selected . '/' . $clause_total . '，'
                . count($payload['fields']) . ' 个字段，' . ($sync ? '同步' : '异步') . '）';
            if ($gap_note !== '') {
                $note .= '；' . $gap_note;  // 缺口不静默：未入选来源/条数进后台留痕
            }
            zw_doc_log_event(
                $doc_id,
                'legal_submitted',
                $actor,
                '',
                'pending_ai',
                $note
            );
        }

        if (!$sync) {
            if (function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + 1, 'zw_doc_legal_process_event', array($doc_id, $actor));
            }

            return array('status' => 'pending_ai', 'summary' => '已排入 AI 审核队列。', 'issues' => array(), 'policy_version' => (string) $payload['policy_version']);
        }

        $result = zw_doc_legal_ai_request($payload);
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            // 失败转人工，且**不自动放行**（与既有链路口径一致）
            $status = zw_doc_legal_store_result($doc_id, array(
                'status'  => 'error',
                'summary' => $result->get_error_message(),
                'issues'  => array(),
                'engine'  => '',
            ), 'ai', $actor);
            update_post_meta($doc_id, '_zw_doc_legal_note', $result->get_error_message());

            return array('status' => $status, 'summary' => $result->get_error_message(), 'issues' => array(), 'policy_version' => (string) $payload['policy_version']);
        }

        $status = zw_doc_legal_store_result($doc_id, $result, 'ai', $actor);

        return array(
            'status'         => $status,
            'summary'        => isset($result['summary']) ? (string) $result['summary'] : '',
            'issues'         => zw_doc_legal_sanitize_issues(isset($result['issues']) ? $result['issues'] : array()),
            'policy_version' => (string) $payload['policy_version'],
        );
    }
}

if (!function_exists('zw_doc_legal_manual_decision')) {
    /**
     * 人工判定（覆盖 AI 结论）。
     *
     * @param int    $doc_id   发文 ID。
     * @param string $decision pass|reject。
     * @param string $note     意见（驳回必填）。
     * @param int    $actor    操作人。
     * @return true|WP_Error
     */
    function zw_doc_legal_manual_decision($doc_id, $decision, $note = '', $actor = 0) {
        $doc_id   = (int) $doc_id;
        $decision = sanitize_key((string) $decision);
        if (!in_array($decision, array('pass', 'reject'), true)) {
            return new WP_Error('zw_doc_legal_bad_decision', '判定必须是「通过」或「驳回」。');
        }
        if ($decision === 'reject' && trim((string) $note) === '') {
            return new WP_Error('zw_doc_legal_note_required', '驳回时必须填写法务意见。');
        }
        $doc = function_exists('zw_doc_get_document') ? zw_doc_get_document($doc_id) : null;
        if (!$doc) {
            return new WP_Error('zw_doc_legal_no_doc', '发文不存在。');
        }

        $actor = $actor ? (int) $actor : get_current_user_id();
        zw_doc_legal_store_result($doc_id, array(
            'status'  => $decision,
            'summary' => $note !== '' ? $note : '人工判定通过。',
            'issues'  => array(),
            'engine'  => 'human',
        ), 'human', $actor, $note);
        if ($note !== '') {
            update_post_meta($doc_id, '_zw_doc_legal_note', sanitize_textarea_field($note));
        }

        return true;
    }
}

if (!function_exists('zw_doc_legal_status')) {
    /**
     * 读取法务状态（供详情页与用印门禁使用）。
     *
     * @param int $doc_id 发文 ID。
     * @return array
     */
    function zw_doc_legal_status($doc_id) {
        $doc_id = (int) $doc_id;
        $result = get_post_meta($doc_id, '_zw_doc_legal_result', true);

        return array(
            'status'         => (string) get_post_meta($doc_id, '_zw_doc_legal_status', true),
            'summary'        => (is_array($result) && isset($result['summary'])) ? (string) $result['summary'] : '',
            'issues'         => (is_array($result) && isset($result['issues']) && is_array($result['issues'])) ? $result['issues'] : array(),
            'engine'         => (is_array($result) && isset($result['engine'])) ? (string) $result['engine'] : '',
            'reviewer_type'  => (string) get_post_meta($doc_id, '_zw_doc_legal_reviewer_type', true),
            'reviewer_id'    => (int) get_post_meta($doc_id, '_zw_doc_legal_reviewer_id', true),
            'reviewed_at'    => (string) get_post_meta($doc_id, '_zw_doc_legal_reviewed_at', true),
            'submitted_at'   => (string) get_post_meta($doc_id, '_zw_doc_legal_submitted_at', true),
            'note'           => (string) get_post_meta($doc_id, '_zw_doc_legal_note', true),
            'policy_version' => (string) get_post_meta($doc_id, '_zw_doc_legal_policy_version', true),
            'clause_sent'    => (int) get_post_meta($doc_id, '_zw_doc_legal_clause_sent', true),
            'clause_total'   => (int) get_post_meta($doc_id, '_zw_doc_legal_clause_total', true),
        );
    }
}

if (!function_exists('zw_doc_legal_is_pass')) {
    /**
     * 是否已取得法务通过结论（用印门禁 G2 的唯一判据）。
     *
     * @param int $doc_id 发文 ID。
     * @return bool
     */
    function zw_doc_legal_is_pass($doc_id) {
        return (string) get_post_meta((int) $doc_id, '_zw_doc_legal_status', true) === 'pass';
    }
}

if (!function_exists('zw_doc_legal_query')) {
    /**
     * 公文法务待办查询。
     *
     * @param array $args status / per_page / page。
     * @return array{items:array,total:int,counts:array}
     */
    function zw_doc_legal_query($args = array()) {
        $args = wp_parse_args($args, array('status' => '', 'per_page' => 50, 'page' => 1));

        $posts = get_posts(array(
            'post_type'        => 'zw_official_doc',
            'post_status'      => 'private',
            'posts_per_page'   => -1,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => true,
        ));

        $counts = array_fill_keys(array_keys(zw_doc_legal_statuses()), 0);
        $items  = array();
        foreach ((array) $posts as $post) {
            $doc = function_exists('zw_doc_get_document') ? zw_doc_get_document($post->ID) : null;
            if (!$doc || empty($doc['body'])) {
                continue;
            }
            $legal = zw_doc_legal_status($post->ID);
            if (isset($counts[$legal['status']])) {
                $counts[$legal['status']]++;
            }
            if ($args['status'] !== '' && $legal['status'] !== $args['status']) {
                continue;
            }
            $doc['legal'] = $legal;
            $items[]      = $doc;
        }

        $total    = count($items);
        $per_page = max(1, (int) $args['per_page']);
        $page     = max(1, (int) $args['page']);

        return array(
            'items'  => array_slice($items, ($page - 1) * $per_page, $per_page),
            'total'  => $total,
            'counts' => $counts,
        );
    }
}

if (!function_exists('zw_doc_legal_handle_post')) {
    /**
     * 「公文法务」页 POST 处理（在 admin_init 阶段执行）。
     *
     * @return void
     */
    function zw_doc_legal_handle_post() {
        $action = isset($_POST['zw_doc_legal_action']) ? sanitize_key(wp_unslash($_POST['zw_doc_legal_action'])) : '';
        if ($action === '') {
            return;
        }
        check_admin_referer('zw_doc_legal');
        if (!zw_doc_legal_can_review()) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }

        $doc_id = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
        $back   = zw_doc_legal_admin_url();
        $notice = '';

        if ($action === 'submit') {
            $result = zw_doc_legal_submit($doc_id, get_current_user_id());
            $notice = is_wp_error($result) ? $result->get_error_message() : ('已送法务审核：' . zw_doc_legal_status_label($result['status']) . ($result['summary'] !== '' ? '（' . $result['summary'] . '）' : ''));
            $back   = zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id));
        } elseif ($action === 'decide') {
            $decision = isset($_POST['legal_decision']) ? sanitize_key(wp_unslash($_POST['legal_decision'])) : '';
            $note     = isset($_POST['legal_note']) ? sanitize_textarea_field(wp_unslash($_POST['legal_note'])) : '';
            $result   = zw_doc_legal_manual_decision($doc_id, $decision, $note, get_current_user_id());
            $notice   = is_wp_error($result) ? $result->get_error_message() : ($decision === 'pass' ? '法务已通过。' : '法务已驳回。');
        } else {
            $notice = '未知操作。';
        }

        wp_safe_redirect(add_query_arg(array('zw_doc_msg' => $notice), $back));
        exit;
    }
}

if (!function_exists('zw_doc_legal_admin_url')) {
    /**
     * 「公文法务」页 URL。
     *
     * @param array $args 查询参数。
     * @return string
     */
    function zw_doc_legal_admin_url($args = array()) {
        return add_query_arg(array_merge(array('page' => 'zw-doc-legal'), (array) $args), admin_url('admin.php'));
    }
}

if (!function_exists('zw_doc_page_legal')) {
    /**
     * 「发文管理 → 公文法务」页：KPI + 待办列表 + 人工判定。
     *
     * @return void
     */
    function zw_doc_page_legal() {
        if (!current_user_can('manage_options') && !current_user_can('tcpc_perm_legal')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        $status = isset($_GET['legal_status']) ? sanitize_key(wp_unslash($_GET['legal_status'])) : '';
        $paged  = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $result = zw_doc_legal_query(array('status' => $status, 'page' => $paged, 'per_page' => 50));
        $notice = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';
        $can    = zw_doc_legal_can_review();
        $labels = zw_doc_legal_statuses();
        ?>
        <div class="wrap tco-doc-legal">
            <?php echo zw_doc_admin_ui_style(); // 唯一出处：SPEC-10 §1.9 卡片范式（TASK-778） ?>
            <style>
            .tco-doc-legal .tco-doc-kpi{display:flex;gap:18px;flex-wrap:wrap}
            .tco-doc-legal .tco-doc-kpi div{min-width:110px}
            .tco-doc-legal .tco-doc-kpi strong{display:block;font-size:20px}
            </style>
            <h1>公文法务</h1>
            <?php if ($notice !== '') { zw_doc_admin_notice($notice, (stripos($notice, '无权限') !== false || stripos($notice, '必须') !== false || stripos($notice, '失败') !== false) ? 'error' : 'success'); } ?>
            <p class="tco-doc-muted">
                复用 AI 法务部既有审核引擎（本机网关）；**失败一律转人工且不自动放行**；本页只保存结论与问题摘要，<strong>不保存正文全文</strong>。
            </p>

            <div class="tco-doc-card">
                <h2>概览</h2>
                <div class="tco-doc-kpi">
                    <?php foreach ($labels as $key => $label) : ?>
                        <div>
                            <strong><?php echo (int) (isset($result['counts'][$key]) ? $result['counts'][$key] : 0); ?></strong>
                            <a href="<?php echo esc_url(zw_doc_legal_admin_url(array('legal_status' => $key))); ?>"><?php echo esc_html($label); ?></a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="tco-doc-muted">
                    当前筛选：<?php echo $status !== '' ? esc_html(zw_doc_legal_status_label($status)) : '全部'; ?>（共 <?php echo (int) $result['total']; ?> 篇）
                    <?php if ($status !== '') : ?>｜<a href="<?php echo esc_url(zw_doc_legal_admin_url()); ?>">清除筛选</a><?php endif; ?>
                </p>
            </div>

            <div class="tco-doc-card">
                <h2>列表</h2>
                <!-- TASK-1633（TASK-1579 P1-6 第四批）：宽表落 canonical 横滑包裹。
                     规则由本模块唯一出处 zw_doc_admin_ui_style()（本页 :549 输出）提供 ⇒ 本页不新增 <style>。 -->
                <div class="tco-table-wrap">
                <table class="widefat striped">
                    <thead><tr><th>文号</th><th>标题</th><th>状态</th><th>结论 / 问题</th><th>时间</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php if (empty($result['items'])) : ?>
                        <tr><td colspan="6">暂无在线撰写且已送审的发文。</td></tr>
                    <?php else : ?>
                        <?php foreach ($result['items'] as $doc) : ?>
                            <?php $legal = $doc['legal']; ?>
                            <tr>
                                <td><?php echo esc_html((string) $doc['number']); ?></td>
                                <td><a href="<?php echo esc_url(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc['id']))); ?>"><?php echo esc_html((string) $doc['title']); ?></a></td>
                                <td><span class="tco-doc-badge"><?php echo esc_html(zw_doc_legal_status_label($legal['status'])); ?></span></td>
                                <td>
                                    <?php if ($legal['summary'] !== '') : ?><?php echo esc_html($legal['summary']); ?><?php endif; ?>
                                    <?php if (!empty($legal['issues'])) : ?>
                                        <div class="tco-doc-muted">
                                            <?php foreach (array_slice($legal['issues'], 0, 3) as $issue) : ?>
                                                · [<?php echo esc_html((string) $issue['severity']); ?>] <?php echo esc_html((string) $issue['message']); ?><br>
                                            <?php endforeach; ?>
                                            <?php if (count($legal['issues']) > 3) : ?>…共 <?php echo count($legal['issues']); ?> 条<?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($legal['clause_total'] > 0) : ?>
                                        <div class="tco-doc-muted">参与判定条款：<?php echo (int) $legal['clause_sent']; ?> / <?php echo (int) $legal['clause_total']; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="tco-doc-muted"><?php echo esc_html($legal['reviewed_at'] !== '' ? $legal['reviewed_at'] : $legal['submitted_at']); ?></td>
                                <td>
                                    <?php if ($can) : ?>
                                        <form method="post" style="display:inline">
                                            <?php wp_nonce_field('zw_doc_legal'); ?>
                                            <input type="hidden" name="zw_doc_legal_action" value="submit">
                                            <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                            <button class="button button-small" type="submit">重新送审</button>
                                        </form>
                                        <form method="post" style="display:inline">
                                            <?php wp_nonce_field('zw_doc_legal'); ?>
                                            <input type="hidden" name="zw_doc_legal_action" value="decide">
                                            <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                            <input type="hidden" name="legal_decision" value="pass">
                                            <button class="button button-small" type="submit">人工通过</button>
                                        </form>
                                        <form method="post" style="display:inline">
                                            <?php wp_nonce_field('zw_doc_legal'); ?>
                                            <input type="hidden" name="zw_doc_legal_action" value="decide">
                                            <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                            <input type="hidden" name="legal_decision" value="reject">
                                            <input type="text" name="legal_note" placeholder="驳回意见（必填）" style="width:150px">
                                            <button class="button button-small" type="submit">人工驳回</button>
                                        </form>
                                    <?php else : ?>
                                        <span class="tco-doc-muted">无判定权限</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('zw_doc_legal_process_event')) {
    /**
     * 异步送审事件回调（WP-Cron）。
     *
     * @param int $doc_id 发文 ID。
     * @param int $actor  触发人。
     * @return void
     */
    function zw_doc_legal_process_event($doc_id = 0, $actor = 0) {
        zw_doc_legal_submit((int) $doc_id, (int) $actor, true);
    }
}
add_action('zw_doc_legal_process_event', 'zw_doc_legal_process_event', 10, 2);
