<?php
/**
 * 工作台卡片 provider（声明式 glue，AGENT-0248 · 方案 §11.4 模板）。
 *
 * 三条硬规矩：① 只返回数据，不 echo 任何 HTML/CSS（渲染唯一处 = 工作台插件 includes/cards.php）；
 * ② 不读文件（ci-check 的 I2 判据按字面扫描，注释里也不要写读取函数名），版本取自既有常量；
 * ③ 不注册 metrics/ledger/specs/page 四键（那是主题的数据面）。
 *
 * @package main
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('zw_workbench_cards', function (array $cards): array {
    $cards['oa'] = array(
        'label'            => '办公协同',
        'contract_version' => 2,
        'rows'             => array(
            array('label' => '版本',     'value' => defined('TCPC_OA_VERSION') ? (string) TCPC_OA_VERSION : '未知'),
            array('label' => '契约版本', 'value' => 'v2'),
            array('label' => '渲染出处', 'value' => '工作台插件 includes/cards.php（唯一）', 'state' => 'ok'),
        ),
    );
    return $cards;
});
