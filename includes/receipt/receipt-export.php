<?php
/**
 * 收文管理 · 台账导出（CSV / Excel 可直接打开）· OA-2470。
 *
 * ## 口径
 *
 * - **按当前筛选导出**：来文单位 / 年份 / 关键词（含 OCR 正文）三件套与台账页完全同源
 *   （复用 `zw_receipt_ledger_request_args()` + `zw_receipt_ledger_where()`），不另造一套筛选语义。
 * - **鉴权**：`tcpc_perm_doc` + `admin-post` nonce（按筛选指纹绑定，防手拼链接批量拉库）。
 * - **字段**：只要台账字段（编号/单位/字号/标题/密级/日期/份数/办理状态/归档盒号/年度/页数/登记时间），
 *   **不含** OCR 正文与个人信息明细。
 * - **上限** 5000 行（超出截断并在末行注明），避免一次导出把 php-fpm 内存打满。
 * - CSV 带 UTF-8 BOM，Excel 双击不乱码。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_RECEIPT_EXPORT_MAX_ROWS')) {
    define('ZW_RECEIPT_EXPORT_MAX_ROWS', 5000);
}

if (!function_exists('zw_receipt_export_nonce_action')) {
    /**
     * 导出 nonce action：绑定「筛选指纹」，换筛选即换 nonce。
     *
     * @param array $filters 归一后的筛选。
     * @return string
     */
    function zw_receipt_export_nonce_action($filters) {
        $filters = is_array($filters) ? $filters : array();
        $fingerprint = (string) (isset($filters['org_key']) ? $filters['org_key'] : '')
            . '|' . (string) (isset($filters['year']) ? $filters['year'] : '')
            . '|' . (string) (isset($filters['keyword']) ? $filters['keyword'] : '');
        return 'zw_receipt_export_' . substr(md5($fingerprint), 0, 16);
    }
}

if (!function_exists('zw_receipt_export_url')) {
    /**
     * 导出地址（带筛选 + nonce；台账页按钮用它）。
     *
     * @param array $filters 筛选（已在 `zw_receipt_ledger_query` 里归一过的也可）。
     * @return string
     */
    function zw_receipt_export_url($filters = array()) {
        $filters = is_array($filters) ? $filters : array();
        $args    = array('action' => 'zw_receipt_export');
        if (!empty($filters['org_key'])) {
            $args['zw_receipt_org'] = (string) $filters['org_key'];
        }
        if (!empty($filters['year'])) {
            $args['zw_receipt_year'] = (string) $filters['year'];
        }
        if (!empty($filters['keyword'])) {
            $args['zw_receipt_kw'] = (string) $filters['keyword'];
        }
        if (function_exists('wp_create_nonce')) {
            $args['_wpnonce'] = wp_create_nonce(zw_receipt_export_nonce_action($filters));
        }
        $base = function_exists('admin_url') ? (string) admin_url('admin-post.php') : 'admin-post.php';
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($args, $base);
        }
        return $base . '?' . http_build_query($args);
    }
}

