# CHANGELOG

## [Unreleased]

### Added

- **网盘文件菜单注册权从主题移入插件（OA-2453 / 主题 TASK-2481）**：`page=zw-org-files` 的
  `add_menu_page`（position **60.5**，办公协同号段第 5 位，能力位 `manage_options`）改由
  `includes/files/org-files.php` 注册；主题 `inc/admin/admin-nav-registry.php` 已删除该调用。
  效果：**关掉插件后左侧六条 OA 入口一条不剩**（用户 2026-10-30 拍板「关插件就不能还有入口」）。
  守卫：页面回调缺失 / 无权限 ⇒ 零注册，不产出悬空菜单项。

### Fixed

- **「启用插件」致命错误（OA-2452，2026-10-30 生产）**：`/wp-admin/plugins.php?action=activate` 整站 500 ——
  `PHP Fatal error: Cannot redeclare zw_policy_platform_clause_review_core()`（主题 `policy-platform-admin.php:303`
  → 插件 `includes/policy/policy-platform-admin.php:289`）。根因＝**装载顺序反转**：正常请求里插件先于主题
  （`wp-settings` 先 require 插件、后 require 主题 `functions.php`），而 `activate_plugin()` 是在 wp-settings
  **之后**才 include 插件文件 ⇒ 主题同名副本先声明，插件 `L289` 起那段**无 function_exists 守卫**的顶层声明随即撞车。
  修复：该段（L289→EOF）改为**哨兵整段条件包裹**（`if (!function_exists('zw_policy_platform_clause_review_core')) { … }`），
  声明层让位（`return` 无效 —— PHP 在 include 期即绑定无条件顶层函数）。两条装载顺序现在都安全，行为在
  「主题缺席」时逐字不变。新增判据：`scripts/probe-load-order.php`（子进程隔离跑 theme-first / plugin-first 双序）
  并挂进 `scripts/ci-check.sh` ⑦。

- **任命对象可见性按实名收口（OA-2450）**：`zw_doc_appointee_ids()` 只保留**实名已核验**的 uid
  （canonical `zw_doc_user_realname_verified()`：`ZW_Realname::is_verified()` → `zw_branch_user_identity()`
  → `zw_real_verified` meta；不可判定 ⇒ 不放行，个人信息面 fail-closed）。
  用户 2026-10-30 拍板「做」：未实名的任命对象不进入「我的收文」与 `zw_doc_can_view_doc()`。
- **任命归档证书路径兜底不再 fatal（OA-2450）**：`zw_doc_appoint_cert_backend()` 的 `cert-paths.php`
  兜底 require 迁入插件后指向插件根（不存在）⇒ CI/CLI/主题未装载时直接 fatal。改为「存在才 require」，
  解析不到 `zw_cert_backend_file()` 时按既有口径返回 null（调用方走 LWEMS 原路径），不中断任命书颁发。
- **「我的收文」补任命对象可见性（OA-2449）**：任字号发文的任命人员 uid（正文 `meta.appointments`）是
  **明确指定的实名收件人**，此前只存在于正文、不进任何可见面（被任命用户在「我的收文」0 篇、预览 403）。
  新增 canonical 派生 `zw_doc_appointee_ids($doc)`（只读、不落库）；`zw_doc_get_document()` 只增键
  `appointee_ids`；`zw_doc_query_documents()` 的 `recipient_user_id` 口径扩为「抄送 ∪ 审核人 ∪ 任命对象」；
  `zw_doc_can_view_doc()` 放行任命对象本人。抄送/审核人语义、已发布（`approved`）门槛、PDF 完成件门槛均不变。
- **AI法务部控制面依赖降级（OA-2448）**：`includes/policy/policy-platform-admin.php` 里对**主题读侧**符号的
  直接调用改为存在性兜底 —— `zw_policy_applicability_registry_groups()` 缺 `zw_policy_module_keys/feature_keys`
  时返回空分组；`zw_policy_gate_option_state()` 缺 `zw_policy_option_is_on/tokens` 时按「未开 + 空白名单」降级。
  效果：主题读侧缺席/版本错位时，后台页**只读降级**而不是整页 fatal。判定/执行链路（年龄审查等）不变。
- **契约登记政策读侧缝（OA-2448）**：`docs/contract-tcpc-oa.md` 新增 **P-4**（方向、符号清单、缺位语义、
  版本握手 `zw_oa_policy_api_version()=1`、插件装载面纪律），并记录「适用模块保存依赖主题
  `scripts/migrate-policy-rule-modules.php`，插件装载时只读」这一已知缺口（待独立单拍板）。

### Added

