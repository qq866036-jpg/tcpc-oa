# CHANGELOG

## [Unreleased]

## [0.1.33] - 2026-11-18

本次上线 **OA-2528（C1）**：一级标题字重默认改「常规」。

- `fonts.h1.weight` 默认 `bold` → **`normal`** —— 一级层次「一、」的字体槽本身就是**黑体**（黑体本身即粗面），
  再叠 `font-weight:bold` 属**双重加粗**（笔画发糊）；GB §7.3.3 只要求「第一层用黑体字」。
- **影响面**：一级标题（`一、`）与**版头标记**（份号 / 密级 / 紧急程度，共用 h1 字重槽）变常规；
  **标题（`title`）字重不动**（小标宋口径另表）；「公文版式 → 一级标题字重」仍可一键改回「加粗」。
- 规范侧同步见主题仓 **CHG-500 / 版式规范 v1.78**（另含 C2：附件实体「仅存档」登记为 GB §7.3.7 偏离，无插件代码）。

### Changed

- **撰写页「编辑正文 / 版式预览」分栏可拖拽 + 常显整页 + 页头图标工具栏（OA-2529，用户 2026-11-18 截图指令）**：
  - **分栏**：左右中间新增分隔条（鼠标 / 触摸 / 键盘 ←→ / Home 复位），左栏占比 38%~78%，松手即把当前比例写入
    `localStorage['zwDocComposerSplitPct']` ⇒ **下次进入就是这次拖出来的比例**；窄屏（<1100px）回落上下堆叠。
  - **固定**：预览右栏 `position:sticky`，**不随页面滚动**；卡片高度按可见顶边实时计算，滚到哪都铺满视口。
  - **整页**：内嵌预览新增 `paged` 模式（`zw_doc_preview_fit_script('paged')`：fit-page + 单页切换），
    每屏只显示**完整的一整页**（默认第 1 页），不再一屏半页。
  - **页头工具栏**：翻页 / 页码 / 缩小 / 缩放百分比 / 放大 / 适应页面 / 打印 / 另存为 HTML / 刷新 / 新窗口，
    全部**图标化**放在预览页头；预览框内只放纸面，说明与状态移到预览**下方**。
  - **零回归面**：默认 `continuous`（fit-width 连续多页）不变 —— 详情页 embed、版式设计器与「预览当前内容」
    新窗口仍是原口径；paged 预览打印前自动把全部页面复位成静态 A4。
  - 新增回归 `tests/test-doc-preview-viewer.php`（44 项）；`test-doc-preview-print-parity.php` 22 项仍全绿。

- **一级标题字重默认改「常规」（OA-2528 / C1，用户 2026-11-18 拍板）**：`fonts.h1.weight` 默认 `bold` → `normal` ——
  一级标题字体槽已是**黑体**（黑体本身即粗面），再叠 `font-weight:bold` 属**双重加粗**（笔画发糊）；
  GB §7.3.3 只要求「第一层用黑体字」。渲染层零改动（OA-2516 已把字重改为读配置）。
  **影响面**：一级标题（`一、`）与**版头标记**（份号 / 密级 / 紧急程度，共用 h1 字重槽）变常规；
  **标题（`title`）字重不动**（小标宋口径另表）；版式页仍可一键改回「加粗」。
  规范侧同步见主题仓 CHG-500 / 版式规范 v1.78。

## [0.1.32] - 2026-11-18

本次上线 0.1.31 之后的 dev 提交（两单，均走 ci-check 九项 + 双端收口 + 反向控制回归）：

- **OA-2525** 详情页「任命同步状态」新增**归档留痕**（归档媒体份数 / 归档问题 / 媒体问题）——
  这三个 meta 此前只写不读，出故障时无法区分「归档失败」与「媒体（证书图片）失败」；无数据不输出空壳。
- **OA-2526** 附件说明 ⇄ 附件文件名**软匹配**提示（`zw_doc_attach_name_mismatch()`：去扩展名 / 去手打序号 /
  去分隔符后双向包含即算一致），数量相等时给出「哪条名称找不到 + 去编辑页核对」的提示，**只提示不阻断**。

### Added

- **附件说明 ⇄ 附件文件名「软匹配」提示（OA-2526）**：建单时在「条数一致」的基础上再比**名称**——
  新增唯一判据 `zw_doc_attach_name_mismatch($notes, $file_names)`（`doc-body-model.php`，纯函数可单测）：
  双方归一化（去扩展名 / 去手打「附件：」「序号」/ 去空白与分隔符 / ASCII 小写）后**双向包含即算一致**，
  因此 `预算明细表` 与 `预算明细表_v2.pdf`、`扫描件-预算明细表.pdf` 都算匹配（避免硬匹配的误报噪音）；
  找不到时给一句**可改正**提示「附件说明「X」在附件里找不到同名文件 —— 请在编辑页核对名称后再用印」，
  **只提示不阻断**；仅在数量相等时比对（数量不等已有条数提示，避免双重噪音）；最多列 3 条防刷屏。

### Changed

- **任命归档留痕可见化（OA-2525）**：详情页「任命同步状态」卡新增**归档留痕**一行 ——
  归档媒体份数 + 归档问题 + 媒体问题。这三个 meta（`_zw_doc_appoint_doc_media` /
  `_zw_doc_appoint_archive_error` / `_zw_doc_appoint_media_error`）此前**只写不读**，
  出事时无法区分「归档失败」还是「媒体（证书图片）失败」。三项全空则整段不输出（不留空壳），
  只读既有值、不改任何同步与归档逻辑；`_zw_doc_pdf_updated_at` 仍不展示（与完成文件版本表的
  「生成时间」同信息冗余）。

## [0.1.31] - 2026-11-18

本次发版上线 0.1.30 之后的全部 dev 提交（三单，均走 ci-check 九项 + 双端收口 + 反向控制回归）：

- **OA-2521** 配置漂移门禁 `scripts/config-drift-gate.py` 接入 `ci-check` 第 ⑨ 项（数据字典 ⇄ 源码、
  版式页 63 个 path ⇄ 渲染层；豁免清单只降不升）+ 数据字典四处纠错（标题/附件说明控件类型、
  页码控件与失效 reader、密级 reader 指向从未存在的 `secret_split()`）+ 两条死配置接线
  （`sign.date_no_leading_zero` 生效、`footer.default_mode` 作页码默认）；
- **OA-2522** 冗余字段收口：`meta.doc_type` 线上也写入（与导入件同口径）、密级/期限结构化双写
  （`secret_level` / `secret_period`，回填优先结构化、纸面仍用合成串）、详情页显示**法务取证**与
  **同版本解锁记录**（此前只写不读）；
- **OA-2523**（并发会话）详情页「下一步」按目标卡片存在性守卫：无正文时回落「纸面要素」，消除死锚点。

### Changed

- **冗余字段收口（OA-2522）**：
  - **`meta.doc_type` 线上也写入**（取文种简称，与导入件 `$entry['type']` 同口径）——
    此前只有导入路径写、线上不写，虽任命类型判定有「文号兜底」不算功能缺陷，但两个入口写法不一致，
    排查时极易误判「线上任命不同步」；
  - **密级/期限结构化**：新增 `meta.secret_level` / `meta.secret_period` 双写；**回填优先读结构化键**，
    仅存量（无结构化键）回落解析 `meta.secret` 合成串；**纸面仍用合成串**（版头「密级★期限」口径零变化）；
    数据字典「密级/保密期限」的 `meta` 指向随之更新为结构化键。
- **留痕字段可见化（OA-2522）**：
  - 详情页「法务审核」卡新增**法务取证**一行（参与判定条款源 / 未取到条款数 / 是否截断 / 缺口说明），
    此前 `_zw_doc_legal_clause_*` 只写不读（写而不读等于没留痕）；
  - 详情页「用印」卡新增**同版本解锁记录**（解锁时间 + 操作人），此前 `_zw_doc_edit_unlocked_*` 同样只写不读；
  - 两处均**无数据不输出**（不留空壳），不改任何门禁判定。

### Added

- **配置漂移门禁 `scripts/config-drift-gate.py`（OA-2521）**，接入 `ci-check` 第 ⑨ 项（现共九项）：
  - 判据 1｜**数据字典 ⇄ 源码**：字典每行 `control` 里的 `name=xxx` 必须在源码里以真实控件出现
    （整行注释先剔除，防"注释骗过门禁"）；`reader` 里的 `zw_*()` 必须真实存在；
  - 判据 2｜**版式页 ⇄ 渲染层**：`doc-layout-designer.php` 的 63 个 `path` 必须命中
    「整键 `get()/gap_lines()`」「渲染通道按末段取值」「动态拼键模式」「具名豁免」之一；
    豁免清单 `scripts/config-drift-allow.txt` 只降不升（源码已能命中的陈旧豁免同样判红）；
  - 反向控制：`--root` 指向夹具仓（错 reader / 幽灵控件 / 死 path）必须判红并点名。

### Fixed

- **数据字典四处登记与源码不符（OA-2521）**：
  - 「公文标题」控件：`input[type=text]` → **`textarea`（3 行）**（TASK-2014 起可手动折行）；
  - 「页码方式」控件：`radio` → **`select`**；reader 由已删除的 `zw_doc_render_html()` → **`zw_doc_render_pageno_html()`**；
  - 「附件说明」控件：`input[type=text]` → **`textarea`**；reader → **`zw_doc_body_attach_plan()`**（OA-2515 唯一出处）；
  - 「密级 / 保密期限」reader：`zw_doc_composer_secret_split()`（**该函数不存在**）→ **`zw_doc_composer_secret_parse()`**。
