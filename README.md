# 中传网办公协同（tcpc-oa）

中传网（`tcpc.org.cn`，WordPress + 宝塔）的**办公协同插件**：发文管理、收文管理、AI法务部、AI秘书处、
网盘文件中心、组织架构业务。

- 本仓 = **插件仓**，仓根 == 插件根；生产落点 `/www/wwwroot/tcpc.org.cn/wp-content/plugins/tcpc-oa`
  （**已上线**：`0.1.27` / 2026-11-14，六个模块全部启用）
- **双远端**（OA-2457）：唯一权威裸库 `/srv/git/repositories/tcpc-oa.git`（`origin`）＋ GitHub 私有镜像
  `qq866036-jpg/tcpc-oa`（`github`，deploy key `/root/.ssh/github_tcpc_oa_ed25519`）。
  合并进 `dev` **只前移本地 ref**（`dev-merge.sh` 不 push）⇒ 收口后必须手工同步：
  `bash scripts/sync-remotes.sh --apply`（默认 dry-run；非快进即拒绝）
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

本插件**已在生产运行**（2026-11-14 起 `0.1.27`）。唯一发版入口：

```bash
bash scripts/plugin-switch-oa.sh --dry-run    # 先看将要做什么（不落盘）
bash scripts/plugin-switch-oa.sh              # 正式发版：版本三方 + MIN_THEME + 备份 + 原子交换 + 冒烟
bash scripts/plugin-switch-oa.sh --rollback <ts>   # 回滚（先 deactivate 再还原备份）
```

判据：① `VERSION == TCPC_OA_VERSION == Version 头`；② 在线主题 `VERSION >= MIN_THEME`；
③ **主题兼容门禁**：在线主题 `functions.php` 必须含「插件优先」条件加载守卫
（`function_exists('zw_policy_platform_cron_mask_webhook')`），否则交换后 policy 读侧会
`Cannot redeclare` ⇒ 直接拒发（2026-11-14 实测事故）；
④ 产物只来自 `git archive HEAD`（工作区脏则拒发）；⑤ 备份到 `/root/tcpc-oa-plugin-backups/pre-switch-<ts>/`；
⑥ 冒烟：插件已激活 + 运行时常量 == 发版版本 + `/login`、`/privacy-policy/`、`/userra`、`/bhzc` 均 200、
`/bhzc` 正文哈希与发版前一致 + **结构冒烟**（六后台菜单 slug / 关键模块符号 / API 清单 11 符号与
`api_version=1` / REST health 可路由）。

> `--no-smoke` 仅供救援；**发布场景不得使用**（跳过冒烟不作为放行依据）。
>
> **发版顺序（硬约束）**：**主题先发 → 插件后发**。主题必须先包含 9 个「插件优先 / 停用回退」
> 条件加载守卫（`zw_oa_org_data_*` 契约同理）；反向（新主题 + 旧插件）安全，正向（新插件 + 旧主题）fatal。
> 2026-11-14 两次实测（直发 fatal、关 policy 模块丢后台菜单）已否决「关模块绕过」。详见 `docs/contract-tcpc-oa.md`。

## 模块迁入（P2 · 严格 diff=0）

模块代码已全部迁入本仓并**在生产启用**（`includes/load-list.php` 的 `enabled=true` + 常量/option 双钥匙，
默认即启用、可一键回滚）。主题侧只保留 parity shim（插件优先 / 插件缺席 fail-closed）。

```bash
bash scripts/verify-module-parity.sh policy --theme /srv/git/worktrees/dev-tcpc/main --expect-theme-absent
```

已迁入并启用：`includes/doc/`（发文 27 文件）、`includes/receipt/`（收文 18）、`includes/files/`（网盘 2）、
`includes/secretariat/`（AI秘书处 7）、`includes/org/`（组织架构 6）、`includes/policy/`（AI法务部 15）、
`includes/api/`（OA API 清单）。契约消费层：`includes/org/org-contract.php`、`includes/api/oa-api.php`。

## 监控 / 健康检查（发布后与日常巡检）

```bash
wp --path=/www/wwwroot/tcpc.org.cn --allow-root plugin get tcpc-oa --field=version   # 应 == VERSION
wp --path=/www/wwwroot/tcpc.org.cn --allow-root plugin is-active tcpc-oa            # active 且目录可读
wp --path=/www/wwwroot/tcpc.org.cn --allow-root cron event list | grep -E "zw_(policy|legal)"  # 政策归档/通知
```

- 结构自检：`wp eval-file` 冒烟脚本（发版脚本内置：六菜单 / 模块符号 / API 清单 / REST health）。
- REST：`GET /wp-json/tcpc-oa/v1/health`（需 `read` 能力位，未登录 401 属正常）。
- **失败信号**：插件目录不可读 / 页面 500 / 六菜单缺任一 / REST 非预期 ⇒ 先
  `bash scripts/plugin-switch-oa.sh --rollback <ts>` 回滚，再排查。

## 目录

```
tcpc-oa.php          插件入口（版本声明 + UI/API 基础设施 + 按双钥匙装载模块）
includes/load-list.php  模块清单（目录 / 入口 / 开关 / 主题对应目录 / 启用状态）
includes/{doc,receipt,files,secretariat,org,policy,api}/
docs/                契约、说明
workbench/           工作台卫星接入（load / preflight / finish / hooks）
tests/               本仓单测
scripts/             ci-check.sh / plugin-switch-oa.sh / verify-module-parity.sh / sync-remotes.sh
```
