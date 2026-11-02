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
# ⓪ 本仓建任务工作树（OA-2458：卫星仓专用入口；主题 workbench-worktree.sh 会把目录建到主题仓）
bash workbench/worktree.sh create OA-xxxx
cd /srv/git/worktrees/dev-tcpc-oa/task-OA-xxxx

# ① 主题侧建**载体单**工作树（唯一写台账的地方；纯插件任务用 TASK 号载体，见 §1.1）
cd /srv/git/worktrees/dev-tcpc/main
bash scripts/workbench-worktree.sh create TASK-xxxx
cd /srv/git/worktrees/dev-tcpc/task-TASK-xxxx
#   追加 ## TASK-xxxx 条目（含「修复范围」逐条路径）
bash scripts/workbench-preflight.sh TASK-xxxx "role:依据;…"

# ② 本仓开工门禁（交叉校验主题 marker；须在**任务工作树**里跑）
cd /srv/git/worktrees/dev-tcpc-oa/task-OA-xxxx
# OA-2455 起**不需要**手工 export：workbench/task-space.sh 按主题 `docs/ops/repos.yml` 自动取本仓号段
# （OA 仓 ⇒ OA）。仅老主题仓（无 zw_task_spaces_for_repo）或临时放宽时才显式设 ZW_TASK_SPACES。
bash workbench/preflight.sh OA-xxxx "role:依据;…"

# ③ 改代码 / 测试 / CHANGELOG
git add <显式路径…>
git commit -m "feat(oa): TASK-xxxx …"

# ④ 本仓质量门 + 收口
bash scripts/ci-check.sh
bash workbench/finish.sh "任务说明"     # 打印主题侧收口命令（带 --repo dev-tcpc-oa）

# ⑤ 提交（**正常** ：OA-2456 起 finish 已落 zw-finished-task，不再需要 --no-verify）
git commit -m "feat(oa): OA-yyyy …"     # 钩子在 marker 缺失时读 zw-finished-task（2h TTL）

# ⑥ 主题侧收口（唯一写 metrics / 台账状态 / 工作台页）
cd /srv/git/worktrees/dev-tcpc/task-TASK-xxxx
# 收口仓由号段推出（AGENT-0024）：OA 号段 ⇒ 注册表 name `dev-tcpc-oa`；传错值会判红。
# AGENT-0091 起**必须显式 --task OA-xxxx**（卫星单号）：marker 会按插件仓归因、记忆回写按任务工作树判定，
# 全程不需要 ZW_SPACE_MISMATCH_ALLOW（见 §1.2）。
bash scripts/workbench-finish.sh --task OA-xxxx --repo dev-tcpc-oa "任务说明"

# ⑦ 合并（唯一入口；跨仓同样走它）
cd /srv/git/worktrees/dev-tcpc/main
ZW_TASK_SPACES=TASK,OA bash scripts/dev-merge.sh TASK-xxxx \
  --repo /srv/git/worktrees/dev-tcpc-oa/main --space OA \
  --ledger-repo /srv/git/worktrees/dev-tcpc/main