- **两条死配置接线（OA-2521）**：
  - `sign.date_no_leading_zero`（版式页「成文日期月日不补零」）此前**全仓 0 处读取**（永远不补零），
    现由 `zw_doc_iso_to_cn_date()` 读取：关掉即输出 `2026年10月04日`；
  - `footer.default_mode`（页码默认模式）此前有键无读取、撰写页写死 `standard`；
    现作为「未登记 `page_mode`」时的回落值（撰写页默认选中 + 渲染层回落，非法值回落 `standard`）。

## [0.1.30] - 2026-11-18

本次发版上线六单（全部走 ci-check 八项 + 双端收口 + 反向控制回归）：

- **OA-2514** 新建发文补「附注」「签发人」两项公文要素（含 `signer` 落库映射）；
- **OA-2515** 附件说明国标化：系统补「附件：」+顺序号 + 悬挂对齐（HTML/DOCX 同源）+ 条数一致性提示；
- **OA-2516** 分页估算补标题上/下间距 + 抄送回行悬挂 + 字重改由「公文版式」决定（含 OA-2515 的 Warning 追溯修复）；
- **OA-2517** 「标题上/下间距」进公文版式页（默认沿用 1 行）；
- **OA-2518** 详情页预览统一为 PDF 阅读器式（与打印同源 1:1）+ 误打印防护；
- **OA-2519** 详情页流程可读化：步骤条 + 「下一步」主行动 + 全量锚点导航（不搬动既定 IA）。

### Changed

- **详情页流程可读化：步骤条 + 「下一步」主行动 + 全量锚点导航（OA-2519）**：
  详情页区块顺序（概览 → 纸面要素 → 流转 → 完成文件 → 任命同步 → 版式预览 → 法务 → 用印 → …）中，
  「完成文件在法务/用印之前」是 TASK-1382 ③ 的既定决策（首屏可见已完成文件），**不是错序**；
  真正缺的是页面没说明真实顺序与前置关系。本单**不搬动卡片**，改为显式化：
  - 首屏新增**流程步骤条**：① 审核 → ② 法务 → ③ 用印 → ④ 完成文件，已完成置绿、未达置灰并写前置；
  - 首屏新增**「下一步」**：按状态给唯一推荐动作与按钮（未审核⇒去审核 / 未过法务⇒去法务 /
    未用印⇒去用印 / 未生成⇒去生成 / 全完成⇒查看完成文件）；
  - **锚点导航覆盖全部区块**（新增 版式预览 / 流转记录），并逐项按目标存在性渲染 ⇒ 无死锚点；
  - 「完成文件」未用印时标题补「— 需先完成「用印」，用印后才能生成」；
  - 回归 `tests/test-doc-detail-flow-nav.php`（31 项；撤回本次改动后 18 项判红）。

- **详情页预览统一为「PDF 阅读器式」+ 打印与预览显式对齐（OA-2518）**：
  - 详情页「版式预览」改为 **iframe 内嵌 `?embed=1` 的打印视图**：同一个渲染器、同一个
    `zw_doc_build_render_args()`、同一份 `zw_doc_preview_fit_html()` 缩放脚本（按宽等比 + 多页纵向连续 +
    页间留缝 + 可滚动）⇒ 与打印 A4 **1:1 同源**，多页不再溢出/重叠；
  - **删除**旧的 `zw_doc_render_preview_html()`（62% `transform:scale()` + 固定单页高盒子）：
    `transform` 不改变布局高度、盒子高度只等于一页且无 overflow 规则 ⇒ 多页公文从第 2 页起视觉溢出卡片，
    这是「预览混乱 / 和打印对不上」的根因；
  - 预览标题给出「**A4 1:1 · 共 N 页**」（页数取同一分页估算），并写明**不要直接 Ctrl+P**；
  - 详情页加**误打印防护**：`@media print` 隐藏全部后台内容，改为输出一句
    「这是后台管理页，不是公文件，请用『打印 / 另存为 PDF』（A4 1:1）」；
  - 回归 `tests/test-doc-preview-print-parity.php`（22 项；撤回本次改动后 5 项判红）。

### Added

- **「标题上/下间距」进公文版式页（OA-2517）**：`title.gap_below_rule_lines`（红线→标题）与
  `title.gap_below_title_lines`（标题→正文，仅无主送机关时）在版式表里一直存在、也一直被
  渲染 CSS 与分页估算读取，但版式页**没有入口** ⇒ 想按国标「红线下空二行」只能改代码。
  现做成可配（数值 0~4 行，单位正文行），**默认沿用 1 行**（纸面观感零回归）；改设置后标题外边距与
  分页估算**同时**生效（`28pt ↔ 56pt`）。字段说明写明「国标 §7.3.1 为 2 行、协会口径默认 1 行」，
  防止下次再被当成缺陷重报。规范侧同步见主题仓 CHG-499 / 版式规范 v1.77。

### Fixed

- **分页估算补标题上/下间距 + 抄送悬挂对齐 + 字重改由版式决定（OA-2516）**：
  - **分页**：首页占用此前只算「版头 + 标题行数 + 主送」，漏了 CSS 里 `.tco-doc-title` 的上/下间距
    （`title.gap_below_rule_lines` 与无主送时的 `title.gap_below_title_lines` 各 1 正文行）⇒ 首页偏乐观
    1~2 行（28~56pt ≈ 9.9~19.8mm），正文密集时把末页/版记顶出纸面。现按同一批版式常量补算；
    有主送机关时标题下间距由主送承担，**不重复计**（差分判据：2+2 vs 0+0 首页块数差 4，有主送时差 2）；
  - **抄送**：`.tco-doc-record-cc{padding-left:3em;text-indent:-3em}` ⇒ 回行与「抄送：」后的首字对齐（GB §7.4.1）；
  - **字重**：新增唯一出处 `zw_doc_render_font_weight($slot)`，标题 / 一级标题 / 版头标记改读
    `fonts.<slot>.weight` —— 版式页自 TASK-2007 起就能选「标题字重 / 一级标题字重」，但渲染层把
    `font-weight:bold` **写死** ⇒ 改设置**不生效**；默认值保持原样（观感零回归），非法值回落 normal；
  - **追溯修复 OA-2515**：附件说明渲染在无 `attach_note` 的公文上会抛「Undefined array key」Warning（先判存在再取）。

### Changed

- **附件说明按国标重组（OA-2515，行为变化）**：GB/T 9704-2012 §7.3.4 ——
  - 「附件：」与阿拉伯数字顺序号改由**系统生成**（唯一出处 `zw_doc_body_attach_plan()`，HTML / DOCX 同源）；
    撰写页只需**填名称**，此前手打的「附件：」「1.」会被自动剥掉后重编号（漏打前缀、跳号不再发生）；
  - **多条**附件：各条序号左缘对齐；名称较长**回行与名称首字对齐**（HTML 用 mark/name 两段 flex，
    DOCX 用「左缩进 + 负首行缩进」表达悬挂，`zw_doc_docx_para()` 放开负值）；
  - 「正文下空一行」**只发生一次**（旧实现每行都吃 `margin-top` ⇒ 多条附件之间多出整行空白）；
  - 建单后比对「附件说明条数 ⇄ 实际上传附件数」，不一致追加提示
    「附件说明写了 N 条，实际上传 M 个 —— 请在编辑页核对后再用印」；
  - **存量兼容**：已存「附件：1.视频拍摄方案」这类行解析后逐字还原；只有「纯名称、无前缀」的旧行
    会在纸面上补出「附件：」（这正是本单要修的国标不一致）；
  - 回归 `tests/test-doc-attach-note-gb.php`（28 项；撤掉两通道渲染改动后 10+ 项判红）。

### Added

- **新建发文补「附注」「签发人」两项公文要素（OA-2514）**：渲染层与 DOCX 早已支持（附注自动圆括号、
  左空二字、排在成文日期下一行；签发人与发文字号同行、文号居左空一字、签发人居右空一字，
  并有版式开关 `header.show_signer`），但撰写页**没有输入控件**，且 `zw_doc_composer_body_from_post()`
  的 meta 映射**漏了 `signer`** ⇒ 这两项在「新建发文」里永远为空，纸面永远缺（GB/T 9704-2012 §7.3.5 / §7.2.4）。
  - 第 1 步「版头」区新增「签发人」输入（`zwc_signer`），说明写清「上行文（请示、报告等）应标注；留空则纸面不显示」；
  - 第 1 步「落款与日期」区新增「附注」输入（`zwc_note`），说明写清「圆括号由系统自动补齐、左空二字」；
  - `body_from_post()` 补 `'signer' => $get('zwc_signer','')` 映射；
  - 两项留空时纸面**零变化**（不产生空段落、不残留「签发人：」冒号）；
  - 回归 `tests/test-doc-note-signer.php`（16 项；对修复前代码 6 项判红，含「meta.signer」「表单控件」「映射行」三条守卫）。

### Fixed

