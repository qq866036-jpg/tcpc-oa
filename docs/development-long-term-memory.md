# 中传网办公协同（tcpc-oa）· 开发长期记忆

> 本文件是**本仓的长期记忆**（跨会话事实）。每次开工前必须读，收口时必须回写或按口径声明。
> **用户 2026-10-29 指令原文**：「dsh 和 中传网主题 以及相关联的 插件项目 的长期记忆均要遵守」
> 三层清单唯一出处：主题仓 `docs/ops/long-term-memory.yml`；判据唯一实现：主题仓
> `scripts/long-term-memory-gate.py`（本仓 `workbench/preflight.sh` / `workbench/finish.sh` 调用同一实例）。

## 0. 三层记忆与"谁拥有什么"

| 层 | 位置 | 谁维护 |
| --- | --- | --- |
| `dsh` | `/opt/deepseek-harness/AGENTS.md` + `/srv/ai-memory/projects/tcpc/` | harness 侧 / 主控；**本仓只读** |
| `theme` | 主题仓 `docs/development-long-term-memory.md` | 主题仓；**跨仓共性口径的唯一出处** |
| `oa` | 本文件 | 本仓；**只写本仓特有事实**，共性一律指向 theme |

**规则**：本文件不复制主题仓口径（避免两处写同一件事 ⇒ 漂移）；只记「本仓怎么落地 + 本仓特有的坑」。

## 1. 号段与开工（唯一出处：主题仓）

- 本仓纯插件任务号段 = **`OA-xxxx`**（`docs/ops/repos.yml` 的 `space: OA`）。取号：
  `ZW_TASK_SPACES=TASK,OA python3 scripts/workbench-task-id.py next --space oa`。
- 主题侧改动仍用 `TASK-xxxx`；**双端 preflight** 顺序见 `workbench/README.md` §1。
- **分层断言（TASK-2429 起）**：主题仓 preflight 只校验"号段已在分仓清单登记"；"必须等于本仓号段"
  由本仓 `workbench/preflight.sh` 与提交钩子执行（`zw_assert_task_space`）。

## 2. 开工 / 收口（本仓脚本）

```bash
bash workbench/load.sh                      # 读主题工作台摘要
bash workbench/preflight.sh OA-xxxx "role:依据;…"
# …改代码…
bash workbench/finish.sh OA-xxxx "说明"
```

- 台账 / metrics / 工作台页**只在主题仓写**；本仓不建第二套台账。
- 门禁：`.githooks` 三道钩子（无 marker 拒提交、提交信息必须含任务号、`ci-check.sh --precommit`）。

## 3. 本仓特有的硬约束（摘自 `AGENTS.md` §2，此处只留索引）

- **本仓长期记忆的建立（OA-2435）**：本文件由 OA-2435 建立（主题侧规则 CHG-435；原取 OA-2434 实测被并发会话占用而顺延），
  收口门禁为 `python3 <主题仓>/scripts/long-term-memory-gate.py finish --task OA-xxxx --repo oa`；
  改本文件后必须把任务号写进对应小节（门禁会校验"声明层里出现本任务号"）。
- **契约唯一出处** `docs/contract-tcpc-oa.md`；主题侧同名文件只留指针。
- **版本三方一致**：`VERSION` == `TCPC_OA_VERSION` == 插件头 `Version:`；`MIN_THEME` 声明最低兼容主题版本。
- **不得反向依赖主题内部实现**：只允许调主题**内核契约**（`inc/oa/contract-legal.php` 的 P-1/P-2/P-3/W-1 等）。
- **函数下沉口径**（TASK-2423 系列）：内核侧下沉的函数必须带 `function_exists` 守卫；主题 admin 副本同名定义同样带守卫 ⇒ 任意加载顺序都不 `Cannot redeclare`。
- **生产只读**：`/www/wwwroot/tcpc.org.cn` 禁止直接改；发版走 `scripts/plugin-switch-oa.sh`。

## 4. 跨仓合并与回滚

- 本仓分支 `task/OA-xxxx` → 主题仓 `scripts/dev-merge.sh OA-xxxx --repo dev-tcpc-oa --space OA`
  并入本仓 `dev`（自动定位、跑 M1/M2/M3；**不要手敲 `git merge`**）。
- 改契约 / 改号段 / 改门禁属治理变更，必须在主题台账写清理由与回滚方式。

## 5. 回写要求（机械门禁）

收口时主题仓 `scripts/long-term-memory-gate.py finish --task OA-xxxx` 会检查：
① 本仓（`oa` 层）本文件**在本次分支里有改动**；② 若台账写了 `- 记忆层：…`，该层文件里**必须出现本任务号**。
确无跨会话事实 ⇒ 在台账写 `- 长期记忆：无需入账（理由：<≥8 字>）`（禁止裸豁免）。

## 2026-10-29 · 发文数据层迁移与子批判据（OA-2439 / W3-R10a）

- **子批判据**：`scripts/verify-module-parity.sh` 新增 `--only <file,file>` —— 子批迁移不必等整模块迁完
  才能判绿；三态：一致 ⇒ `DIFF=0`；指定文件两侧都不存在 ⇒ **fail-closed 判红**；改一字节 ⇒ 判红。
  不可把「未迁完」的 23 个 missing 当成失败，也不能把「找不到」当成通过。
- **发文数据层**：`includes/doc/` 已迁 `doc-repository` / `doc-copy-number-repository` /
  `doc-number-repository` / `doc-download`（2,384 行，与主题侧逐字节一致），`doc.enabled=false` 未启用。
- **载体单路径坑（会阻塞所有 OA 轮次）**：`workbench/preflight.sh` 与 `workbench/finish.sh` 调长期记忆
  门禁时曾按**本仓任务号**拼主题工作树（`task-OA-xxxx` 不存在）⇒ 台账读不到 ⇒ 恒红。
  正确做法：用 `ZW_WORKBENCH_THEME_TASK`（载体单号）拼路径，缺省才回退同号。
- **提交信息坑**：本仓 commit-msg 拒绝任何跨号段 token（消息里出现主题号即失败），
  正文只能写「主题载体单」这类措辞。
