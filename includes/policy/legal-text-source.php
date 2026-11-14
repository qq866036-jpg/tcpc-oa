<?php
/**
 * 统一文本源 · 读源与版本模型（SPEC-88 §6.1 · TASK-1011 / 第 1 期）。
 *
 * 定位（用户口径，唯一拍板人）：
 *  - **AI 法务部数据库是唯一文本源**：`zw_policy_document`（主档）+ `zw_policy_document_version`（版本链）；
 *  - git `docs/legal/<doc>-<version>.html` 只是**自动导出的只读归档**，禁止人工编辑、不作渲染源；
 *  - 版本锚点统一为**整篇正文**规范化后的 SHA-256（`body_hash`）；
 *  - **确认生效 / 回退 / 归档导出仅 `manage_options`（用户本人）**；法务账号只读；**不设第二人复核**。
 *
 * 5 条机器护栏（本文件的落地位置）：
 *  ① 生效版本只读：任何改动只能"新增版本"（本文件只 INSERT 版本行，UPDATE 仅命中 `effective_at`）；
 *  ② 生效前强制 diff + 影响面：`zw_legal_text_diff()` 供后台展示与当前生效版本的差异；
 *  ③ 二次确认：`zw_legal_text_activate()` 要求 `$confirm_title` 与主档标题精确相等；
 *  ④ 一键回退：`zw_legal_text_rollback()` 新增 `is_rollback=1` 版本，历史一行不删；
 *  ⑤ 全链审计 + 归档：每次生效/回退写 `policy_audit_log`，归档导出生成 git 可提交副本。
 *
 * 兼容（SPEC-88 §6.1「未启用时回退」）：
 *  - 开关 `zw_legal_text_source` 默认 `legacy`（= 完全现状：协议正文走条款表、隐私政策走文件）；
 *  - `db` 模式下目标版本缺失/表不存在/正文为空 → `zw_legal_text_render()` 返回 `''` 并告警，
 *    由调用方回退旧源（**绝不返回空正文**下线，避免签署页白屏）。
 *
 * 依赖方向：DB（写/读）→ 归档（单向导出）。本文件**不**反向读归档文件，也不改既有判定引擎。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_LEGAL_TEXT_SOURCE_OPTION')) {
    define('ZW_LEGAL_TEXT_SOURCE_OPTION', 'zw_legal_text_source');
}

// AGENT-0423：纯函数抽到 logic/legal-text.php（函数名/签名不变；本文件负责 require）。
require_once __DIR__ . '/logic/legal-text.php';

if (!function_exists('zw_legal_text_table')) {
    /**
     * 统一文本源的表名（带 `$wpdb->prefix`）。
     *
     * @param string $name `policy_document` | `policy_document_version`。
     * @return string
     */
    function zw_legal_text_table($name) {
        global $wpdb;
        return $wpdb->prefix . 'zw_' . $name;
    }
}

if (!function_exists('zw_legal_text_array_a')) {
    /**
     * `$wpdb` 查询返回模式（测试环境可能未定义 `ARRAY_A` 常量）。
     *
     * @return string
     */
    function zw_legal_text_array_a() {
        return defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
    }
}

if (!function_exists('zw_legal_text_source')) {
    /**
     * 当前文本源开关（SPEC-88 §6.1）。
     *
     * @return string `db` | `legacy`（缺省/非法值一律归一为 `legacy`）。
     */
    function zw_legal_text_source() {
        $raw = function_exists('get_option') ? (string) get_option(ZW_LEGAL_TEXT_SOURCE_OPTION, 'legacy') : 'legacy';
        $raw = strtolower(trim($raw));
        return in_array($raw, array('db', 'legacy'), true) ? $raw : 'legacy';
    }
}

if (!function_exists('zw_legal_text_tables_ready')) {
    /**
     * 统一文本源两张表是否就绪。
     *
     * 优先复用 canonical 的 `zw_policy_platform_exists()`（后台/前端均已加载），
     * 不可用时退回 `SHOW TABLES LIKE`。
     *
     * @return bool
     */
    function zw_legal_text_tables_ready() {
        global $wpdb;
        $names = array('policy_document', 'policy_document_version');
        if (function_exists('zw_policy_platform_exists')) {
            foreach ($names as $n) {
                if (!zw_policy_platform_exists($n)) { return false; }
            }
            return true;
        }
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) { return false; }
        foreach ($names as $n) {
            $table = zw_legal_text_table($n);
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($found !== $table) { return false; }
        }
        return true;
    }
}

if (!function_exists('zw_legal_text_documents')) {
    /**
     * 文档主档列表（`update_time` 倒序）。
     *
     * @return array[] 每行关联数组；表缺失时返回空数组。
     */
    function zw_legal_text_documents() {
        global $wpdb;
        if (!zw_legal_text_tables_ready()) { return array(); }
        $table = zw_legal_text_table('policy_document');
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY update_time DESC, id ASC", zw_legal_text_array_a());
        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('zw_legal_text_document')) {
    /**
     * 单档主档。
     *
     * @param string $doc_id 文档 ID（与 `zw_policy_source.id` 对齐）。
     * @return array|null
     */
    function zw_legal_text_document($doc_id) {
        global $wpdb;
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '' || !zw_legal_text_tables_ready()) { return null; }
        $table = zw_legal_text_table('policy_document');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %s LIMIT 1", $doc_id), zw_legal_text_array_a());
        return is_array($row) && $row ? $row : null;
    }
}

if (!function_exists('zw_legal_text_versions')) {
    /**
     * 某文档的全部版本（`version_no` 数值倒序：`v10` 在 `v9` 之前）。
     *
     * @param string $doc_id 文档 ID。
     * @return array[]
     */
    function zw_legal_text_versions($doc_id) {
        global $wpdb;
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '' || !zw_legal_text_tables_ready()) { return array(); }
        $table = zw_legal_text_table('policy_document_version');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE doc_id = %s", $doc_id), zw_legal_text_array_a());
        if (!is_array($rows) || !$rows) { return array(); }
        usort($rows, function ($a, $b) {
            $na = zw_legal_text_version_number($a['version_no'] ?? '');
            $nb = zw_legal_text_version_number($b['version_no'] ?? '');
            if ($na === $nb) { return 0; }
            return $na > $nb ? -1 : 1; // 数值倒序
        });
        return $rows;
    }
}

if (!function_exists('zw_legal_text_latest_version')) {
    /**
     * 最新一个版本行（= `zw_legal_text_versions()` 的首行）。
     *
     * @param string $doc_id 文档 ID。
     * @return array|null
     */
    function zw_legal_text_latest_version($doc_id) {
        $rows = zw_legal_text_versions($doc_id);
        return $rows ? $rows[0] : null;
    }
}

if (!function_exists('zw_legal_text_version')) {
    /**
     * 取指定版本行。
     *
     * @param string $doc_id     文档 ID。
     * @param string $version_no 版本号。
     * @return array|null
     */
    function zw_legal_text_version($doc_id, $version_no) {
        global $wpdb;
        $doc_id = trim((string) $doc_id);
        $version_no = trim((string) $version_no);
        if ($doc_id === '' || $version_no === '' || !zw_legal_text_tables_ready()) { return null; }
        $table = zw_legal_text_table('policy_document_version');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE doc_id = %s AND version_no = %s LIMIT 1", $doc_id, $version_no), zw_legal_text_array_a());
        return is_array($row) && $row ? $row : null;
    }
}

if (!function_exists('zw_legal_text_current')) {
    /**
     * 当前生效版本行（主档 `current_version` 指向的版本；无则 null）。
     *
     * 注意：不校验 `effective_at`，需要判断"真的生效"请比对 `effective_at` 是否为空。
     *
     * @param string $doc_id 文档 ID。
     * @return array|null
     */
    function zw_legal_text_current($doc_id) {
        $doc = zw_legal_text_document($doc_id);
        if (!$doc) { return null; }
        $cur = trim((string) ($doc['current_version'] ?? ''));
        if ($cur === '') { return null; }
        return zw_legal_text_version($doc_id, $cur);
    }
}

if (!function_exists('zw_legal_text_next_version')) {
    /**
     * 下一个版本号：`v{最大数值+1}`（无版本时 `v1`）。
     *
     * @param string $doc_id 文档 ID。
     * @return string
     */
    function zw_legal_text_next_version($doc_id) {
        $max = 0;
        foreach (zw_legal_text_versions($doc_id) as $row) {
            $max = max($max, zw_legal_text_version_number($row['version_no'] ?? ''));
        }
        return 'v' . ($max + 1);
    }
}

if (!function_exists('zw_legal_text_upsert_document')) {
    /**
     * 登记/更新文档主档（**迁移与登记用，幂等**）。
     *
     * 只接受白名单字段，避免迁移脚本把任意键写进表；已存在则 UPDATE（保持 `create_time` 不变）。
     *
     * @param string $doc_id 文档 ID。
     * @param array  $fields 允许：title/doc_type/status/current_version/page_slug/source_file/applies_to。
     * @return string|WP_Error 文档 ID 或错误。
     */
    function zw_legal_text_upsert_document($doc_id, $fields) {
        global $wpdb;
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '') {
            return new WP_Error('zw_legal_text_no_doc', '文档 ID 为空。');
        }
        $allowed = array(
            'title'           => '',
            'doc_type'        => '',
            'status'          => '草稿',
            'current_version' => '',
            'page_slug'       => '',
            'source_file'     => '',
            'applies_to'      => '',
        );
        $data = array();
        foreach ($allowed as $key => $default) {
            if (array_key_exists($key, (array) $fields)) {
                $data[$key] = (string) $fields[$key];
            } elseif ($default !== '') {
                $data[$key] = $default;
            }
        }
        $now  = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
        $table = zw_legal_text_table('policy_document');
        $exists = zw_legal_text_document($doc_id);
        if ($exists) {
            $data['update_time'] = $now;
            $ok = $wpdb->update($table, $data, array('id' => $doc_id));
        } else {
            $data['id']          = $doc_id;
            $data['create_time'] = $now;
            $data['update_time'] = $now;
            $ok = $wpdb->insert($table, $data);
        }
        if ($ok === false) {
            return new WP_Error('zw_legal_text_doc_write_failed', '文档主档写入失败。');
        }
        return $doc_id;
    }
}

