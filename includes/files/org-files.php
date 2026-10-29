<?php
/**
 * 网盘文件（内部办公统一文件存放区）
 *
 * 统一存放组织人员照片与内部办公文件；不进入 WordPress 媒体库。
 * 文件默认存放于 WP_CONTENT_DIR/zw-office-files，也可通过
 * option zw_office_files_base_dir 指定为非公开目录。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('is_admin') || (!is_admin() && !(defined('WP_CLI') && WP_CLI))) {
    return;
}

// TASK-546：文件中心一级/二级分类与分支机构资料视图。
if (file_exists(__DIR__ . '/file-center.php')) {
    require_once __DIR__ . '/file-center.php';
}

if (!defined('ZW_ORG_FILES_OPTION')) {
    define('ZW_ORG_FILES_OPTION', 'zw_office_files_base_dir');
}

if (!function_exists('zw_org_files_register_cpt')) {
    /**
     * **兼容入口**：网盘文件 CPT 的注册已收敛到存储层唯一出处
     * `inc/files/member-files.php::zw_member_files_register_cpt()`（TASK-2320 增量七）。
     *
     * 背景：此前本文件与存储层**各注册一次**，两处参数逐字相同但属重复实现 —— 任一处被改动都会
     * 造成「后台与前台看到的 CPT 参数不同」的隐性漂移（加载顺序决定谁生效）。
     * 现在本函数只做委托，参数只允许在 canonical 一处维护。
     *
     * @return void
     */
    function zw_org_files_register_cpt() {
        if (function_exists('zw_member_files_register_cpt')) {
            zw_member_files_register_cpt();
        }
    }
}
add_action('init', 'zw_org_files_register_cpt');

if (!function_exists('zw_org_files_base_dir')) {
    /**
     * 网盘文件存储根目录。
     *
     * @return string
     */
    function zw_org_files_base_dir() {
        $custom = get_option(ZW_ORG_FILES_OPTION, '');
        if (is_string($custom) && trim($custom) !== '') {
            return rtrim(trim($custom), '/\\');
        }
        if (defined('ZW_OFFICE_FILES_DIR') && ZW_OFFICE_FILES_DIR) {
            return rtrim((string) ZW_OFFICE_FILES_DIR, '/\\');
        }
        return rtrim(WP_CONTENT_DIR . '/zw-office-files', '/\\');
    }
}

