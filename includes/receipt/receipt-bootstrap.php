<?php
/**
 * 收文管理模块加载入口（TASK-1297 · R1）。
 *
 * 功能开关 `zw_receipt_mvp_enabled`（默认 **0**）：
 * - **关闭时**：不加载任何页面/入口文件（`receipt-create.php` 等 admin-post 入口一并不可达），
 *   `zw_receipt_register_admin_menu()` 直接返回 ⇒ **零菜单、零页面、零入口注册**；
 * - **开启时**：按 `zw_receipt_admin_module_files()` 顺序加载已落盘的模块文件并注册后台菜单。
 *
 * 复用边界（架构稿 §1/§2）：收文只读写既有 `zw_policy_source` / `zw_policy_issue`，
 * 唯一新增表为 `zw_receipt_number_counters`；**不新建第二套政策库**。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_RECEIPT_DIR')) {
    define('ZW_RECEIPT_DIR', __DIR__);
}

require_once ZW_RECEIPT_DIR . '/receipt-helpers.php';
require_once ZW_RECEIPT_DIR . '/receipt-number.php';

if (!function_exists('zw_receipt_admin_module_files')) {
    /**
     * 需在功能开启后加载的模块文件（轮次落点，`file_exists` 守卫 ⇒ 未落盘的文件自动跳过）。
     *
     * 页面/入口文件**只在开关开启时**加载：关闭时 admin-post 入口与页面回调都不存在，
     * 因此「关闭 = 零入口」由**加载面**保证，而不是靠每个入口自己判权。
     *
     * @return string[]
     */
    function zw_receipt_admin_module_files() {
        return array(
            'receipt-storage.php',     // R2 部委/年份目录与私有存储
            'receipt-orgs.php',        // R2 部委字典
            'receipt-create.php',      // R3 登记页 + admin-post 提交入口
            'receipt-ledger.php',      // R4 收文台账
            'receipt-issues.php',      // R5 办理视图
            'receipt-admin-ui.php',    // R5 后台 UI 片段
            'receipt-handle.php',      // OA-2464 拟办/批示/传阅/催办/归档（append-only 流水 + 3 列）
            'receipt-auto.php',        // OA-2468 批示库 + 20 秒自动签阅（复用 handle_log，无新表）
            'receipt-number-admin.php', // TASK-1306 收文编号设置页 + admin-post 保存入口
            'receipt-permissions.php', // R6 权限登记
        );
    }
}

// ─────────────────────────────────────────────── 功能开启才加载页面/入口文件
if (zw_receipt_is_enabled()) {
    foreach (zw_receipt_admin_module_files() as $zw_receipt_module_file) {
        $zw_receipt_module_file = (string) $zw_receipt_module_file;
        // 只允许加载本目录下的 .php 基名，杜绝路径穿越与任意包含。
        if (!preg_match('/^[a-z0-9\-]+\.php$/', $zw_receipt_module_file)) {
            continue;
        }
        $zw_receipt_module_path = ZW_RECEIPT_DIR . '/' . $zw_receipt_module_file;
        if (file_exists($zw_receipt_module_path)) {
            require_once $zw_receipt_module_path;
        }
    }
    unset($zw_receipt_module_file, $zw_receipt_module_path);

    // OA-2463：OCR 接入（开关默认关 ⇒ 未部署识别服务时零行为）。放在模块文件之后装载，
    // 保证 `zw_ocr_*` 能用到 `zw_receipt_ledger_source_table()` / `zw_receipt_storage_*`。
    if (is_file(dirname(__DIR__) . '/ocr/ocr-bootstrap.php')) {
        require_once dirname(__DIR__) . '/ocr/ocr-bootstrap.php';
    }
}

if (!function_exists('zw_receipt_admin_pages')) {
    /**
     * 后台子页登记表（补充 A.1 的 slug 口径；可过滤器扩展）。
     *
     * @return array[]
     */
    function zw_receipt_admin_pages() {
        $pages = array(
            array(
                'slug'       => 'zw-receipt-create',
                'title'      => '收文登记',
                'menu_title' => '收文登记',
                'callback'   => 'zw_receipt_page_create',
            ),
            array(
                'slug'       => 'zw-receipt-ledger',
                'title'      => '收文台账',
                'menu_title' => '收文台账',
                'callback'   => 'zw_receipt_page_ledger',
            ),
            array(
                'slug'       => 'zw-receipt-orgs',
                'title'      => '部委与年份目录',
                'menu_title' => '部委与年份目录',
                'callback'   => 'zw_receipt_page_orgs',
            ),
            array(
                'slug'       => 'zw-receipt-issues',
                'title'      => '收文办理',
                'menu_title' => '收文办理',
                'callback'   => 'zw_receipt_page_issues',
            ),
            array(
                'slug'       => 'zw-receipt-number',
                'title'      => '收文编号设置',
                'menu_title' => '收文编号设置',
                'callback'   => 'zw_receipt_number_page',
            ),
            // OA-2463：OCR 设置**不新增子菜单**（避免改动权限功能树 ID 集与「子页 slug 与登记表一致」
            // 这条既有判据）⇒ 挂在收文台账页的 `view=ocr` 分支上，见 `receipt-ledger.php`。
        );
        if (function_exists('apply_filters')) {
            $pages = apply_filters('zw_receipt_admin_pages', $pages);
        }
        return is_array($pages) ? $pages : array();
    }
}

if (!function_exists('zw_receipt_menu_parent_callback')) {
    /**
     * 顶级菜单落地页回调（按台账 → 登记 → 办理 → 目录顺序取第一个**已落盘**的页面）。
     *
     * 全部未落盘时返回空串 ⇒ 不注册父菜单，避免「菜单指向不存在回调」的白屏。
     *
     * @return string
     */
    function zw_receipt_menu_parent_callback() {
        foreach (array('zw_receipt_page_ledger', 'zw_receipt_page_create', 'zw_receipt_page_issues', 'zw_receipt_page_orgs') as $callback) {
            if (function_exists($callback)) {
                return $callback;
            }
        }
        return '';
    }
}

