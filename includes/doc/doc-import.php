<?php
/**
 * 发文管理历史数据导入与字段解析（TASK-484）。
 *
 * 说明：
 * - 本文件只提供后台可复用的导入解析、部门关联与执行函数；
 * - 默认 dry-run，仅 CLI 显式传入 --execute --confirm=YES 才写入；
 * - 导入数据来自 Excel 的 2023-2026 工作簿；
 * - 附件中的「备注、牌证编号、截止日期、快递地址」不导入。
 *
 * @package tcpc
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('zw_doc_import_clean_text')) {
    /**
     * 清洗文本，保留中文标点和空格。
     *
     * @param mixed $value 原值。
     * @return string
     */
    function zw_doc_import_clean_text($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }
        return trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }
}

if (!function_exists('zw_doc_import_normalize_number_text')) {
    /**
     * 归一化文号文本：统一括号、破折号与空白。
     *
     * @param mixed $raw 原文号。
     * @return string
     */
    function zw_doc_import_normalize_number_text($raw) {
        $raw = (string) $raw;
        if ($raw === '') {
            return '';
        }

        $raw = strtr($raw, array(
            '｛' => '〔',
            '｝' => '〕',
            '﹝' => '〔',
            '﹞' => '〕',
            '[' => '〔',
            ']' => '〕',
            '【' => '〔',
            '】' => '〕',
        ));
        $raw = preg_replace('/[\s\x{00A0}]+/u', '', $raw);
        $raw = str_replace(array('—', '–', '－', '~', '～', '至', '到'), '-', $raw);
        $raw = preg_replace('/-+/u', '-', $raw);

        return trim((string) $raw);
    }
}

if (!function_exists('zw_doc_import_is_excel_date_value')) {
    /**
     * 判断一个单元格值是否可能为发文日期。
     *
     * @param mixed $value 单元格值。
     * @return bool
     */
    function zw_doc_import_is_excel_date_value($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }
        if (is_numeric($value) && (int) $value >= 20000 && (int) $value <= 80000) {
            return true;
        }
        return (bool) preg_match('/^\d{4}\s*[年\.\-\/]\s*\d{1,2}\s*[月\.\-\/]\s*\d{1,2}\s*[日号]?/u', $value);
    }
}

if (!function_exists('zw_doc_import_excel_date')) {
    /**
     * 将 Excel 日期序列号或中文日期转成 Y-m-d。
     *
     * @param mixed $value 单元格值。
     * @return string
     */
    function zw_doc_import_excel_date($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (is_numeric($value) && (int) $value >= 20000 && (int) $value <= 80000) {
            $days = (int) $value - 25569;
            return gmdate('Y-m-d', (int) ($days * 86400));
        }

        if (preg_match('/^(\d{4})\s*[年\.\-\/]\s*(\d{1,2})\s*[月\.\-\/]\s*(\d{1,2})\s*[日号]?/u', $value, $m)) {
            $year  = (int) $m[1];
            $month = (int) $m[2];
            $day   = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
            return '';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }
        return date('Y-m-d', $timestamp);
    }
}

if (!function_exists('zw_doc_import_parse_doc_type')) {
    /**
     * 从「年份前」的文本中解析机关前缀与文种。
     *
     * 规则：
     * - 中传函 / 中传通知 / 中传字：前缀中传，文种为括号后剩余部分；
     * - 中传任：识别为新增文种「任」；
     * - 非中传前缀：优先识别已知文种后缀（如中促民字），否则整体作为文种。
     *
     * @param string $before 年份六角括号之前的文本。
     * @return array{prefix:string,type:string}
     */
    function zw_doc_import_parse_doc_type($before) {
        $before = trim((string) $before);
        $prefix = '中传';
        $type   = '函';

        if ($before === '' || $before === '中传') {
            return array('prefix' => '中传', 'type' => '函');
        }

        if (strpos($before, '中传') === 0) {
            $tail = substr($before, 6); // 中传为 UTF-8 两个字符，共 6 字节。
            $tail = trim((string) $tail);
            return array(
                'prefix' => '中传',
                'type'   => $tail !== '' ? $tail : '函',
            );
        }

        if (preg_match('/(函|通知|字|任|令|请示|批复|公告|决定)$/u', $before, $m)) {
            $type   = $m[1];
            $prefix = substr($before, 0, -strlen($type));
            $prefix = $prefix !== '' ? $prefix : '中传';
            return array('prefix' => $prefix, 'type' => $type);
        }

        return array('prefix' => '中传', 'type' => $before);
    }
}

if (!function_exists('zw_doc_import_parse_number_expression')) {
    /**
     * 解析一个文号或文号范围表达式。
     *
     * 支持：
     * - 中传函〔2026〕86号
     * - 中传函｛2024｝30号
     * - 中传函〔2024〕6号-12号
     * - 中传函〔2023〕89号98-102
     * - 中传任〔2020〕035号
     * - 中促民字﹝2022﹞2号
     *
     * 返回值中 number 为按系统规则归一化后的单个文号；source_number 保留原始行文号。
     *
     * @param mixed $raw 原文号。
     * @return array<int,array<string,mixed>>
     */
    function zw_doc_import_parse_number_expression($raw) {
        $source_number = trim((string) $raw);
        $raw = zw_doc_import_normalize_number_text($raw);
        if ($raw === '') {
            return array();
        }

        if (!preg_match('/〔(\d{4})〕/u', $raw, $year_match)) {
            return array();
        }

        $year      = (int) $year_match[1];
        $year_full = '〔' . $year_match[1] . '〕';
        $year_pos  = strpos($raw, $year_full);
        if ($year_pos === false) {
            return array();
        }

        $before = trim(substr($raw, 0, $year_pos));
        $after  = trim(substr($raw, $year_pos + strlen($year_full)));
        $parts  = zw_doc_import_parse_doc_type($before);

        // 提取范围表达式中的数字 token 与破折号位置。
        preg_match_all('/\d+/u', $after, $token_matches, PREG_OFFSET_CAPTURE);
        $tokens = array();
        foreach ((array) $token_matches[0] as $token) {
            if (!is_array($token) || !isset($token[0], $token[1])) {
                continue;
            }
            $tokens[] = array(
                'value'  => (int) $token[0],
                'offset' => (int) $token[1],
            );
        }

        preg_match_all('/-/', $after, $dash_matches, PREG_OFFSET_CAPTURE);
        $ranges  = array();
        $used    = array();
        foreach ((array) $dash_matches[0] as $dash) {
            if (!is_array($dash) || !isset($dash[1])) {
                continue;
            }
            $dash_offset = (int) $dash[1];
            $left        = null;
            $right       = null;
            foreach ($tokens as $index => $token) {
                if ($token['offset'] < $dash_offset) {
                    $left = $token;
                    continue;
                }
                if ($token['offset'] > $dash_offset) {
                    $right = $token;
                    break;
                }
            }
            if ($left === null || $right === null) {
                continue;
            }
            $left_value  = (int) $left['value'];
            $right_value = (int) $right['value'];
            if ($right_value < $left_value || ($right_value - $left_value) > 500) {
                continue;
            }
            for ($seq = $left_value; $seq <= $right_value; $seq++) {
                $ranges[] = $seq;
            }
            $used[] = $left['value'] . ':' . $left['offset'];
            $used[] = $right['value'] . ':' . $right['offset'];
        }

        $sequences = $ranges;
        foreach ($tokens as $token) {
            $marker = $token['value'] . ':' . $token['offset'];
            if (in_array($marker, $used, true)) {
                continue;
            }
            $sequences[] = (int) $token['value'];
        }

        $sequences = array_values(array_unique(array_filter(array_map('intval', $sequences), function ($seq) {
            return $seq > 0 && $seq <= 999999;
        })));
        sort($sequences, SORT_NUMERIC);

        if (empty($sequences)) {
            return array();
        }

        $items = array();
        foreach ($sequences as $seq) {
            $full_number = $parts['prefix'] . $parts['type'] . '〔' . $year . '〕' . (int) $seq . '号';
            $items[] = array(
                'year'          => $year,
                'type'          => (string) $parts['type'],
                'seq'           => (int) $seq,
                'full_number'   => $full_number,
                'source_number' => $source_number,
            );
        }

        return $items;
    }
}