- **公文标题手动换行不生效（OA-2513）**：`zw_doc_body_normalize()` 对 `meta.title_lines`（TASK-2014 手动断行）
  **不幂等** —— 第一遍把手动断行写成「`meta.title` 单行（空格拼回）+ `title_lines` 数组」，第二遍再归一时
  `meta.title` 已无换行可切 ⇒ `title_lines` 被重置为 `[]`。而写读链路（`zw_doc_update_body()` / 建单 /
  `zw_doc_body_parse()` / `zw_doc_body_to_json()`）都在再归一 ⇒ 断行在落库与读库各丢一次，
  渲染层只能落到自动回行（TASK-1342）。表现：撰写页内嵌预览有手动换行、保存后没有；编辑态回填同样失效。
  - 修法：`title_lines` 改为受控数组专用分支；无换行时**沿用入参 `title_lines`**（逐行清洗、去空行、≤3 行），
    含换行时仍以原文为准（作者改断点即时生效），两者都没有才为空数组；
  - 恢复文件头声明的幂等契约 `normalize(normalize(x)) === normalize(x)`，回归 `tests/test-doc-title-lines.php`
    （15 项；对修复前代码 9 项判红，含"落库/读回后仍有两行"与"手动断行 ≠ 自动回行"的反向控制）；
  - **「公文版式」三个折行键（`title.wrap_chars` / `wrap_mode` / `wrap_min_chars`）与自动回行算法零改动**
    —— 诊断结论：本缺陷不是版式设置冲突，只在 `title_lines` 为空时才会用到这些键。
  - 存量影响：已落库的 10 篇正文模型里 `title_lines` 全为空（生产实证），修复后**重新保存一次**即恢复手动断行；
    旧记录标题里由「空格拼回」留下的那一个空格不做批量清洗（不在本单范围内）。

## [0.1.29] - 2026-11-15

### Added

- **档案处置打印：销毁清册 / 清退清单（OA-2511）**：收文台账「档案处置」页新增两个打印入口。
  - 销毁清册：编号/标题/来文单位/归档盒号/年度/保管期限/到期日/批准人/销毁日期 + 合计件数 +
    **按年度小计** + 制表/审核/批准签字栏；
  - 清退清单：编号/标题/来文单位/归档盒号/清退日期/经办人；
  - 打印友好 HTML + `@media print`（A4 横版），不引第三方 PDF 库；`tcpc_perm_doc` + 按类型绑定 nonce；**只读**；
  - 页脚固定声明「本清册由系统生成，系统未删除任何文件或记录」。

## [0.1.28] - 2026-11-14

### Added

- **REST 只读端点（OA-2505）**：`tcpc-oa/v1` 新增 `GET /legal/{doc}`、`GET /doc/inbox`、`GET /files/{id}`
  （全部 `read` 能力位、无写路由）：
  - `legal/{doc}`：当前生效条款（`version_no/effective_at/body_hash/body_html`）；内部文档 404、`bhzc` 404（铁律 19）、
    读源缺失 503；
  - `doc/inbox`：当前用户收文；查询器缺失 ⇒ 200 + `available=false` + 空列表（degrade=empty）；
  - `files/{id}`：文件**脱敏元数据**（无 path/url/owner_user_id）；非本人且非管理员 403、存储缺失 503。

### Changed

- **发版冒烟扩面（OA-2506）**：结构冒烟新增「三条新只读路由必须真实注册」断言
  （`rest_get_server()->get_routes()` 校验），防止 manifest 写了路由却没注册的静默漂移；
  `tests/test-release-lock.sh` 29→**41 项**全绿。

## [0.1.27] - 2026-11-14

### Release

- **发版范围**：把 2026-11-09（0.1.26 / `953c6ed`）以来的 25 个提交一次上线，生产当前落后本仓一整代：
  - **AI法务部全量迁入**（OA-2498 / TASK-2814 S1+S2）：读源/版本模型/内容规则/判定引擎/签署/拦截/通知/定时
    共 14 文件进 `includes/policy/`；主题同名副本改为「插件优先、停用回退」条件加载。
  - **组织架构数据面契约**（OA-2500 O2a / OA-2501 O2b / 主题 TASK-2820·2821）：字典与人员 meta 经
    `zw_oa_org_data_*` 契约读写；插件内直连 option = 0、直连人员 meta 写 = 0（旧主题自动回退）。
  - **OA API 互通**（OA-2499 / TASK-2818）：`tcpc_oa_api_manifest()` 11 符号 + 版本握手 + 结构化降级 +
    只读 REST `GET /wp-json/tcpc-oa/v1/manifest|health`；消费登记表与门禁（观察期）。
  - **卫星仓 UI 独立**（OA-2491）、**发版互斥**（OA-2494）、**界面文案门禁**（OA-2497）、
    **CSV 导出 PHP 8.4 兼容**（OA-2495）、收口/归档类修复（OA-2492/2493/2490）。
  - **发版通道扩面（本单 OA-2502）**：`plugin-switch-oa.sh` 冒烟新增「六菜单可达 + 模块符号 + API 清单结构 +
    REST health + `/userra/`」，改变此前只验登录页/隐私页/章程哈希的窄口径（TASK-2306 §10 G3）。
- **回滚**：`bash scripts/plugin-switch-oa.sh --rollback <ts>`（发版时自动备份到
  `/root/tcpc-oa-plugin-backups/pre-switch-<ts>/`）。
- **发版前置（2026-11-14 实测·必须遵守）**：本版**必须主题先发**。0.1.27 首次直发（在线主题仍是旧版、
  主题 `inc/policy-platform/` 副本无条件加载）在目录交换后触发
  `Cannot redeclare zw_policy_platform_cron_schedules()`（两处都在第 172 行），冒烟第一步即失败；
  已用本次备份回滚到 0.1.26（插件目录与备份逐字节一致、站点各 URL 200、六菜单在位）。
  为此本单同批加固发版通道：① 新增「主题兼容门禁」——在线主题 `functions.php` 缺
  `function_exists('zw_policy_platform_cron_mask_webhook')` 守卫即**拒发**（在任何生产写入之前）；
  ② 修复 `--rollback` 在备份无 `VERSION`（export-ignore）时把版本记录写成 `0.0.0` 的缺陷（改读插件头）。
- **分步发版路径否决（OA-2503 实测）**：曾尝试「先把 `tcpc_oa_enable_policy` 置 0 再发插件」以绕开主题版本，
  但实测**policy 后台菜单/页面一并消失**（主题侧 `inc/admin/policy-platform-admin.php` 是 shim，插件启用才出菜单），
  属功能降级 ⇒ 否决并回滚（生产保持 0.1.26 + 开关 1）。门禁保持 fail-closed：**主题缺守卫即拒发**，
  不再提供「关模块即放行」的口子。
  同批修复发版冒烟的判别键：manifest 的版本字段是 `api_version`（不是 `version`），旧写法会误报「API 清单版本不是 1」。

### Added

- **政策正文内容规则（OA-2498 / TASK-2814 S1）**：新增 `includes/policy/legal-content-rules.php`
  （隐私政策必备章节 / 产品文档禁用词 / 22100 字上限；用户协议 30000 字护栏；未登记文档跳过）。
  文档中心「新建版本」「确认生效」前置校验，不通过回执 `content_invalid` 并逐条列原因。
  回归：`php tests/test-oa-legal-content-rules.php`（18 项）。

- **OA API 互通（OA-2499 / TASK-2818）**：新增 `includes/api/oa-api.php` —— 唯一能力清单
  `tcpc_oa_api_manifest()`（11 符号 / provider / version / degrade / consumers）、版本握手、
  `tcpc_oa_api_call()` 结构化降级、只读 REST `GET /wp-json/tcpc-oa/v1/manifest|health`。
  回归：`php tests/test-oa-api-manifest.php`（23 项）。
- **parity 登记补齐**：policy 14 文件 + org 5 文件（`org-permissions.php` 按 Q2 exclude）。
- **组织架构 O2a（OA-2500 / TASK-2820）**：新增 `includes/org/org-contract.php`，组织字典
  （departments/duties/roles/data_model）改走主题 P-8 契约（`zw_oa_org_data_dict_get/save`，版本 >=1）；
  旧主题无契约时回落直读既有 option（不新增键）。`org-repository.php` 内 16 处直连 option 全部收口，
  `get/update/delete_option(ZW_ORG_*)` = 0。回归：`php tests/test-oa-org-contract.php`（13 项）。

- **组织架构 O2b 人员 meta 写口（OA-2501 / TASK-2823 / 主题契约 TASK-2821）**：`org-contract.php`
  新增 `zw_oa_org_person_meta_set/delete` —— 优先走主题 `zw_oa_org_data_person_meta_set`
  （`zw_person_` 前缀 + 标量 + `person_id>0`，key ≤64），旧主题无写口时回退
  `update_post_meta/delete_post_meta`（不新增键、不改语义）。`org-repository.php` 内
  **30 处写入 + 1 处删除**全部收口，直连 `update_post_meta/delete_post_meta` = 0。
  回归：`php tests/test-oa-org-contract.php`（38 项：回退/契约双路径 + 空串与 0 放行 +
  非前缀/数组/对象/非法 ID 拒绝 + 源码零直连）。

### Changed

- **读源/版本模型迁入本仓并优先装载（OA-2498 / TASK-2814 S1）**：`policy-platform-data` /
  `legal-text-source` / `logic/legal-text` / `legal-admin-bridge` 已复制进 `includes/policy/`（首版 DIFF=0，
  仅两处有意差异：归档写 `uploads/tcpc-legal/`、审计缺位 fail-closed 不反向读主题）。
  插件 `policy` 模块装载时先装读源再装管理面；主题侧同名副本保留为**插件停用回退**，
  由 `functions.php` 的 `function_exists` 条件加载跳过。回归：`bash scripts/ci-check.sh` 八项全绿 +
  `verify-module-parity.sh policy --expect-theme-absent`。