if (!function_exists('zw_legal_text_insert_version')) {
    /**
     * 版本行 INSERT（内部复用：`create_version` 与 `rollback` 都走这里）。
     *
     * 铁律：本函数**只 INSERT**，绝不 UPDATE 既有版本行（护栏 ①）。
     *
     * @param string $doc_id       文档 ID。
     * @param string $version_no   版本号。
     * @param string $body_html    整篇正文。
     * @param string $hash         正文哈希（调用方已算，避免重复计算）。
     * @param string $note         版本说明。
     * @param string $created_by   创建人（`wp_cli` / 登录名）。
     * @param int    $is_rollback  是否回退版本。
     * @param string $supersedes   被取代的版本号（无则空串）。
     * @return bool
     */
    function zw_legal_text_insert_version($doc_id, $version_no, $body_html, $hash, $note, $created_by, $is_rollback = 0, $supersedes = '') {
        global $wpdb;
        $table = zw_legal_text_table('policy_document_version');
        $now = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
        $ok = $wpdb->insert($table, array(
            'doc_id'      => (string) $doc_id,
            'version_no'  => (string) $version_no,
            'body_html'   => (string) $body_html,
            'body_hash'   => (string) $hash,
            'body_chars'  => (int) zw_legal_text_body_chars($body_html),
            'note'        => (string) $note,
            'created_by'  => (string) $created_by,
            'created_at'  => $now,
            'effective_at' => null,
            'supersedes'  => (string) $supersedes,
            'is_rollback' => $is_rollback ? 1 : 0,
        ));
        return $ok !== false;
    }
}

if (!function_exists('zw_legal_text_create_version')) {
    /**
     * 新建版本（**整篇正文编辑的唯一入口**）。
     *
     * 只 INSERT；出现下列情况拒绝并返回 `WP_Error`：
     *  - 主档不存在 → `zw_legal_text_no_doc`；
     *  - 正文为空（规范化后为空）→ `zw_legal_text_empty_body`；
     *  - 哈希与上一版相同（无变化）→ `zw_legal_text_no_change`。
     *
     * @param string $doc_id     文档 ID。
     * @param string $body_html  整篇正文。
     * @param string $note       版本说明（为什么改）。
     * @param string $created_by 创建人。
     * @return string|WP_Error 新版本号。
     */
    function zw_legal_text_create_version($doc_id, $body_html, $note, $created_by) {
        $doc_id = trim((string) $doc_id);
        $doc = zw_legal_text_document($doc_id);
        if (!$doc) {
            return new WP_Error('zw_legal_text_no_doc', '文档主档不存在：' . $doc_id);
        }
        $body_html = (string) $body_html;
        if (zw_legal_text_normalize($body_html) === '') {
            return new WP_Error('zw_legal_text_empty_body', '正文为空，未创建版本。');
        }
        $hash = zw_legal_text_hash($body_html);
        $prev = zw_legal_text_latest_version($doc_id);
        if ($prev && (string) ($prev['body_hash'] ?? '') === $hash) {
            return new WP_Error('zw_legal_text_no_change', '正文与上一版完全一致（哈希相同），未创建新版本。');
        }
        $version_no = zw_legal_text_next_version($doc_id);
        if (!zw_legal_text_insert_version($doc_id, $version_no, $body_html, $hash, (string) $note, (string) $created_by, 0, $prev ? (string) $prev['version_no'] : '')) {
            return new WP_Error('zw_legal_text_insert_failed', '版本写入失败。');
        }
        return $version_no;
    }
}

if (!function_exists('zw_legal_text_cli_context')) {
    /**
     * 是否处于受信的 CLI 上下文（`wp-cli`）。
     *
     * 迁移脚本以 `wp eval-file` 运行，此时没有登录用户、`current_user_can()` 恒假；
     * 命令行访问即等同站点管理员（唯一能上服务器的人）。**后台页面永远不满足此条件**。
     *
     * @return bool
     */
    function zw_legal_text_cli_context() {
        return defined('WP_CLI') && WP_CLI;
    }
}

if (!function_exists('zw_legal_text_audit')) {
    /**
     * 写统一文本源审计（复用 canonical `policy_audit_log`，**不新造审计表**）。
     *
     * CLI 语境下后台文件可能未加载 → 按需 require 后重试；仍不可用则 `error_log` 告警并返回
     * `WP_Error`，由调用方拒绝本次写操作（宁可不生效，也不留无审计的生效）。
     *
     * @param string $action      审计动作（`legal_text_activate` / `legal_text_rollback`）。
     * @param string $doc_id      文档 ID。
     * @param array  $before      前值。
     * @param array  $after       后值。
     * @param string $actor       操作者。
     * @param string $detail      说明。
     * @return true|WP_Error
     */
    function zw_legal_text_audit($action, $doc_id, $before, $after, $actor, $detail = '') {
        if (!function_exists('zw_policy_platform_audit_insert')) {
            // OA-2498 S1 有意差异（与主题副本）：审计写入由本仓 `policy-platform-admin.php` 提供；
            // 缺位即拒绝（fail-closed），**不反向 require 主题内部文件**（插件仓 ci-check ③）。
        }
        if (function_exists('zw_policy_platform_audit_insert')) {
            zw_policy_platform_audit_insert($action, 'policy_document', array((string) $doc_id), $before, $after, (string) $actor, (string) $detail);
            return true;
        }
        if (function_exists('error_log')) {
            error_log('[ZW-LEGAL-TEXT] audit unavailable: ' . $action . ' / ' . $doc_id);
        }
        return new WP_Error('zw_legal_text_audit_unavailable', '审计组件不可用，已拒绝本次操作。');
    }
}

if (!function_exists('zw_legal_text_activate')) {
    /**
     * 确认生效（**护栏 ①②③④⑤ 的落地点**）。
     *
     * 校验顺序：权限 → 主档 → 版本存在 → **哈希完整性**（防篡改）→ **二次确认**（文档名精确相等）。
     * 写入范围：目标版本行 `effective_at` + 主档 `status/current_version/update_time`；**绝不 UPDATE 任何
     * 版本行的 `body_html` / `body_hash`**。
     *
     * @param string $doc_id        文档 ID。
     * @param string $version_no    目标版本号。
     * @param string $actor         操作者（登录名 / `wp_cli`）。
     * @param string $confirm_title 二次确认：必须与主档 `title` 精确相等。
     * @return true|WP_Error
     */
    function zw_legal_text_activate($doc_id, $version_no, $actor, $confirm_title) {
        global $wpdb;
        if (!zw_legal_text_can_finalize() && !zw_legal_text_cli_context()) {
            return new WP_Error('zw_legal_text_forbidden', '仅站点管理员（manage_options）可确认生效。');
        }
        $doc_id = trim((string) $doc_id);
        $version_no = trim((string) $version_no);
        $doc = zw_legal_text_document($doc_id);
        if (!$doc) {
            return new WP_Error('zw_legal_text_no_doc', '文档主档不存在：' . $doc_id);
        }
        $version = zw_legal_text_version($doc_id, $version_no);
        if (!$version) {
            return new WP_Error('zw_legal_text_no_version', '版本不存在：' . $doc_id . ' / ' . $version_no);
        }
        $hash = zw_legal_text_hash((string) ($version['body_html'] ?? ''));
        if ($hash === '' || (string) ($version['body_hash'] ?? '') !== $hash) {
            return new WP_Error('zw_legal_text_hash_mismatch', '版本正文哈希与记录不一致（疑似被篡改），已拒绝生效。');
        }
        if (trim((string) $confirm_title) !== trim((string) ($doc['title'] ?? ''))) {
            return new WP_Error('zw_legal_text_confirm_mismatch', '二次确认失败：请完整输入文档名「' . (string) ($doc['title'] ?? '') . '」。');
        }

        $now = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
        $doc_table = zw_legal_text_table('policy_document');
        $ver_table = zw_legal_text_table('policy_document_version');

        // 护栏 ①：只允许写 effective_at 一个字段（正文与哈希永不 UPDATE）。
        $wpdb->update($ver_table, array('effective_at' => $now), array('doc_id' => $doc_id, 'version_no' => $version_no));
        $wpdb->update($doc_table, array(
            'status'          => '已生效',
            'current_version' => $version_no,
            'update_time'     => $now,
        ), array('id' => $doc_id));

        $detail = '统一文本源生效：' . $doc_id . ' ' . $version_no . '（哈希 ' . substr($hash, 0, 12) . '，字数 ' . (int) ($version['body_chars'] ?? 0) . '）';
        $result = zw_legal_text_audit('legal_text_activate', $doc_id, array(
            'status'          => (string) ($doc['status'] ?? ''),
            'current_version' => (string) ($doc['current_version'] ?? ''),
        ), array(
            'status'          => '已生效',
            'current_version' => $version_no,
            'version_no'      => $version_no,
            'body_hash'       => $hash,
        ), (string) $actor, $detail);

        // TASK-1019：生效即派生条款切片（单向 正文 → policy_clause）；失败不影响激活结果。
        if (!is_wp_error($result) && function_exists('zw_legal_text_derive_after_activate')) {
            zw_legal_text_derive_after_activate($doc_id, (string) $version_no);
        }
        return $result;
    }
}

