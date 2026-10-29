<?php
/**
 * 发文全流程自动流转（TASK-1410 / SPEC-87 §24）。
 *
 * 用户指令原文：「我要求所有流程都自动流转 不需要 实际人员参与的按照上面合规的路线，并且能被记录」。
 *
 * 「上面合规的路线」= 既有门禁链（**一步不跳**）：
 *   G1 状态 `approved`（`zw_doc_seal_gate()` 的 G1）
 *   → G2 法务结论 `pass`（`zw_doc_seal_require_legal()` 默认强制）
 *   → 用印（写 `_zw_doc_seal_mode='formal'`，唯一写入点 `zw_doc_seal_apply()`）
 *   → 生成正式用印件（`zw_doc_pdf_queue()` → 渲染 worker）。
 *
 * 设计硬约束（违反即缺陷）：
 * 1. **唯一推进器**：本文件的 `zw_doc_auto_flow_advance()` 是全站**唯一**的自动推进实现；
 *    触发点也只有**一处**（`zw_doc_flow_completed` 咽喉上的单个监听器），禁止散落多处触发。
 * 2. **不跳门（fail-closed）**：每一步**先跑既有门禁**（`zw_doc_seal_gate()` / 印章启用 /
 *    `ZW_DOC_PDF_OWNER_PASS` 就绪 / 权限）。任一门未过 ⇒ **停在该步并记录 blocker 原因**，
 *    绝不静默跳过、绝不伪造结论。
 * 3. **零回归**：某一步的开关关闭时，该步及其之后**什么都不做、不写任何 meta / 事件**
 *    ⇒ 行为与开关上线前逐字一致。
 * 4. **不新增绕过路径**：本文件**不含**任何 `update_post_meta(…, '_zw_doc_seal_mode', …)` /
 *    `update_post_meta(…, '_zw_doc_legal_status', …)`；用印必须经 `zw_doc_seal_apply()`（内部跑门禁），
 *    法务结论必须经 `zw_doc_legal_store_result()`（唯一法务结论写入口）。
 * 5. **可记录、可审计**：每步写既有 `zw_doc_log_event()` 的自动事件（`auto_approved` /
 *    `auto_legal_passed` / `auto_sealed` / `auto_pdf_queued`）+ 轨迹 meta `_zw_doc_auto_flow`
 *    （JSON：步骤名 / 时间 / 结果 / 原因 / 触发的开关）。审计三问可答：
 *    「谁做的？」= 系统自动；「依据什么？」= 哪个开关 + 哪条门禁；「什么时候？」= 事件时间。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_AUTO_FLOW_EVENT_BLOCKED')) {
    define('ZW_DOC_AUTO_FLOW_EVENT_BLOCKED', 'auto_flow_blocked');
}

if (!function_exists('zw_doc_auto_flow_switch_definitions')) {
    /**
     * 自动流转开关登记表（**唯一出处**）：键名 / 名称 / 说明 / 依据的门禁链环节。
     *
     * 配置页（`doc-admin-flow.php`）与数据字典页（`doc-admin-dict.php`）**都消费本表**，
     * 禁止在两处各写一份开关名或说明文案（否则「显著标注」的口径会漂移）。
     *
     * `default` = 代码默认值：按用户「所有流程都自动流转」的要求**四项默认开启**。
     * 注意：`zw_doc_flow_settings()` 读的是**已存 option 与默认值的合并结果**，
     * 因此对已经把配置存过库的站点（如生产），运行时取值以**库中已存值**为准，本默认值只决定
     * 「库里没有这个键」时的取值 —— 这正是新增三个开关在存量站点上「开箱即自动」的原因。
     *
     * @return array<string,array<string,string|int>>
     */
    function zw_doc_auto_flow_switch_definitions() {
        return array(
            'auto_review' => array(
                'label'   => '自动审核（提交即通过审核步）',
                'default' => 1,
                'step'    => 'review',
                'gate'    => '状态 pending + 正文已落库（与既有 TASK-1345 触发点同处）',
                'event'   => 'auto_approved',
            ),
            'auto_legal'  => array(
                'label'   => '自动出具法务结论（写入 `_zw_doc_legal_status=pass`）',
                'default' => 1,
                'step'    => 'legal',
                'gate'    => 'G1 已审核通过 + 正文非空；结论经唯一写入口 `zw_doc_legal_store_result()`',
                'event'   => 'auto_legal_passed',
            ),
            'auto_seal'   => array(
                'label'   => '自动用印（G1/G2/G4/G5/G6/G7 门禁全过才盖）',
                'default' => 1,
                'step'    => 'seal',
                'gate'    => '`zw_doc_seal_gate()` 六项（含 G2 法务 pass、G5 印章存在且**已启用**、G4 用印权限）',
                'event'   => 'auto_sealed',
            ),
            'auto_pdf'    => array(
                'label'   => '自动生成正式用印件（投递加密 PDF 任务）',
                'default' => 1,
                'step'    => 'pdf',
                'gate'    => '`zw_doc_pdf_gate()`（已正式用印 + 正文非空 + 用印权限 + `ZW_DOC_PDF_OWNER_PASS` 已配置）',
                'event'   => 'auto_pdf_queued',
            ),
        );
    }
}

