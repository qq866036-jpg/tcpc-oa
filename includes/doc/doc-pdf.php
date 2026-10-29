<?php
/**
 * 公文加密 PDF（正式用印件）任务与产物 —— TASK-774 / SPEC-87 §11、§21。
 *
 * 链路（P1 整页栅格化，五步顺序不可换）：
 *   ① HTML（红头 + 正文 + 印章，全部 data-URI 内联）
 *   ② chromium --headless --print-to-pdf      → 矢量 PDF
 *   ③ pdftoppm -jpeg -r <dpi>                 → 逐页位图（**印章独立对象在此消失**）
 *   ④ 纯 PHP 手写整页图像 PDF（本文件 `zw_doc_pdf_build_image_pdf()`）
 *   ⑤ qpdf --encrypt "" <OWNER> 256 --modify=none --extract=n → 加密成品
 *
 * 铁律：
 * - **③ 之后不得再有任何"加章"步骤**，否则印章又成为 PDF 内独立对象，P1 失去意义；
 * - 产物与中间文件都在 **webroot 之外**，只经鉴权端点下载；
 * - owner 口令**只从 wp-config.php 常量读取**，不落库、不落日志、不进仓库；
 * - 临时文件用完即删，失败**不产出半成品**。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_PDF_VERSION')) {
    define('ZW_DOC_PDF_VERSION', '1');
}

if (!function_exists('zw_doc_pdf_settings')) {
    /**
     * PDF 渲染与加密设置（默认值取自 SPEC-87 版式常量）。
     *
     * @return array
     */
    function zw_doc_pdf_settings() {
        return array(
            'dpi_options'    => function_exists('zw_doc_gb9704_allowed_dpi') ? zw_doc_gb9704_allowed_dpi() : array(150, 200, 300),
            'dpi_default'    => (int) zw_doc_gb9704_get('pdf.dpi_default', 200),
            'max_pages'      => (int) zw_doc_gb9704_get('pdf.max_pages', 20),
            'max_bytes'      => (int) zw_doc_gb9704_get('pdf.max_bytes', 31457280),
            'key_bits'       => (int) zw_doc_gb9704_get('pdf.encrypt_key_bits', 256),
            'permissions'    => (array) zw_doc_gb9704_get('pdf.permissions', array()),
            'owner_constant' => (string) zw_doc_gb9704_get('pdf.owner_pass_constant', 'ZW_DOC_PDF_OWNER_PASS'),
        );
    }
}

if (!function_exists('zw_doc_pdf_owner_pass')) {
    /**
     * 编辑口令（owner password）——**只从 wp-config.php 常量读取**。
     *
     * @return string 未配置返回空串（调用方必须据此阻断生成）。
     */
    function zw_doc_pdf_owner_pass() {
        $constant = (string) zw_doc_gb9704_get('pdf.owner_pass_constant', 'ZW_DOC_PDF_OWNER_PASS');
        if ($constant === '' || !defined($constant)) {
            return '';
        }
        $value = (string) constant($constant);

        return trim($value);
    }
}

if (!function_exists('zw_doc_pdf_owner_pass_configured')) {
    /**
     * 是否已配置编辑口令（**只判断存在性，不回显口令**）。
     *
     * @return bool
     */
    function zw_doc_pdf_owner_pass_configured() {
        return zw_doc_pdf_owner_pass() !== '';
    }
}

if (!function_exists('zw_doc_pdf_tools')) {
    /**
     * 渲染工人依赖的外部工具路径（可用常量覆盖，便于不同发行版）。
     *
     * @return array
     */
    function zw_doc_pdf_tools() {
        return array(
            'chromium' => defined('ZW_DOC_CHROMIUM_BIN') ? (string) ZW_DOC_CHROMIUM_BIN : 'chromium-browser',
            'rasterize' => defined('ZW_DOC_PDFTOPPM_BIN') ? (string) ZW_DOC_PDFTOPPM_BIN : 'pdftoppm',
            'encrypt'  => defined('ZW_DOC_QPDF_BIN') ? (string) ZW_DOC_QPDF_BIN : 'qpdf',
        );
    }
}

if (!function_exists('zw_doc_pdf_tools_ready')) {
    /**
     * 依赖检查（工人入口与后台提示共用）。
     *
     * @return array{tools:array,missing:string[]}
     */
    function zw_doc_pdf_tools_ready() {
        $tools   = zw_doc_pdf_tools();
        $missing = array();
        foreach ($tools as $label => $bin) {
            $found = false;
            if ($bin !== '' && strpos($bin, '/') === 0) {
                $found = is_executable($bin);
            } elseif (function_exists('shell_exec')) {
                // 仅用于探测（web 侧通常禁用了 exec 族；工人侧 CLI 无此限制）
                $which = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
                $found = is_string($which) && trim($which) !== '';
            } else {
                $found = is_executable('/usr/bin/' . $bin) || is_executable('/usr/local/bin/' . $bin);
            }
            if (!$found) {
                $missing[] = $label . '(' . $bin . ')';
            }
        }

        return array('tools' => $tools, 'missing' => $missing);
    }
}

if (!function_exists('zw_doc_pdf_dir')) {
    /**
     * 加密产物目录（**webroot 之外**，与工人目录一致）。
     *
     * @return string
     */
    function zw_doc_pdf_dir() {
        // 与 scripts/doc-render-worker.php、scripts/install-doc-render-worker.sh 保持**同一口径**，
        // 否则下载端点会在 Web 侧找不到工人产出的文件。
        if (defined('ZW_DOC_RENDER_DIR') && ZW_DOC_RENDER_DIR !== '') {
            return rtrim((string) ZW_DOC_RENDER_DIR, '/') . '/out';
        }

        return '/opt/zw-doc-render/out';
    }
}

