<?php
/**
 * 条款与签署 · 未确认规范提醒（SPEC-88 §5.7 · TASK-990 / T5）。
 *
 * 口径（**默认 OFF**，用户未指定渠道/频率前的推荐最小方案）：
 *  - 开关 `zw_legal_consent_notify_enabled` 默认 `0` → 任何提醒都不发（零副作用）；
 *  - **站内机构通知**：复用 canonical `zw_branch_add_notification()`，按「机构 × 规范 × 正文哈希」
 *    聚合**一条**摘要，避免同机构多人重复轰炸；不发短信、不对外公示；
 *  - **聚合 webhook**：复用 AI 法务部既有 `policy_notification` 通道，只推送**人数统计**，
 *    **不含个人身份信息**；按内容指纹去重，写入 `policy_notification` 留痕；
 *  - **每版本每人一次**：`user_meta.zw_legal_consents_notified[doc_id] = {hash, at}`；
 *    正文哈希变更（升版/改文）后允许再次提醒；
 *  - 每次运行写审计（含预演），保证"触发一次提醒有留痕"。
 *
 * 触发方式：T4 管理面「一键提醒/预演」按钮 · 每日 cron（开关关闭时不调度）· CLI
 * `wp --allow-root eval-file scripts/legal-consent-notify.php [execute]`。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_LEGAL_CONSENT_NOTIFY_OPTION')) {
    define('ZW_LEGAL_CONSENT_NOTIFY_OPTION', 'zw_legal_consent_notify_enabled');
    define('ZW_LEGAL_CONSENT_NOTIFIED_META', 'zw_legal_consents_notified');
    define('ZW_LEGAL_CONSENT_NOTIFY_BRANCH_LOG', 'zw_legal_consent_notify_branch_log');
    define('ZW_LEGAL_CONSENT_NOTIFY_WEBHOOK_FP', 'zw_legal_consent_notify_webhook_fingerprint');
    define('ZW_LEGAL_CONSENT_NOTIFY_CRON_HOOK', 'zw_legal_consent_notify_event');
    define('ZW_LEGAL_CONSENT_NOTIFY_NONCE', 'zw_legal_consent_notify');
}

if (!function_exists('zw_legal_consent_notify_enabled')) {
    /**
     * 提醒总开关（默认关）。
     *
     * @return bool
     */
    function zw_legal_consent_notify_enabled() {
        if (!function_exists('get_option')) { return false; }
        return (string) get_option(ZW_LEGAL_CONSENT_NOTIFY_OPTION, '0') === '1';
    }
}

if (!function_exists('zw_legal_consent_notify_stats_empty')) {
    /**
     * 空统计（字段唯一出处，便于测试与调用方稳定读取）。
     *
     * @return array
     */
    function zw_legal_consent_notify_stats_empty() {
        return array(
            'docs' => 0, 'users' => 0, 'unsigned' => 0, 'stale' => 0, 'groups' => 0,
            'branch_sent' => 0, 'branch_skipped' => 0, 'users_marked' => 0, 'skipped_notified' => 0,
        );
    }
}

if (!function_exists('zw_legal_consent_notify_text')) {
    /**
     * 提醒文案（唯一出处；站内机构通知与 webhook 共用措辞模板）。
     *
     * @param array $doc   协议结构。
     * @param int   $count 未确认人数（0 = 不写该段）。
     * @param int   $stale 待重签人数（0 = 不写该段）。
     * @return string
     */
    function zw_legal_consent_notify_text($doc, $count = 0, $stale = 0) {
        $name = trim((string) ($doc['doc_name'] ?? ''));
        $ver  = function_exists('zw_legal_consent_version_label')
            ? zw_legal_consent_version_label((string) ($doc['version_no'] ?? ''))
            : trim((string) ($doc['version_no'] ?? ''));
        $title = $name . ($ver !== '' ? '（' . $ver . '）' : '');
        $parts = array();
        if ((int) $count > 0) { $parts[] = (int) $count . ' 位管理员未确认'; }
        if ((int) $stale > 0) { $parts[] = (int) $stale . ' 位需重新确认'; }
        return '《' . $title . '》' . ($parts ? implode('、', $parts) : '待确认')
            . '。请相关人员在服务中心「我的协议」中阅读并确认。';
    }
}