if (!function_exists('zw_doc_import_branch_index')) {
    /**
     * 发现现有分支机构：标题 => ID。
     *
     * @return array<string,array{id:int,title:string,record_type:string,priority:int}>
     */
    function zw_doc_import_branch_index() {
        static $index = null;
        if ($index !== null) {
            return $index;
        }
        $index = array();
        if (!function_exists('get_posts')) {
            return $index;
        }

        $posts = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'category_name'  => 'fzjg',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'suppress_filters' => true,
        ));

        foreach ((array) $posts as $post) {
            $post_id = is_object($post) && isset($post->ID) ? (int) $post->ID : (int) $post;
            if ($post_id <= 0) {
                continue;
            }
            $post_object = function_exists('get_post') ? get_post($post_id) : null;
            if ($post_object && isset($post_object->post_title)) {
                $title = (string) $post_object->post_title;
            } else {
                $title = function_exists('get_the_title') ? (string) get_the_title($post_id) : '';
            }
            $title = preg_replace('/^(私密|密码保护)：/u', '', $title);
            if (trim((string) $title) === '') {
                continue;
            }
            $title = trim((string) $title);
            $record_type = function_exists('zw_branch_get_record_type') ? (string) zw_branch_get_record_type($post_id) : '';
            if ($record_type === '' && function_exists('get_post_meta')) {
                $record_type = (string) get_post_meta($post_id, 'zw_branch_record_type', true);
            }
            $priority = $record_type === 'archive' ? 0 : 1;
            if (!isset($index[$title]) || $priority > (int) $index[$title]['priority']) {
                $index[$title] = array(
                    'id'          => $post_id,
                    'title'       => $title,
                    'record_type' => $record_type,
                    'priority'    => $priority,
                );
            }
        }

        return $index;
    }
}

if (!function_exists('zw_doc_import_branch_lookup')) {
    /**
     * 在分支机构集合中按标题查询。
     *
     * @param string     $title    机构标题。
     * @param array|null $branches 标题 => ID 或标题 => 行数组；为空时自动发现。
     * @return array|null
     */
    function zw_doc_import_branch_lookup($title, $branches = null) {
        $title = trim((string) $title);
        if ($title === '') {
            return null;
        }
        if ($branches === null) {
            $branches = zw_doc_import_branch_index();
        }
        if (!is_array($branches)) {
            return null;
        }
        if (isset($branches[$title])) {
            $row = $branches[$title];
            if (is_array($row)) {
                return array(
                    'id'          => isset($row['id']) ? (int) $row['id'] : (isset($row[0]) ? (int) $row[0] : 0),
                    'title'       => isset($row['title']) ? (string) $row['title'] : $title,
                    'record_type' => isset($row['record_type']) ? (string) $row['record_type'] : '',
                );
            }
            return array('id' => (int) $row, 'title' => $title, 'record_type' => '');
        }
        return null;
    }
}

if (!function_exists('zw_doc_import_find_department')) {
    /**
     * 将 Excel 中的部门字段推理到现有分支机构。
     *
     * 例如：人才委 => 人才工作委员会。
     *
     * @param mixed      $department 原部门文本。
     * @param array|null $branches   可选的机构字典，便于测试。
     * @return array{department:string,branch_id:int,matched:bool,rule:string}
     */
    function zw_doc_import_find_department($department, $branches = null) {
        $source = zw_doc_import_clean_text($department);
        if ($source === '') {
            return array('department' => '', 'branch_id' => 0, 'matched' => false, 'rule' => 'empty');
        }

        $exact = zw_doc_import_branch_lookup($source, $branches);
        if (!empty($exact['id'])) {
            return array(
                'department' => (string) $exact['title'],
                'branch_id'  => (int) $exact['id'],
                'matched'    => true,
                'rule'       => 'exact',
            );
        }

        $aliases = array(
            '人才委'       => '人才工作委员会',
            '标准委'       => '标准化工作委员会',
            '标准办公室'   => '标准化工作委员会',
            '标准管理办公室' => '标准化工作委员会',
            '新中式'       => '新中式文化工作委员会',
            '总会支部'     => '',
        );
        if (isset($aliases[$source]) && $aliases[$source] !== '') {
            $alias = zw_doc_import_branch_lookup($aliases[$source], $branches);
            if (!empty($alias['id'])) {
                return array(
                    'department' => (string) $alias['title'],
                    'branch_id'  => (int) $alias['id'],
                    'matched'    => true,
                    'rule'       => 'alias',
                );
            }
        }

        $keyword_rules = array(
            '人才'       => '人才工作委员会',
            '标准'       => '标准化工作委员会',
            '非物质'     => '非物质文化遗产工作委员会',
            '非遗'       => '非物质文化遗产工作委员会',
            '新中式'     => '新中式文化工作委员会',
            '康养'       => '康养工作委员会',
            '美育'       => '美育工作委员会',
            '艺术疗愈'   => '艺术疗愈专业委员会',
            '文化创新'   => '文化创新工作委员会',
            '文明发展'   => '文明发展工作委员会',
            '孔子'       => '孔子文化传承工作委员会',
            '道家'       => '道家文化工作委员会',
            '茶文化'     => '茶文化专业委员会',
            '香文化'     => '香文化专业委员会',
            '民族体育'   => '民族体育分会',
            '文化融合发展' => '文化融合发展分会',
            '中医古方'   => '中医古方研究专业委员会',
            '教育研究'   => '教育研究专业委员会',
            '语言艺术'   => '语言艺术专业委员会',
            '语言文化'   => '语言文化研究专业委员会',
            '对外文化'   => '对外文化交流工作委员会',
            '对外交流'   => '对外交流工作委员会',
        );
        foreach ($keyword_rules as $keyword => $target) {
            if (mb_strpos($source, $keyword, 0, 'UTF-8') === false) {
                continue;
            }
            $match = zw_doc_import_branch_lookup($target, $branches);
            if (!empty($match['id'])) {
                return array(
                    'department' => (string) $match['title'],
                    'branch_id'  => (int) $match['id'],
                    'matched'    => true,
                    'rule'       => 'keyword:' . $keyword,
                );
            }
        }

        // 去掉机构类型后缀后再做核心词包含推理，覆盖“某专业委/某工作委”等简写。
        $suffix_pattern = '/(工作委员会|专业委员会|分会|委员会|办公室|工作部|工作室|委)$/u';
        $source_core    = preg_replace($suffix_pattern, '', $source);
        if ($source_core !== '' && $source_core !== $source) {
            $index = is_array($branches) ? $branches : zw_doc_import_branch_index();
            foreach ((array) $index as $title => $row) {
                $branch_title = is_array($row) && isset($row['title']) ? (string) $row['title'] : (string) $title;
                $branch_core  = preg_replace($suffix_pattern, '', $branch_title);
                if ($branch_core === '' || $branch_core === $branch_title) {
                    continue;
                }
                if (mb_strpos($branch_core, $source_core, 0, 'UTF-8') !== false || mb_strpos($source_core, $branch_core, 0, 'UTF-8') !== false) {
                    $match = zw_doc_import_branch_lookup($branch_title, $index);
                    if (!empty($match['id'])) {
                        return array(
                            'department' => (string) $match['title'],
                            'branch_id'  => (int) $match['id'],
                            'matched'    => true,
                            'rule'       => 'core',
                        );
                    }
                }
            }
        }

        return array('department' => $source, 'branch_id' => 0, 'matched' => false, 'rule' => 'unmatched');
    }
}

