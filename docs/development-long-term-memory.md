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

## 2026-11-04 · issuer 口径唯一出处（OA-2479，载体单 TASK-2567）

- **口径唯一出处**：`zw_receipt_org_key_from_issuer()`（`includes/receipt/receipt-orgs.php`，TASK-1297）——
  key 精确 → 关键字表（含过滤器 `zw_receipt_org_issuer_map`）→ 未命中返回空串（**不擅自归「其他」**）。
- 任何「issuer → 部门/目录/显示名」的地方都调它；**不要**再写第三套模糊匹配（OA-2478 的私有实现已删）。
- 存量 `issuer` **保持原值**（全名是公文台账要的信息）；不做数据改写。覆盖情况用只读审计脚本看清：
  `wp eval-file scripts/receipt-issuer-audit.php`。

## 2026-11-04 · 聚合计数按显示名累加（OA-2480，载体单 TASK-2568）

- 分组聚合后再按「映射后的显示名」归并时，**必须累加**（`+= cnt`），不能赋值覆盖：
  同一部委可能由多个 issuer 变体汇入（`cssw` + `中央社会工作部办公厅`），覆盖会静默少算。
- 自查方法：统计页「按来文单位」各维度件数之和应等于「收文总量」。

## 2026-11-04 · 派单规则与干跑预览（OA-2481，载体单 TASK-2569）

- **部委→部门是业务口径**，代码只给「建议规则」（`zw_receipt_auto_dispatch_suggested_rules()`）+ 可编辑规则框；
  不要把它写死成自动生效的默认值。
- **干跑预览**（`zw_receipt_auto_dispatch_preview()`）只读：算「会派给谁/时限/为什么跳过」，**不生成工单**。
  开自动派单前必须先看预览 —— 这是 0.1.13 误归档事故后立的规矩：**批量写入前先干跑**。
- `auto_dispatch` 保持默认关；开启属于业务决定，由用户点。

## 2026-11-04 · 派单通配规则（OA-2482，载体单 TASK-2571）

- 用户口径「所有收文都归办公室」用**通配规则 `*`** 表达：`* | office | 5`；
  具体部委 key 的规则优先（以后想给某个部委单开例外，加一行即可）。
- 解析唯一出处：`zw_receipt_auto_dispatch_rule_for($rules, $org)`；派单与干跑预览都调它。

## 2026-11-04 · 档案处置红线（OA-2483，载体单 TASK-2573）

- **系统永不删除文件或记录**：销毁 = 写状态 + 清册 + 留痕；物理删除由人线下执行（代码里不得出现 unlink/DELETE）。
- 销毁三条门禁：已归档（有盒号）+ 保管期限已到 + 填批准人；缺一不可。
- 清退（returned）≠ 销毁：归还来文单位，档案件与文件都保留。
- 数据在独立表 `zw_receipt_disposal`（append-only，不改主表）；迁移 `scripts/migrate-receipt-disposal.php`。

## 2026-11-04 · 新建表必须跟随主表排序规则（OA-2484，载体单 TASK-2574）

- 事故：`dbDelta()` 建的处置表拿到 `utf8mb4_unicode_520_ci`，与主表 `utf8mb4_general_ci` JOIN 报
  `Illegal mix of collations`（页面与函数都拿不到数据）。
- 规矩：**任何新表的外键式字符列，建表时显式 `DEFAULT CHARSET/COLLATE` 取主表的规则**；
  跨表比对**优先在 PHP 里做**，不要动辄 JOIN（排序规则不一致在生产很难第一时间发现）。

## 2026-11-04 · 收文 5 张表补 canonical 横滑包裹（OA-2485，载体单 TASK-2577）

- 主题 `tests/test-admin-h5-gate.php` 段 U 的扫描面包含 OA 插件 `includes/`；**任何 `<table>` 都必须落
  canonical `.zw-adm-table-wrap`**，否则「未包裹 0」判红。收文打印页不在后台外壳作用域内 ⇒ 在
  `zw_receipt_print_css()` 内**透传** `zw_adm_ui_table_wrap_css()`，不复制第二套声明；统计页规则由
  `zw_receipt_admin_ui_page_open()` 透传。
- 复核命令：`php scripts/admin-h5-static.php --paths <OA includes> --json` ⇒ **52/52 全包裹**（本单前 47/52）。

## 9. 跨仓验证已接线（OA-2486 / 载体单 AGENT-0244，2026-11-04）

- **本仓 `workbench/finish.sh` 已接**：在「本仓质量门」之后调用主题仓
  `scripts/plugin-gate.sh dev-tcpc-oa`（唯一入口；本仓不复制判据）。
- 跑的是：本仓 `ci-check.sh` + 主题里断言本仓源码的 **10 条** `test-oa-*`。
- **为什么 oa 优先**：四个卫星仓里，oa 是**唯一没有任何跨仓兜底**的（cert 曾有专属契约门禁、
  workbench 自举、mapp 有专属 CI），所以「oa 绿、主题红」此前无任何机制拦截。
- **失败语义**：红 ⇒ 中断收口（marker 保留），不静默放行。

## 10. 工作台卡片 provider（OA-2487 / 载体 AGENT-0248，2026-11-04）

- `includes/workbench-card.php`（照抄 workbench 模板，id=`oa`，版本取 `TCPC_OA_VERSION`）；`tcpc-oa.php` 在 load-list 之后 require。
- 纪律：只返回数组、不渲染、不读文件。

## 收口 marker 的 finally 语义（OA-2488，2026-11-04）

- `workbench/finish.sh` 在「本仓质量门通过之后」置 `ZW_MARKER_CLEAR_ON_EXIT=1` 并 `trap ... EXIT`：
  之后任何失败（含长期记忆判据、跨仓验证）都会清 marker，不再残留拦住下一单。
- 质量门**之前**失败仍**保留** marker（在制语义不变）。

## 2026-11-09 · 卫星仓后台 UI 全量独立（OA-2491 / 载体单 AGENT-0332 / 铁律 32）

- **用户拍板**：「独立仓用完全独立的样式 ui js」。本仓后台从主题命名空间整体迁到 `tco-*`，判断依据
  不是「想不想一致」，而是**爆炸半径**：此前主题改一处 `.zw-adm-*` 内联样式，本仓多个后台页会静默变样。
- **唯一出处（改样式先看这三处）**：
  - 类名/助手：`includes/ui/ui-tokens.php`（`tco_ui_table_wrap_css()` / `tco_ui_touch_css()` / `tco_badge()` /
    `tco_ui_state_css()` / `tco_ui_page_slugs()` / enqueue / body class）；
  - 样式资源：`assets/css/oa-admin.css`（颜色一律 `var(--tco-*, 令牌兜底)`，令牌来源
    `docs/tokens/tcpc-design-tokens.json`）+ `assets/js/oa-admin.js`；
  - 契约：`docs/contract-tcpc-oa.md` §UI-1。
- **关键陷阱（本次实测）**：
  1. **菜单 slug 不能跟着类名改**：主题 `inc/admin/org/org-permissions.php` 的 RBAC 功能 ID 形如
     `sub:zw-doc:zw-doc-ledger`，权限配置存 DB ⇒ 改 slug 会造成既有角色权限静默丢失。页面类名走
     `*_page_classes()`（发文/收文）与 `*_page_slug_map()`（收文 slug→类名）映射，slug 保持稳定。
  2. 机械替换**不能**用 `bulk-replace.py --map` 传含 `=` 的旧串（如 `class="wrap X"`）——它按第一个
     `=` 切分，会把 old 截成 `class`，造成全仓 `class` 被替换成新值的灾难（本次已回滚重来）。
     正确写法：旧串用 `"wrap X"`（不含 `=`）或纯 token/选择器形态。
  3. `zw-adm-org-X` 与 `zw-org-X` 原本双写在同一元素 ⇒ 同映射后会得到重复 class（无害但不美观）；
     新写标记只用一个 `tco-org-*`。
- **门禁**：主题 `scripts/satellite-ui-gate.py` 对 `dev-tcpc-oa` 跑 R1–R4（自带资源 / 零主题耦合 /
  令牌可追溯 / 差异登记）；本仓 `bash scripts/ci-check.sh` 七项必须全绿。
## OA-2490 发版脚本补属主/权限归一（2026-11-06）

- **背景**：2026-11-06 学习插件上线事故 —— 安装目录 `root:root 700` ⇒ PHP-FPM(www) 无法进入 ⇒
  插件"显示 active 却从不加载"，而 root 下 `wp eval` 完全看不出异常。
- **本仓实测**：生产 `tcpc-oa` 目录曾为 `root:root 755`（靠 o+r 兜着能跑），一旦某次复制带来 `600` 文件即复现同款故障。
- **改动**：`scripts/plugin-switch-oa.sh` 原子交换后**强制** `chown -R ${ZW_OA_OWNER:-www:www}` + 目录 755 / 文件 644，
  并**回读** `stat -c '%a %U:%G'`；不符打印告警（不静默放过）。
- **现场**：生产 `tcpc-oa` / `tcpc-mapp` 已 `chown -R www:www`，回读 `755 www:www`，HTTP 探针确认两插件仍加载。

## 收口必须传 `--repo-path`（OA-2492 / 载体单 AGENT-0342，2026-11-09）

- **事实**：本仓 `workbench/finish.sh` 调主题仓 `scripts/plugin-gate.sh dev-tcpc-oa` 时**原先不传** `--repo-path`
  ⇒ 入口按 `docs/ops/repos.yml` 的登记路径（= 主工作区 `…/dev-tcpc-oa/main`，即 dev）求值：
  `PG_CI` 跑的是 **dev** 的 `ci-check.sh`、`PG_SUBSET` 子集对着 **dev** 的源码
  ⇒ 收口那一刻**查不出本分支会引入的违规**；并入把 dev 弄红后，下一单 finish 的 `PG_CI` 必红 ⇒ 死锁。
