<?php

declare(strict_types=1);

require_once __DIR__ . '/Provider.php';
foreach (glob(__DIR__ . '/providers/*.php') ?: [] as $providerFile) {
    require_once $providerFile;
}

/**
 * Multi-account VoIP billing service: provider registry, encrypted credentials,
 * customer/extension mappings, prefix rate lookup, CDR analysis, and atomic draft invoicing.
 */
final class VoipBilling
{
    public const SLUG = 'voip-billing';

    /** @return array<string, class-string<BillingProvider>> id => class */
    public static function providers(): array
    {
        $out = [];
        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, BillingProvider::class)) {
                $out[$class::id()] = $class;
            }
        }
        ksort($out);
        return $out;
    }

    /** @param array<string, mixed> $account */
    public static function provider(array $account, array $settings = []): ?BillingProvider
    {
        $type = (string) ($account['provider'] ?? '');
        $class = self::providers()[$type] ?? null;
        if ($class === null) {
            return null;
        }
        return new $class($account, $settings);
    }

    // --- SecretBox & CdrCallAnalyzer lazy loaders ---

    private static function ensureSecretBox(): void
    {
        if (class_exists('SecretBox')) {
            return;
        }
        $candidates = [];
        if (class_exists('PluginManager')) {
            $candidates[] = dirname((string) (new \ReflectionClass('PluginManager'))->getFileName(), 2) . '/secret_box.php';
        }
        $candidates[] = '/opt/aipbx/web/src/secret_box.php';
        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
        throw new \RuntimeException('SecretBox class is required to encrypt provider credentials.');
    }

    public static function ensureAnalyzer(): void
    {
        if (class_exists('CdrCallAnalyzer')) {
            return;
        }
        $candidates = [];
        if (class_exists('PluginManager')) {
            $candidates[] = dirname((string) (new \ReflectionClass('PluginManager'))->getFileName(), 2) . '/services/CdrCallAnalyzer.php';
        }
        $candidates[] = '/opt/aipbx/web/src/services/CdrCallAnalyzer.php';
        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
        throw new \RuntimeException('CdrCallAnalyzer class could not be loaded.');
    }

    // --- Provider accounts CRUD ---

    /**
     * Lists provider accounts. By default secrets are omitted and only masked hints are returned.
     *
     * @return list<array<string, mixed>>
     */
    public static function listProviders(bool $onlyActive = false): array
    {
        $sql = 'SELECT id, name, provider, base_url, api_user, api_key_encrypted, is_active, last_test_at, last_test_status, last_test_message, created_at
                FROM plg_billing_providers';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC, id ASC';

        $rows = getDB()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $enc = (string) ($r['api_key_encrypted'] ?? '');
            unset($r['api_key_encrypted']);
            $r['id'] = (int) $r['id'];
            $r['is_active'] = (int) $r['is_active'];
            $r['has_api_key'] = $enc !== '';
            $r['api_key_masked'] = self::maskEncryptedSecret($enc);
        }
        unset($r);
        return $rows;
    }

    /**
     * Fetches a single provider row. Set $withSecret = true only for outbound API calls.
     *
     * @return array<string, mixed>|null
     */
    public static function getProvider(int $id, bool $withSecret = false): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $st = getDB()->prepare(
            'SELECT id, name, provider, base_url, api_user, api_key_encrypted, is_active, last_test_at, last_test_status, last_test_message, created_at
             FROM plg_billing_providers WHERE id = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $enc = (string) ($row['api_key_encrypted'] ?? '');
        unset($row['api_key_encrypted']);
        $row['id'] = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'];
        $row['has_api_key'] = $enc !== '';
        $row['api_key_masked'] = self::maskEncryptedSecret($enc);
        if ($withSecret) {
            self::ensureSecretBox();
            $row['api_key'] = $enc !== '' ? SecretBox::decrypt($enc) : '';
        }
        return $row;
    }

    private static function maskEncryptedSecret(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }
        try {
            self::ensureSecretBox();
            $plain = SecretBox::decrypt($encrypted);
            return $plain !== '' ? SecretBox::mask($plain) : '••••';
        } catch (\Throwable $e) {
            return '••••';
        }
    }

    /** @param array<string, mixed> $data */
    public static function saveProvider(int $id, array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        $provider = trim((string) ($data['provider'] ?? 'clientfence'));
        $baseUrl = BillingProvider::validateBaseUrl((string) ($data['base_url'] ?? ''));
        $apiUser = trim((string) ($data['api_user'] ?? ''));
        if ($apiUser === '') {
            $apiUser = 'admin';
        }
        $apiKey = trim((string) ($data['api_key'] ?? ''));
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($name === '') {
            throw new \InvalidArgumentException('Provider account name is required.');
        }
        if (!isset(self::providers()[$provider])) {
            throw new \InvalidArgumentException('Unknown billing provider type.');
        }

        self::ensureSecretBox();
        $db = getDB();

        if ($id > 0) {
            $existing = $db->prepare('SELECT id, api_key_encrypted FROM plg_billing_providers WHERE id = ?');
            $existing->execute([$id]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new \InvalidArgumentException('Provider account not found.');
            }
            $enc = $apiKey !== '' ? SecretBox::encrypt($apiKey) : (string) $row['api_key_encrypted'];
            $db->prepare(
                'UPDATE plg_billing_providers SET name = ?, provider = ?, base_url = ?, api_user = ?, api_key_encrypted = ?, is_active = ? WHERE id = ?'
            )->execute([
                mb_substr($name, 0, 80),
                $provider,
                mb_substr($baseUrl, 0, 255),
                mb_substr($apiUser, 0, 120),
                $enc,
                $isActive,
                $id,
            ]);
            return $id;
        }

        if ($apiKey === '') {
            throw new \InvalidArgumentException('API key is required for a new provider account.');
        }
        $enc = SecretBox::encrypt($apiKey);
        $db->prepare(
            'INSERT INTO plg_billing_providers (name, provider, base_url, api_user, api_key_encrypted, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            mb_substr($name, 0, 80),
            $provider,
            mb_substr($baseUrl, 0, 255),
            mb_substr($apiUser, 0, 120),
            $enc,
            $isActive,
            date('Y-m-d H:i:s'),
        ]);
        return (int) $db->lastInsertId();
    }

    /** @param array<string, mixed> $settings */
    public static function testProvider(int $id, array $settings = []): string
    {
        $account = self::getProvider($id, true);
        if ($account === null) {
            throw new \InvalidArgumentException('Provider account not found.');
        }
        $p = self::provider($account, $settings);
        if ($p === null) {
            throw new \RuntimeException('Unsupported provider type.');
        }

        $db = getDB();
        $now = date('Y-m-d H:i:s');
        try {
            $status = $p->testConnection();
            $db->prepare('UPDATE plg_billing_providers SET last_test_at = ?, last_test_status = ?, last_test_message = ? WHERE id = ?')
                ->execute([$now, 'ok', mb_substr($status, 0, 255), $id]);
            return $status;
        } catch (\Throwable $e) {
            $db->prepare('UPDATE plg_billing_providers SET last_test_at = ?, last_test_status = ?, last_test_message = ? WHERE id = ?')
                ->execute([$now, 'error', mb_substr($e->getMessage(), 0, 255), $id]);
            throw $e;
        }
    }

    public static function toggleProvider(int $id): int
    {
        $db = getDB();
        $st = $db->prepare('SELECT is_active FROM plg_billing_providers WHERE id = ?');
        $st->execute([$id]);
        $cur = $st->fetchColumn();
        if ($cur === false) {
            throw new \InvalidArgumentException('Provider account not found.');
        }
        $next = ((int) $cur === 1) ? 0 : 1;
        $db->prepare('UPDATE plg_billing_providers SET is_active = ? WHERE id = ?')->execute([$next, $id]);
        return $next;
    }

    public static function deleteProvider(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $db = getDB();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT id FROM plg_billing_providers WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $pending = $db->prepare("SELECT id FROM plg_billing_invoices WHERE provider_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
            $pending->execute([$id]);
            if ($pending->fetchColumn()) {
                throw new \RuntimeException('Match the pending drafts before deleting this provider.');
            }
            foreach (['plg_billing_calls', 'plg_billing_invoices', 'plg_billing_rates', 'plg_billing_customers'] as $table) {
                $db->prepare("DELETE FROM {$table} WHERE provider_id = ?")->execute([$id]);
            }
            $db->prepare('DELETE FROM plg_billing_providers WHERE id = ?')->execute([$id]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    // --- Customers & extension mappings ---

    /** @return list<array<string, mixed>> */
    public static function listCustomers(?int $providerId = null): array
    {
        $sql = 'SELECT c.*, p.name AS provider_name
                FROM plg_billing_customers c
                LEFT JOIN plg_billing_providers p ON p.id = c.provider_id';
        $params = [];
        if ($providerId !== null && $providerId > 0) {
            $sql .= ' WHERE c.provider_id = ?';
            $params[] = $providerId;
        }
        $sql .= ' ORDER BY c.customer_name ASC, c.id ASC';

        $st = getDB()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['provider_id'] = (int) $r['provider_id'];
            $r['is_active'] = (int) $r['is_active'];
        }
        unset($r);
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function getCustomer(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $st = getDB()->prepare('SELECT * FROM plg_billing_customers WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Normalizes comma/whitespace separated numeric extensions into a unique CSV string.
     *
     * @return list<string>
     */
    public static function parseExtensionsCsv(string $raw): array
    {
        $tokens = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $out = [];
        foreach ($tokens as $tok) {
            if ($tok === '') {
                continue;
            }
            if (!preg_match('/^\d{1,20}$/', $tok)) {
                throw new \InvalidArgumentException("Invalid extension \"{$tok}\": extensions must be numeric.");
            }
            if (!in_array($tok, $out, true)) {
                $out[] = $tok;
            }
        }
        if ($out === []) {
            throw new \InvalidArgumentException('At least one numeric extension is required.');
        }
        return $out;
    }

    /** @param array<string, mixed> $data */
    public static function saveCustomer(int $id, array $data): int
    {
        $providerId = (int) ($data['provider_id'] ?? 0);
        $provider = self::getProvider($providerId, false);
        if ($provider === null) {
            throw new \InvalidArgumentException('Please select a valid provider account.');
        }

        $externalClientId = trim((string) ($data['external_client_id'] ?? ''));
        if ($externalClientId === '' || mb_strlen($externalClientId) > 64) {
            throw new \InvalidArgumentException('External client ID is required.');
        }
        if ($provider['provider'] === 'clientfence' && (!ctype_digit($externalClientId) || (int) $externalClientId <= 0)) {
            throw new \InvalidArgumentException('ClientFence client ID must be a positive integer.');
        }

        $customerName = trim((string) ($data['customer_name'] ?? ''));
        if ($customerName === '') {
            throw new \InvalidArgumentException('Customer name is required.');
        }

        $exts = self::parseExtensionsCsv((string) ($data['extensions_csv'] ?? ''));
        $extensionsCsv = implode(',', $exts);
        if (strlen($extensionsCsv) > 500) {
            throw new \InvalidArgumentException('Extensions list exceeds maximum length.');
        }

        $rateRaw = trim((string) ($data['rate_per_minute'] ?? ''));
        $ratePerMinute = null;
        if ($rateRaw !== '') {
            $ratePerMinute = self::parseDecimalRate($rateRaw, 'Customer rate per minute');
        }

        $taxedRaw = (string) ($data['taxed'] ?? '');
        $taxed = ($taxedRaw === '1' || $taxedRaw === '0') ? (int) $taxedRaw : null;
        $isActive = !empty($data['is_active']) ? 1 : 0;

        $db = getDB();
        if ($isActive === 1) {
            $others = $db->prepare('SELECT id, customer_name, extensions_csv FROM plg_billing_customers WHERE provider_id = ? AND is_active = 1 AND id <> ?');
            $others->execute([$providerId, $id]);
            foreach ($others->fetchAll(PDO::FETCH_ASSOC) ?: [] as $other) {
                $otherExts = explode(',', (string) $other['extensions_csv']);
                $overlap = array_intersect($exts, $otherExts);
                if ($overlap !== []) {
                    throw new \InvalidArgumentException('Extension ' . reset($overlap) . ' is already assigned to "' . $other['customer_name'] . '".');
                }
            }
        }

        if ($id > 0) {
            $st = $db->prepare(
                'UPDATE plg_billing_customers
                 SET provider_id = ?, external_client_id = ?, customer_name = ?, extensions_csv = ?, rate_per_minute = ?, taxed = ?, is_active = ?
                 WHERE id = ?'
            );
            $st->execute([
                $providerId,
                $externalClientId,
                mb_substr($customerName, 0, 120),
                $extensionsCsv,
                $ratePerMinute,
                $taxed,
                $isActive,
                $id,
            ]);
            return $id;
        }

        $db->prepare(
            'INSERT INTO plg_billing_customers (provider_id, external_client_id, customer_name, extensions_csv, rate_per_minute, taxed, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $providerId,
            $externalClientId,
            mb_substr($customerName, 0, 120),
            $extensionsCsv,
            $ratePerMinute,
            $taxed,
            $isActive,
            date('Y-m-d H:i:s'),
        ]);
        return (int) $db->lastInsertId();
    }

    public static function deleteCustomer(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        getDB()->prepare('DELETE FROM plg_billing_customers WHERE id = ?')->execute([$id]);
    }

    // --- Destination prefix rates ---

    /** @return list<array<string, mixed>> */
    public static function listRates(?int $providerId = null): array
    {
        $sql = 'SELECT r.*, p.name AS provider_name
                FROM plg_billing_rates r
                LEFT JOIN plg_billing_providers p ON p.id = r.provider_id';
        $params = [];
        if ($providerId !== null && $providerId > 0) {
            $sql .= ' WHERE r.provider_id = ?';
            $params[] = $providerId;
        }
        $sql .= ' ORDER BY LENGTH(r.prefix) DESC, r.prefix ASC, r.id ASC';

        $st = getDB()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['provider_id'] = (int) $r['provider_id'];
        }
        unset($r);
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public static function getRate(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $st = getDB()->prepare('SELECT * FROM plg_billing_rates WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Strips spaces/punctuation, leading '+', and leading '00' for normalized prefix comparison. */
    public static function normalizeNumber(string $raw): string
    {
        $clean = preg_replace('/[^0-9+]/', '', trim($raw)) ?? '';
        $clean = ltrim($clean, '+');
        if (str_starts_with($clean, '00')) {
            $clean = substr($clean, 2);
        }
        return preg_replace('/\D+/', '', $clean) ?? '';
    }

    private static function parseDecimalRate(string $raw, string $fieldLabel): string
    {
        $val = str_replace(',', '.', trim($raw));
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $val)) {
            throw new \InvalidArgumentException("{$fieldLabel} must be a non-negative number (up to 4 decimals).");
        }
        return number_format((float) $val, 4, '.', '');
    }

    /** @param array<string, mixed> $data */
    public static function saveRate(int $id, array $data): int
    {
        $providerId = (int) ($data['provider_id'] ?? 0);
        if (self::getProvider($providerId, false) === null) {
            throw new \InvalidArgumentException('Please select a valid provider account.');
        }

        $prefix = self::normalizeNumber((string) ($data['prefix'] ?? ''));
        if ($prefix === '' || strlen($prefix) > 32) {
            throw new \InvalidArgumentException('Destination prefix must contain 1 to 32 digits.');
        }

        $label = trim((string) ($data['label'] ?? ''));
        $rate = self::parseDecimalRate((string) ($data['rate_per_minute'] ?? ''), 'Rate per minute');
        $db = getDB();

        if ($id === 0) {
            $existing = $db->prepare('SELECT id FROM plg_billing_rates WHERE provider_id = ? AND prefix = ?');
            $existing->execute([$providerId, $prefix]);
            $foundId = $existing->fetchColumn();
            if ($foundId !== false) {
                $id = (int) $foundId;
            }
        }

        if ($id > 0) {
            try {
                $db->prepare('UPDATE plg_billing_rates SET provider_id = ?, prefix = ?, label = ?, rate_per_minute = ? WHERE id = ?')
                    ->execute([$providerId, $prefix, mb_substr($label, 0, 120), $rate, $id]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate')) {
                    throw new \InvalidArgumentException("Prefix {$prefix} already exists for this provider.");
                }
                throw $e;
            }
            return $id;
        }

        $db->prepare(
            'INSERT INTO plg_billing_rates (provider_id, prefix, label, rate_per_minute, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$providerId, $prefix, mb_substr($label, 0, 120), $rate, date('Y-m-d H:i:s')]);
        return (int) $db->lastInsertId();
    }

    public static function deleteRate(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        getDB()->prepare('DELETE FROM plg_billing_rates WHERE id = ?')->execute([$id]);
    }

    // --- Rate lookup, billing math, and CDR processing ---

    /**
     * Longest-prefix rate lookup over normalized prefixes, falling back to customer override
     * and finally global default rate.
     *
     * @param list<array<string, mixed>> $rates
     * @return array{rate: float, prefix: string, label: string}
     */
    public static function resolveRate(string $destination, array $rates, ?float $customerRate, float $defaultRate): array
    {
        $normalized = self::normalizeNumber($destination);
        $bestPrefix = '';
        $bestRate = null;
        $bestLabel = '';

        foreach ($rates as $r) {
            $prefix = self::normalizeNumber((string) ($r['prefix'] ?? ''));
            if ($prefix === '' || $normalized === '') {
                continue;
            }
            if (str_starts_with($normalized, $prefix) && strlen($prefix) > strlen($bestPrefix)) {
                $bestPrefix = $prefix;
                $bestRate = (float) ($r['rate_per_minute'] ?? 0);
                $bestLabel = trim((string) ($r['label'] ?? ''));
            }
        }

        if ($bestRate !== null) {
            return ['rate' => $bestRate, 'prefix' => $bestPrefix, 'label' => $bestLabel];
        }
        if ($customerRate !== null) {
            return ['rate' => $customerRate, 'prefix' => '', 'label' => 'Customer rate'];
        }
        return ['rate' => $defaultRate, 'prefix' => '', 'label' => 'Default rate'];
    }

    /**
     * Computes billable seconds, units (minutes for 60/60, seconds for 1/1), and call amount.
     *
     * @return array{billed_seconds: int, billed_units: int, amount: float}
     */
    public static function calculateCallBilling(int $billsec, float $ratePerMinute, int $incrementSeconds = 60): array
    {
        if ($billsec <= 0) {
            return ['billed_seconds' => 0, 'billed_units' => 0, 'amount' => 0.0];
        }
        $inc = $incrementSeconds === 1 ? 1 : 60;
        $units = (int) ceil($billsec / $inc);
        $billedSeconds = $units * $inc;
        $amount = round(($billedSeconds / 60.0) * max(0.0, $ratePerMinute), 4);
        return [
            'billed_seconds' => $billedSeconds,
            'billed_units' => $units,
            'amount' => $amount,
        ];
    }

    /**
     * Pure call pricing & grouping over CDR legs grouped by linkedid.
     * Filters to outbound answered calls from active mapped customer extensions,
     * skips already-billed linkedids, applies longest-prefix rate lookup and billing increments.
     *
     * @param array<string, list<array<string, mixed>>> $legsByLinkedId
     * @param list<array<string, mixed>> $customers
     * @param list<array<string, mixed>> $rates
     * @param array<string, mixed> $settings
     * @param array<string, true> $alreadyBilled
     * @return array{
     *     customers: array<int, array<string, mixed>>,
     *     total_calls: int,
     *     total_billed_seconds: int,
     *     total_amount: float,
     *     currency: string,
     *     increment_seconds: int
     * }
     */
    public static function analyzeAndPriceCalls(
        array $legsByLinkedId,
        array $customers,
        array $rates,
        array $settings = [],
        ?CdrCallAnalyzer $analyzer = null,
        array $alreadyBilled = [],
        string $periodStart = '',
        string $periodEnd = ''
    ): array {
        if ($analyzer === null) {
            self::ensureAnalyzer();
            $analyzer = new CdrCallAnalyzer();
        }

        $currency = strtoupper(trim((string) ($settings['default_currency'] ?? 'USD')));
        if ($currency === '') {
            $currency = 'USD';
        }
        $defaultRateRaw = trim((string) ($settings['default_rate_per_minute'] ?? '0.05'));
        $defaultRate = is_numeric($defaultRateRaw) ? max(0.0, (float) $defaultRateRaw) : 0.05;
        $incrementSeconds = ((int) ($settings['billing_increment_seconds'] ?? 60)) === 1 ? 1 : 60;
        $defaultTaxed = ((string) ($settings['default_taxed'] ?? '1')) === '1';

        $extToCustomer = [];
        $customerById = [];
        foreach ($customers as $c) {
            if ((int) ($c['is_active'] ?? 1) !== 1) {
                continue;
            }
            $cid = (int) $c['id'];
            $customerById[$cid] = $c;
            foreach (explode(',', (string) ($c['extensions_csv'] ?? '')) as $ext) {
                $ext = trim($ext);
                if ($ext !== '') {
                    $extToCustomer[$ext] = $cid;
                }
            }
        }

        $startTs = $periodStart !== '' ? $periodStart . ' 00:00:00' : '';
        $endTs = $periodEnd !== '' ? $periodEnd . ' 23:59:59' : '';

        $grouped = [];
        $totalCalls = 0;
        $totalBilledSeconds = 0;
        $totalAmount = 0.0;

        foreach ($legsByLinkedId as $linkedid => $legs) {
            $lid = (string) $linkedid;
            if ($lid === '' || isset($alreadyBilled[$lid]) || $legs === []) {
                continue;
            }

            // Order legs identically to CdrCallAnalyzer so originating leg is first.
            usort($legs, static fn($a, $b): int => [
                (string) ($a['calldate'] ?? ''),
                str_starts_with((string) ($a['channel'] ?? ''), 'Local/'),
                (int) ($a['id'] ?? 0),
            ] <=> [
                (string) ($b['calldate'] ?? ''),
                str_starts_with((string) ($b['channel'] ?? ''), 'Local/'),
                (int) ($b['id'] ?? 0),
            ]);

            $calldate = '';
            foreach ($legs as $leg) {
                $d = trim((string) ($leg['calldate'] ?? ''));
                if ($d !== '' && ($calldate === '' || $d < $calldate)) {
                    $calldate = $d;
                }
            }
            if ($startTs !== '' && $calldate !== '' && $calldate < $startTs) {
                continue;
            }
            if ($endTs !== '' && $calldate !== '' && $calldate > $endTs) {
                continue;
            }

            $flow = $analyzer->analyze($legs);
            if (($flow['direction'] ?? '') !== CdrCallAnalyzer::OUTBOUND) {
                continue;
            }

            // Identify originating extension via caller_number or originating channel endpoint.
            $originExt = '';
            $caller = trim((string) ($flow['caller_number'] ?? ''));
            if ($caller !== '' && isset($extToCustomer[$caller])) {
                $originExt = $caller;
            } else {
                $ep = $analyzer->endpoint((string) ($legs[0]['channel'] ?? ''));
                if ($ep['kind'] === 'ext' && isset($extToCustomer[$ep['id']])) {
                    $originExt = $ep['id'];
                }
            }
            if ($originExt === '') {
                continue;
            }

            // An answered internal leg (e.g. the first leg of click-to-call) is not billable.
            $billsec = 0;
            foreach ($legs as $leg) {
                if (($leg['disposition'] ?? '') !== 'ANSWERED' || !in_array($leg['lastapp'] ?? '', ['Dial', 'Queue'], true)) {
                    continue;
                }
                $dst = $analyzer->endpoint((string) ($leg['dstchannel'] ?? ''));
                if ($dst['kind'] === 'trunk' && $dst['id'] === $flow['out_trunk']) {
                    $billsec = max($billsec, (int) ($leg['billsec'] ?? 0));
                }
            }
            if ($billsec <= 0) {
                continue;
            }

            $destination = trim((string) ($flow['out_number'] ?? ''));
            if ($destination === '' || self::normalizeNumber($destination) === '') {
                $destination = trim((string) ($flow['dialed_number'] ?? ''));
            }
            if ($destination === '') {
                continue;
            }

            $cid = $extToCustomer[$originExt];
            $customer = $customerById[$cid];
            $custRate = ($customer['rate_per_minute'] !== null && $customer['rate_per_minute'] !== '')
                ? (float) $customer['rate_per_minute']
                : null;

            $rateInfo = self::resolveRate($destination, $rates, $custRate, $defaultRate);
            $calc = self::calculateCallBilling($billsec, $rateInfo['rate'], $incrementSeconds);

            if (!isset($grouped[$cid])) {
                $custTaxed = $customer['taxed'];
                $taxed = ($custTaxed !== null && $custTaxed !== '') ? ((int) $custTaxed === 1) : $defaultTaxed;
                $grouped[$cid] = [
                    'customer' => $customer,
                    'taxed' => $taxed,
                    'call_count' => 0,
                    'raw_billsec' => 0,
                    'billed_seconds' => 0,
                    'total_amount' => 0.0,
                    'calls' => [],
                    'tiers' => [],
                ];
            }

            $callRecord = [
                'linkedid' => $lid,
                'customer_id' => $cid,
                'extension' => $originExt,
                'destination' => $destination,
                'calldate' => $calldate !== '' ? $calldate : date('Y-m-d H:i:s'),
                'billsec' => $billsec,
                'billed_seconds' => $calc['billed_seconds'],
                'billed_units' => $calc['billed_units'],
                'rate_per_minute' => $rateInfo['rate'],
                'rate_prefix' => $rateInfo['prefix'],
                'rate_label' => $rateInfo['label'],
                'amount' => $calc['amount'],
            ];

            $grouped[$cid]['calls'][] = $callRecord;
            $grouped[$cid]['call_count']++;
            $grouped[$cid]['raw_billsec'] += $billsec;
            $grouped[$cid]['billed_seconds'] += $calc['billed_seconds'];

            $tierKey = $rateInfo['prefix'] . '|' . number_format($rateInfo['rate'], 4, '.', '');
            if (!isset($grouped[$cid]['tiers'][$tierKey])) {
                $grouped[$cid]['tiers'][$tierKey] = [
                    'prefix' => $rateInfo['prefix'],
                    'label' => $rateInfo['label'],
                    'rate_per_minute' => $rateInfo['rate'],
                    'call_count' => 0,
                    'billed_seconds' => 0,
                    'billed_units' => 0,
                    'amount' => 0.0,
                ];
            }
            $grouped[$cid]['tiers'][$tierKey]['call_count']++;
            $grouped[$cid]['tiers'][$tierKey]['billed_seconds'] += $calc['billed_seconds'];
            $grouped[$cid]['tiers'][$tierKey]['billed_units'] += $calc['billed_units'];
            $grouped[$cid]['tiers'][$tierKey]['amount'] = round($grouped[$cid]['tiers'][$tierKey]['amount'] + $calc['amount'], 4);

            $totalCalls++;
            $totalBilledSeconds += $calc['billed_seconds'];
        }

        foreach ($grouped as &$g) {
            $g['items'] = self::buildInvoiceItems($g['tiers'], $g['taxed'], $incrementSeconds, $currency);
            $g['total_amount'] = self::invoiceSubtotal($g['items']);
            $totalAmount = round($totalAmount + $g['total_amount'], 2);
        }
        unset($g);

        return [
            'customers' => $grouped,
            'total_calls' => $totalCalls,
            'total_billed_seconds' => $totalBilledSeconds,
            'total_amount' => $totalAmount,
            'currency' => $currency,
            'increment_seconds' => $incrementSeconds,
        ];
    }

    /**
     * Converts aggregated rate tiers into ClientFence line items.
     *
     * @param array<string, array<string, mixed>> $tiers
     * @return list<array{title: string, price: float, quantity: int, taxed: bool, unit: string}>
     */
    public static function buildInvoiceItems(array $tiers, bool $taxed, int $incrementSeconds, string $currency): array
    {
        $items = [];
        foreach ($tiers as $tier) {
            $prefix = (string) $tier['prefix'];
            $label = (string) $tier['label'];
            $rate = (float) $tier['rate_per_minute'];
            $calls = (int) $tier['call_count'];
            $billedSec = (int) $tier['billed_seconds'];
            $units = (int) $tier['billed_units'];
            $tierAmount = (float) $tier['amount'];

            $desc = $label !== '' ? $label : ($prefix !== '' ? 'Prefix +' . $prefix : 'Standard');
            if ($prefix !== '' && !str_contains($desc, $prefix)) {
                $desc .= ' (+' . $prefix . ')';
            }

            if ($incrementSeconds === 60 && abs($rate - round($rate, 2)) < 0.00001) {
                $items[] = [
                    'title' => sprintf('VoIP Outbound Calls — %s (%d calls)', $desc, $calls),
                    'price' => round($rate, 2),
                    'quantity' => $units,
                    'taxed' => $taxed,
                    'unit' => 'min',
                ];
            } else {
                $mins = number_format($billedSec / 60.0, 2, '.', '');
                $rateStr = number_format($rate, 4, '.', '');
                $items[] = [
                    'title' => sprintf('VoIP Outbound Calls — %s (%d calls, %s min @ %s %s/min)', $desc, $calls, $mins, $rateStr, $currency),
                    'price' => round($tierAmount, 2),
                    'quantity' => 1,
                    'taxed' => $taxed,
                    'unit' => 'calls',
                ];
            }
        }
        return $items;
    }

    private static function invoiceSubtotal(array $items): float
    {
        return round(array_sum(array_map(
            static fn(array $item): float => round($item['price'] * $item['quantity'], 2),
            $items
        )), 2);
    }

    /** Validates YYYY-MM-DD period dates. */
    public static function validatePeriod(string $start, string $end): array
    {
        $s = trim($start);
        $e = trim($end);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) {
            throw new \InvalidArgumentException('Period start and end must use YYYY-MM-DD format.');
        }
        $sd = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        $ed = \DateTimeImmutable::createFromFormat('!Y-m-d', $e);
        if (!$sd || !$ed || $sd->format('Y-m-d') !== $s || $ed->format('Y-m-d') !== $e) {
            throw new \InvalidArgumentException('Invalid period date.');
        }
        if ($s > $e) {
            throw new \InvalidArgumentException('Period start date cannot be after end date.');
        }
        return [$s, $e];
    }

    /**
     * Locates the raw CDR table (`asteriskcdr` in AiPBX; falls back to `cdrs` if a test table
     * exposes raw Asterisk CDR columns).
     */
    public static function cdrSource(PDO $db): string
    {
        foreach (['asteriskcdr', 'cdrs'] as $table) {
            try {
                $db->query("SELECT id, uniqueid, linkedid, calldate, clid, src, dst, channel, dstchannel, lastapp, lastdata, billsec, disposition FROM {$table} LIMIT 0");
                return $table;
            } catch (\Throwable $e) {
            }
        }
        throw new \RuntimeException('CDR table (asteriskcdr) is not available.');
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} [trunkTitles, userNames] */
    private static function loadAnalyzerMaps(PDO $db): array
    {
        $trunks = [];
        try {
            foreach ($db->query('SELECT trunk_name, title FROM pbx_trunks')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) {
                $trunks[(string) $t['trunk_name']] = ((string) $t['title'] !== '') ? (string) $t['title'] : (string) $t['trunk_name'];
            }
        } catch (\Throwable $e) {
        }

        $users = [];
        try {
            foreach ($db->query("SELECT extension, full_name FROM sys_users WHERE extension IS NOT NULL AND extension <> ''")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
                $users[(string) $u['extension']] = (string) $u['full_name'];
            }
        } catch (\Throwable $e) {
        }

        return [$trunks, $users];
    }

    /**
     * Previews unbilled outbound calls and estimated charges per customer for a provider and period.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function previewUsage(int $providerId, string $periodStart, string $periodEnd, array $settings = []): array
    {
        [$start, $end] = self::validatePeriod($periodStart, $periodEnd);
        $provider = self::getProvider($providerId, false);
        if ($provider === null) {
            return [
                'customers' => [],
                'total_calls' => 0,
                'total_billed_seconds' => 0,
                'total_amount' => 0.0,
                'currency' => strtoupper(trim((string) ($settings['default_currency'] ?? 'USD'))) ?: 'USD',
                'increment_seconds' => ((int) ($settings['billing_increment_seconds'] ?? 60)) === 1 ? 1 : 60,
            ];
        }

        $db = getDB();
        $customers = array_values(array_filter(
            self::listCustomers($providerId),
            static fn(array $c): bool => (int) $c['is_active'] === 1
        ));
        if ($customers === []) {
            return [
                'customers' => [],
                'total_calls' => 0,
                'total_billed_seconds' => 0,
                'total_amount' => 0.0,
                'currency' => strtoupper(trim((string) ($settings['default_currency'] ?? 'USD'))) ?: 'USD',
                'increment_seconds' => ((int) ($settings['billing_increment_seconds'] ?? 60)) === 1 ? 1 : 60,
            ];
        }

        $rates = self::listRates($providerId);
        $cdrTable = self::cdrSource($db);

        // Fetch candidate linkedids with at least one answered billable leg in the period not yet billed.
        $candStmt = $db->prepare(
            "SELECT DISTINCT COALESCE(NULLIF(c.linkedid, ''), c.uniqueid) AS lid
             FROM {$cdrTable} c
             LEFT JOIN plg_billing_calls bc
                 ON bc.provider_id = ? AND bc.linkedid =
                    CONVERT(COALESCE(NULLIF(c.linkedid, ''), c.uniqueid) USING utf8mb4) COLLATE utf8mb4_unicode_ci
             WHERE c.calldate >= ? AND c.calldate <= ?
               AND c.disposition = 'ANSWERED'
               AND c.billsec > 0
               AND bc.linkedid IS NULL"
        );
        $candStmt->execute([$providerId, $start . ' 00:00:00', $end . ' 23:59:59']);
        $candidateIds = array_values(array_filter(array_map('strval', $candStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        if ($candidateIds === []) {
            return self::analyzeAndPriceCalls([], $customers, $rates, $settings);
        }

        // Load all legs for candidate linkedids so CdrCallAnalyzer sees complete call journeys.
        $legsByLinkedId = [];
        foreach (array_chunk($candidateIds, 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $legStmt = $db->prepare(
                "SELECT c.*, COALESCE(NULLIF(c.linkedid, ''), c.uniqueid) AS effective_linkedid
                 FROM {$cdrTable} c
                 WHERE c.linkedid IN ({$ph}) OR c.uniqueid IN ({$ph})
                 ORDER BY c.calldate ASC, (c.channel LIKE 'Local/%') ASC, c.id ASC"
            );
            $legStmt->execute(array_merge($chunk, $chunk));
            foreach ($legStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $leg) {
                $lid = (string) $leg['effective_linkedid'];
                $leg['linkedid'] = $lid;
                $legsByLinkedId[$lid][] = $leg;
            }
        }

        self::ensureAnalyzer();
        [$trunks, $users] = self::loadAnalyzerMaps($db);
        $analyzer = new CdrCallAnalyzer($trunks, $users);

        return self::analyzeAndPriceCalls($legsByLinkedId, $customers, $rates, $settings, $analyzer, [], $start, $end);
    }

    /**
     * Commits the claims and reference before sending the write to the provider.
     * If the outcome is unknown, the calls stay claimed until the draft is reconciled.
     *
     * @param array<string, mixed> $settings
     * @return array{created: int, failed: int, total_calls: int, total_amount: float, errors: list<string>, invoices: list<array<string, mixed>>}
     */
    public static function createDraftInvoices(int $providerId, string $periodStart, string $periodEnd, array $settings = []): array
    {
        [$start, $end] = self::validatePeriod($periodStart, $periodEnd);
        $account = self::getProvider($providerId, true);
        if ($account === null) {
            throw new \InvalidArgumentException('Provider account not found.');
        }
        if ((int) $account['is_active'] !== 1) {
            throw new \InvalidArgumentException('Provider account is disabled.');
        }
        $providerObj = self::provider($account, $settings);
        if ($providerObj === null || !$providerObj->configured()) {
            throw new \RuntimeException('Provider credentials are incomplete.');
        }

        $preview = self::previewUsage($providerId, $start, $end, $settings);
        $currency = $preview['currency'];
        $incrementSeconds = (int) $preview['increment_seconds'];

        $db = getDB();
        $isMysql = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
        $forUpdate = $isMysql ? ' FOR UPDATE' : '';

        $created = 0;
        $failed = 0;
        $totalCalls = 0;
        $totalAmount = 0.0;
        $errors = [];
        $invoices = [];

        foreach ($preview['customers'] as $customerId => $group) {
            $calls = $group['calls'];
            if ($calls === []) {
                continue;
            }

            $invoiceId = null;
            $reference = '';
            $submissionLocked = false;
            try {
                $externalClientId = (string) $group['customer']['external_client_id'];
                $actualCurrency = $providerObj->customerCurrency($externalClientId);
                if ($actualCurrency !== $currency) {
                    throw new \RuntimeException("Customer currency {$actualCurrency} does not match the tariff currency {$currency}.");
                }
                $db->beginTransaction();
                $providerLock = $db->prepare("SELECT is_active, base_url FROM plg_billing_providers WHERE id = ?{$forUpdate}");
                $providerLock->execute([$providerId]);
                $currentProvider = $providerLock->fetch(PDO::FETCH_ASSOC);
                if (!$currentProvider || (int) $currentProvider['is_active'] !== 1 || $currentProvider['base_url'] !== $account['base_url']) {
                    throw new \RuntimeException('The provider account changed. Preview the period again.');
                }
                // Serialize billing for this customer row across concurrent workers/requests.
                $lockStmt = $db->prepare("SELECT id, is_active, external_client_id FROM plg_billing_customers WHERE id = ? AND provider_id = ?{$forUpdate}");
                $lockStmt->execute([(int) $customerId, $providerId]);
                $custRow = $lockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$custRow || (int) $custRow['is_active'] !== 1) {
                    $db->rollBack();
                    continue;
                }
                if ((string) $custRow['external_client_id'] !== $externalClientId) {
                    throw new \RuntimeException('The customer mapping changed. Preview the period again.');
                }

                // Re-check already billed linkedids while holding the customer lock.
                $lids = array_values(array_map(static fn(array $c): string => (string) $c['linkedid'], $calls));
                $existingSet = [];
                foreach (array_chunk($lids, 400) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $seenStmt = $db->prepare("SELECT linkedid FROM plg_billing_calls WHERE provider_id = ? AND linkedid IN ({$ph}){$forUpdate}");
                    $seenStmt->execute(array_merge([$providerId], $chunk));
                    foreach ($seenStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $seenLid) {
                        $existingSet[(string) $seenLid] = true;
                    }
                }

                $pendingCalls = array_values(array_filter(
                    $calls,
                    static fn(array $c): bool => !isset($existingSet[(string) $c['linkedid']])
                ));
                if ($pendingCalls === []) {
                    $db->rollBack();
                    continue;
                }

                $now = date('Y-m-d H:i:s');
                $invStmt = $db->prepare(
                    "INSERT INTO plg_billing_invoices
                     (provider_id, customer_id, period_start, period_end, call_count, billed_seconds, total_amount, external_invoice_id, status, error, created_at)
                     VALUES (?, ?, ?, ?, 0, 0, 0.0000, '', 'pending', '', ?)"
                );
                $invStmt->execute([$providerId, (int) $customerId, $start, $end, $now]);
                $invoiceId = (int) $db->lastInsertId();

                $callIns = $db->prepare(
                    'INSERT INTO plg_billing_calls
                     (provider_id, linkedid, invoice_id, customer_id, extension, destination, calldate, billsec, billed_units, rate_per_minute, amount)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $claimedCalls = [];
                $claimedBilledSec = 0;
                $claimedTiers = [];

                foreach ($pendingCalls as $call) {
                    try {
                        $callIns->execute([
                            $providerId,
                            mb_substr((string) $call['linkedid'], 0, 150),
                            $invoiceId,
                            (int) $customerId,
                            mb_substr((string) $call['extension'], 0, 32),
                            mb_substr((string) $call['destination'], 0, 64),
                            (string) $call['calldate'],
                            (int) $call['billsec'],
                            (int) $call['billed_units'],
                            number_format((float) $call['rate_per_minute'], 4, '.', ''),
                            number_format((float) $call['amount'], 4, '.', ''),
                        ]);
                    } catch (\PDOException $dupEx) {
                        if ((string) $dupEx->getCode() === '23000' || str_contains($dupEx->getMessage(), 'Duplicate')) {
                            continue;
                        }
                        throw $dupEx;
                    }

                    $claimedCalls[] = $call;
                    $claimedBilledSec += (int) $call['billed_seconds'];

                    $tierKey = $call['rate_prefix'] . '|' . number_format((float) $call['rate_per_minute'], 4, '.', '');
                    if (!isset($claimedTiers[$tierKey])) {
                        $claimedTiers[$tierKey] = [
                            'prefix' => $call['rate_prefix'],
                            'label' => $call['rate_label'],
                            'rate_per_minute' => $call['rate_per_minute'],
                            'call_count' => 0,
                            'billed_seconds' => 0,
                            'billed_units' => 0,
                            'amount' => 0.0,
                        ];
                    }
                    $claimedTiers[$tierKey]['call_count']++;
                    $claimedTiers[$tierKey]['billed_seconds'] += (int) $call['billed_seconds'];
                    $claimedTiers[$tierKey]['billed_units'] += (int) $call['billed_units'];
                    $claimedTiers[$tierKey]['amount'] = round($claimedTiers[$tierKey]['amount'] + (float) $call['amount'], 4);
                }

                if ($claimedCalls === []) {
                    $db->rollBack();
                    continue;
                }

                $items = self::buildInvoiceItems($claimedTiers, (bool) $group['taxed'], $incrementSeconds, $currency);
                $claimedAmount = self::invoiceSubtotal($items);
                $submissionLocked = self::lockSubmission($db, $invoiceId);
                if (!$submissionLocked) {
                    throw new \RuntimeException('Draft submission is already in progress.');
                }
                $reference = "VoIP {$start} to {$end} AiPBX " . bin2hex(random_bytes(12));
                $db->prepare(
                    'UPDATE plg_billing_invoices SET external_invoice_id = ?, call_count = ?, billed_seconds = ?, total_amount = ? WHERE id = ?'
                )->execute([$reference, count($claimedCalls), $claimedBilledSec, number_format($claimedAmount, 2, '.', ''), $invoiceId]);
                $db->commit();

                $extInvoiceId = $providerObj->createDraftInvoice(
                    $externalClientId,
                    $reference,
                    $items
                );
                self::confirmDraft($db, $invoiceId, $reference, $extInvoiceId);

                $created++;
                $totalCalls += count($claimedCalls);
                $totalAmount = round($totalAmount + $claimedAmount, 2);
                $invoices[] = [
                    'id' => $invoiceId,
                    'customer_id' => (int) $customerId,
                    'external_invoice_id' => $extInvoiceId,
                    'call_count' => count($claimedCalls),
                    'total_amount' => $claimedAmount,
                    'status' => 'created',
                ];
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $failed++;
                $errMsg = mb_substr($e->getMessage(), 0, 255);
                if ($reference !== '') {
                    if ($e instanceof BillingDraftRejected) {
                        self::releaseRejectedDraft($db, $invoiceId, $reference, $errMsg);
                    } else {
                        $errMsg = mb_substr("Draft outcome unknown. Check ClientFence for reference {$reference} before retrying.", 0, 255);
                        try {
                            $db->prepare("UPDATE plg_billing_invoices SET error = ? WHERE id = ? AND status = 'pending'")
                                ->execute([$errMsg, $invoiceId]);
                        } catch (\Throwable $logEx) {
                        }
                    }
                    $errors[] = $group['customer']['customer_name'] . ': ' . $errMsg;
                    continue;
                }
                $errors[] = $group['customer']['customer_name'] . ': ' . $errMsg;

                try {
                    $db->prepare(
                        "INSERT INTO plg_billing_invoices
                         (provider_id, customer_id, period_start, period_end, call_count, billed_seconds, total_amount, external_invoice_id, status, error, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, '', 'failed', ?, ?)"
                    )->execute([
                        $providerId,
                        (int) $customerId,
                        $start,
                        $end,
                        count($calls),
                        (int) $group['billed_seconds'],
                        number_format((float) $group['total_amount'], 4, '.', ''),
                        $errMsg,
                        date('Y-m-d H:i:s'),
                    ]);
                } catch (\Throwable $logEx) {
                }
            } finally {
                if ($submissionLocked) {
                    self::unlockSubmission($db, $invoiceId);
                }
            }
        }

        return [
            'created' => $created,
            'failed' => $failed,
            'total_calls' => $totalCalls,
            'total_amount' => $totalAmount,
            'errors' => $errors,
            'invoices' => $invoices,
        ];
    }

    private static function confirmDraft(PDO $db, int $invoiceId, string $reference, string $externalId): void
    {
        $db->prepare("UPDATE plg_billing_invoices SET external_invoice_id = ?, status = 'created', error = ''
            WHERE id = ? AND status = 'pending' AND external_invoice_id = ?")->execute([$externalId, $invoiceId, $reference]);
    }

    private static function lockSubmission(PDO $db, int $invoiceId): bool
    {
        $st = $db->prepare("SELECT GET_LOCK(SHA2(CONCAT(DATABASE(), ':aipbx:billing:draft:', ?), 256), 0)");
        $st->execute([$invoiceId]);
        return (int) $st->fetchColumn() === 1;
    }

    private static function unlockSubmission(PDO $db, int $invoiceId): void
    {
        $db->prepare("SELECT RELEASE_LOCK(SHA2(CONCAT(DATABASE(), ':aipbx:billing:draft:', ?), 256))")->execute([$invoiceId]);
    }

    private static function releaseRejectedDraft(PDO $db, int $invoiceId, string $reference, string $error): void
    {
        $db->beginTransaction();
        try {
            $st = $db->prepare("UPDATE plg_billing_invoices SET external_invoice_id = '', status = 'failed', error = ?
                WHERE id = ? AND status = 'pending' AND external_invoice_id = ?");
            $st->execute([$error, $invoiceId, $reference]);
            if ($st->rowCount() === 1) {
                $db->prepare('DELETE FROM plg_billing_calls WHERE invoice_id = ?')->execute([$invoiceId]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function reconcileDraft(int $invoiceId, string $externalId, array $settings = []): void
    {
        $db = getDB();
        if (!self::lockSubmission($db, $invoiceId)) {
            throw new \RuntimeException('Draft submission is still in progress. Check its result first.');
        }
        try {
            $draft = self::pendingDraft($db, $invoiceId);
            $account = self::getProvider((int) $draft['provider_id'], true);
            $provider = $account === null ? null : self::provider($account, $settings);
            if ($provider === null || !$provider->configured()) {
                throw new \RuntimeException('Provider credentials are incomplete.');
            }
            $externalId = trim($externalId);
            $provider->verifyInvoiceReference($externalId, $draft['external_invoice_id']);
            self::confirmDraft($db, $invoiceId, $draft['external_invoice_id'], $externalId);
        } finally {
            self::unlockSubmission($db, $invoiceId);
        }
    }

    /** Releases a pending draft's claimed calls when an administrator confirms it was not created remotely. */
    public static function releasePendingDraft(int $invoiceId): void
    {
        $db = getDB();
        if (!self::lockSubmission($db, $invoiceId)) {
            throw new \RuntimeException('Draft submission is still in progress. Check its result first.');
        }
        try {
            $draft = self::pendingDraft($db, $invoiceId);
            self::releaseRejectedDraft(
                $db,
                $invoiceId,
                (string) $draft['external_invoice_id'],
                'Pending draft released by administrator.'
            );
        } finally {
            self::unlockSubmission($db, $invoiceId);
        }
    }

    private static function pendingDraft(PDO $db, int $invoiceId): array
    {
        $st = $db->prepare("SELECT provider_id, external_invoice_id FROM plg_billing_invoices WHERE id = ? AND status = 'pending'");
        $st->execute([$invoiceId]);
        $draft = $st->fetch(PDO::FETCH_ASSOC);
        if (!$draft || $draft['external_invoice_id'] === '') {
            throw new \InvalidArgumentException('No pending draft reference found.');
        }
        return $draft;
    }

    /** @return list<array<string, mixed>> */
    public static function listInvoices(?int $providerId = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT i.*, p.name AS provider_name, c.customer_name, c.external_client_id
                FROM plg_billing_invoices i
                LEFT JOIN plg_billing_providers p ON p.id = i.provider_id
                LEFT JOIN plg_billing_customers c ON c.id = i.customer_id';
        $params = [];
        if ($providerId !== null && $providerId > 0) {
            $sql .= ' WHERE i.provider_id = ?';
            $params[] = $providerId;
        }
        $sql .= " ORDER BY (i.status = 'pending') DESC, i.id DESC LIMIT {$limit}";

        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
