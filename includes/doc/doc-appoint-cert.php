<?php
/**
 * 「任」字公文生效后置通道：带印章正式版归档 + 任命书证书（TASK-1295）。
 *
 * 三条后置通道（互相独立、失败互不回滚公文状态）：
 * - **N1 前端展现（webp 统一规范）**：带印章正式版 PDF → `pdftoppm` 出图 →
 *   `imagewebp()`（质量**只**经 `zhongchuanwang_cert_image_webp_quality()` 取，
 *   SPEC-89：原图 50 / 缩略图 65）→ 落 `/opt/zw-cert/cache`（既有代理缓存口径）→
 *   前端经既有 `zw_cert_image` 代理 + `ZW_Cert_Image_Guard` 归属守卫展示；
 *   **前端不下发 PDF**，PDF 原件只做后台归档下载。
 * - **N2 归档**：PDF 经**唯一 canonical 写入口** `zw_member_files_store_local()`
 *   （`inc/files/member-files.php`）登记进 `zw_office_file`：
 *   分支 = `scope=branch` + `kind=branch_dispatch` + `branch_id`；
 *   本级 = `scope=office` + `kind=notice`（**不与 branch 混**）。
 * - **N3 任命书证书**：LWEMS 模板 267，`ZW_LwemsClient::push_cert_data()` →
 *   `issue_cert()`（照既有先例 `member-center.php:2875-2897`，不自造请求/签名）；
 *   台账 CPT `zw_cert_push` 的**快照先过 `zw_mc_mask_cert_payload()`**（不落明文）。
 *
 * 用户锁定口径（不得擅改）：
 * - 任字文件与任命书都**不含身份证号展示**；共同业务字段＝姓名·分支机构名称·任职起始日期·发文日期。
 * - 267 的「身份证号」**明文推送**给证书系统（内部记录），**任命书图片不含该字段**，
 *   且**禁止**写入任何 post_meta / user_meta / 日志 / 快照。
 * - 267 变量**除身份证号外全推**：姓名 / 所属部门←分支机构名称 / 职务←会内职务或组织架构职务 /
 *   任期←任职起始~结束 / 发证日期←发文日期（台账 `_zw_doc_issue_date`，缺省回落生效日）。
 * - 前端**只给任职本人**（`appointments.items[].uid`）。
 * - 归档失败不回滚公文状态，记 `_zw_doc_appoint_archive_error`；证书失败记
 *   `_zw_doc_appoint_cert_error`；日志**禁止**出现姓名/证件/手机。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 一、口径与只读组装
 * ══════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_appoint_cert_template_id')) {
    /**
     * 任命书模板编号（LWEMS 267；实测列见 CHG-169 §一）。
     *
     * @return int
     */
    function zw_doc_appoint_cert_template_id() {
        $tid = (int) apply_filters('zw_doc_appoint_cert_template_id', 267);

        return $tid > 0 ? $tid : 267;
    }
}

if (!function_exists('zw_doc_appoint_cert_user_meta_key')) {
    /**
     * 前端「任命书」区块的用户 meta 键（前端 helper 在
     * `inc/member-center/appoint-cert-front.php` 定义，由 `functions.php` require；同名同源）。
     *
     * @return string
     */
    function zw_doc_appoint_cert_user_meta_key() {
        if (function_exists('zhongchuanwang_appoint_cert_user_meta_key')) {
            return (string) zhongchuanwang_appoint_cert_user_meta_key();
        }

        return 'zw_appoint_cert_docs';
    }
}

if (!function_exists('zw_doc_appoint_cert_trigger')) {
    /**
     * 触发口径沿用 TASK-1291 的唯一出处（approved|sealed|off）。
     *
     * @return string
     */
    function zw_doc_appoint_cert_trigger() {
        return function_exists('zw_doc_appoint_sync_trigger') ? (string) zw_doc_appoint_sync_trigger() : 'approved';
    }
}

if (!function_exists('zw_doc_appoint_cert_eligible')) {
    /**
     * 该发文当前是否允许走后置通道（状态门槛 + 文种 + 任命要素）。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $doc    发文详情。
     * @param array $meta   任命要素（`zw_doc_appoint_appoint_meta()['meta']`）。
     * @param bool  $force  人工「重新同步」放宽状态门槛（仍受 trigger=off 阻断）。
     * @return string 空串＝放行；否则为**面向操作人**的原因。
     */
    function zw_doc_appoint_cert_eligible($doc_id, $doc, $meta, $force = false) {
        if (zw_doc_appoint_cert_trigger() === 'off') {
            return '任命后置通道已关闭（zw_doc_appoint_sync_trigger=off）。';
        }
        if (!is_array($doc)) {
            return '发文不存在。';
        }
        if (!function_exists('zw_doc_appoint_is_appoint_type') || !zw_doc_appoint_is_appoint_type(zw_doc_appoint_doc_type($doc_id, $doc))) {
            return '非「任」字文种，后置通道跳过。';
        }
        $items = isset($meta['items']) && is_array($meta['items']) ? $meta['items'] : array();
        if (empty($items)) {
            return '本篇没有任命人员，后置通道跳过。';
        }
        $type = isset($meta['type']) ? (string) $meta['type'] : '';
        if (!in_array($type, array('branch', 'hq'), true)) {
            return '任命类型不合法（只能是分支机构或协会本级）。';
        }
        if ($force) {
            $ok = in_array((string) $doc['status'], array('approved'), true)
                || (string) $doc['seal_mode'] === 'formal'
                || !empty($doc['locked']);
        } else {
            $ok = function_exists('zw_doc_appoint_syncable') ? (bool) zw_doc_appoint_syncable($doc_id, $doc) : false;
        }

        return $ok ? '' : ('当前状态（' . (string) $doc['status'] . '）与触发口径不符，后置通道跳过。');
    }
}

if (!function_exists('zw_doc_appoint_cert_issue_date')) {
    /**
     * 发证日期 ← 发文日期（台账 `_zw_doc_issue_date`，缺省回落生效日）。
     *
     * @param array $doc 发文详情。
     * @return string ISO 日期（Y-m-d）；取不到返回空串。
     */
    function zw_doc_appoint_cert_issue_date($doc) {
        $issue = is_array($doc) && isset($doc['issue_date']) ? trim((string) $doc['issue_date']) : '';
        if ($issue === '') {
            $issue = is_array($doc) && isset($doc['approved_at']) ? substr(trim((string) $doc['approved_at']), 0, 10) : '';
        }
        if ($issue === '') {
            $issue = function_exists('current_time') ? substr((string) current_time('mysql'), 0, 10) : gmdate('Y-m-d');
        }
        if (function_exists('zw_doc_appoint_is_date') && !zw_doc_appoint_is_date($issue)) {
            return '';
        }

        return $issue;
    }
}

if (!function_exists('zw_doc_appoint_cert_cn_date')) {
    /**
     * ISO 日期 → 证书系统口径中文日期（复用公文侧 canonical，禁止第二套）。
     *
     * @param string $iso ISO 日期。
     * @return string
     */
    function zw_doc_appoint_cert_cn_date($iso) {
        if (function_exists('zw_doc_composer_cn_date')) {
            return (string) zw_doc_composer_cn_date($iso);
        }

        return (string) $iso;
    }
}

if (!function_exists('zw_doc_appoint_cert_identity')) {
    /**
     * 取任命书的「身份证号」**明文**（仅用于推送给证书系统）。
     *
     * 取数口径与 TASK-1244 一致：专用归档键优先 + 密文透明解密，只接受规范化 18 位。
     * **调用方禁止**把返回值写入任何 meta / 日志 / 快照。
     *
     * @param int $uid 用户 ID。
     * @return string 明文证件号；取不到返回空串。
     */
    function zw_doc_appoint_cert_identity($uid) {
        $uid = (int) $uid;
        if ($uid <= 0) {
            return '';
        }
        // TASK-1244 同口径：专用归档键优先（分支机构负责人身份 canonical，密文透明解密）。
        if (function_exists('zw_branch_user_identity')) {
            $identity = zw_branch_user_identity($uid);
            $plain    = isset($identity['id_card']) ? (string) $identity['id_card'] : '';
            if (function_exists('zw_idcard_is_plain') && zw_idcard_is_plain($plain)) {
                return function_exists('zw_idcard_normalize') ? zw_idcard_normalize($plain) : $plain;
            }
        }
        // 回退：实名模块密文（透明解密），仍只接受规范化 18 位。
        $raw   = function_exists('get_user_meta') ? (string) get_user_meta($uid, 'zw_id_card', true) : '';
        $plain = '';
        if (class_exists('ZW_Realname') && method_exists('ZW_Realname', 'decrypt')) {
            $plain = (string) ZW_Realname::decrypt($raw);
        } elseif (function_exists('zw_idcard_is_plain') && zw_idcard_is_plain($raw)) {
            $plain = $raw;
        }
        if (function_exists('zw_idcard_is_plain') && zw_idcard_is_plain($plain)) {
            return function_exists('zw_idcard_normalize') ? zw_idcard_normalize($plain) : $plain;
        }

        return '';
    }
}

if (!function_exists('zw_doc_appoint_cert_dept_of')) {
    /**
     * 「所属部门」← 分支机构名称（branch）/ 组织架构部门名（hq）。
     *
     * @param string $type 通道 branch|hq。
     * @param array  $meta 任命要素。
     * @param array  $item 任命行。
     * @return string
     */
    function zw_doc_appoint_cert_dept_of($type, $meta, $item) {
        if ($type === 'hq') {
            $dept = isset($meta['org_dept']) ? (string) $meta['org_dept'] : '';

            return function_exists('zw_doc_appoint_org_dept_name') ? (string) zw_doc_appoint_org_dept_name($dept) : $dept;
        }

        return trim((string) (isset($item['branch']) ? $item['branch'] : ''));
    }
}