- **改法**：改为 `plugin-gate dev-tcpc-oa --repo-path "$REPO"`（`$REPO` 是本仓工作树根）。
- **症状记忆**：遇到「本仓全绿、并入 dev 后主题侧红」时，**先怀疑门禁看的是 dev**。

## `zw-finished-task` 必须带 2 小时 TTL（OA-2493，2026-11-09）

- **实测缺陷**：`workbench/commit-msg.sh` 在 marker 缺失时读 `zw-finished-task` 校验「提交信息必须包含该任务号」，
  但**原先没有 TTL**（只有 `pre-commit.sh` 有 2 小时判据）⇒ 留痕一旦落盘就**永久**生效。
  2026-11-09 实测：3 天前的旧留痕（OA-2490，2026-11-06 10:50）把 **OA-2492 的合并提交**拦住，
  只能人工删陈旧留痕才能并入（`git commit --no-verify` 是禁用的逃生口，不该被这种事逼出来）。
- **改法**：`commit-msg.sh` 的 sentinel 分支补「只认 2 小时内」判据（与 `pre-commit.sh` 同口径）：
  过期/不可读/无 task ⇒ 视为**无留痕**（不参与"必须包含"校验）。
- **判别力（实测两态）**：marker 移除后，新鲜留痕（2h 内、任务号不同）⇒ rc=1 拦住；
  3 天前留痕 ⇒ rc=0 放过。**严格性未降**（2 小时内仍是硬约束）。
- **口径**：任何「一次性/短时状态」落盘成文件，都必须带 TTL 判据 —— 否则它会变成永不失效的第二事实源。

## OA-2494 发版互斥接入：**两条生产写入路径**都要锁（2026-11-10）

- **本仓事实**：`scripts/plugin-switch-oa.sh` 现在有两条生产写入路径，两条都取同一把锁
  （唯一实现 `scripts/lib/release-lock.sh`，主题 `scripts/lib/plugin-release-lock.sh` 的逐字副本）：
  ① 正常发版——锁在「① 版本三方 + ② 兼容矩阵」之后、**③ 构建产物（首个写入）之前**；
  ② `--rollback <ts>`——还原备份前取锁，且**显式放开版本降级**（回滚本身就是降级），
  成功后把「回滚到的版本」写进 `.released`，让后续守卫以实际在跑版本为基线。
- **失败语义**：锁被占 / 版本降级被拒 ⇒ 退出码 **3**，且**未改动生产目录**；
  逃生口 `ZW_RELEASE_LOCK=0`（跳过）与 `--allow-downgrade`/`--force-unlock`（放开降级），均须在台账写明理由。
  锁在 `/root/tcpc-release-locks/tcpc-oa.lock|.released`（0700/600），`kill -9` 随进程退出自动释放。
- **权限回读改 fail-closed**：原先回读只 `echo "⚠ 可能不加载"`，现在 `oa_verify_perms` 判红 ⇒
  用**本次备份**自动还原（`oa_restore_backup`）再 `fail`。理由：CLI 以 root 跑 `wp eval` 看不出
  `www` 读不到目录（learning `root:root 700` 同源事故），只告警等于把事故留给用户发现。
- **坑（本单实测）**：① 脚本是 `set -uo pipefail`（无 `-e`）+ 多个 `exit 0` 成功出口 ⇒ 解锁必须靠
  `trap … EXIT INT TERM` 兜，只在正常终点 release 会漏掉 `--no-smoke` / dry-run 两条出口；
  ② dry-run **要取锁**（这样版本守卫的结论也是真的），但**不能**写 `.released`（没发布），
  否则一次演练就会把基线抬到未上线版本；③ 顺序判据要用**行号**锚定注释段（`# ── ③ 构建产物`），
  不要在整文件里盲 `grep` 关键函数名（回滚路径也含同名调用，会取到前面那一处）。
- **判据**：`tests/test-release-lock.sh` 30 项（行为 10 + 正常路径接线 9 + 回滚接线 3 + 回读接线 3 +
  权限本体 4），已进 `scripts/ci-check.sh` ⑧；反向控制（降级判断改 `if false` ⇒ 用例②红）已验。

## 2026-11-11 · OA-2495：CSV 导出 fputcsv 必须显式传 escape（PHP 8.4）

- 事实：PHP 8.4 起 `fputcsv()` 不传第 5 个参数 `$escape` 会发 deprecation；管理面渲染测试会因此判红（B1 验收红项）。
- 口径：本项目所有 `fputcsv()` 一律写 `fputcsv($fh, $row, ',', '"', '')`（显式空 escape，输出与旧默认一致）。
- 落点：`includes/policy/policy-platform-consent.php`（CSV 导出两处，本次已修）；新增导出代码照此写。

## 2026-11-14 · OA-2498 S1：政策正文内容规则（插件侧唯一实现）

- **用户口径**（TASK-2814 / 2026-11-14）：隐私/注册政策数据库化，更新政策不再发版；正文入库前必须有规则硬校验。
- **落点**：`includes/policy/legal-content-rules.php`（纯 PHP、零 WP 依赖，`rules_version=1`）；
  文档中心「新建版本」「确认生效」两处调用；不通过 ⇒ 不建版本/不生效，回执 `content_invalid`，
  逐条原因存 5 分钟 transient（不落库）并在提示条列出。
- **规则**：隐私政策 14 个必备章节 + 产品文档禁用词 + 22100 字上限；用户协议 30000 字护栏 + 禁用词；
  未登记文档（章程等）跳过，避免误伤。
- **坑（已修）**：PCRE 的 `\b` 在「…到工作台点…」这类中文语境不成立 ⇒ 中文禁用词一律去掉 `\b` 按子串匹配，
  只有 `PRD` 英文缩写保留词边界；首版实测漏报，回归已锁。
- **回归**：`php tests/test-oa-legal-content-rules.php` 18 项；`bash scripts/ci-check.sh` 八项全绿（90 文件 lint）。
- **待办（S1 收口）**：主题 `scripts/privacy-policy-content-check.php` 改薄包装（优先加载本文件），
  消除第二套规则；引擎（legal-text-source/data/logic/bridge）迁入本仓另批。
- **未验证**：真实 WP 后台表单提交 + transient 回执渲染（本单无浏览器会话，仅源码/纯函数级证据）。

## 2026-11-14 · OA-2498 S1：读源/版本模型迁入（插件优先，主题回退）

- **迁入**：`policy-platform-data.php` / `legal-text-source.php` / `logic/legal-text.php` / `legal-admin-bridge.php`
  复制进 `includes/policy/`（首版 4 文件 DIFF=0）；`policy-platform-admin.php` 装载顺序改为
  data → legal-text-source（内部 require logic）→ bridge → 3 个管理面。
- **两处有意差异**（仅插件副本）：
  ① `zw_legal_text_export_archive()` 写 `uploads/tcpc-legal/`，不再写主题 `docs/legal/`
  （政策更新是 DB 发布，不要求主题发版/commit）；
  ② `zw_legal_text_audit()` 去掉「反向 require 主题 admin」候选，审计写入缺位即 fail-closed
  （本仓 `policy-platform-admin.php` 已提供 `zw_policy_platform_audit_insert`）。
- **主题侧回退**：主题同名副本**保留但不再优先**；`functions.php` 的 data / bridge / legal-text 三处
  require 改为 `function_exists` 条件（插件在线即跳过）。主题副本在 S2/S4 补完静态降级后删除。
- **冲突面**：插件 admin 先声明 `zw_policy_platform_table/exists/ready`；data.php 的同名声明带守卫 ⇒ 跳过。
  主题 legal-text 副本的所有函数均带 `function_exists` 守卫 ⇒ 即使无条件加载也不会 `Cannot redeclare`。
- **门禁**：`ci-check` 八项全绿（94 文件 lint / 反向依赖 0 / 双序装载安全 / 发版互斥 30/30）；
  `verify-module-parity.sh policy --expect-theme-absent` ⇒ `POST-MIGRATION OK`（插件 11 文件、主题仅 shim）。
- **未验证（如实）**：真实 WP 下「插件在线 + 主题条件加载」的运行时行为（本机无 WP 会话）；
  `uploads/tcpc-legal/` 归档在生产的可写性；S1 尚未删主题副本（有意，留回退）。

## 2026-11-14 · OA-2498 S2：判定引擎/签署/拦截/通知/定时迁入（插件优先，主题回退）

- **迁入 6 文件（首版 DIFF=0）**：`policy-module-map` / `policy-rule-engine` / `policy-platform-cron` /
  `legal-consent` / `legal-consent-gate` / `legal-consent-notify` → `includes/policy/`。
- **装载顺序**（`policy-platform-admin.php`）：module-map → data → rule-engine → legal-text-source →
  consent → gate → notify → cron → bridge → 3 个管理面。主题侧 9 处 require 全部改为
  `function_exists` 条件（插件在线跳过、停用回退），不再依赖「谁先装载」。
- **冲突面**：6 个文件函数均带 `function_exists` 守卫；cron 顶部对主题 admin 的 require 有
  `!function_exists('zw_policy_platform_table')` 守卫 ⇒ 插件在线时整段跳过。
- **门禁**：`ci-check` 八项全绿（100 文件 lint / 双序装载安全）；`verify-module-parity.sh policy
  --expect-theme-absent` ⇒ `POST-MIGRATION OK`（插件 14 文件、主题仅 `policy-platform-admin.php` shim）。
