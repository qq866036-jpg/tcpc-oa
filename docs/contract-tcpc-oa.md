# tcpc-oa ↔ 主题 契约（唯一出处 · 骨架期指针）

> 主题侧同名文件（`/srv/git/worktrees/dev-tcpc/main/docs/contract-tcpc-oa.md` 若存在）只留指针，正文以**本文件**为准。

## 现状（2026-10-28 · 骨架期）

契约的**实现侧已在主题内落地**（`inc/oa/contract-legal.php`，TASK-2320 增量三~六），本仓尚未迁入业务代码，
因此本文件当前只登记**契约面**与版本握手；待 P2/P3 把 provider 落进本仓后，本文件补「插件实现清单」。

## 契约面

| 编号 | 符号 | 方向 | 缺位语义 |
| --- | --- | --- | --- |
| P-1 | `zw_oa_legal_clause_snapshot()` | 主题 ← 插件 | `available=false` + `reason`（`table_missing` / `accessor_missing` / `engine_missing`） |
| P-2 | `zw_oa_legal_pinned_clause_ids()` | 主题 ← 插件 | 同上 |
| P-3 | `zw_oa_branch_audit_records($branch_id=0,$limit=50)` | 插件 ← 主题 | `available=false` + `reason` |
| W-1 | `zw_oa_branch_review_decide($branch_id,$decision,$note='',$reviewer_id=0)` | 插件 → 主题 | `WP_Error('zw_oa_contract_unavailable')` |
| — | `zw_oa_api_version()` / `zw_oa_api_supports($symbol)` | 双向 | 版本握手（当前 `1`） |

## 插件侧（待实现，S2）

主题契约门已支持**插件 provider 优先**：

- `zw_oa_legal_plugin_clause_snapshot()` → `array{version,captured_at,clauses[]}`（已确认条款行）
- `zw_oa_legal_plugin_pinned_clause_ids()` → `string[]`

两者存在时主题自动优先使用（返回值 `source=plugin`），否则回退主题旧实现（`source=theme`）。
**签名变更＝破坏性变更**，须双仓同批 + 升主版本；主题侧 `docs/oa-frozen-surface.json` 已把 4 个契约 API 的
签名纳入冻结面（改一个字符即判红）。

## 只读对账

主题侧 `scripts/oa-contract-shadow-check.php`（`wp eval-file`，只读）比对「契约 vs 旧实现」，
输出 `OA_CONTRACT_SHADOW=ok|diff`，缺任一侧 rc=2 fail-closed。上线前与每次发版前必须为 `ok`。
