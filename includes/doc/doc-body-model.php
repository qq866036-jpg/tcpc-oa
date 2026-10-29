<?php
/**
 * 公文正文模型（AST）—— TASK-766 / SPEC-87。
 *
 * 设计铁律：
 * - **只存纯文本块，不存 HTML**：正文永远以「块类型 + 纯文本」保存，渲染期再转义，
 *   从根上消除存储型 XSS（本项目不使用富文本编辑器）。
 * - 本文件**不依赖 WordPress 运行态**（纯 PHP：strip_tags / html_entity_decode / preg_* / mb_*），
 *   因此可被单元测试直接 include，且清洗强度不随 WP 版本变化。
 * - 白名单是唯一入口：块类型、meta 键、page_mode 都走白名单，未知一律丢弃。
 * - 上限（块数 / 单块字数 / 全文字数）在此定义，渲染与法务送审共用。
 *
 * 存储：post_meta `_zw_doc_body_json`（由 `doc-repository.php` 读写）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_BODY_VERSION')) {
    define('ZW_DOC_BODY_VERSION', 1);
}

// 「任」字文种任命要素（`meta.appointments`）是**受控结构化 meta**，其编码/校验
// 唯一出处是 doc-appoint.php。此处主动加载，保证单独 include 本文件（单元测试）时
// `zw_doc_appoint_encode()` 也可用；正式链路由 doc-bootstrap 先行 require（幂等）。
if (!function_exists('zw_doc_appoint_encode')) {
    $zw_doc_body_appoint_file = __DIR__ . '/doc-appoint.php';
    if (is_file($zw_doc_body_appoint_file)) {
        require_once $zw_doc_body_appoint_file;
    }
    unset($zw_doc_body_appoint_file);
}

if (!function_exists('zw_doc_body_limits')) {
    /**
     * 正文上限（唯一出处）。
     *
     * @return array
     */
    function zw_doc_body_limits() {
        return array(
            'max_blocks'       => 500,
            'max_block_chars'  => 5000,
            'max_total_chars'  => 30000,
            'max_title_chars'  => 200,
            'max_meta_chars'   => 200,
            'max_attach_items' => 20,
        );
    }
}

if (!function_exists('zw_doc_body_block_types')) {
    /**
     * 允许的块类型白名单。
     *
     * @return string[]
     */
    function zw_doc_body_block_types() {
        return array('p', 'h1', 'h2', 'h3', 'h4');
    }
}

if (!function_exists('zw_doc_body_heading_map')) {
    /**
     * 层次序数映射（GB/T 9704-2012：一、（一）、1.、（1））。
     *
     * @return array
     */
    function zw_doc_body_heading_map() {
        return array(
            'h1' => array('ordinal' => '一、', 'slot' => 'h1'),
            'h2' => array('ordinal' => '（一）', 'slot' => 'h2'),
            'h3' => array('ordinal' => '1.', 'slot' => 'h3'),
            'h4' => array('ordinal' => '（1）', 'slot' => 'h4'),
        );
    }
}

if (!function_exists('zw_doc_body_meta_keys')) {
    /**
     * 允许的 meta 键白名单（其余键一律丢弃）。
     *
     * @return string[]
     */
    function zw_doc_body_meta_keys() {
        return array(
            'org_name', 'doc_type', 'title', 'receiver', 'sign_org', 'date', 'note',
            // TASK-2014（用户「公文标题 输入框改造成可折行 最多显示3行 由我自己控制折行」）：
            // 手动折行后的**标题行数组**（受控数组，专用分支清洗，见下方 title_lines 处理）。
            'title_lines',
            'copy_no', 'secret', 'urgency', 'signer', 'cc', 'print_org', 'print_date',
            'page_mode',
            // 「任」字文种人员任命配置（受控结构化 meta，专用分支，禁止走纯文本清洗）。
            'appointments',
        );
    }
}

if (!function_exists('zw_doc_body_page_modes')) {
    /**
     * 允许的页码模式（取自 SPEC-87 版式常量，避免第二处维护）。
     *
     * @return string[]
     */
    function zw_doc_body_page_modes() {
        if (function_exists('zw_doc_gb9704_get')) {
            $modes = zw_doc_gb9704_get('footer.modes', array());
            if (is_array($modes) && !empty($modes)) {
                return array_values(array_map('strval', $modes));
            }
        }
        return array('standard', 'center', 'none');
    }
}