- **待办（收口前）**：主题 `scripts/privacy-policy-content-check.php` 改薄包装到本仓规则；
  主题侧 10 个回退副本删除与静态降级页（S4）另批。
- **未验证**：真实 WP 下「插件在线 + 主题条件加载」的运行时（含 cron 事件是否重复注册）需生产/预发实测。

## 2026-11-14 · OA-2499：OA API manifest / 版本握手 / 降级 + parity 登记

- **新增** `includes/api/oa-api.php`（无条件装载，零行为）：唯一能力清单 11 符号
  （legal.text/consent、policy.check/gate、doc.inbox、storage.get、org.person、dashboard.snapshot），
  每符号带 provider / version / php / degrade(empty|fail_closed|deny) / consumers。
- **版本握手**：`tcpc_oa_api_version()` + `tcpc_oa_api_supports()`；`available()` 区分「登记」与「运行时可用」；
  `call()` 返回 `{ok,code:ok|unknown|unavailable,degrade,data}`，不抛错。
- **REST**：`GET /wp-json/tcpc-oa/v1/manifest|health`，只读 + `read` 能力位，**无写路由**。
- **主题代理**：`inc/oa/contract-api.php` 的 `zw_oa_api_*` 原样投影/委托；插件缺 ⇒ `source=off` 零值 + fail_closed。
- **parity 登记补齐**（补 OA-2498 收口）：policy 14 文件、org 5 文件（`org-permissions.php` 按 Q2 exclude、删陈旧 allow）。
- **回归**：`php tests/test-oa-api-manifest.php` 23 项；`php tests/test-oa-api-proxy.php`（主题侧）18 项；
  `ci-check` 八项全绿；policy/org parity 均 `POST-MIGRATION OK`。
- **未验证**：REST 在真实 WP 的鉴权/响应（本机无登录态）；消费方（branch/cert/mapp）尚未切到 manifest，下一单接入。

## 2026-11-14 · OA-2500：组织架构 O2a 字典数据契约化

- **契约**：主题 `inc/oa/contract-org-data.php`（P-8，version 1）唯一写口；
  白名单 4 键 → 既有 option（departments/duties/roles/data_model），未知键 fail-closed。
- **插件消费层** `includes/org/org-contract.php`：契约优先（`zw_oa_org_data_version >= 1`）；
  旧主题无契约 ⇒ 回落直读既有 option（过渡，不新增键、不改键名）。
- **收口**：`org-repository.php` 16 处 `get_option/update_option(ZW_ORG_*)` 全部改为
  `zw_oa_org_dict_get/save` / `zw_oa_org_data_model_get/save`；插件内直连 = 0。
- **边界**：`zw_org_person` CPT 仍由主题注册（数据面留主题）；人员 meta 写口属 O2b，另行评审。
- **回归**：`php tests/test-oa-org-contract.php` 13 项（旧主题回退 / 契约优先 / 源码零直连）；
  主题 `php tests/test-oa-org-data-proxy.php` 17 项。
- **未验证**：真实 WP 下 option 读写与 autoload 行为（本机无 WP 会话）；分支/证书仓切换契约消费未做。

## 2026-11-14 · OA-2501：组织架构 O2b 人员 meta 写口收口

- **契约**：主题 `inc/oa/contract-org-data.php` 的 `zw_oa_org_data_person_meta_set($person_id,$key,$value)`
  （TASK-2821）：只收 `zw_person_` 前缀、标量值、`person_id>0`、key ≤64；**主题未提供 delete 口**。
- **插件消费层**（`includes/org/org-contract.php`）：`zw_oa_org_person_meta_set/delete` 是插件内唯一允许
  写人员 meta 的入口 —— 契约在场（`function_exists`）走契约，否则回退 `update_post_meta/delete_post_meta`；
  回退分支保留同一套前缀/标量/ID 校验（fail-closed，非法输入零写入）。
- **收口**：`org-repository.php` 30 处 `update_post_meta(` + 1 处 `delete_post_meta(` 全量替换
  （含动态键 `zw_org_migration_set_identity()` 的 `$meta_key`）；文件内直连 = 0。
  未纳入本单：`zw_file_*` 等非人员命名空间 meta（`includes/files/`、`org-admin.php` 的照片文件反写）。
- **回归**：`php tests/test-oa-org-contract.php` 38 项（旧主题回退 / 契约优先 / 空串与 0 放行 /
  非前缀·数组·对象·非法 ID 拒绝 / 源码零直连）；`ci-check` 全绿。
- **未验证**：真实 WP 下写口与 autoload/meta 缓存行为（本机无 WP 会话）；主题 delete 契约未提供 ⇒
  删除路径当前恒走回退分支（后续主题补口即可自动切换，无需再改插件）。

## 2026-11-14 · OA-2502：0.1.27 发版（版本 bump + 冒烟扩面）

- **生产现状（发版前实测）**：`plugins/tcpc-oa` 与 `OA-2491`（`953c6ed`，2026-11-09）`git archive` **逐字节一致**；
  dev 领先 25 提交（OA-2490…2501），`VERSION` 同为 0.1.26 但内容不同 ⇒ 版本号不能当内容指纹，发版前必须比对构建。
- **版本口径**：OA 仓无原子升版入口（主题 `release-bump.sh` 只管主题三处）⇒ 本单按历史流程**三处同改**
  （`VERSION` / `tcpc-oa.php` 常量 / 插件头 `Version:`）+ 同一提交，`ci-check` 第②项校验三方一致。
- **冒烟扩面（TASK-2306 §10 G3）**：`plugin-switch-oa.sh` 原只验 `/login`、`/privacy-policy/`、`/bhzc` 哈希；
  新增 `wp eval-file --user=1` 结构冒烟：六菜单 slug（`zw-doc/zw-receipt/zw-org/zw-policy-platform/zw-org-files/zw-secretariat`）
  + 关键符号（发文页回调/条款读源/签署判定/`tcpc_oa_api_manifest`/组织写口/政策引擎）+ 三个新文件存在
  + manifest 11 符号与 version=1 + REST health 可路由 + `/userra/` 200。
- **判别力实测**：同一探针在旧构建（0.1.26/OA-2491）上判红，缺失项正是 `tcpc_oa_api_manifest`、
  `zw_oa_org_person_meta_set` 与三个新文件 ⇒ 冒烟能真正拦住「发了旧构建」。
- **注意**：菜单探针必须带 `--user=1`（CLI 用户 0 无 caps，菜单回调里的 `current_user_can` 会直接 return，
  造成「菜单缺失」假阴）；该结论已写进脚本注释。

## 2026-11-14 · OA-2502 事故：0.1.27 直发 fatal（已回滚）+ 发版通道加固

- **现象**：`plugin-switch-oa.sh` 交换目录后冒烟第一步 `wp plugin is-active` 即 fatal：
  `Cannot redeclare zw_policy_platform_cron_schedules()`（插件 `includes/policy/policy-platform-cron.php:172`
  与主题 `inc/policy-platform/policy-platform-cron.php:172` 同时声明）。原因：**在线主题是旧版**，
  `functions.php` 无条件 require 主题 policy 副本（没有「插件优先」守卫）；插件 0.1.27 的 policy 模块先加载后，
  主题副本再加载 ⇒ 同名函数重复声明。已用备份 `pre-switch-20261114-1244` 回滚：插件目录与备份逐字节一致、
  `TCPC_OA_VERSION=0.1.26`、`/`、`/login`、`/privacy-policy/`、`/userra/`、`/bhzc` 均 200、六后台菜单在位。
- **顺序铁律**：插件 policy 迁移版**必须主题先发**（或同一发布窗口且主题先行）。
  反向（新主题 + 旧插件）安全：旧插件缺的符号由主题副本补齐；新插件 + 旧主题 = fatal。
- **门禁沉淀（本单已是代码）**：`plugin-switch-oa.sh` 新增「②a 主题兼容门禁」——在任何生产写入前
  检查在线主题 `functions.php` 是否含 `function_exists('zw_policy_platform_cron_mask_webhook')`，
  缺失即拒发。判别力实测：生产旧主题=拒发、主题 dev=通过。
- **回滚缺陷修复**：`--rollback` 原从备份目录读 `VERSION`，但 `VERSION` 是 export-ignore（不随产物上线）
  ⇒ 读不到就把版本记录写成 `0.0.0`（等于对之后所有版本放开降级）。现改为解析备份 `tcpc-oa.php` 的
  `Version:` 头；仍取不到则**只解锁、不写版本记录**。
- **发版前必做的双重验证**：① 主题 9 个守卫哨兵在插件里全部有定义（policy-platform-data / legal-admin-bridge /
  cron / consent / consent-gate / consent-notify / legal-text-source / module-map / rule-engine —— 本单已逐项 grep 通过）；
  ② 在线主题版本必须 ≥ 含守卫的构建（门禁自动判）。

## 2026-11-14 · OA-2503：分步发版路径**实测否决**（主题必须真先发）

- **设想**：OA-2502 直发 fatal（新插件 policy 7 文件 + 旧主题无限时副本）。曾设想「先把
  `tcpc_oa_enable_policy` 置 0 ⇒ 插件不装载 policy 文件 ⇒ 先发插件，主题发版后再开开关」。
- **实测否决**：置 0 后**policy 后台菜单/页面立即消失**——主题侧 `inc/admin/policy-platform-admin.php`
  是 shim（存在权在插件，「插件启用才出菜单，插件缺/停用零菜单 + 提示」），主题副本并不接回后台。
  这不是「等价降级」，而是功能缺失 ⇒ 该路径在生产不可接受，已回滚（0.1.27 → 0.1.26、开关回 1）。
