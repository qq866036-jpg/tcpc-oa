<?php
/**
 * 后台「发文管理」页面：列表、起草、详情、单级审核（TASK-433）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_admin_url')) {
    /**
     * 发文管理页 URL。
     *
     * @param array $args 查询参数。
     * @return string
     */
    function zw_doc_admin_url($args = array()) {
        return add_query_arg(array_merge(array('page' => 'zw-doc'), $args), admin_url('admin.php'));
    }
}

if (!function_exists('zw_doc_ledger_admin_url')) {
    /**
     * 发文台账页 URL。
     *
     * @param array $args 查询参数。
     * @return string
     */
    function zw_doc_ledger_admin_url($args = array()) {
        return add_query_arg(array_merge(array('page' => 'zw-doc-ledger'), $args), admin_url('admin.php'));
    }
}

if (!function_exists('zw_doc_list_handle_post')) {
    /**
     * 在 admin_init 阶段处理发文管理 POST，避免在页面输出后重定向。
     *
     * @return void
     */
    function zw_doc_list_handle_post() {
        if ((($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')) {
            return;
        }

        if (isset($_POST['zw_doc_skip_button'])) {
            check_admin_referer('zw_doc_create_document');
            $number_settings = zw_doc_number_settings();
            $year   = (int) $number_settings['default_year'];
            $type   = isset($_POST['num_type']) ? sanitize_text_field(wp_unslash($_POST['num_type'])) : '';
            $seq    = isset($_POST['skip_seq']) ? (int) $_POST['skip_seq'] : 0;
            $reason = isset($_POST['skip_reason']) ? sanitize_text_field(wp_unslash($_POST['skip_reason'])) : '';
            $res    = zw_doc_skip_number($year, $type, $seq, $reason);
            $msg    = is_wp_error($res) ? $res->get_error_message() : '已跳过 ' . zw_doc_format_number(zw_doc_number_settings(), $type, $year, $seq);
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'num_year' => $year, 'num_type' => $type, 'zw_doc_msg' => $msg)));
            exit;
        }

        if (!isset($_POST['zw_doc_action'])) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['zw_doc_action']));

        if ($action === 'create_document') {
            check_admin_referer('zw_doc_create_document');
            zw_doc_handle_create_document();
            exit;
        }

        if ($action === 'review_document') {
            check_admin_referer('zw_doc_review_document');
            zw_doc_handle_review_document();
            exit;
        }

        if ($action === 'withdraw_document') {
            check_admin_referer('zw_doc_withdraw_document');
            zw_doc_handle_withdraw_document();
            exit;
        }
    }
}

if (!function_exists('zw_doc_page_list')) {
    /**
     * 发文管理页。
     *
     * @return void
     */
    function zw_doc_page_list() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }

        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'list';
        $view = in_array($view, array('list', 'create', 'detail', 'compose'), true) ? $view : 'list';

        if ($view === 'create') {
            // 在线撰写开关（TASK-772）：关闭时完全走既有表单（逐字段一致，可秒级回退）。
            if (function_exists('zw_doc_composer_enabled') && zw_doc_composer_enabled()) {
                zw_doc_render_composer_page();
            } else {
                zw_doc_render_create_page();
            }
        } elseif ($view === 'compose') {
            $compose_id = isset($_GET['doc_id']) ? (int) $_GET['doc_id'] : 0;
            zw_doc_render_composer_page($compose_id);
        } elseif ($view === 'detail') {
            zw_doc_render_detail_page();
        } else {
            zw_doc_render_list_page();
        }
    }
}

if (!function_exists('zw_doc_page_create')) {
    /**
     * 「新建发文」子菜单入口（TASK-786）。
     *
     * 直接复用既有的 `view=create` 渲染分支，因此：
     * - 在线撰写开关（`zw_doc_composer_enabled`）仍然生效：关闭时走旧表单；
     * - 页面权限、POST 契约、取号与幂等逻辑零改动。
     *
     * @return void
     */
    function zw_doc_page_create() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        if (function_exists('zw_doc_composer_enabled') && zw_doc_composer_enabled()) {
            zw_doc_render_composer_page();
        } else {
            zw_doc_render_create_page();
        }
    }
}

if (!function_exists('zw_doc_page_ledger')) {
    /**
     * 发文台账页。
     *
     * @return void
     */
    function zw_doc_page_ledger() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        zw_doc_render_list_page('ledger');
    }
}