doc-import / doc-helpers / doc-admin-list 三文件迁入（4,991 行），3 处 org/files 契约调用原样保留，模块未启用。
doc-composer / doc-render-html / doc-layout-gb9704 / doc-layout-designer / doc-pdf 五文件迁入（9,032 行）；doc-composer 的 zw_branch_leaders_save 写契约原样保留；模块未启用。
发文余批 14 文件迁入（12,236 行）；doc-appoint-cert.php 因直取主题内部类（实名客户端）单列 blocked，待契约轮；模块未启用。
doc-appoint-cert 迁入：实名客户端改走主题契约 zw_oa_realname_client_ready()，parity 登记唯一 allow 有意差异；发文 27/27 文件全部迁完。
发文渲染冒烟脚本 scripts/oa-doc-render-smoke.php：9 页 slug/回调从 doc-bootstrap 的 add_*_page 实参枚举；四断言；非 WP 与缺回调均 rc=2 不回填通过。

## 2026-10-30 · AI 秘书处迁入（OA-2446 / W4-R16）

- 7 文件 / 2,491 行已迁入 `includes/secretariat/`，与主题逐字节一致；`secretariat.enabled=false` 未启用。
- **模块定位**：不是组织架构。秘书处是「AI 辅助文秘与内容生产」：任务、质检、以及**三道人审门**
  （需求出处《功能需求规格说明书 v1.0》§3：每步结束设人审门，未获发布人确认不得进入下一步）。
- **迁移风险点（启用前必须查）**：①门状态存哪（option/自定义表）；②谁能过门（角色/能力）；
  ③迁移期若插件与主题同时加载门逻辑，是否可能**绕过一道门**——这是安全面，比页面白屏严重。
  故本轮只迁代码、不启用。
- 依赖：秘书处消费组织架构的人员与职务（同型跨模块契约依赖），启用前需与 org 契约一并核。
secretariat.enabled 置 true（钥匙默认关）；版本 0.1.3；权限沿用 tcpc_perm_doc，未给 administrator 扩权。

## 2026-10-30 · AI法务部控制面自足化与政策读侧缝（OA-2448 / 载体单 TASK-2471）

- **关联关系（本仓视角）**：AI法务部迁出的是**管理面**（后台页 + 4 个 option 的开关 UI + 条款/规则维护），
  判定与执行（`policy-module-map` / `policy-platform-cron` / `policy-rule-engine`）按 Q-A2 **留主题**；
  插件只保留与主题逐字节一致的副本用于 parity，**不得 require**（OA-2438 `Cannot redeclare` 事故固化为
  `scripts/check-kernel-symbol-collisions.sh`）。分销「控制面在插件、执行面在主题」，同库同 option 即时生效。
- **本单加固（防 fatal，不改行为）**：`includes/policy/policy-platform-admin.php` 两处直接调用主题读侧符号的地方加
  `function_exists` 兜底 —— `zw_policy_applicability_registry_groups()`（缺注册表 ⇒ `array()`）与
  `zw_policy_gate_option_state()`（缺 option 解析 ⇒ 视为未开/空白名单）。
  探针实测：读侧缺失 ⇒ `L0 / enabled=false / 空分组`、页面可渲染；读侧在场 ⇒ `L4 / enabled=true`（按 option 原值）。
- **契约**：`docs/contract-tcpc-oa.md` 新增 **P-4 政策读侧缝**（符号清单 + 缺位语义 + 版本握手 +
  插件装载面纪律）。这是「插件 → 主题（只读）」的登记缝，改签名 = 破坏性变更，须双仓同批 + 升主版本。
- **已知缺口（未修，需独立单）**：管理面的「适用模块保存」依赖主题 `scripts/migrate-policy-rule-modules.php` 的
  `zw_policy_rule_modules_*`；插件内 `dirname(__DIR__,2)/scripts/` 解析为空 ⇒ `lib_ready=false` ⇒ 该页
  fail-closed 只读（**显式**，非静默写入）。是否把该库迁入插件或登记 P-5，需要用户/架构拍板。
- **回归口径**：插件 `bash scripts/ci-check.sh`（六项）+ `bash scripts/check-kernel-symbol-collisions.sh`；
  链路本体回归仍在主题 `tests/test-policy-age-retire.php` / `test-policy-rule-engine.php` / `test-oa-policy-shim.php`。

## 2026-10-30 · 任字号发文的任命对象在「我的收文」可见（OA-2449 / 载体单 TASK-2474）

- **口径（用户 2026-10-30 指令原文）**：「任命人员 的任字号 发文要选择 被任命人员的uid 主题中已有的前提是 实名认证
  所以发文后 这个针对 uid 实名用户的任命 应该直接能 在 前端我的收文中能看到」。
- **旧行为（缺陷）**：`recipient_user_id` 只认「抄送 `_zw_doc_cc_ids` ∪ 审核人 `_zw_doc_reviewer_id`」；
  任命人员只写正文 `body.meta.appointments`，不进可见面 ⇒ 被任命 uid 在「我的收文」0 篇、预览端点 `can_view=false`。
- **唯一出处**：`includes/doc/doc-helpers.php` 的 `zw_doc_appointee_ids($doc)`（从 `appointments` 的
  `uid`/`user_id` 派生、去重、只读、不落库）。`zw_doc_get_document()` 只增派生键 `appointee_ids`；
  `zw_doc_query_documents()` 收文口径扩为「抄送 ∪ 审核人 ∪ 任命对象」；`zw_doc_can_view_doc()` 放行任命对象本人。
- **边界（一票否决）**：不新增字段/表；不改抄送/审核人语义；仍只列 `approved`；PDF 预览仍要求完成件
  `_zw_doc_pdf_status=ready`（正式用印链路），任命对象只解决「看得见」，不绕过用印。
- **测试**：主题 `tests/test-doc-member-inbox.php`（新增 5 项任命对象断言，共 47 项）；该测试原来直接 `require`
  主题已删的 doc 文件 ⇒ 迁移后恒 fatal，本单顺带改走 `tests/lib/source-path.php`（TASK-2474）。

## 2026-10-30 · 任命对象可见性按实名收口 + 任命归档证书路径兜底（OA-2450 / 载体单 TASK-2475）

