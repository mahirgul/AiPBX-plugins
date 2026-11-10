<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Provider.php';

/**
 * Custom HTTPS URL endpoint provider.
 * Replaces {number} in URL or appends ?num=...
 * If JSON response: checks 'cnam', 'name', 'caller_name', 'callerid'
 * Else parses plain text.
 */
final class CnamCustom extends CnamProvider
{
    public static function id(): string
    {
        return 'custom';
    }

    public static function label(): string
    {
        return 'Custom URL';
    }

    public function configured(): bool
    {
        return $this->setting('custom_url') !== '';
    }

    public function lookup(string $number): ?string
    {
        $urlTemplate = $this->setting('custom_url');
        if ($urlTemplate === '') {
            return null;
        }

        $clean = preg_replace('/[^\d+]/', '', $number) ?? '';
        if ($clean === '') {
            return null;
        }

        if (str_contains($urlTemplate, '{number}')) {
            $url = str_replace('{number}', urlencode($clean), $urlTemplate);
        } else {
            $sep = str_contains($urlTemplate, '?') ? '&' : '?';
            $url = $urlTemplate . $sep . 'num=' . urlencode($clean);
        }

        try {
            [$code, $body] = $this->request('GET', $url, [], null, 1);
            if ($code !== 200 || trim($body) === '') {
                return null;
            }

            $data = json_decode($body, true);
            if (is_array($data)) {
                $name = $data['cnam'] ?? $data['name'] ?? $data['caller_name'] ?? $data['callerid'] ?? null;
                return self::sanitizeName(is_string($name) ? $name : null);
            }

            return self::sanitizeName($body);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
