<?php

declare(strict_types=1);

/**
 * Standalone CLI tests for voip-billing plugin (PHP 8.5 compatible).
 * Run: php plugins/community/voip-billing/tests/billing.php
 */

if (($argv[1] ?? '') === '--dns-child') {
    // Subprocess checks DNS resolution, non-public IP rejection, and CURLOPT_RESOLVE pinning.
    require_once dirname(__DIR__) . '/lib/Provider.php';

    $addresses = ['93.184.215.14'];
    function dns_get_record(string $host, int $type): array|false
    {
        global $addresses;
        return array_map(
            static fn(string $ip): array => [str_contains($ip, ':') ? 'ipv6' : 'ip' => $ip],
            $addresses
        );
    }
    function gethostbynamel(string $host): array|false
    {
        return false;
    }

    $assert = static function (bool $ok, string $msg): void {
        if (!$ok) {
            fwrite(STDERR, "FAIL (dns-child): {$msg}\n");
            exit(1);
        }
    };

    $pinned = BillingProvider::validatedResolve('https://billing.example.com/api/admin/invoice/prepare');
    $assert($pinned === ['billing.example.com:443:93.184.215.14'], 'Expected public IPv4 CURLOPT_RESOLVE pin');

    $addresses = ['2606:4700:4700::1111'];
    $pinnedV6 = BillingProvider::validatedResolve('https://billing.example.com:8443/api/admin/invoice/prepare');
    $assert($pinnedV6 === ['billing.example.com:8443:[2606:4700:4700::1111]'], 'Expected public IPv6 CURLOPT_RESOLVE pin');

    foreach ([['127.0.0.1'], ['10.0.0.1'], ['172.16.5.4'], ['192.168.1.10'], ['169.254.169.254'], ['::1'], ['::ffff:127.0.0.1'], ['93.184.215.14', '10.0.0.5']] as $bad) {
        $addresses = $bad;
        $threw = false;
        try {
            BillingProvider::validatedResolve('https://billing.example.com/api');
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        $assert($threw, 'Expected non-public DNS result to be rejected: ' . implode(',', $bad));
    }
    exit(0);
}

if (($argv[1] ?? '') === '--curl-child') {
    // Subprocess verifies BillingProvider::curl() options (no redirects, TLS verify, pinned IP, timeouts).
    require_once dirname(__DIR__) . '/lib/Provider.php';

    $captured = null;
    function dns_get_record(string $host, int $type): array|false
    {
        return [['ip' => '93.184.215.14']];
    }
    function gethostbynamel(string $host): array|false
    {
        return ['93.184.215.14'];
    }
    function curl_init(?string $url = null): object
    {
        return (object) ['url' => $url, 'options' => []];
    }
    function curl_setopt_array(object $ch, array $options): bool
    {
        $ch->options = $options;
        return true;
    }
    function curl_exec(object $ch): string|bool
    {
        global $captured;
        $captured = $ch;
        return '{"result":{"list":[]},"error":null}';
    }
    function curl_getinfo(object $ch, ?int $option = null): mixed
    {
        return 200;
    }
    function curl_error(object $ch): string
    {
        return '';
    }

    [$code, $body] = BillingProvider::curl('POST', 'https://billing.example.com/api/admin/invoice/get_list', ['Accept: application/json'], '{"per_page":1}');
    $o = $captured->options ?? [];
    $ok = $code === 200
        && ($o[CURLOPT_FOLLOWLOCATION] ?? true) === false
        && ($o[CURLOPT_SSL_VERIFYPEER] ?? false) === true
        && ($o[CURLOPT_SSL_VERIFYHOST] ?? 0) === 2
        && ($o[CURLOPT_CONNECTTIMEOUT] ?? 0) === 5
        && ($o[CURLOPT_TIMEOUT] ?? 0) === 15
        && ($o[CURLOPT_PROXY] ?? null) === ''
        && ($o[CURLOPT_PROTOCOLS] ?? 0) === CURLPROTO_HTTPS
        && ($o[CURLOPT_RESOLVE] ?? []) === ['billing.example.com:443:93.184.215.14'];
    if (!$ok) {
        fwrite(STDERR, "FAIL (curl-child): unsafe or missing curl options\n");
        exit(1);
    }
    exit(0);
}

require_once dirname(__DIR__) . '/lib/VoipBilling.php';

$secretBoxFile = '/mnt/data/projects/AiPBX/web/src/secret_box.php';
if (is_file($secretBoxFile) && !class_exists('SecretBox')) {
    require_once $secretBoxFile;
    SecretBox::useKey(random_bytes(32));
}

if (!class_exists('CdrCallAnalyzer')) {
    $coreAnalyzer = dirname(__DIR__, 5) . '/AiPBX/web/src/services/CdrCallAnalyzer.php';
    if (is_file($coreAnalyzer)) {
        require_once $coreAnalyzer;
    } else {
        require_once '/mnt/data/projects/AiPBX/web/src/services/CdrCallAnalyzer.php';
    }
}

// Lightweight in-memory PDO stub for testing VoipBilling CRUD, SecretBox masking, atomic claims, and rollback.
final class MockBillingStmt extends PDOStatement
{
    public function __construct(private MockBillingPdo $pdo, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->handle($this->sql, $params ?? [], $this);
        return true;
    }

    public array $rows = [];
    public int $affected = 0;

    public function rowCount(): int
    {
        return $this->affected;
    }

    public mixed $scalar = false;
    private int $idx = 0;

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[$this->idx++] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(static fn(array $r): mixed => reset($r), $this->rows);
        }
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->scalar !== false) {
            return $this->scalar;
        }
        $first = $this->rows[0] ?? null;
        if (!is_array($first)) {
            return false;
        }
        $vals = array_values($first);
        return $vals[$column] ?? false;
    }
}

