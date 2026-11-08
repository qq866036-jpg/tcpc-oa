<?php
/**
 * 办公协同后台 UI · **自有令牌与 canonical 助手**（铁律 32 / CHG-466 / OA-2491）。
 *
 * ## 为什么本仓不再调主题样式函数
 *
 * 2026-11-09 用户拍板：「独立仓用完全独立的样式 ui js，工作台记录差异，防止修改一处样式
 * 影响关联的其他功能」（主题 `AGENTS.md` 铁律 32 / SPEC-10 §1.9.4）。
 * 本仓此前把后台样式寄生在主题的三个 canonical 上：
 *
 *   · 主题后台样式令牌文件的宽表横滑常量/函数与触屏块；
 *   · 主题会员域外壳的「当前是否会员域后台屏」判定（slug 前缀 `zw-policy` 命中才输出 shell CSS）；
 *   · 主题会员域的状态徽章助手（输出主题徽章类）。
 *
 * 三者都让「主题改一处样式 ⇒ 插件页静默变样」。本文件是**本仓唯一出处**：类名一律 `tco-*`，
 * 取值仍对齐主题 SPEC-10 §1.9 的令牌（`docs/tokens/tcpc-design-tokens.json`），但**声明归属本仓**。
 *
 * ## 与主题的差异（登记在主题 `docs/ops/satellite-ui-registry.yml`）
 *
 * - 类名前缀 `zw-*` → `tco-*`（含后台壳 body class `tco-oa-admin`）；
 * - 后台壳、宽表横滑、触屏热区、徽章、三态令牌由本仓 `assets/css/oa-admin.css` 自带；
 * - 不再依赖会员域后台壳（slug 前缀判定）；本仓页面按**自己的** slug 白名单判定。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 宽表横滑包裹类名（本仓唯一出处；不出现主题 `zw-adm-*` 命名空间）。 */
const TCO_UI_TABLE_WRAP_CLASS = 'tco-table-wrap';

/** 触屏热区容器类名（替换元素 checkbox/radio 由容器代理点击，唯一出处）。 */
const TCO_UI_TOUCH_CLASS = 'tco-touch';

/** 后台分组标题类名（唯一出处）。 */
const TCO_UI_SECTION_CLASS = 'tco-sec';

/** 后台三态类名（空 / 加载 / 错误，唯一出处）。 */
const TCO_UI_STATE_EMPTY_CLASS   = 'tco-empty';
const TCO_UI_STATE_LOADING_CLASS = 'tco-loading';
const TCO_UI_STATE_ERROR_CLASS   = 'tco-error';

/** 后台壳 body class（本仓自有；主题会员域用自己的 body class，两者互不依赖）。 */
const TCO_UI_BODY_CLASS = 'tco-oa-admin';

/** 样式/脚本资源句柄与版本。 */
const TCO_UI_ASSET_HANDLE = 'tcpc-oa-admin';

if (!function_exists('tco_ui_asset_ver')) {
    /**
     * 资源版本号（= 插件版本；发版脚本保证三方一致）。
     *
     * @return string
     */
    function tco_ui_asset_ver() {
        return defined('TCPC_OA_VERSION') ? (string) TCPC_OA_VERSION : '0.1.26';
    }
}

if (!function_exists('tco_ui_table_wrap_body')) {
    /**
     * 宽表横滑包裹的**声明体**（不含选择器；供页面级作用域自行拼装）。
     *
     * @return string
     */
    function tco_ui_table_wrap_body() {
        return '{width:100%;overflow-x:auto}';
    }
}

if (!function_exists('tco_ui_table_wrap_css')) {
    /**
     * 宽表横滑包裹的**完整规则**（无作用域前缀）。
     *
     * @return string
     */
    function tco_ui_table_wrap_css() {
        return '.' . TCO_UI_TABLE_WRAP_CLASS . tco_ui_table_wrap_body();
    }
}

if (!function_exists('tco_ui_section_css')) {
    /**
     * 后台「分组标题」令牌（唯一出处）。
     *
     * @return string
     */
    function tco_ui_section_css() {
        return '.' . TCO_UI_SECTION_CLASS . '{font-size:13px;font-weight:600;margin:16px 0 10px;padding-left:8px;border-left:3px solid var(--tco-primary,#d80505)}';
    }
}

