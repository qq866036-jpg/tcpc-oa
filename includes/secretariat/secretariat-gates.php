<?php
/**
 * AI 秘书处 · 三道人审门（TASK-1352 · 阶段一）。
 *
 * 需求出处：§3「每个步骤结束设人审门，未获发布人确认不得进入下一步」/
 *           §7「三道人审门 + 发布/存档为人工命令，AI 仅可建议」。
 *
 * 门定义（与状态机一一对应）：
 *   ① `outline` 纲要确认：outline_review    → outline_approved
 *   ② `draft`   初稿审阅：draft_review      → draft_approved
 *   ③ `final`   终稿终审：final_review      → published（命令 publish）/ archived（命令 archive）
 *
 * 硬规则（一票否决）：
 *   1. **具名**：确认必须记录确认人 id **与当时显示名**（人名留痕，不随改名漂移）；
 *   2. **顺序**：门②必须在门①确认之后；门③必须在门②批准之后；
 *   3. **不得跳门**：任一门前置未满足时，确认动作被拒并给出可读原因；
 *   4. **AI 不得自动发布**：发布/存档只接受**具名人工命令**（本模块是唯一写终态的入口）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_secretariat_gates')) {
    /**
     * 门定义（顺序即门的先后；`allows_command` 表示该门可下达发布/存档命令）。
     *
     * @return array<string,array<string,mixed>>
     */
    function zw_secretariat_gates() {
        return array(
            'outline' => array(
                'label'         => '门① 纲要确认',
                'from'          => 'outline_review',
                'to'            => 'outline_approved',
                'allows_command'=> false,
                'requirement'   => '工作纲要（含里程碑与交付物地图）已填写。',
                'field'         => '_zw_sec_outline',
            ),
            'draft' => array(
                'label'         => '门② 初稿审阅',
                'from'          => 'draft_review',
                'to'            => 'draft_approved',
                'allows_command'=> false,
                'requirement'   => '初稿素材（内容提要 / 大纲 / 初稿）已填写。',
                'field'         => '_zw_sec_draft_text',
            ),
            'final' => array(
                'label'         => '门③ 终稿终审',
                'from'          => 'final_review',
                'to'            => '', // 由人工命令决定 published / archived
                'allows_command'=> true,
                'requirement'   => '正式文本已填写，且质量校验 8 项全部通过。',
                'field'         => '_zw_sec_final_text',
            ),
        );
    }
}

if (!function_exists('zw_secretariat_gate_order')) {
    /**
     * 门的先后顺序（key 数组）。
     *
     * @return string[]
     */
    function zw_secretariat_gate_order() {
        return array_keys(zw_secretariat_gates());
    }
}

if (!function_exists('zw_secretariat_gate_label')) {
    /**
     * 门显示名。
     *
     * @param string $gate 门 key。
     * @return string
     */
    function zw_secretariat_gate_label($gate) {
        $all = zw_secretariat_gates();
        $gate = sanitize_key((string) $gate);
        return isset($all[$gate]) ? (string) $all[$gate]['label'] : $gate;
    }
}

if (!function_exists('zw_secretariat_gate_records')) {
    /**
     * 读取三门的确认记录（恒返回完整结构，缺失即为 open）。
     *
     * @param int $task_id 任务 ID。
     * @return array<string,array<string,mixed>>
     */
    function zw_secretariat_gate_records($task_id) {
        $stored = function_exists('get_post_meta') ? get_post_meta((int) $task_id, '_zw_sec_gates', true) : array();
        $stored = is_array($stored) ? $stored : array();
        $out    = array();
        foreach (zw_secretariat_gate_order() as $gate) {
            $row = isset($stored[$gate]) && is_array($stored[$gate]) ? $stored[$gate] : array();
            $out[$gate] = array(
                'state'      => isset($row['state']) ? (string) $row['state'] : 'open', // open|confirmed|returned
                'by'         => isset($row['by']) ? (int) $row['by'] : 0,
                'by_name'    => isset($row['by_name']) ? (string) $row['by_name'] : '',
                'at'         => isset($row['at']) ? (string) $row['at'] : '',
                'note'       => isset($row['note']) ? (string) $row['note'] : '',
                'command'    => isset($row['command']) ? (string) $row['command'] : '', // publish|archive
                'decision'   => isset($row['decision']) ? (string) $row['decision'] : '', // approve|return
            );
        }
        return $out;
    }
}

