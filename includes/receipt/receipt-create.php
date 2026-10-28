<?php
/**
 * 收文管理 · 登记页与写入路径（TASK-1297 · R3）。
 *
 * 口径出处：
 * - `docs/research/TASK-1297-收文管理-需求对齐稿-20261008.md` §3 US-01（登记字段与验收）/ US-03（收文编号）；
 * - `docs/architecture/TASK-1297-收文管理-表结构变更与迁移回滚方案-20261008.md`
 *   §2.1（复用 `zw_policy_source`，新增 4 列）/ §2.4（`uniq_received_no` 防重号）
 *   / §2.5（私有存储目录与文件名）/ §6.1（开关）/ §6.2（降级链）
 *   / §补充 A.1（函数前缀 `zw_receipt_*`、slug 口径）/ §补充 A.3（只在登记时调用政策源纯函数）
 *   / §5 不变式（I1 生效版本只读、I2 单一政策库、I4 缺列不退化、I5 自产恒为 self、I6 办理状态不改政策语义）。
 *
 * 关键实现约定：
 * - **来的单位落库为字典 key**（US-01 验收 + 研究稿 §5.2「来文单位 → `zw_policy_source.issuer`」）：
 *   架构稿 §2.1 只新增 4 列、未登记「来文单位 key」列 ⇒ 对 `origin='incoming'` 行，`issuer` 承载 key；
 *   展示一律走 `zw_receipt_display_issuer()`（兼容存量把显示名写进 issuer 的行）。
 * - **紧急程度**：架构稿仅在 §2.2 登记「`zw_policy_issue.risk_level` 可复用于紧急程度（可选）」，
 *   `zw_policy_source` **无该列且未登记新增** ⇒ 本轮只做白名单归一 + 随登记结果返回（供 R5 派单落 `risk_level`），
 *   **不写未登记列**。
 * - 幂等键：`zw_policy_source.id`（§补充 A.3）——同 id 重复提交直接返回原记录，不新增行。
 * - 缺列（迁移未跑）→ **链路短路、零写入**（铁律 I4）。
 * - 本文件**不 UPDATE/DELETE** 政策源正文与 `zw_policy_document_version`（铁律 I1）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

// 依赖（standalone / CLI 加载时自动补齐；bootstrap 的开启分支按同序加载）。
if (!function_exists('zw_receipt_is_enabled')) {
    require_once __DIR__ . '/receipt-helpers.php';
}
if (!function_exists('zw_receipt_org_options')) {
    require_once __DIR__ . '/receipt-orgs.php';
}
if (!function_exists('zw_receipt_storage_relative_path')) {
    require_once __DIR__ . '/receipt-storage.php';
}
if (!function_exists('zw_receipt_claim_auto_number')) {
    require_once __DIR__ . '/receipt-number.php';
}

if (!defined('ZW_RECEIPT_CREATE_NONCE_ACTION')) {
    define('ZW_RECEIPT_CREATE_NONCE_ACTION', 'zw_receipt_create');
}

if (!defined('ZW_RECEIPT_CREATE_MAX_ATTACHMENT_BYTES')) {
    // 与既有私有存储上限同口径（`inc/files/member-files.php:447` 20MB）。
    define('ZW_RECEIPT_CREATE_MAX_ATTACHMENT_BYTES', 20 * 1024 * 1024);
}

if (!function_exists('zw_receipt_source_table')) {
    /**
     * 政策源表名（**唯一政策库**，铁律 I2：不新建第二套收文库）。
     *
     * @return string
     */
    function zw_receipt_source_table() {
        global $wpdb;
        $prefix = (is_object($wpdb) && isset($wpdb->prefix)) ? (string) $wpdb->prefix : 'wp_';
        return $prefix . 'zw_policy_source';
    }
}

if (!function_exists('zw_receipt_create_array_a')) {
    /**
     * `ARRAY_A` 常量（standalone / CLI 下的退化值）。
     *
     * @return string
     */
    function zw_receipt_create_array_a() {
        return defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
    }
}

if (!function_exists('zw_receipt_create_text')) {
    /**
     * 单行文本净化（`sanitize_text_field(wp_unslash())` 口径；无 WP 时退化）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_create_text($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = (string) $value;
        if (function_exists('wp_unslash')) {
            $value = (string) wp_unslash($value);
        }
        if (function_exists('sanitize_text_field')) {
            return trim((string) sanitize_text_field($value));
        }
        $value = str_replace(array("\0", "\r", "\n", "\t"), '', strip_tags($value));
        return trim($value);
    }
}

if (!function_exists('zw_receipt_create_key')) {
    /**
     * key 净化（`sanitize_key()` 口径；无 WP 时退化）。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_create_key($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        if (function_exists('sanitize_key')) {
            return (string) sanitize_key((string) $value);
        }
        return strtolower((string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $value));
    }
}

if (!function_exists('zw_receipt_create_truncate')) {
    /**
     * 按字符数截断（对齐列宽；避免超长写入被 MySQL 截断产生隐式告警）。
     *
     * @param mixed $value  文本。
     * @param int   $length 上限字符数。
     * @return string
     */
    function zw_receipt_create_truncate($value, $length) {
        $value  = (string) $value;
        $length = max(1, (int) $length);
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, $length, 'UTF-8');
        }
        if (preg_match_all('/./us', $value, $matches)) {
            return implode('', array_slice($matches[0], 0, $length));
        }
        return substr($value, 0, $length);
    }
}

