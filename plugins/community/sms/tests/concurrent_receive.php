<?php
/** Two real database connections receive the same webhook while its destination row is locked. */
require dirname(__DIR__) . '/lib/Sms.php';
$dsn = getenv('SMS_TEST_DSN') ?: '';
if (!preg_match('/dbname=([a-z0-9_]*_test)(?:;|$)/D', $dsn)) { throw new RuntimeException('Use an isolated *_test database'); }
$prefix = $argv[1] ?? ('sms_review_' . bin2hex(random_bytes(6)) . '_');
if (!preg_match('/^sms_review_[a-f0-9]{12}_$/D', $prefix)) { throw new RuntimeException('Invalid test prefix'); }
// Separate disposable tables allow testing locks without changing installed plugin data.
final class ReviewPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        global $prefix;
        return parent::prepare(str_replace('plg_sms_', $prefix, $query), $options);
    }
}
$db = new ReviewPDO($dsn, getenv('SMS_TEST_USER') ?: '', getenv('SMS_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function getDB(): PDO { global $db; return $db; }
if (isset($argv[1])) {
    $request = ['body' => '{"From":"15552223333","To":"15550001111","Message":"test","MessageId":"concurrent"}', 'post' => [], 'query' => []];
    echo Sms::receive(new SmsBulkvs([]), $request, ['email_notify' => '0']);
    exit;
}
$processes = [];
try {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents(dirname(__DIR__) . '/schema.sql'));
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') { $db->exec(str_replace('plg_sms_', $prefix, $statement)); }
    }
    $db->exec("INSERT INTO {$prefix}numbers (number,provider) VALUES ('+15550001111','bulkvs')");
    $db->beginTransaction();
    $db->query("SELECT id FROM {$prefix}numbers FOR UPDATE")->fetchAll();
    for ($i=0; $i<2; $i++) {
        $proc = proc_open([PHP_BINARY, __FILE__, $prefix], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($proc)) { throw new RuntimeException('Cannot start receiver'); }
        fclose($pipes[0]);
        $processes[] = [$proc, $pipes];
    }
    usleep(200000);
    $db->commit();
    $results = [];
    foreach ($processes as [$proc,$pipes]) {
        $results[] = trim(stream_get_contents($pipes[1])); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        if (proc_close($proc) !== 0) { throw new RuntimeException($error); }
    }
    $processes = [];
    sort($results);
    $count = (int)$db->query("SELECT COUNT(*) FROM {$prefix}messages")->fetchColumn();
    if ($results !== ['0','1'] || $count !== 1) { throw new RuntimeException('Concurrent retry duplicated or lost'); }
    echo "OK: concurrent webhook retries produce exactly one message\n";
} finally {
    if ($db->inTransaction()) { $db->rollBack(); }
    foreach ($processes as [$proc,$pipes]) {
        if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
        foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
    }
    foreach (['numbers','messages','media','contacts','access'] as $table) { $db->exec("DROP TABLE IF EXISTS {$prefix}{$table}"); }
}
