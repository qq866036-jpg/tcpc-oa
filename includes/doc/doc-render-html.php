<?php
/**
 * 公文渲染通道 A：预览与打印 HTML —— TASK-767 / SPEC-87。
 *
 * 设计要点：
 * - **版式参数全部取自 `zw_doc_gb9704_*`**（SPEC-87 唯一出处），本文件禁止硬编码 mm 与 pt；
 * - **纯渲染核心与 WP 依赖外壳分离**：`zw_doc_render_*()` 系列只接受数组参数（可单测），
 *   `zw_doc_build_render_args()` / `zw_doc_handle_print()` 才触碰 WordPress；
 * - **样式内联**在页面内（禁止新增全局 CSS 文件）；
 * - 所有用户内容经 `esc_html` 输出，渲染层不做任何标签解释（模型层已剥标签，这里是第二道闸）；
 * - 分页为**排版建议**：按「每面 22 行、每行 28 字」估算分页并逐页输出页码；
 *   最终分页仍由浏览器/PDF 引擎决定，页面会显式声明这一点（不假装精确）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_render_defaults')) {
    /**
     * 渲染参数默认值（纯数组，无 WP 依赖）。
     *
     * @return array
     */
    function zw_doc_render_defaults() {
        return array(
            'base_url'      => '',   // 主题静态资源前缀
            'doc_number'    => '',   // 发文字号（来自发文记录 `_zw_doc_number`）
            'letterhead_url' => '',  // 红头图片 URL；空则回退常量绘制
            'letterhead_alt' => '',
            // TASK-1422：红头生成方式（唯一出处 `zw_doc_letterhead_mode()`）经
            // `zw_doc_letterhead_render_args()` 派生出的两个键 ——
            //   `letterhead_path`：已解析红头图片的**本地路径**（加密 PDF 工人据此转 data-URI；auto 模式恒空）；
            //   `letterhead_blocked`：非空 = **fail-closed** 提示文案（切到图片模式但无可用已启用红头），
            //     渲染层据此输出明确提示，**不静默回落常量绘制、不渲染空白红头**。
            'letterhead_path'    => '',
            'letterhead_blocked' => '',
            'letterhead_assets_url' => '',
            'number_overlay' => null, // array{x:float,y:float,size:float} 百分比坐标；null 表示图片下方排文号
            'seal_url'      => '',   // 印章图片 URL；空则不渲染
            'seal_dpi'      => 0,    // 印章有效 dpi（<300 时标注低清）
            // TASK-1985：印章真实直径（mm）。**唯一事实来源** = 登记表逐章登记值
            // （`zw_doc_seal_diameter_mm()`）；0 = 回退版式常量 `seal.diameter_mm`。
            // 版面按 CSS `width:Nmm` 落纸 ⇒ 与版心同一 mm 坐标系、1:1 物理尺寸。
            'seal_diameter_mm' => 0.0,
            'show_toolbar'  => false,
            'auto_print'    => false,
            'document_title' => '公文打印预览',
            // TASK-1338 批次二 · D1 版记合并：公文要素的真值源（发文部门 / 发文日期）。
            // 正文 meta 未显式保存印发值时由 `zw_doc_record_print_fields()` 回落到这两个值。
            'doc_department' => '',
            'doc_issue_date' => '',
        );
    }
}

if (!function_exists('zw_doc_render_img_src')) {
    /**
     * `<img src>` 的**唯一出口**（TASK-1985 根因修复）。
     *
     * 为什么不能直接用 `esc_url()`：`esc_url()` / `esc_url_raw()` 依据 `wp_allowed_protocols()` 白名单，
     * **`data` 不在其中** ⇒ 传 `data:image/png;base64,…` 会返回**空串**（实测生产 WP：两者都返回 `[]`）。
     * 而印章 / 红头图片素材在 **webroot 之外**、渲染工人与打印新窗口都**没有会话 cookie**
     * （SPEC-87 §17 / §11），只能内联为 data-URI ⇒ 一旦过 `esc_url()` 就被清空成 `src=""`，
     * 版面出现「印章位置没有印章图片」的破图（本任务用户报障，已由产物 PDF 复现）。
     *
     * 安全边界：只放行**受控的位图 data-URI**（`data:image/{png,jpeg,webp,gif};base64,` + 合法 base64 字符集），
     * 其余一律仍走 `esc_url()` —— **不放开** `javascript:` / `vbscript:` / `data:text/html` 等危险协议。
     *
     * @param string $src 图片地址（data-URI 或普通 URL）。
     * @return string 已转义、可直接放进 `src="…"` 的字符串（不可用时为空串）。
     */
    function zw_doc_render_img_src($src) {
        $src = is_string($src) ? trim($src) : '';
        if ($src === '') {
            return '';
        }
        if (preg_match('#^data:image/(?:png|jpe?g|webp|gif);base64,[A-Za-z0-9+/=]+$#i', $src) === 1) {
            // base64 字符集不含引号/尖括号/&，`esc_attr` 只做一次无副作用的兜底转义。
            return esc_attr($src);
        }
        // **自行**判协议（不把安全寄托在调用方的 `esc_url` 实现上）：只放行无协议（相对路径 / 协议相对）
        // 与 http(s)；`javascript:` / `vbscript:` / `data:text/html` 等一律 fail-closed 返回空串。
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $src, $m) === 1) {
            $scheme = strtolower($m[1]);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return '';
            }
        }

        return esc_url($src);
    }
}

