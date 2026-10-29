<?php
/**
 * 发文仓储：CPT、meta、事件、附件与查询（TASK-433）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_register_post_type')) {
    /**
     * 注册私有发文 CPT。
     *
     * @return void
     */
    function zw_doc_register_post_type() {
        register_post_type('zw_official_doc', array(
            'labels'              => array(
                'name'          => '发文',
                'singular_name' => '发文',
            ),
            'public'              => false,
            'publicly_queryable'  => false,
            'query_var'           => false,
            'rewrite'             => false,
            'show_ui'             => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'supports'            => array('title'),
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        ));
    }
}

if (!function_exists('zw_doc_create_document')) {
    /**
     * 创建发文记录。
     *
     * @param array $data 发文数据。
     * @return int|WP_Error
     */
    function zw_doc_create_document($data) {
        $data = wp_parse_args($data, array(
            'title'           => '',
            'summary'         => '',
            'status'          => 'draft',
            'number'          => '',
            'number_id'       => 0,
            'reviewer_id'     => 0,
            // TASK-1345（批次四 D2/D4）：组织架构人员维度的留痕字段 —— 未绑 uid 的审核人/抄送人
            // 仍可在台账里被指名（`reviewer_id` = 0 时不能作为登录人审核，见 doc-admin-list.php）。
            'reviewer_person_id' => 0,
            'cc_person_ids'   => array(),
            'cc_names'        => array(),
            'cc_ids'          => array(),
            'attachment_ids'  => array(),
            'review_note'     => '',
            'idempotency'     => '',
            'department'      => '',
            'branch_id'       => 0,
            'remark'          => '',
            'reviewer_name'   => '',
            'leader_name'     => '',
            'issue_date'      => '',
            'source_number'   => '',
            'source'          => '',
            'import_key'      => '',
            'body'            => null,
            'layout'          => array(),
            'letterhead_id'   => 0,
            'seal_id'         => '',   // 字符串（hex），非整数
            'seal_mode'       => 'none',
        ));

        $title = sanitize_text_field((string) $data['title']);
        if ($title === '') {
            return new WP_Error('zw_doc_empty_title', '公文标题不能为空。');
        }

        $issue_date = sanitize_text_field((string) $data['issue_date']);
        $ledger_at  = '';
        if ($issue_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
            $ledger_at = $issue_date . ' 00:00:00';
        }

        $post_args = array(
            'post_type'   => 'zw_official_doc',
            'post_status' => 'private',
            'post_title'  => $title,
            'post_author' => get_current_user_id(),
        );
        // TASK-1259（用户拍板）：**发文日期是台账/流转字段，不再改写 WP `post_date`**。
        // 理由：`post_date` 决定该发文在台账与待办列表中的排序位置与「创建时间」口径，
        // 属「流转记录」语义之外的副作用（只填第 4 步发文日期会让新发文排到历史日期上去）。
        // 历史导入路径（`zw_doc_import_create_document`）保持既有 post_date 口径不变，
        // 以免打乱已导入台账的排序；如需统一请另立任务评估。
        $post_id = wp_insert_post($post_args, true);
        if (is_wp_error($post_id) || !$post_id) {
            return is_wp_error($post_id) ? $post_id : new WP_Error('zw_doc_create_failed', '发文记录创建失败。');
        }

        $status = in_array($data['status'], array('draft', 'pending'), true) ? $data['status'] : 'draft';
        $meta   = array(
            '_zw_doc_number'           => sanitize_text_field((string) $data['number']),
            '_zw_doc_number_id'        => (int) $data['number_id'],
            '_zw_doc_summary'          => sanitize_textarea_field((string) $data['summary']),
            '_zw_doc_status'           => $status,
            '_zw_doc_reviewer_id'      => (int) $data['reviewer_id'],
            '_zw_doc_reviewer_person_id' => (int) $data['reviewer_person_id'],
            '_zw_doc_cc_ids'           => array_values(array_unique(array_map('absint', (array) $data['cc_ids']))),
            '_zw_doc_cc_person_ids'    => array_values(array_unique(array_map('absint', (array) $data['cc_person_ids']))),
            '_zw_doc_cc_names'         => array_values(array_filter(array_map('sanitize_text_field', (array) $data['cc_names']), 'strlen')),
            '_zw_doc_attachment_ids'   => array_values(array_unique(array_map('absint', (array) $data['attachment_ids']))),
            '_zw_doc_review_note'      => sanitize_textarea_field((string) $data['review_note']),
            '_zw_doc_idempotency_key'  => sanitize_text_field((string) $data['idempotency']),
            '_zw_doc_updated_by'       => get_current_user_id(),
            '_zw_doc_department'       => sanitize_text_field((string) $data['department']),
            '_zw_doc_branch_id'        => (int) $data['branch_id'],
            '_zw_doc_remark'           => sanitize_textarea_field((string) $data['remark']),
            '_zw_doc_reviewer_name'    => sanitize_text_field((string) $data['reviewer_name']),
            '_zw_doc_leader_name'      => sanitize_text_field((string) $data['leader_name']),
            '_zw_doc_issue_date'       => $issue_date,
            '_zw_doc_source_number'    => sanitize_text_field((string) $data['source_number']),
            '_zw_doc_source'           => sanitize_text_field((string) $data['source']),
            '_zw_doc_import_key'       => sanitize_text_field((string) $data['import_key']),
        );
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        // 正文与版式（TASK-766）：只在确有正文时写入，保证旧记录零 meta、可回退。
        // TASK-1414：写入值必须经 `zw_doc_body_to_meta_stored()`（补 `wp_slash()`）——
        // `update_post_meta()` 落库前会 `wp_unslash()`，直接写 canonical JSON 会丢掉 appointments
        // 嵌套 JSON 的转义反斜杠，落库即非法 JSON，用印门禁 G7 永久阻断「完成文件」。
        if ($data['body'] !== null && function_exists('zw_doc_body_to_meta')) {
            $body   = zw_doc_body_normalize($data['body']);
            $stored = function_exists('zw_doc_body_to_meta_stored')
                ? zw_doc_body_to_meta_stored($body)
                : zw_doc_body_to_meta($body);
            update_post_meta($post_id, '_zw_doc_body_json', $stored);
            update_post_meta($post_id, '_zw_doc_body_hash', zw_doc_body_hash($body));
        }
        if (is_array($data['layout']) && !empty($data['layout'])) {
            update_post_meta($post_id, '_zw_doc_layout', $data['layout']);
        }
        update_post_meta($post_id, '_zw_doc_letterhead_id', (int) $data['letterhead_id']);
        // 印章 ID 为 bin2hex(random_bytes(8)) 生成的 16 位十六进制**字符串**，
        // 早期实现此处强转 (int) 会导致用印后取不到印章（首字符为数字时更会静默变成错误的短 ID）。
        // TASK-777 生产端到端实测发现并修正。
        update_post_meta($post_id, '_zw_doc_seal_id', sanitize_text_field((string) $data['seal_id']));
        update_post_meta($post_id, '_zw_doc_seal_mode', sanitize_key((string) $data['seal_mode']));
        update_post_meta($post_id, '_zw_doc_locked', 0);

        $now = current_time('mysql');
        // 台账时间轴起点仍取发文日期（若有），但不再影响 WP post_date / 列表排序（TASK-1259）。
        $created_at = $ledger_at !== '' ? $ledger_at : $now;
        update_post_meta($post_id, '_zw_doc_created_at', $created_at);
        update_post_meta($post_id, '_zw_doc_updated_at', $now);

        zw_doc_log_event($post_id, 'created', get_current_user_id(), '', $status, '创建发文');
        if ($status === 'pending') {
            update_post_meta($post_id, '_zw_doc_submitted_at', $now);
            zw_doc_log_event($post_id, 'submitted', get_current_user_id(), $status, 'pending', '提交审核');
        }

        return (int) $post_id;
    }
}

