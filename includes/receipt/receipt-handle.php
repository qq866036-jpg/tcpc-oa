<?php
/**
 * 收文管理 · 办理后半段：拟办 / 批示 / 传阅 / 催办 / 归档（OA-2464）。
 *
 * ## 为什么是新表而不是扩状态机
 *
 * `zw_policy_issue` 同时服务 AI 法务部的整改台账（`source_type≠incoming`），扩状态值会污染既有语义
 * （`receipt-issues.php` 文件头已明写）。而拟办/批示/传阅/催办是**同一件事上的多条留痕**，天然一对多
 * ⇒ 用 **append-only 动作流水**表达，收文状态机仍是 4 态 + 派生逾期，一码不动。
 *
 * | 表 | 语义 | 写规则 |
 * | --- | --- | --- |
 * | `{prefix}zw_receipt_handle_log` | 拟办 `suggest` / 批示 `instruction` / 催办 `urge` | 只 INSERT，**不 UPDATE、不 DELETE** |
 * | `{prefix}zw_receipt_circulation` | 传阅 / 签阅（`(receipt, target)` 唯一） | 只 INSERT；签阅只写 `read_at` 一列 |
 *
 * 归档盒号 / 归档年度 / 页数 → `zw_policy_source` 增 3 列（单行 UPDATE，只写这 3 列）。
 *
 * ## 铁律
 *
 * - 所有写入口：`tcpc_perm_doc` + nonce + 只对 `origin='incoming'` 的行生效；
 * - 全部 SQL 走 `$wpdb->prepare`；表名来自 `$wpdb->prefix` 常量，不收用户输入；
 * - 缺表/缺列 ⇒ **零查询零写入**，页面显示「未迁移」而不是白屏；
 * - 不触碰 `zw_policy_document_version`、`doc_name` 与正文生效面。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_receipt_handle_tables')) {
    /**
     * 两张新表名（`$wpdb->prefix` + 固定名）。
     *
     * @return array<string,string>
     */
    function zw_receipt_handle_tables() {
        global $wpdb;
        $prefix = (is_object($wpdb) && isset($wpdb->prefix)) ? (string) $wpdb->prefix : 'wp_';
        return array(
            'log'   => $prefix . 'zw_receipt_handle_log',
            'circ'  => $prefix . 'zw_receipt_circulation',
        );
    }
}

if (!function_exists('zw_receipt_handle_new_columns')) {
    /**
     * 政策源新增列（迁移脚本只 ALTER 这些）。
     *
     * @return array<string,string>
     */
    function zw_receipt_handle_new_columns() {
        return array(
            'page_count'     => 'SMALLINT UNSIGNED NULL',
            'archive_box_no' => 'VARCHAR(64) NULL',
            'archive_year'   => 'SMALLINT UNSIGNED NULL',
        );
    }
}

if (!function_exists('zw_receipt_handle_missing')) {
    /**
     * 缺什么（缺表 + 缺列）。非空 ⇒ 调用方必须短路。
     *
     * @param bool $fresh 强制重查列。
     * @return array{tables:string[],columns:string[]}
     */
    function zw_receipt_handle_missing($fresh = false) {
        global $wpdb;
        $missing = array('tables' => array(), 'columns' => array());
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return array('tables' => array('wpdb'), 'columns' => array());
        }
        foreach (zw_receipt_handle_tables() as $key => $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ((string) $found !== $table) {
                $missing['tables'][] = $key;
            }
        }
        if (function_exists('zw_receipt_source_columns')) {
            // OA-2467 热修：`zw_receipt_source_columns()` 返回的是**关联数组（列名 => true）**，
            // 不是列名列表 ⇒ 必须用 `isset($cols[$col])` 判存在；用 `in_array($col, $cols)` 比对的是
            // 值（全是 true）⇒ 恒判「缺列」，0.1.7 上「三卡 + 角标」因此整块不生效（生产实测）。
            $cols = (array) zw_receipt_source_columns($fresh);
            if ($cols) {
                foreach (array_keys(zw_receipt_handle_new_columns()) as $col) {
                    if (!isset($cols[$col])) {
                        $missing['columns'][] = $col;
                    }
                }
            } else {
                $missing['columns'] = array_keys(zw_receipt_handle_new_columns());
            }
        }
        return $missing;
    }
}

if (!function_exists('zw_receipt_handle_ready')) {
    /**
     * 新表与新增列是否就绪。
     *
     * @return bool
     */
    function zw_receipt_handle_ready() {
        $missing = zw_receipt_handle_missing();
        return $missing['tables'] === array() && $missing['columns'] === array();
    }
}

if (!function_exists('zw_receipt_handle_current_login')) {
    /**
     * 当前操作者登录名（无 WP 环境返回空串）。
     *
     * @return string
     */
    function zw_receipt_handle_current_login() {
        if (function_exists('zw_receipt_issue_current_user_login')) {
            return (string) zw_receipt_issue_current_user_login();
        }
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            return isset($user->user_login) ? (string) $user->user_login : '';
        }
        return '';
    }
}