if (!function_exists('zw_org_files_ensure_base_dir')) {
    /**
     * 确保网盘文件根目录存在且受保护。
     *
     * @return true|WP_Error
     */
    function zw_org_files_ensure_base_dir() {
        $dir = zw_org_files_base_dir();
        if (!@is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('zw_org_files_mkdir_failed', '网盘文件目录创建失败：' . $dir);
        }
        if (!@is_writable($dir)) {
            return new WP_Error('zw_org_files_not_writable', '网盘文件目录不可写：' . $dir);
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        return true;
    }
}

if (!function_exists('zw_org_files_ext_mime_map')) {
    /**
     * 允许上传的扩展名与 MIME。
     *
     * @return array
     */
    function zw_org_files_ext_mime_map() {
        return apply_filters('zw_org_files_ext_mime_map', array(
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
            'zip'  => 'application/zip',
            'rar'  => 'application/vnd.rar',
        ));
    }
}

if (!function_exists('zw_org_files_max_size')) {
    /**
     * 单文件大小上限。
     *
     * @return int
     */
    function zw_org_files_max_size() {
        return (int) apply_filters('zw_org_files_max_size', 20 * 1024 * 1024);
    }
}

if (!function_exists('zw_org_files_mime_from_file')) {
    /**
     * 读取真实 MIME。
     *
     * @param string $path 文件路径。
     * @return string
     */
    function zw_org_files_mime_from_file($path) {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        $mime = function_exists('mime_content_type') ? mime_content_type($path) : '';
        return is_string($mime) ? $mime : '';
    }
}

if (!function_exists('zw_org_files_build_relative_path')) {
    /**
     * 生成相对存储路径与文件名。
     *
     * @param string $original_name 原始文件名。
     * @param string $ext           扩展名。
     * @return string
     */
    function zw_org_files_build_relative_path($original_name, $ext) {
        $slug = sanitize_title(pathinfo($original_name, PATHINFO_FILENAME));
        if ($slug === '') {
            $slug = 'file';
        }
        $slug  = substr($slug, 0, 40);
        $stamp = gmdate('Ymd-His');
        $rand  = wp_generate_password(8, false, false);
        return gmdate('Y/m') . '/' . $stamp . '-' . $rand . '-' . $slug . '.' . strtolower($ext);
    }
}

if (!function_exists('zw_org_files_kinds')) {
    /**
     * 网盘文件归档类型白名单（唯一出处 · TASK-953 / SPEC-88 §1）。
     *
     * 政策法规分两类（外部政策 / 内部政策），与 AI 法务部的政策内外二分一一对应：
     * 外部政策=本会之外的权威机构制定（不可修改，只能遵照）；内部政策=本会自行制定（可修订）。
     *
     * @return array<string,string> kind => 中文标签
     */
    function zw_org_files_kinds() {
        return array(
            'person_photo'    => '人员照片',
            'document'        => '办公文档',
            'notice'          => '通知公告',
            'policy'          => '政策文件（历史）',
            'policy_external' => '外部政策',
            'policy_internal' => '内部政策',
        );
    }
}

if (!function_exists('zw_org_files_kind_label')) {
    /**
     * 归档类型中文标签；未知类型回退「内部文件」（与历史展示一致）。
     *
     * @param string $kind 类型键。
     * @return string
     */
    function zw_org_files_kind_label($kind) {
        $map = zw_org_files_kinds();
        return isset($map[(string) $kind]) ? $map[(string) $kind] : '内部文件';
    }
}

if (!function_exists('zw_org_files_create_record')) {
    /**
     * 创建内部文件记录。
     *
     * @param string $relative_path 相对路径。
     * @param string $original_name 原始文件名。
     * @param string $mime          MIME。
     * @param int    $size          大小。
     * @param string $kind          类型：person_photo|document。
     * @param int    $person_id     关联人员 ID。
     * @param string $title         标题。
     * @return int|WP_Error
     */
    function zw_org_files_create_record($relative_path, $original_name, $mime, $size, $kind = 'document', $person_id = 0, $title = '') {
        $file_id = wp_insert_post(array(
            'post_type'   => 'zw_office_file',
            'post_status' => 'publish',
            'post_title'  => $title !== '' ? sanitize_text_field($title) : sanitize_file_name($original_name),
        ));
        if (!$file_id || is_wp_error($file_id)) {
            return new WP_Error('zw_org_files_insert_failed', '文件记录创建失败。');
        }
        update_post_meta($file_id, 'zw_file_path', ltrim(str_replace('\\', '/', $relative_path), '/'));
        update_post_meta($file_id, 'zw_file_original_name', sanitize_file_name($original_name));
        update_post_meta($file_id, 'zw_file_mime', sanitize_mime_type($mime));
        update_post_meta($file_id, 'zw_file_size', (int) $size);
        update_post_meta($file_id, 'zw_file_kind', in_array($kind, array_keys(zw_org_files_kinds()), true) ? $kind : 'document');
        update_post_meta($file_id, 'zw_file_person_id', (int) $person_id);
        update_post_meta($file_id, 'zw_file_uploaded_by', get_current_user_id());
        update_post_meta($file_id, 'zw_file_uploaded_at', current_time('mysql'));
        return (int) $file_id;
    }
}

if (!function_exists('zw_org_files_validate_source')) {
    /**
     * 校验待入库文件。
     *
     * @param string $source_path   本地路径。
     * @param string $original_name 原始文件名。
     * @param int    $size          大小。
     * @return array|WP_Error {ext,mime}
     */
    function zw_org_files_validate_source($source_path, $original_name, $size) {
        if (!@is_readable($source_path)) {
            return new WP_Error('zw_org_files_unreadable', '文件不可读。');
        }
        $ext  = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $map  = zw_org_files_ext_mime_map();
        if ($ext === '' || !isset($map[$ext])) {
            return new WP_Error('zw_org_files_ext_not_allowed', '不支持的文件类型：' . $ext);
        }
        if ((int) $size <= 0 || (int) $size > zw_org_files_max_size()) {
            return new WP_Error('zw_org_files_too_large', '文件大小超出限制。');
        }
        $mime = zw_org_files_mime_from_file($source_path);
        if ($mime === '') {
            $mime = $map[$ext];
        }
        if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) {
            $info = @getimagesize($source_path);
            if (empty($info)) {
                return new WP_Error('zw_org_files_invalid_image', '图片文件无效。');
            }
        }
        return array('ext' => $ext, 'mime' => $mime);
    }
}

