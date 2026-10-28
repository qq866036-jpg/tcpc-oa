# 中传网办公协同（tcpc-oa）· 智能体工作区指令

> 本仓是**插件仓**：发文管理 / 收文管理 / AI法务部 / AI秘书处 / 网盘文件中心 / 组织架构业务。
> 工作台本体（台账 / 角色 / metrics / 工作台页）**只在主题仓** `/srv/git/worktrees/dev-tcpc/main`。
> 本文件是卫星接入层，**不复制**第二套治理。

## 0. 每次会话 / 每个任务开工前（必须）

1. `bash workbench/load.sh` —— 只读加载主题工作台摘要（角色 / 计数 / 最近与在办任务 / 本仓状态）。
2. 任务号必须在**主题仓** `AUTONOMOUS_TASKS.md` 已登记：
   - 主题侧改动 → `TASK-xxxx`；
   - **纯本仓改动** → `OA-xxxx`（需 `export ZW_TASK_SPACES=TASK,OA`）。
   本仓**不得**自造号、不得新建台账。
3. 加载对应角色技能（`tcpc-*`），按 `tcpc-orchestrator` 路由确定上场角色。
4. 门禁：`.githooks` 已接入 —— 无 marker 拒绝提交、提交信息必须含任务号、pre-commit 跑 `ci-check.sh --precommit`。
   安装/查看：`bash workbench/install-hooks.sh [--status]`；急救用 `git commit --no-verify`（须在交接单写明理由）。

## 1. 标准流程

见 [`workbench/README.md`](workbench/README.md) §1。要点：主题侧建工作树 → 补台账 → 双端 preflight →
提交 → `ci-check` → `workbench/finish.sh` → 主题 `workbench-finish.sh --repo tcpc-oa` →
`dev-merge.sh --repo … --space OA`。

## 2. 硬约束（一票否决）

- **唯一台账 / 唯一角色表 / 唯一工作台页**：都在主题仓；本仓不复制。
- **生产只读**：`/www/wwwroot/tcpc.org.cn` 禁止直接改；发版走 `scripts/plugin-switch-oa.sh`（待建）。
- **契约唯一出处**：`docs/contract-tcpc-oa.md`；主题侧同名文件只留指针。
- **版本三方一致**：`VERSION` == `TCPC_OA_VERSION` == 插件头 `Version:`；`MIN_THEME` 声明最低兼容主题版本。
- **不得反向依赖主题内部实现**：只允许调主题**内核契约**（`inc/oa/contract-legal.php` 的 P-1/P-2/P-3/W-1 等）
  与已登记的反向缝；新增调用必须先在主题侧过 `scripts/oa-kernel-boundary-check.php`。
- **文件与目录铁律沿用主题**：文件默认打开动作（SPEC-85 / 主题铁律 22）、网盘归档目录（SPEC-90 / 主题铁律 20）、
  `/bhzc` 只读（主题铁律 19）—— 在本仓同样生效，不得以「新仓」为由另立一套。
- **隐私与适老化沿用主题门禁**：涉及个人信息/条款/可见面时，按主题 `AGENTS.md` 第 12/23 条执行。

## 3. 当前阶段（重要）

本仓处于**骨架期**：只含接入层与契约指针，业务代码尚未迁入。
迁移分阶段与门禁见主题仓 `docs/migration/TASK-2306-中传网办公协同tcpc-oa分仓调研与迁移方案-20261028.md`
（P0 基线 → P1 解耦 → P2 建仓 → P3 发布通道 → P4 主题收尾 → P5 门禁 → P6 多仓调度）。

**禁止**在骨架期把主题业务目录整段复制进来（会产生两套实现）；迁入必须按阶段单执行，并保留 diff=0 证据。
