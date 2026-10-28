<?php
/**
 * 收文编号 canonical（TASK-1297 · R1）。
 *
 * 出处：`docs/architecture/TASK-1297-收文管理-表结构变更与迁移回滚方案-20261008.md`
 * §2.3（新表 `zw_receipt_number_counters`）+ §2.4（`uniq_received_no` 唯一索引防重号）
 * + §6.2（计数器表缺失 → 手工填号兜底，明示「自动编号未就绪」）。
 *
 * 并发口径：
 * - 计数器按**年份**独立，`INSERT … ON DUPLICATE KEY UPDATE last_seq = last_seq + 1`
 *   **单语句原子递增**（与发文 `zw_doc_increment_counter()` 同族做法，但独立表、独立前缀）；
 * - `zw_policy_source.doc_received_no` 的 `uniq_received_no` 唯一索引是**最终兜底**：
 *   占号回调返回 `false` 即视为唯一键冲突，canonical 重试取下一序号（≤100 次）；
 * - **禁止**用 `get_option`/`update_option` 做序号自增；**禁止**写 `zw_doc_numbers`
 *   （铁律 I3 编号域隔离）。
 *
 * 序号**不回收**：占号失败已消耗的序号不复用（US-03「作废/删除不留空号复用」）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_RECEIPT_NUMBER_MAX_ATTEMPTS')) {
    define('ZW_RECEIPT_NUMBER_MAX_ATTEMPTS', 100);
}

if (!function_exists('zw_receipt_counter_table')) {
    /**
     * 收文编号计数器表名（唯一出处：`zw_receipt_number_counters_table()`）。
     *
     * @return string
     */
    function zw_receipt_counter_table() {
        return zw_receipt_number_counters_table();
    }
}

if (!function_exists('zw_receipt_counter_table_exists')) {
    /**
     * 计数器表是否存在（迁移未跑时为 false → 手工填号兜底）。
     *
     * @return bool
     */
    function zw_receipt_counter_table_exists() {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }
        $table = zw_receipt_counter_table();
        $found = $wpdb->get_var(zw_receipt_prepare_sql('SHOW TABLES LIKE %s', array($table)));
        return $found === $table;
    }
}

if (!function_exists('zw_receipt_migration_ready')) {
    /**
     * 迁移①（schema）是否已就绪 —— **开启功能开关的硬前置**（TASK-1297 独立审计处置 #1）。
     *
     * 为什么必须存在：收文编号的去重防线**完全依赖** `uniq_received_no` 唯一索引
     * （`zw_receipt_claim_number()` 以「唯一键冲突 → 重试下一序号」实现并发不重号）。
     * 迁移未跑时**既没有该索引、也可能没有 origin 列** ⇒ 重试失去判据，存在重号窗口；
     * 且台账/目录视图会因缺列返回 0 行（安全但功能不可用）。
     * 因此：**就绪才注册菜单**，未就绪只出管理员提示，不放出页面。
     *
     * 判据（两项同时满足，只用本文件自身 canonical，不依赖懒加载文件）：
     * 1) 计数器表 `zw_receipt_number_counters` 存在；
     * 2) 政策源表存在 `origin` 列（收文视图分流的唯一判据）。
     *
     * @param bool $fresh 是否强制刷新缓存。
     * @return bool
     */
    function zw_receipt_migration_ready($fresh = false) {
        static $cache = null;
        if ($fresh) {
            $cache = null;
        }
        if (is_bool($cache)) {
            return $cache;
        }
        $cache = false;
        if (!zw_receipt_counter_table_exists()) {
            return $cache;
        }
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return $cache;
        }
        $table = function_exists('zw_receipt_ledger_source_table')
            ? (string) zw_receipt_ledger_source_table()
            : ($wpdb->prefix . 'zw_policy_source');
        $rows = $wpdb->get_results(
            zw_receipt_prepare_sql("SHOW COLUMNS FROM {$table}", array()),
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (isset($row['Field']) && (string) $row['Field'] === 'origin') {
                    $cache = true;
                    break;
                }
            }
        }
        return $cache;
    }
}

