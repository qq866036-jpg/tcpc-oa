<?php
/**
 * 公文印章资产与用印策略 —— TASK-769 / SPEC-87 §10。
 *
 * 安全铁律（本文件是公章相关能力的唯一实现，改动必须过安全评审）：
 * 1. **私有存储**：印章文件存 **webroot 之外**（`ABSPATH` 的父级目录），**不注册为媒体附件**、
 *    不进媒体库、文件名随机化、无目录列举；只能经带 nonce 与能力校验的端点读取；
 * 2. **上传白名单**：仅 PNG；`getimagesize()` 二次校验真实类型；短边不低于 `seal.min_source_px`；
 *    体积不超过 `seal.max_bytes`；**明确拒绝 SVG**；
 * 3. **透明底检测**：白底不透明印章会遮住落款文字，检测后如实标注（不静默通过、不自行抠图）；
 * 4. **用印门禁 G1~G7 服务端强制**，任一不满足即拒绝；其中「无审批不得用印」在法务接入（TASK-771）
 *    完成前**默认阻断** —— 安全默认优于可用性；
 * 5. **用印后锁定正文**（复用 `zw_doc_set_locked()`），撤销用印才解锁；全程写审计事件；
 * 6. 审计与日志**不记正文内容、不记印章文件路径**；
 * 7. 印章素材**不得进入 git 仓库**（含 docs/、tests/、dist/）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_SEAL_VERSION')) {
    define('ZW_DOC_SEAL_VERSION', '1');
}

if (!function_exists('zw_doc_seal_require_legal')) {
    /**
     * 用印是否必须已取得法务审批结论（默认**必须**）。
     *
     * 对应法务平台既有执行规则「无审批不得用印」。TASK-771 接入法务结论前，默认阻断用印。
     *
     * @return bool
     */
    function zw_doc_seal_require_legal() {
        $value = get_option('zw_doc_seal_require_legal', 1);
        if (function_exists('apply_filters')) {
            $value = apply_filters('zw_doc_seal_require_legal', $value);
        }

        return !empty($value);
    }
}

if (!function_exists('zw_doc_seal_dir')) {
    /**
     * 印章私有目录（**webroot 之外**）。
     *
     * 优先使用常量 `ZW_DOC_SEAL_DIR`；否则用固定路径 `/opt/zw-doc-seal`。
     *
     * **为什么不再用 `dirname(ABSPATH)`**（TASK-777 生产实测修正）：
     * 生产站点的 `ABSPATH` 为 `/www/wwwroot/tcpc.org.cn/`，其父级 `/www/wwwroot` 属 root/www 且
     * **PHP-FPM 运行用户 www 无写权限** → 首次上传印章时 `mkdir` 必然失败。
     * 改为固定路径并由 `scripts/install-doc-render-worker.sh` 以 0750 + www:www 预创建。
     * 该路径仍在 webroot 之外（`/www/wwwroot/tcpc.org.cn` 之外），私有性不受影响。
     *
     * @return string
     */
    function zw_doc_seal_dir() {
        if (defined('ZW_DOC_SEAL_DIR') && ZW_DOC_SEAL_DIR !== '') {
            return rtrim((string) ZW_DOC_SEAL_DIR, '/');
        }
        return '/opt/zw-doc-seal';
    }
}

if (!function_exists('zw_doc_seal_ensure_dir')) {
    /**
     * 确保私有目录存在并落防御性守卫文件。
     *
     * @return string 目录路径；失败返回空串。
     */
    function zw_doc_seal_ensure_dir() {
        $dir = zw_doc_seal_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return '';
        }
        // 防御性：万一目录被放到可访问位置，索引与目录列举仍被挡。
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }

        return $dir;
    }
}

if (!function_exists('zw_doc_seal_registry')) {
    /**
     * 印章登记表（option `zw_doc_seals`）。
     *
     * @return array id => meta
     */
    function zw_doc_seal_registry() {
        $list = get_option('zw_doc_seals', array());
        if (!is_array($list)) {
            $list = array();
        }
        $out = array();
        foreach ($list as $id => $meta) {
            if (!is_array($meta) || !isset($meta['file'])) {
                continue;
            }
            $out[(string) $id] = $meta;
        }

        return $out;
    }
}

if (!function_exists('zw_doc_seal_save_registry')) {
    /**
     * 保存印章登记表。
     *
     * @param array $list 登记表。
     * @return void
     */
    function zw_doc_seal_save_registry($list) {
        update_option('zw_doc_seals', is_array($list) ? $list : array(), false);
    }
}

if (!function_exists('zw_doc_seal_get')) {
    /**
     * 取单个印章元数据。
     *
     * @param string $seal_id 印章 ID。
     * @return array|null
     */
    function zw_doc_seal_get($seal_id) {
        $list = zw_doc_seal_registry();
        $id   = (string) $seal_id;

        return isset($list[$id]) ? $list[$id] : null;
    }
}

if (!function_exists('zw_doc_seal_path')) {
    /**
     * 印章文件的绝对路径（仅服务端内部使用，禁止外泄到前端）。
     *
     * @param string $seal_id 印章 ID。
     * @return string 不存在返回空串。
     */
    function zw_doc_seal_path($seal_id) {
        $meta = zw_doc_seal_get($seal_id);
        if (!$meta || empty($meta['file'])) {
            return '';
        }
        $path = zw_doc_seal_dir() . '/' . basename((string) $meta['file']);

        return is_file($path) ? $path : '';
    }
}

