<?php
/**
 * 公文红头模板库与「默认红头」—— TASK-770 / SPEC-87 §5、§15.3、§18。
 *
 * 存储口径（与印章刻意不同，理由写在此处与规范里）：
 * - 红头是**对外可见的机构标识**（收到公文的第三方本就看得见），不属于高敏资产，
 *   因此按站内既有先例（证书模块 `zw_activity_default_credential_bg`）使用**媒体库附件**；
 * - 印章则是可被直接冒用的凭证，维持 webroot 外私有存储（TASK-769）。两者差异是**有意的**。
 *
 * 解析优先级（唯一出处，渲染与导出都必须走本函数）：
 *   单篇覆盖（`_zw_doc_letterhead_id`） → 文种映射 → 全局默认 → 无（回退常量绘制）
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_LETTERHEAD_VERSION')) {
    define('ZW_DOC_LETTERHEAD_VERSION', '1');
}

if (!function_exists('zw_doc_letterhead_registry')) {
    /**
     * 红头模板登记表（option `zw_doc_letterheads`）。
     *
     * @return array id => meta
     */
    function zw_doc_letterhead_registry() {
        $list = get_option('zw_doc_letterheads', array());
        if (!is_array($list)) {
            $list = array();
        }
        $out = array();
        foreach ($list as $id => $meta) {
            if (!is_array($meta) || !isset($meta['attachment_id'])) {
                continue;
            }
            $out[(int) $id] = $meta;
        }

        return $out;
    }
}

if (!function_exists('zw_doc_letterhead_save_registry')) {
    /**
     * 保存登记表。
     *
     * @param array $list 登记表。
     * @return void
     */
    function zw_doc_letterhead_save_registry($list) {
        update_option('zw_doc_letterheads', is_array($list) ? $list : array(), false);
    }
}

if (!function_exists('zw_doc_letterhead_get')) {
    /**
     * 取单个模板元数据。
     *
     * @param int $id 模板 ID（= 附件 ID）。
     * @return array|null
     */
    function zw_doc_letterhead_get($id) {
        $list = zw_doc_letterhead_registry();
        $id   = (int) $id;

        return isset($list[$id]) ? $list[$id] : null;
    }
}

if (!function_exists('zw_doc_letterhead_url')) {
    /**
     * 红头图片 URL。
     *
     * @param int $attachment_id 附件 ID。
     * @return string
     */
    function zw_doc_letterhead_url($attachment_id) {
        if (!function_exists('wp_get_attachment_image_url')) {
            return '';
        }
        $url = wp_get_attachment_image_url((int) $attachment_id, 'full');

        return is_string($url) ? $url : '';
    }
}

if (!function_exists('zw_doc_letterhead_path')) {
    /**
     * 红头图片本地路径（DOCX 内联需要文件字节）。
     *
     * @param int $attachment_id 附件 ID。
     * @return string
     */
    function zw_doc_letterhead_path($attachment_id) {
        if (!function_exists('get_attached_file')) {
            return '';
        }
        $path = get_attached_file((int) $attachment_id);

        return (is_string($path) && is_file($path)) ? $path : '';
    }
}

if (!function_exists('zw_doc_letterhead_inspect')) {
    /**
     * 规格自检：宽度、格式、体积、有效 dpi。
     *
     * @param int $attachment_id 附件 ID。
     * @return array{ok:bool,w:int,h:int,bytes:int,dpi:int,reasons:string[]}
     */
    function zw_doc_letterhead_inspect($attachment_id) {
        $min_px    = (int) zw_doc_gb9704_get('header.letterhead_min_px', 1843);
        $max_bytes = (int) zw_doc_gb9704_get('header.letterhead_max_bytes', 3145728);
        $width_mm  = (float) zw_doc_gb9704_get('header.letterhead_width_mm', 156.0);

        $out = array('ok' => false, 'w' => 0, 'h' => 0, 'bytes' => 0, 'dpi' => 0, 'reasons' => array());
        $path = zw_doc_letterhead_path($attachment_id);
        if ($path === '') {
            $out['reasons'][] = '附件文件不存在。';

            return $out;
        }

        $size = @getimagesize($path);
        if (!is_array($size) || empty($size[0])) {
            $out['reasons'][] = '无法识别图片类型。';

            return $out;
        }
        $out['w']     = (int) $size[0];
        $out['h']     = (int) $size[1];
        $out['bytes'] = (int) @filesize($path);
        $out['dpi']   = zw_doc_gb9704_effective_dpi($out['w'], $width_mm);

        if (!in_array((int) $size[2], array(IMAGETYPE_PNG, IMAGETYPE_JPEG), true)) {
            $out['reasons'][] = '只接受 PNG / JPG。';
        }
        if ($out['bytes'] > $max_bytes) {
            $out['reasons'][] = '体积超过 ' . (int) round($max_bytes / 1048576) . 'MB。';
        }
        if ($out['w'] < $min_px) {
            $out['reasons'][] = '宽度不足 ' . $min_px . 'px（当前 ' . $out['w'] . 'px = ' . $out['dpi'] . 'dpi），打印会发虚。';
        }
        $out['ok'] = empty($out['reasons']);

        return $out;
    }
}

