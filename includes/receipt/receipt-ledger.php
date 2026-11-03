<?php
/**
 * 收文管理 · 收文台账与部委/年份目录视图（TASK-1297 · R4）。
 *
 * 口径出处：
 * - `docs/research/TASK-1297-收文管理-需求对齐稿-20261008.md` §3 US-02（按部委/年份归档）
 *   / US-07（收文视图与政策依据视图**分流**：收文视图仅显示 `origin='incoming'`）
 *   / US-08（权限与可见性）；
 * - `docs/architecture/TASK-1297-收文管理-表结构变更与迁移回滚方案-20261008.md`
 *   §2.1（复用 `zw_policy_source`）/ §2.5（部委|年份目录）/ §5（不变式 I2 单一政策库、
 *   I4 缺列返回 0 行绝不退化为全表、I5 自产 4 id 恒为 `self`）
 *   / §6.2（缺列降级链）/ §补充 A.1（前缀 `zw_receipt_*`、slug `zw-receipt-ledger` / `zw-receipt-orgs`）。
 *
 * 铁律：
 * - 本文件**只读**（零 INSERT / UPDATE / DELETE）；不触碰 `zw_policy_document_version` 与 `zw_doc_numbers`；
 * - 台账与目录视图**共用同一个 WHERE 构造器**，查询条件**必须显式** `origin = 'incoming'`；
 * - 迁移未跑（缺列）→ **返回 0 行且不发起任何 SQL 查询**（绝不退化为全表扫描）；
 * - 所有用户输入一律经白名单归一 + `$wpdb->prepare` 参数化（禁止拼接）；
 * - 自产 4 个白名单 id（`SRC-CHARTER` / `SRC-BRANCH-RULES` / `SRC-NDA-BRANCH` / `SRC-PRIVACY-POLICY`）
 *   在台账与目录视图中**恒零出现**（origin 过滤 + id 反向白名单双重保险，铁律 I5）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

// 依赖（standalone / CLI 加载时自动补齐；bootstrap 的开启分支按 receipt-create → receipt-ledger 顺序加载）。
if (!function_exists('zw_receipt_is_enabled')) {
    require_once __DIR__ . '/receipt-helpers.php';
}
if (!function_exists('zw_receipt_org_options')) {
    require_once __DIR__ . '/receipt-orgs.php';
}
if (!function_exists('zw_receipt_storage_relative_dir')) {
    require_once __DIR__ . '/receipt-storage.php';
}
if (!function_exists('zw_receipt_source_columns') && file_exists(__DIR__ . '/receipt-create.php')) {
    require_once __DIR__ . '/receipt-create.php';
}

/* ═════════════════════════════ 表/列探测与缺列短路 ═════════════════════════════ */

if (!function_exists('zw_receipt_ledger_array_a')) {
    /**
     * `ARRAY_A` 常量（standalone / CLI 下的退化值）。
     *
     * @return string
     */
    function zw_receipt_ledger_array_a() {
        return defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
    }
}

if (!function_exists('zw_receipt_ledger_source_table')) {
    /**
     * 政策源表名（**唯一政策库**，铁律 I2）。
     *
     * 复用 R3 canonical `zw_receipt_source_table()`；缺失时本地退化（不新起第二套表名口径）。
     *
     * @return string
     */
    function zw_receipt_ledger_source_table() {
        if (function_exists('zw_receipt_source_table')) {
            return (string) zw_receipt_source_table();
        }
        global $wpdb;
        $prefix = (is_object($wpdb) && isset($wpdb->prefix)) ? (string) $wpdb->prefix : 'wp_';
        return $prefix . 'zw_policy_source';
    }
}

