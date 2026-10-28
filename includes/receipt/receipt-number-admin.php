<?php
/**
 * 收文管理 · 收文编号设置（TASK-1306）。
 *
 * 目的：让管理员可视化配置「不同部委可识别的编号规则」——
 *   - 每个部委一个**编号码**（1~2 字符，如 民/社/文/党/会/通），编号形如 `中传收民〔2026〕1号`；
 *   - 起始序号（全局默认 + 按年覆盖）；
 *   - 是否「每部委独立序号域」（默认开：各部委各自从起始序号计数）。
 *
 * 硬口径（**不可配置**，用户拍板）：
 *   - 前缀固定「中传收」；
 *   - 序号**不补零**；`digits` 仅作界面提示。
 *
 * 边界：
 *   - 页面与提交均判 `tcpc_perm_doc`；nonce 双判；
 *   - 仅经 canonical `zw_receipt_number_save_settings()` 落库（归一 + 审计）；
 *   - 本页**不改已发编号**：起始序号只影响尚未播种的年份/部委。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ZW_RECEIPT_NUMBER_NONCE_ACTION')) {
    define('ZW_RECEIPT_NUMBER_NONCE_ACTION', 'zw_receipt_number_settings_save');
}

if (!function_exists('zw_receipt_number_page_can')) {
    /**
     * 编号设置页权限（与收文管理同一位：`tcpc_perm_doc`）。
     *
     * @return bool
     */
    function zw_receipt_number_page_can() {
        return function_exists('current_user_can') && current_user_can('tcpc_perm_doc');
    }
}

if (!function_exists('zw_receipt_number_form_rows')) {
    /**
     * **纯函数**：把设置整理成页面渲染所需的行（部委 → 码 / 名称）。
     *
     * @param array $settings 归一后的设置。
     * @return array[] 每项 array('key'=>,'label'=>,'code'=>,'is_default'=>bool)
     */
    function zw_receipt_number_form_rows($settings) {
        $settings = is_array($settings) ? $settings : array();
        $codes    = isset($settings['org_codes']) && is_array($settings['org_codes']) ? $settings['org_codes'] : array();
        $orgs     = function_exists('zw_receipt_org_canonical') ? zw_receipt_org_canonical() : array();
        $defaults = function_exists('zw_receipt_number_default_org_codes') ? zw_receipt_number_default_org_codes() : array();
        $rows     = array();
        foreach ($orgs as $key => $label) {
            $code = isset($codes[$key]) ? (string) $codes[$key] : '';
            $rows[] = array(
                'key'        => (string) $key,
                'label'      => (string) $label,
                'code'       => $code,
                'is_default' => (isset($defaults[$key]) && (string) $defaults[$key] === $code),
            );
        }
        return $rows;
    }
}

if (!function_exists('zw_receipt_number_preview')) {
    /**
     * **纯函数**：编号样例预览（每部委一行，用给定年份与有效起始序号）。
     *
     * @param array $settings 归一后的设置。
     * @param int   $year     预览年份。
     * @return array[] 每项 array('label'=>,'number'=>)
     */
    function zw_receipt_number_preview($settings, $year) {
        $settings = is_array($settings) ? $settings : array();
        $year     = (int) $year;
        $start    = function_exists('zw_receipt_number_start_seq')
            ? (int) zw_receipt_number_start_seq($year)
            : 1;
        $orgs     = function_exists('zw_receipt_org_canonical') ? zw_receipt_org_canonical() : array();
        $out      = array();
        foreach ($orgs as $key => $label) {
            $out[] = array(
                'label'  => (string) $label,
                'number' => function_exists('zw_receipt_format_number')
                    ? (string) zw_receipt_format_number($year, $start, $key)
                    : '',
            );
        }
        return $out;
    }
}