if (!function_exists('zw_doc_handle_create_document')) {
    /**
     * 处理新建发文。
     *
     * @return void
     */
    function zw_doc_handle_create_document() {
        $settings = zw_doc_number_settings();
        // TASK-1271（用户拍板 A1）：**以「发文机关」为准**归一 红头 / 署名 / 发文部门 ——
        // 三处回显控件只是呈现层，构造 POST 也能改，故服务端在读取任何字段前先归一。
        if (function_exists('zw_doc_apply_org_pick')) {
            $_POST = zw_doc_apply_org_pick(wp_unslash($_POST));
        }
        $org_pick = isset($_POST['zwc_org_pick']) ? sanitize_text_field(wp_unslash($_POST['zwc_org_pick'])) : '';
        if ($org_pick !== '' && function_exists('zw_doc_department_names')
            && !in_array($org_pick, zw_doc_department_names(), true)) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'zw_doc_msg' => '发文机关必须从名录中选择（中国传统文化促进会或正常状态的分支机构）。')));
            exit;
        }
        // 用户确认：新建发文不允许选年份，服务端也固定使用默认年份。
        $year     = (int) $settings['default_year'];
        $type     = isset($_POST['num_type']) ? sanitize_text_field(wp_unslash($_POST['num_type'])) : '';
        $title    = isset($_POST['doc_title']) ? sanitize_text_field(wp_unslash($_POST['doc_title'])) : '';
        $summary  = isset($_POST['doc_summary']) ? sanitize_textarea_field(wp_unslash($_POST['doc_summary'])) : '';
        $department = isset($_POST['doc_department']) ? sanitize_text_field(wp_unslash($_POST['doc_department'])) : '';
        $remark     = isset($_POST['doc_remark']) ? sanitize_textarea_field(wp_unslash($_POST['doc_remark'])) : '';
        // TASK-1347（用户 2026-10-09 拍板）：「审核（历史记录，不关联系统人员）」**并入审核人** ——
        // 两处表单均已删除该文本字段 ⇒ 此处**不再读取** `doc_reviewer_name`；
        // 该 meta 现只由**导入路径**写死固定短语（`zw_doc_reviewer_legacy_phrase()`）。
        $leader_name   = isset($_POST['doc_leader_name']) ? sanitize_text_field(wp_unslash($_POST['doc_leader_name'])) : '';
        $issue_date    = isset($_POST['doc_issue_date']) ? sanitize_text_field(wp_unslash($_POST['doc_issue_date'])) : '';
        // TASK-1345（批次四 D2/D4）：审核人 / 抄送人提交值改为「候选值」（`p:<人员ID>` / `u:<用户ID>`），
        // 解析与白名单在下方统一处理；这里保留原始串，**不做** `(int)` 强转（否则 `p:26242` 会被截成 0）。
        $reviewer_value = isset($_POST['doc_reviewer_id']) ? sanitize_text_field(wp_unslash($_POST['doc_reviewer_id'])) : '';
        $cc_values      = isset($_POST['doc_cc_ids']) ? array_map('strval', (array) wp_unslash($_POST['doc_cc_ids'])) : array();
        $mode     = isset($_POST['zw_doc_create_mode']) ? sanitize_key(wp_unslash($_POST['zw_doc_create_mode'])) : 'draft';
        $status   = $mode === 'submit' ? 'pending' : 'draft';
        $idem     = isset($_POST['zw_doc_idempotency_key']) ? sanitize_text_field(wp_unslash($_POST['zw_doc_idempotency_key'])) : '';
        $reserved = isset($_POST['reserved_number_id']) ? (int) $_POST['reserved_number_id'] : 0;

        if ($idem !== '') {
            $existing = get_posts(array(
                'post_type'      => 'zw_official_doc',
                'post_status'    => 'private',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array('key' => '_zw_doc_idempotency_key', 'value' => $idem),
                ),
                'suppress_filters' => true,
            ));
            if (!empty($existing)) {
                wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => (int) $existing[0], 'zw_doc_msg' => '该表单已提交过，已为你打开原发文。')));
                exit;
            }
        }

        $year = zw_doc_validate_year($year);
        $type = zw_doc_validate_doc_type($type, $settings);
        if (!$year || !$type) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'zw_doc_msg' => '年份或文种不合法。')));
            exit;
        }
        if ($title === '') {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'zw_doc_msg' => '公文标题不能为空。')));
            exit;
        }
        if ($issue_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue_date)) {
            $issue_date = '';
        }
        $department_match = zw_doc_import_find_department($department);
        $branch_id        = (int) $department_match['branch_id'];
        if ($department_match['matched']) {
            $department = (string) $department_match['department'];
        }
        // TASK-1259（用户拍板）：发文部门不接受自由文本 —— 只允许「协会本级 + 正常状态分支机构」名录内的取值。
        // 前端已是 SPEC-85 canonical 搜索下拉，这里做服务端二次校验（控件的前端校验只是便利，不是权威）。
        if (!zw_doc_department_is_allowed($department)) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'zw_doc_msg' => '发文部门必须从名录中选择（中国传统文化促进会或正常状态的分支机构）。')));
            exit;
        }

        // TASK-1345（批次四 D2/D4）：候选人来源 = 「公文流转配置」按组织架构部门取人；
        // 未配置部门时 `zw_doc_flow_candidates()` 逐字沿用历史「管理员集合」口径 ⇒ 未配置即零行为变更。
        // 服务端是**唯一权威**：前端下拉只是便利，提交值必须命中候选名单（含历史裸 uid 的兼容分支）。
        $flow               = zw_doc_flow_settings();
        // 候选**只构建一次**（每个配置部门 1 次查询），再在列表上解析 —— 避免「每个抄送项重建一遍候选」的 N+1。
        $reviewer_pool      = zw_doc_flow_candidates('reviewer');
        $cc_pool            = zw_doc_flow_candidates('cc');
        $reviewer_candidate = zw_doc_flow_resolve_from($reviewer_pool, $reviewer_value);
        if (!$reviewer_candidate
            || ((int) $reviewer_candidate['user_id'] > 0 && (int) $reviewer_candidate['user_id'] === get_current_user_id())) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'zw_doc_msg' => '请选择有效的审核人，且不能选择自己。')));
            exit;
        }
        // TASK-1378（用户 2026-10-10 拍板）：**未绑 uid 的组织人员不再拦截** ——
        // 「审核人 不管有没有绑定 uid 都显示，内部做记录，以后绑定 uid 后也可关联查看相关数据」。
        // ⇒ 允许指定未绑账号者：落 `reviewer_person_id` + 姓名（uid 留 0）作为**内部记录**，
        //   待其绑定 uid 后按人员 ID 关联回查；是否由系统自动放行仍取决于 `auto_review`（本处不改变该语义）。
        $reviewer = (int) $reviewer_candidate['user_id'];

        // TASK-1347（安全复核 R1）：**分管领导服务端白名单** —— 提交值必须是空串或「协会领导」名录内的人名。
        // 「协会领导」名录为空（组织架构模块未加载 / 该部门无人员）时**不做白名单**，避免在降级场景误伤提交。
        $leader_names = array_map(function ($item) { return (string) $item['value']; }, zw_doc_leader_candidates());
        if ($leader_name !== '' && !empty($leader_names) && !in_array($leader_name, $leader_names, true)) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'zw_doc_msg' => '分管领导必须从「协会领导」名录中选择。')));
            exit;
        }

        // TASK-1347（第三部分）：**分管领导 ⇄ 审核人互斥** —— 分管领导不能同时担任审核人。
        // 前端在审核人下拉里把该协会领导**灰显禁用**（`zw_doc_leader_reviewer_sync_script()`），
        // 但前端只是体验 ⇒ 此处是**服务端权威校验**（按姓名比对；同名即视为同一人，宁可严不可松）。
        if ($leader_name !== ''
            && trim((string) $reviewer_candidate['name']) !== ''
            && trim((string) $reviewer_candidate['name']) === trim($leader_name)) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'zw_doc_msg' => '分管领导不能同时担任审核人，请改选其中一项。')));
            exit;
        }

        $cc_ids        = array();
        $cc_person_ids = array();
        $cc_names      = array();
        foreach ($cc_values as $cc_value) {
            $cc_candidate = zw_doc_flow_resolve_from($cc_pool, $cc_value);
            if (!$cc_candidate || (string) $cc_candidate['value'] === (string) $reviewer_candidate['value']) {
                continue;
            }
            if ((int) $cc_candidate['user_id'] > 0) {
                if ((int) $cc_candidate['user_id'] === $reviewer) {
                    continue; // 抄送人不得与审核人重复（沿用历史口径，按 uid 判重）。
                }
                $cc_ids[] = (int) $cc_candidate['user_id'];
            }
            if ((int) $cc_candidate['person_id'] > 0) {
                $cc_person_ids[] = (int) $cc_candidate['person_id'];
            }
            $cc_names[] = (string) $cc_candidate['name'];
        }
        $cc_ids        = array_values(array_unique($cc_ids));
        $cc_person_ids = array_values(array_unique($cc_person_ids));
        $cc_names      = array_values(array_unique(array_filter($cc_names, 'strlen')));

        $claim = null;
        if ($reserved > 0) {
            $number_row = zw_doc_get_number_by_id($reserved);
            if ($number_row && $number_row['status'] === 'reserved') {
                $claim = zw_doc_claim_reserved_number($reserved);
            } elseif ($number_row && $number_row['status'] === 'skipped') {
                $claim = zw_doc_claim_skipped_number($reserved);
            } else {
                $claim = new WP_Error('zw_doc_number_unavailable', '所选文号已不可用，请重新选择。');
            }
        } else {
            $claim = zw_doc_claim_auto_number($year, $type);
        }
        if (is_wp_error($claim)) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'zw_doc_msg' => $claim->get_error_message())));
            exit;
        }

        // TASK-1867（CHG-362）：份号取号 —— **先解析校验、再建单**：手填份号若已被占用，
        // 直接退回表单，避免产生「建了单却没有份号」的中间态（自动取号则取下一个可用号）。
        $copy_mode   = isset($_POST['zwc_copy_no_mode']) ? sanitize_key(wp_unslash($_POST['zwc_copy_no_mode'])) : '';
        $copy_posted = isset($_POST['zwc_copy_no']) ? sanitize_text_field(wp_unslash($_POST['zwc_copy_no'])) : '';
        // 「从空闲号中选择」下拉：无 JS 时也能生效（输入框为空则以所选号为准）。
        if ($copy_posted === '' && !empty($_POST['zwc_copy_no_pick'])) {
            $copy_posted = sanitize_text_field(wp_unslash($_POST['zwc_copy_no_pick']));
        }
        $copy_target = function_exists('zw_doc_copy_number_resolve_for_save')
            ? zw_doc_copy_number_resolve_for_save($copy_mode, $copy_posted, '', 0)
            : '';
        if (is_wp_error($copy_target)) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'zw_doc_msg' => $copy_target->get_error_message())));
            exit;
        }
        $copy_target = (string) $copy_target;

        $doc_id = zw_doc_create_document(array(
            'title'          => $title,
            'summary'        => $summary,
            'status'         => $status,
            'number'         => $claim['full_number'],
            'number_id'      => (int) $claim['id'],
            'reviewer_id'    => $reviewer,
            'reviewer_person_id' => (int) $reviewer_candidate['person_id'],
            'cc_ids'         => $cc_ids,
            'cc_person_ids'  => $cc_person_ids,
            'cc_names'       => $cc_names,
            'attachment_ids' => array(),
            'idempotency'    => $idem,
            'department'     => $department,
            'branch_id'      => $branch_id,
            'remark'         => $remark,
            // TASK-1347：「审核（历史记录，不关联系统人员）」已并入审核人 ⇒ 在线撰写不再写该文本 meta
            //（保持默认空串）；导入路径另行写死固定短语，见 doc-import.php。
            'leader_name'    => $leader_name,
            'issue_date'     => $issue_date,
        ));
        if (is_wp_error($doc_id) || !$doc_id) {
            $msg = is_wp_error($doc_id) ? $doc_id->get_error_message() : '发文创建失败。';
            if (!empty($claim['claim_token'])) {
                zw_doc_release_claim($claim['claim_token'], '创建发文失败');
            } else {
                zw_doc_void_reserved_number((int) $claim['id'], '创建发文失败');
            }
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'create', 'zw_doc_msg' => $msg)));
            exit;
        }

        if (!empty($claim['claim_token'])) {
            zw_doc_finalize_claim($claim['claim_token'], (int) $doc_id);
        } else {
            global $wpdb;
            $wpdb->update(
                zw_doc_table('numbers'),
                array('status' => 'used', 'document_id' => (int) $doc_id, 'claim_token' => '', 'updated_at' => current_time('mysql')),
                array('id' => (int) $claim['id']),
                array('%s', '%d', '%s', '%s'),
                array('%d')
            );
        }

        $upload = zw_doc_handle_attachments((int) $doc_id);

        // TASK-1291（缺陷 D-1）：`$extra` 必须先初始化并用 `.=` 合并 —— 原实现 :322 用未初始化的
        // `$extra .=`、:325 用 `$extra =` 整体覆写，导致「正文保存失败」提示（含任命要素校验失败）
        // 被附件提示静默吞掉，用户只看到"已提交审核"却不知道正文没落库。
        $extra = '';
        // 在线撰写：正文块与公文要素（TASK-772）。沿用同一 POST 契约，创建成功后立即落库。
        if (isset($_POST['zw_doc_block_type']) && function_exists('zw_doc_composer_body_from_post')) {
            $body  = zw_doc_composer_body_from_post(wp_unslash($_POST), array(
                'title'    => $title,
                'date'     => zw_doc_composer_cn_date($issue_date),
                'org_name' => '中国传统文化促进会',
                // TASK-1338 批次二 ⑦（D1 版记合并）：新建时版记印发机关 / 印发日期取公文要素
                // （发文部门 / 发文日期）；存量已显式保存的印发值不受影响。
                'department' => $department,
                'issue_date' => $issue_date,
                // TASK-1280（CHG-157）：署名口径 —— 分支机构带协会全称前缀（台账 `doc_department` 不变）。
                'sign_org' => function_exists('zw_doc_sign_org')
                    ? zw_doc_sign_org($department)
                    : $department,
            ));
            // TASK-1263：主送机关自由输入上限 20 个汉字（新建无存量值，可无条件施加）。
            if (!empty($body['meta']['receiver'])) {
                $body['meta']['receiver'] = zw_doc_composer_receiver_normalize(
                    $body['meta']['receiver'],
                    zw_doc_composer_receiver_limit()
                );
            }
            // TASK-1867（CHG-362）：占用份号并回填 `body.meta.copy_no`（自动取号的真实号以占号结果为准）。
            if ($copy_target !== '' && function_exists('zw_doc_copy_number_bind')) {
                $copy_bound = zw_doc_copy_number_bind((int) $doc_id, $copy_target);
                if (is_wp_error($copy_bound)) {
                    // 极罕见：并发窗口抢走刚校验过的号 ⇒ 换下一个可用号重试一次；
                    // 仍失败则留空并提示（不阻断建单，编辑页可补填）。
                    $copy_retry = (string) zw_doc_copy_number_next_free();
                    $copy_bound = ($copy_retry !== '')
                        ? zw_doc_copy_number_bind((int) $doc_id, $copy_retry)
                        : $copy_bound;
                    if (!is_wp_error($copy_bound)) {
                        $copy_target = $copy_retry;
                    } else {
                        $extra .= '；份号提示：' . $copy_bound->get_error_message() . '（本次未占用份号，可在编辑页补填）';
                        $copy_target = '';
                    }
                }
            }
            $body['meta']['copy_no'] = $copy_target;
            $saved = zw_doc_update_body((int) $doc_id, $body, get_current_user_id());
            if (is_wp_error($saved)) {
                $extra .= '；正文提示：' . $saved->get_error_message();
                // TASK-1867：正文未落库 ⇒ 释放刚占的份号，避免「台账占用但公文上没有份号」的脏占用。
                if ($copy_target !== '' && function_exists('zw_doc_copy_number_release_doc')) {
                    zw_doc_copy_number_release_doc((int) $doc_id, '正文保存失败');
                }
            }
        }
        $extra .= !empty($upload['errors']) ? '；附件提示：' . implode('；', array_map('sanitize_text_field', $upload['errors'])) : '';

        // ── TASK-1345（批次四 D3）·**内部规则**·全局开关：提交即自动通过审核步 ─────────────────
        // 位置约束（不可上移）：必须在本函数上方 `zw_doc_update_body()` **之后**。
        // 理由：`zw_doc_set_status()` 会广播 `zw_doc_flow_completed`，任命同步（doc-appoint.php）与
        // 任命书归档（doc-appoint-cert.php）据此读 `_zw_doc_body_json`；若在正文/任命要素落库前置为
        // approved，「任」字公文会被同步读到空要素。
        // 记录口径：`zw_doc_set_status()` 已落 `status_approved`；本处**补一条 `auto_approved`**，
        // 使审计能区分「系统自动通过」与「指定审核人人工通过」——**被记录的审核人并未实际实施审核**。
        // TASK-1410：本处是**唯一触发点之外的既有自动审核写入点**（触发钩子仍在 `zw_doc_set_status()`
        // 的 `zw_doc_flow_completed` 咽喉上，本处不新增触发）；按用户「每一步都被记录、可审计」的要求，
        // 事件备注统一为 canonical 格式（系统自动 + 开关 + 门禁结论），并补写轨迹 meta 的 `review` 步，
        // 使四步轨迹与四类自动事件在**正常提交路径**上同样齐全（含 `auto_review` 的开关依据）。
        $auto_approved = false;
        if ($status === 'pending' && !empty($flow['auto_review'])) {
            $auto_approved = (bool) zw_doc_set_status((int) $doc_id, 'approved', get_current_user_id(), '');
            if ($auto_approved) {
                zw_doc_log_event(
                    (int) $doc_id,
                    'auto_approved',
                    get_current_user_id(),
                    'pending',
                    'approved',
                    '系统自动（无人工参与）｜开关 auto_review｜门禁：状态 pending + 正文已落库 ⇒ 通过'
                );
                if (function_exists('zw_doc_auto_flow_trace_append')) {
                    zw_doc_auto_flow_trace_append((int) $doc_id, 'review', 'auto_review', 'done', '门禁通过：状态 pending + 正文已落库');
                }
            }
        }
        if ($status !== 'pending') {
            $flow_notice = '发文草稿已保存';
        } elseif ($auto_approved) {
            $flow_notice = '发文已提交并通过审核';
        } else {
            $flow_notice = '发文已提交审核';
        }

        wp_safe_redirect(zw_doc_admin_url(array(
            'view'       => 'detail',
            'doc_id'     => (int) $doc_id,
            'zw_doc_msg' => $flow_notice . $extra,
        )));
        exit;
    }
}

if (!function_exists('zw_doc_handle_review_document')) {
    /**
     * 处理审核。
     *
     * @return void
     */
    function zw_doc_handle_review_document() {
        $doc_id   = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
        $decision = isset($_POST['review_decision']) ? sanitize_key(wp_unslash($_POST['review_decision'])) : '';
        $note     = isset($_POST['review_note']) ? sanitize_textarea_field(wp_unslash($_POST['review_note'])) : '';
        $doc      = zw_doc_get_document($doc_id);

        if (!$doc || $doc['status'] !== 'pending' || (int) $doc['reviewer_id'] !== get_current_user_id()) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id, 'zw_doc_msg' => '当前用户无权审核或状态已变化。')));
            exit;
        }
        if ($decision === 'reject' && $note === '') {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id, 'zw_doc_msg' => '驳回时必须填写审核意见。')));
            exit;
        }
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        zw_doc_set_status($doc_id, $status, get_current_user_id(), $note);
        wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id, 'zw_doc_msg' => $decision === 'approve' ? '审核已通过。' : '已驳回。')));
        exit;
    }
}