/* ═════════════════════════════ 数据表与缺列短路 ═════════════════════════════ */

if (!function_exists('zw_receipt_source_columns')) {
    /**
     * 政策源表既有列（`SHOW COLUMNS`；静态缓存，可用 `$fresh = true` 强刷）。
     *
     * 表不存在 / 无 DB 连接 → 返回空数组 ⇒ 上层判为「缺列」并短路（绝不退化为写入全表）。
     *
     * @param bool $fresh 是否强制刷新缓存。
     * @return array<string,bool>
     */
    function zw_receipt_source_columns($fresh = false) {
        static $cache = null;
        if ($fresh) {
            $cache = null;
        }
        if (is_array($cache)) {
            return $cache;
        }
        $cache = array();
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return $cache;
        }
        $table = zw_receipt_source_table();
        $rows  = $wpdb->get_results(zw_receipt_prepare_sql("SHOW COLUMNS FROM {$table}", array()), zw_receipt_create_array_a());
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $field = isset($row['Field']) ? (string) $row['Field'] : '';
                if ($field !== '') {
                    $cache[$field] = true;
                }
            }
        }
        return $cache;
    }
}

if (!function_exists('zw_receipt_source_required_columns')) {
    /**
     * 登记写入路径**必需**的列（含迁移新增的 4 列）。
     *
     * @return string[]
     */
    function zw_receipt_source_required_columns() {
        return array(
            'id',
            'doc_name',
            'issuer',
            'source_type',
            'origin',
            'doc_received_date',
            'doc_received_no',
            'copy_count',
        );
    }
}

if (!function_exists('zw_receipt_source_missing_columns')) {
    /**
     * 缺失的必需列（非空 ⇒ 迁移未跑，登记链路必须短路）。
     *
     * @return string[]
     */
    function zw_receipt_source_missing_columns() {
        $columns = zw_receipt_source_columns();
        $missing = array();
        foreach (zw_receipt_source_required_columns() as $column) {
            if (!isset($columns[$column])) {
                $missing[] = $column;
            }
        }
        return $missing;
    }
}

/* ═════════════════════════════ 取值字典与默认口径 ═════════════════════════════ */

if (!function_exists('zw_receipt_confidentiality_options')) {
    /**
     * 密级取值域。
     *
     * 依据（实测）：生产 `zw_policy_source.confidentiality_level` 现有取值仅 `公开` / `内部`
     * （`SELECT confidentiality_level, COUNT(*) … GROUP BY` 实测：公开 1 / 内部 1 / NULL 4）。
     * 本单不新增未登记字典，故 P0 只开放这两个既有取值（可过滤器扩展）。
     *
     * @return array<string,string> 值 => 显示名（`''` 为「未标注」）
     */
    function zw_receipt_confidentiality_options() {
        $options = array(
            ''     => '未标注',
            '公开' => '公开',
            '内部' => '内部',
        );
        if (function_exists('apply_filters')) {
            $options = apply_filters('zw_receipt_confidentiality_options', $options);
        }
        return is_array($options) ? $options : array();
    }
}

if (!function_exists('zw_receipt_urgency_options')) {
    /**
     * 紧急程度取值域（GB/T 9704-2012 §7.2.3：特急 / 加急）。
     *
     * 依据：`inc/admin/doc/doc-layout-gb9704.php:215`（公文取值唯一出处）。
     * 架构稿 §1 铁律「收文模块不引入对 `inc/admin/doc/**` 的依赖」⇒ 此处**只复用取值口径、不调用该模块**。
     *
     * @return array<string,string>
     */
    function zw_receipt_urgency_options() {
        $options = array(
            ''     => '未标注',
            '特急' => '特急',
            '加急' => '加急',
        );
        if (function_exists('apply_filters')) {
            $options = apply_filters('zw_receipt_urgency_options', $options);
        }
        return is_array($options) ? $options : array();
    }
}

if (!function_exists('zw_receipt_create_default_source_type')) {
    /**
     * `source_type` 默认值（该列 NOT NULL 且生产无默认值，登记必须显式写入）。
     *
     * 保守默认 `内部文件`：收文来源类型未在架构稿 §2.1 登记为登记字段，
     * 默认不把来文当作「公开法规」，避免未定级信息被当作公开依据（可过滤器覆盖）。
     *
     * @param array $payload 归一后的登记载荷（可选）。
     * @return string
     */
    function zw_receipt_create_default_source_type($payload = array()) {
        $type = '内部文件';
        if (function_exists('apply_filters')) {
            $type = (string) apply_filters('zw_receipt_create_source_type', $type, $payload);
        }
        return $type !== '' ? $type : '内部文件';
    }
}

/* ═════════════════════════════ 载荷归一与校验 ═════════════════════════════ */

if (!function_exists('zw_receipt_create_generate_id')) {
    /**
     * 生成收文记录主键（`varchar(64)`，与既有 `SRC-*` 主键同表但独立前缀 `RCP-`）。
     *
     * @param int $year 收文年份。
     * @return string
     */
    function zw_receipt_create_generate_id($year) {
        $year = (int) $year;
        $seed = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('', true) . mt_rand();
        return 'RCP-' . $year . '-' . strtoupper(substr(md5($seed), 0, 8));
    }
}

