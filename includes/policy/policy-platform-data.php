<?php
/**
 * AI 法务部 · 政策数据访问层（前端与后台共用 · TASK-954）。
 *
 * 背景：`inc/admin/policy-platform-admin.php` 只在 `is_admin()` 时加载，导致前端
 * 签署判定引擎（`inc/policy-platform/legal-consent.php`）取不到表名与内容哈希。
 * 故把**表名 / 存在性 / 内容哈希**抽为本文件，由 `functions.php` 无条件加载；
 * 后台文件改为 require 本文件（其内同名函数均带 function_exists 守卫，不会重复声明）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_policy_platform_table')) {
    /**
     * 政策平台表名（`{prefix}zw_policy_*`）。
     *
     * @param string $name 表名后缀。
     * @return string
     */
    function zw_policy_platform_table($name) {
        global $wpdb;
        return $wpdb->prefix . 'zw_' . $name;
    }
}

if (!function_exists('zw_policy_platform_exists')) {
    /**
     * 表是否存在。
     *
     * @param string $name 表名后缀。
     * @return bool
     */
    function zw_policy_platform_exists($name) {
        global $wpdb;
        $table = zw_policy_platform_table($name);
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}

if (!function_exists('zw_policy_platform_source_hash')) {
/**
 * 计算政策源「当前生效正文」的内容哈希（SPEC-88 §4 · TASK-953）。
 *
 * 正文 = 该 source 下 `status='已确认'` 的条款；按 `article_no` / `item_no` 稳定排序后
 * 逐条拼接（条款号 | 项号 | 规范化正文，正文内部连续空白折叠为单空格）再取 SHA-256。
 *
 * 这是**签署版本判定的唯一锚点**：哈希变更即须重签，哈希未变不触发重签。
 *
 * TASK-1014（统一文本源第 2 期）：`zw_legal_text_source() === 'db'` 且生效版本可用时，
 * **口径切换为整篇正文哈希**（`zw_legal_text_effective_hash()`）；legacy 分支（本函数下文的
 * 条款拼接口径）**行为一字不改**，db 不可用亦自动落回该分支。
 *
 * @param string $source_id 政策源 ID（`zw_policy_source.id`）。
 * @param string $basis     口径（TASK-1014）：`''`=自动（db 用整篇正文、否则条款拼接）、
 *                          `body_hash`=强制整篇正文口径、`clause_concat`=强制条款拼接口径。
 * @return string 十六进制哈希；无已确认条款或表不存在时返回空串。
 */
function zw_policy_platform_source_hash($source_id, $basis = '') {
    global $wpdb;
    $source_id = trim((string) $source_id);
    $basis     = in_array($basis, array('body_hash', 'clause_concat'), true) ? $basis : '';
    // TASK-1014：db 模式优先走"整篇正文哈希"，不可用则继续走下方 legacy 口径（不返回早退）。
    if ($basis !== 'clause_concat' && function_exists('zw_legal_text_effective_hash')) {
        $effective = zw_legal_text_effective_hash($source_id);
        if ($effective !== '') { return $effective; }
    }
    if ($basis === 'body_hash') { return ''; } // 显式要求整篇口径但不可用 → 空串，由调用方决定
    if ($source_id === '' || !zw_policy_platform_exists('policy_clause')) { return ''; }
    $table = zw_policy_platform_table('policy_clause');
    // TASK-1016：条款锚点必须与"展示/正文"同一顺序（中文条号不能靠 MySQL 排序）。
    // 取确定基序（id）后在 PHP 侧用 canonical 比较器排成文档序；比较器不可用时退回 id 序（仍确定）。
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT id, article_no, item_no, original_text FROM {$table} WHERE source_id=%s AND status=%s ORDER BY id ASC",
        $source_id, '已确认'
    ), ARRAY_A);
    if (!is_array($rows) || !$rows) { return ''; }
    if (function_exists('zw_legal_consent_clause_compare')) {
        usort($rows, 'zw_legal_consent_clause_compare');
    }
    $parts = array();
    foreach ($rows as $row) {
        $text = (string) ($row['original_text'] ?? '');
        $text = function_exists('preg_replace') ? (string) preg_replace('/\s+/u', ' ', $text) : $text;
        $parts[] = trim((string) ($row['article_no'] ?? '')) . '|' . trim((string) ($row['item_no'] ?? '')) . '|' . trim($text);
    }
    return hash('sha256', implode("\n", $parts));
}
}

