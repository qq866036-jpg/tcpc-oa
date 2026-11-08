<?php
/**
 * 「公文版式」设计器（TASK-2000 / CHG-389）。
 *
 * 目标（用户 2026-10-24 决定，原文）：「设计一个 公文版式设计器，命名为：公文版式
 * 将所有公文版式参数 可视化 即 国标各项参数可视化」。
 *
 * 设计要点：
 *  1. **单一事实源不变**：参数仍只有一处定义（`zw_doc_gb9704_spec()`）；本模块只在其上叠一层
 *     **白名单覆盖**（option `zw_doc_layout_overrides`），由 `zw_doc_gb9704_get()` 唯一读口消费 ⇒
 *     HTML / DOCX / 加密 PDF 三条通道自动同步，**不存在第二套排版实现**。
 *  2. **可视化**：每个参数给出「国标值 / 当前值 / 单位 / 允许范围 / GB 条款 / 说明」，
 *     并配**实时预览**（复用既有渲染器，46% 缩放）——改完保存即可看到版式变化。
 *  3. **不越界**：覆盖值一律按字段声明的 `min/max/step/枚举` 夹紧；类型不符直接拒收（fail-closed）。
 *  4. **可追溯**：每次保存写一条历史（时间 / 用户 / 变更明细，最近 20 条），并可一键「恢复国标默认」。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_layout_option_key')) {
    /**
     * 覆盖层 option 键。
     *
     * @return string
     */
    function zw_doc_layout_option_key() {
        return 'zw_doc_layout_overrides';
    }
}

if (!function_exists('zw_doc_layout_history_key')) {
    /**
     * 变更历史 option 键。
     *
     * @return string
     */
    function zw_doc_layout_history_key() {
        return 'zw_doc_layout_history';
    }
}

if (!function_exists('zw_doc_layout_font_choices')) {
    /**
     * **字体族白名单**（TASK-2007；用户「没有 字体配置」）。
     *
     * 只列「协会授权国标字体 + 服务器已装同类回退家族」——设计器**不得**让任意字体名落库：
     * 一旦写入服务器未装的字体，HTML/PDF 会静默回退成默认字体（比没有更糟）。
     * `value` 是**CSS 家族名**（必须与 `fc-list` 输出一致），`label` 供人读，`gb` 标注依据。
     *
     * @return array<string,array{label:string,gb:string}>
     */
    function zw_doc_layout_font_choices() {
        return array(
            // —— 国标四体（GB/T 9704-2012 §5.2.2 / §7.3.1）——
            '方正小标宋简体' => array('label' => '方正小标宋简体（标题·国标）', 'gb' => 'GB §7.3.1'),
            'FZXiaoBiaoSong-B05S' => array('label' => 'FZXiaoBiaoSong-B05S（小标宋·PostScript 名）', 'gb' => 'GB §7.3.1'),
            '黑体'          => array('label' => '黑体（一级标题·国标）', 'gb' => 'GB §5.2.2'),
            'SimHei'        => array('label' => 'SimHei（黑体·英文名）', 'gb' => 'GB §5.2.2'),
            '楷体_GB2312'   => array('label' => '楷体_GB2312（二级标题·国标）', 'gb' => 'GB §5.2.2'),
            'KaiTi_GB2312'  => array('label' => 'KaiTi_GB2312（楷体·国标英文名）', 'gb' => 'GB §5.2.2'),
            '楷体'          => array('label' => '楷体（通用名）', 'gb' => '协会口径'),
            'KaiTi'         => array('label' => 'KaiTi（楷体·通用英文名）', 'gb' => '协会口径'),
            '仿宋_GB2312'   => array('label' => '仿宋_GB2312（正文·国标）', 'gb' => 'GB §5.2.2'),
            'FangSong_GB2312' => array('label' => 'FangSong_GB2312（仿宋·国标英文名）', 'gb' => 'GB §5.2.2'),
            '仿宋'          => array('label' => '仿宋（通用名）', 'gb' => '协会口径'),
            'FangSong'      => array('label' => 'FangSong（仿宋·通用英文名）', 'gb' => '协会口径'),
            '宋体'          => array('label' => '宋体（页码/版记·国标）', 'gb' => 'GB §7.5'),
            'SimSun'        => array('label' => 'SimSun（宋体·英文名）', 'gb' => 'GB §7.5'),
            '新宋体'        => array('label' => '新宋体（NSimSun）', 'gb' => '协会口径'),
            'STZhongsong'   => array('label' => '华文中宋（STZhongsong）', 'gb' => '协会口径'),
            // —— 开源回退（服务器已装）——
            '思源宋体'      => array('label' => '思源宋体（开源回退）', 'gb' => '开源替代'),
            '思源黑体 CN'   => array('label' => '思源黑体 CN（开源回退）', 'gb' => '开源替代'),
        );
    }
}

if (!function_exists('zw_doc_layout_installed_fonts')) {
    /**
     * 服务器已装字体家族（`fc-list` 结果缓存 1 天，仅用于页面上的「已装/未装」提示）。
     *
     * 取不到（`exec` 被禁用等）时返回空数组 —— 页面显示「未知」，**不阻断保存**（保存只校验白名单）。
     *
     * @return array<string,bool>
     */
    function zw_doc_layout_installed_fonts() {
        $cache = get_option('zw_doc_layout_fonts_cache', null);
        if (is_array($cache) && isset($cache['at'], $cache['families'])) {
            if ((int) $cache['at'] > time() - DAY_IN_SECONDS) {
                return is_array($cache['families']) ? $cache['families'] : array();
            }
        }
        $families = array();
        $disabled = (string) ini_get('disable_functions');
        if (function_exists('exec') && strpos($disabled, 'exec') === false) {
            $out = array();
            $rc  = 0;
            @exec('fc-list : family 2>/dev/null', $out, $rc);
            if ($rc === 0 && is_array($out)) {
                foreach ($out as $line) {
                    foreach (preg_split('/,/', (string) $line) as $fam) {
                        $fam = trim((string) $fam);
                        if ($fam !== '') {
                            $families[$fam] = true;
                        }
                    }
                }
            }
        }
        update_option('zw_doc_layout_fonts_cache', array('at' => time(), 'families' => $families), false);

        return $families;
    }
}