if (!function_exists('zw_doc_pdf_retention_policy')) {
    /**
     * 加密 PDF 产物留档策略 —— **唯一出处**（TASK-1386 ④ 用户拍板「永久不删」）。
     *
     * 用户口径原文（2026-10-10 拍板）：**「旧产物留档 需要"永久不删"策略」**。
     *
     * 硬约束（任何模块、任何脚本都不得违反）：
     * - `zw_doc_pdf_dir()`（`<render_dir>/out`，webroot 之外）内的加密 PDF 一律**永久留档**：
     *   **不删除、不覆盖、不 GC**；含 `_zw_doc_seal_history` 记录过的历史版本产物；
     * - 产物命名**只增**：`zw-doc-<doc_id>-<YmdHis>-<sha8>.pdf`，同秒重名再加 `-r2/-r3…`
     *   （见 `scripts/doc-render-worker.php`）⇒ **任何一次生成都不复用旧文件名**；
     * - 版本元数据 `_zw_doc_pdf_versions` **只增不减**（见 `zw_doc_pdf_version_append()`）；
     * - 允许清理的**只有中间临时文件**：`<render_dir>/jobs/*`（含正文与印章，24h 后清）与
     *   渲染过程的 `*.tmp.pdf`；**`<render_dir>/out/*` 不在任何清理范围内**。
     *
     * 本函数是「永久不删」的**可被断言与生产只读实测**的锚点（策略值 + 目录口径）。
     *
     * @return array{mode:string,delete_allowed:bool,overwrite_allowed:bool,dir:string,jobs:string,note:string}
     */
    function zw_doc_pdf_retention_policy() {
        $dir  = function_exists('zw_doc_pdf_dir') ? (string) zw_doc_pdf_dir() : '';
        $jobs = $dir !== '' ? rtrim(dirname($dir), '/') . '/jobs' : '';

        return array(
            'mode'              => 'append_only',
            'delete_allowed'    => false,
            'overwrite_allowed' => false,
            'dir'               => $dir,
            'jobs'              => $jobs,
            'note'              => '旧产物留档永久不删：产物只增不删、绝不复用旧文件名；允许清理的只有 jobs/ 与 *.tmp.pdf。',
        );
    }
}

if (!function_exists('zw_doc_pdf_path_is_retained')) {
    /**
     * 该路径是否属于**永久留档**产物（`out/` 内）——删除/覆盖前的唯一判据。
     *
     * 供任何需要清理的代码先问一句「这是留档产物吗」；本函数**只判定不动作**。
     *
     * @param string $path 待判定路径（绝对或相对）。
     * @return bool
     */
    function zw_doc_pdf_path_is_retained($path) {
        $path = (string) $path;
        if ($path === '' || !function_exists('zw_doc_pdf_dir')) {
            return false;
        }
        $dir = rtrim((string) zw_doc_pdf_dir(), '/');
        if ($dir === '') {
            return false;
        }
        $real_dir = @realpath($dir);
        $real     = @realpath($path);

        return ($real !== false && $real_dir !== false && strpos($real, $real_dir . '/') === 0)
            || strpos($path, $dir . '/') === 0;
    }
}

if (!function_exists('zw_doc_pdf_statuses')) {
    /**
     * 任务状态集合与展示名。
     *
     * @return array
     */
    function zw_doc_pdf_statuses() {
        return array(
            'none'      => '未生成',
            'queued'    => '排队中',
            'rendering' => '生成中',
            'ready'     => '已生成',
            'failed'    => '生成失败',
        );
    }
}

if (!function_exists('zw_doc_pdf_status_label')) {
    /**
     * 状态展示名。
     *
     * @param string $status 状态。
     * @return string
     */
    function zw_doc_pdf_status_label($status) {
        $map = zw_doc_pdf_statuses();

        return isset($map[(string) $status]) ? $map[(string) $status] : (string) $status;
    }
}

if (!function_exists('zw_doc_pdf_status')) {
    /**
     * 读取加密 PDF 任务状态。
     *
     * @param int $doc_id 发文 ID。
     * @return array
     */
    function zw_doc_pdf_status($doc_id) {
        $doc_id = (int) $doc_id;
        $req    = get_post_meta($doc_id, '_zw_doc_pdf_req', true);

        return array(
            'status'   => (string) get_post_meta($doc_id, '_zw_doc_pdf_status', true),
            'file'     => (string) get_post_meta($doc_id, '_zw_doc_pdf_file', true),
            'sha256'   => (string) get_post_meta($doc_id, '_zw_doc_pdf_sha256', true),
            'pages'    => (int) get_post_meta($doc_id, '_zw_doc_pdf_pages', true),
            'bytes'    => (int) get_post_meta($doc_id, '_zw_doc_pdf_bytes', true),
            'dpi'      => (int) get_post_meta($doc_id, '_zw_doc_pdf_dpi', true),
            'error'    => (string) get_post_meta($doc_id, '_zw_doc_pdf_error', true),
            'queued_at' => (string) get_post_meta($doc_id, '_zw_doc_pdf_queued_at', true),
            'ready_at' => (string) get_post_meta($doc_id, '_zw_doc_pdf_ready_at', true),
            'by'       => (int) get_post_meta($doc_id, '_zw_doc_pdf_by', true),
            'req'      => is_array($req) ? $req : array(),
        );
    }
}

if (!function_exists('zw_doc_pdf_versions')) {
    /**
     * 加密 PDF 产物**版本列表**（只增不减）——TASK-1386 ④「旧产物留档 做版本管理」。
     *
     * 用户拍板原文：「旧产物留档 做版本管理 只有超级管理员看到」。
     *
     * 口径：
     * - 工人产物文件名本身已含 `时间戳 + 内容 SHA-256 前 8 位`（见 `scripts/doc-render-worker.php`），
     *   即**每次生成都是新文件、绝不覆盖旧加密 PDF**；本列表在其上给出**版本号**与元数据。
     * - 元数据（版本号 / 路径 / SHA-256 / 生成时间 / 操作者 / dpi / 页数）存
     *   `_zw_doc_pdf_versions`（同族 meta，与 `_zw_doc_pdf_*` 并列）；本函数**只读**，任何路径不得删除。
     *
     * @param int $doc_id 发文 ID。
     * @return array<int,array<string,mixed>> 按版本号升序。
     */
    function zw_doc_pdf_versions($doc_id) {
        $raw = get_post_meta((int) $doc_id, '_zw_doc_pdf_versions', true);
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : array();
    }
}