- **结论**：policy 迁移版**必须主题先发**（主题带 9 个 `function_exists` 条件加载守卫），再发插件；
  门禁保持 fail-closed（主题缺守卫即拒发），**不保留**「关模块即放行」的口子（并有回归钉住它不复活）。
- **同批修**：发版冒烟把 manifest 版本键从 `version` 改为真实键 `api_version`（否则恒误报「版本不是 1」）；
  回滚通道读 `VERSION` 前先判文件存在（该文件 export-ignore，备份里没有）。

## 2026-11-14 · OA-2504：文档纠偏 + parity doc/secretariat shim 登记

- **文档纠偏（事实）**：本仓**早在生产运行**（0.1.27 / 主题 1.2.5 / 2026-11-14），
  README 与 AGENTS 里「骨架期 / 尚未部署 / 生产 plugins 下无本插件 / load-list enabled=false」均已过时，
  会误导后续会话做出错误判断（例：把「未部署」当回滚依据）。已按实测改写，并补「发版顺序（主题先发）+ 监控/健康检查」。
- **parity shim 登记**：`verify-module-parity.sh` 的 doc / secretariat 两个 module 行 shim 字段原为空
  ⇒ `--expect-theme-absent` 直接 fail-closed（无法判定「主题侧只允许剩 shim」）。
  已登记 `doc-bootstrap.php` / `secretariat-bootstrap.php`，并补齐两模块的 27 / 7 条 `require` 清单；
  同时退役 `doc-appoint-cert.php` 的旧 allow 行（主题侧副本已删，保留会触发「陈旧豁免」判红）。
- **结果**：六模块 post-migration 全绿（doc 27 / receipt 18 / files 2 / org 6 / secretariat 7 / policy 14 文件齐全，
  主题侧仅剩 shim）。
- **保留的提醒**：`list`（迁移前）模式对 doc 会报 `doc-seal.php` missing——那是「主题未迁移」的旧口径，
  迁移完成后一律用 `--expect-theme-absent`；不要据此误判为缺口。

## 2026-11-14 · OA-2505：REST 只读端点补全（legal/{doc} · doc/inbox · files/{id}）

- **范围**：在 `tcpc-oa/v1` 下新增三条 **GET 只读**路由（`read` 能力位）：
  `legal/{doc}`（当前生效条款）、`doc/inbox`（当前用户收文）、`files/{id}`（文件元数据）。
  **`policy/check` 不提供 REST**：需要结构化上下文，用 GET 伪装「读取」会让调用方误以为无副作用；
  继续走 PHP 符号契约 `tcpc_oa_api_call('policy.check', …)`（manifest 的 `rest` 段仍只允许 GET，门禁不变）。
- **降级语义（与 manifest 对齐）**：legal 读源缺失 ⇒ 503 `LEGAL_UNAVAILABLE`；inbox 查询器缺失 ⇒
  200 + `available=false` + 空列表（degrade=empty）；files 存储契约缺失 ⇒ 503 `STORAGE_UNAVAILABLE`（degrade=deny）。
- **越权与脱敏**：files 端点只回白名单（id/title/mime/size/kind/scope/exists），**绝不返回 path/url**；
  非本人且非管理员 ⇒ 403；未登录 401。legal 只回 `visibility=public`；`bhzc` 直接 404（铁律 19）。
- **回归**：`php tests/test-oa-api-rest-endpoints.php` **25 项**（路由登记/400/401/403/404/503/降级/脱敏/子进程）；
  `php tests/test-oa-api-manifest.php` 扩到 **32 项**（含三条新路由 + files 函数体不含 path/url/owner 输出）。
- **未验证**：真实 WP 下的路由鉴权与 mapp 消费方联调（本机无登录态）；`files` 的团队/部门级可见性目前
  只做「本人或管理员」，更细的 RBAC 待 C2 消费者接入时按需扩。

## 2026-11-14 · OA-2506：0.1.28 发版（REST 只读端点上生产）

- **版本**：0.1.27 → **0.1.28**（三处同改 + CHANGELOG 段），只含 OA-2505 的 REST 端点；
  主题侧本轮无改动（1.2.5 已含守卫），故插件可单发。
- **冒烟扩面**：结构冒烟新增「`/tcpc-oa/v1/legal/`、`/doc/inbox`、`/files/` 三条路由必须真实注册」
  （用 `rest_get_server()->get_routes()` 校验），治「manifest 写了但忘了 register_rest_route」的静默漂移；
  `tests/test-release-lock.sh` 扩到 41 项。
- **发版顺序不变**：主题 1.2.5 已在生产且含插件优先守卫 ⇒ 插件门禁自动放行。
- **工具纪律自报**：本单滑点 2 处（python 内联改三处版本行；sed -i 改记忆措辞）⇒ `pitfalls.py bump PIT-012` 两次（→221）。

## 2026-11-14 · OA-2507：原子升版入口 release-bump-oa.sh

- **问题**：历史发版（0.1.26→0.1.27→0.1.28）都是手工三处同改（`VERSION` / 插件头 / `TCPC_OA_VERSION`），
  漏改只能靠 `ci-check` ②事后发现，且无回读。
- **口径**：`scripts/release-bump-oa.sh patch|minor|major "说明"` —— 升版前三方一致性校验 →
  计算下一版本 → 三处原子同升（临时文件 + mv）→ 落盘回读；任一失败**零落盘**；stdout 单行=新版本号。
  有 git 工作时拒绝带未提交改动升版；`ZW_OA_REPO_ROOT` 为夹具接缝（供回归用，不碰真实版本）。
- **回归**：`bash tests/test-release-bump-oa.sh` 14 项（patch/minor/major 进位、三处一致、不一致 rc=1 且零改动、
  用法错误 rc=2、语法）。
- **边界**：脚本只管「升版」，发布仍走 `plugin-switch-oa.sh`（版本三方 + 主题兼容 + 结构冒烟 + 回滚）。

- **工具纪律自报**：本单滑点 4 处（python 内联改 README 版本段、台账范围行、记忆；sed -i 改记忆）⇒ `pitfalls.py bump PIT-012` ×4（→228）。

## 2026-11-14 · OA-2508：健康检查与三类告警（health-oa.sh）

- **入口**：`scripts/health-oa.sh`（只读；`--json` / `--quiet` / `--no-alert` / `--self-test`），
  9 项检查 → 9 类告警：`PLUGIN_MISSING` / `VERSION_DRIFT` / `MENU_MISSING` / `SYMBOL_MISSING` /
  `REST_DOWN` / `PUBLIC_URL_DOWN` / `BHZC_CHANGED` / `RELEASE_DRIFT` / `CRON_MISSING`。
- **判据来源**：插件 active + 版本三方、六菜单（admin 身份触发 `admin_menu`）、6 个关键符号、
  `GET /tcpc-oa/v1/health`、四个公开页 200、`/bhzc` 正文 md5 与基线（首次自动建基线
  `/root/tcpc-release-locks/oa-bhzc.sha256`）、发版记录 vs 生产常量、5 个政策/条款 cron 事件。
- **只读边界**：不写 WordPress 数据、不改插件目录；唯一写盘是首次建立 `/bhzc` 基线。
- **告警通道**：`ZW_OA_ALERT_CMD`（stdin 收告警正文，可接 mail / webhook）；未设置只打印 `ALERT|<TYPE>|`。
- **接入文档**：`docs/ops/health-monitoring.md`（cron / systemd timer 两种接法 + 通道示例）。
- **回归**：`bash tests/test-health-oa.sh` 25 项（self-test 11 项判别力 + CLI 契约 + 9 检查/9 告警齐全 +
  只读静态断言 + `--json` 可解析）；真实生产巡检 9/9 绿（rc=0）。

## 2026-11-14 · OA-2509：文档纠偏到生产现状（0.1.28 / 主题 1.2.6 / 已全量迁入）

- **三处过期纠正**：README「已上线 0.1.27」→ `0.1.28`（并注明主题 `1.2.6`）；AGENTS「当前阶段」删
  「主题回退副本清理 / 门禁翻强制 / 消费者接线仍剩余」→ 明确 P0–P6 **已全部完成**、残余为按需项；
  契约首段「骨架期 / 本仓尚未迁入业务代码」→ 「已全量迁入并在生产运行」，并写明主题侧只留 shim/RBAC/回退副本。
- **指针纠正**：契约里 `docs/ops/oa-api-registry.yml`（不存在）→ `oa-api-registry.json`（机器读）/
  `oa-api-registry.md`（人读）。
- **纪律**：文档版本号随生产走；每次发版后 README/AGENTS/契约首段同批复核（本次即因 0.1.28 发版未同批纠偏而返工）。

## 2026-11-14 · OA-2510：政策正文纯文本长度剔除 <style>/<script>（与主题门禁同口径）

- **事实**：`tcpc_oa_legal_content_plain_length()` 用 `strip_tags()` 计字数，`<style>` 内容被当正文
  ⇒ 隐私政策显示 22051 字（真实可读 15395），文档中心保存 v13 时会以「22143 > 22100」误拒。
- **修法**：先 `preg_replace('@<(script|style)[^>]*?>.*?</\1>@si','')`，再 strip_tags + `html_entity_decode`
  + 折叠空白；`max_length` 22100 与必备条款/禁用词规则不变。
- **判据**：`tests/test-oa-legal-content-rules.php` 新增 3c 段（含大段 CSS 仍 ok、plainLength 只算正文、
  含/不含 CSS 口径一致）。
- **可复用**：与主题 `scripts/privacy-policy-content-check.php` 必须保持同一口径；改一处必须同批改另一处，
  否则「后台能存、仓库门禁判红」或反之。

## 2026-11-15 · 处置清册口径（OA-2511，载体单 TASK-2866）