if (!function_exists('zw_legal_text_rollback')) {
    /**
     * 一键回退（护栏 ④）：以目标历史版本正文**新增**一个 `is_rollback=1` 的版本，再走同一激活链路。
     *
     * 历史版本一行不删；`supersedes` 记录"被回退掉的当前版本"，形成可追溯方向。
     *
     * @param string $doc_id         文档 ID。
     * @param string $target_version 目标历史版本号。
     * @param string $actor          操作者。
     * @param string $confirm_title  二次确认（同激活）。
     * @return string|WP_Error 新版本号。
     */
    function zw_legal_text_rollback($doc_id, $target_version, $actor, $confirm_title) {
        if (!zw_legal_text_can_finalize() && !zw_legal_text_cli_context()) {
            return new WP_Error('zw_legal_text_forbidden', '仅站点管理员（manage_options）可回退。');
        }
        $doc_id = trim((string) $doc_id);
        $doc = zw_legal_text_document($doc_id);
        if (!$doc) {
            return new WP_Error('zw_legal_text_no_doc', '文档主档不存在：' . $doc_id);
        }
        $target = zw_legal_text_version($doc_id, $target_version);
        if (!$target) {
            return new WP_Error('zw_legal_text_no_version', '目标版本不存在：' . $doc_id . ' / ' . $target_version);
        }
        $body = (string) ($target['body_html'] ?? '');
        if (zw_legal_text_normalize($body) === '') {
            return new WP_Error('zw_legal_text_empty_body', '目标版本正文为空，拒绝回退。');
        }
        $hash = zw_legal_text_hash($body);
        $current = (string) ($doc['current_version'] ?? '');
        $version_no = zw_legal_text_next_version($doc_id);
        $note = '回退：以 ' . (string) $target_version . ' 的正文重建版本'
            . ($current !== '' ? '（被取代的生效版本 ' . $current . '）' : '');
        if (!zw_legal_text_insert_version($doc_id, $version_no, $body, $hash, $note, (string) $actor, 1, $current)) {
            return new WP_Error('zw_legal_text_insert_failed', '回退版本写入失败。');
        }
        $activated = zw_legal_text_activate($doc_id, $version_no, $actor, $confirm_title);
        if (is_wp_error($activated)) {
            return $activated;
        }
        zw_legal_text_audit('legal_text_rollback', $doc_id, array(
            'current_version' => $current,
            'target_version'  => (string) $target_version,
        ), array(
            'current_version' => $version_no,
            'is_rollback'     => 1,
            'supersedes'      => $current,
        ), (string) $actor, '统一文本源回退：' . $doc_id . ' → ' . $version_no . '（源自 ' . (string) $target_version . '）');
        return $version_no;
    }
}

if (!function_exists('zw_legal_text_diff')) {
    /**
     * 正文差异（护栏 ②：生效前强制看 diff）。
     *
     * 优先 `wp_text_diff()`；不可用时退化为主色无关的**逐行对比表**
     * （`<table class="zw-legal-diff">`，新增行 `zw-diff-add`、删除行 `zw-diff-del`）。
     *
     * @param string $old_html 旧正文。
     * @param string $new_html 新正文。
     * @return string HTML（已转义）。
     */
    function zw_legal_text_diff($old_html, $new_html) {
        if (function_exists('wp_text_diff')) {
            $diff = wp_text_diff((string) $old_html, (string) $new_html, array('show_split_view' => false));
            if (is_string($diff) && $diff !== '') { return $diff; }
        }
        $old_lines = preg_split('/\n/', (string) $old_html);
        $new_lines = preg_split('/\n/', (string) $new_html);
        $old_lines = is_array($old_lines) ? $old_lines : array();
        $new_lines = is_array($new_lines) ? $new_lines : array();
        $max = max(count($old_lines), count($new_lines));
        // TASK-1633（TASK-1579 P1-6 第四批）：退回的逐行对比表落 canonical 横滑包裹。
        // 唯一调用方是 policy-platform-document-center.php（页 slug `zw-policy-documents`，
        // 前缀 `zw-policy` 命中 zw_mc_adm_is_screen() ⇒ canonical 规则已由会员域外壳下沉），
        // 故此处只需包裹、不新增 <style>、不复制第二套声明。
        $html = '<div class="zw-adm-table-wrap"><table class="zw-legal-diff" style="width:100%;border-collapse:collapse;font-size:12.5px">';
        for ($i = 0; $i < $max; $i++) {
            $o = array_key_exists($i, $old_lines) ? $old_lines[$i] : null;
            $n = array_key_exists($i, $new_lines) ? $new_lines[$i] : null;
            if ($o === $n) {
                $html .= '<tr><td style="padding:2px 6px;color:#666;width:34px;text-align:right">' . (int) ($i + 1) . '</td><td style="padding:2px 6px">' . esc_html((string) $o) . '</td></tr>';
                continue;
            }
            if ($o !== null) {
                $html .= '<tr class="zw-diff-del" style="background:#fdecea"><td style="padding:2px 6px;color:#b3261e;width:34px;text-align:right">−</td><td style="padding:2px 6px;color:#b3261e">' . esc_html((string) $o) . '</td></tr>';
            }
            if ($n !== null) {
                $html .= '<tr class="zw-diff-add" style="background:#e8f5e9"><td style="padding:2px 6px;color:#1b5e20;width:34px;text-align:right">+</td><td style="padding:2px 6px;color:#1b5e20">' . esc_html((string) $n) . '</td></tr>';
            }
        }
        return $html . '</table></div>';
    }
}

if (!function_exists('zw_legal_text_effective_hash')) {
    /**
     * **当前对签署/判定生效的正文哈希**（TASK-1014 · 渲染源切换后的唯一锚点）。
     *
     * 口径：`source() === 'db'` 且表就绪 且 主档 `current_version` 指向的版本行 `body_hash` 非空
     * → 返回该 `body_hash`（= 整篇正文规范化 SHA-256）；
     * 否则返回 `''`，**调用方必须据此回退 legacy 口径**（条款拼接哈希），不得静默改判据。
     *
     * @param string $doc_id 文档 ID。
     * @return string 十六进制哈希；不可用时为空串。
     */
    function zw_legal_text_effective_hash($doc_id) {
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '' || zw_legal_text_source() !== 'db') { return ''; }
        if (!zw_legal_text_tables_ready()) { return ''; }
        $current = zw_legal_text_current($doc_id);
        if (!$current) { return ''; }
        $hash = trim((string) ($current['body_hash'] ?? ''));
        if ($hash === '') { return ''; }
        // 纵深防御（TASK-1014 决策⑦）：生效版本行本应不可变，读源时再自证一次；
        // 哈希与正文不符说明数据被篡改/损坏 → 拒绝采信并回退 legacy（fail-closed），绝不按该哈希渲染。
        if ($hash !== zw_legal_text_hash((string) ($current['body_html'] ?? ''))) {
            zw_legal_text_log_fallback($doc_id, 'body_hash_mismatch');
            return '';
        }
        return $hash;
    }
}

if (!function_exists('zw_legal_text_effective_body')) {
    /**
     * **当前生效版本的正文 HTML**（TASK-1014 · db 模式渲染源）。
     *
     * 与 `zw_legal_text_render()` 同判据，但语义更窄：只返回"生效版本正文"，不做净化
     * （净化由调用方按各自白名单完成，例：签署页走 `zw_legal_consent_body_allowed_html()`）。
     * 不可用时返回 `''`，调用方回退 legacy。
     *
     * @param string $doc_id 文档 ID。
     * @return string HTML；不可用时为空串。
     */
    function zw_legal_text_effective_body($doc_id) {
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '' || zw_legal_text_source() !== 'db') { return ''; }
        if (!zw_legal_text_tables_ready()) {
            zw_legal_text_log_fallback($doc_id, 'tables_missing');
            return '';
        }
        $current = zw_legal_text_current($doc_id);
        $body = $current ? (string) ($current['body_html'] ?? '') : '';
        if (zw_legal_text_normalize($body) === '') {
            zw_legal_text_log_fallback($doc_id, 'current_version_unavailable');
            return '';
        }
        // 纵深防御（TASK-1014 决策⑦）：正文必须与生效版本记录的哈希自洽，否则回退 legacy 并告警。
        $recorded = trim((string) ($current['body_hash'] ?? ''));
        if ($recorded !== '' && $recorded !== zw_legal_text_hash($body)) {
            zw_legal_text_log_fallback($doc_id, 'body_hash_mismatch');
            return '';
        }
        return $body;
    }
}

if (!function_exists('zw_legal_text_signature_rows')) {
    /**
     * 存在签署记录的用户数（**纯读**，供切换器安全闸使用）。
     *
     * 判据：`usermeta` 中存在 `zw_legal_consents` 且值非空/非 `'[]'`/非 `'{}'` 的用户。
     * 用于回答"现在切换哈希口径是否会改变重签判定"——有记录时须人工确认（`force`）才允许切换。
     *
     * @return int
     */
    function zw_legal_text_signature_rows() {
        global $wpdb;
        if (!is_object($wpdb) || !isset($wpdb->usermeta)) { return 0; }
        $sql = "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}"
             . " WHERE meta_key = %s AND meta_value IS NOT NULL AND meta_value <> ''"
             . " AND meta_value NOT IN ('[]','{}','a:0:{}')";
        $n = $wpdb->get_var($wpdb->prepare($sql, 'zw_legal_consents'));
        return (int) $n;
    }
}