# ⑧ 同步远端（OA-2457：dev-merge **只前移本地 ref**，不 push）
cd /srv/git/worktrees/dev-tcpc-oa/main
bash scripts/sync-remotes.sh            # dry-run：只打印两个远端待推清单
bash scripts/sync-remotes.sh --apply    # 快进推送 origin + github（非快进即拒绝，永不 force）
```

## 2. 硬约束

- **唯一台账**：主题仓 `AUTONOMOUS_TASKS.md`；本仓**禁止**新建台账或 `agents/metrics.json`。
- **唯一角色表**：主题仓 `agents/registry.yml`（19 角色）；角色 id 必须存在于其中。
- **插件代码门**：`bash scripts/ci-check.sh` 全绿才允许提交（`php -l` / 单测 / 契约 / 版本三方）。
- **契约**：`docs/contract-tcpc-oa.md` 是主题 ↔ 插件契约的唯一出处。
- **版本三方一致**：`VERSION` / `TCPC_OA_VERSION` / 插件头 `Version:`。
- **生产只读**：`/www/wwwroot/tcpc.org.cn` 禁止直接改；发版走 `scripts/plugin-switch-oa.sh`（`--dry-run` 先看，`--rollback <ts>` 回滚）。
- **远端同步（OA-2457）**：`dev-merge.sh` 只 `update-ref` 前移**本地** `dev`，**不含 push**；推送靠
  `scripts/sync-remotes.sh`（默认 dry-run、只快进、永不 force）。收口后不同步 ⇒ 远端落后
  （2026-11-02 实测 GitHub 落后 64 提交 / origin 落后 69 提交）。
- **主题内核不得反向依赖本插件**：主题侧有 `scripts/oa-kernel-boundary-check.php` 守着（反向依赖 0 容忍）。
- **preflight 的台账口径（2026-10-28 实测）**：`workbench/preflight.sh` 默认读**主题主工作区**台账；任务若已并入 dev，
  那里的状态是 `done` ⇒ 会被判"不能开工"。续做时把台账指向**任务工作树**（真实执行面）：

```bash
ZW_WORKBENCH_THEME=/srv/git/worktrees/dev-tcpc/task-TASK-xxxx bash workbench/preflight.sh TASK-xxxx "role:依据;…"
```

### 1.1 纯插件任务（`OA-xxxx`）必须用「主题载体单」

主题仓门禁**严格按号段归属**（`docs/ops/repos.yml`：theme=`TASK`、oa=`OA`）—— `OA-xxxx` 在主题仓跑 `workbench-preflight.sh` **必然 rc=1**（`zw_assert_task_space`）。因此纯插件任务的主题侧 marker 只能来自一个**主题号载体单**（`TASK-xxxx`）：

```bash
# ① 主题侧：建载体单（TASK 号），台账条目写在这个载体单里
cd /srv/git/worktrees/dev-tcpc/main
bash scripts/workbench-worktree.sh create TASK-xxxx
cd /srv/git/worktrees/dev-tcpc/task-TASK-xxxx
bash scripts/workbench-preflight.sh TASK-xxxx "role:依据;…"     # 产出 marker

# ② 插件侧：用 OA 号开工，显式告诉门禁「主题 marker 在哪个载体单」
cd /srv/git/worktrees/dev-tcpc-oa/task-OA-yyyy
# 号段由主题清单派生（OA-2455 起）；仅老主题仓（无 zw_task_spaces_for_repo）才需要显式设置。
export ZW_WORKBENCH_THEME=/srv/git/worktrees/dev-tcpc/task-TASK-xxxx   # 台账/marker 所在 worktree
export ZW_WORKBENCH_THEME_TASK=TASK-xxxx                              # 载体单号（缺省＝自身号）
bash workbench/preflight.sh OA-yyyy "role:依据;…"
```

- **缺省行为不变**：不设 `ZW_WORKBENCH_THEME_TASK` 时仍按「同号」找 `task-<本号>` 与 marker；
- **fail-closed**：设了载体单号但工作树/marker 不存在 ⇒ 直接报错并提示创建，**不静默回退**到同号；
- 载体单与 `OA-yyyy` 在台账里互相 `Refs`；M1/M2/M3 仍按「一仓一号」判。

### 1.2 卫星单收口（已修复 · AGENT-0091）：任务工作树里做完即可直接收口

**历史缺口（OA-2458 实测，已修）**：任务工作树里提交后，主题侧 `workbench-finish.sh` 的长期记忆门禁曾判
「层 oa 未回写」，因为主题 `scripts/long-term-memory-gate.py` 只读**清单登记的仓路径（主工作树）**做
`git -C <repo> diff dev...HEAD`；同一条判据在插件侧（`--repo oa`、cwd=任务工作树）却是**通过**的。

**已修复（主题 AGENT-0091）**：同一判据现在认两个候选来源，且方向未放宽（两处都无改动仍判红）：

| 卡点 | 修法 |
|---|---|
| 记忆门禁看不见任务工作树 | 声明层除登记仓根外，再认同仓的 `<父目录>/task-<本号>`（`_same_repo` 按 git common-dir 判） |
| marker 归因取错（主题工作树里是**载体单**的 TASK 号） | 收口仓非 theme 时按号段取插件仓根，在 `<基座>/task-<本号>`、插件主工作树里解析本任务 marker（`scripts/lib/zw-marker.sh`，`zw-finished-task` 2h TTL），命中才以 `--marker-root` 传给 metrics；找不到仍拒绝 |

**标准收口步骤**（在**任务工作树**里做完即可，无需切回主工作树、无需应急通道）：

```bash
cd /srv/git/worktrees/dev-tcpc-oa/task-OA-xxxx
bash scripts/ci-check.sh
bash workbench/finish.sh "任务说明"                    # 落 zw-finished-task（提交期兜底，2h TTL）
git add <显式路径…> && git commit -m "… OA-xxxx …"