- 销毁清册/清退清单都是**只读打印**：`admin-post` + `tcpc_perm_doc` + 按类型绑定 nonce；不写任何表。
- 清册页脚固定声明「系统未删除任何文件或记录」——与 OA-2483 的红线一致（销毁只写状态，物理删除线下做）。
- 销毁清册带「按年度小计」，便于纸质归档核对。

## 2026-11-17 · OA-2512 记忆覆盖回写：14 个已并入 dev 但本层记忆从未提及的单

- **为什么补**：同 BRANCH-0545（交接单 AGENT-0521 §5 ⑥ 点名 branch/oa 两仓）——只补可追溯性，
  不重写历史段、不复述技术细节（细节看提交）。
- **盘点口径（可复跑）**：
  ```bash
  d=/srv/git/worktrees/dev-tcpc-oa/main; mem=$d/docs/development-long-term-memory.md
  git -C $d log dev --merges --format='%s' | grep -oE '\bOA-[0-9]+\b' | sort -u | while read -r id; do
    grep -q "$id" "$mem" || echo "$id"
  done
  ```
  判据：合并提交里的单号集合 减 记忆正文里的单号集合。本单前 = 14，之后 = 0。
- **补记清单**：

| 单号 | 合并提交 | 内容 |
| --- | --- | --- |
| OA-2497 | `40e9626` | OA-2497 接入铁律 33 界面文案门禁（ux-guard 唯一判据 + 本仓可见面清单） |
| OA-2436 | `4ab31cb` | 豁免命中时 summary 不再谎称逐字节一致（随 OA-2434 合并） |
| OA-2437 | `58ffb39` | OA-2437 为 org/files 登记主题侧 shim + 插件齐全性清单（R8-1） |
| OA-2445 | `d4d55b4` | OA-2445 tcpc-oa 0.1.2（发文模块进仓，钥匙默认关） |
| OA-2444 | `cfe4ce2` | OA-2444 发文后台 9 页渲染冒烟脚本（启用前置） |
| OA-2443 | `9e225c8` | OA-2443 迁入最后一文件并改走实名客户端契约（发文 27/27 完成） |
| OA-2442 | `b458d3b` | OA-2442 迁入发文余批 14 文件（12,236 行，DIFF=0，未启用） |
| OA-2441 | `a3ce532` | OA-2441 迁入发文编辑器 5 文件（9,032 行，DIFF=0，未启用） |
| OA-2440 | `8bb63da` | OA-2440 迁入发文页面层 3 文件（4,991 行，DIFF=0，未启用） |
| OA-2433 | `82b9451` | OA-2433 迁入网盘文件中心 UI 2 文件（1,762 行，DIFF=0，未启用） |
| OA-2432 | `702a048` | OA-2432 迁入 org 业务 5 文件（4,413 行，DIFF=0，未启用） |
| OA-2428 | `197a1b8` | OA-2428 preflight 支持「主题载体单」接缝（解 OA 轮开工阻塞） |
| OA-2419 | `7f73d40` | OA-2419 parity 口径收窄 + policy 装载 7 文件（R3-2） |
| OA-2418 | `626e5dd` | OA-2418 模块 parity 能力扩展（文件清单模式 + --exclude + 按模块 shim） |
| OA-0001 | `507fc0d` | OA-0001 CHANGELOG 记录分仓号段门禁（与 cert 仓同构） |

## 2026-11-18 · OA-2513：公文标题手动换行失效 —— `normalize()` 幂等契约覆盖 `meta.title_lines`（载体单 TASK-2999）

- **症状**：新建发文填写公文标题时回车断行，撰写页右侧内嵌预览**显示**断行，但保存后详情页/打印/DOCX
  一律按自动回行（TASK-1342 梯形/菱形）排，编辑态回填也只剩单行。生产实证：33351（中传函〔2026〕98号）
  标题断点处残留 U+0020、`"title_lines":[]`；全库 495 篇 / 正文模型 10 篇 / 该键非空 **0** 篇。
- **不是「公文版式」设置冲突**：`title.wrap_chars` / `title.wrap_mode` / `title.wrap_min_chars` 只在
  `zw_doc_title_resolve_lines()` 的「自动回行」分支被读；手动优先（SPEC-87 §15.9）实现本来就对。
  排障顺序应先查**数据是否真的落库**，再查渲染优先级 —— 这次是前者。
- **根因（可复现的口径）**：`zw_doc_body_normalize()` 第一遍把断行写成「`meta.title` 单行（空格拼回）+
  `title_lines` 数组」，第二遍再归一时 `meta.title` 已无 `\n` ⇒ 旧实现无条件 `title_lines = []`。
  而**四处都在再归一**：`zw_doc_update_body()`、`zw_doc_create_document()`（写）、
  `zw_doc_body_parse()`、`zw_doc_body_to_json()`（读/序列化）。撰写页预览只用第一遍结果、不落库 ⇒ 预览对、保存错。
- **结论口径（后续新 meta 键必看）**：**任何新增的受控数组/结构化 meta 键，都必须同时满足「专用分支
  （不进纯文本清洗）」与「`normalize()` 幂等」两条**，否则会被写读链路上的再归一静默改写。现仓内符合此
  口径的是 `appointments` 与 `title_lines` 两个键；新增第三个键时照此办理，并在 `tests/` 补一条
  `normalize(normalize(x)) === normalize(x)` 断言（比单遍断言更能抓住这类缺陷）。
- **回归**：`php tests/test-doc-title-lines.php`（15 项；对修复前代码 9 项判红，含落库/读回与反向控制）。
- **不要做的事**：不要靠调 `title.wrap_chars` 去「凑」手动断点 —— 那是全局固定每行字数，会连带改掉
  所有公文标题的断行；也不要把手动断行存成 `\n` 塞进 `meta.title`（列表/检索/文件名口径会一起变）。

## 2026-11-18 · OA-2514：新建发文补「附注」「签发人」——渲染层有能力 ≠ 撰写页有入口（载体单 TASK-3000）

- **症状**：新建发文的公文永远没有附注、上行文永远没有签发人；而打印/DOCX 早就能渲染这两项。
- **根因（两类断点，缺一不可）**：
  1. **表单没有控件** —— 撰写页从未输出 `zwc_note` / `zwc_signer`（`zw_doc_composer_body_from_post()`
     里 `'note' => $get('zwc_note','')` 读的是一个**根本不存在的字段**，于是永远空串）；
  2. **meta 映射漏键** —— 白名单 `zw_doc_body_meta_keys()` 里一直有 `signer`，但 `body_from_post()`
     的 meta 数组**没有 `signer` 这一行** ⇒ 即使表单加了字段也进不了库。本次两处同批补。
- **口径（排查这类缺口的方法）**：审计「国标要素是否落地」要**分三层各查一遍**，只看一层会漏：
  ① 白名单（`zw_doc_body_meta_keys()`）② 写入映射（`body_from_post()` / 导入路径）③ 录入控件（撰写页 DOM）。
  任何一层缺失都表现为「纸面永远空」，且不会报错、不会留日志 —— 属**静默缺失**，只能靠逐项对表发现。
  同类已登记的还有：附件文件不排纸面（设计取舍，见 `doc-composer.php` 附件区块提示）。
- **这次顺带确认的既有能力（不要重复实现）**：附注的圆括号与左空二字、签发人的「签发人：」冒号与
  文号/签发人同行（文号左空一字、签发人右空一字）都在渲染层，且有版式开关 `header.show_signer`；
  写作侧只需把值送进 meta。
- **回归**：`php tests/test-doc-note-signer.php`（16 项；对修复前代码 6 项判红）。
- **仍未做的（留待后续单）**：签发人未按文种自动判定「上行文」并提示/必填；台账快速新建表单未同步该两项。

## 2026-11-18 · OA-2515：附件说明国标化 —— 「附件：」/序号由系统生成 + 悬挂对齐 + 条数提示（载体单 TASK-3001）

- **症状**：附件说明的「附件：」和序号全靠作者手打 ⇒ 漏打就少「附件：」、手打跳号就与附件清单对不上；
  多条附件之间会**多出整行空白**（旧 CSS 每行都吃 `margin-top`）；名称较长回行时**顶到版心左缘**，不与名称首字对齐。
- **口径（唯一出处）**：`zw_doc_body_attach_plan($notes)`（`doc-body-model.php`，WP-free、三通道共用）：
  逐行剥掉手打的「附件：」「N.」「N、」→ 按位置**重新连续编号** → 输出 `mark / name / prefix_chars`。
  - 单条：`附件：名称`（GB 明确单附件**不加**序号）；
  - 多条：第 1 条 `附件：1.`，第 2 条起用**全角空格 ×3** 补足「附件：」宽度 ⇒ 各条序号左缘对齐；
  - 悬挂缩进字符数 `prefix_chars` = 3（「附件：」）+ 序号折算宽，DOCX 用
    `left_chars = 左空二字 + prefix_chars` 与 `indent_chars = -prefix_chars`（**负值 = 悬挂**）表达。
- **两通道实现差异（记住这条，别各写一套）**：HTML 用 `mark + name` 两段 **flex**
  （`display:flex` + `.tco-doc-attach-name{flex:1 1 auto}`）⇒ 回行自动落在名称列内，**不依赖字体度量**；
  DOCX 只能靠字符数折算的悬挂缩进 ⇒ 是**近似**（字体度量参与时会差半字，可接受）。
- **踩过的坑**：① 样式块由 `zw_doc_render_style_block()` 单独输出，`zw_doc_render_document_html()` 的返回值里**没有** `<style>`，
  断言 CSS 要去取那个函数；② `zw_doc_docx_body_xml($body, $args)` 第一参是**整份 AST**，不是 `meta`（传错只会得到空串）。