### Changed

- **判定引擎 / 签署 / 拦截 / 通知 / 定时迁入本仓（OA-2498 S2）**：`policy-module-map` / `policy-rule-engine` /
  `policy-platform-cron` / `legal-consent` / `legal-consent-gate` / `legal-consent-notify` 复制进
  `includes/policy/`（首版 DIFF=0），插件 `policy` 模块按依赖顺序装载；主题侧同名副本改为
  `function_exists` 条件加载（插件在线即跳过，停用即回退）。回归：`ci-check` 八项全绿 +
  `verify-module-parity.sh policy --expect-theme-absent`（插件 14 文件、主题仅 shim）。

## [0.1.7] - 2026-11-03

### Release

- **收文上线窗口（OA-2466）**：把 OA-2462（UI 对齐 + 详情页）、OA-2463（OCR 接入）、OA-2464（拟办/批示/传阅/催办/归档）
  三批合并代码发版上线；版本三方同步到 **0.1.7**。生产步骤按
  `docs/ops/发布窗口-OA收文OCR与办理后半段-执行清单-20261103.md` 执行（备份 → 发版 → OCR 五列 → 两表三列 →
  OCR 服务 → 开关 → 冒烟 → 抽检），每步带回滚点。

## [0.1.8] - 2026-11-03

### Fixed

- **上线热修（OA-2467）**：`zw_receipt_handle_missing()` 把 `zw_receipt_source_columns()` 返回的**关联数组**
  当列名列表用 `in_array()` 比对 ⇒ 恒判「缺列」，导致 0.1.7 生产上「拟办/批示/传阅/归档」三卡显示「尚未迁移」、
  待批示角标恒为 0（DB 里三列其实已加）。改为 `isset($cols[$col])`。
- `zw_ocr_missing_columns()` 增加 `$fresh` 透传：迁移脚本 `execute` 的收尾自检不再误报「仍缺列」。

## [0.1.9] - 2026-11-03

### Release

- **发版 0.1.9（OA-2469）**：上线 OA-2468「批示库 + 收文 20 秒自动签阅」。
  零数据库迁移；版本三方同步 0.1.8 → 0.1.9；上线后用一条测试收文做端到端验证
  （登记 → 自动批示「系统自动（批示库）」→ 20 秒后「自动签阅」+ 签名时间戳）。

## [0.1.10] - 2026-11-03

### Added

- **收文全文检索（含 OCR 正文）+ 台账 CSV 导出（OA-2470）**：
  - 检索框关键词现在同时匹配 **标题 / 来文字号 / 收文编号 / 来文正文（OCR 文本）**；
    `ocr_text` 列不存在时（未迁移的库）自动退回原三字段匹配，SQL 形状不变；
  - 台账操作栏新增「导出 CSV」：**按当前筛选**（来文单位/年份/关键词）导出，UTF-8 BOM（Excel 直接打开），
    字段：收文编号/来文单位/来文字号/标题/密级/收文日期/份数/办理状态/归档盒号/归档年度/页数/登记时间；
    上限 5000 行并在末行注明；`admin-post` + `tcpc_perm_doc` + 按筛选绑定的 nonce；
  - 导出**不含** OCR 正文与个人信息明细；
  - 顺带修 0.1.9 文案：时间线里系统自动行的「人」显示为「系统自动（批示库）/系统自动签阅」。

## [0.1.11] - 2026-11-03

### Added

- **呈批单 / 传阅卡打印（OA-2471）**：详情页新增「打印呈批单」「打印传阅卡」（新窗口）。
  - 打印友好 HTML + `@media print`（A4 竖版、15mm 页边距、正文 14px/行高 1.6、1px 表格线），
    走浏览器「打印 / 另存为 PDF」，**不引第三方 PDF 库**（避免中文字体与服务器依赖）；
  - 呈批单：来文要素 + 拟办意见 + 领导批示 + 承办办理单（部门/人/时限/状态/事项）+ 传阅签阅；
    传阅卡：来文要素简表 + 传阅对象/签阅时间 + 手写签批栏；
  - 鉴权：`tcpc_perm_doc` + 按「记录 ID + 单据类型」绑定的 nonce；**不含 OCR 正文与个人信息明细**；纯只读。

## [0.1.12] - 2026-11-03

### Added

- **自动催办（auto_urge）+ 自动派单（auto_dispatch）（OA-2472）**：
  - 自动催办：每日 08:30 扫描「未收口且时限在 N 天内（含已逾期）」的办理单，写一条
    `【系统自动催办】` 留痕（`system:auto-urge`）；**同一办理单同一期限点只催一次**；不改办理状态。
  - 自动派单：新登记收文按「部委 key | 承办部门 key | 天数」规则自动生成一张办理单
    （走既有 `zw_receipt_issue_create()`，不新写 SQL），并写一条 `自动派单` 审计留痕（`system:auto-dispatch`）；
    无规则/部门不存在/已有办理单 ⇒ 跳过不报错。
  - 两个开关**默认关**（先观察再开）；设置页新增「催办提前量」与「自动派单规则」编辑。
  - 零数据库迁移。

## [0.1.13] - 2026-11-03

### Added

- **自动归档（auto_archive）（OA-2473）**：每日 09:10 扫描「办理单全部收口（无单也算收口）且未归档」的收文，
  按模板生成归档盒号并写归档年度：
  - 年度 = 收文日期年（缺失/非法取当前年）；盒号模板可配（默认 `{year}-{org}-{seq}`，`seq` 按同年度归档数 +1、4 位补零）；
  - **盒号查重**：重复则换号重试 ≤20 次，仍冲突则跳过并留原因（不覆盖他人盒号）；
  - 写入走 `zw_receipt_archive_save()`（单行 UPDATE 三列）+ 一条 `archive` 审计留痕（`system:auto-archive`）；
  - 开关**默认关**；零数据库迁移。

## [0.1.14] - 2026-11-03

### Fixed

- **热修（OA-2474）：自动归档门禁收紧** —— 0.1.13 把「无办理单」也当收口，实测一次扫描把 3 张真实来文
  写上了测试盒号（`2026-cssw-0001` / `2026-mca-0003` / `2026-other-0004`）。已回滚那 3 行（`archive_*` 置 NULL）。
  本次改为：**办理单为 0 ⇒ 不归档**（`skipped:no_issue`）；确需归档无单收文时用 option
  `zw_receipt_archive_allow_no_issue=1` 显式允许（默认 0）。

## [0.1.15] - 2026-11-03

### Added

- **收文统计报表（OA-2475，纯只读）**：台账新增「统计」（`view=stats`，不新增子菜单）。
  - 四张指标卡：收文总量 / 已归档（归档率）/ 逾期办理单 / 待批示；
  - 四个维度横条表：按来文单位、按年度、按密级、办理单状态分布；
  - 筛选：年度 + 来文单位（与台账字典同源）；「导出统计 CSV」（按当前筛选，BOM，鉴权同台账）；
  - 口径写死在 `receipt-stats.php` 头部并显示在页面底部（逾期=未收口且时限早于今天；待批示=有拟办无批示）。

## [0.1.16] - 2026-11-03

### Added

- **收文通知接入主题通知中心（OA-2476）**：复用 `inc/notification-center`，**插件不存任何 Webhook 地址/密钥**。
  - 自登记 4 个企微事件（过滤器 `zw_notify_wecom_events`）：`oa_receipt_pending`（待批示）、
    `oa_receipt_overdue`（逾期）、`oa_receipt_urge`（催办）、`oa_receipt_archived`（归档）；
    后台「通知中心 → 企业微信 → 名称对应管理」即可为这些事件**选 Webhook 名称**；
  - 每日 08:40 摘要：待批示 / 逾期各合并成一条（前 5 件清单），**同一事件每天只发一次**；
  - 催办后、归档后各发一条（每收文每天最多一次）；
  - 站内：台账顶部新增「N 件办理单已逾期」提示（与既有「待批示」提示并列，纯只读）；
  - 总开关 `auto_notify` **默认关**；主题缺席时（无 `zw_notify_wecom_send_event`）静默降级为只做站内。

## [0.1.17] - 2026-11-03

### Fixed

- **统计页部委名回退（OA-2477）**：「按来文单位」遇到字典里没有中文名的 key 时，不再裸露 `cssw` 这类原始 key，
  改显示「未登记单位（cssw）」，并在页面底部提示去「收文管理 → 来文单位」补名后自动恢复。

## [0.1.18] - 2026-11-03

### Fixed

- **统计页来文单位名映射（OA-2478）**：`issuer` 列里既有 key（`cssw`）、也有中文名（`民政部`）、
  还有中文名带后缀（`中央社会工作部办公厅`），而字典是 `key => 中文名`。0.1.17 只按 key 查，
  反而把中文名判成「未登记单位」。现改为三级映射：① key 精确 → ② 中文名精确 → ③ 中文名互相包含（取最长）；
  都不中才回退「未登记单位（原值）」。

## [0.1.19] - 2026-11-04

### Changed