if (!function_exists('zw_receipt_create_number_year')) {
    /**
     * 收文编号的年份（Q5 / Q4）。
     *
     * 取值顺序：① 收文日期可解析年份 → 用之；② 日期串前 4 位为合法年份 → 用之；
     * ③ 兜底当前年（US-02「收文日期缺失/非法不阻断登记」；目录侧仍会落「未知年份」）。
     *
     * @param mixed $date 收文日期。
     * @return int
     */
    function zw_receipt_create_number_year($date) {
        if (function_exists('zw_receipt_storage_year_from_date')) {
            $year = zw_receipt_storage_year_from_date($date);
            if ($year !== false) {
                return (int) $year;
            }
        }
        $raw = trim((string) $date);
        if (preg_match('/^(\d{4})/', $raw, $matches) && function_exists('zw_receipt_normalize_year')) {
            $year = zw_receipt_normalize_year($matches[1]);
            if ($year !== false) {
                return (int) $year;
            }
        }
        $now = function_exists('current_time') ? current_time('Y') : gmdate('Y');
        if (function_exists('zw_receipt_normalize_year')) {
            $year = zw_receipt_normalize_year($now);
            if ($year !== false) {
                return (int) $year;
            }
        }
        return (int) gmdate('Y');
    }
}

if (!function_exists('zw_receipt_create_normalize_payload')) {
    /**
     * 登记载荷归一 + 必填/白名单校验。
     *
     * @param array $raw 原始载荷（admin-post 的 $_POST + `attachment`）。
     * @return array|WP_Error
     */
    function zw_receipt_create_normalize_payload($raw) {
        $raw = is_array($raw) ? $raw : array();

        // ① 标题（doc_name）——必填（R2 台账：文件名与台账均依赖标题）。
        $title_source = isset($raw['doc_name']) ? $raw['doc_name'] : (isset($raw['title']) ? $raw['title'] : '');
        $doc_name     = zw_receipt_create_truncate(zw_receipt_create_text($title_source), 255);
        if ($doc_name === '') {
            return new WP_Error('zw_receipt_missing_title', '请填写收文标题（文件名称）。');
        }

        // ② 来文单位——必填（US-01），且落库为字典 key（非显示名）。
        $org_key = zw_receipt_create_key(isset($raw['org_key']) ? $raw['org_key'] : '');
        if ($org_key === '') {
            return new WP_Error('zw_receipt_missing_org', '请选择来文单位。');
        }
        $org_key = zw_receipt_org_normalize_key($org_key);

        // ③ 收文日期——必填（US-01）；格式非法不阻断登记（US-02，目录侧落「未知年份」）。
        $received_date = zw_receipt_create_truncate(zw_receipt_create_text(isset($raw['doc_received_date']) ? $raw['doc_received_date'] : ''), 32);
        if ($received_date === '') {
            return new WP_Error('zw_receipt_missing_received_date', '请填写收文日期。');
        }

        // ④ 来文字号——选填；空值写 NULL（不用空串占位）。
        $doc_no = zw_receipt_create_truncate(zw_receipt_create_text(isset($raw['doc_no']) ? $raw['doc_no'] : ''), 128);

        // ⑤ 密级——选填，白名单（表单选择项；直 POST 越权取值一律拒绝）。
        $level   = zw_receipt_create_text(isset($raw['confidentiality_level']) ? $raw['confidentiality_level'] : '');
        $levels  = zw_receipt_confidentiality_options();
        if ($level !== '' && !array_key_exists($level, $levels)) {
            return new WP_Error('zw_receipt_bad_confidentiality', '密级取值不在允许范围内。');
        }

        // ⑥ 紧急程度——选填，白名单（GB/T 9704-2012）；落点见文件头说明（R5 派单写 risk_level）。
        $urgency    = zw_receipt_create_text(isset($raw['urgency']) ? $raw['urgency'] : '');
        $urgencies  = zw_receipt_urgency_options();
        if ($urgency !== '' && !array_key_exists($urgency, $urgencies)) {
            return new WP_Error('zw_receipt_bad_urgency', '紧急程度取值不在允许范围内。');
        }

        // ⑦ 份数——选填；`smallint unsigned`，越界拒绝（避免 MySQL 截断产生隐式脏数据）。
        $copy_raw  = zw_receipt_create_text(isset($raw['copy_count']) ? $raw['copy_count'] : '');
        $copy_count = null;
        if ($copy_raw !== '') {
            if (!preg_match('/^\d{1,5}$/', $copy_raw)) {
                return new WP_Error('zw_receipt_bad_copy_count', '份数须为 1–65535 的整数。');
            }
            $copy_count = (int) $copy_raw;
            if ($copy_count < 1 || $copy_count > 65535) {
                return new WP_Error('zw_receipt_bad_copy_count', '份数须为 1–65535 的整数。');
            }
        }

        // ⑧ 主键（幂等键）——可传入（客户端重试/导入），否则生成。
        $id = isset($raw['id']) ? trim((string) $raw['id']) : '';
        if ($id !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,63}$/', $id)) {
            return new WP_Error('zw_receipt_bad_id', '收文记录 ID 非法。');
        }
        if ($id === '') {
            $id = zw_receipt_create_generate_id(zw_receipt_create_number_year($received_date));
        }

        // ⑨ 手工填号兜底（架构稿 §6.2：计数器表缺失时允许手工填号，仍受 `uniq_received_no` 约束）。
        $manual_number = zw_receipt_create_truncate(zw_receipt_create_text(isset($raw['doc_received_no']) ? $raw['doc_received_no'] : ''), 128);

        // ⑩ 附件版本段（R2 未验证项 1：`{版本}` 来源）——登记固定 v1，可传入覆盖。
        $version = zw_receipt_create_key(isset($raw['version']) ? $raw['version'] : '');
        if ($version === '') {
            $version = '1';
        }
        $version = zw_receipt_create_truncate($version, 8);

        return array(
            'id'                    => $id,
            'doc_name'              => $doc_name,
            'org_key'               => $org_key,
            'doc_no'                => $doc_no,
            'confidentiality_level' => $level,
            'urgency'               => $urgency,
            'copy_count'            => $copy_count,
            'doc_received_date'     => $received_date,
            'manual_number'         => $manual_number,
            'version'               => $version,
            'attachment'            => isset($raw['attachment']) ? $raw['attachment'] : null,
        );
    }
}

