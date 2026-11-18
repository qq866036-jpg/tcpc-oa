<?php
/**
 * OA-2531：发文详情左右布局（预览右侧固定）+ 红头配置下线 + 栏目改「印章管理」。
 *
 * 用户 2026-11-18 指令原文：
 *   「发文详情 页面 版式预览 与其他设置项 左右布局 预览放在右侧 且不可移动（参考 新建发文 版式和样式完全对齐），
 *    红头选项删除 并且删除 红头与印章 页面的红头配置 该栏目改为印章管理」
 * 用户确认口径：只删**界面配置**（详情页红头下拉 + 设置页红头模板 Tab），红头渲染仍为系统自动生成；
 * 详情页预览**完全复用**新建发文的预览面板；概览通栏、其下左右分栏。
 *
 * 判据（每条都能真红）：
 *  1. 布局：概览通栏 → 左右分栏（左=设置卡片流，右=预览），右栏 `sticky` 不随页面滚动；
 *  2. 唯一出处：撰写页与详情页都调用 `zw_doc_preview_panel_html/_script/_css()`，详情页不再自带 iframe；
 *  3. 详情页预览 = 单页整页 + 页头图标工具栏 + 打印/另存为 + 导出 DOCX / 编辑正文；
 *  4. 红头界面配置已删除（详情页下拉、数据字典只读卡、设置页 Tab、菜单名），渲染逻辑仍保留；
 *  5. 设置页 = 「印章管理」（标题 / 菜单 / 无 Tab / 不再调用红头区块）。
 *
 * 运行：php tests/test-doc-detail-preview-panel.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

$GLOBALS['oa_dpv_pass'] = 0;
$GLOBALS['oa_dpv_fail'] = array();

function oa_dpv_ok($msg) {
    $GLOBALS['oa_dpv_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_dpv_bad($msg) {
    $GLOBALS['oa_dpv_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_dpv_chk($cond, $msg) {
    if ($cond) {
        oa_dpv_ok($msg);
    } else {
        oa_dpv_bad($msg);
    }
}

$src_list     = (string) file_get_contents(ABSPATH . 'includes/doc/doc-admin-list.php');
$src_composer = (string) file_get_contents(ABSPATH . 'includes/doc/doc-composer.php');
$src_render   = (string) file_get_contents(ABSPATH . 'includes/doc/doc-render-html.php');
$src_seal     = (string) file_get_contents(ABSPATH . 'includes/doc/doc-seal.php');
$src_dict     = (string) file_get_contents(ABSPATH . 'includes/doc/doc-admin-dict.php');
$src_boot     = (string) file_get_contents(ABSPATH . 'includes/doc/doc-bootstrap.php');
$src_lh       = (string) file_get_contents(ABSPATH . 'includes/doc/doc-letterhead.php');

echo "== 1) 详情页：概览通栏 + 左右分栏 + 预览固定 ==========\n";
$p_overview = strpos($src_list, '<h2>概览</h2>');
$p_grid     = strpos($src_list, '<div class="tco-doc-detail-grid">');
$p_face     = strpos($src_list, 'id="tco-doc-face"');
oa_dpv_chk($p_overview !== false && $p_grid !== false && $p_face !== false && $p_overview < $p_grid && $p_grid < $p_face, '概览通栏在上，其后才开始左右分栏');
oa_dpv_chk(strpos($src_list, 'class="tco-doc-detail-main"') !== false, '左栏容器存在（放其余设置卡片）');
oa_dpv_chk(strpos($src_list, 'class="tco-doc-detail-side"') !== false, '右栏容器存在（只放版式预览）');
oa_dpv_chk(strpos($src_list, '.tco-doc-detail-side{position:sticky;top:40px;align-self:start') !== false, '右栏 sticky 固定（不随页面滚动）');
oa_dpv_chk(strpos($src_list, '@media (max-width:1199px)') !== false && strpos($src_list, '.tco-doc-detail-side{position:static}') !== false, '窄屏回落单列（不产生横向滚动）');
$p_side  = strpos($src_list, 'class="tco-doc-detail-side"');
$p_prev  = strpos($src_list, 'id="tco-doc-preview"');
oa_dpv_chk($p_side !== false && $p_prev !== false && $p_side < $p_prev, '版式预览在右栏（不在左栏卡片流里）');
$p_main_close = strpos($src_list, '</div><!-- /.tco-doc-detail-main -->');
oa_dpv_chk($p_main_close !== false && $p_side > $p_main_close, '左栏先闭合、再渲染右栏（结构合法）');

echo "\n== 2) 与新建发文同一面板组件（唯一出处） ==============\n";
oa_dpv_chk(substr_count($src_composer, 'function zw_doc_preview_panel_html(') === 1, '面板结构只有一处定义');
oa_dpv_chk(substr_count($src_composer, 'function zw_doc_preview_panel_script(') === 1, '面板脚本只有一处定义');
oa_dpv_chk(substr_count($src_composer, 'function zw_doc_preview_panel_css(') === 1, '面板样式只有一处定义');
oa_dpv_chk(strpos($src_list, 'zw_doc_preview_panel_html(') !== false && strpos($src_list, 'zw_doc_preview_panel_script(') !== false, '详情页调用同一面板结构 + 同一脚本');
oa_dpv_chk(strpos($src_list, 'zw_doc_preview_panel_css()') !== false, '详情页输出同一面板样式');
oa_dpv_chk(strpos($src_list, '<iframe') === false, '详情页不再自带第二套 iframe/预览实现');
oa_dpv_chk(strpos($src_composer, 'zw_doc_preview_panel_html(') !== false && strpos($src_composer, 'zw_doc_preview_panel_script(') !== false, '撰写页同样调用同一组件');
oa_dpv_chk(strpos($src_composer, "'mode'          => 'form'") !== false, '撰写页用 form 模式');
oa_dpv_chk(strpos($src_list, "'mode'      => 'url'") !== false, '详情页用 url 模式');

echo "\n== 3) 详情页预览内容：单页整页 + 页头工具栏 + 额外入口 ==\n";
oa_dpv_chk(strpos($src_list, "'iframe_src' => zw_doc_embed_url(\$doc['id'])") !== false, '若iframe 走 embed 预览入口（单页整页）');
oa_dpv_chk(strpos($src_render, 'mode=paged') !== false || strpos($src_render, "\$embed_args['mode'] = 'paged'") !== false, 'embed URL 声明 paged');
oa_dpv_chk(strpos($src_render, 'name="zw-doc-preview-fit" content="paged"') !== false, 'paged 由文档头 meta 声明');
oa_dpv_chk(strpos($src_composer, 'name="zw-doc-preview-fit" content="paged"') !== false, 'fit 入口识别 meta 并选 paged 脚本');
oa_dpv_chk(strpos($src_list, 'zw_doc_docx_export_url($doc[\'id\'])') !== false && strpos($src_list, "'icon' => 'docx'") !== false, '页头保留「导出 DOCX」图标入口');
oa_dpv_chk(strpos($src_list, "'icon' => 'edit'") !== false, '页头保留「编辑正文」图标入口（有权限时）');
oa_dpv_chk(strpos($src_list, "zw_doc_print_url(\$doc['id'])") !== false, '打印走既有打印端点（A4 1:1）');
oa_dpv_chk(strpos($src_list, "'save'       => true") !== false, '提供「另存为 HTML」');
oa_dpv_chk(strpos($src_list, 'zw_doc_can_edit_doc($doc)') !== false, '编辑入口按权限门控（无权限不渲染死按钮）');

echo "\n== 4) 红头界面配置下线（渲染逻辑保留） ================\n";
oa_dpv_chk(strpos($src_list, 'letterhead_id') === false, '详情页「红头」下拉已删除');
oa_dpv_chk(strpos($src_list, 'letterhead_pick') === false, '详情页不再提交红头选择');
oa_dpv_chk(strpos($src_list, '红头和印章') === false && strpos($src_list, '红头与印章') === false, '详情页不再出现旧栏目名');
oa_dpv_chk(strpos($src_dict, "zw_doc_admin_card_head('红头生成方式") === false && strpos($src_dict, 'zw_doc_letterhead_mode_state') === false, '数据字典页「红头生成方式」卡已删除');
oa_dpv_chk(strpos($src_seal, 'nav-tab-wrapper') === false && strpos($src_seal, ">红头模板</a>") === false, '设置页不再有 Tab / 红头模板入口');
oa_dpv_chk(strpos($src_seal, 'zw_doc_render_letterhead_admin_section') === false, '设置页不再渲染红头区块');
oa_dpv_chk(strpos($src_seal, "strpos(\$action, 'letterhead_')") === false, '设置页不再受理红头写操作');
oa_dpv_chk(strpos($src_boot, "'印章管理'") !== false && strpos($src_boot, "'红头与印章'") === false, '后台菜单改名为「印章管理」');
oa_dpv_chk(strpos($src_composer, 'function zw_doc_letterhead_resolve(') === false && strpos($src_lh, 'function zw_doc_letterhead_resolve(') !== false, '红头渲染函数仍保留（只删界面配置）');
oa_dpv_chk(strpos($src_render, "<a href=\"' . esc_url(\$hint)") === false, '红头 fail-closed 不再输出已失效的「前往红头模板」链接');

echo "\n== 5) 设置页 = 「印章管理」 ===========================\n";
oa_dpv_chk(strpos($src_seal, '<h1>印章管理</h1>') !== false, '页面标题为「印章管理」');
oa_dpv_chk(strpos($src_seal, 'zw_doc_render_seal_admin_section();') !== false, '页面主体仍是印章管理区块');
oa_dpv_chk(strpos($src_composer, '.zwc-pv-card{display:flex;flex-direction:column;position:sticky;top:40px') !== false, '面板卡片样式为唯一出处（含 sticky）');

echo "\n";
if (empty($GLOBALS['oa_dpv_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_dpv_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_dpv_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_dpv_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