if (!function_exists('zw_receipt_ledger_source_columns')) {
    /**
     * 政策源表既有列（`SHOW COLUMNS`；优先复用 R3 canonical 缓存）。
     *
     * 表不存在 / 无 DB 连接 → 返回空数组 ⇒ 上层判为「缺列」并返回 0 行。
     *
     * @param bool $fresh 是否强制刷新缓存。
     * @return array<string,bool>
     */
    function zw_receipt_ledger_source_columns($fresh = false) {
        if (function_exists('zw_receipt_source_columns')) {
            return (array) zw_receipt_source_columns($fresh);
        }
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
        $table = zw_receipt_ledger_source_table();
        $rows  = $wpdb->get_results(zw_receipt_prepare_sql("SHOW COLUMNS FROM {$table}", array()), zw_receipt_ledger_array_a());
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

if (!function_exists('zw_receipt_ledger_required_columns')) {
    /**
     * 台账/目录视图 SELECT 所引用的列（任一缺失即判「迁移未跑」→ 返回 0 行）。
     *
     * @return string[]
     */
    function zw_receipt_ledger_required_columns() {
        return array(
            'id',
            'doc_name',
            'issuer',
            'doc_no',
            'confidentiality_level',
            'source_file',
            'create_time',
            'origin',
            'doc_received_date',
            'doc_received_no',
            'copy_count',
        );
    }
}

if (!function_exists('zw_receipt_ledger_missing_columns')) {
    /**
     * 缺失的必需列（非空 ⇒ 迁移未跑）。
     *
     * @return string[]
     */
    function zw_receipt_ledger_missing_columns() {
        $columns = zw_receipt_ledger_source_columns();
        $missing = array();
        foreach (zw_receipt_ledger_required_columns() as $column) {
            if (!isset($columns[$column])) {
                $missing[] = $column;
            }
        }
        return $missing;
    }
}

if (!function_exists('zw_receipt_ledger_schema_ready')) {
    /**
     * 台账/目录视图是否可用（迁移已跑）。
     *
     * @return bool
     */
    function zw_receipt_ledger_schema_ready() {
        return !zw_receipt_ledger_missing_columns();
    }
}

/* ═════════════════════════════ 输入归一（防注入第一层） ═════════════════════════════ */

if (!function_exists('zw_receipt_ledger_text')) {
    /**
     * 单行文本净化（`sanitize_text_field(wp_unslash())` 口径；无 WP 时退化）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_ledger_text($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = (string) $value;
        if (function_exists('wp_unslash')) {
            $value = (string) wp_unslash($value);
        }
        if (function_exists('sanitize_text_field')) {
            $value = (string) sanitize_text_field($value);
        } else {
            $value = trim(strip_tags($value));
        }
        $value = (string) str_replace(array("\0", "\r", "\n", "\t"), ' ', $value);
        $value = trim($value);
        if (function_exists('zw_receipt_create_truncate')) {
            $value = (string) zw_receipt_create_truncate($value, 100);
        } elseif (function_exists('zw_receipt_storage_title_excerpt')) {
            $value = (string) zw_receipt_storage_title_excerpt($value, 100);
        }
        return $value;
    }
}

if (!function_exists('zw_receipt_ledger_absint')) {
    /**
     * 非负整数归一（数组/对象/非数值一律 0，绝不触发 Warning）。
     *
     * @param mixed $value 原始值。
     * @return int
     */
    function zw_receipt_ledger_absint($value) {
        if (is_array($value) || is_object($value)) {
            return 0;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !preg_match('/^-?\d+/', $value, $matches)) {
                return 0;
            }
            $value = (int) $matches[0];
        }
        $value = (int) $value;
        return ($value < 0) ? 0 : $value;
    }
}

if (!function_exists('zw_receipt_ledger_default_per_page')) {
    /**
     * 默认每页条数。
     *
     * @return int
     */
    function zw_receipt_ledger_default_per_page() {
        return 20;
    }
}

if (!function_exists('zw_receipt_ledger_max_per_page')) {
    /**
     * 每页条数上限（防大页拖库）。
     *
     * @return int
     */
    function zw_receipt_ledger_max_per_page() {
        return 100;
    }
}

if (!function_exists('zw_receipt_ledger_normalize_year')) {
    /**
     * 年份筛选归一：**严格 4 位数字**且在 `zw_receipt_normalize_year()` 合法区间内，否则视为未筛选。
     *
     * 严格口径的意义：`2026; DROP TABLE …` 这类输入不会被 `(int)` 吃掉前 4 位而变成有效筛选。
     *
     * @param mixed $value 原始值。
     * @return int|string 合法返回 int，非法返回空串。
     */
    function zw_receipt_ledger_normalize_year($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = trim((string) $value);
        if ($value === '' || !preg_match('/^\d{4}$/', $value)) {
            return '';
        }
        $year = (int) $value;
        if ($year < 1900 || $year > 2200) {
            return '';
        }
        return $year;
    }
}

if (!function_exists('zw_receipt_ledger_sanitize_id')) {
    /**
     * 政策源 id 的字面量净化（**保留大小写**：存量 id 形如 `SRC-CHARTER`）。
     *
     * 仅用于本文件硬编码白名单 id 的二次保险，不接受用户输入。
     *
     * @param mixed $id 原始 id。
     * @return string
     */
    function zw_receipt_ledger_sanitize_id($id) {
        return (string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $id);
    }
}

if (!function_exists('zw_receipt_ledger_normalize_filters')) {
    /**
     * 台账/目录筛选条件归一（唯一入口；后续一律走 `prepare` 参数化）。
     *
     * @param array $args 原始筛选（org_key / year / kw / page / per_page）。
     * @return array
     */
    function zw_receipt_ledger_normalize_filters($args) {
        $args = is_array($args) ? $args : array();

        $org_key = isset($args['org_key']) ? zw_receipt_org_sanitize_key($args['org_key']) : '';
        // 幂等：既能吃原始请求键 `kw`，也能吃本函数已归一后的 `keyword`（`where()` 复用同一入口）。
        if (isset($args['kw'])) {
            $keyword = zw_receipt_ledger_text($args['kw']);
        } elseif (isset($args['keyword'])) {
            $keyword = zw_receipt_ledger_text($args['keyword']);
        } else {
            $keyword = '';
        }
        $year    = isset($args['year']) ? zw_receipt_ledger_normalize_year($args['year']) : '';

        $page = isset($args['page']) ? zw_receipt_ledger_absint($args['page']) : 0;
        if ($page < 1) {
            $page = 1;
        }
        $per_page = isset($args['per_page']) ? zw_receipt_ledger_absint($args['per_page']) : 0;
        if ($per_page < 1) {
            $per_page = zw_receipt_ledger_default_per_page();
        }
        $per_page = min($per_page, zw_receipt_ledger_max_per_page());

        return array(
            'org_key'  => $org_key,
            'year'     => $year,
            'keyword'  => $keyword,
            'page'     => $page,
            'per_page' => $per_page,
        );
    }
}

if (!function_exists('zw_receipt_ledger_self_protected_ids')) {
    /**
     * 自产 4 id 反向白名单（铁律 I5；架构稿 §3.2 / §5）。
     *
     * `origin='self'` 已是主防线；本白名单是**第二重保险**：即便某行被误标 `incoming`，
     * 这 4 个已拍板的自产 id 也**不得**出现在收文台账/目录视图中。
     *
     * @return string[]
     */
    function zw_receipt_ledger_self_protected_ids() {
        return array(
            'SRC-CHARTER',
            'SRC-BRANCH-RULES',
            'SRC-NDA-BRANCH',
            'SRC-PRIVACY-POLICY',
        );
    }
}

if (!function_exists('zw_receipt_ledger_where')) {
    /**
     * WHERE 构造器（台账与目录视图**共用**，保证「只查 incoming」口径唯一）。
     *
     * 产出 `sql` 只含**固定字面量**与 `%s`/`%d` 占位符；全部用户输入进 `params`。
     *
     * @param array $filters 归一后的筛选（或原始筛选）。
     * @return array array('sql'=>string,'params'=>array,'filters'=>array)
     */
    function zw_receipt_ledger_where($filters) {
        $filters = zw_receipt_ledger_normalize_filters($filters);

        // ① 铁律 I4/US-07：收文视图必须显式 origin='incoming'（缺列已在上层短路，不会走到这里）。
        $clauses = array('origin = %s');
        $params  = array(zw_receipt_origin_incoming());

        // ② 铁律 I5 第二重保险：自产 4 id 反向白名单（字面量，来自代码常量，不来自用户输入）。
        $protected = array();
        foreach (zw_receipt_ledger_self_protected_ids() as $protected_id) {
            $protected_id = zw_receipt_ledger_sanitize_id($protected_id);
            if ($protected_id !== '') {
                $protected[] = "'" . $protected_id . "'";
            }
        }
        if ($protected) {
            $clauses[] = 'id NOT IN (' . implode(', ', $protected) . ')';
        }

        // ③ 来文单位（字典 key）
        if ($filters['org_key'] !== '') {
            $clauses[] = 'issuer = %s';
            $params[]  = $filters['org_key'];
        }

        // ④ 关键词（标题 / 来文字号 / 收文编号）
        if ($filters['keyword'] !== '') {
            $clauses[] = '(doc_name LIKE %s OR doc_no LIKE %s OR doc_received_no LIKE %s)';
            $like      = '%' . $filters['keyword'] . '%';
            $params[]  = $like;
            $params[]  = $like;
            $params[]  = $like;
        }

        // ⑤ 年份（取 `doc_received_date` 前 4 位；兼容 `2026-08-01` / `20260801` / `2026年8月1日` 等写法）
        if ($filters['year'] !== '' && $filters['year'] !== false) {
            $clauses[] = 'doc_received_date LIKE %s';
            $params[]  = ((int) $filters['year']) . '%';
        }

        return array(
            'sql'     => implode(' AND ', $clauses),
            'params'  => $params,
            'filters' => $filters,
        );
    }
}

/* ═════════════════════════════ 台账查询（分页） ═════════════════════════════ */

if (!function_exists('zw_receipt_ledger_query')) {
    /**
     * 收文台账查询（只读；唯一入口）。
     *
     * 缺列（迁移未跑）/ DB 不可用 → 返回空结果且**零 SQL**（铁律 I4）。
     *
     * @param array $args 原始筛选（org_key / year / kw / page / per_page）。
     * @return array array(
     *     rows, total, page, per_page, pages, schema_ready, missing, filters, sql, params
     * )
     */
    function zw_receipt_ledger_query($args = array()) {
        $filters = zw_receipt_ledger_normalize_filters($args);
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

        // ① 缺列短路：迁移未跑 → 0 行，且**不发起任何查询**。
        $missing = zw_receipt_ledger_missing_columns();
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

        $table = zw_receipt_ledger_source_table();
        $where = zw_receipt_ledger_where($filters);
        $result['sql']    = $where['sql'];
        $result['params'] = $where['params'];

        // ② 总数（同一 WHERE，保证分页与过滤口径一致）
        $count = $wpdb->get_var(zw_receipt_prepare_sql(
            "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}",
            $where['params']
        ));
        $result['total'] = max(0, (int) $count);
        $result['pages'] = ($result['total'] > 0)
            ? (int) ceil($result['total'] / max(1, $result['per_page']))
            : 0;

        // ③ 页数据（LIMIT/OFFSET 亦走 prepare；页码越界返回空页，不回卷到第 1 页）
        // 排序口径：先按「收文日期可解析为 4 位年份」分组（有效日期在前、乱码/空值沉底，
        // 与 `zw_receipt_storage_year_from_date()` 的 4 位前缀规则一致），再按日期与 id 降序。
        $offset = ($result['page'] - 1) * $result['per_page'];
        $sql    = "SELECT id, doc_name, issuer, doc_no, confidentiality_level, source_file, create_time,"
            . " origin, doc_received_date, doc_received_no, copy_count"
            . " FROM {$table} WHERE {$where['sql']}"
            . " ORDER BY (doc_received_date REGEXP '^[0-9]{4}') DESC, doc_received_date DESC, id DESC"
            . " LIMIT %d OFFSET %d";
        $params   = array_merge($where['params'], array((int) $result['per_page'], (int) $offset));
        $rows     = $wpdb->get_results(zw_receipt_prepare_sql($sql, $params), zw_receipt_ledger_array_a());

        $result['rows'] = is_array($rows) ? array_values($rows) : array();
        return $result;
    }
}

if (!function_exists('zw_receipt_ledger_org_key_from_issuer')) {
    /**
     * `issuer` → 部委 key（兼容存量把**显示名**写进 `issuer` 的迁移行）。
     *
     * 优先当 key 认；否则按字典显示名精确反查；都不认 → 兜底 key（`other`）。
     *
     * @param mixed $issuer `zw_policy_source.issuer`。
     * @return string
     */
    function zw_receipt_ledger_org_key_from_issuer($issuer) {
        $issuer = trim((string) $issuer);
        if ($issuer === '') {
            return zw_receipt_org_fallback_key();
        }
        $key = zw_receipt_org_sanitize_key($issuer);
        if ($key !== '' && zw_receipt_org_exists($key)) {
            return $key;
        }
        foreach (zw_receipt_org_options() as $option_key => $label) {
            if ($label !== '' && $label === $issuer) {
                return $option_key;
            }
        }
        return zw_receipt_org_fallback_key();
    }
}

if (!function_exists('zw_receipt_ledger_display_org')) {
    /**
     * `issuer` → 展示名（复用 R3 canonical `zw_receipt_display_issuer()`；缺失时本地等价实现）。
     *
     * @param mixed $issuer `zw_policy_source.issuer`。
     * @return string
     */
    function zw_receipt_ledger_display_org($issuer) {
        if (function_exists('zw_receipt_display_issuer')) {
            return (string) zw_receipt_display_issuer($issuer);
        }
        $issuer = trim((string) $issuer);
        if ($issuer === '') {
            return '';
        }
        return zw_receipt_org_label(zw_receipt_ledger_org_key_from_issuer($issuer)) !== ''
            ? zw_receipt_org_label(zw_receipt_ledger_org_key_from_issuer($issuer))
            : $issuer;
    }
}

if (!function_exists('zw_receipt_ledger_received_year')) {
    /**
     * 行内收文年份（非法/缺失 → 空串）。
     *
     * @param mixed $date 收文日期。
     * @return int|string
     */
    function zw_receipt_ledger_received_year($date) {
        $year = zw_receipt_storage_year_from_date($date);
        return ($year === false) ? '' : (int) $year;
    }
}

if (!function_exists('zw_receipt_ledger_row_view')) {
    /**
     * 单行展示结构（页面渲染与测试共用；不含正文，无副作用）。
     *
     * @param array $row 政策源行。
     * @return array
     */
    function zw_receipt_ledger_row_view($row) {
        $row    = is_array($row) ? $row : array();
        $issuer = isset($row['issuer']) ? (string) $row['issuer'] : '';
        $date   = isset($row['doc_received_date']) ? (string) $row['doc_received_date'] : '';
        $org_key = zw_receipt_ledger_org_key_from_issuer($issuer);

        $year  = zw_receipt_ledger_received_year($date);
        $dir   = zw_receipt_storage_relative_dir($org_key, $date);
        $file  = isset($row['source_file']) ? (string) $row['source_file'] : '';

        return array(
            'id'               => isset($row['id']) ? (string) $row['id'] : '',
            'number'           => isset($row['doc_received_no']) ? (string) $row['doc_received_no'] : '',
            'title'            => isset($row['doc_name']) ? (string) $row['doc_name'] : '',
            'doc_no'           => isset($row['doc_no']) ? (string) $row['doc_no'] : '',
            'confidentiality'  => isset($row['confidentiality_level']) ? (string) $row['confidentiality_level'] : '',
            'copy_count'       => isset($row['copy_count']) && $row['copy_count'] !== null ? (int) $row['copy_count'] : 0,
            'received_date'    => $date,
            'received_year'    => $year,
            'org_key'          => $org_key,
            'org_label'        => zw_receipt_ledger_display_org($issuer),
            'directory'        => $dir,
            'attachment'       => $file,
            'has_attachment'   => ($file !== '' && strpos($file, 'db:') !== 0),
            'create_time'      => isset($row['create_time']) ? (string) $row['create_time'] : '',
        );
    }
}

/* ═════════════════════════════ 部委/年份目录视图 ═════════════════════════════ */

if (!function_exists('zw_receipt_ledger_directory_tree')) {
    /**
     * 部委 → 年份目录树（数据源与台账**同一个 WHERE**，只含 `origin='incoming'`）。
     *
     * 目录与文件名的物理规则复用 R2 canonical `zw_receipt_storage_relative_dir()`；
     * 本函数只做**聚合展示**，不落盘、不移动文件。
     *
     * @param array $args 原始筛选（org_key / year / kw）。
     * @return array array('nodes'=>array,'total'=>int,'schema_ready'=>bool,'missing'=>array)
     */
    function zw_receipt_ledger_directory_tree($args = array()) {
        $result = array(
            'nodes'        => array(),
            'total'        => 0,
            'schema_ready' => true,
            'missing'      => array(),
        );

        $missing = zw_receipt_ledger_missing_columns();
        if ($missing) {
            $result['schema_ready'] = false;
            $result['missing']      = $missing;
            return $result;
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            $result['schema_ready'] = false;
            $result['missing']      = array('wpdb');
            return $result;
        }

        // 目录树只取聚合所需最小列（不取正文）
        $where  = zw_receipt_ledger_where($args);
        $table  = zw_receipt_ledger_source_table();
        $sql    = "SELECT id, issuer, doc_received_date FROM {$table} WHERE {$where['sql']}"
            . " ORDER BY doc_received_date DESC, id DESC";
        $rows   = $wpdb->get_results(zw_receipt_prepare_sql($sql, $where['params']), zw_receipt_ledger_array_a());
        $rows   = is_array($rows) ? $rows : array();

        // 目录节点按字典 canonical 顺序初始化 ⇒ 空部委也可见（便于核对归档结构）
        $nodes = array();
        foreach (zw_receipt_org_options() as $org_key => $label) {
            $nodes[$org_key] = array(
                'org_key'       => $org_key,
                'label'         => $label,
                'dir'           => zw_receipt_org_dir_name($org_key),
                'year_agnostic' => zw_receipt_org_is_year_agnostic($org_key),
                'total'         => 0,
                'years'         => array(),
            );
        }

        foreach ($rows as $row) {
            $row     = is_array($row) ? $row : array();
            $org_key = zw_receipt_ledger_org_key_from_issuer(isset($row['issuer']) ? $row['issuer'] : '');
            if (!isset($nodes[$org_key])) {
                // 兜底 key 被扩展点删除时仍给节点，避免统计丢失。
                $nodes[$org_key] = array(
                    'org_key'       => $org_key,
                    'label'         => zw_receipt_ledger_display_org(isset($row['issuer']) ? $row['issuer'] : ''),
                    'dir'           => zw_receipt_org_dir_name($org_key),
                    'year_agnostic' => zw_receipt_org_is_year_agnostic($org_key),
                    'total'         => 0,
                    'years'         => array(),
                );
            }
            $nodes[$org_key]['total']++;
            $result['total']++;

            if ($nodes[$org_key]['year_agnostic']) {
                // Q4/US-02：本会制度为长期依据，**不按年**
                continue;
            }
            $year = zw_receipt_ledger_received_year(isset($row['doc_received_date']) ? $row['doc_received_date'] : '');
            $key  = ($year === '') ? 'unknown' : (string) $year;
            if (!isset($nodes[$org_key]['years'][$key])) {
                $nodes[$org_key]['years'][$key] = array(
                    'year'  => ($year === '') ? '' : (int) $year,
                    'label' => ($year === '') ? zw_receipt_storage_unknown_year_dir() : (string) $year,
                    'dir'   => zw_receipt_storage_relative_dir($org_key, ($year === '') ? '' : ((int) $year) . '-01-01'),
                    'total' => 0,
                );
            }
            $nodes[$org_key]['years'][$key]['total']++;
        }

        // 年份排序：数值降序，「未知年份」恒排最后（实现口径，非文档规定）
        foreach ($nodes as $org_key => $node) {
            $years = $node['years'];
            uksort($years, function ($a, $b) {
                if ($a === $b) {
                    return 0;
                }
                if ($a === 'unknown') {
                    return 1;
                }
                if ($b === 'unknown') {
                    return -1;
                }
                return ((int) $a < (int) $b) ? 1 : -1;
            });
            $nodes[$org_key]['years'] = array_values($years);
        }

        $result['nodes'] = array_values($nodes);
        return $result;
    }
}

if (!function_exists('zw_receipt_ledger_row_by_id')) {
    /**
     * 按 ID 取单行（只读；**只返回 `origin='incoming'` 的行**）。
     *
     * TASK-2008：收文附件预览端点（SPEC-85 canonical `doc-viewer` 的 `receipt` 来源）
     * 需要按记录 ID 解析 `source_file`。口径与台账查询**同一张表 + 同一 origin 过滤**，
     * 不新起第二套取数口径；缺列（迁移未跑）/ DB 不可用一律返回空数组（零 SQL 退化）。
     *
     * @param int $id 记录 ID。
     * @return array 单行（键与台账查询一致）；不存在返回空数组。
     */
    function zw_receipt_ledger_row_by_id($id) {
        // TASK-2109：收文主键是 VARCHAR（`SRC-MCA-85`）⇒ 不得 `(int)` 强转；白名单后按字符串查。
        $id = trim((string) $id);
        if ($id === '' || strlen($id) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $id) || zw_receipt_ledger_missing_columns()) {
            return array();
        }
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            return array();
        }
        $table = zw_receipt_ledger_source_table();
        $sql   = "SELECT id, doc_name, issuer, doc_no, confidentiality_level, source_file, create_time,"
            . " origin, doc_received_date, doc_received_no, copy_count"
            . " FROM {$table} WHERE id = %s AND origin = 'incoming' LIMIT 1";
        $row   = function_exists('zw_receipt_prepare_sql')
            ? $wpdb->get_row(zw_receipt_prepare_sql($sql, array($id)), zw_receipt_ledger_array_a())
            : $wpdb->get_row($wpdb->prepare($sql, $id), zw_receipt_ledger_array_a());
        return is_array($row) ? $row : array();
    }
}