if (!function_exists('zw_org_files_store_local')) {
    /**
     * 将本地文件复制到网盘文件并创建记录。
     *
     * @param string $source_path   本地源路径。
     * @param string $original_name 原始文件名。
     * @param string $kind          类型。
     * @param int    $person_id     关联人员。
     * @param string $title         标题。
     * @return int|WP_Error
     */
    function zw_org_files_store_local($source_path, $original_name, $kind = 'document', $person_id = 0, $title = '') {
        $source_path = (string) $source_path;
        $size        = @is_readable($source_path) ? (int) @filesize($source_path) : 0;
        $checked     = zw_org_files_validate_source($source_path, $original_name, $size);
        if (is_wp_error($checked)) {
            return $checked;
        }
        $ensure = zw_org_files_ensure_base_dir();
        if (is_wp_error($ensure)) {
            return $ensure;
        }
        $relative = zw_org_files_build_relative_path($original_name, $checked['ext']);
        $target   = zw_org_files_base_dir() . '/' . $relative;
        if (!wp_mkdir_p(dirname($target))) {
            return new WP_Error('zw_org_files_mkdir_failed', '文件目录创建失败。');
        }
        if (!@copy($source_path, $target)) {
            return new WP_Error('zw_org_files_copy_failed', '文件入库失败。');
        }
        @chmod($target, 0644);
        return zw_org_files_create_record($relative, $original_name, $checked['mime'], $size, $kind, $person_id, $title);
    }
}

if (!function_exists('zw_org_files_store_upload')) {
    /**
     * 处理 $_FILES 上传并写入网盘文件。
     *
     * @param array  $file      $_FILES 单文件。
     * @param string $kind      类型。
     * @param int    $person_id 关联人员。
     * @param string $title     标题。
     * @return int|WP_Error
     */
    function zw_org_files_store_upload($file, $kind = 'document', $person_id = 0, $title = '') {
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('zw_org_files_no_file', '未接收到上传文件。');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('zw_org_files_upload_error', '文件上传失败，错误码：' . (int) $file['error']);
        }
        $original = isset($file['name']) ? sanitize_file_name(wp_unslash($file['name'])) : 'file';
        $size     = (int) $file['size'];
        $checked  = zw_org_files_validate_source($file['tmp_name'], $original, $size);
        if (is_wp_error($checked)) {
            return $checked;
        }
        $ensure = zw_org_files_ensure_base_dir();
        if (is_wp_error($ensure)) {
            return $ensure;
        }
        $relative = zw_org_files_build_relative_path($original, $checked['ext']);
        $target   = zw_org_files_base_dir() . '/' . $relative;
        if (!wp_mkdir_p(dirname($target))) {
            return new WP_Error('zw_org_files_mkdir_failed', '文件目录创建失败。');
        }
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return new WP_Error('zw_org_files_move_failed', '上传文件移动失败。');
        }
        @chmod($target, 0644);
        return zw_org_files_create_record($relative, $original, $checked['mime'], $size, $kind, $person_id, $title);
    }
}

