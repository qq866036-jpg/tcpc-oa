<?php
/**
 * 办公协同后台 UI · 装载入口（OA-2491 / 铁律 32）。
 *
 * 只装载**呈现层基础设施**（令牌 + 助手 + 资源装载 + 后台壳 body class），
 * 不含任何业务逻辑、不注册菜单、不读业务数据 —— 因此可随插件入口无条件加载。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ui-tokens.php';

if (function_exists('add_action')) {
    add_action('admin_enqueue_scripts', 'tco_ui_enqueue_assets');
    add_filter('admin_body_class', 'tco_ui_admin_body_class');
}
