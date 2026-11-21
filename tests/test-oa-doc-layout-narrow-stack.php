<?php
/**
 * OA-2536：`zw-doc-layout` 窄屏（<1100px）必须真的堆叠 —— 内联样式不得遮蔽 `@media` 分支。
 *
 * 背景（2026-11-21 生产四档实测）：
 *   该页 `docOverflow` = 263 / 263 / 199 / 0（375 / 768 / 820 / 1280），
 *   offenders 指向 `div.tco-doc-layout-params` **宽仅 12px**、其子按钮与文字溢出视口 263px。
 *   根因不是缺媒体查询 —— `:649-653` **已经**写了 `@media (max-width:1099px){flex-wrap:wrap;height:auto}`，
 *   而是行容器 `.tco-doc-layout-row` 的内联 `flex-wrap:nowrap` 与 `height:calc(100vh - 200px)`
 *   **优先级高于样式表**，把该分支打成死代码 ⇒ 窄屏仍是「预览 100% + 参数列被压缩」。
 *
 * 判据（源码级，静态可判）：
 *  1. 行容器的**内联** `style` 不得再出现 `flex-wrap` / `height`（两者是媒体查询要覆盖的属性）；
 *     行容器内联仍须保留 `display:flex`（桌面两栏不回归）。
 *  2. 桌面行高必须有样式表出处（`body.tco-doc-layout-screen .tco-doc-layout-row{height:…}`），
 *     否则删掉内联 height 会让桌面塌陷。
 *  3. `@media (max-width:1099px)` 分支必须同时给出 `flex-wrap:wrap` 与 `height:auto`、
 *     隐藏分隔条、并把预览列置为 100%（三者缺一，窄屏仍会并排或被 8px 分隔条挤占）。
 *
 * 运行：php tests/test-doc-layout-narrow-stack.php
 * 退出码：0 全绿；1 有断言失败。
 *
 * @package tcpc-oa
 */

$src = dirname(__DIR__) . '/includes/doc/doc-layout-designer.php';
$fail = 0;
$pass = 0;

function zw_l2536_ok($cond, $label)
{
    global $fail, $pass;
    if ($cond) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    echo "  ✗ {$label}\n";
}

if (!is_readable($src)) {
    echo "✗ 找不到源文件：{$src}\n";
    exit(1);
}
$code = file_get_contents($src);

echo "== A. 行容器内联样式不得遮蔽媒体查询 ==\n";

// 取行容器开标签的 style 属性。
$inline = null;
if (preg_match('/class="tco-doc-layout-row"\s+style="([^"]*)"/', $code, $m)) {
    $inline = $m[1];
}
zw_l2536_ok($inline !== null, '找到 .tco-doc-layout-row 的行内 style');
if ($inline !== null) {
    zw_l2536_ok(stripos($inline, 'flex-wrap') === false, '内联 style 不含 flex-wrap（否则 @media 的 flex-wrap:wrap 被遮蔽）');
    zw_l2536_ok(!preg_match('/(^|;)\s*height\s*:/i', $inline), '内联 style 不含 height（否则 @media 的 height:auto 被遮蔽）');
    zw_l2536_ok(stripos($inline, 'display:flex') !== false, '内联 style 仍保留 display:flex（桌面两栏不回归）');
    zw_l2536_ok(stripos($inline, '--tco-doc-split') !== false, '内联 style 仍保留 --tco-doc-split（拖拽比例变量不回归）');
}

echo "== B. 桌面行高仍有样式表出处 ==\n";
zw_l2536_ok(
    (bool) preg_match('/body\.tco-doc-layout-screen\s+\.tco-doc-layout-row\s*\{\s*height\s*:\s*var\(--tco-doc-row-h/', $code),
    '存在 body.tco-doc-layout-screen .tco-doc-layout-row{height:var(--tco-doc-row-h,…)}'
);

echo "== C. @media (max-width:1099px) 堆叠分支完整 ==\n";
$media = null;
if (preg_match('/@media\s*\(max-width:1099px\)\s*\{(.*?)\n\s*\}/s', $code, $mm)) {
    $media = $mm[1];
}
zw_l2536_ok($media !== null, '找到 @media (max-width:1099px) 分支');
if ($media !== null) {
    zw_l2536_ok(
        (bool) preg_match('/\.tco-doc-layout-row\s*\{[^}]*flex-wrap\s*:\s*wrap/', $media),
        '分支内行容器 flex-wrap:wrap'
    );
    zw_l2536_ok(
        (bool) preg_match('/\.tco-doc-layout-row\s*\{[^}]*height\s*:\s*auto/', $media),
        '分支内行容器 height:auto'
    );
    zw_l2536_ok(
        (bool) preg_match('/\.tco-doc-layout-splitter\s*\{[^}]*display\s*:\s*none/', $media),
        '分支内隐藏分隔条（堆叠时无左右可拖）'
    );
    zw_l2536_ok(
        (bool) preg_match('/\.tco-doc-layout-preview\s*\{[^}]*flex\s*:\s*0\s+0\s+100%/', $media),
        '分支内预览列 flex:0 0 100%'
    );
    zw_l2536_ok(
        (bool) preg_match('/\.tco-doc-layout-preview\s*\{[^}]*max-width\s*:\s*100%/', $media),
        '分支内预览列 max-width:100%'
    );
}

echo "\n";
if ($fail > 0) {
    echo "✗ 失败 {$fail} 项，通过 {$pass} 项\n";
    exit(1);
}
echo "✓ 全部通过：{$pass} 项（窄屏堆叠分支可生效，桌面行高有出处）\n";
exit(0);
