<?php
/**
 * 公文撰写页（新建 4 步标签向导 / 编辑正文 / 在线编辑器本体）—— TASK-772 / TASK-786 / TASK-1089 / SPEC-87 §20。
 *
 * 设计取舍：
 * - **单表单分步**而非多请求向导：公文要素字段较多，多请求会丢状态；四步以**标签 + 面板显隐**呈现，一次提交完成，
 *   无 JS 时全部面板可见可直接提交（渐进增强）；
 * - **唯一底部操作条**（TASK-1089）：返回 / 上一步 / 下一步 / 保存草稿 / 提交审核 在同一行；「提交审核」只在末步出现，
 *   其余步骤可随时「保存草稿并占用文号」；
 * - **表单 novalidate + 页面脚本校验**（TASK-1089）：隐藏面板里的原生 `required` 会让浏览器在 `submit` 事件之前中止提交
 *   （控件不可聚焦），改由脚本统一校验并跳到缺项所在步骤，服务端校验仍是最终权威；
 * - **零新增 POST 契约**：新建提交沿用既有 `zw_doc_action=create_document` 的字段名（文种/标题/摘要/部门/日期/审核人/抄送/附件），
 *   只**新增**正文块与公文要素字段 → 取号、幂等、审核人校验、附件处理全部复用既有逻辑，不另造一套创建流程；
 * - **功能开关 `zw_doc_composer_enabled` 默认 0**：关闭时新建页完全走既有表单（逐字段一致），可秒级回退；
 * - **预览用同一个渲染器**（`admin-post` 端点，不保存不占号），避免"预览与成品不一致"；
 * - 编辑已有草稿走 `zw_doc_update_body()`（受用印锁定保护）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_COMPOSER_VERSION')) {
    define('ZW_DOC_COMPOSER_VERSION', '1');
}

if (!function_exists('zw_doc_composer_enabled')) {
    /**
     * 在线撰写是否启用（默认关闭，保证可秒级回退）。
     *
     * @return bool
     */
    function zw_doc_composer_enabled() {
        return !empty(get_option('zw_doc_composer_enabled', 0));
    }
}

if (!function_exists('zw_doc_composer_cn_date')) {
    /**
     * ISO 日期 → 公文成文日期（阿拉伯数字、月日不补零）。
     *
     * TASK-1335：实现已上移到**最早加载**的 `doc-helpers.php`（`zw_doc_iso_to_cn_date()`），
     * 以消除「本文件未加载时拿不到该函数 ⇒ 同一要素两处口径」的隐患。此处仅**保留原名字做包装**
     * （既有调用点与断言均按本名字书写，不改名、不破坏兼容）。
     *
     * @param string $iso 形如 2026-10-04。
     * @return string 形如 2026年10月4日；无法解析或非真实日期返回空串。
     */
    function zw_doc_composer_cn_date($iso) {
        if (function_exists('zw_doc_iso_to_cn_date')) {
            return (string) zw_doc_iso_to_cn_date($iso);
        }
        // 兜底（理论上不可达：doc-helpers.php 先于本文件加载）。
        $iso = trim((string) $iso);
        if ($iso === '' || !preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $iso, $m)) {
            return '';
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }

        return (int) $m[1] . '年' . (int) $m[2] . '月' . (int) $m[3] . '日';
    }
}

if (!function_exists('zw_doc_composer_iso_date')) {
    /**
     * 公文成文日期 → ISO 日期（`type="date"` 标准控件回填用，TASK-1263）。
     *
     * 兼容三类输入：
     * - 已是 ISO（`2026-10-04`）→ 原样归一；
     * - 中文成文日期（`2026年10月4日`）→ 转换；
     * - 其它自由文本（旧记录里的「二〇二六年十月四日」等）→ **返回空串**，由调用方回退为文本控件，
     *   避免旧记录被日期控件静默重置为其它日期。
     *
     * @param string $date 成文日期。
     * @return string 形如 2026-10-04；无法解析返回空串。
     */
    function zw_doc_composer_iso_date($date) {
        $date = trim((string) $date);
        if ($date === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3])
                : '';
        }
        if (preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日$/u', $date, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3])
                : '';
        }

        return '';
    }
}

if (!function_exists('zw_doc_composer_receiver_options')) {
    /**
     * 主送机关数据字典（TASK-1263 用户拍板：逗号分隔给出 4 项）。
     *
     * 「其他」不是取值，而是「切到自由输入」的开关，见 `zw_doc_composer_receiver_other_value()`。
     *
     * @return string[]
     */
    function zw_doc_composer_receiver_options() {
        return array('各会员单位', '各位会员', '尊敬的各位会员', '各分支机构');
    }
}

if (!function_exists('zw_doc_composer_receiver_other_value')) {
    /** 「其他」选项的内部取值（不落库）。 */
    function zw_doc_composer_receiver_other_value() {
        return '__other__';
    }
}

if (!function_exists('zw_doc_composer_receiver_limit')) {
    /** 主送机关自由输入字数上限：20 个汉字（TASK-1263 用户拍板；按用户输入口径计数，不含自动补的冒号）。 */
    function zw_doc_composer_receiver_limit() {
        return 20;
    }
}

if (!function_exists('zw_doc_composer_receiver_strip_colon')) {
    /**
     * 去掉主送机关末尾的全角/半角冒号（用于与数据字典比对回填）。
     *
     * @param string $receiver 原文。
     * @return string
     */
    function zw_doc_composer_receiver_strip_colon($receiver) {
        $receiver = trim((string) $receiver);
        if ($receiver === '') {
            return '';
        }
        $last = function_exists('mb_substr') ? mb_substr($receiver, -1, 1, 'UTF-8') : substr($receiver, -1);
        if ($last === '：' || $last === ':') {
            $receiver = function_exists('mb_substr') ? mb_substr($receiver, 0, -1, 'UTF-8') : substr($receiver, 0, -1);
        }

        return trim($receiver);
    }
}

if (!function_exists('zw_doc_composer_receiver_normalize')) {
    /**
     * 主送机关归一（TASK-1263）：末尾统一为**全角冒号**（SPEC-87 §6「末尾全角冒号」）。
     *
     * 顺序固定：去尾冒号 → 按上限截断（不把自动补的冒号算进上限）→ 补全角冒号。
     * 幂等：`X：` → `X` → `X：`。
     *
     * @param string $receiver 用户输入。
     * @param int    $limit    用户输入字数上限；<=0 表示不截断（用于保留超长存量值）。
     * @return string
     */
    function zw_doc_composer_receiver_normalize($receiver, $limit = 0) {
        $receiver = zw_doc_composer_receiver_strip_colon($receiver);
        if ($receiver === '') {
            return '';
        }
        $limit = (int) $limit;
        if ($limit > 0 && function_exists('zw_doc_body_truncate')) {
            $receiver = zw_doc_body_truncate($receiver, $limit);
            $receiver = zw_doc_composer_receiver_strip_colon($receiver);
        }
        if ($receiver === '') {
            return '';
        }

        return $receiver . '：';
    }
}