if (!function_exists('zw_receipt_display_issuer')) {
    /**
     * 来文单位展示（key → 字典显示名；存量把显示名写进 issuer 的行原样返回）。
     *
     * 迁移回填只改 `origin`、不改 `issuer`（架构稿 §2.5：`origin='self'` 保持原样，
     * 且白名单两行的 issuer 实测为「民政部」「中央社会工作部办公厅」显示名）
     * ⇒ 展示层必须同时兼容 key 与显示名。
     *
     * @param mixed $issuer `zw_policy_source.issuer`。
     * @return string
     */
    function zw_receipt_display_issuer($issuer) {
        $issuer = trim((string) $issuer);
        if ($issuer === '') {
            return '';
        }
        $key = zw_receipt_org_sanitize_key($issuer);
        if ($key !== '' && zw_receipt_org_exists($key)) {
            return zw_receipt_org_label($key);
        }
        return $issuer;
    }
}

/* ═════════════════════════════ 权限与 nonce ═════════════════════════════ */

if (!function_exists('zw_receipt_create_can')) {
    /**
     * 登记权限判定（Q6/Q14：`tcpc_perm_doc`；无 WP 环境一律 fail-closed）。
     *
     * @return bool
     */
    function zw_receipt_create_can() {
        if (!function_exists('current_user_can')) {
            return false;
        }
        return (bool) current_user_can(zw_receipt_capability());
    }
}

if (!function_exists('zw_receipt_create_verify_request')) {
    /**
     * 提交入口鉴权：能力位 + nonce **双判**（US-01/§2.1：页面 + 提交入口双判）。
     *
     * @param array $request 请求数据（$_POST）。
     * @return true|WP_Error
     */
    function zw_receipt_create_verify_request($request) {
        if (!zw_receipt_create_can()) {
            return new WP_Error('zw_receipt_forbidden', '无权限提交收文登记（需要 tcpc_perm_doc）。');
        }
        $request = is_array($request) ? $request : array();
        $nonce   = isset($request['zw_receipt_nonce']) ? (string) $request['zw_receipt_nonce'] : '';
        if (!function_exists('wp_verify_nonce') || !function_exists('wp_unslash')) {
            return new WP_Error('zw_receipt_bad_nonce', '收文登记校验失败：安全令牌不可校验。');
        }
        if ($nonce === '' || !wp_verify_nonce((string) wp_unslash($nonce), ZW_RECEIPT_CREATE_NONCE_ACTION)) {
            return new WP_Error('zw_receipt_bad_nonce', '收文登记校验失败：安全令牌无效或已过期，请刷新页面重试。');
        }
        return true;
    }
}

/* ═════════════════════════════ 附件（私有存储） ═════════════════════════════ */

if (!function_exists('zw_receipt_create_attachment_ext')) {
    /**
     * 附件扩展名（小写、白名单字符）。
     *
     * @param mixed $filename 原始文件名。
     * @return string
     */
    function zw_receipt_create_attachment_ext($filename) {
        $filename = (string) $filename;
        $ext      = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        return (string) preg_replace('/[^a-z0-9]/', '', $ext);
    }
}

if (!function_exists('zw_receipt_create_allowed_ext_map')) {
    /**
     * 附件扩展名 → MIME（**复用**既有私有存储 canonical `zw_member_files_ext_mime_map()`）。
     *
     * 不复制第二套白名单；该 canonical 缺失时返回空数组（fail-closed：一律拒绝附件）。
     *
     * @return array<string,string>
     */
    function zw_receipt_create_allowed_ext_map() {
        if (!function_exists('zw_member_files_ext_mime_map')) {
            return array();
        }
        $map = zw_member_files_ext_mime_map();
        return is_array($map) ? $map : array();
    }
}

