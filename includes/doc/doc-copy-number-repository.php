<?php
/**
 * 份号台账与原子占号仓库（TASK-1867 / CHG-362）。
 *
 * 与「发文字号」台账（`doc-number-repository.php`）同构，但口径不同：
 * - 份号 = **一件公文一个号**。GB/T 9704-2012 §7.2.1 的「按印制份数逐份编号」需要先有
 *   「印制份数 + 多份打印/导出」通道，本任务不做（留二期）。
 * - **不按年重置**：份号是印制顺序号，跨年连续；6 位（`header.copy_no_digits`），从 000001 起。
 * - **占用判定 = 台账占用 ∪ 存量公文 `body.meta.copy_no`**：存量公文**不回填台账**（不写占号行），
 *   但取号与唯一性校验会**读**存量 ⇒ 不会发放与历史重号的份号；后台另给「回填存量」按钮（可选、幂等）。
 * - **并发兜底 = `UNIQUE KEY copy_no`**：占号一律「先写后判受影响行数」，不假设「先查后插」安全。
 *
 * 状态口径（与文号台账同名，便于同一套后台认知）：
 *   reserved  预留（**占用**，留给指定用途）
 *   used      已绑定发文（**占用**）
 *   skipped   主动跳过（**占用**：该号永不再用，保留审计）
 *   void      作废（**不占用**：号回到可用池；重新占用时把该行「复活」为 used，避开唯一索引）
 *
 * 唯一出处声明：份号的归一（去非数字 / 左补 0 / 超长取后 N 位）只允许本文件
 * `zw_doc_copy_number_normalize()` 一处；`zw_doc_composer_copy_no()` 只是它的薄包装
 * （保留旧函数名给既有测试与调用方）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_copy_number_table')) {
    /**
     * 份号台账表名。
     *
     * @return string
     */
    function zw_doc_copy_number_table() {
        return zw_doc_table('copy_numbers');
    }
}

if (!function_exists('zw_doc_copy_number_digits')) {
    /**
     * 份号位数（唯一出处：`header.copy_no_digits`）。
     *
     * @return int
     */
    function zw_doc_copy_number_digits() {
        $width = (int) zw_doc_gb9704_get('header.copy_no_digits', 6);
        if ($width < 1 || $width > 12) {
            $width = 6;
        }

        return $width;
    }
}

if (!function_exists('zw_doc_copy_number_max')) {
    /**
     * 份号上限（6 位 ⇒ 999999）。
     *
     * @return int
     */
    function zw_doc_copy_number_max() {
        return (int) str_repeat('9', zw_doc_copy_number_digits());
    }
}

