<?php
/**
 * 政策法规应用 · 政策规则引擎（SPEC-93 · TASK-1598 · B2-a / T4）。
 *
 * 为什么要有这个文件（需求稿 §1.3 G1 + R-10 / R-8）：
 *  - 生产库 `zw_policy_rule` 有 **11 条 `enabled=1`** 的执行规则，`zw_policy_clause` 有 7 条任职年龄
 *    红线条款，但**运行时没有任何执行器**（`zw_policy_control_log` 全表 1 条建库冒烟）；
 *  - 「70/65 岁」判定此前没有唯一出处，各模块若各自实现 ⇒ 阈值与口径必然分叉（R-2 静默失效）。
 *
 * 本文件是**运行时判定的唯一出处**（模板 / 写入口 / 页面只调用，不复制判定）：
 *  1. `zw_policy_rule_module_match()` —— 规则 ↔ 模块匹配（**未配置模块 ⇒ 不触发**，SPEC-93 §五）；
 *  2. `zw_policy_check()`            —— canonical 判定入口，返回 `{decision,matched,hits,log_id}`；
 *  3. `zw_policy_age_hits()`         —— 任职年龄命中（派生，**不写库、不阻断**）；
 *  4. `zw_policy_log()`              —— 控制台账 `zw_policy_control_log` 的**首个生产写入口**；
 *  5. `zw_policy_issue_open()`       —— 整改工单 `zw_policy_issue` 的幂等打开（同人+条款+周期不重复）。
 *
 * **口径与边界（一票否决）**：
 *  - 三层开关（`inc/policy-platform/policy-module-map.php`）：全局 off ⇒ 本文件**零查询、零写入**，
 *    直接返回 `pass`；表缺失 ⇒ 同样放行 + warning（fail-open，SPEC-93 §九）；
 *  - **本轮不做硬阻断**：`warn` 级映射为 `defer`（暂缓 + 转人事和合规复核）；
 *    `block` 是契约保留值，**只有显式声明 `action=block` 的规则**才会产出，本批规则不声明；
 *  - `over` 级**只派生标记**，**禁止**批量改写负责人 / 人员业务状态（T9 红线，本文件无任何 UPDATE）；
 *  - **个人信息最小必要**：`context_json` 只放 `uid / person_id / 周岁 / 判定日 / 模块键`，
 *    **绝不含**身份证号、生日、姓名（`zw_policy_context_json()` 白名单过滤，过滤后仍不落敏感词）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_policy_engine_version')) {
    /**
     * 引擎版本（R-8 留痕：判定结论必须可复现）。
     *
     * @return string
     */
    function zw_policy_engine_version() {
        return 'policy-engine/1.0.0';
    }
}

if (!function_exists('zw_policy_text')) {
    /**
     * 单行文本归一（无 WP 环境退化）。
     *
     * @param mixed $value
     * @return string
     */
    function zw_policy_text($value) {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = (string) $value;
        if (function_exists('wp_unslash')) {
            $value = (string) wp_unslash($value);
        }
        if (function_exists('sanitize_text_field')) {
            return trim((string) sanitize_text_field($value));
        }
        return trim((string) str_replace(array("\0", "\r", "\n", "\t"), '', strip_tags($value)));
    }
}

if (!function_exists('zw_policy_engine_warn')) {
    /**
     * 降级告警（fail-open 时必须留痕；不得静默）。
     *
     * @param string $message
     * @return void
     */
    function zw_policy_engine_warn($message) {
        $line = '[tcpc policy-engine] ' . (string) $message;
        if (function_exists('error_log')) {
            error_log($line);
            return;
        }
        if (function_exists('trigger_error')) {
            trigger_error($line, E_USER_WARNING);
        }
    }
}

if (!function_exists('zw_policy_table')) {
    /**
     * 政策平台表名（优先复用后台 helper；CLI / 前台无后台上下文时本地等价实现）。
     *
     * @param string $name
     * @return string
     */
    function zw_policy_table($name) {
        if (function_exists('zw_policy_platform_table')) {
            return (string) zw_policy_platform_table($name);
        }
        global $wpdb;
        $prefix = (is_object($wpdb) && isset($wpdb->prefix)) ? (string) $wpdb->prefix : 'wp_';
        return $prefix . 'zw_' . (string) $name;
    }
}

if (!function_exists('zw_policy_table_exists')) {
    /**
     * 表是否存在（唯一探测口径；`get_var` 不可用 ⇒ fail-open 视为不存在）。
     *
     * @param string $name
     * @return bool
     */
    function zw_policy_table_exists($name) {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }
        $table = zw_policy_table($name);
        if (method_exists($wpdb, 'prepare')) {
            $sql = $wpdb->prepare('SHOW TABLES LIKE %s', $table);
        } else {
            $sql = "SHOW TABLES LIKE '" . $table . "'";
        }
        return $wpdb->get_var($sql) === $table;
    }
}

if (!function_exists('zw_policy_table_columns')) {
    /**
     * 读表列名（`SHOW COLUMNS`）；表缺失 / 无 $wpdb ⇒ 空数组（= schema 未就绪）。
     *
     * 为什么先探列：`zw_policy_control_log` / `zw_policy_issue` 的实际列集本轮**未在生产库复核**，
     * 直接写全字段一旦列名不符就会在运行期抛 SQL 错误；先探列才能做到「缺列 ⇒ 降级 + warning」。
     *
     * @param string $name
     * @return array<int,string>
     */
    function zw_policy_table_columns($name) {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return array();
        }
        $table = zw_policy_table($name);
        $rows  = $wpdb->get_results('SHOW COLUMNS FROM ' . $table);
        if (!is_array($rows)) {
            return array();
        }
        $cols = array();
        foreach ($rows as $row) {
            if (is_object($row) && isset($row->Field)) {
                $cols[] = (string) $row->Field;
            } elseif (is_array($row) && isset($row['Field'])) {
                $cols[] = (string) $row['Field'];
            }
        }
        return $cols;
    }
}

/* ═════════════════════════ 规则 ↔ 模块匹配（SPEC-93 §五） ═════════════════════════ */