- **issuer 口径统一（OA-2479）**：统计/报表的来文单位名不再自写第二套映射，改为只走既有 canonical 反查
  `zw_receipt_org_key_from_issuer()`（TASK-1297，关键字表 + `zw_receipt_org_issuer_map` 过滤器），
  命中取字典中文名、未命中保留原值并标「未登记单位」。删除 OA-2478 引入的私有映射实现（重复口径下线）。

### Added

- **`scripts/receipt-issuer-audit.php`（只读）**：列出所有 distinct `issuer`、解析出的部委 key、件数；
  未命中项提示去补字典。`wp eval-file scripts/receipt-issuer-audit.php [--csv]`，未命中时退出码 1。

## [0.1.20] - 2026-11-04

### Fixed

- **统计按单位累加（OA-2480）**：同一部委的多个 `issuer` 变体（`cssw` 与 `中央社会工作部办公厅`）映射到同一显示名时，
  原实现是**赋值覆盖**导致少算（实测 3 件只显示 2 件）；改为累加。

## [0.1.21] - 2026-11-04

### Added

- **自动派单：规则建议 + 干跑预览（OA-2481）**：设置页新增「派单干跑预览」区块（**只读**）：
  逐件列出「解析部委 / 拟派部门 / 拟定时限 / 判定（会派单 / 已有办理单 / 无规则）」，
  并给出可复制的建议规则（`cssw→office/5天`、`mca→secretariat/5天`、`mct→secretariat/5天`、
  `party_upper→office/3天`；`self_rules`/`other` 不配 = 保持人工）。
  `auto_dispatch` 仍**默认关**；预览不生成任何办理单。

## [0.1.22] - 2026-11-04

### Added

- **派单通配规则（OA-2482）**：规则框支持 `*` 通配 —— 一行 `* | office | 5` 即「所有来文都派办公室、时限 5 天」；
  具体部委 key 的规则优先于 `*`。派单与干跑预览共用同一解析函数 `zw_receipt_auto_dispatch_rule_for()`。

## [0.1.23] - 2026-11-04

### Added

- **收文档案处置：保管期限 / 清退 / 销毁（OA-2483）**：收文台账新增「档案处置」（`view=disposal`，不新增子菜单）。
  - 保管期限按件设置（永久 / 30 / 10 / 5 年），到期日 = 收文日期 + 年限；
  - 到期清单：已归档（有盒号）+ 已到期 + 未销毁；
  - 清退：`需清退 / 已清退`（归还来文单位，**不删档**）；
  - 销毁：**前置门禁三条**（已归档 + 已到期 + 填批准人）才允许写「已销毁」；
  - **系统永不删除文件或记录**：只写 `zw_receipt_disposal`（append-only）+ `disposal` 审计留痕；物理删除由人线下做；
  - 新增表 `zw_receipt_disposal`（迁移脚本 `scripts/migrate-receipt-disposal.php`，dry-run/execute/verify）。

## [0.1.24] - 2026-11-04

### Fixed

- **热修（OA-2484）：处置表排序规则** —— 0.1.23 的 `zw_receipt_disposal` 被建成 `utf8mb4_unicode_520_ci`，
  而 `zw_policy_source.id` 是 `utf8mb4_general_ci`，到期清单的 JOIN 直接报 `Illegal mix of collations`。
  - 迁移脚本改为**跟随主表排序规则**建表；表已存在但规则不同时自动 `ALTER ... CONVERT`；
  - 到期清单**去掉跨表 JOIN**，先取到期处置行再逐件判已归档（不再受排序规则影响）。

## [0.1.25] - 2026-11-04

### Fixed

- **收文打印 / 统计页 5 张表补 canonical 横滑包裹（OA-2485）**：主题 `admin-h5-gate` 的「未包裹 0」判据实测
  `includes/receipt/receipt-print.php` 4 张、`includes/receipt/receipt-stats.php` 1 张未落 canonical。
  - 打印页 4 张表逐张套 `.zw-adm-table-wrap`，并在 `zw_receipt_print_css()` 内**透传**主题 canonical
    `zw_adm_ui_table_wrap_css()`（打印页不在后台外壳作用域内；不复制第二套声明）；
  - 统计页横条表套 canonical 容器（规则仍由收文台账页 `zw_receipt_admin_ui_page_open()` 透传）；
  - 零功能变更：不改列/列宽/取数/权限/路由/字段名与提交形状。

## [0.1.26] - 2026-11-09

### Changed

- **卫星仓后台 UI 全量独立（OA-2491 / 主题铁律 32 · CHG-466）**：用户 2026-11-09 拍板「独立仓用完全独立的
  样式 ui js」，本次把后台 UI 从主题命名空间整体迁到本仓 `tco-*`：
  - 类名/选择器/ID/CSS 变量全量改名（`zw-adm-*`→`tco-*`、`zw-doc-*`→`tco-doc-*`、`zw-org-*`→`tco-org-*`、
    `zw-sec-*`→`tco-sec-*`、`zw-fc-*`→`tco-fc-*`、`zw-receipt-*`→`tco-receipt-*`、`zw-policy-*`→`tco-policy-*`、
    `zw-secretariat-*`→`tco-secretariat-*`），双写别名一并去重；
  - 新增 `assets/css/oa-admin.css`（令牌来源注记 + `var(--tco-*, <令牌兜底>)`，零裸色值）与
    `assets/js/oa-admin.js`（宽表兜底包裹/ready 标记），本仓注册 enqueue（仅本仓后台页）；
  - 新增 `includes/ui/ui-tokens.php` + `includes/ui/ui-bootstrap.php`：本仓自有后台壳 body class、
    宽表横滑、触屏热区、徽章、三态令牌的唯一出处；**不再调用**主题 `zw_adm_ui_*` / `zw_mc_adm_*` /
    `zw_mc_adm_is_screen`；
  - **菜单 slug 保持稳定**（主题 RBAC 功能 ID 与书签依赖），页面类名经 `*_page_classes()` /
    `*_page_slug_map()` 映射到 `tco-*`；契约新增 §UI-1，差异登记在主题 `docs/ops/satellite-ui-registry.yml`。
  - 零功能面改动：不改取数/权限/路由/字段名/提交形状。

## [Unreleased]

### Added

- **收文自动办理：批示库 + 20 秒自动签阅（OA-2468，2026-11-03）**：用户指令「领导批示 和 签阅 为我根据 政治站位
  编一个 批示库，签阅 在收文20秒后设置一个签名 时间戳即可」。
  - **批示库**：7 类政治站位模板（督查整改/请示报告/人事/财务/贯彻落实/部署落实/常规阅办，共 21 条，后台可增删改）；
    按「关键词 → 类别 → crc32(记录ID|类别) 确定性轮换」选一条，写入 `zw_receipt_handle_log`（action=`instruction`）；
  - **自动签阅**：登记成功 20 秒后由 WP-Cron 写一条 `sign` 留痕，行时间即**签名时间戳**；幂等只写一次；
  - **审计边界**：自动条目一律 `author_login=system:auto-*` + `author_name=系统自动…` + 时间线灰标「系统自动」，
    **不假冒真人账号**；两个开关默认开、可一键关（关=零行为）；
  - 详情页「拟办与批示」卡显示签名时间戳；台账操作栏新增「自动化设置」（`view=auto`，不新增子菜单）；
  - **无表结构变更、无需迁移**（复用既有 `zw_receipt_handle_log`，内部动作 `sign` 仅内部放行）。
  - 设计说明：`docs/research/OA-2468-收文自动办理与批示库设计-20261103.md`。


### Added

- **收文办理后半段：拟办 / 批示 / 传阅 / 催办 / 归档（OA-2464，2026-11-03）**：用户同意方案后「开」。
  - **新表（append-only）**：`zw_receipt_handle_log`（拟办 `suggest` / 批示 `instruction` / 催办 `urge`，只 INSERT）、
    `zw_receipt_circulation`（传阅，`(receipt,target)` 唯一；签阅只写 `read_at` 一列）。
    不扩 `zw_policy_issue` 状态值——它同时服务法务整改台账，扩状态会污染既有语义。
  - **政策源新增 3 列**：`page_count` / `archive_box_no` / `archive_year`（只 ADD COLUMN，单行 UPDATE 只写这 3 列）。
  - **详情页新增 3 张卡片**：拟办与批示（时间线 + 追加表单）、传阅与签阅（列表 + 登记 + 签阅按钮）、
    归档信息（盒号/年度/页数）；写入走 4 个 `admin-post`（`tcpc_perm_doc` + 按记录绑定 nonce + 失败回详情页带原因）。
  - **待批示角标**：有拟办、无批示的收文数挂到左侧菜单标题（`awaiting-mod` 原生气泡），
    并在台账顶部给可读提示条；**不新增子菜单**（避免改权限功能树 ID 集与「子页 slug 与登记表一致」判据）。
  - **紧急程度扩 4 档**：未标注 / 一般 / 平件 / 加急（急件）/ 特急（保留历史 `加急` 取值，不拆存量）。
  - 迁移：`scripts/migrate-receipt-handle.php dry-run|execute|verify`（`dbDelta` 建表 + 逐列 ADD，幂等）。
  - 设计说明：`docs/research/OA-2464-收文办理后半段设计与落地-20261103.md`。
  - 兼容：台账主查询 `zw_receipt_ledger_query()` 一字未改；归档字段走独立小查询；收文状态机 4 态不变。