if (!function_exists('zw_receipt_handle_display_name')) {
    /**
     * 展示名（登录名 → 显示名；取不到退回登录名）。
     *
     * @param string $login 登录名。
     * @return string
     */
    function zw_receipt_handle_display_name($login) {
        $login = trim((string) $login);
        if ($login === '') {
            return '';
        }
        // OA-2470：系统自动来源的中文显示名（不查用户表，也不冒充真人）。
        $system_labels = array(
            'system:auto-instruction' => '系统自动（批示库）',
            'system:auto-sign'        => '系统自动签阅',
        );
        if (isset($system_labels[$login])) {
            return $system_labels[$login];
        }
        if (function_exists('get_user_by')) {
            $user = get_user_by('login', $login);
            if ($user && isset($user->display_name) && (string) $user->display_name !== '') {
                return (string) $user->display_name;
            }
        }
        return $login;
    }
}

if (!function_exists('zw_receipt_handle_nonce')) {
    /**
     * 各动作的 nonce action（带记录 ID，防跨记录重放）。
     *
     * @param string $action    动作名。
     * @param string $receipt_id 记录 ID。
     * @return string
     */
    function zw_receipt_handle_nonce($action, $receipt_id) {
        return 'zw_receipt_' . $action . '_' . (string) $receipt_id;
    }
}

if (!function_exists('zw_receipt_handle_sanitize_id')) {
    /**
     * 收文记录 ID 白名单（与台账一致：字符串 `SRC-…`）。
     *
     * @param mixed $value 原值。
     * @return string 合法返回 trim 后字符串，否则空串。
     */
    function zw_receipt_handle_sanitize_id($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return '';
        }
        return $value;
    }
}

if (!function_exists('zw_receipt_handle_actions')) {
    /**
     * 动作白名单（展示名）。
     *
     * @return array<string,string>
     */
    function zw_receipt_handle_actions() {
        return array(
            'suggest'     => '拟办意见',
            'instruction' => '领导批示',
            'urge'        => '催办记录',
        );
    }
}