if (!function_exists('zw_doc_department_head_name')) {
    /**
     * 发文部门名录首项 / 默认值：协会本级（TASK-1259 用户拍板）。
     *
     * @return string
     */
    function zw_doc_department_head_name() {
        return '中国传统文化促进会';
    }
}

if (!function_exists('zw_doc_branch_is_normal')) {
    /**
     * 分支机构是否「正常状态」（TASK-1259 用户拍板口径）。
     *
     * - `zw_branch_status` 为 `normal` **或未设置** → 正常。未设置视为正常与
     *   `inc/admin/portal-dashboard.php` 的既有统计口径一致；历史机构多无该 meta，
     *   若把「未设置」当异常，会让大量现行机构从下拉中消失。
     * - 其余状态（`preparing` 筹建 / `transitioning` 换届中 / `archived` 已归档 /
     *   `merged` 已合并 / `revoked` 已撤销）→ 非正常。
     * - 历史归档记录（`zw_branch_record_type = archive`）一律排除。
     *
     * @param int $branch_id 机构 post ID。
     * @return bool
     */
    function zw_doc_branch_is_normal($branch_id) {
        $branch_id = (int) $branch_id;
        if ($branch_id <= 0) {
            return false;
        }
        if (function_exists('zw_branch_is_archive_record') && zw_branch_is_archive_record($branch_id)) {
            return false;
        }
        if (function_exists('get_post_meta') && (string) get_post_meta($branch_id, 'zw_branch_record_type', true) === 'archive') {
            return false;
        }
        $status = function_exists('get_post_meta') ? (string) get_post_meta($branch_id, 'zw_branch_status', true) : '';
        if ($status === '') {
            $status = 'normal';
        }

        return $status === 'normal';
    }
}

if (!function_exists('zw_doc_department_names')) {
    /**
     * 发文部门候选名录（TASK-1259 用户拍板）：**协会本级 ＋ 正常状态的分支机构**。
     *
     * 唯一出处：分支机构字典 `zw_doc_import_branch_index()`（`post_type=post` + `category=fzjg`）。
     * 名单**不接受自由文本** —— 新建向导与旧新建表单都只从这里取值渲染，
     * 服务端再用 `zw_doc_department_is_allowed()` 按同一名录二次校验。
     *
     * @return string[] 标题列表（协会本级恒在首位，其余沿用字典顺序）。
     */
    function zw_doc_department_names() {
        $head  = zw_doc_department_head_name();
        $names = array($head => $head);
        if (!function_exists('zw_doc_import_branch_index')) {
            return array_values($names);
        }
        foreach ((array) zw_doc_import_branch_index() as $row) {
            $title = is_array($row) && isset($row['title']) ? (string) $row['title'] : (string) $row;
            $title = trim($title);
            if ($title === '' || isset($names[$title])) {
                continue;
            }
            $branch_id = is_array($row) && isset($row['id']) ? (int) $row['id'] : 0;
            if (!zw_doc_branch_is_normal($branch_id)) {
                continue;
            }
            $names[$title] = $title;
        }

        return array_values($names);
    }
}

if (!function_exists('zw_doc_department_select_options')) {
    /**
     * 发文部门候选项（SPEC-85 canonical 可搜索单选下拉的本地模式格式）。
     *
     * @return array<int,array{value:string,label:string}>
     */
    function zw_doc_department_select_options() {
        $options = array();
        foreach (zw_doc_department_names() as $name) {
            $options[] = array('value' => (string) $name, 'label' => (string) $name);
        }

        return $options;
    }
}

if (!function_exists('zw_doc_department_is_allowed')) {
    /**
     * 发文部门是否在名录内（服务端二次校验；控件的 `required` 只是前端便利）。
     *
     * @param string $department 提交的部门文本。
     * @return bool 空值放行（字段本身非必填），非空时必须命中名录。
     */
    function zw_doc_department_is_allowed($department) {
        $department = trim((string) $department);
        if ($department === '') {
            return true;
        }

        return in_array($department, zw_doc_department_names(), true);
    }
}

if (!function_exists('zw_doc_head_suffix')) {
    /**
     * 红头后缀（TASK-1271 用户拍板）：机关 + 「文件」。
     *
     * @return string
     */
    function zw_doc_head_suffix() {
        return '文件';
    }
}

if (!function_exists('zw_doc_head_prefix')) {
    /**
     * 红头**协会全称前缀**（TASK-1278 用户拍板）：分支机构红头前**一定要加**「中国传统文化促进会」。
     *
     * 例：`中国传统文化促进会` + `孔子文化传承工作委员会` + `文件`
     *     = `中国传统文化促进会孔子文化传承工作委员会文件`。
     *
     * 唯一出处即协会本级名（`zw_doc_department_head_name()`），**禁止**在别处再写一遍字面量。
     *
     * @return string
     */
    function zw_doc_head_prefix() {
        return function_exists('zw_doc_department_head_name') ? (string) zw_doc_department_head_name() : '中国传统文化促进会';
    }
}

