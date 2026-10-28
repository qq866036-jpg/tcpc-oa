<?php
/**
 * 收文管理 · 收文办理视图（TASK-1297 · R5）。
 *
 * 口径出处：
 * - `docs/research/TASK-1297-收文管理-需求对齐稿-20261008.md`
 *   §3 US-04（指定承办与办理时限）/ US-05（办理与办结留痕）/ US-08（权限与可见性）
 *   / Q10（承办/时限**复用** `zw_policy_issue`）；
 * - `docs/architecture/TASK-1297-收文管理-表结构变更与迁移回滚方案-20261008.md`
 *   §1（依赖单向：收文只读写 `zw_policy_source` / `zw_policy_issue`）/ §2.2（字段契约）
 *   / §5 不变式（I1 生效版本只读、I2 单一政策库、I5 自产文件不被误收、I6 办理状态不改政策语义）
 *   / §6.2（缺列降级链）/ §6.3（「逾期」为派生状态，禁止批量改写）
 *   / §补充 A.1（前缀 `zw_receipt_*`、slug `zw-receipt-issues`）/ A.3（承办/时限写 `zw_policy_issue`）。
 *
 * 铁律（本文件）：
 * - **只写办理台账** `zw_policy_issue`；**绝不** `UPDATE` 政策源正文、`zw_policy_document_version`、
 *   `zw_policy_source.status`（两套时钟不互写，铁律 I1/I6）；
 * - 派单前**强制校验来源**必须是 `origin='incoming'` 的收文行，且不在自产 4 id 白名单内（铁律 I5）；
 * - 「逾期」**只由** `zw_receipt_issue_is_overdue()` 派生；更新入口**拒绝**手工写 `逾期`；
 *   全文件**无任何批量改写 status 的 SQL**（只允许 `$wpdb->update(表, 数据, array('id'=>…))` 单行）；
 * - 缺列（迁移未跑）→ **零查询 / 零写入**，绝不退化为全表或脏写入；
 * - 全部用户输入一律白名单归一 + `$wpdb->prepare` 参数化（禁止拼接）；
 * - 函数前缀一律 `zw_receipt_*`（补充 A.1）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

// 依赖（standalone / CLI 加载时自动补齐；bootstrap 开启分支先加载 receipt-create / receipt-ledger）。
if (!function_exists('zw_receipt_is_enabled')) {
    require_once __DIR__ . '/receipt-helpers.php';
}
if (!function_exists('zw_receipt_org_options')) {
    require_once __DIR__ . '/receipt-orgs.php';
}
if (!function_exists('zw_receipt_urgency_options') && file_exists(__DIR__ . '/receipt-create.php')) {
    require_once __DIR__ . '/receipt-create.php';
}
if (!function_exists('zw_receipt_ledger_self_protected_ids') && file_exists(__DIR__ . '/receipt-ledger.php')) {
    require_once __DIR__ . '/receipt-ledger.php';
}
if (!function_exists('zw_receipt_admin_ui_status_badge') && file_exists(__DIR__ . '/receipt-admin-ui.php')) {
    require_once __DIR__ . '/receipt-admin-ui.php';
}

if (!defined('ZW_RECEIPT_ISSUE_NONCE_CREATE')) {
    define('ZW_RECEIPT_ISSUE_NONCE_CREATE', 'zw_receipt_issue_create');
}
if (!defined('ZW_RECEIPT_ISSUE_NONCE_UPDATE')) {
    define('ZW_RECEIPT_ISSUE_NONCE_UPDATE', 'zw_receipt_issue_update');
}
if (!defined('ZW_RECEIPT_ISSUE_NONCE_FIELD')) {
    define('ZW_RECEIPT_ISSUE_NONCE_FIELD', 'zw_receipt_issue_nonce');
}

/* ═════════════════════════════ 表/列探测与缺列短路 ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_array_a')) {
    /**
     * `ARRAY_A` 常量（standalone / CLI 下的退化值）。
     *
     * @return string
     */
    function zw_receipt_issue_array_a() {
        return defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
    }
}

if (!function_exists('zw_receipt_issue_table')) {
    /**
     * 办理台账表名（**复用** `zw_policy_issue`，不新建表，铁律 I2/Q10）。
     *
     * @return string
     */
    function zw_receipt_issue_table() {
        global $wpdb;
        $prefix = (is_object($wpdb) && isset($wpdb->prefix)) ? (string) $wpdb->prefix : 'wp_';
        return $prefix . 'zw_policy_issue';
    }
}

if (!function_exists('zw_receipt_issue_source_table')) {
    /**
     * 政策源表名（**唯一政策库**；复用 R3 canonical，缺失时本地等价退化）。
     *
     * @return string
     */
    function zw_receipt_issue_source_table() {
        if (function_exists('zw_receipt_source_table')) {
            return (string) zw_receipt_source_table();
        }
        global $wpdb;
        $prefix = (is_object($wpdb) && isset($wpdb->prefix)) ? (string) $wpdb->prefix : 'wp_';
        return $prefix . 'zw_policy_source';
    }
}

if (!function_exists('zw_receipt_issue_columns')) {
    /**
     * 办理台账既有列（`SHOW COLUMNS`；静态缓存，可 `$fresh = true` 强刷）。
     *
     * 表不存在 / 无 DB 连接 → 空数组 ⇒ 上层判为「迁移未跑」并短路。
     *
     * @param bool $fresh 是否强制刷新缓存。
     * @return array<string,bool>
     */
    function zw_receipt_issue_columns($fresh = false) {
        static $cache = null;
        if ($fresh) {
            $cache = null;
        }
        if (is_array($cache)) {
            return $cache;
        }
        $cache = array();
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return $cache;
        }
        $table = zw_receipt_issue_table();
        $rows  = $wpdb->get_results(zw_receipt_prepare_sql("SHOW COLUMNS FROM {$table}", array()), zw_receipt_issue_array_a());
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $field = isset($row['Field']) ? (string) $row['Field'] : '';
                if ($field !== '') {
                    $cache[$field] = true;
                }
            }
        }
        return $cache;
    }
}

if (!function_exists('zw_receipt_issue_required_columns')) {
    /**
     * 办理读写所引用的列（任一缺失即判「迁移未跑」）。
     *
     * 与生产 `wptcpc_zw_policy_issue` 实测结构一致（`SHOW COLUMNS`，2026-10-09）。
     *
     * @return string[]
     */
    function zw_receipt_issue_required_columns() {
        return array(
            'id',
            'source_type',
            'source_id',
            'title',
            'description',
            'risk_level',
            'owner_dept',
            'owner',
            'due_date',
            'status',
            'evidence',
            'review_conclusion',
            'created_by',
            'create_time',
            'update_time',
        );
    }
}

if (!function_exists('zw_receipt_issue_missing_columns')) {
    /**
     * 缺失的必需列（非空 ⇒ 迁移未跑 / 表缺失）。
     *
     * @return string[]
     */
    function zw_receipt_issue_missing_columns() {
        $columns = zw_receipt_issue_columns();
        $missing = array();
        foreach (zw_receipt_issue_required_columns() as $column) {
            if (!isset($columns[$column])) {
                $missing[] = $column;
            }
        }
        return $missing;
    }
}

if (!function_exists('zw_receipt_issue_schema_ready')) {
    /**
     * 办理视图是否可用（表与列齐备）。
     *
     * @return bool
     */
    function zw_receipt_issue_schema_ready() {
        return !zw_receipt_issue_missing_columns();
    }
}

/* ═════════════════════════════ 输入归一（防注入第一层） ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_text')) {
    /**
     * 单行文本净化（`sanitize_text_field(wp_unslash())` 口径；无 WP 时退化）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_issue_text($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        if (function_exists('zw_receipt_create_text')) {
            return (string) zw_receipt_create_text($value);
        }
        $value = (string) $value;
        $value = function_exists('wp_unslash') ? (string) wp_unslash($value) : $value;
        if (function_exists('sanitize_text_field')) {
            return trim((string) sanitize_text_field($value));
        }
        return trim((string) str_replace(array("\0", "\r", "\n", "\t"), '', strip_tags($value)));
    }
}

if (!function_exists('zw_receipt_issue_block')) {
    /**
     * 多行文本净化（保留换行：证据 / 复核结论 / 办理要求）。
     *
     * @param mixed $value 原始值。
     * @param int   $max   最大字符数。
     * @return string
     */
    function zw_receipt_issue_block($value, $max = 5000) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = (string) $value;
        if (function_exists('wp_unslash')) {
            $value = (string) wp_unslash($value);
        }
        if (function_exists('sanitize_textarea_field')) {
            $value = (string) sanitize_textarea_field($value);
        } else {
            $value = strip_tags($value);
            $value = str_replace("\0", '', $value);
        }
        $value = trim((string) str_replace("\r\n", "\n", $value));
        if ($value === '') {
            return '';
        }
        if (function_exists('zw_receipt_create_truncate')) {
            return (string) zw_receipt_create_truncate($value, max(1, (int) $max));
        }
        return (string) substr($value, 0, max(1, (int) $max));
    }
}

