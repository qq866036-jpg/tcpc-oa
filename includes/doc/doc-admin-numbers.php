<?php
/**
 * 后台「文号管理」页面（TASK-433）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_number_status_label')) {
    /**
     * 台账状态中文名。
     *
     * @param string $status 状态。
     * @return string
     */
    function zw_doc_number_status_label($status) {
        $map = array(
            'reserved' => '预留',
            'claimed'  => '占用中',
            'used'     => '已使用',
            'skipped'  => '已跳过',
            'void'     => '已作废',
        );
        return isset($map[$status]) ? $map[$status] : (string) $status;
    }
}

if (!function_exists('zw_doc_numbers_handle_post')) {
    /**
     * 在 admin_init 阶段处理文号管理 POST，避免在页面输出后重定向。
     *
     * @return void
     */
    function zw_doc_numbers_handle_post() {
        if ((($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') || !isset($_POST['zw_doc_action'])) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['zw_doc_action']));
        $notice = '';

        if ($action === 'save_settings') {
            // TASK-2165（S1，用户拍板）：文号规则与文种的**唯一写面已收敛到「数据字典」页**，
            // 本页不再接收保存。旧书签 / 浏览器缓存里的旧表单仍可能 POST 进来 ⇒ **fail-closed 不落库**，
            // 只给一条可读提示。原因：本页原「文种」逗号文本框绕过了数据字典 CRUD 的护栏
            // （「任」字禁删/禁改名、首项默认文种二次确认），可让「任」字号链路整体不可达。
            check_admin_referer('zw_doc_save_number_settings');
            $notice = '文号规则与文种已统一在「数据字典」维护，本页不再保存；请到「数据字典」首行修改。';
        } elseif ($action === 'reserve_number') {
            check_admin_referer('zw_doc_reserve_number');
            $year = isset($_POST['reserve_year']) ? (int) $_POST['reserve_year'] : 0;
            $type = isset($_POST['reserve_type']) ? sanitize_text_field(wp_unslash($_POST['reserve_type'])) : '';
            $spec = isset($_POST['reserve_range']) ? sanitize_text_field(wp_unslash($_POST['reserve_range'])) : '';
            if ($spec === '' && isset($_POST['reserve_seq'])) {
                $spec = (string) (int) $_POST['reserve_seq'];
            }
            $note = isset($_POST['reserve_note']) ? sanitize_text_field(wp_unslash($_POST['reserve_note'])) : '';
            $seqs = zw_doc_parse_reserve_spec($spec);
            if (is_wp_error($seqs)) {
                $notice = $seqs->get_error_message();
            } else {
                $res = zw_doc_create_reserved_numbers($year, $type, $seqs, $note);
                if (is_wp_error($res)) {
                    $notice = $res->get_error_message();
                } else {
                    $count  = (int) $res['count'];
                    $notice = '已预留 ' . $count . ' 个文号。';
                }
            }
        } elseif ($action === 'void_number') {
            check_admin_referer('zw_doc_void_number');
            $id     = isset($_POST['number_id']) ? (int) $_POST['number_id'] : 0;
            $reason = isset($_POST['void_reason']) ? sanitize_text_field(wp_unslash($_POST['void_reason'])) : '';
            $notice = zw_doc_void_reserved_number($id, $reason) ? '预留文号已作废。' : '作废失败：仅预留状态可作废。';
        } elseif ($action === 'reserve_copy') {
            // TASK-1867（CHG-362）：份号预留（占用，不下发给任何发文）。
            check_admin_referer('zw_doc_reserve_copy');
            $spec = isset($_POST['copy_spec']) ? sanitize_text_field(wp_unslash($_POST['copy_spec'])) : '';
            $note = isset($_POST['copy_note']) ? sanitize_text_field(wp_unslash($_POST['copy_note'])) : '';
            $nos  = zw_doc_copy_number_parse_spec($spec);
            if (is_wp_error($nos)) {
                $notice = $nos->get_error_message();
            } else {
                $ok = 0;
                $first_error = '';
                foreach ($nos as $copy_no) {
                    $res = zw_doc_copy_number_reserve($copy_no, $note);
                    if (is_wp_error($res)) {
                        if ($first_error === '') {
                            $first_error = $res->get_error_message();
                        }
                        continue;
                    }
                    $ok++;
                }
                $notice = $ok > 0
                    ? sprintf('已预留 %d 个份号。', $ok)
                    : ('预留失败：' . ($first_error !== '' ? $first_error : '没有可预留的份号。'));
            }
        } elseif ($action === 'skip_copy') {
            // TASK-1867（CHG-362）：份号跳过（占用但永不再用，保留审计）。
            check_admin_referer('zw_doc_skip_copy');
            $spec   = isset($_POST['copy_spec']) ? sanitize_text_field(wp_unslash($_POST['copy_spec'])) : '';
            $reason = isset($_POST['copy_reason']) ? sanitize_text_field(wp_unslash($_POST['copy_reason'])) : '';
            $nos    = zw_doc_copy_number_parse_spec($spec);
            if (is_wp_error($nos)) {
                $notice = $nos->get_error_message();
            } else {
                $ok = 0;
                $first_error = '';
                foreach ($nos as $copy_no) {
                    $res = zw_doc_copy_number_skip($copy_no, $reason);
                    if (is_wp_error($res)) {
                        if ($first_error === '') {
                            $first_error = $res->get_error_message();
                        }
                        continue;
                    }
                    $ok++;
                }
                $notice = $ok > 0
                    ? sprintf('已跳过 %d 个份号。', $ok)
                    : ('跳过失败：' . ($first_error !== '' ? $first_error : '没有可跳过的份号。'));
            }
        } elseif ($action === 'void_copy') {
            check_admin_referer('zw_doc_void_copy');
            $id     = isset($_POST['copy_id']) ? (int) $_POST['copy_id'] : 0;
            $reason = isset($_POST['copy_void_reason']) ? sanitize_text_field(wp_unslash($_POST['copy_void_reason'])) : '';
            $notice = zw_doc_copy_number_void($id, $reason) ? '份号记录已作废（号回到可用池）。' : '作废失败：仅预留 / 已跳过的份号可从台账作废。';
        } elseif ($action === 'backfill_copy') {
            // TASK-1867（CHG-362）：从存量公文回填份号台账（幂等、只补不覆盖）。
            check_admin_referer('zw_doc_backfill_copy');
            $res    = zw_doc_copy_number_backfill();
            $notice = sprintf('已扫描 %d 篇带份号的存量公文，新登记 %d 个份号。', (int) $res['scanned'], (int) $res['inserted']);
            if (!empty($res['conflicts'])) {
                $notice .= ' 冲突 ' . count($res['conflicts']) . ' 处：' . implode('；', array_slice($res['conflicts'], 0, 5));
            }
        }

        wp_safe_redirect(add_query_arg('zw_doc_msg', $notice, zw_doc_current_url()));
        exit;
    }
}

if (!function_exists('zw_doc_page_numbers')) {
    /**
     * 文号管理页。
     *
     * @return void
     */
    function zw_doc_page_numbers() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }

        $settings = zw_doc_number_settings();
        $notice   = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';

        $f_year   = isset($_GET['f_year']) ? (int) $_GET['f_year'] : 0;
        $f_type   = isset($_GET['f_type']) ? sanitize_text_field(wp_unslash($_GET['f_type'])) : '';
        $f_status = isset($_GET['f_status']) ? sanitize_key(wp_unslash($_GET['f_status'])) : '';
        $paged    = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;

        $list = zw_doc_get_numbers(array(
            'year'           => $f_year,
            'type'           => $f_type,
            'status'         => $f_status,
            'exclude_status' => $f_status === '' ? array('void') : array(),
            'page'           => $paged,
            'per_page'       => 30,
        ));

        // TASK-1867（CHG-362）：份号台账数据（与文号台账并列展示；状态口径同名）。
        $copy_status_filter = isset($_GET['c_status']) ? sanitize_key(wp_unslash($_GET['c_status'])) : '';
        $copy_paged         = isset($_GET['c_paged']) ? max(1, (int) $_GET['c_paged']) : 1;
        $copy_list          = function_exists('zw_doc_copy_number_list')
            ? zw_doc_copy_number_list(array(
                'status'         => $copy_status_filter,
                'exclude_status' => $copy_status_filter === '' ? array('void') : array(),
                'page'           => $copy_paged,
                'per_page'       => 20,
            ))
            : array('items' => array(), 'total' => 0, 'page' => 1, 'pages' => 0);
        $copy_next_free     = function_exists('zw_doc_copy_number_next_free')
            ? (string) zw_doc_copy_number_next_free()
            : '';
        ?>
        <div class="wrap zw-doc-numbers">
            <?php echo zw_doc_admin_ui_style(); // 唯一出处：SPEC-10 §1.9 卡片范式（TASK-778） ?>
            <style>
            .zw-doc-numbers .zw-doc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;align-items:start}
            .zw-doc-numbers .zw-adm-sec:first-child{margin-top:0}
            </style>
            <h1>文号管理</h1>
            <p class="zw-doc-muted">本页负责取号 / 预留 / 作废与台账查询；起始序号固定从 1 开始，默认 3 位不补零。</p>
            <p class="zw-doc-muted">文号规则（前缀 / 年份 / 位数）与文种已**统一收敛**到 <a href="<?php echo esc_url(zw_doc_dict_admin_url()); ?>">发文管理 → 数据字典</a>（首行「文号基本参数」+「文种字典」）：那边有「任」字保护与默认文种二次确认，本页只读回显、不再接收保存。</p>
            <?php if ($notice !== '') { zw_doc_admin_notice($notice, stripos($notice, '失败') !== false || stripos($notice, '不能') !== false ? 'error' : 'success'); } ?>

            <div class="zw-doc-grid">
                <?php
                // TASK-2165（S1，用户拍板）：本页「文号规则」改为**只读回显**，唯一可写面是
                // 「数据字典 → 首行 文号基本参数 / 文种字典」。回显值全部取自同一读取入口
                // zw_doc_number_settings()，格式预览复用唯一格式化函数 zw_doc_format_number()（不另造格式）。
                $zw_num_preview_type = isset($settings['doc_types'][0]) ? (string) $settings['doc_types'][0] : '函';
                ?>
                <div class="zw-doc-card" id="zw-doc-number-rule-readonly">
                    <h2>文号规则（只读）</h2>
                    <p class="zw-doc-muted">
                        文号规则与文种只在
                        <a href="<?php echo esc_url(zw_doc_dict_admin_url()); ?>">发文管理 → 数据字典</a>
                        维护（那边有「任」字保护与「默认文种」二次确认）；本页只读回显，<strong>不再提供修改入口</strong>。
                    </p>
                    <div class="zw-doc-field">
                        <label>机关前缀</label>
                        <input type="text" value="<?php echo esc_attr($settings['prefix']); ?>" readonly>
                        <div class="zw-doc-muted">按用户确认固定为“中传”，不可配置。</div>
                    </div>
                    <div class="zw-doc-field">
                        <label>文种（<?php echo (int) count($settings['doc_types']); ?> 项）</label>
                        <input type="text" value="<?php echo esc_attr(implode('，', $settings['doc_types'])); ?>" readonly>
                        <div class="zw-doc-muted">增删与就地改名请到「数据字典 → 文种字典」；此处只回显当前字典。</div>
                    </div>
                    <div class="zw-doc-field">
                        <label>默认年份 / 序号位数</label>
                        <input type="text" value="<?php echo esc_attr((string) (int) $settings['default_year'] . ' 年 / ' . (int) $settings['digits'] . ' 位'); ?>" readonly>
                    </div>
                    <div class="zw-doc-field">
                        <label>整体格式预览</label>
                        <div class="zw-doc-preview" id="zw-doc-format-preview"><?php echo esc_html(zw_doc_format_number($settings, $zw_num_preview_type, (int) $settings['default_year'], 1)); ?></div>
                        <div class="zw-doc-muted">按当前文种字典**首项**与默认年份生成；起始序号固定从 1 开始，不补零。</div>
                    </div>
                    <p><a class="button" href="<?php echo esc_url(zw_doc_dict_admin_url()); ?>">去「数据字典」修改文号规则与文种</a></p>
                </div>

                <div class="zw-doc-card">
                    <h2>新增预留文号</h2>
                    <form method="post">
                        <?php wp_nonce_field('zw_doc_reserve_number'); ?>
                        <input type="hidden" name="zw_doc_action" value="reserve_number">
                        <div class="zw-doc-field">
                            <label>年份</label>
                            <select name="reserve_year">
                                <?php foreach (zw_doc_year_options() as $year) : ?>
                                    <option value="<?php echo esc_attr((string) $year); ?>"<?php selected((int) $settings['default_year'], (int) $year); ?>><?php echo esc_html((string) $year); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="zw-doc-field">
                            <label>文种</label>
                            <select name="reserve_type">
                                <?php foreach ($settings['doc_types'] as $type) : ?>
                                    <option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="zw-doc-field">
                            <label>预留序号（支持单个或范围）</label>
                            <input type="text" name="reserve_range" value="" placeholder="如：33 或 1-10 或 1-3,5,8-10" style="width:100%" required>
                            <div class="zw-doc-muted">支持单个、连续范围、多个范围组合；范围冲突时整体不预留。</div>
                        </div>
                        <div class="zw-doc-field">
                            <label>用途备注</label>
                            <input type="text" name="reserve_note" maxlength="255" style="width:100%">
                        </div>
                        <p><button class="button" type="submit">创建预留</button></p>
                    </form>
                </div>
            </div>

            <h2>操作记录</h2>
            <p class="zw-doc-muted" style="margin:4px 0 8px">默认隐藏已作废记录；选择状态“已作废”可查看并保留审计。</p>
            <form method="get" style="margin:10px 0">
                <input type="hidden" name="page" value="zw-doc-numbers">
                <select name="f_year">
                    <option value="0">全部年份</option>
                    <?php foreach (zw_doc_year_options() as $year) : ?>
                        <option value="<?php echo esc_attr((string) $year); ?>"<?php selected($f_year, (int) $year); ?>><?php echo esc_html((string) $year); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="f_type">
                    <option value="">全部文种</option>
                    <?php foreach ($settings['doc_types'] as $type) : ?>
                        <option value="<?php echo esc_attr($type); ?>"<?php selected($f_type, $type); ?>><?php echo esc_html($type); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="f_status">
                    <option value="">全部状态</option>
                    <?php foreach (array('reserved', 'claimed', 'used', 'skipped', 'void') as $status) : ?>
                        <option value="<?php echo esc_attr($status); ?>"<?php selected($f_status, $status); ?>><?php echo esc_html(zw_doc_number_status_label($status)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button" type="submit">筛选</button>
            </form>

            <?php /* TASK-1586（P1-1c）/ SPEC-10 §1.9.2 第 5 条：8 列宽表（≈760px）必须落在
                 canonical 横滑包裹内。该类由本页 :114 的 zw_doc_admin_ui_style() 提供
                 （唯一出处＝inc/admin/admin-ui-tokens.php 的 zw_adm_ui_table_wrap_css()）。 */ ?>
            <div class="zw-adm-table-wrap">
            <table class="widefat striped">
                <thead>
                <tr>
                    <th style="width:180px">文号</th>
                    <th style="width:70px">年份</th>
                    <th style="width:80px">文种</th>
                    <th style="width:70px">序号</th>
                    <th style="width:90px">状态</th>
                    <th>关联发文 / 备注</th>
                    <th style="width:150px">操作信息</th>
                    <th style="width:120px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($list['items'])) : ?>
                    <tr><td colspan="8">暂无操作记录。</td></tr>
                <?php else : ?>
                    <?php foreach ($list['items'] as $row) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($row['full_number']); ?></strong></td>
                            <td><?php echo esc_html((string) $row['doc_year']); ?></td>
                            <td><?php echo esc_html($row['doc_type']); ?></td>
                            <td><?php echo esc_html((string) $row['seq']); ?></td>
                            <td><span class="zw-doc-status"><?php echo esc_html(zw_doc_number_status_label($row['status'])); ?></span></td>
                            <td>
                                <?php if ($row['document_id'] > 0) : ?>
                                    <a href="<?php echo esc_url(add_query_arg(array('page' => 'zw-doc', 'view' => 'detail', 'doc_id' => $row['document_id']), admin_url('admin.php'))); ?>">#<?php echo (int) $row['document_id']; ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($row['reserved_note'] !== '' ? $row['reserved_note'] : ($row['reason'] !== '' ? $row['reason'] : '—')); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html(zw_doc_user_label($row['operator_id'])); ?><br>
                                <span class="zw-doc-muted"><?php echo esc_html($row['updated_at']); ?></span>
                            </td>
                            <td>
                                <?php if ($row['status'] === 'reserved') : ?>
                                    <form method="post" onsubmit="return confirm('确认作废该预留文号？');" style="display:inline">
                                        <?php wp_nonce_field('zw_doc_void_number'); ?>
                                        <input type="hidden" name="zw_doc_action" value="void_number">
                                        <input type="hidden" name="number_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="void_reason" value="后台手工作废">
                                        <button class="button button-small" type="submit">作废</button>
                                    </form>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div><?php /* /TASK-1586 横滑包裹 */ ?>

            <?php
            if ($list['pages'] > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post(paginate_links(array(
                    'base'      => add_query_arg('paged', '%#%'),
                    'format'    => '',
                    'prev_text' => '«',
                    'next_text' => '»',
                    'total'     => (int) $list['pages'],
                    'current'   => (int) $list['page'],
                )));
                echo '</div></div>';
            }
            ?>

            <?php
            // ── TASK-1867（CHG-362）：份号台账 ─────────────────────────────
            // 与文号台账并列：份号＝**一件公文一个号**（全局唯一、6 位、从 000001 起，不按年重置）。
            // 预留 / 跳过 = 占用但不下发；作废 = 号回到可用池。取号入口在「新建发文 → 第 1 步」。
            ?>
            <h2 style="margin-top:28px">份号台账 <span class="zw-doc-muted" style="font-weight:400">（自动取号 / 手动指定 / 不标注 三种方式在「新建发文」第 1 步选择）</span></h2>
            <p class="zw-doc-muted">下一可用份号：<code><?php echo esc_html($copy_next_free !== '' ? $copy_next_free : '—（6 位号段已用尽）'); ?></code>。份号会读取存量公文里的既有值，故不回填也不会发出重号。</p>

            <div class="zw-doc-grid">
                <div class="zw-doc-card">
                    <h2>预留份号</h2>
                    <form method="post">
                        <?php wp_nonce_field('zw_doc_reserve_copy'); ?>
                        <input type="hidden" name="zw_doc_action" value="reserve_copy">
                        <div class="zw-doc-field">
                            <label>份号（支持单个或范围）</label>
                            <input type="text" name="copy_spec" value="" placeholder="如：000012 或 000001-000010 或 1-3,5" style="width:100%" required>
                            <div class="zw-doc-muted">支持单个、连续范围、多段组合；单次不超过 200 个。</div>
                        </div>
                        <div class="zw-doc-field">
                            <label>用途备注</label>
                            <input type="text" name="copy_note" maxlength="255" style="width:100%">
                        </div>
                        <p><button class="button" type="submit">创建预留</button></p>
                    </form>
                </div>

                <div class="zw-doc-card">
                    <h2>跳过份号</h2>
                    <form method="post">
                        <?php wp_nonce_field('zw_doc_skip_copy'); ?>
                        <input type="hidden" name="zw_doc_action" value="skip_copy">
                        <div class="zw-doc-field">
                            <label>份号（支持单个或范围）</label>
                            <input type="text" name="copy_spec" value="" placeholder="如：000013" style="width:100%" required>
                            <div class="zw-doc-muted">跳过 = 该号永不再用（占用并保留审计），与「作废」不同。</div>
                        </div>
                        <div class="zw-doc-field">
                            <label>跳过原因</label>
                            <input type="text" name="copy_reason" maxlength="255" style="width:100%">
                        </div>
                        <p><button class="button" type="submit">标记跳过</button></p>
                    </form>
                </div>

                <div class="zw-doc-card">
                    <h2>从存量公文回填</h2>
                    <p class="zw-doc-muted">把已保存发文的 <code>body.meta.copy_no</code> 登记进台账（幂等、只补不覆盖；重号会逐条报冲突，不静默改数据）。</p>
                    <form method="post">
                        <?php wp_nonce_field('zw_doc_backfill_copy'); ?>
                        <input type="hidden" name="zw_doc_action" value="backfill_copy">
                        <p><button class="button" type="submit">回填存量份号</button></p>
                    </form>
                </div>
            </div>

            <h2>份号记录</h2>
            <form method="get" style="margin:10px 0">
                <input type="hidden" name="page" value="zw-doc-numbers">
                <select name="c_status">
                    <option value="">全部状态</option>
                    <?php foreach (array('reserved', 'used', 'skipped', 'void') as $copy_status) : ?>
                        <option value="<?php echo esc_attr($copy_status); ?>"<?php selected($copy_status_filter, $copy_status); ?>><?php echo esc_html(zw_doc_copy_number_status_label($copy_status)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button" type="submit">筛选份号</button>
            </form>

            <div class="zw-adm-table-wrap">
            <table class="widefat">
                <thead>
                <tr>
                    <th style="width:110px">份号</th>
                    <th style="width:90px">状态</th>
                    <th>关联发文 / 备注</th>
                    <th style="width:150px">登记人 / 时间</th>
                    <th style="width:120px">操作</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($copy_list['items'])) : ?>
                    <tr><td colspan="5">暂无份号记录。</td></tr>
                <?php else : ?>
                    <?php foreach ($copy_list['items'] as $copy_row) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($copy_row['copy_no']); ?></strong></td>
                            <td><span class="zw-doc-status"><?php echo esc_html(zw_doc_copy_number_status_label($copy_row['status'])); ?></span></td>
                            <td>
                                <?php if ($copy_row['document_id'] > 0) : ?>
                                    <a href="<?php echo esc_url(add_query_arg(array('page' => 'zw-doc', 'view' => 'detail', 'doc_id' => $copy_row['document_id']), admin_url('admin.php'))); ?>">#<?php echo (int) $copy_row['document_id']; ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($copy_row['reserved_note'] !== '' ? $copy_row['reserved_note'] : ($copy_row['reason'] !== '' ? $copy_row['reason'] : '—')); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html(zw_doc_user_label($copy_row['operator_id'])); ?><br>
                                <span class="zw-doc-muted"><?php echo esc_html($copy_row['updated_at']); ?></span>
                            </td>
                            <td>
                                <?php if (in_array($copy_row['status'], array('reserved', 'skipped'), true)) : ?>
                                    <form method="post" onsubmit="return confirm('确认作废该份号记录？号会回到可用池。');" style="display:inline">
                                        <?php wp_nonce_field('zw_doc_void_copy'); ?>
                                        <input type="hidden" name="zw_doc_action" value="void_copy">
                                        <input type="hidden" name="copy_id" value="<?php echo (int) $copy_row['id']; ?>">
                                        <input type="hidden" name="copy_void_reason" value="后台手工作废">
                                        <button class="button button-small" type="submit">作废</button>
                                    </form>
                                <?php else : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>

            <?php
            if ($copy_list['pages'] > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post(paginate_links(array(
                    'base'      => add_query_arg('c_paged', '%#%'),
                    'format'    => '',
                    'prev_text' => '«',
                    'next_text' => '»',
                    'total'     => (int) $copy_list['pages'],
                    'current'   => (int) $copy_list['page'],
                )));
                echo '</div></div>';
            }
            ?>
            <?php
            // TASK-2165（S1）：实时预览脚本随「文号规则」可编辑表单一并下线 —— 本页已无文种/年份/位数输入，
            // 预览改由服务端用唯一格式化函数 zw_doc_format_number() 渲染（见上方只读卡）。
            ?>
        </div>
        <?php
    }
}
