<?php

declare(strict_types=1);

/** The provider explicitly rejected the write before creating a draft. */
final class BillingDraftRejected extends \RuntimeException
{
}

/**
 * Base class for external billing/invoicing providers.
 *
 * Each implementation sits in lib/providers/ and handles authentication,
 * connection checks, and draft invoice creation against its remote API.
 */
abstract class BillingProvider
{
    /**
     * Test transport replacement.
     * Signature: fn(string $method, string $url, array $headers, ?string $body): array{0: int, 1: string}
     */
    public static $http = null;

    /**
     * @param array<string, mixed> $account Provider account row (with decrypted api_key)
     * @param array<string, mixed> $settings Global plugin settings
     */
    public function __construct(protected array $account, protected array $settings = [])
    {
    }

    abstract public static function id(): string;

    abstract public static function label(): string;

    abstract public function configured(): bool;

    /** Verifies connectivity and API credentials; returns a concise status string or throws on failure. */
    abstract public function testConnection(): string;

    abstract public function customerCurrency(string $externalClientId): string;

    /** Verifies a provider invoice against the reference saved before submission. */
    abstract public function verifyInvoiceReference(string $externalInvoiceId, string $reference): void;

    /**
     * Creates an unapproved draft invoice on the external billing platform.
     *
     * @param list<array{title: string, price: float, quantity: int|float, taxed: bool, unit: string}> $items
     * @return string External invoice ID returned by the provider
     */
    abstract public function createDraftInvoice(string $externalClientId, string $periodText, array $items): string;

    protected function accountField(string $key, string $default = ''): string
    {
        $val = trim((string) ($this->account[$key] ?? ''));
        return $val !== '' ? $val : $default;
    }

    protected function setting(string $key, string $default = ''): string
    {
        $val = trim((string) ($this->settings[$key] ?? ''));
        return $val !== '' ? $val : $default;
    }

    /**
     * Validates and normalizes an HTTPS provider base URL.
     * Rejects non-HTTPS schemes, embedded credentials, query strings, fragments,
     * local hostnames, and non-public IP literals.
     */
    public static function validateBaseUrl(string $baseUrl): string
    {
        $url = rtrim(trim($baseUrl), '/');
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \InvalidArgumentException('Provider base URL is empty or contains invalid characters.');
        }

        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https') {
            throw new \InvalidArgumentException('Provider base URL must use https://.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new \InvalidArgumentException('Provider base URL must not contain embedded credentials.');
        }
        if (isset($p['query']) || isset($p['fragment'])) {
            throw new \InvalidArgumentException('Provider base URL must not contain a query string or fragment.');
        }

        $rawHost = (string) ($p['host'] ?? '');
        $host = trim($rawHost, '[]');
        if ($host === '') {
            throw new \InvalidArgumentException('Provider base URL must contain a valid hostname.');
        }

        $port = isset($p['port']) ? (int) $p['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('Provider base URL has an invalid port.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicIp($host)) {
                throw new \InvalidArgumentException('Provider base URL must not target a loopback, private, link-local, or reserved IP address.');
            }
        } else {
            if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $host)
                || preg_match('/\.(?:localhost|local|internal|localdomain|arpa)$/i', $host)) {
                throw new \InvalidArgumentException('Provider base URL must use a valid public hostname.');
            }
        }

        $path = rtrim((string) ($p['path'] ?? ''), '/');
        if ($path !== '' && (str_contains($path, '..') || preg_match('/[\x00-\x20\x7f]/', $path))) {
            throw new \InvalidArgumentException('Provider base URL path is invalid.');
        }

        $hostFormatted = str_contains($host, ':') ? '[' . strtolower($host) . ']' : strtolower($host);
        return 'https://' . $hostFormatted . ($port !== null ? ':' . $port : '') . $path;
    }

    /**
     * Resolves a validated HTTPS URL's hostname via DNS and verifies every returned IPv4/IPv6
     * address is publicly routable, returning CURLOPT_RESOLVE pinning entries.
     *
     * @return list<string> Entries suitable for CURLOPT_RESOLVE
     */
    public static function validatedResolve(string $url): array
    {
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host'])) {
            throw new \RuntimeException('Invalid HTTPS URL.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new \RuntimeException('URL must not contain credentials.');
        }

        $host = trim((string) $p['host'], '[]');
        $port = (int) ($p['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException('Invalid HTTPS port.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicIp($host)) {
                throw new \RuntimeException('Destination IP address is not publicly routable.');
            }
            return [];
        }

        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $host)
            || preg_match('/\.(?:localhost|local|internal|localdomain|arpa)$/i', $host)) {
            throw new \RuntimeException('Destination host is not a valid public domain.');
        }

        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = (string) $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }
        if ($addresses === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                foreach ($v4 as $ip) {
                    $addresses[] = (string) $ip;
                }
            }
        }

        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) {
            throw new \RuntimeException('Provider host did not resolve to any IP address.');
        }

        foreach ($addresses as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \RuntimeException('Provider host resolves to a non-public IP address.');
            }
        }

        $pinned = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
        return [strtolower($host) . ':' . $port . ':' . $pinned];
    }

    /** Returns true only when $ip is a publicly routable IPv4 or IPv6 unicast address. */
    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (defined('FILTER_FLAG_GLOBAL_RANGE') && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (!str_contains($ip, ':')) {
            $first = ord($packed[0]);
            return $first > 0 && $first < 224;
        }
        // Global unicast 2000::/3 only; block transition/6to4 (2002::/16) and Teredo (2001:0000::/32).
        if ((ord($packed[0]) & 0xe0) !== 0x20) {
            return false;
        }
        if (substr($packed, 0, 2) === "\x20\x02" || substr($packed, 0, 4) === "\x20\x01\x00\x00") {
            return false;
        }
        return true;
    }

    /**
     * Calls the provider HTTP endpoint and returns [statusCode, decodedJsonArray].
     *
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, mixed>}
     */
    protected function requestJson(string $method, string $url, array $headers, ?string $body): array
    {
        $http = self::$http ?? [self::class, 'curl'];
        [$code, $answer] = $http($method, $url, $headers, $body);
        $decoded = json_decode((string) $answer, true);
        if (!is_array($decoded)) {
            $snippet = mb_substr(trim((string) $answer), 0, 160);
            throw new \RuntimeException(static::label() . " HTTP {$code}: invalid JSON response" . ($snippet !== '' ? " ({$snippet})" : '.'));
        }
        return [$code, $decoded];
    }

    /** @return array{0: int, 1: string} */
    public static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $resolve = self::validatedResolve($url);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize HTTP client.');
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ];
        if ($resolve !== []) {
            $opts[CURLOPT_RESOLVE] = $resolve;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $answer = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($answer === false) {
            throw new \RuntimeException(static::label() . ' connection failed: ' . $err);
        }
        return [$code, (string) $answer];
    }

    protected static function basicAuthHeader(string $user, string $secret): string
    {
        return 'Authorization: Basic ' . base64_encode($user . ':' . $secret);
    }

    protected static function jsonEncode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