if (!function_exists('zw_secretariat_gate_save_record')) {
    /**
     * 写入单门记录（内部使用）。
     *
     * @param int    $task_id 任务 ID。
     * @param string $gate    门 key。
     * @param array  $record  记录。
     * @return void
     */
    function zw_secretariat_gate_save_record($task_id, $gate, $record) {
        $all  = zw_secretariat_gate_records($task_id);
        $gate = sanitize_key((string) $gate);
        if (!isset($all[$gate])) {
            return;
        }
        $all[$gate] = array_merge($all[$gate], (array) $record);
        update_post_meta((int) $task_id, '_zw_sec_gates', $all);
    }
}

if (!function_exists('zw_secretariat_gate_blockers')) {
    /**
     * **未完成门的阻断原因**（供发布闸门与页面提示）。
     *
     * 返回空数组 = 三门齐备且具名确认。
     *
     * @param int $task_id 任务 ID。
     * @return string[]
     */
    function zw_secretariat_gate_blockers($task_id) {
        $records = zw_secretariat_gate_records($task_id);
        $block   = array();
        foreach (zw_secretariat_gate_order() as $gate) {
            $row = $records[$gate];
            if ($row['state'] !== 'confirmed') {
                $block[] = zw_secretariat_gate_label($gate) . ' 尚未确认。';
                continue;
            }
            if ((int) $row['by'] <= 0 && trim((string) $row['by_name']) === '') {
                $block[] = zw_secretariat_gate_label($gate) . ' 缺少具名确认人（人名留痕缺失）。';
            }
        }
        return $block;
    }
}

if (!function_exists('zw_secretariat_gate_is_confirmed')) {
    /**
     * 单门是否已确认。
     *
     * @param int    $task_id 任务 ID。
     * @param string $gate    门 key。
     * @return bool
     */
    function zw_secretariat_gate_is_confirmed($task_id, $gate) {
        $records = zw_secretariat_gate_records($task_id);
        $gate    = sanitize_key((string) $gate);
        return isset($records[$gate]) && $records[$gate]['state'] === 'confirmed';
    }
}

if (!function_exists('zw_secretariat_gate_can_confirm')) {
    /**
     * **某门当前是否可确认**（顺序约束的唯一判据）。
     *
     * @param int    $task_id 任务 ID。
     * @param string $gate    门 key。
     * @return true|WP_Error
     */
    function zw_secretariat_gate_can_confirm($task_id, $gate) {
        $task_id = (int) $task_id;
        $gates   = zw_secretariat_gates();
        $gate    = sanitize_key((string) $gate);
        if (!isset($gates[$gate])) {
            return new WP_Error('zw_sec_bad_gate', '未知人审门。');
        }
        $status = zw_secretariat_status_of($task_id);
        if (in_array($status, array('published', 'archived'), true)) {
            return new WP_Error('zw_sec_locked', '任务已' . zw_secretariat_status_label($status) . '，不可再确认人审门。');
        }
        if ($status !== $gates[$gate]['from']) {
            return new WP_Error(
                'zw_sec_gate_not_open',
                zw_secretariat_gate_label($gate) . ' 当前不可确认（任务状态：' . zw_secretariat_status_label($status) . '）。'
            );
        }
        // 顺序约束：本门之前的所有门必须已确认。
        $order = zw_secretariat_gate_order();
        foreach ($order as $prev) {
            if ($prev === $gate) {
                break;
            }
            if (!zw_secretariat_gate_is_confirmed($task_id, $prev)) {
                return new WP_Error(
                    'zw_sec_gate_prev_unconfirmed',
                    '不得跳门：' . zw_secretariat_gate_label($prev) . ' 尚未确认。'
                );
            }
        }
        // 门内文本必须已填写。
        $field = (string) $gates[$gate]['field'];
        if (trim((string) zw_secretariat_get_meta($task_id, $field)) === '') {
            return new WP_Error('zw_sec_gate_missing_text', $gates[$gate]['requirement']);
        }
        // 门③额外要求：质检 8 项全部通过。
        if ($gate === 'final' && function_exists('zw_secretariat_qa_blockers')) {
            $qa = zw_secretariat_qa_blockers($task_id);
            if ($qa) {
                return new WP_Error('zw_sec_gate_qa_failed', '门③ 需先通过质量校验：' . implode(' ', $qa));
            }
        }
        return true;
    }
}

