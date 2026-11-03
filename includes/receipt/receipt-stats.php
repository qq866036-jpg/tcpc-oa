<?php
/**
 * 收文管理 · 统计报表（OA-2475）· **纯只读**。
 *
 * ## 统计口径（页面与导出**同源**，只在本文件定义一次）
 *
 * | 指标 | 口径 |
 * |---|---|
 * | 收文总量 | `origin='incoming'` 且不在自产 4 id 白名单内的行数 |
 * | 按部委 | `issuer` 字典名分组计数 |
 * | 按年度 | 收文日期前 4 位分组计数（非法/空的归「未标注」） |
 * | 按密级 | `confidentiality_level` 分组计数（空归「未标注」） |
 * | 已归档 / 归档率 | `archive_box_no` 非空的行数 ÷ 总量 |
 * | 办理单状态分布 | `zw_policy_issue`（`source_type='incoming'`）按 status 分组 |
 * | 逾期 | 未收口（status ∉ 已办结/归档）且 `due_date` < 今天 |
 * | 待批示 | 有拟办、无批示的收文（与 `zw_receipt_handle_pending_instruction()` 同口径） |
 *
 * 只读：全部是 SELECT 聚合；不写任何表（导出也只读）。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_receipt_stats_filters')) {
    /**
     * 统计筛选（年度 + 部委；空=全部）。
     *
     * @param array $request 请求数组（默认 $_GET）。
     * @return array{year:string,org:string}
     */
    function zw_receipt_stats_filters($request = null) {
        $request = is_array($request) ? $request : (isset($_GET) && is_array($_GET) ? $_GET : array());
        $year    = isset($request['zw_stats_year']) ? preg_replace('/\D/', '', (string) wp_unslash($request['zw_stats_year'])) : '';
        $org     = isset($request['zw_stats_org']) ? sanitize_key((string) wp_unslash($request['zw_stats_org'])) : '';
        return array('year' => substr($year, 0, 4), 'org' => $org);
    }
}

if (!function_exists('zw_receipt_stats_where')) {
    /**
     * 统计 WHERE 片段（与台账一致的 origin 约束 + 自产 id 白名单）。
     *
     * @param array $filters 筛选。
     * @return array{sql:string,params:array<int,string>}
     */
    function zw_receipt_stats_where($filters) {
        $filters = is_array($filters) ? $filters : array();
        $clauses = array('origin = %s');
        $params  = array('incoming');
        $protected = function_exists('zw_receipt_ledger_self_protected_ids') ? zw_receipt_ledger_self_protected_ids() : array();
        $list = array();
        foreach ((array) $protected as $one) {
            $one = trim((string) $one);
            if ($one !== '') {
                $list[] = "'" . str_replace("'", '', $one) . "'";
            }
        }
        if ($list) {
            $clauses[] = 'id NOT IN (' . implode(', ', $list) . ')';
        }
        if (!empty($filters['org'])) {
            $clauses[] = 'issuer = %s';
            $params[]  = (string) $filters['org'];
        }
        if (!empty($filters['year'])) {
            $clauses[] = 'doc_received_date LIKE %s';
            $params[]  = (string) $filters['year'] . '%';
        }
        return array('sql' => implode(' AND ', $clauses), 'params' => $params);
    }
}

if (!function_exists('zw_receipt_stats_org_label')) {
    /**
     * 来文单位显示名（OA-2479）：**只走 canonical 反查**，不再自写第二套映射。
     *
     * 唯一出处：`zw_receipt_org_key_from_issuer()`（TASK-1297，关键字表 + 过滤器
     * `zw_receipt_org_issuer_map`）；命中则取字典中文名，未命中保留原值并标「未登记单位」。
     *
     * @param string $issuer     issuer 原值（可能是 key / 中文名 / 中文名带后缀）。
     * @param array  $org_labels key => 中文名。
     * @return string
     */
    function zw_receipt_stats_org_label($issuer, $org_labels) {
        $issuer     = trim((string) $issuer);
        $org_labels = is_array($org_labels) ? $org_labels : array();
        if ($issuer === '') {
            return '未标注';
        }
        $key = function_exists('zw_receipt_org_key_from_issuer') ? (string) zw_receipt_org_key_from_issuer($issuer) : '';
        if ($key !== '' && isset($org_labels[$key]) && (string) $org_labels[$key] !== '') {
            return (string) $org_labels[$key];
        }
        if (isset($org_labels[$issuer]) && (string) $org_labels[$issuer] !== '') {
            return (string) $org_labels[$issuer];
        }
        return '未登记单位（' . $issuer . '）';
    }
}