if (!function_exists('zw_receipt_create_mime_matches_ext')) {
    /**
     * 真实 MIME 与扩展名是否相容（容忍 OOXML=zip 与未知 `application/octet-stream`）。
     *
     * @param string $mime 真实 MIME。
     * @param string $ext  扩展名。
     * @param array  $map  白名单映射。
     * @return bool
     */
    function zw_receipt_create_mime_matches_ext($mime, $ext, $map) {
        $mime = strtolower(trim((string) $mime));
        if ($mime === '' || $mime === 'application/octet-stream') {
            return true; // 无法识别时不误杀，扩展名白名单仍在生效。
        }
        $expected = isset($map[$ext]) ? strtolower((string) $map[$ext]) : '';
        if ($expected === '') {
            return false;
        }
        if ($mime === $expected) {
            return true;
        }
        // OOXML（docx/xlsx/pptx）本质是 zip：finfo 可能报 application/zip。
        if ($mime === 'application/zip' && in_array($ext, array('docx', 'xlsx', 'pptx'), true)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('zw_receipt_create_validate_attachment')) {
    /**
     * 附件校验（**不落盘**；失败早退 ⇒ 不产生登记行）。
     *
     * @param mixed $file `$_FILES` 单文件项（或 null）。
     * @return array|WP_Error array('present'=>bool,'ext'=>string)
     */
    function zw_receipt_create_validate_attachment($file) {
        if (!is_array($file) || !isset($file['name'])) {
            return array('present' => false, 'ext' => '');
        }
        $error = isset($file['error']) ? (int) $file['error'] : 0;
        $name  = zw_receipt_create_text($file['name']);
        if ($error === 4 || $name === '') { // UPLOAD_ERR_NO_FILE
            return array('present' => false, 'ext' => '');
        }
        if ($error !== 0) {
            return new WP_Error('zw_receipt_attachment_upload_error', '附件上传失败（错误码 ' . $error . '），请重试。');
        }

        $name = function_exists('sanitize_file_name') ? (string) sanitize_file_name($name) : $name;
        $ext  = zw_receipt_create_attachment_ext($name);
        $map  = zw_receipt_create_allowed_ext_map();
        if ($ext === '' || !array_key_exists($ext, $map)) {
            return new WP_Error('zw_receipt_attachment_ext', '附件类型不允许：' . ($ext === '' ? '(无扩展名)' : $ext) . '。');
        }

        $tmp  = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmp === '') {
            return new WP_Error('zw_receipt_attachment_tmp', '附件临时文件缺失，请重新选择文件。');
        }
        $size = isset($file['size']) && (int) $file['size'] > 0
            ? (int) $file['size']
            : (is_readable($tmp) ? (int) @filesize($tmp) : 0);
        if ($size <= 0) {
            return new WP_Error('zw_receipt_attachment_empty', '附件为空文件，已拒绝。');
        }
        if ($size > ZW_RECEIPT_CREATE_MAX_ATTACHMENT_BYTES) {
            return new WP_Error('zw_receipt_attachment_too_large', '附件超过 ' . (int) (ZW_RECEIPT_CREATE_MAX_ATTACHMENT_BYTES / 1024 / 1024) . 'MB 上限。');
        }

        if (function_exists('zw_member_files_mime_from_file') && @is_readable($tmp)) {
            $mime = (string) zw_member_files_mime_from_file($tmp);
            if (!zw_receipt_create_mime_matches_ext($mime, $ext, $map)) {
                return new WP_Error('zw_receipt_attachment_mime', '附件内容与扩展名不一致（' . $mime . '），已拒绝。');
            }
        }

        return array('present' => true, 'ext' => $ext);
    }
}

if (!function_exists('zw_receipt_create_store_attachment')) {
    /**
     * 附件写入私有存储（**唯一落盘函数**）。
     *
     * `$deps['ensure_dir']` / `$deps['mover']` 仅供测试注入（默认 null = 走真实 `wp_mkdir_p` /
     * `move_uploaded_file`），以免单测向工作区外写文件。
     *
     * @param array $file     `$_FILES` 单文件项。
     * @param array $payload  归一后的载荷（含 doc_received_no）。
     * @param array $deps     依赖注入（可选）。
     * @return string|WP_Error 相对路径（`部委/年份/文件名`）。
     */
    function zw_receipt_create_store_attachment($file, $payload, $deps = array()) {
        $payload = is_array($payload) ? $payload : array();
        $deps    = is_array($deps) ? $deps : array();
        $checked = zw_receipt_create_validate_attachment($file);
        if (is_wp_error($checked)) {
            return $checked;
        }
        if (empty($checked['present'])) {
            return new WP_Error('zw_receipt_attachment_absent', '未接收到附件。');
        }

        $relative = zw_receipt_storage_relative_path(array(
            'org_key'           => isset($payload['org_key']) ? $payload['org_key'] : '',
            'doc_received_date' => isset($payload['doc_received_date']) ? $payload['doc_received_date'] : '',
            'doc_received_no'   => isset($payload['doc_received_no']) ? $payload['doc_received_no'] : '',
            'doc_no'            => isset($payload['doc_no']) ? $payload['doc_no'] : '',
            'title'             => isset($payload['doc_name']) ? $payload['doc_name'] : '',
            'version'           => isset($payload['version']) ? $payload['version'] : '1',
        ), $checked['ext']);
        if ($relative === '') {
            return new WP_Error('zw_receipt_attachment_path', '附件存储路径非法，已拒绝写入。');
        }

        $dir = (string) dirname($relative);
        if ($dir === '.' || $dir === '') {
            return new WP_Error('zw_receipt_attachment_dir', '附件存储目录非法，已拒绝写入。');
        }

        if (isset($deps['ensure_dir']) && is_callable($deps['ensure_dir'])) {
            $ensured = call_user_func($deps['ensure_dir'], $dir);
        } else {
            $ensured = zw_receipt_storage_ensure_relative_dir($dir);
        }
        if (is_wp_error($ensured)) {
            return $ensured;
        }

        $absolute = zw_receipt_storage_absolute_path($relative);
        if ($absolute === '') {
            return new WP_Error('zw_receipt_attachment_root', '私有存储根不可解析，附件未写入。');
        }

        $tmp = (string) $file['tmp_name'];
        if (isset($deps['mover']) && is_callable($deps['mover'])) {
            $moved = (bool) call_user_func($deps['mover'], $tmp, $absolute);
        } elseif (function_exists('is_uploaded_file') && @is_uploaded_file($tmp)) {
            $moved = (bool) @move_uploaded_file($tmp, $absolute);
        } else {
            // CLI / 非 HTTP 上传来源（如批量导入）：先复制再删源，任一失败保留源文件。
            $moved = (bool) @copy($tmp, $absolute);
            if ($moved) {
                @unlink($tmp);
            }
        }
        if (!$moved) {
            return new WP_Error('zw_receipt_attachment_move', '附件写入私有存储失败：' . $relative);
        }
        return $relative;
    }
}

/* ═════════════════════════════ 写入（幂等 + 唯一编号） ═════════════════════════════ */

if (!function_exists('zw_receipt_create_find_row')) {
    /**
     * 按主键读取政策源行（幂等判定）。
     *
     * @param string $id 主键。
     * @return array|null
     */
    function zw_receipt_create_find_row($id) {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_row')) {
            return null;
        }
        $id = (string) $id;
        if ($id === '') {
            return null;
        }
        $table = zw_receipt_source_table();
        $row   = $wpdb->get_row(zw_receipt_prepare_sql("SELECT * FROM {$table} WHERE id=%s", array($id)), zw_receipt_create_array_a());
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('zw_receipt_create_is_number_conflict')) {
    /**
     * 判断 DB 错误是否为 `doc_received_no` 唯一键冲突（§2.4：冲突取下一号重试）。
     *
     * @param string $error  错误信息。
     * @param string $number 本次尝试的编号。
     * @return bool
     */
    function zw_receipt_create_is_number_conflict($error, $number) {
        $error  = (string) $error;
        $number = (string) $number;
        if ($error === '') {
            return false;
        }
        if (stripos($error, 'uniq_received_no') !== false) {
            return true;
        }
        if (stripos($error, 'duplicate') !== false && $number !== '' && strpos($error, $number) !== false) {
            return true;
        }
        return false;
    }
}

if (!function_exists('zw_receipt_create_result')) {
    /**
     * 统一返回结构。
     *
     * @param array $row       政策源行。
     * @param bool  $created   是否本次新建。
     * @param array $payload   归一载荷（提供 urgency/version 回传）。
     * @return array
     */
    function zw_receipt_create_result($row, $created, $payload = array()) {
        $row = is_array($row) ? $row : array();
        return array(
            'id'         => isset($row['id']) ? (string) $row['id'] : '',
            'number'     => isset($row['doc_received_no']) ? (string) $row['doc_received_no'] : '',
            'created'    => (bool) $created,
            'idempotent' => !$created,
            'urgency'    => isset($payload['urgency']) ? (string) $payload['urgency'] : '',
            'version'    => isset($payload['version']) ? (string) $payload['version'] : '1',
            'row'        => $row,
        );
    }
}

if (!function_exists('zw_receipt_create_register')) {
    /**
     * 收文登记（canonical 写入路径）。
     *
     * @param array $raw  原始载荷。
     * @param array $deps 依赖注入（测试用：increment / ensure_dir / mover）。
     * @return array|WP_Error
     */
    function zw_receipt_create_register($raw, $deps = array()) {
        global $wpdb;
        $deps   = is_array($deps) ? $deps : array();
        $result = zw_receipt_create_normalize_payload($raw);
        if (is_wp_error($result)) {
            return $result;
        }
        $payload = $result;

        if (!is_object($wpdb) || !method_exists($wpdb, 'insert')) {
            return new WP_Error('zw_receipt_no_db', '数据库不可用，收文未写入。');
        }

        // ① 缺列短路（铁律 I4）：迁移未跑 → 零写入。
        $missing = zw_receipt_source_missing_columns();
        if ($missing) {
            return new WP_Error(
                'zw_receipt_schema_missing',
                '收文数据表尚未迁移（缺少列：' . implode('、', $missing) . '），已中止登记，未写入任何数据。'
            );
        }

        // ② 幂等：同 id 已存在 → 直接返回原记录（不新增行）。
        $existing = zw_receipt_create_find_row($payload['id']);
        if ($existing !== null) {
            return zw_receipt_create_result($existing, false, $payload);
        }

        // ③ 附件结构/类型/大小校验（不落盘）：失败早退 ⇒ 不产生登记行。
        $attachment = zw_receipt_create_validate_attachment($payload['attachment']);
        if (is_wp_error($attachment)) {
            return $attachment;
        }
        $has_attachment = !empty($attachment['present']);

        $now = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
        $operator = '';
        $user     = zw_receipt_current_user();
        if (is_object($user) && isset($user->user_login)) {
            $operator = (string) $user->user_login;
        }

        $row = array(
            'id'                    => $payload['id'],
            'doc_name'              => $payload['doc_name'],
            'issuer'                => $payload['org_key'], // US-01：来文单位落库为 key
            'doc_no'                => $payload['doc_no'] !== '' ? $payload['doc_no'] : null,
            'source_type'           => zw_receipt_create_default_source_type($payload),
            'confidentiality_level' => $payload['confidentiality_level'] !== '' ? $payload['confidentiality_level'] : null,
            'origin'                => zw_receipt_origin_incoming(),
            'doc_received_date'     => $payload['doc_received_date'],
            'copy_count'            => $payload['copy_count'],
            'source_file'           => '',
            'created_by'            => $operator,
            'create_time'           => $now,
            'update_time'           => $now,
        );
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('zw_receipt_create_row', $row, $payload);
            if (is_array($filtered)) {
                $row = $filtered;
            }
        }

        $table = zw_receipt_source_table();
        $number = '';

        // ④ 编号与写入：自动编号 canonical 占号；每次尝试都真实写行，冲突则取下一号。
        $reserve = function ($candidate, $seq, $year) use (&$row, $wpdb, $table) {
            $row['doc_received_no'] = $candidate;
            $inserted = $wpdb->insert($table, $row);
            if ($inserted === false) {
                $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
                if (zw_receipt_create_is_number_conflict($error, $candidate)) {
                    return false; // 唯一键冲突：序号已消耗，取下一号重试。
                }
                if (stripos($error, 'duplicate') !== false) {
                    // 主键冲突 = 并发下同 id 已被写入 → 走幂等返回（非冲突类失败不重试）。
                    return new WP_Error('zw_receipt_id_conflict', '同一收文记录已被写入（并发重复提交）。');
                }
                return new WP_Error('zw_receipt_insert_failed', '收文写入失败：' . $error);
            }
            return array('id' => $row['id'], 'doc_received_no' => $candidate, 'seq' => (int) $seq, 'year' => (int) $year);
        };

        $increment = isset($deps['increment']) && is_callable($deps['increment']) ? $deps['increment'] : null;
        $use_manual = ($payload['manual_number'] !== '' && !zw_receipt_counter_table_exists());

        if ($use_manual) {
            // 降级链（§6.2）：计数器不可用 + 操作者手工填号，仍受 `uniq_received_no` 约束。
            $number   = $payload['manual_number'];
            $inserted = $wpdb->insert($table, array_merge($row, array('doc_received_no' => $number)));
            if ($inserted === false) {
                $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
                if (zw_receipt_create_is_number_conflict($error, $number)) {
                    return new WP_Error('zw_receipt_number_duplicate', '收文编号已存在：' . $number . '，请更换。');
                }
                if (stripos($error, 'duplicate') !== false) {
                    $existing = zw_receipt_create_find_row($payload['id']);
                    if ($existing !== null) {
                        return zw_receipt_create_result($existing, false, $payload);
                    }
                }
                return new WP_Error('zw_receipt_insert_failed', '收文写入失败：' . $error);
            }
        } else {
            $year    = zw_receipt_create_number_year($payload['doc_received_date']);
            // TASK-1306：编号维度 (部委, 年) + 部委码段 —— 部委 key 取自表单（已归一）。
            $claimed = zw_receipt_claim_auto_number($year, $reserve, $increment, $payload['org_key']);
            if (is_wp_error($claimed)) {
                if ($claimed->get_error_code() === 'zw_receipt_counter_unavailable') {
                    return new WP_Error(
                        'zw_receipt_manual_number_required',
                        $claimed->get_error_message() . '（可填写收文编号后重试）'
                    );
                }
                if ($claimed->get_error_code() === 'zw_receipt_id_conflict') {
                    $existing = zw_receipt_create_find_row($payload['id']);
                    if ($existing !== null) {
                        return zw_receipt_create_result($existing, false, $payload);
                    }
                }
                return $claimed;
            }
            $number = (string) $claimed['number'];
        }

        $row['doc_received_no'] = $number;

        // ⑤ 附件落私有存储 + 回填 `source_file`（仅本行自身，不触碰正文/版本表）。
        if ($has_attachment) {
            $stored = zw_receipt_create_store_attachment(
                $payload['attachment'],
                array_merge($payload, array('doc_received_no' => $number)),
                $deps
            );
            if (is_wp_error($stored)) {
                zw_receipt_create_audit('RECEIPT_CREATE_ATTACHMENT_FAILED', $row['id'], array(), array('number' => $number), $stored->get_error_message());
                return new WP_Error(
                    'zw_receipt_attachment_store_failed',
                    '登记元数据已保存（编号 ' . $number . '），但附件写入失败：' . $stored->get_error_message(),
                    array('id' => $row['id'], 'number' => $number)
                );
            }
            if (method_exists($wpdb, 'update')) {
                $wpdb->update($table, array('source_file' => $stored, 'update_time' => $now), array('id' => $row['id']));
            }
            $row['source_file'] = $stored;
        }

        zw_receipt_create_audit('RECEIPT_CREATE', $row['id'], array(), $row, '收文登记：' . $number);

        $result = zw_receipt_create_result($row, true, $payload);
        $result['row'] = $row;
        return $result;
    }
}

