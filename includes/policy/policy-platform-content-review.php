<?php
/**
 * AI法务部 · 内容审核可视化台账（TASK-540）
 *
 * 页面提供：
 * - 接入流程说明；
 * - 分支机构对外内容审核列表（待AI/待人工/通过/不通过/异常）；
 * - AI与人工审核日志；
 * - 人工审核通过/驳回动作。
 */
if (!defined('ABSPATH')) { exit; }

if (!function_exists('zw_policy_platform_content_review_items')) {
function zw_policy_platform_content_review_items() {
    $posts = get_posts(array(
        'post_type'      => 'post',
        'post_status'    => array('publish', 'draft', 'pending', 'private'),
        'category_name'  => 'fzjg',
        'posts_per_page' => 300,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ));
    $out = array();
    foreach ((array) $posts as $post) {
        $bid = (int) $post->ID;
        $status = function_exists('zw_branch_content_review_status') ? zw_branch_content_review_status($bid) : 'legacy';
        $result = function_exists('zw_branch_content_review_result') ? zw_branch_content_review_result($bid) : array();
        $out[] = array(
            'id'            => $bid,
            'title'         => (string) $post->post_title,
            'status'        => $status,
            'status_label'  => function_exists('zw_branch_content_review_status_label') ? zw_branch_content_review_status_label($status, $bid) : $status,
            'submitted_at'  => (string) get_post_meta($bid, '_zw_branch_content_review_submitted_at', true),
            'submitted_by'  => (int) get_post_meta($bid, '_zw_branch_content_review_submitted_by', true),
            'reviewer_name' => (string) get_post_meta($bid, '_zw_branch_content_review_reviewer_name', true),
            'reviewed_at'   => (string) get_post_meta($bid, '_zw_branch_content_review_reviewed_at', true),
            'policy_version'=> (string) get_post_meta($bid, '_zw_branch_content_review_policy_version', true),
            'policy_basis'  => isset($result['policy_basis']) && is_array($result['policy_basis']) ? $result['policy_basis'] : array(),
            'summary'       => (string) ($result['summary'] ?? ''),
            'issues'        => isset($result['issues']) && is_array($result['issues']) ? $result['issues'] : array(),
            'source'        => (string) get_post_meta($bid, '_zw_branch_content_review_source', true),
            'draft'         => function_exists('zw_branch_content_review_draft') ? zw_branch_content_review_draft($bid) : array(),
        );
    }
    return $out;
}
}

if (!function_exists('zw_policy_platform_content_review_url')) {
function zw_policy_platform_content_review_url($args = array()) {
    $base = admin_url('admin.php?page=zw-policy-content-review');
    return empty($args) ? $base : add_query_arg($args, $base);
}
}

