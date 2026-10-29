<?php
/**
 * 文号台账与原子占号仓库（TASK-433）。
 *
 * 并发原则：
 * - 计数器表按 年份 + 文种 行锁/原子递增；
 * - 台账表以完整文号与 (年份,文种,序号) 唯一索引做最终兜底；
 * - 禁止使用 get_option/update_option 做序号自增。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_prepare_sql')) {
    /**
     * 兼容不同 WP 版本的 prepare 封装。
     *
     * @param string $sql    SQL。
     * @param array  $params 参数。
     * @return string
     */
    function zw_doc_prepare_sql($sql, $params = array()) {
        global $wpdb;
        if (empty($params)) {
            return $sql;
        }
        return call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), array_values($params)));
    }
}

if (!function_exists('zw_doc_number_row_normalize')) {
    /**
     * 台账行转数组。
     *
     * @param object|array $row 数据行。
     * @return array
     */
    function zw_doc_number_row_normalize($row) {
        $row = (array) $row;
        return array(
            'id'            => isset($row['id']) ? (int) $row['id'] : 0,
            'doc_year'      => isset($row['doc_year']) ? (int) $row['doc_year'] : 0,
            'doc_type'      => isset($row['doc_type']) ? (string) $row['doc_type'] : '',
            'seq'           => isset($row['seq']) ? (int) $row['seq'] : 0,
            'full_number'   => isset($row['full_number']) ? (string) $row['full_number'] : '',
            'status'        => isset($row['status']) ? (string) $row['status'] : '',
            'document_id'   => isset($row['document_id']) ? (int) $row['document_id'] : 0,
            'claim_token'   => isset($row['claim_token']) ? (string) $row['claim_token'] : '',
            'reserved_by'   => isset($row['reserved_by']) ? (int) $row['reserved_by'] : 0,
            'reserved_note' => isset($row['reserved_note']) ? (string) $row['reserved_note'] : '',
            'reserved_at'   => isset($row['reserved_at']) ? (string) $row['reserved_at'] : '',
            'operator_id'   => isset($row['operator_id']) ? (int) $row['operator_id'] : 0,
            'reason'        => isset($row['reason']) ? (string) $row['reason'] : '',
            'created_at'    => isset($row['created_at']) ? (string) $row['created_at'] : '',
            'updated_at'    => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
        );
    }
}

if (!function_exists('zw_doc_get_number_by_id')) {
    /**
     * 按 ID 获取台账行。
     *
     * @param int $id 台账 ID。
     * @return array|null
     */
    function zw_doc_get_number_by_id($id) {
        global $wpdb;
        $table = zw_doc_table('numbers');
        $row   = $wpdb->get_row(zw_doc_prepare_sql("SELECT * FROM {$table} WHERE id=%d", array((int) $id)), ARRAY_A);
        return $row ? zw_doc_number_row_normalize($row) : null;
    }
}

if (!function_exists('zw_doc_increment_counter')) {
    /**
     * 原子递增计数器并返回最新值。必须在事务内调用。
     *
     * @param int    $year 年份。
     * @param string $type 文种。
     * @param int    $min  最小起始值。
     * @return int|false
     */
    function zw_doc_increment_counter($year, $type, $min = 1) {
        global $wpdb;
        $table = zw_doc_table('number_counters');
        $now   = current_time('mysql');

        $ok = $wpdb->query(zw_doc_prepare_sql(
            "INSERT INTO {$table} (doc_year, doc_type, last_seq, updated_at)
             VALUES (%d, %s, %d, %s)
             ON DUPLICATE KEY UPDATE last_seq = last_seq + 1, updated_at = VALUES(updated_at)",
            array($year, $type, $min, $now)
        ));
        if ($ok === false) {
            return false;
        }
        $last = $wpdb->get_var(zw_doc_prepare_sql(
            "SELECT last_seq FROM {$table} WHERE doc_year=%d AND doc_type=%s",
            array($year, $type)
        ));
        return $last === null ? false : (int) $last;
    }
}

