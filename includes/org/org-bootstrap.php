<?php
/**
 * 组织架构模块加载入口。
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

require_once __DIR__ . '/org-permissions.php';
require_once __DIR__ . '/org-admin.php';
