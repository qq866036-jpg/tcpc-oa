<?php
/**
 * 收文管理公共助手（TASK-1297 · R1）。
 *
 * 边界（`docs/architecture/TASK-1297-收文管理-表结构变更与迁移回滚方案-20261008.md` §补充 A）：
 * - 函数前缀**一律** `zw_receipt_*`（禁用 `zw_doc_*` / `zw_policy_*` 前缀）；
 * - option 前缀 `zw_receipt_*`；不新增 post meta / user meta；
 * - 本模块只读 `zw_policy_clause` / `zw_policy_document_version`，登记时写 `zw_policy_source`；
 * - 本文件为**纯函数**（无副作用），前后台都可安全加载。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_RECEIPT_MVP_VERSION')) {
    define('ZW_RECEIPT_MVP_VERSION', '2026-10-09.1');
}

if (!function_exists('zw_receipt_is_enabled')) {
    /**
     * 收文管理功能开关 `zw_receipt_mvp_enabled`（默认 **0 = 关闭**）。
     *
     * 关闭时：不加载页面/入口文件、不注册菜单与页面（见 `receipt-bootstrap.php`）。
     * 可用过滤器 `zw_receipt_mvp_enabled` 在开发/预发临时开启。
     *
     * @return bool
     */
    function zw_receipt_is_enabled() {
        $enabled = false;
        if (function_exists('get_option')) {
            $enabled = (bool) get_option('zw_receipt_mvp_enabled', 0);
        }
        if (function_exists('apply_filters')) {
            $enabled = (bool) apply_filters('zw_receipt_mvp_enabled', $enabled);
        }
        return $enabled;
    }
}

if (!function_exists('zw_receipt_capability')) {
    /**
     * 收文管理能力位（Q6/Q14 用户拍板：**复用** `tcpc_perm_doc`，不新增能力位）。
     *
     * 生产已由 `tcpc_admin` / `tcpc_approver` / `tcpc_supervisor` 三个角色持有。
     *
     * @return string
     */
    function zw_receipt_capability() {
        return 'tcpc_perm_doc';
    }
}

if (!function_exists('zw_receipt_menu_slug')) {
    /**
     * 顶级菜单 slug（补充 A.1：`zw-receipt` 父；子页 `zw-receipt-*`）。
     *
     * @return string
     */
    function zw_receipt_menu_slug() {
        return 'zw-receipt';
    }
}

if (!function_exists('zw_receipt_number_counters_table')) {
    /**
     * 收文编号计数器表名（**本模块唯一新增表**，补充 A.1）。
     *
     * @return string
     */
    function zw_receipt_number_counters_table() {
        global $wpdb;
        $prefix = (is_object($wpdb) && isset($wpdb->prefix)) ? (string) $wpdb->prefix : 'wp_';
        return $prefix . 'zw_receipt_number_counters';
    }
}

if (!function_exists('zw_receipt_prepare_sql')) {
    /**
     * 兼容不同 WP 版本的 `$wpdb->prepare` 封装（空参数不调用 prepare）。
     *
     * @param string $sql    SQL 模板。
     * @param array  $params 参数。
     * @return string
     */
    function zw_receipt_prepare_sql($sql, $params = array()) {
        global $wpdb;
        if (empty($params)) {
            return $sql;
        }
        return call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), array_values($params)));
    }
}

if (!function_exists('zw_receipt_origin_incoming')) {
    /**
     * `origin` 取值：外部来文（架构稿 §2.1）。
     *
     * @return string
     */
    function zw_receipt_origin_incoming() {
        return 'incoming';
    }
}

if (!function_exists('zw_receipt_origin_default')) {
    /**
     * `origin` 的安全默认值 `self`（本会自产）。
     *
     * 安全默认方向：漏写只会让收文台账少一行，**绝不**把章程/协议误收进收文视图。
     *
     * @return string
     */
    function zw_receipt_origin_default() {
        return 'self';
    }
}

if (!function_exists('zw_receipt_sanitize_origin')) {
    /**
     * `origin` 白名单归一：仅 `incoming` 视为来文，其余一律落回安全默认 `self`。
     *
     * @param mixed $value 原始值。
     * @return string
     */
    function zw_receipt_sanitize_origin($value) {
        $value = strtolower(trim((string) $value));
        return $value === 'incoming' ? 'incoming' : 'self';
    }
}

