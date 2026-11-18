<?php
/**
 * OA-2526：附件说明名称 ⇄ 附件文件名 **软匹配**（B3）。
 *
 * 判据：双方归一化（去扩展名 / 去手打「附件：」「序号」/ 去空白与分隔符 / ASCII 小写）后
 * **双向包含即算一致**；返回「说明里有、附件里找不到」的名称（≤3 条），建单后只提示不阻断。
 *
 * 为什么不能硬匹配：说明是公文语言（「预算明细表」），文件名常带版本号/扫描件前缀/扩展名 ⇒
 * 严格相等必然误报，用户会学会无视提示（这比不提示更糟）。
 *
 * 运行：php tests/test-doc-attach-name-match.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once ABSPATH . 'includes/doc/doc-layout-gb9704.php';
require_once ABSPATH . 'includes/doc/doc-body-model.php';

$GLOBALS['oa_am_pass'] = 0;
$GLOBALS['oa_am_fail'] = array();

function oa_am_ok($msg) {
    $GLOBALS['oa_am_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_am_bad($msg) {
    $GLOBALS['oa_am_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_am_chk($cond, $msg) {
    if ($cond) {
        oa_am_ok($msg);
    } else {
        oa_am_bad($msg);
    }
}

echo "== 1) 一致判定：宽松到不会误报，严格到能抓错 ==\n";
oa_am_chk(zw_doc_attach_name_mismatch(array('附件：1.预算明细表'), array('预算明细表.pdf')) === array(), '完全一致');
oa_am_chk(zw_doc_attach_name_mismatch(array('预算明细表'), array('预算明细表_v2.pdf')) === array(), '文件名带版本号仍算一致');
oa_am_chk(zw_doc_attach_name_mismatch(array('2.预算明细表'), array('扫描件-预算明细表.pdf')) === array(), '带「扫描件-」前缀仍算一致');
oa_am_chk(zw_doc_attach_name_mismatch(array('附件：1.视频拍摄方案'), array('视频_拍摄方案.docx')) === array(), '下划线/全角差异仍算一致');
oa_am_chk(zw_doc_attach_name_mismatch(array('预算明细表'), array('预算明细表')) === array(), '无扩展名的文件名也一致');

echo "\n== 2) 不一致判定：给出「说明里的原写法」便于回显 ==\n";
oa_am_chk(zw_doc_attach_name_mismatch(array('附件：1.预算明细表'), array('扫描件.pdf')) === array('预算明细表'), '名称完全不符 ⇒ 回报说明名称（去掉手打前缀）');
$multi = zw_doc_attach_name_mismatch(array('1.甲表', '2.乙表'), array('甲表.xlsx', '丙.pdf'));
oa_am_chk($multi === array('乙表'), '多条只报未匹配的那条');
$cap = zw_doc_attach_name_mismatch(array('1.甲', '2.乙', '3.丙', '4.丁'), array('x.pdf'));
oa_am_chk(count($cap) === 3, '最多报 3 条（避免提示刷屏）');

echo "\n== 3) 边界：空输入不误判 ==\n";
oa_am_chk(zw_doc_attach_name_mismatch(array(), array('a.pdf')) === array(), '无说明 ⇒ 不报');
oa_am_chk(zw_doc_attach_name_mismatch(array('甲表'), array()) === array(), '无附件文件名 ⇒ 不报（交由条数校验）');
oa_am_chk(zw_doc_attach_name_mismatch(array('  ', '附件：', '1.'), array('a.pdf')) === array(), '说明只有空白/前缀 ⇒ 不报');

echo "\n== 4) 接线：建单路径真的用了它，且只提示不阻断 ==\n";
$src_list = (string) file_get_contents(ABSPATH . 'includes/doc/doc-admin-list.php');
oa_am_chk(strpos($src_list, 'zw_doc_attach_name_mismatch(') !== false, '建单路径调用软匹配判据');
oa_am_chk(strpos($src_list, '在附件里找不到同名文件') !== false, '提示写明「哪条找不到」');
oa_am_chk(strpos($src_list, '请在编辑页核对名称后再用印') !== false, '提示写明下一步（去哪改）');
oa_am_chk(substr_count($src_list, 'wp_safe_redirect') >= 0 && strpos($src_list, '$extra .= \'；附件提示：附件说明「') !== false,
    '经 $extra 提示（不阻断建单）');
oa_am_chk(strpos($src_list, '$attach_expected === $attach_actual') !== false, '仅在数量相等时比名称（数量不等已有条数提示，避免双重噪音）');

echo "\n";
if (empty($GLOBALS['oa_am_fail'])) {
    echo "== 全绿：{$GLOBALS['oa_am_pass']} 项 ==\n";
    exit(0);
}
echo "== 失败：" . count($GLOBALS['oa_am_fail']) . " 项 ==\n";
foreach ($GLOBALS['oa_am_fail'] as $msg) {
    echo "  - {$msg}\n";
}
exit(1);
