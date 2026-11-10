<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Provider.php';

/**
 * BulkVS CNAM provider (Issue #159).
 * Query: https://cnam.bulkvs.com/?id=YOUR_API_KEY&did=PHONE_NUMBER&format=json
 * Response: {"status":"success","code":200,"did":"12125551234","cnam":"SAMPLE BUSINESS","error":null}
 * Or plain text if format parameter is omitted.
 */
final class CnamBulkvs extends CnamProvider
{
    public static function id(): string
    {
        return 'bulkvs';
    }

    public static function label(): string
    {
        return 'BulkVS';
    }

    public function configured(): bool
    {
        return $this->setting('bulkvs_api_key') !== '';
    }

    public function lookup(string $number): ?string
    {
        $apiKey = $this->setting('bulkvs_api_key');
        if ($apiKey === '') {
            return null;
        }

        // BulkVS expects 10 or 11 digits (e.g. 1XXXXXXXXXX or XXXXXXXXXX)
        $digits = self::digits($number);
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }
        if ($digits === '') {
            return null;
        }

        $endpoint = rtrim($this->setting('bulkvs_endpoint'), '/');
        if ($endpoint === '') {
            $endpoint = 'https://cnam.bulkvs.com';
        }

        $url = $endpoint . '/?' . http_build_query([
            'id' => $apiKey,
            'did' => $digits,
            'format' => 'json',
        ]);

        try {
            [$code, $body] = $this->request('GET', $url, [], null, 3);
            if ($code !== 200 || $body === '') {
                return null;
            }

            $data = json_decode($body, true);
            if (is_array($data)) {
                $name = $data['cnam'] ?? $data['name'] ?? null;
                return self::sanitizeName(is_string($name) ? $name : null);
            }

            // Fallback if plain text was returned
            return self::sanitizeName($body);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
