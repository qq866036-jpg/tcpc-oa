<?php
/**
 * AI 法务部 · 政策正文内容规则（插件侧唯一实现）—— OA-2498 / TASK-2814 S1。
 *
 * 定位：政策正文（隐私政策 / 用户协议）**入库前**的硬校验，防止把产品文档 / PRD / 业务流程
 * 粘进对外合规文本。纯函数、零 WP 依赖，CLI 与后台发布器共用。
 *
 * 单源约定：主题 `scripts/privacy-policy-content-check.php` 的规则在 OA-2498 完成后改为
 * **薄包装**（优先加载本文件）；本文件是规则的唯一实现，禁止在主题再写第二套。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('tcpc_oa_legal_content_rules_version')) {
    /**
     * 规则版本（改规则必须 +1，便于审计与回归定位）。
     *
     * @return int
     */
    function tcpc_oa_legal_content_rules_version() {
        return 1;
    }
}

if (!function_exists('tcpc_oa_legal_content_rules_for')) {
    /**
     * 按文档取规则集；未登记的文档返回 null（不阻断，避免误伤内部文件）。
     *
     * @param string $doc_id 文档 ID（大小写不敏感）。
     * @return array{required:string[],forbidden:string[],max_length:int}|null
     */
    function tcpc_oa_legal_content_rules_for($doc_id) {
        $doc_id = strtoupper(trim((string) $doc_id));

        $product_words = array(
            // 中文词不用 `\b`：PCRE 的 `\b` 在「…到工作台点…」这类中文语境里不成立（实测漏报），
            // 这里按子串匹配；英文缩写才用词边界。
            '/工作台/u',
            '/后台菜单/u',
            '/快速编辑/u',
            '/字段映射/u',
            '/字段清单/u',
            '/数据库表/u',
            '/接口文档/u',
            '/API\s*文档/ui',
            '/操作步骤/u',
            '/流程图/u',
            '/原型/u',
            '/\bPRD\b/ui',
        );

        if ($doc_id === 'SRC-PRIVACY-POLICY') {
            return array(
                'required' => array(
                    '隐私政策',
                    '生效日期',
                    '更新日期',
                    '我们如何收集和使用您的个人信息',
                    '我们如何使用Cookie和同类技术',
                    '我们如何存储和保护您的个人信息',
                    '我们如何共享、转让、公开披露您的个人信息',
                    '您的权利',
                    '未成年人保护',
                    '隐私政策的更新',
                    '联系我们',
                    '个人信息保护负责人',
                    '存储期限',
                    '第三方 SDK',
                ),
                'forbidden' => array_merge($product_words, array(
                    '/按钮/u',
                    '/点击/u',
                    '/输入框/u',
                    '/下拉框/u',
                )),
                // TASK-2071（用户 2026-10-24 拍板 A）：上限 22000 → 22100。
                'max_length' => 22100,
            );
        }

        if ($doc_id === 'SRC-USER-AGREEMENT') {
            return array(
                'required' => array('用户协议', '生效', '本会', '用户'),
                'forbidden' => $product_words,
                // 用户协议正文比隐私政策长；上限只作"粘错文档"的护栏，不是法律硬线。
                'max_length' => 30000,
            );
        }

        return null;
    }
}

if (!function_exists('tcpc_oa_legal_content_plain_length')) {
    /**
     * 正文纯文本长度（去标签 / 折叠空白），与隐私政策既有口径一致。
     *
     * OA-2510：先剔除 `<script>/<style>` 块再计数 —— `strip_tags()` 会保留样式表**内容**，
     * 使内联 CSS 被当成正文（同一份隐私政策 22051 vs DB 归一化 15395），文档中心会误拒合法版本。
     * 上限 22100 与必备条款/禁用词判据不变。
     *
     * @param string $html 正文 HTML。
     * @return int
     */
    function tcpc_oa_legal_content_plain_length($html) {
        $src = preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $html);
        if ($src === null) { $src = (string) $html; }
        $txt = strip_tags($src);
        $txt = function_exists('html_entity_decode') ? html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $txt;
        $plain = trim((string) preg_replace('/\s+/u', ' ', $txt));
        return function_exists('mb_strlen') ? (int) mb_strlen($plain, 'UTF-8') : (int) floor(strlen($plain) / 3);
    }
}

if (!function_exists('tcpc_oa_legal_content_check')) {
    /**
     * 校验政策正文。
     *
     * @param string $doc_id 文档 ID。
     * @param string $html   正文 HTML。
     * @return array{ok:bool,errors:string[],warnings:string[],plainLength:int,rules_version:int}
     */
    function tcpc_oa_legal_content_check($doc_id, $html) {
        $html = (string) $html;
        $errors = array();
        $warnings = array();

        $rules = tcpc_oa_legal_content_rules_for($doc_id);
        if ($rules === null) {
            return array(
                'ok' => true,
                'errors' => array(),
                'warnings' => array('未登记内容规则的文档，跳过正文硬校验'),
                'plainLength' => tcpc_oa_legal_content_plain_length($html),
                'rules_version' => tcpc_oa_legal_content_rules_version(),
            );
        }

        foreach ($rules['required'] as $needle) {
            if (strpos($html, $needle) === false) {
                $errors[] = '缺少必需内容：' . $needle;
            }
        }
        foreach ($rules['forbidden'] as $pattern) {
            if (preg_match($pattern, $html)) {
                $errors[] = '命中产品文档禁用词：' . $pattern;
            }
        }

        $plain_len = tcpc_oa_legal_content_plain_length($html);
        $max = (int) $rules['max_length'];
        if ($max > 0 && $plain_len > $max) {
            $errors[] = '全文纯文本过长：' . $plain_len . ' 字，超过 ' . $max . ' 字上限';
        } elseif ($max > 0 && $plain_len > (int) floor($max * 0.95)) {
            $warnings[] = '全文纯文本接近上限：' . $plain_len . ' / ' . $max . ' 字';
        }

        return array(
            'ok' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'plainLength' => $plain_len,
            'rules_version' => tcpc_oa_legal_content_rules_version(),
        );
    }
}

if (!function_exists('tcpc_oa_legal_content_error_summary')) {
    /**
     * 把错误列表压成可读的一行（最多 3 条），供后台提示条使用。
     *
     * @param string[] $errors 错误列表。
     * @return string
     */
    function tcpc_oa_legal_content_error_summary($errors) {
        $errors = array_values(array_filter(array_map('strval', (array) $errors), 'strlen'));
        if (!$errors) {
            return '正文未通过内容规则检查';
        }
        $shown = array_slice($errors, 0, 3);
        $text = implode('；', $shown);
        if (count($errors) > 3) {
            $text .= '；等共 ' . count($errors) . ' 项';
        }
        return $text;
    }
}
