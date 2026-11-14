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

## 0.1 长期记忆三层（必须遵守 · TASK-2429 / CHG-435）

用户 2026-10-29 指令原文：「dsh 和 中传网主题 以及相关联的 插件项目 的长期记忆均要遵守」。

- **三层**：`dsh`（`/opt/deepseek-harness/AGENTS.md` + `/srv/ai-memory/projects/tcpc/`，只读）／
  `theme`（主题仓 `docs/development-long-term-memory.md`，跨仓共性唯一出处）／
  `oa`（**本仓 `docs/development-long-term-memory.md`**，只写本仓特有事实）。
- **清单唯一出处**：主题仓 `docs/ops/long-term-memory.yml`；**判据唯一实现**：主题仓
  `scripts/long-term-memory-gate.py`（本仓 `workbench/preflight.sh` / `workbench/finish.sh` 调用同一实例）。
- **开工**：本仓 preflight 跑 `check --task <号>`（各层存在/可读/非空壳，打印必读清单）；
  **收口**：本仓 finish 跑 `finish --task <号> --repo oa`（**本仓记忆必须被本次分支改动**；
  确无跨会话事实 ⇒ 主题台账写 `- 长期记忆：无需入账（理由：<≥8 字>）`，禁止裸豁免）。
- **禁止**在本仓另写第二套判据，也**禁止**把主题仓共性口径抄进本仓（只留指针）。

## 1. 标准流程

见 [`workbench/README.md`](workbench/README.md) §1。要点：**建任务工作树** → 主题侧建载体单并补台账 → 双端 preflight →
提交 → `ci-check` → `workbench/finish.sh` → 主题 `workbench-finish.sh --repo dev-tcpc-oa` →
`dev-merge.sh --repo … --space OA` → `scripts/sync-remotes.sh --apply`。

**第一步必须是「在本仓基座下建任务工作树」**（`OA-2458` 起有门禁断言：`workbench/preflight.sh` ⓪ 会拒绝
「不在 `<基座>/task-<本号>` 的工作树」与「分支不匹配」两种错位）：

```bash
bash workbench/worktree.sh create OA-xxxx                      # → /srv/git/worktrees/dev-tcpc-oa/task-OA-xxxx（分支 task/OA-xxxx）
bash workbench/worktree.sh list                                # 本仓已有哪些任务工作树
bash workbench/worktree.sh remove OA-xxxx --delete-branch      # 合并后清理（未并入的分支用 -d 拒绝删除，防丢活）
```

不要用主题的 `scripts/workbench-worktree.sh`：它把 `WORKTREE_BASE` 写死 `dev-tcpc`，会把 `task-OA-xxxx` 建到**主题仓**。

## 2. 硬约束（一票否决）

- **唯一台账 / 唯一角色表 / 唯一工作台页**：都在主题仓；本仓不复制。
- **生产只读**：`/www/wwwroot/tcpc.org.cn` 禁止直接改；发版走 `scripts/plugin-switch-oa.sh`（已上线使用，含主题兼容门禁与结构冒烟）。
- **契约唯一出处**：`docs/contract-tcpc-oa.md`；主题侧同名文件只留指针。
- **版本三方一致**：`VERSION` == `TCPC_OA_VERSION` == 插件头 `Version:`；`MIN_THEME` 声明最低兼容主题版本。
- **不得反向依赖主题内部实现**：只允许调主题**内核契约**（`inc/oa/contract-legal.php` 的 P-1/P-2/P-3/W-1 等）
  与已登记的反向缝；新增调用必须先在主题侧过 `scripts/oa-kernel-boundary-check.php`。
- **文件与目录铁律沿用主题**：文件默认打开动作（SPEC-85 / 主题铁律 22）、网盘归档目录（SPEC-90 / 主题铁律 20）、
  `/bhzc` 只读（主题铁律 19）—— 在本仓同样生效，不得以「新仓」为由另立一套。
- **隐私与适老化沿用主题门禁**：涉及个人信息/条款/可见面时，按主题 `AGENTS.md` 第 12/23 条执行。

## 3. 当前阶段（重要）

本仓**已在生产运行**（2026-11-14 起 `0.1.27`，主题 `1.2.5`）：发文 / 收文 / AI法务部 / AI秘书处 /
网盘文件中心 / 组织架构业务六个模块全部迁入并在生产启用；主题侧只保留 parity shim 与读侧回退副本。

迁移分期见主题仓 `docs/migration/TASK-2306-中传网办公协同tcpc-oa分仓调研与迁移方案-20261028.md`
（P0 基线 → P1 解耦 → P2 建仓 → P3 发布通道 → P4 主题收尾 → P5 门禁 → P6 多仓调度）；当前剩余为
**主题回退副本清理 / 门禁翻强制 / 消费者接线**（见台账最新单）。

**纪律不变**：仍禁止把主题业务目录整段复制回来（会产生两套实现）；任何迁入必须按阶段单执行并保留 diff=0 证据。
**发版顺序**：主题先发（含「插件优先」条件加载守卫）→ 插件后发；反过来会 `Cannot redeclare`。
