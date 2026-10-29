<?php
/**
 * 公文渲染通道 B：DOCX 导出（最小 OOXML）—— TASK-768 / SPEC-87。
 *
 * 设计要点：
 * - **不引第三方库**：用 `ZipArchive` 手写 OOXML（沿用站内 `branch-member-import.php` 的既有先例）；
 * - 版式参数全部取自 `zw_doc_gb9704_*`（SPEC-87 唯一出处），禁止硬编码 mm/pt；
 * - **只写国标字体名、不做字体替换**：DOCX 由用户的 Word/WPS 渲染，故写 `仿宋_GB2312` 等中文名；
 *   服务器侧 HTML/PDF 通道同样使用国标字体（TASK-1863：不再引用 OFL 近似体）；
 * - 段落序列消费 `zw_doc_body_paragraphs()`（与 HTML 通道**同源**，禁止两套实现）；
 * - **本通道不嵌印章**：印章属"正式用印件"要素，走通道 C（加密 PDF，整页栅格化）；
 *   DOCX 定位为可编辑交付件，文末与页面提示均声明这一点；
 * - 纯构建函数只吃数组与目标路径（可单测），`zw_doc_handle_export_docx()` 才触碰 WordPress。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_DOCX_VERSION')) {
    define('ZW_DOC_DOCX_VERSION', '1');
}

if (!function_exists('zw_doc_docx_available')) {
    /**
     * 运行环境是否具备导出能力。
     *
     * @return bool
     */
    function zw_doc_docx_available() {
        return class_exists('ZipArchive');
    }
}