- **一致性校验**：建单路径比对「`meta.attach_note` 条数 ⇄ `attachment_ids` 数」，不一致给**可改正**提示（不阻断）。
  附件文件本身仍**不排纸面**（既有设计取舍，撰写页有明示文案）—— 本条只保证「说明」与「实体」数量不乱。
- **回归**：`php tests/test-doc-attach-note-gb.php`（28 项；撤掉两通道渲染改动后 10+ 项判红）。
- **仍欠**：附件说明条数与**名称**的一致性（只比条数，不比名称）尚未校验；附件另面编排（GB §7.3.7）仍是取舍项。

## 2026-11-18 · OA-2516：分页估算补标题间距 + 抄送悬挂 + 字重跟随版式（载体单 TASK-3002）

- **三条口径（都在 `doc-render-html.php`）**：
  1. **分页与 CSS 必须同源**：`.tco-doc-title` 的 `margin` 用了 `title.gap_below_rule_lines` 与
     `title.gap_below_title_lines`，那 `zw_doc_render_paginate()` 的首页占用就必须把这两个键算进去
     （无主送才计下间距；有主送时该间距由主送自身承担，重复计会反向多扣 1 行）。**改任何纸面间距时，
     务必同步分页估算** —— 这类"CSS 加了、估算没加"的偏差不会报错，只会表现为「末页/版记被顶出纸面」。
  2. **版式页可配的字段不得在渲染层写死**（本次 h1/title/mark 的 `font-weight:bold` 就是反面教材：
     设置项存在、改了不生效）。判据：版式登记表里每个 `path` 都要能在渲染输出里找到对应的取值调用。
  3. 抄送回行对齐用「左内边距 3em + 首行 -3em」的经典悬挂（「抄送：」= 3 字宽），不需要拆 DOM。
- **测试手法（值得复用）**：分页这种"内部数值"没有对外读数，用**差分法**证明接线 ——
  同内容、只改两个 `gap_*`（2 vs 0），断言首页块数差恰为 4；修复前差值为 0。比断言具体行数更稳。
- **顺带修掉的自伤**：OA-2515 的附件渲染直接读 `$meta['attach_note']` ⇒ 无附件说明的公文会抛
  「Undefined array key」Warning；已改为先 `isset` 再取（渲染通道必须零 Warning/Notice）。
- **回归**：`php tests/test-doc-layout-paginate-weight.php`（11 项；撤掉本次渲染改动后 6 项判红）。
- **仍未做**：`fonts.h1.weight` 默认仍是「加粗」，黑体本身即粗面 ⇒ 观感偏重（双重加粗），
  是否把默认改「常规」需用户拍板（改默认属版式口径变更，不在本单范围）。

## 2026-11-18 · OA-2517：「标题上/下间距」进公文版式页（载体/规范任务 TASK-3003）

- **症状**：规范写「红线下空二行」，纸面实际是 1 行；而「公文版式」页**找不到这个设置**，
  于是每次审计都会把它当缺陷重报一遍，谁也说不清 1 行为什么是现状。
- **真因（第三类断点：表里有键、页面无入口）**：`zw_doc_gb9704_spec()` 里早有
  `title.gap_below_rule_lines` / `title.gap_below_title_lines`，渲染 CSS 与分页估算都在读它们，
  但 `zw_doc_layout_fields()`（版式页字段表）**从未列过这两个 path** ⇒ 无入口、不可调。
- **口径（新增排查维度）**：审计「设置为什么不生效 / 为什么改不了」要分**三种**，以前只盯前两种：
  ① 有页面、没接线（渲染层写死）—— 见 OA-2516 的 `font-weight:bold`；
  ② 有字段、没落库（meta 映射缺键）—— 见 OA-2514 的 `signer`；
  ③ **有键、没页面**（字段表漏登记）—— 就是本单。判据：版式规范里出现的每个 `path`，
  都必须在 `zw_doc_layout_fields()` 找得到，否则用户只能改代码。
- **默认值策略**：本次**不动默认**（仍 1 行），只把「国标 2 行 / 协会口径 1 行」写进字段说明与规范
  （主题仓 CHG-499 / 版式规范 v1.77）—— 改默认属业务口径变更，须用户拍板。
- **回归**：`php tests/test-doc-layout-title-gap.php`（16 项；撤掉字段登记后 13 项判红），
  其中包含「覆盖层把上间距设 2 行 ⇒ CSS 由 28pt 变 56pt」与「分页首页块数差 4」，证明不是只加了输入框。

## 2026-11-18 · OA-2518：详情页预览统一为 PDF 阅读器式 + 打印对齐（载体单 TASK-3004）

- **诊断顺序（重要）**：先分清「纸面参数」与「呈现层」——本例纸面**本来就同源**（预览与打印都走
  `zw_doc_build_render_args()` + `zw_doc_render_document_html()`），不齐的是**呈现**：
  详情页用 `transform:scale(62%)` 塞进固定单页高盒子，而打印是 100% A4 多页 + 自动弹框。
  ⇒ 排查「预览和打印不一样」先看**缩放/分页/容器**，别急着怀疑版式参数。
- **技术口径**：`transform:scale()` **只影响视觉、不改布局高度**。所以「缩放预览」的外框高度必须按
  缩放后尺寸 × **页数** 计算，或者干脆不要自己算 —— 用 iframe 承载整页 + fit 脚本（按宽等比、逐页绝对定位），
  这也是本次采用的做法（与撰写页、版式设计器共用 `zw_doc_preview_fit_script()`）。
- **最小改动路子**：不新开预览端点，给既有打印端点加 `embed=1`（`show_toolbar/auto_print` 关掉 +
  注入 fit 脚本），鉴权沿用同一 `zw_doc_print_<id>` nonce ⇒ 零新增鉴权面。
- **后台页误打印**：任何「后台页里嵌公文件预览」的页面都要加 `@media print` 防护，否则 Ctrl+P 会把
  卡片/按钮一起打出来，用户会直接判定「打印和预览完全不一样」。
- **回归**：`php tests/test-doc-preview-print-parity.php`（22 项；撤回改动 5 项判红），
  含「旧函数已删除」「embed 走同一端点同一 nonce」「fit 脚本注入在 </body> 前」「@media print 隐藏后台」。

## 2026-11-18 · OA-2519：详情页流程可读化（步骤条 + 下一步 + 全量锚点，载体单 TASK-3005）

- **口径（重要，避免下次"顺手重排"）**：详情页「完成文件在法务/用印之前」是 **TASK-1382 ③ 的既定决策**
  （当时用户要求首屏能看到已完成文件）—— 它是**顺序决策**，不是错序缺陷。用户后来觉得"流程混乱"，
  根因是**页面从未说明真实顺序与前置关系**（锚点也只覆盖 4 个区块）。
  ⇒ 正确做法是**显式化**（步骤条 + 下一步 + 全量锚点），而不是推翻既定 IA 搬卡片。
  下次再遇到"顺序看着乱"的报障，先查**这条顺序是不是某单的拍板结论**（台账 grep `id="tco-doc-`），再决定是改顺序还是加说明。
- **实现要点**：步骤条状态由既有唯一取值派生 —— 审核看 `$doc['status']`、法务看 `zw_doc_legal_status()`、
  用印看 `$doc['seal_mode']==='formal'`、完成文件看 `zw_doc_pdf_versions()` 是否非空（**不新造字段**，
  本次曾误用不存在的 `has_pdf` 键，已改为既有取值）。
- **锚点死链**：沿用 TASK-1412 的「逐项按目标存在性渲染」；并给「流转记录」补 `id="tco-doc-events"`，
  「版式预览」用 OA-2518 已加的 `id="tco-doc-preview"`。
- **回归**：`php tests/test-doc-detail-flow-nav.php`（31 项；撤回改动 18 项判红），
  含「步骤顺序」「四条状态分支」「锚点 ↔ id 一一对应」「不搬动卡片」四类守卫。
- **工具纪律自报**：本单中途用内联 `python` 改过一次 `doc-admin-list.php`（替换 `has_pdf` + 给 h2 加 id），
  属 PIT-012 滑点；已在台账如实登记并 bump，后续一律 `edit`。

## 2026-11-18 · OA-2520：发版 0.1.30（近六单上线）

- **做了什么**：`release-bump-oa.sh patch` 原子升版 0.1.29 → **0.1.30**（`VERSION` / `TCPC_OA_VERSION` /
  插件头 `Version:` 三处同升），CHANGELOG 记 `## [0.1.30] - 2026-11-18`，随后 `plugin-switch-oa.sh` 切生产 + 冒烟，
  并推送 origin / github（只快进）。
- **本次上线六单**：OA-2514（附注/签发人）· OA-2515（附件说明国标化）· OA-2516（分页/抄送/字重）·
  OA-2517（标题上下间距可配）· OA-2518（详情页预览统一 + 打印对齐）· OA-2519（详情页流程可读化）。
- **发版口径提醒**：本仓升版脚本**不写台账**（脚本头注「台账留痕由调用方负责」）——
  必须在主题台账的本单条目不写漏 `- 版本槽位：x.y.z（预占）`，否则主题收口的版本槽位判据会红。
- **顺序**：先 ci-check 八项 → 插件侧 finish → 主题侧 finish → dev-merge（主题载体 + 插件各一次）→
  `plugin-switch-oa.sh --dry-run` 看计划 → 正式切换（含备份与冒烟）→ `sync-remotes.sh --apply`。

## 2026-11-18 · OA-2521：配置漂移门禁 + 四条字典登记纠错 + 两条死配置接线（载体单 TASK-3007）

