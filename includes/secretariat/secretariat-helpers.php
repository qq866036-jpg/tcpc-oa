<?php
/**
 * AI 秘书处 · 定义与常量（TASK-1352 · 阶段一）。
 *
 * 出处：原始需求《功能需求规格说明书（AI 辅助文秘与内容生产系统）v1.0》
 *   §1 角色与硬规则 / §3 四步标准流程 + 三道人审门 / §4 三大业务场景 /
 *   §5 交付物与格式 / §6 质量校验清单（8 项）/ §7 人审节点与指令约定。
 *
 * 本文件只放**纯定义与纯函数**（无 DB 写入、无输出），供其余模块与断言共用。
 *
 * 硬规则（需求 §1/§7，一票否决）：
 *   - AI 可起草、可校验；**不得**代行发布、签发、盖章、对外披露；
 *   - 「发布 / 存档」是**人工命令**，必须由任务发布人显式下达；
 *   - 每道人审门未获确认，不得进入下一步。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_SECRETARIAT_CPT')) {
    /** 任务载体（自定义文章类型）。 */
    define('ZW_SECRETARIAT_CPT', 'zw_secretariat_task');
}

if (!defined('ZW_SECRETARIAT_AUDIT_OPTION')) {
    /** 审计轨迹 option（截断保留，与其他模块同族做法）。 */
    define('ZW_SECRETARIAT_AUDIT_OPTION', 'zw_secretariat_audit');
}

if (!defined('ZW_SECRETARIAT_AUDIT_LIMIT')) {
    /** 审计轨迹上限（超出截断最旧）。 */
    define('ZW_SECRETARIAT_AUDIT_LIMIT', 500);
}

if (!function_exists('zw_secretariat_is_enabled')) {
    /**
     * 功能开关（默认关闭；关闭时不注册菜单/页面/入口）。
     *
     * @return bool
     */
    function zw_secretariat_is_enabled() {
        if (!function_exists('get_option')) {
            return false;
        }
        $raw = get_option('zw_secretariat_mvp_enabled', 0);
        if (function_exists('apply_filters')) {
            $raw = apply_filters('zw_secretariat_mvp_enabled', $raw);
        }
        return !empty($raw);
    }
}

if (!function_exists('zw_secretariat_capability')) {
    /**
     * 能力位（复用 `tcpc_perm_doc`：秘书处文书与发文/收文同属公文办理。
     *
     * 为什么复用而不是新开：生产 `tcpc_perm_doc` 已由 tcpc_admin / tcpc_approver / tcpc_supervisor 持有；
     * 新建能力位若不授予任何角色，页面将只有 administrator 可见（既有缺陷先例）。
     *
     * @return string
     */
    function zw_secretariat_capability() {
        $cap = 'tcpc_perm_doc';
        if (function_exists('apply_filters')) {
            $cap = (string) apply_filters('zw_secretariat_capability', $cap);
        }
        return $cap;
    }
}

if (!function_exists('zw_secretariat_can')) {
    /**
     * 当前用户是否可用 AI 秘书处。
     *
     * @return bool
     */
    function zw_secretariat_can() {
        return function_exists('current_user_can') && current_user_can(zw_secretariat_capability());
    }
}

if (!function_exists('zw_secretariat_scenarios')) {
    /**
     * 三大业务场景（需求 §4）。
     *
     * @return array<string,string> key => 显示名
     */
    function zw_secretariat_scenarios() {
        $map = array(
            'council'      => '协会理事会 / 常务理事会', // §4.2（阶段一首发场景）
            'party'        => '党建党务',                // §4.1（阶段二）
            'site_content' => '官网板块文章',            // §4.3（阶段三）
        );
        if (function_exists('apply_filters')) {
            $map = (array) apply_filters('zw_secretariat_scenarios', $map);
        }
        return $map;
    }
}

if (!function_exists('zw_secretariat_scenario_label')) {
    /**
     * 场景显示名（未命中回显原值，不臆造）。
     *
     * @param string $key 场景 key。
     * @return string
     */
    function zw_secretariat_scenario_label($key) {
        $map = zw_secretariat_scenarios();
        $key = (string) $key;
        return isset($map[$key]) ? (string) $map[$key] : $key;
    }
}

