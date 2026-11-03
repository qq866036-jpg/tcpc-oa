<?php
/**
 * 收文管理 · 自动办理（批示库 + 20 秒自动签阅）· OA-2468。
 *
 * ## 用户拍板的两条口径（原话）
 *
 * > 「领导批示 和 签阅 为我根据 政治站位编一个 批示库，签阅 在收文20秒后设置一个签名 时间戳即可」
 *
 * 落地：
 *   1. **批示库**：按政治站位分类的批示语模板（后台可增删改）；系统按「关键词 → 类别 → 轮换」选一条写入，
 *      记为 `system:auto-instruction`（时间线灰标「系统自动（批示库）」）。
 *   2. **自动签阅**：登记成功后 **20 秒** 由 WP-Cron 写一条 `sign` 留痕，`created_at` 即**签名时间戳**，
 *      记为 `system:auto-sign`（时间线灰标「系统自动签阅」）。
 *
 * ## 诚实与可审计（不可让步）
 *
 * - **不假冒领导账号**：`author_login` 一律 `system:*`，`author_name` 写明「系统自动」；
 *   页面据此渲染灰标，人工与自动在时间线上分得开。
 * - **不伪造事实**：批示是「系统按库生成」而非「某领导批示」；签阅是「系统时间戳」而非「某甲已阅」。
 * - **可一键关**：两个开关（`auto_instruction` / `auto_sign`）默认**开**（用户要求），关掉即零行为。
 * - **不新增表/列**：复用 `zw_receipt_handle_log`，新增内部动作 `sign`（仅内部允许，人工表单不出现）。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_RECEIPT_AUTO_SIGN_HOOK')) {
    define('ZW_RECEIPT_AUTO_SIGN_HOOK', 'zw_receipt_auto_sign_event');
}
if (!defined('ZW_RECEIPT_AUTO_SIGN_DELAY')) {
    // 用户口径：收文后 20 秒。
    define('ZW_RECEIPT_AUTO_SIGN_DELAY', 20);
}
if (!defined('ZW_RECEIPT_AUTO_SYSTEM_SIGN')) {
    define('ZW_RECEIPT_AUTO_SYSTEM_SIGN', 'system:auto-sign');
}
if (!defined('ZW_RECEIPT_AUTO_SYSTEM_INSTRUCTION')) {
    define('ZW_RECEIPT_AUTO_SYSTEM_INSTRUCTION', 'system:auto-instruction');
}
if (!defined('ZW_RECEIPT_AUTO_OPTION')) {
    define('ZW_RECEIPT_AUTO_OPTION', 'zw_receipt_auto_settings');
}
if (!defined('ZW_RECEIPT_AUTO_LIBRARY_OPTION')) {
    define('ZW_RECEIPT_AUTO_LIBRARY_OPTION', 'zw_receipt_instruction_library');
}

if (!function_exists('zw_receipt_auto_switches')) {
    /**
     * 自动办理开关（唯一出处：默认值 + 说明 + 事件名）。
     *
     * @return array<string,array<string,string|int>>
     */
    function zw_receipt_auto_switches() {
        return array(
            'auto_instruction' => array(
                'label'   => '自动批示（按批示库生成）',
                'default' => 1,
                'note'    => '开启后，收文登记成功即按「关键词 → 类别 → 轮换」从批示库取一条批示写入；'
                    . '记为「系统自动（批示库）」，不假冒领导账号。可随时关闭改回人工批示。',
            ),
            'auto_sign'        => array(
                'label'   => '自动签阅（收文 20 秒后写签名时间戳）',
                'default' => 1,
                'note'    => '开启后，收文登记 20 秒后由 WP-Cron 写一条「系统自动签阅」留痕，行时间即签名时间戳；'
                    . '可随时关闭改回人工签阅。',
            ),
            'auto_urge'        => array(
                'label'   => '自动催办（时限前 N 天写催办留痕）',
                'default' => 0,
                'note'    => '开启后，每日 08:30 扫描「未收口且时限在 N 天内」的办理单，写一条催办留痕'
                    . '（系统自动催办）；同一办理单同一期限点只催一次。默认关，先观察再开。',
            ),
            'auto_dispatch'    => array(
                'label'   => '自动派单（按部委规则生成办理单）',
                'default' => 0,
                'note'    => '开启后，新登记的收文按「部委 → 承办部门 + 默认天数」规则自动生成办理单'
                    . '（走既有派单写入器）；无规则/部门不存在则不派。默认关，只对新登记生效。',
            ),
            'auto_archive'     => array(
                'label'   => '自动归档（办结后写归档年度/盒号）',
                'default' => 0,
                'note'    => '开启后，每日扫描「办理单全部收口且未归档」的收文，按模板生成归档盒号并写归档年度'
                    . '（盒号重复会自动换号重试）；无办理单的收文也视为收口。默认关。',
            ),
        );
    }
}

