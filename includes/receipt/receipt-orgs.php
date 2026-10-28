<?php
/**
 * 收文管理 · 来文单位（部委）字典（TASK-1297 · R2）。
 *
 * 口径出处：
 * - `docs/research/TASK-1297-收文管理-需求对齐稿-20261008.md` §2 Q3 / §4.4（6 个初始值 + 目录列）；
 * - `docs/architecture/TASK-1297-收文管理-表结构变更与迁移回滚方案-20261008.md` §2.5 物理目录规则；
 * - 同稿 §补充 A.1 唯一前缀约定（`zw_receipt_*`）。
 *
 * 落地形态（需求对齐稿 §4.4 末条）：**代码内 canonical 常量 + 过滤器扩展点**，
 * **不引入自管理 option**（避免组织架构权限模块迁移那种「配置字典失联」）。
 *
 * 本文件为**纯函数**（无副作用、不写库、不写文件），前后台都可安全加载。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_receipt_org_sanitize_key')) {
    /**
     * 部委 key 归一（`sanitize_key()` 口径；无 WP 环境下退化为正则）。
     *
     * @param mixed $key 原始 key。
     * @return string
     */
    function zw_receipt_org_sanitize_key($key) {
        $key = (string) $key;
        if (function_exists('sanitize_key')) {
            return (string) sanitize_key($key);
        }
        return strtolower((string) preg_replace('/[^A-Za-z0-9_\-]/', '', $key));
    }
}

if (!function_exists('zw_receipt_org_clean_label')) {
    /**
     * 显示名归一：既作展示文本、又作目录段名，故必须去掉路径分隔符与穿越片段。
     *
     * @param mixed $label 原始显示名。
     * @return string
     */
    function zw_receipt_org_clean_label($label) {
        $label = (string) $label;
        if (function_exists('sanitize_text_field')) {
            $label = (string) sanitize_text_field($label);
        } else {
            $label = trim(strip_tags($label));
        }
        $label = str_replace(array("\0", "\r", "\n", "\t"), '', $label);
        $label = str_replace(array('/', '\\'), '', $label);
        $label = str_replace('..', '', $label);
        return trim($label);
    }
}

if (!function_exists('zw_receipt_org_canonical')) {
    /**
     * 部委字典 canonical 初始值（Q3 用户拍板，顺序即界面顺序）。
     *
     * key 落库（US-01 验收：选择后落库为 **key**，非显示名）；显示名同时是目录名（§4.4 目录列）。
     *
     * @return array<string,string> key => 显示名
     */
    function zw_receipt_org_canonical() {
        return array(
            'cssw'        => '中央社会工作部',
            'mca'         => '民政部',
            'mct'         => '文化和旅游部',
            'party_upper' => '上级党组织',
            'self_rules'  => '本会制度',
            'other'       => '其他',
        );
    }
}

if (!function_exists('zw_receipt_org_options')) {
    /**
     * 部委字典（canonical 常量 + 过滤器 `zw_receipt_org_options` 扩展点）。
     *
     * 过滤后统一做 key/显示名归一，非法项丢弃 ⇒ 即便扩展点写入脏值也不会污染目录。
     *
     * @return array<string,string> key => 显示名
     */
    function zw_receipt_org_options() {
        $orgs = zw_receipt_org_canonical();
        if (function_exists('apply_filters')) {
            $orgs = apply_filters('zw_receipt_org_options', $orgs);
        }
        $clean = array();
        if (is_array($orgs)) {
            foreach ($orgs as $key => $label) {
                $key   = zw_receipt_org_sanitize_key($key);
                $label = zw_receipt_org_clean_label($label);
                if ($key === '' || $label === '') {
                    continue;
                }
                $clean[$key] = $label;
            }
        }
        return $clean;
    }
}

if (!function_exists('zw_receipt_org_fallback_key')) {
    /**
     * 兜底 key（US-01：「其他」为字典兜底项）。非法/未知 key 一律归到该项。
     *
     * @return string
     */
    function zw_receipt_org_fallback_key() {
        return 'other';
    }
}

if (!function_exists('zw_receipt_org_keys')) {
    /**
     * 全部部委 key。
     *
     * @return string[]
     */
    function zw_receipt_org_keys() {
        return array_keys(zw_receipt_org_options());
    }
}

if (!function_exists('zw_receipt_org_exists')) {
    /**
     * key 是否在字典内。
     *
     * @param mixed $key key。
     * @return bool
     */
    function zw_receipt_org_exists($key) {
        $key = zw_receipt_org_sanitize_key($key);
        return $key !== '' && array_key_exists($key, zw_receipt_org_options());
    }
}