if (!function_exists('zw_doc_pdf_version_get')) {
    /**
     * 取指定版本号的历史产物记录；不存在返回空数组。
     *
     * @param int $doc_id  发文 ID。
     * @param int $version 版本号（从 1 起）。
     * @return array<string,mixed>
     */
    function zw_doc_pdf_version_get($doc_id, $version) {
        $version = (int) $version;
        if ($version <= 0) {
            return array();
        }
        foreach (zw_doc_pdf_versions($doc_id) as $row) {
            if (is_array($row) && (int) (isset($row['version']) ? $row['version'] : 0) === $version) {
                return $row;
            }
        }

        return array();
    }
}

if (!function_exists('zw_doc_pdf_version_append')) {
    /**
     * 追加一个产物版本（只增不减；同文件同 SHA 幂等去重）——**唯一版本写入入口**。
     *
     * TASK-1386 ④「永久不删」：本入口**只追加**，不做任何删除；且
     * - 同文件名 + 不同 SHA ⇒ **拒绝登记**（返回 0，fail-closed，防止"覆盖旧产物"被记账）；
     * - 版本号取「已存最大版本号 + 1」⇒ 版本号永不复用；
     * - 既有记录逐条保留（写入的是「已存列表 + 新条目」，绝不截断）。
     *
     * @param int   $doc_id 发文 ID。
     * @param array $data   产物信息：file / sha256 / bytes / pages / dpi / generated_at。
     * @param int   $actor  操作者（0 = 工人/系统）。
     * @return int 版本号；未写入返回 0。
     */
    function zw_doc_pdf_version_append($doc_id, $data = array(), $actor = 0) {
        $doc_id = (int) $doc_id;
        $data   = is_array($data) ? $data : array();
        // 只存**文件名**（basename）⇒ 读取侧必须与 `zw_doc_pdf_dir()` 拼接，杜绝任意路径。
        $file = isset($data['file']) ? basename((string) $data['file']) : '';
        if ($doc_id <= 0 || $file === '') {
            return 0;
        }
        $sha = isset($data['sha256']) ? (string) $data['sha256'] : '';
        // TASK-1386 ④「永久不删」：先读**已存**版本列表，任何写入都必须满足
        //   ① 列表只变长（既有记录逐条保留、绝不被改写或丢弃）；
        //   ② 版本号只增（取 max+1，绝不因任何缺口而复用旧版本号）；
        //   ③ 同一产物文件名只对应同一内容（同名不同 SHA ⇒ **拒绝登记**，避免"覆盖旧版本产物"被静默记录）。
        $versions    = zw_doc_pdf_versions($doc_id);
        $max_version = 0;
        foreach ($versions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $max_version = max($max_version, (int) (isset($row['version']) ? $row['version'] : 0));
            if ((string) (isset($row['file']) ? $row['file'] : '') !== $file) {
                continue;
            }
            if ((string) (isset($row['sha256']) ? $row['sha256'] : '') !== $sha) {
                // fail-closed：同名不同内容 ⇒ 有人试图复用/覆盖旧产物文件名，拒绝写库。
                return 0;
            }
            // 同文件同 SHA ⇒ 幂等：返回既有版本号，不重复登记。
            return (int) (isset($row['version']) ? $row['version'] : 0);
        }
        $version  = $max_version + 1;
        $versions[] = array(
            'version'      => $version,
            'file'         => $file,
            'sha256'       => $sha,
            'bytes'        => (int) (isset($data['bytes']) ? $data['bytes'] : 0),
            'pages'        => (int) (isset($data['pages']) ? $data['pages'] : 0),
            'dpi'          => (int) (isset($data['dpi']) ? $data['dpi'] : 0),
            'generated_at' => (string) (isset($data['generated_at']) && $data['generated_at'] !== ''
                ? $data['generated_at']
                : (function_exists('current_time') ? current_time('mysql') : '')),
            'actor'        => (int) $actor,
        );
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($versions, JSON_UNESCAPED_UNICODE)
            : json_encode($versions, JSON_UNESCAPED_UNICODE);
        update_post_meta($doc_id, '_zw_doc_pdf_versions', (string) $encoded);

        return $version;
    }
}

if (!function_exists('zw_doc_pdf_gate')) {
    /**
     * 生成加密 PDF 的门禁（纯函数，便于单测）。
     *
     * | 门禁 | 含义 |
     * | --- | --- |
     * | P1 | 已用印（`seal_mode = formal`）—— 正式用印件的前提 |
     * | P2 | 有正文 |
     * | P3 | 具备用印权限（与用印同一权限口径） |
     * | P4 | 已配置 owner 编辑口令常量 |
     * | P5 | 任务不在排队/生成中（幂等） |
     *
     * @param array $doc 发文记录。
     * @param array $ctx 上下文：can_seal / owner_configured / status。
     * @return array 问题列表（空 = 放行）。
     */
    function zw_doc_pdf_gate($doc, $ctx = array()) {
        $problems = array();
        $ctx      = is_array($ctx) ? $ctx : array();

        if ((string) (isset($doc['seal_mode']) ? $doc['seal_mode'] : '') !== 'formal') {
            $problems[] = array('code' => 'p1_not_sealed', 'message' => '尚未完成正式用印，不能生成正式用印件。');
        }
        if (empty($doc['body']) || (function_exists('zw_doc_body_is_empty') && zw_doc_body_is_empty($doc['body']))) {
            $problems[] = array('code' => 'p2_no_body', 'message' => '正文为空，无法生成。');
        }
        if (empty($ctx['can_seal'])) {
            $problems[] = array('code' => 'p3_capability', 'message' => '当前账号没有用印权限。');
        }
        if (empty($ctx['owner_configured'])) {
            $problems[] = array('code' => 'p4_no_owner_pass', 'message' => '尚未配置 PDF 编辑口令（wp-config.php 常量），拒绝生成。');
        }
        $status = isset($ctx['status']) ? (string) $ctx['status'] : '';
        if (in_array($status, array('queued', 'rendering'), true)) {
            $problems[] = array('code' => 'p5_busy', 'message' => '该发文的用印件正在生成中，请稍候。');
        }

        return $problems;
    }
}