if (!function_exists('zw_doc_handle_withdraw_document')) {
    /**
     * 发起人撤回。
     *
     * @return void
     */
    function zw_doc_handle_withdraw_document() {
        $doc_id = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
        $doc    = zw_doc_get_document($doc_id);
        $flow   = zw_doc_flow_settings();

        if (!$doc || (int) $doc['author_id'] !== get_current_user_id() || $doc['status'] !== 'pending' || empty($flow['allow_withdraw'])) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id, 'zw_doc_msg' => '当前不可撤回。')));
            exit;
        }
        zw_doc_set_status($doc_id, 'cancelled', get_current_user_id(), '发起人撤回');
        wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id, 'zw_doc_msg' => '已撤回。')));
        exit;
    }
}

if (!function_exists('zw_doc_handle_appoint_resync')) {
    /**
     * 「重新同步」任命要素（TASK-1291 / 方案 §6.4）。
     *
     * 复用同一 `zw_doc_appoint_sync()`（唯一同步入口），只放宽状态门槛，**不新建第二套写链路**。
     * 权限：`manage_options`（与发文模块管理口径一致）+ nonce + `admin_post`。
     * 公文审批 / 用印状态**不回滚**：同步失败只留痕 + 提示，可反复重试。
     *
     * @return void
     */
    function zw_doc_handle_appoint_resync() {
        if (!current_user_can('manage_options')) {
            wp_die('无权重新同步任命（需要管理员权限）。', '无权操作', array('response' => 403));
        }
        check_admin_referer('zw_doc_appoint_resync');

        $doc_id = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
        $result = function_exists('zw_doc_appoint_sync')
            ? zw_doc_appoint_sync($doc_id, get_current_user_id(), array('force' => true))
            : array('status' => 'failed', 'message' => '任命同步模块未加载。');

        $notice = isset($result['message']) && $result['message'] !== ''
            ? (string) $result['message']
            : '已触发重新同步。';
        if (!empty($result['warnings'])) {
            $notice .= '｜' . implode('；', array_map('sanitize_text_field', (array) $result['warnings']));
        }
        // TASK-1295（Ralph 轮 7）：重新同步**必须同时重试后置通道**
        // （带印章正式版归档 / webp 预热 / 任命书 267）。此前该按钮只重试 TASK-1291 的
        // 人员库同步，后置通道的 `_zw_doc_appoint_*_error` 失败没有任何人工重试入口；
        // 口径同为「只留痕、不回滚公文审批与用印状态」，幂等键不变（已颁发不重复推送）。
        $appointType = function_exists('zw_doc_appoint_doc_type') ? (string) zw_doc_appoint_doc_type($doc_id) : '';
        $isAppoint   = $appointType !== ''
            && function_exists('zw_doc_appoint_is_appoint_type')
            && zw_doc_appoint_is_appoint_type($appointType);
        if ($isAppoint && function_exists('zw_doc_appoint_cert_resync')) {
            $certRes = zw_doc_appoint_cert_resync($doc_id, (int) get_current_user_id());
            if (is_array($certRes) && !empty($certRes['message'])) {
                $notice .= '｜后置通道：' . sanitize_text_field((string) $certRes['message']);
            }
            if (is_array($certRes) && !empty($certRes['archive']['problems'])) {
                $notice .= '｜归档：' . implode('；', array_map('sanitize_text_field', (array) $certRes['archive']['problems']));
            }
            if (is_array($certRes) && !empty($certRes['cert']['problems'])) {
                $notice .= '｜任命书：' . implode('；', array_map('sanitize_text_field', (array) $certRes['cert']['problems']));
            }
        }
        wp_safe_redirect(zw_doc_admin_url(array(
            'view'       => 'detail',
            'doc_id'     => $doc_id,
            'zw_doc_msg' => $notice,
        )));
        exit;
    }
}

if (!function_exists('zw_doc_ledger_years')) {
    /**
     * 获取发文台账可筛选年份：优先取文号台账已有年份。
     *
     * @return int[]
     */
    function zw_doc_ledger_years() {
        global $wpdb;
        $years = array();
        if (is_object($wpdb) && function_exists('zw_doc_table')) {
            $table = zw_doc_table('numbers');
            $rows  = $wpdb->get_col("SELECT DISTINCT doc_year FROM {$table} ORDER BY doc_year DESC");
            $years = array_map('intval', (array) $rows);
        }
        $settings = function_exists('zw_doc_number_settings') ? zw_doc_number_settings() : array();
        if (!empty($settings['default_year'])) {
            $years[] = (int) $settings['default_year'];
        }
        $years = array_values(array_unique(array_filter($years)));
        rsort($years, SORT_NUMERIC);
        return !empty($years) ? $years : array((int) current_time('Y'));
    }
}