if (!function_exists('zw_receipt_issue_sanitize_id')) {
    /**
     * 主键 / 外键 id 净化（仅 `[A-Za-z0-9_\-]`，与既有 `SRC-*` / `RCP-*` 口径一致）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_issue_sanitize_id($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        return (string) preg_replace('/[^A-Za-z0-9_\-]/', '', $value);
    }
}

if (!function_exists('zw_receipt_issue_key')) {
    /**
     * key 净化（`sanitize_key()` 口径；无 WP 时退化）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_issue_key($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        if (function_exists('sanitize_key')) {
            return (string) sanitize_key((string) $value);
        }
        return strtolower((string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $value));
    }
}

if (!function_exists('zw_receipt_issue_absint')) {
    /**
     * 非负整数归一（数组/对象/非数值一律 0，不触发 Warning）。
     *
     * @param mixed $value 原始值。
     * @return int
     */
    function zw_receipt_issue_absint($value) {
        if (is_array($value) || is_object($value)) {
            return 0;
        }
        if (function_exists('absint')) {
            return (int) absint($value);
        }
        return max(0, (int) $value);
    }
}

/* ═════════════════════════════ 状态与日期口径 ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_source_type')) {
    /**
     * `zw_policy_issue.source_type` 的收文取值（架构稿 §2.2：固定写 `incoming`）。
     *
     * 既有整改台账写其它值（如「政策更新影响分析」），**本模块不改其语义**：办理视图只查本值。
     *
     * @return string
     */
    function zw_receipt_issue_source_type() {
        return 'incoming';
    }
}

if (!function_exists('zw_receipt_issue_status_options')) {
    /**
     * 可**手工**写入的办理状态（US-05 取值域；`逾期` 不在其中——它是派生状态）。
     *
     * @return array<string,string>
     */
    function zw_receipt_issue_status_options() {
        $options = array(
            '未开始' => '未开始',
            '办理中' => '办理中',
            '已办结' => '已办结',
            '归档'   => '归档',
        );
        if (function_exists('apply_filters')) {
            $options = apply_filters('zw_receipt_issue_status_options', $options);
        }
        return is_array($options) ? $options : array();
    }
}

if (!function_exists('zw_receipt_issue_statuses')) {
    /**
     * 可手工写入的状态值清单。
     *
     * @return string[]
     */
    function zw_receipt_issue_statuses() {
        return array_values(array_map('strval', array_keys(zw_receipt_issue_status_options())));
    }
}

if (!function_exists('zw_receipt_issue_derived_status')) {
    /**
     * 派生状态名（唯一取值 `逾期`；**禁止**手工写入）。
     *
     * @return string
     */
    function zw_receipt_issue_derived_status() {
        return '逾期';
    }
}

if (!function_exists('zw_receipt_issue_default_status')) {
    /**
     * 派单默认状态（US-05：`未开始`）。
     *
     * @return string
     */
    function zw_receipt_issue_default_status() {
        return '未开始';
    }
}

if (!function_exists('zw_receipt_issue_is_closed_status')) {
    /**
     * 是否「已收口」状态（逾期判定的排除集，§6.3）。
     *
     * @param mixed $status 状态。
     * @return bool
     */
    function zw_receipt_issue_is_closed_status($status) {
        return in_array(trim((string) $status), array('已办结', '归档'), true);
    }
}

if (!function_exists('zw_receipt_issue_normalize_status')) {
    /**
     * 状态归一（白名单）。
     *
     * @param mixed $value 原始值。
     * @return string|WP_Error 空串 = 不修改；`逾期` 一律拒绝（派生状态）。
     */
    function zw_receipt_issue_normalize_status($value) {
        if (is_array($value) || is_object($value)) {
            return new WP_Error('zw_receipt_issue_status_invalid', '办理状态取值非法。');
        }
        $status = trim((string) $value);
        if ($status === '') {
            return '';
        }
        if ($status === zw_receipt_issue_derived_status()) {
            return new WP_Error(
                'zw_receipt_issue_status_derived',
                '「逾期」是派生状态（办理时限早于今日且未办结），不允许手工设置。'
            );
        }
        if (!in_array($status, zw_receipt_issue_statuses(), true)) {
            return new WP_Error('zw_receipt_issue_status_invalid', '办理状态不在允许取值内。');
        }
        return $status;
    }
}

if (!function_exists('zw_receipt_issue_urgency_options')) {
    /**
     * 紧急程度取值域（**复用** R3 canonical `zw_receipt_urgency_options()`；缺失时本地等价退化）。
     *
     * @return array<string,string>
     */
    function zw_receipt_issue_urgency_options() {
        if (function_exists('zw_receipt_urgency_options')) {
            $options = zw_receipt_urgency_options();
            return is_array($options) ? $options : array();
        }
        return array('' => '未标注', '特急' => '特急', '加急' => '加急');
    }
}

if (!function_exists('zw_receipt_issue_normalize_risk_level')) {
    /**
     * 紧急程度 → `zw_policy_issue.risk_level`（架构稿 §2.2：该列可复用于紧急程度）。
     *
     * 架构稿未给映射表 ⇒ 本轮**原样落** `特急` / `加急`（不臆造「高/中/低」映射，见进度台账 R5 口径裁定）。
     * 空值 → 空串（写入时落 `NULL`）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_issue_normalize_risk_level($value) {
        $options = zw_receipt_issue_urgency_options();
        $value   = zw_receipt_issue_text($value);
        if ($value === '') {
            return '';
        }
        return array_key_exists($value, $options) ? $value : '';
    }
}

if (!function_exists('zw_receipt_issue_normalize_date')) {
    /**
     * 日期归一为 `Y-m-d`（支持 `Y-m-d` / `Y/m/d` / `Y.m.d` / `Ymd` / `Y年M月D日`；非法返回空串）。
     *
     * 口径与 R2 `zw_receipt_storage_year_from_date()` 的「4 位年份前缀 + 真实日期校验」一致。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_issue_normalize_date($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $value = (string) str_replace(array('年', '月', '/', '.'), array('-', '-', '-', '-'), $value);
        $value = (string) preg_replace('/日$/', '', $value);
        $value = trim($value, "- \t");
        $matches = array();
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches)) {
            $year  = (int) $matches[1];
            $month = (int) $matches[2];
            $day   = (int) $matches[3];
        } elseif (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $matches)) {
            $year  = (int) $matches[1];
            $month = (int) $matches[2];
            $day   = (int) $matches[3];
        } else {
            return '';
        }
        if ($year < 1900 || $year > 2200) {
            return '';
        }
        if (!checkdate($month, $day, $year)) {
            return '';
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}

if (!function_exists('zw_receipt_issue_today')) {
    /**
     * 「今日」（站点时区；`current_time('Y-m-d')` 不可用时退化 `date()`）。
     *
     * @return string `Y-m-d`
     */
    function zw_receipt_issue_today() {
        if (function_exists('current_time')) {
            $today = current_time('Y-m-d');
            if (is_string($today) && $today !== '') {
                $normalized = zw_receipt_issue_normalize_date($today);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }
        return date('Y-m-d');
    }
}

if (!function_exists('zw_receipt_issue_is_overdue')) {
    /**
     * 「逾期」判定（**唯一出处**，§6.3）：
     * `due_date` 合法且早于今日，且 `status ∉ {已办结, 归档}`。
     *
     * 该状态**只派生不落库**：调用方不得把结果写回 `status`。
     *
     * @param array|object $issue 办理行（需含 `due_date` / `status`）。
     * @param string       $today 今日（`Y-m-d`；空 = 取站点今日）。
     * @return bool
     */
    function zw_receipt_issue_is_overdue($issue, $today = '') {
        $row = is_object($issue) ? (array) $issue : (is_array($issue) ? $issue : array());
        $status = isset($row['status']) ? trim((string) $row['status']) : '';

        // 兼容存量可能存在的显式「逾期」值（本模块禁止写入，但读侧如实呈现）。
        if ($status === zw_receipt_issue_derived_status()) {
            return true;
        }
        if (zw_receipt_issue_is_closed_status($status)) {
            return false;
        }
        $due = isset($row['due_date']) ? zw_receipt_issue_normalize_date($row['due_date']) : '';
        if ($due === '') {
            return false;
        }
        $today = ($today !== '') ? zw_receipt_issue_normalize_date($today) : zw_receipt_issue_today();
        if ($today === '') {
            return false;
        }
        return ($due < $today);
    }
}

if (!function_exists('zw_receipt_issue_display_status')) {
    /**
     * 展示状态：派生逾期优先，否则库内状态。
     *
     * @param array|object $issue 办理行。
     * @param string       $today 今日。
     * @return string
     */
    function zw_receipt_issue_display_status($issue, $today = '') {
        if (zw_receipt_issue_is_overdue($issue, $today)) {
            return zw_receipt_issue_derived_status();
        }
        $row = is_object($issue) ? (array) $issue : (is_array($issue) ? $issue : array());
        $status = isset($row['status']) ? trim((string) $row['status']) : '';
        return $status !== '' ? $status : zw_receipt_issue_default_status();
    }
}

/* ═════════════════════════════ 承办部门（存 key / 展示走 label） ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_dept_key')) {
    /**
     * 承办部门 key 归一（**存 key 非显示名**；**允许留空**——US-04「交办时再补」）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_issue_dept_key($value) {
        return zw_receipt_issue_key($value);
    }
}

if (!function_exists('zw_receipt_issue_dept_label')) {
    /**
     * 承办部门 key → 显示名（**唯一出处** `zw_org_department_label()`，缺失时退回 key，绝不臆造）。
     *
     * @param mixed $key 部门 key。
     * @return string
     */
    function zw_receipt_issue_dept_label($key) {
        $key = trim((string) $key);
        if ($key === '') {
            return '';
        }
        if (function_exists('zw_org_department_label')) {
            return (string) zw_org_department_label($key);
        }
        if (function_exists('zw_receipt_admin_ui_dept_label')) {
            return (string) zw_receipt_admin_ui_dept_label($key);
        }
        return $key;
    }
}

