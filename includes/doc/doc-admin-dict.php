<?php
/**
 * 后台「数据字典」页面（TASK-1396）—— 发文管理模块字典的**集中统一管理页**。
 *
 * 用户指令原文：「**生成 发文管理-数据字典 页面 统一管理**」。
 *
 * ## 本文件承担的三件事
 *
 * 1. **字段字典登记表（唯一出处，代码驱动）**：`zw_doc_dict_field_rows()` 以数组声明
 *    「业务字段 ⇄ meta 键 ⇄ 取值枚举/类型 ⇄ 默认值 ⇄ 可见性/权限 ⇄ 撰写页步骤与控件 ⇄ 读取入口函数」，
 *    页面**只渲染该登记表**，禁止把字段清单手抄成静态 HTML（会与代码漂移）。
 *    断言侧由 `tests/test-doc-data-dictionary.php` 把登记表与
 *    `zw_doc_create_document()` 的 `$meta` 字面量、枚举取值函数、option 默认值**逐项对账**。
 *
 * 2. **可编辑枚举的统一维护表单**：文种字典（`zw_doc_number_settings`）与流转字典（`zw_doc_flow_settings`）
 *    原先分散在「文号管理」（`doc-admin-numbers.php`）与「公文流转配置」（`doc-admin-flow.php`）两页，
 *    本页把它们**集中到一处维护**（`zw_doc_dict_editable_groups()` 驱动）。
 *
 * 3. **唯一写入口** `zw_doc_dict_save()`：全仓 `update_option('zw_doc_number_settings')` 与
 *    `update_option('zw_doc_flow_settings')` **各只允许出现一次**，且都在该函数内。
 *    旧页面（文号管理 / 公文流转配置）保留原表单与旧 URL（**零行为回归**）但**处理器一律改走本函数**；
 *    导入路径 `zw_doc_import_register_doc_types()` 亦改走本函数。
 *    保存语义 / 默认值 / 键名 / 取值集合**一律不变** —— 本单只搬维护入口，不改含义。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ══════════════════════════════════════════════════════════════════════════
 * 一、字段字典登记表（唯一出处）
 * ══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_dict_field_groups')) {
    /**
     * 字段字典分组（显示顺序即此数组顺序）。
     *
     * @return array<string,string> 分组键 => 中文名。
     */
    function zw_doc_dict_field_groups() {
        return array(
            'create'  => 'A1 单据级字段（新建发文表单写入）',
            'compose' => 'A2 撰写页字段（正文 / 版式 meta）',
            'derived' => 'A3 派生与流转字段（系统写入）',
        );
    }
}