if (!function_exists('zw_legal_consent_notified_map')) {
    /**
     * 某账号「已提醒」映射（doc_id => {hash, at}）。
     *
     * @param int $user_id 账号。
     * @return array
     */
    function zw_legal_consent_notified_map($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !function_exists('get_user_meta')) { return array(); }
        $map = get_user_meta($user_id, ZW_LEGAL_CONSENT_NOTIFIED_META, true);
        return is_array($map) ? $map : array();
    }
}

if (!function_exists('zw_legal_consent_notified_hash')) {
    /**
     * 该账号对某规范已提醒过的正文哈希（空 = 未提醒过）。
     *
     * @param int    $user_id 账号。
     * @param string $doc_id  规范 ID。
     * @return string
     */
    function zw_legal_consent_notified_hash($user_id, $doc_id) {
        $map = zw_legal_consent_notified_map($user_id);
        $row = $map[(string) $doc_id] ?? null;
        return is_array($row) ? (string) ($row['hash'] ?? '') : '';
    }
}

if (!function_exists('zw_legal_consent_mark_notified')) {
    /**
     * 记录「已提醒」（每版本每人一次；哈希变更后可再次提醒）。
     *
     * @param int    $user_id 账号。
     * @param string $doc_id  规范 ID。
     * @param string $hash    当时正文哈希。
     * @param int    $at      时间戳（0 = 当前）。
     * @return bool
     */
    function zw_legal_consent_mark_notified($user_id, $doc_id, $hash, $at = 0) {
        $user_id = (int) $user_id;
        $doc_id  = trim((string) $doc_id);
        if ($user_id <= 0 || $doc_id === '' || !function_exists('update_user_meta')) { return false; }
        $map = zw_legal_consent_notified_map($user_id);
        $map[$doc_id] = array(
            'hash' => (string) $hash,
            'at'   => $at > 0 ? (int) $at : (function_exists('time') ? time() : 0),
        );
        update_user_meta($user_id, ZW_LEGAL_CONSENT_NOTIFIED_META, $map);
        return true;
    }
}

if (!function_exists('zw_legal_consent_notify_branch_log')) {
    /**
     * 机构通知去重台账（机构 × 规范 × 哈希 → 时间；容量上限 500，防 option 无限膨胀）。
     *
     * @return array
     */
    function zw_legal_consent_notify_branch_log() {
        $log = function_exists('get_option') ? get_option(ZW_LEGAL_CONSENT_NOTIFY_BRANCH_LOG, array()) : array();
        return is_array($log) ? $log : array();
    }
}

if (!function_exists('zw_legal_consent_notify_branch_key')) {
    /**
     * 机构通知去重键（唯一算法，禁止各调用点自算）。
     *
     * @param int    $branch_id 机构 ID。
     * @param string $doc_id    规范 ID。
     * @param string $hash      正文哈希。
     * @return string
     */
    function zw_legal_consent_notify_branch_key($branch_id, $doc_id, $hash) {
        return md5((int) $branch_id . '|' . (string) $doc_id . '|' . (string) $hash);
    }
}

if (!function_exists('zw_legal_consent_notify_branch_already_sent')) {
    /**
     * 该机构是否已就该（规范 × 版本）收到过提醒。
     *
     * @param int    $branch_id 机构 ID。
     * @param string $doc_id    规范 ID。
     * @param string $hash      正文哈希。
     * @return bool
     */
    function zw_legal_consent_notify_branch_already_sent($branch_id, $doc_id, $hash) {
        $log = zw_legal_consent_notify_branch_log();
        return !empty($log[zw_legal_consent_notify_branch_key($branch_id, $doc_id, $hash)]);
    }
}