if (!function_exists('zw_receipt_ledger_attachment_label')) {
    /**
     * 收文附件展示名（`source_file` 的文件名部分；空值返回空串）。
     *
     * @param mixed $source_file 相对路径。
     * @return string
     */
    function zw_receipt_ledger_attachment_label($source_file) {
        $source_file = trim((string) $source_file);
        if ($source_file === '' || (function_exists('zw_receipt_storage_is_protected_source_file')
            && zw_receipt_storage_is_protected_source_file($source_file))) {
            return '';
        }
        $name = basename(str_replace('\\', '/', $source_file));
        return $name === '' ? '' : $name;
    }
}

if (!function_exists('zw_receipt_ledger_attachment_download_url')) {
    /**
     * 收文附件**显式下载**地址（SPEC-85 canonical `doc-viewer` 的 `download=1` 通道）。
     *
     * 只给「下载」按钮用；预览地址由 `zw_control_file_open_link()`（source=`receipt`）生成，
     * 两者共用同一 nonce（记录绑定），不新起第二套端点或鉴权。
     *
     * @param int|string $id 收文记录 ID（字符串，如 `SRC-MCA-85`）。
     * @return string
     */
    function zw_receipt_ledger_attachment_download_url($id) {
        if (!function_exists('zw_control_doc_viewer_url')) {
            return '';
        }
        return (string) zw_control_doc_viewer_url(array(
            'source'   => 'receipt',
            // TASK-2109：收文记录 ID 是字符串，不转 int。
            'file_id'  => (string) $id,
            'download' => true,
        ));
    }
}

