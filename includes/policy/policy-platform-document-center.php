<?php
/**
 * AI 法务部 · 文档中心（SPEC-88 §6.1「唯一文本源」· TASK-1011 / 第 1 期）。
 *
 * 职责（用户口径：AI 法务部向用户交付最终结果，用户是唯一终审人）：
 *  - 看：文档列表 + 版本时间线 + 当前生效正文预览 + 与上一版 diff + 实际读源徽章（DB / legacy）；
 *  - 编辑：**新建版本**（整篇正文编辑，只 INSERT，不改生效版本）；
 *  - 终审：确认生效（**必须手输文档名**）、一键回退、归档导出 —— 仅 `manage_options`（用户本人）；
 *  - 法务账号（`tcpc_perm_legal`）可看可查，但**看不到也提交不了**终审类按钮（服务端同样拒绝）。
 *
 * 5 条机器护栏在本页的落点：①生效版本只读（无任何编辑生效版本的入口）②生效前展示 diff 与影响面
 * ③二次确认输入文档名 ④一键回退（新增回退版本）⑤审计（由引擎写入 `policy_audit_log`）+ 归档导出。
 *
 * 禁止：本页不直接写 SQL、不新造审计表、不引入全局 CSS、不改动既有签署/拦截/提醒语义。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

// 读源与版本模型（前端也会用到；后台侧防御性再引入一次，保证菜单独立可用）。
if (!function_exists('zw_legal_text_source')) {
    $zw_lt_engine = dirname(__DIR__) . '/policy-platform/legal-text-source.php';
    if (is_file($zw_lt_engine)) {
        require_once $zw_lt_engine;
    }
}

if (!defined('ZW_POLICY_DOC_CREATE_ACTION'))   { define('ZW_POLICY_DOC_CREATE_ACTION', 'zw_policy_document_create'); }
if (!defined('ZW_POLICY_DOC_ACTIVATE_ACTION')) { define('ZW_POLICY_DOC_ACTIVATE_ACTION', 'zw_policy_document_activate'); }
if (!defined('ZW_POLICY_DOC_ROLLBACK_ACTION')) { define('ZW_POLICY_DOC_ROLLBACK_ACTION', 'zw_policy_document_rollback'); }
if (!defined('ZW_POLICY_DOC_EXPORT_ACTION'))   { define('ZW_POLICY_DOC_EXPORT_ACTION', 'zw_policy_document_export'); }
if (!defined('ZW_POLICY_DOC_PUBLISH_ACTION'))  { define('ZW_POLICY_DOC_PUBLISH_ACTION', 'zw_policy_document_publish'); }

/* ───────────── 菜单 ───────────── */
add_action('admin_menu', 'zw_policy_document_center_menu', 62);
if (!function_exists('zw_policy_document_center_menu')) {
    /**
     * 挂到 AI 法务部父菜单（`zw-policy-platform`，能力位与父菜单一致）。
     *
     * @return void
     */
    function zw_policy_document_center_menu() {
        $parent = 'zw-policy-platform';
        add_submenu_page(
            $parent,
            '文档中心',
            '文档中心',
            'tcpc_perm_legal',
            'zw-policy-documents',
            'zw_policy_document_center_page'
        );
    }
}

if (!function_exists('zw_policy_document_center_types')) {
    /**
     * 文档类型字典（徽章展示用；TASK-1022 扩充规范类词表）。
     *
     * 单一出处：列表、详情、迁移脚本（`internal_spec`）与 2.4 实文档迁入均读此表。
     *
     * @return array<string,string>
     */
    function zw_policy_document_center_types() {
        return array(
            'internal_agreement' => '内部协议',
            'external_statement' => '对外声明',
            'internal_spec'      => '内部规范/制度',
            'charter'            => '章程',
            'management_rules'   => '管理办法/议事规则',
            'statement'          => '声明与指南',
            'policy_file'        => '政策文件',
        );
    }
}

if (!function_exists('zw_policy_document_center_type_display')) {
    /**
     * 文档类型的**展示口径**（TASK-1030）：`policy_file` 明确标注"依据文件·不参与签署"。
     *
     * 单一出处：列表与详情共用，避免两处各写一套文案。
     *
     * @param string $type doc_type。
     * @return string
     */
    function zw_policy_document_center_type_display($type) {
        $type  = (string) $type;
        $label = zw_policy_document_center_type_label($type);
        if ($type === 'policy_file') {
            return $label . '（依据文件·不参与签署）';
        }
        return $label;
    }
}

if (!function_exists('zw_policy_document_center_status_display')) {
    /**
     * 文档状态的**展示口径**（TASK-1030）：无生效版本的草稿标注"待补正文"。
     *
     * @param array      $doc     文档主档行。
     * @param array|null $current 当前生效版本行（无则 null）。
     * @return array{label:string,tone:string}
     */
    function zw_policy_document_center_status_display($doc, $current = null) {
        $status = trim((string) ($doc['status'] ?? ''));
        if ($status === '已生效' && $current) {
            return array('label' => '已生效', 'tone' => 'on');
        }
        if ($status === '已生效' && !$current) {
            return array('label' => '已生效（缺生效版本）', 'tone' => 'err');
        }
        return array('label' => '草稿（待补正文）', 'tone' => 'wait');
    }
}

