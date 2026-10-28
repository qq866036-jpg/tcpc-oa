<?php
/**
 * 卸载清理（TASK-2320）。
 *
 * 原则：**只删本插件自己写的东西，绝不碰主题/其它插件的数据**。
 *
 * 本插件目前写入的持久状态只有模块开关 option（`tcpc_oa_enable_<module>`）。
 * 收文等业务数据（自定义表 / 政策库 / 文件）**不在此清理** —— 它们属于站点业务数据，
 * 卸载插件不等于要求删业务数据；如需清理必须由管理员显式执行专用工具。
 *
 * @package tcpc-oa
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$zw_oa_option_keys = array(
    'tcpc_oa_enable_receipt',
);

foreach ($zw_oa_option_keys as $zw_oa_key) {
    delete_option($zw_oa_key);
    // 多站点：一并清理站点级同键（WordPress 对单站点 delete_option 已覆盖，这里只做加固）。
    if (function_exists('is_multisite') && is_multisite()) {
        delete_site_option($zw_oa_key);
    }
}