if (!function_exists('zw_policy_normalize_key')) {
    /**
     * 归一政策域键 / 功能点键：只保留注册表内的合法键，其余（含历史中文旧值）⇒ 空串。
     *
     * 口径：**越界键不参与匹配**，且**空配置 ≠ 全触发**（SPEC-93 §五 第 3/4 条）。
     *
     * @param mixed $raw
     * @return string
     */
    function zw_policy_normalize_key($raw) {
        $token = strtolower(zw_policy_text($raw));
        if ($token === '') {
            return '';
        }
        $feature = function_exists('zw_policy_feature_keys') ? zw_policy_feature_keys() : array();
        if (isset($feature[$token])) {
            return $token;
        }
        $modules = function_exists('zw_policy_module_keys') ? zw_policy_module_keys() : array();
        if (isset($modules[$token])) {
            return $token;
        }
        return '';
    }
}

if (!function_exists('zw_policy_business_module_tokens')) {
    /**
     * 解析 `zw_policy_rule.business_module`（JSON 数组 / 逗号串 / 裸键）为**合法键**列表。
     *
     * `business_module varchar(128)` 的既有形态：JSON 数组（目标形态）／逗号串／中文业务名（迁移前）。
     * 中文旧值与越界键一律被 `zw_policy_normalize_key()` 过滤 ⇒ 不触发（fail-closed 到「不触发」侧）。
     *
     * @param mixed $raw
     * @return array<int,string>
     */
    function zw_policy_business_module_tokens($raw) {
        if (is_array($raw)) {
            $list = $raw;
        } else {
            $text = zw_policy_text($raw);
            if ($text === '') {
                return array();
            }
            $decoded = json_decode($text, true);
            $list    = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $text);
        }
        $out = array();
        foreach ((array) $list as $item) {
            $key = zw_policy_normalize_key($item);
            if ($key !== '') {
                $out[$key] = true;
            }
        }
        return array_keys($out);
    }
}

if (!function_exists('zw_policy_rule_module_match')) {
    /**
     * 规则是否作用于给定功能点 / 政策域（**未配置模块 ⇒ false**，SPEC-93 §五）。
     *
     * 匹配语义：
     *  - 功能点配置命中自身（`branch.leaders` 只命中 `branch.leaders`）；
     *  - 域级配置命中该域全部功能点（`branch` 命中 `branch.*`）；
     *  - 域段相等也视为命中（调用方只给域键、规则配的是同域功能点时）；
     *  - **空配置 / 越界键 / 中文旧值 ⇒ false**；**绝不是「未配置 = 全触发」**。
     *
     * @param array  $rule        规则行（数组或对象）；读 `business_module`。
     * @param string $module_key  政策域键（如 `branch`）。
     * @param string $feature_key 功能点键（如 `branch.leaders`）；可为空。
     * @return bool
     */
    function zw_policy_rule_module_match($rule, $module_key, $feature_key = '') {
        $rule = (array) $rule;
        $raw  = array_key_exists('business_module', $rule) ? $rule['business_module'] : '';
        $keys = zw_policy_business_module_tokens($raw);
        if (!$keys) {
            return false; // 未配置 ⇒ 不触发
        }

        $feature = zw_policy_normalize_key($feature_key);
        $module  = zw_policy_normalize_key($module_key);
        if ($feature === '' && $module === '') {
            return false; // 无询问对象 / 传入键非法（越界）⇒ 不触发
        }
        if ($feature === '' && strpos($module, '.') !== false) {
            // 调用方把功能点键当域键传入 ⇒ 归一为功能点。
            $feature = $module;
            $module  = substr($module, 0, (int) strpos($module, '.'));
        }

        // 功能点级查询优先：只做「精确键 + 该键所属域的域级配置」匹配，**不**回落到裸域命中。
        if ($feature !== '') {
            $domain = substr($feature, 0, (int) strpos($feature, '.'));
            foreach ($keys as $key) {
                if ($key === $feature) {
                    return true;
                }
                if (strpos($key, '.') === false && $key === $domain) {
                    return true; // 域级配置命中该域全部功能点
                }
            }
            return false;
        }

        // 域级查询（调用方只给域键）：命中同域配置（裸域键 / 该域任意功能点键）。
        if ($module === '') {
            return false;
        }
        foreach ($keys as $key) {
            if ($key === $module) {
                return true;
            }
            if (strpos($key, '.') !== false && substr($key, 0, (int) strpos($key, '.')) === $module) {
                return true;
            }
        }
        return false;
    }
}

/* ═════════════════════════ 规则读取（按域收窄，失败放行） ═════════════════════════ */

if (!function_exists('zw_policy_clause_ids')) {
    /**
     * 解析规则的条款 ID 列表（`zw_policy_rule.policy_rule_ids`，JSON 数组 / 逗号串兼容）。
     *
     * @param mixed $raw
     * @return array<int,string>
     */
    function zw_policy_clause_ids($raw) {
        if (is_array($raw)) {
            $list = $raw;
        } else {
            $text = zw_policy_text($raw);
            if ($text === '') {
                return array();
            }
            $decoded = json_decode($text, true);
            $list    = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $text);
        }
        $out = array();
        foreach ((array) $list as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $id = trim((string) $item);
            if ($id !== '') {
                $out[$id] = true;
            }
        }
        return array_keys($out);
    }
}

if (!function_exists('zw_policy_module_rules')) {
    /**
     * 读启用中的执行规则（域/功能点命中的子集）。
     *
     * 失败策略（SPEC-93 §九）：表缺失 / 查询失败 / 无 `$wpdb` ⇒ **空数组**（= 无匹配 = 放行）+ warning。
     * 注：`enabled=1` 与 `approval_status='已启用'` 的双人审批语义由后台维护，引擎只认 `enabled=1`。
     *
     * @param string $module_key
     * @param string $feature_key
     * @param string $trigger_event
     * @return array<int,array<string,mixed>>
     */
    function zw_policy_module_rules($module_key, $feature_key = '', $trigger_event = '') {
        global $wpdb;
        if (!zw_policy_table_exists('policy_rule')) {
            zw_policy_engine_warn('policy_rule 表缺失 ⇒ 引擎放行（fail-open）');
            return array();
        }
        $table = zw_policy_table('policy_rule');
        $rows  = $wpdb->get_results("SELECT * FROM {$table} WHERE enabled=1", ARRAY_A);
        if (!is_array($rows)) {
            zw_policy_engine_warn('policy_rule 查询失败 ⇒ 引擎放行（fail-open）');
            return array();
        }

        $event   = strtolower(zw_policy_text($trigger_event));
        $matched = array();
        foreach ($rows as $row) {
            $row = (array) $row;
            if (!zw_policy_rule_module_match($row, $module_key, $feature_key)) {
                continue;
            }
            if ($event !== '' && isset($row['trigger_event'])) {
                $rule_event = strtolower(zw_policy_text($row['trigger_event']));
                if ($rule_event !== '' && $rule_event !== $event) {
                    continue;
                }
            }
            $matched[] = $row;
        }
        return $matched;
    }
}