if (!function_exists('zw_policy_document_center_spec_status_map')) {
    /**
     * `policy_spec.status` → 文档主档状态（TASK-1022 归并口径）。
     *
     * 只有 `已生效` 直接落为 `已生效`（并在迁移时激活 v1）；其余（含 `草稿`/空/未知）一律 `草稿`，不自动要求签署。
     *
     * @return array<string,string> 映射表
     */
    function zw_policy_document_center_spec_status_map() {
        return array(
            '已生效' => '已生效',
            '草稿'   => '草稿',
        );
    }
}

if (!function_exists('zw_policy_document_center_spec_status')) {
    /**
     * 归一 `policy_spec.status` 到文档主档状态（未知值 → 草稿，绝不臆造"已生效"）。
     *
     * @param string $spec_status spec 表状态值。
     * @return string
     */
    function zw_policy_document_center_spec_status($spec_status) {
        $map = zw_policy_document_center_spec_status_map();
        $key = trim((string) $spec_status);
        return isset($map[$key]) ? $map[$key] : '草稿';
    }
}

if (!function_exists('zw_policy_document_center_spec_mapping')) {
    /**
     * `zw_policy_spec` 字段 → 统一文本源模型的归并映射（TASK-1022：唯一出处）。
     *
     * 归并口径（"不留第三份文本存储"）：
     *   - 规范类**正文** → `zw_policy_document` + `zw_policy_document_version.body_html`（走版本链/生效/回退）；
     *   - **规则定义** → 既有 `zw_policy_rule`（执行规则库）；
     *   - **元数据** → 文档主档与版本元数据；
     *   - `zw_policy_spec` 表**不再是文本源**（保留结构，页面标注废弃）。
     *
     * 页面（派生资产库提示）与迁移脚本共用本表，禁止各自硬编码。
     *
     * @return array<string,array<string,string>> 每个 spec 字段：target（落点）+ note（口径）
     */
    function zw_policy_document_center_spec_mapping() {
        return array(
            'id'          => array('target' => 'zw_policy_document.id',              'note' => '沿用 spec id 作为文档 ID（幂等判定锚点）'),
            'name'        => array('target' => 'zw_policy_document.title',           'note' => '文档标题；同时作为激活时的二次确认名'),
            'version_no'  => array('target' => 'zw_policy_document_version.note',    'note' => '版本链版本号由引擎生成（v1…），spec 原版本号写入版本说明与审计'),
            'status'      => array('target' => 'zw_policy_document.status',          'note' => '已生效 → 已生效（迁移后激活 v1）；其余 → 草稿'),
            'spec_type'   => array('target' => 'zw_policy_document.doc_type',        'note' => '规范类统一归并为 internal_spec（原值写入 source_file 与审计）'),
            'description' => array('target' => 'zw_policy_document_version.body_html','note' => '正文；为空则拒绝迁移并提示补正文（不写任何行）'),
            'rules_json'  => array('target' => 'zw_policy_rule',                     'note' => '规则定义；列表则逐项一条规则，rule_code 前缀 SPEC-<id>-，未知字段写入审计 detail'),
            'create_time' => array('target' => 'zw_policy_document.create_time',     'note' => '仅新建主档时沿用 spec 创建时间'),
            'update_time' => array('target' => 'zw_policy_document.update_time',     'note' => '实际写入时间以引擎为准；spec 原值写入审计'),
        );
    }
}

if (!function_exists('zw_policy_document_center_type_label')) {
    /**
     * 文档类型中文标签（未知类型原样回显，不臆造）。
     *
     * @param string $type 类型标识。
     * @return string
     */
    function zw_policy_document_center_type_label($type) {
        $map = zw_policy_document_center_types();
        $type = (string) $type;
        return isset($map[$type]) ? $map[$type] : $type;
    }
}

if (!function_exists('zw_policy_document_center_source_badge')) {
    /**
     * 读源徽章：DB（唯一文本源已接管）或 legacy（当前仍走旧源）。
     *
     * @param string $doc_id 文档 ID。
     * @return string HTML
     */
    function zw_policy_document_center_source_badge($doc_id) {
        $source = function_exists('zw_legal_text_render_source') ? zw_legal_text_render_source($doc_id) : 'legacy';
        $label  = $source === 'db' ? 'DB' : 'legacy';
        $type   = $source === 'db' ? 'on' : 'off';
        if (function_exists('zw_policy_platform_badge')) {
            return zw_policy_platform_badge($type, $label);
        }
        return '<code>' . esc_html($label) . '</code>';
    }
}