if (!function_exists('zw_doc_body_clean_text')) {
    /**
     * 纯文本硬清洗。
     *
     * 顺序刻意如此：控制字符 → 剥标签 → 解实体 → 再剥标签 → 归一空白。
     * 第二次剥标签是为了拦住 `&lt;script&gt;` 这类实体伪装（解码后才会变成真标签）。
     * 代价：用户想「原样显示 HTML 代码」会丢标签 —— 公文场景不需要，规范已声明只支持纯文本。
     *
     * @param mixed $text 原始文本。
     * @return string
     */
    function zw_doc_body_clean_text($text) {
        $text = (string) $text;

        // 1) 去 NUL 与不可见控制字符（保留 \n \t，稍后归一）
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        if (!is_string($text)) {
            $text = '';
        }

        // 2) 剥掉所有 HTML 标签
        $text = strip_tags($text);

        // 3) 解码实体后再剥一次（防实体伪装的标签）
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);

        // 4) 归一换行与水平空白
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        if (!is_string($text)) {
            $text = '';
        }
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        if (!is_string($text)) {
            $text = '';
        }

        return trim($text);
    }
}

if (!function_exists('zw_doc_body_truncate')) {
    /**
     * 按字符数截断（优先 mbstring）。
     *
     * @param string $text  文本。
     * @param int    $limit 上限。
     * @return string
     */
    function zw_doc_body_truncate($text, $limit) {
        $text  = (string) $text;
        $limit = (int) $limit;
        if ($limit <= 0) {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit, 'UTF-8');
        }
        return substr($text, 0, $limit);
    }
}

if (!function_exists('zw_doc_body_strlen')) {
    /**
     * 字符数（优先 mbstring）。
     *
     * @param string $text 文本。
     * @return int
     */
    function zw_doc_body_strlen($text) {
        $text = (string) $text;
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($text, 'UTF-8');
        }
        return strlen($text);
    }
}

if (!function_exists('zw_doc_body_empty')) {
    /**
     * 空正文结构。
     *
     * @param array $meta 初始 meta。
     * @return array
     */
    function zw_doc_body_empty($meta = array()) {
        $body = array(
            'v'      => ZW_DOC_BODY_VERSION,
            'meta'   => array(),
            'blocks' => array(),
        );
        foreach (zw_doc_body_meta_keys() as $key) {
            $body['meta'][$key] = '';
        }
        $body['meta']['attach_note'] = array();
        $body['meta']['page_mode']   = 'standard';

        return zw_doc_body_normalize(array_merge($body, array('meta' => array_merge($body['meta'], (array) $meta))));
    }
}

if (!function_exists('zw_doc_body_parse')) {
    /**
     * 容错解析：JSON 字符串 / 数组 → AST 数组；旧数据返回 null。
     *
     * 返回 null 的语义是「这篇发文没有正文」，调用方必须回退到既有渲染路径（兼容旧记录）。
     *
     * @param mixed $raw 原始存储值。
     * @return array|null
     */
    function zw_doc_body_parse($raw) {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }
            $raw = $decoded;
        }
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        if (!isset($raw['blocks']) && !isset($raw['meta'])) {
            return null;
        }

        return zw_doc_body_normalize($raw);
    }
}

