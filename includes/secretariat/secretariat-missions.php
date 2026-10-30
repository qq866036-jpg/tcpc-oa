<?php
/**
 * AI 秘书处 · 任务模型（TASK-1352 · 阶段一）。
 *
 * 需求出处：§1 角色与硬规则 / §3 四步流程 + 三道人审门 / §4.2 理事会场景 / §5 交付物与格式。
 *
 * 存储：CPT `zw_secretariat_task`（private，不公开查询）+ post meta（下划线前缀）。
 * **不含第二套政策库**：来源材料只引用既有收文/政策源 id 或附件 id，不复制正文。
 *
 * 状态机（与 gates 模块一一对应）：
 *   draft ──提交纲要──▶ outline_review ──门①确认──▶ outline_approved
 *     └─▶ draft_review ──门②批准──▶ draft_approved
 *          └─▶ final_review ──门③│发布→published ／ 存档→archived
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ─────────────────────────────────────────────── CPT 注册 */
if (!function_exists('zw_secretariat_register_post_type')) {
    /**
     * 注册任务载体 CPT（private；与发文 `zw_official_doc` 同族范式）。
     *
     * @return void
     */
    function zw_secretariat_register_post_type() {
        if (!function_exists('register_post_type')) {
            return;
        }
        register_post_type(ZW_SECRETARIAT_CPT, array(
            'labels'              => array(
                'name'          => '秘书处任务',
                'singular_name' => '秘书处任务',
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

/* ─────────────────────────────────────────────── meta 口径（唯一出处） */
if (!function_exists('zw_secretariat_meta_keys')) {
    /**
     * 任务 meta 键清单（**单值**）。
     *
     * @return array<string,string> 键 => 默认值
     */
    function zw_secretariat_meta_keys() {
        return array(
            '_zw_sec_scenario'      => '',   // 场景 key
            '_zw_sec_cycle'         => '',   // 周期 key
            '_zw_sec_status'        => 'draft',
            '_zw_sec_publisher_id'  => 0,    // 任务发布人（唯一有发布/签发权）
            '_zw_sec_owner_id'      => 0,    // 经办人（起草）
            '_zw_sec_outline'       => '',   // 步骤二：工作纲要（纯文本）
            '_zw_sec_draft_text'    => '',   // 步骤三：初稿素材（纯文本）
            '_zw_sec_final_text'    => '',   // 步骤四：正式文本（纯文本）
            '_zw_sec_read_note'     => '',   // 步骤一：合规约束清单 + 冲突/缺失提示
            '_zw_sec_publish_command' => '', // '' | publish | archive（人工命令）
            '_zw_sec_published_at'  => '',
            '_zw_sec_linked_doc_id' => 0,    // 关联发文记录（终稿→发文草稿）
            '_zw_sec_updated_at'    => '',
        );
    }
}

if (!function_exists('zw_secretariat_meta_list_keys')) {
    /**
     * 任务 meta 键清单（**列表**：每项 key => 每元素默认值）。
     *
     * @return array<string,string>
     */
    function zw_secretariat_meta_list_keys() {
        return array(
            '_zw_sec_source_ids'      => '', // 来源材料：收文/政策源 id 或附件 id
            '_zw_sec_deliverables'    => '', // 交付物 key 列表
            '_zw_sec_milestones'      => '', // 里程碑（纲要要点）
            '_zw_sec_media_retention' => '', // 影像资料留存清单（需求 §4.2）
        );
    }
}

if (!function_exists('zw_secretariat_get_meta')) {
    /**
     * 读取单值 meta（带默认值，缺 meta 不产生 null 噪声）。
     *
     * @param int    $task_id 任务 ID。
     * @param string $key     meta 键。
     * @return mixed
     */
    function zw_secretariat_get_meta($task_id, $key) {
        $keys = zw_secretariat_meta_keys();
        $key  = (string) $key;
        $default = array_key_exists($key, $keys) ? $keys[$key] : '';
        if (!function_exists('get_post_meta')) {
            return $default;
        }
        $value = get_post_meta((int) $task_id, $key, true);
        return ($value === '' || $value === null) ? $default : $value;
    }
}

if (!function_exists('zw_secretariat_get_list_meta')) {
    /**
     * 读取列表 meta（恒返回数组）。
     *
     * @param int    $task_id 任务 ID。
     * @param string $key     meta 键。
     * @return array
     */
    function zw_secretariat_get_list_meta($task_id, $key) {
        if (!function_exists('get_post_meta')) {
            return array();
        }
        $value = get_post_meta((int) $task_id, (string) $key, true);
        return is_array($value) ? array_values($value) : array();
    }
}

if (!function_exists('zw_secretariat_status_of')) {
    /**
     * 取任务状态（归一）。
     *
     * @param int $task_id 任务 ID。
     * @return string
     */
    function zw_secretariat_status_of($task_id) {
        if (!function_exists('get_post_meta')) {
            return 'draft';
        }
        $raw = get_post_meta((int) $task_id, '_zw_sec_status', true);
        if ($raw === '' || $raw === null) {
            return 'draft';
        }
        return zw_secretariat_normalize_status($raw);
    }
}

/* ─────────────────────────────────────────────── 创建 / 更新 */
if (!function_exists('zw_secretariat_sanitize_text')) {
    /**
     * 纯文本归一（去标签、限制长度、统一换行）。
     *
     * @param mixed $text  原始文本。
     * @param int   $limit 长度上限（字符）。
     * @return string
     */
    function zw_secretariat_sanitize_text($text, $limit = 200000) {
        $text = (string) $text;
        if (function_exists('wp_strip_all_tags')) {
            $text = wp_strip_all_tags($text);
        } else {
            $text = strip_tags($text);
        }
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = trim($text);
        $len  = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($len > (int) $limit) {
            $text = function_exists('mb_substr') ? mb_substr($text, 0, (int) $limit, 'UTF-8') : substr($text, 0, (int) $limit);
        }
        return $text;
    }
}

if (!function_exists('zw_secretariat_create_task')) {
    /**
     * 创建任务（唯一创建入口）。
     *
     * @param array $data 任务数据（title/scenario/cycle/publisher_id/owner_id/source_ids/deliverables）。
     * @return int|WP_Error 任务 ID 或错误。
     */
    function zw_secretariat_create_task($data) {
        $data = is_array($data) ? $data : array();

        $title    = zw_secretariat_sanitize_text(isset($data['title']) ? $data['title'] : '', 200);
        $scenario = zw_secretariat_scenario_key(isset($data['scenario']) ? $data['scenario'] : '');
        $cycle    = zw_secretariat_cycle_key(isset($data['cycle']) ? $data['cycle'] : '');

        if ($title === '') {
            return new WP_Error('zw_sec_bad_title', '任务标题不能为空。');
        }
        if ($scenario === '') {
            return new WP_Error('zw_sec_bad_scenario', '业务场景不合法（应为 理事会 / 党建党务 / 官网板块文章 之一）。');
        }
        if ($cycle === '') {
            return new WP_Error('zw_sec_bad_cycle', '任务周期不合法（应为 半年 / 一年 / 一届 之一）。');
        }
        if (!function_exists('wp_insert_post')) {
            return new WP_Error('zw_sec_no_db', 'WordPress 写入接口不可用，任务未创建。');
        }

        $publisher_id = isset($data['publisher_id']) ? (int) $data['publisher_id'] : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        $owner_id     = isset($data['owner_id']) ? (int) $data['owner_id'] : $publisher_id;

        // 交付物：只接受该场景清单内的 key（不臆造）。
        $allowed      = zw_secretariat_deliverables($scenario);
        $deliverables = array();
        foreach ((array) (isset($data['deliverables']) ? $data['deliverables'] : array()) as $key) {
            $key = sanitize_key((string) $key);
            if ($key !== '' && array_key_exists($key, $allowed) && !in_array($key, $deliverables, true)) {
                $deliverables[] = $key;
            }
        }

        $source_ids = array();
        foreach ((array) (isset($data['source_ids']) ? $data['source_ids'] : array()) as $sid) {
            $sid = sanitize_text_field((string) $sid);
            if ($sid !== '' && !in_array($sid, $source_ids, true)) {
                $source_ids[] = $sid;
            }
        }

        $task_id = wp_insert_post(array(
            'post_type'   => ZW_SECRETARIAT_CPT,
            'post_status' => 'private',
            'post_title'  => $title,
            'post_author' => $publisher_id,
        ), true);
        if (function_exists('is_wp_error') && is_wp_error($task_id)) {
            return $task_id;
        }
        $task_id = (int) $task_id;
        if ($task_id <= 0) {
            return new WP_Error('zw_sec_insert_failed', '任务创建失败。');
        }

        $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        update_post_meta($task_id, '_zw_sec_scenario', $scenario);
        update_post_meta($task_id, '_zw_sec_cycle', $cycle);
        update_post_meta($task_id, '_zw_sec_status', 'draft');
        update_post_meta($task_id, '_zw_sec_publisher_id', $publisher_id);
        update_post_meta($task_id, '_zw_sec_owner_id', $owner_id);
        update_post_meta($task_id, '_zw_sec_updated_at', $now);
        if ($deliverables) {
            update_post_meta($task_id, '_zw_sec_deliverables', $deliverables);
        }
        if ($source_ids) {
            update_post_meta($task_id, '_zw_sec_source_ids', $source_ids);
        }

        zw_secretariat_audit('task_create', array(
            'task'     => $task_id,
            'scenario' => $scenario,
            'cycle'    => $cycle,
            'items'    => count($deliverables),
            'sources'  => count($source_ids),
        ));

        return $task_id;
    }
}

if (!function_exists('zw_secretariat_update_text')) {
    /**
     * 写入某步骤的文本（步骤一 / 二 / 三 / 四）。
     *
     * 硬规则：**已发布/已存档任务不得再改文本**（留痕完整性）。
     *
     * @param int    $task_id 任务 ID。
     * @param string $field   read|outline|draft|final。
     * @param string $text    文本。
     * @return true|WP_Error
     */
    function zw_secretariat_update_text($task_id, $field, $text) {
        $map = array(
            'read'    => '_zw_sec_read_note',
            'outline' => '_zw_sec_outline',
            'draft'   => '_zw_sec_draft_text',
            'final'   => '_zw_sec_final_text',
        );
        $field = sanitize_key((string) $field);
        if (!isset($map[$field])) {
            return new WP_Error('zw_sec_bad_field', '未知文本字段。');
        }
        $task_id = (int) $task_id;
        if ($task_id <= 0) {
            return new WP_Error('zw_sec_bad_task', '任务不存在。');
        }
        $status = zw_secretariat_status_of($task_id);
        if (in_array($status, array('published', 'archived'), true)) {
            return new WP_Error('zw_sec_locked', '任务已' . zw_secretariat_status_label($status) . '，文本已锁定，不可再修改。');
        }
        if (!function_exists('update_post_meta')) {
            return new WP_Error('zw_sec_no_db', 'WordPress 写入接口不可用。');
        }
        update_post_meta($task_id, $map[$field], zw_secretariat_sanitize_text($text));
        update_post_meta($task_id, '_zw_sec_updated_at', function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'));

        zw_secretariat_audit('task_text_update', array('task' => $task_id, 'field' => $field));
        return true;
    }
}

/* ─────────────────────────────────────────────── 状态迁移（受门约束） */
if (!function_exists('zw_secretariat_allowed_transitions')) {
    /**
     * 允许的状态迁移表（**唯一出处**；AI 与人都只能沿此表迁移）。
     *
     * 注意：**门内状态 → 已确认状态的迁移不在此表**，只能由 gates 模块的确认动作触发
     * （`zw_secretariat_gate_confirm()`），以保证"未获确认不得进入下一步"。
     *
     * @return array<string,string[]>
     */
    function zw_secretariat_allowed_transitions() {
        return array(
            // 步骤一 → 提交纲要（进入门①）
            'draft'            => array('outline_review'),
            // 门①确认 → outline_approved（由 gates 模块在人工确认后调用 transition，见函数头说明）
            'outline_review'   => array('outline_approved', 'draft'),   // 后者＝撤回重编
            // 已确认纲要 → 进入门②
            'outline_approved' => array('draft_review'),
            // 门②批准 → draft_approved
            'draft_review'     => array('draft_approved', 'outline_approved'), // 后者＝退回重编
            // 已批准初稿 → 进入门③
            'draft_approved'   => array('final_review'),
            // 门③终审 → 终态（published/archived 只能在人工命令通过判据后由 gates 模块写入）
            'final_review'     => array('published', 'archived', 'draft_approved'), // 后者＝退回成稿
            'published'        => array(),
            'archived'         => array(),
        );
    }
}

if (!function_exists('zw_secretariat_can_transition')) {
    /**
     * 迁移是否被允许。
     *
     * @param string $from 当前状态。
     * @param string $to   目标状态。
     * @return bool
     */
    function zw_secretariat_can_transition($from, $to) {
        $map  = zw_secretariat_allowed_transitions();
        $from = zw_secretariat_normalize_status($from);
        $to   = zw_secretariat_normalize_status($to);
        return isset($map[$from]) && in_array($to, $map[$from], true);
    }
}

if (!function_exists('zw_secretariat_transition')) {
    /**
     * 执行状态迁移（**唯一写状态入口**；只走允许迁移表）。
     *
     * @param int    $task_id 任务 ID。
     * @param string $to      目标状态。
     * @param array  $context 上下文（note 等）。
     * @return true|WP_Error
     */
    function zw_secretariat_transition($task_id, $to, $context = array()) {
        $task_id = (int) $task_id;
        $to      = zw_secretariat_normalize_status($to);
        if (!function_exists('get_post_meta') || !function_exists('update_post_meta')) {
            return new WP_Error('zw_sec_no_db', 'WordPress 接口不可用。');
        }
        $from = zw_secretariat_status_of($task_id);
        if ($from === $to) {
            return new WP_Error('zw_sec_same_status', '状态未变化。');
        }
        if (!zw_secretariat_can_transition($from, $to)) {
            return new WP_Error(
                'zw_sec_transition_blocked',
                '不允许的状态迁移：' . zw_secretariat_status_label($from) . ' → ' . zw_secretariat_status_label($to)
                . '（人审门未获确认时不得进入下一步）。'
            );
        }
        update_post_meta($task_id, '_zw_sec_status', $to);
        update_post_meta($task_id, '_zw_sec_updated_at', function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'));

        zw_secretariat_audit('task_transition', array(
            'task' => $task_id,
            'from' => $from,
            'to'   => $to,
            'note' => isset($context['note']) ? zw_secretariat_sanitize_text($context['note'], 500) : '',
        ));
        return true;
    }
}

/* ─────────────────────────────────────────────── 发布闸门判据 */
if (!function_exists('zw_secretariat_publish_blockers')) {
    /**
     * **终稿发布/存档的阻断原因清单**（需求 §7：终审下达命令的前提）。
     *
     * 返回空数组 = 可发布；非空 = 逐条阻断。判据（全部必须有据）：
     *   1. 状态必须是 `final_review`（已走到门③）；
     *   2. 正式文本非空（步骤四产物）；
     *   3. 质量校验 **8 项全部通过**（qa 模块）；
     *   4. **三道人审门全部具名确认**（gates 模块）；
     *   5. 有人工命令位（`publish` / `archive`）——由调用方在确认后写入。
     *
     * @param int $task_id 任务 ID。
     * @return string[] 阻断原因（中文，可直接展示）。
     */
    function zw_secretariat_publish_blockers($task_id) {
        $task_id = (int) $task_id;
        $block   = array();

        $status = zw_secretariat_status_of($task_id);
        if ($status !== 'final_review') {
            $block[] = '任务尚未到达「门③ 终稿终审」（当前：' . zw_secretariat_status_label($status) . '）。';
        }
        if (trim((string) zw_secretariat_get_meta($task_id, '_zw_sec_final_text')) === '') {
            $block[] = '正式文本（步骤四产物）为空。';
        }

        if (function_exists('zw_secretariat_qa_blockers')) {
            foreach (zw_secretariat_qa_blockers($task_id) as $reason) {
                $block[] = $reason;
            }
        }
        if (function_exists('zw_secretariat_gate_blockers')) {
            foreach (zw_secretariat_gate_blockers($task_id) as $reason) {
                $block[] = $reason;
            }
        }
        return $block;
    }
}

if (!function_exists('zw_secretariat_is_publishable')) {
    /**
     * 是否满足发布/存档条件。
     *
     * @param int $task_id 任务 ID。
     * @return bool
     */
    function zw_secretariat_is_publishable($task_id) {
        return zw_secretariat_publish_blockers($task_id) === array();
    }
}