if (!function_exists('zw_doc_bump_counter_to')) {
    /**
     * 将计数器推进到至少指定序号。
     *
     * @param int    $year 年份。
     * @param string $type 文种。
     * @param int    $seq  序号。
     * @return bool
     */
    function zw_doc_bump_counter_to($year, $type, $seq) {
        global $wpdb;
        $table = zw_doc_table('number_counters');
        $now   = current_time('mysql');
        $ok    = $wpdb->query(zw_doc_prepare_sql(
            "INSERT INTO {$table} (doc_year, doc_type, last_seq, updated_at)
             VALUES (%d, %s, %d, %s)
             ON DUPLICATE KEY UPDATE last_seq = GREATEST(last_seq, VALUES(last_seq)), updated_at = VALUES(updated_at)",
            array($year, $type, (int) $seq, $now)
        ));
        return $ok !== false;
    }
}

if (!function_exists('zw_doc_find_first_available_seq')) {
    /**
     * 从起始序号开始，返回第一个没有被操作记录占用的序号。
     *
     * 预留、已使用、已跳过、已作废、占用中均视为已占用，不再复用。
     *
     * @param int    $year  年份。
     * @param string $type  文种。
     * @param int    $start 起始序号。
     * @return int|false
     */
    function zw_doc_find_first_available_seq($year, $type, $start = 1) {
        global $wpdb;
        $settings = zw_doc_number_settings();
        $start    = max(1, (int) $start);
        $table    = zw_doc_table('numbers');
        $rows     = $wpdb->get_col(zw_doc_prepare_sql(
            "SELECT seq FROM {$table} WHERE doc_year=%d AND doc_type=%s ORDER BY seq ASC",
            array((int) $year, (string) $type)
        ));
        $occupied = array();
        foreach ((array) $rows as $row) {
            $occupied[(int) $row] = true;
        }

        $seq = $start;
        for ($i = 0; $i < 100000; $i++) {
            if (empty($occupied[$seq])) {
                return $seq;
            }
            $seq++;
        }
        return false;
    }
}

if (!function_exists('zw_doc_get_next_preview')) {
    /**
     * 只读预览下一个可用文号，不占用。
     *
     * 规则：从起始序号开始，取第一个未出现在操作记录中的序号；
     * 预留/已使用/已跳过/已作废/占用中都会被跳过。
     *
     * @param int    $year 年份。
     * @param string $type 文种。
     * @return array|WP_Error
     */
    function zw_doc_get_next_preview($year, $type) {
        $settings = zw_doc_number_settings();
        $year     = zw_doc_validate_year($year);
        $type     = zw_doc_validate_doc_type($type, $settings);
        if (!$year || !$type) {
            return new WP_Error('zw_doc_bad_number_rule', '年份或文种不合法。');
        }

        $seq = zw_doc_find_first_available_seq($year, $type, zw_doc_number_start_seq($year, $type, $settings));
        if ($seq === false) {
            return new WP_Error('zw_doc_no_available_number', '未找到可用文号，请检查操作记录。');
        }

        return array(
            'id'          => 0,
            'doc_year'    => $year,
            'doc_type'    => $type,
            'seq'         => (int) $seq,
            'full_number' => zw_doc_format_number($settings, $type, $year, $seq),
            'status'      => 'preview',
        );
    }
}