if (!function_exists('zw_doc_org_to_head')) {
    /**
     * 机关 → 默认红头。
     *
     * TASK-1278 口径：协会本级 → `中国传统文化促进会文件`；
     * **分支机构 → `中国传统文化促进会{机构名}文件`**（强制加协会全称前缀）。
     *
     * @param string $org 机关名。
     * @return string
     */
    function zw_doc_org_to_head($org) {
        $org    = trim((string) $org);
        $prefix = zw_doc_head_prefix();
        if ($org === '') {
            return '';
        }
        if ($org === $prefix) {
            return $prefix . zw_doc_head_suffix();
        }

        return $prefix . $org . zw_doc_head_suffix();
    }
}

if (!function_exists('zw_doc_org_head_options')) {
    /**
     * 某机关的**合法红头候选**（TASK-1278）。
     *
     * - 每个机关两项：「带「文件」项」在前 = **默认**，不带「文件」项在后；
     * - **两项都带协会全称前缀**（协会本级即前缀本身，故其「不带文件」项 = `中国传统文化促进会`）；
     * - 只对名录内机关返回；非名录机关返回空数组（调用方据此拒绝）。
     *
     * @param string $org 机关名。
     * @return string[]
     */
    function zw_doc_org_head_options($org) {
        $org = trim((string) $org);
        if ($org === '' || !in_array($org, zw_doc_department_names(), true)) {
            return array();
        }
        $prefix = zw_doc_head_prefix();
        $base   = ($org === $prefix) ? $prefix : $prefix . $org;

        return array($base . zw_doc_head_suffix(), $base);
    }
}

if (!function_exists('zw_doc_head_to_org')) {
    /**
     * 红头 → 归属机关；非名录机关返回空串（TASK-1271 归一依据，TASK-1278 扩前缀兼容）。
     *
     * 三类**同时兼容**（历史数据不得失配）：
     *  ① **新带前缀形式**：去掉尾部「文件」后若以 `zw_doc_head_prefix()` 开头 ——
     *     剩余为空 ⇒ 协会本级；剩余在名录内 ⇒ 返回该机构；其余 ⇒ 空串。
     *  ② **历史无前缀形式**（TASK-1278 之前的存量数据）：`孔子文化传承工作委员会文件` /
     *     `孔子文化传承工作委员会` 仍须归一到该机构。
     *  ③ **协会本级**：`中国传统文化促进会文件` / `中国传统文化促进会`。
     *
     * @param string $head 红头文字。
     * @return string
     */
    function zw_doc_head_to_org($head) {
        $head = trim((string) $head);
        if ($head === '') {
            return '';
        }
        $names  = zw_doc_department_names();
        $suffix = zw_doc_head_suffix();
        $prefix = zw_doc_head_prefix();
        $len    = function_exists('mb_strlen') ? mb_strlen($head, 'UTF-8') : strlen($head);
        $slen   = function_exists('mb_strlen') ? mb_strlen($suffix, 'UTF-8') : strlen($suffix);
        $plen   = function_exists('mb_strlen') ? mb_strlen($prefix, 'UTF-8') : strlen($prefix);

        // 去尾部「文件」（一次）；保留候选供前缀/名录两条路径判定。
        $candidate = $head;
        if ($len > $slen) {
            $tail = function_exists('mb_substr') ? mb_substr($head, -$slen, $slen, 'UTF-8') : substr($head, -$slen);
            if ($tail === $suffix) {
                $candidate = function_exists('mb_substr')
                    ? mb_substr($head, 0, $len - $slen, 'UTF-8')
                    : substr($head, 0, $len - $slen);
            }
        }

        // ① 新带前缀形式：前缀本身 ⇒ 协会本级；前缀 + 名录内机构名 ⇒ 该机构。
        $clen = function_exists('mb_strlen') ? mb_strlen($candidate, 'UTF-8') : strlen($candidate);
        if ($clen >= $plen && $prefix !== '') {
            $lead = function_exists('mb_substr') ? mb_substr($candidate, 0, $plen, 'UTF-8') : substr($candidate, 0, $plen);
            if ($lead === $prefix) {
                $rest = $clen > $plen
                    ? (function_exists('mb_substr') ? mb_substr($candidate, $plen, $clen - $plen, 'UTF-8') : substr($candidate, $plen))
                    : '';
                if ($rest === '') {
                    // 协会本级（前缀本身也必须在名录内，否则不认）
                    return in_array($prefix, $names, true) ? $prefix : '';
                }
                return in_array($rest, $names, true) ? $rest : '';
            }
        }

        // ②③ 历史无前缀形式与机关纯名本身也是合法红头。
        return in_array($candidate, $names, true) ? $candidate : '';
    }
}

if (!function_exists('zw_doc_head_is_allowed')) {
    /**
     * 红头是否合法（给定机关时必须是该机关的两项候选之一；未给机关时须能归一到名录内机关）。
     *
     * @param string $head 红头文字。
     * @param string $org  机关名（可空）。
     * @return bool 空值放行。
     */
    function zw_doc_head_is_allowed($head, $org = '') {
        $head = trim((string) $head);
        if ($head === '') {
            return true;
        }
        $org = trim((string) $org);
        if ($org !== '') {
            return in_array($head, zw_doc_org_head_options($org), true);
        }

        return zw_doc_head_to_org($head) !== '';
    }
}

if (!function_exists('zw_doc_org_apply')) {
    /**
     * A1 口径（TASK-1271 用户拍板）：由「发文机关」推导三处的**规范取值**。
     *
     * TASK-1280（CHG-157）修订署名口径：
     * - 发文机关署名 = `zw_doc_sign_org()` 的产物（**分支机构必须带协会全称前缀**，不含「文件」）；
     * - 发文部门 = 机关名**本身**（台账，**不加前缀**，否则会破坏与分支机构名录/`branch_id` 的关联）；
     * - 发文机关标志 = 提交的红头若属于该机关候选则沿用，否则回落默认「机关+文件」。
     *
     * @param string $org  机关名。
     * @param string $head 提交的红头（可空）。
     * @return array{org_name:string,sign_org:string,department:string}
     */
    function zw_doc_org_apply($org, $head = '') {
        $org  = trim((string) $org);
        $head = trim((string) $head);
        if ($org === '' || !in_array($org, zw_doc_department_names(), true)) {
            return array('org_name' => $head, 'sign_org' => '', 'department' => '');
        }
        if (!in_array($head, zw_doc_org_head_options($org), true)) {
            $head = zw_doc_org_to_head($org);
        }

        return array(
            'org_name'   => $head,
            'sign_org'   => zw_doc_sign_org($org),
            'department' => $org,
        );
    }
}