if (!function_exists('zw_receipt_issue_self_protected_ids')) {
    /**
     * 自产 4 id 白名单（铁律 I5；优先复用 R4 canonical，避免第二套口径）。
     *
     * @return string[]
     */
    function zw_receipt_issue_self_protected_ids() {
        if (function_exists('zw_receipt_ledger_self_protected_ids')) {
            return (array) zw_receipt_ledger_self_protected_ids();
        }
        return array(
            'SRC-CHARTER',
            'SRC-BRANCH-RULES',
            'SRC-NDA-BRANCH',
            'SRC-PRIVACY-POLICY',
        );
    }
}

/* ═════════════════════════════ 载荷归一 ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_generate_id')) {
    /**
     * 生成办理单主键（`varchar(64)`；`ISS-RCP-<8位>`）。
     *
     * @param string $source_id 来源收文 id。
     * @return string
     */
    function zw_receipt_issue_generate_id($source_id) {
        $seed = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('', true) . mt_rand();
        return 'ISS-RCP-' . strtoupper(substr(md5((string) $source_id . '|' . $seed), 0, 8));
    }
}

if (!function_exists('zw_receipt_issue_normalize_payload')) {
    /**
     * 派单载荷归一（全部字段白名单化）。
     *
     * @param array $raw 原始载荷。
     * @return array|WP_Error
     */
    function zw_receipt_issue_normalize_payload($raw) {
        $raw = is_array($raw) ? $raw : array();

        $source_id = zw_receipt_issue_sanitize_id(isset($raw['source_id']) ? $raw['source_id'] : '');
        if ($source_id === '') {
            return new WP_Error('zw_receipt_issue_source_required', '缺少来源收文记录（source_id），无法派单。');
        }

        $status = zw_receipt_issue_normalize_status(isset($raw['status']) ? $raw['status'] : '');
        if (is_wp_error($status)) {
            return $status;
        }
        if ($status === '') {
            $status = zw_receipt_issue_default_status();
        }

        $due_date = '';
        if (isset($raw['due_date']) && trim((string) $raw['due_date']) !== '') {
            $due_date = zw_receipt_issue_normalize_date($raw['due_date']);
            if ($due_date === '') {
                return new WP_Error('zw_receipt_issue_due_date_invalid', '办理时限不是合法日期（支持 YYYY-MM-DD）。');
            }
        }

        $title = zw_receipt_issue_text(isset($raw['title']) ? $raw['title'] : '');
        if (function_exists('zw_receipt_create_truncate')) {
            $title = (string) zw_receipt_create_truncate($title, 255);
        }

        return array(
            'id'                => zw_receipt_issue_sanitize_id(isset($raw['id']) ? $raw['id'] : (isset($raw['issue_id']) ? $raw['issue_id'] : '')),
            'source_id'         => $source_id,
            'title'             => $title,
            'description'       => zw_receipt_issue_block(isset($raw['description']) ? $raw['description'] : '', 5000),
            'risk_level'        => zw_receipt_issue_normalize_risk_level(
                isset($raw['urgency']) ? $raw['urgency'] : (isset($raw['risk_level']) ? $raw['risk_level'] : '')
            ),
            'owner_dept'        => zw_receipt_issue_dept_key(isset($raw['owner_dept']) ? $raw['owner_dept'] : ''),
            'owner'             => zw_receipt_issue_text(isset($raw['owner']) ? $raw['owner'] : ''),
            'due_date'          => $due_date,
            'status'            => $status,
            'evidence'          => zw_receipt_issue_block(isset($raw['evidence']) ? $raw['evidence'] : '', 5000),
            'review_conclusion' => zw_receipt_issue_block(isset($raw['review_conclusion']) ? $raw['review_conclusion'] : '', 5000),
        );
    }
}

if (!function_exists('zw_receipt_issue_normalize_update_payload')) {
    /**
     * 办理更新载荷归一（**只允许**改动办理台账自身字段；不支持来源 / 标题改写）。
     *
     * @param array $raw 原始载荷。
     * @return array|WP_Error array('data'=>array,'evidence_append'=>string)
     */
    function zw_receipt_issue_normalize_update_payload($raw) {
        $raw  = is_array($raw) ? $raw : array();
        $data = array();

        foreach (array('owner_dept', 'owner', 'due_date', 'status', 'risk_level', 'description', 'evidence', 'review_conclusion') as $field) {
            if (!array_key_exists($field, $raw)) {
                continue;
            }
            switch ($field) {
                case 'owner_dept':
                    $data['owner_dept'] = zw_receipt_issue_dept_key($raw[$field]);
                    break;
                case 'owner':
                    $data['owner'] = zw_receipt_issue_text($raw[$field]);
                    if (function_exists('zw_receipt_create_truncate')) {
                        $data['owner'] = (string) zw_receipt_create_truncate($data['owner'], 64);
                    }
                    break;
                case 'due_date':
                    $raw_date = trim((string) $raw[$field]);
                    if ($raw_date === '') {
                        $data['due_date'] = '';
                        break;
                    }
                    $date = zw_receipt_issue_normalize_date($raw_date);
                    if ($date === '') {
                        return new WP_Error('zw_receipt_issue_due_date_invalid', '办理时限不是合法日期（支持 YYYY-MM-DD）。');
                    }
                    $data['due_date'] = $date;
                    break;
                case 'status':
                    $status = zw_receipt_issue_normalize_status($raw[$field]);
                    if (is_wp_error($status)) {
                        return $status;
                    }
                    if ($status === '') {
                        break; // 空 = 不修改
                    }
                    $data['status'] = $status;
                    break;
                case 'risk_level':
                    $data['risk_level'] = zw_receipt_issue_normalize_risk_level($raw[$field]);
                    break;
                default:
                    $data[$field] = zw_receipt_issue_block($raw[$field], 5000);
                    break;
            }
        }

        $append = isset($raw['evidence_append']) ? zw_receipt_issue_block($raw['evidence_append'], 5000) : '';

        return array('data' => $data, 'evidence_append' => $append);
    }
}

if (!function_exists('zw_receipt_issue_append_evidence')) {
    /**
     * 证据**追加留痕**（保留既有证据，不改写历史；US-05「办理证据与复核结论可留痕」）。
     *
     * @param mixed  $existing 既有证据。
     * @param string $entry    新增条目。
     * @param string $operator 操作者。
     * @param string $now      时间（`Y-m-d H:i:s`）。
     * @return string
     */
    function zw_receipt_issue_append_evidence($existing, $entry, $operator = '', $now = '') {
        $existing = trim((string) $existing);
        $entry    = trim((string) $entry);
        if ($entry === '') {
            return $existing;
        }
        $operator = trim((string) $operator);
        $now      = trim((string) $now);
        $line     = ($now !== '' ? '[' . $now . ']' : '') . ($operator !== '' ? ' ' . $operator : '') . '：' . $entry;
        return ($existing === '') ? $line : $existing . "\n" . $line;
    }
}