if (!function_exists('zw_doc_letterhead_register')) {
    /**
     * 登记一个红头模板（媒体库附件）。
     *
     * @param int    $attachment_id 附件 ID。
     * @param string $name          显示名。
     * @param int    $actor         操作人。
     * @param bool   $allow_low      规格不达标时是否仍强制登记。
     * @return array|WP_Error 成功返回元数据。
     */
    function zw_doc_letterhead_register($attachment_id, $name = '', $actor = 0, $allow_low = false) {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || !zw_doc_letterhead_path($attachment_id)) {
            return new WP_Error('zw_doc_lh_missing', '红头附件不存在。');
        }
        $inspect = zw_doc_letterhead_inspect($attachment_id);
        if (!$inspect['ok'] && !$allow_low) {
            return new WP_Error('zw_doc_lh_spec', '红头图片不符合规格：' . implode('；', $inspect['reasons']));
        }

        $meta = array(
            'attachment_id' => $attachment_id,
            'name'          => $name !== '' ? $name : ('红头 #' . $attachment_id),
            'w'             => (int) $inspect['w'],
            'h'             => (int) $inspect['h'],
            'bytes'         => (int) $inspect['bytes'],
            'dpi'           => (int) $inspect['dpi'],
            'enabled'       => 1,
            // 文号叠加坐标（相对红头图片的百分比；0 = 未配置 → 降级为「文号排图片下方」）
            'number_x'      => 50.0,
            'number_y'      => 0.0,
            'number_size'   => 0.0,
            'registered_by' => (int) ($actor ? $actor : get_current_user_id()),
            'registered_at' => current_time('mysql'),
        );

        $list                    = zw_doc_letterhead_registry();
        $list[$attachment_id]    = $meta;
        zw_doc_letterhead_save_registry($list);

        if ((int) get_option('zw_doc_default_letterhead_id', 0) <= 0) {
            update_option('zw_doc_default_letterhead_id', $attachment_id, false);
        }

        return $meta;
    }
}

if (!function_exists('zw_doc_letterhead_remove')) {
    /**
     * 移除登记（**不删除媒体库文件**）。
     *
     * @param int $id 模板 ID。
     * @return bool
     */
    function zw_doc_letterhead_remove($id) {
        $id   = (int) $id;
        $list = zw_doc_letterhead_registry();
        if (!isset($list[$id])) {
            return false;
        }
        unset($list[$id]);
        zw_doc_letterhead_save_registry($list);
        if ((int) get_option('zw_doc_default_letterhead_id', 0) === $id) {
            update_option('zw_doc_default_letterhead_id', 0, false);
        }
        $map = zw_doc_letterhead_map();
        foreach ($map as $type => $mapped) {
            if ((int) $mapped === $id) {
                unset($map[$type]);
            }
        }
        update_option('zw_doc_letterhead_map', $map, false);

        return true;
    }
}

if (!function_exists('zw_doc_letterhead_set_enabled')) {
    /**
     * 启用 / 停用模板。
     *
     * @param int  $id      模板 ID。
     * @param bool $enabled 是否启用。
     * @return bool
     */
    function zw_doc_letterhead_set_enabled($id, $enabled) {
        $id   = (int) $id;
        $list = zw_doc_letterhead_registry();
        if (!isset($list[$id])) {
            return false;
        }
        $list[$id]['enabled'] = $enabled ? 1 : 0;
        zw_doc_letterhead_save_registry($list);

        return true;
    }
}

if (!function_exists('zw_doc_letterhead_set_overlay')) {
    /**
     * 设置文号叠加坐标（相对红头图片的百分比）。
     *
     * GB/T 9704 要求发文字号位于**红色反线之上**；使用「含红色分隔线」的红头图片时，
     * 文号必须叠加在图片内的红线上方，否则会落到线下方而不符合国标顺序。
     * `y = 0` 表示未配置 → 渲染降级为「文号排图片下方」（带 `data-zw-note` 标记，不视为达标）。
     *
     * @param int   $id   模板 ID。
     * @param float $x    横向百分比（0~100）。
     * @param float $y    纵向百分比（0~100；0 = 未配置）。
     * @param float $size 字号（pt；0 = 用正文三号）。
     * @return bool
     */
    function zw_doc_letterhead_set_overlay($id, $x, $y, $size = 0.0) {
        $id   = (int) $id;
        $list = zw_doc_letterhead_registry();
        if (!isset($list[$id])) {
            return false;
        }
        $x = max(0.0, min(100.0, (float) $x));
        $y = max(0.0, min(100.0, (float) $y));
        $list[$id]['number_x']    = $x;
        $list[$id]['number_y']    = $y;
        $list[$id]['number_size'] = max(0.0, min(72.0, (float) $size));
        zw_doc_letterhead_save_registry($list);

        return true;
    }
}

if (!function_exists('zw_doc_letterhead_set_default')) {
    /**
     * 设为默认红头（**全局唯一**）。
     *
     * @param int $id 模板 ID。
     * @return bool
     */
    function zw_doc_letterhead_set_default($id) {
        $id   = (int) $id;
        $meta = zw_doc_letterhead_get($id);
        if (!$meta || empty($meta['enabled'])) {
            return false;
        }
        update_option('zw_doc_default_letterhead_id', $id, false);

        return true;
    }
}

if (!function_exists('zw_doc_letterhead_default_id')) {
    /**
     * 默认红头 ID（未设置或已停用时，取登记表内首个启用的）。
     *
     * @return int
     */
    function zw_doc_letterhead_default_id() {
        $default = (int) get_option('zw_doc_default_letterhead_id', 0);
        $meta    = $default > 0 ? zw_doc_letterhead_get($default) : null;
        if ($meta && !empty($meta['enabled'])) {
            return $default;
        }
        foreach (zw_doc_letterhead_registry() as $id => $item) {
            if (!empty($item['enabled'])) {
                return (int) $id;
            }
        }

        return 0;
    }
}