if (!function_exists('zw_doc_seal_is_transparent')) {
    /**
     * 检测 PNG 是否透明底（抽样四角 alpha）。
     *
     * 只做**如实标注**，不自行抠图、不做白底去除 —— 改动印章外观有伪造风险。
     *
     * @param string $path PNG 路径。
     * @return bool|null true=透明底；false=白底不透明；null=无法判定。
     */
    function zw_doc_seal_is_transparent($path) {
        if (!function_exists('imagecreatefrompng')) {
            return null;
        }
        $im = @imagecreatefrompng($path);
        if (!$im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $corners = array(array(0, 0), array(max(0, $w - 1), 0), array(0, max(0, $h - 1)), array(max(0, $w - 1), max(0, $h - 1)));
        $transparent = 0;
        foreach ($corners as $point) {
            $rgba  = imagecolorat($im, $point[0], $point[1]);
            $alpha = ($rgba >> 24) & 0x7F; // 0 = 完全不透明，127 = 完全透明
            if ($alpha > 0) {
                $transparent++;
            }
        }
        imagedestroy($im);

        return $transparent >= 2;
    }
}

if (!function_exists('zw_doc_seal_effective_dpi')) {
    /**
     * 印章有效分辨率（按 SPEC-87 章径折算）。
     *
     * @param string $seal_id 印章 ID。
     * @return int
     */
    function zw_doc_seal_effective_dpi($seal_id) {
        $meta = zw_doc_seal_get($seal_id);
        if (!$meta) {
            return 0;
        }
        $short = min((int) $meta['w'], (int) $meta['h']);

        // TASK-1985：按**该章真实直径**折算（逐章登记优先，未登记回退兼容常量）。
        return function_exists('zw_doc_gb9704_seal_effective_dpi')
            ? zw_doc_gb9704_seal_effective_dpi($short, zw_doc_seal_diameter_mm($seal_id))
            : 0;
    }
}

if (!function_exists('zw_doc_seal_can_use')) {
    /**
     * 是否具备用印能力。
     *
     * 口径：`manage_options` 放行（沿用发文模块既有口径，避免上线锁死运维入口）
     * 或 `tcpc_perm_doc_seal`（该能力的注册属 TASK-771 的权限收敛范围）。
     *
     * @param int $user_id 用户 ID（0 = 当前用户）。
     * @return bool
     */
    function zw_doc_seal_can_use($user_id = 0) {
        if (!function_exists('current_user_can')) {
            return true; // CLI / 工人在受控上下文调用
        }
        if (current_user_can('manage_options')) {
            return true;
        }

        return (bool) current_user_can('tcpc_perm_doc_seal');
    }
}

if (!function_exists('zw_doc_seal_validate_upload')) {
    /**
     * 校验上传的印章文件。
     *
     * @param array $file 单文件数组（name/tmp_name/error/size）。
     * @return array|WP_Error 通过时返回 array{ext,w,h,bytes,transparent,sha256}。
     */
    function zw_doc_seal_validate_upload($file) {
        $spec        = function_exists('zw_doc_gb9704_spec') ? zw_doc_gb9704_spec() : array();
        $allowed     = isset($spec['seal']['allowed_ext']) ? (array) $spec['seal']['allowed_ext'] : array('png');
        $max_bytes   = isset($spec['seal']['max_bytes']) ? (int) $spec['seal']['max_bytes'] : 2097152;
        $min_side    = isset($spec['seal']['min_source_px']) ? (int) $spec['seal']['min_source_px'] : 600;

        if (!is_array($file) || !isset($file['tmp_name'])) {
            return new WP_Error('zw_doc_seal_no_file', '未收到上传文件。');
        }
        if ((int) $file['error'] !== 0) {
            return new WP_Error('zw_doc_seal_upload_error', '印章上传失败（错误码 ' . (int) $file['error'] . '）。');
        }
        $tmp   = (string) $file['tmp_name'];
        $bytes = (int) $file['size'];
        if ($bytes <= 0 || $bytes > $max_bytes) {
            return new WP_Error('zw_doc_seal_size', '印章文件必须在 ' . (int) round($max_bytes / 1048576) . 'MB 以内。');
        }
        if (!is_file($tmp) || !is_readable($tmp)) {
            return new WP_Error('zw_doc_seal_unreadable', '印章文件不可读。');
        }

        $name = isset($file['name']) ? (string) $file['name'] : '';
        $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            return new WP_Error('zw_doc_seal_svg', '不接受 SVG（可内嵌脚本），请上传 PNG。');
        }
        if (!in_array($ext, $allowed, true)) {
            return new WP_Error('zw_doc_seal_ext', '印章只接受 PNG（透明底）。');
        }

        $size = @getimagesize($tmp);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return new WP_Error('zw_doc_seal_not_image', '无法识别印章图片真实类型。');
        }
        if ((int) $size[2] !== IMAGETYPE_PNG) {
            return new WP_Error('zw_doc_seal_mime', '印章图片真实类型不是 PNG。');
        }
        $w     = (int) $size[0];
        $h     = (int) $size[1];
        $short = min($w, $h);
        if ($short < $min_side) {
            return new WP_Error(
                'zw_doc_seal_too_small',
                '印章图片过小（短边 ' . $short . 'px，要求 ≥ ' . $min_side . 'px）：打印会发虚。'
            );
        }

        $sha = @hash_file('sha256', $tmp);

        return array(
            'ext'        => 'png',
            'w'          => $w,
            'h'          => $h,
            'bytes'      => $bytes,
            'transparent' => zw_doc_seal_is_transparent($tmp),
            'sha256'     => is_string($sha) ? $sha : '',
        );
    }
}

if (!function_exists('zw_doc_seal_store_upload')) {
    /**
     * 落盘并登记印章。
     *
     * @param array  $file  单文件数组。
     * @param string $name  显示名。
     * @param int    $actor 操作人。
     * @param mixed  $diameter_mm 真实印章直径（mm；TASK-1985 用户口径「真实印章是 4cm 直径」）。
     *                            空/非法 ⇒ 不写该字段（渲染回退兼容常量）。
     * @return array|WP_Error 成功返回印章元数据。
     */
    function zw_doc_seal_store_upload($file, $name = '', $actor = 0, $diameter_mm = '') {
        $valid = zw_doc_seal_validate_upload($file);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $dir = zw_doc_seal_ensure_dir();
        if ($dir === '') {
            return new WP_Error('zw_doc_seal_dir', '无法创建印章私有目录。');
        }

        $seal_id = bin2hex(random_bytes(8));
        $file_name = 'seal-' . $seal_id . '.png';
        $target    = $dir . '/' . $file_name;

        $moved = false;
        if (function_exists('is_uploaded_file') && is_uploaded_file($file['tmp_name'])) {
            $moved = @move_uploaded_file($file['tmp_name'], $target);
        }
        if (!$moved) {
            $moved = @copy($file['tmp_name'], $target);
        }
        if (!$moved || !is_file($target)) {
            return new WP_Error('zw_doc_seal_move', '印章文件落盘失败。');
        }
        @chmod($target, 0640);

        // TASK-1985：真实章径（mm）逐章登记 —— 版面宽度与有效 dpi 都按它折算（唯一事实来源）。
        $diameter = zw_doc_seal_normalize_diameter($diameter_mm);

        $meta = array(
            'id'           => $seal_id,
            'file'         => $file_name,
            'name'         => $name !== '' ? $name : '印章',
            'w'            => (int) $valid['w'],
            'h'            => (int) $valid['h'],
            'bytes'        => (int) $valid['bytes'],
            'sha256'       => (string) $valid['sha256'],
            'transparent'  => $valid['transparent'],
            'enabled'      => 1,
            'uploaded_by'  => (int) ($actor ? $actor : get_current_user_id()),
            'uploaded_at'  => current_time('mysql'),
            'effective_dpi' => function_exists('zw_doc_gb9704_seal_effective_dpi') ? zw_doc_gb9704_seal_effective_dpi(min((int) $valid['w'], (int) $valid['h']), $diameter) : 0,
        );
        if ($diameter > 0) {
            $meta['diameter_mm']     = $diameter;
            $meta['diameter_set_at'] = current_time('mysql');
            $meta['diameter_set_by'] = (int) ($actor ? $actor : get_current_user_id());
        }

        $list             = zw_doc_seal_registry();
        $list[$seal_id]   = $meta;
        zw_doc_seal_save_registry($list);

        update_option('zw_doc_default_seal_id', $seal_id, false);

        if (function_exists('zw_doc_log_event')) {
            $color = ($meta['transparent'] === false) ? '，注意：白底不透明，压章会遮挡落款文字' : '';
            zw_doc_log_event(0, 'seal_uploaded', $meta['uploaded_by'], '', '', '上传印章 #' . $seal_id . '（' . (int) $meta['w'] . '×' . (int) $meta['h'] . 'px，' . (int) $meta['effective_dpi'] . 'dpi' . $color . '）');
        }

        return $meta;
    }
}