if (!function_exists('zw_legal_text_render')) {
    /**
     * 读源渲染（**双读**）：`db` 模式且当前版本正文可用时返回该正文，否则返回 `''`。
     *
     * 返回 `''` 表示"本函数不提供正文"，调用方据此回退旧源（条款表 / 文件），**绝不返回空正文下线**。
     *
     * @param string $doc_id 文档 ID。
     * @return string
     */
    function zw_legal_text_render($doc_id) {
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '' || zw_legal_text_source() !== 'db') {
            return '';
        }
        if (!zw_legal_text_tables_ready()) {
            zw_legal_text_log_fallback($doc_id, 'tables_missing');
            return '';
        }
        $current = zw_legal_text_current($doc_id);
        $body = $current ? (string) ($current['body_html'] ?? '') : '';
        if (zw_legal_text_normalize($body) === '') {
            zw_legal_text_log_fallback($doc_id, 'current_version_unavailable');
            return '';
        }
        return $body;
    }
}

if (!function_exists('zw_legal_text_log_fallback')) {
    /**
     * 回退告警（只在 `db` 模式读不到正文时调用，避免 legacy 默认态刷日志）。
     *
     * @param string $doc_id 文档 ID。
     * @param string $why    原因标记。
     * @return void
     */
    function zw_legal_text_log_fallback($doc_id, $why = '') {
        $line = '[ZW-LEGAL-TEXT] fallback to legacy: ' . (string) $doc_id . ($why !== '' ? ' (' . $why . ')' : '');
        // 测试/运维可注入采集器（`zw_legal_text_logger`）；未注入时走 PHP error_log。
        if (function_exists('zw_legal_text_logger')) {
            zw_legal_text_logger($line);
            return;
        }
        if (function_exists('error_log')) {
            error_log($line);
        }
    }
}

if (!function_exists('zw_legal_text_render_source')) {
    /**
     * 实际生效的正文来源（供调用方与页面标注；测试亦依赖）。
     *
     * @param string $doc_id 文档 ID。
     * @return string `db` | `legacy`
     */
    function zw_legal_text_render_source($doc_id) {
        return zw_legal_text_render($doc_id) !== '' ? 'db' : 'legacy';
    }
}

if (!function_exists('zw_legal_text_can_finalize')) {
    /**
     * 终审权限（护栏：**只有用户本人**）。单点判据，页面与处理器共用。
     *
     * @return bool
     */
    function zw_legal_text_can_finalize() {
        return function_exists('current_user_can') ? (bool) current_user_can('manage_options') : false;
    }
}

if (!function_exists('zw_legal_text_can_view')) {
    /**
     * 查看权限：站点管理员或 AI 法务部既有能力位（`tcpc_perm_legal`）。
     *
     * @return bool
     */
    function zw_legal_text_can_view() {
        if (!function_exists('current_user_can')) { return false; }
        if (current_user_can('manage_options')) { return true; }
        if (function_exists('zw_legal_consent_admin_can') && zw_legal_consent_admin_can()) { return true; }
        return (bool) current_user_can('tcpc_perm_legal');
    }
}

if (!function_exists('zw_legal_text_archive_header')) {
    /**
     * 归档文件头（只读镜像声明）。
     *
     * @param string $doc_id     文档 ID。
     * @param string $version_no 版本号。
     * @param string $hash       正文哈希。
     * @return string
     */
    function zw_legal_text_archive_header($doc_id, $version_no, $hash = '') {
        $now = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
        return "<!--\n"
            . "  自动导出只读归档，禁止人工编辑。\n"
            . "  源 = AI 法务部「文档中心」（DB 为唯一文本源）；本文件仅供灾备、对外副本与工程追溯，不是渲染源。\n"
            . "  文档：" . trim((string) $doc_id) . " ｜ 版本：" . trim((string) $version_no) . "\n"
            . "  正文哈希：" . (string) $hash . "\n"
            . "  导出时间：" . (string) $now . "\n"
            . "-->\n";
    }
}

if (!function_exists('zw_legal_text_export_archive')) {
    /**
     * 归档导出（护栏 ⑤）：把某版本正文写为 `docs/legal/<doc>-<version>.html`。
     *
     * 方向铁律：DB → 归档（单向）。导出物**不是**渲染源，禁止人工编辑；重复导出覆盖同名文件。
     *
     * @param string $doc_id     文档 ID。
     * @param string $version_no 版本号。
     * @return string|WP_Error 相对路径。
     */
    function zw_legal_text_export_archive($doc_id, $version_no) {
        if (!zw_legal_text_can_finalize() && !zw_legal_text_cli_context()) {
            return new WP_Error('zw_legal_text_forbidden', '仅站点管理员（manage_options）可导出归档。');
        }
        $doc_id = trim((string) $doc_id);
        $version_no = trim((string) $version_no);
        $version = zw_legal_text_version($doc_id, $version_no);
        if (!$version) {
            return new WP_Error('zw_legal_text_no_version', '版本不存在：' . $doc_id . ' / ' . $version_no);
        }
        $body = (string) ($version['body_html'] ?? '');
        if (zw_legal_text_normalize($body) === '') {
            return new WP_Error('zw_legal_text_empty_body', '版本正文为空，拒绝导出。');
        }
        $base = '';
        // OA-2498 S1 有意差异（与主题副本）：归档写到**上传目录**（`uploads/tcpc-legal/`），
        // 不再写主题 `docs/legal/` —— 政策更新是 DB 发布，不要求主题发版/commit。
        if (function_exists('wp_upload_dir')) {
            $zw_upload = wp_upload_dir();
            if (is_array($zw_upload) && !empty($zw_upload['basedir'])) {
                $base = rtrim((string) $zw_upload['basedir'], '/') . '/tcpc-legal';
            }
        }
        if ($base === '') {
            return new WP_Error('zw_legal_text_archive_path_unavailable', '无法确定上传目录，归档未导出。');
        }
        $relative = zw_legal_text_archive_relative($doc_id, $version_no);
        $absolute = rtrim($base, '/') . '/' . $relative;
        $dir = dirname($absolute);
        if (!is_dir($dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($dir);
            } else {
                @mkdir($dir, 0755, true);
            }
        }
        if (!is_dir($dir)) {
            return new WP_Error('zw_legal_text_archive_mkdir_failed', '归档目录创建失败：' . $dir);
        }
        $content = zw_legal_text_archive_header($doc_id, $version_no, (string) ($version['body_hash'] ?? '')) . $body . "\n";
        $written = @file_put_contents($absolute, $content);
        if ($written === false) {
            return new WP_Error('zw_legal_text_archive_write_failed', '归档写入失败：' . $relative);
        }
        return $relative;
    }
}

if (!function_exists('zw_legal_text_clause_plain_text')) {
    /**
     * 条款节点文本 → 纯文本（TASK-1019）。
     *
     * 与 `zw_legal_text_normalize()` 的关键区别：**不折叠内部空白**。
     * 正文拼装走 `esc_html()`，实体（`&amp;`/`&quot;`/`&#039;`）必须解回原字符，
     * 重建时才能**逐字节**还原；若折叠空白，含换行/多空格的条款文本将无法往返一致。
     *
     * @param string $html 片段 HTML。
     * @return string
     */
    function zw_legal_text_clause_plain_text($html) {
        if (!is_scalar($html)) { return ''; }
        $text = (string) $html;
        if (function_exists('wp_strip_all_tags')) {
            $text = (string) wp_strip_all_tags($text);
        } else {
            $text = (string) strip_tags($text);
        }
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim($text);
    }
}

if (!function_exists('zw_legal_text_parse_body_clauses')) {
    /**
     * 从生效正文解析出条款切片（TASK-1019 · 正文 → 条款的 canonical 解析器）。
     *
     * 识别正文拼装的固定结构：
     *   `<ol class="zw-legal-body"><li><strong class="zw-legal-article">一</strong>
     *    <em class="zw-legal-item">（一）</em><p>正文</p></li>…</ol>`
     * `<strong>`/`<em>` 可缺；正文取该 `<li>` 内全部 `<p>`（无 `<p>` 时取剩余文本）。
     * 输出按 canonical 文档序（`zw_legal_consent_clause_compare()`）排序。
     *
     * 健壮性：空/非标量/无 `<li>` → 返回空数组且**不告警**。
     *
     * @param string $body_html 生效版本正文 HTML。
     * @return array[] 每项：`article_no` / `item_no` / `original_text`
     */
    function zw_legal_text_parse_body_clauses($body_html) {
        $out = array();
        if (!is_scalar($body_html)) { return $out; }
        $html = (string) $body_html;
        if ($html === '' || stripos($html, '<li') === false) { return $out; }
        $m = array();
        if (!preg_match_all('#<li\b[^>]*>(.*?)</li>#is', $html, $m) || empty($m[1])) { return $out; }
        foreach ($m[1] as $inner) {
            $inner = (string) $inner;
            $article = '';
            $item    = '';
            if (preg_match('#<strong\b[^>]*class\s*=\s*"[^"]*zw-legal-article[^"]*"[^>]*>(.*?)</strong>#is', $inner, $a)) {
                $article = zw_legal_text_clause_plain_text($a[1]);
                $inner   = str_replace($a[0], '', $inner);
            }
            if (preg_match('#<em\b[^>]*class\s*=\s*"[^"]*zw-legal-item[^"]*"[^>]*>(.*?)</em>#is', $inner, $b)) {
                $item  = zw_legal_text_clause_plain_text($b[1]);
                $inner = str_replace($b[0], '', $inner);
            }
            $text = '';
            $p = array();
            if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $inner, $p) && !empty($p[1])) {
                // canonical 正文每 <li> 只有一个 <p>；多段时以换行保留段间分隔（非 canonical 输入不保证往返逐字节）。
                $text = implode("\n", array_map('zw_legal_text_clause_plain_text', $p[1]));
            } else {
                $text = zw_legal_text_clause_plain_text($inner);
            }
            if ($article === '' && $item === '' && $text === '') { continue; }
            $out[] = array(
                'article_no'    => $article,
                'item_no'       => $item,
                'original_text' => $text,
            );
        }
        if ($out && function_exists('zw_legal_consent_clause_compare')) {
            usort($out, 'zw_legal_consent_clause_compare');
        }
        return $out;
    }
}