/* ══════════════════════════════════════════════════════════════════════════
 * 全局「红头生成方式」开关（TASK-1422；用户口径原文：
 *   「版式预览 默认使用 系统自动生成的红头 如果 红头文件 增加个开关」+
 *    二选一答复「A + 全局默认」）
 *
 * 取值语义（**全仓唯一出处**）：
 *   - `auto`（**默认**，「系统自动生成红头」）：程序绘制「文字发文机关标志 + 红色分隔线」，
 *     **不使用**上传的红头图片（单篇覆盖 / 文种映射 / 全局默认一律不参与）；
 *   - `image`（「使用上传的红头文件（图片）」）：按既有解析优先级取已启用红头图片；
 *     **无可用素材 ⇒ fail-closed**（明确报错 + 引导），**不静默回落 auto、不渲染空白红头**。
 *
 * 铁律：
 *   - 取值只允许经 `zw_doc_letterhead_mode()`（**全仓只此一处读该 option**）；
 *   - 写入只允许经 `zw_doc_letterhead_mode_save()`（**全仓只此一处写该 option**）；
 *   - 版式预览与出件（HTML 打印 / DOCX / 加密 PDF）**都必须**经 `zw_doc_letterhead_resolve()`
 *     → `zw_doc_letterhead_render_args()` 同源派生，**禁止「预览一套、出件另一套」**；
 *   - 反向守卫断言见 `tests/test-doc-letterhead-mode.php`。
 * ══════════════════════════════════════════════════════════════════════════ */

if (!defined('ZW_DOC_LETTERHEAD_MODE_AUTO')) {
    /** 红头生成方式：系统自动生成红头（默认）。 */
    define('ZW_DOC_LETTERHEAD_MODE_AUTO', 'auto');
}

if (!defined('ZW_DOC_LETTERHEAD_MODE_IMAGE')) {
    /** 红头生成方式：使用上传的红头文件（图片）。 */
    define('ZW_DOC_LETTERHEAD_MODE_IMAGE', 'image');
}

if (!function_exists('zw_doc_letterhead_mode_normalize')) {
    /**
     * 生成方式取值归一（**唯一出处**）：只认 `image`，其余（含未设置 / 非法值 / 空串）一律回落 `auto`。
     *
     * @param mixed $value 原始取值。
     * @return string auto|image
     */
    function zw_doc_letterhead_mode_normalize($value) {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return $value === ZW_DOC_LETTERHEAD_MODE_IMAGE ? ZW_DOC_LETTERHEAD_MODE_IMAGE : ZW_DOC_LETTERHEAD_MODE_AUTO;
    }
}

if (!function_exists('zw_doc_letterhead_mode')) {
    /**
     * 全局红头生成方式的**唯一取值出处**（option `zw_doc_letterhead_mode`）。
     *
     * 未设置 ⇒ `auto`（**默认零回归**：与「无红头素材时程序自动生成」的既有行为一致）。
     * **全仓只允许本函数读取该 option**（反向守卫断言）。
     *
     * @return string auto|image
     */
    function zw_doc_letterhead_mode() {
        $raw = function_exists('get_option')
            ? get_option('zw_doc_letterhead_mode', ZW_DOC_LETTERHEAD_MODE_AUTO)
            : ZW_DOC_LETTERHEAD_MODE_AUTO;

        return zw_doc_letterhead_mode_normalize($raw);
    }
}

if (!function_exists('zw_doc_letterhead_mode_is_auto')) {
    /**
     * 当前生成方式是否为「系统自动生成红头」（默认）——**唯一判定入口**。
     *
     * 调用方（如详情页提示）**禁止**自行比较 `'auto'` / `'image'` 字面量（反向守卫断言），
     * 需要判定时一律走本函数或 `zw_doc_letterhead_mode_label()`。
     *
     * @return bool
     */
    function zw_doc_letterhead_mode_is_auto() {
        return zw_doc_letterhead_mode() === ZW_DOC_LETTERHEAD_MODE_AUTO;
    }
}

if (!function_exists('zw_doc_letterhead_mode_label')) {
    /**
     * 生成方式的人类可读标签（**唯一出处**：后台开关、只读展示、错误提示共用）。
     *
     * @param string $mode auto|image。
     * @return string
     */
    function zw_doc_letterhead_mode_label($mode) {
        return zw_doc_letterhead_mode_normalize($mode) === ZW_DOC_LETTERHEAD_MODE_IMAGE
            ? '使用上传的红头文件（图片）'
            : '系统自动生成红头';
    }
}

if (!function_exists('zw_doc_letterhead_mode_material_ready')) {
    /**
     * 「使用上传的红头文件（图片）」的**可用素材前置条件**：至少 1 个**已启用**红头模板。
     *
     * 素材可用性沿用既有唯一出处 `zw_doc_letterhead_default_id()`（未设置默认或默认已停用时取首个启用的）。
     *
     * @return bool
     */
    function zw_doc_letterhead_mode_material_ready() {
        return zw_doc_letterhead_default_id() > 0;
    }
}

if (!function_exists('zw_doc_letterhead_mode_state')) {
    /**
     * 全局开关的**唯一可用性判定**（预览与出件共用；禁止任何通道自行判定）。
     *
     * @return array{mode:string,ready:bool,blocked:bool,message:string,assets_url:string}
     */
    function zw_doc_letterhead_mode_state() {
        $mode    = zw_doc_letterhead_mode();
        $ready   = zw_doc_letterhead_mode_material_ready();
        $blocked = ($mode === ZW_DOC_LETTERHEAD_MODE_IMAGE && !$ready);

        $message = '';
        if ($blocked) {
            // fail-closed 文案（**唯一出处**）：明说「不静默回落、不渲染空白」，并给出可操作引导。
            $message = '当前红头生成方式为「' . zw_doc_letterhead_mode_label(ZW_DOC_LETTERHEAD_MODE_IMAGE)
                     . '」，但系统内没有「已启用」的红头文件，已按 fail-closed 停止生成红头：'
                     . '不会静默改回「' . zw_doc_letterhead_mode_label(ZW_DOC_LETTERHEAD_MODE_AUTO)
                     . '」，也不会渲染空白红头。请先到「红头与印章 → 红头模板」上传并启用红头文件，'
                     . '或把生成方式切回「' . zw_doc_letterhead_mode_label(ZW_DOC_LETTERHEAD_MODE_AUTO) . '」。';
        }

        return array(
            'mode'       => $mode,
            'ready'      => $ready,
            'blocked'    => $blocked,
            'message'    => $message,
            'assets_url' => function_exists('zw_doc_assets_admin_url') ? zw_doc_assets_admin_url(array('tab' => 'letterhead')) : '',
        );
    }
}