if (!function_exists('zw_receipt_create_audit')) {
    /**
     * 收文登记审计（复用 AI 法务部既有审计通道；不可用时静默跳过，不影响写入结果）。
     *
     * @param string $action 动作。
     * @param string $id     政策源主键。
     * @param array  $before 变更前。
     * @param array  $after  变更后。
     * @param string $detail 说明。
     * @return bool
     */
    function zw_receipt_create_audit($action, $id, $before, $after, $detail = '') {
        if (!function_exists('zw_policy_platform_audit_insert')) {
            return false;
        }
        $operator = '';
        $user     = zw_receipt_current_user();
        if (is_object($user) && isset($user->user_login)) {
            $operator = (string) $user->user_login;
        }
        zw_policy_platform_audit_insert((string) $action, 'policy_source', array((string) $id), $before, $after, $operator, (string) $detail);
        return true;
    }
}

if (!function_exists('zw_receipt_current_user')) {
    /**
     * `wp_get_current_user()` 的安全包装（避免 standalone 环境未定义）。
     *
     * @return object|null
     */
    function zw_receipt_current_user() {
        return function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    }
}

/* ═════════════════════════════ 提交入口与登记页 ═════════════════════════════ */

if (!function_exists('zw_receipt_create_handle_submission')) {
    /**
     * admin-post 提交处理（纯逻辑，便于单测直调）：鉴权 → 归一 → 写入。
     *
     * @param array $request $_POST。
     * @param array $files   $_FILES。
     * @return array|WP_Error
     */
    function zw_receipt_create_handle_submission($request, $files = array()) {
        $verify = zw_receipt_create_verify_request($request);
        if (is_wp_error($verify)) {
            return $verify;
        }
        $payload = is_array($request) ? $request : array();
        if (is_array($files) && isset($files['zw_receipt_attachment'])) {
            $payload['attachment'] = $files['zw_receipt_attachment'];
        }
        return zw_receipt_create_register($payload);
    }
}