if (!function_exists('zw_doc_claim_auto_number')) {
    /**
     * 自动取号并原子占用。
     *
     * 先扫描第一个可用序号，再用唯一索引 INSERT 抢占；
     * 并发冲突时重新扫描下一个可用序号，直到成功或达到重试上限。
     *
     * @param int    $year 年份。
     * @param string $type 文种。
     * @return array|WP_Error
     */
    function zw_doc_claim_auto_number($year, $type) {
        global $wpdb;
        $settings = zw_doc_number_settings();
        $year     = zw_doc_validate_year($year);
        $type     = zw_doc_validate_doc_type($type, $settings);
        if (!$year || !$type) {
            return new WP_Error('zw_doc_bad_number_rule', '年份或文种不合法。');
        }

        $numbers = zw_doc_table('numbers');
        $start   = zw_doc_number_start_seq($year, $type, $settings);
        $token   = wp_generate_uuid4();
        $now     = current_time('mysql');

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $seq = zw_doc_find_first_available_seq($year, $type, $start);
            if ($seq === false) {
                return new WP_Error('zw_doc_no_available_number', '未找到可占用文号。');
            }

            $full = zw_doc_format_number($settings, $type, $year, $seq);
            $ok   = $wpdb->insert(
                $numbers,
                array(
                    'doc_year'    => $year,
                    'doc_type'    => $type,
                    'seq'         => (int) $seq,
                    'full_number' => $full,
                    'status'      => 'claimed',
                    'claim_token' => $token,
                    'operator_id' => get_current_user_id(),
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ),
                array('%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s')
            );

            if ($ok !== false) {
                $id = (int) $wpdb->insert_id;
                zw_doc_bump_counter_to($year, $type, $seq);
                return array(
                    'id'          => $id,
                    'doc_year'    => $year,
                    'doc_type'    => $type,
                    'seq'         => (int) $seq,
                    'full_number' => $full,
                    'claim_token' => $token,
                    'status'      => 'claimed',
                );
            }

            if (stripos((string) $wpdb->last_error, 'duplicate') !== false) {
                continue;
            }

            return new WP_Error('zw_doc_claim_failed', '文号占用失败：' . $wpdb->last_error);
        }

        return new WP_Error('zw_doc_claim_conflict', '当前文号占用冲突较多，请重试。');
    }
}

if (!function_exists('zw_doc_skip_number')) {
    /**
     * 显式跳过当前预览号，写入 skipped 台账并推进计数器。
     *
     * @param int    $year   年份。
     * @param string $type   文种。
     * @param int    $seq    序号。
     * @param string $reason 原因。
     * @return true|WP_Error
     */
    function zw_doc_skip_number($year, $type, $seq, $reason = '') {
        global $wpdb;
        $settings = zw_doc_number_settings();
        $year     = zw_doc_validate_year($year);
        $type     = zw_doc_validate_doc_type($type, $settings);
        $seq      = (int) $seq;
        if (!$year || !$type || $seq < 1) {
            return new WP_Error('zw_doc_bad_number_rule', '年份、文种或序号不合法。');
        }

        $numbers = zw_doc_table('numbers');
        $now     = current_time('mysql');
        $wpdb->query('START TRANSACTION');
        $exists = (int) $wpdb->get_var(zw_doc_prepare_sql(
            "SELECT id FROM {$numbers} WHERE doc_year=%d AND doc_type=%s AND seq=%d LIMIT 1",
            array($year, $type, $seq)
        ));
        if ($exists) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('zw_doc_number_exists', '该文号已被占用，不能跳过。');
        }

        $full = zw_doc_format_number($settings, $type, $year, $seq);
        $ok   = $wpdb->insert(
            $numbers,
            array(
                'doc_year'    => $year,
                'doc_type'    => $type,
                'seq'         => $seq,
                'full_number' => $full,
                'status'      => 'skipped',
                'operator_id' => get_current_user_id(),
                'reason'      => sanitize_text_field((string) $reason),
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array('%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s')
        );
        if ($ok === false) {
            $error = $wpdb->last_error;
            $wpdb->query('ROLLBACK');
            return new WP_Error('zw_doc_skip_failed', '跳过文号失败：' . $error);
        }
        zw_doc_bump_counter_to($year, $type, $seq);
        $wpdb->query('COMMIT');
        return true;
    }
}

if (!function_exists('zw_doc_parse_reserve_spec')) {
    /**
     * 解析预留序号表达式：支持 33、1-10、1-3,5,8-10。
     *
     * @param string $spec 输入表达式。
     * @return int[]|WP_Error
     */
    function zw_doc_parse_reserve_spec($spec) {
        $spec = trim(sanitize_text_field((string) $spec));
        if ($spec === '') {
            return new WP_Error('zw_doc_reserve_empty', '请输入预留序号，如 33 或 1-10。');
        }

        $tokens = preg_split('/[,，、;；\s]+/u', $spec);
        $seqs   = array();
        foreach ((array) $tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (preg_match('/^(\d+)\s*[-~～至]\s*(\d+)$/u', $token, $m)) {
                $from = (int) $m[1];
                $to   = (int) $m[2];
                if ($from < 1 || $to < 1 || $from > $to) {
                    return new WP_Error('zw_doc_reserve_bad_range', '范围不合法：' . $token);
                }
                for ($i = $from; $i <= $to; $i++) {
                    $seqs[] = $i;
                }
            } elseif (preg_match('/^\d+$/u', $token)) {
                $seqs[] = (int) $token;
            } else {
                return new WP_Error('zw_doc_reserve_bad_token', '无法识别的预留序号：' . $token);
            }
        }

        $seqs = array_values(array_unique(array_filter(array_map('absint', $seqs))));
        if (empty($seqs)) {
            return new WP_Error('zw_doc_reserve_empty', '请输入有效的预留序号。');
        }
        if (count($seqs) > 500) {
            return new WP_Error('zw_doc_reserve_too_many', '一次最多预留 500 个文号。');
        }
        return $seqs;
    }
}

