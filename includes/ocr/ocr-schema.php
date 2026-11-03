<?php
/**
 * 收文管理 · OCR 数据落点（OA-2463）。
 *
 * ## 落点选择
 *
 * 复用 `zw_policy_source`（唯一政策库，铁律 I2），**只增 OCR 专用列**，不新建表：
 *
 * | 列 | 语义 |
 * | --- | --- |
 * | `ocr_status`（**已有**） | 空 / `pending` / `running` / `done` / `failed` / `skipped` |
 * | `extraction_method`（**已有**） | `ocr:<engine>@<version>`（换引擎后仍可区分来源） |
 * | `ocr_text` | 识别出的纯文本（LONGTEXT；只读展示，不参与正文生效） |
 * | `ocr_confidence` | 平均置信度百分比（0–100，两位小数；引擎不返回则 NULL） |
 * | `ocr_ms` | 本次识别耗时（毫秒） |
 * | `ocr_at` | 识别完成时间 |
 * | `ocr_message` | 失败原因（≤255 字，页面直接展示；不含文件内容） |
 *
 * 铁律：**绝不**把识别文本写进 `doc_name` / `basis` / `zw_policy_document_version`（正文生效面只读）。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_ocr_columns')) {
    /**
     * OCR 依赖列（表内列名 → 判定用；`ocr_status` / `extraction_method` 为既有列，不重复新增）。
     *
     * @return string[]
     */
    function zw_ocr_columns() {
        return array('ocr_status', 'extraction_method', 'ocr_text', 'ocr_confidence', 'ocr_ms', 'ocr_at', 'ocr_message');
    }
}

if (!function_exists('zw_ocr_new_columns')) {
    /**
     * 本任务**新增**的列（迁移脚本只 ALTER 这些）。
     *
     * @return array<string,string>
     */
    function zw_ocr_new_columns() {
        return array(
            'ocr_text'       => 'LONGTEXT NULL',
            'ocr_confidence' => 'DECIMAL(5,2) NULL',
            'ocr_ms'         => 'INT UNSIGNED NULL',
            'ocr_at'         => 'DATETIME NULL',
            'ocr_message'    => 'VARCHAR(255) NULL',
        );
    }
}

if (!function_exists('zw_ocr_source_columns')) {
    /**
     * 探测政策源表实际列（`SHOW COLUMNS`；结果按请求级缓存）。
     *
     * @param bool $fresh 强制重查。
     * @return string[] 列名；DB 不可用返回空数组。
     */
    function zw_ocr_source_columns($fresh = false) {
        static $cache = null;
        if ($fresh) {
            $cache = null;
        }
        if (is_array($cache)) {
            return $cache;
        }
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results') || !function_exists('zw_receipt_ledger_source_table')) {
            return array();
        }
        $table = zw_receipt_ledger_source_table();
        if ($table === '') {
            return array();
        }
        $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", 'ARRAY_A');
        $rows = is_array($rows) ? $rows : array();
        $cols = array();
        foreach ($rows as $row) {
            if (isset($row['Field'])) {
                $cols[] = (string) $row['Field'];
            }
        }
        $cache = $cols;
        return $cols;
    }
}

if (!function_exists('zw_ocr_missing_columns')) {
    /**
     * 缺失的 OCR 列（迁移未跑时非空 ⇒ 调用方必须**短路**，不得发查询/写库）。
     *
     * @return string[]
     */
    function zw_ocr_missing_columns() {
        $cols = zw_ocr_source_columns();
        if (!$cols) {
            return zw_ocr_columns();
        }
        $missing = array();
        foreach (zw_ocr_columns() as $need) {
            if (!in_array($need, $cols, true)) {
                $missing[] = $need;
            }
        }
        return $missing;
    }
}

if (!function_exists('zw_ocr_schema_ready')) {
    /**
     * OCR 列是否就绪。
     *
     * @return bool
     */
    function zw_ocr_schema_ready() {
        return zw_ocr_missing_columns() === array();
    }
}