if (!function_exists('zw_doc_dict_field_rows')) {
    /**
     * 字段字典登记表（**唯一出处**）。
     *
     * 每行字段：
     * - `group`      分组键（见 `zw_doc_dict_field_groups()`）；
     * - `field`      业务字段中文名；
     * - `meta`       meta 键（`_zw_doc_*`）或正文子键（`body.meta.*`）；空串 = 无独立 meta；
     * - `type`       取值类型（文本 / 整数 / 日期 / 布尔 / 数组 / JSON …）；
     * - `enum`       取值枚举（空数组 = 非枚举）；**必须**与代码里的取值集合一致；
     * - `default`    缺省值（人类可读；枚举与布尔与代码默认值**逐字**一致）；
     * - `visibility` 可见性 / 权限口径；
     * - `step`       对应撰写页步骤（或「列表页」「系统写入」）；
     * - `control`    对应控件（原生控件或 SPEC-85 canonical 控件）；
     * - `reader`     读取入口函数（**唯一读取出处**）；
     * - `source`     `create`（`zw_doc_create_document()` 的 `$meta` 字面量）/ `derived`（其它写入点）。
     *
     * ⚠️ `source = 'create'` 的行**必须**与 `zw_doc_create_document()` 的 `$meta` 字面量**集合相等**
     * （多一条少一条都在断言里判红）。
     *
     * @return array<int,array<string,mixed>>
     */
    function zw_doc_dict_field_rows() {
        return array(
            /* ── A1 单据级字段：`zw_doc_create_document()` 的 `$meta` 字面量 ── */
            array(
                'group' => 'create', 'field' => '公文标题', 'meta' => 'post_title',
                'type' => '文本', 'enum' => array(), 'default' => '（必填，无默认）',
                'visibility' => '发起人 / 审核人 / 管理人（`zw_doc_can_view_doc()`）',
                'step' => '列表页 · 新建发文', 'control' => '`input[type=text]`（`name=doc_title`）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'derived',
            ),
            array(
                'group' => 'create', 'field' => '文号', 'meta' => '_zw_doc_number',
                'type' => '文本（按文号规则生成）', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '列表页 · 新建发文（文种选择后取号）', 'control' => '`input[type=text]` 只读回显（`name=num_type` / `reserved_number_id`）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '文号台账 ID', 'meta' => '_zw_doc_number_id',
                'type' => '整数', 'enum' => array(), 'default' => '0',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '列表页 · 新建发文', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '摘要', 'meta' => '_zw_doc_summary',
                'type' => '多行文本（≤500 字）', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '列表页 · 新建发文', 'control' => '`textarea`（`name=doc_summary`）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '发文状态', 'meta' => '_zw_doc_status',
                'type' => '文本（枚举）',
                'enum' => array('draft', 'pending', 'approved', 'rejected', 'cancelled'),
                'default' => "'draft'",
                'visibility' => '发起人 / 审核人 / 管理人 / 抄送人',
                'step' => '系统写入（流转迁移 `zw_doc_set_status()`）', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_status_label()` / `zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '审核人（账号 uid）', 'meta' => '_zw_doc_reviewer_id',
                'type' => '整数（用户 ID，0 = 未绑账号）', 'enum' => array(), 'default' => '0',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '列表页 · 新建发文 / 第 3 步 公文流转（编辑态改派）',
                'control' => 'SPEC-85 canonical 可搜索单选（`zw_control_search_select()`）',
                'reader' => '`zw_doc_flow_person_label()` / `zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '审核人（组织人员 ID）', 'meta' => '_zw_doc_reviewer_person_id',
                'type' => '整数（组织架构人员 ID）', 'enum' => array(), 'default' => '0',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '列表页 · 新建发文 / 第 3 步 公文流转（编辑态改派）',
                'control' => 'SPEC-85 canonical 可搜索单选（`zw_control_search_select()`）',
                'reader' => '`zw_doc_flow_person_label()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '抄送人（账号 uid）', 'meta' => '_zw_doc_cc_ids',
                'type' => '整数数组', 'enum' => array(), 'default' => 'array()',
                'visibility' => '发起人 / 审核人 / 管理人 / 抄送人本人',
                'step' => '列表页 · 新建发文 / 第 3 步 公文流转（编辑态改派）',
                'control' => 'SPEC-85 canonical 可搜索多选（`zw_control_search_select()`）',
                'reader' => '`zw_doc_flow_cc_labels()` / `zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '抄送人（组织人员 ID）', 'meta' => '_zw_doc_cc_person_ids',
                'type' => '整数数组', 'enum' => array(), 'default' => 'array()',
                'visibility' => '发起人 / 审核人 / 管理人 / 抄送人本人',
                'step' => '第 3 步 公文流转（编辑态改派）/ 流转咽喉 `zw_doc_update_flow()`',
                'control' => 'SPEC-85 canonical 可搜索多选（`zw_control_search_select()`）',
                'reader' => '`zw_doc_flow_cc_labels()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '抄送人姓名快照', 'meta' => '_zw_doc_cc_names',
                'type' => '文本数组', 'enum' => array(), 'default' => 'array()',
                'visibility' => '发起人 / 审核人 / 管理人 / 抄送人本人',
                'step' => '第 3 步 公文流转（编辑态改派）/ 流转咽喉 `zw_doc_update_flow()`',
                'control' => '随抄送人控件自动回填（非独立控件）',
                'reader' => '`zw_doc_flow_cc_labels()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '公文附件', 'meta' => '_zw_doc_attachment_ids',
                'type' => '整数数组（附件 ID）', 'enum' => array(), 'default' => 'array()',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 2 步 正文（附件区块）',
                'control' => 'SPEC-85 canonical 文件上传（file-upload 控件；失败回落原生 `input[type=file][multiple]`）',
                'reader' => '`zw_doc_attachment_names()` / `zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '审核意见', 'meta' => '_zw_doc_review_note',
                'type' => '多行文本', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '详情页 · 审核操作（`name=review_note`）', 'control' => '`textarea`',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '幂等键', 'meta' => '_zw_doc_idempotency_key',
                'type' => '文本', 'enum' => array(), 'default' => "''",
                'visibility' => '系统内部（不呈现）',
                'step' => '列表页 · 新建发文', 'control' => '`input[type=hidden]`（`name=zw_doc_idempotency_key`）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '最后修改人', 'meta' => '_zw_doc_updated_by',
                'type' => '整数（用户 ID）', 'enum' => array(), 'default' => '0',
                'visibility' => '系统内部（不呈现）',
                'step' => '系统写入', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '发文部门', 'meta' => '_zw_doc_department',
                'type' => '文本（名录白名单）', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '列表页 · 新建发文 / 第 3 步 公文流转（版记印发机关）',
                'control' => 'SPEC-85 canonical 可搜索单选（`zw_control_search_select()`）',
                'reader' => '`zw_doc_record_print_fields()` / `zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '分支机构 ID', 'meta' => '_zw_doc_branch_id',
                'type' => '整数', 'enum' => array(), 'default' => '0',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '列表页 · 新建发文（按发文机关归属）', 'control' => '随发文机关联动（非独立控件）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '备注', 'meta' => '_zw_doc_remark',
                'type' => '多行文本', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 3 步 公文流转（编辑态）/ 流转咽喉 `zw_doc_update_flow()`', 'control' => '`textarea`',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '审核人姓名（历史文本）', 'meta' => '_zw_doc_reviewer_name',
                'type' => '文本（固定短语或导入人名）', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '导入路径写死固定短语（`zw_doc_reviewer_legacy_phrase()`）', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_flow_person_label()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '分管领导', 'meta' => '_zw_doc_leader_name',
                'type' => '文本（「协会领导」名录白名单）', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '列表页 · 新建发文 / 第 3 步 公文流转（编辑态改派）',
                'control' => 'SPEC-85 canonical 可搜索单选（`zw_control_search_select()`）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '发文日期', 'meta' => '_zw_doc_issue_date',
                'type' => '日期（`Y-m-d`）', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '列表页 · 新建发文 / 第 3 步 公文流转（版记印发日期）',
                'control' => '`input[type=date]`（`name=doc_issue_date`）',
                'reader' => '`zw_doc_record_print_fields()` / `zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '来源文号', 'meta' => '_zw_doc_source_number',
                'type' => '文本', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '导入路径写入（历史台账）', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '来源标识', 'meta' => '_zw_doc_source',
                'type' => '文本', 'enum' => array(), 'default' => "''",
                'visibility' => '系统内部（台账可见）',
                'step' => '导入路径写入（`doc-import.php`）', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),
            array(
                'group' => 'create', 'field' => '导入幂等键', 'meta' => '_zw_doc_import_key',
                'type' => '文本', 'enum' => array(), 'default' => "''",
                'visibility' => '系统内部（不呈现）',
                'step' => '导入路径写入（`doc-import.php`）', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'create',
            ),

            /* ── A2 撰写页字段 ── */
            array(
                'group' => 'compose', 'field' => '正文（分块）', 'meta' => '_zw_doc_body_json',
                'type' => 'JSON（`zw_doc_body_normalize()` 归一）', 'enum' => array(), 'default' => '（不写入 = 无正文）',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 2 步 正文（分块撰写）',
                'control' => '分块编辑器（`name=zw_doc_block_type[]` / `zw_doc_block_text[]`）',
                'reader' => '`zw_doc_body_parse()` / `zw_doc_get_document()[\'body\']`', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '正文哈希', 'meta' => '_zw_doc_body_hash',
                'type' => '文本（内容指纹）', 'enum' => array(), 'default' => "''",
                'visibility' => '系统内部（不呈现）',
                'step' => '随正文写入（`zw_doc_body_hash()`）', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()[\'body_hash\']`', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '版式参数', 'meta' => '_zw_doc_layout',
                'type' => '数组（GB/T 9704 版式键）', 'enum' => array(), 'default' => 'array()',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 1 步 / 第 2 步（版式开关）', 'control' => '版式字段集合（`zw_doc_gb9704_spec()` 键）',
                'reader' => '`zw_doc_get_document()[\'layout\']`', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '发文机关标志', 'meta' => 'body.meta.org_name',
                'type' => '文本', 'enum' => array(), 'default' => '（回落红头图片 / 默认机关名）',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 1 步 公文要素',
                'control' => '`input[type=text]`（`name=zwc_org_name`，SPEC-85 可搜索单选）',
                'reader' => '`zw_doc_render_*` 版头渲染 / `zw_doc_get_document()[\'body\']`', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '份号', 'meta' => 'body.meta.copy_no',
                'type' => '文本（数字归一）', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                // TASK-1867（CHG-362）：取号方式三选一（自动取号 / 手动指定 / 不标注），自动取号由份号台账
                // `zw_doc_copy_numbers` 原子占号；手动指定可下拉选空闲号。
                'step' => '第 1 步 公文要素（版头标记）',
                'control' => '`select[name=zwc_copy_no_mode]`（auto / manual / none）+ `input[name=zwc_copy_no]` + `select[name=zwc_copy_no_pick]`（空闲号下拉）',
                'reader' => '`zw_doc_copy_number_resolve_for_save()` / `zw_doc_composer_body_from_post()` / 版头渲染', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '密级', 'meta' => 'body.meta.secret（密级部分）',
                'type' => '文本（枚举）', 'enum' => array('绝密', '机密', '秘密'),
                'default' => "''（不标注）",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 1 步 公文要素（版头标记）', 'control' => '`select`（`name=zwc_secret_level`）',
                'reader' => '`zw_doc_composer_secret_split()` / 版头渲染', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '保密期限', 'meta' => 'body.meta.secret（期限部分）',
                'type' => '文本', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 1 步 公文要素（版头标记）', 'control' => '`input[type=text]`（`name=zwc_secret_period`）',
                'reader' => '`zw_doc_composer_secret_split()`', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '紧急程度', 'meta' => 'body.meta.urgency',
                'type' => '文本（枚举）', 'enum' => array('特急', '加急'), 'default' => "''（不标注）",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 1 步 公文要素（版头标记）', 'control' => '`select`（`name=zwc_urgency`）',
                'reader' => '`zw_doc_composer_urgency()` / 版头渲染', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '主送机关', 'meta' => 'body.meta.receiver',
                'type' => '文本（枚举 + 其他自由输入，≤20 字）',
                'enum' => array('各会员单位', '各位会员', '尊敬的各位会员', '各分支机构'),
                'default' => "''（「任」字文种整块豁免）",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 1 步 公文要素',
                'control' => 'SPEC-85 canonical 可搜索单选 + 其他（`name=zwc_receiver`）',
                'reader' => '`zw_doc_composer_receiver_options()` / 版头渲染', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '署名机关', 'meta' => 'body.meta.sign_org',
                'type' => '文本', 'enum' => array(), 'default' => '（回落发文部门 + 前缀）',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 1 步 公文要素（落款）', 'control' => 'SPEC-85 canonical 可搜索单选（`name=zwc_sign_org`）',
                'reader' => '`zw_doc_sign_org()` / 落款渲染', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '成文日期', 'meta' => 'body.meta.date',
                'type' => '日期（`Y-m-d`）', 'enum' => array(), 'default' => '（当日）',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 1 步 公文要素（落款）', 'control' => '`input[type=date]`（`name=zwc_date`）',
                'reader' => '`zw_doc_iso_to_cn_date()` / 落款渲染', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '页码方式', 'meta' => 'body.meta.page_mode',
                'type' => '文本（枚举）', 'enum' => array('standard', 'center', 'none'), 'default' => "'standard'",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 2 步 正文（页码区块）', 'control' => '`radio`（`name=zwc_page_mode`）',
                'reader' => '`zw_doc_render_html()` 页码渲染', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '附件说明', 'meta' => 'body.meta.attach_note',
                'type' => '文本', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 2 步 正文（附件区块）', 'control' => '`input[type=text]`（`name=zwc_attach_note`）',
                'reader' => '`zw_doc_render_html()` 附件说明', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '抄送机关', 'meta' => 'body.meta.cc',
                'type' => '文本', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人（抄送可见时点由流转字典控制）',
                'step' => '第 3 步 公文流转（版记）', 'control' => '`input[type=text]`（`name=zwc_cc`）',
                'reader' => '`zw_doc_gb9704_record_cc_text()`', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '印发机关', 'meta' => 'body.meta.print_org',
                'type' => '文本（只读派生 = 发文部门）', 'enum' => array(), 'default' => '（= `_zw_doc_department`）',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 3 步 公文流转（只读回显）', 'control' => '`input[type=text]` 只读（`name=zwc_print_org`）',
                'reader' => '`zw_doc_record_print_fields()`', 'source' => 'derived',
            ),
            array(
                'group' => 'compose', 'field' => '印发日期', 'meta' => 'body.meta.print_date',
                'type' => '文本（只读派生 = 发文日期）', 'enum' => array(), 'default' => '（= `_zw_doc_issue_date`）',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 3 步 公文流转（只读回显）', 'control' => '`input[type=text]` 只读（`name=zwc_print_date`）',
                'reader' => '`zw_doc_record_print_fields()`', 'source' => 'derived',
            ),

            /* ── A3 派生与流转字段 ── */
            array(
                'group' => 'derived', 'field' => '红头模板 ID', 'meta' => '_zw_doc_letterhead_id',
                'type' => '整数（附件 ID）', 'enum' => array(), 'default' => '0',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '第 1 步 公文要素（红头）', 'control' => 'SPEC-85 canonical 可搜索单选（`name=letterhead_id`）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'derived',
            ),
            array(
                'group' => 'derived', 'field' => '印章 ID', 'meta' => '_zw_doc_seal_id',
                'type' => '十六进制字符串（16 位）', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人 / 用印能力 `tcpc_perm_doc_seal`',
                'step' => '详情页 · 用印（`name=seal_id`）', 'control' => 'SPEC-85 canonical 可搜索单选',
                'reader' => '`zw_doc_get_document()`', 'source' => 'derived',
            ),
            array(
                'group' => 'derived', 'field' => '用印方式', 'meta' => '_zw_doc_seal_mode',
                'type' => '文本（枚举）', 'enum' => array('none'), 'default' => "'none'",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '详情页 · 用印', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'derived',
            ),
            array(
                'group' => 'derived', 'field' => '用印锁定', 'meta' => '_zw_doc_locked',
                'type' => '整数（0/1）', 'enum' => array('0', '1'), 'default' => '0',
                'visibility' => '发起人 / 审核人 / 管理人（锁定后禁止编辑流转字段）',
                'step' => '详情页 · 用印 / 同版本解锁编辑', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_is_locked()`', 'source' => 'derived',
            ),
            array(
                'group' => 'derived', 'field' => '创建时间', 'meta' => '_zw_doc_created_at',
                'type' => '日期时间', 'enum' => array(), 'default' => '（创建时刻；有发文日期时取发文日期）',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '系统写入', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'derived',
            ),
            array(
                'group' => 'derived', 'field' => '最后更新时间', 'meta' => '_zw_doc_updated_at',
                'type' => '日期时间', 'enum' => array(), 'default' => '（创建时刻）',
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '系统写入', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()`', 'source' => 'derived',
            ),
            array(
                'group' => 'derived', 'field' => '提交时间', 'meta' => '_zw_doc_submitted_at',
                'type' => '日期时间', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '系统写入（提交审核）', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()[\'submitted_at\']`', 'source' => 'derived',
            ),
            array(
                'group' => 'derived', 'field' => '通过时间', 'meta' => '_zw_doc_approved_at',
                'type' => '日期时间', 'enum' => array(), 'default' => "''",
                'visibility' => '发起人 / 审核人 / 管理人',
                'step' => '系统写入（审核通过 / 自动审核）', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_get_document()[\'approved_at\']`', 'source' => 'derived',
            ),
            array(
                'group' => 'derived', 'field' => '历史参与人', 'meta' => '_zw_doc_flow_history_ids',
                'type' => '整数数组（用户 ID）', 'enum' => array(), 'default' => 'array()',
                'visibility' => '被换下的原审核人 / 原抄送人（只读可见，无流转编辑权）',
                'step' => '第 3 步 公文流转（编辑态改派时写入）', 'control' => '无（服务端写入）',
                'reader' => '`zw_doc_flow_history_ids()`', 'source' => 'derived',
            ),
        );
    }
}

/* ══════════════════════════════════════════════════════════════════════════
 * 二、可编辑枚举分组（页面块 B）
 * ══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_dict_editable_groups')) {
    /**
     * 可编辑枚举分组登记表（**唯一出处**）：本页块 B 的表单即由它驱动。
     *
     * `option` 为该组落的 option 键；`fields` 列出该组**全部**可编辑键（顺序即渲染顺序）。
     *
     * @return array<string,array<string,mixed>>
     */
    function zw_doc_dict_editable_groups() {
        return array(
            'number' => array(
                'label'      => '文号与文种字典',
                'option'     => 'zw_doc_number_settings',
                'origin'     => '原维护入口：发文管理 → 文号管理（本页集中后旧页表单保留，写入走同一 canonical）',
                'reader'     => '`zw_doc_number_settings()`',
                'fields'     => array('prefix', 'doc_types', 'digits', 'default_year'),
            ),
            'flow' => array(
                'label'      => '公文流转字典',
                'option'     => 'zw_doc_flow_settings',
                'origin'     => '原维护入口：发文管理 → 公文流转配置（本页集中后旧页表单保留，写入走同一 canonical）',
                'reader'     => '`zw_doc_flow_settings()`',
                'fields'     => array(
                    'require_review', 'cc_visibility', 'allow_withdraw', 'allow_resubmit',
                    'download_log_enabled', 'reviewer_dept_keys', 'cc_dept_keys',
                    // TASK-1410：自动流转开关组（四项；顺序 = 推进顺序）。
                    'auto_review', 'auto_legal', 'auto_seal', 'auto_pdf',
                ),
            ),
        );
    }
}

if (!function_exists('zw_doc_dict_unslash')) {
    /**
     * POST 值去斜杠（WP 环境用 `wp_unslash()`；无 WP 时原样返回，供存档/CLI 复用）。
     *
     * @param mixed $value 原始值。
     * @return mixed
     */
    function zw_doc_dict_unslash($value) {
        if (function_exists('wp_unslash')) {
            return wp_unslash($value);
        }
        return $value;
    }
}

if (!function_exists('zw_doc_dict_sanitize_flow')) {
    /**
     * 流转字典清洗（**唯一出处**；语义与 `doc-admin-flow.php` 原处理器**逐字一致**）。
     *
     * @param array $input 原始输入（未去斜杠，函数内部自行去斜杠）。
     * @return array
     */
    function zw_doc_dict_sanitize_flow($input) {
        $input        = is_array($input) ? $input : array();
        $dept_options = function_exists('zw_doc_flow_dept_options') ? zw_doc_flow_dept_options() : array();

        $settings = array(
            'require_review'       => !empty($input['require_review']) ? 1 : 0,
            'review_mode'          => 'single',
            'cc_visibility'        => isset($input['cc_visibility'])
                ? sanitize_key(zw_doc_dict_unslash($input['cc_visibility'])) : 'approved',
            'allow_withdraw'       => !empty($input['allow_withdraw']) ? 1 : 0,
            'allow_resubmit'       => !empty($input['allow_resubmit']) ? 1 : 0,
            'download_log_enabled' => !empty($input['download_log_enabled']) ? 1 : 0,
            // TASK-1345（D2/D4）：候选来源部门的**服务端白名单**来自当前组织架构部门字典。
            'reviewer_dept_keys'   => zw_doc_flow_filter_dept_keys(
                isset($input['reviewer_dept_keys']) ? zw_doc_dict_unslash($input['reviewer_dept_keys']) : array(),
                $dept_options
            ),
            'cc_dept_keys'         => zw_doc_flow_filter_dept_keys(
                isset($input['cc_dept_keys']) ? zw_doc_dict_unslash($input['cc_dept_keys']) : array(),
                $dept_options
            ),
            // TASK-1345（D3）：全局「自动审核」开关；TASK-1410 起与新增三项同属
            //「自动流转开关组」，页面统一显著标注（文案唯一出处
            // `zw_doc_auto_flow_switch_definitions()` / `zw_doc_auto_flow_switch_note()`）。
            'auto_review'          => !empty($input['auto_review']) ? 1 : 0,
            // TASK-1410：自动流转开关组新增三项（默认开启；关闭 ⇒ 该步零回归）。
            'auto_legal'           => !empty($input['auto_legal']) ? 1 : 0,
            'auto_seal'            => !empty($input['auto_seal']) ? 1 : 0,
            'auto_pdf'             => !empty($input['auto_pdf']) ? 1 : 0,
            'updated_by'           => get_current_user_id(),
            'updated_at'           => current_time('mysql'),
        );
        if (!in_array($settings['cc_visibility'], array('submitted', 'approved'), true)) {
            $settings['cc_visibility'] = 'approved';
        }

        return $settings;
    }
}

/* ══════════════════════════════════════════════════════════════════════════
 * 三、唯一写入口
 * ══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_dict_limit_terms')) {
    /**
     * 逗号分隔字典串的**项数与长度边界**（TASK-1396）。
     *
     * 口径：
     * - 去空、去重、**保序**；
     * - 单项长度上限 `$max_len` 个字符（`mb_substr` 口径）——**超长项直接丢弃**（字典项必须逐字精确，
     *   不做静默截断）；上限 20 字覆盖全部实际文种名（实测最长「通知」「通报」「决定」「纪要」等 ≤ 3 字）；
     * - 项数上限 `$max_items`——超出部分丢弃（防以超长 POST 撑爆 option）；
     * - 结果为空时回落既有默认（`函` / `字` / `通知`），与 `zw_doc_sanitize_number_settings()` 同口径。
     *
     * @param mixed $terms     已清洗的词条数组。
     * @param int   $max_items 项数上限。
     * @param int   $max_len   单项字符数上限。
     * @return string[]
     */
    function zw_doc_dict_limit_terms($terms, $max_items = 50, $max_len = 20) {
        $max_items = max(1, (int) $max_items);
        $max_len   = max(1, (int) $max_len);
        $out       = array();
        foreach ((array) $terms as $term) {
            $term = trim((string) $term);
            if ($term === '') {
                continue;
            }
            $len = function_exists('mb_strlen') ? mb_strlen($term, 'UTF-8') : strlen($term);
            if ($len > $max_len) {
                continue; // 超长项丢弃
            }
            if (!in_array($term, $out, true)) {
                $out[] = $term;
            }
            if (count($out) >= $max_items) {
                break;
            }
        }
        if (empty($out)) {
            $out = array('函', '字', '通知');
        }

        return array_values($out);
    }
}

if (!function_exists('zw_doc_dict_is_reserved_appoint_type')) {
    /**
     * 「任」字文种**保护性判定**（TASK-1402 硬约束，唯一出处）。
     *
     * 背景：「任」字号新建文章链路以**字面量「任」**为唯一判据
     * （`zw_doc_appoint_is_appoint_type()`，`doc-appoint.php`）；撰写页文种下拉直接来自
     * `zw_doc_number_settings()['doc_types']`。一旦从文种字典里**删除或改名**「任」，
     * 该链路将整体不可达（用户原文：「不要影响已经开发的 任 字号 新建文章的逻辑关系」）。
     * ⇒ CRUD 侧对「任」字文种 **fail-closed 拒绝删除 / 改名**。
     *
     * @param string $term 文种词条。
     * @return bool
     */
    function zw_doc_dict_is_reserved_appoint_type($term) {
        $term = trim((string) $term);
        if ($term === '') {
            return false;
        }
        if (function_exists('zw_doc_appoint_is_appoint_type')) {
            return (bool) zw_doc_appoint_is_appoint_type($term);
        }

        return $term === '任';
    }
}

if (!function_exists('zw_doc_dict_number_terms_apply')) {
    /**
     * 文种字典 CRUD 的**纯函数**（TASK-1402；不写库，便于断言与反向控制）。
     *
     * 与 `zw_doc_dict_limit_terms()` 同口径：去空、去重、**保序**、单项 ≤ 20 字、最多 50 项、
     * 空值回落既有默认。交互式 CRUD 与落库清洗的差异：这里的**校验失败必须给出可读原因**
     * （`limit_terms()` 是静默丢弃，适合兜底，不适合交互反馈）。
     *
     * 首项语义：`doc_types[0]` 是既有代码的**默认文种**（`$settings['doc_types'][0]`）
     * ⇒ 删除/改名首项必须带 `$confirm_default` 显式确认，否则 fail-closed 拒绝；
     * 新增一律**追加到末尾**（既有顺序语义不变）。
     *
     * @param string   $action          add / rename / delete。
     * @param string[] $terms           当前词条（保序）。
     * @param string   $value           新增值 / 改名目标值。
     * @param int      $index           改名 / 删除下标（0 基）。
     * @param bool     $confirm_default 首项（默认文种）二次确认标记。
     * @return array{ok:bool,terms:string[],message:string}
     */
    function zw_doc_dict_number_terms_apply($action, $terms, $value = '', $index = -1, $confirm_default = false) {
        $action = function_exists('sanitize_key')
            ? sanitize_key((string) $action)
            : strtolower(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $action));
        $terms  = zw_doc_dict_limit_terms($terms);
        $value  = trim((string) $value);
        $index  = (int) $index;
        $value_len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        $max_items = 50;
        $max_len   = 20;

        $ok  = function ($list, $message) {
            return array('ok' => true, 'terms' => array_values((array) $list), 'message' => (string) $message);
        };
        // `$terms` 在定义时**按值捕获**：失败路径返回的永远是未变更的当前词条。
        $bad = function ($message) use ($terms) {
            return array('ok' => false, 'terms' => array_values($terms), 'message' => (string) $message);
        };

        if ($action === 'add') {
            if ($value === '') {
                return $bad('文种名称不能为空。');
            }
            if ($value_len > $max_len) {
                return $bad('文种名称不得超过 ' . $max_len . ' 个字符。');
            }
            if (in_array($value, $terms, true)) {
                return $bad('文种「' . $value . '」已存在（重复项不添加）。');
            }
            if (count($terms) >= $max_items) {
                return $bad('文种数量超过上限 ' . $max_items . ' 项，请先删除后再新增。');
            }
            $terms[] = $value; // 追加到末尾：不改既有顺序，首项默认文种不受影响

            return $ok($terms, '已新增文种「' . $value . '」。');
        }

        if ($action === 'rename') {
            if (!array_key_exists($index, $terms)) {
                return $bad('要改名的文种不存在（可能已被其它窗口修改，请刷新后重试）。');
            }
            $old = (string) $terms[$index];
            if ($value === '') {
                return $bad('文种名称不能为空；如确需移除该文种请使用「删除」。');
            }
            if ($value_len > $max_len) {
                return $bad('文种名称不得超过 ' . $max_len . ' 个字符。');
            }
            if ($value === $old) {
                return $ok($terms, '文种「' . $old . '」名称未变化。');
            }
            if (in_array($value, $terms, true)) {
                return $bad('文种「' . $value . '」已存在，改名会造成重复。');
            }
            if (zw_doc_dict_is_reserved_appoint_type($old)) {
                return $bad('「' . $old . '」是「任」字号新建文章必须保留的文种，不能改名（会中断人员任命链路）。');
            }
            if ($index === 0 && !$confirm_default) {
                return $bad('「' . $old . '」是当前默认文种（撰写页与文号生成的默认取值）；改名后默认文种会随之改变，必须勾选确认后重试。');
            }
            $terms[$index] = $value;
            $extra = ($index === 0) ? '（默认文种已变更为「' . $value . '」）' : '';

            return $ok($terms, '已把文种「' . $old . '」改名为「' . $value . '」' . $extra . '。');
        }

        if ($action === 'delete') {
            if (!array_key_exists($index, $terms)) {
                return $bad('要删除的文种不存在（可能已被其它窗口修改，请刷新后重试）。');
            }
            $old = (string) $terms[$index];
            if (count($terms) <= 1) {
                return $bad('文种字典不能为空，至少保留一项；如确需替换请先新增再删除。');
            }
            if (zw_doc_dict_is_reserved_appoint_type($old)) {
                return $bad('「' . $old . '」是「任」字号新建文章必须保留的文种，不能删除（会中断人员任命链路）。');
            }
            if ($index === 0 && !$confirm_default) {
                return $bad('「' . $old . '」是当前默认文种；删除后默认文种将变为「' . (string) $terms[1] . '」，必须勾选确认后重试。');
            }
            array_splice($terms, $index, 1);
            $extra = ($index === 0) ? '（默认文种已变更为「' . (string) $terms[0] . '」）' : '';

            return $ok($terms, '已删除文种「' . $old . '」' . $extra . '。');
        }

        return $bad('未知的文种操作，保存失败。');
    }
}