if (!function_exists('zw_doc_create_reserved_numbers')) {
    /**
     * 批量预留文号；任一序号冲突则整体回滚。
     *
     * @param int    $year 年份。
     * @param string $type 文种。
     * @param array  $seqs 序号数组。
     * @param string $note 备注。
     * @return array|WP_Error 成功返回 ids 列表。
     */
    function zw_doc_create_reserved_numbers($year, $type, $seqs, $note = '') {
        global $wpdb;
        $settings = zw_doc_number_settings();
        $year     = zw_doc_validate_year($year);
        $type     = zw_doc_validate_doc_type($type, $settings);
        $seqs     = array_values(array_unique(array_filter(array_map('absint', (array) $seqs))));
        if (!$year || !$type || empty($seqs)) {
            return new WP_Error('zw_doc_bad_number_rule', '年份、文种或序号不合法。');
        }

        $numbers = zw_doc_table('numbers');
        $now     = current_time('mysql');
        $wpdb->query('START TRANSACTION');

        $conflicts = array();
        foreach ($seqs as $seq) {
            $exists = (int) $wpdb->get_var(zw_doc_prepare_sql(
                "SELECT id FROM {$numbers} WHERE doc_year=%d AND doc_type=%s AND seq=%d LIMIT 1",
                array($year, $type, $seq)
            ));
            if ($exists) {
                $conflicts[] = $seq;
            }
        }
        if (!empty($conflicts)) {
            $wpdb->query('ROLLBACK');
            return new WP_Error(
                'zw_doc_number_exists',
                '以下序号已在操作记录中，未做任何预留：' . implode('、', array_slice($conflicts, 0, 20)) . (count($conflicts) > 20 ? ' 等' : '')
            );
        }

        $ids = array();
        foreach ($seqs as $seq) {
            $full = zw_doc_format_number($settings, $type, $year, $seq);
            $ok   = $wpdb->insert(
                $numbers,
                array(
                    'doc_year'      => $year,
                    'doc_type'      => $type,
                    'seq'           => $seq,
                    'full_number'   => $full,
                    'status'        => 'reserved',
                    'reserved_by'   => get_current_user_id(),
                    'reserved_note' => sanitize_text_field((string) $note),
                    'reserved_at'   => $now,
                    'operator_id'   => get_current_user_id(),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ),
                array('%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s')
            );
            if ($ok === false) {
                $error = $wpdb->last_error;
                $wpdb->query('ROLLBACK');
                return new WP_Error('zw_doc_reserve_failed', '预留失败：' . $error);
            }
            $ids[] = (int) $wpdb->insert_id;
        }

        zw_doc_bump_counter_to($year, $type, max($seqs));
        $wpdb->query('COMMIT');
        return array('ids' => $ids, 'count' => count($ids));
    }
}

if (!function_exists('zw_doc_create_reserved_number')) {
    /**
     * 兼容单个预留调用。
     *
     * @param int    $year 年份。
     * @param string $type 文种。
     * @param int    $seq  序号。
     * @param string $note 备注。
     * @return int|WP_Error 台账 ID。
     */
    function zw_doc_create_reserved_number($year, $type, $seq, $note = '') {
        $result = zw_doc_create_reserved_numbers($year, $type, array((int) $seq), $note);
        if (is_wp_error($result)) {
            return $result;
        }
        return isset($result['ids'][0]) ? (int) $result['ids'][0] : new WP_Error('zw_doc_reserve_failed', '预留失败。');
    }
}

