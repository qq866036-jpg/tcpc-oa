<?php
/**
 * 收文管理 · 私有存储与目录规则（TASK-1297 · R2）。
 *
 * 口径出处：`docs/architecture/TASK-1297-收文管理-表结构变更与迁移回滚方案-20261008.md` §2.5，
 * 以及 `docs/research/TASK-1297-收文管理-需求对齐稿-20261008.md` §3 US-02。
 *
 * 铁律（架构稿 §2.5 / §5）：
 * - 目录层级固定 `部委/年份/文件名`；「本会制度」固定 `本会制度/`（**不按年**）；
 * - 年份取 `doc_received_date` 的年份；缺失/非法 → `未知年份/`（**不阻断登记**）；
 * - 文件名 `{doc_received_no 或 "无编号"}_{来文字号 或 "无字号"}_{标题截断 60 字}_{版本}.{ext}`，
 *   全字符 `sanitize_file_name()`；
 * - 存储根**复用**既有私有存储 canonical `zw_member_files_base_dir()`，仅新增 `receipts/` 子根，
 *   **不新起第二个存储根**（本文件不读 option、不定义自己的根解析链）；
 * - 元数据一律在 SQL；**禁止**把台账字段写进路径（改名不得丢字段）。
 *
 * 本文件为**纯函数**：只计算路径，不建目录、不写文件、不写库。
 * 目录创建只由 `zw_receipt_storage_ensure_relative_dir()` 在登记上传链路中显式调用。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

// 目录段名来自部委字典（`zw_receipt_org_dir_name()`）；standalone（CLI/测试）加载时自动补齐。
if (!function_exists('zw_receipt_org_dir_name') && file_exists(__DIR__ . '/receipt-orgs.php')) {
    require_once __DIR__ . '/receipt-orgs.php';
}

if (!function_exists('zw_receipt_storage_subroot')) {
    /**
     * 私有存储下的收文子根名（架构稿 §2.5：新增 `receipts/` 子根，不新起第二个存储根）。
     *
     * @return string
     */
    function zw_receipt_storage_subroot() {
        return 'receipts';
    }
}

if (!function_exists('zw_receipt_storage_root')) {
    /**
     * 收文存储根：`{zw_member_files_base_dir()}/receipts`。
     *
     * 复用既有 option/常量优先级链（`zw_office_files_base_dir` → `ZW_OFFICE_FILES_DIR`
     * → `ZW_MEMBER_FILES_DIR` → `WP_CONTENT_DIR/zw-office-files`），本函数**不再解析第二套**。
     *
     * @return string
     */
    function zw_receipt_storage_root() {
        $base = '';
        if (function_exists('zw_member_files_base_dir')) {
            $base = (string) zw_member_files_base_dir();
        }
        if (trim($base) === '' && defined('WP_CONTENT_DIR')) {
            $base = rtrim((string) WP_CONTENT_DIR, '/\\') . '/zw-office-files';
        }
        $root = rtrim($base, '/\\');
        if ($root !== '') {
            $root .= '/' . zw_receipt_storage_subroot();
        }
        if (function_exists('apply_filters')) {
            $root = (string) apply_filters('zw_receipt_storage_root', $root);
        }
        return rtrim($root, '/\\');
    }
}

if (!function_exists('zw_receipt_storage_fallback_sanitize_filename')) {
    /**
     * 无 WP 环境（CLI/单测）下的 `sanitize_file_name()` 退化实现。
     *
     * 仅保留 Unicode 字母/数字与 `._-`，其余（含路径分隔符与空白）折叠为 `-`。
     *
     * @param mixed $name 原始名。
     * @return string
     */
    function zw_receipt_storage_fallback_sanitize_filename($name) {
        $name = (string) $name;
        $name = (string) preg_replace('/[^\p{L}\p{N}._\-]+/u', '-', $name);
        $name = (string) preg_replace('/-+/', '-', $name);
        return trim($name, '.-_');
    }
}

if (!function_exists('zw_receipt_storage_sanitize_filename')) {
    /**
     * 文件名 sanitize（canonical：WP `sanitize_file_name()`；无 WP 时走退化实现）。
     *
     * 额外硬约束：去掉 `/`、`\`、NUL，并剥掉前导点（防隐藏文件与穿越）。
     *
     * @param mixed $name 原始名。
     * @return string
     */
    function zw_receipt_storage_sanitize_filename($name) {
        $name = (string) $name;
        if (function_exists('sanitize_file_name')) {
            $name = (string) sanitize_file_name($name);
        } else {
            $name = zw_receipt_storage_fallback_sanitize_filename($name);
        }
        $name = str_replace(array('/', '\\', "\0"), '', $name);
        return ltrim($name, '.');
    }
}