if (!function_exists('zw_doc_dict_number_payload_from_terms')) {
    /**
     * 由词条数组构造**逗号分隔**的 number 组落库载荷（TASK-1402）。
     *
     * 用户原文要求「修改时**直接将逗号分隔的文种入表**」⇒ CRUD 的最终入参仍是逗号串
     * （经既有 `zw_doc_sanitize_number_settings()` 清洗后落成**同一 option / 同一键**的数组），
     * 不引入第二种存储格式。其余 number 键（`digits` / `default_year`）必须随行携带现值，
     * 否则 `zw_doc_sanitize_number_settings()` 会把它们回落成默认值（静默改配置）。
     *
     * @param string[] $terms 词条（保序）。
     * @return array
     */
    function zw_doc_dict_number_payload_from_terms($terms) {
        $current = function_exists('zw_doc_number_settings') ? zw_doc_number_settings() : array();

        return array(
            'doc_types'    => implode(',', zw_doc_dict_limit_terms($terms)),
            'digits'       => isset($current['digits']) ? (int) $current['digits'] : 3,
            'default_year' => isset($current['default_year']) ? (int) $current['default_year'] : (int) current_time('Y'),
        );
    }
}

if (!function_exists('zw_doc_dict_save')) {
    /**
     * 发文管理字典的**唯一写入口**（TASK-1396）。
     *
     * 全仓 `update_option('zw_doc_number_settings', …)` 与
     * `update_option('zw_doc_flow_settings', …)` **各只出现一次**，都在本函数内；
     * 任何页面 / 处理器 / 导入路径都**必须**经由此函数写入（断言反向守卫见
     * `tests/test-doc-data-dictionary.php`）。
     *
     * 保存语义：`number` 组经 `zw_doc_sanitize_number_settings()`（既有清洗，语义不变）；
     * `flow` 组经 `zw_doc_dict_sanitize_flow()`（与 `doc-admin-flow.php` 原处理器逐字一致）。
     *
     * @param string $group     分组键（`number` / `flow`）。
     * @param array  $input     原始输入（通常为 `$_POST`；number 组允许传完整 settings）。
     * @param string $context   调用来源标识（仅供审计 / 断言定位，不参与保存语义）。
     * @param array  $overrides 显式给定的留痕字段（仅 `updated_by` / `updated_at`），
     *                          供既有导入调用点保持**逐字等价**；缺省不覆盖。
     * @return array|WP_Error|false 落库后的完整 settings；分组非法或缺依赖返回 WP_Error。
     */
    function zw_doc_dict_save($group, $input, $context = '', $overrides = array()) {
        $group     = function_exists('sanitize_key') ? sanitize_key((string) $group) : strtolower(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $group));
        $input     = is_array($input) ? $input : array();
        $overrides = is_array($overrides) ? $overrides : array();
        $tracked   = array('updated_by', 'updated_at');

        if ($group === 'number') {
            if (!function_exists('zw_doc_sanitize_number_settings') || !function_exists('update_option')) {
                return zw_doc_dict_error('zw_doc_dict_missing_dependency', '文号规则清洗函数不可用。');
            }
            $clean = zw_doc_sanitize_number_settings($input);
            if (!is_array($clean)) {
                return zw_doc_dict_error('zw_doc_dict_bad_result', '文号规则清洗结果非法。');
            }
            // TASK-1396：文种字典的**项数与长度边界**（唯一处置点；旧页面 / 本页 / 导入路径三处同口径）。
            if (isset($clean['doc_types'])) {
                $clean['doc_types'] = zw_doc_dict_limit_terms($clean['doc_types']);
            }
            foreach ($tracked as $key) {
                if (array_key_exists($key, $overrides)) {
                    $clean[$key] = $overrides[$key];
                }
            }
            update_option('zw_doc_number_settings', $clean, false);

            return $clean;
        }

        if ($group === 'flow') {
            if (!function_exists('zw_doc_dict_sanitize_flow') || !function_exists('update_option')) {
                return zw_doc_dict_error('zw_doc_dict_missing_dependency', '流转字典清洗函数不可用。');
            }
            $clean = zw_doc_dict_sanitize_flow($input);
            foreach ($tracked as $key) {
                if (array_key_exists($key, $overrides)) {
                    $clean[$key] = $overrides[$key];
                }
            }
            update_option('zw_doc_flow_settings', $clean, false);

            return $clean;
        }

        return zw_doc_dict_error('zw_doc_dict_unknown_group', '未知字典分组。');
    }
}