if (!function_exists('zw_receipt_stats_collect')) {
    /**
     * 采集统计（一次调用返回页面与导出共用的数据结构）。
     *
     * @param array $filters 筛选。
     * @return array<string,mixed>
     */
    function zw_receipt_stats_collect($filters) {
        global $wpdb;
        $out = array(
            'total'       => 0,
            'by_org'      => array(),
            'by_year'     => array(),
            'by_conf'     => array(),
            'by_status'   => array(),
            'archived'    => 0,
            'overdue'     => 0,
            'pending'     => 0,
            'issues_total' => 0,
            'filters'     => $filters,
        );
        if (!is_object($wpdb) || !function_exists('zw_receipt_ledger_source_table')) {
            return $out;
        }
        $table = zw_receipt_ledger_source_table();
        if ($table === '') {
            return $out;
        }
        $where = zw_receipt_stats_where($filters);

        $run = function ($sql, $params) use ($wpdb) {
            $prepared = function_exists('zw_receipt_prepare_sql') ? zw_receipt_prepare_sql($sql, $params) : $wpdb->prepare($sql, $params);
            return $wpdb->get_results($prepared, 'ARRAY_A');
        };

        $out['total'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}",
            $where['params']
        ));

        $org_labels = function_exists('zw_receipt_org_options') ? (array) zw_receipt_org_options() : array();
        $rows = $run("SELECT issuer, COUNT(*) cnt FROM {$table} WHERE {$where['sql']} GROUP BY issuer ORDER BY cnt DESC", $where['params']);
        foreach ((array) $rows as $one) {
            $key   = isset($one['issuer']) ? (string) $one['issuer'] : '';
            $label = zw_receipt_stats_org_label($key, $org_labels);
            // OA-2480：同一个 label 可能由多个 issuer 变体汇入（如 cssw 与 中央社会工作部办公厅），必须**累加**。
            $out['by_org'][$label] = (isset($out['by_org'][$label]) ? (int) $out['by_org'][$label] : 0) + (int) $one['cnt'];
        }

        $rows = $run("SELECT SUBSTR(doc_received_date, 1, 4) y, COUNT(*) c FROM {$table} WHERE {$where['sql']} GROUP BY y ORDER BY y DESC", $where['params']);
        foreach ((array) $rows as $one) {
            $y = isset($one['y']) ? (string) $one['y'] : '';
            $out['by_year'][preg_match('/^\d{4}$/', $y) ? $y : '未标注'] = (int) $one['c'];
        }

        $rows = $run("SELECT confidentiality_level l, COUNT(*) c FROM {$table} WHERE {$where['sql']} GROUP BY l ORDER BY c DESC", $where['params']);
        foreach ((array) $rows as $one) {
            $l = isset($one['l']) ? (string) $one['l'] : '';
            $out['by_conf'][$l === '' ? '未标注' : $l] = (int) $one['c'];
        }

        $cols = function_exists('zw_receipt_source_columns') ? (array) zw_receipt_source_columns() : array();
        if (isset($cols['archive_box_no'])) {
            $out['archived'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']} AND archive_box_no IS NOT NULL AND archive_box_no <> ''",
                $where['params']
            ));
        }

        if (function_exists('zw_receipt_issue_table')) {
            $issues = zw_receipt_issue_table();
            if ($issues !== '') {
                $issue_sql = "SELECT status, COUNT(*) c FROM {$issues} WHERE source_type = 'incoming' GROUP BY status";
                $rows      = $wpdb->get_results($issue_sql, 'ARRAY_A');
                foreach ((array) $rows as $one) {
                    $s = isset($one['status']) ? (string) $one['status'] : '';
                    $out['by_status'][$s === '' ? '未标注' : $s] = (int) $one['c'];
                    $out['issues_total'] += (int) $one['c'];
                }
                $today = function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d');
                $out['overdue'] = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$issues} WHERE source_type = %s AND status NOT IN (%s, %s)"
                    . " AND due_date <> '' AND due_date < %s",
                    'incoming',
                    '已办结',
                    '归档',
                    $today
                ));
            }
        }

        // 待批示：有拟办、无批示（逐件判定，口径与详情页角标一致）。
        if (function_exists('zw_receipt_handle_ready') && zw_receipt_handle_ready() && function_exists('zw_receipt_handle_list')) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE {$where['sql']}", $where['params']));
            foreach ((array) $ids as $id) {
                $has_suggest = false;
                $has_instr   = false;
                foreach ((array) zw_receipt_handle_list((string) $id) as $log) {
                    $a = isset($log['action']) ? (string) $log['action'] : '';
                    if ($a === 'suggest') {
                        $has_suggest = true;
                    } elseif ($a === 'instruction') {
                        $has_instr = true;
                    }
                }
                if ($has_suggest && !$has_instr) {
                    $out['pending']++;
                }
            }
        }

        return $out;
    }
}