if (!function_exists('zw_doc_composer_receiver_applies')) {
    /**
     * 主送机关是否适用于该文种（TASK-1325 用户口径：「任」字号文件不需要主送机关）。
     *
     * 判据复用「任」字文种的**唯一判定函数**（同 `doc-appoint` 的隔离点），不另立字典：
     * 「任」⇒ 不渲染该字段、服务端不接受该取值；其他文种⇒行为**逐字节不变**。
     * 判定函数缺失时（模块未装载）按「适用」处理，保持既有行为不回归。
     *
     * @param string $doc_type 文种。
     * @return bool
     */
    function zw_doc_composer_receiver_applies($doc_type) {
        if (function_exists('zw_doc_appoint_is_appoint_type') && zw_doc_appoint_is_appoint_type($doc_type)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('zw_doc_composer_body_import_applies')) {
    /**
     * 第 2 步是否提供「整篇导入」区（批量粘贴 / 上传 Word）。
     *
     * **TASK-1349（用户 2026-10-09 拍板）**：「任」字公文的正文由系统按任命要素**自动生成四段**
     * （`zwcApFillBody()`，TASK-1325/1329 口径），整篇导入与之语义冲突 ⇒ **不提供**该入口，
     * 第 2 步只保留「正文段（在线编写）」。**含编辑存量任字公文**（口径一致，避免「新建隐藏、编辑又出现」）。
     *
     * 与「主送机关豁免」同型：**服务端条件渲染**（不得只靠 JS 隐藏），判定函数缺失时按「适用」处理
     * （模块未装载时保持既有行为不回归）。
     *
     * @param string $doc_type 文种。
     * @return bool
     */
    function zw_doc_composer_body_import_applies($doc_type) {
        if (function_exists('zw_doc_appoint_is_appoint_type') && zw_doc_appoint_is_appoint_type($doc_type)) {
            return false;
        }

        return true;
    }
}

if (!function_exists('zw_doc_composer_copy_no')) {
    /**
     * 份号归一（TASK-1276 / CHG-155，GB/T 9704-2012 §7.2.1）。
     *
     * ⚠️ TASK-1867（CHG-362）：**实现已迁到唯一出处** `zw_doc_copy_number_normalize()`
     * （`inc/admin/doc/doc-copy-number-repository.php`）。本函数只做薄包装，保留旧函数名
     * 给既有调用方与 `tests/test-doc-marks.php` 的断言（归一语义一字未变）。
     *
     * @param string $value 用户输入。
     * @return string
     */
    function zw_doc_composer_copy_no($value) {
        if (function_exists('zw_doc_copy_number_normalize')) {
            return zw_doc_copy_number_normalize($value);
        }
        // 兜底（模块未按 bootstrap 顺序加载时，保持旧行为，不改变结果）：
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (!is_string($digits)) {
            $digits = '';
        }
        if ($digits === '') {
            return '';
        }
        $width = (int) zw_doc_gb9704_get('header.copy_no_digits', 6);
        if ($width <= 0) {
            $width = 6;
        }
        if (strlen($digits) > $width) {
            $digits = substr($digits, -$width);
        }

        return str_pad($digits, $width, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('zw_doc_copy_number_resolve_for_save')) {
    /**
     * 份号取号解析（新建 / 编辑保存共用，TASK-1867 / CHG-362）。
     *
     * 口径：
     * - `none`   ⇒ 不标注，返回空串；
     * - `manual` ⇒ 手填归一后**校验唯一**（`$doc_id` 自身豁免），被占用则 `WP_Error`；
     * - `auto`   ⇒ 取「台账 + 存量公文都未使用」的最小可用号；号段用尽 ⇒ `WP_Error`（fail-closed）；
     * - 未知/缺省（旧页面缓存、程序化 POST）⇒ 按 `manual` 处理（**零行为变更**：旧契约仍是手填值）。
     *
     * 本函数**只解析与校验，不占号**；占号由调用方在建单/存正文后执行
     * `zw_doc_copy_number_bind()`（先占号再释放旧号）。
     *
     * @param string $mode      auto / manual / none。
     * @param string $posted    手填值（未归一）。
     * @param string $current   当前已存份号（编辑态）。
     * @param int    $doc_id    发文 ID（0 = 新建）。
     * @return string|WP_Error
     */
    function zw_doc_copy_number_resolve_for_save($mode, $posted, $current = '', $doc_id = 0) {
        $mode = in_array($mode, array('auto', 'manual', 'none'), true) ? $mode : 'manual';
        if ($mode === 'none') {
            return '';
        }
        if ($mode === 'auto') {
            $next = function_exists('zw_doc_copy_number_next_free') ? (string) zw_doc_copy_number_next_free() : '';
            if ($next === '') {
                return new WP_Error(
                    'zw_doc_copy_number_exhausted',
                    '可用份号已用尽（6 位号段上限 999999），请改为手动指定，或选择「不标注」。'
                );
            }

            return $next;
        }

        $value = zw_doc_composer_copy_no($posted);
        if ($value === '') {
            return ''; // 手填留空 = 不标注（与既有口径一致）
        }
        if (function_exists('zw_doc_copy_number_is_free') && !zw_doc_copy_number_is_free($value, (int) $doc_id)) {
            return new WP_Error(
                'zw_doc_copy_number_taken',
                sprintf('份号 %s 已被占用，请换一个（可下拉选择空闲份号）。', $value)
            );
        }

        return $value;
    }
}

if (!function_exists('zw_doc_composer_secret_parse')) {
    /**
     * 反解析 `meta.secret` 为「密级 + 保密期限」（TASK-1276 / CHG-155，§7.2.2）。
     *
     * 只按**第一个**分隔符切分（期限文本里再出现分隔符时原样保留在期限里）；
     * 不校验密级是否在字典内 —— 历史值须原样回填，不得丢数据。分隔符唯一出处 = `header.secret_separator`。
     *
     * @param string $secret 形如 秘密★1年 / 秘密。
     * @return array{level:string,period:string}
     */
    function zw_doc_composer_secret_parse($secret) {
        $secret = trim((string) $secret);
        if ($secret === '') {
            return array('level' => '', 'period' => '');
        }
        $sep = (string) zw_doc_gb9704_get('header.secret_separator', '★');
        if ($sep === '') {
            return array('level' => $secret, 'period' => '');
        }
        $pos = strpos($secret, $sep);
        if ($pos === false) {
            return array('level' => $secret, 'period' => '');
        }

        return array(
            'level'  => substr($secret, 0, $pos),
            'period' => substr($secret, $pos + strlen($sep)),
        );
    }
}

if (!function_exists('zw_doc_composer_secret_build')) {
    /**
     * 拼装 `meta.secret`：密级（+ 期限非空时 `分隔符 + 期限`）（TASK-1276 / CHG-155，§7.2.2）。
     *
     * 以 `header.secret_levels` 为**密级字典唯一出处**：密级不在字典内（含空值）时不做标注，
     * 避免把任意文本写进版头（历史值仍由编辑回填的「额外选项 / 文本回退」保留，见 §5）。
     * 期限由用户自由填写（国标未规定枚举，`待复核`）。
     *
     * @param string $level  密级。
     * @param string $period 保密期限。
     * @return string
     */
    function zw_doc_composer_secret_build($level, $period) {
        $levels = (array) zw_doc_gb9704_get('header.secret_levels', array());
        $levels = array_map('strval', $levels);
        $level  = trim((string) $level);
        if ($level === '' || !in_array($level, $levels, true)) {
            return '';
        }
        $period = trim((string) $period);
        if ($period === '') {
            return $level;
        }
        $sep = (string) zw_doc_gb9704_get('header.secret_separator', '★');

        return $level . $sep . $period;
    }
}

if (!function_exists('zw_doc_composer_urgency')) {
    /**
     * 紧急程度白名单归一出（TASK-1276 / CHG-155，§7.2.3）：只允许 `header.urgency_levels` 内取值或空。
     *
     * 非法值一律丢弃（返回空串），不报错。
     *
     * @param string $value 用户输入 / 历史值。
     * @return string
     */
    function zw_doc_composer_urgency($value) {
        $value  = trim((string) $value);
        $levels = array_map('strval', (array) zw_doc_gb9704_get('header.urgency_levels', array()));

        return ($value !== '' && in_array($value, $levels, true)) ? $value : '';
    }
}

if (!function_exists('zw_doc_apply_org_pick')) {
    /**
     * A1 口径的 POST 归一（TASK-1271 用户拍板）：**以「发文机关」为准**覆写三处。
     *
     * 三处回显控件只是呈现层，Construct POST 也可改 ⇒ 服务端必须以此函数为准：
     * - `zwc_org_name`（红头）= 提交值若属于该机关候选则沿用，否则回落默认「机关+文件」；
     * - `zwc_sign_org`（署名）= `zw_doc_sign_org()`（TASK-1280：分支机构**带协会全称前缀**，不含「文件」）；
     * - `doc_department`（台账部门）= 机关名**本身**（**不加前缀**，否则破坏分支机构关联）。
     *
     * 机关为空或不在名录内时**原样返回**（保持旧表单/历史路径的既有行为）。
     *
     * @param array $post 已 wp_unslash 的提交数据。
     * @return array
     */
    function zw_doc_apply_org_pick($post) {
        $post = is_array($post) ? $post : array();
        $org  = isset($post['zwc_org_pick']) ? trim((string) $post['zwc_org_pick']) : '';
        if ($org === '' || !function_exists('zw_doc_org_apply')) {
            return $post;
        }
        $resolved = zw_doc_org_apply($org, isset($post['zwc_org_name']) ? (string) $post['zwc_org_name'] : '');
        if ($resolved['sign_org'] === '') {
            // 机关非法：不覆写，交由既有校验（名录校验）处理
            return $post;
        }
        $post['zwc_org_name']   = $resolved['org_name'];
        $post['zwc_sign_org']   = $resolved['sign_org'];
        $post['doc_department'] = $resolved['department'];

        return $post;
    }
}

if (!function_exists('zw_doc_composer_appoint_from_post')) {
    /**
     * TASK-1291（SPEC-87 §20.12）：由 POST 构建 `meta.appointments`（受控结构化 meta）。
     *
     * - `type` **服务端推导**：唯一权威来源 = 「已选发文机关」`zwc_org_pick`
     *   （`zw_doc_apply_org_pick()` 之后台账 `doc_department` 亦等于机关名 ⇒ 作为旧表单回退）；
     *   两套字段由 `zw_doc_appoint_encode()` 按 type **互斥裁剪**（构造 POST 塞不进两套）；
     * - **TASK-1312**：新增模块级单选 `zwc_ap_mode`（`branch` / `hq`），它是**服务端权威** ——
     *   决定字段集与流向；未提交时回落发文机关推导的 `type`（存量 / 无 JS 不回归）。
     *   因此「总会任命」可以由分支机构机关发出（发文机关与任命模式解耦）。
     * - 任命人员姓名**按 WP 用户 ID 服务端复核**：账号不存在 ⇒ 清 uid（不冒充账号，姓名仍可作提名）；
     *   账号存在 ⇒ 姓名以服务器侧为准（未实名只提示、不阻断，见用户拍板 Q11）；
     * - 无任命要素时返回空结构 JSON（`zw_doc_appoint_has_content()` 为 false ⇒ 全链路零输出）。
     *
     * @param array $post     已 wp_unslash 的提交数据。
     * @param array $fallback 缺省补位（`org_pick`）。
     * @return string JSON 字符串。
     */
    function zw_doc_composer_appoint_from_post($post, $fallback = array()) {
        $post     = is_array($post) ? $post : array();
        $fallback = is_array($fallback) ? $fallback : array();
        $get      = function ($key, $default = '') use ($post) {
            return isset($post[$key]) ? (string) $post[$key] : (string) $default;
        };

        $org = trim($get('zwc_org_pick', ''));
        if ($org === '') {
            $org = trim($get('doc_department', ''));
        }
        if ($org === '' && isset($fallback['org_pick'])) {
            $org = trim((string) $fallback['org_pick']);
        }

        // 编辑态防数据丢失：本次表单**完全没有**任命要素字段（未渲染 ②.5 的旧入口 /
        // 文种无法判定 / 无 JS 的降级表单）⇒ 原样保留已存结构，禁止用空结构覆盖。
        $has_ap_input = false;
        foreach (array('zwc_ap_scope', 'zwc_ap_uid', 'zwc_ap_dept', 'zwc_ap_item_org_dept', 'zwc_ap_item_term_start', 'zwc_ap_org_dept', 'zwc_ap_org_term_start') as $ap_key) {
            if (isset($post[$ap_key])) {
                $has_ap_input = true;
                break;
            }
        }
        if (!$has_ap_input && isset($fallback['appointments'])) {
            return zw_doc_appoint_encode($fallback['appointments']);
        }

        $uids     = (isset($post['zwc_ap_uid']) && is_array($post['zwc_ap_uid'])) ? array_values($post['zwc_ap_uid']) : array();
        $names    = (isset($post['zwc_ap_name']) && is_array($post['zwc_ap_name'])) ? array_values($post['zwc_ap_name']) : array();
        $depts    = (isset($post['zwc_ap_dept']) && is_array($post['zwc_ap_dept'])) ? array_values($post['zwc_ap_dept']) : array();
        $branches = (isset($post['zwc_ap_branch']) && is_array($post['zwc_ap_branch'])) ? array_values($post['zwc_ap_branch']) : array();
        $duties   = (isset($post['zwc_ap_duty']) && is_array($post['zwc_ap_duty'])) ? array_values($post['zwc_ap_duty']) : array();
        $others   = (isset($post['zwc_ap_duty_other']) && is_array($post['zwc_ap_duty_other'])) ? array_values($post['zwc_ap_duty_other']) : array();
        // TASK-1304：逐行字段（本级三件套 + 届次任期、分支逐行任期）—— 列名 → 行内键。
        $item_keys = array(
            'org_dept'       => 'zwc_ap_item_org_dept',
            'org_duty'       => 'zwc_ap_item_org_duty',
            'org_term_name'  => 'zwc_ap_item_org_term_name',
            'org_term_start' => 'zwc_ap_item_org_term_start',
            'org_term_end'   => 'zwc_ap_item_org_term_end',
            'term_start'     => 'zwc_ap_item_term_start',
            'term_end'       => 'zwc_ap_item_term_end',
        );
        $item_cols = array();
        foreach ($item_keys as $item_field => $post_key) {
            $item_cols[$item_field] = (isset($post[$post_key]) && is_array($post[$post_key])) ? array_values($post[$post_key]) : array();
        }

        $items = array();
        $total = max(count($uids), count($names), count($depts));
        for ($i = 0; $i < $total; $i++) {
            $uid = isset($uids[$i]) ? abs((int) $uids[$i]) : 0;
            if ($uid > 0 && function_exists('get_userdata') && !get_userdata($uid)) {
                $uid = 0; // 账号不存在：不冒充账号（姓名仍作提名文字）
            }
            $name = isset($names[$i]) ? (string) $names[$i] : '';
            if ($uid > 0 && function_exists('zw_doc_appoint_user_name')) {
                $resolved = zw_doc_appoint_user_name($uid);
                if ($resolved !== '') {
                    $name = $resolved;
                }
            }
            $item = array(
                'uid'        => $uid,
                'name'       => $name,
                'dept'       => isset($depts[$i]) ? (string) $depts[$i] : '',
                'branch'     => isset($branches[$i]) ? (string) $branches[$i] : '',
                'duty'       => isset($duties[$i]) ? (string) $duties[$i] : '',
                'duty_other' => isset($others[$i]) ? (string) $others[$i] : '',
            );
            foreach ($item_cols as $item_field => $col) {
                if (isset($col[$i]) && trim((string) $col[$i]) !== '') {
                    $item[$item_field] = (string) $col[$i];
                }
            }
            $items[] = $item;
        }

        if (!function_exists('zw_doc_appoint_encode')) {
            return '';
        }

        // TASK-1312：模块级单选（`总会任命` / `分支机构任命`）是**服务端权威**。
        // 空值 ⇒ 回落发文机关推导的 type（存量表单 / 无 JS 提交不回归）。
        $mode = isset($post['zwc_ap_mode']) ? (string) $post['zwc_ap_mode'] : '';

        return zw_doc_appoint_encode(array(
            'mode'           => $mode,
            'org'            => $org,
            'scope'          => $get('zwc_ap_scope', ''),
            'items'          => $items,
            'term_start'     => $get('zwc_ap_term_start', ''),
            'term_end'       => $get('zwc_ap_term_end', ''),
            'org_dept'       => $get('zwc_ap_org_dept', ''),
            'org_duty'       => $get('zwc_ap_org_duty', ''),
            'org_term_name'  => $get('zwc_ap_org_term_name', ''),
            'org_term_start' => $get('zwc_ap_org_term_start', ''),
            'org_term_end'   => $get('zwc_ap_org_term_end', ''),
        ));
    }
}

if (!function_exists('zw_doc_composer_body_from_post')) {
    /**
     * 由 POST 构建正文 AST（块数组 + 公文要素）。
     *
     * @param array $post 提交数据（通常为 $_POST，已由调用方 wp_unslash）。
     * @param array $fallback 缺省补位（如标题取 doc_title、日期由发文日期转换）。
     * @return array 归一后的 AST。
     */
    function zw_doc_composer_body_from_post($post, $fallback = array()) {
        $post     = is_array($post) ? $post : array();
        $fallback = is_array($fallback) ? $fallback : array();
        $get      = function ($key, $default = '') use ($post) {
            return isset($post[$key]) ? (string) $post[$key] : (string) $default;
        };

        $types = isset($post['zw_doc_block_type']) ? (array) $post['zw_doc_block_type'] : array();
        $texts = isset($post['zw_doc_block_text']) ? (array) $post['zw_doc_block_text'] : array();

        $blocks = array();
        foreach ($types as $index => $type) {
            $blocks[] = array(
                't'    => (string) $type,
                'text' => isset($texts[$index]) ? (string) $texts[$index] : '',
            );
        }

        $attach_raw = $get('zwc_attach_note', '');
        $attach     = array();
        foreach (preg_split('/\R/u', $attach_raw) as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $attach[] = $line;
            }
        }

        // TASK-1263：第 1 步成文日期改用标准日期控件，提交值为 ISO → 落库前转中文（月、日不补零）。
        // 非法/空值回落 fallback；已是中文的旧值原样保留（旧记录走文本回退控件，不被重置）。
        $date = $get('zwc_date', '');
        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $date)) {
            $date = zw_doc_composer_cn_date($date);
        }
        if ($date === '') {
            $date = isset($fallback['date']) ? (string) $fallback['date'] : '';
        }
        $org = $get('zwc_org_name', '');
        if ($org === '') {
            $org = isset($fallback['org_name']) ? (string) $fallback['org_name'] : '';
        }
        $sign = $get('zwc_sign_org', '');
        if ($sign === '') {
            $sign = isset($fallback['sign_org']) ? (string) $fallback['sign_org'] : $org;
        }
        // TASK-1280（CHG-157）：署名归一 —— 分支机构补协会全称前缀（存量机构纯名 / 「机构+文件」都能补正；
        // 无法识别为名录机关的**自由文本原样保留**，不臆造、不丢数据）。
        if ($sign !== '' && function_exists('zw_doc_sign_org_normalize')) {
            $sign = zw_doc_sign_org_normalize($sign);
        }
        $title = $get('doc_title', '');
        if ($title === '') {
            $title = isset($fallback['title']) ? (string) $fallback['title'] : '';
        }

        // TASK-1276（CHG-155）：版头标记三要素写入（白名单键 `copy_no` / `secret` / `urgency` 早已存在于
        // `zw_doc_body_meta_keys()`，本任务**不新增 meta 键、不改白名单**）。
        // 三者都不走 `zw_doc_composer_receiver_*` 之类的 20 字上限逻辑（该上限只属于主送机关）。
        // TASK-1867（CHG-362）：份号取号方式（`auto` / `manual` / `none`）—— `auto` 的**真实号**由
        // 保存处理器占号后回填（本函数保持**纯函数**，不碰库）；`none` 一律空串。
        $copy_mode = isset($post['zwc_copy_no_mode']) ? sanitize_key((string) $post['zwc_copy_no_mode']) : '';
        $copy_no   = '';
        if (!in_array($copy_mode, array('auto', 'none'), true)) {
            $copy_no = zw_doc_composer_copy_no($get('zwc_copy_no', ''));
        }
        $secret  = zw_doc_composer_secret_build($get('zwc_secret_level', ''), $get('zwc_secret_period', ''));
        $urgency = zw_doc_composer_urgency($get('zwc_urgency', ''));

        // TASK-1291（SPEC-87 §20.12）：②.5 人员任命配置 —— 收 POST、服务端推导 type、结构清洗。
        $appointments = function_exists('zw_doc_composer_appoint_from_post')
            ? (string) zw_doc_composer_appoint_from_post($post, $fallback)
            : '';

        // TASK-1338 批次二 ⑦（D1 版记合并）：印发机关 / 印发日期以**公文要素为唯一真值源** ——
        // 印发机关 ← 发文部门，印发日期 ← 发文日期（`issue_date`）。第 3 步不再单独录入（只读回显），
        // 表单若仍带回显值则优先保留（**存量公文已显式保存的印发值不覆盖**，与「存量不动」一致）。
        $dept_value = $get('doc_department', '');
        if (trim($dept_value) === '' && isset($fallback['department'])) {
            $dept_value = (string) $fallback['department'];
        }
        $issue_value = $get('doc_issue_date', '');
        if (trim($issue_value) === '' && isset($fallback['issue_date'])) {
            $issue_value = (string) $fallback['issue_date'];
        }
        if (function_exists('zw_doc_record_print_fields')) {
            $print_fields = zw_doc_record_print_fields(
                array(
                    'print_org'  => $get('zwc_print_org', ''),
                    'print_date' => $get('zwc_print_date', ''),
                ),
                $dept_value,
                $issue_value
            );
        } else {
            $print_fields = array(
                'print_org'  => $get('zwc_print_org', ''),
                'print_date' => $get('zwc_print_date', ''),
            );
        }

        $body = zw_doc_body_normalize(array(
            'v'      => 1,
            'meta'   => array(
                'org_name'    => $org,
                'title'       => $title,
                // TASK-1263：主送机关统一补末尾全角冒号（SPEC-87 §6）；20 字上限由调用方按
                // 「新建无条件 / 编辑仅在值变化时」施加，避免编辑旧记录时截断超长的存量值。
                'receiver'    => zw_doc_composer_receiver_normalize($get('zwc_receiver', '')),
                'sign_org'    => $sign,
                'date'        => $date,
                'note'        => $get('zwc_note', ''),
                'cc'          => $get('zwc_cc', ''),
                'print_org'   => (string) $print_fields['print_org'],
                'print_date'  => (string) $print_fields['print_date'],
                'page_mode'   => $get('zwc_page_mode', 'standard'),
                'attach_note' => $attach,
                'copy_no'     => $copy_no,
                'secret'      => $secret,
                'urgency'     => $urgency,
                // TASK-1291：受控结构化 meta（专用分支编码；**不得**走纯文本清洗/截断）。
                'appointments' => $appointments,
            ),
            'blocks' => $blocks,
        ));

        // TASK-1291 服务端兜底：`appointments` 非空而正文为空 ⇒ 补入四段模板（幂等：正文已有内容时不动）。
        if (function_exists('zw_doc_appoint_has_content') && zw_doc_appoint_has_content($appointments)) {
            $has_text = false;
            foreach ($body['blocks'] as $block_row) {
                if (isset($block_row['text']) && trim((string) $block_row['text']) !== '') {
                    $has_text = true;
                    break;
                }
            }
            if (!$has_text && function_exists('zw_doc_appoint_body_texts')) {
                $lines = (array) zw_doc_appoint_body_texts($appointments);
                if (!empty($lines)) {
                    $body['blocks'] = array();
                    foreach ($lines as $line_text) {
                        $body['blocks'][] = array('t' => 'p', 'text' => (string) $line_text);
                    }
                    $body = zw_doc_body_normalize($body); // 再归一：块上限/清洗口径仍以正文模型为唯一出处
                }
            }
        }

        return $body;
    }
}

if (!function_exists('zw_doc_composer_body_from_doc')) {
    /**
     * 由已保存发文构建编辑用 AST（无正文时返回带要素的空壳）。
     *
     * @param array $doc 发文记录。
     * @return array
     */
    function zw_doc_composer_body_from_doc($doc) {
        $doc = is_array($doc) ? $doc : array();
        if (!empty($doc['body']) && is_array($doc['body'])) {
            $body = $doc['body'];
            // TASK-1280（CHG-157）：编辑态回填 —— 存量 `sign_org` 可能是 CHG-157 之前的机构纯名
            // （如「孔子文化传承工作委员会」）⇒ 按新口径补协会全称前缀后再回显/再保存。
            if (isset($body['meta']) && is_array($body['meta'])
                && function_exists('zw_doc_sign_org_normalize')) {
                $body['meta']['sign_org'] = zw_doc_sign_org_normalize(
                    isset($body['meta']['sign_org']) ? (string) $body['meta']['sign_org'] : ''
                );
            }

            return $body;
        }
        $body = zw_doc_body_empty(array(
            'org_name' => '中国传统文化促进会',
            'title'    => isset($doc['title']) ? (string) $doc['title'] : '',
            'date'     => function_exists('zw_doc_composer_cn_date') ? zw_doc_composer_cn_date(isset($doc['issue_date']) ? $doc['issue_date'] : '') : '',
        ));
        if (!empty($doc['department'])) {
            // TASK-1280（CHG-157）：台账回退 —— 部门为分支机构时**同样加协会全称前缀**（署名口径）。
            $body['meta']['sign_org'] = function_exists('zw_doc_sign_org')
                ? zw_doc_sign_org((string) $doc['department'])
                : (string) $doc['department'];
        }

        return $body;
    }
}

if (!function_exists('zw_doc_composer_block_labels')) {
    /**
     * 块类型展示名（含国标序数提示）。
     *
     * @return array
     */
    function zw_doc_composer_block_labels() {
        return array(
            'p'  => '正文段',
            'h1' => '一级标题（一、）',
            'h2' => '二级标题（（一））',
            'h3' => '三级标题（1.）',
            'h4' => '四级标题（（1））',
        );
    }
}

if (!function_exists('zw_doc_composer_appoint_row_html')) {
    /**
     * TASK-1291 / TASK-1304 / TASK-1312（SPEC-87 §20.12）：任命人员**一行**的 HTML
     * （PHP 与前端共用同一份，防两套行模板漂移）。
     *
     * `$index` 为字符串占位（服务端行传真实序号；前端行模板传 `zwc-ap-new`，
     * 由 `zwcApAdd()` 全局替换为唯一序号 —— `zw_control_search_select()` 的 id 会过
     * `sanitize_key()`，故占位符必须是小写字母数字短横）。
     *
     * TASK-1312：列集与顺序由**模块级模式**（`$args['mode']`）决定，同篇不再混选：
     * - 分支机构任命（默认）：任命人员 → 拟任命部门 * → **会内职务 \*** →
     *   **任期开始 / 任期结束**（开始默认当前日期，1/2/3 年快捷）；
     * - 总会任命：**隐藏「拟任命部门」列**（统一为中国传统文化促进会）→
     *   **所属部门 \* → 组织架构职务 \* → 届次（只读）→ 任期（只读）**；
     *   这四项是**模块级单值**，其真实控件在表格外的模块区（`#zwc-ap-hq-*`），
     *   行内以同名隐藏域承接**同一份值**（服务端仍按行归一，保持既有两个同步通道不变）。
     * 所有控件均为 canonical：拟任命部门与人员选择复用 `zw_control_search_select()`。
     *
     * `hidden` 只施加在**容器**（字段行 / 单元格）上、绝不施加在表单控件上 ——
     * 容器隐藏不影响其内控件参与表单提交，故模式切换与往返保存都**不丢数据**。
     *
     * TASK-1322（用户口径）：**逐行人员区由「表格 + 表头列」改为「堆叠分区布局」** ——
     * 每人一块（`.zwc-ap-row`），块内按行分组（`.zwc-ap-line`），字段带自己的 label
     * （改造后没有表头）。第 1 行 = 任命人员 80% ｜ 操作 20%（两种模式都显示）；
     * 第 2 行 = 拟任命部门 50% ｜ 会内职务 50%；第 3 行 = 任期开始 40% ｜ 任期结束 40% ｜
     * 任期快捷 20%（第 2/3 行仅分支机构任命显示，整行带 `is-branch` / `is-hq` 供脚本切换）。
     * 类名 / POST 名 / ID 逐一不变（可新增不可改名）。
     *
     * @param string $index 行序号或占位符。
     * @param array  $row   既有行值（uid/name/dept/branch/duty/duty_other 及本级 `org_term_*`）。
     * @param array  $args  mode / type / role_options / dept_options / org_dept_options /
     *                      org_duty_options / term_default / remote。
     * @return string HTML（`.zwc-ap-row` 块）。
     */
    function zw_doc_composer_appoint_row_html($index, $row = array(), $args = array()) {
        $index = (string) $index;
        $row   = is_array($row) ? $row : array();
        $args  = is_array($args) ? $args : array();
        $type  = (isset($args['type']) && $args['type'] === 'hq') ? 'hq' : 'branch';
        // TASK-1312：模块级模式（权威）；缺省回落 `type`（存量调用点不变）。
        $mode  = isset($args['mode']) && in_array($args['mode'], array('branch', 'hq'), true)
            ? (string) $args['mode']
            : $type;
        $role_options     = isset($args['role_options']) ? (array) $args['role_options'] : array();
        $dept_options     = isset($args['dept_options']) ? (array) $args['dept_options'] : array();
        $org_dept_options = isset($args['org_dept_options']) ? (array) $args['org_dept_options'] : array();
        $org_duty_options = isset($args['org_duty_options']) ? (array) $args['org_duty_options'] : array();
        $term_default     = array_merge(
            array('term' => '', 'start' => '', 'end' => ''),
            isset($args['term_default']) && is_array($args['term_default']) ? $args['term_default'] : array()
        );
        $remote           = isset($args['remote']) ? (array) $args['remote'] : array();

        $uid        = isset($row['uid']) ? (string) $row['uid'] : '';
        $name       = isset($row['name']) ? (string) $row['name'] : '';
        $dept       = isset($row['dept']) ? (string) $row['dept'] : '';
        $duty       = isset($row['duty']) ? (string) $row['duty'] : '';
        $duty_other = isset($row['duty_other']) ? (string) $row['duty_other'] : '';
        if ($mode === 'hq') {
            // 总会任命：拟任命部门统一协会本级（模块级单值，不逐人选）。
            $dept = function_exists('zw_doc_appoint_org_head_name') ? zw_doc_appoint_org_head_name() : $dept;
        }
        $row_role   = function_exists('zw_doc_appoint_item_role')
            ? zw_doc_appoint_item_role($row, $mode)
            : $mode;
        if ($row_role === '') {
            $row_role = $mode;
        }
        $is_hq = ($mode === 'hq');

        $org_dept  = isset($row['org_dept']) && (string) $row['org_dept'] !== ''
            ? (string) $row['org_dept']
            : (isset($args['org_dept_default']) ? (string) $args['org_dept_default'] : '');
        $org_duty  = isset($row['org_duty']) && (string) $row['org_duty'] !== ''
            ? (string) $row['org_duty']
            : (isset($args['org_duty_default']) ? (string) $args['org_duty_default'] : '');
        if ($is_hq) {
            // 总会任命下这四项是**模块级单值**：行内隐藏域一律承接模块区真值，
            // 不允许存量逐行差异把模块值带偏（同一篇只有一个所属部门 / 职务 / 届次 / 任期）。
            $org_dept = isset($args['org_dept_default']) ? (string) $args['org_dept_default'] : $org_dept;
            $org_duty = isset($args['org_duty_default']) ? (string) $args['org_duty_default'] : $org_duty;
        }
        // 届次与任期（本级**只读**）：唯一出处 `zw_term_cycles()` 的当前届次起止。
        $org_term  = (string) $term_default['term'];
        $org_start = (string) $term_default['start'];
        $org_end   = (string) $term_default['end'];
        // 分支逐行任期：行内优先，其次模块级回落（存量）；新建行由 JS 默认当前日期。
        // 总会任命下该列隐藏但**控件仍在 DOM 内**（随表单提交）⇒ 切模式往返不丢数据。
        $term_start = isset($row['term_start']) && (string) $row['term_start'] !== ''
            ? (string) $row['term_start']
            : (isset($args['branch_term_start']) ? (string) $args['branch_term_start'] : '');
        $term_end = isset($row['term_end']) && (string) $row['term_end'] !== ''
            ? (string) $row['term_end']
            : (isset($args['branch_term_end']) ? (string) $args['branch_term_end'] : '');
        // TASK-1319（用户口径）：**分支机构任命**的任期默认填充 —— 开始 = 今天，结束 = 开始 + 1 年 − 1 天。
        // 仅在**分支行**且两值皆缺时套用（存量公文已有值不动；总会任命行不写这项）。
        if ($row_role !== 'hq' && ($term_start === '' || $term_end === '')) {
            if (function_exists('zw_doc_appoint_term_default_range')) {
                $term_range = zw_doc_appoint_term_default_range($term_start, 1);
                if ($term_start === '') {
                    $term_start = (string) $term_range['start'];
                }
                if ($term_end === '') {
                    $term_end = (string) $term_range['end'];
                }
            }
        }

        ob_start();
        ?>
        <div class="zwc-ap-row" data-zw-ap-index="<?php echo esc_attr($index); ?>" data-zw-ap-role="<?php echo esc_attr($row_role); ?>">
            <?php
            // TASK-1346（用户口径）：行级「操作」（删除该行）由第 1 行内**移到本区块右上角**，
            // 形态对齐 Windows 资源管理器右上角的关闭按钮 —— 因此**不带可见文字标签**，
            // 语义由 `title` + `aria-label` 承担。`.zwc-ap-cell.is-ops` 类**保留**（SPEC-87 §20.12.8
            // 「类名不得改名、可新增」的契约），另加 `zwc-ap-row-ops` 承载绝对定位；
            // 删除按钮仍在本块内 ⇒ JS `closest('.zwc-ap-row')` 语义不变。
            ?>
            <div class="zwc-ap-cell is-ops zwc-ap-row-ops">
                <button type="button" class="button button-small zwc-ap-del" onclick="zwcApRemove(this)" title="删除该行" aria-label="删除该行">×</button>
            </div>
            <?php // 第 1 行（两种模式都显示）：任命人员占满整行（「操作」已移出流，见本块右上角）。 ?>
            <div class="zwc-ap-line is-person-line">
                <div class="zwc-ap-cell is-person is-name" style="width:100%">
                    <span class="zwc-ap-label">任命人员 *</span>
                    <?php
                    // 刻意**不传** `required`：canonical 控件的 required 会在**任何** form submit 时
                    // 无条件 preventDefault（含「保存草稿」与「预览当前内容」，且不区分显隐与文种），
                    // 会让「任」字发文无法先存草稿、也无法预览，并阻断历史「任」字发文的保存。
                    // 必填语义改由服务端 `zw_doc_appoint_problems()`（权威）承担。
                    zw_control_search_select(array(
                        'id'            => 'zwc-ap-uid-' . $index,
                        'name'          => 'zwc_ap_uid[]',
                        'value'         => $uid,
                        'selected_label' => $name,
                        'placeholder'   => '输入姓名 / 用户名 / 实名 / 手机号 / UID（≥2 字）',
                        'empty_text'    => '没有匹配的用户；可换关键词，或直接用 UID 搜索',
                        'options'       => array(),
                        'search_url'    => isset($remote['url']) ? (string) $remote['url'] : '',
                        'search_action' => isset($remote['action']) ? (string) $remote['action'] : '',
                        'search_nonce'  => isset($remote['nonce']) ? (string) $remote['nonce'] : '',
                        'min_chars'     => 2,
                        'hint_text'     => '请输入至少 2 个字（支持姓名、用户名、实名、手机号、UID）',
                        'loading_text'  => '搜索中…',
                    ));
                    ?>
                    <input type="text" class="zwc-ap-name" name="zwc_ap_name[]" readonly
                           value="<?php echo esc_attr($name); ?>" placeholder="选定人员后自动回显姓名">
                </div>
            </div>
            <?php
            // 第 2 行（仅分支机构任命显示）：拟任命部门 50% ｜ 会内职务 50%。
            // 总会模式下整行带 `hidden`（服务端首屏即正确），且脚本按模式切换 `.zwc-ap-line.is-branch` 的显隐；
            // 隐藏行内的控件仍在 DOM 内并随表单提交 ⇒ 模式切换往返不丢数据。
            ?>
            <div class="zwc-ap-line is-branch" data-zw-ap-col="branch"<?php echo $is_hq ? ' hidden' : ''; ?>>
                <div class="zwc-ap-cell is-dept" style="width:50%">
                    <span class="zwc-ap-label">拟任命部门 *</span>
                    <?php
                    // 拟任命部门：与**发文机关同一出处**（`zw_doc_department_names()`，首项为协会本级）；
                    // 复用 canonical 可搜索单选（SPEC-85），前端**不自建控件**。
                    // 总会模式下该行隐藏，但控件仍在 DOM 内并随表单提交 ⇒ 存量行数据不丢。
                    zw_control_search_select(array(
                        'id'          => 'zwc-ap-dept-' . $index,
                        'name'        => 'zwc_ap_dept[]',
                        'value'       => $dept,
                        'placeholder' => '输入关键词搜索拟任命部门',
                        'empty_text'  => '没有匹配的部门',
                        'options'     => $dept_options,
                    ));
                    ?>
                </div>
                <?php // 会内职务（分支模式）；总会模式整行隐藏 —— 总会为「组织架构职务」 ?>
                <div class="zwc-ap-cell is-duty" style="width:50%">
                    <span class="zwc-ap-label">会内职务 *</span>
                    <select name="zwc_ap_duty[]" class="zwc-ap-duty">
                        <option value="">请选择</option>
                        <?php foreach ($role_options as $role_key => $role_label) : ?>
                            <option value="<?php echo esc_attr((string) $role_key); ?>"<?php selected($duty, (string) $role_key); ?>><?php echo esc_html((string) $role_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" class="zwc-ap-duty-other" name="zwc_ap_duty_other[]" maxlength="30"
                           value="<?php echo esc_attr($duty_other); ?>" placeholder="「其他」职务原文"
                           <?php echo $duty === 'other' ? '' : 'hidden'; ?>>
                </div>
            </div>
            <?php
            // 第 3 行（仅分支机构任命显示）：任期开始 40% ｜ 任期结束 40% ｜ 任期快捷 20%。
            // 总会任命的「所属部门 / 组织架构职务 / 届次」是**模块级单值**：行内只保留同名隐藏域承接模块区真值
            // （服务端按行归一，两条通道不变）；非总会模式一律 `disabled`（不提交）⇒ 绝不会把分支模式的数据串成本级字段。
            $ap_dis_hq = $is_hq ? '' : ' disabled';
            ?>
            <div class="zwc-ap-line is-branch" data-zw-ap-col="branch"<?php echo $is_hq ? ' hidden' : ''; ?>>
                <div class="zwc-ap-cell is-term is-term-start" style="width:40%">
                    <span class="zwc-ap-label">任期开始 *</span>
                    <input type="date" class="zwc-ap-term-start" name="zwc_ap_item_term_start[]"
                           value="<?php echo esc_attr($term_start); ?>" title="分支机构任命：任期开始（默认当前日期）">
                    <input type="hidden" class="zwc-ap-hq-term-start" name="zwc_ap_item_org_term_start[]"
                           value="<?php echo esc_attr($org_start); ?>"<?php echo $ap_dis_hq; ?>>
                </div>
                <div class="zwc-ap-cell is-term is-term-end" style="width:40%">
                    <span class="zwc-ap-label">任期结束</span>
                    <input type="date" class="zwc-ap-term-end" name="zwc_ap_item_term_end[]"
                           value="<?php echo esc_attr($term_end); ?>" title="分支机构任命：任期结束">
                    <input type="hidden" class="zwc-ap-hq-term-end" name="zwc_ap_item_org_term_end[]"
                           value="<?php echo esc_attr($org_end); ?>"<?php echo $ap_dis_hq; ?>>
                </div>
                <div class="zwc-ap-cell is-term is-term-quick" style="width:20%">
                    <span class="zwc-ap-label">任期快捷</span>
                    <span class="zwc-ap-quick">
                        <button type="button" class="button button-small" data-zw-ap-term="1">1 年</button>
                        <button type="button" class="button button-small" data-zw-ap-term="2">2 年</button>
                        <button type="button" class="button button-small" data-zw-ap-term="3">3 年</button>
                    </span>
                </div>
            </div>
            <?php // 总会任命的三个模块级单值隐藏域：不显示、不以单元格承载，仍随表单提交（每行一份）。 ?>
            <div class="zwc-ap-hq-inline" hidden>
                <input type="hidden" name="zwc_ap_item_org_dept[]" class="zwc-ap-item-org-dept" value="<?php echo esc_attr($org_dept); ?>"<?php echo $ap_dis_hq; ?>>
                <input type="hidden" name="zwc_ap_item_org_duty[]" class="zwc-ap-item-org-duty" value="<?php echo esc_attr($org_duty); ?>"<?php echo $ap_dis_hq; ?>>
                <input type="hidden" name="zwc_ap_item_org_term_name[]" class="zwc-ap-item-org-term" value="<?php echo esc_attr($org_term); ?>"<?php echo $ap_dis_hq; ?>>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}

if (!function_exists('zw_doc_render_composer_page')) {
    /**
     * 撰写页（新建 / 编辑草稿）。
     *
     * @param int $doc_id 编辑已有发文时的 ID；0 = 新建。
     * @return void
     */
    function zw_doc_render_composer_page($doc_id = 0) {
        $doc_id   = (int) $doc_id;
        $settings = zw_doc_number_settings();
        $flow     = zw_doc_flow_settings();
        // TASK-1345（批次四 D2/D4）：审核人 / 抄送人候选来源 = 「公文流转配置」按组织架构部门取人
        // （未绑 uid 的组织人员也入列）；未配置部门时逐字沿用历史「管理员集合」口径。
        $reviewer_candidates = zw_doc_flow_candidates('reviewer');
        $cc_candidates       = zw_doc_flow_candidates('cc');
        $current  = get_current_user_id();
        $notice   = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';

        $doc = null;
        if ($doc_id > 0) {
            $doc = zw_doc_get_document($doc_id);
            if (!$doc) {
                echo '<div class="wrap"><h1>在线撰写</h1><div class="notice notice-error"><p>发文不存在。</p></div></div>';
                return;
            }
            if (!zw_doc_can_edit_doc($doc)) {
                echo '<div class="wrap"><h1>在线撰写</h1><div class="notice notice-error"><p>当前状态不可编辑（已用印锁定，或你不是作者）。</p></div></div>';
                return;
            }
        }

        $body     = zw_doc_composer_body_from_doc($doc ? $doc : array());
        $year     = (int) $settings['default_year'];
        $type     = isset($_GET['num_type']) ? sanitize_text_field(wp_unslash($_GET['num_type'])) : (string) $settings['doc_types'][0];
        $type     = zw_doc_validate_doc_type($type, $settings);
        if (!$type) {
            $type = (string) $settings['doc_types'][0];
        }
        $preview  = $doc ? null : zw_doc_get_next_preview($year, $type);
        $reusable = $doc ? array() : zw_doc_get_reusable_numbers($year, $type);
        $blocks   = isset($body['blocks']) && is_array($body['blocks']) ? $body['blocks'] : array();
        if (empty($blocks)) {
            $blocks = array(array('t' => 'p', 'text' => ''));
        }
        $labels   = zw_doc_composer_block_labels();
        $page_mode = isset($body['meta']['page_mode']) ? (string) $body['meta']['page_mode'] : 'standard';

        // ── TASK-1276（CHG-155）：版头标记三要素回填口径 ─────────────────
        // 份号：存量值原样回填（写入侧统一左补 0，见 `zw_doc_composer_copy_no()`）。
        $copy_no_raw   = isset($body['meta']['copy_no']) ? (string) $body['meta']['copy_no'] : '';
        // TASK-1867（CHG-362）：份号取号方式 —— 新建默认「自动取号」；编辑已有份号默认「手动指定」
        // （回填现值，改值即换号），避免一次无关保存把人工份号悄悄改成下一个流水号。
        $copy_mode_posted = isset($_POST['zwc_copy_no_mode']) ? sanitize_key(wp_unslash($_POST['zwc_copy_no_mode'])) : '';
        $copy_mode        = in_array($copy_mode_posted, array('auto', 'manual', 'none'), true)
            ? $copy_mode_posted
            : ($copy_no_raw !== '' ? 'manual' : 'auto');
        $copy_next        = function_exists('zw_doc_copy_number_next_free') ? (string) zw_doc_copy_number_next_free() : '';
        $copy_pool        = function_exists('zw_doc_copy_number_free_choices') ? (array) zw_doc_copy_number_free_choices(20) : array();
        // 自动取号时输入框只作「下一号」的可视化预览（服务端以占号结果为准）。
        $copy_input_value = ($copy_mode === 'auto' && $copy_no_raw === '') ? $copy_next : $copy_no_raw;
        // 密级和保密期限：`secret` 反解析为「密级 + 期限」；**不在字典内的历史值不得丢**——
        // 优先作为额外 `<option>` 回填；若历史值本身含 `<` / `>` 等会破坏标记的字符，
        // 则回退为文本框原样保留（与「旧成文日期回退文本框」同一取舍）。
        $secret_raw    = isset($body['meta']['secret']) ? (string) $body['meta']['secret'] : '';
        $secret_split  = zw_doc_composer_secret_parse($secret_raw);
        $secret_level  = (string) $secret_split['level'];
        $secret_period = (string) $secret_split['period'];
        $secret_levels = array_map('strval', (array) zw_doc_gb9704_get('header.secret_levels', array()));
        $secret_legacy = ($secret_level !== '' && !in_array($secret_level, $secret_levels, true));
        $secret_unsafe = (strpos($secret_level, '<') !== false || strpos($secret_level, '>') !== false);
        if ($secret_legacy && !$secret_unsafe) {
            $secret_levels[] = $secret_level; // 历史值作为额外选项保留（不丢数据）
        }
        // 字典内取值的判据必须在**追加历史值之前**取（否则追加项会被误判为字典内）。
        $secret_levels_base = array_map('strval', (array) zw_doc_gb9704_get('header.secret_levels', array()));
        // 紧急程度：仅字典内取值可回填（非法历史值按写入侧同一口径丢弃，由「取值 = 特急 / 加急」约束）。
        $urgency_levels = array_map('strval', (array) zw_doc_gb9704_get('header.urgency_levels', array()));
        $urgency_raw    = isset($body['meta']['urgency']) ? (string) $body['meta']['urgency'] : '';
        $urgency_pick   = zw_doc_composer_urgency($urgency_raw);

        // ── TASK-1263：第 1 步三处录入口径 ───────────────────────────────
        // ① 主送机关：下拉单选（数据字典）+ 末项「其他」自由输入；存量非字典值自动落到「其他」。
        $receiver_options  = zw_doc_composer_receiver_options();
        $receiver_other    = zw_doc_composer_receiver_other_value();
        $receiver_raw      = (string) $body['meta']['receiver'];
        $receiver_text     = zw_doc_composer_receiver_strip_colon($receiver_raw);
        $receiver_is_other = ($receiver_text !== '' && !in_array($receiver_text, $receiver_options, true));
        // ② 成文日期：标准日期控件，新建默认当天；旧记录里无法解析的文本日期回退为文本框（不重置、不丢数据）。
        $date_raw    = (string) $body['meta']['date'];
        $date_iso    = zw_doc_composer_iso_date($date_raw);
        $date_legacy = ($date_raw !== '' && $date_iso === '');
        if (!$date_legacy && $date_iso === '') {
            $date_iso = (string) current_time('Y-m-d');
        }
        // ③ 发文机关 A1 口径（TASK-1271：一处选择、三处回显）：
        //    机关推定顺序 = 署名（若在名录内）→ 由红头反查 → 新建时默认协会本级；
        //    红头候选固定两项：「… + 文件」（默认）与不带「文件」项；**分支机构两项都带协会全称前缀**
        //    （TASK-1278：如 `中国传统文化促进会孔子文化传承工作委员会文件`），唯一出处 `zw_doc_org_head_options()`。
        $org_names   = function_exists('zw_doc_department_names') ? zw_doc_department_names() : array();
        $org_head    = (string) $body['meta']['org_name'];
        $org_sign    = (string) $body['meta']['sign_org'];
        $org_current = '';
        if ($org_sign !== '' && in_array($org_sign, $org_names, true)) {
            $org_current = $org_sign;
        } elseif ($org_head !== '' && function_exists('zw_doc_head_to_org')) {
            $org_current = (string) zw_doc_head_to_org($org_head);
        }
        if ($org_current === '' && !$doc && function_exists('zw_doc_department_head_name')) {
            $org_current = (string) zw_doc_department_head_name();
        }
        // TASK-1280（CHG-157）：署名**只读回显**默认值也走署名口径 ——
        // 分支机构必须显示「中国传统文化促进会 + 机构名」（用户报缺陷：落款缺协会全称前缀）。
        // 存量已存值（含无法识别为名录机关的自由文本）经 `zw_doc_sign_org_normalize()` 补正后优先。
        $org_sign_display = $org_sign !== ''
            ? (function_exists('zw_doc_sign_org_normalize') ? zw_doc_sign_org_normalize($org_sign) : $org_sign)
            : (function_exists('zw_doc_sign_org') ? zw_doc_sign_org($org_current) : $org_current);
        $org_head_options = function_exists('zw_doc_org_head_options') ? zw_doc_org_head_options($org_current) : array();
        $org_head_current = in_array($org_head, $org_head_options, true)
            ? $org_head
            : (function_exists('zw_doc_org_to_head') ? (string) zw_doc_org_to_head($org_current) : '');
        // 机关 → 红头候选映射（前端切换机关时重建红头选项，唯一出处仍是 PHP 侧字典函数）
        $org_head_map = array();
        foreach ($org_names as $org_name_key) {
            $org_head_map[$org_name_key] = function_exists('zw_doc_org_head_options') ? zw_doc_org_head_options($org_name_key) : array();
        }
        // TASK-1338 批次二 ⑦（D1 版记合并）：版记「印发机关 / 印发日期」第 3 步**只读回显** ——
        // 真值源 = 公文要素的发文部门 / 发文日期；存量已显式保存的印发值优先（与「存量不动」一致）。
        // 取值经 `zw_doc_record_print_fields()` 单一实现，与 HTML / DOCX 两通道取同一口径。
        $record_dept  = $doc ? (string) $doc['department'] : (string) $org_current;
        $record_issue = $doc ? (string) $doc['issue_date'] : '';
        if (function_exists('zw_doc_record_print_fields')) {
            $record_print = zw_doc_record_print_fields($body['meta'], $record_dept, $record_issue);
        } else {
            $record_print = array(
                'print_org'  => (string) $body['meta']['print_org'],
                'print_date' => (string) $body['meta']['print_date'],
            );
        }
        // TASK-1272：发文机关选择器的 canonical 控件候选（SPEC-85 search-select 本地模式格式）
        $org_select_options = array();
        foreach ($org_names as $org_name_key) {
            $org_select_options[] = array('value' => $org_name_key, 'label' => $org_name_key);
        }

        // ── TASK-1350（用户拍板）：「允许编辑流转字段」—— 编辑态第 4 步是否开放 ──────────
        // 权限矩阵唯一出处 `zw_doc_flow_can_edit()`：draft/rejected/cancelled = 作者+管理员；
        // `pending` = **仅管理员**（作者改派＝换掉自己的审核人，属利益冲突）；`approved` 不允许；
        // **已用印锁定一律禁止（含管理员）**。新建态恒为可编辑（取号 + 首次指派）。
        $flow_editable = $doc ? zw_doc_flow_can_edit($doc) : true;

        // ── TASK-1291（SPEC-87 §20.12）：「任」字文种 ②.5 人员任命配置 ─────────────
        // 仅「任」字文种渲染；`type` 由已选发文机关**服务端推导**（前端不单独提交 type）。
        // 两套字段（branch / hq）按 type **互斥**：渲染层互斥显隐，服务端 `zw_doc_appoint_encode()` 再裁一次。
        // 编辑态：链接（「我的发文 → 编辑正文」）不带 num_type ⇒ 按已占文号反推文种，
        // 否则编辑「任」字发文时本组不渲染、且保存会把任命要素覆盖为空（数据丢失）。
        $ap_doc_type = $type;
        if ($doc && !isset($_GET['num_type']) && function_exists('zw_doc_appoint_doc_type_from_number')) {
            $ap_number_type = zw_doc_appoint_doc_type_from_number(isset($doc['number']) ? (string) $doc['number'] : '');
            if ($ap_number_type !== '') {
                $ap_doc_type = $ap_number_type;
            }
        }
        $ap_active   = function_exists('zw_doc_appoint_is_appoint_type') && zw_doc_appoint_is_appoint_type($ap_doc_type);
        $ap_org_type = ($ap_active && function_exists('zw_doc_appoint_type_from_org'))
            ? (string) zw_doc_appoint_type_from_org($org_current)
            : '';
        $ap_meta     = ($ap_active && function_exists('zw_doc_appoint_decode'))
            ? zw_doc_appoint_decode(isset($body['meta']['appointments']) ? $body['meta']['appointments'] : array())
            : array();
        // TASK-1312：模块级单选（`总会任命` / `分支机构任命`）**默认分支机构任命**；
        // 存量公文按已存口径回显（有 `mode` 用 `mode`，更早的公文用 `type`）——
        // 否则「旧总会任命公文」会被默认值强制切到分支模式（数据被改写）。
        $ap_stored_mode = ($ap_active && function_exists('zw_doc_appoint_mode_of') && is_array($ap_meta) && !empty($ap_meta))
            ? (string) zw_doc_appoint_mode_of($ap_meta, (string) (isset($ap_meta['type']) ? $ap_meta['type'] : ''))
            : '';
        $ap_mode = ($ap_stored_mode !== '') ? $ap_stored_mode : 'branch';
        if ($ap_active && !in_array($ap_mode, array('branch', 'hq'), true)) {
            $ap_mode = 'branch';
        }
        // 发文机关推导的 type 仍保留为**未提交模式时的回落**（无 JS / 存量表单）。
        $ap_type  = $ap_org_type;
        $ap_is_hq = ($ap_mode === 'hq');
        $ap_scope      = isset($ap_meta['scope']) ? (string) $ap_meta['scope'] : '';
        $ap_items      = (isset($ap_meta['items']) && is_array($ap_meta['items'])) ? array_values($ap_meta['items']) : array();
        // 存量是否有实质任命要素：决定「工作范围」是否带原生 required（不阻断历史「任」字发文的保存/预览）。
        $ap_has_content = ($ap_active && function_exists('zw_doc_appoint_has_content'))
            ? zw_doc_appoint_has_content(isset($body['meta']['appointments']) ? $body['meta']['appointments'] : '')
            : false;
        $ap_term_start = isset($ap_meta['term_start']) ? (string) $ap_meta['term_start'] : '';
        $ap_term_end   = isset($ap_meta['term_end']) ? (string) $ap_meta['term_end'] : '';
        $ap_org_dept   = isset($ap_meta['org_dept']) ? (string) $ap_meta['org_dept'] : '';
        $ap_org_duty   = isset($ap_meta['org_duty']) ? (string) $ap_meta['org_duty'] : '';
        $ap_org_term   = isset($ap_meta['org_term_name']) ? (string) $ap_meta['org_term_name'] : '';
        $ap_org_start  = isset($ap_meta['org_term_start']) ? (string) $ap_meta['org_term_start'] : '';
        $ap_org_end    = isset($ap_meta['org_term_end']) ? (string) $ap_meta['org_term_end'] : '';
        if (empty($ap_items)) {
            $ap_items = array(array()); // 至少一行：无 JS 时也能录入（渐进增强）
        }
        $ap_role_options     = function_exists('zw_branch_role_options') ? (array) zw_branch_role_options() : array();
        $ap_org_dept_options = function_exists('zw_doc_appoint_org_dept_options') ? (array) zw_doc_appoint_org_dept_options() : array();
        $ap_org_duty_options = function_exists('zw_doc_appoint_org_duty_options') ? (array) zw_doc_appoint_org_duty_options() : array();
        // 本级「届次 / 任期」**只读**（TASK-1304）：唯一出处 `inc/term-cycles.php` 的**当前**届次起止；
        // 前端不提供修改入口，服务端 `zw_doc_appoint_normalize_item()` 亦强制回写当前届次。
        $ap_term_default     = function_exists('zw_doc_appoint_term_dates') ? (array) zw_doc_appoint_term_dates('') : array();
        $ap_term_default     = array_merge(array('term' => '', 'start' => '', 'end' => ''), $ap_term_default);
        // 文档级本级回落（存量公文）：行内缺省时的展示值。
        if ($ap_org_dept === '' && !empty($ap_org_dept_options)) {
            $ap_org_dept_keys = array_keys($ap_org_dept_options);
            $ap_org_dept      = (string) $ap_org_dept_keys[0];
        }
        if ($ap_org_duty === '' && !empty($ap_org_duty_options)) {
            $ap_org_duty_keys = array_keys($ap_org_duty_options);
            $ap_org_duty      = (string) $ap_org_duty_keys[0];
        }
        // 四行模板句式（唯一出处 = `zw_doc_appoint_template_patterns()`），注入前端避免两套文案。
        $ap_patterns  = function_exists('zw_doc_appoint_template_patterns')
            ? (array) zw_doc_appoint_template_patterns($ap_is_hq ? 'hq' : 'branch')
            : array();
        // 任命人员候选端点：**复用**花名册「关联用户」canonical 远程端点（不新建端点、不复制检索逻辑）。
        $ap_remote = array(
            'url'    => admin_url('admin-ajax.php'),
            'action' => 'zw_branch_search_link_users',
            'nonce'  => function_exists('zw_branch_roster_link_search_nonce_action')
                ? wp_create_nonce(zw_branch_roster_link_search_nonce_action())
                : '',
        );
        // 拟任命部门候选（TASK-1304）：与**发文机关同一出处** `zw_doc_department_names()`，canonical search-select 本地模式。
        // TASK-1319（用户口径）：**分支机构任命模式**下不再显示「中国传统文化促进会」（协会本级）——
        // 该列仅用于指定**具体分支机构**；本级人员不在此列选择（总会任命模式下该列整列隐藏，
        // 且服务端 `zw_doc_appoint_normalize_item()` 对本级行强制把 `dept` 写为协会本级）。
        // 注意：仍**以 `zw_doc_department_names()` 为唯一出处**，此处只是按模式**过滤**，不另立名录。
        $ap_dept_head_name = function_exists('zw_doc_appoint_org_head_name') ? (string) zw_doc_appoint_org_head_name() : '';
        $ap_dept_select_options = array();
        foreach ((function_exists('zw_doc_department_names') ? (array) zw_doc_department_names() : array()) as $ap_dept_name) {
            $ap_dept_name = (string) $ap_dept_name;
            if ($ap_dept_name === '') {
                continue;
            }
            if (!$ap_is_hq && $ap_dept_head_name !== '' && $ap_dept_name === $ap_dept_head_name) {
                continue;
            }
            $ap_dept_select_options[] = array('value' => $ap_dept_name, 'label' => $ap_dept_name);
        }
        $ap_row_args = array(
            // TASK-1312：模块级模式是**行渲染的权威列集来源**；
            // `type` 保留为未提交模式时的回落（存量 / 无 JS）。
            'mode'             => $ap_mode,
            'type'             => ($ap_type !== '' ? $ap_type : $ap_mode),
            'role_options'     => $ap_role_options,
            'dept_options'     => $ap_dept_select_options,
            'org_dept_options' => $ap_org_dept_options,
            'org_duty_options' => $ap_org_duty_options,
            // 模块级单值（总会任命）；分支模式下这两项不参与渲染（行内隐藏域）。
            'org_dept_default' => $ap_org_dept,
            'org_duty_default' => $ap_org_duty,
            'term_default'     => $ap_term_default,
            // 分支逐行任期的**文档级回落**（存量公文零变化）。
            'branch_term_start' => $ap_term_start,
            'branch_term_end'   => $ap_term_end,
            'remote'           => $ap_remote,
        );

        // 提示前缀（TASK-1089）：编辑态没有「流转与附件」步，口径必须随态变化而不是写死 4 步。
        // TASK-1368（用户指令）：四步向导合并为**三步**（③ 公文流转 = 版记 + 流转）。
        $step_hint_prefix = $doc
            ? '共 3 步：公文要素、正文撰写、公文流转；流转字段按权限可改或只读。'
            : '共 3 步：前 2 步决定打印出来的样子，第 3 步的版记上纸面、流转仅存档。';
        $preview_args = array(
            'doc_number' => $doc ? (string) $doc['number'] : (is_array($preview) ? (string) $preview['full_number'] : ''),
        );
        if ($doc) {
            $preview_args = zw_doc_build_render_args($doc, $preview_args);
        } elseif (function_exists('zw_doc_build_render_args')) {
            // TASK-1422：未保存的新发文也走**同一红头构造器**（全局生成方式 → 默认红头 / 系统自动生成），
            // 保证「版式预览」与出件同源（此前新发文预览恒为常量绘制，与出件可能不一致）。
            // TASK-1990：改为**完整构造器** `zw_doc_build_render_args()`（含印章解析）—— 否则新建态缩略预览
            // 无章、落款走「不加盖印章」口径，与「预览当前内容」/出件两侧不一致。
            $preview_args = zw_doc_build_render_args(array(), $preview_args);
        }
        ?>
        <div class="wrap zw-doc-composer">
            <?php echo zw_doc_admin_ui_style(); // 唯一出处：SPEC-10 §1.9 卡片范式（TASK-778） ?>
            <style>
            .zw-doc-composer .zwc-grid{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;width:100%}
            /* 左栏限宽：页面虽为 100% 宽，但表单列过宽会让输入框难以阅读（100% 宽控件在 1500px 下可达 1400px） */
            .zw-doc-composer .zwc-left{flex:1 1 640px;min-width:0;max-width:980px}
            .zw-doc-composer .zwc-right{flex:1 1 400px;min-width:0;max-width:100%;overflow:auto;background:#f0f0f1;border:1px solid #e5e7eb;border-radius:12px;padding:10px}
            .zw-doc-composer .zwc-step{display:inline-block;background:var(--primary-color,#d80505);color:#fff;border-radius:10px;padding:1px 9px;font-size:12px;margin-right:6px}
            .zw-doc-composer .zwc-blocks{width:100%;border-collapse:collapse}
            .zw-doc-composer .zwc-blocks td{border-bottom:1px solid #f0f1f3;padding:6px 4px;vertical-align:top}
            .zw-doc-composer .zwc-blocks select{max-width:180px}
            .zw-doc-composer .zwc-blocks textarea{width:100%}
            /* 4 步标签向导（TASK-786；标签对齐与校验修复 TASK-1089）：步骤条 + 面板 + 两列字段 + 文号信息条 + 唯一底部操作条 */
            .zw-doc-composer .zwc-stepbar{display:flex;flex-wrap:wrap;gap:8px;list-style:none;margin:0 0 10px;padding:0}
            .zw-doc-composer .zwc-stepbar li{margin:0}
            .zw-doc-composer .zwc-tab{border:1px solid #dcdfe6;background:#fff;border-radius:999px;padding:6px 14px;font-size:13px;cursor:pointer;color:#50575e}
            .zw-doc-composer .zwc-tab b{color:var(--primary-color,#d80505);margin-right:4px}
            .zw-doc-composer .zwc-tab.is-active{border-color:var(--primary-color,#d80505);background:#fef2f2;color:#1d2327;font-weight:600}
            .zw-doc-composer .zwc-tab-note{color:#8c8f94;font-size:12px;margin-left:6px}
            .zw-doc-composer .zwc-tab.is-active .zwc-tab-note{color:#b30404}
            .zw-doc-composer .zwc-step-hint{font-size:12px;color:#666;margin:0 0 14px;background:#fafbfc;border-left:3px solid var(--primary-color,#d80505);padding:8px 10px}
            /* 唯一底部操作条（TASK-1089）：左「返回」，右「上一步 / 下一步 / 保存草稿 / 提交审核」同一行 */
            .zw-doc-composer .zwc-actionbar{margin:16px 0 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-top:1px solid #f0f1f3;padding-top:12px}
            .zw-doc-composer .zwc-actionbar .zwc-actionbar-spacer{margin-left:auto}
            /* 无 JS 时上一步/下一步无意义（type=button 无处理器）→ 隐藏；保存与提交保持可见可提交 */
            .zw-doc-composer .zwc-actionbar:not(.is-js) [data-zw-nav]{display:none}
            .zw-doc-composer .zwc-actionbar [data-zw-nav][hidden],.zw-doc-composer .zwc-actionbar [data-zw-submit][hidden]{display:none}
            .zw-doc-composer .zwc-fields{display:grid;gap:0 16px;grid-template-columns:minmax(0,1fr)}
            .zw-doc-composer .zwc-fields.is-2{grid-template-columns:repeat(2,minmax(0,1fr))}
            .zw-doc-composer .zwc-fields.is-2 > .zw-doc-field.is-half{grid-column:auto}
            .zw-doc-composer .zwc-fields.is-2 > .zw-doc-field.is-wide{grid-column:1/-1}
            /* TASK-1271：第 1 步按用途分五段显示（方案 A，用户拍板） */
            /* TASK-1309（用户报「排版及其混乱」）：第 1 步每个分组升级为**独立卡片**（自包含区域边界）。
               旧结构病根：`.zwc-fields.is-2` 是**跨全步**两列网格，`.zwc-group` 只是 `grid-column:1/-1` 的
               行内小标题 ⇒ 其后同级 `.zw-doc-field` 直接参与两列自动布局，相邻分组的字段互相交错。
               新结构：`.zwc-fields.is-2.zwc-sections` 为 flex 纵向堆叠（分组**整块**排列，绝不交错）→
               直接子级 `.zwc-section`（卡片：边框 + 圆角 + 底色 + 标题条左侧主色标识条）>
               `.zwc-section-head`（组名 + 既有 `.zw-adm-badge` 徽章）+ `.zwc-section-body`（**该组自己的**字段网格）。
               仍复用既有类名 `.zwc-fields` / `.zwc-group` / `.zwc-group-t` / `.zw-doc-field` / `.is-half` / `.is-wide`。 */
            .zw-doc-composer .zwc-fields.zwc-sections{display:flex;flex-direction:column;gap:18px}
            .zw-doc-composer .zwc-section{background:#fff;border:1px solid #e3e6eb;border-radius:12px;border-left:3px solid var(--primary-color,#d80505)}
            .zw-doc-composer .zwc-section-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0;padding:10px 14px;background:#fafbfc;border-bottom:1px solid #e9ecf1;border-radius:0 11px 0 0}
            .zw-doc-composer .zwc-section-body{display:grid;gap:14px 16px;grid-template-columns:repeat(2,minmax(0,1fr));align-items:start;padding:14px}
            .zw-doc-composer .zwc-section-body.is-flat{padding:0;gap:0 16px;align-items:stretch}
            .zw-doc-composer .zwc-fields > .zwc-section-body{grid-column:1/-1}
            .zw-doc-composer .zwc-section-body > .zw-doc-field.is-half{grid-column:auto}
            .zw-doc-composer .zwc-section-body > .zw-doc-field.is-wide{grid-column:1/-1}
            /* 卡片体内的非字段块（人员表容器 / 按钮行 / 子区域标题 / 条件字段区）一律整行。
               另加 `min-width:0` 属**防御性加固**：栅格项默认 `min-width:auto` 在**固定 max 轨道**下会取内容
               最小宽度、存在击穿 `overflow-x:auto` 的风险。实测对照见 CHG-171 §二（本页 `minmax(0,1fr)` 弹性
               轨道下加与不加结果相同）⇒ 保留为结构防护，不宣称已复现缺陷。 */
            .zw-doc-composer .zwc-section-body > :not(.zw-doc-field){grid-column:1/-1;min-width:0}
            /* 组内子区域（TASK-1309）：同一张卡片里再分「版头要素」等子块，不新增分组 */
            .zw-doc-composer .zwc-section-sub{display:flex;align-items:center;gap:6px;margin:0;padding:0 0 6px;border-bottom:1px dashed #e9ecf1;font-size:12px;font-weight:600;color:#50575e}
            /* TASK-1312：整块显隐的容器必须让 `hidden` 真正生效（`display:flex/grid` 会盖过 UA 的 [hidden]） */
            .zw-doc-composer .zwc-section-sub[hidden]{display:none}
            .zw-doc-composer .zw-doc-field[hidden]{display:none}
            /* 人员任命配置逐行人员区（TASK-1309 引入；**TASK-1318 修裁剪**；TASK-1322 改堆叠分区布局）。
               TASK-1318 根因：canonical 控件 `zw_control_search_select()` 的面板是**内联绝对定位**
               （`.zw-ss{position:relative}` + `.zw-ss-list{position:absolute;z-index:60}`，无 portal/fixed 逃逸），
               而本容器原为 `overflow-x:auto` ⇒ **任何 `overflow!=visible` 的祖先都会裁剪绝对定位子元素**，
               输入区「拟任命部门 / 任命人员」搜索下拉一弹出即被容器边缘（视觉上即「下一行内容」）截断。
               TASK-1322 改法：容器仍 `overflow:visible`（不裁剪，红线不可退）；行内区不再是表格
               ⇒ 天然无 `table-layout` / 无横向溢出；每人一块、块内按行分组，宽度由内联百分比决定。 */
            .zw-doc-composer .zwc-section-tablewrap{min-width:0;max-width:100%;overflow:visible}
            .zw-doc-composer .zwc-section-tablewrap > .zwc-ap-rows{display:block;margin:0}
            /* 每人一块：块与块之间留间距，块内可辨识边界。TASK-1346：本块同时是行级「操作」
               （右上角删除按钮）的**定位基座**，故必须 `position:relative`。 */
            .zw-doc-composer .zwc-ap-rows > .zwc-ap-row{display:block;position:relative;margin:0 0 12px;padding:10px 12px;border:1px solid #e9ecf1;border-radius:10px;background:#fcfdfe}
            .zw-doc-composer .zwc-ap-rows > .zwc-ap-row:last-child{margin-bottom:0}
            /* TASK-1346（用户口径）：行级「操作」移到本区块**右上角**，形态对齐 Windows 资源管理器
               右上角的关闭按钮 —— 因此不带可见文字标签，语义由 `title` + `aria-label` 承担。
               定位基座是上一条规则的 `position:relative`。主色只用规范变量/规范 hover 值。
               注：本注释**不写该类的字面规则串**，以免被本页静态断言误判为真正的规则。 */
            .zw-doc-composer .zwc-ap-row-ops{position:absolute;top:6px;right:8px;width:auto;line-height:1}
            .zw-doc-composer .zwc-ap-row-ops .zwc-ap-del{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;min-height:24px;padding:0;border-radius:6px;font-size:15px;line-height:1;color:#50575e}
            @media(hover:hover){.zw-doc-composer .zwc-ap-row-ops .zwc-ap-del:hover{color:#b30404;background:#fdecec;border-color:#f0c9c9;box-shadow:none}}
            /* 第 1 行右侧让出右上角按钮的宽度，输入区不与按钮重叠 */
            .zw-doc-composer .zwc-ap-line.is-person-line{padding-right:30px}
            /* 块内按行分组：flex 行 + 可换行（窄屏允许行内换行，不横向溢出） */
            .zw-doc-composer .zwc-ap-line{display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap}
            .zw-doc-composer .zwc-ap-line + .zwc-ap-line{margin-top:10px}
            .zw-doc-composer .zwc-ap-line[hidden]{display:none}
            /* 单元格：宽度由内联百分比给出；`min-width:0` 允许内部 canonical 控件收缩不被截断 */
            .zw-doc-composer .zwc-ap-cell{min-width:0;box-sizing:border-box;display:block}
            .zw-doc-composer .zwc-ap-cell[hidden]{display:none}
            /* 字段自带 label（改造后无表头，字段名必须落在字段自身） */
            .zw-doc-composer .zwc-ap-label{display:block;font-size:12px;font-weight:600;color:#1d2327;margin:0 0 4px}
            .zw-doc-composer .zwc-section-tablewrap > .zwc-ap-rows .zwc-ap-cell > input,
            .zw-doc-composer .zwc-section-tablewrap > .zwc-ap-rows .zwc-ap-cell > select{width:100%;min-width:0;box-sizing:border-box}
            /* TASK-1318：面板宽度跟随控件（`.zw-ss-list{left:0;right:0}`），故控件须可收缩不被单元格截断。 */
            .zw-doc-composer .zwc-section-tablewrap > .zwc-ap-rows .zwc-ap-cell .zw-ss{min-width:0;max-width:100%}
            /* 任期快捷（1/2/3 年）：与任期起止同处第 3 行，占 20% */
            .zw-doc-composer .zwc-ap-quick{display:flex;gap:6px;flex-wrap:wrap}
            /* 总会任命的行内模块级单值隐藏域：不显示、不参与布局，仍随表单提交 */
            .zw-doc-composer .zwc-ap-hq-inline[hidden]{display:none}
            /* TASK-1312：操作区**模块级单选**（总会任命 / 分支机构任命，默认分支机构任命）。
               控件零新增（原生 radio + 既有 .button 视觉口径）；主色只用规范变量，不新增全局 CSS 文件。
               TASK-1324（用户口径）：该**操作**移到**本区块头右上角** —— 用 `margin-left:auto` 把它推到最右，
               与左侧组名同处一行；窄屏由其自身 `flex-wrap` 与区块头既有折行能力自然换行（不横向滚动）。
               注：本注释**不写该类的字面规则串**（形如「类名 + 花括号」），以免被本页静态断言误判为真正的规则。 */
            .zw-doc-composer .zwc-ap-mode-op{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
            .zw-doc-composer .zwc-ap-mode-op-label{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#1d2327;white-space:nowrap}
            .zw-doc-composer .zwc-ap-mode-op .zwc-ap-mode-radios{gap:14px}
            .zw-doc-composer .zwc-ap-mode-radios{display:flex;align-items:center;gap:18px;flex-wrap:wrap}
            .zw-doc-composer .zwc-ap-mode-radio{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#1d2327;cursor:pointer}
            .zw-doc-composer .zwc-ap-mode-radio input[type=radio]{margin:0;accent-color:var(--primary-color,#d80505)}
            /* 总会任命的模块级单值（所属部门 / 组织架构职务 / 届次 / 任期）：两列网格，与卡片体内一致 */
            .zw-doc-composer .zwc-ap-hq-grid{display:grid;gap:14px 16px;grid-template-columns:repeat(2,minmax(0,1fr));align-items:start;padding:0 0 4px}
            .zw-doc-composer .zwc-ap-hq-grid[hidden]{display:none}
            .zw-doc-composer .zwc-ap-hq-grid > .zw-doc-field{min-width:0}
            @media(max-width:782px){.zw-doc-composer .zwc-ap-hq-grid{grid-template-columns:minmax(0,1fr)}}
            .zw-doc-composer .zwc-group-t{font-weight:600;font-size:13px;color:#1d2327}
            /* 隐藏面板：仅在 JS 就绪（.is-js）后生效 → 无 JS 时全部字段可见，仍可提交 */
            .zw-doc-composer .zwc-steps.is-js > .zwc-panel[hidden]{display:none}
            .zw-doc-composer .zwc-steps.is-js > .zwc-panel{display:block}
            .zw-doc-composer .zwc-numbox{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;background:#fafbfc;border:1px dashed #dcdfe6;border-radius:8px;padding:10px 12px;margin:0 0 14px}
            .zw-doc-composer .zwc-numbox-k{font-size:13px;color:#666}
            .zw-doc-composer .zwc-numbox-v{font-size:16px;color:var(--primary-color,#d80505)}
            .zw-doc-composer .zwc-numbox-n{font-size:12px;color:#8c8f94}
            @media(max-width:782px){.zw-doc-composer .zwc-fields.is-2{grid-template-columns:minmax(0,1fr)}}
            /* TASK-1309：窄屏下卡片体内的字段网格同样回落单列（与整页节奏一致） */
            @media(max-width:782px){.zw-doc-composer .zwc-section-body{grid-template-columns:minmax(0,1fr)}.zw-doc-composer .zwc-section-body > .zw-doc-field.is-wide{grid-column:auto}}
            /* TASK-1349（用户 2026-10-09 拍板）：第 2 步「导入正文」折叠块 —— 两个一次性导入动作
               （批量粘贴 / 上传 Word）收进**默认收起**的原生 `<details>`：零 JS、无 JS 也可用，
               且「正文段（在线编写）」编辑器**常驻不被藏**（表单主体始终可见可填）。
               折叠框自带虚线边框与底色，内层 `.zwc-paste` 退化为纯内边距（不再画第二层框）。 */
            .zw-doc-composer .zwc-import{margin:0 0 14px;border:1px dashed #dcdfe6;border-radius:8px;background:#fafbfc}
            .zw-doc-composer .zwc-import > summary{cursor:pointer;padding:10px 14px;font-size:13px;font-weight:600;color:#1d2327;list-style:none}
            .zw-doc-composer .zwc-import > summary::-webkit-details-marker{display:none}
            .zw-doc-composer .zwc-import > summary::before{content:'▸';display:inline-block;width:1em;color:#8c8f94;transition:transform .15s ease}
            .zw-doc-composer .zwc-import[open] > summary::before{transform:rotate(90deg)}
            @media(hover:hover){.zw-doc-composer .zwc-import > summary:hover{color:#b30404}}
            .zw-doc-composer .zwc-import[open] > summary{border-bottom:1px dashed #dcdfe6}
            @media(prefers-reduced-motion:reduce){.zw-doc-composer .zwc-import > summary::before{transition:none}}
            .zw-doc-composer .zwc-paste{padding:12px 14px}
            /* 「正文段（在线编写）」编辑器小标题：让常驻编辑器有自己的身份（TASK-1349） */
            .zw-doc-composer .zwc-blocks-title{margin:0 0 8px;font-size:13px;font-weight:600;color:#1d2327}
            .zw-doc-composer .zwc-hint{background:#fafbfc;border-left:3px solid var(--primary-color,#d80505);padding:8px 10px;margin:0 0 12px;font-size:12px;color:#50575e}
            </style>
            <h1><?php echo $doc ? '编辑正文：' . esc_html((string) $doc['title']) : '新建发文（在线撰写）'; ?></h1>
            <?php if ($notice !== '') { zw_doc_admin_notice($notice, zw_doc_notice_level($notice)); } // TASK-1362：级别由唯一出处判定（原关键词嗅探会把驳回渲染成"成功"）?>
            <?php
            // TASK-1991（用户口径「已经填写的内容都被清空了」）：**校验未通过时本页不保留填过的内容** ——
            // 表单值不落库、失败后按 PRG 重定向回新建页（只有提示文案，没有回填）。这是既有实现，
            // 本单先把它**显式告知**用户，避免"填了半天白填"的惊讶；真正的草稿回填属后续任务。
            if ($doc === null && $notice !== '') :
                ?>
                <div class="notice notice-info"><p>
                    提示：提交未通过校验时，本页<strong>不会保留</strong>你刚填写的内容（表单值不落库）。
                    长文建议先点「保存草稿并占用文号」，再回到该发文点「提交审核」。
                </p></div>
            <?php endif; ?>
            <?php if ($doc && (string) $doc['status'] === 'approved') : ?>
                <div class="notice notice-warning"><p>该发文已审核通过。修改正文后如需重新审核，请按流程提交；已用印的发文不可编辑。</p></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" id="zwc-form" novalidate>
                <?php // novalidate（TASK-1089）：隐藏面板里的原生 required 会让浏览器在 submit 事件之前中止提交（控件不可聚焦），
                      // 表现为「保存草稿 / 提交审核 点了没反应」；改由页面脚本 + 服务端双重校验。?>
                <?php wp_nonce_field($doc ? 'zw_doc_save_body' : 'zw_doc_create_document'); ?>
                <input type="hidden" name="zw_doc_action" value="<?php echo $doc ? 'save_body' : 'create_document'; ?>">
                <?php if ($doc) : ?>
                    <input type="hidden" name="doc_id" value="<?php echo (int) $doc['id']; ?>">
                <?php else : ?>
                    <input type="hidden" name="zw_doc_idempotency_key" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">
                <?php endif; ?>

                <div class="zwc-grid">
                    <div class="zwc-left">
                        <ol class="zwc-stepbar" id="zwc-stepbar">
                            <li><button type="button" class="zwc-tab is-active" data-zw-goto="1"><b>①</b> 公文要素<span class="zwc-tab-note"></span></button></li>
                            <li><button type="button" class="zwc-tab" data-zw-goto="2"><b>②</b> 正文撰写<span class="zwc-tab-note"></span></button></li>
                            <?php
                            // TASK-1368（用户指令）：「版记与页码」与「流转与附件」**合并为第 3 步「公文流转」** ⇒ 四步向导变**三步**。
                            // 第 3 步面板**恒渲染**（版记字段在任何状态下都可改）；其内部**流转区**仍按 TASK-1350 口径门控
                            // （`!$doc || $flow_editable`）。原第 4 步标签一并移除。
                            ?>
                            <li><button type="button" class="zwc-tab" data-zw-goto="3"><b>③</b> 公文流转<span class="zwc-tab-note"><?php echo $doc ? '版记可改·流转视权限' : '必填：审核人'; ?></span></button></li>
                        </ol>
                        <p class="zwc-step-hint" id="zwc-step-hint" data-zw-prefix="<?php echo esc_attr($step_hint_prefix); ?>"><?php echo esc_html($step_hint_prefix); ?><strong>切换步骤不会丢失已填内容</strong>。</p>
                        <div class="zwc-steps" id="zwc-steps">

                        <section class="zw-doc-card zwc-panel" data-zw-step="1">
                            <h2><span class="zwc-step">第 1 步</span>公文要素<span class="zw-adm-badge is-on">影响版式</span></h2>
                            <div class="zwc-numbox" id="zwc-numbox">
                                <span class="zwc-numbox-k">发文字号</span>
                                <strong class="zwc-numbox-v"><?php echo is_wp_error($preview) ? esc_html($preview->get_error_message()) : esc_html((string) $preview['full_number']); ?></strong>
                                <span class="zwc-numbox-n">保存或提交时才真正占用（打开页面只预览）</span>
                            </div>
                            <div class="zwc-fields is-2 zwc-sections">
                                <?php // TASK-1271（用户拍板·方案 A）：第 1 步按「用途」分五段显示 ?>
                                <?php // TASK-1309：每段升级为独立卡片（.zwc-section），本段字段只在本段的 .zwc-section-body 内排布 ?>
                                <section class="zwc-section">
                                <div class="zwc-section-head zwc-group" data-zwc-section-head="numtype"><span class="zwc-group-t">文种与文号</span><span class="zw-adm-badge is-on">上纸面</span><span class="zw-doc-muted">发文字号见上方信息条</span></div>
                                <div class="zwc-section-body">
                                <?php if (!$doc) : // 文种与取号仅在新建时可选 ?>
                                <div class="zw-doc-field">
                                    <label>文种</label>
                                    <select name="num_type" id="zwc-type" onchange="zwcReloadType()" style="max-width:200px">
                                        <?php foreach ($settings['doc_types'] as $type_option) : ?>
                                            <option value="<?php echo esc_attr($type_option); ?>"<?php selected($type, $type_option); ?>><?php echo esc_html($type_option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($reusable)) : ?>
                                        <label style="margin-top:8px">使用预留 / 已跳过文号</label>
                                        <select name="reserved_number_id">
                                            <option value="0">不选择，按系统自动取号</option>
                                            <?php foreach ($reusable as $row) : ?>
                                                <option value="<?php echo (int) $row['id']; ?>">
                                                    <?php echo esc_html(($row['status'] === 'skipped' ? '【已跳过】' : '【预留】') . $row['full_number']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php // TASK-1276（CHG-155）：版头标记三要素（GB/T 9704-2012 §7.2.1~7.2.3）。
                                  // 归属「① 文种与文号」分组（版头要素），**不新增分组、不重排既有五段**。
                                  // 三者自上而下为 份号 → 密级和保密期限 → 紧急程度，整体位于发文机关标志之上；
                                  // 全空时版面上不输出任何标记（渲染侧同口径）。 ?>
                            <?php // TASK-1309：组内再划一层「子区域」标注 —— 版头要素与「文种/文号」语义不同，
                                  // 用 .zwc-section-sub 在**同一张卡片内**标出（不新增分组，分组数仍为 6） ?>
                            <div class="zwc-section-sub">版头要素（GB/T 9704 §7.2.1~7.2.3）：份号 → 密级和保密期限 → 紧急程度</div>
                            <?php // TASK-1867（CHG-362）：份号取号 —— 自动取号（默认，保存时占号）/ 手动指定（可下拉选空闲号）/ 不标注。
                                  // 无 JS 也能用：三态由 `zwc_copy_no_mode` 决定，输入框只是「手动指定」的载体；
                                  // 自动取号的**真实号**以服务端占号结果为准（本框仅作下一号预览）。 ?>
                            <div class="zw-doc-field is-half">
                                <label>份号</label>
                                <select name="zwc_copy_no_mode" id="zwc-copy-no-mode">
                                    <option value="auto"<?php selected($copy_mode, 'auto'); ?>>自动取号（保存时占用下一号）</option>
                                    <option value="manual"<?php selected($copy_mode, 'manual'); ?>>手动指定（可下拉选空闲号）</option>
                                    <option value="none"<?php selected($copy_mode, 'none'); ?>>不标注</option>
                                </select>
                                <input type="text" name="zwc_copy_no" id="zwc-copy-no" inputmode="numeric" maxlength="12" value="<?php echo esc_attr($copy_input_value); ?>" placeholder="如 000012" data-copy-next="<?php echo esc_attr($copy_next); ?>">
                                <?php if (!empty($copy_pool)) : ?>
                                    <select name="zwc_copy_no_pick" id="zwc-copy-no-pick">
                                        <option value="">从空闲号中选择（可选）</option>
                                        <?php foreach ($copy_pool as $copy_option) : ?>
                                            <option value="<?php echo esc_attr($copy_option); ?>"><?php echo esc_html($copy_option); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <div class="zw-doc-muted">6 位数字，不足自动左补 0（超长取后 6 位）；<strong>自动取号</strong>＝保存时占用「下一个可用份号」<?php echo $copy_next !== '' ? '（当前 <code>' . esc_html($copy_next) . '</code>）' : '（<strong>号段已用尽</strong>，请改手动指定）'; ?>；顶格编排在版心左上角第一行，选「不标注」则版面上不出现份号。</div>
                            </div>
                            <script>
                            (function () {
                                var modeEl = document.getElementById('zwc-copy-no-mode');
                                var inputEl = document.getElementById('zwc-copy-no');
                                var pickEl = document.getElementById('zwc-copy-no-pick');
                                if (!modeEl || !inputEl) { return; }
                                var nextNo = inputEl.getAttribute('data-copy-next') || '';
                                function sync() {
                                    var mode = modeEl.value;
                                    inputEl.readOnly = (mode !== 'manual');
                                    if (mode === 'auto') { inputEl.value = nextNo; }
                                    if (mode === 'none') { inputEl.value = ''; }
                                }
                                modeEl.addEventListener('change', sync);
                                if (pickEl) {
                                    // 「从空闲号中选择」＝ 把手动指定的值填进输入框（无 JS 时服务端同样认这个下拉）。
                                    pickEl.addEventListener('change', function () {
                                        if (pickEl.value === '') { return; }
                                        modeEl.value = 'manual';
                                        sync();
                                        inputEl.value = pickEl.value;
                                        inputEl.focus();
                                    });
                                }
                                sync();
                            })();
                            </script>
                            <div class="zw-doc-field is-half">
                                <label>紧急程度</label>
                                <select name="zwc_urgency" id="zwc-urgency">
                                    <option value="">不标注</option>
                                    <?php foreach ($urgency_levels as $urgency_option) : ?>
                                        <option value="<?php echo esc_attr($urgency_option); ?>"<?php selected($urgency_pick, $urgency_option); ?>><?php echo esc_html($urgency_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="zw-doc-muted">3 号黑体、顶格第三行；版头标记的顺序固定为 份号 → 密级和保密期限 → 紧急程度。</div>
                            </div>
                            <div class="zw-doc-field is-half">
                                <label>密级</label>
                                <?php if ($secret_legacy && $secret_unsafe) : ?>
                                    <?php // 历史值含会破坏标记的字符 → 回退为文本框原样保留（用原生 select 无法安全承载） ?>
                                    <input type="text" id="zwc-secret-level" maxlength="30" value="<?php echo esc_attr($secret_level); ?>" readonly>
                                    <input type="hidden" name="zwc_secret_level" value="<?php echo esc_attr($secret_level); ?>">
                                    <div class="zw-doc-muted">该记录原有密级为「<?php echo esc_html($secret_level); ?>」（不在密级字典内），已原样保留；如需改为字典取值，请改选后保存。</div>
                                <?php else : ?>
                                    <select name="zwc_secret_level" id="zwc-secret-level">
                                        <option value="">不标注</option>
                                        <?php foreach ($secret_levels as $secret_option) : ?>
                                            <option value="<?php echo esc_attr($secret_option); ?>"<?php selected($secret_level, $secret_option); ?>><?php echo esc_html($secret_option); ?><?php echo in_array($secret_option, $secret_levels_base, true) ? '' : '（历史值）'; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($secret_legacy) : ?>
                                        <div class="zw-doc-muted">原有密级「<?php echo esc_html($secret_level); ?>」不在密级字典内，已作为额外选项保留，不会被静默改写。</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <div class="zw-doc-field is-half">
                                <label>保密期限</label>
                                <input type="text" name="zwc_secret_period" id="zwc-secret-period" maxlength="20" value="<?php echo esc_attr($secret_period); ?>" placeholder="1年">
                                <div class="zw-doc-muted">与密级同行显示为「密级★期限」（分隔符取自版式常量）；留空则只标注密级。</div>
                            </div>

                                </div><!-- /.zwc-section-body -->
                                </section>
                                <section class="zwc-section">
                                <div class="zwc-section-head zwc-group" data-zwc-section-head="org"><span class="zwc-group-t">发文机关</span><span class="zw-adm-badge is-on">一处选择 · 三处回显</span><span class="zw-doc-muted">红头 / 署名 / 发文部门由「发文机关」带出</span></div>
                                <div class="zwc-section-body">
                            <div class="zw-doc-field is-wide">
                                <label>发文机关 <span class="zw-adm-badge is-on">唯一入口</span></label>
                                <?php // TASK-1272：换 SPEC-85 canonical 可搜索单选下拉 —— 其文本输入**仅作过滤**、
                                      // 真值由 hidden 字段承载，仍满足用户「红头与署名只能选择」的口径（非自由文本） ?>
                                <?php zw_control_search_select(array(
                                    'id'          => 'zwc-org-pick',
                                    'name'        => 'zwc_org_pick',
                                    'value'       => $org_current,
                                    'placeholder' => '输入关键词搜索发文机关',
                                    'empty_text'  => '没有匹配的发文机关',
                                    'options'     => $org_select_options,
                                )); ?>
                                <div class="zw-doc-muted">候选 = 协会本级 + <strong>正常状态</strong>分支机构（支持关键词过滤）；选定后自动带出红头、署名与发文部门（署名/部门为只读回显）。</div>
                            </div>
                            <div class="zw-doc-field">
                                <label>发文机关标志（红头文字） <span class="zw-adm-badge is-on">上纸面</span></label>
                                <select name="zwc_org_name" id="zwc-org">
                                    <?php foreach ($org_head_options as $head_option) : ?>
                                        <option value="<?php echo esc_attr($head_option); ?>"<?php selected($org_head_current, $head_option); ?>><?php echo esc_html($head_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="zw-doc-muted">默认「机关 + 文件」；<strong>分支机构红头带协会全称前缀</strong>，长名自动缩小字号与字距、<strong>绝不折行</strong>；版头红线由程序生成（双线），使用红头图片时以图片为准。</div>
                            </div>
                            <div class="zw-doc-field">
                                <label>发文机关署名 <span class="zw-adm-badge is-on">上纸面</span> <span class="zw-adm-badge">只读回显</span></label>
                                <input type="text" name="zwc_sign_org" id="zwc-sign" maxlength="60" readonly value="<?php echo esc_attr($org_sign_display); ?>">
                                <div class="zw-doc-muted">随机关回显；<strong>分支机构署名带协会全称前缀</strong>（如「中国传统文化促进会孔子文化传承工作委员会」），落款须与印章一致，<strong>不含「文件」二字</strong>。</div>
                            </div>
                            <div class="zw-doc-field">
                                <label>发文部门 <span class="zw-adm-badge is-on">台账 + 版记</span> <span class="zw-adm-badge">只读回显</span></label>
                                <?php if ($doc) : ?>
                                    <input type="text" maxlength="120" readonly value="<?php echo esc_attr((string) $doc['department']); ?>">
                                    <div class="zw-doc-muted">台账字段，且作为版记「<strong>印发机关</strong>」的真值源；编辑正文时不修改，如需变更请在「我的发文」处理。</div>
                                <?php else : ?>
                                    <input type="text" name="doc_department" id="zwc-dept" maxlength="120" readonly value="<?php echo esc_attr($org_current); ?>">
                                    <div class="zw-doc-muted">随机关回显；台账 / 流转用，并作为版记「<strong>印发机关</strong>」的真值源（保存时服务端按同一名录二次校验）。</div>
                                <?php endif; ?>
                            </div>

                                </div><!-- /.zwc-section-body -->
                                </section>
                            <?php if ($ap_active) : // TASK-1291 / TASK-1304 / TASK-1312（SPEC-87 §20.12）：人员任命配置 —— 仅「任」字文种，其他文种零输出 ?>
                            <?php // TASK-1312（用户拍板）：**模块级单选**（总会任命 / 分支机构任命，默认分支机构任命）为服务端权威；
                                  // 同篇不再混选（TASK-1304 的逐人拟任命部门能力**收窄**）。列集与字段顺序按模式切换：
                                  //   分支机构任命 ⇒ 拟任命部门 * → 会内职务 * → 任期（开始 + 结束）→ 工作范围；
                                  //   总会任命     ⇒ 隐藏「拟任命部门」列，显示所属部门 / 组织架构职务 / 届次 / 任期（**模块级单值**），无工作范围。
                                  // 注意：模式专属字段**不得**加原生 required —— 步骤校验统计 `#zwc-form [required]`
                                  // 不区分显隐，会给隐藏那套制造"永远填不完"的必填（最终以服务端 problems 为准）。
                                  // 隐藏一律施加在 `<td>` / 容器上、**不施加在控件上** ⇒ 隐藏列的数据仍随表单提交、往返不丢。 ?>
                                <section class="zwc-section">
                                <?php // TASK-1324（用户口径）：把「任命模式」单选（本区块的**操作**）移到**区块头右上角**，
                                      // 与组名同处一行、靠右对齐；说明性文案移到正文区，避免长句把操作挤到第二行。
                                      // 保留 `#zwc-ap-mode-op` 与 `input[name="zwc_ap_mode"]`（页尾 JS 依此绑定 `zwcApSetMode`）。 ?>
                                <div class="zwc-section-head zwc-group" data-zwc-section-head="appoint"><span class="zwc-group-t">人员任命配置</span><span class="zw-adm-badge is-on">上纸面</span><span class="zw-adm-badge">仅「任」字文种</span>
                                    <div class="zwc-ap-mode-op" id="zwc-ap-mode-op">
                                        <span class="zwc-ap-mode-op-label">任命模式 <span class="zw-adm-badge">必选</span></span>
                                        <div class="zwc-ap-mode-radios" role="radiogroup" aria-label="任命模式">
                                            <label class="zwc-ap-mode-radio zw-adm-touch"><input type="radio" name="zwc_ap_mode" value="branch" data-zw-ap-mode="branch"<?php checked($ap_mode, 'branch'); ?>> 分支机构任命</label>
                                            <label class="zwc-ap-mode-radio zw-adm-touch"><input type="radio" name="zwc_ap_mode" value="hq" data-zw-ap-mode="hq"<?php checked($ap_mode, 'hq'); ?>> 总会任命</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="zwc-section-body">
                                <div class="zw-doc-field is-wide">
                                    <div class="zw-doc-muted" id="zwc-ap-mode-hint"><?php echo $ap_is_hq
                                        ? '总会任命：填「' . esc_html(zw_doc_appoint_org_head_name()) . '」所属部门 / 组织架构职务 / 届次与任期（只读，模块级单值），人员同步进「组织架构 → 人员」。'
                                        : '分支机构任命：逐行选具体分支机构 + 会内职务 + 任期，工作范围写入正文首句；人员同步进「分支机构 → 机构列表 → 负责人管理」。'; ?></div>
                                    <div class="zw-doc-muted">默认「分支机构任命」。切换模式会切换下方字段集与同步去向，<strong>同一篇公文不再混选</strong>。</div>
                                </div>
                                <?php // TASK-1309 引入本包裹层；TASK-1318 已把其 `overflow-x:auto` 改为 `overflow:visible`（否则会裁掉控件下拉面板）。
                                      // TASK-1322：逐行人员区由「表格 + 表头列」改为**堆叠分区布局**（每人一块、块内按行分组、字段自带 label），
                                      // 容器从此不再是表格 ⇒ 无 `table-layout:fixed`、无横向溢出，`overflow:visible` 继续保留（不得裁剪下拉面板）。 ?>
                                <div class="zwc-section-tablewrap">
                                <div class="zwc-ap-rows" id="zwc-ap-rows" data-zw-ap-mode="<?php echo esc_attr($ap_mode); ?>">
                                <?php foreach ($ap_items as $ap_index => $ap_item_row) : ?>
                                    <?php echo zw_doc_composer_appoint_row_html((string) ($ap_index + 1), $ap_item_row, $ap_row_args); // phpcs:ignore WordPress.Security.EscapeOutput -- 行内逐字段转义 ?>
                                <?php endforeach; ?>
                                </div><!-- /#zwc-ap-rows -->
                                </div><!-- /.zwc-section-tablewrap -->
                                <?php // 分支模式：工作范围**紧跟该分支区域**（会内职务 → 任期 → 工作范围，用户口径顺序）。
                                      // 非当前模式的控件一律 `disabled`（disabled 不随表单提交）⇒ 同名域永远只提交一份、无歧义。 ?>
                                <div class="zw-doc-field is-wide" id="zwc-ap-branch-extra"<?php echo $ap_is_hq ? ' hidden' : ''; ?>>
                                    <label>工作范围（最多 <?php echo (int) zw_doc_appoint_scope_limit(); ?> 个汉字）<?php echo $ap_has_content ? '*' : ''; ?></label>
                                    <input type="text" name="zwc_ap_scope" id="zwc-ap-scope" maxlength="<?php echo (int) zw_doc_appoint_scope_limit(); ?>" value="<?php echo esc_attr($ap_scope); ?>" placeholder="如：传统文化教育"<?php echo ($ap_has_content && !$ap_is_hq) ? ' required' : ''; ?><?php echo $ap_is_hq ? ' disabled' : ''; ?>>
                                    <div class="zw-doc-muted">写入正文首句「……我会“工作范围”工作的开展……」；<strong>文档级单值，不随人走</strong>；任命人员不强制实名（未实名只提示，不阻断）。填了工作范围就必须至少指定一位任命人员（服务端校验）。</div>
                                </div>
                                <?php // 总会模式：**无「工作范围」字段**；存量值以隐藏域原样保留（不清空历史数据）。 ?>
                                <input type="hidden" name="zwc_ap_scope" id="zwc-ap-scope-hidden" value="<?php echo esc_attr($ap_scope); ?>"<?php echo $ap_is_hq ? '' : ' disabled'; ?>>
                                <?php // 总会模式：**模块级单值**四项 —— 所属部门 / 组织架构职务 / 届次（只读）/ 任期（只读）。
                                      // 行内隐藏域承接同一份值（服务端按行归一，两条同步通道各自唯一写入口不变）。 ?>
                                <div class="zwc-section-sub" id="zwc-ap-hq-extra"<?php echo $ap_is_hq ? '' : ' hidden'; ?>>总会任命（模块级单值，作用于本篇全部任命人员）：所属部门 → 组织架构职务 → 届次 → 任期</div>
                                <div class="zwc-ap-hq-grid" id="zwc-ap-hq-grid"<?php echo $ap_is_hq ? '' : ' hidden'; ?>>
                                    <div class="zw-doc-field">
                                        <label>所属部门 *</label>
                                        <select name="zwc_ap_org_dept" id="zwc-ap-org-dept" class="zwc-ap-hq-field" data-zw-ap-hq="org_dept">
                                            <?php foreach ($ap_org_dept_options as $ap_dept_key => $ap_dept_label) : ?>
                                                <option value="<?php echo esc_attr((string) $ap_dept_key); ?>"<?php selected($ap_org_dept, (string) $ap_dept_key); ?>><?php echo esc_html((string) $ap_dept_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="zw-doc-field">
                                        <label>组织架构职务 *</label>
                                        <select name="zwc_ap_org_duty" id="zwc-ap-org-duty" class="zwc-ap-hq-field" data-zw-ap-hq="org_duty">
                                            <?php foreach ($ap_org_duty_options as $ap_duty_key => $ap_duty_label) : ?>
                                                <option value="<?php echo esc_attr((string) $ap_duty_key); ?>"<?php selected($ap_org_duty, (string) $ap_duty_key); ?>><?php echo esc_html((string) $ap_duty_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="zw-doc-field">
                                        <label>届次 <span class="zw-adm-badge">只读</span></label>
                                        <input type="text" id="zwc-ap-org-term" class="zwc-ap-hq-term-field" readonly tabindex="-1"
                                               value="<?php echo esc_attr($ap_org_term); ?>" title="届次只读：唯一出处 inc/term-cycles.php 的当前届次">
                                    </div>
                                    <div class="zw-doc-field">
                                        <label>任期 <span class="zw-adm-badge">只读</span></label>
                                        <input type="date" id="zwc-ap-org-term-start" class="zwc-ap-hq-term-field" readonly tabindex="-1"
                                               value="<?php echo esc_attr($ap_org_start); ?>" title="任期只读：取当前届次起止">
                                        <input type="date" id="zwc-ap-org-term-end" class="zwc-ap-hq-term-field" readonly tabindex="-1"
                                               value="<?php echo esc_attr($ap_org_end); ?>" title="任期只读：取当前届次起止" style="margin-top:6px">
                                    </div>
                                    <?php // 列表型真值由**行内隐藏域**承载（`zwc_ap_item_*[]`，每行一份、模式切换时由脚本同步）：
                                          // 服务端把逐行数组当作权威，故不会串行、不会丢；标量 `zwc_ap_org_dept` / `zwc_ap_org_duty`
                                          // 仍在提交，作为数组取值回落文档级时的兜底。 ?>
                                </div>
                            <p>
                                <button type="button" class="button" id="zwc-ap-add">+ 添加任命人员</button>
                                <span class="zw-doc-muted">单选控件不支持多选 ⇒ 多人 = 多行（SPEC-85 边界）；<strong>同一篇不再混选</strong>（模式由上方单选唯一决定）。点「下一步：撰写正文」按上述要素生成正文（末几段全等则不重复追加）。</span>
                                <span class="zw-doc-muted" id="zwc-ap-tip"></span>
                            </p>
                            <div class="zw-doc-field is-wide">
                                <div class="zw-doc-muted">
                                    <strong>流转</strong>：<strong>分支机构任命</strong> ⇒ 同步进<strong>分支机构 → 机构列表 → 负责人管理</strong>（`zw_branch_leaders_save()`）；<strong>总会任命</strong> ⇒ 同步进<strong>组织架构 → 人员</strong>（`zw_org_insert_person()`）。两条通道各写各的、互不串道（服务端以单选为权威）。分支「任期开始」默认当前日期，1/2/3 年快捷按闰年口径推算结束日期；总会「届次 / 任期」<strong>只读</strong>（唯一出处 <code>inc/term-cycles.php</code> 的当前届次）。
                                </div>
                            </div>
                            <?php // TASK-1321 修复（结构 bug）：本区块的容器闭合标签**必须在 `endif` 之前**。
                                  // 原顺序为「`endif` → 容器闭合 → 容器闭合」（即闭合标签落在条件之外、永远输出）：
                                  // **非「任」文种**下本区块的 section 开标签与 section-body 开标签不输出，
                                  // 但那两行闭合标签照常输出 ⇒ **多出 1 个 div 闭合 + 1 个 section 闭合**
                                  // ⇒ `.zwc-left` 与第 1 步面板被**提前闭合**，操作条升格为 `.zwc-grid` 子元素、
                                  // 版式预览掉到 form 层级（整宽沉底）——即「预览跑到最下方、返回/下一步跑到右侧」。
                                  // 修法：把闭合标签移入条件内，使本块**开合自包含**（两种文种都配平）。
                                  // 注：此处**不写字面标签名**，避免被本页静态计数断言误判为「多一张卡片」。 ?>
                                </div><!-- /.zwc-section-body -->
                                </section>
                            <?php endif; ?>
                                <section class="zwc-section">
                                <?php
                                // TASK-1325（用户口径）：「任」字号文件不需要主送机关 ⇒ 本区块与该字段
                                // **服务端按文种条件渲染**（不渲染即不提交、也不参与必填统计），其他文种零变化。
                                $receiver_applies = zw_doc_composer_receiver_applies($ap_doc_type);
                                ?>
                                <div class="zwc-section-head zwc-group" data-zwc-section-head="title-receiver"><span class="zwc-group-t"><?php echo $receiver_applies ? '标题与主送' : '标题'; ?></span><span class="zw-adm-badge is-on">上纸面</span></div>
                                <div class="zwc-section-body">
                            <div class="zw-doc-field is-wide">
                                <label>公文标题 * <span class="zw-doc-muted" style="font-weight:400">（可手动换行决定断行，最多 3 行；不换行则由系统自动回行）</span></label>
                                <?php
                                // TASK-2014（用户「输入框改造成可折行 最多显示3行 由我自己控制折行」）：
                                // 改为 3 行文本域；回车即断行。`title` 仍存单行（列表/文件名不受影响），
                                // 断行结果随表单进 `title_lines`（归一化时解析，渲染/出件/DOCX 同源）。
                                $zw_title_value = !empty($body['meta']['title_lines']) && is_array($body['meta']['title_lines'])
                                    ? implode("\n", (array) $body['meta']['title_lines'])
                                    : (string) $body['meta']['title'];
                                ?>
                                <textarea name="doc_title" id="zwc-title" rows="3" maxlength="240" required
                                    placeholder="例：关于同意成立中国传统文化促进会&#10;孔子文化传承工作委员会的批复"
                                    style="width:100%;resize:vertical;line-height:1.6;font-size:14px"><?php echo esc_textarea($zw_title_value); ?></textarea>
                                <div class="zw-doc-muted" style="font-size:12px">
                                    当前 <span id="zwc-title-lines"><?php echo (int) max(1, count(array_filter(preg_split('/\r\n|\r|\n/', $zw_title_value)))); ?></span> 行（最多 3 行）· 第 4 行起会被自动截断
                                </div>
                            </div>
                            <?php if ($receiver_applies) : ?>
                            <div class="zw-doc-field">
                                <label>主送机关（下拉单选；选「其他」可自行输入，最多 <?php echo (int) zw_doc_composer_receiver_limit(); ?> 个汉字）</label>
                                <select id="zwc-receiver-pick" aria-controls="zwc-receiver">
                                    <option value="">请选择</option>
                                    <?php foreach ($receiver_options as $receiver_option) : ?>
                                        <option value="<?php echo esc_attr($receiver_option); ?>"<?php selected($receiver_is_other ? '' : $receiver_text, $receiver_option); ?>><?php echo esc_html($receiver_option); ?></option>
                                    <?php endforeach; ?>
                                    <option value="<?php echo esc_attr($receiver_other); ?>"<?php selected($receiver_is_other); ?>>其他</option>
                                </select>
                                <?php // 真值字段仍叫 zwc_receiver（零契约变化）：选字典项时由脚本填入并隐藏；无 JS 时保持可输入（渐进增强） ?>
                                <input type="text" name="zwc_receiver" id="zwc-receiver" maxlength="<?php echo (int) zw_doc_composer_receiver_limit(); ?>" value="<?php echo esc_attr($receiver_text); ?>" placeholder="如：各会员单位" style="margin-top:6px">
                                <div class="zw-doc-muted">打印时自动补末尾全角冒号（SPEC-87 §6）。留空表示不写主送机关。</div>
                            </div>
                            <?php endif; ?>
                                </div><!-- /.zwc-section-body -->
                                </section>
                                <section class="zwc-section">
                                <div class="zwc-section-head zwc-group" data-zwc-section-head="signdate"><span class="zwc-group-t">落款与日期</span><span class="zw-adm-badge is-on">成文日期上纸面</span><span class="zw-adm-badge is-on">发文日期上版记</span></div>
                                <div class="zwc-section-body">
                            <div class="zw-doc-field is-half">
                                <label>成文日期（月、日不补零）</label>
                                <?php if ($date_legacy) : ?>
                                    <?php // 旧记录里的非标准日期（如「二〇二六年十月四日」）用文本框原样保留，日期控件会丢数据 ?>
                                    <input type="text" name="zwc_date" id="zwc-date" maxlength="30" value="<?php echo esc_attr($date_raw); ?>">
                                    <div class="zw-doc-muted">该记录原有成文日期为自由文本「<?php echo esc_html($date_raw); ?>」，无法用日期控件表示，已原样保留。</div>
                                <?php else : ?>
                                    <input type="date" name="zwc_date" id="zwc-date" value="<?php echo esc_attr($date_iso); ?>">
                                    <div class="zw-doc-muted">年月日标准控件，新建默认当天；打印为「月、日不补零」。</div>
                                <?php endif; ?>
                                <div class="zw-doc-muted">会打印在落款处。留空则沿用「发文日期」（台账回退）。</div>
                            </div>
                            <div class="zw-doc-field is-half">
                                <label>发文日期 <span class="zw-adm-badge is-on">台账 + 版记</span></label>
                                <?php if ($doc) : ?>
                                    <input type="date" readonly value="<?php echo esc_attr((string) $doc['issue_date']); ?>">
                                    <div class="zw-doc-muted">台账字段，且作为版记「<strong>印发日期</strong>」的真值源；编辑正文时不修改，如需变更请在「我的发文」处理。</div>
                                <?php else : ?>
                                    <input type="date" name="doc_issue_date" id="zwc-issue-date" value="">
                                    <div class="zw-doc-muted">
                                        台账 / 流转用，并作为版记「<strong>印发日期</strong>」的真值源，<strong>不影响列表排序</strong>；
                                        <span id="zwc-copy-date-tip">成文日期变化会<b>自动同步</b>到本栏；也可用下方按钮把本栏带入成文日期（覆盖）。</span><br>
                                        <button type="button" class="button button-small" id="zwc-copy-date">同发文日期带入成文日期</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                                </div><!-- /.zwc-section-body -->
                                </section>
                                <?php // TASK-1363（用户指令）：「附件说明」**移出第 1 步**，与「公文附件」合并到第 2 步「正文撰写」下方的独立区块（字段名 `zwc_attach_note` 不变）。 ?>
                            </div><!-- /.zwc-fields -->
                        </section>

                        <section class="zw-doc-card zwc-panel" data-zw-step="2">
                            <h2><span class="zwc-step">第 2 步</span>正文（分块撰写）<span class="zw-adm-badge is-on">影响版式</span></h2>
                            <div class="zwc-hint">
                                只写纯文本，<strong>不要贴 HTML</strong>；层次序数<strong>不用手写</strong> —— 选「一级标题」后系统按「一、」「（一）」「1.」「（1）」<strong>自动生成并跨页连续编号</strong>。
                            </div>
                            <?php
                            // TASK-1349（用户 2026-10-09 拍板）：第 2 步「三种编写方式」的形态。
                            // ① 「任」字（正文由系统四段自动生成）⇒ **服务端条件隐藏**整篇导入区（含编辑存量）；
                            // ② 其他文种 ⇒ 两个**一次性导入动作**（粘贴 / Word）收进**默认收起**的「导入正文」折叠块
                            //    （原生 `<details>`：**零 JS、无 JS 也可用**，且不藏「正文段（在线编写）」这个表单主体）；
                            // ③ 「正文段（在线编写）」编辑器**常驻**并带自己的小标题。
                            // 判据与「主送机关豁免」（TASK-1325）同型：**服务端条件渲染**，不靠 JS 隐藏；POST 契约不变。
                            $body_import_applies = zw_doc_composer_body_import_applies($ap_doc_type);
                            ?>
                            <?php if ($body_import_applies) : ?>
                            <details class="zwc-import">
                                <summary>导入正文（批量粘贴 / 上传 Word）</summary>
                                <div class="zwc-paste">
                                    <label for="zwc-paste"><strong>批量粘贴（整篇正文）</strong></label>
                                    <p class="zw-doc-muted" style="margin:4px 0 8px">
                                        本地已排好版？把<strong>正文整篇</strong>粘到这里，点「智能拆分」：按段落自动分块，
                                        并识别「一、/（一）/1./（1）」映射为对应层级；<strong>你手写的序数会被剥掉，由系统重新编号</strong>
                                        （保证跨页连续、增删段落不乱号）。标题、主送机关、落款请在<strong>第 1 步</strong>填写。
                                    </p>
                                    <textarea id="zwc-paste" rows="7" placeholder="一、总体要求&#10;统一版式。正文使用三号仿宋，行距 28 磅。&#10;（一）统一入口&#10;各分支机构统一在线撰写。"></textarea>
                                    <p style="margin:8px 0 0">
                                        <button type="button" class="button button-primary" onclick="zwcSplitPaste('replace')">智能拆分并替换正文</button>
                                        <button type="button" class="button" onclick="zwcSplitPaste('append')">追加到现有正文</button>
                                        <span id="zwc-paste-msg" class="zw-doc-muted"></span>
                                    </p>
                                    <hr style="margin:14px 0">
                                    <label for="zwc-docx"><strong>或上传 Word 文档（.docx）导入</strong></label>
                                    <p class="zw-doc-muted" style="margin:4px 0 8px">
                                        本地已有 Word 版公文？直接上传 <code>.docx</code>：系统按 <strong>Word 标题样式 / 大纲级别 /
                                        文本层次序数 / 编号列表</strong> 判定层级，并剥离手写序数由系统重新编号。
                                        <strong>表格与图片不会导入</strong>（会如实告知数量；请作为附件单独上传）。
                                    </p>
                                    <input type="file" id="zwc-docx" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                                    <p style="margin:8px 0 0">
                                        <button type="button" class="button button-primary" onclick="zwcImportDocx('replace')">导入并替换正文</button>
                                        <button type="button" class="button" onclick="zwcImportDocx('append')">追加到现有正文</button>
                                        <span id="zwc-docx-msg" class="zw-doc-muted"></span>
                                    </p>
                                </div>
                            </details>
                            <?php endif; ?>
                            <h3 class="zwc-blocks-title">正文段（在线编写）</h3>
                            <!-- TASK-1633（TASK-1579 P1-6 第四批）：本表为表单版式表（正文段编辑器），
                                 落 canonical 横滑包裹不改任何字段名 / 提交形状；规则由本页 :1189 的
                                 zw_doc_admin_ui_style()（本模块唯一出处）提供 ⇒ 本页不新增 <style>。 -->
                            <div class="zw-adm-table-wrap">
                            <table class="zwc-blocks" id="zwc-blocks">
                                <tbody>
                                <?php foreach ($blocks as $index => $block) : ?>
                                    <tr class="zwc-row">
                                        <td style="width:180px">
                                            <select name="zw_doc_block_type[]">
                                                <?php foreach ($labels as $key => $label) : ?>
                                                    <option value="<?php echo esc_attr($key); ?>"<?php selected((string) $block['t'], $key); ?>><?php echo esc_html($label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td><textarea name="zw_doc_block_text[]" rows="3" placeholder="第 <?php echo (int) ($index + 1); ?> 段"><?php echo esc_textarea((string) $block['text']); ?></textarea></td>
                                        <td style="width:96px;white-space:nowrap">
                                            <button type="button" class="button button-small" onclick="zwcMove(this,-1)" title="上移">↑</button>
                                            <button type="button" class="button button-small" onclick="zwcMove(this,1)" title="下移">↓</button>
                                            <button type="button" class="button button-small" onclick="zwcRemove(this)" title="删除">×</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            </div>
                            <p style="margin-top:10px">
                                <?php foreach ($labels as $key => $label) : ?>
                                    <button type="button" class="button" onclick="zwcAdd('<?php echo esc_js($key); ?>')">+ <?php echo esc_html($label); ?></button>
                                <?php endforeach; ?>
                            </p>
                            <p class="zw-doc-muted">
                                上限：单段 5000 字、全文 30000 字、最多 500 段（超出会拒绝保存并给出原因）。
                            </p>
                            <?php
                            // TASK-1363（用户指令）：「公文附件」从第 4 步移到**正文撰写下方**，与「附件说明」（从第 1 步移入）
                            // 合并为**独立区块**；区块标题与徽标沿用「公文要素」的分组卡范式（`.zwc-section-head` + `.zwc-group-t` + `.zw-adm-badge`）。
                            // ① 字段名**一律不变**（`zwc_attach_note` / `zw_doc_attachments`）⇒ POST 契约、渲染与导出两通道零变化；
                            // ② 附件上传统一走 SPEC-85 canonical `zw_control_file_upload()`（点击选择 + 拖拽），**不新增上传通路**；
                            // ③ 编辑态附件仍按 TASK-1350 口径**只读报数**（不放出输入框）。
                            $attach_files = array();
                            if ($doc && function_exists('zw_doc_attachment_names')) {
                                $attach_files = (array) zw_doc_attachment_names($doc);
                            }
                            ?>
                            <section class="zwc-section">
                                <div class="zwc-section-head zwc-group" data-zwc-section-head="attach"><span class="zwc-group-t">附件</span><span class="zw-adm-badge is-on">附件说明·上纸面</span><span class="zw-adm-badge">附件文件·仅台账</span></div>
                                <div class="zwc-section-body">
                                    <div class="zw-doc-field is-wide">
                                        <label>附件说明（每行一条，名称后不加标点）</label>
                                        <textarea name="zwc_attach_note" rows="2"><?php echo esc_textarea(implode("\n", (array) $body['meta']['attach_note'])); ?></textarea>
                                        <div class="zw-doc-muted">出现在<strong>正文下空一行、左空二字「附件：」</strong>，且<strong>必须排在落款之前</strong>（国标口径）。</div>
                                    </div>
                                    <div class="zw-doc-field is-wide">
                                        <label>公文附件</label>
                                        <?php
                                        echo zw_control_file_upload(array(
                                            'id'         => 'zw-doc-attach',
                                            'name'       => 'zw_doc_attachments',
                                            'accept'     => '.doc,.docx,.pdf,.xls,.xlsx,.zip,.jpg,.jpeg,.png',
                                            'multiple'   => true,
                                            'max_files'  => 10,
                                            'max_mb'     => 20,
                                            'label'      => '把文件拖到这里，或点击右侧「选择文件」',
                                            'hint'       => '类型：doc/docx/pdf/xls/xlsx/zip/jpg/png',
                                            'disabled'   => (bool) $doc,
                                            'existing'   => $attach_files,
                                            'empty_text' => '这篇发文没有附件。',
                                        ));
                                        ?>
                                        <?php if (!$doc) : ?>
                                            <div class="zw-doc-muted">附件<strong>仅存档、不出现在公文纸面</strong>；表单提交时统一上传，请与「附件说明」中的条目名称保持一致。</div>
                                        <?php endif; ?>
                                    </div>
                                </div><!-- /.zwc-section-body -->
                            </section>
                            <?php
                            // TASK-1365（用户指令）：「页码」从**第 3 步「版记与页码」**移到**第 2 步「正文撰写」下方**、
                            // 且位于上一条「附件」区块**之下**，成为独立区块；区块标记沿用公文要素分组卡范式
                            // （`.zwc-section-head.zwc-group` + `.zwc-group-t` + `.zw-adm-badge`）。
                            // **业务逻辑一律不变**：字段名 `zwc_page_mode`、取值集合（standard/center/none）、默认值
                            // （`$page_mode` 解析自 `zwc_page_mode`，默认 standard）、服务端处理与页码渲染**零改动**。
                            ?>
                            <section class="zwc-section">
                                <div class="zwc-section-head zwc-group" data-zwc-section-head="pageno"><span class="zwc-group-t">页码</span><span class="zw-adm-badge is-on">出现在文末</span></div>
                                <div class="zwc-section-body">
                                    <div class="zw-doc-field is-half">
                                        <label>页码</label>
                                        <select name="zwc_page_mode" style="max-width:240px">
                                            <option value="standard"<?php selected($page_mode, 'standard'); ?>>标准（单页居右、双页居左）</option>
                                            <option value="center"<?php selected($page_mode, 'center'); ?>>居中（— 1 —）</option>
                                            <option value="none"<?php selected($page_mode, 'none'); ?>>不显示</option>
                                        </select>
                                        <div class="zw-doc-muted">标准：<strong>单页页码居右、双页居左</strong>；居中为「— 1 —」；「不显示」则不编页码。</div>
                                    </div>
                                </div><!-- /.zwc-section-body -->
                            </section>
                        </section>

                        <section class="zw-doc-card zwc-panel" data-zw-step="3">
                            <h2><span class="zwc-step">第 3 步</span>公文流转<span class="zw-adm-badge is-on">版记·出现在文末</span><span class="zw-adm-badge">流转·仅存档</span></h2>
                            <div class="zwc-fields is-2">
                            <?php // TASK-1309：本步无分组标题，字段统一进 .zwc-section-body.is-flat（零内边距/零行距变化 ⇒ 版面与改造前一致），
                                  // 只为满足「字段一律有自包含容器、不直接挂在 .zwc-fields 下」的同一条结构口径 ?>
                            <div class="zwc-section-body is-flat">
                            <div class="zw-doc-field">
                                <label>抄送机关（版记）</label>
                                <input type="text" name="zwc_cc" maxlength="200" value="<?php echo esc_attr((string) $body['meta']['cc']); ?>">
                            </div>
                            <div class="zw-doc-field">
                                <label>印发机关 <span class="zw-adm-badge is-on">版记·随发文部门</span> <span class="zw-adm-badge">只读回显</span></label>
                                <input type="text" name="zwc_print_org" id="zwc-print-org" maxlength="60" readonly value="<?php echo esc_attr((string) $record_print['print_org']); ?>">
                                <div class="zw-doc-muted">以公文要素为唯一真值源，自动取「<strong>发文部门</strong>」；存量已保存的印发机关保持不变。</div>
                            </div>
                            <div class="zw-doc-field">
                                <label>印发日期 <span class="zw-adm-badge is-on">版记·随发文日期</span> <span class="zw-adm-badge">只读回显</span></label>
                                <input type="text" name="zwc_print_date" id="zwc-print-date" maxlength="30" readonly value="<?php echo esc_attr((string) $record_print['print_date']); ?>">
                                <div class="zw-doc-muted">以公文要素为唯一真值源，自动取「<strong>发文日期</strong>」（月、日不补零）。</div>
                            </div>
                            <?php // TASK-1365（用户指令）：「页码」已移到**第 2 步「正文撰写」下方**（「附件」区块之下）的独立区块；本步只保留版记字段。 ?>
                            <?php
                            // ── TASK-1368（用户指令）：第 3 步「公文流转」= 版记（上）+ 流转（下）+ 备注（最下方）──────────
                            // ① 原第 4 步的流转字段整体**移入本步**（字段名与业务逻辑一律不变）；
                            // ② 流转区仍按 TASK-1350 口径门控（`!$doc || $flow_editable`）：编辑态无流转编辑权时不渲染
                            //    （版记字段与备注**始终**渲染）；
                            // ③ 「备注（历史发文类型）」按用户口径放到**区块最下方**且**保持现有样式**（`is-wide` + rows=2 + maxlength=255，markup 原样）；
                            // ④ 抄送人员改用 **SPEC-85 canonical 可搜索多选下拉**（`multiple => true`，点开才展开 + 已选 chips + 可搜索），
                            //    与「审核人」同为「点开–选–收起」的选法；控件不可用时**降级**为原生多选（服务端仍是权威校验）。
                            $leader_candidates = zw_doc_leader_candidates();
                            $leader_values     = array_map(function ($item) { return (string) $item['value']; }, $leader_candidates);
                            $leader_current    = $doc ? (string) $doc['leader_name'] : '';
                            $leader_value      = ($leader_current !== '') ? $leader_current : zw_doc_leader_default_name($leader_current);
                            $reviewer_current_value  = $doc ? zw_doc_flow_current_reviewer_value($doc) : '';
                            $reviewer_values         = array_map(function ($item) { return (string) $item['value']; }, $reviewer_candidates);
                            $reviewer_legacy         = ($reviewer_current_value !== '' && !in_array($reviewer_current_value, $reviewer_values, true));
                            $doc_cc_ids              = $doc ? array_map('intval', (array) $doc['cc_ids']) : array();
                            $doc_cc_person_ids       = $doc ? array_map('intval', (array) $doc['cc_person_ids']) : array();
                            $cc_selected_values      = array();
                            $cc_select_options       = array();
                            foreach ($cc_candidates as $candidate) {
                                $cc_select_options[] = array('value' => (string) $candidate['value'], 'label' => (string) $candidate['label']);
                                $cc_is_selected = ((int) $candidate['user_id'] > 0 && in_array((int) $candidate['user_id'], $doc_cc_ids, true))
                                    || ((int) $candidate['person_id'] > 0 && in_array((int) $candidate['person_id'], $doc_cc_person_ids, true));
                                if ($cc_is_selected) {
                                    $cc_selected_values[] = (string) $candidate['value'];
                                }
                            }
                            ?>
                            <?php if (!$doc || $flow_editable) : ?>
                            <div class="zw-doc-field is-half">
                                <label>审核人<?php echo $flow_editable ? ' *' : ''; ?></label>
                                <select name="doc_reviewer_id" id="zwc-doc-reviewer"<?php echo $flow_editable ? ' required' : ''; ?>>
                                    <option value="">请选择</option>
                                    <?php if ($reviewer_legacy) : ?>
                                    <option value="<?php echo esc_attr($reviewer_current_value); ?>" selected><?php echo esc_html($reviewer_current_value !== '' ? zw_doc_flow_person_label($doc) : ''); ?>（当前）</option>
                                    <?php endif; ?>
                                    <?php foreach ($reviewer_candidates as $candidate) : ?>
                                        <?php if ((int) $candidate['user_id'] > 0 && (int) $candidate['user_id'] === $current) { continue; } ?>
                                        <?php $candidate_is_leader = ($leader_value !== '' && trim((string) $candidate['name']) === trim($leader_value)); ?>
                                        <option value="<?php echo esc_attr($candidate['value']); ?>"
                                                data-name="<?php echo esc_attr($candidate['name']); ?>"<?php echo $candidate_is_leader ? ' disabled style="color:#a7aaad"' : ''; ?><?php selected($reviewer_current_value, $candidate['value']); ?>><?php echo esc_html($candidate['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($doc) : ?>
                                    <div class="zw-doc-muted"><?php echo esc_html(zw_doc_reviewer_pool_note($flow)); ?>改派后由<strong>新审核人</strong>放行；被换下的原审核人保留查看权限（只读）。不能选择自己，也不能与分管领导为同一人。</div>
                                <?php else : ?>
                                    <div class="zw-doc-muted"><?php echo esc_html(zw_doc_reviewer_pool_note($flow)); ?>必填；不能选择自己，也不能与分管领导为同一人。「保存草稿」与「提交审核」都会校验。</div>
                                <?php endif; ?>
                            </div>
                            <div class="zw-doc-field is-half">
                                <label>抄送人员（仅流转，不出现在公文上）</label>
                                <?php if (function_exists('zw_control_search_select')) : ?>
                                    <?php zw_control_search_select(array(
                                        'id'          => 'zwc-doc-cc',
                                        'name'        => 'doc_cc_ids[]',
                                        'multiple'    => true,
                                        'value'       => $cc_selected_values,
                                        'placeholder' => '输入姓名筛选，可多选',
                                        'empty_text'  => '没有匹配的人员',
                                        'options'     => $cc_select_options,
                                    )); ?>
                                <?php else : ?>
                                    <?php // 降级：控件资源未加载时退回原生多选（表单仍可用；服务端仍是权威校验） ?>
                                    <select name="doc_cc_ids[]" multiple size="5" style="min-height:110px">
                                        <?php foreach ($cc_candidates as $candidate) : ?>
                                            <option value="<?php echo esc_attr($candidate['value']); ?>"<?php selected(in_array((string) $candidate['value'], $cc_selected_values, true)); ?>><?php echo esc_html($candidate['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <div class="zw-doc-muted">可多选；不能与审核人重复（重复项会被自动忽略）。</div>
                            </div>
                            <div class="zw-doc-field is-half">
                                <label>分管领导</label>
                                <select name="doc_leader_name" id="zwc-doc-leader">
                                    <option value="">请选择</option>
                                    <?php if ($leader_value !== '' && !in_array($leader_value, $leader_values, true)) : ?>
                                    <option value="<?php echo esc_attr($leader_value); ?>" selected><?php echo esc_html($leader_value); ?>（原值）</option>
                                    <?php endif; ?>
                                    <?php foreach ($leader_candidates as $leader_option) : ?>
                                    <option value="<?php echo esc_attr($leader_option['value']); ?>"<?php selected($leader_value, $leader_option['value']); ?>><?php echo esc_html($leader_option['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($doc && $flow_editable) : ?>
                            <div class="zw-doc-field is-wide">
                                <label>流转变更说明（可选，随流转记录留痕）</label>
                                <input type="text" name="zw_doc_flow_reason" maxlength="200" placeholder="如：原审核人已调岗，改派给现任负责人">
                            </div>
                            <div class="zw-doc-field is-wide">
                                <?php // 独立提交键 `zw_doc_flow_action`：只保存本步流转字段（正文由「保存正文」负责），服务端分发见 doc-bootstrap.php ?>
                                <button type="submit" class="button button-primary" name="zw_doc_flow_action" value="save">保存流转字段</button>
                                <span class="zw-doc-muted">只保存本步的<strong>审核人 / 抄送人 / 分管领导 / 备注</strong>，不保存正文；每次变更逐字段写入流转记录（谁、何时、从谁改成谁）。</span>
                            </div>
                            <?php endif; ?>
                            <?php zw_doc_leader_reviewer_sync_script('zwc-doc-leader', 'zwc-doc-reviewer'); ?>
                            <?php endif; // /流转区门控 ?>
                            <div class="zw-doc-field is-wide">
                                <label>备注（历史发文类型）</label>
                                <textarea name="doc_remark" rows="2" maxlength="255"><?php echo esc_textarea($doc ? (string) $doc['remark'] : ''); ?></textarea>
                            </div>
                            </div><!-- /.zwc-section-body -->
                            </div><!-- /.zwc-fields -->
                        </section>

                        <?php // TASK-1368：「流转与附件」面板已**合并进第 3 步「公文流转」**（本步不再单独存在）。 ?>
                        </div><!-- /.zwc-steps -->
                        <div class="zwc-actionbar" id="zwc-actionbar">
                            <a class="button" href="<?php echo esc_url($doc ? zw_doc_admin_url(array('view' => 'detail', 'doc_id' => (int) $doc['id'])) : zw_doc_admin_url()); ?>">返回</a>
                            <span class="zwc-actionbar-spacer"></span>
                            <button type="button" class="button" data-zw-prev data-zw-nav hidden>上一步</button>
                            <button type="button" class="button button-primary" data-zw-next="2" data-zw-nav hidden>下一步：撰写正文</button>
                            <?php // TASK-1368：三步向导 —— 原「下一步：版记与页码」与「下一步：流转与附件」合并为一条「下一步：公文流转」 ?>
                            <button type="button" class="button button-primary" data-zw-next="3" data-zw-nav hidden>下一步：公文流转</button>
                            <button class="button" type="submit" name="<?php echo $doc ? 'zwc_save_mode' : 'zw_doc_create_mode'; ?>" value="draft" data-zw-validate><?php echo $doc ? '保存正文' : '保存草稿并占用文号'; ?></button>
                            <?php if (!$doc) : ?>
                                <button class="button button-primary" type="submit" name="zw_doc_create_mode" value="submit" data-zw-validate data-zw-submit>提交审核</button>
                            <?php endif; ?>
                        </div>
                        <?php if ($doc) : ?>
                            <p class="zw-doc-muted">正文与流转字段<strong>分开保存</strong>：本行「保存正文」只写正文；<strong>审核人 / 抄送人 / 分管领导 / 备注</strong>在第 4 步用「保存流转字段」提交。审核与用印流程按原有入口进行。</p>
                        <?php endif; ?>
                    </div>

                    <div class="zwc-right">
                        <h3 style="margin-top:0">版式预览</h3>
                        <p>
                            <?php
                            // TASK-1987：`name="zw_doc_preview" value="1"` 是**预览语义的显式标记** ——
                            // 它随本次提交一起到达服务端，通用分发器（`zw_doc_handle_admin_post()`）见到即跳过，
                            // 保证「预览」永远不会被当成「保存正文 / 新建发文」（否则会跳详情页）。
                            // `formaction + formtarget + formmethod` 让整张表单 POST 到预览端点并在新窗口打开。
                            ?>
                            <button type="submit" class="button" name="zw_doc_preview" value="1" formaction="<?php echo esc_url(zw_doc_composer_preview_url()); ?>" formtarget="_blank" formmethod="post">预览当前内容</button>
                        </p>
                        <p class="zw-doc-muted" style="max-width:420px">
                            预览使用与打印/DOCX <strong>同一个渲染器</strong>，按当前输入内容渲染，<strong>不保存、不占号</strong>。
                            下方预览会**随输入自动更新**（停手 0.9 秒后），也可手动刷新。
                        </p>
                        <p>
                            <button type="button" class="button button-small" id="zwc-preview-refresh">刷新预览</button>
                            <span class="zw-doc-muted" id="zwc-preview-state" style="font-size:12px;margin-left:8px"></span>
                        </p>
                        <?php
                        // TASK-2014：与「公文版式」同源的**内嵌等比预览**（整页缩放、不裁切、固定面板）。
                        // 初始为空，由脚本按当前表单内容 POST 到预览端点后写入 srcdoc（不刷新页面、不占号）。
                        ?>
                        <?php
                        // TASK-2018（用户问「页面是不是变长了？」）：预览框高度**设上限 560px** ——
                        // 原来按 `calc(100vh - 320px)` 会随窗口变高（1000px 视口下 ≈680px），比换掉前的
                        // 46% 缩略图（A4 297mm×0.46 ≈ 516px）**更高**，把撰写页整体撑长了。
                        // 现改为 `min(calc(100vh - 320px), 560px)`：高窗口不再无限变高，也不再比旧版更长。
                        ?>
                        <div id="zwc-preview-box" style="width:100%;height:min(calc(100vh - 320px), 560px);min-height:360px;border:1px solid #dcdcde;border-radius:4px;background:#f0f0f1;overflow:hidden">
                            <iframe id="zwc-preview-frame" title="版式预览" style="width:100%;height:100%;border:0;background:#fff"></iframe>
                        </div>
                    </div>
                </div>
            </form>
            <script>
            // ===== TASK-2014：标题手动折行（≤3 行） + 内嵌预览自动刷新 =====
            (function () {
                var ta = document.getElementById('zwc-title');
                var counter = document.getElementById('zwc-title-lines');
                var frame = document.getElementById('zwc-preview-frame');
                var box = document.getElementById('zwc-preview-box');
                var state = document.getElementById('zwc-preview-state');
                var btn = document.getElementById('zwc-preview-refresh');
                var form = ta ? ta.form : null;
                var previewUrl = (btn && btn.dataset.zwPreviewUrl)
                    ? btn.dataset.zwPreviewUrl
                    : <?php echo wp_json_encode(zw_doc_composer_preview_url()); ?>;
                if (ta) {
                    btn = btn || document.getElementById('zwc-preview-refresh');
                    ta.addEventListener('input', function () {
                        var lines = String(ta.value).replace(/\r/g, '').split('\n');
                        if (lines.length > 3) {
                            ta.value = lines.slice(0, 3).join('\n');
                            lines = lines.slice(0, 3);
                        }
                        if (counter) { counter.textContent = String(lines.length); }
                    });
                }
                if (!frame || !form) { return; }
                var timer = null, inflight = false, pending = false;
                function setState(t) { if (state) { state.textContent = t; } }
                function render() {
                    if (inflight) { pending = true; return; }
                    inflight = true;
                    setState('更新中…');
                    var data = new FormData(form);
                    data.set('zw_doc_preview', '1');
                    fetch(previewUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                        .then(function (r) { return r.text(); })
                        .then(function (html) {
                            frame.srcdoc = html;
                            setState('已按当前输入更新（' + new Date().toLocaleTimeString() + '）');
                        })
                        .catch(function () { setState('预览更新失败，可点「刷新预览」重试'); })
                        .finally(function () {
                            inflight = false;
                            if (pending) { pending = false; render(); }
                        });
                }
                function schedule() {
                    if (timer) { clearTimeout(timer); }
                    timer = setTimeout(render, 900);
                }
                form.addEventListener('input', schedule);
                form.addEventListener('change', schedule);
                if (btn) { btn.addEventListener('click', function (e) { e.preventDefault(); render(); }); }
                // 首屏渲染一次（拿到「已保存内容」的版式，再随输入更新）。
                setTimeout(render, 250);
            })();
            function zwcEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
            // ===== TASK-1291（SPEC-87 §20.12）：「任」字文种 人员任命配置（JS 前缀 zwcAp*）=====
            // 铁律：**模板文案只有一份 PHP 出处**（`zw_doc_appoint_template_patterns()`）——
            // 前端只做 `%1$s` 占位替换，绝不重复拼字面句子；行模板亦由 PHP 渲染后注入（`row_tpl`）。
            // TASK-1315 修复（**线上事故**）：`row_tpl` 由 `zw_doc_composer_appoint_row_html()` 渲染，
            // 其中调用了 canonical 控件 `zw_control_search_select()`，**该控件自带 `<script>` 块**与
            // `<script type="application/json" class="zw-ss-data">…<\/script>`（`inc/controls/search-select.php`）。
            // 原写法 `JSON_UNESCAPED_SLASHES` 会使 `<\/script>` **不被转义**，浏览器在第一个 `<\/script>` 处
            // **提前闭合外层内联脚本**，导致其后 JS 与源码被当纯文本渲染（页面乱码、联动失灵、单选不切换）。
            // 修法：① 去掉 `JSON_UNESCAPED_SLASHES`（输出 `<\/script>`，JSON 解析后仍是 `<\/script>`，
            //        不影响 JS 取值）；② 再对 `</` 做一次兜底转义，防同类问题复发。
            <?php
            $zwc_ap_json = wp_json_encode(array(
                'active'         => (bool) $ap_active,
                'mode'           => $ap_active ? $ap_mode : 'branch',
                'is_hq'          => (bool) $ap_is_hq,
                'default_role'   => $ap_is_hq ? 'hq' : 'branch',
                'hq_name'        => (string) (function_exists('zw_doc_appoint_org_head_name') ? zw_doc_appoint_org_head_name() : ''),
                'patterns'       => $ap_patterns,
                'row_tpl'        => $ap_active ? zw_doc_composer_appoint_row_html('zwc-ap-new', array(), $ap_row_args) : '',
                'term_start'     => (string) $ap_term_default['start'],
                'term_end'       => (string) $ap_term_default['end'],
                'max_items'      => function_exists('zw_doc_appoint_max_items') ? (int) zw_doc_appoint_max_items() : 20,
                'auto_title'     => '',
            ), JSON_UNESCAPED_UNICODE);
            // 兜底：内联 `<script>` 内不得出现未转义的 `</`（`<\/script>` 会提前闭合脚本块）。
            $zwc_ap_json = str_replace('</', '<\/', (string) $zwc_ap_json);
            ?>
            var ZWC_AP = <?php echo $zwc_ap_json; // phpcs:ignore WordPress.Security.EscapeOutput -- 已经 JSON 编码 + `</` 转义，见上方 TASK-1315/1317 说明 ?>;
            ZWC_AP.seq = 0;
            function zwcApFmt(pattern, args) {
                return String(pattern == null ? '' : pattern).replace(/%(\d+)\$s/g, function (m, i) {
                    var v = args[parseInt(i, 10) - 1];
                    return (v === undefined || v === null) ? '' : String(v);
                });
            }
            function zwcApVal(id) { var el = document.getElementById(id); return el ? String(el.value || '') : ''; }
            function zwcApTip(text) { var el = document.getElementById('zwc-ap-tip'); if (el) { el.textContent = text || ''; } }
            // 工作范围：可见控件（分支）与隐藏域（总会）**同一份值**，取任一非空者 ⇒ 模式切换不丢。
            function zwcApScope() {
                var v1 = zwcApVal('zwc-ap-scope');
                if (v1 !== '') { return v1; }
                return zwcApVal('zwc-ap-scope-hidden');
            }
            function zwcApPad(n) { return (n < 10 ? '0' : '') + n; }
            function zwcApToday() { var d = new Date(); return d.getFullYear() + '-' + zwcApPad(d.getMonth() + 1) + '-' + zwcApPad(d.getDate()); }
            function zwcApParseDate(value) {
                var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
                if (!m) { return null; }
                var y = parseInt(m[1], 10), mo = parseInt(m[2], 10), da = parseInt(m[3], 10);
                var dt = new Date(y, mo - 1, da);
                if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== da) { return null; }
                return dt;
            }
            function zwcApFire(el) { if (el && typeof Event === 'function') { el.dispatchEvent(new Event('change', { bubbles: true })); } }
            // TASK-1335（用户口径）：正文「任期」行的日期 = **中文明文且不补零**（例：2026年10月9日），
            // 与 PHP 侧 `zw_doc_iso_to_cn_date()` **同口径**（两处必须一致，否则「默认值/填充值」会分叉）。
            // 非法或不可解析时**原样返回**（不吞数据）；这里用 Date 往返校验真实日期，等价于 PHP 的 checkdate。
            function zwcApCnDate(iso) {
                var s = String(iso || '').trim();
                var m = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(s);
                if (!m) { return s; }
                var y = parseInt(m[1], 10), mo = parseInt(m[2], 10), da = parseInt(m[3], 10);
                var dt = new Date(y, mo - 1, da);
                if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== da) { return s; }
                return y + '年' + mo + '月' + da + '日';
            }
            // 姓名（TASK-1598 / SPEC-93 · R-1）：**只读 canonical 控件的结构化属性**
            // `data-realname`（`ZWSearchSelect.getAttrs()`），不再从候选文案里正则抠「实名 X」。
            // 兼容回退：候选未提供结构化真名时，取「显示名（登录名）」的显示名部分（不做「实名」文案解析）。
            function zwcApNameFromSelection(hiddenInput) {
                var root = hiddenInput && hiddenInput.closest ? hiddenInput.closest('.zw-ss') : null;
                var attrs = (window.ZWSearchSelect && root && ZWSearchSelect.getAttrs) ? ZWSearchSelect.getAttrs(root) : {};
                var real = (attrs && attrs['data-realname']) ? String(attrs['data-realname']).trim() : '';
                if (real !== '') { return real; }
                var textEl = root ? root.querySelector('.zw-ss-input') : null;
                var label = textEl ? String(textEl.value || '').trim() : '';
                if (label === '') { return ''; }
                var cut = label.indexOf('（');
                if (cut > 0) { return label.slice(0, cut).trim(); }
                var cut2 = label.indexOf('(');
                if (cut2 > 0) { return label.slice(0, cut2).trim(); }
                return label;
            }
            // ===== TASK-1312：**模块级模式单选**（总会任命 / 分支机构任命）=====
            // 模式是唯一权威：它决定列集、字段顺序与同步去向；**同篇不再混选**（能力收窄）。
            function zwcApDeptValue(row) {
                var el = row ? row.querySelector('.zwc-ap-cell.is-dept .zw-ss-value') : null;
                return el ? String(el.value || '').trim() : '';
            }
            // 行的角色 = 模块级模式（TASK-1312 起不再按逐行「拟任命部门」推导）。
            function zwcApRowRole(row) { return ZWC_AP.mode || ZWC_AP.default_role || 'branch'; }
            function zwcApElVal(el) { return el ? String(el.value || '') : ''; }
            // 字典 key → 展示文案（TASK-1312：总会四件套的正文取值口径，与 PHP 字典同源）。
            function zwcApOptionText(selectId, key) {
                var el = document.getElementById(selectId);
                if (!el || !el.options) { return ''; }
                for (var i = 0; i < el.options.length; i++) {
                    if (String(el.options[i].value) === String(key)) { return String(el.options[i].text || ''); }
                }
                return '';
            }
            // 启用/禁用模式专属控件：`disabled` 的控件**不随表单提交** ⇒ 同名域永远只提交当前模式那一份。
            function zwcApSetEnabled(el, on) {
                if (!el) { return; }
                if (el.classList && el.classList.contains('zwc-ap-hq-term-field')) { return; } // 只读展示件
                if (on) { el.removeAttribute('disabled'); } else { el.setAttribute('disabled', 'disabled'); }
            }
            // 按模式显隐两套字段（hidden 只施加在**单元格/容器**上，绝不在控件上 ⇒ 数据不丢）。
            function zwcApApplyRole(row) {
                if (!row) { return; }
                var role = zwcApRowRole(row);
                var isHq = (role === 'hq');
                row.setAttribute('data-zw-ap-role', role);
                // TASK-1322：**字段行整体按模式切换**（.zwc-ap-line.is-branch / .is-hq）；
                // 单元格级显隐保留为防御性加固（行隐藏已足够，控件仍随表单提交 ⇒ 数据不丢）。
                Array.prototype.forEach.call(row.querySelectorAll('.zwc-ap-line.is-branch'), function (l) { l.hidden = isHq; });
                Array.prototype.forEach.call(row.querySelectorAll('.zwc-ap-line.is-hq'), function (l) { l.hidden = !isHq; });
                Array.prototype.forEach.call(row.querySelectorAll('.zwc-ap-cell.is-dept'), function (c) { c.hidden = isHq; });
                Array.prototype.forEach.call(row.querySelectorAll('.zwc-ap-cell.is-branch'), function (c) { c.hidden = isHq; });
                Array.prototype.forEach.call(row.querySelectorAll('.zwc-ap-cell.is-hq'), function (c) { c.hidden = !isHq; });
                // 任期行整体按模式显隐；行内两套输入各自按模式启停（第 2/3 行的显隐由上面的 `.zwc-ap-line` 承担）。
                Array.prototype.forEach.call(row.querySelectorAll('.zwc-ap-term-start, .zwc-ap-term-end, .zwc-ap-quick'), function (i) { i.hidden = isHq; });
                Array.prototype.forEach.call(row.querySelectorAll('.zwc-ap-hq-term-start, .zwc-ap-hq-term-end'), function (i) { i.hidden = !isHq; });
                Array.prototype.forEach.call(row.querySelectorAll('.zwc-ap-term-start, .zwc-ap-term-end, .zwc-ap-duty'), function (i) { zwcApSetEnabled(i, !isHq); });
                Array.prototype.forEach.call(row.querySelectorAll('.zwc-ap-item-org-dept, .zwc-ap-item-org-duty, .zwc-ap-item-org-term, .zwc-ap-hq-term-start, .zwc-ap-hq-term-end'), function (i) { zwcApSetEnabled(i, isHq); });
            }
            // 总会模式的模块级单值：读取真值 → 同步到**每一行**的同名隐藏域（+ 只读展示件）。
            function zwcApHqValue() {
                var out = { org_dept: '', org_duty: '', org_term_name: '', org_term_start: '', org_term_end: '' };
                ['org_dept', 'org_duty'].forEach(function (k) {
                    var el = document.querySelector('#zwc-ap-hq-grid select[data-zw-ap-hq="' + k + '"]');
                    if (el) { out[k] = String(el.value || ''); }
                });
                ['org_term_name', 'org_term_start', 'org_term_end'].forEach(function (k) {
                    var el = document.querySelector('#zwc-ap-hq-grid input[data-zw-ap-hq="' + k + '"]');
                    if (el) { out[k] = String(el.value || ''); }
                });
                return out;
            }
            function zwcApApplyHqBridge() {
                var v = zwcApHqValue();
                Array.prototype.forEach.call(document.querySelectorAll('#zwc-ap-rows .zwc-ap-row'), function (row) {
                    var map = {
                        org_dept: '.zwc-ap-item-org-dept',
                        org_duty: '.zwc-ap-item-org-duty',
                        org_term_name: '.zwc-ap-item-org-term',
                        org_term_start: '.zwc-ap-hq-term-start',
                        org_term_end: '.zwc-ap-hq-term-end'
                    };
                    Object.keys(map).forEach(function (k) {
                        var el = row.querySelector(map[k]);
                        if (el && String(el.value || '') !== v[k]) { el.value = v[k]; }
                    });
                });
            }
            // TASK-1322：改造后**没有表头** ⇒ 本函数改为按模式切换**字段行**：
            // `.zwc-ap-line.is-branch`（第 2/3 行）在总会模式隐藏、分支模式显示；`.zwc-ap-line.is-hq` 反之。
            // 函数名保留（既有调用点不变）；行内控件始终在 DOM 内并随表单提交 ⇒ 切模式往返不丢数据。
            function zwcApSyncHeaders() {
                var isHq = (ZWC_AP.mode === 'hq');
                Array.prototype.forEach.call(document.querySelectorAll('#zwc-ap-rows .zwc-ap-line.is-branch'), function (l) { l.hidden = isHq; });
                Array.prototype.forEach.call(document.querySelectorAll('#zwc-ap-rows .zwc-ap-line.is-hq'), function (l) { l.hidden = !isHq; });
                var extra = document.getElementById('zwc-ap-branch-extra');
                if (extra) { extra.hidden = isHq; }
                var scopeVisible = document.getElementById('zwc-ap-scope');
                var scopeHidden = document.getElementById('zwc-ap-scope-hidden');
                zwcApSetEnabled(scopeVisible, !isHq);
                zwcApSetEnabled(scopeHidden, isHq);
                var hqExtra = document.getElementById('zwc-ap-hq-extra');
                var hqGrid = document.getElementById('zwc-ap-hq-grid');
                if (hqExtra) { hqExtra.hidden = !isHq; }
                if (hqGrid) { hqGrid.hidden = !isHq; }
                var table = document.getElementById('zwc-ap-rows');
                if (table) { table.setAttribute('data-zw-ap-mode', ZWC_AP.mode); }
            }
            function zwcApApplyAllRoles() {
                Array.prototype.forEach.call(document.querySelectorAll('#zwc-ap-rows .zwc-ap-row'), zwcApApplyRole);
                zwcApSyncHeaders();
                if (ZWC_AP.mode === 'hq') { zwcApApplyHqBridge(); }
            }
            function zwcApSetMode(mode) {
                mode = (mode === 'hq') ? 'hq' : 'branch';
                if (ZWC_AP.mode === mode) { return; }
                ZWC_AP.mode = mode;
                ZWC_AP.default_role = mode;
                if (mode === 'hq') { zwcApApplyHqBridge(); }
                zwcApApplyAllRoles();
                zwcApSyncTitle();
                zwcApTip(mode === 'hq'
                    ? '已切换到「总会任命」：人员同步进「组织架构 → 人员」；本篇不再混选。'
                    : '已切换到「分支机构任命」：人员同步进「分支机构 → 负责人管理」；本篇不再混选。');
            }
            function zwcApRows() {
                var out = [];
                Array.prototype.forEach.call(document.querySelectorAll('#zwc-ap-rows .zwc-ap-row'), function (row) {
                    var nameEl = row.querySelector('input[name="zwc_ap_name[]"]');
                    var role = zwcApRowRole(row);
                    var rec = {
                        row: row, role: role,
                        name: nameEl ? String(nameEl.value || '').trim() : '',
                        subject: '', duty: '',
                        termStart: '', termEnd: ''
                    };
                    if (role === 'hq') {
                        // TASK-1312：总会任命的四件套是**模块级单值**，行内隐藏域已由 bridge 同步为模块真值；
                        // 正文句式取「部门名 + 组织架构职务名」，故按字典 key 找展示文案。
                        var orgDeptKey = zwcApElVal(row.querySelector('.zwc-ap-item-org-dept'));
                        var orgDutyKey = zwcApElVal(row.querySelector('.zwc-ap-item-org-duty'));
                        rec.subject = zwcApOptionText('zwc-ap-org-dept', orgDeptKey);
                        rec.duty = zwcApOptionText('zwc-ap-org-duty', orgDutyKey);
                        rec.termStart = zwcApElVal(row.querySelector('.zwc-ap-hq-term-start'));
                        rec.termEnd = zwcApElVal(row.querySelector('.zwc-ap-hq-term-end'));
                    } else {
                        rec.subject = zwcApDeptValue(row);
                        var dutyEl = row.querySelector('select.zwc-ap-duty');
                        var otherEl = row.querySelector('input.zwc-ap-duty-other');
                        var duty = dutyEl ? String(dutyEl.value || '') : '';
                        var dutyLabel = '';
                        if (dutyEl && dutyEl.selectedIndex >= 0) { dutyLabel = String(dutyEl.options[dutyEl.selectedIndex].text || ''); }
                        if (duty === 'other') { dutyLabel = (otherEl && String(otherEl.value || '').trim() !== '') ? String(otherEl.value).trim() : '其他'; }
                        rec.duty = dutyLabel;
                        rec.termStart = zwcApElVal(row.querySelector('.zwc-ap-term-start'));
                        rec.termEnd = zwcApElVal(row.querySelector('.zwc-ap-term-end'));
                    }
                    out.push(rec);
                });
                return out;
            }
            function zwcApNames() {
                return zwcApRows().map(function (r) { return r.name; }).filter(function (n) { return n !== ''; });
            }
            // 四行正文（与 PHP `zw_doc_appoint_template_lines()` 同源同序；返回 string[]）。
            function zwcApLines() {
                if (!ZWC_AP.active) { return []; }
                var P = ZWC_AP.patterns || {};
                var lines = [];
                var scope = zwcApScope().trim();
                if (scope !== '') { lines.push(zwcApFmt(P.lead, [scope])); }
                var hqStart = '', hqEnd = '', brStart = '', brEnd = '';
                zwcApRows().forEach(function (r) {
                    if (r.name === '') { return; }
                    lines.push(zwcApFmt(P.item, [r.name, r.subject, r.duty]));
                    if (r.role === 'hq') {
                        if (hqStart === '' && hqEnd === '') { hqStart = r.termStart; hqEnd = r.termEnd; }
                    } else if (brStart === '' && brEnd === '') { brStart = r.termStart; brEnd = r.termEnd; }
                });
                var isHqDoc = (ZWC_AP.mode === 'hq');
                var start = isHqDoc ? hqStart : brStart;
                var end = isHqDoc ? hqEnd : brEnd;
                if (start === '' && end === '') {
                    start = isHqDoc ? brStart : hqStart;
                    end = isHqDoc ? brEnd : hqEnd;
                }
                if (start !== '' || end !== '') { lines.push(zwcApFmt(P.term, [zwcApCnDate(start), zwcApCnDate(end)])); }
                if (lines.length) { lines.push(P.close); }
                return lines;
            }
            // 点「下一步：撰写正文」写入块编辑器：**幂等**（末几段全等则不追加；空块区先清空避免留空段）。
            function zwcApFillBody() {
                var lines = zwcApLines();
                if (!lines.length) { return false; }
                var tbody = document.querySelector('#zwc-blocks tbody');
                if (!tbody) { return false; }
                var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.zwc-row'));
                var texts = rows.map(function (r) { var ta = r.querySelector('textarea[name="zw_doc_block_text[]"]'); return ta ? String(ta.value) : ''; });
                var tail = texts.slice(-lines.length);
                var same = (tail.length === lines.length);
                if (same) {
                    for (var i = 0; i < lines.length; i++) { if (tail[i] !== lines[i]) { same = false; break; } }
                }
                if (same) { return true; }
                var nonEmpty = texts.filter(function (t) { return String(t).trim() !== ''; });
                if (nonEmpty.length === 0) { tbody.innerHTML = ''; }
                lines.forEach(function (text) { tbody.insertAdjacentHTML('beforeend', zwcRowTemplate('p', text)); });
                return true;
            }
            // 标题联动（**保护人工输入**）：仅当标题为空或等于上次自动写入值才覆盖。
            function zwcApSyncTitle() {
                if (!ZWC_AP.active) { return; }
                var el = document.getElementById('zwc-title');
                if (!el) { return; }
                var names = zwcApNames();
                if (!names.length) { return; }
                var next = zwcApFmt((ZWC_AP.patterns || {}).title, [names.join('、')]);
                var cur = String(el.value || '');
                if (cur !== '' && cur !== ZWC_AP.auto_title) {
                    zwcApTip('标题已由你手动修改，任命人员变更未同步标题。');
                    return;
                }
                el.value = next;
                ZWC_AP.auto_title = next;
            }
            // TASK-1312：切发文机关**不再自动改任命模式**（模式只由操作区单选决定，服务端以单选为权威）；
            // 发文机关仅作为「未提交模式时」的兜底派生源，故此处只刷新一次显隐、不覆盖用户选择。
            function zwcApOnOrgChange(org) {
                if (!ZWC_AP.active) { return; }
                zwcApApplyAllRoles();
            }
            function zwcApOnPersonChange(hiddenInput) {
                var row = hiddenInput && hiddenInput.closest ? hiddenInput.closest('.zwc-ap-row') : null;
                if (!row) { return; }
                var nameEl = row.querySelector('input[name="zwc_ap_name[]"]');
                if (nameEl) { nameEl.value = zwcApNameFromSelection(hiddenInput); }
                zwcApSyncTitle();
            }
            // TASK-1312：拟任命部门在**分支模式**下指定具体分支机构（值不得为协会本级名）；
            // 不再按逐行部门切换角色（角色由模块级模式唯一决定）。
            function zwcApOnDeptChange(hiddenInput) {
                var row = hiddenInput && hiddenInput.closest ? hiddenInput.closest('.zwc-ap-row') : null;
                if (!row) { return; }
                var dept = zwcApDeptValue(row);
                if (dept !== '' && ZWC_AP.hq_name !== '' && dept === ZWC_AP.hq_name) {
                    zwcApTip('分支机构任命模式的「拟任命部门」必须是具体分支机构；「' + ZWC_AP.hq_name + '」请改用上方「总会任命」。');
                } else {
                    zwcApTip('');
                }
                zwcApSyncTitle();
            }
            function zwcApAdd() {
                if (!ZWC_AP.active || !ZWC_AP.row_tpl) { return; }
                // TASK-1322：行容器不再是表格 ⇒ 直接挂到 `.zwc-ap-rows` 块容器上（不再查 `tbody`）。
                var list = document.getElementById('zwc-ap-rows');
                if (!list) { return; }
                if (list.querySelectorAll('.zwc-ap-row').length >= ZWC_AP.max_items) {
                    alert('最多 ' + ZWC_AP.max_items + ' 位任命人员。');
                    return;
                }
                ZWC_AP.seq++;
                var html = ZWC_AP.row_tpl.split('zwc-ap-new').join('zwc-ap-' + ZWC_AP.seq);
                list.insertAdjacentHTML('beforeend', html);
                var row = list.lastElementChild;
                if (window.ZWSearchSelect && row) { ZWSearchSelect.initAll(row); }
                // 分支行「任期开始」默认当前日期（用户口径）。
                var sEl = row ? row.querySelector('.zwc-ap-term-start') : null;
                if (sEl && String(sEl.value || '') === '') { sEl.value = zwcApToday(); }
                zwcApApplyRole(row);
                zwcApSyncHeaders();
                return row;
            }
            function zwcApRemove(btn) {
                var row = btn && btn.closest ? btn.closest('.zwc-ap-row') : null;
                var list = row ? row.parentNode : null;
                if (!list) { return; }
                if (list.querySelectorAll('.zwc-ap-row').length <= 1) { alert('至少保留 1 位任命人员。'); return; }
                list.removeChild(row);
                zwcApSyncHeaders();
                zwcApSyncTitle();
            }
            // 任期快捷（1/2/3 年，**逐行**）：**结束 = 开始 + N 年 − 1 天**（TASK-1319 用户口径）。
            // 闰年口径：先按「+N 年」取该日，目标月无该日则回退到该月最后一天，**再 −1 天**
            // ⇒ 2028-02-29 + 3 年 = 2031-02-28（回退）− 1 天 = **2031-02-27**。
            // 与 PHP `zw_doc_appoint_term_default_range()` **同口径**（默认值由 PHP 渲染，快捷由 JS 计算，
            // 两端必须一致，否则「默认值」与「点快捷后」会出现不同结束日）。
            function zwcApTermQuick(btn, years) {
                years = parseInt(years, 10);
                if (!years) { return; }
                var row = btn && btn.closest ? btn.closest('.zwc-ap-row') : null;
                if (!row) { return; }
                var sEl = row.querySelector('.zwc-ap-term-start');
                var eEl = row.querySelector('.zwc-ap-term-end');
                if (!sEl || !eEl) { return; }
                if (String(sEl.value || '') === '') { sEl.value = zwcApToday(); zwcApFire(sEl); }
                var dt = zwcApParseDate(sEl.value);
                if (!dt) { alert('请先填写合法的任期开始日期（yyyy-mm-dd）。'); return; }
                var y = dt.getFullYear() + years, mo = dt.getMonth(), da = dt.getDate();
                var last = new Date(y, mo + 1, 0).getDate();
                if (da > last) { da = last; }
                var end = new Date(y, mo, da);
                end.setDate(end.getDate() - 1);   // TASK-1319：满 N 年的**前一天**为任期结束日
                eEl.value = end.getFullYear() + '-' + zwcApPad(end.getMonth() + 1) + '-' + zwcApPad(end.getDate());
                zwcApFire(eEl);
            }
            (function () {
                if (!ZWC_AP.active) { return; }
                var add = document.getElementById('zwc-ap-add');
                if (add) { add.addEventListener('click', zwcApAdd); }
                // TASK-1322：变更委派挂在 `.zwc-ap-rows` 块容器上（不再是 `tbody`）。
                var list = document.getElementById('zwc-ap-rows');
                if (list) {
                    list.addEventListener('change', function (e) {
                        var t = e.target;
                        if (!t || !t.name) { return; }
                        if (t.name === 'zwc_ap_uid[]') { zwcApOnPersonChange(t); return; }
                        if (t.name === 'zwc_ap_dept[]') { zwcApOnDeptChange(t); return; }
                        if (t.name === 'zwc_ap_duty[]') {
                            var row = t.closest ? t.closest('.zwc-ap-row') : null;
                            var other = row ? row.querySelector('input.zwc-ap-duty-other') : null;
                            if (other) { other.hidden = (String(t.value || '') !== 'other'); }
                        }
                    });
                }
                // 任期快捷按钮逐行委派（data-zw-ap-term 在每行内）。
                document.addEventListener('click', function (e) {
                    var btn = e.target && e.target.closest ? e.target.closest('[data-zw-ap-term]') : null;
                    if (btn && document.getElementById('zwc-ap-rows') && document.getElementById('zwc-ap-rows').contains(btn)) {
                        zwcApTermQuick(btn, btn.getAttribute('data-zw-ap-term'));
                    }
                });
                // 「下一步：撰写正文」追加钩子（不改既有校验顺序：本步仍有必填未完成时不动正文）。
                var bar = document.getElementById('zwc-actionbar');
                if (bar) {
                    bar.addEventListener('click', function (e) {
                        var nx = e.target && e.target.closest ? e.target.closest('[data-zw-next]') : null;
                        if (!nx || String(nx.getAttribute('data-zw-next')) !== '2') { return; }
                        var form = document.getElementById('zwc-form');
                        if (form) {
                            // TASK-1329 修复（真机缺陷：正文**从不**自动填充）：闸门原为**整表单**必填统计
                            // ⇒ 只要**后续步骤**（如第 4 步审核人）还有必填为空，这里就 `return`，正文永远填不上。
                            // 而「下一步」按钮自身只校验**当前步**（向导 `missingIn(panelOf(current))`），
                            // 于是出现「按钮能跳步、正文却是空的」。修法：闸门与向导同口径 —— 只看**当前步**
                            // （= 含逐行人员区的那个步骤面板），并忽略被模式隐藏的字段。
                            var panel = document.getElementById('zwc-ap-rows');
                            panel = (panel && panel.closest) ? panel.closest('.zwc-panel') : null;
                            var gate = panel || form;
                            var miss = Array.prototype.slice.call(gate.querySelectorAll('[required]')).filter(function (f) {
                                if (String(f.value || '').trim() !== '') { return false; }
                                // 隐藏字段（如切换任命模式后隐藏的那一套）不算「本步未完成」。
                                return !!(f.offsetWidth || f.offsetHeight || f.getClientRects().length);
                            });
                            if (miss.length) { return; }
                        }
                        if (zwcApFillBody()) { zwcApTip('已按任命要素生成正文；重新点「下一步」不会重复追加。'); }
                    });
                }
                var scopeEl = document.getElementById('zwc-ap-scope');
                if (scopeEl) { scopeEl.addEventListener('change', zwcApSyncTitle); }
                // TASK-1312：操作区**模块级单选** —— 模式切换即切换字段集（服务端以单选为权威）。
                Array.prototype.forEach.call(document.querySelectorAll('#zwc-ap-mode-op input[name="zwc_ap_mode"]'), function (radio) {
                    radio.addEventListener('change', function () { zwcApSetMode(radio.value); });
                });
                // 总会模式的模块级单值控件：任一变化 ⇒ 同步到每一行隐藏域（保证服务端逐行取值一致）。
                var hqGrid = document.getElementById('zwc-ap-hq-grid');
                if (hqGrid) {
                    hqGrid.addEventListener('change', function () {
                        var v = zwcApHqValue();
                        ['org_term_name', 'org_term_start', 'org_term_end'].forEach(function (k) {
                            var el = hqGrid.querySelector('input[data-zw-ap-hq="' + k + '"]');
                            if (el) { el.value = v[k]; }
                        });
                        zwcApApplyHqBridge();
                    });
                }
                // 初始：逐行显隐 + 新建空行的分支任期默认当前日期。
                Array.prototype.forEach.call(document.querySelectorAll('#zwc-ap-rows .zwc-ap-row'), function (row) {
                    var uidEl = row.querySelector('.zwc-ap-cell.is-person .zw-ss-value');
                    var sEl = row.querySelector('.zwc-ap-term-start');
                    if (sEl && ZWC_AP.mode !== 'hq' && String(sEl.value || '') === ''
                        && !(uidEl && String(uidEl.value || '').trim() !== '')) {
                        sEl.value = zwcApToday();
                    }
                    zwcApApplyRole(row);
                });
                zwcApSyncHeaders();
                if (ZWC_AP.mode === 'hq') { zwcApApplyHqBridge(); }
                zwcApSyncTitle();
            })();
            // TASK-1271（用户拍板 A1）：发文机关 → 红头候选重建 + 署名/发文部门只读回显。
            // 服务端以 zwc_org_pick 为准覆写三处（契约 POST 也改不动），此处只负责呈现。
            (function () {
                // TASK-1272：机关选择器已换 SPEC-85 canonical 可搜索下拉 ⇒ 真值在 `.zw-ss-value` 隐藏字段，
                // canonical 控件选中后会 `dispatchEvent(new Event('change',{bubbles:true}))`，故监听它。
                var pickRoot = document.getElementById('zwc-org-pick');
                var pick = pickRoot ? pickRoot.querySelector('.zw-ss-value') : null;
                var head = document.getElementById('zwc-org');
                var sign = document.getElementById('zwc-sign');
                var dept = document.getElementById('zwc-dept');
                if (!pick || !head) { return; }
                var HEADS = <?php echo wp_json_encode($org_head_map); ?>;
                // TASK-1280（CHG-157）：署名 = 协会全称（协会本级）或 协会全称 + 机构名（分支机构）；
                // 发文部门**不加前缀**（台账字段，需与分支机构名录关联）。
                var ASSOC = <?php echo wp_json_encode(function_exists('zw_doc_head_prefix') ? (string) zw_doc_head_prefix() : '中国传统文化促进会'); ?>;
                function signOf(org) {
                    if (org === '') { return ''; }
                    return org === ASSOC ? org : ASSOC + org;
                }
                function sync() {
                    var org = pick.value;
                    var list = HEADS[org] || [];
                    if (list.length) {
                        head.innerHTML = '';
                        for (var i = 0; i < list.length; i++) {
                            var o = document.createElement('option');
                            o.value = list[i];
                            o.textContent = list[i];
                            head.appendChild(o);
                        }
                    }
                    if (sign && org !== '') { sign.value = signOf(org); }
                    if (dept && org !== '') { dept.value = org; }
                    // TASK-1291：切机关 ⇒ ②.5 人员任命配置整组按 type 互斥重渲染（服务端仍会按 type 再裁一次）。
                    if (typeof zwcApOnOrgChange === 'function') { zwcApOnOrgChange(org); }
                }
                pick.addEventListener('change', sync);
            })();
            // TASK-1271（用户拍板·问题二 A）：发文日期 ⇄ 成文日期两向通道。
            //  - 反向（既有，保留）：发文日期 → 成文日期（按钮手动带入 / 成文日期为空时自动补）。
            //  - 正向（TASK-1325 用户口径）：「落款与日期 发文日期 自动同步 成文日期」
            //    ⇒ 成文日期**变化即写入**发文日期（同一 ISO 值），用户不必再手工对表。
            (function () {
                var issue = document.getElementById('zwc-issue-date');
                var made = document.getElementById('zwc-date');
                var btn = document.getElementById('zwc-copy-date');
                var tip = document.getElementById('zwc-copy-date-tip');
                if (!issue || !made || made.type !== 'date') { return; }
                function copy() {
                    if (issue.value === '') { return; }
                    made.value = issue.value;
                    if (tip) { tip.textContent = '已按发文日期带入成文日期（会覆盖成文日期）。'; }
                }
                // 正向同步：只认标准日期控件的 `yyyy-mm-dd`（旧文本成文日期没有该形态，天然不触发）。
                // 台账栏为空时自动补（不覆盖手工值）；用户刚改过台账栏的那个回合不覆盖（保留人工覆盖权）。
                var issueTouched = false;
                function syncIssueFromMade() {
                    var v = String(made.value || '');
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { return; }
                    if (issue.type === 'date' && issue.value === v) { return; }
                    if (issue.value !== '' && issueTouched) { return; }
                    issue.value = v;
                    if (tip) { tip.textContent = '已按成文日期同步发文日期（台账）。'; }
                }
                if (btn) { btn.addEventListener('click', copy); }
                // 成文日期为空时把台账值带入（既有行为，改挂 `input` 以便文本回退控件也覆盖）。
                issue.addEventListener('input', function () {
                    issueTouched = true;
                    if (made.value === '') { copy(); }
                });
                issue.addEventListener('change', function () { if (made.value === '') { copy(); } });
                made.addEventListener('input', syncIssueFromMade);
                made.addEventListener('change', syncIssueFromMade);
                // 首屏对齐：新建默认当天 ⇒ 发文日期同步为同一天，不依赖任何后续输入。
                syncIssueFromMade();
            })();
            // TASK-1338 批次二 ⑦（D1 版记合并）：版记「印发机关 / 印发日期」为**只读回显**，
            // 真值源 = 公文要素的发文部门 / 发文日期；页面内即时镜像（服务端落库与两渲染通道按同一取值函数再算一次）。
            (function () {
                var dept = document.getElementById('zwc-dept');
                var issue = document.getElementById('zwc-issue-date');
                var outOrg = document.getElementById('zwc-print-org');
                var outDate = document.getElementById('zwc-print-date');
                function cnDate(value) {
                    return (typeof zwcApCnDate === 'function') ? zwcApCnDate(value) : String(value || '');
                }
                function mirror() {
                    if (dept && outOrg && String(dept.value || '') !== '') { outOrg.value = String(dept.value); }
                    if (issue && outDate && issue.value) { outDate.value = cnDate(issue.value); }
                }
                if (dept) { dept.addEventListener('change', mirror); }
                if (issue) { issue.addEventListener('input', mirror); issue.addEventListener('change', mirror); }
                // 首屏只在台账栏**有值**时覆盖；存量已保存的显式印发值保持不动。
                mirror();
            })();
            // TASK-1263：主送机关下拉单选 → 真值字段 zwc_receiver 同步（选「其他」放开自由输入）。
            // 无 JS 时下拉为空操作、文本框始终可输入（渐进增强，真值字段名不变）。
            (function () {
                var pick = document.getElementById('zwc-receiver-pick');
                var text = document.getElementById('zwc-receiver');
                if (!pick || !text) { return; }
                var OTHER = <?php echo wp_json_encode($receiver_other); ?>;
                function sync(byUser) {
                    var value = pick.value;
                    if (value === '') { text.value = ''; text.hidden = true; return; }
                    if (value === OTHER) {
                        text.hidden = false;
                        if (byUser) { text.value = ''; text.focus(); }
                        return;
                    }
                    text.value = value;
                    text.hidden = true;
                }
                pick.addEventListener('change', function () { sync(true); });
                // 初次只按已选项对齐（字典项填入并隐藏；「其他」/未选保持可见可编辑）
                if (pick.value !== OTHER) { sync(false); }
            })();
            function zwcRowTemplate(type, text) {
                var labels = <?php echo wp_json_encode($labels); ?>;
                var html = '<tr class="zwc-row"><td style="width:180px"><select name="zw_doc_block_type[]">';
                for (var k in labels) { if (labels.hasOwnProperty(k)) { html += '<option value="' + k + '"' + (k === type ? ' selected' : '') + '>' + labels[k] + '</option>'; } }
                html += '</select></td><td><textarea name="zw_doc_block_text[]" rows="3">' + zwcEsc(text) + '</textarea></td>'
                      + '<td style="width:96px;white-space:nowrap">'
                      + '<button type="button" class="button button-small" onclick="zwcMove(this,-1)">↑</button> '
                      + '<button type="button" class="button button-small" onclick="zwcMove(this,1)">↓</button> '
                      + '<button type="button" class="button button-small" onclick="zwcRemove(this)">×</button></td></tr>';
                return html;
            }
            function zwcAdd(type) {
                var tbody = document.querySelector('#zwc-blocks tbody');
                tbody.insertAdjacentHTML('beforeend', zwcRowTemplate(type));
            }
            function zwcRemove(btn) {
                var row = btn.closest('tr');
                var tbody = row.parentNode;
                if (tbody.querySelectorAll('tr.zwc-row').length <= 1) { alert('至少保留一段正文。'); return; }
                row.parentNode.removeChild(row);
            }
            function zwcMove(btn, dir) {
                var row = btn.closest('tr');
                if (dir < 0 && row.previousElementSibling) { row.parentNode.insertBefore(row, row.previousElementSibling); }
                if (dir > 0 && row.nextElementSibling) { row.parentNode.insertBefore(row.nextElementSibling, row); }
            }
            function zwcSplitPaste(mode) {
                var src = document.getElementById('zwc-paste');
                var msg = document.getElementById('zwc-paste-msg');
                if (!src || !src.value.trim()) { alert('请先粘贴正文内容。'); return; }
                var tbody = document.querySelector('#zwc-blocks tbody');
                var rows = tbody ? tbody.querySelectorAll('tr.zwc-row') : [];
                if (mode === 'replace' && rows.length && !confirm('将替换当前正文的 ' + rows.length + ' 个块，继续？')) { return; }
                msg.textContent = '解析中…';
                var body = new URLSearchParams();
                body.append('action', 'zw_doc_composer_split');
                body.append('_wpnonce', <?php echo wp_json_encode(wp_create_nonce('zw_doc_composer_split')); ?>);
                body.append('text', src.value);
                fetch(<?php echo wp_json_encode(zw_doc_composer_split_url()); ?>, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (d) {
                    zwcFillBlocks(d, mode, msg, tbody, '拆分');
                }).catch(function () { msg.textContent = '网络错误，拆分失败。'; });
            }
            // 「粘贴拆分」与「Word 导入」共用同一套填充逻辑（返回结构相同）
            function zwcFillBlocks(d, mode, msg, tbody, label) {
                if (!d || !d.success) { msg.textContent = (d && d.data && d.data.message) ? d.data.message : (label + '失败。'); return; }
                if (mode === 'replace') { tbody.innerHTML = ''; }
                d.data.blocks.forEach(function (b) { tbody.insertAdjacentHTML('beforeend', zwcRowTemplate(b.t, b.text)); });
                msg.textContent = d.data.message;
            }
            function zwcImportDocx(mode) {
                var input = document.getElementById('zwc-docx');
                var msg = document.getElementById('zwc-docx-msg');
                if (!input || !input.files || !input.files.length) { alert('请先选择 .docx 文件。'); return; }
                var tbody = document.querySelector('#zwc-blocks tbody');
                var rows = tbody ? tbody.querySelectorAll('tr.zwc-row') : [];
                if (mode === 'replace' && rows.length && !confirm('将替换当前正文的 ' + rows.length + ' 个块，继续？')) { return; }
                msg.textContent = '解析中…';
                var fd = new FormData();
                fd.append('action', 'zw_doc_composer_import_docx');
                fd.append('_wpnonce', <?php echo wp_json_encode(wp_create_nonce('zw_doc_composer_split')); ?>);
                fd.append('docx', input.files[0]);
                fetch(<?php echo wp_json_encode(zw_doc_composer_split_url()); ?>, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { zwcFillBlocks(d, mode, msg, tbody, '导入'); })
                    .catch(function () { msg.textContent = '网络错误，导入失败。'; });
            }
            // ===== 4 步标签向导（TASK-786；标签对齐与校验修复 TASK-1089）=====
            // 硬约束一：**同一个表单内切换显隐**，绝不发请求换页 → 已填内容不会丢。
            // 硬约束二：必填跨步可见（步骤条显示剩余必填），保存/提交时统一校验并跳到缺项所在步。
            // 硬约束三（TASK-1089）：表单已声明 novalidate —— 隐藏面板里的原生 required 会让浏览器
            //   在 submit 事件之前中止提交（控件不可聚焦，控制台报 not focusable），表现为按钮"点了没反应"；
            //   故改由本脚本统一校验，服务端校验仍是最终权威。
            var ZWC_STEPS = (function () {
                var wrap = document.getElementById('zwc-steps');
                if (!wrap) { return null; }
                wrap.classList.add('is-js'); // 只有 JS 就绪才隐藏其余面板（无 JS 时全部可见可提交）
                var panels = Array.prototype.slice.call(wrap.querySelectorAll('.zwc-panel'));
                var LAST = panels.length; // 编辑态没有第 4 步：末步由 DOM 决定，不写死 4
                var tabs = Array.prototype.slice.call(document.querySelectorAll('#zwc-stepbar .zwc-tab'));
                var hint = document.getElementById('zwc-step-hint');
                var bar = document.getElementById('zwc-actionbar');
                if (bar) { bar.classList.add('is-js'); }
                var current = 1;
                function panelOf(n) {
                    return panels.filter(function (p) { return parseInt(p.getAttribute('data-zw-step'), 10) === n; })[0] || null;
                }
                function missingIn(panel) {
                    if (!panel) { return []; }
                    return Array.prototype.slice.call(panel.querySelectorAll('[required]')).filter(function (f) { return !String(f.value || '').trim(); });
                }
                function mark(field) {
                    field.style.borderColor = '#d63638';
                    field.style.boxShadow = '0 0 0 3px rgba(214,54,56,.15)';
                }
                function unmark(field) {
                    field.style.borderColor = '';
                    field.style.boxShadow = '';
                }
                function show(n) {
                    current = Math.max(1, Math.min(LAST, n));
                    panels.forEach(function (p) { var on = parseInt(p.getAttribute('data-zw-step'), 10) === current; p.hidden = !on; if (on) { p.classList.add('is-active'); } else { p.classList.remove('is-active'); } });
                    tabs.forEach(function (t) { t.classList.toggle('is-active', parseInt(t.getAttribute('data-zw-goto'), 10) === current); });
                    if (bar) {
                        // 底部操作条与当前步骤同步：上一步/下一步/提交审核只在有意义的步骤出现
                        Array.prototype.slice.call(bar.querySelectorAll('[data-zw-prev]')).forEach(function (b) { b.hidden = current <= 1; });
                        Array.prototype.slice.call(bar.querySelectorAll('[data-zw-next]')).forEach(function (b) { b.hidden = parseInt(b.getAttribute('data-zw-next'), 10) !== current + 1; });
                        Array.prototype.slice.call(bar.querySelectorAll('[data-zw-submit]')).forEach(function (b) { b.hidden = current !== LAST; });
                    }
                    refresh();
                }
                function stepOf(el) { var p = el.closest ? el.closest('.zwc-panel') : null; return p ? parseInt(p.getAttribute('data-zw-step'), 10) : 0; }
                function refresh() {
                    // 各步完成度：统计该步内 [required] 且为空的字段
                    tabs.forEach(function (t) {
                        var n = parseInt(t.getAttribute('data-zw-goto'), 10);
                        var panel = panelOf(n);
                        var note = t.querySelector('.zwc-tab-note');
                        if (!panel || !note) { return; }
                        var req = Array.prototype.slice.call(panel.querySelectorAll('[required]'));
                        if (!req.length) { note.textContent = ''; return; }
                        var miss = req.filter(function (f) { return !String(f.value || '').trim(); });
                        note.textContent = miss.length ? ('还有 ' + miss.length + ' 项必填') : '必填已完成';
                    });
                    if (hint) {
                        // 统计全表剩余必填总数，避免用户在第 1 步不知道后面还有必填
                        var allMiss = Array.prototype.slice.call(document.querySelectorAll('#zwc-form [required]')).filter(function (f) { return !String(f.value || '').trim(); });
                        var prefix = hint.getAttribute('data-zw-prefix') || '';
                        hint.textContent = allMiss.length
                            ? (prefix + '当前还有 ' + allMiss.length + ' 项必填（' + allMiss.map(function (f) { var l = f.closest('.zw-doc-field'); var s = l ? l.querySelector('label') : null; return s ? s.textContent.replace(/\s*\*\s*$/, '') : '必填项'; }).join('、') + '），保存时会自动跳到对应步骤。')
                            : (prefix + '必填项已全部填写。');
                    }
                }
                document.querySelectorAll('#zwc-stepbar .zwc-tab').forEach(function (t) {
                    t.addEventListener('click', function () { show(parseInt(t.getAttribute('data-zw-goto'), 10)); });
                });
                if (bar) {
                    bar.addEventListener('click', function (e) {
                        var nx = e.target.closest('[data-zw-next]');
                        var pv = e.target.closest('[data-zw-prev]');
                        if (nx) {
                            var target = parseInt(nx.getAttribute('data-zw-next'), 10);
                            var miss = missingIn(panelOf(current));
                            if (miss.length) {
                                // 逐步确认：当前步必填未完成则不推进，直接定位到缺项
                                mark(miss[0]); miss[0].focus(); refresh();
                                if (hint) { hint.textContent = '本步还有 ' + miss.length + ' 项必填未完成，请先填写后再进入下一步。'; }
                                return;
                            }
                            if (target <= LAST) { show(target); }
                        }
                        if (pv) { show(current - 1); }
                    });
                }
                document.querySelectorAll('#zwc-form [required]').forEach(function (f) {
                    var sync = function () { if (String(f.value || '').trim()) { unmark(f); } refresh(); };
                    f.addEventListener('change', sync);
                    f.addEventListener('input', sync);
                    f.addEventListener('blur', sync);
                });
                // 提交时统一校验：缺项 → 跳到该步并高亮（服务端校验仍是权威）
                // 例外：右栏「预览当前内容」也是 submit 按钮（formaction 指向预览端点），不保存、不占号，
                // 不参与必填校验，必须放行（只对带 data-zw-validate 的按钮校验）。
                var form = document.getElementById('zwc-form');
                if (form) {
                    form.addEventListener('submit', function (e) {
                        var btn = e.submitter || document.activeElement;
                        if (btn && btn.getAttribute && !btn.hasAttribute('data-zw-validate')) { return; }
                        var miss = Array.prototype.slice.call(form.querySelectorAll('[required]')).filter(function (f) { return !String(f.value || '').trim(); });
                        if (!miss.length) { return; }
                        e.preventDefault();
                        var step = stepOf(miss[0]);
                        if (step) { show(step); }
                        miss[0].focus();
                        mark(miss[0]);
                        refresh();
                    });
                }
                show(1); // 初始只显示第 1 步（无 JS 时不会执行到这里 → 全部面板可见）
                return { show: show, refresh: refresh };
            })();
            function zwcReloadType() {
                var el = document.getElementById('zwc-type');
                if (!el) { return; }
                var url = new URL(window.location.href);
                url.searchParams.set('page', 'zw-doc');
                url.searchParams.set('view', 'create');
                url.searchParams.set('num_type', el.value);
                window.location.href = url.toString();
            }
            </script>
        </div>
        <?php
    }
}

if (!function_exists('zw_doc_preview_fit_script')) {
    /**
     * 预览页「A4 等比缩放」片段（TASK-2012 / TASK-2014，唯一出处）。
     *
     * 把整页内容整体 `transform:scale(min(容器宽/A4宽, 容器高/A4高))`，居中、不裁切、无横向滚动；
     * **设计器**与**新建发文撰写页**的内嵌预览都注入这一段 ⇒ 同样的观感，不再各写一套。
     *
     * @return string HTML（含 <style> 与 <script>）。
     */
    function zw_doc_preview_fit_script() {
        // TASK-2060（用户「修改预览窗口 对齐 pdf 预览能力」）：
        //   **改为 PDF 阅读器式**：按**宽度**等比缩放（fit-width）+ 多页**纵向连续** + 页间留缝 +
        //   容器内**纵向滚动**。旧版是"整篇内容缩放到一屏"（fit-both）⇒ 多页时页面被压得很小、
        //   超出部分不可见，读起来不像 PDF。
        return <<<'ZWJS'
<style>
html,body{margin:0;padding:0;background:#e9e9ec;}
body{overflow-y:auto;overflow-x:hidden;}
#zw-pdf-view{padding:12px 12px 28px;box-sizing:border-box;}
#zw-pdf-view .zw-pdf-page{position:relative;margin:0 auto 14px;background:#fff;box-shadow:0 1px 6px rgba(0,0,0,.18);}
#zw-pdf-view .zw-pdf-page > *{transform-origin:top left;position:absolute;top:0;left:0;}
/* 渲染器的「排版预览说明」块属出件免责说明，内嵌预览只看版式（出件仍完整保留）。 */
#zw-pdf-view .zw-doc-notice{display:none;}
</style>
<script>
(function(){
  var PAD = 24;
  var pages = [];

  function buildView(){
    var view = document.getElementById('zw-pdf-view');
    if (view) { return view; }
    view = document.createElement('div');
    view.id = 'zw-pdf-view';
    var sheets = Array.prototype.slice.call(document.querySelectorAll('.zw-doc-sheet'));
    document.body.insertBefore(view, document.body.firstChild);
    if (!sheets.length) {
      var only = document.createElement('div');
      while (document.body.childNodes.length > 1) {
        only.appendChild(document.body.childNodes[1]);
      }
      sheets = [only];
    }
    sheets.forEach(function (sheet) {
      var box = document.createElement('div');
      box.className = 'zw-pdf-page';
      view.appendChild(box);
      box.appendChild(sheet);
      pages.push({ box: box, sheet: sheet });
    });
    return view;
  }

  function fit(){
    if (!pages.length) { buildView(); }
    var avail = Math.max(160, window.innerWidth - PAD);
    pages.forEach(function (p) {
      var sheet = p.sheet;
      sheet.style.transform = 'none';
      sheet.style.width = '';
      var w = sheet.offsetWidth  || 794;
      var h = sheet.offsetHeight || 1123;
      var s = avail / w;
      if (!isFinite(s) || s <= 0) { s = 1; }
      p.box.style.width  = Math.round(w * s) + 'px';
      p.box.style.height = Math.round(h * s) + 'px';
      sheet.style.transform = 'scale(' + s + ')';
    });
  }

  function fitSoon(){ requestAnimationFrame(fit); }
  window.addEventListener('load', fitSoon);
  window.addEventListener('resize', fitSoon);
  setTimeout(fit, 40); setTimeout(fit, 220); setTimeout(fit, 600);
})();
</script>
ZWJS;
    }
}

if (!function_exists('zw_doc_preview_fit_html')) {
    /**
     * 给整页 HTML 注入等比缩放片段（无 `</body>` 时追加到末尾）。
     *
     * @param string $html 完整 HTML。
     * @return string
     */
    function zw_doc_preview_fit_html($html) {
        $html = (string) $html;
        $fit  = zw_doc_preview_fit_script();
        if (strpos($html, '</body>') !== false) {
            return str_replace('</body>', $fit . '</body>', $html);
        }

        return $html . $fit;
    }
}

if (!function_exists('zw_doc_composer_preview_url')) {
    /**
     * 撰写页预览端点 URL。
     *
     * @return string
     */
    function zw_doc_composer_preview_url() {
        return admin_url('admin-post.php?action=zw_doc_composer_preview');
    }
}

if (!function_exists('zw_doc_handle_composer_preview')) {
    /**
     * 预览端点：按当前输入渲染真实版式（**不保存、不占号**）。
     *
     * 鉴权：功能开关 + `tcpc_perm_doc` 能力（与模块入口一致）；正文仍经模型白名单清洗。
     *
     * @return void
     */
    function zw_doc_handle_composer_preview() {
        if (!function_exists('zw_doc_is_enabled') || !zw_doc_is_enabled()) {
            wp_die('发文管理未启用。', '不可用', array('response' => 403));
        }
        if (!current_user_can('manage_options') && !current_user_can('tcpc_perm_doc')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        // TASK-1987 + TASK-1989：预览是**同一张表单**提交的，而表单的 nonce 动作随态变化 —— 新建态
        // `zw_doc_create_document`、编辑态 `zw_doc_save_body`（渲染层 `wp_nonce_field($doc ? 'zw_doc_save_body' : …)`）。
        // 本端点原只认 `zw_doc_create_document` ⇒ 编辑态点预览必 403。
        //
        // ⚠️ **TASK-1989 生产事故（1.1.464）**：TASK-1987 曾写成
        // `check_admin_referer('zw_doc_create_document', '_wpnonce', false)` 想「不 die 地试探」——
        // 但 **`check_admin_referer()` 只有 2 个形参**（`$action, $query_arg`；`$stop` 是
        // `check_ajax_referer()` 的形参），第 3 个实参被 PHP **静默忽略** ⇒ 第一个动作校验失败时
        // **立即 `wp_nonce_ays()` + 403**，第二段 `zw_doc_save_body` 永远轮不到执行
        // （而编辑态 nonce 恰恰是 save_body）⇒ 点预览必现「您点击的链接已过期」。
        // 生产实证：`POST /wp-admin/admin-post.php?action=zw_doc_composer_preview ⇒ 403 (1204 bytes)`。
        //
        // 正确写法：用 `wp_verify_nonce()` **直接验、不 die**，两个动作任一通过即放行；
        // 都不通过时才调用一次 `check_admin_referer()` 输出 WP 原生 403（**fail-closed，不静默放行**）。
        $zw_preview_nonce = isset($_REQUEST['_wpnonce']) ? (string) wp_unslash($_REQUEST['_wpnonce']) : '';
        $zw_preview_nonce_ok = ($zw_preview_nonce !== '')
            && ((bool) wp_verify_nonce($zw_preview_nonce, 'zw_doc_create_document')
                || (bool) wp_verify_nonce($zw_preview_nonce, 'zw_doc_save_body'));
        if (!$zw_preview_nonce_ok) {
            check_admin_referer('zw_doc_create_document');
        }

        $post = wp_unslash($_POST);
        unset($post['_wpnonce'], $post['_wp_http_referer']);
        // TASK-1271（A1）：预览同样以「发文机关」为准归一三处，保证预览与保存口径一致。
        $post = zw_doc_apply_org_pick($post);

        $fallback = array(
            'title'    => isset($post['doc_title']) ? (string) $post['doc_title'] : '',
            'date'     => zw_doc_composer_cn_date(isset($post['doc_issue_date']) ? (string) $post['doc_issue_date'] : ''),
            'org_name' => '中国传统文化促进会',
            // TASK-1338 批次二 ⑦（D1 版记合并）：预览与保存同口径 —— 版记印发机关 / 印发日期取公文要素。
            'department' => isset($post['doc_department']) ? (string) $post['doc_department'] : '',
            'issue_date' => isset($post['doc_issue_date']) ? (string) $post['doc_issue_date'] : '',
        );
        $body = zw_doc_composer_body_from_post($post, $fallback);

        // TASK-2018（用户报障「份号 / 紧急程度 / 密级 / 保密期限 没有显示」）：
        // 份号默认是 `auto`（**保存时才占号**）⇒ `body_from_post()` 里 copy_no 为空 ⇒ 预览版头不显示份号。
        // 预览必须"看得见将要印什么"，故此处按**下一可用份号**补上（`zw_doc_copy_number_next_free()` 只读计算，
        // **不占号**）；真实占号仍由保存处理器执行。`none` 保持不显示、`manual` 用作者填的值。
        $zw_preview_copy_mode = isset($post['zwc_copy_no_mode']) ? sanitize_key((string) $post['zwc_copy_no_mode']) : '';
        if ($zw_preview_copy_mode === 'auto'
            && empty($body['meta']['copy_no'])
            && function_exists('zw_doc_copy_number_next_free')) {
            $zw_preview_next_copy = (string) zw_doc_copy_number_next_free();
            if ($zw_preview_next_copy !== '') {
                $body['meta']['copy_no'] = $zw_preview_next_copy;
            }
        }

        // TASK-1263：预览同样只按 20 个汉字口径展示（预览不落库，不涉及存量数据）。
        // TASK-1325（用户口径）：预览同样按文种豁免 ——「任」字号文件不写主送机关，
        // 避免「表单没这个字段、预览却有主送机关」的口径分叉。
        $preview_doc_type = isset($post['num_type']) ? (string) $post['num_type'] : '';
        if (!zw_doc_composer_receiver_applies($preview_doc_type)) {
            $body['meta']['receiver'] = '';
        } else {
            $body['meta']['receiver'] = zw_doc_composer_receiver_normalize(
                isset($body['meta']['receiver']) ? (string) $body['meta']['receiver'] : '',
                zw_doc_composer_receiver_limit()
            );
        }

        $number = '';
        if (!empty($post['reserved_number_id'])) {
            $row = function_exists('zw_doc_get_number_by_id') ? zw_doc_get_number_by_id((int) $post['reserved_number_id']) : null;
            if (is_array($row) && !empty($row['full_number'])) {
                $number = (string) $row['full_number'];
            }
        }
        if ($number === '') {
            $settings = zw_doc_number_settings();
            $type     = isset($post['num_type']) ? zw_doc_validate_doc_type((string) $post['num_type'], $settings) : false;
            if (!$type) {
                $type = (string) $settings['doc_types'][0];
            }
            $next = zw_doc_get_next_preview((int) $settings['default_year'], $type);
            $number = is_wp_error($next) ? '' : (string) $next['full_number'];
        }

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        // TASK-1990（用户报障「落款日期也不是 居中对齐落款单位」）：预览必须与**详情页版式预览 / 正式用印件
        // 同源**取渲染参数 —— 尤其**印章**：旧实现只调 `zw_doc_letterhead_render_args()`（不含印章解析）⇒
        // 「预览当前内容」**永远无章** ⇒ 落款走「不加盖印章」口径（署名、成文日期各自右空二字），
        // 成文日期因此**看起来没有居中于发文机关署名**，与用印件/右栏缩略预览不一致。
        // 改为走唯一构造器 `zw_doc_build_render_args()`：编辑态用该发文绑定的印章（seal_mode=formal），
        // 新建/未用印时按既有「默认印章回退」口径取章（TASK-1340 用户口径「所有公文都盖章」）。
        $preview_doc = array();
        if (!empty($post['doc_id']) && function_exists('zw_doc_get_document')) {
            $preview_doc_row = zw_doc_get_document((int) $post['doc_id']);
            if (is_array($preview_doc_row)) {
                $preview_doc = $preview_doc_row;
            }
        }
        $preview_overrides = array(
            'doc_number'     => $number,
            'base_url'       => get_template_directory_uri(),
            'show_toolbar'   => true,
            'auto_print'     => false,
            'document_title' => '公文版式预览（未保存）',
        );
        $preview_args = function_exists('zw_doc_build_render_args')
            ? zw_doc_build_render_args($preview_doc, $preview_overrides)
            : zw_doc_letterhead_render_args($preview_doc, $preview_overrides);
        // TASK-2014：与「公文版式」同一段等比缩放（内嵌 iframe 里整页自适应、不裁切）。
        echo zw_doc_preview_fit_html(zw_doc_render_standalone_html($body, $preview_args)); // phpcs:ignore WordPress.Security.EscapeOutput -- 渲染器内部逐字段转义；fit 片段为本模块内固定字符串
        exit;
    }
}

if (!function_exists('zw_doc_composer_handle_save')) {
    /**
     * 保存已有发文的正文（`zw_doc_action=save_body`）。
     *
     * @return void
     */
    function zw_doc_composer_handle_save() {
        // TASK-1350 顺带加固（同一提交面）：正文保存原先**未校验 nonce**，而表单**已经**输出
        // `zw_doc_save_body`（编辑态）—— 补上校验，客户端零改动。属 CSRF 加固，不改任何业务语义。
        check_admin_referer('zw_doc_save_body');
        $doc_id = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
        $doc    = zw_doc_get_document($doc_id);
        if (!$doc) {
            wp_safe_redirect(zw_doc_admin_url(array('zw_doc_msg' => '发文不存在。')));
            exit;
        }
        if (!zw_doc_can_edit_doc($doc)) {
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id, 'zw_doc_msg' => '当前状态不可编辑（已用印锁定，或你不是作者）。')));
            exit;
        }

        $post = wp_unslash($_POST);
        // TASK-1271（A1）：编辑保存同样以「发文机关」为准归一红头/署名（发文部门与发文日期为台账字段，
        // 本页编辑态不修改，故其提交值被忽略——见渲染层的只读回显）。
        $post = zw_doc_apply_org_pick($post);
        $body = zw_doc_composer_body_from_post($post, array(
            'title'    => (string) $doc['title'],
            'date'     => zw_doc_composer_cn_date((string) $doc['issue_date']),
            'org_name' => '中国传统文化促进会',
            // TASK-1338 批次二 ⑦（D1 版记合并）：发文部门 / 发文日期是版记印发机关 / 印发日期的真值源
            // （编辑态这两栏在页面上不提交，必须由服务端从台账字段补位；存量已显式保存的印发值不覆盖）。
            'department' => isset($doc['department']) ? (string) $doc['department'] : '',
            'issue_date' => isset($doc['issue_date']) ? (string) $doc['issue_date'] : '',
            // TASK-1280（CHG-157）：台账回退也走署名口径（分支机构带协会全称前缀）。
            'sign_org' => function_exists('zw_doc_sign_org')
                ? zw_doc_sign_org((string) $doc['department'])
                : (string) $doc['department'],
            // TASK-1291：本次表单若未渲染 ②.5（旧入口 / 文种不可判定），保留已存任命要素。
            'appointments' => (is_array($doc['body']) && isset($doc['body']['meta']['appointments']))
                ? (string) $doc['body']['meta']['appointments']
                : '',
        ));

        // TASK-1263：主送机关 20 个汉字上限**只在值发生变化时**施加 —— 否则编辑无正文 AST 的旧记录
        // （回退出的主送机关可能超长）会在一次无关保存里被静默截断。
        $posted_receiver = isset($post['zwc_receiver']) ? (string) $post['zwc_receiver'] : '';
        $stored_receiver = (isset($doc['body']['meta']['receiver']) && is_array($doc['body']))
            ? (string) $doc['body']['meta']['receiver']
            : '';
        // TASK-1325（用户口径）：「任」字号文件不需要主送机关 ⇒ 保存侧**同样豁免**（不接受该取值）。
        // 文种按已占文号反推（编辑态请求不带 `num_type`，与渲染层同一判据），保证渲染与保存同源。
        $save_doc_type = function_exists('zw_doc_appoint_doc_type_from_number')
            ? (string) zw_doc_appoint_doc_type_from_number(isset($doc['number']) ? (string) $doc['number'] : '')
            : '';
        if (!zw_doc_composer_receiver_applies($save_doc_type)) {
            $body['meta']['receiver'] = '';
        } elseif (zw_doc_composer_receiver_strip_colon($posted_receiver) !== zw_doc_composer_receiver_strip_colon($stored_receiver)) {
            $body['meta']['receiver'] = zw_doc_composer_receiver_normalize(
                $posted_receiver,
                zw_doc_composer_receiver_limit()
            );
        }

        // TASK-1867（CHG-362）：份号换号 —— **先占新号、再释放旧号**（`zw_doc_copy_number_bind()`），
        // 占号失败即中止保存（不写半改状态）；值未变化时不做任何台账写入（存量重号记录也能原样再保存）。
        $current_copy = (isset($doc['body']['meta']['copy_no']) && is_array($doc['body']))
            ? (string) $doc['body']['meta']['copy_no']
            : '';
        $copy_mode   = isset($post['zwc_copy_no_mode']) ? sanitize_key((string) $post['zwc_copy_no_mode']) : '';
        $copy_posted = isset($post['zwc_copy_no']) ? sanitize_text_field((string) $post['zwc_copy_no']) : '';
        // 「从空闲号中选择」下拉：无 JS 时同样生效（输入框为空则以所选号为准）。
        if ($copy_posted === '' && !empty($post['zwc_copy_no_pick'])) {
            $copy_posted = sanitize_text_field((string) $post['zwc_copy_no_pick']);
        }
        $copy_final  = zw_doc_copy_number_resolve_for_save($copy_mode, $copy_posted, $current_copy, $doc_id);
        if (is_wp_error($copy_final)) {
            wp_safe_redirect(zw_doc_admin_url(array(
                'view'       => 'compose',
                'doc_id'     => $doc_id,
                'zw_doc_msg' => $copy_final->get_error_message(),
            )));
            exit;
        }
        $copy_final = (string) $copy_final;
        if ($copy_final !== $current_copy) {
            $bound = zw_doc_copy_number_bind($doc_id, $copy_final);
            if (is_wp_error($bound)) {
                wp_safe_redirect(zw_doc_admin_url(array(
                    'view'       => 'compose',
                    'doc_id'     => $doc_id,
                    'zw_doc_msg' => $bound->get_error_message(),
                )));
                exit;
            }
        }
        $body['meta']['copy_no'] = $copy_final;

        $result = zw_doc_update_body($doc_id, $body, get_current_user_id());
        $msg    = is_wp_error($result) ? $result->get_error_message() : '正文已保存。';
        wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id, 'zw_doc_msg' => $msg)));
        exit;
    }
}

if (!function_exists('zw_doc_composer_handle_flow_save')) {
    /**
     * 编辑态第 4 步「保存流转字段」的处理器（TASK-1350）。
     *
     * 与正文保存**完全分开**：只写流转 meta（审核人 / 抄送人 / 分管领导 / 备注），
     * **不写正文、不迁移状态**（避免在 `approved` 上改派重新广播 `zw_doc_flow_completed`）。
     * 权限判定用**专属**判据 `zw_doc_flow_can_edit()`（不得复用正文判据）；字段级校验在
     * `zw_doc_update_flow()` 内复用 TASK-1345 / 1347 的候选白名单与互斥规则。
     *
     * @return void
     */
    function zw_doc_composer_handle_flow_save() {
        // 编辑态表单的 nonce 动作就是 `zw_doc_save_body`（渲染层 `wp_nonce_field($doc ? 'zw_doc_save_body' : …)`）；
        // 本处理器只在编辑态可达，故用同一动作校验（改派是**权限变更**，必须校验 Referer/nonce）。
        check_admin_referer('zw_doc_save_body');
        $doc_id = isset($_POST['doc_id']) ? (int) $_POST['doc_id'] : 0;
        $doc    = $doc_id > 0 ? zw_doc_get_document($doc_id) : null;
        if (!$doc) {
            wp_safe_redirect(zw_doc_admin_url(array('zw_doc_msg' => '发文不存在。')));
            exit;
        }
        if (!zw_doc_flow_can_edit($doc)) {
            // 与权限矩阵同源的理由文案（含「已用印锁定」与「待审核仅管理员可改派」两种情形）。
            wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id, 'zw_doc_msg' => '当前状态不可编辑流转字段（已用印锁定，或该状态下你无权改派）。')));
            exit;
        }

        $post   = wp_unslash($_POST);
        $fields = array(
            'reviewer_value' => isset($post['doc_reviewer_id']) ? sanitize_text_field((string) $post['doc_reviewer_id']) : '',
            'cc_values'      => isset($post['doc_cc_ids']) ? array_map('strval', (array) $post['doc_cc_ids']) : array(),
            'leader_name'    => isset($post['doc_leader_name']) ? sanitize_text_field((string) $post['doc_leader_name']) : '',
            'remark'         => isset($post['doc_remark']) ? sanitize_textarea_field((string) $post['doc_remark']) : (string) $doc['remark'],
        );
        $reason = isset($post['zw_doc_flow_reason']) ? sanitize_text_field((string) $post['zw_doc_flow_reason']) : '';

        $result = zw_doc_update_flow($doc, $fields, get_current_user_id(), $reason);
        if (is_wp_error($result)) {
            $msg = $result->get_error_message();
        } elseif (empty($result['changed'])) {
            $msg = '流转字段未发生变化。';
        } else {
            $msg = '流转字段已保存（' . count($result['changed']) . ' 项变更）：' . implode('；', $result['changed']);
        }
        wp_safe_redirect(zw_doc_admin_url(array('view' => 'detail', 'doc_id' => $doc_id, 'zw_doc_msg' => $msg)));
        exit;
    }
}

if (!function_exists('zw_doc_composer_split_url')) {
    /**
     * 「智能拆分」JSON 端点 URL。
     *
     * @return string
     */
    function zw_doc_composer_split_url() {
        return admin_url('admin-post.php?action=zw_doc_composer_split');
    }
}

if (!function_exists('zw_doc_handle_composer_split')) {
    /**
     * 「智能拆分」端点：整篇粘贴文本 → 正文块 JSON。
     *
     * 解析逻辑**只有一份 PHP 实现**（`zw_doc_body_blocks_from_paste()`），前端不重复实现，
     * 避免「浏览器按 A 拆、保存时按 B 拆」的漂移。
     *
     * @return void
     */
    function zw_doc_handle_composer_split() {
        if (!function_exists('zw_doc_is_enabled') || !zw_doc_is_enabled()) {
            wp_send_json_error(array('message' => '发文管理未启用。'), 403);
        }
        if (!current_user_can('manage_options') && !current_user_can('tcpc_perm_doc')) {
            wp_send_json_error(array('message' => '无权限。'), 403);
        }
        check_admin_referer('zw_doc_composer_split');

        $text = isset($_POST['text']) ? (string) wp_unslash($_POST['text']) : '';
        if (trim($text) === '') {
            wp_send_json_error(array('message' => '请先粘贴正文内容。'));
        }

        $blocks = zw_doc_body_blocks_from_paste($text);
        if (function_exists('is_wp_error') && is_wp_error($blocks)) {
            wp_send_json_error(array('message' => $blocks->get_error_message()));
        }

        $counts = array('h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'p' => 0);
        foreach ($blocks as $block) {
            if (isset($counts[$block['t']])) {
                $counts[$block['t']]++;
            }
        }

        wp_send_json_success(array(
            'blocks'  => $blocks,
            'count'   => count($blocks),
            'counts'  => $counts,
            'message' => '已解析 ' . count($blocks) . ' 段（标题 ' . ($counts['h1'] + $counts['h2'] + $counts['h3'] + $counts['h4']) . ' 个 / 正文段 ' . $counts['p'] . ' 个）；手写序数已剥离，由系统重新编号。',
        ));
    }
}