if (!function_exists('zw_doc_body_normalize')) {
    /**
     * 归一化：白名单过滤 + 清洗 + 截断，输出永远安全可存。
     *
     * 幂等：`normalize(normalize(x)) === normalize(x)`。
     *
     * @param mixed $raw 原始结构。
     * @return array
     */
    function zw_doc_body_normalize($raw) {
        $limits = zw_doc_body_limits();
        $raw    = is_array($raw) ? $raw : array();

        $body = array(
            'v'      => ZW_DOC_BODY_VERSION,
            'meta'   => array(),
            'blocks' => array(),
        );

        // —— meta：白名单 + 清洗 + 截断 ——
        $raw_meta = isset($raw['meta']) && is_array($raw['meta']) ? $raw['meta'] : array();
        foreach (zw_doc_body_meta_keys() as $key) {
            if ($key === 'appointments') {
                continue; // 受控结构化 meta，禁止走纯文本清洗（否则 JSON 被 strip_tags / 200 字截断破坏）
            }
            // TASK-2002：`cc`（抄送机关）允许数组 —— 早前直接进 `zw_doc_body_clean_text()` 的 `(string)` 强转，
            // 数组会变成字面量「Array」并写进版记（设计器预览实测「抄送：Array」）。
            // 数组一律以「、」连接为一行（GB §7.4：抄送机关同行排列）。
            $zw_raw_value = isset($raw_meta[$key]) ? $raw_meta[$key] : '';
            if (is_array($zw_raw_value)) {
                $zw_raw_value = implode('、', array_map(function ($v) { return (string) $v; }, $zw_raw_value));
            }
            $value = zw_doc_body_clean_text($zw_raw_value);
            $limit = ($key === 'title') ? $limits['max_title_chars'] : $limits['max_meta_chars'];
            $body['meta'][$key] = zw_doc_body_truncate($value, $limit);
        }

        // TASK-2014：**标题手动折行** —— 作者在输入框里用换行决定断行位置（最多 3 行）。
        // 口径：`meta.title` 仍保存**单行**（列表、文件名、检索不受影响）；
        //      断行结果存 `meta.title_lines`（≤3 行、逐行清洗、去空行）；无换行时为空数组 ⇒ 渲染层走自动回行。
        $zw_raw_title = isset($raw_meta['title']) ? (string) $raw_meta['title'] : '';
        $zw_title_lines = array();
        if (strpos($zw_raw_title, "\n") !== false || strpos($zw_raw_title, "\r") !== false) {
            foreach (preg_split('/\r\n|\r|\n/', $zw_raw_title) as $zw_ln) {
                $zw_ln = zw_doc_body_truncate(zw_doc_body_clean_text($zw_ln), $limits['max_title_chars']);
                if ($zw_ln !== '') {
                    $zw_title_lines[] = $zw_ln;
                }
                if (count($zw_title_lines) >= 3) {
                    break;
                }
            }
        }
        if (!empty($zw_title_lines)) {
            $body['meta']['title_lines'] = $zw_title_lines;
            $body['meta']['title']       = zw_doc_body_truncate(implode(' ', $zw_title_lines), $limits['max_title_chars']);
        } else {
            $body['meta']['title_lines'] = array();
        }

        // 「任」字文种任命要素：专用分支（结构清洗在 doc-appoint.php 内，唯一出处）。
        // 非「任」文种该值为空对象 ⇒ 渲染通道零输出、同步层短路。
        $body['meta']['appointments'] = function_exists('zw_doc_appoint_encode')
            ? zw_doc_appoint_encode(isset($raw_meta['appointments']) ? $raw_meta['appointments'] : array())
            : '{"v":1,"type":"","scope":"","items":[]}';

        // 附注（收发文字号等）—— 数组，逐项清洗去空，限量
        $attach = array();
        if (isset($raw_meta['attach_note'])) {
            foreach ((array) $raw_meta['attach_note'] as $item) {
                $item = zw_doc_body_clean_text($item);
                if ($item === '') {
                    continue;
                }
                $attach[] = zw_doc_body_truncate($item, $limits['max_meta_chars']);
                if (count($attach) >= $limits['max_attach_items']) {
                    break;
                }
            }
        }
        $body['meta']['attach_note'] = $attach;

        // 页码模式：白名单
        $mode = isset($raw_meta['page_mode']) ? (string) $raw_meta['page_mode'] : '';
        if (!in_array($mode, zw_doc_body_page_modes(), true)) {
            $mode = 'standard';
        }
        $body['meta']['page_mode'] = $mode;

        // —— blocks：类型白名单 + 清洗 + 逐级限量 ——
        $types      = zw_doc_body_block_types();
        $total      = 0;
        $raw_blocks = isset($raw['blocks']) && is_array($raw['blocks']) ? $raw['blocks'] : array();

        foreach ($raw_blocks as $block) {
            if (count($body['blocks']) >= $limits['max_blocks']) {
                break;
            }
            if (!is_array($block)) {
                continue;
            }
            $type = isset($block['t']) ? strtolower(trim((string) $block['t'])) : '';
            if (!in_array($type, $types, true)) {
                continue; // 未知块类型直接丢弃（含 script/table/iframe 等）
            }
            $text = zw_doc_body_clean_text(isset($block['text']) ? $block['text'] : '');
            if ($text === '') {
                continue;
            }
            $text = zw_doc_body_truncate($text, $limits['max_block_chars']);

            // 全文字数上限：超出即停止收录
            $len = zw_doc_body_strlen($text);
            if ($total + $len > $limits['max_total_chars']) {
                $remain = $limits['max_total_chars'] - $total;
                if ($remain <= 0) {
                    break;
                }
                $text = zw_doc_body_truncate($text, $remain);
                $len  = zw_doc_body_strlen($text);
            }
            if ($text === '') {
                break;
            }

            $body['blocks'][] = array('t' => $type, 'text' => $text);
            $total           += $len;
        }

        return $body;
    }
}

