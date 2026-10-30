<?php
/**
 * 发文管理公共助手（TASK-433）。
 *
 * 约束：
 * - 仅后台管理员可用；
 * - 不新增全局 CSS，页面样式由页面模板内联输出；
 * - 业务标识统一使用 zw_doc_* 前缀。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_MVP_VERSION')) {
    // TASK-1867（CHG-362）：新增份号台账表 `zw_doc_copy_numbers` ⇒ 版本号递增触发 dbDelta。
    define('ZW_DOC_MVP_VERSION', '2026-10-21.1');
}

if (!function_exists('zw_doc_table')) {
    /**
     * 获取本模块自定义表名。
     *
     * @param string $suffix 表后缀，如 numbers / events。
     * @return string
     */
    function zw_doc_table($suffix) {
        global $wpdb;
        return $wpdb->prefix . 'zw_doc_' . sanitize_key($suffix);
    }
}

if (!function_exists('zw_doc_is_enabled')) {
    /**
     * 功能开关：默认关闭，发布时再开启。
     *
     * @return bool
     */
    function zw_doc_is_enabled() {
        return (bool) get_option('zw_doc_mvp_enabled', 0);
    }
}

if (!function_exists('zw_doc_number_settings')) {
    /**
     * 文号规则。
     *
     * @return array
     */
    function zw_doc_number_settings() {
        $defaults = array(
            'prefix'       => '中传',
            'doc_types'    => array('函', '字', '通知'),
            'digits'       => 3,
            'pad'          => 0,
            'default_year' => (int) current_time('Y'),
            'start_seq'    => 1,
            'start_seq_overrides' => array(),
            'updated_by'   => 0,
            'updated_at'   => '',
        );
        $saved = get_option('zw_doc_number_settings', array());
        if (!is_array($saved)) {
            $saved = array();
        }
        $settings = array_merge($defaults, $saved);

        if (!is_array($settings['start_seq_overrides'])) {
            $settings['start_seq_overrides'] = array();
        }
        if (!is_array($settings['doc_types'])) {
            $settings['doc_types'] = array_map('trim', preg_split('/[,，\\s]+/u', (string) $settings['doc_types']));
        }
        $settings['doc_types'] = array_values(array_filter(array_unique(array_map('sanitize_text_field', $settings['doc_types']))));
        if (empty($settings['doc_types'])) {
            $settings['doc_types'] = array('函', '字', '通知');
        }
        $settings['prefix']       = trim((string) $settings['prefix']) !== '' ? sanitize_text_field($settings['prefix']) : '中传';
        $settings['digits']       = max(1, min(6, (int) $settings['digits']));
        $settings['pad']          = 0;
        $settings['default_year'] = (int) $settings['default_year'];
        // 用户确认：起始序号从 UI 隐藏，内部固定为 1。
        $settings['start_seq']    = 1;

        return $settings;
    }
}

if (!function_exists('zw_doc_sanitize_number_settings')) {
    /**
     * 清洗文号规则保存输入。
     *
     * @param array $input 原始输入。
     * @return array
     */
    function zw_doc_sanitize_number_settings($input) {
        $current = zw_doc_number_settings();
        $input   = is_array($input) ? $input : array();

        $types_raw = isset($input['doc_types']) ? $input['doc_types'] : '';
        if (is_array($types_raw)) {
            $types = $types_raw;
        } else {
            $types = preg_split('/[,，\\s]+/u', (string) $types_raw);
        }
        $types = array_values(array_filter(array_unique(array_map('sanitize_text_field', array_map('trim', (array) $types)))));
        if (empty($types)) {
            $types = array('函', '字', '通知');
        }

        $digits    = max(1, min(6, isset($input['digits']) ? (int) $input['digits'] : 3));
        $year      = isset($input['default_year']) ? (int) $input['default_year'] : (int) current_time('Y');
        if ($year < 1900 || $year > 2200) {
            $year = (int) current_time('Y');
        }

        return array(
            'prefix'       => $current['prefix'], // 用户已确认：中传固定不可配置。
            'doc_types'    => $types,
            'digits'       => $digits,
            'pad'          => 0,
            'default_year' => $year,
            'start_seq'    => 1,
            'start_seq_overrides' => isset($current['start_seq_overrides']) && is_array($current['start_seq_overrides']) ? $current['start_seq_overrides'] : array(),
            'updated_by'   => get_current_user_id(),
            'updated_at'   => current_time('mysql'),
        );
    }
}

if (!function_exists('zw_doc_number_type_labels')) {
    /**
     * 文种**简称 → 全称**映射（**唯一出处**，TASK-1402 / CHG-213）。
     *
     * 用户口径（2026-10-10 指令原文）：「通-通知 函-函件 字-字 任-任命 聘-聘任 告-公告」。
     * 用途：填充「公文台账」列表的「发文类型」列，以及其他展示「发文类型」的页面与详情。
     *
     * 约束（断言反向守卫见 `tests/test-doc-data-dictionary.php`）：
     * - 本表是「发文类型」展示的**唯一出处**，全仓禁止复制第二张映射表
     *   （映射字面量 `'通' => '通知'` 等仅允许在本函数内出现 1 次）；
     * - 未登记的简称 ⇒ **原样显示**（`zw_doc_number_type_label()` 直通），不得臆造全称；
     * - 「任 → 任命」只是**展示口径**，不改变 `zw_doc_appoint_is_appoint_type()` 对
     *   文种字面量「任」的判定（展示与业务判定解耦，任命链路零影响）。
     *
     * @return array<string,string>
     */
    function zw_doc_number_type_labels() {
        return array(
            '通' => '通知',
            '函' => '函件',
            '字' => '字',
            '任' => '任命',
            '聘' => '聘任',
            '告' => '公告',
        );
    }
}

if (!function_exists('zw_doc_number_type_label')) {
    /**
     * 单个文种简称 → 展示全称（未登记 ⇒ 原样返回；空串 ⇒ 空串，由展示层决定占位）。
     *
     * @param string $short 文种简称（如「通」）。
     * @return string
     */
    function zw_doc_number_type_label($short) {
        $short = trim((string) $short);
        if ($short === '') {
            return '';
        }
        $map = zw_doc_number_type_labels();

        return array_key_exists($short, $map) ? (string) $map[$short] : $short;
    }
}

if (!function_exists('zw_doc_number_type_display')) {
    /**
     * 展示包装：空值 ⇒ 友好占位；其余 ⇒ 全称（未登记简称原样显示）。
     *
     * @param string $short       文种简称。
     * @param string $placeholder 空值占位符。
     * @return string
     */
    function zw_doc_number_type_display($short, $placeholder = '—') {
        $label = zw_doc_number_type_label($short);

        return $label !== '' ? $label : (string) $placeholder;
    }
}

if (!function_exists('zw_doc_number_type_unregistered')) {
    /**
     * 当前文种字典中**未登记**简称清单（数据字典页 A 块只读展示用）。
     *
     * @param string[]|null $types 文种列表；缺省取 `zw_doc_number_settings()['doc_types']`。
     * @return string[]
     */
    function zw_doc_number_type_unregistered($types = null) {
        if ($types === null) {
            $settings = function_exists('zw_doc_number_settings') ? zw_doc_number_settings() : array();
            $types    = (isset($settings['doc_types']) && is_array($settings['doc_types'])) ? $settings['doc_types'] : array();
        }
        $map = zw_doc_number_type_labels();
        $out = array();
        foreach ((array) $types as $one) {
            $one = trim((string) $one);
            if ($one !== '' && !array_key_exists($one, $map) && !in_array($one, $out, true)) {
                $out[] = $one;
            }
        }

        return $out;
    }
}

