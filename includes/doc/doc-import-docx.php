<?php
/**
 * 上传 Word(.docx) 导入公文正文 —— TASK-783 / SPEC-87。
 *
 * 只做一件事：把 `.docx` 的 `word/document.xml` 解析成正文块数组，
 * 复用与「整篇粘贴」**同一套**返回结构与序数实现，前端因此共用同一套填充逻辑。
 *
 * 层级判定优先级（**可预测优先于聪明**）：
 *   ① Word 标题样式（`w:pStyle` 为 HeadingN / 标题N，或 `w:outlineLvl`）→ h1~h4
 *   ② 文本自带层次序数（复用 TASK-781 唯一实现，**并剥离序数**由系统重新编号）
 *   ③ Word 自动编号列表 `w:numPr@w:ilvl`（编号本身不在 XML 里，正好交给系统生成）
 *   ④ 其余 → 正文段
 *
 * 安全（上传解析类功能必须过审）：
 * - 只接受 `.docx`；用 `ZipArchive` 验证**确实是 zip** 且含 `word/document.xml`；
 * - **只按名称读取 `word/document.xml`，绝不落盘、绝不按路径解压**（无路径穿越面）；
 * - 解析前限制 XML 解压后体积（防 zip bomb）；
 * - XML 用 `DOMDocument` 解析并**禁用外部实体**（`LIBXML_NONET`、不传 `LIBXML_NOENT`/`LIBXML_DTDLOAD`，
 *   且在可用时显式关闭外部实体加载器）→ 防 XXE / SSRF；
 * - 全程只读临时上传文件，不写入任何目录、不入库、不外传。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_DOCX_IMPORT_VERSION')) {
    define('ZW_DOC_DOCX_IMPORT_VERSION', '1');
}

if (!function_exists('zw_doc_docx_import_limits')) {
    /**
     * 导入限制（集中一处，便于评审与测试）。
     *
     * @return array
     */
    function zw_doc_docx_import_limits() {
        return array(
            'max_upload_bytes' => 10 * 1024 * 1024, // 上传文件 ≤10MB
            'max_xml_bytes'    => 8 * 1024 * 1024,  // document.xml 解压后 ≤8MB（防 zip bomb）
            'max_tables_note'  => 50,               // 仅用于提示文案
        );
    }
}

if (!function_exists('zw_doc_docx_validate_upload')) {
    /**
     * 校验上传的 `.docx`（返回文件信息，不落盘）。
     *
     * @param array $file `$_FILES` 单项。
     * @return array{path:string,name:string,bytes:int}|WP_Error
     */
    function zw_doc_docx_validate_upload($file) {
        $limits = zw_doc_docx_import_limits();

        if (!is_array($file) || empty($file['tmp_name']) || empty($file['name'])) {
            return new WP_Error('zw_doc_docx_missing', '未收到上传文件。');
        }
        if (isset($file['error']) && (int) $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('zw_doc_docx_upload_error', '文件上传失败（错误码 ' . (int) $file['error'] . '）。');
        }
        // 先做零风险的形状校验（扩展名/体积），fail fast 且提示更准确；
        $name = (string) $file['name'];
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'docx') {
            return new WP_Error('zw_doc_docx_ext', '只接受 .docx 文件（Word 2007 及以上）。');
        }
        // 任何文件访问之前，必须确认它确实来自 HTTP 上传（防本地路径被当作上传件）
        if (!function_exists('is_uploaded_file') || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('zw_doc_docx_not_uploaded', '文件来源不可信，已拒绝。');
        }

        $bytes = (int) @filesize($file['tmp_name']);
        if ($bytes <= 0) {
            return new WP_Error('zw_doc_docx_empty', '文件为空。');
        }
        if ($bytes > (int) $limits['max_upload_bytes']) {
            return new WP_Error('zw_doc_docx_too_big', '文件超过上限（' . (int) round($limits['max_upload_bytes'] / 1048576) . 'MB）。');
        }

        if (!class_exists('ZipArchive')) {
            return new WP_Error('zw_doc_docx_no_zip', '服务器缺少 PHP zip 扩展，无法读取 .docx。');
        }

        return array('path' => (string) $file['tmp_name'], 'name' => $name, 'bytes' => $bytes);
    }
}