if (!function_exists('zw_receipt_auto_settings')) {
    /**
     * 运行时开关取值（option 与默认值合并）。
     *
     * @return array<string,int>
     */
    function zw_receipt_auto_settings() {
        $stored = function_exists('get_option') ? get_option(ZW_RECEIPT_AUTO_OPTION, array()) : array();
        $stored = is_array($stored) ? $stored : array();
        $out    = array();
        foreach (zw_receipt_auto_switches() as $key => $meta) {
            $out[$key] = isset($stored[$key]) ? (int) $stored[$key] : (int) $meta['default'];
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_auto_enabled')) {
    /**
     * 单个开关是否开启。
     *
     * @param string $key 开关键。
     * @return bool
     */
    function zw_receipt_auto_enabled($key) {
        $settings = zw_receipt_auto_settings();
        return !empty($settings[$key]);
    }
}

if (!function_exists('zw_receipt_auto_library_defaults')) {
    /**
     * 批示库默认模板（**政治站位口径**；全部为可编辑模板，不含具体人名与具体事件）。
     *
     * @return array<string,array{label:string,keywords:string[],phrases:string[]}>
     */
    function zw_receipt_auto_library_defaults() {
        return array(
            'implement' => array(
                'label'    => '贯彻落实类',
                'keywords' => array('贯彻', '落实', '学习', '精神', '意见', '决定'),
                'phrases'  => array(
                    '请认真学习领会中央有关精神，结合实际抓好贯彻落实，重要情况及时报告。',
                    '请提高政治站位，把此项工作纳入年度重点任务，明确责任分工，确保落实到位。',
                    '请对照上级要求逐项细化措施，压实责任，抓好督导落实。',
                ),
            ),
            'request'   => array(
                'label'    => '请示报告类',
                'keywords' => array('请示', '报告', '报请', '申请', '审批'),
                'phrases'  => array(
                    '请相关处室牵头研究提出意见，按程序报批。',
                    '请会领导阅示，相关处室提出建议后按程序办理。',
                    '请对请示事项认真研究，形成书面意见报会领导审定。',
                ),
            ),
            'deploy'    => array(
                'label'    => '部署落实类',
                'keywords' => array('通知', '部署', '安排', '开展', '实施', '组织'),
                'phrases'  => array(
                    '请按通知要求抓好落实，明确责任人与完成时限，落实情况及时反馈。',
                    '请结合我会实际制定实施方案，做好统筹协调与督促检查。',
                    '请及时传达学习，确保相关人员知晓并抓好执行。',
                ),
            ),
            'inspect'   => array(
                'label'    => '督查整改类',
                'keywords' => array('整改', '督查', '检查', '通报', '问题', '清单'),
                'phrases'  => array(
                    '请对照问题清单逐项整改，建立台账、限期销号，整改情况报会领导。',
                    '请举一反三开展自查，健全制度机制，防止问题反弹。',
                    '请明确整改责任人和完成时限，定期报告进展。',
                ),
            ),
            'hr'        => array(
                'label'    => '人事管理类',
                'keywords' => array('任免', '干部', '人事', '招聘', '职称', '编制'),
                'phrases'  => array(
                    '请按干部管理权限和有关规定程序办理。',
                    '请做好沟通说明，按程序报批后实施。',
                    '请严格审核把关，确保程序合规、材料齐全。',
                ),
            ),
            'finance'   => array(
                'label'    => '财务管理类',
                'keywords' => array('经费', '预算', '财务', '资金', '报销', '审计'),
                'phrases'  => array(
                    '请按财务管理制度和预算安排审核办理，严守财经纪律。',
                    '请核实经费来源与支出依据，按程序报批。',
                    '请做好台账登记与凭证归档，接受监督检查。',
                ),
            ),
            'routine'   => array(
                'label'    => '常规阅办类',
                'keywords' => array(),
                'phrases'  => array(
                    '请阅处。按职责分工办理，重要事项报会领导。',
                    '请相关处室阅办，办理结果留存备查。',
                    '请传阅知悉，按分工做好后续工作。',
                ),
            ),
        );
    }
}

if (!function_exists('zw_receipt_auto_library')) {
    /**
     * 批示库（默认值 + option 覆盖；option 里同 key 的 phrases 覆盖默认 phrases）。
     *
     * @return array<string,array{label:string,keywords:string[],phrases:string[]}>
     */
    function zw_receipt_auto_library() {
        $library = zw_receipt_auto_library_defaults();
        $stored  = function_exists('get_option') ? get_option(ZW_RECEIPT_AUTO_LIBRARY_OPTION, array()) : array();
        if (!is_array($stored)) {
            return $library;
        }
        foreach ($library as $key => $meta) {
            if (!isset($stored[$key]) || !is_array($stored[$key])) {
                continue;
            }
            $phrases = array();
            if (isset($stored[$key]['phrases']) && is_array($stored[$key]['phrases'])) {
                foreach ($stored[$key]['phrases'] as $one) {
                    $one = trim((string) $one);
                    if ($one !== '') {
                        $phrases[] = $one;
                    }
                }
            }
            if ($phrases) {
                $library[$key]['phrases'] = $phrases;
            }
            if (isset($stored[$key]['keywords']) && is_array($stored[$key]['keywords'])) {
                $kw = array();
                foreach ($stored[$key]['keywords'] as $one) {
                    $one = trim((string) $one);
                    if ($one !== '') {
                        $kw[] = $one;
                    }
                }
                if ($kw) {
                    $library[$key]['keywords'] = $kw;
                }
            }
        }
        return $library;
    }
}

if (!function_exists('zw_receipt_auto_match_category')) {
    /**
     * 纯函数：按标题/来文单位/OCR 文本关键词命中的批示库类别。
     *
     * 命中顺序固定（先紧后松）：督查整改 → 请示报告 → 人事 → 财务 → 贯彻落实 → 部署落实 → 常规阅办。
     *
     * @param array $library 批示库。
     * @param string $haystack 待匹配文本（标题 + 来文字号 + 来文单位 + OCR 文本截断）。
     * @return string 类别 key。
     */
    function zw_receipt_auto_match_category($library, $haystack) {
        $haystack = (string) $haystack;
        $order    = array('inspect', 'request', 'hr', 'finance', 'implement', 'deploy');
        foreach ($order as $key) {
            if (!isset($library[$key])) {
                continue;
            }
            foreach ((array) $library[$key]['keywords'] as $kw) {
                if ($kw !== '' && strpos($haystack, $kw) !== false) {
                    return $key;
                }
            }
        }
        return 'routine';
    }
}

if (!function_exists('zw_receipt_auto_pick_instruction')) {
    /**
     * 纯函数：从批示库挑一条（确定性轮换，同一个收文永远同一条，便于复现与审计）。
     *
     * @param array  $library 批示库。
     * @param string $category 类别 key。
     * @param string $receipt_id 收文记录 ID。
     * @return array{category:string,label:string,phrase:string,index:int}
     */
    function zw_receipt_auto_pick_instruction($library, $category, $receipt_id) {
        if (!isset($library[$category])) {
            $category = 'routine';
        }
        $phrases = isset($library[$category]['phrases']) ? (array) $library[$category]['phrases'] : array();
        if (!$phrases && isset($library['routine']['phrases'])) {
            $category = 'routine';
            $phrases  = (array) $library['routine']['phrases'];
        }
        $count = count($phrases);
        if ($count === 0) {
            return array('category' => $category, 'label' => '', 'phrase' => '', 'index' => -1);
        }
        // 确定性：crc32(记录 ID + 类别) 决定用第几条（同一收文重复调用结果一致）。
        $index = (int) (crc32((string) $receipt_id . '|' . $category) % $count);
        return array(
            'category' => $category,
            'label'    => isset($library[$category]['label']) ? (string) $library[$category]['label'] : '',
            'phrase'   => (string) $phrases[$index],
            'index'    => $index,
        );
    }
}

if (!function_exists('zw_receipt_auto_haystack')) {
    /**
     * 匹配文本：标题 + 来文字号 + 来文单位 + OCR 文本前 2000 字（有则加）。
     *
     * @param array $row 收文行（台账行视图字段即可）。
     * @return string
     */
    function zw_receipt_auto_haystack($row) {
        $row  = is_array($row) ? $row : array();
        $text = (string) (isset($row['title']) ? $row['title'] : (isset($row['doc_name']) ? $row['doc_name'] : ''));
        $text .= ' ' . (string) (isset($row['doc_no']) ? $row['doc_no'] : '');
        $text .= ' ' . (string) (isset($row['org_label']) ? $row['org_label'] : '');
        if (function_exists('zw_ocr_record')) {
            $ocr = zw_ocr_record(isset($row['id']) ? (string) $row['id'] : '');
            if (!empty($ocr['ocr_text'])) {
                $ocr_text = (string) $ocr['ocr_text'];
                $text .= ' ' . (function_exists('mb_substr') ? mb_substr($ocr_text, 0, 2000) : substr($ocr_text, 0, 2000));
            }
        }
        return $text;
    }
}

if (!function_exists('zw_receipt_auto_has_action')) {
    /**
     * 该收文是否已有某类动作（幂等判据）。
     *
     * @param string $receipt_id 收文 ID。
     * @param string $action     动作（instruction / sign）。
     * @param string $system_tag 系统来源标识（可为空 = 不判来源）。
     * @return bool
     */
    function zw_receipt_auto_has_action($receipt_id, $action, $system_tag = '') {
        if (!function_exists('zw_receipt_handle_list')) {
            return false;
        }
        foreach (zw_receipt_handle_list($receipt_id) as $row) {
            if (!isset($row['action']) || (string) $row['action'] !== (string) $action) {
                continue;
            }
            if ($system_tag === '') {
                return true;
            }
            if (isset($row['author_login']) && (string) $row['author_login'] === (string) $system_tag) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('zw_receipt_auto_apply_instruction')) {
    /**
     * 自动写一条批示（按批示库）。幂等：同一收文已存在自动批示则跳过。
     *
     * @param string $receipt_id 收文 ID。
     * @return true|WP_Error true=已写入或已存在；WP_Error=失败。
     */
    function zw_receipt_auto_apply_instruction($receipt_id) {
        $receipt_id = function_exists('zw_receipt_handle_sanitize_id')
            ? zw_receipt_handle_sanitize_id($receipt_id)
            : trim((string) $receipt_id);
        if ($receipt_id === '') {
            return new WP_Error('zw_receipt_auto_bad_id', '收文记录 ID 非法。');
        }
        if (!zw_receipt_auto_enabled('auto_instruction')) {
            return true; // 关=零行为
        }
        if (zw_receipt_auto_has_action($receipt_id, 'instruction', ZW_RECEIPT_AUTO_SYSTEM_INSTRUCTION)) {
            return true; // 幂等
        }
        if (!function_exists('zw_receipt_handle_add')) {
            return new WP_Error('zw_receipt_auto_no_writer', '办理留痕写入器未加载。');
        }

        $row = function_exists('zw_receipt_ledger_row_by_id') ? zw_receipt_ledger_row_by_id($receipt_id) : array();
        if (!$row) {
            return new WP_Error('zw_receipt_auto_no_receipt', '找不到该收文（或不是 origin=incoming 的来文）。');
        }
        $view  = function_exists('zw_receipt_ledger_row_view') ? zw_receipt_ledger_row_view($row) : $row;
        $lib   = zw_receipt_auto_library();
        $cat   = zw_receipt_auto_match_category($lib, zw_receipt_auto_haystack($view));
        $pick  = zw_receipt_auto_pick_instruction($lib, $cat, $receipt_id);
        if ($pick['phrase'] === '') {
            return new WP_Error('zw_receipt_auto_library_empty', '批示库为空（请先在「收文自动化」里填写批示语）。');
        }

        $result = zw_receipt_handle_add(array(
            'receipt_id' => $receipt_id,
            'action'     => 'instruction',
            'content'    => $pick['phrase'],
        ), array('sign'), ZW_RECEIPT_AUTO_SYSTEM_INSTRUCTION);
        if (is_wp_error($result)) {
            return $result;
        }
        return true;
    }
}

if (!function_exists('zw_receipt_auto_schedule_sign')) {
    /**
     * 排一次「20 秒后自动签阅」。
     *
     * @param string $receipt_id 收文 ID。
     * @return bool 是否已排期（已签阅/已排期返回 false）。
     */
    function zw_receipt_auto_schedule_sign($receipt_id) {
        $receipt_id = (string) $receipt_id;
        if ($receipt_id === '' || !zw_receipt_auto_enabled('auto_sign')) {
            return false;
        }
        if (zw_receipt_auto_has_action($receipt_id, 'sign', ZW_RECEIPT_AUTO_SYSTEM_SIGN)) {
            return false;
        }
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(ZW_RECEIPT_AUTO_SIGN_HOOK, array($receipt_id));
        }
        if (function_exists('wp_schedule_single_event')) {
            return (bool) wp_schedule_single_event(time() + (int) ZW_RECEIPT_AUTO_SIGN_DELAY, ZW_RECEIPT_AUTO_SIGN_HOOK, array($receipt_id));
        }
        // 无 cron 通道（CLI / 测试）：当场写，保证行为可验证。
        zw_receipt_auto_sign($receipt_id);
        return true;
    }
}

if (!function_exists('zw_receipt_auto_sign')) {
    /**
     * 写「系统自动签阅」留痕（行时间即签名时间戳）。幂等：只写一次。
     *
     * @param string $receipt_id 收文 ID。
     * @return bool
     */
    function zw_receipt_auto_sign($receipt_id) {
        $receipt_id = function_exists('zw_receipt_handle_sanitize_id')
            ? zw_receipt_handle_sanitize_id($receipt_id)
            : trim((string) $receipt_id);
        if ($receipt_id === '' || !zw_receipt_auto_enabled('auto_sign')) {
            return false;
        }
        if (zw_receipt_auto_has_action($receipt_id, 'sign', ZW_RECEIPT_AUTO_SYSTEM_SIGN)) {
            return true;
        }
        if (!function_exists('zw_receipt_handle_add')) {
            return false;
        }
        $stamp  = function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s');
        $result = zw_receipt_handle_add(array(
            'receipt_id' => $receipt_id,
            'action'     => 'sign',
            'content'    => '系统自动签阅：收文后 ' . (int) ZW_RECEIPT_AUTO_SIGN_DELAY . ' 秒写入签名时间戳（' . $stamp . '）',
        ), array('sign'), ZW_RECEIPT_AUTO_SYSTEM_SIGN);
        return !is_wp_error($result);
    }
}

if (!function_exists('zw_receipt_auto_after_register')) {
    /**
     * 登记成功后的唯一入口（单一触发点）：自动批示 + 排 20 秒自动签阅。
     *
     * @param string $receipt_id 收文 ID。
     * @return array{instruction:string,sign:string}
     */
    function zw_receipt_auto_after_register($receipt_id) {
        $out = array('instruction' => 'off', 'sign' => 'off');
        if (zw_receipt_auto_enabled('auto_instruction')) {
            $r = zw_receipt_auto_apply_instruction($receipt_id);
            $out['instruction'] = is_wp_error($r) ? ('error:' . $r->get_error_code()) : 'done';
        }
        if (zw_receipt_auto_enabled('auto_dispatch')) {
            $r = zw_receipt_auto_dispatch($receipt_id);
            $out['dispatch'] = is_wp_error($r) ? ('error:' . $r->get_error_code()) : (is_array($r) ? (string) $r['result'] : 'done');
        }
        if (zw_receipt_auto_enabled('auto_sign')) {
            $out['sign'] = zw_receipt_auto_schedule_sign($receipt_id) ? 'scheduled' : 'skipped';
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_auto_register_hooks')) {
    /**
     * 注册 cron 回调（只在开关开启时挂）。
     *
     * @return void
     */
    function zw_receipt_auto_register_hooks() {
        if (!function_exists('add_action')) {
            return;
        }
        add_action(ZW_RECEIPT_AUTO_SIGN_HOOK, 'zw_receipt_auto_sign', 10, 1);
    }
}

if (function_exists('add_action')) {
    zw_receipt_auto_register_hooks();
}

if (!function_exists('zw_receipt_auto_settings_url')) {
    /**
     * 「收文自动化」设置视图地址（台账页 `view=auto`）。
     *
     * @return string
     */
    function zw_receipt_auto_settings_url() {
        $base = function_exists('admin_url') ? (string) admin_url('admin.php') : 'admin.php';
        $args = array('page' => 'zw-receipt-ledger', 'view' => 'auto');
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($args, $base);
        }
        return $base . '?' . http_build_query($args);
    }
}

if (!defined('ZW_RECEIPT_AUTO_SAVE_NONCE')) {
    define('ZW_RECEIPT_AUTO_SAVE_NONCE', 'zw_receipt_auto_save');
}

if (!function_exists('zw_receipt_auto_handle_save')) {
    /**
     * 保存自动化设置（开关 + 批示库）。
     *
     * 批示库表单形态：每个类别一个 textarea，一行一条批示语（**空行忽略**）；关键词一行多个用顿号/逗号分隔。
     *
     * @return void
     */
    function zw_receipt_auto_handle_save() {
        if (function_exists('current_user_can') && !current_user_can('tcpc_perm_doc')) {
            wp_die('无权限操作收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
        }
        check_admin_referer(ZW_RECEIPT_AUTO_SAVE_NONCE, 'zw_receipt_auto_nonce');

        $settings = array();
        foreach (zw_receipt_auto_switches() as $key => $meta) {
            $settings[$key] = (isset($_POST[$key]) && (string) wp_unslash($_POST[$key]) === '1') ? 1 : 0;
        }
        update_option(ZW_RECEIPT_AUTO_OPTION, $settings);

        // OA-2472：催办提前量 + 派单规则（一行一条：部委 key | 部门 key | 天数）
        if (isset($_POST['auto_urge_days'])) {
            update_option(ZW_RECEIPT_AUTO_URGE_DAYS_OPTION, (string) max(0, min(30, (int) wp_unslash($_POST['auto_urge_days']))));
        }
        if (isset($_POST['auto_archive_template'])) {
            $tpl = trim((string) wp_unslash($_POST['auto_archive_template']));
            update_option(ZW_RECEIPT_AUTO_ARCHIVE_TEMPLATE_OPTION, $tpl);
        }
        if (isset($_POST['auto_dispatch_rules'])) {
            $rules = array();
            $raw   = (string) wp_unslash($_POST['auto_dispatch_rules']);
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                $parts = array_map('trim', explode('|', $line));
                if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                    continue;
                }
                $rules[$parts[0]] = array(
                    'dept' => $parts[1],
                    'days' => isset($parts[2]) ? max(1, min(60, (int) $parts[2])) : 5,
                );
            }
            update_option(ZW_RECEIPT_AUTO_DISPATCH_RULES_OPTION, $rules);
        }

        $library = array();
        foreach (zw_receipt_auto_library_defaults() as $key => $meta) {
            $raw   = isset($_POST['lib_' . $key]) ? (string) wp_unslash($_POST['lib_' . $key]) : null;
            $rawkw = isset($_POST['kw_' . $key]) ? (string) wp_unslash($_POST['kw_' . $key]) : null;
            if ($raw === null && $rawkw === null) {
                continue;
            }
            $one = array();
            if ($rawkw !== null) {
                $kw = array();
                foreach (preg_split('/[、,，\s]+/u', $rawkw) as $piece) {
                    $piece = trim((string) $piece);
                    if ($piece !== '') {
                        $kw[] = $piece;
                    }
                }
                $one['keywords'] = $kw;
            }
            if ($raw !== null) {
                $phrases = array();
                foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                    $line = trim((string) $line);
                    if ($line !== '') {
                        $phrases[] = $line;
                    }
                }
                $one['phrases'] = $phrases;
            }
            $library[$key] = $one;
        }
        update_option(ZW_RECEIPT_AUTO_LIBRARY_OPTION, $library);

        $target = add_query_arg(array('zw_msg' => 'auto_saved'), zw_receipt_auto_settings_url());
        if (function_exists('wp_safe_redirect')) {
            wp_safe_redirect($target);
        }
        exit;
    }
}

if (function_exists('add_action')) {
    add_action('admin_post_zw_receipt_auto_save', 'zw_receipt_auto_handle_save');
}

if (!function_exists('zw_receipt_auto_render_settings')) {
    /**
     * 「收文自动化」设置视图主体（挂在台账 `view=auto`）：开关 + 批示库编辑。
     *
     * @return void
     */
    function zw_receipt_auto_render_settings() {
        if (!function_exists('esc_html')) {
            return;
        }
        $settings = zw_receipt_auto_settings();
        $library  = zw_receipt_auto_library();
        $msg      = isset($_GET['zw_msg']) ? sanitize_key((string) wp_unslash($_GET['zw_msg'])) : '';
        $post_url = function_exists('admin_url') ? (string) admin_url('admin-post.php') : 'admin-post.php';

        if ($msg === 'auto_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>自动化设置已保存。</p></div>';
        }

        echo '<form method="post" action="' . esc_url($post_url) . '">';
        echo '<input type="hidden" name="action" value="zw_receipt_auto_save">';
        if (function_exists('wp_nonce_field')) {
            wp_nonce_field(ZW_RECEIPT_AUTO_SAVE_NONCE, 'zw_receipt_auto_nonce');
        }

        echo '<div class="zw-adm-card" style="margin:0 0 16px">';
        echo '<h2>自动办理开关</h2>';
        echo '<div class="zw-adm-grid is-2">';
        foreach (zw_receipt_auto_switches() as $key => $meta) {
            echo '<div class="zw-adm-field"><label>' . esc_html((string) $meta['label']) . '</label>'
                . '<label style="display:flex;gap:8px;align-items:center;font-size:14px">'
                . '<input type="checkbox" name="' . esc_attr($key) . '" value="1"'
                . (!empty($settings[$key]) ? ' checked' : '') . '> 开启</label>'
                . '<p class="description">' . esc_html((string) $meta['note']) . '</p></div>';
        }
        echo '</div>';
        echo '<p class="zw-doc-muted">自动写入的留痕一律标记 <code>system:*</code> + 时间线灰标「系统自动」，'
            . '不假冒任何真人账号；关闭开关即完全回到人工（与未开启前逐字一致）。</p>';
        echo '</div>';

        echo '<div class="zw-adm-card" style="margin:0 0 16px">';
        echo '<h2>批示库（按政治站位分类的模板）</h2>';
        echo '<p class="zw-doc-muted">每行一条批示语（空行忽略）；关键词用顿号/逗号/空格分隔。'
            . '系统按「关键词命中顺序（督查整改 → 请示报告 → 人事 → 财务 → 贯彻落实 → 部署落实 → 常规阅办）」'
            . '选类别，再按记录 ID 做**确定性轮换**（同一收文永远同一条，便于复现与审计）。</p>';
        foreach ($library as $key => $meta) {
            $label = isset($meta['label']) ? (string) $meta['label'] : $key;
            $kw    = isset($meta['keywords']) ? implode('、', (array) $meta['keywords']) : '';
            $ph    = isset($meta['phrases']) ? implode("\n", (array) $meta['phrases']) : '';
            echo '<div class="zw-adm-sec">' . esc_html($label) . '（<code>' . esc_attr($key) . '</code>）</div>';
            echo '<div class="zw-adm-field"><label for="kw-' . esc_attr($key) . '">关键词（命中即归入本类）</label>'
                . '<input type="text" id="kw-' . esc_attr($key) . '" name="kw_' . esc_attr($key) . '" value="'
                . esc_attr($kw) . '"></div>';
            echo '<div class="zw-adm-field"><label for="lib-' . esc_attr($key) . '">批示语（每行一条，≤5000 字/条）</label>'
                . '<textarea id="lib-' . esc_attr($key) . '" name="lib_' . esc_attr($key) . '" rows="4">'
                . esc_html($ph) . '</textarea></div>';
        }
        echo '</div>';

        zw_receipt_auto_render_rule_section();

        echo '<div class="zw-adm-foot">'
            . '<a class="button" href="' . esc_url(function_exists('zw_receipt_ledger_admin_url') ? zw_receipt_ledger_admin_url() : '') . '">返回台账</a>'
            . '<button type="submit" class="button button-primary">保存自动化设置</button>'
            . '</div>';
        echo '</form>';
    }
}

if (!defined('ZW_RECEIPT_AUTO_SYSTEM_URGE')) {
    define('ZW_RECEIPT_AUTO_SYSTEM_URGE', 'system:auto-urge');
}
if (!defined('ZW_RECEIPT_AUTO_SYSTEM_DISPATCH')) {
    define('ZW_RECEIPT_AUTO_SYSTEM_DISPATCH', 'system:auto-dispatch');
}
if (!defined('ZW_RECEIPT_AUTO_URGE_HOOK')) {
    define('ZW_RECEIPT_AUTO_URGE_HOOK', 'zw_receipt_auto_urge_event');
}
if (!defined('ZW_RECEIPT_AUTO_URGE_DAYS_OPTION')) {
    define('ZW_RECEIPT_AUTO_URGE_DAYS_OPTION', 'zw_receipt_auto_urge_days');
}
if (!defined('ZW_RECEIPT_AUTO_DISPATCH_RULES_OPTION')) {
    define('ZW_RECEIPT_AUTO_DISPATCH_RULES_OPTION', 'zw_receipt_dispatch_rules');
}

if (!function_exists('zw_receipt_auto_urge_days')) {
    /**
     * 催办提前量（天；夹取 0–30，默认 2）。
     *
     * @return int
     */
    function zw_receipt_auto_urge_days() {
        $raw = function_exists('get_option') ? (int) get_option(ZW_RECEIPT_AUTO_URGE_DAYS_OPTION, 2) : 2;
        return max(0, min(30, $raw));
    }
}

if (!function_exists('zw_receipt_auto_dispatch_rules')) {
    /**
     * 派单规则：`org_key => ['dept' => 部门 key, 'days' => 天数]`。
     *
     * 存 option（一行一条在设置页维护）；无规则即不自动派单（默认空）。
     *
     * @return array<string,array{dept:string,days:int}>
     */
    function zw_receipt_auto_dispatch_rules() {
        $raw = function_exists('get_option') ? get_option(ZW_RECEIPT_AUTO_DISPATCH_RULES_OPTION, array()) : array();
        $out = array();
        foreach (is_array($raw) ? $raw : array() as $org => $one) {
            $org = trim((string) $org);
            if ($org === '' || !is_array($one)) {
                continue;
            }
            $dept = isset($one['dept']) ? trim((string) $one['dept']) : '';
            if ($dept === '') {
                continue;
            }
            $days = isset($one['days']) ? (int) $one['days'] : 5;
            $out[$org] = array('dept' => $dept, 'days' => max(1, min(60, $days)));
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_auto_dispatch')) {
    /**
     * 自动派单：按规则给收文生成一张办理单（走既有 `zw_receipt_issue_create()`，不新写 SQL）。
     *
     * 跳过条件（都返回 `result=skipped`，不报错）：无规则 / 部门 key 不合法 / 已有办理单。
     *
     * @param string $receipt_id 收文 ID。
     * @return array{result:string,issue_id:string,note:string}|WP_Error
     */
    function zw_receipt_auto_dispatch($receipt_id) {
        $receipt_id = function_exists('zw_receipt_handle_sanitize_id')
            ? zw_receipt_handle_sanitize_id($receipt_id)
            : trim((string) $receipt_id);
        if ($receipt_id === '' || !function_exists('zw_receipt_issue_create')) {
            return new WP_Error('zw_receipt_auto_dispatch_unavailable', '派单写入器未加载。');
        }
        if (zw_receipt_auto_has_action($receipt_id, 'dispatch', '')) {
            return array('result' => 'skipped', 'issue_id' => '', 'note' => 'already dispatched');
        }
        $row = function_exists('zw_receipt_ledger_row_by_id') ? zw_receipt_ledger_row_by_id($receipt_id) : array();
        if (!$row) {
            return new WP_Error('zw_receipt_auto_no_receipt', '找不到该收文。');
        }
        $view = function_exists('zw_receipt_ledger_row_view') ? zw_receipt_ledger_row_view($row) : $row;
        $org_key = isset($view['org_key']) ? (string) $view['org_key'] : '';
        $rules   = zw_receipt_auto_dispatch_rules();
        if ($org_key === '' || !isset($rules[$org_key])) {
            return array('result' => 'skipped', 'issue_id' => '', 'note' => 'no_rule:' . $org_key);
        }
        $dept = (string) $rules[$org_key]['dept'];
        if (function_exists('zw_receipt_issue_dept_key') && zw_receipt_issue_dept_key($dept) === '') {
            return array('result' => 'skipped', 'issue_id' => '', 'note' => 'bad_dept:' . $dept);
        }
        // 已有办理单 ⇒ 不再派（一纸多单应由人决定，系统只补第一张）。
        if (function_exists('zw_receipt_issue_query')) {
            $existing = zw_receipt_issue_query(array('source_id' => $receipt_id, 'per_page' => 1));
            if (!empty($existing['total'])) {
                return array('result' => 'skipped', 'issue_id' => '', 'note' => 'issue_exists');
            }
        }

        $days = (int) $rules[$org_key]['days'];
        $due  = date('Y-m-d', strtotime('+' . $days . ' day'));
        $title = isset($view['title']) && $view['title'] !== '' ? (string) $view['title'] : '收文办理';
        $result = zw_receipt_issue_create(array(
            'source_id'  => $receipt_id,
            'title'      => '办理：' . $title,
            'owner_dept' => $dept,
            'due_date'   => $due,
            'urgency'    => '',
            'description' => '（系统自动派单：部委规则 ' . $org_key . ' → ' . $dept . '，默认 ' . $days . ' 天）',
        ));
        if (is_wp_error($result)) {
            return $result;
        }
        $issue_id = '';
        if (is_array($result)) {
            $issue_id = isset($result['id']) ? (string) $result['id'] : '';
        }
        // 审计留痕：一条 dispatch 行（内部动作；不占用 拟办/批示/催办 的语义）。
        if (function_exists('zw_receipt_handle_add')) {
            zw_receipt_handle_add(array(
                'receipt_id' => $receipt_id,
                'action'     => 'dispatch',
                'content'    => '系统自动派单：' . $dept . '，时限 ' . $due . '（规则 ' . $org_key . '）',
            ), array('dispatch'), ZW_RECEIPT_AUTO_SYSTEM_DISPATCH);
        }
        return array('result' => 'done', 'issue_id' => $issue_id, 'note' => '');
    }
}

if (!function_exists('zw_receipt_auto_urge_scan')) {
    /**
     * 每日扫描：对「未收口且时限在 N 天内（含已逾期）」的办理单写催办留痕。
     *
     * 幂等键 = 办理单 ID + 时限（同一期限内不重复催）。
     *
     * @param int $limit 单次最多处理条数（防跑太久）。
     * @return array{due:int,urged:int,skipped:int}
     */
    function zw_receipt_auto_urge_scan($limit = 100) {
        $out = array('due' => 0, 'urged' => 0, 'skipped' => 0);
        if (!zw_receipt_auto_enabled('auto_urge') || !function_exists('zw_receipt_issue_table')) {
            return $out;
        }
        global $wpdb;
        $table = zw_receipt_issue_table();
        if ($table === '' || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return $out;
        }
        $closed = array('已办结', '归档');
        $rows   = $wpdb->get_results($wpdb->prepare(
            "SELECT id, source_id, title, due_date, status FROM {$table}"
            . " WHERE source_type = %s AND due_date <> '' AND status NOT IN (%s, %s)"
            . " ORDER BY due_date ASC LIMIT %d",
            'incoming',
            $closed[0],
            $closed[1],
            max(1, (int) $limit)
        ), 'ARRAY_A');
        $rows = is_array($rows) ? $rows : array();
        $today = function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d');
        $days  = zw_receipt_auto_urge_days();

        foreach ($rows as $row) {
            $due = isset($row['due_date']) ? (string) $row['due_date'] : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                continue;
            }
            $diff = (int) floor((strtotime($due) - strtotime($today)) / 86400);
            if ($diff > $days) {
                continue; // 还早，不催
            }
            $out['due']++;
            $issue_id  = isset($row['id']) ? (string) $row['id'] : '';
            $source_id = isset($row['source_id']) ? (string) $row['source_id'] : '';
            if ($issue_id === '' || $source_id === '') {
                continue;
            }
            $marker = '# ' . $issue_id . ' ｜时限 ' . $due;
            $already = false;
            foreach ((array) zw_receipt_handle_list($source_id) as $one) {
                if (isset($one['action']) && (string) $one['action'] === 'urge'
                    && strpos((string) (isset($one['content']) ? $one['content'] : ''), $marker) !== false) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                $out['skipped']++;
                continue;
            }
            $word = $diff < 0 ? ('已逾期 ' . abs($diff) . ' 天') : ('距期限 ' . $diff . ' 天');
            $add  = zw_receipt_handle_add(array(
                'receipt_id' => $source_id,
                'action'     => 'urge',
                'content'    => '【系统自动催办】办理单 ' . $marker . '（' . $word . '），请抓紧办理。',
            ), array('urge'), ZW_RECEIPT_AUTO_SYSTEM_URGE);
            if (is_wp_error($add)) {
                $out['skipped']++;
            } else {
                $out['urged']++;
            }
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_auto_schedule_urge')) {
    /**
     * 排每日催办扫描（08:30）；已排过则不重复。
     *
     * @return void
     */
    function zw_receipt_auto_schedule_urge() {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (!zw_receipt_auto_enabled('auto_urge')) {
            return;
        }
        if (!wp_next_scheduled(ZW_RECEIPT_AUTO_URGE_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 08:30'), 'daily', ZW_RECEIPT_AUTO_URGE_HOOK);
        }
    }
}

if (!function_exists('zw_receipt_auto_urge_register_hooks')) {
    /**
     * 注册催办 cron 回调与每日排期。
     *
     * @return void
     */
    function zw_receipt_auto_urge_register_hooks() {
        if (!function_exists('add_action')) {
            return;
        }
        add_action(ZW_RECEIPT_AUTO_URGE_HOOK, 'zw_receipt_auto_urge_scan', 10, 0);
        add_action('init', 'zw_receipt_auto_schedule_urge', 20);
    }
}

if (function_exists('add_action')) {
    zw_receipt_auto_urge_register_hooks();
}

if (!function_exists('zw_receipt_auto_render_rule_section')) {
    /**
     * 设置页「催办 / 派单规则」区块（供 `zw_receipt_auto_render_settings()` 调用）。
     *
     * @return void
     */
    function zw_receipt_auto_render_rule_section() {
        if (!function_exists('esc_html')) {
            return;
        }
        $days  = zw_receipt_auto_urge_days();
        $rules = zw_receipt_auto_dispatch_rules();
        $lines = array();
        foreach ($rules as $org => $one) {
            $lines[] = $org . ' | ' . $one['dept'] . ' | ' . (int) $one['days'];
        }
        $orgs = function_exists('zw_receipt_org_options') ? zw_receipt_org_options() : array();

        echo '<div class="zw-adm-card" style="margin:0 0 16px">';
        echo '<h2>催办与派单规则</h2>';
        echo '<div class="zw-adm-grid is-2">';
        echo '<div class="zw-adm-field"><label for="zw-auto-urge-days">催办提前量（天）</label>'
            . '<input type="number" id="zw-auto-urge-days" name="auto_urge_days" min="0" max="30" value="' . esc_attr((string) $days) . '">'
            . '<p class="description">时限前 N 天（含已逾期）开始催办；0 = 只在逾期当天催。</p></div>';
        echo '</div>';
        echo '<div class="zw-adm-field"><label for="zw-auto-archive-tpl">归档盒号模板</label>'
            . '<input type="text" id="zw-auto-archive-tpl" name="auto_archive_template" value="'
            . esc_attr(zw_receipt_auto_archive_template()) . '" placeholder="{year}-{org}-{seq}">'
            . '<p class="description">占位：<code>{year}</code> 归档年度、<code>{org}</code> 部委 key、'
            . '<code>{seq}</code> 同年度序号（4 位补零）。盒号重复会自动换号重试。</p></div>';
        echo '<div class="zw-adm-field"><label for="zw-auto-rules">自动派单规则（一行一条：部委 key | 承办部门 key | 天数）</label>'
            . '<textarea id="zw-auto-rules" name="auto_dispatch_rules" rows="5" placeholder="mca | office | 5">'
            . esc_html(implode("\n", $lines)) . '</textarea>'
            . '<p class="description">可用部委 key：' . esc_html(implode('、', array_keys($orgs))) . '；'
            . '留空 = 不自动派单。承办部门用组织架构部门 key。</p></div>';
        echo '</div>';
    }
}

if (!defined('ZW_RECEIPT_AUTO_SYSTEM_ARCHIVE')) {
    define('ZW_RECEIPT_AUTO_SYSTEM_ARCHIVE', 'system:auto-archive');
}
if (!defined('ZW_RECEIPT_AUTO_ARCHIVE_HOOK')) {
    define('ZW_RECEIPT_AUTO_ARCHIVE_HOOK', 'zw_receipt_auto_archive_event');
}
if (!defined('ZW_RECEIPT_AUTO_ARCHIVE_TEMPLATE_OPTION')) {
    define('ZW_RECEIPT_AUTO_ARCHIVE_TEMPLATE_OPTION', 'zw_receipt_archive_template');
}

if (!function_exists('zw_receipt_auto_archive_template')) {
    /**
     * 盒号模板（默认 `{year}-{org}-{seq}`；可用占位 {year}/{org}/{seq}）。
     *
     * @return string
     */
    function zw_receipt_auto_archive_template() {
        $tpl = function_exists('get_option') ? (string) get_option(ZW_RECEIPT_AUTO_ARCHIVE_TEMPLATE_OPTION, '') : '';
        $tpl = trim($tpl);
        return $tpl !== '' ? $tpl : '{year}-{org}-{seq}';
    }
}

if (!function_exists('zw_receipt_auto_archive_box')) {
    /**
     * 生成盒号（纯函数；`seq` 由调用方给）。
     *
     * @param string $template 模板。
     * @param int    $year     年度。
     * @param string $org     部委 key。
     * @param int    $seq      序号。
     * @return string
     */
    function zw_receipt_auto_archive_box($template, $year, $org, $seq) {
        $box = str_replace(
            array('{year}', '{org}', '{seq}'),
            array((string) (int) $year, (string) $org, str_pad((string) (int) $seq, 4, '0', STR_PAD_LEFT)),
            (string) $template
        );
        $box = trim($box);
        // 与 `zw_receipt_archive_save()` 的字符白名单保持一致（中文/字母/数字/连字符）。
        $box = preg_replace('/[^A-Za-z0-9_\-\x{4e00}-\x{9fa5}]/u', '', $box);
        if (function_exists('mb_substr')) {
            $box = mb_substr($box, 0, 64);
        }
        return $box;
    }
}

if (!function_exists('zw_receipt_auto_archive_year')) {
    /**
     * 归档年度：取收文日期年；缺失/非法 ⇒ 当前年。
     *
     * @param array $view 台账行视图。
     * @return int
     */
    function zw_receipt_auto_archive_year($view) {
        $date = isset($view['received_date']) ? (string) $view['received_date'] : '';
        if (preg_match('/^(\d{4})/', $date, $m)) {
            $year = (int) $m[1];
            if ($year >= 1900 && $year <= 2999) {
                return $year;
            }
        }
        return function_exists('current_time') ? (int) current_time('Y') : (int) date('Y');
    }
}

if (!function_exists('zw_receipt_auto_archive_one')) {
    /**
     * 给单个收文归档（幂等：已归档则跳过）。
     *
     * @param string $receipt_id 收文 ID。
     * @return array{result:string,box:string,year:int,note:string}|WP_Error
     */
    function zw_receipt_auto_archive_one($receipt_id) {
        $receipt_id = function_exists('zw_receipt_handle_sanitize_id')
            ? zw_receipt_handle_sanitize_id($receipt_id)
            : trim((string) $receipt_id);
        if ($receipt_id === '' || !function_exists('zw_receipt_archive_save')) {
            return new WP_Error('zw_receipt_auto_archive_unavailable', '归档写入器未加载。');
        }
        $info = function_exists('zw_receipt_handle_archive_info') ? zw_receipt_handle_archive_info($receipt_id) : array();
        if (!empty($info['archive_box_no'])) {
            return array('result' => 'skipped', 'box' => (string) $info['archive_box_no'], 'year' => (int) $info['archive_year'], 'note' => 'already_archived');
        }

        // 门禁①：所有办理单已收口（无办理单 ⇒ 视为收口）。
        if (function_exists('zw_receipt_issue_table')) {
            global $wpdb;
            $table = zw_receipt_issue_table();
            if ($table !== '' && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $open = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE source_type = %s AND source_id = %s AND status NOT IN (%s, %s)",
                    'incoming',
                    $receipt_id,
                    '已办结',
                    '归档'
                ));
                if ($open > 0) {
                    return array('result' => 'skipped', 'box' => '', 'year' => 0, 'note' => 'open_issues:' . $open);
                }
            }
        }

        $row  = function_exists('zw_receipt_ledger_row_by_id') ? zw_receipt_ledger_row_by_id($receipt_id) : array();
        if (!$row) {
            return new WP_Error('zw_receipt_auto_no_receipt', '找不到该收文。');
        }
        $view = function_exists('zw_receipt_ledger_row_view') ? zw_receipt_ledger_row_view($row) : $row;
        $year = zw_receipt_auto_archive_year($view);
        $org  = isset($view['org_key']) && (string) $view['org_key'] !== '' ? (string) $view['org_key'] : 'other';
        $tpl  = zw_receipt_auto_archive_template();

        // 门禁②：盒号查重（同年度已有归档数 + 1 起，最多试 20 次）。
        $base_seq = 1;
        if (function_exists('zw_ocr_source_table') || function_exists('zw_receipt_ledger_source_table')) {
            global $wpdb;
            $src = function_exists('zw_receipt_ledger_source_table') ? zw_receipt_ledger_source_table() : '';
            if ($src !== '' && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $n = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$src} WHERE origin = 'incoming' AND archive_year = %d",
                    $year
                ));
                $base_seq = $n + 1;
            }
        }
        $box   = '';
        $tries = 0;
        while ($tries < 20) {
            $candidate = zw_receipt_auto_archive_box($tpl, $year, $org, $base_seq + $tries);
            $tries++;
            if ($candidate === '') {
                break;
            }
            $taken = false;
            global $wpdb;
            $src = function_exists('zw_receipt_ledger_source_table') ? zw_receipt_ledger_source_table() : '';
            if ($src !== '' && is_object($wpdb) && method_exists($wpdb, 'get_var')) {
                $hit = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$src} WHERE archive_box_no = %s LIMIT 1",
                    $candidate
                ));
                $taken = !empty($hit);
            }
            if (!$taken) {
                $box = $candidate;
                break;
            }
        }
        if ($box === '') {
            return array('result' => 'skipped', 'box' => '', 'year' => $year, 'note' => 'box_conflict');
        }

        $saved = zw_receipt_archive_save(array(
            'receipt_id'     => $receipt_id,
            'archive_box_no' => $box,
            'archive_year'   => $year,
        ));
        if (is_wp_error($saved)) {
            return $saved;
        }
        if (function_exists('zw_receipt_handle_add')) {
            zw_receipt_handle_add(array(
                'receipt_id' => $receipt_id,
                'action'     => 'archive',
                'content'    => '系统自动归档：盒号 ' . $box . '，年度 ' . $year,
            ), array('archive'), ZW_RECEIPT_AUTO_SYSTEM_ARCHIVE);
        }
        return array('result' => 'done', 'box' => $box, 'year' => $year, 'note' => '');
    }
}