- **收文附件 OCR 接入（OA-2463，2026-11-03）**：用户指令「收文需要专业的ocr文字识别 再次调研github中star最高的
  适合的仓库代码」。调研结论与实现：
  - **选型**：GitHub star 实测（2026-11-03）——PaddleOCR **92,195**（Apache-2.0）/ MinerU 83,048 / tesseract 77,313 /
    Umi-OCR 48,400 / EasyOCR 30,120 / Surya 21,575 / chineseocr_lite 12,355 / dots.ocr 9,185 / RapidOCR 8,431 /
    GOT-OCR2.0 8,227 / chineseocr 6,109 / CnOCR 3,774。**首选 PaddleOCR**（中文公文质量 + 版面/表格 + CPU 可跑 +
    许可干净），**保底 RapidOCR**（ONNX、依赖最轻）；**排除 Surya**（权重是非 OSI 的 OpenRAIL-M，商用有营收门槛）。
    报告：`docs/research/OA-2463-收文OCR引擎调研与接入方案-20261103.md`。
  - **PHP 侧接缝**：`includes/ocr/`（config / client / schema / job / bootstrap / admin）；登记成功只置
    `ocr_status=pending` 并排 WP-Cron（20s），识别在 cron 执行；失败写 `failed` + `ocr_message`（详情页给原因与重试），
    **OCR 不可用不阻断登记**；详情页新增 OCR 卡片（状态/引擎/置信度/耗时/文本折叠/重新识别），
    设置挂在「收文台账 → `view=ocr`」（**不新增子菜单**，避免改权限功能树与子页 slug 判据）。
  - **数据落点**：复用 `zw_policy_source`，只增 `ocr_text/ocr_confidence/ocr_ms/ocr_at/ocr_message` 五列
    （复用既有 `ocr_status`/`extraction_method`，写 `ocr:engine@version`），**不写** `doc_name` 与正文生效面；
    迁移 `scripts/migrate-receipt-ocr.php`（dry-run/execute/verify，只 ADD COLUMN、幂等）。
  - **服务物料**：`scripts/ocr-service/`（`app.py` 标准库 HTTP、两档后端 PaddleOCR/RapidOCR 同一契约、
    PDF 用 pypdfium2 逐页 200DPI、路径 realpath 白名单、可选令牌、日志不写正文）+ `requirements*.txt` +
    `tcpc-ocr.service.example` + README（安装/排障/回滚）。**未在生产部署**（属发布窗口动作）。
  - 安全：端点只允许回环/内网（PHP 与服务端**双端**校验）、只传路径不传字节、20MB 与扩展名白名单、令牌常量时间比较。

- **收文详情页 + 收文台账 UI 对齐发文管理（OA-2462，2026-11-03）**：用户指令「进一步开发收文管理…主要注重
  功能完整性，UI界面与发文管理对齐」。本次为第一批（只动展示层，**不动数据模型**）：
  - **收文详情页**：同一个后台页的 `view=detail&id=SRC-…`（与发文 `zw-doc&view=detail` 同构，**不新增菜单项**、
    不动主题导航注册表）。卡片式三段——来文信息（编号/单位/字号/日期/密级/份数/紧急程度/办理状态/归档目录/登记时间）、
    附件（文件名=默认打开、下载只走显式按钮，SPEC-85 canonical）、办理单列表（状态徽章 + 承办部门/人 + 时限）；
  - **收文台账重写**：办理状态列（无单=`待派单`、逾期为派生）、操作列（查看 / 办理）、主按钮「登记收文」+
    「重置」筛选、空态写在表内并给**就地登记入口**、有 WP 时用 `paginate_links` 原生分页、列宽显式声明；
  - **登记页排版对齐**：`.zw-adm-card` + `.zw-adm-field` 两列栅格 + `input[type=date]` + 页脚 `zw-adm-foot`
    （主按钮 + 返回台账）；
  - **目录页 / 编号设置页**接入同一套共享令牌；
  - 新增 `docs/research/OA-2462-收文管理开源案例调研与功能补全方案-20261103.md`（GitHub 案例调研、22 项功能矩阵、
    第二批数据模型裁定建议、不采纳清单、未验证项）。

### Changed

- **办公协同后台样式令牌抽取为共享唯一出处（OA-2462）**：新增 `includes/oa/admin-ui.php` 的
  `zw_oa_admin_ui_style($page_classes)`（卡片 / 字段 / 徽章 / 分页 / 触屏），`zw_doc_admin_ui_style()` 改为
  `zw_oa_admin_ui_style(zw_doc_admin_page_classes())` —— **输出逐字节不变**（主题 `tests/test-doc-admin-ui.php`
  102 项字符串断言仍全绿）；收文侧新增 `zw_receipt_admin_ui_style()` / `zw_receipt_admin_page_classes()` 传自己的
  页面类。收文台账的主查询 `zw_receipt_ledger_query()` **一字未改**（主题契约面 + `test-receipt-ledger.php` 的
  SQL 形状断言），办理状态改由新函数 `zw_receipt_ledger_issue_map()` 按当页 ID 批量查（办理表缺失 ⇒ 空 map，
  页面不失败）。

### Docs

- **卫星单收口流程回写：从「已知缺口 + 临时处置」改为「已修复 + 标准步骤」（OA-2459，2026-11-02）**：
  主题侧 `AGENT-0091` 修掉两个卡点后（记忆门禁认同仓的 `<父目录>/task-<本号>` 工作树；marker 按插件仓归因并经
  `--marker-root` 传给 metrics），`workbench/README.md` §1.2 仍写着过时口径（切回主工作树 / 走应急通道）。
  本单改写 §1.2 并同步 §1 第 ⑥ 步：
  - 在**任务工作树**里做完即可直接收口：`workbench-finish.sh --task OA-xxxx --repo dev-tcpc-oa "说明"`；
  - `--task` 不可省（否则按 marker 的 TASK 号推出 `--repo theme` 判红）；
  - 不再需要 `ZW_SPACE_MISMATCH_ALLOW=1`（通道保留、语义未改，卫星单不再依赖）；
  - 给出对应判别力回归位置（主题 `test-long-term-memory-gate.php` ⑨/⑩、`test-satellite-marker.sh`）。
  验证方式：**按新文档实跑了一遍本单自身**（新 `workbench/worktree.sh` 建树 → 双端 preflight → 收口），全程未用应急通道。

- **收口顺序写死：先载体、后卫星（OA-2460，2026-11-02）**：`workbench/README.md` 新增 §1.2.1。因果＝
  主题 `workbench-finish.sh` 收口成功即删 `<gitdir>/zw-workbench-active.json`（一个 worktree 只有一个），
  卫星单与载体单共用同一主题工作树 ⇒ 顺序反了载体单会报「没有开工 marker」（OA-2459 实测，靠退状态重取 marker 补救）。
  正确顺序：插件 finish+commit → 载体单 finish → 卫星单 finish → 两条翻 done 后提交载体分支。

### Added

- **插件仓任务工作树入口 + 工作树归属断言（OA-2458，2026-11-02）**：此前「新任务在插件独立工作区」只靠人记
  一条手敲命令（主题 `workbench-worktree.sh` 的 `WORKTREE_BASE` 写死 `dev-tcpc` 且会跑主题 `agents-sync`，
  跨仓套用会把 `task-OA-xxxx` 建到**主题仓**），无入口、无断言。本单补：
  - `workbench/worktree.sh`：`create <OA-xxxx> [--base dev] [--path …]` / `list` / `path` / `remove [--force] [--delete-branch]`；
    号段与基座从主题 `docs/ops/repos.yml` 派生；**分支或目录已存在 ⇒ rc=1 且不创建任何东西**（PIT-006 防误写）；
    删除未并入的分支用 `git branch -d` 拒绝（防丢活）；
  - `workbench/preflight.sh` ⓪ 断言：任务工作树必须位于 `<主工作树父目录>/task-<本任务号>`，且分支必须是
    `task/<本任务号>`；`main` 主工作区放行（TASK-2306 §5 首期形态）；逃生口 `ZW_WORKTREE_LAYOUT_ALLOW=1`（理由入台账）；
  - `AGENTS.md` §1 与 `workbench/README.md` §1：建树命令入档（不再只写在迁移规格里），并修正
    `workbench-finish.sh --repo tcpc-oa` 的旧写法（注册表 name 是 `dev-tcpc-oa`，别名会判红）。

### Changed

- **双远端镜像同步口径 + 固化入口（OA-2457，2026-11-02）**：收口后**没有**任何动作会把 `dev` 推到远端 ——
  `scripts/dev-merge.sh` 只对本地 `refs/heads/dev` 做 CAS `update-ref`，**不含 push**。实测 2026-11-02：
  本地 `dev`=58ffb39（86 提交）／GitHub `github/dev`=507fc0d（22）⇒ **落后 64**／`origin/dev`=0452221（17）⇒ **落后 69**。
  已快进补齐（`507fc0d..58ffb39`、`0452221..58ffb39`），并新增守门脚本：
  - `scripts/sync-remotes.sh`：默认 **dry-run**，`--apply` 才推，`--check` 只判是否已同步；
    **只快进**（远端非本地祖先 ⇒ 拒绝并提示人工比对，**永不做 force**），工作区脏 ⇒ 拒绝；
  - `README.md` / `workbench/README.md`：远端口径更正为「双远端（origin 权威裸库 + GitHub 私有镜像）」，
    标准流程补第 ⑧ 步「同步远端」；
  - **不给 `finish.sh` 自动 push**：收口脚本不写远端（防并发收口互相顶掉），固化为脚本 + 流程步骤；
    定时器/系统服务需另行拍板，本单不做。

