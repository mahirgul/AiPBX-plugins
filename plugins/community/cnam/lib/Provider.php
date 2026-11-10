<?php

declare(strict_types=1);

/**
 * Base class for CNAM (Caller Name) providers.
 */
abstract class CnamProvider
{
    /** Sends HTTP requests; tests replace it. fn(string $method, string $url, array $headers, ?string $body, int $timeout): array{0: int, 1: string} */
    public static $http = null;

    /** @param array<string, mixed> $settings the plugin's settings */
    public function __construct(protected array $settings)
    {
    }

    abstract public static function id(): string;
    abstract public static function label(): string;
    abstract public function configured(): bool;

    /**
     * Look up caller name for $number. Returns clean name or null if not found.
     */
    abstract public function lookup(string $number): ?string;

    protected function setting(string $key): string
    {
        return trim((string) ($this->settings[$key] ?? ''));
    }

    /** Cleans phone number digits */
    public static function digits(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
    }

    /** +15551234567 format */
    public static function e164(string $number): string
    {
        $d = self::digits($number);
        if ($d === '') {
            return '';
        }
        if (str_starts_with(trim($number), '+')) {
            return '+' . $d;
        }
        return '+' . (strlen($d) === 10 ? '1' . $d : $d);
    }

    /**
     * Sanitizes caller ID name:
     * - Trims whitespace
     * - Removes invalid ASCII/control chars
     * - Standard maximum length
     */
    public static function sanitizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $n = trim($name);
        if ($n === '') {
            return null;
        }
        // Known generic responses that mean "no name available"
        $lower = strtolower($n);
        $invalid = ['unavailable', 'unknown', 'anonymous', 'private', 'restricted', 'null', 'none'];
        if (in_array($lower, $invalid, true)) {
            return null;
        }
        // Remove characters that might break Asterisk or SIP display names (like " or ;)
        $clean = preg_replace('/["\\\\;\\r\\n]/', '', $n);
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) $clean));
        if ($clean === '') {
            return null;
        }
        return mb_substr($clean, 0, 60);
    }

    /** Calls the provider; returns [int $statusCode, string $body]. */
    protected function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 1): array
    {
        $http = self::$http ?? [self::class, 'curl'];
        return $http($method, $url, $headers, $body, $timeout);
    }

    /** @return array{0: int, 1: string} */
    public static function curl(string $method, string $url, array $headers, ?string $body, int $timeout = 1): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $answer = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($answer === false) {
            $err = curl_error($ch);
            throw new \RuntimeException('CNAM HTTP error: ' . $err);
        }
        return [$code, (string) $answer];
    }
}