if (!function_exists('zw_doc_dict_error')) {
    /**
     * 统一错误构造（`WP_Error` 不可用时退回 `false`，保持 fail-closed）。
     *
     * @param string $code    错误码。
     * @param string $message 错误文案。
     * @return WP_Error|false
     */
    function zw_doc_dict_error($code, $message) {
        if (class_exists('WP_Error')) {
            return new WP_Error($code, $message);
        }
        return false;
    }
}

/* ══════════════════════════════════════════════════════════════════════════
 * 四、页面
 * ══════════════════════════════════════════════════════════════════════════ */

if (!function_exists('zw_doc_dict_admin_url')) {
    /**
     * 「数据字典」页 URL（沿用模块既有 `zw_doc_admin_url()` 范式的兄弟函数）。
     *
     * @param array $args 查询参数。
     * @return string
     */
    function zw_doc_dict_admin_url($args = array()) {
        return add_query_arg(array_merge(array('page' => 'zw-doc-dict'), (array) $args), admin_url('admin.php'));
    }
}

if (!function_exists('zw_doc_dict_handle_post')) {
    /**
     * 在 `admin_init` 阶段处理「数据字典」页 POST（页面输出后无法再发重定向头）。
     *
     * @return void
     */
    function zw_doc_dict_handle_post() {
        if ((($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') || !isset($_POST['zw_doc_dict_group'])) {
            return;
        }
        // 纵深防御：除 doc-bootstrap 的统一守卫外，本处理器自行再判一次。
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        $group  = sanitize_key(wp_unslash($_POST['zw_doc_dict_group']));
        $groups = zw_doc_dict_editable_groups();
        if (!isset($groups[$group])) {
            wp_safe_redirect(zw_doc_dict_admin_url(array('zw_doc_msg' => '未知字典分组。')));
            exit;
        }
        check_admin_referer('zw_doc_dict_save_' . $group);

        // TASK-1402：文种字典 CRUD（新增 / 就地改名 / 删除）。
        // 先经**纯函数**算出新词条（含首项默认文种保护与「任」字保护，fail-closed），
        // 再把**逗号分隔**的整串交给唯一写入口 `zw_doc_dict_save()`（落库形态与键名一律不变）。
        $action  = isset($_POST['zw_doc_dict_action']) ? sanitize_key(wp_unslash($_POST['zw_doc_dict_action'])) : '';
        $saved   = false;
        $notice  = '';
        $context = 'dict_page';
        if ($group === 'number' && $action !== '') {
            $applied = zw_doc_dict_number_terms_apply(
                $action,
                zw_doc_number_settings()['doc_types'],
                isset($_POST['zw_doc_dict_term']) ? sanitize_text_field(wp_unslash($_POST['zw_doc_dict_term'])) : '',
                isset($_POST['zw_doc_dict_index']) ? (int) $_POST['zw_doc_dict_index'] : -1,
                !empty($_POST['zw_doc_confirm_default'])
            );
            $notice = (string) $applied['message'];
            if ($applied['ok']) {
                $context = 'dict_page_doc_type_' . $action;
                $saved   = zw_doc_dict_save('number', zw_doc_dict_number_payload_from_terms($applied['terms']), $context);
            }
        } else {
            $payload = $_POST;
            if ($group === 'number' && !isset($payload['doc_types'])) {
                // 「文号基本参数」表单不含文种字段 ⇒ 以**现值**补齐，避免清洗阶段回落成默认而静默清空字典。
                $payload['doc_types'] = zw_doc_number_settings()['doc_types'];
            }
            $saved = zw_doc_dict_save($group, $payload, $context);
        }

        if ($notice === '') {
            $notice = is_wp_error($saved) || $saved === false
                ? '数据字典保存失败（清洗结果非法）。'
                : ((string) $groups[$group]['label'] . '已保存。');
        }

        if (!is_wp_error($saved) && $saved !== false && function_exists('zw_doc_log_event')) {
            // 与流转配置同口径：以 `document_id = 0` 记录**配置级**事件，回答
            // 「谁在什么时刻把字典改成了什么」。
            zw_doc_log_event(
                0,
                'flow_config_saved',
                get_current_user_id(),
                '',
                '',
                sprintf('数据字典保存（%s/%s）：%s', $group, $context, (string) $groups[$group]['option'])
            );
        }

        wp_safe_redirect(zw_doc_dict_admin_url(array('zw_doc_msg' => $notice)));
        exit;
    }
}

if (!function_exists('zw_doc_page_dict')) {
    /**
     * 「数据字典」页渲染：A 字段字典（只读、登记表驱动）+ B 可编辑枚举统一维护。
     *
     * @return void
     */
    function zw_doc_page_dict() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }

        $notice = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';
        $rows   = zw_doc_dict_field_rows();
        $labels = zw_doc_dict_field_groups();
        $groups = zw_doc_dict_editable_groups();
        $number = zw_doc_number_settings();
        $flow   = zw_doc_flow_settings();
        ?>
        <div class="wrap tco-doc-dict">
            <?php echo zw_doc_admin_ui_style(); // 唯一出处：SPEC-10 §1.9 卡片范式（TASK-778） ?>
            <style>
            .tco-doc-dict .tco-table-wrap{width:100%;overflow-x:auto}
            .tco-doc-dict table.zw-dict-table{width:100%;border-collapse:collapse;font-size:13px}
            .tco-doc-dict table.zw-dict-table th,.tco-doc-dict table.zw-dict-table td{border-bottom:1px solid #f0f1f3;padding:8px 10px;text-align:left;vertical-align:top}
            .tco-doc-dict table.zw-dict-table th{background:#fafbfc;font-weight:600;white-space:nowrap}
            .tco-doc-dict table.zw-dict-table code{background:#f4f4f4;border-radius:4px;padding:1px 5px;font-size:12px}
            .tco-doc-dict .zw-dict-enum{display:block;margin-top:2px;color:#8c8f94;font-size:12px}
            .tco-doc-dict .zw-dict-group{margin:16px 0 0}
            .tco-doc-dict .zw-dict-term-add{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:8px 0 10px}
            .tco-doc-dict .zw-dict-term-add input[type=text]{min-width:260px}
            .tco-doc-dict .zw-dict-term-op{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0 10px 4px 0}
            .tco-doc-dict .zw-dict-term-op input[type=text]{max-width:170px}
            .tco-doc-dict .zw-dict-confirm{display:inline-flex;gap:4px;align-items:center;font-size:12px;color:#8c8f94}
            .tco-doc-dict .zw-dict-badge{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:999px;background:#f0f1f3;color:#50575e;font-size:12px}
            .tco-doc-dict .zw-dict-danger{color:#b30404;border-color:#b30404}
            .tco-doc-dict .zw-dict-danger[disabled],.tco-doc-dict .zw-dict-term-op .button[disabled]{opacity:.5;cursor:not-allowed}
            <?php
            // TASK-2165（SPEC-10-B §3 / TASK-2149 缺陷③形态）：本页使用 canonical 热区容器类 `.tco-touch`
            // （默认文种二次确认勾选标签），必须在**同文件**输出 canonical 触屏块，否则该类在本页规则数恒为 0、
            // 44×44 热区不存在。唯一出处 `tco_ui_touch_css()`（仅 `@media(pointer:coarse)` 命中 ⇒ 桌面零视觉变更）。
            echo function_exists('tco_ui_touch_css') ? tco_ui_touch_css('body') : '';
            ?>
            </style>
            <?php echo zw_doc_admin_page_head(
                '数据字典',
                '发文管理模块字典的集中统一管理页：字段字典只读展示（由代码登记表驱动），可编辑枚举在此统一维护。'
            ); ?>
            <?php if ($notice !== '') { zw_doc_admin_notice($notice, zw_doc_notice_level($notice)); } ?>

            <?php
            // TASK-2165（S1，用户拍板）：文号规则的**唯一写面**收敛到本页，并置于**页面首行**。
            // 原入口「文号管理 → 文号规则」表单已下线（该页只留取号 / 预留 / 作废与台账 + 回链），
            // 因为它的「文种」文本框绕过了本页 CRUD 的护栏（「任」字禁删/禁改名、首项默认文种二次确认）。
            // 落库仍走唯一写入口 zw_doc_dict_save('number', …)；键名 / 形态 / 消费方取值路径一律不变。
            ?>
            <div class="tco-doc-card" id="zw-dict-number-basics">
                <?php echo zw_doc_admin_card_head('文号基本参数', '首行 · 唯一可写面', true, 'ceoicon-book-2-line'); ?>
                <p class="tco-doc-muted">
                    原「文号管理 → 文号规则」表单已并入本页：<strong>文号规则与文种只在这里维护</strong>
                    （落 option <code><?php echo esc_html($groups['number']['option']); ?></code>，
                    保存经唯一写入口 <code>zw_doc_dict_save('number', …)</code>）。
                    文号管理页只保留取号 / 预留 / 作废与台账查询，并回链到本页。
                </p>
                <form method="post">
                    <?php wp_nonce_field('zw_doc_dict_save_number'); ?>
                    <input type="hidden" name="zw_doc_dict_group" value="number">
                    <div class="tco-doc-field">
                        <label>机关前缀</label>
                        <input type="text" value="<?php echo esc_attr($number['prefix']); ?>" readonly>
                        <div class="tco-doc-muted">按用户确认固定为“中传”，不可配置。</div>
                    </div>
                    <div class="tco-doc-field">
                        <label for="zw-dict-number-year">默认年份</label>
                        <select id="zw-dict-number-year" name="default_year">
                            <?php foreach (zw_doc_year_options() as $year) : ?>
                                <option value="<?php echo esc_attr((string) $year); ?>"<?php selected((int) $number['default_year'], (int) $year); ?>><?php echo esc_html((string) $year); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="tco-doc-muted">撰写页与预留下拉的默认年份；<strong>不改写任何历史文号</strong>。</div>
                    </div>
                    <div class="tco-doc-field">
                        <label for="zw-dict-number-digits">序号位数</label>
                        <input type="number" id="zw-dict-number-digits" name="digits" min="1" max="6" value="<?php echo esc_attr((string) $number['digits']); ?>">
                        <div class="tco-doc-muted">起始序号固定从 1 开始，不补零。</div>
                    </div>
                    <div class="tco-foot">
                        <button class="button button-primary" type="submit">保存文号基本参数</button>
                    </div>
                </form>
            </div>

            <div class="tco-doc-card">
                <?php echo zw_doc_admin_card_head('字段字典（只读）', count($rows) . ' 条', true, 'ceoicon-book-2-line'); ?>
                <p class="tco-doc-muted">
                    本表由 <code>zw_doc_dict_field_rows()</code> 登记表驱动，禁止手抄成静态 HTML；
                    断言会把登记表与 <code>zw_doc_create_document()</code> 的 meta 字面量、枚举取值函数逐项对账。
                </p>
                <?php
                // 先按分组归集，避免渲染期操作数组指针（易错且不可回归）。
                $grouped = array();
                foreach ($rows as $row) {
                    $key = (string) $row['group'];
                    if (!isset($grouped[$key])) {
                        $grouped[$key] = array();
                    }
                    $grouped[$key][] = $row;
                }
                foreach ($grouped as $group_key => $group_rows) :
                    ?>
                    <p class="tco-sec zw-dict-group"><?php echo esc_html(isset($labels[$group_key]) ? $labels[$group_key] : $group_key); ?></p>
                    <div class="tco-table-wrap">
                        <table class="zw-dict-table widefat striped">
                            <thead>
                            <tr>
                                <th>业务字段</th>
                                <th>meta 键</th>
                                <th>取值枚举 / 类型</th>
                                <th>默认值</th>
                                <th>可见性 / 权限</th>
                                <th>撰写页步骤</th>
                                <th>控件</th>
                                <th>读取入口函数</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group_rows as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['field']); ?></strong></td>
                                    <td><code><?php echo esc_html($row['meta'] !== '' ? $row['meta'] : '—'); ?></code></td>
                                    <td>
                                        <?php echo esc_html($row['type']); ?>
                                        <?php if (!empty($row['enum'])) : ?>
                                            <span class="zw-dict-enum">枚举：<?php echo esc_html(implode(' ｜ ', array_map('strval', $row['enum']))); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html((string) $row['default']); ?></td>
                                    <td><?php echo esc_html((string) $row['visibility']); ?></td>
                                    <td><?php echo esc_html((string) $row['step']); ?></td>
                                    <td><?php echo esc_html((string) $row['control']); ?></td>
                                    <td><code><?php echo esc_html((string) $row['reader']); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                endforeach;
                ?>

                <?php
                // TASK-1402：「发文类型」展示用的**文种简称 → 全称**映射（只读；口径来自代码 canonical）。
                // 映射表**不由本页编辑**；此处仅展示，供运营核对台账「发文类型」列会显示成什么。
                $dict_type_map  = zw_doc_number_type_labels();
                $dict_unmapped  = zw_doc_number_type_unregistered();
                ?>
                <p class="tco-sec zw-dict-group">文种简称 → 全称映射（只读）</p>
                <p class="tco-doc-muted">
                    唯一出处 <code>zw_doc_number_type_labels()</code>；供「公文台账」列表「发文类型」列
                    与其他展示位渲染，本页<b>只读展示、不提供编辑</b>。
                    未登记的简称按**原样**显示（不臆造全称）；空值显示友好占位「—」。
                </p>
                <div class="tco-table-wrap">
                    <table class="zw-dict-table widefat striped">
                        <thead>
                        <tr>
                            <th style="width:120px">简称（字段值）</th>
                            <th>全称（展示值）</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dict_type_map as $dict_short => $dict_full) : ?>
                            <tr>
                                <td><code><?php echo esc_html((string) $dict_short); ?></code></td>
                                <td><?php echo esc_html((string) $dict_full); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="tco-doc-muted">
                    当前文种字典中**未登记**的简称：
                    <?php if ($dict_unmapped === array()) : ?>
                        无（全部已在映射表内）。
                    <?php else : ?>
                        <?php echo esc_html(implode('、', $dict_unmapped)); ?>（台账按原样显示，不映射全称）。
                    <?php endif; ?>
                </p>
            </div>

            <?php
            // TASK-1422：全局「红头生成方式」本页**只读展示**（统一查阅）；
            // 唯一可写入口在「红头与印章 → 红头模板」页（写函数 `zw_doc_letterhead_mode_save()`）。
            $dict_lh_state = function_exists('zw_doc_letterhead_mode_state')
                ? zw_doc_letterhead_mode_state()
                : array('mode' => 'auto', 'ready' => false, 'blocked' => false, 'message' => '', 'assets_url' => '');
            ?>
            <div class="tco-doc-card">
                <?php echo zw_doc_admin_card_head('红头生成方式（只读）', '全局默认', true, 'ceoicon-book-2-line'); ?>
                <p class="tco-doc-muted">
                    唯一取值出处 <code>zw_doc_letterhead_mode()</code>（option <code>zw_doc_letterhead_mode</code>，默认 <code>auto</code>）；
                    版式预览与出件（HTML 打印 / DOCX / 加密 PDF）<strong>同源</strong>使用该值。
                    本页<strong>只读展示、不提供编辑</strong>；切换请在「红头与印章 → 红头模板」页
                    （唯一写入口 <code>zw_doc_letterhead_mode_save()</code>）。
                </p>
                <!-- TASK-1633（TASK-1579 P1-6 第四批）：宽表落 canonical 横滑包裹。
                     规则由本模块唯一出处 zw_doc_admin_ui_style()（本页 :935 输出）提供 ⇒ 本页不新增 <style>。 -->
                <div class="tco-table-wrap">
                <table class="zw-dict-table widefat striped">
                    <thead>
                    <tr><th>设置项</th><th>取值</th><th>说明</th><th>写入入口</th></tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td><strong>红头生成方式</strong></td>
                        <td>
                            <?php echo esc_html(function_exists('zw_doc_letterhead_mode_label') ? zw_doc_letterhead_mode_label($dict_lh_state['mode']) : $dict_lh_state['mode']); ?>
                            <span class="zw-dict-badge"><?php echo esc_html((string) $dict_lh_state['mode']); ?></span>
                        </td>
                        <td>
                            <?php if (!empty($dict_lh_state['blocked'])) : ?>
                                <span style="color:#b30404">fail-closed：已切到「使用上传的红头文件（图片）」但无已启用红头 —— 预览与出件给出明确提示，不静默回落、不渲染空白红头。</span>
                            <?php else : ?>
                                <span class="tco-doc-muted">「系统自动生成红头」不需要图片素材；「使用上传的红头文件（图片）」按「单篇指定 → 文种映射 → 默认红头」取已启用图片。</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($dict_lh_state['assets_url'])) : ?>
                                <a href="<?php echo esc_url((string) $dict_lh_state['assets_url']); ?>">红头与印章 → 红头模板</a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="tco-grid is-2">
                <div class="tco-doc-card">
                    <?php echo zw_doc_admin_card_head($groups['number']['label'], '唯一写入口', true, 'ceoicon-book-2-line'); ?>
                    <p class="tco-doc-muted">
                        落 option <code><?php echo esc_html($groups['number']['option']); ?></code>；
                        读取入口 <code><?php echo esc_html($groups['number']['reader']); ?></code>；
                        保存经唯一写入口 <code>zw_doc_dict_save('number', …)</code>。
                    </p>
                    <p class="tco-doc-muted"><?php echo esc_html($groups['number']['origin']); ?></p>
                    <?php
                    // TASK-1402：文种字典改 **CRUD 列表**（顶部输入框 + 确认按钮新增；下方逐项就地改名 / 删除）。
                    // 词条顺序即 rank：第 1 项是既有代码的**默认文种**（`$settings['doc_types'][0]`），
                    // 故首项改名 / 删除必须二次确认；新增一律追加到末尾（顺序语义不变）。
                    $dict_terms = isset($number['doc_types']) && is_array($number['doc_types']) ? array_values($number['doc_types']) : array();
                    ?>
                    <p class="tco-sec zw-dict-group">文种字典（新增 / 就地改名 / 删除）</p>
                    <form method="post" class="zw-dict-term-add">
                        <?php wp_nonce_field('zw_doc_dict_save_number'); ?>
                        <input type="hidden" name="zw_doc_dict_group" value="number">
                        <input type="hidden" name="zw_doc_dict_action" value="add">
                        <input type="text" name="zw_doc_dict_term" value="" maxlength="20" placeholder="输入文种名称，如：通知" aria-label="新增文种名称" required>
                        <button class="button button-primary" type="submit">确认新增</button>
                    </form>
                    <div class="tco-table-wrap">
                        <table class="zw-dict-table widefat striped">
                            <thead>
                            <tr>
                                <th style="width:48px">序号</th>
                                <th style="width:220px">文种（字段值）</th>
                                <th style="width:150px">发文类型全称（映射）</th>
                                <th>操作</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($dict_terms === array()) : ?>
                                <tr><td colspan="4">文种字典为空（保存后会自动回落默认「函 / 字 / 通知」）。</td></tr>
                            <?php else : ?>
                                <?php foreach ($dict_terms as $dict_index => $dict_term) : ?>
                                    <?php
                                    $dict_is_default = ((int) $dict_index === 0);
                                    $dict_reserved   = zw_doc_dict_is_reserved_appoint_type($dict_term);
                                    $dict_confirm_id = 'zw-dict-confirm-' . (int) $dict_index;
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $dict_index + 1; ?></td>
                                        <td>
                                            <strong><?php echo esc_html((string) $dict_term); ?></strong>
                                            <?php if ($dict_is_default) : ?>
                                                <span class="zw-dict-badge">默认文种</span>
                                            <?php endif; ?>
                                            <?php if ($dict_reserved) : ?>
                                                <span class="zw-dict-badge">任字号必需</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html(zw_doc_number_type_display($dict_term)); ?></td>
                                        <td>
                                            <form method="post" class="zw-dict-term-op" onsubmit="return confirm('确认把文种「<?php echo esc_js((string) $dict_term); ?>」改名？');">
                                                <?php wp_nonce_field('zw_doc_dict_save_number'); ?>
                                                <input type="hidden" name="zw_doc_dict_group" value="number">
                                                <input type="hidden" name="zw_doc_dict_action" value="rename">
                                                <input type="hidden" name="zw_doc_dict_index" value="<?php echo (int) $dict_index; ?>">
                                                <input type="text" name="zw_doc_dict_term" value="<?php echo esc_attr((string) $dict_term); ?>" maxlength="20" aria-label="文种名称"<?php echo $dict_reserved ? ' readonly' : ''; ?>>
                                                <?php if ($dict_is_default) : ?>
                                                    <label class="zw-dict-confirm tco-touch" for="<?php echo esc_attr($dict_confirm_id); ?>">
                                                        <input type="checkbox" id="<?php echo esc_attr($dict_confirm_id); ?>" name="zw_doc_confirm_default" value="1">
                                                        确认修改默认文种
                                                    </label>
                                                <?php endif; ?>
                                                <button class="button" type="submit"<?php echo $dict_reserved ? ' disabled' : ''; ?>>保存</button>
                                            </form>
                                            <form method="post" class="zw-dict-term-op" onsubmit="return confirm('确认删除文种「<?php echo esc_js((string) $dict_term); ?>」？删除后不可恢复。');">
                                                <?php wp_nonce_field('zw_doc_dict_save_number'); ?>
                                                <input type="hidden" name="zw_doc_dict_group" value="number">
                                                <input type="hidden" name="zw_doc_dict_action" value="delete">
                                                <input type="hidden" name="zw_doc_dict_index" value="<?php echo (int) $dict_index; ?>">
                                                <?php if ($dict_is_default) : ?>
                                                    <label class="zw-dict-confirm tco-touch" for="<?php echo esc_attr($dict_confirm_id . '-del'); ?>">
                                                        <input type="checkbox" id="<?php echo esc_attr($dict_confirm_id . '-del'); ?>" name="zw_doc_confirm_default" value="1">
                                                        确认删除默认文种
                                                    </label>
                                                <?php endif; ?>
                                                <button class="button zw-dict-danger" type="submit"<?php echo $dict_reserved ? ' disabled' : ''; ?>>删除</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="tco-doc-muted">
                        保存口径：去空、去重、**保序**，单项 ≤ 20 字，最多 50 项；空值回落默认「函，字，通知」。
                        写入一律经唯一写入口 <code>zw_doc_dict_save('number', …)</code>，且词条以**逗号分隔串**入表
                        （落 option <code>zw_doc_number_settings</code> 的 <code>doc_types</code> 键，形态与既有消费方不变）。
                        第 1 项是撰写页与文号生成的**默认文种**，改名 / 删除需勾选二次确认；
                        「任」字文种是「任」字号新建文章的必要条件，删除与改名一律拒绝。
                    </p>

                    <p class="tco-doc-muted">
                        文号基本参数（机关前缀 / 默认年份 / 序号位数）已移到本页<strong>首行</strong>的
                        「文号基本参数」卡维护（同一唯一写入口）；本文种卡只负责文种的增删与就地改名。
                    </p>
                </div>

                <div class="tco-doc-card">
                    <?php echo zw_doc_admin_card_head($groups['flow']['label'], '唯一写入口', true, 'ceoicon-book-2-line'); ?>
                    <p class="tco-doc-muted">
                        落 option <code><?php echo esc_html($groups['flow']['option']); ?></code>；
                        读取入口 <code><?php echo esc_html($groups['flow']['reader']); ?></code>；
                        保存经唯一写入口 <code>zw_doc_dict_save('flow', …)</code>。
                    </p>
                    <p class="tco-doc-muted"><?php echo esc_html($groups['flow']['origin']); ?></p>
                    <?php
                    // 候选来源部门按**当前组织架构部门字典**渲染（与流转配置页同源）。
                    $dict_dept_options = array();
                    foreach (zw_doc_flow_dept_options() as $dict_dept_key => $dict_dept_name) {
                        $dict_dept_options[] = array(
                            'value'  => (string) $dict_dept_key,
                            'label'  => (string) $dict_dept_name,
                            'search' => $dict_dept_name . ' ' . $dict_dept_key,
                        );
                    }
                    ?>
                    <form method="post">
                        <?php wp_nonce_field('zw_doc_dict_save_flow'); ?>
                        <input type="hidden" name="zw_doc_dict_group" value="flow">
                        <div class="tco-doc-field">
                            <label class="tco-touch"><input type="checkbox" name="require_review" value="1"<?php checked(!empty($flow['require_review'])); ?>> 需要审核</label>
                        </div>
                        <div class="tco-doc-field">
                            <label>审核方式</label>
                            <input type="text" value="单人审核（MVP 固定）" readonly>
                        </div>
                        <div class="tco-doc-field">
                            <label>审核人候选部门</label>
                            <?php
                            if (function_exists('zw_control_search_select')) {
                                zw_control_search_select(array(
                                    'id'          => 'tco-doc-dict-reviewer-depts',
                                    'name'        => 'reviewer_dept_keys[]',
                                    'multiple'    => true,
                                    'value'       => $flow['reviewer_dept_keys'],
                                    'placeholder' => '输入部门名称筛选',
                                    'empty_text'  => '没有匹配的部门',
                                    'options'     => $dict_dept_options,
                                ));
                            } else {
                                echo '<select name="reviewer_dept_keys[]" multiple size="5" style="min-height:110px">';
                                foreach ($dict_dept_options as $dict_option) {
                                    $dict_selected = in_array($dict_option['value'], $flow['reviewer_dept_keys'], true);
                                    echo '<option value="' . esc_attr($dict_option['value']) . '"' . ($dict_selected ? ' selected' : '') . '>' . esc_html($dict_option['label']) . '</option>';
                                }
                                echo '</select>';
                            }
                            ?>
                        </div>
                        <div class="tco-doc-field">
                            <?php
                            // TASK-1410：四项自动流转开关**统一渲染**（唯一实现；文案与显著标注同配置页）。
                            echo zw_doc_auto_flow_render_switches($flow, 'tco-doc-dict-auto');
                            ?>
                        </div>
                        <div class="tco-doc-field">
                            <label>抄送人候选部门</label>
                            <?php
                            if (function_exists('zw_control_search_select')) {
                                zw_control_search_select(array(
                                    'id'          => 'tco-doc-dict-cc-depts',
                                    'name'        => 'cc_dept_keys[]',
                                    'multiple'    => true,
                                    'value'       => $flow['cc_dept_keys'],
                                    'placeholder' => '输入部门名称筛选',
                                    'empty_text'  => '没有匹配的部门',
                                    'options'     => $dict_dept_options,
                                ));
                            } else {
                                echo '<select name="cc_dept_keys[]" multiple size="5" style="min-height:110px">';
                                foreach ($dict_dept_options as $dict_option) {
                                    $dict_selected = in_array($dict_option['value'], $flow['cc_dept_keys'], true);
                                    echo '<option value="' . esc_attr($dict_option['value']) . '"' . ($dict_selected ? ' selected' : '') . '>' . esc_html($dict_option['label']) . '</option>';
                                }
                                echo '</select>';
                            }
                            ?>
                        </div>
                        <div class="tco-doc-field">
                            <label>抄送人可见时点</label>
                            <select name="cc_visibility">
                                <option value="approved"<?php selected($flow['cc_visibility'], 'approved'); ?>>审核通过后可见</option>
                                <option value="submitted"<?php selected($flow['cc_visibility'], 'submitted'); ?>>提交后可见</option>
                            </select>
                        </div>
                        <div class="tco-doc-field">
                            <label class="tco-touch"><input type="checkbox" name="allow_withdraw" value="1"<?php checked(!empty($flow['allow_withdraw'])); ?>> 待审核阶段允许发起人撤回</label>
                        </div>
                        <div class="tco-doc-field">
                            <label class="tco-touch"><input type="checkbox" name="allow_resubmit" value="1"<?php checked(!empty($flow['allow_resubmit'])); ?>> 驳回后允许修改重提</label>
                        </div>
                        <div class="tco-doc-field">
                            <label class="tco-touch"><input type="checkbox" name="download_log_enabled" value="1"<?php checked(!empty($flow['download_log_enabled'])); ?>> 记录附件下载日志</label>
                        </div>
                        <div class="tco-foot">
                            <button class="button button-primary" type="submit">保存公文流转字典</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}

/* ══════════════════════════════════════════════════════════════════════════
 * 五、权限功能树登记（非 administrator 角色可达性）
 * ══════════════════════════════════════════════════════════════════════════ */

if (!defined('ZW_DOC_DICT_PERMISSION_VERSION')) {
    define('ZW_DOC_DICT_PERMISSION_VERSION', '20261010-1');
}

if (!function_exists('zw_doc_dict_permission_version_key')) {
    /**
     * 本模块自有的迁移版本键（**不覆盖**既有共享版本字面量 `20260922-2`）。
     *
     * 依据 TASK-1297 的口径裁定（`inc/receipt/receipt-permissions.php` 同型先例）：
     * 既有 `zw_org_migrate_permission_module_functions()` 的版本字面量硬编码在
     * `inc/admin/org/org-permissions.php` 且被 `tests/test-dashboard-permissions.php` 断言为
     * `20260922-2`；抬高它会令既有迁移**每请求重跑**并回写版本（版本震荡 + 每请求写库）。
     * 故本单改用**独立版本键**做等价的「带版本幂等合并」。
     *
     * TASK-1534 追记：上述事故在 TASK-1521 真的发生了（只升守卫、漏改收尾值），TASK-1534 已把
     * 该共享字面量三处对齐为 `20261013-1`（`tests/test-dashboard-permissions.php` 断言同步更新）。
     * 本模块**继续使用独立版本键**的口径不变 —— 它本就不依赖那个共享字面量的具体取值。
     *
     * @return string
     */
    function zw_doc_dict_permission_version_key() {
        return 'zw_doc_dict_permission_functions_version';
    }
}

if (!function_exists('zw_doc_dict_permission_function_ids')) {
    /**
     * 需要登记进权限模块功能树的菜单功能 ID（与 WordPress 实际注册的 slug 逐字一致）。
     *
     * 不登记 ⇒ 非 administrator 角色（持 `tcpc_perm_doc`）会被
     * `zw_admin_nav_apply_permission_filter()` 按功能 ID 裁掉菜单，且请求模块桥接失效
     * ⇒ 页面内 `manage_options` 守卫会 403。
     *
     * @return string[]
     */
    function zw_doc_dict_permission_function_ids() {
        return array('sub:zw-doc:zw-doc-dict');
    }
}

if (!function_exists('zw_doc_dict_permission_merge_modules')) {
    /**
     * **纯函数**：把本页功能 ID 幂等合并进 `doc` 模块 `functions[]`（保序、不覆盖他人配置）。
     *
     * 不做任何 option 读写 ⇒ 可在单测中直接断言。
     *
     * @param array $modules 权限模块行（行列表）。
     * @return array 归一为 list 的模块行。
     */
    function zw_doc_dict_permission_merge_modules($modules) {
        $modules = is_array($modules) ? array_values($modules) : array();
        $ids     = zw_doc_dict_permission_function_ids();
        $found   = false;

        foreach ($modules as $index => $row) {
            if (!is_array($row) || !isset($row['key'])) {
                continue;
            }
            $key = function_exists('sanitize_key')
                ? sanitize_key((string) $row['key'])
                : strtolower((string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $row['key']));
            if ($key !== 'doc') {
                continue;
            }
            $found     = true;
            $functions = isset($row['functions']) && is_array($row['functions'])
                ? array_values($row['functions']) : array();
            foreach ($ids as $function_id) {
                if (!in_array($function_id, $functions, true)) {
                    $functions[] = $function_id;
                }
            }
            $modules[$index]['functions']            = array_values(array_unique($functions));
            $modules[$index]['functions_configured'] = 1;
        }

        if (!$found) {
            $modules[] = array(
                'key'                  => 'doc',
                'label'                => '发文管理',
                'description'          => '',
                'functions'            => array_values($ids),
                'functions_configured' => 1,
            );
        }

        return array_values($modules);
    }
}

if (!function_exists('zw_doc_dict_permission_missing_ids')) {
    /**
     * 尚未登记进 `doc` 模块的功能 ID（空数组 = 已齐备）。
     *
     * @param array $modules 权限模块行。
     * @return string[]
     */
    function zw_doc_dict_permission_missing_ids($modules) {
        $modules = is_array($modules) ? array_values($modules) : array();
        $have    = array();
        foreach ($modules as $row) {
            if (!is_array($row) || !isset($row['key'])) {
                continue;
            }
            $key = function_exists('sanitize_key')
                ? sanitize_key((string) $row['key'])
                : strtolower((string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $row['key']));
            if ($key !== 'doc') {
                continue;
            }
            if (isset($row['functions']) && is_array($row['functions'])) {
                foreach ($row['functions'] as $function_id) {
                    $have[] = (string) $function_id;
                }
            }
        }
        $missing = array();
        foreach (zw_doc_dict_permission_function_ids() as $function_id) {
            if (!in_array($function_id, $have, true)) {
                $missing[] = $function_id;
            }
        }

        return $missing;
    }
}

if (!function_exists('zw_doc_dict_permission_migrate')) {
    /**
     * 带版本的幂等迁移：把「数据字典」功能 ID 写进 `zw_org_permission_modules` 的 `doc` 模块。
     *
     * - 版本键 == 目标版本 ⇒ 直接返回（幂等，零写库）；
     * - option 缺失 ⇒ 先调 `zw_org_permission_modules()` 触发既有默认种子，再合并；
     * - 只追加缺失 ID，**不删除 / 不改写**既有功能项；
     * - 组织架构模块未加载 ⇒ 返回 `modules_unavailable` 且**不写版本键**，下次请求再试。
     *
     * @return array
     */
    function zw_doc_dict_permission_migrate() {
        $result = array('changed' => false, 'reason' => 'noop', 'added' => array(), 'missing_after' => array());

        if (!function_exists('get_option') || !function_exists('update_option')) {
            $result['reason'] = 'no_wp_option_api';
            return $result;
        }
        $version_key = zw_doc_dict_permission_version_key();
        $target      = ZW_DOC_DICT_PERMISSION_VERSION;
        if ((string) get_option($version_key, '') === (string) $target) {
            $result['reason'] = 'up_to_date';
            return $result;
        }
        if (!function_exists('zw_org_permission_modules')) {
            $result['reason'] = 'modules_unavailable';
            return $result;
        }

        $option_key = defined('ZW_ORG_PERMISSION_MODULES_OPTION') ? (string) ZW_ORG_PERMISSION_MODULES_OPTION : 'zw_org_permission_modules';
        $stored     = get_option($option_key, null);
        if (!is_array($stored)) {
            zw_org_permission_modules();
            $stored = get_option($option_key, null);
        }
        if (!is_array($stored)) {
            $result['reason'] = 'no_option';
            return $result;
        }

        $result['added']   = zw_doc_dict_permission_missing_ids($stored);
        $merged            = zw_doc_dict_permission_merge_modules($stored);
        $result['changed'] = ($merged !== array_values($stored));
        if ($result['changed']) {
            update_option($option_key, $merged, false);
        }
        update_option($version_key, $target, false);

        $result['reason']        = $result['changed'] ? 'merged' : 'already_registered';
        $result['missing_after'] = zw_doc_dict_permission_missing_ids($merged);

        return $result;
    }
}

if (function_exists('add_action')) {
    // 20：晚于既有 `zw_org_migrate_permission_module_functions()`（优先级 0），确保 option 已就绪。
    add_action('after_setup_theme', 'zw_doc_dict_permission_migrate', 20);
}