if (!function_exists('zw_doc_get_document')) {
    /**
     * 读取发文详情。
     *
     * @param int $post_id 发文 ID。
     * @return array|null
     */
    function zw_doc_get_document($post_id) {
        $post = get_post((int) $post_id);
        if (!$post || $post->post_type !== 'zw_official_doc') {
            return null;
        }
        return array(
            'id'             => (int) $post->ID,
            'title'          => (string) $post->post_title,
            'summary'        => (string) get_post_meta($post->ID, '_zw_doc_summary', true),
            'status'         => (string) get_post_meta($post->ID, '_zw_doc_status', true),
            'number'         => (string) get_post_meta($post->ID, '_zw_doc_number', true),
            'number_id'      => (int) get_post_meta($post->ID, '_zw_doc_number_id', true),
            'author_id'      => (int) $post->post_author,
            'reviewer_id'    => (int) get_post_meta($post->ID, '_zw_doc_reviewer_id', true),
            'reviewer_person_id' => (int) get_post_meta($post->ID, '_zw_doc_reviewer_person_id', true),
            'cc_ids'         => array_values(array_filter(array_map('absint', (array) get_post_meta($post->ID, '_zw_doc_cc_ids', true)))),
            'cc_person_ids'  => array_values(array_filter(array_map('absint', (array) get_post_meta($post->ID, '_zw_doc_cc_person_ids', true)))),
            'cc_names'       => array_values(array_filter(array_map('sanitize_text_field', (array) get_post_meta($post->ID, '_zw_doc_cc_names', true)), 'strlen')),
            // TASK-1350：「历史参与人」—— 改派/换抄送后**被换下的人保留可见**（用户拍板）。
            'flow_history_ids' => array_values(array_filter(array_map('absint', (array) get_post_meta($post->ID, '_zw_doc_flow_history_ids', true)))),
            'attachment_ids' => array_values(array_filter(array_map('absint', (array) get_post_meta($post->ID, '_zw_doc_attachment_ids', true)))),
            // TASK-1644 阶段四：关联网盘文件（归档通道，按 zw_file_doc_id 反查；与媒体库附件是两套通道）。
            'linked_files'   => zw_doc_linked_office_files((int) $post->ID),
            'review_note'    => (string) get_post_meta($post->ID, '_zw_doc_review_note', true),
            'idempotency'    => (string) get_post_meta($post->ID, '_zw_doc_idempotency_key', true),
            'department'     => (string) get_post_meta($post->ID, '_zw_doc_department', true),
            'branch_id'      => (int) get_post_meta($post->ID, '_zw_doc_branch_id', true),
            'remark'         => (string) get_post_meta($post->ID, '_zw_doc_remark', true),
            'reviewer_name'  => (string) get_post_meta($post->ID, '_zw_doc_reviewer_name', true),
            'leader_name'    => (string) get_post_meta($post->ID, '_zw_doc_leader_name', true),
            'issue_date'     => (string) get_post_meta($post->ID, '_zw_doc_issue_date', true),
            'source_number'  => (string) get_post_meta($post->ID, '_zw_doc_source_number', true),
            'source'         => (string) get_post_meta($post->ID, '_zw_doc_source', true),
            'import_key'     => (string) get_post_meta($post->ID, '_zw_doc_import_key', true),
            // 正文与用印（TASK-766）：body 为 null 表示「这篇发文没有正文」，调用方须回退既有渲染路径。
            'body'           => function_exists('zw_doc_body_parse') ? zw_doc_body_parse(get_post_meta($post->ID, '_zw_doc_body_json', true)) : null,
            'body_hash'      => (string) get_post_meta($post->ID, '_zw_doc_body_hash', true),
            'layout'         => (array) get_post_meta($post->ID, '_zw_doc_layout', true),
            'letterhead_id'  => (int) get_post_meta($post->ID, '_zw_doc_letterhead_id', true),
            'seal_id'        => (string) get_post_meta($post->ID, '_zw_doc_seal_id', true),
            'seal_mode'      => (string) get_post_meta($post->ID, '_zw_doc_seal_mode', true),
            'locked'         => (int) get_post_meta($post->ID, '_zw_doc_locked', true) === 1,
            'created_at'     => (string) get_post_meta($post->ID, '_zw_doc_created_at', true),
            'updated_at'     => (string) get_post_meta($post->ID, '_zw_doc_updated_at', true),
            'submitted_at'   => (string) get_post_meta($post->ID, '_zw_doc_submitted_at', true),
            'approved_at'    => (string) get_post_meta($post->ID, '_zw_doc_approved_at', true),
            'rejected_at'    => (string) get_post_meta($post->ID, '_zw_doc_rejected_at', true),
        );
    }
}

