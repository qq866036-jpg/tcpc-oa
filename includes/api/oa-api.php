<?php
/**
 * tcpc-oa · 对外 API 清单与版本握手（OA-2499 / TASK-2814 S4）。
 *
 * 定位：OA 与主题 / 分支机构 / 证书 / 小程序 / 工作台之间的**唯一能力清单**。
 *  - PHP 符号契约：同库同请求直接 `call_user_func_array`（零网络开销、可 fail-closed）；
 *  - REST：跨端/小程序走 `/wp-json/tcpc-oa/v1/*`（只读，能力位门禁）；
 *  - 版本握手：`tcpc_oa_api_version()` + `tcpc_oa_api_supports()`；消费方必须先问支持再调用；
 *  - 降级语义：每个符号声明 `degrade`（`empty` / `fail_closed` / `deny`），缺位不得静默成功。
 *
 * 纪律：本文件只做清单/路由/委托，**不复制**业务实现；不新增写路由；不反向 require 主题文件。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('TCPC_OA_API_VERSION')) {
    define('TCPC_OA_API_VERSION', 1);
}

if (!function_exists('tcpc_oa_api_version')) {
    /**
     * API 版本（破坏性变更 +1；新增只读符号不升版本）。
     *
     * @return int
     */
    function tcpc_oa_api_version() {
        return (int) TCPC_OA_API_VERSION;
    }
}

if (!function_exists('tcpc_oa_api_manifest')) {
    /**
     * 能力清单（唯一出处）。
     *
     * 每个符号：provider（oa/theme/branch）、version、php（PHP 符号，可空=仅 REST）、
     * degrade（empty/fail_closed/deny）、consumers（消费方仓）。
     *
     * @return array<string,mixed>
     */
    function tcpc_oa_api_manifest() {
        return array(
            'api_version' => tcpc_oa_api_version(),
            'plugin'      => 'tcpc-oa',
            'namespace'   => 'tcpc-oa/v1',
            'symbols'     => array(
                'legal.text.current' => array(
                    'provider'  => 'oa',
                    'version'   => 1,
                    'php'       => 'zw_legal_text_current',
                    'degrade'   => 'empty',
                    'consumers' => array('theme', 'mapp'),
                    'since'     => 'OA-2499',
                ),
                'legal.text.render' => array(
                    'provider'  => 'oa',
                    'version'   => 1,
                    'php'       => 'zw_legal_text_render',
                    'degrade'   => 'empty',
                    'consumers' => array('theme', 'mapp'),
                    'since'     => 'OA-2499',
                ),
                'legal.text.public_url' => array(
                    'provider'  => 'oa',
                    'version'   => 1,
                    'php'       => 'zw_legal_public_doc_url',
                    'degrade'   => 'empty',
                    'consumers' => array('theme', 'mapp'),
                    'since'     => 'OA-2499',
                ),
                'legal.consent.enforce' => array(
                    'provider'  => 'oa',
                    'version'   => 1,
                    'php'       => 'zw_legal_consent_enforce_enabled',
                    'degrade'   => 'fail_closed',
                    'consumers' => array('theme'),
                    'since'     => 'OA-2499',
                ),
                'legal.consent.must_sign' => array(
                    'provider'  => 'oa',
                    'version'   => 1,
                    'php'       => 'zw_legal_consent_must_sign',
                    'degrade'   => 'fail_closed',
                    'consumers' => array('theme', 'branch'),
                    'since'     => 'OA-2499',
                ),
                'policy.check' => array(
                    'provider'  => 'oa',
                    'version'   => 1,
                    'php'       => 'zw_policy_check',
                    'degrade'   => 'fail_closed',
                    'consumers' => array('theme', 'branch', 'cert'),
                    'since'     => 'OA-2499',
                ),
                'policy.gate_enabled' => array(
                    'provider'  => 'oa',
                    'version'   => 1,
                    'php'       => 'zw_policy_gate_enabled',
                    'degrade'   => 'fail_closed',
                    'consumers' => array('theme', 'branch'),
                    'since'     => 'OA-2499',
                ),
                'doc.inbox.query' => array(
                    'provider'  => 'oa',
                    'version'   => 1,
                    'php'       => 'zw_oa_doc_inbox_query',
                    'degrade'   => 'empty',
                    'consumers' => array('theme', 'mapp'),
                    'since'     => 'OA-2499',
                ),
                'storage.get' => array(
                    'provider'  => 'theme',
                    'version'   => 1,
                    'php'       => 'zw_oa_storage_get',
                    'degrade'   => 'deny',
                    'consumers' => array('oa', 'branch', 'cert'),
                    'since'     => 'OA-2499',
                ),
                'org.person.get' => array(
                    'provider'  => 'theme',
                    'version'   => 1,
                    'php'       => 'zw_org_person_meta',
                    'degrade'   => 'empty',
                    'consumers' => array('oa', 'branch', 'cert'),
                    'since'     => 'OA-2499',
                ),
                'dashboard.snapshot' => array(
                    'provider'  => 'oa',
                    'version'   => 1,
                    'php'       => 'zw_oa_contract_dashboard_snapshot',
                    'degrade'   => 'empty',
                    'consumers' => array('theme', 'workbench'),
                    'since'     => 'OA-2499',
                ),
            ),
            'rest' => array(
                'namespace' => 'tcpc-oa/v1',
                'routes'    => array(
                    'GET /manifest' => array('read_only' => true, 'cap' => 'read'),
                    'GET /health'   => array('read_only' => true, 'cap' => 'read'),
                ),
            ),
        );
    }
}

