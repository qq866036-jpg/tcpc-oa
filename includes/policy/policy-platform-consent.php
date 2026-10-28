<?php
/**
 * AI 法务部 · 协议签署管理面（SPEC-88 §5.6「管理面口径」· TASK-989 / T4）。
 *
 * 职责（**只读展示 + 受控导出**）：
 *  - 按「规范 × 版本」列出适用账号的**已确认（含确认时间/阅读耗时/存证入口）**与**未确认**清单；
 *  - CSV 导出走 `admin_post` + nonce + `tcpc_perm_legal`，列最小化并中和 CSV 公式注入，导出写审计；
 *  - 为机构「管理员」页提供 canonical 的「协议」列状态取数（`zw_legal_consent_admin_user_status()`）。
 *
 * 禁止（SPEC-88 §5.6）：后台**不得代签**、不得直接编辑协议正文或签署记录；
 * 存证快照只复用 T3 的鉴权端点，不新增直链下载通道。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_LEGAL_CONSENT_EXPORT_ACTION')) {
    define('ZW_LEGAL_CONSENT_EXPORT_ACTION', 'zw_legal_consent_export');
}

/* ───────────── 菜单 ───────────── */
add_action('admin_menu', 'zw_legal_consent_admin_menu', 62);
function zw_legal_consent_admin_menu() {
    add_submenu_page(
        'zw-policy-platform',
        '协议签署',
        '协议签署',
        'tcpc_perm_legal',
        'zw-policy-consents',
        'zw_legal_consent_admin_page'
    );
}

if (!function_exists('zw_legal_consent_admin_can')) {
    /**
     * 管理面能力位（沿用 AI 法务部既有 `tcpc_perm_legal`）。
     *
     * @return bool
     */
    function zw_legal_consent_admin_can() {
        return function_exists('current_user_can') ? (bool) current_user_can('tcpc_perm_legal') : false;
    }
}

if (!function_exists('zw_legal_consent_admin_internal_docs')) {
    /**
     * 「内部文件」类规范清单（含草稿/停用；标出是否当前要求签署）。
     *
     * 与 `zw_legal_consent_docs()` 的差别：后者只返回**已生效**的；管理面要看全部，
     * 便于法务在草稿阶段就看到"将来要谁签"。生效哈希同样以**正文计算值**为准。
     *
     * @return array[] 归一后的协议结构 + `requires_sign`（是否已生效且适用角色）。
     */
    function zw_legal_consent_admin_internal_docs() {
        global $wpdb;
        if (!function_exists('zw_policy_platform_exists') || !zw_policy_platform_exists('policy_source')) {
            return array();
        }
        if (!function_exists('zw_legal_consent_normalize_doc') || !function_exists('zw_legal_consent_role_keys')) {
            return array();
        }
        $table = zw_policy_platform_table('policy_source');
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, doc_name, source_type, status, version_no, source_hash, applies_to, basis, source_file, effective_date, expiry_date
             FROM {$table} WHERE source_type=%s ORDER BY effective_date DESC, id ASC",
            '内部文件'
        ), ARRAY_A);
        if (!is_array($rows)) { return array(); }

        $role_keys = zw_legal_consent_role_keys();
        $out = array();
        foreach ($rows as $row) {
            $applies = zw_legal_consent_applies_to_normalize($row['applies_to'] ?? '');
            if (!array_intersect($applies, $role_keys)) { continue; }   // 与角色键无关的"内部文件"不进入签署面
            $doc = zw_legal_consent_normalize_doc($row, $applies);
            $computed = zw_legal_consent_hash($doc['doc_id']);
            if ($computed !== '') { $doc['source_hash'] = $computed; }
            $doc['requires_sign'] = ((string) $doc['status'] === '已生效')
                && zw_legal_consent_date_le((string) $doc['effective_date'])
                && zw_legal_consent_date_ge((string) $doc['expiry_date']);
            $out[] = $doc;
        }
        return $out;
    }
}

