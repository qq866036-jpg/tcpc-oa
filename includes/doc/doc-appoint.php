<?php
/**
 * 「任」字文种 · 人员任命配置（TASK-1291 / SPEC-87 §20.12）。
 *
 * 职责（本文件是「任命要素」的唯一出处）：
 * 1. 字段与常量：文种判定、工作范围上限、引号口径、任命类型推导；
 * 2. 结构清洗：`zw_doc_appoint_encode()` / `zw_doc_appoint_decode()` ——
 *    `meta.appointments` 是**受控结构化 meta**，禁止走 `zw_doc_body_clean_text()`
 *    的通用纯文本分支（会被 `strip_tags` 剥标签 + 200 字截断，导致 JSON 破损）；
 * 3. 模板：`zw_doc_appoint_template_lines()`（四行，按 type 出 2a/2b 两套句式）、
 *    `zw_doc_appoint_title()`（标题模板）；
 * 4. 同步层：`zw_doc_appoint_sync()` 及 `zw_doc_appoint_sync_branch()` /
 *    `zw_doc_appoint_sync_hq()`（两条通道各自复用唯一写入口）。
 *
 * 依赖方向：本文件只**单向调用** `inc/member-center/branch-leaders.php`（读 `zw_branch_leaders()`、
 * 写 `zw_branch_leaders_save()`）与 `inc/admin/org/org-repository.php`（写 `zw_org_insert_person()`）；
 * 依赖均在函数体内部按需调用，文件本身可被单元测试直接 include。
 *
 * 用户拍板口径（2026-10-08，不得擅自改）：
 * - 允许任命到协会本级（中国传统文化促进会）；
 * - 「会内职务」只在选「分支机构」时出现，取其字典 `zw_branch_role_options()` 全部 10 项，**不做任何职务映射**；
 * - 选协会本级时改为「所属部门 + 组织架构职务 + 任期」三件套，职务取 `zw_org_duties()` 自己的字典；
 * - 两套字段按 type **互斥**（type 由已选发文机关服务端推导，不由前端单独提交）；
 * - 引号统一直角引号 “”（U+201C/U+201D）；多人姓名用「、」连接。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_appoint_version')) {
    /**
     * 任命要素结构版本。
     *
     * @return int
     */
    function zw_doc_appoint_version() {
        return 1;
    }
}

if (!function_exists('zw_doc_appoint_scope_limit')) {
    /**
     * 工作范围上限（汉字数，唯一出处）。
     *
     * @return int
     */
    function zw_doc_appoint_scope_limit() {
        return 20;
    }
}

if (!function_exists('zw_doc_appoint_max_items')) {
    /**
     * 单篇发文任命人员上限。
     *
     * @return int
     */
    function zw_doc_appoint_max_items() {
        return 20;
    }
}

if (!function_exists('zw_doc_appoint_max_json_chars')) {
    /**
     * 任命要素 JSON 自限（避免结构化 meta 无限膨胀）。
     *
     * @return int
     */
    function zw_doc_appoint_max_json_chars() {
        return 1500;
    }
}

if (!function_exists('zw_doc_appoint_type_options')) {
    /**
     * 任命类型白名单（'' = 未配置；branch = 分支机构；hq = 协会本级）。
     *
     * @return string[]
     */
    function zw_doc_appoint_type_options() {
        return array('', 'branch', 'hq');
    }
}

if (!function_exists('zw_doc_appoint_is_appoint_type')) {
    /**
     * 是否「任」字文种（其他文种全链路零输出 / 直接短路的关键隔离点）。
     *
     * @param string $type 文种。
     * @return bool
     */
    function zw_doc_appoint_is_appoint_type($type) {
        return trim((string) $type) === '任';
    }
}

if (!function_exists('zw_doc_appoint_doc_type_from_number')) {
    /**
     * 按已占文号**反推文种**（仅用于编辑态呈现，不写库、不改既有契约）。
     *
     * 背景：撰写页的文种来自 `num_type` 入参，而「我的发文 → 编辑正文」链接不带该参数
     * ⇒ 编辑一篇「任」字发文时若只看 `num_type`，②.5 分组会被误判为「非任」而不渲染。
     * 判据：文号在 `〔` 之前的尾部命中 `zw_doc_number_settings()['doc_types']` 中的某一项
     * （与 `zw_doc_format_number()` 的拼装口径互逆）。
     *
     * @param string $number 完整文号（如「中传任〔2026〕1号」）。
     * @return string 命中返回文种；无法判定返回空串。
     */
    function zw_doc_appoint_doc_type_from_number($number) {
        $number = trim((string) $number);
        $pos    = strpos($number, '〔');
        if ($number === '' || $pos === false || $pos === 0) {
            return '';
        }
        if (!function_exists('zw_doc_number_settings')) {
            return '';
        }
        $settings = zw_doc_number_settings();
        $types    = (isset($settings['doc_types']) && is_array($settings['doc_types'])) ? $settings['doc_types'] : array();
        $head     = substr($number, 0, $pos);
        $matched  = '';
        foreach ($types as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            if (substr($head, -strlen($candidate)) === $candidate && strlen($candidate) > strlen($matched)) {
                $matched = $candidate; // 取最长命中，避免短文种误吞（如「字」与「任字」并存的情形）
            }
        }

        return $matched;
    }
}

if (!function_exists('zw_doc_appoint_lquote')) {
    /**
     * 左直角引号（唯一出处）。
     *
     * @return string
     */
    function zw_doc_appoint_lquote() {
        return "\xE2\x80\x9C";
    }
}

if (!function_exists('zw_doc_appoint_rquote')) {
    /**
     * 右直角引号（唯一出处）。
     *
     * @return string
     */
    function zw_doc_appoint_rquote() {
        return "\xE2\x80\x9D";
    }
}

if (!function_exists('zw_doc_appoint_quote')) {
    /**
     * 用直角引号包裹（模板与标题共用，改口径只改这一处）。
     *
     * @param string $text 内文。
     * @return string
     */
    function zw_doc_appoint_quote($text) {
        return zw_doc_appoint_lquote() . (string) $text . zw_doc_appoint_rquote();
    }
}

if (!function_exists('zw_doc_appoint_org_head_name')) {
    /**
     * 协会本级名称（与发文机关名录首项同源）。
     *
     * @return string
     */
    function zw_doc_appoint_org_head_name() {
        if (function_exists('zw_doc_department_head_name')) {
            $head = trim((string) zw_doc_department_head_name());
            if ($head !== '') {
                return $head;
            }
        }

        return '中国传统文化促进会';
    }
}

if (!function_exists('zw_doc_appoint_org_is_hq')) {
    /**
     * 发文机关是否为协会本级。
     *
     * @param string $org 已选发文机关。
     * @return bool
     */
    function zw_doc_appoint_org_is_hq($org) {
        $org = trim((string) $org);

        return $org !== '' && $org === zw_doc_appoint_org_head_name();
    }
}

if (!function_exists('zw_doc_appoint_type_from_org')) {
    /**
     * 由已选发文机关**服务端推导** type（前端不单独提交 type）。
     *
     * @param string $org 已选发文机关。
     * @return string '' | 'branch' | 'hq'
     */
    function zw_doc_appoint_type_from_org($org) {
        $org = trim((string) $org);
        if ($org === '') {
            return '';
        }

        return zw_doc_appoint_org_is_hq($org) ? 'hq' : 'branch';
    }
}

if (!function_exists('zw_doc_appoint_mode_options')) {
    /**
     * 模块级任命模式白名单（TASK-1312）。
     *
     * 模块级单选（`总会任命` / `分支机构任命`）是**服务端权威**：它决定本组字段集与流向，
     * 不再由「发文机关」隐式决定（发文机关仍是分会默认目标回落的兜底）。
     *
     * @return string[]
     */
    function zw_doc_appoint_mode_options() {
        return array('branch', 'hq');
    }
}

if (!function_exists('zw_doc_appoint_mode_label')) {
    /**
     * 模块级模式的用户可见名称（唯一出处）。
     *
     * @param string $mode branch | hq | ''。
     * @return string
     */
    function zw_doc_appoint_mode_label($mode) {
        return (trim((string) $mode) === 'hq') ? '总会任命' : '分支机构任命';
    }
}

if (!function_exists('zw_doc_appoint_mode_of')) {
    /**
     * 本篇任命模式（唯一口径，TASK-1312）。
     *
     * 解析顺序（顺序即契约，改动会破坏存量兼容）：
     * 1. `$meta['mode']` 显式存在且合法（本任务起**新写入一定有该键**）；
     * 2. 其次回落到 `$meta['type']`（TASK-1304 及更早的存量公文只有 `type`）；
     * 3. 都没有 ⇒ `$fallback`（新建表单默认 `branch`）。
     *
     * 存量公文无 `mode` ⇒ 取 `type` ⇒ 与改造前**逐字节等价**（R1）。
     *
     * @param mixed  $meta     任命令要素（数组或标量）。
     * @param string $fallback 兜底模式 branch | hq | ''。
     * @return string 'branch' | 'hq' | ''
     */
    function zw_doc_appoint_mode_of($meta, $fallback = '') {
        $meta = is_array($meta) ? $meta : array();
        foreach (array('mode', 'type') as $key) {
            $candidate = isset($meta[$key]) ? trim((string) $meta[$key]) : '';
            if (in_array($candidate, zw_doc_appoint_mode_options(), true)) {
                return $candidate;
            }
        }
        $fallback = trim((string) $fallback);

        return in_array($fallback, zw_doc_appoint_mode_options(), true) ? $fallback : '';
    }
}

if (!function_exists('zw_doc_appoint_mode_state')) {
    /**
     * 任命模式的**来源**状态（TASK-1312，R1 兼容的关键区分）。
     *
     * - `explicit`：本篇带显式 `mode` 键 ⇒ 模块级单选是权威（同篇不再混选）；
     * - `derived` ：无 `mode`、由发文机关推导（`org`）⇒ 尚未经模块级单选确认，
     *   角色仍按逐行 `dept` 推导（存量混选公文零回归）；
     * - `legacy`  ：无 `mode`、无 `org`、回落文档级 `type` ⇒ 与 TASK-1304 及更早口径等价；
     * - `unknown` ：都没有 ⇒ 无法判定。
     *
     * @param mixed $meta 任命要素（数组或标量）。
     * @return string explicit | derived | legacy | unknown
     */
    function zw_doc_appoint_mode_state($meta) {
        $meta = is_array($meta) ? $meta : array();
        $mode = isset($meta['mode']) ? trim((string) $meta['mode']) : '';
        if (in_array($mode, zw_doc_appoint_mode_options(), true)) {
            return 'explicit';
        }
        $org = isset($meta['org']) ? trim((string) $meta['org']) : '';
        if ($org !== '' && zw_doc_appoint_type_from_org($org) !== '') {
            return 'derived';
        }
        $type = isset($meta['type']) ? trim((string) $meta['type']) : '';
        if (in_array($type, zw_doc_appoint_mode_options(), true)) {
            return 'legacy';
        }

        return 'unknown';
    }
}

if (!function_exists('zw_doc_appoint_normalize_mode')) {
    /**
     * 模块级模式的输入归一（空/未知一律回落到 `$fallback`）。
     *
     * @param mixed  $raw      提交值。
     * @param string $fallback 兜底模式。
     * @return string 'branch' | 'hq' | ''
     */
    function zw_doc_appoint_normalize_mode($raw, $fallback = '') {
        $raw = strtolower(trim((string) $raw));
        if (in_array($raw, zw_doc_appoint_mode_options(), true)) {
            return $raw;
        }

        return zw_doc_appoint_mode_of(array(), $fallback);
    }
}

if (!function_exists('zw_doc_appoint_is_date')) {
    /**
     * 是否合法 `Y-m-d` 真实日期。
     *
     * @param mixed $value 待判定值。
     * @return bool
     */
    function zw_doc_appoint_is_date($value) {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}

if (!function_exists('zw_doc_appoint_text')) {
    /**
     * 结构化字段文本清洗（剥标签 + 去控制字符 + 截断）。
     *
     * 独立于 `zw_doc_body_clean_text()` 但复用其实现，避免两套清洗口径。
     *
     * @param mixed $value 原始值。
     * @param int   $limit 字符上限（0 = 不限）。
     * @return string
     */
    function zw_doc_appoint_text($value, $limit = 0) {
        if (function_exists('zw_doc_body_clean_text')) {
            $text = zw_doc_body_clean_text($value);
        } else {
            $text = trim(strip_tags((string) $value));
        }
        if ($limit > 0) {
            if (function_exists('zw_doc_body_truncate')) {
                $text = zw_doc_body_truncate($text, $limit);
            } else {
                $text = function_exists('mb_substr')
                    ? mb_substr($text, 0, $limit, 'UTF-8')
                    : substr($text, 0, $limit);
            }
        }

        return $text;
    }
}

if (!function_exists('zw_doc_appoint_text_len')) {
    /**
     * 字符数（优先 mbstring）。
     *
     * @param string $text 文本。
     * @return int
     */
    function zw_doc_appoint_text_len($text) {
        if (function_exists('zw_doc_body_strlen')) {
            return (int) zw_doc_body_strlen($text);
        }

        return function_exists('mb_strlen') ? (int) mb_strlen((string) $text, 'UTF-8') : strlen((string) $text);
    }
}

if (!function_exists('zw_doc_appoint_json_encode')) {
    /**
     * 稳定 JSON 编码（中文与斜杠不转义）。
     *
     * @param mixed $data 数据。
     * @return string
     */
    function zw_doc_appoint_json_encode($data) {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $json  = function_exists('wp_json_encode') ? wp_json_encode($data, $flags) : json_encode($data, $flags);

        return is_string($json) ? $json : '';
    }
}

if (!function_exists('zw_doc_appoint_branch_names')) {
    /**
     * 分支机构名录（与发文机关候选同源）。
     *
     * @return string[]
     */
    function zw_doc_appoint_branch_names() {
        if (!function_exists('zw_doc_department_names')) {
            return array();
        }
        $names = zw_doc_department_names();

        return is_array($names) ? array_values($names) : array();
    }
}

if (!function_exists('zw_doc_appoint_branch_is_allowed')) {
    /**
     * 分支机构是否命中名录。
     *
     * 字典不可用（隔离单测）时放行 —— 生产链路由 `doc-bootstrap` 保证名录函数已加载。
     *
     * @param string $name 机构名。
     * @return bool
     */
    function zw_doc_appoint_branch_is_allowed($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return false;
        }
        $allowed = zw_doc_appoint_branch_names();
        if (empty($allowed)) {
            return true;
        }

        return in_array($name, $allowed, true);
    }
}

if (!function_exists('zw_doc_appoint_org_dept_options')) {
    /**
     * 组织架构部门字典（key => name）。
     *
     * @return array<string,string>
     */
    function zw_doc_appoint_org_dept_options() {
        if (!function_exists('zw_org_departments')) {
            return array();
        }
        $out = array();
        foreach ((array) zw_org_departments() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = isset($row['key']) ? trim((string) $row['key']) : '';
            if ($key === '') {
                continue;
            }
            $out[$key] = isset($row['name']) ? (string) $row['name'] : $key;
        }

        return $out;
    }
}