if (!function_exists('zw_doc_seal_normalize_diameter')) {
    /**
     * 归一「真实印章直径」（mm）—— 唯一校验入口（TASK-1985）。
     *
     * 用户口径（2026-10-24）：「**真实印章是 4cm 直径**」⇒ 章径是**实物事实**，必须逐章登记，
     * 不得由程序猜（旧实现只有全局常量 42mm）。
     *
     * 边界（法规）：国发〔1999〕25号 第十四条「企业事业单位、社会团体的印章，直径**不得大于 4.5 厘米**」；
     * 下限取 `seal.diameter_min_mm`（默认 30mm，仅作防呆，无国标依据）。
     * 非法输入返回 0（调用方按「未登记」处理，回退全局常量，**不写脏值**）。
     *
     * @param mixed $mm 待归一的值（字符串/浮点均可，允许 4 / 4.0 / 40 / "40.0"）。
     * @return float 合法值（1 位小数）；非法返回 0.0。
     */
    function zw_doc_seal_normalize_diameter($mm) {
        if (is_string($mm)) {
            $mm = trim(str_replace(array('mm', 'MM', '毫米'), '', $mm));
            if ($mm === '' || !is_numeric($mm)) {
                return 0.0;
            }
        }
        if (!is_numeric($mm)) {
            return 0.0;
        }
        $value = (float) $mm;
        // 容错：用户按「厘米」填（4 / 4.2 / 4.5）时按厘米理解 —— 42/45 与 4/4.5 的取值域不重叠。
        if ($value > 0 && $value < 10) {
            $value = $value * 10;
        }
        $min = function_exists('zw_doc_gb9704_get') ? (float) zw_doc_gb9704_get('seal.diameter_min_mm', 30.0) : 30.0;
        $max = function_exists('zw_doc_gb9704_get') ? (float) zw_doc_gb9704_get('seal.diameter_max_mm', 45.0) : 45.0;
        if ($value < $min || $value > $max) {
            return 0.0;
        }

        return round($value, 1);
    }
}

if (!function_exists('zw_doc_seal_diameter_mm')) {
    /**
     * 该印章的**真实直径**（mm）—— 渲染与 dpi 折算的唯一取值入口（TASK-1985）。
     *
     * 优先级：登记表 `diameter_mm`（合法）→ 全局兼容常量 `seal.diameter_mm`。
     * 渲染层（HTML/加密 PDF）与有效 dpi 都必须经本函数取值，**禁止**各通道自取常量（否则「用哪个直径」会漂移）。
     *
     * @param string $seal_id 印章 ID。
     * @return float
     */
    function zw_doc_seal_diameter_mm($seal_id) {
        $meta = zw_doc_seal_get($seal_id);
        if (is_array($meta) && isset($meta['diameter_mm'])) {
            $value = zw_doc_seal_normalize_diameter($meta['diameter_mm']);
            if ($value > 0) {
                return $value;
            }
        }

        return function_exists('zw_doc_gb9704_get') ? (float) zw_doc_gb9704_get('seal.diameter_mm', 42.0) : 42.0;
    }
}

if (!function_exists('zw_doc_seal_set_diameter')) {
    /**
     * 登记/修改印章的真实直径（mm）。只改登记表，**不动图片文件、不动任何发文**。
     *
     * 已用印的公文不受影响：产物（加密 PDF）按生成时的章径留档，历史不重排。
     *
     * @param string $seal_id 印章 ID。
     * @param mixed  $mm      真实直径（mm；允许 4 = 4cm 的写法）。
     * @return bool
     */
    function zw_doc_seal_set_diameter($seal_id, $mm) {
        $id   = (string) $seal_id;
        $list = zw_doc_seal_registry();
        if ($id === '' || !isset($list[$id])) {
            return false;
        }
        $value = zw_doc_seal_normalize_diameter($mm);
        if ($value <= 0) {
            return false;
        }
        $list[$id]['diameter_mm']  = $value;
        $list[$id]['diameter_set_at'] = current_time('mysql');
        $list[$id]['diameter_set_by'] = get_current_user_id();
        // 有效 dpi 随章径变化（同一素材、章径越小 dpi 越高）⇒ 同步刷新登记值。
        $short = min((int) $list[$id]['w'], (int) $list[$id]['h']);
        $list[$id]['effective_dpi'] = function_exists('zw_doc_gb9704_seal_effective_dpi')
            ? (int) zw_doc_gb9704_seal_effective_dpi($short, $value)
            : 0;
        zw_doc_seal_save_registry($list);

        if (function_exists('zw_doc_log_event')) {
            zw_doc_log_event(0, 'seal_diameter_set', get_current_user_id(), '', '',
                '登记印章 #' . $id . ' 真实直径 ' . $value . 'mm（有效 ' . (int) $list[$id]['effective_dpi'] . 'dpi）');
        }

        return true;
    }
}

if (!function_exists('zw_doc_seal_delete')) {
    /**
     * 删除印章（含文件）。
     *
     * @param string $seal_id 印章 ID。
     * @return bool
     */
    function zw_doc_seal_delete($seal_id) {
        $id   = (string) $seal_id;
        $list = zw_doc_seal_registry();
        if (!isset($list[$id])) {
            return false;
        }
        $path = zw_doc_seal_path($id);
        if ($path !== '') {
            @unlink($path);
        }
        unset($list[$id]);
        zw_doc_seal_save_registry($list);
        if ((string) get_option('zw_doc_default_seal_id', '') === $id) {
            update_option('zw_doc_default_seal_id', '', false);
        }

        return true;
    }
}

if (!function_exists('zw_doc_seal_set_enabled')) {
    /**
     * 启用 / 停用印章。
     *
     * @param string $seal_id 印章 ID。
     * @param bool   $enabled 是否启用。
     * @return bool
     */
    function zw_doc_seal_set_enabled($seal_id, $enabled) {
        $id   = (string) $seal_id;
        $list = zw_doc_seal_registry();
        if (!isset($list[$id])) {
            return false;
        }
        $list[$id]['enabled'] = $enabled ? 1 : 0;
        zw_doc_seal_save_registry($list);

        return true;
    }
}

if (!function_exists('zw_doc_seal_set_default')) {
    /**
     * 设为默认印章（全局唯一）。
     *
     * @param string $seal_id 印章 ID。
     * @return bool
     */
    function zw_doc_seal_set_default($seal_id) {
        $id = (string) $seal_id;
        if (!zw_doc_seal_get($id)) {
            return false;
        }
        update_option('zw_doc_default_seal_id', $id, false);

        return true;
    }
}

if (!function_exists('zw_doc_seal_default_id')) {
    /**
     * 默认印章 ID（未设置时取登记表内第一个启用的）。
     *
     * @return string
     */
    function zw_doc_seal_default_id() {
        $default = (string) get_option('zw_doc_default_seal_id', '');
        if ($default !== '' && zw_doc_seal_get($default)) {
            return $default;
        }
        // TASK-1995：**默认章优先「总会」章** —— 分支机构章也启用时，未指定机关的文书不应
        // 因登记表顺序随机落到某个分支机构章上（生产实测：曾返回分支机构的 `cfc501…`）。
        foreach (zw_doc_seal_registry() as $id => $meta) {
            if (!empty($meta['enabled']) && strpos((string) (isset($meta['name']) ? $meta['name'] : ''), '总会') !== false) {
                return (string) $id;
            }
        }
        foreach (zw_doc_seal_registry() as $id => $meta) {
            if (!empty($meta['enabled'])) {
                return (string) $id;
            }
        }

        return '';
    }
}

if (!function_exists('zw_doc_seal_name_norm')) {
    /**
     * 印章名/机构名归一（用于**按发文机关匹配印章**）。
     *
     * 去掉「文件 / 印章 / 公章 / 大印」等后缀与所有空白、全角括号内的注解，
     * 使「中国传统文化促进会非物质文化遗产工作委员会」与「非物质文化遗产工作委员会印章」可比。
     *
     * @param string $name 原始名称。
     * @return string
     */
    function zw_doc_seal_name_norm($name) {
        $name = (string) $name;
        if ($name === '') {
            return '';
        }
        $name = preg_replace('/[（(][^）)]*[）)]/u', '', $name);
        $name = preg_replace('/\s+/u', '', (string) $name);
        $name = preg_replace('/(文件|印章|公章|大印|印鉴)$/u', '', (string) $name);

        return trim((string) $name);
    }
}

