<?php
/**
 * 政策法规应用 · 政策域键 / 功能点键 / 阈值注册表（SPEC-93 · TASK-1598 · B0 / T2）。
 *
 * 为什么要有这个文件（需求稿 §1.3 G2 + R-2）：
 *  - 条款库里「任职年龄不超过 70 周岁」等 7 条红线条款确实存在（`risk_level=高` / `redline_flag=是`
 *    / `status=已确认`），但运行时**没有任何执行器**，且阈值 70/65/69 此前**没有唯一出处**；
 *  - 若阈值散落在模板 / JS / 各业务模块里，条款改了也不会有人同步（R-2 的静默失效）。
 *
 * 本文件是以下四件事的**唯一出处**（细则见 `docs/中传网政策法规应用规范-20261014.md`）：
 *  1. `zw_policy_module_keys()`        —— 政策域键表（方案 A，10 键）；
 *  2. `zw_policy_feature_keys()`       —— 功能点键表（`域.功能`，首期 8 键）；
 *  3. `zw_policy_age_rules()`          —— 阈值注册表（阈值 ⇄ 条款 `original_text` 数字由 CI 断言强制同步）；
 *  4. `zw_policy_gate_enabled()`       —— 三层灰度开关判定（默认 **off**）；
 *  5. `zw_policy_realname_required_since()` / `zw_policy_realname_notice_text()` —— 上线日常量与提示文案（D2）；
 *  6. `zw_policy_recommended_modules()` / `zw_policy_recommended_modules_for_rule()`
 *     —— 11 条执行规则的**推荐适用模块**（TASK-1655 · B「一键推荐」唯一出处；**只读、不参与判定**）。
 *
 * **零副作用**：本文件只返回纯数组 / 纯函数，**不注册 hook、不写 option、不查库**；
 * 唯一的运行时读取是 `zw_policy_gate_enabled()` 内的 `get_option()`（只读）。
 * 因此删文件 + 撤 `functions.php` 的 require 即完全回滚（零影响）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_policy_module_keys')) {
    /**
     * 政策域键表（SPEC-93 §2.1 · 方案 A，用户 2026-10-14 拍板）。
     *
     * 口径：**与权限模块键 / 导航域键解耦**；前 7 个键在重叠处刻意沿用同名以降低认知成本，
     * 但语义独立（只回答「政策规则作用于哪一类业务动作」）。
     * `office` 只表示办公协同中**未被 `doc`/`receipt` 覆盖**的部分（组织架构 / 网盘 / 审批 / 外事）。
     *
     * @return array<string,string> 域键 => 中文名
     */
    function zw_policy_module_keys() {
        return array(
            'doc'      => '发文管理',
            'member'   => '会员管理',
            'branch'   => '分支机构',
            'content'  => '内容管理',
            'legal'    => '法务管理',
            'data'     => '数据管理',
            'system'   => '系统管理',
            'activity' => '活动报名',
            'receipt'  => '收文管理',
            'office'   => '办公协同',
        );
    }
}

if (!function_exists('zw_policy_feature_keys')) {
    /**
     * 功能点键表（SPEC-93 §三 · 首期 8 键）。
     *
     * 命名规则：`域.功能`，正则 `^[a-z][a-z0-9]*\.[a-z][a-z0-9_]*$`，**段数恒为 2**，
     * 域段必须 ∈ `zw_policy_module_keys()`。键**只增不改**（改名即历史规则行静默失配）。
     *
     * ⚠️ 历史别名：需求稿示例中的 `org.people` **不登记为合法键**；组织架构人员属 `office` 政策域，
     * 合法键为 **`office.people`**（裁决留痕见 `specs/changes/CHG-293.md` §五）。
     *
     * @return array<string,string> 功能点键 => 中文名
     */
    function zw_policy_feature_keys() {
        return array(
            'branch.leaders' => '分支机构负责人管理',
            'branch.roster'  => '分支机构花名册',
            'branch.detail'  => '分支机构详情页',
            'office.people'  => '组织架构人员',
            'doc.appoint'    => '发文任命人员',
            'doc.seal'       => '用印管理',
            'member.pay'     => '会员缴费',
            'legal.issue'    => '法务问题处置',
        );
    }
}