if (!function_exists('zw_receipt_number_parse_form')) {
    /**
     * **纯函数**：把后台表单原始输入整理成待保存设置（不落库、不判权）。
     *
     * @param array $post 原始 `$_POST`（或测试注入）。
     * @return array 供 `zw_receipt_number_save_settings()` 的入参。
     */
    function zw_receipt_number_parse_form($post) {
        $post = is_array($post) ? $post : array();

        $codes_in = isset($post['org_codes']) && is_array($post['org_codes']) ? $post['org_codes'] : array();
        $orgs     = function_exists('zw_receipt_org_canonical') ? zw_receipt_org_canonical() : array();
        $codes    = array();
        foreach ($orgs as $key => $label) {
            $raw = isset($codes_in[$key]) ? $codes_in[$key] : '';
            if (function_exists('zw_receipt_number_clean_org_code')) {
                $code = zw_receipt_number_clean_org_code($raw);
            } else {
                $code = trim((string) $raw);
            }
            if ($code !== '') {
                $codes[$key] = $code;
            }
        }

        $overrides = array();
        $years_in  = isset($post['year_overrides']) && is_array($post['year_overrides']) ? $post['year_overrides'] : array();
        foreach ($years_in as $year => $seq) {
            $year = (int) $year;
            $seq  = (int) $seq;
            if ($year >= 1900 && $year <= 2200 && $seq >= 1) {
                $overrides[(string) $year] = $seq;
            }
        }

        return array(
            // 走 canonical 归一：digits 夹紧 1~6、start_seq 下限 1、码去噪 —— 页面与落库口径一致。
            'digits'              => function_exists('zw_receipt_number_settings_sanitize')
                ? (int) zw_receipt_number_settings_sanitize(array('digits' => isset($post['digits']) ? $post['digits'] : 3))['digits']
                : (isset($post['digits']) ? (int) $post['digits'] : 3),
            'start_seq'           => isset($post['start_seq']) ? (int) $post['start_seq'] : 1,
            'start_seq_overrides' => $overrides,
            'org_codes'           => $codes,
            'per_org_seq'         => !empty($post['per_org_seq']),
        );
    }
}