if (!function_exists('zw_doc_appoint_org_dept_name')) {
    /**
     * 部门 key → 部门名（未命中返回空串）。
     *
     * @param string $key 部门 key。
     * @return string
     */
    function zw_doc_appoint_org_dept_name($key) {
        $key     = trim((string) $key);
        $options = zw_doc_appoint_org_dept_options();

        return isset($options[$key]) ? (string) $options[$key] : '';
    }
}

if (!function_exists('zw_doc_appoint_org_dept_is_allowed')) {
    /**
     * 部门 key 是否命中字典。
     *
     * @param string $key 部门 key。
     * @return bool
     */
    function zw_doc_appoint_org_dept_is_allowed($key) {
        $key = trim((string) $key);
        if ($key === '') {
            return false;
        }
        $options = zw_doc_appoint_org_dept_options();
        if (empty($options)) {
            return true;
        }

        return isset($options[$key]);
    }
}

if (!function_exists('zw_doc_appoint_org_duty_options')) {
    /**
     * 组织架构职务字典（key => name）；**与会内职务零映射**。
     *
     * @return array<string,string>
     */
    function zw_doc_appoint_org_duty_options() {
        if (!function_exists('zw_org_duties')) {
            return array();
        }
        $out = array();
        foreach ((array) zw_org_duties() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = isset($row['key']) ? trim((string) $row['key']) : '';
            if ($key === '') {
                continue;
            }
            $out[$key] = isset($row['name']) ? (string) $row['name'] : $key;
        }

        return $out;
    }
}

if (!function_exists('zw_doc_appoint_org_duty_name')) {
    /**
     * 组织架构职务 key → 职务名（未命中返回空串）。
     *
     * @param string $key 职务 key。
     * @return string
     */
    function zw_doc_appoint_org_duty_name($key) {
        $key     = trim((string) $key);
        $options = zw_doc_appoint_org_duty_options();

        return isset($options[$key]) ? (string) $options[$key] : '';
    }
}

if (!function_exists('zw_doc_appoint_org_duty_is_allowed')) {
    /**
     * 组织架构职务 key 是否命中字典。
     *
     * @param string $key 职务 key。
     * @return bool
     */
    function zw_doc_appoint_org_duty_is_allowed($key) {
        $key = trim((string) $key);
        if ($key === '') {
            return false;
        }
        $options = zw_doc_appoint_org_duty_options();
        if (empty($options)) {
            return true;
        }

        return isset($options[$key]);
    }
}

if (!function_exists('zw_doc_appoint_term_options')) {
    /**
     * 届次周期候选（唯一出处 `inc/term-cycles.php`，禁止直读 option）。
     *
     * @return array<int,array{term:string,start:string,end:string}>
     */
    function zw_doc_appoint_term_options() {
        if (!function_exists('zw_term_cycles')) {
            return array();
        }
        $rows = zw_term_cycles();

        return is_array($rows) ? array_values($rows) : array();
    }
}

if (!function_exists('zw_doc_appoint_term_is_allowed')) {
    /**
     * 届次名是否命中届次周期配置。
     *
     * @param string $term 届次名。
     * @return bool
     */
    function zw_doc_appoint_term_is_allowed($term) {
        $term = trim((string) $term);
        if ($term === '') {
            return false;
        }
        $options = zw_doc_appoint_term_options();
        if (empty($options)) {
            return true;
        }
        foreach ($options as $row) {
            if (isset($row['term']) && (string) $row['term'] === $term) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('zw_doc_appoint_term_dates')) {
    /**
     * 某届次的默认起止日（委托届次 canonical；空届次取首项）。
     *
     * @param string $term 届次名。
     * @return array{term:string,start:string,end:string}
     */
    function zw_doc_appoint_term_dates($term = '') {
        if (function_exists('zw_term_cycle_dates')) {
            $dates = zw_term_cycle_dates($term);
            if (is_array($dates)) {
                return array(
                    'term'  => isset($dates['term']) ? (string) $dates['term'] : '',
                    'start' => isset($dates['start']) ? (string) $dates['start'] : '',
                    'end'   => isset($dates['end']) ? (string) $dates['end'] : '',
                );
            }
        }

        return array('term' => '', 'start' => '', 'end' => '');
    }
}

if (!function_exists('zw_doc_appoint_term_default_range')) {
    /**
     * 分支机构任期的**默认区间**（TASK-1319，用户口径）。
     *
     * 口径：**开始 = 今天**（或传入的合法开始日期）；**结束 = 开始 + N 年 − 1 天**（默认 N = 1）。
     * 例：2026-10-09 起 1 年 ⇒ 结束 2027-10-08。
     *
     * 闰年口径与前端快捷按钮**保持一致**：先按「+N 年」取该日，若目标月不存在该日则**回退到目标月最后一天**
     * （2028-02-29 + 3 年 ⇒ 2031-02-28），**再 −1 天**（⇒ 2031-02-27）。两端必须同口径，否则默认值与
     * 点按快捷按钮会算出不同结束日（TASK-1318 的教训：默认与交互口径不一致会产生难查的观感缺陷）。
     *
     * @param string $start 期望的开始日期（yyyy-mm-dd）；非法或空则取今天。
     * @param int    $years 年数（≥1）。
     * @return array{start:string,end:string}
     */
    function zw_doc_appoint_term_default_range($start = '', $years = 1) {
        $start = function_exists('zw_doc_appoint_is_date') ? trim((string) $start) : trim((string) $start);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = date('Y-m-d');
        }
        $years = max(1, (int) $years);
        list($y, $m, $d) = array_map('intval', explode('-', $start));
        $ty = $y + $years;
        // 「+N 年」：目标月不存在该日时回退到该月最后一天（与 JS `new Date(ty, m, 0).getDate()` 同口径）。
        $last = (int) date('t', mktime(12, 0, 0, $m, 1, $ty));
        $td   = min($d, $last);
        $anniv = mktime(12, 0, 0, $m, $td, $ty);
        // 再 −1 天 ⇒ 结束日为「满 N 年的前一天」（含首含尾的任期写法）。
        $end = date('Y-m-d', $anniv - 86400);

        return array('start' => $start, 'end' => $end);
    }
}

if (!function_exists('zw_doc_appoint_duty_label')) {
    /**
     * 会内职务展示文案（唯一出处 `zw_branch_role_*()`；`other` 取原文）。
     *
     * @param string $duty  职务 code。
     * @param string $other 其他职务原文。
     * @return string
     */
    function zw_doc_appoint_duty_label($duty, $other = '') {
        if (function_exists('zw_branch_role_label')) {
            return (string) zw_branch_role_label($duty, $other);
        }
        $duty = trim((string) $duty);
        if ($duty === 'other') {
            $other = trim((string) $other);

            return $other !== '' ? '其他：' . $other : '其他';
        }

        return $duty;
    }
}

if (!function_exists('zw_doc_appoint_duty_parse')) {
    /**
     * 会内职务归一（唯一出处 `zw_branch_role_parse()`；字典不可用时原样保守保留）。
     *
     * @param string $raw 原始职务值。
     * @return array{role:string,other:string}
     */
    function zw_doc_appoint_duty_parse($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return array('role' => '', 'other' => '');
        }
        if (function_exists('zw_branch_role_parse')) {
            $parsed = zw_branch_role_parse($raw);
            if (is_array($parsed)) {
                return array(
                    'role'  => isset($parsed['role']) ? (string) $parsed['role'] : '',
                    'other' => isset($parsed['other']) ? (string) $parsed['other'] : '',
                );
            }
        }

        return array('role' => $raw, 'other' => '');
    }
}

if (!function_exists('zw_doc_appoint_empty')) {
    /**
     * 空任命结构（受控空对象，默认值必须是它而不是空串）。
     *
     * @return array
     */
    function zw_doc_appoint_empty() {
        return array(
            'v'     => zw_doc_appoint_version(),
            'type'  => '',
            'scope' => '',
            'items' => array(),
        );
    }
}

if (!function_exists('zw_doc_appoint_empty_json')) {
    /**
     * 空任命结构的 JSON 形态。
     *
     * @return string
     */
    function zw_doc_appoint_empty_json() {
        return zw_doc_appoint_json_encode(zw_doc_appoint_empty());
    }
}