if (!function_exists('zw_doc_render_style_block')) {
    /**
     * 内联样式块（版式参数全部来自 SPEC-87 常量）。
     *
     * 公文红使用版式常量中的 `header.rule_color`（与站点主色 `--primary-color,#d80505` 同值）；
     * 打印页是独立页面，主题 CSS 变量不可用，故此处必须取常量字面值。
     *
     * @param string $base_url 主题静态资源前缀（**必须传入**，否则托管字体 URL 无法解析）。
     * @return string
     */
    function zw_doc_render_style_block($base_url = '') {
        $cfg    = function_exists('zw_doc_gb9704_spec') ? zw_doc_gb9704_spec() : array();
        $paper  = isset($cfg['paper']) ? $cfg['paper'] : array('w_mm' => 210, 'h_mm' => 297);
        $margin = isset($cfg['margin']) ? $cfg['margin'] : array('top_mm' => 37, 'bottom_mm' => 35, 'left_mm' => 28, 'right_mm' => 26);
        $text   = isset($cfg['text']) ? $cfg['text'] : array('pt' => 16, 'line_pt' => 28);
        $title  = isset($cfg['title']) ? $cfg['title'] : array('pt' => 22, 'line_pt' => 35, 'gap_below_rule_lines' => 2);
        $header = isset($cfg['header']) ? $cfg['header'] : array('rule_gap_mm' => 4, 'rule_h_mm' => 0.35, 'rule_color' => 'D80505', 'org_color' => 'D80505');
        $record = isset($cfg['record']) ? $cfg['record'] : array('pt' => 14, 'separator_thick_mm' => 0.35, 'separator_thin_mm' => 0.25);
        // TASK-1338 批次二：版记三条分隔线、抄送句号、左右空一字、版记撑底与页码「空一字」
        // 一律经版式常量唯一出处取值；DOCX 通道取**同一批键**（只是换算单位不同）。
        $record_rules    = function_exists('zw_doc_gb9704_record_rules')
            ? zw_doc_gb9704_record_rules()
            : array('thick_mm' => 0.35, 'thin_mm' => 0.25);
        $record_side     = function_exists('zw_doc_gb9704_record_side_chars') ? (int) zw_doc_gb9704_record_side_chars() : 1;
        $footer_side     = function_exists('zw_doc_gb9704_footer_indent_chars') ? (int) zw_doc_gb9704_footer_indent_chars() : 1;
        $record_anchor   = (bool) zw_doc_gb9704_get('record.anchor_text_bottom', true);
        $footer = isset($cfg['footer']) ? $cfg['footer'] : array('pt' => 14, 'baseline_below_text_mm' => 7);

        $box   = function_exists('zw_doc_gb9704_text_box_mm') ? zw_doc_gb9704_text_box_mm() : array('w_mm' => 156, 'h_mm' => 225);
        // TASK-2052（用户「红色分隔线 的颜色 也放到设置中」）：颜色必须**读覆盖后的常量**。
        // 旧实现从 `$cfg['header']`（调用方传入的 spec 快照）取色 ⇒ 设计器里改了 `header.rule_color` 也不生效
        //（实测：`zw_doc_gb9704_get()` 已返回新色，但 CSS 仍是旧色）。改为以 `zw_doc_gb9704_get()` 为准。
        $red   = '#' . ltrim((string) zw_doc_gb9704_get('header.rule_color', isset($header['rule_color']) ? $header['rule_color'] : 'D80505'), '#');
        // TASK-1331 修正（用户报「预览中 公文标题与双横线距离太远了」）：
        // GB/T 9704-2012 §7.3.1「标题…编排于红色分隔线下**空二行**位置」——「空二行」是**正文行**（3 号字行），
        // **不是**标题自身行高。原实现取 `title.line_pt × gap_below_rule_lines`（35pt × 2 = 70pt ≈ 24.7mm），
        // 比国标口径偏大约 4.9mm。**DOCX 通道本就是对的**（插 2 个正文行空段：`blank_twips = text.line_pt`），
        // 仅 HTML 通道偏大 ⇒ 两通道不一致。此处改以**正文行高**为基准（2 × 28pt = 56pt ≈ 19.8mm），与 DOCX 同口径。
        $body_line_pt = isset($cfg['text']['line_pt']) ? (float) $cfg['text']['line_pt'] : 28.0;
        $gap_lines    = isset($title['gap_below_rule_lines']) ? max(0, (int) $title['gap_below_rule_lines']) : 2;
        $title_gap_pt = $body_line_pt * $gap_lines;

        // TASK-1333 批次一（主体要素纸面顺序与落款）：主送机关上间距、附件说明上间距、落款上间距
        // 一律经版式常量唯一出处取值；DOCX 通道消费**同一参数**（只是换算单位不同），禁止两通道各写一套。
        $receiver_gap_pt = function_exists('zw_doc_gb9704_gap_pt') ? (float) zw_doc_gb9704_gap_pt('main.receiver_gap_lines', 1) : $body_line_pt;
        $attach_gap_pt   = function_exists('zw_doc_gb9704_gap_pt') ? (float) zw_doc_gb9704_gap_pt('main.attach_gap_lines', 1) : $body_line_pt;
        $sign_gap_pt     = function_exists('zw_doc_gb9704_gap_pt') ? (float) zw_doc_gb9704_gap_pt('sign.gap_lines_before_org', 1) : $body_line_pt;
        // 落款右空字距：署名与成文日期共用同一对参数；有无印章各取对应口径。
        $sign_indents        = function_exists('zw_doc_gb9704_sign_indents')
            ? zw_doc_gb9704_sign_indents(false)
            : array('org_chars' => 2, 'date_chars' => 2);
        $sign_indents_sealed = function_exists('zw_doc_gb9704_sign_indents')
            ? zw_doc_gb9704_sign_indents(true)
            : array('org_chars' => 2, 'date_chars' => 4);
        // TASK-1985：印章版面几何（唯一实现 `zw_doc_gb9704_seal_geometry()`）。
        // 静态样式块**无参可断言** ⇒ 这里只能取**兼容章径常量**的几何作默认值；
        // 每篇公文的**真实章径**（登记值，如本会公章 40mm）由 `zw_doc_render_sign_html()` 写成**元素内联样式**覆盖
        // （与 TASK-1278/1282 红头字号内联同一范式：静态块兜底、逐篇内联定稿）。
        $seal_geom = function_exists('zw_doc_gb9704_seal_geometry')
            ? zw_doc_gb9704_seal_geometry(0, (float) $text['line_pt'])
            : array('diameter_mm' => 42.0, 'top_mm' => 25.0, 'gap_lines' => 2, 'line_pt' => (float) $text['line_pt']);
        $receiver_indent = max(0, (int) zw_doc_gb9704_get('main.receiver_indent_chars', 0));
        $attach_indent   = max(0, (int) zw_doc_gb9704_get('main.attach_indent_chars', 2));
        $note_indent     = max(0, (int) zw_doc_gb9704_get('main.note_indent_chars', 2));

        $css  = '<style>';
        $css .= zw_doc_render_font_face_css((string) $base_url);
        $css .= '.tco-doc-sheet{position:relative;box-sizing:border-box;background:#fff;color:#000;'
              . 'width:' . (float) $paper['w_mm'] . 'mm;min-height:' . (float) $paper['h_mm'] . 'mm;'
              . 'padding:' . (float) $margin['top_mm'] . 'mm ' . (float) $margin['right_mm'] . 'mm '
              . (float) $margin['bottom_mm'] . 'mm ' . (float) $margin['left_mm'] . 'mm;'
              . 'margin:0 auto 6mm;}';
        // TASK-1338 批次二 ⑤：版记锚定最后一面版心**下边缘** —— 版心容器改纵向弹性布局，
        // 版记块以自动上边距撑到底部；正文超高时自动退化为紧随正文之后（不重叠、不裁切）。
        $css .= '.tco-doc-flow{width:' . (float) $box['w_mm'] . 'mm;min-height:' . (float) $box['h_mm'] . 'mm;'
              . ($record_anchor ? 'display:flex;flex-direction:column;' : '') . '}';
        $css .= '.tco-doc-body{font-family:' . zw_doc_render_font_family('body') . ';'
              . 'font-size:' . (float) $text['pt'] . 'pt;line-height:' . (float) $text['line_pt'] . 'pt;'
              . 'text-align:justify;color:' . esc_attr('#' . ltrim((string) $text['color'], '#')) . ';}';
        $css .= '.tco-doc-body p{margin:0;}';
        $css .= '.tco-doc-p{text-indent:' . (int) $text['indent_chars'] . 'em;}';
        $css .= '.tco-doc-h1,.tco-doc-h2,.tco-doc-h3,.tco-doc-h4{text-indent:' . (int) $text['indent_chars'] . 'em;}';
        $css .= '.tco-doc-h1{font-family:' . zw_doc_render_font_family('h1') . ';font-weight:bold;}';
        $css .= '.tco-doc-h2{font-family:' . zw_doc_render_font_family('h2') . ';font-weight:normal;}';
        $css .= '.tco-doc-h3,.tco-doc-h4{font-family:' . zw_doc_render_font_family('h3') . ';font-weight:normal;}';
        // TASK-1340 阶段三（用户口径）：「红线和公文标题 以及 公文标题和正文第一行 各留一行」⇒
        // 标题上间距 = 红线→标题 = **1 正文行**（`title.gap_below_rule_lines` 已由 2 改为 1）；
        // 标题下间距 = 标题→正文第一行 = **1 正文行**（新增 `title.gap_below_title_lines`）。
        // 无主送机关时（如「任」字）该下间距即为标题到正文的距离；有主送机关时其自身已有 1 行上间距，
        // 同级外边距**合并取 max** ⇒ 不会叠成 2 行（DOCX 通道为加法，故那边按"无主送机关才插空段"处理）。
        $title_below_lines = max(0, (int) zw_doc_gb9704_get('title.gap_below_title_lines', 1));
        $title_below_pt    = $body_line_pt * $title_below_lines;
        $css .= '.tco-doc-title{font-family:' . zw_doc_render_font_family('title') . ';'
              . 'font-size:' . (float) $title['pt'] . 'pt;line-height:' . (float) $title['line_pt'] . 'pt;'
              . 'font-weight:bold;text-align:center;text-indent:0;margin:' . $title_gap_pt . 'pt 0 ' . $title_below_pt . 'pt;}';
        $css .= '.tco-doc-letterhead{position:relative;width:' . (float) $box['w_mm'] . 'mm;text-align:center;}';
        $css .= '.tco-doc-letterhead img{display:block;width:' . (float) $box['w_mm'] . 'mm;height:auto;}';
        // TASK-1276（CHG-155）：版头标记三要素（份号 / 密级和保密期限 / 紧急程度，GB/T 9704-2012 §7.2.1~7.2.3）。
        // 3 号（mark_pt）、黑体（mark_slot → fonts.h1）、行高 mark_line_pt、**顶格左对齐**（text-align:left;text-indent:0;margin:0）；
        // 标记块与发文机关标志之间留 `mark_gap_lines × mark_line_pt`（pt）空行；三者皆空时整块不输出（见渲染函数）。
        $mark_pt    = (float) zw_doc_gb9704_get('header.mark_pt', 16.0);
        $mark_slot  = (string) zw_doc_gb9704_get('header.mark_slot', 'h1');
        $mark_line  = (float) zw_doc_gb9704_get('header.mark_line_pt', 28.0);
        $mark_gap   = (float) $mark_line * max(0, (int) zw_doc_gb9704_get('header.mark_gap_lines', 1));
        $css .= '.tco-doc-marks{margin:0 0 ' . $mark_gap . 'pt;}';
        $css .= '.tco-doc-mark{font-family:' . zw_doc_render_font_family($mark_slot) . ';'
              . 'font-size:' . $mark_pt . 'pt;line-height:' . $mark_line . 'pt;'
              . 'font-weight:bold;text-align:left;text-indent:0;margin:0;}';
        // TASK-1278（CHG-156）：`.tco-doc-org` 加 `white-space:nowrap` —— 无论红头多长都**不允许折行**
        // （硬保证；字号/字距的每篇适配见 `zw_doc_head_fit()`，以元素**内联样式**落到具体公文上）。
        // TASK-1282（CHG-158）：红头**垂直拉长**（只改高度、**宽度不变**）。
        // TASK-1287（CHG-159）：改为**全等高（方案 C）** —— `scaleY` **随文本变化**（9 字 ≈1.05 / 11 字 1.20 /
        // 26 字 ≈2.85），故**必须**由 `zw_doc_render_letterhead_html()` 写成**元素内联样式**；
        // 本静态样式块只保留一个**默认值**（`org_height_scale` 常量 = 旧 `fixed` 口径 ×1.2；
        // 补偿量按 `org_ink_ratio × org_pt_max × 该常量 − title.line_pt` 推导 = Δ4.688pt），
        // 供**无 JS / 兜底**（静态样式块无参、保持可断言）；每篇公文的真实值由下述内联样式覆盖。
        // ⚠️ **红线**：拉长系数 `scale_y` **绝不进入宽度预算**（`zw_doc_head_fit()` 的 pt 仍完全由
        // 宽度决定）。若把 2.85 乘进字号，26 字将由 15.86pt 变 45.16pt ⇒ **≈615mm ≫ 156mm 版心即溢出** ——
        // 这正是 TASK-1280 已修复、且已被用户截图报过的缺陷（**禁止复发**）。
        // 实现（内联，见 `zw_doc_render_letterhead_html()`）：`transform:scaleY(scale_y)`
        // （`transform` 不影响布局宽度 ⇒ 单行/不出界继续成立）+ `transform-origin:center center`
        // （围绕元素盒中心缩放，不产生水平位移）+ 对称 `padding` 行高补偿（**按实际 `scale_y`** 计算，
        // 推导见 `zw_doc_head_org_padding_pt()`），把拉长后的字面**包回布局盒内**，
        // 从而不压占上方「份号/密级/紧急程度」标记与下方发文字号。
        $org_ln_pt   = (float) $title['line_pt'];
        $org_scale   = (float) zw_doc_gb9704_get('header.org_height_scale', 1.0);
        $org_ink     = (float) zw_doc_gb9704_get('header.org_ink_ratio', 0.86);
        $org_h_pt    = (float) zw_doc_gb9704_get('header.org_pt_max', (float) $title['pt']);
        if ($org_scale <= 0) {
            $org_scale = 1.0;   // 常量被非法改写时的保守回退（不拉长）
        }
        $org_pad_pt = 0.5 * $org_ink * $org_h_pt * $org_scale - 0.5 * $org_ln_pt;
        if ($org_pad_pt < 0) {
            $org_pad_pt = 0.0;
        }
        $org_pad_pt = round($org_pad_pt, 4);
        // TASK-1985（用户指令「在线预览 PDF 文件发现 红头加粗了 需要取消加粗」）：
        // 原「`font-weight:bold` + `-webkit-text-stroke`」是 TASK-1272「无粗体字重时以描边近似加粗」的权宜口径；
        // 自 TASK-1863 起服务器已装**真小标宋**（GB/T 9704-2012 §7.2.4「推荐使用小标宋体字」）⇒ 合成加粗属**多余**：
        // 浏览器对已无 bold 字重的字体做 faux-bold（人工横竖加粗），笔画明显变粗并失真。
        // 现口径：`font-weight:normal`（显式写出，便于断言）+ 描边归 0（`header.org_stroke_px = 0`）⇒ 真字重上纸。
        $css .= '.tco-doc-org{font-family:' . zw_doc_render_font_family('title') . ';'
              . 'line-height:' . $org_ln_pt . 'pt;'
              . 'font-weight:normal;white-space:nowrap;color:' . esc_attr($red) . ';'
              . 'transform:scaleY(' . $org_scale . ');transform-origin:center center;'
              . 'padding:' . $org_pad_pt . 'pt 0;}';
        // TASK-1272（用户拍板「路线 1」）：红头以**呈现参数**近似「大标宋」观感 —— 名义字体不变（小标宋，
        // TASK-1863 起为服务器已装的真小标宋），用「更大字号 + 字距 + 描边加粗」实现（无粗体字重时描边近似）；
        // 字号/字距/描边全部取常量（禁止硬编码 pt/px）。
        // TASK-1278：静态样式块的 font-size/letter-spacing 只作**上限回退**（取 `org_pt_max` 与字距基准），
        // 具体公文的适配值由 `zw_doc_head_fit()` 计算后写成**元素内联样式**（`zw_doc_render_style_block()` 无参可断言）。
        $org_pt   = (float) zw_doc_gb9704_get('header.org_pt_max', (float) zw_doc_gb9704_get('header.org_pt', (float) $title['pt']));
        $org_ls   = (float) zw_doc_gb9704_get('header.org_letter_spacing_pt', 0.0);
        $org_stk  = (float) zw_doc_gb9704_get('header.org_stroke_px', 0.0);
        $org_css  = '.tco-doc-org{font-size:' . $org_pt . 'pt;';
        if ($org_ls > 0) {
            $org_css .= 'letter-spacing:' . $org_ls . 'pt;';
        }
        if ($org_stk > 0) {
            $org_css .= '-webkit-text-stroke:' . $org_stk . 'px currentColor;';
        }
        $css .= $org_css . '}';
        // 版头红色分隔线（TASK-1271）：默认**双线**（上粗 0.5mm / 下细 0.25mm，两线间距 1.0mm，全公文适用）。
        // 实现：单元素用「上下边框 + 容器高度=间距」表达双线，线宽/间距/颜色全部取常量（禁止硬编码 mm）；
        // rule_style=single 时回退为原单线（border-bottom + margin-top）口径。
        $rule_style = (string) zw_doc_gb9704_get('header.rule_style', 'double');
        if ($rule_style === 'double') {
            $css .= '.tco-doc-rule{height:' . (float) zw_doc_gb9704_get('header.rule_gap2_mm', 1.0) . 'mm;'
                  . 'border-top:' . (float) zw_doc_gb9704_get('header.rule_top_h_mm', 0.5) . 'mm solid ' . esc_attr($red) . ';'
                  . 'border-bottom:' . (float) zw_doc_gb9704_get('header.rule2_h_mm', 0.25) . 'mm solid ' . esc_attr($red) . ';'
                  . 'margin-top:' . (float) $header['rule_gap_mm'] . 'mm;}';
        } else {
            $css .= '.tco-doc-rule{border-bottom:' . (float) $header['rule_h_mm'] . 'mm solid ' . esc_attr($red) . ';'
                  . 'margin-top:' . (float) $header['rule_gap_mm'] . 'mm;}';
        }
        $number_align = (string) zw_doc_gb9704_get('header.number_align', 'right');
        if (!in_array($number_align, array('right', 'center', 'left'), true)) {
            $number_align = 'right';
        }
        $css .= '.tco-doc-number{font-family:' . zw_doc_render_font_family('body') . ';'
              . 'font-size:' . (float) $text['pt'] . 'pt;line-height:' . (float) $text['line_pt'] . 'pt;'
              . 'text-align:' . $number_align . ';text-indent:0;margin:0;}';
        $css .= '.tco-doc-number-overlay{position:absolute;transform:translate(-50%,-50%);white-space:nowrap;}';
        // 常量回退模式：发文机关标志与发文字号之间空二行（2 × 正文行高）。
        // TASK-1274（用户指令「按国标调整文号位置」）：**国标口径**（above_rule，默认）下——
        // 发文机关标志 → 空二行 → **发文字号（居中）** → 4mm → 红色反线；空二行挂在**文号**上，反线只留 rule_gap_mm。
        // 旧口径（below_rule，TASK-776）下空二行挂在**反线**上、文号排在反线之后（可切换回退）。
        $org_gap_lines = max(0, (int) zw_doc_gb9704_get('header.org_gap_lines', 2));
        $number_position = (string) zw_doc_gb9704_get('header.number_position', 'above_rule');
        $number_above    = ($number_position !== 'below_rule');
        if ($number_above) {
            $css .= '.tco-doc-number.is-above-rule{margin-top:' . ((float) $text['line_pt'] * $org_gap_lines) . 'pt;}';
        } else {
            $css .= '.tco-doc-letterhead.is-constant .tco-doc-rule{margin-top:' . ((float) $text['line_pt'] * $org_gap_lines) . 'pt;}';
        }
        $css .= '.tco-doc-number.is-below-letterhead{margin-top:4mm;}';
        // TASK-1342（GB/T 9704-2012 §7.2.6 上行文签发人）：**发文字号居左空一字 + 签发人居右空一字，同一行**。
        // 「签发人：」用 3 号仿宋（正文体），姓名用 3 号楷体（`h2` 槽位）——与国标一致。
        // 该行仅在**有签发人**时启用（下行文/平行文不标注签发人，保持原居中/右对齐文号）。
        $signer_side = max(0, (int) zw_doc_gb9704_get('main.signer_side_indent_chars', 1));
        $css .= '.tco-doc-number-row{display:flex;justify-content:space-between;align-items:baseline;'
              . 'padding-left:' . $signer_side . 'em;padding-right:' . $signer_side . 'em;}';
        $css .= '.tco-doc-number-row .tco-doc-number{margin:0;}';
        $css .= '.tco-doc-number-row.is-above-rule{margin-top:' . ((float) $text['line_pt'] * $org_gap_lines) . 'pt;}';
        $css .= '.tco-doc-signer{font-family:' . zw_doc_render_font_family('body') . ';'
              . 'font-size:' . (float) $text['pt'] . 'pt;line-height:' . (float) $text['line_pt'] . 'pt;'
              . 'white-space:nowrap;text-align:right;}';
        $css .= '.tco-doc-signer-name{font-family:' . zw_doc_render_font_family('h2') . ';}';
        // TASK-1333 ①：主送机关编排于标题下**空一行**（空行数 × 正文行高），仍左顶格。
        // TASK-1990（用户报障「主送机关 字号字体不对」）：`.tco-doc-receiver` 是 `.tco-doc-flow` 的**直接子元素**、
        // **不在** `.tco-doc-body` 内 ⇒ 只拿到浏览器默认字体与字号（默认 16px 的衬线/系统字体），
        // 既不是 3 号仿宋、也不是 16pt。GB/T 9704-2012 §5.2.2/§7.3.2：主送机关用 **3 号仿宋**、左顶格、行距同正文。
        // ⇒ 显式补齐与正文**同源**的字体族/字号/行高（不再依赖外层继承；DOCX 通道本就按 3 号仿宋写入）。
        $css .= '.tco-doc-receiver{font-family:' . zw_doc_render_font_family('body') . ';'
              . 'font-size:' . (float) $text['pt'] . 'pt;line-height:' . (float) $text['line_pt'] . 'pt;'
              . 'text-indent:' . ($receiver_indent > 0 ? $receiver_indent . 'em' : '0') . ';'
              . 'margin-top:' . $receiver_gap_pt . 'pt;}';
        $css .= '.tco-doc-sign{position:relative;margin-top:' . $sign_gap_pt . 'pt;text-align:right;}';
        $css .= '.tco-doc-sign p{margin:0;font-family:' . zw_doc_render_font_family('h2') . ';}';
        // TASK-1333 ③：署名与成文日期右空字距共用同一对参数（有印章时日期右空加大）；
        // 「字」按落款自身 3 号字宽折算，故用相对字号单位表达。
        $css .= '.tco-doc-sign-org{padding-right:' . (int) $sign_indents['org_chars'] . 'em;}';
        $css .= '.tco-doc-sign-date{padding-right:' . (int) $sign_indents['date_chars'] . 'em;}';
        $css .= '.tco-doc-sign.is-sealed .tco-doc-sign-date{padding-right:' . (int) $sign_indents_sealed['date_chars'] . 'em;}';
        // TASK-1985（GB/T 9704-2012 §7.3.5.1 逐字口径）：「成文日期一般**右空四字**编排……
        // 单一机关行文时，一般在成文日期之上、**以成文日期为准居中**编排发文机关署名，
        // 印章端正、**居中下压**发文机关署名和成文日期，使发文机关署名和成文日期居**印章中心偏下**位置，
        // 印章顶端应当上距正文（或附件说明）**一行之内**。」
        //
        // 旧实现（TASK-1340）把**整块落款**改成 `text-align:center` 并把两条右空字距归零 ⇒
        // 成文日期落在版心中线上（实测右空 ≈14.6 字，不是 4 字），署名也只是跟着整块居中，
        // **不是**「以成文日期为准居中」。本版按国标重排（`.tco-doc-sign-ref` = 以**成文日期**为宽度基准的参照盒）：
        //   · `.tco-doc-sign-ref`：`width:max-content` + `margin-right` = 成文日期右空字数（4 字）⇒ 日期右空四字精确成立；
        //   · `.tco-doc-sign-org`：绝对定位在参照盒上方、`left:50%` + `translateX(-50%)`
        //     ⇒ 署名水平中心恒等于成文日期中心（国标「以成文日期为准居中」），署名超长时对称外溢、不回卷；
        //   · `.tco-doc-sign` 的 `padding-top` 预留署名那一行（署名脱流后由容器占位）；
        //   · 印章锚定在同一参照盒（水平中心 = 日期中心 = 署名中心），垂直量由 `zw_doc_gb9704_seal_geometry()` 给出。
        // 无印章时**完全不受影响**（仍走 TASK-1333 的右空二字口径，DOM 也不套参照盒）。
        // TASK-1993：'below' 口径（用户指令「印章 放到落款单位和日期之下」）—— 章整体在落款两行之下，
        // 因此必须在落款容器**下方预留章高空间**（`reserve_below_mm`），否则绝对定位的章会压住版记。
        // 'over' 口径（国标）该值为 0，逐字回到 TASK-1985 行为。
        $css .= '.tco-doc-sign.is-sealed{text-align:right;padding-right:0;'
              . 'padding-top:' . (float) $text['line_pt'] . 'pt;'
              . 'margin-top:' . ((float) $text['line_pt'] * (int) $seal_geom['gap_lines']) . 'pt;'
              . 'padding-bottom:' . (float) $seal_geom['reserve_below_mm'] . 'mm;}';
        $css .= '.tco-doc-sign.is-sealed .tco-doc-sign-ref{position:relative;width:max-content;'
              . 'margin-left:auto;margin-right:' . (int) $sign_indents_sealed['date_chars'] . 'em;text-align:center;}';
        $css .= '.tco-doc-sign.is-sealed .tco-doc-sign-org{position:absolute;left:50%;bottom:100%;'
              . 'transform:translateX(-50%);white-space:nowrap;text-align:center;}';
        // TASK-2000：署名回行（长署名）—— 允许换行、按可用宽度收窄，各行以同一中轴居中（GB「回行时与上一行居中」）。
        $css .= '.tco-doc-sign.is-sealed.is-wrapped .tco-doc-sign-org{white-space:normal;line-height:'
              . (float) $text['line_pt'] . 'pt;}';
        // TASK-2009：落款横向对齐口径（谁是中轴）——
        //   is-align-center_on_date（默认）：署名中心 = 日期中心（`left:50% + translateX(-50%)`，见上）；
        //   is-align-center_on_org：署名整块居中于版心（相对落款容器居中），日期改为居中于署名；
        //   is-align-right_align：两行右端对齐（日期右空 N 字不变，署名右端同对齐）。
        $css .= '.tco-doc-sign.is-sealed.is-align-center_on_org .tco-doc-sign-org{left:50%;}';
        $css .= '.tco-doc-sign.is-sealed.is-align-center_on_org .tco-doc-sign-ref{position:static;width:auto;margin:0;text-align:center;}';
        $css .= '.tco-doc-sign.is-sealed.is-align-center_on_org .tco-doc-sign-date{display:inline-block;text-align:center;padding-right:0;}';
        $css .= '.tco-doc-sign.is-sealed.is-align-right_align .tco-doc-sign-org{left:auto;right:' . (int) $sign_indents_sealed['date_chars'] . 'em;transform:none;text-align:right;}';
        $css .= '.tco-doc-sign.is-sealed .tco-doc-sign-org,.tco-doc-sign.is-sealed .tco-doc-sign-date{padding-right:0;}';
        // TASK-1333 ②：附件说明正文下空一行，条目标题左空二字。
        // 选择器必须比正文段落归零规则（正文容器下的段落）更具体，否则上间距会被归零覆盖
        // —— TASK-1333 实渲染量测实测：旧写法上间距实测 0px（本次修正为 37.33px = 1 正文行）。
        $css .= '.tco-doc-body p.tco-doc-attach{margin-top:' . $attach_gap_pt . 'pt;text-indent:' . $attach_indent . 'em;}';
        // TASK-1333 ④：附注居左空二字（脱离落款容器的右对齐），位置仍在成文日期下一行。
        $css .= '.tco-doc-note{text-align:left;text-indent:' . $note_indent . 'em;}';
        $css .= '.tco-doc-record{font-family:' . zw_doc_render_font_family('note') . ';'
              . 'font-size:' . (float) $record['pt'] . 'pt;line-height:' . ((float) $record['pt'] * 1.6) . 'pt;'
              . 'padding:0 ' . $record_side . 'em;}';
        // TASK-1338 批次二 ①：版记分隔线**三条**（首条粗 0.35mm、中间细 0.25mm、末条粗 0.35mm，GB/T 9704-2012 §7.4.1）。
        // 基础类为**粗线**（首/末条），附加类为中间**细线**；线宽取同一批版式常量，禁止硬编码。
        $css .= '.tco-doc-record-block{margin-top:auto;}';
        $css .= '.tco-doc-record-rule{border-top:' . (float) $record_rules['thick_mm'] . 'mm solid #000;}';
        $css .= '.tco-doc-record-rule.is-thin{border-top-width:' . (float) $record_rules['thin_mm'] . 'mm;}';
        // TASK-1338 批次二 ③：印发机关左空一字、印发日期右空一字 —— 同一行两端分置（弹性布局）；
        // 左右空字数取 `record.side_indent_chars`，DOCX 通道以段落左/右字符缩进表达同一键。
        $css .= '.tco-doc-record-print{display:flex;justify-content:space-between;}';
        $css .= '.tco-doc-record-print .tco-doc-record-org{text-align:left;}';
        $css .= '.tco-doc-record-print .tco-doc-record-date{text-align:right;}';
        // 印章锚定在**落款区**内（下压发文机关署名与成文日期），不得相对整页底部定位 —— 否则会压住附件说明与版记。
        // TASK-1985（GB/T 9704-2012 §7.3.5.1）：**居中下压**改成**由几何推导**，不再用「实测量测凑出来的 −7.2mm」：
        //   · 水平：`left:50%` 于 `.tco-doc-sign-ref`（= 以成文日期为宽度基准的参照盒）
        //     ⇒ 章心恒等于成文日期中心，也就恒等于署名中心（署名以日期为准居中）；
        //   · 垂直：`.tco-doc-seal` 的定位基准改为参照盒**顶边**（即成文日期那一行的行顶 = 落款块心），
        //     `top = −(center_offset_mm + 直径/2)` ⇒ 章心在落款块心**上方** `center_offset_mm`
        //     ⇔ 署名与成文日期**居印章中心偏下**（国标第二条约束），`top` 值由 `zw_doc_gb9704_seal_geometry()` 给出；
        //   · 章径与章顶留白的联动见 `zw_doc_gb9704_seal_geometry()`（章越大，落款前自动多留行，
        //     保证「印章顶端上距正文一行之内」）。
        $seal_d_mm   = (float) $seal_geom['diameter_mm'];
        $seal_top_mm = (float) $seal_geom['top_mm'];
        // TASK-1993：`top` 语义 = **相对成文日期行顶边的下移量**（mm）：
        //   'over'（国标）= −(center_offset + 直径/2)（负值 ⇒ 章顶在日期行顶之上）；
        //   'below'（用户口径）= line_mm + gap（正值 ⇒ 章顶贴成文日期底边）。
        // TASK-1994（用户口径「印章置于落款单位和日期的**下一层**」）：章的层序 = **1**（在文字之下）；
        // 落款单位与成文日期 → `z-index:2`（日期须补 `position:relative` 才生效），使文字**盖在章之上**、清晰可读。
        // `seal.text_above=false` 时恢复旧层序（章压在字上），供需要"真章压字"观感时切换。
        $seal_text_above = (bool) zw_doc_gb9704_get('seal.text_above', true);
        $css .= '.tco-doc-seal{position:absolute;left:50%;top:' . (float) $seal_top_mm . 'mm;'
              . 'transform:translateX(-50%);width:' . $seal_d_mm . 'mm;z-index:' . ($seal_text_above ? 1 : 3) . ';}';
        if ($seal_text_above) {
            $css .= '.tco-doc-sign.is-sealed .tco-doc-sign-date{position:relative;z-index:2;}';
            $css .= '.tco-doc-sign.is-sealed .tco-doc-sign-org{z-index:2;}';
        }
        $css .= '.tco-doc-seal img{display:block;width:100%;height:auto;}';
        // 页码位于版心**下边缘再下** 7mm（`footer.baseline_below_text_mm`），故相对纸张底边定位。
        $pageno_bottom_mm = (float) $margin['bottom_mm'] - (float) $footer['baseline_below_text_mm'];
        $css .= '.tco-doc-pageno{position:absolute;left:' . (float) $margin['left_mm'] . 'mm;right:' . (float) $margin['right_mm'] . 'mm;'
              . 'bottom:' . $pageno_bottom_mm . 'mm;'
              . 'font-family:' . zw_doc_render_font_family('page') . ';font-size:' . (float) $footer['pt'] . 'pt;line-height:1;}';
        $css .= '.tco-doc-pageno.is-odd{text-align:right;padding-right:' . $footer_side . 'em;}';
        $css .= '.tco-doc-pageno.is-even{text-align:left;padding-left:' . $footer_side . 'em;}';
        $css .= '.tco-doc-pageno.is-center{text-align:center;}';
        $css .= '.tco-doc-notice{font-family:' . zw_doc_render_font_family('body') . ';font-size:10pt;line-height:1.7;color:#646970;margin:0 0 6px;}';
        $css .= '.tco-doc-toolbar{position:sticky;top:0;z-index:9;background:#f0f0f1;padding:8px 12px;margin:0 0 8mm;'
              . 'display:flex;gap:8px;align-items:center;font-family:' . zw_doc_render_font_family('body') . ';}';
        $css .= '.tco-doc-wrap{background:#f0f0f1;padding:0 0 20px;}';
        $css .= '.tco-doc-scaled{transform-origin:top left;}';
        $css .= '@media print{@page{size:A4;margin:0}'
              . 'html,body{margin:0;padding:0;background:#fff}'
              // TASK-1985（实渲染量测发现）：`.tco-doc-wrap{padding:0 0 20px}` 与 `background:#f0f0f1` 未在打印口径复位
              // ⇒ 20px 下内边距把「预览区灰底」顶到第 2 页，**每份正式用印件都多出一页灰条空白页**
              // （生产产物 `tco-doc-29711-*.pdf` 实测 Pages=2，而公文本身 1 页）。此处按打印口径复位，**零视觉副作用**。
              . '.tco-doc-wrap{padding:0;background:#fff}'
              . '.tco-doc-toolbar,.tco-doc-notice{display:none}'
              . '.tco-doc-sheet{margin:0;box-shadow:none;page-break-after:always}'
              . '.tco-doc-sheet:last-child{page-break-after:auto}}';
        $css .= '</style>';

        return $css;
    }
}