if (!function_exists('zw_doc_pdf_queue')) {
    /**
     * 投递生成任务（后台触发；工人在 CLI 侧消费）。
     *
     * @param int  $doc_id 发文 ID。
     * @param int  $actor  操作人。
     * @param int  $dpi    栅格化 dpi（白名单夹取）。
     * @return true|WP_Error
     */
    function zw_doc_pdf_queue($doc_id, $actor = 0, $dpi = 0) {
        $doc_id = (int) $doc_id;
        $doc    = function_exists('zw_doc_get_document') ? zw_doc_get_document($doc_id) : null;
        if (!$doc) {
            return new WP_Error('zw_doc_pdf_no_doc', '发文不存在。');
        }

        $status   = zw_doc_pdf_status($doc_id);
        $problems = zw_doc_pdf_gate($doc, array(
            'can_seal'         => function_exists('zw_doc_seal_can_use') ? zw_doc_seal_can_use() : false,
            'owner_configured' => zw_doc_pdf_owner_pass_configured(),
            'status'           => $status['status'],
        ));
        if (!empty($problems)) {
            $first = $problems[0];

            return new WP_Error('zw_doc_pdf_gate_' . $first['code'], $first['message']);
        }

        $settings = zw_doc_pdf_settings();
        $dpi      = function_exists('zw_doc_gb9704_normalize_dpi') ? zw_doc_gb9704_normalize_dpi($dpi > 0 ? $dpi : $settings['dpi_default']) : 200;
        $actor    = $actor ? (int) $actor : get_current_user_id();

        // TASK-1422 **fail-closed**：切到「使用上传的红头文件（图片）」却无可用已启用红头时，
        // **不投递**加密 PDF 任务（避免工人侧渲染出「静默回落」或空白红头的正式产物）。
        $lh_gate = function_exists('zw_doc_letterhead_mode_gate') ? zw_doc_letterhead_mode_gate() : true;
        if (is_wp_error($lh_gate)) {
            return $lh_gate;
        }

        $letterhead = function_exists('zw_doc_letterhead_resolve')
            ? zw_doc_letterhead_resolve($doc)
            : array('id' => 0, 'url' => '', 'path' => '', 'source' => 'none', 'name' => '', 'dpi' => 0, 'low' => false, 'ok' => false, 'overlay' => null);
        $req        = array(
            'dpi'              => $dpi,
            'letterhead_id'    => (int) $letterhead['id'],
            'letterhead_source' => (string) $letterhead['source'],
            'seal_id'          => (string) (isset($doc['seal_id']) ? $doc['seal_id'] : ''),
            'page_mode'        => isset($doc['body']['meta']['page_mode']) ? (string) $doc['body']['meta']['page_mode'] : 'standard',
            'body_hash'        => (string) (isset($doc['body_hash']) ? $doc['body_hash'] : ''),
            'queued_by'        => $actor,
        );

        update_post_meta($doc_id, '_zw_doc_pdf_status', 'queued');
        update_post_meta($doc_id, '_zw_doc_pdf_req', $req);
        update_post_meta($doc_id, '_zw_doc_pdf_dpi', $dpi);
        update_post_meta($doc_id, '_zw_doc_pdf_queued_at', current_time('mysql'));
        update_post_meta($doc_id, '_zw_doc_pdf_by', $actor);
        delete_post_meta($doc_id, '_zw_doc_pdf_error');

        if (function_exists('zw_doc_log_event')) {
            zw_doc_log_event($doc_id, 'pdf_queued', $actor, '', 'queued', '投递加密 PDF 任务（' . $dpi . 'dpi，印章 #' . $req['seal_id'] . '）');
        }

        return true;
    }
}

