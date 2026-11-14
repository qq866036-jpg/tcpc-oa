<?php
/**
 * 统一文本源 · 纯逻辑（AGENT-0423 / 测试分层 T2）。
 *
 * 定位：只放**无 WP / DB / 网络 / 文件 / 时间 / 随机**依赖的纯函数，
 * 供 `legal-text-source.php` 与 L0 测试（`scripts/logic-suite.php --module=policy/legal-text`）共用。
 *
 * 兼容约束：函数名、签名、返回值与本文件抽出前**完全一致**；原文件 require 本文件，
 * 调用方无需改动。新增纯函数才放这里；一旦需要 WP/DB，留在 `legal-text-source.php`。
 *
 * @package tcpc
 */

if (!function_exists('zw_legal_text_normalize')) {
    /**
     * 正文规范化（**唯一口径**，哈希与字数都基于它）。
     *
     * 步骤：去标签 → 实体解码 → 折叠所有空白为单空格 → 去首尾空白。
     *
     * @param string $html 正文（可含白名单 HTML）。
     * @return string
     */
    function zw_legal_text_normalize($html) {
        $html = (string) $html;
        $text = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($html) : strip_tags($html);
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $text);
        if ($collapsed === null) {
            // 非法 UTF-8 时 `/u` 会返回 null：退回非 unicode 折叠，绝不返回 null。
            $collapsed = preg_replace('/\s+/', ' ', $text);
        }
        return trim((string) $collapsed);
    }
}

if (!function_exists('zw_legal_text_hash')) {
    /**
     * 整篇正文的版本锚点：`sha256(normalize(body_html))`。
     *
     * @param string $html 正文。
     * @return string 64 位十六进制。
     */
    function zw_legal_text_hash($html) {
        return hash('sha256', zw_legal_text_normalize($html));
    }
}

if (!function_exists('zw_legal_text_body_chars')) {
    /**
     * 正文纯文本字数（阅读时长 `auto` 口径复用）。
     *
     * @param string $html 正文。
     * @return int
     */
    function zw_legal_text_body_chars($html) {
        $text = zw_legal_text_normalize($html);
        return function_exists('mb_strlen') ? (int) mb_strlen($text, 'UTF-8') : (int) strlen($text);
    }
}

if (!function_exists('zw_legal_text_version_number')) {
    /**
     * 从 `v12` 取数值 12（排序与自增用）。
     *
     * @param string $version_no 版本号。
     * @return int
     */
    function zw_legal_text_version_number($version_no) {
        $digits = preg_replace('/\D+/', '', (string) $version_no);
        return $digits === '' ? 0 : (int) $digits;
    }
}

if (!function_exists('zw_legal_text_archive_relative')) {
    /**
     * 归档相对路径（**纯函数，不写盘**）。
     *
     * @param string $doc_id     文档 ID。
     * @param string $version_no 版本号。
     * @return string
     */
    function zw_legal_text_archive_relative($doc_id, $version_no) {
        return 'docs/legal/' . trim((string) $doc_id) . '-' . trim((string) $version_no) . '.html';
    }
}
