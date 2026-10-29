<?php
/**
 * tcpc-oa 模块装载清单（唯一出处）。
 *
 * ## 设计
 *
 * 每个模块声明：`dir`（相对插件根的代码目录）、`bootstrap`（入口文件名）、`flag`（常量开关）、
 * `theme_path`（迁移前在主题仓的对应目录，用于 diff=0 parity 校验）。
 *
 * ## 骨架期纪律（重要）
 *
 * `enabled` 默认 **false**：模块代码可以先迁入本仓并保持与主题逐字节一致（diff=0），
 * 但在**主题侧 shim 与本插件部署同一发布窗口完成之前，绝不启用** —— 否则会出现
 * 「主题与插件各加载一份」⇒ 菜单重复 / 函数重复定义 / 行为漂移。
 *
 * 启用顺序（每个模块一次发布窗口）：
 *   ① 本仓 `enabled=true` 且 `tcpc-oa.php` 装载路径就绪；
 *   ② 主题侧对应目录改为 shim（有插件则 require 插件，无插件则 fail-closed + admin_notices）；
 *   ③ 同窗口部署插件（`scripts/plugin-switch-oa.sh`）；
 *   ④ 冒烟 + `scripts/verify-module-parity.sh <module>` 记录 diff=0。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('tcpc_oa_modules')) {
    /**
     * 模块清单。
     *
     * @return array<string,array<string,mixed>>
     */
    function tcpc_oa_modules() {
        return array(
            'receipt' => array(
                'label'      => '收文管理',
                'dir'        => 'includes/receipt',
                'bootstrap'  => 'receipt-bootstrap.php',
                'flag'       => 'TCPC_OA_ENABLE_RECEIPT',
                'theme_path' => 'inc/receipt',
                // TASK-2320 增量十四：代码侧启用（第一把钥匙）；第二把钥匙（常量或 option
                // `tcpc_oa_enable_receipt`）未满足时仍**不加载** ⇒ 默认零行为。
                'enabled'    => true,
                'note'       => '依赖：政策库（zw_policy_source/zw_policy_issue）+ 组织架构部委字典；本模块自带 1 张编号计数表',
            ),
            'policy' => array(
                'label'      => 'AI法务部（写侧/管理面）',
                'dir'        => 'includes/policy',
                'bootstrap'  => 'policy-platform-admin.php',
                'flag'       => 'TCPC_OA_ENABLE_POLICY',
                // OA-2418 R2：`theme_path` 只列**真正迁入比对**的文件（判定/读侧仍在主题：
                // policy-platform-data.php / legal-*.php 未迁），外加主题侧 shim。
                // OA-2419 R3-2（Q-A2）：口径再收窄 —— 主题侧只剩 3 个管理面副本要删；
                // 内核读侧 3 个（policy-module-map / policy-platform-cron / policy-rule-engine）
                // **留主题**，故从 parity 主题侧清单移出（插件内同名副本由 registry 默认 exclude 放行）。
                // 与 `scripts/verify-module-parity.sh` 的 registry 逐项对齐（改一处必须两处同步）。
                'theme_path' => 'inc/admin/policy-platform-{consent,content-review,document-center}.php（shim：inc/admin/policy-platform-admin.php）；读侧 inc/policy-platform/{policy-module-map,policy-platform-cron,policy-rule-engine}.php 按 Q-A2 留主题、不进 parity 口径',
                // TASK-2320 W1-2 / OA-2410（**代码侧启用 = 第一把钥匙**）。前提已满足：
                // 主题 1.1.593 起 `inc/admin/policy-platform-admin.php` 已是「插件优先/主题回退」shim
                // （符号哨兵 + 整段条件包裹）⇒ 本模块装载后主题让位，不会重复声明。
                // 第二把钥匙（常量 TCPC_OA_ENABLE_POLICY / option `tcpc_oa_enable_policy`）未满足时
                // 仍**不加载** ⇒ 默认零行为，可一键回滚（option 置 0）。判定/读侧仍留主题（Q-A2）。
                'enabled'    => true,
                // OA-2419 R3-2 · 装载责任：`bootstrap`（policy-platform-admin.php）自己负责 require
                // 全部 7 个模块文件（policy-module-map / admin / consent / content-review / cron /
                // document-center / rule-engine，每处 `is_file` 守卫）。原因：主题 `functions.php` 把这 7 个
                // 分成 5 处 require，其中 3 个管理面只在 `is_admin()` 加载 ⇒ 主题副本删除后若插件只装
                // admin，「内容审核 / 签署管理 / 文档中心」3 个后台页会整页消失。
                'note'       => '判定引擎与条款读侧留主题；本模块装载全部 7 个文件（管理面 4 + 读侧 3），后台页不依赖主题副本',
            ),
            // 以下四个模块 = OA-2418 R2「实际登记」（占位注释的兑现）：`enabled` 仍为 **false**
            // ⇒ `tcpc_oa_module_enabled()` 直接返回 false ⇒ `tcpc_oa_load_modules()` 零 require，
            // 运行期行为与登记前**完全一致**（新增的只是数据行，不产生任何加载）。
            // `theme_path` 口径与 `scripts/verify-module-parity.sh` 的 `registry` 同源，改一处必须两处同步。
            'doc' => array(
                'label'      => '发文管理',
                'dir'        => 'includes/doc',
                'bootstrap'  => 'doc-bootstrap.php',
                'flag'       => 'TCPC_OA_ENABLE_DOC',
                'theme_path' => 'inc/admin/doc',
                // 未迁入：`includes/doc` 尚不存在 ⇒ `verify-module-parity.sh doc` 输出
                // 「插件侧模块目录不存在」并 rc=1（预期红，不得写成通过）。迁入后转 DIFF=0。
                'enabled'    => false,
                'note'       => 'R9–R17 分批迁入（数据层/列表/编辑器/落盘）；迁入前本行只是登记，零加载',
            ),
            'org' => array(
                'label'      => '组织架构业务',
                'dir'        => 'includes/org',
                'bootstrap'  => 'org-bootstrap.php',
                'flag'       => 'TCPC_OA_ENABLE_ORG',
                'theme_path' => 'inc/admin/org（org-*.php；不含 file-center.php / org-files.php）',
                // Q2 拍板：`org-permissions.php` 是 RBAC 内核，**不迁** ⇒ parity 判定时用
                // `--exclude org-permissions.php` 显式排除（插件内不得出现该文件副本）。
                'enabled'    => true,
                'note'       => 'Q2：org-permissions.php 内核不迁；parity 标准调用带 --exclude org-permissions.php',
            ),
            'files' => array(
                'label'      => '网盘文件中心（UI）',
                'dir'        => 'includes/files',
                // 入口待 R20 前复核（未验证）：主题侧 `inc/admin/org/` 下无独立的 files-bootstrap，
                // 现按 UI 入口 `file-center.php` 登记；迁入时若改入口须同步本行与 parity registry。
                'bootstrap'  => 'file-center.php',
                'flag'       => 'TCPC_OA_ENABLE_FILES',
                'theme_path' => 'inc/admin/org/file-center.php + inc/admin/org/org-files.php',
                'enabled'    => true,
                'note'       => '只含主题 inc/admin/org/ 8 个 php 中的这 2 个；bootstrap 入口待复核（未验证）',
            ),
            'secretariat' => array(
                'label'      => 'AI秘书处',
                'dir'        => 'includes/secretariat',
                'bootstrap'  => 'secretariat-bootstrap.php',
                'flag'       => 'TCPC_OA_ENABLE_SECRETARIAT',
                'theme_path' => 'inc/secretariat',
                // R19 迁入 7 文件 / R20 删主题侧 7 文件；在此之前主题独占，插件零加载。
                'enabled'    => false,
                'note'       => 'R19 迁入 / R20 主题侧删除；未迁入 ⇒ parity 预期红（缺插件目录）',
            ),
        );
    }
}