if (!function_exists('zw_receipt_stats_url')) {
    /**
     * 统计页地址（保留筛选）。
     *
     * @param array $filters 筛选。
     * @return string
     */
    function zw_receipt_stats_url($filters = array()) {
        $args = array('page' => 'zw-receipt-ledger', 'view' => 'stats');
        if (!empty($filters['year'])) {
            $args['zw_stats_year'] = (string) $filters['year'];
        }
        if (!empty($filters['org'])) {
            $args['zw_stats_org'] = (string) $filters['org'];
        }
        $base = function_exists('admin_url') ? (string) admin_url('admin.php') : 'admin.php';
        return function_exists('add_query_arg') ? (string) add_query_arg($args, $base) : $base . '?' . http_build_query($args);
    }
}

if (!function_exists('zw_receipt_stats_export_url')) {
    /**
     * 统计导出地址（CSV，按当前筛选）。
     *
     * @param array $filters 筛选。
     * @return string
     */
    function zw_receipt_stats_export_url($filters = array()) {
        $args = array('action' => 'zw_receipt_stats_export');
        if (!empty($filters['year'])) {
            $args['zw_stats_year'] = (string) $filters['year'];
        }
        if (!empty($filters['org'])) {
            $args['zw_stats_org'] = (string) $filters['org'];
        }
        if (function_exists('wp_create_nonce')) {
            $args['_wpnonce'] = wp_create_nonce('zw_receipt_stats_export');
        }
        $base = function_exists('admin_url') ? (string) admin_url('admin-post.php') : 'admin-post.php';
        return function_exists('add_query_arg') ? (string) add_query_arg($args, $base) : $base . '?' . http_build_query($args);
    }
}

