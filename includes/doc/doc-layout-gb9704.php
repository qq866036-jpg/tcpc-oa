<?php
/**
 * 公文版式常量唯一出处（GB/T 9704-2012）—— TASK-765 / SPEC-87。
 *
 * 铁律：
 * - 本文件是公文版式参数的**唯一出处**；HTML / DOCX / PDF 三条渲染通道**禁止硬编码 mm 与 pt**，
 *   一律经 `zw_doc_gb9704_spec()` 及其派生函数取值。
 * - 任何数值改动必须先改 `docs/中传网公文版式实现规范-20261003.md`，再走 `specs/changes/CHG-*`，
 *   最后同步本文件；禁止只改代码不改规范。
 * - 数值整理自 GB/T 9704-2012 的第三方执行摘要，**实施验收阶段必须比对国标原文或本单位红头纸样**
 *   （见 `spec` 数组的 `pending_review` 标记）。
 * - 本文件不依赖 WordPress 运行态（纯数组 + 纯函数），便于单元测试直接 include。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_DOC_GB9704_VERSION')) {
    define('ZW_DOC_GB9704_VERSION', '1');
}

if (!function_exists('zw_doc_gb9704_spec')) {
    /**
     * 公文版式参数表（唯一出处）。
     *
     * @return array
     */
    function zw_doc_gb9704_spec() {
        static $spec = null;      // 国标原值（只构建一次）
        static $resolved = null;  // 应用「公文版式」覆盖层后的结果
        static $resolved_key = null;
        if (is_array($spec)) {
            // TASK-2000：覆盖层按**指纹**缓存 —— 设计器保存后无需重启即生效，
            // 且无覆盖时与国标原值逐字一致（`$resolved === $spec`）。
            $ov  = function_exists('zw_doc_layout_get_overrides') ? zw_doc_layout_get_overrides() : array();
            $key = md5(serialize($ov));
            if (is_array($resolved) && $resolved_key === $key) {
                return $resolved;
            }
            $resolved = function_exists('zw_doc_layout_apply_overrides')
                ? zw_doc_layout_apply_overrides($spec)
                : $spec;
            $resolved_key = $key;

            return $resolved;
        }

        // 正文位（h3/h4/body 共用）。
        // TASK-1863：协会授权的国标字体已由 TASK-1862 装入服务器（`/usr/share/fonts/truetype/zw-office/`），
        // 公文通道**不再托管 OFL 近似体**（原 `hosted` 指向霞鹜文楷 Lite）；`local()` 链仍以国标字体名优先，
        // 浏览器/渲染引擎在本机未装同名字体时由系统替换（属浏览器行为，主题不再提供近似体）。
        $body_slot = array(
            'gb'     => '仿宋_GB2312',
            'local'  => array('仿宋_GB2312', 'FangSong_GB2312', '仿宋', 'FangSong', 'Noto Serif CJK SC', 'Source Han Serif CN'),
            'hosted' => '',
            'weight' => 'normal',
            'approx' => false,
        );

        $spec = array(
            'spec'          => 'GB/T 9704-2012',
            'spec_title'    => '党政机关公文格式',
            'version'       => ZW_DOC_GB9704_VERSION,
            'pending_review' => true,
            'pending_note'  => '数值依据第三方执行摘要整理，未逐字比对国标原文；验收阶段必须以国标原文或本单位红头纸样复核',

            // —— 纸张与版心 ——
            'paper'  => array(
                'w_mm' => 210.0,
                'h_mm' => 297.0,
                'name' => 'A4',
            ),
            'margin' => array(
                'top_mm'    => 37.0,
                'bottom_mm' => 35.0,
                'left_mm'   => 28.0,
                'right_mm'  => 26.0,
            ),
            'grid'   => array(
                'lines_per_page' => 22,
                'chars_per_line' => 28,
            ),

            // —— 正文与标题 ——
            'text'   => array(
                'slot'         => 'body',
                'pt'           => 16.0,
                'line_pt'      => 28.0,
                'indent_chars' => 2,
                'color'        => '000000',
                'align'        => 'justify',
            ),
            'title'  => array(
                'slot'      => 'title',
                'pt'        => 22.0,
                'line_pt'   => 35.0,
                'align'     => 'center',
                // TASK-1340 阶段三（用户口径 2026-10-09）：「红线和公文标题 以及 公文标题和正文第一行 各留一行」
                // ⇒ 原 2 行（56pt ≈19.76mm）改 **1 行**；并新增「标题 → 正文第一行」1 行。
                // 单位统一为**正文行**（`text.line_pt` = 28pt ≈9.88mm），与 TASK-1331 的「空二行」同口径。
                'gap_below_rule_lines' => 1,
                // 仅当**无主送机关**时生效（有主送机关时：标题 →(1行)→ 主送机关 → 正文，间距由 receiver 承担，
                // 避免 DOCX 通道"加法叠加"变成 2 行；HTML 通道靠外边距合并天然取 max，不会叠加）。
                'gap_below_title_lines' => 1,
                // TASK-2010：标题折行口径 —— `wrap_chars` 每行最多字数（0 = 按版心宽/标题字宽自动），
                // `wrap_mode` 同落款（balanced 均衡 / fill 填满）。
                'wrap_chars'            => 0,
                'wrap_mode'             => 'balanced',
                'wrap_min_chars'        => 0,
            ),

            // —— 层次序数四级（一、（一）、1.、（1）） ——
            'heading' => array(
                'h1' => array('slot' => 'h1', 'pt' => 16.0, 'line_pt' => 28.0, 'indent_chars' => 2),
                'h2' => array('slot' => 'h2', 'pt' => 16.0, 'line_pt' => 28.0, 'indent_chars' => 2),
                'h3' => array('slot' => 'h3', 'pt' => 16.0, 'line_pt' => 28.0, 'indent_chars' => 2),
                'h4' => array('slot' => 'h4', 'pt' => 16.0, 'line_pt' => 28.0, 'indent_chars' => 2),
            ),

            // —— 主体其余要素 ——
            'main'   => array(
                'receiver_gap_lines'      => 1,   // 主送机关：标题下空一行
                'receiver_indent_chars'   => 0,   // 左顶格
                'receiver_trailing_colon' => '：', // 末尾全角冒号
                'attach_gap_lines'        => 1,   // 附件说明：正文下空一行
                'attach_indent_chars'     => 2,
                'note_indent_chars'       => 2,   // 附注：左空二字、加圆括号
            ),

            // —— 落款（署名 / 成文日期） ——
            'sign'   => array(
                'date_no_leading_zero'                 => true,
                'date_right_indent_chars_with_seal'    => 4,
                // TASK-2009（用户问「日期相对署名居中如何配置；不同长度署名怎么保证」）：
                // 落款横向**对齐口径** —— 决定「谁是中轴」：
                //   'center_on_date'（默认，国标 GB §7.3.5.1）：**成文日期**右空 N 字定位，**署名以日期为准居中**
                //        ⇒ 署名长度变化时只有署名左右伸展，日期位置不变（这才是国标要求的关系）；
                //   'center_on_org'：**署名**为基准（整块居中于版心），**日期以署名为准居中**
                //        ⇒ 署名长短会带着日期一起左右移动；
                //   'right_align'：两行**右端对齐**（长署名兜底观感，日期右空 N 字不变）。
                'org_align'                            => 'center_on_date',
                // TASK-2010（用户「标题 和 署名 折行如何配置」）：**折行口径**。
                //   wrap_mode = 'balanced' 均衡（梯形/菱形，各行等宽，GB 观感）/ 'fill' 逐行填满（末行短）
                //   wrap_min_chars = 每行**最少字数**（0 = 不限）；用于压缩行数、避免把词切碎
                'wrap_mode'                            => 'balanced',
                'wrap_min_chars'                       => 0,
                // 回行时每行**最少字数**（防止长署名被切得很碎；0 = 不限）。
                'wrap_min_chars'                       => 0,
                'date_right_indent_chars_without_seal' => 2, // TASK-1333：无印章（含 DOCX 通道）成文日期右空二字
                'org_right_indent_chars_without_seal'  => 2,
                'gap_lines_before_org'                 => 1,
            ),

            // —— 版头（红头） ——
            'header' => array(
                // TASK-2024（用户「份号 / 紧急程度 / 密级 / 保密期限 / 签发人 设计显示选项（胶囊开关）」）：
                // 版头各要素的**显示开关**（默认全开 = 有值即显示，与历史行为一致）。
                // 关掉后：即便该发文有值，版面也不输出该要素（用于特定文种/场景不标注）。
                'show_copy_no'                         => true,
                'show_secret'                          => true,
                // 保密期限是「密级★期限」中的期限部分：单独关闭 ⇒ 只显示密级（如「秘密」）。
                'show_secret_period'                   => true,
                'show_urgency'                         => true,
                'show_signer'                          => true,
                'letterhead_width_mm' => 156.0, // = 版心宽
                'letterhead_min_px'   => 1843,  // 156mm @ 300dpi
                'letterhead_max_bytes' => 3145728,
                // ⚠️ TASK-1422：以下两个键是**渲染模式名**（历史留痕），**不是**全局开关的取值出处。
                // 全局「红头生成方式」（auto 默认 / image）的唯一取值出处是 `zw_doc_letterhead_mode()`
                // （见 `inc/admin/doc/doc-letterhead.php`，option `zw_doc_letterhead_mode`）——
                // 开关只影响**取值语义**，不改本表的任何数值。
                'mode_image'          => 'image',    // 用户上传红头图片
                'mode_constant'       => 'constant', // 无素材时回退：文字机关标志 + 红色反线
                'rule_gap_mm'         => 4.0,   // 发文字号下 4mm 为红色分隔线
                'rule_h_mm'           => 0.35,  // **单线**口径（rule_style=single 时使用；保留供回退与既有断言）
                // 版头红色分隔线形态（TASK-1271 用户拍板）：**所有公文都用双线**（V2 上粗下细），两线间距 1.0mm。
                // 说明：GB/T 9704-2012 的常式版头为**一条**红色反线；双线属业务方版式决定（信函格式观感），
                // 线宽/间距为待复核项（见本表 pending_review），必须在 SPEC-87 §5 显式登记为偏离。
                'rule_style'          => 'double', // double（默认，用户拍板）| single（回退）
                // —— 红头呈现参数（TASK-1272「路线 1」；TASK-1278 按参考图标定并做单行适配）——
                // 名义字体**不变**（fonts.title.gb = 方正小标宋简体，商业字体不入库）；以「字号 + 加粗 + 字距 +
                // HTML/PDF 描边」近似「大标宋」观感。**偏离国标登记**：GB/T 9704 的发文机关标志为 2 号（22pt）；
                // 本处按**用户上传的参考图实测**标定（见下），属业务方版式决定，必须在 SPEC-87 §4/§5 显式登记。
                //
                // TASK-1278 参考图标定（**实测自用户上传的红头图**，图片只读、勿改）：
                //   参考图 /www/wwwroot/tcpc.org.cn/wp-content/uploads/2026/10/红头-中国传统文化促进会_原文双线.png
                //   尺寸 2122×307 px；文字为 **9 字**「中国传统文化促进会」（**不含**「文件」）；
                //   红色列段恰 **9 段**（列 238–1931）；文字带高 207 px、水平跨度 1693 px、单字步进 188.2 px
                //   ⇒ **步进 / 字高 = 0.908**；图片按版心宽 156mm 显示 ⇒ 折算**字面高 ≈ 15.2mm ≈ 43pt**、
                //   单字宽 ≈ 13.8mm。（双线各 ≈0.29mm、中心间距 ≈1.4mm —— 本次**不动**双线，
                //   仍按 CHG-152 的 0.50 / 0.25 / 1.00mm 口径。）
                // 单行适配的唯一实现见本文件 `zw_doc_head_fit()`：长机关名（分支机构「…工作委员会」）靠
                // **等比缩小字号与字距**适配版心宽，**绝不折行**（HTML 侧另有 `white-space:nowrap` 硬保证）。
                //
                // ⚠️ TASK-1280（CHG-157）缺陷根因：CHG-156 版把 `org_advance_ratio = 0.908`（**参考图里真实
                // 小标宋**的窄长体比例：单字步进 ÷ 字面高）当成了**宽度预算系数**。当时 HTML/加密 PDF 通道实际
                // 用的是**回退字体**（本机 `Source Han Sans CN` 等），每字宽 ≈ **1.0em**（≈1.16 × 字面高），
                // 比参考图模型宽约 **48%** ⇒ 22 字模型算 147mm、实机约 189mm，**冲出 156mm 版心**（用户截图）。
                // 因此**宽度预算必须按 1.0em/字**（`org_advance_em`），HTML 与 DOCX 共用同一保守模型。
                // （TASK-1863：服务器已装真小标宋，实测单字步进 ≈0.908em，按 1.0em 预算只会更宽松，
                //   不会因换真字体而溢出；比例仍**只作标定用**，不进宽度预算。）
                'org_pt_max'          => 43.0,     // 红头字号**上限**（pt；参考图标定 ≈43pt，原 org_pt=26pt 已按参考图拉高）
                'org_pt'              => 43.0,     // **兼容别名**（= org_pt_max；仅供既有引用/历史断言继续取值，新实现一律用 org_pt_max）
                'org_target_mm'       => 15.2,     // 目标**字面高**（mm；实测自用户上传的红头图：207px，按 156mm 版心宽折算）
                // `org_advance_ratio` **只用于参考图「目标字面高」的换算/标定说明**（0.908 是参考图小标宋实测：
                // 188.2px ÷ 207px）。**禁止**再拿它做宽度预算 —— 若再用它算宽即**重犯 TASK-1280 的溢出缺陷**
                // （历史回退字体每字 ≈1.0em，模型会低估约 48%；现通道已用真小标宋，预算仍按 1.0em 保守取）。
                'org_advance_ratio'   => 0.908,    // 【仅标定用·禁止进入宽度预算】参考图单字**步进 / 字高**（188.2px ÷ 207px）
                'org_advance_em'      => 1.0,      // 宽度预算：**每字宽 = 1.0 × 字号（em）**（保守；HTML 与 DOCX 共用）
                'org_safe_mm'         => 2.0,      // 宽度预算左右**各留**的安全余量（mm）⇒ 预算 = 156 − 2×2 = 152mm
                // —— 红头**垂直拉长**（TASK-1282 / CHG-158；用户要求「增加红头文字高度／拉长增加 20%」）——
                // ⚠️ **绝不进入宽度预算**：`zw_doc_head_fit()` 的 pt 仍**完全由宽度决定**
                // （预算 152mm、`org_advance_em = 1.0`、`org_safe_mm = 2.0`），**不是**「字号 × 1.2」。
                // **失败模式警示（禁止复发）**：把 1.2 乘进字号即**重犯 TASK-1280 的红头溢出缺陷**
                // （用户已截图报过一次）—— 9 字 43pt 实测已 142.87mm，×1.2 = 51.6pt ⇒ **163.8mm > 156mm 版心**。
                // 生效方式（只改高度、宽度不变）：
                //  · HTML / 加密 PDF（走 HTML 栅格化）：`.zw-doc-org` 加 `transform: scaleY(1.2)`；
                //  · DOCX：字号 = 宽度口径字号 × 1.2 + OOXML **字符宽度缩放** `<w:w w:val="83"/>`（100/1.2≈83）。
                // 两侧共用口径见 `zw_doc_head_stretch()`。
                'org_height_scale'    => 1.2,      // 垂直拉长系数（仅高度；与宽度预算**解耦**）；`org_height_mode=fixed` 时生效
                // —— 红头**全等高**（TASK-1287 / CHG-159；用户拍板「方案 C：高度都按照『中国传统文化促进会文件』的高度自动拉长」）——
                // 目标：**所有**红头文字（9 / 11 / 26 字……）的**字面高**都等于 11 字「中国传统文化促进会文件」的当前
                // 渲染字面高 ⇒ 逐用例反解纵向拉长系数 `scale_y`（`zw_doc_head_stretch()`）。
                // ⚠️ **红线（务必与 CHG-159 §五 同读）**：`scale_y` **绝不进入宽度预算** —— `zw_doc_head_fit()`
                // 的 `pt` 仍**完全由宽度**决定（预算 152mm、`org_advance_em = 1.0`、`org_safe_mm = 2.0`）。
                // 若把拉长系数乘进字号，26 字将由 15.86pt 变 45.16pt ⇒ **约 615mm ≫ 156mm 版心**，
                // 即**重犯 TASK-1280 的红头溢出缺陷**（用户已截图报过一次）——**禁止复发**。
                // ⚠️ **失真为业务方决定、已获用户确认**：26 字需 **≈2.85×** 纵向拉伸（30 字 ≈3.29×、40 字 ≈4.38×），
                // 观感**明显失真**；主控已在对比图与答复中明确告知「26 字需 ≈2.85× 纵向拉伸、明显失真」，
                // 用户仍选择方案 C ⇒ **实现之**，并在 SPEC-87 §5.2 与 CHG-159 登记留痕（**非实现缺陷**）。
                // **外推风险（待用户复核）**：机关名越长拉伸越极端（30 / 40 字见 CHG-159 §六），
                // 若后续需设帽，用 `org_height_scale_max`（当前 0 = 不设上限），**不改变本模式算法**。
                'org_ink_target_mm'   => 13.7,     // 全等高**目标字面高**（mm）= 11 字「中国传统文化促进会文件」当前渲染字面高
                                                   // （宽度口径 37.5806pt × org_ink_ratio 0.86 × 1.2 行高补偿前基准 = 13.7mm）
                'org_pad_safe_mm'     => 0.3,      // 行高补偿的**安全余量**（mm；仅当自然行盒**装不下**拉长后的字面时才补，见 `zw_doc_head_org_padding_pt()`）
                'org_height_mode'     => 'equal',  // 'equal' = **全等高（方案 C，默认）**；'fixed' = 旧行为（统一 ×org_height_scale，保留回退）
                'org_height_scale_max' => 0.0,     // `scale_y` **上限**（0 = **不设上限**；后续如需给超长机关名加帽在此赋值，**待复核**）
                'org_ink_ratio'       => 0.86,     // 字面高 / 字号 em（**待复核**：无字体度量依据，仅用于 mm ⇄ pt 折算）
                'org_pt_min'          => 12.0,     // 红头字号**可读性下限**（pt；长名适配到下限即止，不再缩小）
                'org_letter_spacing_pt' => 2.0,    // 字距**基准**（pt；上限于 org_pt_max 下的取值；适配时等比缩小，允许 0、不允许负）
                // TASK-1985（用户指令「在线预览 PDF 文件发现 红头加粗了 需要取消加粗」）：
                // 原 0.3px 描边是 TASK-1272「无粗体字重时以描边近似加粗」的权宜手段；自 TASK-1863 起
                // HTML/PDF 通道已装**真小标宋**（`fonts` 默认档 = 服务器已装授权国标字体），
                // 「字重不足」的前提已不存在 ⇒ 描边归 0（与 `font-weight:normal` 一起取消红头的合成加粗）。
                'org_stroke_px'       => 0.0,      // HTML/PDF 描边宽度（-webkit-text-stroke；0 = 不描边）
                'rule_top_h_mm'       => 0.50,     // 双线**上粗**
                'rule2_h_mm'          => 0.25,     // 双线**下细**
                'rule_gap2_mm'        => 1.0,      // 双线两线间距
                'rule_color'          => 'D80505',
                'org_color'           => 'D80505',
                'rule_width'          => 'text_width',
                // 发文字号排布（TASK-1274 用户指令「按国标调整文号位置」）：**回归 GB/T 9704-2012** ——
                // 发文机关标志**下空二行**排发文字号、**居中**；红色反线在文号**之下 4mm**（rule_gap_mm）；
                // 旧口径（TASK-776「红头/反线下一行、居右」，曾明确偏离国标）保留为**可切换回退**：
                // number_position=below_rule + number_align=right。
                // ⚠️ TASK-1274 修复：此前 number_position 是**死常量**（无任何渲染/导出判断它），
                // CHG-099 声称的「一行配置切回国标」并未落地；现两个分支在 HTML 与 DOCX 中**均被真实判断**。
                'number_position'     => 'above_rule',
                'number_align'        => 'center',
                'org_gap_lines'       => 2,      // 发文机关标志与红色反线之间的空行数（文号已移至反线下方）
                'copy_no_digits'      => 6,     // 份号：6 位 3 号阿拉伯数字

                // —— 版头标记三要素（TASK-1276 / CHG-155，GB/T 9704-2012 §7.2.1~7.2.3）——
                // 顺序固定：**份号**（§7.2.1，6 位 3 号阿拉伯数字，顶格版心左上角第一行）
                //        → **密级和保密期限**（§7.2.2，3 号黑体，顶格第二行，形如「秘密★1年」）
                //        → **紧急程度**（§7.2.3，3 号黑体，顶格第三行：特急 / 加急）。
                // 三者整体位于**发文机关标志之上**；三者皆空时整块不输出（HTML / DOCX 两侧同源）。
                // 字号 3 号 = 16pt，字体槽取 `fonts.h1`（黑体，已在本表 fonts 块定义）；标记行高取正文行距 28pt。
                // 复用的常量：`copy_no_digits = 6`（份号位数）、`fonts.h1`（黑体槽）。
                'mark_pt'          => 16.0,     // 3 号（版头标记字号）
                'mark_slot'        => 'h1',     // 黑体（fonts.h1）
                'mark_line_pt'     => 28.0,     // 标记行高（与正文行距同值）
                'mark_gap_lines'   => 1,        // 标记块与发文机关标志之间的空行数（★ **待复核**：具体行位以国标原文 / 本单位红头纸样为准）
                'secret_separator' => '★',      // 密级与保密期限的分隔符（无期限时只写密级）
                'secret_levels'    => array('绝密', '机密', '秘密'), // 密级字典唯一出处（录入控件与写入校验共用）
                'urgency_levels'   => array('特急', '加急'),          // 紧急程度字典唯一出处（公文取值）
            ),

            // —— 版记（TASK-1338 批次二：线型条数 / 末尾句号 / 左右空一字 / 锚定版心底） ——
            'record' => array(
                'slot'                 => 'body',
                'pt'                   => 14.0, // 4 号
                'side_indent_chars'    => 1,    // 左右各空一字（印发机关左、印发日期右，兼作抄送左右空）
                'trailing_punct'       => '。',
                // 版记分隔线**三条**：首条粗、中间细、末条粗（GB/T 9704-2012 §7.4.1）。
                // 条数与线型是**两通道共同**的硬口径（HTML 与 DOCX 各按本键取值，禁止任一通道自定）。
                'separator_thick_mm'   => 0.35,
                'separator_thin_mm'    => 0.25,
                // TASK-2024（用户「调整 抄送 和印发 是否在 横线内 如何调整？」）：
                //   'enclose'（默认，GB §7.4）：首尾粗线把**抄送与印发**都括在里面；
                //   'print_only'：**抄送行在横线之上**（不括入），只把印发行括进横线；
                //   'none'：不画版记横线。
                'rules_scope'          => 'enclose',
                // 版记锚定最后一面版心**下边缘**（§7.4.1 末条与版心下边缘重合）。
                // HTML 通道以撑底实现；DOCX 通道为最小可行实现（登记于 SPEC-87 §8.3 与批次二报告）。
                'anchor_text_bottom'   => true,
            ),

            // —— 页码 ——
            'footer' => array(
                'slot'   => 'page',
                'pt'     => 14.0, // 4 号
                'baseline_below_text_mm' => 7.0,
                'modes'  => array('standard', 'center', 'none'),
                'default_mode' => 'standard',
                'odd'    => 'right',
                'even'   => 'left',
                'dash'   => '—',  // 一字线（左右各一条）
                // TASK-1338 批次二（GB/T 9704-2012 §7.5）：单页码居右**空一字**、双页码居左**空一字**。
                // 「一字」= 页码自身 4 号字宽（footer.pt）；两通道取同一键（HTML 用相对字号单位、DOCX 用字符缩进）。
                'side_indent_chars' => 1,
            ),

            // —— 印章 ——
            'seal'   => array(
                'over'               => 'sign_org_and_date',
                'color'              => 'red',
                // TASK-1985（用户口径「真实印章是 4cm 直径」）：**章径的唯一事实来源 = 真实印章实测直径**。
                // 每个印章的真实直径登记在登记表 `zw_doc_seals[<id>].diameter_mm`，经唯一取值入口
                // `zw_doc_seal_diameter_mm()` 读出并落到版面（CSS `width:Nmm`，与版心同一 mm 坐标系 ⇒ 1:1 物理尺寸）。
                // 本常量（40mm）是「未逐章登记」时的回退值 —— 取用户 2026-10-24 给出的实物口径
                // （原值 42mm 从未验证过，§14 第 3 项本就写着「以本单位实际印章为准」，本单据此收口）。
                'diameter_mm'        => 40.0, // 回退值（逐章登记优先）
                // 法规边界：国发〔1999〕25号《国务院关于国家行政机关和企业事业单位社会团体印章管理的规定》
                // 第十四条「企业事业单位、社会团体的印章，直径**不得大于 4.5 厘米**」⇒ 登记值夹取 [30, 45]mm。
                'diameter_min_mm'    => 30.0,
                'diameter_max_mm'    => 45.0,
                // GB/T 9704-2012 §7.3.5.1「使发文机关署名和成文日期居**印章中心偏下**位置」的量化口径：
                // 落款块（署名 + 成文日期两行）**块心落在章心下方**该毫米数（正面值 = 块心比章心低）。
                'center_offset_mm'   => 4.0,
                // 印章摆放口径。
                //   'over'  = **默认**：国标口径（GB/T 9704-2012 §7.3.5.1「居中下压发文机关署名和成文日期，
                //             使署名与成文日期居印章中心偏下」）。落款区高度 ≈ 两行，1 页口径。
                //   'below' = 备选：章整体落在落款两行**之下**（章顶贴成文日期底边 + `gap_below_sign_mm`）
                //             ⇒ 必须在落款之后预留章高空间（否则章压版记），落款区 ≈ 两行 + 章径（40mm 章会多占 40mm）。
                // TASK-1994（用户 2026-10-24 更正原文「印章 调整方向错了，不是文字的下方 是文字的**下一层**」）：
                // 用户要的是**层叠顺序**（章在文字之下、文字盖在章上），**不是**把章垂直搬到文字下方 ⇒
                // 默认恢复 'over'（TASK-1993 曾把默认改成 'below'，导致 52 号溢出成 2 页）；层序另由
                // `seal.text_above` 控制（见渲染层：`.zw-doc-seal` z-index 1、署名/日期 z-index 2）。
                'placement'          => 'over',
                // TASK-1994：**文字在章之上**（用户口径「印章置于落款单位和日期的下一层」）——真章压在字上，
                // 但本会版式要求**落款单位与成文日期清晰可读**，故把章放到文字**下面一层**。
                'text_above'         => true,
                // 'below' 模式下章顶与成文日期底边的间距（mm；0 = 紧贴）。
                'gap_below_sign_mm'  => 0.0,
                // 印章顶端上距正文（或附件说明）的**安全余量**：GB 要求「一行之内」；
                // 仅在 `reserve_body_gap = true` 时用于**自动多留空行**（见 `zw_doc_gb9704_seal_geometry()`）。
                'body_clear_mm'      => 1.0,
                // TASK-1985 取舍（**已实测登记**，勿随手改）：
                // 40mm 章 + 两行落款时，「印章顶端上距正文一行之内」要求落款前留 ≥ `above_block_mm`（≈14.1mm ≈ 1.43 行）
                // ⇒ 必须多留 1 行；但当正文恰好排满一页时，多留这 1 行会把**版记**顶出原位、导致章压版记
                // （真实公文版记是页面底部要素，被章压住是硬缺陷）。
                // 二者不可能同时满足的区间真实存在（TASK-1985 实测：本区间为空），GB/T 9704-2012 §7.3.5.5 给的
                // 出路是「适当调整行距、字距」—— 服务端无法预量版面，不能自动做。
                // 因此**默认不自动加行**（`false`）：保证「章不压版记」「分页零回归」；
                // 章顶压到正文末行**行盒**的偏差由 `body_gap_mm`（负值）如实给出，供复核/后续做行距压缩时收口。
                'reserve_body_gap'   => false,
                // TASK-1340（用户报告"上传印章不成功"）：原 600px / 2MB 过严 —— 实测多数印章 PNG 会被这一步拒掉
                // （页面虽已写明限制，但极易漏看；且 600px 短边相当于 42mm 印章 ≈363dpi，远超需求）。
                // 现放宽为 400px / 8MB。换算：400px ÷ (42mm/25.4) ≈ **242dpi**，
                // 即**低于** `low_dpi_threshold`(300dpi) ⇒ 这类素材**会显示"低清素材"警告**，
                // 但按原设计**只警告、不拦上传**（拦与不拦的边界由 `min_source_px` 单独决定）。
                'min_source_px'      => 400,
                'low_dpi_threshold'  => 300,
                'allowed_ext'        => array('png'),
                'max_bytes'          => 8388608,
            ),

            // —— 加密 PDF（P1 整页栅格化） ——
            'pdf'    => array(
                'pipeline'           => array('html', 'chromium_print', 'rasterize', 'image_pdf', 'qpdf_encrypt'),
                'rasterize_tool'     => 'pdftoppm',
                'dpi_options'        => array(150, 200, 300),
                'dpi_default'        => 200,
                'max_pages'          => 20,
                'max_bytes'          => 31457280, // 30MB
                'encrypt_key_bits'   => 256,
                'user_password'      => '',       // 打开口令留空（收件人可直接打开）
                'owner_pass_constant' => 'ZW_DOC_PDF_OWNER_PASS',
                'permissions'        => array(
                    'print'    => 'full',
                    'modify'   => 'none',
                    'extract'  => 'n',
                    // 真机实测（TASK-777，qpdf 7.1.1）：256 位加密**只接受**
                    // `--print` / `--modify` / `--extract` / `--accessibility` 四个权限开关。
                    // - `--annotate` **只对 40 位加密有效**，128/256 位下会报
                    //   "invalid for 128-bit keys" 并拒绝执行；
                    // - `--form` / `--assemble` 在本版**不存在**；
                    // - 注释与表单权限由 `--modify=none` 统一涵盖（实测 modify forms/annotations 均为 not allowed）。
                    'accessibility' => 'y',
                ),
            ),

            // —— 字体档位与版式位映射（TASK-1863：默认档 = 服务器已装授权国标字体） ——
            // 背景：TASK-1862 已把协会授权的国标字体装入服务器；此前"档 A = OFL 近似体"的默认档
            // 及其 `hosted`（霞鹜文楷 Lite）已从公文通道撤下 —— 新建发文的 HTML/PDF 字形一律为国标字体。
            'font_tiers' => array(
                'A' => '服务器已装协会授权国标字体（默认；HTML/PDF 通道字形 100% 国标）',
                'B' => '浏览器本地字体（用户机已装同名字体时由 local() 命中，不经服务器）',
                'C' => '浏览器打印（用户机本地字体，打印效果取决于本机字体）',
            ),
            'fonts' => array(
                'title' => array(
                    'gb'     => '方正小标宋简体',
                    'local'  => array('方正小标宋简体', 'FZXiaoBiaoSong-B05S', '小标宋', 'Noto Serif CJK SC'),
                    'hosted' => '',
                    'weight' => 'bold',
                    'approx' => false,
                ),
                'h1' => array(
                    'gb'     => '黑体',
                    'local'  => array('黑体', 'SimHei', 'Heiti SC', 'Noto Sans CJK SC', 'Source Han Sans CN', '思源黑体 CN'),
                    'hosted' => '',
                    'weight' => 'bold',
                    'approx' => false,
                ),
                'h2' => array(
                    'gb'     => '楷体_GB2312',
                    'local'  => array('楷体_GB2312', 'KaiTi_GB2312', '楷体', 'KaiTi', 'LXGW WenKai Lite'),
                    'hosted' => '',
                    'weight' => 'normal',
                    'approx' => false,
                ),
                'h3'     => $body_slot,
                'h4'     => $body_slot,
                'body'   => $body_slot,
                'page'   => array(
                    'gb'     => '宋体',
                    'local'  => array('宋体', 'SimSun', 'Noto Serif CJK SC'),
                    'hosted' => '',
                    'weight' => 'normal',
                    'approx' => false,
                ),
            ),
            // 托管字体白名单：**当前公文通道不再托管任何字体**（`hosted` 已全部清空）。
            // 保留白名单是为了守住"不得托管商业字体"这条线：将来若要恢复兜底，只允许此处登记过的 OFL 字体。
            'font_hosted_allowlist' => array(
                '/static/fonts/LXGWWenKaiLite-Regular.ttf' => array(
                    'name'    => '霞鹜文楷 Lite',
                    'license' => 'SIL OFL 1.1',
                    'spec'    => 'SPEC-20 已登记（当前公文通道未使用）',
                ),
            ),
            'font_forbidden_note' => '禁止把任何商业中文字体放进仓库或发布包（方正小标宋简体 / 仿宋_GB2312 / 楷体_GB2312 / 宋体 / 黑体 / 微软雅黑 / 等线 等）；授权字体由协会自行安装到服务器自用（TASK-1862：/usr/share/fonts/truetype/zw-office/），仓库只引用字体**名**、不分发字体**文件**',

            // —— 统一声明文案与禁用表述 ——
            'disclaimer' => array(
                'font'    => '字形为协会已授权的国标字体（仿宋_GB2312 / 方正小标宋简体 / 黑体 / 楷体_GB2312），由服务器渲染；浏览器预览时若本机未装同名字体，将由本机系统字体替换',
                'encrypt' => '本文件为机构版式用印件（AES-256 加密 · 禁止编辑与提取）；文字不可选中、不可检索；本文件不是《电子签名法》意义上的可靠电子签名。',
                'draft'   => '草稿件，未生效',
                'seal_low_dpi' => '低清素材',
                'forbidden_phrases' => array('无法复制', '绝对安全', '印章不可下载', '不可篡改'),
            ),
        );

        // TASK-2000：首次构建只存**国标原值**；覆盖层在函数入口按指纹应用（见上）。
        // 之后所有通道（HTML / DOCX / 加密 PDF）都经 `zw_doc_gb9704_get()` 读到同一份值。
        $ov  = function_exists('zw_doc_layout_get_overrides') ? zw_doc_layout_get_overrides() : array();
        $resolved_key = md5(serialize($ov));
        $resolved = function_exists('zw_doc_layout_apply_overrides')
            ? zw_doc_layout_apply_overrides($spec)
            : $spec;

        return $resolved;
    }
}