if (!function_exists('zw_doc_render_font_face_css')) {
    /**
     * @font-face 规则（`local()` 链取自 SPEC-87 版式常量）。
     *
     * TASK-1863：公文通道**只走 local() 国标字体名**，不再附带托管 OFL 近似体；
     * 服务器已装授权字体（TASK-1862），chromium/浏览器按 local() 命中真字体。
     *
     * @param string $base_url 主题静态资源前缀（保留入参以兼容既有调用方）。
     * @return string
     */
    function zw_doc_render_font_face_css($base_url = '') {
        if (!function_exists('zw_doc_gb9704_font_face_src')) {
            return '';
        }
        $slots = array('title', 'h1', 'h2', 'h3', 'h4', 'body', 'page');
        $css   = '';
        foreach ($slots as $slot) {
            $src = zw_doc_gb9704_font_face_src($slot, $base_url);
            if ($src === '') {
                continue;
            }
            $css .= '@font-face{font-family:"ZW-DOC-' . strtoupper($slot) . '";src:' . $src . ';}';
        }

        return $css;
    }
}

if (!function_exists('zw_doc_render_font_family')) {
    /**
     * 字体族名（指向 @font-face 定义的 ZW-DOC-* 族）。
     *
     * @param string $slot 版式位。
     * @return string
     */
    function zw_doc_render_font_family($slot) {
        $slot = (string) $slot;
        if ($slot === 'note') {
            $slot = 'body';
        }
        if (!in_array($slot, array('title', 'h1', 'h2', 'h3', 'h4', 'body', 'page'), true)) {
            $slot = 'body';
        }
        $family = 'ZW-DOC-' . strtoupper($slot);

        return '"' . $family . '",serif';
    }
}

if (!function_exists('zw_doc_title_resolve_lines')) {
    /**
     * 标题断行解析（TASK-2014 唯一出处，HTML / DOCX 两通道共用）。
     *
     * 优先级：
     *   1. `$title_lines`（作者在输入框里**手动折行**的结果，≤3 行）——原样使用，一字不动；
     *   2. `$title` 里含换行 ⇒ 按行切（≤3 行）——兼容直接 POST 多行文本的路径；
     *   3. 都没有 ⇒ 走自动回行 `zw_doc_title_lines()`（TASK-1342 的梯形/菱形算法）。
     *
     * @param string $title       单行标题。
     * @param array  $title_lines 手动折行结果（可为空）。
     * @return string[] 行数组（≥1 行）。
     */
    function zw_doc_title_resolve_lines($title, $title_lines = array()) {
        $limit = 3;   // 用户口径：标题最多 3 行
        $lines = array();
        if (is_array($title_lines)) {
            foreach ($title_lines as $ln) {
                $ln = trim((string) $ln);
                if ($ln !== '') {
                    $lines[] = $ln;
                }
                if (count($lines) >= $limit) {
                    break;
                }
            }
        }
        if (!empty($lines)) {
            return $lines;
        }
        $title = (string) $title;
        if (strpos($title, "\n") !== false || strpos($title, "\r") !== false) {
            foreach (preg_split('/\r\n|\r|\n/', $title) as $ln) {
                $ln = trim((string) $ln);
                if ($ln !== '') {
                    $lines[] = $ln;
                }
                if (count($lines) >= $limit) {
                    break;
                }
            }

            return !empty($lines) ? $lines : array($title);
        }

        return function_exists('zw_doc_title_lines') ? (array) zw_doc_title_lines($title) : array($title);
    }
}