if (!function_exists('zw_doc_linked_office_files')) {
    /**
     * 读取发文关联的「网盘文件」记录（TASK-1644 阶段四：发文详情页展示用）。
     *
     * 关联位：`zw_office_file` 的 meta `zw_file_doc_id` = 发文 ID（迁移 CLI 与后台上传均写入）。
     * 与「附件」（媒体库 `_zw_doc_attachment_ids`）是两套通道，互不影响、互不覆盖。
     *
     * @param int $doc_id 发文 ID。
     * @return array<int,array<string,mixed>> 元素含 id/name/path/scope/kind/size/uploaded_at；无关联返回空数组。
     */
    function zw_doc_linked_office_files($doc_id) {
        $doc_id = (int) $doc_id;
        if ($doc_id <= 0 || !function_exists('get_posts')) {
            return array();
        }
        $ids = get_posts(array(
            'post_type'        => 'zw_office_file',
            'post_status'      => 'publish',
            'posts_per_page'   => 50,
            'fields'           => 'ids',
            'orderby'          => 'date',
            'order'            => 'ASC',
            'meta_query'       => array(
                array('key' => 'zw_file_doc_id', 'value' => $doc_id, 'type' => 'NUMERIC'),
            ),
            'suppress_filters' => true,
        ));
        $out = array();
        foreach ((array) $ids as $fid) {
            // TASK-1649（发布基线对齐）：`get_posts(..., 'fields' => 'ids')` 在真实 WP 下返回 int 数组，
            // 但测试精简环境的 `get_posts` 桩件可能忽略 `fields` 而返回 post 对象 ⇒ 此处**统一归一化**，
            // 既避免「对象转 int」告警 / `get_post_meta()` 类型错误，也不改变生产行为。
            $fid = is_object($fid) ? (int) ($fid->ID ?? 0) : (int) $fid;
            if ($fid <= 0) {
                continue;
            }
            $out[] = array(
                'id'          => $fid,
                'name'        => (string) get_post_meta($fid, 'zw_file_original_name', true),
                'path'        => (string) get_post_meta($fid, 'zw_file_path', true),
                'scope'       => (string) get_post_meta($fid, 'zw_file_scope', true),
                'kind'        => (string) get_post_meta($fid, 'zw_file_kind', true),
                'size'        => (int) get_post_meta($fid, 'zw_file_size', true),
                'uploaded_at' => (string) get_post_meta($fid, 'zw_file_uploaded_at', true),
            );
        }
        return $out;
    }
}