if (!function_exists('zw_doc_seal_match_id')) {
    /**
     * **按发文机关匹配印章**（TASK-1995；用户 2026-10-24 问「分支机构是否也会匹配印章」）。
     *
     * 规则（保守、只做「明确同名」的匹配，不做模糊推断）：
     * 1. 候选名 = 发文部门名 + 「中国传统文化促进会{部门名}」+ 发文机关（org_name 去掉「文件」后缀）；
     * 2. 与登记表内**启用**印章的 `name` 归一后**相等** ⇒ 命中（优先级按候选顺序）；
     * 3. 都不等时，若印章名归一后**以部门名结尾**且部门名长度 ≥ 4 ⇒ 命中（覆盖「…工作委员会」写法差异）；
     * 4. 无命中返回空串 —— 调用方回退 `zw_doc_seal_default_id()`（既有口径，零回归）。
     *
     * @param array|string $doc 发文数组（取 `department` / `org_name` / `branch_name`）或部门名。
     * @param string       $org 可选，发文机关名。
     * @return string 印章 ID，未命中返回 ''。
     */
    function zw_doc_seal_match_id($doc, $org = '') {
        $department = '';
        $org_name   = (string) $org;
        if (is_array($doc)) {
            $department = isset($doc['department']) ? (string) $doc['department'] : '';
            if ($org_name === '') {
                $org_name = isset($doc['org_name']) ? (string) $doc['org_name'] : '';
            }
            if ($org_name === '' && !empty($doc['branch_name'])) {
                $org_name = (string) $doc['branch_name'];
            }
        } else {
            $department = (string) $doc;
        }

        $cands = array();
        foreach (array($department, $org_name) as $raw) {
            $norm = zw_doc_seal_name_norm($raw);
            if ($norm === '') {
                continue;
            }
            $cands[] = $norm;
            // 分支机构常写「中国传统文化促进会{部门}」⇒ 反推出部门名做候选。
            $prefix = zw_doc_seal_name_norm('中国传统文化促进会');
            if ($prefix !== '' && strpos($norm, $prefix) === 0) {
                $rest = (string) substr($norm, strlen($prefix));
                if ($rest !== '') {
                    $cands[] = $rest;
                }
            }
        }
        $cands = array_values(array_unique($cands));
        if (empty($cands)) {
            return '';
        }

        $enabled = array();
        foreach (zw_doc_seal_registry() as $id => $meta) {
            if (empty($meta['enabled'])) {
                continue;
            }
            $enabled[(string) $id] = zw_doc_seal_name_norm(isset($meta['name']) ? $meta['name'] : '');
        }
        if (empty($enabled)) {
            return '';
        }

        // ① 归一后相等（按候选顺序）
        foreach ($cands as $cand) {
            foreach ($enabled as $id => $seal_name) {
                if ($seal_name !== '' && $seal_name === $cand) {
                    return $id;
                }
            }
        }
        // ② **总会本级**（发文机关就是「中国传统文化促进会」）⇒ 优先名字含「总会」的章
        //    （登记名如「总会印章2025」）；没有含「总会」的章再走后续规则。
        $office_norm = zw_doc_seal_name_norm('中国传统文化促进会');
        if ($office_norm !== '' && in_array($office_norm, $cands, true)) {
            foreach ($enabled as $id => $seal_name) {
                if ($seal_name !== '' && strpos($seal_name, '总会') !== false) {
                    return $id;
                }
            }
        }

        // ③ 印章名以候选名结尾（部门名 ≥ 4 字）
        foreach ($cands as $cand) {
            if (mb_strlen($cand, 'UTF-8') < 4) {
                continue;
            }
            $len = mb_strlen($cand, 'UTF-8');
            foreach ($enabled as $id => $seal_name) {
                if ($seal_name === '' || mb_strlen($seal_name, 'UTF-8') < $len) {
                    continue;
                }
                if (mb_substr($seal_name, -$len, null, 'UTF-8') === $cand) {
                    return $id;
                }
            }
        }

        return '';
    }
}

if (!function_exists('zw_doc_seal_gate')) {
    /**
     * 用印门禁 G1~G7（服务端强制，纯函数：只吃数组，便于单测）。
     *
     * | 门禁 | 含义 |
     * | --- | --- |
     * | G1 | 发文已审核通过（`_zw_doc_status = approved`） |
     * | G2/G3 | 已取得法务审批结论「pass」—— 对应法务平台执行规则「无审批不得用印」 |
     * | G4 | 当前账号具备用印能力 |
     * | G5 | 指定印章存在且启用 |
     * | G6 | 已完成二次确认 |
     * | G7 | 版式自检通过（标题与正文非空） |
     *
     * @param array $doc 发文记录。
     * @param array $ctx 上下文：legal_status / can_seal / seal_id / seal_enabled / confirmed。
     * @return array 问题列表；空数组表示可放行。每项 array{code,message}。
     */
    function zw_doc_seal_gate($doc, $ctx = array()) {
        $problems = array();
        $ctx      = is_array($ctx) ? $ctx : array();
        $status   = isset($doc['status']) ? (string) $doc['status'] : '';

        if ($status !== 'approved') {
            $problems[] = array(
                'code'    => 'g1_status',
                'message' => '发文尚未审核通过（当前状态：' . ($status !== '' ? $status : '未知') . '），不得用印。',
            );
        }

        if (zw_doc_seal_require_legal()) {
            $legal = isset($ctx['legal_status']) ? (string) $ctx['legal_status'] : '';
            if ($legal !== 'pass') {
                $problems[] = array(
                    'code'    => 'g2_legal',
                    'message' => '尚未取得法务审批结论（执行规则「无审批不得用印」）。'
                               . ($legal === '' ? '法务结论接入见 TASK-771，在此之前用印保持阻断。' : '当前结论：' . $legal . '。'),
                );
            }
        }

        if (empty($ctx['can_seal'])) {
            $problems[] = array(
                'code'    => 'g4_capability',
                'message' => '当前账号没有用印权限。',
            );
        }

        $seal_id = isset($ctx['seal_id']) ? (string) $ctx['seal_id'] : '';
        if ($seal_id === '') {
            $problems[] = array('code' => 'g5_seal_missing', 'message' => '未指定印章。');
        } elseif (empty($ctx['seal_enabled'])) {
            $problems[] = array('code' => 'g5_seal_disabled', 'message' => '印章不存在或已停用。');
        }

        if (empty($ctx['confirmed'])) {
            $problems[] = array(
                'code'    => 'g6_confirm',
                'message' => '用印是不可逆操作，需要二次确认。',
            );
        }

        $has_body  = !empty($doc['body']) && function_exists('zw_doc_body_is_empty') && !zw_doc_body_is_empty($doc['body']);
        $has_title = !empty($doc['body']['meta']['title']);
        if (!$has_body || !$has_title) {
            $problems[] = array(
                'code'    => 'g7_layout',
                'message' => '版式自检未通过：标题或正文为空。',
            );
        }

        return $problems;
    }
}

if (!function_exists('zw_doc_seal_pdf_sha12')) {
    /**
     * 当前完成文件（已用印加密 PDF）的 SHA-256 前 12 位（用于把「哪一版被盖过章」钉死）。
     *
     * TASK-1382 ④：解锁 / 再次用印的审计说明必须带该指纹；拿不到时返回空串（不臆造）。
     *
     * @param int $doc_id 发文 ID。
     * @return string
     */
    function zw_doc_seal_pdf_sha12($doc_id) {
        if (!function_exists('zw_doc_pdf_status')) {
            return '';
        }
        $status = zw_doc_pdf_status((int) $doc_id);
        $sha    = isset($status['sha256']) ? (string) $status['sha256'] : '';

        return $sha !== '' ? substr($sha, 0, 12) : '';
    }
}