if (!function_exists('zw_doc_title_lines')) {
    /**
     * TASK-1342（GB/T 9704-2012 §7.3.1）：公文标题回行的**梯形/菱形**排布 —— 唯一实现，HTML 与 DOCX 两通道共用。
     *
     * 国标口径：「标题…分一行或多行居中排布」；回行应呈梯形/菱形（上短下长或中长两端短），
     * 不得出现「长—短—长」断裂，也不得出现仅一两个字的**孤字行**。
     *
     * 算法：① 单行装得下 ⇒ 原样返回（**单行标题零变化**）；② 两次贪心 —— 先按每行上限得到行数 N，
     * 再按「总字宽 ÷ N」的**平均宽度**重新打包（形成均衡的梯形/菱形）；③ 末行过短则从前一行借一字消除孤字行。
     * 宽度口径与 `zw_doc_render_lines_of_text()` 一致：全角/汉字计 1，半角计 0.5，避免出现第二套换算。
     *
     * @param string $title    标题原文。
     * @param int    $per_line 每行可用字宽（0 = 按版式常量推算：正文每行字数 × 正文字号/标题字号）。
     * @return array 行数组；标题为空时返回空数组。
     */
    function zw_doc_title_lines($title, $per_line = 0) {
        $title = trim((string) $title);
        if ($title === '') {
            return array();
        }
        // TASK-2010（用户「标题 和 署名 折行如何配置」）：折行口径可配 ——
        //   `title.wrap_chars`（0 = 自动：按「版心宽 / 标题字宽」推算每行字数）
        //   `title.wrap_mode`（balanced 均衡 / fill 填满）· `title.wrap_min_chars`（每行最少字数）
        if ((int) $per_line <= 0) {
            $per_line = (int) zw_doc_gb9704_get('title.wrap_chars', 0);
        }
        if ((int) $per_line <= 0) {
            $cpl      = max(1, (int) zw_doc_gb9704_get('grid.chars_per_line', 28));
            $title_pt = (float) zw_doc_gb9704_get('title.pt', 22.0);
            $body_pt  = (float) zw_doc_gb9704_get('text.pt', 16.0);
            $per_line = max(2, (int) floor($cpl * ($body_pt / max(1.0, $title_pt))));
        }
        $per_line = max(2, (int) $per_line);
        $wrap_mode = (string) zw_doc_gb9704_get('title.wrap_mode', 'balanced');
        $wrap_min  = max(0, (int) zw_doc_gb9704_get('title.wrap_min_chars', 0));

        $width = function ($s) {
            $w   = 0.0;
            $len = function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
            for ($i = 0; $i < $len; $i++) {
                $ch   = function_exists('mb_substr') ? mb_substr($s, $i, 1, 'UTF-8') : $s[$i];
                $w   += (strlen($ch) >= 3) ? 1.0 : 0.5;
            }
            return $w;
        };
        if ($width($title) <= $per_line) {
            return array($title);
        }

        $len   = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
        $chars = array();
        for ($i = 0; $i < $len; $i++) {
            $chars[] = function_exists('mb_substr') ? mb_substr($title, $i, 1, 'UTF-8') : $title[$i];
        }
        $total    = count($chars);
        $punct    = array('、' => 1, '，' => 1, '。' => 1, '；' => 1, '：' => 1, '！' => 1, '？' => 1, '）' => 1, '》' => 1);
        $pack = function ($limit) use ($chars, $total, $width, $punct) {
            $lines = array();
            $cur   = '';
            $curw  = 0.0;
            foreach ($chars as $idx => $ch) {
                $cur  .= $ch;
                $curw += $width($ch);
                if ($idx === $total - 1) {
                    $lines[] = $cur;
                    $cur     = '';   // TASK-2060：**必须清空** —— 否则末尾 `if ($cur !== '')` 会把它**再追加一次**
                    break;           //（实测：标题折行 "fill" 模式下最后一行重复，如「视频拍摄的函」出现两次）
                }
                $next_w  = $width($chars[$idx + 1]);
                $at_punc = isset($punct[$ch]);
                if ($curw + $next_w > $limit || ($at_punc && $curw >= $limit * 0.6)) {
                    $lines[] = $cur;
                    $cur     = '';
                    $curw    = 0.0;
                }
            }
            if ($cur !== '') {
                $lines[] = $cur;
            }
            return $lines;
        };

        $lines = $pack($per_line);   // 兜底（DP 不可行时沿用贪心结果）
        // TASK-2010：`fill` 模式 —— 逐行填满（末行短），不做均衡；`balanced` 继续走下方 DP。
        if ($wrap_mode === 'fill') {
            return $lines;
        }
        // `min_chars`：每行最少字数 ⇒ 行数上限 = floor(字数 / 最少字数)，超过则改用 fill（避免切碎）。
        if ($wrap_min > 0) {
            $cap = max(1, (int) floor($total / $wrap_min));
            $need = max(1, (int) ceil($width($title) / max(1, $per_line)));
            if ($need > $cap) {
                return $lines;
            }
        }
        // TASK-2060（用户「标题折行方式有问题」）：**禁断位置** ——
        //   ① 《…》〈…〉（…）**内部**（实测切开「《传统文 / 化大讲堂》」）；
        //   ② 闭合符号**之前**（会把「》」甩到下一行行首）；
        //   ③ **数字 / 西文连续段内部**（实测把「2026」劈成「公布2 / 026年度」）。
        $protected = array();
        $depth     = 0;
        foreach ($chars as $pi => $pch) {
            if ($pch === '《' || $pch === '〈' || $pch === '（' || $pch === '(') {
                $depth++;
                continue;
            }
            if ($pch === '》' || $pch === '〉' || $pch === '）' || $pch === ')') {
                if ($depth > 0) {
                    $protected[$pi] = 1;
                }
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($depth > 0 && $pi > 0) {
                $protected[$pi] = 1;
            }
            if ($pi > 0) {
                $prev_ch = $chars[$pi - 1];
                if (preg_match('/[0-9A-Za-z]/', (string) $pch) && preg_match('/[0-9A-Za-z]/', (string) $prev_ch)) {
                    $protected[$pi] = 1;
                }
            }
        }

        // TASK-1342 阶段五修法：**N 段最小宽度方差的动态规划**划分。
        // 替换原「两遍贪心 + 行数相等才采纳均衡」的回退逻辑 —— 实测那版极差 4~8（首行满、后续行短），未达判据。
        // N = ceil(总字宽 / 每行上限)；硬约束：每段 ≤ per_line、**非末段禁孤字行**（段宽 < 3 不接受）；
        // 软偏好：在标点之后断行给小额奖励，避免把词组拦腰截断。
        $dp_n = max(2, (int) ceil($width($title) / $per_line));
        $dp_n = min($dp_n, max(2, (int) floor($total / 3)));
        $avg  = $width($title) / $dp_n;
        $w    = array();
        for ($i = 0; $i < $total; $i++) {
            $w[$i] = $width($chars[$i]);
        }
        $pref = array(0.0);
        for ($i = 0; $i < $total; $i++) {
            $pref[$i + 1] = $pref[$i] + $w[$i];
        }
        $INF = 1.0e18;
        // TASK-2060：两趟 + 行数递增的 DP ——
        //   第 1 趟：禁断位置 = **硬约束**；解不出就**多给一行**（最多 +2 行）再试；
        //   仍无解：同一 DP 换**重罚**（+1000）；**绝不**退回贪心切碎（会切出「《传统文 / 化大讲堂》」这类结果）。
        $run_dp = function ($hard, $lines_n) use ($total, $per_line, $pref, $punct, $protected, $chars, $INF, $width, $title) {
            $avg  = $width($title) / max(1, $lines_n);
            $cost = array();
            $from = array();
            for ($k = 0; $k <= $lines_n; $k++) {
                for ($i = 0; $i <= $total; $i++) {
                    $cost[$k][$i] = $INF;
                    $from[$k][$i] = -1;
                }
            }
            $cost[0][0] = 0.0;
            for ($k = 1; $k <= $lines_n; $k++) {
                for ($i = $k; $i <= $total; $i++) {
                    for ($j = $k - 1; $j < $i; $j++) {
                        if ($cost[$k - 1][$j] >= $INF) {
                            continue;
                        }
                        $sw = $pref[$i] - $pref[$j];
                        if ($sw > $per_line + 0.001) {
                            continue;                        // 硬约束：不超每行上限
                        }
                        if ($k < $lines_n && $sw < 3) {
                            continue;                        // 非末段禁孤字行
                        }
                        $c = $cost[$k - 1][$j] + ($sw - $avg) * ($sw - $avg);
                        if ($i < $total && isset($punct[$chars[$i - 1]])) {
                            $c -= 0.35;                      // 标点后断行奖励
                        }
                        if ($i < $total && isset($protected[$i])) {
                            if ($hard) {
                                continue;                    // 硬约束：禁断位置直接跳过
                            }
                            $c += 1000.0;                    // 重罚版：保证有均衡解
                        }
                        if ($c < $cost[$k][$i]) {
                            $cost[$k][$i] = $c;
                            $from[$k][$i] = $j;
                        }
                    }
                }
            }
            if ($cost[$lines_n][$total] >= $INF) {
                return null;
            }
            $cuts = array();
            $i    = $total;
            for ($k = $lines_n; $k >= 1; $k--) {
                $j      = $from[$k][$i];
                $cuts[] = array($j, $i);
                $i      = $j;
            }
            $cuts = array_reverse($cuts);
            $out  = array();
            foreach ($cuts as $c) {
                $out[] = implode('', array_slice($chars, $c[0], $c[1] - $c[0]));
            }

            return $out;
        };
        $dp_lines = null;
        for ($try_n = $dp_n; $try_n <= $dp_n + 2; $try_n++) {
            $dp_lines = $run_dp(true, $try_n);
            if (is_array($dp_lines) && !empty($dp_lines)) {
                break;
            }
        }
        if (!is_array($dp_lines) || empty($dp_lines)) {
            $dp_lines = $run_dp(false, $dp_n);
        }
        if (is_array($dp_lines) && !empty($dp_lines)) {
            $lines = $dp_lines;
        }
        // 消除孤字行：末行不足 3 字宽时，从前一行借最后一字（行数与总字数均不变）。
        $n = count($lines);
        if ($n >= 2 && $width($lines[$n - 1]) < 3) {
            $prev = $lines[$n - 2];
            $plen = function_exists('mb_strlen') ? mb_strlen($prev, 'UTF-8') : strlen($prev);
            if ($plen > 2) {
                $lines[$n - 2] = function_exists('mb_substr') ? mb_substr($prev, 0, $plen - 1, 'UTF-8') : substr($prev, 0, -1);
                $moved         = function_exists('mb_substr') ? mb_substr($prev, $plen - 1, 1, 'UTF-8') : substr($prev, -1);
                $lines[$n - 1] = $moved . $lines[$n - 1];
            }
        }
        return $lines;
    }
}

if (!function_exists('zw_doc_render_lines_of_text')) {
    /**
     * 估算一段文本占用行数（按每行字数换算）。
     *
     * @param string $text            文本。
     * @param int    $chars_per_line  每行字数。
     * @return int
     */
    function zw_doc_render_lines_of_text($text, $chars_per_line) {
        $chars_per_line = max(1, (int) $chars_per_line);
        $lines          = 0;
        foreach (explode("\n", (string) $text) as $line) {
            $len    = function_exists('zw_doc_body_strlen') ? zw_doc_body_strlen($line) : strlen($line);
            $lines += max(1, (int) ceil($len / $chars_per_line));
        }

        return max(1, $lines);
    }
}

if (!function_exists('zw_doc_render_lines_for_block')) {
    /**
     * 估算单个块占用的行数（标题字号大于正文，按比例折算每行字数）。
     *
     * @param array $block 块。
     * @return int
     */
    function zw_doc_render_lines_for_block($block) {
        $chars_per_line = (int) zw_doc_gb9704_get('grid.chars_per_line', 28);
        $type           = isset($block['t']) ? (string) $block['t'] : 'p';
        $text           = isset($block['text']) ? (string) $block['text'] : '';

        return zw_doc_render_lines_of_text($text, $chars_per_line);
    }
}

if (!function_exists('zw_doc_render_letterhead_lines')) {
    /**
     * 版头占用行数（用于分页估算）。
     *
     * - 图片模式：图片高度按 `header.letterhead_lines`（默认为图片高度折算的行数上限）；
     * - 常量模式：机关标志 1 行 + 红线 1 行。
     *
     * @param array $args 渲染参数。
     * @return int
     */
    function zw_doc_render_letterhead_lines($args, $meta = array()) {
        // TASK-2019（用户「比例不对，页面是不是被拉长了？」）：**按实际高度折算行数**。
        // 旧实现写死 2 行，而真实版头（版头标记 3 行 + 发文机关标志 43pt + 分隔线与间距 + 发文字号行）
        // 实测约 **98mm ≈ 10 行** ⇒ 首页少算 8 行 ≈ 79mm，正文被顶到纸面以下、整张"页面"被拉长。
        $line_pt = max(1.0, (float) zw_doc_gb9704_get('text.line_pt', 28.0));
        $mm2line = function ($mm) use ($line_pt) {
            return (float) $mm / ($line_pt * 25.4 / 72);
        };

        // 版头标记（份号 / 密级和保密期限 / 紧急程度）：各一行（有值才算，渲染侧同口径）。
        $marks = 0;
        if (is_array($meta)) {
            foreach (array('copy_no', 'secret', 'urgency') as $key) {
                if (!empty($meta[$key]) && trim((string) $meta[$key]) !== '') {
                    $marks++;
                }
            }
        }

        // 发文机关标志：图片红头按常量行数；文字标志按 `header.org_target_mm` 的图文区高度折算。
        $org_lines = 0.0;
        if (!empty($args['letterhead_url'])) {
            $org_lines = (float) max(2, (int) zw_doc_gb9704_get('header.letterhead_lines', 6));
        } else {
            $org_mm = (float) zw_doc_gb9704_get('header.org_target_mm', 15.2);
            $org_lines = max(1.0, $mm2line($org_mm));
        }

        // 分隔线与文号行：`header.rule_gap_mm` + 线高 + 文号行（1 行）。
        $rule_lines = $mm2line((float) zw_doc_gb9704_get('header.rule_gap_mm', 4.0)
            + (float) zw_doc_gb9704_get('header.rule_h_mm', 0.35)
            + (float) zw_doc_gb9704_get('header.rule_top_h_mm', 0.5));

        return (int) ceil($marks + $org_lines + $rule_lines + 1.0);
    }
}

if (!function_exists('zw_doc_render_paginate')) {
    /**
     * 分页估算：把块按「每面 N 行」分配到页。
     *
     * 首页扣减版头与标题占用行数；每页行数取 `grid.lines_per_page`。
     * 这是**排版建议**，与浏览器/PDF 引擎的最终分页可能有 1 页误差，页面会显式声明。
     *
     * @param array $body 正文 AST。
     * @param array $args 渲染参数。
     * @return array 每页一个数组，元素为该页承载的块索引列表。
     */
    function zw_doc_render_paginate($body, $args = array()) {
        $args       = array_merge(zw_doc_render_defaults(), is_array($args) ? $args : array());
        $lines_page = max(1, (int) zw_doc_gb9704_get('grid.lines_per_page', 22));
        $chars_line = max(1, (int) zw_doc_gb9704_get('grid.chars_per_line', 28));
        $meta       = isset($body['meta']) && is_array($body['meta']) ? $body['meta'] : array();
        $blocks     = isset($body['blocks']) && is_array($body['blocks']) ? array_values($body['blocks']) : array();

        // 首页固定占用（TASK-2019：版头按实际高度折算）
        $used = function_exists('zw_doc_render_letterhead_lines')
            ? (int) zw_doc_render_letterhead_lines($args, $meta)
            : 2;
        if (!empty($meta['title'])) {
            $title_chars_line = max(1, (int) floor($chars_line * 16 / max(1, (int) zw_doc_gb9704_get('title.pt', 22))));
            $used            += zw_doc_render_lines_of_text($meta['title'], $title_chars_line);
        }
        if (!empty($meta['receiver'])) {
            $used += max(1, (int) zw_doc_gb9704_get('main.receiver_gap_lines', 1))
                   + zw_doc_render_lines_of_text($meta['receiver'], $chars_line);
        }

        // 尾部固定占用（落款 + 附件说明 + 版记），从最后一页倒扣
        // TASK-2019：尾部件 = 落款（空行 + 署名 + 日期）+ 附注 + **版记**（抄送行 / 印发行 + 两条横线）。
        // 旧实现**没有计入版记**，也没有为附注留行 ⇒ 末页容易把版记顶出纸面。
        $tail = (int) zw_doc_gb9704_get('sign.gap_lines_before_org', 1) + 2; // 署名 + 日期
        if (!empty($meta['note'])) {
            $tail += 1; // 附注
        }
        $record_pt = (float) zw_doc_gb9704_get('record.pt', 14.0);
        $record_row = max(1, (int) ceil(($record_pt * 1.5) / max(1.0, (float) zw_doc_gb9704_get('text.line_pt', 28.0))));
        if ((!empty($meta['cc']))) {
            $tail += $record_row; // 抄送行
        }
        if (!empty($args['doc_department']) || !empty($meta['print_org'])) {
            $tail += $record_row; // 印发机关 + 印发日期行
        }
        $tail += 2; // 版记上下横线（各按 1 行折算，保守）
        if (!empty($meta['attach_note']) && is_array($meta['attach_note'])) {
            $tail += (int) zw_doc_gb9704_get('main.attach_gap_lines', 1);
            foreach ($meta['attach_note'] as $note) {
                $tail += zw_doc_render_lines_of_text($note, $chars_line);
            }
        }

        $pages    = array(array());
        $page_idx = 0;
        $used     = (int) $used;   // 首页已占用（版头/标题/主送）
        $used_per = array(0 => $used);
        // TASK-2060（用户「主送和正文位置也有问题」）：**先排满、末页再让位**。
        // TASK-2019 为防"版记被顶出纸面"改成"从第 1 页起就预留尾部件"，代价是首页只剩 1~2 行给正文
        // ⇒ 实测第 1 页只有版头+标题+主送、正文全跑第 2 页。正确做法：先按整页容量贪心排，
        // 末页放不下尾部件时再从末页**尾部往前挪整块**到新页。
        $capacity = max(1, $lines_page);

        foreach ($blocks as $index => $block) {
            $need = zw_doc_render_lines_for_block($block);
            if ($used + $need > $capacity) {
                $pages[]             = array();
                $page_idx++;
                $used                = 0;
                $used_per[$page_idx] = 0;
            }
            $pages[$page_idx][]  = $index;
            $used               += $need;
            $used_per[$page_idx] = $used;
        }

        // 末页让位：把末页最后一个块挪到新页，直到「末页内容 + 尾部件」放得下（末页至少保留 1 块）。
        $guard = 0;
        while ($guard++ < 200) {
            $last = count($pages) - 1;
            if ($last < 0) {
                break;
            }
            $last_used = isset($used_per[$last]) ? (int) $used_per[$last] : 0;
            if ($last_used + $tail <= $lines_page) {
                break;
            }
            if (count($pages[$last]) <= 1) {
                break;
            }
            $moved    = array_pop($pages[$last]);
            $mv_lines = zw_doc_render_lines_for_block($blocks[$moved]);
            $used_per[$last] = max(0, $last_used - $mv_lines);
            if (!isset($pages[$last + 1]) || $pages[$last + 1] === array()) {
                $pages[$last + 1]    = array();
                $used_per[$last + 1] = 0;
            }
            array_unshift($pages[$last + 1], $moved);
            $used_per[$last + 1] = (int) $used_per[$last + 1] + $mv_lines;
        }

        // 去掉可能产生的空页（末页被搬空的情形）。
        $clean = array();
        foreach ($pages as $pg) {
            if (!empty($pg)) {
                $clean[] = array_values($pg);
            }
        }

        return !empty($clean) ? $clean : array(array());
    }
}

if (!function_exists('zw_doc_render_mark_lines')) {
    /**
     * 版头标记文本行（TASK-1276 / CHG-155，GB/T 9704-2012 §7.2.1~7.2.3）。
     *
     * 顺序**固定**为：份号 → 密级和保密期限 → 紧急程度（自上而下分行排列）。
     * 空值不产生行；三者皆为空的文本行时返回空数组（调用方据此**整体不输出标记块**）。
     *
     * 份号按 `header.copy_no_digits` 左补 0（渲染侧兜底：历史 body JSON 里的份号未被归一过）；
     * `meta.secret` 已是「密级 + 分隔符 + 期限」的成品串（拼装唯一出处在录入侧 `zw_doc_composer_secret_build()`）。
     *
     * @param array $meta 正文 meta。
     * @return string[]
     */
    function zw_doc_render_mark_lines($meta) {
        $meta  = is_array($meta) ? $meta : array();
        $lines = array();

        // TASK-2024：显示开关（胶囊开关）—— 关掉即不输出该要素（有值也不显示）。
        $show_copy    = (bool) zw_doc_gb9704_get('header.show_copy_no', true);
        $show_secret  = (bool) zw_doc_gb9704_get('header.show_secret', true);
        $show_period  = (bool) zw_doc_gb9704_get('header.show_secret_period', true);
        $show_urgency = (bool) zw_doc_gb9704_get('header.show_urgency', true);

        $copy_no = isset($meta['copy_no']) ? trim((string) $meta['copy_no']) : '';
        if (!$show_copy) {
            $copy_no = '';
        }
        if ($copy_no !== '') {
            $digits = preg_replace('/\D+/', '', $copy_no);
            if (!is_string($digits)) {
                $digits = '';
            }
            $width = (int) zw_doc_gb9704_get('header.copy_no_digits', 6);
            if ($width <= 0) {
                $width = 6;
            }
            if ($digits !== '') {
                if (strlen($digits) > $width) {
                    $digits = substr($digits, -$width);
                }
                $lines[] = str_pad($digits, $width, '0', STR_PAD_LEFT);
            }
        }

        $secret = isset($meta['secret']) ? trim((string) $meta['secret']) : '';
        if ($secret !== '' && !$show_secret) {
            $secret = '';
        }
        // 「密级★保密期限」：仅关闭**期限**时只保留密级部分（分隔符唯一出处 `header.secret_separator`）。
        if ($secret !== '' && !$show_period) {
            $sep = (string) zw_doc_gb9704_get('header.secret_separator', '★');
            if ($sep !== '' && strpos($secret, $sep) !== false) {
                $secret = (string) substr($secret, 0, (int) strpos($secret, $sep));
            }
        }
        if ($secret !== '') {
            $lines[] = $secret;
        }

        $urgency = isset($meta['urgency']) ? trim((string) $meta['urgency']) : '';
        if (!$show_urgency) {
            $urgency = '';
        }
        if ($urgency !== '') {
            $lines[] = $urgency;
        }

        return $lines;
    }
}

if (!function_exists('zw_doc_render_marks_html')) {
    /**
     * 版头标记块 HTML（份号 / 密级和保密期限 / 紧急程度）。
     *
     * **三者皆空时返回空串** —— 版面上不输出任何标记 DOM（GB/T 9704 版头标记三要素为可选标注）。
     *
     * @param array $meta 正文 meta。
     * @return string
     */
    function zw_doc_render_marks_html($meta) {
        $lines = zw_doc_render_mark_lines($meta);
        if (empty($lines)) {
            return '';
        }
        $html = '<div class="tco-doc-marks">';
        foreach ($lines as $line) {
            $html .= '<p class="tco-doc-mark">' . esc_html($line) . '</p>';
        }
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('zw_doc_render_letterhead_html')) {
    /**
     * 版头 HTML：图片模式（含可选坐标叠加）/ 常量模式 / **fail-closed 提示块**（TASK-1422）。
     *
     * @param array $meta 正文 meta。
     * @param array $args 渲染参数。
     * @return string
     */
    function zw_doc_render_letterhead_html($meta, $args) {
        $args   = array_merge(zw_doc_render_defaults(), is_array($args) ? $args : array());
        $org    = isset($meta['org_name']) ? (string) $meta['org_name'] : '';
        // 发文字号不在正文 meta 里，而来自发文记录（`_zw_doc_number`），故经渲染参数传入
        $number = (string) $args['doc_number'];

        // TASK-1422 **fail-closed**：切到「使用上传的红头文件（图片）」但没有可用已启用红头。
        // 输出**明确提示 + 引导**替代版头；**绝不静默回落「系统自动生成红头」，也绝不渲染空白红头**
        // （文案唯一出处 `zw_doc_letterhead_mode_state()`，经 `zw_doc_letterhead_render_args()` 下发）。
        if ((string) $args['letterhead_blocked'] !== '') {
            $hint = (string) $args['letterhead_assets_url'];
            $html = '<div class="tco-doc-letterhead is-blocked" data-zw-note="letterhead-mode-blocked"'
                  . ' style="width:' . (float) zw_doc_gb9704_get('header.letterhead_width_mm', 156.0) . 'mm;'
                  . 'box-sizing:border-box;border:1px dashed #b30404;padding:6mm;color:#b30404;'
                  . 'font-size:10.5pt;line-height:1.7;text-align:left">'
                  . '<strong>红头未生成（fail-closed）：</strong>' . esc_html((string) $args['letterhead_blocked'])
                  . ($hint !== '' ? '<br>前往设置：<a href="' . esc_url($hint) . '">红头与印章 → 红头模板</a>' : '')
                  . '</div>';

            return $html;
        }

        // TASK-1274：文号位置口径（国标 above_rule 为默认；below_rule 为旧口径回退）——
        // 在图片/常量两分支之前先算好，避免回退分支在图片模式读取未定义变量。
        $number_above_rule = ((string) zw_doc_gb9704_get('header.number_position', 'above_rule') !== 'below_rule');
        $html   = '<div class="tco-doc-letterhead' . (!empty($args['letterhead_url']) ? ' is-image' : ' is-constant') . '">';

        // TASK-1276（CHG-155）：版头标记三要素**在发文机关标志（文字或图片）之前**输出，
        // 顺序固定 份号 → 密级和保密期限 → 紧急程度；三者皆空时 `zw_doc_render_marks_html()` 返回空串。
        // 图片模式下标记同样位于红头图片之上（标记属版心左上的版面要素，不进入图片内部）。
        $html .= zw_doc_render_marks_html($meta);

        if (!empty($args['letterhead_url'])) {
            // TASK-1985：红头图片同属「工人只能内联 data-URI」的素材，必须走 `zw_doc_render_img_src()`
            // （`esc_url()` 会把 data-URI 清空 ⇒ 图片模式红头同样破图）。
            $html .= '<img src="' . zw_doc_render_img_src((string) $args['letterhead_url']) . '" alt="' . esc_attr($args['letterhead_alt'] !== '' ? $args['letterhead_alt'] : '发文机关标志') . '">';
            if (is_array($args['number_overlay'])) {
                $x    = isset($args['number_overlay']['x']) ? (float) $args['number_overlay']['x'] : 50.0;
                $y    = isset($args['number_overlay']['y']) ? (float) $args['number_overlay']['y'] : 78.0;
                $size = isset($args['number_overlay']['size']) ? (float) $args['number_overlay']['size'] : 0.0;
                $style = 'left:' . $x . '%;top:' . $y . '%;';
                if ($size > 0) {
                    $style .= 'font-size:' . $size . 'pt;';
                }
                $html .= '<p class="tco-doc-number tco-doc-number-overlay" style="' . esc_attr($style) . '">' . esc_html($number) . '</p>';
            }
        } else {
            // 常量回退模式。GB/T 9704 版头顺序：发文机关标志 → 空二行 → 发文字号 → 4mm → 红色反线。
            // 修复（TASK-775 整体验收发现）：此前漏渲染发文字号，导致无红头图时文号整体缺失。
            // TASK-1278（CHG-156）：红头字号/字距按**本文机关标志**经唯一适配函数取得，写成**元素内联样式**
            // （静态样式块无参、保持可断言）；配合 `.tco-doc-org{white-space:nowrap}` 保证**单行不折行**。
            // TASK-1282（CHG-158）：**垂直拉长不进字号** —— 拉长只改高度，字号/字距永远是宽度口径。
            // TASK-1287（CHG-159）：**全等高（方案 C）** ⇒ `scaleY` 随文本变化（9 字 ≈1.05 / 11 字 1.20 /
            // 26 字 ≈2.85），故 `transform:scaleY(...)` 与**行高补偿 `padding`（按实际 scale_y）** 一并写成
            // **元素内联样式**（覆盖静态样式块的默认值，供无 JS 兜底）；内联 `font-size`/`letter-spacing`
            // 仍**只有宽度口径** —— 因此**拉长系数不会进入字号**，不会重犯 TASK-1280 的溢出缺陷。
            $org_fit = function_exists('zw_doc_head_fit')
                ? zw_doc_head_fit($org)
                : array('pt' => (float) zw_doc_gb9704_get('header.org_pt_max', 43.0), 'letter_spacing_pt' => (float) zw_doc_gb9704_get('header.org_letter_spacing_pt', 0.0));
            $org_stretch = function_exists('zw_doc_head_stretch')
                ? zw_doc_head_stretch($org)
                : array('scale_y' => 1.0, 'docx_pt' => (float) $org_fit['pt'], 'docx_char_width_percent' => 100);
            $org_scale_y = (float) $org_stretch['scale_y'];
            if ($org_scale_y <= 0) {
                $org_scale_y = 1.0;
            }
            $org_pad = function_exists('zw_doc_head_org_padding_pt')
                ? (float) zw_doc_head_org_padding_pt($org)
                : 0.0;
            if ($org_pad < 0) {
                $org_pad = 0.0;
            }
            $org_style = 'font-size:' . $org_fit['pt'] . 'pt;letter-spacing:' . $org_fit['letter_spacing_pt'] . 'pt;'
                       . 'transform:scaleY(' . $org_scale_y . ');padding:' . $org_pad . 'pt 0;';
            $html .= '<div class="tco-doc-org" style="' . esc_attr($org_style) . '">' . esc_html($org) . '</div>';
            // TASK-1274（国标口径）：发文字号排进版头容器**内、红色反线之前**（居中）；
            // 文号为空时也输出（空段落）以保持「发文机关标志下空二行」的版式节奏稳定。
            if ($number_above_rule && !is_array($args['number_overlay'])) {
                // TASK-1342（GB/T 9704-2012 §7.2.6）：**有签发人**（上行文）时，文号与签发人排在同一行——
                // 文号居左空一字、签发人居右空一字；无签发人时保持原来的单段落文号（不动既有版式）。
                $signer_name = isset($meta['signer']) ? trim((string) $meta['signer']) : '';
                if (!(bool) zw_doc_gb9704_get('header.show_signer', true)) {
                    $signer_name = '';
                }
                if ($signer_name !== '') {
                    $html .= '<div class="tco-doc-number-row is-above-rule" data-zw-note="letterhead-number-with-signer">'
                          . '<p class="tco-doc-number">' . esc_html($number) . '</p>'
                          . '<p class="tco-doc-signer">签发人：<span class="tco-doc-signer-name">' . esc_html($signer_name) . '</span></p>'
                          . '</div>';
                } else {
                    $html .= '<p class="tco-doc-number is-above-rule" data-zw-note="letterhead-number-above-rule">' . esc_html($number) . '</p>';
                }
            }
            $html .= '<div class="tco-doc-rule"></div>';
        }

        $html .= '</div>';

        // 发文字号（TASK-1274）：
        // - 图片模式（未配坐标）：红头图片自带红色反线，无法把文号排进图片内部 ⇒ 只能排图片下方（留标记）；
        //   走国标口径需为该素材配置 `number_overlay`（见 SPEC-87 §18.6）；
        // - 常量模式：国标口径已在版头容器内、反线之前输出；仅旧口径 below_rule 才排到反线之后（回退）。
        // 仅当图片模式**配置了叠加坐标**时（该素材已预留文号区）才把文号叠到图片内，不走本分支。
        if ($number !== '' && !is_array($args['number_overlay']) && !empty($args['letterhead_url'])) {
            $html .= '<p class="tco-doc-number is-below-letterhead" data-zw-note="letterhead-number-below">' . esc_html($number) . '</p>';
        } elseif (!$number_above_rule && $number !== '' && !is_array($args['number_overlay'])) {
            $html .= '<p class="tco-doc-number is-below-letterhead" data-zw-note="letterhead-number-below-rule">' . esc_html($number) . '</p>';
        }

        return $html;
    }
}

if (!function_exists('zw_doc_render_blocks_html')) {
    /**
     * 正文块 HTML（层次序数由渲染层按序生成，跨页连续）。
     *
     * @param array $blocks  全量块列表。
     * @param array $indexes 本页块索引。
     * @return string
     */
    function zw_doc_render_blocks_html($blocks, $indexes = array()) {
        $types    = function_exists('zw_doc_body_block_types') ? zw_doc_body_block_types() : array('p', 'h1', 'h2', 'h3', 'h4');
        $ordinals = zw_doc_body_build_ordinals($blocks); // 序数唯一实现在正文模型（TASK-768 下沉）
        $out      = '';
        foreach ((array) $indexes as $index) {
            if (!isset($blocks[$index]) || !is_array($blocks[$index])) {
                continue;
            }
            $block = $blocks[$index];
            $type  = isset($block['t']) ? strtolower((string) $block['t']) : 'p';
            if (!in_array($type, $types, true)) {
                continue;
            }
            $text = isset($block['text']) ? (string) $block['text'] : '';
            if (trim($text) === '') {
                continue;
            }
            $prefix = isset($ordinals[$index]) ? (string) $ordinals[$index] : '';
            $class  = 'tco-doc-' . $type;
            $out   .= '<p class="' . esc_attr($class) . '">' . nl2br(esc_html($prefix . $text)) . '</p>';
        }

        return $out;
    }
}

if (!function_exists('zw_doc_sign_text_width_mm')) {
    /**
     * 落款文字宽度估算（mm）—— 只用于**版心溢出钳制**，不参与字号/字距决定。
     *
     * 模型：**全角 1em / 半角 0.5em**（CJK 公文用字体均为全角等宽；ASCII 与数字按半角），
     * 乘以字号（pt→mm）。误差来自字体实际步进（小标宋≈0.908em、仿宋≈1.0em），故调用方须留安全余量。
     *
     * @param string $text    文字。
     * @param float  $font_pt 字号（pt）。
     * @return float mm。
     */
    function zw_doc_sign_text_width_mm($text, $font_pt) {
        $text = (string) $text;
        $pt   = (float) $font_pt;
        if ($text === '' || $pt <= 0) {
            return 0.0;
        }
        $em = 0.0;
        $len = function_exists('mb_strlen') ? (int) mb_strlen($text, 'UTF-8') : strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = function_exists('mb_substr') ? mb_substr($text, $i, 1, 'UTF-8') : $text[$i];
            // ASCII（含数字/字母/标点）按半角 0.5em，其余（CJK/全角符号）按 1em。
            $em += (strlen($ch) === 1 && ord($ch) < 128) ? 0.5 : 1.0;
        }

        return $em * $pt * 25.4 / 72;
    }
}

if (!function_exists('zw_doc_sign_metrics')) {
    /**
     * 落款几何读数（TASK-2009：用户「现有配置无法判断署名长度」）。
     *
     * 用同一套宽度模型与对齐口径，算出「署名宽 / 日期宽 / 中轴 / 偏移 / 行数 / 是否越界」，
     * 供**渲染层**与**设计器读数面板**共用 —— 所见即所得，不再靠肉眼判断。
     *
     * 坐标：以**版心左边界**为 0（mm）。
     *
     * @param array $opts {
     *     org, date: string；box_w: 版心宽 mm；font_pt：字号 pt；
     *     date_chars: 日期右空字数；align: center_on_date|center_on_org|right_align；wrap_min_chars:int
     * }
     * @return array
     */
    function zw_doc_sign_metrics($opts = array()) {
        $opts = array_merge(array(
            'org' => '', 'date' => '', 'box_w' => 156.0, 'font_pt' => 16.0,
            'date_chars' => 4, 'align' => 'center_on_date', 'wrap_min_chars' => 0,
        ), is_array($opts) ? $opts : array());

        $box  = (float) $opts['box_w'];
        $pt   = (float) $opts['font_pt'];
        $em   = $pt * 25.4 / 72;
        $org  = (string) $opts['org'];
        $date = (string) $opts['date'];
        $align = (string) $opts['align'];
        if (!in_array($align, array('center_on_date', 'center_on_org', 'right_align'), true)) {
            $align = 'center_on_date';
        }

        $org_w  = zw_doc_sign_text_width_mm($org, $pt);
        $date_w = zw_doc_sign_text_width_mm($date, $pt);
        $date_right = $box - max(0, (int) $opts['date_chars']) * $em;
        $date_cx    = $date_right - $date_w / 2;

        // 中轴与单行可用宽度：随对齐口径变化。
        if ($align === 'center_on_org') {
            $axis = $box / 2;                                  // 署名整块居中于版心
            $max_w = $box - 2 * 1.0;                           // 留 1mm 边距
        } else {
            $axis = $date_cx;                                  // 国标：以日期中心为准
            $max_w = max(10.0, 2 * ($box - $axis) - 0.5);       // 以日期中心为中轴、左右等宽
        }

        $wrap = function_exists('zw_doc_sign_org_lines')
            ? zw_doc_sign_org_lines($org, $max_w, $pt)
            : array('lines' => array($org), 'wrapped' => false);
        $lines = (array) $wrap['lines'];
        $line_w = 0.0;
        foreach ($lines as $ln) {
            $line_w = max($line_w, zw_doc_sign_text_width_mm((string) $ln, $pt));
        }

        // 各口径下的最终放置
        if ($align === 'right_align') {
            $org_right = $date_right;                          // 两行右端对齐
            $org_cx    = $org_right - $line_w / 2;
        } else {
            $org_cx    = $axis;                                // 居中于中轴
            $org_right = $org_cx + $line_w / 2;
        }
        $org_left = $org_cx - $line_w / 2;

        $delta = $org_cx - $date_cx;                           // 署名中心 − 日期中心（0 = 严格居中）

        return array(
            'align'        => $align,
            'org_w_mm'     => round($org_w, 2),
            'date_w_mm'    => round($date_w, 2),
            'line_w_mm'    => round($line_w, 2),
            'lines'        => count($lines),
            'line_texts'   => $lines,
            'wrap_min_chars' => (int) $opts['wrap_min_chars'],
            'axis_mm'      => round($axis, 2),
            'date_cx_mm'   => round($date_cx, 2),
            'org_cx_mm'    => round($org_cx, 2),
            'delta_mm'     => round($delta, 2),
            'org_left_mm'  => round($org_left, 2),
            'org_right_mm' => round($org_right, 2),
            'date_right_mm' => round($date_right, 2),
            'over_left'    => $org_left < -0.01,
            'over_right'   => $org_right > $box + 0.01,
        );
    }
}

if (!function_exists('zw_doc_sign_org_lines')) {
    /**
     * 发文机关署名**回行**（TASK-2000；用户口径「日期和单位要居中对齐」）。
     *
     * 背景：GB「署名以成文日期为准居中」在署名很长时会顶出版心（生产 `中传函〔2026〕94号` 20 字 = 118.5mm，
     * 居中后右缘 200.92mm > 版心右 184mm）。TASK-1995 曾用「左移钳制」保边界，但**破坏了居中**
     * （用户复测即报「日期和单位又没居中对齐」）。
     *
     * 现口径（GB §7.3.5.1「署名回行时与上一行居中」的落地）：**能一行就一行；超出可用宽度则回行，
     * 每行宽度 ≤ 可用宽度、各行以同一中轴居中**（不裁字、不换行英文词、不越界）。
     *
     * 可用宽度 = 2 ×（版心右 − 成文日期中心），即「以日期中心为中轴、左右等宽」的最大宽度。
     *
     * @param string $org        发文机关署名。
     * @param float  $max_w_mm   单行可用宽度（mm）。
     * @param float  $font_pt    字号（pt）。
     * @return array{lines:string[],wrapped:bool,max_w_mm:float}
     */
    function zw_doc_sign_org_lines($org, $max_w_mm, $font_pt) {
        $org = trim((string) $org);
        $max = (float) $max_w_mm;
        $pt  = (float) $font_pt;
        if ($org === '' || $max <= 0 || $pt <= 0) {
            return array('lines' => $org === '' ? array() : array($org), 'wrapped' => false, 'max_w_mm' => round($max, 2));
        }
        if (zw_doc_sign_text_width_mm($org, $pt) <= $max) {
            return array('lines' => array($org), 'wrapped' => false, 'max_w_mm' => round($max, 2));
        }
        $len = function_exists('mb_strlen') ? (int) mb_strlen($org, 'UTF-8') : strlen($org);
        if ($len <= 1) {
            return array('lines' => array($org), 'wrapped' => false, 'max_w_mm' => round($max, 2));
        }
        // TASK-2010：折行口径可配 —— `sign.wrap_mode`（balanced 均衡 / fill 填满）与 `sign.wrap_min_chars`
        //（每行最少字数；**不越界优先**，仅在不超宽时用它压缩行数）。
        $mode = function_exists('zw_doc_gb9704_get') ? (string) zw_doc_gb9704_get('sign.wrap_mode', 'balanced') : 'balanced';
        $min_chars = function_exists('zw_doc_gb9704_get') ? max(0, (int) zw_doc_gb9704_get('sign.wrap_min_chars', 0)) : 0;

        $chars = array();
        for ($i = 0; $i < $len; $i++) {
            $chars[] = function_exists('mb_substr') ? mb_substr($org, $i, 1, 'UTF-8') : $org[$i];
        }
        // fill：逐字装到超宽为止（末行可短）。
        $fill = array();
        $cur  = '';
        foreach ($chars as $ch) {
            if ($cur !== '' && zw_doc_sign_text_width_mm($cur . $ch, $pt) > $max) {
                $fill[] = $cur;
                $cur    = '';
            }
            $cur .= $ch;
        }
        if ($cur !== '') {
            $fill[] = $cur;
        }

        // 行数：按宽度估算取最小可行数，再按「字符数均分」切（CJK 等宽，均分即宽度均分）。
        $total = zw_doc_sign_text_width_mm($org, $pt);
        $n = max(2, (int) ceil($total / $max));
        $per = (int) ceil($len / $n);
        $lines = array();
        for ($i = 0; $i < $len; $i += $per) {
            $lines[] = function_exists('mb_substr') ? mb_substr($org, $i, $per, 'UTF-8') : substr($org, $i, $per);
        }
        if ($mode === 'fill' && !empty($fill)) {
            $lines = $fill;
        } elseif ($min_chars > 0) {
            $cap = max(1, (int) floor($len / $min_chars));
            if (count($lines) > $cap && count($fill) <= $cap && !empty($fill)) {
                $lines = $fill;   // 用填满版压缩行数，且仍不越界
            }
        }
        // 逐行复核：若某行仍超宽（全角/半角混排），加一行重算。
        foreach ($lines as $line) {
            if (zw_doc_sign_text_width_mm($line, $pt) > $max + 0.05 && $len > count($lines) + 1) {
                $n = min($len, $n + 1);
                $per = (int) ceil($len / $n);
                $lines = array();
                for ($i = 0; $i < $len; $i += $per) {
                    $lines[] = function_exists('mb_substr') ? mb_substr($org, $i, $per, 'UTF-8') : substr($org, $i, $per);
                }
                break;
            }
        }

        $min_violated = false;
        if ($min_chars > 0) {
            foreach ($lines as $ln) {
                $ln_len = function_exists('mb_strlen') ? mb_strlen($ln, 'UTF-8') : strlen($ln);
                if ($ln_len < $min_chars) {
                    $min_violated = true;
                    break;
                }
            }
        }

        return array(
            'lines' => $lines,
            'wrapped' => count($lines) > 1,
            'max_w_mm' => round($max, 2),
            'mode' => $mode,
            'min_chars' => $min_chars,
            'min_violated' => $min_violated,
        );
    }
}

if (!function_exists('zw_doc_sign_org_overflow_mm')) {
    /**
     * 有印章落款：署名按「以成文日期为准居中」摆放时**超出版心右边界**的量（mm）。
     *
     * TASK-1995（生产基准 `中传函〔2026〕94号` = post 29755）：分支机构署名
     * 「中国传统文化促进会非物质文化遗产工作委员会」实测 **118.5mm**，居中后右缘 = 141.67 + 59.25
     * = **200.92mm > 版心右边 184mm** ⇒ 溢出 **16.92mm**（落款跑进右边距）。
     * 本函数给出该溢出量，渲染层据此把署名**左移到版心内**（仍尽量保持居中）。
     *
     * 计算（自版心左边界起算，mm）：
     *   版心右边 = `box_w`；成文日期右边 = `box_w − date_right_chars × em_mm`；
     *   日期宽 = 估算；日期中心 = 日期右边 − 日期宽/2；
     *   署名居中后右缘 = 日期中心 + 署名宽/2 ⇒ 溢出 = max(0, 右缘 − box_w)。
     *
     * @param string $org             发文机关署名。
     * @param string $date            成文日期。
     * @param float  $box_w_mm        版心宽（mm）。
     * @param int    $date_right_chars 成文日期右空字数（有印章口径，默认 4）。
     * @param float  $font_pt         落款字号（pt，默认 16 = 3 号）。
     * @return array{overflow_mm:float,org_w_mm:float,date_w_mm:float,center_mm:float}
     */
    function zw_doc_sign_org_overflow_mm($org, $date, $box_w_mm, $date_right_chars = 4, $font_pt = 16.0) {
        $org  = (string) $org;
        $date = (string) $date;
        $box  = (float) $box_w_mm;
        $pt   = (float) $font_pt;
        if ($org === '' || $box <= 0 || $pt <= 0) {
            return array('overflow_mm' => 0.0, 'org_w_mm' => 0.0, 'date_w_mm' => 0.0, 'center_mm' => 0.0);
        }
        $em_mm = $pt * 25.4 / 72;
        $org_w  = zw_doc_sign_text_width_mm($org, $pt);
        $date_w = zw_doc_sign_text_width_mm($date, $pt);
        $date_right = $box - max(0, (int) $date_right_chars) * $em_mm;
        $center = $date_right - $date_w / 2;
        $overflow = ($center + $org_w / 2) - $box;
        if ($overflow < 0) {
            $overflow = 0.0;
        }

        // 溢出时改「**右缘与成文日期右缘对齐**」：这比「贴版心右边界」更整齐（两行右端齐平、成组），
        // 且同样保证不越界。左移量 = 署名居中后的右缘 − 成文日期右缘。
        $shift_to_date = ($center + $org_w / 2) - $date_right;
        if ($shift_to_date < 0) {
            $shift_to_date = 0.0;
        }

        return array(
            'overflow_mm'         => round($overflow, 2),
            'org_w_mm'            => round($org_w, 2),
            'date_w_mm'           => round($date_w, 2),
            'center_mm'           => round($center, 2),
            'date_right_mm'       => round($date_right, 2),
            'shift_to_date_mm'    => round($shift_to_date, 2),
        );
    }
}

if (!function_exists('zw_doc_render_sign_html')) {
    /**
     * 落款（发文机关署名 + 成文日期 + 印章）。
     *
     * @param array $meta 正文 meta。
     * @param array $args 渲染参数。
     * @return string
     */
    function zw_doc_render_sign_html($meta, $args) {
        $sealed = !empty($args['seal_url']);
        if (!$sealed) {
            // —— 不加盖印章：TASK-1333 口径（署名右空二字 / 成文日期右空二字），DOM 与既有逐字一致 ——
            $html = '<div class="tco-doc-sign">';
            if (!empty($meta['sign_org'])) {
                $html .= '<p class="tco-doc-sign-org">' . esc_html((string) $meta['sign_org']) . '</p>';
            }
            if (!empty($meta['date'])) {
                $html .= '<p class="tco-doc-sign-date">' . esc_html((string) $meta['date']) . '</p>';
            }
            if (!empty($meta['note'])) {
                $html .= '<p class="tco-doc-note">（' . esc_html((string) $meta['note']) . '）</p>';
            }

            return $html . '</div>';
        }

        // —— 加盖印章：GB/T 9704-2012 §7.3.5.1（TASK-1985 重排） ——
        // 参照盒 `.tco-doc-sign-ref` 以**成文日期**为宽度基准（署名绝对定位在其上方并水平居中）
        // ⇒ 成文日期右空四字、署名以成文日期为准居中，两者同时精确成立。
        // 章径取**该印章的真实直径**（`seal_diameter_mm`，未登记回退常量），几何量由唯一实现
        // `zw_doc_gb9704_seal_geometry()` 给出，写成**元素内联样式**（静态样式块只留默认值）。
        $d_mm   = isset($args['seal_diameter_mm']) ? (float) $args['seal_diameter_mm'] : 0.0;
        $geom   = function_exists('zw_doc_gb9704_seal_geometry')
            ? zw_doc_gb9704_seal_geometry($d_mm)
            : array('diameter_mm' => 42.0, 'top_mm' => 25.0, 'gap_lines' => 2, 'line_pt' => 28.0);
        $sign_style = ' style="margin-top:' . ((float) $geom['gap_lines'] * (float) $geom['line_pt']) . 'pt;'
            . 'padding-bottom:' . (float) (isset($geom['reserve_below_mm']) ? $geom['reserve_below_mm'] : 0) . 'mm"';
        // TASK-2000（用户复测「落款 日期和单位又没居中对齐」）：**取消左移钳制，改为署名回行**。
        // 依据 GB §7.3.5.1「署名回行时与上一行居中」：署名一行放不下时**回行**，各行同一中轴居中，
        // 既保住「日期与单位居中对齐」，又不越出版心（TASK-1995 的左移虽保边界，但破坏居中 ⇒ 已废）。
        // 单行可用宽度 = 2 ×（版心右 − 成文日期中心）：以日期中心为中轴、左右等宽。
        $org_lines   = array();
        $org_wrapped = false;
        $org_max_w   = 0.0;
        if (!empty($meta['sign_org']) && function_exists('zw_doc_sign_org_lines')) {
            $sign_box = function_exists('zw_doc_gb9704_get') ? (float) zw_doc_gb9704_get('header.letterhead_width_mm', 156.0) : 156.0;
            $sign_pt  = function_exists('zw_doc_gb9704_get') ? (float) zw_doc_gb9704_get('text.pt', 16.0) : 16.0;
            $sign_indents_c = function_exists('zw_doc_gb9704_sign_indents')
                ? zw_doc_gb9704_sign_indents(true)
                : array('org_chars' => 2, 'date_chars' => 4);
            $em_mm    = $sign_pt * 25.4 / 72;
            $date_w   = zw_doc_sign_text_width_mm(isset($meta['date']) ? (string) $meta['date'] : '', $sign_pt);
            $date_right = $sign_box - (int) $sign_indents_c['date_chars'] * $em_mm;
            $date_cx    = $date_right - $date_w / 2;
            // 安全余量 0.5mm：宽度为估算（全角 1em / 半角 0.5em），实际字体步进略有差异。
            $org_max_w  = max(10.0, 2 * ($sign_box - $date_cx) - 0.5);
            $wrap = zw_doc_sign_org_lines((string) $meta['sign_org'], $org_max_w, $sign_pt);
            $org_lines   = (array) $wrap['lines'];
            $org_wrapped = !empty($wrap['wrapped']);
        }
        // TASK-2009：对齐口径（谁是中轴）。默认 center_on_date = 国标「署名以成文日期为准居中」。
        $org_align = function_exists('zw_doc_gb9704_get')
            ? (string) zw_doc_gb9704_get('sign.org_align', 'center_on_date')
            : 'center_on_date';
        if (!in_array($org_align, array('center_on_date', 'center_on_org', 'right_align'), true)) {
            $org_align = 'center_on_date';
        }
        // TASK-2009：对齐口径决定「中轴与单行可用宽度」——
        //   center_on_date（默认/国标）：中轴 = 日期中心，可用宽 = 2×(版心右 − 日期中心)；
        //   center_on_org：中轴 = 版心中心（署名整块居中），日期随之居中；
        //   right_align：不换行居中，改为两行右端对齐（长署名兜底观感）。
        if ($org_align !== 'center_on_date' && function_exists('zw_doc_sign_text_width_mm')) {
            $sign_box = function_exists('zw_doc_gb9704_get') ? (float) zw_doc_gb9704_get('header.letterhead_width_mm', 156.0) : 156.0;
            $sign_pt  = function_exists('zw_doc_gb9704_get') ? (float) zw_doc_gb9704_get('text.pt', 16.0) : 16.0;
            $use_max_w = ($org_align === 'center_on_org')
                ? max(10.0, $sign_box - 2.0)
                : $org_max_w;
            $wrap2 = zw_doc_sign_org_lines((string) $meta['sign_org'], $use_max_w, $sign_pt);
            $org_lines   = (array) $wrap2['lines'];
            $org_wrapped = !empty($wrap2['wrapped']);
            $org_max_w   = $use_max_w;
        }
        $org_line_count = max(1, count($org_lines));
        if ($org_line_count > 1) {
            // 署名回行 ⇒ 落款上方要预留 N 行（否则绝对定位的署名会压到正文末行）。
            $sign_style = ' style="margin-top:' . ((float) $geom['gap_lines'] * (float) $geom['line_pt']) . 'pt;'
                . 'padding-top:' . ($org_line_count * (float) $geom['line_pt']) . 'pt;'
                . 'padding-bottom:' . (float) (isset($geom['reserve_below_mm']) ? $geom['reserve_below_mm'] : 0) . 'mm"';
        }
        $org_style = '';

        $html = '<div class="tco-doc-sign is-sealed' . ($org_wrapped ? ' is-wrapped' : '')
            . ' is-align-' . esc_attr($org_align) . '"' . $sign_style . '>';
        $html .= '<div class="tco-doc-sign-ref">';
        if (!empty($meta['sign_org'])) {
            $org_text = $org_wrapped
                ? implode('<br>', array_map('esc_html', $org_lines))
                : esc_html((string) $meta['sign_org']);
            if ($org_wrapped) {
                // 绝对定位 + `left:50%` 时，只给 max-width 会按「剩余可用宽度」收缩（实测只容 1 字/行 ⇒ 竖排堆叠）。
                // 必须给**确定宽度**：盒宽 = 可用宽度、`translateX(-50%)` 居中，行内 `text-align:center`
                // ⇒ 各行仍以日期中心为中轴居中，且宽度确定后换行行为可预期。
                $org_style = ' style="width:' . round($org_max_w, 2) . 'mm"';
            }
            $html .= '<p class="tco-doc-sign-org"' . $org_style . '>' . $org_text . '</p>';
        }
        if (!empty($meta['date'])) {
            $html .= '<p class="tco-doc-sign-date">' . esc_html((string) $meta['date']) . '</p>';
        }
        // 印章必须在落款容器内（下压署名与日期），不随整页底部定位
        $html .= zw_doc_render_seal_html($args);
        $html .= '</div>';
        if (!empty($meta['note'])) {
            // 附注仍在成文日期下一行、居左空二字（不进入参照盒，故不受「以日期为准」约束）
            $html .= '<p class="tco-doc-note">（' . esc_html((string) $meta['note']) . '）</p>';
        }
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('zw_doc_render_seal_html')) {
    /**
     * 印章层（绝对定位，下压署名与成文日期）。
     *
     * @param array $args 渲染参数。
     * @return string
     */
    function zw_doc_render_seal_html($args) {
        if (empty($args['seal_url'])) {
            return '';
        }
        // TASK-1985：**真实章径**写成元素内联样式（静态样式块只有兼容常量默认值）。
        // `seal_diameter_mm` 为 0 时不写内联 → 与静态块逐字一致（存量零变化）。
        $d_mm  = isset($args['seal_diameter_mm']) ? (float) $args['seal_diameter_mm'] : 0.0;
        $style = '';
        if ($d_mm > 0) {
            $geom = function_exists('zw_doc_gb9704_seal_geometry') ? zw_doc_gb9704_seal_geometry($d_mm) : null;
            if (is_array($geom)) {
                // TASK-1993：`top_mm` 已是「带符号的下移量」（'over' 为负、'below' 为正），直接落值，不再补负号。
                $style = ' style="width:' . (float) $geom['diameter_mm'] . 'mm;top:' . (float) $geom['top_mm'] . 'mm"';
            }
        }
        // 低清提示不落在版面上（会压住日期），统一走 `zw_doc_render_notice_html()`。
        // 图片 **必须** 走 `zw_doc_render_img_src()`：`esc_url()` 会清空 data-URI（TASK-1985 根因）。
        return '<div class="tco-doc-seal"' . $style . '><img src="' . zw_doc_render_img_src((string) $args['seal_url']) . '" alt="印章"></div>';
    }

    if (!function_exists('zw_doc_render_seal_is_low_dpi')) {
        /**
         * 印章有效 dpi 是否低于阈值（提示用）。
         *
         * @param array $args 渲染参数。
         * @return bool
         */
        function zw_doc_render_seal_is_low_dpi($args) {
            $dpi = isset($args['seal_dpi']) ? (int) $args['seal_dpi'] : 0;
            if ($dpi <= 0) {
                return false;
            }

            return $dpi < (int) zw_doc_gb9704_get('seal.low_dpi_threshold', 300);
        }
    }
}

if (!function_exists('zw_doc_render_record_html')) {
    /**
     * 版记（首条粗线 → 抄送 → 中间细线 → 印发机关 / 印发日期 → 末条粗线）。
     *
     * TASK-1338 批次二：① 三条分隔线（首/末粗 0.35mm、中间细 0.25mm）；
     * ② 抄送末尾标句号（经抄送文本唯一实现，已有句号不重复补、空抄送不补）；
     * ③ 印发机关左空一字、印发日期右空一字（同一行两端分置）；
     * ⑤ 整块带撑底容器，锚定最后一面版心**下边缘**；
     * ⑦ D1 版记合并：印发机关 / 印发日期未显式保存时取公文要素的发文部门 / 发文日期。
     *
     * @param array $meta 正文 meta。
     * @param array $args 渲染参数（可含 doc_department / doc_issue_date）。
     * @return string
     */
    function zw_doc_render_record_html($meta, $args = array()) {
        $args       = is_array($args) ? $args : array();
        // TASK-2002：`cc` 允许数组（多抄送机关）—— 直接 `(string)` 强转会渲染成字面量「Array」
        // （实测设计器预览页出现「抄送：Array」）。数组一律以「、」连接，保持版记一行。
        $cc_raw     = isset($meta['cc']) ? $meta['cc'] : '';
        if (is_array($cc_raw)) {
            $cc_raw = implode('、', array_map('strval', $cc_raw));
        }
        $cc         = (string) $cc_raw;
        $department = isset($args['doc_department']) ? (string) $args['doc_department'] : '';
        $issue_date = isset($args['doc_issue_date']) ? (string) $args['doc_issue_date'] : '';

        // ⑦ D1：印发机关 / 印发日期 = 公文要素（发文部门 / 发文日期）；存量显式值不覆盖。
        if (function_exists('zw_doc_record_print_fields')) {
            $print = zw_doc_record_print_fields($meta, $department, $issue_date);
        } else {
            $print = array(
                'print_org'  => isset($meta['print_org']) ? (string) $meta['print_org'] : '',
                'print_date' => isset($meta['print_date']) ? (string) $meta['print_date'] : '',
            );
        }
        $print_org  = (string) $print['print_org'];
        $print_date = (string) $print['print_date'];

        // ②：抄送文本（末尾标句号）经唯一实现取值。
        $cc_text = function_exists('zw_doc_gb9704_record_cc_text')
            ? (string) zw_doc_gb9704_record_cc_text($cc)
            : trim((string) $cc);

        if ($cc_text === '' && $print_org === '' && $print_date === '') {
            return '';
        }

        // ① 分隔线条数与线型取唯一实现（两通道共用）：
        //    两行要素 ⇒ 粗 / 细 / 粗；只有一行要素 ⇒ 粗 / 粗（旧实现在此多出一条无内容的中间细线）。
        $has_cc    = ($cc_text !== '');
        $has_print = ($print_org !== '' || $print_date !== '');
        $rule_plan = function_exists('zw_doc_gb9704_record_rule_plan')
            ? zw_doc_gb9704_record_rule_plan($has_cc, $has_print)
            : array('thick', ($has_cc && $has_print) ? 'thin' : 'thick', 'thick');

        // ② 抄送行（文本末尾句号已在上面经唯一实现取得）。
        $rows = array();
        if ($has_cc) {
            $rows[] = '<div class="tco-doc-record"><p>抄送：' . esc_html($cc_text) . '</p></div>';
        }
        if ($has_print) {
            // ③ 印发机关左空一字（容器左内边距）、印发日期右空一字（容器右内边距 + 两端分置）。
            $rows[] = '<div class="tco-doc-record"><p class="tco-doc-record-print">'
                    . '<span class="tco-doc-record-org">' . esc_html($print_org) . '</span>'
                    . '<span class="tco-doc-record-date">' . esc_html($print_date) . '印发</span>'
                    . '</p></div>';
        }

        // TASK-2024（用户「调整 抄送 和印发 是否在 横线内 如何调整？」）：
        //   enclose    —— 首尾粗线把抄送与印发**都括在里面**（GB §7.4 默认）
        //   print_only —— **抄送行在横线之上**，只把印发行括进横线
        //   none       —— 不画横线
        $rules_scope = (string) zw_doc_gb9704_get('record.rules_scope', 'enclose');
        if (!in_array($rules_scope, array('enclose', 'print_only', 'none'), true)) {
            $rules_scope = 'enclose';
        }

        $html = '<div class="tco-doc-record-block">';
        if ($rules_scope === 'print_only' && $has_cc && $has_print) {
            // 抄送提到横线之外（在首条粗线之前）。
            $html .= '<div class="tco-doc-record is-outside-rules"><p>抄送：' . esc_html($cc_text) . '</p></div>';
            $html .= '<div class="tco-doc-record-rule"></div>';
            $html .= '<div class="tco-doc-record"><p class="tco-doc-record-print">'
                . '<span class="tco-doc-record-org">' . esc_html($print_org) . '</span>'
                . '<span class="tco-doc-record-date">' . esc_html($print_date) . '印发</span></p></div>';
            $html .= '<div class="tco-doc-record-rule"></div>';
        } elseif ($rules_scope === 'none') {
            foreach ($rows as $row) {
                $html .= $row;
            }
        } else {
            // 首条恒为粗线（plan[0]）。
            $html .= '<div class="tco-doc-record-rule"></div>';
            foreach ($rows as $ri => $row) {
                $html .= $row;
                $next  = isset($rule_plan[$ri + 1]) ? (string) $rule_plan[$ri + 1] : '';
                if ($next === 'thin') {
                    $html .= '<div class="tco-doc-record-rule is-thin"></div>';
                } elseif ($next === 'thick') {
                    $html .= '<div class="tco-doc-record-rule"></div>';
                }
            }
        }
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('zw_doc_render_pageno_html')) {
    /**
     * 页码（三档模式）。
     *
     * @param int    $page_no 页号（从 1 开始）。
     * @param string $mode    页码模式。
     * @return string
     */
    function zw_doc_render_pageno_html($page_no, $mode) {
        $mode = in_array($mode, array('standard', 'center', 'none'), true) ? $mode : 'standard';
        if ($mode === 'none') {
            return '';
        }
        $dash  = (string) zw_doc_gb9704_get('footer.dash', '—');
        $label = $dash . ' ' . (int) $page_no . ' ' . $dash;
        if ($mode === 'center') {
            return '<div class="tco-doc-pageno is-center">' . esc_html($label) . '</div>';
        }
        $is_odd = ((int) $page_no % 2) === 1;

        return '<div class="tco-doc-pageno ' . ($is_odd ? 'is-odd' : 'is-even') . '">' . esc_html($label) . '</div>';
    }
}

if (!function_exists('zw_doc_render_document_html')) {
    /**
     * 完整文档 HTML（多页 A4）。
     *
     * @param array $body 正文 AST。
     * @param array $args 渲染参数。
     * @return string
     */
    function zw_doc_render_document_html($body, $args = array()) {
        $args   = array_merge(zw_doc_render_defaults(), is_array($args) ? $args : array());
        $meta   = isset($body['meta']) && is_array($body['meta']) ? $body['meta'] : array();
        $blocks = isset($body['blocks']) && is_array($body['blocks']) ? array_values($body['blocks']) : array();
        $pages  = zw_doc_render_paginate($body, $args);
        $mode   = isset($meta['page_mode']) ? (string) $meta['page_mode'] : 'standard';

        $html = '';
        foreach ($pages as $page_idx => $indexes) {
            $html .= '<div class="tco-doc-sheet" data-zw-page="' . (int) ($page_idx + 1) . '">';
            $html .= '<div class="tco-doc-flow">';
            if ($page_idx === 0) {
                $html .= zw_doc_render_letterhead_html($meta, $args);
                if (!empty($meta['title'])) {
                    // TASK-1342（§7.3.1）：多行标题按**梯形/菱形**回行；单行时与旧实现**逐字节一致**（`<br>` 不出现）。
                    // TASK-2014：作者**手动折行**优先（`meta.title_lines`，≤3 行），否则再走自动回行。
                    $title_lines_1342 = zw_doc_title_resolve_lines(
                        (string) $meta['title'],
                        isset($meta['title_lines']) && is_array($meta['title_lines']) ? $meta['title_lines'] : array()
                    );
                    $html .= '<h1 class="tco-doc-title">'
                          . implode('<br>', array_map('esc_html', $title_lines_1342))
                          . '</h1>';
                }
                if (!empty($meta['receiver'])) {
                    $html .= '<p class="tco-doc-receiver">' . esc_html((string) $meta['receiver']) . '</p>';
                }
            }
            $html .= '<div class="tco-doc-body">';
            $html .= zw_doc_render_blocks_html($blocks, $indexes);
            if ($page_idx === count($pages) - 1) {
                // TASK-1333 ②：附件说明**移到落款之前**（正文下空一行），
                // 纸面顺序固定为 正文 → 空一行 → 附件说明 → 空一行 → 署名 → 成文日期 → 附注 → 版记。
                if (!empty($meta['attach_note']) && is_array($meta['attach_note'])) {
                    foreach ($meta['attach_note'] as $note) {
                        $html .= '<p class="tco-doc-attach">' . esc_html((string) $note) . '</p>';
                    }
                }
                $html .= zw_doc_render_sign_html($meta, $args);
            }
            $html .= '</div>';
            if ($page_idx === count($pages) - 1) {
                $html .= zw_doc_render_record_html($meta, $args);
            }
            $html .= '</div>';
            // TASK-1340 阶段四（用户口径）：「页码 做个自动判断，如果是只有一页 默认选择 不显示」
            // ⇒ **按实际页数自动判断**：总页数为 1 时不编页码（单页件的页码没有导航价值；
            // 国标 §7.5 亦对「版记页与版记页前空白页」另有不编页码的规定，本项是更强的自动收敛）。
            // 多页时仍按 `$mode`（standard 单右双左 / center 居中 / none 不显示）照常编排；
            // 显式选 none 时本来返回空串，两条规则不冲突。判断依据是**分页结果本身的页数**，不需要用户先选。
            $html .= (count($pages) > 1 ? zw_doc_render_pageno_html($page_idx + 1, $mode) : '');
            $html .= '</div>';
        }
        return $html;
    }
}

if (!function_exists('zw_doc_render_notice_html')) {
    /**
     * 渲染页提示（字体来源 / 分页为估算 / 非电子签名）。
     *
     * 字体声明文案唯一出处：`zw_doc_gb9704_disclaimer('font')`（TASK-1863 起为「已授权国标字体，服务器渲染」）。
     *
     * @param array $args 渲染参数。
     * @return string
     */
    function zw_doc_render_notice_html($args = array()) {
        $args  = array_merge(zw_doc_render_defaults(), is_array($args) ? $args : array());
        $lines = array();
        $lines[] = zw_doc_gb9704_disclaimer('font');
        $lines[] = '分页为按「每面 ' . (int) zw_doc_gb9704_get('grid.lines_per_page', 22) . ' 行、每行 ' . (int) zw_doc_gb9704_get('grid.chars_per_line', 28) . ' 字」的排版估算，最终分页与字距以浏览器打印或 PDF 引擎为准。';
        // 通道 A 是**未加密**的排版预览/打印稿，因此这里不得引用通道 C 的 AES-256 加密声明
        // （加密声明唯一出处仍是 SPEC-87 的 `disclaimer.encrypt`，仅由加密 PDF 通道使用）。
        $lines[] = '本页为公文排版预览 / 打印稿（未加密），不是《电子签名法》意义上的可靠电子签名；正式用印件由系统单独生成 AES-256 加密 PDF。';
        $lines[] = '若印章素材为白底不透明，压章会遮挡落款文字与成文日期，请改用透明底 PNG。';
        if (zw_doc_render_seal_is_low_dpi($args)) {
            $lines[] = zw_doc_gb9704_disclaimer('seal_low_dpi') . '：当前印章素材有效分辨率仅 ' . (int) $args['seal_dpi'] . 'dpi（低于 ' . (int) zw_doc_gb9704_get('seal.low_dpi_threshold', 300) . 'dpi），打印边缘可能发虚，建议更换高清原图。';
        }

        return '<div class="tco-doc-notice">' . implode('<br>', array_map('esc_html', $lines)) . '</div>';
    }
}

if (!function_exists('zw_doc_render_standalone_html')) {
    /**
     * 独立可打印 HTML 文档（用于 admin-post 打印视图，无后台 chrome）。
     *
     * @param array $body 正文 AST。
     * @param array $args 渲染参数。
     * @return string
     */
    function zw_doc_render_standalone_html($body, $args = array()) {
        $args  = array_merge(zw_doc_render_defaults(), is_array($args) ? $args : array());
        $title = (string) $args['document_title'];

        $head  = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
               . '<meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<meta name="robots" content="noindex,nofollow">'
               . '<title>' . esc_html($title) . '</title>'
               . zw_doc_render_style_block((string) $args['base_url'])
               . '</head><body class="tco-doc-wrap">';

        $toolbar = '';
        if (!empty($args['show_toolbar'])) {
            $toolbar = '<div class="tco-doc-toolbar">'
                     . '<button type="button" class="button button-primary" onclick="window.print();">打印 / 另存为 PDF</button>'
                     . '<button type="button" class="button" onclick="window.close();">关闭</button>'
                     . '<span class="tco-doc-notice" style="margin:0;">如未自动弹出打印窗口，请点击左侧按钮。</span>'
                     . '</div>';
        }

        $script = '';
        if (!empty($args['auto_print'])) {
            $script = '<script>window.addEventListener("load",function(){setTimeout(function(){window.focus();window.print();},300);});</script>';
        }

        return $head . $toolbar . zw_doc_render_notice_html($args)
             . zw_doc_render_document_html($body, $args) . $script . '</body></html>';
    }
}

if (!function_exists('zw_doc_render_preview_html')) {
    /**
     * 后台详情页内的「版式预览」区块（按容器宽度缩放）。
     *
     * @param array $body 正文 AST。
     * @param array $args 渲染参数。
     * @param int   $scale 缩放百分比。
     * @return string
     */
    function zw_doc_render_preview_html($body, $args = array(), $scale = 62) {
        $scale = max(30, min(100, (int) $scale));
        $html  = '<div class="tco-doc-preview">';
        $html .= zw_doc_render_notice_html($args);
        // **必须以 mm 显式收外框**（TASK-780）：`transform:scale()` 只做视觉缩放，**不改变布局尺寸**。
        // 早期实现外层声明 `width:21000/scale mm`（scale=46 时约 457mm≈1728px），
        // 导致右栏布局宽度远超可视宽度、两栏相加超容器而换行（预览被挤到下一行）。
        // 现外层盒子 = 视觉尺寸（210×scale%），内层仍为 210mm 宽内容并按 origin 左上角缩小。
        $box_w = (int) round(210 * $scale / 100);
        $box_h = (int) round(297 * $scale / 100);
        $html .= '<div class="tco-doc-preview-box" style="width:' . $box_w . 'mm;height:' . $box_h . 'mm;">';
        $html .= '<div class="tco-doc-scaled" style="transform:scale(' . ($scale / 100) . ');transform-origin:top left;width:210mm;">';
        $html .= zw_doc_render_document_html($body, $args);
        $html .= '</div></div></div>';

        return $html;
    }
}

if (!function_exists('zw_doc_print_url')) {
    /**
     * 打印视图 URL（带 nonce）。
     *
     * @param int $document_id 发文 ID。
     * @return string
     */
    function zw_doc_print_url($document_id) {
        $url = add_query_arg(array(
            'action' => 'zw_doc_print',
            'doc_id' => (int) $document_id,
        ), admin_url('admin-post.php'));

        return wp_nonce_url($url, 'zw_doc_print_' . (int) $document_id);
    }
}

if (!function_exists('zw_doc_letterhead_render_args')) {
    /**
     * 红头渲染参数的**唯一构造器**（TASK-1422）——版式预览与三条出件通道（HTML 打印 / DOCX / 加密 PDF）共用。
     *
     * 生成方式的**唯一出处**是 `zw_doc_letterhead_mode()`，本函数只经
     * `zw_doc_letterhead_resolve()` 收口一次，**禁止任何通道自行读取该 option 或自行判定模式**：
     *   - `auto`（默认，系统自动生成红头）⇒ 不带图片键，调用方走**常量绘制**；
     *   - `image`（使用上传的红头文件）⇒ 带 `letterhead_url` / `letterhead_path` / `number_overlay`；
     *   - `image` 但**无可用已启用红头** ⇒ **fail-closed**：写 `letterhead_blocked`（可操作提示），
     *     清空图片键；**不静默回落常量绘制、不渲染空白红头**。
     *
     * @param array $doc  发文记录（可为空数组：撰写页「预览当前内容」未保存预览）。
     * @param array $args 渲染参数（就地补充红头相关键）。
     * @return array
     */
    function zw_doc_letterhead_render_args($doc, $args = array()) {
        $args = is_array($args) ? $args : array();
        if (!function_exists('zw_doc_letterhead_resolve')) {
            return $args;
        }

        // **全局开关是唯一权威**：先显式归零红头相关键（避免调用方依赖 `zw_doc_render_defaults()` 的合并，
        // 也避免任何通道把自己拼的红头值夹带进来 ⇒ 保证「预览 auto / 出件 image」这类分叉不可能出现）。
        $args['letterhead_url']        = '';
        $args['letterhead_alt']        = '';
        $args['letterhead_path']       = '';
        $args['letterhead_source']     = 'none';
        $args['letterhead_blocked']    = '';
        $args['letterhead_assets_url'] = '';
        $args['number_overlay']        = null;

        $resolved = zw_doc_letterhead_resolve(is_array($doc) ? $doc : array());

        if (!empty($resolved['blocked'])) {
            $args['letterhead_blocked']    = (string) $resolved['reason'];
            $args['letterhead_assets_url'] = (string) $resolved['assets_url'];

            return $args;
        }

        if (!empty($resolved['url']) || !empty($resolved['path'])) {
            $args['letterhead_url']    = (string) $resolved['url'];
            $args['letterhead_alt']    = (string) $resolved['name'];
            $args['letterhead_path']   = (string) $resolved['path'];
            $args['letterhead_source'] = (string) $resolved['source'];
            // 模板配置了文号叠加坐标时，文号叠加在图片内红线之上（符合 GB/T 9704 顺序）
            if (is_array($resolved['overlay'])) {
                $args['number_overlay'] = $resolved['overlay'];
            }
        }

        return $args;
    }
}

if (!function_exists('zw_doc_build_render_args')) {
    /**
     * 从发文记录构造渲染参数（WP 依赖部分集中在此）。
     *
     * @param array $doc  发文记录（`zw_doc_get_document()` 结果）。
     * @param array $args 覆盖项。
     * @return array
     */
    function zw_doc_build_render_args($doc, $args = array()) {
        $args = array_merge(zw_doc_render_defaults(), is_array($args) ? $args : array());

        if (empty($args['base_url'])) {
            $args['base_url'] = function_exists('get_template_directory_uri') ? get_template_directory_uri() : '';
        }

        if ($args['doc_number'] === '' && !empty($doc['number'])) {
            $args['doc_number'] = (string) $doc['number'];
        }

        // 红头（TASK-770 + TASK-1422）：**全局生成方式开关**与解析优先级（单篇覆盖 → 文种映射 → 全局默认）
        // 一律经 `zw_doc_letterhead_render_args()` 收口（内部即唯一解析入口 `zw_doc_letterhead_resolve()`）
        // ⇒ 版式预览与 HTML 打印/DOCX/加密 PDF **同源**，禁止任何通道各写一套。
        $args = zw_doc_letterhead_render_args($doc, $args);

        // 印章：素材存于 webroot 之外，**必须内联为 data-URI**（打印新窗口与 PDF 工人都没有会话 cookie）。
        // 正式用印件（seal_mode=formal）用发文绑定的印章；也支持经 `seal_preview_id` 注入的预览。
        $seal_id = '';
        $seal_pick = '';
        if (!empty($doc['seal_id']) && (string) (isset($doc['seal_mode']) ? $doc['seal_mode'] : '') === 'formal') {
            $seal_id = (string) $doc['seal_id'];
        } elseif (!empty($doc['seal_preview_id'])) {
            $seal_id = (string) $doc['seal_preview_id'];
        } elseif (function_exists('zw_doc_seal_match_id')) {
            // TASK-1995：**按发文机关匹配印章**（分支机构发文 → 该分支机构的章；无同名章则留空，
            // 由下方既有「默认印章回退」兜底 ⇒ 零回归）。
            $seal_id = (string) zw_doc_seal_match_id($doc);
        }
        if ($seal_id !== '' && function_exists('zw_doc_seal_data_uri')) {
            $uri = zw_doc_seal_data_uri($seal_id);
            if ($uri !== '') {
                $args['seal_url'] = $uri;
                $args['seal_dpi'] = function_exists('zw_doc_seal_effective_dpi') ? (int) zw_doc_seal_effective_dpi($seal_id) : 0;
                $seal_pick        = $seal_id;
            }
        } elseif (!empty($doc['seal_url'])) {
            $args['seal_url'] = (string) $doc['seal_url'];
            if (!empty($doc['seal_dpi'])) {
                $args['seal_dpi'] = (int) $doc['seal_dpi'];
            }
        }

        // TASK-1340 批次三（用户 2026-10-09 拍板「所有公文都盖章」）：
        // 上面两条只在「已正式用印」或「显式指定预览印章」时取章。现补**默认印章回退**——
        // 未指定印章时取「红头与印章」页设为默认的那一枚（`zw_doc_default_seal_id`），
        // 使**任何公文渲染都带章**，无需逐篇操作。显式指定者优先（前面两条已返回，不受影响）。
        if (empty($args['seal_url']) && function_exists('zw_doc_seal_data_uri')) {
            $default_seal_id = (string) get_option('zw_doc_default_seal_id', '');
            if ($default_seal_id === '' && function_exists('zw_doc_seal_registry')) {
                // 没设默认时取注册表里**第一个启用的**印章（用户口径是"所有公文都盖章"，不应因漏设默认而无章）。
                foreach ((array) zw_doc_seal_registry() as $sid => $row) {
                    if (!empty($row['enabled'])) {
                        $default_seal_id = (string) $sid;
                        break;
                    }
                }
            }
            if ($default_seal_id !== '') {
                $uri = zw_doc_seal_data_uri($default_seal_id);
                if ($uri !== '') {
                    $args['seal_url'] = $uri;
                    $args['seal_dpi'] = function_exists('zw_doc_seal_effective_dpi') ? (int) zw_doc_seal_effective_dpi($default_seal_id) : 0;
                    $seal_pick        = $default_seal_id;
                }
            }
        }

        // TASK-1985：把**该印章的真实直径**下发给渲染层（用户口径「真实印章是 4cm 直径」）。
        // 唯一取值入口 `zw_doc_seal_diameter_mm()`（逐章登记 → 兼容常量回退），
        // 渲染层据此写 CSS `width:Nmm` 并按 `zw_doc_gb9704_seal_geometry()` 定位。
        if ($seal_pick !== '' && function_exists('zw_doc_seal_diameter_mm')) {
            $args['seal_diameter_mm'] = (float) zw_doc_seal_diameter_mm($seal_pick);
        }

        // TASK-1338 批次二 ⑦（D1 版记合并）：把公文要素的真值源（发文部门 / 发文日期）随渲染参数下发，
        // 供版记在「未显式保存印发值」时回落取值（存量公文已有显式值时由取值函数优先返回存量值）。
        if (!empty($doc['department'])) {
            $args['doc_department'] = (string) $doc['department'];
        }
        if (!empty($doc['issue_date'])) {
            $args['doc_issue_date'] = (string) $doc['issue_date'];
        }

        return $args;
    }
}

if (!function_exists('zw_doc_handle_print')) {
    /**
     * 打印视图（admin-post，无后台 chrome）。
     *
     * 鉴权：功能开关 + `manage_options` + nonce（沿用发文模块既有口径；
     * 权限收敛在 TASK-771 统一处理，本任务不擅自扩大或收紧）。
     *
     * @return void
     */
    function zw_doc_handle_print() {
        $doc_id = isset($_GET['doc_id']) ? (int) $_GET['doc_id'] : 0;

        if (!function_exists('zw_doc_is_enabled') || !zw_doc_is_enabled()) {
            wp_die('发文管理未启用。', '不可用', array('response' => 403));
        }
        check_admin_referer('zw_doc_print_' . $doc_id);

        $doc = function_exists('zw_doc_get_document') ? zw_doc_get_document($doc_id) : null;
        if (!$doc) {
            wp_die('发文不存在。', '未找到', array('response' => 404));
        }
        // 权限收敛（TASK-772）：管理员 / 作者 / 审核人 / 抄送人可查看；仅靠 manage_options 会挡住本该可见的属主。
        if (function_exists('zw_doc_can_view_doc') && !zw_doc_can_view_doc($doc)) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        if (empty($doc['body'])) {
            wp_die('该发文没有在线撰写的正文，无法生成版式预览。', '无正文', array('response' => 404));
        }
        // TASK-1422 **fail-closed**：切到「使用上传的红头文件（图片）」却无可用已启用红头时，
        // 打印/另存为 PDF 通道直接拒出（409 + 可操作提示），**不输出「静默回落」或空白红头的成品**。
        // 版式预览（详情页内嵌 / 撰写页预览端点）则渲染明确提示块，便于就地发现并修复。
        $lh_gate = function_exists('zw_doc_letterhead_mode_gate') ? zw_doc_letterhead_mode_gate() : true;
        if (is_wp_error($lh_gate)) {
            wp_die(esc_html($lh_gate->get_error_message()), '红头素材缺失', array('response' => 409));
        }

        $args = zw_doc_build_render_args($doc, array(
            'show_toolbar'   => true,
            'auto_print'     => true,
            'document_title' => (string) $doc['title'] . ' · 打印预览',
        ));

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        echo zw_doc_render_standalone_html($doc['body'], $args); // phpcs:ignore WordPress.Security.EscapeOutput -- 渲染器内部逐字段转义
        exit;
    }
}
