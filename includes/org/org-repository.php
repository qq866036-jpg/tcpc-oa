<?php
/**
 * 组织架构 · 数据层
 *
 * 部门与角色采用 option 字典存储；人员复用主题已有的 zw_org_person CPT，
 * 通过 zw_person_org_dept 与分支机构届次人员区分。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (function_exists('is_admin') && !is_admin() && !(defined('WP_CLI') && WP_CLI)) {
    return;
}

// SPEC-85：统一字典与身份证控件（测试直载时按需引导）
if (!function_exists('zw_control_dictionary_options') || !function_exists('zw_control_id_card')) {
    require_once dirname(__DIR__, 2) . '/controls/bootstrap.php';
}


if (!defined('ZW_ORG_DEPARTMENTS_OPTION')) {
    define('ZW_ORG_DEPARTMENTS_OPTION', 'zw_org_departments');
}
if (!defined('ZW_ORG_DUTIES_OPTION')) {
    define('ZW_ORG_DUTIES_OPTION', 'zw_org_duties');
}
if (!defined('ZW_ORG_ROLES_OPTION')) {
    define('ZW_ORG_ROLES_OPTION', 'zw_org_roles');
}
if (!defined('ZW_ORG_DATA_MODEL_OPTION')) {
    define('ZW_ORG_DATA_MODEL_OPTION', 'zw_org_data_model_version');
}
if (!defined('ZW_ORG_DATA_MODEL_VERSION')) {
    define('ZW_ORG_DATA_MODEL_VERSION', '2');
}

if (!function_exists('zw_org_default_departments')) {
    /**
     * 预设部门。
     *
     * @return array
     */
    function zw_org_default_departments() {
        return array(
            array('key' => 'leadership', 'name' => '协会领导', 'order' => 10),
            array('key' => 'secretariat', 'name' => '秘书处', 'order' => 20),
            array('key' => 'office', 'name' => '办公室', 'order' => 30),
            array('key' => 'membership', 'name' => '会籍部', 'order' => 40),
            array('key' => 'standards', 'name' => '标准部', 'order' => 50),
        );
    }
}

if (!function_exists('zw_org_default_duties')) {
    /**
     * 预设职务：由原角色字典迁移而来，法定代表人改为唯一身份开关，不再作为职务。
     *
     * @return array
     */
    function zw_org_default_duties() {
        return array(
            array('key' => 'president', 'name' => '会长', 'order' => 10, 'description' => '协会主要负责人'),
            array('key' => 'vice_president', 'name' => '副会长', 'order' => 20, 'description' => '协助会长工作'),
            array('key' => 'secretary_general', 'name' => '秘书长', 'order' => 30, 'description' => '主持秘书处日常工作'),
            array('key' => 'deputy_secretary_general', 'name' => '副秘书长', 'order' => 40, 'description' => '协助秘书长工作'),
            array('key' => 'director', 'name' => '主任', 'order' => 50, 'description' => '部门负责人'),
            array('key' => 'deputy_director', 'name' => '副主任', 'order' => 60, 'description' => '部门副职'),
            array('key' => 'staff', 'name' => '职员', 'order' => 70, 'description' => '部门工作人员'),
        );
    }
}

if (!function_exists('zw_org_legacy_default_roles')) {
    /**
     * 旧「角色字典」默认值，仅用于 TASK-463 一次性迁移。
     *
     * @return array
     */
    function zw_org_legacy_default_roles() {
        return array(
            array('key' => 'legal_representative', 'name' => '法定代表人', 'order' => 5, 'description' => '依法代表协会'),
            array('key' => 'president', 'name' => '会长', 'order' => 10, 'description' => '协会主要负责人'),
            array('key' => 'vice_president', 'name' => '副会长', 'order' => 20, 'description' => '协助会长工作'),
            array('key' => 'secretary_general', 'name' => '秘书长', 'order' => 30, 'description' => '主持秘书处日常工作'),
            array('key' => 'deputy_secretary_general', 'name' => '副秘书长', 'order' => 40, 'description' => '协助秘书长工作'),
            array('key' => 'director', 'name' => '主任', 'order' => 50, 'description' => '部门负责人'),
            array('key' => 'deputy_director', 'name' => '副主任', 'order' => 60, 'description' => '部门副职'),
            array('key' => 'staff', 'name' => '职员', 'order' => 70, 'description' => '部门工作人员'),
        );
    }
}

if (!function_exists('zw_org_default_roles')) {
    /**
     * 新角色字典默认值：按用户要求保持为空，等待后续权限设计。
     *
     * @return array
     */
    function zw_org_default_roles() {
        return function_exists('zw_org_attachment_roles') ? zw_org_attachment_roles() : array();
    }
}

if (!function_exists('zw_org_filter_legacy_duties')) {
    /**
     * 过滤旧角色字典：法定代表人/党支部书记改为唯一身份开关，不进入职务列表。
     *
     * @param array $rows 旧角色行。
     * @return array
     */
    function zw_org_filter_legacy_duties($rows) {
        $out = array();
        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key  = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
            $name = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';
            if ($key === '' || $name === '') {
                continue;
            }
            if ($key === 'legal_representative' || $name === '法定代表人' || $name === '党支部书记') {
                continue;
            }
            $out[] = array(
                'key'         => $key,
                'name'        => $name,
                'order'       => isset($row['order']) ? (int) $row['order'] : 0,
                'description' => isset($row['description']) ? sanitize_text_field((string) $row['description']) : '',
            );
        }
        return $out;
    }
}

if (!function_exists('zw_org_normalize_dict_row')) {
    /**
     * 标准化单条字典行；角色行额外保留权限模块与 WP 角色。
     *
     * @param array $row 原始行。
     * @return array
     */
    function zw_org_normalize_dict_row($row) {
        $row = is_array($row) ? $row : array();
        $key = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
        $name = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';
        if ($key === '' || $name === '') {
            return array();
        }
        $out = array(
            'key'         => $key,
            'name'        => $name,
            'order'       => isset($row['order']) ? (int) $row['order'] : 0,
            'description' => isset($row['description']) ? sanitize_text_field((string) $row['description']) : '',
        );
        if (!empty($row['wp_role'])) {
            $out['wp_role'] = sanitize_key((string) $row['wp_role']);
        }
        if (isset($row['permissions']) && is_array($row['permissions']) && function_exists('zw_org_all_permission_keys')) {
            $allowed = zw_org_all_permission_keys();
            $permissions = array();
            foreach ($row['permissions'] as $permission) {
                $permission = sanitize_key((string) $permission);
                if ($permission !== '' && in_array($permission, $allowed, true) && !in_array($permission, $permissions, true)) {
                    $permissions[] = $permission;
                }
            }
            $out['permissions'] = $permissions;
        }
        if (isset($row['people']) && is_array($row['people'])) {
            $people = array();
            foreach ($row['people'] as $person_id) {
                $person_id = absint($person_id);
                if ($person_id > 0 && !in_array($person_id, $people, true)) {
                    $people[] = $person_id;
                }
            }
            $out['people'] = $people;
        }
        // TASK-602：纯前端分支机构角色额外保留 scope 与前端能力位。
        if (isset($row['scope'])) {
            $scope = sanitize_key((string) $row['scope']);
            if ($scope !== '') {
                $out['scope'] = $scope;
            }
        }
        if (isset($row['front_caps']) && is_array($row['front_caps']) && function_exists('zw_branch_front_capabilities')) {
            $allowed = array_keys(zw_branch_front_capabilities());
            $front_caps = array();
            foreach ($row['front_caps'] as $front_cap) {
                $front_cap = sanitize_key((string) $front_cap);
                if ($front_cap !== '' && in_array($front_cap, $allowed, true) && !in_array($front_cap, $front_caps, true)) {
                    $front_caps[] = $front_cap;
                }
            }
            $out['front_caps'] = $front_caps;
        }
        return $out;
    }
}

if (!function_exists('zw_org_normalize_rows')) {
    /**
     * 统一字典行的字段并排序。
     *
     * @param array $rows 原始行。
     * @param array $defaults 默认行。
     * @return array
     */
    function zw_org_normalize_rows($rows, $defaults) {
        if (!is_array($rows)) {
            $rows = $defaults;
        }
        $out = array();
        foreach ($rows as $row) {
            $normalized = zw_org_normalize_dict_row($row);
            if (!empty($normalized)) {
                $out[] = $normalized;
            }
        }
        if (empty($out)) {
            foreach ($defaults as $row) {
                $normalized = zw_org_normalize_dict_row($row);
                if (!empty($normalized)) {
                    $out[] = $normalized;
                }
            }
        }
        usort($out, 'zw_org_sort_rows');
        return array_values($out);
    }
}

if (!function_exists('zw_org_sort_rows')) {
    /**
     * 字典排序：order 升序，其次名称。
     *
     * @param array $a 行 A。
     * @param array $b 行 B。
     * @return int
     */
    function zw_org_sort_rows($a, $b) {
        $oa = isset($a['order']) ? (int) $a['order'] : 0;
        $ob = isset($b['order']) ? (int) $b['order'] : 0;
        if ($oa === $ob) {
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        }
        return $oa <=> $ob;
    }
}

if (!function_exists('zw_org_departments')) {
    /**
     * 读取部门字典；首次调用时写入预设值。
     *
     * @return array
     */
    function zw_org_departments() {
        $stored = zw_oa_org_dict_get('departments', null);
        if (!is_array($stored)) {
            $stored = zw_org_default_departments();
            zw_oa_org_dict_save('departments', $stored);
        }
        $rows    = zw_org_normalize_rows($stored, zw_org_default_departments());
        $keys    = array_column($rows, 'key');
        $changed = false;
        foreach (zw_org_default_departments() as $default) {
            if (!in_array($default['key'], $keys, true)) {
                $rows[]  = $default;
                $changed = true;
            }
        }
        if ($changed) {
            usort($rows, 'zw_org_sort_rows');
            zw_oa_org_dict_save('departments', $rows);
        }
        return $rows;
    }
}

