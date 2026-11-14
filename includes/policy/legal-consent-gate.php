<?php
/**
 * 条款与签署 · 前端拦截判定（SPEC-88 · TASK-966 / T3）。
 *
 * 本文件只放**纯判定与文案**函数，**不注册任何钩子**（钩子在 functions.php，
 * 便于「判定逻辑」被 tests/test-legal-consent-ui.php 用最小 WordPress 桩独立单测）。
 *
 * 职责（开发方案 §4 的「拦截点与流程」）：
 *  - 首登探针（transient）与「只在 GET 上跳转」的保护（POST 数据不得被 302 吞掉）；
 *  - 持续判定：机构管理区路径（默认 `user/branch`，filter 可扩展）+ 后台机构相关页（D7）；
 *  - `wp-admin/admin-ajax.php` 的机构动作改为 **JSON 拒绝**（不返回 HTML 登录页）；
 *  - `return` 跳转只允许站内白名单（防开放重定向）；
 *  - 未签署提示页 HTML（HTTP 200，文案唯一出处）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_LEGAL_CONSENT_PROBE_PREFIX')) {
    define('ZW_LEGAL_CONSENT_PROBE_PREFIX', 'zw_legal_consent_probe_');
}

if (!function_exists('zw_legal_consent_set_notice')) {
    /**
     * 写入一次性页面提示（POST 处理器 → 页面渲染，请求内传递，不落库）。
     *
     * @param string $type error|success
     * @param string $msg  提示文案。
     * @return void
     */
    function zw_legal_consent_set_notice($type, $msg) {
        $GLOBALS['zw_legal_consent_notice'] = array(
            'type' => ((string) $type === 'success') ? 'success' : 'error',
            'msg'  => (string) $msg,
        );
    }
}

if (!function_exists('zw_legal_consent_get_notice')) {
    /**
     * 读取页面提示（无则 null）。
     *
     * @return array|null
     */
    function zw_legal_consent_get_notice() {
        $n = isset($GLOBALS['zw_legal_consent_notice']) ? $GLOBALS['zw_legal_consent_notice'] : null;
        if (!is_array($n) || empty($n['msg'])) { return null; }
        return array(
            'type' => ((string) ($n['type'] ?? 'error') === 'success') ? 'success' : 'error',
            'msg'  => (string) $n['msg'],
        );
    }
}

if (!function_exists('zw_legal_consent_probe_key')) {
    /**
     * 首登探针的 transient 键。
     *
     * @param int $user_id 用户 ID。
     * @return string
     */
    function zw_legal_consent_probe_key($user_id) {
        return ZW_LEGAL_CONSENT_PROBE_PREFIX . (int) $user_id;
    }
}

if (!function_exists('zw_legal_consent_request_path')) {
    /**
     * 当前请求路径（去首尾斜杠、去重复斜杠、去查询串）。
     *
     * @return string
     */
    function zw_legal_consent_request_path() {
        $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if (function_exists('wp_unslash')) { $req = wp_unslash($req); }
        $path = trim((string) strtok($req, '?'), '/');
        return (string) preg_replace('#/+$#', '', $path);
    }
}

if (!function_exists('zw_legal_consent_current_url')) {
    /**
     * 当前请求的站内绝对地址（用于 `return`，去除控制字符与绝对 URL 前缀）。
     *
     * @return string
     */
    function zw_legal_consent_current_url() {
        $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        if (function_exists('wp_unslash')) { $req = wp_unslash($req); }
        $req = (string) preg_replace('/[\r\n\t]+/', '', $req);
        $req = (string) preg_replace('#^https?://[^/]+#i', '', $req);
        if ($req === '' || $req[0] !== '/') { $req = '/' . ltrim($req, '/'); }
        return function_exists('home_url') ? home_url($req) : $req;
    }
}

