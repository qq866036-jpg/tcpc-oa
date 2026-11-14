<?php
/**
 * OA-2498 S1：政策正文内容规则（插件侧唯一实现）行为断言。
 *
 * 运行：php tests/test-oa-legal-content-rules.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/includes/policy/legal-content-rules.php';

$GLOBALS['oa_rules_pass'] = 0;
$GLOBALS['oa_rules_fail'] = array();

function oa_rules_ok($msg) {
    $GLOBALS['oa_rules_pass']++;
    echo "  ✓ {$msg}\n";
}

function oa_rules_bad($msg) {
    $GLOBALS['oa_rules_fail'][] = $msg;
    fwrite(STDERR, "  ✗ {$msg}\n");
}

function oa_rules_chk($cond, $msg) {
    if ($cond) { oa_rules_ok($msg); } else { oa_rules_bad($msg); }
}

/**
 * 构造一份"最小但齐全"的隐私政策正文（含全部必备章节，无禁用词）。
 *
 * @return string
 */
function oa_rules_privacy_fixture() {
    $parts = array(
        '<h1>隐私政策</h1>',
        '<p>生效日期：2026-11-14　更新日期：2026-11-14</p>',
        '<h2>一、我们如何收集和使用您的个人信息</h2><p>我们仅收集为提供服务所必需的信息。</p>',
        '<h2>二、我们如何使用Cookie和同类技术</h2><p>我们使用必要Cookie维持登录状态。</p>',
        '<h2>三、我们如何存储和保护您的个人信息</h2><p>我们在境内存储并采取加密与访问控制。</p>',
        '<h2>四、我们如何共享、转让、公开披露您的个人信息</h2><p>除法定情形外，我们不对外共享。</p>',
        '<h2>五、您的权利</h2><p>您可以查询、更正、删除您的个人信息。</p>',
        '<h2>六、未成年人保护</h2><p>我们不会主动收集未成年人信息。</p>',
        '<h2>七、隐私政策的更新</h2><p>更新时我们会在本页公示。</p>',
        '<h2>八、存储期限</h2><p>我们按法定与业务必要期限保存。</p>',
        '<h2>九、第三方 SDK</h2><p>我们接入的第三方服务均在本政策列明。</p>',
        '<h2>十、联系我们</h2><p>您可通过本会公布的联系方式与我们联系。</p>',
        '<h2>十一、个人信息保护负责人</h2><p>我们设有个人信息保护负责人。</p>',
    );
    return implode("\n", $parts);
}

echo "== 1) 规则版本与规则集 ==\n";
oa_rules_chk(tcpc_oa_legal_content_rules_version() >= 1, '规则版本 >= 1');
oa_rules_chk(is_array(tcpc_oa_legal_content_rules_for('SRC-PRIVACY-POLICY')), '隐私政策有规则集');
oa_rules_chk(is_array(tcpc_oa_legal_content_rules_for('SRC-USER-AGREEMENT')), '用户协议有规则集');
oa_rules_chk(tcpc_oa_legal_content_rules_for('SRC-CHARTER') === null, '未登记文档返回 null（不误伤）');

echo "== 2) 隐私政策：齐全正文通过 ==\n";
$fixture = oa_rules_privacy_fixture();
$res = tcpc_oa_legal_content_check('SRC-PRIVACY-POLICY', $fixture);
oa_rules_chk(!empty($res['ok']), '齐全正文 ⇒ ok=true');
oa_rules_chk($res['plainLength'] > 0, 'plainLength 计算 > 0');
oa_rules_chk(empty($res['errors']), '无错误');

echo "== 3) 隐私政策：缺必备章节 / 禁用词 / 超长 ⇒ 判红 ==\n";
$missing = str_replace('未成年人保护', '', $fixture);
$res = tcpc_oa_legal_content_check('SRC-PRIVACY-POLICY', $missing);
oa_rules_chk(empty($res['ok']), '缺「未成年人保护」⇒ ok=false');
oa_rules_chk((bool) preg_grep('/缺少必需内容/u', $res['errors']), '错误列表点名缺少必备内容');

$res = tcpc_oa_legal_content_check('SRC-PRIVACY-POLICY', $fixture . '<p>请到工作台点击按钮。</p>');
oa_rules_chk(empty($res['ok']), '含「工作台/点击/按钮」⇒ ok=false');
oa_rules_chk((bool) preg_grep('/禁用词/u', $res['errors']), '错误列表点名禁用词');

$oversize = $fixture . '<p>' . str_repeat('字', 22200) . '</p>';
$res = tcpc_oa_legal_content_check('SRC-PRIVACY-POLICY', $oversize);
oa_rules_chk(empty($res['ok']), '超 22100 字 ⇒ ok=false');
oa_rules_chk((bool) preg_grep('/超过 22100 字上限/u', $res['errors']), '错误列表点名超长');

echo "== 4) 用户协议：通过 / 禁用词 / 未登记文档 ==\n";
$agreement = '<h1>用户协议</h1><p>生效日期：2026-11-14</p><p>本会与用户订立本协议。</p>';
$res = tcpc_oa_legal_content_check('SRC-USER-AGREEMENT', $agreement);
oa_rules_chk(!empty($res['ok']), '用户协议最小正文 ⇒ ok=true');
$res = tcpc_oa_legal_content_check('SRC-USER-AGREEMENT', $agreement . '<p>字段清单见数据库表。</p>');
oa_rules_chk(empty($res['ok']), '用户协议含「字段清单/数据库表」⇒ ok=false');
$res = tcpc_oa_legal_content_check('SRC-CHARTER', '<p>章程正文</p>');
oa_rules_chk(!empty($res['ok']), '未登记文档 ⇒ 跳过硬校验（ok=true）');

echo "== 5) 错误摘要（给后台提示条） ==\n";
$summary = tcpc_oa_legal_content_error_summary(array('a', 'b', 'c', 'd'));
oa_rules_chk(strpos($summary, '等共 4 项') !== false, '超过 3 条时给出总数');
oa_rules_chk(tcpc_oa_legal_content_error_summary(array()) !== '', '空列表也有兜底文案');

if ($GLOBALS['oa_rules_fail']) {
    fwrite(STDERR, "FAIL: OA-2498 政策内容规则断言未通过（{$GLOBALS['oa_rules_pass']} 项通过 / "
        . count($GLOBALS['oa_rules_fail']) . " 项失败）\n");
    exit(1);
}

echo "OA-2498 政策内容规则通过（{$GLOBALS['oa_rules_pass']} 项断言全绿）\n";
exit(0);