if (!function_exists('zw_doc_appoint_cert_duty_of')) {
    /**
     * 「职务」← 会内职务（branch）/ 组织架构职务名（hq）。
     *
     * @param string $type 通道 branch|hq。
     * @param array  $meta 任命要素。
     * @param array  $item 任命行。
     * @return string
     */
    function zw_doc_appoint_cert_duty_of($type, $meta, $item) {
        if ($type === 'hq') {
            $duty = isset($meta['org_duty']) ? (string) $meta['org_duty'] : '';

            return function_exists('zw_doc_appoint_org_duty_name') ? (string) zw_doc_appoint_org_duty_name($duty) : $duty;
        }
        if (!function_exists('zw_doc_appoint_duty_label')) {
            return (string) (isset($item['duty']) ? $item['duty'] : '');
        }

        return (string) zw_doc_appoint_duty_label(
            isset($item['duty']) ? (string) $item['duty'] : '',
            isset($item['duty_other']) ? (string) $item['duty_other'] : ''
        );
    }
}

if (!function_exists('zw_doc_appoint_cert_term_of')) {
    /**
     * 「任期」← 任职起始 ~ 结束（hq 缺省回落届次周期唯一出处）。
     *
     * @param string $type 通道 branch|hq。
     * @param array  $meta 任命要素。
     * @return string
     */
    function zw_doc_appoint_cert_term_of($type, $meta) {
        $start = isset($meta['term_start']) ? trim((string) $meta['term_start']) : '';
        $end   = isset($meta['term_end']) ? trim((string) $meta['term_end']) : '';
        if ($type === 'hq') {
            $start = isset($meta['org_term_start']) && (string) $meta['org_term_start'] !== '' ? (string) $meta['org_term_start'] : '';
            $end   = isset($meta['org_term_end']) && (string) $meta['org_term_end'] !== '' ? (string) $meta['org_term_end'] : '';
            if (($start === '' || $end === '') && function_exists('zw_doc_appoint_term_dates')) {
                $default = zw_doc_appoint_term_dates(isset($meta['org_term_name']) ? (string) $meta['org_term_name'] : '');
                if ($start === '' && !empty($default['start'])) {
                    $start = (string) $default['start'];
                }
                if ($end === '' && !empty($default['end'])) {
                    $end = (string) $default['end'];
                }
            }
        }
        $left  = zw_doc_appoint_cert_cn_date($start);
        $right = zw_doc_appoint_cert_cn_date($end);
        if ($left === '' && $right === '') {
            return '';
        }
        if ($left === '') {
            return $right;
        }
        if ($right === '') {
            return $left;
        }

        return $left . '~' . $right;
    }
}

if (!function_exists('zw_doc_appoint_cert_vars')) {
    /**
     * 组装 267 证书变量（**除身份证号外全推**，避免版面留空）。
     *
     * 返回的 `身份证号` 为**明文**，调用方在推送后**必须**用 `zw_mc_mask_cert_payload()`
     * 脱敏快照，且**禁止**落任何 meta / 日志。
     *
     * @param array $doc  发文详情。
     * @param array $meta 任命要素。
     * @param array $item 任命行。
     * @return array<string,string>
     */
    function zw_doc_appoint_cert_vars($doc, $meta, $item) {
        $type = isset($meta['type']) ? (string) $meta['type'] : 'branch';
        $vars = array(
            '姓名'      => trim((string) (isset($item['name']) ? $item['name'] : '')),
            '所属部门'  => zw_doc_appoint_cert_dept_of($type, $meta, $item),
            '职务'      => zw_doc_appoint_cert_duty_of($type, $meta, $item),
            '任期'      => zw_doc_appoint_cert_term_of($type, $meta),
            '发证日期'  => zw_doc_appoint_cert_cn_date(zw_doc_appoint_cert_issue_date($doc)),
            '身份证号'  => zw_doc_appoint_cert_identity((int) (isset($item['uid']) ? $item['uid'] : 0)),
        );

        return $vars;
    }
}