if (!function_exists('zw_doc_gb9704_get')) {
    /**
     * 按点分路径读取版式参数，缺失时返回默认值。
     *
     * @param string $path    形如 paper.w_mm。
     * @param mixed  $default 缺失默认值。
     * @return mixed
     */
    function zw_doc_gb9704_get($path, $default = null) {
        $node = zw_doc_gb9704_spec();
        foreach (explode('.', (string) $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }
}

if (!function_exists('zw_doc_gb9704_gap_lines')) {
    /**
     * 「空 N 行」的空行数取值（TASK-1333 批次一）—— 主送机关 / 附件说明 / 落款三处间距的**唯一口径**。
     *
     * 参数键形如 `main.receiver_gap_lines`、`main.attach_gap_lines`、`sign.gap_lines_before_org`；
     * 非法值（负数、非数字）一律按 0 处理，避免把版式顶出边界。HTML 与 DOCX 两条通道
     * **都经本函数取值**，禁止任一条通道另写基准。
     *
     * @param string $path    点分路径。
     * @param int    $default 缺省空行数。
     * @return int 空行数（≥ 0）。
     */
    function zw_doc_gb9704_gap_lines($path, $default = 1) {
        $lines = (int) zw_doc_gb9704_get($path, $default);
        return $lines > 0 ? $lines : 0;
    }
}

if (!function_exists('zw_doc_gb9704_gap_pt')) {
    /**
     * 「空 N 行」折算为磅值（TASK-1333 批次一）= 空行数 × **正文行高**。
     *
     * 「行」在本规范中一律指正文行（3 号字行），不是标题行高 —— TASK-1331 的标题上间距即因此修正。
     * 本函数是 HTML 通道写「上边距」等间距值的唯一来源，与 DOCX 通道所消费的空行数**同源同参数**。
     *
     * @param string $path    点分路径（同上）。
     * @param int    $default 缺省空行数。
     * @return float 磅值（≥ 0）。
     */
    function zw_doc_gb9704_gap_pt($path, $default = 1) {
        $line_pt = (float) zw_doc_gb9704_get('text.line_pt', 28.0);
        return zw_doc_gb9704_gap_lines($path, $default) * $line_pt;
    }
}

if (!function_exists('zw_doc_gb9704_sign_indents')) {
    /**
     * 落款右空字距口径（TASK-1333 批次一）—— 发文机关署名与成文日期**共用同一对参数**。
     *
     * 返回值为「字」数，1 字宽 = 3 号字宽：HTML 通道以相对字号单位表达，DOCX 通道以字符缩进表达，
     * 两条通道**都必须**经本函数取同一对参数，禁止任一条通道自取常量或另设口径。
     * 有印章时成文日期右空随印章加大（取有章口径），无印章时取无章口径。
     *
     * @param bool $sealed 是否加盖印章。
     * @return array{org_chars:int,date_chars:int} 署名右空字数、成文日期右空字数。
     */
    function zw_doc_gb9704_sign_indents($sealed = false) {
        $org = (int) zw_doc_gb9704_get('sign.org_right_indent_chars_without_seal', 2);
        if ($sealed) {
            $date = (int) zw_doc_gb9704_get('sign.date_right_indent_chars_with_seal', 4);
        } else {
            $date = (int) zw_doc_gb9704_get('sign.date_right_indent_chars_without_seal', $org > 0 ? $org : 2);
        }

        return array(
            'org_chars'  => $org > 0 ? $org : 0,
            'date_chars' => $date > 0 ? $date : 0,
        );
    }
}

if (!function_exists('zw_doc_gb9704_record_rules')) {
    /**
     * 版记分隔线口径（TASK-1338 批次二）—— 线型与条数的**唯一取值入口**。
     *
     * 返回首条 / 中间 / 末条三条线的毫米线宽，两通道**都**经本函数取值：HTML 以边框宽度表达、
     * DOCX 以前段落下边框表达。历史上只有两条且末条误用细线（本单修正）。
     *
     * @return array{thick_mm:float,thin_mm:float}
     */
    function zw_doc_gb9704_record_rules() {
        $thick = (float) zw_doc_gb9704_get('record.separator_thick_mm', 0.35);
        $thin  = (float) zw_doc_gb9704_get('record.separator_thin_mm', 0.25);
        if ($thick <= 0) {
            $thick = 0.35;
        }
        if ($thin <= 0) {
            $thin = 0.25;
        }

        return array('thick_mm' => $thick, 'thin_mm' => $thin);
    }
}

if (!function_exists('zw_doc_gb9704_record_rule_plan')) {
    /**
     * 版记分隔线**条数与线型序列**的唯一实现（TASK-1338 批次二轮 4，GB/T 9704-2012 §7.4.1）。
     *
     * 国标口径：「首条分隔线和末条分隔线用粗线，中间的用细线」——「中间的」指**版记要素行之间**的分隔线。
     * 因此分隔线条数 = 版记要素行数 + 1：
     *   * 抄送与印发机关和印发日期**两行都在** ⇒ 三条（粗 / 细 / 粗）；
     *   * 只有一行要素（无抄送，或只有抄送）⇒ 两条（粗 / 粗），**没有中间细线**；
     *   * 版记全空 ⇒ 不输出任何分隔线。
     *
     * 旧实现（批次二第一阶段）在印发行前**无条件**加中间细线，使「无抄送」的公文在首条粗线正下方
     * 紧贴一条上下无内容的细线（视觉上像加粗了一倍）。本条把「条数」从两通道各自的判断
     * 提升为**同一函数**，HTML 与 DOCX 两条通道都必须经本函数取序列，禁止任一通道自行决定条数。
     *
     * @param bool $has_cc    版记是否有抄送要素行。
     * @param bool $has_print 版记是否有印发机关和印发日期要素行。
     * @return string[] 自上而下的线型序列（'thick' 或 'thin'）；版记全空时返回空数组。
     */
    function zw_doc_gb9704_record_rule_plan($has_cc, $has_print) {
        $has_cc    = (bool) $has_cc;
        $has_print = (bool) $has_print;
        if (!$has_cc && !$has_print) {
            return array();
        }
        if ($has_cc && $has_print) {
            return array('thick', 'thin', 'thick');
        }

        return array('thick', 'thick');
    }
}

if (!function_exists('zw_doc_gb9704_record_cc_text')) {
    /**
     * 版记抄送文本（TASK-1338 批次二，GB/T 9704-2012 §7.4.2）—— 末尾标句号的**唯一实现**。
     *
     * 空抄送返回空串（不补句号）；已以 `record.trailing_punct` 结尾时**不重复补**。
     * HTML 与 DOCX 两条通道都必须经本函数取文本，禁止任一通道自行拼接标点。
     *
     * @param mixed $cc 抄送原值。
     * @return string
     */
    function zw_doc_gb9704_record_cc_text($cc) {
        $cc = trim((string) $cc);
        if ($cc === '') {
            return '';
        }
        $punct = (string) zw_doc_gb9704_get('record.trailing_punct', '。');
        if ($punct === '') {
            return $cc;
        }
        if (substr($cc, -strlen($punct)) === $punct) {
            return $cc;
        }

        return $cc . $punct;
    }
}

if (!function_exists('zw_doc_gb9704_record_side_chars')) {
    /**
     * 版记「左空一字 / 右空一字」的字数（TASK-1338 批次二，GB/T 9704-2012 §7.4.3）。
     *
     * HTML 以相对字号单位表达、DOCX 以字符缩进表达，**同一参数键**；
     * 「字」按版记自身 4 号字宽折算（版记字号见 `record.pt`）。
     *
     * @return int 非负字数。
     */
    function zw_doc_gb9704_record_side_chars() {
        $chars = (int) zw_doc_gb9704_get('record.side_indent_chars', 1);

        return $chars > 0 ? $chars : 0;
    }
}

if (!function_exists('zw_doc_gb9704_footer_indent_chars')) {
    /**
     * 页码「空一字」的字数（TASK-1338 批次二，GB/T 9704-2012 §7.5）。
     *
     * 单页码居右空一字、双页码居左空一字；两通道取同一参数键，HTML 以相对字号单位表达、
     * DOCX 以字符缩进表达。数值为 0 时两通道均不缩进（可秒级回退）。
     *
     * @return int 非负字数。
     */
    function zw_doc_gb9704_footer_indent_chars() {
        $chars = (int) zw_doc_gb9704_get('footer.side_indent_chars', 1);

        return $chars > 0 ? $chars : 0;
    }
}

if (!function_exists('zw_doc_gb9704_mm_to_twips')) {
    /**
     * 毫米 → OOXML twips（1in = 1440 twips = 25.4mm），四舍五入取整。
     *
     * @param float $mm 毫米。
     * @return int
     */
    function zw_doc_gb9704_mm_to_twips($mm) {
        return (int) round(((float) $mm) / 25.4 * 1440);
    }
}

if (!function_exists('zw_doc_gb9704_mm_to_pt')) {
    /**
     * 毫米 → 磅（1in = 72pt = 25.4mm）。版头双线间距等「mm 常量 → Word 段间距」换算用（TASK-1271）。
     *
     * @param float $mm 毫米。
     * @return float 磅。
     */
    function zw_doc_gb9704_mm_to_pt($mm) {
        return (float) $mm / 25.4 * 72;
    }
}

if (!function_exists('zw_doc_gb9704_pt_to_half_points')) {
    /**
     * 磅 → OOXML half-points（w:sz 单位）。
     *
     * @param float $pt 磅。
     * @return int
     */
    function zw_doc_gb9704_pt_to_half_points($pt) {
        return (int) round(((float) $pt) * 2);
    }
}

if (!function_exists('zw_doc_gb9704_pt_to_twips')) {
    /**
     * 磅 → twips（w:spacing w:line 单位）。
     *
     * @param float $pt 磅。
     * @return int
     */
    function zw_doc_gb9704_pt_to_twips($pt) {
        return (int) round(((float) $pt) * 20);
    }
}

if (!function_exists('zw_doc_head_fit')) {
    /**
     * 红头（发文机关标志）**单行适配**：给定红头文字，返回该文字应使用的字号与字距。
     *
     * TASK-1278（CHG-156）唯一实现、TASK-1280（CHG-157）**重写宽度预算**：HTML/PDF 与 DOCX 两侧
     * **共同调用本函数**，禁止各写一套。
     *
     * TASK-1282（CHG-158）**语义不变**：本函数**只算宽度口径**（字号/字距完全由宽度决定，返回 `pt`）。
     * 用户要求的「红头文字高度 +20%」由 `zw_doc_head_stretch()` 以**垂直拉长（宽度不变）**实现；
     * **`header.org_height_scale` 绝不允许乘进本函数** —— 9 字 43pt 实测已 142.87mm，
     * ×1.2 = 51.6pt ⇒ **163.8mm > 156mm 版心**，即**重犯 TASK-1280 的红头溢出缺陷**（用户已截图报过）。
     *
     * 标定来源（**实测自用户上传的红头图**，见 `header` 块注释）：
     *  - `org_target_mm = 15.2`：目标**字面高**（参考图 207px @ 156mm 版心宽）；
     *  - `org_advance_ratio = 0.908`：参考图小标宋单字**步进 / 字高**（188.2px ÷ 207px）——
     *    **只用于标定说明，禁止进入宽度预算**（重犯 TASK-1280 溢出缺陷）；
     *  - `org_advance_em = 1.0`：**宽度预算**用的每字宽（= 1.0 × 字号，保守；真小标宋实测 ≈0.908em）；
     *  - `org_safe_mm = 2.0`：宽度预算左右**各留**的安全余量（mm）；
     *  - `org_ink_ratio = 0.86`：字面高 / 字号 em（**待复核**）；
     *  - `org_pt_max = 43.0`：字号**上限**（参考图标定）；`org_pt_min = 12.0`：可读性下限。
     *
     * 算法（**保证单行不溢出**，长名靠缩小字号与字距适配，绝不折行）：
     *  ① `$n = mb_strlen(trim($text))`；**宽度预算** `$budget_mm = letterhead_width_mm − 2 × org_safe_mm`
     *     （默认 156 − 4 = **152mm**）；
     *  ② **目标字面高上限** → 目标字号：`$h_target_mm = min(org_target_mm, 参考图标定换算上限)`，
     *     `$pt_target = $h_target_mm / org_ink_ratio × 72 / 25.4`；
     *  ③ **宽度上限**（解析求解，字距按 `ls = org_letter_spacing_pt × pt / org_pt_max` 随 pt 等比展开，
     *     故每 pt 的等效字宽系数为 `n × org_advance_em + (n−1) × ls_per_pt`，其中
     *     `ls_per_pt = org_letter_spacing_pt / org_pt_max`）：
     *     `$pt_width = ($budget_mm × 72 / 25.4) / ($n × org_advance_em + ($n − 1) × ls_per_pt)`；
     *  ④ `$pt = max(org_pt_min, min(org_pt_max, $pt_target, $pt_width))`；
     *  ⑤ **返回前用同一公式复算** `$n × org_advance_em × pt + ($n−1) × ls ≤ budget`；
     *     不成立则继续下调 `pt`（用解析式一步解出需要的 pt，再留 1e-6pt 余量吸收四舍五入），
     *     `pt` 不会被抬回 `org_pt_min` —— **不溢出优先于可读性下限**（下限只在数学上可行时约束）。
     *
     * @param string $text 红头文字（如「中国传统文化促进会孔子文化传承工作委员会文件」）。
     * @return array{pt:float,letter_spacing_pt:float} 字号（pt）与字距（pt）。
     */
    function zw_doc_head_fit($text) {
        $text = trim((string) $text);
        $n    = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $n    = (int) $n;

        $max = (float) zw_doc_gb9704_get('header.org_pt_max', (float) zw_doc_gb9704_get('header.org_pt', 43.0));
        $ls_ref = (float) zw_doc_gb9704_get('header.org_letter_spacing_pt', 2.0);

        // 空串：返回上限值（红色反线等其余版式要素不受影响）。
        if ($n <= 0) {
            return array('pt' => $max, 'letter_spacing_pt' => $ls_ref);
        }

        $avail   = (float) zw_doc_gb9704_get('header.letterhead_width_mm', 156.0);
        $target  = (float) zw_doc_gb9704_get('header.org_target_mm', 15.2);
        $advance = (float) zw_doc_gb9704_get('header.org_advance_em', 1.0);   // 宽度预算：1.0em/字（保守）
        $safe    = (float) zw_doc_gb9704_get('header.org_safe_mm', 2.0);
        $ink     = (float) zw_doc_gb9704_get('header.org_ink_ratio', 0.86);
        $min     = (float) zw_doc_gb9704_get('header.org_pt_min', 12.0);
        $mm_pt   = 25.4 / 72;

        if ($advance <= 0 || $ink <= 0) {
            // 常量被非法改写时的保守回退：不产生除零，取上限。
            return array('pt' => $max, 'letter_spacing_pt' => $ls_ref);
        }

        // ① 宽度预算 = 版心宽 − 左右各 org_safe_mm（安全余量）。
        $budget_mm = $avail - 2 * max(0.0, $safe);
        if ($budget_mm <= 0) {
            $budget_mm = $avail;
        }
        $budget_pt = $budget_mm / $mm_pt;

        // ② 目标字面高上限 → 目标字号。
        $pt_target = min($target, $avail) / $ink * 72 / 25.4;

        // ③ 宽度上限（字距随 pt 等比展开为线性项，解析求解）。
        //    ls_per_pt = org_letter_spacing_pt / org_pt_max；n=1 时无字距项。
        $ls_per_pt = $max > 0 ? $ls_ref / $max : 0.0;
        $pt_width  = $max;
        if ($n > 1) {
            $coeff = $n * $advance + ($n - 1) * $ls_per_pt;
            if ($coeff > 0) {
                $pt_width = $budget_pt / $coeff;
            }
        } else {
            $coeff = $advance;
            if ($coeff > 0) {
                $pt_width = $budget_pt / $coeff;
            }
        }

        // ④ 三值取小：`max(org_pt_min, min(org_pt_max, pt_target, pt_width))`。
        //    下限只在**数学上可行**（`pt_width ≥ org_pt_min`）时才约束；否则宽度上限优先（不溢出）。
        $pt = min($max, $pt_target, $pt_width);
        if ($pt < $min && $pt_width >= $min) {
            $pt = $min;
        }

        // ⑤ 返回前**用同一公式复算**：`n × advance × pt + (n−1) × ls ≤ budget_pt`。
        //    字距是 pt 的线性函数 ⇒ 总宽对 pt 也是线性的 ⇒ 解析式一步解出。
        //    注意：`pt`/`ls` **先按返回精度（4 位小数）量化再复算**，否则 round() 可能把宽度顶出预算。
        $coeff = $n * $advance + ($n > 1 ? ($n - 1) * $ls_per_pt : 0.0);
        $pt = round(max(0.0, $pt), 4);
        $ls = $max > 0 ? round(max(0.0, $ls_ref * $pt / $max), 4) : 0.0;
        $w  = $n * $advance * $pt + ($n > 1 ? ($n - 1) * $ls : 0.0);
        $guard = 0;
        while ($w > $budget_pt && $guard < 8) {
            $guard++;
            // 每轮退一个最小量化步（1e-4pt），量化后仍超则继续退。
            $pt = round(max(0.0, $pt - 1.0e-4), 4);
            $ls = $max > 0 ? round(max(0.0, $ls_ref * $pt / $max), 4) : 0.0;
            $w  = $n * $advance * $pt + ($n > 1 ? ($n - 1) * $ls : 0.0);
        }

        return array(
            'pt'                => $pt,
            'letter_spacing_pt' => $ls,
        );
    }
}

if (!function_exists('zw_doc_head_stretch')) {
    /**
     * 红头**纵向拉长**口径（TASK-1282 / CHG-158 引入；TASK-1287 / CHG-159 改为**全等高**）：HTML/加密 PDF 与 DOCX 共用。
     *
     * 用户拍板（TASK-1287）：**方案 C —— 全等高**，「高度都按照 `中国传统文化促进会文件` 的高度自动拉长」。
     * 主控已在对比图与答复中明确告知「26 字需 ≈2.85× 纵向拉伸、**明显失真**」，用户仍选择 C
     * ⇒ 本实现按 C 落地，**失真是业务方决定、已获用户确认**（登记于 SPEC-87 §5.2 与 CHG-159，**非实现缺陷**）。
     *
     * 两种模式（`header.org_height_mode`）：
     *  - **`equal`（默认，方案 C）**：`scale_y = org_ink_target_mm / (pt × org_ink_ratio × 25.4/72)`
     *    —— `pt` **仍只由宽度预算决定**（`zw_doc_head_fit()`），逐用例反解纵向拉长系数，
     *    使**所有**红头的字面高都收敛到 `org_ink_target_mm`（13.7mm = 11 字「…文件」当前渲染字面高）。
     *    推论（真实输出见 CHG-159 §四）：9 字 43pt → **≈1.05**；11 字 37.58pt → **1.20**（用户认可的观感不变）；
     *    26 字 15.86pt → **≈2.85**；30 字 ≈3.29×、40 字 ≈4.38×（**外推失真加剧，登记为待用户复核项**）。
     *    `org_height_scale_max > 0` 时对 `scale_y` 设上限（**当前 0 = 不设上限**）。
     *  - **`fixed`（旧行为，保留回退）**：`scale_y = org_height_scale`（1.2）——与 CHG-158 逐字一致。
     *
     * **为什么绝不直接放大字号（失败模式警示，禁止复发）**：9 字 43pt 在实机预览里**已实测 142.87mm**，
     * 若字号 ×1.2 = 51.6pt ⇒ **163.8mm > 156mm 版心即溢出**；26 字若按 2.85× 放大字号更会到 **≈615mm**，
     * 与「绝不折行 / 不出界」硬约束冲突。故采用**纵向拉长（宽度不变）**：
     *  - HTML / 加密 PDF（走 HTML 栅格化）：`.zw-doc-org` 加 `transform: scaleY(scale_y)`
     *    （`transform` **不改变布局宽度** ⇒ 单行与不出界继续成立）；
     *    行高补偿见 `zw_doc_head_org_padding_pt()`；
     *  - DOCX：字号 = **宽度口径字号 × scale_y**（`docx_pt`）＋ OOXML **字符宽度缩放**
     *    `<w:w w:val="{docx_char_width_percent}"/>`（`100 / scale_y` 四舍五入）
     *    ⇒ 字高拉长 scale_y 倍、字符步进 ≈ 不变（**行宽不变**）。
     *
     * ⚠️ **红线**：`scale_y` **绝不允许乘进宽度预算**。`zw_doc_head_fit()` 的 pt 仍完全由宽度决定；
     * 把 `scale_y` 乘进字号即**重犯 TASK-1280 的红头溢出缺陷**（用户已截图报过一次）。
     *
     * @param string $text 红头文字（如「中国传统文化促进会传统文化与对话心理学工作委员会文件」）。
     * @return array{scale_y:float,docx_pt:float,docx_char_width_percent:int}
     *         `scale_y` 纵向拉长系数；`docx_pt` DOCX 用字号（pt）；`docx_char_width_percent` DOCX 字符宽度缩放百分比。
     */
    function zw_doc_head_stretch($text) {
        // 字号/字距仍取**宽度口径**的唯一实现（本函数**不参与、不修改**宽度预算）。
        $fit = zw_doc_head_fit($text);
        $pt  = (float) $fit['pt'];

        $mode = (string) zw_doc_gb9704_get('header.org_height_mode', 'equal');
        if ($mode === 'fixed') {
            // 旧行为（CHG-158）：统一 × org_height_scale。
            $scale = (float) zw_doc_gb9704_get('header.org_height_scale', 1.0);
            if ($scale <= 0) {
                $scale = 1.0;   // 常量被非法改写时的保守回退（不拉长）
            }
        } else {
            // 全等高（方案 C）：逐用例反解纵向拉长系数，使字面高收敛到目标 13.7mm。
            $target_mm = (float) zw_doc_gb9704_get('header.org_ink_target_mm', 0.0);
            $ink_ratio = (float) zw_doc_gb9704_get('header.org_ink_ratio', 0.86);
            // 宽度口径字号在版面上的自然字面高（mm）：pt × (字面高/em) × (25.4/72)。
            $natural_mm = $pt * $ink_ratio * 25.4 / 72.0;
            if ($target_mm > 0 && $natural_mm > 0) {
                $scale = $target_mm / $natural_mm;
            } else {
                // 常量被非法改写时的保守回退：不拉长（不产生除零/负缩放）。
                $scale = 1.0;
            }
            // 可选上限（`org_height_scale_max = 0` ⇒ 不设上限；后续给超长机关名加帽用，**待复核**）。
            $cap = (float) zw_doc_gb9704_get('header.org_height_scale_max', 0.0);
            if ($cap > 0 && $scale > $cap) {
                $scale = $cap;
            }
            if ($scale <= 0) {
                $scale = 1.0;
            }
        }

        // 字符宽度缩放百分比：`w:w` 为百分比整数，合法区间 1..600（100/scale_y 四舍五入）。
        $width_pct = (int) round(100.0 / $scale);
        if ($width_pct < 1) {
            $width_pct = 1;
        } elseif ($width_pct > 600) {
            $width_pct = 600;
        }

        return array(
            'scale_y'                 => $scale,
            'docx_pt'                 => round($pt * $scale, 4),
            'docx_char_width_percent' => $width_pct,
        );
    }
}

if (!function_exists('zw_doc_head_org_padding_pt')) {
    /**
     * 红头 `.zw-doc-org` 的**行高补偿**（对称上下 padding，pt）—— 把纵向拉长后的字面**包回布局盒**，
     * 从而不压占上方「份号/密级/紧急程度」标记与下方发文字号
     * （TASK-1282 / CHG-158 引入；TASK-1287 / CHG-159 改为**按实际 `scale_y`**）。
     *
     * **为什么不复用 CHG-158 的固定补偿量**：CHG-158 的 `Δ = max(0, 0.5 × org_ink_ratio × org_pt_max ×
     * org_height_scale − 0.5 × title.line_pt)` = **4.688pt 恒定**（用字号**上限** + 固定 1.2 推导），
     * 对 26 字（scale_y ≈2.85）**补偿不足**；且它在 `.zw-doc-org` 上恒定，与「每篇实际拉长系数」无关。
     * 本函数改为**逐用例**计算。
     *
     * 推导（`transform-origin:center center`，`transform` 不改变布局高度 ⇒ 缩放后字面会溢出原行盒）：
     *  - `L` = 拉长前布局盒高（pt）；`H` = 拉长后字面高（pt）= `org_ink_ratio × docx_pt`；
     *  - 缩放前字面相对盒中心的偏移 = `(H/scale_y − L) / 2`，缩放后 = `(H − L × scale_y) / 2`；
     *  - 缩放后盒半高 = `scale_y × L/2 + P`（padding 在**缩放前**加入、**不被** `transform` 缩放 ——
     *    这是 `transform` 与「把 padding 写进盒」的关键差别）；
     *  - 要求「包住 + 安全余量」⇒ `P = max(0, (H − L × scale_y)/2 + org_pad_safe_mm×25.4/72)`。
     *
     * ⚠️ `L` **不能**用 `title.line_pt`（35pt）：`.zw-doc-org` 的 `font-size` 在 15.86~43pt 之间变化，
     * 而静态 `line-height:35pt` 是**绝对值**，`html` 未设字号（继承浏览器默认）⇒ 浏览器实测计算行盒
     * 随字号变化且**明显大于** `title.line_pt`（实测比值 1.25~2.44 × 当前字号，见 CHG-159 §四）。
     * 故 `L` 取**保守下界** `title.line_pt`：`L` 越小 ⇒ `P` 越大 ⇒ 补偿**只多不少**（安全侧）。
     * 结果：只要 `scale_y ≲ 1.29`（含 9 字 1.0502 与 11 字 1.2016）`P = 0` —— 与 CHG-158 的固定 4.688pt 相比
     * **不再多补**（少占版头高度）；`scale_y` 明显更大时（26 字 2.8468 起）才开始补。
     * 本函数**不依赖**浏览器实测行盒（无头浏览器不可用时仍可算），是「不压占上下元素」的**保守下界**保障；
     * 实际上限由 CHG-159 §四的**实机墨迹量测**闭合（9 / 11 / 26 字红头与上方标记间隔 ≥ 7.04mm、
     * 与下方发文字号间隔 ≥ 17.13mm，**均无重叠**）。
     *
     * @param string $text 红头文字。
     * @return float 对称上下 padding（pt，≥ 0，4 位小数）。
     */
    function zw_doc_head_org_padding_pt($text) {
        $stretch = zw_doc_head_stretch($text);
        $ink     = (float) zw_doc_gb9704_get('header.org_ink_ratio', 0.86);
        $line_pt = (float) zw_doc_gb9704_get('title.line_pt', 35.0);
        $safe_pt = (float) zw_doc_gb9704_get('header.org_pad_safe_mm', 0.3) * 25.4 / 72.0;

        // H = 拉长后字面高（pt）；L = 拉长前布局盒高（pt，保守下界取 title.line_pt）。
        $ink_h_pt = $ink * (float) $stretch['docx_pt'];
        $box_pt   = $line_pt * (float) $stretch['scale_y'];

        $pad = 0.5 * ($ink_h_pt - $box_pt) + $safe_pt;
        if ($pad < 0) {
            $pad = 0.0;
        }

        return round($pad, 4);
    }
}

if (!function_exists('zw_doc_gb9704_text_box_mm')) {
    /**
     * 版心尺寸（由纸张与页边距推导）。
     *
     * @return array{w_mm:float,h_mm:float}
     */
    function zw_doc_gb9704_text_box_mm() {
        $paper  = zw_doc_gb9704_get('paper', array());
        $margin = zw_doc_gb9704_get('margin', array());
        return array(
            'w_mm' => round((float) $paper['w_mm'] - (float) $margin['left_mm'] - (float) $margin['right_mm'], 2),
            'h_mm' => round((float) $paper['h_mm'] - (float) $margin['top_mm'] - (float) $margin['bottom_mm'], 2),
        );
    }
}

if (!function_exists('zw_doc_gb9704_docx_page_setup')) {
    /**
     * DOCX（OOXML）页面与页边距参数，单位 twips。
     *
     * @return array
     */
    function zw_doc_gb9704_docx_page_setup() {
        $paper  = zw_doc_gb9704_get('paper', array());
        $margin = zw_doc_gb9704_get('margin', array());
        return array(
            'pgSz'  => array(
                'w' => zw_doc_gb9704_mm_to_twips($paper['w_mm']),
                'h' => zw_doc_gb9704_mm_to_twips($paper['h_mm']),
            ),
            'pgMar' => array(
                'top'    => zw_doc_gb9704_mm_to_twips($margin['top_mm']),
                'right'  => zw_doc_gb9704_mm_to_twips($margin['right_mm']),
                'bottom' => zw_doc_gb9704_mm_to_twips($margin['bottom_mm']),
                'left'   => zw_doc_gb9704_mm_to_twips($margin['left_mm']),
            ),
        );
    }
}

if (!function_exists('zw_doc_gb9704_font_slot')) {
    /**
     * 取某个版式位的字体定义。
     *
     * 正文位（h3/h4/body）共用同一份定义，避免三处漂移。
     *
     * @param string $slot body|title|h1|h2|h3|h4|page。
     * @return array|null
     */
    function zw_doc_gb9704_font_slot($slot) {
        $slot = (string) $slot;
        if ($slot === 'note') {
            $slot = 'body'; // 版记用正文体
        }
        $fonts = zw_doc_gb9704_get('fonts', array());
        return isset($fonts[$slot]) && is_array($fonts[$slot]) ? $fonts[$slot] : null;
    }
}

if (!function_exists('zw_doc_gb9704_font_face_src')) {
    /**
     * 构造 @font-face 的 src 值：**只走 local() 国标字体名链**（TASK-1863）。
     *
     * 语义：协会授权字体已装入服务器（TASK-1862），HTML/PDF 通道由渲染引擎按 `local()` 命中真字体；
     * 浏览器预览时若本机未装同名字体，由**系统字体**替换（**不再托管 OFL 近似体**）。
     * 主题仓库不分发任何商业字体文件；`hosted` 字段保留但当前一律为空串。
     *
     * @param string $slot     版式位。
     * @param string $base_url 主题静态资源前缀（如 get_template_directory_uri()）。
     * @return string 形如 local("仿宋_GB2312"), local("FangSong_GB2312")。
     */
    function zw_doc_gb9704_font_face_src($slot, $base_url = '') {
        $font = zw_doc_gb9704_font_slot($slot);
        if (!$font) {
            return '';
        }

        $parts = array();
        foreach ((array) $font['local'] as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $parts[] = 'local("' . $name . '")';
        }

        $hosted = trim((string) $font['hosted']);
        if ($hosted !== '') {
            $parts[] = 'url("' . rtrim((string) $base_url, '/') . $hosted . '") format("truetype")';
        }

        return implode(', ', $parts);
    }
}

if (!function_exists('zw_doc_gb9704_effective_dpi')) {
    /**
     * 素材在给定物理宽度下的有效分辨率。
     *
     * @param int   $px       像素宽。
     * @param float $width_mm 物理宽度（毫米）。
     * @return int
     */
    function zw_doc_gb9704_effective_dpi($px, $width_mm) {
        $px       = (int) $px;
        $width_mm = (float) $width_mm;
        if ($px <= 0 || $width_mm <= 0) {
            return 0;
        }
        return (int) round($px / $width_mm * 25.4);
    }
}

if (!function_exists('zw_doc_gb9704_seal_effective_dpi')) {
    /**
     * 印章素材的有效分辨率（按**该印章真实直径**估算）。
     *
     * TASK-1985：章径不再是全局常量 —— 真实印章直径（如本会公章 40mm）由登记表逐章登记，
     * 有效 dpi 必须按**该章直径**折算（`dpi = 短边px ÷ (直径mm / 25.4)`），否则「低清」判定会随
     * 「用哪个直径」漂移。传 0 时回退兼容常量（旧调用方零变化）。
     *
     * @param int   $px          印章图片短边像素。
     * @param float $diameter_mm 真实直径（mm）；0 = 取兼容常量。
     * @return int
     */
    function zw_doc_gb9704_seal_effective_dpi($px, $diameter_mm = 0) {
        $d = (float) $diameter_mm;
        if ($d <= 0) {
            $d = (float) zw_doc_gb9704_get('seal.diameter_mm', 42.0);
        }

        return zw_doc_gb9704_effective_dpi($px, $d);
    }
}

if (!function_exists('zw_doc_gb9704_seal_geometry')) {
    /**
     * 印章版面几何（GB/T 9704-2012 §7.3.5.1）—— **唯一实现**，HTML/加密 PDF 共用。
     *
     * 落款（有印章）的版面结构（见 `zw_doc_render_sign_html()` / `zw_doc_render_style_block()`）：
     * ```
     *   署名行  ┐            ← `.zw-doc-sign-org`（绝对定位，水平居中于成文日期）
     *   日期行  ┘ 块心 = 日期行顶边  ← `.zw-doc-sign-ref`（宽度 = 成文日期宽；右空 N 字）
     * ```
     * 成文日期右空四字（`sign.date_right_indent_chars_with_seal`）；署名「以成文日期为准居中」。
     *
     * 返回三个可断言的量：
     * - `top_mm`  ：印章**顶边**相对「日期行顶边」的上移量 = `center_offset_mm + 直径/2`
     *               （章心在日期行顶边上方 `center_offset_mm`，而日期行顶边正是落款块心 ⇒ 块心居章心偏下）；
     * - `above_block_mm`：印章**顶边**高出落款块顶边多少 = `top_mm − 行高mm`（章大于落款块时会伸出）；
     * - `gap_lines`：落款容器上间距应留的**正文行数** —— 取「基础空行数」与
     *               `ceil((above_block_mm + body_clear_mm) / 行高mm)` 的较大者，
     *               使**印章顶边到正文（或附件说明）的距离 ≥ body_clear_mm 且 ≤ 一行**
     *               （GB「印章顶端应当上距正文（或附件说明）一行之内」；空间不足时本函数自动多留一行）。
     *
     * @param float $diameter_mm 真实章径（mm）；0 = 取兼容常量。
     * @param float $line_pt     正文行高（pt）；0 = 取 `text.line_pt`。
     * @return array{diameter_mm:float,line_pt:float,line_mm:float,center_offset_mm:float,top_mm:float,above_block_mm:float,base_gap_lines:int,gap_lines:int}
     */
    function zw_doc_gb9704_seal_geometry($diameter_mm = 0, $line_pt = 0) {
        $d = (float) $diameter_mm;
        if ($d <= 0) {
            $d = (float) zw_doc_gb9704_get('seal.diameter_mm', 42.0);
        }
        $min = (float) zw_doc_gb9704_get('seal.diameter_min_mm', 30.0);
        $max = (float) zw_doc_gb9704_get('seal.diameter_max_mm', 45.0);
        if ($d < $min) {
            $d = $min;
        }
        if ($d > $max) {
            $d = $max;
        }
        $line_pt = (float) $line_pt;
        if ($line_pt <= 0) {
            $cfg     = zw_doc_gb9704_spec();
            $line_pt = isset($cfg['text']['line_pt']) ? (float) $cfg['text']['line_pt'] : 28.0;
        }
        $line_mm = $line_pt * 25.4 / 72;
        if ($line_mm <= 0) {
            $line_mm = 28.0 * 25.4 / 72;
        }

        $offset = (float) zw_doc_gb9704_get('seal.center_offset_mm', 4.0);
        if ($offset < 0) {
            $offset = 0.0;
        }
        $clear = (float) zw_doc_gb9704_get('seal.body_clear_mm', 1.0);
        if ($clear < 0) {
            $clear = 0.0;
        }

        // 摆放口径（TASK-1993）：非法值一律回退 'over'（国标口径，保守）。
        $placement = (string) zw_doc_gb9704_get('seal.placement', 'over');
        if ($placement !== 'below') {
            $placement = 'over';
        }
        $gap_below = (float) zw_doc_gb9704_get('seal.gap_below_sign_mm', 0.0);
        if ($gap_below < 0) {
            $gap_below = 0.0;
        }

        if ($placement === 'below') {
            // 章顶 = 成文日期底边 + 间距；参照盒高 = 一行 ⇒ 相对「日期行顶」的下移量 = line_mm + gap（正）。
            $top_mm        = $line_mm + $gap_below;
            $above_block   = 0.0;              // 章不再伸到落款块之上
            $reserve_below = $d + $gap_below;  // 落款之后须预留的空间（章高 + 缝隙）
        } else {
            // 'over'（国标）：章心在落款块心上方 `center_offset` ⇒ 章顶相对「日期行顶」**上移**
            // （带符号下移量 ⇒ 负值），故此处取负；CSS/内联直接落这个值即可。
            $top_mm        = -1 * ($offset + $d / 2);
            $reserve_below = 0.0;
            $above_block   = abs($top_mm) - $line_mm;
            if ($above_block < 0) {
                $above_block = 0.0;
            }
        }
        $base_lines = function_exists('zw_doc_gb9704_gap_pt')
            ? max(0, (int) round(zw_doc_gb9704_gap_pt('sign.gap_lines_before_org', 1) / $line_pt))
            : 1;
        $need_mm   = $above_block + $clear;
        $need_lines = (int) ceil($need_mm / $line_mm - 1e-9);
        if ($need_lines < 0) {
            $need_lines = 0;
        }
        // 默认**不自动加行**（见常量 `seal.reserve_body_gap` 的取舍说明）：
        // 加行会在「正文恰好排满」时把版记顶出原位 ⇒ 章压版记（硬缺陷）；不加行只会让章顶压到正文末行**行盒**，
        // 其偏差量由 `body_gap_mm` 如实返回（负值 = 章顶高过正文末行底边多少 mm）。
        $reserve   = (bool) zw_doc_gb9704_get('seal.reserve_body_gap', false);
        $gap_lines = $reserve ? max($base_lines, $need_lines) : $base_lines;

        return array(
            'diameter_mm'       => round($d, 2),
            'line_pt'           => round($line_pt, 4),
            'line_mm'           => round($line_mm, 4),
            'center_offset_mm'  => round($offset, 2),
            'top_mm'            => round($top_mm, 2),
            'above_block_mm'    => round($above_block, 2),
            'need_gap_lines'    => $need_lines,
            'base_gap_lines'    => $base_lines,
            'gap_lines'         => $gap_lines,
            // 章顶 − 正文末行底边（mm）：≥0 = 章在正文之下且间隔该值（GB「一行之内」）；
            // <0 = 章顶高过正文末行底边该值（行盒重叠，是否压到字墨取决于末行文字的横向位置）。
            'body_gap_mm'       => round($gap_lines * $line_mm - $above_block, 2),
            // TASK-1993：摆放口径 + 落款之后须预留的空间（'below' = 章径 + 缝隙；'over' = 0）。
            'placement'         => $placement,
            'gap_below_sign_mm' => round($gap_below, 2),
            'reserve_below_mm'  => round($reserve_below, 2),
        );
    }
}

if (!function_exists('zw_doc_gb9704_seal_is_low_dpi')) {
    /**
     * 印章素材是否低于「低清」阈值（需在 UI 与产物上标注）。
     *
     * @param int $px 印章图片短边像素。
     * @return bool
     */
    function zw_doc_gb9704_seal_is_low_dpi($px) {
        $threshold = (int) zw_doc_gb9704_get('seal.low_dpi_threshold', 300);
        return zw_doc_gb9704_seal_effective_dpi($px) < $threshold;
    }
}

if (!function_exists('zw_doc_gb9704_allowed_dpi')) {
    /**
     * 允许的栅格化 dpi 白名单（服务端强制夹取，防资源耗尽）。
     *
     * @return int[]
     */
    function zw_doc_gb9704_allowed_dpi() {
        $options = array_map('intval', (array) zw_doc_gb9704_get('pdf.dpi_options', array()));
        $options = array_values(array_filter($options, function ($dpi) {
            return $dpi >= 72 && $dpi <= 600;
        }));
        return !empty($options) ? $options : array(200);
    }
}

if (!function_exists('zw_doc_gb9704_normalize_dpi')) {
    /**
     * 把任意输入夹取到白名单内的 dpi。
     *
     * @param mixed $dpi 输入 dpi。
     * @return int
     */
    function zw_doc_gb9704_normalize_dpi($dpi) {
        $allowed = zw_doc_gb9704_allowed_dpi();
        $dpi     = (int) $dpi;
        if (in_array($dpi, $allowed, true)) {
            return $dpi;
        }
        $default = (int) zw_doc_gb9704_get('pdf.dpi_default', $allowed[0]);
        return in_array($default, $allowed, true) ? $default : $allowed[0];
    }
}

if (!function_exists('zw_doc_gb9704_disclaimer')) {
    /**
     * 统一声明文案。
     *
     * @param string $key font|encrypt|draft|seal_low_dpi。
     * @return string
     */
    function zw_doc_gb9704_disclaimer($key) {
        $text = zw_doc_gb9704_get('disclaimer.' . (string) $key, '');
        return is_string($text) ? $text : '';
    }
}

if (!function_exists('zw_doc_gb9704_text_has_forbidden_phrase')) {
    /**
     * 文案是否含被禁用的夸大表述（「无法复制」等）。
     *
     * @param string $text 待检文案。
     * @return string|false 命中的禁用词，未命中返回 false。
     */
    function zw_doc_gb9704_text_has_forbidden_phrase($text) {
        $phrases = (array) zw_doc_gb9704_get('disclaimer.forbidden_phrases', array());
        foreach ($phrases as $phrase) {
            $phrase = (string) $phrase;
            if ($phrase !== '' && strpos((string) $text, $phrase) !== false) {
                return $phrase;
            }
        }
        return false;
    }
}