if (!function_exists('zw_policy_document_center_notice')) {
    /**
     * PRG 回执（`zw_msg` 查询参数 → 顶部提示条）。
     *
     * @return void
     */
    function zw_policy_document_center_notice() {
        $msg = isset($_GET['zw_msg']) ? sanitize_key(wp_unslash((string) $_GET['zw_msg'])) : '';
        if ($msg === '') { return; }
        $map = array(
            'created'         => array('notice-success', '已新建版本（未生效）：请核对差异后确认生效。'),
            'activated'       => array('notice-success', '已确认生效：该版本已成为唯一文本源口径的当前版本，并已写审计。'),
            'rolled_back'     => array('notice-success', '已回退：系统新增了一个回退版本并生效，历史版本一行未删。'),
            'exported'        => array('notice-success', '已导出只读归档（DB → 归档单向）。'),
            'published'       => array('notice-success', '已发布到页面：生效版本正文已写入该文档的 page_slug 页面。'),
            'publish_noop'    => array('notice-warning', '无需变更：页面与生效版本逐字一致。'),
            'publish_internal' => array('notice-error', '拒绝：internal（受限）文档不得发布到公开页面。'),
            'publish_no_slug' => array('notice-error', '该文档未配置 page_slug，无法发布到页面。'),
            'publish_no_page' => array('notice-error', '未找到 page_slug 对应页面。'),
            'publish_failed'  => array('notice-error', '发布失败（无生效版本 / 页面写入失败），详见审计与错误日志。'),
            'no_change'       => array('notice-warning', '未创建版本：正文与上一版完全一致（哈希相同）。'),
            'empty_body'      => array('notice-warning', '未创建版本：正文为空。'),
            'confirm_mismatch' => array('notice-error', '二次确认失败：文档名不一致，操作已取消。'),
            'hash_mismatch'   => array('notice-error', '拒绝生效：版本正文哈希与记录不一致（疑似被篡改）。'),
            'no_doc'          => array('notice-error', '文档主档不存在。'),
            'no_version'      => array('notice-error', '目标版本不存在。'),
            'forbidden'       => array('notice-error', '无权限：确认生效 / 回退 / 导出仅站点管理员可操作。'),
            'audit_unavailable' => array('notice-error', '审计组件不可用，已拒绝本次操作（宁可不生效，也不留无审计的生效）。'),
            'error'           => array('notice-error', '操作失败，请查看审计与错误日志。'),
        );
        if (!isset($map[$msg])) { return; }
        $text = $map[$msg][1];
        if ($msg === 'exported') {
            $rel = isset($_GET['zw_archive']) ? sanitize_text_field(wp_unslash((string) $_GET['zw_archive'])) : '';
            if ($rel !== '') { $text .= '　路径：' . $rel; }
        }
        echo '<div class="notice ' . esc_attr($map[$msg][0]) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }
}