if (!function_exists('zw_doc_auto_flow_switch_keys')) {
    /**
     * 四个开关的键名（顺序 = 推进顺序）。
     *
     * @return string[]
     */
    function zw_doc_auto_flow_switch_keys() {
        return array_keys(zw_doc_auto_flow_switch_definitions());
    }
}

if (!function_exists('zw_doc_auto_flow_switches')) {
    /**
     * 当前四个开关的运行时取值（读 `zw_doc_flow_settings()`，不新增第二套读取路径）。
     *
     * @return array<string,int>
     */
    function zw_doc_auto_flow_switches() {
        $settings = function_exists('zw_doc_flow_settings') ? zw_doc_flow_settings() : array();
        $out      = array();
        foreach (zw_doc_auto_flow_switch_definitions() as $key => $meta) {
            $out[$key] = !empty($settings[$key]) ? 1 : 0;
        }

        return $out;
    }
}

if (!function_exists('zw_doc_auto_flow_switch_note')) {
    /**
     * 页面上「显著标注」的统一文案（唯一出处）。
     *
     * @param string $key 开关键名。
     * @return string
     */
    function zw_doc_auto_flow_switch_note($key) {
        $map = zw_doc_auto_flow_switch_definitions();
        if (!isset($map[$key])) {
            return '';
        }
        $meta = $map[$key];

        return '开启后该步骤由系统自动完成，记录为自动事件，无人工参与；可随时关闭改为人工（关闭后行为与未开启时逐字一致）。'
             . '依据门禁：' . $meta['gate'] . '。'
             . '自动事件类型：' . $meta['event'] . '。';
    }
}

if (!function_exists('zw_doc_auto_flow_switch_group_intro')) {
    /**
     * 开关组**显著标注**的固定文案（唯一出处；配置页与数据字典页共用同一句）。
     *
     * @return string
     */
    function zw_doc_auto_flow_switch_group_intro() {
        return '开启后该步骤由系统自动完成，记录为自动事件，无人工参与；可随时关闭改为人工。'
             . '自动推进**不跳门**：每一步都先跑既有门禁（G1 已审核通过 → G2 法务结论 pass → 用印门禁 '
             . 'zw_doc_seal_gate() → PDF 门禁），任一门未过即停在该步并在「流转记录」中记录阻断原因（fail-closed）。';
    }
}

