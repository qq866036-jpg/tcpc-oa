<?php
/**
 * AI 秘书处 · 任务查询与视图装配（TASK-1352 · 阶段一）。
 *
 * 只做**读**：查询 + 装配列表行/详情视图；不写任何数据（写入口在 missions/gates/qa）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_secretariat_query_tasks')) {
    /**
     * 查询任务列表。
     *
     * @param array $args status / scenario / per_page / paged / q。
     * @return array{rows:array<int,array<string,mixed>>,total:int,pages:int,paged:int,per_page:int}
     */
    function zw_secretariat_query_tasks($args = array()) {
        $args = is_array($args) ? $args : array();
        $per_page = isset($args['per_page']) ? max(1, min(200, (int) $args['per_page'])) : 20;
        $paged    = isset($args['paged']) ? max(1, (int) $args['paged']) : 1;

        if (!function_exists('get_posts')) {
            return array('rows' => array(), 'total' => 0, 'pages' => 0, 'paged' => $paged, 'per_page' => $per_page);
        }

        $meta_query = array();
        $status     = isset($args['status']) ? sanitize_key((string) $args['status']) : '';
        if ($status !== '' && array_key_exists($status, zw_secretariat_statuses())) {
            $meta_query[] = array('key' => '_zw_sec_status', 'value' => $status);
        }
        $scenario = isset($args['scenario']) ? zw_secretariat_scenario_key($args['scenario']) : '';
        if ($scenario !== '') {
            $meta_query[] = array('key' => '_zw_sec_scenario', 'value' => $scenario);
        }

        $query_args = array(
            'post_type'      => ZW_SECRETARIAT_CPT,
            'post_status'    => 'any',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'fields'         => 'ids',
        );
        if ($meta_query) {
            $query_args['meta_query'] = $meta_query;
        }
        $q = isset($args['q']) ? trim((string) $args['q']) : '';
        if ($q !== '') {
            $query_args['s'] = $q;
        }

        $posts = get_posts($query_args);
        $ids   = is_array($posts) ? array_map('intval', $posts) : array();

        $rows = array();
        foreach ($ids as $id) {
            $rows[] = zw_secretariat_task_summary($id);
        }
        return array(
            'rows'     => $rows,
            'total'    => count($rows),
            'pages'    => 1,
            'paged'    => $paged,
            'per_page' => $per_page,
        );
    }
}

if (!function_exists('zw_secretariat_task_summary')) {
    /**
     * 单任务摘要（列表行 / 详情页共用）。
     *
     * @param int $task_id 任务 ID。
     * @return array<string,mixed>
     */
    function zw_secretariat_task_summary($task_id) {
        $task_id = (int) $task_id;
        $title   = function_exists('get_the_title') ? (string) get_the_title($task_id) : '';
        $status  = zw_secretariat_status_of($task_id);
        $qa      = function_exists('zw_secretariat_qa_summary') ? zw_secretariat_qa_summary($task_id) : array('total' => 0, 'pass' => 0, 'fail' => 0, 'pending' => 0, 'items' => array());

        return array(
            'id'                => $task_id,
            'title'             => $title,
            'scenario'          => (string) zw_secretariat_get_meta($task_id, '_zw_sec_scenario'),
            'scenario_label'    => zw_secretariat_scenario_label(zw_secretariat_get_meta($task_id, '_zw_sec_scenario')),
            'cycle'             => (string) zw_secretariat_get_meta($task_id, '_zw_sec_cycle'),
            'cycle_label'       => zw_secretariat_cycle_label(zw_secretariat_get_meta($task_id, '_zw_sec_cycle')),
            'status'            => $status,
            'status_label'      => zw_secretariat_status_label($status),
            'publisher_id'      => (int) zw_secretariat_get_meta($task_id, '_zw_sec_publisher_id'),
            'owner_id'          => (int) zw_secretariat_get_meta($task_id, '_zw_sec_owner_id'),
            'deliverables'      => zw_secretariat_get_list_meta($task_id, '_zw_sec_deliverables'),
            'source_count'      => count(zw_secretariat_get_list_meta($task_id, '_zw_sec_source_ids')),
            'gates'             => function_exists('zw_secretariat_gates_summary') ? zw_secretariat_gates_summary($task_id) : array(),
            'gate_done'         => function_exists('zw_secretariat_gate_blockers') ? (zw_secretariat_gate_blockers($task_id) === array()) : false,
            'qa'                => $qa,
            'qa_pass'           => isset($qa['pending']) && (int) $qa['pending'] === 0 && (int) $qa['fail'] === 0 && (int) $qa['total'] > 0,
            // `publishable` 只对**待发布**（final_review）任务有意义：已发布/已存档任务不应再被视为"可发布"。
            'publishable'       => ($status === 'final_review') && zw_secretariat_is_publishable($task_id),
            'publish_blockers'  => zw_secretariat_publish_blockers($task_id),
            'updated_at'        => (string) zw_secretariat_get_meta($task_id, '_zw_sec_updated_at'),
            'published_at'      => (string) zw_secretariat_get_meta($task_id, '_zw_sec_published_at'),
            'publish_command'   => (string) zw_secretariat_get_meta($task_id, '_zw_sec_publish_command'),
            'linked_doc_id'     => (int) zw_secretariat_get_meta($task_id, '_zw_sec_linked_doc_id'),
        );
    }
}