if (!function_exists('zw_policy_platform_refresh_source_hash')) {
/**
 * 重算并写回 `zw_policy_source.source_hash`（发布/条款确认后调用）。
 *
 * TASK-1014：`db` 模式下写回**整篇正文哈希**（生效版本 `body_hash`），legacy 模式行为不变；
 * 两种口径都会在审计 detail 中显式标注，便于事后举证"当时用的是哪套口径"。
 *
 * @param string $source_id 政策源 ID。
 * @param string $basis     口径（可选）：`''`=自动、`body_hash`、`clause_concat`。
 * @return string 新哈希；空串表示无可用正文（不写库）。
 */
function zw_policy_platform_refresh_source_hash($source_id, $basis = '') {
    global $wpdb;
    $source_id = trim((string) $source_id);
    $basis     = in_array($basis, array('body_hash', 'clause_concat'), true) ? $basis : '';
    $hash  = zw_policy_platform_source_hash($source_id, $basis);
    if ($hash === '') { return ''; }
    $mode  = function_exists('zw_legal_text_source') ? zw_legal_text_source() : 'legacy';
    $used  = ($mode === 'db' && function_exists('zw_legal_text_effective_hash')
              && zw_legal_text_effective_hash($source_id) !== '') ? 'body_hash' : 'clause_concat';
    if ($basis !== '') { $used = $basis; }
    $before = '';
    if (zw_policy_platform_exists('policy_source')) {
        $before = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT source_hash FROM ' . zw_policy_platform_table('policy_source') . ' WHERE id=%s',
            $source_id
        ));
    }
    $wpdb->update(zw_policy_platform_table('policy_source'), array('source_hash' => $hash), array('id' => (string) $source_id));
    // 审计：**仅在新口径（body_hash）生效时写**，legacy 模式保持与改造前逐字一致的行为
    // （不新增任何库写入）；口径切换本身另有 scripts/switch-legal-text-source.php 的
    // `legal_text_hash_switch` 审计兜底。
    if ($used === 'body_hash' && function_exists('zw_policy_platform_audit_insert')) {
        zw_policy_platform_audit_insert(
            'source_hash_refresh', 'policy_source', array($source_id),
            array('source_hash' => $before, 'basis' => $used === 'body_hash' ? 'clause_concat' : 'body_hash'),
            array('source_hash' => $hash, 'basis' => $used, 'documents' => 'zw_policy_document_version'),
            function_exists('wp_get_current_user') && wp_get_current_user() && wp_get_current_user()->ID ? wp_get_current_user()->user_login : 'wp_cli',
            'TASK-1014：重算政策源内容哈希，口径=' . $used . '（' . ($mode === 'db' ? '统一文本源 db 模式' : 'legacy 条款拼接') . '）'
        );
    }
    return $hash;
}
}

/*
 * ───────────── TASK-2423 / OA 迁移 R3-3：审计写入下沉（S1） ─────────────
 *
 * 由 `inc/admin/policy-platform-admin.php:150` 搬来（函数体原样），原因不是「整理归属」而是修**真缺陷**：
 * 该函数原先只定义在 `is_admin()` 门控的后台文件里，而内核 5 处调用全部用 `function_exists` 兜底
 * （`legal-text-source.php:457`、`legal-consent.php:1427`、`policy-platform-cron.php:235/561`、
 * `policy-platform-data.php:127`）⇒ **WP-Cron 与前台签署语境下审计写入被静默跳过**。
 * 本文件由 `functions.php` **无条件** require，故下沉到这里即修好该缺陷。
 *
 * 双声明纪律（WP 先加载插件、后加载主题）：插件 `tcpc-oa` 若已声明同名函数 ⇒ 本定义让位；
 * 主题 admin 副本（`inc/admin/policy-platform-admin.php`）保留的同名定义同样带守卫 ⇒ 任意顺序都不 fatal。
 */
if (!function_exists('zw_policy_platform_audit_insert')) {
    /**
     * 写政策平台审计行（`{prefix}zw_policy_audit_log`，只追加、不更新）。
     *
     * @param string $action      动作码。
     * @param string $target_type 目标类型。
     * @param array  $target_ids  目标 ID 列表。
     * @param mixed  $before      变更前快照。
     * @param mixed  $after       变更后快照。
     * @param string $operator    操作者。
     * @param string $detail      详情。
     * @return void
     */
    function zw_policy_platform_audit_insert($action, $target_type, $target_ids, $before, $after, $operator, $detail = '') {
        global $wpdb;
        if (!zw_policy_platform_exists('policy_audit_log')) return;
        $wpdb->insert(zw_policy_platform_table('policy_audit_log'), array(
            'id' => 'AUDIT_' . wp_generate_uuid4(),
            'action' => $action,
            'target_type' => $target_type,
            'target_ids' => wp_json_encode(array_values((array) $target_ids)),
            'before_json' => wp_json_encode($before),
            'after_json' => wp_json_encode($after),
            'operator' => $operator,
            'detail' => $detail,
        ));
    }
}