if (!function_exists('zw_doc_appoint_item_is_blank')) {
    /**
     * 任命行是否完全空白（空行直接丢弃，不进结构、不报错）。
     *
     * @param mixed $item 原始行。
     * @return bool
     */
    function zw_doc_appoint_item_is_blank($item) {
        if (!is_array($item)) {
            return true;
        }
        foreach (array('uid', 'user_id', 'name', 'branch', 'duty', 'duty_other') as $key) {
            if (isset($item[$key]) && trim((string) $item[$key]) !== '') {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('zw_doc_appoint_dept_is_allowed')) {
    /**
     * 拟任命部门是否命中名录（TASK-1304）。
     *
     * 唯一出处与**发文机关候选同一处** `zw_doc_department_names()`（首项为协会本级）；
     * 不在册的值一律丢弃并回落，禁止自由文本。
     *
     * @param string $name 拟任命部门名。
     * @return bool
     */
    function zw_doc_appoint_dept_is_allowed($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return false;
        }
        if (zw_doc_appoint_org_is_hq($name)) {
            return true;
        }

        return zw_doc_appoint_branch_is_allowed($name);
    }
}

if (!function_exists('zw_doc_appoint_resolve_role')) {
    /**
     * 角色回落唯一口径（TASK-1312）：**模块级模式（`mode`）优先于文档级 `type`**。
     *
     * 存量公文只有 `type`（TASK-1304 及更早）⇒ `mode` 空 ⇒ 等价于旧口径（R1）。
     *
     * @param string $mode branch | hq | ''
     * @param string $type branch | hq | ''
     * @return string 'branch' | 'hq' | ''
     */
    function zw_doc_appoint_resolve_role($mode, $type = '') {
        foreach (array($mode, $type) as $candidate) {
            $candidate = trim((string) $candidate);
            if (in_array($candidate, array('branch', 'hq'), true)) {
                return $candidate;
            }
        }

        return '';
    }
}

if (!function_exists('zw_doc_appoint_item_role')) {
    /**
     * 逐人角色推导**唯一口径**（TASK-1304；回落顺序由 TASK-1312 修订）：
     * 显式 `dept` 命中名录 ⇒ `dept === 协会本级名` ? `hq` : `branch`；
     * `dept` 缺省/为协会本级时回落 `$fallback`（调用方传入模块级模式，其次文档级 `type`）。
     *
     * TASK-1312 说明：模块级单选是权威 —— 总会任命模式下「拟任命部门」列隐藏、全部默认
     * 协会本级；分支模式下逐行 `dept` 只用于指定**具体分支机构**（值不得是协会本级名）。
     *
     * 判定复用既有 `zw_doc_appoint_org_is_hq()` / `zw_doc_appoint_org_head_name()`，
     * **不新造判定字典**。
     *
     * @param mixed  $item     任命行。
     * @param string $fallback 回落角色 branch | hq | ''（模块级模式优先，其次文档级 type）。
     * @return string 'hq' | 'branch' | ''
     */
    function zw_doc_appoint_item_role($item, $fallback = '') {
        $item = is_array($item) ? $item : array();
        $dept = isset($item['dept']) ? trim((string) $item['dept']) : '';
        if ($dept !== '' && zw_doc_appoint_dept_is_allowed($dept)) {
            // 显式 `dept` 优先（TASK-1304 逐行口径；协会本级名 ⇒ hq）。
            return zw_doc_appoint_org_is_hq($dept) ? 'hq' : 'branch';
        }
        $fallback = trim((string) $fallback);

        return in_array($fallback, array('branch', 'hq'), true) ? $fallback : '';
    }
}

if (!function_exists('zw_doc_appoint_item_dept_name')) {
    /**
     * 行的**运行时**拟任命部门（不写回磁盘）：显式 `dept` 优先，缺省按角色推断。
     *
     * 存量公文（无 `item.dept`）在 `zw_doc_appoint_decode()` 里补上该视图值，
     * 使前端与模板可统一按行分流；磁盘 JSON **逐字节不变**（R1）。
     *
     * @param mixed  $item     任命行。
     * @param string $fallback 文档级回落类型。
     * @return string
     */
    function zw_doc_appoint_item_dept_name($item, $fallback = '') {
        $item = is_array($item) ? $item : array();
        $dept = isset($item['dept']) ? trim((string) $item['dept']) : '';
        if ($dept !== '' && zw_doc_appoint_dept_is_allowed($dept)) {
            return $dept;
        }
        $role = zw_doc_appoint_item_role($item, $fallback);
        if ($role === 'hq') {
            return zw_doc_appoint_org_head_name();
        }
        if ($role === 'branch') {
            $branch = isset($item['branch']) ? trim((string) $item['branch']) : '';
            if ($branch !== '' && zw_doc_appoint_branch_is_allowed($branch)) {
                return $branch;
            }
        }

        return '';
    }
}

if (!function_exists('zw_doc_appoint_normalize_item')) {
    /**
     * 归一单条任命行（TASK-1304 逐行 + TASK-1312 模块级模式权威）。
     *
     * - `dept`：拟任命部门，命中 `zw_doc_department_names()` 才保留；
     *   **分支模式下 `dept` 只用于指定具体分支机构**（提交协会本级名一律丢弃）；
     *   **总会模式下统一写协会本级名**（不再逐人选），`dept` 因此成为「模式落库痕迹」；
     * - 角色口径（`zw_doc_appoint_item_role()` 同源）：行内 `dept` 为**具体分支机构**时优先
     *   （存量混选公文按行保留），否则取 `$role`（模块级模式优先、其次文档级 `type`）；
     * - branch 行：`branch`（与 dept 同源双写、兼容旧消费者）+ `duty` / `duty_other`
     *   + 可选逐行任期（缺省时同步侧回落文档级 `term_start/term_end`）；
     * - hq 行：`org_dept` / `org_duty` / `org_term_*`；**届次与任期只读** —— 由模块级模式
     *   显式选定的行一律取届次周期当前起止（`inc/term-cycles.php` 冻结契约），客户端提交值
     *   不被接受；存量行（无 dept 且无任何 `org_*` 键）不生成任何 `org_*` 键，保持逐字节兼容。
     *
     * @param mixed  $item 原始行。
     * @param string $role 该行的角色 branch | hq | ''（`zw_doc_appoint_item_role()` 的结果）。
     * @param string $mode 模块级**显式**模式 branch | hq | ''（TASK-1312；空 = 存量/回落口径）。
     * @param array  $doc  文档级回落值（`org_dept` / `org_duty` / `org_term_*`），存量行缺省时取用。
     * @return array
     */
    function zw_doc_appoint_normalize_item($item, $role, $mode = '', $doc = array()) {
        $mode = in_array((string) $mode, array('branch', 'hq'), true) ? (string) $mode : '';
        $doc  = is_array($doc) ? $doc : array();
        $item = is_array($item) ? $item : array();
        $uid  = 0;
        foreach (array('uid', 'user_id') as $key) {
            if (!empty($item[$key])) {
                $uid = abs((int) $item[$key]);
                break;
            }
        }

        $row = array(
            'uid'  => $uid,
            'name' => zw_doc_appoint_text(isset($item['name']) ? $item['name'] : '', 60),
        );

        // 拟任命部门（白名单：与发文机关同一出处）。
        // TASK-1312：分支模式下 `dept` **只能是具体分支机构**（协会本级名一律丢弃）；
        // 总会模式下忽略提交值、统一写协会本级（模块级单值）。
        $dept = zw_doc_appoint_text(isset($item['dept']) ? $item['dept'] : '', 80);
        if ($dept !== '' && !zw_doc_appoint_dept_is_allowed($dept)) {
            $dept = '';
        }
        // 该行是否**自带** `dept`（显式逐行数据）—— 决定本级四件套取行内还是取文档级回落（R1）。
        $own_dept = ($dept !== '');
        $role = in_array((string) $role, array('branch', 'hq'), true) ? (string) $role : '';
        if ($role === 'hq') {
            $row['dept'] = zw_doc_appoint_org_head_name();
        } elseif ($role === 'branch') {
            // 分支模式：协会本级名不是「具体分支机构」⇒ 丢弃，避免把本级串成分支。
            if ($dept !== '' && !zw_doc_appoint_org_is_hq($dept)) {
                $row['dept'] = $dept;
            }
            $dept = (isset($row['dept']) && !zw_doc_appoint_org_is_hq((string) $row['dept'])) ? (string) $row['dept'] : '';
        } elseif ($dept !== '') {
            // 存量（无 mode / 无 type 回落）：保留逐行 `dept` 原值，保证 R1 逐字节与角色判定不变。
            $row['dept'] = $dept;
        }
        if ($role === 'branch') {
            // `dept` 不可用（缺省 / 非具体分支机构）时回落行内 `branch`（与旧消费者同源同形），
            // 保证服务端同步侧 `items[].branch` 永远可判（分支通道按 branch 匹配机构名录）。
            $branch = $dept !== '' ? $dept : zw_doc_appoint_text(isset($item['branch']) ? $item['branch'] : '', 80);
            if ($branch !== '' && (!zw_doc_appoint_branch_is_allowed($branch) || zw_doc_appoint_org_is_hq($branch))) {
                $branch = '';
            }
            $duty       = array('role' => '', 'other' => '');
            $duty_other = '';
            if (isset($item['duty_other']) && trim((string) $item['duty_other']) !== '') {
                $duty_other = zw_doc_appoint_text($item['duty_other'], 30);
            }
            if (isset($item['duty'])) {
                $duty = zw_doc_appoint_duty_parse($item['duty']);
                if ($duty['role'] === 'other') {
                    if ($duty_other === '' && $duty['other'] !== '') {
                        $duty_other = zw_doc_appoint_text($duty['other'], 30);
                    }
                } else {
                    $duty_other = '';
                }
            }
            $row['branch']     = $branch;
            $row['duty']       = (string) $duty['role'];
            $row['duty_other'] = $duty['role'] === 'other' ? $duty_other : '';
            // 逐行分支任期（TASK-1304）：仅在**显式提交**时落键（存量行不新增键 ⇒ R1）。
            foreach (array('term_start', 'term_end') as $term_key) {
                if (isset($item[$term_key]) && trim((string) $item[$term_key]) !== '') {
                    $row[$term_key] = zw_doc_appoint_is_date($item[$term_key]) ? trim((string) $item[$term_key]) : '';
                }
            }
        } elseif ($role === 'hq') {
            // 存量行（无 dept 且无任何 org_* 键）不生成新键，保证 JSON 逐字节兼容。
            // TASK-1312：`mode === 'hq'`（模块级单选显式选定）时，四件套一律取**行内隐藏域**
            // （该隐藏域由模块区 bridge 同步为模块真值）；其余情况按原口径逐行取值。
            $item_has_org = false;
            foreach (array('org_dept', 'org_duty', 'org_term_name', 'org_term_start', 'org_term_end') as $org_key) {
                if (isset($item[$org_key]) && trim((string) $item[$org_key]) !== '') {
                    $item_has_org = true;
                    break;
                }
            }
            $has_org = $item_has_org || (isset($row['dept']) && (string) $row['dept'] !== '');
            $own_org = ($mode === 'hq') || $item_has_org;
            if ($has_org) {
                $org_dept = $own_org
                    ? zw_doc_appoint_text(isset($item['org_dept']) ? $item['org_dept'] : '', 40)
                    : zw_doc_appoint_text(isset($doc['org_dept']) ? $doc['org_dept'] : '', 40);
                $row['org_dept'] = zw_doc_appoint_org_dept_is_allowed($org_dept) ? $org_dept : '';
                $org_duty = $own_org
                    ? zw_doc_appoint_text(isset($item['org_duty']) ? $item['org_duty'] : '', 40)
                    : zw_doc_appoint_text(isset($doc['org_duty']) ? $doc['org_duty'] : '', 40);
                $row['org_duty'] = zw_doc_appoint_org_duty_is_allowed($org_duty) ? $org_duty : '';
                // 只读口径（TASK-1304 / TASK-1312）：**模块级单选显式选定总会任命**时，
                // 届次与任期一律取届次周期当前起止，客户端提交值不生效；
                // 存量行（未显式选定模式）仍按原口径：自带 dept ⇒ 回写当前届次，否则原样保留。
                if ($own_dept || $mode === 'hq') {
                    $dates = zw_doc_appoint_term_dates('');
                    $row['org_term_name']  = (string) $dates['term'];
                    $row['org_term_start'] = (string) $dates['start'];
                    $row['org_term_end']   = (string) $dates['end'];
                } else {
                    // 存量行：逐行缺省时回落**文档级**（与 TASK-1304 及更早逐字节等价）。
                    $term_name = zw_doc_appoint_text(isset($item['org_term_name']) ? $item['org_term_name'] : '', 60);
                    if (!$own_org && $term_name === '' && isset($doc['org_term_name'])) {
                        $term_name = zw_doc_appoint_text($doc['org_term_name'], 60);
                    }
                    $row['org_term_name'] = zw_doc_appoint_term_is_allowed($term_name) ? $term_name : '';
                    $term_start = isset($item['org_term_start']) ? (string) $item['org_term_start'] : '';
                    if (!$own_org && $term_start === '' && isset($doc['org_term_start'])) {
                        $term_start = (string) $doc['org_term_start'];
                    }
                    $row['org_term_start'] = zw_doc_appoint_is_date($term_start) ? trim($term_start) : '';
                    $term_end = isset($item['org_term_end']) ? (string) $item['org_term_end'] : '';
                    if (!$own_org && $term_end === '' && isset($doc['org_term_end'])) {
                        $term_end = (string) $doc['org_term_end'];
                    }
                    $row['org_term_end'] = zw_doc_appoint_is_date($term_end) ? trim($term_end) : '';
                }
            }
        }

        return $row;
    }
}

if (!function_exists('zw_doc_appoint_encode')) {
    /**
     * 任命要素**结构清洗唯一出处**（`meta.appointments` 的编码器）。
     *
     * 铁律：
     * - 受控白名单键；未知键一律丢弃；
     * - 先按 type 做**互斥裁剪**（branch 保留 branch/duty/duty_other/term_*；
     *   hq 保留 org_*；两套字段不会同时落库）；
     * - type 优先由 `$raw['org']`（已选发文机关）**服务端推导**，其次显式 `$type` 参数，
     *   最后才用 `$raw['type']`（前端不单独提交 type）；
     * - 超限即丢弃多余项；返回 JSON 字符串（与正文 AST 同存）。
     *
     * @param mixed  $raw  原始结构（数组或 JSON 字符串）。
     * @param string $type 可选显式 type（服务端推导结果）。
     * @return string JSON 字符串；永远合法、可解析。
     */
    function zw_doc_appoint_encode($raw, $type = '') {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return zw_doc_appoint_empty_json();
            }
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($raw) || empty($raw)) {
            return zw_doc_appoint_empty_json();
        }

        $out = zw_doc_appoint_empty();

        // —— TASK-1312：mode（模块级单选）**服务端权威** ——
        // 决定本组字段集与流向；`org` 推导只作**未提交 mode 时的回落**
        // （存量公文与无 JS 提交不回归）。由此总会任命可以由分支机构机关发出。
        $mode_echo = isset($raw['mode']) ? strtolower(trim((string) $raw['mode'])) : '';
        $mode_hint = in_array($mode_echo, zw_doc_appoint_mode_options(), true) ? $mode_echo : '';

        // —— type：服务端推导优先（org）；显式 mode 时 **mode 即 type** ——
        $resolved = '';
        if ($mode_hint !== '') {
            $resolved = $mode_hint;
        } else {
            if (isset($raw['org']) && trim((string) $raw['org']) !== '') {
                $resolved = zw_doc_appoint_type_from_org($raw['org']);
            }
            if ($resolved === '') {
                $candidate = trim((string) $type);
                if ($candidate === '' && isset($raw['type'])) {
                    $candidate = trim((string) $raw['type']);
                }
                if (in_array($candidate, zw_doc_appoint_type_options(), true)) {
                    $resolved = $candidate;
                }
            }
        }
        $out['type'] = $resolved;
        $mode = zw_doc_appoint_normalize_mode($mode_hint, $resolved);
        // R1：`mode` **只在显式提交/显式存在时落键**（存量公文 JSON 逐字节不变）。
        if ($mode_hint !== '') {
            $out['mode'] = $mode_hint;
        }

        // TASK-1312：工作范围是**分支模式**的文档级单值；总会任命模式**无该字段**
        //（表单不渲染可编辑控件，存量值以隐藏域原样保留 ⇒ 不清空历史数据、R1 不回归）。
        $out['scope'] = ($mode === 'hq')
            ? ($mode_hint !== '' ? '' : zw_doc_appoint_text(isset($raw['scope']) ? $raw['scope'] : '', zw_doc_appoint_scope_limit()))
            : zw_doc_appoint_text(isset($raw['scope']) ? $raw['scope'] : '', zw_doc_appoint_scope_limit());

        $raw_items = isset($raw['items']) && is_array($raw['items']) ? $raw['items'] : array();
        if (isset($raw['items']) && is_string($raw['items'])) {
            $decoded_items = json_decode(trim($raw['items']), true);
            $raw_items     = is_array($decoded_items) ? $decoded_items : array();
        }

        // —— 文档级键（**仅作兼容与回落**）——
        // TASK-1312：先把提交的文档级键归一，再交给逐行归一作为「行内缺省时的回落值」，
        // 最后按行补齐（下面 fill 段）。存量公文两套键本就各自齐全 ⇒ 值不变（R1）。
        if ($mode === 'branch') {
            $out['term_start'] = zw_doc_appoint_is_date(isset($raw['term_start']) ? $raw['term_start'] : '')
                ? trim((string) $raw['term_start']) : '';
            $out['term_end']   = zw_doc_appoint_is_date(isset($raw['term_end']) ? $raw['term_end'] : '')
                ? trim((string) $raw['term_end']) : '';
        } elseif ($mode === 'hq') {
            $doc_dept = zw_doc_appoint_text(isset($raw['org_dept']) ? $raw['org_dept'] : '', 40);
            $out['org_dept'] = zw_doc_appoint_org_dept_is_allowed($doc_dept) ? $doc_dept : '';
            $doc_duty = zw_doc_appoint_text(isset($raw['org_duty']) ? $raw['org_duty'] : '', 40);
            $out['org_duty'] = zw_doc_appoint_org_duty_is_allowed($doc_duty) ? $doc_duty : '';
            $doc_term = zw_doc_appoint_text(isset($raw['org_term_name']) ? $raw['org_term_name'] : '', 60);
            $out['org_term_name'] = zw_doc_appoint_term_is_allowed($doc_term) ? $doc_term : '';
            $out['org_term_start'] = zw_doc_appoint_is_date(isset($raw['org_term_start']) ? $raw['org_term_start'] : '')
                ? trim((string) $raw['org_term_start']) : '';
            $out['org_term_end']   = zw_doc_appoint_is_date(isset($raw['org_term_end']) ? $raw['org_term_end'] : '')
                ? trim((string) $raw['org_term_end']) : '';
        }

        // TASK-1312：**显式单选模式**为唯一权威（同篇不再混选，构造 POST 塞入对立模式的
        // `dept` 也改不动角色）；未显式提交 mode 时才按行内 `dept` 推导、
        // 未选行回落**文档级 `type`**（TASK-1304 及更早的混选 / 单通道口径零回归，R1）。
        $role_fallback = ($mode_hint !== '') ? $mode_hint : $resolved;
        $items = array();
        foreach ($raw_items as $item) {
            if (count($items) >= zw_doc_appoint_max_items()) {
                break;
            }
            if (!is_array($item) || zw_doc_appoint_item_is_blank($item)) {
                continue;
            }
            $row_role = zw_doc_appoint_item_role($item, $role_fallback);
            if ($mode_hint !== '') {
                $row_role = $mode_hint;
            }
            $row = zw_doc_appoint_normalize_item($item, $row_role, $mode_hint, $out);
            if ($row['name'] === '') {
                continue; // 无姓名无法成文，直接丢弃（校验层另行提示缺人）。
            }
            $items[] = $row;
        }
        $out['items'] = $items;

        if ($mode === 'branch') {
            // 分支通道任期：显式提交优先，否则回落**首个分支行**（存量兼容）。
            if ((string) $out['term_start'] === '' && (string) $out['term_end'] === '') {
                foreach ($items as $branch_row) {
                    if (!is_array($branch_row)) {
                        continue;
                    }
                    if (zw_doc_appoint_item_role($branch_row, $mode) === 'branch'
                        && ((string) (isset($branch_row['term_start']) ? $branch_row['term_start'] : '') !== ''
                            || (string) (isset($branch_row['term_end']) ? $branch_row['term_end'] : '') !== '')) {
                        $out['term_start'] = (string) $branch_row['term_start'];
                        $out['term_end']   = (string) $branch_row['term_end'];
                        break;
                    }
                }
            }
        }

        // TASK-1304 / TASK-1312：文档级键**仅作兼容与回落**（`type`/`mode`/`scope` 语义见上）。
        // 逐人分流后，文档级键取该通道**首个具值行**补齐；存量公文两套键本就各自齐全
        // ⇒ 不新增任何键、值不变 ⇒ 原文哈希不变（R1：幂等标记与同步结果零变化）。
        foreach ($items as $filled_row) {
            if (!is_array($filled_row)) {
                continue;
            }
            // TASK-1312：只从**自带 org_* 数据**的行补齐文档级键；行内为空（值取文档级回落）时
            // 不得用空串覆盖文档级已有值（否则二次 encode 不再幂等）。
            $row_has_org = false;
            foreach (array('org_dept', 'org_duty', 'org_term_name', 'org_term_start', 'org_term_end') as $org_key) {
                if (isset($filled_row[$org_key]) && (string) $filled_row[$org_key] !== '') {
                    $row_has_org = true;
                    break;
                }
            }
            if (zw_doc_appoint_item_role($filled_row, $mode) === 'hq') {
                if (!$row_has_org) {
                    continue;
                }
                foreach (array('org_dept', 'org_duty', 'org_term_name', 'org_term_start', 'org_term_end') as $fill_key) {
                    if ((!isset($out[$fill_key]) || (string) $out[$fill_key] === '')
                        && isset($filled_row[$fill_key]) && (string) $filled_row[$fill_key] !== '') {
                        $out[$fill_key] = (string) $filled_row[$fill_key];
                    }
                }
            } else {
                foreach (array('term_start', 'term_end') as $fill_key) {
                    if ((!isset($out[$fill_key]) || (string) $out[$fill_key] === '')
                        && isset($filled_row[$fill_key]) && (string) $filled_row[$fill_key] !== '') {
                        $out[$fill_key] = (string) $filled_row[$fill_key];
                    }
                }
            }
        }

        $json = zw_doc_appoint_json_encode($out);
        if ($json === '') {
            return zw_doc_appoint_empty_json();
        }

        // JSON 自限：超限时从尾部丢弃人员（不破坏 JSON）。
        $guard = 0;
        while (zw_doc_appoint_text_len($json) > zw_doc_appoint_max_json_chars()
            && !empty($out['items']) && $guard < zw_doc_appoint_max_items() + 1) {
            array_pop($out['items']);
            $json = zw_doc_appoint_json_encode($out);
            if ($json === '') {
                return zw_doc_appoint_empty_json();
            }
            $guard++;
        }

        return $json;
    }
}

