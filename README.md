# 中传网办公协同（tcpc-oa）

中传网（`tcpc.org.cn`，WordPress + 宝塔）的**办公协同插件**：发文管理、收文管理、AI法务部、AI秘书处、
网盘文件中心、组织架构业务。

- 本仓 = **插件仓**，仓根 == 插件根；生产落点 `/www/wwwroot/tcpc.org.cn/wp-content/plugins/tcpc-oa`（布局期未部署）
- 唯一权威仓：`/srv/git/repositories/tcpc-oa.git`；GitHub 私有镜像 `qq866036-jpg/tcpc-oa`（待用户建仓 + deploy key）
- 主工作区：`/srv/git/worktrees/dev-tcpc-oa/main`；任务工作区 `…/task-OA-xxxx`
- **不复制**主题仓的 19 角色 / 台账 / metrics / 工作台页；卫星接入见 [`workbench/README.md`](workbench/README.md)

## 治理与边界

- 工作区指令：[`AGENTS.md`](AGENTS.md)
- 主题 ↔ 插件契约（唯一出处）：[`docs/contract-tcpc-oa.md`](docs/contract-tcpc-oa.md)
- 迁移方案与分期（主题仓）：`docs/migration/TASK-2306-中传网办公协同tcpc-oa分仓调研与迁移方案-20261028.md`
- 内核边界口径（主题仓）：`docs/oa-kernel-boundary.json` + `scripts/oa-kernel-boundary-check.php`

## 版本

`VERSION` 是发版唯一号源；`TCPC_OA_VERSION` 与插件头 `Version:` 必须与之一致；`MIN_THEME` 声明最低兼容主题版本。

## 安装 / 发版 / 回滚

骨架期**尚未部署**（生产 `plugins/` 下无本插件 ⇒ 站点零影响）。唯一发版入口：

```bash
bash scripts/plugin-switch-oa.sh --dry-run    # 先看将要做什么（不落盘）
bash scripts/plugin-switch-oa.sh              # 正式发版：版本三方 + MIN_THEME + 备份 + 原子交换 + 冒烟
bash scripts/plugin-switch-oa.sh --rollback <ts>   # 回滚（先 deactivate 再还原备份）
```

判据：① `VERSION == TCPC_OA_VERSION == Version 头`；② 在线主题 `VERSION >= MIN_THEME`；
③ 产物只来自 `git archive HEAD`（工作区脏则拒发）；④ 备份到 `/root/tcpc-oa-plugin-backups/pre-switch-<ts>/`；
⑤ 冒烟：插件已激活 + 运行时常量 == 发版版本 + `/login`、`/privacy-policy/`、`/bhzc` 均 200，且
**`/bhzc` 正文哈希与发版前一致**（铁律 19：章程官网页只读）。

> `--no-smoke` 仅供救援；**发布场景不得使用**（跳过冒烟不作为放行依据）。

## 目录（规划）

```
tcpc-oa.php          插件入口（当前仅声明版本，不加载业务）
includes/            业务模块（doc / receipt / policy / secretariat / org / files）——迁入前为空
docs/                契约、说明
workbench/           工作台卫星接入（load / preflight / finish / hooks）
tests/               本仓单测（迁入后）
scripts/             ci-check.sh / plugin-switch-oa.sh（后者待建）
```
