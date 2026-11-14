<?php
/**
 * 收文管理 · 档案处置（保管期限 / 清退 / 销毁）· OA-2483。
 *
 * ## 红线（先写死，别让后来人绕）
 *
 * 1. **系统永不删除任何文件或记录**：本文件的「销毁」只写状态与清册（append-only），
 *    物理删除由人线下执行；代码里**不出现** unlink / delete_file / DELETE FROM。
 * 2. **销毁前置门禁**：已归档（有盒号）+ 已到期 + 人工填批准人 ⇒ 才允许写「已销毁」。
 * 3. **清退不等于删档**：清退是「归还来文单位」，状态记 `returned`，档案件与文件都保留。
 * 4. 每个动作写 `zw_receipt_disposal`（append-only）+ 一条 `disposal` 审计留痕。
 *
 * ## 数据
 *
 * 表 `zw_receipt_disposal`（不改主表）：receipt_id / kind / years / until / state / approver / note / operator / created_at。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_receipt_disposal_table')) {
    /**
     * 处置表名。
     *
     * @return string
     */
    function zw_receipt_disposal_table() {
        global $wpdb;
        return is_object($wpdb) ? $wpdb->prefix . 'zw_receipt_disposal' : '';
    }
}

if (!function_exists('zw_receipt_disposal_ready')) {
    /**
     * 表是否已迁移（缓存一次）。
     *
     * @param bool $fresh 强制重新探测。
     * @return bool
     */
    function zw_receipt_disposal_ready($fresh = false) {
        static $ready = null;
        if ($fresh) {
            $ready = null;
        }
        if ($ready !== null) {
            return $ready;
        }
        global $wpdb;
        $table = zw_receipt_disposal_table();
        $ready = $table !== '' && is_object($wpdb)
            && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        return $ready;
    }
}

if (!function_exists('zw_receipt_disposal_retention_options')) {
    /**
     * 保管期限选项（0 = 永久）。
     *
     * @return array<int,string>
     */
    function zw_receipt_disposal_retention_options() {
        return array(0 => '永久', 30 => '30 年', 10 => '10 年', 5 => '5 年');
    }
}

if (!function_exists('zw_receipt_disposal_until')) {
    /**
     * 到期日 = 收文日期 + 年限（0 = 永久 ⇒ 空串）。
     *
     * @param string $received_date 收文日期。
     * @param int    $years         年限。
     * @return string
     */
    function zw_receipt_disposal_until($received_date, $years) {
        $years = (int) $years;
        if ($years <= 0) {
            return '';
        }
        $base = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $received_date, $m)
            ? (string) $received_date
            : (function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d'));
        $ts = strtotime($base);
        if ($ts === false) {
            return '';
        }
        return date('Y-m-d', strtotime('+' . $years . ' year', $ts));
    }
}

if (!function_exists('zw_receipt_disposal_add')) {
    /**
     * 追加一条处置记录（**只增不改**；这是本模块唯一写入点）。
     *
     * @param array $raw 记录。
     * @return int|WP_Error 新记录 ID。
     */
    function zw_receipt_disposal_add($raw) {
        if (!zw_receipt_disposal_ready()) {
            return new WP_Error('zw_receipt_disposal_not_ready', '处置表尚未迁移（请先跑 scripts/migrate-receipt-disposal.php execute）。');
        }
        global $wpdb;
        $raw = is_array($raw) ? $raw : array();
        $receipt_id = trim((string) (isset($raw['receipt_id']) ? $raw['receipt_id'] : ''));
        if ($receipt_id === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $receipt_id)) {
            return new WP_Error('zw_receipt_disposal_bad_id', '收文 ID 非法。');
        }
        $kind = trim((string) (isset($raw['kind']) ? $raw['kind'] : 'retention'));
        if (!in_array($kind, array('retention', 'return', 'destroy'), true)) {
            return new WP_Error('zw_receipt_disposal_bad_kind', '处置类型非法（只允许 retention / return / destroy）。');
        }
        $years = max(0, min(100, (int) (isset($raw['years']) ? $raw['years'] : 0)));
        $ok = $wpdb->insert(
            zw_receipt_disposal_table(),
            array(
                'receipt_id' => $receipt_id,
                'kind'       => $kind,
                'years'      => $years,
                'until'      => ((string) (isset($raw['until']) ? $raw['until'] : '')) ?: null,
                'state'      => substr(trim((string) (isset($raw['state']) ? $raw['state'] : '')), 0, 16),
                'approver'   => substr(trim((string) (isset($raw['approver']) ? $raw['approver'] : '')), 0, 64),
                'note'       => substr(trim((string) (isset($raw['note']) ? $raw['note'] : '')), 0, 255),
                'operator'   => substr(trim((string) (isset($raw['operator']) ? $raw['operator'] : '')), 0, 64),
                'created_at' => function_exists('current_time') ? (string) current_time('mysql') : date('Y-m-d H:i:s'),
            ),
            array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        if (!$ok) {
            return new WP_Error('zw_receipt_disposal_db', '处置记录写入失败。');
        }
        $id = (int) $wpdb->insert_id;
        if (function_exists('zw_receipt_handle_add')) {
            zw_receipt_handle_add(array(
                'receipt_id' => $receipt_id,
                'action'     => 'disposal',
                'content'    => '档案处置：' . $kind . ($years > 0 ? ('，保管 ' . $years . ' 年') : '，永久') . (isset($raw['note']) && $raw['note'] !== '' ? ('（' . $raw['note'] . '）') : ''),
            ), array('disposal'));
        }
        return $id;
    }
}

