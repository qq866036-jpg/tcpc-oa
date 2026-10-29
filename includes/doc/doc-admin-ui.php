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
        $selectors = array();
        foreach (zw_doc_admin_page_classes() as $class) {
            $selectors[] = '.' . $class;
        }
        $page = implode(',', $selectors);
        // 注意：CSS 选择器列表里的 `*` 只跟随**最后一个**选择器（`, ` 分隔的是完整选择器）。
        // 故后代选择器必须逐个页面类展开，否则 box-sizing 只会作用于最后一个页面，
        // 导致其余页面「卡片 width:100% + padding」横向溢出（TASK-778 目视核对实测溢出 38px）。
        $descendants = array();
        foreach (zw_doc_admin_page_classes() as $class) {
            $descendants[] = '.' . $class . ' *';
        }
        $page_all = implode(',', $descendants);

        $css  = '<style>';
        // —— 100% 宽度：显式声明并清除历史 max-width（用户要求：发文管理所有页面 100% 宽度）——
        $css .= $page . '{max-width:none;width:100%;box-sizing:border-box}';
        $css .= $page_all . '{box-sizing:border-box}';
        // —— 卡片栅格（§1.9：display:grid;gap:16px；≥1200px 两列）——
        $css .= '.zw-adm-grid{display:grid;gap:16px;grid-template-columns:minmax(0,1fr);align-items:start}';
        $css .= '@media(min-width:1200px){.zw-adm-grid.is-2{grid-template-columns:repeat(2,minmax(0,1fr))}}';
        // —— 卡片（§1.9 令牌；zw-doc-card 为兼容别名）——
        $css .= '.zw-adm-card,.zw-doc-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 18px;max-width:none;margin:0;width:100%;transition:box-shadow .2s ease}';
        // —— 卡头（§1.9：约 68px、#fafbfc 底 + 底边线 #f0f1f3）。为免重写全部标记，直接作用于卡内首个 h2 ——
        $css .= '.zw-adm-card-head{min-height:68px;display:flex;align-items:center;gap:12px;background:#fafbfc;border-bottom:1px solid #f0f1f3;margin:-16px -18px 16px;padding:14px 18px;border-radius:12px 12px 0 0}';
        $css .= '.zw-adm-card>h2:first-child,.zw-doc-card>h2:first-child{min-height:68px;display:flex;align-items:center;gap:10px;background:#fafbfc;border-bottom:1px solid #f0f1f3;margin:-16px -18px 16px;padding:14px 18px;border-radius:12px 12px 0 0;font-size:15px;font-weight:600;line-height:1.4}';
        $css .= '.zw-adm-ico{width:40px;height:40px;border-radius:10px;background:var(--primary-color,#d80505);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:20px;flex:0 0 auto}';
        // —— 状态徽章（§1.9）——
        $css .= '.zw-adm-badge{margin-left:auto;border-radius:999px;padding:2px 10px;font-size:12px;line-height:1.8;white-space:nowrap;background:#f4f4f4;color:#999}';
        $css .= '.zw-adm-badge.is-on{background:rgba(76,175,80,.1);color:#2e7d32}';
        $css .= '.zw-doc-badge{display:inline-block;border-radius:999px;padding:1px 9px;font-size:12px;background:#f4f4f4;color:#666}';
        $css .= '.zw-doc-badge.is-warn{background:#fef3e2;color:#b26a00}';
        // —— 字段：上下标签，控件 100% 宽（§1.9；禁左标签旧式排版）——
        $css .= '.zw-adm-field,.zw-doc-field{margin:0 0 14px}';
        $css .= '.zw-adm-field>label,.zw-doc-field>label{display:block;font-size:13px;color:#666;margin-bottom:6px;font-weight:400}';
        $css .= '.zw-adm-field input[type=text],.zw-adm-field input[type=date],.zw-adm-field input[type=number],.zw-adm-field input[type=password],.zw-adm-field input[type=file],.zw-adm-field select,.zw-adm-field textarea,'
              . '.zw-doc-field input[type=text],.zw-doc-field input[type=date],.zw-doc-field input[type=number],.zw-doc-field select,.zw-doc-field textarea,'
              . '.zw-doc-card input[type=text],.zw-doc-card textarea,.zw-doc-card select{width:100%;max-width:100%;border:1px solid #dcdfe6;border-radius:8px;padding:7px 10px;box-shadow:none}';
        $css .= '.zw-doc-card input[type=text]:focus,.zw-doc-card textarea:focus,.zw-doc-card select:focus{border-color:#3385ff;box-shadow:0 0 0 3px rgba(51,133,255,.15);outline:0}';
        $css .= '.zw-adm-field .description,.zw-doc-field .description,.zw-doc-muted{color:#8c8f94;font-size:12px}';
        // —— 分组标题（§1.9：13px/600 + 左竖线主色）——
        $css .= '.zw-adm-sec{font-size:13px;font-weight:600;border-left:3px solid var(--primary-color,#d80505);padding-left:8px;margin:18px 0 10px}';
        // —— 页脚保存栏（§1.9：sticky bottom + 右对齐主按钮）——
        $css .= '.zw-adm-foot{position:sticky;bottom:0;z-index:5;display:flex;justify-content:flex-end;gap:8px;align-items:center;background:#fff;border-top:1px solid #f0f1f3;border-radius:12px;padding:12px 18px;margin-top:16px}';
        // TASK-1318 **既有构造 bug 修正**：`$page` 是**逗号选择器列表**，`$page . ' X'` 只会让 `X`
        // 跟随**最后一项**，其余项退化为**裸选择器**并把 `X` 的声明**直接作用到页面外层容器**上。
        // 这与本函数 `:67-69` 已记录的坑**同源**（当时只为 `*` 修了 `$page_all`，此处 4 处遗漏）。
        // 后果（实测）：`.zw-doc-composer` 等页面容器被加上 `overflow-x:auto` ⇒ **裁剪**容器内
        // canonical 搜索下拉控件（`inc/controls/search-select.php`）的绝对定位下拉面板
        // （用户报「下拉被下一行挡住」）。—— 此处**不写控件函数名**，避免被控件登记簿的
        // `scan_patterns` 误判为本文件是该控件的调用点。
        // 同时「表格包裹层可横向滚动」这条**只在最后一个页面生效**，其余 7 个页面一直没生效。
        // 修法：与 `$page_all` 同口径——**逐页面类展开后缀**，再拼成完整选择器列表。
        $expand = function ($suffix) {
            $out = array();
            foreach (zw_doc_admin_page_classes() as $class) {
                $out[] = '.' . $class . $suffix;
            }
            return implode(',', $out);
        };
        // —— 页头（§1.9：标题 20px/600 + 副说明 13px/#666）——
        $css .= $expand('>h1') . '{font-size:20px;font-weight:600;margin:0 0 6px}';
        $css .= $expand('>h1+p') . ',.zw-doc-sub{font-size:13px;color:#666;margin:0 0 14px}';
        // —— hover 阴影（§1.9：仅 hover 设备 + reduced-motion 兜底）——
        $css .= '@media(hover:hover){.zw-adm-card:hover,.zw-doc-card:hover{box-shadow:0 8px 24px rgba(17,24,39,.08)}}';
        $css .= '@media(prefers-reduced-motion:reduce){.zw-adm-card,.zw-doc-card{transition:none}}';
        // —— 表格在 100% 宽度下不溢出 ——
        // 预览：外层盒子即视觉尺寸，内层按 scale 缩小（TASK-780：transform 不占布局，必须显式收外框）
        $css .= '.zw-doc-preview-box{overflow:hidden;background:#fff;border:1px solid #e5e7eb;border-radius:6px}';
        $css .= '.zw-doc-preview-box>.zw-doc-scaled{display:block}';
        $css .= $expand(' table.widefat') . '{width:100%}';
        // 表格包裹层才是「可横向滚动」的载体（**不得**落在页面容器上，否则裁剪下拉面板）。
        // TASK-1582：类名与声明体改为引用 canonical（inc/admin/admin-ui-tokens.php）；声明值逐字节不变。
        $css .= $expand(' .' . ZW_ADM_UI_TABLE_WRAP_CLASS) . zw_adm_ui_table_wrap_body();
        // —— TASK-1370：分页页码**横排**（用户报「我的发文各标签页下方分页页码是竖排」）——
        // 病根：`paginate_links(array('type' => 'list'))` 输出 `<ul class="page-numbers"><li>…</li></ul>`，
        // 而 **wp-admin 的 common.css 完全没有 `page-numbers` 规则**（本机实测 `grep -c page-numbers wp-admin/css/common.css` = **0**）
        // ⇒ `<li>` 退回默认 `display:list-item`（块级）⇒ 每个页码各占一行（竖排）。
        // 修法：在本后台「样式唯一出处」里把 `ul.page-numbers` 显式改为**横向 flex 换行**容器，并清掉列表符号与默认外边距。
        $css .= '.tablenav .tablenav-pages{margin:0}';
        $css .= '.tablenav-pages ul.page-numbers{display:flex;flex-wrap:wrap;align-items:center;gap:4px;margin:0;padding:0;list-style:none}';
        $css .= '.tablenav-pages ul.page-numbers>li{margin:0;list-style:none}';
        $css .= '.tablenav-pages ul.page-numbers .page-numbers{display:inline-block;min-width:28px;text-align:center}';
        // TASK-1637（TASK-1579 §7 P1-4 余项 / 用户拍板②「后台页同样强制 ≥44px」）：触屏触摸目标
        // 与 `:active` 按压反馈的**唯一出处**＝canonical `zw_adm_ui_touch_css()`。发文域**不继承**
        // 会员域外壳的 `.zw-adm-tcpc` body class ⇒ 传 `body` 作用域（本样式表本就是**页面作用域**内联
        // 输出，选择器不外溢）；规则只在 `@media(pointer:coarse)` / 按下瞬间生效 ⇒ **桌面零视觉变更**。
        $css .= function_exists('zw_adm_ui_touch_css') ? zw_adm_ui_touch_css('body') : '';
        $css .= '</style>';

        return $css;
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