if (!function_exists('zw_legal_consent_admin_user_state')) {
    /**
     * 兼容别名（T4 调用点）：委托引擎 canonical `zw_legal_consent_user_state()`。
     *
     * TASK-990 已把状态判定上移到引擎——提醒在 cron/CLI 语境执行，那里不会加载本后台文件。
     *
     * @param int   $user_id 账号。
     * @param array $doc     归一后的协议结构。
     * @return array{code:string,at:int,role:string,inst:int,read_seconds:int|null,snap:string,version:string,name:string}
     */
    function zw_legal_consent_admin_user_state($user_id, $doc) {
        if (function_exists('zw_legal_consent_user_state')) { return zw_legal_consent_user_state($user_id, $doc); }
        return array('code' => 'unsigned', 'at' => 0, 'role' => '', 'inst' => 0, 'read_seconds' => null, 'snap' => '', 'version' => '', 'name' => '');
    }
}

if (!function_exists('zw_legal_consent_admin_state_label')) {
    /**
     * 状态文案（唯一出处；管理面与机构「管理员」列共用）。
     *
     * @param string $code      signed|stale|unsigned|none
     * @param array  $state     zw_legal_consent_admin_user_state() 结果。
     * @param string $version_no 协议当前版本号（展示用）。
     * @return string
     */
    function zw_legal_consent_admin_state_label($code, $state = array(), $version_no = '') {
        $label_ver = function_exists('zw_legal_consent_version_label')
            ? zw_legal_consent_version_label((string) ($state['version'] ?? '') !== '' ? (string) $state['version'] : (string) $version_no)
            : '';
        if ($code === 'signed') {
            $at = (int) ($state['at'] ?? 0);
            $date = $at > 0 ? (function_exists('wp_date') ? wp_date('Y-m-d', $at) : gmdate('Y-m-d', $at + 8 * 3600)) : '';
            return trim('已签 ' . $label_ver . ($date !== '' ? ' · ' . $date : ''));
        }
        if ($code === 'stale') { return '规范已更新'; }
        if ($code === 'unsigned') { return '未签'; }
        return '—';
    }
}

if (!function_exists('zw_legal_consent_admin_user_status')) {
    /**
     * 单账号的「协议」列状态（机构「管理员」页复用；取**第一份生效且适用**的协议）。
     *
     * @param int $user_id 账号。
     * @return array{code:string,label:string,badge:string,doc_id:string,at:int}
     */
    function zw_legal_consent_admin_user_status($user_id) {
        $none = array('code' => 'none', 'label' => '—', 'badge' => 'off', 'doc_id' => '', 'at' => 0);
        if (!function_exists('zw_legal_consent_docs') || !function_exists('zw_legal_consent_applies')) { return $none; }
        foreach (zw_legal_consent_docs() as $doc) {
            if (!zw_legal_consent_applies($user_id, $doc)) { continue; }
            $state = zw_legal_consent_admin_user_state($user_id, $doc);
            $code  = (string) $state['code'];
            return array(
                'code'   => $code,
                'label'  => zw_legal_consent_admin_state_label($code, $state, (string) $doc['version_no']),
                'badge'  => ($code === 'signed') ? 'on' : (($code === 'stale') ? 'wait' : 'err'),
                'doc_id' => (string) $doc['doc_id'],
                'at'     => (int) $state['at'],
            );
        }
        return $none;
    }
}

if (!function_exists('zw_legal_consent_admin_role_label')) {
    /**
     * 角色键 → 展示名（复用 TASK-602 的 canonical）。
     *
     * @param string $role_key 角色键。
     * @return string
     */
    function zw_legal_consent_admin_role_label($role_key) {
        $role_key = (string) $role_key;
        if ($role_key === '') { return ''; }
        if (function_exists('zw_branch_front_role_label')) {
            $label = (string) zw_branch_front_role_label($role_key);
            if ($label !== '') { return $label; }
        }
        return $role_key;
    }
}