if (!function_exists('zw_receipt_number_settings')) {
    /**
     * 收文编号规则（Q5「中传收〔年〕N号」+ Q5b 起始序号可配）。
     *
     * 口径：`prefix` 固定「中传收」、**序号不补零**（`pad = 0`）、起始序号默认 1（可配）。
     *
     * @return array
     */
    function zw_receipt_number_settings() {
        $defaults = array(
            'prefix'              => '中传收',
            'digits'              => 3, // 位数口径仅用于界面提示；格式化**不补零**。
            'pad'                 => 0, // 用户拍板：3 位不补零。
            'start_seq'           => 1, // Q5b：起始序号可配，默认 1。
            'start_seq_overrides' => array(),
            'org_codes'           => array(), // TASK-1306：部委 key → 编号码（下方补默认字典）。
            'per_org_seq'         => true,    // TASK-1306：每部委独立序号域。
        );
        $saved = function_exists('get_option') ? get_option('zw_receipt_number_settings', array()) : array();
        if (!is_array($saved)) {
            $saved = array();
        }
        $settings = array_merge($defaults, $saved);

        $settings['prefix']    = '中传收'; // 用户拍板：前缀固定，不可配置。
        $settings['pad']       = 0;
        $settings['digits']    = max(1, min(6, (int) $settings['digits']));
        $settings['start_seq'] = max(1, (int) $settings['start_seq']);
        if (!is_array($settings['start_seq_overrides'])) {
            $settings['start_seq_overrides'] = array();
        }
        // TASK-1306：部委码与「每部委独立序号域」——存量 option 无这两键时补默认，不破坏 Q5b 语义。
        $default_codes = zw_receipt_number_default_org_codes();
        if (!is_array($settings['org_codes']) || !$settings['org_codes']) {
            $settings['org_codes'] = $default_codes;
        } else {
            $settings['org_codes'] = array_merge($default_codes, $settings['org_codes']);
        }
        $settings['per_org_seq'] = !isset($settings['per_org_seq']) || (bool) $settings['per_org_seq'];

        return $settings;
    }
}

if (!function_exists('zw_receipt_number_start_seq')) {
    /**
     * 某年份的收文自动编号起始序号（可配；支持按年覆盖以避免与线下台账撞号）。
     *
     * @param int $year 年份。
     * @return int
     */
    function zw_receipt_number_start_seq($year = 0) {
        $settings = zw_receipt_number_settings();
        $start    = max(1, (int) $settings['start_seq']);
        $key      = (string) (int) $year;
        if (isset($settings['start_seq_overrides'][$key]) && (int) $settings['start_seq_overrides'][$key] > 0) {
            $start = max($start, (int) $settings['start_seq_overrides'][$key]);
        }
        if (function_exists('apply_filters')) {
            $start = (int) apply_filters('zw_receipt_number_start_seq', $start, (int) $year, $settings);
        }
        return max(1, $start);
    }
}

if (!function_exists('zw_receipt_number_settings_sanitize')) {
    /**
     * **纯函数**：收文编号设置归一（Q5b）。
     *
     * 硬口径：`prefix` 固定「中传收」、`pad` 固定 0（**序号不补零**）—— 用户拍板，不接受外部覆盖。
     * 可配项：`digits`（1~6，仅界面提示）、`start_seq`（≥1）、`start_seq_overrides`（按年 ≥1）。
     *
     * @param array $input 待归一设置。
     * @return array
     */
    function zw_receipt_number_settings_sanitize($input) {
        $input = is_array($input) ? $input : array();
        $out   = array(
            'prefix'              => '中传收',
            'pad'                 => 0,
            'digits'              => 3,
            'start_seq'           => 1,
            'start_seq_overrides' => array(),
            'org_codes'           => zw_receipt_number_default_org_codes(),
            'per_org_seq'         => true,
        );
        if (isset($input['digits'])) {
            $out['digits'] = max(1, min(6, (int) $input['digits']));
        }
        if (isset($input['start_seq'])) {
            $out['start_seq'] = max(1, (int) $input['start_seq']);
        }
        if (isset($input['start_seq_overrides']) && is_array($input['start_seq_overrides'])) {
            $out['start_seq_overrides'] = array();
            foreach ($input['start_seq_overrides'] as $year => $seq) {
                $year = (int) $year;
                $seq  = (int) $seq;
                if ($year >= 1900 && $year <= 2200 && $seq >= 1) {
                    $out['start_seq_overrides'][(string) $year] = $seq;
                }
            }
        }
        // 部委码：只接受**已知部委 key**；码长 1~2（按字符），空白/超长/含分隔符一律丢弃并回退默认。
        $out['org_codes'] = zw_receipt_number_default_org_codes();
        if (isset($input['org_codes']) && is_array($input['org_codes'])) {
            foreach ($input['org_codes'] as $key => $code) {
                $key = function_exists('zw_receipt_org_sanitize_key')
                    ? zw_receipt_org_sanitize_key($key)
                    : strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key));
                if ($key === '' || !function_exists('zw_receipt_org_exists') || !zw_receipt_org_exists($key)) {
                    continue;
                }
                $code = zw_receipt_number_clean_org_code($code);
                if ($code === '') {
                    continue;
                }
                // 同一码不得被两个部委占用（保留先到者，保证「码 → 部委」反查唯一）。
                $existing = array_search($code, $out['org_codes'], true);
                if ($existing !== false && $existing !== $key) {
                    continue;
                }
                $out['org_codes'][$key] = $code;
            }
        }
        if (isset($input['per_org_seq'])) {
            $out['per_org_seq'] = (bool) $input['per_org_seq'];
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_number_default_org_codes')) {
    /**
     * 部委 key → 编号码的**默认字典**（TASK-1306「不同部委可识别的编号规则」）。
     *
     * 码取部委名首字；「其他」用「通」（通用件）。可用过滤器覆盖，管理员也可在设置页逐项改。
     *
     * @return array<string,string>
     */
    function zw_receipt_number_default_org_codes() {
        $map = array(
            'cssw'        => '社', // 中央社会工作部
            'mca'         => '民', // 民政部
            'mct'         => '文', // 文化和旅游部
            'party_upper' => '党', // 上级党组织
            'self_rules'  => '会', // 本会制度
            'other'       => '通', // 其他（通用）
        );
        if (function_exists('apply_filters')) {
            $map = (array) apply_filters('zw_receipt_number_default_org_codes', $map);
        }
        return $map;
    }
}

