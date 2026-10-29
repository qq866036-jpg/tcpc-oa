<?php
/**
 * 组织架构 · 角色与权限页面
 *
 * TASK-463：原「角色列表」更名为「职务列表」；新角色列表保持为空。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('is_admin') || (!is_admin() && !(defined('WP_CLI') && WP_CLI))) {
    return;
}


if (!function_exists('zw_org_module_selected_functions')) {
    /**
     * 计算权限模块编辑时应勾选的功能 ID。
     *
     * @param array $module 当前模块。
     * @param array $tree   实际功能树。
     * @return string[]
     */
    function zw_org_module_selected_functions($module, $tree) {
        $selected = isset($module['functions']) && is_array($module['functions']) ? array_values($module['functions']) : array();
        if (!empty($module['functions_configured']) || !empty($selected) || ($module['key'] ?? '') !== 'system') {
            return $selected;
        }
        $all_ids = zw_org_admin_function_ids($tree);
        $used    = array();
        foreach (zw_org_permission_modules() as $key => $row) {
            if ($key === 'system') {
                continue;
            }
            $used = array_merge($used, isset($row['functions']) && is_array($row['functions']) ? $row['functions'] : array());
        }
        return array_values(array_diff($all_ids, array_values(array_unique($used))));
    }
}

if (!function_exists('zw_org_role_edit_form')) {
    /**
     * 角色编辑表单。
     *
     * @param array $role               角色行。
     * @param array $permission_modules 权限模块。
     * @return void
     */
    function zw_org_role_edit_form($role, $permission_modules) {
        $permissions     = isset($role['permissions']) && is_array($role['permissions']) ? $role['permissions'] : array();
        $selected_people = isset($role['people']) && is_array($role['people']) ? array_map('absint', $role['people']) : array();
        $all_people      = function_exists('zw_org_people') ? zw_org_people() : array();
        // TASK-602：纯前端分支机构角色走「前端机构能力」勾选，不参与后台权限模块。
        $is_front_role   = !empty($role['scope']) && (string) $role['scope'] === 'branch';
        $front_caps      = isset($role['front_caps']) && is_array($role['front_caps']) ? array_values($role['front_caps']) : array();
        $front_options   = function_exists('zw_branch_front_capabilities') ? zw_branch_front_capabilities() : array();
        ?>
        <div class="zw-org-card" id="zw-org-role-edit">
            <div class="zw-org-card-head"><span class="ct">编辑角色：<?php echo esc_html($role['name']); ?></span><span class="desc"><?php echo $is_front_role ? '纯前端分支机构角色（仅在被绑定的机构内生效）' : '可修改角色字段与权限模块'; ?></span></div>
            <div class="zw-org-card-body">
                <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org-roles', admin_url('admin.php'))); ?>">
                    <?php wp_nonce_field('zw_org_update_role'); ?>
                    <input type="hidden" name="zw_org_action" value="update_role">
                    <input type="hidden" name="role_key" value="<?php echo esc_attr($role['key']); ?>">
                    <div class="zw-org-grid">
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="zw-org-role-edit-name">角色名称</label>
                            <input type="text" id="zw-org-role-edit-name" name="role_name" value="<?php echo esc_attr($role['name']); ?>" required>
                        </div>
                        <input type="hidden" name="role_wp_role" value="<?php echo esc_attr($role['wp_role'] ?? ''); ?>">
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="zw-org-role-edit-order">排序</label>
                            <input type="number" id="zw-org-role-edit-order" name="role_order" value="<?php echo (int) $role['order']; ?>" step="1">
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="zw-org-role-edit-desc">权限说明</label>
                            <input type="text" id="zw-org-role-edit-desc" name="role_description" value="<?php echo esc_attr($role['description']); ?>">
                        </div>
                    </div>
                    <?php if ($is_front_role) : ?>
                        <div class="zw-adm-org-field zw-org-field">
                            <label>前端机构授权项（功能类目 · 只读）</label>
                            <?php
                            // TASK-1481（用户 2026-10-12 拍板）：两个机构前端角色**能力位同步**，
                            // 角色层恒 = 全量授权项目录（新增功能自动进入）；逐人差异由机构总管理员
                            // 在机构前台「权限管理」逐项收回，因此本页不再提供角色级勾选（避免无效入口）。
                            $front_items = function_exists('zw_branch_authorization_items') ? zw_branch_authorization_items() : array();
                            ?>
                            <ul class="zw-adm-org-hint zw-org-hint" style="margin:0;padding-left:18px;line-height:1.9">
                                <?php foreach ($front_items as $front_item) : ?>
                                    <li><?php echo esc_html((string) $front_item['label']); ?> <span style="color:#999">（<?php echo esc_html((string) $front_item['key']); ?>）</span></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (!$front_items && $front_options) : ?>
                                <div class="zw-adm-org-check-grid zw-org-check-grid">
                                    <?php foreach ($front_options as $cap_key => $cap_label) : ?>
                                        <label class="zw-adm-org-check zw-org-check zw-adm-touch"><input type="checkbox" checked disabled> <?php echo esc_html($cap_label); ?></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <p class="zw-adm-org-hint zw-org-hint">
                                「分支机构总管理员」与「分支机构管理员」默认拥有同样的全部授权项；授权项来自功能类目注册表，
                                以后新开发的功能会**自动**出现在这里与机构前台「权限管理」中。<br>
                                具体到每位管理员的开放 / 收回，由该机构**总管理员**在机构前台「权限管理」逐项操作（留痕见「分支机构 → 机构角色授权」）。
                                制证提交与批次发货不下放机构，仍仅后端管理员。
                            </p>
                        </div>
                    <?php else : ?>
                        <div class="zw-adm-org-field zw-org-field">
                            <label>权限模块</label>
                            <div class="zw-adm-org-check-grid zw-org-check-grid">
                                <?php foreach ($permission_modules as $module) : ?>
                                    <label class="zw-adm-org-check zw-org-check zw-adm-touch"><input type="checkbox" name="role_permissions[]" value="<?php echo esc_attr($module['key']); ?>" <?php checked(in_array($module['key'], $permissions, true)); ?>> <?php echo esc_html($module['label']); ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($is_front_role) : ?>
                        <p class="zw-adm-org-hint zw-org-hint">纯前端角色的授权对象在「分支机构 → 机构列表 → 管理员」页按机构绑定（总管理员每机构 1 人、管理员可多人），不在此处关联人员。</p>
                    <?php else : ?>
                    <div class="zw-adm-org-field zw-org-field">
                        <label for="zw-org-role-people-search">关联人员姓名</label>
                        <input type="search" class="zw-adm-org-user-search zw-org-user-search" id="zw-org-role-people-search" placeholder="输入姓名或部门筛选">
                        <select class="zw-adm-org-user-select zw-org-user-select" id="zw-org-role-people" name="role_people[]" multiple size="8">
                            <?php foreach ($all_people as $person) :
                                $person_id = (int) $person->ID;
                                $person_uid = (int) get_post_meta($person_id, 'zw_person_user_id', true);
                                $dept      = (string) get_post_meta($person_id, 'zw_person_org_dept', true);
                                ?>
                                <option value="<?php echo $person_id; ?>" <?php selected(in_array($person_id, $selected_people, true)); ?>>
                                    uid：<?php echo $person_uid; ?> <?php echo esc_html($person->post_title); ?><?php echo $dept !== '' ? '（' . esc_html(zw_org_department_label($dept)) . '）' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="zw-adm-org-hint zw-org-hint">按住 Ctrl / Command 可多选；保存后会同步到人员多角色关系。</p>
                    </div>
                    <?php endif; ?>
                    <div class="zw-adm-org-form-actions zw-org-form-actions">
                        <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary">保存角色</button>
                        <a class="zw-adm-org-btn zw-org-btn" href="<?php echo esc_url(add_query_arg('page', 'zw-org-roles', admin_url('admin.php'))); ?>">取消</a>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('zw_org_module_edit_form')) {
    /**
     * 权限模块编辑表单：一级/二级功能复选框。
     *
     * @param array $module 权限模块。
     * @param array $tree   实际后台功能树。
     * @return void
     */
    function zw_org_module_edit_form($module, $tree) {
        $selected = zw_org_module_selected_functions($module, $tree);
        ?>
        <div class="zw-org-card" id="zw-org-module-edit">
            <div class="zw-org-card-head"><span class="ct">编辑权限模块：<?php echo esc_html($module['label']); ?></span><span class="desc">勾选该模块可访问的一级/二级后台功能</span></div>
            <div class="zw-org-card-body">
                <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org-roles', admin_url('admin.php'))); ?>">
                    <?php wp_nonce_field('zw_org_update_permission_module'); ?>
                    <input type="hidden" name="zw_org_action" value="update_permission_module">
                    <input type="hidden" name="module_key" value="<?php echo esc_attr($module['key']); ?>">
                    <div class="zw-org-grid">
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="zw-org-module-edit-label">模块名称</label>
                            <input type="text" id="zw-org-module-edit-label" name="module_label" value="<?php echo esc_attr($module['label']); ?>" required>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="zw-org-module-edit-desc">模块说明</label>
                            <input type="text" id="zw-org-module-edit-desc" name="module_description" value="<?php echo esc_attr($module['description']); ?>">
                        </div>
                    </div>
                    <div class="zw-adm-org-field zw-org-field">
                        <label>后台功能</label>
                        <div class="zw-adm-org-perm-tree zw-org-perm-tree">
                            <?php if (empty($tree)) : ?>
                                <p class="zw-adm-org-hint zw-org-hint">当前上下文未读取到后台菜单树，请在后台页面刷新后重试。</p>
                            <?php else : ?>
                                <?php foreach ($tree as $top) : ?>
                                    <div class="zw-adm-org-perm-tree-top zw-org-perm-tree-top">
                                        <label class="zw-adm-org-check zw-org-check zw-adm-org-check-top zw-org-check-top zw-adm-touch">
                                            <input type="checkbox" name="module_functions[]" value="<?php echo esc_attr($top['id']); ?>" data-perm-parent="<?php echo esc_attr($top['id']); ?>" <?php checked(in_array($top['id'], $selected, true)); ?>>
                                            <strong><?php echo esc_html($top['title']); ?></strong>
                                        </label>
                                        <?php if (!empty($top['children'])) : ?>
                                            <div class="zw-adm-org-perm-tree-children zw-org-perm-tree-children">
                                                <?php foreach ($top['children'] as $child) : ?>
                                                    <label class="zw-adm-org-check zw-org-check zw-adm-touch">
                                                        <input type="checkbox" name="module_functions[]" value="<?php echo esc_attr($child['id']); ?>" data-perm-child="<?php echo esc_attr($top['id']); ?>" <?php checked(in_array($child['id'], $selected, true)); ?>>
                                                        <?php echo esc_html($child['title']); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                    // TASK-1980：虚拟功能点（粒度比菜单更细，例如「看日志姓名/IP 原文」）。
                    // 它们不产生菜单项，因此不在上方功能树里，必须单独渲染，否则无法勾选。
                    $vf_specs = function_exists('zw_org_virtual_function_specs') ? zw_org_virtual_function_specs() : array();
                    $vf_rows  = array();
                    foreach ($vf_specs as $vf_id => $vf_spec) {
                        if (isset($vf_spec['module']) && (string) $vf_spec['module'] === (string) $module['key']) {
                            $vf_rows[(string) $vf_id] = $vf_spec;
                        }
                    }
                    ?>
                    <?php if (!empty($vf_rows)) : ?>
                    <div class="zw-adm-org-field zw-org-field">
                        <label>细粒度权限（不在后台菜单中）</label>
                        <div class="zw-adm-org-perm-tree zw-org-perm-tree">
                            <?php foreach ($vf_rows as $vf_id => $vf_spec) : ?>
                                <div class="zw-adm-org-perm-tree-top zw-org-perm-tree-top">
                                    <label class="zw-adm-org-check zw-org-check zw-adm-org-check-top zw-org-check-top zw-adm-touch">
                                        <input type="checkbox" name="module_functions[]" value="<?php echo esc_attr($vf_id); ?>" <?php checked(in_array($vf_id, $selected, true)); ?>>
                                        <strong><?php echo esc_html((string) ($vf_spec['title'] ?? $vf_id)); ?></strong>
                                    </label>
                                    <?php if (!empty($vf_spec['description'])) : ?>
                                        <p class="zw-adm-org-hint zw-org-hint"><?php echo esc_html((string) $vf_spec['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="zw-adm-org-form-actions zw-org-form-actions">
                        <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary">保存权限模块</button>
                        <a class="zw-adm-org-btn zw-org-btn" href="<?php echo esc_url(add_query_arg('page', 'zw-org-roles', admin_url('admin.php'))); ?>">取消</a>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('zw_org_page_roles')) {
    /**
     * 角色与权限后台页。
     *
     * @return void
     */
    function zw_org_page_roles() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限');
        }

        $duties             = zw_org_duties();
        $roles              = zw_org_roles();
        $permission_modules = zw_org_permission_modules();
        $duty_counts        = zw_org_people_count_by_duty();
        $role_counts        = zw_org_people_count_by_role();
        $total_people       = zw_org_people_count();
        $notice             = zw_org_admin_take_notice();

        $role_edit_key   = isset($_GET['role']) ? sanitize_key((string) wp_unslash($_GET['role'])) : '';
        $module_edit_key = isset($_GET['module']) ? sanitize_key((string) wp_unslash($_GET['module'])) : '';
        $role_edit       = null;
        foreach ($roles as $role_row) {
            if (($role_row['key'] ?? '') === $role_edit_key) {
                $role_edit = $role_row;
                break;
            }
        }
        $module_edit   = $module_edit_key !== '' && isset($permission_modules[$module_edit_key]) ? $permission_modules[$module_edit_key] : null;
        $function_tree = $module_edit ? zw_org_admin_function_tree() : array();

        // TASK-2166：本页标记使用 `.zw-adm-touch` ⇒ 由本文件生成 canonical 触屏热区 CSS
        // 并交给模块唯一 `<style>` 输出点（SPEC-10-B §3；输出仍只有一次）。
        zw_org_admin_styles(array(
            'touch_css' => function_exists('zw_adm_ui_touch_css') ? zw_adm_ui_touch_css('body') : '',
        ));
        ?>
        <div class="wrap zw-adm-org-wrap zw-org-wrap">
            <div class="zw-adm-org-head zw-org-head">
                <h1>角色与权限</h1>
                <p>维护职务、角色与权限模块；可编辑角色关联人员与模块对应的后台功能。</p>
            </div>

            <?php if ($notice) : ?>
                <div class="zw-adm-org-notice zw-org-notice <?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['text']); ?></div>
            <?php endif; ?>

            <?php if ($role_edit) : ?>
                <?php zw_org_role_edit_form($role_edit, $permission_modules); ?>
            <?php endif; ?>
            <?php if ($module_edit) : ?>
                <?php zw_org_module_edit_form($module_edit, $function_tree); ?>
            <?php endif; ?>

            <div class="zw-adm-org-kpis zw-org-kpis">
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">职务</div><div class="n"><?php echo (int) count($duties); ?></div><div class="d">原角色字典已迁移为职务</div></div>
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">角色</div><div class="n"><?php echo (int) count($roles); ?></div><div class="d">附件角色已导入</div></div>
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">未指定职务</div><div class="n"><?php echo (int) ($duty_counts['__none__'] ?? 0); ?></div><div class="d">可在组织与人员中补充</div></div>
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">权限模块</div><div class="n"><?php echo (int) count($permission_modules); ?></div><div class="d">维护后台功能范围</div></div>
            </div>

            <div class="zw-org-card zw-adm-org-hidden zw-org-hidden" id="zw-org-duty-panel">
                <div class="zw-org-card-head"><span class="ct">增加职务</span><span class="desc">职务名称不可重复</span></div>
                <div class="zw-org-card-body">
                    <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org-roles', admin_url('admin.php'))); ?>">
                        <?php wp_nonce_field('zw_org_add_duty'); ?>
                        <input type="hidden" name="zw_org_action" value="add_duty">
                        <div class="zw-org-grid">
                            <div class="zw-adm-org-field zw-org-field">
                                <label for="zw-org-duty-name">职务名称</label>
                                <input type="text" id="zw-org-duty-name" name="duty_name" required placeholder="例如：监事">
                            </div>
                            <div class="zw-adm-org-field zw-org-field">
                                <label for="zw-org-duty-order">排序</label>
                                <input type="number" id="zw-org-duty-order" name="duty_order" value="<?php echo (int) (count($duties) * 10 + 10); ?>" step="1">
                                <p class="zw-adm-org-hint zw-org-hint">数字越小越靠前。</p>
                            </div>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="zw-org-duty-desc">职务说明</label>
                            <textarea id="zw-org-duty-desc" name="duty_description" placeholder="用于备注该职务的职责范围，本阶段不接管后台权限。"></textarea>
                        </div>
                        <div class="zw-adm-org-form-actions zw-org-form-actions">
                            <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary">保存职务</button>
                            <button type="button" class="zw-adm-org-btn zw-org-btn" data-org-close="zw-org-duty-panel">取消</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="zw-org-card zw-adm-org-hidden zw-org-hidden" id="zw-org-role-panel">
                <div class="zw-org-card-head"><span class="ct">增加角色</span><span class="desc">角色名称不可重复；附件角色已导入，可继续扩展</span></div>
                <div class="zw-org-card-body">
                    <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org-roles', admin_url('admin.php'))); ?>">
                        <?php wp_nonce_field('zw_org_add_role'); ?>
                        <input type="hidden" name="zw_org_action" value="add_role">
                        <div class="zw-org-grid">
                            <div class="zw-adm-org-field zw-org-field">
                                <label for="zw-org-role-name">角色名称</label>
                                <input type="text" id="zw-org-role-name" name="role_name" required placeholder="例如：费用审批人">
                            </div>
                            <div class="zw-adm-org-field zw-org-field">
                                <label for="zw-org-role-order">排序</label>
                                <input type="number" id="zw-org-role-order" name="role_order" value="<?php echo (int) (count($roles) * 10 + 10); ?>" step="1">
                                <p class="zw-adm-org-hint zw-org-hint">数字越小越靠前。</p>
                            </div>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="zw-org-role-desc">权限说明</label>
                            <textarea id="zw-org-role-desc" name="role_description" placeholder="用于备注该角色的职责或权限范围，本阶段不接管后台权限。"></textarea>
                        </div>
                        <div class="zw-adm-org-form-actions zw-org-form-actions">
                            <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary">保存角色</button>
                            <button type="button" class="zw-adm-org-btn zw-org-btn" data-org-close="zw-org-role-panel">取消</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="zw-org-card">
                <div class="zw-org-card-head"><span class="ct">职务列表</span><span class="desc">共 <?php echo (int) count($duties); ?> 个职务</span><button type="button" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary" data-org-panel="zw-org-duty-panel" style="margin-left:10px">+ 增加职务</button></div>
                <div class="zw-org-card-body" style="padding:0">
                    <?php if (empty($duties)) : ?>
                        <div class="zw-org-empty">暂无职务。</div>
                    <?php else : ?>
                        <div class="zw-org-tbl-wrap zw-adm-table-wrap">
                            <table class="zw-adm-org-tbl zw-org-tbl">
                                <thead><tr><th>职务名称</th><th>排序</th><th>关联人员</th><th>职务说明</th></tr></thead>
                                <tbody>
                                <?php foreach ($duties as $duty) :
                                    $duty_count = (int) ($duty_counts[$duty['name']] ?? ($duty_counts[$duty['key']] ?? 0));
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($duty['name']); ?></strong></td>
                                        <td><?php echo (int) $duty['order']; ?></td>
                                        <td><?php echo $duty_count; ?></td>
                                        <td><?php echo $duty['description'] !== '' ? esc_html($duty['description']) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="zw-org-card">
                <div class="zw-org-card-head"><span class="ct">角色列表</span><span class="desc">共 <?php echo (int) count($roles); ?> 个角色</span><button type="button" class="zw-adm-org-btn zw-org-btn" data-org-panel="zw-org-role-panel" style="margin-left:10px">+ 增加角色</button></div>
                <div class="zw-org-card-body" style="padding:0">
                    <?php if (empty($roles)) : ?>
                        <div class="zw-org-empty">角色列表暂为空，等待下一步指示。</div>
                    <?php else : ?>
                        <div class="zw-org-tbl-wrap zw-adm-table-wrap">
                            <table class="zw-adm-org-tbl zw-org-tbl">
                                <thead><tr><th>角色名称</th><th>权限范围</th><th>排序</th><th>关联人员</th><th>权限说明</th><th>操作</th></tr></thead>
                                <tbody>
                                <?php foreach ($roles as $role) :
                                    $wp_role       = isset($role['wp_role']) ? (string) $role['wp_role'] : '';
                                    $permission_keys = isset($role['permissions']) && is_array($role['permissions']) ? $role['permissions'] : array();
                                    $is_front_role = !empty($role['scope']) && (string) $role['scope'] === 'branch';
                                    $permission_labels = array();
                                    if ($is_front_role) {
                                        // TASK-1481：机构前端角色展示**授权项目录**（功能类目注册表，canonical），
                                        // 不再读存储的 `front_caps`（角色层恒为全量授权项，存储值已不参与生效）。
                                        $front_items = function_exists('zw_branch_authorization_items')
                                            ? zw_branch_authorization_items()
                                            : array();
                                        foreach ($front_items as $front_item) {
                                            $permission_labels[] = (string) ($front_item['label'] ?? '');
                                        }
                                    } else {
                                        foreach ($permission_keys as $permission_key) {
                                            $permission_labels[] = zw_org_permission_label($permission_key);
                                        }
                                    }
                                    $people_names = zw_org_role_people_names($role);
                                    // TASK-2252：后台模块角色缺 wp_role ⇒ 勾了权限也不生效，必须显红（否则无人察觉）。
                                    $wp_role_missing = function_exists('zw_org_role_needs_wp_role')
                                        && zw_org_role_needs_wp_role($role);
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($role['name']); ?></strong>
                                            <?php if ($wp_role_missing) : ?>
                                                <span class="zw-org-warn" style="color:#b32d2e;margin-left:6px;white-space:nowrap;">⚠ 未绑定 WP 角色 · 权限不生效</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo !empty($permission_labels) ? esc_html(implode('、', $permission_labels)) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                        <td><?php echo (int) $role['order']; ?></td>
                                        <td><?php echo !empty($people_names) ? esc_html(implode('、', $people_names)) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                        <td><?php echo $role['description'] !== '' ? esc_html($role['description']) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                        <td><a class="zw-adm-org-btn zw-org-btn" href="<?php echo esc_url(add_query_arg(array('page' => 'zw-org-roles', 'role' => $role['key']), admin_url('admin.php'))); ?>">编辑</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="zw-org-card">
                <div class="zw-org-card-head"><span class="ct">权限模块</span><span class="desc">维护模块可访问的后台功能</span></div>
                <div class="zw-org-card-body" style="padding:0">
                    <div class="zw-org-tbl-wrap zw-adm-table-wrap">
                        <table class="zw-adm-org-tbl zw-org-tbl">
                            <thead><tr><th>模块</th><th>功能数</th><th>说明</th><th>操作</th></tr></thead>
                            <tbody>
                            <?php foreach ($permission_modules as $module) : ?>
                                <?php
                                $module_functions = isset($module['functions']) && is_array($module['functions']) ? $module['functions'] : array();
                                if (empty($module_functions) && ($module['key'] ?? '') === 'system') {
                                    $module_functions = zw_org_module_selected_functions($module, zw_org_admin_function_tree());
                                }
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($module['label']); ?></strong></td>
                                    <td><?php echo (int) count($module_functions); ?></td>
                                    <td><?php echo esc_html($module['description']); ?></td>
                                    <td><a class="zw-adm-org-btn zw-org-btn" href="<?php echo esc_url(add_query_arg(array('page' => 'zw-org-roles', 'module' => $module['key']), admin_url('admin.php'))); ?>">编辑</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <script>
        (function () {
            // TASK-2166（回归修复）：双写期（SPEC-10 §1.9.3 阶段 ①）面板标记同时带
            // canonical `.zw-adm-org-hidden` 与兼容别名 `.zw-org-hidden`，样式对两者都
            // `display:none` ⇒ 只翻旧类会让「增加职务 / 增加角色」面板打不开。
            function setPanelHidden(panel, hidden) {
                if (!panel) { return; }
                hidden = !!hidden;
                panel.classList.toggle('zw-adm-org-hidden', hidden);
                panel.classList.toggle('zw-org-hidden', hidden);
            }
            function panelIsHidden(panel) {
                return !panel
                    || panel.classList.contains('zw-adm-org-hidden')
                    || panel.classList.contains('zw-org-hidden');
            }
            document.querySelectorAll('[data-org-panel]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var target = document.getElementById(btn.getAttribute('data-org-panel'));
                    if (!target) { return; }
                    var willOpen = panelIsHidden(target);
                    setPanelHidden(target, !willOpen);
                    if (willOpen) {
                        var first = target.querySelector('input,select,textarea');
                        if (first) { first.focus(); }
                    }
                });
            });
            document.querySelectorAll('[data-org-close]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setPanelHidden(document.getElementById(btn.getAttribute('data-org-close')), true);
                });
            });
            document.querySelectorAll('.zw-org-perm-tree input[type=checkbox][data-perm-parent]').forEach(function (topCheckbox) {
                topCheckbox.addEventListener('change', function () {
                    var parentId = topCheckbox.getAttribute('data-perm-parent');
                    document.querySelectorAll('.zw-org-perm-tree input[type=checkbox][data-perm-child="' + parentId + '"]').forEach(function (childCheckbox) {
                        childCheckbox.checked = topCheckbox.checked;
                    });
                });
            });
            var rolePeopleSearch = document.getElementById('zw-org-role-people-search');
            var rolePeopleSelect = document.getElementById('zw-org-role-people');
            if (rolePeopleSearch && rolePeopleSelect) {
                rolePeopleSearch.addEventListener('input', function () {
                    var q = rolePeopleSearch.value.toLowerCase().trim();
                    Array.prototype.forEach.call(rolePeopleSelect.options, function (option) {
                        option.hidden = q !== '' && option.textContent.toLowerCase().indexOf(q) === -1;
                    });
                });
            }
        })();
        </script>
        <?php
    }
}