if (!function_exists('zw_receipt_auto_archive_scan')) {
    /**
     * 每日扫描：对「已收口且未归档」的收文自动归档。
     *
     * @param int $limit 单次上限。
     * @return array{scanned:int,archived:int,skipped:int}
     */
    function zw_receipt_auto_archive_scan($limit = 100) {
        $out = array('scanned' => 0, 'archived' => 0, 'skipped' => 0);
        if (!zw_receipt_auto_enabled('auto_archive')) {
            return $out;
        }
        $missing = function_exists('zw_receipt_handle_missing') ? zw_receipt_handle_missing() : array('columns' => array('x'));
        if (!empty($missing['columns'])) {
            return $out; // 归档三列未迁移 ⇒ 零行为
        }
        global $wpdb;
        $src = function_exists('zw_receipt_ledger_source_table') ? zw_receipt_ledger_source_table() : '';
        if ($src === '' || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return $out;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$src} WHERE origin = 'incoming' AND (archive_box_no IS NULL OR archive_box_no = '')"
            . " ORDER BY id ASC LIMIT %d",
            max(1, (int) $limit)
        ), 'ARRAY_A');
        foreach (is_array($rows) ? $rows : array() as $one) {
            $id = isset($one['id']) ? (string) $one['id'] : '';
            if ($id === '') {
                continue;
            }
            $out['scanned']++;
            $r = zw_receipt_auto_archive_one($id);
            if (is_array($r) && $r['result'] === 'done') {
                $out['archived']++;
            } else {
                $out['skipped']++;
            }
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_auto_archive_schedule')) {
    /**
     * 排每日归档扫描（09:10）。
     *
     * @return void
     */
    function zw_receipt_auto_archive_schedule() {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (!zw_receipt_auto_enabled('auto_archive')) {
            return;
        }
        if (!wp_next_scheduled(ZW_RECEIPT_AUTO_ARCHIVE_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 09:10'), 'daily', ZW_RECEIPT_AUTO_ARCHIVE_HOOK);
        }
    }
}

if (function_exists('add_action')) {
    add_action(ZW_RECEIPT_AUTO_ARCHIVE_HOOK, 'zw_receipt_auto_archive_scan', 10, 0);
    add_action('init', 'zw_receipt_auto_archive_schedule', 21);
}