- **用户口径**：2026-10-30 拍板「做」——任字号发文的任命对象可见性**只放行实名本人**（此前 OA-2449 按 uid 放行，
  但写作口径写明「前提是实名认证」；实测 `doc-composer.php` 的口径是「未实名只提示、不阻断」⇒ 门槛必须在可见性侧兜住）。
- **唯一出处**：`includes/doc/doc-helpers.php` 的 `zw_doc_user_realname_verified($uid)`；判定链
  `ZW_Realname::is_verified()`（铁律 21 canonical）→ `zw_branch_user_identity()['verified']` →
  `get_user_meta('zw_real_verified')`；三者都不可用 ⇒ **不放行**（fail-closed）。
  `zw_doc_appointee_ids()` 只返回实名 uid，`zw_doc_can_view_doc()` / 收文过滤沿用该派生键。
- **边界**：不改抄送/审核人语义；仍只列 `approved`；PDF 预览仍需完成件 `ready`。
- **证书路径兜底（潜在 fatal）**：`doc-appoint-cert.php` 的 `cert-paths.php` 兜底 require 迁入插件后解析到
  **插件根**（不存在）；改为「存在才 require」，解析不到 `zw_cert_backend_file()` 时按既有文档口径返回 null
  （调用方走 LWEMS 原路径）⇒ 不再 fatal，也不改变正常态（主题 `inc/cert-loader.php` 已先定义函数）。
- **测试口径**：主题 `tests/test-doc-appoint-cert.php` 已改为显式装载 `inc/cert-paths.php` + `inc/oa/contract-doc.php`
  （插件不得拼主题路径）；`test-doc-member-inbox.php` 增「未实名任命对象不可见」用例（50 项）。
## 2026-10-30 · 后台导航位置号段：插件在主题发放的号段内取号（OA-2451 / 载体单 TASK-2476）

- **用户口径原文**：「我认为是插件能力，如果关闭插件 那么还有模块入口 那么插件就失去了意义」＋
  「顺序为：收文管理、发文管理、ai秘书处、ai法务部、网盘文件、组织架构」。
- **定界（架构决议正文在主题仓）**：**存在权 + 位置权在插件**（关插件 ⇒ 零入口），主题只发**场地**：
  办公协同号段 `59–60.9`、分组标题、仪表盘容器。主题侧注册表只登记 `domain` / `order` 序号，**不写 position**。
- **取号（本单落地，唯一出处＝各模块 bootstrap 的 `add_menu_page` 第三位后的位置参数）**：
  收文 `59`（原 59.5）→ 发文 `59.2`（原 59）→ AI秘书处 `59.5`（原 59.2）→ AI法务部 `60`（不变）→
  网盘文件 `60.5`（原 60.7，注册仍在主题侧、待收敛）→ 组织架构 `60.7`（原 60.5）。
- **契约**：`docs/contract-tcpc-oa.md` 新增 **P-5 后台导航位置号段**（号段边界、显示顺序、取号方、
  主题不得做的事、插件缺席语义）。改号段或改「谁写 position」= 破坏性变更，须双仓同批 + 升主版本。
- **门禁**：主题侧 `tests/test-admin-nav-registry.php` 断言「域内 order 严格递增 = 显示顺序」；
  插件侧 `bash scripts/ci-check.sh`（含 `php -l`）不得回归。

## 2026-10-30 · 「启用插件」fatal：装载顺序反转（OA-2452 / 载体单 TASK-2480）

- **生产现场**：`/wp-admin/plugins.php?action=activate&plugin=tcpc-oa/tcpc-oa.php` ⇒ 500，
  `Cannot redeclare zw_policy_platform_clause_review_core()`（主题 `:303` → 插件 `:289`）；nginx error.log 留证。
- **根因（可复用结论）**：**激活请求的装载顺序与正常请求相反** —— 正常请求 `wp-settings.php` 先 require 插件、
  后 require 主题 `functions.php`（插件赢）；但 `activate_plugin()` 在 wp-settings **之后**才 include 插件文件
  ⇒ 「主题先、插件后」，主题同名副本先声明 ⇒ 插件未守卫的顶层声明 `Cannot redeclare`（整站 500，非仅插件页）。
- **纪律（本次固化）**：插件顶层函数声明**一律** `if (!function_exists('x')) { … }`；**`return` 无效**
  （PHP 在 include 期即绑定无条件顶层函数）。`policy-platform-admin.php` 的 L289→EOF 用哨兵
  `zw_policy_platform_clause_review_core` 整段包裹 ⇒ 主题在场则零声明零钩子，主题缺席行为逐字不变。
- **判据**：新增 `scripts/probe-load-order.php`（子进程隔离跑 theme-first / plugin-first，任一条 fatal 即红），
  已挂 `scripts/ci-check.sh` ⑦；`ZW_THEME_PATH` 指定主题根（CI 无主题时 skip）。
- **教训**：迁移期「DIFF=0 双份副本 + 依赖装载顺序」是**高危组合**；只要还有一份副本留在主题，
  插件侧就必须假设「主题可能先装载」。首删副本（R3/R8）前不得依赖于「插件先装载」。


## 2026-10-30 · 网盘文件菜单注册权移入插件（OA-2453 / 主题 TASK-2481）

- **用户口径（2026-10-30）**：「关闭插件后 左侧有残留信息」＋ 拍板做「主题回退删除轮」。
- **变更**：`zw-org-files` 的 `add_menu_page`（position 60.5 / `manage_options`）从主题
  `inc/admin/admin-nav-registry.php` 移入本仓 `includes/files/org-files.php`
  （`zw_org_files_register_admin_menu()`，`admin_menu` 优先级 60；回调缺失或无权限 ⇒ 零注册）。
- **配套（主题侧 TASK-2481）**：收文 / 法务管理面 / 网盘文件的主题回退实现全部改为 fail-closed shim，
  并移除「文号管理（建设中）」「审批引擎（预留）」占位 ⇒ 插件停用时六条 OA 入口一条不剩。
- **发版顺序（重要）**：**先发插件（本单 0.1.5）→ 再发主题**。反过来会出现「主题已删注册、插件还没带注册」
  的窗口期 ⇒ 网盘文件入口短暂消失。