if (!function_exists('zw_doc_appoint_decode')) {
    /**
     * 任命要素解码（容错：字符串 / 数组；永远返回受控结构）。
     *
     * TASK-1304：对**缺失 `dept`** 的行做**运行时**推断补上（hq 行 → 协会本级名；
     * branch 行 → 行内 `branch`）—— **不写回磁盘**，故存量公文的存储 JSON、
     * 原文哈希与同步结果逐字节不变（R1）。
     *
     * @param mixed $raw 存储值或原始结构。
     * @return array
     */
    function zw_doc_appoint_decode($raw) {
        $json    = zw_doc_appoint_encode($raw);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return zw_doc_appoint_empty();
        }
        $meta = array_merge(zw_doc_appoint_empty(), $decoded);

        $items = array();
        foreach ((array) $meta['items'] as $item) {
            if (is_array($item) && (!isset($item['dept']) || trim((string) $item['dept']) === '')) {
                // 行内缺省时按**运行时模式**（显式 mode 优先，其次文档级 type）推断视图值；仅内存。
                $inferred = zw_doc_appoint_item_dept_name($item, zw_doc_appoint_mode_of($meta, (string) $meta['type']));
                if ($inferred !== '') {
                    $item['dept'] = $inferred; // 运行时视图，仅内存
                }
            }
            $items[] = $item;
        }
        $meta['items'] = $items;

        return $meta;
    }
}

if (!function_exists('zw_doc_appoint_has_content')) {
    /**
     * 任命要素是否有实质内容（决定是否渲染 / 是否同步）。
     *
     * @param mixed $raw 存储值。
     * @return bool
     */
    function zw_doc_appoint_has_content($raw) {
        $meta = zw_doc_appoint_decode($raw);

        return !empty($meta['items']) || (string) $meta['scope'] !== '';
    }
}

/* ═══════ 政策挂点（TASK-1598 · T5 · 唯一判定出处＝政策规则引擎 `zw_policy_check()`） ═══════ */

if (!function_exists('zw_doc_appoint_policy_gate_on')) {
    /**
     * 是否启用政策挂点（缺省**跟随全局三层开关**，默认 off）。
     *
     * @param array $options 可选：显式 `policy_gate`。
     * @return bool
     */
    function zw_doc_appoint_policy_gate_on($options = array()) {
        $options = is_array($options) ? $options : array();
        if (array_key_exists('policy_gate', $options)) {
            return !empty($options['policy_gate']);
        }

        return function_exists('zw_policy_gate_enabled') ? (bool) zw_policy_gate_enabled('doc.appoint') : false;
    }
}

if (!function_exists('zw_doc_appoint_policy_position')) {
    /**
     * 「任命职务 → 政策场景描述」桥（喂给引擎 `context.position`）。
     *
     * **词汇 100% 派生自注册表**（`zw_policy_age_rules()` 的 `scopes` ⇄
     * `zw_policy_feature_keys()`）：不写死岗位词、**不含阈值数字**（SPEC-93 §关键硬口径 1）。
     * 回落链：本行职务标签 → `branch.leaders` 中文名（分支通道写入的就是分支机构负责人）
     * → `doc.appoint` 中文名。**任命书写入口不另建一套判定**（R-7）。
     *
     * @param array $labels
     * @param array $fallback_features
     * @return string
     */
    function zw_doc_appoint_policy_position($labels, $fallback_features = array('branch.leaders', 'doc.appoint')) {
        $labels = is_array($labels) ? $labels : array($labels);
        $hits   = array();

        if (function_exists('zw_policy_age_rules') && function_exists('zw_policy_age_scope_tokens')) {
            foreach ((array) zw_policy_age_rules() as $rule) {
                foreach (zw_policy_age_scope_tokens(isset($rule['scopes']) ? $rule['scopes'] : array()) as $token) {
                    foreach ($labels as $label) {
                        $label = trim((string) $label);
                        if ($label === '') {
                            continue;
                        }
                        if (mb_stripos($label, $token) !== false
                            || (mb_strlen($label) >= 2 && mb_stripos($token, $label) !== false)) {
                            $hits[$token] = true;
                            continue 3;
                        }
                    }
                }
            }
        }

        if (!$hits && $fallback_features && function_exists('zw_policy_feature_keys')) {
            $keys = zw_policy_feature_keys();
            foreach ((array) $fallback_features as $feature) {
                if (isset($keys[$feature])) {
                    $hits[(string) $keys[$feature]] = true;
                }
            }
        }

        return implode(' ', array_keys($hits));
    }
}

if (!function_exists('zw_doc_appoint_policy_item_labels')) {
    /**
     * 单行任命要素的「岗位 / 场景」标签（分支通道＝会内职务；本级通道＝组织架构职务）。
     *
     * @param array  $item 原始行。
     * @param string $role `zw_doc_appoint_item_role()` 结果（branch|hq|''）。
     * @param array  $raw  文档级结构（本级四件套回落用）。
     * @return array<int,string>
     */
    function zw_doc_appoint_policy_item_labels($item, $role, $raw = array()) {
        $item = is_array($item) ? $item : array();
        $raw  = is_array($raw) ? $raw : array();
        $out  = array();

        if ($role === 'hq') {
            $duty_key = trim((string) (isset($item['org_duty']) ? $item['org_duty'] : ''));
            if ($duty_key === '') {
                $duty_key = trim((string) (isset($raw['org_duty']) ? $raw['org_duty'] : ''));
            }
            $duty_name = function_exists('zw_doc_appoint_org_duty_name') ? (string) zw_doc_appoint_org_duty_name($duty_key) : '';
            $out[]     = $duty_name !== '' ? $duty_name : $duty_key;
        } else {
            $duty = isset($item['duty']) ? (string) $item['duty'] : '';
            $other = isset($item['duty_other']) ? (string) $item['duty_other'] : '';
            $out[] = function_exists('zw_branch_role_label') ? (string) zw_branch_role_label($duty, $other) : $duty;
        }

        return array_values(array_filter($out, function ($one) {
            return trim((string) $one) !== '';
        }));
    }
}

if (!function_exists('zw_doc_appoint_policy_notices')) {
    /**
     * 任命要素的政策**软提示**（警示 / 暂缓；**永不阻断**）。
     *
     * 为什么单独一个通道（工程决定，见 TASK-1598 R4 台账）：
     * `zw_doc_appoint_problems()` 的任何一项都会被 `zw_doc_body_validate()` ⇒
     * `zw_doc_update_body()` 直接 `WP_Error` **硬阻断正文保存**；而本批（B2）
     * 的硬边界是「**不做硬阻断**」（年龄命中只暂缓 + 转人工，R-5）。
     * ⇒ 警示/暂缓码（`appointments_over_age` / `appointments_policy_defer`）走本通道，
     * 阻断码（`appointments_unverified`）走 `zw_doc_appoint_problems()`。
     *
     * @param mixed $raw     原始结构（数组或 JSON 字符串）。
     * @param array $options 可选：`policy_gate`（显式开关）。
     * @return array<int,array<string,mixed>> 每项 `{code,level,message,soft,...}`
     */
    function zw_doc_appoint_policy_notices($raw, $options = array()) {
        $out = array();
        if (is_string($raw)) {
            $raw = trim($raw);
            $raw = $raw === '' ? array() : json_decode($raw, true);
        }
        if (!is_array($raw) || !$raw || !zw_doc_appoint_policy_gate_on($options) || !function_exists('zw_policy_check')) {
            return $out;
        }

        $type = isset($raw['type']) ? trim((string) $raw['type']) : '';
        $mode = function_exists('zw_doc_appoint_mode_of') ? (string) zw_doc_appoint_mode_of($raw, $type) : '';
        $default_as_of = function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d');
        $items = (isset($raw['items']) && is_array($raw['items'])) ? array_values($raw['items']) : array();

        $index = 0;
        $deferred = false;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $index++;
            if (function_exists('zw_doc_appoint_item_is_blank') && zw_doc_appoint_item_is_blank($item)) {
                continue;
            }
            $uid  = 0;
            foreach (array('uid', 'user_id') as $uid_key) {
                if (!empty($item[$uid_key])) {
                    $uid = abs((int) $item[$uid_key]);
                    break;
                }
            }

            // R-8：周岁以**任职生效日**为准（行内任期优先，其次文档级；都无 ⇒ 今日）。
            $as_of = '';
            foreach (array('org_term_start', 'term_start') as $as_key) {
                if (!empty($item[$as_key]) && function_exists('zw_doc_appoint_is_date') && zw_doc_appoint_is_date($item[$as_key])) {
                    $as_of = (string) $item[$as_key];
                    break;
                }
            }
            if ($as_of === '') {
                foreach (array('org_term_start', 'term_start') as $as_key) {
                    if (!empty($raw[$as_key]) && function_exists('zw_doc_appoint_is_date') && zw_doc_appoint_is_date($raw[$as_key])) {
                        $as_of = (string) $raw[$as_key];
                        break;
                    }
                }
            }
            if ($as_of === '') {
                $as_of = $default_as_of;
            }

            $identity = ($uid > 0 && function_exists('zw_branch_user_identity')) ? (array) zw_branch_user_identity($uid) : array();
            $age      = 0;
            if (!empty($identity['birth_date']) && function_exists('zw_control_age_from_birthday')) {
                $calc = zw_control_age_from_birthday($identity['birth_date'], $as_of);
                $age  = ($calc === '' || $calc === false) ? 0 : (int) $calc;
            }
            if ($age <= 0 && !empty($identity['age'])) {
                $age = (int) $identity['age'];
            }

            $role    = function_exists('zw_doc_appoint_item_role') ? (string) zw_doc_appoint_item_role($item, $mode) : '';
            $position = zw_doc_appoint_policy_position(zw_doc_appoint_policy_item_labels($item, $role, $raw));

            $result = (array) zw_policy_check(array(
                'module_key'  => 'doc',
                'feature_key' => 'doc.appoint',
                'position'    => $position,
                'as_of'       => $as_of,
                'fields'      => array(
                    'age'       => $age,
                    'uid'       => $uid,
                    'person_id' => '',
                ),
            ));
            $hits = (isset($result['hits']) && is_array($result['hits'])) ? $result['hits'] : array();
            if (!$hits) {
                continue;
            }

            $deferred = true;
            foreach ($hits as $hit) {
                $clause    = isset($hit['clause']) ? (string) $hit['clause'] : '';
                $threshold = array_key_exists('threshold', (array) $hit) ? $hit['threshold'] : null;
                $out[]     = array(
                    'code'      => 'appointments_over_age',
                    'level'     => isset($hit['level']) ? (string) $hit['level'] : '',
                    'message'   => '第 ' . $index . ' 位任命人员任职年龄命中政策条款'
                        . ($clause !== '' ? '（' . $clause . '）' : '') . '，提交后暂缓并转人事与合规复核。',
                    'soft'      => true,
                    'index'     => $index,
                    'uid'       => $uid,
                    'clause'    => $clause,
                    'threshold' => $threshold,
                    'age'       => isset($hit['age']) ? (int) $hit['age'] : $age,
                    'as_of'     => $as_of,
                );
            }
        }

        if ($deferred) {
            $out[] = array(
                'code'    => 'appointments_policy_defer',
                'level'   => 'defer',
                'message' => '任命要素命中任职年龄政策条款：提交＝暂缓 + 转人事和合规复核（本轮不硬阻断，R-5）。',
                'soft'    => true,
            );
        }

        return $out;
    }
}

if (!function_exists('zw_doc_appoint_policy_unverified_problems')) {
    /**
     * 任命要素的**实名未核验**阻断项（R-3）。
     *
     * **默认不产生**（须 `$options['policy_enforce']` 显式请求）：本函数只能看到提交快照、
     * 看不到存量行，若默认阻断会让「整页回存 / 正文重存」把**存量未编辑的未核验行**一并拦掉，
     * 违反 §5.1 验收③「存量未编辑行不被拦」；R-3 阻断的**真实落点**是两个 canonical 写入口
     * （`zw_branch_leaders_save()` / `zw_org_insert_person()`），那里有存量行可比对。
     *
     * @param mixed $raw
     * @param array $options 可选：`policy_gate` / `policy_enforce`。
     * @return array<int,array<string,mixed>> 每项 `{code,message}`
     */
    function zw_doc_appoint_policy_unverified_problems($raw, $options = array()) {
        $problems = array();
        $options  = is_array($options) ? $options : array();
        if (empty($options['policy_enforce']) || !zw_doc_appoint_policy_gate_on($options)) {
            return $problems;
        }
        if (is_string($raw)) {
            $raw = trim($raw);
            $raw = $raw === '' ? array() : json_decode($raw, true);
        }
        if (!is_array($raw) || !$raw) {
            return $problems;
        }

        $items = (isset($raw['items']) && is_array($raw['items'])) ? array_values($raw['items']) : array();
        $index = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $index++;
            if (function_exists('zw_doc_appoint_item_is_blank') && zw_doc_appoint_item_is_blank($item)) {
                continue;
            }
            $uid = 0;
            foreach (array('uid', 'user_id') as $uid_key) {
                if (!empty($item[$uid_key])) {
                    $uid = abs((int) $item[$uid_key]);
                    break;
                }
            }
            if ($uid <= 0) {
                continue; // 无关联账号的行交由既有结构校验 / 同步链路处置
            }

            $identity = function_exists('zw_branch_user_identity') ? (array) zw_branch_user_identity($uid) : array();
            $verified = !empty($identity['verified']);
            if (!$verified && class_exists('ZW_Realname') && method_exists('ZW_Realname', 'is_verified')) {
                $verified = (bool) ZW_Realname::is_verified($uid); // 铁律 21：实名强度唯一出处
            }
            if ($verified) {
                continue;
            }

            $notice   = function_exists('zw_policy_realname_notice_text') ? (string) zw_policy_realname_notice_text() : '';
            $problems[] = array(
                'code'    => 'appointments_unverified',
                'message' => '第 ' . $index . ' 位任命人员未核验实名，不能新增或变更任命'
                    . ($notice !== '' ? '（' . $notice . '）' : '') . '。',
            );
        }

        return $problems;
    }
}