if (!function_exists('zw_doc_letterhead_mode_gate')) {
    /**
     * 出件通道的 fail-closed **门禁**（唯一出处）：不可用时返回 `WP_Error`，可用时返回 `true`。
     *
     * HTML 打印 / DOCX / 加密 PDF 三条通道与撰写页预览端点都必须先过本门禁。
     *
     * @return true|WP_Error|false
     */
    function zw_doc_letterhead_mode_gate() {
        $state = zw_doc_letterhead_mode_state();
        if (empty($state['blocked'])) {
            return true;
        }
        if (class_exists('WP_Error')) {
            return new WP_Error('zw_doc_lh_mode_no_material', (string) $state['message']);
        }

        return false;
    }
}

if (!function_exists('zw_doc_letterhead_mode_save')) {
    /**
     * 全局红头生成方式的**唯一写入口**（TASK-1422）。
     *
     * 全仓 `update_option('zw_doc_letterhead_mode', …)` **只允许在此出现一次**（反向守卫断言）。
     * 切到「使用上传的红头文件（图片）」而**没有可用已启用红头** ⇒ **fail-closed**
     * （返回 WP_Error，**不落库**、保持原值；避免「已切到图片模式却渲染不出红头」的静默失败）。
     *
     * @param string $mode    auto|image（非法值一律回落 auto）。
     * @param string $context 调用来源标识（仅审计/断言定位，不参与保存语义）。
     * @return string|WP_Error|false 落库后的规范取值。
     */
    function zw_doc_letterhead_mode_save($mode, $context = '') {
        $mode = zw_doc_letterhead_mode_normalize($mode);

        if ($mode === ZW_DOC_LETTERHEAD_MODE_IMAGE && !zw_doc_letterhead_mode_material_ready()) {
            $msg = '无法切换为「' . zw_doc_letterhead_mode_label(ZW_DOC_LETTERHEAD_MODE_IMAGE)
                 . '」：系统内没有「已启用」的红头文件。请先到「红头与印章 → 红头模板」上传并启用红头文件，再切换；'
                 . '当前生成方式保持为「' . zw_doc_letterhead_mode_label(zw_doc_letterhead_mode()) . '」。';

            return class_exists('WP_Error') ? new WP_Error('zw_doc_lh_mode_no_material', $msg) : false;
        }

        if (!function_exists('update_option')) {
            return class_exists('WP_Error') ? new WP_Error('zw_doc_lh_mode_missing_dependency', 'update_option 不可用。') : false;
        }

        update_option('zw_doc_letterhead_mode', $mode, false);

        return $mode;
    }
}

if (!function_exists('zw_doc_letterhead_map')) {
    /**
     * 文种 → 模板 ID 映射。
     *
     * @return array
     */
    function zw_doc_letterhead_map() {
        $map = get_option('zw_doc_letterhead_map', array());
        if (!is_array($map)) {
            return array();
        }
        $out = array();
        foreach ($map as $type => $id) {
            $type = sanitize_text_field((string) $type);
            if ($type !== '' && (int) $id > 0) {
                $out[$type] = (int) $id;
            }
        }

        return $out;
    }
}

if (!function_exists('zw_doc_letterhead_set_map')) {
    /**
     * 设置某文种的映射（传 0 表示清除该文种映射）。
     *
     * @param string $doc_type 文种。
     * @param int    $id       模板 ID。
     * @return bool
     */
    function zw_doc_letterhead_set_map($doc_type, $id) {
        $doc_type = sanitize_text_field((string) $doc_type);
        if ($doc_type === '') {
            return false;
        }
        $map = zw_doc_letterhead_map();
        if ((int) $id <= 0) {
            unset($map[$doc_type]);
        } else {
            if (!zw_doc_letterhead_get((int) $id)) {
                return false;
            }
            $map[$doc_type] = (int) $id;
        }
        update_option('zw_doc_letterhead_map', $map, false);

        return true;
    }
}

if (!function_exists('zw_doc_letterhead_doc_type')) {
    /**
     * 从发文字号解析文种（**复用既有解析器，不另造一套**）。
     *
     * @param array $doc 发文记录。
     * @return string 解析不出时返回空串。
     */
    function zw_doc_letterhead_doc_type($doc) {
        $number = isset($doc['number']) ? (string) $doc['number'] : '';
        if ($number === '') {
            return '';
        }
        if (!preg_match('/^(.+?)〔/u', $number, $m)) {
            return '';
        }
        if (!function_exists('zw_doc_import_parse_doc_type')) {
            return '';
        }
        $parsed = zw_doc_import_parse_doc_type($m[1]);
        if (!is_array($parsed) || empty($parsed['type'])) {
            return '';
        }
        $type = (string) $parsed['type'];
        // 与文号字典比对，避免把任意前缀当成文种
        if (function_exists('zw_doc_number_settings')) {
            $types = (array) zw_doc_number_settings()['doc_types'];
            if (!in_array($type, $types, true)) {
                return $type; // 历史导入可能注册过新文种，仍返回以供映射匹配
            }
        }

        return $type;
    }
}

