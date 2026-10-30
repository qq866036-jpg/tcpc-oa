<?php
/**
 * 组织架构 · 后台入口与表单处理
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('is_admin') || (!is_admin() && !(defined('WP_CLI') && WP_CLI))) {
    return;
}


require_once __DIR__ . '/org-repository.php';
// OA-2434（R6b）**与主题侧有意差异**（CHG-434）：主题侧这里是 `__DIR__ . '/org-files.php'`。
// 插件侧该文件已按模块拆分迁到 `includes/files/`（R6 / OA-2433），同目录不存在 ⇒ 启用即 fatal。
// 只改引用路径，不改文件内容。
require_once dirname(__DIR__) . '/files/org-files.php';
require_once __DIR__ . '/org-admin-people.php';
require_once __DIR__ . '/org-admin-roles.php';

add_action('admin_init', 'zw_org_maybe_migrate_data', 5);

if (!defined('ZW_ORG_ADMIN_VERSION')) {
    define('ZW_ORG_ADMIN_VERSION', '2026-09-21.2');
}

if (!function_exists('zw_org_search_users_nonce_action')) {
    /**
     * 「关联用户」服务端搜索的 nonce action（页面与端点共用）。
     *
     * @return string
     */
    function zw_org_search_users_nonce_action() {
        return 'zw_org_search_users';
    }
}

if (!function_exists('zw_org_ajax_search_users')) {
    /**
     * admin-ajax：按关键词搜索可选用户（TASK-598）。
     *
     * 生产 1336 个用户不能整表预载进后台页面，改为管理员输入关键词时现查：
     * 仅具备 `manage_options` 的登录用户可调用，且校验 nonce；返回条数由
     * `zw_org_search_users_for_link()` 限制在 50 以内，只回必要字段。
     *
     * @return void
     */
    function zw_org_ajax_search_users() {
        // canonical search-select 远程请求体用 `nonce` 字段（TASK-606 修正）。
        check_ajax_referer(zw_org_search_users_nonce_action(), 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => '无权限。'), 403);
        }
        $keyword = isset($_REQUEST['q']) ? sanitize_text_field(wp_unslash($_REQUEST['q'])) : '';
        // 关键词长度上限，避免超长输入造成无意义的全表 LIKE。
        $keyword = function_exists('mb_substr') ? mb_substr($keyword, 0, 50, 'UTF-8') : substr($keyword, 0, 50);
        wp_send_json_success(array('options' => zw_org_search_users_for_link($keyword, 30)));
    }
}

add_action('wp_ajax_zw_org_search_users', 'zw_org_ajax_search_users');

if (!function_exists('zw_org_admin_pages')) {
    /**
     * 本模块后台页面 slug。
     *
     * @return array
     */
    function zw_org_admin_pages() {
        return array('zw-org', 'zw-org-roles', 'zw-org-files');
    }
}

if (!function_exists('zw_org_is_admin_page')) {
    /**
     * 当前请求是否属于组织架构后台页。
     *
     * @return bool
     */
    function zw_org_is_admin_page() {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        return in_array($page, zw_org_admin_pages(), true);
    }
}

if (!function_exists('zw_org_admin_notice')) {
    /**
     * 写入一次性提示。
     *
     * @param string $type success|error。
     * @param string $text 文案。
     * @return void
     */
    function zw_org_admin_notice($type, $text) {
        if (!get_current_user_id()) {
            return;
        }
        set_transient('zw_org_notice_' . get_current_user_id(), array(
            'type' => $type === 'error' ? 'error' : 'success',
            'text' => (string) $text,
        ), 45);
    }
}

if (!function_exists('zw_org_admin_take_notice')) {
    /**
     * 取出并清理一次性提示。
     *
     * @return array|null
     */
    function zw_org_admin_take_notice() {
        $uid = get_current_user_id();
        if (!$uid) {
            return null;
        }
        $notice = get_transient('zw_org_notice_' . $uid);
        if (!is_array($notice) || empty($notice['text'])) {
            return null;
        }
        delete_transient('zw_org_notice_' . $uid);
        return $notice;
    }
}