if (!function_exists('zw_legal_text_clause_table')) {
    /**
     * `policy_clause` 表名（不可用时返回空串）。
     *
     * @return string
     */
    function zw_legal_text_clause_table() {
        if (!function_exists('zw_policy_platform_table') || !function_exists('zw_policy_platform_exists')) { return ''; }
        if (!zw_policy_platform_exists('policy_clause')) { return ''; }
        return (string) zw_policy_platform_table('policy_clause');
    }
}

if (!function_exists('zw_legal_text_clause_excluded_docs')) {
    /**
     * 明确**不做**条款切片的文档（TASK-1539 · 用户拍板 D1，2026-10-13）。
     *
     * 为何排除《章程》：铁律 19 —— `/bhzc` 是《章程》唯一对外官网页，且章程不得纳入统一文本源的
     * 渲染/发布通道。用户 2026-10-13 明确选择「不纳入条款切片（保持只读）」⇒ 派生与对账都跳过它，
     * 其正文一行不改、`page_slug` 保持为空。
     *
     * @return string[] 文档 ID 列表。
     */
    function zw_legal_text_clause_excluded_docs() {
        $docs = array('SRC-CHARTER');
        if (function_exists('apply_filters')) {
            $docs = (array) apply_filters('zw_legal_text_clause_excluded_docs', $docs);
        }
        $out = array();
        foreach ($docs as $d) {
            $d = trim((string) $d);
            if ($d !== '') { $out[] = $d; }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('zw_legal_text_clause_id')) {
    /**
     * 条款切片的**稳定主键**（TASK-1539 · 唯一出处；派生 INSERT 与对账匹配共用）。
     *
     * 两条口径（修的是 TASK-1537 定位的「派生键塌缩 / 空主键冻结」）：
     *  - **有条号或项号**：键只由 `(条号|项号)` 决定 ⇒ 正文改文本仍是同一主键，走 UPDATE，保留行与状态；
     *  - **两者都为空**（隐私政策 / 用户协议这类只有 `<li>` 而没有 `zw-legal-article` 的体例）：
     *    键由该条**正文归一化文本**的哈希决定 ⇒ 每条各得一个主键，**不再整篇塌缩到同一个空键**；
     *    文本被改写 ⇒ 新键（新行 + 旧行按"已不在生效正文中"走 `已退回`），与"条号变更"同语义。
     *
     * @param string $doc_id     文档 ID。
     * @param string $article_no 条号（可为空）。
     * @param string $item_no    项号（可为空）。
     * @param string $text       条款正文（无条号时用于稳定键）。
     * @return string 形如 `CLS-XXXXXXXXXX`（varchar(64) 主键内）。
     */
    function zw_legal_text_clause_id($doc_id, $article_no, $item_no, $text) {
        $doc_id  = trim((string) $doc_id);
        $article = trim((string) $article_no);
        $item    = trim((string) $item_no);
        if ($article !== '' || $item !== '') {
            $basis = 'A|' . $article . '|' . $item;
        } else {
            $norm = function_exists('zw_legal_text_normalize')
                ? (string) zw_legal_text_normalize((string) $text)
                : trim((string) $text);
            if ($norm === '') { $norm = (string) $text; }
            $basis = 'I|' . $norm;
        }
        return 'CLS-' . strtoupper(substr(hash('sha256', $doc_id . '|' . $basis), 0, 10));
    }
}

if (!function_exists('zw_legal_text_sync_clauses')) {
    /**
     * 条款切片派生（TASK-1019 · **单向**：生效正文 → `policy_clause`）。
     *
     * 铁律：
     *  1. **只写 `policy_clause`**，任何情况下不得反向改写 `zw_policy_document(_version)`；
     *  2. 以 `(article_no, item_no)` 为键：正文新增 → INSERT；文本变化 → UPDATE（保留 id）；
     *     正文中已不存在的旧 `已确认` 行 → `已退回` + `review_opinion` 注明（复用既有状态词表，不新增枚举）；
     *  3. **幂等**：无差异时零写入（连 `update_time` 都不动）；
     *  4. 有实际写入才写审计 `legal_text_clauses_derive`。
     *
     * @param string $doc_id     文档 ID。
     * @param string $version_no 指定版本（空 = 当前生效版本）。
     * @return array|WP_Error 统计：version/parsed/insert/update/deactivate/unchanged/written
     */
    function zw_legal_text_sync_clauses($doc_id, $version_no = '') {
        global $wpdb;
        $doc_id = trim((string) $doc_id);
        $stats  = array(
            'version'    => (string) $version_no,
            'parsed'     => 0,
            'insert'     => 0,
            'update'     => 0,
            'deactivate' => 0,
            'unchanged'  => 0,
            'skipped'    => 0,
            'excluded'   => false,
            'written'    => false,
        );
        if ($doc_id === '') { return new WP_Error('zw_legal_text_no_doc', '文档 ID 为空。'); }

        // TASK-1539（用户拍板 D1）：明确排除的文档不做条款切片（《章程》保持只读，不纳入任何派生通道）。
        if (in_array($doc_id, zw_legal_text_clause_excluded_docs(), true)) {
            $stats['excluded'] = true;
            return $stats;
        }

        $table = zw_legal_text_clause_table();
        if ($table === '') { return new WP_Error('zw_legal_text_no_clause_table', '条款表不可用（policy_clause 不存在）。'); }

        $version = null;
        if (trim((string) $version_no) !== '') {
            $version = function_exists('zw_legal_text_version') ? zw_legal_text_version($doc_id, $version_no) : null;
        } else {
            $version = function_exists('zw_legal_text_current') ? zw_legal_text_current($doc_id) : null;
        }
        if (!$version) {
            return new WP_Error('zw_legal_text_no_version', '找不到可用版本：' . $doc_id . ' ' . (string) $version_no);
        }
        $version_no = (string) ($version['version_no'] ?? '');
        $body       = (string) ($version['body_html'] ?? '');
        if (zw_legal_text_normalize($body) === '') {
            return new WP_Error('zw_legal_text_empty_body', '生效版本正文为空，拒绝派生：' . $doc_id . ' ' . $version_no);
        }

        $parsed = zw_legal_text_parse_body_clauses($body);
        $stats['version'] = $version_no;
        $stats['parsed']  = count($parsed);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, article_no, item_no, original_text, status, policy_version FROM {$table} WHERE source_id=%s",
            $doc_id
        ), zw_legal_text_array_a());
        $rows = is_array($rows) ? $rows : array();

        // TASK-1539：匹配键改为**稳定主键**（唯一出处 `zw_legal_text_clause_id()`），修 TASK-1537 定位的
        // 「无条号正文整篇塌缩到同一个空键 ⇒ 只剩 1 行、其余主键冲突丢失」。
        $existing      = array();
        $empty_id_rows = array();
        foreach ($rows as $row) {
            $key = zw_legal_text_clause_id($doc_id, (string) ($row['article_no'] ?? ''), (string) ($row['item_no'] ?? ''), (string) ($row['original_text'] ?? ''));
            if ((string) ($row['id'] ?? '') === '') {
                // 历史遗留：早期派生 INSERT 未带主键 ⇒ 落成空串主键，此后永远无法被 UPDATE 命中（TASK-1021 守卫）。
                // 这里**不猜行**（`WHERE id=''` 可能误伤多行），只登记；由 repair 脚本在用户授权下补主键，
                // 期间该条按「待修复」跳过，绝不重复 INSERT 造成同文两条。
                $empty_id_rows[$key] = $row;
                continue;
            }
            $existing[$key] = $row;
        }

        $planned = array();   // 计划：每项 array(op, key, id, data)
        $skipped = array();   // 计划外跳过（含原因），用于失败可观测
        $seen    = array();
        foreach ($parsed as $entry) {
            $article = (string) $entry['article_no'];
            $item    = (string) $entry['item_no'];
            $text    = (string) $entry['original_text'];
            $key     = zw_legal_text_clause_id($doc_id, $article, $item, $text);
            $seen[$key] = true;
            if (isset($empty_id_rows[$key])) {
                $stats['skipped']++;
                $skipped[] = $key . '：条款行主键为空（历史遗留），需先补主键';
                continue;
            }
            if (!isset($existing[$key])) {
                $planned[] = array('op' => 'insert', 'key' => $key, 'id' => $key, 'article' => $article, 'item' => $item, 'text' => $text);
                continue;
            }
            $row = $existing[$key];
            $same_text = (string) ($row['original_text'] ?? '') === $text;
            $same_ver  = (string) ($row['policy_version'] ?? '') === $version_no;
            $same_st   = (string) ($row['status'] ?? '') === '已确认';
            if ($same_text && $same_ver && $same_st) {
                $stats['unchanged']++;
                continue;
            }
            $planned[] = array('op' => 'update', 'key' => $key, 'id' => (string) ($row['id'] ?? ''), 'article' => $article, 'item' => $item, 'text' => $text, 'status' => '已确认');
        }
        foreach ($existing as $key => $row) {
            if (isset($seen[$key])) { continue; }
            if ((string) ($row['status'] ?? '') !== '已确认') { continue; }
            $planned[] = array('op' => 'deactivate', 'key' => $key, 'id' => (string) ($row['id'] ?? ''), 'article' => (string) ($row['article_no'] ?? ''), 'item' => (string) ($row['item_no'] ?? ''), 'text' => (string) ($row['original_text'] ?? ''));
        }

        if (!$planned) {
            // TASK-1539 失败可观测：计划为空但存在跳过项时**必须留痕**，不得像 TASK-1537 定位的那样静默通过。
            if ($stats['skipped'] > 0) {
                zw_legal_text_sync_clauses_report_skipped($doc_id, $version_no, $skipped);
            }
            return $stats;   // 幂等：零写入
        }

        $now = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
        foreach ($planned as $p) {
            if ($p['op'] === 'insert') {
                $ok = $wpdb->insert($table, array(
                    'id'             => (string) $p['id'],   // TASK-1539：**显式主键**（修「空串主键 ⇒ 永久冻结」）
                    'source_id'      => $doc_id,
                    'article_no'     => $p['article'],
                    'item_no'        => $p['item'],
                    'original_text'  => $p['text'],
                    'policy_version' => $version_no,
                    'status'         => '已确认',
                    'created_by'     => 'derived-from-doc-version',
                    'create_time'    => $now,
                    'update_time'    => $now,
                ));
                if ($ok !== false) {
                    $stats['insert']++;
                } else {
                    $stats['skipped']++;
                    $skipped[] = (string) $p['key'] . '：写入失败（主键冲突或字段不合法）';
                }
                continue;
            }
            if ($p['op'] === 'update') {
                // TASK-1021：id 为字符串主键（如 CLS-0A8DAEBCC8）；空 id 一律跳过，避免 WHERE id='' 误伤。
                if ((string) $p['id'] === '') {
                    $stats['skipped']++;
                    $skipped[] = (string) $p['key'] . '：待更新行主键为空，已跳过';
                    continue;
                }
                $ok = $wpdb->update($table, array(
                    'original_text'  => $p['text'],
                    'policy_version' => $version_no,
                    'status'         => '已确认',
                    'created_by'     => 'derived-from-doc-version',
                    'update_time'    => $now,
                ), array('id' => (string) $p['id']));
                if ($ok !== false) { $stats['update']++; }
                continue;
            }
            if ((string) $p['id'] === '') {   // TASK-1021：同上，空 id 跳过
                $stats['skipped']++;
                $skipped[] = (string) $p['key'] . '：待停用行主键为空，已跳过';
                continue;
            }
            $ok = $wpdb->update($table, array(
                'status'         => '已退回',
                'review_opinion' => '已不在生效正文中（TASK-1019 派生）',
                'update_time'    => $now,
            ), array('id' => (string) $p['id']));
            if ($ok !== false) { $stats['deactivate']++; }
        }

        $stats['written'] = ($stats['insert'] + $stats['update'] + $stats['deactivate']) > 0;
        if ($stats['written']) {
            $detail = '条款切片派生：' . $doc_id . ' ' . $version_no
                . '（解析 ' . (int) $stats['parsed'] . ' 条；新增 ' . (int) $stats['insert']
                . ' / 更新 ' . (int) $stats['update'] . ' / 停用 ' . (int) $stats['deactivate']
                . ' / 未变 ' . (int) $stats['unchanged'] . ' / 跳过 ' . (int) $stats['skipped'] . '）';
            zw_legal_text_audit('legal_text_clauses_derive', $doc_id, array(
                'version' => (string) $version_no,
            ), array(
                'parsed'     => (int) $stats['parsed'],
                'insert'     => (int) $stats['insert'],
                'update'     => (int) $stats['update'],
                'deactivate' => (int) $stats['deactivate'],
                'unchanged'  => (int) $stats['unchanged'],
                'skipped'    => (int) $stats['skipped'],
            ), 'derived-from-doc-version', $detail);
        }
        // TASK-1539 失败可观测：有任何跳过（写入失败 / 空主键待修）都必须留痕，绝不静默。
        if ($stats['skipped'] > 0) {
            zw_legal_text_sync_clauses_report_skipped($doc_id, $version_no, $skipped);
        }
        return $stats;
    }
}