if (!function_exists('zw_doc_pdf_mark')) {
    /**
     * 工人侧写回任务状态（**唯一的状态写入入口**）。
     *
     * @param int    $doc_id 发文 ID。
     * @param string $status queued|rendering|ready|failed。
     * @param array  $data   产物信息：file / pages / bytes / sha256 / dpi / error。
     * @param int    $actor  操作人（0 = 工人）。
     * @return bool
     */
    function zw_doc_pdf_mark($doc_id, $status, $data = array(), $actor = 0) {
        $doc_id = (int) $doc_id;
        $status = sanitize_key((string) $status);
        if (!array_key_exists($status, zw_doc_pdf_statuses()) || $status === 'none') {
            return false;
        }
        $data = is_array($data) ? $data : array();

        update_post_meta($doc_id, '_zw_doc_pdf_status', $status);
        update_post_meta($doc_id, '_zw_doc_pdf_updated_at', current_time('mysql'));
        foreach (array('file' => '_zw_doc_pdf_file', 'sha256' => '_zw_doc_pdf_sha256', 'bytes' => '_zw_doc_pdf_bytes', 'pages' => '_zw_doc_pdf_pages', 'dpi' => '_zw_doc_pdf_dpi', 'error' => '_zw_doc_pdf_error') as $key => $meta_key) {
            if (array_key_exists($key, $data)) {
                update_post_meta($doc_id, $meta_key, $data[$key]);
            }
        }
        if ($status === 'ready') {
            update_post_meta($doc_id, '_zw_doc_pdf_ready_at', current_time('mysql'));
            // TASK-1386 ④：产物就绪即登记一个**版本**（只增不减）。
            // 工人产物文件名含时间戳 + SHA-256 前缀 ⇒ 旧加密 PDF **不被覆盖**，本列表只补版本号与元数据。
            if (function_exists('zw_doc_pdf_version_append')) {
                zw_doc_pdf_version_append($doc_id, array(
                    'file'         => (string) get_post_meta($doc_id, '_zw_doc_pdf_file', true),
                    'sha256'       => (string) get_post_meta($doc_id, '_zw_doc_pdf_sha256', true),
                    'bytes'        => (int) get_post_meta($doc_id, '_zw_doc_pdf_bytes', true),
                    'pages'        => (int) get_post_meta($doc_id, '_zw_doc_pdf_pages', true),
                    'dpi'          => (int) get_post_meta($doc_id, '_zw_doc_pdf_dpi', true),
                    'generated_at' => (string) get_post_meta($doc_id, '_zw_doc_pdf_ready_at', true),
                ), (int) $actor);
            }
        }

        if (function_exists('zw_doc_log_event')) {
            $note = $status === 'ready'
                ? '生成完成（' . (int) (isset($data['pages']) ? $data['pages'] : 0) . ' 页 / ' . size_format((int) (isset($data['bytes']) ? $data['bytes'] : 0)) . '）'
                : ('生成任务状态：' . zw_doc_pdf_status_label($status) . (isset($data['error']) && $data['error'] !== '' ? '（' . $data['error'] . '）' : ''));
            zw_doc_log_event($doc_id, 'pdf_' . $status, $actor, '', $status, $note);
        }

        // TASK-1295：带印章件生成完成的广播钩子（唯一产物咽喉）——
        // 「任」字文书的后置通道（归档 + webp 预热）由此触发，避免轮询。
        // 只广播不改状态；消费者缺省无副作用（`do_action` 不存在时代码不执行，保持既有单测可跑）。
        if ($status === 'ready' && function_exists('do_action')) {
            do_action('zw_doc_pdf_ready', $doc_id, $data, $actor);
        }

        return true;
    }
}

if (!function_exists('zw_doc_pdf_mode_allows')) {
    /**
     * POSIX 权限位判定：给定「属主 / 属组 / 权限位」与「读取者 uid / gid 列表」，判断读取者能否读该文件。
     *
     * 唯一出处（TASK-1429）：产物「存在但读不到」的判定不得各写一套。
     * 语义与内核一致：root（uid 0）不受权限位约束；属主取 `0400`、属组取 `0040`、其他人取 `0004`。
     *
     * @param int   $mode    权限位（只取低 9 位）。
     * @param int   $file_uid 文件属主 uid。
     * @param int   $file_gid 文件属组 gid。
     * @param int   $as_uid   读取者 uid。
     * @param array $as_gids  读取者的附加组 gid 列表。
     * @return bool
     */
    function zw_doc_pdf_mode_allows($mode, $file_uid, $file_gid, $as_uid, $as_gids = array()) {
        $mode = ((int) $mode) & 0777;
        $as_uid = (int) $as_uid;
        if ($as_uid === 0) {
            return true;
        }
        if ((int) $file_uid === $as_uid) {
            return ($mode & 0400) !== 0;
        }
        foreach ((array) $as_gids as $gid) {
            if ((int) $gid === (int) $file_gid) {
                return ($mode & 0040) !== 0;
            }
        }

        return ($mode & 0004) !== 0;
    }
}

if (!function_exists('zw_doc_pdf_path_allowed')) {
    /**
     * 路径是否落在 PHP `open_basedir` 白名单内（纯函数，便于单测）。
     *
     * TASK-1997 生产事故：站点 `.user.ini` 的 `open_basedir` 白名单含 `/opt/zw-doc-seal/`（印章可读），
     * 但**漏了 `/opt/zw-doc-render/`** ⇒ PHP-FPM 侧 `file_exists()` 恒为 false、`scandir()` 报
     * 「Operation not permitted」⇒ 下载端点报「产物校验未通过：产物文件不存在。」，
     * 而 root/CLI 侧一切正常（**同一份代码两种结论**）。此函数用于把这种"看不见的墙"说清楚。
     *
     * @param string $path       绝对路径。
     * @param string $basedir    `ini_get('open_basedir')` 的值；空 = 未限制。
     * @return array{allowed:bool,blocked_by:string}
     */
    function zw_doc_pdf_path_allowed($path, $basedir) {
        $path    = (string) $path;
        $basedir = trim((string) $basedir);
        if ($basedir === '') {
            return array('allowed' => true, 'blocked_by' => '');
        }
        if ($path === '') {
            return array('allowed' => false, 'blocked_by' => '');
        }
        $real = @realpath($path);
        $probe = $real !== false ? $real : $path;
        foreach (preg_split('/[:;]/', $basedir) as $dir) {
            $dir = trim((string) $dir);
            if ($dir === '') {
                continue;
            }
            $dir = rtrim($dir, '/');
            if ($dir === '' ) {
                continue;
            }
            if ($probe === $dir || strpos($probe, $dir . '/') === 0) {
                return array('allowed' => true, 'blocked_by' => '');
            }
        }

        return array('allowed' => false, 'blocked_by' => (string) dirname($probe));
    }
}