- **为什么要有门禁**：2026-11-18 人工盘点一次就查出「字典登记与源码不符」4 处（标题/附件说明是 textarea 却写
  input、页码是 select 却写 radio、两处 reader 指向已删除的 `zw_doc_render_html()`、密级 reader 指向
  **从未存在**的 `zw_doc_composer_secret_split()`）+「版式页有入口但 0 处读取」2 处
  （`sign.date_no_leading_zero`、`footer.default_mode`）。这类漂移**不报错、不告警**，只让改配置的人以为生效、
  让读字典的人以为那样实现 ⇒ 必须有判据。
- **门禁唯一出处** `scripts/config-drift-gate.py`（ci-check 第 ⑨ 项）：判据 1 字典 control/reader ⇄ 源码；
  判据 2 版式页 63 个 path ⇄ 渲染通道。**豁免只降不升**（`scripts/config-drift-allow.txt`，理由 ≥8 字，
  且「源码已能命中的陈旧豁免」同样判红）——现状 **0 条豁免**。
- **两个实现坑（都踩过，写下来别再犯）**：
  1. 判据 1 若直接在被注释掉的代码里找 `name="xxx"`，**注释写一句就能骗过门禁**（夹具实测）⇒ 匹配前先剔除整行注释；
  2. 动态拼键（`'fonts.' . $slot . '.weight'`）静态命不中整键 ⇒ 门禁按「前缀.*.后缀」模式识别，
     而不是给它加一条豁免（豁免是给"说不清"的情况，不是给"能识别"的情况）。
- **两条死配置选择"接线"而不是"摘掉"**：`sign.date_no_leading_zero` → `zw_doc_iso_to_cn_date()`（默认不补零=国标）；
  `footer.default_mode` → 撰写页默认选中 + 渲染层回落（非法值回落 standard）。判据只覆盖"版式页可配键"，
  **表里有、页面没入口**的键（如旧的 `footer.default_mode`）门禁看不见，仍需人工抽查。
- **注意**：页码只在**多页**时输出（单页自动不编页码，TASK-1340），所以验证页码默认模式必须造多页夹具。
- **回归**：`php tests/test-config-drift-gate.php`（12 项：本仓通过 + `--root` 夹具判红 + 两条接线行为），
  撤回源码改动 → 门禁 6 处判红、测试 4 项判红。
- **工具纪律自报**：本单有 3 处内联 `python` 改写既有文件（字典 4 行、render/composer 接线、测试夹具补丁），
  属 PIT-012 滑点，已登记并 bump。

## 2026-11-18 · OA-2522：冗余字段收口（doc_type 统一 / 密级期限结构化 / 留痕可见）（载体单 TASK-3010）

- **`meta.doc_type` 的真相**：只有导入路径写，线上新建不写；但 `zw_doc_appoint_doc_type()` **有文号兜底**
  （为空 ⇒ 由文号派生）⇒ 这不是功能缺陷，而是**两个入口写法不一致**，排查时极易被误判成「线上任命不同步」。
  本单让线上也写（取文种简称，与导入件同口径），兜底逻辑不动。
- **合成串改结构化的兼容读法（可复用）**：**双写 + 读侧优先新键 + 回落旧串**——
  `meta.secret`（合成串）继续承担纸面渲染，新增 `secret_level` / `secret_period` 只承担回填与单改；
  这样存量零迁移、纸面零变化，且不再需要「改一项要整串重算」。**不要**反过来只存结构化再拼串渲染，
  那会把纸面口径绑死在两个字段上。
- **「写而不读等于没留痕」**：`_zw_doc_legal_clause_*`（法务取证）与 `_zw_doc_edit_unlocked_*`（同版本解锁）
  一直只写不读。留痕字段的判据不是"写了"，而是**有人在需要的时候看得见**（解锁记录尤其重要：
  它决定「已用印还能改正文」是否合规）。本次在详情页对应卡片就地展示，且**无数据不输出空壳**。
- **新增 meta 键的两件必做事**：① 加进 `zw_doc_body_meta_keys()` 白名单；② 数据字典补/改登记行——
  否则 OA-2521 的 `config-drift-gate` 会把它当新的漂移（本次两件都做了，门禁保持绿）。
- **回归**：`php tests/test-doc-field-consistency.php`（18 项；撤回源码改动 15 项判红）。
- **仍未做（明确留白）**：`_zw_doc_appoint_*` 三个任命归档留痕键、`_zw_doc_pdf_updated_at` 仍只写不读；
  是否展示/停写留待下次按同样口径处理。
## 2026-11-18 · OA-2523：详情页「下一步」死锚点（$zw_next 缺目标存在性守卫）

- **TASK-1412 的守卫只做了一半**：`$ov_jump`（快速跳转）逐项按 `$ov_has_*_card` 渲染，但
  `$zw_next`（下一步主行动）是**先算后用**、不看卡片是否存在 ⇒ 正文为空的记录会输出
  `href="#tco-doc-legal"`，而法务卡渲染条件是 `!empty($doc['body'])` ⇒ 点了没反应。
  修法：`$zw_next_targets` 白名单（`#tco-doc-face`/`#tco-doc-flow` 常驻，其余按 `$ov_has_*_card`），
  目标不存在时回落「纸面要素」并说明「先补正文」。
- **这类缺陷只有运行时渲染断言抓得到**：本仓 `tests/test-doc-detail-flow-nav.php` 是**静态 grep**（全绿），
  主题仓 `tests/test-doc-detail-anchors.php` 用桩渲染整页才暴露。跨仓契约测试在这里是有效补充，
  不要因为「OA 自家测试绿了」就认为详情页没有死锚点。
- **给后续单的口径**：任何新增/改动的「下一步 / 快速跳转 / 锚点链接」，都必须① 与目标卡片渲染门控逐字对齐，
  ② 有回落目标（常驻卡），③ 至少被一条**运行时**断言覆盖（静态 grep 不算）。

## 2026-11-18 · OA-2524：发版 0.1.31（配置漂移门禁 + 冗余字段收口 + 下一步守卫）

- 0.1.30 → **0.1.31**（`release-bump-oa.sh patch`，三处同升），上线 0.1.30 之后全部 dev 提交：
  OA-2521（config-drift-gate 接入 ci-check ⑨ + 字典四处纠错 + 两条死配置接线）、
  OA-2522（doc_type 线上写入 + 密级期限结构化 + 法务取证/解锁留痕可见）、
  OA-2523（并发会话：详情页下一步按目标卡片存在性守卫）。
- 发版顺序仍按上次踩过的路径执行：**先收口（跳过主题侧回读）→ 合并载体 → 合并插件 → 切生产 → 复核**。
  原因：`release-readback-check.sh` 只比对**主题**生产版本，与插件发版无关（主题 1.4.13 未发布属他单在途）。
- 生产切换证据由 `plugin-switch-oa.sh` 自带（运行时常量 / 六菜单 / REST / 结构冒烟 / /bhzc 正文哈希不变）。

## 2026-11-18 · OA-2525：任命归档留痕可见化（B2，载体单 TASK-3014）

- **症状**：任命公文的证书记录归档失败时，详情页「任命同步状态」卡只看
  `zw_doc_appoint_sync_status()`，而 `doc-appoint-cert.php` 写的三条留痕
  （`_zw_doc_appoint_doc_media` 媒体 URL 数组、`_zw_doc_appoint_archive_error` 归档问题、
  `_zw_doc_appoint_media_error` 媒体问题）**页面上完全看不到** ⇒ 运维无法区分
  「归档失败」与「证书图片（媒体）失败」，只能翻库。
- **口径**：留痕字段的判据是**在需要时看得见**（同 OA-2522 已立的规矩）。本次在对应卡片内补一行
  「归档留痕：媒体 N 份｜归档问题：…｜媒体问题：…」，三项全空则整段不输出（不留空壳）；
  媒体列表按 JSON 解析后只显示**份数**（不把 URL 怼到页面，避免泄露路径）。
- **不动**：同步状态判定、归档写入逻辑与去重键、`_zw_doc_pdf_updated_at`（与完成文件版本表
  「生成时间」同信息冗余，仍不展示）。
- **回归**：`php tests/test-doc-appoint-trace-visible.php`（13 项；撤回改动 10 项判红）。

## 2026-11-18 · OA-2526：附件说明名称 ⇄ 附件文件名软匹配（B3，载体单 TASK-3016）

- **口径（软匹配，别再改成硬匹配）**：`zw_doc_attach_name_mismatch()`（`doc-body-model.php`，纯函数）——
  双方归一化（**去扩展名**（只去 ≤5 位字母数字的末段，避免吃掉「1.预算」的点）/ 去手打「附件：」「序号」/
  去空白与 `_ - — · ． .` 分隔符 / ASCII 小写）后**双向包含即算一致**。
  理由：说明是公文语言（「预算明细表」），文件名常带 `_v2` / `扫描件-` / 扩展名 ⇒ **硬匹配必然误报**，
  用户一旦学会无视提示，这个校验就等于没做。
- **提示口径**：只提示**不阻断**；只在**条数一致**时比对（数量不等已有条数提示，双重提示会互相淹没）；
  最多列 3 条防刷屏；提示必须带「去哪改」（编辑页）。
- **判据位置**：比对逻辑放模型层（WP-free、可单测），提示拼装放建单路径（`doc-admin-list.php` 的 `$extra`）——
  与 OA-2515 的「条数一致性」同一处、同一形状。
- **回归**：`php tests/test-doc-attach-name-match.php`（16 项：一致/不一致/边界/接线四组）；
  撤回源码改动后 rc=255（函数不存在 ⇒ 直接 fatal，仍属判红）。

## 2026-11-18 · OA-2527：发版 0.1.32（任命归档留痕 + 附件名称软匹配）