if (!function_exists('zw_receipt_number_clean_org_code')) {
    /**
     * 部委编号码归一：去空白/控制字符与分隔符，长度 1~2（按字符计）；非法返回空串。
     *
     * @param mixed $code 原始码。
     * @return string
     */
    function zw_receipt_number_clean_org_code($code) {
        $code = (string) $code;
        $code = preg_replace('/[\s\x{2000}-\x{200F}\x{2028}-\x{202F}\x{3000}]+/u', '', $code);
        $code = preg_replace('/[\/\\\\〔〕\[\]\(\)【】<>]/u', '', (string) $code);
        if ($code === '') {
            return '';
        }
        $len = function_exists('mb_strlen') ? mb_strlen($code, 'UTF-8') : strlen($code);
        return ($len >= 1 && $len <= 2) ? $code : '';
    }
}

if (!function_exists('zw_receipt_number_org_code')) {
    /**
     * 取某部委的编号码（未配置返回空串 ⇒ 编号退化为无部委段的全局格式）。
     *
     * @param mixed $org_key 部委 key。
     * @return string
     */
    function zw_receipt_number_org_code($org_key) {
        $settings = zw_receipt_number_settings();
        $key      = function_exists('zw_receipt_org_normalize_key')
            ? zw_receipt_org_normalize_key($org_key)
            : (string) $org_key;
        $codes    = isset($settings['org_codes']) && is_array($settings['org_codes'])
            ? $settings['org_codes']
            : array();
        return isset($codes[$key]) ? zw_receipt_number_clean_org_code($codes[$key]) : '';
    }
}

if (!function_exists('zw_receipt_number_org_from_code')) {
    /**
     * 反查：编号码 → 部委 key（未命中返回空串）。用于编号展示与对账。
     *
     * @param string $code 编号码。
     * @return string
     */
    function zw_receipt_number_org_from_code($code) {
        $code = zw_receipt_number_clean_org_code($code);
        if ($code === '') {
            return '';
        }
        $settings = zw_receipt_number_settings();
        $codes    = isset($settings['org_codes']) && is_array($settings['org_codes'])
            ? $settings['org_codes']
            : array();
        $hit = array_search($code, $codes, true);
        return ($hit === false) ? '' : (string) $hit;
    }
}

if (!function_exists('zw_receipt_number_per_org_seq')) {
    /**
     * 是否启用「每部委独立序号域」（默认 true）。
     *
     * - true ：不同部委各自从起始序号开始独立计数；
     * - false：全部部委共用同一序号域（码只作标识，序号全局唯一）。
     *
     * @return bool
     */
    function zw_receipt_number_per_org_seq() {
        $settings = zw_receipt_number_settings();
        return !isset($settings['per_org_seq']) || (bool) $settings['per_org_seq'];
    }
}

if (!function_exists('zw_receipt_number_save_settings')) {
    /**
     * 写入收文编号设置（唯一写入口；Q5b 可配置入口）。
     *
     * 为什么必须走 canonical：收文编号是最终公文标识，起始序号改错会造成与线下台账撞号；
     * 因此只允许经本函数（归一 + 审计）落库，`start_seq` 只影响**尚未播种的年份**
     * （计数器已存在的年份不受影响，避免已发编号被回收/重复）。
     *
     * @param array $input 设置片段（digits / start_seq / start_seq_overrides）。
     * @return array 归一后并已保存的设置。
     */
    function zw_receipt_number_save_settings($input) {
        $clean = zw_receipt_number_settings_sanitize($input);
        if (function_exists('update_option')) {
            update_option('zw_receipt_number_settings', $clean);
        }
        if (function_exists('zw_receipt_audit')) {
            zw_receipt_audit('receipt_number_settings_save', array(
                'start_seq' => (int) $clean['start_seq'],
                'digits'    => (int) $clean['digits'],
                'years'     => array_keys((array) $clean['start_seq_overrides']),
            ));
        }
        return $clean;
    }
}