if (!function_exists('zw_receipt_stats_bars')) {
    /**
     * 简单横条（用共享令牌色，不引图表库）。
     *
     * @param array  $data  label => count。
     * @param int    $max  最大值（0=自动）。
     * @return string
     */
    function zw_receipt_stats_bars($data, $max = 0) {
        $data = is_array($data) ? $data : array();
        if (!$data) {
            return '<p class="zw-doc-muted">（无数据）</p>';
        }
        $max = $max > 0 ? $max : max(array_map('intval', array_values($data)));
        $max = $max > 0 ? $max : 1;
        $html = '<table class="widefat striped"><tbody>';
        foreach ($data as $label => $count) {
            $pct  = (int) round(((int) $count / $max) * 100);
            $html .= '<tr><td style="width:26%">' . zw_receipt_print_esc((string) $label) . '</td>'
                . '<td style="width:58%"><span style="display:inline-block;height:10px;border-radius:3px;'
                . 'background:var(--zw-adm-accent,#2271b1);width:' . $pct . '%"></span></td>'
                . '<td style="width:16%;text-align:right">' . (int) $count . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }
}

if (!function_exists('zw_receipt_stats_render')) {
    /**
     * 统计页主体（挂 `view=stats`）。
     *
     * @return void
     */
    function zw_receipt_stats_render() {
        if (!function_exists('esc_html')) {
            return;
        }
        $filters = zw_receipt_stats_filters();
        $stats   = zw_receipt_stats_collect($filters);
        $orgs    = function_exists('zw_receipt_org_options') ? zw_receipt_org_options() : array();
        $years   = array_keys((array) $stats['by_year']);
        sort($years);

        echo '<form method="get" action="' . esc_url(function_exists('admin_url') ? admin_url('admin.php') : 'admin.php') . '" class="zw-adm-filters">';
        echo '<input type="hidden" name="page" value="zw-receipt-ledger"><input type="hidden" name="view" value="stats">';
        echo '<label>年度 <select name="zw_stats_year"><option value="">全部</option>';
        foreach ($years as $y) {
            if (!preg_match('/^\d{4}$/', (string) $y)) {
                continue;
            }
            echo '<option value="' . esc_attr($y) . '"' . ((string) $filters['year'] === (string) $y ? ' selected' : '') . '>' . esc_html($y) . '</option>';
        }
        echo '</select></label>';
        echo '<label>来文单位 <select name="zw_stats_org"><option value="">全部</option>';
        foreach ($orgs as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . ($filters['org'] === (string) $key ? ' selected' : '') . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<button type="submit" class="button">筛选</button>';
        echo ' <a class="button" href="' . esc_url(zw_receipt_stats_export_url($filters)) . '">导出统计 CSV</a>';
        echo '</form>';

        $rate = $stats['total'] > 0 ? round($stats['archived'] * 100 / $stats['total'], 1) : 0;
        echo '<div class="zw-adm-grid is-4" style="margin:12px 0 16px">';
        foreach (array(
            array('收文总量', (string) $stats['total']),
            array('已归档（归档率）', $stats['archived'] . '（' . $rate . '%）'),
            array('逾期办理单', (string) $stats['overdue']),
            array('待批示', (string) $stats['pending']),
        ) as $card) {
            echo '<div class="zw-adm-card" style="margin:0"><div class="zw-doc-muted">' . esc_html($card[0]) . '</div>'
                . '<div style="font-size:26px;font-weight:700;margin-top:6px">' . esc_html($card[1]) . '</div></div>';
        }
        echo '</div>';

        echo '<div class="zw-adm-card" style="margin:0 0 16px"><h2>按来文单位</h2>' . zw_receipt_stats_bars($stats['by_org']) . '</div>';
        echo '<div class="zw-adm-card" style="margin:0 0 16px"><h2>按年度</h2>' . zw_receipt_stats_bars($stats['by_year']) . '</div>';
        echo '<div class="zw-adm-grid is-2">';
        echo '<div class="zw-adm-card" style="margin:0"><h2>按密级</h2>' . zw_receipt_stats_bars($stats['by_conf']) . '</div>';
        echo '<div class="zw-adm-card" style="margin:0"><h2>办理单状态分布</h2>' . zw_receipt_stats_bars($stats['by_status']) . '</div>';
        echo '</div>';
        echo '<p class="zw-doc-muted" style="margin-top:14px">口径：逾期=未收口且时限早于今天；待批示=有拟办无批示；'
            . '归档率=已写归档盒号的收文 ÷ 收文总量。数据实时读取，不做缓存。</p>';
        echo '<p class="zw-doc-muted">显示为「未登记单位（key）」的，说明该来文单位还没在字典里填中文名 —— '
            . '去「收文管理 → 来文单位」补一个名称即可自动恢复。</p>';
    }
}

if (!function_exists('zw_receipt_stats_export_handle')) {
    /**
     * 统计 CSV 导出（`admin-post.php?action=zw_receipt_stats_export`）。
     *
     * @return void
     */
    function zw_receipt_stats_export_handle() {
        if (function_exists('zw_receipt_ledger_can') && !zw_receipt_ledger_can()) {
            wp_die('无权限导出收文统计（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
        }
        if (function_exists('check_admin_referer')) {
            check_admin_referer('zw_receipt_stats_export');
        }
        $filters = zw_receipt_stats_filters();
        $stats   = zw_receipt_stats_collect($filters);
        $rows    = array(
            array('指标', '维度', '数值'),
            array('收文总量', '全部', (string) $stats['total']),
            array('已归档', '全部', (string) $stats['archived']),
            array('归档率', '全部', ($stats['total'] > 0 ? round($stats['archived'] * 100 / $stats['total'], 1) : 0) . '%'),
            array('逾期办理单', '全部', (string) $stats['overdue']),
            array('待批示', '全部', (string) $stats['pending']),
        );
        foreach (array('by_org' => '来文单位', 'by_year' => '年度', 'by_conf' => '密级', 'by_status' => '办理状态') as $key => $label) {
            foreach ((array) $stats[$key] as $dim => $n) {
                $rows[] = array($label, (string) $dim, (string) $n);
            }
        }
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="收文统计-' . date('Ymd-His') . '.csv"');
            header('Cache-Control: no-store');
        }
        $out = fopen('php://output', 'w');
        if ($out === false) {
            wp_die('导出失败：无法打开输出流。', '导出失败', array('response' => 500));
        }
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}

if (function_exists('add_action')) {
    add_action('admin_post_zw_receipt_stats_export', 'zw_receipt_stats_export_handle');
}
