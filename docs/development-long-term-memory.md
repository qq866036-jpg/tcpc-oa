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