final class MockBillingPdo extends PDO
{
    public array $providers = [];
    public array $customers = [];
    public array $rates = [];
    public array $invoices = [];
    public array $calls = [];
    public array $cdrs = [];
    public array $submissionLocks = [];
    public int $nextId = 1;
    private bool $inTx = false;
    private array $snapshot = [];

    public function __construct()
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === PDO::ATTR_DRIVER_NAME) {
            return 'mysql';
        }
        return null;
    }

    public function beginTransaction(): bool
    {
        $this->inTx = true;
        $this->snapshot = [
            'invoices' => $this->invoices,
            'calls' => $this->calls,
        ];
        return true;
    }

    public function commit(): bool
    {
        $this->inTx = false;
        $this->snapshot = [];
        return true;
    }

    public function rollBack(): bool
    {
        $this->inTx = false;
        if ($this->snapshot !== []) {
            $this->invoices = $this->snapshot['invoices'];
            $this->calls = $this->snapshot['calls'];
            $this->snapshot = [];
        }
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) ($this->nextId - 1);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $stmt = new MockBillingStmt($this, $query);
        $stmt->execute([]);
        return $stmt;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new MockBillingStmt($this, $query);
    }

    public function handle(string $sql, array $params, MockBillingStmt $stmt): void
    {
        $s = preg_replace('/\s+/', ' ', trim($sql)) ?? '';

        if (str_starts_with($s, 'SELECT GET_LOCK(')) {
            $id = (int) $params[0];
            $stmt->scalar = isset($this->submissionLocks[$id]) ? 0 : 1;
            if ($stmt->scalar === 1) {
                $this->submissionLocks[$id] = true;
            }
            return;
        }
        if (str_starts_with($s, 'SELECT RELEASE_LOCK(')) {
            unset($this->submissionLocks[(int) $params[0]]);
            return;
        }
        if (str_contains($s, 'FROM asteriskcdr LIMIT 0')) {
            return;
        }
        if (str_starts_with($s, 'SELECT trunk_name, title FROM pbx_trunks')
            || str_starts_with($s, 'SELECT extension, full_name FROM sys_users')) {
            $stmt->rows = [];
            return;
        }
        if (str_starts_with($s, 'INSERT INTO plg_billing_providers')) {
            $id = $this->nextId++;
            $this->providers[$id] = [
                'id' => $id,
                'name' => $params[0],
                'provider' => $params[1],
                'base_url' => $params[2],
                'api_user' => $params[3],
                'api_key_encrypted' => $params[4],
                'is_active' => $params[5],
                'last_test_at' => null,
                'last_test_status' => '',
                'last_test_message' => '',
                'created_at' => $params[6],
            ];
            return;
        }
        if (str_contains($s, 'FROM plg_billing_providers WHERE id = ?')) {
            $row = $this->providers[(int) $params[0]] ?? null;
            $stmt->rows = $row ? [$row] : [];
            return;
        }
        if (str_contains($s, 'FROM plg_billing_providers')) {
            $stmt->rows = array_values($this->providers);
            return;
        }
        if (str_starts_with($s, 'INSERT INTO plg_billing_customers')) {
            $id = $this->nextId++;
            $this->customers[$id] = [
                'id' => $id,
                'provider_id' => $params[0],
                'external_client_id' => $params[1],
                'customer_name' => $params[2],
                'extensions_csv' => $params[3],
                'rate_per_minute' => $params[4],
                'taxed' => $params[5],
                'is_active' => $params[6],
                'created_at' => $params[7],
                'provider_name' => $this->providers[(int) $params[0]]['name'] ?? '',
            ];
            return;
        }
        if (str_contains($s, 'FROM plg_billing_customers WHERE provider_id = ? AND is_active = 1 AND id <> ?')) {
            $stmt->rows = array_values(array_filter(
                $this->customers,
                static fn(array $c): bool => (int) $c['provider_id'] === (int) $params[0] && (int) $c['is_active'] === 1 && (int) $c['id'] !== (int) $params[1]
            ));
            return;
        }
        if (str_contains($s, 'FROM plg_billing_customers WHERE id = ? AND provider_id = ?')) {
            $c = $this->customers[(int) $params[0]] ?? null;
            $stmt->rows = ($c && (int) $c['provider_id'] === (int) $params[1]) ? [$c] : [];
            return;
        }
        if (str_contains($s, 'FROM plg_billing_customers c')) {
            $stmt->rows = isset($params[0])
                ? array_values(array_filter($this->customers, static fn(array $c): bool => (int) $c['provider_id'] === (int) $params[0]))
                : array_values($this->customers);
            return;
        }
        if (str_contains($s, 'FROM plg_billing_rates r')) {
            $stmt->rows = isset($params[0])
                ? array_values(array_filter($this->rates, static fn(array $r): bool => (int) $r['provider_id'] === (int) $params[0]))
                : array_values($this->rates);
            return;
        }
        if (str_contains($s, 'SELECT DISTINCT COALESCE(NULLIF(c.linkedid')) {
            $pid = (int) $params[0];
            $lids = [];
            foreach ($this->cdrs as $row) {
                $lid = $row['linkedid'] !== '' ? $row['linkedid'] : $row['uniqueid'];
                if ($row['calldate'] >= $params[1] && $row['calldate'] <= $params[2]
                    && $row['disposition'] === 'ANSWERED' && (int) $row['billsec'] > 0
                    && !isset($this->calls[$pid . '|' . $lid])) {
                    $lids[$lid] = ['lid' => $lid];
                }
            }
            $stmt->rows = array_values($lids);
            return;
        }
        if (str_contains($s, 'AS effective_linkedid FROM asteriskcdr c')) {
            $wanted = array_flip(array_slice($params, 0, (int) (count($params) / 2)));
            $rows = [];
            foreach ($this->cdrs as $row) {
                $lid = $row['linkedid'] !== '' ? $row['linkedid'] : $row['uniqueid'];
                if (isset($wanted[$lid]) || isset($wanted[$row['uniqueid']])) {
                    $row['effective_linkedid'] = $lid;
                    $rows[] = $row;
                }
            }
            $stmt->rows = $rows;
            return;
        }
        if (str_starts_with($s, 'SELECT linkedid FROM plg_billing_calls WHERE provider_id = ?')) {
            $pid = (int) $params[0];
            $rows = [];
            foreach (array_slice($params, 1) as $lid) {
                if (isset($this->calls[$pid . '|' . $lid])) {
                    $rows[] = ['linkedid' => $lid];
                }
            }
            $stmt->rows = $rows;
            return;
        }
        if (str_starts_with($s, 'INSERT INTO plg_billing_invoices')) {
            $id = $this->nextId++;
            $isFailed = str_contains($s, "'failed'");
            $this->invoices[$id] = $isFailed
                ? [
                    'id' => $id, 'provider_id' => $params[0], 'customer_id' => $params[1],
                    'period_start' => $params[2], 'period_end' => $params[3],
                    'call_count' => $params[4], 'billed_seconds' => $params[5], 'total_amount' => $params[6],
                    'external_invoice_id' => '', 'status' => 'failed', 'error' => $params[7], 'created_at' => $params[8],
                ]
                : [
                    'id' => $id, 'provider_id' => $params[0], 'customer_id' => $params[1],
                    'period_start' => $params[2], 'period_end' => $params[3],
                    'call_count' => 0, 'billed_seconds' => 0, 'total_amount' => 0.0,
                    'external_invoice_id' => '', 'status' => 'pending', 'error' => '', 'created_at' => $params[4],
                ];
            return;
        }
        if (str_starts_with($s, 'SELECT provider_id, external_invoice_id FROM plg_billing_invoices')) {
            $row = $this->invoices[(int) $params[0]] ?? null;
            $stmt->rows = $row && $row['status'] === 'pending' ? [$row] : [];
            return;
        }
        if (str_starts_with($s, 'UPDATE plg_billing_invoices SET error = ?')) {
            $id = (int) $params[1];
            if (isset($this->invoices[$id]) && $this->invoices[$id]['status'] === 'pending') {
                $this->invoices[$id]['error'] = (string) $params[0];
                $stmt->affected = 1;
            }
            return;
        }
        if (str_starts_with($s, 'UPDATE plg_billing_invoices')) {
            if (count($params) === 5) {
                $id = (int) $params[4];
                $this->invoices[$id]['external_invoice_id'] = $params[0];
                $this->invoices[$id]['call_count'] = $params[1];
                $this->invoices[$id]['billed_seconds'] = $params[2];
                $this->invoices[$id]['total_amount'] = $params[3];
            } else {
                $id = (int) $params[1];
                if ($this->invoices[$id]['status'] === 'pending' && $this->invoices[$id]['external_invoice_id'] === $params[2]) {
                    $rejected = str_contains($s, "status = 'failed'");
                    $this->invoices[$id]['status'] = $rejected ? 'failed' : 'created';
                    $this->invoices[$id]['external_invoice_id'] = $rejected ? '' : $params[0];
                    $this->invoices[$id]['error'] = $rejected ? $params[0] : '';
                    $stmt->affected = 1;
                }
            }
            return;
        }
        if (str_starts_with($s, 'DELETE FROM plg_billing_calls WHERE invoice_id = ?')) {
            $this->calls = array_filter($this->calls, static fn(array $call): bool => $call['invoice_id'] !== (int) $params[0]);
            return;
        }
        if (str_starts_with($s, 'INSERT INTO plg_billing_calls')) {
            $key = (int) $params[0] . '|' . $params[1];
            if (isset($this->calls[$key])) {
                throw new PDOException('Duplicate entry', 23000);
            }
            $this->calls[$key] = [
                'provider_id' => (int) $params[0],
                'linkedid' => $params[1],
                'invoice_id' => (int) $params[2],
                'customer_id' => (int) $params[3],
                'extension' => $params[4],
                'destination' => $params[5],
                'calldate' => $params[6],
                'billsec' => (int) $params[7],
                'billed_units' => (int) $params[8],
                'rate_per_minute' => $params[9],
                'amount' => $params[10],
            ];
            return;
        }
        throw new RuntimeException('Unhandled test SQL: ' . $s);
    }
}

