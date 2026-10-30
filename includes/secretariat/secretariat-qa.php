<?php
/**
 * AI 秘书处 · 质量校验报告（TASK-1352 · 阶段一）。
 *
 * 需求出处：§6「执行人成稿后必须逐条核对，在《质量校验报告》中勾选并附说明；任一项存疑即退回修订」。
 *
 * 设计要点：
 *   - **8 项为一个整体报告实体**（不塞进法务 `issues[]`）：每项记录 结论 pass|fail|pending、
 *     校验人（具名）、时间、说明；gate③ 要求 8 项全部 `pass`；
 *   - **缺项即阻断**：未出现的项按 `pending` 计（不默认通过）；
 *   - 报告随终稿归档（meta 持久化，不进正文）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_secretariat_qa_states')) {
    /**
     * 单项结论取值。
     *
     * @return array<string,string>
     */
    function zw_secretariat_qa_states() {
        return array(
            'pending' => '待校验',
            'pass'    => '通过',
            'fail'    => '不通过（须修订）',
        );
    }
}

if (!function_exists('zw_secretariat_qa_normalize_state')) {
    /**
     * 结论归一（非法回落 pending；**绝不默认通过**）。
     *
     * @param mixed $state 结论。
     * @return string
     */
    function zw_secretariat_qa_normalize_state($state) {
        $state = sanitize_key((string) $state);
        return array_key_exists($state, zw_secretariat_qa_states()) ? $state : 'pending';
    }
}

if (!function_exists('zw_secretariat_qa_report')) {
    /**
     * 读取质检报告（恒返回 8 项完整结构，缺失项为 pending）。
     *
     * @param int $task_id 任务 ID。
     * @return array<string,array<string,mixed>>
     */
    function zw_secretariat_qa_report($task_id) {
        $stored = function_exists('get_post_meta') ? get_post_meta((int) $task_id, '_zw_sec_qa', true) : array();
        $stored = is_array($stored) ? $stored : array();
        $out    = array();
        foreach (zw_secretariat_quality_items() as $key => $label) {
            $row = isset($stored[$key]) && is_array($stored[$key]) ? $stored[$key] : array();
            $out[$key] = array(
                'label'  => $label,
                'state'  => zw_secretariat_qa_normalize_state(isset($row['state']) ? $row['state'] : 'pending'),
                'by'     => isset($row['by']) ? (int) $row['by'] : 0,
                'by_name'=> isset($row['by_name']) ? (string) $row['by_name'] : '',
                'at'     => isset($row['at']) ? (string) $row['at'] : '',
                'note'   => isset($row['note']) ? (string) $row['note'] : '',
            );
        }
        return $out;
    }
}

if (!function_exists('zw_secretariat_qa_set_item')) {
    /**
     * **写入单项结论（唯一入口）**。
     *
     * 具名要求：`pass` / `fail` 必须能具名（id 或姓名至少其一）。
     *
     * @param int    $task_id 任务 ID。
     * @param string $key     校验项 key。
     * @param string $state   pass|fail|pending。
     * @param array  $args    by / by_name / note。
     * @return true|WP_Error
     */
    function zw_secretariat_qa_set_item($task_id, $key, $state, $args = array()) {
        $task_id = (int) $task_id;
        $key     = sanitize_key((string) $key);
        $items   = zw_secretariat_quality_items();
        if (!isset($items[$key])) {
            return new WP_Error('zw_sec_qa_bad_item', '未知质量校验项。');
        }
        $args  = is_array($args) ? $args : array();
        $state = zw_secretariat_qa_normalize_state($state);

        if ($state !== 'pending') {
            $by      = isset($args['by']) ? (int) $args['by'] : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
            $by_name = isset($args['by_name']) ? zw_secretariat_sanitize_text($args['by_name'], 100) : '';
            if ($by_name === '' && $by > 0 && function_exists('get_userdata')) {
                $user = get_userdata($by);
                if ($user && isset($user->display_name)) {
                    $by_name = (string) $user->display_name;
                }
            }
            if ($by <= 0 && $by_name === '') {
                return new WP_Error('zw_sec_qa_no_checker', '质量校验结论必须具名（校验人缺失）。');
            }
        } else {
            $by      = 0;
            $by_name = '';
        }

        $report          = zw_secretariat_qa_report($task_id);
        $report[$key]    = array(
            'state'   => $state,
            'by'      => isset($by) ? (int) $by : 0,
            'by_name' => isset($by_name) ? (string) $by_name : '',
            'at'      => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            'note'    => isset($args['note']) ? zw_secretariat_sanitize_text($args['note'], 1000) : '',
        );
        update_post_meta($task_id, '_zw_sec_qa', $report);

        zw_secretariat_audit('qa_set', array('task' => $task_id, 'item' => $key, 'state' => $state));
        return true;
    }
}

if (!function_exists('zw_secretariat_qa_blockers')) {
    /**
     * **质检阻断原因**（供门③与发布闸门）：
     *   任一项非 `pass` 即阻断，逐项给出可读原因。
     *
     * @param int $task_id 任务 ID。
     * @return string[]
     */
    function zw_secretariat_qa_blockers($task_id) {
        $report = zw_secretariat_qa_report($task_id);
        $block  = array();
        foreach ($report as $key => $row) {
            if ($row['state'] === 'pass') {
                continue;
            }
            if ($row['state'] === 'fail') {
                $block[] = '质量校验未通过：' . $row['label'] . '（须修订后重新校验）。';
            } else {
                $block[] = '质量校验待完成：' . $row['label'] . '。';
            }
        }
        return $block;
    }
}

if (!function_exists('zw_secretariat_qa_is_pass')) {
    /**
     * 8 项是否全部通过。
     *
     * @param int $task_id 任务 ID。
     * @return bool
     */
    function zw_secretariat_qa_is_pass($task_id) {
        return zw_secretariat_qa_blockers($task_id) === array();
    }
}

if (!function_exists('zw_secretariat_qa_summary')) {
    /**
     * 报告摘要（展示与归档用；含逐项结论与具名校验人）。
     *
     * @param int $task_id 任务 ID。
     * @return array{total:int,pass:int,fail:int,pending:int,items:array<int,array<string,string>>}
     */
    function zw_secretariat_qa_summary($task_id) {
        $report = zw_secretariat_qa_report($task_id);
        $out    = array('total' => 0, 'pass' => 0, 'fail' => 0, 'pending' => 0, 'items' => array());
        $states = zw_secretariat_qa_states();
        foreach ($report as $key => $row) {
            $out['total']++;
            if (isset($out[$row['state']])) {
                $out[$row['state']]++;
            }
            $out['items'][] = array(
                'key'    => $key,
                'label'  => $row['label'],
                'state'  => $row['state'],
                'status' => isset($states[$row['state']]) ? $states[$row['state']] : $row['state'],
                'by'     => $row['by_name'] !== '' ? $row['by_name'] : (string) $row['by'],
                'at'     => $row['at'],
                'note'   => $row['note'],
            );
        }
        return $out;
    }
}