if (!function_exists('zw_receipt_number_handle_post')) {
    /**
     * `admin_post_zw_receipt_number_settings` 入口（仅功能开启后由本文件注册）。
     *
     * @return void
     */
    function zw_receipt_number_handle_post() {
        if (!function_exists('wp_die') || !function_exists('wp_safe_redirect')) {
            return;
        }
        if (!zw_receipt_number_page_can()) {
            wp_die('无权限保存收文编号设置（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
        }
        $nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
        if (!function_exists('wp_verify_nonce') || $nonce === '' || !wp_verify_nonce($nonce, ZW_RECEIPT_NUMBER_NONCE_ACTION)) {
            wp_die('收文编号设置校验失败：安全令牌无效或已过期，请刷新页面重试。', '校验失败', array('response' => 403));
        }

        $post    = function_exists('wp_unslash') ? (array) wp_unslash($_POST) : (array) $_POST;
        $input   = zw_receipt_number_parse_form($post);
        $saved   = zw_receipt_number_save_settings($input);

        wp_safe_redirect(add_query_arg(array(
            'page'          => 'zw-receipt-number',
            'zw_receipt_msg'=> 'number-settings-saved',
            'zw_receipt_years' => count((array) $saved['start_seq_overrides']),
        ), admin_url('admin.php')));
        exit;
    }
}

if (!function_exists('zw_receipt_number_page')) {
    /**
     * 收文编号设置页渲染（TASK-1306）。
     *
     * @return void
     */
    function zw_receipt_number_page() {
        if (!zw_receipt_number_page_can()) {
            if (function_exists('wp_die')) {
                wp_die('无权限访问收文管理（需要 tcpc_perm_doc）。', '无权访问', array('response' => 403));
            }
            return;
        }
        if (!function_exists('esc_html') || !function_exists('esc_attr')) {
            return;
        }

        $settings   = zw_receipt_number_settings();
        $rows       = zw_receipt_number_form_rows($settings);
        $current_yr = (int) (function_exists('current_time') ? current_time('Y') : gmdate('Y'));
        $preview    = zw_receipt_number_preview($settings, $current_yr);
        $saved      = (isset($_GET['zw_receipt_msg']) && $_GET['zw_receipt_msg'] === 'number-settings-saved');

        // 码 → 部委 冲突提示（同一码被两部委占用时 canonical 会保留先到者，这里显式告知）。
        $code_owner = array();
        $dups       = array();
        foreach ($rows as $row) {
            if ($row['code'] === '') {
                continue;
            }
            if (isset($code_owner[$row['code']]) && $code_owner[$row['code']] !== $row['key']) {
                $dups[] = $row['code'] . '（' . $row['label'] . ' 与 ' . $code_owner[$row['code']] . '）';
            } else {
                $code_owner[$row['code']] = $row['key'];
            }
        }
        ?>
        <div class="wrap zw-receipt-number-wrap">
            <h1>收文编号设置</h1>
            <?php
            // TASK-1633（TASK-1579 P1-6 第四批）：收文管理域不在会员域外壳作用域内
            // （slug 前缀 `zw-receipt` 不命中 `zw_mc_adm_is_screen()`）⇒ 本页须自带 canonical 规则输出点。
            // 唯一出处仍是 canonical `zw_adm_ui_table_wrap_css()`；本处只透传，不复制第二套声明。
            echo function_exists('zw_receipt_admin_ui_table_wrap_style') ? zw_receipt_admin_ui_table_wrap_style() : '';
            ?>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible"><p>收文编号设置已保存。</p></div>
            <?php endif; ?>

            <?php if ($dups) : ?>
                <div class="notice notice-warning"><p><strong>部委码重复：</strong><?php echo esc_html(implode('；', $dups)); ?>
                    —— 同一码只能归属一个部委，请修改后重新保存。</p></div>
            <?php endif; ?>

            <p class="description">
                编号形如 <code>中传收民〔<?php echo esc_html((string) $current_yr); ?>〕1号</code>：
                「中传收」+ <strong>部委码</strong> + 年份 + 序号。<strong>前缀与「不补零」为固定口径</strong>，不可配置。
                起始序号<strong>只影响尚未取号的年份/部委</strong>，已发出的编号不会被改写。
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="zw_receipt_number_settings" />
                <?php if (function_exists('wp_nonce_field')) { wp_nonce_field(ZW_RECEIPT_NUMBER_NONCE_ACTION); } ?>

                <h2>一、部委编号码</h2>
                <div class="zw-adm-table-wrap">
                <table class="widefat striped" style="max-width:900px">
                    <thead>
                        <tr><th style="width:200px">部委</th><th style="width:120px">编号码</th><th>样例（本年第 1 号）</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <?php
                        $sample = function_exists('zw_receipt_format_number')
                            ? (string) zw_receipt_format_number($current_yr, 1, $row['key'])
                            : '';
                        ?>
                        <tr>
                            <td><?php echo esc_html($row['label']); ?></td>
                            <td>
                                <input type="text" name="org_codes[<?php echo esc_attr($row['key']); ?>]"
                                       value="<?php echo esc_attr($row['code']); ?>" maxlength="2" size="4"
                                       style="text-align:center" />
                            </td>
                            <td><code><?php echo esc_html($sample); ?></code>
                                <?php if ($row['is_default']) : ?>
                                    <span style="color:#666;font-size:12px">（默认）</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="description">编号码 1~2 个字符；留空表示该部委编号不带部委段（退化为 <code>中传收〔年〕N号</code>）。</p>

                <h2>二、序号规则</h2>
                <div class="zw-adm-table-wrap">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="zw-receipt-start-seq">起始序号</label></th>
                        <td>
                            <input type="number" min="1" step="1" id="zw-receipt-start-seq" name="start_seq"
                                   value="<?php echo esc_attr((string) (int) $settings['start_seq']); ?>" class="small-text" />
                            <p class="description">默认 1。仅对**尚未取号**的年份生效。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">每部委独立序号域</th>
                        <td>
                            <label class="zw-adm-touch">
                                <input type="checkbox" name="per_org_seq" value="1" <?php checked(!empty($settings['per_org_seq'])); ?> />
                                勾选＝各部委各自从起始序号计数（推荐）；不勾＝全部部委共用同一序号域。
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="zw-receipt-year-<?php echo esc_attr((string) $current_yr); ?>">按年覆盖（<?php echo esc_html((string) $current_yr); ?>）</label></th>
                        <td>
                            <?php
                            $yr_over = isset($settings['start_seq_overrides'][(string) $current_yr])
                                ? (int) $settings['start_seq_overrides'][(string) $current_yr]
                                : 0;
                            ?>
                            <input type="number" min="0" step="1" id="zw-receipt-year-<?php echo esc_attr((string) $current_yr); ?>"
                                   name="year_overrides[<?php echo esc_attr((string) $current_yr); ?>]"
                                   value="<?php echo $yr_over > 0 ? esc_attr((string) $yr_over) : ''; ?>" class="small-text"
                                   placeholder="留空＝用起始序号" />
                            <p class="description">用于与线下既有台账对齐、避免撞号；留空表示不覆盖。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">位数（仅提示）</th>
                        <td>
                            <input type="number" min="1" max="6" step="1" name="digits"
                                   value="<?php echo esc_attr((string) (int) $settings['digits']); ?>" class="small-text" />
                            <p class="description">序号**不补零**（固定口径），此项只用于界面说明。</p>
                        </td>
                    </tr>
                </table>
                </div>

                <?php if (function_exists('submit_button')) { submit_button('保存设置'); } else { ?>
                    <p><button type="submit" class="button button-primary">保存设置</button></p>
                <?php } ?>
            </form>

            <h2>三、编号样例（<?php echo esc_html((string) $current_yr); ?> 年，起始序号起算）</h2>
            <div class="zw-adm-table-wrap">
            <table class="widefat striped" style="max-width:700px">
                <thead><tr><th style="width:220px">部委</th><th>样例编号</th></tr></thead>
                <tbody>
                <?php foreach ($preview as $item) : ?>
                    <tr><td><?php echo esc_html($item['label']); ?></td><td><code><?php echo esc_html($item['number']); ?></code></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }
}

if (function_exists('add_action')) {
    add_action('admin_post_zw_receipt_number_settings', 'zw_receipt_number_handle_post');
}