if (!function_exists('zw_receipt_handle_create_post')) {
    /**
     * `admin_post_zw_receipt_create` 入口（仅在功能开启后由本文件注册）。
     *
     * @return void
     */
    function zw_receipt_handle_create_post() {
        if (!function_exists('wp_die') || !function_exists('wp_safe_redirect')) {
            return;
        }
        $result = zw_receipt_create_handle_submission(isset($_POST) ? (array) $_POST : array(), isset($_FILES) ? (array) $_FILES : array());

        if (is_wp_error($result)) {
            $code   = (string) $result->get_error_code();
            $status = in_array($code, array('zw_receipt_forbidden', 'zw_receipt_bad_nonce'), true) ? 403 : 400;
            wp_die(esc_html($result->get_error_message()), '收文登记失败', array('response' => $status));
            return;
        }

        $page = function_exists('zw_receipt_page_ledger') ? 'zw-receipt-ledger' : 'zw-receipt-create';
        wp_safe_redirect(add_query_arg(array(
            'page'   => $page,
            'zw_msg' => 'receipt_created',
            'zw_id'  => rawurlencode((string) $result['id']),
        ), admin_url('admin.php')));
        exit;
    }
}

if (!function_exists('zw_receipt_create_notice')) {
    /**
     * 登记页提示语。
     *
     * @param string $code 提示码。
     * @return string
     */
    function zw_receipt_create_notice($code) {
        $messages = array(
            'receipt_created'       => '收文登记成功，收文编号已生成。',
            'receipt_duplicate'     => '该收文记录已存在（幂等返回原记录，未新增行）。',
            'receipt_counter_ready' => '自动编号未就绪，请手工填写收文编号后重试。',
        );
        $code = (string) $code;
        return isset($messages[$code]) ? $messages[$code] : '';
    }
}

