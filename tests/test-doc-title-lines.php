<?php
/**
 * OA-2513：公文标题**手动折行**（`meta.title_lines`）在写读链路中的存活断言。
 *
 * 背景（用户报障原文）：「新建发文 公文标题 手动换行后不生效」。
 * 真因：`zw_doc_body_normalize()` 对 TASK-2014 新增的 `meta.title_lines` 不幂等 ——
 * 第一遍把手动断行写成「`meta.title` 单行（空格拼回）+ `title_lines` 数组」，
 * 第二遍进来时 `meta.title` 已无换行 ⇒ `title_lines` 被重置为 `[]`；
 * 而 `zw_doc_update_body()` / 建单 / `zw_doc_body_parse()` / `zw_doc_body_to_json()` **四处都在再归一**
 * ⇒ 断行在落库与读库各丢一次，渲染层只能落到自动回行（TASK-1342 算法）。
 *
 * 本测试因此**不只测一次归一**，而是照真实链路逐步再归一（这正是旧实现漏掉的场景），并带反向控制：
 * 清空 `title_lines` 后 `zw_doc_title_resolve_lines()` 必须给出**不同**的断行（证明断言有判别力）。
 *
 * 运行：php tests/test-doc-title-lines.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/includes/doc/doc-layout-gb9704.php';
require_once dirname(__DIR__) . '/includes/doc/doc-body-model.php';
// 只取纯函数 `zw_doc_title_resolve_lines()`（HTML / DOCX 两通道共用的唯一解析入口）。
require_once dirname(__DIR__) . '/includes/doc/doc-render-html.php';

$GLOBALS['oa_tl_pass'] = 0;
$GLOBALS['oa_tl_fail'] = array();

function oa_tl_ok($msg) {
    $GLOBALS['oa_tl_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_tl_bad($msg) {
    $GLOBALS['oa_tl_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_tl_chk($cond, $msg) {
    if ($cond) {
        oa_tl_ok($msg);
    } else {
        oa_tl_bad($msg);
    }
}

/**
 * 生产实证里的真实标题（33351 · 中传函〔2026〕98号）：作者在「撤销」后回车断行。
 *
 * @return string
 */
function oa_tl_manual_title() {
    return "中国传统文化促进会关于撤销\n部分分支机构的报告";
}

/**
 * 模拟正文模型的一次完整写读链路：POST 原文 → 归一 → `zw_doc_update_body()` 再归一 →
 * 序列化 → `zw_doc_body_parse()` 读回。返回最终 AST 与中间态，供逐步断言。
 *
 * @param array $raw 原始 AST（`meta.title` 可含换行）。
 * @return array{posted:array,on_save:array,json:string,read_back:array}
 */
function oa_tl_pipeline($raw) {
    $posted    = zw_doc_body_normalize($raw);           // 撰写页 body_from_post() 的那一遍
    $on_save   = zw_doc_body_normalize($posted);        // zw_doc_update_body() 内部再归一
    $json      = zw_doc_body_to_json($on_save);         // 落库序列化（内部又归一）
    $read_back = zw_doc_body_parse($json);              // 详情页/打印/DOCX 读回路

    return array(
        'posted'    => $posted,
        'on_save'   => $on_save,
        'json'      => $json,
        'read_back' => $read_back,
    );
}

echo "== 1) 单遍归一：含换行的标题被切成 title_lines ==\n";
$once = zw_doc_body_normalize(array(
    'v'     => 1,
    'meta'  => array('title' => oa_tl_manual_title()),
    'blocks' => array(array('t' => 'p', 'text' => '正文')),
));
oa_tl_chk($once['meta']['title_lines'] === array('中国传统文化促进会关于撤销', '部分分支机构的报告'), '断行数组 = 手动两行');
oa_tl_chk($once['meta']['title'] === '中国传统文化促进会关于撤销 部分分支机构的报告', 'meta.title 仍存单行（列表/检索口径不变）');

echo "\n== 2) 幂等：再归一不得清空（旧实现此处判红） ==\n";
$twice = zw_doc_body_normalize($once);
oa_tl_chk($twice['meta']['title_lines'] === $once['meta']['title_lines'], 'normalize(normalize(x)) 的 title_lines 与一次归一相同');
oa_tl_chk($twice === $once, 'normalize(normalize(x)) === normalize(x)（整份 AST 逐字段相等）');