if (!function_exists('zw_receipt_storage_sanitize_segment')) {
    /**
     * 单级目录段 sanitize（段内不得再含分隔符、不得为 `.` / `..`）。
     *
     * @param mixed $segment 原始段。
     * @return string 非法返回空串。
     */
    function zw_receipt_storage_sanitize_segment($segment) {
        $segment = zw_receipt_storage_sanitize_filename($segment);
        $segment = str_replace(array('/', '\\'), '', $segment);
        $segment = trim($segment, " \t\n\r\0\x0B.");
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return '';
        }
        return $segment;
    }
}

if (!function_exists('zw_receipt_storage_unknown_year_dir')) {
    /**
     * 年份缺失/非法时的目录段（US-02：落「未知年份」，不阻断登记）。
     *
     * @return string
     */
    function zw_receipt_storage_unknown_year_dir() {
        return '未知年份';
    }
}

if (!function_exists('zw_receipt_storage_year_from_date')) {
    /**
     * 从 `doc_received_date`（varchar 日期串）取年份。
     *
     * 解析口径沿用既有政策日期 canonical（`inc/policy-platform/legal-consent.php`
     * 的 `zw_legal_consent_date_le()`）：先 `年/月/日/./` → `-`，再 `strtotime()` 校验整体合法；
     * 额外要求归一后以 4 位数字开头，避免 `strtotime()` 吃掉自然语言串。
     *
     * @param mixed $date 日期串。
     * @return int|false 非法返回 false。
     */
    function zw_receipt_storage_year_from_date($date) {
        $date = trim((string) $date);
        if ($date === '') {
            return false;
        }
        $norm = str_replace(array('年', '月', '/', '.'), '-', str_replace('日', '', $date));
        if (!preg_match('/^(\d{4})/', $norm, $matches)) {
            return false;
        }
        if (strtotime($norm) === false) {
            return false;
        }
        $year = (int) $matches[1];
        if (function_exists('zw_receipt_normalize_year')) {
            $year = zw_receipt_normalize_year($year, false);
        } elseif ($year < 1900 || $year > 2200) {
            $year = false;
        }
        return $year === false ? false : (int) $year;
    }
}

if (!function_exists('zw_receipt_storage_relative_dir')) {
    /**
     * 目录模板：`部委/年份`；「本会制度」为 `部委`（不按年）。
     *
     * @param mixed $org_key           部委 key。
     * @param mixed $received_date     收文日期。
     * @return string 形如 `民政部/2026`、`本会制度`、`中央社会工作部/未知年份`。
     */
    function zw_receipt_storage_relative_dir($org_key, $received_date = '') {
        $segments = array(zw_receipt_org_dir_name($org_key));
        if (!zw_receipt_org_is_year_agnostic($org_key)) {
            $year       = zw_receipt_storage_year_from_date($received_date);
            $segments[] = ($year === false) ? zw_receipt_storage_unknown_year_dir() : (string) $year;
        }
        $clean = array();
        foreach ($segments as $segment) {
            $segment = zw_receipt_storage_sanitize_segment($segment);
            if ($segment !== '') {
                $clean[] = $segment;
            }
        }
        return implode('/', $clean);
    }
}

if (!function_exists('zw_receipt_storage_title_excerpt')) {
    /**
     * 标题按**字符数**截断（默认 60 字，架构稿 §2.5）。
     *
     * @param mixed $title  标题。
     * @param int   $length 保留字符数。
     * @return string
     */
    function zw_receipt_storage_title_excerpt($title, $length = 60) {
        $title  = trim((string) $title);
        $length = max(1, (int) $length);
        if ($title === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return (string) mb_substr($title, 0, $length, 'UTF-8');
        }
        if (preg_match_all('/./us', $title, $matches)) {
            return implode('', array_slice($matches[0], 0, $length));
        }
        return substr($title, 0, $length);
    }
}

if (!function_exists('zw_receipt_storage_build_filename')) {
    /**
     * 文件名模板：`{收文编号 或 无编号}_{来文字号 或 无字号}_{标题截断 60 字}_{版本}.{ext}`。
     *
     * `version`（版本标记）由调用方传入，缺省 `1`：用于同一件来文重复上传/换版时**不覆盖**既有文件。
     *
     * @param array  $args 参数：doc_received_no / doc_no / title / version。
     * @param string $ext  扩展名。
     * @return string
     */
    function zw_receipt_storage_build_filename($args, $ext = '') {
        $args = is_array($args) ? $args : array();

        $number = isset($args['doc_received_no']) ? trim((string) $args['doc_received_no']) : '';
        $doc_no = isset($args['doc_no']) ? trim((string) $args['doc_no']) : '';
        $title  = isset($args['title']) ? (string) $args['title'] : '';
        $ver    = isset($args['version']) ? trim((string) $args['version']) : '';

        if ($number === '') {
            $number = '无编号';
        }
        if ($doc_no === '') {
            $doc_no = '无字号';
        }
        if ($ver === '') {
            $ver = '1';
        }

        $name = zw_receipt_storage_sanitize_filename($number)
            . '_' . zw_receipt_storage_sanitize_filename($doc_no)
            . '_' . zw_receipt_storage_sanitize_filename(zw_receipt_storage_title_excerpt($title, 60))
            . '_' . zw_receipt_storage_sanitize_filename($ver);
        $name = zw_receipt_storage_sanitize_filename($name);
        if ($name === '') {
            $name = 'receipt';
        }

        $ext = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $ext));
        if ($ext === '') {
            $ext = 'bin';
        }
        return $name . '.' . $ext;
    }
}