if (!function_exists('zw_policy_recommended_modules')) {
    /**
     * 11 条执行规则的推荐适用模块（SPEC-93 §十五 · TASK-1655 · B「一键推荐」**唯一出处**）。
     *
     * **只读、不参与判定**：`zw_policy_gate_enabled()` / `zw_policy_rule_module_match()` 均**不读**本表；
     * 本表只回答「这条规则**建议**挂到哪些域 / 功能点上」，供后台页【应用推荐配置】按钮**填表单**用
     * （**只填表单、不提交**，用户可再微调）。
     *
     * 键 = **生产实测 `rule_code`**（证据：`wp --allow-root db query "SELECT rule_code,
     * business_module FROM wptcpc_zw_policy_rule ORDER BY rule_code"` ⇒ 11 行，2026-10-15 实测）；
     * 值为空数组 = **已知但无推荐**（5 条占位规则 `待业务场景映射` ⇒ UI 提示「建议先指派到具体模块」），
     * 与「未知规则（返回 `null`，不臆造）」严格区分。
     *
     * 推荐依据（中文，逐条）：
     *  - **负责人任职**（`…287-4`）：规则触发面 = 任命书 + 分支机构负责人管理 + 组织架构人员，
     *    取三个功能点键（= 需求稿 §4 的 R-4 五个面中的三个运行时面；`branch.roster` / `branch.detail`
     *    是同一批负责人数据的展示面，由 `branch.leaders` 域内规则统一覆盖，故不重复挂键）；
     *  - **分支机构设立、变更、撤销**（`…278-3`）：作用于**整个分支机构域**（设立 / 变更 / 撤销三动作
     *    横跨负责人、花名册、详情页）⇒ 按「域级配置 = 整个域」挂 `branch`（§5.3 明文合法）；
     *  - **用印管理**（`…319-8`）：用印动作唯一对应功能点「用印管理」⇒ `doc.seal`；
     *  - **收费/会费/捐赠/财务**（`…295-5`）：资金类动作唯一对应功能点「会员缴费」⇒ `member.pay`；
     *  - **活动立项、宣传发布、外事申请**（`…268-2`）：三类动作都属「内容管理」政策域
     *    （立项材料 / 宣传稿 / 外事申请文的产出与发布）⇒ 域级 `content`；
     *  - **监督整改**（`…303-6`）：整改工单与法务问题处置同域 ⇒ 功能点「法务问题处置」`legal.issue`；
     *  - **`待业务场景映射` ×5**（`…254-1` / `…311-7` / `…327-9` / `…335-10` / `…342-11`）：
     *    规则名与业务模块均为占位串，**真实业务场景未核实** ⇒ 一律**空数组**（不臆造），
     *    UI 明确提示「建议先指派到具体模块」。
     *
     * @return array<string,array<int,string>> `rule_code` => 推荐键列表（合法键取自本文件两张键表）
     */
    function zw_policy_recommended_modules() {
        return array(
            // 负责人任职（任命书 + 分支机构负责人管理 + 组织架构人员）
            'AUTO-CONF-1789888076287-4' => array('branch.leaders', 'doc.appoint', 'office.people'),
            // 分支机构设立、变更、撤销（分支域整体）
            'AUTO-CONF-1789888076278-3' => array('branch'),
            // 用印管理
            'AUTO-CONF-1789888076319-8' => array('doc.seal'),
            // 收费/会费/捐赠/财务
            'AUTO-CONF-1789888076295-5' => array('member.pay'),
            // 活动立项、宣传发布、外事申请（内容管理域）
            'AUTO-CONF-1789888076268-2' => array('content'),
            // 监督整改
            'AUTO-CONF-1789888076303-6' => array('legal.issue'),
            // 以下 5 条为占位规则（业务场景未核实）⇒ 无推荐，禁止臆造
            'AUTO-CONF-1789888076254-1' => array(),
            'AUTO-CONF-1789888076311-7' => array(),
            'AUTO-CONF-1789888076327-9' => array(),
            'AUTO-CONF-1789888076335-10' => array(),
            'AUTO-CONF-1789888076342-11' => array(),
        );
    }
}