if (!function_exists('zw_doc_appoint_cert_vars_hash')) {
    /**
     * 幂等哈希：只覆盖**可公开**业务字段（不含身份证号）。
     *
     * @param array $vars 证书变量。
     * @return string
     */
    function zw_doc_appoint_cert_vars_hash($vars) {
        $public = array();
        foreach (array('姓名', '所属部门', '职务', '任期', '发证日期') as $key) {
            $public[$key] = isset($vars[$key]) ? (string) $vars[$key] : '';
        }

        return md5((string) wp_json_encode($public, JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('zw_doc_appoint_cert_items')) {
    /**
     * 只保留**有 uid** 的任命行（前端只给任职本人；无账号无法归属）。
     *
     * @param array $meta 任命要素。
     * @return array[]
     */
    function zw_doc_appoint_cert_items($meta) {
        $out = array();
        foreach ((array) (isset($meta['items']) ? $meta['items'] : array()) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((int) (isset($item['uid']) ? $item['uid'] : 0) <= 0) {
                continue;
            }
            if (trim((string) (isset($item['name']) ? $item['name'] : '')) === '') {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 二、N3 任命书证书（推送 + 颁发 + 台账脱敏 + 幂等）
 * ══════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_appoint_cert_ledger_open')) {
    /**
     * 开推送台账（`zw_cert_push`）：**先写记录后调**，与会员证制证同纪律。
     *
     * 快照先过 `zw_mc_mask_cert_payload()`；该函数缺失时**不落快照**（绝不落明文）。
     *
     * ⚠️ **字段口径必须与 canonical 写入侧一致**（TASK-1295 Ralph 轮 8 修复）：
     * `zw_push_created_at` 在 `member-center.php:2864` 写的是 **Unix 时间戳（int）**，
     * 且**唯一消费者**是「制证推送记录」页 `member-center.php:4210`
     * `gmdate('Y-m-d H:i', (int) get_post_meta(...) + 8*3600)` —— 早期本模块写
     * `current_time('mysql')`（如 `2026-10-09 00:10:00`），`(int)` 转型只取到年份 `2026`
     * ⇒ 该页对**每一条任命书台账**都渲染成 `1970-01-01 08:33:46`（可见的脏数据）。
     * 故这里改为 `time()`，并把操作人**在建账时**即落库（与 canonical 同纪律：
     * 推送失败的行也要能追责，而原实现只在推送成功后才写 `zw_push_operator`）。
     *
     * @param int   $doc_id 发文 ID。
     * @param int   $uid    任职人。
     * @param array $vars   证书变量（含明文身份证号）。
     * @param int   $actor  操作人（0 = 系统 / 未登录）。
     * @return int 台账 post ID；0 表示创建失败。
     */
    function zw_doc_appoint_cert_ledger_open($doc_id, $uid, $vars, $actor = 0) {
        if (!function_exists('wp_insert_post')) {
            return 0;
        }
        $snapshot = '';
        if (function_exists('zw_mc_mask_cert_payload')) {
            $snapshot = (string) wp_json_encode(zw_mc_mask_cert_payload($vars), JSON_UNESCAPED_UNICODE);
        }
        $pushId = wp_insert_post(array(
            'post_type'   => 'zw_cert_push',
            'post_status' => 'publish',
            'post_title'  => 'appoint-' . (int) $doc_id . '-u' . (int) $uid,
            'meta_input'  => array(
                'zw_push_template'   => zw_doc_appoint_cert_template_id(),
                'zw_push_user'       => (int) $uid,
                'zw_push_doc'        => (int) $doc_id,
                'zw_push_source'     => 'doc_appoint',
                'zw_push_status'     => 'pending',
                'zw_push_payload'    => $snapshot,
                // 与 canonical 写入侧（`member-center.php:2864`）同为 int 时间戳；见上方 ⚠️。
                'zw_push_created_at' => function_exists('time') ? time() : (int) gmdate('U'),
                'zw_push_operator'   => (int) $actor,
            ),
        ));

        return $pushId ? (int) $pushId : 0;
    }
}

if (!function_exists('zw_doc_appoint_cert_ledger_find')) {
    /**
     * 在台账 CPT `zw_cert_push` 里定位某人在某篇任命上的**最新一条**记录 ID（只读）。
     *
     * 查询口径的**唯一出处**：`zw_doc_appoint_cert_ledger_cert_no()`（读号）与
     * `zw_doc_appoint_cert_ledger_recover()`（自愈回填）共用，避免两处各写一份
     * `meta_query` 而在后续改动中漂移。
     *
     * @param int    $doc_id 发文 ID。
     * @param int    $uid    任职人。
     * @param string $status 期望的 `zw_push_status`；空串 = 不限状态。
     * @return int 台账 post ID；无记录返回 0。
     */
    function zw_doc_appoint_cert_ledger_find($doc_id, $uid, $status = 'issued') {
        $doc_id = (int) $doc_id;
        $uid    = (int) $uid;
        if ($doc_id <= 0 || $uid <= 0 || !function_exists('get_posts')) {
            return 0;
        }
        $meta = array(
            'relation' => 'AND',
            array('key' => 'zw_push_doc', 'value' => $doc_id),
            array('key' => 'zw_push_user', 'value' => $uid),
            array('key' => 'zw_push_template', 'value' => zw_doc_appoint_cert_template_id()),
        );
        if ((string) $status !== '') {
            $meta[] = array('key' => 'zw_push_status', 'value' => (string) $status);
        }
        $ids = get_posts(array(
            'post_type'   => 'zw_cert_push',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'orderby'     => 'ID',
            'order'       => 'DESC',
            'meta_query'  => $meta,
        ));
        if (!is_array($ids) || empty($ids)) {
            return 0;
        }

        return (int) $ids[0];
    }
}

if (!function_exists('zw_doc_appoint_cert_ledger_cert_no')) {
    /**
     * 从推送台账 CPT `zw_cert_push` 回查某人在某篇任命上的**已颁发证书号**（只读）。
     *
     * 幂等标记 `_zw_doc_appoint_cert_issued` 只存哈希（不含证书号），故重复触发时
     * 必须回台账取号，才能回查证书图（见 `zw_doc_appoint_cert_issue()` 幂等分支）。
     * 台账是既有 CPT（`member-center.php` 注册），字段 `zw_push_doc` / `zw_push_user` /
     * `zw_push_template` / `zw_push_status=issued` / `zw_push_cert_no` 均由本模块写入。
     *
     * ⚠️ 本函数**纯读**；台账行 `status=issued` 但编号为空时的自愈见
     * `zw_doc_appoint_cert_ledger_recover()`（Ralph 轮 8）。
     *
     * @param int $doc_id 发文 ID。
     * @param int $uid    任职人。
     * @return string 证书号；无记录 / 取不到返回空串。
     */
    function zw_doc_appoint_cert_ledger_cert_no($doc_id, $uid) {
        if (!function_exists('get_post_meta')) {
            return '';
        }
        $pushId = zw_doc_appoint_cert_ledger_find($doc_id, $uid, 'issued');
        if ($pushId <= 0) {
            return '';
        }

        return trim((string) get_post_meta($pushId, 'zw_push_cert_no', true));
    }
}

if (!function_exists('zw_doc_appoint_cert_ledger_recover')) {
    /**
     * 幂等跳过时的证书号**自愈回填**（TASK-1295 Ralph 轮 8）。
     *
     * 真实可达的失败链：首次颁发时 `ZW_LwemsClient::issue_cert()` 已成功（台账
     * `zw_push_status=issued`），但紧随其后的 `zw_mc_cert_lookup_no()` 因「证书行尚不可见」
     * 返回空串 —— canonical 写入侧自己也是这个口径（`member-center.php:2906-2908` 注释逐字：
     * 「做短暂重试；仍缺失时保留 issued，由列表/预览自愈回填」），并为此提供了 canonical
     * `zw_mc_cert_recover_no($pushId,…)`（`member-center.php:2695+`，回查编号并回写）。
     *
     * 而本模块的幂等键只覆盖**可公开业务字段**（`zw_doc_appoint_cert_vars_hash()` 不含证书号）
     * ⇒ 重复触发必然走**幂等跳过**分支：若跳过分支只读台账，`zw_push_cert_no` 为空时**永远**
     * 补不上 —— `zw_doc_appoint_cert_run()` 取不到 cert_no ⇒ 不回查任命书图 ⇒ 前端
     * 「任命书」区块只剩带印章件（证书图缺失），而 `/user/mycert/` 已把 267 从「个人会籍证书」
     * 列表剔除 ⇒ **证书颁了前端看不到**（正是本任务要修的反面）。
     *
     * 边界（R5 / R7 不受影响）：只在 `status=issued` 且编号为空时调 canonical 自愈；
     * 自愈**只回查并回写证书编号**，不触碰公文审批/用印状态、不写任何个人信息、不回滚任何东西。
     *
     * @param int $doc_id 发文 ID。
     * @param int $uid    任职人。
     * @return string 回填后的证书号；仍取不到返回空串。
     */
    function zw_doc_appoint_cert_ledger_recover($doc_id, $uid) {
        if (!function_exists('get_post_meta')) {
            return '';
        }
        $pushId = zw_doc_appoint_cert_ledger_find($doc_id, $uid, 'issued');
        if ($pushId <= 0) {
            return '';
        }
        $certNo = trim((string) get_post_meta($pushId, 'zw_push_cert_no', true));
        if ($certNo !== '') {
            return $certNo;
        }
        // canonical 自愈缺失（未加载会员中心 / 测试替身未提供）时**不臆造**编号。
        if (!function_exists('zw_mc_cert_recover_no') || !function_exists('get_post_type')) {
            return '';
        }

        return trim((string) zw_mc_cert_recover_no($pushId, 2, 400));
    }
}

if (!function_exists('zw_doc_appoint_cert_backend')) {
    /**
     * 取任命书应使用的证书后端实现（TASK-1802）。
     *
     * 与会员中心 `zw_mc_push_cert()` 走**同一条分发口径**：`zw_cert_backend_map`
     * （template_id => 'local'，缺省 lwems）+ `zw_cert_backend` filter。
     * 证书模块缺失时返回 null（调用方按 LWEMS 原路径处理，绝不因模块缺失而中断任命书颁发）。
     *
     * @param int $templateId 证书模板 ID。
     * @return ZW_Cert_Backend|null
     */
    function zw_doc_appoint_cert_backend($templateId) {
        // ⚠️ `ZW_Cert_Backend` 是 **interface**：`class_exists()` 对它恒为 false，
        // 判定一律用 `interface_exists()`（否则分发恒返回 null，静默回退 LWEMS）。
        // TASK-2203：证书后端已移入插件 zw-cert，路径解析统一走 `zw_cert_backend_file()`
        // （候选链：插件常量 → wp-content/plugins → 主题内嵌副本 → ≤1.1.556 旧主题 inc/cert）。
        // 候选链文件按 __DIR__ 直接 require，不依赖 loader 时序（否则独立入口会静默回退 LWEMS）。
        if (!interface_exists('ZW_Cert_Backend')) {
            if (!function_exists('zw_cert_backend_file')) {
                // OA-2450：本文件迁入插件后，`__DIR__ . '/../../cert-paths.php'` 解析到**插件根**（无该文件）
                // ⇒ 旧写法在生产之外（CI/CLI/主题未装载）会直接 fatal。改为「存在才 require」；
                // 解析不到 `zw_cert_backend_file()` 时按本函数既有口径返回 null（调用方走 LWEMS 原路径），
                // 绝不因路径解析中断任命书颁发。
                $zw_cert_paths_fallback = dirname(__DIR__, 2) . '/cert-paths.php';
                if (is_file($zw_cert_paths_fallback)) {
                    require_once $zw_cert_paths_fallback;
                }
                unset($zw_cert_paths_fallback);
            }
            if (function_exists('zw_cert_backend_file')) {
                $module = zw_cert_backend_file();
                if ($module !== '') {
                    require_once $module;
                }
                unset($module);
            }
        }
        if (!interface_exists('ZW_Cert_Backend') || !function_exists('zw_cert_backend')) {
            return null;
        }

        return zw_cert_backend((int) $templateId);
    }
}

if (!function_exists('zw_doc_appoint_cert_issue_local')) {
    /**
     * 本地后端颁发单张任命书（TASK-1802）：台账先落行 → 取号/状态机/primary 入队。
     *
     * 与 LWEMS 段的差别只在**受理步骤**：本地后端无远端受理，`pushed`/`issued` 同刻落定，
     * 证书号由 `Zw_Cert_Serial` 取 `N{年份}-{5位}`（与会员制证共用同一编号空间）。
     * 任命书没有会员申请单 ⇒ `app_id=0`（申请单侧收尾由后端自行跳过）。
     *
     * @param int    $doc_id 发文 ID（测试/无公文场景可为 0）。
     * @param int    $uid    任职人。
     * @param int    $tid    模板 ID。
     * @param array  $vars   证书变量（姓名/所属部门/职务/任期/发证日期/身份证号）。
     * @param int    $actor  操作人。
     * @return array{ok:bool,message:string,cert_no:string,push_id:int}
     */
    function zw_doc_appoint_cert_issue_local($doc_id, $uid, $tid, $vars, $actor = 0) {
        $pushId = zw_doc_appoint_cert_ledger_open($doc_id, $uid, $vars, (int) $actor);
        if ($pushId <= 0) {
            return array('ok' => false, 'message' => '证书台账写入失败。', 'cert_no' => '', 'push_id' => 0);
        }

        $result = zw_cert_backend((int) $tid)->issue(array(
            'push_id'     => (int) $pushId,
            'app_id'      => 0, // 任命书无会员申请单（TASK-1802）
            'template_id' => (int) $tid,
            'level_key'   => '',
            'cert_data'   => (array) $vars,
            'operator_id' => (int) $actor,
        ));

        // 失败留痕由后端写入（failed + zw_push_error）；此处只补操作人，不覆盖后端错误信息。
        update_post_meta($pushId, 'zw_push_operator', (int) $actor);

        if (empty($result['ok'])) {
            return array(
                'ok'      => false,
                'message' => (string) (isset($result['message']) ? $result['message'] : '本地制证失败'),
                'cert_no' => '',
                'push_id' => (int) $pushId,
            );
        }

        $certNo = trim((string) (isset($result['cert_no']) ? $result['cert_no'] : ''));
        if ($certNo === '') {
            $certNo = trim((string) get_post_meta($pushId, 'zw_push_cert_no', true));
        }

        return array(
            'ok'      => true,
            'message' => '任命书已颁发（本地 ' . $certNo . '）。',
            'cert_no' => $certNo,
            'push_id' => (int) $pushId,
        );
    }
}

if (!function_exists('zw_doc_appoint_cert_issue_one')) {
    /**
     * 单人颁发：推送 → 颁发 → 台账收敛；**失败只留痕**，绝不回滚公文。
     *
     * TASK-1802：按模板后端分发。`backend=local` 走本地取号 + 本地渲染入队
     * （`zw_doc_appoint_cert_issue_local()`）；其余保持 LWEMS 原路径逐行不变。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $doc    发文详情。
     * @param array $meta   任命要素。
     * @param array $item   任命行（必须带 uid）。
     * @param int   $actor  操作人。
     * @return array{ok:bool,message:string,cert_no:string,push_id:int}
     */
    function zw_doc_appoint_cert_issue_one($doc_id, $doc, $meta, $item, $actor = 0) {
        $uid  = (int) (isset($item['uid']) ? $item['uid'] : 0);
        $vars = zw_doc_appoint_cert_vars($doc, $meta, $item);
        $tid  = zw_doc_appoint_cert_template_id();
        if ($uid <= 0 || $vars['姓名'] === '') {
            return array('ok' => false, 'message' => '任命行缺少账号或姓名。', 'cert_no' => '', 'push_id' => 0);
        }

        $backend = zw_doc_appoint_cert_backend($tid);
        if ($backend instanceof ZW_Cert_Backend_Local) {
            return zw_doc_appoint_cert_issue_local($doc_id, $uid, $tid, $vars, $actor);
        }

        // OA-2443（契约轮）：实名客户端改走主题契约，不再直取主题内部类路径
        // （ci-check 反向依赖红线；契约提供方：主题 inc/oa/contract-doc.php 的 zw_oa_realname_client_ready）。
        if (!function_exists('zw_oa_realname_client_ready') || !zw_oa_realname_client_ready()) {
            return array('ok' => false, 'message' => 'LWEMS 客户端不可用。', 'cert_no' => '', 'push_id' => 0);
        }

        $pushId = zw_doc_appoint_cert_ledger_open($doc_id, $uid, $vars, (int) $actor);
        // R6：只按既有先例调用（不自造请求 / 不自造签名）。
        $pushed = ZW_LwemsClient::push_cert_data($tid, $vars);
        if (empty($pushed['ok'])) {
            if ($pushId > 0) {
                update_post_meta($pushId, 'zw_push_status', 'failed');
                update_post_meta($pushId, 'zw_push_error', sanitize_text_field((string) $pushed['message']));
            }

            return array('ok' => false, 'message' => 'certpushdata 失败：' . (string) $pushed['message'], 'cert_no' => '', 'push_id' => $pushId);
        }
        $applyId = (int) $pushed['apply_id'];
        if ($pushId > 0) {
            update_post_meta($pushId, 'zw_push_apply_id', $applyId);
            update_post_meta($pushId, 'zw_push_status', 'pushed');
            update_post_meta($pushId, 'zw_push_pushed_at', time());
            update_post_meta($pushId, 'zw_push_operator', (int) $actor);
        }
        $issued = ZW_LwemsClient::issue_cert($tid, $applyId);
        if (empty($issued['ok'])) {
            if ($pushId > 0) {
                update_post_meta($pushId, 'zw_push_status', 'issue_failed');
                update_post_meta($pushId, 'zw_push_error', sanitize_text_field((string) $issued['message']));
            }

            return array('ok' => false, 'message' => 'certissue 失败：' . (string) $issued['message'], 'cert_no' => '', 'push_id' => $pushId);
        }
        $certNo = '';
        if (function_exists('zw_mc_cert_lookup_no')) {
            $certNo = (string) zw_mc_cert_lookup_no($applyId, $tid, 3, 800);
        }
        if ($pushId > 0) {
            update_post_meta($pushId, 'zw_push_status', 'issued');
            update_post_meta($pushId, 'zw_push_issued_at', time());
            if ($certNo !== '') {
                update_post_meta($pushId, 'zw_push_cert_no', $certNo);
            }
        }

        return array('ok' => true, 'message' => '任命书已颁发。', 'cert_no' => $certNo, 'push_id' => $pushId);
    }
}

if (!function_exists('zw_doc_appoint_cert_issue')) {
    /**
     * N3：逐人颁发任命书（幂等键 `doc_id + uid + 模板267`）。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $doc    发文详情。
     * @param array $meta   任命要素。
     * @param int   $actor  操作人。
     * @param array $args   可选：`force` 忽略错误重试。
     * @return array{ok:int,failed:int,skipped:int,problems:string[],issued:array<int,string>}
     */
    function zw_doc_appoint_cert_issue($doc_id, $doc, $meta, $actor = 0, $args = array()) {
        $out = array('ok' => 0, 'failed' => 0, 'skipped' => 0, 'problems' => array(), 'issued' => array());
        $doc_id = (int) $doc_id;
        $tid    = zw_doc_appoint_cert_template_id();
        $issued = function_exists('get_post_meta') ? (array) json_decode((string) get_post_meta($doc_id, '_zw_doc_appoint_cert_issued', true), true) : array();
        if (!is_array($issued)) {
            $issued = array();
        }
        $errors = function_exists('get_post_meta') ? (array) json_decode((string) get_post_meta($doc_id, '_zw_doc_appoint_cert_error', true), true) : array();
        if (!is_array($errors)) {
            $errors = array();
        }

        foreach (zw_doc_appoint_cert_items($meta) as $item) {
            $uid  = (int) $item['uid'];
            $vars = zw_doc_appoint_cert_vars($doc, $meta, $item);
            $key  = sha1($doc_id . '|' . $uid . '|' . $tid . '|' . zw_doc_appoint_cert_vars_hash($vars));
            if (isset($issued[$uid]) && (string) $issued[$uid] === $key) {
                $out['skipped']++;
                // TASK-1295（Ralph 轮 7）：**幂等跳过也必须回填证书号**。
                // 幂等标记只存哈希，不含 cert_no；若此处不回查台账，
                // `zw_doc_appoint_cert_run()` 就取不到 cert_no ⇒ 既不回查证书图，
                // 又（在修复前）把 user_meta 里已有的图片清成空数组。
                // 回填后：重复触发成为**自愈路径**（首次 `zw_mc_cert_lookup_no` 为空 /
                // 出图尚未就绪时，下一次重新同步即可补齐任命书图片）。
                $certNo = zw_doc_appoint_cert_ledger_cert_no($doc_id, $uid);
                if ($certNo === '') {
                    // 轮 8：只读回查取不到 ⇒ 走 canonical 自愈（`status=issued` 但编号为空的真实场景）。
                    // 修复前这里是**死路**：编号永远补不上 ⇒ 任命书图永远取不到。
                    $certNo = zw_doc_appoint_cert_ledger_recover($doc_id, $uid);
                }
                if ($certNo !== '') {
                    $out['issued'][$uid] = $certNo;
                }
                continue;
            }
            $one = zw_doc_appoint_cert_issue_one($doc_id, $doc, $meta, $item, $actor);
            if (!empty($one['ok'])) {
                $issued[$uid] = $key;
                unset($errors[$uid]);
                $out['ok']++;
                $out['issued'][$uid] = (string) $one['cert_no'];
            } else {
                $out['failed']++;
                $errors[$uid] = (string) $one['message'];
                // 脱敏：问题描述只保留接口消息，不含姓名 / 证件（本函数不把姓名写入 meta）。
                $out['problems'][] = 'uid#' . $uid . '：' . (string) $one['message'];
            }
        }

        if (function_exists('update_post_meta')) {
            if ($out['ok'] > 0 || !empty($issued)) {
                update_post_meta($doc_id, '_zw_doc_appoint_cert_issued', wp_json_encode($issued, JSON_UNESCAPED_UNICODE));
            } else {
                // 全部失败 ⇒ 不写幂等标记（可无限重试），与 TASK-1291 同纪律。
                delete_post_meta($doc_id, '_zw_doc_appoint_cert_issued');
            }
            if (!empty($errors)) {
                update_post_meta($doc_id, '_zw_doc_appoint_cert_error', wp_json_encode($errors, JSON_UNESCAPED_UNICODE));
            } else {
                delete_post_meta($doc_id, '_zw_doc_appoint_cert_error');
            }
        }
        if (function_exists('zw_doc_log_event')) {
            // 脱敏：只记人数与结果，禁止姓名 / 证件 / 手机。
            zw_doc_log_event(
                $doc_id,
                'appoint_cert',
                (int) $actor,
                '',
                '',
                '任命书（模板' . $tid . '）：成功 ' . (int) $out['ok'] . ' / 跳过 ' . (int) $out['skipped'] . ' / 失败 ' . (int) $out['failed']
            );
        }

        return $out;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 三、N2 归档（唯一 canonical 写入口）
 * ══════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_appoint_cert_pdf_path')) {
    /**
     * 取带印章正式版 PDF 的**绝对路径**（仅服务端使用；前端永不下载）。
     *
     * @param int $doc_id 发文 ID。
     * @return string 空串＝尚未生成 / 不可读。
     */
    function zw_doc_appoint_cert_pdf_path($doc_id) {
        if (!function_exists('zw_doc_pdf_status') || !function_exists('zw_doc_pdf_dir')) {
            return '';
        }
        $status = zw_doc_pdf_status((int) $doc_id);
        if ((string) $status['status'] !== 'ready' || (string) $status['file'] === '') {
            return '';
        }
        $path = zw_doc_pdf_dir() . '/' . basename((string) $status['file']);

        return is_readable($path) ? $path : '';
    }
}

if (!function_exists('zw_doc_appoint_cert_archive_targets')) {
    /**
     * 归档落点（分支 = 一机构一条；本级 = office/notice，**不与 branch 混**）。
     *
     * @param array $doc  发文详情。
     * @param array $meta 任命要素。
     * @return array[] 每项 `array(scope,kind,branch_id,branch_name,branch_subtype,key)`
     */
    function zw_doc_appoint_cert_archive_targets($doc, $meta) {
        $type    = isset($meta['type']) ? (string) $meta['type'] : 'branch';
        $targets = array();
        if ($type === 'hq') {
            $targets[] = array(
                'scope'          => 'office',
                'kind'           => 'notice',
                'branch_id'      => 0,
                'branch_name'    => '',
                'branch_subtype' => '',
                'key'            => 'office',
            );

            return $targets;
        }
        $seen = array();
        foreach ((array) (isset($meta['items']) ? $meta['items'] : array()) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $info = function_exists('zw_doc_appoint_branch_info') ? zw_doc_appoint_branch_info(isset($item['branch']) ? $item['branch'] : '') : array('id' => 0);
            $bid  = (int) (isset($info['id']) ? $info['id'] : 0);
            if ($bid <= 0 || isset($seen[$bid])) {
                continue;
            }
            $seen[$bid] = true;
            $targets[]  = array(
                'scope'          => 'branch',
                'kind'           => 'branch_dispatch',
                'branch_id'      => $bid,
                // TASK-1386 ⑥（A 方案）：目录用**分支机构名称**，故落点必须携带名称。
                'branch_name'    => isset($info['title']) ? (string) $info['title'] : '',
                'branch_subtype' => 'dispatch',
                'key'            => 'branch-' . $bid,
            );
        }
        if (empty($targets) && (int) (isset($doc['branch_id']) ? $doc['branch_id'] : 0) > 0) {
            $bid       = (int) $doc['branch_id'];
            $targets[] = array(
                'scope'          => 'branch',
                'kind'           => 'branch_dispatch',
                'branch_id'      => $bid,
                // 兜底路径无 items 名录 ⇒ 反查名称；查不到留空，由目录规则 fail-closed（不静默落 id 目录）。
                'branch_name'    => function_exists('zw_doc_appoint_branch_title_by_id') ? (string) zw_doc_appoint_branch_title_by_id($bid) : '',
                'branch_subtype' => 'dispatch',
                'key'            => 'branch-' . $bid,
            );
        }

        return $targets;
    }
}

if (!function_exists('zw_doc_appoint_cert_archive_note')) {
    /**
     * 失败 / 待重试留痕（写 `_zw_doc_appoint_archive_error`；日志脱敏）。
     *
     * @param int    $doc_id  发文 ID。
     * @param string $problem 原因。
     * @return void
     */
    function zw_doc_appoint_cert_archive_note($doc_id, $problem) {
        if (function_exists('update_post_meta')) {
            update_post_meta((int) $doc_id, '_zw_doc_appoint_archive_error', sanitize_text_field((string) $problem));
        }
    }
}

if (!function_exists('zw_doc_appoint_cert_archive')) {
    /**
     * N2：把带印章正式版 PDF 归档进「网盘文件」（分支 / 本级）。
     *
     * **唯一写入口** `zw_member_files_store_local()`；去重键 = `doc_id + PDF sha256 + 落点`。
     * 失败 / 待重试不回滚公文状态，记 `_zw_doc_appoint_archive_error`。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $doc    发文详情。
     * @param array $meta   任命要素。
     * @param int   $actor  操作人。
     * @return array{ok:int,failed:int,skipped:int,files:array<int,int>,problems:string[],pdf:string}
     */
    function zw_doc_appoint_cert_archive($doc_id, $doc, $meta, $actor = 0) {
        $out = array('ok' => 0, 'failed' => 0, 'skipped' => 0, 'files' => array(), 'problems' => array(), 'pdf' => '');
        $doc_id = (int) $doc_id;
        if (!function_exists('zw_member_files_store_local')) {
            $out['problems'][] = '文件存储模块不可用（zw_member_files_store_local 缺失）。';
            zw_doc_appoint_cert_archive_note($doc_id, $out['problems'][0]);

            return $out;
        }
        $pdf = zw_doc_appoint_cert_pdf_path($doc_id);
        $out['pdf'] = $pdf;
        if ($pdf === '') {
            $out['problems'][] = '带印章正式版 PDF 尚未生成完成，归档待重试。';
            zw_doc_appoint_cert_archive_note($doc_id, $out['problems'][0]);

            return $out;
        }
        // 产物自检（要求已加密，与下载口径一致）。
        if (function_exists('zw_doc_pdf_verify')) {
            $check = zw_doc_pdf_verify($pdf, array('require_encrypt' => true));
            if (empty($check['ok'])) {
                $out['problems'][] = '产物校验未通过：' . implode('；', (array) $check['reasons']);
                zw_doc_appoint_cert_archive_note($doc_id, $out['problems'][0]);

                return $out;
            }
        }
        $shas = (string) (function_exists('hash_file') ? hash_file('sha256', $pdf) : '');
        $targets = zw_doc_appoint_cert_archive_targets($doc, $meta);
        if (empty($targets)) {
            $out['problems'][] = '未匹配到归档落点（分支名录未命中且无 branch_id）。';
            zw_doc_appoint_cert_archive_note($doc_id, $out['problems'][0]);

            return $out;
        }
        $done   = (array) json_decode((string) get_post_meta($doc_id, '_zw_doc_appoint_archive', true), true);
        $errors = array();
        foreach ($targets as $target) {
            $key   = (string) $target['key'];
            $stamp = sha1($doc_id . '|' . $shas . '|' . $key);
            if (isset($done[$key]['stamp']) && (string) $done[$key]['stamp'] === $stamp) {
                $out['skipped']++;
                continue;
            }
            $name = 'zw-doc-' . $doc_id . (string) (isset($doc['number']) ? $doc['number'] : '') . '.pdf';
            if (function_exists('sanitize_file_name')) {
                $name = sanitize_file_name($name);
            }
            // TASK-1386 ⑥（用户拍板 A 方案）：目录与文件名由 `netdisk_dir_rules.php` 唯一解析；
            // 这里只提供**原始事实**（名称 / 任字号 / 标题 / 发文日期），不在调用侧拼路径或命名。
            $args = array(
                'scope'          => (string) $target['scope'],
                'kind'           => (string) $target['kind'],
                'branch_id'      => (int) $target['branch_id'],
                'branch_name'    => isset($target['branch_name']) ? (string) $target['branch_name'] : '',
                'branch_subtype' => (string) $target['branch_subtype'],
                'doc_id'         => $doc_id,
                'doc_number'     => (string) (isset($doc['number']) ? $doc['number'] : ''),
                'doc_title'      => (string) (isset($doc['title']) ? $doc['title'] : ''),
                'doc_date'       => function_exists('zw_doc_appoint_cert_issue_date') ? (string) zw_doc_appoint_cert_issue_date($doc) : '',
                'source'         => 'doc_appoint',
                'uploaded_by'    => (int) $actor,
                'title'          => '任命文件·' . (string) (isset($doc['title']) ? $doc['title'] : ('发文#' . $doc_id)),
            );
            $fileId = zw_member_files_store_local($pdf, $name, $args, (int) @filesize($pdf), false);
            if (function_exists('is_wp_error') && is_wp_error($fileId)) {
                $out['failed']++;
                $errors[$key]      = $fileId->get_error_message();
                $out['problems'][] = $key . '：' . $fileId->get_error_message();
                continue;
            }
            $fileId         = (int) $fileId;
            $done[$key]     = array('stamp' => $stamp, 'file_id' => $fileId);
            $out['ok']++;
            $out['files'][$key] = $fileId;
        }
        if (function_exists('update_post_meta')) {
            update_post_meta($doc_id, '_zw_doc_appoint_archive', wp_json_encode($done, JSON_UNESCAPED_UNICODE));
            if (!empty($errors)) {
                update_post_meta($doc_id, '_zw_doc_appoint_archive_error', sanitize_text_field(implode('；', $errors)));
            } else {
                delete_post_meta($doc_id, '_zw_doc_appoint_archive_error');
            }
        }
        if (function_exists('zw_doc_log_event')) {
            // 脱敏：只记落点数与结果，禁止姓名 / 证件 / 手机。
            zw_doc_log_event(
                $doc_id,
                'appoint_archive',
                (int) $actor,
                '',
                '',
                '任命文件归档（网盘）：成功 ' . (int) $out['ok'] . ' / 跳过 ' . (int) $out['skipped'] . ' / 失败 ' . (int) $out['failed']
            );
        }

        return $out;
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 四、N1 带印章正式版 → webp（SPEC-89 统一规范）+ 前端记录
 * ══════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_appoint_cert_media_url')) {
    /**
     * 带印章正式版的**展示 URL**（合成地址，落在证书系统主机白名单前缀内）。
     *
     * 该地址**不对应证书系统上的真实文件**：代理先读本地缓存（已预热）即直出；
     * 缓存缺失时才会回源并 404（属可接受的降级，见 CHG-169 §六）。
     *
     * @param int $doc_id 发文 ID。
     * @param int $page   页码（从 1 起）。
     * @param string $sha8 PDF sha256 前 8 位（避免换版命中旧缓存）。
     * @return string
     */
    function zw_doc_appoint_cert_media_url($doc_id, $page, $sha8) {
        return 'https://zs.tcpc.org.cn/appoint-doc/' . (int) $doc_id . '/' . (string) $sha8 . '-p' . (int) $page . '.webp';
    }
}

if (!function_exists('zw_doc_appoint_cert_cache_dir')) {
    /**
     * 代理缓存目录（唯一出处 `zw_cert_image_cache_dir` 过滤器；默认 `/opt/zw-cert/cache`）。
     *
     * @return string
     */
    function zw_doc_appoint_cert_cache_dir() {
        if (function_exists('cert_image_cache_dir')) {
            return (string) cert_image_cache_dir();
        }
        $dir = function_exists('apply_filters')
            ? (string) apply_filters('zw_cert_image_cache_dir', '/opt/zw-cert/cache')
            : '/opt/zw-cert/cache';

        return rtrim($dir !== '' ? $dir : '/opt/zw-cert/cache', '/');
    }
}

if (!function_exists('zw_doc_appoint_cert_cache_key')) {
    /**
     * 代理缓存键（与 `zw_cert_image` 端点**同一算法**：`sha1(url|w)`）。
     *
     * @param string $url   展示 URL。
     * @param int    $width 缩略宽度（0 = 原图）。
     * @return string
     */
    function zw_doc_appoint_cert_cache_key($url, $width = 0) {
        return sha1((string) $url . ((int) $width > 0 ? '|w' . (int) $width : ''));
    }
}

if (!function_exists('zw_doc_appoint_cert_media_build')) {
    /**
     * N1：`pdftoppm` 逐页出图 → `imagewebp()` 预热代理缓存（原图 + 320 缩略图）。
     *
     * 质量**只**经 `zhongchuanwang_cert_image_webp_quality()` 取（SPEC-89，禁止硬编码）。
     *
     * @param int    $doc_id 发文 ID。
     * @param string $pdf    带印章正式版绝对路径。
     * @return array{ok:bool,urls:string[],message:string}
     */
    function zw_doc_appoint_cert_media_build($doc_id, $pdf) {
        $out = array('ok' => false, 'urls' => array(), 'message' => '');
        $doc_id = (int) $doc_id;
        if (!function_exists('imagewebp') || !function_exists('imagecreatefrompng')) {
            $out['message'] = 'GD WebP 支持不可用。';

            return $out;
        }
        if (!function_exists('zhongchuanwang_cert_image_webp_quality')) {
            $out['message'] = '证书图质量口径不可用（禁止硬编码质量）。';

            return $out;
        }
        if (!function_exists('shell_exec')) {
            $out['message'] = 'shell_exec 不可用，无法调用 pdftoppm。';

            return $out;
        }
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        if (in_array('shell_exec', $disabled, true)) {
            $out['message'] = 'shell_exec 被 disable_functions 禁用，无法调用 pdftoppm。';

            return $out;
        }
        $bin = '/usr/bin/pdftoppm';
        if (!is_executable($bin)) {
            $out['message'] = '缺少 pdftoppm。';

            return $out;
        }
        $cacheDir = zw_doc_appoint_cert_cache_dir();
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true)) {
            $out['message'] = '缓存目录不可用：' . $cacheDir;

            return $out;
        }
        $sha8  = substr((string) (function_exists('hash_file') ? hash_file('sha256', $pdf) : md5((string) @filemtime($pdf))), 0, 8);
        $pages = (int) get_post_meta($doc_id, '_zw_doc_pdf_pages', true);
        $pages = $pages > 0 ? $pages : 1;
        $tmp   = rtrim(sys_get_temp_dir(), '/') . '/zw-appoint-' . $doc_id . '-' . substr(sha1((string) microtime(true)), 0, 8);
        if (!@mkdir($tmp, 0700, true)) {
            $out['message'] = '临时目录创建失败。';

            return $out;
        }
        $urls = array();
        for ($page = 1; $page <= $pages; $page++) {
            $png = $tmp . '/p' . $page;
            $cmd = escapeshellarg($bin) . ' -png -r 150 -f ' . $page . ' -l ' . $page . ' -singlefile '
                . escapeshellarg($pdf) . ' ' . escapeshellarg($png) . ' 2>&1';
            @shell_exec($cmd);
            $file = $png . '.png';
            if (!is_readable($file)) {
                $out['message'] = '第 ' . $page . ' 页栅格化失败。';
                zw_doc_appoint_cert_media_rmdir($tmp);

                return $out;
            }
            $img = @imagecreatefrompng($file);
            if (!$img) {
                $out['message'] = '第 ' . $page . ' 页图像解码失败。';
                zw_doc_appoint_cert_media_rmdir($tmp);

                return $out;
            }
            // 原图（灯箱）：spec「原图 WebP 50」
            imagealphablending($img, false);
            imagesavealpha($img, true);
            // 临时名必须**全局唯一**（`wp_tempnam` 优先，先例 `member-files.php:999` / `doc-export-docx.php:893`）：
            // 缓存目录是多个渲染任务共用的，固定 `tmp-appoint-<doc>-<page>.webp` 会让并发同号任务互相踩踏
            // （A 的 rename 抢先消费掉 B 的半成品，B 随后 rename 失败但已判成功 ⇒ 静默丢图）。
            $tmpWebp = zw_doc_appoint_cert_tempnam($cacheDir, 'tmp-appoint-orig');
            $ok = @imagewebp($img, $tmpWebp, zhongchuanwang_cert_image_webp_quality(false));
            // 缩略图（卡片）：spec「缩略图 WebP 65」
            $thumb = $img;
            $sw    = imagesx($img);
            $sh    = imagesy($img);
            if ($sw > 320) {
                $nh    = (int) round($sh * 320 / $sw);
                $thumb = imagecreatetruecolor(320, max(1, $nh));
                imagecopyresampled($thumb, $img, 0, 0, 0, 0, 320, max(1, $nh), $sw, $sh);
            }
            imagedestroy($img);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $url       = zw_doc_appoint_cert_media_url($doc_id, $page, $sha8);
            $tmpThumb  = zw_doc_appoint_cert_tempnam($cacheDir, 'tmp-appoint-thumb');
            $okThumb   = @imagewebp($thumb, $tmpThumb, zhongchuanwang_cert_image_webp_quality(true));
            imagedestroy($thumb);
            if (!$ok || !$okThumb || !is_file($tmpWebp) || !is_file($tmpThumb)) {
                @unlink($tmpWebp);
                @unlink($tmpThumb);
                $out['message'] = '第 ' . $page . ' 页 WebP 转码失败。';
                zw_doc_appoint_cert_media_rmdir($tmp);

                return $out;
            }
            // 发布 = **同目录**原子改名，且**必须检查返回值**：`rename()` 在跨文件系统（EXDEV）、
            // 目标被占用（EISDIR/ENOTEMPTY）、只读挂载或权限不足时只发一条 warning 并返回 false。
            // 早期写法 `@rename(...)` 吞掉结果 ⇒ 「URL 已写进 `_zw_doc_appoint_doc_media` 与
            // user_meta、缓存目录里却没有图」的**静默丢图**：前端卡片空图、
            // `_zw_doc_appoint_media_error` 也不会留痕（N1「前端展现带印章正式版」的验收反面）。
            $finalOrig  = $cacheDir . '/' . zw_doc_appoint_cert_cache_key($url, 0) . '.webp';
            $finalThumb = $cacheDir . '/' . zw_doc_appoint_cert_cache_key($url, 320) . '.webp';
            if (!zw_doc_appoint_cert_publish($tmpWebp, $finalOrig)
                || !zw_doc_appoint_cert_publish($tmpThumb, $finalThumb)) {
                @unlink($tmpWebp);
                @unlink($tmpThumb);
                @unlink($finalOrig);
                @unlink($finalThumb);
                $out['message'] = '第 ' . $page . ' 页 WebP 发布失败（缓存目录不可写 / 跨文件系统 / 目标被占用）。';
                zw_doc_appoint_cert_media_rmdir($tmp);

                return $out;
            }
            $urls[] = $url;
        }
        zw_doc_appoint_cert_media_rmdir($tmp);
        $out['ok']   = !empty($urls);
        $out['urls'] = $urls;

        return $out;
    }
}

if (!function_exists('zw_doc_appoint_cert_in_dir')) {
    /**
     * 判断路径是否**位于**指定目录内（同目录发布的判据）。
     *
     * 目录与路径均可解析时用 `realpath()` 比较（消除 `..`、软链与尾斜杠歧义）；
     * 路径尚不存在时退回字符串前缀比较（两者都以「`$dir` + `/`」为边界，避免
     * `/opt/zw-cert/cache-evil/x` 被误判为在 `/opt/zw-cert/cache` 内）。
     *
     * @param string $path 待判路径。
     * @param string $dir  目标目录。
     * @return bool
     */
    function zw_doc_appoint_cert_in_dir($path, $dir) {
        if (!is_string($path) || $path === '') {
            return false;
        }
        $dir = rtrim((string) $dir, '/');
        if ($dir === '') {
            return false;
        }
        $realPath = @realpath($path);
        $realDir  = @realpath($dir);
        if ($realPath !== false && $realDir !== false) {
            return strpos($realPath, $realDir . '/') === 0;
        }

        return strpos((string) $path, $dir . '/') === 0;
    }
}

if (!function_exists('zw_doc_appoint_cert_publish')) {
    /**
     * 把已编码好的临时 webp **原子发布**到代理将要读取的缓存键路径（同目录改名）。
     *
     * 为什么必须检查 `rename()` 返回值：失败时（跨文件系统 EXDEV、目标是目录、只读挂载、
     * 权限不足）PHP 只发 warning 并返回 `false`。若调用方忽略结果并继续把 URL 记入
     * `_zw_doc_appoint_doc_media` / user_meta，就会出现「前端拿到 URL、缓存里没有图」的
     * **静默失败**。故本 helper：`rename` 失败 ⇒ 清理临时件并返回 `false`，
     * 由调用方留痕 `_zw_doc_appoint_media_error` 且**不下发 URL**。
     *
     * @param string $tmp   已写好的临时文件。
     * @param string $final 目标缓存键路径（`<sha1(url|w)>.webp`）。
     * @return bool 是否已就位。
     */
    function zw_doc_appoint_cert_publish($tmp, $final) {
        $tmp   = (string) $tmp;
        $final = (string) $final;
        if ($tmp === '' || $final === '' || !is_file($tmp)) {
            return false;
        }
        if (!@rename($tmp, $final)) {
            @unlink($tmp);

            return false;
        }

        return is_file($final);
    }
}

if (!function_exists('zw_doc_appoint_cert_media_rmdir')) {
    /**
     * 清理临时目录（失败不留半成品）。
     *
     * @param string $dir 目录。
     * @return void
     */
    function zw_doc_appoint_cert_media_rmdir($dir) {
        $dir = (string) $dir;
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach ((array) @glob($dir . '/*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}

if (!function_exists('zw_doc_appoint_cert_tempnam')) {
    /**
     * 在指定目录内取一个**全局唯一**的临时文件名（并发安全）。
     *
     * 背景：缓存目录 `/opt/zw-cert/cache` 由所有渲染/代理任务共用，早期写法
     * `tmp-appoint-<doc>-<page>.webp` 是**可预测**的，两个并发任务（同页重跑、批量重颁）
     * 会互相消费半成品：A 先 `rename` 成功、B 的 `rename` 因源文件已消失而失败，
     * 但 B 的成败判定发生在 `rename` **之前** ⇒ B 也判成功、实际未落盘（静默丢图）。
     *
     * 实现同既有 canonical 范式（`inc/files/member-files.php:999`、`inc/admin/doc/doc-export-docx.php:893`）：
     * 优先 `wp_tempnam()`，回退 `tempnam()`；两者都不可用时才退回带随机后缀的可写路径。
     * 返回值为**待写入路径**（`wp_tempnam` 会建出空文件，回退路径不建）。
     *
     * ⚠️ **必须把 `$dir` 传给 `wp_tempnam($filename, $dir)`**（本次修复点）：
     * WP 的实现（`wp-admin/includes/file.php`）
     * `if ( empty( $dir ) ) { $dir = get_temp_dir(); }` … `$temp_filename = $dir . $temp_filename;`
     * 在**省略 `$dir`** 时会把临时文件建到 `get_temp_dir()`（默认 `/tmp/`），随后再 `rename()`
     * 到 `/opt/zw-cert/cache` —— 两者**不同文件系统**时 `rename()` 必然失败（EXDEV）。
     * 本 helper 因此保证返回值**始终位于 `$dir` 内**（先试 `wp_tempnam($prefix,$dir)`，
     * 再试 `tempnam($dir,$prefix)`，最后用 `$dir` 内的随机名），使「发布」退化为**同目录改名**
     * （同文件系统 ⇒ 原子且不会 EXDEV）；落到 `$dir` 之外的候选一律**丢弃**，绝不返回。
     *
     * @param string $dir    目标目录（缓存目录）。
     * @param string $prefix 文件名前缀（仅用于回退路径的可读性）。
     * @return string 唯一路径（**位于 `$dir` 内**）；无法取得时返回空串（调用方据此判失败）。
     */
    function zw_doc_appoint_cert_tempnam($dir, $prefix = 'tmp-appoint') {
        $dir    = rtrim((string) $dir, '/');
        $prefix = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $prefix);
        if ($prefix === '') { $prefix = 'tmp-appoint'; }
        if ($dir === '') { return ''; }
        // ① canonical：把临时文件直接建在缓存目录内（同文件系统，发布即原子改名）。
        if (function_exists('wp_tempnam')) {
            $path = wp_tempnam($prefix, $dir);
            if (zw_doc_appoint_cert_in_dir($path, $dir)) { return (string) $path; }
        }
        // ② 回退：`tempnam()` 同样指定目录（PHP 保证在 `$dir` 内创建，失败才落系统临时目录）。
        if (function_exists('tempnam')) {
            $path = @tempnam($dir, $prefix);
            if (zw_doc_appoint_cert_in_dir($path, $dir)) { return (string) $path; }
        }
        // ③ 兜底：`$dir` 内的随机名，**绝不**退回系统临时目录（跨文件系统会破坏原子发布）。
        // 说明：`random_bytes()` 在 PHP < 7.0 或熵不足时会抛错，故用带 @ 的兼容取值，
        // 最终仍退回 `uniqid()`（唯一性弱于前两者，但只在 wp_tempnam/tempnam 双双不可用时才走到）。
        $rand = function_exists('random_bytes') ? @bin2hex(random_bytes(8)) : '';
        if ($rand === '') { $rand = str_replace('.', '', uniqid('', true)); }

        return $dir . '/' . $prefix . '-' . $rand . '.webp';
    }
}

if (!function_exists('zw_doc_appoint_cert_record_upsert')) {
    /**
     * 写前端记录（**只给任职本人**；不含身份证号）。
     *
     * @param int   $uid  任职人。
     * @param int   $doc_id 发文 ID。
     * @param array $entry 记录字段。
     * @return void
     */
    function zw_doc_appoint_cert_record_upsert($uid, $doc_id, $entry) {
        $uid    = (int) $uid;
        $doc_id = (int) $doc_id;
        if ($uid <= 0 || $doc_id <= 0 || !function_exists('get_user_meta') || !function_exists('update_user_meta')) {
            return;
        }
        $key     = zw_doc_appoint_cert_user_meta_key();
        $records = get_user_meta($uid, $key, true);
        $records = is_array($records) ? array_values($records) : array();
        // TASK-1295（Ralph 轮 7）：**按 `doc_id` 字段定位既有记录**。
        // 存储形态是**列表**（写入前 `array_values()`），早期实现却用 `$records[$doc_id]`
        // 读写 ⇒ 第二次触发时读不到既有记录（`$prev` 恒为空），同时**再追加一条**同
        // `doc_id` 的记录：user_meta 里出现重复卡片（前端「任命书」区块重复渲染，
        // 且其中一条可能因本次 media/证书失败而缺图）。
        $prev = array();
        $at   = -1;
        foreach ($records as $zw_i => $zw_rec) {
            if (is_array($zw_rec) && (int) (isset($zw_rec['doc_id']) ? $zw_rec['doc_id'] : 0) === $doc_id) {
                $prev = $zw_rec;
                $at   = (int) $zw_i;
                break;
            }
        }
        $entry = is_array($entry) ? $entry : array();
        // 空值不得覆盖既有图：重复触发时 `zw_doc_appoint_cert_issue()` 走**幂等跳过**
        // （不返回 cert_no）且 `media_sync` 可能本次失败 ⇒ `$entry` 里 `imgs`/`doc_imgs`
        // 都是空数组；若照旧覆盖，就会把上一轮已取到的任命书图 / 带印章 webp
        // **从 user_meta 里抹掉**，前端「任命书」区块随之空图（与轮 6 同属「静默丢图」类）。
        // 语义：图只在**本次确实取到**时才替换（换版新 URL 仍能覆盖旧 URL）。
        foreach (array('imgs', 'doc_imgs') as $k) {
            if (array_key_exists($k, $entry) && empty($entry[$k]) && !empty($prev[$k])) {
                unset($entry[$k]);
            }
        }
        $merged = array_merge(
            array('doc_id' => $doc_id, 'template_id' => zw_doc_appoint_cert_template_id(), 'category' => '任命书'),
            $prev,
            $entry
        );
        if ($at >= 0) {
            $records[$at] = $merged;
        } else {
            $records[] = $merged;
        }
        update_user_meta($uid, $key, array_values($records));
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 五、编排与钩子
 * ══════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_appoint_cert_run')) {
    /**
     * 后置通道编排（幂等）：N1 webp + N2 归档 + N3 证书 + 前端记录。
     *
     * @param int   $doc_id 发文 ID。
     * @param int   $actor  操作人。
     * @param array $args   可选：`force` 放宽状态门槛；`skip_cert` 只做归档与 webp。
     * @return array
     */
    function zw_doc_appoint_cert_run($doc_id, $actor = 0, $args = array()) {
        $doc_id = (int) $doc_id;
        $actor  = (int) $actor;
        $force  = !empty($args['force']);
        $result = array('status' => 'skipped', 'message' => '', 'archive' => null, 'media' => null, 'cert' => null);
        if (!function_exists('zw_doc_get_document') || !function_exists('zw_doc_appoint_appoint_meta')) {
            $result['message'] = '公文模块不可用。';

            return $result;
        }
        $doc = zw_doc_get_document($doc_id);
        if (!is_array($doc)) {
            $result['status']  = 'failed';
            $result['message'] = '发文不存在。';

            return $result;
        }
        $appoint  = zw_doc_appoint_appoint_meta($doc_id);
        $meta     = (array) $appoint['meta'];
        $problems = zw_doc_appoint_cert_eligible($doc_id, $doc, $meta, $force);
        if ($problems !== '') {
            $result['message'] = $problems;

            return $result;
        }
        $result['archive'] = zw_doc_appoint_cert_archive($doc_id, $doc, $meta, $actor);
        $media             = zw_doc_appoint_cert_media_sync($doc_id, $doc, $meta, $actor);
        $result['media']   = $media;
        if (empty($args['skip_cert'])) {
            $result['cert'] = zw_doc_appoint_cert_issue($doc_id, $doc, $meta, $actor, $args);
        }
        // 前端记录：逐人 upsert（只给本人）。
        if (function_exists('zw_doc_appoint_cert_items')) {
            foreach (zw_doc_appoint_cert_items($meta) as $item) {
                $uid   = (int) $item['uid'];
                $vars  = zw_doc_appoint_cert_vars($doc, $meta, $item);
                $entry = array(
                    'name'        => (string) $vars['姓名'],
                    'branch'      => (string) $vars['所属部门'],
                    'duty'        => (string) $vars['职务'],
                    'term'        => (string) $vars['任期'],
                    'date'        => (string) zw_doc_appoint_cert_issue_date($doc),
                    'date_cn'     => (string) $vars['发证日期'],
                    'doc_title'   => (string) (isset($doc['title']) ? $doc['title'] : ''),
                    'doc_imgs'    => is_array($media) && !empty($media['urls']) ? array_values($media['urls']) : array(),
                    'imgs'        => array(),
                );
                // 任命书图片（LWEMS 267 出图）：颁发后回查一次（失败不阻断）。
                $certNo = isset($result['cert']['issued'][$uid]) ? (string) $result['cert']['issued'][$uid] : '';
                if ($certNo !== '') {
                    $entry['cert_no'] = $certNo;
                    $imgs = zw_doc_appoint_cert_fetch_images($certNo);
                    if (!empty($imgs)) {
                        $entry['imgs'] = $imgs;
                    }
                }
                zw_doc_appoint_cert_record_upsert($uid, $doc_id, $entry);
            }
        }
        $result['status']  = 'done';
        $result['message'] = '后置通道已执行。';

        return $result;
    }
}

if (!function_exists('zw_doc_appoint_cert_resync')) {
    /**
     * 人工「重新同步」的后置通道重试入口（force，幂等）。
     *
     * 为什么需要它（TASK-1295 Ralph 轮 7 实测的结构性缺口）：后置通道原先只挂在
     * `zw_doc_flow_completed` / `zw_doc_sealed` / `zw_doc_pdf_ready` 三个**自动**广播上，
     * 而后台唯一的「重新同步」按钮走的是 TASK-1291 的 `zw_doc_appoint_sync(force)`，
     * **不经过**本模块 ⇒ `_zw_doc_appoint_{archive,media,cert}_error` 里记下的失败
     * **没有任何人工重试入口**（`zw_doc_appoint_cert_run()` 的 `force` 分支成为死代码），
     * 与「失败可重试」的既定口径不符。
     * 现由 `zw_doc_handle_appoint_resync()` 在人员库同步之后调用本函数。
     *
     * 语义：`trigger=off` ⇒ 零副作用跳过（与所有入口一致）；其余交给
     * `zw_doc_appoint_cert_run(force=true)`（幂等键不变，已颁发不会重复推送，见 §五）。
     *
     * @param int $doc_id 发文 ID。
     * @param int $actor  操作人。
     * @return array 与 `zw_doc_appoint_cert_run()` 同形，另带 `retried` 标记。
     */
    function zw_doc_appoint_cert_resync($doc_id, $actor = 0) {
        $doc_id = (int) $doc_id;
        if (zw_doc_appoint_cert_trigger() === 'off') {
            return array(
                'status'  => 'skipped',
                'message' => '任命后置通道已关闭（zw_doc_appoint_sync_trigger=off）。',
                'retried' => false,
                'archive' => null,
                'media'   => null,
                'cert'    => null,
            );
        }
        $result            = zw_doc_appoint_cert_run($doc_id, (int) $actor, array('force' => true));
        $result['retried'] = true;

        return $result;
    }
}

if (!function_exists('zw_doc_appoint_cert_media_sync')) {
    /**
     * N1：确保带印章正式版的 webp 缓存已预热（PDF 未就绪则留待重试）。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $doc    发文详情。
     * @param array $meta   任命要素。
     * @param int   $actor  操作人。
     * @return array{ok:bool,urls:string[],message:string}
     */
    function zw_doc_appoint_cert_media_sync($doc_id, $doc, $meta, $actor = 0) {
        $media = array('ok' => false, 'urls' => array(), 'message' => '');
        $pdf   = zw_doc_appoint_cert_pdf_path($doc_id);
        if ($pdf === '') {
            $media['message'] = '带印章正式版 PDF 尚未生成完成。';

            return $media;
        }
        $media = zw_doc_appoint_cert_media_build($doc_id, $pdf);
        if (!empty($media['ok'])) {
            update_post_meta($doc_id, '_zw_doc_appoint_doc_media', wp_json_encode(array_values($media['urls']), JSON_UNESCAPED_UNICODE));
            delete_post_meta($doc_id, '_zw_doc_appoint_media_error');
        } else {
            update_post_meta($doc_id, '_zw_doc_appoint_media_error', sanitize_text_field((string) $media['message']));
        }

        return $media;
    }
}

if (!function_exists('zw_doc_appoint_cert_fetch_images')) {
    /**
     * 回查 LWEMS 单证书出图（复用既有客户端探针；失败返回空数组，不阻断）。
     *
     * @param string $certNo 证书编号。
     * @return string[]
     */
    function zw_doc_appoint_cert_fetch_images($certNo) {
        $certNo = trim((string) $certNo);
        if ($certNo === '') {
            return array();
        }
        // OA-2443（契约轮）：实名客户端改走主题契约，不再直取主题内部类路径。
        if (!class_exists('ZW_LwemsClient') || !method_exists('ZW_LwemsClient', 'probe_one_cert_data')) {
            return array();
        }
        $probe = ZW_LwemsClient::probe_one_cert_data(zw_doc_appoint_cert_template_id(), $certNo);
        if (empty($probe['ok']) || empty($probe['found']) || empty($probe['data']) || !is_array($probe['data'])) {
            return array();
        }
        $data = $probe['data'];
        // 唯一实现：客户端公开薄封装（禁止在调用方复制「图片地址N」解析）。
        if (method_exists('ZW_LwemsClient', 'cert_images_from_data')) {
            return array_values(array_filter((array) ZW_LwemsClient::cert_images_from_data($data), 'strlen'));
        }
        $imgs = array();
        for ($i = 1; $i <= 10; $i++) {
            $key = '图片地址' . $i;
            if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                $imgs[] = trim((string) $data[$key]);
            } else {
                break;
            }
        }
        if (empty($imgs) && isset($data['图片地址']) && trim((string) $data['图片地址']) !== '') {
            $imgs[] = trim((string) $data['图片地址']);
        }

        return array_values(array_filter($imgs, 'strlen'));
    }
}

if (!function_exists('zw_doc_appoint_cert_on_flow_completed')) {
    /**
     * `zw_doc_flow_completed` 监听（触发口径 approved）。
     *
     * @param int    $doc_id  发文 ID。
     * @param string $status  新状态。
     * @param mixed  $context 上下文。
     * @return void
     */
    function zw_doc_appoint_cert_on_flow_completed($doc_id, $status, $context = array()) {
        if (zw_doc_appoint_cert_trigger() !== 'approved' || (string) $status !== 'approved') {
            return;
        }
        $actor = (is_array($context) && isset($context['actor'])) ? (int) $context['actor'] : 0;
        zw_doc_appoint_cert_run((int) $doc_id, $actor);
    }
}

if (!function_exists('zw_doc_appoint_cert_on_sealed')) {
    /**
     * `zw_doc_sealed` 监听（触发口径 sealed）。
     *
     * @param int    $doc_id  发文 ID。
     * @param string $seal_id 印章 ID。
     * @param int    $actor   操作人。
     * @return void
     */
    function zw_doc_appoint_cert_on_sealed($doc_id, $seal_id = '', $actor = 0) {
        if (zw_doc_appoint_cert_trigger() !== 'sealed') {
            return;
        }
        zw_doc_appoint_cert_run((int) $doc_id, (int) $actor);
    }
}

if (!function_exists('zw_doc_appoint_cert_on_pdf_ready')) {
    /**
     * `zw_doc_pdf_ready` 监听（TASK-1295 新增广播）：带印章件一旦生成即归档 + 预热 webp。
     *
     * 与触发口径无关（PDF 只在正式用印后存在）；`off` 时零副作用。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $data   产物信息。
     * @param int   $actor  操作人（CLI 工人为 0）。
     * @return void
     */
    function zw_doc_appoint_cert_on_pdf_ready($doc_id, $data = array(), $actor = 0) {
        if (zw_doc_appoint_cert_trigger() === 'off') {
            return;
        }
        $doc = function_exists('zw_doc_get_document') ? zw_doc_get_document((int) $doc_id) : null;
        if (!is_array($doc) || !function_exists('zw_doc_appoint_appoint_meta')) {
            return;
        }
        $appoint = zw_doc_appoint_appoint_meta((int) $doc_id);
        $meta    = (array) $appoint['meta'];
        if (empty($meta['items']) || !zw_doc_appoint_is_appoint_type(zw_doc_appoint_doc_type((int) $doc_id, $doc))) {
            return;
        }
        zw_doc_appoint_cert_archive((int) $doc_id, $doc, $meta, (int) $actor);
        $media = zw_doc_appoint_cert_media_sync((int) $doc_id, $doc, $meta, (int) $actor);
        // 前端记录补 doc_imgs（证书可能已在审核通过时颁发）。
        if (!empty($media['ok'])) {
            foreach (zw_doc_appoint_cert_items($meta) as $item) {
                zw_doc_appoint_cert_record_upsert((int) $item['uid'], (int) $doc_id, array('doc_imgs' => array_values($media['urls'])));
            }
        }
    }
}

/* ── 钩子挂载（广播钩子 + 本模块自行挂载，与 TASK-1291 同型） ── */
if (function_exists('add_action')) {
    add_action('zw_doc_flow_completed', 'zw_doc_appoint_cert_on_flow_completed', 20, 3);
    add_action('zw_doc_sealed', 'zw_doc_appoint_cert_on_sealed', 20, 3);
    add_action('zw_doc_pdf_ready', 'zw_doc_appoint_cert_on_pdf_ready', 10, 3);
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 六、归类 / 索引一致性（TASK-1295 用户口径 8）
 * ══════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_appoint_cert_template_map_file')) {
    /**
     * 索引构建输入 `template-map.json` 路径（可过滤；与 SPEC-89 缓存目录过滤器同范式）。
     *
     * @return string
     */
    function zw_doc_appoint_cert_template_map_file() {
        if (function_exists('apply_filters')) {
            $file = (string) apply_filters('zw_cert_template_map_file', '/opt/zw-cert/template-map.json');
            if ($file !== '') {
                return $file;
            }
        }

        return '/opt/zw-cert/template-map.json';
    }
}

if (!function_exists('zw_doc_appoint_cert_talent_templates_file')) {
    /**
     * 人才索引输入 `talent-templates.json` 路径（可过滤）。
     *
     * @return string
     */
    function zw_doc_appoint_cert_talent_templates_file() {
        if (function_exists('apply_filters')) {
            $file = (string) apply_filters('zw_cert_talent_templates_file', '/opt/zw-cert/talent-templates.json');
            if ($file !== '') {
                return $file;
            }
        }

        return '/opt/zw-cert/talent-templates.json';
    }
}

if (!function_exists('zw_doc_appoint_cert_align_template_map')) {
    /**
     * 8①：确保 option `zw_template_map[267].cat === 'member'`，并同步落盘
     * `/opt/zw-cert/template-map.json`（索引构建输入）。
     *
     * 幂等：已是 member 时不写库、不落盘。返回是否发生变更。
     *
     * @return bool
     */
    function zw_doc_appoint_cert_align_template_map() {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return false;
        }
        $tid = zw_doc_appoint_cert_template_id();
        $map = (array) get_option('zw_template_map', array());
        if (!isset($map[$tid]) || !is_array($map[$tid])) {
            return false; // 模板映射由后台「认证模板映射」生成；缺条目时不臆造。
        }
        if (isset($map[$tid]['cat']) && (string) $map[$tid]['cat'] === 'member') {
            return false;
        }
        $map[$tid]['cat'] = 'member';
        update_option('zw_template_map', $map);
        if (function_exists('wp_json_encode')) {
            @file_put_contents(zw_doc_appoint_cert_template_map_file(), wp_json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return true;
    }
}

if (!function_exists('zw_doc_appoint_cert_consistency')) {
    /**
     * 8⑤：三方一致性断言（只读）：`template-map.json` ⇄ option `zw_template_map` ⇄ 索引输入
     * （`talent-templates.json` 不得含 267）。
     *
     * @return array{ok:bool,checks:array<string,bool>,details:array<string,string>}
     */
    function zw_doc_appoint_cert_consistency() {
        $tid   = (string) zw_doc_appoint_cert_template_id();
        $out   = array('ok' => true, 'checks' => array(), 'details' => array());
        $map   = (array) get_option('zw_template_map', array());
        $optCat = isset($map[$tid]['cat']) ? (string) $map[$tid]['cat'] : '';
        $out['checks']['option_cat_member'] = ($optCat === 'member');
        $out['details']['option_cat_member'] = 'option zw_template_map[' . $tid . '].cat = ' . ($optCat !== '' ? $optCat : '（缺）');

        $file = zw_doc_appoint_cert_template_map_file();
        $disk = is_readable($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $diskCat = (is_array($disk) && isset($disk[$tid]['cat'])) ? (string) $disk[$tid]['cat'] : '';
        $out['checks']['file_cat_member'] = ($diskCat === 'member');
        $out['details']['file_cat_member'] = 'template-map.json[' . $tid . '].cat = ' . ($diskCat !== '' ? $diskCat : '（缺/文件不可读）');

        $talentFile = zw_doc_appoint_cert_talent_templates_file();
        $talent = is_readable($talentFile) ? json_decode((string) @file_get_contents($talentFile), true) : array();
        $inTalent = is_array($talent) && isset($talent[$tid]);
        $out['checks']['not_in_talent_input'] = !$inTalent;
        $out['details']['not_in_talent_input'] = 'talent-templates.json 含 ' . $tid . ' = ' . ($inTalent ? '是（陈旧，需运维重建）' : '否');

        foreach ($out['checks'] as $ok) {
            if (!$ok) {
                $out['ok'] = false;
            }
        }

        return $out;
    }
}