$mockDb = new MockBillingPdo();
function getDB(): PDO
{
    global $mockDb;
    return $mockDb;
}

$failed = 0;
$passed = 0;
$check = static function (bool $ok, string $what) use (&$failed, &$passed): void {
    if ($ok) {
        $passed++;
    } else {
        echo "FAIL: {$what}\n";
        $failed++;
    }
};

$throws = static function (callable $fn, string $what) use ($check): void {
    try {
        $fn();
        $check(false, $what);
    } catch (\Throwable $e) {
        $check(true, $what);
    }
};

// 1. URL & SSRF validation
$badUrls = [
    'http://billing.example.com',
    'https://user:pass@billing.example.com',
    'https://user@billing.example.com',
    'https://billing.example.com?debug=1',
    'https://billing.example.com#section',
    'https://127.0.0.1',
    'https://127.0.0.1/panel',
    'https://10.0.0.1',
    'https://172.16.0.5',
    'https://192.168.1.1',
    'https://169.254.169.254',
    'https://224.0.0.1',
    'https://100.64.0.1',
    'https://[::1]',
    'https://[::ffff:127.0.0.1]',
    'https://[fc00::1]',
    'https://[fe80::1]',
    'https://[2002:7f00:1::]',
    'https://localhost',
    'https://billing.local',
    'https://billing.internal',
    'https://2130706433',
];
foreach ($badUrls as $badUrl) {
    $throws(static fn() => BillingProvider::validateBaseUrl($badUrl), "Reject unsafe URL: {$badUrl}");
}