### Fixed

- **办公协同号段撞 WP 核心键，导致「外观」被夹进分组（OA-2454 / 主题 TASK-2482）**：原号段 `59–60.9`
  与核心 `$menu[59]`（分隔线）、`$menu[60]`（**外观**）冲突，`add_menu_page()` 避让后法务变 `60.000001`
  ⇒ 渲染顺序里「外观」落在秘书处与法务之间（用户现场「办公协同 把 wp 外观装进去了」）。
  号段整体下移到 **`58.6–58.9`**：收文 `58.6` / 发文 `58.7` / 秘书处 `58.75` / 法务 `58.8` /
  网盘文件 `58.85` / 组织架构 `58.9`（顺序不变；区间位于 `58.5` 小程序配置之后、核心分隔线 `59` 之前）。
  纪律已写入契约 P-5：号段必须落在核心未占用键之间。

### Added

- **网盘文件菜单注册权从主题移入插件（OA-2453 / 主题 TASK-2481）**：`page=zw-org-files` 的
  `add_menu_page`（position **60.5**，办公协同号段第 5 位，能力位 `manage_options`）改由
  `includes/files/org-files.php` 注册；主题 `inc/admin/admin-nav-registry.php` 已删除该调用。
  效果：**关掉插件后左侧六条 OA 入口一条不剩**（用户 2026-10-30 拍板「关插件就不能还有入口」）。
  守卫：页面回调缺失 / 无权限 ⇒ 零注册，不产出悬空菜单项。

### Fixed

- **「启用插件」致命错误（OA-2452，2026-10-30 生产）**：`/wp-admin/plugins.php?action=activate` 整站 500 ——
  `PHP Fatal error: Cannot redeclare zw_policy_platform_clause_review_core()`（主题 `policy-platform-admin.php:303`
  → 插件 `includes/policy/policy-platform-admin.php:289`）。根因＝**装载顺序反转**：正常请求里插件先于主题
  （`wp-settings` 先 require 插件、后 require 主题 `functions.php`），而 `activate_plugin()` 是在 wp-settings
  **之后**才 include 插件文件 ⇒ 主题同名副本先声明，插件 `L289` 起那段**无 function_exists 守卫**的顶层声明随即撞车。
  修复：该段（L289→EOF）改为**哨兵整段条件包裹**（`if (!function_exists('zw_policy_platform_clause_review_core')) { … }`），
  声明层让位（`return` 无效 —— PHP 在 include 期即绑定无条件顶层函数）。两条装载顺序现在都安全，行为在
  「主题缺席」时逐字不变。新增判据：`scripts/probe-load-order.php`（子进程隔离跑 theme-first / plugin-first 双序）
  并挂进 `scripts/ci-check.sh` ⑦。

- **任命对象可见性按实名收口（OA-2450）**：`zw_doc_appointee_ids()` 只保留**实名已核验**的 uid
  （canonical `zw_doc_user_realname_verified()`：`ZW_Realname::is_verified()` → `zw_branch_user_identity()`
  → `zw_real_verified` meta；不可判定 ⇒ 不放行，个人信息面 fail-closed）。
  用户 2026-10-30 拍板「做」：未实名的任命对象不进入「我的收文」与 `zw_doc_can_view_doc()`。
- **任命归档证书路径兜底不再 fatal（OA-2450）**：`zw_doc_appoint_cert_backend()` 的 `cert-paths.php`
  兜底 require 迁入插件后指向插件根（不存在）⇒ CI/CLI/主题未装载时直接 fatal。改为「存在才 require」，
  解析不到 `zw_cert_backend_file()` 时按既有口径返回 null（调用方走 LWEMS 原路径），不中断任命书颁发。
- **「我的收文」补任命对象可见性（OA-2449）**：任字号发文的任命人员 uid（正文 `meta.appointments`）是
  **明确指定的实名收件人**，此前只存在于正文、不进任何可见面（被任命用户在「我的收文」0 篇、预览 403）。
  新增 canonical 派生 `zw_doc_appointee_ids($doc)`（只读、不落库）；`zw_doc_get_document()` 只增键
  `appointee_ids`；`zw_doc_query_documents()` 的 `recipient_user_id` 口径扩为「抄送 ∪ 审核人 ∪ 任命对象」；
  `zw_doc_can_view_doc()` 放行任命对象本人。抄送/审核人语义、已发布（`approved`）门槛、PDF 完成件门槛均不变。
- **AI法务部控制面依赖降级（OA-2448）**：`includes/policy/policy-platform-admin.php` 里对**主题读侧**符号的
  直接调用改为存在性兜底 —— `zw_policy_applicability_registry_groups()` 缺 `zw_policy_module_keys/feature_keys`
  时返回空分组；`zw_policy_gate_option_state()` 缺 `zw_policy_option_is_on/tokens` 时按「未开 + 空白名单」降级。
  效果：主题读侧缺席/版本错位时，后台页**只读降级**而不是整页 fatal。判定/执行链路（年龄审查等）不变。
- **契约登记政策读侧缝（OA-2448）**：`docs/contract-tcpc-oa.md` 新增 **P-4**（方向、符号清单、缺位语义、
  版本握手 `zw_oa_policy_api_version()=1`、插件装载面纪律），并记录「适用模块保存依赖主题
  `scripts/migrate-policy-rule-modules.php`，插件装载时只读」这一已知缺口（待独立单拍板）。

### Added

- **契约登记后台导航位置号段 P-5（OA-2451 / 主题 TASK-2476）**：`docs/contract-tcpc-oa.md` 新增 P-5 ——
  办公协同号段 `59–60.9` 由**主题发放**，**插件在号段内取号**并持有「存在权 + 位置权」；主题只发分组标题、
  号段与仪表盘容器，不得为 OA 模块写 `position`。插件缺席 ⇒ 号段整段空置（零菜单项、零占位、分组标题不渲染、
  仪表盘卡片消失）。

- **分仓号段门禁（OA-0001 / 主题 TASK-2398 / CHG-431）**：`workbench/preflight.sh`（②b）与
  `workbench/commit-msg.sh`（④）新增「号段归属」判据 —— 任务号前缀必须等于 `docs/ops/repos.yml`
  里本仓的 `space`（oa = `OA`）。
  - 判定唯一实现在主题仓 `scripts/lib/zw-repo-space.sh`；本仓新增 `workbench/repo-space.sh`
    只做来源加载（与 `task-space.sh` 同构），**不复制第二份判定**；缺库 / 清单不可读 ⇒ fail-closed。
  - 与 cert 仓（CERT-0004）**零代码差异**：同一份实现，只读清单；接入新卫星仓无需改判定逻辑。
  - 实测（2026-10-28）：`CERT-0004` / `TASK-9999` 过 commit-msg ⇒ 拒绝；`OA-0001` ⇒ 通过。

- `scripts/plugin-switch-oa.sh`：P3 发版通道（版本三方 + `MIN_THEME` 兼容矩阵 + `git archive HEAD` 构建 +
  备份 + 原子交换 + 冒烟 + `--rollback`）。冒烟含 `/bhzc` 正文哈希前后一致（铁律 19）。
- **收文管理迁入（未启用）**：`includes/receipt/` 11 文件 / 6,675 行，与主题 `inc/receipt` **DIFF=0**；
  `includes/load-list.php` 登记模块 + 双钥匙开关（`enabled=false` 且 `TCPC_OA_ENABLE_RECEIPT` 未定义 ⇒ 零加载）。
- `scripts/verify-module-parity.sh`：模块迁入的 diff=0 唯一判据工具。
- **组织架构业务迁入（OA-2432 / R5，未启用）**：`includes/org/` 5 文件 / 4,413 行
  （`org-bootstrap.php` / `org-repository.php` / `org-admin.php` / `org-admin-people.php` /
  `org-admin-roles.php`），与主题 `inc/admin/org/` **逐字节 DIFF=0**
  （`bash scripts/verify-module-parity.sh org --exclude org-permissions.php` ⇒ 5 文件逐个 `cmp`
  一致，rc=0；`cp` 原样复制，未改一行代码、未动 require 路径）。
  RBAC 内核 `org-permissions.php`（1,621 行）按 Q2 拍板**不迁**，`file-center.php` / `org-files.php`
  属 `files` 模块留 R6；插件侧 `grep -rc 'function zw_org_permissions_'` 命中 **0**（无内核副本）。
  `includes/load-list.php` 的 `org` 项 `enabled` 仍为 **false** ⇒ `tcpc_oa_load_modules()` 零 require，
  运行期零行为；启用（双钥匙）待 R7。
- **网盘文件中心 UI 迁入（OA-2433 / R6，未启用）**：`includes/files/` 2 文件 / 1,762 行
  （`file-center.php` 1,075 行 + `org-files.php` 687 行，取自主题 `inc/admin/org/`），**逐字节 DIFF=0**
  （`bash scripts/verify-module-parity.sh files` ⇒ 2 文件逐个 `cmp` 一致，rc=0；`cp -p` 原样复制，
  未改一行代码、未动 require 路径）。`includes/load-list.php` 的 `files` 项本就指向
  `includes/files` 且 `enabled=false` ⇒ **零改动**；`tcpc_oa_load_modules()` 仍零 require，运行期零行为。
  文件默认打开动作复核（SPEC-85 / 铁律 22）：两文件 `zw_control_file_open_link()` 5 处、
  `zw_control_file_download_button()` 4 处（3 处实际渲染）、`download=` 属性 **0** 处
  ⇒ 文件名/图标仍只走 canonical「默认打开」，下载仍只由显式按钮触发。

