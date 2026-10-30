<?php
/**
 * AI 秘书处模块加载入口（TASK-1352 · 阶段一）。
 *
 * 出处：原始需求《功能需求规格说明书（AI 辅助文秘与内容生产系统）v1.0》。
 *
 * 功能开关 `zw_secretariat_mvp_enabled`（默认 **0**）：
 * - **关闭时**：不加载任何页面/入口文件（`admin-post` 入口一并不可达），
 *   菜单注册直接返回 ⇒ **零菜单、零页面、零入口注册**；
 * - **开启时**：按 `zw_secretariat_module_files()` 顺序加载并注册后台菜单。
 *
 * 架构边界（阶段一）：
 *   - **不新建第二套政策库/公文库**：任务只引用收文与政策源的**标识**，正文仍由既有模块持有；
 *   - **不自动发布/签发**：`published` / `archived` 只能由 `zw_secretariat_gate_command()` 的**具名人工命令**进入；
 *   - AI 生成能力**阶段一只留接入位**（不默认开启）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_SECRETARIAT_DIR')) {
    define('ZW_SECRETARIAT_DIR', __DIR__);
}

require_once ZW_SECRETARIAT_DIR . '/secretariat-helpers.php';

if (!function_exists('zw_secretariat_module_files')) {
    /**
     * 功能开启后加载的模块文件（`file_exists` 守卫 ⇒ 未落盘自动跳过）。
     *
     * @return string[]
     */
    function zw_secretariat_module_files() {
        return array(
            'secretariat-missions.php',   // 任务模型 + 状态机 + 发布闸门判据
            'secretariat-gates.php',      // 三道人审门 + 发布/存档人工命令
            'secretariat-qa.php',         // 质量校验 8 项报告
            'secretariat-repository.php', // 查询与视图装配
            'secretariat-admin.php',      // 后台页面 + admin-post 写入口
        );
    }
}

// ─────────────────────────────────────────────── 功能开启才加载页面/入口文件
if (zw_secretariat_is_enabled()) {
    foreach (zw_secretariat_module_files() as $zw_secretariat_module_file) {
        $zw_secretariat_module_file = (string) $zw_secretariat_module_file;
        // 只允许加载本目录下的 .php 基名，杜绝路径穿越与任意包含。
        if (!preg_match('/^[a-z0-9\-]+\.php$/', $zw_secretariat_module_file)) {
            continue;
        }
        $zw_secretariat_module_path = ZW_SECRETARIAT_DIR . '/' . $zw_secretariat_module_file;
        if (file_exists($zw_secretariat_module_path)) {
            require_once $zw_secretariat_module_path;
        }
    }
}

if (!function_exists('zw_secretariat_admin_pages')) {
    /**
     * 后台页面定义（唯一出处；导航注册与菜单注册共用）。
     *
     * @return array<int,array<string,string>>
     */
    function zw_secretariat_admin_pages() {
        return array(
            array(
                'slug'     => 'zw-secretariat',
                'title'    => 'AI秘书处',
                'menu'     => '任务台账',
                'callback' => 'zw_secretariat_page_ledger',
            ),
            array(
                'slug'     => 'zw-secretariat-create',
                'title'    => '新建秘书处任务',
                'menu'     => '新建任务',
                'callback' => 'zw_secretariat_page_create',
            ),
            array(
                'slug'     => 'zw-secretariat-task',
                'title'    => '任务详情',
                'menu'     => '任务详情',
                'callback' => 'zw_secretariat_page_task',
            ),
            array(
                'slug'     => 'zw-secretariat-gates',
                'title'    => '人审门',
                'menu'     => '人审门',
                'callback' => 'zw_secretariat_page_gates',
            ),
            array(
                'slug'     => 'zw-secretariat-qa',
                'title'    => '质量校验报告',
                'menu'     => '质量校验报告',
                'callback' => 'zw_secretariat_page_qa',
            ),
        );
    }
}

if (!function_exists('zw_secretariat_menu_slug')) {
    /**
     * 顶级菜单 slug（`AI秘书处` 归办公协同域）。
     *
     * @return string
     */
    function zw_secretariat_menu_slug() {
        return 'zw-secretariat';
    }
}

if (!function_exists('zw_secretariat_register_post_type_hook')) {
    /**
     * 注册 CPT（`init` 钩子）。
     *
     * @return void
     */
    function zw_secretariat_register_post_type_hook() {
        if (function_exists('zw_secretariat_register_post_type')) {
            zw_secretariat_register_post_type();
        }
    }
}

if (!function_exists('zw_secretariat_register_admin_menu')) {
    /**
     * 注册后台菜单（**功能关闭或无权限时不注册**）。
     *
     * 位置：`59.5` —— 办公协同号段（59–60.9）内第 3 位：收文(59) → 发文(59.2) → **AI秘书处(59.5)**
     *（OA-2451 / 主题 TASK-2476：用户 2026-10-30 拍板的显示顺序，取代 TASK-1352 的 Q1「置于公文类之前」）。
     *
     * @return void
     */
    function zw_secretariat_register_admin_menu() {
        if (!zw_secretariat_is_enabled() || !zw_secretariat_can()) {
            return;
        }
        $pages = zw_secretariat_admin_pages();
        if (!$pages) {
            return;
        }
        $first = $pages[0];
        if (!function_exists($first['callback'])) {
            return;
        }

        add_menu_page(
            $first['title'],
            $first['menu'],
            zw_secretariat_capability(),
            zw_secretariat_menu_slug(),
            $first['callback'],
            'dashicons-clipboard',
            59.5
        );

        foreach ($pages as $index => $page) {
            if ($index === 0) {
                continue; // 第一项即父项，不重复添加。
            }
            if (!function_exists($page['callback'])) {
                continue;
            }
            add_submenu_page(
                zw_secretariat_menu_slug(),
                $page['title'],
                $page['menu'],
                zw_secretariat_capability(),
                $page['slug'],
                $page['callback']
            );
        }
    }
}

if (!function_exists('zw_secretariat_register_post_handlers')) {
    /**
     * 注册 `admin-post` 写入口（**只在功能开启时注册**；关闭 = 零入口）。
     *
     * @return void
     */
    function zw_secretariat_register_post_handlers() {
        if (!function_exists('add_action')) {
            return;
        }
        $handlers = array(
            'zw_secretariat_create_task'   => 'zw_secretariat_handle_create',
            'zw_secretariat_save_text'     => 'zw_secretariat_handle_save_text',
            'zw_secretariat_gate_action'   => 'zw_secretariat_handle_gate_action',
            'zw_secretariat_gate_command'  => 'zw_secretariat_handle_gate_command',
            'zw_secretariat_qa_save'       => 'zw_secretariat_handle_qa_save',
        );
        foreach ($handlers as $action => $callback) {
            if (function_exists($callback)) {
                add_action('admin_post_' . $action, $callback);
            }
        }
    }
}

// ─────────────────────────────────────────────── 钩子接线（仅功能开启）
if (zw_secretariat_is_enabled() && function_exists('add_action')) {
    add_action('init', 'zw_secretariat_register_post_type_hook');
    add_action('admin_menu', 'zw_secretariat_register_admin_menu');
    zw_secretariat_register_post_handlers();
}
