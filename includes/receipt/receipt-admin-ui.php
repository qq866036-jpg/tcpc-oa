<?php
/**
 * 收文管理 · 后台 UI 片段（TASK-1297 · R5）。
 *
 * 口径出处：
 * - `docs/architecture/TASK-1297-收文管理-表结构变更与迁移回滚方案-20261008.md`
 *   §补充 A.1（slug `zw-receipt-issues`、函数前缀一律 `zw_receipt_*`）；
 * - `docs/research/TASK-1297-收文管理-需求对齐稿-20261008.md` §3 US-04/US-05/US-08。
 *
 * 边界：
 * - 本文件**只做展示**（转义、徽章、通知、下拉、页面壳），**零写库、零副作用**（除 echo）；
 * - 部门下拉的选项来自组织架构字典 canonical（`zw_org_departments()`），**只读**；
 * - 不新增全局 CSS 文件、不引入图标字体依赖；页面专属样式一律内联；
 * - 颜色只用中性灰与主色 `var(--primary-color,#d80505)`（hover `#b30404`），不用橙/遗留蓝。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

// 依赖（standalone / CLI 加载时自动补齐；bootstrap 的开启分支先加载本文件之前的模块）。
if (!function_exists('zw_receipt_is_enabled')) {
    require_once __DIR__ . '/receipt-helpers.php';
}
if (!function_exists('zw_receipt_org_options')) {
    require_once __DIR__ . '/receipt-orgs.php';
}

if (!function_exists('zw_receipt_admin_ui_esc')) {
    /**
     * HTML 转义（`esc_html()` 口径；无 WP 时退化）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_admin_ui_esc($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        if (function_exists('esc_html')) {
            return (string) esc_html((string) $value);
        }
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('zw_receipt_admin_ui_attr')) {
    /**
     * HTML 属性转义（`esc_attr()` 口径；无 WP 时退化）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_admin_ui_attr($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        if (function_exists('esc_attr')) {
            return (string) esc_attr((string) $value);
        }
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('zw_receipt_admin_ui_url')) {
    /**
     * URL 转义（`esc_url()` 口径；无 WP 时退化）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_admin_ui_url($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        if (function_exists('esc_url')) {
            return (string) esc_url((string) $value);
        }
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('zw_receipt_admin_ui_status_label')) {
    /**
     * 办理状态展示名（`逾期` 为**派生**状态，由调用方按 due_date 判定后传入）。
     *
     * @param mixed $status  库内状态。
     * @param bool  $overdue 是否派生为逾期。
     * @return string
     */
    function zw_receipt_admin_ui_status_label($status, $overdue = false) {
        if ($overdue) {
            return '逾期';
        }
        $status = trim((string) $status);
        return $status !== '' ? $status : '未开始';
    }
}

if (!function_exists('zw_receipt_admin_ui_status_badge')) {
    /**
     * 办理状态徽章（内联样式；只用中性灰 + 主色）。
     *
     * @param mixed $status  库内状态。
     * @param bool  $overdue 是否派生为逾期。
     * @return string HTML。
     */
    function zw_receipt_admin_ui_status_badge($status, $overdue = false) {
        $label = zw_receipt_admin_ui_status_label($status, $overdue);
        $style = 'display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;'
            . 'border:1px solid #c3c4c7;background:#fff;color:#50575e';
        if ($overdue) {
            $style = 'display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;'
                . 'border:1px solid var(--primary-color,#d80505);background:#fff;color:#b30404;font-weight:600';
        } elseif ($label === '办理中') {
            $style = 'display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;'
                . 'border:1px solid #c3c4c7;border-left:3px solid var(--primary-color,#d80505);background:#fff;color:#1d2327';
        } elseif ($label === '已办结' || $label === '归档') {
            $style = 'display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;'
                . 'border:1px solid #c3c4c7;background:#f6f7f7;color:#1d2327';
        }
        return '<span style="' . zw_receipt_admin_ui_attr($style) . '">' . zw_receipt_admin_ui_esc($label) . '</span>';
    }
}