/* ═════════════════════════════ 读取（办理台账 / 来源收文） ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_find')) {
    /**
     * 按主键读取办理单（缺列 / 不存在 → null）。
     *
     * @param mixed $id 主键。
     * @return array|null
     */
    function zw_receipt_issue_find($id) {
        $id = zw_receipt_issue_sanitize_id($id);
        if ($id === '' || zw_receipt_issue_missing_columns()) {
            return null;
        }
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return null;
        }
        $table = zw_receipt_issue_table();
        $sql   = "SELECT id, source_type, source_id, title, description, risk_level, owner_dept, owner,"
            . " due_date, status, evidence, review_conclusion, created_by, create_time, update_time"
            . " FROM {$table} WHERE id = %s LIMIT 1";
        $rows  = $wpdb->get_results(zw_receipt_prepare_sql($sql, array($id)), zw_receipt_issue_array_a());
        if (!is_array($rows) || !$rows) {
            return null;
        }
        $row = $rows[0];
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('zw_receipt_issue_find_source')) {
    /**
     * 读取来源收文行（政策源，**只读**；列缺失 / 不存在 → null）。
     *
     * 必需列含 `origin`（判 incoming）与 R3 的 3 个收文列 ⇒ 迁移未跑时 fail-closed 返回 null。
     *
     * @param mixed $source_id 政策源主键。
     * @return array|null
     */
    function zw_receipt_issue_find_source($source_id) {
        $source_id = zw_receipt_issue_sanitize_id($source_id);
        if ($source_id === '') {
            return null;
        }
        if (function_exists('zw_receipt_source_columns')) {
            $columns = zw_receipt_source_columns();
            foreach (array('id', 'doc_name', 'issuer', 'origin', 'doc_received_date', 'doc_received_no') as $column) {
                if (!isset($columns[$column])) {
                    return null;
                }
            }
        }
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return null;
        }
        $table = zw_receipt_issue_source_table();
        $sql   = "SELECT id, doc_name, issuer, doc_no, doc_received_no, doc_received_date, origin"
            . " FROM {$table} WHERE id = %s LIMIT 1";
        $rows  = $wpdb->get_results(zw_receipt_prepare_sql($sql, array($source_id)), zw_receipt_issue_array_a());
        if (!is_array($rows) || !$rows) {
            return null;
        }
        $row = $rows[0];
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('zw_receipt_issue_verify_source')) {
    /**
     * 派单来源校验（铁律 I5）：必须是 `origin='incoming'` 的收文行，且不在自产 4 id 白名单内。
     *
     * @param mixed $source_id 政策源主键。
     * @return array|WP_Error 合法的来源行。
     */
    function zw_receipt_issue_verify_source($source_id) {
        $source_id = zw_receipt_issue_sanitize_id($source_id);
        if (in_array($source_id, zw_receipt_issue_self_protected_ids(), true)) {
            return new WP_Error(
                'zw_receipt_issue_source_self',
                '该记录属本会自产文件（恒为 origin=self），不得作为收文派单来源。'
            );
        }
        $source = zw_receipt_issue_find_source($source_id);
        if ($source === null) {
            return new WP_Error(
                'zw_receipt_issue_source_invalid',
                '来源收文记录不存在、数据表尚未迁移或缺少必需列，已中止派单，未写入任何数据。'
            );
        }
        if (zw_receipt_sanitize_origin(isset($source['origin']) ? $source['origin'] : '') !== zw_receipt_origin_incoming()) {
            return new WP_Error(
                'zw_receipt_issue_source_not_incoming',
                '来源记录不是外部来文（origin 必须为 incoming），已中止派单，未写入任何数据。'
            );
        }
        return $source;
    }
}

/* ═════════════════════════════ 写入（派单 / 办理） ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_result')) {
    /**
     * 统一返回结构。
     *
     * @param array $row     办理行。
     * @param bool  $created 是否本次新建 / 变更。
     * @param array $extra   附加字段。
     * @return array
     */
    function zw_receipt_issue_result($row, $created, $extra = array()) {
        $row = is_array($row) ? $row : array();
        $out = array(
            'id'         => isset($row['id']) ? (string) $row['id'] : '',
            'source_id'  => isset($row['source_id']) ? (string) $row['source_id'] : '',
            'status'     => isset($row['status']) ? (string) $row['status'] : '',
            'created'    => (bool) $created,
            'idempotent' => !$created,
            'row'        => $row,
        );
        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                $out[$key] = $value;
            }
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_issue_create')) {
    /**
     * 派单（canonical 写入路径）：`zw_policy_issue` 新增一单，`source_type='incoming'`。
     *
     * @param array $raw  原始载荷。
     * @param array $deps 依赖注入（测试用；文本 / 时间 / 操作者）。
     * @return array|WP_Error
     */
    function zw_receipt_issue_create($raw, $deps = array()) {
        global $wpdb;
        $deps    = is_array($deps) ? $deps : array();
        $payload = zw_receipt_issue_normalize_payload($raw);
        if (is_wp_error($payload)) {
            return $payload;
        }
        if (!is_object($wpdb) || !method_exists($wpdb, 'insert')) {
            return new WP_Error('zw_receipt_issue_no_db', '数据库不可用，收文办理单未写入。');
        }

        // ① 缺列短路：迁移未跑 → 零写入。
        $missing = zw_receipt_issue_missing_columns();
        if ($missing) {
            return new WP_Error(
                'zw_receipt_issue_schema_missing',
                '收文办理数据表尚未迁移（缺少列：' . implode('、', $missing) . '），已中止派单，未写入任何数据。'
            );
        }

        // ② 幂等：显式 id 已存在 → 直接返回原记录（不新增行）。
        if ($payload['id'] !== '') {
            $existing = zw_receipt_issue_find($payload['id']);
            if ($existing !== null) {
                return zw_receipt_issue_result($existing, false, array('payload' => $payload));
            }
        }

        // ③ 来源校验：必须是 incoming 收文，且非自产 4 id（铁律 I5）。
        $source = zw_receipt_issue_verify_source($payload['source_id']);
        if (is_wp_error($source)) {
            return $source;
        }

        // ④ 标题回填：未填则取来源标题（来源 `doc_name` NOT NULL）。
        $title = $payload['title'];
        if ($title === '' && isset($source['doc_name'])) {
            $title = zw_receipt_issue_text($source['doc_name']);
        }
        if ($title === '') {
            return new WP_Error('zw_receipt_issue_title_required', '办理事项标题缺失，无法派单。');
        }

        $id = ($payload['id'] !== '') ? $payload['id'] : zw_receipt_issue_generate_id($payload['source_id']);
        // 生成 id 后再次幂等（并发/重复提交）。
        $existing = zw_receipt_issue_find($id);
        if ($existing !== null) {
            return zw_receipt_issue_result($existing, false, array('payload' => $payload));
        }

        $now      = isset($deps['now']) && is_string($deps['now']) && $deps['now'] !== ''
            ? (string) $deps['now']
            : (function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s'));
        $operator = isset($deps['operator']) && is_string($deps['operator'])
            ? (string) $deps['operator']
            : zw_receipt_issue_current_user_login();

        $row = array(
            'id'                => $id,
            'source_type'       => zw_receipt_issue_source_type(),
            'source_id'         => $payload['source_id'],
            'title'             => $title,
            'description'       => ($payload['description'] !== '') ? $payload['description'] : null,
            'risk_level'        => ($payload['risk_level'] !== '') ? $payload['risk_level'] : null,
            'owner_dept'        => ($payload['owner_dept'] !== '') ? $payload['owner_dept'] : null,
            'owner'             => ($payload['owner'] !== '') ? $payload['owner'] : null,
            'due_date'          => ($payload['due_date'] !== '') ? $payload['due_date'] : null,
            'status'            => $payload['status'],
            'evidence'          => ($payload['evidence'] !== '') ? $payload['evidence'] : null,
            'review_conclusion' => ($payload['review_conclusion'] !== '') ? $payload['review_conclusion'] : null,
            'created_by'        => $operator,
            'create_time'       => $now,
            'update_time'       => $now,
        );
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('zw_receipt_issue_row', $row, $payload, $source);
            if (is_array($filtered)) {
                $row = $filtered;
            }
        }

        $table    = zw_receipt_issue_table();
        $inserted = $wpdb->insert($table, $row);
        if ($inserted === false) {
            $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
            if (stripos($error, 'duplicate') !== false) {
                $existing = zw_receipt_issue_find($id);
                if ($existing !== null) {
                    return zw_receipt_issue_result($existing, false, array('payload' => $payload));
                }
            }
            return new WP_Error('zw_receipt_issue_insert_failed', '收文办理单写入失败：' . $error);
        }

        zw_receipt_issue_audit('RECEIPT_ISSUE_CREATE', $row['id'], array(), $row, '收文派单：' . $title);

        return zw_receipt_issue_result($row, true, array('payload' => $payload));
    }
}