- 0.1.31 → **0.1.32**（`release-bump-oa.sh patch`），上线 OA-2525（任命归档留痕可见）、
  OA-2526（附件说明名称软匹配提示）。
- **本次发版顺序（把「逃生口」彻底去掉的标准路径）**：
  1. 卫星单改代码 + 提交 → OA 侧 `workbench/finish.sh`；
  2. **载体单标题不带「发版/发布/上线/切换」**（载体本身不部署）⇒ 载体收口**不触发**回读判据；
  3. 先合并载体（台账/metrics 落 dev）→ 再合并插件分支（M3 靠 metrics 里的载体单号即通过）；
  4. **合并之后**才切生产（`plugin-switch-oa.sh` 归档 dev HEAD = 0.1.32）；
  5. 最后收卫星单 —— 此时 `release-readback-check.sh --repo dev-tcpc-oa` 比对
     「仓 0.1.32 vs 生产 0.1.32」**真实一致**，**无需 `ZW_SKIP_RELEASE_READBACK`**（AGENT-0542 实证）。
- 口径沉淀：**回读判据应按「真正部署的那个单」判定**；载体单不该背这个门禁，否则只能一直靠逃生口。

## 2026-11-18 · OA-2528：一级标题字重默认改「常规」（C1，载体/规范任务 TASK-3025）

- **口径（用户 2026-11-18 拍板）**：`fonts.h1.weight` 默认 `bold` → **`normal`**。
  理由：一级标题字体槽已是**黑体**（本身即粗面），再叠 `font-weight:bold` 就是**双重加粗**，
  笔画发糊；GB §7.3.3 只说「第一层用黑体字」，没说再加粗。
- **影响面（改默认值必须盘清）**：`fonts.h1.weight` 被**两处**共用 ——
  ① 一级标题 `.tco-doc-h1`；② **版头标记** `.tco-doc-mark`（份号/密级/紧急程度，走同一 h1 字重槽）。
  两处一起变常规；**标题 `title` 字重不动**（小标宋口径另表）。版式页仍可一键改回「加粗」。
- **实现要点**：本次**只改版式表默认值**，渲染层零改动 —— 因为 OA-2516 已把字重改成
  `zw_doc_render_font_weight()` 读 `fonts.<slot>.weight`。**这就是先接线、后调默认的好处**。
- **测试坑**：注入版式覆盖的测试桩必须支持**任意层级键**（`fonts.h1.weight` 是 3 层），
  只写两层会静默失败（本次实测踩到，已修）。
- **回归**：`php tests/test-doc-h1-weight-default.php`（10 项：默认值/影响面/可配性/纸面回归；撤默认值改动 rc=1）。

## 2026-11-18 · OA-2529：撰写页「编辑正文 / 版式预览」分栏 + 单页整页预览 + 页头图标工具栏

- **用户口径（2026-11-18 截图指令）**：① 中间分割线可左右拖拽、比例作为默认值；② 预览区**始终完整显示第一页**；
  ③ 翻页 / 缩放 / 打印 / 另存为等快捷功能**图标化放页头**（参考 PDF 编辑器）；④ 注释放预览**下方**，
  预览内不放功能与注释；⑤ 预览**不随页面滚动**。
- **两种预览模式的唯一入口**：`zw_doc_preview_fit_script($mode)` / `zw_doc_preview_fit_html($html, $mode)`
  （`doc-composer.php`）。默认 `continuous`（fit-width + 多页纵向连续）= 详情页 embed / 版式设计器 /
  「预览当前内容」新窗口，**零变化**；`paged` = 撰写页内嵌（fit-page 单页 + 打印复位）。
  **禁止**再写第二套预览脚本；新增预览面只选模式。
- **paged 协议**：父页 → iframe `{type:'zw-pdf-command', action: next|prev|zoom-in|zoom-out|fit|goto|print}`；
  iframe → 父页 `{type:'zw-pdf-state', page, pages, zoom}`（页码唯一出处是渲染器输出的 `.tco-doc-sheet` 数）。
  `zoom = 1` 即「适应页面」，百分比只用于显示。
- **分栏持久化**：`localStorage['zwDocComposerSplitPct']`（左栏占比，38%~78% 夹紧，默认 60）。
  松手 / 键盘 ←→ / Home 时写入；**没有服务端用户偏好**（换浏览器不继承，这是刻意的最小实现）。
- **sticky 契约**：`.zwc-right{position:sticky;top:40px}`；**祖先不得有 `overflow:hidden/auto`**，
  否则 sticky 静默失效（本页 `.zwc-grid` 已无 overflow）。卡片高度由 `sizeCard()` 按
  `max(stickyTop, rect.top)` 实时算 ⇒ 滚动时底边恒定、预览始终铺满视口；<1100px 堆叠并清掉 inline height。
- **本次踩到的坑（写进回归）**：① `display:none` 的预览页**量不到宽高** ⇒ paged 的 `layout()` 必须先临时
  解除 `[hidden]` 再量；② 预览 iframe 是 `srcdoc`（同源）但**功能按钮必须在父页**，预览页只画纸面；
  ③ 表单内图标按钮一律 `type="button"`，唯一 `type="submit"` 是「新窗口打开」（`formaction` + `target=_blank`）；
  ④ 打印必须 `beforeprint` 撤掉 `transform` 并显示全部页面，否则只印当前页。
- **回归**：`php tests/test-doc-preview-viewer.php`（44 项）；`php tests/test-doc-preview-print-parity.php`
  仍须全绿（保证默认 continuous 与详情页零变化）。

## 2026-11-18 · OA-2530：发版 0.1.33（C1 一级标题字重改常规上生产）

- 0.1.32 → **0.1.33**（`release-bump-oa.sh patch`），上线 OA-2528（`fonts.h1.weight` 默认 normal）。
- **教训（重要）**：C1 并入 dev 时**没有升版**，于是出现「仓与生产版本号相同、但内容不同」的状态
  （生产 h1 仍是 `bold`，dev 已是 `normal`），而 `release-readback-check.sh` 只比版本号 ⇒ 会显示 ok **却漏掉差异**。
  ⇒ **内容型改动（字重/间距/默认值）合入 dev 后必须尽快升版发布**；收口时除版本号外，还要**实内容抽查**
  （本次已抽查生产副本 `doc-layout-gb9704.php` 的 `fonts.h1.weight` 是否已是 `normal`）。
- 发版顺序沿用固定做法：卫星单提交 + OA 侧 finish → 载体单标题不带「发版」词（不触发回读）→ 先合并载体 →
  再合并插件 → 切生产 → 最后收卫星单（此时按仓回读真实一致，不需要逃生口）。

## 2026-11-18 · OA-2531：发文详情左右布局（预览右侧固定）+ 红头界面配置下线 + 栏目改「印章管理」

- **预览面板只有一个出处**：`zw_doc_preview_panel_css()` / `zw_doc_preview_panel_html()` /
  `zw_doc_preview_panel_script()`（`doc-composer.php`）。撰写页（form 模式，POST + srcdoc）与
  详情页（url 模式，iframe src + 重载）都调它；**禁止**再在任一页面写第二套工具栏/样式。
  元素 id 固定（`zwc-preview-*` / `zwc-pv-*`）——一页只放一个面板。
- **详情页布局**：「概览」通栏；`.tco-doc-detail-grid` = 左 `.tco-doc-detail-main`（设置卡片流）+
  右 `.tco-doc-detail-side`（`sticky;top:40px`，只放预览）。**sticky 必须挂在 grid item（side）上**，
  挂在 `.zwc-pv-card` 上会因父级高度=自身而失效（本页已用 `.tco-doc-detail-side .zwc-pv-card{position:static}` 覆盖）。
  卡片高度仍由共享脚本按可见顶边计算（`min_width=1200` 与页面断点一致）。
- **内嵌 paged 的接线（保持单参调用）**：`zw_doc_embed_url()` 默认加 `mode=paged`；打印端点据此在
  `<head>` 写 `<meta name="zw-doc-preview-fit" content="paged">`；`zw_doc_preview_fit_html()` 读该 meta 选
  paged 脚本。这样主题跨仓测试锁的 `zw_doc_preview_fit_html($zw_print_html)` 字面调用不用改，
  也不会出现「第二个 fit 入口」。
- **红头口径（用户 2026-11-18 拍板「只删界面配置」）**：`zw_doc_letterhead_resolve()` / 门禁 / 素材登记表
  全部保留（渲染仍可系统自动生成）；**界面**只删三处 —— 详情页「红头」下拉、数据字典「红头生成方式（只读）」卡、
  「红头与印章」页的红头模板 Tab（页面/菜单改名「印章管理」，不再受理 `letterhead_*` 写动作）。
  已上传素材保留、不再有入口；fail-closed 文案改为「联系系统管理员」，不得再指向已删除页面（死链）。
- **本次同步的主题跨仓断言（10 个文件）**：`test-doc-admin-ui` / `test-doc-letterhead` /
  `test-doc-letterhead-mode` / `test-doc-detail-anchors` / `test-doc-pdf` / `test-doc-composer` /
  `test-doc-composer-preview` / `test-doc-composer-steps` / `test-doc-render-html` / `test-doc-acceptance`。
  其中 `test-doc-composer-steps` 的表单 name 扫描收紧为「完整字段名」形态（组件生成式属性不计入），
  `test-doc-render-html` / `test-doc-acceptance` 的 h1 字重断言跟上 OA-2528（normal）。
- **回归**：`php tests/test-doc-detail-preview-panel.php`（38 项）、
  `php tests/test-doc-preview-print-parity.php`（25 项）、`php tests/test-doc-preview-viewer.php`（44 项）。
