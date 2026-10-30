<?php
/**
 * 发文管理模块加载入口（TASK-433）。
 *
 * 功能开关：wp_options.zw_doc_mvp_enabled 默认 0；开启后注册菜单、页面与下载入口。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/doc-layout-gb9704.php';
// TASK-2000：「公文版式」设计器（国标参数可视化 + 白名单覆盖层）。放在 spec 之后、渲染之前 ——
// 覆盖层由 `zw_doc_gb9704_spec()` 末尾应用，渲染器读到即生效（单源，无第二套排版）。
require_once __DIR__ . '/doc-layout-designer.php';
require_once __DIR__ . '/doc-body-model.php';
require_once __DIR__ . '/doc-helpers.php';
require_once __DIR__ . '/doc-admin-ui.php';
require_once __DIR__ . '/doc-number-repository.php';
// TASK-1867（CHG-362）：份号台账与原子占号 —— 必须晚于 doc-helpers.php（表名/通知）
// 与 doc-number-repository.php（复用 `zw_doc_prepare_sql()`），早于 composer 与后台页面。
require_once __DIR__ . '/doc-copy-number-repository.php';
require_once __DIR__ . '/doc-repository.php';
require_once __DIR__ . '/doc-render-html.php';
require_once __DIR__ . '/doc-export-docx.php';
require_once __DIR__ . '/doc-seal.php';
require_once __DIR__ . '/doc-letterhead.php';
require_once __DIR__ . '/doc-legal.php';
// 「任」字文种人员任命配置（TASK-1291）：必须在 doc-composer.php 之前加载
// —— composer 要调用 zw_doc_appoint_is_appoint_type() / zw_doc_appoint_template_lines()。
require_once __DIR__ . '/doc-appoint.php';
require_once __DIR__ . '/doc-composer.php';
require_once __DIR__ . '/doc-import-docx.php';
require_once __DIR__ . '/doc-pdf.php';
// TASK-1410：发文全流程**自动流转**唯一推进器（`zw_doc_auto_flow_advance()`）+ 唯一触发监听器。
// 必须晚于 doc-seal.php（用印门禁与动作）、doc-legal.php（法务结论唯一写入口）与 doc-pdf.php
// （PDF 门禁与投递），因为推进器按顺序消费它们；亦须早于后台页面（页面消费开关登记表）。
require_once __DIR__ . '/doc-auto-flow.php';
require_once __DIR__ . '/doc-import.php';
require_once __DIR__ . '/doc-download.php';
require_once __DIR__ . '/doc-admin-numbers.php';
require_once __DIR__ . '/doc-admin-list.php';
require_once __DIR__ . '/doc-admin-flow.php';
// TASK-1396：「数据字典」页 + 发文管理字典的**唯一写入口** `zw_doc_dict_save()`。
// 必须晚于 doc-helpers.php（清洗/读取出处）与 doc-admin-ui.php（页面范式）；
// 亦须早于任何会调用该唯一写入口的模块（运行时调用，加载顺序不敏感，此处就近登记）。
require_once __DIR__ . '/doc-admin-dict.php';
// TASK-1295：「任」字公文生效后置（带印章正式版归档 + webp + 任命书证书 267）。
// 必须晚于 doc-appoint.php（复用其任命要素与字典）与 doc-pdf.php（读带印章件产物）。
require_once __DIR__ . '/doc-appoint-cert.php';

add_action('init', 'zw_doc_register_post_type');

if (!function_exists('zw_doc_admin_menu')) {
    /**
     * 注册「发文管理」顶级菜单与子菜单。
     *
     * @return void
     */
    function zw_doc_admin_menu() {
        if (!current_user_can('tcpc_perm_doc') || !zw_doc_is_enabled()) {
            return;
        }

        add_menu_page(
            '发文管理',
            '发文管理',
            'tcpc_perm_doc',
            'zw-doc',
            'zw_doc_page_list',
            'dashicons-media-document',
            // OA-2454 / 主题 TASK-2482：号段下移为 **58.6–58.9**（避开 WP 核心 59 分隔线 / 60 外观）。
            // 顺序：收文(58.6) → 发文(58.7) → AI秘书处(58.75) → AI法务部(58.8)
            // → 网盘文件(58.85) → 组织架构(58.9)。
            58.7
        );

        // 「新建发文」直达子菜单（TASK-786 用户拍板方案 B）：动作与列表分离，高频创建少一次点击。
        // 不改信息架构（仍属「发文管理」），也不拆顶级菜单。
        add_submenu_page(
            'zw-doc',
            '新建发文',
            '新建发文',
            'tcpc_perm_doc',
            'zw-doc-create',
            'zw_doc_page_create'
        );

        add_submenu_page(
            'zw-doc',
            '发文台账',
            '发文台账',
            'tcpc_perm_doc',
            'zw-doc-ledger',
            'zw_doc_page_ledger'
        );

        add_submenu_page(
            'zw-doc',
            '文号管理',
            '文号管理',
            'tcpc_perm_doc',
            'zw-doc-numbers',
            'zw_doc_page_numbers'
        );

        add_submenu_page(
            'zw-doc',
            '公文流转配置',
            '公文流转配置',
            'tcpc_perm_doc',
            'zw-doc-flow',
            'zw_doc_page_flow'
        );

        // TASK-1396（用户指令原文「生成 发文管理-数据字典 页面 统一管理」）：
        // 「数据字典」与「文号管理 / 公文流转配置」并列 —— 后两者是**维护入口的历史落点**，
        // 本页把同一批可编辑枚举**集中**到一处维护（写入统一走 `zw_doc_dict_save()`）。
        add_submenu_page(
            'zw-doc',
            '数据字典',
            '数据字典',
            'tcpc_perm_doc',
            'zw-doc-dict',
            'zw_doc_page_dict'
        );

        // TASK-2000（用户 2026-10-24 决定）：新增「公文版式」——国标各项参数的可视化调整入口。
        add_submenu_page(
            'zw-doc',
            '公文版式',
            '公文版式',
            'tcpc_perm_doc',
            'zw-doc-layout',
            'zw_doc_layout_render_page'
        );

        add_submenu_page(
            'zw-doc',
            '红头与印章',
            '红头与印章',
            'tcpc_perm_doc',
            'zw-doc-assets',
            'zw_doc_page_assets'
        );

        add_submenu_page(
            'zw-doc',
            '公文法务',
            '公文法务',
            'tcpc_perm_doc',
            'zw-doc-legal',
            'zw_doc_page_legal'
        );

        // 将 WordPress 自动创建的首个子菜单从「发文管理」改为「我的发文」。
        global $submenu;
        if (isset($submenu['zw-doc'][0]) && is_array($submenu['zw-doc'][0])) {
            $submenu['zw-doc'][0][0] = '我的发文';
            $submenu['zw-doc'][0][3] = '我的发文';
        }
    }
}
add_action('admin_menu', 'zw_doc_admin_menu', 60);