if (!function_exists('tco_ui_table_wrap_style')) {
    /**
     * 只含宽表横滑 + 触屏块的 `<style>`（收文/文件中心等页面的既有唯一出口）。
     *
     * @param string $scope 触屏块作用域。
     * @return string
     */
    function tco_ui_table_wrap_style($scope = 'body') {
        return '<style>' . tco_ui_table_wrap_css() . tco_ui_touch_css($scope) . '</style>';
    }
}

if (!function_exists('tco_ui_touch_css')) {
    /**
     * 触屏触摸目标与按压反馈（本仓唯一出处；SPEC-10 §1.9.2 第 6 条 / §2.3 的同等口径）。
     *
     * 只在 `@media(pointer:coarse)` / 按下瞬间生效 ⇒ 桌面零视觉变更。
     *
     * @param string      $scope 选择器作用域前缀（默认 `body`；本仓页面样式本就页面作用域）。
     * @param string|null $root  承载表单控件的容器选择器；`null` ⇒ `$scope . ' .wrap'`。
     * @return string
     */
    function tco_ui_touch_css($scope = 'body', $root = null) {
        $scope = trim((string) $scope);
        if ($scope === '') {
            $scope = 'body';
        }
        $root = ($root === null) ? '' : trim((string) $root);
        $wrap = ($root === '') ? ($scope . ' .wrap') : $root;
        $hot  = $scope . ' .' . TCO_UI_TOUCH_CLASS;
        $lines = array(
            '@media(pointer:coarse){',
            $wrap . ' .button,' . $wrap . ' button,' . $wrap . ' [role=button],' . $wrap . ' input[type=submit],' . $wrap . ' input[type=button],' . $wrap . ' input[type=reset],' . $scope . ' .tco-btn,' . $scope . ' .tco-tabbar a,' . $scope . ' .tco-tabs a,' . $scope . ' .tco-sheet-item,' . $scope . ' .tco-thumb{min-height:44px}',
            $wrap . ' input[type=text],' . $wrap . ' input[type=search],' . $wrap . ' input[type=number],' . $wrap . ' input[type=date],' . $wrap . ' input[type=email],' . $wrap . ' input[type=tel],' . $wrap . ' input[type=password],' . $wrap . ' select,' . $wrap . ' textarea{min-height:44px}',
            $scope . ' .tco-reveal{display:inline-flex;align-items:center;min-height:44px;padding:0 10px}',
            $hot . '{display:inline-flex;align-items:center;min-height:44px}',
            $hot . ' input[type=checkbox],' . $hot . ' input[type=radio]{flex:0 0 auto}',
            '}',
            $scope . ' .tco-btn:active,' . $scope . ' .tco-tabbar a:active,' . $scope . ' .tco-tabs a:active,' . $scope . ' .tco-reveal:active,' . $scope . ' .tco-thumb:active,' . $wrap . ' .button:active,' . $wrap . ' button:active{-webkit-tap-highlight-color:transparent;filter:brightness(.94)}',
        );
        return implode("\n    ", $lines);
    }
}

if (!function_exists('tco_ui_state_css')) {
    /**
     * 后台三态令牌（空 / 加载 / 错误）—— 本仓唯一出处。
     *
     * @return string
     */
    function tco_ui_state_css() {
        $lines = array(
            '.' . TCO_UI_STATE_EMPTY_CLASS . '{text-align:center;color:var(--tco-text-3,#999);padding:38px 0;font-size:13px}',
            '.' . TCO_UI_STATE_LOADING_CLASS . '{text-align:center;color:var(--tco-text-2,#666);padding:38px 0;font-size:13px}',
            '.' . TCO_UI_STATE_LOADING_CLASS . '::before{content:"";display:block;width:22px;height:22px;margin:0 auto 10px;border:2px solid var(--tco-border,#e5e7eb);border-top-color:var(--tco-primary,#d80505);border-radius:50%;animation:tcoSpin .8s linear infinite}',
            '@keyframes tcoSpin{to{transform:rotate(360deg)}}',
            '@media(prefers-reduced-motion:reduce){.' . TCO_UI_STATE_LOADING_CLASS . '::before{animation:none}}',
            '.' . TCO_UI_STATE_ERROR_CLASS . '{text-align:center;color:var(--tco-error,#c62828);padding:30px 14px;font-size:13px;background:rgba(198,40,40,.06);border:1px solid rgba(198,40,40,.18);border-radius:10px}',
        );
        return implode("\n", $lines);
    }
}