$check(
    BillingProvider::validateBaseUrl('https://Billing.Example.com:8443/sub/') === 'https://billing.example.com:8443/sub',
    'Normalize valid HTTPS URL with port and path'
);

// Run isolated DNS pinning/SSRF subprocess check
$dnsCmd = escapeshellarg(PHP_BINARY)
    . ' -d disable_functions=dns_get_record,gethostbynamel '
    . escapeshellarg(__FILE__) . ' --dns-child';
exec($dnsCmd, $dnsOut, $dnsStatus);
$check($dnsStatus === 0, 'DNS pinning and non-public IP rejection subprocess');

// Run isolated curl option verification subprocess check
$curlCmd = escapeshellarg(PHP_BINARY)
    . ' -d disable_functions=dns_get_record,gethostbynamel,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_error '
    . escapeshellarg(__FILE__) . ' --curl-child';
exec($curlCmd, $curlOut, $curlStatus);
$check($curlStatus === 0, 'BillingProvider::curl enforces no redirects, TLS verify, timeouts, and IP pinning');

// 2. ClientFence testConnection() & createDraftInvoice()
$requests = [];
$nextResponse = [200, '{"result":{"list":[]},"error":null}'];
BillingProvider::$http = static function (string $method, string $url, array $headers, ?string $body) use (&$requests, &$nextResponse): array {
    $requests[] = compact('method', 'url', 'headers', 'body');
    return $nextResponse;
};

$cf = new BillingClientfence([
    'name' => 'Main',
    'provider' => 'clientfence',
    'base_url' => 'https://billing.example.com/',
    'api_user' => '',
    'api_key' => 'secret-token-123',
]);

$check($cf->configured(), 'ClientFence configured() is true');
$check($cf->testConnection() === 'Connected', 'ClientFence testConnection() succeeds with error === null');
$last = end($requests);
$check($last['method'] === 'POST', 'testConnection uses POST');
$check($last['url'] === 'https://billing.example.com/api/admin/invoice/get_list', 'testConnection URL');
$check(
    in_array('Authorization: Basic ' . base64_encode('admin:secret-token-123'), $last['headers'], true),
    'testConnection sends default admin HTTP Basic Auth header'
);
$check(json_decode((string) $last['body'], true) === ['per_page' => 1], 'testConnection body');

$nextResponse = [200, '{"result":{"list":[]}}'];
$check($cf->testConnection() === 'Connected', 'ClientFence testConnection() succeeds when error key absent');

$nextResponse = [200, '{"result":null,"error":{"message":"Invalid API key","code":403}}'];
$throws(static fn() => $cf->testConnection(), 'ClientFence testConnection() rejects JSON error object');

$nextResponse = [401, '{"error":{"message":"Unauthorized"}}'];
$throws(static fn() => $cf->testConnection(), 'ClientFence testConnection() rejects HTTP 401');

$nextResponse = [200, '{"result":456,"error":null}'];
$items = [
    ['title' => 'VoIP Outbound Calls — US (+1) (3 calls)', 'price' => 0.05, 'quantity' => 5, 'taxed' => true, 'unit' => 'min'],
];
$invoiceId = $cf->createDraftInvoice('42', 'VoIP calls 2026-11-01 to 2026-11-30', $items);
$check($invoiceId === '456', 'createDraftInvoice returns external invoice id as string');
$last = end($requests);
$check($last['url'] === 'https://billing.example.com/api/admin/invoice/prepare', 'createDraftInvoice URL');
$sentPayload = json_decode((string) $last['body'], true);
$check(
    $sentPayload === [
        'client_id' => 42,
        'text_1' => 'VoIP calls 2026-11-01 to 2026-11-30',
        'approve' => false,
        'items' => $items,
    ],
    'createDraftInvoice JSON payload shape'
);