# 主题侧收口：显式 --task（卫星单号）+ --repo（注册表 name）
cd /srv/git/worktrees/dev-tcpc/task-TASK-xxxx
bash scripts/workbench-finish.sh --task OA-xxxx --repo dev-tcpc-oa "任务说明"
```

- `--task` 不能省：只传 `--repo dev-tcpc-oa` 时 finish 会按 marker 里的 TASK 号推出 `--repo theme` 并判红；
- 全程**不需要** `ZW_SPACE_MISMATCH_ALLOW=1`；该通道与 `ZW_QUEUE_OVERLAP_ALLOW=1` 仍保留（语义未改），但我仓卫星单不再依赖它们；
- 判别力回归：主题 `tests/test-long-term-memory-gate.php` ⑨/⑩（真 git 工作树正反例）、`tests/test-satellite-marker.sh`（6 态）。

#### 1.2.1 收口顺序（必须按此顺序，OA-2460 实跑踩过）

**一个 worktree 只有一个 marker，清 marker 不可逆** —— 主题 `workbench-finish.sh` 收口成功即删
`<gitdir>/zw-workbench-active.json`。卫星单与载体单**共用同一个主题工作树**，所以顺序反了会这样报错：

```
✗ 拒绝写入工作台记录：本 worktree 没有开工 marker（…/task-TASK-xxxx/zw-workbench-active.json）。
```

正确顺序（**先载体、后卫星**）：

| # | 在哪 | 动作 |
|---|---|---|
| ① | 插件任务工作树 | `workbench/finish.sh` → `git commit`（提交信息带 OA 号） |
| ② | 主题载体工作树 | `workbench-finish.sh --task TASK-xxxx --repo theme "说明"`（**先记载体单 metrics、消费载体 marker**） |
| ③ | 同一主题载体工作树 | `workbench-finish.sh --task OA-xxxx --repo dev-tcpc-oa "说明"`（此时不再需要载体 marker：marker 归因走插件仓） |
| ④ | 同上 | `ledger-state.py set` 把两条都翻 `done`，再提交载体分支（metrics/页/台账一并入库） |

- 反面教材（OA-2459 实测）：先跑 ③ 再跑 ② ⇒ ② 报「没有开工 marker」，只能把载体单状态临时退回
  `in_progress` 重新 preflight 取 marker 才补上 TASK metrics；
- ③ 依赖 ①② 的产物（插件仓的 `zw-finished-task` 与已提交的记忆回写），顺序不能倒。



## 3. 与主题的分工（边界口径）

详见主题仓 `docs/migration/TASK-2306-中传网办公协同tcpc-oa分仓调研与迁移方案-20261028.md`：

| 归属 | 内容 |
| --- | --- |
| **留主题（内核）** | RBAC 引擎 `org-permissions.php`、网盘存储原语与目录铁律、条款**读侧**（data / consent / gate / notify / text-source）、文件控件 `file-open`/`doc-viewer`/`file-icon` |
| **进本仓** | 发文管理、收文管理、AI法务部（政策库/规则引擎/审查/文档中心/复核台）、AI秘书处、网盘文件中心 UI、组织架构业务（人员/角色/任期/机构档案） |