if (!function_exists('zw_org_admin_redirect')) {
    /**
     * 处理完成后跳转。
     *
     * @param string $page 页面 slug。
     * @param array  $args 额外查询参数。
     * @return void
     */
    function zw_org_admin_redirect($page = 'zw-org', $args = array()) {
        $args = array_merge(array('page' => $page), $args);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}

if (!function_exists('zw_org_admin_menu')) {
    /**
     * 注册「组织架构」顶级菜单和子页面。
     *
     * @return void
     */
    function zw_org_admin_menu() {
        add_menu_page(
            '组织架构',
            '组织架构',
            'tcpc_perm_system',
            'zw-org',
            'zw_org_page_people',
            'dashicons-id-alt',
            // OA-2451 / 主题 TASK-2476：办公协同号段 59–60.9 的**末位** 60.7（用户拍板顺序最后一名：
            // 收文 → 发文 → AI秘书处 → AI法务部 → 网盘文件 → 组织架构）。
            60.7
        );

        add_submenu_page(
            'zw-org',
            '组织与人员',
            '组织与人员',
            'tcpc_perm_system',
            'zw-org',
            'zw_org_page_people'
        );

        // TASK-1289：届次周期收敛为「组织架构」下的全站唯一管理入口
        // （原入口：会员管理 → 认证审核 → Tab「届次周期」；原旧独立页 zw-member-term-cycles 已 301 到此）。
        add_submenu_page(
            'zw-org',
            '届次周期',
            '届次周期',
            'tcpc_perm_system',
            'zw-org-term-cycles',
            'zw_org_page_term_cycles'
        );

        add_submenu_page(
            'zw-org',
            '角色与权限',
            '角色与权限',
            'tcpc_perm_system',
            'zw-org-roles',
            'zw_org_page_roles'
        );
    }
}
add_action('admin_menu', 'zw_org_admin_menu', 60);

if (!function_exists('zw_org_page_term_cycles')) {
    /**
     * 「组织架构 → 届次周期」页面（TASK-1289，全站唯一管理入口）。
     *
     * 数据读写全部走 canonical `inc/term-cycles.php`（`zw_term_cycles()` / `zw_term_cycles_save()`），
     * 本页不得直接 `get_option` / `update_option`。
     *
     * @return void
     */
    function zw_org_page_term_cycles() {
        if (!current_user_can('tcpc_perm_system')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }

        $saved  = !empty($_GET['zw_saved']);
        $cycles = function_exists('zw_term_cycles') ? zw_term_cycles() : array();
        $notice = isset($_GET['zw_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['zw_notice'])) : '';
        ?>
        <div class="wrap zw-adm-org-term-cycles zw-org-term-cycles">
            <style>
            /* TASK-1646（TASK-1579 P2-1 批次 3 / SPEC-10 §1.9.3 第 2/3 项；机制出处 CHG-300）：
               下列旧类为**兼容别名** —— canonical 规则的选择器组**同时承载**新类与旧类，
               旧类的历史声明**原位保留**（声明体逐字节未改 ⇒ 零取值变化）；
               标记面同时携带新类与旧类（双写）。**双写一个发布周期内不得删除任何旧类**。
               [已废弃 → 见 .zw-adm-org-term-cycles] 旧类 .zw-org-term-cycles */
            .zw-adm-org-term-cycles .zwtc-card,.zw-org-term-cycles .zwtc-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin:16px 0}
            .zw-adm-org-term-cycles .zwtc-card-head,.zw-org-term-cycles .zwtc-card-head{padding:10px 14px;border-bottom:1px solid #f0f1f3;background:#fafbfc;font-weight:600;color:#1d2327;border-radius:12px 12px 0 0}
            .zw-adm-org-term-cycles .zwtc-card-body,.zw-org-term-cycles .zwtc-card-body{padding:14px}
            .zw-adm-org-term-cycles .zwtc-tbl,.zw-org-term-cycles .zwtc-tbl{width:100%;border-collapse:collapse}
            .zw-adm-org-term-cycles .zwtc-tbl th,.zw-org-term-cycles .zwtc-tbl th{text-align:left;font-weight:600;color:#50575e;font-size:12.5px;padding:8px 6px;border-bottom:1px solid #e5e7eb}
            .zw-adm-org-term-cycles .zwtc-tbl td,.zw-org-term-cycles .zwtc-tbl td{padding:8px 6px;border-bottom:1px solid #f0f1f3;vertical-align:middle}
            .zw-adm-org-term-cycles .zwtc-hint,.zw-org-term-cycles .zwtc-hint{margin:0 0 10px;padding:8px 10px;background:#fafbfc;border-left:3px solid var(--primary-color,#d80505);color:#50575e;font-size:12.5px;line-height:1.6}
            .zw-adm-org-term-cycles .zwtc-muted,.zw-org-term-cycles .zwtc-muted{color:#8c8f94;font-size:12.5px}
            <?php
            // TASK-1632（TASK-1579 §7 P1-6 余项）：宽表横滑包裹唯一出处＝canonical
            // （inc/admin/admin-ui-tokens.php 的 zw_adm_ui_table_wrap_css()）。
            // 「组织架构」域不在会员域外壳作用域内（外壳前缀判定 zw-mc / zw-member / zw-policy / zw-notify）
            // ⇒ 须在本页**既有**内联 <style> 内输出该 canonical 规则；**未**新增全局 CSS 文件、**未**复制第二套声明。
            echo zw_adm_ui_table_wrap_css();
            // TASK-1637（P1-4 余项 / 用户拍板②）：同域不继承 `.zw-adm-tcpc` ⇒ 以 `body` 作用域输出
            // canonical 触屏块（唯一出处 `zw_adm_ui_touch_css()`；仅 `@media(pointer:coarse)` 命中）。
            echo function_exists('zw_adm_ui_touch_css') ? zw_adm_ui_touch_css('body') : '';
            ?>
            </style>
            <h1>届次周期</h1>
            <p class="zwtc-hint">
                <strong>全站唯一入口</strong>：届次周期（届次名称 + 任期起止）只在这里维护。
                会员管理「理事证有效期」按证书的<strong>所属届次</strong>取本页配置的任期结束日判定是否过期；
                组织架构人员的任期默认值同样取自本页。原「会员管理 → 认证审核 → 届次周期」入口已迁移至此。
            </p>
            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p>届次周期已保存。</p></div>
            <?php elseif ($notice !== '') : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=zw-org-term-cycles')); ?>" id="zwtc-form">
                <?php wp_nonce_field('zw_org_term_cycles_save'); ?>
                <input type="hidden" name="zw_org_action" value="save_term_cycles">
                <div class="zwtc-card">
                    <div class="zwtc-card-head">届次与任期</div>
                    <div class="zwtc-card-body">
                        <div class="zw-adm-table-wrap">
                        <table class="zwtc-tbl" id="zwtc-table">
                            <thead><tr><th style="width:34%">届次名称</th><th style="width:24%">任期开始</th><th style="width:24%">任期结束（到期日）</th><th style="width:18%">操作</th></tr></thead>
                            <tbody>
                            <?php foreach ((array) $cycles as $i => $c) : ?>
                                <tr>
                                    <td><input type="text" name="zw_terms[<?php echo (int) $i; ?>][term]" value="<?php echo esc_attr((string) ($c['term'] ?? '')); ?>" style="width:100%" placeholder="如 第六届理事会"></td>
                                    <td><input type="date" name="zw_terms[<?php echo (int) $i; ?>][start]" value="<?php echo esc_attr((string) ($c['start'] ?? '')); ?>"></td>
                                    <td><input type="date" name="zw_terms[<?php echo (int) $i; ?>][end]" value="<?php echo esc_attr((string) ($c['end'] ?? '')); ?>"></td>
                                    <td><button type="button" class="button zwtc-del">删除</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <p style="margin:12px 0 0">
                            <button type="button" class="button" id="zwtc-add">+ 添加届次</button>
                            <button type="submit" class="button button-primary">保存届次</button>
                        </p>
                        <p class="zwtc-muted" style="margin:10px 0 0">
                            留空的届次名称不会保存；任期起止必须是 <code>yyyy-mm-dd</code>，非法日期会被清空（该届次仍保留）。
                            理事证没有「有效期至」字段，按上述对应关系判过期。
                        </p>
                    </div>
                </div>
            </form>
            <script>
            (function () {
                var tb = document.querySelector('#zwtc-table tbody');
                if (!tb) { return; }
                var i = <?php echo (int) count((array) $cycles); ?>;
                document.getElementById('zwtc-add').addEventListener('click', function () {
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td><input type="text" name="zw_terms[' + i + '][term]" style="width:100%" placeholder="如 第七届理事会"></td>'
                        + '<td><input type="date" name="zw_terms[' + i + '][start]"></td>'
                        + '<td><input type="date" name="zw_terms[' + i + '][end]"></td>'
                        + '<td><button type="button" class="button zwtc-del">删除</button></td>';
                    tb.appendChild(tr);
                    i++;
                });
                tb.addEventListener('click', function (e) {
                    if (e.target.classList && e.target.classList.contains('zwtc-del')) {
                        var tr = e.target.closest('tr');
                        if (tr) { tr.parentNode.removeChild(tr); }
                    }
                });
            })();
            </script>
        </div>
        <?php
    }
}

if (!function_exists('zw_org_term_cycles_handle_post')) {
    /**
     * 处理「组织架构 → 届次周期」提交（TASK-1289）。
     *
     * 唯一写入口：`zw_term_cycles_save()`（canonical）。本函数只做 nonce / 权限 / 清洗编排。
     *
     * @return void
     */
    function zw_org_term_cycles_handle_post() {
        if (!is_admin()) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== 'zw-org-term-cycles') {
            return;
        }
        $action = isset($_POST['zw_org_action']) ? sanitize_key((string) wp_unslash($_POST['zw_org_action'])) : '';
        if ($action !== 'save_term_cycles') {
            return;
        }
        if (!current_user_can('tcpc_perm_system')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        check_admin_referer('zw_org_term_cycles_save');
        if (!function_exists('zw_term_cycles_save') || !function_exists('zw_term_cycles')) {
            wp_safe_redirect(add_query_arg(
                array('page' => 'zw-org-term-cycles', 'zw_notice' => rawurlencode('届次周期模块未加载，保存失败。')),
                admin_url('admin.php')
            ));
            exit;
        }

        $rows = isset($_POST['zw_terms']) && is_array($_POST['zw_terms']) ? wp_unslash($_POST['zw_terms']) : array();
        zw_term_cycles_save($rows);
        zw_org_admin_redirect('zw-org-term-cycles', array('zw_saved' => 1));
    }
}
add_action('admin_init', 'zw_org_term_cycles_handle_post', 20);

if (!function_exists('zw_org_admin_place_menus')) {
    /**
     * 将自动生成的顶级子项改名为「组织与人员」，并保持角色与权限子页顺序。
     *
     * @return void
     */
    function zw_org_admin_place_menus() {
        global $submenu;
        if (empty($submenu['zw-org']) || !is_array($submenu['zw-org'])) {
            return;
        }
        $by_slug = array();
        foreach ($submenu['zw-org'] as $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            if ($slug !== '') {
                $by_slug[$slug] = $item;
            }
        }
        if (!empty($by_slug['zw-org'])) {
            $by_slug['zw-org'][0] = '组织与人员';
        }
        $ordered = array();
        if (!empty($by_slug['zw-org'])) {
            $ordered[] = $by_slug['zw-org'];
        }
        // TASK-1289：届次周期排在「组织与人员」与「角色与权限」之间。
        if (!empty($by_slug['zw-org-term-cycles'])) {
            $ordered[] = $by_slug['zw-org-term-cycles'];
        }
        if (!empty($by_slug['zw-org-roles'])) {
            $ordered[] = $by_slug['zw-org-roles'];
        }
        foreach ($submenu['zw-org'] as $item) {
            $slug = isset($item[2]) ? (string) $item[2] : '';
            if (in_array($slug, array('zw-org', 'zw-org-term-cycles', 'zw-org-roles'), true)) {
                continue;
            }
            $ordered[] = $item;
        }
        $submenu['zw-org'] = array_values($ordered);
    }
}
add_action('admin_menu', 'zw_org_admin_place_menus', 99);

if (!function_exists('zw_org_person_post_args')) {
    /**
     * 从人员表单收集标准参数。
     *
     * @return array
     */
    function zw_org_person_post_args() {
        return array(
            'name'         => isset($_POST['person_name']) ? sanitize_text_field(wp_unslash($_POST['person_name'])) : '',
            'dept'         => isset($_POST['person_dept']) ? sanitize_key(wp_unslash($_POST['person_dept'])) : '',
            'duty'         => isset($_POST['person_duty']) ? sanitize_text_field(wp_unslash($_POST['person_duty'])) : '',
            'legal_representative' => isset($_POST['person_legal_representative']) ? absint($_POST['person_legal_representative']) : 0,
            'party_secretary'      => isset($_POST['person_party_secretary']) ? absint($_POST['person_party_secretary']) : 0,
            'id_card'      => isset($_POST['person_id_card']) ? sanitize_text_field(wp_unslash($_POST['person_id_card'])) : '',
            'political'    => isset($_POST['person_political']) ? sanitize_text_field(wp_unslash($_POST['person_political'])) : '',
            'education'    => isset($_POST['person_education']) ? sanitize_text_field(wp_unslash($_POST['person_education'])) : '',
            'degree'       => isset($_POST['person_degree']) ? sanitize_text_field(wp_unslash($_POST['person_degree'])) : '',
            'company'      => isset($_POST['person_company']) ? sanitize_text_field(wp_unslash($_POST['person_company'])) : '',
            'company_duty' => isset($_POST['person_company_duty']) ? sanitize_text_field(wp_unslash($_POST['person_company_duty'])) : '',
            'phone'        => isset($_POST['person_phone']) ? sanitize_text_field(wp_unslash($_POST['person_phone'])) : '',
            'email'        => isset($_POST['person_email']) ? sanitize_email(wp_unslash($_POST['person_email'])) : '',
            'address'      => isset($_POST['person_address']) ? sanitize_text_field(wp_unslash($_POST['person_address'])) : '',
            'gender'       => isset($_POST['person_gender']) ? sanitize_key(wp_unslash($_POST['person_gender'])) : '',
            'birthday'     => isset($_POST['person_birthday']) ? sanitize_text_field(wp_unslash($_POST['person_birthday'])) : '',
            'remark'       => isset($_POST['person_remark']) ? sanitize_text_field(wp_unslash($_POST['person_remark'])) : '',
            'photo_file_id'=> isset($_POST['person_photo_file_id']) ? absint($_POST['person_photo_file_id']) : 0,
            // TASK-1291：组织架构人员任期（届次 / 任期开始 / 任期结束）。
            'term_name'    => isset($_POST['person_term_name']) ? sanitize_text_field(wp_unslash($_POST['person_term_name'])) : '',
            'term_start'   => isset($_POST['person_term_start']) ? sanitize_text_field(wp_unslash($_POST['person_term_start'])) : '',
            'term_end'     => isset($_POST['person_term_end']) ? sanitize_text_field(wp_unslash($_POST['person_term_end'])) : '',
            'source'       => 'manual',
        );
    }
}

if (!function_exists('zw_org_person_resolve_photo_upload')) {
    /**
     * 处理人员表单中的照片上传；未上传时沿用现有文件 ID。
     *
     * @param int $current_file_id 当前照片文件 ID。
     * @return int|WP_Error
     */
    function zw_org_person_resolve_photo_upload($current_file_id) {
        $current_file_id = (int) $current_file_id;
        if (empty($_FILES['person_photo']) || !isset($_FILES['person_photo']['error'])) {
            return $current_file_id;
        }
        $error = (int) $_FILES['person_photo']['error'];
        if ($error === UPLOAD_ERR_NO_FILE) {
            return $current_file_id;
        }
        $ext = strtolower(pathinfo((string) ($_FILES['person_photo']['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) {
            return new WP_Error('zw_org_photo_invalid_type', '人员照片仅支持 jpg/png/gif/webp 图片。');
        }
        $file_id = zw_org_files_store_upload($_FILES['person_photo'], 'person_photo', 0, '人员照片');
        return is_wp_error($file_id) ? $file_id : (int) $file_id;
    }
}

if (!function_exists('zw_org_admin_handle_post')) {
    /**
     * admin_init 阶段统一处理组织架构表单。
     *
     * @return void
     */
    function zw_org_admin_handle_post() {
        if (!is_admin() || !current_user_can('manage_options') || !zw_org_is_admin_page()) {
            return;
        }

        $action = isset($_POST['zw_org_action']) ? sanitize_key((string) wp_unslash($_POST['zw_org_action'])) : '';
        if ($action === '') {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : 'zw-org';
        $dept = isset($_POST['dept']) ? sanitize_key((string) wp_unslash($_POST['dept'])) : '';

        if ($action === 'add_department') {
            check_admin_referer('zw_org_add_department');
            $name  = isset($_POST['dept_name']) ? wp_unslash($_POST['dept_name']) : '';
            $order = isset($_POST['dept_order']) ? (int) $_POST['dept_order'] : 0;
            $result = zw_org_add_department($name, $order);
            if (is_wp_error($result)) {
                zw_org_admin_notice('error', $result->get_error_message());
            } else {
                zw_org_admin_notice('success', '部门已增加。');
            }
            zw_org_admin_redirect('zw-org');
        }

        if ($action === 'add_person') {
            check_admin_referer('zw_org_add_person');
            $args   = zw_org_person_post_args();
            $photo  = zw_org_person_resolve_photo_upload($args['photo_file_id']);
            if (is_wp_error($photo)) {
                zw_org_admin_notice('error', $photo->get_error_message());
                zw_org_admin_redirect('zw-org', array('dept' => $args['dept']));
            }
            $args['photo_file_id'] = (int) $photo;
            $result = zw_org_insert_person($args);
            if (is_wp_error($result)) {
                zw_org_admin_notice('error', $result->get_error_message());
            } else {
                if ($args['photo_file_id'] > 0) {
                    update_post_meta($args['photo_file_id'], 'zw_file_person_id', $result['id']);
                }
                zw_org_admin_notice('success', '人员已保存。');
            }
            zw_org_admin_redirect('zw-org', array('dept' => $args['dept']));
        }

        if ($action === 'update_person') {
            $person_id = isset($_POST['person_id']) ? absint($_POST['person_id']) : 0;
            check_admin_referer('zw_org_update_person_' . $person_id);
            $args                = zw_org_person_post_args();
            $args['person_id']   = $person_id;
            $photo               = zw_org_person_resolve_photo_upload($args['photo_file_id']);
            if (is_wp_error($photo)) {
                zw_org_admin_notice('error', $photo->get_error_message());
                zw_org_admin_redirect('zw-org', array('dept' => $args['dept']));
            }
            $args['photo_file_id'] = (int) $photo;
            $result                = zw_org_insert_person($args);
            if (is_wp_error($result)) {
                zw_org_admin_notice('error', $result->get_error_message());
            } else {
                if ($args['photo_file_id'] > 0) {
                    update_post_meta($args['photo_file_id'], 'zw_file_person_id', $person_id);
                }
                zw_org_admin_notice('success', '人员信息已更新。');
            }
            zw_org_admin_redirect('zw-org', array('dept' => $args['dept']));
        }

        if ($action === 'upload_file') {
            check_admin_referer('zw_org_upload_file');
            $file = isset($_FILES['office_file']) ? $_FILES['office_file'] : array();
            // TASK-953：归档目录由表单选择（外部政策 / 内部政策 / 办公文档…）；
            // 未知值由 zw_org_files_create_record() 的白名单回退为 document。
            $kind = isset($_POST['file_kind']) ? sanitize_key(wp_unslash((string) $_POST['file_kind'])) : 'document';
            $id   = zw_org_files_store_upload($file, $kind, 0, isset($_POST['file_title']) ? sanitize_text_field(wp_unslash($_POST['file_title'])) : '');
            if (is_wp_error($id)) {
                zw_org_admin_notice('error', $id->get_error_message());
            } else {
                zw_org_admin_notice('success', '文件已上传到网盘文件。');
            }
            zw_org_admin_redirect('zw-org-files');
        }

        if ($action === 'delete_file') {
            $file_id = isset($_POST['file_id']) ? absint($_POST['file_id']) : 0;
            check_admin_referer('zw_org_delete_file_' . $file_id);
            $deleted = zw_org_files_delete($file_id);
            zw_org_admin_notice($deleted ? 'success' : 'error', $deleted ? '文件已删除。' : '文件不存在或删除失败。');
            zw_org_admin_redirect('zw-org-files');
        }

        if ($action === 'add_users') {
            check_admin_referer('zw_org_add_users');
            $duty     = isset($_POST['user_duty']) ? sanitize_text_field(wp_unslash($_POST['user_duty'])) : '';
            $user_ids = isset($_POST['user_ids']) ? (array) wp_unslash($_POST['user_ids']) : array();
            $user_ids = array_values(array_unique(array_filter(array_map('absint', $user_ids))));
            if (empty($user_ids)) {
                zw_org_admin_notice('error', '请至少选择一位用户。');
                zw_org_admin_redirect('zw-org', array('dept' => $dept));
            }
            $added = 0;
            $failed = 0;
            foreach ($user_ids as $user_id) {
                $user = get_userdata($user_id);
                if (!$user) {
                    $failed++;
                    continue;
                }
                $name = $user->display_name !== '' ? $user->display_name : $user->user_login;
                $existing_id = zw_org_find_person_by_user($user_id, $dept);
                if ($existing_id > 0) {
                    $meta = zw_org_person_meta($existing_id);
                    $result = zw_org_insert_person(array(
                        'person_id'    => $existing_id,
                        'name'         => $name,
                        'dept'         => $dept,
                        'duty'         => $duty,
                        'user_id'      => $user_id,
                        'phone'        => $meta['phone'],
                        'email'        => $meta['email'],
                        'address'      => $meta['address'],
                        'id_card'      => $meta['id_card'],
                        'political'    => $meta['political'],
                        'education'    => $meta['education'],
                        'degree'       => $meta['degree'],
                        'company'      => $meta['company'],
                        'company_duty' => $meta['company_duty'],
                        'gender'       => $meta['gender'],
                        'birthday'     => $meta['birthday'],
                        'remark'       => $meta['remark'],
                        'photo_file_id'=> $meta['photo_file_id'],
                        'source'       => 'user',
                    ));
                } else {
                    $result = zw_org_insert_person(array(
                        'name'    => $name,
                        'dept'    => $dept,
                        'duty'    => $duty,
                        'user_id' => $user_id,
                        'source'  => 'user',
                    ));
                }
                if (is_wp_error($result)) {
                    $failed++;
                } else {
                    $added++;
                }
            }
            $msg = $failed > 0 ? "已关联 {$added} 位用户，{$failed} 位失败。" : "已关联 {$added} 位用户。";
            zw_org_admin_notice($failed > 0 ? 'error' : 'success', $msg);
            zw_org_admin_redirect('zw-org', array('dept' => $dept));
        }

        // TASK-593：给已有人员补绑 / 更换 UID（存量导入人员此前只能靠 WP-CLI 绑定）。
        if ($action === 'link_person_user') {
            check_admin_referer('zw_org_link_person_user');
            $person_id = isset($_POST['person_id']) ? absint($_POST['person_id']) : 0;
            $user_id   = isset($_POST['person_user_id']) ? absint($_POST['person_user_id']) : 0;
            $result    = zw_org_link_person_user($person_id, $user_id);
            if (is_wp_error($result)) {
                zw_org_admin_notice('error', $result->get_error_message());
            } else {
                $user  = get_userdata($user_id);
                $label = $user ? $user->display_name : ('#' . $user_id);
                zw_org_admin_notice('success', sprintf('已把「%s」关联到用户 %s（UID %d）。', get_the_title($person_id), $label, $user_id));
            }
            zw_org_admin_redirect('zw-org', array('dept' => $dept));
        }

        // TASK-593：解除人员与 UID 的关联（绑错时无需命令行即可回退）。
        if ($action === 'unlink_person_user') {
            check_admin_referer('zw_org_unlink_person_user');
            $person_id = isset($_POST['person_id']) ? absint($_POST['person_id']) : 0;
            $result    = zw_org_unlink_person_user($person_id);
            if (is_wp_error($result)) {
                zw_org_admin_notice('error', $result->get_error_message());
            } else {
                zw_org_admin_notice('success', sprintf('已解除「%s」与 UID %d 的关联。', get_the_title($person_id), (int) $result['user_id']));
            }
            zw_org_admin_redirect('zw-org', array('dept' => $dept));
        }

        if ($action === 'add_duty') {
            check_admin_referer('zw_org_add_duty');
            $name  = isset($_POST['duty_name']) ? wp_unslash($_POST['duty_name']) : '';
            $order = isset($_POST['duty_order']) ? (int) $_POST['duty_order'] : 0;
            $desc  = isset($_POST['duty_description']) ? wp_unslash($_POST['duty_description']) : '';
            $result = zw_org_add_duty($name, $order, $desc);
            if (is_wp_error($result)) {
                zw_org_admin_notice('error', $result->get_error_message());
            } else {
                zw_org_admin_notice('success', '职务已增加。');
            }
            zw_org_admin_redirect('zw-org-roles');
        }

        if ($action === 'add_role') {
            check_admin_referer('zw_org_add_role');
            $name  = isset($_POST['role_name']) ? wp_unslash($_POST['role_name']) : '';
            $order = isset($_POST['role_order']) ? (int) $_POST['role_order'] : 0;
            $desc  = isset($_POST['role_description']) ? wp_unslash($_POST['role_description']) : '';
            $result = zw_org_add_role($name, $order, $desc);
            if (is_wp_error($result)) {
                zw_org_admin_notice('error', $result->get_error_message());
            } else {
                // TASK-2252：新角色已带 wp_role ⇒ 立即建 WP 角色，避免「建完就是死角色」。
                if (function_exists('zw_org_sync_wp_roles')) {
                    zw_org_sync_wp_roles();
                }
                zw_org_admin_notice('success', '角色已增加，后续可继续扩展权限说明。');
            }
            zw_org_admin_redirect('zw-org-roles');
        }

        if ($action === 'update_role') {
            check_admin_referer('zw_org_update_role');
            $role_key = isset($_POST['role_key']) ? sanitize_key(wp_unslash($_POST['role_key'])) : '';
            $rows     = zw_org_roles();
            $updated  = false;
            foreach ($rows as &$row) {
                if (($row['key'] ?? '') !== $role_key) {
                    continue;
                }
                $row['name']        = isset($_POST['role_name']) ? sanitize_text_field(wp_unslash($_POST['role_name'])) : $row['name'];
                $row['wp_role']     = isset($_POST['role_wp_role']) ? sanitize_key(wp_unslash($_POST['role_wp_role'])) : ($row['wp_role'] ?? '');
                $row['order']       = isset($_POST['role_order']) ? (int) $_POST['role_order'] : (int) ($row['order'] ?? 0);
                $row['description'] = isset($_POST['role_description']) ? sanitize_text_field(wp_unslash($_POST['role_description'])) : ($row['description'] ?? '');
                $permissions = isset($_POST['role_permissions']) ? (array) wp_unslash($_POST['role_permissions']) : array();
                $permissions = array_values(array_intersect(array_map('sanitize_key', $permissions), zw_org_all_permission_keys()));
                $row['permissions'] = $permissions;
                // TASK-2252：历史「死角色」（后台模块角色但 wp_role 为空）在保存时**自动补齐**，
                // 否则用户在界面上勾了权限、保存成功，实际仍不生效（三处同步继续跳过）。
                if (function_exists('zw_org_role_needs_wp_role') && zw_org_role_needs_wp_role($row)) {
                    $row['wp_role'] = zw_org_role_wp_role_key((string) ($row['key'] ?? ''));
                }
                // TASK-602：纯前端分支机构角色保存「前端机构能力」；scope 由角色定义保留，不随表单清空。
                // TASK-1481：两个机构前端角色的角色层能力位**恒为全量授权项**（`branch-front-roles.php` 读取时
                // 忽略存储值），前端机构「权限管理」才是逐人差异的唯一写入口 ⇒ 本页提交**不得**清空存储
                // （否则只是把历史数据抹成空、且无任何生效作用）。故此处对 scope=branch 一律跳过写 front_caps。
                if (!empty($row['scope']) && (string) $row['scope'] === 'branch') {
                    // 保留既有 `front_caps` 原值（历史留痕），角色级勾选已停用。
                }
                $people = isset($_POST['role_people']) ? (array) wp_unslash($_POST['role_people']) : array();
                $row['people'] = zw_org_save_role_people($role_key, $people);
                $updated = true;
                break;
            }
            unset($row);
            if ($updated) {
                zw_org_save_roles($rows);
                if (function_exists('zw_org_sync_wp_roles')) {
                    zw_org_sync_wp_roles();
                }
                foreach ($rows as $saved_role) {
                    if (($saved_role['key'] ?? '') === $role_key && function_exists('zw_org_sync_role_people_to_users')) {
                        zw_org_sync_role_people_to_users($saved_role);
                        break;
                    }
                }
                zw_org_admin_notice('success', '角色已更新，关联用户的 WP 角色与后台能力已同步。');
            } else {
                zw_org_admin_notice('error', '角色不存在。');
            }
            zw_org_admin_redirect('zw-org-roles');
        }

        if ($action === 'update_permission_module') {
            check_admin_referer('zw_org_update_permission_module');
            $module_key = isset($_POST['module_key']) ? sanitize_key(wp_unslash($_POST['module_key'])) : '';
            $modules    = zw_org_permission_modules();
            if (isset($modules[$module_key])) {
                $functions = isset($_POST['module_functions']) ? (array) wp_unslash($_POST['module_functions']) : array();
                $functions = array_values(array_unique(array_filter(array_map('sanitize_text_field', $functions))));
                $allowed   = zw_org_admin_function_ids(zw_org_admin_function_tree());
                if (!empty($allowed)) {
                    $functions = array_values(array_intersect($functions, $allowed));
                }
                $modules[$module_key]['label']       = isset($_POST['module_label']) ? sanitize_text_field(wp_unslash($_POST['module_label'])) : $modules[$module_key]['label'];
                $modules[$module_key]['description'] = isset($_POST['module_description']) ? sanitize_text_field(wp_unslash($_POST['module_description'])) : $modules[$module_key]['description'];
                $modules[$module_key]['functions']   = $functions;
                zw_org_save_permission_modules($modules);
                zw_org_admin_notice('success', '权限模块已更新。');
            } else {
                zw_org_admin_notice('error', '权限模块不存在。');
            }
            zw_org_admin_redirect('zw-org-roles');
        }

        if ($action === 'delete_person') {
            $person_id = isset($_POST['person_id']) ? absint($_POST['person_id']) : 0;
            check_admin_referer('zw_org_delete_person_' . $person_id);
            $deleted = zw_org_delete_person($person_id);
            zw_org_admin_notice($deleted ? 'success' : 'error', $deleted ? '人员已删除。' : '人员不存在或不可删除。');
            zw_org_admin_redirect('zw-org', array('dept' => $dept));
        }
    }
}
add_action('admin_init', 'zw_org_admin_handle_post', 20);

if (!function_exists('zw_org_admin_styles')) {
    /**
     * 组织架构后台页内联样式；只在本模块页面输出。
     *
     * @param array $args {
     *     @type string $touch_css 可选：调用方（页面文件）自行生成的 canonical 触屏热区 CSS。
     *                            TASK-2166：使用 `.zw-adm-touch` 标记的页面文件需要**同文件**
     *                            持有 canonical 输出点（SPEC-10-B §3 / ux-guard 判据），
     *                            因此由页面调用 `zw_adm_ui_touch_css('body')` 后传入；
     *                            CSS 仍只在本函数唯一的 `<style>` 块内输出一次。
     * }
     * @return void
     */
    function zw_org_admin_styles($args = array()) {
        static $printed = false;
        $args = is_array($args) ? $args : array();
        $touch_css = isset($args['touch_css']) ? (string) $args['touch_css'] : null;
        if ($printed) {
            return;
        }
        $printed = true;
        ?>
        <style id="zw-org-admin">
        /* TASK-1646（TASK-1579 P2-1 批次 3 · SPEC-10 §1.9.3 第 2/3 项；机制出处 CHG-300）：
           本域旧类 `.zw-org-*` 收敛到统一语义层 `.zw-adm-org-*`，处于**阶段 ① 双写**：
           canonical 规则的选择器组**同时承载**新类与旧类（旧类＝**兼容别名**），
           标记面同时携带新类与旧类。**双写一个发布周期内不得删除任何旧类**；
           阶段 ②（切换）与阶段 ③（清理）另按 SPEC-10 §1.9.3 第 2 项的阶段门执行
           （清理**只能**由另一次 CHG 授权）。
           **第 1 类（语义对应 canonical、取值统一会改变视觉）本批不做**：`.zw-org-card` /
           `.zw-org-card-head` / `.zw-org-card-body` / `.zw-org-grid` / `.zw-org-badge` /
           `.zw-org-empty` 须先走 UI 走查并另立 CHG ⇒ 本批其旧声明与旧标记**原样保留**
           （登记为批次 3-残余）。`.zw-org-tbl-wrap` 已由批次 1（CHG-300）并入 canonical
           `.zw-adm-table-wrap` 作兼容别名，本批**不再**改写。
           废止标记（旧类 → 新类，逐类一行；带「标记面别名」者为无历史声明的纯标记类）：
           [已废弃 → 见 .zw-adm-org-actions] 旧类 .zw-org-actions
           [已废弃 → 见 .zw-adm-org-btn] 旧类 .zw-org-btn
           [已废弃 → 见 .zw-adm-org-btn-danger] 旧类 .zw-org-btn-danger
           [已废弃 → 见 .zw-adm-org-btn-primary] 旧类 .zw-org-btn-primary
           [已废弃 → 见 .zw-adm-org-check] 旧类 .zw-org-check
           [已废弃 → 见 .zw-adm-org-check-grid] 旧类 .zw-org-check-grid
           [已废弃 → 见 .zw-adm-org-check-top] 旧类 .zw-org-check-top
           [已废弃 → 见 .zw-adm-org-field] 旧类 .zw-org-field
           [已废弃 → 见 .zw-adm-org-file-actions] 旧类 .zw-org-file-actions
           [已废弃 → 见 .zw-adm-org-file-icon] 旧类 .zw-org-file-icon
           [已废弃 → 见 .zw-adm-org-file-layout] 旧类 .zw-org-file-layout
           [已废弃 → 见 .zw-adm-org-file-meta] 旧类 .zw-org-file-meta
           [已废弃 → 见 .zw-adm-org-file-name] 旧类 .zw-org-file-name
           [已废弃 → 见 .zw-adm-org-file-preview] 旧类 .zw-org-file-preview
           [已废弃 → 见 .zw-adm-org-form-actions] 旧类 .zw-org-form-actions
           [已废弃 → 见 .zw-adm-org-head] 旧类 .zw-org-head
           [已废弃 → 见 .zw-adm-org-hidden] 旧类 .zw-org-hidden
           [已废弃 → 见 .zw-adm-org-hint] 旧类 .zw-org-hint
           [已废弃 → 见 .zw-adm-org-identity-strip] 旧类 .zw-org-identity-strip
           [已废弃 → 见 .zw-adm-org-kpi] 旧类 .zw-org-kpi
           [已废弃 → 见 .zw-adm-org-kpis] 旧类 .zw-org-kpis
           [已废弃 → 见 .zw-adm-org-muted] 旧类 .zw-org-muted
           [已废弃 → 见 .zw-adm-org-notice] 旧类 .zw-org-notice
           [已废弃 → 见 .zw-adm-org-nowrap] 旧类 .zw-org-nowrap
           [已废弃 → 见 .zw-adm-org-perm-tree] 旧类 .zw-org-perm-tree
           [已废弃 → 见 .zw-adm-org-perm-tree-children] 旧类 .zw-org-perm-tree-children
           [已废弃 → 见 .zw-adm-org-perm-tree-top] 旧类 .zw-org-perm-tree-top
           [已废弃 → 见 .zw-adm-org-person-layout] 旧类 .zw-org-person-layout
           [已废弃 → 见 .zw-adm-org-person-main] 旧类 .zw-org-person-main
           [已废弃 → 见 .zw-adm-org-person-photo] 旧类 .zw-org-person-photo
           [已废弃 → 见 .zw-adm-org-photo-input] 旧类 .zw-org-photo-input
           [已废弃 → 见 .zw-adm-org-photo-preview] 旧类 .zw-org-photo-preview
           [已废弃 → 见 .zw-adm-org-role-static] 旧类 .zw-org-role-static
           [已废弃 → 见 .zw-adm-org-switch] 旧类 .zw-org-switch
           [已废弃 → 见 .zw-adm-org-switch-thumb] 旧类 .zw-org-switch-thumb
           [已废弃 → 见 .zw-adm-org-switch-track] 旧类 .zw-org-switch-track
           [已废弃 → 见 .zw-adm-org-tab] 旧类 .zw-org-tab
           [已废弃 → 见 .zw-adm-org-tabs] 旧类 .zw-org-tabs
           [已废弃 → 见 .zw-adm-org-tbl] 旧类 .zw-org-tbl
           [已废弃 → 见 .zw-adm-org-term-cycles] 旧类 .zw-org-term-cycles
           [已废弃 → 见 .zw-adm-org-user-search] 旧类 .zw-org-user-search
           [已废弃 → 见 .zw-adm-org-user-select] 旧类 .zw-org-user-select
           [已废弃 → 见 .zw-adm-org-wrap] 旧类 .zw-org-wrap
           [已废弃 → 见 .zw-adm-org-auto-age] 旧类 .zw-org-auto-age（标记面别名，无历史声明）
           [已废弃 → 见 .zw-adm-org-auto-birthday] 旧类 .zw-org-auto-birthday（标记面别名，无历史声明）
           [已废弃 → 见 .zw-adm-org-auto-gender] 旧类 .zw-org-auto-gender（标记面别名，无历史声明）
           [已废弃 → 见 .zw-adm-org-auto-gender-key] 旧类 .zw-org-auto-gender-key（标记面别名，无历史声明）
           [已废弃 → 见 .zw-adm-org-id-card-msg] 旧类 .zw-org-id-card-msg（标记面别名，无历史声明）
           [已废弃 → 见 .zw-adm-org-link-form] 旧类 .zw-org-link-form（标记面别名，无历史声明）
           [已废弃 → 见 .zw-adm-org-person-form] 旧类 .zw-org-person-form（标记面别名，无历史声明）
           [已废弃 → 见 .zw-adm-org-person-id-card] 旧类 .zw-org-person-id-card（标记面别名，无历史声明） */
        .zw-adm-org-wrap,.zw-org-wrap{max-width:none!important;width:auto!important;margin-right:0!important}
        .zw-adm-org-head,.zw-org-head{margin:6px 0 16px}
        .zw-adm-org-head h1,.zw-org-head h1{font-size:20px;font-weight:600;color:#222;margin:0}
        .zw-adm-org-head p,.zw-org-head p{font-size:13px;color:#666;margin:5px 0 0}
        .zw-adm-org-actions,.zw-org-actions{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 14px}
        .zw-adm-org-btn,.zw-org-btn{display:inline-flex;align-items:center;gap:5px;font-size:13px;padding:7px 14px;border-radius:8px;border:1px solid #dcdfe6;background:#fff;color:#333;cursor:pointer;text-decoration:none;line-height:1.5}
        @media(hover:hover){.zw-adm-org-btn:hover,.zw-org-btn:hover{color:#d80505;border-color:#d80505}}
        .zw-adm-org-btn-primary,.zw-org-btn-primary{background:var(--primary-color,#d80505);border-color:var(--primary-color,#d80505);color:#fff}
        @media(hover:hover){.zw-adm-org-btn-primary:hover,.zw-org-btn-primary:hover{background:#b30404;border-color:#b30404;color:#fff}}
        .zw-adm-org-btn-danger,.zw-org-btn-danger{color:#c62828;border-color:#e5bebe}
        @media(hover:hover){.zw-adm-org-btn-danger:hover,.zw-org-btn-danger:hover{background:#fff5f5;border-color:#c62828;color:#c62828}}
        .zw-org-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin:0 0 16px}
        .zw-org-card-head{display:flex;align-items:center;gap:10px;padding:13px 16px;border-bottom:1px solid #f0f1f3;background:#fafbfc}
        .zw-org-card-head .ct{font-size:14px;font-weight:600;flex:1}
        .zw-org-card-head .desc{font-size:12px;color:#999}
        .zw-org-card-body{padding:16px}
        /* TASK-595：关联用户面板使用 SPEC-85 可搜索下拉，卡片不得裁剪弹层 */
        #zw-org-panel-link{overflow:visible}
        .zw-adm-org-kpis,.zw-org-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:0 0 16px}
        @media(max-width:1100px){.zw-adm-org-kpis,.zw-org-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:600px){.zw-adm-org-kpis,.zw-org-kpis{grid-template-columns:1fr}}
        .zw-adm-org-kpi,.zw-org-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px}
        .zw-adm-org-kpi .l,.zw-org-kpi .l{font-size:12.5px;color:#666}
        .zw-adm-org-kpi .n,.zw-org-kpi .n{font-size:26px;font-weight:700;color:#222;margin:5px 0 1px}
        .zw-adm-org-kpi .d,.zw-org-kpi .d{font-size:11.5px;color:#999}
        .zw-adm-org-tabs,.zw-org-tabs{display:flex;gap:8px;overflow-x:auto;padding-bottom:2px;margin:0 0 14px}
        .zw-adm-org-tab,.zw-org-tab{flex:0 0 auto;font-size:12.5px;color:#666;background:#fff;border:1px solid #e5e7eb;border-radius:999px;padding:6px 13px;text-decoration:none;white-space:nowrap}
        .zw-adm-org-tab.on,.zw-org-tab.on{color:#fff;background:var(--primary-color,#d80505);border-color:var(--primary-color,#d80505);font-weight:600}
        .zw-adm-org-tab .cnt,.zw-org-tab .cnt{color:#999;margin-left:4px}
        .zw-adm-org-tab.on .cnt,.zw-org-tab.on .cnt{color:rgba(255,255,255,.82)}
        .zw-org-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        @media(max-width:900px){.zw-org-grid{grid-template-columns:1fr}}
        .zw-adm-org-person-layout,.zw-org-person-layout{display:grid;grid-template-columns:minmax(0,1fr) 220px;gap:18px;align-items:start}
        .zw-adm-org-person-main,.zw-org-person-main{min-width:0}
        .zw-adm-org-person-photo,.zw-org-person-photo{background:#fafbfc;border:1px solid #e5e7eb;border-radius:12px;padding:14px;text-align:center}
        .zw-adm-org-photo-preview,.zw-org-photo-preview{width:100%;aspect-ratio:3/4;border-radius:10px;background:#f0f1f3;border:1px dashed #dcdcde;display:flex;align-items:center;justify-content:center;overflow:hidden;margin-bottom:10px}
        .zw-adm-org-photo-preview img,.zw-org-photo-preview img{width:100%;height:100%;object-fit:cover;display:block}
        .zw-adm-org-photo-preview .ph,.zw-org-photo-preview .ph{color:#999;font-size:13px}
        .zw-adm-org-photo-input,.zw-org-photo-input{display:none}
        @media(max-width:900px){.zw-adm-org-person-layout,.zw-org-person-layout{grid-template-columns:1fr}.zw-adm-org-person-photo,.zw-org-person-photo{max-width:220px}}
        .zw-adm-org-field,.zw-org-field{margin:0 0 12px}
        .zw-adm-org-field label,.zw-org-field label{display:block;font-size:12.5px;color:#444;margin:0 0 5px;font-weight:500}
        .zw-adm-org-field input[type=text],.zw-adm-org-field input[type=number],.zw-adm-org-field input[type=search],.zw-adm-org-field input[type=email],.zw-adm-org-field select,.zw-adm-org-field textarea,.zw-org-field input[type=text],.zw-org-field input[type=number],.zw-org-field input[type=search],.zw-org-field input[type=email],.zw-org-field select,.zw-org-field textarea{width:100%;border:1px solid #dcdcde;border-radius:8px;padding:7px 10px;font-size:13px;background:#fff;box-sizing:border-box}
        .zw-adm-org-field input[readonly],.zw-org-field input[readonly]{background:#f6f7f9;color:#555}
        .zw-adm-org-field textarea,.zw-org-field textarea{min-height:96px;resize:vertical}
        .zw-adm-org-hint,.zw-org-hint{font-size:11.5px;color:#999;margin:6px 0 0;line-height:1.6}
        .zw-adm-org-form-actions,.zw-org-form-actions{display:flex;gap:10px;align-items:center;margin-top:14px}
        .zw-adm-org-hidden,.zw-org-hidden{display:none}
        .zw-adm-org-user-search,.zw-org-user-search{margin-bottom:8px}
        .zw-adm-org-user-select,.zw-org-user-select{width:100%;min-height:180px;border:1px solid #dcdcde;border-radius:8px;padding:4px}
        .zw-adm-org-tbl,.zw-org-tbl{width:100%;border-collapse:collapse}
        .zw-adm-org-tbl th,.zw-org-tbl th{font-size:12px;color:#666;text-align:left;padding:10px 12px;background:#fafbfc;border-bottom:1px solid #e5e7eb;white-space:nowrap}
        .zw-adm-org-tbl td,.zw-org-tbl td{padding:11px 12px;border-bottom:1px solid #f0f1f3;vertical-align:middle;font-size:13px}
        .zw-adm-org-tbl tr:last-child td,.zw-org-tbl tr:last-child td{border-bottom:0}
        @media(hover:hover){.zw-adm-org-tbl tr:hover td,.zw-org-tbl tr:hover td{background:#fafbfd}}
        .zw-adm-org-tbl .zw-adm-org-nowrap,.zw-org-tbl .zw-org-nowrap{white-space:nowrap}
        .zw-adm-org-muted,.zw-org-muted{color:#999;font-size:12px}
        .zw-org-badge{display:inline-block;font-size:11.5px;line-height:18px;padding:1px 9px;border-radius:999px;background:#f2f3f5;color:#666}
        .zw-org-badge.on{color:#2e7d32;background:rgba(76,175,80,.12)}
        .zw-org-badge.info{color:#1a66cc;background:rgba(51,133,255,.1)}
        .zw-adm-org-identity-strip,.zw-org-identity-strip{display:grid;grid-template-columns:minmax(220px,1fr) repeat(2,minmax(170px,auto));gap:14px;align-items:end;padding:12px 14px;margin:0 0 16px;background:#f8faf9;border:1px solid #dfe9e1;border-radius:10px}
        @media(max-width:900px){.zw-adm-org-identity-strip,.zw-org-identity-strip{grid-template-columns:1fr}}
        .zw-adm-org-identity-strip .zw-adm-org-field,.zw-org-identity-strip .zw-org-field{margin-bottom:0}
        .zw-adm-org-role-static,.zw-org-role-static{display:inline-block;max-width:100%;padding:6px 0;font-size:13px;line-height:1.5;color:#333;word-break:break-word}
        .zw-adm-org-switch,.zw-org-switch{display:inline-flex;align-items:center;gap:8px;margin-top:6px;font-size:13px;color:#333;cursor:pointer;user-select:none}
        .zw-adm-org-switch input[type=checkbox],.zw-org-switch input[type=checkbox]{position:absolute;opacity:0;pointer-events:none}
        .zw-adm-org-switch-track,.zw-org-switch-track{position:relative;flex:0 0 auto;width:42px;height:22px;border-radius:999px;background:#d9dee3;transition:background .2s}
        .zw-adm-org-switch-thumb,.zw-org-switch-thumb{position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.18);transition:left .2s}
        .zw-adm-org-switch.on .zw-adm-org-switch-track,
        .zw-adm-org-switch input:checked + .zw-adm-org-switch-track,
        .zw-org-switch.on .zw-org-switch-track,
        .zw-org-switch input:checked + .zw-org-switch-track{background:#2e7d32}
        .zw-adm-org-switch.on .zw-adm-org-switch-thumb,
        .zw-adm-org-switch input:checked + .zw-adm-org-switch-track .zw-adm-org-switch-thumb,
        .zw-org-switch.on .zw-org-switch-thumb,
        .zw-org-switch input:checked + .zw-org-switch-track .zw-org-switch-thumb{left:22px}
        .zw-adm-org-switch.is-disabled,.zw-org-switch.is-disabled{cursor:not-allowed}
        .zw-adm-org-switch input:disabled + .zw-adm-org-switch-track,.zw-org-switch input:disabled + .zw-org-switch-track{background:#e4e7ea;opacity:.65}
        .zw-adm-org-switch.is-disabled.on .zw-adm-org-switch-track,.zw-org-switch.is-disabled.on .zw-org-switch-track{background:#9bbf9d}
        .zw-adm-org-check-grid,.zw-org-check-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px 14px}
        .zw-adm-org-check,.zw-org-check{display:flex;align-items:flex-start;gap:6px;font-size:13px;line-height:1.6;color:#333}
        .zw-adm-org-check input,.zw-org-check input{flex:0 0 auto;margin-top:3px}
        .zw-adm-org-check-top,.zw-org-check-top{font-weight:600}
        .zw-adm-org-perm-tree,.zw-org-perm-tree{max-height:560px;overflow:auto;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;background:#fafbfc}
        .zw-adm-org-perm-tree code,.zw-org-perm-tree code{font-size:11px;color:#777;background:#f0f1f3;padding:1px 4px;border-radius:4px}
        .zw-adm-org-perm-tree-top,.zw-org-perm-tree-top{padding:6px 0;border-bottom:1px dashed #e5e7eb}
        .zw-adm-org-perm-tree-top:last-child,.zw-org-perm-tree-top:last-child{border-bottom:0}
        .zw-adm-org-perm-tree-children,.zw-org-perm-tree-children{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 14px;margin:6px 0 0 22px}
        @media(max-width:900px){.zw-adm-org-check-grid,.zw-org-check-grid,.zw-adm-org-perm-tree-children,.zw-org-perm-tree-children{grid-template-columns:1fr}}
        .zw-org-empty{text-align:center;color:#999;padding:34px 0;font-size:13px}
        .zw-adm-org-notice,.zw-org-notice{margin:10px 0 14px;padding:10px 12px;border-radius:8px;font-size:13px;border:1px solid #dcdcde;background:#fff}
        .zw-adm-org-notice.success,.zw-org-notice.success{border-color:#b7dfb9;background:#f3fbf4;color:#1e6b24}
        .zw-adm-org-notice.error,.zw-org-notice.error{border-color:#f0c3c3;background:#fff5f5;color:#b42318}
        /* TASK-1586 / SPEC-10 §1.9.2 第 5 条：宽表横滑容器**必须常驻**。
           原先 `.zw-org-tbl-wrap{overflow-x:auto}` 只写在 ≤782px 内，而卡片
           `.zw-org-card{…overflow:hidden}`（:754）常驻 ⇒ **783–1200px 区间**该 17 列宽表
           既无横滑容器、又被卡片裁掉 ⇒ **内容不可达**（比横向溢出更糟）。
           修法：`overflow-x:auto` 提到常驻（+ iOS 惯性滚动）；`min-width` 仍只在窄屏生效。
           **TASK-1643（P2-1 第一批 / CHG-300 · SPEC-10 §1.9.3 第 2 条）**：本类
           `[已废弃 → 见 .zw-adm-table-wrap]` —— 它是 canonical 宽表包裹的历史命名空间
           **兼容别名**（canonical 规则的**选择器组**已同时承载它，见本块下方
           `echo zw_adm_ui_table_wrap_css();`），**双写一个发布周期内不得删除**；
           本声明保留是为了不丢失 iOS 惯性滚动语义（canonical 声明体不含该属性）。 */
        .zw-org-tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
        @media(max-width:782px){
            .zw-org-card-body{padding:12px}
            .zw-adm-org-tbl,.zw-org-tbl{min-width:1280px}
        }
        <?php
        // TASK-1632（TASK-1579 §7 P1-6 余项）：宽表横滑包裹唯一出处＝canonical
        // （inc/admin/admin-ui-tokens.php 的 zw_adm_ui_table_wrap_css()）。
        // 「组织架构」域不在会员域外壳作用域内 ⇒ 须在本模块**既有**内联 <style>
        // （唯一出处 zw_org_admin_styles()）内输出该 canonical 规则；
        // 页面上的历史容器 `.zw-org-tbl-wrap` **保留**（含 iOS 惯性滚动声明），
        // 与 canonical 类**双写**（SPEC-10 §1.9.2 第 10 条 / P2-1 收敛口径），未复制第二套声明。
        echo zw_adm_ui_table_wrap_css();
        // TASK-1637（P1-4 余项 / 用户拍板②）：触屏块同由 canonical 输出、`body` 作用域
        //（本模块不注入 `.zw-adm-tcpc`；规则仅 `@media(pointer:coarse)` 命中 ⇒ 桌面零变化）。
        // TASK-2166：页面文件若已自行生成 canonical 热区 CSS（同文件输出点），此处只输出它，
        // 保证「唯一 style 输出点 + 不重复声明」；未传时维持既有自生成行为（其它 org 页不变）。
        if ($touch_css === null) {
            $touch_css = function_exists('zw_adm_ui_touch_css') ? zw_adm_ui_touch_css('body') : '';
        }
        echo $touch_css;
        ?>
        </style>
        <?php
    }
}