if (!function_exists('zw_doc_body_validate')) {
    /**
     * 校验（面向用户反馈，不改变数据）。
     *
     * @param mixed $body AST 或原始结构。
     * @return array 问题列表；空数组表示通过。每项为 array{code,message}。
     */
    function zw_doc_body_validate($body) {
        $problems = array();
        $limits   = zw_doc_body_limits();

        if (!is_array($body)) {
            return array(array('code' => 'invalid_structure', 'message' => '正文结构不合法。'));
        }

        $types      = zw_doc_body_block_types();
        $raw_blocks = isset($body['blocks']) && is_array($body['blocks']) ? $body['blocks'] : array();

        if (count($raw_blocks) > $limits['max_blocks']) {
            $problems[] = array(
                'code'    => 'too_many_blocks',
                'message' => '段落数超出上限（最多 ' . $limits['max_blocks'] . ' 段）。',
            );
        }

        $total = 0;
        foreach ($raw_blocks as $index => $block) {
            if (!is_array($block)) {
                $problems[] = array(
                    'code'    => 'invalid_block',
                    'message' => '第 ' . ($index + 1) . ' 段结构不合法。',
                );
                continue;
            }
            $type = isset($block['t']) ? strtolower(trim((string) $block['t'])) : '';
            if (!in_array($type, $types, true)) {
                $problems[] = array(
                    'code'    => 'unknown_block_type',
                    'message' => '第 ' . ($index + 1) . ' 段使用了不允许的类型：' . $type . '。',
                );
                continue;
            }
            $text = isset($block['text']) ? (string) $block['text'] : '';
            $len  = zw_doc_body_strlen($text);
            if ($len > $limits['max_block_chars']) {
                $problems[] = array(
                    'code'    => 'block_too_long',
                    'message' => '第 ' . ($index + 1) . ' 段超过 ' . $limits['max_block_chars'] . ' 字。',
                );
            }
            $total += $len;
        }

        if ($total > $limits['max_total_chars']) {
            $problems[] = array(
                'code'    => 'too_long',
                'message' => '正文总字数超过 ' . $limits['max_total_chars'] . ' 字。',
            );
        }

        if (isset($body['meta']) && is_array($body['meta'])) {
            $mode = isset($body['meta']['page_mode']) ? (string) $body['meta']['page_mode'] : '';
            if ($mode !== '' && !in_array($mode, zw_doc_body_page_modes(), true)) {
                $problems[] = array(
                    'code'    => 'bad_page_mode',
                    'message' => '页码模式不合法：' . $mode . '。',
                );
            }
            $title = isset($body['meta']['title']) ? (string) $body['meta']['title'] : '';
            if (zw_doc_body_strlen($title) > $limits['max_title_chars']) {
                $problems[] = array(
                    'code'    => 'title_too_long',
                    'message' => '标题超过 ' . $limits['max_title_chars'] . ' 字。',
                );
            }

            // 「任」字文种任命要素：受控结构校验（非法 JSON / 越界 / 两套字段互斥 / 必填缺失）。
            if (array_key_exists('appointments', $body['meta']) && function_exists('zw_doc_appoint_problems')) {
                foreach (zw_doc_appoint_problems($body['meta']['appointments']) as $appoint_problem) {
                    if (is_array($appoint_problem) && isset($appoint_problem['code'], $appoint_problem['message'])) {
                        $problems[] = array(
                            'code'    => (string) $appoint_problem['code'],
                            'message' => (string) $appoint_problem['message'],
                        );
                    }
                }
            }
        }

        return $problems;
    }
}

if (!function_exists('zw_doc_body_to_json')) {
    /**
     * 稳定序列化（用于 post_meta 与内容哈希）。
     *
     * @param mixed $body AST。
     * @return string
     */
    function zw_doc_body_to_json($body) {
        $body = zw_doc_body_normalize($body);
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '';
    }
}

