<?php
/** Real MySQL/InnoDB regression test. Uses temporary tables; never modifies installed plugin data.
 * SMS_TEST_DSN='mysql:host=127.0.0.1;dbname=sms_test' SMS_TEST_USER=... SMS_TEST_PASSWORD=... php tests/receive.php
 */
require dirname(__DIR__) . '/lib/Sms.php';
$dsn = getenv('SMS_TEST_DSN') ?: '';
if (!preg_match('/dbname=([a-z0-9_]*_test)(?:;|$)/D', $dsn)) {
    throw new RuntimeException('Supply an isolated *_test database in SMS_TEST_DSN');
}
$db = new PDO($dsn, getenv('SMS_TEST_USER') ?: '', getenv('SMS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function getDB(): PDO { global $db; return $db; }
$sql = preg_replace('/^--.*$/m', '', file_get_contents(dirname(__DIR__) . '/schema.sql'));
foreach (explode(';', $sql) as $statement) {
    if (trim($statement) !== '') { $db->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $statement)); }
}
$db->exec("INSERT INTO plg_sms_numbers (number, provider) VALUES ('+15550001111','bulkvs'),('+15550002222','bulkvs'),('+15550003333','voipms')");
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function incoming($to, $id, $media = []): array {
    return ['body' => json_encode(['From' => '15552223333', 'To' => $to, 'Message' => 'hello', 'MessageId' => $id, 'MediaUrls' => $media]), 'post' => [], 'query' => []];
}
$p = new SmsBulkvs([]); $settings = ['email_notify' => '0'];
check(Sms::receive($p, incoming('15550001111','same'),$settings) === 1, 'First message missing');
check(Sms::receive($p, incoming('15550001111','same'),$settings) === 0, 'Retry duplicated');
check(Sms::receive($p, incoming('15550002222','same'),$settings) === 1, 'Second destination lost');
check(Sms::receive(new SmsVoipms([]), ['query' => ['from'=>'15552223333','to'=>'15550003333','message'=>'hello','id'=>'same'], 'post'=>[]], $settings) === 1, 'Second provider lost');
check(Sms::receive($p, incoming('15550009999','unknown'),$settings) === 0, 'Unknown destination accepted');
check(!$db->inTransaction(), 'Transaction leaked on early return');
$long = str_repeat('x', 120);
check(Sms::receive($p, incoming('15550001111',$long),$settings) === 1, 'Long ID missing');
check(Sms::receive($p, incoming('15550001111',$long),$settings) === 0, 'Long ID retry duplicated');
check(Sms::receive($p, incoming('15550001111',$long.'y'),$settings) === 1, 'Long IDs collide');
check(Sms::receive($p, incoming('15550001111',''),$settings) === 1, 'Missing ID discarded');
check(Sms::receive($p, incoming('15550001111',''),$settings) === 1, 'Independent missing ID discarded');
check(Sms::receive($p, incoming('15550001111','attack',['https://127.0.0.1/private']),$settings) === 1, 'Message lost on bad attachment');
check((int)$db->query('SELECT COUNT(*) FROM plg_sms_media')->fetchColumn() === 0, 'Private media stored');
check(str_contains($db->query("SELECT error FROM plg_sms_messages WHERE provider_id='attack'")->fetchColumn(), 'public address'), 'Attachment failure not recorded');
echo "OK: real schema, destination/provider scope, retries, long/missing IDs, attachment rejection\n";