if (!function_exists('zw_doc_docx_xml')) {
    /**
     * XML 文本转义 + 控制字符剔除。
     *
     * @param string $text 原始文本。
     * @return string
     */
    function zw_doc_docx_xml($text) {
        $text = (string) $text;
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
        if (!is_string($text)) {
            $text = '';
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

if (!function_exists('zw_doc_docx_text_xml')) {
    /**
     * 段内文本 XML（TASK-1338 批次二）—— 把制表符转为 OOXML 的制表元素。
     *
     * 版记「印发机关左空一字 / 印发日期右空一字」需要在**同一段落内两端分置**：
     * 机关文本 + 制表符 + 日期文本，靠段落右对齐制表位把日期顶到版心右缘再退一字。
     * 制表符在 OOXML 里必须是独立元素而不是文本里的制表字符，故此处统一转换；
     * 文本中无制表符时输出与旧实现**逐字节相同**（不影响既有断言）。
     *
     * @param string $text 原始文本。
     * @return string
     */
    function zw_doc_docx_text_xml($text) {
        $parts = explode("\t", (string) $text);
        $out   = '';
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $out .= '<w:tab/>';
            }
            if ($part !== '') {
                $out .= '<w:t xml:space="preserve">' . zw_doc_docx_xml($part) . '</w:t>';
            }
        }

        return $out;
    }
}

if (!function_exists('zw_doc_docx_ooxml_order_violations')) {
    /**
     * 段落属性容器 / 字面属性容器的**子元素顺序**校验（TASK-1338 批次二轮 3）。
     *
     * 背景：这两个容器在 ECMA-376 Part 1 Annex A 里定义为 **xsd:sequence（有序序列）**；
     * 子元素乱序即**无效 OOXML**。生成器此前把「段落下边框」排在段落间距之后、
     * 把「字符间距」排在加粗与颜色之前，均属乱序；Word 对乱序通常**静默修复**
     * （修复行为不可控），第三方校验器则按错误处理。
     *
     * 本函数只判定**结构化顺序**，不看属性取值（取值由各专项断言与渲染层量测负责）；
     * 仅判定公文主题名字空间下的直接子元素，外来扩展元素跳过（不计为违规）。
     * 供静态测试与证据脚本共用，避免两处各写一套顺序表（两处口径分叉是本项目已踩过的坑）。
     *
     * @param string $xml 单部件 XML 全文（如正文部件）。
     * @return array 违规描述数组；空数组表示合规。
     */
    function zw_doc_docx_ooxml_order_violations($xml) {
        $orders = array(
            'pPr' => array(
                'pStyle', 'keepNext', 'keepLines', 'pageBreakBefore', 'framePr', 'widowControl',
                'numPr', 'suppressLineNumbers', 'pBdr', 'shd', 'tabs', 'suppressAutoHyphens',
                'kinsoku', 'wordWrap', 'overflowPunct', 'topLinePunct', 'autoSpaceDE', 'autoSpaceDN',
                'bidi', 'adjustRightInd', 'snapToGrid', 'spacing', 'ind', 'contextualSpacing',
                'mirrorIndents', 'suppressOverlap', 'jc', 'textDirection', 'textAlignment',
                'textboxTightWrap', 'outlineLvl', 'divId', 'cnfStyle',
                'rPr', 'sectPr', 'pPrChange',
            ),
            'rPr' => array(
                'rStyle', 'rFonts', 'b', 'bCs', 'i', 'iCs', 'caps', 'smallCaps', 'strike', 'dstrike',
                'outline', 'shadow', 'emboss', 'imprint', 'noProof', 'snapToGrid', 'vanish',
                'webHidden', 'color', 'spacing', 'w', 'kern', 'position', 'sz', 'szCs', 'highlight',
                'u', 'effect', 'bdr', 'shd', 'fitText', 'vertAlign', 'rtl', 'cs', 'em', 'lang',
                'eastAsianLayout', 'specVanish', 'oMath', 'rPrChange',
            ),
        );

        $xml = (string) $xml;
        if (trim($xml) === '' || !class_exists('DOMDocument')) {
            return array();
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok   = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return array('XML 无法解析，无法判定元素顺序');
        }
        $xp = new DOMXPath($dom);
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $xp->registerNamespace('w', $ns);

        $violations = array();
        foreach ($orders as $container => $order) {
            $nodes = $xp->query('//w:' . $container);
            foreach ($nodes as $node) {
                $max_pos  = null;
                $max_name = '';
                foreach ($node->childNodes as $child) {
                    if ($child->nodeType !== XML_ELEMENT_NODE || $child->namespaceURI !== $ns) {
                        continue;
                    }
                    $pos = array_search($child->localName, $order, true);
                    if ($pos === false) {
                        continue;
                    }
                    if ($max_pos !== null && $pos < $max_pos) {
                        $violations[] = $container . ' 内 ' . $child->localName
                            . '（位序 ' . $pos . '）出现在 ' . $max_name . '（位序 ' . $max_pos . '）之后';
                        continue;
                    }
                    $max_pos  = $pos;
                    $max_name = $child->localName;
                }
            }
        }

        return $violations;
    }
}

if (!function_exists('zw_doc_docx_font_name')) {
    /**
     * 某版式位应写入 DOCX 的**国标字体名**（不写 OFL 替代体）。
     *
     * @param string $slot 版式位。
     * @return string
     */
    function zw_doc_docx_font_name($slot) {
        $font = function_exists('zw_doc_gb9704_font_slot') ? zw_doc_gb9704_font_slot($slot) : null;
        if (is_array($font) && !empty($font['gb'])) {
            return (string) $font['gb'];
        }

        return '仿宋_GB2312';
    }
}

if (!function_exists('zw_doc_docx_rpr')) {
    /**
     * 字符属性（rPr）：字体 + 字号（half-points）+ 可选颜色、加粗、字符间距、字符宽度缩放。
     *
     * @param string $slot   版式位。
     * @param float  $pt     字号（磅）。
     * @param array  $extras 可选：color / bold / char_spacing_pt / char_width_percent。
     * @return string
     */
    function zw_doc_docx_rpr($slot, $pt, $extras = array()) {
        $font = zw_doc_docx_font_name($slot);
        $sz   = function_exists('zw_doc_gb9704_pt_to_half_points') ? zw_doc_gb9704_pt_to_half_points($pt) : (int) round($pt * 2);

        $xml  = '<w:rPr>';
        $xml .= '<w:rFonts w:ascii="' . zw_doc_docx_xml($font) . '" w:hAnsi="' . zw_doc_docx_xml($font) . '" w:eastAsia="' . zw_doc_docx_xml($font) . '" w:cs="' . zw_doc_docx_xml($font) . '"/>';
        if (!empty($extras['bold'])) {
            $xml .= '<w:b/><w:bCs/>';
        }
        if (!empty($extras['color'])) {
            $xml .= '<w:color w:val="' . zw_doc_docx_xml(ltrim((string) $extras['color'], '#')) . '"/>';
        }
        // TASK-1278（CHG-156）引入红头字符间距；TASK-1338 批次二轮 3 **修正其序列位置**。
        // 该属性在字面属性容器的有序序列里位于**加粗与颜色之后**、**字符宽度缩放与字号之前**
        // （此前排在加粗之前，属无效 OOXML 乱序：第三方校验器按错误处理，Word 静默修复、行为不可控）。
        // 属性取值与单位（twips）完全未变，仅先后次序合规。
        if (isset($extras['char_spacing_pt']) && (float) $extras['char_spacing_pt'] > 0) {
            $spacing = function_exists('zw_doc_gb9704_pt_to_twips')
                ? zw_doc_gb9704_pt_to_twips((float) $extras['char_spacing_pt'])
                : (int) round((float) $extras['char_spacing_pt'] * 20);
            $xml .= '<w:spacing w:val="' . (int) $spacing . '"/>';
        }
        // TASK-1282（CHG-158）引入 / TASK-1287（CHG-159）沿用：**字符宽度缩放**
        // （Word「字符缩放」，`w:w` 为百分比整数 1..600）。
        // 红头**全等高**在 DOCX 侧的等价表达 = 「字号 = 宽度字号 × `scale_y`」+「宽度缩放 = `round(100/scale_y)`」
        // ⇒ 字高逐用例一致、字符步进 `scale_y × (100/scale_y) ≈ 1.0`（**行宽不变**）。
        // 位置须在 `w:spacing` / `w:color` 之后、`w:sz` 之前（OOXML CT_RPr 元素顺序）。
        if (isset($extras['char_width_percent'])) {
            $width_pct = (int) $extras['char_width_percent'];
            if ($width_pct >= 1 && $width_pct <= 600) {
                $xml .= '<w:w w:val="' . $width_pct . '"/>';
            }
        }
        $xml .= '<w:sz w:val="' . (int) $sz . '"/><w:szCs w:val="' . (int) $sz . '"/>';
        $xml .= '</w:rPr>';

        return $xml;
    }
}

if (!function_exists('zw_doc_docx_para')) {
    /**
     * 段落 XML。
     *
     * @param string $text 文本（可为空，用于绘制分隔线）。
     * @param array  $opts 选项：
     *   slot/pt/line_pt/align/indent_chars/left_chars/right_chars/tab_right_twips/space_before_pt/space_after_pt/
     *   bold/color/char_spacing_pt/char_width_percent/border_bottom_mm/border_color/keep_next。
     * @return string
     */
    function zw_doc_docx_para($text, $opts = array()) {
        $opts = array_merge(array(
            'slot'             => 'body',
            'pt'               => 16.0,
            'line_pt'          => 28.0,
            'align'            => '',
            'indent_chars'     => 0,
            'left_chars'       => 0,
            'right_chars'      => 0,
            // TASK-1338 批次二：右对齐制表位（twips）—— 用于版记「印发机关左空一字 / 印发日期右空一字」
            // 的同一行两端分置。位置由版心宽与同一批版式常量算出，禁止硬编码。
            'tab_right_twips'  => 0,
            'space_before_pt'  => 0.0,
            'space_after_pt'   => 0.0,
            'bold'             => false,
            'color'            => '',
            'char_spacing_pt'  => 0.0,
            'char_width_percent' => 0,
            'border_bottom_mm' => 0.0,
            'border_color'     => '',
            'keep_next'        => false,
        ), is_array($opts) ? $opts : array());

        $line_twips = function_exists('zw_doc_gb9704_pt_to_twips') ? zw_doc_gb9704_pt_to_twips($opts['line_pt']) : (int) round($opts['line_pt'] * 20);
        $pt_twips   = function_exists('zw_doc_gb9704_pt_to_twips') ? zw_doc_gb9704_pt_to_twips($opts['pt']) : (int) round($opts['pt'] * 20);

        $ppr  = '<w:pPr>';
        if ($opts['keep_next']) {
            $ppr .= '<w:keepNext/>';
        }
        // TASK-1338 批次二轮 3：段落边框在段落属性容器的有序序列里**最早**（仅次于分页/编号类属性），
        // 必须排在制表位、段落间距、缩进、对齐之前。此前排在最后 ⇒ 无效 OOXML 乱序
        // （红头红色反线与版记三条分隔线都走本分支，故本条对两处同时生效）。
        // 属性取值（线宽 1/8 磅、颜色）完全未变，仅先后次序合规。
        if ((float) $opts['border_bottom_mm'] > 0) {
            // 线宽单位：1/8 磅。0.35mm ≈ 0.992pt ≈ 8（1pt）。
            $mm      = (float) $opts['border_bottom_mm'];
            $eighths = max(2, (int) round(($mm / 25.4 * 72) * 8));
            $ppr    .= '<w:pBdr><w:bottom w:val="single" w:sz="' . $eighths . '" w:space="1" w:color="'
                     . zw_doc_docx_xml(ltrim((string) $opts['border_color'], '#')) . '"/></w:pBdr>';
        }
        if ((int) $opts['tab_right_twips'] > 0) {
            $ppr .= '<w:tabs><w:tab w:val="right" w:pos="' . (int) $opts['tab_right_twips'] . '"/></w:tabs>';
        }
        $ppr .= '<w:spacing w:before="' . (int) round((float) $opts['space_before_pt'] * 20) . '"'
              . ' w:after="' . (int) round((float) $opts['space_after_pt'] * 20) . '"'
              . ' w:line="' . (int) $line_twips . '" w:lineRule="exact"/>';
        $ind_attrs = '';
        if ((int) $opts['indent_chars'] > 0) {
            $ind_attrs .= ' w:firstLineChars="' . ((int) $opts['indent_chars'] * 100) . '"'
                        . ' w:firstLine="' . ((int) $opts['indent_chars'] * $pt_twips) . '"';
        }
        if ((int) $opts['left_chars'] > 0) {
            $ind_attrs .= ' w:leftChars="' . ((int) $opts['left_chars'] * 100) . '"'
                        . ' w:left="' . ((int) $opts['left_chars'] * $pt_twips) . '"';
        }
        if ((int) $opts['right_chars'] > 0) {
            $ind_attrs .= ' w:rightChars="' . ((int) $opts['right_chars'] * 100) . '"'
                        . ' w:right="' . ((int) $opts['right_chars'] * $pt_twips) . '"';
        }
        if ($ind_attrs !== '') {
            $ppr .= '<w:ind' . $ind_attrs . '/>';
        }
        if ($opts['align'] !== '') {
            $ppr .= '<w:jc w:val="' . zw_doc_docx_xml($opts['align']) . '"/>';
        }
        $ppr .= '<w:rPr>' . substr(zw_doc_docx_rpr($opts['slot'], $opts['pt'], array(
            'bold'            => $opts['bold'],
            'color'           => $opts['color'],
            'char_spacing_pt' => $opts['char_spacing_pt'],
            'char_width_percent' => $opts['char_width_percent'],
        )), strlen('<w:rPr>'), -strlen('</w:rPr>')) . '</w:rPr>';
        $ppr .= '</w:pPr>';

        $run = '';
        if ((string) $text !== '') {
            $run = '<w:r>' . zw_doc_docx_rpr($opts['slot'], $opts['pt'], array(
                'bold'            => $opts['bold'],
                'color'           => $opts['color'],
                'char_spacing_pt' => $opts['char_spacing_pt'],
                'char_width_percent' => $opts['char_width_percent'],
            )) . zw_doc_docx_text_xml($text) . '</w:r>';
        }

        return '<w:p>' . $ppr . $run . '</w:p>';
    }
}

if (!function_exists('zw_doc_docx_drawing')) {
    /**
     * 内联图片（红头）的 drawing XML。
     *
     * @param int    $rel_id 关系 ID（数字）。
     * @param int    $cx     EMU 宽。
     * @param int    $cy     EMU 高。
     * @param string $name   图片名。
     * @return string
     */
    function zw_doc_docx_drawing($rel_id, $cx, $cy, $name = 'letterhead') {
        $name = zw_doc_docx_xml($name);

        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
             . '<wp:extent cx="' . (int) $cx . '" cy="' . (int) $cy . '"/>'
             . '<wp:docPr id="' . (int) $rel_id . '" name="' . $name . '"/>'
             . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
             . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . (int) $rel_id . '" name="' . $name . '"/><pic:cNvPicPr/></pic:nvPicPr>'
             . '<pic:blipFill><a:blip r:embed="rId' . (int) $rel_id . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
             . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . (int) $cx . '" cy="' . (int) $cy . '"/></a:xfrm>'
             . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
             . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }
}

if (!function_exists('zw_doc_docx_emu_width')) {
    /**
     * 版心宽对应的 EMU（1mm = 36000 EMU）。
     *
     * @return int
     */
    function zw_doc_docx_emu_width() {
        $width_mm = (float) zw_doc_gb9704_get('header.letterhead_width_mm', 156.0);

        return (int) round($width_mm * 36000);
    }
}

if (!function_exists('zw_doc_docx_marks_text')) {
    /**
     * 版头标记文本行（TASK-1276 / CHG-155，GB/T 9704-2012 §7.2.1~7.2.3）。
     *
     * 顺序固定：份号 → 密级和保密期限 → 紧急程度；空值不产生行；三者皆空返回空数组。
     * 优先复用通道 A 的同源实现 `zw_doc_render_mark_lines()`（**禁止第二套口径**）；
     * 若该实现未加载（仅 require 导出器的极简场景），按同一常量口径就地推导。
     *
     * @param array $meta 正文 meta。
     * @return string[]
     */
    function zw_doc_docx_marks_text($meta) {
        if (function_exists('zw_doc_render_mark_lines')) {
            return (array) zw_doc_render_mark_lines($meta);
        }
        $meta  = is_array($meta) ? $meta : array();
        $lines = array();
        $copy_no = isset($meta['copy_no']) ? trim((string) $meta['copy_no']) : '';
        if ($copy_no !== '') {
            $digits = preg_replace('/\D+/', '', $copy_no);
            $width  = (int) zw_doc_gb9704_get('header.copy_no_digits', 6);
            if (!is_string($digits)) {
                $digits = '';
            }
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
        foreach (array('secret', 'urgency') as $key) {
            $value = isset($meta[$key]) ? trim((string) $meta[$key]) : '';
            if ($value !== '') {
                $lines[] = $value;
            }
        }

        return $lines;
    }
}

if (!function_exists('zw_doc_docx_body_xml')) {
    /**
     * `w:body` 主体 XML（不含 sectPr）。
     *
     * @param array $body 正文 AST。
     * @param array $args 渲染参数（doc_number / letterhead_path / letterhead_size）。
     * @return string
     */
    function zw_doc_docx_body_xml($body, $args = array()) {
        $args = array_merge(array(
            'doc_number'        => '',
            'letterhead_path'   => '',
            'letterhead_size'   => array(),
            'image_rel_id'      => 3,
        ), is_array($args) ? $args : array());

        $meta     = (isset($body['meta']) && is_array($body['meta'])) ? $body['meta'] : array();
        $text_cfg = zw_doc_gb9704_get('text', array());
        $title_cfg = zw_doc_gb9704_get('title', array());
        $head_cfg = zw_doc_gb9704_get('header', array());
        $sign_cfg = zw_doc_gb9704_get('sign', array());
        $main_cfg = zw_doc_gb9704_get('main', array());
        $record_cfg = zw_doc_gb9704_get('record', array());
        $body_pt  = isset($text_cfg['pt']) ? (float) $text_cfg['pt'] : 16.0;
        $line_pt  = isset($text_cfg['line_pt']) ? (float) $text_cfg['line_pt'] : 28.0;
        $indent   = isset($text_cfg['indent_chars']) ? (int) $text_cfg['indent_chars'] : 2;

        $blank_twips = $line_pt;
        $xml         = '';

        // —— 版头标记（TASK-1276 / CHG-155）：份号 → 密级和保密期限 → 紧急程度 ——
        // 位置在**整个版头部分最前**（发文机关标志/图片、发文字号、红色反线之前），与通道 A 同源同序；
        // 空值不输出段落，三者皆空时整块不输出。字号/字体/行高全部取常量（mark_pt / mark_slot / mark_line_pt）。
        foreach (zw_doc_docx_marks_text($meta) as $mark_line) {
            $xml .= zw_doc_docx_para($mark_line, array(
                'slot'      => (string) zw_doc_gb9704_get('header.mark_slot', 'h1'),
                'pt'        => (float) zw_doc_gb9704_get('header.mark_pt', 16.0),
                'line_pt'   => (float) zw_doc_gb9704_get('header.mark_line_pt', 28.0),
                'align'     => 'left',
                'keep_next' => true,
            ));
        }

        // —— 版头 ——
        $has_image = ($args['letterhead_path'] !== '' && is_file($args['letterhead_path']));
        if ($has_image) {
            $size = is_array($args['letterhead_size']) ? $args['letterhead_size'] : array();
            $cx   = zw_doc_docx_emu_width();
            $cy   = (isset($size[0]) && (int) $size[0] > 0)
                ? (int) round($cx * ((int) $size[1] / (int) $size[0]))
                : (int) round($cx * 0.145);
            // TASK-1338 批次二轮 3：内联段落属性里「行距」必须排在「对齐」之前（有序序列）。
            $xml .= '<w:p><w:pPr><w:spacing w:line="' . (int) zw_doc_gb9704_pt_to_twips($line_pt) . '" w:lineRule="exact"/><w:jc w:val="center"/></w:pPr>'
                  . zw_doc_docx_drawing((int) $args['image_rel_id'], $cx, $cy)
                  . '</w:p>';
            if ((string) $args['doc_number'] !== '') {
                // TASK-776：文号排在红头下一行、居右（对齐方式由 header.number_align 决定）
                // TASK-1342（GB/T 9704-2012 §7.2.6）：**有签发人**（上行文）时改为**文号居左空一字 + 签发人居右空一字、
                // 同一行** —— 复用版记「印发机关/日期」已验证的**右制表位**机制（`tab_right_twips`），
                // 制表位取与版记同一基准（版心右缘退一字 = 8564 twips），保证两处右缘对齐。
                // 残留（已登记 CHG-191）：DOCX 段落级字体单一，「签发人：」与姓名**未能分设仿宋/楷体**（HTML 通道已分设）。
                $signer_name_docx = isset($meta['signer']) ? trim((string) $meta['signer']) : '';
                $number_opts = array(
                    'slot' => 'body', 'pt' => $body_pt, 'line_pt' => $line_pt,
                    'align' => isset($head_cfg['number_align']) ? (string) $head_cfg['number_align'] : 'right',
                    'keep_next' => true,
                );
                $number_text = (string) $args['doc_number'];
                if ($signer_name_docx !== '') {
                    $number_opts['align']       = 'left';
                    $number_opts['indent_chars'] = 1;
                    $number_opts['tab_right_twips'] = 8564;
                    $number_text .= "\t" . '签发人：' . $signer_name_docx;
                }
                $xml .= zw_doc_docx_para($number_text, $number_opts);
            }
        } else {
            $org = isset($meta['org_name']) ? (string) $meta['org_name'] : '';
            // TASK-1278（CHG-156）：红头字号/字距取**唯一适配函数** `zw_doc_head_fit()`（与 HTML/PDF 同源），
            // 长机关名靠缩小字号与字距适配版心宽（DOCX 侧不强制 nowrap，靠字号收敛保证不折行）；
            // 名义字体不变（小标宋），字重保持 bold。未加载适配函数时回退到上限常量。
            $org_fit = function_exists('zw_doc_head_fit')
                ? zw_doc_head_fit($org)
                : array(
                    'pt'                => max((float) $title_cfg['pt'], (float) zw_doc_gb9704_get('header.org_pt_max', (float) $title_cfg['pt'])),
                    'letter_spacing_pt' => (float) zw_doc_gb9704_get('header.org_letter_spacing_pt', 0.0),
                );
            // TASK-1287（CHG-159）：红头**全等高（方案 C）** 在 DOCX 侧的等价表达 ——
            // 「字号 = 宽度口径字号 × scale_y」（`docx_pt`；**全等高下逐用例恒等** = 13.7 ÷ 0.86 × 72/25.4
            // = 45.1566pt）＋ OOXML **字符宽度缩放** `<w:w w:val="{docx_char_width_percent}"/>`
            // （9 字 95 / 11 字 83 / 26 字 35）⇒ **字高逐用例一致、行宽不变**（scale_y × 100/scale_y ≈ 1.0）。
            // **用户拍板留痕**：方案 C 会让 26 字需 ≈2.85× 纵向拉伸、**明显失真**，主控已明确告知、用户仍选择 C
            // ⇒ **失真属业务方决定、已获用户确认**（见 SPEC-87 §5.2⑧ 与 CHG-159，**非实现缺陷**）。
            // ⚠️ **红线**：宽度口径仍由 `zw_doc_head_fit()` 决定，**禁止**把 `scale_y` 乘进它的宽度预算
            // （否则重犯 TASK-1280 的红头溢出缺陷：26 字 15.8623pt × 2.8468 = 45.1566pt ⇒ 估算宽 ≈615mm ≫ 156mm 版心）。
            $org_stretch = function_exists('zw_doc_head_stretch')
                ? zw_doc_head_stretch($org)
                : array(
                    'scale_y'                 => 1.0,
                    'docx_pt'                 => (float) $org_fit['pt'],
                    'docx_char_width_percent' => 100,
                );
            // TASK-1282 补正（主控复核发现；TASK-1287 沿用）：DOCX 段落行距为 `lineRule="exact"`
            // （见 `zw_doc_docx_para`），拉长后的红头字号（`docx_pt` = 45.1566pt）**大于**原行高（35pt）
            // ⇒ Word 会**裁切**字形。故红头段行高取 `max(原行高, docx_pt × 1.06)`：
            // 既避免裁切，又不影响其后「空二行」的既有节奏（`docx_pt` 逐用例恒等 ⇒ 该行高亦逐用例恒等）。
            $org_line_pt = max(
                isset($title_cfg['line_pt']) ? (float) $title_cfg['line_pt'] : 35.0,
                (float) $org_stretch['docx_pt'] * 1.06
            );
            $xml .= zw_doc_docx_para($org, array(
                'slot'      => 'title',
                'pt'        => (float) $org_stretch['docx_pt'],
                'line_pt'   => $org_line_pt,
                'align'     => 'center',
                'color'     => isset($head_cfg['org_color']) ? (string) $head_cfg['org_color'] : 'D80505',
                'bold'      => true,
                'char_spacing_pt' => (float) $org_fit['letter_spacing_pt'],
                'char_width_percent' => (int) $org_stretch['docx_char_width_percent'],
                'keep_next' => true,
            ));
            // TASK-776：发文机关标志下空二行，随后是红色反线，文号排在反线**下一行、居右**。
            $org_gap_lines = max(0, (int) (isset($head_cfg['org_gap_lines']) ? $head_cfg['org_gap_lines'] : 2));
            for ($gi = 0; $gi < $org_gap_lines; $gi++) {
                $xml .= zw_doc_docx_para('', array(
                    'slot'      => 'body',
                    'pt'        => $body_pt,
                    'line_pt'   => $line_pt,
                    'keep_next' => ($gi === $org_gap_lines - 1),
                ));
            }
            // TASK-1274（用户指令「按国标调整文号位置」）：**真正实现** number_position 分支。
            // GB/T 9704-2012：发文机关标志**下空二行**排发文字号（**居中**），红色反线在文号**之下 4mm**。
            // 故顺序为 org → 空二行 → 文号 → 反线（space_before = rule_gap_mm）；
            // below_rule（TASK-776 旧口径）保留为可切换回退：org → 空二行 → 反线 → 文号。
            $number_position = isset($head_cfg['number_position']) ? (string) $head_cfg['number_position'] : 'above_rule';
            $number_above    = ($number_position !== 'below_rule');
            $number_text     = (string) $args['doc_number'];
            $number_align    = isset($head_cfg['number_align']) ? (string) $head_cfg['number_align'] : 'center';
            if ($number_above && $number_text !== '') {
                $xml .= zw_doc_docx_para($number_text, array(
                    'slot'      => 'body',
                    'pt'        => $body_pt,
                    'line_pt'   => $line_pt,
                    'align'     => $number_align,
                    'keep_next' => true,
                ));
            }
            // 版头红色分隔线（TASK-1271）：默认**双线**（上粗 0.5mm / 下细 0.25mm，两线间距 1.0mm，全公文适用）。
            // 实现要点：**不使用** w:bdr 的 val="double"（其线宽比例由 Word 固定，不可控、违反「常量唯一出处」），
            // 而是「两段各一条 bottom border」——上粗线段落保持既有几何（线落在其盒底），下细线段落以
            // space_before + line_pt 精确定出 1.0mm 间距（mm 常量经 zw_doc_gb9704_mm_to_pt 换算）。
            $rule_style    = isset($head_cfg['rule_style']) ? (string) $head_cfg['rule_style'] : 'double';
            $rule_color    = isset($head_cfg['rule_color']) ? (string) $head_cfg['rule_color'] : 'D80505';
            $rule_top_mm   = isset($head_cfg['rule_top_h_mm']) ? (float) $head_cfg['rule_top_h_mm'] : 0.5;
            $rule_bot_mm   = isset($head_cfg['rule2_h_mm']) ? (float) $head_cfg['rule2_h_mm'] : 0.25;
            $rule_gap2_mm  = isset($head_cfg['rule_gap2_mm']) ? (float) $head_cfg['rule_gap2_mm'] : 1.0;
            // 国标口径下反线在文号之下 rule_gap_mm（4mm）；旧 below_rule 口径下该间距由「空二行」承担。
            $rule_gap_mm   = isset($head_cfg['rule_gap_mm']) ? (float) $head_cfg['rule_gap_mm'] : 4.0;
            $rule_before_pt = ($number_above && $number_text !== '')
                ? (function_exists('zw_doc_gb9704_mm_to_pt') ? zw_doc_gb9704_mm_to_pt($rule_gap_mm) : ($rule_gap_mm / 25.4 * 72))
                : 0.0;
            if ($rule_style === 'double') {
                $xml .= zw_doc_docx_para('', array(
                    'slot'             => 'body',
                    'pt'               => $body_pt,
                    'line_pt'          => $line_pt,
                    'border_bottom_mm' => $rule_top_mm,
                    'border_color'     => $rule_color,
                    'space_before_pt'  => $rule_before_pt,
                    'keep_next'        => true,
                ));
                $gap_pt   = function_exists('zw_doc_gb9704_mm_to_pt') ? zw_doc_gb9704_mm_to_pt($rule_gap2_mm) : ($rule_gap2_mm / 25.4 * 72);
                $thin_pt  = 1.0; // 下细线段落的精确行高（pt），用于把间距扣准
                $xml .= zw_doc_docx_para('', array(
                    'slot'             => 'body',
                    'pt'               => $body_pt,
                    'line_pt'          => $thin_pt,
                    'border_bottom_mm' => $rule_bot_mm,
                    'border_color'     => $rule_color,
                    'space_before_pt'  => max(0, $gap_pt - $thin_pt),
                    'keep_next'        => true,
                ));
            } else {
                $xml .= zw_doc_docx_para('', array(
                    'slot'             => 'body',
                    'pt'               => $body_pt,
                    'line_pt'          => $line_pt,
                    'border_bottom_mm' => isset($head_cfg['rule_h_mm']) ? (float) $head_cfg['rule_h_mm'] : 0.35,
                    'border_color'     => $rule_color,
                    'space_before_pt'  => $rule_before_pt,
                    'keep_next'        => true,
                ));
            }
            // 旧口径（below_rule）才把文号排在反线之后。
            if (!$number_above && $number_text !== '') {
                $xml .= zw_doc_docx_para($number_text, array(
                    'slot'      => 'body',
                    'pt'        => $body_pt,
                    'line_pt'   => $line_pt,
                    'align'     => $number_align,
                    'keep_next' => true,
                ));
            }
        }

        // 红线下空二行
        $gap_lines = isset($title_cfg['gap_below_rule_lines']) ? max(0, (int) $title_cfg['gap_below_rule_lines']) : 2;
        if (!$has_image) {
            for ($i = 0; $i < $gap_lines; $i++) {
                $xml .= zw_doc_docx_para('', array('slot' => 'body', 'pt' => $body_pt, 'line_pt' => $blank_twips));
            }
        }

        // —— 标题 ——
        // TASK-1342（GB/T 9704-2012 §7.3.1）：多行标题按**梯形/菱形**回行。
        // 与 HTML 通道**同一函数** `zw_doc_title_lines()` 取得同一行数组；每行一个居中段落
        // （等效于"整块居中回行"，且不需依赖 `\n` → `<w:br/>` 的转换）；单行标题仍只出一个段落 ⇒ **零变化**。
        if (!empty($meta['title'])) {
            // ⚠️ TASK-1342 阶段五**已知缺陷（未修完，未发布）**：`zw_doc_title_lines()` 目前定义在 `doc-render-html.php`，
            // 而 **DOCX 导出路径不加载该文件** ⇒ 直接调用会 `Call to undefined function` **致命错误**（实测复现）。
            // 此处先加 `function_exists` 守卫**止血**：函数缺失时退化为"不换行"（与原实现一致，不 fatal）。
            // **真正的修法**（下一轮）：把该 canonical 函数**移到 `doc-helpers.php`**（两通道都加载的公共文件，
            // 与 `zw_doc_iso_to_cn_date()` 同样的处置），并删除 html 侧副本；随后两通道才真正同源。
            // TASK-2014：与 HTML 通道**同一解析函数**（手动折行优先，≤3 行；否则自动回行）。
            $title_lines_1342 = function_exists('zw_doc_title_resolve_lines')
                ? zw_doc_title_resolve_lines(
                    (string) $meta['title'],
                    isset($meta['title_lines']) && is_array($meta['title_lines']) ? $meta['title_lines'] : array()
                )
                : (function_exists('zw_doc_title_lines')
                    ? zw_doc_title_lines((string) $meta['title'])
                    : array((string) $meta['title']));
            foreach ($title_lines_1342 as $title_line_1342) {
                $xml .= zw_doc_docx_para($title_line_1342, array(
                    'slot'      => 'title',
                    'pt'        => isset($title_cfg['pt']) ? (float) $title_cfg['pt'] : 22.0,
                    'line_pt'   => isset($title_cfg['line_pt']) ? (float) $title_cfg['line_pt'] : 35.0,
                    'align'     => 'center',
                    'bold'      => true,
                    'keep_next' => true,
                ));
            }
        }

        // —— 标题 → 正文第一行：**无主送机关时**空一行（TASK-1340 阶段三，用户口径：「公文标题和正文第一行 各留一行」） ——
        // 有主送机关时该间距由下方 `main.receiver_gap_lines` 承担（DOCX 空段是**加法**，不像 HTML 边距会合并取 max，
        // 故此处必须按"无主送机关才插空段"处理，否则会叠成 2 行 —— 两通道即不一致）。
        if (empty($meta['receiver'])) {
            $title_below_lines = zw_doc_gb9704_gap_lines('title.gap_below_title_lines', 1);
            for ($tb = 0; $tb < $title_below_lines; $tb++) {
                $xml .= zw_doc_docx_para('', array(
                    'slot'      => 'body',
                    'pt'        => $body_pt,
                    'line_pt'   => $line_pt,
                    'keep_next' => ($tb === $title_below_lines - 1),
                ));
            }
        }

        // —— 主送机关（标题下空一行、左顶格、全角冒号） ——
        if (!empty($meta['receiver'])) {
            // TASK-1333 ①：标题下「空一行」由空段表达，空行数与行高均取版式常量，
            // 与 HTML 通道所写间距**同一参数**（该通道经同一取值函数换算为磅值）。
            $receiver_gap_lines = zw_doc_gb9704_gap_lines('main.receiver_gap_lines', 1);
            for ($ri = 0; $ri < $receiver_gap_lines; $ri++) {
                $xml .= zw_doc_docx_para('', array(
                    'slot'      => 'body',
                    'pt'        => $body_pt,
                    'line_pt'   => $line_pt,
                    'keep_next' => ($ri === $receiver_gap_lines - 1),
                ));
            }
            $receiver = (string) $meta['receiver'];
            $colon    = isset($main_cfg['receiver_trailing_colon']) ? (string) $main_cfg['receiver_trailing_colon'] : '：';
            if ($colon !== '' && substr($receiver, -strlen($colon)) !== $colon && substr($receiver, -3) !== '：') {
                $receiver .= $colon;
            }
            $xml .= zw_doc_docx_para($receiver, array(
                'slot' => 'body', 'pt' => $body_pt, 'line_pt' => $line_pt,
            ));
        }

        // —— 正文块（序数来自正文模型，与 HTML 通道同源） ——
        foreach (zw_doc_body_paragraphs($body) as $para) {
            $slot = in_array($para['t'], array('h1', 'h2', 'h3', 'h4'), true) ? $para['t'] : 'body';
            $xml .= zw_doc_docx_para($para['display'], array(
                'slot'        => $slot,
                'pt'          => $body_pt,
                'line_pt'     => $line_pt,
                'indent_chars' => $indent,
                'bold'        => ($slot === 'h1'),
            ));
        }

        // —— 附件说明（TASK-1333 ②：正文下空一行，且**位于落款之前**） ——
        $attach_notes     = (!empty($meta['attach_note']) && is_array($meta['attach_note'])) ? array_values($meta['attach_note']) : array();
        $attach_gap_lines = zw_doc_gb9704_gap_lines('main.attach_gap_lines', 1);
        if (!empty($attach_notes)) {
            for ($ai = 0; $ai < $attach_gap_lines; $ai++) {
                $xml .= zw_doc_docx_para('', array(
                    'slot'      => 'body',
                    'pt'        => $body_pt,
                    'line_pt'   => $line_pt,
                    'keep_next' => true,
                ));
            }
            $attach_indent = max(0, (int) zw_doc_gb9704_get('main.attach_indent_chars', 2));
            foreach ($attach_notes as $attach_line) {
                $xml .= zw_doc_docx_para((string) $attach_line, array(
                    'slot'         => 'body',
                    'pt'           => $body_pt,
                    'line_pt'      => $line_pt,
                    'indent_chars' => $attach_indent,
                ));
            }
        }

        // —— 落款（TASK-1333 ②：与附件说明之间空一行；③：右空字距与 HTML 通道共用同一对参数） ——
        // 本通道**不嵌印章**，故落款恒定按「不加印章」口径取右空字距；有章口径由同一取值函数提供，
        // 供 HTML 通道使用，两通道**不存在各自取值的余地**。
        $sign_has_content = (!empty($meta['sign_org']) || !empty($meta['date']) || !empty($meta['note']));
        if ($sign_has_content) {
            $sign_gap_lines = zw_doc_gb9704_gap_lines('sign.gap_lines_before_org', 1);
            for ($si = 0; $si < $sign_gap_lines; $si++) {
                $xml .= zw_doc_docx_para('', array(
                    'slot'      => 'body',
                    'pt'        => $body_pt,
                    'line_pt'   => $line_pt,
                    'keep_next' => true,
                ));
            }
        }
        $sign_indents = zw_doc_gb9704_sign_indents(false);
        if (!empty($meta['sign_org'])) {
            $xml .= zw_doc_docx_para((string) $meta['sign_org'], array(
                'slot' => 'h2', 'pt' => $body_pt, 'line_pt' => $line_pt, 'align' => 'right',
                'right_chars' => (int) $sign_indents['org_chars'],
            ));
        }
        if (!empty($meta['date'])) {
            $xml .= zw_doc_docx_para((string) $meta['date'], array(
                'slot' => 'h2', 'pt' => $body_pt, 'line_pt' => $line_pt, 'align' => 'right',
                'right_chars' => (int) $sign_indents['date_chars'],
            ));
        }
        if (!empty($meta['note'])) {
            // TASK-1333 ④：附注居左空二字（与 HTML 通道同一参数），在成文日期下一行。
            $xml .= zw_doc_docx_para('（' . (string) $meta['note'] . '）', array(
                'slot' => 'body', 'pt' => $body_pt, 'line_pt' => $line_pt,
                'indent_chars' => max(0, (int) zw_doc_gb9704_get('main.note_indent_chars', 2)),
            ));
        }

        // —— 版记（TASK-1338 批次二：① 三条分隔线 ② 抄送句号 ③ 印发左右各空一字 ⑦ D1 版记合并） ——
        $cc = isset($meta['cc']) ? (string) $meta['cc'] : '';
        // ⑦ D1：印发机关 / 印发日期 = 公文要素（发文部门 / 发文日期）；存量显式值不覆盖。
        // 取值经与 HTML 通道**同一个**函数，禁止本通道另写默认值口径。
        $doc_department = isset($args['doc_department']) ? (string) $args['doc_department'] : '';
        $doc_issue_date = isset($args['doc_issue_date']) ? (string) $args['doc_issue_date'] : '';
        if (function_exists('zw_doc_record_print_fields')) {
            $print = zw_doc_record_print_fields($meta, $doc_department, $doc_issue_date);
        } else {
            $print = array(
                'print_org'  => isset($meta['print_org']) ? (string) $meta['print_org'] : '',
                'print_date' => isset($meta['print_date']) ? (string) $meta['print_date'] : '',
            );
        }
        $print_org  = (string) $print['print_org'];
        $print_date = (string) $print['print_date'];
        // ② 抄送文本（末尾标句号）经与 HTML 通道**同一个**函数取值。
        $cc_text = function_exists('zw_doc_gb9704_record_cc_text')
            ? (string) zw_doc_gb9704_record_cc_text($cc)
            : trim((string) $cc);
        if ($cc_text !== '' || $print_org !== '' || $print_date !== '') {
            $record_pt   = isset($record_cfg['pt']) ? (float) $record_cfg['pt'] : 14.0;
            $record_line = $record_pt * 1.6;
            // ① 线型与条数取版式常量唯一入口（首条粗 / 中间细 / 末条粗）。
            $record_rules = function_exists('zw_doc_gb9704_record_rules')
                ? zw_doc_gb9704_record_rules()
                : array('thick_mm' => 0.35, 'thin_mm' => 0.25);
            $record_side  = function_exists('zw_doc_gb9704_record_side_chars') ? (int) zw_doc_gb9704_record_side_chars() : 1;
            // ① 条数与线型序列取唯一实现（与 HTML 通道**同一个**函数）：
            //    两行要素 ⇒ 粗 / 细 / 粗；只有一行要素 ⇒ 粗 / 粗（旧实现在此多出一条无内容的中间细线）。
            $has_cc    = ($cc_text !== '');
            $has_print = ($print_org !== '' || $print_date !== '');
            $rule_plan = function_exists('zw_doc_gb9704_record_rule_plan')
                ? zw_doc_gb9704_record_rule_plan($has_cc, $has_print)
                : array('thick', ($has_cc && $has_print) ? 'thin' : 'thick', 'thick');
            $rule_xml  = array(
                'thick' => zw_doc_docx_para('', array(
                    'slot' => 'body', 'pt' => $record_pt, 'line_pt' => $record_line,
                    'border_bottom_mm' => (float) $record_rules['thick_mm'],
                    'border_color'     => '000000',
                )),
                'thin'  => zw_doc_docx_para('', array(
                    'slot' => 'body', 'pt' => $record_pt, 'line_pt' => $record_line,
                    'border_bottom_mm' => (float) $record_rules['thin_mm'],
                    'border_color'     => '000000',
                )),
            );

            $record_rows = array();
            if ($has_cc) {
                $record_rows[] = zw_doc_docx_para('抄送：' . $cc_text, array('slot' => 'body', 'pt' => $record_pt, 'line_pt' => $record_line));
            }
            if ($has_print) {
                // ③ 印发机关左空一字（段落左字符缩进）＋ 印发日期右空一字（右对齐制表位 = 版心宽 − 一字）。
                $tab_pos = 0;
                if (function_exists('zw_doc_gb9704_text_box_mm') && function_exists('zw_doc_gb9704_mm_to_twips')) {
                    $box_mm  = zw_doc_gb9704_text_box_mm();
                    $tab_pos = zw_doc_gb9704_mm_to_twips(isset($box_mm['w_mm']) ? $box_mm['w_mm'] : 156.0)
                             - $record_side * (int) round($record_pt * 20);
                    if ($tab_pos < 0) {
                        $tab_pos = 0;
                    }
                }
                $record_rows[] = zw_doc_docx_para($print_org . "\t" . $print_date . '印发', array(
                    'slot' => 'body', 'pt' => $record_pt, 'line_pt' => $record_line,
                    'left_chars' => $record_side,
                    'tab_right_twips' => $tab_pos,
                ));
            }

            // 首条恒为粗线（plan[0]），其后每行要素后接 plan 里的下一条线（末条为粗线）。
            $xml .= $rule_xml[$rule_plan[0] === 'thin' ? 'thin' : 'thick'];
            foreach ($record_rows as $ri => $row_xml) {
                $xml .= $row_xml;
                $next = isset($rule_plan[$ri + 1]) ? (string) $rule_plan[$ri + 1] : '';
                if ($next === 'thin' || $next === 'thick') {
                    $xml .= $rule_xml[$next];
                }
            }
        }

        // —— 通道声明（DOCX 不带印章、字体由本机决定） ——
        $xml .= zw_doc_docx_para('（本文件为可编辑排版件，未加盖印章；如需用印请走正式用印件流程生成加密 PDF。本文件只写入国标字体名，若本机未安装 仿宋_GB2312 / 方正小标宋简体，Word/WPS 会以替代字体显示。）', array(
            'slot' => 'body', 'pt' => 10.5, 'line_pt' => 16.0, 'indent_chars' => 0,
        ));

        return $xml;
    }
}

if (!function_exists('zw_doc_docx_sect_pr')) {
    /**
     * 节属性：页面尺寸、页边距、页脚引用。
     *
     * @param string $page_mode 页码模式。
     * @param array  $rels      关系映射：footer_default / footer_even。
     * @return string
     */
    function zw_doc_docx_sect_pr($page_mode, $rels = array()) {
        $setup = zw_doc_gb9704_docx_page_setup();
        $mode  = in_array($page_mode, zw_doc_body_page_modes(), true) ? $page_mode : 'standard';

        $xml = '<w:sectPr>';
        if ($mode !== 'none') {
            if ($mode === 'standard') {
                if (!empty($rels['footer_even'])) {
                    $xml .= '<w:footerReference w:type="even" r:id="' . zw_doc_docx_xml($rels['footer_even']) . '"/>';
                }
                if (!empty($rels['footer_default'])) {
                    $xml .= '<w:footerReference w:type="default" r:id="' . zw_doc_docx_xml($rels['footer_default']) . '"/>';
                }
            } elseif (!empty($rels['footer_default'])) {
                $xml .= '<w:footerReference w:type="default" r:id="' . zw_doc_docx_xml($rels['footer_default']) . '"/>';
            }
        }
        $xml .= '<w:pgSz w:w="' . (int) $setup['pgSz']['w'] . '" w:h="' . (int) $setup['pgSz']['h'] . '"/>';
        $xml .= '<w:pgMar w:top="' . (int) $setup['pgMar']['top'] . '" w:right="' . (int) $setup['pgMar']['right']
              . '" w:bottom="' . (int) $setup['pgMar']['bottom'] . '" w:left="' . (int) $setup['pgMar']['left']
              . '" w:header="851" w:footer="992" w:gutter="0"/>';
        $xml .= '</w:sectPr>';

        return $xml;
    }
}

if (!function_exists('zw_doc_docx_footer_xml')) {
    /**
     * 页脚 XML：4 号半角宋体 + 一字线 + PAGE 域。
     *
     * @param string $align left|right|center。
     * @return string
     */
    function zw_doc_docx_footer_xml($align = 'right') {
        $footer_cfg = zw_doc_gb9704_get('footer', array());
        $pt         = isset($footer_cfg['pt']) ? (float) $footer_cfg['pt'] : 14.0;
        $dash       = isset($footer_cfg['dash']) ? (string) $footer_cfg['dash'] : '—';
        $rpr        = zw_doc_docx_rpr('page', $pt);
        // TASK-1338 批次二 ④（GB/T 9704-2012 §7.5）：单页码居右**空一字**、双页码居左**空一字**。
        // 「一字」= 页码自身 4 号字宽；取与 HTML 通道**同一参数键**（`footer.side_indent_chars`），
        // 本通道以段落字符缩进表达。
        $side_chars = function_exists('zw_doc_gb9704_footer_indent_chars') ? (int) zw_doc_gb9704_footer_indent_chars() : 0;
        $ind        = '';
        if ($side_chars > 0) {
            $chars_twips = (int) round($pt * 20);
            if ($align === 'right') {
                $ind = '<w:ind w:rightChars="' . ($side_chars * 100) . '" w:right="' . ($side_chars * $chars_twips) . '"/>';
            } elseif ($align === 'left') {
                $ind = '<w:ind w:leftChars="' . ($side_chars * 100) . '" w:left="' . ($side_chars * $chars_twips) . '"/>';
            }
        }

        $p  = '<w:p><w:pPr>' . $ind . '<w:jc w:val="' . zw_doc_docx_xml($align) . '"/>'
            . '<w:rPr>' . substr($rpr, strlen('<w:rPr>'), -strlen('</w:rPr>')) . '</w:rPr></w:pPr>';
        $p .= '<w:r>' . $rpr . '<w:t xml:space="preserve">' . zw_doc_docx_xml($dash . ' ') . '</w:t></w:r>';
        $p .= '<w:r>' . $rpr . '<w:fldChar w:fldCharType="begin"/></w:r>';
        $p .= '<w:r>' . $rpr . '<w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>';
        $p .= '<w:r>' . $rpr . '<w:fldChar w:fldCharType="separate"/></w:r>';
        $p .= '<w:r>' . $rpr . '<w:t>1</w:t></w:r>';
        $p .= '<w:r>' . $rpr . '<w:fldChar w:fldCharType="end"/></w:r>';
        $p .= '<w:r>' . $rpr . '<w:t xml:space="preserve"> ' . zw_doc_docx_xml($dash) . '</w:t></w:r>';
        $p .= '</w:p>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
             . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
             . $p . '</w:ftr>';
    }
}

if (!function_exists('zw_doc_docx_styles_xml')) {
    /**
     * 最小 styles.xml（docDefaults + Normal）。
     *
     * @return string
     */
    function zw_doc_docx_styles_xml() {
        $text_cfg = zw_doc_gb9704_get('text', array());
        $font     = zw_doc_docx_font_name('body');
        $sz       = zw_doc_gb9704_pt_to_half_points(isset($text_cfg['pt']) ? (float) $text_cfg['pt'] : 16.0);
        $line     = zw_doc_gb9704_pt_to_twips(isset($text_cfg['line_pt']) ? (float) $text_cfg['line_pt'] : 28.0);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
             . '<w:docDefaults><w:rPrDefault><w:rPr>'
             . '<w:rFonts w:ascii="' . zw_doc_docx_xml($font) . '" w:hAnsi="' . zw_doc_docx_xml($font) . '" w:eastAsia="' . zw_doc_docx_xml($font) . '"/>'
             . '<w:sz w:val="' . (int) $sz . '"/><w:szCs w:val="' . (int) $sz . '"/>'
             . '</w:rPr></w:rPrDefault><w:pPrDefault><w:pPr>'
             . '<w:spacing w:line="' . (int) $line . '" w:lineRule="exact"/>'
             . '</w:pPr></w:pPrDefault></w:docDefaults>'
             . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
             . '</w:styles>';
    }
}

if (!function_exists('zw_doc_docx_settings_xml')) {
    /**
     * settings.xml（standard 模式需开启奇偶页不同页脚）。
     *
     * @param bool $even_and_odd 是否启用奇偶页不同。
     * @return string
     */
    function zw_doc_docx_settings_xml($even_and_odd = false) {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
             . ($even_and_odd ? '<w:evenAndOddHeaders/>' : '')
             . '</w:settings>';
    }
}

if (!function_exists('zw_doc_build_docx')) {
    /**
     * 构建 DOCX 到目标路径（纯函数：只吃数组与路径）。
     *
     * @param array  $body  正文 AST。
     * @param array  $args  渲染参数。
     * @param string $target 目标文件绝对路径。
     * @return true|WP_Error
     */
    function zw_doc_build_docx($body, $args = array(), $target = '') {
        if (!zw_doc_docx_available()) {
            return new WP_Error('zw_doc_docx_no_zip', '服务器缺少 ZipArchive 扩展，无法导出 DOCX。');
        }
        $target = (string) $target;
        if ($target === '') {
            return new WP_Error('zw_doc_docx_no_target', '未指定导出目标路径。');
        }
        if (!is_array($body) || !function_exists('zw_doc_body_paragraphs')) {
            return new WP_Error('zw_doc_docx_no_body', '正文模型未加载或正文为空。');
        }

        $args = array_merge(array(
            'doc_number'      => '',
            'letterhead_path' => '',
            'letterhead_size' => array(),
            'image_rel_id'    => 3,
        ), is_array($args) ? $args : array());

        $meta      = (isset($body['meta']) && is_array($body['meta'])) ? $body['meta'] : array();
        $page_mode = isset($meta['page_mode']) ? (string) $meta['page_mode'] : 'standard';
        if (!in_array($page_mode, zw_doc_body_page_modes(), true)) {
            $page_mode = 'standard';
        }

        $has_image = ($args['letterhead_path'] !== '' && is_file($args['letterhead_path']));
        $image_ext = '';
        $image_data = '';
        if ($has_image) {
            $image_data = (string) @file_get_contents($args['letterhead_path']);
            $image_ext  = strtolower((string) pathinfo($args['letterhead_path'], PATHINFO_EXTENSION));
            if ($image_data === '' || !in_array($image_ext, array('png', 'jpg', 'jpeg'), true)) {
                $has_image  = false;
                $image_data = '';
            }
        }

        // 关系 ID 分配
        $rid_styles  = 'rId1';
        $rid_settings = 'rId2';
        $rid_footer1  = 'rId3';
        $rid_footer2  = 'rId4';
        $rid_image    = 'rId5';
        $args['image_rel_id'] = 5;

        $rels = array();
        if ($page_mode !== 'none') {
            $rels['footer_default'] = $rid_footer1;
            if ($page_mode === 'standard') {
                $rels['footer_even'] = $rid_footer2;
            }
        }

        $document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<w:body>' . zw_doc_docx_body_xml($body, $args) . zw_doc_docx_sect_pr($page_mode, $rels) . '</w:body></w:document>';

        // [Content_Types].xml
        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>';
        if ($has_image) {
            $types .= '<Default Extension="' . zw_doc_docx_xml($image_ext === 'jpeg' ? 'jpg' : $image_ext) . '" ContentType="image/' . ($image_ext === 'png' ? 'png' : 'jpeg') . '"/>';
        }
        $types .= '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>';
        if ($page_mode !== 'none') {
            $types .= '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>';
            if ($page_mode === 'standard') {
                $types .= '<Override PartName="/word/footer2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>';
            }
        }
        $types .= '</Types>';

        $root_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $doc_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="' . $rid_styles . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="' . $rid_settings . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>';
        if ($page_mode !== 'none') {
            $doc_rels .= '<Relationship Id="' . $rid_footer1 . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
            if ($page_mode === 'standard') {
                $doc_rels .= '<Relationship Id="' . $rid_footer2 . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer2.xml"/>';
            }
        }
        if ($has_image) {
            $doc_rels .= '<Relationship Id="' . $rid_image . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/header.' . ($image_ext === 'jpeg' ? 'jpg' : $image_ext) . '"/>';
        }
        $doc_rels .= '</Relationships>';

        $zip = new ZipArchive();
        if (true !== $zip->open($target, ZipArchive::OVERWRITE | ZipArchive::CREATE)) {
            return new WP_Error('zw_doc_docx_zip', '无法创建 DOCX 文件。');
        }
        $zip->addFromString('[Content_Types].xml', $types);
        $zip->addFromString('_rels/.rels', $root_rels);
        $zip->addFromString('word/document.xml', $document_xml);
        $zip->addFromString('word/_rels/document.xml.rels', $doc_rels);
        $zip->addFromString('word/styles.xml', zw_doc_docx_styles_xml());
        $zip->addFromString('word/settings.xml', zw_doc_docx_settings_xml($page_mode === 'standard'));
        if ($page_mode === 'center') {
            $zip->addFromString('word/footer1.xml', zw_doc_docx_footer_xml('center'));
        } elseif ($page_mode === 'standard') {
            $zip->addFromString('word/footer1.xml', zw_doc_docx_footer_xml('right'));
            $zip->addFromString('word/footer2.xml', zw_doc_docx_footer_xml('left'));
        }
        if ($has_image) {
            $zip->addFromString('word/media/header.' . ($image_ext === 'jpeg' ? 'jpg' : $image_ext), $image_data);
        }
        $zip->close();

        if (!is_file($target) || filesize($target) <= 0) {
            return new WP_Error('zw_doc_docx_empty', 'DOCX 生成失败（产物为空）。');
        }

        return true;
    }
}

if (!function_exists('zw_doc_docx_export_url')) {
    /**
     * 导出 URL（带 nonce）。
     *
     * @param int $document_id 发文 ID。
     * @return string
     */
    function zw_doc_docx_export_url($document_id) {
        $url = add_query_arg(array(
            'action' => 'zw_doc_export_docx',
            'doc_id' => (int) $document_id,
        ), admin_url('admin-post.php'));

        return wp_nonce_url($url, 'zw_doc_export_docx_' . (int) $document_id);
    }
}

if (!function_exists('zw_doc_docx_letterhead_path')) {
    /**
     * 解析红头图片的**本地文件路径**（DOCX 需要文件字节，不是 URL）。
     *
     * @param array $doc 发文记录。
     * @return string 不存在则空串。
     */
    function zw_doc_docx_letterhead_path($doc) {
        if (!function_exists('zw_doc_letterhead_resolve')) {
            return '';
        }
        $letterhead = zw_doc_letterhead_resolve($doc);

        return (string) $letterhead['path'];
    }
}

if (!function_exists('zw_doc_handle_export_docx')) {
    /**
     * DOCX 导出（admin-post）。
     *
     * 鉴权：功能开关 + `manage_options` + nonce（沿用发文模块既有口径；权限收敛见 TASK-771）。
     *
     * @return void
     */
    function zw_doc_handle_export_docx() {
        $doc_id = isset($_GET['doc_id']) ? (int) $_GET['doc_id'] : 0;

        if (!function_exists('zw_doc_is_enabled') || !zw_doc_is_enabled()) {
            wp_die('发文管理未启用。', '不可用', array('response' => 403));
        }
        check_admin_referer('zw_doc_export_docx_' . $doc_id);

        $doc = function_exists('zw_doc_get_document') ? zw_doc_get_document($doc_id) : null;
        if (!$doc) {
            wp_die('发文不存在。', '未找到', array('response' => 404));
        }
        // 权限收敛（TASK-772）：管理员 / 作者 / 审核人 / 抄送人可导出。
        if (function_exists('zw_doc_can_view_doc') && !zw_doc_can_view_doc($doc)) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        if (empty($doc['body'])) {
            wp_die('该发文没有在线撰写的正文，无法导出 DOCX。', '无正文', array('response' => 404));
        }
        // TASK-1422 **fail-closed**：切到「使用上传的红头文件（图片）」却无可用已启用红头时，
        // DOCX 通道直接拒出（409 + 可操作提示），**不产出「静默回落」或空白红头的 DOCX**。
        $lh_gate = function_exists('zw_doc_letterhead_mode_gate') ? zw_doc_letterhead_mode_gate() : true;
        if (is_wp_error($lh_gate)) {
            wp_die(esc_html($lh_gate->get_error_message()), '红头素材缺失', array('response' => 409));
        }

        // 红头图片本地路径：唯一解析入口 `zw_doc_letterhead_resolve()`（TASK-770）。
        // TASK-1422：`auto`（默认，系统自动生成）时恒为空串 ⇒ DOCX 走常量绘制（文字机关标志 + 红色分隔线）。
        $letterhead = zw_doc_docx_letterhead_path($doc);
        $size       = ($letterhead !== '') ? @getimagesize($letterhead) : false;
        $args       = array(
            'doc_number'      => (string) $doc['number'],
            'letterhead_path' => $letterhead,
            'letterhead_size' => is_array($size) ? array((int) $size[0], (int) $size[1]) : array(),
            // TASK-1338 批次二 ⑦（D1 版记合并）：公文要素真值源（发文部门 / 发文日期）随渲染参数下发，
            // 与 HTML 通道**同一批参数名**；版记在「未显式保存印发值」时回落取值。
            'doc_department'  => isset($doc['department']) ? (string) $doc['department'] : '',
            'doc_issue_date'  => isset($doc['issue_date']) ? (string) $doc['issue_date'] : '',
        );

        $tmp = function_exists('wp_tempnam') ? wp_tempnam('zw-doc-' . $doc_id . '.docx') : tempnam(sys_get_temp_dir(), 'zwdoc');
        if (!$tmp) {
            wp_die('无法创建临时文件。', '导出失败', array('response' => 500));
        }

        $built = zw_doc_build_docx($doc['body'], $args, $tmp);
        if (is_wp_error($built)) {
            @unlink($tmp);
            wp_die(esc_html($built->get_error_message()), '导出失败', array('response' => 500));
        }

        $base     = 'zw-doc-' . (int) $doc['id'];
        if ((string) $doc['number'] !== '') {
            $base .= '-' . (string) $doc['number'];
        }
        $filename = sanitize_file_name($base . '.docx');

        if (function_exists('zw_doc_log_download')) {
            zw_doc_log_download((int) $doc['id'], 0, $filename, 'success');
        }

        nocache_headers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($tmp));
        header('X-Content-Type-Options: nosniff');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
