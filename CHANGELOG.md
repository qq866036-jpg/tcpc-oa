# CHANGELOG

## [Unreleased]

### Added

- `scripts/plugin-switch-oa.sh`：P3 发版通道（版本三方 + `MIN_THEME` 兼容矩阵 + `git archive HEAD` 构建 +
  备份 + 原子交换 + 冒烟 + `--rollback`）。冒烟含 `/bhzc` 正文哈希前后一致（铁律 19）。
- **收文管理迁入（未启用）**：`includes/receipt/` 11 文件 / 6,675 行，与主题 `inc/receipt` **DIFF=0**；
  `includes/load-list.php` 登记模块 + 双钥匙开关（`enabled=false` 且 `TCPC_OA_ENABLE_RECEIPT` 未定义 ⇒ 零加载）。
- `scripts/verify-module-parity.sh`：模块迁入的 diff=0 唯一判据工具。

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