/* ═════════════════════════ 年龄命中（派生，不写库） ═════════════════════════ */

if (!function_exists('zw_policy_age_scope_tokens')) {
    /**
     * 拆 `scopes` 为可比对的关键词（去掉纯数字与过短词，避免误命中）。
     *
     * @param mixed $scopes
     * @return array<int,string>
     */
    function zw_policy_age_scope_tokens($scopes) {
        $out = array();
        foreach ((array) $scopes as $scope) {
            foreach (preg_split('/[、,，\s\/（）()]+/u', (string) $scope) as $token) {
                $token = trim($token);
                if ($token === '' || preg_match('/^\d+$/', $token)) {
                    continue; // 纯数字（如「70」）不作为岗位关键词
                }
                if (mb_strlen($token) < 3) {
                    continue; // 过短（如「的」「和」）易误命中
                }
                $out[$token] = true;
            }
        }
        return array_keys($out);
    }
}

if (!function_exists('zw_policy_age_rule_scope_match')) {
    /**
     * 岗位 / 场景 ⇄ 注册表条目 `scopes` 的关键词匹配（阈值只从注册表取，禁止硬编码）。
     *
     * @param array  $rule  注册表条目。
     * @param string $position 岗位 / 场景描述（可为空）。
     * @return bool
     */
    function zw_policy_age_rule_scope_match($rule, $position) {
        return zw_policy_age_scope_score($rule, $position) > 0;
    }
}

if (!function_exists('zw_policy_age_scope_score')) {
    /**
     * 岗位匹配度（**最长命中关键词长度**；0 = 不匹配）。
     *
     * 为什么需要「度」：注册表 7 条的 `scopes` 会同时命中宽泛岗位描述
     * （如「聘任制秘书长」同时含「负责人」与「秘书长」）。按匹配度取**最specific**条目，
     * 才能让聘任制秘书长按 **65** 判而不是按 70（需求稿 §4 + §5.4 验收⑤）。
     *
     * @param array  $rule
     * @param string $position
     * @return int
     */
    function zw_policy_age_scope_score($rule, $position) {
        $rule     = (array) $rule;
        $position = strtolower(zw_policy_text($position));
        if ($position === '') {
            return 0;
        }
        $best = 0;
        foreach (zw_policy_age_scope_tokens(isset($rule['scopes']) ? $rule['scopes'] : array()) as $token) {
            if (mb_stripos($position, $token) !== false && mb_strlen($token) > $best) {
                $best = mb_strlen($token);
            }
        }
        return $best;
    }
}

if (!function_exists('zw_policy_age_rules_for')) {
    /**
     * 取某岗位适用的阈值条目（阈值条目 + 其派生条目；**最长 scopes 命中优先**）。
     *
     * 语义：
     *  - 同一自然语言岗位上可能同时命中多条（如「聘任制秘书长」同时含「负责人」与「聘任制秘书长」），
     *    此时**只取匹配度最高**一组（聘任制秘书长 ⇒ 只取 `CLAUSE-POL-008` 的 65，不并取 70）；
     *  - 派生条目（`minus_one` / 无数值阈的 `over`）按 `threshold_clause` 归属到来源条款，
     *    **跟随其来源条款的岗位范围**（不再单独按自身 scopes 过滤）。
     *    理由：注册表 `CLAUSE-POL-007`（阈值＝来源 − 1）的 scopes 带「拟提名负责人」注释口径，
     *    若按它自己过滤，会出现「在任负责人 69 岁不提示」的漏洞（R-4 明确要求 69 岁信息级），
     *    且聘任制秘书长会误取「负责人 − 1 = 69」（正确是 65 − 1 = 64）。故以**来源条款的角色**为准。
     *  - 无岗位信息 ⇒ 不猜岗位，返回空（未配置/未标注 ⇒ 不触发）。
     *
     * @param string $position
     * @return array<int,array{rule:array<string,mixed>,score:int}>
     */
    function zw_policy_age_rules_for($position) {
        if (!function_exists('zw_policy_age_rules')) {
            return array();
        }
        $rules = zw_policy_age_rules();
        $best  = 0;
        $prim  = array();
        foreach ($rules as $rule) {
            $mode = (string) ($rule['threshold_mode'] ?? '');
            if ($mode === 'minus_one' || $mode === 'none') {
                continue; // 派生条目由下方按来源条款归属
            }
            $score = zw_policy_age_scope_score($rule, $position);
            if ($score <= 0) {
                continue;
            }
            if ($score > $best) {
                $best = $score;
                $prim = array($rule);
            } elseif ($score === $best) {
                $prim[] = $rule;
            }
        }
        if (!$prim) {
            return array();
        }

        $sources = array();
        $scoped  = array();
        foreach ($prim as $rule) {
            $sources[(string) $rule['clause']] = true;
            $scoped[] = array('rule' => $rule, 'score' => zw_policy_age_scope_score($rule, $position));
        }

        // 派生「距限不满 1 年」条目：**按命中的来源条款动态派生**（阈值 = 来源阈值 − 1），
        // 元数据（level / clause / scopes / note）取自注册表中 `threshold_mode=minus_one` 的条目。
        // 为什么不直接用注册表条目的固定阈值：注册表 `CLAUSE-POL-007` 的 `threshold=69` 是
        // 「70 − 1」这一档；聘任制秘书长的档位是 65 ⇒ 派生口径必须是 **64**。若沿用固定 69，
        // 秘书长 64 岁会漏报、且会误取「70 − 1」。动态派生同时满足 R-4（69 岁信息级）与 §5.4 验收⑤。
        $template = null;
        foreach ($rules as $rule) {
            if ((string) ($rule['threshold_mode'] ?? '') === 'minus_one') {
                $template = $rule;
                break;
            }
        }
        if ($template !== null) {
            foreach ($prim as $rule) {
                if (!is_int($rule['threshold']) && !ctype_digit((string) $rule['threshold'])) {
                    continue;
                }
                $derived          = $template;
                $derived['clause'] = (string) $template['clause'];
                $derived['threshold'] = (int) $rule['threshold'] - 1;
                $derived['threshold_clause'] = (string) $rule['clause'];
                $derived['threshold_mode'] = 'minus_one';
                $derived['level'] = 'info';
                $derived['feature'] = (string) ($rule['feature'] ?? '');
                $derived['features'] = (array) ($rule['features'] ?? array());
                $scoped[] = array('rule' => $derived, 'score' => 0);
            }
        }

        // 无数值阈的 `over` 条目（如 `CLAUSE-POL-005`）由 `zw_policy_age_hits()` 在「已达标」时附带，
        // 这里不并入（它没有自身阈值，不参与 age 比较）。
        return $scoped;
    }
}