if (!function_exists('zw_doc_docx_read_document_xml')) {
    /**
     * 从 `.docx` 中**按名称**取出 `word/document.xml`（不解压到磁盘）。
     *
     * @param string $path 文件路径。
     * @return string|WP_Error
     */
    function zw_doc_docx_read_document_xml($path) {
        $limits = zw_doc_docx_import_limits();
        $zip    = new ZipArchive();
        // 用 RDONLY：只读打开，绝不解压/写入
        if ($zip->open((string) $path, ZipArchive::RDONLY) !== true) {
            return new WP_Error('zw_doc_docx_not_zip', '文件不是有效的 .docx（zip）包。');
        }

        $index = $zip->locateName('word/document.xml');
        if ($index === false) {
            $zip->close();

            return new WP_Error('zw_doc_docx_no_document', '不是 Word 文档：缺少 word/document.xml。');
        }
        $stat = $zip->statIndex($index);
        if (is_array($stat) && isset($stat['size']) && (int) $stat['size'] > (int) $limits['max_xml_bytes']) {
            $zip->close();

            return new WP_Error('zw_doc_docx_xml_too_big', '文档正文过大（解压后超过 ' . (int) round($limits['max_xml_bytes'] / 1048576) . 'MB），请拆分后分次导入。');
        }

        $xml = $zip->getFromIndex($index);
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            return new WP_Error('zw_doc_docx_xml_empty', '无法读取文档正文。');
        }
        if (strlen($xml) > (int) $limits['max_xml_bytes']) {
            return new WP_Error('zw_doc_docx_xml_too_big', '文档正文过大，请拆分后分次导入。');
        }

        return $xml;
    }
}

if (!function_exists('zw_doc_docx_parse_xml')) {
    /**
     * 安全解析 XML（**禁用外部实体**，防 XXE/SSRF）。
     *
     * @param string $xml XML 文本。
     * @return DOMDocument|WP_Error
     */
    function zw_doc_docx_parse_xml($xml) {
        if (!class_exists('DOMDocument')) {
            return new WP_Error('zw_doc_docx_no_dom', '服务器缺少 PHP dom 扩展，无法解析 .docx。');
        }
        // 显式关闭外部实体加载（libxml ≥2.9 默认已禁网，此处再加一道并留痕）
        // 注意：该函数返回 bool（成功与否），**不是**上一个加载器，故恢复时只能传 null 还原默认。
        $has_loader_api = function_exists('libxml_set_external_entity_loader');
        if ($has_loader_api) {
            libxml_set_external_entity_loader(function () {
                return null;
            });
        }
        $previous_errors = libxml_use_internal_errors(true);

        $dom  = new DOMDocument();
        // 关键：**不传** LIBXML_NOENT / LIBXML_DTDLOAD（这两个才会展开实体）
        $ok   = $dom->loadXML((string) $xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        $errs = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous_errors);
        if ($has_loader_api) {
            libxml_set_external_entity_loader(null);
        }

        if (!$ok) {
            $first = !empty($errs) ? trim((string) $errs[0]->message) : 'XML 结构错误';

            return new WP_Error('zw_doc_docx_xml_bad', '文档 XML 解析失败：' . $first);
        }

        return $dom;
    }
}

if (!function_exists('zw_doc_docx_style_level')) {
    /**
     * 由 Word 段落样式/大纲级别推断层级。
     *
     * @param DOMElement $p `<w:p>` 元素。
     * @return string 块类型（h1~h4）或空串。
     */
    function zw_doc_docx_style_level($p) {
        $pPr = null;
        foreach ($p->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'pPr') {
                $pPr = $child;
                break;
            }
        }
        if (!$pPr) {
            return '';
        }
        // ① 显式大纲级别：w:outlineLvl val=0 → h1
        foreach ($pPr->getElementsByTagName('*') as $node) {
            if ($node->localName === 'outlineLvl') {
                $lvl = (int) $node->getAttribute('w:val');
                if ($node->getAttribute('w:val') === '') {
                    $lvl = (int) $node->getAttribute('val');
                }
                $lvl = max(0, min(3, $lvl));

                return 'h' . ($lvl + 1);
            }
        }
        // ② 样式名：Heading 1 / heading1 / 标题 1 / 标题1
        foreach ($pPr->getElementsByTagName('*') as $node) {
            if ($node->localName !== 'pStyle') {
                continue;
            }
            $val = (string) $node->getAttribute('w:val');
            if ($val === '') {
                $val = (string) $node->getAttribute('val');
            }
            $val = trim($val);
            if ($val === '') {
                continue;
            }
            if (preg_match('/(?:heading|标题)\s*([1-9])/iu', $val, $m)) {
                $lvl = max(1, min(4, (int) $m[1]));

                return 'h' . $lvl;
            }
            if (preg_match('/^(?:H|h)([1-9])$/', $val, $m)) {
                return 'h' . max(1, min(4, (int) $m[1]));
            }
        }

        return '';
    }
}