if (!function_exists('zw_doc_is_locked')) {
    /**
     * 是否已用印锁定（锁定后正文只读）。
     *
     * @param int $post_id 发文 ID。
     * @return bool
     */
    function zw_doc_is_locked($post_id) {
        return (int) get_post_meta((int) $post_id, '_zw_doc_locked', true) === 1;
    }
}

if (!function_exists('zw_doc_set_locked')) {
    /**
     * 设置 / 解除用印锁定。
     *
     * 用印是不可逆动作：锁定由用印流程调用，解除必须走「撤销用印 → 重新审核」。
     *
     * @param int    $post_id  发文 ID。
     * @param bool   $locked   是否锁定。
     * @param int    $actor_id 操作人。
     * @param string $note     说明。
     * @return bool
     */
    function zw_doc_set_locked($post_id, $locked, $actor_id = 0, $note = '') {
        $post_id = (int) $post_id;
        $doc     = zw_doc_get_document($post_id);
        if (!$doc) {
            return false;
        }
        $actor_id = $actor_id ? (int) $actor_id : get_current_user_id();
        update_post_meta($post_id, '_zw_doc_locked', $locked ? 1 : 0);
        update_post_meta($post_id, '_zw_doc_updated_by', $actor_id);
        update_post_meta($post_id, '_zw_doc_updated_at', current_time('mysql'));
        zw_doc_log_event(
            $post_id,
            $locked ? 'locked' : 'unlocked',
            $actor_id,
            (string) $doc['status'],
            (string) $doc['status'],
            $note !== '' ? $note : ($locked ? '用印后锁定正文' : '解除用印锁定')
        );

        return true;
    }
}