if (!function_exists('zw_doc_appoint_problems')) {
    /**
     * 任命要素校验（面向用户反馈，不改变数据）。
     *
     * 统一问题码 `appointments_invalid`（与正文模型的问题码风格一致）；
     * TASK-1598 · T5 追加阻断码 `appointments_unverified`（实名未核验，**须显式
     * `$options['policy_enforce']`**；年龄警示/暂缓走软通道 `zw_doc_appoint_policy_notices()`，
     * 本返回值的任何一项都会被 `zw_doc_update_body()` 硬阻断 ⇒ 年龄**不得**放在这里）。
     *
     * @param mixed $raw     原始结构（数组或 JSON 字符串）。
     * @param array $options 可选：`policy_gate` / `policy_enforce`。
     * @return array 每项 array{code,message}；空数组表示通过。
     */
    function zw_doc_appoint_problems($raw, $options = array()) {
        $problems = array();
        $push     = function ($message) use (&$problems) {
            $problems[] = array('code' => 'appointments_invalid', 'message' => (string) $message);
        };

        if ($raw === null || $raw === '' || $raw === false) {
            return $problems;
        }
        if (is_array($raw) && empty($raw)) {
            return $problems;
        }
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                return $problems;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $push('任命配置不是合法的 JSON。');
                return $problems;
            }
            $raw = $decoded;
        }
        if (!is_array($raw)) {
            $push('任命配置结构不合法。');
            return $problems;
        }

        $type = isset($raw['type']) ? trim((string) $raw['type']) : '';
        if (!in_array($type, zw_doc_appoint_type_options(), true)) {
            $push('任命类型不合法（只能是分支机构或协会本级）。');
            return $problems;
        }

        // TASK-1312：模块级模式（服务端权威）决定必填集与流向。
        $mode = zw_doc_appoint_mode_of($raw);
        if ($mode === '') {
            $mode = zw_doc_appoint_mode_of(array(), $type);
        }

        $scope = isset($raw['scope']) ? (string) $raw['scope'] : '';
        if (zw_doc_appoint_text_len($scope) > zw_doc_appoint_scope_limit()) {
            $push('工作范围超过 ' . zw_doc_appoint_scope_limit() . ' 个汉字。');
        }

        $items = isset($raw['items']) && is_array($raw['items']) ? array_values($raw['items']) : array();
        if (count($items) > zw_doc_appoint_max_items()) {
            $push('任命人员超过 ' . zw_doc_appoint_max_items() . ' 人。');
        }

        $effective = array();
        foreach ($items as $item) {
            if (is_array($item) && !zw_doc_appoint_item_is_blank($item)) {
                $effective[] = $item;
            }
        }

        // TASK-1312：总会任命模式**无「工作范围」字段** ⇒ 半成品判定只看人数。
        if (empty($effective) && $mode !== 'hq' && trim($scope) !== '') {
            $push('请至少指定一位任命人员。');
        }

        // 文档级类型未定：不做逐行必填（与改造前一致，避免误伤半成品）。
        if (!in_array($type, array('branch', 'hq'), true)) {
            return $problems;
        }

        if ($mode === 'branch' && !empty($effective) && trim($scope) === '') {
            $push('请填写工作范围。');
        }
        // 文档级任期键（兼容回落用）的日期合法性：存量语义不变。
        foreach (array('term_start' => '开始', 'term_end' => '结束', 'org_term_start' => '开始', 'org_term_end' => '结束') as $key => $label) {
            if (isset($raw[$key]) && trim((string) $raw[$key]) !== '' && !zw_doc_appoint_is_date($raw[$key])) {
                $push('任期' . $label . '日期不合法。');
            }
        }

        // TASK-1312：**模块级模式**决定必填集（不再逐人可选 / 不再混选）。
        // 总会任命：所属部门 / 组织架构职务 / 届次 / 任期（**模块级单值**，行内或文档级取其一）；
        // 分支机构任命：具体分支机构 + 会内职务（逐行）。
        foreach ($effective as $index => $item) {
            $no   = $index + 1;
            $name = trim((string) (isset($item['name']) ? $item['name'] : ''));
            if ($name === '') {
                $push('第 ' . $no . ' 位任命人员缺少姓名。');
                continue;
            }
            if (zw_doc_appoint_item_role($item, $mode) === 'hq') {
                $dept = trim((string) (isset($item['org_dept']) ? $item['org_dept'] : ''));
                if ($dept === '') {
                    $dept = trim((string) (isset($raw['org_dept']) ? $raw['org_dept'] : ''));
                }
                if ($dept === '') {
                    $push('第 ' . $no . ' 位任命人员缺少所属部门。');
                } elseif (!zw_doc_appoint_org_dept_is_allowed($dept)) {
                    $push('第 ' . $no . ' 位任命人员的所属部门不在部门字典内。');
                }
                $duty = trim((string) (isset($item['org_duty']) ? $item['org_duty'] : ''));
                if ($duty === '') {
                    $duty = trim((string) (isset($raw['org_duty']) ? $raw['org_duty'] : ''));
                }
                if ($duty === '') {
                    $push('第 ' . $no . ' 位任命人员缺少组织架构职务。');
                } elseif (!zw_doc_appoint_org_duty_is_allowed($duty)) {
                    $push('第 ' . $no . ' 位任命人员的组织架构职务不在职务字典内。');
                }
                $term = trim((string) (isset($item['org_term_name']) ? $item['org_term_name'] : ''));
                if ($term === '') {
                    $term = trim((string) (isset($raw['org_term_name']) ? $raw['org_term_name'] : ''));
                }
                if ($term === '') {
                    $push('第 ' . $no . ' 位任命人员缺少届次。');
                } elseif (!zw_doc_appoint_term_is_allowed($term)) {
                    $push('第 ' . $no . ' 位任命人员的届次不在届次周期配置内。');
                }
                foreach (array('org_term_start' => '开始', 'org_term_end' => '结束') as $key => $label) {
                    $val = trim((string) (isset($item[$key]) ? $item[$key] : ''));
                    if ($val === '') {
                        $val = trim((string) (isset($raw[$key]) ? $raw[$key] : ''));
                    }
                    if ($val === '') {
                        $push('第 ' . $no . ' 位任命人员缺少任期' . $label . '日期。');
                    } elseif (!zw_doc_appoint_is_date($val)) {
                        $push('第 ' . $no . ' 位任命人员的任期' . $label . '日期不合法。');
                    }
                }
            } else {
                $branch = trim((string) (isset($item['branch']) ? $item['branch'] : ''));
                $dept   = trim((string) (isset($item['dept']) ? $item['dept'] : ''));
                if ($branch === '' && $dept !== '' && !zw_doc_appoint_org_is_hq($dept)) {
                    $branch = $dept;
                }
                if ($branch === '') {
                    $push('第 ' . $no . ' 位任命人员缺少拟任命部门。');
                } elseif (!zw_doc_appoint_branch_is_allowed($branch)) {
                    $push('第 ' . $no . ' 位任命人员的拟任命部门不在名录内。');
                }
                if (trim((string) (isset($item['duty']) ? $item['duty'] : '')) === '') {
                    $push('第 ' . $no . ' 位任命人员缺少会内职务。');
                }
            }
        }

        // TASK-1598 · T5：实名未核验 ⇒ 阻断码（仅 `policy_enforce` 显式请求；年龄警示走软通道）。
        $problems = array_merge($problems, zw_doc_appoint_policy_unverified_problems($raw, $options));

        return $problems;
    }
}

if (!function_exists('zw_doc_appoint_user_name')) {
    /**
     * 按 WP 用户 ID 取任命姓名（服务端权威；优先实名，其次 display_name）。
     *
     * 「任命人员不强制实名，只提示」（用户拍板 Q11）⇒ 无实名时回落显示名；
     * 账号不存在（或隔离单测无 WP 环境）时返回空串，由调用方决定保留原值还是丢弃。
     *
     * @param int $uid WP 用户 ID。
     * @return string
     */
    function zw_doc_appoint_user_name($uid) {
        $uid = abs((int) $uid);
        if ($uid <= 0 || !function_exists('get_userdata')) {
            return '';
        }
        $name = '';
        if (function_exists('zw_branch_user_identity')) {
            $identity = zw_branch_user_identity($uid);
            if (is_array($identity) && isset($identity['name'])) {
                $name = trim((string) $identity['name']);
            }
        }
        if ($name === '' && function_exists('get_userdata')) {
            $user = get_userdata($uid);
            if ($user && isset($user->display_name)) {
                $name = trim((string) $user->display_name);
            }
        }

        return $name;
    }
}

if (!function_exists('zw_doc_appoint_template_patterns')) {
    /**
     * 任命文件四行模板的**句式唯一出处**（PHP 与前端共用同一份文案）。
     *
     * 占位符固定为 `%1$s` / `%2$s` / `%3$s`：PHP 侧由 `sprintf()` 填充
     * （`zw_doc_appoint_template_lines()`），前端由 `wp_json_encode()` 注入本数组后用
     * 同名占位符替换 ⇒ **前端不重复拼字面文案**，改口径只改这一处（防两套模板漂移）。
     *
     * 第 2 行按 type 出 2a / 2b：两套句式**文本同形**、差异只在取值来源
     * （2a 取 `items[].branch` + 会内职务；2b 取 `org_dept` 部门名 + `org_duty` 组织架构职务名），
     * 故 `item` 用同一 pattern、由调用方决定填入哪两个值。
     *
     * @param string $type branch | hq。
     * @return array{type:string,lead:string,item:string,term:string,close:string}
     */
    function zw_doc_appoint_template_patterns($type = 'branch') {
        $type = ($type === 'hq') ? 'hq' : 'branch';
        $ph1  = '%1$s';
        $ph2  = '%2$s';
        $ph3  = '%3$s';

        return array(
            'type'  => $type,
            // TASK-1331（用户口径）：「将段落中引用变量的引号删除，即正文中不应该有『“”』号」
            // ⇒ 正文三行（lead / item / term）**一律不再包裹引号**，变量直接内联。
            // 注意：`zw_doc_appoint_lquote()/rquote()/quote()` 仍保留（其它调用点与既有断言在用），
            // 只是**正文模板不再引用**它们 —— 引号码位（U+201C/U+201D）在正文中**不得出现**。
            'lead'  => '为了弘扬中华优秀传统文化，进一步推进我会' . $ph1
                . '工作的开展，加强组织领导和工作协调，经中国传统文化促进会常务理事会研究决定：',
            'item'  => '任命' . $ph1 . '同志为中国传统文化促进会' . $ph2 . $ph3 . '。',
            'term'  => '任期: ' . $ph1 . '至' . $ph2,
            'close' => '此任命。',
            // 标题模板（多人由调用方用「、」连接后填入 %1$s）。
            // TASK-1325（用户口径）：「公文标题 姓名中的引号删除」⇒ 姓名**不加引号**。
            'title' => '关于' . $ph1 . '同志职务任命的决定',
        );
    }
}

if (!function_exists('zw_doc_appoint_template_lines')) {
    /**
     * 任命文件模板（唯一出处；前端由 PHP 渲染为 JSON 注入，禁止前端重复拼串）。
     *
     * 四行：
     * - 第 1 行（固定 + 工作范围）；
     * - 第 2a 行（type=branch：任命“姓名”同志为中国传统文化促进会“机构”“会内职务”。）
     *   或第 2b 行（type=hq：…“部门名”“组织架构职务名”。）；多人员各一行；
     * - 第 3 行：任期: “开始”至“结束”；
     * - 第 4 行（固定）：此任命。
     *
     * 空工作范围时不生成第 1 行（由录入侧提示必填）。
     *
     * @param mixed $raw 任命要素（存储值或原始结构）。
     * @return string[]
     */
    function zw_doc_appoint_template_lines($raw) {
        $meta     = zw_doc_appoint_decode($raw);
        // TASK-1312：句式按**模块级模式**取（`mode` 缺省回落 `type` ⇒ 存量逐字节不变）。
        $type     = zw_doc_appoint_mode_of($meta, (string) $meta['type']);
        $patterns = zw_doc_appoint_template_patterns($type);
        $lines    = array();

        if ((string) $meta['scope'] !== '') {
            $lines[] = sprintf($patterns['lead'], (string) $meta['scope']);
        }

        foreach ($meta['items'] as $item) {
            if (!is_array($item) || trim((string) (isset($item['name']) ? $item['name'] : '')) === '') {
                continue;
            }
            // TASK-1304：按**行内角色**选句式取值（存量行角色 === 文档级 type ⇒ 逐字节不变）。
            if (zw_doc_appoint_item_role($item, $type) === 'hq') {
                // 2b：所属部门名 + 组织架构职务名（**不做任何职务映射**）；行内缺省回落文档级。
                $dept_key = (isset($item['org_dept']) && (string) $item['org_dept'] !== '')
                    ? (string) $item['org_dept'] : (string) $meta['org_dept'];
                $duty_key = (isset($item['org_duty']) && (string) $item['org_duty'] !== '')
                    ? (string) $item['org_duty'] : (string) $meta['org_duty'];
                $subject = zw_doc_appoint_org_dept_name($dept_key);
                $duty    = zw_doc_appoint_org_duty_name($duty_key);
            } else {
                // 2a：所属分支机构 + 会内职务（`other` 取原文）。
                $subject = isset($item['branch']) ? (string) $item['branch'] : '';
                $duty    = zw_doc_appoint_duty_label(
                    isset($item['duty']) ? $item['duty'] : '',
                    isset($item['duty_other']) ? $item['duty_other'] : ''
                );
            }
            $lines[] = sprintf($patterns['item'], (string) $item['name'], $subject, $duty);
        }

        if ($type === 'hq') {
            $start = isset($meta['org_term_start']) ? (string) $meta['org_term_start'] : '';
            $end   = isset($meta['org_term_end']) ? (string) $meta['org_term_end'] : '';
        } else {
            $start = isset($meta['term_start']) ? (string) $meta['term_start'] : '';
            $end   = isset($meta['term_end']) ? (string) $meta['term_end'] : '';
        }
        // 混选回落：本通道无值而另一通道有值时取另一通道（存量两通道各自齐全 ⇒ 零变化）。
        if ($start === '' && $end === '') {
            if ($type === 'hq') {
                $start = isset($meta['term_start']) ? (string) $meta['term_start'] : '';
                $end   = isset($meta['term_end']) ? (string) $meta['term_end'] : '';
            } else {
                $start = isset($meta['org_term_start']) ? (string) $meta['org_term_start'] : '';
                $end   = isset($meta['org_term_end']) ? (string) $meta['org_term_end'] : '';
            }
        }
        if ($start !== '' || $end !== '') {
            // TASK-1335（用户口径）：正文「任期」行的日期用**中文明文格式且不补零**（例：2026年10月9日），
            // **不再**直接输出 ISO（2026-10-09）。转换复用 canonical `zw_doc_composer_cn_date()`（唯一出处，
            // 它本身就是「公文成文日期：阿拉伯数字、月日不补零」）；helper 缺失或日期不可解析时**原样回退**，
            // 绝不吞数据、不产出空串。前端组行逻辑里的日期格式化函数与本处**同口径**（两处必须一致）。
            $cn = function ($iso) {
                $iso = trim((string) $iso);
                if ($iso === '') {
                    return '';
                }
                if (function_exists('zw_doc_iso_to_cn_date')) {
                    $out = (string) zw_doc_iso_to_cn_date($iso);
                    if ($out !== '') {
                        return $out;
                    }
                }

                return $iso;
            };
            $lines[] = sprintf($patterns['term'], $cn($start), $cn($end));
        }

        $lines[] = $patterns['close'];

        return $lines;
    }
}