if (!function_exists('zw_doc_docx_numpr_level')) {
    /**
     * 由 Word 自动编号列表的 `w:ilvl` 推断层级（编号文本不在 XML 中，交给系统生成）。
     *
     * @param DOMElement $p `<w:p>` 元素。
     * @return string 块类型或空串。
     */
    function zw_doc_docx_numpr_level($p) {
        foreach ($p->getElementsByTagName('*') as $node) {
            if ($node->localName !== 'numPr') {
                continue;
            }
            foreach ($node->getElementsByTagName('*') as $sub) {
                if ($sub->localName === 'ilvl') {
                    $val = (string) $sub->getAttribute('w:val');
                    if ($val === '') {
                        $val = (string) $sub->getAttribute('val');
                    }

                    return 'h' . (max(0, min(3, (int) $val)) + 1);
                }
            }
        }

        return '';
    }
}

if (!function_exists('zw_doc_docx_paragraph_text')) {
    /**
     * 取段落纯文本（`<w:t>` 连接；`<w:br>`→换行；`<w:tab>`→空格）。
     *
     * @param DOMElement $p `<w:p>` 元素。
     * @return string
     */
    function zw_doc_docx_paragraph_text($p) {
        $out = '';
        foreach ($p->getElementsByTagName('*') as $node) {
            switch ($node->localName) {
                case 't':
                    $out .= (string) $node->textContent;
                    break;
                case 'br':
                    $out .= "\n";
                    break;
                case 'tab':
                    $out .= ' ';
                    break;
                default:
                    break;
            }
        }

        return trim((string) $out);
    }
}

if (!function_exists('zw_doc_docx_read_blocks')) {
    /**
     * `.docx` → 正文块数组（唯一实现）。
     *
     * @param string $path 文件路径（通常是上传临时文件）。
     * @return array|WP_Error array{blocks:array,stats:array}
     */
    function zw_doc_docx_read_blocks($path) {
        $xml = zw_doc_docx_read_document_xml($path);
        if (is_object($xml) && function_exists('is_wp_error') && is_wp_error($xml)) {
            return $xml;
        }
        $dom = zw_doc_docx_parse_xml($xml);
        if (is_object($dom) && function_exists('is_wp_error') && is_wp_error($dom)) {
            return $dom;
        }

        $limits = zw_doc_docx_import_limits();
        $body   = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return new WP_Error('zw_doc_docx_no_body', '文档缺少正文结构。');
        }

        $limits_doc = function_exists('zw_doc_body_limits') ? zw_doc_body_limits() : array('max_blocks' => 500, 'max_total_chars' => 30000, 'max_block_chars' => 5000);
        $blocks     = array();
        $stats      = array('from_style' => 0, 'from_ordinal' => 0, 'from_numpr' => 0, 'plain' => 0, 'tables' => 0, 'drawings' => 0, 'textbox' => 0);
        $total      = 0;

        foreach ($body->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            if ($child->localName === 'tbl') {
                $stats['tables']++;
                continue;
            }
            if ($child->localName !== 'p') {
                continue;
            }
            // 含 drawing（图片）与文本框的段落单独计数，便于如实告知用户
            foreach ($child->getElementsByTagName('*') as $node) {
                if ($node->localName === 'drawing') {
                    $stats['drawings']++;
                }
                if ($node->localName === 'txbxContent') {
                    $stats['textbox']++;
                }
            }

            $text = zw_doc_docx_paragraph_text($child);
            if ($text === '') {
                continue;
            }

            $type = zw_doc_docx_style_level($child);
            if ($type !== '') {
                $stats['from_style']++;
            } else {
                // 复用 TASK-781 的序数识别（含剥离）
                $part = zw_doc_body_strip_paste_ordinal($text);
                if ($part['ordinal'] !== '') {
                    $type = $part['t'];
                    $text = $part['text'];
                    $stats['from_ordinal']++;
                } else {
                    $num_type = zw_doc_docx_numpr_level($child);
                    if ($num_type !== '') {
                        $type = $num_type;
                        $stats['from_numpr']++;
                    } else {
                        $type = 'p';
                        $stats['plain']++;
                    }
                }
            }
            if ($text === '') {
                continue;
            }

            if (function_exists('zw_doc_body_strlen')) {
                $total += zw_doc_body_strlen($text);
                if (zw_doc_body_strlen($text) > (int) $limits_doc['max_block_chars']) {
                    $text = function_exists('zw_doc_body_substr')
                        ? zw_doc_body_substr($text, 0, (int) $limits_doc['max_block_chars'])
                        : mb_substr($text, 0, (int) $limits_doc['max_block_chars'], 'UTF-8');
                }
                if ($total > (int) $limits_doc['max_total_chars']) {
                    return new WP_Error('zw_doc_docx_too_long', '文档正文超过全文上限（' . (int) $limits_doc['max_total_chars'] . ' 字），请拆分后分次导入。');
                }
            }

            $blocks[] = array('t' => $type, 'text' => $text);
            if (count($blocks) > (int) $limits_doc['max_blocks']) {
                return new WP_Error('zw_doc_docx_too_many_blocks', '段落数超过上限（' . (int) $limits_doc['max_blocks'] . ' 段），请拆分后分次导入。');
            }
        }

        if (empty($blocks)) {
            return new WP_Error('zw_doc_docx_nothing', '未从文档中解析出任何正文段落（可能是纯图片或空文档）。');
        }

        return array('blocks' => $blocks, 'stats' => $stats);
    }
}