## 2026-10-30 · 号段撞 WP 核心键：「外观」被夹进办公协同（OA-2454 / 主题 TASK-2482）

- **用户口径（2026-10-30 原文）**：「仪表盘左侧 办公协同 将 wp 外观装进去了」。
- **根因**：号段用了 `59–60.9`，而核心 `wp-admin/menu.php` 占用 `$menu[59]`（分隔线）与 `$menu[60]`（**外观**）；
  `add_menu_page()` 遇已存在键会自增 `1e-6` 避让 ⇒ 法务（60）变 `60.000001`，渲染顺序中「外观」夹在
  秘书处（59.5）与法务之间。
- **修复**：六模块 position 下移到 **58.6–58.9**（收文 58.6 / 发文 58.7 / 秘书处 58.75 / 法务 58.8 /
  网盘 58.85 / 组织 58.9），区间落在 `58.5` 小程序配置之后、核心 `59` 分隔线之前。
- **纪律（写进契约 P-5）**：**号段必须落在核心未占用键之间**；核心键全集
  2/4/5/10/15/20/25/59/60/65/70/75/80/99。区间只剩 0.3 宽，六模块已用满 —— **新增 OA 模块必须重选号段**。
- **发版顺序**：先插件 `0.1.6`、后主题。

## 2026-10-31 · 插件仓工作台副本去手工债（OA-2455）

- **本仓特有事实**：`workbench/task-space.sh` 现在**默认按主题 `docs/ops/repos.yml` 派生本仓号段**
  （调主题库 `zw_task_spaces_for_repo <本仓根>`；OA 仓 ⇒ `OA`），三钩子（preflight / pre-commit / commit-msg）
  不再需要 `ZW_TASK_SPACES=OA,task`。判据仍全部来自主题仓，本仓**不复制**号段/状态词规则。
- **严格性未放宽**：派生结果只有 `OA` ⇒ `zw_is_task_id TASK-123` 在本仓为 **false**（一仓一号仍成立）；
  只有老主题仓（无 `zw_task_spaces_for_repo`）才回落到主题默认号段并告警。
- **load.sh**：删掉自带的状态词前缀表，改 import 主题 `scripts/lib/zw_task_status.py`（`is_open`），
  并新增「号段 / 收口仓」两行输出（`repo_space_and_target()`）；插件侧"在办"与主题侧 `plan` 同口径。
- **收口命令**：`workbench/finish.sh` 打印的主题收口命令带 `--repo dev-tcpc-oa`（AGENT-0024 起主题侧按号段推仓，
  传 `tcpc-oa` 之类的别名会判红）。

## 2026-10-31 · 插件仓钩子顺序修复：finish 落 zw-finished-task（OA-2456）

- **本仓特有事实**：`workbench/finish.sh` 现在清 marker **之前**写 `<gitdir>/zw-finished-task`
  （`{task, finished_at}`）；`pre-commit.sh` / `commit-msg.sh` 在 marker 缺失时读它兜底：
  2 小时内 ⇒ 放行并按该任务号做"提交信息必须包含"校验；过期/缺失/不可读 ⇒ 仍拒绝（fail-closed）。
- **为什么**：钩子要求"提交时 marker 仍在"，而收口后 marker 必被清 ⇒ 按 README 顺序（先 finish 再 commit）
  提交必被拒，OA-2455 只能用 `--no-verify`（已留痕）。主题侧 AGENT-0023 用同一接缝解决，本单是卫星版。
- **纪律**：TTL 2 小时只覆盖"收口后立刻提交"的正常时序；长期无 marker 仍必须重新 preflight；
  **不给 `--no-verify` 任何自动通道**，ci-check 在 pre-commit 里照跑。

## 2026-11-02 · 双远端镜像同步：收口不 push，靠 sync-remotes.sh（OA-2457）

- **本仓特有事实**：`scripts/dev-merge.sh` 只对**本地** `refs/heads/dev` 做 CAS `update-ref`，**不含 push**
  ⇒ 「并入 dev」不等于「远端拿到 dev」。实测 2026-11-02：本地 `dev`=58ffb39（86 提交）／
  `github/dev`=507fc0d（22 提交，**落后 64**）／`origin/dev`=0452221（17 提交，**落后 69**）。
- **固化入口**：`bash scripts/sync-remotes.sh`（默认 dry-run）／`--apply`（真推）／`--check`（只判是否已同步，落后 rc=1）。
  只走快进（远端不是本地祖先 ⇒ 拒绝并提示人工比对，**永不做 force**）；工作区脏 ⇒ 拒推。
  **不要**把它塞进 `workbench/finish.sh`：收口脚本不写远端，避免并发收口互相顶掉。
- **远端身份**：`origin`=`/srv/git/repositories/tcpc-oa.git`（权威裸库）；`github`=`qq866036-jpg/tcpc-oa`
  （私有镜像；SSH alias `github-tcpc-oa` + `/root/.ssh/github_tcpc_oa_ed25519`；deploy key **只可 push**，
  读 Actions 日志需另配凭据，见主题仓 `docs/ops/ci-logs.md`）。
- **判据**：远端是否最新 = `git rev-list --count <remote>/dev..dev`（与 `sync-remotes.sh --check` 的 rc 同口径）。

## 2026-11-02 · 插件仓建树入口与工作树归属断言（OA-2458）

- **本仓特有事实**：任务工作树入口是 `bash workbench/worktree.sh create OA-xxxx`（基座 `…/dev-tcpc-oa`，
  分支 `task/OA-xxxx`）。**不要**用主题 `scripts/workbench-worktree.sh` —— 它把 `WORKTREE_BASE` 写死
  `/srv/git/worktrees/dev-tcpc`，在插件仓里跑会把 `task-OA-xxxx` 建到**主题仓**（错仓）。
- **断言（`workbench/preflight.sh` ⓪，fail-closed）**：任务工作树必须位于 `<主工作树父目录>/task-<本任务号>`，
  且分支必须是 `task/<本任务号>`；`main` 主工作区放行（TASK-2306 §5 首期形态）。
  逃生口 `ZW_WORKTREE_LAYOUT_ALLOW=1`，**须在台账写明理由**。
