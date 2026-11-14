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

## P-6 · 政策正文内容规则（插件侧唯一实现 · OA-2498 / TASK-2814 S1）

| 项 | 值 |
| --- | --- |
| 唯一实现 | 本仓 `includes/policy/legal-content-rules.php`（纯 PHP，零 WP 依赖） |
| 规则版本 | `tcpc_oa_legal_content_rules_version()`；改规则必须 +1 |
| 覆盖文档 | `SRC-PRIVACY-POLICY`（必备章节 / 产品文档禁用词 / 22100 字上限）、`SRC-USER-AGREEMENT`（必备片段 / 禁用词 / 30000 字护栏）；未登记文档（如 `SRC-CHARTER`）**跳过**，不误伤 |
| 触发点 | 文档中心「新建版本」与「确认生效」两处；不通过 ⇒ 不建版本/不生效，回执 `content_invalid` 并**逐条列出原因**（5 分钟 transient，不落库） |
| 主题 CLI | `scripts/privacy-policy-content-check.php` 在 S1 完成后改**薄包装**（优先加载本文件）；本文件是唯一规则源，禁止第二套 |
| 边界 | 不写库、不改生效版本、不改签署/拦截；`/bhzc` 仍永不纳入（铁律 19） |

**破坏性变更**：改规则集（必备章节/上限/禁用词）必须 bump `rules_version` 并补回归（`tests/test-oa-legal-content-rules.php`）；
改回执键名须双仓同批（主题 `workbench-finish` 的 DB 证据口径不依赖本键，但运营文案依赖）。

## P-7 · OA API 互通（manifest + 版本握手 + 降级 · OA-2499 / TASK-2818）

| 项 | 值 |
| --- | --- |
| 唯一清单 | 本仓 `includes/api/oa-api.php` 的 `tcpc_oa_api_manifest()`（符号 / provider / version / php / degrade / consumers） |
| 版本握手 | `tcpc_oa_api_version()` + `tcpc_oa_api_supports($key)`；改签名/删符号/改 degrade ⇒ 版本 +1（双仓同批） |
| 调用入口 | `tcpc_oa_api_call($key,$args)` ⇒ `{ok,code:ok|unknown|unavailable,degrade,data}`，**不抛错** |
| 可用性 | `tcpc_oa_api_available($key)`（PHP 符号是否已声明）；清单登记 ≠ 运行时可用 |
| 降级语义 | `empty`（列表/文本 → 空）、`fail_closed`（合规面 → 拒绝/拦截）、`deny`（存储 → 无地址/拒绝） |
| REST | `GET /wp-json/tcpc-oa/v1/manifest`、`/health`、`/legal/{doc}`、`/doc/inbox`、`/files/{id}`（全部只读 + `read` 能力位；**无写路由**）。`legal` 只回公开文档（`bhzc` 永不返回，铁律 19）；`doc/inbox` 只回当前用户（管理员按既有口径）；`files` 只回白名单元数据（无 path/url），非本人且非管理员 ⇒ 403 |
| 主题代理 | `inc/oa/contract-api.php` 的 `zw_oa_api_manifest/version/supports/degrade/call`：插件在 ⇒ 原样投影；插件缺 ⇒ `source=off` 零值 + 按降级语义 |

**消费纪律**：先 `supports` 再 `available` 再 `call`；`ok=false` 必须按 `degrade` 处理，禁止把缺位当成功。
新增符号只增不改；改版本必须同时更新主题代理与 `docs/ops/oa-api-registry.yml`（后续单）。

## P-8 · 组织架构数据面（主题契约 · OA-2500 / TASK-2820）

| 项 | 值 |
| --- | --- |
| 唯一写口 | 主题 `inc/oa/contract-org-data.php`；插件字典经 `zw_oa_org_data_dict_get/save`，人员 meta 经 `zw_oa_org_data_person_meta_set` |
| 白名单 | `departments` / `duties` / `roles` / `data_model` → `zw_org_departments` / `zw_org_duties` / `zw_org_roles` / `zw_org_data_model_version`；未知键 ⇒ `null`/`false`（fail-closed） |
| 版本握手 | `zw_oa_org_data_version()`（当前 1）；插件侧 `>=1` 才走契约 |
| 人员 meta 写口（O2b） | 主题 `zw_oa_org_data_person_meta_set($person_id,$key,$value)`：只允许 `zw_person_` 前缀 + 标量 + `person_id>0`（key ≤64 字符）；插件消费层 `zw_oa_org_person_meta_set/delete` 契约优先、旧主题回退 `update_post_meta/delete_post_meta`；`org-repository.php` 直连 `update_post_meta/delete_post_meta` = 0 |
| 插件消费层 | `includes/org/org-contract.php`：契约优先；旧主题无契约 ⇒ 回落直读**既有** option / 直写既有 meta（过渡，不新增键） |
| 边界 | 不新建 option、不注册 `zw_org_person` CPT（CPT/schema 仍在主题）；插件内 `get/update/delete_option(ZW_ORG_*)` = 0；人员 meta 直连写 = 0 |
| 阶段 | O2a：字典 + 数据模型版本（OA-2500）；O2b：人员 meta 写口消费（OA-2501，主题契约 TASK-2821） |