if (!function_exists('zw_doc_docx_import_message')) {
    /**
     * 生成如实的结果提示（**跳过项必须明说，不静默丢弃**）。
     *
     * @param int   $count 块数。
     * @param array $stats 统计。
     * @return string
     */
    function zw_doc_docx_import_message($count, $stats) {
        $stats = is_array($stats) ? $stats : array();
        $get   = function ($key) use ($stats) {
            return isset($stats[$key]) ? (int) $stats[$key] : 0;
        };
        $head = $get('from_style') + $get('from_ordinal') + $get('from_numpr');

        $msg = '已从 Word 导入 ' . (int) $count . ' 段（标题 ' . $head . ' 个 / 正文段 ' . $get('plain') . ' 个）。';
        $src = array();
        if ($get('from_style') > 0) {
            $src[] = 'Word 标题样式 ' . $get('from_style');
        }
        if ($get('from_ordinal') > 0) {
            $src[] = '文本序数（已剥离重编号）' . $get('from_ordinal');
        }
        if ($get('from_numpr') > 0) {
            $src[] = 'Word 编号列表 ' . $get('from_numpr');
        }
        if ($src) {
            $msg .= '层级来源：' . implode('、', $src) . '。';
        }
        $skipped = array();
        if ($get('tables') > 0) {
            $skipped[] = '表格 ' . $get('tables') . ' 个';
        }
        if ($get('drawings') > 0) {
            $skipped[] = '图片 ' . $get('drawings') . ' 处';
        }
        if ($get('textbox') > 0) {
            $skipped[] = '文本框 ' . $get('textbox') . ' 处';
        }
        if ($skipped) {
            $msg .= '⚠ 未导入：' . implode('、', $skipped) . '（表格与图片请作为附件单独上传；印章请走用印流程）。';
        }

        return $msg . ' 请核对后保存。';
    }
}

if (!function_exists('zw_doc_handle_composer_import_docx')) {
    /**
     * 上传 `.docx` 导入端点（返回与「整篇粘贴」**同一结构**，前端共用填充逻辑）。
     *
     * @return void
     */
    function zw_doc_handle_composer_import_docx() {
        if (!function_exists('zw_doc_is_enabled') || !zw_doc_is_enabled()) {
            wp_send_json_error(array('message' => '发文管理未启用。'), 403);
        }
        if (!current_user_can('manage_options') && !current_user_can('tcpc_perm_doc')) {
            wp_send_json_error(array('message' => '无权限。'), 403);
        }
        check_admin_referer('zw_doc_composer_split'); // 与粘贴共用同一 nonce 语义

        $valid = zw_doc_docx_validate_upload(isset($_FILES['docx']) ? $_FILES['docx'] : null);
        if (function_exists('is_wp_error') && is_wp_error($valid)) {
            wp_send_json_error(array('message' => $valid->get_error_message()));
        }

        $read = zw_doc_docx_read_blocks($valid['path']);
        if (function_exists('is_wp_error') && is_wp_error($read)) {
            wp_send_json_error(array('message' => $read->get_error_message()));
        }

        $counts = array('h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'p' => 0);
        foreach ($read['blocks'] as $block) {
            if (isset($counts[$block['t']])) {
                $counts[$block['t']]++;
            }
        }

        wp_send_json_success(array(
            'blocks'  => $read['blocks'],
            'count'   => count($read['blocks']),
            'counts'  => $counts,
            'message' => zw_doc_docx_import_message(count($read['blocks']), $read['stats']),
        ));
    }
}