if (!function_exists('tcpc_oa_api_supports')) {
    /**
     * 当前 API 版本是否登记了某符号（**只问清单**，不代表运行时可用）。
     *
     * @param string $key 符号键，如 `legal.text.current`。
     * @return bool
     */
    function tcpc_oa_api_supports($key) {
        $m = tcpc_oa_api_manifest();
        return isset($m['symbols'][(string) $key]);
    }
}

if (!function_exists('tcpc_oa_api_available')) {
    /**
     * 符号在**当前请求**是否真的可调（PHP 符号已声明）。
     *
     * @param string $key 符号键。
     * @return bool
     */
    function tcpc_oa_api_available($key) {
        $m = tcpc_oa_api_manifest();
        $key = (string) $key;
        if (!isset($m['symbols'][$key])) {
            return false;
        }
        $php = (string) ($m['symbols'][$key]['php'] ?? '');
        return $php !== '' && function_exists($php);
    }
}

if (!function_exists('tcpc_oa_api_degrade')) {
    /**
     * 取某符号的降级语义。
     *
     * @param string $key 符号键。
     * @return string `empty`|`fail_closed`|`deny`|`none`
     */
    function tcpc_oa_api_degrade($key) {
        $m = tcpc_oa_api_manifest();
        $key = (string) $key;
        return isset($m['symbols'][$key]['degrade']) ? (string) $m['symbols'][$key]['degrade'] : 'none';
    }
}

if (!function_exists('tcpc_oa_api_call')) {
    /**
     * 通过清单调用 PHP 符号（消费方统一入口）。
     *
     * 返回结构化结果，**不抛异常**：
     *   ok=true  ⇒ data=调用结果
     *   ok=false ⇒ code=unknown|unavailable，degrade=声明的降级语义，由消费方按语义处理。
     *
     * @param string $key  符号键。
     * @param array  $args 位置参数（按顺序）。
     * @return array{ok:bool,code:string,degrade:string,data:mixed}
     */
    function tcpc_oa_api_call($key, $args = array()) {
        $key = (string) $key;
        if (!tcpc_oa_api_supports($key)) {
            return array('ok' => false, 'code' => 'unknown', 'degrade' => 'none', 'data' => null);
        }
        if (!tcpc_oa_api_available($key)) {
            return array('ok' => false, 'code' => 'unavailable', 'degrade' => tcpc_oa_api_degrade($key), 'data' => null);
        }
        $m = tcpc_oa_api_manifest();
        $php = (string) $m['symbols'][$key]['php'];
        $data = call_user_func_array($php, array_values((array) $args));
        return array('ok' => true, 'code' => 'ok', 'degrade' => tcpc_oa_api_degrade($key), 'data' => $data);
    }
}

if (!function_exists('tcpc_oa_api_health')) {
    /**
     * 只读健康快照（版本 + 模块开关 + 符号可用数）。
     *
     * @return array<string,mixed>
     */
    function tcpc_oa_api_health() {
        $m = tcpc_oa_api_manifest();
        $available = 0;
        foreach (array_keys($m['symbols']) as $key) {
            if (tcpc_oa_api_available($key)) {
                $available++;
            }
        }
        $modules = array();
        if (function_exists('tcpc_oa_modules') && function_exists('tcpc_oa_module_enabled')) {
            foreach (array_keys(tcpc_oa_modules()) as $module) {
                $modules[$module] = (bool) tcpc_oa_module_enabled($module);
            }
        }
        return array(
            'ok'            => true,
            'plugin'        => 'tcpc-oa',
            'api_version'   => tcpc_oa_api_version(),
            'symbols_total' => count($m['symbols']),
            'symbols_available' => $available,
            'modules'       => $modules,
        );
    }
}

/* ───────────── REST（只读） ───────────── */

if (!function_exists('tcpc_oa_api_rest_permission')) {
    /**
     * REST 只读门禁：登录即可读（清单不含个人信息；健康快照不含密钥）。
     *
     * @return bool
     */
    function tcpc_oa_api_rest_permission() {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return false;
        }
        return function_exists('current_user_can') ? (bool) current_user_can('read') : false;
    }
}

if (!function_exists('tcpc_oa_api_register_rest')) {
    /**
     * 注册 `/tcpc-oa/v1` 只读路由（manifest / health）。
     *
     * @return void
     */
    function tcpc_oa_api_register_rest() {
        if (!function_exists('register_rest_route')) {
            return;
        }
        register_rest_route('tcpc-oa/v1', '/manifest', array(
            'methods'             => 'GET',
            'permission_callback' => 'tcpc_oa_api_rest_permission',
            'callback'            => function () {
                return tcpc_oa_api_manifest();
            },
        ));
        register_rest_route('tcpc-oa/v1', '/health', array(
            'methods'             => 'GET',
            'permission_callback' => 'tcpc_oa_api_rest_permission',
            'callback'            => function () {
                return tcpc_oa_api_health();
            },
        ));
    }
}

if (function_exists('add_action')) {
    add_action('rest_api_init', 'tcpc_oa_api_register_rest');
}