if (!function_exists('zw_doc_body_to_meta')) {
    /**
     * 序列化别名（语义化：写入 post_meta 用）。
     *
     * @param mixed $body AST。
     * @return string
     */
    function zw_doc_body_to_meta($body) {
        return zw_doc_body_to_json($body);
    }
}

if (!function_exists('zw_doc_body_to_meta_stored')) {
    /**
     * 交给 `update_post_meta()` / `add_post_meta()` 的**存储值**（TASK-1414 / CHG-217）。
     *
     * 为什么必须 slashed：WordPress 的 `update_metadata()` 在落库前**先对值做 `wp_unslash()`**
     * （`wp-includes/meta.php` → `stripslashes_deep`）。而正文 canonical JSON 中「任」字任命要素
     * 本身是一段**嵌套 JSON 字符串**（`"appointments":"{\"v\":1,…}"`），正文文本里的反斜杠也会被
     * `json_encode()` 转义 —— 若把 canonical JSON 直接写入，落库即丢掉全部反斜杠 ⇒ 变成非法 JSON
     * ⇒ `zw_doc_body_parse()` 回读返回 null ⇒ 用印门禁 G7「标题或正文为空」**永久阻断**
     * ⇒「完成文件」（正式用印件）永远无法生成（用户报障「点击完成文件没有任何反应」的更深根因）。
     *
     * 语义零变更：输出与 `zw_doc_body_to_meta()` **逐字节同构**，只补上 WP 入参转义；
     * 非 WordPress 环境（测试 / 纯 PHP）没有 `wp_slash()`，保持原样（该环境的存储层不做 unslash）。
     *
     * @param mixed $body AST。
     * @return string
     */
    function zw_doc_body_to_meta_stored($body) {
        $json = zw_doc_body_to_meta($body);
        if ($json === '' || !function_exists('wp_slash')) {
            return $json;
        }

        return wp_slash($json);
    }
}

if (!function_exists('zw_doc_body_hash')) {
    /**
     * 正文内容哈希（用印归档与篡改检测用）。
     *
     * @param mixed $body AST。
     * @return string 64 位十六进制；空正文返回空串。
     */
    function zw_doc_body_hash($body) {
        $json = zw_doc_body_to_json($body);
        if ($json === '' || $json === zw_doc_body_to_json(zw_doc_body_empty())) {
            return '';
        }

        return hash('sha256', $json);
    }
}