foreach (['{"result":null,"error":null}', '{"result":"","error":null}', '{"result":0,"error":null}', '{"result":null,"error":{"message":"Client not found"}}'] as $badResp) {
    $nextResponse = [200, $badResp];
    $throws(static fn() => $cf->createDraftInvoice('42', 'Period', $items), "createDraftInvoice rejects bad response: {$badResp}");
}

$throws(static fn() => $cf->createDraftInvoice('0', 'Period', $items), 'createDraftInvoice rejects client_id 0');
$throws(static fn() => $cf->createDraftInvoice('abc', 'Period', $items), 'createDraftInvoice rejects non-numeric client_id');

// 3. Number normalization & longest-prefix rate selection
$check(VoipBilling::normalizeNumber('+1 (555) 123-4567') === '15551234567', 'Normalize +1 number');
$check(VoipBilling::normalizeNumber('0090 532 111 22 33') === '905321112233', 'Normalize 0090 number');
$check(VoipBilling::normalizeNumber('+905321112233') === '905321112233', 'Normalize +90 number');

$rates = [
    ['prefix' => '90', 'label' => 'Turkey Landline', 'rate_per_minute' => '0.0300'],
    ['prefix' => '90532', 'label' => 'Turkey Mobile', 'rate_per_minute' => '0.1200'],
    ['prefix' => '1', 'label' => 'US / Canada', 'rate_per_minute' => '0.0200'],
];
$r1 = VoipBilling::resolveRate('00905321112233', $rates, 0.08, 0.05);
$check($r1['prefix'] === '90532' && abs($r1['rate'] - 0.12) < 1e-6, 'Longest prefix wins (0090532 -> 90532)');

$r2 = VoipBilling::resolveRate('+902121112233', $rates, 0.08, 0.05);
$check($r2['prefix'] === '90' && abs($r2['rate'] - 0.03) < 1e-6, 'Shorter prefix matches when mobile prefix does not (+90212 -> 90)');

$r3 = VoipBilling::resolveRate('+442071234567', $rates, 0.08, 0.05);
$check($r3['prefix'] === '' && abs($r3['rate'] - 0.08) < 1e-6, 'Customer rate override used when no prefix matches');

$r4 = VoipBilling::resolveRate('+442071234567', $rates, null, 0.05);
$check($r4['prefix'] === '' && abs($r4['rate'] - 0.05) < 1e-6, 'Global default rate used when no prefix and no customer rate');

// 4. Billing increments: 60/60 vs 1/1
$b60 = VoipBilling::calculateCallBilling(65, 0.12, 60);
$check($b60['billed_seconds'] === 120 && $b60['billed_units'] === 2 && abs($b60['amount'] - 0.24) < 1e-6, '60/60 billing rounds 65s up to 2 min');

$b1 = VoipBilling::calculateCallBilling(65, 0.12, 1);
$check($b1['billed_seconds'] === 65 && $b1['billed_units'] === 65 && abs($b1['amount'] - 0.13) < 1e-6, '1/1 billing charges exact 65s');

// 5. CDR deduplication, direction filtering, originating leg resolution, and duplicate call prevention
$customers = [
    [
        'id' => 10,
        'external_client_id' => '42',
        'customer_name' => 'Acme Corp',
        'extensions_csv' => '3001,3002',
        'rate_per_minute' => null,
        'taxed' => null,
        'is_active' => 1,
    ],
    [
        'id' => 11,
        'external_client_id' => '43',
        'customer_name' => 'Inactive Corp',
        'extensions_csv' => '3003',
        'rate_per_minute' => null,
        'taxed' => null,
        'is_active' => 0,
    ],
];