if (!function_exists('zw_doc_flow_settings')) {
    /**
     * 公文流转配置。
     *
     * @return array
     */
    function zw_doc_flow_settings() {
        $defaults = array(
            'require_review'       => 1,
            'review_mode'          => 'single',
            'cc_visibility'        => 'approved',
            'allow_withdraw'       => 1,
            'allow_resubmit'       => 1,
            'download_log_enabled' => 1,
            // TASK-1345（批次四 D2/D4）：审核人 / 抄送人的**候选来源部门**（组织架构部门 key）。
            // 空数组 = 沿用历史「管理员集合」口径 ⇒ 未配置时行为与发布前逐字一致。
            'reviewer_dept_keys'   => array(),
            'cc_dept_keys'         => array(),
            // TASK-1345（批次四 D3）：全局自动审核开关。
            // 开启后「提交审核」即刻通过该审核步（无需真人），但仍记录指定审核人用于留痕。
            // TASK-1410：本键**保持现状不回归** —— 默认值仍为 0（未配置站点提交后仍停在待审核）；
            // 页面上以「自动流转开关组」统一显著标注（文案唯一出处
            // `zw_doc_auto_flow_switch_definitions()`）。生产已存值 = 1（只读实测），
            // 故上线后生产端四项自动流转开关**全开**。
            'auto_review'          => 0,
            // TASK-1410（用户指令「所有流程都自动流转…并且能被记录」）：自动流转开关组。
            // 三项**默认开启**（按用户要求「不需要实际人员参与的按合规路线自动推进」）。
            // 关闭后该步（及其后各步）**什么都不做、不写任何 meta / 事件** ⇒ 行为回到现状（零回归）。
            // 每一步仍**先跑既有门禁**（`zw_doc_seal_gate()` / 印章启用 / PDF 口令就绪 / 权限），
            // 未过即停在该步并记录 blocker（fail-closed）。唯一推进器见
            // `inc/admin/doc/doc-auto-flow.php`。
            'auto_legal'           => 1,
            'auto_seal'            => 1,
            'auto_pdf'             => 1,
            'updated_by'           => 0,
            'updated_at'           => '',
        );
        $saved = get_option('zw_doc_flow_settings', array());
        if (!is_array($saved)) {
            $saved = array();
        }
        $settings = array_merge($defaults, $saved);
        $settings['require_review']       = !empty($settings['require_review']) ? 1 : 0;
        $settings['allow_withdraw']       = !empty($settings['allow_withdraw']) ? 1 : 0;
        $settings['allow_resubmit']       = !empty($settings['allow_resubmit']) ? 1 : 0;
        $settings['download_log_enabled'] = !empty($settings['download_log_enabled']) ? 1 : 0;
        $settings['auto_review']          = !empty($settings['auto_review']) ? 1 : 0;
        // TASK-1410：自动流转开关组归一（默认开启，见上方默认值说明）。
        $settings['auto_legal']           = !empty($settings['auto_legal']) ? 1 : 0;
        $settings['auto_seal']            = !empty($settings['auto_seal']) ? 1 : 0;
        $settings['auto_pdf']             = !empty($settings['auto_pdf']) ? 1 : 0;
        $settings['review_mode']          = 'single';
        $settings['cc_visibility']        = in_array($settings['cc_visibility'], array('submitted', 'approved'), true) ? $settings['cc_visibility'] : 'approved';
        $settings['reviewer_dept_keys']   = zw_doc_flow_sanitize_dept_keys($settings['reviewer_dept_keys']);
        $settings['cc_dept_keys']         = zw_doc_flow_sanitize_dept_keys($settings['cc_dept_keys']);
        return $settings;
    }
}

if (!function_exists('zw_doc_flow_sanitize_dept_keys')) {
    /**
     * 部门 key 数组形状归一（去空、清洗、去重；**不做字典白名单**）。
     *
     * 字典白名单在写入（`zw_doc_flow_filter_dept_keys()`）与取人（`zw_doc_flow_candidates()`）
     * 两处施加 —— 本函数保持「纯数据」语义，不依赖组织架构模块是否已加载。
     *
     * @param mixed $raw 原始值。
     * @return string[]
     */
    function zw_doc_flow_sanitize_dept_keys($raw) {
        if (!is_array($raw)) {
            $raw = ($raw === null || $raw === '') ? array() : array($raw);
        }
        $out = array();
        foreach ($raw as $item) {
            if (is_array($item) || is_object($item)) {
                continue;
            }
            $key = function_exists('sanitize_key')
                ? sanitize_key((string) $item)
                : preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $item));
            $key = trim((string) $key);
            if ($key !== '' && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }
        return $out;
    }
}

if (!function_exists('zw_doc_flow_filter_dept_keys')) {
    /**
     * 按**当前组织架构部门字典**过滤提交的部门 key（服务端白名单；前端禁用只是体验）。
     *
     * @param mixed                $raw     提交值。
     * @param array<string,string> $options 允许的 key => 名称；为空数组时不做字典过滤（避免误清空）。
     * @return string[]
     */
    function zw_doc_flow_filter_dept_keys($raw, $options) {
        $keys = zw_doc_flow_sanitize_dept_keys($raw);
        if (empty($options) || !is_array($options)) {
            return $keys;
        }
        $allowed = array_map('strval', array_keys($options));
        return array_values(array_intersect($keys, $allowed));
    }
}

if (!function_exists('zw_doc_flow_dept_options')) {
    /**
     * 组织架构部门字典（key => 名称）。
     *
     * 组织架构模块（`inc/admin/org/org-bootstrap.php`）在 `functions.php` 中**晚于**
     * doc-bootstrap 加载 ⇒ 只能运行期 `function_exists` 守卫（先例：`doc-appoint.php`）。
     *
     * @return array<string,string>
     */
    function zw_doc_flow_dept_options() {
        if (!function_exists('zw_org_departments')) {
            return array();
        }
        $out = array();
        foreach ((array) zw_org_departments() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = isset($row['key']) ? trim((string) $row['key']) : '';
            if ($key === '') {
                continue;
            }
            $out[$key] = isset($row['name']) ? (string) $row['name'] : $key;
        }
        return $out;
    }
}

if (!function_exists('zw_doc_flow_candidate_depts')) {
    /**
     * 某语境**当前生效**的候选部门 key（已按现行部门字典过滤悬空 key）。
     *
     * @param string $role 'reviewer' | 'cc'。
     * @return string[]
     */
    function zw_doc_flow_candidate_depts($role) {
        $settings = zw_doc_flow_settings();
        $field    = ($role === 'cc') ? 'cc_dept_keys' : 'reviewer_dept_keys';
        $keys     = isset($settings[$field]) && is_array($settings[$field]) ? $settings[$field] : array();
        $dict     = zw_doc_flow_dept_options();
        if (empty($dict)) {
            return array();
        }
        return array_values(array_intersect($keys, array_map('strval', array_keys($dict))));
    }
}

if (!function_exists('zw_doc_flow_candidates')) {
    /**
     * 生成某语境的审核人 / 抄送人候选（两处 UI 的唯一出处）。
     *
     * - **配置了部门** ⇒ 组织架构部门**全员**（D2：**不过滤是否绑定 uid**），值 = `p:<人员ID>`；
     * - **未配置部门 / 组织架构模块未加载** ⇒ 历史口径「管理员集合」，值 = `u:<用户ID>`。
     *
     * 候选只渲染**姓名（部门·职务）** —— 严禁把手机号 / 邮箱 / 身份证号放进标签或检索词。
     *
     * @param string $role 'reviewer' | 'cc'。
     * @return array[] 见下方字段说明。
     */
    function zw_doc_flow_candidates($role) {
        $role      = ($role === 'cc') ? 'cc' : 'reviewer';
        $dept_keys = zw_doc_flow_candidate_depts($role);
        $out       = array();

        if (!empty($dept_keys) && function_exists('zw_org_people')) {
            $dict = zw_doc_flow_dept_options();
            foreach ($dept_keys as $dept_key) {
                foreach ((array) zw_org_people($dept_key) as $person) {
                    if (!is_object($person) || !isset($person->ID)) {
                        continue;
                    }
                    $person_id = (int) $person->ID;
                    $name      = isset($person->post_title) ? trim((string) $person->post_title) : '';
                    if ($person_id <= 0 || $name === '') {
                        continue;
                    }
                    $uid       = (int) get_post_meta($person_id, 'zw_person_user_id', true);
                    $duty      = trim((string) get_post_meta($person_id, 'zw_person_duty', true));
                    $dept_name = isset($dict[$dept_key]) ? (string) $dict[$dept_key] : (string) $dept_key;
                    // TASK-1378（用户 2026-10-10 拍板，**推翻 TASK-1362 的候选过滤**）：
                    // 「审核人 **不管有没有绑定 uid 都显示** —— 内部做记录，以后绑定 uid 后也可**关联查看**相关数据」。
                    // ⇒ 未绑账号者**一律入列**（可被选、可落库：落 `reviewer_person_id` + 姓名、uid 留 0），
                    //   待其日后绑定 uid 即可按人员 ID 关联回查既有记录。
                    // 注：原先「关自动审核时不列入」的过滤在此**移除**；同时未绑 uid 的指派在
                    //     创建侧（doc-admin-list.php）与改派侧（zw_doc_update_flow）**都不再拦截**，
                    //     因此不会退回"必然被驳回的死选项"。（TASK-1362 的提示级别修复保持有效。）
                    $label     = $name . '（' . $dept_name . ($duty !== '' ? '·' . $duty : '') . '）';
                    $out['p:' . $person_id] = array(
                        'source'    => 'org',
                        'value'     => 'p:' . $person_id,
                        'label'     => $label,
                        'search'    => trim($name . ' ' . $dept_name . ' ' . $duty),
                        'name'      => $name,
                        'user_id'   => $uid,
                        'person_id' => $person_id,
                        'dept_key'  => (string) $dept_key,
                        'dept_name' => $dept_name,
                        'duty'      => $duty,
                    );
                }
            }
            return array_values($out);
        }

        foreach ((array) zw_doc_get_admin_users() as $user) {
            if (!is_object($user) || !isset($user->ID)) {
                continue;
            }
            $uid = (int) $user->ID;
            if ($uid <= 0) {
                continue;
            }
            $display = isset($user->display_name) && $user->display_name !== '' ? (string) $user->display_name : (string) $user->user_login;
            $login   = isset($user->user_login) ? (string) $user->user_login : '';
            $label   = $display . ($login !== '' ? '（' . $login . '）' : '');
            $out['u:' . $uid] = array(
                'source'    => 'admin',
                'value'     => 'u:' . $uid,
                'label'     => $label,
                'search'    => trim($display . ' ' . $login),
                'name'      => $display,
                'user_id'   => $uid,
                'person_id' => 0,
                'dept_key'  => '',
                'dept_name' => '',
                'duty'      => '',
            );
        }
        return array_values($out);
    }
}