if (!function_exists('zw_receipt_issue_update')) {
    /**
     * 办理 / 办结更新（**只写办理台账自身行**）。
     *
     * 明确不做（铁律 I1/I6）：
     * - 不 `UPDATE` `zw_policy_source`（政策源正文 / `status`）；
     * - 不 `UPDATE` `zw_policy_document_version`（生效版本只读）；
     * - 不批量改写 `status`（仅 `array('id' => …)` 单行）。
     *
     * @param array $raw  原始载荷（含 id）。
     * @param array $deps 依赖注入（测试用）。
     * @return array|WP_Error
     */
    function zw_receipt_issue_update($raw, $deps = array()) {
        global $wpdb;
        $raw = is_array($raw) ? $raw : array();
        $deps = is_array($deps) ? $deps : array();

        $id = zw_receipt_issue_sanitize_id(isset($raw['id']) ? $raw['id'] : (isset($raw['issue_id']) ? $raw['issue_id'] : ''));
        if ($id === '') {
            return new WP_Error('zw_receipt_issue_id_required', '缺少办理单 id，无法更新。');
        }
        if (!is_object($wpdb) || !method_exists($wpdb, 'update')) {
            return new WP_Error('zw_receipt_issue_no_db', '数据库不可用，办理状态未更新。');
        }

        $missing = zw_receipt_issue_missing_columns();
        if ($missing) {
            return new WP_Error(
                'zw_receipt_issue_schema_missing',
                '收文办理数据表尚未迁移（缺少列：' . implode('、', $missing) . '），已中止更新，未写入任何数据。'
            );
        }

        $existing = zw_receipt_issue_find($id);
        if ($existing === null) {
            return new WP_Error('zw_receipt_issue_not_found', '办理单不存在：' . $id);
        }

        $payload = zw_receipt_issue_normalize_update_payload($raw);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $now      = isset($deps['now']) && is_string($deps['now']) && $deps['now'] !== ''
            ? (string) $deps['now']
            : (function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s'));
        $operator = isset($deps['operator']) && is_string($deps['operator'])
            ? (string) $deps['operator']
            : zw_receipt_issue_current_user_login();

        $data = $payload['data'];
        if ($payload['evidence_append'] !== '') {
            // 证据留痕：追加而非覆盖（保留历史）。
            $data['evidence'] = zw_receipt_issue_append_evidence(
                isset($existing['evidence']) ? $existing['evidence'] : '',
                $payload['evidence_append'],
                $operator,
                $now
            );
        }
        if (!$data) {
            return new WP_Error('zw_receipt_issue_nothing_to_update', '未提交任何可更新字段。');
        }
        $data['update_time'] = $now;

        $table   = zw_receipt_issue_table();
        $updated = $wpdb->update($table, $data, array('id' => $id));
        if ($updated === false) {
            $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
            return new WP_Error('zw_receipt_issue_update_failed', '办理状态更新失败：' . $error);
        }

        $row = zw_receipt_issue_find($id);
        if ($row === null) {
            $row = array_merge($existing, $data);
        }
        zw_receipt_issue_audit('RECEIPT_ISSUE_UPDATE', $id, $existing, $row, '收文办理更新：' . implode('、', array_keys($data)));

        return zw_receipt_issue_result($row, true, array('fields' => array_keys($data)));
    }
}

if (!function_exists('zw_receipt_issue_current_user_login')) {
    /**
     * 当前用户登录名（standalone 环境返回空串）。
     *
     * @return string
     */
    function zw_receipt_issue_current_user_login() {
        if (function_exists('zw_receipt_current_user')) {
            $user = zw_receipt_current_user();
        } else {
            $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        }
        return (is_object($user) && isset($user->user_login)) ? (string) $user->user_login : '';
    }
}

if (!function_exists('zw_receipt_issue_audit')) {
    /**
     * 办理审计（复用 AI 法务部既有审计通道；不可用时静默跳过，不影响写入结果）。
     *
     * 目标类型固定 `policy_issue`（与登记的 `policy_source` 区分）。
     *
     * @param string $action 动作。
     * @param string $id     办理单主键。
     * @param array  $before 变更前。
     * @param array  $after  变更后。
     * @param string $detail 说明。
     * @return bool
     */
    function zw_receipt_issue_audit($action, $id, $before, $after, $detail = '') {
        if (!function_exists('zw_policy_platform_audit_insert')) {
            return false;
        }
        zw_policy_platform_audit_insert(
            (string) $action,
            'policy_issue',
            array((string) $id),
            is_array($before) ? $before : array(),
            is_array($after) ? $after : array(),
            zw_receipt_issue_current_user_login(),
            (string) $detail
        );
        return true;
    }
}

/* ═════════════════════════════ 查询（办理视图列表） ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_default_per_page')) {
    /**
     * 默认每页条数。
     *
     * @return int
     */
    function zw_receipt_issue_default_per_page() {
        return 20;
    }
}

if (!function_exists('zw_receipt_issue_max_per_page')) {
    /**
     * 每页条数上限（防超大 LIMIT）。
     *
     * @return int
     */
    function zw_receipt_issue_max_per_page() {
        return 100;
    }
}

if (!function_exists('zw_receipt_issue_normalize_filters')) {
    /**
     * 列表筛选归一（白名单 + 夹取）。
     *
     * @param array $args 原始筛选。
     * @return array
     */
    function zw_receipt_issue_normalize_filters($args) {
        $args = is_array($args) ? $args : array();

        $source_id = zw_receipt_issue_sanitize_id(isset($args['source_id']) ? $args['source_id'] : '');
        $dept      = zw_receipt_issue_dept_key(isset($args['dept']) ? $args['dept'] : '');
        $status    = zw_receipt_issue_text(isset($args['status']) ? $args['status'] : '');
        if ($status !== zw_receipt_issue_derived_status() && !in_array($status, zw_receipt_issue_statuses(), true)) {
            $status = '';
        }
        $keyword = zw_receipt_issue_text(isset($args['kw']) ? $args['kw'] : '');
        if (function_exists('zw_receipt_create_truncate')) {
            $keyword = (string) zw_receipt_create_truncate($keyword, 100);
        }
        $overdue = false;
        if (isset($args['overdue'])) {
            $raw_overdue = $args['overdue'];
            $overdue = (!is_array($raw_overdue) && !is_object($raw_overdue))
                && in_array(strtolower(trim((string) $raw_overdue)), array('1', 'on', 'true', 'yes'), true);
        }

        $page = zw_receipt_issue_absint(isset($args['page']) ? $args['page'] : 1);
        if ($page < 1) {
            $page = 1;
        }
        $per_page = zw_receipt_issue_absint(isset($args['per_page']) ? $args['per_page'] : 0);
        if ($per_page < 1) {
            $per_page = zw_receipt_issue_default_per_page();
        }
        $per_page = min($per_page, zw_receipt_issue_max_per_page());

        return array(
            'source_id' => $source_id,
            'dept'      => $dept,
            'status'    => $status,
            'kw'        => $keyword,
            'overdue'   => $overdue,
            'issue_id'  => zw_receipt_issue_sanitize_id(isset($args['issue']) ? $args['issue'] : ''),
            'page'      => $page,
            'per_page'  => $per_page,
        );
    }
}

if (!function_exists('zw_receipt_issue_where')) {
    /**
     * WHERE 构造器（列表与计数**共用**，保证口径唯一）。
     *
     * 产出 `sql` 只含固定字面量与 `%s`/`%d` 占位符；全部用户输入进 `params`。
     *
     * @param array $filters 归一后的筛选。
     * @return array array('sql'=>string,'params'=>array)
     */
    function zw_receipt_issue_where($filters) {
        $filters = zw_receipt_issue_normalize_filters($filters);

        // ① 分流：办理视图只显示收文派单（source_type='incoming'）。
        $clauses = array('source_type = %s');
        $params  = array(zw_receipt_issue_source_type());

        // ② 铁律 I5 第二重保险：自产 4 id 反向白名单（字面量来自代码常量，不来自用户输入）。
        $protected = array();
        foreach (zw_receipt_issue_self_protected_ids() as $protected_id) {
            $protected_id = zw_receipt_issue_sanitize_id($protected_id);
            if ($protected_id !== '') {
                $protected[] = "'" . $protected_id . "'";
            }
        }
        if ($protected) {
            $clauses[] = 'source_id NOT IN (' . implode(', ', $protected) . ')';
        }

        // ③ 来源收文
        if ($filters['source_id'] !== '') {
            $clauses[] = 'source_id = %s';
            $params[]  = $filters['source_id'];
        }

        // ④ 承办部门（key）
        if ($filters['dept'] !== '') {
            $clauses[] = 'owner_dept = %s';
            $params[]  = $filters['dept'];
        }

        // ⑤ 状态：派生「逾期」走上限判定；可写状态走等值（`逾期` 不是可写状态）。
        if ($filters['status'] === zw_receipt_issue_derived_status() || $filters['overdue']) {
            $clauses[] = "due_date <> '' AND due_date IS NOT NULL AND due_date < %s AND status NOT IN ('已办结', '归档')";
            $params[]  = zw_receipt_issue_today();
        } elseif ($filters['status'] !== '') {
            $clauses[] = 'status = %s';
            $params[]  = $filters['status'];
        }

        // ⑥ 关键词（办理事项标题）
        if ($filters['kw'] !== '') {
            $clauses[] = 'title LIKE %s';
            $params[]  = '%' . $filters['kw'] . '%';
        }

        return array('sql' => implode(' AND ', $clauses), 'params' => $params, 'filters' => $filters);
    }
}

