# tcpc-oa 健康检查与告警（OA-2508）

> 目的：把「插件缺失 / 定时任务失效 / 发布漂移」三类故障变成**可定时巡检、可告警**的判据。
> 唯一入口：`scripts/health-oa.sh`（**只读**；不写库、不改文件、不发写请求）。

## 1. 检查项与告警类型

| check id | 判据 | 失败告警 | 说明 |
| --- | --- | --- | --- |
| `plugin_active` | `wp plugin is-active tcpc-oa` | `PLUGIN_MISSING` | 插件缺失/停用 ⇒ 六模块整体不可用 |
| `version` | 运行时常量 = 插件头 = 仓库 `VERSION` | `VERSION_DRIFT` | 任一不一致 = 半升/回滚残留 |
| `menus` | 六个后台菜单 slug 全注册（admin 用户触发 `admin_menu`） | `MENU_MISSING` | 以 admin 身份探测，避免 CLI 无 caps 假阴 |
| `symbols` | 关键符号 6/6（API/条款/政策/签署/组织写口/存储） | `SYMBOL_MISSING` | 插件模块未装载的直接信号 |
| `rest` | `GET /tcpc-oa/v1/health` 返回 `ok=true` | `REST_DOWN` | 服务端内部调用（不依赖登录 Cookie） |
| `urls` | `/login`、`/privacy-policy/`、`/userra/`、`/bhzc` 全 200 | `PUBLIC_URL_DOWN` | 合规页不可达 = 合规风险 |
| `bhzc` | `/bhzc` 正文 md5 与基线一致 | `BHZC_CHANGED` | 铁律 19；首次运行会**自动建立基线** |
| `release` | `tcpc-oa.released` 里的版本 == 生产运行时常量 | `RELEASE_DRIFT` | 「记录说发了、实际没发」的漂移 |
| `cron` | 5 个政策/条款 cron 事件已注册 | `CRON_MISSING` | 归档/通知/对账/到龄/签署提醒 |

> 首次运行时若 `/root/tcpc-release-locks/oa-bhzc.sha256` 不存在，脚本会写入当前值作为基线（仅此一处写盘）。

## 2. 用法

```bash
bash scripts/health-oa.sh              # 人读；全绿 rc=0，任一红 rc=1
bash scripts/health-oa.sh --quiet      # 只输出失败项与告警（cron 推荐）
bash scripts/health-oa.sh --json       # 每行一个 JSON（接监控系统）
bash scripts/health-oa.sh --no-alert   # 只打印告警行，不调用外部告警命令
bash scripts/health-oa.sh --self-test  # 夹具自测（11 项，不碰生产）
```

环境变量（CI/预发可覆盖）：`ZW_OA_HEALTH_WP_PATH`、`ZW_OA_HEALTH_BASE`、`ZW_OA_HEALTH_REPO`、
`ZW_OA_HEALTH_BHZC_BASELINE`、`ZW_OA_HEALTH_RELEASE_RECORD`、`ZW_OA_ALERT_CMD`。

## 3. 告警接入（二选一）

### 3.1 cron（最简单）

```cron
# 每 5 分钟巡检；失败时脚本自身把告警正文通过 ZW_OA_ALERT_CMD 送出
*/5 * * * * ZW_OA_ALERT_CMD='mail -s "[tcpc-oa] 健康告警" ops@example.com' /usr/bin/bash /srv/git/worktrees/dev-tcpc-oa/main/scripts/health-oa.sh --quiet >> /var/log/tcpc-oa-health.log 2>&1
```

### 3.2 systemd timer（推荐生产）

```ini
# /etc/systemd/system/tcpc-oa-health.service
[Unit]
Description=tcpc-oa health check
After=network-online.target

[Service]
Type=oneshot
Environment=ZW_OA_ALERT_CMD=/usr/local/bin/tcpc-alert-send
ExecStart=/usr/bin/bash /srv/git/worktrees/dev-tcpc-oa/main/scripts/health-oa.sh --quiet
```

```ini
# /etc/systemd/system/tcpc-oa-health.timer
[Unit]
Description=Run tcpc-oa health check every 5 min

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
Unit=tcpc-oa-health.service

[Install]
WantedBy=timers.target
```

`/usr/local/bin/tcpc-alert-send` 从 **stdin** 读告警正文，投递到实际通道（邮件 / 企业微信 / 钉钉 / 监控平台），
例如：`curl -sS -X POST "$WEBHOOK" -H 'Content-Type: application/json' --data-binary @-`。

## 4. 退出码与接入纪律

- `0` 全绿；`1` 有失败项（已逐项打印 `ALERT|<TYPE>|`）；`2` 用法/环境错误。
- **只读**：本脚本不写 WordPress 数据、不改插件目录、不发写请求；唯一写盘是首次建立 `/bhzc` 基线。
- **不复制判据**：发版冒烟仍在 `scripts/plugin-switch-oa.sh`；本脚本是**运行期巡检**，两者互补，不互相替代。
- 告警去重/抑制由告警通道负责（例如连续 N 次失败才升级），脚本只保证「每次失败都如实报」。

## 5. 与发版流程的关系

| 阶段 | 命令 | 目的 |
| --- | --- | --- |
| 发版前 | `plugin-switch-oa.sh --dry-run` | 门禁与计划 |
| 发版中 | `plugin-switch-oa.sh` | 版本/主题兼容/结构冒烟/回滚 |
| 发版后 | `health-oa.sh` | 运行期确认（含 `/bhzc` 基线漂移、发布记录漂移） |
| 日常 | cron/systemd 定时跑 | 三类故障告警 |