if (!function_exists('zw_org_files_file')) {
    /**
     * 读取文件记录。
     *
     * @param int $file_id 文件 ID。
     * @return array
     */
    function zw_org_files_file($file_id) {
        $file_id = (int) $file_id;
        $post    = $file_id > 0 ? get_post($file_id) : null;
        if (!$post || $post->post_type !== 'zw_office_file') {
            return array();
        }
        $relative = (string) get_post_meta($file_id, 'zw_file_path', true);
        $path     = $relative !== '' ? zw_org_files_base_dir() . '/' . ltrim($relative, '/') : '';
        return array(
            'id'            => $file_id,
            'title'         => $post->post_title,
            'original_name' => (string) get_post_meta($file_id, 'zw_file_original_name', true),
            'path'          => $path,
            'relative'      => $relative,
            'mime'          => (string) get_post_meta($file_id, 'zw_file_mime', true),
            'size'          => (int) get_post_meta($file_id, 'zw_file_size', true),
            'kind'          => (string) get_post_meta($file_id, 'zw_file_kind', true),
            'person_id'     => (int) get_post_meta($file_id, 'zw_file_person_id', true),
            'uploaded_by'   => (int) get_post_meta($file_id, 'zw_file_uploaded_by', true),
            'uploaded_at'   => (string) get_post_meta($file_id, 'zw_file_uploaded_at', true),
            'exists'        => $path !== '' && @is_readable($path),
            'url'           => zw_org_files_download_url($file_id),
            'preview_url'   => zw_org_files_download_url($file_id, true),
        );
    }
}

if (!function_exists('zw_org_files_download_url')) {
    /**
     * 文件下载代理 URL。
     *
     * @param int  $file_id 文件 ID。
     * @param bool $preview 是否内联预览。
     * @return string
     */
    function zw_org_files_download_url($file_id, $preview = false) {
        $file_id = (int) $file_id;
        $args    = array(
            'action'   => 'zw_org_file_download',
            'file_id'  => $file_id,
            '_wpnonce' => wp_create_nonce('zw_org_file_download_' . $file_id),
        );
        if ($preview) {
            $args['preview'] = 1;
        }
        return add_query_arg($args, admin_url('admin-post.php'));
    }
}

if (!function_exists('zw_org_files_person_photo')) {
    /**
     * 读取人员照片文件。
     *
     * @param int $person_id 人员 ID。
     * @return array
     */
    function zw_org_files_person_photo($person_id) {
        $file_id = (int) get_post_meta((int) $person_id, 'zw_person_photo_file_id', true);
        return $file_id > 0 ? zw_org_files_file($file_id) : array();
    }
}

if (!function_exists('zw_org_files_list')) {
    /**
     * 文件列表。
     *
     * @param string $kind      类型，空为全部。
     * @param int    $person_id 关联人员，0 为全部。
     * @return WP_Post[]
     */
    function zw_org_files_list($kind = '', $person_id = 0) {
        $meta_query = array();
        if ($kind !== '') {
            $meta_query[] = array('key' => 'zw_file_kind', 'value' => sanitize_key($kind));
        }
        if ($person_id > 0) {
            $meta_query[] = array('key' => 'zw_file_person_id', 'value' => (int) $person_id);
        }
        return get_posts(array(
            'post_type'      => 'zw_office_file',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => $meta_query,
        ));
    }
}

if (!function_exists('zw_org_files_delete')) {
    /**
     * 删除文件记录与磁盘文件。
     *
     * @param int $file_id 文件 ID。
     * @return bool
     */
    function zw_org_files_delete($file_id) {
        $file = zw_org_files_file($file_id);
        if (empty($file)) {
            return false;
        }
        if (!empty($file['path']) && @is_readable($file['path'])) {
            @unlink($file['path']);
        }
        return (bool) wp_delete_post((int) $file_id, true);
    }
}

