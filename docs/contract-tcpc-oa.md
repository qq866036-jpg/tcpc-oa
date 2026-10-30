# tcpc-oa ↔ 主题 契约（唯一出处 · 骨架期指针）

> 主题侧同名文件（`/srv/git/worktrees/dev-tcpc/main/docs/contract-tcpc-oa.md` 若存在）只留指针，正文以**本文件**为准。

## 现状（2026-10-28 · 骨架期）

契约的**实现侧已在主题内落地**（`inc/oa/contract-legal.php`，TASK-2320 增量三~六），本仓尚未迁入业务代码，
因此本文件当前只登记**契约面**与版本握手；待 P2/P3 把 provider 落进本仓后，本文件补「插件实现清单」。

## 契约面

| 编号 | 符号 | 方向 | 缺位语义 |
| --- | --- | --- | --- |
| P-1 | `zw_oa_legal_clause_snapshot()` | 主题 ← 插件 | `available=false` + `reason`（`table_missing` / `accessor_missing` / `engine_missing`） |
| P-2 | `zw_oa_legal_pinned_clause_ids()` | 主题 ← 插件 | 同上 |
| P-3 | `zw_oa_branch_audit_records($branch_id=0,$limit=50)` | 插件 ← 主题 | `available=false` + `reason` |
| W-1 | `zw_oa_branch_review_decide($branch_id,$decision,$note='',$reviewer_id=0)` | 插件 → 主题 | `WP_Error('zw_oa_contract_unavailable')` |
| — | `zw_oa_api_version()` / `zw_oa_api_supports($symbol)` | 双向 | 版本握手（当前 `1`） |

## P-4 · 政策读侧缝（AI法务部的「控制面 ↔ 执行面」接线 · OA-2448）

**方向：插件 → 主题（只读，单向）**。AI法务部的**判定与执行**留在主题读侧（Q-A2 拍板；OA-2438 事故后
插件**不得**装载这 3 个文件，否则生产 `Cannot redeclare`）；插件只提供**管理与配置面**（后台页、4 个开关 option、
条款/规则维护）。因此插件的控制面必须把下列符号当作**主题提供的只读依赖**：

| 类别 | 符号 | 缺位语义（插件侧） |
| --- | --- | --- |
| 开关判定 | `zw_policy_gate_enabled($module=null)` | 视为 `false`（未开） |
| option 解析 | `zw_policy_option_is_on($v)` / `zw_policy_option_tokens($v)` | 视为 `false` / `array()`（只读面板照常渲染，**不 fatal**） |
| 键注册表 | `zw_policy_module_keys()` / `zw_policy_feature_keys()` | `array()`（配置页 fail-closed 只读，禁止写入来源不明的键） |
| 判定引擎 | `zw_policy_check($context)` / `zw_policy_age_hits($context)` | 挂点 `pass`（**静默不判**：迁移读侧前必须先加在场断言） |
| 年龄派生 | `zw_policy_age_retire_state()` / `zw_policy_age_retire_marker()` / `zw_policy_age_retire_scan_leader()` | 不渲染徽标 / 不派生（只读展示随主题缺席而消失） |
| 契约 A | `zw_oa_policy_check()` / `zw_oa_policy_gate_enabled()` / `zw_oa_policy_api_version()` | 契约壳缺失 ⇒ 主题 `function_exists` 兜底；版本不匹配按破坏性变更处理 |

**版本握手**：`zw_oa_policy_api_version()` 当前 `1`；改符号清单/签名 = 破坏性变更，须双仓同批 + 升主版本。
**插件装载面纪律**：`policy-module-map.php` / `policy-platform-cron.php` / `policy-rule-engine.php` 是主题读侧，
插件**只允许保留与主题逐字节一致的副本用于 parity，不得 require**（判据 `scripts/check-kernel-symbol-collisions.sh`）。

**已知缺口（未在本单修，需独立单拍板）**：插件管理面的「适用模块保存」依赖
`scripts/migrate-policy-rule-modules.php` 的 `zw_policy_rule_modules_*` 库，而该文件只在**主题仓**
（插件内 `dirname(__DIR__,2)/scripts/` 解析为空）⇒ 插件装载时该页 fail-closed 为只读。
是否把该库迁入插件（或登记为契约 P-5）需单独决策；当前行为是**显式只读**，不是静默写入。

## 插件侧（待实现，S2）

主题契约门已支持**插件 provider 优先**：

- `zw_oa_legal_plugin_clause_snapshot()` → `array{version,captured_at,clauses[]}`（已确认条款行）
- `zw_oa_legal_plugin_pinned_clause_ids()` → `string[]`

两者存在时主题自动优先使用（返回值 `source=plugin`），否则回退主题旧实现（`source=theme`）。
**签名变更＝破坏性变更**，须双仓同批 + 升主版本；主题侧 `docs/oa-frozen-surface.json` 已把 4 个契约 API 的
签名纳入冻结面（改一个字符即判红）。

## P-5 · 后台导航位置号段（主题 → 插件发号段 / 插件 → 主题取号 · OA-2451 / OA-2454）

**口径（用户 2026-10-30 拍板，决议正文在主题仓 `docs/architecture/`）**：后台左侧导航的**存在权与位置权在插件**
（关闭插件 ⇒ 零入口），主题只发**场地**——分组标题、号段、仪表盘容器。

| 项 | 值 |
| --- | --- |
| 办公协同号段 | **`58.6` – `58.9`**（须避开 WP 核心键：`59` 分隔线、`60` 外观、`65` 插件…；不得越界到 `58.5` 小程序 / `61` 开发协作） |
| 显示顺序（用户拍板） | 收文管理 `58.6` → 发文管理 `58.7` → AI秘书处 `58.75` → AI法务部 `58.8` → 网盘文件 `58.85` → 组织架构 `58.9` |
| 号段内取号方 | 插件（`add_menu_page(..., $position)` 的数值只允许插件写；主题侧注册表只登记 `order` 序号与 `domain`） |
| 主题不得做 | 为 OA 模块写 `$position`、维护 OA 模块的占位菜单项（`建设中` 除外，必须无链接）、按 slug 写死分组锚点顺序 |
| 插件缺席语义 | 号段整段空置：零菜单项、零占位、分组标题不渲染、仪表盘卡片消失（fail-closed，不 fatal） |

**号段选址纪律（OA-2454 事故固化）**：号段必须落在 **WP 核心未占用的键**之间。原号段 `59–60.9` 与核心
`$menu[59]`（分隔线）和 `$menu[60]`（外观）冲突 —— 核心先占键，插件项被挤到 `60.000001`，结果「外观」
被夹进「办公协同」分组内（用户 2026-10-30 现场）。改号段前必须核对 `wp-admin/menu.php` 的 `$menu[...]` 全集。

**破坏性变更**：改号段边界或改「谁写 position」＝契约破坏性变更，须双仓同批 + 升主版本 + 更新本节。

## 只读对账

主题侧 `scripts/oa-contract-shadow-check.php`（`wp eval-file`，只读）比对「契约 vs 旧实现」，
输出 `OA_CONTRACT_SHADOW=ok|diff`，缺任一侧 rc=2 fail-closed。上线前与每次发版前必须为 `ok`。