if (!function_exists('zw_doc_request_targets_admin_post_endpoint')) {
    /**
     * 当前请求是否指向本模块的某个 **admin-post 端点**（TASK-1987）。
     *
     * 为什么必须显式判定：`wp-admin/admin-post.php` 在分发 `admin_post_{action}` **之前**会先
     * `do_action('admin_init')`（WP 核心行为，见 `wp-admin/admin-post.php`）。撰写页「预览当前内容」按钮是
     * **整张表单**的 submit（`formaction=admin-post.php?action=zw_doc_composer_preview`），表单里必然带着
     * `zw_doc_action=save_body|create_document` 隐藏域 ⇒ `admin_init` 上的本模块通用分发器
     * `zw_doc_handle_admin_post()` 会**抢先**按 `zw_doc_action` 执行「保存正文 / 新建发文」并
     * `wp_safe_redirect()` 到详情页，**预览端点永远轮不到执行** —— 用户看到的就是「点预览却跳到发文详情」。
     *
     * 实测生产 access log（2026-10-23 12:37:24，本单触发场景）：
     * `POST /wp-admin/admin-post.php?action=zw_doc_composer_preview ⇒ 302`
     * 紧接着 `GET …/admin.php?page=zw-doc&view=detail&doc_id=29711&zw_doc_msg=正文已保存。`
     *
     * 判据：`action` 参数以 `zw_doc_` 开头即属本模块的 admin-post 端点
     * （`zw_doc_download` / `zw_doc_print` / `zw_doc_export_docx` / `zw_doc_seal_view` / `zw_doc_pdf_download` /
     * `zw_doc_appoint_resync` / `zw_doc_composer_preview|split|import_docx`）。这些请求一律**交由各自的
     * `admin_post_*` handler** 处理，通用分发器**不得插手**。页面级 POST（`admin.php?page=zw-doc*`）不带
     * `action`，行为零变化。
     *
     * @return bool
     */
    function zw_doc_request_targets_admin_post_endpoint() {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action === '') {
            return false;
        }

        return strpos($action, 'zw_doc_') === 0;
    }
}

