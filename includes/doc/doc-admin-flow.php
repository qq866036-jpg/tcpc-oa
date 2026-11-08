<?php
/**
 * 后台「公文流转配置」页面（TASK-433）。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_flow_handle_post')) {
    /**
     * 在 admin_init 阶段处理流转配置保存，避免页面输出后重定向。
     *
     * @return void
     */
    function zw_doc_flow_handle_post() {
        if ((($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') || !isset($_POST['zw_doc_action']) || $_POST['zw_doc_action'] !== 'save_flow') {
            return;
        }
        // TASK-1345：显式判权。原先只依赖 doc-bootstrap 的 admin_init 守卫；
        // 本处理器自身补一层（纵深防御），不改变合法管理员的既有行为。
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }
        check_admin_referer('zw_doc_save_flow');
        // TASK-1396：字段清洗与落库改走**唯一写入口** `zw_doc_dict_save()`。
        // 清洗语义（含 TASK-1345 的候选来源部门服务端白名单）逐字搬到
        // `zw_doc_dict_sanitize_flow()`，本页表单 / 旧 URL / nonce / 判权 / 事件留痕**一律保留**
        // ⇒ 零行为回归；全仓 `update_option('zw_doc_flow_settings')` 只允许一处（在 canonical 内）。
        $settings = zw_doc_dict_save('flow', $_POST, 'flow_page');
        if (!is_array($settings)) {
            wp_safe_redirect(zw_doc_admin_url(array('page' => 'zw-doc-flow', 'zw_doc_msg' => '流转配置保存失败（清洗结果非法）。')));
            exit;
        }
        // TASK-1345：流转配置变更留痕。
        // 事件表以 `document_id = 0` 记录**配置级**事件 —— 该行不会出现在任何发文详情页的「流转记录」，
        // 仅用于回答审计问题「谁在什么时刻把审核规则改成了什么」。
        // TASK-1410：备注补「自动流转开关组」四项取值（谁把哪个自动步骤改成了什么，可审计）。
        if (function_exists('zw_doc_log_event')) {
            $auto_note = array();
            foreach (zw_doc_auto_flow_switch_definitions() as $auto_key => $auto_meta) {
                $auto_note[] = $auto_key . ' ' . (!empty($settings[$auto_key]) ? '开' : '关');
            }
            zw_doc_log_event(
                0,
                'flow_config_saved',
                get_current_user_id(),
                '',
                '',
                sprintf(
                    '流转配置保存：审核人候选部门 %d 个｜抄送候选部门 %d 个｜自动审核 %s｜自动流转开关组：%s',
                    count($settings['reviewer_dept_keys']),
                    count($settings['cc_dept_keys']),
                    $settings['auto_review'] ? '开' : '关',
                    implode('｜', $auto_note)
                )
            );
        }
        wp_safe_redirect(zw_doc_admin_url(array('page' => 'zw-doc-flow', 'zw_doc_msg' => '流转配置已保存。')));
        exit;
    }
}

