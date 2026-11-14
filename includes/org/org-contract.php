<?php
/**
 * 组织架构 · 数据面契约消费层（OA-2500 / TASK-2820）。
 *
 * 唯一允许出现「组织字典 option 名」的插件侧文件。优先走主题契约 P-8：
 *   `zw_oa_org_data_dict_get/save` + `zw_oa_org_data_version()`（>=1）。
 * 主题未升级（旧版无契约）⇒ 回落直接读写既有 option，保持行为不变；
 * 这是过渡回退，**不新增 option**、不改键名，契约就位后回退分支自然不再走。
 *
 * 禁止：本文件之外再写 `get_option('zw_org_*')` / `update_option('zw_org_*')`。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_oa_org_option_map')) {
    /**
     * 键 → option 名（**过渡回退用**；契约在场时不使用）。
     *
     * @return array<string,string>
     */
    function zw_oa_org_option_map() {
        return array(
            'departments' => 'zw_org_departments',
            'duties'      => 'zw_org_duties',
            'roles'       => 'zw_org_roles',
            'data_model'  => 'zw_org_data_model_version',
        );
    }
}

if (!function_exists('zw_oa_org_contract_available')) {
    /**
     * 主题 P-8 契约是否可用（版本 >= 1）。
     *
     * @return bool
     */
    function zw_oa_org_contract_available() {
        if (!function_exists('zw_oa_org_data_version') || !function_exists('zw_oa_org_data_dict_get')) {
            return false;
        }
        return (int) zw_oa_org_data_version() >= 1;
    }
}

if (!function_exists('zw_oa_org_dict_get')) {
    /**
     * 读组织字典。
     *
     * @param string $key      departments|duties|roles|data_model。
     * @param mixed  $fallback 缺省值。
     * @return mixed
     */
    function zw_oa_org_dict_get($key, $fallback = null) {
        $key = (string) $key;
        if (zw_oa_org_contract_available()) {
            $value = zw_oa_org_data_dict_get($key, null);
            return $value === null ? $fallback : $value;
        }
        $map = zw_oa_org_option_map();
        if (!isset($map[$key]) || !function_exists('get_option')) {
            return $fallback;
        }
        return get_option($map[$key], $fallback);
    }
}

if (!function_exists('zw_oa_org_dict_save')) {
    /**
     * 写组织字典（调用方负责规范化）。
     *
     * @param string $key  白名单键。
     * @param array  $rows 行数组。
     * @return bool
     */
    function zw_oa_org_dict_save($key, $rows) {
        $key = (string) $key;
        if (!is_array($rows)) {
            return false;
        }
        if (zw_oa_org_contract_available() && function_exists('zw_oa_org_data_dict_save')) {
            return (bool) zw_oa_org_data_dict_save($key, $rows);
        }
        $map = zw_oa_org_option_map();
        if (!isset($map[$key]) || !function_exists('update_option')) {
            return false;
        }
        return (bool) update_option($map[$key], array_values($rows), false);
    }
}

if (!function_exists('zw_oa_org_data_model_get')) {
    /**
     * 读数据模型版本。
     *
     * @return string
     */
    function zw_oa_org_data_model_get() {
        $v = zw_oa_org_dict_get('data_model', '');
        return is_scalar($v) ? (string) $v : '';
    }
}

if (!function_exists('zw_oa_org_data_model_save')) {
    /**
     * 写数据模型版本（迁移脚本专用）。
     *
     * @param string $version 版本号。
     * @return bool
     */
    function zw_oa_org_data_model_save($version) {
        return zw_oa_org_dict_save('data_model', array((string) $version));
    }
}

if (!function_exists('zw_oa_org_contract_diagnostics')) {
    /**
     * 自检快照（只读）。
     *
     * @return array<string,mixed>
     */
    function zw_oa_org_contract_diagnostics() {
        return array(
            'contract' => zw_oa_org_contract_available(),
            'version'  => function_exists('zw_oa_org_data_version') ? (int) zw_oa_org_data_version() : 0,
            'schema'   => function_exists('zw_oa_org_data_schema_version') ? (string) zw_oa_org_data_schema_version() : '',
        );
    }
}