if (!function_exists('zw_doc_sign_org')) {
    /**
     * 落款**署名**（TASK-1280 / CHG-157 用户报缺陷修复）：分支机构署名**必须**带协会全称前缀。
     *
     * 用户缺陷（附实机截图）：落款只写「孔子文化传承工作委员会」，**缺**「中国传统文化促进会」。
     * 口径（与红头前缀同源，唯一出处 = `zw_doc_head_prefix()`）：
     *  - 协会本级 → `中国传统文化促进会`（**不重复加前缀**）；
     *  - 分支机构 → `中国传统文化促进会{机构名}`（如 `中国传统文化促进会孔子文化传承工作委员会`）；
     *  - 非法 / 空 → **空串**（交由调用方的名录校验拒绝）；
     *  - **不含**「文件」二字（落款须与印章一致）。
     *
     * ⚠️ 只用于**署名**：发文部门（台账字段）仍取机构名本身，**不加前缀**（否则破坏分支机构关联）。
     *
     * @param string $org 机关名。
     * @return string
     */
    function zw_doc_sign_org($org) {
        $org = trim((string) $org);
        if ($org === '' || !in_array($org, zw_doc_department_names(), true)) {
            return '';
        }
        $prefix = function_exists('zw_doc_head_prefix') ? (string) zw_doc_head_prefix() : '中国传统文化促进会';
        if ($prefix === '' || $org === $prefix) {
            return $org;
        }

        return $prefix . $org;
    }
}

if (!function_exists('zw_doc_sign_org_normalize')) {
    /**
     * 署名**归一**：把任意历史署名（可能是机构纯名、可能是「机构+文件」、可能已带前缀）统一为新口径。
     *
     * 用途：编辑态回填 / 台账回退时，存量记录的 `sign_org` 可能仍是 CHG-157 之前的机构纯名
     * （如 `孔子文化传承工作委员会`）⇒ 必须补前缀。取值顺序：
     *  ① 已在名录内 ⇒ 直接 `zw_doc_sign_org()`；
     *  ② 由红头反查 `zw_doc_head_to_org()` 命中名录 ⇒ `zw_doc_sign_org()`；
     *  ③ 都无法识别（自由文本/秘书处等）⇒ **原样保留**（不臆造、不丢数据）。
     *
     * @param string $value 现存署名文本。
     * @return string
     */
    function zw_doc_sign_org_normalize($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $org = function_exists('zw_doc_head_to_org') ? (string) zw_doc_head_to_org($value) : '';
        if ($org === '') {
            return $value;
        }

        return zw_doc_sign_org($org);
    }
}

if (!function_exists('zw_doc_import_column_index')) {
    /**
     * Excel 列名（A、B、AA）转 0 基索引。
     *
     * @param string $column 列名。
     * @return int
     */
    function zw_doc_import_column_index($column) {
        $column = strtoupper(preg_replace('/[^A-Z]/i', '', (string) $column));
        if ($column === '') {
            return -1;
        }
        $index = 0;
        for ($i = 0; $i < strlen($column); $i++) {
            $index = $index * 26 + (ord($column[$i]) - 64);
        }
        return $index - 1;
    }
}

if (!function_exists('zw_doc_import_load_xlsx')) {
    /**
     * 读取 xlsx 工作簿为 工作簿名 => 行数组。
     *
     * @param string $file 文件路径。
     * @return array|WP_Error
     */
    function zw_doc_import_load_xlsx($file) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('zw_doc_import_zip_missing', 'PHP ZipArchive 扩展不可用，无法读取 xlsx。');
        }
        if (!is_readable($file)) {
            return new WP_Error('zw_doc_import_file_missing', 'Excel 文件不存在或不可读：' . $file);
        }

        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return new WP_Error('zw_doc_import_zip_open', '无法打开 Excel 文件：' . $file);
        }

        $read = function ($entry) use ($zip) {
            $content = $zip->getFromName($entry);
            return $content === false ? '' : $content;
        };
        $load_xml = function ($xml) {
            if ($xml === '') {
                return null;
            }
            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = false;
            if (!@$dom->loadXML($xml)) {
                return null;
            }
            return $dom;
        };
        $xpath = function (DOMDocument $dom, $query, DOMNode $context = null) {
            $xp = new DOMXPath($dom);
            return $context ? $xp->query($query, $context) : $xp->query($query);
        };

        // 共享字符串。
        $shared = array();
        $shared_dom = $load_xml($read('xl/sharedStrings.xml'));
        if ($shared_dom) {
            foreach ($xpath($shared_dom, '//*[local-name()="si"]') as $si) {
                $text = '';
                foreach ($xpath($shared_dom, './/*[local-name()="t"]', $si) as $t) {
                    $text .= (string) $t->textContent;
                }
                $shared[] = $text;
            }
        }

        // 工作簿关系。
        $rels = array();
        $rels_dom = $load_xml($read('xl/_rels/workbook.xml.rels'));
        if ($rels_dom) {
            foreach ($xpath($rels_dom, '//*[local-name()="Relationship"]') as $rel) {
                $id = (string) $rel->getAttribute('Id');
                if ($id === '') {
                    $id = (string) $rel->getAttributeNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Id');
                }
                $rels[$id] = (string) $rel->getAttribute('Target');
            }
        }

        $workbook_dom = $load_xml($read('xl/workbook.xml'));
        if (!$workbook_dom) {
            $zip->close();
            return new WP_Error('zw_doc_import_workbook_invalid', 'Excel 工作簿结构损坏。');
        }

        $sheets = array();
        foreach ($xpath($workbook_dom, '//*[local-name()="sheet"]') as $sheet) {
            $name = (string) $sheet->getAttribute('name');
            $rid  = (string) $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if ($rid === '') {
                foreach ($sheet->attributes as $attribute) {
                    if (substr($attribute->nodeName, -3) === ':id' || $attribute->localName === 'id') {
                        $rid = (string) $attribute->nodeValue;
                        break;
                    }
                }
            }
            if ($name === '' || $rid === '' || !isset($rels[$rid])) {
                continue;
            }
            $target = ltrim((string) $rels[$rid], '/');
            if (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . $target;
            }
            $sheets[$name] = $target;
        }

        $out = array();
        foreach ($sheets as $name => $entry) {
            $sheet_dom = $load_xml($read($entry));
            if (!$sheet_dom) {
                continue;
            }
            $rows = array();
            foreach ($xpath($sheet_dom, '//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                $cells = array();
                foreach ($xpath($sheet_dom, './*[local-name()="c"]', $row) as $cell) {
                    $ref = (string) $cell->getAttribute('r');
                    $column_index = -1;
                    if (preg_match('/^([A-Z]+)\d+$/i', $ref, $col_match)) {
                        $column_index = zw_doc_import_column_index($col_match[1]);
                    }
                    if ($column_index < 0) {
                        continue;
                    }

                    $type  = (string) $cell->getAttribute('t');
                    $value = '';
                    if ($type === 'inlineStr') {
                        foreach ($xpath($sheet_dom, './/*[local-name()="t"]', $cell) as $t) {
                            $value .= (string) $t->textContent;
                        }
                    } else {
                        $v_nodes = $xpath($sheet_dom, './*[local-name()="v"]', $cell);
                        if ($v_nodes->length > 0) {
                            $value = (string) $v_nodes->item(0)->textContent;
                        }
                    }

                    if ($type === 's') {
                        $shared_index = (int) $value;
                        $value        = isset($shared[$shared_index]) ? $shared[$shared_index] : '';
                    }
                    $cells[$column_index] = $value;
                }
                if (empty($cells)) {
                    continue;
                }
                $max_index = max(array_keys($cells));
                $line      = array();
                for ($i = 0; $i <= $max_index; $i++) {
                    $line[$i] = isset($cells[$i]) ? (string) $cells[$i] : '';
                }
                $rows[] = $line;
            }
            $out[$name] = $rows;
        }

        $zip->close();
        return $out;
    }
}