if (!function_exists('zw_receipt_number_set_year_start')) {
    /**
     * 为**某一年份**设置起始序号（Q5b 按年覆盖，避免与线下台账撞号）。
     *
     * @param int $year  年份（1900~2200）。
     * @param int $start 起始序号（≥1）。
     * @return array|WP_Error 归一后的设置；非法入参返回错误。
     */
    function zw_receipt_number_set_year_start($year, $start) {
        $year  = (int) $year;
        $start = (int) $start;
        if ($year < 1900 || $year > 2200) {
            return function_exists('zw_receipt_error')
                ? zw_receipt_error('zw_receipt_bad_year', '年份非法（应 1900~2200）。')
                : null;
        }
        if ($start < 1) {
            return function_exists('zw_receipt_error')
                ? zw_receipt_error('zw_receipt_bad_start', '起始序号必须 ≥ 1。')
                : null;
        }
        $current = zw_receipt_number_settings();
        $over    = (array) $current['start_seq_overrides'];
        $over[(string) $year] = $start;
        return zw_receipt_number_save_settings(array(
            'digits'              => (int) $current['digits'],
            'start_seq'           => (int) $current['start_seq'],
            'start_seq_overrides' => $over,
        ));
    }
}

if (!function_exists('zw_receipt_format_number')) {
    /**
     * 生成收文编号（TASK-1306「不同部委可识别的编号规则」）。
     *
     * - 有部委码：`中传收民〔2026〕1号`（民＝民政部；码来自 `zw_receipt_number_org_codes`）
     * - 无部委码：`中传收〔2026〕1号`（**兼容 Q5b 既有全局格式**，也用于未配置码的部委）
     * 序号**不补零**（用户拍板硬口径）。与发文编号域（`中传X〔年〕N号`）互不占用（铁律 I3）。
     *
     * @param int         $year    年份。
     * @param int         $seq     序号。
     * @param string|null $org_key 部委 key（null/空 ⇒ 无部委段；传 `''` 亦同）。
     * @return string
     */
    function zw_receipt_format_number($year, $seq, $org_key = null) {
        $code = '';
        if ($org_key !== null && (string) $org_key !== '' && function_exists('zw_receipt_number_org_code')) {
            $code = zw_receipt_number_org_code($org_key);
        }
        return '中传收' . $code . '〔' . (int) $year . '〕' . (int) $seq . '号';
    }
}

if (!function_exists('zw_receipt_parse_number')) {
    /**
     * 解析收文编号（仅认本域格式；发文号等一律返回 null）。
     *
     * 同时兼容两种形态：
     *   - TASK-1306 新形态：`中传收{部委码}〔年〕N号`（部委码 1~2 字符）⇒ 返回 `org_key`；
     *   - Q5b 旧形态：`中传收〔年〕N号` ⇒ `org_key` 为空串（**不得因新增部委段而拒认历史编号**）。
     *
     * @param string $number 编号。
     * @return array|null array('year'=>int,'seq'=>int,'code'=>string,'org_key'=>string)
     */
    function zw_receipt_parse_number($number) {
        $number = (string) $number;
        if (preg_match('/^中传收([^〔〕]{1,2})〔(\d{4})〕(\d+)号$/u', $number, $matches)) {
            $code = (string) $matches[1];
            return array(
                'year'    => (int) $matches[2],
                'seq'     => (int) $matches[3],
                'code'    => $code,
                'org_key' => function_exists('zw_receipt_number_org_from_code')
                    ? zw_receipt_number_org_from_code($code)
                    : '',
            );
        }
        if (preg_match('/^中传收〔(\d{4})〕(\d+)号$/u', $number, $matches)) {
            return array(
                'year'    => (int) $matches[1],
                'seq'     => (int) $matches[2],
                'code'    => '',
                'org_key' => '',
            );
        }
        return null;
    }
}

if (!function_exists('zw_receipt_normalize_year')) {
    /**
     * 年份归一（非法返回 false）。
     *
     * @param mixed $year    年份。
     * @param mixed $default 非法时返回值。
     * @return int|mixed
     */
    function zw_receipt_normalize_year($year, $default = false) {
        $year = (int) $year;
        return ($year >= 1900 && $year <= 2200) ? $year : $default;
    }
}