if (!function_exists('zw_doc_seal_history')) {
    /**
     * 用印历史（`_zw_doc_seal_history`）：**只增不减**，用于「保留已用印记录」（TASK-1382 ④）。
     *
     * 容错：非法 JSON / 非数组一律视为空数组；本函数**只读**，任何路径都不得删除该 meta。
     *
     * @param int $doc_id 发文 ID。
     * @return array<int,array<string,mixed>>
     */
    function zw_doc_seal_history($doc_id) {
        $raw = get_post_meta((int) $doc_id, '_zw_doc_seal_history', true);
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : array();
    }
}

if (!function_exists('zw_doc_seal_history_append')) {
    /**
     * 追加一条用印历史（只增不减）。
     *
     * @param int    $doc_id  发文 ID。
     * @param string $seal_id 印章 ID。
     * @param int    $actor   操作人。
     * @param string $at      用印时间（空则取 `_zw_doc_seal_applied_at`）。
     * @param string $note    说明（例：再次用印 / 撤销用印）。
     * @param string $sha12   产物 SHA-256 前 12 位（可为空）。
     * @return void
     */
    function zw_doc_seal_history_append($doc_id, $seal_id, $actor, $at = '', $note = '', $sha12 = '') {
        $doc_id = (int) $doc_id;
        $history = zw_doc_seal_history($doc_id);
        $history[] = array(
            'seal_id'    => (string) $seal_id,
            'applied_at' => (string) ($at !== '' ? $at : get_post_meta($doc_id, '_zw_doc_seal_applied_at', true)),
            'applied_by' => (int) $actor,
            'pdf_sha12'  => (string) $sha12,
            'note'       => (string) $note,
            'recorded_at'=> function_exists('current_time') ? current_time('mysql') : '',
        );
        // 编码口径：WP 环境用 `wp_json_encode`；单测/CLI 桩环境回退 `json_encode`（两者对本结构等价）。
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($history, JSON_UNESCAPED_UNICODE)
            : json_encode($history, JSON_UNESCAPED_UNICODE);
        update_post_meta($doc_id, '_zw_doc_seal_history', (string) $encoded);
    }
}

if (!function_exists('zw_doc_seal_edit_unlocked')) {
    /**
     * 该发文当前是否处于「同版本解锁编辑」态（TASK-1382 ④）。
     *
     * @param int $doc_id 发文 ID。
     * @return bool
     */
    function zw_doc_seal_edit_unlocked($doc_id) {
        return (int) get_post_meta((int) $doc_id, '_zw_doc_edit_unlocked', true) === 1;
    }
}

if (!function_exists('zw_doc_seal_edit_unlock')) {
    /**
     * TASK-1382 ④ 同一版本内解锁编辑：保留已用印记录与完成文件，只解锁**正文 + 要素**。
     *
     * 用户口径：「可以回退重新编辑内容 即同一版本内解锁编辑（保留已用印记录、只解锁正文+要素）」。
     *
     * 与「撤销用印」的区别：
     * - 本函数**不删** `_zw_doc_seal_id/_mode/_applied_at/_applied_by`，`seal_mode` 保持 `formal`
     *   ⇒ 完成文件（加密 PDF）与用印追溯保留；
     * - 只把 `_zw_doc_locked` 置 0（复用既有出口 `zw_doc_set_locked()`），使正文与要素可编辑；
     * - 再次用印时把本次用印记录 append 进 `_zw_doc_seal_history`（只增不减），不丢追溯。
     *
     * 回退：未调用本函数时行为与现状逐字等价（老数据无新 meta）。
     *
     * @param int    $doc_id 发文 ID。
     * @param int    $actor  操作人（0 = 当前用户）。
     * @param string $note   说明（空则用默认文案）。
     * @return true|WP_Error
     */
    function zw_doc_seal_edit_unlock($doc_id, $actor = 0, $note = '') {
        $doc_id = (int) $doc_id;
        $doc    = function_exists('zw_doc_get_document') ? zw_doc_get_document($doc_id) : null;
        if (!$doc) {
            return new WP_Error('zw_doc_seal_no_doc', '发文不存在。');
        }
        if ((string) $doc['seal_mode'] !== 'formal') {
            return new WP_Error('zw_doc_seal_not_sealed', '该发文未用印，无需解锁编辑。');
        }
        $actor = $actor ? (int) $actor : get_current_user_id();
        if (!zw_doc_seal_can_use($actor)) {
            return new WP_Error('zw_doc_seal_capability', '当前账号没有解锁编辑权限（需管理员或用印权限）。');
        }
        if (zw_doc_seal_edit_unlocked($doc_id) && empty($doc['locked'])) {
            return new WP_Error('zw_doc_seal_already_unlocked', '该发文已处于同版本解锁编辑状态。');
        }

        update_post_meta($doc_id, '_zw_doc_edit_unlocked', 1);
        update_post_meta($doc_id, '_zw_doc_edit_unlocked_at', current_time('mysql'));
        update_post_meta($doc_id, '_zw_doc_edit_unlocked_by', $actor);

        $seal_id = (string) get_post_meta($doc_id, '_zw_doc_seal_id', true);
        $sha12   = zw_doc_seal_pdf_sha12($doc_id);
        $text    = $note !== '' ? $note : ('同版本解锁编辑（保留已用印记录 印章 #' . $seal_id
            . ($sha12 !== '' ? '，已用印产物 SHA-256 ' . $sha12 . '…' : '')
            . '；正文与要素解锁，再次用印前请勿触发任命同步 / 证书生成）');

        if (function_exists('zw_doc_log_event')) {
            zw_doc_log_event($doc_id, 'seal_edit_unlocked', $actor, (string) $doc['status'], (string) $doc['status'], $text);
        }
        if (function_exists('zw_doc_set_locked')) {
            zw_doc_set_locked($doc_id, false, $actor, '同版本解锁编辑：正文与要素解锁（用印记录保留）');
        }

        return true;
    }
}