- **基座怎么算**：`git rev-parse --git-common-dir` → 主工作树的 `.git` → 其父目录即主工作树 → 再上一层即基座。
  这个算法在主工作树与任意任务工作树里结果一致（不认仓名，cert/mapp 可照抄）。
- **删除纪律**：`remove` 不带 `--force` 时，脏工作树拒绝删除；`--delete-branch` 走 `git branch -d`，
  未并入就拒绝（要丢必须显式 `git branch -D`）。

## 2026-11-02 · 卫星单收口新流程（OA-2459 回写 + 首次实跑）

- **口径已反转**：AGENT-0091 之前，卫星单在插件仓任务工作树里收口要「切回主工作树」或走
  `ZW_SPACE_MISMATCH_ALLOW=1`；之后**都不需要**。标准命令：
  `bash workbench/worktree.sh create OA-xxxx` → 双端 preflight → `workbench/finish.sh` → commit →
  主题侧 `workbench-finish.sh --task OA-xxxx --repo dev-tcpc-oa "说明"`。
- **为什么 `--task` 不能省**：不传时 finish 按 marker 里的任务号推 `--repo`（卫星单在插件仓有 marker，
  主题工作树里的 marker 属载体单 TASK 号），会判「收口仓与号段不符」而红。
- **首次实跑（本单）**：`workbench/worktree.sh create OA-2459` 建树 → 插件 preflight（载体单 marker 交叉校验）
  → 主题 finish 用新路径（marker 归因 `<基座>/task-OA-2459`、记忆回写按任务工作树）**一次通过，未用任何应急通道**。
- **仍要小心的**：一个 worktree 只能有一个未收口 marker —— 载体单先收口（清 marker）再给卫星单 preflight 时，
  若卫星单在插件仓（本单就是）两者不冲突；只有「在主题工作树里给卫星号 preflight」才需要应急通道，而那个做法已不再需要。

## 2026-11-02 · 卫星单收口顺序：先载体、后卫星（OA-2460 入档）

- **因果**：主题 `workbench-finish.sh` 收口成功即 `rm zw-workbench-active.json`（<gitdir> 级，**一个 worktree 只有一个**）。
  卫星单与载体单共用同一个主题工作树 ⇒ 先收卫星单会把载体单的 marker 清掉，载体单随后报
  「本 worktree 没有开工 marker（…/task-TASK-xxxx/zw-workbench-active.json）」。
- **正确顺序**：① 插件 `workbench/finish.sh` + commit → ② 载体单 `--task TASK-xxxx --repo theme`
  → ③ 卫星单 `--task OA-xxxx --repo dev-tcpc-oa` → ④ 两条翻 done 后提交载体分支。
- **补救**：顺序反了不必回滚代码 —— 把载体单状态临时退回 `in_progress`，重跑 preflight 取 marker，
  再 finish 即可（OA-2459 实测可行；但会多一轮 preflight/finish，属可避免的成本）。
- **为什么 ③ 不依赖载体 marker**：AGENT-0091/0093 之后，卫星单 marker 从插件仓归因（`<基座>/task-<本号>`），
  与主题工作树里的载体 marker 无关。

## 2026-11-02 · 任命书前端图与「本地证书」（OA-2461，配合主题 MEMBER-0004）

- **图源不是龙威独有**：任命书前端图原实现只认证书数据 `图片地址N`（`zw_doc_appoint_cert_fetch_images`），
  而电子证书插件本地发的 N 编号证书**没有这个字段** ⇒ 切到插件后任命书前端会空白。修法：先用主题唯一判据
  `zw_mc_cert_print_source($tid,$certNo)` 判源，local 时用 `zw_mc_cert_local_image_url()` 逐页生成受鉴权端点 URL，
  页数取 `zw_mc_cert_local_pages()`；判据/构造点缺失时**回落龙威**（fail-open 到旧链路，不制造空白）。
- **禁止自拼端点 URL**：`admin-ajax.php?action=zw_mc_cert_local_image…` 只允许由主题那一处构造（带登录+nonce+属主），
  OA 侧直接调用；自拼会绕过 nonce 语义并制造第二套 URL 形态。
- **载体单号不再限定 TASK-**：`ZW_WORKBENCH_THEME_TASK` 原正则只认 `TASK-\d+`，但主题仓业务号段（MEMBER 等）
  同样是合法载体单（本单载体 = `MEMBER-0004`）。已放宽为
  `^(TASK|MEMBER|CONTENT|BIZ|SYS|AGENT)-[0-9]{3,4}$`，仍是白名单 + fail-closed（其它形态 rc=2）。
- 卫星仓 `workbench/preflight.sh` / `finish.sh` 属门禁自编辑：提交信息带 `[self-edit]`。

## 2026-11-03 · 收文管理功能补全第一批：共享样式令牌 + 详情页（OA-2462，载体单 TASK-2544）

- **后台样式令牌的唯一出处已不是发文模块**：卡片/字段/徽章/分页/触屏 CSS 抽到
  `includes/oa/admin-ui.php` 的 `zw_oa_admin_ui_style($page_classes)`；`zw_doc_admin_ui_style()` 只是
  「传发文页面类」的薄入口（输出逐字节不变），收文侧 `zw_receipt_admin_ui_style()` 传
  `zw_receipt_admin_page_classes()`。以后**任何办公协同后台页**要卡片范式都走这一处，禁止再写第二份 CSS。
- **收文台账主查询属于主题契约面，不能改**：`zw_receipt_ledger_query()` 的 SQL 形状被主题
  `tests/test-receipt-ledger.php` 逐项断言（`FROM 政策源表 WHERE origin = %s`、`ORDER BY (doc_received_date REGEXP …)`、
  `LIMIT %d OFFSET %d` 必须是**最后一条** prepare），且主题 `inc/oa/contract-api.php` 的门户快照直接调它。
  ⇒ 新增展示字段（如办理状态）只能**另起一条**按当页 ID 的查询（`zw_receipt_ledger_issue_map()`），
  不得在原查询上加 LEFT JOIN。
