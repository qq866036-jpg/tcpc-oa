<?php
/**
 * 收文管理 · 权限三处登记（TASK-1297 · R6）。
 *
 * 口径出处：
 * - `docs/research/TASK-1297-收文管理-需求对齐稿-20261008.md` §2 Q6/Q14 + §4.5 + US-08；
 * - `docs/architecture/TASK-1297-收文管理-表结构变更与迁移回滚方案-20261008.md` §4（三处必须同改）
 *   与 §补充 A.2（不新增 `tcpc_perm_receipt`，页面 capability 直接写 `'tcpc_perm_doc'`）。
 *
 * 三处落点：
 *   1. 页面/菜单 capability = `tcpc_perm_doc`（`receipt-bootstrap.php` 的 `add_menu_page()` /
 *      `add_submenu_page()` 实参，由 `zw_receipt_capability()` 唯一出处给出）；
 *   2. `inc/admin/admin-nav-registry.php` 的 `office` 域 `receipt` 条目（本文件不重复登记）；
 *   3. **本文件**：把 `menu:zw-receipt` / `sub:zw-receipt:*` 写进
 *      `zw_org_permission_modules` 的 `doc` 模块 `functions[]`（功能树），否则非 administrator
 *      角色持 `tcpc_perm_doc` 时会被 `zw_admin_nav_apply_permission_filter()` 按功能 ID 裁掉菜单。
 *
 * 版本与幂等（口径裁定，见进度台账 R6 节）：
 * - 既有 `zw_org_migrate_permission_module_functions()` 的版本字面量 `20260922-2` 硬编码在
 *   `inc/admin/org/org-permissions.php`（**不在 R6 允许改动清单内**）。若把该 option 抬高到别的值，
 *   既有迁移会在**每个请求**重跑并把版本回写成 `20260922-2`（版本震荡 + 每请求写库），
 *   因此本轮**不改**该共享版本字面量；
 *   （TASK-1534 追记：TASK-1521 后来只升守卫/早退为 `20261013-1`、漏改收尾值，恰好复现了上面
 *   描述的「每请求重跑」事故；TASK-1534 已把收尾值对齐为 `20261013-1`，三处同值后迁移恢复
 *   「跑一次即收敛」。本模块改用独立版本键的**理由不受影响**，仍按 R6 口径保留。）
 * - 改为在**本文件**内做等价的「带版本的幂等合并」：独立版本键
 *   `zw_receipt_permission_functions_version`（`zw_receipt_*` 前缀，铁律 I8），
 *   把功能 ID **真实写入** `zw_org_permission_modules` 的 `doc` 模块 `functions[]`（非过滤器假象）。
 *
 * 降级（架构稿 §4「风险与降级」）：页面内不依赖 `manage_options`，直接判
 * `current_user_can('tcpc_perm_doc')`；即便功能树未登记也不会 403（只是非管理员看不到菜单）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_RECEIPT_PERMISSION_VERSION')) {
    define('ZW_RECEIPT_PERMISSION_VERSION', '20261009-1');
}

if (!function_exists('zw_receipt_permission_key')) {
    /**
     * 权限模块 key 归一（`sanitize_key()` 口径；无 WP 环境下退化为正则）。
     *
     * @param mixed $value 原始 key。
     * @return string
     */
    function zw_receipt_permission_key($value) {
        $value = (string) $value;
        if (function_exists('sanitize_key')) {
            return (string) sanitize_key($value);
        }
        return strtolower((string) preg_replace('/[^A-Za-z0-9_\-]/', '', $value));
    }
}

if (!function_exists('zw_receipt_permission_capability')) {
    /**
     * 收文管理能力位（唯一出处：`zw_receipt_capability()`；Q6/Q14 复用 `tcpc_perm_doc`）。
     *
     * @return string
     */
    function zw_receipt_permission_capability() {
        if (function_exists('zw_receipt_capability')) {
            return (string) zw_receipt_capability();
        }
        return 'tcpc_perm_doc';
    }
}

if (!function_exists('zw_receipt_permission_module_key')) {
    /**
     * 功能树登记所属权限模块：`doc`（发文管理模块；收文与发文同属公文办理，架构稿 §补充 A.2）。
     *
     * @return string
     */
    function zw_receipt_permission_module_key() {
        return 'doc';
    }
}