if (!function_exists('zw_receipt_admin_ui_notice')) {
    /**
     * 办理视图提示语（`zw_msg` 白名单映射；未识别返回空串）。
     *
     * @param mixed $code 提示码。
     * @return string
     */
    function zw_receipt_admin_ui_notice($code) {
        $messages = array(
            'issue_created'   => '派单成功：已生成收文办理单。',
            'issue_updated'   => '办理状态 / 证据已更新（仅写办理台账，未触碰政策源正文与生效版本）。',
            'issue_duplicate' => '该办理单已存在（幂等返回原记录，未新增行）。',
        );
        $code = trim((string) $code);
        return isset($messages[$code]) ? $messages[$code] : '';
    }
}

if (!function_exists('zw_receipt_admin_ui_notice_html')) {
    /**
     * 提示条 HTML（无提示时返回空串）。
     *
     * @param mixed $code 提示码。
     * @return string
     */
    function zw_receipt_admin_ui_notice_html($code) {
        $notice = zw_receipt_admin_ui_notice($code);
        if ($notice === '') {
            return '';
        }
        return '<div class="notice notice-success is-dismissible"><p>' . zw_receipt_admin_ui_esc($notice) . '</p></div>';
    }
}

if (!function_exists('zw_receipt_admin_ui_table_wrap_style')) {
    /**
     * 收文管理域的 canonical 宽表横滑规则输出（TASK-1633 / TASK-1579 §7 P1-6 第四批）。
     *
     * **本函数不是第二套声明**：唯一出处仍是 canonical `inc/admin/admin-ui-tokens.php` 的
     * `zw_adm_ui_table_wrap_css()`（本函数只做 `<style>` 包裹，逐字透传），因此本文件不含该
     * canonical 类的**字面声明体**（写「声明体」三个字即可，不写字面量，避免与
     * `tests/test-admin-h5-gate.php` 段 W/X 的「全仓唯一声明」判据冲突）。收文管理域 slug 前缀
     * `zw-receipt` **不命中**会员域外壳判定 `zw_mc_adm_is_screen()`（只看 `zw-mc` / `zw-member`
     * / `zw-policy` / `zw-notify`）⇒ 本域页面必须**自带**该规则的输出点
     * （先例 TASK-1627 / TASK-1632 的活动域 / 组织架构域）。
     *
     * @return string `<style>…</style>`；canonical 未加载时返回空串（不猜测、不回落自造规则）。
     */
    function zw_receipt_admin_ui_table_wrap_style() {
        if (!function_exists('zw_adm_ui_table_wrap_css')) {
            return '';
        }
        // TASK-1637（P1-4 余项 / 用户拍板②）：收文域同样不继承会员域外壳的 `.zw-adm-tcpc`
        // ⇒ 同批透传 canonical 触屏块（`zw_adm_ui_touch_css('body')`；仍只做 `<style>` 包裹、
        // **零自造声明**；规则仅 `@media(pointer:coarse)` 命中 ⇒ 桌面零视觉变更）。
        $touch = function_exists('zw_adm_ui_touch_css') ? zw_adm_ui_touch_css('body') : '';
        return '<style>' . zw_adm_ui_table_wrap_css() . $touch . '</style>';
    }
}

if (!function_exists('zw_receipt_admin_ui_page_open')) {
    /**
     * 收文管理页面壳（`.wrap` + 标题 + 说明 + 同域导航）。
     *
     * @param string $title    标题。
     * @param string $subtitle 说明（允许安全 HTML 片段，调用方负责转义）。
     * @param string $current  当前页面 slug（用于高亮，不改变渲染结构）。
     * @return void
     */
    function zw_receipt_admin_ui_page_open($title, $subtitle = '', $current = '') {
        echo '<div class="wrap">';
        echo zw_receipt_admin_ui_table_wrap_style();
        echo '<h1>' . zw_receipt_admin_ui_esc($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<p style="color:#646970">' . $subtitle . '</p>';
        }
        echo zw_receipt_admin_ui_nav($current);
    }
}