if (!function_exists('zw_doc_seal_apply')) {
    /**
     * 正式用印：门禁 → 写 meta → 锁定正文 → 审计。
     *
     * @param int    $doc_id    发文 ID。
     * @param string $seal_id   印章 ID（空则取默认印章）。
     * @param int    $actor     操作人。
     * @param bool   $confirmed 是否已二次确认。
     * @return true|WP_Error
     */
    function zw_doc_seal_apply($doc_id, $seal_id = '', $actor = 0, $confirmed = false) {
        $doc_id = (int) $doc_id;
        $doc    = function_exists('zw_doc_get_document') ? zw_doc_get_document($doc_id) : null;
        if (!$doc) {
            return new WP_Error('zw_doc_seal_no_doc', '发文不存在。');
        }
        if (!empty($doc['locked'])) {
            return new WP_Error('zw_doc_seal_locked', '该发文已用印锁定。');
        }

        $seal_id = (string) $seal_id;
        if ($seal_id === '') {
            $seal_id = zw_doc_seal_default_id();
        }
        $seal = zw_doc_seal_get($seal_id);

        $problems = zw_doc_seal_gate($doc, array(
            'legal_status' => (string) get_post_meta($doc_id, '_zw_doc_legal_status', true),
            'can_seal'     => zw_doc_seal_can_use(),
            'seal_id'      => $seal_id,
            'seal_enabled' => ($seal && !empty($seal['enabled'])),
            'confirmed'    => (bool) $confirmed,
        ));
        if (!empty($problems)) {
            $first = $problems[0];
            return new WP_Error('zw_doc_seal_gate_' . $first['code'], $first['message']);
        }

        $actor = $actor ? (int) $actor : get_current_user_id();

        // TASK-1382 ④：再次用印前，把**上一次**用印记录 append 进 `_zw_doc_seal_history`（只增不减），
        // 避免 `_zw_doc_seal_applied_at/by` 被直接覆盖而丢失首次用印的追溯；同时把**已用印产物指纹**
        // （SHA-256 前 12 位）钉进历史 —— 这是「哪一版被盖过章」的唯一证据。
        $prev_at  = (string) get_post_meta($doc_id, '_zw_doc_seal_applied_at', true);
        $prev_id  = (string) get_post_meta($doc_id, '_zw_doc_seal_id', true);
        $prev_by  = (int) get_post_meta($doc_id, '_zw_doc_seal_applied_by', true);
        $prev_sha = zw_doc_seal_pdf_sha12($doc_id);
        if ($prev_at !== '' || $prev_id !== '') {
            zw_doc_seal_history_append(
                $doc_id,
                $prev_id,
                $prev_by,
                $prev_at,
                $prev_at !== '' ? '再次用印前的上一版用印记录' : '早期用印记录（无时间）',
                $prev_sha
            );
        }

        update_post_meta($doc_id, '_zw_doc_seal_id', $seal_id);
        update_post_meta($doc_id, '_zw_doc_seal_mode', 'formal');
        update_post_meta($doc_id, '_zw_doc_seal_applied_at', current_time('mysql'));
        update_post_meta($doc_id, '_zw_doc_seal_applied_by', $actor);
        // 再次用印即回到「已锁定」态：清除同版本解锁编辑标记（历史记录不受影响）。
        delete_post_meta($doc_id, '_zw_doc_edit_unlocked');
        delete_post_meta($doc_id, '_zw_doc_edit_unlocked_at');
        delete_post_meta($doc_id, '_zw_doc_edit_unlocked_by');

        if (function_exists('zw_doc_log_event')) {
            zw_doc_log_event(
                $doc_id,
                'seal_applied',
                $actor,
                (string) $doc['status'],
                (string) $doc['status'],
                '用印（印章 #' . $seal_id . '，有效 ' . (int) zw_doc_seal_effective_dpi($seal_id) . 'dpi）'
            );
        }

        if (function_exists('zw_doc_set_locked')) {
            zw_doc_set_locked($doc_id, true, $actor, '用印后锁定正文');
        }

        // TASK-1291：正式用印广播钩子（`zw_doc_appoint_sync_trigger=sealed` 时由监听侧消费）。
        do_action('zw_doc_sealed', $doc_id, $seal_id, $actor);

        return true;
    }
}

if (!function_exists('zw_doc_seal_revoke')) {
    /**
     * 撤销用印：清用印 meta → 解锁正文 → 审计。
     *
     * @param int    $doc_id 发文 ID。
     * @param int    $actor  操作人。
     * @param string $note   说明。
     * @return true|WP_Error
     */
    function zw_doc_seal_revoke($doc_id, $actor = 0, $note = '') {
        $doc_id = (int) $doc_id;
        $doc    = function_exists('zw_doc_get_document') ? zw_doc_get_document($doc_id) : null;
        if (!$doc) {
            return new WP_Error('zw_doc_seal_no_doc', '发文不存在。');
        }
        if (empty($doc['locked']) && (string) $doc['seal_mode'] === 'none') {
            return new WP_Error('zw_doc_seal_not_sealed', '该发文未用印。');
        }

        $actor = $actor ? (int) $actor : get_current_user_id();

        // TASK-1382 ④：撤销用印会**删除** `applied_at/by`，此前该次用印的追溯随之消失。
        // 按「保留已用印记录」口径，先 append 进 `_zw_doc_seal_history`（只增不减）再清 meta。
        $prev_at  = (string) get_post_meta($doc_id, '_zw_doc_seal_applied_at', true);
        $prev_id  = (string) get_post_meta($doc_id, '_zw_doc_seal_id', true);
        $prev_by  = (int) get_post_meta($doc_id, '_zw_doc_seal_applied_by', true);
        if ($prev_at !== '' || $prev_id !== '') {
            zw_doc_seal_history_append($doc_id, $prev_id, $prev_by, $prev_at, '撤销用印', zw_doc_seal_pdf_sha12($doc_id));
        }

        update_post_meta($doc_id, '_zw_doc_seal_mode', 'none');
        delete_post_meta($doc_id, '_zw_doc_seal_applied_at');
        delete_post_meta($doc_id, '_zw_doc_seal_applied_by');
        // 撤销用印回到未用印态，同版本解锁编辑标记一并清除。
        delete_post_meta($doc_id, '_zw_doc_edit_unlocked');
        delete_post_meta($doc_id, '_zw_doc_edit_unlocked_at');
        delete_post_meta($doc_id, '_zw_doc_edit_unlocked_by');

        if (function_exists('zw_doc_log_event')) {
            zw_doc_log_event($doc_id, 'seal_revoked', $actor, (string) $doc['status'], (string) $doc['status'], $note !== '' ? $note : '撤销用印（需重新审核后方可再次用印）');
        }
        if (function_exists('zw_doc_set_locked')) {
            zw_doc_set_locked($doc_id, false, $actor, '撤销用印并解锁正文');
        }

        return true;
    }
}