if (!function_exists('zw_org_duties')) {
    /**
     * 读取职务字典；旧角色字典会由迁移函数自动转换。
     *
     * @return array
     */
    function zw_org_duties() {
        if (function_exists('zw_org_maybe_migrate_data')) {
            zw_org_maybe_migrate_data();
        }
        $stored = zw_oa_org_dict_get('duties', null);
        if (!is_array($stored)) {
            $legacy = zw_oa_org_dict_get('roles', null);
            if (is_array($legacy) && !empty($legacy)) {
                $stored = zw_org_filter_legacy_duties($legacy);
            } else {
                $stored = zw_org_default_duties();
            }
            zw_oa_org_dict_save('duties', $stored);
        }
        $rows    = zw_org_normalize_rows($stored, zw_org_default_duties());
        $keys    = array_column($rows, 'key');
        $changed = false;
        foreach (zw_org_default_duties() as $default) {
            if (!in_array($default['key'], $keys, true)) {
                $rows[]  = $default;
                $changed = true;
            }
        }
        if ($changed) {
            usort($rows, 'zw_org_sort_rows');
            zw_oa_org_dict_save('duties', $rows);
        }
        return $rows;
    }
}

if (!function_exists('zw_org_roles')) {
    /**
     * 读取新角色字典；按 TASK-463 要求默认保持为空。
     *
     * @return array
     */
    function zw_org_roles() {
        if (function_exists('zw_org_maybe_migrate_data')) {
            zw_org_maybe_migrate_data();
        }
        $stored = zw_oa_org_dict_get('roles', array());
        if (!is_array($stored)) {
            $stored = array();
        }
        return zw_org_normalize_rows($stored, zw_org_default_roles());
    }
}

if (!function_exists('zw_org_save_departments')) {
    /**
     * 保存部门字典。
     *
     * @param array $rows 部门行。
     * @return void
     */
    function zw_org_save_departments($rows) {
        zw_oa_org_dict_save('departments', zw_org_normalize_rows($rows, zw_org_default_departments()));
    }
}

if (!function_exists('zw_org_save_duties')) {
    /**
     * 保存职务字典。
     *
     * @param array $rows 职务行。
     * @return void
     */
    function zw_org_save_duties($rows) {
        zw_oa_org_dict_save('duties', zw_org_normalize_rows($rows, zw_org_default_duties()));
    }
}

if (!function_exists('zw_org_save_roles')) {
    /**
     * 保存新角色字典。
     *
     * @param array $rows 角色行。
     * @return void
     */
    function zw_org_save_roles($rows) {
        zw_oa_org_dict_save('roles', zw_org_normalize_rows($rows, zw_org_default_roles()));
    }
}

if (!function_exists('zw_org_make_key')) {
    /**
     * 生成唯一字典 key。
     *
     * @param string $prefix 前缀。
     * @param string $name 名称。
     * @param array  $existing 已有行。
     * @return string
     */
    function zw_org_make_key($prefix, $name, $existing) {
        $base = sanitize_key($name);
        if ($base === '') {
            $base = substr(md5((string) $name . microtime()), 0, 8);
        }
        $base = sanitize_key($prefix . '_' . $base);
        $keys = array();
        foreach ($existing as $row) {
            if (isset($row['key'])) {
                $keys[] = (string) $row['key'];
            }
        }
        $key = $base;
        $i = 2;
        while (in_array($key, $keys, true)) {
            $key = $base . '_' . $i;
            $i++;
        }
        return $key;
    }
}

if (!function_exists('zw_org_add_department')) {
    /**
     * 新增部门。
     *
     * @param string $name 部门名称。
     * @param int    $order 排序。
     * @return string|WP_Error 新部门 key。
     */
    function zw_org_add_department($name, $order = 0) {
        $name = sanitize_text_field((string) $name);
        if ($name === '') {
            return new WP_Error('zw_org_empty_dept', '部门名称不能为空。');
        }
        $rows = zw_org_departments();
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return new WP_Error('zw_org_duplicate_dept', '该部门已存在。');
            }
        }
        $row = array(
            'key'   => zw_org_make_key('dept', $name, $rows),
            'name'  => $name,
            'order' => (int) $order,
        );
        $rows[] = $row;
        zw_org_save_departments($rows);
        return $row['key'];
    }
}

if (!function_exists('zw_org_add_duty')) {
    /**
     * 新增职务。
     *
     * @param string $name 职务名称。
     * @param int    $order 排序。
     * @param string $description 职务说明。
     * @return string|WP_Error 新职务 key。
     */
    function zw_org_add_duty($name, $order = 0, $description = '') {
        $name = sanitize_text_field((string) $name);
        if ($name === '') {
            return new WP_Error('zw_org_empty_duty', '职务名称不能为空。');
        }
        if (in_array($name, array('法定代表人', '党支部书记'), true)) {
            return new WP_Error('zw_org_reserved_duty', '法定代表人、党支部书记为唯一身份开关，不能作为职务名称。');
        }
        $rows = zw_org_duties();
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return new WP_Error('zw_org_duplicate_duty', '该职务已存在。');
            }
        }
        $row = array(
            'key'         => zw_org_make_key('duty', $name, $rows),
            'name'        => $name,
            'order'       => (int) $order,
            'description' => sanitize_text_field((string) $description),
        );
        $rows[] = $row;
        zw_org_save_duties($rows);
        return $row['key'];
    }
}

if (!function_exists('zw_org_add_role')) {
    /**
     * 新增角色。
     *
     * @param string $name 角色名称。
     * @param int    $order 排序。
     * @param string $description 权限说明。
     * @return string|WP_Error 新角色 key。
     */
    function zw_org_add_role($name, $order = 0, $description = '', $scope = '') {
        $name = sanitize_text_field((string) $name);
        if ($name === '') {
            return new WP_Error('zw_org_empty_role', '角色名称不能为空。');
        }
        $rows = zw_org_roles();
        foreach ($rows as $row) {
            if ($row['name'] === $name) {
                return new WP_Error('zw_org_duplicate_role', '该角色已存在。');
            }
        }
        $key = zw_org_make_key('role', $name, $rows);
        $row = array(
            'key'         => $key,
            'name'        => $name,
            'order'       => (int) $order,
            'description' => sanitize_text_field((string) $description),
        );
        // TASK-2252：后台模块角色**必须**带 wp_role —— 否则三处同步逻辑全部跳过，权限永不生效。
        // 纯前端分支机构角色（scope=branch）按设计不绑 WP 角色，仍留空。
        if ((string) $scope === 'branch') {
            $row['scope'] = 'branch';
        } elseif (function_exists('zw_org_role_wp_role_key')) {
            $row['wp_role'] = zw_org_role_wp_role_key($key);
        }
        $rows[] = $row;
        zw_org_save_roles($rows);
        return $row['key'];
    }
}

if (!function_exists('zw_org_find_department')) {
    /**
     * 查找部门。
     *
     * @param string $key 部门 key。
     * @return array|null
     */
    function zw_org_find_department($key) {
        $key = sanitize_key((string) $key);
        foreach (zw_org_departments() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        return null;
    }
}

if (!function_exists('zw_org_find_role')) {
    /**
     * 按 key 或名称查找角色。
     *
     * @param string $ref key 或名称。
     * @return array|null
     */
    function zw_org_find_role($ref) {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }
        foreach (zw_org_roles() as $row) {
            if ($row['key'] === $ref || $row['name'] === $ref) {
                return $row;
            }
        }
        return null;
    }
}

if (!function_exists('zw_org_find_duty')) {
    /**
     * 按 key 或名称查找职务。
     *
     * @param string $ref key 或名称。
     * @return array|null
     */
    function zw_org_find_duty($ref) {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }
        foreach (zw_org_duties() as $row) {
            if ($row['key'] === $ref || $row['name'] === $ref) {
                return $row;
            }
        }
        return null;
    }
}

if (!function_exists('zw_org_duty_label')) {
    /**
     * 职务显示名称。
     *
     * @param string $ref key 或名称。
     * @return string
     */
    function zw_org_duty_label($ref) {
        $duty = zw_org_find_duty($ref);
        return $duty ? $duty['name'] : trim((string) $ref);
    }
}

if (!function_exists('zw_org_role_label')) {
    /**
     * 角色显示名称。
     *
     * @param string $ref key 或名称。
     * @return string
     */
    function zw_org_role_label($ref) {
        $role = zw_org_find_role($ref);
        return $role ? $role['name'] : trim((string) $ref);
    }
}

if (!function_exists('zw_org_political_options')) {
    /**
     * 政治面貌选项：统一走 SPEC-85 控件字典。
     *
     * @return array
     */
    function zw_org_political_options() {
        return function_exists('zw_control_dictionary_options') ? zw_control_dictionary_options('political') : array();
    }
}

if (!function_exists('zw_org_education_options')) {
    /**
     * 学历选项：统一走 SPEC-85 控件字典。
     *
     * @return array
     */
    function zw_org_education_options() {
        $options = function_exists('zw_control_dictionary_options') ? zw_control_dictionary_options('education') : array();
        return function_exists('apply_filters') ? apply_filters('zw_org_education_options', $options) : $options;
    }
}