if (!function_exists('zw_receipt_storage_normalize_relative')) {
    /**
     * 相对路径白名单归一（防穿越的唯一入口）。
     *
     * 拒绝：空、含 `\` 或 NUL、任一段为 `.` / `..` / 空段（含 `//`）。
     * 允许：`部委/年份/文件名`（首尾多余的 `/` 被裁掉）。
     *
     * @param mixed $relative 相对路径。
     * @return string 非法返回空串。
     */
    function zw_receipt_storage_normalize_relative($relative) {
        $relative = trim((string) $relative);
        if ($relative === '') {
            return '';
        }
        if (strpos($relative, '\\') !== false || strpos($relative, "\0") !== false) {
            return '';
        }
        // 绝对路径一律拒绝（含 Unix `/…` 与 Windows `C:…`）：本函数只接受根下相对路径。
        if (strpos($relative, '/') === 0 || preg_match('/^[A-Za-z]:/', $relative)) {
            return '';
        }
        $relative = trim($relative, '/');
        if ($relative === '') {
            return '';
        }
        $clean = array();
        foreach (explode('/', $relative) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
            $clean[] = $segment;
        }
        return implode('/', $clean);
    }
}

if (!function_exists('zw_receipt_storage_relative_path')) {
    /**
     * 生成相对路径 `部委/年份/文件名`（纯计算，不建目录、不落盘）。
     *
     * @param array  $args 参数：org_key / doc_received_date / doc_received_no / doc_no / title / version。
     * @param string $ext  扩展名。
     * @return string 非法返回空串。
     */
    function zw_receipt_storage_relative_path($args, $ext = '') {
        $args = is_array($args) ? $args : array();
        $org  = isset($args['org_key']) ? $args['org_key'] : '';
        $date = isset($args['doc_received_date']) ? $args['doc_received_date'] : '';
        $dir  = zw_receipt_storage_relative_dir($org, $date);
        return zw_receipt_storage_normalize_relative($dir . '/' . zw_receipt_storage_build_filename($args, $ext));
    }
}

if (!function_exists('zw_receipt_storage_absolute_path')) {
    /**
     * 相对路径 → 绝对路径（白名单归一后拼接；非法返回空串）。
     *
     * @param mixed $relative 相对路径。
     * @return string
     */
    function zw_receipt_storage_absolute_path($relative) {
        $relative = zw_receipt_storage_normalize_relative($relative);
        if ($relative === '') {
            return '';
        }
        $root = zw_receipt_storage_root();
        if ($root === '') {
            return '';
        }
        return $root . '/' . $relative;
    }
}

if (!function_exists('zw_receipt_storage_ensure_relative_dir')) {
    /**
     * 确保相对目录存在（**唯一会写文件系统的函数**，仅在登记上传链路显式调用）。
     *
     * @param mixed $relative_dir 相对目录。
     * @return true|WP_Error
     */
    function zw_receipt_storage_ensure_relative_dir($relative_dir) {
        $relative_dir = zw_receipt_storage_normalize_relative($relative_dir);
        if ($relative_dir === '') {
            return new WP_Error('zw_receipt_storage_dir_invalid', '收文目录非法，已拒绝创建。');
        }
        $absolute = zw_receipt_storage_absolute_path($relative_dir);
        if ($absolute === '') {
            return new WP_Error('zw_receipt_storage_root_invalid', '收文存储根不可解析，已拒绝创建。');
        }
        if (@is_dir($absolute)) {
            return true;
        }
        $ok = function_exists('wp_mkdir_p') ? wp_mkdir_p($absolute) : @mkdir($absolute, 0755, true);
        if (!$ok && !@is_dir($absolute)) {
            return new WP_Error('zw_receipt_storage_mkdir', '收文目录创建失败：' . $absolute);
        }
        return true;
    }
}

if (!function_exists('zw_receipt_storage_is_protected_source_file')) {
    /**
     * `source_file` 受保护行判定（架构稿 §2.5）：以 `db:` 开头的行是数据库正文引用，
     * **不是**文件系统路径，迁移与路径解析必须白名单跳过（如 `db:SRC-PRIVACY-POLICY@v2`）。
     *
     * @param mixed $source_file source_file 值。
     * @return bool
     */
    function zw_receipt_storage_is_protected_source_file($source_file) {
        return strpos(ltrim((string) $source_file), 'db:') === 0;
    }
}