if (!function_exists('zw_receipt_permission_version')) {
    /**
     * 本文件自有的迁移版本号（写入独立版本键，不覆盖既有共享版本字面量）。
     *
     * @return string
     */
    function zw_receipt_permission_version() {
        return ZW_RECEIPT_PERMISSION_VERSION;
    }
}

if (!function_exists('zw_receipt_permission_version_key')) {
    /**
     * 独立版本键（铁律 I8：新增 option 一律 `zw_receipt_*` 前缀）。
     *
     * @return string
     */
    function zw_receipt_permission_version_key() {
        return 'zw_receipt_permission_functions_version';
    }
}

if (!function_exists('zw_receipt_permission_modules_option')) {
    /**
     * 组织架构权限模块 option 名（唯一出处：`ZW_ORG_PERMISSION_MODULES_OPTION`）。
     *
     * @return string
     */
    function zw_receipt_permission_modules_option() {
        if (defined('ZW_ORG_PERMISSION_MODULES_OPTION')) {
            return (string) ZW_ORG_PERMISSION_MODULES_OPTION;
        }
        return 'zw_org_permission_modules';
    }
}

if (!function_exists('zw_receipt_permission_function_ids')) {
    /**
     * 收文管理需要登记进功能树的菜单功能 ID（与 WordPress 实际注册的菜单 ID 逐字对齐）。
     *
     * ID 规则（沿用 `zw_org_default_module_function_ids()`）：一级 `menu:<slug>`，
     * 二级 `sub:<parent-slug>:<slug>`。子页顺序与 `zw_receipt_admin_pages()` 一致。
     *
     * @return string[]
     */
    function zw_receipt_permission_function_ids() {
        $parent = function_exists('zw_receipt_menu_slug') ? (string) zw_receipt_menu_slug() : 'zw-receipt';
        $slugs  = array('zw-receipt-create', 'zw-receipt-ledger', 'zw-receipt-orgs', 'zw-receipt-issues');
        if (function_exists('zw_receipt_admin_pages')) {
            $slugs = array();
            foreach ((array) zw_receipt_admin_pages() as $page) {
                $slug = isset($page['slug']) ? (string) $page['slug'] : '';
                if ($slug !== '' && strpos($slug, 'zw-receipt-') === 0) {
                    $slugs[] = $slug;
                }
            }
        }
        $ids = array('menu:' . $parent);
        foreach (array_values(array_unique($slugs)) as $slug) {
            $ids[] = 'sub:' . $parent . ':' . $slug;
        }
        return $ids;
    }
}

if (!function_exists('zw_receipt_permission_merge_modules')) {
    /**
     * **纯函数**：把收文功能 ID 合并进 `doc` 模块 `functions[]`（幂等、保序、不覆盖他人配置）。
     *
     * - 已存在的 ID 不重复追加；既有条目顺序与其他模块内容**原样保留**；
     * - `doc` 模块行缺失时按既有默认标签补一行（防御性；正常不会发生）；
     * - 不做任何 option 读写 ⇒ 可在单测中直接断言。
     *
     * 备注：option 的 canonical 形态是**行列表**（`zw_org_save_permission_modules()` 同样落 list），
     * 因此本函数对输入先 `array_values()` 归一、输出恒为 list of row。
     *
     * @param array $modules 权限模块行（行列表）。
     * @return array 归一为 list 的模块行。
     */
    function zw_receipt_permission_merge_modules($modules) {
        $modules = is_array($modules) ? array_values($modules) : array();
        $key     = zw_receipt_permission_module_key();
        $ids     = zw_receipt_permission_function_ids();
        $found   = false;

        foreach ($modules as $index => $row) {
            if (!is_array($row) || !isset($row['key'])) {
                continue;
            }
            if (zw_receipt_permission_key($row['key']) !== $key) {
                continue;
            }
            $found     = true;
            $functions = isset($row['functions']) && is_array($row['functions'])
                ? array_values($row['functions'])
                : array();
            foreach ($ids as $function_id) {
                if (!in_array($function_id, $functions, true)) {
                    $functions[] = $function_id;
                }
            }
            $modules[$index]['functions']            = array_values(array_unique($functions));
            $modules[$index]['functions_configured'] = 1;
        }

        if (!$found && !empty($ids)) {
            $modules[] = array(
                'key'                  => $key,
                'label'                => '发文管理',
                'description'          => '发文管理模块',
                'functions'            => array_values($ids),
                'functions_configured' => 1,
            );
        }

        return array_values($modules);
    }
}