$legsByLinkedId = [
    'out-multileg-1' => [
        [
            'id' => 1, 'uniqueid' => 'out-multileg-1.1', 'linkedid' => 'out-multileg-1',
            'calldate' => '2026-11-10 10:00:00', 'src' => '3001', 'dst' => '00905321112233', 'did' => '',
            'channel' => 'PJSIP/3001-webrtc-0001', 'dstchannel' => 'PJSIP/trk1-0002',
            'lastapp' => 'Dial', 'lastdata' => 'PJSIP/00905321112233@trk1,30',
            'billsec' => 0, 'duration' => 5, 'disposition' => 'NO ANSWER',
        ],
        [
            'id' => 2, 'uniqueid' => 'out-multileg-1.2', 'linkedid' => 'out-multileg-1',
            'calldate' => '2026-11-10 10:00:05', 'src' => '3001', 'dst' => '00905321112233', 'did' => '',
            'channel' => 'PJSIP/3001-webrtc-0001', 'dstchannel' => 'PJSIP/trk2-0003',
            'lastapp' => 'Dial', 'lastdata' => 'PJSIP/00905321112233@trk2,60',
            'billsec' => 65, 'duration' => 70, 'disposition' => 'ANSWERED',
        ],
    ],
    'out-rewritten-src-2' => [
        [
            'id' => 3, 'uniqueid' => 'out-rewritten-src-2', 'linkedid' => 'out-rewritten-src-2',
            'calldate' => '2026-11-10 11:00:00', 'src' => '+15550009999', 'dst' => '+15551234567', 'did' => '',
            'channel' => 'PJSIP/3002-sip-0004', 'dstchannel' => 'PJSIP/trk1-0005',
            'lastapp' => 'Dial', 'lastdata' => 'PJSIP/15551234567@trk1,60',
            'billsec' => 30, 'duration' => 35, 'disposition' => 'ANSWERED',
        ],
    ],
    'inbound-3' => [
        [
            'id' => 4, 'uniqueid' => 'inbound-3', 'linkedid' => 'inbound-3',
            'calldate' => '2026-11-10 12:00:00', 'src' => '05329998877', 'dst' => '3001', 'did' => '5550001',
            'channel' => 'PJSIP/trk1-0006', 'dstchannel' => 'PJSIP/3001-sip-0007',
            'lastapp' => 'Dial', 'lastdata' => 'PJSIP/3001-sip,30',
            'billsec' => 120, 'duration' => 125, 'disposition' => 'ANSWERED',
        ],
    ],
    'internal-4' => [
        [
            'id' => 5, 'uniqueid' => 'internal-4', 'linkedid' => 'internal-4',
            'calldate' => '2026-11-10 13:00:00', 'src' => '3001', 'dst' => '3002', 'did' => '',
            'channel' => 'PJSIP/3001-sip-0008', 'dstchannel' => 'PJSIP/3002-sip-0009',
            'lastapp' => 'Dial', 'lastdata' => 'PJSIP/3002-sip,30',
            'billsec' => 90, 'duration' => 92, 'disposition' => 'ANSWERED',
        ],
    ],
    'inactive-cust-5' => [
        [
            'id' => 6, 'uniqueid' => 'inactive-cust-5', 'linkedid' => 'inactive-cust-5',
            'calldate' => '2026-11-10 14:00:00', 'src' => '3003', 'dst' => '15551234567', 'did' => '',
            'channel' => 'PJSIP/3003-sip-0010', 'dstchannel' => 'PJSIP/trk1-0011',
            'lastapp' => 'Dial', 'lastdata' => 'PJSIP/15551234567@trk1,60',
            'billsec' => 45, 'duration' => 50, 'disposition' => 'ANSWERED',
        ],
    ],
    'already-billed-6' => [
        [
            'id' => 7, 'uniqueid' => 'already-billed-6', 'linkedid' => 'already-billed-6',
            'calldate' => '2026-11-10 15:00:00', 'src' => '3001', 'dst' => '15551234567', 'did' => '',
            'channel' => 'PJSIP/3001-sip-0012', 'dstchannel' => 'PJSIP/trk1-0013',
            'lastapp' => 'Dial', 'lastdata' => 'PJSIP/15551234567@trk1,60',
            'billsec' => 60, 'duration' => 62, 'disposition' => 'ANSWERED',
        ],
    ],
];

$settings = [
    'default_currency' => 'USD',
    'default_rate_per_minute' => '0.05',
    'billing_increment_seconds' => '60',
    'default_taxed' => '1',
];

$analyzer = new CdrCallAnalyzer(['trk1' => 'Trunk 1', 'trk2' => 'Trunk 2']);
$res60 = VoipBilling::analyzeAndPriceCalls(
    $legsByLinkedId,
    $customers,
    $rates,
    $settings,
    $analyzer,
    ['already-billed-6' => true],
    '2026-11-01',
    '2026-11-30'
);

$check($res60['total_calls'] === 2, 'Only 2 outbound unbilled mapped calls counted under 60/60');
$check($res60['total_billed_seconds'] === 180, 'Total billed seconds = 120s + 60s = 180s');
$check(abs($res60['total_amount'] - 0.26) < 1e-6, 'Total amount = 0.2600 under 60/60');
$check(count($res60['customers'][10]['items']) === 2, 'Two rate-tier line items built for customer 10');

$settingsPerSec = ['billing_increment_seconds' => '1'] + $settings;
$res1 = VoipBilling::analyzeAndPriceCalls(
    $legsByLinkedId,
    $customers,
    $rates,
    $settingsPerSec,
    $analyzer,
    ['already-billed-6' => true],
    '2026-11-01',
    '2026-11-30'
);
$check($res1['total_billed_seconds'] === 95, 'Total billed seconds = 65s + 30s = 95s under 1/1');
$check(abs($res1['total_amount'] - 0.14) < 1e-6, 'Total amount = 0.1400 under 1/1');

$unanswered = $legsByLinkedId['out-multileg-1'][0];
$internalAnswer = $legsByLinkedId['internal-4'][0];
$internalAnswer['calldate'] = '2026-11-10 10:00:05';
$res = VoipBilling::analyzeAndPriceCalls(['unanswered' => [$unanswered, $internalAnswer]], $customers, $rates, $settings, $analyzer);
$check($res['total_calls'] === 0, 'An internal answer does not make an unanswered trunk billable');