if (!function_exists('zw_doc_render_list_page')) {
    /**
     * 列表页。
     *
     * @return void
     */
    function zw_doc_render_list_page($forced_tab = '') {
        if ($forced_tab !== '') {
            $tab = sanitize_key((string) $forced_tab);
        } else {
            $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'mine';
        }
        $tab = in_array($tab, array('mine', 'review', 'cc', 'all', 'ledger'), true) ? $tab : 'mine';
        $flow = zw_doc_flow_settings();
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $ledger_year    = 0;
        $number_keyword = '';
        $args = array('page' => $paged, 'per_page' => 50);

        if ($tab === 'mine') {
            $args['author_id'] = get_current_user_id();
        } elseif ($tab === 'review') {
            $args['reviewer_id'] = get_current_user_id();
            $args['status']      = 'pending';
        } elseif ($tab === 'cc') {
            $args['cc_user_id'] = get_current_user_id();
            if ($flow['cc_visibility'] === 'approved') {
                $args['statuses'] = array('approved');
            } else {
                $args['statuses'] = array('pending', 'approved');
            }
        } elseif ($tab === 'ledger') {
            $ledger_year    = array_key_exists('num_year', $_GET) ? (int) $_GET['num_year'] : (int) current_time('Y');
            $number_keyword = isset($_GET['number_keyword']) ? sanitize_text_field(wp_unslash($_GET['number_keyword'])) : '';
            $args['statuses']       = array('approved');
            $args['number_year']    = $ledger_year;
            $args['number_keyword'] = $number_keyword;
            $args['per_page']       = 80;
        }

        $result = zw_doc_query_documents($args);
        $notice = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';

        $tabs = array(
            'mine'   => '我发起的',
            'review' => '待我审核',
            'cc'     => '抄送我的',
            'all'    => '全部',
        );
        $page_title  = $tab === 'ledger' ? '发文台账' : '我的发文';
        $page_intro  = $tab === 'ledger'
            ? '按年份、文号筛选已通过发文；列顺序按 2026 标准工作簿：发文类型、发文日期、文号、文件名称、部门、审核、分管领导。'
            : '文号自动调取、跳过、预留选择，单级审核，审核通过后抄送人可见。';
        ?>
        <div class="wrap zw-doc-list">
            <?php echo zw_doc_admin_ui_style(); // 唯一出处：SPEC-10 §1.9 卡片范式（TASK-778） ?>
            <style>
            .zw-doc-list .zw-doc-tabs{margin:14px 0 10px}
            .zw-doc-list .zw-doc-status{display:inline-block;padding:1px 7px;border-radius:999px;background:#f0f0f1;font-size:12px}
            .zw-doc-list .zw-doc-actions{margin:10px 0}
            .zw-doc-list .zw-doc-ledger-filter{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
            .zw-doc-list .zw-doc-ledger-filter label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:#666}
            </style>
            <h1><?php echo esc_html($page_title); ?></h1>
            <p class="zw-doc-muted"><?php echo esc_html($page_intro); ?></p>
            <?php if ($notice !== '') { zw_doc_admin_notice($notice, zw_doc_notice_level($notice)); } // TASK-1362：级别唯一出处 ?>
            <div class="zw-doc-actions">
                <a class="button button-primary" href="<?php echo esc_url(zw_doc_admin_url(array('view' => 'create'))); ?>">新建发文</a>
            </div>
            <?php if ($tab === 'ledger') : ?>
                <form method="get" class="zw-doc-ledger-filter">
                    <input type="hidden" name="page" value="zw-doc">
                    <input type="hidden" name="tab" value="ledger">
                    <label>年份
                        <select name="num_year">
                            <option value="0">全部年份</option>
                            <?php foreach (zw_doc_ledger_years() as $year_option) : ?>
                                <option value="<?php echo (int) $year_option; ?>"<?php selected($ledger_year, (int) $year_option); ?>><?php echo (int) $year_option; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>文号
                        <input type="text" name="number_keyword" value="<?php echo esc_attr($number_keyword); ?>" placeholder="如：中传函〔2026〕11号 或 11">
                    </label>
                    <button class="button button-primary">筛选</button>
                    <a class="button" href="<?php echo esc_url(zw_doc_ledger_admin_url()); ?>">重置</a>
                    <span class="zw-doc-muted">按文号年份与完整文号/序号筛选。</span>
                </form>
            <?php endif; ?>
            <?php if ($tab !== 'ledger') : ?>
                <div class="nav-tab-wrapper zw-doc-tabs">
                    <?php foreach ($tabs as $key => $label) : ?>
                        <a class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(zw_doc_admin_url(array('tab' => $key))); ?>"><?php echo esc_html($label); ?><?php if ($tab === $key) : ?> (<?php echo (int) $result['total']; ?>)<?php endif; ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'ledger') : ?>
                <div class="zw-adm-table-wrap">
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th style="width:100px">发文类型</th>
                        <th style="width:120px">发文日期</th>
                        <th style="width:190px">文号</th>
                        <th>文件名称</th>
                        <th style="width:180px">部门</th>
                        <th style="width:100px">审核</th>
                        <th style="width:100px">分管领导</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($result['items'])) : ?>
                        <tr><td colspan="7">暂无发文台账记录。</td></tr>
                    <?php else : ?>
                        <?php foreach ($result['items'] as $doc) : ?>
                            <?php $detail_url = zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc['id'])); ?>
                            <tr>
                                <td><?php echo esc_html(zw_doc_number_type_display($doc['remark'])); // TASK-1402：简称 → 全称唯一出处 ?></td>
                                <td><?php echo esc_html($doc['issue_date']); ?></td>
                                <td><a href="<?php echo esc_url($detail_url); ?>"><strong><?php echo esc_html($doc['number']); ?></strong></a></td>
                                <td><a href="<?php echo esc_url($detail_url); ?>"><?php echo esc_html($doc['title']); ?></a></td>
                                <td><?php echo esc_html($doc['department']); ?></td>
                                <td><?php echo esc_html(zw_doc_flow_person_label($doc)); ?></td>
                                <td><?php echo esc_html($doc['leader_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            <?php else : ?>
                <div class="zw-adm-table-wrap">
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th style="width:170px">文号</th>
                        <th>标题</th>
                        <th style="width:90px">状态</th>
                        <th style="width:120px">审核人</th>
                        <th style="width:120px">发起人</th>
                        <th style="width:150px">更新时间</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($result['items'])) : ?>
                        <tr><td colspan="6">暂无发文记录。</td></tr>
                    <?php else : ?>
                        <?php foreach ($result['items'] as $doc) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc['id']))); ?>"><strong><?php echo esc_html($doc['number']); ?></strong></a></td>
                                <td><?php echo esc_html($doc['title']); ?></td>
                                <td><span class="zw-doc-status"><?php echo esc_html(zw_doc_status_label($doc['status'])); ?></span></td>
                                <td><?php echo esc_html(zw_doc_flow_person_label($doc)); ?></td>
                                <td><?php echo esc_html(zw_doc_user_label($doc['author_id'])); ?></td>
                                <td><?php echo esc_html($doc['updated_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>

            <?php
            $total_pages = (int) ceil($result['total'] / max(1, (int) $args['per_page']));
            if ($total_pages > 1) {
                $base_args = array('tab' => $tab);
                if ($tab === 'ledger') {
                    $base_args = array('num_year' => $ledger_year, 'number_keyword' => $number_keyword);
                    $base_url  = zw_doc_ledger_admin_url($base_args);
                } else {
                    $base_url = zw_doc_admin_url($base_args);
                }
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links(array(
                    'base'      => add_query_arg('paged', '%#%', $base_url),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'type'      => 'list',
                    'prev_text' => '‹',
                    'next_text' => '›',
                ));
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }
}

if (!function_exists('zw_doc_render_create_page')) {
    /**
     * 起草页。
     *
     * @return void
     */
    function zw_doc_render_create_page() {
        $settings = zw_doc_number_settings();
        // 用户确认：新建发文不允许选年份，固定使用默认年份。
        $year     = (int) $settings['default_year'];
        $type     = isset($_GET['num_type']) ? sanitize_text_field(wp_unslash($_GET['num_type'])) : (string) $settings['doc_types'][0];
        $type     = zw_doc_validate_doc_type($type, $settings);
        if (!$type) {
            $type = (string) $settings['doc_types'][0];
        }

        $preview = zw_doc_get_next_preview($year, $type);
        $reusable = zw_doc_get_reusable_numbers($year, $type);
        // TASK-1345（D2/D4）：候选人来源 = 「公文流转配置」按组织架构部门取人；未配置时沿用管理员集合。
        $reviewer_candidates = zw_doc_flow_candidates('reviewer');
        $cc_candidates       = zw_doc_flow_candidates('cc');
        $current  = get_current_user_id();
        $notice   = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';
        ?>
        <div class="wrap zw-doc-create">
            <?php echo zw_doc_admin_ui_style(); // 唯一出处：SPEC-10 §1.9 卡片范式（TASK-778） ?>
            <style>
            .zw-doc-create .zw-doc-number-box{background:#fafbfc;border:1px dashed #dcdfe6;border-radius:8px;padding:12px 14px;margin-bottom:14px}
            </style>
            <h1>新建发文</h1>
            <?php if ($notice !== '') { zw_doc_admin_notice($notice, zw_doc_notice_level($notice)); } // TASK-1362：级别唯一出处 ?>
            <div class="zw-doc-card">
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('zw_doc_create_document'); ?>
                    <input type="hidden" name="zw_doc_action" value="create_document">
                    <input type="hidden" name="zw_doc_idempotency_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">

                    <div class="zw-doc-number-box">
                        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
                            <label style="margin:0">文种
                                <select name="num_type" id="zw-doc-create-type" onchange="zwDocReloadCreate()" style="min-width:100px">
                                    <?php foreach ($settings['doc_types'] as $type_option) : ?>
                                        <option value="<?php echo esc_attr($type_option); ?>"<?php selected($type, $type_option); ?>><?php echo esc_html($type_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <span><strong>文号预览：</strong>
                                <?php if (is_wp_error($preview)) : ?>
                                    <span style="color:#b32d2e"><?php echo esc_html($preview->get_error_message()); ?></span>
                                <?php else : ?>
                                    <span id="zw-doc-preview-number" data-auto="<?php echo esc_attr($preview['full_number']); ?>" style="font-size:16px"><?php echo esc_html($preview['full_number']); ?></span>
                                    <input type="hidden" name="skip_seq" value="<?php echo (int) $preview['seq']; ?>">
                                    <button class="button button-small" type="submit" name="zw_doc_skip_button" value="1" formnovalidate onclick="return confirm('确认跳过当前文号？');">跳过当前号</button>
                                <?php endif; ?>
                            </span>
                        </div>
                        <input type="hidden" name="num_year" value="<?php echo (int) $year; ?>">
                    </div>

                    <div class="zw-doc-field">
                        <label>选择预留 / 已跳过文号</label>
                        <select id="zw-doc-existing-number" name="reserved_number_id" onchange="zwDocSyncSelectedNumber()">
                            <option value="0" data-number="">不选择，按系统自动取号</option>
                            <?php foreach ($reusable as $row) : ?>
                                <?php
                                $is_skipped = ($row['status'] === 'skipped');
                                $label      = $is_skipped ? '【已跳过，可选用】' : '【预留】';
                                $extra      = (!$is_skipped && $row['reserved_note'] !== '') ? '（' . $row['reserved_note'] . '）' : '';
                                ?>
                                <option value="<?php echo (int) $row['id']; ?>" data-number="<?php echo esc_attr($row['full_number']); ?>"><?php echo esc_html($label . $row['full_number'] . $extra); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="zw-doc-muted">已跳过的文号也可在这里重新选择；保存/提交时以所选文号为准。自动取号会跳过预留和已跳过记录。</div>
                    </div>

                    <div class="zw-doc-field">
                        <label>公文标题 *</label>
                        <input type="text" name="doc_title" maxlength="200" required>
                    </div>
                    <div class="zw-doc-field">
                        <label>公文摘要</label>
                        <textarea name="doc_summary" rows="4" maxlength="500"></textarea>
                    </div>
                    <div class="zw-doc-field">
                        <label>发文部门（台账 + 版记）</label>
                        <?php // TASK-1259：SPEC-85 canonical 搜索下拉；候选 = 协会本级 + 正常状态分支机构，默认协会本级 ?>
                        <?php zw_control_search_select(array(
                            'id'          => 'zw-doc-department',
                            'name'        => 'doc_department',
                            'value'       => zw_doc_department_head_name(),
                            'placeholder' => '输入关键词搜索分支机构',
                            'empty_text'  => '没有匹配的分支机构',
                            'options'     => zw_doc_department_select_options(),
                        )); ?>
                        <div class="zw-doc-muted">默认「中国传统文化促进会」；候选只含<strong>正常状态</strong>的分支机构；保存时服务端按同一名录二次校验。历史导入仍按关键字关联现有分支机构。</div>
                    </div>
                    <div class="zw-doc-field">
                        <label>发文日期（流转记录用 + 版记）</label>
                        <input type="date" name="doc_issue_date">
                    </div>
                    <div class="zw-doc-field">
                        <label>备注（历史发文类型）</label>
                        <textarea name="doc_remark" rows="2" maxlength="255" placeholder="如：通知、任命、决定；历史导入时写入发文类型"></textarea>
                        <?php // TASK-1402：台账「发文类型」按简称 → 全称映射展示（唯一出处 zw_doc_number_type_labels()）。 ?>
                        <div class="zw-doc-muted">台账「发文类型」列按映射展示全称：<?php
                            $zw_remark_map = array();
                            foreach (zw_doc_number_type_labels() as $zw_remark_short => $zw_remark_full) {
                                $zw_remark_map[] = $zw_remark_short . ' → ' . $zw_remark_full;
                            }
                            echo esc_html(implode('；', $zw_remark_map));
                        ?>；未登记的写法按原样显示。</div>
                    </div>
                    <?php
                    // TASK-1347（同在线撰写第 4 步口径）：① 不再并列「审核（历史记录，不关联系统人员）」文本字段
                    //（并入审核人）；② 分管领导改**下拉**（组织架构「协会领导」）并**自动随机**填入一人；
                    // ③ 该协会领导在审核人中**灰色不可选**（互斥，前端 sync + 服务端校验）。
                    $leader_candidates = zw_doc_leader_candidates();
                    $leader_values     = array_map(function ($item) { return (string) $item['value']; }, $leader_candidates);
                    // 安全复核 R4c：`$current` 守卫接线（本页仅新建 ⇒ 恒为空串；变量保留以便将来回填）。
                    $leader_current    = '';
                    $leader_value      = zw_doc_leader_default_name($leader_current);
                    ?>
                    <div class="zw-doc-field">
                        <label>分管领导</label>
                        <select name="doc_leader_name" id="zw-doc-leader">
                            <option value="">请选择</option>
                            <?php if ($leader_value !== '' && !in_array($leader_value, $leader_values, true)) : ?>
                            <option value="<?php echo esc_attr($leader_value); ?>" selected><?php echo esc_html($leader_value); ?>（原值）</option>
                            <?php endif; ?>
                            <?php foreach ($leader_candidates as $leader_option) : ?>
                            <option value="<?php echo esc_attr($leader_option['value']); ?>"<?php selected($leader_value, $leader_option['value']); ?>><?php echo esc_html($leader_option['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="zw-doc-field">
                        <label>公文附件</label>
                        <input type="file" name="zw_doc_attachments[]" multiple>
                        <div class="zw-doc-muted">类型：doc/docx/pdf/xls/xlsx/zip/jpg/png；单文件 ≤20MB，最多 10 个。</div>
                    </div>
                    <div class="zw-doc-field">
                        <label>审核人 *</label>
                        <select name="doc_reviewer_id" id="zw-doc-reviewer" required>
                            <option value="">请选择</option>
                            <?php foreach ($reviewer_candidates as $candidate) : ?>
                                <?php if ((int) $candidate['user_id'] > 0 && (int) $candidate['user_id'] === $current) { continue; } ?>
                                <?php $candidate_is_leader = ($leader_value !== '' && trim((string) $candidate['name']) === trim($leader_value)); ?>
                                <option value="<?php echo esc_attr($candidate['value']); ?>"
                                        data-name="<?php echo esc_attr($candidate['name']); ?>"<?php echo $candidate_is_leader ? ' disabled style="color:#a7aaad"' : ''; ?>><?php echo esc_html($candidate['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php $reviewer_pool_note = zw_doc_reviewer_pool_note(); ?>
                        <?php if ($reviewer_pool_note !== '') : ?>
                        <div class="zw-doc-muted"><?php echo esc_html($reviewer_pool_note); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="zw-doc-field">
                        <label>抄送人</label>
                        <select name="doc_cc_ids[]" multiple size="6" style="min-height:120px">
                            <?php foreach ($cc_candidates as $candidate) : ?>
                                <option value="<?php echo esc_attr($candidate['value']); ?>"><?php echo esc_html($candidate['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="zw-doc-muted">可多选；抄送人不能与审核人重复（重复项会被自动忽略）。</div>
                    </div>
                    <?php zw_doc_leader_reviewer_sync_script('zw-doc-leader', 'zw-doc-reviewer'); ?>

                    <p>
                        <button class="button" type="submit" name="zw_doc_create_mode" value="draft">保存草稿并占用文号</button>
                        <button class="button button-primary" type="submit" name="zw_doc_create_mode" value="submit">提交审核</button>
                        <a class="button" href="<?php echo esc_url(zw_doc_admin_url()); ?>">返回列表</a>
                    </p>
                </form>
            </div>
            <script>
            function zwDocReloadCreate() {
                var typeEl = document.getElementById('zw-doc-create-type');
                if (!typeEl) { return; }
                var url = new URL(window.location.href);
                url.searchParams.set('page', 'zw-doc');
                url.searchParams.set('view', 'create');
                url.searchParams.set('num_year', '<?php echo esc_js((string) $year); ?>');
                url.searchParams.set('num_type', typeEl.value);
                window.location.href = url.toString();
            }
            function zwDocSyncSelectedNumber() {
                var sel = document.getElementById('zw-doc-existing-number');
                var el  = document.getElementById('zw-doc-preview-number');
                if (!sel || !el) { return; }
                var opt = sel.options[sel.selectedIndex];
                var num = opt ? (opt.getAttribute('data-number') || '') : '';
                el.textContent = num !== '' ? num : (el.getAttribute('data-auto') || '');
            }
            document.addEventListener('DOMContentLoaded', zwDocSyncSelectedNumber);
            </script>
        </div>
        <?php
    }
}

if (!function_exists('zw_doc_render_detail_page')) {
    /**
     * 详情页。
     *
     * @return void
     */
    function zw_doc_render_detail_page() {
        $doc_id = isset($_GET['doc_id']) ? (int) $_GET['doc_id'] : 0;
        $doc    = zw_doc_get_document($doc_id);
        $notice = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';
        if (!$doc) {
            echo '<div class="wrap"><h1>发文详情</h1><div class="notice notice-error"><p>发文不存在。</p></div></div>';
            return;
        }
        $flow   = zw_doc_flow_settings();
        $events = zw_doc_get_events($doc_id);
        ?>
        <div class="wrap zw-doc-detail">
            <?php echo zw_doc_admin_ui_style(); // 唯一出处：SPEC-10 §1.9 卡片范式（TASK-778） ?>
            <style>
            .zw-doc-detail .zw-doc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
            .zw-doc-detail .zw-doc-status{display:inline-block;padding:2px 8px;border-radius:999px;background:#f0f0f1}
            </style>
            <?php
            // TASK-2008：文件默认打开动作 canonical 资源（doc-viewer 弹层/图片灯箱 + file-icon + 本控件样式）。
            if (function_exists('zw_control_file_open_assets')) {
                zw_control_file_open_assets();
            }
            ?>
            <h1>发文详情：<?php echo esc_html($doc['title']); ?></h1>
            <?php if ($notice !== '') { zw_doc_admin_notice($notice, zw_doc_notice_level($notice)); } // TASK-1362：级别唯一出处 ?>
            <?php
            // TASK-1379（用户口径「发文详情 需要重新排版 可以看到 已完成的文件」）：详情页**首屏概览** ——
            // 把「这篇发文现在处于什么状态、**完成文件**有没有生成好」提到第一屏，并提供到完成文件区块的跳转。
            // 只读既有数据（`zw_doc_status_label()` / `zw_doc_pdf_status()`），不新增数据来源、不改变任何判定。
            $ov_has_body  = !empty($doc['body']);
            $ov_is_sealed = ((string) $doc['seal_mode'] === 'formal');
            $ov_pdf       = ($ov_has_body && function_exists('zw_doc_pdf_status') && (string) $doc['seal_mode'] === 'formal')
                ? zw_doc_pdf_status($doc['id'])
                : array('status' => 'none', 'pages' => 0, 'bytes' => 0, 'sha256' => '');
            $ov_pdf_ready = ($ov_pdf['status'] === 'ready' && !empty($ov_pdf['file']));
            // TASK-1412（用户报障「点击 完成文件 没有任何反应」）：死锚点根治 + 未就绪引导。
            // 判定**只读既有 meta / 既有函数**，且判据与各卡片自身的渲染条件**逐字对齐** ——
            // 保证「有目标才渲染链接」，杜绝 `href="#x"` 指向不存在的 `id="x"`。
            $ov_has_files_card = ($ov_has_body && function_exists('zw_doc_pdf_status') && $ov_is_sealed);
            $ov_has_legal_card = ($ov_has_body && function_exists('zw_doc_legal_status'));
            $ov_has_seal_card  = ($ov_has_body && function_exists('zw_doc_seal_gate'));
            // 法务是否已通过：优先既有 canonical 判据 `zw_doc_legal_is_pass()`（用印门禁 G2 的唯一判据），
            // 无该函数时回退到同一 meta（与用印门禁 `doc-admin-list.php` 内 `legal_status` 取值逐字一致）。
            $ov_legal_pass     = function_exists('zw_doc_legal_is_pass')
                ? zw_doc_legal_is_pass($doc['id'])
                : ((string) get_post_meta($doc['id'], '_zw_doc_legal_status', true) === 'pass');
            // 未就绪时的**状态说明**：按当前缺哪一环给出人话（仅文案，不改变任何判定）。
            if (!$ov_has_body) {
                $ov_pdf_hint = '尚未生成（需先撰写正文 → 法务通过 → 用印）';
            } elseif (!$ov_has_files_card) {
                $ov_pdf_hint = $ov_legal_pass ? '尚未生成（需先正式用印）' : '尚未生成（需先法务通过 → 用印）';
            } else {
                $ov_pdf_hint = '尚未生成（已用印，需在「完成文件」点「生成正式用印件」）';
            }
            ?>
            <div class="zw-doc-card">
                <h2>概览</h2>
                <div class="zw-doc-grid">
                    <div>
                        <strong>发文状态</strong><br>
                        <span class="zw-doc-status"><?php echo esc_html(zw_doc_status_label($doc['status'])); ?></span>
                        <span class="zw-doc-muted">文号 <?php echo esc_html($doc['number']); ?></span>
                    </div>
                    <div>
                        <strong>完成文件</strong><br>
                        <?php if ($ov_pdf_ready && $ov_has_files_card) : ?>
                            <span class="zw-doc-status">已生成</span>
                            <span class="zw-doc-muted"><?php echo (int) $ov_pdf['pages']; ?> 页 / <?php echo esc_html(size_format($ov_pdf['bytes'])); ?> / SHA-256 <?php echo esc_html(substr((string) $ov_pdf['sha256'], 0, 12)); ?>…</span>
                            <br><a class="button button-small" href="#zw-doc-files">查看完成文件（加密 PDF）</a>
                        <?php elseif (function_exists('zw_doc_pdf_status_label')) : ?>
                            <span class="zw-doc-muted"><?php echo esc_html(zw_doc_pdf_status_label($ov_pdf['status'])); ?>：<?php echo esc_html($ov_pdf_hint); ?></span>
                            <?php
                            // TASK-1412：未就绪时给出**下一步动作链接**，且只在目标卡片确实渲染时输出（无死链）。
                            if (!$ov_has_body) :
                                // 无正文时本页没有法务 / 用印 / 完成文件卡，无可指目标 ⇒ 只给状态说明，不渲染死链。
                                ?>
                            <?php elseif (!$ov_has_files_card) : ?>
                                <?php if ($ov_has_legal_card && !$ov_legal_pass) : ?>
                                    <br><a class="button button-small" href="#zw-doc-legal">去「法务审核」送审</a>
                                    <?php // 次级跳转：用印卡会如实列出「尚未取得法务审批结论」等阻断项，属说明而非绕过。 ?>
                                    <?php if ($ov_has_seal_card) : ?><a class="button button-small" href="#zw-doc-seal">去「用印」</a><?php endif; ?>
                                <?php elseif ($ov_has_seal_card) : ?>
                                    <br><a class="button button-small" href="#zw-doc-seal">去「用印」正式用印</a>
                                <?php endif; ?>
                            <?php else : ?>
                                <br><a class="button button-small" href="#zw-doc-files">去「完成文件」生成正式用印件</a>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="zw-doc-muted">—</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong>流转</strong><br>
                        <span class="zw-doc-muted">发起人 <?php echo esc_html(zw_doc_user_label((int) $doc['author_id'])); ?> ｜ 审核人 <?php echo esc_html(zw_doc_flow_person_label($doc)); ?></span>
                    </div>
                </div>
                <?php
                // TASK-1382 ③（用户口径「发文详情 需要重新排版 可以看到 已完成的文件」）：
                // 首屏给出到「纸面要素 / 流转 / 法务审核 / 用印 / 完成文件」的**锚点跳转**。
                // TASK-1412：**逐项按目标存在性渲染** —— 目标卡片不存在时该链接**一律不渲染**，
                // 避免出现「点了没反应」的死锚点（用户报障根因）。
                $ov_jump = array('<a href="#zw-doc-face">纸面要素</a>', '<a href="#zw-doc-flow">流转</a>');
                if ($ov_has_legal_card) {
                    $ov_jump[] = '<a href="#zw-doc-legal">法务审核</a>';
                }
                if ($ov_has_seal_card) {
                    $ov_jump[] = '<a href="#zw-doc-seal">用印</a>';
                }
                if ($ov_has_files_card) {
                    $ov_jump[] = '<a href="#zw-doc-files">完成文件</a>';
                }
                if (!empty($doc['linked_files'])) {
                    // TASK-1644 阶段四：关联网盘文件存在时才给锚点（逐项按目标存在性渲染，无死链）。
                    $ov_jump[] = '<a href="#zw-doc-netdisk">归档文件</a>';
                }
                ?>
                <p class="zw-doc-muted" style="margin:10px 0 0">
                    快速跳转：<?php echo implode(' ｜ ', $ov_jump); // phpcs:ignore WordPress.Security.EscapeOutput -- 静态锚点，逐项按目标存在性渲染 ?>
                </p>
            </div>
            <div class="zw-doc-card" id="zw-doc-face">
                <h2>纸面要素</h2>
                <div class="zw-doc-grid">
                    <div><strong>文号</strong><br><?php echo esc_html($doc['number']); ?></div>
                    <div><strong>状态</strong><br><span class="zw-doc-status"><?php echo esc_html(zw_doc_status_label($doc['status'])); ?></span></div>
                    <div><strong>发文日期</strong><br><?php echo $doc['issue_date'] !== '' ? esc_html($doc['issue_date']) : '—'; ?></div>
                    <div><strong>发文部门</strong><br><?php echo $doc['department'] !== '' ? esc_html($doc['department']) : '—'; ?><?php if ((int) $doc['branch_id'] > 0) : ?><span class="zw-doc-muted">（已关联分支机构 #<?php echo (int) $doc['branch_id']; ?>）</span><?php endif; ?></div>
                </div>
                <hr>
                <p><strong>摘要</strong><br><?php echo nl2br(esc_html($doc['summary'])); ?></p>
                <p><strong>备注（历史发文类型）</strong><br><?php echo nl2br(esc_html(zw_doc_number_type_display($doc['remark']))); // TASK-1402：简称 → 全称唯一出处 ?></p>
                <?php
                // TASK-1347（用户拍板）：「审核（历史记录，不关联系统人员）」**并入审核人** ⇒
                // 本页不再并列第二个「审核」展示行；统一由上格「审核人」经 `zw_doc_flow_person_label()` 呈现
                //（在线撰写 = 系统人员显示名；导入 = 固定短语）。
                ?>
                <p><strong>附件</strong><br>
                    <?php if (empty($doc['attachment_ids'])) : ?>
                        —
                    <?php else : ?>
                        <?php foreach ($doc['attachment_ids'] as $att_id) : ?>
                            <?php
                            // TASK-2008：附件行改为「文件名 / 图标 = 默认打开（在线浏览）」，
                            // 下载只由显式「下载」按钮触发；预览实现仍在 canonical `doc-viewer`，
                            // 分发与标记统一在 `inc/controls/file-open.php`。
                            $att_name   = zw_doc_attachment_name($att_id);
                            $att_open   = array(
                                'name'         => $att_name,
                                'source'       => 'doc_attachment',
                                'id'           => (int) $att_id,
                                'doc_id'       => (int) $doc['id'],
                                'size'         => (int) @filesize((string) get_attached_file($att_id)),
                                'download_url' => zw_doc_download_url($doc['id'], $att_id),
                            );
                            $att_link   = current_user_can('manage_options') && function_exists('zw_control_file_open_link')
                                ? zw_control_file_open_link($att_open)
                                : esc_html($att_name);
                            $att_dl     = current_user_can('manage_options') && function_exists('zw_control_file_download_button')
                                ? zw_control_file_download_button(array(
                                    'url'   => zw_doc_download_url($doc['id'], $att_id),
                                    'name'  => $att_name,
                                    'label' => '下载',
                                    'icon'  => false,
                                ))
                                : '';
                            ?>
                            <span style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap">
                                <?php echo $att_link; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?>
                                <?php echo $att_dl; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?>
                            </span><br>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </p>
                <?php // TASK-1644 阶段四：关联网盘文件（归档通道）——与上方媒体库「附件」两套通道并列展示。 ?>
                <p id="zw-doc-netdisk"><strong>归档文件（网盘）</strong><br>
                    <?php if (empty($doc['linked_files'])) : ?>
                        <span class="zw-doc-muted">暂无。分支机构发文归档至「网盘文件 · 分支机构 · 发文管理」，总会发文归档至「网盘文件 · 内部办公 · 办公文档」。</span>
                    <?php else : ?>
                        <?php foreach ($doc['linked_files'] as $lf) : ?>
                            <?php
                            $lf_url = function_exists('zw_org_files_download_url') ? zw_org_files_download_url((int) $lf['id']) : '';
                            $lf_space_label = ((string) $lf['scope'] === 'branch') ? '分支机构 · 发文管理' : '内部办公 · 办公文档';
                            // TASK-2008：归档文件同样「文件名 / 图标 = 默认打开（在线浏览）」；
                            // 下载走显式按钮（canonical `zw_control_file_download_button()`）。
                            $lf_open = array(
                                'name'         => (string) $lf['name'],
                                'source'       => 'netdisk',
                                'id'           => (int) $lf['id'],
                                'size'         => (int) $lf['size'],
                                'download_url' => $lf_url,
                            );
                            $lf_link = current_user_can('manage_options') && function_exists('zw_control_file_open_link')
                                ? zw_control_file_open_link($lf_open)
                                : esc_html((string) $lf['name']);
                            $lf_dl   = current_user_can('manage_options') && function_exists('zw_control_file_download_button')
                                ? zw_control_file_download_button(array(
                                    'url'   => $lf_url,
                                    'name'  => (string) $lf['name'],
                                    'label' => '下载',
                                    'icon'  => false,
                                ))
                                : '';
                            ?>
                            <span style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap">
                            <?php echo $lf_link; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?>
                            <?php echo $lf_dl; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?>
                            <span class="zw-doc-muted">（<?php echo esc_html($lf_space_label); ?> / <?php echo esc_html(size_format((int) $lf['size'])); ?> / <?php echo esc_html($lf['uploaded_at']); ?>）</span>
                            </span><br>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </p>
                <?php if ($doc['review_note'] !== '') : ?>
                    <p><strong>审核意见</strong><br><?php echo nl2br(esc_html($doc['review_note'])); ?></p>
                <?php endif; ?>
            </div>

            <?php
            // TASK-1382 ③ 信息架构重排：**流转** 独立成卡（原在「纸面要素」格内）。
            // 发起人 / 审核人一律显示**姓名**：`zw_doc_user_label()` 姓名优先于账号昵称（TASK-1379 已实现）。
            ?>
            <div class="zw-doc-card" id="zw-doc-flow">
                <h2>流转</h2>
                <div class="zw-doc-grid">
                    <div><strong>发起人</strong><br><?php echo esc_html(zw_doc_user_label((int) $doc['author_id'])); ?></div>
                    <div><strong>审核人</strong><br><?php echo esc_html(zw_doc_flow_person_label($doc)); ?></div>
                    <div><strong>分管领导</strong><br><?php echo $doc['leader_name'] !== '' ? esc_html($doc['leader_name']) : '—'; ?></div>
                    <div><strong>抄送人</strong><br>
                        <?php
                        // TASK-1345（D4）：抄送人含**未绑 uid** 的组织人员，展示以落库时记录的
                        // `_zw_doc_cc_names` 为准（`zw_doc_flow_cc_labels()` 唯一出处）。
                        $cc_labels = zw_doc_flow_cc_labels($doc);
                        echo empty($cc_labels) ? '—' : esc_html(implode('、', $cc_labels));
                        ?>
                    </div>
                </div>
                <p class="zw-doc-muted">流转明细（含时间 / 操作人 / 事件）见页尾「流转记录」。</p>
            </div>

            <?php
            // 正式用印件（TASK-774，TASK-1382 ③ 前移至「流转」之后）：仅对「已用印」的发文展示。
            if (!empty($doc['body']) && function_exists('zw_doc_pdf_status') && (string) $doc['seal_mode'] === 'formal') :
                $pdf       = zw_doc_pdf_status($doc['id']);
                $pdf_ready = ($pdf['status'] === 'ready' && $pdf['file'] !== '');
                $pdf_tools = zw_doc_pdf_tools_ready();
                $pdf_blockers = ($pdf['status'] === 'queued' || $pdf['status'] === 'rendering') ? array() : zw_doc_pdf_gate($doc, array(
                    'can_seal'         => function_exists('zw_doc_seal_can_use') ? zw_doc_seal_can_use() : false,
                    'owner_configured' => zw_doc_pdf_owner_pass_configured(),
                    'status'           => $pdf['status'],
                ));
                ?>
                <div class="zw-doc-card">
                    <?php // TASK-1839：标题前挂 canonical 文件图标（图标是**兄弟节点**，`<h2>` 原文逐字不变，避免改动既有断言契约）。 ?>
                    <div style="display:flex;align-items:center;gap:8px">
                        <?php
                        echo function_exists('zw_control_file_icon')
                            ? zw_control_file_icon(array('name' => '完成文件.pdf', 'size' => 'sm')) // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义
                            : '';
                        ?>
                        <h2 id="zw-doc-files">完成文件（正式用印件（加密 PDF））</h2>
                    </div>
                    <p>
                        <strong>状态：</strong><?php echo esc_html(zw_doc_pdf_status_label($pdf['status'])); ?>
                        <?php if ($pdf['status'] === 'ready') : ?>
                            <span class="zw-doc-muted">
                                （<?php echo (int) $pdf['pages']; ?> 页 / <?php echo esc_html(size_format($pdf['bytes'])); ?> / <?php echo (int) $pdf['dpi']; ?>dpi / SHA-256 <?php echo esc_html(substr($pdf['sha256'], 0, 12)); ?>…）
                            </span>
                        <?php endif; ?>
                    </p>
                    <?php if (function_exists('zw_doc_seal_edit_unlocked') && zw_doc_seal_edit_unlocked($doc['id'])) : ?>
                        <?php // TASK-1382 ④：解锁编辑态下，本卡产物仍是「已用印版本」，不得被误当最终稿。 ?>
                        <p class="zw-doc-muted">⚠️ 该发文已<strong>同版本解锁编辑</strong>：下方为<strong>已用印版本</strong>的产物；若正文 / 要素已修改，请以<strong>重新用印后</strong>生成的新产物为准。</p>
                    <?php endif; ?>
                    <?php if ($pdf['error'] !== '') : ?>
                        <p class="zw-doc-muted">失败原因：<?php echo esc_html($pdf['error']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($pdf_tools['missing'])) : ?>
                        <p class="zw-doc-muted">⚠️ 生成依赖未就绪：<?php echo esc_html(implode('、', $pdf_tools['missing'])); ?>（需先执行 <code>scripts/install-doc-render-worker.sh</code>）</p>
                    <?php endif; ?>
                    <?php if (!zw_doc_pdf_owner_pass_configured()) : ?>
                        <p class="zw-doc-muted">⚠️ 未在 <code>wp-config.php</code> 配置编辑口令常量 <code>ZW_DOC_PDF_OWNER_PASS</code>；未配置时拒绝生成（安全默认）。</p>
                    <?php endif; ?>
                    <?php if (!empty($pdf_blockers)) : ?>
                        <ul style="margin:0 0 10px 20px;list-style:disc">
                            <?php foreach ($pdf_blockers as $blocker) : ?>
                                <li><?php echo esc_html($blocker['message']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($pdf_ready) : ?>
                        <?php
                        // TASK-1379（用户口径「可以在线浏览 流程完成后自动盖章后的编辑加密的 pdf文件 … 内嵌下载」）：
                        // 在**同一权限边界**内提供**内嵌浏览**：复用同一鉴权端点（nonce + `zw_doc_can_view_doc()`），
                        // 仅把响应头改为 `inline`（见 `zw_doc_pdf_download_url($id, true)`）；
                        // **不做服务端解密** —— 文件仍是 AES-256 加密件，浏览器打开时会提示编辑口令。
                        // 折叠呈现（`<details>`）：默认不加载内嵌对象，避免详情页每次打开都拉取数十 MB 的 PDF。
                        $pdf_inline_url = zw_doc_pdf_download_url($doc['id'], true);
                        ?>
                        <p>
                            <a class="button button-primary" href="<?php echo esc_url(zw_doc_pdf_download_url($doc['id'])); ?>">下载加密 PDF（正式用印件）</a>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field('zw_doc_pdf_queue_' . (int) $doc['id']); ?>
                                <input type="hidden" name="zw_doc_action" value="pdf_queue">
                                <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                <button class="button" type="submit">重新生成</button>
                            </form>
                        </p>
                        <?php
                        // TASK-1386 ④（用户拍板原文「旧产物留档 做版本管理 只有超级管理员看到」）：
                        // 版本列表**仅超级管理员可见**（超管口径 = `zw_doc_is_super_admin()`，即项目既有 `manage_options`）。
                        // 旧版本下载 / 内嵌浏览走**同一鉴权端点**（`zw_doc_pdf_download_url($id, $inline, $version)`），
                        // 端点内再次判定超管（服务端权威，不靠前端隐藏）。普通有权者只见当前版本。
                        $pdf_versions = function_exists('zw_doc_pdf_versions') ? zw_doc_pdf_versions($doc['id']) : array();
                        if (!empty($pdf_versions) && function_exists('zw_doc_is_super_admin') && zw_doc_is_super_admin()) :
                            $pdf_vcount = count($pdf_versions);
                            ?>
                            <div style="margin:12px 0 0;padding:10px 12px;border:1px solid #e5e7eb;border-radius:6px;background:#fafafa">
                                <strong>历史版本（旧产物留档 · 仅超级管理员可见）</strong>
                                <p class="zw-doc-muted" style="margin:4px 0 8px">
                                    重新生成 / 再次用印<strong>不覆盖</strong>旧加密 PDF：每次产物都是新文件并登记为一个版本（只增不减）；
                                    普通有权者只见<strong>当前版本</strong>，历史版本的下载 / 内嵌浏览只放行<strong>超级管理员</strong>。
                                </p>
                                <div class="zw-adm-table-wrap">
                                <table class="widefat striped" style="max-width:100%">
                                    <thead>
                                        <tr>
                                            <th>版本</th>
                                            <th>生成时间</th>
                                            <th>页数</th>
                                            <th>dpi</th>
                                            <th>SHA-256</th>
                                            <th>操作者</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_reverse($pdf_versions) as $pdf_ver) : ?>
                                            <?php $pdf_vno = (int) $pdf_ver['version']; ?>
                                            <tr>
                                                <td>
                                                    v<?php echo (int) $pdf_vno; ?>
                                                    <?php if ($pdf_vno === $pdf_vcount) : ?><span class="zw-doc-muted">（最新）</span><?php endif; ?>
                                                </td>
                                                <td><?php echo esc_html((string) $pdf_ver['generated_at']); ?></td>
                                                <td><?php echo (int) $pdf_ver['pages']; ?></td>
                                                <td><?php echo (int) $pdf_ver['dpi']; ?></td>
                                                <td><code><?php echo esc_html(substr((string) $pdf_ver['sha256'], 0, 12)); ?>…</code></td>
                                                <td>#<?php echo (int) $pdf_ver['actor']; ?></td>
                                                <td>
                                                    <a class="button button-small" href="<?php echo esc_url(zw_doc_pdf_download_url($doc['id'], false, $pdf_vno)); ?>">下载</a>
                                                    <?php
                                                    // TASK-1838：历史版本的「内嵌浏览」收敛到 canonical 控件（同一鉴权端点，仅内联呈现）。
                                                    $pdf_v_viewer = function_exists('zw_control_doc_viewer_trigger') ? zw_control_doc_viewer_trigger(array(
                                                        'name'         => '正式用印件 v' . $pdf_vno,
                                                        'kind'         => 'pdf',
                                                        'url'          => zw_doc_pdf_download_url($doc['id'], true, $pdf_vno),
                                                        'download_url' => zw_doc_pdf_download_url($doc['id'], false, $pdf_vno),
                                                        'size'         => (int) $pdf_ver['bytes'],
                                                        'label'        => '内嵌浏览',
                                                    )) : '';
                                                    if ($pdf_v_viewer !== '') {
                                                        echo $pdf_v_viewer; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义
                                                    } else {
                                                        echo '<a class="button button-small" href="' . esc_url(zw_doc_pdf_download_url($doc['id'], true, $pdf_vno)) . '" target="_blank" rel="noopener">内嵌浏览</a>';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php
                        // TASK-1838：在线浏览收敛到 SPEC-85 canonical「文档在线浏览」控件
                        //（原 `details + <object>` 一次性内联实现废止；同一鉴权端点 `inline=1` 提供内联流，
                        // 弹层交互/无障碍/移动端全屏由控件统一提供，不再各页自搓）。
                        $pdf_viewer = function_exists('zw_control_doc_viewer_trigger') ? zw_control_doc_viewer_trigger(array(
                            'name'         => '正式用印件（加密 PDF）',
                            'kind'         => 'pdf',
                            'url'          => $pdf_inline_url,
                            'download_url' => zw_doc_pdf_download_url($doc['id']),
                            'size'         => (int) $ov_pdf['bytes'],
                            'label'        => '在线浏览（加密 PDF）',
                        )) : '';
                        ?>
                        <p style="margin:6px 0 0">
                            <?php if ($pdf_viewer !== '') : ?>
                                <?php echo $pdf_viewer; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?>
                            <?php else : ?>
                                <a class="button" href="<?php echo esc_url($pdf_inline_url); ?>" target="_blank" rel="noopener">在线浏览（加密 PDF）</a>
                            <?php endif; ?>
                        </p>
                        <p class="zw-doc-muted">
                            文件为 <strong>AES-256 加密件</strong>，打开时会提示<strong>编辑口令</strong>；系统<strong>不在服务端解密</strong>，
                            仅向<strong>本发文的可见者</strong>（发起人 / 审核人 / 抄送 / 分管领导 / 管理员）提供该内嵌通道。
                        </p>
                    <?php else : ?>
                        <form method="post">
                            <?php wp_nonce_field('zw_doc_pdf_queue_' . (int) $doc['id']); ?>
                            <input type="hidden" name="zw_doc_action" value="pdf_queue">
                            <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                            <p>
                                <label>栅格化精度
                                    <select name="pdf_dpi">
                                        <?php foreach (zw_doc_pdf_settings()['dpi_options'] as $dpi_option) : ?>
                                            <option value="<?php echo (int) $dpi_option; ?>"<?php selected((int) zw_doc_pdf_settings()['dpi_default'], (int) $dpi_option); ?>><?php echo (int) $dpi_option; ?> dpi</option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="button button-primary" type="submit"<?php echo (!empty($pdf_blockers) || !empty($pdf_tools['missing'])) ? ' disabled' : ''; ?>>生成正式用印件</button>
                            </p>
                        </form>
                    <?php endif; ?>
                    <p class="zw-doc-muted">
                        P1 整页栅格化 + AES-256 加密（编辑口令）：PDF 内<strong>没有独立的印章对象</strong>，无法被单独提取；
                        但<strong>权限位是规范层标记、不是密码学保护</strong>，<strong>无法阻止截图</strong>。文字不可选中、不可检索。
                    </p>
                </div>
            <?php endif; ?>

            <?php
            // 任命同步状态（TASK-1291 / 方案 §6.4）：只有 appointments 非空才显示，只读回显 + 「重新同步」。
            if (function_exists('zw_doc_appoint_appoint_meta')) :
                $ap_meta = zw_doc_appoint_appoint_meta($doc_id);
                if (!empty($ap_meta['has_content'])) :
                    $ap_sync   = zw_doc_appoint_sync_status($doc_id, $ap_meta);
                    $ap_state  = (string) $ap_sync['state'];
                    $ap_badge  = $ap_state === 'synced' ? 'is-ok' : ($ap_state === 'error' ? 'is-warn' : '');
                    $ap_label  = $ap_state === 'synced' ? '已同步' : ($ap_state === 'error' ? '部分成功 / 待人工处理' : '未同步');
                    $ap_scope  = $ap_meta['meta']['type'] === 'hq' ? '协会本级 → 组织架构 → 人员' : '分支机构 → 负责人管理';
                    $ap_target = '';
                    if ($ap_meta['meta']['type'] === 'hq') {
                        $ap_dept   = (string) $ap_meta['meta']['org_dept'];
                        $ap_target = zw_doc_appoint_org_dept_name($ap_dept) . '｜' . zw_doc_appoint_org_duty_name((string) $ap_meta['meta']['org_duty']);
                    } else {
                        $ap_first  = isset($ap_meta['items'][0]['branch']) ? (string) $ap_meta['items'][0]['branch'] : '';
                        $ap_target = $ap_first;
                    }
                    ?>
                    <div class="zw-doc-card">
                        <h2>任命同步状态</h2>
                        <p>
                            <span class="zw-doc-badge <?php echo esc_attr($ap_badge); ?>"><?php echo esc_html($ap_label); ?></span>
                            <span class="zw-doc-muted">
                                <?php echo esc_html('通道：' . $ap_scope); ?><?php echo $ap_target !== '' ? '｜对象：' . esc_html($ap_target) : ''; ?>
                                <?php echo '｜任命 ' . (int) $ap_meta['count'] . ' 人'; ?>
                                <?php echo '｜触发口径：' . esc_html(zw_doc_appoint_sync_trigger_label()); ?>
                                <?php if ((string) $ap_sync['synced_at'] !== '') : ?><?php echo '｜同步时间：' . esc_html((string) $ap_sync['synced_at']); ?><?php endif; ?>
                            </span>
                        </p>
                        <?php if ($ap_state === 'error' && !empty($ap_sync['problems'])) : ?>
                            <p><strong>待人工处理原因</strong></p>
                            <ul style="margin:0 0 10px 20px;list-style:disc">
                                <?php foreach ($ap_sync['problems'] as $ap_problem) : ?>
                                    <li><?php echo esc_html((string) $ap_problem); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php elseif ($ap_state === 'none') : ?>
                            <p class="zw-doc-muted"><?php echo esc_html((string) $ap_sync['detail']); ?></p>
                        <?php endif; ?>
                        <?php if (current_user_can('manage_options')) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0">
                                <?php wp_nonce_field('zw_doc_appoint_resync'); ?>
                                <input type="hidden" name="action" value="zw_doc_appoint_resync">
                                <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                <button class="button" type="submit">重新同步</button>
                                <span class="zw-doc-muted">重新同步重试人员库写入与后置通道（带印章正式版归档 / 任命文件 webp / 任命书证书）；<strong>公文审批 / 用印状态不回滚</strong>。</span>
                            </form>
                        <?php else : ?>
                            <p class="zw-doc-muted">当前账号没有重新同步权限（需管理员）。</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            // 版式预览（TASK-767）：只有在线撰写的正文才渲染；旧记录无 body 时不显示本区块。
            if (!empty($doc['body']) && function_exists('zw_doc_render_preview_html')) :
                $preview_args = zw_doc_build_render_args($doc);
                ?>
                <div class="zw-doc-card">
                    <h2>版式预览</h2>
                    <p>
                        <a class="button button-primary" target="_blank" rel="noopener"
                           href="<?php echo esc_url(zw_doc_print_url($doc['id'])); ?>">打印 / 另存为 PDF</a>
                        <a class="button" href="<?php echo esc_url(zw_doc_docx_export_url($doc['id'])); ?>">导出 DOCX</a>
                        <?php if (function_exists('zw_doc_can_edit_doc') && zw_doc_can_edit_doc($doc)) : ?>
                            <a class="button" href="<?php echo esc_url(zw_doc_admin_url(array('view' => 'compose', 'doc_id' => (int) $doc['id']))); ?>">编辑正文</a>
                        <?php endif; ?>
                        <span class="zw-doc-muted">打印视图在新窗口打开并自动调起打印对话框；DOCX 为可编辑排版件（<strong>不含印章</strong>，用印请走正式用印件流程），且<strong>只写入国标字体名</strong> —— 本机未安装 仿宋_GB2312 / 方正小标宋简体 时，Word/WPS 会用替代字体显示。</span>
                    </p>
                    <?php if (function_exists('zw_doc_letterhead_registry')) : ?>
                        <?php
                        $lh_list   = zw_doc_letterhead_registry();
                        $lh_now    = zw_doc_letterhead_resolve($doc);
                        // TASK-1422：全局「红头生成方式」——`auto`（默认，系统自动生成红头）时
                        // 「本篇单独指定 / 文种映射 / 默认红头」都**不生效**，必须在下方向用户说明；
                        // `blocked`（切到图片模式却无可用已启用红头）时给出 fail-closed 提示与引导。
                        $lh_is_auto = function_exists('zw_doc_letterhead_mode_is_auto') ? zw_doc_letterhead_mode_is_auto() : false;
                        $lh_labels = array(
                            'document' => '本篇单独指定',
                            'doc_type' => '按文种映射',
                            'default'  => '默认红头',
                            'none'     => ($lh_is_auto ? '系统自动生成红头（全局默认）' : '未使用红头素材（回退文字机关标志）'),
                        );
                        ?>
                        <form method="post" action="<?php echo esc_url(zw_doc_assets_admin_url(array('tab' => 'letterhead'))); ?>" style="margin:0 0 12px">
                            <?php wp_nonce_field('zw_doc_assets'); ?>
                            <input type="hidden" name="zw_doc_assets_action" value="letterhead_pick">
                            <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                            <label>红头
                                <select name="letterhead_id">
                                    <option value="0">跟随默认 / 文种映射</option>
                                    <?php foreach ($lh_list as $lid => $lmeta) : ?>
                                        <option value="<?php echo (int) $lid; ?>"<?php selected((int) $doc['letterhead_id'], (int) $lid); ?>>
                                            <?php echo esc_html((string) $lmeta['name']); ?>（<?php echo (int) $lmeta['dpi']; ?>dpi）
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button class="button button-small" type="submit">保存红头</button>
                            <span class="zw-doc-muted">
                                当前：<?php echo esc_html(isset($lh_labels[$lh_now['source']]) ? $lh_labels[$lh_now['source']] : $lh_now['source']); ?>
                                <?php if ($lh_now['name'] !== '') : ?>（<?php echo esc_html($lh_now['name']); ?>，<?php echo (int) $lh_now['dpi']; ?>dpi）<?php endif; ?>
                                <?php if ($lh_now['low']) : ?><span class="zw-doc-badge is-warn">低清</span><?php endif; ?>
                            </span>
                        </form>
                        <?php if (!empty($lh_now['blocked'])) : ?>
                            <p class="zw-doc-muted" style="color:#b30404">
                                <strong>红头 fail-closed：</strong><?php echo esc_html((string) $lh_now['reason']); ?>
                                <?php if (!empty($lh_now['assets_url'])) : ?>
                                    <a href="<?php echo esc_url((string) $lh_now['assets_url']); ?>">前往「红头与印章 → 红头模板」</a>
                                <?php endif; ?>
                            </p>
                        <?php elseif ($lh_is_auto) : ?>
                            <p class="zw-doc-muted">
                                全局红头生成方式为「系统自动生成红头」（默认）：本页「红头」选择与「文种映射」<strong>当前不生效</strong>，
                                仅在「红头与印章 → 红头模板」把生成方式切为「使用上传的红头文件（图片）」后生效。
                                <a href="<?php echo esc_url(zw_doc_assets_admin_url(array('tab' => 'letterhead'))); ?>">前往设置</a>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div style="overflow:auto;max-height:720px;border:1px solid #dcdcde;background:#f0f0f1;padding:12px;">
                        <?php echo zw_doc_render_preview_html($doc['body'], $preview_args); // phpcs:ignore WordPress.Security.EscapeOutput -- 渲染器内部逐字段转义 ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php
            // 法务（TASK-771）：仅对「有正文」的发文展示；结论决定用印门禁 G2 是否放行。
            if (!empty($doc['body']) && function_exists('zw_doc_legal_status')) :
                $legal     = zw_doc_legal_status($doc['id']);
                $can_legal = zw_doc_legal_can_review();
                ?>
                <div class="zw-doc-card" id="zw-doc-legal">
                    <h2>法务审核</h2>
                    <p>
                        <strong>状态：</strong><?php echo esc_html(zw_doc_legal_status_label($legal['status'])); ?>
                        <?php if ($legal['reviewer_type'] !== '') : ?>
                            <span class="zw-doc-muted">（<?php echo $legal['reviewer_type'] === 'ai' ? 'AI 初审' : '人工判定'; ?><?php echo $legal['reviewed_at'] !== '' ? '，' . esc_html($legal['reviewed_at']) : ''; ?>）</span>
                        <?php endif; ?>
                    </p>
                    <?php if ($legal['policy_version'] !== '') : ?>
                        <p class="zw-doc-muted">政策版本：<?php echo esc_html($legal['policy_version']); ?>｜参与判定条款：<?php echo (int) $legal['clause_sent']; ?> / <?php echo (int) $legal['clause_total']; ?></p>
                    <?php endif; ?>
                    <?php if ($legal['summary'] !== '') : ?>
                        <p><strong>结论：</strong><?php echo esc_html($legal['summary']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($legal['issues'])) : ?>
                        <ul style="margin:0 0 10px 20px;list-style:disc">
                            <?php foreach ($legal['issues'] as $issue) : ?>
                                <li>
                                    [<?php echo esc_html((string) $issue['severity']); ?>]
                                    <?php if ($issue['label'] !== '') : ?><?php echo esc_html((string) $issue['label']); ?>：<?php endif; ?>
                                    <?php echo esc_html((string) $issue['message']); ?>
                                    <?php if ($issue['suggestion'] !== '') : ?><span class="zw-doc-muted">（建议：<?php echo esc_html((string) $issue['suggestion']); ?>）</span><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="zw-doc-muted">AI 异常/不通过一律转人工且**不自动放行**；本模块只保存结论与问题摘要，<strong>不保存正文全文</strong>。</p>
                    <?php if ($can_legal) : ?>
                        <form method="post" action="<?php echo esc_url(zw_doc_legal_admin_url()); ?>" style="display:inline">
                            <?php wp_nonce_field('zw_doc_legal'); ?>
                            <input type="hidden" name="zw_doc_legal_action" value="submit">
                            <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                            <button class="button" type="submit"><?php echo $legal['status'] === 'none' ? '送法务审核' : '重新送审'; ?></button>
                        </form>
                        <?php if ($legal['status'] !== 'pass') : ?>
                            <form method="post" action="<?php echo esc_url(zw_doc_legal_admin_url()); ?>" style="display:inline">
                                <?php wp_nonce_field('zw_doc_legal'); ?>
                                <input type="hidden" name="zw_doc_legal_action" value="decide">
                                <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                <input type="hidden" name="legal_decision" value="pass">
                                <button class="button button-primary" type="submit">人工判定通过</button>
                            </form>
                        <?php endif; ?>
                    <?php else : ?>
                        <p class="zw-doc-muted">当前账号没有法务判定权限（需 AI法务部角色或管理员）。</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            // 用印（TASK-769）：仅对「有正文」的发文展示；门禁 G1~G7 由服务端强制，此处只做如实提示。
            if (!empty($doc['body']) && function_exists('zw_doc_seal_gate')) :
                $is_sealed = ((string) $doc['seal_mode'] === 'formal');
                // TASK-1382 ④：同版本解锁编辑态（`seal_mode` 仍为 formal，但正文/要素已解锁，用印记录与完成文件保留）。
                $edit_unlocked = ($is_sealed && function_exists('zw_doc_seal_edit_unlocked') && zw_doc_seal_edit_unlocked($doc['id']));
                $seal_all  = zw_doc_seal_registry();
                $seal_opts = array();
                foreach ($seal_all as $sid => $smeta) {
                    if (!empty($smeta['enabled'])) {
                        $seal_opts[(string) $sid] = $smeta;
                    }
                }
                $can_seal  = zw_doc_seal_can_use();
                // 未用印、或「已用印但已同版本解锁编辑」⇒ 都要走同一套用印门禁 G1~G7。
                $blockers  = (!$is_sealed || $edit_unlocked) ? zw_doc_seal_gate($doc, array(
                    'legal_status' => (string) get_post_meta($doc['id'], '_zw_doc_legal_status', true),
                    'can_seal'     => $can_seal,
                    // TASK-1995：门禁校验用「按发文机关匹配到的章」，无匹配回退默认章（与渲染层同源）。
                    'seal_id'      => (function_exists('zw_doc_seal_match_id') && zw_doc_seal_match_id($doc) !== '')
                        ? zw_doc_seal_match_id($doc)
                        : zw_doc_seal_default_id(),
                    'seal_enabled' => !empty($seal_opts),
                    'confirmed'    => true,
                )) : array();
                ?>
                <div class="zw-doc-card" id="zw-doc-seal">
                    <h2>用印</h2>
                    <?php if ($is_sealed) : ?>
                        <?php if ($edit_unlocked) : ?>
                            <p>
                                已用印（印章 <code><?php echo esc_html((string) $doc['seal_id']); ?></code>）·
                                <strong>已同版本解锁编辑</strong>：正文与要素可修改，<strong>已用印记录与完成文件保留</strong>。
                                编辑完成后请<strong>重新用印</strong>；上一版用印记录会进入用印历史，不丢失。
                            </p>
                            <p class="zw-doc-muted">解锁期间请勿触发「重新同步 / 证书生成」：这些通道以当前产物为准，重新用印后才是最终版。</p>
                        <?php else : ?>
                            <p>
                                已用印（印章 <code><?php echo esc_html((string) $doc['seal_id']); ?></code>）：正文已锁定，如需修改有两条路径 ——
                                ① <strong>同版本解锁编辑</strong>（TASK-1382：保留已用印记录与完成文件，只解锁正文 + 要素）；
                                ② 撤销用印后重新审核（会清除本次用印记录）。
                            </p>
                            <form method="post" action="<?php echo esc_url(zw_doc_assets_admin_url()); ?>" style="display:inline">
                                <?php wp_nonce_field('zw_doc_assets'); ?>
                                <input type="hidden" name="zw_doc_assets_action" value="seal_edit_unlock">
                                <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                <button class="button button-primary" type="submit" onclick="return confirm('确认同版本解锁编辑？正文与要素将可修改，已用印记录与完成文件保留。');">同版本解锁编辑（保留已用印记录）</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(zw_doc_assets_admin_url()); ?>" style="display:inline">
                                <?php wp_nonce_field('zw_doc_assets'); ?>
                                <input type="hidden" name="zw_doc_assets_action" value="seal_revoke">
                                <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                                <button class="button" type="submit" onclick="return confirm('确认撤销用印？撤销后正文解锁，须重新审核才能再次用印。');">撤销用印</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!$is_sealed || $edit_unlocked) : ?>
                        <?php if (!$can_seal) : ?>
                            <p class="zw-doc-muted">当前账号没有用印权限。</p>
                        <?php endif; ?>
                        <?php if (!empty($blockers)) : ?>
                            <p><strong>当前尚不满足用印条件：</strong></p>
                            <ul style="margin:0 0 10px 20px;list-style:disc">
                                <?php foreach ($blockers as $blocker) : ?>
                                    <li><?php echo esc_html($blocker['message']); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($can_seal && empty($seal_opts)) : ?>
                            <p class="zw-doc-muted">尚未上传可用印章，请先到「<a href="<?php echo esc_url(zw_doc_assets_admin_url()); ?>">红头与印章</a>」上传。</p>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url(zw_doc_assets_admin_url()); ?>">
                            <?php wp_nonce_field('zw_doc_assets'); ?>
                            <input type="hidden" name="zw_doc_assets_action" value="seal_apply">
                            <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                            <p>
                                <label>印章
                                    <select name="seal_id" required>
                                        <?php foreach ($seal_opts as $sid => $smeta) : ?>
                                            <?php $zw_seal_preselect = (function_exists('zw_doc_seal_match_id') && zw_doc_seal_match_id($doc) !== '') ? zw_doc_seal_match_id($doc) : zw_doc_seal_default_id(); ?>
                                            <option value="<?php echo esc_attr($sid); ?>"<?php selected($zw_seal_preselect, $sid); ?>>
                                                <?php echo esc_html((string) $smeta['name']); ?>
                                                （<?php echo (int) $smeta['effective_dpi']; ?>dpi<?php echo ($smeta['transparent'] === false) ? '，白底不透明' : ''; ?>）
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </p>
                            <p>
                                <label>二次确认：请输入 <code>SEAL</code>
                                    <input type="text" name="seal_confirm" value="" autocomplete="off" required>
                                </label>
                            </p>
                            <p>
                                <button class="button button-primary" type="submit"><?php echo $edit_unlocked ? '重新用印（以新产物为准）' : '正式用印'; ?></button>
                                <span class="zw-doc-muted">用印不可逆：确认后正文将被锁定，且正式用印件为加密 PDF（通道 C，见后续任务）。</span>
                            </p>
                        </form>
                        <p class="zw-doc-muted">门禁（服务端强制）：发文已审核通过 · 已取得法务审批结论（无审批不得用印）· 具备用印权限 · 印章启用 · 二次确认 · 版式自检通过。</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            // TASK-1382 ③：完成文件卡片已按新的信息架构**前移到「流转」之后**（同文件上方 `id="zw-doc-files"` 区块）。
            // 判定条件与数据来源逐字未改，仅改变渲染位置 —— 保证首屏「概览 → 纸面要素 → 流转 → 完成文件」的顺序目标。
            ?>

            <?php if ($doc['status'] === 'pending' && (int) $doc['reviewer_id'] === get_current_user_id()) : ?>
                <div class="zw-doc-card">
                    <h2>审核操作</h2>
                    <form method="post">
                        <?php wp_nonce_field('zw_doc_review_document'); ?>
                        <input type="hidden" name="zw_doc_action" value="review_document">
                        <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                        <p><textarea name="review_note" rows="3" style="width:100%" placeholder="审核意见；驳回时必填"></textarea></p>
                        <p>
                            <button class="button button-primary" type="submit" name="review_decision" value="approve">审核通过</button>
                            <button class="button" type="submit" name="review_decision" value="reject" onclick="return confirm('确认驳回？');">驳回</button>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($doc['status'] === 'pending' && (int) $doc['author_id'] === get_current_user_id() && !empty($flow['allow_withdraw'])) : ?>
                <div class="zw-doc-card">
                    <h2>发起人操作</h2>
                    <form method="post" onsubmit="return confirm('确认撤回该发文？');">
                        <?php wp_nonce_field('zw_doc_withdraw_document'); ?>
                        <input type="hidden" name="zw_doc_action" value="withdraw_document">
                        <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                        <button class="button" type="submit">撤回</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="zw-doc-card">
                <h2>流转记录</h2>
                <?php
                // TASK-1410：自动流转轨迹 meta（`_zw_doc_auto_flow`）。与事件表并列展示 ——
                // 事件回答「发生了什么」，轨迹回答「系统自动推进到哪一步 / 为何停住 / 依据哪个开关」。
                $auto_trace = function_exists('zw_doc_auto_flow_trace') ? zw_doc_auto_flow_trace($doc_id) : array('steps' => array(), 'updated_at' => '');
                ?>
                <?php if (!empty($auto_trace['steps'])) : ?>
                    <h3 style="margin:14px 0 6px">系统自动流转轨迹</h3>
                    <div class="zw-adm-table-wrap">
                    <table class="widefat striped">
                        <thead><tr><th>时间</th><th>步骤</th><th>触发开关</th><th>结果</th><th>原因 / 门禁结论</th></tr></thead>
                        <tbody>
                        <?php foreach ($auto_trace['steps'] as $auto_step) : ?>
                            <tr>
                                <td><?php echo esc_html($auto_step['at']); ?></td>
                                <td><?php echo esc_html(zw_doc_auto_flow_step_label($auto_step['step'])); ?></td>
                                <td><code><?php echo esc_html($auto_step['switch']); ?></code></td>
                                <td><?php echo $auto_step['result'] === 'done'
                                    ? '<span class="zw-adm-badge is-on">已完成</span>'
                                    : '<span class="zw-adm-badge">已停住</span>'; ?></td>
                                <td><?php echo esc_html($auto_step['reason']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
                <div class="zw-adm-table-wrap">
                <table class="widefat striped">
                    <thead><tr><th>时间</th><th>来源</th><th>操作人</th><th>事件</th><th>说明</th></tr></thead>
                    <tbody>
                    <?php if (empty($events)) : ?>
                        <tr><td colspan="5">暂无记录。</td></tr>
                    <?php else : ?>
                        <?php foreach ($events as $event) : ?>
                            <?php $event_origin = zw_doc_auto_flow_event_origin($event['event_type']); ?>
                            <tr>
                                <td><?php echo esc_html($event['created_at']); ?></td>
                                <td><?php echo $event_origin === 'auto'
                                    ? '<span class="zw-adm-badge is-on">系统自动</span>'
                                    : '<span class="zw-adm-badge">人工</span>'; ?></td>
                                <td><?php echo esc_html(zw_doc_user_label((int) $event['actor_id'])); ?></td>
                                <td><?php echo esc_html($event['event_type']); ?></td>
                                <td><?php echo esc_html($event['note']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <p><a class="button" href="<?php echo esc_url(zw_doc_admin_url()); ?>">返回列表</a></p>
        </div>
        <?php
    }
}
