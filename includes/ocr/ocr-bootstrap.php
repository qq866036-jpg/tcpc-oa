<?php
/**
 * 收文管理 · OCR 接入入口（OA-2463）。
 *
 * 装载顺序（依赖自上而下，全部 `require_once` 幂等）：
 *   config（开关/端点/白名单）→ schema（列探测）→ client（HTTP 契约）→
 *   job（排队/执行/重试）→ admin（设置页）。
 *
 * 只在「收文模块已启用」时被 `receipt-bootstrap.php` 引入；OCR 自身再由
 * `zw_ocr_is_enabled()` 二次开关（默认关）⇒ 未部署服务时**零行为**：
 * 不排队、不发请求、不注册 admin-post 入口、不写任何列。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/ocr-config.php';
require_once __DIR__ . '/ocr-schema.php';
require_once __DIR__ . '/ocr-client.php';
require_once __DIR__ . '/ocr-job.php';
require_once __DIR__ . '/ocr-admin.php';

if (function_exists('add_action')) {
    // cron 与 admin-post 入口只在启用了 OCR 时挂（`zw_ocr_register_hooks()` 内部判开关）。
    add_action('init', 'zw_ocr_register_hooks');
}