- **契约登记后台导航位置号段 P-5（OA-2451 / 主题 TASK-2476）**：`docs/contract-tcpc-oa.md` 新增 P-5 ——
  办公协同号段 `59–60.9` 由**主题发放**，**插件在号段内取号**并持有「存在权 + 位置权」；主题只发分组标题、
  号段与仪表盘容器，不得为 OA 模块写 `position`。插件缺席 ⇒ 号段整段空置（零菜单项、零占位、分组标题不渲染、
  仪表盘卡片消失）。

- **分仓号段门禁（OA-0001 / 主题 TASK-2398 / CHG-431）**：`workbench/preflight.sh`（②b）与
  `workbench/commit-msg.sh`（④）新增「号段归属」判据 —— 任务号前缀必须等于 `docs/ops/repos.yml`
  里本仓的 `space`（oa = `OA`）。
  - 判定唯一实现在主题仓 `scripts/lib/zw-repo-space.sh`；本仓新增 `workbench/repo-space.sh`
    只做来源加载（与 `task-space.sh` 同构），**不复制第二份判定**；缺库 / 清单不可读 ⇒ fail-closed。
  - 与 cert 仓（CERT-0004）**零代码差异**：同一份实现，只读清单；接入新卫星仓无需改判定逻辑。
  - 实测（2026-10-28）：`CERT-0004` / `TASK-9999` 过 commit-msg ⇒ 拒绝；`OA-0001` ⇒ 通过。

- `scripts/plugin-switch-oa.sh`：P3 发版通道（版本三方 + `MIN_THEME` 兼容矩阵 + `git archive HEAD` 构建 +
  备份 + 原子交换 + 冒烟 + `--rollback`）。冒烟含 `/bhzc` 正文哈希前后一致（铁律 19）。
- **收文管理迁入（未启用）**：`includes/receipt/` 11 文件 / 6,675 行，与主题 `inc/receipt` **DIFF=0**；
  `includes/load-list.php` 登记模块 + 双钥匙开关（`enabled=false` 且 `TCPC_OA_ENABLE_RECEIPT` 未定义 ⇒ 零加载）。
- `scripts/verify-module-parity.sh`：模块迁入的 diff=0 唯一判据工具。
- **组织架构业务迁入（OA-2432 / R5，未启用）**：`includes/org/` 5 文件 / 4,413 行
  （`org-bootstrap.php` / `org-repository.php` / `org-admin.php` / `org-admin-people.php` /
  `org-admin-roles.php`），与主题 `inc/admin/org/` **逐字节 DIFF=0**
  （`bash scripts/verify-module-parity.sh org --exclude org-permissions.php` ⇒ 5 文件逐个 `cmp`
  一致，rc=0；`cp` 原样复制，未改一行代码、未动 require 路径）。
  RBAC 内核 `org-permissions.php`（1,621 行）按 Q2 拍板**不迁**，`file-center.php` / `org-files.php`
  属 `files` 模块留 R6；插件侧 `grep -rc 'function zw_org_permissions_'` 命中 **0**（无内核副本）。
  `includes/load-list.php` 的 `org` 项 `enabled` 仍为 **false** ⇒ `tcpc_oa_load_modules()` 零 require，
  运行期零行为；启用（双钥匙）待 R7。
- **网盘文件中心 UI 迁入（OA-2433 / R6，未启用）**：`includes/files/` 2 文件 / 1,762 行
  （`file-center.php` 1,075 行 + `org-files.php` 687 行，取自主题 `inc/admin/org/`），**逐字节 DIFF=0**
  （`bash scripts/verify-module-parity.sh files` ⇒ 2 文件逐个 `cmp` 一致，rc=0；`cp -p` 原样复制，
  未改一行代码、未动 require 路径）。`includes/load-list.php` 的 `files` 项本就指向
  `includes/files` 且 `enabled=false` ⇒ **零改动**；`tcpc_oa_load_modules()` 仍零 require，运行期零行为。
  文件默认打开动作复核（SPEC-85 / 铁律 22）：两文件 `zw_control_file_open_link()` 5 处、
  `zw_control_file_download_button()` 4 处（3 处实际渲染）、`download=` 属性 **0** 处
  ⇒ 文件名/图标仍只走 canonical「默认打开」，下载仍只由显式按钮触发。

### Changed