if (!function_exists('zw_receipt_admin_ui_page_close')) {
    /**
     * 关闭页面壳。
     *
     * @return void
     */
    function zw_receipt_admin_ui_page_close() {
        echo '</div>';
    }
}

if (!function_exists('zw_receipt_admin_ui_pages')) {
    /**
     * 同域页面清单（slug → 显示名 → 回调），仅返回**已落盘**的页面。
     *
     * @return array[]
     */
    function zw_receipt_admin_ui_pages() {
        $candidates = array(
            array('slug' => 'zw-receipt-create', 'label' => '收文登记', 'callback' => 'zw_receipt_page_create'),
            array('slug' => 'zw-receipt-ledger', 'label' => '收文台账', 'callback' => 'zw_receipt_page_ledger'),
            array('slug' => 'zw-receipt-orgs', 'label' => '部委与年份目录', 'callback' => 'zw_receipt_page_orgs'),
            array('slug' => 'zw-receipt-issues', 'label' => '收文办理', 'callback' => 'zw_receipt_page_issues'),
        );
        $pages = array();
        foreach ($candidates as $page) {
            if (function_exists($page['callback'])) {
                $pages[] = $page;
            }
        }
        return $pages;
    }
}

if (!function_exists('zw_receipt_admin_ui_nav')) {
    /**
     * 同域导航（纯链接，不产生副作用；无 WP URL 函数时退化为相对 URL）。
     *
     * @param string $current 当前 slug。
     * @return string HTML。
     */
    function zw_receipt_admin_ui_nav($current = '') {
        $pages = zw_receipt_admin_ui_pages();
        if (count($pages) < 2) {
            return '';
        }
        $base   = function_exists('admin_url') ? (string) admin_url('admin.php') : 'admin.php';
        $items  = array();
        foreach ($pages as $page) {
            $url = function_exists('add_query_arg')
                ? (string) add_query_arg(array('page' => $page['slug']), $base)
                : $base . '?page=' . rawurlencode($page['slug']);
            $label = zw_receipt_admin_ui_esc($page['label']);
            if ((string) $current === (string) $page['slug']) {
                $items[] = '<strong>' . $label . '</strong>';
            } else {
                $items[] = '<a href="' . zw_receipt_admin_ui_url($url) . '">' . $label . '</a>';
            }
        }
        return '<p style="margin:4px 0 12px">' . implode(' · ', $items) . '</p>';
    }
}