if (!function_exists('zw_org_degree_options')) {
    /**
     * 学位选项：统一走 SPEC-85 控件字典。
     *
     * @return array
     */
    function zw_org_degree_options() {
        $options = function_exists('zw_control_dictionary_options') ? zw_control_dictionary_options('degree') : array();
        return function_exists('apply_filters') ? apply_filters('zw_org_degree_options', $options) : $options;
    }
}

if (!function_exists('zw_org_gender_options')) {
    /**
     * 性别选项。
     *
     * @return array
     */
    function zw_org_gender_options() {
        return array(
            'male'   => '男',
            'female' => '女',
        );
    }
}

if (!function_exists('zw_org_gender_label')) {
    /**
     * 性别显示名称。
     *
     * @param string $gender 性别 key。
     * @return string
     */
    function zw_org_gender_label($gender) {
        $options = zw_org_gender_options();
        return isset($options[$gender]) ? $options[$gender] : '';
    }
}

if (!function_exists('zw_org_id_card_info')) {
    /**
     * 解析中国大陆 15/18 位身份证号：委托 SPEC-85 统一解析器。
     *
     * @param string $id_card 身份证号。
     * @return array
     */
    function zw_org_id_card_info($id_card) {
        if (!function_exists('zw_control_id_card')) {
            return array('valid' => false, 'reason' => 'format', 'gender' => '', 'birthday' => '', 'age' => '');
        }
        $info = zw_control_id_card($id_card);
        return array(
            'valid'    => !empty($info['valid']),
            'reason'   => isset($info['reason']) ? (string) $info['reason'] : '',
            'gender'   => !empty($info['valid']) ? ((string) $info['gender'] === '男' ? 'male' : 'female') : '',
            'birthday' => !empty($info['valid']) ? (string) $info['birthday'] : '',
            'age'      => !empty($info['valid']) ? $info['age'] : '',
        );
    }
}

if (!function_exists('zw_org_id_card_any_type_ok')) {
    /**
     * 是否为**任一受支持证件类型**的号码形态（TASK-2118）。
     *
     * 用途：组织人员名单保存时不再只认 15/18 位 —— 香港/澳门居民身份证与两类通行证
     * 无法从号码推导性别/出生日期，但**号码本身是合法的**，应允许录入（性别/生日取表单值）。
     *
     * 唯一出处：委托 `ZW_Realname::valid_id_document_any_type()`（它内部组合 `valid_id_card_format()`，
     * 后者是 SPEC-85 的唯一形态判定）——本函数**只做委托**，不得复制第二套正则。
     *
     * @param string $id_card 证件号。
     * @return bool
     */
    function zw_org_id_card_any_type_ok($id_card) {
        if (!class_exists('ZW_Realname') || !method_exists('ZW_Realname', 'valid_id_document_any_type')) {
            return false;   // fail-closed：canonical 不可用时不放宽（旧行为继续拦非 18 位）
        }
        return (bool) ZW_Realname::valid_id_document_any_type((string) $id_card);
    }
}

if (!function_exists('zw_org_age_from_birthday')) {
    /**
     * 按出生日期计算周岁：委托 SPEC-85 统一实现。
     *
     * @param string $birthday 出生日期 Y-m-d。
     * @param string $today    参考日期。
     * @return int|string
     */
    function zw_org_age_from_birthday($birthday, $today = '') {
        return function_exists('zw_control_age_from_birthday') ? zw_control_age_from_birthday($birthday, $today) : '';
    }
}

if (!function_exists('zw_org_mask_id_card')) {
    /**
     * 身份证号脱敏：委托 SPEC-85 统一实现。
     *
     * @param string $id_card 身份证号。
     * @return string
     */
    function zw_org_mask_id_card($id_card) {
        return function_exists('zw_control_mask_id_card') ? zw_control_mask_id_card($id_card) : '';
    }
}

if (!function_exists('zw_org_person_meta')) {
    /**
     * 读取主组织人员扩展字段。
     *
     * @param int $person_id 人员 ID。
     * @return array
     */
    function zw_org_person_meta($person_id) {
        $person_id = (int) $person_id;
        if ($person_id <= 0) {
            return array();
        }
        // TASK-804：透明读取（兼容历史明文与密文）
        $id_card  = function_exists('zw_idcard_read')
            ? (string) zw_idcard_read($person_id, 'zw_person_id_card')
            : (string) get_post_meta($person_id, 'zw_person_id_card', true);
        $birthday = (string) get_post_meta($person_id, 'zw_person_birthday', true);
        return array(
            'id_card'        => $id_card,
            'id_card_masked' => zw_org_mask_id_card($id_card),
            'political'      => (string) get_post_meta($person_id, 'zw_person_political', true),
            'education'      => (string) get_post_meta($person_id, 'zw_person_education', true),
            'degree'         => (string) get_post_meta($person_id, 'zw_person_degree', true),
            'duty'           => (string) get_post_meta($person_id, 'zw_person_duty', true),
            'company'        => (string) get_post_meta($person_id, 'zw_person_company', true),
            'company_duty'   => (string) get_post_meta($person_id, 'zw_person_company_duty', true),
            'phone'          => (string) get_post_meta($person_id, 'zw_person_phone', true),
            'email'          => (string) get_post_meta($person_id, 'zw_person_email', true),
            'address'        => (string) get_post_meta($person_id, 'zw_person_address', true),
            'gender'         => (string) get_post_meta($person_id, 'zw_person_gender', true),
            'gender_label'   => zw_org_gender_label((string) get_post_meta($person_id, 'zw_person_gender', true)),
            'birthday'       => $birthday,
            'age'            => zw_org_age_from_birthday($birthday),
            'remark'         => (string) get_post_meta($person_id, 'zw_person_remark', true),
            'photo_file_id'  => (int) get_post_meta($person_id, 'zw_person_photo_file_id', true),
            'role'           => (string) get_post_meta($person_id, 'zw_person_org_role', true),
            'legal_representative' => (int) get_post_meta($person_id, 'zw_person_legal_representative', true),
            'party_secretary'       => (int) get_post_meta($person_id, 'zw_person_party_secretary', true),
            'duty_legacy'          => (string) get_post_meta($person_id, 'zw_person_duty_legacy', true),
            // TASK-1291：组织架构人员任期三键（届次 / 任期开始 / 任期结束），
            // 缺失时由 zw_org_person_term() 回退届次周期默认值（只读呈现，不写库）。
            'term_name'      => (string) get_post_meta($person_id, 'zw_person_term_name', true),
            'term_start'     => (string) get_post_meta($person_id, 'zw_person_term_start', true),
            'term_end'       => (string) get_post_meta($person_id, 'zw_person_term_end', true),
            'term_days_left' => zw_org_term_days_left($person_id),
        );
    }
}

if (!function_exists('zw_org_term_default_dates')) {
    /**
     * 组织架构人员任期的届次默认起止（TASK-1291）。
     *
     * **薄封装**：委托全站届次唯一出处 `inc/term-cycles.php` 的 `zw_term_cycle_dates()`，
     * 本文件不再自建第 2 个读取器（禁止直接读写 option `zw_term_cycles`）。
     *
     * @param string $term 届次名；空则取当前基准届次（列表首项）。
     * @return array{term:string,start:string,end:string}
     */
    function zw_org_term_default_dates($term = '') {
        $dates = function_exists('zw_term_cycle_dates') ? zw_term_cycle_dates($term) : array();

        return array(
            'term'  => isset($dates['term']) ? (string) $dates['term'] : '',
            'start' => isset($dates['start']) ? (string) $dates['start'] : '',
            'end'   => isset($dates['end']) ? (string) $dates['end'] : '',
        );
    }
}

if (!function_exists('zw_org_person_term')) {
    /**
     * 读取组织架构人员的任期（TASK-1291，只读）。
     *
     * 三个 meta（`zw_person_term_name` / `zw_person_term_start` / `zw_person_term_end`）
     * 缺失或非法时**回退到届次周期默认值**（默认届次 + 该届起止），便于页面直接展示；
     * 本函数**不写库**，是否回填由页面/回填脚本显式决定。
     *
     * @param int $person_id 人员 ID。
     * @return array{term_name:string,term_start:string,term_end:string,term_source:string}
     */
    function zw_org_person_term($person_id) {
        $person_id = (int) $person_id;
        $is_date   = function_exists('zw_term_cycle_is_date') ? 'zw_term_cycle_is_date' : 'zw_org_is_date_value';

        if ($person_id <= 0) {
            $default = zw_org_term_default_dates();

            return array(
                'term_name'   => $default['term'],
                'term_start'  => $default['start'],
                'term_end'    => $default['end'],
                'term_source' => 'default',
            );
        }

        $name  = trim((string) get_post_meta($person_id, 'zw_person_term_name', true));
        $start = trim((string) get_post_meta($person_id, 'zw_person_term_start', true));
        $end   = trim((string) get_post_meta($person_id, 'zw_person_term_end', true));
        $source = ($name !== '' || $start !== '' || $end !== '') ? 'stored' : 'default';

        if ($name === '') {
            $name = zw_org_term_default_dates()['term'];
        }
        $default = zw_org_term_default_dates($name);
        if ($start === '' || !call_user_func($is_date, $start)) {
            $start = $default['start'];
        }
        if ($end === '' || !call_user_func($is_date, $end)) {
            $end = $default['end'];
        }

        return array(
            'term_name'   => $name,
            'term_start'  => $start,
            'term_end'    => $end,
            'term_source' => $source,
        );
    }
}