if (!function_exists('zw_legal_consent_notify_mark_branch_sent')) {
    /**
     * 记录机构提醒已发送。
     *
     * @param int    $branch_id 机构 ID。
     * @param string $doc_id    规范 ID。
     * @param string $hash      正文哈希。
     * @param int    $at        时间戳。
     * @return void
     */
    function zw_legal_consent_notify_mark_branch_sent($branch_id, $doc_id, $hash, $at = 0) {
        if (!function_exists('update_option')) { return; }
        $log = zw_legal_consent_notify_branch_log();
        $log[zw_legal_consent_notify_branch_key($branch_id, $doc_id, $hash)] = $at > 0 ? (int) $at : time();
        if (count($log) > 500) { $log = array_slice($log, -500, null, true); }
        update_option(ZW_LEGAL_CONSENT_NOTIFY_BRANCH_LOG, $log, false);
    }
}

if (!function_exists('zw_legal_consent_notify_plan')) {
    /**
     * 提醒计划（**纯计算，不发送**）：谁需要提醒、按机构怎么聚。
     *
     * @param array $opts 可选：doc_id（限定规范）。
     * @return array{items:array,stats:array}
     */
    function zw_legal_consent_notify_plan($opts = array()) {
        $doc_filter = isset($opts['doc_id']) ? trim((string) $opts['doc_id']) : '';
        $plan = array('items' => array(), 'stats' => zw_legal_consent_notify_stats_empty());
        if (!function_exists('zw_legal_consent_docs') || !function_exists('zw_legal_consent_applicable_users') || !function_exists('zw_legal_consent_user_state')) {
            return $plan;
        }
        foreach (zw_legal_consent_docs() as $doc) {
            $doc_id = (string) ($doc['doc_id'] ?? '');
            if ($doc_id === '') { continue; }
            if ($doc_filter !== '' && $doc_id !== $doc_filter) { continue; }
            $hash = (string) ($doc['source_hash'] ?? '');
            // TASK-997：可签署性收敛到 canonical 谓词（不可签署 → 不提醒），禁止各调用点自算。
            if (function_exists('zw_legal_consent_signable')) {
                if (!zw_legal_consent_signable($doc)) { continue; }
            } elseif ($hash === '') {
                continue;
            }
            $users = zw_legal_consent_applicable_users($doc);
            if (!$users) { continue; }

            $pending = array();
            $groups  = array();
            foreach ($users as $uid) {
                $uid = (int) $uid;
                if ($uid <= 0) { continue; }
                if (zw_legal_consent_notified_hash($uid, $doc_id) === $hash) { $plan['stats']['skipped_notified']++; continue; }
                $st = zw_legal_consent_user_state($uid, $doc);
                if ((string) ($st['code'] ?? '') === 'signed') { continue; }   // 已确认当前版本：不打扰
                $pending[$uid] = $st;
                $inst = (int) ($st['inst'] ?? 0);
                if ($inst > 0) { $groups[$inst][] = $uid; }
            }
            if (!$pending) { continue; }

            $plan['items'][$doc_id] = array('doc' => $doc, 'pending' => $pending, 'groups' => $groups, 'hash' => $hash);
            $plan['stats']['docs']++;
            $plan['stats']['users'] += count($pending);
            foreach ($pending as $st) {
                if ((string) ($st['code'] ?? '') === 'stale') { $plan['stats']['stale']++; } else { $plan['stats']['unsigned']++; }
            }
            $plan['stats']['groups'] += count($groups);
        }
        return $plan;
    }
}