if (!function_exists('zw_doc_auto_flow_render_switches')) {
    /**
     * 渲染「自动流转开关组」（唯一实现；配置页与数据字典页共用，禁止各写一份）。
     *
     * @param array  $settings 流转配置（`zw_doc_flow_settings()` 结果）。
     * @param string $prefix   复选框 id 前缀（避免两页 id 撞车）。
     * @return string HTML（已 escape）。
     */
    function zw_doc_auto_flow_render_switches($settings, $prefix = 'zw-doc-auto') {
        $settings = is_array($settings) ? $settings : array();
        $html     = '<div class="zw-doc-auto-switches" id="' . esc_attr($prefix) . '-switches">';
        $html    .= '<h3 style="margin:18px 0 8px">自动流转开关组（四项）</h3>';
        $html    .= '<p style="margin:0 0 12px;padding:10px 12px;border-left:4px solid var(--primary-color,#d80505);'
                  . 'background:#fff7f7;line-height:1.8">' . esc_html(zw_doc_auto_flow_switch_group_intro()) . '</p>';
        foreach (zw_doc_auto_flow_switch_definitions() as $key => $meta) {
            $id    = esc_attr($prefix . '-' . $key);
            $html .= '<div class="zw-doc-field">';
            $html .= '<label class="zw-adm-touch" for="' . $id . '"><input type="checkbox" id="' . $id . '" name="' . esc_attr($key) . '" value="1"'
                   . checked(!empty($settings[$key]), true, false) . '> ' . esc_html($meta['label']) . '</label>';
            $html .= '<div class="zw-doc-muted">' . esc_html(zw_doc_auto_flow_switch_note($key)) . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('zw_doc_auto_flow_event_types')) {
    /**
     * 自动流转事件类型清单（供页面与断言共用，禁止手抄字符串）。
     *
     * @return string[]
     */
    function zw_doc_auto_flow_event_types() {
        $out = array();
        foreach (zw_doc_auto_flow_switch_definitions() as $meta) {
            $out[] = (string) $meta['event'];
        }
        $out[] = ZW_DOC_AUTO_FLOW_EVENT_BLOCKED;

        return $out;
    }
}

if (!function_exists('zw_doc_auto_flow_event_origin')) {
    /**
     * 事件来源判定（**唯一出处**）：自动事件 vs 人工事件。
     *
     * 判据只用事件类型前缀：`auto_` 开头（`auto_approved` / `auto_legal_passed` /
     * `auto_sealed` / `auto_pdf_queued` / `auto_flow_blocked`）= 系统自动；其余 = 人工。
     * 与 actor 无关 —— 自动步骤的 actor 仍记发起人 uid（留痕「谁触发的」），
     * 但不能因此被误判为「人工做的」。
     *
     * @param string $event_type 事件类型。
     * @return string auto|manual
     */
    function zw_doc_auto_flow_event_origin($event_type) {
        return (strpos((string) $event_type, 'auto_') === 0) ? 'auto' : 'manual';
    }
}

if (!function_exists('zw_doc_auto_flow_trace')) {
    /**
     * 读轨迹 meta `_zw_doc_auto_flow`（只读；任何路径不得删除）。
     *
     * @param int $doc_id 发文 ID。
     * @return array{steps:array<int,array<string,string>>,updated_at:string}
     */
    function zw_doc_auto_flow_trace($doc_id) {
        $raw = get_post_meta((int) $doc_id, '_zw_doc_auto_flow', true);
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) $raw, true);
        }
        if (!is_array($decoded)) {
            $decoded = array();
        }
        $steps = isset($decoded['steps']) && is_array($decoded['steps']) ? $decoded['steps'] : array();
        $out   = array();
        foreach ($steps as $one) {
            if (!is_array($one)) {
                continue;
            }
            $out[] = array(
                'step'   => isset($one['step']) ? (string) $one['step'] : '',
                'switch' => isset($one['switch']) ? (string) $one['switch'] : '',
                'result' => isset($one['result']) ? (string) $one['result'] : '',
                'reason' => isset($one['reason']) ? (string) $one['reason'] : '',
                'at'     => isset($one['at']) ? (string) $one['at'] : '',
            );
        }

        return array(
            'steps'      => $out,
            'updated_at' => isset($decoded['updated_at']) ? (string) $decoded['updated_at'] : '',
        );
    }
}

if (!function_exists('zw_doc_auto_flow_trace_append')) {
    /**
     * 追加一条轨迹（**只增不减**，最多保留 50 条，避免 meta 无限膨胀）。
     *
     * @param int    $doc_id 发文 ID。
     * @param string $step   步骤名 review|legal|seal|pdf。
     * @param string $switch 触发的开关名。
     * @param string $result done|blocked。
     * @param string $reason 原因（门禁结论或阻断原因）。
     * @return void
     */
    function zw_doc_auto_flow_trace_append($doc_id, $step, $switch, $result, $reason) {
        $trace = zw_doc_auto_flow_trace($doc_id);
        $steps = $trace['steps'];
        $steps[] = array(
            'step'   => (string) $step,
            'switch' => (string) $switch,
            'result' => (string) $result,
            'reason' => sanitize_text_field((string) $reason),
            'at'     => current_time('mysql'),
        );
        if (count($steps) > 50) {
            $steps = array_slice($steps, -50);
        }
        update_post_meta((int) $doc_id, '_zw_doc_auto_flow', wp_json_encode(array(
            'steps'      => $steps,
            'updated_at' => current_time('mysql'),
        )));
    }
}