if (!function_exists('zw_receipt_issue_query')) {
    /**
     * 办理单列表查询（只读；唯一入口）。
     *
     * 缺列（迁移未跑）/ DB 不可用 → 空结果且**零 SQL**。
     *
     * @param array $args 原始筛选。
     * @return array
     */
    function zw_receipt_issue_query($args = array()) {
        $filters = zw_receipt_issue_normalize_filters($args);
        $result  = array(
            'rows'         => array(),
            'total'        => 0,
            'page'         => (int) $filters['page'],
            'per_page'     => (int) $filters['per_page'],
            'pages'        => 0,
            'schema_ready' => true,
            'missing'      => array(),
            'filters'      => $filters,
            'sql'          => '',
            'params'       => array(),
        );

        $missing = zw_receipt_issue_missing_columns();
        if ($missing) {
            $result['schema_ready'] = false;
            $result['missing']      = $missing;
            $result['page']         = 1;
            return $result;
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !method_exists($wpdb, 'get_var')) {
            $result['schema_ready'] = false;
            $result['missing']      = array('wpdb');
            $result['page']         = 1;
            return $result;
        }

        $table = zw_receipt_issue_table();
        $where = zw_receipt_issue_where($filters);
        $result['sql']    = $where['sql'];
        $result['params'] = $where['params'];

        $count = $wpdb->get_var(zw_receipt_prepare_sql(
            "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}",
            $where['params']
        ));
        $result['total'] = max(0, (int) $count);
        $result['pages'] = ($result['total'] > 0)
            ? (int) ceil($result['total'] / max(1, (int) $result['per_page']))
            : 0;

        $offset = ((int) $result['page'] - 1) * (int) $result['per_page'];
        $sql    = "SELECT id, source_type, source_id, title, description, risk_level, owner_dept, owner,"
            . " due_date, status, evidence, review_conclusion, created_by, create_time, update_time"
            . " FROM {$table} WHERE {$where['sql']}"
            . " ORDER BY (due_date IS NULL OR due_date = '') ASC, due_date ASC, id DESC"
            . " LIMIT %d OFFSET %d";
        $params = array_merge($where['params'], array((int) $result['per_page'], (int) $offset));
        $rows   = $wpdb->get_results(zw_receipt_prepare_sql($sql, $params), zw_receipt_issue_array_a());

        $result['rows'] = is_array($rows) ? array_values($rows) : array();
        return $result;
    }
}

if (!function_exists('zw_receipt_issue_row_view')) {
    /**
     * 单行展示结构（页面渲染与测试共用；无副作用）。
     *
     * @param array  $row   办理行。
     * @param string $today 今日（可选）。
     * @return array
     */
    function zw_receipt_issue_row_view($row, $today = '') {
        $row     = is_array($row) ? $row : array();
        $dept    = isset($row['owner_dept']) ? (string) $row['owner_dept'] : '';
        $overdue = zw_receipt_issue_is_overdue($row, $today);

        return array(
            'id'                => isset($row['id']) ? (string) $row['id'] : '',
            'source_id'         => isset($row['source_id']) ? (string) $row['source_id'] : '',
            'source_type'       => isset($row['source_type']) ? (string) $row['source_type'] : '',
            'title'             => isset($row['title']) ? (string) $row['title'] : '',
            'description'       => isset($row['description']) ? (string) $row['description'] : '',
            'risk_level'        => isset($row['risk_level']) ? (string) $row['risk_level'] : '',
            'dept_key'          => $dept,
            'dept_label'        => zw_receipt_issue_dept_label($dept),
            'owner'             => isset($row['owner']) ? (string) $row['owner'] : '',
            'due_date'          => isset($row['due_date']) ? (string) $row['due_date'] : '',
            'status'            => isset($row['status']) ? (string) $row['status'] : '',
            'display_status'    => zw_receipt_issue_display_status($row, $today),
            'overdue'           => $overdue,
            'evidence'          => isset($row['evidence']) ? (string) $row['evidence'] : '',
            'review_conclusion' => isset($row['review_conclusion']) ? (string) $row['review_conclusion'] : '',
            'created_by'        => isset($row['created_by']) ? (string) $row['created_by'] : '',
            'create_time'       => isset($row['create_time']) ? (string) $row['create_time'] : '',
            'update_time'       => isset($row['update_time']) ? (string) $row['update_time'] : '',
        );
    }
}

/* ═════════════════════════════ 权限 / nonce / URL ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_can')) {
    /**
     * 办理视图权限判定（Q6/Q14：`tcpc_perm_doc`；无 WP 环境一律 fail-closed）。
     *
     * @return bool
     */
    function zw_receipt_issue_can() {
        if (function_exists('zw_receipt_create_can')) {
            return (bool) zw_receipt_create_can();
        }
        if (!function_exists('current_user_can')) {
            return false;
        }
        return (bool) current_user_can(zw_receipt_capability());
    }
}

if (!function_exists('zw_receipt_issue_verify_request')) {
    /**
     * 办理入口鉴权：能力位 + nonce **双判**（US-08：页面 + 提交入口双判）。
     *
     * @param array  $request 请求数据（$_POST）。
     * @param string $action  nonce 动作（派单 / 办理）。
     * @return true|WP_Error
     */
    function zw_receipt_issue_verify_request($request, $action) {
        if (!zw_receipt_issue_can()) {
            return new WP_Error('zw_receipt_forbidden', '无权限办理收文（需要 tcpc_perm_doc）。');
        }
        $request = is_array($request) ? $request : array();
        $nonce   = isset($request[ZW_RECEIPT_ISSUE_NONCE_FIELD]) ? (string) $request[ZW_RECEIPT_ISSUE_NONCE_FIELD] : '';
        if (!function_exists('wp_verify_nonce') || !function_exists('wp_unslash')) {
            return new WP_Error('zw_receipt_bad_nonce', '办理校验失败：安全令牌不可校验。');
        }
        if ($nonce === '' || !wp_verify_nonce((string) wp_unslash($nonce), (string) $action)) {
            return new WP_Error('zw_receipt_bad_nonce', '办理校验失败：安全令牌无效或已过期，请刷新页面重试。');
        }
        return true;
    }
}

if (!function_exists('zw_receipt_issue_request_args')) {
    /**
     * 从请求（`$_GET`）提取参数（键名限定，未识别键一律忽略）。
     *
     * @param array $request 请求数组。
     * @return array
     */
    function zw_receipt_issue_request_args($request) {
        $request = is_array($request) ? $request : array();
        return array(
            'source_id' => isset($request['zw_receipt_source']) ? $request['zw_receipt_source'] : '',
            'dept'      => isset($request['zw_receipt_dept']) ? $request['zw_receipt_dept'] : '',
            'status'    => isset($request['zw_receipt_status']) ? $request['zw_receipt_status'] : '',
            'kw'        => isset($request['zw_receipt_kw']) ? $request['zw_receipt_kw'] : '',
            'overdue'   => isset($request['zw_receipt_overdue']) ? $request['zw_receipt_overdue'] : '',
            'issue'     => isset($request['zw_issue']) ? $request['zw_issue'] : '',
            'page'      => isset($request['zw_receipt_page']) ? $request['zw_receipt_page'] : 1,
            'per_page'  => isset($request['zw_receipt_per_page']) ? $request['zw_receipt_per_page'] : '',
        );
    }
}

if (!function_exists('zw_receipt_issue_page_url')) {
    /**
     * 办理视图 URL（筛选 / 分页链接；全部参数归一后拼装）。
     *
     * @param array  $args 筛选。
     * @param int    $page 目标页。
     * @param string $slug 页面 slug。
     * @return string
     */
    function zw_receipt_issue_page_url($args, $page = 1, $slug = 'zw-receipt-issues') {
        $filters = zw_receipt_issue_normalize_filters($args);
        $query   = array('page' => (string) $slug);
        if ($filters['source_id'] !== '') {
            $query['zw_receipt_source'] = $filters['source_id'];
        }
        if ($filters['dept'] !== '') {
            $query['zw_receipt_dept'] = $filters['dept'];
        }
        if ($filters['status'] !== '') {
            $query['zw_receipt_status'] = $filters['status'];
        }
        if ($filters['kw'] !== '') {
            $query['zw_receipt_kw'] = $filters['kw'];
        }
        if ($filters['overdue']) {
            $query['zw_receipt_overdue'] = '1';
        }
        if ($filters['issue_id'] !== '') {
            $query['zw_issue'] = $filters['issue_id'];
        }
        if ((int) $filters['per_page'] !== zw_receipt_issue_default_per_page()) {
            $query['zw_receipt_per_page'] = (int) $filters['per_page'];
        }
        $query['zw_receipt_page'] = max(1, (int) $page);

        $base = function_exists('admin_url') ? (string) admin_url('admin.php') : 'admin.php';
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($query, $base);
        }
        return $base . '?' . http_build_query($query);
    }
}

/* ═════════════════════════════ 提交入口（admin-post） ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_handle_create_submission')) {
    /**
     * 派单提交处理（纯逻辑，便于单测直调）：鉴权 → 归一 → 写入。
     *
     * @param array $request $_POST。
     * @return array|WP_Error
     */
    function zw_receipt_issue_handle_create_submission($request) {
        $verify = zw_receipt_issue_verify_request($request, ZW_RECEIPT_ISSUE_NONCE_CREATE);
        if (is_wp_error($verify)) {
            return $verify;
        }
        return zw_receipt_issue_create(is_array($request) ? $request : array());
    }
}

if (!function_exists('zw_receipt_issue_handle_update_submission')) {
    /**
     * 办理提交处理（纯逻辑，便于单测直调）：鉴权 → 归一 → 单行更新。
     *
     * @param array $request $_POST。
     * @return array|WP_Error
     */
    function zw_receipt_issue_handle_update_submission($request) {
        $verify = zw_receipt_issue_verify_request($request, ZW_RECEIPT_ISSUE_NONCE_UPDATE);
        if (is_wp_error($verify)) {
            return $verify;
        }
        return zw_receipt_issue_update(is_array($request) ? $request : array());
    }
}

