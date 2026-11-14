<?php
/**
 * Plugin Name: 中传网办公协同
 * Plugin URI:  https://tcpc.org.cn/
 * Description: 中传网办公协同（发文管理 / 收文管理 / AI法务部 / AI秘书处 / 网盘文件中心 / 组织架构业务）。独立仓 tcpc-oa。
 * Version:     0.1.28
 * Author:      中国传统文化促进会
 * Requires PHP: 7.4
 * Text Domain: tcpc-oa
 *
 * ⚠️ 入口说明（TASK-2306 P2 骨架期 → 现行）：本文件只做**装载与版本声明** ——
 * 无条件装载 UI 基础设施与 OA API 清单（不注册菜单、不读业务数据，零行为）；
 * 业务模块按 `includes/load-list.php` 的「双钥匙」（清单 enabled + 常量/option 开关）装载。
 * 全部业务实现在 `includes/`，迁移与 parity 纪律见 `scripts/verify-module-parity.sh`
 * 与 `docs/contract-tcpc-oa.md`。
 *
 * 版本三方一致：VERSION == TCPC_OA_VERSION == 本文件 Version 头。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('TCPC_OA_VERSION')) {
    define('TCPC_OA_VERSION', '0.1.28');
}

if (!defined('TCPC_OA_DIR')) {
    define('TCPC_OA_DIR', __DIR__);
}

if (!defined('TCPC_OA_MIN_THEME')) {
    // 与 MIN_THEME 文件同源；发版脚本会校验两者一致。
    define('TCPC_OA_MIN_THEME', '1.1.572');
}

/**
 * 模块装载（TASK-2320 P2 正式迁入的第一个模块：收文管理）。
 *
 * 纪律：**双钥匙**才装载 —— ① `includes/load-list.php` 里该模块 `enabled=true`；
 * ② 常量开关（如 `TCPC_OA_ENABLE_RECEIPT`）为真。默认两者都不满足 ⇒ 本插件加载后
 * 对站点**零行为**（骨架期/灰度期安全）。
 *
 * 启用必须在「主题侧 shim + 插件部署」同一发布窗口完成，否则会出现两份加载。
 */
// OA-2491 / 铁律 32：呈现层基础设施（自有 tco-* 令牌 + 资源装载 + 后台壳 body class）。
// 与业务模块的「双钥匙」无关：本文件不注册菜单、不读业务数据，无条件装载也零行为。
if (file_exists(TCPC_OA_DIR . '/includes/ui/ui-bootstrap.php')) {
    require_once TCPC_OA_DIR . '/includes/ui/ui-bootstrap.php';
}
// OA-2499：对外 API 清单 / 版本握手 / 只读 REST（不注册菜单、不读业务数据，无条件装载零行为）。
if (file_exists(TCPC_OA_DIR . '/includes/api/oa-api.php')) {
    require_once TCPC_OA_DIR . '/includes/api/oa-api.php';
}
if (file_exists(TCPC_OA_DIR . '/includes/load-list.php')) {
    require_once TCPC_OA_DIR . '/includes/load-list.php';
    // AGENT-0248：工作台卡片 provider（只返回数据，渲染由 tcpc-workbench 负责）。
    if (file_exists(TCPC_OA_DIR . '/includes/workbench-card.php')) {
        require_once TCPC_OA_DIR . '/includes/workbench-card.php';
    }
    if (function_exists('tcpc_oa_load_modules')) {
        tcpc_oa_load_modules();
    }
}

/**
 * 契约 A · 规则引擎骨架（TASK-2320）：默认**不加载**，只有显式置
 * `TCPC_OA_LOAD_CONTRACT_SKELETON` 时才 require —— 骨架期对生产零影响。
 */
// TASK-2320：开关两种载体都认（常量优先，其次 option `tcpc_oa_load_contract_skeleton`，便于灰度/一键回退）。
if (!defined('TCPC_OA_CONTRACT_SKELETON_ON')) {
    $zw_oa_sk = (defined('TCPC_OA_LOAD_CONTRACT_SKELETON') && TCPC_OA_LOAD_CONTRACT_SKELETON)
        || (function_exists('get_option') && (int) get_option('tcpc_oa_load_contract_skeleton', 0) === 1);
    define('TCPC_OA_CONTRACT_SKELETON_ON', $zw_oa_sk);
}
if (TCPC_OA_CONTRACT_SKELETON_ON && file_exists(TCPC_OA_DIR . '/includes/oa/contract-policy.php')) {
    require_once TCPC_OA_DIR . '/includes/oa/contract-policy.php';
}
// 契约 C1~C3 · 发文读面骨架（同一开关，默认不加载）。
if (TCPC_OA_CONTRACT_SKELETON_ON && file_exists(TCPC_OA_DIR . '/includes/oa/contract-doc.php')) {
    require_once TCPC_OA_DIR . '/includes/oa/contract-doc.php';
}