/* ═════════════════════════════ 办理状态聚合（OA-2462） ═════════════════════════════ */

if (!function_exists('zw_receipt_ledger_issue_map')) {
    /**
     * 批量取「收文 → 办理单」汇总（台账 / 详情页展示用；**只读**）。
     *
     * 为什么单独一条查询、而不并进 `zw_receipt_ledger_query()`：
     * 台账主查询的 SQL 形状是主题契约面（`contract-api.php` 的收文快照 + 主题侧
     * `tests/test-receipt-ledger.php` 对 `FROM 政策源表 WHERE origin = %s` 与
     * `LIMIT %d OFFSET %d` 的逐项断言）。在那里加 LEFT JOIN 会同时改契约面与断言口径
     * ⇒ 保持主查询**一字不动**，状态聚合走本函数（按当页 ID 批量查）。
     *
     * 状态派生（与 `receipt-issues.php` 同口径，**不新造状态词**）：
     *   · 无办理单 ⇒ `待派单`（展示态，不落库）；
     *   · 任一单 `zw_receipt_issue_is_overdue()` 为真 ⇒ `逾期`（派生，不落库）；
     *   · 存在未收口单（状态不在 `已办结` / `归档`）⇒ `办理中`；
     *   · 全部收口 ⇒ `已办结`。
     *
     * @param string[] $ids 收文记录 ID 列表。
     * @return array<string,array<string,mixed>> id => array(status, overdue, count, due_date, risk_level)
     */
    function zw_receipt_ledger_issue_map($ids) {
        $ids = is_array($ids) ? $ids : array();
        $clean = array();
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id !== '' && strlen($id) <= 64 && preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                $clean[$id] = $id;
            }
        }
        if (!$clean) {
            return array();
        }
        if (!function_exists('zw_receipt_issue_table') || !function_exists('zw_receipt_issue_schema_ready')
            || !zw_receipt_issue_schema_ready()) {
            return array();
        }

        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return array();
        }

        $table        = zw_receipt_issue_table();
        $placeholders = implode(',', array_fill(0, count($clean), '%s'));
        $sql          = "SELECT source_id, status, due_date, risk_level FROM {$table}"
            . " WHERE source_type = %s AND source_id IN ({$placeholders})";
        $params = array_merge(array(zw_receipt_issue_source_type()), array_values($clean));
        $rows   = function_exists('zw_receipt_prepare_sql')
            ? $wpdb->get_results(zw_receipt_prepare_sql($sql, $params), zw_receipt_ledger_array_a())
            : $wpdb->get_results($wpdb->prepare($sql, $params), zw_receipt_ledger_array_a());
        $rows = is_array($rows) ? $rows : array();

        $today  = function_exists('zw_receipt_issue_today') ? (string) zw_receipt_issue_today() : '';
        $closed = array('已办结', '归档');
        $map    = array();
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : array();
            $sid = trim((string) (isset($row['source_id']) ? $row['source_id'] : ''));
            if ($sid === '') {
                continue;
            }
            if (!isset($map[$sid])) {
                $map[$sid] = array(
                    'status'     => '待派单',
                    'overdue'    => false,
                    'open'       => false,
                    'count'      => 0,
                    'due_date'   => '',
                    'risk_level' => '',
                );
            }
            $map[$sid]['count']++;
            $risk = trim((string) (isset($row['risk_level']) ? $row['risk_level'] : ''));
            if ($map[$sid]['risk_level'] === '' && $risk !== '') {
                $map[$sid]['risk_level'] = $risk;
            }
            $due = trim((string) (isset($row['due_date']) ? $row['due_date'] : ''));
            if ($map[$sid]['due_date'] === '' && $due !== '') {
                $map[$sid]['due_date'] = $due;
            }
            if (function_exists('zw_receipt_issue_is_overdue') && zw_receipt_issue_is_overdue($row, $today)) {
                $map[$sid]['overdue'] = true;
            }
            $status = trim((string) (isset($row['status']) ? $row['status'] : ''));
            if (!in_array($status, $closed, true)) {
                $map[$sid]['open'] = true;
            }
        }

        foreach ($map as $sid => $item) {
            if (!empty($item['overdue'])) {
                $map[$sid]['status'] = '逾期';
            } elseif (!empty($item['open'])) {
                $map[$sid]['status'] = '办理中';
            } else {
                $map[$sid]['status'] = '已办结';
            }
            unset($map[$sid]['open']);
        }

        return $map;
    }
}

