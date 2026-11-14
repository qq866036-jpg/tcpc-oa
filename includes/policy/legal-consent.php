<?php
/**
 * 条款与签署 · 判定引擎（SPEC-88 · TASK-954 / T2）。
 *
 * 职责（只做判定与记录，不做页面与拦截——拦截在 T3）：
 *  - 找出"当前生效且需要签署"的内部声明/协议；
 *  - 判定某账号是否适用、是否已签当前版本（**以正文 SHA-256 为唯一锚点**）；
 *  - 渲染协议正文（条款白名单净化）；
 *  - 执行签署：服务端重算版本与哈希、校验实名一致性、写 user_meta + 两张审计表 + 正文快照。
 *
 * 铁律（SPEC-88）：
 *  - **外部政策不进入签署判定**（只有 `source_type='内部文件'` 且 `applies_to` 命中角色键者才需签）；
 *  - 哈希变更即须重签；哈希未变（仅改元数据）不触发重签；
 *  - **快照写入失败不得视为签署成功**；
 *  - 模块未加载 / 表缺失 → 一律判定"无需签署"，**绝不阻断登录**；
 *  - 默认**不启用**拦截（option `zw_legal_consent_enforce` 默认 0），本任务零用户可见行为变化。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_LEGAL_CONSENT_META')) {
    define('ZW_LEGAL_CONSENT_META', 'zw_legal_consents');        // user_meta 单键：doc_id => 记录
    define('ZW_LEGAL_CONSENT_ENFORCE_OPTION', 'zw_legal_consent_enforce');
    define('ZW_LEGAL_CONSENT_SNAPSHOT_DIR', 'zw-legal-consents');
    define('ZW_LEGAL_CONSENT_MIN_READ_OPTION', 'zw_legal_consent_min_read_seconds');
}

if (!function_exists('zw_legal_consent_enforce_enabled')) {
    /**
     * 是否启用拦截（0=只记录不拦，1=半硬拦截）。默认 0。
     *
     * @return bool
     */
    function zw_legal_consent_enforce_enabled() {
        if (!function_exists('get_option')) { return false; }
        return (string) get_option(ZW_LEGAL_CONSENT_ENFORCE_OPTION, '0') === '1';
    }
}

if (!function_exists('zw_legal_consent_nonce_action')) {
    /**
     * 签署提交的 nonce action。
     *
     * @return string
     */
    function zw_legal_consent_nonce_action() {
        return 'zw_legal_consent_sign';
    }
}

if (!function_exists('zw_legal_consent_body_chars')) {
    /**
     * 某协议当前生效正文的**正文字数**（仅统计正文，不含标签）。
     *
     * 供 TASK-962 D2「按正文字数动态计算最短阅读时长」使用；表缺失/无条款返回 0。
     *
     * TASK-1016：统一文本源 `db` 灰度下，正文以**生效版本**为源（`zw_legal_consent_render_body()`
     * 同样优先取生效版本），因此倒计时口径必须与**实际展示的正文**一致 —— 取
     * `zw_legal_text_effective_body()` + `zw_legal_text_body_chars()`；读不到（非 db 模式/表缺失/
     * 版本缺失/哈希不自洽）则**回退条款口径**，绝不返回 0 造成倒计时形同虚设。
     *
     * @param string $doc_id  规范/协议 ID。
     * @param string $version 版本（空 = 全部已确认条款；db 模式忽略）。
     * @return int
     */
    function zw_legal_consent_body_chars($doc_id, $version = '') {
        // TASK-1016：db 模式与生效版本正文对齐（复用统一文本源，禁止第二套字数口径）。
        if (function_exists('zw_legal_text_effective_body') && function_exists('zw_legal_text_body_chars')) {
            $effective = zw_legal_text_effective_body($doc_id);
            if (trim((string) $effective) !== '') {
                return (int) zw_legal_text_body_chars($effective);
            }
        }
        $chars = 0;
        foreach (zw_legal_consent_clauses($doc_id, $version) as $row) {
            $text = trim((string) ($row['original_text'] ?? ''));
            if ($text === '') { continue; }
            $chars += function_exists('mb_strlen') ? (int) mb_strlen($text, 'UTF-8') : (int) strlen($text);
        }
        return $chars;
    }
}

if (!function_exists('zw_legal_consent_clause_key_norm')) {
    /**
     * 条款排序键的**规范化原串**（TASK-1016）：去括号/空白/标点等装饰后返回。
     *
     * 目的：让「等价写法」得到**同一个键** —— `（一）` / `(一)` / ` 一 ` / `第一项` 归一后一致；
     * 同时让无法解析的输入仍有一个确定、可比较的次级键（保证排序稳定且不崩）。
     * 非标量输入返回空串（不触发 PHP Warning）。
     *
     * @param mixed $raw 条号或项号原始值。
     * @return string
     */
    function zw_legal_consent_clause_key_norm($raw) {
        if (!is_scalar($raw)) { return ''; }
        $s = (string) $raw;
        // 去掉成对/单个括号（全角与半角）、各类空白与常见中文序号装饰词。
        $s = str_replace(
            array('（', '）', '(', ')', '【', '】', '[', ']', '　', ' ', "\t", "\n", "\r", '．', '.', '、', '第', '条', '项', '款', '章', '节'),
            '',
            $s
        );
        return trim($s);
    }
}