if (!function_exists('zw_legal_consent_notify_webhook_lines')) {
    /**
     * webhook 聚合文案（**只含人数统计，绝不含个人身份信息**）。
     *
     * @param array $plan 提醒计划。
     * @return string[]
     */
    function zw_legal_consent_notify_webhook_lines($plan) {
        $stats = $plan['stats'] ?? zw_legal_consent_notify_stats_empty();
        $lines = array('**规范签署待办（聚合提醒）**');
        foreach ((array) ($plan['items'] ?? array()) as $item) {
            $stale = 0; $total = 0;
            foreach ((array) ($item['pending'] ?? array()) as $st) {
                $total++;
                if ((string) ($st['code'] ?? '') === 'stale') { $stale++; }
            }
            $name = trim((string) ($item['doc']['doc_name'] ?? ''));
            $ver  = function_exists('zw_legal_consent_version_label')
                ? zw_legal_consent_version_label((string) ($item['doc']['version_no'] ?? ''))
                : (string) ($item['doc']['version_no'] ?? '');
            $lines[] = '- ' . $name . ($ver !== '' ? '（' . $ver . '）' : '')
                . '：未确认 ' . max(0, $total - $stale) . ' 人'
                . ($stale > 0 ? '，待重签 ' . $stale . ' 人' : '');
        }
        $lines[] = '- 合计：未确认 ' . (int) $stats['unsigned'] . ' 人，待重签 ' . (int) $stats['stale']
            . ' 人（覆盖 ' . (int) $stats['users'] . ' 个账号 / ' . (int) $stats['docs'] . ' 份规范）';
        $lines[] = '- 生成时间：' . (function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'));
        return $lines;
    }
}

if (!function_exists('zw_legal_consent_notify_webhook')) {
    /**
     * 推送聚合提醒到 AI 法务部既有 webhook 通道（指纹去重 + `policy_notification` 留痕）。
     *
     * @param array $plan    提醒计划。
     * @param bool  $dry_run 预演：不实际发送、不加指纹。
     * @return array
     */
    function zw_legal_consent_notify_webhook($plan, $dry_run = false) {
        $stats = $plan['stats'] ?? array();
        if (empty($stats['users'])) { return array('ok' => false, 'reason' => 'NOTHING'); }
        if (!function_exists('zw_policy_platform_cron_webhook') || !function_exists('zw_policy_platform_exists')
            || !zw_policy_platform_exists('policy_notification') || !function_exists('zw_policy_platform_table')) {
            return array('ok' => false, 'reason' => 'NO_CHANNEL');
        }
        $webhook = (string) zw_policy_platform_cron_webhook();
        if ($webhook === '') { return array('ok' => false, 'reason' => 'NO_WEBHOOK'); }

        $lines = zw_legal_consent_notify_webhook_lines($plan);
        $fp    = hash('sha256', implode("\n", $lines));
        if ((string) get_option(ZW_LEGAL_CONSENT_NOTIFY_WEBHOOK_FP, '') === $fp) {
            return array('ok' => true, 'skipped' => true, 'reason' => 'DUPLICATE', 'fingerprint' => $fp);
        }
        if ($dry_run) { return array('ok' => true, 'dry_run' => true, 'fingerprint' => $fp); }

        $payload  = array('msgtype' => 'markdown', 'markdown' => array('content' => implode("\n", $lines)));
        $response = wp_remote_post($webhook, array(
            'timeout' => 8,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($payload),
        ));
        $code = function_exists('is_wp_error') && is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $body = function_exists('is_wp_error') && is_wp_error($response) ? $response->get_error_message() : (string) wp_remote_retrieve_body($response);
        $ok   = ($code >= 200 && $code < 300 && strpos($body, '"errcode":0') !== false);

        global $wpdb;
        if (is_object($wpdb) && method_exists($wpdb, 'insert')) {
            $wpdb->insert(zw_policy_platform_table('policy_notification'), array(
                'id'              => 'NOTIFY_' . wp_generate_uuid4(),
                'channel'         => 'legal_consent',
                'webhook_url'     => function_exists('zw_policy_platform_cron_mask_webhook') ? zw_policy_platform_cron_mask_webhook($webhook) : '',
                'total'           => (int) $stats['users'],
                'messages_json'   => wp_json_encode($lines),
                'payload_json'    => wp_json_encode($payload),
                'status'          => $ok ? '成功' : '失败',
                'error'           => $ok ? '' : ('HTTP ' . $code),
                'response_status' => $code,
                'response_body'   => $body,
                'created_by'      => 'legal_consent',
            ));
        }
        if ($ok) { update_option(ZW_LEGAL_CONSENT_NOTIFY_WEBHOOK_FP, $fp, false); }
        return array('ok' => $ok, 'status' => $ok ? '成功' : '失败', 'http' => $code, 'fingerprint' => $fp);
    }
}

if (!function_exists('zw_legal_consent_notify_run')) {
    /**
     * 执行提醒（站内机构通知 + 聚合 webhook）；幂等、可预演、留审计。
     *
     * @param array $opts 可选：dry_run(bool) / force(bool) / doc_id(string) / source(string)。
     * @return array{ok:bool,dry_run:bool,reason:string,stats:array,channels:string[],webhook:array}
     */
    function zw_legal_consent_notify_run($opts = array()) {
        $dry    = !empty($opts['dry_run']);
        $force  = !empty($opts['force']);
        $source = isset($opts['source']) ? sanitize_key((string) $opts['source']) : 'manual';
        $out    = array('ok' => false, 'dry_run' => $dry, 'reason' => '', 'stats' => zw_legal_consent_notify_stats_empty(), 'channels' => array(), 'webhook' => array());

        if (!$force && !zw_legal_consent_notify_enabled()) {
            $out['reason'] = 'DISABLED';
            return $out;
        }
        if (!function_exists('zw_legal_consent_notify_plan')) {
            $out['reason'] = 'NO_PLAN';
            return $out;
        }

        $plan = zw_legal_consent_notify_plan($opts);
        $out['stats'] = $plan['stats'];
        if (empty($plan['items'])) {
            $out['ok'] = true;
            $out['reason'] = 'NOTHING';
            return $out;
        }

        $now = function_exists('time') ? time() : 0;
        foreach ($plan['items'] as $doc_id => $item) {
            $doc  = $item['doc'];
            $hash = (string) $item['hash'];

            /* 站内机构通知：按「机构 × 规范 × 哈希」聚合一条（同机构不重复轰炸） */
            foreach ((array) $item['groups'] as $inst => $uids) {
                $inst = (int) $inst;
                if ($inst <= 0) { continue; }
                if (zw_legal_consent_notify_branch_already_sent($inst, $doc_id, $hash)) {
                    $out['stats']['branch_skipped']++;
                    continue;
                }
                $stale = 0;
                foreach ((array) $uids as $u) {
                    if ((string) ($item['pending'][$u]['code'] ?? '') === 'stale') { $stale++; }
                }
                if (!$dry && function_exists('zw_branch_add_notification')) {
                    zw_branch_add_notification($inst, 'legal_consent_pending', zw_legal_consent_notify_text($doc, count((array) $uids), $stale), 0);
                    zw_legal_consent_notify_mark_branch_sent($inst, $doc_id, $hash, $now);
                }
                $out['stats']['branch_sent']++;
                $out['channels'][] = 'branch:' . $inst;
            }

            /* 每版本每人一次（预演不写标记） */
            if (!$dry) {
                foreach (array_keys((array) $item['pending']) as $uid) {
                    if (zw_legal_consent_mark_notified((int) $uid, $doc_id, $hash, $now)) { $out['stats']['users_marked']++; }
                }
            }
        }

        $out['webhook'] = zw_legal_consent_notify_webhook($plan, $dry);
        $out['ok']      = true;

        if (function_exists('zw_mc_audit_write')) {
            $actor = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
            zw_mc_audit_write('legal_consent_notify', 'user#' . $actor, 'source=' . $source, array(
                'context' => array(
                    'source'       => $source,
                    'dry_run'      => $dry,
                    'docs'         => (int) $out['stats']['docs'],
                    'users'        => (int) $out['stats']['users'],
                    'branch_sent'  => (int) $out['stats']['branch_sent'],
                    'webhook'      => (string) ($out['webhook']['status'] ?? ($out['webhook']['reason'] ?? '')),
                ),
                'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
            ), $actor);
        }
        return $out;
    }
}

/* ───────────── 触发：每日 cron（开关关闭时不调度） ───────────── */
add_action('init', 'zw_legal_consent_notify_maybe_schedule', 30);
if (!function_exists('zw_legal_consent_notify_maybe_schedule')) {
    /**
     * 开关开启时调度每日提醒；关闭时清理计划任务（默认关闭 = 零副作用）。
     *
     * @return void
     */
    function zw_legal_consent_notify_maybe_schedule() {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) { return; }
        $hook     = ZW_LEGAL_CONSENT_NOTIFY_CRON_HOOK;
        $enabled  = zw_legal_consent_notify_enabled();
        $scheduled = wp_next_scheduled($hook);
        if ($enabled && !$scheduled) {
            wp_schedule_event(strtotime('tomorrow 09:10'), 'daily', $hook);
        } elseif (!$enabled && $scheduled) {
            wp_clear_scheduled_hook($hook);
        }
    }
}

