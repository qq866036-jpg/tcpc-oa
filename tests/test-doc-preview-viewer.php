<?php
/**
 * OA-2529：撰写页「编辑正文 / 版式预览」分栏 + 单页整页预览 + 页头图标工具栏。
 *
 * 用户 2026-11-18 指令原文：
 *   「编辑正文 与预览中间的分割线 可左右拖拽固定比例，拖动比例作为默认值，版式预览 区域始终要显示完整的第一页，
 *    快捷功能（翻页、放大缩小、打印、另存为等 以及其他类似pdf预览功能 图标化）都放在页头（参考pdf编辑器）
 *    注释放在版式预览下方 即预览中不放功能和注释，版式预览不随页面移动」
 *
 * 判据（每条都能真红）：
 *  1. 分栏：`.zwc-grid` 用 `--zwc-split` 百分比、有 `#zwc-splitter`、松手即写 localStorage（下次即默认值）、
 *     键盘 ←/→ 可调、窄屏隐藏分隔条；
 *  2. 固定：`.zwc-right` 为 `position:sticky`（不随页面滚动），且没有被 `overflow` 破掉；
 *  3. 整页：paged 预览脚本按 `min(可用宽/页宽, 可用高/页高)` 缩放 ⇒ 每屏只显示一整页，默认第 1 页；
 *  4. 页头：翻页 / 页码 / 缩放 / 适应页面 / 打印 / 另存为 / 刷新 / 新窗口均为**图标按钮**，位于预览框**之前**；
 *  5. 预览内无功能、无注释：预览框内只有 iframe；说明在 `zwc-pv-foot`（预览框**之后**）；
 *  6. 两模式同源：默认 `continuous`（详情页 / 设计器 / 新窗口零变化），撰写页内嵌显式传 `paged`；
 *  7. 打印：paged 模式打印前把所有页面复位成静态 A4（否则只印当前页）。
 *
 * 运行：php tests/test-doc-preview-viewer.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once ABSPATH . 'includes/doc/doc-composer.php';

$GLOBALS['oa_pv_pass'] = 0;
$GLOBALS['oa_pv_fail'] = array();

function oa_pv_ok($msg) {
    $GLOBALS['oa_pv_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_pv_bad($msg) {
    $GLOBALS['oa_pv_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_pv_chk($cond, $msg) {
    if ($cond) {
        oa_pv_ok($msg);
    } else {
        oa_pv_bad($msg);
    }
}

$src = (string) file_get_contents(ABSPATH . 'includes/doc/doc-composer.php');

echo "== 1) 左右分栏：可拖拽 + 比例持久化 ==============\n";
oa_pv_chk(strpos($src, '--zwc-split:60%') !== false, '分栏变量 --zwc-split 有默认值（60%）');
oa_pv_chk(strpos($src, 'id="zwc-splitter"') !== false && strpos($src, 'role="separator"') !== false, '分隔条为可聚焦的 separator');
oa_pv_chk(strpos($src, 'aria-valuemin="38"') !== false && strpos($src, 'aria-valuemax="78"') !== false, '分隔条声明 38%~78% 夹紧');
oa_pv_chk(strpos($src, "'zwDocComposerSplitPct'") !== false, '比例写入固定 localStorage 键');
oa_pv_chk(strpos($src, 'applySplit(cur, true)') !== false, '松手时持久化当前比例（= 下次默认值）');
oa_pv_chk(strpos($src, "e.key === 'ArrowLeft'") !== false && strpos($src, "e.key === 'ArrowRight'") !== false, '聚焦后可 ←/→ 微调');
oa_pv_chk(strpos($src, '@media (max-width:1099px)') !== false && strpos($src, '.zwc-splitter{display:none}') !== false, '窄屏隐藏分隔条并回落堆叠');
oa_pv_chk(strpos($src, 'touch-action:none') !== false, '触摸拖拽不被页面滚动抢走');

echo "\n== 2) 预览不随页面移动（sticky） ==================\n";
oa_pv_chk(strpos($src, 'position:sticky;top:40px') !== false, '右栏 sticky 固定（top 40px，让开 32px 后台栏）');
oa_pv_chk(strpos($src, 'getBoundingClientRect().top') !== false, '滚动时按当前可见顶边重算高度（始终铺满视口）');
oa_pv_chk(strpos($src, "window.addEventListener('scroll', scheduleSize") !== false, '滚动时只调高度、不移动预览');

echo "\n== 3) 单页整页：每屏显示完整第一页 =================\n";
$paged    = zw_doc_preview_fit_script('paged');
$continuous = zw_doc_preview_fit_script();
oa_pv_chk(strpos($paged, 'Math.min(availW / w, availH / h)') !== false, 'paged 按「宽 / 高同时放下」缩放（fit-page，不是 fit-width）');
oa_pv_chk(strpos($paged, 'show(0)') !== false, '初始显示第 1 页');
oa_pv_chk(strpos($paged, "setAttribute('hidden'") !== false, '非当前页隐藏（一屏只有一页）');
oa_pv_chk(strpos($paged, 'zw-pdf-state') !== false && strpos($paged, 'zw-pdf-command') !== false, '页码/缩放回传父页、命令由父页下发');
oa_pv_chk(strpos($continuous, 'zw-pdf-stage') === false, '默认 continuous 不含单页舞台（详情页/设计器口径不变）');
oa_pv_chk(strpos($continuous, '#zw-pdf-view{padding:12px 12px 28px') !== false, '默认模式仍是 TASK-2060 的 fit-width 连续多页');

echo "\n== 4) 页头图标工具栏（参考 PDF 阅读器） ===========\n";
oa_pv_chk(strpos($src, 'role="toolbar" aria-label="预览工具栏"') !== false, '页头有 toolbar 语义');
foreach (array('prev' => '上一页', 'next' => '下一页', 'zoom-out' => '缩小', 'zoom-in' => '放大', 'fit' => '适应页面', 'print' => '打印', 'save' => '另存为') as $act => $label) {
    oa_pv_chk(strpos($src, 'data-zw-pv="' . $act . '"') !== false && strpos($src, 'aria-label="' . $label) !== false, "工具栏含「{$label}」图标按钮");
}
oa_pv_chk(substr_count($src, '<svg viewBox="0 0 24 24"') >= 9, '工具按钮全部是内联 SVG 图标（不是 emoji / 文字按钮）');
oa_pv_chk(strpos($src, '>刷新预览<') === false && strpos($src, '>预览当前内容<') === false, '旧的文字按钮不再出现');
oa_pv_chk(strpos($src, '@media(pointer:coarse)') !== false && strpos($src, 'width:44px;height:44px') !== false, '触屏热区 44×44');
oa_pv_chk(strpos($src, ':focus-visible{outline:2px solid #2271b1') !== false, '图标按钮有可见焦点环');
oa_pv_chk(strpos($src, 'transition:all') === false, '无 transition:all（动效铁律）');

echo "\n== 5) 预览内无功能、无注释（注释在预览下方） =======\n";
$mb = strpos($src, '<div class="zwc-pv-box"');
$mf = strpos($src, '<div class="zwc-pv-foot">');
$mh = strpos($src, '<div class="zwc-pv-head">');
oa_pv_chk($mh !== false && $mb !== false && $mf !== false && $mh < $mb && $mb < $mf, '结构顺序：页头 → 预览框 → 页脚说明');
oa_pv_chk($mb !== false && $mf !== false && strpos(substr($src, $mb, $mf - $mb), '<button') === false, '预览框内只有 iframe，没有按钮');
oa_pv_chk(strpos($src, 'class="zwc-pv-note"') !== false && $mf !== false && $mf < strpos($src, 'class="zwc-pv-note"'), '说明文字在预览框之后（下方）');
oa_pv_chk(strpos($src, 'id="zwc-preview-state"') !== false && strpos($src, 'zwc-pv-foot') < strpos($src, 'id="zwc-preview-state"'), '状态文字也在预览框下方');

echo "\n== 6) 两模式同源：撰写页显式 paged，新窗口连续 =======\n";
oa_pv_chk(strpos($src, "data.set('zw_doc_preview_mode', 'paged')") !== false, '内嵌预览显式请求 paged');
oa_pv_chk(strpos($src, "\$zw_preview_paged ? 'paged' : 'continuous'") !== false, '预览端点按白名单选模式');
oa_pv_chk(strpos($src, "'show_toolbar'   => !\$zw_preview_paged") !== false, 'paged 模式预览页不出渲染器自带工具栏');
oa_pv_chk(strpos($src, "formtarget=\"_blank\"") !== false, '「新窗口打开」仍是整表单 POST（完整打印视图）');

echo "\n== 7) 打印：paged 模式打印前复位全部页面 ==========\n";
oa_pv_chk(strpos($paged, 'beforeprint') !== false && strpos($paged, 'zw-printing') !== false, '打印前进入 zw-printing 静态态');
oa_pv_chk(strpos($paged, 'transform:none!important') !== false, '打印时撤掉缩放（按 A4 原尺寸出纸）');
oa_pv_chk(strpos($paged, 'afterprint') !== false, '打印后恢复单页视口');
oa_pv_chk(strpos($paged, 'window.print()') !== false, '打印按钮由预览页自己执行 window.print()');

$fitted = zw_doc_preview_fit_html('<html><body><div class="tco-doc-sheet">P1</div></body></html>', 'paged');
oa_pv_chk(strpos($fitted, 'zw-pdf-stage') !== false, 'fit_html 支持第二参数透传 paged');
oa_pv_chk(strpos($fitted, '</body>') !== false && strpos($fitted, 'zw-pdf-stage') < strpos($fitted, '</body>'), 'paged 片段注入在 </body> 之前');

echo "\n";
if (empty($GLOBALS['oa_pv_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_pv_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_pv_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_pv_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
