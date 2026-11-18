<?php
/**
 * OA-2519：详情页「流程可读化」—— 步骤条 / 下一步主行动 / 全量锚点导航的静态守卫。
 *
 * 诊断背景：详情页区块顺序是 概览 → 纸面要素 → 流转 → **完成文件** → 任命同步 → **版式预览** →
 * 法务 → 用印 → 审核操作 → 发起人操作 → 流转记录。其中「完成文件」排在法务/用印之前是
 * **TASK-1382 ③ 的既定决策**（用户要求首屏能看到已完成文件），不是 bug；真正的问题是
 * 页面**从未说明真实顺序与前置关系**，锚点也只覆盖 4 个区块 ⇒ 读起来"流程混乱"。
 *
 * 本单因此**不搬动卡片**（不推翻既定 IA），改为显式化：① 步骤条；② 一条「下一步」主行动；
 * ③ 锚点覆盖全部区块（且不产生死锚点）；④ 完成文件未用印时标题带前置提示。
 *
 * 运行：php tests/test-doc-detail-flow-nav.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

$src = (string) file_get_contents(ABSPATH . 'includes/doc/doc-admin-list.php');

$GLOBALS['oa_fn_pass'] = 0;
$GLOBALS['oa_fn_fail'] = array();

function oa_fn_ok($msg) {
    $GLOBALS['oa_fn_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_fn_bad($msg) {
    $GLOBALS['oa_fn_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_fn_chk($cond, $msg) {
    if ($cond) {
        oa_fn_ok($msg);
    } else {
        oa_fn_bad($msg);
    }
}

echo "== 1) 流程步骤条：四步、顺序固定 ==\n";
oa_fn_chk(strpos($src, 'class="tco-doc-steps"') !== false, '存在步骤条容器');
oa_fn_chk(strpos($src, 'class="tco-doc-step') !== false, '存在步骤项样式');
$labels = array('审核', '法务', '用印', '完成文件');
$pos = array();
foreach ($labels as $label) {
    $needle = "array('label' => '" . $label . "'";
    $pos[$label] = strpos($src, $needle);
    oa_fn_chk($pos[$label] !== false, '步骤含「' . $label . '」');
}
oa_fn_chk($pos['审核'] < $pos['法务'] && $pos['法务'] < $pos['用印'] && $pos['用印'] < $pos['完成文件'],
    '步骤顺序 ①审核 → ②法务 → ③用印 → ④完成文件（与真实门禁一致）');

echo "\n== 2) 「下一步」主行动：四条状态分支齐全 ==\n";
oa_fn_chk(strpos($src, '<strong>下一步：</strong>') !== false, '首屏有「下一步」一行');
oa_fn_chk(strpos($src, '先完成「审核」') !== false, '未审核 ⇒ 引导去审核');
oa_fn_chk(strpos($src, '下一步是「法务」') !== false, '已审核未过法务 ⇒ 引导去法务');
oa_fn_chk(strpos($src, '下一步是「用印」') !== false, '法务通过未用印 ⇒ 引导去用印');
oa_fn_chk(strpos($src, '下一步是「完成文件」') !== false, '已用印未生成 ⇒ 引导去生成');
oa_fn_chk(strpos($src, '流程已走完，可直接下载完成文件') !== false, '已完成 ⇒ 给下载入口');

echo "\n== 3) 锚点导航：覆盖全部区块、且无死锚点 ==\n";
$anchors = array('#tco-doc-face', '#tco-doc-preview', '#tco-doc-flow', '#tco-doc-legal', '#tco-doc-seal', '#tco-doc-files', '#tco-doc-events');
foreach ($anchors as $anchor) {
    $id = substr($anchor, 1);
    oa_fn_chk(strpos($src, 'href="' . $anchor . '"') !== false, '导航含 ' . $anchor);
    oa_fn_chk(strpos($src, 'id="' . $id . '"') !== false, '目标存在：id="' . $id . '"（无死锚点）');
}

echo "\n== 4) 完成文件的前置关系写在标题上 ==\n";
oa_fn_chk(strpos($src, '需先完成「用印」，用印后才能生成') !== false, '未用印时标题带「需先完成用印」提示');
oa_fn_chk(strpos($src, "(string) \$doc['seal_mode'] !== 'formal'") !== false, '提示按印章状态条件渲染（不是无条件文案）');

echo "\n== 5) 不推翻既定 IA（未搬动卡片，仍保留 TASK-1382 ③ 的顺序决策）==\n";
$i_flow = strpos($src, 'id="tco-doc-flow"');
$i_files = strpos($src, 'id="tco-doc-files"');
$i_legal = strpos($src, 'id="tco-doc-legal"');
oa_fn_chk($i_flow !== false && $i_files !== false && $i_flow < $i_files, '完成文件仍在流转之后（首屏可见，保留既定决策）');
oa_fn_chk($i_files < $i_legal, '法务仍在完成文件之后（顺序未改，靠步骤条说明前置关系）');

echo "\n";
if (empty($GLOBALS['oa_fn_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_fn_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_fn_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_fn_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