add_action(ZW_LEGAL_CONSENT_NOTIFY_CRON_HOOK, 'zw_legal_consent_notify_cron_tick');
if (!function_exists('zw_legal_consent_notify_cron_tick')) {
    /**
     * 每日提醒任务（开关关闭时直接返回）。
     *
     * @return void
     */
    function zw_legal_consent_notify_cron_tick() {
        if (!zw_legal_consent_notify_enabled()) { return; }
        zw_legal_consent_notify_run(array('dry_run' => false, 'source' => 'cron'));
    }
}

/* ───────────── 触发：T4 管理面「一键提醒 / 预演」 ───────────── */
add_action('admin_post_' . ZW_LEGAL_CONSENT_NOTIFY_NONCE, 'zw_legal_consent_notify_admin_action');
if (!function_exists('zw_legal_consent_notify_admin_action')) {
    /**
     * 管理面按钮处理：nonce + `tcpc_perm_legal`（复用 T4 能力位）→ 执行/预演 → PRG 回明细页。
     *
     * @return void
     */
    function zw_legal_consent_notify_admin_action() {
        if (!function_exists('zw_legal_consent_admin_can') || !zw_legal_consent_admin_can()) {
            wp_die('无权限发送签署提醒。', '', array('response' => 403));
        }
        check_admin_referer(ZW_LEGAL_CONSENT_NOTIFY_NONCE);

        $doc_id = isset($_POST['doc']) ? sanitize_text_field(wp_unslash((string) $_POST['doc'])) : '';
        $dry    = !empty($_POST['dry_run']);
        $res    = zw_legal_consent_notify_run(array('dry_run' => $dry, 'doc_id' => $doc_id, 'source' => 'admin'));

        $args = array('page' => 'zw-policy-consents');
        if ($doc_id !== '') { $args['doc'] = $doc_id; }
        if ($dry) {
            $args['zw_notify'] = 'dry';
        } elseif (!empty($res['ok'])) {
            $args['zw_notify'] = ((string) ($res['reason'] ?? '') === 'NOTHING') ? 'nothing' : 'sent';
        } else {
            $args['zw_notify'] = 'skip';
        }
        $args['zw_notify_n'] = (int) ($res['stats']['users'] ?? 0);
        if (!empty($res['reason'])) { $args['zw_notify_reason'] = sanitize_key((string) $res['reason']); }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