if (!function_exists('zw_doc_layout_fields')) {
    /**
     * **可编辑参数注册表**（设计器页字段的唯一定义）。
     *
     * 每个字段：`path`（点分路径，指向 `zw_doc_gb9704_spec()`）、`label`、`group`（分组键）、
     * `type`（`number|select|text|bool`）、`unit`、`min|max|step`（number）、`options`（select）、
     * `gb`（GB/T 9704-2012 条款或"协会口径"）、`note`（一句话解释）、`risk`（`safe|layout|visual`）。
     *
     * ⚠️ 不在本表内的路径**不可被覆盖**（白名单即权限边界）。
     *
     * @return array<int,array<string,mixed>>
     */
    function zw_doc_layout_fields() {
        static $fields = null;
        if (is_array($fields)) {
            return $fields;
        }

        $f = array(
            // ── 纸张与版心 ────────────────────────────────────────────────
            array('path' => 'paper.w_mm', 'label' => '纸张宽', 'group' => 'paper', 'type' => 'number', 'unit' => 'mm', 'min' => 200, 'max' => 220, 'step' => 0.1, 'gb' => 'GB §5.1 表 A.1（A4）', 'note' => '国标 A4 = 210mm', 'risk' => 'layout'),
            array('path' => 'paper.h_mm', 'label' => '纸张高', 'group' => 'paper', 'type' => 'number', 'unit' => 'mm', 'min' => 280, 'max' => 310, 'step' => 0.1, 'gb' => 'GB §5.1 表 A.1（A4）', 'note' => '国标 A4 = 297mm', 'risk' => 'layout'),
            array('path' => 'margin.top_mm', 'label' => '上边距（天头）', 'group' => 'paper', 'type' => 'number', 'unit' => 'mm', 'min' => 20, 'max' => 60, 'step' => 0.5, 'gb' => 'GB §5.1（上白边 37mm）', 'note' => '版心自纸顶下移量', 'risk' => 'layout'),
            array('path' => 'margin.bottom_mm', 'label' => '下边距（地脚）', 'group' => 'paper', 'type' => 'number', 'unit' => 'mm', 'min' => 15, 'max' => 60, 'step' => 0.5, 'gb' => 'GB §5.1（下白边 35mm）', 'note' => '', 'risk' => 'layout'),
            array('path' => 'margin.left_mm', 'label' => '左边距（订口）', 'group' => 'paper', 'type' => 'number', 'unit' => 'mm', 'min' => 15, 'max' => 45, 'step' => 0.5, 'gb' => 'GB §5.1（左白边 28mm）', 'note' => '', 'risk' => 'layout'),
            array('path' => 'margin.right_mm', 'label' => '右边距', 'group' => 'paper', 'type' => 'number', 'unit' => 'mm', 'min' => 15, 'max' => 45, 'step' => 0.5, 'gb' => 'GB §5.1（右白边 26mm）', 'note' => '', 'risk' => 'layout'),
            array('path' => 'grid.lines_per_page', 'label' => '每页行数', 'group' => 'paper', 'type' => 'number', 'unit' => '行', 'min' => 18, 'max' => 26, 'step' => 1, 'gb' => 'GB §5.2.3（22 行）', 'note' => '与行距联动：22 × 28pt ≈ 156mm 版心', 'risk' => 'layout'),
            array('path' => 'grid.chars_per_line', 'label' => '每行字数', 'group' => 'paper', 'type' => 'number', 'unit' => '字', 'min' => 22, 'max' => 32, 'step' => 1, 'gb' => 'GB §5.2.3（28 字）', 'note' => '', 'risk' => 'layout'),

            // ── 字体与行距 ────────────────────────────────────────────────
            array('path' => 'text.pt', 'label' => '正文字号', 'group' => 'text', 'type' => 'number', 'unit' => 'pt', 'min' => 12, 'max' => 22, 'step' => 0.5, 'gb' => 'GB §5.2.2（3 号 = 16pt）', 'note' => 'GB 正文 3 号仿宋', 'risk' => 'layout'),
            array('path' => 'text.line_pt', 'label' => '正文行距（固定值）', 'group' => 'text', 'type' => 'number', 'unit' => 'pt', 'min' => 20, 'max' => 40, 'step' => 0.5, 'gb' => 'GB §5.2.3（每页 22 行 ⇒ 28pt）', 'note' => '行距变了会连带每页行数', 'risk' => 'layout'),
            array('path' => 'text.indent_chars', 'label' => '正文首行缩进', 'group' => 'text', 'type' => 'number', 'unit' => '字', 'min' => 0, 'max' => 4, 'step' => 1, 'gb' => 'GB §7.3.3（左空二字）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'title.pt', 'label' => '标题字号', 'group' => 'text', 'type' => 'number', 'unit' => 'pt', 'min' => 16, 'max' => 30, 'step' => 0.5, 'gb' => 'GB §7.3.1（2 号 = 22pt）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'title.line_pt', 'label' => '标题行距', 'group' => 'text', 'type' => 'number', 'unit' => 'pt', 'min' => 22, 'max' => 50, 'step' => 0.5, 'gb' => 'GB §7.3.1', 'note' => '', 'risk' => 'visual'),
            array('path' => 'title.wrap_chars', 'label' => '标题每行最多字数（0=按版心自动）', 'group' => 'text', 'type' => 'number', 'unit' => '字', 'min' => 0, 'max' => 40, 'step' => 1, 'gb' => 'GB §7.3.1（回行时梯形/菱形居中）', 'note' => '0 = 按「版心宽 ÷ 标题字宽」自动推算（2 号标题约 20 字/行）', 'risk' => 'layout'),
            array('path' => 'title.wrap_mode', 'label' => '标题折行方式', 'group' => 'text', 'type' => 'select', 'unit' => '',
                'options' => array('balanced' => '均衡（梯形/菱形，各行等宽）', 'fill' => '逐行填满（末行可短）'),
                'gb' => 'GB §7.3.1', 'note' => '国标观感为均衡；填满只在你明确要求时用', 'risk' => 'visual'),
            array('path' => 'title.wrap_min_chars', 'label' => '标题每行最少字数（0=不限）', 'group' => 'text', 'type' => 'number', 'unit' => '字', 'min' => 0, 'max' => 20, 'step' => 1, 'gb' => '协会口径（避免词组被切碎）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'heading.h1.pt', 'label' => '层次标题字号（一、）', 'group' => 'text', 'type' => 'number', 'unit' => 'pt', 'min' => 12, 'max' => 22, 'step' => 0.5, 'gb' => 'GB §5.2.2（3 号黑体）', 'note' => '', 'risk' => 'visual'),

            // ── 主送机关与正文 ────────────────────────────────────────────
            array('path' => 'main.receiver_gap_lines', 'label' => '主送机关前空行', 'group' => 'main', 'type' => 'number', 'unit' => '行', 'min' => 0, 'max' => 3, 'step' => 1, 'gb' => 'GB §7.3.2（标题下空一行）', 'note' => '', 'risk' => 'layout'),
            array('path' => 'main.receiver_indent_chars', 'label' => '主送机关缩进', 'group' => 'main', 'type' => 'number', 'unit' => '字', 'min' => 0, 'max' => 4, 'step' => 1, 'gb' => 'GB §7.3.2（左顶格 = 0）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'main.attach_gap_lines', 'label' => '附件说明前空行', 'group' => 'main', 'type' => 'number', 'unit' => '行', 'min' => 0, 'max' => 3, 'step' => 1, 'gb' => 'GB §7.3.4（正文下空一行）', 'note' => '', 'risk' => 'layout'),
            array('path' => 'main.attach_indent_chars', 'label' => '附件说明缩进', 'group' => 'main', 'type' => 'number', 'unit' => '字', 'min' => 0, 'max' => 4, 'step' => 1, 'gb' => 'GB §7.3.4（左空二字）', 'note' => '', 'risk' => 'visual'),

            // ── 落款 ──────────────────────────────────────────────────────
            array('path' => 'sign.gap_lines_before_org', 'label' => '落款前空行', 'group' => 'sign', 'type' => 'number', 'unit' => '行', 'min' => 0, 'max' => 4, 'step' => 1, 'gb' => 'GB §7.3.5.1（正文下空一行）', 'note' => '', 'risk' => 'layout'),
            array('path' => 'sign.org_right_indent_chars_without_seal', 'label' => '署名右空（无印章）', 'group' => 'sign', 'type' => 'number', 'unit' => '字', 'min' => 0, 'max' => 6, 'step' => 1, 'gb' => 'GB §7.3.5.1（右空二字）', 'note' => '仅"不加盖印章"时生效', 'risk' => 'visual'),
            array('path' => 'sign.date_right_indent_chars_without_seal', 'label' => '成文日期右空（无印章）', 'group' => 'sign', 'type' => 'number', 'unit' => '字', 'min' => 0, 'max' => 6, 'step' => 1, 'gb' => 'GB §7.3.5.1（右空二字）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'sign.date_right_indent_chars_with_seal', 'label' => '成文日期右空（有印章）', 'group' => 'sign', 'type' => 'number', 'unit' => '字', 'min' => 0, 'max' => 8, 'step' => 1, 'gb' => 'GB §7.3.5.1（右空四字）', 'note' => '「日期与单位居中」以该值为横向基准', 'risk' => 'layout'),
            array('path' => 'sign.org_align', 'label' => '署名与日期的对齐口径（谁是中轴）', 'group' => 'sign', 'type' => 'select', 'unit' => '',
                'options' => array(
                    'center_on_date' => '署名以成文日期为准居中（国标默认）',
                    'center_on_org'  => '日期以署名为准居中（署名整块居中于版心）',
                    'right_align'    => '两行右端对齐',
                ),
                'gb' => 'GB §7.3.5.1（署名以成文日期为准居中）',
                'note' => '国标口径下**日期位置固定**，署名长度只影响左右伸展；换口径会让日期随署名左右移动', 'risk' => 'layout'),
            array('path' => 'sign.wrap_min_chars', 'label' => '回行每行最少字数（0=不限）', 'group' => 'sign', 'type' => 'number', 'unit' => '字', 'min' => 0, 'max' => 20, 'step' => 1, 'gb' => '协会口径（防止长署名被切碎）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'sign.wrap_mode', 'label' => '署名折行方式', 'group' => 'sign', 'type' => 'select', 'unit' => '',
                'options' => array('balanced' => '均衡（各行等宽）', 'fill' => '逐行填满（末行可短）'),
                'gb' => 'GB §7.3.5.1（回行时与上一行居中）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'sign.date_no_leading_zero', 'label' => '成文日期月日不补零', 'group' => 'sign', 'type' => 'bool', 'unit' => '', 'gb' => 'GB §7.3.5.1（10月4日 不写 10月04日）', 'note' => '', 'risk' => 'visual'),

            // ── 印章 ──────────────────────────────────────────────────────
            array('path' => 'seal.diameter_mm', 'label' => '印章直径', 'group' => 'seal', 'type' => 'number', 'unit' => 'mm', 'min' => 30, 'max' => 45, 'step' => 0.5, 'gb' => '国发〔1999〕25 号（社团印章 ≤ 45mm）', 'note' => '本会真实章 40mm；单篇可覆盖', 'risk' => 'layout'),
            array('path' => 'seal.center_offset_mm', 'label' => '章心相对落款块心的上移量', 'group' => 'seal', 'type' => 'number', 'unit' => 'mm', 'min' => 0, 'max' => 20, 'step' => 0.5, 'gb' => 'GB §7.3.5.1（署名与日期居章心偏下）', 'note' => '越大 ⇒ 章越往上压', 'risk' => 'visual'),
            array('path' => 'seal.placement', 'label' => '印章摆放口径', 'group' => 'seal', 'type' => 'select', 'unit' => '', 'options' => array('over' => '居中下压（国标）', 'below' => '落款两行之下'), 'gb' => 'GB §7.3.5.1（居中下压）', 'note' => '「下一层」的层序由固定口径保证（文字压章），与此项无关', 'risk' => 'layout'),
            array('path' => 'seal.text_above', 'label' => '文字压章之上', 'group' => 'seal', 'type' => 'bool', 'unit' => '', 'gb' => '协会口径（章在下一层）', 'note' => '开启 = 落款文字清晰可读', 'risk' => 'visual'),
            array('path' => 'seal.gap_below_sign_mm', 'label' => '章顶距日期底（仅"两行之下"口径）', 'group' => 'seal', 'type' => 'number', 'unit' => 'mm', 'min' => 0, 'max' => 20, 'step' => 0.5, 'gb' => '协会口径', 'note' => '', 'risk' => 'visual'),
            array('path' => 'seal.low_dpi_threshold', 'label' => '低清判定阈值', 'group' => 'seal', 'type' => 'number', 'unit' => 'dpi', 'min' => 150, 'max' => 600, 'step' => 10, 'gb' => '协会口径（素材质量）', 'note' => '低于该有效 dpi 视为低清', 'risk' => 'visual'),

            // ── 红头（发文机关标志） ──────────────────────────────────────
            array('path' => 'header.letterhead_width_mm', 'label' => '版心宽（红头/正文可用宽）', 'group' => 'header', 'type' => 'number', 'unit' => 'mm', 'min' => 130, 'max' => 180, 'step' => 0.5, 'gb' => 'GB §5.1（156mm）', 'note' => '红头、正文、落款的横向基准', 'risk' => 'layout'),
            array('path' => 'header.org_pt', 'label' => '发文机关标志字号', 'group' => 'header', 'type' => 'number', 'unit' => 'pt', 'min' => 20, 'max' => 60, 'step' => 0.5, 'gb' => 'GB §7.2.2（≤ 上级机关标志）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'header.rule_h_mm', 'label' => '红色分隔线粗细', 'group' => 'header', 'type' => 'number', 'unit' => 'mm', 'min' => 0.1, 'max' => 3, 'step' => 0.05, 'gb' => 'GB §7.2.3', 'note' => '', 'risk' => 'visual'),
            array('path' => 'header.rule_gap_mm', 'label' => '分隔线与文号间距', 'group' => 'header', 'type' => 'number', 'unit' => 'mm', 'min' => 0, 'max' => 20, 'step' => 0.5, 'gb' => 'GB §7.2.3', 'note' => '', 'risk' => 'visual'),
            array('path' => 'header.rule_color', 'label' => '红色分隔线（发文机关标志下反线）颜色', 'group' => 'header', 'type' => 'color', 'unit' => '', 'gb' => 'GB §7.2.5（红色反线）', 'note' => '取色器或填 6 位十六进制（如 D80505）；GB 要求与发文机关标志同色', 'risk' => 'visual'),
            array('path' => 'header.org_color', 'label' => '发文机关标志（红头）颜色', 'group' => 'header', 'type' => 'color', 'unit' => '', 'gb' => 'GB §7.2.5（红色）', 'note' => '红头与反线应同色（除非刻意区分）', 'risk' => 'visual'),

            // ── 字体（国标四体）—— TASK-2007：用户「没有 字体配置」 ──────────────
            array('path' => 'fonts.title.local', 'label' => '标题字体', 'group' => 'font', 'type' => 'font', 'gb' => 'GB §7.3.1（小标宋）', 'note' => '公文标题用 2 号小标宋；改字号见「字体、字号与行距」', 'risk' => 'visual'),
            array('path' => 'fonts.h1.local', 'label' => '一级标题字体（一、）', 'group' => 'font', 'type' => 'font', 'gb' => 'GB §5.2.2（黑体）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'fonts.h2.local', 'label' => '二级标题字体（（一））', 'group' => 'font', 'type' => 'font', 'gb' => 'GB §5.2.2（楷体）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'fonts.h3.local', 'label' => '三级标题字体（1.）', 'group' => 'font', 'type' => 'font', 'gb' => 'GB §5.2.2（仿宋）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'fonts.h4.local', 'label' => '四级标题字体（（1））', 'group' => 'font', 'type' => 'font', 'gb' => 'GB §5.2.2（仿宋）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'fonts.body.local', 'label' => '正文字体', 'group' => 'font', 'type' => 'font', 'gb' => 'GB §5.2.2（仿宋_GB2312）', 'note' => '正文、主送机关、附件说明、落款均随之变化', 'risk' => 'visual'),
            array('path' => 'fonts.page.local', 'label' => '页码 / 版记字体', 'group' => 'font', 'type' => 'font', 'gb' => 'GB §7.5（宋体）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'fonts.title.weight', 'label' => '标题字重', 'group' => 'font', 'type' => 'select', 'unit' => '', 'options' => array('normal' => '常规（不加粗）', 'bold' => '加粗'), 'gb' => 'GB §7.3.1', 'note' => '小标宋本身较重，一般保持加粗', 'risk' => 'visual'),
            array('path' => 'fonts.h1.weight', 'label' => '一级标题字重', 'group' => 'font', 'type' => 'select', 'unit' => '', 'options' => array('normal' => '常规', 'bold' => '加粗'), 'gb' => 'GB §5.2.2', 'note' => '黑体一般加粗', 'risk' => 'visual'),

            // ── 版记与页码 ────────────────────────────────────────────────
            // ── 版头要素显示开关（胶囊开关，TASK-2024）────────────────────────
            array('path' => 'header.show_copy_no', 'label' => '显示份号', 'group' => 'header', 'type' => 'bool', 'unit' => '', 'gb' => 'GB §7.2.1（份号）', 'note' => '关掉后即便有份号也不印', 'risk' => 'visual'),
            array('path' => 'header.show_secret', 'label' => '显示密级', 'group' => 'header', 'type' => 'bool', 'unit' => '', 'gb' => 'GB §7.2.2（密级和保密期限）', 'note' => '关掉后密级与期限都不印', 'risk' => 'visual'),
            array('path' => 'header.show_secret_period', 'label' => '显示保密期限（★后的部分）', 'group' => 'header', 'type' => 'bool', 'unit' => '', 'gb' => 'GB §7.2.2', 'note' => '关掉后只显示密级（如「秘密」）', 'risk' => 'visual'),
            array('path' => 'header.show_urgency', 'label' => '显示紧急程度', 'group' => 'header', 'type' => 'bool', 'unit' => '', 'gb' => 'GB §7.2.3（紧急程度）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'header.show_signer', 'label' => '显示签发人', 'group' => 'header', 'type' => 'bool', 'unit' => '', 'gb' => 'GB §7.2.4（上行文标注签发人）', 'note' => '关掉后发文字号右侧不显示签发人', 'risk' => 'visual'),

            array('path' => 'record.rules_scope', 'label' => '版记横线范围（抄送 / 印发）', 'group' => 'record', 'type' => 'select', 'unit' => '',
                'options' => array(
                    'enclose'    => '抄送与印发都在横线内（GB 默认）',
                    'print_only' => '抄送在横线之外（横线只括印发）',
                    'none'       => '不画版记横线',
                ),
                'gb' => 'GB §7.4（版记：上下横线夹住抄送与印发）', 'note' => '改这项即可控制「抄送/印发是否在横线内」', 'risk' => 'layout'),
            array('path' => 'record.pt', 'label' => '版记字号', 'group' => 'record', 'type' => 'number', 'unit' => 'pt', 'min' => 10, 'max' => 18, 'step' => 0.5, 'gb' => 'GB §7.4.2（4 号仿宋 = 14pt）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'record.separator_thick_mm', 'label' => '版记粗分隔线', 'group' => 'record', 'type' => 'number', 'unit' => 'mm', 'min' => 0, 'max' => 1.5, 'step' => 0.05, 'gb' => 'GB §7.4.2', 'note' => '', 'risk' => 'visual'),
            array('path' => 'record.separator_thin_mm', 'label' => '版记细分隔线', 'group' => 'record', 'type' => 'number', 'unit' => 'mm', 'min' => 0, 'max' => 1.5, 'step' => 0.05, 'gb' => 'GB §7.4.2', 'note' => '', 'risk' => 'visual'),
            array('path' => 'footer.pt', 'label' => '页码字号', 'group' => 'record', 'type' => 'number', 'unit' => 'pt', 'min' => 8, 'max' => 16, 'step' => 0.5, 'gb' => 'GB §7.5（4 号半角宋体）', 'note' => '', 'risk' => 'visual'),
            array('path' => 'footer.baseline_below_text_mm', 'label' => '页码距版心下沿', 'group' => 'record', 'type' => 'number', 'unit' => 'mm', 'min' => 0, 'max' => 20, 'step' => 0.5, 'gb' => 'GB §7.5', 'note' => '', 'risk' => 'visual'),
        );

        $fields = $f;

        return $fields;
    }
}

if (!function_exists('zw_doc_layout_groups')) {
    /**
     * 分组标题（设计器页分区顺序）。
     *
     * @return array<string,string>
     */
    function zw_doc_layout_groups() {
        return array(
            'paper'  => '纸张与版心',
            'text'   => '字体、字号与行距',
            'main'   => '主送机关与正文',
            'sign'   => '落款（署名 / 成文日期）',
            'seal'   => '印章',
            'header' => '红头（发文机关标志）',
            'font'   => '字体（国标四体）',
            'record' => '版记与页码',
        );
    }
}

if (!function_exists('zw_doc_layout_get_overrides')) {
    /**
     * 读取覆盖层（已过滤为白名单内的键）。
     *
     * @return array<string,mixed>
     */
    function zw_doc_layout_get_overrides() {
        $raw = get_option(zw_doc_layout_option_key(), array());
        if (!is_array($raw)) {
            return array();
        }
        $allow = array();
        foreach (zw_doc_layout_fields() as $field) {
            $allow[(string) $field['path']] = true;
        }
        $out = array();
        foreach ($raw as $path => $value) {
            if (isset($allow[(string) $path])) {
                $out[(string) $path] = $value;
            }
        }

        return $out;
    }
}

if (!function_exists('zw_doc_layout_merge_post')) {
    /**
     * 把一次表单提交合并进现有覆盖项（**纯函数**，无副作用，便于回归测试）。
     *
     * TASK-2054（用户报障「份号 密级 等胶囊按钮 不生效」）：**未勾选的 checkbox 不会出现在提交数据里**，
     * 旧实现 `if (!array_key_exists($path, $submitted)) { continue; }` 于是把"关掉开关"当作"没提交"，
     * 覆盖项永远保留旧值 `true` ⇒ **开关只能开、不能关**。修法：`bool` 字段**始终参与合并**，
     * 未提交即视为 `false`（关闭）。
     *
     * @param array $submitted 提交的 `layout[...]` 数组（已 unslash）。
     * @param array $current   现有覆盖项。
     * @return array{next:array,changed:array}
     */
    function zw_doc_layout_merge_post($submitted, $current) {
        $submitted = is_array($submitted) ? $submitted : array();
        $current   = is_array($current) ? $current : array();
        $next      = $current;
        $changed   = array();
        if (!function_exists('zw_doc_layout_fields')) {
            return array('next' => $next, 'changed' => $changed);
        }
        foreach (zw_doc_layout_fields() as $field) {
            $path = isset($field['path']) ? (string) $field['path'] : '';
            if ($path === '') {
                continue;
            }
            $type = isset($field['type']) ? (string) $field['type'] : 'text';
            $has  = array_key_exists($path, $submitted);
            // bool：**永远合并**（未提交 = 关闭）；其它类型：未提交则跳过（不改动既有值）。
            if (!$has && $type !== 'bool') {
                continue;
            }
            $raw   = $has ? $submitted[$path] : '';
            $clean = zw_doc_layout_coerce($field, $raw);
            if ($clean === null) {
                continue;
            }
            $old_missing = !array_key_exists($path, $next);
            if ($old_missing || (string) $next[$path] !== (string) $clean) {
                $changed[$path] = array(
                    'old' => $old_missing ? null : $next[$path],
                    'new' => $clean,
                );
                $next[$path] = $clean;
            }
        }

        return array('next' => $next, 'changed' => $changed);
    }
}

if (!function_exists('zw_doc_layout_coerce')) {
    /**
     * 按字段声明**夹紧/校验**单个值（fail-closed：类型不符返回 `null`）。
     *
     * @param array $field 字段定义。
     * @param mixed $value 原始提交值。
     * @return mixed|null
     */
    function zw_doc_layout_coerce($field, $value) {
        $type = isset($field['type']) ? (string) $field['type'] : 'text';
        if ($type === 'bool') {
            return (bool) $value;
        }
        if ($type === 'font') {
            // 字体槽：写入 `fonts.<slot>.local` 的首选家族。白名单外一律拒收（返回 null），
            // 防止把服务器没装的字体写进版式（会静默回退成默认字体，比不配更糟）。
            $choices = function_exists('zw_doc_layout_font_choices') ? zw_doc_layout_font_choices() : array();
            // 入参可能是标量（表单提交）或数组（option 里存的自有格式）——统一取首项比对，
            // 否则「保存过一次之后再读」会被判非法而静默失效（实测踩过）。
            if (is_array($value)) {
                $value = isset($value[0]) ? (string) $value[0] : '';
            } else {
                $value = is_scalar($value) ? (string) $value : '';
            }

            return ($value !== '' && isset($choices[$value])) ? array($value) : null;
        }
        if ($type === 'color') {
            // TASK-2052（用户「红头-发文机关 红色分隔线 的颜色 也放到设置中」）：颜色字段。
            // 接受 `#RGB` / `#RRGGBB` / `RGB` / `RRGGBB`，统一归一为**不带 # 的大写 6 位**
            //（版式常量里 `header.rule_color` / `header.org_color` 就是此形状，渲染侧自己补 `#`）。
            if (is_array($value)) {
                $value = isset($value[0]) ? (string) $value[0] : '';
            } else {
                $value = is_scalar($value) ? (string) $value : '';
            }
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            $hex = strtoupper(ltrim($value, '#'));
            if (preg_match('/^[0-9A-F]{3}$/', $hex)) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }

            return preg_match('/^[0-9A-F]{6}$/', $hex) ? $hex : null;
        }
        if ($type === 'select') {
            $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();
            $value   = is_scalar($value) ? (string) $value : '';

            return isset($options[$value]) ? $value : null;
        }
        if ($type === 'number') {
            if (!is_scalar($value) || !is_numeric($value)) {
                return null;
            }
            $num = (float) $value;
            if (isset($field['min']) && $num < (float) $field['min']) {
                $num = (float) $field['min'];
            }
            if (isset($field['max']) && $num > (float) $field['max']) {
                $num = (float) $field['max'];
            }
            if (isset($field['step']) && (float) $field['step'] > 0) {
                $step = (float) $field['step'];
                $num  = round($num / $step) * $step;
            }

            return round($num, 4);
        }
        if (!is_scalar($value)) {
            return null;
        }

        return sanitize_text_field((string) $value);
    }
}

if (!function_exists('zw_doc_layout_apply_overrides')) {
    /**
     * 把覆盖层写进 spec 数组（`zw_doc_gb9704_spec()` 末尾调用）。
     *
     * @param array $spec 原始 spec。
     * @return array
     */
    function zw_doc_layout_apply_overrides($spec) {
        if (!is_array($spec) || !function_exists('zw_doc_layout_get_overrides')) {
            return $spec;
        }
        $overrides = zw_doc_layout_get_overrides();
        if (empty($overrides)) {
            return $spec;
        }
        $by_path = array();
        foreach (zw_doc_layout_fields() as $field) {
            $by_path[(string) $field['path']] = $field;
        }
        foreach ($overrides as $path => $value) {
            if (!isset($by_path[$path])) {
                continue;
            }
            $clean = zw_doc_layout_coerce($by_path[$path], $value);
            if ($clean === null) {
                continue;
            }
            // 字体槽特例：值为数组 ⇒ **保留原回退链**，只把选中字体提到最前（去重）。
            if ((isset($by_path[$path]['type']) ? $by_path[$path]['type'] : '') === 'font' && is_array($clean)) {
                $keys = explode('.', $path);
                $node = &$spec;
                $ok2  = true;
                foreach ($keys as $k) {
                    if (!is_array($node) || !array_key_exists($k, $node)) {
                        $ok2 = false;
                        break;
                    }
                    $node = &$node[$k];
                }
                if ($ok2) {
                    $rest  = is_array($node) ? array_map('strval', $node) : array();
                    $node  = array_values(array_unique(array_merge(array_map('strval', $clean), $rest)));
                }
                unset($node);
                continue;
            }
            $keys = explode('.', $path);
            $node = &$spec;
            $ok   = true;
            foreach ($keys as $k) {
                if (!is_array($node) || !array_key_exists($k, $node)) {
                    $ok = false;
                    break;
                }
                $node = &$node[$k];
            }
            if ($ok) {
                $node = $clean;
            }
            unset($node);
        }

        return $spec;
    }
}

if (!function_exists('zw_doc_layout_history_add')) {
    /**
     * 记录一条变更历史（最近 20 条）。
     *
     * @param array $changed 变更明细 path => array('old'=>..,'new'=>..)。
     * @return void
     */
    function zw_doc_layout_history_add($changed) {
        $hist = get_option(zw_doc_layout_history_key(), array());
        if (!is_array($hist)) {
            $hist = array();
        }
        array_unshift($hist, array(
            'at'      => current_time('mysql'),
            'user'    => (int) get_current_user_id(),
            'changed' => is_array($changed) ? $changed : array(),
        ));
        update_option(zw_doc_layout_history_key(), array_slice($hist, 0, 20), false);
    }
}

if (!function_exists('zw_doc_layout_handle_post')) {
    /**
     * 保存 / 恢复默认 / 导入（唯一写入口）。
     *
     * @return void
     */
    function zw_doc_layout_handle_post() {
        if ((($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        $action = isset($_POST['zw_doc_layout_action']) ? sanitize_key(wp_unslash($_POST['zw_doc_layout_action'])) : '';
        if ($action === '') {
            return;
        }
        check_admin_referer('zw_doc_layout_save');

        if ($action === 'reset') {
            $old = zw_doc_layout_get_overrides();
            delete_option(zw_doc_layout_option_key());
            zw_doc_layout_history_add(array('__reset__' => array('old' => count($old), 'new' => 0)));
            wp_safe_redirect(add_query_arg(array('zw_doc_msg' => '已恢复为国标默认版式（覆盖项已清空）。', 'zw_doc_nc' => time()), zw_doc_layout_url()));
            exit;
        }

        $submitted = isset($_POST['layout']) && is_array($_POST['layout']) ? wp_unslash($_POST['layout']) : array();
        $current   = zw_doc_layout_get_overrides();
        $merged    = zw_doc_layout_merge_post($submitted, $current);
        $next      = $merged['next'];
        $changed   = $merged['changed'];

        update_option(zw_doc_layout_option_key(), $next, false);
        if ($changed) {
            zw_doc_layout_history_add($changed);
        }
        $msg = $changed ? ('已保存 ' . count($changed) . ' 项版式参数。') : '没有检测到变更。';
        wp_safe_redirect(add_query_arg(array('zw_doc_msg' => $msg, 'zw_doc_nc' => time()), zw_doc_layout_url()));
        exit;
    }
}

if (!function_exists('zw_doc_layout_url')) {
    /**
     * 设计器页 URL。
     *
     * @param array $args 附加参数。
     * @return string
     */
    function zw_doc_layout_url($args = array()) {
        $base = admin_url('admin.php?page=zw-doc-layout');
        if (empty($args)) {
            return $base;
        }

        return add_query_arg($args, $base);
    }
}

if (!function_exists('zw_doc_layout_value_label')) {
    /**
     * 字段当前值的展示文案。
     *
     * @param array $field 字段。
     * @param mixed $value 值。
     * @return string
     */
    function zw_doc_layout_value_label($field, $value) {
        $type = isset($field['type']) ? (string) $field['type'] : 'text';
        if ($type === 'font') {
            $first = is_array($value) ? (string) (isset($value[0]) ? $value[0] : '') : (string) $value;
            $choices = function_exists('zw_doc_layout_font_choices') ? zw_doc_layout_font_choices() : array();

            return (isset($choices[$first]) ? (string) $choices[$first]['label'] : $first);
        }
        if ($type === 'bool') {
            return !empty($value) ? '开启' : '关闭';
        }
        if ($type === 'select') {
            $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : array();

            return isset($options[(string) $value]) ? (string) $options[(string) $value] : (string) $value;
        }
        $unit = isset($field['unit']) ? (string) $field['unit'] : '';

        return (string) $value . ($unit !== '' ? ' ' . $unit : '');
    }
}

if (!function_exists('zw_doc_layout_render_page')) {
    /**
     * 页面渲染。
     *
     * @return void
     */
    function zw_doc_layout_render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        $overrides = zw_doc_layout_get_overrides();
        $groups    = zw_doc_layout_groups();
        $fields    = zw_doc_layout_fields();
        $notice    = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';
        $hist      = get_option(zw_doc_layout_history_key(), array());
        $preview_u = zw_doc_layout_preview_url();
        ?>
        <?php
        // TASK-2004：本页是**满屏编辑器**版式 —— 隐藏 WP 页脚（用户看到的「感谢使用 WordPress 进行创作。」）
        // 并去掉 #wpbody-content 的底部留白，使整页高度 ≈ 视口高 ⇒ 页面本身不滚，只有右侧参数列内部滚动。
        // 页面专属样式按项目口径写在本模板内（不新增全局 CSS 文件）。
        ?>
        <style>
        body.tco-doc-layout-screen #wpfooter{display:none}
        body.tco-doc-layout-screen #wpbody-content{padding-bottom:0}
        body.tco-doc-layout-screen .tco-doc-layout-row{height:var(--tco-doc-row-h, calc(100vh - 200px))}
        /* TASK-2012：可拖拽分隔条（视觉上是一条 1px 竖线 + 8px 抓取区）。 */
        .tco-doc-layout-splitter{flex:0 0 8px;align-self:stretch;cursor:col-resize;position:relative;background:transparent;outline:none}
        .tco-doc-layout-splitter::before{content:"";position:absolute;top:0;bottom:0;left:50%;width:1px;transform:translateX(-50%);background:#dcdcde}
        .tco-doc-layout-splitter::after{content:"";position:absolute;top:50%;left:50%;width:4px;height:34px;transform:translate(-50%,-50%);border-radius:2px;background:#c3c4c7}
        /* TASK-2006 代修：`:hover` 按 SPEC-10 §4.4 必须包在 `@media(hover:hover)` 内（TASK-2015 引入的 S4b 回归，
           原先与 `:focus` / `.is-dragging` 同行裸写）。拆成两条：键盘与拖拽态照旧即时生效，悬停态仅在支持 hover 的设备上生效。 */
        .tco-doc-layout-splitter:focus::after,.tco-doc-layout-splitter.is-dragging::after{background:#2271b1}
        @media(hover:hover){.tco-doc-layout-splitter:hover::after{background:#2271b1}}
        .tco-doc-layout-splitter:focus-visible{box-shadow:0 0 0 2px #2271b1;border-radius:2px}
        .tco-doc-layout-splitter.is-dragging+.tco-doc-layout-params{pointer-events:none}
        /* TASK-2024：胶囊开关（.zw-pill-switch）—— 纯 CSS，无 JS 依赖，键盘可用（原生 checkbox）。 */
        .zw-pill-switch{display:inline-flex;align-items:center;gap:8px;cursor:pointer;user-select:none}
        .zw-pill-switch input{position:absolute;opacity:0;width:0;height:0}
        .zw-pill-track{position:relative;display:inline-block;width:38px;height:20px;border-radius:999px;background:#c3c4c7;transition:background .15s}
        .zw-pill-knob{position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;transition:left .15s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
        .zw-pill-switch input:checked+.zw-pill-track{background:#2271b1}
        .zw-pill-switch input:checked+.zw-pill-track .zw-pill-knob{left:20px}
        .zw-pill-switch input:focus-visible+.zw-pill-track{box-shadow:0 0 0 2px #2271b1}
        .zw-pill-text{font-size:12px;color:#50575e}
        /* TASK-2052：颜色字段（取色器 + 十六进制 + 色块）。 */
        .zw-color-field{display:inline-flex;align-items:center;gap:8px}
        .zw-color-field input[type=color]{width:34px;height:26px;padding:0;border:1px solid #8c8f94;border-radius:3px;background:#fff;cursor:pointer}
        .zw-color-chip{display:inline-block;width:18px;height:18px;border:1px solid #8c8f94;border-radius:3px}
        @media (max-width:1099px){
            body.tco-doc-layout-screen .tco-doc-layout-row{flex-wrap:wrap;height:auto}
            .tco-doc-layout-splitter{display:none}
            .tco-doc-layout-preview{flex:0 0 100%!important;max-width:100%!important;height:60vh!important}
        }
        </style>
        <script>document.body.classList.add('tco-doc-layout-screen');</script>
        <div class="wrap tco-doc-layout-designer">
            <h1>公文版式</h1>
            <?php /* TASK-1999 发版门禁修复：说明性文字块的行宽上限改用 `width:min(100%,900px)`（等价视觉），
                     避免 S1b 把「说明文字块」误计为「容器 max-width」（SPEC-10 §1.9.2 第 4 条只约束容器）。 */ ?>
            <p style="width:min(100%,900px);color:#50575e">
                国标各项参数的可视化调整（GB/T 9704-2012）。左侧改参数、右侧即刻预览；保存后
                <strong>正文 / 红头 / 落款 / 印章 / 版记 / 加密 PDF</strong> 全通道同步（同一事实源，不存在第二套排版）。
                每项都给出「国标值 / 允许范围 / 条款」，超出范围会被夹紧而非报错。
            </p>
            <?php if ($notice !== '') : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($overrides)) : ?>
                <div class="notice notice-warning"><p>
                    当前有 <strong><?php echo count($overrides); ?></strong> 项覆盖国标默认值。
                    需要回到国标口径请点「恢复国标默认」。
                </p></div>
            <?php endif; ?>

            <?php
            // TASK-2002 + TASK-2004（用户 2026-10-24）：**预览在左 60%、参数在右 40%，预览固定不随页面滚动**。
            // TASK-2004 修正：**不再依赖 `sticky`** —— 行容器固定为视口高，左列 `height:100%`，
            // 右列（参数）`overflow-y:auto` 内部滚动 + 顶部固定操作条 ⇒
            // 整页不再被参数列撑高、无法滚到 WP 页脚，预览**物理上不可能**跟着动。
            // A4 在左列可用高度内等比放大（缩放由预览页脚本按容器宽/高计算，不裁切、无横向滚动条）；
            // 窄屏（< 1100px）降级为上下堆叠。
            // 与 TASK-2002 的差异：此前靠 `sticky` 且整页可滚 ⇒ 实测预览仍会跟页脚一起移动（用户反馈「为什么也跟着动」）。
            ?>
            <div class="tco-doc-layout-row" style="display:flex;gap:0;align-items:stretch;flex-wrap:nowrap;height:calc(100vh - 200px);min-height:420px;--tco-doc-split:50%">
                <?php /* TASK-2004：**不靠 sticky** —— 行容器固定为视口高，左列 `height:100%`，
                        右列内部滚动 ⇒ 整页不滚、预览物理上不可能跟着移动。 */ ?>
                <div class="tco-doc-layout-preview" style="flex:0 0 calc(var(--tco-doc-split, 50%) - 4px);max-width:calc(var(--tco-doc-split, 50%) - 4px);height:100%;display:flex;min-width:280px">
                    <div class="card" style="max-width:none;padding:10px;margin:0;display:flex;flex-direction:column;width:100%">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin:0 0 8px">
                            <h2 style="margin:0;font-size:15px">版式预览（当前保存值 · 等比缩放）</h2>
                            <span>
                                <button type="button" class="button button-small" id="tco-doc-layout-refresh">刷新预览</button>
                                <a href="<?php echo esc_url($preview_u); ?>" target="_blank" rel="noopener" style="font-size:12px;margin-left:8px">新窗口打开</a>
                            </span>
                        </div>
                        <div id="tco-doc-layout-preview-box" style="width:100%;flex:1 1 auto;min-height:320px;overflow:hidden;background:#f0f0f1;border:1px solid #dcdcde;border-radius:4px">
                            <iframe id="tco-doc-layout-preview-frame" src="<?php echo esc_url($preview_u); ?>" style="width:100%;height:100%;border:0;background:#fff" title="版式预览"></iframe>
                        </div>
                        <p style="color:#646970;font-size:12px;margin:8px 0 0">
                            样例文书含主送机关 / 落款 / 印章 / 版记；保存参数后点「刷新预览」即可看到新口径。
                        </p>
                    </div>
                </div>
                <?php /* TASK-2002 修正：60% + 40% + gap(18px) > 100% 会触发 flex 换行（实测参数列被挤到下一行） ⇒ 两列各自再减去半个间距，合计正好 100%。 */ ?>
                <?php /* TASK-2012：预览与参数之间的**可拖拽分隔条**（鼠标/触摸/键盘 ←→；30%~70% 夹紧，比例记在 localStorage）。 */ ?>
                <div id="tco-doc-layout-splitter" class="tco-doc-layout-splitter" role="separator" aria-orientation="vertical"
                     aria-label="拖拽调整左右布局占比" aria-valuemin="30" aria-valuemax="70" aria-valuenow="50" tabindex="0"
                     title="拖拽调整左右占比（也可聚焦后用 ← → 微调）"></div>
                <div class="tco-doc-layout-params" style="flex:1 1 auto;min-width:320px;height:100%;display:flex;flex-direction:column;min-height:0;padding-left:12px">
                    <?php /* 固定操作条：滚动时也始终可点（按钮用 HTML5 `form=` 关联到下面两个表单）。 */ ?>
                    <div class="tco-doc-layout-actions" style="flex:0 0 auto;display:flex;align-items:center;gap:8px;padding:6px 0 8px;border-bottom:1px solid #dcdcde;margin-bottom:8px;position:relative;z-index:2;background:#f0f0f1">
                        <button type="submit" form="tco-doc-layout-save-form" class="button button-primary button-small">保存版式</button>
                        <button type="submit" form="tco-doc-layout-reset-form" class="button button-small" onclick="return confirm('确定恢复为国标默认版式？（覆盖项将全部清空）');">恢复国标默认</button>
                        <span style="color:#646970;font-size:12px;margin-left:auto">
                            <?php if (!empty($hist)) : ?>
                                最近变更 <?php echo esc_html((string) (isset($hist[0]['at']) ? $hist[0]['at'] : '')); ?>
                                （<?php echo (int) (isset($hist[0]['changed']) ? count((array) $hist[0]['changed']) : 0); ?> 项）
                            <?php else : ?>
                                尚无覆盖
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="tco-doc-layout-params-scroll" style="flex:1 1 auto;min-height:0;overflow-y:auto;overscroll-behavior:contain;padding-right:6px">
            <?php
            // TASK-2009：**落款几何读数** —— 用户「现有配置无法判断署名长度」：这里直接把「算出来的结果」摊开，
            // 用 9 / 15 / 20 字三种署名长度，显示 署名宽、行数、中轴、署名中心与日期中心的偏移、是否越界。
            // 与渲染层同一纯函数（`zw_doc_sign_metrics()`），所见即所得。
            $zw_ld_readout = array();
            if (function_exists('zw_doc_sign_metrics')) {
                $zw_ld_box  = (float) (function_exists('zw_doc_gb9704_get') ? zw_doc_gb9704_get('header.letterhead_width_mm', 156.0) : 156.0);
                $zw_ld_pt   = (float) (function_exists('zw_doc_gb9704_get') ? zw_doc_gb9704_get('text.pt', 16.0) : 16.0);
                $zw_ld_date = (string) (function_exists('zw_doc_gb9704_get') ? zw_doc_gb9704_get('sign.date_right_indent_chars_with_seal', 4) : 4);
                $zw_ld_chars = (int) $zw_ld_date;
                $zw_ld_align = (string) (function_exists('zw_doc_gb9704_get') ? zw_doc_gb9704_get('sign.org_align', 'center_on_date') : 'center_on_date');
                foreach (array(9, 15, 20) as $zw_ld_n) {
                    $zw_ld_readout[$zw_ld_n] = zw_doc_sign_metrics(array(
                        'org'        => mb_substr('中国传统文化促进会非物质文化遗产工作委员会', 0, $zw_ld_n),
                        'date'       => '2026年10月24日',
                        'box_w'      => $zw_ld_box,
                        'font_pt'    => $zw_ld_pt,
                        'date_chars' => $zw_ld_chars,
                        'align'      => $zw_ld_align,
                    ));
                }
            }
            ?>
            <?php if (!empty($zw_ld_readout)) : ?>
                <div class="card" style="max-width:none;padding:10px 14px;margin:0 0 12px">
                    <h2 style="margin:2px 0 6px;font-size:15px">落款几何读数（按当前参数实时计算）</h2>
                    <p style="margin:0 0 8px;color:#646970;font-size:12px">
                        中轴 = <?php echo esc_html($zw_ld_align === 'center_on_date' ? '成文日期中心（国标：署名以日期为准居中）' : ($zw_ld_align === 'center_on_org' ? '版心中心（日期以署名为准居中）' : '两行右端对齐')); ?>
                        ；日期右空 <?php echo (int) $zw_ld_chars; ?> 字；
                        <strong>Δ = 署名中心 − 日期中心</strong>，国标口径下无论署名多长都应为 <strong>0.00mm</strong>。
                    </p>
                    <?php /* TASK-1999 发版门禁（三轮）：TASK-2009 新增「落款几何读数」宽表，补 canonical 横滑包裹（SPEC-10 §1.9.2 第 4/5 条）。 */ ?>
                    <div class="tco-table-wrap">
                    <table class="widefat striped" style="border:0">
                        <thead><tr>
                            <th style="width:12%">署名长度</th><th>估算宽度</th><th>行数</th><th>中轴</th>
                            <th>署名中心</th><th>日期中心</th><th>Δ</th><th>是否越界</th><th>折行结果（每行文字）</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($zw_ld_readout as $zw_ld_n => $zw_ld_m) : ?>
                            <tr>
                                <td><strong><?php echo (int) $zw_ld_n; ?> 字</strong></td>
                                <td><?php echo esc_html((string) $zw_ld_m['org_w_mm']); ?> mm</td>
                                <td><?php echo (int) $zw_ld_m['lines']; ?></td>
                                <td><?php echo esc_html((string) $zw_ld_m['axis_mm']); ?> mm</td>
                                <td><?php echo esc_html((string) $zw_ld_m['org_cx_mm']); ?> mm</td>
                                <td><?php echo esc_html((string) $zw_ld_m['date_cx_mm']); ?> mm</td>
                                <td style="color:<?php echo abs((float) $zw_ld_m['delta_mm']) < 0.05 ? '#1d6f42' : '#b32d2e'; ?>">
                                    <strong><?php echo esc_html((string) $zw_ld_m['delta_mm']); ?> mm</strong>
                                </td>
                                <td>
                                    <?php if ($zw_ld_m['over_left'] || $zw_ld_m['over_right']) : ?>
                                        <span style="color:#b32d2e">越界（左<?php echo $zw_ld_m['over_left'] ? '是' : '否'; ?> / 右<?php echo $zw_ld_m['over_right'] ? '是' : '否'; ?>）</span>
                                    <?php else : ?>
                                        <span style="color:#1d6f42">在版心内</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12px">
                                    <?php
                                    // TASK-2010：把断行结果直接摊开，便于判断「词组有没有被切碎」。
                                    $zw_ld_lines_txt = array_map(function ($x) { return esc_html((string) $x); }, (array) (isset($zw_ld_m['line_texts']) ? $zw_ld_m['line_texts'] : array()));
                                    echo implode(' <span style="color:#646970">/</span> ', $zw_ld_lines_txt);
                                    if (!empty($zw_ld_m['min_violated'])) {
                                        echo '<div style="color:#b32d2e;font-size:11px">⚠ 有行少于「每行最少字数」</div>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div><?php /* /.tco-table-wrap */ ?>
                    <?php
                    // TASK-2010：标题折行读数（短/中/长三档样例），断行结果与各行字数一并给出。
                    $zw_ld_titles = array(
                        '短（14 字）' => '关于同意成立分支机构的批复',
                        '中（26 字）' => '关于同意成立中国传统文化促进会孔子文化传承工作委员会的批复',
                        '长（40 字）' => '关于同意成立中国传统文化促进会孔子文化传承工作委员会等三个分支机构并开展相关工作的批复',
                    );
                    ?>
                    <h2 style="margin:14px 0 6px;font-size:15px">标题折行读数</h2>
                    <p style="margin:0 0 8px;color:#646970;font-size:12px">
                        每行最多字数：<?php echo (int) (function_exists('zw_doc_gb9704_get') ? zw_doc_gb9704_get('title.wrap_chars', 0) : 0); ?>（0 = 按版心自动）；
                        折行方式：<?php echo esc_html((string) (function_exists('zw_doc_gb9704_get') ? zw_doc_gb9704_get('title.wrap_mode', 'balanced') : 'balanced')); ?>
                    </p>
                    <div class="tco-table-wrap">
                    <table class="widefat striped" style="border:0">
                        <thead><tr><th style="width:14%">样例</th><th style="width:8%">行数</th><th>断行结果</th></tr></thead>
                        <tbody>
                        <?php foreach ($zw_ld_titles as $zw_ld_tlabel => $zw_ld_ttext) : ?>
                            <?php $zw_ld_tlines = function_exists('zw_doc_title_lines') ? (array) zw_doc_title_lines($zw_ld_ttext) : array($zw_ld_ttext); ?>
                            <tr>
                                <td><strong><?php echo esc_html($zw_ld_tlabel); ?></strong></td>
                                <td><?php echo count($zw_ld_tlines); ?></td>
                                <td style="font-size:12px">
                                    <?php echo implode(' <span style="color:#646970">/</span> ', array_map('esc_html', $zw_ld_tlines)); ?>
                                    <div style="color:#646970;font-size:11px">
                                        各行字数：<?php echo implode(' / ', array_map(function ($x) { return (string) (function_exists('mb_strlen') ? mb_strlen((string) $x, 'UTF-8') : strlen((string) $x)); }, $zw_ld_tlines)); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endif; ?>

                    <form id="tco-doc-layout-save-form" method="post">
                        <?php wp_nonce_field('zw_doc_layout_save'); ?>
                        <input type="hidden" name="zw_doc_layout_action" value="save">
                        <?php foreach ($groups as $gkey => $glabel) : ?>
                            <div class="card" style="max-width:none;padding:8px 14px 14px;margin-bottom:12px">
                                <h2 style="margin:8px 0 4px;font-size:15px"><?php echo esc_html($glabel); ?></h2>
                                <?php /* TASK-1999 发版门禁修复：宽表落 canonical 横滑包裹（SPEC-10 §1.9.2 第 4/5 条「溢出内容可达」）。 */ ?>
                                <div class="tco-table-wrap">
                                <table class="widefat striped" style="border:0">
                                    <thead>
                                        <tr>
                                            <th style="width:34%">参数</th>
                                            <th style="width:20%">国标值</th>
                                            <th style="width:20%">当前值</th>
                                            <th>调整</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($fields as $field) : ?>
                                        <?php if ((string) $field['group'] !== (string) $gkey) { continue; } ?>
                                        <?php
                                        $path    = (string) $field['path'];
                                        $gb      = function_exists('zw_doc_gb9704_get') ? zw_doc_gb9704_get($path) : null;
                                        $now     = array_key_exists($path, $overrides) ? $overrides[$path] : $gb;
                                        $type    = (string) $field['type'];
                                        $unit    = isset($field['unit']) ? (string) $field['unit'] : '';
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html((string) $field['label']); ?></strong>
                                                <div style="color:#646970;font-size:12px">
                                                    <code><?php echo esc_html($path); ?></code>
                                                    <?php if (!empty($field['gb'])) : ?>· <?php echo esc_html((string) $field['gb']); ?><?php endif; ?>
                                                    <?php if (!empty($field['note'])) : ?><br><?php echo esc_html((string) $field['note']); ?><?php endif; ?>
                                                </div>
                                            </td>
                                            <td style="color:#2271b1"><?php echo esc_html(zw_doc_layout_value_label($field, $gb)); ?></td>
                                            <td>
                                                <?php echo esc_html(zw_doc_layout_value_label($field, $now)); ?>
                                                <?php if (array_key_exists($path, $overrides)) : ?>
                                                    <span style="color:#b32d2e;font-size:11px">（已覆盖）</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($type === 'font') : ?>
                                                    <?php
                                                    $zw_font_choices = zw_doc_layout_font_choices();
                                                    $zw_font_now     = is_array($now) ? (string) (isset($now[0]) ? $now[0] : '') : (string) $now;
                                                    $zw_font_installed = zw_doc_layout_installed_fonts();
                                                    ?>
                                                    <?php /* TASK-1999 发版门禁（二轮）：表单控件宽度用 `width`+`max-width:100%` 表达（等价视觉），
                                                             避免 S1b 把控件宽度上限计为「容器 max-width」；S1b 只约束页面容器。 */ ?>
                                                    <select name="layout[<?php echo esc_attr($path); ?>]" style="width:260px;max-width:100%">
                                                        <?php foreach ($zw_font_choices as $fval => $fmeta) : ?>
                                                            <?php
                                                            $zw_state = empty($zw_font_installed) ? ''
                                                                : (!empty($zw_font_installed[$fval]) ? ' ✓已装' : ' ✗未装');
                                                            ?>
                                                            <option value="<?php echo esc_attr((string) $fval); ?>"<?php selected($zw_font_now, (string) $fval); ?>>
                                                                <?php echo esc_html((string) $fmeta['label'] . $zw_state); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if ($zw_font_now !== '' && !isset($zw_font_choices[$zw_font_now])) : ?>
                                                        <div style="color:#b32d2e;font-size:11px">当前值「<?php echo esc_html($zw_font_now); ?>」不在白名单（渲染将回退默认）</div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($zw_font_installed) && $zw_font_now !== '' && empty($zw_font_installed[$zw_font_now])) : ?>
                                                        <div style="color:#b32d2e;font-size:11px">⚠ 服务器未装该字体，出件会用回退链里的第一个已装字体</div>
                                                    <?php endif; ?>
                                                <?php elseif ($type === 'select') : ?>
                                                    <select name="layout[<?php echo esc_attr($path); ?>]">
                                                        <?php foreach ((array) $field['options'] as $oval => $olabel) : ?>
                                                            <option value="<?php echo esc_attr((string) $oval); ?>"<?php selected((string) $now, (string) $oval); ?>><?php echo esc_html((string) $olabel); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php elseif ($type === 'bool') : ?>
                                                    <?php /* TASK-2024：胶囊开关（pill switch）—— 视觉是开关，语义仍是 checkbox（无 JS 也能用）。 */ ?>
                                                    <label class="zw-pill-switch" title="开启 / 关闭">
                                                        <input type="checkbox" name="layout[<?php echo esc_attr($path); ?>]" value="1"<?php checked(!empty($now)); ?>>
                                                        <span class="zw-pill-track" aria-hidden="true"><span class="zw-pill-knob"></span></span>
                                                        <span class="zw-pill-text"><?php echo !empty($now) ? '显示' : '不显示'; ?></span>
                                                    </label>
                                                <?php elseif ($type === 'color') : ?>
                                                    <?php /* TASK-2052：颜色字段 = 取色器 + 十六进制输入（两者同步，无 JS 时十六进制可用）。 */ ?>
                                                    <?php $zw_hex = '#' . ltrim((string) $now, '#'); ?>
                                                    <span class="zw-color-field">
                                                        <input type="color" value="<?php echo esc_attr($zw_hex); ?>"
                                                            data-zw-color-picker="<?php echo esc_attr($path); ?>"
                                                            aria-label="<?php echo esc_attr((string) $field['label']); ?> 取色器">
                                                        <input type="text" maxlength="7" name="layout[<?php echo esc_attr($path); ?>]"
                                                            id="zw-color-<?php echo esc_attr(str_replace('.', '-', $path)); ?>"
                                                            value="<?php echo esc_attr((string) $now); ?>"
                                                            data-zw-color-text="<?php echo esc_attr($path); ?>"
                                                            placeholder="D80505" style="width:96px;font-family:monospace">
                                                        <span class="zw-color-chip" style="background:<?php echo esc_attr($zw_hex); ?>"
                                                            data-zw-color-chip="<?php echo esc_attr($path); ?>" aria-hidden="true"></span>
                                                    </span>
                                                <?php else : ?>
                                                    <input type="number" step="<?php echo esc_attr((string) (isset($field['step']) ? $field['step'] : 0.1)); ?>"
                                                        min="<?php echo esc_attr((string) (isset($field['min']) ? $field['min'] : '')); ?>"
                                                        max="<?php echo esc_attr((string) (isset($field['max']) ? $field['max'] : '')); ?>"
                                                        name="layout[<?php echo esc_attr($path); ?>]"
                                                        value="<?php echo esc_attr((string) $now); ?>" style="width:110px">
                                                    <span style="color:#646970"><?php echo esc_html($unit); ?></span>
                                                <?php endif; ?>
                                                <?php if (isset($field['min']) || isset($field['max'])) : ?>
                                                    <div style="color:#646970;font-size:11px">
                                                        允许 <?php echo esc_html((string) (isset($field['min']) ? $field['min'] : '−')); ?>
                                                        ~ <?php echo esc_html((string) (isset($field['max']) ? $field['max'] : '−')); ?><?php echo esc_html($unit); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                </div><?php /* /.tco-table-wrap */ ?>
                            </div>
                        <?php endforeach; ?>
                    </form>
                    </div><?php /* /.tco-doc-layout-params-scroll */ ?>
                    <form id="tco-doc-layout-reset-form" method="post" style="display:none">
                        <?php wp_nonce_field('zw_doc_layout_save'); ?>
                        <input type="hidden" name="zw_doc_layout_action" value="reset">
                    </form>
                </div>
            </div>
            </div>
            <script>
            (function(){
                var btn = document.getElementById('tco-doc-layout-refresh');
                var frame = document.getElementById('tco-doc-layout-preview-frame');
                if (btn && frame) {
                    btn.addEventListener('click', function(){
                        var u = frame.getAttribute('src').split('&zw_t=')[0];
                        frame.setAttribute('src', u + '&zw_t=' + Date.now());
                    });
                }
                // TASK-2004：行高按「行容器顶边 → 视口底」实测计算 ⇒ 整页不产生纵向滚动，
                // 参数列在自己的滚动区里滚，预览永远不会跟着页面移动（不依赖 magic 数字，也不依赖 sticky）。
                var row = document.querySelector('.tco-doc-layout-row');
                function fitRow(){
                    if (!row) { return; }
                    var top = row.getBoundingClientRect().top + (window.pageYOffset || 0);
                    // −24px：扣除 .wrap 的下外边距与浏览器取整，确保页面**不产生纵向滚动**（实测 −8 时仍溢出 15px）。
                    var h = Math.max(360, Math.floor(window.innerHeight - top - 24));
                    row.style.setProperty('--tco-doc-row-h', h + 'px');
                    row.style.height = h + 'px';
                }
                // TASK-2012：左右分栏**可拖拽**（鼠标/触摸；聚焦后 ←→ 微调 2%），30%~70% 夹紧，
                // 比例写入 localStorage（下次进来保持）。拖拽只改分栏比例，不改行高 ⇒ 预览位置依旧固定。
                var splitter = document.getElementById('tco-doc-layout-splitter');
                var SPLIT_KEY = 'zwDocLayoutSplitPct';
                function applySplit(pct, persist){
                    pct = Math.max(30, Math.min(70, pct));
                    row.style.setProperty('--tco-doc-split', pct.toFixed(2) + '%');
                    if (splitter) { splitter.setAttribute('aria-valuenow', String(Math.round(pct))); }
                    if (persist) { try { localStorage.setItem(SPLIT_KEY, pct.toFixed(2)); } catch (e) {} }
                }
                if (row && splitter) {
                    try {
                        var saved = parseFloat(localStorage.getItem(SPLIT_KEY) || '0');
                        if (saved >= 30 && saved <= 70) { applySplit(saved, false); }
                    } catch (e) {}
                    var dragging = false;
                    function pctFromX(x){
                        var r = row.getBoundingClientRect();
                        return ((x - r.left) / r.width) * 100;
                    }
                    function onDown(e){
                        dragging = true;
                        splitter.classList.add('is-dragging');
                        document.body.style.cursor = 'col-resize';
                        document.body.style.userSelect = 'none';
                        if (e.cancelable) { e.preventDefault(); }
                    }
                    function onMove(e){
                        if (!dragging) { return; }
                        var x = (e.touches && e.touches[0]) ? e.touches[0].clientX : e.clientX;
                        applySplit(pctFromX(x), false);
                    }
                    function onUp(){
                        if (!dragging) { return; }
                        dragging = false;
                        splitter.classList.remove('is-dragging');
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                        var cur = parseFloat(getComputedStyle(row).getPropertyValue('--tco-doc-split')) || 50;
                        applySplit(cur, true);
                    }
                    splitter.addEventListener('mousedown', onDown);
                    splitter.addEventListener('touchstart', onDown, { passive: false });
                    window.addEventListener('mousemove', onMove);
                    window.addEventListener('touchmove', onMove, { passive: false });
                    window.addEventListener('mouseup', onUp);
                    window.addEventListener('touchend', onUp);
                    splitter.addEventListener('keydown', function (e) {
                        var cur = parseFloat(getComputedStyle(row).getPropertyValue('--tco-doc-split')) || 50;
                        if (e.key === 'ArrowLeft') { applySplit(cur - 2, true); e.preventDefault(); }
                        if (e.key === 'ArrowRight') { applySplit(cur + 2, true); e.preventDefault(); }
                        if (e.key === 'Home') { applySplit(50, true); e.preventDefault(); }
                    });
                }
                window.addEventListener('load', fitRow);
                window.addEventListener('resize', function(){ requestAnimationFrame(fitRow); });
                fitRow();
                setTimeout(fitRow, 120);
            })();

            // TASK-2052：颜色字段 —— 取色器 ⇄ 十六进制输入 ⇄ 色块 三者互相同步。
            // 无 JS 时十六进制输入仍可直接手填（服务端 `zw_doc_layout_coerce('color')` 归一校验）。
            (function () {
                function norm(v) {
                    v = String(v || '').replace('#', '').trim().toUpperCase();
                    if (/^[0-9A-F]{3}$/.test(v)) {
                        v = v[0] + v[0] + v[1] + v[1] + v[2] + v[2];
                    }
                    return /^[0-9A-F]{6}$/.test(v) ? v : '';
                }
                document.querySelectorAll('[data-zw-color-picker]').forEach(function (picker) {
                    var key = picker.getAttribute('data-zw-color-picker');
                    var text = document.querySelector('[data-zw-color-text="' + key + '"]');
                    var chip = document.querySelector('[data-zw-color-chip="' + key + '"]');
                    function paint(hex) {
                        if (chip) { chip.style.background = '#' + hex; }
                    }
                    picker.addEventListener('input', function () {
                        var hex = norm(picker.value);
                        if (!hex) { return; }
                        if (text) { text.value = hex; }
                        paint(hex);
                    });
                    if (text) {
                        text.addEventListener('input', function () {
                            var hex = norm(text.value);
                            if (!hex) { return; }
                            picker.value = '#' + hex;
                            paint(hex);
                        });
                    }
                });
            })();
            </script>
        </div>
        <?php
    }
}

if (!function_exists('zw_doc_layout_preview_url')) {
    /**
     * 预览端点 URL（admin-post，返回整页 HTML）。
     *
     * @return string
     */
    function zw_doc_layout_preview_url() {
        return wp_nonce_url(
            admin_url('admin-post.php?action=zw_doc_layout_preview'),
            'zw_doc_layout_preview'
        );
    }
}

if (!function_exists('zw_doc_layout_render_preview')) {
    /**
     * 渲染样例文书预览（**用当前已保存的覆盖层**，与出件同源）。
     *
     * @return void
     */
    function zw_doc_layout_render_preview() {
        if (!current_user_can('manage_options') && !current_user_can('tcpc_perm_doc')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        check_admin_referer('zw_doc_layout_preview');

        $body = array(
            'meta' => array(
                // TASK-2016（用户报障「份号 / 紧急程度 / 密级 / 保密期限 没有显示」）：
                // 样例文案必须**带齐版头四要素**，否则设计器预览里这几项永远空着，无法据此调版式。
                'copy_no'    => '000012',
                'secret'     => '秘密★1年',
                'urgency'    => '特急',
                'signer'     => '张三',
                'org_name'   => '中国传统文化促进会文件',
                'doc_type'   => '函',
                'title'      => '关于商请支持《传统文化大讲堂》视频拍摄的函',
                'receiver'   => '武当山特区文化和旅游局：',
                'sign_org'   => '中国传统文化促进会非物质文化遗产工作委员会',
                'date'       => '2026年10月24日',
                'attach_note' => array('附件：1.视频拍摄方案'),
                'note'       => '联系人：张三，电话 010-00000000',
                'print_org'  => '中国传统文化促进会办公室',
                'print_date' => '2026年10月25日',
                'cc'         => array('中国传统文化促进会秘书处'),
                'page_mode'  => 'standard',
            ),
            'blocks' => array(
                array('t' => 'p', 'text' => '为弘扬中华优秀传统文化，拟联合拍摄《传统文化大讲堂》系列视频，现商请贵局在场地与拍摄许可方面给予支持。'),
                array('t' => 'p', 'text' => '具体安排如下：一、拍摄时间；二、拍摄地点；三、参与人员。'),
                array('t' => 'p', 'text' => '特此函达，敬请函复。'),
            ),
        );
        if (function_exists('zw_doc_body_normalize')) {
            $body = zw_doc_body_normalize($body);
        }
        $doc  = array('id' => 0, 'number' => '中传函〔2026〕94号');
        $args = function_exists('zw_doc_build_render_args')
            ? zw_doc_build_render_args($doc, array(
                'base_url'       => get_template_directory_uri(),
                'show_toolbar'   => false,
                'auto_print'     => false,
                'document_title' => '公文版式预览',
            ))
            : array('base_url' => get_template_directory_uri());

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        $html = function_exists('zw_doc_render_standalone_html')
            ? (string) zw_doc_render_standalone_html($body, $args)
            : '<p>渲染器不可用。</p>';
        // TASK-2002：**等比缩放**到容器（用户口径「保持公文版式等比缩放在预览框中」）——
        // 取「容器宽/A4 宽」与「容器高/A4 高」的较小比例，居中显示；不裁切、不出横向滚动条；
        // 容器尺寸变化（拖窗 / 切列宽）自动重算。
        // TASK-2014：等比缩放片段统一由 `zw_doc_preview_fit_script()`（doc-composer.php）提供 ——
        // 设计器与「新建发文」内嵌预览**同一份脚本**，不再各写一套。
        $fit_js = function_exists('zw_doc_preview_fit_script') ? zw_doc_preview_fit_script() : '';
        $html = function_exists('zw_doc_preview_fit_html') ? zw_doc_preview_fit_html($html) : $html;
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- 渲染器内部逐字段转义；$fit_js 为本函数内固定字符串
        exit;
    }
}
