<?php
/**
 * 发文管理后台页面的**唯一** UI 样式出处 —— TASK-778 / SPEC-10 §1.9。
 *
 * 背景：发文管理 8 个页面此前各自内联 `<style>`（共 9 处），令牌互相漂移且**多数不符合 §1.9**：
 *   - 卡片圆角 6px（规范 12px）、边框 `#dcdcde`（规范 `#e5e7eb`）
 *   - 卡片用 `margin-bottom` 拉开间距（规范要求 `display:grid;gap:16px`）
 *   - 多处 `max-width:760px / 960px`（用户要求**全部 100% 宽度**）
 *   - 无卡头（`#fafbfc` 底 + 底边线）、无 sticky 保存栏、hover 阴影未按 `@media(hover:hover)` 约束
 *
 * 本模块把这些令牌收敛为**一处**，页面只调用 `zw_doc_admin_ui_style()`。
 *
 * 类族约定：
 * - `zw-adm-*` 为 §1.9 规定的 canonical 类族（新建标记优先使用）；
 * - `zw-doc-card` 等既有类保留为**兼容别名**，由同一条规则一并覆盖，避免为改样式而重写全部标记。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_ADMIN_UI_VERSION')) {
    define('ZW_DOC_ADMIN_UI_VERSION', '1');
}

// OA-2462：卡片 / 字段 / 徽章 / 分页 / 触屏令牌抽到办公协同共享出处（收文管理复用同一套）。
// 本文件保留 `zw_doc_admin_ui_style()` 作为**发文侧入口**，输出与抽取前逐字节一致。
if (!function_exists('zw_oa_admin_ui_style')) {
    $zw_doc_admin_ui_shared = dirname(__DIR__) . '/oa/admin-ui.php';
    if (is_file($zw_doc_admin_ui_shared)) {
        require_once $zw_doc_admin_ui_shared;
    }
    unset($zw_doc_admin_ui_shared);
}

if (!function_exists('zw_doc_admin_page_classes')) {
    /**
     * 发文管理各页面的 `.wrap` 类名（100% 宽度与卡片令牌按这些类生效）。
     *
     * @return string[]
     */
    function zw_doc_admin_page_classes() {
        return array(
            'zw-doc-list',
            'zw-doc-create',
            'zw-doc-detail',
            'zw-doc-composer',
            'zw-doc-numbers',
            'zw-doc-flow',
            // TASK-1396：数据字典页（与文号管理 / 公文流转配置并列）。
            'zw-doc-dict',
            'zw-doc-assets',
            'zw-doc-legal',
        );
    }
}

if (!function_exists('zw_doc_admin_ui_style')) {
    /**
     * 发文管理后台页面的内联样式（**唯一出处**，禁止再在页面里另写一份令牌）。
     *
     * 令牌严格对齐 SPEC-10 §1.9「后台管理仪表盘布局」：
     * 卡片 `#fff`/`1px #e5e7eb`/`12px`；栅格 `display:grid;gap:16px`（≥1200px 两列）；
     * 卡头 `#fafbfc` + 底边线 `#f0f1f3`；字段上下标签（label 13px `#666` + 控件 100% 宽，
     * 输入框 `1px #dcdfe6`/`8px`/焦点 `#3385ff` 光晕）；分组标题 13px/600 + 左竖线主色；
     * 页脚 `position:sticky`；hover 阴影仅 `@media(hover:hover)` 且 transition 仅 shadow，
     * 并带 `prefers-reduced-motion` 兜底。
     *
     * @return string
     */
    function zw_doc_admin_ui_style() {
        // OA-2462：令牌唯一出处 = `includes/oa/admin-ui.php`（办公协同共享；收文管理复用同一套）。
        // 本函数只负责把「发文页面类」交过去 —— 输出与抽取前**逐字节一致**，故主题侧
        // `tests/test-doc-admin-ui.php` 的全部字符串断言仍按原样命中。
        if (function_exists('zw_oa_admin_ui_style')) {
            return zw_oa_admin_ui_style(zw_doc_admin_page_classes());
        }
        // 极端降级：共享令牌文件缺失时**不输出半套样式**（页面仍可用，只是没有卡片令牌），
        // 绝不在此再写一份 CSS（那会成为第二套出处）。
        return '';
    }
}

if (!function_exists('zw_doc_admin_page_head')) {
    /**
     * 页面头（§1.9 布局规则 1：标题 20px/600 + 一句话副说明 13px `#666`）。
     *
     * @param string $title 标题。
     * @param string $sub   副说明（写清「启用后行为」与「未启用时回退」）。
     * @return string
     */
    function zw_doc_admin_page_head($title, $sub = '') {
        $html = '<h1>' . esc_html((string) $title) . '</h1>';
        if ($sub !== '') {
            $html .= '<p class="zw-doc-sub">' . esc_html((string) $sub) . '</p>';
        }

        return $html;
    }
}

if (!function_exists('zw_doc_admin_card_head')) {
    /**
     * 卡头（§1.9：图标圆片 40px + 名称 15px/600 + 状态徽章右浮）。
     *
     * @param string $name  卡片名。
     * @param string $badge 徽章文案（空则不显示）。
     * @param bool   $badge_on 徽章是否「已启用」态。
     * @param string $icon  ceofont 类名（须先 grep 验证存在）。
     * @return string
     */
    function zw_doc_admin_card_head($name, $badge = '', $badge_on = true, $icon = '') {
        $html = '<div class="zw-adm-card-head">';
        if ($icon !== '') {
            $html .= '<span class="zw-adm-ico ' . esc_attr($icon) . '" aria-hidden="true"></span>';
        }
        $html .= '<span>' . esc_html((string) $name) . '</span>';
        if ($badge !== '') {
            $html .= '<span class="zw-adm-badge' . ($badge_on ? ' is-on' : '') . '">' . esc_html((string) $badge) . '</span>';
        }
        $html .= '</div>';

        return $html;
    }
}