if (!function_exists('zw_legal_consent_cn_number')) {
    /**
     * 中文数字 → 整数（TASK-1016 canonical 解析器）。
     *
     * 支持：`一`…`九`、`十`、`十一`…`十九`、`二十`、`二十一`…（按「十」拆分，**不是** 1–9 硬编码表）；
     * 兼容阿拉伯数字（`1`、`11`，与"第 1 条"写法一致）。无法解析返回 `null`（调用方回退 0）。
     *
     * @param mixed $raw 原始串（可含括号/空白/「第…条」装饰）。
     * @return int|null
     */
    function zw_legal_consent_cn_number($raw) {
        if (!is_scalar($raw)) { return null; }
        $s = zw_legal_consent_clause_key_norm($raw);
        if ($s === '') { return null; }
        if (preg_match('/^\d+$/', $s)) { return (int) $s; }
        $digits = array('零' => 0, '〇' => 0, '一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9);
        if (isset($digits[$s])) { return $digits[$s]; }
        if (strpos($s, '十') !== false) {
            $parts = explode('十', $s);
            if (count($parts) > 2) { return null; }   // 「十十」等异常写法不解析
            $left  = $parts[0];
            $right = isset($parts[1]) ? $parts[1] : '';
            if ($left === '') { $tens = 1; }                       // 十 → 10
            elseif (isset($digits[$left])) { $tens = $digits[$left]; }
            else { return null; }
            if ($right === '') { $units = 0; }                      // 二十 → 20
            elseif (isset($digits[$right])) { $units = $digits[$right]; }
            else { return null; }
            return $tens * 10 + $units;
        }
        return null;
    }
}

if (!function_exists('zw_legal_consent_clause_order_key')) {
    /**
     * 条款排序键（TASK-1016 **唯一出处**）。
     *
     * 返回可稳定比较的数组：`[是否无法解析(0/1), 条号数值, 项号数值, 条号规范串, 项号规范串]`
     *  - 第 0 位：**完全无法解析时为 1**，使脏数据排在可解析条款**之后**（不是排在最前扰乱正文）；
     *  - 第 1/2 位：中文数字（含十/二十…）转整数；无法解析记 **0**（按需求回退，不抛错）；
     *  - 第 3/4 位：规范化原串（去括号/空白/装饰），保证等价写法同键、脏数据仍有确定序；
     *  - 同名键由调用方按 `id` 升序兜底（见 `zw_legal_consent_clause_compare()`）。
     *
     * 示例：`三/（一）` → `[0,3,1,'三','一']`；`一/（一）` → `[0,1,1,'一','一']`。
     *
     * @param mixed $article_no 条号（如「三」）。
     * @param mixed $item_no    项号（如「（一）」）。
     * @return array 长度 5 的可比较数组。
     */
    function zw_legal_consent_clause_order_key($article_no, $item_no) {
        $a_num = zw_legal_consent_cn_number($article_no);
        $i_num = zw_legal_consent_cn_number($item_no);
        return array(
            ($a_num === null && $i_num === null) ? 1 : 0,
            $a_num === null ? 0 : (int) $a_num,
            $i_num === null ? 0 : (int) $i_num,
            zw_legal_consent_clause_key_norm($article_no),
            zw_legal_consent_clause_key_norm($item_no),
        );
    }
}

if (!function_exists('zw_legal_consent_clause_compare')) {
    /**
     * 条款比较器（TASK-1016 **唯一出处**）：条号 → 项号 → 同名键按 `id` 升序（稳定）。
     *
     * 任何需要"文档序"的地方都必须用它（禁止再写第二套序，例如 `CAST(article_no AS UNSIGNED)`）。
     *
     * @param array $x 条款行（至少含 article_no / item_no，建议含 id）。
     * @param array $y 条款行。
     * @return int -1|0|1
     */
    function zw_legal_consent_clause_compare($x, $y) {
        $kx = zw_legal_consent_clause_order_key($x['article_no'] ?? '', $x['item_no'] ?? '');
        $ky = zw_legal_consent_clause_order_key($y['article_no'] ?? '', $y['item_no'] ?? '');
        for ($n = 0; $n < 5; $n++) {
            if ($kx[$n] === $ky[$n]) { continue; }
            return ($kx[$n] < $ky[$n]) ? -1 : 1;
        }
        $ix = is_scalar($x['id'] ?? '') ? (string) ($x['id'] ?? '') : '';
        $iy = is_scalar($y['id'] ?? '') ? (string) ($y['id'] ?? '') : '';
        return strcmp($ix, $iy);
    }
}

if (!function_exists('zw_legal_consent_auto_read_seconds')) {
    /**
     * 按正文字数动态推算最短阅读时长（TASK-962 D2 口径 ②，**推荐默认值**）。
     *
     * 公式：`ceil(字数 / 速率)`，并钳制在 `[下限, 上限]`：
     *  - 速率 `zw_legal_consent_auto_read_rate`（字/秒）默认 **8**（约 480 字/分钟，成年人正常精读速度）；
     *  - 下限 `zw_legal_consent_auto_read_min` 默认 **15** 秒（短文不形同虚设）；
     *  - 上限 `zw_legal_consent_auto_read_max` 默认 **120** 秒（长规范不拖沓）。
     *
     * 三个参数都可用 filter 覆盖；口径最终由 TASK-962 D2 拍板，拍板前保持推荐默认。
     *
     * @param int $chars 正文字数。
     * @return int 秒
     */
    function zw_legal_consent_auto_read_seconds($chars) {
        $chars = max(0, (int) $chars);
        $rate = 8; $min = 15; $max = 120;
        if (function_exists('apply_filters')) {
            $rate = (int) apply_filters('zw_legal_consent_auto_read_rate', $rate, $chars);
            $min  = (int) apply_filters('zw_legal_consent_auto_read_min', $min, $chars);
            $max  = (int) apply_filters('zw_legal_consent_auto_read_max', $max, $chars);
        }
        $rate = max(1, $rate);
        $min  = max(0, $min);
        $max  = max($min, $max);
        if ($chars <= 0) { return 0; }
        return (int) min($max, max($min, (int) ceil($chars / $rate)));
    }
}

if (!function_exists('zw_legal_consent_min_read_seconds')) {
    /**
     * 最短阅读时长（秒）——TASK-962 R3 的**服务端口径**（TASK-963 / TASK-966）。
     *
     * option `zw_legal_consent_min_read_seconds` 支持两种取值：
     *  - 数字（如 `30`）：固定秒数；
     *  - 字符串 `auto`：按正文字数动态（见 `zw_legal_consent_auto_read_seconds()`，TASK-962 D2 推荐口径）。
     *
     * **默认 `0` = 不启用**（发布后零用户可见变化）；最终仍可用 filter
     * `zw_legal_consent_min_read_seconds` 按规范逐份覆盖。
     *
     * @param string $doc_id 规范/协议 ID。
     * @return int
     */
    function zw_legal_consent_min_read_seconds($doc_id = '') {
        $raw = function_exists('get_option') ? get_option(ZW_LEGAL_CONSENT_MIN_READ_OPTION, 0) : 0;
        if (is_string($raw) && strtolower(trim($raw)) === 'auto') {
            $min = zw_legal_consent_auto_read_seconds(zw_legal_consent_body_chars((string) $doc_id));
        } else {
            $min = max(0, (int) $raw);
        }
        if (function_exists('apply_filters')) {
            $min = max(0, (int) apply_filters('zw_legal_consent_min_read_seconds', $min, (string) $doc_id));
        }
        return $min;
    }
}

if (!function_exists('zw_legal_consent_reading_plan')) {
    /**
     * 某协议的**阅读计划**（签署页倒数控件唯一取数口径）。
     *
     * @param string $doc_id 规范/协议 ID。
     * @return array{seconds:int,chars:int,mode:string} mode ∈ off|fixed|auto
     */
    function zw_legal_consent_reading_plan($doc_id) {
        $doc_id = trim((string) $doc_id);
        $raw = function_exists('get_option') ? get_option(ZW_LEGAL_CONSENT_MIN_READ_OPTION, 0) : 0;
        $chars = zw_legal_consent_body_chars($doc_id);
        $seconds = zw_legal_consent_min_read_seconds($doc_id);
        $mode = 'off';
        if (is_string($raw) && strtolower(trim($raw)) === 'auto') {
            $mode = $seconds > 0 ? 'auto' : 'off';
        } elseif ($seconds > 0) {
            $mode = 'fixed';
        }
        return array('seconds' => (int) $seconds, 'chars' => (int) $chars, 'mode' => $mode);
    }
}

if (!function_exists('zw_legal_consent_version_label')) {
    /**
     * 版本号展示标签：`v2` / `2` / `第2版` → 「第 2 版」；无法识别时原样返回。
     *
     * 唯一出处（签署页、未签署提示页、日报文案共用），避免各页各写一套映射。
     *
     * @param string $version_no 库中 version_no。
     * @return string
     */
    function zw_legal_consent_version_label($version_no) {
        $v = trim((string) $version_no);
        if ($v === '') { return ''; }
        if (preg_match('/^v?(\d+)$/i', $v, $m)) { return '第 ' . (int) $m[1] . ' 版'; }
        return $v;
    }
}

if (!function_exists('zw_legal_consent_read_key')) {
    /**
     * "阅读开始"的 transient 键（绑定 用户 + 规范）。
     *
     * @param int    $user_id 用户 ID。
     * @param string $doc_id  规范/协议 ID。
     * @return string
     */
    function zw_legal_consent_read_key($user_id, $doc_id) {
        return 'zw_legal_read_' . (int) $user_id . '_' . md5((string) $doc_id);
    }
}

if (!function_exists('zw_legal_consent_mark_read')) {
    /**
     * 记录"阅读开始"（进入阅读页时由 T3 页面调用）。
     *
     * 存 `{hash, at}`：**hash 绑定当时的正文版本**，正文升版后旧记录自然失效
     * （呼应 TASK-962 R6「新版须重复阅读」）。TTL 30 分钟，超时重新计时。
     *
     * @param int    $user_id 用户 ID。
     * @param string $doc_id  规范/协议 ID。
     * @return bool
     */
    function zw_legal_consent_mark_read($user_id, $doc_id) {
        $user_id = (int) $user_id;
        $doc_id  = trim((string) $doc_id);
        if ($user_id <= 0 || $doc_id === '' || !function_exists('set_transient')) { return false; }
        $hash = zw_legal_consent_hash($doc_id);
        $ttl  = function_exists('apply_filters')
            ? (int) apply_filters('zw_legal_consent_read_ttl', 30 * 60, $user_id, $doc_id)
            : 30 * 60;
        set_transient(zw_legal_consent_read_key($user_id, $doc_id), array(
            'hash' => $hash,
            'at'   => function_exists('time') ? time() : 0,
        ), max(60, $ttl));
        return true;
    }
}

if (!function_exists('zw_legal_consent_read_elapsed')) {
    /**
     * 已阅读秒数；**未开始阅读或正文哈希已变 → null**（视为未读）。
     *
     * @param int    $user_id 用户 ID。
     * @param string $doc_id  规范/协议 ID。
     * @param string $hash    当前生效正文哈希（空则取实时哈希）。
     * @return int|null
     */
    function zw_legal_consent_read_elapsed($user_id, $doc_id, $hash = '') {
        $user_id = (int) $user_id;
        $doc_id  = trim((string) $doc_id);
        if ($user_id <= 0 || $doc_id === '' || !function_exists('get_transient')) { return null; }
        $row = get_transient(zw_legal_consent_read_key($user_id, $doc_id));
        if (!is_array($row) || empty($row['at'])) { return null; }
        $cur = $hash !== '' ? (string) $hash : zw_legal_consent_hash($doc_id);
        if ($cur === '' || (string) ($row['hash'] ?? '') !== $cur) { return null; }
        $now = function_exists('time') ? time() : 0;
        return max(0, $now - (int) $row['at']);
    }
}

if (!function_exists('zw_legal_consent_role_keys')) {
    /**
     * 当前纳入签署的机构角色键（唯一出处：TASK-602 的前端机构角色模板）。
     *
     * @return string[]
     */
    function zw_legal_consent_role_keys() {
        if (function_exists('zw_branch_front_role_keys')) {
            $keys = zw_branch_front_role_keys();
            $out  = array();
            foreach ((array) $keys as $k => $v) {
                if (is_string($k) && in_array($k, array('branch_admin', 'branch_editor'), true)) { $out[] = $k; continue; }
                if (is_string($v) && in_array($v, array('branch_admin', 'branch_editor'), true)) { $out[] = $v; }
            }
            if ($out) { return array_values(array_unique($out)); }
        }
        return array('branch_admin', 'branch_editor');
    }
}

if (!function_exists('zw_legal_consent_user_roles')) {
    /**
     * 某账号持有的机构角色（按机构聚合）。
     *
     * 口径与 TASK-602 一致：总管理员存机构 meta `zw_branch_manager_user_id`（每机构 1 人），
     * 管理员存可重复 meta `zw_branch_editor_user_id`（每机构 0..N 人）。
     *
     * @param int $user_id 用户 ID。
     * @return array<string,int[]> role_key => 机构 ID 列表
     */
    function zw_legal_consent_user_roles($user_id) {
        $user_id = (int) $user_id;
        $out = array('branch_admin' => array(), 'branch_editor' => array());
        if ($user_id <= 0 || !function_exists('get_posts')) { return $out; }

        $base = array(
            'post_type'      => 'post',
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'category_name'  => 'fzjg',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );
        $admins = get_posts(array_merge($base, array(
            'meta_key'   => 'zw_branch_manager_user_id',
            'meta_value' => $user_id,
        )));
        foreach ((array) $admins as $bid) { $out['branch_admin'][] = (int) $bid; }

        $editors = get_posts(array_merge($base, array(
            'meta_query' => array(array('key' => 'zw_branch_editor_user_id', 'value' => $user_id)),
        )));
        foreach ((array) $editors as $bid) { $out['branch_editor'][] = (int) $bid; }

        return $out;
    }
}

if (!function_exists('zw_legal_consent_applicable_users')) {
    /**
     * 某协议「适用对象」的账号清单（管理面聚合的唯一口径 · TASK-989 / T4）。
     *
     * 与 `zw_legal_consent_user_roles()` **完全同源、方向相反**：同一批 post meta 键
     * （分类 `fzjg` 上的 `zw_branch_manager_user_id` / `zw_branch_editor_user_id`），
     * 只返回 `applies_to` 命中的角色键对应的账号；去重升序。
     *
     * @param array $doc 归一后的协议结构（含 applies_to）。
     * @return int[] 账号 ID 列表。
     */
    function zw_legal_consent_applicable_users($doc) {
        $out = array();
        if (!is_array($doc) || !function_exists('get_posts')) { return $out; }
        $applies = (isset($doc['applies_to']) && is_array($doc['applies_to']))
            ? $doc['applies_to']
            : zw_legal_consent_applies_to_normalize($doc['applies_to'] ?? '');
        if (!$applies) { return $out; }

        $base = array(
            'post_type'      => 'post',
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'category_name'  => 'fzjg',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );
        if (in_array('branch_admin', $applies, true) && function_exists('get_post_meta')) {
            $posts = get_posts(array_merge($base, array('meta_key' => 'zw_branch_manager_user_id')));
            foreach ((array) $posts as $bid) {
                $uid = (int) get_post_meta((int) $bid, 'zw_branch_manager_user_id', true);
                if ($uid > 0) { $out[$uid] = true; }
            }
        }
        if (in_array('branch_editor', $applies, true) && function_exists('get_post_meta')) {
            $posts = get_posts(array_merge($base, array(
                'meta_query' => array(array('key' => 'zw_branch_editor_user_id', 'compare' => 'EXISTS')),
            )));
            foreach ((array) $posts as $bid) {
                foreach ((array) get_post_meta((int) $bid, 'zw_branch_editor_user_id', false) as $uid) {
                    $uid = (int) $uid;
                    if ($uid > 0) { $out[$uid] = true; }
                }
            }
        }
        $ids = array_map('intval', array_keys($out));
        sort($ids);
        return $ids;
    }
}

if (!function_exists('zw_legal_consent_applies_to_normalize')) {
    /**
     * 归一 `policy_source.applies_to`（JSON 字符串或数组）为字符串数组。
     *
     * @param mixed $raw 原始值。
     * @return string[]
     */
    function zw_legal_consent_applies_to_normalize($raw) {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array($raw);
        }
        if (!is_array($raw)) { return array(); }
        $out = array();
        foreach ($raw as $v) {
            $v = trim((string) $v);
            if ($v !== '') { $out[] = $v; }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('zw_legal_consent_today')) {
    /**
     * 站点当日（北京时间）Y-m-d。
     *
     * @return string
     */
    function zw_legal_consent_today() {
        $ts = function_exists('current_time') ? (int) current_time('timestamp') : time();
        return gmdate('Y-m-d', $ts + 8 * 3600);
    }
}

if (!function_exists('zw_legal_consent_date_le')) {
    /**
     * 宽松日期比较：`$date` 是否 <= 今天（空日期视为不限制，返回 true）。
     *
     * 兼容 `2026-10-06`、`2026/10/06`、`2026年10月6日` 三种写法。
     *
     * @param string $date 日期串。
     * @return bool
     */
    function zw_legal_consent_date_le($date) {
        $date = trim((string) $date);
        if ($date === '') { return true; }
        $norm = str_replace(array('年', '月', '/', '.'), '-', str_replace('日', '', $date));
        $ts   = strtotime($norm);
        if ($ts === false) { return true; }
        return gmdate('Y-m-d', $ts) <= zw_legal_consent_today();
    }
}

if (!function_exists('zw_legal_consent_date_ge')) {
    /**
     * 到期日是否尚未过期（空 = 不限，返回 true）。
     *
     * @param string $date 日期串。
     * @return bool
     */
    function zw_legal_consent_date_ge($date) {
        $date = trim((string) $date);
        if ($date === '') { return true; }
        $norm = str_replace(array('年', '月', '/', '.'), '-', str_replace('日', '', $date));
        $ts   = strtotime($norm);
        if ($ts === false) { return true; }
        return gmdate('Y-m-d', $ts) >= zw_legal_consent_today();
    }
}

if (!function_exists('zw_legal_consent_docs')) {
    /**
     * 当前"生效且需要签署"的内部声明/协议列表。
     *
     * 判据（SPEC-88 §1/§3/§4）：
     *  - `source_type='内部文件'`（**外部政策一律排除**）；
     *  - `status='已生效'`；生效日 <= 今天；到期日为空或 >= 今天；
     *  - `applies_to` 含至少一个机构角色键。
     *
     * @return array[] 每项见 zw_legal_consent_normalize_doc()。
     */
    function zw_legal_consent_docs() {
        global $wpdb;
        if (!function_exists('zw_policy_platform_exists') || !zw_policy_platform_exists('policy_source')) {
            return array();
        }
        $table = zw_policy_platform_table('policy_source');
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, doc_name, source_type, status, version_no, source_hash, applies_to, basis, source_file, effective_date, expiry_date, update_time
             FROM {$table} WHERE source_type=%s AND status=%s ORDER BY effective_date ASC, id ASC",
            '内部文件', '已生效'
        ), ARRAY_A);
        if (!is_array($rows)) { return array(); }

        $role_keys = zw_legal_consent_role_keys();
        $out = array();
        foreach ($rows as $row) {
            $applies = zw_legal_consent_applies_to_normalize($row['applies_to'] ?? '');
            if (!array_intersect($applies, $role_keys)) { continue; }
            if (!zw_legal_consent_date_le((string) ($row['effective_date'] ?? ''))) { continue; }
            if (!zw_legal_consent_date_ge((string) ($row['expiry_date'] ?? ''))) { continue; }
            $doc = zw_legal_consent_normalize_doc($row, $applies);
            // 生效哈希以**正文计算值**为准（防库中 source_hash 陈旧导致永远要求重签）；
            // 条款尚未确认（计算为空）时回退库值，此时 sign() 会以「无已确认条款」拒绝签署。
            $computed = zw_legal_consent_hash($doc['doc_id']);
            if ($computed !== '') { $doc['source_hash'] = $computed; }
            $out[] = $doc;
        }
        return $out;
    }
}