if (!function_exists('zw_legal_consent_intercept_paths')) {
    /**
     * 持续拦截的前台路径（唯一出处；默认只拦机构管理区）。
     *
     * @return string[]
     */
    function zw_legal_consent_intercept_paths() {
        $paths = array('user/branch');
        if (function_exists('apply_filters')) {
            $paths = (array) apply_filters('zw_legal_consent_intercept_paths', $paths);
        }
        $out = array();
        foreach ($paths as $p) {
            $p = trim((string) $p, '/');
            if ($p !== '') { $out[] = $p; }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('zw_legal_consent_path_intercepted')) {
    /**
     * 前台路径是否在持续拦截面内（含子路径）。
     *
     * @param string $path 去首尾斜杠的路径。
     * @return bool
     */
    function zw_legal_consent_path_intercepted($path) {
        $path = trim((string) $path, '/');
        if ($path === '') { return false; }
        foreach (zw_legal_consent_intercept_paths() as $p) {
            if ($path === $p || strpos($path, $p . '/') === 0) { return true; }
        }
        return false;
    }
}

if (!function_exists('zw_legal_consent_admin_path_intercepted')) {
    /**
     * 后台路径是否拦截（D7：后台也拦）。
     *
     * 安全边界（不得误伤）：
     *  - 只拦 **GET**：后台表单 POST（`admin-post.php`）被 302 会丢数据；
     *  - 跳过 `admin-ajax.php`（走 JSON 拒绝分支）、`admin-post.php`、`wp-login.php`、
     *    `wp-cron.php`、`async-upload.php`；
     *  - 站点管理员（`manage_options`）一律放行（防把自己锁在后台外）。
     *
     * @param string $path   去首尾斜杠路径。
     * @param string $method 请求方法。
     * @return bool
     */
    function zw_legal_consent_admin_path_intercepted($path, $method = 'GET') {
        $path = trim((string) $path, '/');
        if (strpos($path, 'wp-admin') !== 0) { return false; }
        if (strtoupper((string) $method) !== 'GET') { return false; }
        foreach (array('wp-admin/admin-ajax.php', 'wp-admin/admin-post.php', 'wp-admin/async-upload.php', 'wp-admin/media-upload.php') as $skip) {
            if ($path === $skip) { return false; }
        }
        if (function_exists('current_user_can') && current_user_can('manage_options')) { return false; }
        return true;
    }
}

if (!function_exists('zw_legal_consent_probe_redirect_allowed')) {
    /**
     * 首登探针是否允许用 302 跳转（**只允许 GET/HEAD**，避免吞掉 POST 数据）。
     *
     * @param string $method 请求方法。
     * @return bool
     */
    function zw_legal_consent_probe_redirect_allowed($method) {
        return in_array(strtoupper((string) $method), array('GET', 'HEAD'), true);
    }
}

if (!function_exists('zw_legal_consent_ajax_action_intercepted')) {
    /**
     * admin-ajax 动作是否属于「机构管理动作」（未签署时必须 JSON 拒绝）。
     *
     * @param string $action 动作名。
     * @return bool
     */
    function zw_legal_consent_ajax_action_intercepted($action) {
        $action = trim((string) $action);
        if ($action === '') { return false; }
        $prefixes = array('zw_branch_');
        if (function_exists('apply_filters')) {
            $prefixes = (array) apply_filters('zw_legal_consent_intercept_ajax_prefixes', $prefixes);
        }
        foreach ($prefixes as $p) {
            $p = (string) $p;
            if ($p !== '' && strpos($action, $p) === 0) { return true; }
        }
        return false;
    }
}

if (!function_exists('zw_legal_consent_request_exempt')) {
    /**
     * 与签署无关、必须放行的请求前缀（登录/退出/静态/接口/计划任务）。
     *
     * @param string $path 去首尾斜杠路径。
     * @return bool
     */
    function zw_legal_consent_request_exempt($path) {
        $path = trim((string) $path, '/');
        if ($path === '') { return false; }
        if ($path === 'user/agreement') { return true; }   // 签署页自身
        foreach (array('wp-login', 'wp-json', 'wp-content', 'wp-includes', 'wp-cron.php', 'xmlrpc.php', 'favicon.ico', 'robots.txt') as $p) {
            if ($path === $p || strpos($path, $p . '/') === 0 || strpos($path, $p . '.php') === 0) { return true; }
        }
        return false;
    }
}

if (!function_exists('zw_legal_consent_safe_return')) {
    /**
     * `return` 跳转白名单：只接受同站地址，且排除签署页自身/登录/退出（防开放重定向与死循环）。
     *
     * @param string $url 候选地址。
     * @return string 合法地址；不合法返回空串。
     */
    function zw_legal_consent_safe_return($url) {
        $url = trim((string) $url);
        if ($url === '') { return ''; }
        $url = function_exists('zw_front_login_target') ? zw_front_login_target($url) : '';
        if ($url === '') { return ''; }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === 'user/agreement') { return ''; }
        $lower = strtolower($url);
        if (strpos($path, 'wp-login') === 0 || strpos($lower, 'action=logout') !== false || strpos($lower, 'wp_logout') !== false) {
            return '';
        }
        return $url;
    }
}

if (!function_exists('zw_legal_consent_branch_nda_doc_id')) {
    /**
     * 分支机构负责人保密协议的规范 ID（TASK-1254）。
     *
     * 唯一出处：阅读时长覆盖（30 秒）与签署后回跳「机构概况」共用，禁止在页面/处理器里写字面量。
     *
     * @return string
     */
    function zw_legal_consent_branch_nda_doc_id() {
        return 'SRC-NDA-BRANCH';
    }
}

if (!function_exists('zw_legal_consent_branch_nda_min_read')) {
    /**
     * 分支机构负责人保密协议的最短阅读时长（秒）——用户 2026-10-08 拍板 **30 秒**（TASK-1254 ①）。
     *
     * @return int
     */
    function zw_legal_consent_branch_nda_min_read() {
        return 30;
    }
}

if (!function_exists('zw_legal_consent_branch_overview_url')) {
    /**
     * 分支机构管理-机构概况地址（TASK-1254 ② 签署成功后回跳口径）。
     *
     * @return string
     */
    function zw_legal_consent_branch_overview_url() {
        $base = function_exists('home_url') ? home_url('/user/branch/') : '/user/branch/';
        if (!function_exists('add_query_arg')) { return $base; }
        return (string) add_query_arg(array('tab' => 'org', 'view' => 'overview'), $base);
    }
}

/**
 * 阅读时长覆盖（TASK-1254 ①）：分支机构负责人保密协议固定 `zw_legal_consent_branch_nda_min_read()` 秒。
 *
 * 走引擎既有 filter `zw_legal_consent_min_read_seconds`，**不改引擎三态（0/固定/auto）语义**，
 * 也不影响其它协议；签署页倒数控件与服务端时长校验同读 `zw_legal_consent_reading_plan()`，自动生效。
 * 注册点放在本 canonical 文件（与取数口径同处），避免主题加载顺序差异导致漏挂。
 */
if (function_exists('add_filter')) {
    add_filter('zw_legal_consent_min_read_seconds', 'zw_legal_consent_branch_nda_min_read_filter', 10, 2);
}
if (!function_exists('zw_legal_consent_branch_nda_min_read_filter')) {
    /**
     * @param int    $min    引擎算出的最短阅读秒数。
     * @param string $doc_id 规范/协议 ID。
     * @return int
     */
    function zw_legal_consent_branch_nda_min_read_filter($min, $doc_id = '') {
        if ((string) $doc_id === zw_legal_consent_branch_nda_doc_id()) {
            return (int) zw_legal_consent_branch_nda_min_read();
        }
        return (int) $min;
    }
}

if (!function_exists('zw_legal_consent_sign_url')) {
    /**
     * 签署页地址（带待签协议与站内 return）。
     *
     * @param string $doc_id 协议 ID。
     * @param string $return 签署完成后希望回到的站内地址（不合法则忽略）。
     * @return string
     */
    function zw_legal_consent_sign_url($doc_id, $return = '') {
        $args = array();
        $doc_id = trim((string) $doc_id);
        if ($doc_id !== '') { $args['doc'] = $doc_id; }
        $back = zw_legal_consent_safe_return($return);
        if ($back !== '') { $args['return'] = $back; }
        $base = function_exists('home_url') ? home_url('/user/agreement/') : '/user/agreement/';
        if (!$args || !function_exists('add_query_arg')) { return $base; }
        return (string) add_query_arg($args, $base);
    }
}

if (!function_exists('zw_legal_consent_pending_first_doc_id')) {
    /**
     * 待签清单里的第一份协议 ID。
     *
     * @param array $pending zw_legal_consent_must_sign() 的结果。
     * @return string
     */
    function zw_legal_consent_pending_first_doc_id($pending) {
        if (!is_array($pending) || !isset($pending[0]) || !is_array($pending[0])) { return ''; }
        $doc = $pending[0]['doc'] ?? array();
        return is_array($doc) ? trim((string) ($doc['doc_id'] ?? '')) : '';
    }
}

if (!function_exists('zw_legal_consent_notice_text')) {
    /**
     * 未签署提示文案（唯一出处；签署页与提示页共用同一句）。
     *
     * @param array $pending 待签清单。
     * @return string
     */
    function zw_legal_consent_notice_text($pending) {
        $first = (is_array($pending) && isset($pending[0]) && is_array($pending[0])) ? $pending[0] : null;
        $doc   = $first ? ($first['doc'] ?? null) : null;
        $name  = is_array($doc) ? trim((string) ($doc['doc_name'] ?? '')) : '';
        if ($name === '') {
            return '您有尚未确认的规范（协议），暂不能使用机构管理功能。个人中心其他功能不受影响。';
        }
        $label = function_exists('zw_legal_consent_version_label')
            ? zw_legal_consent_version_label((string) ($doc['version_no'] ?? ''))
            : trim((string) ($doc['version_no'] ?? ''));
        $title = $label !== '' ? $name . '（' . $label . '）' : $name;
        $text  = '未签署《' . $title . '》，暂不能使用机构管理功能。个人中心其他功能不受影响。';
        if (is_array($pending) && count($pending) > 1) {
            $text .= '另有 ' . (count($pending) - 1) . ' 份规范待确认。';
        }
        return $text;
    }
}

if (!function_exists('zw_legal_consent_notice_title')) {
    /**
     * 未签署提示页标题（唯一出处，TASK-1035）。
     *
     * 口径（用户 2026-10-06 拍板）：提示页标题＝**待签协议名**（如《分支机构负责人保密协议》），
     * 不再是原来那个没有主体的「需要先完成签署」；取不到协议名时回退通用标题。
     *
     * @param array $pending 待签清单。
     * @return string
     */
    function zw_legal_consent_notice_title($pending) {
        $first = (is_array($pending) && isset($pending[0]) && is_array($pending[0])) ? $pending[0] : null;
        $doc   = $first ? ($first['doc'] ?? null) : null;
        $name  = is_array($doc) ? trim((string) ($doc['doc_name'] ?? '')) : '';
        return $name !== '' ? $name : '需要先完成签署';
    }
}

if (!function_exists('zw_legal_consent_notice_pending_list')) {
    /**
     * 待签清单片段（提示页 / 降级页共用；含文档名、版本徽章与原因）。
     *
     * @param array $pending 待签清单。
     * @return string HTML
     */
    function zw_legal_consent_notice_pending_list($pending) {
        if (!is_array($pending) || !$pending) { return ''; }
        $esc = function ($v) {
            return function_exists('esc_html') ? esc_html((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $esc_url = function ($v) {
            return function_exists('esc_url') ? esc_url((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $html = '<h3 class="zw-legal-notice-sub">待确认的规范（' . (int) count($pending) . '）</h3><ul class="zw-legal-notice-list">';
        foreach ($pending as $item) {
            $doc = (is_array($item) && is_array($item['doc'] ?? null)) ? $item['doc'] : array();
            $did = trim((string) ($doc['doc_id'] ?? ''));
            $name = trim((string) ($doc['doc_name'] ?? ''));
            if ($name === '') { $name = $did; }
            $label = function_exists('zw_legal_consent_version_label')
                ? (string) zw_legal_consent_version_label((string) ($doc['version_no'] ?? ''))
                : trim((string) ($doc['version_no'] ?? ''));
            $state = ((string) ($item['reason'] ?? '') === 'version_changed') ? '规范已更新，需重新确认' : '待确认';
            $html .= '<li><a href="' . $esc_url(zw_legal_consent_sign_url($did, zw_legal_consent_current_url())) . '">' . $esc($name) . '</a>';
            if ($label !== '') { $html .= '<span class="zw-legal-notice-badge">' . $esc($label) . '</span>'; }
            $html .= '<span class="zw-legal-notice-state">' . $esc($state) . '</span></li>';
        }
        return $html . '</ul>';
    }
}

if (!function_exists('zw_legal_consent_notice_html')) {
    /**
     * 未签署提示页 HTML（**降级**独立最小页，HTTP 200；页面专属样式内联，不新增全局 CSS）。
     *
     * 仅在主题外壳不可用（`get_header` / `get_footer` / `zw_user_shell_open` 缺失）时使用；
     * 常规路径走 `zw_legal_consent_notice_page()`（统一 logo 页头页尾 + UID 个人页头 + 统一背景）。
     *
     * @param array $pending 待签清单。
     * @return string
     */
    function zw_legal_consent_notice_html($pending) {
        $text  = zw_legal_consent_notice_text($pending);
        $title = zw_legal_consent_notice_title($pending);
        $doc   = zw_legal_consent_pending_first_doc_id($pending);
        $url   = zw_legal_consent_sign_url($doc, zw_legal_consent_current_url());
        $esc   = function ($v) {
            return function_exists('esc_html') ? esc_html((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $esc_url = function ($v) {
            return function_exists('esc_url') ? esc_url((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $home = function_exists('home_url') ? home_url('/user/') : '/user/';

        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . $esc($title) . '</title><style>'
            . 'body{margin:0;background:#f5f6f7;color:#333;font:15px/1.7 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif}'
            . '.zw-legal-notice{max-width:640px;margin:8vh auto;padding:28px 24px;background:#fff;border:1px solid rgba(128,128,128,.18);border-radius:8px}'
            . '.zw-legal-notice h1{margin:0 0 14px;font-size:20px;font-weight:600}'
            . '.zw-legal-notice p{margin:0 0 12px}'
            . '.zw-legal-notice .zw-legal-notice-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:20px}'
            . '.zw-legal-notice a{display:inline-block;padding:10px 20px;border-radius:4px;text-decoration:none;font-size:15px}'
            . '.zw-legal-notice a.zw-legal-primary{background:var(--primary-color,#d80505);color:#fff}'
            . '@media(hover:hover){.zw-legal-notice a.zw-legal-primary:hover{background:#b30404}}'
            . '.zw-legal-notice a.zw-legal-ghost{border:1px solid rgba(128,128,128,.35);color:inherit}'
            . '.zw-legal-notice-sub{margin:18px 0 8px;font-size:15px;font-weight:600}'
            . '.zw-legal-notice-list{margin:0;padding:0;list-style:none}'
            . '.zw-legal-notice-list li{display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:8px 0;border-bottom:1px dashed rgba(128,128,128,.25);font-size:14px}'
            . '.zw-legal-notice-list li:last-child{border-bottom:0}'
            . '.zw-legal-notice-badge{padding:1px 8px;border-radius:10px;border:1px solid rgba(128,128,128,.3);font-size:12px;line-height:20px}'
            . '.zw-legal-notice-state{color:var(--primary-color,#d80505);font-size:13px}'
            . '@media(max-width:600px){.zw-legal-notice{margin:16px;padding:20px 16px}}'
            . '</style></head><body><main class="zw-legal-notice">'
            . '<h1>' . $esc($title) . '</h1>'
            . '<p>' . $esc($text) . '</p>'
            . zw_legal_consent_notice_pending_list($pending)
            . '<p>本次签署会记录时间、IP 与浏览器标识用于存证，说明见<a href="' . $esc_url(function_exists('home_url') ? home_url('/privacy-policy/') : '/privacy-policy/') . '">隐私政策</a>。</p>'
            . '<div class="zw-legal-notice-actions">'
            . '<a class="zw-legal-primary" href="' . $esc_url($url) . '">前往阅读并确认</a>'
            . '<a class="zw-legal-ghost" href="' . $esc_url($home) . '">返回服务中心</a>'
            . '</div></main></body></html>';
    }
}

if (!function_exists('zw_legal_consent_notice_page')) {
    /**
     * 未签署提示页（**主题内页面**，TASK-1035 · 用户指令）。
     *
     * 要求（用户 2026-10-06）：「该页面要有统一的网站 logo 页头、页尾和 uid 个人页头，和统一的背景」。
     * 做法：直接复用会员中心 canonical 外壳 `zw_user_shell_open()`（UID 资料卡 + 左侧导航），
     * 外层用主题 `get_header()` / `get_footer()`，从而继承站点 logo 页头、页尾与统一页面底色；
     * 页面专属样式内联在页尾（禁止新增全局 CSS 文件）。
     *
     * 降级：主题外壳不可用（函数缺失）→ 回退 `zw_legal_consent_notice_html()` 独立最小页。
     *
     * @param array $pending 待签清单。
     * @return string HTML
     */
    function zw_legal_consent_notice_page($pending) {
        if (!function_exists('get_header') || !function_exists('get_footer') || !function_exists('zw_user_shell_open') || !function_exists('zw_user_shell_close')) {
            return zw_legal_consent_notice_html($pending);
        }

        $title = zw_legal_consent_notice_title($pending);
        $text  = zw_legal_consent_notice_text($pending);
        $doc   = zw_legal_consent_pending_first_doc_id($pending);
        $url   = zw_legal_consent_sign_url($doc, zw_legal_consent_current_url());
        $esc   = function ($v) {
            return function_exists('esc_html') ? esc_html((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $esc_url = function ($v) {
            return function_exists('esc_url') ? esc_url((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $home = function_exists('home_url') ? home_url('/user/') : '/user/';
        $privacy = function_exists('home_url') ? home_url('/privacy-policy/') : '/privacy-policy/';

        ob_start();
        get_header();
        zw_user_shell_open('');
        ?>
        <div class="b-r-4 ceo-background-default zw-legal-notice-page">
            <div class="b-b user-title-top"><h2><?php echo $esc($title); ?></h2></div>
            <div class="user-box zw-legal-notice-box">
                <p class="zw-legal-notice-text"><?php echo $esc($text); ?></p>
                <?php echo zw_legal_consent_notice_pending_list($pending); ?>
                <p class="zw-legal-notice-foot">
                    本次签署会记录时间、IP 与浏览器标识用于存证，说明见
                    <a href="<?php echo $esc_url($privacy); ?>">隐私政策</a>；签署完成前，机构管理功能不可用，个人中心其他功能不受影响。
                </p>
                <div class="zw-legal-notice-actions">
                    <a class="zw-legal-primary" href="<?php echo $esc_url($url); ?>">前往阅读并确认</a>
                    <a class="zw-legal-ghost" href="<?php echo $esc_url($home); ?>">返回服务中心</a>
                </div>
            </div>
        </div>
        <style>
        /* TASK-1035：拦截提示页专属样式（内联，禁止新增全局 CSS 文件） */
        .zw-legal-notice-page .zw-legal-notice-box{padding:18px 20px 24px}
        .zw-legal-notice-text{margin:0 0 14px;font-size:15px;line-height:1.8}
        .zw-legal-notice-sub{margin:18px 0 8px;font-size:15px;font-weight:600}
        .zw-legal-notice-list{margin:0 0 14px;padding:0;list-style:none}
        .zw-legal-notice-list li{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:10px 0;border-bottom:1px dashed rgba(128,128,128,.25);font-size:14px}
        .zw-legal-notice-list li:last-child{border-bottom:0}
        .zw-legal-notice-list a{font-weight:600;color:inherit;text-decoration:none}
        @media(hover:hover){.zw-legal-notice-list a:hover{color:var(--primary-color,#d80505)}}
        .zw-legal-notice-badge{display:inline-flex;align-items:center;padding:1px 8px;border-radius:10px;border:1px solid rgba(128,128,128,.3);font-size:12px;line-height:20px}
        .zw-legal-notice-state{color:var(--primary-color,#d80505);font-size:13px}
        .zw-legal-notice-foot{margin:0 0 16px;font-size:12px;opacity:.72;line-height:1.7}
        .zw-legal-notice-foot a{color:var(--primary-color,#d80505)}
        .zw-legal-notice-actions{display:flex;flex-wrap:wrap;gap:12px}
        .zw-legal-notice-actions a{display:inline-block;min-height:40px;line-height:40px;padding:0 20px;border-radius:4px;text-decoration:none;font-size:15px}
        .zw-legal-notice-actions a.zw-legal-primary{background:var(--primary-color,#d80505);color:#fff}
        @media(hover:hover){.zw-legal-notice-actions a.zw-legal-primary:hover{background:#b30404}}
        .zw-legal-notice-actions a.zw-legal-ghost{border:1px solid rgba(128,128,128,.35);color:inherit}
        @media (max-width:600px){
            .zw-legal-notice-page .zw-legal-notice-box{padding:14px 12px 18px}
            .zw-legal-notice-actions a{width:100%;text-align:center}
        }
        @media (prefers-reduced-motion:reduce){.zw-legal-notice-page *{transition:none!important;animation:none!important}}
        </style>
        <?php
        zw_user_shell_close();
        get_footer();
        return (string) ob_get_clean();
    }
}