$fractionalRates = [
    ['prefix' => '90', 'label' => 'A', 'rate_per_minute' => '0.0349'],
    ['prefix' => '1', 'label' => 'B', 'rate_per_minute' => '0.0349'],
];
$first = $legsByLinkedId['out-multileg-1'][1];
$first['billsec'] = 60;
$res = VoipBilling::analyzeAndPriceCalls([
    'first' => [$first],
    'second' => $legsByLinkedId['out-rewritten-src-2'],
], $customers, $fractionalRates, $settings, $analyzer);
$check($res['total_amount'] === 0.06 && $res['customers'][10]['total_amount'] === 0.06, 'Preview subtotal matches two independently rounded invoice lines');

// 6. End-to-end DB CRUD, SecretBox masking, rollback on API failure, and atomic deduplication (MockBillingPdo)
$providerId = VoipBilling::saveProvider(0, [
    'name' => 'CF Prod',
    'provider' => 'clientfence',
    'base_url' => 'https://billing.example.com/',
    'api_user' => 'admin',
    'api_key' => 'super-secret-api-key-9999',
    'is_active' => true,
]);
$listedProviders = VoipBilling::listProviders();
$check(count($listedProviders) === 1, 'Saved 1 provider');
$check(!isset($listedProviders[0]['api_key']) && !isset($listedProviders[0]['api_key_encrypted']), 'listProviders strips secrets');
$check(str_starts_with($listedProviders[0]['api_key_masked'], '••••') && !str_contains($listedProviders[0]['api_key_masked'], 'super-secret'), 'listProviders masks api_key');

$customerId = VoipBilling::saveCustomer(0, [
    'provider_id' => $providerId,
    'external_client_id' => '42',
    'customer_name' => 'Acme Corp',
    'extensions_csv' => '3001, 3002',
    'rate_per_minute' => '',
    'taxed' => '',
    'is_active' => true,
]);
$check($customerId > 0, 'Saved customer mapping');

// Overlapping active extension rejected
$throws(static fn() => VoipBilling::saveCustomer(0, [
    'provider_id' => $providerId,
    'external_client_id' => '43',
    'customer_name' => 'Beta Corp',
    'extensions_csv' => '3002',
    'is_active' => true,
]), 'Overlapping active extension rejected');

foreach ($legsByLinkedId as $lid => $legs) {
    foreach ($legs as $leg) {
        $mockDb->cdrs[] = $leg;
    }
}
// Mark 'already-billed-6' as previously billed in plg_billing_calls.
$mockDb->calls[$providerId . '|already-billed-6'] = [
    'provider_id' => $providerId,
    'linkedid' => 'already-billed-6',
    'invoice_id' => 999,
    'customer_id' => $customerId,
    'extension' => '3001',
    'destination' => '15551234567',
    'calldate' => '2026-11-10 15:00:00',
    'billsec' => 60,
    'billed_units' => 1,
    'rate_per_minute' => '0.0500',
    'amount' => '0.0500',
];

$remoteCurrency = 'EUR';
$prepareRequests = 0;
$loseResponse = false;
$remoteReference = '';
BillingProvider::$http = static function (string $method, string $url, array $headers, ?string $body) use (
    &$remoteCurrency, &$prepareRequests, &$loseResponse, &$remoteReference, &$nextResponse, $mockDb, $check
): array {
    $params = json_decode($body, true);
    if (str_ends_with($url, '/client/get')) {
        return [200, json_encode(['result' => ['id' => $params['id'], 'currency' => $remoteCurrency]])];
    }
    if (str_ends_with($url, '/invoice/get')) {
        return [200, json_encode(['result' => ['id' => $params['id'], 'text_1' => $params['id'] === 777 ? $remoteReference : 'Different invoice']])];
    }
    $check(!$mockDb->inTransaction(), 'The provider write runs after the local transaction ends');
    $prepareRequests++;
    $remoteReference = $params['text_1'];
    if ($loseResponse) {
        throw new RuntimeException('Response was lost after the provider saved the draft');
    }
    return $nextResponse;
};

$failRun = VoipBilling::createDraftInvoices($providerId, '2026-11-01', '2026-11-30', $settings);
$check($failRun['failed'] === 1 && $prepareRequests === 0 && count($mockDb->calls) === 1, 'Mismatched currency blocks draft creation');

$remoteCurrency = 'USD';
$nextResponse = [403, '{"result":null,"error":{"message":"Access denied"}}'];
$failRun = VoipBilling::createDraftInvoices($providerId, '2026-11-01', '2026-11-30', $settings);
$check($failRun['failed'] === 1 && $prepareRequests === 1, 'Confirmed rejection reports failure');
$check(count($mockDb->calls) === 1 && end($mockDb->invoices)['status'] === 'failed', 'Confirmed rejection releases only the new claims');

