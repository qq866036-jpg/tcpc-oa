<?php
/**
 * 收文管理 · OCR 客户端（OA-2463）。
 *
 * ## 唯一的跨进程契约（换引擎只换服务端，不改这里）
 *
 *   POST {endpoint}   Content-Type: application/json   X-TCPC-OCR-Token: <token>
 *   → 请求体：{"path":"<存储根内绝对路径>","engine":"<标识>","request_id":"<幂等键>","pages":0}
 *   ← 成功 200：{"ok":true,"text":"…","confidence":93.2,"engine":"rapidocr",
 *               "engine_version":"1.4.4","ms":1234,"pages":2,"lines":57}
 *   ← 失败 4xx/5xx：{"ok":false,"code":"…","error":"<给人看的原因>"}
 *
 *   GET {health}  ← {"ok":true,"engine":"…","engine_version":"…","models_ready":true}
 *
 * ## 为什么传「路径」而不是文件字节
 *
 * 服务与本插件**同机**，附件已在私有存储里（`{私有根}/receipts/部委/年份/文件`，不在 webroot）。
 * 传路径 ⇒ PHP 不读 20MB 到内存、不做 base64、不产生第二份临时文件；服务端自己按
 * **同一份根目录白名单**校验（realpath 前缀 + 扩展名 + 大小），越界一律 403。
 *
 * ## 失败语义（重要）
 *
 * 任何失败都返回 `WP_Error`，由 `ocr-job.php` 落 `ocr_status=failed` + `ocr_message`，
 * **绝不**中断收文登记 / 详情页渲染。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_ocr_client_request_id')) {
    /**
     * 请求幂等键（同记录 + 同文件 + 同引擎才可能重复；服务端可据此去重）。
     *
     * @param string $id       收文记录 ID。
     * @param string $relative 相对路径。
     * @return string
     */
    function zw_ocr_client_request_id($id, $relative) {
        return substr(hash('sha256', (string) $id . '|' . (string) $relative . '|' . zw_ocr_engine_name()), 0, 32);
    }
}

if (!function_exists('zw_ocr_client_resolve_path')) {
    /**
     * 把相对路径解析成**受管绝对路径**（必须在允许根内，且文件真实存在）。
     *
     * @param string $relative 相对路径（如 `民政部/2026/xxx.pdf`）。
     * @return string|WP_Error
     */
    function zw_ocr_client_resolve_path($relative) {
        $relative = trim((string) $relative);
        if ($relative === '' || !function_exists('zw_receipt_storage_absolute_path')) {
            return new WP_Error('zw_ocr_file_missing', '该收文没有可识别的附件路径。');
        }
        $absolute = (string) zw_receipt_storage_absolute_path($relative);
        if ($absolute === '' || !is_file($absolute)) {
            return new WP_Error('zw_ocr_file_missing', '附件文件不存在或已被移动（请核对归档目录）。');
        }
        $real = realpath($absolute);
        if ($real === false) {
            return new WP_Error('zw_ocr_file_missing', '附件路径无法解析（realpath 失败）。');
        }
        $inside = false;
        foreach (zw_ocr_allowed_roots() as $root) {
            $root_real = realpath($root);
            if ($root_real === false) {
                continue;
            }
            $root_real = rtrim($root_real, '/\\') . DIRECTORY_SEPARATOR;
            if (strpos($real, $root_real) === 0) {
                $inside = true;
                break;
            }
        }
        if (!$inside) {
            return new WP_Error('zw_ocr_path_outside_root', '附件不在收文私有存储根内，拒绝送去识别。');
        }

        $ext = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($ext, zw_ocr_supported_ext(), true)) {
            return new WP_Error('zw_ocr_ext_unsupported', '该文件类型不支持 OCR（支持：' . implode(' / ', zw_ocr_supported_ext()) . '）。');
        }
        $size = (int) @filesize($real);
        if ($size <= 0) {
            return new WP_Error('zw_ocr_file_empty', '附件是空文件，无法识别。');
        }
        if ($size > zw_ocr_max_bytes()) {
            return new WP_Error('zw_ocr_file_too_large', '附件超过 20MB，超过 OCR 单文件上限。');
        }

        return $real;
    }
}

