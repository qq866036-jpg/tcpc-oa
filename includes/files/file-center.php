<?php
/**
 * 网盘文件中心：企业微信微盘式一级/二级分类与分支机构资料视图。
 *
 * P0：分支机构一级分类仅 manage_options；会员资料以批次和会员信息为主，原始导入包私有保留 15 天。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_file_center_can_manage')) {
    function zw_file_center_can_manage() {
        return function_exists('current_user_can') && current_user_can('manage_options');
    }
}

if (!function_exists('zw_file_center_url')) {
    function zw_file_center_url($args = array()) {
        $base = array('page' => 'zw-org-files');
        $args = is_array($args) ? $args : array();
        foreach ($args as $k => $v) {
            if ($v === '' || $v === null || $v === false) { unset($args[$k]); }
        }
        return add_query_arg(array_merge($base, $args), admin_url('admin.php'));
    }
}

if (!function_exists('zw_file_center_default_space')) {
    /**
     * 从左侧菜单点「网盘文件」进来时的默认文件空间（TASK-2140：用户口径「默认进入 内部办公」）。
     *
     * @return string
     */
    function zw_file_center_default_space() {
        return 'office';
    }
}

if (!function_exists('zw_file_center_view_modes')) {
    /**
     * 浏览方式（图标 / 列表）—— 唯一出处。
     *
     * @return array
     */
    function zw_file_center_view_modes() {
        return array(
            'grid' => array('label' => '图标', 'icon' => 'ceoicon-grid-line'),
            'list' => array('label' => '列表', 'icon' => 'ceoicon-list-check'),
        );
    }
}

if (!function_exists('zw_file_center_resolve_view')) {
    /**
     * 解析浏览方式：显式参数（合法才认）＞ 用户上次选择 ＞ 默认图标。
     *
     * @param string $param  URL 参数 `fc_view`。
     * @param string $saved  用户上次选择（user_meta）。
     * @return string grid|list
     */
    function zw_file_center_resolve_view($param, $saved) {
        $modes = array_keys(zw_file_center_view_modes());
        $param = is_string($param) ? strtolower(trim($param)) : '';
        if (in_array($param, $modes, true)) {
            return $param;
        }
        $saved = is_string($saved) ? strtolower(trim($saved)) : '';
        return in_array($saved, $modes, true) ? $saved : 'grid';
    }
}

if (!function_exists('zw_file_center_state_args')) {
    /**
     * `zw_file_center_state()` 的键 → URL 查询参数（唯一映射出处）。
     *
     * 背景（TASK-2140）：分页 / 上传回跳此前直接把 `$state`（键为 `space`/`cat`…）丢给
     * `zw_file_center_url()`，而该函数只做原样合并 ⇒ 生成 `?space=office` 这类**页面不认**的参数，
     * 翻页后空间与分类会丢。本函数把键统一映射成 `fc_*`，所有「由 state 生成链接」的调用点都走它。
     *
     * @param array $state 状态数组。
     * @return array
     */
    function zw_file_center_state_args($state) {
        $state = is_array($state) ? $state : array();
        $map = array(
            'space'  => 'fc_space',
            'cat'    => 'fc_cat',
            'branch' => 'fc_branch',
            'batch'  => 'fc_batch',
            'paged'  => 'fc_paged',
            'q'      => 'fc_q',
            'view'   => 'fc_view',
        );
        $args = array();
        foreach ($map as $key => $qk) {
            if (!array_key_exists($key, $state)) {
                continue;
            }
            $value = $state[$key];
            if ($value === '' || $value === null || $value === false || $value === 0 || $value === '0') {
                continue;
            }
            $args[$qk] = $value;
        }
        return $args;
    }
}

if (!function_exists('zw_file_center_state')) {
    function zw_file_center_state() {
        $space = isset($_GET['fc_space']) ? sanitize_key(wp_unslash((string) $_GET['fc_space'])) : zw_file_center_default_space();
        $cat   = isset($_GET['fc_cat']) ? sanitize_key(wp_unslash((string) $_GET['fc_cat'])) : '';
        if (!in_array($space, array('all', 'office', 'branch', 'member', 'certificate', 'recycle'), true)) { $space = 'all'; }
        // TASK-2140：浏览器记忆上次的「图标 / 列表」选择（user_meta），显式点击时写回。
        $param_view = isset($_GET['fc_view']) ? sanitize_key(wp_unslash((string) $_GET['fc_view'])) : '';
        $uid        = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $saved_view = ($uid > 0 && function_exists('get_user_meta')) ? (string) get_user_meta($uid, 'zw_fc_view', true) : '';
        $view       = zw_file_center_resolve_view($param_view, $saved_view);
        if ($param_view !== '' && in_array($param_view, array_keys(zw_file_center_view_modes()), true)
            && $uid > 0 && function_exists('update_user_meta') && $saved_view !== $param_view) {
            update_user_meta($uid, 'zw_fc_view', $param_view);
        }
        return array(
            'space'  => $space,
            'cat'    => $cat,
            'branch' => isset($_GET['fc_branch']) ? absint($_GET['fc_branch']) : 0,
            'batch'  => isset($_GET['fc_batch']) ? absint($_GET['fc_batch']) : 0,
            'paged'  => isset($_GET['fc_paged']) ? max(1, absint($_GET['fc_paged'])) : 1,
            'q'      => isset($_GET['fc_q']) ? sanitize_text_field(wp_unslash((string) $_GET['fc_q'])) : '',
            'view'   => $view,
        );
    }
}

if (!function_exists('zw_file_center_first_levels')) {
    function zw_file_center_first_levels() {
        return array(
            'all'         => array('label' => '全部文件', 'icon' => 'ceoicon-folder-line'),
            'office'      => array('label' => '内部办公', 'icon' => 'ceoicon-folder-line'),
            'branch'      => array('label' => '分支机构', 'icon' => 'ceoicon-folder-info-line'),
            'member'      => array('label' => '会员材料', 'icon' => 'ceoicon-folder-lock-line'),
            'certificate' => array('label' => '制证归档', 'icon' => 'ceoicon-folder-open-line'),
            'recycle'     => array('label' => '回收站', 'icon' => 'ceoicon-delete-bin-6-line'),
        );
    }
}