if (!function_exists('zw_legal_consent_admin_rows')) {
    /**
     * 某协议的签署明细行（唯一口径：适用对象来自 `zw_legal_consent_applicable_users()`）。
     *
     * @param array  $doc   协议结构。
     * @param string $state 过滤：''|signed|stale|unsigned。
     * @return array[] 每行：uid/name/role/inst/code/at/read_seconds/snap/version
     */
    function zw_legal_consent_admin_rows($doc, $state = '') {
        $rows = array();
        if (!is_array($doc) || !function_exists('zw_legal_consent_applicable_users')) { return $rows; }
        foreach (zw_legal_consent_applicable_users($doc) as $uid) {
            $st = zw_legal_consent_admin_user_state($uid, $doc);
            if ($state !== '' && $state !== 'all' && (string) $st['code'] !== $state) { continue; }
            $u = function_exists('get_userdata') ? get_userdata($uid) : null;
            $rows[] = array(
                'uid'          => (int) $uid,
                'name'         => $u ? (string) $u->display_name : ('#' . (int) $uid),
                'role'         => zw_legal_consent_admin_role_label((string) $st['role']),
                'inst'         => ((int) $st['inst'] > 0 && function_exists('get_the_title')) ? (string) get_the_title((int) $st['inst']) : '',
                'code'         => (string) $st['code'],
                'at'           => (int) $st['at'],
                'read_seconds' => $st['read_seconds'],
                'snap'         => (string) $st['snap'],
                'version'      => ((string) $st['version'] !== '') ? (string) $st['version'] : (string) ($doc['version_no'] ?? ''),
            );
        }
        return $rows;
    }
}

if (!function_exists('zw_legal_consent_admin_counts')) {
    /**
     * 某协议的已确认 / 待重签 / 未确认计数。
     *
     * @param array $doc 协议结构。
     * @return array{signed:int,stale:int,unsigned:int,total:int}
     */
    function zw_legal_consent_admin_counts($doc) {
        $out = array('signed' => 0, 'stale' => 0, 'unsigned' => 0, 'total' => 0);
        if (!function_exists('zw_legal_consent_applicable_users')) { return $out; }
        $users = zw_legal_consent_applicable_users($doc);
        $out['total'] = count($users);
        foreach ($users as $uid) {
            $st = zw_legal_consent_admin_user_state($uid, $doc);
            if (isset($out[$st['code']])) { $out[$st['code']]++; }
        }
        return $out;
    }
}

if (!function_exists('zw_legal_consent_admin_time')) {
    /**
     * 时间戳 → 站点时间（北京时间兜底）。
     *
     * @param int $ts 时间戳。
     * @return string
     */
    function zw_legal_consent_admin_time($ts) {
        $ts = (int) $ts;
        if ($ts <= 0) { return '—'; }
        if (function_exists('wp_date')) { return (string) wp_date('Y-m-d H:i', $ts); }
        return gmdate('Y-m-d H:i', $ts + 8 * 3600);
    }
}