if (!function_exists('zw_legal_consent_normalize_doc')) {
    /**
     * 归一协议记录（统一字段与类型）。
     *
     * @param array    $row     数据行。
     * @param string[] $applies 适用对象（已归一）。
     * @return array
     */
    function zw_legal_consent_normalize_doc($row, $applies = null) {
        $applies = $applies === null ? zw_legal_consent_applies_to_normalize($row['applies_to'] ?? '') : $applies;
        return array(
            'doc_id'         => (string) ($row['id'] ?? ''),
            'doc_name'       => (string) ($row['doc_name'] ?? ''),
            'source_type'    => (string) ($row['source_type'] ?? ''),
            'status'         => (string) ($row['status'] ?? ''),
            'version_no'     => (string) ($row['version_no'] ?? ''),
            'source_hash'    => (string) ($row['source_hash'] ?? ''),        // 生效哈希（见 docs() 以正文计算值为准）
            'source_hash_db' => (string) ($row['source_hash'] ?? ''),        // 库中物化值（仅审计/展示对照）
            'applies_to'     => $applies,
            'basis'          => (string) ($row['basis'] ?? ''),
            'source_file'    => (string) ($row['source_file'] ?? ''),
            'effective_date' => (string) ($row['effective_date'] ?? ''),
            'expiry_date'    => (string) ($row['expiry_date'] ?? ''),
        );
    }
}

