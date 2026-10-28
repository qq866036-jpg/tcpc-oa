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
                'enabled'    => false, // 见文件头「骨架期纪律」
                'note'       => '依赖：政策库（zw_policy_source/zw_policy_issue）+ 组织架构部委字典；本模块自带 1 张编号计数表',
            ),
            // 后续模块（按迁移顺序占位，未迁入前保持注释）：
            // 'policy'      => array('label' => 'AI法务部（写侧+管理面）', 'theme_path' => 'inc/policy-platform + inc/admin/policy-platform-*.php'),
            // 'doc'         => array('label' => '发文管理', 'theme_path' => 'inc/admin/doc'),
            // 'org'         => array('label' => '组织架构业务', 'theme_path' => 'inc/admin/org（不含 org-permissions.php）'),
            // 'files'       => array('label' => '网盘文件中心 UI', 'theme_path' => 'inc/admin/org/file-center.php + org-files.php'),
            // 'secretariat' => array('label' => 'AI秘书处', 'theme_path' => 'inc/secretariat'),
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
        return $flag !== '' && defined($flag) && constant($flag);
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