if (!function_exists('zw_secretariat_cycles')) {
    /**
     * 任务周期（需求 §2「半年 / 一年 / 五年」）。
     *
     * @return array<string,string>
     */
    function zw_secretariat_cycles() {
        $map = array(
            'half_year'   => '半年',
            'year'        => '一年',
            'five_year'   => '一届（五年）',
        );
        if (function_exists('apply_filters')) {
            $map = (array) apply_filters('zw_secretariat_cycles', $map);
        }
        return $map;
    }
}

if (!function_exists('zw_secretariat_cycle_label')) {
    /**
     * 周期显示名。
     *
     * @param string $key 周期 key。
     * @return string
     */
    function zw_secretariat_cycle_label($key) {
        $map = zw_secretariat_cycles();
        $key = (string) $key;
        return isset($map[$key]) ? (string) $map[$key] : $key;
    }
}

if (!function_exists('zw_secretariat_deliverables')) {
    /**
     * 各场景交付物清单。
     *
     * - `council`：需求 §4.2 五类（阶段一实现）
     * - `party`  ：需求 §4.1 七类（阶段二，登记以免遗漏）
     * - `site_content`：需求 §4.3 七步流程产物（阶段三）
     *
     * @param string $scenario 场景 key。
     * @return array<string,string> key => 显示名
     */
    function zw_secretariat_deliverables($scenario = '') {
        $all = array(
            'council' => array(
                'minutes'         => '会议纪要',
                'host_speech'     => '主持人发言稿',
                'media_retention' => '会议影像资料留存清单',
                'resolutions'     => '年度决议清单',
                'audit_list'      => '年度审计清单',
            ),
            'party' => array(
                'study_minutes'   => '集中学习 / 讨论 / 视频学习会议纪要',
                'monthly_ledger'  => '月度学习台账',
                'reflection'      => '党员学习交流心得体会',
                'secretary_class' => '支部书记讲党课讲话稿',
                'phase_report'    => '阶段性情况汇报',
                'year_summary'    => '半年 / 年度学习总结',
                'rectify_list'    => '阶段性问题及整改清单',
            ),
            'site_content' => array(
                'section_guide'   => '板块及栏目写作要求',
                'overall_outline' => '整体写作纲要',
                'annual_outline'  => '全年栏目写作纲要',
                'column_outline'  => '具体栏目写作纲要',
                'abstract'        => '每篇文章内容提要',
                'article'         => '完整文章',
            ),
        );
        if (function_exists('apply_filters')) {
            $all = (array) apply_filters('zw_secretariat_deliverables', $all, (string) $scenario);
        }
        $scenario = (string) $scenario;
        if ($scenario === '') {
            return $all;
        }
        return isset($all[$scenario]) ? (array) $all[$scenario] : array();
    }
}

if (!function_exists('zw_secretariat_deliverable_label')) {
    /**
     * 交付物显示名（先按场景查，未命中再全局查）。
     *
     * @param string $scenario 场景 key。
     * @param string $key      交付物 key。
     * @return string
     */
    function zw_secretariat_deliverable_label($scenario, $key) {
        $scoped = zw_secretariat_deliverables($scenario);
        $key    = (string) $key;
        if (isset($scoped[$key])) {
            return (string) $scoped[$key];
        }
        foreach (zw_secretariat_deliverables() as $list) {
            if (isset($list[$key])) {
                return (string) $list[$key];
            }
        }
        return $key;
    }
}

if (!function_exists('zw_secretariat_steps')) {
    /**
     * 四步标准流程（需求 §3）。
     *
     * @return array<int,array<string,string>>
     */
    function zw_secretariat_steps() {
        return array(
            array('key' => 'read',    'label' => '步骤一 通读吃透、严格依从'),
            array('key' => 'outline', 'label' => '步骤二 编制工作纲要（排期）'),
            array('key' => 'draft',   'label' => '步骤三 撰写内容简介（素材层）'),
            array('key' => 'final',   'label' => '步骤四 正式文本 + 质量校验'),
        );
    }
}

