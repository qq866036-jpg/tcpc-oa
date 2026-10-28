<?php
/**
 * Plugin Name: 中传网办公协同
 * Plugin URI:  https://tcpc.org.cn/
 * Description: 中传网办公协同（发文管理 / 收文管理 / AI法务部 / AI秘书处 / 网盘文件中心 / 组织架构业务）。独立仓 tcpc-oa。
 * Version:     0.1.0
 * Author:      中国传统文化促进会
 * Requires PHP: 7.4
 * Text Domain: tcpc-oa
 *
 * ⚠️ 骨架期说明（TASK-2306 P2）：
 * 本入口**只声明版本**，不 require 任何业务文件、不注册任何钩子 —— 目的是让仓骨架可提交、可校验，
 * 同时对站点**零影响**。业务模块在 P2/P3 阶段按「一个阶段单只迁一个模块」的节奏装入 includes/，
 * 并必须带 diff=0 证据（新仓与主题对应目录逐字节一致）。
 *
 * 版本三方一致：VERSION == TCPC_OA_VERSION == 本文件 Version 头。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('TCPC_OA_VERSION')) {
    define('TCPC_OA_VERSION', '0.1.0');
}

if (!defined('TCPC_OA_DIR')) {
    define('TCPC_OA_DIR', __DIR__);
}

if (!defined('TCPC_OA_MIN_THEME')) {
    // 与 MIN_THEME 文件同源；发版脚本会校验两者一致。
    define('TCPC_OA_MIN_THEME', '1.1.572');
}