if (!function_exists('zw_receipt_ledger_detail_url')) {
    /**
     * 收文详情页地址（同一个后台页的 `view=detail` 分支；**不新增菜单项**）。
     *
     * @param string $id 收文记录 ID。
     * @return string
     */
    function zw_receipt_ledger_detail_url($id) {
        $id   = trim((string) $id);
        $base = function_exists('admin_url') ? (string) admin_url('admin.php') : 'admin.php';
        $args = array('page' => 'zw-receipt-ledger', 'view' => 'detail');
        if ($id !== '') {
            $args['id'] = $id;
        }
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($args, $base);
        }
        return $base . '?' . http_build_query($args);
    }
}

if (!function_exists('zw_receipt_ledger_admin_url')) {
    /**
     * 收文后台某页面地址（`收文登记` 主按钮与 `重置` 用；无 WP 环境退化拼装）。
     *
     * @param string $slug 页面 slug（`zw-receipt-*`）。
     * @return string
     */
    function zw_receipt_ledger_admin_url($slug = 'zw-receipt-ledger') {
        $slug = trim((string) $slug);
        if ($slug === '') {
            $slug = 'zw-receipt-ledger';
        }
        $base = function_exists('admin_url') ? (string) admin_url('admin.php') : 'admin.php';
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg(array('page' => $slug), $base);
        }
        return $base . '?page=' . rawurlencode($slug);
    }
}

/* ═════════════════════════════ 权限与页面 URL ═════════════════════════════ */

if (!function_exists('zw_receipt_ledger_can')) {
    /**
     * 台账/目录页权限判定（Q6/Q14：`tcpc_perm_doc`；无 WP 环境一律 fail-closed）。
     *
     * @return bool
     */
    function zw_receipt_ledger_can() {
        if (function_exists('zw_receipt_create_can')) {
            return (bool) zw_receipt_create_can();
        }
        if (!function_exists('current_user_can')) {
            return false;
        }
        return (bool) current_user_can(zw_receipt_capability());
    }
}

if (!function_exists('zw_receipt_ledger_request_args')) {
    /**
     * 从请求（`$_GET`）提取筛选参数（键名限定，未识别键一律忽略）。
     *
     * @param array $request 请求数组。
     * @return array
     */
    function zw_receipt_ledger_request_args($request) {
        $request = is_array($request) ? $request : array();
        return array(
            'org_key'  => isset($request['zw_receipt_org']) ? $request['zw_receipt_org'] : '',
            'year'     => isset($request['zw_receipt_year']) ? $request['zw_receipt_year'] : '',
            'kw'       => isset($request['zw_receipt_kw']) ? $request['zw_receipt_kw'] : '',
            'page'     => isset($request['zw_receipt_page']) ? $request['zw_receipt_page'] : 1,
            'per_page' => isset($request['zw_receipt_per_page']) ? $request['zw_receipt_per_page'] : '',
        );
    }
}