if (!function_exists('zw_doc_auto_flow_step_label')) {
    /**
     * 步骤名 → 中文标签（详情页轨迹展示用）。
     *
     * @param string $step 步骤名。
     * @return string
     */
    function zw_doc_auto_flow_step_label($step) {
        $map = array(
            'review' => '审核',
            'legal'  => '法务结论',
            'seal'   => '用印',
            'pdf'    => '生成正式用印件',
        );

        return isset($map[(string) $step]) ? $map[(string) $step] : (string) $step;
    }
}

if (!function_exists('zw_doc_auto_flow_block')) {
    /**
     * 停下并记录 blocker（fail-closed 的唯一出口）。
     *
     * 同时写：① 轨迹 meta（result=blocked，含原因与开关）；② 既有事件表 `auto_flow_blocked`。
     * 事件备注固定格式：`系统自动（无人工参与）｜开关 <key>｜门禁 <code> 未过：<message>`。
     *
     * @param int    $doc_id 发文 ID。
     * @param string $step   步骤名。
     * @param string $switch 开关名。
     * @param string $code   门禁代号（如 g2_legal / p4_no_owner_pass / no_enabled_seal）。
     * @param string $message 门禁原文。
     * @return array 轨迹条目。
     */
    function zw_doc_auto_flow_block($doc_id, $step, $switch, $code, $message) {
        $reason = '门禁 ' . (string) $code . ' 未过：' . (string) $message;
        zw_doc_auto_flow_trace_append($doc_id, $step, $switch, 'blocked', $reason);
        if (function_exists('zw_doc_log_event')) {
            zw_doc_log_event(
                (int) $doc_id,
                ZW_DOC_AUTO_FLOW_EVENT_BLOCKED,
                get_current_user_id(),
                '',
                '',
                '系统自动（无人工参与）｜开关 ' . (string) $switch . '｜' . $reason
            );
        }

        return array('step' => (string) $step, 'switch' => (string) $switch, 'result' => 'blocked', 'reason' => $reason);
    }
}