if (!function_exists('zw_receipt_permission_missing_ids')) {
    /**
     * 尚未登记进 `doc` 模块的功能 ID（空数组 = 功能树已齐备）。
     *
     * @param array $modules 权限模块行。
     * @return string[]
     */
    function zw_receipt_permission_missing_ids($modules) {
        $modules = is_array($modules) ? array_values($modules) : array();
        $key     = zw_receipt_permission_module_key();
        $have    = array();
        foreach ($modules as $row) {
            if (!is_array($row) || !isset($row['key']) || zw_receipt_permission_key($row['key']) !== $key) {
                continue;
            }
            if (isset($row['functions']) && is_array($row['functions'])) {
                foreach ($row['functions'] as $function_id) {
                    $have[] = (string) $function_id;
                }
            }
        }
        $missing = array();
        foreach (zw_receipt_permission_function_ids() as $function_id) {
            if (!in_array($function_id, $have, true)) {
                $missing[] = $function_id;
            }
        }
        return $missing;
    }
}

if (!function_exists('zw_receipt_permission_migrate')) {
    /**
     * 带版本的幂等迁移：把收文功能 ID 写进 `zw_org_permission_modules` 的 `doc` 模块。
     *
     * 行为：
     * - 版本键 == 目标版本 ⇒ 直接返回（幂等，零写库）；
     * - option 缺失 ⇒ 先调用 `zw_org_permission_modules()` 触发既有默认种子，再合并；
     * - 只做「追加缺失 ID」，不删除/不改写既有功能项（与既有迁移同口径）；
     * - 组织架构模块未加载 ⇒ 返回 `modules_unavailable` 且**不写版本键**，下次请求再试。
     *
     * @return array array('changed'=>bool,'reason'=>string,'added'=>string[],'missing_after'=>string[])
     */
    function zw_receipt_permission_migrate() {
        $result = array(
            'changed'        => false,
            'reason'         => 'noop',
            'added'          => array(),
            'missing_after'  => array(),
        );

        $version_key = zw_receipt_permission_version_key();
        $target      = zw_receipt_permission_version();
        if (!function_exists('get_option') || !function_exists('update_option')) {
            $result['reason'] = 'no_wp_option_api';
            return $result;
        }
        if ((string) get_option($version_key, '') === $target) {
            $result['reason'] = 'up_to_date';
            return $result;
        }
        if (!function_exists('zw_org_permission_modules')) {
            $result['reason'] = 'modules_unavailable';
            return $result;
        }

        $option_key = zw_receipt_permission_modules_option();
        $stored     = get_option($option_key, null);
        if (!is_array($stored)) {
            // 触发既有默认种子（仅在 option 缺失时写入一次；正常 admin 请求同样会走到这里）。
            zw_org_permission_modules();
            $stored = get_option($option_key, null);
        }
        if (!is_array($stored)) {
            $result['reason'] = 'no_option';
            return $result;
        }

        $result['added']   = zw_receipt_permission_missing_ids($stored);
        $merged            = zw_receipt_permission_merge_modules($stored);
        $result['changed'] = ($merged !== array_values($stored));
        if ($result['changed']) {
            update_option($option_key, $merged, false);
        }
        update_option($version_key, $target, false);

        $result['reason']        = $result['changed'] ? 'merged' : 'already_registered';
        $result['missing_after'] = zw_receipt_permission_missing_ids($merged);
        return $result;
    }
}

if (function_exists('add_action')) {
    // 20：晚于既有 `zw_org_migrate_permission_module_functions()`（优先级 0），确保 option 已就绪。
    add_action('after_setup_theme', 'zw_receipt_permission_migrate', 20);
}