if (!function_exists('zw_doc_page_flow')) {
    /**
     * 最小流转配置页。
     *
     * @return void
     */
    function zw_doc_page_flow() {
        if (!current_user_can('manage_options')) {
            wp_die('无权限', '禁止访问', array('response' => 403));
        }

        $notice   = isset($_GET['zw_doc_msg']) ? sanitize_text_field(wp_unslash($_GET['zw_doc_msg'])) : '';
        $settings = zw_doc_flow_settings();
        ?>
        <div class="wrap tco-doc-flow">
            <?php echo zw_doc_admin_ui_style(); // 唯一出处：SPEC-10 §1.9 卡片范式（TASK-778） ?>
            <style>
            .tco-doc-flow .tco-doc-muted{line-height:1.7}
            </style>
            <h1>公文流转配置</h1>
            <p class="tco-doc-muted">MVP 固定为单级审核，不做可视化流程设计器、多级审批或会签。</p>
            <p class="tco-doc-muted">流转字典已集中到 <a href="<?php echo esc_url(zw_doc_dict_admin_url()); ?>">发文管理 → 数据字典</a> 统一维护（本页表单保留，写入走同一唯一写入口）。</p>
            <?php if ($notice !== '') { zw_doc_admin_notice($notice); } ?>
            <div class="tco-doc-card">
                <form method="post">
                    <?php wp_nonce_field('zw_doc_save_flow'); ?>
                    <input type="hidden" name="zw_doc_action" value="save_flow">
                    <div class="tco-doc-field">
                        <label class="tco-touch"><input type="checkbox" name="require_review" value="1"<?php checked(!empty($settings['require_review'])); ?>> 需要审核</label>
                        <div class="tco-doc-muted">关闭后提交可直接进入已通过（MVP 保留开关，默认开启）。</div>
                    </div>
                    <div class="tco-doc-field">
                        <label>审核方式</label>
                        <input type="text" value="单人审核（MVP 固定）" readonly style="width:280px">
                    </div>
                    <?php
                    // TASK-1345（D2/D4）：候选来源部门按**当前组织架构部门字典**渲染。
                    $flow_dept_options = array();
                    foreach (zw_doc_flow_dept_options() as $flow_dept_key => $flow_dept_name) {
                        $flow_dept_options[] = array(
                            'value'  => (string) $flow_dept_key,
                            'label'  => (string) $flow_dept_name,
                            'search' => $flow_dept_name . ' ' . $flow_dept_key,
                        );
                    }
                    ?>
                    <div class="tco-doc-field">
                        <label>审核人候选部门</label>
                        <?php
                        if (function_exists('zw_control_search_select')) {
                            zw_control_search_select(array(
                                'id'          => 'tco-doc-flow-reviewer-depts',
                                'name'        => 'reviewer_dept_keys[]',
                                'multiple'    => true,
                                'value'       => $settings['reviewer_dept_keys'],
                                'placeholder' => '输入部门名称筛选',
                                'empty_text'  => '没有匹配的部门',
                                'options'     => $flow_dept_options,
                            ));
                        } else {
                            // 降级：控件资源未加载时退回原生多选，保证表单仍可用（服务端仍是权威校验）。
                            echo '<select name="reviewer_dept_keys[]" multiple size="5" style="min-height:110px">';
                            foreach ($flow_dept_options as $flow_option) {
                                $flow_selected = in_array($flow_option['value'], $settings['reviewer_dept_keys'], true);
                                echo '<option value="' . esc_attr($flow_option['value']) . '"' . ($flow_selected ? ' selected' : '') . '>' . esc_html($flow_option['label']) . '</option>';
                            }
                            echo '</select>';
                        }
                        ?>
                    </div>
                    <div class="tco-doc-field">
                        <?php
                        // TASK-1410：四项自动流转开关**统一渲染**（唯一实现，配置页与数据字典页共用）。
                        echo zw_doc_auto_flow_render_switches($settings, 'tco-doc-flow-auto');
                        ?>
                    </div>
                    <div class="tco-doc-field">
                        <label>抄送人候选部门</label>
                        <?php
                        if (function_exists('zw_control_search_select')) {
                            zw_control_search_select(array(
                                'id'          => 'tco-doc-flow-cc-depts',
                                'name'        => 'cc_dept_keys[]',
                                'multiple'    => true,
                                'value'       => $settings['cc_dept_keys'],
                                'placeholder' => '输入部门名称筛选',
                                'empty_text'  => '没有匹配的部门',
                                'options'     => $flow_dept_options,
                            ));
                        } else {
                            echo '<select name="cc_dept_keys[]" multiple size="5" style="min-height:110px">';
                            foreach ($flow_dept_options as $flow_option) {
                                $flow_selected = in_array($flow_option['value'], $settings['cc_dept_keys'], true);
                                echo '<option value="' . esc_attr($flow_option['value']) . '"' . ($flow_selected ? ' selected' : '') . '>' . esc_html($flow_option['label']) . '</option>';
                            }
                            echo '</select>';
                        }
                        ?>
                    </div>
                    <div class="tco-doc-field">
                        <label>抄送人可见时点</label>
                        <select name="cc_visibility">
                            <option value="approved"<?php selected($settings['cc_visibility'], 'approved'); ?>>审核通过后可见</option>
                            <option value="submitted"<?php selected($settings['cc_visibility'], 'submitted'); ?>>提交后可见</option>
                        </select>
                    </div>
                    <div class="tco-doc-field">
                        <label class="tco-touch"><input type="checkbox" name="allow_withdraw" value="1"<?php checked(!empty($settings['allow_withdraw'])); ?>> 待审核阶段允许发起人撤回</label>
                    </div>
                    <div class="tco-doc-field">
                        <label class="tco-touch"><input type="checkbox" name="allow_resubmit" value="1"<?php checked(!empty($settings['allow_resubmit'])); ?>> 驳回后允许修改重提</label>
                    </div>
                    <div class="tco-doc-field">
                        <label class="tco-touch"><input type="checkbox" name="download_log_enabled" value="1"<?php checked(!empty($settings['download_log_enabled'])); ?>> 记录附件下载日志</label>
                        <div class="tco-doc-muted">关闭后附件仍可下载，但不再写入下载日志表。</div>
                    </div>
                    <p><button class="button button-primary" type="submit">保存配置</button></p>
                </form>
            </div>
        </div>
        <?php
    }
}