if (!function_exists('zw_policy_recommended_scenario_alias')) {
    /**
     * 业务场景中文名 → `rule_code` 别名表（`zw_policy_recommended_modules()` 的**回退入口**）。
     *
     * 为什么要有：`rule_code` 由导入器按毫秒时间戳生成（`AUTO-CONF-<ms>-<seq>`），
     * **换环境 / 重新导入即变**。因此除 `rule_code` 直接命中外，还需按业务场景中文名回退，
     * 才能让「一键推荐」在 dev / 测试环境同样可用。**别名只指向同一张推荐表**（不复制第二套键集，
     * 由 `tests/test-policy-applicability.php` 断言两张表逐条一致）。
     *
     * 值为空串 = 该场景**已知为占位**（`待业务场景映射`）⇒ 无推荐（不臆造）。
     *
     * @return array<string,string> 业务场景中文名 => `rule_code`（空串 = 已知占位无推荐）
     */
    function zw_policy_recommended_scenario_alias() {
        return array(
            '负责人任职'                 => 'AUTO-CONF-1789888076287-4',
            '分支机构设立、变更、撤销'   => 'AUTO-CONF-1789888076278-3',
            '用印管理'                   => 'AUTO-CONF-1789888076319-8',
            '收费/会费/捐赠/财务'        => 'AUTO-CONF-1789888076295-5',
            '活动立项、宣传发布、外事申请' => 'AUTO-CONF-1789888076268-2',
            '监督整改'                   => 'AUTO-CONF-1789888076303-6',
            // 5 条占位规则共用同一场景串；空串 = 无推荐（UI 提示「建议先指派到具体模块」）
            '待业务场景映射'             => '',
        );
    }
}