if (!function_exists('zw_policy_platform_page_content_review')) {
function zw_policy_platform_page_content_review() {
    if (!current_user_can('manage_options') && !current_user_can('tcpc_perm_legal')) {
        wp_die('无权限');
    }
    $filter_status = isset($_GET['review_status']) ? sanitize_key((string) wp_unslash($_GET['review_status'])) : '';
    $items = zw_policy_platform_content_review_items();
    $counts = array('pending_ai' => 0, 'pending_manual' => 0, 'approved' => 0, 'rejected' => 0, 'error' => 0, 'legacy' => 0);
    foreach ($items as $item) {
        if (isset($counts[$item['status']])) { $counts[$item['status']]++; }
    }
    $rows = array();
    foreach ($items as $item) {
        if ($filter_status !== '' && $item['status'] !== $filter_status) { continue; }
        $rows[] = $item;
    }
    // TASK-2320 增量五：审计流水走跨仓契约门（P-3）；契约缺位回退旧函数，再缺则空。
    $logs = array();
    if (function_exists('zw_oa_branch_audit_records')) {
        $zw_cr_audit = zw_oa_branch_audit_records(0, 100);
        $logs = is_array($zw_cr_audit['rows'] ?? null) ? $zw_cr_audit['rows'] : array();
    } elseif (function_exists('zw_branch_content_review_records')) {
        $logs = zw_branch_content_review_records(0, 100);
    }

    zw_policy_platform_page_header('内容审核', '分支机构对外文字内容的 AI法务初审、人工复核、结果标记与审计日志。');
    ?>
    <div class="zw-adm-card" style="margin:14px 0">
        <div class="zw-adm-card-head"><span class="ct">接入流程</span><span class="desc">保存不直接覆盖线上，审核通过才发布</span></div>
        <div class="zw-adm-card-body" style="font-size:13px;line-height:1.9;color:#50575e">
            1. 分支机构管理员/仪表盘管理员修改对外文字字段 →
            2. 系统保存为候选草稿并写修改审计 →
            3. AI法务初审 →
            4. <strong style="color:#2e7d32">通过则自动发布并记录“AI法务部 + 时间 + 政策版本”</strong>；<strong style="color:#b7791f">不通过/不确定则转人工</strong> →
            5. 人工通过/驳回，全程写入 `zw_mc_audit`。
            <div style="margin-top:6px;color:#999">旧内容审核期间继续公开；AI不通过不更新线上版本。</div>
        </div>
    </div>

    <?php
    // ── TASK-1549：送审依据预览（只读；**不发任何 AI 请求**）—— 把 TASK-1542 的新选取口径变成"看得见"的依据。
    // 唯一口径出处仍是 `zw_branch_content_review_select_clauses()`；本页不新造第二套选取/统计实现。
    if (function_exists('zw_branch_content_review_policy_snapshot') && function_exists('zw_branch_content_review_select_clauses')) :
        $zw_cr_snapshot = zw_branch_content_review_policy_snapshot();
        $zw_cr_clauses  = (array) ($zw_cr_snapshot['clauses'] ?? array());
        $zw_cr_selected = zw_branch_content_review_select_clauses($zw_cr_clauses);
        $zw_cr_total    = (int) ($zw_cr_selected['total'] ?? 0);
        $zw_cr_picked   = (array) ($zw_cr_selected['items'] ?? array());
        $zw_cr_by_src   = array();
        foreach ($zw_cr_picked as $zw_cr_row) {
            if (!is_array($zw_cr_row)) { continue; }
            $zw_cr_sid = (string) ($zw_cr_row['source_id'] ?? '');
            $zw_cr_by_src[$zw_cr_sid] = (int) ($zw_cr_by_src[$zw_cr_sid] ?? 0) + 1;
        }
        $zw_cr_limits = function_exists('zw_branch_content_review_clause_limits')
            ? zw_branch_content_review_clause_limits()
            : array('max_items' => 0, 'max_chars' => 0);
        ?>
        <div class="zw-adm-card" style="margin:14px 0">
            <div class="zw-adm-card-head"><span class="ct">送审依据预览</span><span class="desc">只读；不发任何请求</span></div>
            <div class="zw-adm-card-body" style="font-size:13px;line-height:1.9;color:#50575e">
                已确认条款快照 <strong><?php echo (int) $zw_cr_total; ?></strong> 条 ⇒ 本次送审选取 <strong><?php echo count($zw_cr_picked); ?></strong> 条，
                覆盖 <strong><?php echo count($zw_cr_by_src); ?></strong> 个来源<?php echo !empty($zw_cr_selected['truncated'])
                    ? '（已达选取预算：' . (int) $zw_cr_limits['max_items'] . ' 条 / ' . (int) $zw_cr_limits['max_chars'] . ' 字节）'
                    : '（未截断：全部条款均会送审）'; ?>。
                <div style="margin-top:6px;color:#666">选取口径：按来源逐轮各取 1 条（round-robin）+ 字符预算，任何单一来源都不会吃掉全部预算 —— 见 TASK-1542 / CHG-271。</div>
                <?php if ($zw_cr_by_src) : ?>
                    <div style="margin-top:8px">
                        <?php foreach ($zw_cr_by_src as $zw_cr_sid => $zw_cr_n) : ?>
                            <span class="zw-adm-badge off" style="margin:0 6px 6px 0"><?php echo esc_html($zw_cr_sid !== '' ? $zw_cr_sid : '(无来源)'); ?> · <?php echo (int) $zw_cr_n; ?> 条</span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <details style="margin-top:8px">
                    <summary style="cursor:pointer;color:#666">查看本次送审条款（前 30 条，按选取顺序）</summary>
                    <ol style="margin:8px 0 0 18px;color:#50575e">
                        <?php foreach (array_slice($zw_cr_picked, 0, 30) as $zw_cr_row) : if (!is_array($zw_cr_row)) { continue; } ?>
                            <li><code style="font-size:11px;color:#888"><?php echo esc_html((string) ($zw_cr_row['source_id'] ?? '')); ?></code>
                                <?php echo esc_html(trim((string) ($zw_cr_row['article_no'] ?? '')) !== '' ? (string) $zw_cr_row['article_no'] . ' ' : ''); ?>
                                <?php echo esc_html(function_exists('mb_substr') ? mb_substr((string) ($zw_cr_row['original_text'] ?? ''), 0, 60) : substr((string) ($zw_cr_row['original_text'] ?? ''), 0, 60)); ?></li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            </div>
        </div>
    <?php endif; ?>

    <div class="zw-adm-grid zw-adm-g4" style="margin:16px 0 18px">
        <div class="zw-adm-kpi warn"><div class="l">待AI审核</div><div class="n"><?php echo (int) $counts['pending_ai']; ?></div></div>
        <div class="zw-adm-kpi red"><div class="l">待人工审核</div><div class="n"><?php echo (int) $counts['pending_manual']; ?></div></div>
        <div class="zw-adm-kpi green"><div class="l">已通过</div><div class="n"><?php echo (int) $counts['approved']; ?></div></div>
        <div class="zw-adm-kpi blue"><div class="l">历史未审核</div><div class="n"><?php echo (int) $counts['legacy']; ?></div></div>
    </div>

    <div class="zw-adm-tabbar">
        <a<?php echo $filter_status === '' ? ' class="on"' : ''; ?> href="<?php echo esc_url(zw_policy_platform_content_review_url()); ?>">全部 (<?php echo count($items); ?>)</a>
        <a<?php echo $filter_status === 'pending_ai' ? ' class="on"' : ''; ?> href="<?php echo esc_url(zw_policy_platform_content_review_url(array('review_status' => 'pending_ai'))); ?>">待AI审核 (<?php echo (int) $counts['pending_ai']; ?>)</a>
        <a<?php echo $filter_status === 'pending_manual' ? ' class="on"' : ''; ?> href="<?php echo esc_url(zw_policy_platform_content_review_url(array('review_status' => 'pending_manual'))); ?>">待人工审核 (<?php echo (int) $counts['pending_manual']; ?>)</a>
        <a<?php echo $filter_status === 'approved' ? ' class="on"' : ''; ?> href="<?php echo esc_url(zw_policy_platform_content_review_url(array('review_status' => 'approved'))); ?>">已通过 (<?php echo (int) $counts['approved']; ?>)</a>
        <a<?php echo $filter_status === 'rejected' ? ' class="on"' : ''; ?> href="<?php echo esc_url(zw_policy_platform_content_review_url(array('review_status' => 'rejected'))); ?>">不通过 (<?php echo (int) $counts['rejected']; ?>)</a>
    </div>

    <div class="zw-adm-card" style="overflow-x:auto;margin-bottom:18px">
        <!-- TASK-1633（TASK-1579 P1-6 第四批）：宽表落 canonical 横滑包裹。
             规则由 ai法务部外壳（slug 前缀 zw-policy ⇒ 命中 zw_mc_adm_is_screen()）下沉，本页不新增 <style>。 -->
        <div class="zw-adm-table-wrap">
        <table class="zw-adm-tbl">
            <thead><tr><th>机构</th><th>状态</th><th>提交</th><th>AI/人工结论</th><th>政策版本</th><th>操作</th></tr></thead>
            <tbody>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="6" style="text-align:center;color:#999;padding:28px 0">暂无记录。</td></tr>
            <?php else : foreach ($rows as $row) : ?>
                <tr>
                    <td><strong><?php echo esc_html($row['title']); ?></strong><div style="color:#999;font-size:12px">ID <?php echo (int) $row['id']; ?></div></td>
                    <td><?php echo function_exists('zw_mc_adm_badge') ? zw_mc_adm_badge(function_exists('zw_branch_content_review_status_badge_type') ? zw_branch_content_review_status_badge_type($row['status']) : 'off', $row['status_label']) : esc_html($row['status_label']); ?></td>
                    <td><?php echo esc_html($row['submitted_at'] !== '' ? $row['submitted_at'] : '—'); ?><div style="color:#999;font-size:12px">UID <?php echo (int) $row['submitted_by']; ?></div></td>
                    <td style="max-width:360px">
                        <?php if ($row['summary'] !== '') : ?><div><?php echo esc_html($row['summary']); ?></div><?php endif; ?>
                        <?php if (!empty($row['issues'])) : ?>
                            <ul style="margin:4px 0 0 16px;color:#c62828;font-size:12px">
                                <?php foreach (array_slice((array) $row['issues'], 0, 5) as $issue) : if (!is_array($issue)) { continue; } ?>
                                    <li><?php echo esc_html((string) ($issue['message'] ?? '')); ?><?php echo !empty($issue['suggestion']) ? '（建议：' . esc_html((string) $issue['suggestion']) . '）' : ''; ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($row['reviewer_name'] !== '') : ?><div style="color:#999;font-size:12px">审核人：<?php echo esc_html($row['reviewer_name']); ?> <?php echo esc_html($row['reviewed_at']); ?></div><?php endif; ?>
                    </td>
                    <td><?php echo esc_html($row['policy_version'] !== '' ? substr($row['policy_version'], 0, 12) : '—'); ?>
                        <?php
                        // TASK-1551：本次审核依据的条款选取口径（旧记录无该键 → 显示"依据未记录"，不报错）。
                        $zw_cr_basis = (array) ($row['policy_basis'] ?? array());
                        if (!empty($zw_cr_basis)) {
                            echo '<div style="color:#999;font-size:11px">依据：选取 ' . (int) ($zw_cr_basis['selected'] ?? 0)
                                . ' / 共 ' . (int) ($zw_cr_basis['total'] ?? 0) . ' 条 · '
                                . (int) ($zw_cr_basis['sources'] ?? 0) . ' 个来源'
                                . (!empty($zw_cr_basis['truncated']) ? '（截断）' : '') . '</div>';
                        } elseif ((string) $row['status'] !== 'legacy') {
                            echo '<div style="color:#bbb;font-size:11px">依据未记录</div>';
                        }
                        ?>
                    </td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=zw-mc-branches&branch=' . (int) $row['id'] . '#content-review')); ?>">查看机构</a>
                        <?php if (in_array($row['status'], array('pending_manual', 'error', 'rejected'), true) && !empty($row['draft'])) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-top:6px;min-width:260px;vertical-align:top">
                                <input type="hidden" name="action" value="zw_branch_content_review_manual">
                                <input type="hidden" name="branch_id" value="<?php echo (int) $row['id']; ?>">
                                <?php wp_nonce_field('zw_branch_content_review_manual_' . (int) $row['id']); ?>
                                <textarea name="review_note" rows="2" style="width:100%;font-size:12px;margin-bottom:4px" placeholder="审核意见（驳回必填）"></textarea>
                                <button class="button button-primary button-small" type="submit" name="decision" value="approve" onclick="return confirm('确认人工审核通过？通过后将更新线上内容。');">人工通过</button>
                                <button class="button button-small button-link-delete" type="submit" name="decision" value="reject" onclick="return confirm('确认人工驳回？线上继续展示旧版本。');">驳回</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="zw-adm-sec" style="margin-top:22px">审核日志</div>
    <div class="zw-adm-card" style="overflow-x:auto">
        <div class="zw-adm-table-wrap">
        <table class="zw-adm-tbl">
            <thead><tr><th>时间</th><th>动作</th><th>机构</th><th>操作/审核人</th><th>说明</th></tr></thead>
            <tbody>
            <?php if (empty($logs)) : ?>
                <tr><td colspan="5" style="text-align:center;color:#999;padding:24px 0">暂无审核日志。</td></tr>
            <?php else : foreach ($logs as $log) : ?>
                <tr>
                    <td><?php echo esc_html((string) ($log['created_at'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($log['action'] ?? '')); ?></td>
                    <td>#<?php echo esc_html((string) ($log['object_id'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($log['operator_name'] ?? '')); ?></td>
                    <td style="max-width:520px"><?php echo esc_html((string) ($log['reason'] ?? '')); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
    zw_policy_platform_page_footer();
}
}

if (!function_exists('zw_policy_platform_handle_branch_content_review_manual')) {
function zw_policy_platform_handle_branch_content_review_manual() {
    if (!current_user_can('manage_options') && !current_user_can('tcpc_perm_legal')) {
        wp_die('无权限');
    }
    $branch_id = isset($_POST['branch_id']) ? absint($_POST['branch_id']) : 0;
    if ($branch_id <= 0) { wp_die('参数错误'); }
    check_admin_referer('zw_branch_content_review_manual_' . $branch_id);
    $decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
    $note     = isset($_POST['review_note']) ? sanitize_textarea_field(wp_unslash($_POST['review_note'])) : '';
    if ($decision === 'reject' && $note === '') {
        wp_safe_redirect(add_query_arg(array('page' => 'zw-policy-content-review', 'msg' => 'reject_note_required'), admin_url('admin.php')));
        exit;
    }
    // TASK-2320 增量五：人工决策走跨仓契约门（W-1 · **唯一写入口**）；契约缺位回退旧函数。
    if (function_exists('zw_oa_branch_review_decide')) {
        $result = zw_oa_branch_review_decide($branch_id, $decision, $note, get_current_user_id());
    } elseif (function_exists('zw_branch_content_review_manual_decision')) {
        $result = zw_branch_content_review_manual_decision($branch_id, $decision, $note, get_current_user_id());
    } else {
        wp_die('审核模块未加载');
    }
    if (function_exists('is_wp_error') && is_wp_error($result)) {
        wp_safe_redirect(add_query_arg(array('page' => 'zw-policy-content-review', 'msg' => 'error'), admin_url('admin.php')));
        exit;
    }
    wp_safe_redirect(add_query_arg(array('page' => 'zw-policy-content-review', 'msg' => $decision === 'approve' ? 'approved' : 'rejected'), admin_url('admin.php')));
    exit;
}
}

if (function_exists('add_action')) {
    add_action('admin_post_zw_branch_content_review_manual', 'zw_policy_platform_handle_branch_content_review_manual');
}