if (!function_exists('zw_receipt_ledger_page_url')) {
    /**
     * 台账/目录页 URL（分页与筛选链接；参数全部归一后拼装）。
     *
     * @param array  $args 筛选。
     * @param int    $page 目标页。
     * @param string $slug 页面 slug。
     * @return string
     */
    function zw_receipt_ledger_page_url($args, $page = 1, $slug = 'zw-receipt-ledger') {
        $filters = zw_receipt_ledger_normalize_filters($args);
        $query   = array('page' => (string) $slug);
        if ($filters['org_key'] !== '') {
            $query['zw_receipt_org'] = $filters['org_key'];
        }
        if ($filters['year'] !== '') {
            $query['zw_receipt_year'] = (string) $filters['year'];
        }
        if ($filters['keyword'] !== '') {
            $query['zw_receipt_kw'] = $filters['keyword'];
        }
        if ((int) $filters['per_page'] !== zw_receipt_ledger_default_per_page()) {
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

/* ═════════════════════════════ 页面渲染 ═════════════════════════════ */

if (!function_exists('zw_receipt_page_ledger')) {
    /**
     * 收文台账页（capability = `tcpc_perm_doc`；页面级二次判权，无权限直接 403）。
     *
     * @return void
     */
    function zw_receipt_page_ledger() {
        if (!zw_receipt_ledger_can()) {
            if (function_exists('wp_die')) {
                wp_die('无权限访问收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
            }
            return;
        }
        if (!function_exists('esc_html')) {
            return;
        }

        $request = isset($_GET) && is_array($_GET) ? $_GET : array();
        $view    = isset($request['view'])
            ? strtolower((string) preg_replace('/[^A-Za-z_]/', '', (string) $request['view']))
            : '';
        if ($view === 'detail') {
            zw_receipt_page_ledger_detail(isset($request['id']) ? $request['id'] : '');
            return;
        }
        // OA-2463：OCR 设置视图（挂在台账页，不新增子菜单 —— 见 ocr-admin.php 头部说明）。
        if ($view === 'ocr' && function_exists('zw_ocr_render_settings_body')) {
            if (function_exists('zw_receipt_admin_ui_page_open')) {
                zw_receipt_admin_ui_page_open(
                    'OCR 识别设置',
                    '收文附件在本机 OCR 引擎上识别为文本（不出服务器）。识别是**异步**的：登记成功后排队，'
                    . '在 WP-Cron 里执行；失败只影响 OCR 字段，不影响收文登记与办理。',
                    'zw-receipt-ledger'
                );
            } else {
                echo '<div class="wrap zw-receipt-ledger"><h1>OCR 识别设置</h1>';
            }
            zw_ocr_render_settings_body();
            if (function_exists('zw_receipt_admin_ui_page_close')) {
                zw_receipt_admin_ui_page_close();
            } else {
                echo '</div>';
            }
            return;
        }

        $args    = zw_receipt_ledger_request_args($request);
        $result  = zw_receipt_ledger_query($args);
        $orgs    = zw_receipt_org_options();
        $filters = $result['filters'];

        // OA-2462：当页收文的「办理状态」（按当页 ID 批量一条查询；办理表缺失/迁移未跑 ⇒
        // 空 map，状态列显示「—」，页面照常可用，不因办理表不可用而整页失败）。
        $page_ids = array();
        foreach ($result['rows'] as $row) {
            if (isset($row['id']) && (string) $row['id'] !== '') {
                $page_ids[] = (string) $row['id'];
            }
        }
        $issue_map = zw_receipt_ledger_issue_map($page_ids);

        echo '<div class="wrap zw-receipt-ledger">';
        // TASK-1633（TASK-1579 P1-6 第四批）：收文管理域不在会员域外壳作用域内
        // （slug 前缀 `zw-receipt` 不命中 `zw_mc_adm_is_screen()`）⇒ 本页须自带 canonical 规则输出点；
        // 唯一出处仍是 canonical `zw_adm_ui_table_wrap_css()`（经本域唯一出口透传，不复制第二套声明）。
        echo function_exists('zw_receipt_admin_ui_table_wrap_style') ? zw_receipt_admin_ui_table_wrap_style() : '';
        // OA-2462：共享卡片 / 字段 / 徽章 / 分页令牌（与发文管理同一套；唯一出处
        // `includes/oa/admin-ui.php`，本域不写第二套声明）。
        echo function_exists('zw_receipt_admin_ui_style') ? zw_receipt_admin_ui_style() : '';
        echo '<h1>收文台账</h1>';
        // TASK-2008：文件默认打开动作 canonical 资源（doc-viewer 弹层/图片灯箱 + file-icon + 本控件样式）。
        // 本页不在会员域外壳作用域内 ⇒ 必须自带输出点（不新增全局 CSS 文件）。
        if (function_exists('zw_control_file_open_assets')) {
            zw_control_file_open_assets();
        }
        echo '<p class="zw-doc-sub">只显示 <code>origin=incoming</code> 的来文；本会自产文件（章程/管理办法/保密协议/隐私政策）不出现在此视图（<a href="'
            . esc_url(zw_receipt_ledger_page_url(array(), 1, 'zw-receipt-orgs')) . '">部委与年份目录</a>）。</p>';

        // 主操作（与发文列表页「新建发文」同位：标题下、筛选上）
        echo '<div style="margin:10px 0 14px"><a class="button button-primary" href="'
            . esc_url(zw_receipt_ledger_admin_url('zw-receipt-create')) . '">登记收文</a>';
        if (function_exists('zw_ocr_settings_url')) {
            echo ' <a class="button" href="' . esc_url(zw_ocr_settings_url()) . '">OCR 识别设置</a>';
        }
        echo '</div>';

        // OA-2464：待批示提示（有拟办、无批示）。角标在左侧菜单，这里给可读入口。
        if (function_exists('zw_receipt_handle_pending_instruction')) {
            $zw_pending = (int) zw_receipt_handle_pending_instruction();
            if ($zw_pending > 0) {
                echo '<div class="notice notice-warning inline" style="margin:0 0 14px"><p><strong>'
                    . $zw_pending . ' 件收文待批示</strong>（已填拟办、尚无领导批示）。'
                    . '点开对应收文的「查看」→「拟办与批示」卡片补批示。</p></div>';
            }
        }

        if (!$result['schema_ready']) {
            echo '<div class="notice notice-warning"><p>收文台账暂不可用：数据表尚未迁移（缺少列：'
                . esc_html(implode('、', $result['missing'])) . '），本次返回 0 行（不显示任何记录）。</p></div>';
            // 迁移未跑 ⇒ **不渲染表头/表格**（既有判据：缺列时页面不得出现表头，避免"空壳台账"误导）。
            echo '</div>';
            return;
        }

        // 筛选表单（GET；只读视图，无需 nonce）
        echo '<form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin:0 0 14px">';
        echo '<input type="hidden" name="page" value="zw-receipt-ledger">';
        echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:13px;color:#666">来文单位';
        echo '<select name="zw_receipt_org"><option value="">全部来文单位</option>';
        foreach ($orgs as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . (($filters['org_key'] === $key) ? ' selected' : '') . '>'
                . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:13px;color:#666">收文年份';
        echo '<input type="text" name="zw_receipt_year" value="' . esc_attr(($filters['year'] === '') ? '' : (string) $filters['year'])
            . '" placeholder="如 2026" size="10"></label>';
        echo '<label style="display:flex;flex-direction:column;gap:4px;font-size:13px;color:#666">关键词';
        echo '<input type="search" name="zw_receipt_kw" value="' . esc_attr($filters['keyword'])
            . '" placeholder="标题 / 来文字号 / 收文编号"></label>';
        echo '<button type="submit" class="button button-primary">筛选</button>';
        echo '<a class="button" href="' . esc_url(zw_receipt_ledger_admin_url()) . '">重置</a>';
        echo '</form>';

        $colspan = 11;
        echo '<div class="zw-adm-table-wrap">';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th style="width:150px">收文编号</th><th style="width:110px">来文单位</th>'
            . '<th style="width:140px">来文字号</th><th>标题</th>'
            . '<th style="width:80px">密级</th><th style="width:110px">收文日期</th>'
            . '<th style="width:60px">份数</th><th style="width:90px">办理状态</th>'
            . '<th style="width:150px">归档目录</th><th style="width:170px">附件</th>'
            . '<th style="width:110px">操作</th>'
            . '</tr></thead><tbody>';

        if (!$result['rows']) {
            // OA-2462：空态写在表内（与发文台账一致：`<tr><td colspan>`），并给出**就地入口**。
            $empty_extra = '';
            if ($filters['org_key'] !== '' || $filters['year'] !== '' || $filters['keyword'] !== '') {
                $empty_extra = ' 或 <a href="' . esc_url(zw_receipt_ledger_admin_url()) . '">清空筛选</a>';
            }
            echo '<tr><td colspan="' . (int) $colspan . '">暂无收文记录：当前筛选条件下没有来文。'
                . '<a class="button button-small" style="margin-left:8px" href="'
                . esc_url(zw_receipt_ledger_admin_url('zw-receipt-create')) . '">登记第一份收文</a>'
                . $empty_extra . '</td></tr>';
        } else {
            foreach ($result['rows'] as $row) {
                $view = zw_receipt_ledger_row_view($row);
                $issue = isset($issue_map[(string) $view['id']]) ? $issue_map[(string) $view['id']] : array();
                echo '<tr>';
                echo '<td>' . esc_html($view['number']) . '</td>';
                echo '<td>' . esc_html($view['org_label']) . '</td>';
                echo '<td>' . esc_html($view['doc_no']) . '</td>';
                echo '<td><a href="' . esc_url(zw_receipt_ledger_detail_url((string) $view['id'])) . '">'
                    . esc_html($view['title']) . '</a></td>';
                echo '<td>' . esc_html($view['confidentiality']) . '</td>';
                echo '<td>' . esc_html($view['received_date']) . '</td>';
                echo '<td>' . (int) $view['copy_count'] . '</td>';
                // 办理状态：无办理单 ⇒ 「待派单」；有则按办理单聚合（逾期为派生，不落库）。
                echo '<td>' . (function_exists('zw_receipt_admin_ui_status_badge')
                    ? zw_receipt_admin_ui_status_badge(
                        isset($issue['status']) ? $issue['status'] : '待派单',
                        !empty($issue['overdue'])
                    )
                    : esc_html(isset($issue['status']) ? $issue['status'] : '待派单')) . '</td>';
                echo '<td>' . esc_html($view['directory']) . '</td>';
                // TASK-2008：附件列从「有 / —」升级为**可用入口**：
                // 文件名 = 默认打开（PDF/doc/docx 弹层、图片灯箱），下载只走显式「下载」按钮。
                // 预览实现仍是 SPEC-85 canonical `doc-viewer`（source=`receipt`，私有存储路径受管校验）。
                $att_cell = '—';
                if ($view['has_attachment']) {
                    $att_name = function_exists('zw_receipt_ledger_attachment_label')
                        ? zw_receipt_ledger_attachment_label($view['attachment'])
                        : '';
                    $att_name = $att_name !== '' ? $att_name : '附件';
                    $att_open = function_exists('zw_control_file_open_link')
                        ? zw_control_file_open_link(array(
                            'name'         => $att_name,
                            'source'       => 'receipt',
                            // TASK-2109：收文记录 ID 是字符串（`SRC-…`），不得强转 int。
                            'id'           => (string) $view['id'],
                            'size'         => 0,
                            // 弹层工具条里的「下载原件」也走显式下载通道（不是默认动作）。
                            'download_url' => function_exists('zw_receipt_ledger_attachment_download_url')
                                ? zw_receipt_ledger_attachment_download_url((string) $view['id'])
                                : '',
                        ))
                        : esc_html($att_name);
                    $att_dl = function_exists('zw_control_file_download_button')
                        ? zw_control_file_download_button(array(
                            'url'   => function_exists('zw_receipt_ledger_attachment_download_url')
                                ? zw_receipt_ledger_attachment_download_url((string) $view['id'])
                                : '',
                            'name'  => $att_name,
                            'label' => '下载',
                            'icon'  => false,
                        ))
                        : '';
                    $att_cell = '<span style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap">'
                        . $att_open . $att_dl . '</span>';
                }
                echo '<td>' . $att_cell . '</td>';
                // 操作列（OA-2462）：详情 + 直接去办理（带来源筛选，落到收文办理页）
                $ops = '<a class="button button-small" href="'
                    . esc_url(zw_receipt_ledger_detail_url((string) $view['id'])) . '">查看</a>';
                if (function_exists('zw_receipt_issue_page_url')) {
                    $ops .= ' <a class="button button-small" href="'
                        . esc_url(zw_receipt_issue_page_url(array('source_id' => (string) $view['id']), 1))
                        . '">办理</a>';
                }
                echo '<td>' . $ops . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';

        // 分页：有 WP `paginate_links()` 时用后台原生页码（横排规则由共享样式提供），
        // 否则退回按钮式翻页（无 WP 环境 / 单测）。
        $pages = (int) $result['pages'];
        if ($pages > 1) {
            $paged_now = (int) $filters['page'];
            if (function_exists('paginate_links') && function_exists('add_query_arg')) {
                $page_base = zw_receipt_ledger_page_url($filters, 1);
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links(array(
                    'base'      => add_query_arg('zw_receipt_page', '%#%', $page_base),
                    'format'    => '',
                    'current'   => $paged_now,
                    'total'     => $pages,
                    'type'      => 'list',
                    'prev_text' => '‹',
                    'next_text' => '›',
                ));
                echo '</div></div>';
            } else {
                echo '<p style="margin-top:12px">';
                if ($paged_now > 1) {
                    echo '<a class="button" href="' . esc_url(zw_receipt_ledger_page_url($filters, $paged_now - 1)) . '">上一页</a> ';
                }
                echo '第 ' . $paged_now . ' / ' . $pages . ' 页（共 ' . (int) $result['total'] . ' 条）';
                if ($paged_now < $pages) {
                    echo ' <a class="button" href="' . esc_url(zw_receipt_ledger_page_url($filters, $paged_now + 1)) . '">下一页</a>';
                }
                echo '</p>';
            }
        }
        echo '</div>';
    }
}

if (!function_exists('zw_receipt_ledger_detail_field')) {
    /**
     * 详情页字段格（标签 + 值；值缺省渲染「—」）。
     *
     * @param string $label 标签。
     * @param string $value 值（已转义的纯文本）。
     * @return string HTML。
     */
    function zw_receipt_ledger_detail_field($label, $value) {
        $label = (string) $label;
        $value = trim((string) $value);
        if ($value === '') {
            $value = '—';
        }
        return '<div class="zw-adm-field"><label>' . zw_receipt_admin_ui_esc($label) . '</label>'
            . '<div>' . zw_receipt_admin_ui_esc($value) . '</div></div>';
    }
}

if (!function_exists('zw_receipt_page_ledger_detail')) {
    /**
     * 收文详情页（同一后台页的 `view=detail` 分支 · OA-2462）。
     *
     * 为什么用 `view=detail` 而不是新菜单项：与发文管理同构（`zw-doc&view=detail`），
     * 且**不动**主题侧导航注册表与父菜单落地页（`zw-receipt-ledger` 仍是菜单落点）。
     *
     * 权限：与台账同一能力位（`tcpc_perm_doc`），无权限 403；**只读**（不写库）。
     *
     * @param mixed $id 收文记录 ID（字符串，如 `SRC-MCA-85`）。
     * @return void
     */
    function zw_receipt_page_ledger_detail($id) {
        if (!zw_receipt_ledger_can()) {
            if (function_exists('wp_die')) {
                wp_die('无权限访问收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
            }
            return;
        }
        if (!function_exists('esc_html')) {
            return;
        }

        $id  = trim((string) $id);
        $row = $id !== '' ? zw_receipt_ledger_row_by_id($id) : array();

        echo '<div class="wrap zw-receipt-detail">';
        echo function_exists('zw_receipt_admin_ui_table_wrap_style') ? zw_receipt_admin_ui_table_wrap_style() : '';
        echo function_exists('zw_receipt_admin_ui_style') ? zw_receipt_admin_ui_style() : '';
        if (function_exists('zw_control_file_open_assets')) {
            zw_control_file_open_assets();
        }
        echo '<h1>收文详情</h1>';
        echo '<p style="margin:0 0 12px"><a class="button" href="'
            . esc_url(zw_receipt_ledger_admin_url()) . '">← 返回收文台账</a></p>';

        if (!$row) {
            echo '<div class="notice notice-warning"><p>未找到该收文：'
                . '记录不存在、已被删除，或不是 <code>origin=incoming</code> 的来文。'
                . '请<a href="' . esc_url(zw_receipt_ledger_admin_url()) . '">返回台账</a>重新选择。</p></div>';
            echo '</div>';
            return;
        }

        $view      = zw_receipt_ledger_row_view($row);
        $issue_map = zw_receipt_ledger_issue_map(array((string) $view['id']));
        $issue_sum = isset($issue_map[(string) $view['id']]) ? $issue_map[(string) $view['id']] : array();

        // —— 卡片 1：来文信息 ——
        echo '<div class="zw-adm-card" style="margin:0 0 16px">';
        echo '<h2>来文信息</h2>';
        echo '<div class="zw-adm-grid is-2">';
        echo zw_receipt_ledger_detail_field('收文编号', $view['number']);
        echo zw_receipt_ledger_detail_field('来文单位', $view['org_label']);
        echo zw_receipt_ledger_detail_field('来文字号', $view['doc_no']);
        echo zw_receipt_ledger_detail_field('收文日期', $view['received_date']);
        echo zw_receipt_ledger_detail_field('密级', $view['confidentiality']);
        echo zw_receipt_ledger_detail_field('份数', (string) (int) $view['copy_count']);
        echo zw_receipt_ledger_detail_field('紧急程度', isset($issue_sum['risk_level']) ? (string) $issue_sum['risk_level'] : '');
        echo zw_receipt_ledger_detail_field('办理状态', isset($issue_sum['status']) ? (string) $issue_sum['status'] : '待派单');
        echo zw_receipt_ledger_detail_field('归档目录', $view['directory']);
        echo zw_receipt_ledger_detail_field('登记时间', $view['create_time']);
        echo '</div>';
        echo '<div class="zw-adm-field"><label>标题</label><div>' . esc_html($view['title']) . '</div></div>';
        echo '</div>';

        // —— 卡片 2：附件（文件名 = 默认打开；下载只走显式按钮 · SPEC-85 铁律）——
        echo '<div class="zw-adm-card" style="margin:0 0 16px">';
        echo '<h2>附件</h2>';
        if ($view['has_attachment']) {
            $att_name = function_exists('zw_receipt_ledger_attachment_label')
                ? zw_receipt_ledger_attachment_label($view['attachment'])
                : '';
            $att_name = $att_name !== '' ? $att_name : '附件';
            $att_dl_url = function_exists('zw_receipt_ledger_attachment_download_url')
                ? zw_receipt_ledger_attachment_download_url((string) $view['id'])
                : '';
            if (function_exists('zw_control_file_open_link')) {
                echo '<p>' . zw_control_file_open_link(array(
                    'name'         => $att_name,
                    'source'       => 'receipt',
                    'id'           => (string) $view['id'],
                    'size'         => 0,
                    'download_url' => $att_dl_url,
                )) . '</p>';
            } else {
                echo '<p>' . esc_html($att_name) . '</p>';
            }
            if (function_exists('zw_control_file_download_button')) {
                echo '<p>' . zw_control_file_download_button(array(
                    'url'   => $att_dl_url,
                    'name'  => $att_name,
                    'label' => '下载原件',
                    'icon'  => false,
                )) . '</p>';
            }
            echo '<p class="zw-doc-muted">点击文件名或图标即默认打开（PDF / docx 在线预览、图片灯箱）；'
                . '下载只由上面的「下载原件」触发。附件走私有存储，不产生公开直链。</p>';
        } else {
            echo '<p class="zw-doc-muted">该收文未上传附件。附件在「收文登记」时随件上传，'
                . '落私有存储 <code>部委/年份/文件名</code>。</p>';
        }
        echo '</div>';

        // —— 卡片 2.5：OCR 识别（OA-2463；结果只落 OCR 专用列，不改进正文）——
        if (function_exists('zw_ocr_record') && function_exists('zw_ocr_status_label')) {
            $ocr = zw_ocr_record((string) $view['id']);
            echo '<div class="zw-adm-card" style="margin:0 0 16px">';
            echo '<h2>OCR 识别</h2>';

            $ocr_msg = isset($_GET['zw_msg']) ? sanitize_key((string) wp_unslash($_GET['zw_msg'])) : '';
            if ($ocr_msg === 'ocr_queued') {
                echo '<div class="notice notice-success inline"><p>已重新排队：识别完成后刷新本页即可看到文本。</p></div>';
            } elseif ($ocr_msg === 'ocr_not_queued') {
                echo '<div class="notice notice-warning inline"><p>未能排队：请先在「OCR 识别设置」启用开关并保存端点'
                    . '（或数据列尚未迁移）。</p></div>';
            }

            echo '<div class="zw-adm-grid is-2">';
            echo zw_receipt_ledger_detail_field('识别状态', zw_ocr_status_label($ocr['ocr_status']));
            echo zw_receipt_ledger_detail_field('引擎', $ocr['extraction_method'] !== '' ? $ocr['extraction_method'] : '—');
            echo zw_receipt_ledger_detail_field('平均置信度', $ocr['ocr_confidence'] === null ? '' : (string) $ocr['ocr_confidence'] . '%');
            echo zw_receipt_ledger_detail_field('耗时', $ocr['ocr_ms'] > 0 ? $ocr['ocr_ms'] . ' ms' : '');
            echo zw_receipt_ledger_detail_field('识别时间', $ocr['ocr_at']);
            echo '</div>';

            if ($ocr['ocr_status'] === 'failed' && $ocr['ocr_message'] !== '') {
                echo '<div class="notice notice-error inline"><p><strong>识别失败：</strong>'
                    . esc_html($ocr['ocr_message']) . '</p><p>请先到「OCR 识别设置 → 测试连接」确认服务可达；'
                    . '修好后点下面的「重新识别」。</p></div>';
            } elseif ($ocr['ocr_status'] === '' || $ocr['ocr_status'] === 'pending') {
                echo '<p class="zw-doc-muted">尚未识别或正在排队。启用 OCR 后，登记成功会自动排队；'
                    . '也可以点「重新识别」立即排一次。</p>';
            }

            if ($ocr['ocr_text'] !== '') {
                echo '<details style="margin:10px 0 0"><summary style="cursor:pointer;font-weight:600">识别文本（'
                    . (int) str_word_count((string) $ocr['ocr_text']) . ' 词 / ' . (function_exists('mb_strlen') ? (int) mb_strlen((string) $ocr['ocr_text']) : (int) strlen((string) $ocr['ocr_text']))
                    . ' 字）</summary>'
                    . '<pre style="white-space:pre-wrap;word-break:break-word;max-height:360px;overflow:auto;'
                    . 'background:#f6f7f7;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:10px 0 0;font-size:13px">'
                    . esc_html($ocr['ocr_text']) . '</pre></details>';
            }

            if (function_exists('zw_ocr_retry_url') && function_exists('zw_ocr_retry_nonce')) {
                echo '<form method="post" action="' . esc_url(zw_ocr_retry_url()) . '" style="margin:12px 0 0">';
                echo '<input type="hidden" name="action" value="zw_ocr_retry">';
                echo '<input type="hidden" name="zw_ocr_id" value="' . esc_attr((string) $view['id']) . '">';
                if (function_exists('wp_nonce_field')) {
                    wp_nonce_field(zw_ocr_retry_nonce((string) $view['id']), 'zw_ocr_nonce');
                }
                echo '<button type="submit" class="button">重新识别</button>';
                echo '<span class="zw-doc-muted" style="margin-left:10px">识别在本机进行，文件不出服务器；'
                    . '识别文本只写 OCR 专用列，不修改来文正文。</span>';
                echo '</form>';
            }
            echo '</div>';
        }

        // —— 卡片 3：办理单（只读列表；办理动作仍去「收文办理」页）——
        echo '<div class="zw-adm-card" style="margin:0 0 16px">';
        echo '<h2>办理单</h2>';
        echo '<p style="margin:0 0 12px"><a class="button button-primary" href="'
            . esc_url(function_exists('zw_receipt_issue_page_url')
                ? zw_receipt_issue_page_url(array('source_id' => (string) $view['id']), 1)
                : zw_receipt_ledger_admin_url('zw-receipt-issues')) . '">去收文办理</a></p>';

        $issues = array();
        if (function_exists('zw_receipt_issue_query')) {
            $issue_result = zw_receipt_issue_query(array('source_id' => (string) $view['id'], 'per_page' => 50));
            $issues = isset($issue_result['rows']) && is_array($issue_result['rows']) ? $issue_result['rows'] : array();
        }

        echo '<div class="zw-adm-table-wrap"><table class="widefat striped"><thead><tr>'
            . '<th style="width:90px">状态</th><th>办理事项</th><th style="width:130px">承办部门</th>'
            . '<th style="width:110px">承办人</th><th style="width:110px">办理时限</th>'
            . '<th style="width:150px">更新时间</th>'
            . '</tr></thead><tbody>';
        if (!$issues) {
            echo '<tr><td colspan="6">尚无办理单：这份收文还没有指定承办部门与时限。'
                . '点上面的「去收文办理」派单（一纸收文可派多单）。</td></tr>';
        } else {
            foreach ($issues as $issue_row) {
                $issue_view = function_exists('zw_receipt_issue_row_view')
                    ? zw_receipt_issue_row_view($issue_row)
                    : array();
                if (!$issue_view) {
                    continue;
                }
                echo '<tr>';
                echo '<td>' . (function_exists('zw_receipt_admin_ui_status_badge')
                    ? zw_receipt_admin_ui_status_badge($issue_view['status'], (bool) $issue_view['overdue'])
                    : esc_html((string) $issue_view['display_status'])) . '</td>';
                echo '<td>' . esc_html((string) $issue_view['title']) . '</td>';
                echo '<td>' . esc_html((string) $issue_view['dept_label']) . '</td>';
                echo '<td>' . esc_html((string) $issue_view['owner']) . '</td>';
                echo '<td>' . esc_html((string) $issue_view['due_date']) . '</td>';
                echo '<td>' . esc_html((string) $issue_view['update_time']) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';
        echo '</div>';

        // —— 卡片 4/5/6：拟办与批示 / 传阅与签阅 / 归档信息（OA-2464）——
        if (function_exists('zw_receipt_handle_render_cards')) {
            zw_receipt_handle_render_cards((string) $view['id']);
        }

        echo '</div>';
    }
}

if (!function_exists('zw_receipt_page_orgs')) {
    /**
     * 部委与年份目录页（capability = `tcpc_perm_doc`；页面级二次判权）。
     *
     * 展示物理归档结构 `部委/年份/`（本会制度不按年）；不落盘、不移动文件。
     *
     * @return void
     */
    function zw_receipt_page_orgs() {
        if (!zw_receipt_ledger_can()) {
            if (function_exists('wp_die')) {
                wp_die('无权限访问收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
            }
            return;
        }
        if (!function_exists('esc_html')) {
            return;
        }

        $tree = zw_receipt_ledger_directory_tree(zw_receipt_ledger_request_args(isset($_GET) ? $_GET : array()));

        echo '<div class="wrap zw-receipt-orgs">';
        // OA-2462：与台账/发文同一套卡片 / 徽章 / 字段令牌（唯一出处 `includes/oa/admin-ui.php`）。
        echo function_exists('zw_receipt_admin_ui_table_wrap_style') ? zw_receipt_admin_ui_table_wrap_style() : '';
        echo function_exists('zw_receipt_admin_ui_style') ? zw_receipt_admin_ui_style() : '';
        echo '<h1>部委与年份目录</h1>';
        echo '<p class="zw-doc-sub">归档层级固定 <code>部委/年份/文件名</code>；「本会制度」为长期依据，<strong>不按年</strong>；收文日期缺失或非法落「未知年份」。数据源与台账同一查询（<code>origin=incoming</code>）。</p>';

        if (!$tree['schema_ready']) {
            echo '<div class="notice notice-warning"><p>目录视图暂不可用：数据表尚未迁移（缺少列：'
                . esc_html(implode('、', $tree['missing'])) . '），本次返回 0 行。</p></div>';
            echo '</div>';
            return;
        }

        foreach ($tree['nodes'] as $node) {
            echo '<h2 style="margin-bottom:4px">' . esc_html($node['label'])
                . ' <span style="color:#646970;font-size:12px">(' . (int) $node['total'] . ' 件 · <code>'
                . esc_html($node['dir']) . '</code>)</span></h2>';
            if ($node['year_agnostic']) {
                echo '<p style="margin:0 0 12px;color:#646970">长期依据，不按年归档（固定目录 <code>'
                    . esc_html($node['dir']) . '</code>）。</p>';
                continue;
            }
            if (!$node['years']) {
                echo '<p style="margin:0 0 12px;color:#646970">暂无来文。</p>';
                continue;
            }
            echo '<ul style="margin:0 0 12px 18px">';
            foreach ($node['years'] as $year) {
                echo '<li>' . esc_html($year['label']) . '（' . (int) $year['total'] . ' 件）· <code>'
                    . esc_html($year['dir']) . '</code></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
}