/* ───────────── 页面 ───────────── */
if (!function_exists('zw_legal_consent_admin_page')) {
    /**
     * AI 法务部「协议签署」主页面（概览 + 明细；只读）。
     */
    function zw_legal_consent_admin_page() {
        if (!zw_legal_consent_admin_can()) { wp_die('无权限查看签署管理面。'); }
        if (!function_exists('zw_policy_platform_page_header')) {
            echo '<div class="wrap"><p>政策平台模块未加载。</p></div>';
            return;
        }
        zw_policy_platform_page_header('协议签署', '按「规范 × 版本」查看已确认（含确认时间）与未确认清单并导出 CSV；本页只读，签署只能由本人完成。');

        /* TASK-990 / T5：提醒动作的 PRG 回执（预演 / 已发送 / 无可提醒 / 未开启） */
        $zw_notify_flag = isset($_GET['zw_notify']) ? sanitize_key(wp_unslash((string) $_GET['zw_notify'])) : '';
        if ($zw_notify_flag !== '') {
            $zw_notify_n = isset($_GET['zw_notify_n']) ? (int) $_GET['zw_notify_n'] : 0;
            $zw_reason   = isset($_GET['zw_notify_reason']) ? sanitize_key(wp_unslash((string) $_GET['zw_notify_reason'])) : '';
            $map = array(
                'dry'     => array('notice-info', '已预演：命中 ' . $zw_notify_n . ' 个待提醒账号（未发送、未记账）。'),
                'sent'    => array('notice-success', '已发送提醒：覆盖 ' . $zw_notify_n . ' 个账号（同机构同版本仅一条站内通知；聚合 webhook 按内容去重）。'),
                'nothing' => array('notice-info', '没有需要提醒的账号（均已确认当前版本或已提醒过）。'),
                'skip'    => array('notice-warning', '未发送：' . ($zw_reason !== '' ? $zw_reason : '提醒开关为关或通道未配置') . '。'),
            );
            if (isset($map[$zw_notify_flag])) {
                echo '<div class="notice ' . esc_attr($map[$zw_notify_flag][0]) . ' is-dismissible"><p>' . esc_html($map[$zw_notify_flag][1]) . '</p></div>';
            }
        }

        if (!function_exists('zw_legal_consent_docs')) {
            if (function_exists('zw_policy_platform_missing_notice')) { zw_policy_platform_missing_notice(); }
            zw_policy_platform_page_footer();
            return;
        }

        $docs = zw_legal_consent_admin_internal_docs();
        if (!$docs) {
            echo '<div class="zw-adm-card" style="padding:14px"><p style="margin:0;color:#666">尚未登记「内部文件」类规范，或未配置机构角色适用对象（`applies_to`）。</p></div>';
            zw_policy_platform_page_footer();
            return;
        }

        $doc_id = isset($_GET['doc']) ? sanitize_text_field(wp_unslash((string) $_GET['doc'])) : '';
        $doc    = null;
        foreach ($docs as $d) { if ((string) $d['doc_id'] === $doc_id) { $doc = $d; break; } }
        if (!$doc) { $doc = $docs[0]; }

        $state = isset($_GET['state']) ? sanitize_key(wp_unslash((string) $_GET['state'])) : '';
        if (!in_array($state, array('', 'signed', 'stale', 'unsigned'), true)) { $state = ''; }

        /* 概览：规范 × 版本 */
        $head_rows = array();
        foreach ($docs as $d) {
            $c = zw_legal_consent_admin_counts($d);
            $status_html = function_exists('zw_policy_platform_badge_cell')
                ? zw_policy_platform_badge_cell(function_exists('zw_policy_platform_status_type') ? zw_policy_platform_status_type((string) $d['status']) : 'off', (string) $d['status'])
                : array('html' => esc_html((string) $d['status']));
            $sign_html = ($c['total'] === 0)
                ? '<span style="color:#999">无适用账号</span>'
                : '<strong>' . (int) $c['signed'] . '</strong> / ' . (int) $c['total'] . ' 已确认'
                  . ($c['stale'] > 0 ? '　<span style="color:#e65100">待重签 ' . (int) $c['stale'] . '</span>' : '');
            $link = add_query_arg(array('page' => 'zw-policy-consents', 'doc' => (string) $d['doc_id']), admin_url('admin.php'));
            $head_rows[] = array(
                (string) $d['doc_id'],
                '<a href="' . esc_url($link) . '">' . esc_html((string) $d['doc_name']) . '</a>'
                    . ($d['requires_sign'] ? '' : '　<span style="color:#999">（未生效）</span>'),
                esc_html(zw_legal_consent_version_label((string) $d['version_no'])),
                $status_html,
                esc_html((string) $d['effective_date']),
                esc_html(implode('、', array_map('zw_legal_consent_admin_role_label', (array) $d['applies_to']))),
                array('html' => $sign_html),
                '<code>' . esc_html(substr((string) $d['source_hash'], 0, 12)) . '</code>',
            );
        }
        echo '<h2 style="font-size:15px;margin:18px 0 8px">规范概览</h2>';
        zw_policy_platform_render_table(array('规范 ID', '名称', '版本', '状态', '生效日期', '适用角色', '已确认', '正文哈希'), $head_rows);

        /* 明细：选中规范 */
        $counts   = zw_legal_consent_admin_counts($doc);
        $all_rows = zw_legal_consent_admin_rows($doc, $state);
        $per      = 50;
        $paged    = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $slice    = array_slice($all_rows, ($paged - 1) * $per, $per);
        $base     = add_query_arg(array('page' => 'zw-policy-consents', 'doc' => (string) $doc['doc_id']), admin_url('admin.php'));

        echo '<h2 style="font-size:15px;margin:22px 0 8px">签署明细：' . esc_html((string) $doc['doc_name']) . '（' . esc_html(zw_legal_consent_version_label((string) $doc['version_no'])) . '）</h2>';
        echo '<p style="margin:0 0 10px;color:#666;font-size:12.5px">适用账号 ' . (int) $counts['total']
            . ' 个：已确认 <strong>' . (int) $counts['signed'] . '</strong>'
            . '、待重签 ' . (int) $counts['stale']
            . '、未确认 <strong>' . (int) $counts['unsigned'] . '</strong>'
            . '　（未确认 = 未签 或 已签正文哈希 ≠ 当前哈希）</p>';

        $filters = array(
            ''          => '全部',
            'signed'    => '已确认',
            'stale'     => '待重签',
            'unsigned'  => '未确认',
        );
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">';
        foreach ($filters as $key => $label) {
            $url = add_query_arg(array('page' => 'zw-policy-consents', 'doc' => (string) $doc['doc_id'], 'state' => $key), admin_url('admin.php'));
            $cls = 'button' . ((string) $state === (string) $key ? ' button-primary' : '');
            echo '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '<span style="flex:1"></span>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
        echo '<input type="hidden" name="action" value="' . esc_attr(ZW_LEGAL_CONSENT_EXPORT_ACTION) . '">';
        echo '<input type="hidden" name="doc" value="' . esc_attr((string) $doc['doc_id']) . '">';
        echo '<input type="hidden" name="state" value="' . esc_attr((string) $state) . '">';
        wp_nonce_field(ZW_LEGAL_CONSENT_EXPORT_ACTION);
        echo '<button class="button button-primary" type="submit">导出 CSV</button>';
        echo '</form>';

        /* TASK-990 / T5：一键提醒（预演 / 发送）——开关 `zw_legal_consent_notify_enabled` 默认 OFF */
        if (function_exists('zw_legal_consent_notify_run')) {
            $notify_on = function_exists('zw_legal_consent_notify_enabled') && zw_legal_consent_notify_enabled();
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;display:flex;gap:6px;align-items:center">';
            echo '<input type="hidden" name="action" value="' . esc_attr(ZW_LEGAL_CONSENT_NOTIFY_NONCE) . '">';
            echo '<input type="hidden" name="doc" value="' . esc_attr((string) $doc['doc_id']) . '">';
            wp_nonce_field(ZW_LEGAL_CONSENT_NOTIFY_NONCE);
            echo '<button class="button" type="submit" name="dry_run" value="1">预演提醒</button>';
            echo '<button class="button" type="submit"' . ($notify_on ? '' : ' disabled') . ' title="'
                . esc_attr($notify_on ? '按去重规则发送站内提醒与聚合 webhook' : '提醒总开关当前为关（zw_legal_consent_notify_enabled=0），不会发送') . '">一键提醒未确认</button>';
            if (!$notify_on) { echo '<span style="color:#999;font-size:12px">提醒开关：关</span>'; }
            echo '</form>';
        }
        echo '</div>';

        $rows = array();
        foreach ($slice as $r) {
            $label = zw_legal_consent_admin_state_label((string) $r['code'], $r, (string) $r['version']);
            $badge = ($r['code'] === 'signed') ? 'on' : (($r['code'] === 'stale') ? 'wait' : 'err');
            $snap  = '';
            if ((string) $r['snap'] !== '' && function_exists('wp_create_nonce')) {
                $snap_url = add_query_arg(array(
                    'action' => 'zw_legal_consent_snapshot',
                    'doc'    => (string) $doc['doc_id'],
                    'user'   => (int) $r['uid'],
                    'nonce'  => wp_create_nonce('zw_legal_consent_snapshot'),
                ), admin_url('admin-ajax.php'));
                $snap = '<a href="' . esc_url($snap_url) . '" target="_blank" rel="noopener">存证</a>';
            }
            $rows[] = array(
                (int) $r['uid'],
                esc_html((string) $r['name']),
                esc_html((string) $r['inst']),
                esc_html((string) $r['role']),
                array('html' => function_exists('zw_policy_platform_badge')
                    ? zw_policy_platform_badge($badge, $label)
                    : esc_html($label)),
                esc_html(zw_legal_consent_admin_time((int) $r['at'])),
                ($r['read_seconds'] === null ? '—' : (int) $r['read_seconds'] . ' 秒'),
                array('html' => $snap !== '' ? $snap : '<span style="color:#999">—</span>'),
            );
        }
        zw_policy_platform_render_table(array('UID', '显示名', '机构', '角色', '状态', '确认时间', '阅读耗时', '存证'), $rows);
        zw_policy_platform_pager(count($all_rows), $per, $paged, $base, array_filter(array('state' => $state)));
        zw_policy_platform_page_footer();
    }
}

/* ───────────── CSV 导出（受控） ───────────── */
if (!function_exists('zw_legal_consent_admin_csv')) {
    /**
     * 生成签署清单 CSV 内容（**纯函数**，便于单测；导出端点只负责鉴权、审计与响应头）。
     *
     * 列最小化：不含手机号、证件号、身份证哈希等无关个人信息；
     * 单元格统一走 canonical `zw_branch_csv_export_cell()`（防 CSV 公式注入）。
     *
     * @param array  $doc   协议结构。
     * @param string $state 过滤：''|signed|stale|unsigned。
     * @return string CSV 文本（不含 BOM）；依赖缺失时返回空串。
     */
    function zw_legal_consent_admin_csv($doc, $state = '') {
        if (!function_exists('zw_branch_csv_export_cell') || !function_exists('zw_legal_consent_admin_rows')) {
            return '';
        }
        if (!is_array($doc)) { return ''; }
        if (!in_array($state, array('', 'signed', 'stale', 'unsigned'), true)) { $state = ''; }

        $fh = fopen('php://temp', 'w+');
        if (!$fh) { return ''; }
        $headers = array('规范ID', '规范名称', '版本', '账号UID', '显示名', '机构', '角色', '状态', '确认时间', '阅读耗时(秒)', '正文哈希');
        fputcsv($fh, array_map('zw_branch_csv_export_cell', $headers));
        foreach (zw_legal_consent_admin_rows($doc, $state) as $r) {
            $line = array(
                (string) $doc['doc_id'],
                (string) $doc['doc_name'],
                (string) $doc['version_no'],
                (int) $r['uid'],
                (string) $r['name'],
                (string) $r['inst'],
                (string) $r['role'],
                (string) $r['code'],
                ((int) $r['at'] > 0 ? zw_legal_consent_admin_time((int) $r['at']) : ''),
                ($r['read_seconds'] === null ? '' : (int) $r['read_seconds']),
                (string) ($doc['source_hash'] ?? ''),
            );
            fputcsv($fh, array_map('zw_branch_csv_export_cell', $line));
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }
}

add_action('admin_post_' . ZW_LEGAL_CONSENT_EXPORT_ACTION, 'zw_legal_consent_admin_export');
if (!function_exists('zw_legal_consent_admin_export')) {
    /**
     * 导出签署清单 CSV（POST + nonce + `tcpc_perm_legal`）。
     */
    function zw_legal_consent_admin_export() {
        if (!zw_legal_consent_admin_can()) {
            wp_die('无权限导出签署清单。', '', array('response' => 403));
        }
        check_admin_referer(ZW_LEGAL_CONSENT_EXPORT_ACTION);
        if (!function_exists('zw_legal_consent_admin_internal_docs') || !function_exists('zw_legal_consent_admin_csv')) {
            wp_die('签署模块未加载。');
        }
        if (!function_exists('zw_branch_csv_export_cell')) {
            wp_die('CSV 组件未加载（canonical 防注入函数缺失）。');
        }

        $doc_id = isset($_POST['doc']) ? sanitize_text_field(wp_unslash((string) $_POST['doc'])) : '';
        $state  = isset($_POST['state']) ? sanitize_key(wp_unslash((string) $_POST['state'])) : '';
        if (!in_array($state, array('', 'signed', 'stale', 'unsigned'), true)) { $state = ''; }

        $doc = null;
        foreach (zw_legal_consent_admin_internal_docs() as $d) {
            if ((string) $d['doc_id'] === $doc_id) { $doc = $d; break; }
        }
        if (!$doc) { wp_die('协议不存在或不是内部文件。'); }

        $csv = zw_legal_consent_admin_csv($doc, $state);
        if ($csv === '') { wp_die('导出内容为空或 CSV 组件不可用。'); }

        $uid = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $ip  = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (function_exists('zw_mc_audit_write')) {
            zw_mc_audit_write('legal_consent_export', 'user#' . $uid, (string) $doc['doc_id'] . '@' . (string) $doc['version_no'], array(
                'context' => array(
                    'doc_id'  => (string) $doc['doc_id'],
                    'version' => (string) $doc['version_no'],
                    'state'   => $state,
                    'rows'    => count(zw_legal_consent_admin_rows($doc, $state)),
                ),
                'ip' => $ip,
            ), $uid);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tcpc-legal-consents-'
            . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $doc['doc_id'])
            . '-' . ($state !== '' ? $state : 'all') . '-' . date('Ymd-His') . '.csv"');
        echo "\xEF\xBB\xBF" . $csv;   // 与既有导出一致：BOM 便于 Excel 识别 UTF-8
        exit;
    }
}
