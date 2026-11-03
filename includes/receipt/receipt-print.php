<?php
/**
 * 收文管理 · 呈批单 / 传阅卡打印（OA-2471）。
 *
 * ## 形态
 *
 * **打印友好 HTML + `@media print`**，走浏览器原生「打印 / 另存为 PDF」：
 * 不引第三方 PDF 库（字体、中文换行、服务器依赖都是坑），也不生成需要落盘的文件。
 *
 * 两张单据：
 *   · `approval`    呈批单（收文处理单）：来文要素 + 拟办意见 + 领导批示 + 办理单 + 传阅登记
 *   · `circulation` 传阅卡（收文传阅单）：来文要素简表 + 传阅对象/签阅时间 + 手写签批栏
 *
 * ## 边界
 *
 * - 鉴权：`tcpc_perm_doc` + 按「记录 ID + 单据类型」绑定的 nonce（防手拼链接批量拉）；
 * - **不含 OCR 正文、不含个人信息明细**：只有台账要素与内部办理留痕；
 * - **只读**：不写任何表、不改任何状态；
 * - 打印样式内联（本页 `<style>`），不新增全局 CSS 文件。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_receipt_print_types')) {
    /**
     * 单据类型白名单。
     *
     * @return array<string,string>
     */
    function zw_receipt_print_types() {
        return array(
            'approval'    => '收文处理单',
            'circulation' => '收文传阅单',
        );
    }
}

if (!function_exists('zw_receipt_print_nonce')) {
    /**
     * 打印 nonce action（记录 + 单据类型绑定）。
     *
     * @param string $id   记录 ID。
     * @param string $type 单据类型。
     * @return string
     */
    function zw_receipt_print_nonce($id, $type) {
        return 'zw_receipt_print_' . (string) $type . '_' . (string) $id;
    }
}

if (!function_exists('zw_receipt_print_url')) {
    /**
     * 打印地址（详情页按钮用；非 admin-post 之外不暴露）。
     *
     * @param string $id   记录 ID。
     * @param string $type 单据类型（approval|circulation）。
     * @return string
     */
    function zw_receipt_print_url($id, $type) {
        $id   = trim((string) $id);
        $type = isset(zw_receipt_print_types()[$type]) ? (string) $type : 'approval';
        $args = array(
            'action' => 'zw_receipt_print',
            'id'     => $id,
            'doc'    => $type,
        );
        if (function_exists('wp_create_nonce')) {
            $args['_wpnonce'] = wp_create_nonce(zw_receipt_print_nonce($id, $type));
        }
        $base = function_exists('admin_url') ? (string) admin_url('admin-post.php') : 'admin-post.php';
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($args, $base);
        }
        return $base . '?' . http_build_query($args);
    }
}

if (!function_exists('zw_receipt_print_rows_for')) {
    /**
     * 单据数据：来文要素 + 办理留痕 + 办理单 + 传阅。
     *
     * @param string $id 记录 ID。
     * @return array
     */
    function zw_receipt_print_rows_for($id) {
        $row  = function_exists('zw_receipt_ledger_row_by_id') ? zw_receipt_ledger_row_by_id($id) : array();
        if (!$row) {
            return array();
        }
        $view = function_exists('zw_receipt_ledger_row_view') ? zw_receipt_ledger_row_view($row) : $row;

        $logs = function_exists('zw_receipt_handle_list') ? zw_receipt_handle_list($id) : array();
        $suggest = array();
        $instruction = array();
        $sign = '';
        foreach ($logs as $one) {
            $action = isset($one['action']) ? (string) $one['action'] : '';
            if ($action === 'suggest') {
                $suggest[] = $one;
            } elseif ($action === 'instruction') {
                $instruction[] = $one;
            } elseif ($action === 'sign' && $sign === '') {
                $sign = isset($one['created_at']) ? (string) $one['created_at'] : '';
            }
        }

        $issues = array();
        if (function_exists('zw_receipt_issue_query')) {
            $res    = zw_receipt_issue_query(array('source_id' => $id, 'per_page' => 50));
            $issues = isset($res['rows']) && is_array($res['rows']) ? $res['rows'] : array();
        }
        $circ = function_exists('zw_receipt_circ_list') ? zw_receipt_circ_list($id) : array();
        $archive = function_exists('zw_receipt_handle_archive_info') ? zw_receipt_handle_archive_info($id) : array();

        return array(
            'view'        => $view,
            'suggest'     => $suggest,
            'instruction' => $instruction,
            'sign'        => $sign,
            'issues'      => $issues,
            'circ'        => $circ,
            'archive'     => is_array($archive) ? $archive : array(),
        );
    }
}