echo "\n== 3) 真实写读链路：落库前后断行都在 ==\n";
$pipe = oa_tl_pipeline(array(
    'v'      => 1,
    'meta'   => array('title' => oa_tl_manual_title()),
    'blocks' => array(array('t' => 'p', 'text' => '正文')),
));
oa_tl_chk($pipe['on_save']['meta']['title_lines'] === array('中国传统文化促进会关于撤销', '部分分支机构的报告'), 'update_body 再归一后仍有两行');
oa_tl_chk(strpos($pipe['json'], '"title_lines":["中国传统文化促进会关于撤销","部分分支机构的报告"]') !== false, '落库 JSON 里 title_lines 非空');
oa_tl_chk($pipe['read_back']['meta']['title_lines'] === array('中国传统文化促进会关于撤销', '部分分支机构的报告'), '读回（body_parse）后仍有两行');

echo "\n== 4) 渲染：手动断行优先，且与自动回行结果不同（反向控制） ==\n";
$manual_lines = zw_doc_title_resolve_lines(
    (string) $pipe['read_back']['meta']['title'],
    (array) $pipe['read_back']['meta']['title_lines']
);
oa_tl_chk($manual_lines === array('中国传统文化促进会关于撤销', '部分分支机构的报告'), '读到手动两行（作者意图）');
$auto_lines = zw_doc_title_resolve_lines((string) $pipe['read_back']['meta']['title'], array());
oa_tl_chk($auto_lines !== $manual_lines, '去掉 title_lines 后自动回行给出不同断行 ⇒ 断言有判别力');
oa_tl_chk(implode('', $manual_lines) === '中国传统文化促进会关于撤销部分分支机构的报告', '手动断行不增删字（拼接后与原文一致）');

echo "\n== 5) 三档口径：含换行优先，其次沿用入参，都没有才清空 ==\n";
// 5a 用户删掉断行（撰写页提交单行、不带 title_lines）⇒ 必须真的清空，不是只增不减。
$cleared = zw_doc_body_normalize(array(
    'v'    => 1,
    'meta' => array('title' => '中国传统文化促进会关于撤销部分分支机构的报告', 'title_lines' => array()),
));
oa_tl_chk($cleared['meta']['title_lines'] === array(), '单行且 title_lines 为空 ⇒ 清空（允许作者取消断行）');
// 5b 标题含换行时以原文为准，不被旧 title_lines 覆盖（作者改了断点）。
$edited = zw_doc_body_normalize(array(
    'v'    => 1,
    'meta' => array('title' => "中国传统文化促进会\n关于撤销部分分支机构的\n报告", 'title_lines' => array('旧', '断行')),
));
oa_tl_chk($edited['meta']['title_lines'] === array('中国传统文化促进会', '关于撤销部分分支机构的', '报告'), '换行原文优先，旧 title_lines 被覆盖');

echo "\n== 6) 上限与脏值：≤3 行、去空行、非标量跳过 ==\n";
$cap = zw_doc_body_normalize(array(
    'v'    => 1,
    'meta' => array('title' => "第一行\n\n第二行\n第三行\n第四行"),
));
oa_tl_chk($cap['meta']['title_lines'] === array('第一行', '第二行', '第三行'), '第 4 行被截断、空行被丢弃');
$dirty = zw_doc_body_normalize(array(
    'v'    => 1,
    'meta' => array('title' => '标题', 'title_lines' => array('甲', array('脏'), '乙')),
));
oa_tl_chk($dirty['meta']['title_lines'] === array('甲', '乙'), '非标量元素被跳过（不产生「Array」字面量）');

echo "\n== 7) 回归：appointments 受控分支不受影响 ==\n";
$ap = zw_doc_body_normalize(array(
    'v'    => 1,
    'meta' => array('title' => oa_tl_manual_title(), 'appointments' => array('v' => 1, 'type' => '任', 'scope' => 'branch', 'items' => array())),
));
$ap_decoded = json_decode((string) $ap['meta']['appointments'], true);
oa_tl_chk(
    is_array($ap_decoded) && isset($ap_decoded['scope']) && $ap_decoded['scope'] === 'branch' && isset($ap_decoded['items']),
    'appointments 仍是结构化 JSON（scope/items 未被拍平成文本）'
);

echo "\n";
if (empty($GLOBALS['oa_tl_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_tl_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_tl_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_tl_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