if (!function_exists('zw_policy_age_hits')) {
    /**
     * 任职年龄命中（**纯派生**：不查库、不写库、不改业务状态）。
     *
     * 判定口径：
     *  - `as_of`（固定「任职生效日」）当天的周岁 `age = fields.age`（由调用方经 SPEC-85 canonical
     *    身份证控件算出后传入；本函数**不接受生日 / 身份证**，避免个人信息在引擎层停留）；
     *  - 每条适用条目：`age >= threshold` ⇒ 该条目 `level`（`warn` / `over`）；
     *    `minus_one` 派生条目在 `age >= threshold - 1` 时命中，级别固定为 `info`（「距限不满 1 年」）；
     *    按 `over > warn > info` 收敛后，同一条款只保留**最严重**的 `level`（如 70 岁 ⇒ `warn`，不是 `info`）；
     *  - `threshold === null`（`CLAUSE-POL-005`：应当及时退出）⇒ 由同岗位阈值条目命中时**附带**一条
     *    `over` 命中（在任到龄派生标记），而不是凭空造阈值；
     *  - 同一条款只保留**最严重**一条（`over > warn > info`）。
     *
     * @param array $context `{module_key,feature_key,trigger_event,position,as_of,fields:{age}}`。
     * @return array<int,array<string,mixed>> `{clause,threshold,age,level,as_of,rule_code,rule_name}`
     */
    function zw_policy_age_hits($context) {
        $context  = (array) $context;
        $fields   = isset($context['fields']) && is_array($context['fields']) ? $context['fields'] : array();
        $position = isset($context['position']) ? (string) $context['position'] : '';

        if (!array_key_exists('age', $fields)) {
            // 口径：无身份证 / 未实名 ⇒ 无法算周岁 ⇒ **不误判为超龄**（需求稿 §4 异常行）。
            return array();
        }
        $age = $fields['age'];
        if (!is_numeric($age) || (int) $age <= 0) {
            return array();
        }
        $age = (int) $age;

        $as_of = isset($context['as_of']) && $context['as_of'] !== ''
            ? (string) $context['as_of']
            : (function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d'));

        if (!function_exists('zw_policy_age_rules')) {
            return array();
        }

        $severity = array('info' => 1, 'warn' => 2, 'over' => 3);
        $hits     = array();
        $over_reached = false;

        foreach (zw_policy_age_rules_for($position) as $scoped) {
            $rule      = (array) $scoped['rule'];
            $score     = (int) $scoped['score'];
            $clause    = (string) ($rule['clause'] ?? '');
            $threshold = $rule['threshold'];
            $level     = (string) ($rule['level'] ?? 'info');
            $mode      = (string) ($rule['threshold_mode'] ?? 'none');

            if ($clause === '' || $threshold === null) {
                continue; // 无数值阈：由下方 `over` 附带逻辑补齐，不独立命中
            }
            if (!is_int($threshold) && !ctype_digit((string) $threshold)) {
                continue;
            }
            $threshold = (int) $threshold;

            if ($mode === 'minus_one') {
                if ($age < $threshold - 1) {
                    continue; // 「距限不满 1 年」：阈值 − 1 及以上（防止 67 岁也报）
                }
                $level = 'info';
            } elseif ($age < $threshold) {
                continue;
            }

            if ($severity[$level] >= 2) {
                $over_reached = true;
            }

            $entry = array(
                'clause'       => $clause,
                'threshold'    => $threshold,
                'age'          => $age,
                'level'        => $level,
                'as_of'        => $as_of,
                'rule_code'    => '',
                'rule_name'    => '',
                'match_length' => $score,
            );
            if (!isset($hits[$clause])) {
                $hits[$clause] = $entry;
                continue;
            }
            $old = $hits[$clause];
            if ($severity[$entry['level']] > $severity[$old['level']]
                || ($severity[$entry['level']] === $severity[$old['level']] && $entry['match_length'] > $old['match_length'])) {
                $hits[$clause] = $entry;
            }
        }

        // `over`：到龄（已达界限）派生 —— 依据 CLAUSE-POL-005 第(6)项「应当及时退出」。
        // 触发口径：本岗位**确有 warn/over 级阈值命中**（即已达标）才派生；68 岁这种未达标不派生。
        if ($over_reached && $position !== '') {
            $over_rule = null;
            foreach (zw_policy_age_rules() as $rule) {
                if ((string) ($rule['level'] ?? '') === 'over') {
                    $over_rule = $rule;
                    break;
                }
            }
            if ($over_rule !== null) {
                $clause = (string) $over_rule['clause'];
                if (!isset($hits[$clause])) {
                    $hits[$clause] = array(
                        'clause'       => $clause,
                        'threshold'    => null,
                        'age'          => $age,
                        'level'        => 'over',
                        'as_of'        => $as_of,
                        'rule_code'    => '',
                        'rule_name'    => '',
                        'match_length' => 0,
                    );
                }
            }
        }

        return array_values($hits);
    }
}

/* ═════════════════════════ 控制台账写入口（T4：首个生产写入口） ═════════════════════════ */

if (!function_exists('zw_policy_context_json')) {
    /**
     * 归一 `context_json`（**个人信息最小必要**：只放 uid / person_id / 周岁 / 判定日 / 模块键）。
     *
     * @param array $context
     * @return string JSON
     */
    function zw_policy_context_json($context) {
        $context = (array) $context;
        $fields  = isset($context['fields']) && is_array($context['fields']) ? $context['fields'] : array();
        $safe    = array(
            'module_key'    => zw_policy_text($context['module_key'] ?? ''),
            'feature_key'   => zw_policy_text($context['feature_key'] ?? ''),
            'trigger_event' => zw_policy_text($context['trigger_event'] ?? ''),
            'as_of'         => zw_policy_text($context['as_of'] ?? ''),
            'uid'           => isset($fields['uid']) ? (int) $fields['uid'] : 0,
            'person_id'     => zw_policy_text($fields['person_id'] ?? ''),
            'age'           => (array_key_exists('age', $fields) && is_numeric($fields['age'])) ? (int) $fields['age'] : null,
        );
        return function_exists('wp_json_encode')
            ? (string) wp_json_encode($safe)
            : (string) json_encode($safe);
    }
}

if (!function_exists('zw_policy_matched_rules_json')) {
    /**
     * 归一 `matched_rules_json`（只放规则编码 + 条款 ID + 结论，不含个人信息）。
     *
     * @param array $matched
     * @return string JSON
     */
    function zw_policy_matched_rules_json($matched) {
        $out = array();
        foreach ((array) $matched as $rule) {
            $rule = (array) $rule;
            $out[] = array(
                'rule_code' => (string) ($rule['rule_code'] ?? ''),
                'clauses'   => array_values(array_map('strval', (array) ($rule['clauses'] ?? array()))),
                'decision'  => (string) ($rule['decision'] ?? ''),
            );
        }
        return function_exists('wp_json_encode')
            ? (string) wp_json_encode($out)
            : (string) json_encode($out);
    }
}

if (!function_exists('zw_policy_log')) {
    /**
     * 写控制台账 `zw_policy_control_log`（首个生产写入口；`$wpdb->insert` 内部按列转义）。
     *
     * 降级口径（本轮验收硬要求）：
     *  - 开关 off ⇒ 调用方（`zw_policy_check()`）**不进入本函数** ⇒ 零查询零写入；
     *  - 表缺失 / 必需列缺失 ⇒ **返回空串**（= 未落痕）+ warning，**不抛错、不阻断业务**。
     *
     * @param array $context
     * @param array $result
     * @return string log_id（未落痕 ⇒ `''`）
     */
    function zw_policy_log($context, $result) {
        global $wpdb;
        $context = (array) $context;
        $result  = (array) $result;

        if (!is_object($wpdb) || !method_exists($wpdb, 'insert')) {
            zw_policy_engine_warn('无 $wpdb ⇒ 控制台账未写入（fail-open）');
            return '';
        }
        if (!zw_policy_table_exists('policy_control_log')) {
            zw_policy_engine_warn('zw_policy_control_log 表缺失 ⇒ 控制台账未写入（fail-open）');
            return '';
        }

        $required = array('id', 'domain_type', 'business_domain', 'business_module', 'trigger_event', 'context_json', 'decision', 'matched_count', 'matched_rules_json', 'create_time');
        $columns  = zw_policy_table_columns('policy_control_log');
        $usable   = array();
        foreach ($required as $column) {
            if (in_array($column, $columns, true)) {
                $usable[] = $column;
            }
        }
        if (!in_array('id', $usable, true) || !in_array('decision', $usable, true) || !in_array('context_json', $usable, true)) {
            zw_policy_engine_warn('zw_policy_control_log 列集不符（缺 id/decision/context_json）⇒ 控制台账未写入（fail-open）');
            return '';
        }

        $now   = function_exists('current_time') ? (string) current_time('mysql') : date('Y-m-d H:i:s');
        $log_id = function_exists('wp_generate_uuid4')
            ? (string) wp_generate_uuid4()
            : ('PCL-' . gmdate('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8));

        $matched = isset($result['matched']) && is_array($result['matched']) ? $result['matched'] : array();
        $row     = array(
            'id'                 => $log_id,
            'domain_type'        => '业务控制',
            'business_domain'    => zw_policy_text($context['module_key'] ?? ''),
            'business_module'    => zw_policy_text($context['feature_key'] ?? ''),
            'trigger_event'      => zw_policy_text($context['trigger_event'] ?? ''),
            'context_json'       => zw_policy_context_json($context),
            'decision'           => strtoupper(zw_policy_text($result['decision'] ?? 'PASS')),
            'matched_count'      => count($matched),
            'matched_rules_json' => zw_policy_matched_rules_json($matched),
            'create_time'        => $now,
        );

        $row = array_intersect_key($row, array_flip($usable));
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('zw_policy_control_log_row', $row, $context, $result);
            if (is_array($filtered)) {
                $row = $filtered;
            }
        }

        $inserted = $wpdb->insert(zw_policy_table('policy_control_log'), $row);
        if ($inserted === false) {
            $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
            zw_policy_engine_warn('控制台账写入失败 ⇒ 未落痕（fail-open）：' . $error);
            return '';
        }
        return $log_id;
    }
}

/* ═════════════════════════ 整改工单（幂等） ═════════════════════════ */

if (!function_exists('zw_policy_issue_period')) {
    /**
     * 工单周期键（默认「天」，即同人 + 同条款 + 同一天只开一单）。
     *
     * @param array $context
     * @return string `Y-m-d`
     */
    function zw_policy_issue_period($context) {
        $context = (array) $context;
        $as_of   = isset($context['as_of']) ? zw_policy_text($context['as_of']) : '';
        if ($as_of !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $as_of, $m)) {
            return $m[0];
        }
        return function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d');
    }
}

if (!function_exists('zw_policy_issue_id')) {
    /**
     * 工单确定性主键（幂等唯一出处：同 `条款|人|周期` ⇒ 同 ID）。
     *
     * @param array $context
     * @param array $hit
     * @return string
     */
    function zw_policy_issue_id($context, $hit) {
        $context  = (array) $context;
        $hit      = (array) $hit;
        $fields   = isset($context['fields']) && is_array($context['fields']) ? $context['fields'] : array();
        $clause   = zw_policy_text($hit['clause'] ?? '');
        $person   = zw_policy_text($fields['person_id'] ?? '');
        if ($person === '' && isset($fields['uid'])) {
            $person = 'uid' . (int) $fields['uid'];
        }
        if ($person === '') {
            $person = 'unknown';
        }
        $raw   = $clause . '|' . $person . '|' . zw_policy_issue_period($context);
        $slug  = strtolower((string) preg_replace('/[^A-Za-z0-9_\-]+/', '-', $raw));
        $slug  = trim($slug, '-');
        return 'POLAGE-' . substr($slug, 0, 48) . '-' . substr(md5($raw), 0, 8);
    }
}

if (!function_exists('zw_policy_issue_open')) {
    /**
     * 打开整改工单（**幂等**：同 `person + clause + 周期` 已存在 ⇒ 返回既有 ID，不再插入）。
     *
     * 归属口径（需求稿 §9 开放项 3 / 本文件登记）：`zw_policy_issue` 已被收文管理复用为承办台账，
     * 本函数以 `source_type='policy_age'` 区分「到龄」来源；写入前先探列，缺列 ⇒ 不写 + warning。
     *
     * @param array $context
     * @param array $hit
     * @return int|string|null 工单 ID（带前缀的字符串键；纯数字环境退化为 int）；未落单 ⇒ `null`
     */
    function zw_policy_issue_open($context, $hit) {
        global $wpdb;
        $context = (array) $context;
        $hit     = (array) $hit;

        if (!zw_policy_table_exists('policy_issue')) {
            zw_policy_engine_warn('zw_policy_issue 表缺失 ⇒ 未开工单（fail-open）');
            return null;
        }

        $id = zw_policy_issue_id($context, $hit);
        if ($id === '') {
            return null;
        }

        // 幂等①：先查同 ID（确定性主键）。
        $table   = zw_policy_table('policy_issue');
        $columns = zw_policy_table_columns('policy_issue');
        if ($columns) {
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%s LIMIT 1", $id));
            if ($existing !== null && $existing !== '') {
                return ctype_digit((string) $existing) ? (int) $existing : (string) $existing;
            }
        } else {
            zw_policy_engine_warn('zw_policy_issue 列集不可读 ⇒ 未开工单（fail-open）');
            return null;
        }

        $fields  = isset($context['fields']) && is_array($context['fields']) ? $context['fields'] : array();
        $person  = zw_policy_text($fields['person_id'] ?? '');
        if ($person === '' && isset($fields['uid'])) {
            $person = 'uid' . (int) $fields['uid'];
        }
        $clause    = zw_policy_text($hit['clause'] ?? '');
        $threshold = array_key_exists('threshold', $hit) && $hit['threshold'] !== null ? (int) $hit['threshold'] : null;
        $age       = isset($hit['age']) && is_numeric($hit['age']) ? (int) $hit['age'] : null;
        $as_of     = isset($hit['as_of']) ? zw_policy_text($hit['as_of']) : zw_policy_issue_period($context);

        $title = '任职年龄到龄复核：' . ($person !== '' ? $person : '未标注人员') . '（' . $clause . '）';
        $desc  = '按 ' . $as_of . ' 周岁 ' . ($age === null ? '未知' : $age) . ' 判定，'
               . '依据条款 ' . $clause
               . ($threshold === null ? '（无数值阈：到龄退出）' : ('（阈值 ' . $threshold . ' 周岁）'))
               . '；本轮不硬阻断、不改业务状态，转人事与合规人工复核。';

        $now = function_exists('current_time') ? (string) current_time('mysql') : date('Y-m-d H:i:s');
        $all = array(
            'id'            => $id,
            'source_type'   => 'policy_age',
            'source_id'     => $clause,
            'title'         => $title,
            'description'   => $desc,
            'risk_level'    => '高',
            'owner_dept'    => '人事',
            'owner'         => '',
            'due_date'      => '',
            'status'        => '待处理',
            'evidence'      => '',
            'review_conclusion' => '',
            'created_by'    => 'policy-engine',
            'create_time'   => $now,
            'update_time'   => $now,
        );
        $row = array_intersect_key($all, array_flip($columns));
        if (!isset($row['id']) || !isset($row['title'])) {
            zw_policy_engine_warn('zw_policy_issue 必需列缺失 ⇒ 未开工单（fail-open）');
            return null;
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('zw_policy_issue_row', $row, $context, $hit);
            if (is_array($filtered)) {
                $row = $filtered;
            }
        }

        $inserted = $wpdb->insert($table, $row);
        if ($inserted === false) {
            $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
            // 幂等②：并发下同 ID 重复插入 ⇒ 回查既有行。
            if (stripos($error, 'duplicate') !== false) {
                $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%s LIMIT 1", $id));
                if ($existing !== null && $existing !== '') {
                    return ctype_digit((string) $existing) ? (int) $existing : (string) $existing;
                }
            }
            zw_policy_engine_warn('整改工单写入失败 ⇒ 未开工单（fail-open）：' . $error);
            return null;
        }
        // 工单 ID 是带前缀的字符串键（`POLAGE-…`），**不得**整体转 int（会退化为 0）。
        return ctype_digit((string) $id) ? (int) $id : (string) $id;
    }
}

/* ═════════════════════════ canonical 判定入口 ═════════════════════════ */

if (!function_exists('zw_policy_action_to_decision')) {
    /**
     * 规则动作 ⇄ 引擎结论映射（**本轮不做硬阻断**：只有显式 `action=block` 才是 `block`）。
     *
     * @param array  $rule
     * @param string $level 年龄命中级别（`info|warn|over`）；非年龄规则传空串。
     * @return string `pass|defer|block`
     */
    function zw_policy_action_to_decision($rule, $level = '') {
        $rule = (array) $rule;
        $raw  = strtolower(zw_policy_text($rule['internal_action'] ?? '')) . ' '
              . strtolower(zw_policy_text($rule['external_action'] ?? '')) . ' '
              . strtolower(zw_policy_text($rule['action'] ?? ''));
        // 只认显式英文 `block`（机器口径）。**刻意不把中文「禁止」映射为 block**：
        // 现网 11 条规则的 external_action/internal_action 含中文措辞，若把「禁止」当硬阻断，
        // 会绕过「本轮不做硬阻断」的一票否决边界（SPEC-93 §六 第 6 条 / 需求稿 §7 不做清单）。
        if (preg_match('/(^|[^a-z])block([^a-z]|$)/', $raw)) {
            return 'block'; // 契约保留：本批规则不声明 ⇒ 运行期不会产生
        }
        if ($level === 'over' || $level === 'warn') {
            return 'defer'; // 警示级 ⇒ 暂缓 + 转人事和合规复核（R-5）
        }
        if ($level === 'info') {
            return 'defer'; // 信息级同样留痕暂缓，但不阻断
        }
        return 'pass';
    }
}

if (!function_exists('zw_policy_check')) {
    /**
     * canonical 判定入口（**唯一出处**，需求稿 R-10）。
     *
     * 调用顺序（决定「零查询零写入」是否成立）：
     *  1. 全局开关 off（含域 / 功能点收窄）⇒ `return ['decision'=>'pass','matched'=>[],'hits'=>[],'log_id'=>'']`，
     *     在线路最前端返回，**不读表、不写表**（本函数内无任何 SQL 在这条路径上）；
     *  2. 读启用规则（域 / 功能点 / 触发事件收窄）；
     *  3. 命中判定：任职年龄（派生）⇒ 规则 ⇄ 命中收敛为 `matched`，`decision = max(命中级别映射)`；
     *     规则自身声明 `action=block` 时以 `block` 为准；`warn|over` ⇒ `defer`（不硬阻断）；
     *  4. 结论落 `zw_policy_control_log`（`log_id` 回填）。
     *
     * @param array $context `module_key`/`feature_key`/`trigger_event`/`as_of`/`position`/`fields`。
     * @return array `{decision,matched,hits,log_id}`
     */
    function zw_policy_check($context) {
        $context = is_array($context) ? $context : array();
        $module  = isset($context['module_key']) ? $context['module_key'] : '';
        $feature = isset($context['feature_key']) ? $context['feature_key'] : '';

        $pass = array('decision' => 'pass', 'matched' => array(), 'hits' => array(), 'log_id' => '');

        $gate_module = ($feature !== '') ? $feature : $module;
        if (!function_exists('zw_policy_gate_enabled') || !zw_policy_gate_enabled($gate_module)) {
            return $pass; // 开关 off / 未收窄命中 ⇒ 零查询零写入
        }

        $hits = zw_policy_age_hits($context);

        $rules   = zw_policy_module_rules($module, $feature, isset($context['trigger_event']) ? $context['trigger_event'] : '');
        $matched = array();
        $level_rank = array('' => 0, 'info' => 1, 'warn' => 2, 'over' => 3);
        $top    = '';
        $block  = false;

        foreach ($rules as $rule) {
            $rule    = (array) $rule;
            $clauses = zw_policy_clause_ids(isset($rule['policy_rule_ids']) ? $rule['policy_rule_ids'] : '');
            $rule_hits = array();
            foreach ($hits as $hit) {
                if (in_array((string) $hit['clause'], $clauses, true)) {
                    $rule_hits[] = $hit;
                }
            }
            if (!$rule_hits) {
                continue; // 规则未命中 ⇒ 不计入 matched（`pass` 时 matched 必须为空）
            }
            $decision = 'pass';
            foreach ($rule_hits as $hit) {
                $decision = zw_policy_action_to_decision($rule, (string) $hit['level']);
                if ($decision === 'block') {
                    $block = true;
                }
                if ($level_rank[(string) $hit['level']] > $level_rank[$top]) {
                    $top = (string) $hit['level'];
                }
            }
            $matched[] = array(
                'rule_code'       => (string) ($rule['rule_code'] ?? ''),
                'rule_name'       => (string) ($rule['rule_name'] ?? ''),
                'clauses'         => $clauses,
                'external_action' => (string) ($rule['external_action'] ?? ''),
                'internal_action' => (string) ($rule['internal_action'] ?? ''),
                'decision'        => $decision,
            );
        }

        $decision = 'pass';
        if ($matched) {
            $decision = $block ? 'block' : 'defer';
        }

        $result = array(
            'decision' => $decision,
            'matched'  => $matched,
            'hits'     => $hits,
            'log_id'   => '',
        );
        $result['log_id'] = zw_policy_log($context, $result);
        return $result;
    }
}

/* ═════════════════════════ 派生展示（T9/T10 复用；不写库） ═════════════════════════ */

if (!function_exists('zw_policy_age_retire_state')) {
    /**
     * 到龄派生标记（`ok|approaching|over` + 条款 + 阈值 + 周岁；**不改业务状态**）。
     *
     * 口径：`over` = 命中 `over` 级；`approaching` = 命中 `info` 级（距限不满 1 年）；其余 `ok`。
     * 无周岁（未实名 / 无身份证）⇒ `ok` + `unverified` 标记，**不误判为超龄**。
     *
     * @param array  $row   人员行（读 `position` / `age`；不读生日）。
     * @param string $as_of 判定日（默认今天）。
     * @return array `{state,clause,threshold,age,remain_years,as_of,level,reason}`
     *               `remain_years` = `threshold - age`（**负值 = 已超限年数**；无数值阈 / 未校验 ⇒ `null`）。
     */
    function zw_policy_age_retire_state($row, $as_of = '') {
        $row      = (array) $row;
        $position = isset($row['position']) ? (string) $row['position'] : '';
        $as_of    = $as_of !== '' ? (string) $as_of : (function_exists('current_time') ? (string) current_time('Y-m-d') : date('Y-m-d'));

        $out = array(
            'state'        => 'ok',
            'clause'       => '',
            'threshold'    => null,
            'age'          => null,
            'remain_years' => null,
            'as_of'        => $as_of,
            'level'        => '',
            'reason'       => '',
        );

        if (!array_key_exists('age', $row) || !is_numeric($row['age']) || (int) $row['age'] <= 0) {
            $out['reason'] = 'unverified'; // 未实名 / 无身份证 ⇒ 未做年龄校验
            return $out;
        }
        $age = (int) $row['age'];

        $hits = zw_policy_age_hits(array(
            'module_key' => isset($row['module_key']) ? $row['module_key'] : '',
            'position'   => $position,
            'as_of'      => $as_of,
            'fields'     => array('age' => $age),
        ));
        if (!$hits) {
            return $out;
        }

        $severity = array('info' => 1, 'warn' => 2, 'over' => 3);

        // 取证口径：**只看有数值阈的命中**（warn / info）。无数值阈的 `CLAUSE-POL-005`（`over`）
        // 是「已达界限」的派生标记、没有本岗位阈值，若让它参与取最严重级别，会把
        // 「聘任制秘书长 66 岁」的证据条款误报成 POL-005，丢失 R-4 要求的 65 岁依据。
        $witness = null;
        foreach ($hits as $hit) {
            if ($hit['threshold'] === null) {
                continue;
            }
            if ($witness === null || $severity[$hit['level']] > $severity[$witness['level']]) {
                $witness = $hit;
            }
        }
        if ($witness === null) {
            return $out; // 只有到龄标记却没有岗位阈值证据（异常态）⇒ 不生成结论
        }

        $out['clause']       = (string) $witness['clause'];
        $out['threshold']    = $witness['threshold'];
        $out['age']          = $age;
        $out['level']        = (string) $witness['level'];
        // 距限差额（T9 判据）：负值 = 已超限年数；`0` = 正好达限；正值 = 尚余年数。
        // 用途：展示面文案「距限不满 1 年」与「已超限 N 年」由**同一派生**给出（禁止各页各自算）。
        $out['remain_years'] = (int) $witness['threshold'] - $age;
        if ($witness['level'] === 'warn' || $witness['level'] === 'over') {
            $out['state']  = 'over';
            $out['reason'] = ($witness['level'] === 'over')
                ? '到龄：已达任职年龄界限，应当及时退出（只派生标记，不改业务状态）'
                : '超龄：已达条款阈值';
        } elseif ($witness['level'] === 'info') {
            $out['state']  = 'approaching';
            $out['reason'] = '距最高任职年龄不满 1 年';
        }
        return $out;
    }
}

if (!function_exists('zw_policy_age_retire_marker')) {
    /**
     * 到龄派生标记的**展示契约**（T10 唯一出处；五个展示面共用，禁止各页各自拼文案）。
     *
     * 口径（需求稿 §5.4 / R-9）：
     *  - `over`        ⇒ `tone=danger`，文案「**已达任职年龄界限**」（警示级）；距限差额 < 0 时随附「已超限 N 年」；
     *  - `approaching` ⇒ `tone=wait`，文案「**距限不满 1 年**」（信息级）；
     *  - `ok` + `unverified` ⇒ `tone=off`，文案「**未实名·未做年龄校验**」（**不误判为超龄**）；
     *  - `ok`          ⇒ 不产出标记（`visible=false`），页面据此**不渲染徽章**。
     *
     * 徽章类名映射（UI 层唯一约定，**禁止各页自定**）：`danger ⇒ zw-adm-badge err` / `wait ⇒ wait` /
     * `off ⇒ off`（语义色取自 `inc/admin/admin-ui-tokens.php` 的 `.zw-adm-badge.*` 规范徽章）。
     *
     * 个人信息最小必要（R-9）：本函数只输出**周岁 / 实名态 / 差额**，**绝不输出出生日期、证件号**。
     *
     * 入参兼容（**展示面免各自派生岗位**）：
     *  - 已带 `position`（引擎 `context.position`）⇒ 直接判定；
     *  - 或带 `role` / `role_other`（业务负责人行）⇒ 委托 `zw_policy_age_retire_scan_leader()` 派生岗位与周岁
     *    （唯一出处，禁止各展示面自行拼岗位词）。两者都没有 ⇒ 判定为「未标注岗位」`ok`（不触发）。
     *
     * @param array  $row   人员行（引擎 context 形状或业务负责人行形状）。
     * @param string $as_of 判定日（默认今天）。
     * @return array `{visible,tone,label,state,clause,threshold,age,remain_years,reason}`
     */
    function zw_policy_age_retire_marker($row, $as_of = '') {
        if (!array_key_exists('position', (array) $row) && function_exists('zw_policy_age_retire_scan_leader')) {
            $state = zw_policy_age_retire_scan_leader($row, $as_of);
        } else {
            $state = zw_policy_age_retire_state($row, $as_of);
        }
        $marker = array(
            'visible'      => false,
            'tone'         => '',
            'label'        => '',
            'state'        => (string) ($state['state'] ?? 'ok'),
            'clause'       => (string) ($state['clause'] ?? ''),
            'threshold'    => $state['threshold'],
            'age'          => $state['age'],
            'remain_years' => $state['remain_years'],
            'reason'       => (string) ($state['reason'] ?? ''),
        );

        if ($marker['state'] === 'over') {
            $marker['visible'] = true;
            $marker['tone']    = 'danger';
            $marker['label']   = '已达任职年龄界限';
            if ($marker['remain_years'] !== null && (int) $marker['remain_years'] < 0) {
                $marker['label'] .= '（已超限 ' . abs((int) $marker['remain_years']) . ' 年）';
            }
            return $marker;
        }
        if ($marker['state'] === 'approaching') {
            $marker['visible'] = true;
            $marker['tone']    = 'wait';
            $marker['label']   = '距限不满 1 年';
            return $marker;
        }
        if ($marker['reason'] === 'unverified') {
            $marker['visible'] = true;
            $marker['tone']    = 'off';
            $marker['label']   = '未实名·未做年龄校验';
        }
        return $marker;
    }
}