if (!function_exists('zw_doc_copy_number_normalize')) {
    /**
     * 份号归一（GB/T 9704-2012 §7.2.1）。
     *
     * - 只保留阿拉伯数字（`"1a2"` → `12`）；
     * - 不足 N 位左补 `0`（`"12"` → `000012`）；
     * - 超过 N 位取**后 N 位**（`"1234567"` → `234567`）；
     * - 空值 / 全非数字 → 空串（允许不标注份号）。
     *
     * @param string $value 用户输入。
     * @return string
     */
    function zw_doc_copy_number_normalize($value) {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (!is_string($digits)) {
            $digits = '';
        }
        if ($digits === '') {
            return '';
        }
        $width = zw_doc_copy_number_digits();
        if (strlen($digits) > $width) {
            $digits = substr($digits, -$width);
        }

        return str_pad($digits, $width, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('zw_doc_copy_number_format')) {
    /**
     * 序号转份号字符串（1 → 000001）。
     *
     * @param int $seq 序号。
     * @return string
     */
    function zw_doc_copy_number_format($seq) {
        return str_pad((string) max(0, (int) $seq), zw_doc_copy_number_digits(), '0', STR_PAD_LEFT);
    }
}

if (!function_exists('zw_doc_copy_number_status_label')) {
    /**
     * 台账状态中文名（与文号台账同名同义）。
     *
     * @param string $status 状态。
     * @return string
     */
    function zw_doc_copy_number_status_label($status) {
        $map = array(
            'reserved' => '预留',
            'used'     => '已使用',
            'skipped'  => '已跳过',
            'void'     => '已作废',
        );

        return isset($map[$status]) ? $map[$status] : (string) $status;
    }
}

if (!function_exists('zw_doc_copy_number_row_normalize')) {
    /**
     * 台账行转数组。
     *
     * @param object|array $row 数据行。
     * @return array
     */
    function zw_doc_copy_number_row_normalize($row) {
        $row = (array) $row;

        return array(
            'id'            => isset($row['id']) ? (int) $row['id'] : 0,
            'copy_no'       => isset($row['copy_no']) ? (string) $row['copy_no'] : '',
            'status'        => isset($row['status']) ? (string) $row['status'] : '',
            'document_id'   => isset($row['document_id']) ? (int) $row['document_id'] : 0,
            'reserved_note' => isset($row['reserved_note']) ? (string) $row['reserved_note'] : '',
            'reserved_by'   => isset($row['reserved_by']) ? (int) $row['reserved_by'] : 0,
            'reserved_at'   => isset($row['reserved_at']) ? (string) $row['reserved_at'] : '',
            'operator_id'   => isset($row['operator_id']) ? (int) $row['operator_id'] : 0,
            'reason'        => isset($row['reason']) ? (string) $row['reason'] : '',
            'created_at'    => isset($row['created_at']) ? (string) $row['created_at'] : '',
            'updated_at'    => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
        );
    }
}

if (!function_exists('zw_doc_copy_number_get')) {
    /**
     * 按份号取台账行。
     *
     * @param string $copy_no 份号（未归一也可）。
     * @return array|null
     */
    function zw_doc_copy_number_get($copy_no) {
        global $wpdb;
        $copy_no = zw_doc_copy_number_normalize($copy_no);
        if ($copy_no === '') {
            return null;
        }
        $table = zw_doc_copy_number_table();
        $row   = $wpdb->get_row(
            zw_doc_prepare_sql("SELECT * FROM {$table} WHERE copy_no=%s", array($copy_no)),
            ARRAY_A
        );

        return $row ? zw_doc_copy_number_row_normalize($row) : null;
    }
}

if (!function_exists('zw_doc_copy_number_get_by_id')) {
    /**
     * 按 ID 取台账行。
     *
     * @param int $id 台账 ID。
     * @return array|null
     */
    function zw_doc_copy_number_get_by_id($id) {
        global $wpdb;
        $table = zw_doc_copy_number_table();
        $row   = $wpdb->get_row(
            zw_doc_prepare_sql("SELECT * FROM {$table} WHERE id=%d", array((int) $id)),
            ARRAY_A
        );

        return $row ? zw_doc_copy_number_row_normalize($row) : null;
    }
}

if (!function_exists('zw_doc_copy_number_current_for_doc')) {
    /**
     * 某发文当前绑定的份号行（仅 used / reserved，忽略已作废）。
     *
     * @param int $doc_id 发文 ID。
     * @return array|null
     */
    function zw_doc_copy_number_current_for_doc($doc_id) {
        global $wpdb;
        $doc_id = (int) $doc_id;
        if ($doc_id <= 0) {
            return null;
        }
        $table = zw_doc_copy_number_table();
        $row   = $wpdb->get_row(
            zw_doc_prepare_sql(
                "SELECT * FROM {$table} WHERE document_id=%d AND status IN ('used','reserved') ORDER BY id DESC LIMIT 1",
                array($doc_id)
            ),
            ARRAY_A
        );

        return $row ? zw_doc_copy_number_row_normalize($row) : null;
    }
}

if (!function_exists('zw_doc_copy_number_occupied')) {
    /**
     * 「当前不可用份号」集合（台账占用 ∪ 存量公文份号）。
     *
     * 说明：存量公文只**读**不写（不回填），与「不改变既有数据」的边界一致；
     * 结果进程内缓存，占号/登记后调用 `zw_doc_copy_number_occupied(true)` 刷新。
     *
     * @param bool $refresh 强制重算。
     * @return array<string,bool> 份号 => true。
     */
    function zw_doc_copy_number_occupied($refresh = false) {
        static $map = null;
        global $wpdb;

        if ($refresh) {
            $map = null;
        }
        if (is_array($map)) {
            return $map;
        }

        $map   = array();
        $table = zw_doc_copy_number_table();

        // ① 台账占用（reserved / used / skipped；void 不占用）。
        $ledger = $wpdb->get_col(
            "SELECT copy_no FROM {$table} WHERE status IN ('reserved','used','skipped')"
        );
        foreach ((array) $ledger as $copy_no) {
            $map[(string) $copy_no] = true;
        }

        // ② 存量公文（`_zw_doc_body_json` 的 `meta.copy_no`）——历史数据未回填台账也照样防重号。
        $rows = $wpdb->get_results(
            zw_doc_prepare_sql(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value LIKE %s",
                array('_zw_doc_body_json', '%"copy_no":"%')
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $body = json_decode((string) $row['meta_value'], true);
            $no   = (is_array($body) && isset($body['meta']['copy_no']))
                ? zw_doc_copy_number_normalize((string) $body['meta']['copy_no'])
                : '';
            if ($no !== '') {
                $map[$no] = true;
            }
        }

        return $map;
    }
}

if (!function_exists('zw_doc_copy_number_is_free')) {
    /**
     * 份号是否可用（`$except_doc_id` 用于「自己保留自己」，编辑态不改号时不做无谓拦截）。
     *
     * @param string $copy_no       份号。
     * @param int    $except_doc_id 视为可用的发文 ID（0 = 不豁免）。
     * @return bool
     */
    function zw_doc_copy_number_is_free($copy_no, $except_doc_id = 0) {
        $copy_no = zw_doc_copy_number_normalize($copy_no);
        if ($copy_no === '') {
            return false;
        }
        $except_doc_id = (int) $except_doc_id;
        if ($except_doc_id > 0) {
            $own = zw_doc_copy_number_current_for_doc($except_doc_id);
            if ($own && $own['copy_no'] === $copy_no) {
                return true;
            }
        }

        $map = zw_doc_copy_number_occupied();

        return !isset($map[$copy_no]);
    }
}

if (!function_exists('zw_doc_copy_number_next_free')) {
    /**
     * 第一个可用份号（从 000001 起；号段用尽返回空串，由调用方 fail-closed）。
     *
     * @return string
     */
    function zw_doc_copy_number_next_free() {
        $map = zw_doc_copy_number_occupied();
        $max = zw_doc_copy_number_max();
        for ($seq = 1; $seq <= $max; $seq++) {
            $copy_no = zw_doc_copy_number_format($seq);
            if (!isset($map[$copy_no])) {
                return $copy_no;
            }
        }

        return '';
    }
}

if (!function_exists('zw_doc_copy_number_free_choices')) {
    /**
     * 空闲份号候选（供「选择编号」下拉，按升序取前 N 个）。
     *
     * @param int $limit 数量上限。
     * @return string[]
     */
    function zw_doc_copy_number_free_choices($limit = 20) {
        $limit = max(1, min(200, (int) $limit));
        $map   = zw_doc_copy_number_occupied();
        $max   = zw_doc_copy_number_max();
        $out   = array();
        for ($seq = 1; $seq <= $max && count($out) < $limit; $seq++) {
            $copy_no = zw_doc_copy_number_format($seq);
            if (!isset($map[$copy_no])) {
                $out[] = $copy_no;
            }
        }

        return $out;
    }
}

if (!function_exists('zw_doc_copy_number_claim')) {
    /**
     * 原子占号（used / reserved / skipped）。
     *
     * 已存在同号行时：`void` 行或**同文档**行 ⇒ 复活/幂等更新；其余状态 ⇒ `WP_Error`（已被占用）。
     *
     * @param string $copy_no 份号。
     * @param int    $doc_id  绑定发文 ID（0 = 不绑定，预留/跳过用）。
     * @param string $status  used / reserved / skipped。
     * @param string $note    用途备注（预留/跳过原因）。
     * @return int|WP_Error 台账 ID。
     */
    function zw_doc_copy_number_claim($copy_no, $doc_id = 0, $status = 'used', $note = '') {
        global $wpdb;

        $copy_no = zw_doc_copy_number_normalize($copy_no);
        $doc_id  = (int) $doc_id;
        $status  = in_array($status, array('used', 'reserved', 'skipped'), true) ? $status : 'used';
        $note    = (string) $note;
        if ($copy_no === '') {
            return new WP_Error('zw_doc_copy_no_invalid', '份号不能为空。');
        }

        $table = zw_doc_copy_number_table();
        $now   = current_time('mysql');
        $uid   = (int) get_current_user_id();
        $row   = zw_doc_copy_number_get($copy_no);
        $revive = array(
            'status'        => $status,
            'document_id'   => $status === 'used' ? $doc_id : 0,
            'reserved_note' => $note,
            'reserved_by'   => $status === 'reserved' ? $uid : 0,
            'reserved_at'   => $status === 'reserved' ? $now : null,
            'operator_id'   => $uid,
            'updated_at'    => $now,
        );

        if ($row) {
            $same_doc = ($doc_id > 0 && $row['document_id'] === $doc_id);
            if ($row['status'] !== 'void' && !$same_doc) {
                return new WP_Error(
                    'zw_doc_copy_number_taken',
                    sprintf('份号 %s 已被占用（%s）。', $copy_no, zw_doc_copy_number_status_label($row['status']))
                );
            }
            $updated = $wpdb->update(
                $table,
                $revive,
                array('id' => (int) $row['id']),
                array('%s', '%d', '%s', '%d', '%s', '%d', '%s'),
                array('%d')
            );
            if ($updated === false) {
                return new WP_Error('zw_doc_copy_number_write_failed', '份号台账写入失败，请重试。');
            }
            zw_doc_copy_number_occupied(true);

            return (int) $row['id'];
        }

        // 用 `$wpdb->insert()`：它经 `process_fields()` 把 `null` 落成 SQL NULL
        // （`zw_doc_prepare_sql` 会把 null 变成空串，写入 datetime 列在严格模式下会报错）。
        $inserted = $wpdb->insert(
            $table,
            array(
                'copy_no'       => $copy_no,
                'status'        => $status,
                'document_id'   => $status === 'used' ? $doc_id : 0,
                'reserved_note' => $note,
                'reserved_by'   => $status === 'reserved' ? $uid : 0,
                'reserved_at'   => $status === 'reserved' ? $now : null,
                'operator_id'   => $uid,
                'reason'        => '',
                'created_at'    => $now,
                'updated_at'    => $now,
            ),
            array('%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s')
        );
        if (!$inserted) {
            // 并发窗口：上一刻被别的会话写入（唯一索引兜底）。按「已占用」返回，调用方可换号重试。
            return new WP_Error('zw_doc_copy_number_taken', sprintf('份号 %s 已被占用，请换一个。', $copy_no));
        }

        zw_doc_copy_number_occupied(true);

        return (int) $wpdb->insert_id;
    }
}

if (!function_exists('zw_doc_copy_number_release_doc')) {
    /**
     * 释放某发文占用的份号（作废行，号回到可用池）。
     *
     * @param int    $doc_id 发文 ID。
     * @param string $reason 原因。
     * @return void
     */
    function zw_doc_copy_number_release_doc($doc_id, $reason = '') {
        global $wpdb;
        $doc_id = (int) $doc_id;
        if ($doc_id <= 0) {
            return;
        }
        $table = zw_doc_copy_number_table();
        $wpdb->query(
            zw_doc_prepare_sql(
                "UPDATE {$table} SET status='void', document_id=0, reason=%s, updated_at=%s
                 WHERE document_id=%d AND status IN ('used','reserved')",
                array((string) $reason, current_time('mysql'), $doc_id)
            )
        );
        zw_doc_copy_number_occupied(true);
    }
}

if (!function_exists('zw_doc_copy_number_bind')) {
    /**
     * 把份号绑定到发文（**先占新号、再释放旧号**，中途失败不留双号）。
     *
     * @param int    $doc_id  发文 ID。
     * @param string $copy_no 份号（空串 = 解绑/不标注）。
     * @return true|WP_Error
     */
    function zw_doc_copy_number_bind($doc_id, $copy_no) {
        $doc_id  = (int) $doc_id;
        $copy_no = zw_doc_copy_number_normalize($copy_no);
        if ($doc_id <= 0) {
            return new WP_Error('zw_doc_copy_number_bad_doc', '发文 ID 无效。');
        }

        $current = zw_doc_copy_number_current_for_doc($doc_id);
        if ($copy_no === '') {
            zw_doc_copy_number_release_doc($doc_id, '改为不标注份号');
            return true;
        }
        if ($current && $current['copy_no'] === $copy_no) {
            if ($current['status'] !== 'used') {
                global $wpdb;
                $wpdb->update(
                    zw_doc_copy_number_table(),
                    array('status' => 'used', 'document_id' => $doc_id, 'updated_at' => current_time('mysql')),
                    array('id' => (int) $current['id']),
                    array('%s', '%d', '%s'),
                    array('%d')
                );
                zw_doc_copy_number_occupied(true);
            }
            return true;
        }

        $claim = zw_doc_copy_number_claim($copy_no, $doc_id, 'used');
        if (is_wp_error($claim)) {
            return $claim;
        }
        if ($current && (int) $current['id'] !== (int) $claim) {
            global $wpdb;
            $wpdb->update(
                zw_doc_copy_number_table(),
                array('status' => 'void', 'document_id' => 0, 'reason' => '换号释放', 'updated_at' => current_time('mysql')),
                array('id' => (int) $current['id']),
                array('%s', '%d', '%s', '%s'),
                array('%d')
            );
        }
        zw_doc_copy_number_occupied(true);

        return true;
    }
}

if (!function_exists('zw_doc_copy_number_reserve')) {
    /**
     * 预留份号（占用，不下发给任何发文）。
     *
     * @param string $copy_no 份号。
     * @param string $note    用途备注。
     * @return int|WP_Error
     */
    function zw_doc_copy_number_reserve($copy_no, $note = '') {
        return zw_doc_copy_number_claim($copy_no, 0, 'reserved', $note);
    }
}

if (!function_exists('zw_doc_copy_number_skip')) {
    /**
     * 跳过份号（占用但永不再用，保留审计）。
     *
     * @param string $copy_no 份号。
     * @param string $reason  原因。
     * @return int|WP_Error
     */
    function zw_doc_copy_number_skip($copy_no, $reason = '') {
        return zw_doc_copy_number_claim($copy_no, 0, 'skipped', $reason);
    }
}

if (!function_exists('zw_doc_copy_number_void')) {
    /**
     * 作废预留 / 已跳过（号回到可用池；已绑定发文的份号不得从台账直接作废）。
     *
     * @param int    $id     台账 ID。
     * @param string $reason 原因。
     * @return bool
     */
    function zw_doc_copy_number_void($id, $reason = '') {
        global $wpdb;
        $row = zw_doc_copy_number_get_by_id($id);
        if (!$row || !in_array($row['status'], array('reserved', 'skipped'), true)) {
            return false;
        }
        $updated = $wpdb->update(
            zw_doc_copy_number_table(),
            array('status' => 'void', 'document_id' => 0, 'reason' => (string) $reason, 'updated_at' => current_time('mysql')),
            array('id' => (int) $id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
        zw_doc_copy_number_occupied(true);

        return $updated !== false;
    }
}

if (!function_exists('zw_doc_copy_number_parse_spec')) {
    /**
     * 解析份号录入（支持单个、连续范围、多段组合）。
     *
     * 例：`12` → 000012；`000001-000010` → 10 个；`1-3,5` → 000001..000003 + 000005。
     *
     * @param string $spec 输入。
     * @return string[]|WP_Error 归一后的份号数组。
     */
    function zw_doc_copy_number_parse_spec($spec) {
        $spec = trim((string) $spec);
        if ($spec === '') {
            return new WP_Error('zw_doc_copy_number_spec_empty', '请填写份号或份号范围。');
        }
        $width = zw_doc_copy_number_digits();
        $max   = zw_doc_copy_number_max();
        $out   = array();
        foreach (preg_split('/[,，、\s]+/u', $spec) as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if (strpos($part, '-') !== false || strpos($part, '~') !== false) {
                $pieces = preg_split('/[-~]/', $part);
                if (count($pieces) !== 2) {
                    return new WP_Error('zw_doc_copy_number_spec_bad', '范围格式不正确：' . $part);
                }
                $lo_raw = preg_replace('/\D+/', '', (string) $pieces[0]);
                $hi_raw = preg_replace('/\D+/', '', (string) $pieces[1]);
                if ($lo_raw === '' || $hi_raw === '') {
                    return new WP_Error('zw_doc_copy_number_spec_bad', '范围两端必须是数字：' . $part);
                }
                $lo = (int) $lo_raw;
                $hi = (int) $hi_raw;
                if ($lo > $hi) {
                    return new WP_Error('zw_doc_copy_number_spec_bad', '范围起点大于终点：' . $part);
                }
                if ($hi > $max) {
                    return new WP_Error(
                        'zw_doc_copy_number_spec_bad',
                        sprintf('份号超出 %d 位可编范围（上限 %s）。', $width, zw_doc_copy_number_format($max))
                    );
                }
                if (($hi - $lo + 1) > 200) {
                    return new WP_Error('zw_doc_copy_number_spec_bad', '单次预留/跳过不得超过 200 个份号。');
                }
                for ($seq = $lo; $seq <= $hi; $seq++) {
                    $out[] = zw_doc_copy_number_format($seq);
                }
                continue;
            }
            $one = zw_doc_copy_number_normalize($part);
            if ($one === '') {
                return new WP_Error('zw_doc_copy_number_spec_bad', '无法识别的份号：' . $part);
            }
            $out[] = $one;
        }
        $out = array_values(array_unique($out));
        if (empty($out)) {
            return new WP_Error('zw_doc_copy_number_spec_empty', '请填写份号或份号范围。');
        }

        return $out;
    }
}

if (!function_exists('zw_doc_copy_number_list')) {
    /**
     * 份号台账分页列表。
     *
     * @param array $args 过滤参数：status / exclude_status / page / per_page。
     * @return array{items:array,total:int,page:int,pages:int}
     */
    function zw_doc_copy_number_list($args = array()) {
        global $wpdb;
        $args = wp_parse_args(
            $args,
            array('status' => '', 'exclude_status' => array(), 'page' => 1, 'per_page' => 20)
        );
        $table = zw_doc_copy_number_table();
        $where = array('1=1');
        $params = array();

        if ((string) $args['status'] !== '') {
            $where[]  = 'status=%s';
            $params[] = (string) $args['status'];
        }
        $exclude = array_values(array_filter((array) $args['exclude_status'], 'strlen'));
        if (!empty($exclude)) {
            $where[]  = 'status NOT IN (' . implode(',', array_fill(0, count($exclude), '%s')) . ')';
            $params   = array_merge($params, $exclude);
        }

        $per_page = max(1, min(100, (int) $args['per_page']));
        $page     = max(1, (int) $args['page']);
        $offset   = ($page - 1) * $per_page;
        $where_sql = implode(' AND ', $where);

        $total = (int) $wpdb->get_var(
            zw_doc_prepare_sql("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params)
        );
        $rows = $wpdb->get_results(
            zw_doc_prepare_sql(
                "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
                array_merge($params, array($per_page, $offset))
            ),
            ARRAY_A
        );
        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = zw_doc_copy_number_row_normalize($row);
        }

        return array(
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'pages' => $total > 0 ? (int) ceil($total / $per_page) : 0,
        );
    }
}

if (!function_exists('zw_doc_copy_number_backfill')) {
    /**
     * 从存量公文回填台账（可选、幂等、只补不覆盖）。
     *
     * 存量公文里同号被多篇占用时**不覆盖**任何一方：首个登记的绑定为 used，
     * 其余记入 `conflicts` 由管理员人工处置（宁可报冲突，不静默丢数据）。
     *
     * @return array{inserted:int,conflicts:string[],scanned:int}
     */
    function zw_doc_copy_number_backfill() {
        global $wpdb;
        $table = zw_doc_copy_number_table();
        $rows  = $wpdb->get_results(
            zw_doc_prepare_sql(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value LIKE %s ORDER BY post_id ASC",
                array('_zw_doc_body_json', '%"copy_no":"%')
            ),
            ARRAY_A
        );

        $inserted  = 0;
        $conflicts = array();
        $scanned   = 0;
        $now       = current_time('mysql');
        foreach ((array) $rows as $row) {
            $doc_id = (int) $row['post_id'];
            $body   = json_decode((string) $row['meta_value'], true);
            $copy_no = (is_array($body) && isset($body['meta']['copy_no']))
                ? zw_doc_copy_number_normalize((string) $body['meta']['copy_no'])
                : '';
            if ($copy_no === '' || $doc_id <= 0) {
                continue;
            }
            $scanned++;
            $existing = zw_doc_copy_number_get($copy_no);
            if ($existing) {
                if ($existing['document_id'] !== $doc_id) {
                    $conflicts[] = sprintf(
                        '%s：文档 #%d 与 #%d 重号（保留先登记者）',
                        $copy_no,
                        $existing['document_id'] > 0 ? $existing['document_id'] : 0,
                        $doc_id
                    );
                }
                continue;
            }
            $ok = $wpdb->query(
                zw_doc_prepare_sql(
                    "INSERT IGNORE INTO {$table}
                     (copy_no,status,document_id,reserved_note,reserved_by,reserved_at,operator_id,reason,created_at,updated_at)
                     VALUES (%s,'used',%d,'',0,NULL,%d,%s,%s,%s)",
                    array($copy_no, $doc_id, (int) get_current_user_id(), '存量回填', $now, $now)
                )
            );
            if ($ok) {
                $inserted++;
            }
        }
        zw_doc_copy_number_occupied(true);

        return array('inserted' => $inserted, 'conflicts' => $conflicts, 'scanned' => $scanned);
    }
}
