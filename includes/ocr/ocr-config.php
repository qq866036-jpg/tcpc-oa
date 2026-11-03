<?php
/**
 * 收文管理 · OCR 配置（OA-2463）。
 *
 * ## 为什么单独一层
 *
 * 识别引擎（本地 Python 服务）是可替换的运维件：**PHP 侧只认一个 HTTP 契约**
 * （见 `ocr-client.php` 头部）。开关 / 端点 / 凭据 / 超时集中在**本文件**，
 * 页面与任务代码不读 option、不拼 URL、不判环境 —— 换引擎只改配置，不改业务代码。
 *
 * ## 默认关闭
 *
 * `zw_ocr_enabled` 默认 **0**（未部署服务时零行为：不排队、不发请求、不产生新状态）。
 * 打开方式：后台「收文管理 → OCR 识别设置」，或常量 `ZW_OCR_ENABLE`。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_ocr_option')) {
    /**
     * 读 option（无 WP 环境返回默认值；空串也视为未设置）。
     *
     * @param string $key     option 名。
     * @param mixed  $default 默认值。
     * @return mixed
     */
    function zw_ocr_option($key, $default = '') {
        if (!function_exists('get_option')) {
            return $default;
        }
        $value = get_option($key, $default);
        if ($value === null || $value === false || $value === '') {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('zw_ocr_is_enabled')) {
    /**
     * OCR 是否启用（常量优先，其次 option；默认关闭）。
     *
     * @return bool
     */
    function zw_ocr_is_enabled() {
        if (defined('ZW_OCR_ENABLE')) {
            return (bool) ZW_OCR_ENABLE;
        }
        return (string) zw_ocr_option('zw_ocr_enabled', '0') === '1';
    }
}

if (!function_exists('zw_ocr_default_endpoint')) {
    /**
     * 默认端点（本机回环，**不绑公网**）。
     *
     * @return string
     */
    function zw_ocr_default_endpoint() {
        return 'http://127.0.0.1:8848/ocr';
    }
}

if (!function_exists('zw_ocr_endpoint')) {
    /**
     * 识别端点（常量 > option > 默认）。只允许 http/https 且**必须是回环或内网地址**。
     *
     * 为什么不放公网：公文含个人信息，出服务器即违规。校验在 `zw_ocr_validate_endpoint()`。
     *
     * @return string
     */
    function zw_ocr_endpoint() {
        if (defined('ZW_OCR_ENDPOINT') && (string) ZW_OCR_ENDPOINT !== '') {
            return (string) ZW_OCR_ENDPOINT;
        }
        return (string) zw_ocr_option('zw_ocr_endpoint', zw_ocr_default_endpoint());
    }
}

if (!function_exists('zw_ocr_validate_endpoint')) {
    /**
     * 端点白名单：http/https + 主机必须是 `127.0.0.1` / `localhost` / `::1` / 内网段。
     *
     * @param string $url 端点。
     * @return true|WP_Error
     */
    function zw_ocr_validate_endpoint($url) {
        $url = trim((string) $url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return new WP_Error('zw_ocr_endpoint_invalid', 'OCR 端点必须是以 http:// 或 https:// 开头的地址。');
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return new WP_Error('zw_ocr_endpoint_invalid', 'OCR 端点缺少主机名。');
        }
        $allowed = array('127.0.0.1', 'localhost', '::1', '[::1]');
        $is_private = (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host);
        if (!in_array($host, $allowed, true) && !$is_private) {
            return new WP_Error(
                'zw_ocr_endpoint_public',
                'OCR 端点只允许本机回环或内网地址（公文不出服务器）：当前主机 ' . $host . '。'
            );
        }
        return true;
    }
}

if (!function_exists('zw_ocr_health_url')) {
    /**
     * 健康检查地址（端点末段 `/ocr` → `/health`；无末段时补 `/health`）。
     *
     * @return string
     */
    function zw_ocr_health_url() {
        $url = zw_ocr_endpoint();
        if (substr($url, -4) === '/ocr') {
            return substr($url, 0, -4) . '/health';
        }
        return rtrim($url, '/') . '/health';
    }
}

if (!function_exists('zw_ocr_token')) {
    /**
     * 共享令牌（常量优先）。为空表示服务端不校验（只允许回环时接受）。
     *
     * @return string
     */
    function zw_ocr_token() {
        if (defined('ZW_OCR_TOKEN')) {
            return (string) ZW_OCR_TOKEN;
        }
        return (string) zw_ocr_option('zw_ocr_token', '');
    }
}

if (!function_exists('zw_ocr_timeout')) {
    /**
     * 单次识别超时（秒；夹取 5–120）。
     *
     * @return int
     */
    function zw_ocr_timeout() {
        $value = (int) zw_ocr_option('zw_ocr_timeout', 30);
        return max(5, min(120, $value));
    }
}

if (!function_exists('zw_ocr_max_bytes')) {
    /**
     * 单文件上限（与登记上传同口径 20MB）。
     *
     * @return int
     */
    function zw_ocr_max_bytes() {
        return 20 * 1024 * 1024;
    }
}

if (!function_exists('zw_ocr_engine_name')) {
    /**
     * 引擎标识（写进 `extraction_method`，便于日后换引擎后区分数据来源）。
     *
     * @return string
     */
    function zw_ocr_engine_name() {
        return (string) zw_ocr_option('zw_ocr_engine_name', 'rapidocr');
    }
}

if (!function_exists('zw_ocr_allowed_roots')) {
    /**
     * 允许识别的文件根（**只有收文私有存储根**；服务端与 PHP 侧都按它校验）。
     *
     * @return string[]
     */
    function zw_ocr_allowed_roots() {
        $roots = array();
        if (function_exists('zw_receipt_storage_root')) {
            $root = (string) zw_receipt_storage_root();
            if ($root !== '') {
                $roots[] = rtrim($root, '/\\');
            }
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('zw_ocr_allowed_roots', $roots);
            if (is_array($filtered)) {
                $roots = $filtered;
            }
        }
        return array_values(array_unique(array_filter(array_map('strval', $roots))));
    }
}

if (!function_exists('zw_ocr_status_label')) {
    /**
     * 识别状态展示名（库内值 → 中文）。
     *
     * @param string $status 库内状态。
     * @return string
     */
    function zw_ocr_status_label($status) {
        $map = array(
            ''          => '未识别',
            'pending'   => '待识别',
            'running'   => '识别中',
            'done'      => '已识别',
            'failed'    => '识别失败',
            'skipped'   => '已跳过',
        );
        $status = trim((string) $status);
        return isset($map[$status]) ? $map[$status] : '未知状态';
    }
}

if (!function_exists('zw_ocr_supported_ext')) {
    /**
     * 可识别扩展名（服务端与客户端同一份白名单）。
     *
     * @return string[]
     */
    function zw_ocr_supported_ext() {
        return array('pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp');
    }
}