if (!function_exists('zw_receipt_admin_ui_dept_rows')) {
    /**
     * 组织架构部门字典行（只读；字典不可用时返回空数组，不臆造）。
     *
     * @return array[]
     */
    function zw_receipt_admin_ui_dept_rows() {
        if (!function_exists('zw_org_departments')) {
            return array();
        }
        $rows = zw_org_departments();
        if (!is_array($rows)) {
            return array();
        }
        $out = array();
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : array();
            $key = isset($row['key']) ? (string) $row['key'] : '';
            if ($key === '') {
                continue;
            }
            $out[] = array(
                'key'  => $key,
                'name' => isset($row['name']) ? (string) $row['name'] : $key,
            );
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_admin_ui_dept_label')) {
    /**
     * 部门 key → 显示名（**唯一出处** `zw_org_department_label()`；不可用时退回 key，绝不臆造）。
     *
     * @param mixed $key 部门 key。
     * @return string
     */
    function zw_receipt_admin_ui_dept_label($key) {
        $key = trim((string) $key);
        if ($key === '') {
            return '';
        }
        if (function_exists('zw_org_department_label')) {
            return (string) zw_org_department_label($key);
        }
        return $key;
    }
}

if (!function_exists('zw_receipt_admin_ui_dept_select')) {
    /**
     * 承办部门下拉（存 key；**允许留空**——US-04「交办时再补」）。
     *
     * @param string $name    字段名。
     * @param string $selected 已选 key。
     * @param bool   $allow_empty 是否提供空选项。
     * @return string HTML。
     */
    function zw_receipt_admin_ui_dept_select($name, $selected = '', $allow_empty = true) {
        $name     = (string) $name;
        $selected = trim((string) $selected);
        $html     = '<select name="' . zw_receipt_admin_ui_attr($name) . '">';
        if ($allow_empty) {
            $html .= '<option value="">（空：交办时再补）</option>';
        }
        $known = false;
        foreach (zw_receipt_admin_ui_dept_rows() as $row) {
            $is_selected = ($selected !== '' && $selected === $row['key']);
            if ($is_selected) {
                $known = true;
            }
            $html .= '<option value="' . zw_receipt_admin_ui_attr($row['key']) . '"' . ($is_selected ? ' selected' : '') . '>'
                . zw_receipt_admin_ui_esc($row['name']) . '</option>';
        }
        if ($selected !== '' && !$known) {
            // 字典里没有的历史 key：原样保留为可选项（不臆造显示名，也不静默丢弃）。
            $html .= '<option value="' . zw_receipt_admin_ui_attr($selected) . '" selected>'
                . zw_receipt_admin_ui_esc(zw_receipt_admin_ui_dept_label($selected)) . '（字典未登记）</option>';
        }
        $html .= '</select>';
        return $html;
    }
}

if (!function_exists('zw_receipt_admin_ui_status_select')) {
    /**
     * 办理状态下拉（**不含**派生状态「逾期」——US-05 禁止手工置为逾期）。
     *
     * @param string $name     字段名。
     * @param string $selected 已选状态。
     * @param bool   $allow_keep 是否提供「不修改」空选项。
     * @return string HTML。
     */
    function zw_receipt_admin_ui_status_select($name, $selected = '', $allow_keep = false) {
        $name     = (string) $name;
        $selected = trim((string) $selected);
        $options  = function_exists('zw_receipt_issue_status_options')
            ? zw_receipt_issue_status_options()
            : array('未开始' => '未开始', '办理中' => '办理中', '已办结' => '已办结', '归档' => '归档');

        $html = '<select name="' . zw_receipt_admin_ui_attr($name) . '">';
        if ($allow_keep) {
            $html .= '<option value="">（不修改）</option>';
        }
        foreach ($options as $value => $label) {
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $html .= '<option value="' . zw_receipt_admin_ui_attr($value) . '"'
                . (($selected === $value) ? ' selected' : '') . '>' . zw_receipt_admin_ui_esc($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }
}

if (!function_exists('zw_receipt_admin_ui_field')) {
    /**
     * 单行文本字段（标签 + 输入框 + 可选必填）。
     *
     * @param string $label      标签。
     * @param string $name       字段名。
     * @param string $value      当前值。
     * @param bool   $required   是否必填。
     * @param string $placeholder 占位提示。
     * @return string HTML。
     */
    function zw_receipt_admin_ui_field($label, $name, $value = '', $required = false, $placeholder = '') {
        $html = '<p><label>' . zw_receipt_admin_ui_esc($label) . ($required ? ' *' : '') . '<br>';
        $html .= '<input type="text" class="regular-text" name="' . zw_receipt_admin_ui_attr($name) . '" value="'
            . zw_receipt_admin_ui_attr((string) $value) . '"'
            . ($placeholder !== '' ? ' placeholder="' . zw_receipt_admin_ui_attr($placeholder) . '"' : '')
            . ($required ? ' required' : '') . '></label></p>';
        return $html;
    }
}

if (!function_exists('zw_receipt_admin_ui_textarea')) {
    /**
     * 多行文本字段（证据 / 复核结论留痕）。
     *
     * @param string $label 标签。
     * @param string $name  字段名。
     * @param string $value 当前值。
     * @param int    $rows  行数。
     * @return string HTML。
     */
    function zw_receipt_admin_ui_textarea($label, $name, $value = '', $rows = 3) {
        $html = '<p><label>' . zw_receipt_admin_ui_esc($label) . '<br>';
        $html .= '<textarea class="large-text" name="' . zw_receipt_admin_ui_attr($name) . '" rows="' . (int) max(1, (int) $rows) . '">'
            . zw_receipt_admin_ui_esc((string) $value) . '</textarea></label></p>';
        return $html;
    }
}
