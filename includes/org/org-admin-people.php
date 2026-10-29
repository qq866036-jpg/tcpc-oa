<?php
/**
 * 组织架构 · 组织与人员页面
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('is_admin') || (!is_admin() && !(defined('WP_CLI') && WP_CLI))) {
    return;
}


if (!function_exists('zw_org_person_form')) {
    /**
     * 渲染人员新增 / 编辑表单。
     *
     * @param array  $departments    部门字典。
     * @param array  $duties         职务字典。
     * @param array  $person         人员数据。
     * @param string $current_dept   当前筛选部门。
     * @param string $mode           add|edit。
     * @return void
     */
    function zw_org_person_form($departments, $duties, $person = array(), $current_dept = '', $mode = 'add') {
        $person_id = isset($person['id']) ? (int) $person['id'] : 0;
        $action    = $mode === 'edit' ? 'update_person' : 'add_person';
        $nonce     = $mode === 'edit' ? 'zw_org_update_person_' . $person_id : 'zw_org_add_person';
        $uid       = 'zw-org-person-' . ($mode === 'edit' ? 'edit' : 'add');
        $value     = function ($key, $default = '') use ($person) {
            return isset($person[$key]) && $person[$key] !== '' ? $person[$key] : $default;
        };
        $gender_label = zw_org_gender_label($value('gender'));
        $age          = zw_org_age_from_birthday($value('birthday'));
        $dept_value   = $value('dept', $current_dept);
        $photo        = $person_id > 0 ? zw_org_files_person_photo($person_id) : array();

        // TASK-1291：届次 / 任期默认值取全站届次唯一出处（inc/term-cycles.php），可手工覆盖。
        $term_cycles      = function_exists('zw_term_cycles') ? (array) zw_term_cycles() : array();
        $term_default     = function_exists('zw_org_term_default_dates')
            ? zw_org_term_default_dates((string) $value('term_name'))
            : array('term' => '', 'start' => '', 'end' => '');
        $term_name_value  = (string) $value('term_name', isset($term_default['term']) ? $term_default['term'] : '');
        $term_start_value = (string) $value('term_start', isset($term_default['start']) ? $term_default['start'] : '');
        $term_end_value   = (string) $value('term_end', isset($term_default['end']) ? $term_default['end'] : '');

        $is_legal = !empty($person['legal_representative']);
        $is_party = !empty($person['party_secretary']);
        $legal_holder_id = function_exists('zw_org_find_unique_identity_holder')
            ? zw_org_find_unique_identity_holder('zw_person_legal_representative', $person_id)
            : 0;
        $party_holder_id = function_exists('zw_org_find_unique_identity_holder')
            ? zw_org_find_unique_identity_holder('zw_person_party_secretary', $person_id)
            : 0;
        $legal_holder_name = $legal_holder_id > 0 ? get_the_title($legal_holder_id) : '';
        $party_holder_name = $party_holder_id > 0 ? get_the_title($party_holder_id) : '';
        $legal_disabled = $legal_holder_id > 0 && $legal_holder_id !== $person_id;
        $party_disabled = $party_holder_id > 0 && $party_holder_id !== $person_id;
        ?>
        <div class="zw-org-card <?php echo $mode === 'add' ? 'zw-org-hidden' : ''; ?>" id="<?php echo esc_attr($mode === 'add' ? 'zw-org-panel-person' : 'zw-org-edit-person'); ?>">
            <div class="zw-org-card-head">
                <span class="ct"><?php echo $mode === 'edit' ? '编辑人员信息' : '手工增加人员名单'; ?></span>
                <span class="desc">身份证号可自动推算性别、出生日期与年龄</span>
            </div>
            <div class="zw-org-card-body">
                <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org', admin_url('admin.php'))); ?>" class="zw-adm-org-person-form zw-org-person-form" data-mode="<?php echo esc_attr($mode); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field($nonce); ?>
                    <input type="hidden" name="zw_org_action" value="<?php echo esc_attr($action); ?>">
                    <input type="hidden" name="person_id" value="<?php echo (int) $person_id; ?>">
                    <div class="zw-adm-org-identity-strip zw-org-identity-strip">
                        <div class="zw-adm-org-field zw-org-field">
                            <label>角色</label>
                            <div class="zw-adm-org-role-static zw-org-role-static"><?php echo $value('role') !== '' ? esc_html(zw_org_role_label($value('role'))) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></div>
                            <p class="zw-adm-org-hint zw-org-hint">新角色列表暂为空，待后续权限设计完成后关联。</p>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label>法定代表人</label>
                            <label class="<?php echo 'zw-adm-org-switch zw-org-switch' . ($is_legal ? ' on' : '') . ($legal_disabled ? ' is-disabled' : ''); ?> zw-adm-touch" title="<?php echo $legal_disabled ? esc_attr('已由 ' . $legal_holder_name . ' 担任') : '法定代表人'; ?>">
                                <input type="hidden" name="person_legal_representative" value="0">
                                <input type="checkbox" name="person_legal_representative" value="1" aria-label="法定代表人" <?php checked($is_legal); ?> <?php disabled($legal_disabled); ?>>
                                <span class="zw-adm-org-switch-track zw-org-switch-track"><span class="zw-adm-org-switch-thumb zw-org-switch-thumb"></span></span>
                            </label>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label>党支部书记</label>
                            <label class="<?php echo 'zw-adm-org-switch zw-org-switch' . ($is_party ? ' on' : '') . ($party_disabled ? ' is-disabled' : ''); ?> zw-adm-touch" title="<?php echo $party_disabled ? esc_attr('已由 ' . $party_holder_name . ' 担任') : '党支部书记'; ?>">
                                <input type="hidden" name="person_party_secretary" value="0">
                                <input type="checkbox" name="person_party_secretary" value="1" aria-label="党支部书记" <?php checked($is_party); ?> <?php disabled($party_disabled); ?>>
                                <span class="zw-adm-org-switch-track zw-org-switch-track"><span class="zw-adm-org-switch-thumb zw-org-switch-thumb"></span></span>
                            </label>
                        </div>
                    </div>
                    <div class="zw-adm-org-person-layout zw-org-person-layout">
                        <div class="zw-adm-org-person-main zw-org-person-main">
                    <div class="zw-org-grid">
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-name">姓名 <span style="color:#c62828">*</span></label>
                            <input type="text" id="<?php echo esc_attr($uid); ?>-name" name="person_name" value="<?php echo esc_attr($value('name')); ?>" required>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-dept">所属部门 <span style="color:#c62828">*</span></label>
                            <select id="<?php echo esc_attr($uid); ?>-dept" name="person_dept" required>
                                <?php foreach ($departments as $dept) : ?>
                                    <option value="<?php echo esc_attr($dept['key']); ?>" <?php selected($dept_value, $dept['key']); ?>><?php echo esc_html($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php
                        $duty_value = (string) $value('duty');
                        $duty_known = false;
                        $president_holder_id = function_exists('zw_org_find_duty_holder')
                            ? zw_org_find_duty_holder('会长', $person_id)
                            : 0;
                        ?>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-duty">职务</label>
                            <select id="<?php echo esc_attr($uid); ?>-duty" name="person_duty">
                                <option value="">不指定</option>
                                <?php foreach ($duties as $duty) :
                                    $selected = ($duty_value === $duty['name'] || $duty_value === $duty['key']);
                                    if ($selected) {
                                        $duty_known = true;
                                    }
                                    $is_president = ($duty['key'] === 'president' || $duty['name'] === '会长');
                                    $duty_disabled = $is_president && $president_holder_id > 0 && $president_holder_id !== $person_id;
                                    ?>
                                    <option value="<?php echo esc_attr($duty['name']); ?>" <?php selected($selected); ?> <?php disabled($duty_disabled); ?>>
                                        <?php echo esc_html($duty['name']); ?><?php echo $duty_disabled ? '（已由 ' . esc_html(get_the_title($president_holder_id)) . ' 担任）' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!$duty_known && $duty_value !== '') : ?>
                                    <option value="<?php echo esc_attr($duty_value); ?>" selected><?php echo esc_html($duty_value); ?>（历史值）</option>
                                <?php endif; ?>
                            </select>
                            <p class="zw-adm-org-hint zw-org-hint">会长为唯一职务，已有持有人时不可重复选择。</p>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-term-name">届次</label>
                            <select id="<?php echo esc_attr($uid); ?>-term-name" name="person_term_name">
                                <option value="">不指定</option>
                                <?php
                                $term_known = false;
                                foreach ($term_cycles as $cycle) :
                                    $cycle_term = isset($cycle['term']) ? (string) $cycle['term'] : '';
                                    if ($cycle_term === '') {
                                        continue;
                                    }
                                    if ($cycle_term === $term_name_value) {
                                        $term_known = true;
                                    }
                                    ?>
                                    <option value="<?php echo esc_attr($cycle_term); ?>" <?php selected($term_name_value, $cycle_term); ?>><?php echo esc_html($cycle_term); ?></option>
                                <?php endforeach; ?>
                                <?php if (!$term_known && $term_name_value !== '') : ?>
                                    <option value="<?php echo esc_attr($term_name_value); ?>" selected><?php echo esc_html($term_name_value); ?>（历史值）</option>
                                <?php endif; ?>
                            </select>
                            <p class="zw-adm-org-hint zw-org-hint">候选来自「组织架构 → 届次周期」（全站唯一出处）。</p>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-term-start">任期开始</label>
                            <input type="date" id="<?php echo esc_attr($uid); ?>-term-start" name="person_term_start" value="<?php echo esc_attr($term_start_value); ?>">
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-term-end">任期结束</label>
                            <input type="date" id="<?php echo esc_attr($uid); ?>-term-end" name="person_term_end" value="<?php echo esc_attr($term_end_value); ?>">
                            <p class="zw-adm-org-hint zw-org-hint">默认取所选届次起止，可手工调整。</p>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-political">政治面貌</label>
                            <select id="<?php echo esc_attr($uid); ?>-political" name="person_political">
                                <option value="">请选择</option>
                                <?php foreach (zw_org_political_options() as $political) : ?>
                                    <option value="<?php echo esc_attr($political); ?>" <?php selected($value('political'), $political); ?>><?php echo esc_html($political); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-education">学历</label>
                            <select id="<?php echo esc_attr($uid); ?>-education" name="person_education">
                                <option value="">请选择</option>
                                <?php foreach (zw_org_education_options() as $education) : ?>
                                    <option value="<?php echo esc_attr($education); ?>" <?php selected($value('education'), $education); ?>><?php echo esc_html($education); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-degree">学位</label>
                            <select id="<?php echo esc_attr($uid); ?>-degree" name="person_degree">
                                <option value="">请选择</option>
                                <?php foreach (zw_org_degree_options() as $degree) : ?>
                                    <option value="<?php echo esc_attr($degree); ?>" <?php selected($value('degree'), $degree); ?>><?php echo esc_html($degree); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-id-card">身份证号</label>
                            <input type="text" class="zw-adm-org-person-id-card zw-org-person-id-card" id="<?php echo esc_attr($uid); ?>-id-card" name="person_id_card" value="<?php echo esc_attr($value('id_card')); ?>" maxlength="18" autocomplete="off" placeholder="输入 18 位或 15 位身份证号，自动推算性别/出生日期/年龄">
                            <p class="zw-adm-org-hint zw-org-hint zw-adm-org-id-card-msg zw-org-id-card-msg" aria-live="polite"></p>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-phone">联系电话</label>
                            <input type="text" id="<?php echo esc_attr($uid); ?>-phone" name="person_phone" value="<?php echo esc_attr($value('phone')); ?>">
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-email">邮箱</label>
                            <input type="email" id="<?php echo esc_attr($uid); ?>-email" name="person_email" value="<?php echo esc_attr($value('email')); ?>">
                        </div>
                        <div class="zw-adm-org-field zw-org-field" style="grid-column:1 / -1">
                            <label for="<?php echo esc_attr($uid); ?>-address">通讯地址</label>
                            <input type="text" id="<?php echo esc_attr($uid); ?>-address" name="person_address" value="<?php echo esc_attr($value('address')); ?>">
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-company">所在单位</label>
                            <input type="text" id="<?php echo esc_attr($uid); ?>-company" name="person_company" value="<?php echo esc_attr($value('company')); ?>">
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-company-duty">所在单位职务</label>
                            <input type="text" id="<?php echo esc_attr($uid); ?>-company-duty" name="person_company_duty" value="<?php echo esc_attr($value('company_duty')); ?>">
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-gender">性别（自动）</label>
                            <input type="hidden" class="zw-adm-org-auto-gender-key zw-org-auto-gender-key" name="person_gender" value="<?php echo esc_attr($value('gender')); ?>">
                            <input type="text" class="zw-adm-org-auto-gender zw-org-auto-gender" id="<?php echo esc_attr($uid); ?>-gender" value="<?php echo esc_attr($gender_label); ?>" readonly>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-birthday">出生日期（自动）</label>
                            <input type="text" class="zw-adm-org-auto-birthday zw-org-auto-birthday" id="<?php echo esc_attr($uid); ?>-birthday" name="person_birthday" value="<?php echo esc_attr($value('birthday')); ?>" readonly>
                        </div>
                        <div class="zw-adm-org-field zw-org-field">
                            <label for="<?php echo esc_attr($uid); ?>-age">年龄（自动）</label>
                            <input type="text" class="zw-adm-org-auto-age zw-org-auto-age" id="<?php echo esc_attr($uid); ?>-age" value="<?php echo $age !== '' ? esc_attr((string) $age . ' 岁') : ''; ?>" readonly>
                        </div>
                    </div>
                    <div class="zw-adm-org-field zw-org-field">
                        <label for="<?php echo esc_attr($uid); ?>-remark">备注</label>
                        <textarea id="<?php echo esc_attr($uid); ?>-remark" name="person_remark" rows="2"><?php echo esc_textarea($value('remark')); ?></textarea>
                    </div>
                        </div>
                        <aside class="zw-adm-org-person-photo zw-org-person-photo">
                            <div class="zw-adm-org-photo-preview zw-org-photo-preview">
                                <?php if (!empty($photo['exists'])) : ?>
                                    <img src="<?php echo esc_url($photo['preview_url']); ?>" alt="">
                                <?php else : ?>
                                    <span class="ph">照片</span>
                                <?php endif; ?>
                            </div>
                            <label class="zw-adm-org-btn zw-org-btn" for="<?php echo esc_attr($uid); ?>-photo">选择照片</label>
                            <input type="file" class="zw-adm-org-photo-input zw-org-photo-input" id="<?php echo esc_attr($uid); ?>-photo" name="person_photo" accept="image/*">
                            <input type="hidden" name="person_photo_file_id" value="<?php echo (int) $value('photo_file_id'); ?>">
                            <p class="zw-adm-org-hint zw-org-hint">照片保存到「网盘文件」，不进入媒体库。</p>
                        </aside>
                    </div>
                    <div class="zw-adm-org-form-actions zw-org-form-actions">
                        <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary"><?php echo $mode === 'edit' ? '保存修改' : '保存人员'; ?></button>
                        <?php if ($mode === 'edit') : ?>
                            <a class="zw-adm-org-btn zw-org-btn" href="<?php echo esc_url(add_query_arg('page', 'zw-org', admin_url('admin.php'))); ?>">返回人员名单</a>
                        <?php else : ?>
                            <button type="button" class="zw-adm-org-btn zw-org-btn" data-org-close="zw-org-panel-person">取消</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('zw_org_page_people')) {
    /**
     * 组织与人员后台页。
     *
     * @return void
     */
    function zw_org_page_people() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限');
        }

        $departments = zw_org_departments();
        $duties      = zw_org_duties();
        $current_dept = isset($_GET['dept']) ? sanitize_key((string) wp_unslash($_GET['dept'])) : '';
        if ($current_dept !== '' && !zw_org_find_department($current_dept)) {
            $current_dept = '';
        }

        $person_id = isset($_GET['person']) ? absint($_GET['person']) : 0;
        $edit_person = array();
        if ($person_id > 0) {
            $edit_post = get_post($person_id);
            if ($edit_post && $edit_post->post_type === 'zw_org_person') {
                $dept = (string) get_post_meta($person_id, 'zw_person_org_dept', true);
                if ($dept !== '') {
                    $meta = zw_org_person_meta($person_id);
                    $edit_person = array_merge($meta, array(
                        'id'   => $person_id,
                        'name' => $edit_post->post_title,
                        'dept' => $dept,
                        'role' => (string) get_post_meta($person_id, 'zw_person_org_role', true),
                    ));
                    if ($current_dept === '') {
                        $current_dept = $dept;
                    }
                }
            }
        }

        $people      = zw_org_people($current_dept);
        $all_people  = zw_org_people();
        $dept_counts = array();
        foreach ($all_people as $person) {
            $key = (string) get_post_meta($person->ID, 'zw_person_org_dept', true);
            if (!isset($dept_counts[$key])) {
                $dept_counts[$key] = 0;
            }
            $dept_counts[$key]++;
        }

        $linked_users = 0;
        foreach ($all_people as $person) {
            if ((int) get_post_meta($person->ID, 'zw_person_user_id', true) > 0) {
                $linked_users++;
            }
        }

        // 「选择用户」面板仍用一次性列表（多选场景，本次未改）。
        $users = get_users(array(
            'number'  => 500,
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => array('ID', 'display_name', 'user_login', 'user_email'),
        ));

        // TASK-595：关联用户面板改用 SPEC-85 canonical「可搜索单选下拉」。
        $link_person_options = array();
        foreach ($all_people as $link_person) {
            $link_person_uid  = (int) get_post_meta($link_person->ID, 'zw_person_user_id', true);
            $link_person_dept = (string) get_post_meta($link_person->ID, 'zw_person_org_dept', true);
            $link_person_dept_label = zw_org_department_label($link_person_dept);
            $link_person_options[] = array(
                'value' => (string) $link_person->ID,
                'label' => $link_person->post_title . '（' . $link_person_dept_label . '）uid：' . $link_person_uid,
                'search' => $link_person->post_title . ' ' . $link_person_dept_label . ' ' . $link_person_dept . ' uid' . $link_person_uid . ' ' . $link_person->ID,
            );
        }
        // TASK-598：用户候选改为服务端搜索（生产 1336 个用户不能整表预载，
        // 原「前 500 名」截断导致排名靠后的用户永远搜不到，见 CHG-067）。

        $notice = zw_org_admin_take_notice();
        // TASK-2166：本页标记使用 `.zw-adm-touch`（替换元素热区容器）⇒ 由**本文件**生成
        // canonical 触屏热区 CSS 并交给模块唯一 `<style>` 输出点（SPEC-10-B §3 判据：
        // 「使用该类的页面文件必须持有同文件 canonical 输出点」；输出仍只有一次）。
        zw_org_admin_styles(array(
            'touch_css' => function_exists('zw_adm_ui_touch_css') ? zw_adm_ui_touch_css('body') : '',
        ));
        ?>
        <div class="wrap zw-adm-org-wrap zw-org-wrap">
            <div class="zw-adm-org-head zw-org-head">
                <h1>组织与人员</h1>
                <p>维护协会部门、人员名单与用户账号关联；支持身份证号自动推算性别、出生日期与年龄。</p>
            </div>

            <?php if ($notice) : ?>
                <div class="zw-adm-org-notice zw-org-notice <?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['text']); ?></div>
            <?php endif; ?>

            <?php if (empty($edit_person)) : ?>
                <div class="zw-adm-org-actions zw-org-actions">
                    <button type="button" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary" data-org-panel="zw-org-panel-dept">+ 增加部门</button>
                    <button type="button" class="zw-adm-org-btn zw-org-btn" data-org-panel="zw-org-panel-person">手工增加人员名单</button>
                    <button type="button" class="zw-adm-org-btn zw-org-btn" data-org-panel="zw-org-panel-users">选择用户</button>
                    <button type="button" class="zw-adm-org-btn zw-org-btn" data-org-panel="zw-org-panel-link">关联用户</button>
                </div>
            <?php endif; ?>

            <div class="zw-adm-org-kpis zw-org-kpis">
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">部门</div><div class="n"><?php echo (int) count($departments); ?></div><div class="d">预设 5 个，可继续增加</div></div>
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">人员</div><div class="n"><?php echo (int) count($all_people); ?></div><div class="d">组织架构内全部人员</div></div>
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">职务</div><div class="n"><?php echo (int) count($duties); ?></div><div class="d">职务字典可扩展</div></div>
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">已关联用户</div><div class="n"><?php echo (int) $linked_users; ?></div><div class="d">可与 WordPress 用户绑定</div></div>
            </div>

            <?php if (empty($edit_person)) : ?>
                <div class="zw-org-card zw-adm-org-hidden zw-org-hidden" id="zw-org-panel-dept">
                    <div class="zw-org-card-head"><span class="ct">增加部门</span><span class="desc">部门名称不可重复</span></div>
                    <div class="zw-org-card-body">
                        <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org', admin_url('admin.php'))); ?>">
                            <?php wp_nonce_field('zw_org_add_department'); ?>
                            <input type="hidden" name="zw_org_action" value="add_department">
                            <div class="zw-org-grid">
                                <div class="zw-adm-org-field zw-org-field">
                                    <label for="zw-org-dept-name">部门名称</label>
                                    <input type="text" id="zw-org-dept-name" name="dept_name" required placeholder="例如：会员服务部">
                                </div>
                                <div class="zw-adm-org-field zw-org-field">
                                    <label for="zw-org-dept-order">排序</label>
                                    <input type="number" id="zw-org-dept-order" name="dept_order" value="50" step="1">
                                    <p class="zw-adm-org-hint zw-org-hint">数字越小越靠前。</p>
                                </div>
                            </div>
                            <div class="zw-adm-org-form-actions zw-org-form-actions">
                                <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary">保存部门</button>
                                <button type="button" class="zw-adm-org-btn zw-org-btn" data-org-close="zw-org-panel-dept">取消</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php zw_org_person_form($departments, $duties, array(), $current_dept, 'add'); ?>

                <div class="zw-org-card zw-adm-org-hidden zw-org-hidden" id="zw-org-panel-users">
                    <div class="zw-org-card-head"><span class="ct">选择用户</span><span class="desc">从已有 WordPress 用户中关联，关联后可编辑补充详细信息</span></div>
                    <div class="zw-org-card-body">
                        <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org', admin_url('admin.php'))); ?>">
                            <?php wp_nonce_field('zw_org_add_users'); ?>
                            <input type="hidden" name="zw_org_action" value="add_users">
                            <div class="zw-org-grid">
                                <div class="zw-adm-org-field zw-org-field">
                                    <label for="zw-org-user-dept">所属部门</label>
                                    <select id="zw-org-user-dept" name="dept" required>
                                        <?php foreach ($departments as $dept) : ?>
                                            <option value="<?php echo esc_attr($dept['key']); ?>" <?php selected($current_dept, $dept['key']); ?>><?php echo esc_html($dept['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="zw-adm-org-field zw-org-field">
                                    <label for="zw-org-user-duty">职务</label>
                                    <select id="zw-org-user-duty" name="user_duty">
                                        <option value="">不指定</option>
                                        <?php foreach ($duties as $duty) : ?>
                                            <option value="<?php echo esc_attr($duty['name']); ?>"><?php echo esc_html($duty['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="zw-adm-org-field zw-org-field">
                                <label for="zw-org-user-search">搜索用户</label>
                                <input type="search" class="zw-adm-org-user-search zw-org-user-search" id="zw-org-user-search" placeholder="输入昵称、用户名或邮箱筛选">
                                <select class="zw-adm-org-user-select zw-org-user-select" id="zw-org-user-select" name="user_ids[]" multiple size="8">
                                    <?php foreach ($users as $user) : ?>
                                        <option value="<?php echo (int) $user->ID; ?>">
                                            <?php echo esc_html($user->display_name); ?>（<?php echo esc_html($user->user_login); ?>）
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="zw-adm-org-hint zw-org-hint">按住 Ctrl / Command 可多选；已在该部门关联的用户再次选择时会更新角色，不会重复创建。</p>
                            </div>
                            <div class="zw-adm-org-form-actions zw-org-form-actions">
                                <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary">关联所选用户</button>
                                <button type="button" class="zw-adm-org-btn zw-org-btn" data-org-close="zw-org-panel-users">取消</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php // TASK-593：给已有人员补绑 / 更换 UID；同一用户只允许绑定一位人员。 ?>
                <div class="zw-org-card zw-adm-org-hidden zw-org-hidden" id="zw-org-panel-link">
                    <div class="zw-org-card-head"><span class="ct">关联用户</span><span class="desc">把已有人员与 WordPress 用户账号绑定；同一用户只能绑定一位人员</span></div>
                    <div class="zw-org-card-body">
                        <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org', admin_url('admin.php'))); ?>" class="zw-adm-org-link-form zw-org-link-form">
                            <?php wp_nonce_field('zw_org_link_person_user'); ?>
                            <input type="hidden" name="zw_org_action" value="link_person_user">
                            <input type="hidden" name="dept" value="<?php echo esc_attr($current_dept); ?>">
                            <div class="zw-org-grid">
                                <div class="zw-adm-org-field zw-org-field">
                                    <label for="zw-org-link-person-input">人员</label>
                                    <?php zw_control_search_select(array(
                                        'id'             => 'zw-org-link-person',
                                        'name'           => 'person_id',
                                        'placeholder'    => '输入姓名 / 部门 / UID 筛选',
                                        'empty_text'     => '没有匹配的人员',
                                        'required'       => true,
                                        'required_text'  => '请先选择人员。',
                                        'options'        => $link_person_options,
                                    )); ?>
                                    <p class="zw-adm-org-hint zw-org-hint">输入即筛选，单击选项即选中；列表行「关联用户 / 改绑」会自动选中该人员。</p>
                                </div>
                                <div class="zw-adm-org-field zw-org-field">
                                    <label for="zw-org-link-user-input">用户账号</label>
                                    <?php zw_control_search_select(array(
                                        'id'             => 'zw-org-link-user',
                                        'name'           => 'person_user_id',
                                        'placeholder'    => '输入姓名 / 用户名 / 实名 / 手机号 / UID（≥2 字）',
                                        'empty_text'     => '没有匹配的用户；可换关键词，或直接用 UID 搜索',
                                        'required'       => true,
                                        'required_text'  => '请先选择用户账号。',
                                        'options'        => array(),
                                        // TASK-598：候选改为服务端搜索，避免「前 500 名」截断。
                                        'search_url'     => admin_url('admin-ajax.php'),
                                        'search_action'  => 'zw_org_search_users',
                                        'search_nonce'   => wp_create_nonce(zw_org_search_users_nonce_action()),
                                        'min_chars'      => 2,
                                        'hint_text'      => '请输入至少 2 个字（支持姓名、用户名、实名、手机号、UID）',
                                        'loading_text'   => '搜索中…',
                                    )); ?>
                                    <p class="zw-adm-org-hint zw-org-hint">输入至少 2 个字即向服务端检索（全库 1300+ 账号均可搜到）；「实名」取该账号实名认证姓名，用于核对身份；已被其他人员占用的用户不可选择，如需改绑请先在原人员处「解除关联」。</p>
                                </div>
                            </div>
                            <div class="zw-adm-org-form-actions zw-org-form-actions">
                                <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary">保存关联</button>
                                <button type="button" class="zw-adm-org-btn zw-org-btn" data-org-close="zw-org-panel-link">取消</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else : ?>
                <?php zw_org_person_form($departments, $duties, $edit_person, $current_dept, 'edit'); ?>
            <?php endif; ?>

            <div class="zw-adm-org-tabs zw-org-tabs">
                <a class="zw-adm-org-tab zw-org-tab <?php echo $current_dept === '' ? 'on' : ''; ?>" href="<?php echo esc_url(add_query_arg('page', 'zw-org', admin_url('admin.php'))); ?>">全部<span class="cnt"><?php echo (int) count($all_people); ?></span></a>
                <?php foreach ($departments as $dept) : ?>
                    <a class="zw-adm-org-tab zw-org-tab <?php echo $current_dept === $dept['key'] ? 'on' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'zw-org', 'dept' => $dept['key']), admin_url('admin.php'))); ?>"><?php echo esc_html($dept['name']); ?><span class="cnt"><?php echo (int) ($dept_counts[$dept['key']] ?? 0); ?></span></a>
                <?php endforeach; ?>
            </div>

            <div class="zw-org-card">
                <div class="zw-org-card-head"><span class="ct">人员名单</span><span class="desc"><?php echo $current_dept === '' ? '全部部门' : esc_html(zw_org_department_label($current_dept)); ?> · <?php echo (int) count($people); ?> 人</span></div>
                <div class="zw-org-card-body" style="padding:0">
                    <?php if (empty($people)) : ?>
                        <div class="zw-org-empty">该部门暂无人员，可使用「手工增加人员名单」或「选择用户」。</div>
                    <?php else : ?>
                        <div class="zw-org-tbl-wrap zw-adm-table-wrap">
                            <table class="zw-adm-org-tbl zw-org-tbl">
                                <thead><tr><th>UID</th><th>姓名</th><th>部门</th><th>角色</th><th>职务</th><th>届次</th><th>任期开始</th><th>任期结束</th><th>政治面貌</th><th>学历 / 学位</th><th>联系电话</th><th>邮箱</th><th>身份证号</th><th>性别 / 年龄 / 出生日期</th><th>账号 / 来源</th><th>操作</th></tr></thead>
                                <tbody>
                                <?php foreach ($people as $person) :
                                    $dept_key = (string) get_post_meta($person->ID, 'zw_person_org_dept', true);
                                    $role_key = (string) get_post_meta($person->ID, 'zw_person_org_role', true);
                                    $user_id  = (int) get_post_meta($person->ID, 'zw_person_user_id', true);
                                    $source   = (string) get_post_meta($person->ID, 'zw_person_source', true);
                                    $meta     = zw_org_person_meta($person->ID);
                                    $term     = function_exists('zw_org_person_term')
                                        ? zw_org_person_term($person->ID)
                                        : array('term_name' => '', 'term_start' => '', 'term_end' => '');
                                    $gender   = $meta['gender_label'] !== '' ? $meta['gender_label'] : '—';
                                    $age      = $meta['age'] !== '' ? $meta['age'] . ' 岁' : '—';
                                    $birthday = $meta['birthday'] !== '' ? $meta['birthday'] : '—';
                                    $id_card  = $meta['id_card_masked'] !== '' ? $meta['id_card_masked'] : '—';
                                    ?>
                                    <tr>
                                        <td class="zw-adm-org-nowrap zw-org-nowrap"><span class="zw-adm-org-muted zw-org-muted">uid：<?php echo (int) $user_id; ?></span></td>
                                        <td>
                                            <strong class="zw-adm-org-nowrap zw-org-nowrap"><?php echo esc_html($person->post_title); ?></strong>
                                            <?php if ($meta['remark'] !== '') : ?><div class="zw-adm-org-muted zw-org-muted"><?php echo esc_html($meta['remark']); ?></div><?php endif; ?>
                                        </td>
                                        <td class="zw-adm-org-nowrap zw-org-nowrap"><?php echo esc_html(zw_org_department_label($dept_key)); ?></td>
                                        <td><?php echo $role_key !== '' ? esc_html(zw_org_role_label($role_key)) : '<span class="zw-adm-org-muted zw-org-muted">未指定</span>'; ?></td>
                                        <td>
                                            <?php echo $meta['duty'] !== '' ? esc_html($meta['duty']) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?>
                                            <?php if (!empty($meta['legal_representative']) || !empty($meta['party_secretary'])) : ?>
                                                <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">
                                                    <?php if (!empty($meta['legal_representative'])) : ?><span class="zw-org-badge on">法定代表人</span><?php endif; ?>
                                                    <?php if (!empty($meta['party_secretary'])) : ?><span class="zw-org-badge on">党支部书记</span><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="zw-adm-org-nowrap zw-org-nowrap"><?php echo $term['term_name'] !== '' ? esc_html($term['term_name']) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                        <td class="zw-adm-org-nowrap zw-org-nowrap"><?php echo $term['term_start'] !== '' ? esc_html($term['term_start']) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                        <td class="zw-adm-org-nowrap zw-org-nowrap"><?php echo $term['term_end'] !== '' ? esc_html($term['term_end']) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                        <td><?php echo $meta['political'] !== '' ? esc_html($meta['political']) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                        <td>
                                            <?php if ($meta['education'] !== '' || $meta['degree'] !== '') : ?>
                                                <?php if ($meta['education'] !== '') : ?><div><?php echo esc_html($meta['education']); ?></div><?php endif; ?>
                                                <?php if ($meta['degree'] !== '') : ?><div class="zw-adm-org-muted zw-org-muted"><?php echo esc_html($meta['degree']); ?></div><?php endif; ?>
                                            <?php else : ?>
                                                <span class="zw-adm-org-muted zw-org-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $meta['phone'] !== '' ? esc_html($meta['phone']) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                        <td><?php echo $meta['email'] !== '' ? esc_html($meta['email']) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                        <td><?php echo esc_html($id_card); ?></td>
                                        <td>
                                            <div><?php echo esc_html($gender); ?></div>
                                            <div class="zw-adm-org-muted zw-org-muted"><?php echo esc_html($age); ?></div>
                                            <div class="zw-adm-org-muted zw-org-muted"><?php echo esc_html($birthday); ?></div>
                                            <?php
                                            // TASK-1598 / T10（R-9）：到龄派生标记（只读展示，**不改业务状态**）。
                                            // 唯一出处 `zw_policy_age_retire_marker()` ⇒ 内部委托 canonical 岗位桥与周岁派生
                                            // （`zw_policy_age_retire_scan_leader()`）；本页**不自行拼岗位词 / 不算周岁**。
                                            // 个人信息最小必要：只出「周岁 / 差额」，不出出生日期与证件号。
                                            if (function_exists('zw_policy_age_retire_marker')) :
                                                $zw_org_age_marker = (array) zw_policy_age_retire_marker(array(
                                                    'role'      => (string) $meta['duty'],
                                                    'age'       => $meta['age'] !== '' ? (int) $meta['age'] : 0,
                                                    'person_id' => 'person-' . (int) $person->ID,
                                                ));
                                                if (!empty($zw_org_age_marker['visible'])) :
                                                    $zw_org_age_tone = (string) ($zw_org_age_marker['tone'] ?? 'off');
                                                    if ($zw_org_age_tone === 'danger') { $zw_org_age_tone = 'err'; }
                                                    ?>
                                                    <div style="margin-top:4px"><span class="zw-adm-badge <?php echo esc_attr($zw_org_age_tone); ?>" title="任职年龄政策条款：<?php echo esc_attr((string) ($zw_org_age_marker['clause'] ?? '')); ?>"><?php echo esc_html((string) ($zw_org_age_marker['label'] ?? '')); ?></span></div>
                                                <?php endif;
                                            endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($user_id > 0) :
                                                $user = get_userdata($user_id);
                                                if ($user) : ?>
                                                    <a href="<?php echo esc_url(get_edit_user_link($user_id)); ?>"><?php echo esc_html($user->display_name); ?> <span class="zw-adm-org-muted zw-org-muted">#<?php echo (int) $user_id; ?></span></a>
                                                <?php else : ?>
                                                    <span class="zw-adm-org-muted zw-org-muted">已删除用户 #<?php echo (int) $user_id; ?></span>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <span class="zw-adm-org-muted zw-org-muted">—</span>
                                            <?php endif; ?>
                                            <div><span class="zw-org-badge <?php echo $source === 'user' ? 'info' : ''; ?>"><?php echo $source === 'user' ? '选择用户' : ($source === 'import' ? '导入' : '手工录入'); ?></span></div>
                                        </td>
                                        <td>
                                            <a class="zw-adm-org-btn zw-org-btn" href="<?php echo esc_url(add_query_arg(array('page' => 'zw-org', 'person' => $person->ID, 'dept' => $current_dept), admin_url('admin.php'))); ?>">编辑</a>
                                            <button type="button" class="zw-adm-org-btn zw-org-btn" data-org-link="<?php echo (int) $person->ID; ?>"><?php echo $user_id > 0 ? '改绑用户' : '关联用户'; ?></button>
                                            <?php if ($user_id > 0) : ?>
                                                <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org', admin_url('admin.php'))); ?>" onsubmit="return confirm('确认解除该人员与 UID <?php echo (int) $user_id; ?> 的关联？');" style="display:inline">
                                                    <?php wp_nonce_field('zw_org_unlink_person_user'); ?>
                                                    <input type="hidden" name="zw_org_action" value="unlink_person_user">
                                                    <input type="hidden" name="person_id" value="<?php echo (int) $person->ID; ?>">
                                                    <input type="hidden" name="dept" value="<?php echo esc_attr($current_dept); ?>">
                                                    <button type="submit" class="zw-adm-org-btn zw-org-btn">解除关联</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org', admin_url('admin.php'))); ?>" onsubmit="return confirm('确认从组织架构中删除该人员？');" style="display:inline">
                                                <?php wp_nonce_field('zw_org_delete_person_' . $person->ID); ?>
                                                <input type="hidden" name="zw_org_action" value="delete_person">
                                                <input type="hidden" name="person_id" value="<?php echo (int) $person->ID; ?>">
                                                <input type="hidden" name="dept" value="<?php echo esc_attr($current_dept); ?>">
                                                <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-danger zw-org-btn-danger">删除</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        (function () {
            document.querySelectorAll('.zw-org-switch input[type=checkbox]').forEach(function (checkbox) {
                var label = checkbox.closest('.zw-org-switch');
                if (!label) { return; }
                function syncSwitch() {
                    label.classList.toggle('on', checkbox.checked);
                }
                checkbox.addEventListener('change', syncSwitch);
                syncSwitch();
            });
            // TASK-2166（回归修复）：面板显隐唯一入口。
            // 双写期（SPEC-10 §1.9.3 阶段 ①）面板标记同时带 canonical `.zw-adm-org-hidden`
            // 与兼容别名 `.zw-org-hidden`，而样式是 `.zw-adm-org-hidden,.zw-org-hidden{display:none}`
            // ⇒ 只翻旧类时 canonical 类仍在场，面板**打不开**（生产实测「关联用户」点击无反应）。
            // 因此显隐必须同时翻两个类；本文件内所有面板显隐都走下面两个函数。
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
            function closePanels(except) {
                document.querySelectorAll('.zw-org-card[id^="zw-org-panel-"]').forEach(function (panel) {
                    if (panel !== except) { setPanelHidden(panel, true); }
                });
            }
            document.querySelectorAll('[data-org-panel]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var panel = document.getElementById(btn.getAttribute('data-org-panel'));
                    if (!panel) { return; }
                    var willOpen = panelIsHidden(panel);
                    closePanels(panel);
                    setPanelHidden(panel, !willOpen);
                    if (willOpen) {
                        var first = panel.querySelector('input,select,textarea');
                        if (first) { first.focus(); }
                    }
                });
            });
            document.querySelectorAll('[data-org-close]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    setPanelHidden(document.getElementById(btn.getAttribute('data-org-close')), true);
                });
            });
            var search = document.getElementById('zw-org-user-search');
            var select = document.getElementById('zw-org-user-select');
            if (search && select) {
                search.addEventListener('input', function () {
                    var q = search.value.toLowerCase().trim();
                    Array.prototype.forEach.call(select.options, function (option) {
                        option.hidden = q !== '' && option.textContent.toLowerCase().indexOf(q) === -1;
                    });
                });
            }

            // TASK-593 / TASK-595：关联用户面板（改为 SPEC-85 canonical 可搜索单选下拉）。
            var linkPerson  = document.getElementById('zw-org-link-person');
            var linkUser    = document.getElementById('zw-org-link-user');
            var linkApi     = window.ZWSearchSelect;

            if (linkApi && linkPerson && linkUser) {
                // 已被其他人员占用的用户不可选；本人已占用的选项保留可选，便于重复保存。
                linkUser.zwDisabledResolver = function (option) {
                    var owner = String((option.attrs && option.attrs['data-owner']) || '0');
                    if (owner === '0') { return false; }
                    return owner !== String(linkApi.getValue(linkPerson) || '');
                };
                linkPerson.zwOnChange = function () { linkApi.refresh(linkUser); };
                linkApi.refresh(linkUser);
            }

            document.querySelectorAll('[data-org-link]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var panel = document.getElementById('zw-org-panel-link');
                    if (!panel) { return; }
                    if (linkApi && linkPerson) {
                        linkApi.setValue(linkPerson, String(btn.getAttribute('data-org-link') || ''), true);
                        linkApi.refresh(linkUser);
                    }
                    closePanels(panel);
                    setPanelHidden(panel, false);
                    var linkSearch = document.getElementById('zw-org-link-user-input');
                    if (linkSearch) { linkSearch.focus(); }
                });
            });

            function parseIdCard(input) {
                var id = (input || '').toUpperCase().replace(/\s/g, '');
                var birthday, genderDigit;
                if (/^\d{17}[\dX]$/.test(id)) {
                    var weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
                    var checkCodes = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
                    var sum = 0;
                    for (var i = 0; i < 17; i++) { sum += parseInt(id.charAt(i), 10) * weights[i]; }
                    if (id.charAt(17) !== checkCodes[sum % 11]) { return null; }
                    birthday = id.substr(6, 4) + '-' + id.substr(10, 2) + '-' + id.substr(12, 2);
                    genderDigit = parseInt(id.charAt(16), 10);
                } else if (/^\d{15}$/.test(id)) {
                    birthday = '19' + id.substr(6, 2) + '-' + id.substr(8, 2) + '-' + id.substr(10, 2);
                    genderDigit = parseInt(id.charAt(14), 10);
                } else {
                    return null;
                }
                var parts = birthday.split('-');
                var date = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                if (date.getFullYear() !== parseInt(parts[0], 10) || (date.getMonth() + 1) !== parseInt(parts[1], 10) || date.getDate() !== parseInt(parts[2], 10)) {
                    return null;
                }
                var today = new Date();
                var age = today.getFullYear() - date.getFullYear();
                var m = today.getMonth() - date.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < date.getDate())) { age--; }
                return {
                    gender: (genderDigit % 2 === 1) ? 'male' : 'female',
                    gender_label: (genderDigit % 2 === 1) ? '男' : '女',
                    birthday: birthday,
                    age: age >= 0 ? age : ''
                };
            }

            document.querySelectorAll('.zw-org-person-form .zw-org-person-id-card').forEach(function (input) {
                function sync() {
                    var form = input.closest('.zw-org-person-form');
                    if (!form) { return; }
                    var genderKey = form.querySelector('.zw-org-auto-gender-key');
                    var gender = form.querySelector('.zw-org-auto-gender');
                    var birthday = form.querySelector('.zw-org-auto-birthday');
                    var age = form.querySelector('.zw-org-auto-age');
                    var msg = form.querySelector('.zw-org-id-card-msg');
                    var info = parseIdCard(input.value);
                    if (input.value.trim() === '') {
                        if (genderKey) { genderKey.value = ''; }
                        if (gender) { gender.value = ''; }
                        if (birthday) { birthday.value = ''; }
                        if (age) { age.value = ''; }
                        if (msg) { msg.textContent = ''; }
                        return;
                    }
                    if (!info) {
                        if (genderKey) { genderKey.value = ''; }
                        if (gender) { gender.value = ''; }
                        if (birthday) { birthday.value = ''; }
                        if (age) { age.value = ''; }
                        if (msg) { msg.textContent = '身份证号格式或校验位不正确。'; msg.style.color = '#c62828'; }
                        return;
                    }
                    if (genderKey) { genderKey.value = info.gender; }
                    if (gender) { gender.value = info.gender_label; }
                    if (birthday) { birthday.value = info.birthday; }
                    if (age) { age.value = info.age === '' ? '' : info.age + ' 岁'; }
                    if (msg) { msg.textContent = '已根据身份证号自动推算。'; msg.style.color = '#2e7d32'; }
                }
                input.addEventListener('input', sync);
                input.addEventListener('blur', sync);
            });
        })();
        </script>
        <?php
    }
}