if (!function_exists('zw_secretariat_task_detail')) {
    /**
     * 单任务详情（含四步文本与门/质检明细）。
     *
     * @param int $task_id 任务 ID。
     * @return array<string,mixed>
     */
    function zw_secretariat_task_detail($task_id) {
        $task_id = (int) $task_id;
        $row     = zw_secretariat_task_summary($task_id);
        $row['read_note']        = (string) zw_secretariat_get_meta($task_id, '_zw_sec_read_note');
        $row['outline']          = (string) zw_secretariat_get_meta($task_id, '_zw_sec_outline');
        $row['draft_text']       = (string) zw_secretariat_get_meta($task_id, '_zw_sec_draft_text');
        $row['final_text']       = (string) zw_secretariat_get_meta($task_id, '_zw_sec_final_text');
        $row['milestones']       = zw_secretariat_get_list_meta($task_id, '_zw_sec_milestones');
        $row['media_retention']  = zw_secretariat_get_list_meta($task_id, '_zw_sec_media_retention');
        $row['source_ids']       = zw_secretariat_get_list_meta($task_id, '_zw_sec_source_ids');
        $row['gates_detail']     = function_exists('zw_secretariat_gate_records') ? zw_secretariat_gate_records($task_id) : array();
        $row['qa_detail']        = function_exists('zw_secretariat_qa_report') ? zw_secretariat_qa_report($task_id) : array();
        return $row;
    }
}

if (!function_exists('zw_secretariat_next_action')) {
    /**
     * 当前状态下的**下一步动作**提示（供页面引导；不代替人工判断）。
     *
     * @param array $task 任务摘要/详情。
     * @return array{label:string,hint:string}
     */
    function zw_secretariat_next_action($task) {
        $task   = is_array($task) ? $task : array();
        $status = isset($task['status']) ? (string) $task['status'] : 'draft';
        $map    = array(
            'draft'            => array('label' => '填写步骤一（通读依从）并提交纲要', 'hint' => '提交后进入门① 纲要确认。'),
            'outline_review'   => array('label' => '等待门① 纲要确认', 'hint' => '发布人确认后方可进入步骤三。'),
            'outline_approved' => array('label' => '填写步骤三（初稿素材）并提交审阅', 'hint' => '提交后进入门② 初稿审阅。'),
            'draft_review'     => array('label' => '等待门② 初稿审阅', 'hint' => '发布人批准后方可进入步骤四。'),
            'draft_approved'   => array('label' => '填写步骤四（正式文本）并完成质量校验 8 项', 'hint' => '8 项全通过后提交终审。'),
            'final_review'     => array('label' => '等待门③ 终稿终审', 'hint' => '由发布人下达「发布」或「存档」命令。'),
            'published'        => array('label' => '已发布（终态）', 'hint' => '文本已锁定，不可再修改。'),
            'archived'         => array('label' => '已存档（终态）', 'hint' => '文本已锁定，不可再修改。'),
        );
        return isset($map[$status]) ? $map[$status] : array('label' => '', 'hint' => '');
    }
}