if (!function_exists('zw_doc_appoint_title')) {
    /**
     * 标题模板：关于姓名、姓名同志职务任命的决定（多人用「、」连接）。
     * TASK-1325（用户口径）：姓名**不加引号**（其余三行仍带引号）。
     *
     * @param mixed $raw 任命要素。
     * @return string 无人员时返回空串。
     */
    function zw_doc_appoint_title($raw) {
        $meta  = zw_doc_appoint_decode($raw);
        $names = array();
        foreach ($meta['items'] as $item) {
            if (is_array($item) && trim((string) (isset($item['name']) ? $item['name'] : '')) !== '') {
                $names[] = (string) $item['name'];
            }
        }
        if (empty($names)) {
            return '';
        }

        $patterns = zw_doc_appoint_template_patterns('branch');

        return sprintf($patterns['title'], implode('、', $names));
    }
}

if (!function_exists('zw_doc_appoint_join_lines')) {
    /**
     * 模板行 → 正文段落文本（服务端兜底与前端共用同一份行文本）。
     *
     * @param mixed $raw 任命要素。
     * @return string[]
     */
    function zw_doc_appoint_body_texts($raw) {
        return zw_doc_appoint_template_lines($raw);
    }
}

/* ══════════════════════════════════════════════════════════════════════════════
 * 二、同步层（T7）：流转完成 → 分支机构「负责人管理」/ 协会本级「组织架构 → 人员」
 *
 * 硬约束（违反即一票否决）：
 * - 分支通道**唯一写入口** `zw_branch_leaders_save()`；本级通道**唯一写入口**
 *   `zw_org_insert_person()`；本文件内**不出现** `update_post_meta` 写 `zw_branch_leaders`
 *   或 `zw_person_*`（幂等标记与审计写在公文自身的 `_zw_doc_appoint_*` meta 上，属另一域）。
 * - 幂等键 = `meta.appointments` 的**原文哈希**；哈希一致 ⇒ 零副作用直接返回。
 * - 失败**不回滚**公文状态（公文已成立，人员库同步是可重试的运维动作），但必须逐人留痕。
 * - 审计 note **禁止**写入姓名 / 证件 / 手机，只记「机构|部门 ID + 人数 + 结果」。
 * ══════════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_appoint_sync_trigger')) {
    /**
     * 同步触发口径（用户拍板 P0）：`approved`（默认）| `sealed` | `off`。
     *
     * `off` = 功能开关关闭：钩子照常挂载但**零副作用**返回，便于灰度与回滚
     * （回滚预案：置 `off` 即停写，不影响既有公文与已生效人员记录）。
     *
     * @return string
     */
    function zw_doc_appoint_sync_trigger() {
        $value = function_exists('get_option') ? (string) get_option('zw_doc_appoint_sync_trigger', 'approved') : 'approved';
        $value = strtolower(trim($value));
        if (!in_array($value, array('approved', 'sealed', 'off'), true)) {
            $value = 'approved';
        }

        return $value;
    }
}

if (!function_exists('zw_doc_appoint_sync_trigger_label')) {
    /**
     * 触发口径的中文标签（详情页只读回显用）。
     *
     * @return string
     */
    function zw_doc_appoint_sync_trigger_label() {
        $map = array(
            'approved' => '审核通过时同步',
            'sealed'   => '正式用印时同步',
            'off'      => '已关闭（不同步）',
        );
        $trigger = zw_doc_appoint_sync_trigger();

        return isset($map[$trigger]) ? $map[$trigger] : $map['approved'];
    }
}

if (!function_exists('zw_doc_appoint_appoint_meta')) {
    /**
     * 读取某发文的任命要素（受控结构 + 原文哈希 + 人数）。
     *
     * 幂等键必须是**原文**的哈希（与方案 §6.3 第 6 条一致）：任何字段变化都会换键，
     * 内容未变则重复触发不重复写。
     *
     * @param int $doc_id 发文 ID。
     * @return array{raw:string,meta:array,items:array,count:int,hash:string,has_content:bool}
     */
    function zw_doc_appoint_appoint_meta($doc_id) {
        $doc_id = (int) $doc_id;
        $raw    = '';
        if ($doc_id > 0 && function_exists('get_post_meta')) {
            $stored = get_post_meta($doc_id, '_zw_doc_body_json', true);
            if (is_string($stored) && trim($stored) !== '') {
                $decoded = json_decode($stored, true);
                if (is_array($decoded) && isset($decoded['meta']) && is_array($decoded['meta'])
                    && isset($decoded['meta']['appointments'])) {
                    $raw = is_string($decoded['meta']['appointments'])
                        ? $decoded['meta']['appointments']
                        : zw_doc_appoint_json_encode($decoded['meta']['appointments']);
                }
            }
        }

        $json = zw_doc_appoint_encode($raw);
        $meta = zw_doc_appoint_decode($json);

        return array(
            'raw'         => $json,
            'meta'        => $meta,
            'items'       => (array) $meta['items'],
            'count'       => count((array) $meta['items']),
            'hash'        => md5($json),
            'has_content' => zw_doc_appoint_has_content($json),
        );
    }
}

if (!function_exists('zw_doc_appoint_doc_type')) {
    /**
     * 取发文文种（其他文种的**关键隔离点**：必须 === '任' 才继续）。
     *
     * 判据顺序：正文 meta `doc_type` → 文号反推（编辑态/导入态无 body 时的兜底）。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $doc    可选发文详情（减少一次读库）。
     * @return string
     */
    function zw_doc_appoint_doc_type($doc_id, $doc = null) {
        $doc_id = (int) $doc_id;
        $type   = '';
        $body   = (is_array($doc) && isset($doc['body']) && is_array($doc['body'])) ? $doc['body'] : null;

        if ($body === null && $doc_id > 0 && function_exists('get_post_meta') && function_exists('zw_doc_body_parse')) {
            $body = zw_doc_body_parse(get_post_meta($doc_id, '_zw_doc_body_json', true));
        }
        if (is_array($body) && isset($body['meta']['doc_type'])) {
            $type = trim((string) $body['meta']['doc_type']);
        }

        if ($type === '' && $doc_id > 0 && function_exists('zw_doc_get_document')) {
            $number = '';
            if (is_array($doc) && isset($doc['number'])) {
                $number = (string) $doc['number'];
            } else {
                $fresh  = zw_doc_get_document($doc_id);
                $number = is_array($fresh) ? (string) $fresh['number'] : '';
            }
            if ($number !== '') {
                $type = zw_doc_appoint_doc_type_from_number($number);
            }
        }

        return $type;
    }
}

if (!function_exists('zw_doc_appoint_branch_info')) {
    /**
     * 分支机构名 → 机构名录行（`zw_doc_import_branch_lookup()`，与导入链路同源）。
     *
     * 以 `zw_doc_appoint_branch_names()`（本模块渲染所用的同一名录）做前置白名单，
     * 避免把任意标题当日志查询条件；查不到 ID 即计入失败（不猜、不新建机构）。
     *
     * @param string $name 机构名。
     * @return array{id:int,title:string}
     */
    function zw_doc_appoint_branch_info($name) {
        $name = trim((string) $name);
        if ($name === '' || !zw_doc_appoint_branch_is_allowed($name) || !function_exists('zw_doc_import_branch_lookup')) {
            return array('id' => 0, 'title' => '');
        }
        $row = zw_doc_import_branch_lookup($name);
        if (!is_array($row) || empty($row['id'])) {
            return array('id' => 0, 'title' => '');
        }

        return array(
            'id'    => (int) $row['id'],
            'title' => isset($row['title']) && (string) $row['title'] !== '' ? (string) $row['title'] : $name,
        );
    }
}

if (!function_exists('zw_doc_appoint_branch_title_by_id')) {
    /**
     * 由机构 ID 反查机构**名称**（TASK-1386 ⑥：网盘归档目录用名称，非 ID）。
     *
     * 复用 `zw_doc_import_branch_index()`（标题 => 行）反向匹配；查不到返回空串
     * （调用侧据此走目录规则的 fail-closed，绝不静默回退到 `branches/<id>/`）。
     *
     * @param int $branch_id 机构 ID。
     * @return string 机构名称；查不到返回空串。
     */
    function zw_doc_appoint_branch_title_by_id($branch_id) {
        $branch_id = (int) $branch_id;
        if ($branch_id <= 0 || !function_exists('zw_doc_import_branch_index')) {
            return '';
        }
        $index = zw_doc_import_branch_index();
        if (!is_array($index)) {
            return '';
        }
        foreach ($index as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) (isset($row['id']) ? $row['id'] : 0) === $branch_id) {
                return isset($row['title']) ? trim((string) $row['title']) : '';
            }
        }

        return '';
    }
}

if (!function_exists('zw_doc_appoint_doc_hash')) {
    /**
     * 幂等标记值：`sha1(原文哈希|通道)`。
     *
     * 前缀通道，避免「同名发文先分支后本级」时误判为已同步。
     *
     * @param int    $doc_id 发文 ID。
     * @param string $hash   任命要素原文哈希。
     * @param string $type   通道 branch | hq。
     * @return string
     */
    function zw_doc_appoint_doc_hash($doc_id, $hash, $type) {
        return sha1((int) $doc_id . '|' . (string) $hash . '|' . (string) $type);
    }
}

if (!function_exists('zw_doc_appoint_roles_of')) {
    /**
     * 本篇出现的角色集合（**逐行**推导；固定序 `branch` → `hq`，保证标记值稳定）。
     *
     * 无行（空结构/半成品）时回落文档级 `type`，与改造前口径一致。
     *
     * @param array $meta 任命要素。
     * @return string[]
     */
    function zw_doc_appoint_roles_of($meta) {
        $meta  = is_array($meta) ? $meta : array();
        $mode  = zw_doc_appoint_mode_of($meta);
        $type  = isset($meta['type']) ? (string) $meta['type'] : '';
        // TASK-1312：**显式**模块级模式是权威（同篇不再混选）；
        // 由发文机关推导 / 存量 `type` 回落的模式不具跨行权威 ⇒ 仍逐行推导（R1 混选兼容）。
        if (zw_doc_appoint_mode_state($meta) === 'explicit' && in_array($mode, array('branch', 'hq'), true)) {
            return array($mode);
        }
        $items = isset($meta['items']) && is_array($meta['items']) ? $meta['items'] : array();
        $found = array();
        foreach ($items as $item) {
            $role = zw_doc_appoint_item_role($item, $mode);
            if ($role !== '') {
                $found[$role] = true;
            }
        }
        if (empty($found) && in_array($mode, array('branch', 'hq'), true)) {
            $found[$mode] = true;
        }
        $out = array();
        foreach (array('branch', 'hq') as $role) {
            if (isset($found[$role])) {
                $out[] = $role;
            }
        }

        return $out;
    }
}

if (!function_exists('zw_doc_appoint_marker_roles')) {
    /**
     * 幂等标记的角色集合（TASK-1312）：**模块级单选显式选定模式时只按该模式一键计**
     * （不因逐行残留数据分裂）；否则沿用逐行角色集合（存量混选公文零回归，R1）。
     *
     * `zw_doc_appoint_sync()` 与 `zw_doc_appoint_sync_status()` **共用本函数**，
     * 保证「写标记」与「读标记」形态永远一致（否则幂等失效、重复写人员库）。
     *
     * @param array  $meta  任命要素。
     * @param string $roles 可选：`zw_doc_appoint_roles_of()` 的结果（避免重复计算）。
     * @return string[]
     */
    function zw_doc_appoint_marker_roles($meta, $roles = null) {
        $meta  = is_array($meta) ? $meta : array();
        $roles = is_array($roles) ? $roles : zw_doc_appoint_roles_of($meta);
        if (zw_doc_appoint_mode_state($meta) === 'explicit') {
            $mode = zw_doc_appoint_mode_of($meta);
            if (in_array($mode, array('branch', 'hq'), true)) {
                return array($mode);
            }
        }

        return $roles;
    }
}

if (!function_exists('zw_doc_appoint_synced_marker')) {
    /**
     * 逐行幂等标记值（TASK-1304）：角色集合各自的 `sha1(doc|hash|role)`，逗号连接。
     *
     * **兼容旧值形态**：单一角色时与改造前的 `sha1(doc|hash|type)` **逐字节相同**，
     * 故存量公文的幂等标记零变化（R1）；混选公文才有两个键。
     *
     * @param int      $doc_id 发文 ID。
     * @param string   $hash   任命要素原文哈希。
     * @param string[] $roles  角色集合。
     * @return string
     */
    function zw_doc_appoint_synced_marker($doc_id, $hash, $roles) {
        $keys = array();
        foreach ((array) $roles as $role) {
            $role = trim((string) $role);
            if ($role === '') {
                continue;
            }
            $keys[] = zw_doc_appoint_doc_hash($doc_id, $hash, $role);
        }
        $keys = array_values(array_unique($keys));

        return implode(',', $keys);
    }
}

if (!function_exists('zw_doc_appoint_mark_synced')) {
    /**
     * 写幂等标记 + 审计（**仅**在全部成功时调用）。
     *
     * 只写公文自身 meta（`_zw_doc_appoint_synced*`），不触碰分支/组织架构数据。
     *
     * @param int    $doc_id 发文 ID。
     * @param string $hash   任命要素原文哈希。
     * @param string $type   通道角色集合（逗号连接，如 `branch` / `branch,hq`）。
     * @param int    $actor  操作人。
     * @param int    $ok     成功人数。
     * @param string $branch 机构名（仅计入日志；脱敏口径：不加姓名）。
     * @return void
     */
    function zw_doc_appoint_mark_synced($doc_id, $hash, $type, $actor = 0, $ok = 0, $branch = '') {
        $doc_id = (int) $doc_id;
        if ($doc_id <= 0 || !function_exists('update_post_meta')) {
            return;
        }
        $roles = array_values(array_filter(array_map('trim', explode(',', (string) $type))));
        update_post_meta($doc_id, '_zw_doc_appoint_synced', zw_doc_appoint_synced_marker($doc_id, $hash, $roles));
        update_post_meta($doc_id, '_zw_doc_appoint_synced_at', function_exists('current_time') ? current_time('mysql') : '');
        delete_post_meta($doc_id, '_zw_doc_appoint_sync_error');

        if (function_exists('zw_doc_log_event')) {
            // 脱敏：只记「机构 ID + 人数 + 结果」，禁止写姓名 / 证件 / 手机。
            $labels = array();
            foreach ($roles as $role) {
                $labels[] = ($role === 'hq')
                    ? '组织架构·人员'
                    : ('负责人管理·' . ($branch !== '' ? $branch : '机构'));
            }
            $scope_label = empty($labels) ? '任命' : implode(' + ', $labels);
            zw_doc_log_event((int) $doc_id, 'appoint_synced', (int) $actor, '', '', '任命同步完成：' . $scope_label . '｜' . (int) $ok . ' 人');
        }
    }
}