- **收文详情页用 `view=detail`，不加菜单项**：与发文 `zw-doc&view=detail` 同构；主题导航注册表
  （`inc/admin/admin-nav-registry.php` 的 `receipt` 条目）与父菜单落地页 `zw-receipt-ledger` 都不动。
- **办理状态词不要扩**：`待派单` 只是展示态（无办理单），`逾期` 仍是 `zw_receipt_issue_is_overdue()` 的派生；
  拟办/批示/传阅应做 **append-only 流水表**，不许往 `zw_policy_issue` 加状态值（它同时服务法务整改台账）。
- **收文页新增 `<table>` 必须包 `.zw-adm-table-wrap`**：主题 `test-admin-h5-gate.php` 有「全树未包裹 0」判据。
- 第一批回归口径（读插件源码）：主题 `test-receipt-{ledger,issues,create,orgs,permissions,number-org,portal-office-receipt}`
  + `test-doc-admin-ui` 全绿；插件 `scripts/ci-check.sh` 七项全绿。

## 2026-11-03 · 收文 OCR 接入：两档引擎 + 单行写 + 不新增菜单（OA-2463，载体单 TASK-2546）

- **全仓原先零 OCR**（grep 证据）：`zw_policy_source` 的 `ocr_status` / `extraction_method` 两列一直没人写。
  本次新增 `includes/ocr/`（config/client/schema/job/bootstrap/admin），落点仍是 `zw_policy_source`，
  只加 `ocr_text/ocr_confidence/ocr_ms/ocr_at/ocr_message` 五列（迁移 `scripts/migrate-receipt-ocr.php`，只 ADD COLUMN）。
- **引擎选型口径**：GitHub star 实测 PaddleOCR 92,195 最高 ⇒ **首选 PaddleOCR**（中文公文 + PP-StructureV3 版面表格 +
  Apache-2.0 + CPU）；**保底 RapidOCR**（ONNX，依赖最轻，本机已自带 onnxruntime）。两档共用同一 HTTP 契约
  （`POST /ocr {path}` / `GET /health`），切换只改 `TCPC_OCR_ENGINE` 与依赖文件，**PHP 侧不动**。
  Surya 被排除的硬理由：模型权重是修改版 OpenRAIL-M（非 OSI、商用有营收门槛），不是「质量不够」。
- **只传路径不传字节**：附件已在私有存储根，服务与本插件同机 ⇒ PHP 发绝对路径、服务端用 `realpath` 前缀白名单
  + 扩展名 + 20MB 校验（**双端**都校验）。这样 PHP 不读大文件、不 base64、不产生第二份临时副本。
- **异步 + 不阻断**：登记成功只置 `pending` 并排 WP-Cron（20s），识别在 cron 跑；失败写 `failed` + `ocr_message`，
  详情页给「原因 + 重试 + 下一步」。任何时候 OCR 挂掉都不影响登记/办理 —— 这是刻意的降级语义，不是遗漏。
- **不新增子菜单**：OCR 设置挂在「收文台账 → `view=ocr`」。原因具体：新增子页会改
  `zw_receipt_permission_function_ids()` 的 ID 集与「子页 slug 与登记表一致」两条判据
  （主题 `tests/test-receipt-permissions.php` 逐项断言 5 个 slug），而本单不改主题测试口径。
- **测试取源陷阱（真踩到）**：主题 `tests/test-receipt-ledger.php` 用 `ZW_OA_DIR` 而非 `ZW_OA_PLUGIN_DIR`
  解析插件源码 ⇒ 只设后者时测试跑的是**插件主工作树**（旧代码），会「假绿」。跑收文相关测试必须
  `ZW_OA_DIR=<任务工作树> ZW_OA_PLUGIN_DIR=<任务工作树>` 两个都设。
- 未验证：PaddleOCR 适配器未在真机跑（本机未装 paddlepaddle）；RapidOCR 真机识别耗时/准确率未测（依赖安装耗时）。

## 2026-11-03 · 收文办理后半段：append-only 流水 + 角标改 $menu（OA-2464，载体单 TASK-2549）

- **拟办/批示/传阅/催办一律不碰状态机**：`zw_policy_issue` 同时服务法务整改台账，扩状态值会污染语义
  ⇒ 新表 `zw_receipt_handle_log`（只 INSERT）+ `zw_receipt_circulation`（只 INSERT，签阅只写 `read_at`）。
  收文状态仍是 4 态 + 派生逾期；拟办/批示只是「留痕」，不是「状态」。
- **归档三列只单行 UPDATE**：`page_count` / `archive_box_no` / `archive_year` 在 `zw_policy_source` 上，
  `WHERE id = %s` 写三列；台账主查询 `zw_receipt_ledger_query()` 的 SQL **不许动**（契约面 + 主题断言），
  归档信息用独立小查询 `zw_receipt_handle_archive_info()`（与 `zw_ocr_record()` 同套路）。
- **待批示角标不能改 add_menu_page 实参**：主题 `tests/test-receipt-number-org.php` 取源判据是
  「add_menu_page 块内出现 58.6」，把菜单标题换成变量会直接判红（本次实测踩到）。正确做法：
  保持 `add_menu_page('收文管理','收文管理',…,58.6)` 原样，**之后**遍历 `$GLOBALS['menu']` 改标题加气泡。
- **签阅是「同一行的状态变化」，不是新留痕**：所以 `read_at` 用单行 UPDATE（且 `AND read_at IS NULL`
  保证只写一次），不要往 handle_log 里再插一条。
- 迁移 `scripts/migrate-receipt-handle.php`（dbDelta + ADD COLUMN）**未在真库执行**，属发布窗口动作。

## 2026-11-03 · 收文上线顺序与回滚点（OA-2465，载体单 TASK-2550）

- **迁移脚本不在生产插件目录**：`scripts/` 与 `docs/` 是 `export-ignore`（`git archive` 产物只有运行时），
  所以 `wp eval-file` 必须指向**插件仓检出**里的脚本（`$OA/scripts/migrate-*.php`），不是 `/www/.../plugins/tcpc-oa/scripts/`。
  OCR 服务物料同理从检出目录 `install` 到 `/opt/tcpc-ocr`。