if (!function_exists('zw_receipt_handle_add')) {
    /**
     * 追加一条动作流水（只 INSERT）。
     *
     * @param array $raw 原始输入：receipt_id / action / content / dept_key。
     * @return int|WP_Error 新行 ID。
     */
    function zw_receipt_handle_add($raw, $extra_actions = array(), $system_login = '') {
        $raw = is_array($raw) ? $raw : array();
        if (!zw_receipt_handle_ready()) {
            return new WP_Error('zw_receipt_handle_not_ready', '办理留痕表尚未迁移（请先跑迁移脚本）。');
        }
        $receipt_id = zw_receipt_handle_sanitize_id(isset($raw['receipt_id']) ? $raw['receipt_id'] : '');
        $action     = isset($raw['action']) ? trim((string) $raw['action']) : '';
        $content    = isset($raw['content']) ? trim((string) $raw['content']) : '';
        if ($receipt_id === '') {
            return new WP_Error('zw_receipt_handle_bad_id', '收文记录 ID 非法。');
        }
        // OA-2468：内部调用可显式放行额外动作（如自动签阅 `sign`）；人工表单仍只允许三项。
        $allowed = zw_receipt_handle_actions();
        foreach ((array) $extra_actions as $one) {
            $one = trim((string) $one);
            if ($one !== '') {
                $allowed[$one] = $one;
            }
        }
        if (!isset($allowed[$action])) {
            return new WP_Error('zw_receipt_handle_bad_action', '动作类型非法（只允许 suggest / instruction / urge）。');
        }
        if ($content === '') {
            return new WP_Error('zw_receipt_handle_empty', '内容不能为空。');
        }
        if (function_exists('mb_substr')) {
            $content = mb_substr($content, 0, 5000);
        } else {
            $content = substr($content, 0, 15000);
        }

        $source = function_exists('zw_receipt_ledger_row_by_id') ? zw_receipt_ledger_row_by_id($receipt_id) : array();
        if (!$source) {
            return new WP_Error('zw_receipt_handle_no_receipt', '找不到该收文（或它不是 origin=incoming 的来文）。');
        }

        global $wpdb;
        $tables = zw_receipt_handle_tables();
        // OA-2468：系统自动写入用固定标识（`system:*`），**不假冒任何真人账号**。
        $login  = trim((string) $system_login) !== '' ? trim((string) $system_login) : zw_receipt_handle_current_login();
        $dept   = isset($raw['dept_key']) ? trim((string) $raw['dept_key']) : '';
        $now    = function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s');
        $ok     = $wpdb->insert($tables['log'], array(
            'receipt_id'   => $receipt_id,
            'action'       => $action,
            'author_login' => $login,
            'author_name'  => zw_receipt_handle_display_name($login),
            'dept_key'     => $dept,
            'content'      => $content,
            'created_at'   => $now,
        ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s'));
        if ($ok === false) {
            return new WP_Error('zw_receipt_handle_insert_failed', '写入失败：' . (string) $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }
}

if (!function_exists('zw_receipt_handle_list')) {
    /**
     * 取某收文的动作流水（按时间正序）。
     *
     * @param string $receipt_id 记录 ID。
     * @return array[]
     */
    function zw_receipt_handle_list($receipt_id) {
        $receipt_id = zw_receipt_handle_sanitize_id($receipt_id);
        if ($receipt_id === '' || !zw_receipt_handle_ready()) {
            return array();
        }
        global $wpdb;
        $tables = zw_receipt_handle_tables();
        $rows   = $wpdb->get_results($wpdb->prepare(
            "SELECT id, receipt_id, action, author_login, author_name, dept_key, content, created_at"
            . " FROM {$tables['log']} WHERE receipt_id = %s ORDER BY id ASC",
            $receipt_id
        ), 'ARRAY_A');
        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('zw_receipt_handle_pending_instruction')) {
    /**
     * 「待批示」收文数：有拟办、但还没有任何批示的来文（全局计数）。
     *
     * @return int
     */
    function zw_receipt_handle_pending_instruction() {
        if (!zw_receipt_handle_ready()) {
            return 0;
        }
        global $wpdb;
        $tables = zw_receipt_handle_tables();
        $count  = $wpdb->get_var(
            "SELECT COUNT(DISTINCT l.receipt_id) FROM {$tables['log']} l"
            . " WHERE l.action = 'suggest'"
            . " AND NOT EXISTS (SELECT 1 FROM {$tables['log']} i"
            . " WHERE i.receipt_id = l.receipt_id AND i.action = 'instruction')"
        );
        return max(0, (int) $count);
    }
}

if (!function_exists('zw_receipt_circ_targets')) {
    /**
     * 传阅对象类型白名单。
     *
     * @return array<string,string>
     */
    function zw_receipt_circ_targets() {
        return array('user' => '人员', 'dept' => '部门');
    }
}

if (!function_exists('zw_receipt_circ_add')) {
    /**
     * 追加一条传阅登记（同一记录 + 同一对象只登记一次）。
     *
     * @param array $raw receipt_id / target_type / target_id / note。
     * @return int|WP_Error 新行 ID（已存在时返回既有 ID）。
     */
    function zw_receipt_circ_add($raw) {
        $raw = is_array($raw) ? $raw : array();
        if (!zw_receipt_handle_ready()) {
            return new WP_Error('zw_receipt_handle_not_ready', '传阅表尚未迁移（请先跑迁移脚本）。');
        }
        $receipt_id  = zw_receipt_handle_sanitize_id(isset($raw['receipt_id']) ? $raw['receipt_id'] : '');
        $target_type = isset($raw['target_type']) ? trim((string) $raw['target_type']) : '';
        $target_id   = isset($raw['target_id']) ? trim((string) $raw['target_id']) : '';
        if ($receipt_id === '') {
            return new WP_Error('zw_receipt_handle_bad_id', '收文记录 ID 非法。');
        }
        if (!isset(zw_receipt_circ_targets()[$target_type])) {
            return new WP_Error('zw_receipt_circ_bad_type', '传阅对象类型非法（只允许 user / dept）。');
        }
        if ($target_id === '' || strlen($target_id) > 64 || !preg_match('/^[A-Za-z0-9_:.@\-]+$/', $target_id)) {
            return new WP_Error('zw_receipt_circ_bad_target', '传阅对象非法。');
        }
        if (function_exists('zw_receipt_ledger_row_by_id') && !zw_receipt_ledger_row_by_id($receipt_id)) {
            return new WP_Error('zw_receipt_handle_no_receipt', '找不到该收文（或它不是 origin=incoming 的来文）。');
        }

        global $wpdb;
        $tables = zw_receipt_handle_tables();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$tables['circ']} WHERE receipt_id = %s AND target_type = %s AND target_id = %s LIMIT 1",
            $receipt_id,
            $target_type,
            $target_id
        ));
        if ($exists !== null && $exists !== false && (int) $exists > 0) {
            return (int) $exists;
        }

        $now  = function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s');
        $note = isset($raw['note']) ? trim((string) $raw['note']) : '';
        if (function_exists('mb_substr')) {
            $note = mb_substr($note, 0, 250);
        } else {
            $note = substr($note, 0, 750);
        }
        $ok = $wpdb->insert($tables['circ'], array(
            'receipt_id'  => $receipt_id,
            'target_type' => $target_type,
            'target_id'   => $target_id,
            'note'        => $note,
            'created_by'  => zw_receipt_handle_current_login(),
            'created_at'  => $now,
        ), array('%s', '%s', '%s', '%s', '%s', '%s'));
        if ($ok === false) {
            return new WP_Error('zw_receipt_circ_insert_failed', '写入失败：' . (string) $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }
}

if (!function_exists('zw_receipt_circ_list')) {
    /**
     * 取某收文的传阅登记（按登记顺序）。
     *
     * @param string $receipt_id 记录 ID。
     * @return array[]
     */
    function zw_receipt_circ_list($receipt_id) {
        $receipt_id = zw_receipt_handle_sanitize_id($receipt_id);
        if ($receipt_id === '' || !zw_receipt_handle_ready()) {
            return array();
        }
        global $wpdb;
        $tables = zw_receipt_handle_tables();
        $rows   = $wpdb->get_results($wpdb->prepare(
            "SELECT id, receipt_id, target_type, target_id, read_at, note, created_by, created_at"
            . " FROM {$tables['circ']} WHERE receipt_id = %s ORDER BY id ASC",
            $receipt_id
        ), 'ARRAY_A');
        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('zw_receipt_circ_mark_read')) {
    /**
     * 签阅：**只** 写 `read_at` 一列（单行 UPDATE；已签阅不覆盖时间）。
     *
     * @param int $id 传阅行 ID。
     * @return bool
     */
    function zw_receipt_circ_mark_read($id) {
        $id = (int) $id;
        if ($id <= 0 || !zw_receipt_handle_ready()) {
            return false;
        }
        global $wpdb;
        $tables = zw_receipt_handle_tables();
        $now    = function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s');
        $rows   = $wpdb->query($wpdb->prepare(
            "UPDATE {$tables['circ']} SET read_at = %s WHERE id = %d AND read_at IS NULL",
            $now,
            $id
        ));
        return $rows !== false && (int) $rows === 1;
    }
}

if (!function_exists('zw_receipt_archive_save')) {
    /**
     * 保存归档信息（**只** 写 page_count / archive_box_no / archive_year 三列，单行）。
     *
     * @param array $raw receipt_id / page_count / archive_box_no / archive_year。
     * @return true|WP_Error
     */
    function zw_receipt_archive_save($raw) {
        $raw = is_array($raw) ? $raw : array();
        if (!zw_receipt_handle_ready()) {
            return new WP_Error('zw_receipt_handle_not_ready', '归档字段尚未迁移（请先跑迁移脚本）。');
        }
        $receipt_id = zw_receipt_handle_sanitize_id(isset($raw['receipt_id']) ? $raw['receipt_id'] : '');
        if ($receipt_id === '' || !zw_receipt_ledger_row_by_id($receipt_id)) {
            return new WP_Error('zw_receipt_handle_no_receipt', '找不到该收文（或它不是 origin=incoming 的来文）。');
        }
        $pages = isset($raw['page_count']) ? (int) $raw['page_count'] : 0;
        $pages = ($pages > 0 && $pages <= 65535) ? $pages : null;
        $year  = isset($raw['archive_year']) ? (int) $raw['archive_year'] : 0;
        $year  = ($year >= 1900 && $year <= 2999) ? $year : null;
        $box   = isset($raw['archive_box_no']) ? trim((string) $raw['archive_box_no']) : '';
        if (function_exists('mb_substr')) {
            $box = mb_substr($box, 0, 64);
        } else {
            $box = substr($box, 0, 192);
        }
        if ($box !== '' && !preg_match('/^[A-Za-z0-9_\-\.\x{4e00}-\x{9fa5}]+$/u', $box)) {
            return new WP_Error('zw_receipt_archive_bad_box', '归档盒号只允许中文/字母/数字/连字符（≤64 字）。');
        }

        global $wpdb;
        $table = zw_receipt_ledger_source_table();
        $ok    = $wpdb->update($table, array(
            'page_count'     => $pages,
            'archive_box_no' => $box !== '' ? $box : null,
            'archive_year'   => $year,
        ), array('id' => $receipt_id));
        if ($ok === false) {
            return new WP_Error('zw_receipt_archive_update_failed', '保存失败：' . (string) $wpdb->last_error);
        }
        return true;
    }
}

if (!function_exists('zw_receipt_handle_post_url')) {
    /**
     * 写入口地址（admin-post）。
     *
     * @return string
     */
    function zw_receipt_handle_post_url() {
        return function_exists('admin_url') ? (string) admin_url('admin-post.php') : 'admin-post.php';
    }
}

if (!function_exists('zw_receipt_handle_back_to_detail')) {
    /**
     * 收口后回详情页（带提示码）。
     *
     * @param string $receipt_id 记录 ID。
     * @param string $msg        提示码。
     * @return void
     */
    function zw_receipt_handle_back_to_detail($receipt_id, $msg) {
        $target = function_exists('zw_receipt_ledger_detail_url')
            ? zw_receipt_ledger_detail_url($receipt_id)
            : (function_exists('admin_url') ? admin_url('admin.php?page=zw-receipt-ledger') : '');
        $target = add_query_arg(array('zw_msg' => $msg), $target);
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($target);
        }
        exit;
    }
}

if (!function_exists('zw_receipt_handle_require_can')) {
    /**
     * 写入口统一鉴权（能力位 + nonce）。
     *
     * @param string $action    动作名（nonce 用）。
     * @param string $receipt_id 记录 ID。
     * @param string $field     nonce 字段名。
     * @return void 失败直接 wp_die。
     */
    function zw_receipt_handle_require_can($action, $receipt_id, $field) {
        if (function_exists('current_user_can') && !current_user_can('tcpc_perm_doc')) {
            wp_die('无权限操作收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
        }
        check_admin_referer(zw_receipt_handle_nonce($action, $receipt_id), $field);
    }
}

if (!function_exists('zw_receipt_handle_add_post')) {
    /**
     * admin-post：追加拟办 / 批示 / 催办。
     *
     * @return void
     */
    function zw_receipt_handle_add_post() {
        $receipt_id = zw_receipt_handle_sanitize_id(isset($_POST['receipt_id']) ? wp_unslash($_POST['receipt_id']) : '');
        if ($receipt_id === '') {
            wp_die('收文记录 ID 非法。', '参数错误', array('response' => 400));
        }
        zw_receipt_handle_require_can('handle_add', $receipt_id, 'zw_receipt_handle_nonce');
        $result = zw_receipt_handle_add(array(
            'receipt_id' => $receipt_id,
            'action'     => isset($_POST['handle_action']) ? wp_unslash($_POST['handle_action']) : '',
            'content'    => isset($_POST['content']) ? wp_unslash($_POST['content']) : '',
            'dept_key'   => isset($_POST['dept_key']) ? wp_unslash($_POST['dept_key']) : '',
        ));
        zw_receipt_handle_back_to_detail(
            $receipt_id,
            is_wp_error($result) ? 'handle_failed' : 'handle_added'
        );
    }
}

if (!function_exists('zw_receipt_circ_add_post')) {
    /**
     * admin-post：登记传阅。
     *
     * @return void
     */
    function zw_receipt_circ_add_post() {
        $receipt_id = zw_receipt_handle_sanitize_id(isset($_POST['receipt_id']) ? wp_unslash($_POST['receipt_id']) : '');
        if ($receipt_id === '') {
            wp_die('收文记录 ID 非法。', '参数错误', array('response' => 400));
        }
        zw_receipt_handle_require_can('circ_add', $receipt_id, 'zw_receipt_circ_nonce');
        $result = zw_receipt_circ_add(array(
            'receipt_id'  => $receipt_id,
            'target_type' => isset($_POST['target_type']) ? wp_unslash($_POST['target_type']) : '',
            'target_id'   => isset($_POST['target_id']) ? wp_unslash($_POST['target_id']) : '',
            'note'        => isset($_POST['note']) ? wp_unslash($_POST['note']) : '',
        ));
        zw_receipt_handle_back_to_detail(
            $receipt_id,
            is_wp_error($result) ? 'circ_failed' : 'circ_added'
        );
    }
}

if (!function_exists('zw_receipt_circ_read_post')) {
    /**
     * admin-post：签阅（只写 read_at）。
     *
     * @return void
     */
    function zw_receipt_circ_read_post() {
        $receipt_id = zw_receipt_handle_sanitize_id(isset($_POST['receipt_id']) ? wp_unslash($_POST['receipt_id']) : '');
        $circ_id    = isset($_POST['circ_id']) ? (int) $_POST['circ_id'] : 0;
        if ($receipt_id === '' || $circ_id <= 0) {
            wp_die('参数非法。', '参数错误', array('response' => 400));
        }
        zw_receipt_handle_require_can('circ_read', $receipt_id, 'zw_receipt_circ_nonce');
        zw_receipt_handle_back_to_detail($receipt_id, zw_receipt_circ_mark_read($circ_id) ? 'circ_read' : 'circ_read_failed');
    }
}

if (!function_exists('zw_receipt_archive_post')) {
    /**
     * admin-post：保存归档盒号 / 年度 / 页数。
     *
     * @return void
     */
    function zw_receipt_archive_post() {
        $receipt_id = zw_receipt_handle_sanitize_id(isset($_POST['receipt_id']) ? wp_unslash($_POST['receipt_id']) : '');
        if ($receipt_id === '') {
            wp_die('收文记录 ID 非法。', '参数错误', array('response' => 400));
        }
        zw_receipt_handle_require_can('archive', $receipt_id, 'zw_receipt_archive_nonce');
        $result = zw_receipt_archive_save(array(
            'receipt_id'     => $receipt_id,
            'page_count'     => isset($_POST['page_count']) ? wp_unslash($_POST['page_count']) : 0,
            'archive_box_no' => isset($_POST['archive_box_no']) ? wp_unslash($_POST['archive_box_no']) : '',
            'archive_year'   => isset($_POST['archive_year']) ? wp_unslash($_POST['archive_year']) : 0,
        ));
        zw_receipt_handle_back_to_detail($receipt_id, is_wp_error($result) ? 'archive_failed' : 'archive_saved');
    }
}

if (!function_exists('zw_receipt_handle_register_hooks')) {
    /**
     * 注册 admin-post 入口（只在模块启用时由 bootstrap 调用）。
     *
     * @return void
     */
    function zw_receipt_handle_register_hooks() {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('admin_post_zw_receipt_handle_add', 'zw_receipt_handle_add_post');
        add_action('admin_post_zw_receipt_circ_add', 'zw_receipt_circ_add_post');
        add_action('admin_post_zw_receipt_circ_read', 'zw_receipt_circ_read_post');
        add_action('admin_post_zw_receipt_archive', 'zw_receipt_archive_post');
    }
}

if (function_exists('add_action')) {
    zw_receipt_handle_register_hooks();
}

if (!function_exists('zw_receipt_handle_archive_info')) {
    /**
     * 取归档信息（独立小查询：不改台账主查询 SQL —— 那是契约面与断言面）。
     *
     * @param string $receipt_id 记录 ID。
     * @return array{page_count:int,archive_box_no:string,archive_year:int}
     */
    function zw_receipt_handle_archive_info($receipt_id) {
        $receipt_id = zw_receipt_handle_sanitize_id($receipt_id);
        $empty = array('page_count' => 0, 'archive_box_no' => '', 'archive_year' => 0);
        $missing = zw_receipt_handle_missing();
        if ($receipt_id === '' || $missing['columns'] !== array() || !function_exists('zw_receipt_ledger_source_table')) {
            return $empty;
        }
        global $wpdb;
        $table = zw_receipt_ledger_source_table();
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT page_count, archive_box_no, archive_year FROM {$table} WHERE id = %s AND origin = 'incoming' LIMIT 1",
            $receipt_id
        ), 'ARRAY_A');
        if (!is_array($row)) {
            return $empty;
        }
        return array(
            'page_count'     => isset($row['page_count']) ? (int) $row['page_count'] : 0,
            'archive_box_no' => isset($row['archive_box_no']) ? (string) $row['archive_box_no'] : '',
            'archive_year'   => isset($row['archive_year']) ? (int) $row['archive_year'] : 0,
        );
    }
}

if (!function_exists('zw_receipt_handle_notice_map')) {
    /**
     * 提示码 → 文案（详情页卡片内展示）。
     *
     * @return array<string,array{0:string,1:string}>
     */
    function zw_receipt_handle_notice_map() {
        return array(
            'handle_added'    => array('success', '已追加办理留痕。'),
            'handle_failed'   => array('error', '追加失败：内容不能为空，或办理留痕表未迁移。'),
            'circ_added'      => array('success', '传阅登记成功。'),
            'circ_failed'     => array('error', '传阅登记失败：请检查对象类型与对象标识。'),
            'circ_read'       => array('success', '已签阅。'),
            'circ_read_failed' => array('warning', '签阅未生效：该条已签阅，或记录不存在。'),
            'archive_saved'   => array('success', '归档信息已保存。'),
            'archive_failed'  => array('error', '归档信息保存失败：请检查盒号字符与年度范围。'),
        );
    }
}

if (!function_exists('zw_receipt_handle_render_cards')) {
    /**
     * 详情页「拟办与批示 / 传阅与签阅 / 归档」三张卡片（OA-2464）。
     *
     * 只做展示与表单；所有写入走 admin-post（`receipt-handle.php` 的 handler）。
     *
     * @param string $receipt_id 收文记录 ID。
     * @return void
     */
    function zw_receipt_handle_render_cards($receipt_id) {
        $receipt_id = zw_receipt_handle_sanitize_id($receipt_id);
        if ($receipt_id === '' || !function_exists('esc_html')) {
            return;
        }
        $missing = zw_receipt_handle_missing();
        // OA-2468：展示用动作名（含内部动作 `sign`；人工表单仍只给三项）。
        $actions = zw_receipt_handle_actions();
        $actions['sign']     = '自动签阅';
        $actions['dispatch'] = '自动派单';
        $msg     = isset($_GET['zw_msg']) ? sanitize_key((string) wp_unslash($_GET['zw_msg'])) : '';
        $notices = zw_receipt_handle_notice_map();

        if ($missing['tables'] !== array() || $missing['columns'] !== array()) {
            echo '<div class="tco-card" style="margin:0 0 16px"><h2>拟办 / 批示 / 传阅 / 归档</h2>';
            echo '<div class="notice notice-warning inline"><p>相关表或列尚未迁移：请执行 '
                . '<code>wp eval-file scripts/migrate-receipt-handle.php execute</code> 后再用。'
                . '（缺表：' . esc_html(implode('、', $missing['tables']) ?: '无')
                . '；缺列：' . esc_html(implode('、', $missing['columns']) ?: '无') . '）</p></div>';
            echo '</div>';
            return;
        }

        $post_url = zw_receipt_handle_post_url();
        $logs     = zw_receipt_handle_list($receipt_id);
        $circ     = zw_receipt_circ_list($receipt_id);
        $archive  = zw_receipt_handle_archive_info($receipt_id);
        $pending  = zw_receipt_handle_pending_instruction();

        // ————— 卡片 A：拟办 / 批示 / 催办（append-only 时间线）—————
        echo '<div class="tco-card" style="margin:0 0 16px">';
        echo '<h2>拟办与批示</h2>';
        if (isset($notices[$msg])) {
            echo '<div class="notice notice-' . esc_attr($notices[$msg][0]) . ' inline"><p>'
                . esc_html($notices[$msg][1]) . '</p></div>';
        }
        // OA-2468：签名时间戳（系统自动签阅那一条的行时间）。
        $signed_at = '';
        foreach ($logs as $one_log) {
            if (isset($one_log['action']) && (string) $one_log['action'] === 'sign') {
                $signed_at = isset($one_log['created_at']) ? (string) $one_log['created_at'] : '';
                break;
            }
        }
        echo '<p class="tco-doc-muted">拟办 / 批示 / 催办 / 签阅一律**追加留痕**（只增不改）；'
            . '它们不改变办理状态机（状态仍在「收文办理」里维护）。'
            . ' 签名时间戳：<strong>' . ($signed_at !== '' ? esc_html($signed_at) : '未签阅') . '</strong></p>';

        if (!$logs) {
            echo '<p>还没有拟办：登记来文后，先由办公室填「拟办意见」，再走领导批示。</p>';
        } else {
            echo '<div class="tco-table-wrap"><table class="widefat striped"><thead><tr>'
                . '<th style="width:100px">类型</th><th style="width:120px">人</th>'
                . '<th style="width:150px">时间</th><th>内容</th>'
                . '</tr></thead><tbody>';
            foreach ($logs as $log) {
                $action   = isset($log['action']) ? (string) $log['action'] : '';
                $login    = isset($log['author_login']) ? (string) $log['author_login'] : '';
                $is_auto  = (strpos($login, 'system:') === 0);
                $who      = isset($log['author_name']) && $log['author_name'] !== '' ? (string) $log['author_name'] : $login;
                echo '<tr>';
                echo '<td>' . esc_html(isset($actions[$action]) ? $actions[$action] : $action) . '</td>';
                echo '<td>' . esc_html($who);
                if ($is_auto) {
                    echo ' <span class="tco-badge" style="margin-left:6px">系统自动</span>';
                }
                echo '</td>';
                echo '<td>' . esc_html((string) (isset($log['created_at']) ? $log['created_at'] : '')) . '</td>';
                echo '<td style="white-space:pre-wrap">' . esc_html((string) (isset($log['content']) ? $log['content'] : '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<form method="post" action="' . esc_url($post_url) . '" style="margin:14px 0 0">';
        echo '<input type="hidden" name="action" value="zw_receipt_handle_add">';
        echo '<input type="hidden" name="receipt_id" value="' . esc_attr($receipt_id) . '">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(zw_receipt_handle_nonce('handle_add', $receipt_id), 'zw_receipt_handle_nonce');
        }
        echo '<div class="tco-grid is-2">';
        echo '<div class="tco-field"><label for="zw-rh-action">类型</label><select id="zw-rh-action" name="handle_action">';
        foreach ($actions as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="tco-field"><label for="zw-rh-content">内容 <span style="color:#b30404">*</span></label>'
            . '<textarea id="zw-rh-content" name="content" rows="3" required placeholder="拟办：请××处阅办；批示：同意，请按拟办意见办理。"></textarea></div>';
        echo '</div>';
        echo '<button type="submit" class="button button-primary">追加留痕</button>';
        echo '</form>';
        echo '</div>';

        // ————— 卡片 B：传阅与签阅 —————
        echo '<div class="tco-card" style="margin:0 0 16px">';
        echo '<h2>传阅与签阅</h2>';
        echo '<p class="tco-doc-muted">一人一行（同一对象只登记一次）；「签阅」只写签阅时间，不改登记内容。'
            . '待批示收文：<strong>' . (int) $pending . '</strong> 件（有拟办、无批示）。</p>';
        if (!$circ) {
            echo '<p>还没有传阅登记：可在下方把这份来文传阅给相关人员或部门。</p>';
        } else {
            echo '<div class="tco-table-wrap"><table class="widefat striped"><thead><tr>'
                . '<th style="width:90px">类型</th><th style="width:180px">对象</th>'
                . '<th style="width:150px">登记时间</th><th style="width:150px">签阅时间</th>'
                . '<th>备注</th><th style="width:100px">操作</th>'
                . '</tr></thead><tbody>';
            foreach ($circ as $row) {
                $type = isset($row['target_type']) ? (string) $row['target_type'] : '';
                echo '<tr>';
                echo '<td>' . esc_html(isset(zw_receipt_circ_targets()[$type]) ? zw_receipt_circ_targets()[$type] : $type) . '</td>';
                echo '<td>' . esc_html((string) (isset($row['target_id']) ? $row['target_id'] : '')) . '</td>';
                echo '<td>' . esc_html((string) (isset($row['created_at']) ? $row['created_at'] : '')) . '</td>';
                echo '<td>' . esc_html((string) (isset($row['read_at']) && $row['read_at'] !== null ? $row['read_at'] : '未签阅')) . '</td>';
                echo '<td>' . esc_html((string) (isset($row['note']) ? $row['note'] : '')) . '</td>';
                echo '<td>';
                if (empty($row['read_at'])) {
                    echo '<form method="post" action="' . esc_url($post_url) . '" style="margin:0">';
                    echo '<input type="hidden" name="action" value="zw_receipt_circ_read">';
                    echo '<input type="hidden" name="receipt_id" value="' . esc_attr($receipt_id) . '">';
                    echo '<input type="hidden" name="circ_id" value="' . (int) (isset($row['id']) ? $row['id'] : 0) . '">';
                    if (function_exists('wp_nonce_field')) {
                        wp_nonce_field(zw_receipt_handle_nonce('circ_read', $receipt_id), 'zw_receipt_circ_nonce');
                    }
                    echo '<button type="submit" class="button button-small">签阅</button>';
                    echo '</form>';
                } else {
                    echo '已签阅';
                }
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<form method="post" action="' . esc_url($post_url) . '" style="margin:14px 0 0">';
        echo '<input type="hidden" name="action" value="zw_receipt_circ_add">';
        echo '<input type="hidden" name="receipt_id" value="' . esc_attr($receipt_id) . '">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(zw_receipt_handle_nonce('circ_add', $receipt_id), 'zw_receipt_circ_nonce');
        }
        echo '<div class="tco-grid is-2">';
        echo '<div class="tco-field"><label for="zw-rc-type">对象类型</label><select id="zw-rc-type" name="target_type">';
        foreach (zw_receipt_circ_targets() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="tco-field"><label for="zw-rc-target">对象标识 <span style="color:#b30404">*</span></label>'
            . '<input type="text" id="zw-rc-target" name="target_id" required placeholder="人员填登录名；部门填部门 key">'
            . '<p class="description">人员：WordPress 登录名；部门：组织架构部门 key（如 <code>office</code>）。</p></div>';
        echo '<div class="tco-field"><label for="zw-rc-note">备注</label>'
            . '<input type="text" id="zw-rc-note" name="note" maxlength="250"></div>';
        echo '</div>';
        echo '<button type="submit" class="button button-primary">登记传阅</button>';
        echo '</form>';
        echo '</div>';

        // ————— 卡片 C：归档（页数 / 盒号 / 年度）—————
        echo '<div class="tco-card" style="margin:0 0 16px">';
        echo '<h2>归档信息</h2>';
        echo '<p class="tco-doc-muted">归档盒号 / 年度是**纸质归档**标识，与私有存储目录（部委/年份）互不影响；'
            . '页数用于纸质件核对。</p>';
        echo '<form method="post" action="' . esc_url($post_url) . '">';
        echo '<input type="hidden" name="action" value="zw_receipt_archive">';
        echo '<input type="hidden" name="receipt_id" value="' . esc_attr($receipt_id) . '">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(zw_receipt_handle_nonce('archive', $receipt_id), 'zw_receipt_archive_nonce');
        }
        echo '<div class="tco-grid is-2">';
        echo '<div class="tco-field"><label for="zw-ra-box">归档盒号</label>'
            . '<input type="text" id="zw-ra-box" name="archive_box_no" maxlength="64" value="'
            . esc_attr($archive['archive_box_no']) . '" placeholder="如 收-2026-03"></div>';
        echo '<div class="tco-field"><label for="zw-ra-year">归档年度</label>'
            . '<input type="number" id="zw-ra-year" name="archive_year" min="1900" max="2999" value="'
            . ($archive['archive_year'] > 0 ? (int) $archive['archive_year'] : '') . '" placeholder="如 2026"></div>';
        echo '<div class="tco-field"><label for="zw-ra-pages">页数</label>'
            . '<input type="number" id="zw-ra-pages" name="page_count" min="1" max="65535" value="'
            . ($archive['page_count'] > 0 ? (int) $archive['page_count'] : '') . '"></div>';
        echo '</div>';
        echo '<button type="submit" class="button button-primary">保存归档信息</button>';
        echo '</form>';
        echo '</div>';
    }
}