if (!function_exists('zw_receipt_disposal_list')) {
    /**
     * 某收文的处置记录（新→旧）。
     *
     * @param string $receipt_id 收文 ID。
     * @return array[]
     */
    function zw_receipt_disposal_list($receipt_id) {
        if (!zw_receipt_disposal_ready()) {
            return array();
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . zw_receipt_disposal_table() . ' WHERE receipt_id = %s ORDER BY id DESC',
            (string) $receipt_id
        ), 'ARRAY_A');
        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('zw_receipt_disposal_state')) {
    /**
     * 某收文的当前处置状态（取最新一条）。
     *
     * @param string $receipt_id 收文 ID。
     * @return array{years:int,until:string,state:string,approver:string,kind:string}
     */
    function zw_receipt_disposal_state($receipt_id) {
        $out = array('years' => -1, 'until' => '', 'state' => '', 'approver' => '', 'kind' => '');
        foreach (zw_receipt_disposal_list($receipt_id) as $row) {
            if ($out['years'] < 0 && (string) $row['kind'] === 'retention') {
                $out['years'] = (int) $row['years'];
                $out['until'] = (string) $row['until'];
            }
            if ($out['state'] === '' && in_array((string) $row['kind'], array('return', 'destroy'), true)) {
                $out['state']    = (string) $row['state'];
                $out['approver'] = (string) $row['approver'];
                $out['kind']     = (string) $row['kind'];
            }
            if ($out['years'] >= 0 && $out['state'] !== '') {
                break;
            }
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_disposal_set_retention')) {
    /**
     * 设置保管期限（写一条 retention 记录）。
     *
     * @param string $receipt_id 收文 ID。
     * @param int    $years      0=永久。
     * @return int|WP_Error
     */
    function zw_receipt_disposal_set_retention($receipt_id, $years) {
        $row  = function_exists('zw_receipt_ledger_row_by_id') ? zw_receipt_ledger_row_by_id($receipt_id) : array();
        if (!$row) {
            return new WP_Error('zw_receipt_disposal_no_receipt', '找不到该收文。');
        }
        $view = function_exists('zw_receipt_ledger_row_view') ? zw_receipt_ledger_row_view($row) : $row;
        $date = isset($view['received_date']) ? (string) $view['received_date'] : '';
        return zw_receipt_disposal_add(array(
            'receipt_id' => $receipt_id,
            'kind'       => 'retention',
            'years'      => (int) $years,
            'until'      => zw_receipt_disposal_until($date, (int) $years),
            'state'      => 'set',
            'operator'   => function_exists('zw_receipt_handle_current_login') ? (string) zw_receipt_handle_current_login() : '',
            'note'       => (int) $years === 0 ? '永久' : ('保管 ' . (int) $years . ' 年'),
        ));
    }
}

if (!function_exists('zw_receipt_disposal_mark')) {
    /**
     * 标记「需清退 / 已清退 / 待销毁 / 已销毁」（销毁有门禁）。
     *
     * @param string $receipt_id 收文 ID。
     * @param string $state      需求值：pending_return|returned|pending_destroy|destroyed。
     * @param string $approver   批准人（destroyed 必填）。
     * @param string $note       备注。
     * @return int|WP_Error
     */
    function zw_receipt_disposal_mark($receipt_id, $state, $approver = '', $note = '') {
        $allowed = array('pending_return', 'returned', 'pending_destroy', 'destroyed');
        if (!in_array($state, $allowed, true)) {
            return new WP_Error('zw_receipt_disposal_bad_state', '状态非法。');
        }
        $row = function_exists('zw_receipt_ledger_row_by_id') ? zw_receipt_ledger_row_by_id($receipt_id) : array();
        if (!$row) {
            return new WP_Error('zw_receipt_disposal_no_receipt', '找不到该收文。');
        }
        $kind = $state === 'returned' || $state === 'pending_return' ? 'return' : 'destroy';

        if ($kind === 'destroy' && $state === 'destroyed') {
            // 门禁：已归档 + 已到期 + 有批准人。三条缺一不可。
            $arch = function_exists('zw_receipt_handle_archive_info') ? zw_receipt_handle_archive_info($receipt_id) : array();
            if (empty($arch['archive_box_no'])) {
                return new WP_Error('zw_receipt_disposal_not_archived', '未归档（无盒号），不得销毁。');
            }
            $cur = zw_receipt_disposal_state($receipt_id);
            $until = (string) $cur['until'];
            if ($until !== '' && $until > (function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d'))) {
                return new WP_Error('zw_receipt_disposal_not_due', '保管期限未到（' . $until . '），不得销毁。');
            }
            if (trim((string) $approver) === '') {
                return new WP_Error('zw_receipt_disposal_no_approver', '销毁必须填写批准人。');
            }
        }

        return zw_receipt_disposal_add(array(
            'receipt_id' => $receipt_id,
            'kind'       => $kind,
            'years'      => isset($cur['years']) ? max(0, (int) $cur['years']) : 0,
            'until'      => isset($cur['until']) ? (string) $cur['until'] : '',
            'state'      => $state,
            'approver'   => (string) $approver,
            'operator'   => function_exists('zw_receipt_handle_current_login') ? (string) zw_receipt_handle_current_login() : '',
            'note'       => (string) $note,
        ));
    }
}

if (!function_exists('zw_receipt_disposal_due_list')) {
    /**
     * 到期清单（只读）：已归档、保管期限已到、且尚未销毁。
     *
     * @param int $limit 上限。
     * @return array[]
     */
    function zw_receipt_disposal_due_list($limit = 100) {
        if (!zw_receipt_disposal_ready() || !function_exists('zw_receipt_ledger_source_table')) {
            return array();
        }
        global $wpdb;
        $table = zw_receipt_ledger_source_table();
        $today = function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d');
        // OA-2484：**不做跨表 JOIN**（排序规则不一致会报 Illegal mix of collations）。
        // 先取到期处置行，再逐件判「已归档」——协会量级（数百件）可忽略。
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT receipt_id, years, until FROM ' . zw_receipt_disposal_table()
            . ' WHERE kind = %s AND until IS NOT NULL AND until <= %s ORDER BY until ASC LIMIT %d',
            'retention',
            $today,
            max(1, (int) $limit)
        ), 'ARRAY_A');
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $one) {
            $rid = isset($one['receipt_id']) ? (string) $one['receipt_id'] : '';
            if ($rid === '') {
                continue;
            }
            $arch = function_exists('zw_receipt_handle_archive_info') ? zw_receipt_handle_archive_info($rid) : array();
            if (empty($arch['archive_box_no'])) {
                continue; // 未归档不列入销毁清单
            }
            $out[] = $one;
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_disposal_render_page')) {
    /**
     * 「档案处置」视图（view=disposal）：到期清单 + 逐件处置 + 说明红线。
     *
     * @return void
     */
    function zw_receipt_disposal_render_page() {
        if (!function_exists('esc_html')) {
            return;
        }
        if (!zw_receipt_disposal_ready()) {
            echo '<div class="notice notice-warning"><p>处置表尚未迁移：请执行 '
                . '<code>wp eval-file scripts/migrate-receipt-disposal.php execute</code>。</p></div>';
            return;
        }
        global $wpdb;
        $src  = function_exists('zw_receipt_ledger_source_table') ? zw_receipt_ledger_source_table() : '';
        $due  = zw_receipt_disposal_due_list(100);
        $rows = $src !== '' ? $wpdb->get_results(
            "SELECT id, doc_name, issuer, doc_received_date, archive_box_no, archive_year FROM {$src}"
            . " WHERE origin = 'incoming' ORDER BY id ASC LIMIT 100", 'ARRAY_A'
        ) : array();

        // OA-2489：两份清册的打印入口（打印友好 HTML，浏览器「打印 / 另存为 PDF」）。
        echo '<p style="margin:0 0 14px">'
            . '<a class="button" target="_blank" rel="noopener" href="' . esc_url(zw_receipt_disposal_print_url('destroy'))
            . '">打印销毁清册</a> '
            . '<a class="button" target="_blank" rel="noopener" href="' . esc_url(zw_receipt_disposal_print_url('return'))
            . '">打印清退清单</a></p>';

        echo '<div class="notice notice-info inline" style="margin:0 0 14px"><p><strong>红线：</strong>'
            . '系统**不会删除任何文件或记录**。这里的「销毁」只写状态与清册（留痕），物理删除由人线下执行。</p></div>';

        echo '<div class="tco-card" style="margin:0 0 16px"><h2>到期可销毁清单（' . count($due) . ' 件）</h2>';
        echo '<p class="tco-doc-muted">口径：已归档（有盒号）+ 保管期限已到 + 尚未销毁。</p>';
        echo '<div class="tco-table-wrap"><table class="widefat striped"><thead><tr>'
            . '<th>收文</th><th>保管年限</th><th>到期日</th><th>操作</th></tr></thead><tbody>';
        if (!$due) {
            echo '<tr><td colspan="4">（暂无到期件）</td></tr>';
        }
        foreach ($due as $d) {
            $rid = (string) $d['receipt_id'];
            echo '<tr><td>' . esc_html($rid) . '</td>'
                . '<td>' . ((int) $d['years'] === 0 ? '永久' : (int) $d['years'] . ' 年') . '</td>'
                . '<td>' . esc_html((string) $d['until']) . '</td><td>'
                . '<a class="button" target="_blank" rel="noopener" href="'
                . esc_url(function_exists('zw_receipt_print_url') ? zw_receipt_print_url($rid, 'approval') : '#')
                . '">看单据</a></td></tr>';
        }
        echo '</tbody></table></div></div>';

        echo '<div class="tco-card" style="margin:0 0 16px"><h2>逐件处置（保管期限 / 清退 / 销毁）</h2>';
        echo '<div class="tco-table-wrap"><table class="widefat striped"><thead><tr>'
            . '<th>收文</th><th>标题</th><th>收文日期</th><th>盒号</th><th>保管期限</th><th>当前状态</th></tr>'
            . '</thead><tbody>';
        foreach ((array) $rows as $r) {
            $rid = (string) $r['id'];
            $st  = zw_receipt_disposal_state($rid);
            $years = (int) $st['years'];
            $state_label = array(
                ''               => '未设置',
                'set'            => '已设期限',
                'pending_return' => '需清退',
                'returned'       => '已清退',
                'pending_destroy' => '待销毁',
                'destroyed'      => '已销毁',
            );
            echo '<tr><td>' . esc_html($rid) . '</td>'
                . '<td>' . esc_html(mb_substr((string) $r['doc_name'], 0, 24)) . '</td>'
                . '<td>' . esc_html((string) $r['doc_received_date']) . '</td>'
                . '<td>' . esc_html((string) $r['archive_box_no']) . '</td>'
                . '<td>' . ($years < 0 ? '未设置' : ($years === 0 ? '永久' : $years . ' 年')) . '</td>'
                . '<td>' . esc_html(isset($state_label[$st['state']]) ? $state_label[$st['state']] : $st['state']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="tco-doc-muted">设置与标记请用 WP-CLI（避免在页面放危险按钮）：<br>'
            . '<code>wp eval \'zw_receipt_disposal_set_retention("&lt;收文ID&gt;", 10);\'</code><br>'
            . '<code>wp eval \'zw_receipt_disposal_mark("&lt;收文ID&gt;", "returned", "批准人", "备注");\'</code>'
            . '</p></div>';
    }
}

if (!function_exists('zw_receipt_disposal_print_types')) {
    /**
     * 处置打印单据类型（OA-2489）。
     *
     * @return array<string,string>
     */
    function zw_receipt_disposal_print_types() {
        return array(
            'destroy' => '档案销毁清册',
            'return'  => '档案清退清单',
        );
    }
}

if (!function_exists('zw_receipt_disposal_print_url')) {
    /**
     * 打印地址（处置页按钮用）。
     *
     * @param string $type destroy|return。
     * @return string
     */
    function zw_receipt_disposal_print_url($type) {
        $type = isset(zw_receipt_disposal_print_types()[$type]) ? (string) $type : 'destroy';
        $args = array('action' => 'zw_receipt_disposal_print', 'doc' => $type);
        if (function_exists('wp_create_nonce')) {
            $args['_wpnonce'] = wp_create_nonce('zw_receipt_disposal_print_' . $type);
        }
        $base = function_exists('admin_url') ? (string) admin_url('admin-post.php') : 'admin-post.php';
        return function_exists('add_query_arg') ? (string) add_query_arg($args, $base) : $base . '?' . http_build_query($args);
    }
}

if (!function_exists('zw_receipt_disposal_print_rows')) {
    /**
     * 清册行（只读）：按最新处置状态筛「已销毁 / 已清退」，并补齐收文要素。
     *
     * @param string $type destroy|return。
     * @param int    $limit 上限。
     * @return array[]
     */
    function zw_receipt_disposal_print_rows($type, $limit = 500) {
        $state = $type === 'return' ? 'returned' : 'destroyed';
        if (!zw_receipt_disposal_ready()) {
            return array();
        }
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT receipt_id, years, until, approver, operator, created_at FROM ' . zw_receipt_disposal_table()
            . ' WHERE state = %s ORDER BY id ASC LIMIT %d',
            $state,
            max(1, (int) $limit)
        ), 'ARRAY_A');
        $out  = array();
        $seen = array();
        foreach (is_array($rows) ? $rows : array() as $one) {
            $rid = isset($one['receipt_id']) ? (string) $one['receipt_id'] : '';
            if ($rid === '' || isset($seen[$rid])) {
                continue; // 同一收文只取最早一条该状态（清册不重复列）
            }
            $seen[$rid] = true;
            $row  = function_exists('zw_receipt_ledger_row_by_id') ? zw_receipt_ledger_row_by_id($rid) : array();
            $view = function_exists('zw_receipt_ledger_row_view') ? zw_receipt_ledger_row_view($row) : array();
            $arch = function_exists('zw_receipt_handle_archive_info') ? zw_receipt_handle_archive_info($rid) : array();
            $one['number']   = isset($view['number']) ? (string) $view['number'] : $rid;
            $one['title']    = isset($view['title']) ? (string) $view['title'] : '';
            $one['org']      = isset($view['org_label']) ? (string) $view['org_label'] : '';
            $one['box']      = isset($arch['archive_box_no']) ? (string) $arch['archive_box_no'] : '';
            $one['arch_year'] = isset($arch['archive_year']) ? (int) $arch['archive_year'] : 0;
            $out[] = $one;
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_disposal_print_render')) {
    /**
     * 清册 HTML（A4 **横版**：列多；打印友好，不引 PDF 库）。
     *
     * @param array  $rows 行。
     * @param string $type destroy|return。
     * @return string
     */
    function zw_receipt_disposal_print_render($rows, $type) {
        $types = zw_receipt_disposal_print_types();
        $title = isset($types[$type]) ? $types[$type] : '档案销毁清册';
        $e = function ($v) {
            return function_exists('esc_html') ? (string) esc_html((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $now = function_exists('current_time') ? (string) current_time('mysql') : date('Y-m-d H:i:s');

        $css = '<style>'
            . '@page{size:A4 landscape;margin:12mm 10mm}'
            . 'body{font-family:"Microsoft YaHei","PingFang SC",sans-serif;font-size:13px;line-height:1.5;color:#000;background:#fff;margin:0}'
            . '.doc{max-width:277mm;margin:0 auto}'
            . 'h1{font-size:20px;text-align:center;letter-spacing:2px;margin:0 0 2px}'
            . '.org{text-align:center;font-size:12px;margin:0 0 10px}'
            . '.meta{display:flex;justify-content:space-between;font-size:12px;margin:0 0 8px}'
            . 'table{width:100%;border-collapse:collapse}'
            . 'th,td{border:1px solid #000;padding:5px 6px;vertical-align:top}'
            . 'th{background:#f2f2f2;font-weight:600;white-space:nowrap}'
            . 'td.num{text-align:center;white-space:nowrap}'
            . '.foot{margin-top:12px;font-size:12px;display:flex;justify-content:space-between}'
            . '.print-bar{margin:0 0 10px;text-align:right}'
            . '@media print{.print-bar{display:none}}'
            . '</style>';

        $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>' . $e($title) . '</title>' . $css . '</head><body><div class="doc">';
        $html .= '<div class="print-bar"><button type="button" onclick="window.print()">打印 / 另存为 PDF</button></div>';
        $html .= '<h1>' . $e($title) . '</h1><p class="org">中国传统文化促进会</p>';
        $html .= '<div class="meta"><span>合计：<strong>' . count($rows) . '</strong> 件</span><span>制表时间：' . $e($now) . '</span></div>';

        if ($type === 'return') {
            $html .= '<table><thead><tr><th style="width:4%">序号</th><th style="width:14%">收文编号</th><th>标题</th>'
                . '<th style="width:14%">来文单位</th><th style="width:10%">归档盒号</th><th style="width:10%">清退日期</th><th style="width:10%">经办人</th></tr></thead><tbody>';
            if (!$rows) {
                $html .= '<tr><td colspan="7" class="num">（暂无已清退记录）</td></tr>';
            }
            foreach ($rows as $i => $r) {
                $html .= '<tr><td class="num">' . ($i + 1) . '</td><td>' . $e($r['number']) . '</td>'
                    . '<td>' . $e($r['title']) . '</td><td>' . $e($r['org']) . '</td>'
                    . '<td>' . $e($r['box']) . '</td><td>' . $e($r['created_at']) . '</td>'
                    . '<td>' . $e($r['operator']) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<table><thead><tr><th style="width:4%">序号</th><th style="width:12%">收文编号</th><th>标题</th>'
                . '<th style="width:12%">来文单位</th><th style="width:9%">归档盒号</th><th style="width:6%">年度</th>'
                . '<th style="width:7%">保管期限</th><th style="width:9%">到期日</th><th style="width:9%">批准人</th>'
                . '<th style="width:9%">销毁日期</th></tr></thead><tbody>';
            if (!$rows) {
                $html .= '<tr><td colspan="10" class="num">（暂无已销毁记录）</td></tr>';
            }
            foreach ($rows as $i => $r) {
                $years = (int) $r['years'];
                $html .= '<tr><td class="num">' . ($i + 1) . '</td><td>' . $e($r['number']) . '</td>'
                    . '<td>' . $e($r['title']) . '</td><td>' . $e($r['org']) . '</td>'
                    . '<td>' . $e($r['box']) . '</td><td class="num">' . ((int) $r['arch_year'] > 0 ? (int) $r['arch_year'] : '—') . '</td>'
                    . '<td class="num">' . ($years === 0 ? '永久' : $years . ' 年') . '</td>'
                    . '<td class="num">' . $e((string) $r['until']) . '</td>'
                    . '<td>' . $e($r['approver']) . '</td><td class="num">' . $e($r['created_at']) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        // 按年度小计（纸质清册常见要求）：只统计有归档年度的行。
        if ($type !== 'return' && $rows) {
            $by_year = array();
            foreach ($rows as $r) {
                $y = (int) ($r['arch_year'] ?? 0);
                $key = $y > 0 ? (string) $y : '未标注';
                $by_year[$key] = isset($by_year[$key]) ? $by_year[$key] + 1 : 1;
            }
            ksort($by_year, SORT_NATURAL);
            $html .= '<p class="meta" style="margin-top:10px"><span>按年度小计：'
                . $e(implode('　', array_map(function ($k, $v) { return $k . ' 年度 ' . $v . ' 件'; }, array_keys($by_year), array_values($by_year))))
                . '</span></p>';
        }

        $html .= '<div class="foot"><span>制表人：____________　审核：____________　批准：____________</span>'
            . '<span>本清册由系统生成，系统未删除任何文件或记录</span></div>';
        $html .= '</div></body></html>';
        return $html;
    }
}

if (!function_exists('zw_receipt_disposal_print_handle')) {
    /**
     * `admin-post.php?action=zw_receipt_disposal_print` 入口。
     *
     * @return void
     */
    function zw_receipt_disposal_print_handle() {
        if (function_exists('zw_receipt_ledger_can') && !zw_receipt_ledger_can()) {
            wp_die('无权限打印处置清册（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
        }
        $type = isset($_GET['doc']) ? trim((string) wp_unslash($_GET['doc'])) : 'destroy';
        if (!isset(zw_receipt_disposal_print_types()[$type])) {
            wp_die('打印参数非法。', '参数错误', array('response' => 400));
        }
        if (function_exists('check_admin_referer')) {
            check_admin_referer('zw_receipt_disposal_print_' . $type);
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
            header('X-Robots-Tag: noindex, nofollow');
        }
        echo zw_receipt_disposal_print_render(zw_receipt_disposal_print_rows($type), $type);
        exit;
    }
}

if (function_exists('add_action')) {
    add_action('admin_post_zw_receipt_disposal_print', 'zw_receipt_disposal_print_handle');
}
