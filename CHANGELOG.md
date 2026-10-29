# CHANGELOG

## [Unreleased]

### Added

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