if (!function_exists('zw_doc_import_header_map')) {
    /**
     * 查找表头行与字段索引。
     *
     * @param array $rows 工作表行。
     * @return array{0:int,1:array<string,int>}
     */
    function zw_doc_import_header_map($rows) {
        $field_labels = array(
            '发文类型'   => 'source_type',
            '发文日期'   => 'issue_date',
            '文号'       => 'number_raw',
            '文件名称'   => 'title',
            '部门'       => 'department',
            '审核'       => 'reviewer_name',
            '分管领导'   => 'leader_name',
            '备注'       => 'ignored_remark',
            '牌证编号'   => 'ignored_cert',
            '截止日期'   => 'ignored_deadline',
            '快递地址'   => 'ignored_address',
        );
        foreach (array_slice((array) $rows, 0, 8, true) as $row_index => $row) {
            $map = array();
            foreach ((array) $row as $column_index => $value) {
                $label = trim((string) $value);
                if ($label !== '' && isset($field_labels[$label])) {
                    $map[$field_labels[$label]] = (int) $column_index;
                }
            }
            if (isset($map['number_raw'], $map['title'])) {
                return array((int) $row_index, $map);
            }
        }
        return array(-1, array());
    }
}

if (!function_exists('zw_doc_import_align_row')) {
    /**
     * 将一行 Excel 数据按「文号」位置对齐，兼容日期/文号错位的行。
     *
     * @param array $row        原始行。
     * @param array $header_map 表头映射。
     * @return array<string,string>
     */
    function zw_doc_import_align_row($row, $header_map) {
        $row = array_values((array) $row);
        $number_index = -1;
        foreach ($row as $index => $value) {
            $text = trim((string) $value);
            if ($text !== '' && preg_match('/[〔｛﹝\[]?\s*\d{4}\s*[〕｝﹞\]]/u', $text)) {
                $number_index = (int) $index;
                break;
            }
        }
        if ($number_index < 0 && isset($header_map['number_raw'])) {
            $number_index = (int) $header_map['number_raw'];
        }

        $get = function ($key, $default = '') use ($row, $header_map) {
            if (isset($header_map[$key]) && array_key_exists((int) $header_map[$key], $row)) {
                return (string) $row[(int) $header_map[$key]];
            }
            return $default;
        };

        $out = array(
            'source_type'   => $get('source_type'),
            'issue_date'    => $get('issue_date'),
            'number_raw'    => $number_index >= 0 && isset($row[$number_index]) ? (string) $row[$number_index] : $get('number_raw'),
            'title'         => $get('title'),
            'department'    => $get('department'),
            'reviewer_name' => $get('reviewer_name'),
            'leader_name'   => $get('leader_name'),
        );

        if ($number_index >= 0) {
            if ($out['number_raw'] === '') {
                $out['number_raw'] = (string) ($row[$number_index] ?? '');
            }
            // 以文号单元格为锚点向后取相邻列，兼容整行错位的情况；
            // 相邻列为空时再退回表头映射。
            if (isset($row[$number_index + 1]) && trim((string) $row[$number_index + 1]) !== '') {
                $out['title'] = (string) $row[$number_index + 1];
            }
            if (isset($row[$number_index + 2]) && trim((string) $row[$number_index + 2]) !== '') {
                $out['department'] = (string) $row[$number_index + 2];
            }
            // 只有表头确认存在审核/分管领导列时，才使用锚点后的相邻列。
            if (isset($header_map['reviewer_name']) && isset($row[$number_index + 3]) && trim((string) $row[$number_index + 3]) !== '') {
                $out['reviewer_name'] = (string) $row[$number_index + 3];
            }
            if (isset($header_map['leader_name']) && isset($row[$number_index + 4]) && trim((string) $row[$number_index + 4]) !== '') {
                $out['leader_name'] = (string) $row[$number_index + 4];
            }
        }

        // 日期为空时，从文号左侧寻找最接近的日期单元格。
        if ($out['issue_date'] === '' && $number_index > 0) {
            for ($i = $number_index - 1; $i >= 0; $i--) {
                if (zw_doc_import_is_excel_date_value($row[$i])) {
                    $out['issue_date'] = (string) $row[$i];
                    break;
                }
            }
        }

        // 发文类型为空时，取文号左侧第一个非日期文本。
        if ($out['source_type'] === '') {
            for ($i = 0; $i < $number_index; $i++) {
                $text = trim((string) $row[$i]);
                if ($text !== '' && !zw_doc_import_is_excel_date_value($text)) {
                    $out['source_type'] = $text;
                    break;
                }
            }
        }

        return $out;
    }
}

if (!function_exists('zw_doc_import_prepare_workbook')) {
    /**
     * 读取并标准化附件工作簿。
     *
     * @param string $file    xlsx 路径。
     * @param array  $options 选项：sheets / source。
     * @return array|WP_Error
     */
    function zw_doc_import_prepare_workbook($file, $options = array()) {
        $options = wp_parse_args($options, array(
            'sheets' => array('2023及以前', '2024', '2025', '2026'),
            'source' => function_exists('wp_basename') ? wp_basename($file) : basename($file),
        ));

        $workbook = zw_doc_import_load_xlsx($file);
        if (is_wp_error($workbook)) {
            return $workbook;
        }

        $rows   = array();
        $errors = array();
        foreach ((array) $options['sheets'] as $sheet_name) {
            if (!isset($workbook[$sheet_name])) {
                $errors[] = '未找到工作表：' . $sheet_name;
                continue;
            }
            $sheet_rows      = $workbook[$sheet_name];
            list($header_index, $header_map) = zw_doc_import_header_map($sheet_rows);
            if ($header_index < 0 || empty($header_map)) {
                $errors[] = '工作表缺少表头：' . $sheet_name;
                continue;
            }
            foreach ($sheet_rows as $row_index => $row) {
                if ($row_index <= $header_index) {
                    continue;
                }
                $all_text = implode('', array_map('strval', (array) $row));
                if (trim($all_text) === '') {
                    continue;
                }
                if (strpos($all_text, '注：自2023年3月27日起') !== false) {
                    continue;
                }

                $aligned = zw_doc_import_align_row($row, $header_map);
                $number  = trim((string) $aligned['number_raw']);
                if ($number === '' || !preg_match('/\d{4}/u', $number)) {
                    continue;
                }

                $rows[] = array(
                    'sheet'         => (string) $sheet_name,
                    'row'           => (int) $row_index + 1,
                    'source_type'   => zw_doc_import_clean_text($aligned['source_type']),
                    'issue_date'    => zw_doc_import_excel_date($aligned['issue_date']),
                    'number_raw'    => $number,
                    'title'         => zw_doc_import_clean_text(preg_replace('/\s+/u', ' ', (string) $aligned['title'])),
                    'department'    => zw_doc_import_clean_text($aligned['department']),
                    'reviewer_name' => zw_doc_import_clean_text($aligned['reviewer_name']),
                    'leader_name'   => zw_doc_import_clean_text($aligned['leader_name']),
                    'source'        => (string) $options['source'],
                );
            }
        }

        return array(
            'rows'   => $rows,
            'errors' => $errors,
        );
    }
}