if (!function_exists('zw_secretariat_gate_confirm')) {
    /**
     * **确认一道人审门（唯一入口）**。
     *
     * @param int    $task_id 任务 ID。
     * @param string $gate    门 key。
     * @param array  $args    confirmer_id / note。
     * @return true|WP_Error
     */
    function zw_secretariat_gate_confirm($task_id, $gate, $args = array()) {
        $args    = is_array($args) ? $args : array();
        $task_id = (int) $task_id;
        $gate    = sanitize_key((string) $gate);

        $can = zw_secretariat_gate_can_confirm($task_id, $gate);
        if (function_exists('is_wp_error') && is_wp_error($can)) {
            return $can;
        }
        $gates = zw_secretariat_gates();

        $confirmer_id = isset($args['confirmer_id']) ? (int) $args['confirmer_id']
            : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        $by_name      = '';
        if (function_exists('get_userdata') && $confirmer_id > 0) {
            $user = get_userdata($confirmer_id);
            if ($user && isset($user->display_name)) {
                $by_name = (string) $user->display_name;
            }
        }
        if (isset($args['by_name']) && trim((string) $args['by_name']) !== '') {
            $by_name = zw_secretariat_sanitize_text($args['by_name'], 100);
        }
        if ($confirmer_id <= 0 && $by_name === '') {
            return new WP_Error('zw_sec_gate_no_confirmer', '人审门确认必须具名（确认人缺失）。');
        }

        $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        zw_secretariat_gate_save_record($task_id, $gate, array(
            'state'    => 'confirmed',
            'by'       => $confirmer_id,
            'by_name'  => $by_name,
            'at'       => $now,
            'note'     => isset($args['note']) ? zw_secretariat_sanitize_text($args['note'], 1000) : '',
            'decision' => 'approve',
        ));

        // 门①②：确认后进入下一个"已确认"后态；门③：等待人工命令（不改终态）。
        $to = (string) $gates[$gate]['to'];
        if ($to !== '') {
            $moved = zw_secretariat_transition($task_id, $to, array('note' => 'gate:' . $gate));
            if (function_exists('is_wp_error') && is_wp_error($moved)) {
                return $moved;
            }
        }

        zw_secretariat_audit('gate_confirm', array(
            'task' => $task_id,
            'gate' => $gate,
            'by'   => $confirmer_id,
            'name' => $by_name,
        ));
        return true;
    }
}

if (!function_exists('zw_secretariat_gate_return')) {
    /**
     * 退回（任一门未过，按意见修订后重新提交；不跳过、不默认通过 —— 需求 §7）。
     *
     * @param int    $task_id 任务 ID。
     * @param string $gate    门 key。
     * @param array  $args    note / actor_id。
     * @return true|WP_Error
     */
    function zw_secretariat_gate_return($task_id, $gate, $args = array()) {
        $args    = is_array($args) ? $args : array();
        $task_id = (int) $task_id;
        $gate    = sanitize_key((string) $gate);
        $gates   = zw_secretariat_gates();
        if (!isset($gates[$gate])) {
            return new WP_Error('zw_sec_bad_gate', '未知人审门。');
        }
        $status = zw_secretariat_status_of($task_id);
        if ($status !== $gates[$gate]['from']) {
            return new WP_Error('zw_sec_gate_not_open', zw_secretariat_gate_label($gate) . ' 当前不可退回。');
        }
        // 退回目标：门内 → 退回上一"已批准"状态（门① 回到 draft）。
        $back = array('outline' => 'draft', 'draft' => 'outline_approved', 'final' => 'draft_approved');
        $to   = isset($back[$gate]) ? $back[$gate] : 'draft';

        $actor_id = isset($args['actor_id']) ? (int) $args['actor_id']
            : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        $by_name = '';
        if (function_exists('get_userdata') && $actor_id > 0) {
            $user = get_userdata($actor_id);
            if ($user && isset($user->display_name)) {
                $by_name = (string) $user->display_name;
            }
        }
        $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        zw_secretariat_gate_save_record($task_id, $gate, array(
            'state'    => 'returned',
            'by'       => $actor_id,
            'by_name'  => $by_name,
            'at'       => $now,
            'note'     => isset($args['note']) ? zw_secretariat_sanitize_text($args['note'], 1000) : '',
            'decision' => 'return',
        ));

        // 直接写状态：这是"退回"而非正常推进，不走 allowed_transitions（避免把退回混同为推进）。
        update_post_meta($task_id, '_zw_sec_status', $to);
        update_post_meta($task_id, '_zw_sec_updated_at', $now);
        zw_secretariat_audit('gate_return', array('task' => $task_id, 'gate' => $gate, 'to' => $to, 'by' => $actor_id));
        return true;
    }
}