if (!function_exists('zw_doc_letterhead_resolve')) {
    /**
     * 解析某篇发文应当使用的红头（**唯一出处**）。
     *
     * TASK-1422：先过**全局「红头生成方式」开关**（唯一出处 `zw_doc_letterhead_mode()`）——
     *   - `auto`（默认，系统自动生成红头）⇒ 直接返回 `source=none`（调用方走常量绘制，**不取图片**）；
     *   - `image`（使用上传的红头文件）⇒ 按下列优先级解析；**无可用已启用红头 ⇒ fail-closed**
     *     （`blocked=true` + `reason` 可操作提示，**不静默回落 auto、不渲染空白红头**）。
     *
     * 图片解析优先级：单篇覆盖 → 文种映射 → 全局默认 → 无（来源 `none`，调用方回退常量绘制）。
     *
     * @param array $doc 发文记录。
     * @return array{id:int,url:string,path:string,source:string,name:string,dpi:int,low:bool,ok:bool,overlay:array|null,mode:string,blocked:bool,reason:string,assets_url:string}
     */
    function zw_doc_letterhead_resolve($doc = array()) {
        $doc = is_array($doc) ? $doc : array();

        $out = array(
            'id'     => 0,
            'url'    => '',
            'path'   => '',
            'source' => 'none',
            'name'   => '',
            'dpi'    => 0,
            'low'     => false,
            'ok'      => false,
            'overlay' => null,
            // TASK-1422：生成方式（唯一出处 `zw_doc_letterhead_mode()` 经 `zw_doc_letterhead_mode_state()`）
            // 与 fail-closed 标记 —— 预览与出件三条通道**共用同一判定**，禁止各自判定。
            'mode'       => ZW_DOC_LETTERHEAD_MODE_AUTO,
            'blocked'    => false,
            'reason'     => '',
            'assets_url' => '',
        );

        $state        = zw_doc_letterhead_mode_state();
        $out['mode']  = (string) $state['mode'];

        if (!empty($state['blocked'])) {
            // fail-closed：切到「使用上传的红头文件（图片）」却没有可用已启用红头 ⇒ 明确报错。
            // **不静默回落 auto、不渲染空白红头**（调用方据 `blocked` 给出提示或拒绝出件）。
            $out['blocked']    = true;
            $out['reason']     = (string) $state['message'];
            $out['assets_url'] = (string) $state['assets_url'];

            return $out;
        }

        if ($state['mode'] === ZW_DOC_LETTERHEAD_MODE_AUTO) {
            // 「系统自动生成红头」（默认）：**不参与图片解析** —— 单篇覆盖 / 文种映射 / 全局默认一律不生效，
            // 调用方据此走**常量绘制**（文字发文机关标志 + 红色分隔线）。
            return $out;
        }

        // —— 以下仅「使用上传的红头文件（图片）」：既有解析优先级（单篇覆盖 → 文种映射 → 全局默认）不变 ——
        $candidates = array();
        if (!empty($doc['letterhead_id'])) {
            $candidates[] = array((int) $doc['letterhead_id'], 'document');
        }
        $doc_type = zw_doc_letterhead_doc_type($doc);
        $map      = zw_doc_letterhead_map();
        if ($doc_type !== '' && !empty($map[$doc_type])) {
            $candidates[] = array((int) $map[$doc_type], 'doc_type');
        }
        $default = zw_doc_letterhead_default_id();
        if ($default > 0) {
            $candidates[] = array($default, 'default');
        }

        foreach ($candidates as $candidate) {
            $id   = (int) $candidate[0];
            $meta = zw_doc_letterhead_get($id);
            if (!$meta || empty($meta['enabled'])) {
                continue;
            }
            $out['id']     = $id;
            $out['source'] = (string) $candidate[1];
            $out['name']   = (string) $meta['name'];
            $out['url']    = zw_doc_letterhead_url($id);
            $out['path']   = zw_doc_letterhead_path($id);
            $out['dpi']    = (int) (isset($meta['dpi']) ? $meta['dpi'] : 0);
            $out['low']    = ($out['dpi'] > 0 && $out['dpi'] < (int) zw_doc_gb9704_get('seal.low_dpi_threshold', 300));
            $out['ok']     = ($out['url'] !== '' || $out['path'] !== '');
            $overlay_y     = (float) (isset($meta['number_y']) ? $meta['number_y'] : 0);
            if ($overlay_y > 0) {
                $out['overlay'] = array(
                    'x'    => (float) (isset($meta['number_x']) ? $meta['number_x'] : 50.0),
                    'y'    => $overlay_y,
                    'size' => (float) (isset($meta['number_size']) ? $meta['number_size'] : 0.0),
                );
            }
            break;
        }

        return $out;
    }
}