- **上线顺序**：备份 → 升版三处一致 → `plugin-switch-oa.sh` 发版 → OCR 五列迁移 → 办理后半段两表三列 →
  装 OCR 服务（先 RapidOCR，`/health` 绿）→ 后台开 OCR 开关（默认关，未开=零行为）→ 冒烟 10 条 → 质量抽检 3 份。
- **回滚只回插件版本与开关，不回滚新增列/表**：加列加表对旧代码无影响，DROP 反而丢留痕；
  异常时先关 `zw_ocr_enabled`，仍异常再 `--rollback <ts>`，避免一次退两步。
- 速度与精度权衡：默认 RapidOCR mobile 模型，抽检 `conf < 75` 或表格串行 ⇒ 切 PaddleOCR（`TCPC_OCR_ENGINE=paddleocr`），
  HTTP 契约不变，插件侧零改动。
## 2026-11-03 · 收文上线窗口实跑（OA-2466，载体单 TASK-2551）

- **版本**：0.1.6 → **0.1.7**（三处同改：`VERSION`、`TCPC_OA_VERSION`、插件头 `Version:`）。
- **窗口顺序（实跑）**：备份（`/root/tcpc-oa-backup-<ts>.tgz` + `/root/tcpc-oa-db-<ts>.sql`）→
  `plugin-switch-oa.sh --dry-run` → 正式发版 → `migrate-receipt-ocr.php`（dry-run/execute/verify）→
  `migrate-receipt-handle.php`（同上）→ OCR 服务（venv + systemd + `/health`）→ 后台开开关 → 冒烟。
- **迁移脚本必须从插件仓检出跑**（生产插件目录没有 `scripts/`，export-ignore）。
- **回滚点**：插件 `bash scripts/plugin-switch-oa.sh --rollback <ts>`；OCR 只关开关（`zw_ocr_enabled=0`）；
  新增列/表不回滚（对旧代码无影响，DROP 会丢留痕）。

## 2026-11-03 · 上线热修：列探测的 assoc/isset 坑（OA-2467，载体单 TASK-2552）

- **`zw_receipt_source_columns()` 返回的是 `['列名' => true]` 关联数组**，不是列名列表。
  判「列是否存在」必须 `isset($cols[$col])`；用 `in_array($col, $cols, true)` 比对的是**值**（全是 true）⇒ 恒判缺列。
  0.1.7 上线后「拟办/批示/传阅/归档」三卡与待批示角标因此整块不生效（DB 三列已加，页面却显示「尚未迁移」）。
- **迁移脚本的收尾自检要传 `$fresh = true`**：`zw_ocr_missing_columns()` 原先不接受参数、走请求级缓存，
  `execute` 刚加完列就自检会误报「仍缺列」（数据其实已写入）。现已支持透传。
- 教训：**列探测函数返回值形状要写进 docblock 并配一条断言**（本次是「函数名叫 columns，返回却是 map」的典型陷阱）。

## 2026-11-03 · 收文自动办理：批示库 + 20 秒自动签阅（OA-2468，载体单 TASK-2554）

- **用户口径**：「领导批示 和 签阅 为我根据政治站位编一个批示库，签阅 在收文20秒后设置一个签名时间戳即可」。
  落地为两个开关（`auto_instruction` / `auto_sign`，默认**开**），复用 `zw_receipt_handle_log`，**无新表无迁移**。
- **审计红线（不许让路）**：自动条目 `author_login=system:auto-instruction|auto-sign`、`author_name=系统自动…`，
  时间线渲染灰标「系统自动」。批示是「系统按库生成」、签阅是「系统时间戳」，**不得伪装成领导/本人操作**。
- **批示库选词是确定性的**：`crc32(receipt_id|category) % count` ⇒ 同一收文永远同一条（复现、可审计），
  不同收文自然分散；匹配顺序固定「督查整改→请示报告→人事→财务→贯彻落实→部署落实→常规阅办」。
- **内部动作 `sign` 只能内部写**：`zw_receipt_handle_add($raw, $extra_actions, $system_login)` 第三参传 system 标识；
  人工表单仍只给 拟办/批示/催办 三项（否则任何人可手写"签阅"）。
- 未做（等确认）：auto_dispatch / auto_circulate / auto_urge / auto_archive；LLM 生成批示需另立合规评估。

## 2026-11-03 · 0.1.9 上线（OA-2469，载体单 TASK-2555）

- 0.1.8 → **0.1.9** 三处同改后 `plugin-switch-oa.sh` 发版；**零迁移**（批示库/自动签阅复用既有表）。
- 上线后验证口径：登记一条测试收文 → 详情页应立刻出现「系统自动（批示库）」批示；
  20 秒后出现 `自动签阅` 条目，`created_at` 即签名时间戳；时间线两条均为灰标「系统自动」。
- 回滚：`plugin-switch-oa.sh --rollback <ts>`；或后台把两个自动开关关掉（零行为）。

## 2026-11-03 · 收文检索扩 OCR 正文 + 台账 CSV 导出（OA-2470，载体单 TASK-2556）

- **检索**：台账关键词子句在 `ocr_text` 列存在时追加 `OR ocr_text LIKE %s`（**同一张表**，不加 JOIN、不改契约形状；
  列不存在则退回原三字段）。判存在用 `isset($cols['ocr_text'])` —— 该函数返回关联数组，`in_array` 会恒假（OA-2467 的坑）。
- **量级**：LIKE 全表扫描在协会量级（数百–数千行）可接受；**不建全文索引表**，一旦上千行再评估 MySQL FULLTEXT。
- **导出**：`admin-post.php?action=zw_receipt_export`，`tcpc_perm_doc` + **按筛选指纹绑定的 nonce**
  （换筛选即换 nonce，防手拼链接批量拉库）；CSV 带 UTF-8 BOM；上限 5000 行；不含 OCR 正文与个人信息明细。
- 导出另起一条 `SELECT ... LIMIT`（不复用台账分页查询，避免把「契约面 SQL」拉长），归档三列缺列时不查。

## 2026-11-03 · 收文呈批单/传阅卡打印（OA-2471，载体单 TASK-2558）

