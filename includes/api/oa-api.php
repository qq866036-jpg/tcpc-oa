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
                    'GET /manifest'   => array('read_only' => true, 'cap' => 'read'),
                    'GET /health'     => array('read_only' => true, 'cap' => 'read'),
                    'GET /legal/(?P<doc>[A-Za-z0-9._-]{1,64})' => array('read_only' => true, 'cap' => 'read'),
                    'GET /doc/inbox'  => array('read_only' => true, 'cap' => 'read'),
                    'GET /files/(?P<id>\d+)' => array('read_only' => true, 'cap' => 'read'),
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

if (!function_exists('tcpc_oa_api_rest_legal')) {
    /**
     * `GET /tcpc-oa/v1/legal/{doc}` —— 当前生效条款（只读）。
     *
     * 降级：读源缺失 ⇒ 503 `LEGAL_UNAVAILABLE`（上游 degrade=empty 的语义）；
     * 文档不存在 ⇒ 404；内部/禁读文档 ⇒ 404。
     * 铁律 19：`/bhzc`（章程官网页）**永不可读**，doc id 命中直接拒绝。
     *
     * @param WP_REST_Request $request 请求。
     * @return array|WP_Error
     */
    function tcpc_oa_api_rest_legal($request) {
        $doc = (string) $request->get_param('doc');
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $doc)) {
            return new WP_Error('LEGAL_DOC_INVALID', '文档编号不合法。', array('status' => 400));
        }
        if (strtolower($doc) === 'bhzc') {
            return new WP_Error('LEGAL_DOC_FORBIDDEN', '该页面不在条款读源范围内。', array('status' => 404));
        }
        if (!function_exists('zw_legal_text_current')) {
            return new WP_Error('LEGAL_UNAVAILABLE', '条款读源不可用。', array('status' => 503));
        }
        if (function_exists('zw_legal_text_visibility') && (string) zw_legal_text_visibility($doc) !== 'public') {
            return new WP_Error('LEGAL_DOC_NOT_PUBLIC', '该文档不对外公开。', array('status' => 404));
        }
        $current = zw_legal_text_current($doc);
        if (!is_array($current) || $current === array()) {
            return new WP_Error('LEGAL_DOC_NOT_FOUND', '未找到该文档的生效版本。', array('status' => 404));
        }
        return array(
            'doc_id'       => $doc,
            'version_no'   => (string) ($current['version_no'] ?? ''),
            'effective_at' => (string) ($current['effective_at'] ?? ''),
            'body_hash'    => (string) ($current['body_hash'] ?? ''),
            'body_html'    => (string) ($current['body_html'] ?? ''),
            'degrade'      => 'empty',
        );
    }
}

if (!function_exists('tcpc_oa_api_rest_doc_inbox')) {
    /**
     * `GET /tcpc-oa/v1/doc/inbox` —— 当前用户收文（只读）。
     *
     * 降级（degrade=empty）：查询器缺失 ⇒ 200 + `available=false` + 空列表（调用方按空处理）。
     *
     * @param WP_REST_Request $request 请求。
     * @return array|WP_Error
     */
    function tcpc_oa_api_rest_doc_inbox($request) {
        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($uid <= 0) {
            return new WP_Error('DOC_INBOX_UNAUTHORIZED', '未登录。', array('status' => 401));
        }
        $page = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        $per_page = $per_page > 0 ? min(50, $per_page) : 10;
        if (!function_exists('zw_oa_doc_inbox_query')) {
            return array('available' => false, 'reason' => 'accessor_missing', 'items' => array(), 'total' => 0, 'pages' => 0, 'is_admin' => false, 'degrade' => 'empty');
        }
        $r = zw_oa_doc_inbox_query($uid, $page, $per_page);
        $r = is_array($r) ? $r : array();
        return array(
            'available' => !empty($r['available']),
            'reason'    => (string) ($r['reason'] ?? ''),
            'items'     => isset($r['items']) && is_array($r['items']) ? array_values($r['items']) : array(),
            'total'     => (int) ($r['total'] ?? 0),
            'pages'     => (int) ($r['pages'] ?? 0),
            'is_admin'  => !empty($r['is_admin']),
            'degrade'   => 'empty',
        );
    }
}

if (!function_exists('tcpc_oa_api_rest_file')) {
    /**
     * `GET /tcpc-oa/v1/files/{id}` —— 文件**元数据**（只读，脱敏）。
     *
     * 越权面（fail-closed）：未登录 401；存储契约缺失 503（degrade=deny）；
     * 非本人且无 `manage_options` ⇒ 403。**只回白名单字段**，绝不返回物理路径 / 直链。
     *
     * @param WP_REST_Request $request 请求。
     * @return array|WP_Error
     */
    function tcpc_oa_api_rest_file($request) {
        $id = (int) $request->get_param('id');
        if ($id <= 0) {
            return new WP_Error('FILE_ID_INVALID', '文件编号不合法。', array('status' => 400));
        }
        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($uid <= 0) {
            return new WP_Error('FILE_UNAUTHORIZED', '未登录。', array('status' => 401));
        }
        if (!function_exists('zw_oa_storage_get')) {
            return new WP_Error('STORAGE_UNAVAILABLE', '文件存储服务不可用。', array('status' => 503));
        }
        $file = zw_oa_storage_get($id);
        if (!is_array($file) || empty($file['id'])) {
            return new WP_Error('FILE_NOT_FOUND', '未找到该文件。', array('status' => 404));
        }
        $owner = (int) ($file['owner_user_id'] ?? 0);
        $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
        if (!$is_admin && $owner !== $uid) {
            return new WP_Error('FILE_FORBIDDEN', '无权读取该文件。', array('status' => 403));
        }
        return array(
            'id'      => (int) $file['id'],
            'title'   => (string) ($file['title'] ?? ''),
            'mime'    => (string) ($file['mime'] ?? ''),
            'size'    => (int) ($file['size'] ?? 0),
            'kind'    => (string) ($file['kind'] ?? ''),
            'scope'   => (string) ($file['scope'] ?? ''),
            'exists'  => !empty($file['exists']),
            'degrade' => 'deny',
        );
    }
}

if (!function_exists('tcpc_oa_api_register_rest')) {
    /**
     * 注册 `/tcpc-oa/v1` 只读路由。
     *
     * 全部 `GET` + `read` 能力位（manifest 的 `rest` 段与门禁只允许只读方法）。
     * `policy/check` **不提供 REST**：它需要结构化请求上下文，用 GET 伪装「读取」会诱导调用方
     * 把「判定」当无副作用；该能力继续走 PHP 符号契约（`tcpc_oa_api_call('policy.check', …)`）。
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
        register_rest_route('tcpc-oa/v1', '/legal/(?P<doc>[A-Za-z0-9._-]{1,64})', array(
            'methods'             => 'GET',
            'permission_callback' => 'tcpc_oa_api_rest_permission',
            'callback'            => 'tcpc_oa_api_rest_legal',
        ));
        register_rest_route('tcpc-oa/v1', '/doc/inbox', array(
            'methods'             => 'GET',
            'permission_callback' => 'tcpc_oa_api_rest_permission',
            'callback'            => 'tcpc_oa_api_rest_doc_inbox',
        ));
        register_rest_route('tcpc-oa/v1', '/files/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'permission_callback' => 'tcpc_oa_api_rest_permission',
            'callback'            => 'tcpc_oa_api_rest_file',
        ));
    }
}

if (function_exists('add_action')) {
    add_action('rest_api_init', 'tcpc_oa_api_register_rest');
}