if (!function_exists('zw_doc_import_register_doc_types')) {
    /**
     * 将导入中出现的文种合并进文号规则。
     *
     * @param array $types 文种列表。
     * @return array{added:array,all:array}
     */
    function zw_doc_import_register_doc_types($types, $user_id = 0) {
        $types = array_values(array_filter(array_unique(array_map('strval', (array) $types))));
        if (empty($types) || !function_exists('get_option') || !function_exists('update_option')) {
            return array('added' => array(), 'all' => $types);
        }

        if (function_exists('zw_doc_number_settings')) {
            $settings = zw_doc_number_settings();
        } else {
            $settings = get_option('zw_doc_number_settings', array());
        }
        if (!is_array($settings)) {
            $settings = array();
        }
        $current = isset($settings['doc_types']) && is_array($settings['doc_types']) ? $settings['doc_types'] : array('函', '字', '通知');
        $added   = array_values(array_diff($types, $current));
        $all     = array_values(array_unique(array_merge($current, $types)));
        if (!empty($added)) {
            // TASK-1396：文种字典的写入改走**唯一写入口** `zw_doc_dict_save('number', …)`。
            // 传入的是**完整 settings**（`zw_doc_number_settings()` 的归一结果 + 合并后的文种）⇒
            // `zw_doc_sanitize_number_settings()` 的清洗结果与改写前**逐字一致**；
            // `updated_by` / `updated_at` 以 overrides 显式给定，保持导入路径原有的留痕口径
            //（原先写 `$user_id` 与 `date()`，不能改写成「当前用户 / local 时间」）。
            // 唯一写入口不可用时 **fail-closed 不写库**（与上方 option API 缺失时的既有降级同型）。
            if (!function_exists('zw_doc_dict_save')) {
                return array('added' => array(), 'all' => $types);
            }
            $settings['doc_types']   = $all;
            $settings['updated_by']  = $user_id > 0 ? (int) $user_id : (function_exists('get_current_user_id') ? (int) get_current_user_id() : 0);
            $settings['updated_at']  = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
            zw_doc_dict_save('number', $settings, 'import', array(
                'updated_by' => $settings['updated_by'],
                'updated_at' => $settings['updated_at'],
            ));
        }

        return array('added' => $added, 'all' => $all);
    }
}

if (!function_exists('zw_doc_import_number_map')) {
    /**
     * 读取现有文号台账：full_number => 行。
     *
     * @return array<string,array>
     */
    function zw_doc_import_number_map() {
        global $wpdb;
        if (!function_exists('zw_doc_table') || !is_object($wpdb)) {
            return array();
        }
        $table = zw_doc_table('numbers');
        $rows  = $wpdb->get_results("SELECT id,doc_year,doc_type,seq,full_number,status,document_id FROM {$table}", ARRAY_A);
        $map   = array();
        foreach ((array) $rows as $row) {
            if (!empty($row['full_number'])) {
                $map[(string) $row['full_number']] = $row;
            }
        }
        return $map;
    }
}

if (!function_exists('zw_doc_import_existing_key')) {
    /**
     * 按导入幂等键查已有发文。
     *
     * @param string $key 幂等键。
     * @return int
     */
    function zw_doc_import_existing_key($key) {
        if ($key === '' || !function_exists('get_posts')) {
            return 0;
        }
        $posts = get_posts(array(
            'post_type'      => 'zw_official_doc',
            'post_status'    => 'private',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_zw_doc_import_key',
            'meta_value'     => $key,
            'suppress_filters' => true,
        ));
        return !empty($posts) ? (int) $posts[0] : 0;
    }
}

if (!function_exists('zw_doc_import_create_document')) {
    /**
     * 写入一篇历史发文。
     *
     * @param array  $row       标准化行。
     * @param array  $entry     解析后的文号条目。
     * @param array  $department 部门匹配结果。
     * @param array  $args      执行参数。
     * @param string $key       幂等键。
     * @return int|WP_Error
     */
    function zw_doc_import_create_document($row, $entry, $department, $args, $key) {
        $title = trim((string) $row['title']);
        if ($title === '') {
            $title = '历史发文 ' . (string) $entry['full_number'];
        }
        $author_id = isset($args['imported_by']) ? (int) $args['imported_by'] : 0;
        if ($author_id <= 0 && function_exists('get_current_user_id')) {
            $author_id = (int) get_current_user_id();
        }
        if ($author_id <= 0) {
            $author_id = 1;
        }

        $issue_date = isset($row['issue_date']) ? (string) $row['issue_date'] : '';
        $post_date  = $issue_date !== '' ? $issue_date . ' 00:00:00' : current_time('mysql');
        $now        = current_time('mysql');

        $post_id = wp_insert_post(array(
            'post_type'   => 'zw_official_doc',
            'post_status' => 'private',
            'post_title'  => $title,
            'post_author' => $author_id,
            'post_date'   => $post_date,
        ), true);

        if (is_wp_error($post_id) || !$post_id) {
            return is_wp_error($post_id) ? $post_id : new WP_Error('zw_doc_import_create_failed', '历史发文创建失败。');
        }

        $department_name = isset($department['department']) ? (string) $department['department'] : '';
        $branch_id       = isset($department['branch_id']) ? (int) $department['branch_id'] : 0;

        $meta = array(
            '_zw_doc_number'          => (string) $entry['full_number'],
            '_zw_doc_number_id'       => 0,
            '_zw_doc_summary'         => '',
            '_zw_doc_status'          => 'approved',
            '_zw_doc_reviewer_id'     => 0,
            '_zw_doc_cc_ids'          => array(),
            '_zw_doc_attachment_ids'  => array(),
            '_zw_doc_review_note'     => '历史数据导入',
            '_zw_doc_idempotency_key' => '',
            '_zw_doc_updated_by'      => $author_id,
            '_zw_doc_created_at'      => $post_date,
            '_zw_doc_updated_at'      => $now,
            '_zw_doc_approved_at'     => $post_date,
            '_zw_doc_issue_date'      => $issue_date,
            '_zw_doc_department'      => $department_name,
            '_zw_doc_branch_id'       => $branch_id,
            '_zw_doc_remark'          => (string) $row['source_type'],
            // TASK-1347（用户 2026-10-09 拍板）：导入（历史发文）的「审核人」**一律写死**为固定短语 ——
            // 即使表格「审核」列有真实人名（如「王海涛」）也**丢弃**，以表达「历史记录、不关联系统人员」。
            // 在线撰写侧已把「审核（历史记录，不关联系统人员）」并入系统人员选择（`doc_reviewer_id`）。
            '_zw_doc_reviewer_name'   => zw_doc_reviewer_legacy_phrase(),
            '_zw_doc_leader_name'     => (string) $row['leader_name'],
            '_zw_doc_source_number'   => (string) $row['number_raw'],
            '_zw_doc_source'          => (string) $args['source'],
            '_zw_doc_import_key'      => (string) $key,
        );

        foreach ($meta as $meta_key => $meta_value) {
            update_post_meta((int) $post_id, $meta_key, $meta_value);
        }

        if (function_exists('zw_doc_log_event')) {
            zw_doc_log_event((int) $post_id, 'imported', $author_id, '', 'approved', '历史发文导入：' . (string) $row['number_raw']);
        }

        return (int) $post_id;
    }
}