if (!function_exists('zw_doc_pdf_read_barrier')) {
    /**
     * 产物可读性守卫 —— **唯一出处**（TASK-1429 事故防复发）。
     *
     * 背景（已发生线上事故）：`/opt/zw-doc-render/out/zw-doc-27735-….pdf` 确实存在
     * （694311 字节），但属主是 `root:root 0640`，而站点以 `www` 运行、目录 `750 www:www`
     * ⇒ Web 侧读不到；旧实现用 `!is_file()` 一把判定，于是把「读不到」误报为「文件不存在」，
     * 掩盖了真正的**权限**原因。
     *
     * 本函数把「存在性 / 是否普通文件 / 可读性 / PDF 魔数」四件事分开判定，供下载端点与自检共用。
     *
     * @param string   $path    产物路径。
     * @param int|null $as_uid  以该 uid 视角判定权限位（null = 以当前进程视角，即 `is_readable()`）。
     * @param array    $as_gids 该 uid 的附加组（仅 $as_uid 非 null 时参与判定）。
     * @return array{code:string,reason:string,head:string,exists:bool,regular:bool,readable:bool,note:string}
     *         code ∈ ok / missing / not_regular / unreadable / bad_magic
     */
    function zw_doc_pdf_read_barrier($path, $as_uid = null, $as_gids = array()) {
        $path = is_string($path) ? $path : '';
        $out  = array(
            'code' => 'ok', 'reason' => '', 'head' => '',
            'exists' => false, 'regular' => false, 'readable' => false, 'note' => '',
        );

        if ($path === '') {
            $out['code']   = 'missing';
            $out['reason'] = '产物文件不存在。';

            return $out;
        }

        // 断链符号链接：`file_exists()` 为假但路径确实被占用，分开措辞便于运维定位。
        $out['exists'] = file_exists($path) || is_link($path);
        if (!$out['exists']) {
            $out['code'] = 'missing';
            // TASK-1997：**先排除 open_basedir 拦截** —— 否则会误报「文件不存在」，把运维引向错误方向
            // （生产实测：`.user.ini` 白名单漏了 `/opt/zw-doc-render/`，Web 侧 stat 恒 false，CLI/root 正常）。
            $allow = zw_doc_pdf_path_allowed($path, (string) ini_get('open_basedir'));
            if (!$allow['allowed']) {
                $out['reason'] = '产物路径不在 PHP open_basedir 白名单内（' . $allow['blocked_by']
                    . '），Web 进程无法访问该目录；请把该目录加入站点 .user.ini 的 open_basedir 并重载 php-fpm。';
            } else {
                $out['reason'] = '产物文件不存在。';
            }

            return $out;
        }
        $out['regular'] = is_file($path);
        if (!$out['regular']) {
            $out['code']   = 'not_regular';
            $out['reason'] = '产物路径不是普通文件（目录或特殊文件），Web 侧无法读取。';

            return $out;
        }

        $mode = @fileperms($path);
        $uid  = @fileowner($path);
        $gid  = @filegroup($path);
        $out['note'] = '属主 uid=' . (int) $uid . ' 属组 gid=' . (int) $gid
            . ' 权限 ' . ($mode === false ? '未知' : substr(sprintf('%04o', (int) $mode), -4));

        $readable = @is_readable($path);
        if ($as_uid !== null && $mode !== false) {
            // 诊断模式：按「以某用户身份运行」的 POSIX 语义判定（不受当前进程是否为 root 影响）。
            $readable = zw_doc_pdf_mode_allows((int) $mode, (int) $uid, (int) $gid, (int) $as_uid, $as_gids);
            if (!$readable) {
                $out['note'] .= '（以 uid=' . (int) $as_uid . ' 判定不可读）';
            }
        }
        $out['readable'] = (bool) $readable;

        // 真正读一次头 8 字节：既做魔数校验，也兜住「is_readable 为真但读取失败」的场景。
        $head = @file_get_contents($path, false, null, 0, 8);
        if ($head === false) {
            $out['readable'] = false;
        }

        if (!$out['readable']) {
            $out['code']   = 'unreadable';
            $out['reason'] = '产物文件不可读（属主/权限不匹配：' . $out['note'] . '）。';

            return $out;
        }
        $out['head'] = (string) $head;

        if (strpos($out['head'], '%PDF-') !== 0) {
            $out['code']   = 'bad_magic';
            $out['reason'] = '产物不是合法 PDF（缺少 %PDF- 头）。';
        }

        return $out;
    }
}

if (!function_exists('zw_doc_pdf_verify')) {
    /**
     * 产物自检。
     *
     * TASK-1429：文件层判定一律经 `zw_doc_pdf_read_barrier()`（唯一出处）——
     * 「存在但读不到」必须给出**权限**原因，不得再笼统报「产物文件不存在」。
     *
     * @param string $path  文件路径。
     * @param array  $opts  require_encrypt / expected_pages / max_bytes。
     * @param int|null $as_uid 以该 uid 视角判定可读性（null = 当前进程；下载端点与工人均用 null）。
     * @param array  $as_gids 该 uid 的附加组（仅 $as_uid 非 null 时参与判定）。
     * @return array{ok:bool,reasons:string[],bytes:int,encrypted:bool}
     */
    function zw_doc_pdf_verify($path, $opts = array(), $as_uid = null, $as_gids = array()) {
        $opts = array_merge(array('require_encrypt' => false, 'expected_pages' => 0, 'max_bytes' => 0), is_array($opts) ? $opts : array());
        $out  = array('ok' => false, 'reasons' => array(), 'bytes' => 0, 'encrypted' => false);

        $barrier = zw_doc_pdf_read_barrier($path, $as_uid, $as_gids);
        if ($barrier['code'] === 'missing' || $barrier['code'] === 'not_regular' || $barrier['code'] === 'unreadable') {
            $out['reasons'][] = $barrier['reason'];

            return $out;
        }

        $out['bytes'] = (int) @filesize($path);
        if ($out['bytes'] <= 0) {
            $out['reasons'][] = '产物为空文件。';
        }
        $max = (int) $opts['max_bytes'];
        if ($max > 0 && $out['bytes'] > $max) {
            $out['reasons'][] = '产物超过体积上限（' . size_format($out['bytes']) . '）。';
        }

        if ($barrier['code'] === 'bad_magic') {
            $out['reasons'][] = $barrier['reason'];
        }

        // /Encrypt 位于未加密的 trailer 字典中，可被检索到；内容流加密不影响该判定。
        $tail = (string) @file_get_contents($path, false, null, max(0, $out['bytes'] - 4096), 4096);
        $out['encrypted'] = (strpos($tail, '/Encrypt') !== false);
        if (!empty($opts['require_encrypt']) && !$out['encrypted']) {
            $out['reasons'][] = '产物未加密（缺少 /Encrypt）。';
        }
        if ($out['encrypted'] && !empty($opts['forbid_encrypt'])) {
            $out['reasons'][] = '中间产物不应被加密。';
        }

        if ((int) $opts['expected_pages'] > 0) {
            $found = preg_match_all('#/Type\s*/Page[^s]#', (string) @file_get_contents($path), $m);
            // 加密产物的对象流不可直接计数，此时以栅格化阶段记录的页数为准，不因计数不符而判失败。
            if (!$out['encrypted'] && (int) $found !== (int) $opts['expected_pages']) {
                $out['reasons'][] = '页数与栅格化结果不一致（期望 ' . (int) $opts['expected_pages'] . '，实测 ' . (int) $found . '）。';
            }
        }

        $out['ok'] = empty($out['reasons']);

        return $out;
    }
}