**破坏性变更**：改白名单键/option 名/语义 ⇒ `zw_oa_org_data_version` +1、双仓同批、更新本节。

### 发版顺序（2026-11-14 实测固化）

| 步骤 | 动作 | 判据/原因 |
| --- | --- | --- |
| ① 主题先发 | 主题版本必须含 9 个「插件优先 / 停用回退」条件加载守卫（`function_exists`）与 `zw_oa_org_data_*` 契约 | 插件 policy 读侧（7 文件）与旧主题副本会同名重复声明 ⇒ `Cannot redeclare`；反向（新主题 + 旧插件）安全 |
| ② 插件后发 | `scripts/plugin-switch-oa.sh`（含主题兼容门禁 + 六菜单/模块符号/API 清单/REST 冒烟） | 门禁在**任何生产写入前**检查在线主题 `functions.php`，缺守卫即拒发 |
| ③ 回滚 | 插件 `--rollback <ts>`；主题按 `pre-switch-<ts>` 备份还原 | 两边独立可回；**不要**用「关 policy 模块」当回滚手段（后台菜单会一并消失，2026-11-14 实测） |

**禁止**：新插件 + 旧主题直接发版；把 `tcpc_oa_enable_policy=0` 当作发版旁路或长期回滚态。

## 只读对账

主题侧 `scripts/oa-contract-shadow-check.php`（`wp eval-file`，只读）比对「契约 vs 旧实现」，
输出 `OA_CONTRACT_SHADOW=ok|diff`，缺任一侧 rc=2 fail-closed。上线前与每次发版前必须为 `ok`。

## UI-1 · 后台样式命名空间独立（OA-2491 / 主题铁律 32 · CHG-466）

**口径（用户 2026-11-09 拍板）**：「独立仓用完全独立的样式 ui js，工作台记录差异，防止修改一处样式影响关联的其他功能」。

| 项 | 值 |
| --- | --- |
| 类名命名空间 | 本仓后台一律 `tco-*`（`tco-*` / `tco-doc-*` / `tco-org-*` / `tco-sec-*` / `tco-fc-*` / `tco-receipt-*` / `tco-policy-*` / `tco-secretariat-*`）；**不得**出现主题 `zw-adm-*` / `zw-doc-*` / `zw-org-*` / `zw-sec-*` / `zw-fc-*` 类名 |
| 样式/脚本资源 | 本仓自带 `assets/css/oa-admin.css` + `assets/js/oa-admin.js`，由 `includes/ui/ui-tokens.php` 的 `tco_ui_enqueue_assets()` 注册（仅本仓后台页） |
| 令牌出处 | 颜色/字号/间距对齐 `docs/tokens/tcpc-design-tokens.json`；CSS 一律 `var(--tco-*, <令牌兜底>)` |
| 后台壳 | 本仓自有 `tco-oa-admin` body class 与 100% 宽度规则；**不再**依赖主题会员域外壳判定 |
| 宽表横滑 / 触屏 / 徽章 / 三态 | 本仓 `includes/ui/ui-tokens.php`（`tco_ui_table_wrap_css()` / `tco_ui_touch_css()` / `tco_badge()` / `tco_ui_state_css()`）唯一出处 |
| 菜单 slug | **保持稳定**（`zw-doc-*` / `zw-org-*` / `zw-receipt-*` / `zw-policy-*` / `zw-secretariat-*`）——主题侧 RBAC 功能 ID（`sub:<parent>:<slug>`）与用户书签按 slug 保存；页面类名由 `*_page_classes()` / `*_page_slug_map()` 映射到 `tco-*`，**slug 不随 UI 改名** |
| 差异登记 | 主题 `docs/ops/satellite-ui-registry.yml`（`dev-tcpc-oa` 行）；门禁 `scripts/satellite-ui-gate.py` R1–R4 |

**纪律**：新增后台样式只写本仓 `tco-*`；新增页面必须在 `tco_ui_page_slugs()` 登记（否则不装载资源）；
确需与主题视觉不同 ⇒ 允许，但必须在该登记表留 owner/理由/日期，禁止「悄悄不同」。