if (!function_exists('zw_receipt_increment_counter')) {
    /**
     * 原子递增某年份的收文计数器并返回最新序号。
     *
     * 单语句 `INSERT … ON DUPLICATE KEY UPDATE last_seq = last_seq + 1`：
     * 行不存在时以 `$start` 播种（Q5b 起始序号），存在时原地 +1（并发下不重号）。
     *
     * @param int $year  年份。
     * @param int $start 起始序号（仅播种用）。
     * @return int|false
     */
    function zw_receipt_increment_counter($year, $start = 1, $org_key = '') {
        global $wpdb;
        if (!is_object($wpdb)) {
            return false;
        }
        $year    = (int) $year;
        $start   = max(1, (int) $start);
        $org_key = zw_receipt_number_counter_org_key($org_key);
        $table   = zw_receipt_counter_table();
        $now     = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');

        $ok = $wpdb->query(zw_receipt_prepare_sql(
            "INSERT INTO {$table} (org_key, doc_year, last_seq, updated_at)
             VALUES (%s, %d, %d, %s)
             ON DUPLICATE KEY UPDATE last_seq = last_seq + 1, updated_at = VALUES(updated_at)",
            array($org_key, $year, $start, $now)
        ));
        if ($ok === false) {
            return false;
        }

        $last = $wpdb->get_var(zw_receipt_prepare_sql(
            "SELECT last_seq FROM {$table} WHERE org_key=%s AND doc_year=%d",
            array($org_key, $year)
        ));
        return ($last === null || $last === false) ? false : (int) $last;
    }
}

if (!function_exists('zw_receipt_number_counter_org_key')) {
    /**
     * 计数器行使用的部委维度值（TASK-1306）。
     *
     * - **每部委独立序号域**（默认）：返回归一后的部委 key（`mca` / `cssw` …）；
     * - **共用序号域**（`per_org_seq=false`）：恒返回 `''` ⇒ 全部部委落在同一计数器行（序号全局唯一）。
     * - 空入参：返回 `''`（兼容 Q5b 无部委段的历史行与既有测试）。
     *
     * @param mixed $org_key 部委 key。
     * @return string
     */
    function zw_receipt_number_counter_org_key($org_key) {
        if (!function_exists('zw_receipt_number_per_org_seq') || !zw_receipt_number_per_org_seq()) {
            return '';
        }
        $org_key = (string) $org_key;
        if ($org_key === '') {
            return '';
        }
        return function_exists('zw_receipt_org_normalize_key')
            ? zw_receipt_org_normalize_key($org_key)
            : $org_key;
    }
}