if (!function_exists('zw_doc_update_body')) {
    /**
     * 更新正文 AST（正文的唯一写入口）。
     *
     * 铁律：
     * - 已用印锁定（`_zw_doc_locked`）时**拒绝写入**，必须先撤销用印并重新审核；
     * - 先校验原始输入（超限**硬拒绝并给明确原因**，不静默截断误导用户），再归一化后落库；
     * - 事件说明只记「段数 / 字数」，**不记正文内容**（隐私要求）。
     *
     * @param int   $post_id  发文 ID。
     * @param mixed $body     正文 AST 或原始结构。
     * @param int   $actor_id 操作人。
     * @return true|WP_Error
     */
    function zw_doc_update_body($post_id, $body, $actor_id = 0) {
        $post_id = (int) $post_id;
        $doc     = zw_doc_get_document($post_id);
        if (!$doc) {
            return new WP_Error('zw_doc_not_found', '发文不存在。');
        }
        if (!empty($doc['locked'])) {
            return new WP_Error('zw_doc_locked', '已用印锁定，修改前请先撤销用印并重新审核。');
        }
        if (!function_exists('zw_doc_body_normalize') || !function_exists('zw_doc_body_validate')) {
            return new WP_Error('zw_doc_body_model_missing', '正文模型未加载。');
        }

        $problems = zw_doc_body_validate($body);
        if (!empty($problems)) {
            $first = $problems[0];
            return new WP_Error('zw_doc_body_invalid_' . $first['code'], $first['message']);
        }

        $normalized = zw_doc_body_normalize($body);
        // TASK-1414：落库值必须经 `zw_doc_body_to_meta_stored()`（补 `wp_slash()`），
        // 否则 `update_post_meta()` 的 `wp_unslash()` 会吃掉 appointments 嵌套 JSON 的转义反斜杠。
        $json       = function_exists('zw_doc_body_to_meta_stored')
            ? zw_doc_body_to_meta_stored($normalized)
            : zw_doc_body_to_meta($normalized);
        if ($json === '') {
            return new WP_Error('zw_doc_body_encode_failed', '正文序列化失败。');
        }

        $actor_id = $actor_id ? (int) $actor_id : get_current_user_id();
        update_post_meta($post_id, '_zw_doc_body_json', $json);
        update_post_meta($post_id, '_zw_doc_body_hash', zw_doc_body_hash($normalized));
        update_post_meta($post_id, '_zw_doc_updated_by', $actor_id);
        update_post_meta($post_id, '_zw_doc_updated_at', current_time('mysql'));
        zw_doc_log_event(
            $post_id,
            'body_updated',
            $actor_id,
            (string) $doc['status'],
            (string) $doc['status'],
            '更新正文（' . count($normalized['blocks']) . ' 段 / ' . zw_doc_body_count_chars($normalized) . ' 字）'
        );

        return true;
    }
}

if (!function_exists('zw_doc_set_status')) {
    /**
     * 流转状态。
     *
     * @param int    $post_id   发文 ID。
     * @param string $status    新状态。
     * @param int    $actor_id  操作人。
     * @param string $note      说明。
     * @return bool
     */
    function zw_doc_set_status($post_id, $status, $actor_id = 0, $note = '') {
        $post_id = (int) $post_id;
        $doc     = zw_doc_get_document($post_id);
        if (!$doc) {
            return false;
        }
        $allowed = array('draft', 'pending', 'approved', 'rejected', 'cancelled');
        if (!in_array($status, $allowed, true)) {
            return false;
        }
        $actor_id = $actor_id ? (int) $actor_id : get_current_user_id();
        $now      = current_time('mysql');
        $old      = $doc['status'];
        update_post_meta($post_id, '_zw_doc_status', $status);
        update_post_meta($post_id, '_zw_doc_updated_by', $actor_id);
        update_post_meta($post_id, '_zw_doc_updated_at', $now);
        if ($note !== '') {
            update_post_meta($post_id, '_zw_doc_review_note', sanitize_textarea_field($note));
        }
        if ($status === 'pending') {
            update_post_meta($post_id, '_zw_doc_submitted_at', $now);
        } elseif ($status === 'approved') {
            update_post_meta($post_id, '_zw_doc_approved_at', $now);
        } elseif ($status === 'rejected') {
            update_post_meta($post_id, '_zw_doc_rejected_at', $now);
        }
        zw_doc_log_event($post_id, 'status_' . $status, $actor_id, $old, $status, $note);

        // TASK-1291：流转完成广播钩子（唯一咽喉）。监听侧自行判配置与文种，
        // 未来新增审核入口（API / 批量 / CLI）只要走本函数就不会**静默漏同步**。
        do_action('zw_doc_flow_completed', $post_id, $status, array(
            'from'  => $old,
            'to'    => $status,
            'actor' => $actor_id,
            'note'  => $note,
        ));

        return true;
    }
}