if (!function_exists('tcpc_oa_module_enabled')) {
    /**
     * 模块是否启用：清单 `enabled` 且常量开关为真，二者都满足才启用（双钥匙）。
     *
     * @param string $module 模块键。
     * @return bool
     */
    function tcpc_oa_module_enabled($module) {
        $modules = tcpc_oa_modules();
        if (!isset($modules[$module])) {
            return false;
        }
        $meta = $modules[$module];
        if (empty($meta['enabled'])) {
            return false;
        }
        $flag = (string) ($meta['flag'] ?? '');
        if ($flag !== '' && defined($flag) && constant($flag)) {
            return true;
        }
        // 第二把钥匙的另一种载体：option（`wp option update tcpc_oa_enable_<module> 1`）。
        // 便于灰度与**一键回滚**：把 option 置 0 即恢复主题接管，不必改文件、不必回滚代码。
        $opt = 'tcpc_oa_enable_' . (string) $module;
        return function_exists('get_option') && (int) get_option($opt, 0) === 1;
    }
}

if (!function_exists('tcpc_oa_load_modules')) {
    /**
     * 装载已启用模块的 bootstrap。
     *
     * @return string[] 实际装载的模块键。
     */
    function tcpc_oa_load_modules() {
        $loaded = array();
        foreach (array_keys(tcpc_oa_modules()) as $module) {
            if (!tcpc_oa_module_enabled($module)) {
                continue;
            }
            $meta = tcpc_oa_modules()[$module];
            $file = TCPC_OA_DIR . '/' . trim((string) $meta['dir'], '/') . '/' . (string) $meta['bootstrap'];
            if (!is_file($file)) {
                // fail-closed：启用却缺文件 ⇒ 后台提示，不静默跳过（否则"菜单莫名消失"难查）。
                if (function_exists('add_action')) {
                    add_action('admin_notices', function () use ($module, $file) {
                        if (function_exists('current_user_can') && !current_user_can('manage_options')) {
                            return;
                        }
                        echo '<div class="notice notice-error"><p>'
                            . '办公协同：模块 <code>' . htmlspecialchars((string) $module, ENT_QUOTES, 'UTF-8') . '</code> 已启用但入口缺失：'
                            . '<code>' . htmlspecialchars((string) $file, ENT_QUOTES, 'UTF-8') . '</code>'
                            . '</p></div>';
                    });
                }
                continue;
            }
            require_once $file;
            $loaded[] = $module;
        }
        return $loaded;
    }
}