if (!function_exists('zw_legal_text_sync_clauses_report_skipped')) {
    /**
     * 派生「跳过项」留痕（TASK-1539 · 修 TASK-1537 定位的**静默失败**）。
     *
     * 口径：`计划有写入但实际写入 0`（或部分跳过）时，必须同时 ①写审计 `legal_text_clauses_derive_skipped`
     * ②`error_log` 告警，使"覆盖率缺口"可被对账与日志发现；不改任何正文与既有行。
     *
     * @param string   $doc_id     文档 ID。
     * @param string   $version_no 版本号。
     * @param string[] $skipped    跳过明细。
     * @return void
     */
    function zw_legal_text_sync_clauses_report_skipped($doc_id, $version_no, $skipped) {
        $list = array();
        foreach ((array) $skipped as $one) {
            $one = trim((string) $one);
            if ($one !== '') { $list[] = $one; }
        }
        if (!$list) { return; }
        $detail = '条款切片派生跳过 ' . count($list) . ' 项：' . $doc_id . ' ' . (string) $version_no
            . '（' . implode('；', array_slice($list, 0, 10)) . '）';
        if (function_exists('error_log')) { error_log('[ZW-LEGAL-TEXT] ' . $detail); }
        zw_legal_text_audit('legal_text_clauses_derive_skipped', $doc_id, array(
            'version' => (string) $version_no,
        ), array(
            'skipped' => count($list),
            'items'   => array_slice($list, 0, 50),
        ), 'derived-from-doc-version', $detail);
    }
}

if (!function_exists('zw_legal_text_derive_after_activate')) {
    /**
     * 激活后自动派生条款切片（TASK-1019 薄封装）。
     *
     * **失败绝不影响激活结果**：只 `error_log` 告警 + 写一条审计说明。
     *
     * @param string $doc_id     文档 ID。
     * @param string $version_no 刚生效的版本号。
     * @return array|WP_Error
     */
    function zw_legal_text_derive_after_activate($doc_id, $version_no) {
        if (!function_exists('zw_legal_text_sync_clauses')) { return new WP_Error('zw_legal_text_no_sync', '派生器不可用。'); }
        $result = zw_legal_text_sync_clauses($doc_id, $version_no);
        if (is_wp_error($result)) {
            $code = (string) $result->get_error_code();
            $msg  = '[ZW-LEGAL-TEXT] clauses derive failed: ' . $doc_id . ' ' . $version_no . ' / ' . $code;
            if (function_exists('error_log')) { error_log($msg); }
            // 条款表不存在 = 政策平台未安装 → 属「不适用」，不写失败审计（避免噪声写入）。
            if ($code !== 'zw_legal_text_no_clause_table') {
                zw_legal_text_audit('legal_text_clauses_derive_failed', $doc_id, array(
                    'version' => (string) $version_no,
                ), array('error' => $code), 'derived-from-doc-version', $msg);
            }
        }
        return $result;
    }
}

if (!function_exists('zw_legal_text_verify_clauses')) {
    /**
     * 条款派生对账（TASK-1019）：已确认条款 == 生效正文条目（条数 / 逐条文本 / 版本号 / 文档序）。
     *
     * @param string $doc_id 文档 ID。
     * @return array `array('ok'=>bool, 'version'=>string, 'count'=>int, 'failures'=>string[])`
     */
    function zw_legal_text_verify_clauses($doc_id) {
        global $wpdb;
        $doc_id = trim((string) $doc_id);
        $out = array('ok' => false, 'version' => '', 'count' => 0, 'excluded' => false, 'failures' => array());
        // TASK-1539（用户拍板 D1）：明确排除的文档（《章程》）不参与对账 —— 视为"不适用且通过"。
        if (in_array($doc_id, zw_legal_text_clause_excluded_docs(), true)) {
            $out['ok'] = true;
            $out['excluded'] = true;
            return $out;
        }
        $table = zw_legal_text_clause_table();
        if ($table === '') { $out['failures'][] = '条款表不可用（policy_clause 不存在）'; return $out; }
        $version = function_exists('zw_legal_text_current') ? zw_legal_text_current($doc_id) : null;
        if (!$version) { $out['failures'][] = '无生效版本：' . $doc_id; return $out; }
        $version_no = (string) ($version['version_no'] ?? '');
        $parsed = zw_legal_text_parse_body_clauses((string) ($version['body_html'] ?? ''));
        $out['version'] = $version_no;
        $out['count']   = count($parsed);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, article_no, item_no, original_text, status, policy_version FROM {$table} WHERE source_id=%s",
            $doc_id
        ), zw_legal_text_array_a());
        $rows = is_array($rows) ? $rows : array();
        // TASK-1539：与派生**同一稳定键**（zw_legal_text_clause_id），否则无条号正文（隐私政策/用户协议）
        // 会因"空键全等"而对账失真。
        $confirmed = array();
        foreach ($rows as $row) {
            if ((string) ($row['status'] ?? '') !== '已确认') { continue; }
            $key = zw_legal_text_clause_id($doc_id, (string) ($row['article_no'] ?? ''), (string) ($row['item_no'] ?? ''), (string) ($row['original_text'] ?? ''));
            $confirmed[$key] = $row;
        }
        if (count($confirmed) !== count($parsed)) {
            $out['failures'][] = '条数不一致：已确认 ' . count($confirmed) . ' 条 vs 正文 ' . count($parsed) . ' 条';
        }
        $seq = 0;
        foreach ($parsed as $entry) {
            $seq++;
            $key = zw_legal_text_clause_id($doc_id, (string) $entry['article_no'], (string) $entry['item_no'], (string) $entry['original_text']);
            if (!isset($confirmed[$key])) {
                $out['failures'][] = '第 ' . $seq . ' 条在条款表中缺失：' . $entry['article_no'] . $entry['item_no'];
                continue;
            }
            if ((string) ($confirmed[$key]['original_text'] ?? '') !== (string) $entry['original_text']) {
                $out['failures'][] = '第 ' . $seq . ' 条文本不一致：' . $entry['article_no'] . $entry['item_no'];
            }
            if ((string) ($confirmed[$key]['policy_version'] ?? '') !== $version_no) {
                $out['failures'][] = '第 ' . $seq . ' 条版本号不一致（' . (string) ($confirmed[$key]['policy_version'] ?? '') . ' ≠ ' . $version_no . '）';
            }
        }
        $out['ok'] = empty($out['failures']);
        return $out;
    }
}

