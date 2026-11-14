<?php
/**
 * AI 法务部 · 内核后台「桥接助手」（TASK-2423 / OA 迁移 R3-3）。
 *
 * 定位：**内核后台页共享的读侧助手**，只放「即便插件未启用、主题也要能渲染」的两件事：
 *  - `zw_policy_platform_ready()`：政策平台三张核心表是否就绪（门户统计卡片用，`portal-dashboard.php:78`）；
 *  - `zw_policy_platform_badge()`：状态徽章 HTML（机构「管理员」页实名/协议列用，`page-branches.php:4198`）。
 *
 * 禁止：**不得**往里放页面逻辑 / 查询 / 写操作。数据访问层在 `policy-platform-data.php`
 * （表名 / 存在性 / 内容哈希），本文件只消费它（`:338` 先于本文件 require）。
 *
 * 为什么单独建文件而不是塞进 `policy-platform-data.php`：data.php 的定位是「数据访问层」，
 * 徽章是 HTML 渲染，塞进去会污染层次（清单 §3.1 的架构决定）。
 *
 * 双声明纪律（WP 先加载插件、后加载主题）：
 *  - 插件 `tcpc-oa` 若已声明同名函数 ⇒ 本定义让位（插件优先）；
 *  - 主题 `inc/admin/policy-platform-admin.php` 里保留的同名定义**同样带守卫** ⇒ 任意顺序都不 fatal。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_policy_platform_ready')) {
    /**
     * 政策平台核心表是否就绪（`policy_source` / `policy_clause` / `policy_rule` 三张）。
     *
     * @return bool
     */
    function zw_policy_platform_ready() {
        return zw_policy_platform_exists('policy_source')
            && zw_policy_platform_exists('policy_clause')
            && zw_policy_platform_exists('policy_rule');
    }
}

if (!function_exists('zw_policy_platform_badge')) {
    /**
     * 状态徽章（`on|wait|err|info|off`，未知类型回落 `off`）。
     *
     * @param string $type  徽章类型。
     * @param string $label 文案。
     * @return string HTML。
     */
    function zw_policy_platform_badge($type, $label) {
        $type = in_array($type, array('on', 'wait', 'err', 'info', 'off'), true) ? $type : 'off';
        return '<span class="zw-adm-badge ' . esc_attr($type) . '">' . esc_html((string) $label) . '</span>';
    }
}
