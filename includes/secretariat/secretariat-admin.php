<?php
/**
 * AI 秘书处 · 后台页面（TASK-1352 · 阶段一）。
 *
 * 视图（slug 前缀 `zw-secretariat`）：
 *   - `zw-secretariat`         任务台账（列表）
 *   - `zw-secretariat-create`  新建任务（登记）
 *   - `zw-secretariat-task`    任务详情（步骤一~四 文本 + 交付物 + 里程碑 + 影像留存清单）
 *   - `zw-secretariat-gates`   人审门（门①②③ 确认 / 退回 / 门③ 发布·存档命令）
 *   - `zw-secretariat-qa`      质量校验报告（8 项逐项结论）
 *
 * 安全（SPEC-42）：
 *   - 每个页面 `zw_secretariat_can()` 能力校验；无权限 `wp_die(403)`；
 *   - 所有写操作走 `admin_post_*` + `check_admin_referer` + 能力校验；
 *   - 输出全部 `esc_html` / `esc_attr` / `esc_url`；
 *   - 页面专属样式写模板尾部内联 `<style>`（**不新增全局 CSS 文件**）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

// OA-2491：本页输出自有宽表横滑/触屏块；单测直接 require 本文件时也要保证助手在场。
if (!function_exists('tco_ui_table_wrap_css')) {
    require_once dirname(__DIR__) . '/ui/ui-tokens.php';
}

if (!function_exists('zw_secretariat_die_forbidden')) {
    /**
     * 统一 403（不泄露内部信息）。
     *
     * @return void
     */
    function zw_secretariat_die_forbidden() {
        wp_die('没有权限访问 AI 秘书处。', '权限不足', array('response' => 403));
    }
}

if (!function_exists('zw_secretariat_admin_url')) {
    /**
     * 页面地址。
     *
     * @param string $slug 页面 slug。
     * @param array  $args 查询参数。
     * @return string
     */
    function zw_secretariat_admin_url($slug, $args = array()) {
        $url = admin_url('admin.php?page=' . $slug);
        if ($args) {
            $url = add_query_arg($args, $url);
        }
        return $url;
    }
}