if (!function_exists('zw_ocr_client_post')) {
    /**
     * 发一次 JSON 请求（统一超时 / 令牌 / 错误归一）。
     *
     * @param string $url    目标。
     * @param array  $body   请求体（数组）。
     * @param string $method HTTP 方法。
     * @return array|WP_Error 解析后的响应数组。
     */
    function zw_ocr_client_post($url, $body = array(), $method = 'POST') {
        if (!function_exists('wp_remote_request')) {
            return new WP_Error('zw_ocr_http_unavailable', '当前环境没有 HTTP 客户端（wp_remote_request 不可用）。');
        }
        $headers = array('Content-Type' => 'application/json; charset=utf-8');
        $token   = zw_ocr_token();
        if ($token !== '') {
            $headers['X-TCPC-OCR-Token'] = $token;
        }
        $args = array(
            'method'  => $method,
            'timeout' => zw_ocr_timeout(),
            'headers' => $headers,
        );
        if ($method !== 'GET') {
            $args['body'] = function_exists('wp_json_encode')
                ? wp_json_encode($body)
                : json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return new WP_Error(
                'zw_ocr_http_failed',
                '识别服务不可达：' . $response->get_error_message() . '（检查服务是否启动 / 端口是否正确）。'
            );
        }
        $code = (int) (function_exists('wp_remote_retrieve_response_code') ? wp_remote_retrieve_response_code($response) : 0);
        $raw  = (string) (function_exists('wp_remote_retrieve_body') ? wp_remote_retrieve_body($response) : '');
        $json = json_decode($raw, true);

        if (!is_array($json)) {
            return new WP_Error(
                'zw_ocr_bad_response',
                '识别服务返回的不是 JSON（HTTP ' . $code . '）。请检查端点是否为 OCR 服务。'
            );
        }
        if ($code < 200 || $code >= 300 || (isset($json['ok']) && !$json['ok'])) {
            $message = isset($json['error']) ? (string) $json['error'] : ('HTTP ' . $code);
            $error   = isset($json['code']) ? (string) $json['code'] : '';
            return new WP_Error('zw_ocr_server_error', '识别服务报错：' . $message, array('http' => $code, 'code' => $error));
        }
        return $json;
    }
}

if (!function_exists('zw_ocr_client_health')) {
    /**
     * 健康检查（后台「测试连接」用）。
     *
     * @return array|WP_Error
     */
    function zw_ocr_client_health() {
        $check = zw_ocr_validate_endpoint(zw_ocr_endpoint());
        if (is_wp_error($check)) {
            return $check;
        }
        if (!zw_ocr_is_enabled()) {
            return new WP_Error('zw_ocr_disabled', 'OCR 未启用（先打开开关）。');
        }
        return zw_ocr_client_post(zw_ocr_health_url(), array(), 'GET');
    }
}

if (!function_exists('zw_ocr_client_scan')) {
    /**
     * 识别一个收文附件。
     *
     * @param string $relative 相对路径（`zw_policy_source.source_file`）。
     * @param string $record_id 收文记录 ID（幂等键用）。
     * @return array|WP_Error 归一后的结果：text / confidence / engine / engine_version / ms / pages / lines。
     */
    function zw_ocr_client_scan($relative, $record_id = '') {
        if (!zw_ocr_is_enabled()) {
            return new WP_Error('zw_ocr_disabled', 'OCR 未启用（设置页打开开关后再试）。');
        }
        $check = zw_ocr_validate_endpoint(zw_ocr_endpoint());
        if (is_wp_error($check)) {
            return $check;
        }
        if (!zw_ocr_schema_ready()) {
            return new WP_Error('zw_ocr_schema_not_ready', 'OCR 数据列未迁移（先跑 scripts/migrate-receipt-ocr.php execute）。');
        }
        $path = zw_ocr_client_resolve_path($relative);
        if (is_wp_error($path)) {
            return $path;
        }

        $started = function_exists('microtime') ? microtime(true) : 0.0;
        $json    = zw_ocr_client_post(zw_ocr_endpoint(), array(
            'path'       => $path,
            'engine'     => zw_ocr_engine_name(),
            'request_id' => zw_ocr_client_request_id($record_id, $relative),
            'pages'      => 0,
        ));
        if (is_wp_error($json)) {
            return $json;
        }
        if (!isset($json['text']) || !is_string($json['text'])) {
            return new WP_Error('zw_ocr_bad_response', '识别服务没有返回 text 字段。');
        }

        $elapsed = ($started > 0 && function_exists('microtime')) ? (int) round((microtime(true) - $started) * 1000) : 0;
        $ms      = isset($json['ms']) ? (int) $json['ms'] : $elapsed;

        return array(
            'text'           => (string) $json['text'],
            'confidence'     => isset($json['confidence']) && is_numeric($json['confidence']) ? round((float) $json['confidence'], 2) : null,
            'engine'         => isset($json['engine']) ? (string) $json['engine'] : zw_ocr_engine_name(),
            'engine_version' => isset($json['engine_version']) ? (string) $json['engine_version'] : '',
            'ms'             => $ms > 0 ? $ms : $elapsed,
            'pages'          => isset($json['pages']) ? (int) $json['pages'] : 0,
            'lines'          => isset($json['lines']) ? (int) $json['lines'] : 0,
        );
    }
}