if (!function_exists('zw_doc_appoint_mark_failed')) {
    /**
     * 写失败留痕（不写幂等标记 ⇒ 可无限重试）。
     *
     * @param int    $doc_id   发文 ID。
     * @param array  $problems 逐人原因（面向操作人，可含姓名 —— meta 只有 manage_options 可见，不进审计日志）。
     * @param int    $actor    操作人。
     * @param string $type     通道 branch | hq。
     * @param int    $ok       成功人数。
     * @param int    $pending  待人工人数。
     * @return void
     */
    function zw_doc_appoint_mark_failed($doc_id, $problems, $actor = 0, $type = '', $ok = 0, $pending = 0) {
        $doc_id = (int) $doc_id;
        if ($doc_id <= 0 || !function_exists('update_post_meta')) {
            return;
        }
        delete_post_meta($doc_id, '_zw_doc_appoint_synced');
        update_post_meta($doc_id, '_zw_doc_appoint_sync_error', wp_json_encode(array_values((array) $problems), JSON_UNESCAPED_UNICODE));

        if (function_exists('zw_doc_log_event')) {
            // 脱敏：只记人数，禁止写姓名 / 原因原文。
            zw_doc_log_event(
                (int) $doc_id,
                'appoint_sync_failed',
                (int) $actor,
                '',
                '',
                '任命同步未完成：' . (string) $type . '｜已成功 ' . (int) $ok . ' 人 / 待人工 ' . (int) $pending . ' 人'
            );
        }
    }
}

if (!function_exists('zw_doc_appoint_syncable')) {
    /**
     * 该发文当前是否允许写人员库（触发配置 + 状态 + 用印态）。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $doc    发文详情。
     * @return bool
     */
    function zw_doc_appoint_syncable($doc_id, $doc = null) {
        $trigger = zw_doc_appoint_sync_trigger();
        if ($trigger === 'off') {
            return false; // 功能开关关闭：零副作用
        }
        if (!is_array($doc)) {
            $doc = ($doc_id > 0 && function_exists('zw_doc_get_document')) ? zw_doc_get_document((int) $doc_id) : null;
        }
        if (!is_array($doc)) {
            return false;
        }
        if ($trigger === 'sealed') {
            // 「正式用印」口径：以用印 meta 为准（而不仅看钩子来源），保证手动重试同样成立。
            return (string) $doc['seal_mode'] === 'formal' || (string) $doc['seal_id'] !== '' || !empty($doc['locked']);
        }

        // 默认口径「审核通过」：approved 之后（含已用印）都算成立；驳回/撤回/草稿不写。
        $status = (string) $doc['status'];

        return in_array($status, array('approved'), true)
            || (string) $doc['seal_mode'] === 'formal'
            || !empty($doc['locked']);
    }
}

if (!function_exists('zw_doc_appoint_row_of')) {
    /**
     * 在负责人行中定位某人（优先 `user_id`，其次同身份键）。
     *
     * @param array $rows 负责人行。
     * @param int   $uid  用户 ID。
     * @return array 命中行；未命中返回空数组。
     */
    function zw_doc_appoint_row_of($rows, $uid) {
        $uid = (int) $uid;
        if ($uid <= 0 || !is_array($rows)) {
            return array();
        }
        foreach ($rows as $row) {
            if (is_array($row) && (int) (isset($row['user_id']) ? $row['user_id'] : 0) === $uid) {
                return $row;
            }
        }

        return array();
    }
}

if (!function_exists('zw_doc_appoint_sync_branch')) {
    /**
     * 分支通道：分支机构 → 「分支机构 → 负责人管理」（唯一写入口 `zw_branch_leaders_save()`）。
     *
     * R-b / P7 分档（用户拍板，不得擅自改）：
     * ① 旧职为**主任委员**且新职不是主任委员 ⇒ 压入 `change_action=leave` 结束旧任职，再新增；
     * ② 旧职为**副主任委员** ⇒ 直接新增；
     * ③ 其它职务冲突 ⇒ 跳过该人 + 提示人工到「负责人管理」处理。
     * R-c：新职为主任委员且该人在**其它机构**已是 active 主任委员 ⇒ 跳过 + 提示（不自动免他机构）。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $meta   任命要素。
     * @param int   $actor  操作人。
     * @return array{ok:int,skipped:int,failed:int,warnings:string[],problems:string[]}
     */
    function zw_doc_appoint_sync_branch($doc_id, $meta, $actor = 0) {
        $result = array('ok' => 0, 'skipped' => 0, 'failed' => 0, 'warnings' => array(), 'problems' => array());
        $items  = isset($meta['items']) && is_array($meta['items']) ? $meta['items'] : array();
        // TASK-1304 / TASK-1312：**按角色分流** —— 本通道只处理 branch 行；
        // 模块级模式（`mode`）存在时它是权威回落（TASK-1312 起同一篇不再混选）。
        $doc_type = zw_doc_appoint_mode_of($meta);
        if ($doc_type === '') {
            $doc_type = isset($meta['type']) ? (string) $meta['type'] : '';
        }
        $items = array_values(array_filter($items, function ($item) use ($doc_type) {
            return is_array($item) && zw_doc_appoint_item_role($item, $doc_type) === 'branch';
        }));
        if (empty($items)) {
            return $result;
        }
        if (!function_exists('zw_branch_leaders') || !function_exists('zw_branch_leaders_save') || !function_exists('zw_doc_import_branch_lookup')) {
            $result['failed']   = count($items);
            $result['problems'][] = '分支机构负责人模块或机构名录不可用，无法同步。';

            return $result;
        }

        // 文档级任期为**回落**（存量公文唯一来源）；行内任期优先。
        $term_start = isset($meta['term_start']) ? (string) $meta['term_start'] : '';
        $term_end   = isset($meta['term_end']) ? (string) $meta['term_end'] : '';
        $groups     = array();

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) (isset($item['name']) ? $item['name'] : ''));
            $duty = (string) (isset($item['duty']) ? $item['duty'] : '');
            $uid  = (int) (isset($item['uid']) ? $item['uid'] : 0);
            if ($name === '') {
                continue;
            }
            $duty_other = (string) (isset($item['duty_other']) ? $item['duty_other'] : '');

            $branch = zw_doc_appoint_branch_info(isset($item['branch']) ? $item['branch'] : '');
            if ($branch['id'] <= 0) {
                $result['failed']++;
                $result['problems'][] = $name . '：未在机构名录中匹配到分支机构「' . (string) (isset($item['branch']) ? $item['branch'] : '') . '」。';
                continue;
            }
            $bid = (int) $branch['id'];
            if (!isset($groups[$bid])) {
                $groups[$bid] = array('title' => $branch['title'], 'rows' => zw_branch_leaders($bid), 'new' => array(), 'leaves' => array());
            }

            $hit = zw_doc_appoint_row_of($groups[$bid]['rows'], $uid);
            if (!$hit && $uid <= 0) {
                // 未选账号：按姓名保守比对（仅用于冲突判定；写入仍交由唯一写入口）。
                foreach ($groups[$bid]['rows'] as $row) {
                    if (is_array($row) && (string) ($row['name'] ?? '') === $name) {
                        $hit = $row;
                        break;
                    }
                }
            }

            // R-c：主任委员跨机构唯一 —— 命中即跳过 + 提示，**不自动免掉其它机构**。
            if ($duty === 'chairman') {
                $probe      = $hit ? $hit : array('name' => $name, 'user_id' => $uid, 'role' => 'chairman');
                $probe['role'] = 'chairman';
                $conflict = function_exists('zw_branch_leader_find_other_chairman')
                    ? zw_branch_leader_find_other_chairman($bid, $probe)
                    : false;
                if ($conflict) {
                    $result['skipped']++;
                    $result['warnings'][] = $name . '：该同志已是《' . (string) $conflict['title'] . '》主任委员，需人工处理（不自动免去其它机构任职）。';
                    continue;
                }
            }

            $old_role  = $hit ? (string) ($hit['role'] ?? '') : '';
            $old_pid   = $hit ? (string) ($hit['person_id'] ?? '') : '';

            if ($hit && $old_role === 'chairman' && $duty !== 'chairman') {
                // P7①：先把旧任职落成 leave 指令（同批一次提交；`is_active` 只看 status 与生效区间）。
                $today = function_exists('current_time') ? substr((string) current_time('mysql'), 0, 10) : gmdate('Y-m-d');
                $groups[$bid]['leaves'][] = array(
                    'change_action'        => 'leave',
                    'original_person_id'   => $old_pid,
                    'change_effective_date'=> $today,
                );
                $result['warnings'][] = $name . '：原任「主任委员」已按任命公文免去旧任职，并新任「' . zw_doc_appoint_duty_label($duty, $duty_other) . '」。';
            } elseif ($hit && $old_role !== '' && $old_role !== 'chairman' && $old_role !== 'vice_chairman') {
                // P7③：其它职务冲突 —— 不自动处理。
                $result['skipped']++;
                $result['warnings'][] = $name . '：在《' . $groups[$bid]['title'] . '》已有生效职务「'
                    . zw_doc_appoint_duty_label($old_role, (string) ($hit['role_other'] ?? ''))
                    . '」，需人工到「负责人管理」结束旧任职后再新增。';
                continue;
            }
            // P7②：旧职为副主任委员 ⇒ 直接新增（不结束旧任职）。

            if ($old_pid !== '' && $old_role === $duty && $old_role !== '') {
                // 同人同职重复触发：不重复追加（幂等兜底；主幂等在 _zw_doc_appoint_synced 哈希）。
                $result['ok']++;
                continue;
            }

            // 新增行：person_id 留空让唯一写入口侧生成（`zw_branch_leader_sanitize_row()`），
            // 且保留旧行原字段（含 id_card_enc 密文），只追加不重建。
            // 任期：行内优先（TASK-1304 逐行任期），缺省回落文档级（存量口径不变）。
            $row_term_start = (isset($item['term_start']) && (string) $item['term_start'] !== '')
                ? (string) $item['term_start'] : $term_start;
            $row_term_end = (isset($item['term_end']) && (string) $item['term_end'] !== '')
                ? (string) $item['term_end'] : $term_end;
            $groups[$bid]['new'][] = array(
                'name'      => $name,
                'user_id'   => $uid,
                'role'      => $duty,
                'role_other'=> $duty_other,
                'term_start'=> $row_term_start,
                'term_end'  => $row_term_end,
                'status'    => 'active',
                'source'    => 'manual',
            );
        }

        foreach ($groups as $bid => $group) {
            $rows = is_array($group['rows']) ? $group['rows'] : array();
            if (empty($group['new']) && empty($group['leaves'])) {
                continue;
            }
            $rows = array_merge($rows, $group['leaves'], $group['new']);
            // R-a：任命公文即权威来源 ⇒ 不强制「必须已实名」；未实名只在提交侧提示（选择权交操作人）。
            $saved = zw_branch_leaders_save((int) $bid, $rows, array('require_verified_new_users' => false));
            if (function_exists('is_wp_error') && is_wp_error($saved)) {
                $result['failed'] += count($group['new']);
                $result['problems'][] = $group['title'] . '：负责人库拒写（' . $saved->get_error_message() . '）。';
                continue;
            }
            $result['ok'] += count($group['new']);
        }

        return $result;
    }
}

