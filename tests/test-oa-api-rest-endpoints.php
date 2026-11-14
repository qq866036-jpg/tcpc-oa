<?php
/**
 * OA-2505：REST 只读端点**行为**回归（降级 / 越权 / 脱敏 / 铁律 19）。
 *
 * 覆盖（三个新端点）：
 *  - `GET /legal/{doc}`：编号非法 400；`bhzc` 404（铁律 19）；不可用 503；公开文档回正文/哈希；内部文档 404。
 *  - `GET /doc/inbox`：未登录 401；查询器缺失 ⇒ 200 + available=false + 空列表；正常透传 items/total/pages/is_admin。
 *  - `GET /files/{id}`：编号非法 400 / 未登录 401 / 存储缺失 503 / 不存在 404 / 非本人 403 / 本人或管理员 200，
 *    且响应**不含 path / url / owner_user_id**（只回白名单元数据）。
 *
 * 运行：php tests/test-oa-api-rest-endpoints.php
 *
 * @package tcpc-oa
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

$GLOBALS['ga_uid'] = 7;
$GLOBALS['ga_admin'] = false;
$GLOBALS['ga_legal'] = array(
    'SRC-PRIVACY-POLICY' => array('version_no' => 'v12', 'effective_at' => '2026-10-25 14:05:13', 'body_hash' => 'abc', 'body_html' => '<p>P</p>'),
    'SRC-INTERNAL'       => array('version_no' => 'v1', 'effective_at' => '2026-01-01 00:00:00', 'body_hash' => 'x', 'body_html' => '<p>I</p>'),
);
$GLOBALS['ga_inbox'] = array('available' => true, 'items' => array(array('id' => 11)), 'total' => 1, 'pages' => 1, 'is_admin' => false);
$GLOBALS['ga_file'] = array('id' => 5, 'title' => '通知.pdf', 'mime' => 'application/pdf', 'size' => 12, 'kind' => 'document', 'scope' => 'private', 'exists' => true, 'owner_user_id' => 9, 'path' => '/secret/path.pdf', 'url' => 'https://x/secret');
$GLOBALS['ga_file_found'] = true;
$GLOBALS['ga_storage_missing'] = false;
$GLOBALS['ga_inbox_missing'] = false;
$GLOBALS['ga_rest_routes'] = array();

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct($code = '', $message = '', $data = null) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        public $params = array();
        public function __construct($params = array()) { $this->params = (array) $params; }
        public function get_param($key) { return isset($this->params[$key]) ? $this->params[$key] : null; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!function_exists('add_action')) { function add_action($h, $cb, $p = 10, $a = 1) { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return (int) $GLOBALS['ga_uid']; } }
if (!function_exists('current_user_can')) { function current_user_can($cap) { return (bool) $GLOBALS['ga_admin'] || $cap === 'read'; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return (int) $GLOBALS['ga_uid'] > 0; } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
if (!function_exists('register_rest_route')) {
    function register_rest_route($ns, $route, $args) { $GLOBALS['ga_rest_routes'][] = $ns . $route; return true; }
}
if (!function_exists('zw_legal_text_current')) {
    function zw_legal_text_current($doc) { return isset($GLOBALS['ga_legal'][$doc]) ? $GLOBALS['ga_legal'][$doc] : null; }
}
if (!function_exists('zw_legal_text_visibility')) {
    function zw_legal_text_visibility($doc) { return $doc === 'SRC-INTERNAL' ? 'internal' : 'public'; }
}
if (!function_exists('zw_oa_doc_inbox_query')) {
    function zw_oa_doc_inbox_query($uid, $page, $per = 10) { return $GLOBALS['ga_inbox_missing'] ? array('available' => false, 'reason' => 'x', 'items' => array(), 'total' => 0) : $GLOBALS['ga_inbox']; }
}
if (!function_exists('zw_oa_storage_get')) {
    function zw_oa_storage_get($id) { return ($GLOBALS['ga_file_found'] && (int) $id === 5) ? $GLOBALS['ga_file'] : array(); }
}

require_once dirname(__DIR__) . '/includes/api/oa-api.php';

$GLOBALS['ga_pass'] = 0;
$GLOBALS['ga_fail'] = array();
function ga_ok($m) { $GLOBALS['ga_pass']++; echo "  ✓ {$m}\n"; }
function ga_bad($m) { $GLOBALS['ga_fail'][] = $m; fwrite(STDERR, "  ✗ {$m}\n"); }
function ga_chk($c, $m) { $c ? ga_ok($m) : ga_bad($m); }
function ga_err($r, $code, $status) {
    return $r instanceof WP_Error && $r->get_error_code() === $code
        && is_array($r->get_error_data()) && (int) ($r->get_error_data()['status'] ?? 0) === $status;
}
function ga_req($params) { return new WP_REST_Request($params); }

echo "== 0) 路由登记（5 条 GET）==\n";
tcpc_oa_api_register_rest();
ga_chk(count($GLOBALS['ga_rest_routes']) === 5, '注册 5 条路由（实际 ' . count($GLOBALS['ga_rest_routes']) . '）');
foreach (array('/manifest', '/health', '/legal/', '/doc/inbox', '/files/') as $needle) {
    $hit = false;
    foreach ($GLOBALS['ga_rest_routes'] as $r) { if (strpos($r, $needle) !== false) { $hit = true; } }
    ga_chk($hit, "含路由 {$needle}");
}

echo "== 1) legal/{doc} ==\n";
ga_chk(ga_err(tcpc_oa_api_rest_legal(ga_req(array('doc' => '../etc'))), 'LEGAL_DOC_INVALID', 400), '非法编号 ⇒ 400');
ga_chk(ga_err(tcpc_oa_api_rest_legal(ga_req(array('doc' => 'bhzc'))), 'LEGAL_DOC_FORBIDDEN', 404), 'bhzc ⇒ 404（铁律 19）');
$ok = tcpc_oa_api_rest_legal(ga_req(array('doc' => 'SRC-PRIVACY-POLICY')));
ga_chk(is_array($ok) && ($ok['version_no'] ?? '') === 'v12' && ($ok['body_html'] ?? '') === '<p>P</p>', '公开文档回正文/版本');
ga_chk(is_array($ok) && ($ok['degrade'] ?? '') === 'empty', 'degrade 声明 empty');
ga_chk(ga_err(tcpc_oa_api_rest_legal(ga_req(array('doc' => 'SRC-INTERNAL'))), 'LEGAL_DOC_NOT_PUBLIC', 404), '内部文档 ⇒ 404');
ga_chk(ga_err(tcpc_oa_api_rest_legal(ga_req(array('doc' => 'SRC-UNKNOWN'))), 'LEGAL_DOC_NOT_FOUND', 404), '未知文档 ⇒ 404');

echo "== 2) doc/inbox ==\n";
$inbox = tcpc_oa_api_rest_doc_inbox(ga_req(array('page' => 1, 'per_page' => 500)));
ga_chk(is_array($inbox) && $inbox['available'] === true && count($inbox['items']) === 1, '正常透传 items');
ga_chk(is_array($inbox) && !array_key_exists('per_page', $inbox), '响应不臆造 per_page 字段（无该键）');
ga_chk(is_array($inbox) && ($inbox['degrade'] ?? '') === 'empty', 'degrade 声明 empty');
$GLOBALS['ga_inbox_missing'] = true;
$inbox2 = tcpc_oa_api_rest_doc_inbox(ga_req(array()));
ga_chk(is_array($inbox2) && $inbox2['available'] === false && $inbox2['items'] === array(), '查询器缺失 ⇒ 空列表 + available=false');
$GLOBALS['ga_inbox_missing'] = false;
$saved = $GLOBALS['ga_uid'];
$GLOBALS['ga_uid'] = 0;
ga_chk(ga_err(tcpc_oa_api_rest_doc_inbox(ga_req(array())), 'DOC_INBOX_UNAUTHORIZED', 401), '未登录 ⇒ 401');
$GLOBALS['ga_uid'] = $saved;

echo "== 3) files/{id} ==\n";
ga_chk(ga_err(tcpc_oa_api_rest_file(ga_req(array('id' => 0))), 'FILE_ID_INVALID', 400), '编号非法 ⇒ 400');
ga_chk(ga_err(tcpc_oa_api_rest_file(ga_req(array('id' => 99))), 'FILE_NOT_FOUND', 404), '不存在 ⇒ 404');
ga_chk(ga_err(tcpc_oa_api_rest_file(ga_req(array('id' => 5))), 'FILE_FORBIDDEN', 403), '非本人 ⇒ 403');
$GLOBALS['ga_uid'] = 9;
$mine = tcpc_oa_api_rest_file(ga_req(array('id' => 5)));
ga_chk(is_array($mine) && ($mine['id'] ?? 0) === 5 && ($mine['title'] ?? '') === '通知.pdf', '本人可读元数据');
ga_chk(is_array($mine) && !array_key_exists('path', $mine) && !array_key_exists('url', $mine) && !array_key_exists('owner_user_id', $mine), '响应无 path/url/owner_user_id（脱敏）');
$GLOBALS['ga_admin'] = true;
ga_chk(is_array(tcpc_oa_api_rest_file(ga_req(array('id' => 5)))), '管理员可读');
$GLOBALS['ga_admin'] = false;
$GLOBALS['ga_storage_missing'] = true;
$saved_uid = $GLOBALS['ga_uid'];
$GLOBALS['ga_uid'] = 0;
ga_chk(ga_err(tcpc_oa_api_rest_file(ga_req(array('id' => 5))), 'FILE_UNAUTHORIZED', 401), '未登录 ⇒ 401');
$GLOBALS['ga_uid'] = $saved_uid;
$GLOBALS['ga_storage_missing'] = false;

echo "== 4) 降级：读源/存储缺失（子进程，避免与本进程的 stub 冲突）==\n";
$code = 'define("ABSPATH","/tmp/");'
    . 'class WP_Error{public $c;function __construct($c="",$m="",$d=null){$this->c=$c;}function get_error_code(){return $this->c;}function get_error_data(){return array("status"=>0);}}'
    . 'class WP_REST_Request{public $p;function __construct($p=array()){$this->p=$p;}function get_param($k){return $this->p[$k]??null;}}'
    . 'function is_wp_error($t){return $t instanceof WP_Error;}'
    . 'function add_action(){} function register_rest_route(){} function get_option($k,$d=false){return $d;}'
    . 'function get_current_user_id(){return 7;} function current_user_can($c){return $c==="read";} function is_user_logged_in(){return true;}'
    . 'require ' . var_export(dirname(__DIR__) . '/includes/api/oa-api.php', true) . ';'
    . '$r=tcpc_oa_api_rest_legal(new WP_REST_Request(array("doc"=>"SRC-PRIVACY-POLICY")));'
    . 'echo is_wp_error($r)?"LEGAL_ERR:".$r->get_error_code():"LEGAL_OK";'
    . 'echo "|";'
    . '$f=tcpc_oa_api_rest_file(new WP_REST_Request(array("id"=>5)));'
    . 'echo is_wp_error($f)?"FILE_ERR:".$f->get_error_code():"FILE_OK";';
exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out, $rc);
$o = trim(implode('', $out));
ga_chk($rc === 0 && $o === 'LEGAL_ERR:LEGAL_UNAVAILABLE|FILE_ERR:STORAGE_UNAVAILABLE', "无插件符号时降级 503（实际 {$o}）");

if ($GLOBALS['ga_fail']) {
    fwrite(STDERR, "FAIL: OA-2505 REST 端点断言未通过（{$GLOBALS['ga_pass']} 通过 / " . count($GLOBALS['ga_fail']) . " 失败）\n");
    exit(1);
}
echo "OA-2505 REST 只读端点通过（{$GLOBALS['ga_pass']} 项断言全绿）\n";
exit(0);