if (!function_exists('zw_doc_letterhead_handle_post')) {
    /**
     * 红头相关 POST 处理（**假定调用方已校验 `zw_doc_assets` nonce**）。
     *
     * @param string $action 动作名。
     * @return void
     */
    function zw_doc_letterhead_handle_post($action) {
        $notice = '';
        $back   = zw_doc_assets_admin_url(array('tab' => 'letterhead'));

        if ($action === 'letterhead_upload' || $action === 'letterhead_attach') {
            $attachment_id = 0;
            if ($action === 'letterhead_upload' && !empty($_FILES['zw_doc_letterhead_file'])) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $attachment_id = media_handle_upload('zw_doc_letterhead_file', 0);
                if (is_wp_error($attachment_id)) {
                    $notice = '上传失败：' . $attachment_id->get_error_message();
                }
            } elseif ($action === 'letterhead_attach') {
                $attachment_id = isset($_POST['letterhead_attachment_id']) ? (int) $_POST['letterhead_attachment_id'] : 0;
            }

            if ($notice === '') {
                $name   = isset($_POST['zw_doc_letterhead_name']) ? sanitize_text_field(wp_unslash($_POST['zw_doc_letterhead_name'])) : '';
                $allow  = !empty($_POST['zw_doc_letterhead_allow_low']);
                $result = zw_doc_letterhead_register((int) $attachment_id, $name, get_current_user_id(), $allow);
                if (is_wp_error($result)) {
                    $notice = $result->get_error_message();
                } else {
                    $notice = '红头模板已登记（' . (int) $result['w'] . '×' . (int) $result['h'] . 'px，' . (int) $result['dpi'] . 'dpi）。';
                }
            }
        } elseif ($action === 'letterhead_default') {
            $id     = isset($_POST['letterhead_id']) ? (int) $_POST['letterhead_id'] : 0;
            $notice = zw_doc_letterhead_set_default($id) ? '已设为默认红头。' : '模板不存在或已停用。';
        } elseif ($action === 'letterhead_toggle') {
            $id     = isset($_POST['letterhead_id']) ? (int) $_POST['letterhead_id'] : 0;
            $enable = !empty($_POST['letterhead_enable']);
            $notice = zw_doc_letterhead_set_enabled($id, $enable) ? ($enable ? '模板已启用。' : '模板已停用。') : '模板不存在。';
        } elseif ($action === 'letterhead_remove') {
            $id     = isset($_POST['letterhead_id']) ? (int) $_POST['letterhead_id'] : 0;
            $notice = zw_doc_letterhead_remove($id) ? '已移除登记（媒体库文件保留）。' : '模板不存在。';
        } elseif ($action === 'letterhead_overlay') {
            $id     = isset($_POST['letterhead_id']) ? (int) $_POST['letterhead_id'] : 0;
            $x      = isset($_POST['number_x']) ? (float) $_POST['number_x'] : 50.0;
            $y      = isset($_POST['number_y']) ? (float) $_POST['number_y'] : 0.0;
            $size   = isset($_POST['number_size']) ? (float) $_POST['number_size'] : 0.0;
            $notice = zw_doc_letterhead_set_overlay($id, $x, $y, $size)
                ? ($y > 0 ? '已保存文号叠加坐标（符合「文号在红线之上」）。' : '已清空文号坐标，将降级为「文号排图片下方」。')
                : '模板不存在。';
        } elseif ($action === 'letterhead_map') {
            $type   = isset($_POST['doc_type']) ? sanitize_text_field(wp_unslash($_POST['doc_type'])) : '';
            $id     = isset($_POST['letterhead_id']) ? (int) $_POST['letterhead_id'] : 0;
            $notice = zw_doc_letterhead_set_map($type, $id) ? ('已更新「' . $type . '」的红头映射。') : '映射保存失败。';
        } elseif ($action === 'letterhead_mode') {
            // TASK-1422：「全局红头生成方式」开关（唯一写入口 `zw_doc_letterhead_mode_save()`；
            // nonce 已由调用方（doc-seal.php）校验 `zw_doc_assets`，权限沿用模块 `manage_options`）。
            $mode = isset($_POST['letterhead_mode']) ? sanitize_key(wp_unslash($_POST['letterhead_mode'])) : '';
            if (!in_array($mode, array(ZW_DOC_LETTERHEAD_MODE_AUTO, ZW_DOC_LETTERHEAD_MODE_IMAGE), true)) {
                // 白名单外取值一律拒绝（**不静默落库**），避免畸形 POST 把开关改成别的语义。
                $notice = '未知的红头生成方式取值，已保持当前设置（' . zw_doc_letterhead_mode_label(zw_doc_letterhead_mode()) . '）。';
            } else {
                $saved  = zw_doc_letterhead_mode_save($mode, 'assets_page');
                $notice = is_wp_error($saved)
                    ? $saved->get_error_message()
                    : ('红头生成方式已切换为「' . zw_doc_letterhead_mode_label($saved) . '」。');
            }
        } elseif ($action === 'letterhead_pick') {
            $doc_id = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
            $id     = isset($_POST['letterhead_id']) ? (int) $_POST['letterhead_id'] : 0;
            if ($doc_id <= 0 || !function_exists('zw_doc_get_document') || !zw_doc_get_document($doc_id)) {
                $notice = '发文不存在。';
            } else {
                update_post_meta($doc_id, '_zw_doc_letterhead_id', max(0, $id));
                $notice = $id > 0 ? '已为该发文指定红头模板。' : '已改为跟随默认红头。';
            }
            $back = zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id));
        } else {
            $notice = '未知红头操作。';
        }

        wp_safe_redirect(add_query_arg(array('zw_doc_msg' => $notice), $back));
        exit;
    }
}

