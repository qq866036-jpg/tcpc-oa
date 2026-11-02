<?php
/**
 * OA-2461 回归：任命书前端图在「本地（N 编号）证书」下的 local 分支。
 *
 * 跑法：php tests/test-doc-appoint-local-img.php
 * 退出码：0 全通过 / 1 有断言失败。
 *
 * 背景：任命书前端图原先只认证书数据里的「图片地址N」（龙威返回），本地证书没有该字段；
 * 切到电子证书插件后前端会空白。本单改为：local 走主题唯一构造点生成受鉴权端点 URL。
 *
 * 只做源码级不变式（不加载 WP、不连库、不联网）。
 */

$fail = 0;
function ol_ok($cond, $label) {
    global $fail;
    if ($cond) { echo "  ok   {$label}\n"; return; }
    $fail++;
    echo "  FAIL {$label}\n";
}
function ol_code($file) {
    $out = '';
    foreach (token_get_all((string) file_get_contents($file)) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

$root = dirname(__DIR__);
$src = ol_code($root . '/includes/doc/doc-appoint-cert.php');

echo "== OA-2461 任命书本地图源 ==\n";
ol_ok(strpos($src, 'zw_mc_cert_print_source(') !== false, 'A1 用唯一判据 zw_mc_cert_print_source()');
ol_ok(strpos($src, "=== 'local'") !== false, 'A2 仅 local 走新分支');
$p_local = strpos($src, 'zw_mc_cert_local_image_url(');
$p_probe = strpos($src, 'probe_one_cert_data(');
ol_ok($p_local !== false && $p_probe !== false && $p_local < $p_probe, 'A3 local 分支在龙威探针之前');
ol_ok(strpos($src, 'zw_mc_cert_local_pages(') !== false, 'B1 页数取 zw_mc_cert_local_pages()');
ol_ok(substr_count($src, 'zw_mc_cert_local_image_url(') === 1, 'B2 URL 只由唯一构造点生成（一份）');
ol_ok(strpos($src, 'admin-ajax.php?action=zw_mc_cert_local_image') === false, 'B3 不自拼端点 URL');
ol_ok(strpos($src, "function_exists('zw_mc_cert_print_source')") !== false, 'C1 判据缺失时回落');
ol_ok(strpos($src, "function_exists('zw_mc_cert_local_image_url')") !== false, 'C2 构造点缺失时回落');
ol_ok(strpos($src, 'cert_images_from_data(') !== false, 'D1 龙威分支保留');
ol_ok(strpos($src, "empty(\$probe['ok'])") !== false, 'D2 龙威失败仍返回空数组（不阻断）');
$pos = strpos($src, "=== 'local'");
$local_block = $pos !== false ? substr($src, $pos, 900) : '';
ol_ok($local_block !== '' && strpos($local_block, '图片地址') === false, 'E1 local 分支不读「图片地址」');

echo "\n结果：" . ($fail === 0 ? "全部通过\n" : $fail . " 项失败\n");
exit($fail === 0 ? 0 : 1);