if (!function_exists('zw_doc_log_event')) {
    /**
     * 写流转事件。
     *
     * @param int    $document_id 发文 ID。
     * @param string $event_type  事件类型。
     * @param int    $actor_id    操作人。
     * @param string $from        原状态。
     * @param string $to          新状态。
     * @param string $note        说明。
     * @return void
     */
    function zw_doc_log_event($document_id, $event_type, $actor_id = 0, $from = '', $to = '', $note = '') {
        global $wpdb;
        $table = zw_doc_table('events');
        $wpdb->insert(
            $table,
            array(
                'document_id' => (int) $document_id,
                'event_type'  => sanitize_key($event_type),
                'actor_id'    => (int) $actor_id,
                'from_status' => sanitize_key($from),
                'to_status'   => sanitize_key($to),
                'note'        => sanitize_textarea_field((string) $note),
                'created_at'  => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%s', '%s', '%s', '%s')
        );
    }
}

if (!function_exists('zw_doc_get_events')) {
    /**
     * 读取流转事件。
     *
     * @param int $document_id 发文 ID。
     * @return array[]
     */
    function zw_doc_get_events($document_id) {
        global $wpdb;
        $table = zw_doc_table('events');
        $rows  = $wpdb->get_results(zw_doc_prepare_sql(
            "SELECT * FROM {$table} WHERE document_id=%d ORDER BY id ASC",
            array((int) $document_id)
        ), ARRAY_A);
        return (array) $rows;
    }
}

if (!function_exists('zw_doc_query_documents')) {
    /**
     * 查询发文。MVP 数据量小，先取回后做数组过滤，避免复杂 meta 查询。
     *
     * @param array $args 查询条件。
     * @return array{items:array,total:int}
     */
    function zw_doc_query_documents($args = array()) {
        $args = wp_parse_args($args, array(
            'author_id'         => 0,
            'reviewer_id'       => 0,
            'cc_user_id'        => 0,
            // TASK-1386 ⑤：「我的收文」——**发给本人**的收文（收件人 / 抄送包含本人）。
            // 数据模型里能映射到「用户」的收件方只有两类：抄送（`_zw_doc_cc_ids`，uid 列表）
            // 与审核人（`_zw_doc_reviewer_id`，uid）；主送机关 `body.meta.receiver` 是**文本**（无 uid），
            // 因此无法按用户过滤（已在 CHG-211 记录理由）。本参数是**唯一**的收文过滤出处。
            'recipient_user_id' => 0,
            'status'            => '',
            'statuses'          => array(),
            'number_year'       => 0,
            'number_keyword'    => '',
            'page'              => 1,
            'per_page'          => 20,
        ));

        $posts = get_posts(array(
            'post_type'        => 'zw_official_doc',
            'post_status'      => 'private',
            'posts_per_page'   => -1,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => true,
        ));

        $items = array();
        foreach ((array) $posts as $post) {
            $doc = zw_doc_get_document($post->ID);
            if (!$doc) {
                continue;
            }
            if (!empty($args['author_id']) && (int) $doc['author_id'] !== (int) $args['author_id']) {
                continue;
            }
            if (!empty($args['reviewer_id']) && (int) $doc['reviewer_id'] !== (int) $args['reviewer_id']) {
                continue;
            }
            if (!empty($args['cc_user_id']) && !in_array((int) $args['cc_user_id'], $doc['cc_ids'], true)) {
                continue;
            }
            if (!empty($args['recipient_user_id'])) {
                // 「发给我」= 抄送我（cc_ids 含本人）或 我是该文审核人（reviewer_id）。
                $recv = (int) $args['recipient_user_id'];
                $is_recipient = in_array($recv, array_map('intval', (array) $doc['cc_ids']), true)
                    || (int) $doc['reviewer_id'] === $recv;
                if (!$is_recipient) {
                    continue;
                }
            }
            if (!empty($args['status']) && $doc['status'] !== $args['status']) {
                continue;
            }
            if (!empty($args['statuses']) && !in_array($doc['status'], (array) $args['statuses'], true)) {
                continue;
            }
            if (!empty($args['number_year']) && (int) zw_doc_number_year($doc['number']) !== (int) $args['number_year']) {
                continue;
            }
            if ((string) $args['number_keyword'] !== '') {
                $keyword = (string) $args['number_keyword'];
                $haystack = (string) $doc['number'] . ' ' . (string) $doc['source_number'];
                if (stripos($haystack, $keyword) === false) {
                    continue;
                }
            }
            $items[] = $doc;
        }

        $total    = count($items);
        $per_page = max(1, (int) $args['per_page']);
        $page     = max(1, (int) $args['page']);
        $items    = array_slice($items, ($page - 1) * $per_page, $per_page);

        return array('items' => $items, 'total' => $total);
    }
}

if (!function_exists('zw_doc_is_dashboard_admin')) {
    /**
     * 是否**仪表盘管理员**（TASK-1386 ⑤ 用户拍板「我的收文 所有仪表盘管理员皆可见」）。
     *
     * 口径**唯一**：复用项目既有管理员判定 `zw_doc_is_super_admin()`（`manage_options` +
     * `zw_doc_is_super_admin` 过滤器），**不新造第二套**；该函数缺失时回退到 `manage_options`。
     *
     * @param int $user_id 用户 ID（0 = 当前用户）。
     * @return bool
     */
    function zw_doc_is_dashboard_admin($user_id = 0) {
        if (function_exists('zw_doc_is_super_admin')) {
            return (bool) zw_doc_is_super_admin($user_id);
        }
        $uid = (int) $user_id;
        if ($uid > 0 && function_exists('user_can')) {
            return (bool) user_can($uid, 'manage_options');
        }

        return function_exists('current_user_can') && (bool) current_user_can('manage_options');
    }
}

if (!function_exists('zw_doc_query_inbox_documents')) {
    /**
     * 「我的收文」查询 —— **服务端唯一权威入口**（TASK-1386 ⑤）。
     *
     * 用户拍板原文（2026-10-10）：「我的收文 所有仪表盘管理员皆可见」。
     *
     * 口径（两条分支，服务端判定，**禁止靠前端隐藏**）：
     * - **仪表盘管理员**（`zw_doc_is_dashboard_admin()`，即既有 `manage_options` 口径）：
     *   可见**全部**已发布（`approved`）收文；
     * - **普通会员**：只可见**发给本人**的收文 = 抄送包含本人（`_zw_doc_cc_ids`）或本人是该文
     *   审核人（`_zw_doc_reviewer_id`），且已发布；候选集由 canonical
     *   `zw_doc_query_documents(['recipient_user_id' => $uid])` 产出（**唯一收文过滤出处**）；
     * - 两个分支的候选项**逐条再经** `zw_doc_can_view_doc($doc, $uid)` 复验（canonical 可见性判定），
     *   复验后重新计数 ⇒ 分页总数与真实可见集合一致，**既不漏也不越权**。
     *
     * @param int $user_id  用户 ID（0 = 当前用户）。
     * @param int $page     页码（从 1 起）。
     * @param int $per_page 每页条数。
     * @return array{items:array,total:int,pages:int,page:int,per_page:int,is_admin:bool}
     */
    function zw_doc_query_inbox_documents($user_id = 0, $page = 1, $per_page = 10) {
        $uid = (int) $user_id;
        if ($uid <= 0 && function_exists('get_current_user_id')) {
            $uid = (int) get_current_user_id();
        }
        $page     = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $is_admin = $uid > 0 && zw_doc_is_dashboard_admin($uid);

        $out = array(
            'items'    => array(),
            'total'    => 0,
            'pages'    => 1,
            'page'     => $page,
            'per_page' => $per_page,
            'is_admin' => $is_admin,
        );
        if ($uid <= 0 || !function_exists('zw_doc_query_documents')) {
            return $out;
        }

        $args = array(
            // 「已发布」：`approved` = 已通过（本模块唯一的对外发布态）。
            'statuses' => array('approved'),
            'page'     => 1,
            // 先取全量候选，逐条复验后再自行分页 ⇒ total 与可见集合严格一致。
            'per_page' => 100000,
        );
        if (!$is_admin) {
            // 管理员分支**不带**收文过滤 ⇒ 全部已发布收文；会员分支只带本人。
            $args['recipient_user_id'] = $uid;
        }
        $result = zw_doc_query_documents($args);

        $items = array();
        foreach ((array) (isset($result['items']) ? $result['items'] : array()) as $doc) {
            if (!function_exists('zw_doc_can_view_doc') || !zw_doc_can_view_doc($doc, $uid)) {
                continue;
            }
            $items[] = $doc;
        }

        $out['total'] = count($items);
        $out['items'] = array_slice($items, ($page - 1) * $per_page, $per_page);
        $out['pages'] = (int) max(1, (int) ceil($out['total'] / $per_page));

        return $out;
    }
}

if (!function_exists('zw_doc_count_pending_for_user')) {
    /**
     * 待某管理员审核的数量。
     *
     * @param int $user_id 用户 ID。
     * @return int
     */
    function zw_doc_count_pending_for_user($user_id) {
        $result = zw_doc_query_documents(array(
            'reviewer_id' => (int) $user_id,
            'status'      => 'pending',
            'per_page'    => 1,
        ));
        return (int) $result['total'];
    }
}

if (!function_exists('zw_doc_download_url')) {
    /**
     * 附件鉴权下载 URL。
     *
     * @param int $document_id   发文 ID。
     * @param int $attachment_id 附件 ID。
     * @return string
     */
    function zw_doc_download_url($document_id, $attachment_id) {
        $url = add_query_arg(array(
            'action'        => 'zw_doc_download',
            'doc_id'        => (int) $document_id,
            'attachment_id' => (int) $attachment_id,
        ), admin_url('admin-post.php'));
        return wp_nonce_url($url, 'zw_doc_download_' . (int) $document_id . '_' . (int) $attachment_id);
    }
}

if (!function_exists('zw_doc_attachment_name')) {
    /**
     * 附件展示名。
     *
     * @param int $attachment_id 附件 ID。
     * @return string
     */
    function zw_doc_attachment_name($attachment_id) {
        $path = get_attached_file((int) $attachment_id);
        return $path ? wp_basename($path) : ('#' . (int) $attachment_id);
    }
}

if (!function_exists('zw_doc_rearray_files')) {
    /**
     * 将 $_FILES 多文件结构转为逐文件数组。
     *
     * @param array $files 原始 $_FILES 项。
     * @return array[]
     */
    function zw_doc_rearray_files($files) {
        $out = array();
        if (!is_array($files) || !isset($files['name'])) {
            return $out;
        }
        $names = is_array($files['name']) ? $files['name'] : array($files['name']);
        foreach ($names as $i => $name) {
            if ((string) $name === '') {
                continue;
            }
            $out[] = array(
                'name'     => (string) $name,
                'type'     => is_array($files['type']) ? (string) $files['type'][$i] : (string) $files['type'],
                'tmp_name' => is_array($files['tmp_name']) ? (string) $files['tmp_name'][$i] : (string) $files['tmp_name'],
                'error'    => is_array($files['error']) ? (int) $files['error'][$i] : (int) $files['error'],
                'size'     => is_array($files['size']) ? (int) $files['size'][$i] : (int) $files['size'],
            );
        }
        return $out;
    }
}

if (!function_exists('zw_doc_validate_attachment')) {
    /**
     * 校验单个附件。
     *
     * @param array $file 单文件数组。
     * @return true|WP_Error
     */
    function zw_doc_validate_attachment($file) {
        $allowed_ext = array('doc', 'docx', 'pdf', 'xls', 'xlsx', 'zip', 'jpg', 'jpeg', 'png');
        $max_size    = 20 * 1024 * 1024;

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('zw_doc_upload_error', '附件上传失败：' . (string) $file['error']);
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > $max_size) {
            return new WP_Error('zw_doc_upload_size', '附件大小必须在 20MB 以内。');
        }

        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        $ext   = strtolower((string) ($check['ext'] ?: pathinfo($file['name'], PATHINFO_EXTENSION)));
        if (!in_array($ext, $allowed_ext, true)) {
            return new WP_Error('zw_doc_upload_type', '不支持的附件类型：' . esc_html($ext));
        }
        if (empty($check['type'])) {
            return new WP_Error('zw_doc_upload_type', '无法识别附件真实类型。');
        }
        return true;
    }
}