if (!function_exists('zw_secretariat_gate_command')) {
    /**
     * **门③ 人工命令：发布 / 存档**（需求 §7「发布 / 存档为人工命令」）。
     *
     * 这是**唯一**能把任务推入 `published` / `archived` 终态的入口。
     * 前置（缺一不可）：状态为 final_review + 正式文本非空 + 质检 8 项全通过 + 门①②已具名确认。
     *
     * @param int    $task_id 任务 ID。
     * @param string $command publish|archive。
     * @param array  $args    actor_id / note。
     * @return true|WP_Error
     */
    function zw_secretariat_gate_command($task_id, $command, $args = array()) {
        $args    = is_array($args) ? $args : array();
        $task_id = (int) $task_id;
        $command = sanitize_key((string) $command);
        if (!in_array($command, array('publish', 'archive'), true)) {
            return new WP_Error('zw_sec_bad_command', '非法命令（只接受 发布 / 存档）。');
        }
        $blockers = zw_secretariat_publish_blockers($task_id);
        if ($blockers) {
            return new WP_Error('zw_sec_command_blocked', '不满足发布/存档条件：' . implode(' ', $blockers));
        }

        $actor_id = isset($args['actor_id']) ? (int) $args['actor_id']
            : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        $by_name = '';
        if (function_exists('get_userdata') && $actor_id > 0) {
            $user = get_userdata($actor_id);
            if ($user && isset($user->display_name)) {
                $by_name = (string) $user->display_name;
            }
        }
        if ($actor_id <= 0 && $by_name === '') {
            return new WP_Error('zw_sec_command_no_actor', '发布 / 存档必须具名（操作人缺失）。');
        }

        $to  = ($command === 'publish') ? 'published' : 'archived';
        $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');

        zw_secretariat_gate_save_record($task_id, 'final', array(
            'state'    => 'confirmed',
            'by'       => $actor_id,
            'by_name'  => $by_name,
            'at'       => $now,
            'note'     => isset($args['note']) ? zw_secretariat_sanitize_text($args['note'], 1000) : '',
            'command'  => $command,
            'decision' => 'approve',
        ));
        update_post_meta($task_id, '_zw_sec_status', $to);
        update_post_meta($task_id, '_zw_sec_publish_command', $command);
        update_post_meta($task_id, '_zw_sec_published_at', $now);
        update_post_meta($task_id, '_zw_sec_updated_at', $now);

        zw_secretariat_audit('gate_command', array(
            'task'    => $task_id,
            'command' => $command,
            'to'      => $to,
            'by'      => $actor_id,
            'name'    => $by_name,
        ));
        return true;
    }
}

if (!function_exists('zw_secretariat_gates_summary')) {
    /**
     * 三门摘要（供列表/详情展示）。
     *
     * @param int $task_id 任务 ID。
     * @return array<int,array<string,string>>
     */
    function zw_secretariat_gates_summary($task_id) {
        $records = zw_secretariat_gate_records($task_id);
        $out     = array();
        foreach (zw_secretariat_gate_order() as $gate) {
            $row = $records[$gate];
            $state_label = array('open' => '待确认', 'confirmed' => '已确认', 'returned' => '已退回');
            $out[] = array(
                'key'    => $gate,
                'label'  => zw_secretariat_gate_label($gate),
                'state'  => $row['state'],
                'status' => isset($state_label[$row['state']]) ? $state_label[$row['state']] : $row['state'],
                'by'     => $row['by_name'] !== '' ? $row['by_name'] : (string) $row['by'],
                'at'     => $row['at'],
                'note'   => $row['note'],
            );
        }
        return $out;
    }
}