if (!function_exists('zw_doc_render_letterhead_admin_section')) {
    /**
     * 红头模板管理区块（「红头与印章 → 红头模板」Tab）。
     *
     * @return void
     */
    function zw_doc_render_letterhead_admin_section() {
        $list    = zw_doc_letterhead_registry();
        $default = zw_doc_letterhead_default_id();
        $map     = zw_doc_letterhead_map();
        $types   = function_exists('zw_doc_number_settings') ? (array) zw_doc_number_settings()['doc_types'] : array();
        $notice  = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';
        $min_px  = (int) zw_doc_gb9704_get('header.letterhead_min_px', 1843);
        // TASK-1422：全局生成方式（唯一取值出处；本页是**唯一可写入口**所在页）。
        $mode_state = zw_doc_letterhead_mode_state();
        ?>
        <?php if ($notice !== '') { zw_doc_admin_notice($notice, (stripos($notice, '失败') !== false || stripos($notice, '不存在') !== false || stripos($notice, '不符合') !== false || stripos($notice, '无法切换') !== false || stripos($notice, '未知的红头生成方式') !== false) ? 'error' : 'success'); } ?>

        <div class="tco-doc-card">
            <h2>红头生成方式（全局默认）</h2>
            <p class="tco-doc-muted">
                改一次<strong>全场生效</strong>（非每篇切换）：<strong>版式预览</strong>与<strong>出件</strong>
                （HTML 打印 / DOCX / 加密 PDF）<strong>同源</strong>使用本设置。
                「数据字典」页只读展示当前值。
            </p>
            <form method="post">
                <?php wp_nonce_field('zw_doc_assets'); ?>
                <input type="hidden" name="zw_doc_assets_action" value="letterhead_mode">
                <p>
                    <label class="tco-touch">
                        <input type="radio" name="letterhead_mode" value="<?php echo esc_attr(ZW_DOC_LETTERHEAD_MODE_AUTO); ?>"<?php checked($mode_state['mode'], ZW_DOC_LETTERHEAD_MODE_AUTO); ?>>
                        <strong>系统自动生成红头</strong>（默认）—— 程序绘制「文字发文机关标志 + 红色分隔线」，<strong>不需要图片素材</strong>
                    </label>
                </p>
                <p>
                    <label class="tco-touch">
                        <input type="radio" name="letterhead_mode" value="<?php echo esc_attr(ZW_DOC_LETTERHEAD_MODE_IMAGE); ?>"<?php checked($mode_state['mode'], ZW_DOC_LETTERHEAD_MODE_IMAGE); ?>>
                        <strong>使用上传的红头文件（图片）</strong> —— 按「单篇指定 → 文种映射 → 默认红头」取<strong>已启用</strong>图片<?php echo $mode_state['ready'] ? '' : '（⚠️ 当前没有已启用的红头文件，切换会被拒绝）'; ?>
                    </label>
                </p>
                <p><button class="button button-primary" type="submit">保存生成方式</button></p>
            </form>
            <?php if ($mode_state['blocked']) : ?>
                <p class="tco-doc-muted" style="color:#b30404">
                    <strong>fail-closed：</strong>已切到「使用上传的红头文件（图片）」但无可用已启用红头 ——
                    预览与出件都不会静默改回「系统自动生成红头」，也不会渲染空白红头；
                    请先在下方<strong>上传 / 登记并启用</strong>红头文件，或切回「系统自动生成红头」。
                </p>
            <?php endif; ?>
        </div>

        <div class="tco-doc-card">
            <h2>上传 / 登记红头模板</h2>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('zw_doc_assets'); ?>
                <input type="hidden" name="zw_doc_assets_action" value="letterhead_upload">
                <p><label>模板名称 <input type="text" name="zw_doc_letterhead_name" maxlength="60" placeholder="如：总会红头（原文双线）"></label></p>
                <p><input type="file" name="zw_doc_letterhead_file" accept="image/png,image/jpeg" required></p>
                <p class="tco-doc-muted">
                    规格：宽度 ≥ <?php echo $min_px; ?>px（156mm @300dpi）、PNG/JPG、≤ 3MB。
                    图片含发文机关标志与红色分隔线者，渲染时按「图片模式」整体置顶、宽度铺满版心。
                </p>
                <p><button class="button button-primary" type="submit">上传并登记</button></p>
            </form>
            <hr>
            <form method="post">
                <?php wp_nonce_field('zw_doc_assets'); ?>
                <input type="hidden" name="zw_doc_assets_action" value="letterhead_attach">
                <p>
                    <label>或登记已上传的附件 ID
                        <input type="number" name="letterhead_attachment_id" min="1" step="1" style="width:120px">
                    </label>
                    <label>名称 <input type="text" name="zw_doc_letterhead_name" maxlength="60"></label>
                </p>
                <p><button class="button" type="submit">登记已有附件</button></p>
            </form>
        </div>

        <div class="tco-doc-card">
            <h2>模板列表（<?php echo count($list); ?>）</h2>
            <?php if (empty($list)) : ?>
                <p class="tco-doc-muted">
                    尚未登记红头模板。生成方式为「系统自动生成红头」（默认）时，渲染使用「文字发文机关标志 + 红色分隔线」，不需要素材；
                    若要切到「使用上传的红头文件（图片）」，必须先在此<strong>登记并启用</strong>至少一个红头文件（否则切换被拒绝、出件 fail-closed）。
                </p>
            <?php else : ?>
                <!-- TASK-1633（TASK-1579 P1-6 第四批）：宽表落 canonical 横滑包裹。
                     本区块由 doc-seal.php（页 slug `zw-doc-assets`）调用渲染，canonical 规则由该页
                     :1090 的 zw_doc_admin_ui_style()（本模块唯一出处）输出 ⇒ 本文件不新增 <style>。 -->
                <div class="tco-table-wrap">
                <table class="widefat striped">
                    <thead><tr><th>预览</th><th>名称</th><th>尺寸</th><th>dpi</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($list as $id => $meta) : ?>
                        <?php
                        $is_def = ((int) $default === (int) $id);
                        $low    = ((int) $meta['dpi'] > 0 && (int) $meta['dpi'] < 300);
                        ?>
                        <tr>
                            <td><img src="<?php echo esc_url(zw_doc_letterhead_url($id)); ?>" alt="" style="max-width:160px;height:auto;background:#fff;border:1px solid #dcdcde"></td>
                            <td>
                                <?php echo esc_html((string) $meta['name']); ?>
                                <?php if ($is_def) : ?><span class="tco-doc-badge">默认</span><?php endif; ?>
                            </td>
                            <td><?php echo (int) $meta['w']; ?>×<?php echo (int) $meta['h']; ?>px</td>
                            <td>
                                <?php echo (int) $meta['dpi']; ?> dpi
                                <?php if ($low) : ?><span class="tco-doc-badge is-warn">低清</span><?php endif; ?>
                            </td>
                            <td>
                                <?php echo !empty($meta['enabled']) ? '启用' : '停用'; ?>
                                <?php if ((float) (isset($meta['number_y']) ? $meta['number_y'] : 0) > 0) : ?>
                                    <span class="tco-doc-badge">文号已定位</span>
                                <?php else : ?>
                                    <span class="tco-doc-badge is-warn">文号未定位</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$is_def) : ?>
                                    <form method="post" style="display:inline">
                                        <?php wp_nonce_field('zw_doc_assets'); ?>
                                        <input type="hidden" name="zw_doc_assets_action" value="letterhead_default">
                                        <input type="hidden" name="letterhead_id" value="<?php echo (int) $id; ?>">
                                        <button class="button button-small" type="submit">设为默认</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" style="display:inline">
                                    <?php wp_nonce_field('zw_doc_assets'); ?>
                                    <input type="hidden" name="zw_doc_assets_action" value="letterhead_toggle">
                                    <input type="hidden" name="letterhead_id" value="<?php echo (int) $id; ?>">
                                    <input type="hidden" name="letterhead_enable" value="<?php echo !empty($meta['enabled']) ? '0' : '1'; ?>">
                                    <button class="button button-small" type="submit"><?php echo !empty($meta['enabled']) ? '停用' : '启用'; ?></button>
                                </form>
                                <form method="post" style="display:inline" onsubmit="return confirm('确认移除该模板登记？媒体库文件会保留。');">
                                    <?php wp_nonce_field('zw_doc_assets'); ?>
                                    <input type="hidden" name="zw_doc_assets_action" value="letterhead_remove">
                                    <input type="hidden" name="letterhead_id" value="<?php echo (int) $id; ?>">
                                    <button class="button button-small" type="submit">移除登记</button>
                                </form>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="6" style="background:#f6f7f7">
                                <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                                    <?php wp_nonce_field('zw_doc_assets'); ?>
                                    <input type="hidden" name="zw_doc_assets_action" value="letterhead_overlay">
                                    <input type="hidden" name="letterhead_id" value="<?php echo (int) $id; ?>">
                                    <strong>发文字号叠加坐标</strong>
                                    <label>横 % <input type="number" name="number_x" step="0.1" min="0" max="100" value="<?php echo esc_attr((string) (isset($meta['number_x']) ? $meta['number_x'] : 50)); ?>" style="width:80px"></label>
                                    <label>纵 % <input type="number" name="number_y" step="0.1" min="0" max="100" value="<?php echo esc_attr((string) (isset($meta['number_y']) ? $meta['number_y'] : 0)); ?>" style="width:80px"></label>
                                    <label>字号 pt <input type="number" name="number_size" step="0.5" min="0" max="72" value="<?php echo esc_attr((string) (isset($meta['number_size']) ? $meta['number_size'] : 0)); ?>" style="width:80px"></label>
                                    <button class="button button-small" type="submit">保存坐标</button>
                                    <span class="tco-doc-muted">
                                        纵坐标为 0 = 未配置，渲染会<strong>降级</strong>为「文号排在图片下方」；而 GB/T 9704 要求文号位于<strong>红色反线之上</strong>。<br>
                                        请先确认素材<strong>在机关标志与红线之间预留了文号区</strong>（建议 ≥2 行、约占图片高度 15%~20%），再把纵坐标设到该空白带（一般 60~75）。<br>
                                        若素材是<strong>紧裁</strong>的（标志与红线之间没有空白），则无论叠加还是排下方都<strong>不符合国标顺序</strong> —— 此时应改用
                                        <strong>预印红头纸套打</strong>（红头由纸张承载，只打印文号与正文对位）或更换预留了文号区的素材。
                                    </span>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="tco-doc-card">
            <h2>按文种指定模板</h2>
            <p class="tco-doc-muted">未指定文种时使用「默认红头」；单篇发文仍可在详情页单独覆盖。</p>
            <?php if (empty($list)) : ?>
                <p class="tco-doc-muted">登记模板后可在此配置。</p>
            <?php else : ?>
                <div class="tco-table-wrap">
                <table class="widefat striped">
                    <thead><tr><th>文种</th><th>指定模板</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($types as $type) : ?>
                        <?php $current = isset($map[$type]) ? (int) $map[$type] : 0; ?>
                        <tr>
                            <td><?php echo esc_html((string) $type); ?></td>
                            <td colspan="2">
                                <form method="post" style="display:flex;gap:8px;align-items:center">
                                    <?php wp_nonce_field('zw_doc_assets'); ?>
                                    <input type="hidden" name="zw_doc_assets_action" value="letterhead_map">
                                    <input type="hidden" name="doc_type" value="<?php echo esc_attr((string) $type); ?>">
                                    <select name="letterhead_id">
                                        <option value="0">跟随默认红头</option>
                                        <?php foreach ($list as $id => $meta) : ?>
                                            <option value="<?php echo (int) $id; ?>"<?php selected($current, (int) $id); ?>><?php echo esc_html((string) $meta['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="button button-small" type="submit">保存</button>
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