if (!function_exists('zw_secretariat_statuses')) {
    /**
     * 任务状态机（状态 → 显示名）。
     *
     * 设计要点：**状态名与「门」一一对应**，`approved` 类状态表示"该门已获人工确认"；
     * 终态 `published` / `archived` 只能由**人工命令**进入（见 gates 模块）。
     *
     * @return array<string,string>
     */
    function zw_secretariat_statuses() {
        return array(
            'draft'             => '草稿（步骤一 通读依从）',
            'outline_review'    => '待门① 纲要确认',
            'outline_approved'  => '纲要已确认（步骤三 素材层）',
            'draft_review'      => '待门② 初稿审阅',
            'draft_approved'    => '初稿已批准（步骤四 成稿 + 质检）',
            'final_review'      => '待门③ 终稿终审',
            'published'         => '已发布',
            'archived'          => '已存档',
        );
    }
}

if (!function_exists('zw_secretariat_status_label')) {
    /**
     * 状态显示名。
     *
     * @param string $status 状态 key。
     * @return string
     */
    function zw_secretariat_status_label($status) {
        $map = zw_secretariat_statuses();
        $status = (string) $status;
        return isset($map[$status]) ? (string) $map[$status] : $status;
    }
}

if (!function_exists('zw_secretariat_normalize_status')) {
    /**
     * 状态归一：非法值回落到 `draft`（不臆造）。
     *
     * @param mixed $status 状态。
     * @return string
     */
    function zw_secretariat_normalize_status($status) {
        $status = sanitize_key((string) $status);
        return array_key_exists($status, zw_secretariat_statuses()) ? $status : 'draft';
    }
}

if (!function_exists('zw_secretariat_scenario_key')) {
    /**
     * 场景 key 归一（非法回落空串，由调用方报错）。
     *
     * @param mixed $key 场景。
     * @return string
     */
    function zw_secretariat_scenario_key($key) {
        $key = sanitize_key((string) $key);
        return array_key_exists($key, zw_secretariat_scenarios()) ? $key : '';
    }
}

if (!function_exists('zw_secretariat_cycle_key')) {
    /**
     * 周期 key 归一（非法回落空串）。
     *
     * @param mixed $key 周期。
     * @return string
     */
    function zw_secretariat_cycle_key($key) {
        $key = sanitize_key((string) $key);
        return array_key_exists($key, zw_secretariat_cycles()) ? $key : '';
    }
}

if (!function_exists('zw_secretariat_quality_items')) {
    /**
     * 质量校验清单 **8 项**（需求 §6，步骤四强制逐项执行）。
     *
     * key 为稳定英文标识（入库口径），label 为中文显示名。
     *
     * @return array<string,string>
     */
    function zw_secretariat_quality_items() {
        return array(
            'duplicate'     => '查重（文本相似度、引用来源标注）',
            'infringement'  => '侵权检查（图片 / 他人成果 / 引用授权）',
            'wording'       => '用语规范（公文 / 文章语体、禁用错用表述）',
            'ministry'      => '部委合规（民政部 / 中社部 / 文旅部要求）',
            'ideology'      => '意识形态（风险表述）',
            'triple_one'    => '三重一大（决策流程）',
            'accuracy'      => '事实准确（日期 / 地点 / 人名 / 会议名称）',
            'typography'    => '排版与错字（错别字 / 标点 / 序号）',
        );
    }
}

if (!function_exists('zw_secretariat_audit')) {
    /**
     * 审计留痕（append-only；超出上限截断最旧）。
     *
     * @param string $event  事件 key。
     * @param array  $detail 细节（**不得放入正文全文**；只放标识与结论摘要）。
     * @return void
     */
    function zw_secretariat_audit($event, $detail = array()) {
        static $depth = 0;
        if ($depth > 0 || !function_exists('get_option') || !function_exists('update_option')) {
            return;
        }
        $depth++;
        $log = get_option(ZW_SECRETARIAT_AUDIT_OPTION, array());
        if (!is_array($log)) {
            $log = array();
        }
        $log[] = array(
            'at'     => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            'event'  => (string) $event,
            'actor'  => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
            'detail' => is_array($detail) ? $detail : array(),
        );
        if (count($log) > ZW_SECRETARIAT_AUDIT_LIMIT) {
            $log = array_slice($log, -ZW_SECRETARIAT_AUDIT_LIMIT);
        }
        update_option(ZW_SECRETARIAT_AUDIT_OPTION, $log, false);
        $depth--;
    }
}