if (!function_exists('zw_org_is_date_value')) {
    /**
     * `Y-m-d` 合法日期兜底判定（`zw_term_cycle_is_date()` 不可用时）。
     *
     * @param mixed $value 待判定值。
     * @return bool
     */
    function zw_org_is_date_value($value) {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}

if (!function_exists('zw_org_term_days_left')) {
    /**
     * 任期剩余天数（用于列表展示；无结束日返回 0）。
     *
     * @param int $person_id 人员 ID。
     * @return int
     */
    function zw_org_term_days_left($person_id) {
        $term = zw_org_person_term($person_id);
        if ($term['term_end'] === '' || !zw_org_is_date_value($term['term_end'])) {
            return 0;
        }
        $end   = strtotime($term['term_end'] . ' 00:00:00');
        $today = function_exists('current_time') ? (int) current_time('timestamp') : time();
        if (!$end) {
            return 0;
        }
        $days = (int) floor(($end - $today) / 86400);

        return $days > 0 ? $days : 0;
    }
}

if (!function_exists('zw_org_department_label')) {
    /**
     * 部门显示名称。
     *
     * @param string $key 部门 key。
     * @return string
     */
    function zw_org_department_label($key) {
        $dept = zw_org_find_department($key);
        return $dept ? $dept['name'] : trim((string) $key);
    }
}

if (!function_exists('zw_org_people')) {
    /**
     * 主组织人员列表。
     *
     * @param string $dept_key 部门 key；为空表示全部。
     * @return WP_Post[]
     */
    function zw_org_people($dept_key = '') {
        $meta_query = array(
            array(
                'key'     => 'zw_person_org_dept',
                'compare' => 'EXISTS',
            ),
        );
        $dept_key = sanitize_key((string) $dept_key);
        if ($dept_key !== '') {
            $meta_query[] = array(
                'key'   => 'zw_person_org_dept',
                'value' => $dept_key,
            );
        }
        $posts = get_posts(array(
            'post_type'      => 'zw_org_person',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => $meta_query,
            'orderby'        => array('menu_order' => 'ASC', 'title' => 'ASC'),
        ));
        return is_array($posts) ? $posts : array();
    }
}

if (!function_exists('zw_org_people_count')) {
    /**
     * 人员总数。
     *
     * @return int
     */
    function zw_org_people_count() {
        return count(zw_org_people());
    }
}

if (!function_exists('zw_org_find_person_by_meta_values')) {
    /**
     * 按一个元字段的多个候选值查找人员。
     *
     * @param string $meta_key 元字段。
     * @param array  $values   候选值。
     * @param int    $exclude_id 排除人员。
     * @return int
     */
    function zw_org_find_person_by_meta_values($meta_key, $values, $exclude_id = 0) {
        $meta_key = sanitize_key((string) $meta_key);
        $values   = array_values(array_filter(array_map('strval', (array) $values), 'strlen'));
        if ($meta_key === '' || empty($values)) {
            return 0;
        }
        $args = array(
            'post_type'      => 'zw_org_person',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => $meta_key,
                    'value'   => $values,
                    'compare' => 'IN',
                ),
                array(
                    'key'     => 'zw_person_org_dept',
                    'compare' => 'EXISTS',
                ),
            ),
        );
        $exclude_id = (int) $exclude_id;
        if ($exclude_id > 0) {
            $args['post__not_in'] = array($exclude_id);
        }
        $posts = get_posts($args);
        return !empty($posts) ? (int) $posts[0] : 0;
    }
}

if (!function_exists('zw_org_find_unique_identity_holder')) {
    /**
     * 查找唯一身份当前持有人。
     *
     * @param string $meta_key  身份元字段。
     * @param int    $exclude_id 排除人员。
     * @return int
     */
    function zw_org_find_unique_identity_holder($meta_key, $exclude_id = 0) {
        return zw_org_find_person_by_meta_values($meta_key, array('1'), $exclude_id);
    }
}

if (!function_exists('zw_org_find_duty_holder')) {
    /**
     * 查找某职务当前持有人。
     *
     * @param string $duty_ref 职务 key 或名称。
     * @param int    $exclude_id 排除人员。
     * @return int
     */
    function zw_org_find_duty_holder($duty_ref, $exclude_id = 0) {
        $duty = zw_org_find_duty($duty_ref);
        $values = $duty ? array($duty['name'], $duty['key']) : array(trim((string) $duty_ref));
        return zw_org_find_person_by_meta_values('zw_person_duty', $values, $exclude_id);
    }
}

if (!function_exists('zw_org_people_count_by_role')) {
    /**
     * 按角色统计人数。
     *
     * @return array role_key => count
     */
    function zw_org_people_count_by_role() {
        $counts = array();
        foreach (zw_org_people() as $person) {
            $role = (string) get_post_meta($person->ID, 'zw_person_org_role', true);
            if ($role === '') {
                $role = '__none__';
            }
            if (!isset($counts[$role])) {
                $counts[$role] = 0;
            }
            $counts[$role]++;
        }
        return $counts;
    }
}

if (!function_exists('zw_org_people_count_by_duty')) {
    /**
     * 按职务统计人数。
     *
     * @return array duty_value => count
     */
    function zw_org_people_count_by_duty() {
        $counts = array();
        foreach (zw_org_people() as $person) {
            $duty = (string) get_post_meta($person->ID, 'zw_person_duty', true);
            if ($duty === '') {
                $duty = '__none__';
            }
            if (!isset($counts[$duty])) {
                $counts[$duty] = 0;
            }
            $counts[$duty]++;
        }
        return $counts;
    }
}

if (!function_exists('zw_org_find_person_by_user')) {
    /**
     * 查找某部门下已关联的用户。
     *
     * @param int    $user_id  用户 ID。
     * @param string $dept_key 部门 key。
     * @return int
     */
    function zw_org_find_person_by_user($user_id, $dept_key) {
        $user_id  = (int) $user_id;
        $dept_key = sanitize_key((string) $dept_key);
        if ($user_id <= 0 || $dept_key === '') {
            return 0;
        }
        $posts = get_posts(array(
            'post_type'      => 'zw_org_person',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                array('key' => 'zw_person_org_dept', 'value' => $dept_key),
                array('key' => 'zw_person_user_id', 'value' => (string) $user_id),
            ),
        ));
        return !empty($posts) ? (int) $posts[0] : 0;
    }
}

if (!function_exists('zw_org_person_user_identity')) {
    /**
     * 读取某个 WP 用户的实名身份信息。
     *
     * 复用分支机构负责人模块已有的 canonical 读取器 `zw_branch_user_identity()`，
     * 不复制第二套身份解析；读取器缺失时返回空值（不臆造身份）。
     *
     * @param int $user_id 用户 ID。
     * @return array {name:string,id_card:string,mobile:string,verified:bool}
     */
    function zw_org_person_user_identity($user_id) {
        $user_id = (int) $user_id;
        $empty   = array('name' => '', 'id_card' => '', 'mobile' => '', 'verified' => false);
        if ($user_id <= 0) {
            return $empty;
        }
        if (function_exists('zw_branch_user_identity')) {
            $identity = zw_branch_user_identity($user_id);
            if (is_array($identity)) {
                return array_merge($empty, array_intersect_key($identity, $empty));
            }
        }
        return $empty;
    }
}

if (!function_exists('zw_org_person_user_conflict')) {
    /**
     * 查找某个 WP 用户已关联的其他组织人员。
     *
     * 组织人员与 WP 账号是一对一：同一 UID 若已绑定其他人员，再次绑定会让
     * 角色同步（按 UID 授予 WP 角色）落到错误的人身上，故一律视为冲突。
     *
     * @param int $user_id    用户 ID。
     * @param int $exclude_id 排除的人员 ID（允许本人重复保存）。
     * @return int 冲突人员 ID；无冲突为 0。
     */
    function zw_org_person_user_conflict($user_id, $exclude_id = 0) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }
        return (int) zw_org_find_person_by_meta_values('zw_person_user_id', array((string) $user_id), $exclude_id);
    }
}

if (!function_exists('zw_org_link_person_user')) {
    /**
     * 把 WP 用户（UID）关联到已有组织人员。
     *
     * 只写 `zw_person_user_id`，不改动人员其他字段与来源；不改变
     * `zw_org_insert_person()` 既有的「user_id=0 表示保留原值」语义。
     *
     * 拒绝条件：
     * - 人员不存在或不属于组织架构；
     * - 用户不存在；
     * - 该 UID 已绑定其他人员；
     * - 人员与用户的身份证号都有效且不一致（绑错人会直接导致越权）。
     *
     * @param int $person_id 人员 ID。
     * @param int $user_id   用户 ID。
     * @return array|WP_Error {person_id:int,user_id:int}
     */
    function zw_org_link_person_user($person_id, $user_id) {
        $person_id = (int) $person_id;
        $user_id   = (int) $user_id;
        if ($person_id <= 0 || $user_id <= 0) {
            return new WP_Error('zw_org_link_invalid', '请选择要关联的人员与用户。');
        }
        $person = get_post($person_id);
        if (!$person || $person->post_type !== 'zw_org_person' || (string) get_post_meta($person_id, 'zw_person_org_dept', true) === '') {
            return new WP_Error('zw_org_link_invalid_person', '人员不存在或不属于组织架构。');
        }
        if (!get_userdata($user_id)) {
            return new WP_Error('zw_org_link_invalid_user', '用户不存在。');
        }
        $conflict_id = zw_org_person_user_conflict($user_id, $person_id);
        if ($conflict_id > 0) {
            return new WP_Error(
                'zw_org_user_already_linked',
                sprintf('该用户已关联人员「%s」（#%d），请先解除原关联。', get_the_title($conflict_id), $conflict_id)
            );
        }
        $zw_person_raw = function_exists('zw_idcard_read')
            ? (string) zw_idcard_read($person_id, 'zw_person_id_card')
            : (string) get_post_meta($person_id, 'zw_person_id_card', true);
        $person_id_card = strtoupper((string) preg_replace('/\s+/', '', $zw_person_raw));
        $identity       = zw_org_person_user_identity($user_id);
        $user_id_card   = strtoupper((string) preg_replace('/\s+/', '', (string) (isset($identity['id_card']) ? $identity['id_card'] : '')));
        if (preg_match('/^\d{17}[\dX]$/', $person_id_card) && preg_match('/^\d{17}[\dX]$/', $user_id_card) && $person_id_card !== $user_id_card) {
            return new WP_Error('zw_org_user_id_card_mismatch', '所选用户实名身份证号与该人员已填身份证号不一致，请确认后再关联。');
        }
        zw_oa_org_person_meta_set($person_id, 'zw_person_user_id', $user_id);
        return array('person_id' => $person_id, 'user_id' => $user_id);
    }
}