if (!function_exists('zw_doc_pdf_build_image_pdf')) {
    /**
     * 纯 PHP 整页图像 PDF 构建器（P1 链路第 ④ 步）。
     *
     * **每页只有一个整页图像对象** —— 栅格化之后不存在独立的印章/图形对象，
     * 这是「从 PDF 干净抠章」在技术上不可行的实现保证。
     *
     * 支持 JPEG（`/DCTDecode`，直接嵌入不重编码，默认路径）与 PNG（经 GD 转 RGB + `/FlateDecode`）。
     *
     * @param array  $images 每项 array{path:string}。
     * @param string $target 目标路径。
     * @return true|WP_Error
     */
    function zw_doc_pdf_build_image_pdf($images, $target) {
        if (!is_array($images) || empty($images)) {
            return new WP_Error('zw_doc_pdf_no_images', '没有可用的页面位图。');
        }
        $target = (string) $target;
        if ($target === '') {
            return new WP_Error('zw_doc_pdf_no_target', '未指定目标路径。');
        }

        $width_pt  = 595.276; // A4
        $height_pt = 841.890;

        $objects   = array(); // 1-based 对象号 => 内容（不含 "N 0 obj"/"endobj"）
        $page_ids  = array();
        $contents  = array();

        // 预留：1 = Catalog，2 = Pages
        $next = 3;

        foreach ($images as $image) {
            $path = is_array($image) && isset($image['path']) ? (string) $image['path'] : '';
            if ($path === '' || !is_file($path)) {
                return new WP_Error('zw_doc_pdf_image_missing', '页面位图缺失：' . $path);
            }
            $raw = (string) @file_get_contents($path);
            if ($raw === '') {
                return new WP_Error('zw_doc_pdf_image_empty', '页面位图为空：' . $path);
            }
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            if ($ext === 'jpg' || $ext === 'jpeg') {
                $size = @getimagesize($path);
                if (!is_array($size) || empty($size[0])) {
                    return new WP_Error('zw_doc_pdf_image_bad', '无法识别位图尺寸：' . $path);
                }
                $img_w = (int) $size[0];
                $img_h = (int) $size[1];
                $img_body = $raw;
                $filter   = '/DCTDecode';
            } else {
                $converted = zw_doc_pdf_png_to_rgb($path);
                if (is_wp_error($converted)) {
                    return $converted;
                }
                $img_w    = (int) $converted['w'];
                $img_h    = (int) $converted['h'];
                $img_body = gzcompress($converted['rgb'], 6);
                $filter   = '/FlateDecode';
            }

            $img_obj  = $next++;
            $cont_obj = $next++;
            $page_obj = $next++;

            $objects[$img_obj] = '<</Type/XObject/Subtype/Image/Width ' . $img_w . '/Height ' . $img_h
                . '/ColorSpace/DeviceRGB/BitsPerComponent 8/Filter ' . $filter
                . '/Length ' . strlen($img_body) . ">>\nstream\n" . $img_body . "\nendstream";

            $content = "q\n" . $width_pt . " 0 0 " . $height_pt . " 0 0 cm\n/Im0 Do\nQ\n";
            $objects[$cont_obj] = '<</Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream";

            $objects[$page_obj] = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 ' . $width_pt . ' ' . $height_pt . ']'
                . '/Resources<</XObject<</Im0 ' . $img_obj . ' 0 R>>>>/Contents ' . $cont_obj . ' 0 R>>';

            $page_ids[] = $page_obj;
        }

        $objects[1] = '<</Type/Catalog/Pages 2 0 R>>';
        $objects[2] = '<</Type/Pages/Count ' . count($page_ids) . '/Kids[' . implode(' ', array_map(function ($id) {
            return $id . ' 0 R';
        }, $page_ids)) . ']>>';

        ksort($objects);

        $pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array();
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf         .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref_pos = strlen($pdf);
        $count    = max(array_keys($objects)) + 1;
        $pdf     .= "xref\n0 " . $count . "\n";
        $pdf     .= "0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<</Size " . $count . "/Root 1 0 R>>\nstartxref\n" . $xref_pos . "\n%%EOF\n";

        if (@file_put_contents($target, $pdf) === false) {
            return new WP_Error('zw_doc_pdf_write_failed', '写入 PDF 失败。');
        }

        return true;
    }
}

if (!function_exists('zw_doc_pdf_png_to_rgb')) {
    /**
     * PNG → 原始 RGB（GD）。仅在工人使用 PNG 栅格化时走此路径。
     *
     * @param string $path PNG 路径。
     * @return array{w:int,h:int,rgb:string}|WP_Error
     */
    function zw_doc_pdf_png_to_rgb($path) {
        if (!function_exists('imagecreatefrompng')) {
            return new WP_Error('zw_doc_pdf_no_gd', '服务器缺少 GD PNG 支持，无法处理 PNG 位图（可改用 JPEG 栅格化）。');
        }
        $src = @imagecreatefrompng($path);
        if (!$src) {
            return new WP_Error('zw_doc_pdf_png_read', '无法读取 PNG 位图。');
        }
        $w   = imagesx($src);
        $h   = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        $rgb = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $color = imagecolorat($dst, $x, $y);
                $rgb  .= chr(($color >> 16) & 0xFF) . chr(($color >> 8) & 0xFF) . chr($color & 0xFF);
            }
        }
        imagedestroy($dst);

        return array('w' => $w, 'h' => $h, 'rgb' => $rgb);
    }
}