if (!function_exists('zw_legal_consent_required')) {
    /**
     * 单份协议的"当前要求"（服务端唯一口径）。
     *
     * @param string $doc_id 协议 ID。
     * @return array|null 未生效/非内部/不适用时返回 null。
     */
    function zw_legal_consent_required($doc_id) {
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '') { return null; }
        foreach (zw_legal_consent_docs() as $doc) {
            if ($doc['doc_id'] === $doc_id) { return $doc; }
        }
        return null;
    }
}

if (!function_exists('zw_legal_consent_applies')) {
    /**
     * 某账号是否适用该协议（命中 `applies_to` 的机构角色键）。
     *
     * @param int   $user_id 用户 ID。
     * @param array $doc     协议（normalize 后的结构）。
     * @return bool
     */
    function zw_legal_consent_applies($user_id, $doc) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !is_array($doc)) { return false; }
        // 铁律：外部政策不得进入签署判定。
        if ((string) ($doc['source_type'] ?? '') !== '内部文件') { return false; }
        $applies = isset($doc['applies_to']) && is_array($doc['applies_to'])
            ? $doc['applies_to']
            : zw_legal_consent_applies_to_normalize($doc['applies_to'] ?? '');
        if (!$applies) { return false; }
        $roles = zw_legal_consent_user_roles($user_id);
        foreach ($applies as $role_key) {
            if (!empty($roles[$role_key])) { return true; }
        }
        return false;
    }
}

if (!function_exists('zw_legal_consent_signed')) {
    /**
     * 读取某账号对某协议的**已签署记录**（无则 null）。
     *
     * @param int    $user_id 用户 ID。
     * @param string $doc_id  协议 ID。
     * @return array|null
     */
    function zw_legal_consent_signed($user_id, $doc_id) {
        $user_id = (int) $user_id;
        $doc_id  = trim((string) $doc_id);
        if ($user_id <= 0 || $doc_id === '' || !function_exists('get_user_meta')) { return null; }
        $all = get_user_meta($user_id, ZW_LEGAL_CONSENT_META, true);
        if (!is_array($all) || !isset($all[$doc_id]) || !is_array($all[$doc_id])) { return null; }
        return $all[$doc_id];
    }
}

if (!function_exists('zw_legal_consent_user_state')) {
    /**
     * 某账号在某协议下的签署状态（**canonical 取数口径**：管理面 T4、提醒 T5、机构页协议列共用）。
     *
     * TASK-990 由 `inc/admin/policy-platform-consent.php` 上移到引擎：提醒在 cron/CLI 语境执行，
     * 那里不会加载 `is_admin()` 守卫的后台文件，故状态判定必须放在始终加载的引擎里。
     *
     * @param int   $user_id 账号。
     * @param array $doc     归一后的协议结构。
     * @return array{code:string,at:int,role:string,inst:int,read_seconds:int|null,snap:string,version:string,name:string}
     *         code ∈ signed（当前版本已确认）| stale（签过但正文已变更）| unsigned（未签）
     */
    function zw_legal_consent_user_state($user_id, $doc) {
        $user_id = (int) $user_id;
        $empty = array('code' => 'unsigned', 'at' => 0, 'role' => '', 'inst' => 0, 'read_seconds' => null, 'snap' => '', 'version' => '', 'name' => '');
        if ($user_id <= 0 || !is_array($doc) || !function_exists('zw_legal_consent_signed')) { return $empty; }

        $signed = zw_legal_consent_signed($user_id, (string) ($doc['doc_id'] ?? ''));
        $hash   = (string) ($doc['source_hash'] ?? '');
        $out    = $empty;
        if (is_array($signed)) {
            $out['at']           = (int) ($signed['at'] ?? 0);
            $out['role']         = (string) ($signed['role'] ?? '');
            $out['inst']         = (int) ($signed['inst'] ?? 0);
            $out['read_seconds'] = (isset($signed['read_seconds']) && $signed['read_seconds'] !== null) ? (int) $signed['read_seconds'] : null;
            $out['snap']         = (string) ($signed['snap'] ?? '');
            $out['version']      = (string) ($signed['version'] ?? '');
            $out['name']         = (string) ($signed['name'] ?? '');
            $out['code']         = ($hash !== '' && (string) ($signed['hash'] ?? '') === $hash) ? 'signed' : 'stale';
        }
        if ($out['role'] === '' && function_exists('zw_legal_consent_user_roles')) {
            $roles = zw_legal_consent_user_roles($user_id);
            foreach ((array) ($doc['applies_to'] ?? array()) as $rk) {
                if (!empty($roles[$rk])) { $out['role'] = (string) $rk; $out['inst'] = (int) $roles[$rk][0]; break; }
            }
        }
        return $out;
    }
}

if (!function_exists('zw_legal_consent_signable')) {
    /**
     * 该协议**当前是否可签署**（TASK-997 互锁唯一口径）。
     *
     * 判据只有一个：**生效正文哈希非空**（= 至少有一条 `已确认` 条款）。
     * 哈希为空说明正文尚未确认发布，`zw_legal_consent_sign()` 会以 `zw_legal_empty_body` 拒绝，
     * 此时若仍要求签署就等于"被拦却无法合规"——故**不可签署即不要求、不拦截、不提醒**。
     *
     * 与 SPEC-88「表缺失/模块未加载一律判定无需签署，绝不阻断」同一精神；管理面展示不受影响。
     *
     * @param array $doc 归一后的协议结构。
     * @return bool
     */
    function zw_legal_consent_signable($doc) {
        if (!is_array($doc)) { return false; }
        return (string) ($doc['source_hash'] ?? '') !== '';
    }
}

if (!function_exists('zw_legal_consent_must_sign')) {
    /**
     * 该账号当前**待签署**的协议清单（判定入口）。
     *
     * @param int $user_id 用户 ID。
     * @return array[] 每项：doc（结构）、signed（或 null）、reason（unsigned|version_changed）
     */
    function zw_legal_consent_must_sign($user_id) {
        $user_id = (int) $user_id;
        $out = array();
        if ($user_id <= 0) { return $out; }
        foreach (zw_legal_consent_docs() as $doc) {
            if (!zw_legal_consent_applies($user_id, $doc)) { continue; }
            // TASK-997 互锁：正文尚未确认发布（生效哈希为空）→ 不可签署 → 不要求、不拦截。
            if (!zw_legal_consent_signable($doc)) { continue; }
            $hash = (string) $doc['source_hash'];
            $signed = zw_legal_consent_signed($user_id, $doc['doc_id']);
            if ($signed && (string) ($signed['hash'] ?? '') === $hash && $hash !== '') {
                continue; // 已签当前版本
            }
            $out[] = array(
                'doc'    => $doc,
                'signed' => $signed,
                'reason' => $signed ? 'version_changed' : 'unsigned',
            );
        }
        return $out;
    }
}

if (!function_exists('zw_legal_consent_hash')) {
    /**
     * 某协议当前生效正文的内容哈希（唯一锚点，SPEC-88 §4）。
     *
     * @param string $doc_id  协议 ID。
     * @param string $version 版本号（仅用于审计展示，不参与计算）。
     * @return string
     */
    function zw_legal_consent_hash($doc_id, $version = '') {
        unset($version); // 版本号不参与计算：哈希只由正文决定。
        if (!function_exists('zw_policy_platform_source_hash')) { return ''; }
        return (string) zw_policy_platform_source_hash((string) $doc_id);
    }
}

if (!function_exists('zw_legal_consent_allowed_html')) {
    /**
     * 正文允许的 HTML 白名单（SPEC-88 §5.4）。
     *
     * @return array
     */
    function zw_legal_consent_allowed_html() {
        return array(
            'p'      => array(),
            'br'     => array(),
            'strong' => array(),
            'em'     => array(),
            'ol'     => array(),
            'ul'     => array(),
            'li'     => array(),
        );
    }
}

if (!function_exists('zw_legal_consent_body_allowed_html')) {
    /**
     * 正文渲染的**完整** kses 白名单（TASK-1004 修复）。
     *
     * 铁律：属性允许必须在基础白名单之上**显式赋值**。
     * **禁止**把基础白名单与属性数组做 PHP 数组联合（`+` 保留**左侧同名键**）——
     * 基础白名单里已有 `'ol' => array()`，右侧的 `class` 允许会被静默丢弃，
     * `wp_kses` 随即把 `<ol class="zw-legal-body">` 洗成 `<ol>`，
     * 导致签署页正文列表选择器失效（缩进/条目间距退化为浏览器默认）。
     *
     * @return array
     */
    function zw_legal_consent_body_allowed_html() {
        $allowed = zw_legal_consent_allowed_html();
        // 显式赋值（不是联合）：列表容器与行内强调需要 class 以命中页面样式。
        $allowed['ol']     = array('class' => true);
        $allowed['ul']     = array('class' => true);
        $allowed['strong'] = array('class' => true);
        $allowed['em']     = array('class' => true);
        $allowed['li']     = array('class' => true);
        $allowed['p']      = array();
        // TASK-1035：条款展示按「章」分组需要块级容器、章标题与行内片段。
        // 只放开 class 属性（无 on* / style / href），不扩大脚本注入面。
        $allowed['div']    = array('class' => true);
        $allowed['h4']     = array('class' => true);
        $allowed['span']   = array('class' => true);
        return $allowed;
    }
}