if (!function_exists('zw_doc_flow_resolve_from')) {
    /**
     * 在**已构建好的候选列表**上解析提交值（批量场景唯一入口，避免每项都重建候选）。
     *
     * 接受 `p:<人员ID>` / `u:<用户ID>`；并兼容历史表单提交的**裸整数 uid**（仅在
     * 「未配置部门」的管理员候选下解析）。配置了部门时，裸 uid **一律拒绝**（防止绕过名单）。
     *
     * @param array[] $candidates `zw_doc_flow_candidates()` 的结果。
     * @param mixed   $value      提交值。
     * @return array|null
     */
    function zw_doc_flow_resolve_from($candidates, $value) {
        if (is_array($value) || is_object($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach ((array) $candidates as $candidate) {
            if ((string) $candidate['value'] === $value) {
                return $candidate;
            }
        }
        if (ctype_digit($value)) {
            foreach ((array) $candidates as $candidate) {
                if ($candidate['source'] === 'admin' && (int) $candidate['user_id'] === (int) $value) {
                    return $candidate;
                }
            }
        }
        return null;
    }
}

if (!function_exists('zw_doc_flow_resolve_person')) {
    /**
     * 提交值 → 候选人（按语境现取候选；单项场景使用）。
     *
     * @param string $role  'reviewer' | 'cc'。
     * @param mixed  $value 提交值。
     * @return array|null
     */
    function zw_doc_flow_resolve_person($role, $value) {
        return zw_doc_flow_resolve_from(zw_doc_flow_candidates($role), $value);
    }
}

if (!function_exists('zw_doc_flow_person_label')) {
    /**
     * 人员显示名（审核人 / 抄送人台账展示的唯一出处）。
     *
     * 优先已绑 uid 的账号显示名；无账号时回退组织人员姓名（`_zw_doc_reviewer_person_id` /
     * `_zw_doc_cc_person_ids`）；再回退历史文本字段。
     *
     * @param array $doc 发文记录。
     * @return string
     */
    function zw_doc_flow_person_label($doc) {
        if (!is_array($doc)) {
            return '—';
        }
        if (!empty($doc['reviewer_id']) && (int) $doc['reviewer_id'] > 0) {
            return zw_doc_user_label((int) $doc['reviewer_id']);
        }
        if (!empty($doc['reviewer_person_id'])) {
            $person = get_post((int) $doc['reviewer_person_id']);
            if ($person && isset($person->post_title) && $person->post_title !== '') {
                return (string) $person->post_title;
            }
        }
        if (!empty($doc['reviewer_name'])) {
            return (string) $doc['reviewer_name'];
        }
        return '—';
    }
}

if (!function_exists('zw_doc_reviewer_legacy_phrase')) {
    /**
     * 「审核（历史记录，不关联系统人员）」固定短语。
     *
     * **TASK-1347（用户 2026-10-09 拍板）**：导入（历史发文）的「审核人」**一律写死**为该字面值 ——
     * 即使导入表格的「审核」列有真实人名（如「王海涛」），也**丢弃**，以表达「历史记录、不关联系统人员」。
     * 在线撰写侧不再并列第二个「审核」字段，统一走系统人员选择（`doc_reviewer_id`）。
     *
     * @return string
     */
    function zw_doc_reviewer_legacy_phrase() {
        return '审核（历史记录，不关联系统人员）';
    }
}

if (!function_exists('zw_doc_leader_dept_key')) {
    /**
     * 「分管领导」候选来源部门 key（组织架构「协会领导」）。
     *
     * 口径：优先取 `leadership`（默认字典 key，重命名部门不影响），退化为按**名称**「协会领导」匹配；
     * 组织架构模块未加载 / 字典缺该部门时返回 `''`（调用方据此降级为「仅原值/空」）。
     *
     * @return string
     */
    function zw_doc_leader_dept_key() {
        $dict = zw_doc_flow_dept_options();
        if (isset($dict['leadership'])) {
            return 'leadership';
        }
        foreach ($dict as $key => $name) {
            if (trim((string) $name) === '协会领导') {
                return (string) $key;
            }
        }
        return '';
    }
}

if (!function_exists('zw_doc_leader_candidates')) {
    /**
     * 「分管领导」候选（组织架构「协会领导」部门**全员**，含未绑 uid 者）。
     *
     * **个人信息最小化（硬约束）**：只投影 `姓名 / 职务 / person_id / user_id`；
     * **禁止**读取或渲染手机号、邮箱、身份证号（与 TASK-1345 候选同口径）。
     *
     * @return array[] 每项：value（姓名，提交值）/ name / duty / label / user_id / person_id
     */
    function zw_doc_leader_candidates() {
        $key = zw_doc_leader_dept_key();
        $out = array();
        if ($key === '' || !function_exists('zw_org_people')) {
            return $out;
        }
        foreach ((array) zw_org_people($key) as $person) {
            if (!is_object($person) || !isset($person->ID)) {
                continue;
            }
            $person_id = (int) $person->ID;
            $name      = isset($person->post_title) ? trim((string) $person->post_title) : '';
            if ($person_id <= 0 || $name === '') {
                continue;
            }
            $duty = trim((string) get_post_meta($person_id, 'zw_person_duty', true));
            $uid  = (int) get_post_meta($person_id, 'zw_person_user_id', true);
            // 同名去重（同一姓名只出一个选项，值即姓名）。
            $out[$name] = array(
                'value'     => $name,
                'name'      => $name,
                'duty'      => $duty,
                'user_id'   => $uid,
                'person_id' => $person_id,
                'label'     => $name . ($duty !== '' ? '（' . $duty . '）' : ''),
            );
        }
        return array_values($out);
    }
}

if (!function_exists('zw_doc_leader_default_name')) {
    /**
     * 「分管领导」自动分配默认值：在「协会领导」候选里**随机**取一人。
     *
     * **绝不覆盖已有值**：`$current` 非空（存量 / 用户已改 / 上一次已保存）时返回空串，调用方保持原值。
     * 候选为空（组织架构模块未加载或该部门无人员）时同样返回空串 ⇒ 字段保持「请选择」。
     *
     * @param string $current 当前值。
     * @return string 随机姓名；不适用时为空串。
     */
    function zw_doc_leader_default_name($current = '') {
        if (trim((string) $current) !== '') {
            return '';
        }
        $candidates = zw_doc_leader_candidates();
        if (empty($candidates)) {
            return '';
        }
        $pick = $candidates[array_rand($candidates)];
        return (string) $pick['name'];
    }
}

if (!function_exists('zw_doc_leader_reviewer_sync_script')) {
    /**
     * 「分管领导 ⇄ 审核人」互斥的前端同步脚本（两处表单共用唯一出处）。
     *
     * 用户口径（TASK-1347）：自动填入的协会领导在**审核人**中**灰色不可选**。
     * 实现为**双向**互斥（同一条规则的另一向），避免「选完审核人才发现提交被拒」的死路：
     * ① 分管领导已选 ⇒ 审核人中同名选项 `disabled` + 灰字；
     * ② 审核人已选 ⇒ 分管领导中同名选项 `disabled` + 灰字；若分管领导的当前值因此被禁用，
     *    则在**未被禁用**的候选里重新随机（沿用「自动分配」口径），无可选时置空。
     * 前端只是体验，服务端 `zw_doc_handle_create_document()` 仍做权威校验。
     *
     * @param string $leader_id   分管领导 select 的 id。
     * @param string $reviewer_id 审核人 select 的 id（选项需带 `data-name`）。
     * @return void
     */
    function zw_doc_leader_reviewer_sync_script($leader_id, $reviewer_id) {
        $leader_id   = (string) $leader_id;
        $reviewer_id = (string) $reviewer_id;
        if ($leader_id === '' || $reviewer_id === '') {
            return;
        }
        // 与 canonical 控件同款守卫：部分加载场景（单测 / 探针）未必有 `wp_json_encode`。
        $encode = function_exists('wp_json_encode') ? 'wp_json_encode' : 'json_encode';
        ?>
        <script>
        (function () {
            var leader = document.getElementById(<?php echo $encode($leader_id); ?>);
            var reviewer = document.getElementById(<?php echo $encode($reviewer_id); ?>);
            if (!leader || !reviewer) { return; }
            var GREY = '#a7aaad';
            function dataName(opt) {
                return opt && opt.getAttribute ? String(opt.getAttribute('data-name') || '').trim() : '';
            }
            function applyLeaderToReviewer(name) {
                var picked = false;
                for (var i = 0; i < reviewer.options.length; i++) {
                    var opt = reviewer.options[i];
                    var hit = (name !== '' && dataName(opt) === name);
                    opt.disabled = hit;
                    opt.style.color = hit ? GREY : '';
                    if (hit && opt.selected) { picked = true; }
                }
                if (picked) { reviewer.value = ''; }
            }
            function applyReviewerToLeader(name) {
                for (var i = 0; i < leader.options.length; i++) {
                    var opt = leader.options[i];
                    var hit = (opt.value !== '' && name !== '' && String(opt.value).trim() === name);
                    opt.disabled = hit;
                    opt.style.color = hit ? GREY : '';
                }
                var cur = leader.options[leader.selectedIndex];
                if (cur && cur.disabled) {
                    // 分管领导当前值与被选审核人同名 ⇒ 在仍可选的协会领导里重新随机。
                    var pool = [];
                    for (var j = 0; j < leader.options.length; j++) {
                        if (leader.options[j].value !== '' && !leader.options[j].disabled) { pool.push(leader.options[j].value); }
                    }
                    leader.value = pool.length ? pool[Math.floor(Math.random() * pool.length)] : '';
                }
            }
            function sync() {
                var leaderName = String(leader.value || '').trim();
                applyLeaderToReviewer(leaderName);
                var rev = reviewer.options[reviewer.selectedIndex];
                applyReviewerToLeader(dataName(rev));
                // 分管领导可能因互斥被重新随机 ⇒ 审核人侧再判定一次。
                applyLeaderToReviewer(String(leader.value || '').trim());
            }
            leader.addEventListener('change', sync);
            reviewer.addEventListener('change', sync);
            sync();
        })();
        </script>
        <?php
    }
}

if (!function_exists('zw_doc_flow_cc_labels')) {
    /**
     * 抄送人显示名列表。
     *
     * 优先落库时记录的 `_zw_doc_cc_names`（含**未绑 uid** 的组织人员）；缺失时回退按 uid 解析。
     *
     * @param array $doc 发文记录。
     * @return string[]
     */
    function zw_doc_flow_cc_labels($doc) {
        if (!is_array($doc)) {
            return array();
        }
        $names = array();
        if (!empty($doc['cc_names']) && is_array($doc['cc_names'])) {
            foreach ($doc['cc_names'] as $name) {
                $name = trim((string) $name);
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }
        foreach ((array) (isset($doc['cc_ids']) ? $doc['cc_ids'] : array()) as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            $label = zw_doc_user_label($uid);
            if ($label !== '—' && !in_array($label, $names, true)) {
                $names[] = $label;
            }
        }
        // 未绑 uid 的抄送人（person id）补名，保证台账与指派一致。
        foreach ((array) (isset($doc['cc_person_ids']) ? $doc['cc_person_ids'] : array()) as $person_id) {
            $person_id = (int) $person_id;
            if ($person_id <= 0) {
                continue;
            }
            $person = get_post($person_id);
            if ($person && isset($person->post_title) && $person->post_title !== '' && !in_array($person->post_title, $names, true)) {
                $names[] = (string) $person->post_title;
            }
        }
        return $names;
    }
}

if (!function_exists('zw_doc_flow_can_edit')) {
    /**
     * 是否有权编辑某篇发文的**流转字段**（TASK-1350 权限矩阵唯一出处）。
     *
     * ⚠️ **不得复用** `zw_doc_can_edit_doc()`（正文判据 = 作者或管理员且未锁定）——
     * 流转字段涉及「谁能审核 / 谁可见」，门槛不同：
     *
     * | 状态 | 作者 | 管理员（`manage_options`） |
     * | --- | --- | --- |
     * | `draft` / `rejected` / `cancelled` | ✅ | ✅ |
     * | `pending` | ❌（改派＝换掉自己的审核人，**利益冲突**） | ✅（解卡单） |
     * | `approved` / 未知 | ❌ | ❌（更正走「撤销用印 → 重新审核」） |
     * | **已用印锁定**（`_zw_doc_locked`） | ❌ | **❌（含管理员）** |
     *
     * @param array $doc     发文记录（`zw_doc_get_document()` 结果）。
     * @param int   $user_id 用户 ID（0 = 当前用户）。
     * @return bool
     */
    function zw_doc_flow_can_edit($doc, $user_id = 0) {
        if (!is_array($doc) || empty($doc['id'])) {
            return false;
        }
        if (!empty($doc['locked'])) {
            return false;
        }
        $user_id  = $user_id ? (int) $user_id : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
        $status   = isset($doc['status']) ? (string) $doc['status'] : '';
        if ($status === 'pending') {
            return $is_admin;
        }
        if ($status === 'approved') {
            // TASK-1350（Q1 × Q4 的衔接解释）：**导入的历史件没有审核 / 用印流程**，
            // 用户对「导入件是否允许改派」选了「允许」⇒ 此处**仅**为导入件（`import_key` 非空）
            // 且**仅管理员**开放；普通 approved 仍一律不允许（更正走「撤销用印 → 重新审核」）。
            return $is_admin && !empty($doc['import_key']);
        }
        if (in_array($status, array('draft', 'rejected', 'cancelled'), true)) {
            return $is_admin || ($user_id > 0 && (int) (isset($doc['author_id']) ? $doc['author_id'] : 0) === $user_id);
        }
        return false;
    }
}

if (!function_exists('zw_doc_flow_history_ids')) {
    /**
     * 「历史参与人」用户 ID 列表（TASK-1350 / 用户拍板：改派后被换下的原审核人·原抄送人**保留可见**）。
     *
     * 只读消费；写入见 `zw_doc_update_flow()`（把被替换掉的 reviewer / cc 追加进来，只增不减）。
     *
     * @param array $doc 发文记录。
     * @return int[]
     */
    function zw_doc_flow_history_ids($doc) {
        if (!is_array($doc) || empty($doc['flow_history_ids']) || !is_array($doc['flow_history_ids'])) {
            return array();
        }
        return array_values(array_filter(array_map('absint', $doc['flow_history_ids'])));
    }
}

if (!function_exists('zw_doc_flow_current_reviewer_value')) {
    /**
     * 存量审核人在候选口径下的「提交值」（`p:<人员ID>` 优先，退化 `u:<用户ID>`；无则空串）。
     *
     * 用途：改派表单回显 + 「未改动即允许提交」的白名单豁免（候选来源会随组织架构/管理员集合变化，
     * 存量指派可能已不在当前候选里 —— 此时**保持不变**必须仍然可保存，否则改别的字段会被连带拒绝）。
     *
     * @param array $doc 发文记录。
     * @return string
     */
    function zw_doc_flow_current_reviewer_value($doc) {
        if (!is_array($doc)) {
            return '';
        }
        if (!empty($doc['reviewer_person_id'])) {
            return 'p:' . (int) $doc['reviewer_person_id'];
        }
        if (!empty($doc['reviewer_id'])) {
            return 'u:' . (int) $doc['reviewer_id'];
        }
        return '';
    }
}

if (!function_exists('zw_doc_update_flow')) {
    /**
     * 流转字段的**唯一写入咽喉**（TASK-1350）。
     *
     * 口径：① 只写流转 meta（审核人 / 抄送 / 分管领导 / 备注），**不迁移状态**（避免在 `approved` 上
     * 改派重新广播 `zw_doc_flow_completed` ⇒ 任命同步 / 证书归档）；② 字段级**白名单**与**互斥**复用
     * TASK-1345 / 1347 的既有规则（禁止编辑态自造第二套校验）；③ 逐字段写审计事件（旧值 → 新值）；
     * ④ 被换下的 reviewer / cc 追加进 `_zw_doc_flow_history_ids`（保留可见）。
     *
     * 调用方**必须先**用 `zw_doc_flow_can_edit()` 判权（本函数只做字段级校验，不做权限判定）。
     *
     * @param array  $doc     发文记录（当前值）。
     * @param array  $fields  期望值：reviewer_value / cc_values[] / leader_name / remark。
     * @param int    $actor   操作人。
     * @param string $reason  变更原因（可空，随审计事件落库）。
     * @return array|WP_Error 成功返回 array('changed' => string[])，失败返回 WP_Error。
     */
    function zw_doc_update_flow($doc, $fields, $actor = 0, $reason = '') {
        if (!is_array($doc) || empty($doc['id'])) {
            return new WP_Error('zw_doc_flow_no_doc', '发文不存在。');
        }
        $doc_id = (int) $doc['id'];
        $actor  = $actor ? (int) $actor : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        $fields = is_array($fields) ? $fields : array();
        $reason = trim((string) $reason);
        $note_suffix = ($reason !== '') ? '；原因：' . $reason : '';
        // 缺键 = **不改该项**（部分更新语义）：handler 会提交全键，但部分调用方（含单测）只传要改的项。
        $has = function ($key) use ($fields) { return array_key_exists($key, $fields); };

        /* ── ① 审核人（候选白名单 + 未绑 uid 约束 + 与分管领导互斥） ───────────── */
        $current_value    = zw_doc_flow_current_reviewer_value($doc);
        $reviewer_supplied = $has('reviewer_value');
        $reviewer_value   = $reviewer_supplied ? trim((string) $fields['reviewer_value']) : $current_value;
        $candidate        = null;
        if ($reviewer_supplied || $current_value !== '') {
            $candidate = zw_doc_flow_resolve_from(zw_doc_flow_candidates('reviewer'), $reviewer_value);
            if (!$candidate && $reviewer_value !== '' && $reviewer_value === $current_value) {
                // 存量指派：候选来源变化后**保持不变**也必须可保存（只改别的字段时不该被拦）。
                $candidate = array(
                    'source' => 'legacy', 'value' => $reviewer_value, 'label' => zw_doc_user_label((int) $doc['reviewer_id']),
                    'name' => (string) $doc['reviewer_name'], 'user_id' => (int) $doc['reviewer_id'],
                    'person_id' => (int) $doc['reviewer_person_id'], 'dept_key' => '', 'dept_name' => '', 'duty' => '',
                );
            }
            if (!$candidate) {
                return new WP_Error('zw_doc_flow_bad_reviewer', '审核人必须从当前候选名单中选择。');
            }
            if ((int) $candidate['user_id'] > 0 && (int) $candidate['user_id'] === $actor) {
                return new WP_Error('zw_doc_flow_self_reviewer', '审核人不能是操作人自己。');
            }
            // TASK-1378（用户拍板）：**未绑 uid 的审核人不再拦截** —— 只落「人员 ID + 姓名」作内部记录，
            // 待其日后绑定 uid 即可关联回查；是否由系统自动放行仍取决于 `auto_review`（不影响本处受理）。
            unset($flow_settings);
        }

        /* ── ② 分管领导（名录白名单；空值允许） ──────────────────────────────── */
        $leader_name  = $has('leader_name') ? trim((string) $fields['leader_name']) : (string) $doc['leader_name'];
        $leader_names = array_map(function ($item) { return (string) $item['value']; }, zw_doc_leader_candidates());
        if ($leader_name !== '' && !empty($leader_names) && !in_array($leader_name, $leader_names, true)) {
            return new WP_Error('zw_doc_flow_bad_leader', '分管领导必须从「协会领导」名录中选择。');
        }
        if ($candidate && $leader_name !== '' && trim((string) $candidate['name']) !== '' && trim((string) $candidate['name']) === $leader_name) {
            return new WP_Error('zw_doc_flow_leader_conflict', '分管领导不能同时担任审核人，请改选其中一项。');
        }

        /* ── ③ 抄送人（候选白名单；未改动的存量值豁免；去重并剔除审核人） ────── */
        $cc_pool        = zw_doc_flow_candidates('cc');
        $current_cc     = array_map('intval', (array) (isset($doc['cc_ids']) ? $doc['cc_ids'] : array()));
        $current_cc_pid = array_map('intval', (array) (isset($doc['cc_person_ids']) ? $doc['cc_person_ids'] : array()));
        $current_cc_val = array_map(function ($uid) { return 'u:' . (int) $uid; }, $current_cc);
        $cc_ids = array(); $cc_person_ids = array(); $cc_names = array();
        $cc_supplied = $has('cc_values');
        if (!$cc_supplied) {
            // 未提交抄送 ⇒ 保持原样（部分更新语义）
            $cc_ids        = $current_cc;
            $cc_person_ids = $current_cc_pid;
            $cc_names      = array_map('strval', (array) (isset($doc['cc_names']) ? $doc['cc_names'] : array()));
        } else {
        foreach ((array) $fields['cc_values'] as $cc_value) {
            $cc_value = trim((string) $cc_value);
            if ($cc_value === '') { continue; }
            $cc_candidate = zw_doc_flow_resolve_from($cc_pool, $cc_value);
            if (!$cc_candidate && in_array($cc_value, $current_cc_val, true)) {
                $cc_uid = (int) substr($cc_value, 2);
                $cc_candidate = array(
                    'source' => 'legacy', 'value' => $cc_value, 'label' => zw_doc_user_label($cc_uid),
                    'name' => '', 'user_id' => $cc_uid, 'person_id' => 0, 'dept_key' => '', 'dept_name' => '', 'duty' => '',
                );
            }
            if (!$cc_candidate) { continue; }                       // 名单外的项静默丢弃（不阻断其它字段）
            if ($candidate && (string) $cc_candidate['value'] === (string) $candidate['value']) { continue; }
            if ((int) $cc_candidate['user_id'] > 0) {
                if ($candidate && (int) $cc_candidate['user_id'] === (int) $candidate['user_id']) { continue; }
                $cc_ids[] = (int) $cc_candidate['user_id'];
            }
            if ((int) $cc_candidate['person_id'] > 0) { $cc_person_ids[] = (int) $cc_candidate['person_id']; }
            $cc_names[] = (string) $cc_candidate['name'];
        }
        }
        $cc_ids        = array_values(array_unique($cc_ids));
        $cc_person_ids = array_values(array_unique($cc_person_ids));
        $cc_names      = array_values(array_unique(array_filter($cc_names, 'strlen')));

        /* ── ④ 差量计算 + 写库 + 审计（只写变化项） ─────────────────────────── */
        $remark  = $has('remark') ? sanitize_textarea_field((string) $fields['remark']) : (string) $doc['remark'];
        $changed = array();

        $new_reviewer_id   = $candidate ? (int) $candidate['user_id'] : (int) $doc['reviewer_id'];
        $new_reviewer_pid  = $candidate ? (int) $candidate['person_id'] : (int) $doc['reviewer_person_id'];
        if ($new_reviewer_id !== (int) $doc['reviewer_id'] || $new_reviewer_pid !== (int) $doc['reviewer_person_id']) {
            $old_label = zw_doc_flow_person_label($doc);
            update_post_meta($doc_id, '_zw_doc_reviewer_id', $new_reviewer_id);
            update_post_meta($doc_id, '_zw_doc_reviewer_person_id', $new_reviewer_pid);
            $new_label = ($new_reviewer_id > 0) ? zw_doc_user_label($new_reviewer_id) : (string) $candidate['name'];
            $changed[] = '审核人：' . $old_label . ' → ' . $new_label;
            // 被换下的原审核人 ⇒ 历史参与人（保留可见）
            if ((int) $doc['reviewer_id'] > 0 && (int) $doc['reviewer_id'] !== $new_reviewer_id) {
                zw_doc_flow_remember_participant($doc_id, array((int) $doc['reviewer_id']));
            }
        }
        if ($cc_ids !== $current_cc || $cc_person_ids !== array_map('intval', (array) $doc['cc_person_ids'])) {
            $old_cc = zw_doc_flow_cc_labels($doc);
            update_post_meta($doc_id, '_zw_doc_cc_ids', $cc_ids);
            update_post_meta($doc_id, '_zw_doc_cc_person_ids', $cc_person_ids);
            update_post_meta($doc_id, '_zw_doc_cc_names', $cc_names);
            $changed[] = '抄送人：' . (empty($old_cc) ? '—' : implode('、', $old_cc)) . ' → ' . (empty($cc_names) ? '—' : implode('、', $cc_names));
            $removed = array_values(array_diff($current_cc, $cc_ids));
            if (!empty($removed)) {
                zw_doc_flow_remember_participant($doc_id, $removed);
            }
        }
        if ($leader_name !== (string) $doc['leader_name']) {
            update_post_meta($doc_id, '_zw_doc_leader_name', $leader_name);
            $changed[] = '分管领导：' . ($doc['leader_name'] !== '' ? $doc['leader_name'] : '—') . ' → ' . ($leader_name !== '' ? $leader_name : '—');
        }
        if ($remark !== (string) $doc['remark']) {
            update_post_meta($doc_id, '_zw_doc_remark', $remark);
            $changed[] = '备注：' . ($doc['remark'] !== '' ? $doc['remark'] : '—') . ' → ' . ($remark !== '' ? $remark : '—');
        }

        if (!empty($changed)) {
            update_post_meta($doc_id, '_zw_doc_updated_by', $actor);
            update_post_meta($doc_id, '_zw_doc_updated_at', current_time('mysql'));
            // 逐字段审计：事件表已有 event_type / actor_id / note，note 记「字段：旧 → 新」+ 原因。
            if (function_exists('zw_doc_log_event')) {
                foreach ($changed as $line) {
                    zw_doc_log_event($doc_id, 'flow_field_changed', $actor, '', '', $line . $note_suffix);
                }
            }
        }

        return array('changed' => $changed);
    }
}

if (!function_exists('zw_doc_flow_remember_participant')) {
    /**
     * 把用户追加进「历史参与人」名单（只增不减；TASK-1350 用户拍板：被换下者保留可见）。
     *
     * @param int   $doc_id 发文 ID。
     * @param int[] $user_ids 用户 ID 列表。
     * @return int[] 追加后的完整名单。
     */
    function zw_doc_flow_remember_participant($doc_id, $user_ids) {
        $doc_id   = (int) $doc_id;
        $user_ids = array_values(array_filter(array_map('absint', (array) $user_ids)));
        if ($doc_id <= 0 || empty($user_ids)) {
            return array();
        }
        $current = array_values(array_filter(array_map('absint', (array) get_post_meta($doc_id, '_zw_doc_flow_history_ids', true))));
        $merged  = array_values(array_unique(array_merge($current, $user_ids)));
        if ($merged !== $current) {
            update_post_meta($doc_id, '_zw_doc_flow_history_ids', $merged);
        }
        return $merged;
    }
}

if (!function_exists('zw_doc_attachment_names')) {
    /**
     * 附件的**文件名**列表（TASK-1363：编辑态只读清单展示用）。
     *
     * 只读；拿不到磁盘路径时回退附件标题；两者皆无则跳过（不臆造文件名）。
     *
     * @param array $doc 发文记录。
     * @return string[]
     */
    function zw_doc_attachment_names($doc) {
        $ids = (is_array($doc) && !empty($doc['attachment_ids']))
            ? array_values(array_filter(array_map('absint', (array) $doc['attachment_ids'])))
            : array();
        $out = array();
        foreach ($ids as $id) {
            $name = '';
            if (function_exists('get_attached_file')) {
                $path = (string) get_attached_file($id);
                if ($path !== '') {
                    $name = basename($path);
                }
            }
            if ($name === '' && function_exists('get_the_title')) {
                $name = (string) get_the_title($id);
            }
            if ($name !== '') {
                $out[] = $name;
            }
        }
        return $out;
    }
}

if (!function_exists('zw_doc_format_number')) {
    /**
     * 生成完整文号：中传函〔2026〕86号。
     *
     * 格式口径（TASK-1263 用户拍板）：**沿用「机关前缀 + 文种 + 〔年〕序号号」**，
     * 不加「（）字」包装；历史导入路径同样保持该格式。
     *
     * @param array  $settings 文号规则。
     * @param string $type     文种。
     * @param int    $year     年份。
     * @param int    $seq      序号。
     * @return string
     */
    function zw_doc_format_number($settings, $type, $year, $seq) {
        $prefix = isset($settings['prefix']) && $settings['prefix'] !== '' ? (string) $settings['prefix'] : '中传';
        return $prefix . $type . '〔' . (int) $year . '〕' . (int) $seq . '号';
    }
}

if (!function_exists('zw_doc_number_year')) {
    /**
     * 从完整文号中提取年份。
     *
     * @param string $number 完整文号。
     * @return int
     */
    function zw_doc_number_year($number) {
        if (preg_match('/〔(\d{4})〕/u', (string) $number, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }
}

if (!function_exists('zw_doc_number_start_seq')) {
    /**
     * 获取某年份 + 文种的自动取号起始序号。
     *
     * 支持通过 start_seq_overrides 配置特定年份/文种的下一号下限，例如：
     * array('2026|函' => 94)。
     *
     * @param int        $year     年份。
     * @param string     $type     文种。
     * @param array|null $settings 文号设置。
     * @return int
     */
    function zw_doc_number_start_seq($year, $type, $settings = null) {
        if ($settings === null) {
            $settings = zw_doc_number_settings();
        }
        $start = isset($settings['start_seq']) ? (int) $settings['start_seq'] : 1;
        $overrides = isset($settings['start_seq_overrides']) && is_array($settings['start_seq_overrides']) ? $settings['start_seq_overrides'] : array();
        $key = (int) $year . '|' . (string) $type;
        if (isset($overrides[$key]) && (int) $overrides[$key] > 0) {
            $start = max($start, (int) $overrides[$key]);
        }
        return max(1, $start);
    }
}

if (!function_exists('zw_doc_validate_year')) {
    /**
     * 校验年份。
     *
     * @param mixed $year 年份。
     * @return int|false
     */
    function zw_doc_validate_year($year) {
        $year = (int) $year;
        return ($year >= 1900 && $year <= 2200) ? $year : false;
    }
}

if (!function_exists('zw_doc_validate_doc_type')) {
    /**
     * 校验文种是否在配置字典中。
     *
     * @param string     $type     文种。
     * @param array|null $settings 文号规则。
     * @return string|false
     */
    function zw_doc_validate_doc_type($type, $settings = null) {
        if ($settings === null) {
            $settings = zw_doc_number_settings();
        }
        $type = sanitize_text_field((string) $type);
        return in_array($type, $settings['doc_types'], true) ? $type : false;
    }
}

if (!function_exists('zw_doc_get_admin_users')) {
    /**
     * 获取全部仪表盘管理员。
     *
     * @return WP_User[]
     */
    function zw_doc_get_admin_users() {
        global $wpdb;

        // **性能修复（TASK-783 实测）**：原实现用 `get_users(['number' => -1])` 把**全站所有用户**
        // 连同 user meta 一次性加载后再筛 `manage_options`。本站 1471 个用户，
        // 单这一步就占用 **约 21.6MB**，把「新建发文 / 在线撰写」页的使用峰值推到 **121.1MB**
        // （PHP-FPM 上限 128MB），**仅剩约 7MB 余量**，随时可能白屏 OOM。
        // 现改为**只查询具备管理权限的用户**，并设上限兜底。
        $candidates = get_users(array(
            'capability' => 'manage_options', // WP_User_Query 支持；仅返回命中者，不再全表加载
            'number'     => 200,              // 兜底上限：异常站点也不会因此爆内存
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ));

        // 兜底：若站点 WP 版本不支持 capability 参数（返回全量），仍以 user_can 兜住正确性，
        // 但此种情况不应发生；此处保持结果语义与旧实现完全一致。
        $out = array();
        foreach ((array) $candidates as $user) {
            if ($user instanceof WP_User && user_can($user, 'manage_options')) {
                $out[] = $user;
            }
        }

        return $out;
    }
}

if (!function_exists('zw_doc_user_label')) {
    /**
     * 用户显示名。
     *
     * @param int $user_id 用户 ID。
     * @return string
     */
    function zw_doc_user_label($user_id) {
        $user_id = (int) $user_id;
        // TASK-1379（用户口径「发起人、审核人要用**姓名**」，生产实测）：
        // 账号 `display_name` 可能是**昵称**——如滕小丽的账号显示名是「**紫气东来**」，
        // 而**组织架构人员记录**（`zw_person_user_id` 一对一映射）的标题才是**姓名**「滕小丽」。
        // ⇒ **优先**取组织架构人员姓名；取不到才回落 `display_name`，再回落 `user_login`。
        // 复用既有反查 `zw_org_person_user_conflict()`（按 uid 取已绑定人员 ID），**不新造第二套映射**。
        if ($user_id > 0 && function_exists('zw_org_person_user_conflict')) {
            $person_id = (int) zw_org_person_user_conflict($user_id);
            if ($person_id > 0) {
                $person_name = (string) get_the_title($person_id);
                if ($person_name !== '') {
                    return $person_name;
                }
            }
        }
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return '—';
        }
        return $user->display_name !== '' ? $user->display_name : $user->user_login;
    }
}

if (!function_exists('zw_doc_status_label')) {
    /**
     * 发文状态中文名。
     *
     * @param string $status 状态。
     * @return string
     */
    function zw_doc_status_label($status) {
        $map = array(
            'draft'     => '草稿',
            'pending'   => '待审核',
            'approved'  => '已通过',
            'rejected'  => '已驳回',
            'cancelled' => '已撤回',
        );
        return isset($map[$status]) ? $map[$status] : (string) $status;
    }
}

if (!function_exists('zw_doc_year_options')) {
    /**
     * 年份下拉选项：当前年 ±1，合并已有配置/台账年份。
     *
     * @return int[]
     */
    function zw_doc_year_options() {
        $current = (int) current_time('Y');
        $years   = array($current - 1, $current, $current + 1);
        $saved   = zw_doc_number_settings();
        if (!empty($saved['default_year'])) {
            $years[] = (int) $saved['default_year'];
        }
        $years = array_values(array_unique(array_filter($years)));
        sort($years);
        return $years;
    }
}

if (!function_exists('zw_doc_install_tables')) {
    /**
     * 安装自定义表。幂等，可重复运行。
     *
     * @return void
     */
    function zw_doc_install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $numbers         = zw_doc_table('numbers');
        $counters        = zw_doc_table('number_counters');
        $events          = zw_doc_table('events');
        $downloads       = zw_doc_table('download_logs');
        $copy_numbers    = zw_doc_table('copy_numbers');

        $sql = array();

        $sql[] = "CREATE TABLE {$numbers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            doc_year smallint(5) unsigned NOT NULL,
            doc_type varchar(20) NOT NULL,
            seq int(10) unsigned NOT NULL,
            full_number varchar(100) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'claimed',
            document_id bigint(20) unsigned NOT NULL DEFAULT 0,
            claim_token varchar(64) NOT NULL DEFAULT '',
            reserved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reserved_note varchar(255) NOT NULL DEFAULT '',
            reserved_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            operator_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reason varchar(255) NOT NULL DEFAULT '',
            created_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY full_number (full_number),
            UNIQUE KEY year_type_seq (doc_year,doc_type,seq),
            KEY status_type_year (status,doc_type,doc_year),
            KEY document_id (document_id),
            KEY claim_token (claim_token)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$counters} (
            doc_year smallint(5) unsigned NOT NULL,
            doc_type varchar(20) NOT NULL,
            last_seq int(10) unsigned NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (doc_year,doc_type)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            document_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(40) NOT NULL DEFAULT '',
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            from_status varchar(20) NOT NULL DEFAULT '',
            to_status varchar(20) NOT NULL DEFAULT '',
            note text,
            created_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY document_time (document_id,created_at),
            KEY event_type (event_type),
            KEY actor_id (actor_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$downloads} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            document_id bigint(20) unsigned NOT NULL DEFAULT 0,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            file_name varchar(255) NOT NULL DEFAULT '',
            result varchar(20) NOT NULL DEFAULT 'success',
            downloaded_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY document_time (document_id,downloaded_at),
            KEY user_time (user_id,downloaded_at)
        ) {$charset_collate};";

        // TASK-1867（CHG-362）：份号台账 —— 一件公文一个份号；`copy_no` 唯一索引是并发兜底。
        // 状态：reserved 预留 / used 已绑定 / skipped 已跳过（三者**占用**）/ void 已作废（**不占用**）。
        $sql[] = "CREATE TABLE {$copy_numbers} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            copy_no varchar(16) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'used',
            document_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reserved_note varchar(255) NOT NULL DEFAULT '',
            reserved_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reserved_at datetime DEFAULT NULL,
            operator_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reason varchar(255) NOT NULL DEFAULT '',
            created_at datetime DEFAULT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY copy_no (copy_no),
            KEY status (status),
            KEY document_id (document_id)
        ) {$charset_collate};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        update_option('zw_doc_db_version', ZW_DOC_MVP_VERSION, false);
    }
}

if (!function_exists('zw_doc_maybe_install_tables')) {
    /**
     * 功能开启后按版本自动建表。
     *
     * @return void
     */
    function zw_doc_maybe_install_tables() {
        if (!zw_doc_is_enabled()) {
            return;
        }
        if (get_option('zw_doc_db_version') === ZW_DOC_MVP_VERSION) {
            return;
        }
        zw_doc_install_tables();
    }
}

if (!function_exists('zw_doc_admin_notice')) {
    /**
     * 输出后台提示。
     *
     * @param string $message 提示文字。
     * @param string $type    success|error|warning|info。
     * @return void
     */
    function zw_doc_admin_notice($message, $type = 'success') {
        $class = in_array($type, array('success', 'error', 'warning', 'info'), true) ? $type : 'info';
        echo '<div class="notice notice-' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}

if (!function_exists('zw_doc_reviewer_pool_note')) {
    /**
     * 审核人下拉的补充说明（TASK-1362）：当候选来自组织架构部门**且「自动审核」关闭**时，
     * 未绑定系统账号者已被 `zw_doc_flow_candidates()` **排除**，此处如实告知，避免用户以为"人少了"。
     *
     * @param array|null $flow 流转配置（缺省自行读取）。
     * @return string 无需要时返回空串。
     */
    function zw_doc_reviewer_pool_note($flow = null) {
        // TASK-1378：候选已改为**全量显示**（含未绑账号者，仅作内部记录）⇒ 不再需要"仅列出已绑定账号者"的说明。
        // 保留函数与签名以兼容既有调用点，恒返回空串。
        return '';
    }
}

if (!function_exists('zw_doc_notice_level')) {
    /**
     * 由提示文案判定提示级别（**唯一出处**，TASK-1362 生产缺陷修复）。
     *
     * 背景：原先各页面用**少数几个关键词**（失败/不能/无权/不合法）就地嗅探 ⇒
     * 像「所选审核人**未绑定**系统账号，无法在系统内完成审核，请改选其他人员。」这种**驳回**文案
     * 一个关键词都不命中，被渲染成**绿色"成功"**提示（生产实测：用户因此以为提交成功、随后找不到记录）。
     *
     * 规则：命中「否定/驳回」词表 ⇒ `error`；命中「提醒」词 ⇒ `warning`；否则 `success`。
     *
     * @param string $message 提示文案。
     * @return string error|warning|success
     */
    function zw_doc_notice_level($message) {
        $message = (string) $message;
        $error_kw = array('失败', '不能', '不可', '不合法', '无效', '未绑定', '未通过', '请改选', '请选择', '请先',
                          '必须', '无权', '未授权', '已锁定', '锁定', '超出', '超过', '拒绝', '不存在', '未启用',
                          '重复', '到期', '过期', '未完成', '不足', '缺失', '错误', '冲突');
        foreach ($error_kw as $kw) {
            if (mb_strpos($message, $kw) !== false) {
                return 'error';
            }
        }
        foreach (array('注意', '提醒', '已保留', '未变化', '未发生') as $kw) {
            if (mb_strpos($message, $kw) !== false) {
                return 'warning';
            }
        }
        return 'success';
    }
}

if (!function_exists('zw_doc_current_url')) {
    /**
     * 当前后台页面 URL（不含一次性消息参数）。
     *
     * @param array $args 查询参数。
     * @return string
     */
    function zw_doc_current_url($args = array()) {
        $base = admin_url('admin.php');
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'zw-doc';
        $args = array_merge(array('page' => $page), $args);
        return add_query_arg($args, $base);
    }
}

add_action('admin_init', 'zw_doc_maybe_install_tables', 5);

if (!function_exists('zw_doc_is_super_admin')) {
    /**
     * 是否**超级管理员**（TASK-1386 ④：旧产物版本列表与旧版本下载仅超管可见）。
     *
     * **口径依据（复用项目既有判定，不新造第二套）**：全站超管判定一贯为能力位
     * `manage_options`（见 `inc/activity/auth.php`「manage_options（超级管理员）」、
     * `inc/admin/*` 系列页面的 `current_user_can('manage_options')`），本模块
     * `zw_doc_can_view_doc()` / `zw_doc_seal_can_use()` 亦以它作为最高权限放行位。
     *
     * 可通过过滤器 `zw_doc_is_super_admin` 显式改判（返回 bool 时生效），便于分级授权与单测。
     *
     * @param int $user_id 用户 ID（0 = 当前用户）。
     * @return bool
     */
    function zw_doc_is_super_admin($user_id = 0) {
        $uid = (int) $user_id;
        if ($uid <= 0 && function_exists('get_current_user_id')) {
            $uid = (int) get_current_user_id();
        }
        if (function_exists('apply_filters')) {
            $override = apply_filters('zw_doc_is_super_admin', null, $uid);
            if (is_bool($override)) {
                return $override;
            }
        }
        if ($uid > 0 && function_exists('user_can')) {
            return (bool) user_can($uid, 'manage_options');
        }
        if (function_exists('current_user_can')) {
            return (bool) current_user_can('manage_options');
        }

        return false;
    }
}

if (!function_exists('zw_doc_user_realname_verified')) {
    /**
     * 账号是否已完成中传网实名核验（铁律 21：实名强度唯一出处优先）。
     *
     * 用途：任字号发文的**任命对象可见性**只放行实名本人（用户 2026-10-30 拍板「做」）。
     * 判定链：`ZW_Realname::is_verified()`（canonical）→ `zw_branch_user_identity()['verified']`
     * → `get_user_meta('zw_real_verified')`；三者都不可用 ⇒ **不放行**（个人信息面 fail-closed）。
     *
     * @param int $user_id WP 用户 ID。
     * @return bool
     */
    function zw_doc_user_realname_verified($user_id) {
        $user_id = abs((int) $user_id);
        if ($user_id <= 0) {
            return false;
        }
        if (class_exists('ZW_Realname') && method_exists('ZW_Realname', 'is_verified')) {
            return (bool) ZW_Realname::is_verified($user_id);
        }
        if (function_exists('zw_branch_user_identity')) {
            $identity = (array) zw_branch_user_identity($user_id);
            if (array_key_exists('verified', $identity)) {
                return !empty($identity['verified']);
            }
        }
        if (function_exists('get_user_meta')) {
            return (bool) get_user_meta($user_id, 'zw_real_verified', true);
        }
        return false;
    }
}

if (!function_exists('zw_doc_appointee_ids')) {
    /**
     * 任命对象 uid 列表（从正文结构化 meta `appointments` 派生，**只读、不落库**）。
     *
     * 用户 2026-10-30 指令：「任命人员 的任字号 发文要选择 被任命人员的uid … 发文后这个针对 uid
     * 实名用户的任命 应该直接能 在 前端我的收文中能看到」；同日拍板「做」＝可见性按**实名**收口。
     * 因此本函数只返回**已实名核验**的 uid（判定唯一出处 `zw_doc_user_realname_verified()`）；
     * 未实名 / 无法判定实名的 uid 一律排除（失败即不可见，个人信息面 fail-closed）。
     *
     * 兼容：`appointments` 可能是 JSON 串或已解码数组；行内键兼容 `uid` / `user_id`。
     *
     * @param array $doc 发文记录（`zw_doc_get_document()` 结果或至少含 `body`）。
     * @return int[] 去重后的实名 uid 列表（无任命要素 / 未选账号 / 未实名 ⇒ 空数组）。
     */
    function zw_doc_appointee_ids($doc) {
        $doc  = is_array($doc) ? $doc : array();
        $body = isset($doc['body']) && is_array($doc['body']) ? $doc['body'] : array();
        $raw  = isset($body['meta']['appointments']) ? $body['meta']['appointments'] : null;
        if (is_string($raw)) {
            $raw = trim($raw);
            $raw = $raw === '' ? null : json_decode($raw, true);
        }
        if (!is_array($raw) || empty($raw['items']) || !is_array($raw['items'])) {
            return array();
        }
        $ids = array();
        foreach ($raw['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $uid = 0;
            foreach (array('uid', 'user_id') as $key) {
                if (!empty($item[$key])) {
                    $uid = abs((int) $item[$key]);
                    break;
                }
            }
            if ($uid > 0 && zw_doc_user_realname_verified($uid)) {
                $ids[] = $uid;
            }
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('zw_doc_can_view_doc')) {
    /**
     * 是否有权查看某篇发文（TASK-772 权限收敛）。
     *
     * 口径：仪表盘管理员（`manage_options`）**始终放行**（不锁死运维入口），其余按属主关系：
     * 作者 / 审核人 / 抄送人 / **任命对象**（正文 `appointments` 的**实名** uid；用户 2026-10-30 指令）。
     *
     * TASK-1386 ⑤：管理员判定改为**按传入 uid**（复用 canonical `zw_doc_is_dashboard_admin()`），
     * 并**保留**原有「当前用户是管理员」判定 ⇒ 行为对当前用户逐字等价，只是把「管理员可见」
     * 从「仅当前用户」推广到「被查询的那个 uid」——这正是「我的收文 所有仪表盘管理员皆可见」
     * 与服务端权威过滤所必需的（管理员视图不得依赖"当前用户恰好是管理员"这一隐式前提）。
     *
     * @param array $doc     发文记录（`zw_doc_get_document()` 结果）。
     * @param int   $user_id 用户 ID（0 = 当前用户）。
     * @return bool
     */
    function zw_doc_can_view_doc($doc, $user_id = 0) {
        if (!is_array($doc) || empty($doc['id'])) {
            return false;
        }
        $user_id = $user_id ? (int) $user_id : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        $is_admin = function_exists('zw_doc_is_dashboard_admin')
            ? zw_doc_is_dashboard_admin($user_id)
            : (function_exists('current_user_can') && current_user_can('manage_options'));
        if ($is_admin) {
            return true;
        }
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            return true;
        }
        if ($user_id <= 0) {
            return false;
        }
        if ((int) $doc['author_id'] === $user_id) {
            return true;
        }
        if (!empty($doc['reviewer_id']) && (int) $doc['reviewer_id'] === $user_id) {
            return true;
        }
        // TASK-1350（用户拍板）：改派/换抄送后，**被换下的原审核人·原抄送人保留可见**（只读）。
        // 名单只增不减，写入见 `zw_doc_update_flow()`；编辑与放行仍按当前状态/当前审核人判定，不受此影响。
        if (in_array($user_id, zw_doc_flow_history_ids($doc), true)) {
            return true;
        }
        // 用户 2026-10-30 指令：任字号发文的**任命对象**（正文 `appointments` 的 uid）是被明确指定的
        // 收件人，与抄送/审核人一样放行；uid 来自新建时选择的实名账号（服务端复核）。
        $zw_doc_appointees = isset($doc['appointee_ids']) ? (array) $doc['appointee_ids'] : zw_doc_appointee_ids($doc);
        if (!empty($zw_doc_appointees) && in_array($user_id, array_map('intval', $zw_doc_appointees), true)) {
            return true;
        }

        return !empty($doc['cc_ids']) && in_array($user_id, array_map('intval', (array) $doc['cc_ids']), true);
    }
}

if (!function_exists('zw_doc_can_edit_doc')) {
    /**
     * 是否有权编辑某篇发文的正文。
     *
     * 口径：作者本人或管理员；**已用印锁定一律拒绝**（锁定解除须走撤销用印）。
     *
     * @param array $doc     发文记录。
     * @param int   $user_id 用户 ID（0 = 当前用户）。
     * @return bool
     */
    function zw_doc_can_edit_doc($doc, $user_id = 0) {
        if (!is_array($doc) || empty($doc['id'])) {
            return false;
        }
        if (!empty($doc['locked'])) {
            return false;
        }
        $user_id = $user_id ? (int) $user_id : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            return true;
        }

        return $user_id > 0 && (int) $doc['author_id'] === $user_id;
    }
}

if (!function_exists('zw_doc_can_review_doc')) {
    /**
     * 是否有权审核某篇发文。
     *
     * @param array $doc     发文记录。
     * @param int   $user_id 用户 ID（0 = 当前用户）。
     * @return bool
     */
    function zw_doc_can_review_doc($doc, $user_id = 0) {
        if (!is_array($doc) || empty($doc['id'])) {
            return false;
        }
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            return true;
        }
        $user_id = $user_id ? (int) $user_id : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);

        return $user_id > 0 && !empty($doc['reviewer_id']) && (int) $doc['reviewer_id'] === $user_id;
    }
}

if (!function_exists('zw_doc_iso_to_cn_date')) {
    /**
     * ISO 日期 → 中文公文日期（阿拉伯数字、**月日不补零**）。
     *
     * TASK-1335：本函数是**唯一实现**（原先只在 `doc-composer.php` 内，导致
     * `doc-appoint.php` 的正文模板在“未加载 composer”的路径上拿不到它 ⇒ 同一要素两处口径）。
     * 这里放在**最早加载**的公共助手里，`doc-composer.php` 的同名包装与
     * `doc-appoint.php` 的正文模板都调用它，**不会再各写一套格式化规则**。
     *
     * - 严格 `yyyy-mm-dd`（也接受月/日不补零的写法）；
     * - 用 `checkdate()` 校验真实日期（`2026-13-45` 之流返回空串，由调用方回退原值）；
     * - 去前导零靠 `(int)` 转换天然完成（`2026年10月9日`，不是 `2026年10月09日`）。
     *
     * @param string $iso ISO 日期。
     * @return string 形如 `2026年10月9日`；无法解析时返回空串。
     */
    function zw_doc_iso_to_cn_date($iso) {
        $iso = trim((string) $iso);
        if ($iso === '' || !preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $iso, $m)) {
            return '';
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }

        return (int) $m[1] . '年' . (int) $m[2] . '月' . (int) $m[3] . '日';
    }
}