if (!function_exists('zw_doc_pdf_download_url')) {
    /**
     * 加密 PDF 下载 / 内嵌浏览 URL（带 nonce）。
     *
     * TASK-1379（用户口径「可以在线浏览…加密的 pdf 文件 …内嵌下载」）：
     * `$inline = true` 时追加 `inline=1` —— 同一端点、**同一 nonce 与同一权限校验**（`zw_doc_can_view_doc()`），
     * 仅把响应头由 `Content-Disposition: attachment` 改为 `inline`，供页面内 `<object>` 内嵌浏览。
     * **不做服务端解密**：文件仍是 AES-256 加密件（浏览器/阅读器打开时提示编辑口令）。
     *
     * @param int  $doc_id  发文 ID。
     * @param bool $inline  是否内嵌浏览（默认 false = 下载）。
     * @param int  $version 版本号（0 = 当前版本；>0 = 历史版本，**仅超级管理员**可访问，TASK-1386 ④）。
     * @return string
     */
    function zw_doc_pdf_download_url($doc_id, $inline = false, $version = 0) {
        $args = array(
            'action' => 'zw_doc_pdf_download',
            'doc_id' => (int) $doc_id,
        );
        if ($inline) {
            $args['inline'] = 1;
        }
        if ((int) $version > 0) {
            $args['version'] = (int) $version;
        }
        $url = add_query_arg($args, admin_url('admin-post.php'));

        return wp_nonce_url($url, 'zw_doc_pdf_download_' . (int) $doc_id);
    }
}

if (!function_exists('zw_doc_handle_pdf_download')) {
    /**
     * 加密 PDF 鉴权下载。
     *
     * @return void
     */
    function zw_doc_handle_pdf_download() {
        $doc_id = isset($_GET['doc_id']) ? (int) $_GET['doc_id'] : 0;

        if (!function_exists('zw_doc_is_enabled') || !zw_doc_is_enabled()) {
            wp_die('发文管理未启用。', '不可用', array('response' => 403));
        }
        check_admin_referer('zw_doc_pdf_download_' . $doc_id);

        $doc = function_exists('zw_doc_get_document') ? zw_doc_get_document($doc_id) : null;
        if (!$doc) {
            wp_die('发文不存在。', '未找到', array('response' => 404));
        }
        if (function_exists('zw_doc_can_view_doc') && !zw_doc_can_view_doc($doc)) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }

        $status = zw_doc_pdf_status($doc_id);
        // TASK-1386 ④：`version>0` ⇒ 历史产物；**仅超级管理员**放行（普通有权者只见当前版本）。
        // 复用同一 nonce / 可见性判定 / `zw_doc_pdf_verify(['require_encrypt' => true])`，不另造下载实现。
        $version = isset($_GET['version']) ? (int) $_GET['version'] : 0;
        if ($version > 0) {
            if (!function_exists('zw_doc_is_super_admin') || !zw_doc_is_super_admin()) {
                wp_die('历史版本（旧产物）仅超级管理员可见。', '禁止访问', array('response' => 403));
            }
            $entry = zw_doc_pdf_version_get($doc_id, $version);
            if (empty($entry['file'])) {
                wp_die('该历史版本不存在。', '未找到', array('response' => 404));
            }
            $path     = zw_doc_pdf_dir() . '/' . basename((string) $entry['file']);
            $filename = 'zw-doc-' . $doc_id . '-v' . $version . (string) $doc['number'] . '.pdf';
        } else {
            if ($status['status'] !== 'ready' || $status['file'] === '') {
                wp_die('该发文的正式用印件尚未生成完成。', '不可下载', array('response' => 404));
            }
            $path     = zw_doc_pdf_dir() . '/' . basename($status['file']);
            $filename = 'zw-doc-' . $doc_id . (string) $doc['number'] . '.pdf';
        }

        $check = zw_doc_pdf_verify($path, array('require_encrypt' => true));
        if (!$check['ok']) {
            wp_die('产物校验未通过：' . esc_html(implode('；', $check['reasons'])), '不可下载', array('response' => 500));
        }

        if (function_exists('sanitize_file_name')) {
            $filename = sanitize_file_name($filename);
        }

        if (function_exists('zw_doc_log_download')) {
            zw_doc_log_download($doc_id, 0, $filename, 'success');
        }

        nocache_headers();
        // TASK-1379：`inline=1` ⇒ 页面内**内嵌浏览**（不下载）；权限与 nonce 校验同上，**不做服务端解密**。
        // 安全边界不变：仍必须是「可见该发文者」且产物通过 `zw_doc_pdf_verify(['require_encrypt' => true])`。
        $disposition = !empty($_GET['inline']) ? 'inline' : 'attachment';
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}

if (!function_exists('zw_doc_pdf_handle_queue_post')) {
    /**
     * 「生成正式用印件」POST 处理（`zw_doc_action=pdf_queue`）。
     *
     * @return void
     */
    function zw_doc_pdf_handle_queue_post() {
        $doc_id = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
        check_admin_referer('zw_doc_pdf_queue_' . $doc_id);

        $result = zw_doc_pdf_queue($doc_id, get_current_user_id(), isset($_POST['pdf_dpi']) ? (int) $_POST['pdf_dpi'] : 0);
        $msg    = is_wp_error($result) ? $result->get_error_message() : '已投递加密 PDF 生成任务，通常 1 分钟内完成（由系统工人处理）。';

        wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id, 'zw_doc_msg' => $msg)));
        exit;
    }
}