if (!function_exists('zw_secretariat_style_once')) {
    /**
     * 页面专属样式（内联，只输出一次）。
     *
     * @return void
     */
    function zw_secretariat_style_once() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        echo '<style>
/* TASK-1584 / SPEC-10 §1.9:166 与 §1.9.2 第 7 条：页面容器禁设 max-width（100% 宽度口径）。
   原为 max-width:1200px（5 个秘书处页面共用）；改为 none 以显式清除。 */
/* TASK-1645（TASK-1579 P2-1 批次 2 / SPEC-10 §1.9.3 第 2/3 项；机制出处 CHG-300）：
   下列旧类为**兼容别名** —— canonical 规则的选择器组**同时承载**新类与旧类，
   旧类的历史声明**原位保留**（同一行、声明体逐字节未改 ⇒ 零取值变化）；
   标记面同时携带新类与旧类（双写）。**双写一个发布周期内不得删除任何旧类**；
   阶段 ②（切换）与阶段 ③（清理）另按 §1.9.3 第 2 项的阶段门执行。
   [已废弃 → 见 .tco-sec-wrap] 旧类 .tco-sec-wrap
   [已废弃 → 见 .tco-sec-cards] 旧类 .tco-sec-cards
   [已废弃 → 见 .tco-sec-card] 旧类 .tco-sec-card
   [已废弃 → 见 .tco-sec-muted] 旧类 .tco-sec-muted
   [已废弃 → 见 .tco-sec-table] 旧类 .tco-sec-table
   [已废弃 → 见 .tco-sec-blockers] 旧类 .tco-sec-blockers
   [已废弃 → 见 .tco-sec-ok] 旧类 .tco-sec-ok
   [已废弃 → 见 .tco-sec-steps] 旧类 .tco-sec-steps
   [已废弃 → 见 .tco-sec-step] 旧类 .tco-sec-step
   `.tco-sec-badge` 属收敛映射第 1 类（语义对应 canonical `.tco-badge`，取值统一会改变视觉）
   ⇒ 按 SPEC-10 §1.9.3 第 5 项**须走 UI 走查并另立 CHG**，本批**不做**：旧声明与旧标记**原样保留**。 */
.tco-sec-wrap,.tco-sec-wrap{max-width:none}
.tco-sec-cards,.tco-sec-cards{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0}
.tco-sec-card,.tco-sec-card{flex:1 1 260px;border:1px solid #dcdcde;border-left:4px solid var(--primary-color,#d80505);background:#fff;padding:10px 12px;border-radius:3px}
.tco-sec-card h3,.tco-sec-card h3{margin:0 0 6px;font-size:14px}
.tco-sec-muted,.tco-sec-muted{color:#646970}
.tco-sec-badge{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;border:1px solid #c3c4c7;background:#f6f7f7;margin-right:4px}
.tco-sec-badge.is-open{border-color:#dba617;background:#fcf9e8;color:#8a6116}
.tco-sec-badge.is-ok{border-color:#00a32a;background:#f0f9f2;color:#007017}
.tco-sec-badge.is-bad{border-color:#d63638;background:#fdf0f0;color:#8a2424}
.tco-sec-table td,.tco-sec-table th,.tco-sec-table td,.tco-sec-table th{vertical-align:top}
.tco-sec-blockers,.tco-sec-blockers{margin:8px 0;padding:8px 12px;border-left:4px solid #d63638;background:#fdf0f0}
.tco-sec-ok,.tco-sec-ok{margin:8px 0;padding:8px 12px;border-left:4px solid #00a32a;background:#f0f9f2}
.tco-sec-steps,.tco-sec-steps{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}
.tco-sec-step,.tco-sec-step{flex:1 1 180px;border:1px solid #dcdcde;padding:8px;background:#fff;border-radius:3px}
.tco-sec-step.is-done,.tco-sec-step.is-done{border-color:#00a32a;background:#f0f9f2}
';
        /* TASK-1627（TASK-1579 P1-6）：本域 5 张表落 canonical 横滑包裹 ——
           规则唯一出处 tco_ui_table_wrap_css()（不复制第二套声明）。 */
        echo tco_ui_table_wrap_css();
        /* TASK-1637（P1-4 余项 / 用户拍板②）：AI 秘书处不在会员域外壳作用域内 ⇒ 同批透传
           canonical 触屏块（唯一出处 tco_ui_touch_css()，`body` 作用域；仅 `@media(pointer:coarse)`
           与 `:active` 生效 ⇒ 桌面零视觉变更）。 */
        echo function_exists('tco_ui_touch_css') ? tco_ui_touch_css('body') : '';
        echo '
</style>';
    }
}

/* ─────────────────────────────────────────────── 台账（列表） */
if (!function_exists('zw_secretariat_page_ledger')) {
    /**
     * 任务台账。
     *
     * @return void
     */
    function zw_secretariat_page_ledger() {
        if (!zw_secretariat_can()) {
            zw_secretariat_die_forbidden();
        }
        zw_secretariat_style_once();

        $status   = isset($_GET['st']) ? sanitize_key(wp_unslash((string) $_GET['st'])) : '';
        $scenario = isset($_GET['sc']) ? sanitize_key(wp_unslash((string) $_GET['sc'])) : '';
        $result   = zw_secretariat_query_tasks(array(
            'status'   => $status,
            'scenario' => $scenario,
            'per_page' => 50,
        ));

        echo '<div class="wrap tco-sec-wrap tco-sec-wrap">';
        echo '<h1>AI 秘书处 · 任务台账</h1>';
        echo '<p class="tco-sec-muted tco-sec-muted">四步流程：通读依从 → 编制纲要 → 撰写素材 → 成稿与质量校验；每步经人审门，'
            . '「发布 / 存档」为人工命令，AI 不代行。</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(zw_secretariat_admin_url('zw-secretariat-create')) . '">新建任务</a></p>';

        // 场景筛选。
        echo '<form method="get" style="margin:8px 0">';
        echo '<input type="hidden" name="page" value="zw-secretariat" />';
        echo '<select name="sc"><option value="">全部场景</option>';
        foreach (zw_secretariat_scenarios() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($scenario, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> <select name="st"><option value="">全部状态</option>';
        foreach (zw_secretariat_statuses() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($status, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> <button class="button">筛选</button></form>';

        if (!$result['rows']) {
            echo '<p>暂无任务。</p></div>';
            return;
        }

        echo '<div class="tco-table-wrap">';
        echo '<table class="widefat striped tco-sec-table tco-sec-table"><thead><tr>'
            . '<th>任务</th><th>场景</th><th>周期</th><th>状态</th><th>人审门</th><th>质检</th><th>操作</th>'
            . '</tr></thead><tbody>';
        foreach ($result['rows'] as $row) {
            $detail_url = zw_secretariat_admin_url('zw-secretariat-task', array('task' => $row['id']));
            $gates_url  = zw_secretariat_admin_url('zw-secretariat-gates', array('task' => $row['id']));
            $qa_url     = zw_secretariat_admin_url('zw-secretariat-qa', array('task' => $row['id']));
            echo '<tr>';
            echo '<td><strong>' . esc_html($row['title']) . '</strong><br /><span class="tco-sec-muted tco-sec-muted">#' . (int) $row['id']
                . ' · 交付物 ' . (int) count($row['deliverables']) . ' · 来源材料 ' . (int) $row['source_count'] . '</span></td>';
            echo '<td>' . esc_html($row['scenario_label']) . '</td>';
            echo '<td>' . esc_html($row['cycle_label']) . '</td>';
            echo '<td>' . esc_html($row['status_label']) . '</td>';
            echo '<td>' . ($row['gate_done'] ? '<span class="tco-sec-badge is-ok">三门齐备</span>' : '<span class="tco-sec-badge is-open">未齐备</span>') . '</td>';
            $qa = $row['qa'];
            $qa_badge = ((int) $qa['pending'] === 0 && (int) $qa['fail'] === 0)
                ? '<span class="tco-sec-badge is-ok">8/8 通过</span>'
                : '<span class="tco-sec-badge is-open">' . (int) $qa['pass'] . '/' . (int) $qa['total'] . ' 通过</span>';
            echo '<td>' . $qa_badge . '</td>';
            echo '<td><a href="' . esc_url($detail_url) . '">详情</a> · <a href="' . esc_url($gates_url) . '">人审门</a> · <a href="' . esc_url($qa_url) . '">质检</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }
}

/* ─────────────────────────────────────────────── 新建任务 */
if (!function_exists('zw_secretariat_page_create')) {
    /**
     * 新建任务（登记：场景 / 周期 / 交付物 / 来源材料）。
     *
     * @return void
     */
    function zw_secretariat_page_create() {
        if (!zw_secretariat_can()) {
            zw_secretariat_die_forbidden();
        }
        zw_secretariat_style_once();

        $notice = isset($_GET['zw_sec_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['zw_sec_notice'])) : '';

        echo '<div class="wrap tco-sec-wrap tco-sec-wrap">';
        echo '<h1>新建秘书处任务</h1>';
        if ($notice !== '') {
            echo '<div class="notice notice-error"><p>' . esc_html($notice) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="zw_secretariat_create_task" />';
        wp_nonce_field('zw_secretariat_create_task');
        echo '<div class="tco-table-wrap">';
        echo '<table class="form-table tco-sec-table tco-sec-table"><tbody>';
        echo '<tr><th scope="row"><label for="tco-sec-title">任务标题</label></th>'
            . '<td><input class="regular-text" id="tco-sec-title" name="title" required maxlength="200" /></td></tr>';
        echo '<tr><th scope="row"><label for="tco-sec-scenario">业务场景</label></th><td><select id="tco-sec-scenario" name="scenario">';
        foreach (zw_secretariat_scenarios() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">阶段一首发场景为「协会理事会 / 常务理事会」。</p></td></tr>';
        echo '<tr><th scope="row"><label for="tco-sec-cycle">任务周期</label></th><td><select id="tco-sec-cycle" name="cycle">';
        foreach (zw_secretariat_cycles() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row">交付物</th><td>';
        foreach (zw_secretariat_deliverables() as $scenario => $list) {
            echo '<fieldset style="margin:0 0 8px"><legend><strong>' . esc_html(zw_secretariat_scenario_label($scenario)) . '</strong></legend>';
            foreach ($list as $key => $label) {
                echo '<label class="tco-touch" style="margin-right:14px"><input type="checkbox" name="deliverables[]" value="' . esc_attr($key) . '" /> '
                    . esc_html($label) . '</label>';
            }
            echo '</fieldset>';
        }
        echo '<p class="description">只登记所选场景清单内的交付物；跨场景勾选会被忽略。</p></td></tr>';
        echo '<tr><th scope="row"><label for="tco-sec-sources">来源材料</label></th>'
            . '<td><textarea id="tco-sec-sources" name="source_ids" rows="3" class="large-text" placeholder="每行一个标识：收文编号 / 政策源 id / 附件 id"></textarea>'
            . '<p class="description">只登记**引用标识**，不复制正文；正文仍由收文管理与 AI 法务部持有。</p></td></tr>';
        echo '</tbody></table>';
        echo '</div>';
        submit_button('登记任务');
        echo '</form></div>';
    }
}

/* ─────────────────────────────────────────────── 任务详情（四步） */
if (!function_exists('zw_secretariat_page_task')) {
    /**
     * 任务详情：四步文本 + 交付物 + 里程碑 + 影像留存清单。
     *
     * @return void
     */
    function zw_secretariat_page_task() {
        if (!zw_secretariat_can()) {
            zw_secretariat_die_forbidden();
        }
        zw_secretariat_style_once();

        $task_id = isset($_GET['task']) ? (int) $_GET['task'] : 0;
        if ($task_id <= 0) {
            echo '<div class="wrap"><h1>任务详情</h1><p>缺少任务编号。</p></div>';
            return;
        }
        $task   = zw_secretariat_task_detail($task_id);
        $next   = zw_secretariat_next_action($task);
        $locked = in_array($task['status'], array('published', 'archived'), true);
        $notice = isset($_GET['zw_sec_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['zw_sec_notice'])) : '';

        echo '<div class="wrap tco-sec-wrap tco-sec-wrap">';
        echo '<h1>' . esc_html($task['title']) . '</h1>';
        echo '<p class="tco-sec-muted tco-sec-muted">#' . (int) $task['id'] . ' · ' . esc_html($task['scenario_label'])
            . ' · ' . esc_html($task['cycle_label']) . ' · 状态：<strong>' . esc_html($task['status_label']) . '</strong></p>';
        if ($notice !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
        }
        echo '<p class="tco-sec-muted tco-sec-muted">下一步：<strong>' . esc_html($next['label']) . '</strong> —— ' . esc_html($next['hint']) . '</p>';

        // 四步进度条。
        echo '<div class="tco-sec-steps tco-sec-steps">';
        $steps = array(
            array('label' => '步骤一 通读依从', 'done' => trim((string) $task['read_note']) !== ''),
            array('label' => '步骤二 工作纲要', 'done' => trim((string) $task['outline']) !== ''),
            array('label' => '步骤三 初稿素材', 'done' => trim((string) $task['draft_text']) !== ''),
            array('label' => '步骤四 正式文本', 'done' => trim((string) $task['final_text']) !== ''),
        );
        foreach ($steps as $step) {
            /* TASK-1645（P2-1 批次 2）：类名先拼进变量再输出 —— 既完成双写（新类 + 兼容别名旧类），
               也修掉改造前既有的「类名属性内混入 PHP 引号拼接」源文本标记缺陷（渲染结果同前，仅多一个等价类名）。 */
            $step_cls = 'tco-sec-step tco-sec-step' . ($step['done'] ? ' is-done' : '');
            echo '<div class="' . $step_cls . '">'
                . esc_html($step['label']) . '<br /><span class="tco-sec-muted tco-sec-muted">' . ($step['done'] ? '已填写' : '未填写') . '</span></div>';
        }
        echo '</div>';

        if ($locked) {
            echo '<div class="tco-sec-ok tco-sec-ok"><strong>' . esc_html($task['status_label']) . '</strong>：文本已锁定，不可再修改（'
                . esc_html($task['published_at']) . '，命令：' . esc_html($task['publish_command']) . '）。</div>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="zw_secretariat_save_text" />';
            echo '<input type="hidden" name="task" value="' . (int) $task['id'] . '" />';
            wp_nonce_field('zw_secretariat_save_text');
            echo '<div class="tco-table-wrap">';
            echo '<table class="form-table tco-sec-table tco-sec-table"><tbody>';
            $fields = array(
                'read'    => array('步骤一 · 通读吃透与硬性约束清单', $task['read_note'], '逐条列出：时限 / 责任主体 / 必备要素 / 格式要求 / 禁止事项；标注材料冲突与缺失项。'),
                'outline' => array('步骤二 · 工作纲要（含里程碑与交付物地图）', $task['outline'], '按任务周期给出排期与里程碑；说明每类交付物的产出顺序。'),
                'draft'   => array('步骤三 · 初稿素材（内容提要 / 详细大纲 / 初稿）', $task['draft_text'], '此层可为素材与要点；正式成稿在步骤四。'),
                'final'   => array('步骤四 · 正式文本', $task['final_text'], '正式文本需通过质量校验 8 项后方可提交终审。'),
            );
            foreach ($fields as $key => $conf) {
                echo '<tr><th scope="row"><label for="zw-sec-' . esc_attr($key) . '">' . esc_html($conf[0]) . '</label></th><td>'
                    . '<textarea id="zw-sec-' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="10" class="large-text code">'
                    . esc_textarea((string) $conf[1]) . '</textarea>'
                    . '<p class="description">' . esc_html($conf[2]) . '</p></td></tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
            submit_button('保存文本');
            echo '</form>';
        }

        // 交付物与来源材料。
        echo '<h2>交付物</h2>';
        if ($task['deliverables']) {
            echo '<ul>';
            foreach ($task['deliverables'] as $key) {
                echo '<li>' . esc_html(zw_secretariat_deliverable_label($task['scenario'], $key)) . ' <span class="tco-sec-muted tco-sec-muted">(' . esc_html($key) . ')</span></li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="tco-sec-muted tco-sec-muted">未登记交付物。</p>';
        }
        echo '<h2>来源材料标识</h2>';
        if ($task['source_ids']) {
            echo '<ul>';
            foreach ($task['source_ids'] as $sid) {
                echo '<li><code>' . esc_html((string) $sid) . '</code></li>';
            }
            echo '</ul>';
        } else {
            echo '<p class="tco-sec-muted tco-sec-muted">未登记来源材料。</p>';
        }

        // 人审门 + 质检入口。
        echo '<h2>人审门与质量校验</h2>';
        echo '<p><a class="button" href="' . esc_url(zw_secretariat_admin_url('zw-secretariat-gates', array('task' => $task['id']))) . '">人审门</a> '
            . '<a class="button" href="' . esc_url(zw_secretariat_admin_url('zw-secretariat-qa', array('task' => $task['id']))) . '">质量校验报告</a></p>';
        echo '</div>';
    }
}

/* ─────────────────────────────────────────────── 人审门 */
if (!function_exists('zw_secretariat_page_gates')) {
    /**
     * 人审门页：门①②③ 确认 / 退回；门③ 发布·存档命令。
     *
     * @return void
     */
    function zw_secretariat_page_gates() {
        if (!zw_secretariat_can()) {
            zw_secretariat_die_forbidden();
        }
        zw_secretariat_style_once();

        $task_id = isset($_GET['task']) ? (int) $_GET['task'] : 0;
        if ($task_id <= 0) {
            echo '<div class="wrap"><h1>人审门</h1><p>缺少任务编号。</p></div>';
            return;
        }
        $task    = zw_secretariat_task_summary($task_id);
        $records = zw_secretariat_gate_records($task_id);
        $notice  = isset($_GET['zw_sec_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['zw_sec_notice'])) : '';

        echo '<div class="wrap tco-sec-wrap tco-sec-wrap">';
        echo '<h1>人审门 · ' . esc_html($task['title']) . '</h1>';
        echo '<p class="tco-sec-muted tco-sec-muted">状态：<strong>' . esc_html($task['status_label']) . '</strong>；'
            . '未获发布人确认不得进入下一步；发布 / 存档为人工命令。</p>';
        if ($notice !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<div class="tco-table-wrap">';
        echo '<table class="widefat striped tco-sec-table tco-sec-table"><thead><tr><th>门</th><th>状态</th><th>确认人</th><th>时间</th><th>意见</th><th>操作</th></tr></thead><tbody>';
        foreach (zw_secretariat_gates_summary($task_id) as $gate) {
            $can = zw_secretariat_gate_can_confirm($task_id, $gate['key']);
            $ok  = !(function_exists('is_wp_error') && is_wp_error($can));
            echo '<tr>';
            echo '<td><strong>' . esc_html($gate['label']) . '</strong></td>';
            $cls = $gate['state'] === 'confirmed' ? 'is-ok' : ($gate['state'] === 'returned' ? 'is-bad' : 'is-open');
            echo '<td><span class="tco-sec-badge ' . esc_attr($cls) . '">' . esc_html($gate['status']) . '</span></td>';
            echo '<td>' . esc_html($gate['by']) . '</td>';
            echo '<td>' . esc_html($gate['at']) . '</td>';
            echo '<td>' . esc_html($gate['note']) . '</td>';
            echo '<td>';
            if ($ok) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
                echo '<input type="hidden" name="action" value="zw_secretariat_gate_action" />';
                echo '<input type="hidden" name="task" value="' . (int) $task_id . '" />';
                echo '<input type="hidden" name="gate" value="' . esc_attr($gate['key']) . '" />';
                echo '<input type="hidden" name="decision" value="confirm" />';
                wp_nonce_field('zw_secretariat_gate_action');
                echo '<button class="button button-primary">确认</button></form> ';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">';
                echo '<input type="hidden" name="action" value="zw_secretariat_gate_action" />';
                echo '<input type="hidden" name="task" value="' . (int) $task_id . '" />';
                echo '<input type="hidden" name="gate" value="' . esc_attr($gate['key']) . '" />';
                echo '<input type="hidden" name="decision" value="return" />';
                echo '<input type="text" name="note" placeholder="退回意见" />';
                wp_nonce_field('zw_secretariat_gate_action');
                echo ' <button class="button">退回</button></form>';
            } else {
                echo '<span class="tco-sec-muted tco-sec-muted">' . esc_html(function_exists('is_wp_error') && is_wp_error($can) ? $can->get_error_message() : '当前不可操作') . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        // 门③ 发布 / 存档命令。
        echo '<h2>门③ 人工命令：发布 / 存档</h2>';
        $blockers = zw_secretariat_publish_blockers($task_id);
        if ($blockers) {
            echo '<div class="tco-sec-blockers tco-sec-blockers"><strong>不满足条件（逐条已列出）：</strong><ul>';
            foreach ($blockers as $reason) {
                echo '<li>' . esc_html($reason) . '</li>';
            }
            echo '</ul></div>';
        } else {
            echo '<div class="tco-sec-ok tco-sec-ok"><strong>已满足发布 / 存档条件</strong>（三道具名确认 + 质检 8/8 通过 + 正式文本非空）。</div>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="zw_secretariat_gate_command" />';
            echo '<input type="hidden" name="task" value="' . (int) $task_id . '" />';
            wp_nonce_field('zw_secretariat_gate_command');
            echo '<input type="text" class="regular-text" name="note" placeholder="命令备注（可选）" /> ';
            echo '<button class="button button-primary" name="command" value="publish">发布</button> ';
            echo '<button class="button" name="command" value="archive">存档</button>';
            echo '</form>';
        }
        echo '<p><a href="' . esc_url(zw_secretariat_admin_url('zw-secretariat-task', array('task' => $task_id))) . '">返回任务详情</a></p>';
        echo '</div>';
    }
}

/* ─────────────────────────────────────────────── 质量校验报告 */
if (!function_exists('zw_secretariat_page_qa')) {
    /**
     * 质量校验报告：8 项逐项结论（pass / fail / pending）。
     *
     * @return void
     */
    function zw_secretariat_page_qa() {
        if (!zw_secretariat_can()) {
            zw_secretariat_die_forbidden();
        }
        zw_secretariat_style_once();

        $task_id = isset($_GET['task']) ? (int) $_GET['task'] : 0;
        if ($task_id <= 0) {
            echo '<div class="wrap"><h1>质量校验报告</h1><p>缺少任务编号。</p></div>';
            return;
        }
        $task   = zw_secretariat_task_summary($task_id);
        $report = zw_secretariat_qa_report($task_id);
        $notice = isset($_GET['zw_sec_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['zw_sec_notice'])) : '';

        echo '<div class="wrap tco-sec-wrap tco-sec-wrap">';
        echo '<h1>质量校验报告 · ' . esc_html($task['title']) . '</h1>';
        echo '<p class="tco-sec-muted tco-sec-muted">需求 §6：逐条核对并在报告中勾选与说明；任一项存疑即退回修订。'
            . '门③ 要求 **8 项全部通过**。</p>';
        if ($notice !== '') {
            echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>';
        }
        if (zw_secretariat_qa_is_pass($task_id)) {
            echo '<div class="tco-sec-ok tco-sec-ok"><strong>8/8 全部通过。</strong></div>';
        } else {
            echo '<div class="tco-sec-blockers tco-sec-blockers"><strong>尚未满足（缺项按待校验计，不默认通过）：</strong><ul>';
            foreach (zw_secretariat_qa_blockers($task_id) as $reason) {
                echo '<li>' . esc_html($reason) . '</li>';
            }
            echo '</ul></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="zw_secretariat_qa_save" />';
        echo '<input type="hidden" name="task" value="' . (int) $task_id . '" />';
        wp_nonce_field('zw_secretariat_qa_save');
        echo '<div class="tco-table-wrap">';
        echo '<table class="widefat striped tco-sec-table tco-sec-table"><thead><tr><th>校验项</th><th>结论</th><th>说明</th><th>校验人</th><th>时间</th></tr></thead><tbody>';
        $states = zw_secretariat_qa_states();
        foreach ($report as $key => $row) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($row['label']) . '</strong><br /><span class="tco-sec-muted tco-sec-muted">' . esc_html($key) . '</span></td>';
            echo '<td><select name="qa[' . esc_attr($key) . '][state]">';
            foreach ($states as $sk => $sl) {
                echo '<option value="' . esc_attr($sk) . '"' . selected($row['state'], $sk, false) . '>' . esc_html($sl) . '</option>';
            }
            echo '</select></td>';
            echo '<td><input type="text" class="regular-text" name="qa[' . esc_attr($key) . '][note]" value="' . esc_attr($row['note']) . '" /></td>';
            echo '<td>' . esc_html($row['by_name'] !== '' ? $row['by_name'] : (string) $row['by']) . '</td>';
            echo '<td>' . esc_html($row['at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        submit_button('保存校验结论');
        echo '</form>';
        echo '<p><a href="' . esc_url(zw_secretariat_admin_url('zw-secretariat-task', array('task' => $task_id))) . '">返回任务详情</a></p>';
        echo '</div>';
    }
}

/* ─────────────────────────────────────────────── 写操作（admin-post） */
if (!function_exists('zw_secretariat_redirect_task')) {
    /**
     * 处理完跳回任务页（带提示）。
     *
     * @param string $slug    页面 slug。
     * @param int    $task_id 任务 ID。
     * @param string $notice  提示。
     * @return void
     */
    function zw_secretariat_redirect_task($slug, $task_id, $notice = '') {
        $args = array('task' => (int) $task_id);
        if ($notice !== '') {
            $args['zw_sec_notice'] = $notice;
        }
        wp_safe_redirect(zw_secretariat_admin_url($slug, $args));
        exit;
    }
}

if (!function_exists('zw_secretariat_handle_create')) {
    /**
     * 处理「新建任务」。
     *
     * @return void
     */
    function zw_secretariat_handle_create() {
        if (!zw_secretariat_can()) {
            zw_secretariat_die_forbidden();
        }
        check_admin_referer('zw_secretariat_create_task');

        $title     = isset($_POST['title']) ? wp_unslash((string) $_POST['title']) : '';
        $scenario  = isset($_POST['scenario']) ? wp_unslash((string) $_POST['scenario']) : '';
        $cycle     = isset($_POST['cycle']) ? wp_unslash((string) $_POST['cycle']) : '';
        $items     = isset($_POST['deliverables']) ? (array) wp_unslash($_POST['deliverables']) : array();
        $raw_src   = isset($_POST['source_ids']) ? (string) wp_unslash($_POST['source_ids']) : '';
        $sources   = array();
        foreach (preg_split('/[\r\n]+/', $raw_src) as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $sources[] = $line;
            }
        }

        $task_id = zw_secretariat_create_task(array(
            'title'        => $title,
            'scenario'     => $scenario,
            'cycle'        => $cycle,
            'deliverables' => $items,
            'source_ids'   => $sources,
        ));
        if (function_exists('is_wp_error') && is_wp_error($task_id)) {
            wp_safe_redirect(zw_secretariat_admin_url('zw-secretariat-create', array('zw_sec_notice' => $task_id->get_error_message())));
            exit;
        }
        zw_secretariat_redirect_task('zw-secretariat-task', (int) $task_id, '任务已登记。');
    }
}

if (!function_exists('zw_secretariat_handle_save_text')) {
    /**
     * 处理「保存四步文本」。
     *
     * @return void
     */
    function zw_secretariat_handle_save_text() {
        if (!zw_secretariat_can()) {
            zw_secretariat_die_forbidden();
        }
        check_admin_referer('zw_secretariat_save_text');

        $task_id = isset($_POST['task']) ? (int) $_POST['task'] : 0;
        if ($task_id <= 0) {
            wp_die('缺少任务编号。', '参数错误', array('response' => 400));
        }
        $notice = '已保存。';
        foreach (array('read', 'outline', 'draft', 'final') as $field) {
            if (!isset($_POST[$field])) {
                continue;
            }
            $saved = zw_secretariat_update_text($task_id, $field, wp_unslash((string) $_POST[$field]));
            if (function_exists('is_wp_error') && is_wp_error($saved)) {
                $notice = $saved->get_error_message();
                break;
            }
        }
        zw_secretariat_redirect_task('zw-secretariat-task', $task_id, $notice);
    }
}

if (!function_exists('zw_secretariat_handle_gate_action')) {
    /**
     * 处理人审门确认 / 退回。
     *
     * @return void
     */
    function zw_secretariat_handle_gate_action() {
        if (!zw_secretariat_can()) {
            zw_secretariat_die_forbidden();
        }
        check_admin_referer('zw_secretariat_gate_action');

        $task_id  = isset($_POST['task']) ? (int) $_POST['task'] : 0;
        $gate     = isset($_POST['gate']) ? sanitize_key(wp_unslash((string) $_POST['gate'])) : '';
        $decision = isset($_POST['decision']) ? sanitize_key(wp_unslash((string) $_POST['decision'])) : '';
        $note     = isset($_POST['note']) ? wp_unslash((string) $_POST['note']) : '';
        if ($task_id <= 0 || $gate === '') {
            wp_die('参数不完整。', '参数错误', array('response' => 400));
        }

        if ($decision === 'return') {
            $result = zw_secretariat_gate_return($task_id, $gate, array('note' => $note));
        } else {
            $result = zw_secretariat_gate_confirm($task_id, $gate, array('note' => $note));
        }
        $notice = (function_exists('is_wp_error') && is_wp_error($result)) ? $result->get_error_message() : '操作成功。';
        zw_secretariat_redirect_task('zw-secretariat-gates', $task_id, $notice);
    }
}

if (!function_exists('zw_secretariat_handle_gate_command')) {
    /**
     * 处理门③ 发布 / 存档命令。
     *
     * @return void
     */
    function zw_secretariat_handle_gate_command() {
        if (!zw_secretariat_can()) {
            zw_secretariat_die_forbidden();
        }
        check_admin_referer('zw_secretariat_gate_command');

        $task_id = isset($_POST['task']) ? (int) $_POST['task'] : 0;
        $command = isset($_POST['command']) ? sanitize_key(wp_unslash((string) $_POST['command'])) : '';
        $note    = isset($_POST['note']) ? wp_unslash((string) $_POST['note']) : '';
        if ($task_id <= 0) {
            wp_die('缺少任务编号。', '参数错误', array('response' => 400));
        }
        $result = zw_secretariat_gate_command($task_id, $command, array('note' => $note));
        $notice = (function_exists('is_wp_error') && is_wp_error($result)) ? $result->get_error_message() : '命令已执行。';
        zw_secretariat_redirect_task('zw-secretariat-gates', $task_id, $notice);
    }
}

if (!function_exists('zw_secretariat_handle_qa_save')) {
    /**
     * 处理质检报告保存（逐项具名结论）。
     *
     * @return void
     */
    function zw_secretariat_handle_qa_save() {
        if (!zw_secretariat_can()) {
            zw_secretariat_die_forbidden();
        }
        check_admin_referer('zw_secretariat_qa_save');

        $task_id = isset($_POST['task']) ? (int) $_POST['task'] : 0;
        if ($task_id <= 0) {
            wp_die('缺少任务编号。', '参数错误', array('response' => 400));
        }
        $rows   = isset($_POST['qa']) ? (array) wp_unslash($_POST['qa']) : array();
        $notice = '质检结论已保存。';
        foreach ($rows as $key => $row) {
            $key   = sanitize_key((string) $key);
            $state = isset($row['state']) ? sanitize_key((string) $row['state']) : 'pending';
            $note  = isset($row['note']) ? (string) $row['note'] : '';
            $saved = zw_secretariat_qa_set_item($task_id, $key, $state, array('note' => $note));
            if (function_exists('is_wp_error') && is_wp_error($saved)) {
                $notice = $saved->get_error_message();
                break;
            }
        }
        zw_secretariat_redirect_task('zw-secretariat-qa', $task_id, $notice);
    }
}