if (!function_exists('zw_legal_consent_clauses')) {
    /**
     * 读取某协议**已确认**的条款。
     *
     * @param string $doc_id  协议 ID。
     * @param string $version 限定版本（空 = 不限）。
     * @return array[] 每项：article_no / item_no / original_text
     */
    function zw_legal_consent_clauses($doc_id, $version = '') {
        global $wpdb;
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '' || !function_exists('zw_policy_platform_exists') || !zw_policy_platform_exists('policy_clause')) {
            return array();
        }
        $table = zw_policy_platform_table('policy_clause');
        $version = trim((string) $version);
        // TASK-1016：SQL 只做「取数」，**不做文档序** ——
        // MySQL 对中文条号的排序既不按数值也不按文档序（实测 legacy 得 一七三九二五八六四），
        // 故 SQL 一律 `ORDER BY id ASC` 取得确定基序，真正的文档序由 PHP 侧 canonical 比较器决定。
        if ($version !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, article_no, item_no, original_text FROM {$table} WHERE source_id=%s AND status=%s AND policy_version=%s ORDER BY id ASC",
                $doc_id, '已确认', $version
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, article_no, item_no, original_text FROM {$table} WHERE source_id=%s AND status=%s ORDER BY id ASC",
                $doc_id, '已确认'
            ), ARRAY_A);
        }
        if (!is_array($rows)) { return array(); }
        // TASK-1016：文档序 = 条号（中文数字转整数）→ 项号 →（同名键按 id 升序）。
        // 用 usort 而非 SQL：真实库与测试桩行为一致，且「十/十一/二十」这类 Chinese 数字
        // 在 MySQL 无法可靠排序。PHP 8 sort 稳定，等价键保持基序。
        usort($rows, 'zw_legal_consent_clause_compare');
        return $rows;
    }
}

if (!function_exists('zw_legal_consent_render_body')) {
    /**
     * 渲染协议正文（条款分条；输出经白名单净化）。
     *
     * TASK-1014：`db` 模式下正文以**生效版本**为源（统一文本源第 2 期），但
     * **白名单净化不可省** —— DB 正文是后台可编辑内容，必须过 `zw_legal_consent_body_allowed_html()`
     * 后再输出；读取失败/正文为空则落到原条款路径（**回退 legacy**，绝不返回空正文）。
     *
     * @param string $doc_id  协议 ID。
     * @param string $version 版本（空 = 全部已确认条款；db 模式忽略，始终用生效版本）。
     * @return string HTML；两路都取不到正文时返回空串。
     */
    function zw_legal_consent_render_body($doc_id, $version = '') {
        // TASK-1014：db 模式优先取生效版本正文，并**强制**过与条款路径同一套白名单。
        if (function_exists('zw_legal_text_effective_body')) {
            $db_body = zw_legal_text_effective_body($doc_id);
            if (trim((string) $db_body) !== '') {
                if (function_exists('wp_kses')) {
                    $allowed_db = function_exists('zw_legal_consent_body_allowed_html')
                        ? zw_legal_consent_body_allowed_html()
                        : zw_legal_consent_allowed_html();
                    return (string) wp_kses((string) $db_body, $allowed_db);
                }
                return (string) $db_body;
            }
        }
        $clauses = zw_legal_consent_clauses($doc_id, $version);
        if (!$clauses) { return ''; }
        $esc = function ($v) {
            return function_exists('esc_html') ? esc_html((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $html = '<ol class="zw-legal-body">';
        foreach ($clauses as $row) {
            $article = trim((string) ($row['article_no'] ?? ''));
            $item    = trim((string) ($row['item_no'] ?? ''));
            $text    = trim((string) ($row['original_text'] ?? ''));
            if ($text === '' && $article === '') { continue; }
            $head = $article !== '' ? '<strong class="zw-legal-article">' . $esc($article) . '</strong>' : '';
            $sub  = $item !== '' ? '<em class="zw-legal-item">' . $esc($item) . '</em>' : '';
            $html .= '<li>' . $head . $sub . '<p>' . $esc($text) . '</p></li>';
        }
        $html .= '</ol>';
        if (function_exists('wp_kses')) {
            // TASK-1004：白名单必须"建完再传"，禁止用数组联合 +（会丢属性允许）。
            $allowed = function_exists('zw_legal_consent_body_allowed_html')
                ? zw_legal_consent_body_allowed_html()
                : zw_legal_consent_allowed_html();
            $html = (string) wp_kses($html, $allowed);
        }
        return $html;
    }
}

if (!function_exists('zw_legal_consent_article_titles')) {
    /**
     * 条标题（`policy_clause.control_summary`）：`article_no => 标题`（TASK-1035）。
     *
     * 用途**仅限展示**：签署页按章分组时给「一」配一个可读标题（如「签署主体与身份」）。
     * 正文仍是唯一文本源——条款表取不到标题时按「无标题」渲染，绝不因此丢正文。
     *
     * @param string $doc_id 协议 ID。
     * @return array<string,string>
     */
    function zw_legal_consent_article_titles($doc_id) {
        global $wpdb;
        $out    = array();
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '' || !function_exists('zw_policy_platform_exists') || !zw_policy_platform_exists('policy_clause')) {
            return $out;
        }
        $table = zw_policy_platform_table('policy_clause');
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT article_no, control_summary FROM {$table} WHERE source_id=%s AND status=%s ORDER BY id ASC",
            $doc_id, '已确认'
        ), ARRAY_A);
        foreach ((array) $rows as $row) {
            $article = trim((string) ($row['article_no'] ?? ''));
            $title   = trim((string) ($row['control_summary'] ?? ''));
            if ($article === '' || $title === '' || isset($out[$article])) { continue; }
            $out[$article] = $title;
        }
        return $out;
    }
}

if (!function_exists('zw_legal_consent_identity_tokens')) {
    /**
     * 正文占位符 → 当前用户真实身份（TASK-1035，用户拍板口径）。
     *
     * 正文模板里保留 `{{机构名称}}` / `{{角色名称}}` 两个代词，**渲染时**按登录用户替换：
     *  - 机构名称＝该用户在本协议适用角色下管理的机构标题；取不到 → `所属分支机构`；
     *  - 角色名称＝canonical 角色中文标签（分支机构总管理员 / 分支机构管理员）；取不到 → `分支机构负责人`。
     *
     * 只替换代词，不改正文、不改 `body_hash` → **不触发重新签署**。
     *
     * @param int $user_id 用户 ID（0 = 当前登录用户）。
     * @return array<string,string> 代词 => 明文（未转义）
     */
    function zw_legal_consent_identity_tokens($user_id = 0) {
        $user_id = (int) $user_id;
        if ($user_id <= 0 && function_exists('get_current_user_id')) { $user_id = (int) get_current_user_id(); }

        $role_key = '';
        $inst_id  = 0;
        if ($user_id > 0 && function_exists('zw_legal_consent_user_roles') && function_exists('zw_legal_consent_role_keys')) {
            $roles = zw_legal_consent_user_roles($user_id);
            foreach (zw_legal_consent_role_keys() as $rk) {
                if (!empty($roles[$rk])) { $role_key = (string) $rk; $inst_id = (int) $roles[$rk][0]; break; }
            }
        }

        $inst_name  = ($inst_id > 0 && function_exists('get_the_title')) ? trim((string) get_the_title($inst_id)) : '';
        $role_label = ($role_key !== '' && function_exists('zw_branch_front_role_label')) ? trim((string) zw_branch_front_role_label($role_key)) : '';

        return array(
            '{{机构名称}}' => $inst_name !== '' ? $inst_name : '所属分支机构',
            '{{角色名称}}' => $role_label !== '' ? $role_label : '分支机构负责人',
        );
    }
}

if (!function_exists('zw_legal_consent_apply_identity')) {
    /**
     * 把正文 HTML 里的身份代词替换为当前用户真实身份（值经 `esc_html` 转义后插入）(TASK-1035)。
     *
     * @param string $html    已过白名单的正文 HTML（或含代词的纯文本片段）。
     * @param int    $user_id 用户 ID（0 = 当前登录用户）。
     * @return string
     */
    function zw_legal_consent_apply_identity($html, $user_id = 0) {
        $html = (string) $html;
        if ($html === '' || strpos($html, '{{') === false) { return $html; }
        $tokens = zw_legal_consent_identity_tokens($user_id);
        $from   = array();
        $to     = array();
        foreach ($tokens as $key => $value) {
            $from[] = (string) $key;
            $to[]   = function_exists('esc_html') ? esc_html((string) $value) : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        }
        return str_replace($from, $to, $html);
    }
}