if (!function_exists('zw_doc_handle_attachments')) {
    /**
     * 处理上传附件并绑定到发文。
     *
     * @param int    $post_id    发文 ID。
     * @param string $input_name 文件 input 名。
     * @return array{ids:array,errors:array}
     */
    function zw_doc_handle_attachments($post_id, $input_name = 'zw_doc_attachments') {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $result = array('ids' => array(), 'errors' => array());
        if (empty($_FILES[$input_name])) {
            return $result;
        }

        $files = zw_doc_rearray_files($_FILES[$input_name]);
        if (count($files) > 10) {
            $result['errors'][] = '单篇最多上传 10 个附件。';
            return $result;
        }

        foreach ($files as $index => $file) {
            $valid = zw_doc_validate_attachment($file);
            if (is_wp_error($valid)) {
                $result['errors'][] = $valid->get_error_message();
                continue;
            }
            $_FILES['zw_doc_single_upload'] = $file;
            $attachment_id = media_handle_upload('zw_doc_single_upload', (int) $post_id);
            unset($_FILES['zw_doc_single_upload']);
            if (is_wp_error($attachment_id)) {
                $result['errors'][] = '第 ' . ($index + 1) . ' 个附件上传失败：' . $attachment_id->get_error_message();
                continue;
            }
            $result['ids'][] = (int) $attachment_id;
        }

        if (!empty($result['ids'])) {
            $existing = array_values(array_filter(array_map('absint', (array) get_post_meta((int) $post_id, '_zw_doc_attachment_ids', true))));
            $merged   = array_values(array_unique(array_merge($existing, $result['ids'])));
            update_post_meta((int) $post_id, '_zw_doc_attachment_ids', array_slice($merged, 0, 10));
        }
        return $result;
    }
}