if (!function_exists('zw_receipt_issue_handle_create_post')) {
    /**
     * `admin_post_zw_receipt_issue_create` 入口（仅功能开启后由本文件注册）。
     *
     * @return void
     */
    function zw_receipt_issue_handle_create_post() {
        if (!function_exists('wp_die') || !function_exists('wp_safe_redirect')) {
            return;
        }
        $result = zw_receipt_issue_handle_create_submission(isset($_POST) ? (array) $_POST : array());
        if (is_wp_error($result)) {
            $code   = (string) $result->get_error_code();
            $status = in_array($code, array('zw_receipt_forbidden', 'zw_receipt_bad_nonce'), true) ? 403 : 400;
            wp_die(esc_html($result->get_error_message()), '收文派单失败', array('response' => $status));
            return;
        }
        $base = function_exists('admin_url') ? admin_url('admin.php') : 'admin.php';
        wp_safe_redirect(add_query_arg(array(
            'page'         => 'zw-receipt-issues',
            'zw_msg'       => !empty($result['idempotent']) ? 'issue_duplicate' : 'issue_created',
            'zw_receipt_source' => rawurlencode((string) $result['source_id']),
            'zw_issue'     => rawurlencode((string) $result['id']),
        ), $base));
        exit;
    }
}

if (!function_exists('zw_receipt_issue_handle_update_post')) {
    /**
     * `admin_post_zw_receipt_issue_update` 入口（仅功能开启后由本文件注册）。
     *
     * @return void
     */
    function zw_receipt_issue_handle_update_post() {
        if (!function_exists('wp_die') || !function_exists('wp_safe_redirect')) {
            return;
        }
        $result = zw_receipt_issue_handle_update_submission(isset($_POST) ? (array) $_POST : array());
        if (is_wp_error($result)) {
            $code   = (string) $result->get_error_code();
            $status = in_array($code, array('zw_receipt_forbidden', 'zw_receipt_bad_nonce'), true) ? 403 : 400;
            wp_die(esc_html($result->get_error_message()), '收文办理更新失败', array('response' => $status));
            return;
        }
        $base = function_exists('admin_url') ? admin_url('admin.php') : 'admin.php';
        wp_safe_redirect(add_query_arg(array(
            'page'              => 'zw-receipt-issues',
            'zw_msg'            => 'issue_updated',
            'zw_receipt_source' => rawurlencode((string) $result['source_id']),
            'zw_issue'          => rawurlencode((string) $result['id']),
        ), $base));
        exit;
    }
}

/* ═════════════════════════════ 页面渲染 ═════════════════════════════ */

if (!function_exists('zw_receipt_issue_source_view')) {
    /**
     * 来源收文摘要视图（**只读**政策源；缺列 / 不存在 → null）。
     *
     * @param mixed $source_id 政策源主键。
     * @return array|null
     */
    function zw_receipt_issue_source_view($source_id) {
        $source = zw_receipt_issue_find_source($source_id);
        if ($source === null) {
            return null;
        }
        $issuer = isset($source['issuer']) ? (string) $source['issuer'] : '';
        $label  = function_exists('zw_receipt_display_issuer') ? (string) zw_receipt_display_issuer($issuer) : $issuer;
        return array(
            'id'          => isset($source['id']) ? (string) $source['id'] : '',
            'title'       => isset($source['doc_name']) ? (string) $source['doc_name'] : '',
            'org_label'   => $label,
            'doc_no'      => isset($source['doc_no']) ? (string) $source['doc_no'] : '',
            'number'      => isset($source['doc_received_no']) ? (string) $source['doc_received_no'] : '',
            'received_on' => isset($source['doc_received_date']) ? (string) $source['doc_received_date'] : '',
        );
    }
}

if (!function_exists('zw_receipt_issue_render_filters')) {
    /**
     * 列表筛选表单（GET；只读视图不引入 nonce）。
     *
     * @param array $filters 归一后的筛选。
     * @return void
     */
    function zw_receipt_issue_render_filters($filters) {
        echo '<form method="get" style="margin:12px 0">';
        echo '<input type="hidden" name="page" value="zw-receipt-issues">';
        echo '<input type="text" name="zw_receipt_source" value="' . zw_receipt_admin_ui_attr($filters['source_id'])
            . '" placeholder="来源收文 id（如 RCP-2026-…）" size="24"> ';
        echo zw_receipt_admin_ui_dept_select('zw_receipt_dept', $filters['dept'], true) . ' ';
        echo '<select name="zw_receipt_status">';
        echo '<option value="">全部状态</option>';
        foreach (zw_receipt_issue_status_options() as $value => $label) {
            $value = (string) $value;
            echo '<option value="' . zw_receipt_admin_ui_attr($value) . '"'
                . (($filters['status'] === $value) ? ' selected' : '') . '>' . zw_receipt_admin_ui_esc($label) . '</option>';
        }
        echo '<option value="' . zw_receipt_admin_ui_attr(zw_receipt_issue_derived_status()) . '"'
            . (($filters['status'] === zw_receipt_issue_derived_status()) ? ' selected' : '') . '>'
            . zw_receipt_admin_ui_esc(zw_receipt_issue_derived_status()) . '（派生）</option>';
        echo '</select> ';
        echo '<input type="search" name="zw_receipt_kw" value="' . zw_receipt_admin_ui_attr($filters['kw'])
            . '" placeholder="办理事项标题"> ';
        echo '<label class="zw-adm-touch"><input type="checkbox" name="zw_receipt_overdue" value="1"'
            . ($filters['overdue'] ? ' checked' : '') . '> 仅看逾期（派生）</label> ';
        echo '<button type="submit" class="button">筛选</button>';
        echo '</form>';
    }
}

if (!function_exists('zw_receipt_issue_admin_post_url')) {
    /**
     * `admin-post.php` 地址（无 WP 环境退化为相对路径）。
     *
     * @return string
     */
    function zw_receipt_issue_admin_post_url() {
        if (function_exists('admin_url')) {
            return (string) admin_url('admin-post.php');
        }
        return 'admin-post.php';
    }
}

if (!function_exists('zw_receipt_issue_render_create_form')) {
    /**
     * 派单表单（POST + nonce；来源收文固定为隐藏域，防篡改由服务端二次校验）。
     *
     * @param array $source 来源收文视图。
     * @return void
     */
    function zw_receipt_issue_render_create_form($source) {
        $source = is_array($source) ? $source : array();
        echo '<h2 style="margin-bottom:4px">派单（承办部门 / 办理时限）</h2>';
        echo '<p style="color:#646970">来源：<code>' . zw_receipt_admin_ui_esc(isset($source['id']) ? $source['id'] : '')
            . '</code> · ' . zw_receipt_admin_ui_esc(isset($source['number']) ? $source['number'] : '')
            . ' · ' . zw_receipt_admin_ui_esc(isset($source['title']) ? $source['title'] : '') . '</p>';
        echo '<form method="post" action="' . zw_receipt_admin_ui_url(zw_receipt_issue_admin_post_url())
            . '" style="padding:14px 16px;max-width:820px;border:1px solid #c3c4c7;background:#fff">';
        echo '<input type="hidden" name="action" value="zw_receipt_issue_create">';
        echo '<input type="hidden" name="source_id" value="' . zw_receipt_admin_ui_attr(isset($source['id']) ? $source['id'] : '') . '">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(ZW_RECEIPT_ISSUE_NONCE_CREATE, ZW_RECEIPT_ISSUE_NONCE_FIELD);
        }
        echo zw_receipt_admin_ui_field('办理事项标题', 'title', isset($source['title']) ? $source['title'] : '', true);
        echo '<p><label>承办部门（可留空，交办时再补）<br>' . zw_receipt_admin_ui_dept_select('owner_dept', '', true) . '</label></p>';
        echo zw_receipt_admin_ui_field('承办人', 'owner', '', false);
        echo zw_receipt_admin_ui_field('办理时限', 'due_date', '', false, '2026-10-31');
        echo '<p><label>紧急程度<br><select name="urgency">';
        foreach (zw_receipt_issue_urgency_options() as $value => $label) {
            echo '<option value="' . zw_receipt_admin_ui_attr($value) . '">' . zw_receipt_admin_ui_esc($label) . '</option>';
        }
        echo '</select></label></p>';
        echo zw_receipt_admin_ui_textarea('办理要求', 'description', '', 3);
        echo '<p><button type="submit" class="button button-primary">派单</button></p>';
        echo '</form>';
    }
}