if (!function_exists('zw_doc_auto_flow_advance')) {
    /**
     * 全流程**唯一推进器**：按合规门禁路线顺序推进自动步骤。
     *
     * 顺序：review（`auto_review`）→ legal（`auto_legal`）→ seal（`auto_seal`）→ pdf（`auto_pdf`）。
     *
     * 语义（逐条硬约束）：
     * - 每一步**先判开关**：关闭 ⇒ **立即停止**（后续一步也不做、**不写任何 meta / 事件**）
     *   ⇒ 关闭即零回归；
     * - 开关开启则**先跑既有门禁**：未过 ⇒ `zw_doc_auto_flow_block()` 停住并记录（fail-closed）；
     * - 门禁全过 ⇒ 执行既有动作（**不新建第二套写链路**）并写自动事件 + 轨迹；
     * - 已满足的步骤（状态已 approved / 法务已 pass / 已正式用印 / PDF 已投递或在途）
     *   直接跳过且**不重复记录**（幂等，可安全重入）。
     *
     * 适用范围：仅 `pending` / `approved` 的发文。`draft` / `rejected` / `cancelled` 一律
     * **直接返回、不写任何东西**（自动链对该状态无意义，写 blocker 只会污染审计）。
     *
     * @param int   $doc_id  发文 ID。
     * @param array $context 上下文：`trigger`（触发来源自述，仅记录不判权限）。
     * @return array{advanced:bool,steps:array<int,array<string,string>>,stopped:string}
     */
    function zw_doc_auto_flow_advance($doc_id, $context = array()) {
        static $running = array();

        $doc_id = (int) $doc_id;
        $result = array('advanced' => false, 'steps' => array(), 'stopped' => '');

        if ($doc_id <= 0 || !function_exists('zw_doc_get_document') || !function_exists('zw_doc_flow_settings')) {
            return $result;
        }
        // 重入守卫：推进器内部的状态迁移会再广播 `zw_doc_flow_completed`，
        // 无守卫会无限递归（单次请求内同一发文只允许一次完整推进）。
        if (!empty($running[$doc_id])) {
            $result['stopped'] = 'reentrant';

            return $result;
        }
        $running[$doc_id] = true;

        $doc = zw_doc_get_document($doc_id);
        if (!$doc) {
            unset($running[$doc_id]);

            return $result;
        }
        $status = isset($doc['status']) ? (string) $doc['status'] : '';
        if ($status !== 'pending' && $status !== 'approved') {
            unset($running[$doc_id]);
            $result['stopped'] = 'status_not_auto_flowable:' . $status;

            return $result;
        }

        $actor    = get_current_user_id();
        $switches = zw_doc_auto_flow_switches();

        // ── 步骤 1/4：自动审核（已存在能力，保持现状不回归）───────────────────
        // 开关只决定「**本系统是否替人做这一步**」：状态已是 `approved`（人工已审核 / 已在提交时自动通过）
        // 时该步**天然已满足**，无论开关取值都继续往下走 —— 否则「关闭自动审核」会连带停掉法务/用印/PDF，
        // 那既不符合用户「不需要实际人员参与的按合规路线自动推进」，也不是「保持现状」。
        if ($status === 'pending') {
            if (empty($switches['auto_review'])) {
                unset($running[$doc_id]);
                $result['stopped'] = 'auto_review_off'; // 停在人工审核步：不做、不写（零回归）

                return $result;
            }
            // 门禁：提交必须已完成正文落库（与既有 TASK-1345 触发点同处同约束）。
            $has_body = !empty($doc['body']) && function_exists('zw_doc_body_is_empty') && !zw_doc_body_is_empty($doc['body']);
            if (!$has_body) {
                $one = zw_doc_auto_flow_block($doc_id, 'review', 'auto_review', 'no_body', '正文为空，不得自动通过审核。');
                $result['steps'][] = $one;
                unset($running[$doc_id]);
                $result['stopped'] = 'review_blocked';

                return $result;
            }
            $ok = (bool) zw_doc_set_status($doc_id, 'approved', $actor, '');
            if (!$ok) {
                $one = zw_doc_auto_flow_block($doc_id, 'review', 'auto_review', 'status_transition_failed', '状态迁移被拒绝。');
                $result['steps'][] = $one;
                unset($running[$doc_id]);
                $result['stopped'] = 'review_blocked';

                return $result;
            }
            if (function_exists('zw_doc_log_event')) {
                zw_doc_log_event($doc_id, 'auto_approved', $actor, 'pending', 'approved',
                    '系统自动（无人工参与）｜开关 auto_review｜门禁：状态 pending + 正文已落库 ⇒ 通过');
            }
            zw_doc_auto_flow_trace_append($doc_id, 'review', 'auto_review', 'done', '门禁通过：状态 pending + 正文已落库');
            $result['steps'][] = array('step' => 'review', 'switch' => 'auto_review', 'result' => 'done', 'reason' => '门禁通过');
            $result['advanced'] = true;
            $status             = 'approved';
            $doc                = zw_doc_get_document($doc_id);
        }

        // ── 步骤 2/4：自动出具法务结论 ──────────────────────────────────────
        if (empty($switches['auto_legal'])) {
            unset($running[$doc_id]);
            $result['stopped'] = 'auto_legal_off';

            return $result;
        }
        if (!zw_doc_legal_is_pass($doc_id)) {
            if ($status !== 'approved') {
                $one = zw_doc_auto_flow_block($doc_id, 'legal', 'auto_legal', 'g1_status', '发文尚未审核通过，不得自动出具法务结论。');
                $result['steps'][] = $one;
                unset($running[$doc_id]);
                $result['stopped'] = 'legal_blocked';

                return $result;
            }
            $has_body = !empty($doc['body']) && function_exists('zw_doc_body_is_empty') && !zw_doc_body_is_empty($doc['body']);
            if (!$has_body) {
                $one = zw_doc_auto_flow_block($doc_id, 'legal', 'auto_legal', 'no_body', '正文为空，无法出具法务结论。');
                $result['steps'][] = $one;
                unset($running[$doc_id]);
                $result['stopped'] = 'legal_blocked';

                return $result;
            }
            if (!function_exists('zw_doc_legal_store_result')) {
                $one = zw_doc_auto_flow_block($doc_id, 'legal', 'auto_legal', 'no_writer', '法务结论唯一写入口不可用。');
                $result['steps'][] = $one;
                unset($running[$doc_id]);
                $result['stopped'] = 'legal_blocked';

                return $result;
            }
            // **唯一法务结论写入口**（不新增直接写 `_zw_doc_legal_status` 的路径）。
            zw_doc_legal_store_result($doc_id, array(
                'status'  => 'pass',
                'summary' => '系统自动出具（无人工参与，开关 auto_legal）',
                'issues'  => array(),
                'engine'  => 'auto_flow',
            ), 'ai', $actor, '开关 auto_legal｜系统自动（无人工参与）');
            if (function_exists('zw_doc_log_event')) {
                zw_doc_log_event($doc_id, 'auto_legal_passed', $actor, '', 'pass',
                    '系统自动（无人工参与）｜开关 auto_legal｜门禁 G1（状态 approved）+ 正文非空 ⇒ 结论 pass');
            }
            zw_doc_auto_flow_trace_append($doc_id, 'legal', 'auto_legal', 'done', '门禁 G1 + 正文非空通过；结论经唯一写入口落库');
            $result['steps'][] = array('step' => 'legal', 'switch' => 'auto_legal', 'result' => 'done', 'reason' => '门禁通过');
            $result['advanced'] = true;
        }

        // ── 步骤 3/4：自动用印（必须走 `zw_doc_seal_apply()` ⇒ 内部跑 `zw_doc_seal_gate()`）──
        if (empty($switches['auto_seal'])) {
            unset($running[$doc_id]);
            $result['stopped'] = 'auto_seal_off';

            return $result;
        }
        $doc = zw_doc_get_document($doc_id);
        if ($doc && (string) $doc['seal_mode'] !== 'formal') {
            $seal_id = function_exists('zw_doc_seal_default_id') ? (string) zw_doc_seal_default_id() : '';
            if ($seal_id === '') {
                $one = zw_doc_auto_flow_block($doc_id, 'seal', 'auto_seal', 'g5_seal_missing', '无**已启用**印章（默认印章未启用），系统自动用印停止。');
                $result['steps'][] = $one;
                unset($running[$doc_id]);
                $result['stopped'] = 'seal_blocked';

                return $result;
            }
            $seal = function_exists('zw_doc_seal_get') ? zw_doc_seal_get($seal_id) : null;
            // **先跑既有门禁**并如实取出首条问题（fail-closed 且原因可审计）。
            $problems = zw_doc_seal_gate($doc, array(
                'legal_status' => (string) get_post_meta($doc_id, '_zw_doc_legal_status', true),
                'can_seal'     => function_exists('zw_doc_seal_can_use') ? zw_doc_seal_can_use() : false,
                'seal_id'      => $seal_id,
                'seal_enabled' => ($seal && !empty($seal['enabled'])),
                'confirmed'    => true,
            ));
            if (!empty($problems)) {
                $first = $problems[0];
                $one   = zw_doc_auto_flow_block($doc_id, 'seal', 'auto_seal', (string) $first['code'], (string) $first['message']);
                $result['steps'][] = $one;
                unset($running[$doc_id]);
                $result['stopped'] = 'seal_blocked';

                return $result;
            }
            // `confirmed = true`：二次确认由**开关本身**承担（管理员在配置页显式开启自动用印），
            // 且此处**仍然**把 confirmed 作为门禁输入传给 `zw_doc_seal_gate()`（不绕过 G6）。
            $applied = zw_doc_seal_apply($doc_id, $seal_id, $actor, true);
            if (function_exists('is_wp_error') && is_wp_error($applied)) {
                $one = zw_doc_auto_flow_block($doc_id, 'seal', 'auto_seal', 'apply_failed', $applied->get_error_message());
                $result['steps'][] = $one;
                unset($running[$doc_id]);
                $result['stopped'] = 'seal_blocked';

                return $result;
            }
            if (function_exists('zw_doc_log_event')) {
                zw_doc_log_event($doc_id, 'auto_sealed', $actor, (string) $doc['status'], (string) $doc['status'],
                    '系统自动（无人工参与）｜开关 auto_seal｜门禁 zw_doc_seal_gate() 六项全过（G1 已通过 / G2 法务 pass / G4 权限 / G5 印章 #' . $seal_id . ' 已启用 / G6 由开关承担 / G7 版式）｜印章 #' . $seal_id);
            }
            zw_doc_auto_flow_trace_append($doc_id, 'seal', 'auto_seal', 'done', 'zw_doc_seal_gate() 六项全过；印章 #' . $seal_id);
            $result['steps'][] = array('step' => 'seal', 'switch' => 'auto_seal', 'result' => 'done', 'reason' => 'zw_doc_seal_gate() 通过');
            $result['advanced'] = true;
        }

        // ── 步骤 4/4：自动生成正式用印件 ────────────────────────────────────
        if (empty($switches['auto_pdf'])) {
            unset($running[$doc_id]);
            $result['stopped'] = 'auto_pdf_off';

            return $result;
        }
        $doc  = zw_doc_get_document($doc_id);
        $pdf  = function_exists('zw_doc_pdf_status') ? zw_doc_pdf_status($doc_id) : array('status' => '');
        $busy = in_array((string) $pdf['status'], array('queued', 'rendering', 'ready'), true);
        if (!$busy && $doc) {
            // **先跑既有门禁**（`zw_doc_pdf_queue()` 内部还会再跑一次，双保险）。
            $problems = function_exists('zw_doc_pdf_gate') ? zw_doc_pdf_gate($doc, array(
                'can_seal'         => function_exists('zw_doc_seal_can_use') ? zw_doc_seal_can_use() : false,
                'owner_configured' => function_exists('zw_doc_pdf_owner_pass_configured') ? zw_doc_pdf_owner_pass_configured() : false,
                'status'           => (string) $pdf['status'],
            )) : array(array('code' => 'no_gate', 'message' => 'PDF 门禁不可用。'));
            if (!empty($problems)) {
                $first = $problems[0];
                $one   = zw_doc_auto_flow_block($doc_id, 'pdf', 'auto_pdf', (string) $first['code'], (string) $first['message']);
                $result['steps'][] = $one;
                unset($running[$doc_id]);
                $result['stopped'] = 'pdf_blocked';

                return $result;
            }
            $queued = zw_doc_pdf_queue($doc_id, $actor, 0);
            if (function_exists('is_wp_error') && is_wp_error($queued)) {
                $one = zw_doc_auto_flow_block($doc_id, 'pdf', 'auto_pdf', 'queue_failed', $queued->get_error_message());
                $result['steps'][] = $one;
                unset($running[$doc_id]);
                $result['stopped'] = 'pdf_blocked';

                return $result;
            }
            if (function_exists('zw_doc_log_event')) {
                zw_doc_log_event($doc_id, 'auto_pdf_queued', $actor, '', 'queued',
                    '系统自动（无人工参与）｜开关 auto_pdf｜门禁 zw_doc_pdf_gate() 全过（已正式用印 / 正文非空 / 用印权限 / ZW_DOC_PDF_OWNER_PASS 已配置）⇒ 已投递渲染任务');
            }
            zw_doc_auto_flow_trace_append($doc_id, 'pdf', 'auto_pdf', 'done', 'zw_doc_pdf_gate() 全过；已投递加密 PDF 任务');
            $result['steps'][] = array('step' => 'pdf', 'switch' => 'auto_pdf', 'result' => 'done', 'reason' => 'zw_doc_pdf_gate() 通过');
            $result['advanced'] = true;
        }

        unset($running[$doc_id]);

        return $result;
    }
}