if (!function_exists('zw_org_unlink_person_user')) {
    /**
     * 解除组织人员与 WP 用户的关联（UID 置 0）。
     *
     * @param int $person_id 人员 ID。
     * @return array|WP_Error {person_id:int,user_id:int} user_id 为解除前的 UID。
     */
    function zw_org_unlink_person_user($person_id) {
        $person_id = (int) $person_id;
        if ($person_id <= 0) {
            return new WP_Error('zw_org_unlink_invalid', '未指定要解除关联的人员。');
        }
        $person = get_post($person_id);
        if (!$person || $person->post_type !== 'zw_org_person' || (string) get_post_meta($person_id, 'zw_person_org_dept', true) === '') {
            return new WP_Error('zw_org_link_invalid_person', '人员不存在或不属于组织架构。');
        }
        $before = (int) get_post_meta($person_id, 'zw_person_user_id', true);
        if ($before <= 0) {
            return new WP_Error('zw_org_user_not_linked', '该人员当前未关联用户。');
        }
        zw_oa_org_person_meta_set($person_id, 'zw_person_user_id', 0);
        return array('person_id' => $person_id, 'user_id' => $before);
    }
}

if (!function_exists('zw_org_user_link_owner_map')) {
    /**
     * UID → 已关联人员（#ID + 姓名）映射。
     *
     * @return array<int,array{id:int,name:string}>
     */
    function zw_org_user_link_owner_map() {
        $map = array();
        foreach (zw_org_people() as $person) {
            $uid = (int) get_post_meta($person->ID, 'zw_person_user_id', true);
            if ($uid > 0 && !isset($map[$uid])) {
                $map[$uid] = array('id' => (int) $person->ID, 'name' => (string) $person->post_title);
            }
        }
        return $map;
    }
}

if (!function_exists('zw_org_person_user_option_label')) {
    /**
     * 「关联用户」候选项标签（页面与服务端搜索共用，禁止两套口径）。
     *
     * @param object $user       用户对象（含 display_name / user_login / ID）。
     * @param string $real_name  实名姓名，空串表示未实名。
     * @param int    $owner_id   已关联人员 ID，0 表示未占用。
     * @param string $owner_name 已关联人员姓名。
     * @return string
     */
    function zw_org_person_user_option_label($user, $real_name = '', $owner_id = 0, $owner_name = '') {
        $uid   = is_object($user) && isset($user->ID) ? (int) $user->ID : 0;
        $label = (is_object($user) && isset($user->display_name) ? $user->display_name : ('#' . $uid));
        if (is_object($user) && isset($user->user_login) && $user->user_login !== '') {
            $label .= '（' . $user->user_login . '）';
        }
        $label .= ' #' . $uid;
        $real_name = trim((string) $real_name);
        $label    .= $real_name !== '' ? ' · 实名：' . $real_name : ' · 未实名';
        $owner_id   = (int) $owner_id;
        if ($owner_id > 0) {
            $label .= ' · 已关联：' . (string) $owner_name . ' #' . $owner_id;
        }
        return $label;
    }
}

if (!function_exists('zw_org_search_users_for_link')) {
    /**
     * 「关联用户」服务端搜索（TASK-598）。
     *
     * 覆盖 UID / 用户名 / 邮箱 / 昵称 / 显示名 / 实名（`zw_real_name`）/ 手机号（`shouji`），
     * 返回 canonical search-select 选项数组。生产 1336 个用户不能整表预载进页面，
     * 因此候选一律按关键词现查（见 CHG-067）。
     *
     * @param string $keyword 关键词（≥2 字才检索）。
     * @param int    $limit   返回条数上限（默认 30，最大 50）。
     * @return array<int,array> 选项数组：value / label / search / disabled / attrs。
     */
    function zw_org_search_users_for_link($keyword, $limit = 30) {
        $keyword = trim((string) $keyword);
        $limit   = max(1, min(50, (int) $limit));
        $length  = function_exists('mb_strlen') ? mb_strlen($keyword, 'UTF-8') : strlen($keyword);
        if ($length < 2) {
            return array();
        }

        $fields = array('ID', 'display_name', 'user_login', 'user_email');
        $args   = array(
            'number'  => $limit,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => $fields,
        );

        $found = array();

        // 1) UID 精确命中优先。
        if (ctype_digit($keyword)) {
            $by_id = get_userdata((int) $keyword);
            if ($by_id) {
                $found[(int) $by_id->ID] = $by_id;
            }
        }

        // 2) 核心字段模糊（用户名 / 邮箱 / 昵称 / 显示名）。
        $core = get_users(array_merge($args, array(
            'search'         => '*' . $keyword . '*',
            'search_columns' => array('user_login', 'user_email', 'user_nicename', 'display_name'),
        )));
        foreach ((array) $core as $user) {
            $found[(int) $user->ID] = $user;
        }

        // 3) 实名与手机号（用户元数据）。
        $meta = get_users(array_merge($args, array(
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => 'zw_real_name', 'value' => $keyword, 'compare' => 'LIKE'),
                array('key' => 'shouji', 'value' => $keyword, 'compare' => 'LIKE'),
            ),
        )));
        foreach ((array) $meta as $user) {
            $found[(int) $user->ID] = $user;
        }

        if (empty($found)) {
            return array();
        }

        $ids = array_keys($found);
        if (function_exists('update_meta_cache')) {
            // 一次性预加载用户元数据，避免逐个用户查库（只读）。
            update_meta_cache('user', $ids);
        }
        $owner_map = zw_org_user_link_owner_map();

        $options = array();
        foreach ($found as $uid => $user) {
            if (count($options) >= $limit) {
                break;
            }
            $real_name  = function_exists('get_user_meta') ? trim((string) get_user_meta($uid, 'zw_real_name', true)) : '';
            $phone      = function_exists('get_user_meta') ? trim((string) get_user_meta($uid, 'shouji', true)) : '';
            $owner_id   = isset($owner_map[$uid]) ? (int) $owner_map[$uid]['id'] : 0;
            $owner_name = $owner_id > 0 ? (string) $owner_map[$uid]['name'] : '';
            $email      = isset($user->user_email) ? (string) $user->user_email : '';
            $options[]  = array(
                // 远程端点必须回 SPEC-85 canonical 紧凑键（v/l/s/d/a），与 `inc/controls/search-select.php`
                // 的 `{success:true,data:{options:[{v,l,s,d,a}]}}` 契约一致（TASK-604 修正 TASK-598 的长键口径）。
                'v' => (string) $uid,
                'l' => zw_org_person_user_option_label($user, $real_name, $owner_id, $owner_name),
                // 远程结果不再由前端二次过滤，search 文案只留展示型标识，不回带邮箱/手机号（最小化个人信息暴露）。
                's' => $user->display_name . ' ' . $user->user_login . ' ' . $uid . ' ' . $real_name . ' ' . $owner_name,
                'd' => $owner_id > 0 ? 1 : 0,
                'a' => array('data-owner' => (string) $owner_id),
            );
        }
        return $options;
    }
}

/* ═══════ 政策挂点（TASK-1598 · T5 · 唯一判定出处＝政策规则引擎 `zw_policy_check()`） ═══════ */

if (!function_exists('zw_org_policy_gate_on')) {
    /**
     * 是否启用政策挂点（缺省**跟随全局三层开关**，默认 off）。
     *
     * 调用方可显式传 `$args['policy_gate']`（true/false）覆盖；**4 个既有调用点签名不变**。
     *
     * @param array $args          人员参数（`wp_parse_args` 前/后均可）。
     * @param array $provided_keys 调用方**显式提供**的键（`zw_org_insert_person()` 已收集）。
     * @return bool
     */
    function zw_org_policy_gate_on($args, $provided_keys = array()) {
        $provided_keys = is_array($provided_keys) ? $provided_keys : array();
        $args          = is_array($args) ? $args : array();
        if (in_array('policy_gate', $provided_keys, true)) {
            return !empty($args['policy_gate']);
        }

        return function_exists('zw_policy_gate_enabled') ? (bool) zw_policy_gate_enabled('office.people') : false;
    }
}

