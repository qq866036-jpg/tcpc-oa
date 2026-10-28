<?php
/**
 * 契约 A · 规则引擎（骨架，零行为变更）—— TASK-2320。
 *
 * 方向：**插件提供 → 主题消费**。规则引擎属 AI法务部写侧，随法务部迁入本插件；
 * 主题侧的分支任免、会员准入等 Policy 挂点经本契约判定。
 *
 * 骨架期纪律（与主题 `inc/oa/contract-legal.php` 同构）：
 *   - 每次返回 `available` + `reason`（`table_missing|accessor_missing|engine_missing|provider_missing`）；
 *   - 引擎缺失时 `zw_oa_policy_check()` **fail-closed**（`allowed=false`），禁止静默放行；
 *   - 不复制 SQL、不写对方 meta；本文件只做委托与语义收敛。
 *
 * ⚠ 骨架期**不加载**：`includes/load-list.php` 未登记 policy 模块，`tcpc-oa.php` 只在
 * `TCPC_OA_LOAD_CONTRACT_SKELETON` 为真时 require 本文件，避免对生产产生任何影响。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_OA_POLICY_API_VERSION')) {
    define('ZW_OA_POLICY_API_VERSION', 1);
}

if (!function_exists('zw_oa_policy_api_version')) {
    /**
     * 版本握手。
     *
     * @return int
     */
    function zw_oa_policy_api_version() {
        return ZW_OA_POLICY_API_VERSION;
    }
}

if (!function_exists('zw_oa_policy_available')) {
    /**
     * 可用性：引擎函数在 ⇒ available；否则 engine_missing。
     *
     * @return array{available:bool,reason:string,source:string}
     */
    function zw_oa_policy_available() {
        if (!function_exists('zw_policy_check') && !function_exists('zw_policy_gate_enabled')) {
            return array('available' => false, 'reason' => 'engine_missing', 'source' => 'plugin');
        }
        return array('available' => true, 'reason' => '', 'source' => 'plugin');
    }
}

if (!function_exists('zw_oa_policy_gate_enabled')) {
    /**
     * 挂点开关（委托主题既有实现）。
     *
     * @param string $key 挂点键（如 `branch.leaders`）。
     * @return bool
     */
    function zw_oa_policy_gate_enabled($key) {
        if (!function_exists('zw_policy_gate_enabled')) {
            return false; // fail-closed：引擎缺失视为"未开启"，调用方须先判 available
        }
        return (bool) zw_policy_gate_enabled((string) $key);
    }
}

if (!function_exists('zw_oa_policy_gate_modules')) {
    /**
     * 模块开关表。
     *
     * @return array
     */
    function zw_oa_policy_gate_modules() {
        return function_exists('zw_policy_gate_modules') ? (array) zw_policy_gate_modules() : array();
    }
}

if (!function_exists('zw_oa_policy_gate_features')) {
    /**
     * 功能开关表。
     *
     * @return array
     */
    function zw_oa_policy_gate_features() {
        return function_exists('zw_policy_gate_features') ? (array) zw_policy_gate_features() : array();
    }
}

if (!function_exists('zw_oa_policy_check')) {
    /**
     * 规则引擎唯一判定（骨架：委托既有 `zw_policy_check()`）。
     *
     * @param string $ctx     上下文键。
     * @param array  $payload 事实载荷。
     * @return array{allowed:bool,reasons:array,version:int,available:bool}
     */
    function zw_oa_policy_check($ctx, $payload = array()) {
        $state = zw_oa_policy_available();
        if (empty($state['available'])) {
            return array(
                'allowed'   => false,
                'reasons'   => array('engine_missing'),
                'version'   => ZW_OA_POLICY_API_VERSION,
                'available' => false,
            );
        }
        if (!function_exists('zw_policy_check')) {
            // 引擎在但判定器缺：保持旧形状（hits 为空），并标 accessor_missing。
            return array(
                'hits'      => array(),
                'allowed'   => false,
                'reasons'   => array('accessor_missing'),
                'version'   => ZW_OA_POLICY_API_VERSION,
                'available' => true,
            );
        }
        $r = (array) zw_policy_check((string) $ctx, (array) $payload);
        // 形状与旧实现兼容：**保留 hits/其它既有键**（调用方读 $result['hits']），只追加契约元信息。
        $r['available'] = true;
        $r['version']   = ZW_OA_POLICY_API_VERSION;
        return $r;
    }
}

if (!function_exists('zw_oa_policy_rules_snapshot')) {
    /**
     * 规则快照（展示/审计用；骨架期未接线）。
     *
     * @param string $key 规则键。
     * @return array{available:bool,reason:string,version:int,rules:array}
     */
    function zw_oa_policy_rules_snapshot($key = '') {
        return array(
            'available' => false,
            'reason'    => 'provider_missing',
            'version'   => ZW_OA_POLICY_API_VERSION,
            'rules'     => array(),
        );
    }
}

if (!function_exists('zw_oa_policy_diagnostics')) {
    /**
     * 诊断（恒可用）。
     *
     * @return array
     */
    function zw_oa_policy_diagnostics() {
        $state = zw_oa_policy_available();
        return array(
            'api_version' => ZW_OA_POLICY_API_VERSION,
            'available'   => (bool) $state['available'],
            'reason'      => (string) $state['reason'],
            'source'      => 'plugin',
            'delegates'   => function_exists('zw_policy_gate_enabled'),
        );
    }
}
