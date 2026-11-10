<?php
/** Preview/confirm and API destination tests without a core checkout or paid provider. */
class PluginManager {
    public static bool $enabled=true;
    public static array $items=[];
    public static bool $loseClaim=false;
    public static string $token='apbx_test_token_for_controlled_transport';
    public static function isEnabled($slug): bool { return self::$enabled; }
    public static function getSetting($slug,$key,$default=''): string { return self::$token; }
    public static function kvSet($slug,$collection,$key,$value): void { self::$items[$collection][$key]=['value'=>$value]; }
    public static function kvGet($slug,$collection,$key) { return self::$items[$collection][$key]??null; }
    public static function kvDelete($slug,$collection,$key): bool {
        if (self::$loseClaim || !isset(self::$items[$collection][$key])) { return false; }
        unset(self::$items[$collection][$key]); return true;
    }
    public static function kvList($slug,$collection,$limit): array { return array_values(self::$items[$collection]??[]); }
}
function getUserLanguage(): string { return 'en'; }
function verifyCSRFToken($token): bool { return $token==='test-csrf'; }
function uiTableEmptyRow(...$args): string { return '<tr><td>Empty</td></tr>'; }
function check($ok,$why): void { if(!$ok){throw new RuntimeException($why);} }
function render(array $post): string {
    $_SERVER['REQUEST_METHOD']='POST';$_POST=$post;
    $plugin=['enabled'=>PluginManager::$enabled];$settings=[];$csrf_token='test-csrf';
    ob_start();require dirname(__DIR__).'/admin/page.php';return ob_get_clean();
}
require dirname(__DIR__).'/lib/AssistantApi.php';
$requests=[];
AssistantApi::$http=static function($method,$url,$headers,$body) use (&$requests): array {
    $requests[]=compact('method','url','headers','body');return [200,'{"success":true,"data":[]}'];
};
render(['csrf_token'=>'test-csrf','assistant_prompt'=>'List queues']);
check(count(PluginManager::$items['drafts'])===1,'Preview did not save a plan');
check($requests===[],'Preview executed an API request');
$key=array_key_first(PluginManager::$items['drafts']);
render(['csrf_token'=>'wrong','execute_draft_id'=>$key]);
check($requests===[],'Invalid CSRF executed a request');
PluginManager::$enabled=false;
render(['csrf_token'=>'test-csrf','execute_draft_id'=>$key]);
check($requests===[],'Disabled plugin executed a request');
PluginManager::$enabled=true;
PluginManager::$loseClaim=true;
render(['csrf_token'=>'test-csrf','execute_draft_id'=>$key]);
check($requests===[],'Concurrent lost claim executed API');
PluginManager::$loseClaim=false;
render(['csrf_token'=>'test-csrf','execute_draft_id'=>$key]);
check(count($requests)===1,'Confirmation did not execute');
check($requests[0]['url']==='https://127.0.0.1/api/v1/queues','Request escaped loopback API');
check(!isset(PluginManager::$items['drafts'][$key]),'Completed draft not consumed');
check(count(PluginManager::$items['history'])===1,'History not saved');
render(['csrf_token'=>'test-csrf','execute_draft_id'=>$key]);
check(count($requests)===1,'Replayed consumed draft executed');
foreach(['https://attacker.example/api/v1/queues','//attacker.example/x','/api/v1/../system','/api/v1/queues#fragment',"/api/v1/queues\r\nX: test"] as $path) {
    check(AssistantApi::execute('GET',$path)['status_code']===400,'Unsafe path accepted');
}
check(AssistantApi::execute('CONNECT','/api/v1/queues')['status_code']===400,'Unsupported method accepted');
check(count($requests)===1,'Rejected requests reached transport');
PluginManager::$token='';check(AssistantApi::execute('GET','/api/v1/queues')['status_code']===403,'Missing token accepted');
PluginManager::$token='test';
render(['csrf_token'=>'test-csrf','assistant_prompt'=>'apply sync']);
$draft=end(PluginManager::$items['drafts']);
check($draft['value']['path']==='/api/v1/system/apply-sync','Sync plan uses nonexistent endpoint');
AssistantApi::$http=static fn()=>[0,''];
check(AssistantApi::execute('GET','/api/v1/queues')['status_code']===502,'Transport failure reported as success');
$m=json_decode(file_get_contents(dirname(__DIR__).'/plugin.json'),true);
check(in_array('queues:write',$m['scopes'],true),'Queue API scope missing');
echo "OK: preview, confirmation, CSRF, disabled state, draft replay, fixed API destination and failures\n";