if (!function_exists('zw_receipt_register_admin_menu')) {
    /**
     * 注册「收文管理」顶级菜单与子菜单。
     *
     * 四重守卫，确保关闭/无权限/迁移未就绪/半成品时**零注册**：
     *   1. 功能开关关闭 → 直接返回；
     *   2. 无 `tcpc_perm_doc` → 直接返回；
     *   3. **迁移①未就绪**（缺计数器表或缺 `origin` 列）→ 直接返回并出管理员提示
     *      （硬前置：编号去重依赖 `uniq_received_no` 唯一索引，见 `zw_receipt_migration_ready()`）；
     *   4. 无任何已落盘页面回调 → 直接返回（不产出空菜单或悬空回调）。
     *
     * @return void
     */
    function zw_receipt_register_admin_menu() {
        if (!zw_receipt_is_enabled()) {
            return;
        }
        if (function_exists('zw_receipt_migration_ready') && !zw_receipt_migration_ready()) {
            // 开关已开但迁移未跑：不放出任何页面（避免使用缺索引/缺列的半成品链路）。
            add_action('admin_notices', 'zw_receipt_migration_notice');
            return;
        }
        if (function_exists('current_user_can') && !current_user_can(zw_receipt_capability())) {
            return;
        }
        $parent_callback = zw_receipt_menu_parent_callback();
        if ($parent_callback === '') {
            return;
        }

        $submenus = array();
        foreach (zw_receipt_admin_pages() as $page) {
            $slug     = isset($page['slug']) ? (string) $page['slug'] : '';
            $callback = isset($page['callback']) ? (string) $page['callback'] : '';
            if ($slug === '' || $callback === '' || !function_exists($callback)) {
                continue;
            }
            if (!preg_match('/^zw-receipt-[a-z0-9\-]+$/', $slug)) {
                continue;
            }
            $submenus[] = $page;
        }
        if (!$submenus) {
            return;
        }

        add_menu_page(
            '收文管理',
            '收文管理',
            zw_receipt_capability(),
            zw_receipt_menu_slug(),
            $parent_callback,
            'dashicons-archive',
            // OA-2454 / 主题 TASK-2482：号段整体下移到 **58.6–58.9**（用户 2026-10-30 现场：
            // 原号段 59–60.9 与 WP 核心冲突 —— 核心 `$menu[59]` 是分隔线、`$menu[60]` 是「外观」，
            // 导致「外观」被夹进「办公协同」分组内）。新号段位于小程序配置（58.5）与核心分隔线（59）之间，
            // 无任何核心键占用。顺序不变：收文 → 发文 → 秘书处 → 法务 → 网盘 → 组织。
            58.6
        );

        // OA-2464：待批示角标（有拟办、无批示）—— 在 add_menu_page 之后改 `$menu` 里的标题，
        // **不改菜单调用本体**（既有判据按「add_menu_page 块内出现 58.6」取源，改实参会撞断言）。
        if (function_exists('zw_receipt_handle_pending_instruction')) {
            $zw_receipt_pending_n = (int) zw_receipt_handle_pending_instruction();
            if ($zw_receipt_pending_n > 0 && isset($GLOBALS['menu']) && is_array($GLOBALS['menu'])) {
                foreach ($GLOBALS['menu'] as $zw_receipt_menu_i => $zw_receipt_menu_item) {
                    if (isset($zw_receipt_menu_item[2]) && (string) $zw_receipt_menu_item[2] === zw_receipt_menu_slug()) {
                        $GLOBALS['menu'][$zw_receipt_menu_i][0] = '收文管理'
                            . ' <span class="awaiting-mod count-' . $zw_receipt_pending_n . '">'
                            . '<span class="pending-count">' . $zw_receipt_pending_n . '</span></span>';
                    }
                }
            }
        }

        foreach ($submenus as $page) {
            add_submenu_page(
                zw_receipt_menu_slug(),
                (string) $page['title'],
                (string) $page['menu_title'],
                zw_receipt_capability(),
                (string) $page['slug'],
                (string) $page['callback']
            );
        }
    }
}

if (function_exists('add_action')) {
    // 关闭时该回调直接返回（且页面/入口文件本就未加载）⇒ 零注册。
    add_action('admin_menu', 'zw_receipt_register_admin_menu', 21);
}

if (!function_exists('zw_receipt_migration_notice')) {
    /**
     * 「功能开关已开但迁移未跑」的管理员提示（TASK-1297 独立审计处置 #1）。
     *
     * 只在开关开启、且当前用户具备 `tcpc_perm_doc` 时输出；
     * 文案给出可复现的三步（dry-run → execute → verify），**不含任何密钥或数据内容**。
     *
     * @return void
     */
    function zw_receipt_migration_notice() {
        if (!zw_receipt_is_enabled()) {
            return;
        }
        if (function_exists('current_user_can') && !current_user_can(zw_receipt_capability())) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>收文管理未就绪：</strong>'
            . '功能开关已开启，但数据库迁移（计数器表与 <code>origin</code> 列）尚未执行，'
            . '为免出现重号与空台账，<strong>已暂不放出收文管理页面</strong>。'
            . '请依次执行：<code>wp eval-file scripts/migrate-receipt-schema.php</code>（dry-run）→ '
            . '<code>wp eval-file scripts/migrate-receipt-schema.php execute</code> → '
            . '<code>wp eval-file scripts/migrate-receipt-schema.php verify</code>。'
            . '</p></div>';
    }
}