if (!function_exists('zw_doc_body_is_empty')) {
    /**
     * 是否没有实质正文（无块）。
     *
     * @param mixed $body AST。
     * @return bool
     */
    function zw_doc_body_is_empty($body) {
        if (!is_array($body) || empty($body['blocks']) || !is_array($body['blocks'])) {
            return true;
        }
        foreach ($body['blocks'] as $block) {
            if (is_array($block) && isset($block['text']) && trim((string) $block['text']) !== '') {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('zw_doc_body_count_chars')) {
    /**
     * 正文字数（仅块文本，不含 meta）。
     *
     * @param mixed $body AST。
     * @return int
     */
    function zw_doc_body_count_chars($body) {
        if (!is_array($body) || empty($body['blocks']) || !is_array($body['blocks'])) {
            return 0;
        }
        $total = 0;
        foreach ($body['blocks'] as $block) {
            if (is_array($block) && isset($block['text'])) {
                $total += zw_doc_body_strlen(zw_doc_body_clean_text($block['text']));
            }
        }

        return $total;
    }
}

if (!function_exists('zw_doc_body_text')) {
    /**
     * 纯文本拍平（法务送审 / 检索 / 导出预览用）。
     *
     * 顺序：标题 → 主送机关 → 各块文本 → 发文机关署名 → 成文日期 → 附件说明。
     * 层次序数**保留块类型语义**：h1~h4 前加对应序数前缀，避免送审时丢失层级。
     *
     * @param mixed $body AST。
     * @return string
     */
    function zw_doc_body_text($body) {
        if (!is_array($body)) {
            return '';
        }
        $lines = array();
        $meta  = isset($body['meta']) && is_array($body['meta']) ? $body['meta'] : array();

        foreach (array('title', 'receiver') as $key) {
            if (!empty($meta[$key])) {
                $lines[] = (string) $meta[$key];
            }
        }

        $headings = zw_doc_body_heading_map();
        if (!empty($body['blocks']) && is_array($body['blocks'])) {
            foreach ($body['blocks'] as $block) {
                if (!is_array($block) || !isset($block['text'])) {
                    continue;
                }
                $type = isset($block['t']) ? (string) $block['t'] : 'p';
                $text = (string) $block['text'];
                if (isset($headings[$type])) {
                    $text = $headings[$type]['ordinal'] . $text;
                }
                if (trim($text) !== '') {
                    $lines[] = $text;
                }
            }
        }

        foreach (array('sign_org', 'date') as $key) {
            if (!empty($meta[$key])) {
                $lines[] = (string) $meta[$key];
            }
        }

        if (!empty($meta['attach_note']) && is_array($meta['attach_note'])) {
            foreach ($meta['attach_note'] as $item) {
                if (trim((string) $item) !== '') {
                    $lines[] = (string) $item;
                }
            }
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('zw_doc_body_add_block')) {
    /**
     * 追加一个块（编辑器用），返回新 AST；类型不合法时原样返回。
     *
     * @param mixed  $body AST。
     * @param string $type 块类型。
     * @param string $text 文本。
     * @return array
     */
    function zw_doc_body_add_block($body, $type, $text) {
        $body = is_array($body) ? $body : zw_doc_body_empty();
        $type = strtolower(trim((string) $type));
        if (!in_array($type, zw_doc_body_block_types(), true)) {
            return zw_doc_body_normalize($body);
        }
        if (!isset($body['blocks']) || !is_array($body['blocks'])) {
            $body['blocks'] = array();
        }
        $body['blocks'][] = array('t' => $type, 'text' => (string) $text);

        return zw_doc_body_normalize($body);
    }
}

if (!function_exists('zw_doc_body_ordinal_cn')) {
    /**
     * 阿拉伯数字 → 中文序数（1→一、21→二十一）。
     *
     * 层次序数是**内容语义**而非渲染细节，因此归正文模型唯一实现，
     * HTML / DOCX / PDF 三条通道共用（禁止各通道自行实现一套）。
     *
     * @param int $n 序号（1~99，超出按边界夹取）。
     * @return string
     */
    function zw_doc_body_ordinal_cn($n) {
        $n     = max(1, min(99, (int) $n));
        $units = array('', '一', '二', '三', '四', '五', '六', '七', '八', '九');
        if ($n < 10) {
            return $units[$n];
        }
        if ($n === 10) {
            return '十';
        }
        if ($n < 20) {
            return '十' . $units[$n - 10];
        }
        $tens = (int) floor($n / 10);
        $ones = $n % 10;

        return $units[$tens] . '十' . ($ones > 0 ? $units[$ones] : '');
    }
}

if (!function_exists('zw_doc_body_block_ordinal')) {
    /**
     * 按块类型生成序数前缀（GB/T 9704-2012：一、（一）、1.、（1））。
     *
     * @param string $type 块类型。
     * @param int    $seq  该层级序号。
     * @return string
     */
    function zw_doc_body_block_ordinal($type, $seq) {
        switch ((string) $type) {
            case 'h1':
                return zw_doc_body_ordinal_cn($seq) . '、';
            case 'h2':
                return '（' . zw_doc_body_ordinal_cn($seq) . '）';
            case 'h3':
                return (int) $seq . '.';
            case 'h4':
                return '（' . (int) $seq . '）';
            default:
                return '';
        }
    }
}

if (!function_exists('zw_doc_body_block_has_ordinal')) {
    /**
     * 文本是否已自带该层级的序数（用户手写了序数就不再重复添加）。
     *
     * @param string $type 块类型。
     * @param string $text 文本。
     * @return bool
     */
    function zw_doc_body_block_has_ordinal($type, $text) {
        $text = (string) $text;
        switch ((string) $type) {
            case 'h1':
                return (bool) preg_match('/^\s*[一二三四五六七八九十]+、/u', $text);
            case 'h2':
                return (bool) preg_match('/^\s*（[一二三四五六七八九十]+）/u', $text);
            case 'h3':
                return (bool) preg_match('/^\s*\d+[.．、]/u', $text);
            case 'h4':
                return (bool) preg_match('/^\s*（\d+）/u', $text);
            default:
                return false;
        }
    }
}

if (!function_exists('zw_doc_body_build_ordinals')) {
    /**
     * 为全量块计算序数前缀映射。
     *
     * 规则：跨页连续（按全量块计算）；下级遇到上级时重置（h1 → h2/h3/h4 归零，依此类推）；
     * 文本已自带同类型序数时不生成前缀。
     *
     * @param array $blocks 全量块。
     * @return array index => 前缀字符串
     */
    function zw_doc_body_build_ordinals($blocks) {
        $counters = array('h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0);
        $map      = array();
        foreach ((array) $blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = isset($block['t']) ? strtolower((string) $block['t']) : 'p';
            if (!isset($counters[$type])) {
                continue;
            }
            $counters[$type]++;
            if ($type === 'h1') {
                $counters['h2'] = 0;
                $counters['h3'] = 0;
                $counters['h4'] = 0;
            } elseif ($type === 'h2') {
                $counters['h3'] = 0;
                $counters['h4'] = 0;
            } elseif ($type === 'h3') {
                $counters['h4'] = 0;
            }
            $text = isset($block['text']) ? (string) $block['text'] : '';
            $map[$index] = zw_doc_body_block_has_ordinal($type, $text)
                ? ''
                : zw_doc_body_block_ordinal($type, $counters[$type]);
        }

        return $map;
    }
}

if (!function_exists('zw_doc_body_paragraphs')) {
    /**
     * 排版段落序列（唯一出处）：块 → 带序数的展示段落。
     *
     * HTML 渲染与 DOCX 导出**都**必须消费本函数，避免两套实现漂移。
     *
     * @param mixed $body AST。
     * @return array 每项 array{t,text,ordinal,display}
     */
    function zw_doc_body_paragraphs($body) {
        $blocks = (is_array($body) && isset($body['blocks']) && is_array($body['blocks'])) ? array_values($body['blocks']) : array();
        $map    = zw_doc_body_build_ordinals($blocks);
        $out    = array();
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = isset($block['t']) ? strtolower((string) $block['t']) : 'p';
            if (!in_array($type, zw_doc_body_block_types(), true)) {
                continue;
            }
            $text = isset($block['text']) ? (string) $block['text'] : '';
            if (trim($text) === '') {
                continue;
            }
            $ordinal = isset($map[$index]) ? (string) $map[$index] : '';
            $out[]   = array(
                't'       => $type,
                'text'    => $text,
                'ordinal' => $ordinal,
                'display' => $ordinal . $text,
            );
        }

        return $out;
    }
}

if (!function_exists('zw_doc_body_paste_ordinal_patterns')) {
    /**
     * 粘贴文本的**层次序数识别规则**（唯一出处）。
     *
     * 顺序敏感：先判「（一）」「（1）」这类带括号的，再判「一、」「1.」。
     * 允许常见 Word 变体：半角括号、`1、`、`1．`、前后多余空格。
     *
     * 识别结果与 `zw_doc_body_block_ordinal()` 的生成格式**往返一致**：
     *   h1 → `一、`   h2 → `（一）`   h3 → `1.`   h4 → `（1）`
     *
     * @return array<string,string> 块类型 => 正则（匹配到的整段前缀会被剥离）
     */
    function zw_doc_body_paste_ordinal_patterns() {
        return array(
            'h2' => '/^[\s\x{3000}]*[（(]\s*([一二三四五六七八九十]{1,3})\s*[）)][\s\x{3000}]*/u',
            'h4' => '/^[\s\x{3000}]*[（(]\s*(\d{1,2})\s*[）)][\s\x{3000}]*/u',
            'h1' => '/^[\s\x{3000}]*([一二三四五六七八九十]{1,3})\s*[、.．,，][\s\x{3000}]*/u',
            'h3' => '/^[\s\x{3000}]*(\d{1,2})\s*[.．、][\s\x{3000}]*/u',
        );
    }
}

if (!function_exists('zw_doc_body_strip_paste_ordinal')) {
    /**
     * 从单段文本中剥离层次序数，并返回推断出的块类型。
     *
     * 无序号时返回 `p`（正文段）且原样保留文字。
     *
     * @param string $line 单段文本。
     * @return array{t:string,text:string,ordinal:string} ordinal 为被剥离的原文前缀（便于提示用户）
     */
    function zw_doc_body_strip_paste_ordinal($line) {
        $raw = trim((string) $line);
        if ($raw === '') {
            return array('t' => 'p', 'text' => '', 'ordinal' => '');
        }
        foreach (zw_doc_body_paste_ordinal_patterns() as $type => $pattern) {
            if (preg_match($pattern, $raw, $m)) {
                $stripped = (string) preg_replace($pattern, '', $raw, 1);
                // Word 的项目符号/装饰字符与多余空白一并清理
                $stripped = preg_replace('/^[\s\x{3000}·•・\-—*]+/u', '', $stripped);

                return array('t' => $type, 'text' => trim((string) $stripped), 'ordinal' => $m[0]);
            }
        }
        // 无层次序数：剥离 Word 项目符号与行首空白（含全角空格），保持为正文段
        $clean = preg_replace('/^[\s\x{3000}·•・\-—*]+/u', '', $raw);

        return array('t' => 'p', 'text' => trim((string) $clean), 'ordinal' => '');
    }
}

if (!function_exists('zw_doc_body_blocks_from_paste')) {
    /**
     * 整篇粘贴文本 → 正文块数组（「智能拆分」的唯一实现）。
     *
     * 行为契约：
     * 1. 归一换行（CRLF/CR → LF），**按换行拆段**（与 Word 的段落模型一致：Word 的软换行在剪贴板里不带换行符）；
     * 2. 每段识别层次序数并**剥离**（序数由系统重新生成，保证跨页连续、增删段落不乱号）；
     * 3. 清理 Word 残留：全角空格缩进、项目符号、制表符；
     * 4. 空段丢弃；超过全文/块数上限时返回 WP_Error（不静默截断）；
     * 5. 结果可直接喂给 `zw_doc_body_normalize()`（块类型与文本均已合规）。
     *
     * @param string $text 粘贴文本。
     * @return array|WP_Error 块数组 array{t,text}
     */
    function zw_doc_body_blocks_from_paste($text) {
        $text = (string) $text;
        // 剥离 HTML（从 Word/网页复制往往带标签），保留换行
        $text = str_replace(array("\r\n", "\r"), "\n", $text);

        // 段落分隔的判定依据：**带块级标签的富文本**用「块级标签边界」当段落分隔，
        // `<br>`（Word 的段内换行 / Shift+Enter）保持在段内；**纯文本**则每个换行即一段。
        // 若把 `<br>` 也当段落分隔，Word 里同一段的软换行会被拆成多个块。
        $has_block_tags = (bool) preg_match('#</(p|div|li|h[1-6]|tr|blockquote|td|th)\s*>#i', $text);

        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text);
        $text = preg_replace('#<br\s*/?>#i', "\n", (string) $text);
        $text = preg_replace('#</(p|div|li|h[1-6]|tr|blockquote|td|th)\s*>#i', "\n\n", (string) $text);
        $text = strip_tags((string) $text);
        // 解码实体：Word/网页复制常见 `&nbsp;`；不处理会变成垃圾段落
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // NBSP（U+00A0）与全角空格、制表符一律归一为普通空格，使其能被当作空白丢弃
        $text = str_replace(array("\xC2\xA0", "\x{3000}", "\t"), array(' ', ' ', ' '), (string) $text);

        $limits = zw_doc_body_limits();
        if (function_exists('zw_doc_body_strlen') && zw_doc_body_strlen($text) > (int) $limits['max_total_chars']) {
            return new WP_Error('zw_doc_paste_too_long', '粘贴内容超过全文上限（' . (int) $limits['max_total_chars'] . ' 字），请分次粘贴。');
        }

        $separator = $has_block_tags ? '/\n\s*\n/u' : '/\n+/u';
        $lines     = preg_split($separator, (string) $text);
        $blocks = array();
        foreach ((array) $lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $part = zw_doc_body_strip_paste_ordinal((string) $line);
            if ($part['text'] === '') {
                continue;
            }
            if (function_exists('zw_doc_body_strlen') && zw_doc_body_strlen($part['text']) > (int) $limits['max_block_chars']) {
                $part['text'] = function_exists('zw_doc_body_substr')
                    ? zw_doc_body_substr($part['text'], 0, (int) $limits['max_block_chars'])
                    : mb_substr($part['text'], 0, (int) $limits['max_block_chars'], 'UTF-8');
            }
            $blocks[] = array('t' => $part['t'], 'text' => $part['text']);
            if (count($blocks) > (int) $limits['max_blocks']) {
                return new WP_Error('zw_doc_paste_too_many_blocks', '拆分后段落数超过上限（' . (int) $limits['max_blocks'] . ' 段），请分次粘贴。');
            }
        }

        if (empty($blocks)) {
            return new WP_Error('zw_doc_paste_empty', '未从粘贴内容中解析出任何段落。');
        }

        return $blocks;
    }
}