if (!function_exists('zw_org_policy_position')) {
    /**
     * 「组织架构职务 → 政策场景描述」桥（喂给引擎 `context.position`）。
     *
     * **词汇 100% 派生自注册表**（`zw_policy_age_rules()` 的 `scopes` ⇄
     * `zw_policy_feature_keys()`）：不写死岗位词、**不含阈值数字**（SPEC-93 §关键硬口径 1）；
     * 未命中任何场景 ⇒ 回落 `office.people` 中文名；仍未命中 ⇒ 引擎按「未标注职务」不触发。
     *
     * @param array $labels
     * @param array $fallback_features
     * @return string
     */
    function zw_org_policy_position($labels, $fallback_features = array('office.people')) {
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

if (!function_exists('zw_org_policy_row_changed')) {
    /**
     * 组织架构人员行是否**被编辑**（R-3「写入动作时点」判据）。
     *
     * 口径：
     *  - `person_id <= 0` ⇒ 新增 ⇒ 变更；
     *  - 只比**调用方显式提供**的键（未提供 ⇒ 保持原值 ⇒ 不算变更）；
     *  - 非身份关键键（任期 / 联系方式 / 学历等）**存量为空时不比** ——
     *    避免「首次补全」被误判为编辑，从而误拦「存量未编辑行」；
     *  - 姓名 / 部门 / 职务 / 关联用户 四个任命关键键按严格比对。
     *
     * @param int   $person_id
     * @param array $effective     已清洗的生效值。
     * @param array $provided_keys 显式提供的键。
     * @return bool
     */
    function zw_org_policy_row_changed($person_id, $effective, $provided_keys = array()) {
        $person_id = (int) $person_id;
        if ($person_id <= 0) {
            return true;
        }
        if (!function_exists('get_post_meta')) {
            return true; // 读不到存量 ⇒ 保守按变更处理
        }
        $provided_keys = is_array($provided_keys) ? $provided_keys : array();
        $effective     = is_array($effective) ? $effective : array();
        $post          = function_exists('get_post') ? get_post($person_id) : null;
        $title         = ($post && isset($post->post_title)) ? (string) $post->post_title : '';

        $has = function ($key) use ($provided_keys) {
            return in_array($key, $provided_keys, true);
        };
        $meta = function ($key) use ($person_id) {
            return (string) get_post_meta($person_id, $key, true);
        };
        $val = function ($key) use ($effective) {
            return isset($effective[$key]) ? (string) $effective[$key] : '';
        };

        // 每项：`当前值` / `存量值` / `是否参与比对` / `存量为空时跳过`。
        $checks = array(
            array($val('name'), $title, true, false),
            array($val('dept'), $meta('zw_person_org_dept'), true, false),
            array($val('duty'), $meta('zw_person_duty'), true, false),
            array($val('user_id'), $meta('zw_person_user_id'), true, false),
            array($val('role'), $meta('zw_person_org_role'), $has('role'), false),
            array((string) ((int) $val('legal_representative')), (string) ((int) $meta('zw_person_legal_representative')), $has('legal_representative'), false),
            array((string) ((int) $val('party_secretary')), (string) ((int) $meta('zw_person_party_secretary')), $has('party_secretary'), false),
            array($val('term_name'), $meta('zw_person_term_name'), $has('term_name'), true),
            array($val('term_start'), $meta('zw_person_term_start'), $has('term_start'), true),
            array($val('term_end'), $meta('zw_person_term_end'), $has('term_end'), true),
            array($val('id_card'), $meta('zw_person_id_card'), $val('id_card') !== '', true),
            array($val('birthday'), $meta('zw_person_birthday'), $val('birthday') !== '', true),
            array($val('phone'), $meta('zw_person_phone'), $has('phone'), true),
            array($val('email'), $meta('zw_person_email'), $has('email'), true),
            array($val('address'), $meta('zw_person_address'), $has('address'), true),
            array($val('political'), $meta('zw_person_political'), $has('political'), true),
            array($val('education'), $meta('zw_person_education'), $has('education'), true),
            array($val('degree'), $meta('zw_person_degree'), $has('degree'), true),
            array($val('company'), $meta('zw_person_company'), $has('company'), true),
            array($val('company_duty'), $meta('zw_person_company_duty'), $has('company_duty'), true),
            array($val('remark'), $meta('zw_person_remark'), $has('remark'), true),
        );

        foreach ($checks as $check) {
            if (empty($check[2])) {
                continue;
            }
            $now  = (string) $check[0];
            $prev = (string) $check[1];
            if (!empty($check[3]) && $prev === '') {
                continue; // 存量为空 ⇒ 首次补全，不算「编辑」
            }
            if ($now !== $prev) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('zw_org_policy_gate_row')) {
    /**
     * 单行政策复核（T5 挂点核心；由 `zw_org_insert_person()` 调用）。
     *
     * 行为口径（需求稿 §5.1 R-3 + §5.5 R-5）：
     *  - 新增（`person_id <= 0`）或被编辑的存量行 ⇒ 判实名 + 判年龄；存量未编辑 ⇒ **不判**；
     *  - 实名未核验 ⇒ `block`（R-3 明文授权；开关 off 时根本不会走到这里）；
     *  - 年龄命中 ⇒ `defer`（暂缓 + 转人事和合规复核，**不阻断**；结论随返回值 `policy` 键返回）；
     *  - 判定唯一出处＝引擎 `zw_policy_check()`；**不传 `trigger_event`**（生产 `policy_trigger_event`
     *    取值未复核，收窄会静默漏规则，见台账「未验证」）。
     *
     * @param int   $person_id
     * @param array $effective     已清洗的生效值（含 `user_id` / `birthday` / `duty` / `term_start`）。
     * @param array $provided_keys
     * @return array `{decision,blocked,warning,result}`
     */
    function zw_org_policy_gate_row($person_id, $effective, $provided_keys = array()) {
        $out = array('decision' => 'pass', 'blocked' => false, 'warning' => null, 'result' => null);
        if (!function_exists('zw_policy_check')) {
            return $out; // 引擎未加载 ⇒ 挂点静默不生效（fail-open，不臆造结论）
        }
        if (!zw_org_policy_row_changed($person_id, $effective, $provided_keys)) {
            return $out; // R-3：存量未编辑 ⇒ 不判
        }

        $effective = is_array($effective) ? $effective : array();
        $uid       = isset($effective['user_id']) ? absint($effective['user_id']) : 0;
        $duty      = isset($effective['duty']) ? (string) $effective['duty'] : '';

        $identity = ($uid > 0 && function_exists('zw_branch_user_identity')) ? (array) zw_branch_user_identity($uid) : array();
        $verified = !empty($identity['verified']);
        if (!$verified && $uid > 0 && class_exists('ZW_Realname') && method_exists('ZW_Realname', 'is_verified')) {
            $verified = (bool) ZW_Realname::is_verified($uid); // 铁律 21：实名强度唯一出处
        }

        $as_of = isset($effective['term_start']) ? trim((string) $effective['term_start']) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $as_of)) {
            $as_of = function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d');
        }

        // R-8：周岁以**任职生效日**为准（唯一出处 `zw_control_age_from_birthday()`）。
        $age   = 0;
        $birth = isset($effective['birthday']) ? trim((string) $effective['birthday']) : '';
        if ($birth !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth) && function_exists('zw_control_age_from_birthday')) {
            $calc = zw_control_age_from_birthday($birth, $as_of);
            $age  = ($calc === '' || $calc === false) ? 0 : (int) $calc;
        }

        $context = array(
            'module_key'  => 'office',
            'feature_key' => 'office.people',
            'position'    => zw_org_policy_position(array($duty)),
            'as_of'       => $as_of,
            'fields'      => array(
                'age'       => $age,
                'uid'       => $uid,
                'person_id' => (string) ((int) $person_id),
            ),
        );

        $result = (array) zw_policy_check($context);
        $hits   = (isset($result['hits']) && is_array($result['hits'])) ? $result['hits'] : array();
        $out['result'] = $result;

        if (!$verified) {
            $notice = function_exists('zw_policy_realname_notice_text') ? (string) zw_policy_realname_notice_text() : '';
            $out['decision'] = 'block';
            $out['blocked']  = true;
            $out['warning']  = array(
                'code'    => 'zw_org_policy_unverified',
                'message' => '新增或变更组织架构人员须核验实名' . ($notice !== '' ? '（' . $notice . '）' : '') . '。',
                'uid'     => $uid,
            );

            return $out;
        }

        if ($hits) {
            $clauses = array();
            foreach ($hits as $hit) {
                $clause = isset($hit['clause']) ? (string) $hit['clause'] : '';
                if ($clause !== '') {
                    $clauses[$clause] = true;
                }
            }
            $out['decision'] = 'defer';
            $out['warning']  = array(
                'code'    => 'zw_org_policy_defer',
                'message' => '任职年龄命中政策条款，已暂缓并转人事与合规复核（依据：' . implode('、', array_keys($clauses)) . '）。',
                'uid'     => $uid,
                'hits'    => $hits,
            );
        }

        return $out;
    }
}

if (!function_exists('zw_org_insert_person')) {
    /**
     * 新增或更新一位主组织人员。
     *
     * @param array $args 人员参数（可选 `policy_gate`：政策挂点显式开关，缺省跟随全局三层开关）。
     * @return array|WP_Error {id:int, updated:bool, policy?:array}
     */
    function zw_org_insert_person($args) {
        $provided_keys = is_array($args) ? array_keys($args) : array();
        $args = wp_parse_args($args, array(
            'person_id'             => 0,
            'name'                  => '',
            'dept'                  => '',
            'role'                  => '',
            'duty'                  => '',
            'legal_representative'  => 0,
            'party_secretary'       => 0,
            'user_id'               => 0,
            'phone'                 => '',
            'email'                 => '',
            'address'               => '',
            'id_card'               => '',
            'political'             => '',
            'education'             => '',
            'degree'                => '',
            'company'               => '',
            'company_duty'          => '',
            'gender'                => '',
            'birthday'              => '',
            'source'                => 'manual',
            'remark'                => '',
            'photo_file_id'         => 0,
            // TASK-1291：组织架构人员任期（默认取届次周期；仅显式提供时才覆盖既有值）。
            'term_name'             => '',
            'term_start'            => '',
            'term_end'              => '',
        ));

        $person_id = (int) $args['person_id'];
        if ($person_id > 0) {
            $existing = get_post($person_id);
            if (!$existing || $existing->post_type !== 'zw_org_person' || (string) get_post_meta($person_id, 'zw_person_org_dept', true) === '') {
                return new WP_Error('zw_org_invalid_person', '人员不存在或不属于组织架构。');
            }
        }

        $dept_key = sanitize_key((string) $args['dept']);
        if ($dept_key === '' || !zw_org_find_department($dept_key)) {
            return new WP_Error('zw_org_invalid_dept', '请选择有效部门。');
        }

        $user_id = (int) $args['user_id'];
        if ($person_id > 0 && $user_id <= 0) {
            $user_id = (int) get_post_meta($person_id, 'zw_person_user_id', true);
        }
        $name = sanitize_text_field((string) $args['name']);
        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if (!$user) {
                return new WP_Error('zw_org_invalid_user', '用户不存在。');
            }
            if ($name === '') {
                $name = $user->display_name !== '' ? $user->display_name : $user->user_login;
            }
        }
        if ($name === '') {
            return new WP_Error('zw_org_empty_name', '姓名不能为空。');
        }

        $source = in_array($args['source'], array('manual', 'user', 'import'), true) ? $args['source'] : 'manual';
        if ($person_id <= 0 && $user_id > 0) {
            $source = 'user';
            $exists = zw_org_find_person_by_user($user_id, $dept_key);
            if ($exists > 0) {
                $person_id = $exists;
            }
        }
        if ($person_id > 0) {
            $old_source = (string) get_post_meta($person_id, 'zw_person_source', true);
            if ($old_source !== '') {
                $source = $old_source;
            }
        }

        $email_provided = in_array('email', $provided_keys, true);
        $email = $email_provided
            ? sanitize_email((string) $args['email'])
            : ($person_id > 0 ? (string) get_post_meta($person_id, 'zw_person_email', true) : '');

        $role_provided = in_array('role', $provided_keys, true);
        $role_ref      = sanitize_text_field(trim((string) $args['role']));
        if ($role_provided) {
            $role = zw_org_find_role($role_ref);
            $role_key = $role ? $role['key'] : '';
            if ($role_key === '' && $role_ref !== '') {
                $role_key = sanitize_key($role_ref);
                if ($role_key === '') {
                    $role_key = $role_ref;
                }
            }
        } else {
            $role_key = $person_id > 0 ? (string) get_post_meta($person_id, 'zw_person_org_role', true) : '';
        }

        $duty_provided = in_array('duty', $provided_keys, true);
        $duty_ref      = sanitize_text_field(trim((string) $args['duty']));
        if ($duty_provided) {
            $duty = zw_org_find_duty($duty_ref);
            $duty_value = $duty ? $duty['name'] : $duty_ref;
        } else {
            $duty_value = $person_id > 0 ? (string) get_post_meta($person_id, 'zw_person_duty', true) : '';
        }

        if (in_array($duty_value, array('法定代表人', '党支部书记'), true)) {
            return new WP_Error('zw_org_reserved_duty', '法定代表人、党支部书记为唯一身份开关，不能作为职务。');
        }

        if ($duty_value === '会长' || $duty_value === 'president') {
            $holder = zw_org_find_duty_holder('会长', $person_id);
            if ($holder > 0) {
                return new WP_Error('zw_org_duplicate_president', '会长已有其他人员担任，不能重复选择。');
            }
        }

        $legal_provided = in_array('legal_representative', $provided_keys, true);
        $party_provided = in_array('party_secretary', $provided_keys, true);
        $legal_representative = $legal_provided
            ? (!empty($args['legal_representative']) ? 1 : 0)
            : ($person_id > 0 ? (int) get_post_meta($person_id, 'zw_person_legal_representative', true) : 0);
        $party_secretary = $party_provided
            ? (!empty($args['party_secretary']) ? 1 : 0)
            : ($person_id > 0 ? (int) get_post_meta($person_id, 'zw_person_party_secretary', true) : 0);

        if ($legal_representative) {
            $holder = zw_org_find_unique_identity_holder('zw_person_legal_representative', $person_id);
            if ($holder > 0) {
                return new WP_Error('zw_org_duplicate_legal_representative', '法定代表人已有其他人员担任，不能重复选择。');
            }
        }
        if ($party_secretary) {
            $holder = zw_org_find_unique_identity_holder('zw_person_party_secretary', $person_id);
            if ($holder > 0) {
                return new WP_Error('zw_org_duplicate_party_secretary', '党支部书记已有其他人员担任，不能重复选择。');
            }
        }

        $id_card = strtoupper(preg_replace('/\s+/', '', sanitize_text_field((string) $args['id_card'])));
        if ($id_card !== '') {
            $id_info = zw_org_id_card_info($id_card);
            if (!empty($id_info['valid'])) {
                // 18 位（含两类居民居住证）：行为不变 —— 性别与出生日期由证件号推导
                $gender   = $id_info['gender'];
                $birthday = $id_info['birthday'];
            } elseif (zw_org_id_card_any_type_ok($id_card)) {
                /*
                 * TASK-2118：香港/澳门居民身份证与两类通行证**无法从号码推导**性别与出生日期
                 * （号码里没有性别位/生日段）。以前这里直接返回「格式或校验位不正确」⇒ 持这些证件的
                 * 理事、负责人**根本录不进组织架构**（TASK-2114 实测）。
                 * 现改为：号码只要是**任一受支持类型**的形态就接受，性别/出生日期取表单填写的值
                 * （表单本来就有这两项，只是以前在这条分支被推导值覆盖）。
                 */
                $gender   = in_array($args['gender'], array('male', 'female'), true) ? $args['gender'] : '';
                $birthday = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['birthday']) ? (string) $args['birthday'] : '';
            } else {
                return new WP_Error('zw_org_invalid_id_card', '证件号格式不正确，请照证件填写后重试。');
            }
        } else {
            $gender   = in_array($args['gender'], array('male', 'female'), true) ? $args['gender'] : '';
            $birthday = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['birthday']) ? (string) $args['birthday'] : '';
        }

        $political = sanitize_text_field((string) $args['political']);
        if ($political !== '' && !in_array($political, zw_org_political_options(), true)) {
            $political = '';
        }

        $education = sanitize_text_field((string) $args['education']);
        if ($education !== '' && !in_array($education, zw_org_education_options(), true)) {
            $education = '';
        }

        $degree = sanitize_text_field((string) $args['degree']);
        if ($degree !== '' && !in_array($degree, zw_org_degree_options(), true)) {
            $degree = '';
        }

        // TASK-1598 · T5：政策挂点（缺省跟随全局三层开关，默认 off ⇒ 以下整段短路）。
        // 位置：所有生效值已算完、**任何写库之前** ⇒ 阻断时不产生半成品数据。
        $policy = array('decision' => 'pass', 'blocked' => false, 'warning' => null, 'result' => null);
        if (zw_org_policy_gate_on($args, $provided_keys)) {
            $policy_term_start = isset($args['term_start']) ? sanitize_text_field((string) $args['term_start']) : '';
            $policy_term_start = zw_org_is_date_value($policy_term_start) ? $policy_term_start : '';
            $policy_term_end = isset($args['term_end']) ? sanitize_text_field((string) $args['term_end']) : '';
            $policy_term_end = zw_org_is_date_value($policy_term_end) ? $policy_term_end : '';

            $policy = zw_org_policy_gate_row($person_id, array(
                'name'                 => $name,
                'dept'                 => $dept_key,
                'duty'                 => $duty_value,
                'role'                 => $role_key,
                'user_id'              => $user_id,
                'id_card'              => $id_card,
                'birthday'             => $birthday,
                'term_name'            => isset($args['term_name']) ? sanitize_text_field((string) $args['term_name']) : '',
                'term_start'           => $policy_term_start,
                'term_end'             => $policy_term_end,
                'phone'                => sanitize_text_field((string) $args['phone']),
                'email'                => $email,
                'address'              => sanitize_text_field((string) $args['address']),
                'political'            => $political,
                'education'            => $education,
                'degree'               => $degree,
                'company'              => sanitize_text_field((string) $args['company']),
                'company_duty'         => sanitize_text_field((string) $args['company_duty']),
                'remark'               => sanitize_text_field((string) $args['remark']),
                'legal_representative' => $legal_representative,
                'party_secretary'      => $party_secretary,
            ), $provided_keys);

            if (!empty($policy['blocked'])) {
                $policy_message = isset($policy['warning']['message'])
                    ? (string) $policy['warning']['message']
                    : '新增或变更组织架构人员须核验实名。';

                return new WP_Error('zw_org_policy_unverified', $policy_message);
            }
        }

        $is_update = $person_id > 0;
        if ($is_update) {
            wp_update_post(array('ID' => $person_id, 'post_title' => $name));
        } else {
            $person_id = wp_insert_post(array(
                'post_type'   => 'zw_org_person',
                'post_status' => 'publish',
                'post_title'  => $name,
            ));
            if (!$person_id || is_wp_error($person_id)) {
                return new WP_Error('zw_org_insert_failed', '人员保存失败。');
            }
            $count = count(zw_org_people($dept_key));
            wp_update_post(array('ID' => $person_id, 'menu_order' => $count + 1));
        }

        zw_oa_org_person_meta_set($person_id, 'zw_person_org_dept', $dept_key);
        if ($role_provided || !$is_update) {
            zw_oa_org_person_meta_set($person_id, 'zw_person_org_role', $role_key);
        }
        zw_oa_org_person_meta_set($person_id, 'zw_person_duty', $duty_value);
        zw_oa_org_person_meta_set($person_id, 'zw_person_legal_representative', $legal_representative ? '1' : '0');
        zw_oa_org_person_meta_set($person_id, 'zw_person_party_secretary', $party_secretary ? '1' : '0');
        zw_oa_org_person_meta_set($person_id, 'zw_person_user_id', $user_id);
        zw_oa_org_person_meta_set($person_id, 'zw_person_phone', sanitize_text_field((string) $args['phone']));
        zw_oa_org_person_meta_set($person_id, 'zw_person_email', $email);
        zw_oa_org_person_meta_set($person_id, 'zw_person_address', sanitize_text_field((string) $args['address']));
        // TASK-804：身份证落密文 + 查重哈希（不落明文）
        if (function_exists('zw_idcard_store')) {
            zw_idcard_store($person_id, 'zw_person_id_card', $id_card);
        } else {
            zw_oa_org_person_meta_set($person_id, 'zw_person_id_card', $id_card);
        }
        zw_oa_org_person_meta_set($person_id, 'zw_person_political', $political);
        zw_oa_org_person_meta_set($person_id, 'zw_person_education', $education);
        zw_oa_org_person_meta_set($person_id, 'zw_person_degree', $degree);
        zw_oa_org_person_meta_set($person_id, 'zw_person_company', sanitize_text_field((string) $args['company']));
        zw_oa_org_person_meta_set($person_id, 'zw_person_company_duty', sanitize_text_field((string) $args['company_duty']));
        zw_oa_org_person_meta_set($person_id, 'zw_person_gender', $gender);
        zw_oa_org_person_meta_set($person_id, 'zw_person_birthday', $birthday);
        zw_oa_org_person_meta_set($person_id, 'zw_person_remark', sanitize_text_field((string) $args['remark']));
        zw_oa_org_person_meta_set($person_id, 'zw_person_photo_file_id', (int) $args['photo_file_id']);
        zw_oa_org_person_meta_set($person_id, 'zw_person_source', $source);
        zw_oa_org_person_meta_set($person_id, 'zw_person_status', 'active');

        // TASK-1291：任期三键 —— 仅当调用方**显式提供**该参数时才覆盖（未提供保持原值），
        // 与 role/duty 的 *_provided 写法同型，保证既有保存链路与存量数据不被回冲。
        if (in_array('term_name', $provided_keys, true)) {
            zw_oa_org_person_meta_set($person_id, 'zw_person_term_name', sanitize_text_field((string) $args['term_name']));
        }
        if (in_array('term_start', $provided_keys, true)) {
            $term_start = sanitize_text_field((string) $args['term_start']);
            zw_oa_org_person_meta_set($person_id, 'zw_person_term_start', zw_org_is_date_value($term_start) ? $term_start : '');
        }
        if (in_array('term_end', $provided_keys, true)) {
            $term_end = sanitize_text_field((string) $args['term_end']);
            zw_oa_org_person_meta_set($person_id, 'zw_person_term_end', zw_org_is_date_value($term_end) ? $term_end : '');
        }

        // TASK-1598 · T5：`policy` 为**只增键**（不阻断时携带年龄软结论 / 暂缓警告，供展示面消费）。
        return array('id' => (int) $person_id, 'updated' => $is_update, 'policy' => $policy);
    }
}