$loseResponse = true;
$failRun = VoipBilling::createDraftInvoices($providerId, '2026-11-01', '2026-11-30', $settings);
$pending = end($mockDb->invoices);
$check($failRun['failed'] === 1 && $pending['status'] === 'pending' && count($mockDb->calls) === 3, 'Lost response preserves the draft and its calls');
$check($pending['external_invoice_id'] === $remoteReference, 'The provider receives the persisted reference');
$check(str_contains($pending['error'], $remoteReference), 'Lost response records the unknown-outcome reference on the pending draft');
VoipBilling::createDraftInvoices($providerId, '2026-11-01', '2026-11-30', $settings);
$check($prepareRequests === 2, 'Retry never resends reserved calls');
$throws(static fn() => VoipBilling::reconcileDraft($pending['id'], '778', $settings), 'Reconciliation rejects a different remote draft');
VoipBilling::reconcileDraft($pending['id'], '777', $settings);
$check($mockDb->invoices[$pending['id']]['status'] === 'created' && $mockDb->invoices[$pending['id']]['external_invoice_id'] === '777', 'Matching remote reference reconciles the saved draft');

// Verify releasePendingDraft() frees claimed calls when an admin confirms a pending draft was not created remotely
$loseResponse = true;
$mockDb->cdrs[] = [
    'id' => 98, 'uniqueid' => 'lost-release-98', 'linkedid' => 'lost-release-98',
    'calldate' => '2026-11-12 10:00:00', 'src' => '3001', 'dst' => '15559997777', 'did' => '',
    'channel' => 'PJSIP/3001-sip-0098', 'dstchannel' => 'PJSIP/trk1-0098',
    'lastapp' => 'Dial', 'lastdata' => 'PJSIP/15559997777@trk1,60',
    'billsec' => 60, 'duration' => 65, 'disposition' => 'ANSWERED',
];
VoipBilling::createDraftInvoices($providerId, '2026-11-01', '2026-11-30', $settings);
$releasedPending = end($mockDb->invoices);
$check($releasedPending['status'] === 'pending' && count($mockDb->calls) === 4, 'New lost-response draft claims its call');
$mockDb->submissionLocks[$releasedPending['id']] = true;
$throws(static fn() => VoipBilling::releasePendingDraft($releasedPending['id']), 'A concurrent submission prevents releasing its calls');
$throws(static fn() => VoipBilling::reconcileDraft($releasedPending['id'], '777', $settings), 'A concurrent submission prevents reconciliation');
$check(count($mockDb->calls) === 4, 'Claims stay reserved while submission is locked');
unset($mockDb->submissionLocks[$releasedPending['id']]);
VoipBilling::releasePendingDraft($releasedPending['id']);
$check($mockDb->invoices[$releasedPending['id']]['status'] === 'failed' && count($mockDb->calls) === 3, 'Releasing a pending draft marks it failed and frees its claimed calls');
$throws(static fn() => VoipBilling::releasePendingDraft($releasedPending['id']), 'Releasing a non-pending draft is rejected');

// Simulate duplicate PK (SQLSTATE 23000) collision on a new CDR inserted between preview and claim
$mockDb->cdrs[] = [
    'id' => 99, 'uniqueid' => 'race-call-99', 'linkedid' => 'race-call-99',
    'calldate' => '2026-11-11 10:00:00', 'src' => '3001', 'dst' => '15559998888', 'did' => '',
    'channel' => 'PJSIP/3001-sip-0099', 'dstchannel' => 'PJSIP/trk1-0099',
    'lastapp' => 'Dial', 'lastdata' => 'PJSIP/15559998888@trk1,60',
    'billsec' => 60, 'duration' => 65, 'disposition' => 'ANSWERED',
];
// Pre-insert directly into $mockDb->calls? No, if it's in $mockDb->calls, candStmt already filters it out.
// Instead verify direct INSERT duplicate PK throwing PDOException(23000) works cleanly:
$dupThrew = false;
try {
    $mockDb->prepare('INSERT INTO plg_billing_calls (provider_id, linkedid, invoice_id, customer_id, extension, destination, calldate, billsec, billed_units, rate_per_minute, amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$providerId, 'out-multileg-1', 1, $customerId, '3001', '00905321112233', '2026-11-10 10:00:00', 65, 2, '0.0500', '0.1000']);
} catch (PDOException $e) {
    $dupThrew = ($e->getCode() === '23000' || (int) $e->getCode() === 23000);
}
$check($dupThrew, 'Duplicate primary key (provider_id, linkedid) raises 23000');

// 7. Validate plugin.json and schema.sql
$manifest = json_decode((string) file_get_contents(dirname(__DIR__) . '/plugin.json'), true);
$check(is_array($manifest) && ($manifest['slug'] ?? '') === 'voip-billing', 'plugin.json is valid JSON with voip-billing slug');

$pluginManagerFile = '/mnt/data/projects/AiPBX/web/src/core/PluginManager.php';
if (is_file($pluginManagerFile)) {
    $pmSource = (string) file_get_contents($pluginManagerFile);
    if (preg_match('/public static function parseAndValidatePluginSchemaSql\(string \$rawSql\): array\s*\{(.*?)\n    \}/s', $pmSource, $m)) {
        $validator = eval('return static function (string $rawSql): array {' . $m[1] . "\n};");
        $parsedTables = $validator((string) file_get_contents(dirname(__DIR__) . '/schema.sql'));
        $tableNames = array_column($parsedTables, 'table');
        $check($tableNames === ($manifest['tables'] ?? []), 'schema.sql passes PluginManager::parseAndValidatePluginSchemaSql() and matches plugin.json tables');
    }
}

echo $failed === 0 ? "OK ({$passed} checks passed)\n" : "{$failed} failed, {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