/* ═══════════════ 文档可见性（TASK-1032 / SPEC-88 §6.5） ═══════════════
 * option `zw_legal_text_visibility` = array(doc_id => 'public'|'internal')，默认 public。
 * `internal` 文档（例：分支机构管理办法）**只能给分支机构管理员及以上查看**：
 * 未授权一律返回空，**不得回退 legacy**，且不得出现在任何公开列表。
 */
if (!function_exists('zw_legal_text_visibility')) {
    /**
     * 某文档的可见性（非法值归一 `public`）。
     *
     * @param string $doc_id 文档 ID。
     * @return string `public`|`internal`
     */
    function zw_legal_text_visibility($doc_id) {
        $doc_id = trim((string) $doc_id);
        $map = function_exists('get_option') ? get_option('zw_legal_text_visibility', array()) : array();
        $map = is_array($map) ? $map : array();
        $v = isset($map[$doc_id]) ? (string) $map[$doc_id] : 'public';
        return $v === 'internal' ? 'internal' : 'public';
    }
}

if (!function_exists('zw_legal_text_set_visibility')) {
    /**
     * 设置文档可见性（**仅站点管理员**；写审计）。
     *
     * @param string $doc_id 文档 ID。
     * @param string $visibility `public`|`internal`。
     * @param string $actor 操作者。
     * @return bool
     */
    function zw_legal_text_set_visibility($doc_id, $visibility, $actor = '') {
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '') { return false; }
        // CLI（迁移/发布器）视为受控运维通道；Web 语境仅站点管理员可设置。
        $is_cli = (defined('WP_CLI') && WP_CLI);
        if (!$is_cli && function_exists('current_user_can') && !current_user_can('manage_options')) { return false; }
        $visibility = ((string) $visibility === 'internal') ? 'internal' : 'public';
        $map = function_exists('get_option') ? get_option('zw_legal_text_visibility', array()) : array();
        $map = is_array($map) ? $map : array();
        $before = isset($map[$doc_id]) ? (string) $map[$doc_id] : 'public';
        $map[$doc_id] = $visibility;
        if (function_exists('update_option')) { update_option('zw_legal_text_visibility', $map, false); }
        if (function_exists('zw_legal_text_audit')) {
            zw_legal_text_audit('legal_text_visibility', $doc_id, array('visibility' => $before), array('visibility' => $visibility), $actor);
        }
        return true;
    }
}

if (!function_exists('zw_legal_text_doc_visible')) {
    /**
     * 当前访问者能否查看该文档正文（`internal` → 仅分支机构管理员及以上或站点管理员）。
     *
     * @param string $doc_id 文档 ID。
     * @return bool
     */
    function zw_legal_text_doc_visible($doc_id) {
        if (zw_legal_text_visibility($doc_id) !== 'internal') { return true; }
        if (function_exists('current_user_can') && current_user_can('manage_options')) { return true; }
        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($uid <= 0) { return false; }
        if (!function_exists('zw_legal_consent_user_roles')) { return false; }
        $roles = zw_legal_consent_user_roles($uid);
        if (!is_array($roles)) { return false; }
        foreach (array('branch_admin', 'branch_editor') as $r) {
            if (!empty($roles[$r])) { return true; }
        }
        return false;
    }
}

if (!function_exists('zw_legal_text_render_for_user')) {
    /**
     * **按访问权限**渲染正文：不可见返回空串（**不回退 legacy**，防泄露）。
     *
     * @param string $doc_id 文档 ID。
     * @return string
     */
    function zw_legal_text_render_for_user($doc_id) {
        if (!zw_legal_text_doc_visible($doc_id)) {
            // TASK-1036：措辞改为明确的 "visibility denied"，避免被误读为"回退旧源"（行为不变：仍返回空）。
            zw_legal_text_log_visibility_denied($doc_id);
            return '';
        }
        return zw_legal_text_render($doc_id);
    }
}

if (!function_exists('zw_legal_text_log_visibility_denied')) {
    /**
     * 受限文档被未授权访问时的告警（TASK-1036）。
     *
     * 固定输出 `[ZW-LEGAL-TEXT] visibility denied: <doc_id>`；仍走与回退告警相同的可注入通道
     * （`zw_legal_text_logger`），便于测试与运维采集。
     *
     * @param string $doc_id 文档 ID。
     * @return void
     */
    function zw_legal_text_log_visibility_denied($doc_id) {
        $line = '[ZW-LEGAL-TEXT] visibility denied: ' . (string) $doc_id;
        if (function_exists('zw_legal_text_logger')) {
            zw_legal_text_logger($line);
            return;
        }
        if (function_exists('error_log')) {
            error_log($line);
        }
    }
}

if (!function_exists('zw_legal_text_publish_to_page')) {
    /**
     * 把文档**生效版本**的正文发布到其 `page_slug` 对应页面（TASK-1036：canonical 唯一实现）。
     *
     * CLI 脚本 `scripts/sync-document-page.php` 与后台「发布到页面」按钮**共用本函数**，
     * 禁止第二套发布实现。规则：
     *   - `internal`（受限）文档**拒绝发布到公开页面**；
     *   - 未配置 `page_slug` / 无生效版本 / 正文为空 / 页面不存在 → 拒绝并给出原因；
     *   - **逐字比对幂等**：页面与生效版本一致 → `noop`（不写库）；
     *   - 仅在 `$dry_run === false` 时写页面，并写审计 `document_page_publish`。
     *
     * @param string $doc_id   文档 ID。
     * @param bool   $dry_run  true=预演（默认，绝不写库）。
     * @param string $actor    操作者标识（写入审计）。
     * @return array{ok:bool,reason:string,page_id:int,slug:string,version:string,bytes:int,old_bytes:int}
     */
    function zw_legal_text_publish_to_page($doc_id, $dry_run = true, $actor = '') {
        $out = array(
            'ok' => false, 'reason' => '', 'page_id' => 0, 'slug' => '',
            'version' => '', 'bytes' => 0, 'old_bytes' => 0,
        );
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '' || !function_exists('zw_legal_text_document')) {
            $out['reason'] = 'no_doc';
            return $out;
        }
        $doc = zw_legal_text_document($doc_id);
        if (!$doc) {
            $out['reason'] = 'no_doc';
            return $out;
        }
        // 受限文档不得发布到公开页面
        if (function_exists('zw_legal_text_visibility') && zw_legal_text_visibility($doc_id) === 'internal') {
            $out['reason'] = 'internal';
            return $out;
        }
        $slug = trim((string) ($doc['page_slug'] ?? ''));
        $out['slug'] = $slug;
        if ($slug === '') {
            $out['reason'] = 'no_slug';
            return $out;
        }
        $cur = function_exists('zw_legal_text_current') ? zw_legal_text_current($doc_id) : null;
        $body = $cur ? (string) ($cur['body_html'] ?? '') : '';
        if (!$cur || $body === '') {
            $out['reason'] = 'no_version';
            return $out;
        }
        if (trim($body) === '') {
            $out['reason'] = 'empty_body';
            return $out;
        }
        $out['version'] = (string) ($cur['version_no'] ?? '');
        $out['bytes'] = strlen($body);

        $page = function_exists('get_page_by_path') ? get_page_by_path($slug) : null;
        if (!$page) {
            $out['reason'] = 'no_page';
            return $out;
        }
        $out['page_id'] = (int) $page->ID;
        $old = (string) $page->post_content;
        $out['old_bytes'] = strlen($old);

        // TASK-1326 阶段四（生产实测修复）：正文按 **Gutenberg HTML 块** 落页。
        // 不加块标记时 `the_content` 会跑 `wpautop`：它把版式根容器里的裸 `<input>`（目录折叠开关）
        // 当成段落包起来，多出一个游离 `</p>`／空 `<p>` ⇒ 该空段落成为 CSS Grid 的第一格，
        // 目录被挤到宽列、正文掉到窄列第二行（生产实测 SIDEBAR w=1054 / MAIN w=236 y=1323）。
        // 《隐私政策》正文自带 `<!-- wp:html -->`，故一直正常；本函数统一补齐（幂等，已带则不重复包）。
        $page_html = function_exists('zw_legal_text_page_wrap')
            ? zw_legal_text_page_wrap($body)
            : $body;

        if (trim($old) === trim($page_html)) {
            $out['ok'] = true;
            $out['reason'] = 'noop';
            return $out;
        }
        if ($dry_run) {
            $out['ok'] = true;
            $out['reason'] = 'dry_run';
            return $out;
        }
        if (!function_exists('wp_update_post')) {
            $out['reason'] = 'write_failed';
            return $out;
        }
        $saved = wp_update_post(array('ID' => (int) $page->ID, 'post_content' => $page_html), true);
        if (function_exists('is_wp_error') && is_wp_error($saved)) {
            $out['reason'] = 'write_failed';
            return $out;
        }
        if (function_exists('zw_legal_text_audit')) {
            zw_legal_text_audit('document_page_publish', $doc_id,
                array('page' => (int) $page->ID, 'bytes' => strlen($old)),
                array('page' => (int) $page->ID, 'bytes' => strlen($page_html), 'version' => $out['version']),
                (string) $actor);
        }
        $out['ok'] = true;
        $out['reason'] = 'published';
        return $out;
    }
}