if (!function_exists('zw_legal_consent_render_body_display')) {
    /**
     * 签署页正文的**展示渲染**（TASK-1035）：条号每章只出一次 + 章标题 + 身份代词替换。
     *
     * 数据源仍是 `zw_legal_consent_render_body()`（生效正文 / 条款回退，已过白名单）；
     * 本函数只做展示层重排：
     *   1. 用 canonical 解析器 `zw_legal_text_parse_body_clauses()` 取回（条号, 项号, 文本）；
     *   2. 按条号分组：`<h4>一、签署主体与身份</h4>` + `<ol><li>（一）…</li>…</ol>`；
     *   3. 章标题取 `zw_legal_consent_article_titles()`（取不到则只出条号，不丢正文）；
     *   4. 最后替换 `{{机构名称}}` / `{{角色名称}}`。
     *
     * 解析失败 / 无行 → 原样返回（保持既有展示，绝不白屏）。
     *
     * @param string $doc_id  协议 ID。
     * @param string $version 版本（空 = 生效版本 / 全部已确认条款）。
     * @param int    $user_id 用户 ID（0 = 当前登录用户；用于身份代词替换）。
     * @return string HTML
     */
    function zw_legal_consent_render_body_display($doc_id, $version = '', $user_id = 0) {
        $body = zw_legal_consent_render_body($doc_id, $version);
        if (trim((string) $body) === '') { return ''; }

        $rows = array();
        if (function_exists('zw_legal_text_parse_body_clauses')) {
            $rows = zw_legal_text_parse_body_clauses($body);
        }
        if (!$rows) { return zw_legal_consent_apply_identity($body, $user_id); }

        $titles = zw_legal_consent_article_titles($doc_id);
        $esc    = function ($v) {
            return function_exists('esc_html') ? esc_html((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };

        $html    = '';
        $current = null;
        $open    = false;
        foreach ($rows as $row) {
            $article = trim((string) ($row['article_no'] ?? ''));
            $item    = trim((string) ($row['item_no'] ?? ''));
            $text    = (string) ($row['original_text'] ?? '');

            if ($article !== $current) {
                if ($open) { $html .= '</ol></div>'; }
                $current = $article;
                $title   = ($article !== '' && isset($titles[$article])) ? (string) $titles[$article] : '';
                $html   .= '<div class="zw-legal-body-group">';
                if ($article !== '' || $title !== '') {
                    $html .= '<h4 class="zw-legal-article-head">';
                    if ($article !== '') {
                        // 条号后补顿号（已是标点结尾则不重复补），让「一、签署主体与身份」可读。
                        $article_label = preg_match('/[、．.。:：,，]$/u', $article) ? $article : $article . '、';
                        $html .= '<span class="zw-legal-article-no">' . $esc($article_label) . '</span>';
                    }
                    if ($title !== '') { $html .= '<span class="zw-legal-article-title">' . $esc($title) . '</span>'; }
                    $html .= '</h4>';
                }
                $html .= '<ol class="zw-legal-items">';
                $open  = true;
            }

            $sub   = $item !== '' ? '<em class="zw-legal-item">' . $esc($item) . '</em>' : '';
            $html .= '<li class="zw-legal-item-row">' . $sub . '<p>' . $esc($text) . '</p></li>';
        }
        if ($open) { $html .= '</ol></div>'; }

        if (function_exists('wp_kses')) {
            $allowed = function_exists('zw_legal_consent_body_allowed_html')
                ? zw_legal_consent_body_allowed_html()
                : zw_legal_consent_allowed_html();
            $html = (string) wp_kses($html, $allowed);
        }
        return zw_legal_consent_apply_identity($html, $user_id);
    }
}

if (!function_exists('zw_legal_consent_basis_chain')) {
    /**
     * 收集某协议的**依据链**（沿 `policy_relation` 向上，SPEC-88 §2/§3）。
     *
     * 返回按"距离本协议的层数"归类的依据政策（不含本协议自身）：
     *   tier = external（外部政策：仅知悉并遵照）/ internal（协会制度：应遵守）
     *
     * @param string $doc_id 协议 ID。
     * @param int    $depth  最大上溯层数（防环）。
     * @return array[] 每项：doc_id / doc_name / source_type / tier / basis / source_file
     */
    function zw_legal_consent_basis_chain($doc_id, $depth = 4) {
        global $wpdb;
        $doc_id = trim((string) $doc_id);
        $out = array();
        if ($doc_id === '' || !function_exists('zw_policy_platform_exists')) { return $out; }
        if (!zw_policy_platform_exists('policy_relation') || !zw_policy_platform_exists('policy_source')) { return $out; }

        $rel_table = zw_policy_platform_table('policy_relation');
        $src_table = zw_policy_platform_table('policy_source');

        $frontier = array($doc_id);
        $seen     = array($doc_id => true);
        for ($i = 0; $i < max(1, (int) $depth); $i++) {
            if (!$frontier) { break; }
            $placeholders = implode(',', array_fill(0, count($frontier), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT to_id, relation_type FROM {$rel_table} WHERE from_type=%s AND to_type=%s AND from_id IN ({$placeholders}) AND relation_type IN (%s,%s)",
                array_merge(array('policy_source', 'policy_source'), $frontier, array('上位依据', '引用'))
            ), ARRAY_A);
            if (!is_array($rows)) { break; }
            $next = array();
            foreach ($rows as $r) {
                $to = trim((string) ($r['to_id'] ?? ''));
                if ($to === '' || isset($seen[$to])) { continue; }
                $seen[$to] = true;
                $next[] = $to;
            }
            if (!$next) { break; }
            $ph2 = implode(',', array_fill(0, count($next), '%s'));
            $srcs = $wpdb->get_results($wpdb->prepare(
                "SELECT id, doc_name, source_type, basis, source_file FROM {$src_table} WHERE id IN ({$ph2})",
                $next
            ), ARRAY_A);
            foreach ((array) $srcs as $row) {
                $type = (string) ($row['source_type'] ?? '');
                $out[] = array(
                    'doc_id'      => (string) ($row['id'] ?? ''),
                    'doc_name'    => (string) ($row['doc_name'] ?? ''),
                    'source_type' => $type,
                    'tier'        => ($type === '公开法规') ? 'external' : 'internal',
                    'basis'       => (string) ($row['basis'] ?? ''),
                    'source_file' => (string) ($row['source_file'] ?? ''),
                );
            }
            $frontier = $next;
        }
        return $out;
    }
}

if (!function_exists('zw_legal_consent_render_basis')) {
    /**
     * 渲染签署页「依据区」（SPEC-88 §3 三段式 · 签署语义差异）。
     *
     * - **外部政策**：措辞「已知悉并遵照执行」——**不得出现"同意"**（不是缔约对象）；
     * - **内部政策**：措辞「应遵守」；
     * - **本协议**：措辞「我已阅读并同意」。
     *
     * 输出经 `wp_kses` 白名单净化；无依据时返回空串（T3 页面据此不渲染依据区）。
     *
     * @param string $doc_id 协议 ID。
     * @return string HTML
     */
    function zw_legal_consent_render_basis($doc_id) {
        $doc = zw_legal_consent_required($doc_id);
        $chain = zw_legal_consent_basis_chain($doc_id);
        if (!$doc && !$chain) { return ''; }

        $esc = function ($v) {
            return function_exists('esc_html') ? esc_html((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $ext = array(); $int = array();
        foreach ($chain as $row) {
            if ($row['tier'] === 'external') { $ext[] = $row; } else { $int[] = $row; }
        }

        $html  = '<div class="zw-legal-basis">';
        $html .= '<h3 class="zw-legal-basis-title">本协议的依据</h3>';

        if ($ext) {
            $html .= '<section class="zw-legal-basis-ext"><h4>一、外部政策（本会之外制定，仅遵照执行）</h4><ul>';
            foreach ($ext as $row) {
                $html .= '<li><strong>' . $esc($row['doc_name']) . '</strong>'
                       . '　<span class="zw-legal-ack">已知悉并遵照执行</span>'
                       . ($row['basis'] !== '' ? '<em class="zw-legal-basis-note">（依据：' . $esc($row['basis']) . '）</em>' : '')
                       . '</li>';
            }
            $html .= '</ul></section>';
        }
        if ($int) {
            $html .= '<section class="zw-legal-basis-int"><h4>二、协会制度（应遵守）</h4><ul>';
            foreach ($int as $row) {
                $html .= '<li><strong>' . $esc($row['doc_name']) . '</strong>　<span class="zw-legal-ack">应遵守</span></li>';
            }
            $html .= '</ul></section>';
        }
        if ($doc) {
            $html .= '<section class="zw-legal-basis-self"><h4>三、本协议（同意对象）</h4><p><strong>'
                   . $esc($doc['doc_name']) . '</strong>（' . $esc($doc['version_no']) . '）　<span class="zw-legal-ack-agree">我已阅读并同意</span></p></section>';
        }
        $html .= '</div>';

        if (function_exists('wp_kses')) {
            $html = (string) wp_kses($html, array(
                'div' => array('class' => true), 'section' => array('class' => true),
                'h3' => array('class' => true), 'h4' => array(), 'ul' => array(), 'li' => array(),
                'strong' => array(), 'em' => array('class' => true),
                'span' => array('class' => true), 'p' => array(),
            ));
        }
        return $html;
    }
}

if (!function_exists('zw_legal_consent_snapshot_base_dir')) {
    /**
     * 快照根目录（上传目录下的私有子目录）。
     *
     * @return string
     */
    function zw_legal_consent_snapshot_base_dir() {
        if (function_exists('wp_upload_dir')) {
            $u = wp_upload_dir();
            if (is_array($u) && !empty($u['basedir'])) {
                return rtrim((string) $u['basedir'], '/\\') . '/' . ZW_LEGAL_CONSENT_SNAPSHOT_DIR;
            }
        }
        return '';
    }
}

if (!function_exists('zw_legal_consent_snapshot_write')) {
    /**
     * 落盘签署时的正文快照（举证用；SPEC-88 §5.1）。
     *
     * @param int    $user_id 用户 ID。
     * @param string $doc_id  协议 ID。
     * @param string $version 版本号。
     * @param string $html    正文 HTML。
     * @param int    $ts      签署时间戳。
     * @return string|WP_Error 相对基目录的路径；失败返回 WP_Error。
     */
    function zw_legal_consent_snapshot_write($user_id, $doc_id, $version, $html, $ts = 0) {
        $base = zw_legal_consent_snapshot_base_dir();
        if ($base === '') {
            return new WP_Error('zw_legal_snapshot_dir', '快照目录不可用');
        }
        $ts      = $ts > 0 ? (int) $ts : (function_exists('time') ? time() : 0);
        $doc_id  = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $doc_id);
        $version = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', (string) $version);
        $rel     = $doc_id . '/' . $version . '/' . (int) $user_id . '-' . $ts . '.html';
        $dir     = $base . '/' . $doc_id . '/' . $version;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return new WP_Error('zw_legal_snapshot_mkdir', '快照目录创建失败');
        }
        // 目录防直访（Apache）；nginx 侧由发布说明要求站点加 deny。
        if (!file_exists($base . '/.htaccess')) {
            @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!file_exists($base . '/index.php')) {
            @file_put_contents($base . '/index.php', "<?php // Silence is golden.\n");
        }
        $doc = "<!doctype html><html lang=\"zh-CN\"><head><meta charset=\"utf-8\">"
             . "<title>" . htmlspecialchars($doc_id . ' ' . $version, ENT_QUOTES, 'UTF-8') . "</title></head><body>"
             . "<p>user_id=" . (int) $user_id . " doc=" . htmlspecialchars($doc_id, ENT_QUOTES, 'UTF-8')
             . " version=" . htmlspecialchars($version, ENT_QUOTES, 'UTF-8')
             . " signed_at=" . gmdate('Y-m-d H:i:s', $ts + 8 * 3600) . " (UTC+8)</p>"
             . $html . "</body></html>";
        if (@file_put_contents($base . '/' . $rel, $doc) === false) {
            return new WP_Error('zw_legal_snapshot_write', '快照写入失败');
        }
        return $rel;
    }
}

if (!function_exists('zw_legal_consent_snapshot_read')) {
    /**
     * 读取快照（鉴权：本人或 `manage_options`；并校验路径必须落在快照根目录内）。
     *
     * @param int    $user_id   目标用户 ID（记录归属）。
     * @param string $rel_path  相对路径（签署时写入）。
     * @param int    $reader_id 读取者 ID（0 = 当前用户）。
     * @return string|WP_Error
     */
    function zw_legal_consent_snapshot_read($user_id, $rel_path, $reader_id = 0) {
        $user_id   = (int) $user_id;
        $reader_id = $reader_id > 0 ? (int) $reader_id : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
        if ($user_id <= 0 || $reader_id <= 0) {
            return new WP_Error('zw_legal_snapshot_forbidden', '无权访问');
        }
        $is_admin = function_exists('current_user_can') && (current_user_can('manage_options') || current_user_can('tcpc_perm_legal'));
        if ($reader_id !== $user_id && !$is_admin) {
            return new WP_Error('zw_legal_snapshot_forbidden', '无权访问');
        }
        $base = zw_legal_consent_snapshot_base_dir();
        $rel  = ltrim(str_replace('\\', '/', (string) $rel_path), '/');
        if ($base === '' || $rel === '' || strpos($rel, '..') !== false) {
            return new WP_Error('zw_legal_snapshot_path', '路径非法');
        }
        $full = $base . '/' . $rel;
        $real = @realpath($full);
        $realbase = @realpath($base);
        if ($real === false || $realbase === false || strpos($real, $realbase) !== 0 || !is_file($real)) {
            return new WP_Error('zw_legal_snapshot_missing', '快照不存在');
        }
        $body = @file_get_contents($real);
        return $body === false ? new WP_Error('zw_legal_snapshot_read', '快照读取失败') : (string) $body;
    }
}

if (!function_exists('zw_legal_consent_granted_at')) {
    /**
     * 该账号获得"适用该协议"角色的时间戳（宽限期计算用）。
     *
     * 现状：角色授权时间未单独落库（只在前端角色 meta 上体现），故返回 0 = 未知。
     * 口径（SPEC-88 / 方案 R4）：未知时**不给予宽限**（安全优先），T3 可接入更精确来源。
     *
     * @param int    $user_id 用户 ID。
     * @param string $doc_id  协议 ID。
     * @return int 0 = 未知。
     */
    function zw_legal_consent_granted_at($user_id, $doc_id = '') {
        $ts = 0;
        if (function_exists('apply_filters')) {
            $ts = (int) apply_filters('zw_legal_consent_granted_at', 0, (int) $user_id, (string) $doc_id);
        }
        return $ts > 0 ? $ts : 0;
    }
}

if (!function_exists('zw_legal_consent_sign')) {
    /**
     * 执行签署（服务端唯一入口）。
     *
     * @param int    $user_id    用户 ID。
     * @param string $doc_id     协议 ID。
     * @param string $typed_name 手输本人姓名（须与实名全等）。
     * @param array  $opts       可选：ip / ua / ts。
     * @return true|WP_Error
     */
    function zw_legal_consent_sign($user_id, $doc_id, $typed_name, $opts = array()) {
        $user_id = (int) $user_id;
        $doc_id  = trim((string) $doc_id);
        if ($user_id <= 0 || !function_exists('get_userdata') || !get_userdata($user_id)) {
            return new WP_Error('zw_legal_user', '用户不存在');
        }
        $doc = zw_legal_consent_required($doc_id);
        if (!$doc) {
            return new WP_Error('zw_legal_not_effective', '该协议当前不是可签署状态');
        }
        if (!zw_legal_consent_applies($user_id, $doc)) {
            return new WP_Error('zw_legal_not_applicable', '该协议不适用于当前账号的角色');
        }
        // 实名一致性（SPEC-88 §5.2：意愿表示须为"本人真实姓名"）。
        $real = function_exists('get_user_meta') ? trim((string) get_user_meta($user_id, 'zw_real_name', true)) : '';
        if ($real === '') {
            return new WP_Error('zw_legal_no_realname', '请先完成实名认证再签署');
        }
        if (trim((string) $typed_name) !== $real) {
            return new WP_Error('zw_legal_name_mismatch', '输入的姓名与实名不一致，请填写本人真实姓名');
        }
        // 服务端重算哈希（不信任前端）。
        $hash = zw_legal_consent_hash($doc_id, (string) $doc['version_no']);
        if ($hash === '') {
            return new WP_Error('zw_legal_empty_body', '该协议尚无已确认条款，暂不能签署');
        }
        // 幂等：同版本同哈希已签 → 直接成功，不重复写库。
        $signed = zw_legal_consent_signed($user_id, $doc_id);
        if ($signed && (string) ($signed['hash'] ?? '') === $hash) {
            return true;
        }
        $ts = isset($opts['ts']) ? (int) $opts['ts'] : (function_exists('time') ? time() : 0);
        $ip = isset($opts['ip']) ? (string) $opts['ip'] : (isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
        $ua = isset($opts['ua']) ? (string) $opts['ua'] : (isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '');
        $ua = function_exists('mb_substr') ? mb_substr($ua, 0, 200, 'UTF-8') : substr($ua, 0, 200);

        // 阅读门禁（TASK-963 / TASK-962 R3）：min>0 时必须「已开始阅读且达到最短时长」。
        // 这是**服务端**校验——前端倒数可被改 DOM 或直接 POST 绕过，故必须在此拦截。
        $min_read = zw_legal_consent_min_read_seconds($doc_id);
        $read_seconds = null;
        if ($min_read > 0) {
            $elapsed = zw_legal_consent_read_elapsed($user_id, $doc_id, $hash);
            if ($elapsed === null || $elapsed < $min_read) {
                return new WP_Error('zw_legal_read_too_short', '阅读时间不足，请完整阅读后再确认。');
            }
            $read_seconds = $elapsed;
        }

        // 快照必须先成功：失败即不得视为签署成功（SPEC-88 §5.3）。
        // TASK-1035：快照与签署页**同源同形** —— 用展示渲染器（条号分章 + 身份代词已替换），
        // 存证记录的正是用户当时看到的文本，避免「页面显示真实机构名、存证却是 {{机构名称}}」。
        $body = function_exists('zw_legal_consent_render_body_display')
            ? zw_legal_consent_render_body_display($doc_id, (string) $doc['version_no'], $user_id)
            : zw_legal_consent_render_body($doc_id, (string) $doc['version_no']);
        $snap = zw_legal_consent_snapshot_write($user_id, $doc_id, (string) $doc['version_no'], $body, $ts);
        if (function_exists('is_wp_error') && is_wp_error($snap)) {
            return $snap;
        }
        if (is_object($snap) && !is_string($snap)) {
            return new WP_Error('zw_legal_snapshot_write', '快照写入失败');
        }

        $record = array(
            'version' => (string) $doc['version_no'],
            'hash'    => $hash,
            'at'      => $ts,
            'name'    => $real,
            'snap'    => (string) $snap,
            'inst'    => 0,
            'role'    => '',
            'read_seconds' => $read_seconds,   // TASK-962 D5：进入→确认耗时（本任务起记录）
        );
        $roles = zw_legal_consent_user_roles($user_id);
        foreach (zw_legal_consent_role_keys() as $rk) {
            if (!empty($roles[$rk])) { $record['role'] = $rk; $record['inst'] = (int) $roles[$rk][0]; break; }
        }

        if (function_exists('get_user_meta') && function_exists('update_user_meta')) {
            $all = get_user_meta($user_id, ZW_LEGAL_CONSENT_META, true);
            if (!is_array($all)) { $all = array(); }
            $all[$doc_id] = $record;
            update_user_meta($user_id, ZW_LEGAL_CONSENT_META, $all);
        } else {
            return new WP_Error('zw_legal_meta', '签署记录写入失败');
        }

        // 审计：本系统审计表 + 政策平台审计表（均只追加）。
        if (function_exists('zw_mc_audit_write')) {
            zw_mc_audit_write('legal_consent_sign', 'user#' . $user_id, $doc_id . '@' . $record['version'], array(
                'context' => array(
                    'doc_id'     => $doc_id,
                    'version'    => $record['version'],
                    'hash'       => $hash,
                    'inst'       => $record['inst'],
                    'role'       => $record['role'],
                    'name_typed' => $real,
                    'ua'         => $ua,
                    'snapshot'   => $record['snap'],
                ),
                'ip' => $ip,
            ), $user_id);
        }
        if (function_exists('zw_policy_platform_audit_insert')) {
            zw_policy_platform_audit_insert('consent_sign', 'policy_source', array($doc_id), null, array(
                'user_id' => $user_id, 'version' => $record['version'], 'hash' => $hash,
            ), (string) $user_id, '签署 ' . $doc_id . '@' . $record['version']);
        }
        return true;
    }
}

if (!function_exists('zw_legal_consent_clear')) {
    /**
     * 清除某账号的签署记录（仅用于回退/测试；正常业务不调用）。
     *
     * @param int    $user_id 用户 ID。
     * @param string $doc_id  协议 ID（空 = 全部）。
     * @return bool
     */
    function zw_legal_consent_clear($user_id, $doc_id = '') {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !function_exists('delete_user_meta')) { return false; }
        $doc_id = trim((string) $doc_id);
        if ($doc_id === '') {
            delete_user_meta($user_id, ZW_LEGAL_CONSENT_META);
            return true;
        }
        $all = get_user_meta($user_id, ZW_LEGAL_CONSENT_META, true);
        if (!is_array($all) || !isset($all[$doc_id])) { return true; }
        unset($all[$doc_id]);
        update_user_meta($user_id, ZW_LEGAL_CONSENT_META, $all);
        return true;
    }
}

/*
 * ───────────── TASK-2423 / OA 迁移 R3-3：管理面读侧四个函数下沉（S2~S5） ─────────────
 *
 * 由 `inc/admin/policy-platform-consent.php:43/100/115/137` 搬来（函数体原样）。理由：
 *  - `zw_legal_consent_admin_can()` 被内核的提醒与可见性判定消费（`legal-consent-notify.php:464`、
 *    `legal-text-source.php:796`），而提醒在 **cron/CLI** 语境执行 —— 那里不会加载 `is_admin()` 门控的后台文件；
 *  - `zw_legal_consent_admin_user_status()` 被内核后台页机构「管理员」页消费（`page-branches.php:4151`），
 *    若随插件走，插件关闭时该列退化为 `—`；
 *  - `_user_state` / `_state_label` 是 `_user_status` 的内依赖，必须同批下沉（否则留下未定义依赖）。
 *
 * 本文件由 `functions.php` 无条件 require。双声明纪律：插件已声明同名函数 ⇒ 本定义让位；
 * 主题 `inc/admin/policy-platform-consent.php` 里的同名定义（4 处）保留且**同样带守卫** ⇒ 任意加载顺序都不 fatal。
 */
if (!function_exists('zw_legal_consent_admin_can')) {
    /**
     * 管理面能力位（沿用 AI 法务部既有 `tcpc_perm_legal`）。
     *
     * @return bool
     */
    function zw_legal_consent_admin_can() {
        return function_exists('current_user_can') ? (bool) current_user_can('tcpc_perm_legal') : false;
    }
}

if (!function_exists('zw_legal_consent_admin_user_state')) {
    /**
     * 兼容别名（T4 调用点）：委托引擎 canonical `zw_legal_consent_user_state()`。
     *
     * TASK-990 已把状态判定上移到引擎——提醒在 cron/CLI 语境执行，那里不会加载本后台文件。
     *
     * @param int   $user_id 账号。
     * @param array $doc     归一后的协议结构。
     * @return array{code:string,at:int,role:string,inst:int,read_seconds:int|null,snap:string,version:string,name:string}
     */
    function zw_legal_consent_admin_user_state($user_id, $doc) {
        if (function_exists('zw_legal_consent_user_state')) { return zw_legal_consent_user_state($user_id, $doc); }
        return array('code' => 'unsigned', 'at' => 0, 'role' => '', 'inst' => 0, 'read_seconds' => null, 'snap' => '', 'version' => '', 'name' => '');
    }
}

if (!function_exists('zw_legal_consent_admin_state_label')) {
    /**
     * 状态文案（唯一出处；管理面与机构「管理员」列共用）。
     *
     * @param string $code      signed|stale|unsigned|none
     * @param array  $state     zw_legal_consent_admin_user_state() 结果。
     * @param string $version_no 协议当前版本号（展示用）。
     * @return string
     */
    function zw_legal_consent_admin_state_label($code, $state = array(), $version_no = '') {
        $label_ver = function_exists('zw_legal_consent_version_label')
            ? zw_legal_consent_version_label((string) ($state['version'] ?? '') !== '' ? (string) $state['version'] : (string) $version_no)
            : '';
        if ($code === 'signed') {
            $at = (int) ($state['at'] ?? 0);
            $date = $at > 0 ? (function_exists('wp_date') ? wp_date('Y-m-d', $at) : gmdate('Y-m-d', $at + 8 * 3600)) : '';
            return trim('已签 ' . $label_ver . ($date !== '' ? ' · ' . $date : ''));
        }
        if ($code === 'stale') { return '规范已更新'; }
        if ($code === 'unsigned') { return '未签'; }
        return '—';
    }
}

if (!function_exists('zw_legal_consent_admin_user_status')) {
    /**
     * 单账号的「协议」列状态（机构「管理员」页复用；取**第一份生效且适用**的协议）。
     *
     * @param int $user_id 账号。
     * @return array{code:string,label:string,badge:string,doc_id:string,at:int}
     */
    function zw_legal_consent_admin_user_status($user_id) {
        $none = array('code' => 'none', 'label' => '—', 'badge' => 'off', 'doc_id' => '', 'at' => 0);
        if (!function_exists('zw_legal_consent_docs') || !function_exists('zw_legal_consent_applies')) { return $none; }
        foreach (zw_legal_consent_docs() as $doc) {
            if (!zw_legal_consent_applies($user_id, $doc)) { continue; }
            $state = zw_legal_consent_admin_user_state($user_id, $doc);
            $code  = (string) $state['code'];
            return array(
                'code'   => $code,
                'label'  => zw_legal_consent_admin_state_label($code, $state, (string) $doc['version_no']),
                'badge'  => ($code === 'signed') ? 'on' : (($code === 'stale') ? 'wait' : 'err'),
                'doc_id' => (string) $doc['doc_id'],
                'at'     => (int) $state['at'],
            );
        }
        return $none;
    }
}