if (!function_exists('zw_doc_seal_data_uri')) {
    /**
     * 印章图片的 data-URI（供打印 HTML 与 PDF 工人**内联**使用）。
     *
     * 工人（TASK-770）无会话 cookie，不能依赖鉴权 URL，因此必须内联。
     *
     * @param string $seal_id 印章 ID。
     * @return string 失败返回空串。
     */
    function zw_doc_seal_data_uri($seal_id) {
        $path = zw_doc_seal_path($seal_id);
        if ($path === '') {
            return '';
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($bytes);
    }
}

if (!function_exists('zw_doc_seal_view_url')) {
    /**
     * 印章缩略图查看 URL（带 nonce；后台预览用）。
     *
     * @param string $seal_id 印章 ID。
     * @return string
     */
    function zw_doc_seal_view_url($seal_id) {
        $url = add_query_arg(array(
            'action'  => 'zw_doc_seal_view',
            'seal_id' => (string) $seal_id,
        ), admin_url('admin-post.php'));

        return wp_nonce_url($url, 'zw_doc_seal_view_' . (string) $seal_id);
    }
}

if (!function_exists('zw_doc_handle_seal_view')) {
    /**
     * 印章素材鉴权输出端点（admin-post）。
     *
     * @return void
     */
    function zw_doc_handle_seal_view() {
        $seal_id = isset($_GET['seal_id']) ? sanitize_text_field(wp_unslash($_GET['seal_id'])) : '';

        if (!function_exists('zw_doc_is_enabled') || !zw_doc_is_enabled()) {
            wp_die('发文管理未启用。', '不可用', array('response' => 403));
        }
        if (!zw_doc_seal_can_use()) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        check_admin_referer('zw_doc_seal_view_' . $seal_id);

        $path = zw_doc_seal_path($seal_id);
        if ($path === '') {
            wp_die('印章不存在。', '未找到', array('response' => 404));
        }

        nocache_headers();
        header('Content-Type: image/png');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}

if (!function_exists('zw_doc_assets_admin_url')) {
    /**
     * 「红头与印章」页 URL。
     *
     * @param array $args 查询参数。
     * @return string
     */
    function zw_doc_assets_admin_url($args = array()) {
        return add_query_arg(array_merge(array('page' => 'zw-doc-assets'), (array) $args), admin_url('admin.php'));
    }
}

if (!function_exists('zw_doc_assets_handle_post')) {
    /**
     * 「红头与印章」页 POST 处理（在 admin_init 阶段执行）。
     *
     * @return void
     */
    function zw_doc_assets_handle_post() {
        // TASK-1340（用户报告"上传印章不成功"）：**静默失败**修复。
        // 当请求体超过服务器 `post_max_size` 时，PHP 会**丢弃整个 POST**（`$_POST` 与 `$_FILES` 同时为空），
        // 原实现随即 `return` ⇒ 页面刷新后**没有任何提示**，用户只能看到"没反应/没预览"。
        // 这里显式识别该情形（仅限本页请求，避免影响其它后台 POST）并给出可操作提示。
        if (empty($_POST) && empty($_FILES)
            && isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) === 'POST'
            && !empty($_SERVER['CONTENT_LENGTH'])
            && isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], 'page=zw-doc-assets') !== false) {
            $limit = (string) ini_get('post_max_size');
            wp_safe_redirect(add_query_arg(
                array('zw_doc_msg' => '上传体积超过服务器上限（post_max_size=' . ($limit !== '' ? $limit : '未知') . '），请压缩图片后重试。'),
                zw_doc_assets_admin_url()
            ));
            exit;
        }
        $action = isset($_POST['zw_doc_assets_action']) ? sanitize_key(wp_unslash($_POST['zw_doc_assets_action'])) : '';
        if ($action === '') {
            return;
        }
        check_admin_referer('zw_doc_assets');

        $notice = '';
        $back   = zw_doc_assets_admin_url();

        if ($action === 'seal_upload') {
            if (empty($_FILES['zw_doc_seal_file'])) {
                $notice = '请选择印章文件。';
            } else {
                $name  = isset($_POST['zw_doc_seal_name']) ? sanitize_text_field(wp_unslash($_POST['zw_doc_seal_name'])) : '';
                // TASK-1985：真实章径随上传登记（单位 mm；填 4 / 4.0 按 cm 容错为 40mm）。
                $diameter = isset($_POST['zw_doc_seal_diameter']) ? sanitize_text_field(wp_unslash($_POST['zw_doc_seal_diameter'])) : '';
                $stored = zw_doc_seal_store_upload($_FILES['zw_doc_seal_file'], $name, get_current_user_id(), $diameter);
                if (is_wp_error($stored)) {
                    $notice = $stored->get_error_message();
                } else {
                    $diameter_note = isset($stored['diameter_mm'])
                        ? '，真实直径 ' . (float) $stored['diameter_mm'] . 'mm'
                        : '；⚠️ 未登记真实直径（暂按兼容值 ' . (float) zw_doc_gb9704_get('seal.diameter_mm', 42.0) . 'mm 排版，请在列表登记）';
                    $notice = '印章已上传（' . (int) $stored['w'] . '×' . (int) $stored['h'] . 'px，有效 ' . (int) $stored['effective_dpi'] . 'dpi'
                            . $diameter_note
                            . ($stored['transparent'] === false ? '；⚠️ 白底不透明，压章会遮挡落款文字' : '')
                            . ($stored['transparent'] === null ? '；无法判定透明底（服务器缺少 GD PNG 支持）' : '') . '）。';
                }
            }
        } elseif ($action === 'seal_diameter') {
            // TASK-1985：登记/修改真实章径（只改登记表，不动图片、不动已用印发文）。
            $seal_id  = isset($_POST['seal_id']) ? sanitize_text_field(wp_unslash($_POST['seal_id'])) : '';
            $diameter = isset($_POST['seal_diameter_mm']) ? sanitize_text_field(wp_unslash($_POST['seal_diameter_mm'])) : '';
            if (zw_doc_seal_set_diameter($seal_id, $diameter)) {
                $notice = '已登记真实直径 ' . (float) zw_doc_seal_diameter_mm($seal_id) . 'mm（此后生成的用印件按此章径排版）。';
            } else {
                $notice = '直径无效：请输入 ' . (float) zw_doc_gb9704_get('seal.diameter_min_mm', 30.0) . '~'
                        . (float) zw_doc_gb9704_get('seal.diameter_max_mm', 45.0) . 'mm（国发〔1999〕25号第十四条：直径不得大于 4.5 厘米）。';
            }
        } elseif ($action === 'seal_default') {
            $seal_id = isset($_POST['seal_id']) ? sanitize_text_field(wp_unslash($_POST['seal_id'])) : '';
            $notice  = zw_doc_seal_set_default($seal_id) ? '已设为默认印章。' : '印章不存在。';
        } elseif ($action === 'seal_toggle') {
            $seal_id = isset($_POST['seal_id']) ? sanitize_text_field(wp_unslash($_POST['seal_id'])) : '';
            $enable  = !empty($_POST['seal_enable']);
            $notice  = zw_doc_seal_set_enabled($seal_id, $enable) ? ($enable ? '印章已启用。' : '印章已停用。') : '印章不存在。';
        } elseif ($action === 'seal_delete') {
            $seal_id = isset($_POST['seal_id']) ? sanitize_text_field(wp_unslash($_POST['seal_id'])) : '';
            $notice  = zw_doc_seal_delete($seal_id) ? '印章已删除。' : '印章不存在。';
        } elseif ($action === 'seal_apply') {
            $doc_id  = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
            $seal_id = isset($_POST['seal_id']) ? sanitize_text_field(wp_unslash($_POST['seal_id'])) : '';
            $confirm = isset($_POST['seal_confirm']) && (string) wp_unslash($_POST['seal_confirm']) === 'SEAL';
            $result  = zw_doc_seal_apply($doc_id, $seal_id, get_current_user_id(), $confirm);
            $notice  = is_wp_error($result) ? $result->get_error_message() : '用印完成，正文已锁定。';
            $back    = zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id));
        } elseif ($action === 'seal_revoke') {
            $doc_id = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
            $result = zw_doc_seal_revoke($doc_id, get_current_user_id());
            $notice = is_wp_error($result) ? $result->get_error_message() : '已撤销用印并解锁正文（修改后须重新审核）。';
            $back   = zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id));
        } elseif ($action === 'seal_edit_unlock') {
            // TASK-1382 ④：同一版本内解锁编辑。nonce 已在上方 `check_admin_referer('zw_doc_assets')` 校验；
            // 权限判据在 `zw_doc_seal_edit_unlock()` 内与用印同口径（`zw_doc_seal_can_use()`）。
            $doc_id = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
            $result = zw_doc_seal_edit_unlock($doc_id, get_current_user_id());
            $notice = is_wp_error($result) ? $result->get_error_message() : '已同版本解锁编辑（用印记录与完成文件保留，再次用印前请勿触发任命同步 / 证书生成）。';
            $back   = zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id));
        } elseif (strpos($action, 'letterhead_') === 0 && function_exists('zw_doc_letterhead_handle_post')) {
            // nonce 已在上方校验；红头动作由红头模块处理（TASK-770）
            zw_doc_letterhead_handle_post($action);
            return;
        } else {
            $notice = '未知操作。';
        }

        wp_safe_redirect(add_query_arg(array('zw_doc_msg' => $notice), $back));
        exit;
    }
}