### Changed

- **policy parity 口径收窄 + 插件装载责任（OA-2419 R3-2 / Q-A2「读侧留主题」）**：
  `scripts/verify-module-parity.sh` 的 `registry` 把 `policy` 主题侧匹配**收窄到 3 个管理面副本**
  `inc/admin/policy-platform-{consent,content-review,document-center}.php`（+ shim
  `inc/admin/policy-platform-admin.php`）；内核读侧 3 个
  `inc/policy-platform/{policy-module-map,policy-platform-cron,policy-rule-engine}.php` 拍板留主题 ⇒
  移出 theme 行（否则 `--expect-theme-absent` 把内核文件误报成「残留副本」），插件内同名副本登记为
  **默认 exclude**（否则反向扫描误判 `missing-theme`）。**比对不放松**：3 个管理面副本仍逐个 `cmp`
  （夹具把副本改 1 字节 ⇒ `DIFF: policy-platform-consent.php` rc=1）。
  `includes/policy/policy-platform-admin.php`（= `load-list.php` 登记的 policy bootstrap）承接**装载责任**：
  `require_once` 其余 6 个模块文件（7 个全覆盖），每处 `is_file` 守卫；本文件先装 ⇒
  `policy-platform-cron.php:13` 的「缺函数回指主题 admin」懒加载守卫不触发，插件不反向 require 主题文件。
  原因：主题 `functions.php` 把这 7 个文件分 5 处 require，其中 3 个管理面只在 `is_admin()` 加载
  ⇒ 主题副本按 R3 删除后，若插件只装 admin，「内容审核 / 签署管理 / 文档中心」3 个后台页会整页消失。
  另：`zw_policy_platform_table` / `zw_policy_platform_exists` 的 `function_exists` 守卫**自迁入即存在**
  （`policy-platform-admin.php:50/57`），本轮补注释锚定 canonical
  `inc/policy-platform/policy-platform-data.php:24/37`（守卫行 `:17/:30`）；**未删函数体**（保住与主题副本 DIFF=0，删体留 R3-4）。
  ⚠️ 发布窗口纪律：主题侧 3 个副本删除（R3）**之前**不得打开第二把钥匙
  （`TCPC_OA_ENABLE_POLICY` / option `tcpc_oa_enable_policy`）—— 会与仍在加载的主题副本撞
  `zw_legal_consent_admin_menu`（全仓唯一无声明守卫的函数）⇒ `Cannot redeclare`。
  - 实测（2026-10-29）：`verify-module-parity.sh policy` ⇒ `DIFF=0`（3 文件）rc=0；
    `/tmp` 两态夹具 **19/19**（残留任一 `inc/admin` 副本 ⇒ rc=1 点名；只留 shim + 读侧 3 文件 ⇒
    `POST-MIGRATION OK` rc=0；读侧 3 个两态均未被点名）；插件独立加载探针 **16/16**（7 文件全 include、
    3 个后台页菜单/回调就绪、0 个主题文件被 include）；第二把钥匙关闭态 **2/2**（`loaded=[]`，零加载）；
    `bash scripts/ci-check.sh` 六项全绿。

- **模块 parity 能力扩展（OA-2418 R2）**：`scripts/verify-module-parity.sh` 从「整目录 `diff -r` +
  模块 `case` if-else 长链」改为**数据化模块登记表**（`registry`：模式 / 插件侧目录 / 主题侧匹配清单 /
  shim / 默认 exclude），并新增**文件清单模式**（逐文件 `cmp`，缺失任一侧显式报
  `missing-theme:` / `missing-plugin:`）、`--exclude <basename>`（可多次）、
  按模块声明 shim 判定的 `--expect-theme-absent`（缺 shim ⇒ rc=1，fail-closed 不放宽）。
  `includes/load-list.php` 把 `doc` / `org` / `files` / `secretariat` 的占位注释兑现为**实际登记**
  （`enabled=false` ⇒ `tcpc_oa_load_modules()` 零 require，运行期行为不变）。
  - 修掉的三个实测缺陷（2026-10-29）：① `policy` / `org` / `files` 传进去直接 `rc=2 未登记模块`；
    ② `THEME_SHIM` 只在 `receipt` 分支赋值 ⇒ 其它模块走 `--expect-theme-absent` 恒判红；
    ③ `diff -r` 整目录无法表达「`policy` 4 个文件散在主题 `inc/admin/` 13 个 php 中」与
    「`files` 只占 `inc/admin/org/` 8 个中的 2 个」。
  - 实测：`policy` ⇒ `DIFF=0`（6 文件逐个 cmp）；`org` / `files` / `doc` / `secretariat` ⇒ `rc=1` 且
    输出「插件侧模块目录不存在」（预期红，不是假绿）；`receipt` 默认模式与 `--expect-theme-absent`
    与改造前一致；`org --exclude org-permissions.php`（Q2）与 `--expect-theme-absent` 的夹具三态/两态已跑通。

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

### Changed

- **多仓开工接缝（OA-2428）**：`workbench/preflight.sh` 新增 `ZW_WORKBENCH_THEME_TASK=<TASK 号>`，
  让纯插件任务（`OA-xxxx`）可以引用**主题载体单**（`TASK-xxxx`）的开工 marker。
  - 动机：主题仓 `zw_assert_task_space` 严格比号段前缀（theme=`TASK`），`OA-xxxx` 在主题仓
    preflight 必然 rc=1 ⇒ 插件仓第 ③ 步硬要求的「同号 marker」结构性不可满足。
  - 缺省行为**逐字不变**（不设该变量 ⇒ 仍找 `task-<本号>`）；设了但载体缺失 ⇒ **fail-closed**，
    不静默回退。官方形态见 `workbench/README.md` §1.1。

### Fixed

- **OA-2434（R6b）org 模块启用前的两处加载适配（有意差异，CHG-434）**：
  ① `includes/org/org-bootstrap.php` 不再 `require org-permissions.php`（主题内核，Q2 不迁）
  —— 改为在 `after_setup_theme` 判内核契约符号 `zw_org_all_permission_keys`，缺则 fail-closed
  （零菜单 + `admin_notices`），不留 fatal；② `includes/org/org-admin.php` 的 `org-files.php`
  引用改指 `includes/files/`（R6 已按模块拆分迁入）。两处均与主题侧**有意不同**，由 CHG 登记。
  实测修正：**不能用 `admin_init`** —— WP 核心 `wp-admin/admin.php:163` 先 require `menu.php`
  （`admin_menu` 在其内触发），`:180` 才 `admin_init` ⇒ 挂 `admin_init` 菜单永远不显示。

### Changed

- **OA-2435（R7-1）parity 增加「按文件、带理由」的有意差异豁免**（`scripts/verify-module-parity.sh`）：
  登记表新增 `allow|<模块>|<basename>|<理由≥8 非空白字符>`；命中差异时打印
  `· ALLOWED-DIFF <file>（理由）` 并放行，**只放行该文件**（不是整模块 exclude）。
  两条反滥用判据：理由空/过短 ⇒ fail-closed 判红；**陈旧豁免**（登记了但实测无差异）⇒ 判红。
  首期登记 OA-2434 的两处（理由见 CHG-434）；`org` 由 DIFF≠0 转为 DIFF=0，`files`/`policy` 行为不变。

## [0.1.1] - 2026-10-29

### Added

- 组织架构业务（`includes/org/`，5 文件 4,413 行，自主题 `inc/admin/org/` 逐字节迁入）与网盘文件中心 UI
  （`includes/files/`，2 文件 1,762 行），两模块 `enabled=true` 但**第二把钥匙默认关**（option 未写）
  ⇒ 生产行为与 0.1.0 等价。
- `includes/org/org-bootstrap.php` 改为 `after_setup_theme` 装载 + 内核契约符号守卫（缺则 fail-closed 不 fatal）；
  `org-admin.php` 的 `org-files.php` 引用改指 `includes/files/`（两处有意差异，CHG-434）。

### Fixed

- parity 脚本：新增「按文件、带理由」的有意差异豁免 + 陈旧豁免判红；豁免命中时 summary 不再谎称逐字节一致。

## [0.1.2] - 2026-10-29

### Added

- 发文管理模块（27 文件 / 30,242 行）整模块迁入 `includes/doc/`，与主题逐字节一致；
  `doc.enabled=true` 但第二把钥匙（option `tcpc_oa_enable_doc`）默认关 ⇒ 生产行为不变。
- 实名客户端改走主题契约 `zw_oa_realname_client_ready()`（parity 登记唯一有意差异）。

### Added (tooling)

- `scripts/oa-doc-render-smoke.php`：发文后台 9 页渲染冒烟（四断言，缺回调 fail-closed rc=2）。

## [0.1.3] - 2026-10-30

### Changed

- AI 秘书处模块 `secretariat.enabled` 置 true（第二把钥匙 `tcpc_oa_enable_secretariat` 默认关）。
  门状态存于任务文章 post meta `_zw_sec_gates`（迁移前后同一份），74/74 函数带 `function_exists` 守卫。
  权限沿用 `tcpc_perm_doc`（TCPC管理员 / TCPC审批人 / TCPC监督管理），未给内置 administrator 扩权。