if (!function_exists('zw_org_delete_person')) {
    /**
     * 删除主组织人员；分支机构届次人员不可由此删除。
     *
     * @param int $person_id 人员 ID。
     * @return bool
     */
    function zw_org_delete_person($person_id) {
        $person_id = (int) $person_id;
        if ($person_id <= 0 || get_post_type($person_id) !== 'zw_org_person') {
            return false;
        }
        $dept = (string) get_post_meta($person_id, 'zw_person_org_dept', true);
        if ($dept === '') {
            return false;
        }
        return (bool) wp_delete_post($person_id, true);
    }
}

if (!function_exists('zw_org_migration_set_identity')) {
    /**
     * 迁移时设置唯一身份；若已有其他人持有则跳过，保证唯一性。
     *
     * @param int    $person_id 人员 ID。
     * @param string $meta_key  身份元字段。
     * @return bool
     */
    function zw_org_migration_set_identity($person_id, $meta_key) {
        $person_id = (int) $person_id;
        $meta_key  = sanitize_key((string) $meta_key);
        if ($person_id <= 0 || $meta_key === '') {
            return false;
        }
        if ((string) get_post_meta($person_id, $meta_key, true) === '1') {
            return true;
        }
        $holder = zw_org_find_unique_identity_holder($meta_key, $person_id);
        if ($holder > 0) {
            return false;
        }
        zw_oa_org_person_meta_set($person_id, $meta_key, '1');
        return true;
    }
}