if (!function_exists('zw_receipt_page_create')) {
    /**
     * 收文登记页（capability = `tcpc_perm_doc`）。
     *
     * 页面级**二次判权**（US-01：页面 + 提交入口双判）；无权限直接 403，不渲染表单。
     *
     * @return void
     */
    function zw_receipt_page_create() {
        if (!zw_receipt_create_can()) {
            if (function_exists('wp_die')) {
                wp_die('无权限访问收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
            }
            return;
        }
        if (!function_exists('esc_html')) {
            return;
        }

        $orgs     = zw_receipt_org_options();
        $levels   = zw_receipt_confidentiality_options();
        $urgencies = zw_receipt_urgency_options();
        $msg      = isset($_GET['zw_msg']) ? sanitize_key((string) wp_unslash($_GET['zw_msg'])) : '';
        $notice   = zw_receipt_create_notice($msg);

        echo '<div class="wrap">';
        echo '<h1>收文登记</h1>';
        if ($notice !== '') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }
        echo '<p style="color:#646970">登记上级来文：必填「标题 + 来文单位 + 收文日期」；登记成功即生成收文编号并写入收文台账（复用政策源表，<code>origin=incoming</code>）。</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" class="zw-adm-card" style="padding:14px 16px;max-width:820px">';
        echo '<input type="hidden" name="action" value="zw_receipt_create">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(ZW_RECEIPT_CREATE_NONCE_ACTION, 'zw_receipt_nonce');
        }

        echo '<p><label>收文标题 *<br><input type="text" name="doc_name" class="regular-text" maxlength="255" required></label></p>';
        echo '<p><label>来文单位 *<br><select name="org_key" required>';
        echo '<option value="">请选择</option>';
        foreach ($orgs as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>来文字号<br><input type="text" name="doc_no" class="regular-text" maxlength="128"></label></p>';
        echo '<p><label>密级<br><select name="confidentiality_level">';
        foreach ($levels as $value => $label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>紧急程度<br><select name="urgency">';
        foreach ($urgencies as $value => $label) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>份数<br><input type="number" name="copy_count" min="1" max="65535" step="1"></label></p>';
        echo '<p><label>收文日期 *<br><input type="text" name="doc_received_date" class="regular-text" placeholder="2026-10-09" required></label></p>';
        echo '<p><label>附件<br><input type="file" name="zw_receipt_attachment"></label><br><span style="color:#646970;font-size:12px">走私有存储（<code>部委/年份/文件名</code>），不产生公开直链。</span></p>';
        echo '<p><button type="submit" class="button button-primary">登记收文</button></p>';
        echo '</form>';
        echo '</div>';
    }
}

if (function_exists('add_action')) {
    // 仅在功能开启（模块文件被加载）时存在 ⇒ 关闭时零入口（架构稿 §6.1）。
    add_action('admin_post_zw_receipt_create', 'zw_receipt_handle_create_post');
}