if (!function_exists('zw_doc_handle_admin_post')) {
    /**
     * 在 admin_init 阶段统一处理发文管理 POST。
     * 页面回调在 admin header 之后执行，不能再输出重定向头。
     *
     * @return void
     */
    function zw_doc_handle_admin_post() {
        if (!zw_doc_is_enabled()) {
            return;
        }
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }
        // TASK-1987：请求已指名本模块的某个 admin-post 端点 ⇒ 通用分发器**一律不插手**
        // （否则 `zw_doc_action` 会在 admin_init 阶段抢先执行保存并跳详情，把预览等端点吞掉）。
        if (zw_doc_request_targets_admin_post_endpoint()) {
            return;
        }
        // TASK-1987 双保险：预览按钮带 `zw_doc_preview=1` —— 万一将来提交目标变化（不经 admin-post），
        // 只要这次提交是「预览」，同样不许走保存/新建分支。
        if (isset($_POST['zw_doc_preview']) && (string) wp_unslash($_POST['zw_doc_preview']) === '1') {
            return;
        }
        // TASK-1350：**流转字段保存**（编辑态第 4 步）—— 独立提交键，只写流转 meta，不走正文保存。
        // 放在 `save_body` **之前**判定：编辑态第 4 步的「保存流转字段」按钮只提交本键。
        if (isset($_POST['zw_doc_flow_action']) && sanitize_key(wp_unslash($_POST['zw_doc_flow_action'])) === 'save'
            && function_exists('zw_doc_composer_handle_flow_save')) {
            zw_doc_composer_handle_flow_save();
            return;
        }
        // 正文保存（编辑已有草稿）：不按页面分发，避免与列表页 POST 语义纠缠（TASK-772）。
        if (isset($_POST['zw_doc_action']) && sanitize_key(wp_unslash($_POST['zw_doc_action'])) === 'save_body'
            && function_exists('zw_doc_composer_handle_save')) {
            zw_doc_composer_handle_save();
            return;
        }
        // 投递加密 PDF 任务（TASK-774）
        if (isset($_POST['zw_doc_action']) && sanitize_key(wp_unslash($_POST['zw_doc_action'])) === 'pdf_queue'
            && function_exists('zw_doc_pdf_handle_queue_post')) {
            zw_doc_pdf_handle_queue_post();
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === 'zw-doc-numbers') {
            zw_doc_numbers_handle_post();
        } elseif (in_array($page, array('zw-doc', 'zw-doc-create', 'zw-doc-ledger'), true)) {
            // TASK-1991（用户报障「新建发文 文种选函 提交审核后自动跳回首页 + 提示还有一项必填 + 已填内容被清空」）
            // **根因**：「新建发文」子菜单页的 slug 是 `zw-doc-create`（TASK-786 方案 B 的直达入口），
            // 与列表页 `zw-doc` **不是同一个 slug**，而本分发器此前只认 `zw-doc` ⇒ 该页的
            // 「保存草稿 / 提交审核」POST **无人处理**：WordPress 只是把同一页面**原样重渲染**（HTTP 200、无重定向），
            // 既没保存、也没跳转、表单被重绘成空 ⇒ 用户看到的就是「跳回来 + 还有 N 项必填 + 内容全清空」。
            // 生产实测（access log）：成功创建走 `POST …?page=zw-doc&view=create&num_type=任 ⇒ 302`；
            // 失败那两次是 `POST …?page=zw-doc-create ⇒ 200 (88527 bytes)`（= 原页重渲染，无任何写入）。
            // 台账页 `zw-doc-ledger` 的「跳过该号」POST 同理，故三者共用**同一处理器**
            // （`zw_doc_list_handle_post()` 内部按 `zw_doc_skip_button` / `zw_doc_action` 自行分发）。
            zw_doc_list_handle_post();
        } elseif ($page === 'zw-doc-flow') {
            zw_doc_flow_handle_post();
        } elseif ($page === 'zw-doc-dict') {
            // TASK-1396：数据字典页保存（唯一写入口 `zw_doc_dict_save()` 的内部调用）。
            zw_doc_dict_handle_post();
        } elseif ($page === 'zw-doc-assets') {
            zw_doc_assets_handle_post();
        } elseif ($page === 'zw-doc-legal') {
            zw_doc_legal_handle_post();
        } elseif ($page === 'zw-doc-layout') {
            // TASK-2000：公文版式设计器保存/恢复默认（TASK-1991 教训：新页必须同批接线，否则「提交没反应」）。
            zw_doc_layout_handle_post();
        }
    }
}
add_action('admin_init', 'zw_doc_handle_admin_post', 20);

add_action('admin_post_zw_doc_download', 'zw_doc_handle_download');
add_action('admin_post_zw_doc_print', 'zw_doc_handle_print');
add_action('admin_post_zw_doc_export_docx', 'zw_doc_handle_export_docx');
add_action('admin_post_zw_doc_seal_view', 'zw_doc_handle_seal_view');
// TASK-2000：「公文版式」预览端点（返回整页 HTML，供设计器 iframe 内嵌）。
add_action('admin_post_zw_doc_layout_preview', 'zw_doc_layout_render_preview');
add_action('admin_post_zw_doc_composer_preview', 'zw_doc_handle_composer_preview');
add_action('admin_post_zw_doc_composer_split', 'zw_doc_handle_composer_split');
add_action('admin_post_zw_doc_composer_import_docx', 'zw_doc_handle_composer_import_docx');
add_action('admin_post_zw_doc_pdf_download', 'zw_doc_handle_pdf_download');
// TASK-1291：「任」字发文详情页「重新同步」（admin_post + nonce + manage_options）。
add_action('admin_post_zw_doc_appoint_resync', 'zw_doc_handle_appoint_resync');