if (!function_exists('zw_file_center_second_levels')) {
    function zw_file_center_second_levels($space) {
        $map = array(
            'all' => array(
                '' => array('label' => '全部文件', 'key' => ''),
            ),
            'office' => array(
                ''                => array('label' => '全部内部办公', 'key' => ''),
                'person_photo'    => array('label' => '组织人员照片', 'key' => 'person_photo'),
                'document'        => array('label' => '办公文档', 'key' => 'document'),
                // TASK-953 / SPEC-88 §1：政策法规按「外部政策 / 内部政策」分目录
                'policy_external' => array('label' => '政策法规 · 外部政策', 'key' => 'policy_external'),
                'policy_internal' => array('label' => '政策法规 · 内部政策', 'key' => 'policy_internal'),
            ),
            'branch' => array(
                'dispatch' => array('label' => '发文管理', 'key' => 'dispatch'),
                'receipt'  => array('label' => '收文管理', 'key' => 'receipt'),
                'activity' => array('label' => '活动报送', 'key' => 'activity'),
                'member'   => array('label' => '会员资料', 'key' => 'member'),
            ),
            'member' => array(
                ''             => array('label' => '全部会员材料', 'key' => ''),
                'application'  => array('label' => '会员申请材料', 'key' => 'application'),
                'certificate'  => array('label' => '会员证认证', 'key' => 'certificate'),
                'image_pool'   => array('label' => '图片池', 'key' => 'image_pool'),
                'draft'        => array('label' => '草稿 / 暂存', 'key' => 'draft'),
            ),
            'certificate' => array(
                ''              => array('label' => '全部制证归档', 'key' => ''),
                'archive_photo' => array('label' => '制证照片归档', 'key' => 'archive_photo'),
                'member_cert'   => array('label' => '会员证图片', 'key' => 'member_cert'),
            ),
            'recycle' => array(
                '' => array('label' => '已删除文件', 'key' => ''),
            ),
        );
        return isset($map[$space]) ? $map[$space] : array();
    }
}

if (!function_exists('zw_file_center_branches')) {
    function zw_file_center_branches() {
        if (!function_exists('get_posts')) { return array(); }
        return get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'category_name'  => 'fzjg',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
    }
}

if (!function_exists('zw_file_center_branch_title')) {
    function zw_file_center_branch_title($branch_id) {
        $branch_id = (int) $branch_id;
        if ($branch_id <= 0) { return '全部分支机构'; }
        return function_exists('get_the_title') ? (string) get_the_title($branch_id) : ('#' . $branch_id);
    }
}

if (!function_exists('zw_file_center_kind_label')) {
    function zw_file_center_kind_label($kind) {
        $map = array(
            'person_photo'         => '人员照片',
            'document'             => '办公文档',
            'notice'               => '通知公告',
            'photo'                => '免冠照片',
            'org_license'          => '营业执照',
            'org_auth'             => '单位授权书',
            'pay_voucher'          => '付款凭证',
            'member_cert'          => '会员证',
            'image'                => '图片池',
            'archive_photo'        => '制证照片',
            'branch_dispatch'      => '发文',
            'branch_receipt'       => '收文',
            'branch_activity'      => '活动报送',
            'branch_import_source' => '原始导入包',
        );
        $kind = sanitize_key($kind);
        return isset($map[$kind]) ? $map[$kind] : ($kind !== '' ? $kind : '文件');
    }
}

if (!function_exists('zw_file_center_status_label')) {
    function zw_file_center_status_label($status) {
        $map = array(
            'importing'             => '导入中',
            'completed'             => '已完成',
            'completed_with_errors' => '部分完成',
            'active'                => '有效',
            'expired'               => '已过期',
            'deleted'               => '已清理',
        );
        $status = sanitize_key($status);
        return isset($map[$status]) ? $map[$status] : ($status !== '' ? $status : '—');
    }
}