if (!function_exists('zw_policy_recommended_scenario_labels')) {
    /**
     * 从一条规则行推导**可能的业务场景中文名**（用于回退查别名表；纯函数）。
     *
     * 候选顺序：① 原始 `business_module`（迁移前的中文旧值，如 `负责人任职`）；
     * ② `rule_name` 原样；③ `rule_name` 去掉后缀「自动映射规则」（生产 11 条均带该后缀）。
     *
     * @param array|object $rule 规则行（含 `rule_name` / `business_module`）。
     * @return array<int,string>
     */
    function zw_policy_recommended_scenario_labels($rule) {
        $rule = (array) $rule;
        $out  = array();
        $raw  = trim((string) ($rule['business_module'] ?? ''));
        if ($raw !== '') { $out[] = $raw; }
        $name = trim((string) ($rule['rule_name'] ?? ''));
        if ($name !== '') {
            $out[]  = $name;
            $suffix = '自动映射规则';
            if (substr($name, -strlen($suffix)) === $suffix) {
                $trimmed = trim(substr($name, 0, -strlen($suffix)));
                if ($trimmed !== '') { $out[] = $trimmed; }
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('zw_policy_recommended_modules_for_rule')) {
    /**
     * 取某条规则的**推荐键列表**（唯一入口；后台页只消费本函数，禁止自己查表）。
     *
     * 判定顺序：① `rule_code` 精确命中（生产实测 11 条）；② 业务场景中文名回退（换环境后仍可用）。
     *
     * @param array|object $rule 规则行（含 `rule_code` / `rule_name` / `business_module`）。
     * @return array<int,string>|null 推荐键列表（**可能是空数组** = 已知但无推荐）；
     *                               `null` = **未核实该规则**（不臆造，UI 不得显示「应用推荐配置」）。
     */
    function zw_policy_recommended_modules_for_rule($rule) {
        $rule = (array) $rule;
        $map  = zw_policy_recommended_modules();

        // ① rule_code 精确命中（大小写不敏感，容错导入器的大小写差异）。
        $code = strtoupper(trim((string) ($rule['rule_code'] ?? '')));
        if ($code !== '') {
            foreach ($map as $rule_code => $keys) {
                if (strtoupper((string) $rule_code) === $code) {
                    return array_values((array) $keys);
                }
            }
        }

        // ② 业务场景中文名回退（别名表只指向同一张推荐表）。
        $alias = zw_policy_recommended_scenario_alias();
        foreach (zw_policy_recommended_scenario_labels($rule) as $label) {
            if (!array_key_exists($label, $alias)) { continue; }
            $target = (string) $alias[$label];
            if ($target === '') { return array(); }             // 已知占位 ⇒ 无推荐（不臆造）
            if (isset($map[$target])) { return array_values((array) $map[$target]); }
        }

        return null;                                            // 未核实 ⇒ 不臆造
    }
}

if (!function_exists('zw_policy_age_rules')) {
    /**
     * 任职年龄阈值注册表（SPEC-93 §4 · **D1 = 方案 (a)** 已拍板）。
     *
     * 阈值数字放在**代码注册表**而非 DB 列；「注册表 ⇄ 条款原文数字」由
     * `tests/test-policy-module-map.php` 的断言 A–E 强制同步（条款改了 ⇒ 断言红 ⇒ 强制改这里）。
     *
     * 条目字段：
     *  - `clause`（必填）：条款 ID；
     *  - `threshold`：周岁阈值（int）或 `null`（不设数值阈）；
     *  - `threshold_clause`：数值交叉校验来源条款（`threshold` 非 null 时必填）；
     *  - `threshold_mode`：`literal`（原文含「N 周岁」）| `minus_one`（同阈条款 − 1）| `none`（无数值阈）；
     *  - `level`：`info`（信息级，距限不满 1 年）| `warn`（警示级）| `over`（已达界限，R-13 到龄）；
     *  - `scopes`：岗位 / 场景（需求稿 §4 场景列）；
     *  - `feature`：首选功能点键（必须 === `features[0]`）；
     *  - `features`：适用功能点（R-4 的 5 个面：任命书 + 负责人管理 + 花名册 + 详情页 + 组织架构人员）；
     *  - `note`：口径说明（**不复制条款全文**）。
     *
     * 首期 7 条与需求稿 §4 映射表**逐条一致**（见 SPEC-93 §4.3）。
     *
     * @return array<int,array<string,mixed>> 条目列表
     */
    function zw_policy_age_rules() {
        $faces = array('doc.appoint', 'branch.leaders', 'branch.roster', 'branch.detail', 'office.people');
        return array(
            array(
                'clause'           => 'CLAUSE-POL-006',
                'threshold'        => 70,
                'threshold_clause' => 'CLAUSE-POL-006',
                'threshold_mode'   => 'literal',
                'level'            => 'warn',
                'scopes'           => array('本会负责人（会长/副会长）'),
                'feature'          => 'doc.appoint',
                'features'         => $faces,
                'note'             => '全国性行业协会商会负责人最高任职年龄不超过 70 周岁',
            ),
            array(
                'clause'           => 'CLAUSE-POL-008',
                'threshold'        => 65,
                'threshold_clause' => 'CLAUSE-POL-008',
                'threshold_mode'   => 'literal',
                'level'            => 'warn',
                'scopes'           => array('聘任制秘书长'),
                'feature'          => 'doc.appoint',
                'features'         => $faces,
                'note'             => '聘任制秘书长最高任职年龄不超过 65 周岁（且不得任法定代表人）',
            ),
            array(
                'clause'           => 'CLAUSE-POL-093',
                'threshold'        => 70,
                'threshold_clause' => 'CLAUSE-POL-093',
                'threshold_mode'   => 'literal',
                'level'            => 'warn',
                'scopes'           => array('监事长/副监事长'),
                'feature'          => 'doc.appoint',
                'features'         => $faces,
                'note'             => '监事长和副监事长最高任职年龄不超过 70 周岁（连任 ≤2 届）',
            ),
            array(
                'clause'           => 'CLAUSE-POL-098',
                'threshold'        => 70,
                'threshold_clause' => 'CLAUSE-POL-098',
                'threshold_mode'   => 'literal',
                'level'            => 'warn',
                'scopes'           => array('分支（代表）机构负责人'),
                'feature'          => 'doc.appoint',
                'features'         => $faces,
                'note'             => '分支（代表）机构负责人最高任职年龄不得超过 70 周岁（连任 ≤2 届）',
            ),
            array(
                'clause'           => 'CLAUSE-POL-011',
                'threshold'        => 70,
                'threshold_clause' => 'CLAUSE-POL-011',
                'threshold_mode'   => 'literal',
                'level'            => 'warn',
                'scopes'           => array('退（离）休领导干部兼职'),
                'feature'          => 'doc.appoint',
                'features'         => $faces,
                'note'             => '退（离）休领导干部兼职的任职年龄界限为 70 周岁（兼职 ≤1 个、≤两届）',
            ),
            array(
                'clause'           => 'CLAUSE-POL-007',
                'threshold'        => 69,
                'threshold_clause' => 'CLAUSE-POL-006',
                'threshold_mode'   => 'minus_one',
                'level'            => 'info',
                'scopes'           => array('距最高任职年龄不满 1 年（拟提名负责人）'),
                'feature'          => 'doc.appoint',
                'features'         => $faces,
                'note'             => '一般不提名距最高任职年龄不满 1 年的人员为负责人（阈值＝同阈条款 − 1，来源 CLAUSE-POL-006）',
            ),
            array(
                'clause'           => 'CLAUSE-POL-005',
                'threshold'        => null,
                'threshold_clause' => '',
                'threshold_mode'   => 'none',
                'level'            => 'over',
                'scopes'           => array('已达任职年龄界限（在任）'),
                'feature'          => 'doc.appoint',
                'features'         => $faces,
                'note'             => '达到章程规定任职年龄界限的应当及时退出（无数值阈，判定依赖被约束岗位的阈值条款）',
            ),
        );
    }
}

if (!function_exists('zw_policy_gate_enabled')) {
    /**
     * 三层灰度开关判定（SPEC-93 §九；默认 **off**）。
     *
     * 判定顺序：
     *  1. 全局 `zw_policy_gate_enabled` 未开 ⇒ `false`（调用方据此**零查询零写入**）；
     *  2. `$module` 为空 ⇒ `true`（全局已开，无域 / 功能点收窄）；
     *  3. 域白名单 `zw_policy_gate_modules` 非空且（域键 ∉ 白名单 且 完整键 ∉ 白名单）⇒ `false`；
     *  4. 功能点白名单 `zw_policy_gate_features` 非空且（完整键 ∉ 白名单 且 域键 ∉ 白名单）⇒ `false`；
     *     **调用方传裸域键时**（功能点白名单非空）⇒ 保守 `false`（无法确认其属于哪个已启用功能点）；
     *  5. 其余 ⇒ `true`。
     *
     * 白名单 option 支持 **数组** / **JSON 数组字符串** / **逗号或空白分隔字符串** 三种形态。
     *
     * @param string|null $module 域键（如 `branch`）或功能点键（如 `branch.leaders`）；null = 只问全局。
     * @return bool
     */
    function zw_policy_gate_enabled($module = null) {
        $global = function_exists('get_option') ? get_option('zw_policy_gate_enabled', '0') : '0';
        if (!zw_policy_option_is_on($global)) {
            return false;
        }

        $token = is_string($module) ? strtolower(trim($module)) : '';
        if ($token === '') {
            return true;
        }

        $dot    = strpos($token, '.');
        $key    = $dot === false ? '' : $token;              // 完整功能点键（裸域键时为空）
        $domain = $dot === false ? $token : substr($token, 0, $dot);

        $domains  = zw_policy_option_tokens(function_exists('get_option') ? get_option('zw_policy_gate_modules', array()) : array());
        $features = zw_policy_option_tokens(function_exists('get_option') ? get_option('zw_policy_gate_features', array()) : array());

        // ③ 域白名单：非空 ⇒ 必须命中域键或完整键。
        if ($domains && !in_array($domain, $domains, true) && ($key === '' || !in_array($key, $domains, true))) {
            return false;
        }

        // ④ 功能点白名单：非空 ⇒ 裸域键保守拒绝；功能点键必须命中完整键或域键。
        if ($features) {
            if ($key === '') {
                return false;
            }
            if (!in_array($key, $features, true) && !in_array($domain, $features, true)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('zw_policy_realname_required_since')) {
    /**
     * 实名强制上线日（SPEC-93 §八 · **D2 已拍板**）。
     *
     * 用途：**只作文案用，不作判据**。R-3 的「存量 vs 上线后」按**写入动作时点**判定（需求稿 §5.1），
     * 与基准日无关。
     *
     * @return string `Y-m-d`（本地口径，不带时区语义）
     */
    function zw_policy_realname_required_since() {
        return '2026-10-01';
    }
}

if (!function_exists('zw_policy_realname_notice_text')) {
    /**
     * 实名门禁后台提示文案（SPEC-93 §八 · **由常量派生，禁止在模板 / JS / 文案表写死**）。
     *
     * 输出固定为「自 2026年10月1日 起新增任命须核验实名」；
     * 中文日期由 `zw_policy_realname_required_since()` 派生（去掉月/日的前导零），
     * 因此改上线日**只需改一处**。
     *
     * @return string
     */
    function zw_policy_realname_notice_text() {
        $cn = zw_policy_cn_date(zw_policy_realname_required_since());
        if ($cn === '') {
            // fail-safe：常量被改成非法日期时不得产出「自  起…」这类残句。
            return '自即日起新增任命须核验实名';
        }
        return '自 ' . $cn . ' 起新增任命须核验实名';
    }
}

if (!function_exists('zw_policy_option_is_on')) {
    /**
     * option 布尔判定（内部工具，SPEC-93 §九）。
     *
     * 只把明确真值视为开：`true` / `1` / `'1'` / `'on'` / `'yes'` / `'true'` / `'enabled'`；
     * 其余（含 `'0'` / `0` / `''` / `null` / `'off'`）一律为关 —— 默认 off 是硬口径。
     *
     * @param mixed $value
     * @return bool
     */
    function zw_policy_option_is_on($value) {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (!is_string($value)) {
            return false;
        }
        return in_array(strtolower(trim($value)), array('1', 'on', 'yes', 'true', 'enabled'), true);
    }
}

if (!function_exists('zw_policy_option_tokens')) {
    /**
     * 把白名单 option 归一为小写 token 列表（内部工具，SPEC-93 §九）。
     *
     * 支持形态：`array` / JSON 数组字符串 / 逗号或空白分隔字符串；空值 ⇒ 空数组（= 不收窄）。
     *
     * @param mixed $raw
     * @return array<int,string>
     */
    function zw_policy_option_tokens($raw) {
        if (is_array($raw)) {
            $list = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode(trim($raw), true);
            $list    = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', trim($raw));
        } else {
            $list = array();
        }

        $out = array();
        foreach ((array) $list as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $token = strtolower(trim((string) $item));
            if ($token === '') {
                continue;
            }
            $out[$token] = true;
        }
        return array_keys($out);
    }
}

if (!function_exists('zw_policy_cn_date')) {
    /**
     * `Y-m-d` → 中文日期（内部工具：`2026-10-01` → `2026年10月1日`，月/日去前导零）。
     *
     * @param string $ymd
     * @return string 非法输入返回空串（调用方负责兜底）
     */
    function zw_policy_cn_date($ymd) {
        if (!is_string($ymd) || !preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', trim($ymd), $m)) {
            return '';
        }
        return $m[1] . '年' . (int) $m[2] . '月' . (int) $m[3] . '日';
    }
}