if (!function_exists('zw_doc_claim_reserved_number')) {
    /**
     * 将 reserved 台账行原子占用为 claimed。
     *
     * @param int $id 台账 ID。
     * @return array|WP_Error
     */
    function zw_doc_claim_reserved_number($id) {
        global $wpdb;
        $id     = (int) $id;
        $row    = zw_doc_get_number_by_id($id);
        if (!$row || $row['status'] !== 'reserved') {
            return new WP_Error('zw_doc_reserved_invalid', '预留文号不存在或已被占用。');
        }
        $table = zw_doc_table('numbers');
        $token = wp_generate_uuid4();
        $now   = current_time('mysql');
        $ok    = $wpdb->query(zw_doc_prepare_sql(
            "UPDATE {$table}
             SET status='claimed', claim_token=%s, operator_id=%d, updated_at=%s, document_id=0
             WHERE id=%d AND status='reserved'",
            array($token, get_current_user_id(), $now, $id)
        ));
        if ($ok === false || $ok === 0) {
            return new WP_Error('zw_doc_reserved_taken', '预留文号已被其他人占用，请重新选择。');
        }
        $row['status']      = 'claimed';
        $row['claim_token'] = $token;
        return $row;
    }
}

if (!function_exists('zw_doc_claim_skipped_number')) {
    /**
     * 将 skipped 操作记录重新占用为 claimed。
     *
     * @param int $id 操作记录 ID。
     * @return array|WP_Error
     */
    function zw_doc_claim_skipped_number($id) {
        global $wpdb;
        $id     = (int) $id;
        $row    = zw_doc_get_number_by_id($id);
        if (!$row || $row['status'] !== 'skipped') {
            return new WP_Error('zw_doc_skipped_invalid', '已跳过文号不存在或已被使用。');
        }
        $table = zw_doc_table('numbers');
        $token = wp_generate_uuid4();
        $now   = current_time('mysql');
        $ok    = $wpdb->query(zw_doc_prepare_sql(
            "UPDATE {$table}
             SET status='claimed', claim_token=%s, operator_id=%d, updated_at=%s, document_id=0
             WHERE id=%d AND status='skipped'",
            array($token, get_current_user_id(), $now, $id)
        ));
        if ($ok === false || $ok === 0) {
            return new WP_Error('zw_doc_skipped_taken', '已跳过文号已被其他人使用，请重新选择。');
        }
        $row['status']      = 'claimed';
        $row['claim_token'] = $token;
        return $row;
    }
}

if (!function_exists('zw_doc_finalize_claim')) {
    /**
     * 将 claimed 台账绑定到发文记录，状态转 used。
     *
     * @param string $token       占号 token。
     * @param int    $document_id 发文 ID。
     * @return bool
     */
    function zw_doc_finalize_claim($token, $document_id) {
        global $wpdb;
        $table = zw_doc_table('numbers');
        $now   = current_time('mysql');
        $ok    = $wpdb->query(zw_doc_prepare_sql(
            "UPDATE {$table}
             SET status='used', document_id=%d, claim_token='', updated_at=%s
             WHERE claim_token=%s AND status='claimed'",
            array((int) $document_id, $now, (string) $token)
        ));
        return $ok !== false && $ok > 0;
    }
}

if (!function_exists('zw_doc_release_claim')) {
    /**
     * 创建失败时释放占号（转 void）。
     *
     * @param string $token  占号 token。
     * @param string $reason 原因。
     * @return bool
     */
    function zw_doc_release_claim($token, $reason = '') {
        global $wpdb;
        $table = zw_doc_table('numbers');
        $now   = current_time('mysql');
        $ok    = $wpdb->query(zw_doc_prepare_sql(
            "UPDATE {$table}
             SET status='void', document_id=0, claim_token='', reason=%s, operator_id=%d, updated_at=%s
             WHERE claim_token=%s AND status='claimed'",
            array(sanitize_text_field((string) $reason), get_current_user_id(), $now, (string) $token)
        ));
        return $ok !== false;
    }
}