if (!function_exists('zw_file_center_query_files')) {
    /**
     * 查询网盘文件记录。
     *
     * @param array $state 当前状态。
     * @return WP_Query
     */
    function zw_file_center_query_files($state) {
        $space = isset($state['space']) ? (string) $state['space'] : 'all';
        $cat   = isset($state['cat']) ? (string) $state['cat'] : '';
        $meta  = array('relation' => 'AND');
        $is_branch_member = ($space === 'branch' && $cat === 'member');
        if ($space === 'office') {
            if ($cat === 'person_photo') {
                $meta[] = array('key' => 'zw_file_kind', 'value' => 'person_photo');
            } elseif ($cat === 'document') {
                $meta[] = array('key' => 'zw_file_kind', 'value' => array('document', 'notice', 'policy'), 'compare' => 'IN');
            } elseif ($cat === 'policy_external') {
                // TASK-953：外部政策（本会之外的权威机构制定，不可修改，只能遵照）
                $meta[] = array('key' => 'zw_file_kind', 'value' => 'policy_external');
            } elseif ($cat === 'policy_internal') {
                // TASK-953：内部政策（本会自行制定，可修订）
                $meta[] = array('key' => 'zw_file_kind', 'value' => 'policy_internal');
            } else {
                $meta[] = array('key' => 'zw_file_kind', 'value' => array('person_photo', 'document', 'notice', 'policy', 'policy_external', 'policy_internal'), 'compare' => 'IN');
            }
        } elseif ($space === 'branch') {
            if (in_array($cat, array('dispatch', 'receipt', 'activity'), true)) {
                $meta[] = array('key' => 'zw_file_scope', 'value' => 'branch');
                $meta[] = array('key' => 'zw_file_kind', 'value' => 'branch_' . $cat);
            } else {
                $meta[] = array('key' => 'zw_file_scope', 'value' => 'branch');
            }
            if (!empty($state['branch'])) {
                $meta[] = array('key' => 'zw_file_branch_id', 'value' => (int) $state['branch'], 'type' => 'NUMERIC');
            }
        } elseif ($space === 'member') {
            $scopes = array('application', 'certificate', 'image_pool', 'draft');
            if ($cat === 'application') { $scopes = array('application'); }
            elseif ($cat === 'certificate') { $scopes = array('certificate'); }
            elseif ($cat === 'image_pool') { $scopes = array('image_pool'); }
            elseif ($cat === 'draft') { $scopes = array('draft'); }
            $meta[] = array('key' => 'zw_file_scope', 'value' => $scopes, 'compare' => 'IN');
        } elseif ($space === 'certificate') {
            if ($cat === 'archive_photo') {
                $meta[] = array('key' => 'zw_file_kind', 'value' => 'archive_photo');
            } elseif ($cat === 'member_cert') {
                $meta[] = array('key' => 'zw_file_kind', 'value' => 'member_cert');
            } else {
                $meta[] = array('key' => 'zw_file_kind', 'value' => array('archive_photo', 'member_cert'), 'compare' => 'IN');
            }
        } elseif ($space === 'all') {
            // 原始导入包只在批次详情审计区展示，不进入文件总览。
            $meta = array(
                'relation' => 'AND',
                array(
                    'relation' => 'OR',
                    array('key' => 'zw_file_scope', 'compare' => 'NOT EXISTS'),
                    array('key' => 'zw_file_scope', 'value' => 'branch_import_source', 'compare' => '!='),
                ),
            );
        }
        // 分支机构会员资料不是 office_file 文件树，走专门批次/会员视图。
        if ($is_branch_member) {
            $meta[] = array('key' => 'zw_file_scope', 'value' => 'branch_import_source');
        }
        $args = array(
            'post_type'      => 'zw_office_file',
            'post_status'    => ($space === 'recycle') ? 'trash' : 'publish',
            'posts_per_page' => 24,
            'paged'          => max(1, (int) ($state['paged'] ?? 1)),   // TASK-1043：未传 paged 时的告警守卫（行为不变）
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ($space !== 'all' && count($meta) > 1) { $args['meta_query'] = $meta; }
        if (!empty($state['q'])) {
            $args['s'] = $state['q'];
        }
        return new WP_Query($args);
    }
}

if (!function_exists('zw_file_center_render_empty')) {
    function zw_file_center_render_empty($text = '暂无文件') {
        return '<div class="zw-fc-empty"><span class="ceofont ceoicon-folder-open-line" aria-hidden="true"></span><p>' . esc_html($text) . '</p></div>';
    }
}

if (!function_exists('zw_file_center_render_view_toggle')) {
    /**
     * 「图标 / 列表」浏览方式切换（TASK-2140）。
     *
     * 适老化：按钮 ≥44px 热区、字号 ≥14px、`:focus-visible` 可见；引导化：**就地切换**
     * （同一页面换 URL 参数，不跳走、不弹窗），当前态用 `aria-pressed` + 高亮双表达（不只靠颜色）。
     *
     * @param array $state 页面状态。
     * @return string
     */
    function zw_file_center_render_view_toggle($state) {
        $modes   = zw_file_center_view_modes();
        $current = isset($state['view']) ? (string) $state['view'] : 'grid';
        if (!isset($modes[$current])) {
            $current = 'grid';
        }
        $html = '<div class="zw-fc-view" role="group" aria-label="浏览方式">';
        foreach ($modes as $key => $mode) {
            $args = zw_file_center_state_args($state);
            $args['fc_view'] = $key;
            $active = ($current === $key);
            $html .= '<a class="zw-fc-view-btn' . ($active ? ' is-active' : '') . '"'
                . ' href="' . esc_url(zw_file_center_url($args)) . '"'
                . ' aria-pressed="' . ($active ? 'true' : 'false') . '"'
                . ' aria-label="' . esc_attr($active ? ('当前：' . $mode['label'] . '视图') : ('切换为' . $mode['label'] . '视图')) . '"'
                . ' title="' . esc_attr($mode['label'] . '视图') . '">'
                . '<span class="ceofont ' . esc_attr($mode['icon']) . '" aria-hidden="true"></span>'
                . '<span>' . esc_html($mode['label']) . '</span></a>';
        }
        return $html . '</div>';
    }
}

if (!function_exists('zw_file_center_file_context')) {
    /**
     * 文件展示上下文（卡片与列表共用的唯一取数口径，TASK-2140）。
     *
     * @param int $file_id 文件 ID。
     * @return array 空数组表示不可展示。
     */
    function zw_file_center_file_context($file_id) {
        $file_id = (int) $file_id;
        $file    = function_exists('zw_org_files_file') ? zw_org_files_file($file_id) : array();
        if (empty($file)) {
            return array();
        }
        $title     = (string) ($file['title'] ?? basename((string) ($file['original_name'] ?? '')));
        $file_name = (string) ($file['original_name'] ?? '') !== '' ? (string) $file['original_name'] : $title;
        return array(
            'id'            => $file_id,
            'file'          => $file,
            'title'         => $title,
            'file_name'     => $file_name,
            'mime'          => (string) ($file['mime'] ?? ''),
            'is_image'      => strpos((string) ($file['mime'] ?? ''), 'image/') === 0,
            'url'           => (string) ($file['url'] ?? ''),
            'preview'       => (string) ($file['preview_url'] ?? ($file['url'] ?? '')),
            'kind'          => (string) get_post_meta($file_id, 'zw_file_kind', true),
            'branch_id'     => (int) get_post_meta($file_id, 'zw_file_branch_id', true),
            'size_bytes'    => (int) ($file['size'] ?? 0),
            'size'          => function_exists('zw_org_files_format_size') ? zw_org_files_format_size((int) ($file['size'] ?? 0)) : '',
            'uploaded_at'   => (string) ($file['uploaded_at'] ?? ''),
            'scope'         => (string) get_post_meta($file_id, 'zw_file_scope', true),
        );
    }
}

if (!function_exists('zw_file_center_open_args')) {
    /**
     * 默认打开（在线浏览）触发器的公共参数（铁律 22：文件名 / 图标 = 预览，不是下载）。
     *
     * @param array $ctx `zw_file_center_file_context()` 结果。
     * @return array
     */
    function zw_file_center_open_args($ctx) {
        return array(
            'name'         => (string) $ctx['file_name'],
            'source'       => 'netdisk',
            'id'           => (int) $ctx['id'],
            'size'         => (int) $ctx['size_bytes'],
            'download_url' => (string) $ctx['url'],
        );
    }
}

if (!function_exists('zw_file_center_file_card')) {
    function zw_file_center_file_card($file_id) {
        $ctx = zw_file_center_file_context($file_id);
        if (empty($ctx)) { return ''; }
        $file_id  = (int) $ctx['id'];
        $title    = (string) $ctx['title'];
        $url      = (string) $ctx['url'];
        $preview  = (string) $ctx['preview'];
        $kind     = (string) $ctx['kind'];
        $branchId = (int) $ctx['branch_id'];
        $size     = (string) $ctx['size'];
        $file_name = (string) $ctx['file_name'];
        // TASK-2008：**默认打开动作 = 在线浏览**（文档弹层 / 图片灯箱）。
        // 卡片缩略图与文件名都是打开触发器的本体；下载只由下方显式「下载」按钮触发。
        // 预览实现、鉴权端点、图片灯箱均在 SPEC-85 canonical `doc-viewer`；
        // 分发与标记统一在 `inc/controls/file-open.php`（本页不再拼第二套 data-* 契约）。
        $thumb = '';
        if (!empty($ctx['is_image']) && $preview !== '') {
            $thumb = '<img src="' . esc_url($preview) . '" alt="' . esc_attr($title) . '" loading="lazy">';
        } elseif (function_exists('zw_control_file_icon')) {
            // TASK-1839：文件图标统一走 SPEC-85 canonical「文件图标（按后缀分族）」控件。
            $thumb = (string) zw_control_file_icon(array('name' => $file_name, 'size' => 'lg'));
        } else {
            $thumb = '<span class="ceofont ceoicon-file-2-line" aria-hidden="true"></span>';
        }
        $open_args = zw_file_center_open_args($ctx);
        $preview_link = function_exists('zw_control_file_open_link')
            ? zw_control_file_open_link(array_merge($open_args, array(
                'content'    => $thumb,
                'class'      => 'zw-fc-card-preview',
                'aria_label' => '在线浏览：' . $title,
            )))
            : '<span class="zw-fc-card-preview">' . $thumb . '</span>';
        $title_link = function_exists('zw_control_file_open_link')
            ? zw_control_file_open_link(array_merge($open_args, array(
                'icon_size' => '',
                'class'     => 'zw-fc-card-title-link',
            )))
            : esc_html($title);
        // TASK-2008：下载按钮只走 canonical（无兜底第二套；`bootstrap.php` 恒被 require）。
        $download_button = zw_control_file_download_button(array(
            'url'   => $url,
            'name'  => $file_name,
            'label' => '下载',
            'class' => 'button button-small',
            'icon'  => false,
        ));
        ob_start();
        ?>
        <div class="zw-fc-card">
            <?php echo $preview_link; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?>
            <div class="zw-fc-card-body">
                <div class="zw-fc-card-title" title="<?php echo esc_attr($title); ?>"><?php echo $title_link; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?></div>
                <div class="zw-fc-card-meta">
                    <span><?php echo esc_html(zw_file_center_kind_label($kind)); ?></span>
                    <span><?php echo esc_html($size); ?></span>
                </div>
                <?php if ($branchId > 0) : ?>
                    <div class="zw-fc-card-meta"><span><?php echo esc_html(zw_file_center_branch_title($branchId)); ?></span></div>
                <?php endif; ?>
            </div>
            <div class="zw-fc-card-actions">
                <span class="zw-fc-actions-main">
                    <?php echo $download_button; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?>
                </span>
                <?php if (in_array(get_post_meta($file_id, 'zw_file_scope', true), array('branch', 'branch_import_source'), true)) : ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('确认删除该文件？');">
                        <?php wp_nonce_field('zw_fc_delete_file_' . $file_id); ?>
                        <input type="hidden" name="zw_fc_action" value="delete_file">
                        <input type="hidden" name="file_id" value="<?php echo (int) $file_id; ?>">
                        <input type="hidden" name="fc_return" value="<?php echo esc_attr(zw_file_center_url($_GET)); ?>">
                        <button type="submit" class="button button-small button-link-delete">删除</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('zw_file_center_file_list_row')) {
    /**
     * 列表视图的一行（TASK-2140）：图标 + 文件名 = 默认打开（在线浏览），下载只走显式按钮。
     *
     * @param int $file_id 文件 ID。
     * @return string `<tr>`；不可展示时返回空串。
     */
    function zw_file_center_file_list_row($file_id) {
        $ctx = zw_file_center_file_context($file_id);
        if (empty($ctx)) {
            return '';
        }
        $file_id   = (int) $ctx['id'];
        $title     = (string) $ctx['title'];
        $file_name = (string) $ctx['file_name'];
        $open_args = zw_file_center_open_args($ctx);
        $icon = function_exists('zw_control_file_icon')
            ? (string) zw_control_file_icon(array('name' => $file_name, 'size' => 'sm'))
            : '<span class="ceofont ceoicon-file-2-line" aria-hidden="true"></span>';
        $name_link = function_exists('zw_control_file_open_link')
            ? zw_control_file_open_link(array_merge($open_args, array(
                'content'    => $icon . '<span class="zw-fc-list-name-t">' . esc_html($title) . '</span>',
                'class'      => 'zw-fc-list-open',
                'aria_label' => '在线浏览：' . $title,
            )))
            : esc_html($title);
        $download = function_exists('zw_control_file_download_button')
            ? zw_control_file_download_button(array(
                'url'   => (string) $ctx['url'],
                'name'  => $file_name,
                'label' => '下载',
                'class' => 'button button-small',
                'icon'  => false,
            ))
            : '';
        $uploaded = (string) $ctx['uploaded_at'] !== '' ? (string) $ctx['uploaded_at'] : '—';
        ob_start();
        ?>
        <tr class="zw-fc-list-row">
            <td class="zw-fc-list-name"><?php echo $name_link; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?></td>
            <td><?php echo esc_html(zw_file_center_kind_label((string) $ctx['kind'])); ?></td>
            <td class="zw-fc-list-size"><?php echo esc_html((string) $ctx['size']); ?></td>
            <td class="zw-fc-list-time"><?php echo esc_html($uploaded); ?></td>
            <td class="zw-fc-list-actions"><?php echo $download; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?></td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('zw_file_center_render_file_list')) {
    /**
     * 列表视图（TASK-2140）：紧凑表格，字段与卡片一致；表格外壳沿用 canonical 宽表横滑包裹。
     *
     * @param WP_Query $query 文件查询。
     * @param array    $state 页面状态。
     * @return string
     */
    function zw_file_center_render_file_list($query, $state) {
        $rows = '';
        while ($query->have_posts()) {
            $query->the_post();
            $rows .= zw_file_center_file_list_row(get_the_ID());
        }
        wp_reset_postdata();
        return '<div class="zw-adm-table-wrap">'
            . '<table class="widefat striped zw-fc-table zw-fc-list">'
            . '<thead><tr><th scope="col">名称</th><th scope="col">类型</th><th scope="col">大小</th>'
            . '<th scope="col">上传时间</th><th scope="col">操作</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }
}

if (!function_exists('zw_file_center_render_file_section')) {
    function zw_file_center_render_file_section($state) {
        $space = (string) $state['space'];
        $cat   = (string) $state['cat'];
        $query = zw_file_center_query_files($state);
        $label = '';
        $levels = zw_file_center_second_levels($space);
        if (isset($levels[$cat])) { $label = (string) $levels[$cat]['label']; }
        if ($label === '') { $label = '全部文件'; }
        ?>
        <div class="zw-fc-section-head">
            <div>
                <div class="zw-fc-breadcrumb">网盘文件 / <?php echo esc_html(zw_file_center_first_levels()[$space]['label'] ?? '全部文件'); ?> / <?php echo esc_html($label); ?></div>
                <h2><?php echo esc_html($label); ?></h2>
            </div>
            <div class="zw-fc-section-tools">
                <?php echo zw_file_center_render_view_toggle($state); // phpcs:ignore WordPress.Security.EscapeOutput -- 控件自带转义 ?>
                <form method="get" class="zw-fc-search">
                    <input type="hidden" name="page" value="zw-org-files">
                    <input type="hidden" name="fc_space" value="<?php echo esc_attr($space); ?>">
                    <input type="hidden" name="fc_cat" value="<?php echo esc_attr($cat); ?>">
                    <?php if (!empty($state['view'])) : ?><input type="hidden" name="fc_view" value="<?php echo esc_attr((string) $state['view']); ?>"><?php endif; ?>
                    <input type="search" name="fc_q" value="<?php echo esc_attr($state['q']); ?>" placeholder="搜索文件名">
                    <button type="submit" class="button">搜索</button>
                </form>
            </div>
        </div>
        <?php
        if ($space === 'branch' && in_array($cat, array('dispatch', 'receipt', 'activity'), true)) {
            zw_file_center_render_branch_upload_form($state, $cat);
            zw_file_center_render_branch_filter($state);
        }
        if (!$query->have_posts()) {
            echo zw_file_center_render_empty('该分类暂无文件');
        } elseif ((string) $state['view'] === 'list') {
            echo zw_file_center_render_file_list($query, $state);
            echo zw_file_center_render_pagination($query, $state);
        } else {
            echo '<div class="zw-fc-grid">';
            while ($query->have_posts()) {
                $query->the_post();
                echo zw_file_center_file_card(get_the_ID());
            }
            echo '</div>';
            echo zw_file_center_render_pagination($query, $state);
            wp_reset_postdata();
        }
    }
}

if (!function_exists('zw_file_center_render_pagination')) {
    function zw_file_center_render_pagination($query, $state) {
        $total = (int) $query->max_num_pages;
        if ($total <= 1) { return ''; }
        $html = '<div class="zw-fc-pagination">';
        for ($i = 1; $i <= $total; $i++) {
            // TASK-2140：状态键必须映射成 `fc_*`（此前直接传 $state ⇒ 翻页丢失空间/分类/视图）。
            $args = zw_file_center_state_args($state);
            $args['fc_paged'] = $i;
            $html .= '<a class="button' . ($i === (int) $state['paged'] ? ' button-primary' : '') . '" href="' . esc_url(zw_file_center_url($args)) . '"'
                . ' aria-label="第 ' . esc_attr((string) $i) . ' 页"' . ($i === (int) $state['paged'] ? ' aria-current="page"' : '') . '>' . $i . '</a>';
        }
        return $html . '</div>';
    }
}

if (!function_exists('zw_file_center_render_branch_upload_form')) {
    function zw_file_center_render_branch_upload_form($state, $cat) {
        $branches = zw_file_center_branches();
        if (empty($branches)) { return; }
        $kindMap = array('dispatch' => '发文', 'receipt' => '收文', 'activity' => '活动报送');
        ?>
        <div class="zw-fc-upload">
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin.php?page=zw-org-files')); ?>">
                <?php wp_nonce_field('zw_fc_upload_branch'); ?>
                <input type="hidden" name="zw_fc_action" value="upload_branch_file">
                <input type="hidden" name="fc_return" value="<?php echo esc_attr(zw_file_center_url(zw_file_center_state_args($state))); ?>">
                <select name="branch_id" required>
                    <option value="">选择分支机构</option>
                    <?php foreach ($branches as $branch) : ?>
                        <option value="<?php echo (int) $branch->ID; ?>" <?php selected((int) $state['branch'], (int) $branch->ID); ?>><?php echo esc_html(get_the_title($branch)); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="branch_kind" required>
                    <?php foreach ($kindMap as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($cat, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="file_title" placeholder="文件标题（可选）">
                <input type="file" name="fc_file" required>
                <button type="submit" class="button button-primary"><span class="ceofont ceoicon-upload-cloud-line" aria-hidden="true"></span> 上传</button>
            </form>
        </div>
        <?php
    }
}

if (!function_exists('zw_file_center_render_branch_filter')) {
    function zw_file_center_render_branch_filter($state) {
        $branches = zw_file_center_branches();
        if (empty($branches)) { return; }
        ?>
        <form method="get" class="zw-fc-filter">
            <input type="hidden" name="page" value="zw-org-files">
            <input type="hidden" name="fc_space" value="<?php echo esc_attr($state['space']); ?>">
            <input type="hidden" name="fc_cat" value="<?php echo esc_attr($state['cat']); ?>">
            <select name="fc_branch" onchange="this.form.submit()">
                <option value="">全部分支机构</option>
                <?php foreach ($branches as $branch) : ?>
                    <option value="<?php echo (int) $branch->ID; ?>" <?php selected((int) $state['branch'], (int) $branch->ID); ?>><?php echo esc_html(get_the_title($branch)); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php
    }
}

if (!function_exists('zw_file_center_render_branch_member')) {
    function zw_file_center_render_branch_member($state) {
        if (!empty($state['batch'])) {
            zw_file_center_render_branch_member_batch($state);
            return;
        }
        $args = array('limit' => 100);
        if (!empty($state['branch'])) { $args['branch_id'] = (int) $state['branch']; }
        $batches = function_exists('zw_branch_member_import_get_batches') ? zw_branch_member_import_get_batches($args) : array();
        ?>
        <div class="zw-fc-section-head">
            <div>
                <div class="zw-fc-breadcrumb">网盘文件 / 分支机构 / 会员资料 / 批量导入批次</div>
                <h2>批量导入批次</h2>
            </div>
        </div>
        <?php zw_file_center_render_branch_filter($state); ?>
        <?php if (empty($batches)) : ?>
            <?php echo zw_file_center_render_empty('暂无批量导入批次'); ?>
        <?php else : ?>
            <div class="zw-adm-table-wrap">
            <table class="widefat striped zw-fc-table">
                <thead><tr><th>批次号</th><th>分支机构</th><th>会员级别</th><th>提交人</th><th>总人数</th><th>成功</th><th>失败</th><th>导入时间</th><th>状态</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($batches as $batch) :
                    $batchId = (int) $batch->ID;
                    $branchId = (int) get_post_meta($batchId, 'zw_batch_branch_id', true);
                    $level = (string) get_post_meta($batchId, 'zw_batch_level', true);
                    $operator = (int) get_post_meta($batchId, 'zw_batch_operator', true);
                    $total = (int) get_post_meta($batchId, 'zw_batch_total', true);
                    $success = (int) get_post_meta($batchId, 'zw_batch_success', true);
                    $failed = (int) get_post_meta($batchId, 'zw_batch_failed', true);
                    $status = (string) get_post_meta($batchId, 'zw_batch_status', true);
                    $createdAt = (int) get_post_meta($batchId, 'zw_batch_created_at', true);
                    $operatorUser = $operator > 0 ? get_userdata($operator) : null;
                    $viewUrl = zw_file_center_url(array('fc_space' => 'branch', 'fc_cat' => 'member', 'fc_batch' => $batchId, 'fc_branch' => $branchId));
                    ?>
                    <tr>
                        <td>#<?php echo $batchId; ?></td>
                        <td><?php echo esc_html(zw_file_center_branch_title($branchId)); ?></td>
                        <td><?php echo esc_html(function_exists('zw_mc_level_display') ? zw_mc_level_display($level) : $level); ?></td>
                        <td><?php echo esc_html($operatorUser ? $operatorUser->display_name : ('#' . $operator)); ?></td>
                        <td><?php echo $total; ?></td>
                        <td><?php echo $success; ?></td>
                        <td><?php echo $failed; ?></td>
                        <td><?php echo $createdAt ? esc_html(wp_date('Y-m-d H:i', $createdAt + 8 * 3600)) : '—'; ?></td>
                        <td><?php echo esc_html(zw_file_center_status_label($status)); ?></td>
                        <td><a class="button button-small" href="<?php echo esc_url($viewUrl); ?>">查看会员</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
        <?php
    }
}

if (!function_exists('zw_file_center_render_branch_member_batch')) {
    function zw_file_center_render_branch_member_batch($state) {
        $batchId = (int) $state['batch'];
        $batch = get_post($batchId);
        if (!$batch || $batch->post_type !== (function_exists('zw_branch_member_import_batch_post_type') ? zw_branch_member_import_batch_post_type() : 'zw_branch_import')) {
            echo zw_file_center_render_empty('批次不存在');
            return;
        }
        $branchId = (int) get_post_meta($batchId, 'zw_batch_branch_id', true);
        $level = (string) get_post_meta($batchId, 'zw_batch_level', true);
        $total = (int) get_post_meta($batchId, 'zw_batch_total', true);
        $success = (int) get_post_meta($batchId, 'zw_batch_success', true);
        $failed = (int) get_post_meta($batchId, 'zw_batch_failed', true);
        $status = (string) get_post_meta($batchId, 'zw_batch_status', true);
        $apps = function_exists('zw_branch_member_import_get_batch_apps') ? zw_branch_member_import_get_batch_apps($batchId) : array();
        $backUrl = zw_file_center_url(array('fc_space' => 'branch', 'fc_cat' => 'member', 'fc_branch' => $branchId));
        ?>
        <div class="zw-fc-section-head">
            <div>
                <div class="zw-fc-breadcrumb"><a href="<?php echo esc_url($backUrl); ?>">批量导入批次</a> / #<?php echo $batchId; ?></div>
                <h2><?php echo esc_html(zw_file_center_branch_title($branchId)); ?></h2>
                <p class="zw-fc-muted"><?php echo esc_html(function_exists('zw_mc_level_display') ? zw_mc_level_display($level) : $level); ?> · 总 <?php echo $total; ?> 人 · 成功 <?php echo $success; ?> · 失败 <?php echo $failed; ?> · <?php echo esc_html(zw_file_center_status_label($status)); ?></p>
            </div>
            <a class="button" href="<?php echo esc_url($backUrl); ?>">返回批次列表</a>
        </div>
        <?php zw_file_center_render_source_package($batchId, $state); ?>
        <h3 class="zw-fc-subtitle">会员信息</h3>
        <?php if (empty($apps)) : ?>
            <?php echo zw_file_center_render_empty('该批次暂无会员信息'); ?>
        <?php else : ?>
            <div class="zw-adm-table-wrap">
            <table class="widefat striped zw-fc-table">
                <thead><tr><th>姓名</th><th>身份证</th><th>手机号</th><th>会员类别</th><th>证件照</th><th>审核状态</th><th>制证 / 发货</th></tr></thead>
                <tbody>
                <?php foreach ($apps as $app) :
                    $appId = (int) $app->ID;
                    $fields = json_decode((string) get_post_meta($appId, 'zw_app_fields', true), true);
                    if (!is_array($fields)) { $fields = array(); }
                    $name = (string) ($fields['real_name'] ?? get_the_title($app));
                    $idCard = (string) ($fields['id_card'] ?? '');
                    // TASK-805：JSON 已不再存身份证键 → 回退到专用归档键（密文，须解密）
                    if ($idCard === '' && function_exists('zw_idcard_read')) {
                        $idCard = (string) zw_idcard_read($appId, 'zw_app_af_id_card');
                    }
                    $mobile = (string) ($fields['mobile'] ?? '');
                    $memberCat = (string) ($fields['member_cat'] ?? '');
                    $photoId = function_exists('zw_member_files_app_ref') ? (int) zw_member_files_app_ref($appId, 'photo') : 0;
                    $photo = $photoId > 0 ? (function_exists('zw_org_files_file') ? zw_org_files_file($photoId) : array()) : array();
                    $photoUrl = !empty($photo['preview_url']) ? (string) $photo['preview_url'] : '';
                    $appStatus = (string) get_post_meta($appId, 'zw_app_status', true);
                    $shipment = (string) get_post_meta($appId, 'zw_shipment_status', true);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($name); ?></strong></td>
                        <td><?php echo esc_html(function_exists('zw_branch_member_import_mask_id_card') ? zw_branch_member_import_mask_id_card($idCard) : $idCard); ?></td>
                        <td><?php echo esc_html(function_exists('zw_branch_member_import_mask_mobile') ? zw_branch_member_import_mask_mobile($mobile) : $mobile); ?></td>
                        <td><?php echo esc_html($memberCat); ?></td>
                        <td>
                            <?php if ($photoUrl !== '') : ?>
                                <a href="<?php echo esc_url((string) ($photo['url'] ?? '')); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url($photoUrl); ?>" alt="证件照" style="width:42px;height:56px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb"></a>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td><?php echo esc_html($appStatus !== '' ? $appStatus : '—'); ?></td>
                        <td><?php echo esc_html($shipment !== '' ? $shipment : '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
        <?php
    }
}

if (!function_exists('zw_file_center_render_source_package')) {
    function zw_file_center_render_source_package($batchId, $state) {
        $batchId = (int) $batchId;
        $fileId = (int) get_post_meta($batchId, 'zw_batch_source_file_id', true);
        $fileName = (string) get_post_meta($batchId, 'zw_batch_source_file', true);
        $expiresAt = (int) get_post_meta($batchId, 'zw_batch_source_expires_at', true);
        $status = (string) get_post_meta($batchId, 'zw_batch_source_status', true);
        $uploadedAt = (int) get_post_meta($batchId, 'zw_batch_source_uploaded_at', true);
        $fileSize = (int) get_post_meta($batchId, 'zw_batch_source_file_size', true);
        if ($fileName === '' && $fileId <= 0) { return; }
        $remaining = $expiresAt > 0 ? max(0, (int) ceil(($expiresAt - time()) / DAY_IN_SECONDS)) : 0;
        $file = $fileId > 0 && function_exists('zw_org_files_file') ? zw_org_files_file($fileId) : array();
        $url = !empty($file['url']) ? (string) $file['url'] : '';
        ?>
        <details class="zw-fc-source" open>
            <summary>原始导入包（仅审计，保留 15 天）</summary>
            <div class="zw-fc-source-body">
                <p>
                    <strong><?php echo esc_html($fileName !== '' ? $fileName : '原始导入包'); ?></strong><br>
                    大小：<?php echo esc_html(function_exists('zw_org_files_format_size') && $fileSize > 0 ? zw_org_files_format_size($fileSize) : '—'); ?> ·
                    上传时间：<?php echo $uploadedAt ? esc_html(wp_date('Y-m-d H:i', $uploadedAt + 8 * 3600)) : '—'; ?> ·
                    到期时间：<?php echo $expiresAt ? esc_html(wp_date('Y-m-d H:i', $expiresAt + 8 * 3600)) : '—'; ?> ·
                    状态：<?php echo esc_html(zw_file_center_status_label($status)); ?>
                    <?php if ($status === 'active' && $expiresAt > 0) : ?>（剩余 <?php echo $remaining; ?> 天）<?php endif; ?>
                </p>
                <p>
                    <?php if ($url !== '' && $status === 'active') : ?>
                        <a class="button button-primary" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">下载原始导入包</a>
                    <?php endif; ?>
                    <?php if ($status === 'active' && $fileId > 0) : ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('确认立即清理原始导入包？清理后不影响会员信息。');">
                            <?php wp_nonce_field('zw_fc_delete_source_' . $fileId); ?>
                            <input type="hidden" name="zw_fc_action" value="delete_source">
                            <input type="hidden" name="file_id" value="<?php echo (int) $fileId; ?>">
                            <input type="hidden" name="batch_id" value="<?php echo (int) $batchId; ?>">
                            <input type="hidden" name="fc_return" value="<?php echo esc_attr(zw_file_center_url(zw_file_center_state_args($state))); ?>">
                            <button type="submit" class="button button-link-delete">立即清理</button>
                        </form>
                    <?php endif; ?>
                </p>
            </div>
        </details>
        <?php
    }
}

if (!function_exists('zw_file_center_render')) {
    function zw_file_center_render() {
        if (!zw_file_center_can_manage()) { wp_die('无权限'); }
        $state = zw_file_center_state();
        $levels = zw_file_center_first_levels();
        if (!isset($levels[$state['space']])) { $state['space'] = 'all'; }
        $second = zw_file_center_second_levels($state['space']);
        if ($state['cat'] === '' && !empty($second)) {
            $keys = array_keys($second);
            if (!in_array('', $keys, true)) { $state['cat'] = (string) $keys[0]; }
        }
        if ($state['cat'] !== '' && !isset($second[$state['cat']])) {
            $state['cat'] = (string) array_key_first($second);
        }
        ?>
        <div class="wrap zw-adm-org-wrap zw-org-wrap zw-fc-wrap">
            <div class="zw-adm-org-head zw-org-head">
                <h1>网盘文件</h1>
                <p>统一文件中心：内部办公、分支机构、会员材料与制证归档；分支机构批量导入以会员信息和批次为主，原始导入包仅审计保留 15 天。</p>
            </div>
            <?php if (!empty($_GET['fc_msg'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash((string) $_GET['fc_msg']))); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['fc_err'])) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html(sanitize_text_field(wp_unslash((string) $_GET['fc_err']))); ?></p></div>
            <?php endif; ?>
            <div class="zw-fc-layout">
                <aside class="zw-fc-nav">
                    <div class="zw-fc-nav-title">文件空间</div>
                    <?php foreach ($levels as $key => $level) :
                        $active = ($state['space'] === $key) ? ' is-active' : '';
                        ?>
                        <a class="zw-fc-nav-item<?php echo esc_attr($active); ?>" href="<?php echo esc_url(zw_file_center_url(array('fc_space' => $key))); ?>">
                            <span class="ceofont <?php echo esc_attr($level['icon']); ?>" aria-hidden="true"></span>
                            <span><?php echo esc_html($level['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </aside>
                <section class="zw-fc-mid">
                    <div class="zw-fc-mid-title"><?php echo esc_html($levels[$state['space']]['label']); ?></div>
                    <?php foreach ($second as $key => $item) :
                        $active = ((string) $state['cat'] === (string) $key) ? ' is-active' : '';
                        ?>
                        <a class="zw-fc-mid-item<?php echo esc_attr($active); ?>" href="<?php echo esc_url(zw_file_center_url(array('fc_space' => $state['space'], 'fc_cat' => $key))); ?>">
                            <span class="ceofont ceoicon-folder-line" aria-hidden="true"></span>
                            <span><?php echo esc_html($item['label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </section>
                <main class="zw-fc-main">
                    <?php
                    if ($state['space'] === 'branch' && $state['cat'] === 'member') {
                        zw_file_center_render_branch_member($state);
                    } else {
                        zw_file_center_render_file_section($state);
                    }
                    ?>
                </main>
            </div>
        </div>
        <style>
        .zw-fc-wrap{max-width:none!important;margin-right:20px}
        .zw-fc-layout{display:grid;grid-template-columns:200px 240px minmax(0,1fr);gap:16px;align-items:start}
        .zw-fc-nav,.zw-fc-mid{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:10px;position:sticky;top:46px}
        .zw-fc-nav-title,.zw-fc-mid-title{font-size:12px;color:#8a8f99;padding:6px 10px 8px}
        .zw-fc-nav-item,.zw-fc-mid-item{display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:8px;color:#333;text-decoration:none;font-size:13px}
        @media(hover:hover){.zw-fc-nav-item:hover,.zw-fc-mid-item:hover{background:#f6f7f7;color:var(--primary-color,#d80505)}}
        .zw-fc-nav-item.is-active,.zw-fc-mid-item.is-active{background:#fff2f2;color:var(--primary-color,#d80505);font-weight:600}
        .zw-fc-main{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;min-height:420px}
        .zw-fc-section-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin-bottom:14px}
        .zw-fc-section-head h2{margin:4px 0 0;font-size:20px}
        .zw-fc-breadcrumb{font-size:12px;color:#8a8f99}
        .zw-fc-breadcrumb a{color:#8a8f99;text-decoration:none}
        .zw-fc-search{display:flex;gap:6px}
        .zw-fc-search input[type=search]{min-width:180px}
        /* TASK-2140：图标 / 列表切换（适老化 44px 热区 + 焦点可见；当前态不只靠颜色——aria-pressed + 高亮） */
        .zw-fc-section-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
        .zw-fc-view{display:inline-flex;gap:6px;background:#f4f5f7;border:1px solid #e5e7eb;border-radius:10px;padding:3px}
        .zw-fc-view-btn{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:0 14px;border-radius:8px;color:#4b5563;font-size:14px;line-height:1.2;text-decoration:none}
        .zw-fc-view-btn .ceofont{font-size:18px}
        .zw-fc-view-btn.is-active{background:#fff;color:var(--primary-color,#d80505);font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.08)}
        .zw-fc-view-btn:focus-visible{outline:3px solid var(--primary-color,#d80505);outline-offset:2px}
        @media(hover:hover){.zw-fc-view-btn:not(.is-active):hover{background:#fff;color:#111827}}
        /* TASK-2140：列表视图（名称/图标 = 默认打开；下载只走显式按钮） */
        .zw-fc-list td{vertical-align:middle;padding:10px}
        .zw-fc-list-open{display:inline-flex;align-items:center;gap:8px;min-height:44px;max-width:100%;color:inherit;text-decoration:none}
        .zw-fc-list-name-t{font-weight:600;font-size:14px;line-height:1.5;word-break:break-word}
        .zw-fc-list-open:focus-visible{outline:3px solid var(--primary-color,#d80505);outline-offset:2px}
        @media(hover:hover){.zw-fc-list-open:hover .zw-fc-list-name-t{color:var(--primary-color,#d80505);text-decoration:underline}}
        /* 元信息（大小 / 上传时间）取 14px：不低于是次 <14px 的告警线，也与后台表格既有字号一致。 */
        .zw-fc-list-size,.zw-fc-list-time{color:#6b7280;font-size:14px;white-space:nowrap}
        .zw-fc-list-actions{white-space:nowrap}
        @media(max-width:900px){.zw-fc-view-btn{padding:0 10px}}
        .zw-fc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
        .zw-fc-card{border:1px solid #eceff3;border-radius:10px;overflow:hidden;background:#fff;display:flex;flex-direction:column}
        .zw-fc-card-preview{height:120px;display:flex;align-items:center;justify-content:center;background:#f7f8fa;color:#c0c4cc;text-decoration:none}
        /* TASK-2008：预览区与文件名都是「默认打开」触发器（a.zw-fo-link，由 canonical 输出）⇒ 覆盖其内联排布，
           让它铺满 120px 预览框。**只调排版，不改任何色值**（色仍取页面既有值）。 */
        .zw-fc-card-preview.zw-fo-link,.zw-fc-card-preview.zw-fo-static{padding:0;margin:0;border-radius:0;justify-content:center;width:100%}
        .zw-fc-card-title-link{padding:0;margin:0;max-width:100%;vertical-align:bottom}
        .zw-fc-card-preview img{width:100%;height:100%;object-fit:cover}
        .zw-fc-card-preview .ceofont{font-size:40px}
        .zw-fc-card-body{padding:10px 10px 6px}
        .zw-fc-card-title{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .zw-fc-card-meta{display:flex;justify-content:space-between;gap:6px;font-size:11px;color:#8a8f99;margin-top:5px}
        .zw-fc-card-actions{display:flex;justify-content:space-between;gap:6px;padding:8px 10px 10px;margin-top:auto}
        .zw-fc-actions-main{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
        .zw-fc-upload{background:#f8f9fb;border:1px solid #eceff3;border-radius:8px;padding:10px;margin-bottom:10px}
        .zw-fc-upload form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .zw-fc-filter{margin:0 0 12px}
        .zw-fc-table td,.zw-fc-table th{vertical-align:middle}
        .zw-fc-empty{text-align:center;color:#8a8f99;padding:60px 20px}
        .zw-fc-empty .ceofont{font-size:38px;display:block;margin-bottom:8px;color:#c0c4cc}
        .zw-fc-pagination{display:flex;gap:6px;flex-wrap:wrap;margin-top:16px}
        .zw-fc-source{margin:12px 0 18px;border:1px solid #eceff3;border-radius:8px;background:#fbfcfd}
        .zw-fc-source summary{cursor:pointer;padding:10px 12px;font-weight:600;font-size:13px}
        .zw-fc-source-body{padding:0 12px 12px;font-size:12.5px;color:#555}
        .zw-fc-muted{color:#8a8f99;font-size:12px}
        .zw-fc-subtitle{margin:18px 0 8px}
        @media(max-width:1200px){.zw-fc-layout{grid-template-columns:170px 210px minmax(0,1fr)}}
        @media(max-width:900px){.zw-fc-layout{grid-template-columns:1fr}.zw-fc-nav,.zw-fc-mid{position:static}}
        <?php
        // TASK-1632（TASK-1579 §7 P1-6 余项）：宽表横滑包裹唯一出处＝canonical
        // （inc/admin/admin-ui-tokens.php 的 zw_adm_ui_table_wrap_css()）。
        // 「网盘文件中心」不在会员域外壳作用域内 ⇒ 须在本页**既有**内联 <style> 内输出该 canonical 规则；
        // **未**新增全局 CSS 文件、**未**复制第二套声明。
        echo zw_adm_ui_table_wrap_css();
        // TASK-1637（P1-4 余项 / 用户拍板②）：本页不在会员域外壳作用域内 ⇒ 触屏块同由 canonical
        // 输出、`body` 作用域（规则仅 `@media(pointer:coarse)` 命中 ⇒ 桌面零视觉变更）。
        echo function_exists('zw_adm_ui_touch_css') ? zw_adm_ui_touch_css('body') : '';
        ?>
        </style>
        <?php
        // TASK-2008：文件默认打开动作 canonical 资源（内部一次性输出 doc-viewer 弹层/图片灯箱 +
        // file-icon 样式 + 本控件样式）。三处后台页面（网盘/发文/收文）统一走这一个入口。
        if (function_exists('zw_control_file_open_assets')) {
            zw_control_file_open_assets();
        }
    }
}

/* ── POST 处理 ── */
if (!function_exists('zw_file_center_handle_actions')) {
    function zw_file_center_handle_actions() {
        if (!is_admin() || empty($_POST['zw_fc_action']) || !zw_file_center_can_manage()) { return; }
        $action = sanitize_key(wp_unslash((string) $_POST['zw_fc_action']));
        $return = !empty($_POST['fc_return']) ? wp_validate_redirect(esc_url_raw(wp_unslash((string) $_POST['fc_return'])), admin_url('admin.php?page=zw-org-files')) : admin_url('admin.php?page=zw-org-files');

        if ($action === 'upload_branch_file') {
            check_admin_referer('zw_fc_upload_branch');
            $branchId = isset($_POST['branch_id']) ? absint($_POST['branch_id']) : 0;
            $kindKey  = isset($_POST['branch_kind']) ? sanitize_key(wp_unslash((string) $_POST['branch_kind'])) : '';
            $branchPost = get_post($branchId);
            if (!$branchPost || !has_category('fzjg', $branchId) || !in_array($kindKey, array('dispatch', 'receipt', 'activity'), true)) {
                wp_safe_redirect(add_query_arg('fc_err', rawurlencode('分支机构或分类无效'), $return)); exit;
            }
            if (empty($_FILES['fc_file']['tmp_name']) || !is_uploaded_file($_FILES['fc_file']['tmp_name'])) {
                wp_safe_redirect(add_query_arg('fc_err', rawurlencode('请选择上传文件'), $return)); exit;
            }
            $fileId = zw_member_files_store_upload($_FILES['fc_file'], array(
                'scope'         => 'branch',
                'kind'          => 'branch_' . $kindKey,
                'user_id'       => get_current_user_id(),
                'uploaded_by'   => get_current_user_id(),
                'branch_id'     => $branchId,
                'branch_subtype'=> $kindKey,
                'source'        => 'admin_file_center',
                'title'         => !empty($_POST['file_title']) ? sanitize_text_field(wp_unslash((string) $_POST['file_title'])) : ('分支机构' . $kindKey),
            ));
            if (is_wp_error($fileId)) {
                wp_safe_redirect(add_query_arg('fc_err', rawurlencode($fileId->get_error_message()), $return)); exit;
            }
            wp_safe_redirect(add_query_arg('fc_msg', rawurlencode('文件已上传'), $return)); exit;
        }

        if ($action === 'delete_file') {
            $fileId = isset($_POST['file_id']) ? absint($_POST['file_id']) : 0;
            check_admin_referer('zw_fc_delete_file_' . $fileId);
            $scope = (string) get_post_meta($fileId, 'zw_file_scope', true);
            if (!in_array($scope, array('branch', 'branch_import_source'), true)) {
                wp_safe_redirect(add_query_arg('fc_err', rawurlencode('仅支持删除分支机构附件'), $return)); exit;
            }
            zw_member_files_delete($fileId);
            wp_safe_redirect(add_query_arg('fc_msg', rawurlencode('文件已删除'), $return)); exit;
        }

        if ($action === 'delete_source') {
            $fileId = isset($_POST['file_id']) ? absint($_POST['file_id']) : 0;
            $batchId = isset($_POST['batch_id']) ? absint($_POST['batch_id']) : 0;
            check_admin_referer('zw_fc_delete_source_' . $fileId);
            if ((string) get_post_meta($fileId, 'zw_file_scope', true) !== 'branch_import_source') {
                wp_safe_redirect(add_query_arg('fc_err', rawurlencode('原始导入包记录无效'), $return)); exit;
            }
            zw_member_files_delete($fileId);
            if ($batchId > 0) {
                update_post_meta($batchId, 'zw_batch_source_status', 'deleted');
                update_post_meta($batchId, 'zw_batch_source_deleted_at', time());
            }
            wp_safe_redirect(add_query_arg('fc_msg', rawurlencode('原始导入包已清理'), $return)); exit;
        }
    }
}
add_action('admin_init', 'zw_file_center_handle_actions');