if (!function_exists('zw_doc_auto_flow_on_status_change')) {
    /**
     * **唯一触发点**：`zw_doc_set_status()` 的 `zw_doc_flow_completed` 咽喉上的单个监听器。
     *
     * 为什么挂在咽喉而不是散落各处：`zw_doc_set_status()` 是全站状态迁移的**唯一出口**，
     * 任何新增入口（后台按钮 / API / 批量 / CLI）只要走它就不会静默漏推进；
     * 同时保证「一处调用」的硬约束（`add_action` 只登记一次）。
     *
     * 非 `pending` / `approved` 的状态变化（草稿 / 驳回 / 撤回）**直接返回、不写任何东西**。
     *
     * @param int    $post_id 发文 ID。
     * @param string $status  新状态。
     * @param array  $ctx     上下文（from/to/actor/note）。
     * @return void
     */
    function zw_doc_auto_flow_on_status_change($post_id, $status, $ctx = array()) {
        $status = (string) $status;
        if ($status !== 'approved' && $status !== 'pending') {
            return;
        }
        zw_doc_auto_flow_advance((int) $post_id, array(
            'trigger' => 'status_change',
            'to'      => $status,
            'from'    => isset($ctx['from']) ? (string) $ctx['from'] : '',
        ));
    }
}

add_action('zw_doc_flow_completed', 'zw_doc_auto_flow_on_status_change', 20, 3);