if (!function_exists('zw_receipt_print_esc')) {
    /**
     * 转义（无 WP 环境退化）。
     *
     * @param mixed $value 值。
     * @return string
     */
    function zw_receipt_print_esc($value) {
        if (function_exists('esc_html')) {
            return (string) esc_html((string) $value);
        }
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('zw_receipt_print_css')) {
    /**
     * 打印版式（A4 竖版；纸质可读性优先：正文 14px / 行高 1.6 / 表格线 1px）。
     *
     * @return string
     */
    function zw_receipt_print_css() {
        return '<style>'
            . '@page{size:A4 portrait;margin:15mm 14mm}'
            . 'html,body{margin:0;padding:0;background:#fff;color:#000}'
            . 'body{font-family:"Microsoft YaHei","PingFang SC","Noto Sans CJK SC",sans-serif;font-size:14px;line-height:1.6}'
            . '.doc{width:100%;max-width:190mm;margin:0 auto}'
            . '.doc h1{font-size:20px;text-align:center;letter-spacing:2px;margin:0 0 4px}'
            . '.doc .org{text-align:center;font-size:13px;color:#333;margin:0 0 14px}'
            . '.doc .meta{display:flex;justify-content:space-between;font-size:12px;color:#333;margin:0 0 10px}'
            . 'table{width:100%;border-collapse:collapse;margin:0 0 12px}'
            . 'th,td{border:1px solid #000;padding:6px 8px;vertical-align:top;font-size:13px}'
            . 'th{background:#f2f2f2;font-weight:600;text-align:left;white-space:nowrap}'
            . 'td.label{width:16%;background:#fafafa;font-weight:600;white-space:nowrap}'
            . '.sec{font-size:14px;font-weight:700;margin:14px 0 6px;border-left:4px solid #000;padding-left:8px}'
            . '.content{white-space:pre-wrap;word-break:break-word}'
            . '.sign-area{height:22mm}'
            . '.foot{margin-top:14px;font-size:12px;color:#333;display:flex;justify-content:space-between}'
            . '.print-bar{margin:0 0 12px;text-align:right}'
            . '.print-bar button{font-size:14px;padding:6px 14px;cursor:pointer}'
            . '@media print{.print-bar{display:none}body{font-size:12pt}@page{margin:14mm}}'
            . '</style>';
    }
}

if (!function_exists('zw_receipt_print_render')) {
    /**
     * 渲染单据 HTML（打印即用）。
     *
     * @param array  $data 单据数据（`zw_receipt_print_rows_for()` 的返回）。
     * @param string $type 单据类型。
     * @return string
     */
    function zw_receipt_print_render($data, $type) {
        $types = zw_receipt_print_types();
        $title = isset($types[$type]) ? $types[$type] : '收文处理单';
        $view  = isset($data['view']) ? (array) $data['view'] : array();
        $arch  = isset($data['archive']) && is_array($data['archive']) ? $data['archive'] : array();
        $e     = function ($v) {
            return zw_receipt_print_esc($v);
        };
        $val = function ($key, $default = '—') use ($view, $e) {
            $v = isset($view[$key]) && (string) $view[$key] !== '' ? (string) $view[$key] : $default;
            return $e($v);
        };

        $html  = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<title>' . $e($title) . '-' . $val('number', '') . '</title>'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . zw_receipt_print_css() . '</head><body><div class="doc">';
        $html .= '<div class="print-bar"><button type="button" onclick="window.print()">打印 / 另存为 PDF</button></div>';
        $html .= '<h1>' . $e($title) . '</h1>';
        $html .= '<p class="org">中国传统文化促进会</p>';
        $html .= '<div class="meta"><span>收文编号：' . $val('number') . '</span>'
            . '<span>打印时间：' . $e(function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s')) . '</span></div>';

        // 来文要素
        $html .= '<table><tbody>';
        $html .= '<tr><td class="label">来文单位</td><td>' . $val('org_label') . '</td>'
            . '<td class="label">来文字号</td><td>' . $val('doc_no') . '</td></tr>';
        $html .= '<tr><td class="label">收文日期</td><td>' . $val('received_date') . '</td>'
            . '<td class="label">密级</td><td>' . $val('confidentiality') . '</td></tr>';
        $html .= '<tr><td class="label">份数</td><td>' . (int) (isset($view['copy_count']) ? $view['copy_count'] : 0) . '</td>'
            . '<td class="label">页数</td><td>' . (isset($arch['page_count']) && (int) $arch['page_count'] > 0 ? (int) $arch['page_count'] : '—') . '</td></tr>';
        $html .= '<tr><td class="label">归档盒号</td><td>'
            . (isset($arch['archive_box_no']) && $arch['archive_box_no'] !== '' ? $e($arch['archive_box_no']) : '—') . '</td>'
            . '<td class="label">归档年度</td><td>'
            . (isset($arch['archive_year']) && (int) $arch['archive_year'] > 0 ? (int) $arch['archive_year'] : '—') . '</td></tr>';
        $html .= '<tr><td class="label">文件标题</td><td colspan="3" class="content">' . $val('title') . '</td></tr>';
        $html .= '</tbody></table>';

        if ($type === 'approval') {
            // 拟办
            $html .= '<div class="sec">拟办意见</div>';
            if (!empty($data['suggest'])) {
                foreach ($data['suggest'] as $one) {
                    $who = isset($one['author_name']) && $one['author_name'] !== '' ? $one['author_name'] : (isset($one['author_login']) ? $one['author_login'] : '');
                    $html .= '<div class="content">' . $e(isset($one['content']) ? $one['content'] : '')
                        . '<br><span style="font-size:12px;color:#333">—— ' . $e($who) . ' '
                        . $e(isset($one['created_at']) ? $one['created_at'] : '') . '</span></div><div class="sign-area"></div>';
                }
            } else {
                $html .= '<div class="content">（未填写）</div><div class="sign-area"></div>';
            }

            // 批示
            $html .= '<div class="sec">领导批示</div>';
            if (!empty($data['instruction'])) {
                foreach ($data['instruction'] as $one) {
                    $who = isset($one['author_name']) && $one['author_name'] !== '' ? $one['author_name'] : (isset($one['author_login']) ? $one['author_login'] : '');
                    $html .= '<div class="content">' . $e(isset($one['content']) ? $one['content'] : '')
                        . '<br><span style="font-size:12px;color:#333">—— ' . $e($who) . ' '
                        . $e(isset($one['created_at']) ? $one['created_at'] : '') . '</span></div><div class="sign-area"></div>';
                }
            } else {
                $html .= '<div class="content">（待批示）</div><div class="sign-area"></div>';
            }

            // 办理单
            $html .= '<div class="sec">承办与办理</div>';
            $html .= '<table><thead><tr><th style="width:18%">承办部门</th><th style="width:16%">承办人</th>'
                . '<th style="width:14%">办理时限</th><th style="width:14%">状态</th><th>办理事项</th></tr></thead><tbody>';
            if (!empty($data['issues'])) {
                foreach ($data['issues'] as $one) {
                    $iv = function_exists('zw_receipt_issue_row_view') ? zw_receipt_issue_row_view($one) : array();
                    $html .= '<tr><td>' . $e(isset($iv['dept_label']) ? $iv['dept_label'] : '') . '</td>'
                        . '<td>' . $e(isset($iv['owner']) ? $iv['owner'] : '') . '</td>'
                        . '<td>' . $e(isset($iv['due_date']) ? $iv['due_date'] : '') . '</td>'
                        . '<td>' . $e(isset($iv['display_status']) ? $iv['display_status'] : (isset($iv['status']) ? $iv['status'] : '')) . '</td>'
                        . '<td class="content">' . $e(isset($iv['title']) ? $iv['title'] : '') . '</td></tr>';
                }
            } else {
                $html .= '<tr><td colspan="5">（尚未派单）</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        // 传阅（两种单据都带，呈批单作附页信息）
        $html .= '<div class="sec">传阅与签阅</div>';
        $html .= '<table><thead><tr><th style="width:14%">类型</th><th style="width:24%">传阅对象</th>'
            . '<th style="width:20%">登记时间</th><th style="width:20%">签阅时间</th><th>备注</th></tr></thead><tbody>';
        if (!empty($data['circ'])) {
            $targets = function_exists('zw_receipt_circ_targets') ? zw_receipt_circ_targets() : array();
            foreach ($data['circ'] as $one) {
                $tt = isset($one['target_type']) ? (string) $one['target_type'] : '';
                $html .= '<tr><td>' . $e(isset($targets[$tt]) ? $targets[$tt] : $tt) . '</td>'
                    . '<td>' . $e(isset($one['target_id']) ? $one['target_id'] : '') . '</td>'
                    . '<td>' . $e(isset($one['created_at']) ? $one['created_at'] : '') . '</td>'
                    . '<td>' . $e(!empty($one['read_at']) ? $one['read_at'] : '未签阅') . '</td>'
                    . '<td>' . $e(isset($one['note']) ? $one['note'] : '') . '</td></tr>';
            }
        } else {
            $html .= '<tr><td colspan="5">（无传阅登记）</td></tr>';
        }
        $html .= '</tbody></table>';

        if ($type === 'circulation') {
            $html .= '<div class="sec">签批栏</div>'
                . '<table><tbody><tr><td class="label">阅办人签字</td><td class="sign-area"></td></tr>'
                . '<tr><td class="label">日期</td><td class="sign-area"></td></tr></tbody></table>';
        }

        $html .= '<div class="foot"><span>系统自动签阅时间戳：'
            . (!empty($data['sign']) ? $e($data['sign']) : '未签阅') . '</span>'
            . '<span>本单据由系统生成，仅供内部办理使用</span></div>';
        $html .= '</div></body></html>';

        return $html;
    }
}

if (!function_exists('zw_receipt_print_handle')) {
    /**
     * `admin-post.php?action=zw_receipt_print` 入口。
     *
     * @return void
     */
    function zw_receipt_print_handle() {
        if (function_exists('zw_receipt_ledger_can') && !zw_receipt_ledger_can()) {
            wp_die('无权限打印收文单据（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
        }
        $id   = isset($_GET['id']) ? trim((string) wp_unslash($_GET['id'])) : '';
        $type = isset($_GET['doc']) ? trim((string) wp_unslash($_GET['doc'])) : 'approval';
        if ($id === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) || !isset(zw_receipt_print_types()[$type])) {
            wp_die('打印参数非法。', '参数错误', array('response' => 400));
        }
        if (function_exists('check_admin_referer')) {
            check_admin_referer(zw_receipt_print_nonce($id, $type));
        }

        $data = zw_receipt_print_rows_for($id);
        if (!$data) {
            wp_die('找不到该收文（或它不是 origin=incoming 的来文）。', '未找到', array('response' => 404));
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Robots-Tag: noindex, nofollow');
        }
        echo zw_receipt_print_render($data, $type);
        exit;
    }
}

if (function_exists('add_action')) {
    add_action('admin_post_zw_receipt_print', 'zw_receipt_print_handle');
}
