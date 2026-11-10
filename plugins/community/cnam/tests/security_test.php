<?php
/** Isolated endpoint attack tests: no server, credentials, database or paid API. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/Cnam.php';
class PluginManager {
    public static string $token = '';
    public static function getSetting(string $slug, string $key): string { return self::$token; }
    public static function setSetting(string $slug, string $key, string $value, bool $secret): void {
        if (!$secret) { throw new RuntimeException('Token must be stored encrypted'); }
        self::$token = $value;
    }
}
class TestStatement extends PDOStatement {
    public function __construct(private mixed $value) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return $this->value; }
}
class TestDB extends PDO {
    public array $queries = [];
    public mixed $userName = false;
    public mixed $smsName = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries[] = $query;
        if (str_contains($query, 'FROM sys_users')) { return new TestStatement($this->userName); }
        if (str_contains($query, 'FROM plg_sms_contacts WHERE phone =')) { return new TestStatement($this->smsName); }
        throw new RuntimeException('Unexpected query: ' . $query);
    }
}
$db = new TestDB();
function getDB(): PDO { return $GLOBALS['db']; }
$count = 0;
function check(bool $condition, string $message): void {
    global $count;
    if (!$condition) { throw new RuntimeException($message); }
    $count++;
}
$handler = require dirname(__DIR__) . '/public/handler.php';
$calls = 0;
CnamProvider::$http = function($method, $url, $headers, $body, $timeout) use (&$calls): array {
    check($timeout === 1, 'Provider must finish before dialplan timeout');
    $calls++;
    return [200, '{"cnam":"TEST NAME"}'];
};
PluginManager::$token = str_repeat('a', 64);
$settings = ['provider'=>'bulkvs', 'bulkvs_api_key'=>'fake', 'check_phonebook'=>'0', 'cache_days'=>'0'];
$_GET = ['num'=>'12125550123'];
foreach ([['203.0.113.10',''], ['203.0.113.10',PluginManager::$token], ['127.0.0.1',''], ['::1','wrong']] as [$ip,$token]) {
    $_SERVER = ['REMOTE_ADDR'=>$ip, 'REQUEST_METHOD'=>'GET', 'HTTP_X_CNAM_TOKEN'=>$token, 'HTTP_X_FORWARDED_FOR'=>'127.0.0.1'];
    http_response_code(200);
    ob_start(); $handler('lookup',$settings); $out=ob_get_clean();
    check(http_response_code()===403 && $out==='' && $calls===0, 'Unauthorised request reached provider');
}
foreach (['127.0.0.1','::1'] as $ip) {
    $_SERVER=['REMOTE_ADDR'=>$ip,'REQUEST_METHOD'=>'GET','HTTP_X_CNAM_TOKEN'=>PluginManager::$token];
    http_response_code(200);
    ob_start();$handler('lookup',$settings);$out=ob_get_clean();
    check(http_response_code()===200 && $out==='TEST NAME','Authenticated loopback lookup failed');
}
$_SERVER=['REMOTE_ADDR'=>'127.0.0.1','REQUEST_METHOD'=>'GET','HTTP_AUTHORIZATION'=>'Basic '.base64_encode('cnam:'.PluginManager::$token)];
ob_start();$handler('lookup',$settings);$out=ob_get_clean();
check($out==='TEST NAME','Basic loopback authentication failed');
$db->userName='Local Person';
check(Cnam::lookupLocal($db,'1001')==='Local Person','Active user lookup failed');
$db->userName=false;$db->smsName='SMS Person';
check(Cnam::lookupLocal($db,'+12125550123')==='SMS Person','SMS phone column lookup failed');
$before=$calls;
check(Cnam::resolve($db,$settings,'bad input')['name']===null && $calls===$before,'Invalid number triggered billing');
$conf='';
function writePBXConf(string $file, string $content): void { $GLOBALS['conf']=$content; }
class AsteriskHelper { public static function reloadDialplan(): void {} }
$sync=require dirname(__DIR__).'/sync/Sync.php';
PluginManager::$token='';$sync('cnam',[]);
check(strlen(PluginManager::$token)===64,'Missing generated token');
$token=PluginManager::$token;$sync('cnam',[]);
check(PluginManager::$token===$token,'Apply unexpectedly rotated token');
check(str_contains($conf,'CURLOPT(userpwd)=cnam:'.$token),'Dialplan token missing');
check(str_contains($conf,'Set(CURLOPT(userpwd)=)'), 'Token header must be cleared after lookup');
echo "Passed $count CNAM security/integration checks\n";