if (!function_exists('zw_doc_import_execute')) {
    /**
     * 执行历史发文导入。
     *
     * @param array $rows    标准化行。
     * @param bool  $dry_run 是否只预检。
     * @param array $args    执行参数。
     * @return array
     */
    function zw_doc_import_execute($rows, $dry_run = true, $args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'source'      => '统计-发文任命工作证顾问统计表.xlsx',
            'imported_by' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
        ));

        $summary = array(
            'rows_total'          => 0,
            'rows_skipped'        => 0,
            'documents_planned'   => 0,
            'documents_created'   => 0,
            'documents_skipped'   => 0,
            'numbers_planned'     => 0,
            'numbers_created'     => 0,
            'numbers_skipped'     => 0,
            'numbers_conflict'    => 0,
            'department_matched'  => 0,
            'department_unmatched'=> 0,
            'doc_types_added'     => array(),
            'errors'              => array(),
        );
        $items    = array();
        $max_seq  = array();
        $type_map = array();
        $parsed_rows = array();

        foreach ((array) $rows as $row) {
            $summary['rows_total']++;
            $entries = zw_doc_import_parse_number_expression(isset($row['number_raw']) ? $row['number_raw'] : '');
            if (empty($entries)) {
                $summary['rows_skipped']++;
                $summary['errors'][] = '第 ' . (isset($row['sheet']) ? $row['sheet'] : '') . ' 行 ' . (isset($row['row']) ? $row['row'] : '') . '：无法解析文号「' . (isset($row['number_raw']) ? $row['number_raw'] : '') . '」';
                continue;
            }
            foreach ($entries as $entry) {
                $type_map[(string) $entry['type']] = true;
            }
            $parsed_rows[] = array('row' => $row, 'entries' => $entries);
        }

        if (!$dry_run) {
            $registered = zw_doc_import_register_doc_types(array_keys($type_map), isset($args['imported_by']) ? (int) $args['imported_by'] : 0);
            $summary['doc_types_added'] = $registered['added'];
        } else {
            $settings = function_exists('zw_doc_number_settings') ? zw_doc_number_settings() : array();
            $current  = isset($settings['doc_types']) && is_array($settings['doc_types']) ? $settings['doc_types'] : array('函', '字', '通知');
            $summary['doc_types_added'] = array_values(array_diff(array_keys($type_map), $current));
        }

        $number_map = (function_exists('zw_doc_table') && is_object($wpdb)) ? zw_doc_import_number_map() : array();

        foreach ($parsed_rows as $parsed) {
            $row     = $parsed['row'];
            $entries = $parsed['entries'];
            $dept    = zw_doc_import_find_department(isset($row['department']) ? $row['department'] : '');
            if (!empty($dept['matched'])) {
                $summary['department_matched']++;
            } else {
                $summary['department_unmatched']++;
            }

            foreach ($entries as $entry) {
                $full_number = (string) $entry['full_number'];
                $key         = 'doc-history|' . (string) $args['source'] . '|' . (string) $row['sheet'] . '|' . (int) $row['row'] . '|' . $full_number;

                if (isset($number_map[$full_number])) {
                    $summary['numbers_skipped']++;
                    if (!empty($number_map[$full_number]['document_id'])) {
                        $summary['documents_skipped']++;
                    }
                    continue;
                }

                $existing_key = zw_doc_import_existing_key($key);
                if ($existing_key > 0) {
                    $summary['numbers_skipped']++;
                    $summary['documents_skipped']++;
                    continue;
                }

                $summary['numbers_planned']++;
                if ($dry_run) {
                    $summary['documents_planned']++;
                    continue;
                }

                $post_id = zw_doc_import_create_document($row, $entry, $dept, $args, $key);
                if (is_wp_error($post_id) || !$post_id) {
                    $message = is_wp_error($post_id) ? $post_id->get_error_message() : '创建发文失败';
                    $summary['errors'][] = $full_number . '：' . $message;
                    continue;
                }

                $number_ok = $wpdb->insert(
                    zw_doc_table('numbers'),
                    array(
                        'doc_year'    => (int) $entry['year'],
                        'doc_type'    => (string) $entry['type'],
                        'seq'         => (int) $entry['seq'],
                        'full_number' => $full_number,
                        'status'      => 'used',
                        'document_id' => (int) $post_id,
                        'operator_id' => (int) $args['imported_by'],
                        'reason'      => '历史数据导入',
                        'created_at'  => current_time('mysql'),
                        'updated_at'  => current_time('mysql'),
                    ),
                    array('%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s')
                );

                if ($number_ok === false) {
                    $summary['numbers_conflict']++;
                    $summary['errors'][] = $full_number . '：文号台账写入冲突，已保留发文 #' . (int) $post_id;
                    continue;
                }

                $number_map[$full_number] = array('document_id' => (int) $post_id, 'status' => 'used');
                $summary['documents_created']++;
                $summary['numbers_created']++;
                $items[] = array('document_id' => (int) $post_id, 'number' => $full_number);
                if (!isset($max_seq[(int) $entry['year']])) {
                    $max_seq[(int) $entry['year']] = array();
                }
                $max_seq[(int) $entry['year']][(string) $entry['type']] = max(
                    isset($max_seq[(int) $entry['year']][(string) $entry['type']]) ? (int) $max_seq[(int) $entry['year']][(string) $entry['type']] : 0,
                    (int) $entry['seq']
                );
            }
        }

        if (!$dry_run && function_exists('zw_doc_bump_counter_to')) {
            foreach ($max_seq as $year => $types) {
                foreach ((array) $types as $type => $seq) {
                    zw_doc_bump_counter_to((int) $year, (string) $type, (int) $seq);
                }
            }
        }

        $summary['items'] = $items;
        return $summary;
    }
}