- **不引第三方 PDF 库**：打印走「打印友好 HTML + @media print」，浏览器原生打印/另存为 PDF。
  理由：中文字体嵌入、分页断行在 PHP 侧自己算容易出错；打印件也不需要服务器落盘。
- 端点：`admin-post.php?action=zw_receipt_print&id=<ID>&doc=approval|circulation`，
  鉴权 `tcpc_perm_doc` + **按记录+单据类型绑定的 nonce**；只读、不写任何表。
- 单据内容边界：来文要素 + 拟办/批示/办理单/传阅；**不含 OCR 正文与个人信息明细**。
- 版式：A4 竖版、`@page` 15mm、正文 14px/1.6、表格 1px 实线；打印栏 `@media print{display:none}`。

## 2026-11-03 · 自动催办 + 自动派单（OA-2472，载体单 TASK-2559）

- `auto_urge`：每日 08:30 cron 扫 `policy_issue`（source_type=incoming 且 status 不在 已办结/归档），
  时限在 N 天内（含逾期）⇒ 写 `urge` 留痕；**幂等键 = 办理单 ID + 时限**（内容里带 `# <id> ｜时限 <due>` 标记）。
  不改办理状态、不派单；默认关。
- `auto_dispatch`：登记后按 option `zw_receipt_dispatch_rules`（org_key => dept/days）派单，
  **只补第一张**（已有办理单就跳过），走 `zw_receipt_issue_create()`；派单后写 `dispatch` 审计行；默认关。
- 规则存 option（设置页一行一条 `部委 | 部门 | 天数`）；两天数的语义是**自然日**（不是工作日），已在 UI 说明。
- 自动写入仍一律 `system:*` 标识 + 灰标；`dispatch` 是新增的内部动作（人工表单不出现）。

## 2026-11-03 · 自动归档（OA-2473，载体单 TASK-2560）

- 门禁：所有办理单已收口（`已办结`/`归档`；**无办理单视为收口**）且 `archive_box_no` 为空。
- 盒号：模板 `{year}-{org}-{seq}`（可配），`seq` = 同年度已有归档数 + 1、4 位补零；**查重失败换号重试 ≤20 次**，
  仍冲突 ⇒ `skipped:box_conflict`（绝不覆盖别人盒号）。
- 年度：收文日期年，缺失/非法取当前年；写入走 `zw_receipt_archive_save()` 单行三列 + `archive` 审计留痕。
- 默认关；每日 09:10 cron 扫描；零迁移。

## 2026-11-03 · 自动归档误写事故与门禁收紧（OA-2474，载体单 TASK-2561）

- **事故**：0.1.13 首版 `zw_receipt_auto_archive_one()` 把「无办理单」当收口；在真实库上跑一次扫描
  （limit=50）即把 3 张真实来文写上测试盒号（`2026-cssw-0001`/`2026-mca-0003`/`2026-other-0004`）。
  发现后立刻用一条 `UPDATE` 把 3 行 `archive_box_no/archive_year/page_count` 置回 NULL（影响 3 行，已核）。
- **教训（写进脑子）**：任何「批量扫描 + 自动写业务字段」的新功能，**第一次在真实库上跑之前**必须先：
  ① 用 `limit=1` 或 dry-run 形态验证命中面；② 确认「无前置条件」的样本不会被误判为满足条件。
  「缺失即满足」是最危险的默认值。
- **收紧**：办理单为 0 ⇒ `skipped:no_issue`；要归档无单收文必须显式 `zw_receipt_archive_allow_no_issue=1`。
- 版本 0.1.13 → 0.1.14；零迁移（只改门禁逻辑）。

## 2026-11-03 · 收文统计口径（OA-2475，载体单 TASK-2562）

- **只读**：统计全部是 SELECT 聚合，不写任何表；导出也只读。
- 口径唯一出处：`includes/receipt/stats.php` 头注释 + 页面底部说明（两处必须同步改）。
- 逾期 = 未收口（status ∉ 已办结/归档）且 `due_date` < 今天；待批示 = 有拟办无批示（与详情页角标同源）；
  归档率 = 已写盒号 ÷ 收文总量。
- 不引图表库：横条用共享令牌色 + 宽度百分比，导出 CSV 用同一份 `collect()` 结果（页面与导出口径不会漂）。

## 2026-11-03 · 收文通知与主题通知中心的衔接口径（OA-2476，载体单 TASK-2563）

- **Webhook 名称/地址/默认群/事件绑定只在主题侧**（`inc/notification-center/wecom-webhooks.php`：
  `zw_notify_wecom_webhooks` / `zw_notify_wecom_event_webhook_map`）。插件**只登记事件 + 调用发送**，
  不存任何密钥或 URL —— 这是跨仓红线，别在插件里再长出一套 webhook 配置。
- 事件登记走过滤器 `zw_notify_wecom_events`（先例：活动域、安全中心），不改通知中心文件。
- 投递结果与重试全在 `ZW_Notify_Log`（「通知中心 → 通知记录」）；绑定到停用/无效群时**不改道默认群**。
- 幂等：同一事件同一自然日一次（transient 24h）；摘要合并、不按件刷屏。
- 主题缺席（无 `zw_notify_wecom_send_event`）时静默降级为只做站内提示。

## 2026-11-03 · 统计显示回退约定（OA-2477，载体单 TASK-2565）

- 统计/报表里凡是「字典 key → 显示名」的地方，**缺中文名不得裸露 key**：显示「未登记单位（key）」+ 补名入口提示。
  这条适用于收文统计；以后新增别的字典维度报表沿用同一约定。

## 2026-11-03 · issuer 取值面与显示映射（OA-2478，载体单 TASK-2566）

- 实测：`zw_policy_source.issuer` **不是稳定的 key**——同库并存 `cssw`（key）、`民政部`（中文名）、
  `中央社会工作部办公厅`（中文名+后缀）。任何「按 key 查名字」的显示都会误判中文名。
- 统一用 `zw_receipt_stats_org_label()`：key 精确 → 中文名精确 → 中文名互相包含（最长匹配）→ 未登记回退。
- 更彻底的修法（未做，建议）：登记时把 issuer 规范成 key（或在字典里补「办公厅」这类后缀别名），
  否则映射只能靠模糊包含。这条留给数据侧决策。