if (!function_exists('zw_doc_appoint_sync_hq')) {
    /**
     * 本级通道：协会本级 → 「组织架构 → 人员」（唯一写入口 `zw_org_insert_person()`）。
     *
     * R-d：新任「会长」且已有他人担任 ⇒ 跳过 + 提示（不自动免职）；
     * R-e：法定代表人 / 党支部书记为唯一身份开关，不可作职务（预检兜底）；
     * R-f：`dept` 必填且必须命中 `zw_org_departments()` 字典。
     *
     * TASK-1304：**按行分流** —— 本通道只处理 hq 行；部门/职务/届次/任期**逐行**取值，
     * 行内缺省回落文档级（存量公文零变化）。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $meta   任命要素。
     * @param int   $actor  操作人。
     * @return array{ok:int,skipped:int,failed:int,warnings:string[],problems:string[]}
     */
    function zw_doc_appoint_sync_hq($doc_id, $meta, $actor = 0) {
        $result = array('ok' => 0, 'skipped' => 0, 'failed' => 0, 'warnings' => array(), 'problems' => array());
        $items  = isset($meta['items']) && is_array($meta['items']) ? $meta['items'] : array();
        // TASK-1304 / TASK-1312：本通道只处理 hq 行；模块级模式（`mode`）存在时它是权威回落。
        $doc_type = zw_doc_appoint_mode_of($meta);
        if ($doc_type === '') {
            $doc_type = isset($meta['type']) ? (string) $meta['type'] : '';
        }
        $items = array_values(array_filter($items, function ($item) use ($doc_type) {
            return is_array($item) && zw_doc_appoint_item_role($item, $doc_type) === 'hq';
        }));
        if (empty($items)) {
            return $result;
        }
        if (!function_exists('zw_org_insert_person') || !function_exists('zw_org_find_person_by_user')) {
            $result['failed']    = count($items);
            $result['problems'][] = '组织架构人员模块不可用，无法同步。';

            return $result;
        }

        // 文档级回落（存量公文的唯一来源）；逐行可覆盖。
        $meta_dept = sanitize_key((string) (isset($meta['org_dept']) ? $meta['org_dept'] : ''));
        $meta_duty = (string) (isset($meta['org_duty']) ? $meta['org_duty'] : '');
        // 任期默认取届次周期（唯一出处 inc/term-cycles.php），允许公文显式覆盖。
        $term_override  = isset($meta['org_term_name']) ? trim((string) $meta['org_term_name']) : '';
        $default        = zw_doc_appoint_term_dates($term_override);
        $meta_term_name = $term_override !== '' ? $term_override : (string) (isset($default['term']) ? $default['term'] : '');
        $meta_term_start = (string) (isset($meta['org_term_start']) && (string) $meta['org_term_start'] !== ''
            ? $meta['org_term_start']
            : (string) (isset($default['start']) ? $default['start'] : ''));
        $meta_term_end = (string) (isset($meta['org_term_end']) && (string) $meta['org_term_end'] !== ''
            ? $meta['org_term_end']
            : (string) (isset($default['end']) ? $default['end'] : ''));

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) (isset($item['name']) ? $item['name'] : ''));
            $uid  = (int) (isset($item['uid']) ? $item['uid'] : 0);
            if ($name === '') {
                continue;
            }

            // R-f：逐行所属部门（行内优先，回落文档级）。
            $dept = sanitize_key((string) (isset($item['org_dept']) ? $item['org_dept'] : ''));
            if ($dept === '') {
                $dept = $meta_dept;
            }
            if ($dept === '' || !zw_doc_appoint_org_dept_is_allowed($dept)) {
                $result['failed']++;
                $result['problems'][] = $name . '：所属部门未选择或不在组织架构部门字典内，无法同步到「组织架构 → 人员」。';
                continue;
            }

            // 逐行组织架构职务（行内优先，回落文档级）。
            $duty = (string) (isset($item['org_duty']) ? $item['org_duty'] : '');
            if ($duty === '') {
                $duty = $meta_duty;
            }
            if ($duty === '' || !zw_doc_appoint_org_duty_is_allowed($duty)) {
                $result['failed']++;
                $result['problems'][] = $name . '：组织架构职务未选择或不在职务字典内，无法同步到「组织架构 → 人员」。';
                continue;
            }
            $duty_name = zw_doc_appoint_org_duty_name($duty);
            // R-e 兜底：字典本就不含这两项，此处防御历史/脏数据。
            if (in_array($duty_name, array('法定代表人', '党支部书记'), true)) {
                $result['failed']++;
                $result['problems'][] = $name . '：法定代表人 / 党支部书记为唯一身份开关，不能作为职务任命。';
                continue;
            }

            // 逐行届次与任期（行内优先，回落文档级 / 届次周期）。
            $term_name = trim((string) (isset($item['org_term_name']) ? $item['org_term_name'] : ''));
            if ($term_name === '') {
                $term_name = $meta_term_name;
            }
            $term_start = (string) (isset($item['org_term_start']) && (string) $item['org_term_start'] !== ''
                ? $item['org_term_start'] : $meta_term_start);
            $term_end = (string) (isset($item['org_term_end']) && (string) $item['org_term_end'] !== ''
                ? $item['org_term_end'] : $meta_term_end);

            if ($uid <= 0) {
                $result['failed']++;
                $result['problems'][] = $name . '：本级任命必须关联中传网用户账号（用于在组织架构建档与实名回填）。';
                continue;
            }

            // 幂等定位：同部门同用户已建档 ⇒ 复用该 person_id（更新而非新建）。
            $person_id = (int) zw_org_find_person_by_user($uid, $dept);

            // R-d：会长唯一 —— 命中他人即跳过 + 提示，不自动免职。
            $is_president = in_array($duty, array('president', '会长'), true) || $duty_name === '会长';
            if ($is_president && function_exists('zw_org_find_duty_holder')
                && zw_org_find_duty_holder('会长', $person_id) > 0) {
                $result['skipped']++;
                $result['warnings'][] = $name . '：会长已有其他人员担任，需人工处理（不自动免去现任会长）。';
                continue;
            }

            $args = array(
                'person_id'  => $person_id,
                'user_id'    => $uid,
                'name'       => $name,
                'dept'       => $dept,
                'duty'       => $duty,
                'term_name'  => $term_name,
                'term_start' => $term_start,
                'term_end'   => $term_end,
                'source'     => 'user',
            );
            $saved = zw_org_insert_person($args);
            if (function_exists('is_wp_error') && is_wp_error($saved)) {
                $result['failed']++;
                $result['problems'][] = $name . '：组织架构人员库拒写（' . $saved->get_error_message() . '）。';
                continue;
            }
            $result['ok']++;
        }

        return $result;
    }
}

if (!function_exists('zw_doc_appoint_sync')) {
    /**
     * 同步入口（唯一出处）：`zw_doc_flow_completed` / `zw_doc_sealed` / 详情页「重新同步」共用。
     *
     * 六步（方案 §6.3）：状态与触发配置 → 文种必须 === '任' → items 非空 →
     * 幂等哈希 → 按 type 分流 → 全部成功才写幂等标记，否则逐人留痕（**不回滚公文**）。
     *
     * @param int   $doc_id 发文 ID。
     * @param int   $actor  操作人（0 = 当前用户）。
     * @param array $args   可选：`force` = true 表示人工「重新同步」（放宽状态门槛，仍受 trigger=off 阻断）。
     * @return array{status:string,message:string,ok:int,skipped:int,failed:int,warnings:string[],problems:string[]}
     */
    function zw_doc_appoint_sync($doc_id, $actor = 0, $args = array()) {
        $doc_id = (int) $doc_id;
        $actor  = (int) $actor;
        if ($actor <= 0 && function_exists('get_current_user_id')) {
            $actor = (int) get_current_user_id();
        }
        $force  = !empty($args['force']);
        $result = array(
            'status'   => 'skipped',
            'message'  => '',
            'ok'       => 0,
            'skipped'  => 0,
            'failed'   => 0,
            'warnings' => array(),
            'problems' => array(),
        );

        // 1) 触发配置
        if (zw_doc_appoint_sync_trigger() === 'off') {
            $result['message'] = '任命同步已关闭（zw_doc_appoint_sync_trigger=off）。';

            return $result;
        }
        $doc = ($doc_id > 0 && function_exists('zw_doc_get_document')) ? zw_doc_get_document($doc_id) : null;
        if (!is_array($doc)) {
            $result['status']  = 'failed';
            $result['message'] = '发文不存在。';

            return $result;
        }
        // 状态门槛：自动触发按触发口径判定；人工重试（force）只要求「已审核通过或用印」，
        // 避免把「已同步但状态被后续动作改动」的文书锁死。
        $eligible = $force
            ? (in_array((string) $doc['status'], array('approved'), true)
                || (string) $doc['seal_mode'] === 'formal' || !empty($doc['locked']))
            : zw_doc_appoint_syncable($doc_id, $doc);
        if (!$eligible) {
            $result['message'] = '当前状态（' . (string) $doc['status'] . '）与触发口径（' . zw_doc_appoint_sync_trigger_label() . '）不符，未同步。';

            return $result;
        }

        // 2) 文种隔离：必须 === '任'
        $doc_type = zw_doc_appoint_doc_type($doc_id, $doc);
        if (!zw_doc_appoint_is_appoint_type($doc_type)) {
            $result['message'] = '非「任」字文种（' . ($doc_type !== '' ? $doc_type : '未识别') . '），未同步。';

            return $result;
        }

        // 3) 任命要素非空
        $appoint = zw_doc_appoint_appoint_meta($doc_id);
        if ($appoint['count'] <= 0) {
            $result['message'] = '本篇没有任命人员，未同步。';

            return $result;
        }
        $type  = (string) $appoint['meta']['type'];
        // TASK-1312：模块级模式为权威回落（存量无 mode ⇒ 取 type，口径不变）。
        $mode  = zw_doc_appoint_mode_of($appoint['meta'], $type);
        // 4) 角色集合：模块级模式下**恰一个**通道（TASK-1312 起同一篇不再混选）；
        //    存量无 mode 的混选公文仍按行推导（R1）。
        $roles = zw_doc_appoint_roles_of($appoint['meta']);
        if (empty($roles)) {
            $result['status']  = 'failed';
            $result['message'] = '任命类型不合法（只能是分支机构或协会本级）。';

            return $result;
        }
        $role_key = implode(',', $roles);
        // 幂等标记的角色集合（与 `sync_status()` 同源，见 `zw_doc_appoint_marker_roles()`）。
        $marker_roles = zw_doc_appoint_marker_roles($appoint['meta'], $roles);

        // 5) 幂等：幂等标记已存在且等于标记 ⇒ 零副作用返回（不重复写人员库）。
        //    单一角色时标记 = 原 `sha1(doc|hash|type)`，存量公文零变化（R1）。
        $marker   = function_exists('get_post_meta') ? (string) get_post_meta($doc_id, '_zw_doc_appoint_synced', true) : '';
        $expected = zw_doc_appoint_synced_marker($doc_id, $appoint['hash'], $marker_roles);
        if ($marker !== '' && $marker === $expected) {
            $result['status']  = 'synced';
            $result['message'] = '该篇内容已同步过，无需重复写入。';

            return $result;
        }

        // 6) 按角色分流：两条通道各自唯一写入口（存量混选可同时命中；新口径恰一个）。
        $result['ok']       = 0;
        $result['skipped']  = 0;
        $result['failed']   = 0;
        $result['warnings'] = array();
        $result['problems'] = array();
        $branch = '';
        foreach ($roles as $role) {
            $channel = ($role === 'hq')
                ? zw_doc_appoint_sync_hq($doc_id, $appoint['meta'], $actor)
                : zw_doc_appoint_sync_branch($doc_id, $appoint['meta'], $actor);
            $result['ok']       += (int) $channel['ok'];
            $result['skipped']  += (int) $channel['skipped'];
            $result['failed']   += (int) $channel['failed'];
            $result['warnings']  = array_merge($result['warnings'], array_values((array) $channel['warnings']));
            $result['problems']  = array_merge($result['problems'], array_values((array) $channel['problems']));
            if ($role === 'branch' && $branch === '') {
                foreach ($appoint['items'] as $first_item) {
                    if (is_array($first_item) && zw_doc_appoint_item_role($first_item, $mode ? $mode : $type) === 'branch'
                        && !empty($first_item['branch'])) {
                        $branch = (string) $first_item['branch'];
                        break;
                    }
                }
            }
        }

        // 7) 全部成功 ⇒ 写幂等标记 + 审计；部分/全部失败 ⇒ 逐人留痕，不写幂等标记。
        $pending = $result['skipped'] + $result['failed'];
        if ($pending === 0 && $result['ok'] > 0) {
            // TASK-1312：写标记必须用**同一份** `$marker_roles`（否则二次触发算出的 expected
            // 与已写值形态不一致 ⇒ 幂等失效、重复写人员库）。
            zw_doc_appoint_mark_synced($doc_id, $appoint['hash'], implode(',', $marker_roles), $actor, $result['ok'], $branch);
            $result['status']  = 'synced';
            $result['message'] = '任命同步完成（' . (string) $result['ok'] . ' 人）。';

            return $result;
        }
        if ($result['ok'] > 0) {
            $result['status'] = 'partial';
        } elseif ($result['failed'] > 0) {
            $result['status'] = 'failed';
        } else {
            $result['status'] = 'skipped';
        }
        $all = array_merge($result['warnings'], $result['problems']);
        zw_doc_appoint_mark_failed($doc_id, $all, $actor, $role_key, $result['ok'], $pending);
        $result['message'] = '已成功 ' . (string) $result['ok'] . ' 人 / 待人工处理 ' . (string) $pending . ' 人（公文状态不受影响）。';

        return $result;
    }
}

if (!function_exists('zw_doc_appoint_on_flow_completed')) {
    /**
     * `zw_doc_flow_completed` 监听（默认触发口径「审核通过」）。
     *
     * @param int    $doc_id 发文 ID。
     * @param string $status 新状态。
     * @param mixed  $context 上下文。
     * @return void
     */
    function zw_doc_appoint_on_flow_completed($doc_id, $status, $context = array()) {
        if (zw_doc_appoint_sync_trigger() !== 'approved') {
            return; // 切到「正式用印」或「关闭」⇒ 零副作用
        }
        if ((string) $status !== 'approved') {
            return;
        }
        $actor = (is_array($context) && isset($context['actor'])) ? (int) $context['actor'] : 0;
        zw_doc_appoint_sync((int) $doc_id, $actor);
    }
}

if (!function_exists('zw_doc_appoint_on_sealed')) {
    /**
     * `zw_doc_sealed` 监听（触发口径切「正式用印」时生效）。
     *
     * @param int    $doc_id  发文 ID。
     * @param string $seal_id 印章 ID。
     * @param int    $actor   操作人。
     * @return void
     */
    function zw_doc_appoint_on_sealed($doc_id, $seal_id = '', $actor = 0) {
        if (zw_doc_appoint_sync_trigger() !== 'sealed') {
            return; // 默认「审核通过」口径下用印不重复写
        }
        zw_doc_appoint_sync((int) $doc_id, (int) $actor);
    }
}

if (!function_exists('zw_doc_appoint_sync_status')) {
    /**
     * 详情页只读回显数据（已同步 / 未同步 / 失败原因 / 部分成功）。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $appoint 可选 `zw_doc_appoint_appoint_meta()` 结果。
     * @return array{state:string,label:string,detail:string,synced_at:string,problems:string[],ok:int,pending:int}
     */
    function zw_doc_appoint_sync_status($doc_id, $appoint = null) {
        $doc_id  = (int) $doc_id;
        $appoint = is_array($appoint) ? $appoint : zw_doc_appoint_appoint_meta($doc_id);
        $type    = (string) $appoint['meta']['type'];
        $marker  = function_exists('get_post_meta') ? (string) get_post_meta($doc_id, '_zw_doc_appoint_synced', true) : '';
        $at      = function_exists('get_post_meta') ? (string) get_post_meta($doc_id, '_zw_doc_appoint_synced_at', true) : '';
        $errors  = function_exists('get_post_meta') ? get_post_meta($doc_id, '_zw_doc_appoint_sync_error', true) : '';
        $problems = array();
        if (is_string($errors) && $errors !== '') {
            $decoded = json_decode($errors, true);
            if (is_array($decoded)) {
                $problems = array_values(array_map('strval', $decoded));
            }
        } elseif (is_array($errors)) {
            $problems = array_values(array_map('strval', $errors));
        }

        $synced = ($marker !== '' && $marker === zw_doc_appoint_synced_marker($doc_id, $appoint['hash'], zw_doc_appoint_marker_roles($appoint['meta'])));

        if ($synced) {
            return array(
                'state'     => 'synced',
                'label'     => '已同步',
                'detail'    => $at !== '' ? ('同步时间：' . $at) : '',
                'synced_at' => $at,
                'problems' => array(),
                'ok'        => (int) $appoint['count'],
                'pending'   => 0,
            );
        }
        if (!empty($problems)) {
            return array(
                'state'     => 'error',
                'label'     => '同步未完成（部分成功 / 待人工）',
                'detail'    => implode('；', $problems),
                'synced_at' => $at,
                'problems' => $problems,
                'ok'        => 0,
                'pending'   => count($problems),
            );
        }

        return array(
            'state'     => 'none',
            'label'     => '未同步',
            'detail'    => '触发口径：' . zw_doc_appoint_sync_trigger_label(),
            'synced_at' => '',
            'problems' => array(),
            'ok'        => 0,
            'pending'   => (int) $appoint['count'],
        );
    }
}

/* ── 钩子挂载（与 `zw_realname_state_changed` 同型：广播钩子 + 模块自行挂载） ── */
if (function_exists('add_action')) {
    add_action('zw_doc_flow_completed', 'zw_doc_appoint_on_flow_completed', 10, 3);
    add_action('zw_doc_sealed', 'zw_doc_appoint_on_sealed', 10, 3);
}