if (!function_exists('zw_policy_document_center_redirect')) {
    /**
     * 统一回跳详情页（PRG）。
     *
     * @param string $doc_id 文档 ID。
     * @param string $msg    回执键。
     * @param array  $extra  额外查询参数。
     * @return void
     */
    function zw_policy_document_center_redirect($doc_id, $msg, $extra = array()) {
        $args = array('page' => 'zw-policy-documents', 'zw_msg' => (string) $msg);
        if ((string) $doc_id !== '') { $args['doc'] = (string) $doc_id; }
        foreach ((array) $extra as $k => $v) { $args[(string) $k] = (string) $v; }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}

if (!function_exists('zw_policy_document_center_error_msg')) {
    /**
     * `WP_Error` → 回执键（白名单，未知一律 `error`）。
     *
     * @param WP_Error $error 错误对象。
     * @return string
     */
    function zw_policy_document_center_error_msg($error) {
        $allowed = array(
            'zw_legal_text_no_change', 'zw_legal_text_empty_body', 'zw_legal_text_no_doc',
            'zw_legal_text_no_version', 'zw_legal_text_confirm_mismatch', 'zw_legal_text_hash_mismatch',
            'zw_legal_text_forbidden', 'zw_legal_text_audit_unavailable',
        );
        $code = is_object($error) && method_exists($error, 'get_error_code') ? (string) $error->get_error_code() : '';
        if (!in_array($code, $allowed, true)) { return 'error'; }
        return str_replace('zw_legal_text_', '', $code);
    }
}

/* ───────────── 写操作（admin_post + nonce + 终审判据） ───────────── */

/** 新建版本（整篇编辑；只 INSERT）。 */
add_action('admin_post_' . ZW_POLICY_DOC_CREATE_ACTION, 'zw_policy_document_handle_create');
if (!function_exists('zw_policy_document_handle_create')) {
    /**
     * @return void
     */
    function zw_policy_document_handle_create() {
        if (!function_exists('zw_legal_text_can_finalize') || !zw_legal_text_can_finalize()) {
            wp_die('无权限：仅站点管理员可编辑文档正文。', '', array('response' => 403));
        }
        check_admin_referer(ZW_POLICY_DOC_CREATE_ACTION);
        $doc_id = isset($_POST['doc']) ? sanitize_text_field(wp_unslash((string) $_POST['doc'])) : '';
        $body   = isset($_POST['body']) ? (string) wp_unslash($_POST['body']) : '';
        $note   = isset($_POST['note']) ? sanitize_text_field(wp_unslash((string) $_POST['note'])) : '';
        if (!function_exists('zw_legal_text_create_version')) {
            zw_policy_document_center_redirect($doc_id, 'error');
        }
        $result = zw_legal_text_create_version($doc_id, $body, $note, wp_get_current_user()->user_login);
        if (is_wp_error($result)) {
            zw_policy_document_center_redirect($doc_id, zw_policy_document_center_error_msg($result));
        }
        zw_policy_document_center_redirect($doc_id, 'created');
    }
}

/** 确认生效（二次确认 + 审计）。 */
add_action('admin_post_' . ZW_POLICY_DOC_ACTIVATE_ACTION, 'zw_policy_document_handle_activate');
if (!function_exists('zw_policy_document_handle_activate')) {
    /**
     * @return void
     */
    function zw_policy_document_handle_activate() {
        if (!function_exists('zw_legal_text_can_finalize') || !zw_legal_text_can_finalize()) {
            wp_die('无权限：确认生效仅站点管理员可操作。', '', array('response' => 403));
        }
        check_admin_referer(ZW_POLICY_DOC_ACTIVATE_ACTION);
        $doc_id  = isset($_POST['doc']) ? sanitize_text_field(wp_unslash((string) $_POST['doc'])) : '';
        $version = isset($_POST['version']) ? sanitize_text_field(wp_unslash((string) $_POST['version'])) : '';
        $confirm = isset($_POST['confirm_title']) ? sanitize_text_field(wp_unslash((string) $_POST['confirm_title'])) : '';
        if (!function_exists('zw_legal_text_activate')) {
            zw_policy_document_center_redirect($doc_id, 'error');
        }
        $result = zw_legal_text_activate($doc_id, $version, wp_get_current_user()->user_login, $confirm);
        if (is_wp_error($result)) {
            zw_policy_document_center_redirect($doc_id, zw_policy_document_center_error_msg($result));
        }
        zw_policy_document_center_redirect($doc_id, 'activated');
    }
}

/** 一键回退（新增回退版本）。 */
add_action('admin_post_' . ZW_POLICY_DOC_ROLLBACK_ACTION, 'zw_policy_document_handle_rollback');
if (!function_exists('zw_policy_document_handle_rollback')) {
    /**
     * @return void
     */
    function zw_policy_document_handle_rollback() {
        if (!function_exists('zw_legal_text_can_finalize') || !zw_legal_text_can_finalize()) {
            wp_die('无权限：回退仅站点管理员可操作。', '', array('response' => 403));
        }
        check_admin_referer(ZW_POLICY_DOC_ROLLBACK_ACTION);
        $doc_id  = isset($_POST['doc']) ? sanitize_text_field(wp_unslash((string) $_POST['doc'])) : '';
        $target  = isset($_POST['target']) ? sanitize_text_field(wp_unslash((string) $_POST['target'])) : '';
        $confirm = isset($_POST['confirm_title']) ? sanitize_text_field(wp_unslash((string) $_POST['confirm_title'])) : '';
        if (!function_exists('zw_legal_text_rollback')) {
            zw_policy_document_center_redirect($doc_id, 'error');
        }
        $result = zw_legal_text_rollback($doc_id, $target, wp_get_current_user()->user_login, $confirm);
        if (is_wp_error($result)) {
            zw_policy_document_center_redirect($doc_id, zw_policy_document_center_error_msg($result));
        }
        zw_policy_document_center_redirect($doc_id, 'rolled_back');
    }
}

/** 归档导出（DB → git 只读镜像；仅终审人）。 */
add_action('admin_post_' . ZW_POLICY_DOC_EXPORT_ACTION, 'zw_policy_document_handle_export');
if (!function_exists('zw_policy_document_handle_export')) {
    /**
     * @return void
     */
    function zw_policy_document_handle_export() {
        if (!function_exists('zw_legal_text_can_finalize') || !zw_legal_text_can_finalize()) {
            wp_die('无权限：归档导出仅站点管理员可操作。', '', array('response' => 403));
        }
        check_admin_referer(ZW_POLICY_DOC_EXPORT_ACTION);
        $doc_id  = isset($_POST['doc']) ? sanitize_text_field(wp_unslash((string) $_POST['doc'])) : '';
        $version = isset($_POST['version']) ? sanitize_text_field(wp_unslash((string) $_POST['version'])) : '';
        if (!function_exists('zw_legal_text_export_archive')) {
            zw_policy_document_center_redirect($doc_id, 'error');
        }
        $result = zw_legal_text_export_archive($doc_id, $version);
        if (is_wp_error($result)) {
            zw_policy_document_center_redirect($doc_id, zw_policy_document_center_error_msg($result));
        }
        zw_policy_document_center_redirect($doc_id, 'exported', array('zw_archive' => (string) $result));
    }
}

/** 发布到页面（TASK-1036：复用 canonical 发布器；仅终审人）。 */
add_action('admin_post_' . ZW_POLICY_DOC_PUBLISH_ACTION, 'zw_policy_document_handle_publish');
if (!function_exists('zw_policy_document_handle_publish')) {
    /**
     * @return void
     */
    function zw_policy_document_handle_publish() {
        if (!function_exists('zw_legal_text_can_finalize') || !zw_legal_text_can_finalize()) {
            wp_die('无权限：发布到页面仅站点管理员可操作。', '', array('response' => 403));
        }
        check_admin_referer(ZW_POLICY_DOC_PUBLISH_ACTION);
        $doc_id = isset($_POST['doc']) ? sanitize_text_field(wp_unslash((string) $_POST['doc'])) : '';
        if (!function_exists('zw_legal_text_publish_to_page')) {
            zw_policy_document_center_redirect($doc_id, 'publish_failed');
        }
        $result = zw_legal_text_publish_to_page($doc_id, false, wp_get_current_user()->user_login);
        $reason = (string) ($result['reason'] ?? '');
        $map = array(
            'published'  => 'published',
            'noop'       => 'publish_noop',
            'internal'   => 'publish_internal',
            'no_slug'    => 'publish_no_slug',
            'no_page'    => 'publish_no_page',
        );
        zw_policy_document_center_redirect($doc_id, isset($map[$reason]) ? $map[$reason] : 'publish_failed');
    }
}

/* ───────────── 页面 ───────────── */
if (!function_exists('zw_policy_document_center_page')) {
    /**
     * 文档中心主页面（列表 / 详情）。
     *
     * @return void
     */
    function zw_policy_document_center_page() {
        if (!function_exists('zw_legal_text_can_view') || !zw_legal_text_can_view()) {
            wp_die('无权限查看文档中心。');
        }
        if (!function_exists('zw_policy_platform_page_header')) {
            echo '<div class="wrap"><p>政策平台模块未加载。</p></div>';
            return;
        }
        $finalize = function_exists('zw_legal_text_can_finalize') && zw_legal_text_can_finalize();
        zw_policy_platform_page_header(
            '文档中心',
            '统一文本源：文档正文存 AI 法务部数据库，版本只增不减，已生效版本只读；确认生效 / 回退 / 归档导出仅站点管理员可操作。'
        );
        zw_policy_document_center_notice();

        $switch = function_exists('zw_legal_text_source') ? zw_legal_text_source() : 'legacy';
        $switch_label = $switch === 'db' ? 'db（DB 为渲染源）' : 'legacy（现状：协议走条款表、隐私政策走文件）';
        echo '<div class="zw-adm-card" style="padding:12px 14px;margin-bottom:14px">'
            . '<p style="margin:0;font-size:13px"><strong>读源开关</strong> <code>zw_legal_text_source</code> = <code>' . esc_html($switch_label) . '</code>'
            . '　｜　<strong>你的权限</strong>：' . ($finalize
                ? '<span style="color:#1b5e20">可终审（manage_options）</span>'
                : '<span style="color:#8a6d00">只读（' . esc_html('tcpc_perm_legal') . '）；生效/回退/导出仅站点管理员</span>')
            . '　｜　<strong>归档方向</strong>：DB → <code>docs/legal/&lt;文档&gt;-&lt;版本&gt;.html</code>（只读镜像，禁止人工编辑）</p>'
            . '</div>';

        if (!function_exists('zw_legal_text_tables_ready') || !zw_legal_text_tables_ready()) {
            if (function_exists('zw_policy_platform_missing_notice')) { zw_policy_platform_missing_notice(); }
            echo '<div class="zw-adm-card" style="padding:14px"><p style="margin:0;color:#666">统一文本源数据表尚未初始化。请在站点根目录执行：<br>'
                . '<code>wp --allow-root eval-file wp-content/themes/tcpc/scripts/migrate-policy-document-center.php execute</code></p></div>';
            zw_policy_platform_page_footer();
            return;
        }

        $doc_id = isset($_GET['doc']) ? sanitize_text_field(wp_unslash((string) $_GET['doc'])) : '';
        if ($doc_id !== '') {
            $doc = zw_legal_text_document($doc_id);
            if ($doc) {
                zw_policy_document_center_detail($doc, $finalize);
                zw_policy_platform_page_footer();
                return;
            }
            echo '<div class="notice notice-warning"><p>未找到文档 <code>' . esc_html($doc_id) . '</code>，已返回列表。</p></div>';
        }
        zw_policy_document_center_list();
        zw_policy_platform_page_footer();
    }
}

if (!function_exists('zw_policy_document_center_list')) {
    /**
     * 文档列表视图。
     *
     * @return void
     */
    function zw_policy_document_center_list() {
        $docs = zw_legal_text_documents_visible();
        if (!$docs) {
            echo '<div class="zw-adm-card" style="padding:14px"><p style="margin:0;color:#666">尚未迁移任何文档。执行迁移脚本后可在此编辑与生效。</p></div>';
            return;
        }
        $rows = array();
        foreach ($docs as $doc) {
            $id       = (string) ($doc['id'] ?? '');
            $current  = function_exists('zw_legal_text_current') ? zw_legal_text_current($id) : null;
            $versions = zw_legal_text_versions($id);
            $link     = add_query_arg(array('page' => 'zw-policy-documents', 'doc' => $id), admin_url('admin.php'));
            // TASK-1030：状态展示口径（无生效版本的草稿 → 「草稿（待补正文）」）。
            $status_disp = zw_policy_document_center_status_display($doc, $current);
            $status_html = function_exists('zw_policy_platform_badge')
                ? zw_policy_platform_badge($status_disp['tone'], $status_disp['label'])
                : esc_html($status_disp['label']);
            // TASK-1041：前三列的值本身就是**已拼好的 HTML**（标题链接 / 类型徽章 / 状态徽章）。
            // `zw_policy_platform_render_table()` 对**普通字符串单元格**会再走一次 esc_html()，
            // 必须声明为 array('html'=>…)（渲染时过 wp_kses_post），否则页面会把标签当文本打印（二次转义）。
            $title_html = '<a href="' . esc_url($link) . '"><strong>' . esc_html((string) ($doc['title'] ?? $id)) . '</strong></a>'
                . '<div style="color:#888;font-size:12px"><code>' . esc_html($id) . '</code>'
                . ((string) ($doc['page_slug'] ?? '') !== '' ? '　渲染落点：/' . esc_html((string) $doc['page_slug']) : '')
                . '</div>';
            $type_label = zw_policy_document_center_type_display((string) ($doc['doc_type'] ?? ''));
            $type_cell  = function_exists('zw_policy_platform_badge_cell')
                ? zw_policy_platform_badge_cell('info', $type_label)
                : array('html' => esc_html($type_label));
            $rows[] = array(
                array('html' => $title_html),
                $type_cell,
                array('html' => $status_html),
                esc_html((string) ($doc['current_version'] ?? '')),
                esc_html($current ? (string) ($current['effective_at'] ?? '') : '—'),
                array('html' => zw_policy_document_center_source_badge($id)),
                (string) count($versions) . ' 个版本',
            );
        }
        if (function_exists('zw_policy_platform_render_table')) {
            zw_policy_platform_render_table(array('文档', '类型', '状态', '当前版本', '生效时间', '读源', '版本'), $rows);
        }
    }
}

if (!function_exists('zw_policy_document_center_detail')) {
    /**
     * 单文档详情：版本时间线 + 当前正文预览 + diff + 新建版本 / 生效 / 回退 / 导出。
     *
     * @param array $doc      主档行。
     * @param bool  $finalize 是否具备终审权限。
     * @return void
     */
    function zw_policy_document_center_detail($doc, $finalize) {
        $doc_id   = (string) ($doc['id'] ?? '');
        $title    = (string) ($doc['title'] ?? '');
        $versions = zw_legal_text_versions($doc_id);
        $current  = zw_legal_text_current($doc_id);
        $latest   = $versions ? $versions[0] : null;

        echo '<h2 style="font-size:15px;margin:6px 0 8px">' . esc_html($title)
            . ' <code style="font-size:12px;color:#888">' . esc_html($doc_id) . '</code></h2>';
        echo '<p style="margin:0 0 12px;color:#666;font-size:12.5px">'
            . '类型：' . esc_html(zw_policy_document_center_type_display((string) ($doc['doc_type'] ?? '')))
            . '　状态：' . esc_html((string) ($doc['status'] ?? ''))
            . '　当前版本：' . esc_html((string) ($doc['current_version'] ?? '—'))
            . '　生效时间：' . esc_html($current ? (string) ($current['effective_at'] ?? '—') : '—')
            . '　读源：' . ($current ? esc_html(zw_legal_text_render_source($doc_id)) : '—')
            . ((string) ($doc['source_file'] ?? '') !== '' ? '　实物：' . esc_html((string) $doc['source_file']) : '')
            . '</p>';

        /* 发布到页面（TASK-1036：仅终审人；复用 canonical 发布器；internal 文档禁用） */
        $slug = trim((string) ($doc['page_slug'] ?? ''));
        $vis  = function_exists('zw_legal_text_visibility') ? zw_legal_text_visibility($doc_id) : 'public';
        if ($slug !== '') {
            echo '<div class="zw-adm-card" style="padding:12px 14px;margin:0 0 14px">';
            echo '<p style="margin:0 0 8px;font-size:13px"><strong>发布到页面</strong>：把<strong>生效版本</strong>正文写入 <code>/'
                . esc_html($slug) . '/</code>（逐字比对幂等；页面写入与自动发布/CLI 共用同一实现）。</p>';
            if ($vis === 'internal') {
                echo '<p style="margin:0;color:#8a6d00;font-size:12.5px">该文档为 <strong>internal（受限）</strong>，'
                    . '<strong>不得发布到公开页面</strong>：按钮已禁用。</p>';
            } elseif (!$finalize) {
                echo '<p style="margin:0;color:#8a6d00;font-size:12.5px">发布到页面仅站点管理员可操作。</p>';
            } else {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
                echo '<input type="hidden" name="action" value="' . esc_attr(ZW_POLICY_DOC_PUBLISH_ACTION) . '">';
                echo '<input type="hidden" name="doc" value="' . esc_attr($doc_id) . '">';
                wp_nonce_field(ZW_POLICY_DOC_PUBLISH_ACTION);
                echo '<button type="submit" class="button button-primary">发布到页面</button>';
                echo ' <span style="color:#666;font-size:12px;margin-left:6px">页面已一致时会提示"无需变更"（不写库）。</span>';
                echo '</form>';
            }
            echo '</div>';
        }

        /* 版本时间线 */
        $rows = array();
        foreach ($versions as $v) {
            $no    = (string) ($v['version_no'] ?? '');
            $eff   = (string) ($v['effective_at'] ?? '');
            $marks = array();
            if ($eff !== '') { $marks[] = '<span style="color:#1b5e20">已生效</span>'; }
            if (!empty($v['is_rollback'])) { $marks[] = '<span style="color:#8a6d00">回退版本</span>'; }
            if ($latest && $no === (string) ($latest['version_no'] ?? '')) { $marks[] = '<span style="color:#555">最新</span>'; }
            $actions = '';
            if ($finalize) {
                $export_url = wp_nonce_url(admin_url('admin-post.php?action=' . ZW_POLICY_DOC_EXPORT_ACTION . '&doc=' . rawurlencode($doc_id) . '&version=' . rawurlencode($no)), ZW_POLICY_DOC_EXPORT_ACTION);
                $actions = '<a class="button button-small" href="' . esc_url($export_url) . '">导出归档</a>';
            }
            $rows[] = array(
                esc_html($no),
                // TASK-1041：<code> 包裹的哈希是 HTML，不能当普通字符串（会被二次转义）。
                array('html' => '<code>' . esc_html(substr((string) ($v['body_hash'] ?? ''), 0, 12)) . '</code>'),
                (string) (int) ($v['body_chars'] ?? 0),
                esc_html((string) ($v['created_by'] ?? '')),
                esc_html((string) ($v['created_at'] ?? '')),
                esc_html($eff !== '' ? $eff : '—'),
                array('html' => implode('　', $marks)),
                esc_html((string) ($v['note'] ?? '')),
                array('html' => $actions),
            );
        }
        echo '<h3 style="font-size:14px;margin:0 0 8px">版本时间线</h3>';
        if (function_exists('zw_policy_platform_render_table')) {
            zw_policy_platform_render_table(array('版本', '正文哈希', '字数', '创建人', '创建时间', '生效时间', '标记', '版本说明', '操作'), $rows);
        }

        /* 当前生效正文预览 */
        echo '<h3 style="font-size:14px;margin:18px 0 8px">当前生效正文</h3>';
        echo '<div class="zw-adm-card" style="padding:14px;max-height:420px;overflow:auto">';
        if ($current && zw_legal_text_normalize((string) ($current['body_html'] ?? '')) !== '') {
            $body_html = (string) $current['body_html'];
            echo zw_policy_document_center_preview_html($body_html);
        } else {
            echo '<p style="margin:0;color:#8a6d00">当前没有生效版本（或正文为空）——渲染将回退 legacy 旧源，不影响线上。</p>';
        }
        echo '</div>';

        /* 与上一版 diff（护栏 ②） */
        if (count($versions) >= 2) {
            $new = $versions[0];
            $old = $versions[1];
            echo '<h3 style="font-size:14px;margin:18px 0 8px">差异：' . esc_html((string) ($old['version_no'] ?? '')) . ' → ' . esc_html((string) ($new['version_no'] ?? '')) . '</h3>';
            echo '<div class="zw-adm-card" style="padding:12px;overflow:auto">'
                . zw_legal_text_diff((string) ($old['body_html'] ?? ''), (string) ($new['body_html'] ?? ''))
                . '</div>';
        }

        if (!$finalize) {
            echo '<div class="notice notice-info" style="margin-top:16px"><p>只读视图：新建版本 / 确认生效 / 回退 / 归档导出仅站点管理员可操作。</p></div>';
            return;
        }

        /* 新建版本 */
        echo '<h3 style="font-size:14px;margin:20px 0 8px">新建版本（整篇正文）</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="zw-adm-card" style="padding:14px">';
        wp_nonce_field(ZW_POLICY_DOC_CREATE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(ZW_POLICY_DOC_CREATE_ACTION) . '">'
            . '<input type="hidden" name="doc" value="' . esc_attr($doc_id) . '">'
            . '<p style="margin:0 0 6px"><textarea name="body" rows="22" style="width:100%;min-height:460px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px" placeholder="粘贴整篇正文（HTML；对外声明类文档请连同内联样式块与 zw-legal-page 根容器一起粘贴）"></textarea></p>'
            . '<p style="margin:0 0 10px"><input type="text" name="note" style="width:60%" placeholder="版本说明（为什么改，留痕用）"></p>'
            . '<p style="margin:0 0 10px;color:#666;font-size:12px">整篇编辑：正文<strong>按原样入库</strong>（不套用白名单；白名单只在签署页渲染时生效）。'
            . '对外声明 / 协议类文档的版式要求见《中传网 UI 规范》§1.22（根容器 <code>zw-legal-page</code>，样式随正文内联，禁止新增全局 CSS 文件）。</p>'
            . '<p style="margin:0"><button type="submit" class="button button-primary" style="background:var(--primary-color,#d80505);border-color:var(--primary-color,#d80505)">新建版本（不立即生效）</button>'
            . ' <span style="color:#666;font-size:12px">与上一版哈希相同、或正文为空时会被拒绝。</span></p></form>';

        /* 确认生效（护栏 ③） */
        echo '<h3 style="font-size:14px;margin:20px 0 8px">确认生效（二次确认）</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="zw-adm-card" style="padding:14px">';
        wp_nonce_field(ZW_POLICY_DOC_ACTIVATE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(ZW_POLICY_DOC_ACTIVATE_ACTION) . '">'
            . '<input type="hidden" name="doc" value="' . esc_attr($doc_id) . '">'
            . '<p style="margin:0 0 8px"><select name="version">';
        foreach ($versions as $v) {
            echo '<option value="' . esc_attr((string) $v['version_no']) . '">' . esc_html((string) $v['version_no'])
                . (((string) ($v['effective_at'] ?? '')) !== '' ? '（当前已生效）' : '') . '</option>';
        }
        echo '</select></p>'
            . '<p style="margin:0 0 10px"><input type="text" name="confirm_title" style="width:60%" placeholder="请输入完整文档名以确认">'
            . ' <span style="color:#666;font-size:12px">必须与「' . esc_html($title) . '」完全一致。</span></p>'
            . '<p style="margin:0"><button type="submit" class="button button-primary" style="background:var(--primary-color,#d80505);border-color:var(--primary-color,#d80505)">确认生效</button></p></form>';

        /* 一键回退（护栏 ④） */
        echo '<h3 style="font-size:14px;margin:20px 0 8px">一键回退（新增回退版本，历史不删）</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="zw-adm-card" style="padding:14px">';
        wp_nonce_field(ZW_POLICY_DOC_ROLLBACK_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(ZW_POLICY_DOC_ROLLBACK_ACTION) . '">'
            . '<input type="hidden" name="doc" value="' . esc_attr($doc_id) . '">'
            . '<p style="margin:0 0 8px"><select name="target">';
        foreach ($versions as $v) {
            $no = (string) $v['version_no'];
            if ($current && $no === (string) ($current['version_no'] ?? '')) { continue; }
            echo '<option value="' . esc_attr($no) . '">' . esc_html($no) . '（' . esc_html((string) ($v['created_at'] ?? '')) . '）</option>';
        }
        echo '</select></p>'
            . '<p style="margin:0 0 10px"><input type="text" name="confirm_title" style="width:60%" placeholder="请输入完整文档名以确认">'
            . ' <span style="color:#666;font-size:12px">回退 = 以该历史正文重建一个新版本并生效。</span></p>'
            . '<p style="margin:0"><button type="submit" class="button">确认回退</button></p></form>';
    }
}

if (!function_exists('zw_policy_document_center_preview_html')) {
    /**
     * 详情页「当前生效正文」预览 HTML（TASK-1043）。
     *
     * 规则：①渲染**净化后**的正文（`wp_kses_post`），②**剥除 `<style>` 与区块注释**，
     * 避免把 CSS 当文本裸露；③另附**折叠的源码视图**（`esc_html` + `<details>`），源码当文本是有意的但必须折叠标注。
     *
     * @param string $body_html 生效版本正文。
     * @return string HTML
     */
    function zw_policy_document_center_preview_html($body_html) {
        $body_html = (string) $body_html;
        $preview = function_exists('preg_replace')
            ? (string) preg_replace(array('#<style\b[^>]*>.*?</style>#is', '#<!--.*?-->#s'), '', $body_html)
            : $body_html;
        $rendered = function_exists('wp_kses_post') ? wp_kses_post($preview) : esc_html($preview);
        return $rendered
            . '<details style="margin-top:12px"><summary style="cursor:pointer;color:#666;font-size:13px">源码视图（只读，折叠）</summary>'
            . '<pre style="margin:8px 0 0;padding:10px;background:#fafafa;border:1px solid #eee;border-radius:4px;max-height:320px;overflow:auto;white-space:pre-wrap;word-break:break-all;font-size:12px;color:#555">'
            . esc_html($body_html) . '</pre></details>';
    }
}