if (!function_exists('zw_receipt_export_fetch')) {
    /**
     * 按筛选取导出行（一条 SELECT，不逐行查）。
     *
     * @param array $filters 归一后的筛选。
     * @param int   $limit   上限。
     * @return array[] 行（键与台账查询一致 + 归档三字段）。
     */
    function zw_receipt_export_fetch($filters, $limit = ZW_RECEIPT_EXPORT_MAX_ROWS) {
        if (!function_exists('zw_receipt_ledger_where') || !function_exists('zw_receipt_ledger_source_table')) {
            return array();
        }
        $limit = max(1, (int) $limit);
        $where = zw_receipt_ledger_where($filters);
        $table = zw_receipt_ledger_source_table();
        if ($table === '') {
            return array();
        }
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return array();
        }

        // 归档三列若未迁移则不查（不做「缺列就退化全表」）。
        $extra  = '';
        $cols   = function_exists('zw_receipt_source_columns') ? (array) zw_receipt_source_columns() : array();
        $has    = function ($col) use ($cols) {
            return isset($cols[$col]);
        };
        if ($has('page_count') && $has('archive_box_no') && $has('archive_year')) {
            $extra = ', page_count, archive_box_no, archive_year';
        }

        $sql = "SELECT id, doc_name, issuer, doc_no, confidentiality_level, source_file, create_time,"
            . " origin, doc_received_date, doc_received_no, copy_count" . $extra
            . " FROM {$table} WHERE {$where['sql']}"
            . " ORDER BY (doc_received_date REGEXP '^[0-9]{4}') DESC, doc_received_date DESC, id DESC"
            . " LIMIT %d";
        $params = array_merge($where['params'], array($limit));
        $sql    = function_exists('zw_receipt_prepare_sql')
            ? zw_receipt_prepare_sql($sql, $params)
            : $wpdb->prepare($sql, $params);
        $rows   = $wpdb->get_results($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('zw_receipt_export_issue_status_map')) {
    /**
     * 批量取办理状态（与台账列同一派生口径：待派单 / 逾期 / 办理中 / 已办结）。
     *
     * @param string[] $ids 记录 ID。
     * @return array<string,string>
     */
    function zw_receipt_export_issue_status_map($ids) {
        $map = array();
        if (!function_exists('zw_receipt_ledger_issue_map')) {
            return $map;
        }
        $ids = array_values(array_filter(array_map('strval', (array) $ids)));
        foreach (array_chunk($ids, 200) as $chunk) {
            foreach (zw_receipt_ledger_issue_map($chunk) as $id => $one) {
                $map[(string) $id] = isset($one['status']) ? (string) $one['status'] : '待派单';
            }
        }
        return $map;
    }
}

if (!function_exists('zw_receipt_export_rows')) {
    /**
     * 组装导出记录（表头 => 值），供 CSV 与将来的 Excel 共用。
     *
     * @param array $filters 筛选。
     * @return array{header:string[],rows:array<int,array<int,string>>,truncated:bool}
     */
    function zw_receipt_export_rows($filters) {
        $rows = zw_receipt_export_fetch($filters);
        $ids  = array();
        foreach ($rows as $row) {
            $ids[] = isset($row['id']) ? (string) $row['id'] : '';
        }
        $status_map = zw_receipt_export_issue_status_map($ids);

        $header  = array('收文编号', '来文单位', '来文字号', '标题', '密级', '收文日期', '份数',
            '办理状态', '归档盒号', '归档年度', '页数', '登记时间');
        $out     = array();
        $total   = count($rows);
        foreach ($rows as $row) {
            $view = function_exists('zw_receipt_ledger_row_view') ? zw_receipt_ledger_row_view($row) : array();
            $id   = isset($view['id']) ? (string) $view['id'] : '';
            $out[] = array(
                isset($view['number']) ? (string) $view['number'] : '',
                isset($view['org_label']) ? (string) $view['org_label'] : '',
                isset($view['doc_no']) ? (string) $view['doc_no'] : '',
                isset($view['title']) ? (string) $view['title'] : '',
                isset($view['confidentiality']) ? (string) $view['confidentiality'] : '',
                isset($view['received_date']) ? (string) $view['received_date'] : '',
                (string) (int) (isset($view['copy_count']) ? $view['copy_count'] : 0),
                isset($status_map[$id]) ? (string) $status_map[$id] : '待派单',
                isset($row['archive_box_no']) ? (string) $row['archive_box_no'] : '',
                isset($row['archive_year']) && (int) $row['archive_year'] > 0 ? (string) (int) $row['archive_year'] : '',
                isset($row['page_count']) && (int) $row['page_count'] > 0 ? (string) (int) $row['page_count'] : '',
                isset($view['create_time']) ? (string) $view['create_time'] : '',
            );
        }

        return array(
            'header'    => $header,
            'rows'      => $out,
            'truncated' => $total >= (int) ZW_RECEIPT_EXPORT_MAX_ROWS,
        );
    }
}

if (!function_exists('zw_receipt_export_handle')) {
    /**
     * `admin-post.php?action=zw_receipt_export` 入口：鉴权 → 校验 nonce → 流式吐 CSV。
     *
     * @return void
     */
    function zw_receipt_export_handle() {
        if (function_exists('zw_receipt_ledger_can') && !zw_receipt_ledger_can()) {
            if (function_exists('wp_die')) {
                wp_die('无权限导出收文台账（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
            }
            return;
        }
        $request = array(
            'zw_receipt_org'  => isset($_GET['zw_receipt_org']) ? $_GET['zw_receipt_org'] : '',
            'zw_receipt_year' => isset($_GET['zw_receipt_year']) ? $_GET['zw_receipt_year'] : '',
            'zw_receipt_kw'   => isset($_GET['zw_receipt_kw']) ? $_GET['zw_receipt_kw'] : '',
        );
        $args    = function_exists('zw_receipt_ledger_request_args') ? zw_receipt_ledger_request_args($request) : array();
        $filters = function_exists('zw_receipt_ledger_normalize_filters') ? zw_receipt_ledger_normalize_filters($args) : array();

        if (function_exists('check_admin_referer')) {
            check_admin_referer(zw_receipt_export_nonce_action($args));
        }

        $data     = zw_receipt_export_rows($args);
        $filename = '收文台账-' . date('Ymd-His') . '.csv';
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
        $out = fopen('php://output', 'w');
        if ($out === false) {
            if (function_exists('wp_die')) {
                wp_die('导出失败：无法打开输出流。', '导出失败', array('response' => 500));
            }
            return;
        }
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM：Excel 双击不乱码
        fputcsv($out, $data['header']);
        foreach ($data['rows'] as $row) {
            fputcsv($out, $row);
        }
        if ($data['truncated']) {
            fputcsv($out, array('（已达导出上限 ' . (int) ZW_RECEIPT_EXPORT_MAX_ROWS . ' 行，请缩小筛选范围）'));
        }
        fclose($out);
        exit;
    }
}

if (function_exists('add_action')) {
    add_action('admin_post_zw_receipt_export', 'zw_receipt_export_handle');
}