if (!function_exists('zw_receipt_org_label')) {
    /**
     * key -> 显示名（未知返回空串；**不臆造**名称）。
     *
     * @param mixed $key key。
     * @return string
     */
    function zw_receipt_org_label($key) {
        $key     = zw_receipt_org_sanitize_key($key);
        $options = zw_receipt_org_options();
        return isset($options[$key]) ? (string) $options[$key] : '';
    }
}

if (!function_exists('zw_receipt_org_normalize_key')) {
    /**
     * key 白名单归一：未知/非法 → 兜底 key（`other`）。
     *
     * 与 `origin` 的安全默认方向相反：`origin` 宁可少收（默认 `self`），
     * 而部委字典是**必填**字段（US-01），漏选必须落到显式兜底项而不是空值。
     *
     * @param mixed $key key。
     * @return string
     */
    function zw_receipt_org_normalize_key($key) {
        $key = zw_receipt_org_sanitize_key($key);
        return zw_receipt_org_exists($key) ? $key : zw_receipt_org_fallback_key();
    }
}

if (!function_exists('zw_receipt_org_dir_name')) {
    /**
     * 部委目录段名（= 显示名，见需求对齐稿 §4.4 目录列）。
     *
     * @param mixed $key 部委 key。
     * @return string
     */
    function zw_receipt_org_dir_name($key) {
        $label = zw_receipt_org_label(zw_receipt_org_normalize_key($key));
        if ($label === '') {
            // 兜底项被扩展点删除时仍给出「其他」目录名，避免落到空目录段。
            $label = zw_receipt_org_clean_label(zw_receipt_org_canonical()[zw_receipt_org_fallback_key()]);
        }
        return $label;
    }
}

if (!function_exists('zw_receipt_org_key_from_issuer')) {
    /**
     * 来文机关名（`zw_policy_source.issuer`）→ 部委 key 的**反向映射**（TASK-1297 生产实测补强）。
     *
     * 为什么需要：生产存量 `issuer` 是**机关全名**（`民政部` / `中央社会工作部办公厅`），
     * 而目录模板要的是部委 key。没有反查时两件真实外部来文会全部落进「其他/」，
     * 迁移③ dry-run 实测暴露（2026-10-09）。
     *
     * 判据优先级（表内顺序即优先级，先匹配先返回）：
     *   1. 已是合法 key（`mca` / `cssw` …）→ 原样返回（迁移测试 fixture 用的就是 key）；
     *   2. 关键字包含匹配（「中央社会工作部办公厅」含「社会工作部」⇒ `cssw`）；
     *   3. 未命中 → 空串（由调用方决定兜底，**本函数不擅自归到「其他」**）。
     *
     * @param mixed $issuer 来文机关名或部委 key。
     * @return string 部委 key，未命中返回空串。
     */
    function zw_receipt_org_key_from_issuer($issuer) {
        $raw = zw_receipt_org_clean_label($issuer);
        if ($raw === '') {
            return '';
        }
        $key = zw_receipt_org_sanitize_key($raw);
        if ($key !== '' && zw_receipt_org_exists($key)) {
            return $key;
        }
        // 关键字 → key（顺序即优先级；`本会制度` 优先于泛化的「中国传统文化促进会」判定）。
        $table = array(
            '社会工作部'   => 'cssw',
            '中社部'       => 'cssw',
            '民政'         => 'mca',
            '文化和旅游'   => 'mct',
            '文旅'         => 'mct',
            '上级党组织'   => 'party_upper',
            '党组织'       => 'party_upper',
            '本会制度'     => 'self_rules',
            '中国传统文化促进会' => 'self_rules',
        );
        $table = (array) apply_filters('zw_receipt_org_issuer_map', $table);
        foreach ($table as $needle => $target) {
            $needle = (string) $needle;
            if ($needle !== '' && mb_strpos($raw, $needle) !== false && zw_receipt_org_exists((string) $target)) {
                return (string) $target;
            }
        }
        return '';
    }
}

if (!function_exists('zw_receipt_org_is_year_agnostic')) {
    /**
     * 是否**不按年**归档（Q4：本会制度为长期依据，固定目录、不参与年份迁移）。
     *
     * @param mixed $key 部委 key。
     * @return bool
     */
    function zw_receipt_org_is_year_agnostic($key) {
        return zw_receipt_org_sanitize_key($key) === 'self_rules';
    }
}