if (!function_exists('zw_doc_render_seal_admin_section')) {
    /**
     * 印章管理区块（供「红头与印章」页的印章 Tab 使用）。
     *
     * @return void
     */
    function zw_doc_render_seal_admin_section() {
        $list    = zw_doc_seal_registry();
        $default = zw_doc_seal_default_id();
        $notice  = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';
        ?>
        <?php if ($notice !== '') { zw_doc_admin_notice($notice, (strpos($notice, '⚠️') !== false || stripos($notice, '失败') !== false) ? 'warning' : 'success'); } ?>

        <div class="zw-doc-card">
            <h2>上传印章</h2>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('zw_doc_assets'); ?>
                <input type="hidden" name="zw_doc_assets_action" value="seal_upload">
                <p>
                    <label>印章名称 <input type="text" name="zw_doc_seal_name" maxlength="60" placeholder="如：总会公章 2025"></label>
                </p>
                <p>
                    <label>真实印章直径
                        <input type="text" name="zw_doc_seal_diameter" inputmode="decimal" size="6"
                               placeholder="<?php echo esc_attr((string) (float) zw_doc_gb9704_get('seal.diameter_mm', 42.0)); ?>">
                        mm
                    </label>
                    <span class="zw-doc-muted">
                        用尺量<strong>实物印章</strong>的圆直径后如实填写（本会公章为 <strong>40mm</strong>）。
                        可填 40 / 4（按厘米理解）；法规上限 45mm（国发〔1999〕25号第十四条）。
                        版面 1mm = 纸面 1mm，填错会直接改变印章的物理大小。
                    </span>
                </p>
                <p>
                    <input type="file" name="zw_doc_seal_file" accept="image/png" required>
                </p>
                <p class="zw-doc-muted">
                    仅接受 <strong>PNG 透明底</strong>；短边 ≥ <?php echo (int) zw_doc_gb9704_get('seal.min_source_px', 600); ?>px；
                    体积 ≤ <?php echo (int) round((int) zw_doc_gb9704_get('seal.max_bytes', 2097152) / 1048576); ?>MB；
                    不接受 SVG。素材存于 <strong>站点根目录之外</strong>，不注册为媒体附件。
                </p>
                <p><button class="button button-primary" type="submit">上传印章</button></p>
            </form>
        </div>

        <div class="zw-doc-card">
            <h2>印章列表（<?php echo count($list); ?>）</h2>
            <?php if (empty($list)) : ?>
                <p class="zw-doc-muted">尚未上传印章。</p>
            <?php else : ?>
                <!-- TASK-1633（TASK-1579 P1-6 第四批）：宽表落 canonical 横滑包裹。
                     规则由本模块唯一出处 zw_doc_admin_ui_style()（本页 :1090 输出）提供 ⇒ 本页不新增 <style>。 -->
                <div class="zw-adm-table-wrap">
                <table class="widefat striped">
                    <thead><tr><th>预览</th><th>名称</th><th>尺寸</th><th>真实直径</th><th>有效 dpi</th><th>透明底</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($list as $id => $meta) : ?>
                        <?php
                        $dpi      = (int) (isset($meta['effective_dpi']) ? $meta['effective_dpi'] : 0);
                        $low      = function_exists('zw_doc_gb9704_seal_is_low_dpi') && $dpi > 0 && $dpi < (int) zw_doc_gb9704_get('seal.low_dpi_threshold', 300);
                        $trans    = $meta['transparent'];
                        $is_def   = ((string) $default === (string) $id);
                        // TASK-1985：真实章径（未登记 ⇒ 标「未登记」并明示回退值，不冒充事实）。
                        $d_mm     = function_exists('zw_doc_seal_diameter_mm') ? (float) zw_doc_seal_diameter_mm($id) : (float) zw_doc_gb9704_get('seal.diameter_mm', 42.0);
                        $d_set    = isset($meta['diameter_mm']) && (float) $meta['diameter_mm'] > 0;
                        ?>
                        <tr>
                            <td><img src="<?php echo esc_url(zw_doc_seal_view_url($id)); ?>" alt="" style="width:56px;height:56px;object-fit:contain;background:#fff;border:1px solid #dcdcde"></td>
                            <td>
                                <?php echo esc_html((string) $meta['name']); ?>
                                <?php if ($is_def) : ?><span class="zw-doc-badge">默认</span><?php endif; ?>
                            </td>
                            <td><?php echo (int) $meta['w']; ?>×<?php echo (int) $meta['h']; ?>px</td>
                            <td>
                                <?php if ($d_set) : ?>
                                    <strong><?php echo (float) $d_mm; ?> mm</strong>
                                <?php else : ?>
                                    <span class="zw-doc-badge is-warn">未登记</span>
                                    <span class="zw-doc-muted">（排印回退 <?php echo (float) $d_mm; ?> mm）</span>
                                <?php endif; ?>
                                <form method="post" style="margin-top:4px">
                                    <?php wp_nonce_field('zw_doc_assets'); ?>
                                    <input type="hidden" name="zw_doc_assets_action" value="seal_diameter">
                                    <input type="hidden" name="seal_id" value="<?php echo esc_attr($id); ?>">
                                    <input type="text" name="seal_diameter_mm" inputmode="decimal" size="4"
                                           value="<?php echo esc_attr($d_set ? (string) (float) $d_mm : ''); ?>"
                                           placeholder="40">
                                    <button class="button button-small" type="submit">登记</button>
                                </form>
                            </td>
                            <td>
                                <?php echo $dpi; ?> dpi
                                <?php if ($low) : ?><span class="zw-doc-badge is-warn">低清素材</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($trans === true) : ?>
                                    是
                                <?php elseif ($trans === false) : ?>
                                    <span class="zw-doc-badge is-warn">白底不透明</span>
                                <?php else : ?>
                                    <span class="zw-doc-muted">无法判定</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo !empty($meta['enabled']) ? '启用' : '停用'; ?></td>
                            <td>
                                <?php if (!$is_def) : ?>
                                    <form method="post" style="display:inline">
                                        <?php wp_nonce_field('zw_doc_assets'); ?>
                                        <input type="hidden" name="zw_doc_assets_action" value="seal_default">
                                        <input type="hidden" name="seal_id" value="<?php echo esc_attr($id); ?>">
                                        <button class="button button-small" type="submit">设为默认</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field('zw_doc_assets'); ?>
                                    <input type="hidden" name="zw_doc_assets_action" value="seal_toggle">
                                    <input type="hidden" name="seal_id" value="<?php echo esc_attr($id); ?>">
                                    <input type="hidden" name="seal_enable" value="<?php echo !empty($meta['enabled']) ? '0' : '1'; ?>">
                                    <button class="button button-small" type="submit"><?php echo !empty($meta['enabled']) ? '停用' : '启用'; ?></button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('确认删除该印章？删除后历史发文仍保留用印记录。');">
                                    <?php wp_nonce_field('zw_doc_assets'); ?>
                                    <input type="hidden" name="zw_doc_assets_action" value="seal_delete">
                                    <input type="hidden" name="seal_id" value="<?php echo esc_attr($id); ?>">
                                    <button class="button button-small" type="submit">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('zw_doc_page_assets')) {
    /**
     * 「发文管理 → 红头与印章」页（印章 Tab；红头 Tab 由 TASK-770 补齐）。
     *
     * @return void
     */
    function zw_doc_page_assets() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'seal';
        ?>
        <div class="wrap zw-doc-assets">
            <?php echo zw_doc_admin_ui_style(); // 唯一出处：SPEC-10 §1.9 卡片范式（TASK-778） ?>
            <style>
            .zw-doc-assets .nav-tab-wrapper{margin-bottom:16px}
            </style>
            <h1>红头与印章</h1>
            <h2 class="nav-tab-wrapper">
                <a class="nav-tab <?php echo $tab === 'seal' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(zw_doc_assets_admin_url(array('tab' => 'seal'))); ?>">印章</a>
                <a class="nav-tab <?php echo $tab === 'letterhead' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(zw_doc_assets_admin_url(array('tab' => 'letterhead'))); ?>">红头模板</a>
            </h2>
            <?php if ($tab === 'letterhead') : ?>
                <?php if (function_exists('zw_doc_render_letterhead_admin_section')) { zw_doc_render_letterhead_admin_section(); } ?>
            <?php else : ?>
                <?php zw_doc_render_seal_admin_section(); ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