if (!function_exists('zw_receipt_issue_render_update_form')) {
    /**
     * 办理 / 办结表单（POST + nonce；状态下拉**不含**「逾期」）。
     *
     * @param array $issue 办理行视图。
     * @return void
     */
    function zw_receipt_issue_render_update_form($issue) {
        $issue = is_array($issue) ? $issue : array();
        echo '<h2 style="margin:20px 0 4px">办理 / 办结</h2>';
        echo '<p style="color:#646970">办理单 <code>' . zw_receipt_admin_ui_esc(isset($issue['id']) ? $issue['id'] : '')
            . '</code> · 状态 '
            . zw_receipt_admin_ui_status_badge(isset($issue['status']) ? $issue['status'] : '', !empty($issue['overdue']))
            . ' · 「逾期」为派生状态（时限早于今日且未办结），不可手工设置。</p>';
        echo '<form method="post" action="' . zw_receipt_admin_ui_url(zw_receipt_issue_admin_post_url())
            . '" style="padding:14px 16px;max-width:820px;border:1px solid #c3c4c7;background:#fff">';
        echo '<input type="hidden" name="action" value="zw_receipt_issue_update">';
        echo '<input type="hidden" name="id" value="' . zw_receipt_admin_ui_attr(isset($issue['id']) ? $issue['id'] : '') . '">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(ZW_RECEIPT_ISSUE_NONCE_UPDATE, ZW_RECEIPT_ISSUE_NONCE_FIELD);
        }
        echo '<p><label>办理状态<br>' . zw_receipt_admin_ui_status_select('status', '', true) . '</label></p>';
        echo '<p><label>承办部门（可留空）<br>'
            . zw_receipt_admin_ui_dept_select('owner_dept', isset($issue['dept_key']) ? $issue['dept_key'] : '', true)
            . '</label></p>';
        echo zw_receipt_admin_ui_field('承办人', 'owner', isset($issue['owner']) ? $issue['owner'] : '', false);
        echo zw_receipt_admin_ui_field('办理时限', 'due_date', isset($issue['due_date']) ? $issue['due_date'] : '', false, '2026-10-31');
        echo zw_receipt_admin_ui_textarea('办理证据（追加留痕：保留既有证据，不做覆盖）', 'evidence_append', '', 3);
        echo zw_receipt_admin_ui_textarea('复核结论', 'review_conclusion', isset($issue['review_conclusion']) ? $issue['review_conclusion'] : '', 3);
        echo '<p><button type="submit" class="button button-primary">更新办理</button></p>';
        echo '</form>';

        if (isset($issue['evidence']) && (string) $issue['evidence'] !== '') {
            echo '<h3 style="margin:16px 0 4px">既有办理证据</h3>';
            echo '<pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #c3c4c7;padding:8px">'
                . zw_receipt_admin_ui_esc($issue['evidence']) . '</pre>';
        }
    }
}

if (!function_exists('zw_receipt_issue_render_table')) {
    /**
     * 办理单列表表格。
     *
     * @param array $rows 原始行。
     * @return void
     */
    function zw_receipt_issue_render_table($rows) {
        $rows = is_array($rows) ? $rows : array();
        if (!$rows) {
            echo '<p>暂无办理单。</p>';
            return;
        }
        // TASK-1633（TASK-1579 P1-6 第四批）：宽表落 canonical 横滑包裹。
        // 规则由本域唯一输出点 zw_receipt_admin_ui_page_open()（本页 :1787 调用）透传 canonical
        // `zw_adm_ui_table_wrap_css()` ⇒ 本函数不新增 <style>、不复制第二套声明。
        echo '<div class="zw-adm-table-wrap">';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>状态</th><th>办理事项</th><th>来源收文</th><th>承办部门</th><th>承办人</th>'
            . '<th>办理时限</th><th>紧急程度</th><th>更新时间</th><th>操作</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $view = zw_receipt_issue_row_view($row);
            echo '<tr>';
            echo '<td>' . zw_receipt_admin_ui_status_badge($view['status'], $view['overdue']) . '</td>';
            echo '<td>' . zw_receipt_admin_ui_esc($view['title']) . '</td>';
            echo '<td><code>' . zw_receipt_admin_ui_esc($view['source_id']) . '</code></td>';
            echo '<td>' . zw_receipt_admin_ui_esc($view['dept_label']) . '</td>';
            echo '<td>' . zw_receipt_admin_ui_esc($view['owner']) . '</td>';
            echo '<td>' . zw_receipt_admin_ui_esc($view['due_date']) . '</td>';
            echo '<td>' . zw_receipt_admin_ui_esc($view['risk_level']) . '</td>';
            echo '<td>' . zw_receipt_admin_ui_esc($view['update_time']) . '</td>';
            $url = zw_receipt_issue_page_url(array('source_id' => $view['source_id'], 'issue' => $view['id']), 1);
            echo '<td><a class="button button-small" href="' . zw_receipt_admin_ui_url($url) . '">办理</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}

if (!function_exists('zw_receipt_page_issues')) {
    /**
     * 收文办理视图（capability = `tcpc_perm_doc`；页面级二次判权，无权限直接 403）。
     *
     * 数据源：`zw_policy_issue`（`source_type='incoming'`）+ 只读 `zw_policy_source` 摘要。
     *
     * @return void
     */
    function zw_receipt_page_issues() {
        if (!zw_receipt_issue_can()) {
            if (function_exists('wp_die')) {
                wp_die('无权限访问收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
            }
            return;
        }
        if (!function_exists('esc_html')) {
            return;
        }

        $request   = isset($_GET) ? (array) $_GET : array();
        $args      = zw_receipt_issue_request_args($request);
        $result    = zw_receipt_issue_query($args);
        $filters   = $result['filters'];
        $msg       = isset($request['zw_msg']) ? zw_receipt_issue_key($request['zw_msg']) : '';

        zw_receipt_admin_ui_page_open(
            '收文办理',
            '复用既有办理台账 <code>zw_policy_issue</code>（只显示 <code>source_type=incoming</code> 的收文派单）；'
            . '承办部门存组织架构部门 <strong>key</strong> 并允许留空；<strong>「逾期」为派生状态</strong>'
            . '（时限早于今日且未办结），不写回库、不影响政策源正文与生效版本。',
            'zw-receipt-issues'
        );
        echo zw_receipt_admin_ui_notice_html($msg);

        if (!$result['schema_ready']) {
            echo '<div class="notice notice-warning"><p>收文办理暂不可用：办理台账尚未迁移（缺少列：'
                . zw_receipt_admin_ui_esc(implode('、', $result['missing'])) . '），本次返回 0 行（不显示任何记录）。</p></div>';
            zw_receipt_admin_ui_page_close();
            return;
        }

        zw_receipt_issue_render_filters($filters);

        // 派单区（指定来源收文时展示）
        $source_view = null;
        if ($filters['source_id'] !== '') {
            $source_view = zw_receipt_issue_source_view($filters['source_id']);
            if ($source_view === null) {
                echo '<div class="notice notice-warning"><p>来源收文 <code>'
                    . zw_receipt_admin_ui_esc($filters['source_id'])
                    . '</code> 不存在、尚未迁移或不是外部来文（<code>origin=incoming</code>），无法派单。</p></div>';
            } else {
                zw_receipt_issue_render_create_form($source_view);
            }
        } else {
            echo '<p style="color:#646970">派单请从 <a href="'
                . zw_receipt_admin_ui_url(zw_receipt_issue_page_url(array(), 1, 'zw-receipt-ledger'))
                . '">收文台账</a> 选择一件收文后进入本页（或在下方筛选框填入来源收文 id）。</p>';
        }

        echo '<h2 style="margin:20px 0 4px">办理单（' . (int) $result['total'] . ' 条）</h2>';
        zw_receipt_issue_render_table($result['rows']);

        // 办理表单（点选某单「办理」后展示）
        if ($filters['issue_id'] !== '') {
            $issue = zw_receipt_issue_find($filters['issue_id']);
            if ($issue === null) {
                echo '<div class="notice notice-warning"><p>办理单 <code>'
                    . zw_receipt_admin_ui_esc($filters['issue_id']) . '</code> 不存在。</p></div>';
            } else {
                zw_receipt_issue_render_update_form(zw_receipt_issue_row_view($issue));
            }
        }

        // 分页
        $pages = (int) $result['pages'];
        if ($pages > 1) {
            echo '<p style="margin-top:12px">';
            if ((int) $filters['page'] > 1) {
                echo '<a class="button" href="' . zw_receipt_admin_ui_url(zw_receipt_issue_page_url($args, (int) $filters['page'] - 1))
                    . '">上一页</a> ';
            }
            echo '第 ' . (int) $filters['page'] . ' / ' . $pages . ' 页（共 ' . (int) $result['total'] . ' 条）';
            if ((int) $filters['page'] < $pages) {
                echo ' <a class="button" href="' . zw_receipt_admin_ui_url(zw_receipt_issue_page_url($args, (int) $filters['page'] + 1))
                    . '">下一页</a>';
            }
            echo '</p>';
        }

        zw_receipt_admin_ui_page_close();
    }
}

if (function_exists('add_action')) {
    // 仅在功能开启（模块文件被加载）时存在 ⇒ 关闭时零入口（架构稿 §6.1）。
    add_action('admin_post_zw_receipt_issue_create', 'zw_receipt_issue_handle_create_post');
    add_action('admin_post_zw_receipt_issue_update', 'zw_receipt_issue_handle_update_post');
}