if (!function_exists('zw_doc_void_reserved_number')) {
    /**
     * 作废预留号。
     *
     * @param int    $id     台账 ID。
     * @param string $reason 原因。
     * @return bool
     */
    function zw_doc_void_reserved_number($id, $reason = '') {
        global $wpdb;
        $table = zw_doc_table('numbers');
        $now   = current_time('mysql');
        $ok    = $wpdb->query(zw_doc_prepare_sql(
            "UPDATE {$table}
             SET status='void', reason=%s, operator_id=%d, updated_at=%s
             WHERE id=%d AND status='reserved'",
            array(sanitize_text_field((string) $reason), get_current_user_id(), $now, (int) $id)
        ));
        return $ok !== false && $ok > 0;
    }
}

if (!function_exists('zw_doc_get_numbers')) {
    /**
     * 分页查询文号台账。
     *
     * @param array $args 查询参数。
     * @return array{items:array,total:int,pages:int,page:int,per_page:int}
     */
    function zw_doc_get_numbers($args = array()) {
        global $wpdb;
        $defaults = array(
            'year'           => 0,
            'type'           => '',
            'status'         => '',
            'exclude_status' => array(),
            'page'           => 1,
            'per_page'       => 30,
        );
        $args     = wp_parse_args($args, $defaults);
        $table    = zw_doc_table('numbers');
        $where    = array('1=1');
        $params   = array();

        if ((int) $args['year'] > 0) {
            $where[]  = 'doc_year=%d';
            $params[] = (int) $args['year'];
        }
        if ($args['type'] !== '') {
            $where[]  = 'doc_type=%s';
            $params[] = (string) $args['type'];
        }
        if ($args['status'] !== '') {
            $where[]  = 'status=%s';
            $params[] = (string) $args['status'];
        }
        $exclude_status = array_values(array_filter(array_map('sanitize_key', (array) $args['exclude_status'])));
        if (!empty($exclude_status)) {
            $placeholders = implode(',', array_fill(0, count($exclude_status), '%s'));
            $where[]      = "status NOT IN ({$placeholders})";
            foreach ($exclude_status as $exclude) {
                $params[] = $exclude;
            }
        }

        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $count     = (int) ($params ? $wpdb->get_var(zw_doc_prepare_sql($count_sql, $params)) : $wpdb->get_var($count_sql));
        $per_page  = max(1, (int) $args['per_page']);
        $page      = max(1, (int) $args['page']);
        $offset    = ($page - 1) * $per_page;
        $sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY doc_year DESC, doc_type ASC, seq DESC LIMIT %d OFFSET %d";
        $items     = $wpdb->get_results(zw_doc_prepare_sql($sql, array_merge($params, array($per_page, $offset))), ARRAY_A);
        $items     = array_map('zw_doc_number_row_normalize', (array) $items);

        return array(
            'items'    => $items,
            'total'    => $count,
            'pages'    => (int) ceil($count / $per_page),
            'page'     => $page,
            'per_page' => $per_page,
        );
    }
}

if (!function_exists('zw_doc_get_reserved_numbers')) {
    /**
     * 获取某年/文种下的可用预留号。
     *
     * @param int    $year 年份。
     * @param string $type 文种。
     * @return array[]
     */
    function zw_doc_get_reserved_numbers($year, $type) {
        global $wpdb;
        $table = zw_doc_table('numbers');
        $rows  = $wpdb->get_results(zw_doc_prepare_sql(
            "SELECT * FROM {$table}
             WHERE doc_year=%d AND doc_type=%s AND status='reserved'
             ORDER BY seq ASC",
            array((int) $year, (string) $type)
        ), ARRAY_A);
        return array_map('zw_doc_number_row_normalize', (array) $rows);
    }
}

if (!function_exists('zw_doc_get_reusable_numbers')) {
    /**
     * 获取可手工选择的文号：预留 + 已跳过。
     *
     * @param int    $year 年份。
     * @param string $type 文种。
     * @return array[]
     */
    function zw_doc_get_reusable_numbers($year, $type) {
        global $wpdb;
        $table = zw_doc_table('numbers');
        $rows  = $wpdb->get_results(zw_doc_prepare_sql(
            "SELECT * FROM {$table}
             WHERE doc_year=%d AND doc_type=%s AND status IN ('reserved','skipped')
             ORDER BY (status='skipped') ASC, seq ASC",
            array((int) $year, (string) $type)
        ), ARRAY_A);
        return array_map('zw_doc_number_row_normalize', (array) $rows);
    }
}