- **policy parity 口径收窄 + 插件装载责任（OA-2419 R3-2 / Q-A2「读侧留主题」）**：
  `scripts/verify-module-parity.sh` 的 `registry` 把 `policy` 主题侧匹配**收窄到 3 个管理面副本**
  `inc/admin/policy-platform-{consent,content-review,document-center}.php`（+ shim
  `inc/admin/policy-platform-admin.php`）；内核读侧 3 个
  `inc/policy-platform/{policy-module-map,policy-platform-cron,policy-rule-engine}.php` 拍板留主题 ⇒
  移出 theme 行（否则 `--expect-theme-absent` 把内核文件误报成「残留副本」），插件内同名副本登记为
  **默认 exclude**（否则反向扫描误判 `missing-theme`）。**比对不放松**：3 个管理面副本仍逐个 `cmp`
  （夹具把副本改 1 字节 ⇒ `DIFF: policy-platform-consent.php` rc=1）。
  `includes/policy/policy-platform-admin.php`（= `load-list.php` 登记的 policy bootstrap）承接**装载责任**：
  `require_once` 其余 6 个模块文件（7 个全覆盖），每处 `is_file` 守卫；本文件先装 ⇒
  `policy-platform-cron.php:13` 的「缺函数回指主题 admin」懒加载守卫不触发，插件不反向 require 主题文件。
  原因：主题 `functions.php` 把这 7 个文件分 5 处 require，其中 3 个管理面只在 `is_admin()` 加载
  ⇒ 主题副本按 R3 删除后，若插件只装 admin，「内容审核 / 签署管理 / 文档中心」3 个后台页会整页消失。
  另：`zw_policy_platform_table` / `zw_policy_platform_exists` 的 `function_exists` 守卫**自迁入即存在**
  （`policy-platform-admin.php:50/57`），本轮补注释锚定 canonical
  `inc/policy-platform/policy-platform-data.php:24/37`（守卫行 `:17/:30`）；**未删函数体**（保住与主题副本 DIFF=0，删体留 R3-4）。
  ⚠️ 发布窗口纪律：主题侧 3 个副本删除（R3）**之前**不得打开第二把钥匙
  （`TCPC_OA_ENABLE_POLICY` / option `tcpc_oa_enable_policy`）—— 会与仍在加载的主题副本撞
  `zw_legal_consent_admin_menu`（全仓唯一无声明守卫的函数）⇒ `Cannot redeclare`。
  - 实测（2026-10-29）：`verify-module-parity.sh policy` ⇒ `DIFF=0`（3 文件）rc=0；
    `/tmp` 两态夹具 **19/19**（残留任一 `inc/admin` 副本 ⇒ rc=1 点名；只留 shim + 读侧 3 文件 ⇒
    `POST-MIGRATION OK` rc=0；读侧 3 个两态均未被点名）；插件独立加载探针 **16/16**（7 文件全 include、
    3 个后台页菜单/回调就绪、0 个主题文件被 include）；第二把钥匙关闭态 **2/2**（`loaded=[]`，零加载）；
    `bash scripts/ci-check.sh` 六项全绿。

- **模块 parity 能力扩展（OA-2418 R2）**：`scripts/verify-module-parity.sh` 从「整目录 `diff -r` +
  模块 `case` if-else 长链」改为**数据化模块登记表**（`registry`：模式 / 插件侧目录 / 主题侧匹配清单 /
  shim / 默认 exclude），并新增**文件清单模式**（逐文件 `cmp`，缺失任一侧显式报
  `missing-theme:` / `missing-plugin:`）、`--exclude <basename>`（可多次）、
  按模块声明 shim 判定的 `--expect-theme-absent`（缺 shim ⇒ rc=1，fail-closed 不放宽）。
  `includes/load-list.php` 把 `doc` / `org` / `files` / `secretariat` 的占位注释兑现为**实际登记**
  （`enabled=false` ⇒ `tcpc_oa_load_modules()` 零 require，运行期行为不变）。
  - 修掉的三个实测缺陷（2026-10-29）：① `policy` / `org` / `files` 传进去直接 `rc=2 未登记模块`；
    ② `THEME_SHIM` 只在 `receipt` 分支赋值 ⇒ 其它模块走 `--expect-theme-absent` 恒判红；
    ③ `diff -r` 整目录无法表达「`policy` 4 个文件散在主题 `inc/admin/` 13 个 php 中」与
    「`files` 只占 `inc/admin/org/` 8 个中的 2 个」。
  - 实测：`policy` ⇒ `DIFF=0`（6 文件逐个 cmp）；`org` / `files` / `doc` / `secretariat` ⇒ `rc=1` 且
    输出「插件侧模块目录不存在」（预期红，不是假绿）；`receipt` 默认模式与 `--expect-theme-absent`
    与改造前一致；`org --exclude org-permissions.php`（Q2）与 `--expect-theme-absent` 的夹具三态/两态已跑通。

### Notes

- 收文启用须与主题侧 shim + 插件部署同一发布窗口；在此之前本插件对站点零行为。

## [0.1.0] - 2026-10-28

### Added

- 仓骨架：`AGENTS.md`（卫星接入指令）、`workbench/` 七件套（load / preflight / finish / 钩子 / 任务号镜像）、
  `.githooks/`（marker + 任务号 + ci-check 前置）、`VERSION` / `MIN_THEME` / `README`。