if (!function_exists('zw_org_files_format_size')) {
    /**
     * 文件大小格式化。
     *
     * @param int $bytes 字节。
     * @return string
     */
    function zw_org_files_format_size($bytes) {
        $bytes = (int) $bytes;
        if ($bytes >= 1048576) {
            return number_format_i18n($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format_i18n($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}

if (!function_exists('zw_org_files_handle_download')) {
    /**
     * 后台代理下载 / 预览文件。
     *
     * @return void
     */
    function zw_org_files_handle_download() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限');
        }
        $file_id = isset($_GET['file_id']) ? absint($_GET['file_id']) : 0;
        check_admin_referer('zw_org_file_download_' . $file_id);
        $file = zw_org_files_file($file_id);
        if (empty($file) || empty($file['exists'])) {
            wp_die('文件不存在或已被删除。');
        }
        $is_preview = !empty($_GET['preview']);
        $filename   = $file['original_name'] !== '' ? $file['original_name'] : $file['title'];
        nocache_headers();
        header('Content-Type: ' . ($file['mime'] !== '' ? $file['mime'] : 'application/octet-stream'));
        header('Content-Length: ' . (string) @filesize($file['path']));
        header('Content-Disposition: ' . ($is_preview && strpos((string) $file['mime'], 'image/') === 0 ? 'inline' : 'attachment') . '; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        readfile($file['path']);
        exit;
    }
}
add_action('admin_post_zw_org_file_download', 'zw_org_files_handle_download');

if (!function_exists('zw_org_page_files')) {
    /**
     * 网盘文件后台页。
     *
     * @return void
     */
    function zw_org_page_files() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限');
        }
        if (function_exists('zw_file_center_render')) {
            zw_file_center_render();
            return;
        }
        $ensure = zw_org_files_ensure_base_dir();
        $files  = zw_org_files_list();
        $notice = zw_org_admin_take_notice();
        zw_org_admin_styles();
        ?>
        <style id="zw-org-files-css">
        /* TASK-1646（TASK-1579 P2-1 批次 3 / SPEC-10 §1.9.3 第 2/3 项；机制出处 CHG-300）：
           下列旧类为**兼容别名**（阶段 ① 双写）：canonical 规则的选择器组同时承载
           `.zw-adm-org-file-*` 与旧类 `.zw-org-file-*`，声明体逐字节未改（零取值变化）；
           标记面同时携带新类与旧类。**双写一个发布周期内不得删除任何旧类**。
           废止标记逐类见 `zw_org_admin_styles()`（本域唯一登记处，org-admin.php）。 */
        .zw-adm-org-file-layout,.zw-org-file-layout{display:grid;grid-template-columns:minmax(0,1fr);gap:16px}
        .zw-adm-org-file-name,.zw-org-file-name{font-weight:600;color:#222}
        .zw-adm-org-file-preview,.zw-org-file-preview{width:48px;height:48px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb;vertical-align:middle}
        .zw-adm-org-file-icon,.zw-org-file-icon{display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:8px;background:#f2f3f5;color:#8a8f99;font-size:20px}
        .zw-adm-org-file-meta,.zw-org-file-meta{font-size:12px;color:#999;margin-top:3px}
        .zw-adm-org-file-actions,.zw-org-file-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        </style>
        <?php
        // TASK-2008：文件默认打开动作 canonical 资源（doc-viewer 弹层/图片灯箱 + file-icon + 本控件样式）。
        if (function_exists('zw_control_file_open_assets')) {
            zw_control_file_open_assets();
        }
        ?>
        <div class="wrap zw-adm-org-wrap zw-org-wrap">
            <div class="zw-adm-org-head zw-org-head">
                <h1>网盘文件</h1>
                <p>内部办公统一文件存放区：照片、附件、文档均存放于此，不进入 WordPress 媒体库，不提供公网访问。</p>
            </div>
            <?php if ($notice) : ?>
                <div class="zw-adm-org-notice zw-org-notice <?php echo esc_attr($notice['type']); ?>"><?php echo esc_html($notice['text']); ?></div>
            <?php endif; ?>
            <?php if (is_wp_error($ensure)) : ?>
                <div class="zw-adm-org-notice zw-org-notice error"><?php echo esc_html($ensure->get_error_message()); ?></div>
            <?php endif; ?>
            <div class="zw-adm-org-kpis zw-org-kpis">
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">文件总数</div><div class="n"><?php echo (int) count($files); ?></div><div class="d">独立存储，不进媒体库</div></div>
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">人员照片</div><div class="n"><?php echo (int) count(zw_org_files_list('person_photo')); ?></div><div class="d">关联组织人员</div></div>
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">存储位置</div><div class="n" style="font-size:14px;padding-top:7px;word-break:break-all"><?php echo esc_html(zw_org_files_base_dir()); ?></div><div class="d">可后台下载/预览</div></div>
                <div class="zw-adm-org-kpi zw-org-kpi"><div class="l">访问方式</div><div class="n" style="font-size:18px;padding-top:6px">仅后台</div><div class="d">manage_options + nonce</div></div>
            </div>
            <div class="zw-org-card">
                <div class="zw-org-card-head"><span class="ct">上传文件</span><span class="desc">单文件上限 <?php echo esc_html(zw_org_files_format_size(zw_org_files_max_size())); ?></span></div>
                <div class="zw-org-card-body">
                    <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org-files', admin_url('admin.php'))); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('zw_org_upload_file'); ?>
                        <input type="hidden" name="zw_org_action" value="upload_file">
                        <div class="zw-org-grid">
                            <div class="zw-adm-org-field zw-org-field">
                                <label for="zw-org-file-title">文件标题</label>
                                <input type="text" id="zw-org-file-title" name="file_title" placeholder="可选，默认使用文件名">
                            </div>
                            <div class="zw-adm-org-field zw-org-field">
                                <label for="zw-org-file-kind">归档目录</label>
                                <select id="zw-org-file-kind" name="file_kind">
                                    <?php foreach (zw_org_files_kinds() as $zw_kind_key => $zw_kind_label) : ?>
                                        <option value="<?php echo esc_attr($zw_kind_key); ?>"><?php echo esc_html($zw_kind_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="zw-adm-org-field zw-org-field">
                                <label for="zw-org-file-input">选择文件</label>
                                <input type="file" id="zw-org-file-input" name="office_file" required>
                            </div>
                        </div>
                        <div class="zw-adm-org-form-actions zw-org-form-actions">
                            <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-primary zw-org-btn-primary">上传到网盘文件</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="zw-org-card">
                <div class="zw-org-card-head"><span class="ct">文件列表</span><span class="desc">共 <?php echo (int) count($files); ?> 个</span></div>
                <div class="zw-org-card-body" style="padding:0">
                    <?php if (empty($files)) : ?>
                        <div class="zw-org-empty">暂无文件，可上传照片、文档或附件。</div>
                    <?php else : ?>
                        <div class="zw-org-tbl-wrap zw-adm-table-wrap">
                            <table class="zw-adm-org-tbl zw-org-tbl">
                                <thead><tr><th>文件</th><th>类型</th><th>大小</th><th>关联人员</th><th>上传时间</th><th>操作</th></tr></thead>
                                <tbody>
                                <?php foreach ($files as $post) :
                                    $file = zw_org_files_file($post->ID);
                                    if (empty($file)) { continue; }
                                    $person_title = $file['person_id'] > 0 ? get_the_title($file['person_id']) : '';
                                    $is_image     = strpos((string) $file['mime'], 'image/') === 0;
                                    $fi_name      = (string) $file['original_name'] !== '' ? (string) $file['original_name'] : (string) $file['title'];
                                    // TASK-2008：图标 / 缩略图 与 文件名都是「默认打开 = 在线浏览」的触发器；
                                    // 下载只由右侧显式「下载」按钮触发（canonical `zw_control_file_download_button()`）。
                                    $open_args = array(
                                        'name'         => $fi_name,
                                        'source'       => 'netdisk',
                                        'id'           => (int) $post->ID,
                                        'size'         => (int) $file['size'],
                                        'download_url' => (string) $file['url'],
                                    );
                                    $thumb = $is_image && $file['exists']
                                        ? '<img class="zw-adm-org-file-preview zw-org-file-preview" src="' . esc_url($file['preview_url']) . '" alt="">'
                                        : (function_exists('zw_control_file_icon')
                                            ? (string) zw_control_file_icon(array('name' => $fi_name, 'size' => 'lg'))
                                            : '<span class="zw-adm-org-file-icon zw-org-file-icon">文</span>');
                                    $thumb_link = function_exists('zw_control_file_open_link')
                                        ? zw_control_file_open_link(array_merge($open_args, array(
                                            'content'    => $thumb,
                                            'class'      => 'zw-org-file-open',
                                            'aria_label' => ($is_image ? '查看图片：' : '在线浏览：') . $fi_name,
                                        )))
                                        : $thumb;
                                    $name_link = function_exists('zw_control_file_open_link')
                                        ? zw_control_file_open_link(array_merge($open_args, array('icon_size' => '')))
                                        : esc_html($file['title']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex;gap:10px;align-items:center">
                                                <?php echo $thumb_link; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?>
                                                <div>
                                                    <div class="zw-adm-org-file-name zw-org-file-name"><?php echo $name_link; // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义 ?></div>
                                                    <div class="zw-adm-org-file-meta zw-org-file-meta"><?php echo esc_html($file['original_name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo esc_html(zw_org_files_kind_label($file['kind'] ?? '')); ?></td>
                                        <td><?php echo esc_html(zw_org_files_format_size($file['size'])); ?></td>
                                        <td><?php echo $person_title !== '' ? esc_html($person_title) : '<span class="zw-adm-org-muted zw-org-muted">—</span>'; ?></td>
                                        <td><?php echo esc_html($file['uploaded_at']); ?></td>
                                        <td>
                                            <div class="zw-adm-org-file-actions zw-org-file-actions">
                                                <?php
                                                // TASK-2008：下载 = 显式按钮（唯一入口），不再在这里并列「在线浏览」触发器。
                                                // TASK-2008：下载 = 显式按钮（唯一入口，且**无兜底第二套**）——
                                                // `inc/controls/bootstrap.php` 在 functions.php 里恒被 require，
                                                // 因此不再保留「自己拼一个 `<a href=下载>`」的 fallback（那会是守卫盲区）。
                                                echo zw_control_file_download_button(array( // phpcs:ignore WordPress.Security.EscapeOutput -- canonical 控件自带转义
                                                    'url'   => (string) $file['url'],
                                                    'name'  => $fi_name,
                                                    'class' => 'zw-adm-org-btn zw-org-btn',
                                                    'icon'  => false,
                                                ));
                                                ?>
                                                <form method="post" action="<?php echo esc_url(add_query_arg('page', 'zw-org-files', admin_url('admin.php'))); ?>" onsubmit="return confirm('确认删除该文件？');">
                                                    <?php wp_nonce_field('zw_org_delete_file_' . $post->ID); ?>
                                                    <input type="hidden" name="zw_org_action" value="delete_file">
                                                    <input type="hidden" name="file_id" value="<?php echo (int) $post->ID; ?>">
                                                    <button type="submit" class="zw-adm-org-btn zw-org-btn zw-adm-org-btn-danger zw-org-btn-danger">删除</button>
                                                </form>
                                            </div>
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
        <?php
    }
}
