<?php
/**
 * 组织架构模块加载入口（插件侧）。
 *
 * 仅后台加载；数据层与后台页面在 org-admin.php 中按需引入。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!is_admin() && !(defined('WP_CLI') && WP_CLI)) {
    return;
}

/**
 * OA-2434（R6b）插件侧加载适配 —— **与主题侧有意差异**（见 CHG-434）。
 *
 * 主题侧本文件是 `require_once __DIR__ . '/org-permissions.php';`，而
 * `org-permissions.php`（1,621 行 RBAC 内核）按 Q2 拍板**留主题、不迁插件**
 * ⇒ 插件侧该文件永远不存在，直接 require 会 fatal（整站 500），不是降级。
 *
 * 改法（两条硬约束）：
 *   ① **不反向 require 主题内部文件**（内核边界铁律）：改为消费内核**契约符号**，
 *      装载前判 `function_exists('zw_org_all_permission_keys')` ——
 *      与 `org-repository.php:163` 已在用的同一符号同口径；
 *   ② 装载时机挪到 `after_setup_theme`（此时主题 `functions.php` 已加载、内核符号可用）。
 *      **不能用 `admin_init`**：WP 核心 `wp-admin/admin.php:163` 先 `require wp-admin/menu.php`
 *      （`admin_menu` 在其中的 `includes/menu.php` 触发），到 `:180` 才 `do_action('admin_init')`
 *      ⇒ `admin_init` **晚于** `admin_menu`，在那里注册菜单永远不显示。R6b 已按此实测修正。
 *
 * 缺内核符号 ⇒ **fail-closed**：零菜单、零页面，只给管理员一条 `admin_notices`，绝不 fatal。
 */
if (!function_exists('zw_oa_org_boot')) {
    /**
     * 在 after_setup_theme 阶段装载 org 管理面（内核符号可用时）。
     *
     * @return void
     */
    function zw_oa_org_boot() {
        if (!function_exists('zw_org_all_permission_keys')) {
            if (function_exists('add_action')) {
                add_action('admin_notices', 'zw_oa_org_missing_kernel_notice');
            }
            return;
        }
        require_once __DIR__ . '/org-admin.php';
    }
}

if (!function_exists('zw_oa_org_missing_kernel_notice')) {
    /**
     * 内核缺失时的管理员提示（fail-closed，不静默）。
     *
     * @return void
     */
    function zw_oa_org_missing_kernel_notice() {
        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>组织架构不可用</strong>：'
            . '该模块依赖主题内核的组织权限能力（<code>inc/admin/org/org-permissions.php</code>），'
            . '当前主题未提供。请确认主题版本不低于本插件 <code>MIN_THEME</code> 后再启用。</p></div>';
    }
}

if (function_exists('add_action')) {
    add_action("after_setup_theme", "zw_oa_org_boot", 20);
} else {
    // 非 WP 环境（单元测试 / CLI 探针）：直接跑一次，与挂载等价。
    zw_oa_org_boot();
}