if (!function_exists('zw_receipt_claim_auto_number')) {
    /**
     * 领取一个收文编号（canonical）。
     *
     * 返回结构：
     *   array('year'=>int,'seq'=>int,'number'=>string,'org_key'=>string,'attempts'=>int,
     *         'claimed'=>bool,'reservation'=>mixed)
     * 或 `WP_Error`：
     *   - `zw_receipt_bad_year`            年份非法；
     *   - `zw_receipt_counter_unavailable` 计数器表缺失 / 递增失败 → 走手工填号兜底；
     *   - `zw_receipt_number_conflict`     连续 100 次唯一键冲突。
     *
     * `$reserve`（可选）为占号回调 `function ($number, $seq, $year)`：
     *   - 返回 `false` 视为**唯一键冲突**（`uniq_received_no`）→ 取下一序号重试；
     *   - 返回 `WP_Error` 原样上抛（非冲突类失败不重试）；
     *   - 返回其它非 false/null 值视为占号成功（其值放进 `reservation`）。
     * 不传 `$reserve` 时只递增计数器并返回编号（预览/测试用），`claimed = false`。
     *
     * `$increment`（可选）为序号供给回调 `function ($year, $start, $org_key)`，仅供测试注入；
     * 默认走 DB 原子计数器（TASK-1306：维度为 `(部委, 年)`）。
     *
     * @param int           $year      年份。
     * @param callable|null $reserve   占号回调。
     * @param callable|null $increment 序号供给回调（测试注入）。
     * @param string        $org_key   部委 key（TASK-1306；空 ⇒ 无部委段/共用序号域）。
     * @return array|WP_Error
     */
    function zw_receipt_claim_auto_number($year, $reserve = null, $increment = null, $org_key = '') {
        $year = zw_receipt_normalize_year($year);
        if ($year === false) {
            return new WP_Error('zw_receipt_bad_year', '收文年份不合法（须为 1900–2200 之间的 4 位年份）。');
        }
        if ($reserve !== null && !is_callable($reserve)) {
            return new WP_Error('zw_receipt_bad_reserve', '收文占号回调不可调用。');
        }

        if ($increment === null) {
            // 降级链（架构稿 §6.2）：计数器表缺失 → 不写脏数据，交回调用方提示手工填号。
            if (!zw_receipt_counter_table_exists()) {
                return new WP_Error('zw_receipt_counter_unavailable', '自动编号未就绪（收文计数器表缺失），请手工填写收文编号。');
            }
            $increment = 'zw_receipt_increment_counter';
        }
        if (!is_callable($increment)) {
            return new WP_Error('zw_receipt_bad_increment', '收文序号供给回调不可调用。');
        }

        $counter_org = zw_receipt_number_counter_org_key($org_key);
        $start       = zw_receipt_number_start_seq($year);
        $attempts    = 0;
        $last        = '';

        for ($attempts = 1; $attempts <= ZW_RECEIPT_NUMBER_MAX_ATTEMPTS; $attempts++) {
            // 计数器维度：`(counter_org, year)` —— org_key 由 canonical 归一（TASK-1306）。
            $seq = call_user_func($increment, $year, $start, $counter_org);
            if ($seq === false || $seq === null) {
                return new WP_Error('zw_receipt_counter_unavailable', '自动编号未就绪（收文计数器递增失败），请手工填写收文编号。');
            }
            $seq = (int) $seq;
            if ($seq < 1) {
                return new WP_Error('zw_receipt_counter_unavailable', '自动编号未就绪（收文计数器返回值非法），请手工填写收文编号。');
            }

            $number = zw_receipt_format_number($year, $seq, $org_key);
            $last   = $number;

            if ($reserve === null) {
                return array(
                    'year'        => $year,
                    'seq'         => $seq,
                    'number'      => $number,
                    'org_key'     => (string) $org_key,
                    'attempts'    => $attempts,
                    'claimed'     => false,
                    'reservation' => null,
                );
            }

            $result = call_user_func($reserve, $number, $seq, $year);
            if (is_wp_error($result)) {
                return $result;
            }
            if ($result !== false && $result !== null) {
                return array(
                    'year'        => $year,
                    'seq'         => $seq,
                    'number'      => $number,
                    'org_key'     => (string) $org_key,
                    'attempts'    => $attempts,
                    'claimed'     => true,
                    'reservation' => $result,
                );
            }
            // false = 唯一键冲突：序号已消耗（不回填），取下一序号重试。
        }

        return new WP_Error(
            'zw_receipt_number_conflict',
            '收文编号冲突较多，请重试。' . ($last !== '' ? '（最后一次尝试：' . $last . '）' : '')
        );
    }
}

if (!function_exists('zw_receipt_is_duplicate_error')) {
    /**
     * 判断 DB 错误信息是否为唯一键冲突（占号回调可复用）。
     *
     * @param string $message 错误信息。
     * @return bool
     */
    function zw_receipt_is_duplicate_error($message) {
        return stripos((string) $message, 'duplicate') !== false;
    }
}