- `docs/contract-tcpc-oa.md`：主题 ↔ 插件契约指针（P-1/P-2/P-3/W-1，正文在主题仓实现侧登记）。
- `tcpc-oa.php`：插件入口占位（只声明 `TCPC_OA_VERSION`，**不加载任何业务**，避免骨架期影响生产）。

### Notes

- 骨架期**不迁业务代码**；迁入按主题仓 TASK-2306 的 P 阶段执行，并要求 diff=0 证据。
- 生产目录未创建、未激活：本仓当前对站点零影响。

### Changed

- **多仓开工接缝（OA-2428）**：`workbench/preflight.sh` 新增 `ZW_WORKBENCH_THEME_TASK=<TASK 号>`，
  让纯插件任务（`OA-xxxx`）可以引用**主题载体单**（`TASK-xxxx`）的开工 marker。
  - 动机：主题仓 `zw_assert_task_space` 严格比号段前缀（theme=`TASK`），`OA-xxxx` 在主题仓
    preflight 必然 rc=1 ⇒ 插件仓第 ③ 步硬要求的「同号 marker」结构性不可满足。
  - 缺省行为**逐字不变**（不设该变量 ⇒ 仍找 `task-<本号>`）；设了但载体缺失 ⇒ **fail-closed**，
    不静默回退。官方形态见 `workbench/README.md` §1.1。

### Fixed

- **OA-2434（R6b）org 模块启用前的两处加载适配（有意差异，CHG-434）**：
  ① `includes/org/org-bootstrap.php` 不再 `require org-permissions.php`（主题内核，Q2 不迁）
  —— 改为在 `after_setup_theme` 判内核契约符号 `zw_org_all_permission_keys`，缺则 fail-closed
  （零菜单 + `admin_notices`），不留 fatal；② `includes/org/org-admin.php` 的 `org-files.php`
  引用改指 `includes/files/`（R6 已按模块拆分迁入）。两处均与主题侧**有意不同**，由 CHG 登记。
  实测修正：**不能用 `admin_init`** —— WP 核心 `wp-admin/admin.php:163` 先 require `menu.php`
  （`admin_menu` 在其内触发），`:180` 才 `admin_init` ⇒ 挂 `admin_init` 菜单永远不显示。

### Changed

- **OA-2435（R7-1）parity 增加「按文件、带理由」的有意差异豁免**（`scripts/verify-module-parity.sh`）：
  登记表新增 `allow|<模块>|<basename>|<理由≥8 非空白字符>`；命中差异时打印
  `· ALLOWED-DIFF <file>（理由）` 并放行，**只放行该文件**（不是整模块 exclude）。
  两条反滥用判据：理由空/过短 ⇒ fail-closed 判红；**陈旧豁免**（登记了但实测无差异）⇒ 判红。
  首期登记 OA-2434 的两处（理由见 CHG-434）；`org` 由 DIFF≠0 转为 DIFF=0，`files`/`policy` 行为不变。

## [0.1.1] - 2026-10-29

### Added

- 组织架构业务（`includes/org/`，5 文件 4,413 行，自主题 `inc/admin/org/` 逐字节迁入）与网盘文件中心 UI
  （`includes/files/`，2 文件 1,762 行），两模块 `enabled=true` 但**第二把钥匙默认关**（option 未写）
  ⇒ 生产行为与 0.1.0 等价。
- `includes/org/org-bootstrap.php` 改为 `after_setup_theme` 装载 + 内核契约符号守卫（缺则 fail-closed 不 fatal）；
  `org-admin.php` 的 `org-files.php` 引用改指 `includes/files/`（两处有意差异，CHG-434）。

### Fixed

- parity 脚本：新增「按文件、带理由」的有意差异豁免 + 陈旧豁免判红；豁免命中时 summary 不再谎称逐字节一致。

## [0.1.2] - 2026-10-29

### Added

- 发文管理模块（27 文件 / 30,242 行）整模块迁入 `includes/doc/`，与主题逐字节一致；
  `doc.enabled=true` 但第二把钥匙（option `tcpc_oa_enable_doc`）默认关 ⇒ 生产行为不变。
- 实名客户端改走主题契约 `zw_oa_realname_client_ready()`（parity 登记唯一有意差异）。

### Added (tooling)

- `scripts/oa-doc-render-smoke.php`：发文后台 9 页渲染冒烟（四断言，缺回调 fail-closed rc=2）。

## [0.1.3] - 2026-10-30

### Changed

- AI 秘书处模块 `secretariat.enabled` 置 true（第二把钥匙 `tcpc_oa_enable_secretariat` 默认关）。
  门状态存于任务文章 post meta `_zw_sec_gates`（迁移前后同一份），74/74 函数带 `function_exists` 守卫。
  权限沿用 `tcpc_perm_doc`（TCPC管理员 / TCPC审批人 / TCPC监督管理），未给内置 administrator 扩权。