if (!function_exists('zw_legal_text_page_wrap')) {
    /**
     * 把生效版本正文包成 **Gutenberg HTML 块**（canonical，唯一实现；TASK-1326 阶段四）。
     *
     * 为什么必须包：正文是**整篇原始 HTML**（含内联 `<style>` 与 CSS Grid 版式根容器）。
     * 不包块标记时，`the_content` 会跑 `wpautop`——它把版式容器里的裸 `<input>`（目录折叠开关）
     * 当段落包起来并多出游离 `</p>`／空 `<p>`；该空段落作为 Grid 第一格，把目录挤到宽列、
     * 正文挤到窄列第二行（生产实测 `SIDEBAR w=1054 / MAIN w=236 y=1323`）。
     * 《隐私政策》正文自带 `<!-- wp:html -->`，故一直正常；本函数把该口径统一到发布层。
     *
     * **幂等**：正文已以 `<!-- wp:html -->` 开头时原样返回，不重复包裹。
     * **不影响版本锚点**：`body_hash` 只由正文文本决定，本包装只作用于**页面落库**，
     * 因此「正文未变」的页面修复**无需新建版本、不触发重签**。
     *
     * @param string $body 生效版本正文 HTML。
     * @return string 落页用 HTML。
     */
    function zw_legal_text_page_wrap($body) {
        $body = (string) $body;
        if (trim($body) === '') { return $body; }
        if (strpos(ltrim($body), '<!-- wp:html -->') === 0) { return $body; }
        return "<!-- wp:html -->\n" . $body . "\n<!-- /wp:html -->";
    }
}

if (!function_exists('zw_legal_text_documents_visible')) {
    /**
     * 仅返回当前访问者可见的文档（`internal` 不进公开列表）。
     *
     * @return array[]
     */
    function zw_legal_text_documents_visible() {
        $out = array();
        foreach (zw_legal_text_documents() as $doc) {
            $id = (string) ($doc['id'] ?? '');
            if ($id !== '' && !zw_legal_text_doc_visible($id)) { continue; }
            $out[] = $doc;
        }
        return $out;
    }
}

if (!function_exists('zw_legal_text_default_visibility')) {
    /**
     * 纳管时的**默认可见性**（canonical）：分支机构管理办法类 = `internal`，其余 = `public`。
     *
     * @param string $doc_id  文档 ID。
     * @param string $doc_type 文档类型。
     * @return string `public`|`internal`
     */
    function zw_legal_text_default_visibility($doc_id, $doc_type = '') {
        $doc_id = strtoupper(trim((string) $doc_id));
        if ($doc_id === 'SRC-BRANCH-RULES') { return 'internal'; }
        if (strtoupper(trim((string) $doc_type)) === 'MANAGEMENT_RULES') { return 'internal'; }
        return 'public';
    }
}

/* ═══════════════ 《章程》官网页（/bhzc / 页面 #64）反向守卫（TASK-1440 / CHG-234）═══════════════
 * 铁律 19（AGENTS.md 第 19 条，一票否决）：`/bhzc`（WordPress 页面 #64「本会章程」）是
 * 《中国传统文化促进会章程》的**唯一对外官网页**，只读、禁止任何形式的修改，且**禁止纳入任何
 * 统一文本源的渲染或发布通道**（其 `page_slug` 必须保持为空）。
 *
 * 本组函数是**唯一出处**的反向守卫：任何"按 slug / 页面 id 取法务文本"的新通道（当前唯一消费者
 * 是 `inc/api/rest-legal.php`）都必须在**取正文之前**调用它，命中即 fail-closed（判红 + 告警），
 * 从而保证「小程序读章程」只能读文档中心 `SRC-CHARTER` 副本，**不可能**绕到 #64。
 */
if (!function_exists('zw_legal_text_forbidden_page_paths')) {
    /**
     * 禁读页面路径（唯一出处）。
     *
     * @return string[]
     */
    function zw_legal_text_forbidden_page_paths() {
        return array('bhzc', 'gywm/bhzc');
    }
}

if (!function_exists('zw_legal_text_forbidden_page_ids')) {
    /**
     * 禁读 WordPress 页面 ID（唯一出处）：`64` = 「本会章程」官网页（TASK-1439 A 段只读实测）。
     *
     * @return int[]
     */
    function zw_legal_text_forbidden_page_ids() {
        return array(64);
    }
}

if (!function_exists('zw_legal_public_doc_url')) {
    /**
     * 对外法律文本的**公开页面 URL**（TASK-1890，唯一出处）。
     *
     * 起因：注册表单原来从主题选项 `ceo_login_zcxy_link` 取链接，线上实测该值为 `/userra `（**尾随空格**），
     * 点开就是 404 —— 而且整张表单只有**一个**「注册协议」链接，隐私政策没有入口。
     * 现在把「哪份文本对应哪个公开页」收敛到本函数一处，调用方只传语义键，不再各自拼 slug。
     *
     * ⚠️ 铁律 19：《章程》官网页 `/bhzc` 不在本映射内，也**不得**加入 —— 该页只读，
     * 且用户协议/隐私政策与它是三份不同文档。
     *
     * @param string $key `privacy`（隐私政策）| `agreement`（用户协议）。
     * @return string 绝对 URL；未知键返回空串（调用方据此不渲染该链接，不臆造落点）。
     */
    function zw_legal_public_doc_url($key) {
        $map = array(
            'privacy'   => '/privacy-policy/',
            'agreement' => '/userra/',
        );
        $key = strtolower(trim((string) $key));
        if (!isset($map[$key])) {
            return '';
        }
        return function_exists('home_url') ? (string) home_url($map[$key]) : $map[$key];
    }
}

if (!function_exists('zw_legal_text_is_forbidden_page_ref')) {
    /**
     * 引用（slug / path / 页面 id 字符串）是否命中《章程》官网页禁读集。
     *
     * 口径：`bhzc` / `gywm/bhzc`（含前后斜杠与大小写归一）或纯数字页面 id `64` ⇒ `true`。
     * **注意**：本函数只做"判红"，**绝不**返回或读取该页面的任何内容。
     *
     * @param mixed $ref slug / path / 页面 id。
     * @return bool
     */
    function zw_legal_text_is_forbidden_page_ref($ref) {
        if (is_int($ref)) {
            return in_array($ref, zw_legal_text_forbidden_page_ids(), true);
        }
        $ref = strtolower(trim((string) $ref));
        if ($ref === '') { return false; }
        if (ctype_digit($ref) && in_array((int) $ref, zw_legal_text_forbidden_page_ids(), true)) {
            return true;
        }
        $ref = trim($ref, '/');
        return in_array($ref, zw_legal_text_forbidden_page_paths(), true);
    }
}

if (!function_exists('zw_legal_text_is_forbidden_document')) {
    /**
     * 文档主档是否命中《章程》官网页禁读集（主档 `page_slug` 落点，或 doc_id 明示 bhzc）。
     *
     * 用途：防止"将来有人在文档中心把某对外文档的 `page_slug` 配成 `bhzc`"从而**再开一条**
     * 经小程序读 #64 的通道 —— 一旦命中，该文档在小程序侧立即不可达。
     *
     * @param mixed $doc 主档行（关联数组）。
     * @return bool
     */
    function zw_legal_text_is_forbidden_document($doc) {
        if (!is_array($doc)) { return false; }
        if (zw_legal_text_is_forbidden_page_ref($doc['page_slug'] ?? '')) { return true; }
        $id = strtolower((string) ($doc['id'] ?? ''));
        return $id !== '' && strpos($id, 'bhzc') !== false;
    }
}

if (!function_exists('zw_legal_text_log_forbidden_ref')) {
    /**
     * 命中《章程》官网页禁读集时的**判红告警**（固定行首，便于测试与运维采集）。
     *
     * 走与文本源其他告警相同的可注入通道 `zw_legal_text_logger`（未注入时 `error_log`）。
     *
     * @param mixed  $ref  命中的引用。
     * @param string $why  命中原因标记（例：`request_doc_id` / `document_page_slug`）。
     * @return void
     */
    function zw_legal_text_log_forbidden_ref($ref, $why = '') {
        $line = '[ZW-LEGAL-TEXT] forbidden page ref (bhzc/page #64): ' . (string) $ref
            . ($why !== '' ? ' (' . (string) $why . ')' : '');
        if (function_exists('zw_legal_text_logger')) {
            zw_legal_text_logger($line);
            return;
        }
        if (function_exists('error_log')) {
            error_log($line);
        }
    }
}