if (!function_exists('tco_badge')) {
    /**
     * 状态徽章（本仓唯一出处；与主题徽章的**输出结构**等价，类名独立）。
     *
     * @param string $status on|off|wait|err|info
     * @param string $label  文案（已转义）
     * @return string
     */
    function tco_badge($status, $label) {
        $map = array('wait' => 'wait', 'on' => 'on', 'err' => 'err', 'info' => 'info', 'off' => 'off');
        $c   = isset($map[$status]) ? $map[$status] : 'off';
        return '<span class="tco-badge ' . esc_attr($c) . '">' . esc_html((string) $label) . '</span>';
    }
}

if (!function_exists('tco_ui_page_slugs')) {
    /**
     * 本仓后台页 slug 白名单（**只用于本仓资源装载与壳判定**；slug 本身保持稳定，
     * 不参与主题 RBAC 功能 ID 变更）。
     *
     * @return string[]
     */
    function tco_ui_page_slugs() {
        return array(
            'zw-doc', 'zw-doc-ledger', 'zw-doc-create', 'zw-doc-numbers', 'zw-doc-flow',
            'zw-doc-dict', 'zw-doc-assets', 'zw-doc-legal', 'zw-doc-layout', 'zw-doc-detail',
            'zw-receipt-create', 'zw-receipt-ledger', 'zw-receipt-detail', 'zw-receipt-orgs',
            'zw-receipt-issues', 'zw-receipt-number',
            'zw-org', 'zw-org-people', 'zw-org-files', 'zw-org-roles', 'zw-org-term-cycles',
            'zw-policy-platform', 'zw-policy-sources', 'zw-policy-clauses', 'zw-policy-topics',
            'zw-policy-applicability', 'zw-policy-rules', 'zw-policy-assets', 'zw-policy-issues',
            'zw-policy-supervision', 'zw-policy-consents', 'zw-policy-documents',
            'zw-policy-content-review',
            'zw-secretariat-create', 'zw-secretariat-task', 'zw-secretariat-gates', 'zw-secretariat-qa',
        );
    }
}

if (!function_exists('tco_ui_is_oa_screen')) {
    /**
     * 当前后台请求是否本仓页面。
     *
     * @return bool
     */
    function tco_ui_is_oa_screen() {
        if (!function_exists('is_admin') || !is_admin()) {
            return false;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
        if ($page === '') {
            return false;
        }
        return in_array($page, tco_ui_page_slugs(), true);
    }
}

if (!function_exists('tco_ui_enqueue_assets')) {
    /**
     * 本仓后台资源装载（**只在本仓页面**）：自有 CSS 与 JS，不依赖主题 enqueue。
     *
     * @return void
     */
    function tco_ui_enqueue_assets() {
        if (!tco_ui_is_oa_screen()) {
            return;
        }
        $base = defined('TCPC_OA_DIR') ? TCPC_OA_DIR : dirname(__DIR__, 2);
        $css  = $base . '/assets/css/oa-admin.css';
        $js   = $base . '/assets/js/oa-admin.js';
        $ver  = tco_ui_asset_ver();
        if (is_file($css)) {
            wp_enqueue_style(TCO_UI_ASSET_HANDLE, plugins_url('assets/css/oa-admin.css', $base . '/tcpc-oa.php'), array(), $ver);
        }
        if (is_file($js)) {
            wp_enqueue_script(TCO_UI_ASSET_HANDLE, plugins_url('assets/js/oa-admin.js', $base . '/tcpc-oa.php'), array(), $ver, true);
        }
    }
}

if (!function_exists('tco_ui_admin_body_class')) {
    /**
     * 本仓页面加自有 body class（全宽与壳作用域）。
     *
     * @param string $classes
     * @return string
     */
    function tco_ui_admin_body_class($classes) {
        if (tco_ui_is_oa_screen()) {
            $classes .= ' ' . TCO_UI_BODY_CLASS;
        }
        return $classes;
    }
}
