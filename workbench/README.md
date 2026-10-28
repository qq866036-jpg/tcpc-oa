# 中传网办公协同（tcpc-oa）· 插件工作区 × 智能体工作台（卫星接入）

> 本仓是**插件仓**（仓根 == 插件根）。工作台本体（台账 / 角色 / metrics / 工作台页）**只在主题仓**
> `/srv/git/worktrees/dev-tcpc/main`，本目录只做**卫星接入**，不复制第二套。

## 0. 自动加载

1. 会话首个请求自动注入本仓根 `AGENTS.md`（工作区根 `/srv/git/worktrees/dev-tcpc-oa/AGENTS.md` 软链到同一份）；
2. `AGENTS.md` 要求开工前 `bash workbench/load.sh`（**只读**）汇总主题仓：19 角色 / 执行计数 / 最近与在办任务 / 活动 marker / 本仓状态；
3. 摘要不落盘、不缓存，每次都是当前值。

```bash
bash workbench/load.sh          # 人读
bash workbench/load.sh --json   # 机器读
```

## 1. 标准流程（跨仓：主题台账唯一）

```bash
# ① 主题侧建任务工作树（唯一写台账的地方）
cd /srv/git/worktrees/dev-tcpc/main
bash scripts/workbench-worktree.sh create TASK-xxxx
cd /srv/git/worktrees/dev-tcpc/task-TASK-xxxx
#   追加 ## TASK-xxxx 条目（含「修复范围」逐条路径）
bash scripts/workbench-preflight.sh TASK-xxxx "role:依据;…"

# ② 本仓开工门禁（交叉校验主题 marker）
cd /srv/git/worktrees/dev-tcpc-oa/main
export ZW_TASK_SPACES=TASK,OA          # 纯插件任务用 OA-xxxx 时
bash workbench/preflight.sh TASK-xxxx "role:依据;…"

# ③ 改代码 / 测试 / CHANGELOG
git add <显式路径…>
git commit -m "feat(oa): TASK-xxxx …"

# ④ 本仓质量门 + 收口
bash scripts/ci-check.sh
bash workbench/finish.sh "任务说明"     # 打印主题侧收口命令（带 --repo tcpc-oa）

# ⑤ 主题侧收口（唯一写 metrics / 台账状态 / 工作台页）
cd /srv/git/worktrees/dev-tcpc/task-TASK-xxxx
bash scripts/workbench-finish.sh "任务说明" --repo tcpc-oa

# ⑥ 合并（唯一入口；跨仓同样走它）
cd /srv/git/worktrees/dev-tcpc/main
ZW_TASK_SPACES=TASK,OA bash scripts/dev-merge.sh TASK-xxxx \
  --repo /srv/git/worktrees/dev-tcpc-oa/main --space OA \
  --ledger-repo /srv/git/worktrees/dev-tcpc/main
```

## 2. 硬约束

- **唯一台账**：主题仓 `AUTONOMOUS_TASKS.md`；本仓**禁止**新建台账或 `agents/metrics.json`。
- **唯一角色表**：主题仓 `agents/registry.yml`（19 角色）；角色 id 必须存在于其中。
- **插件代码门**：`bash scripts/ci-check.sh` 全绿才允许提交（`php -l` / 单测 / 契约 / 版本三方）。
- **契约**：`docs/contract-tcpc-oa.md` 是主题 ↔ 插件契约的唯一出处。
- **版本三方一致**：`VERSION` / `TCPC_OA_VERSION` / 插件头 `Version:`。
- **生产只读**：`/www/wwwroot/tcpc.org.cn` 禁止直接改；发版走 `scripts/plugin-switch-oa.sh`（`--dry-run` 先看，`--rollback <ts>` 回滚）。
- **主题内核不得反向依赖本插件**：主题侧有 `scripts/oa-kernel-boundary-check.php` 守着（反向依赖 0 容忍）。
- **preflight 的台账口径（2026-10-28 实测）**：`workbench/preflight.sh` 默认读**主题主工作区**台账；任务若已并入 dev，
  那里的状态是 `done` ⇒ 会被判"不能开工"。续做时把台账指向**任务工作树**（真实执行面）：

```bash
ZW_WORKBENCH_THEME=/srv/git/worktrees/dev-tcpc/task-TASK-xxxx bash workbench/preflight.sh TASK-xxxx "role:依据;…"
```

## 3. 与主题的分工（边界口径）

详见主题仓 `docs/migration/TASK-2306-中传网办公协同tcpc-oa分仓调研与迁移方案-20261028.md`：

| 归属 | 内容 |
| --- | --- |
| **留主题（内核）** | RBAC 引擎 `org-permissions.php`、网盘存储原语与目录铁律、条款**读侧**（data / consent / gate / notify / text-source）、文件控件 `file-open`/`doc-viewer`/`file-icon` |
| **进本仓** | 发文管理、收文管理、AI法务部（政策库/规则引擎/审查/文档中心/复核台）、AI秘书处、网盘文件中心 UI、组织架构业务（人员/角色/任期/机构档案） |