if (!function_exists('zw_org_maybe_migrate_data')) {
    /**
     * TASK-463 一次性数据迁移：
     * 1. 旧角色字典 -> 职务字典，新角色字典清空；
     * 2. 人员旧角色字段 -> 职务字段；
     * 3. 法定代表人/党支部书记改为唯一身份标记；
     * 4. 按用户指定标记陈新洲的身份。
     *
     * @param bool $force 是否强制重跑（仅数据修复时使用）。
     * @return void
     */
    function zw_org_maybe_migrate_data($force = false) {
        static $running = false;
        if ($running) {
            return;
        }
        $version = (string) zw_oa_org_data_model_get();
        if (!$force && $version === ZW_ORG_DATA_MODEL_VERSION) {
            return;
        }
        $running = true;

        $legacy_rows = zw_oa_org_dict_get('roles', null);
        if (!is_array($legacy_rows) || empty($legacy_rows)) {
            $legacy_rows = zw_org_legacy_default_roles();
        }

        $duties = zw_org_filter_legacy_duties($legacy_rows);
        $keys   = array_column($duties, 'key');
        foreach (zw_org_default_duties() as $default) {
            if (!in_array($default['key'], $keys, true)) {
                $duties[] = $default;
                $keys[]   = $default['key'];
            }
        }
        if (empty($duties)) {
            $duties = zw_org_default_duties();
        }
        zw_oa_org_dict_save('duties', zw_org_normalize_rows($duties, zw_org_default_duties()));
        zw_oa_org_dict_save('roles', array());

        $legacy_map = array();
        foreach ($legacy_rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key  = isset($row['key']) ? sanitize_key((string) $row['key']) : '';
            $name = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';
            if ($key !== '') {
                $legacy_map[$key] = $row;
            }
            if ($name !== '') {
                $legacy_map[$name] = $row;
            }
        }
        foreach (zw_org_legacy_default_roles() as $row) {
            if (!isset($legacy_map[$row['key']])) {
                $legacy_map[$row['key']] = $row;
            }
            if (!isset($legacy_map[$row['name']])) {
                $legacy_map[$row['name']] = $row;
            }
        }

        $identity_targets = apply_filters('zw_org_identity_migration_targets', array(
            '陈新洲' => array(
                'zw_person_legal_representative' => true,
                'zw_person_party_secretary'      => true,
            ),
        ));

        $people = get_posts(array(
            'post_type'      => 'zw_org_person',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                array(
                    'key'     => 'zw_person_org_dept',
                    'compare' => 'EXISTS',
                ),
            ),
        ));
        foreach ((array) $people as $person) {
            $person_id = (int) $person->ID;
            $old_role  = (string) get_post_meta($person_id, 'zw_person_org_role', true);
            $old_duty  = (string) get_post_meta($person_id, 'zw_person_duty', true);
            $target    = '';

            if ($old_role !== '') {
                $legacy = isset($legacy_map[$old_role]) ? $legacy_map[$old_role] : null;
                if ($legacy) {
                    $legacy_key  = isset($legacy['key']) ? (string) $legacy['key'] : '';
                    $legacy_name = isset($legacy['name']) ? (string) $legacy['name'] : '';
                    if ($legacy_key === 'legal_representative' || $legacy_name === '法定代表人') {
                        zw_org_migration_set_identity($person_id, 'zw_person_legal_representative');
                    } elseif ($legacy_name === '党支部书记') {
                        zw_org_migration_set_identity($person_id, 'zw_person_party_secretary');
                    } else {
                        $target = $legacy_name;
                    }
                } else {
                    $target = $old_role;
                }
            }

            if ($old_duty !== '') {
                $cleaned = $old_duty;
                if (strpos($cleaned, '法定代表人') !== false) {
                    zw_org_migration_set_identity($person_id, 'zw_person_legal_representative');
                    $cleaned = trim(str_replace('法定代表人', '', $cleaned));
                }
                if (strpos($cleaned, '党支部书记') !== false) {
                    zw_org_migration_set_identity($person_id, 'zw_person_party_secretary');
                    $cleaned = trim(str_replace('党支部书记', '', $cleaned));
                }
                if ($target === '' && $cleaned !== '') {
                    $target = $cleaned;
                }
            }

            if (isset($identity_targets[$person->post_title]) && is_array($identity_targets[$person->post_title])) {
                foreach ($identity_targets[$person->post_title] as $identity_key => $enabled) {
                    if ($enabled) {
                        zw_org_migration_set_identity($person_id, (string) $identity_key);
                    }
                }
            }

            if ($target === '会长') {
                $existing_president = zw_org_find_duty_holder('会长', $person_id);
                if ($existing_president > 0) {
                    zw_oa_org_person_meta_set($person_id, 'zw_person_duty_legacy', '会长');
                    $target = '';
                }
            }

            if ($old_duty !== '' && $old_duty !== $target) {
                zw_oa_org_person_meta_set($person_id, 'zw_person_duty_legacy', $old_duty);
            }
            zw_oa_org_person_meta_set($person_id, 'zw_person_duty', $target);
            if ($old_role !== '') {
                zw_oa_org_person_meta_delete($person_id, 'zw_person_org_role');
            }
        }

        zw_oa_org_data_model_save(ZW_ORG_DATA_MODEL_VERSION);
        $running = false;
    }
}