if (!function_exists('zw_doc_record_print_fields')) {
    /**
     * 版记「印发机关 / 印发日期」字段口径（TASK-1338 批次二 · D1 版记合并）。
     *
     * 用户拍板口径：以**公文要素为唯一真值源** —— 印发机关 ← 发文部门，印发日期 ← 发文日期。
     * 取值优先级：
     *  ① 正文 meta 里**已显式保存**的印发值最高（存量公文已有显式值**不覆盖**，与「存量不动」一致）；
     *  ② 否则取公文要素的发文部门 / 发文日期（发文日期为 ISO 时按公文日期格式转为中文、月日不补零）。
     * 表单只读回显、HTML 通道、DOCX 通道三处都经本函数取值，禁止各处自行拼接默认值（避免两通道分叉）。
     *
     * @param array  $meta       正文 meta（可含 print_org / print_date）。
     * @param string $department 公文要素「发文部门」。
     * @param string $issue_date 公文要素「发文日期」（ISO yyyy-mm-dd 或已是中文文本）。
     * @return array{print_org:string,print_date:string}
     */
    function zw_doc_record_print_fields($meta, $department, $issue_date) {
        $meta = is_array($meta) ? $meta : array();
        $org  = isset($meta['print_org']) ? trim((string) $meta['print_org']) : '';
        $date = isset($meta['print_date']) ? trim((string) $meta['print_date']) : '';

        if ($org === '') {
            $org = trim((string) $department);
        }
        if ($date === '') {
            $date = trim((string) $issue_date);
            if ($date !== '' && function_exists('zw_doc_iso_to_cn_date')) {
                $cn = (string) zw_doc_iso_to_cn_date($date);
                if ($cn !== '') {
                    $date = $cn;
                }
            }
        }

        return array('print_org' => $org, 'print_date' => $date);
    }
}
