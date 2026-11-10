<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Provider.php';

/**
 * Vonage (Nexmo) Number Insight Advanced (CNAM) provider.
 * Query: GET https://api.nexmo.com/ni/advanced/json?api_key=...&api_secret=...&number=...&cnam=true
 * Response JSON:
 * {
 *   "status": 0,
 *   "caller_name": "ACME CORP",
 *   "caller_identity": { "caller_name": "ACME CORP", "caller_type": "business" }
 * }
 */
final class CnamVonage extends CnamProvider
{
    public static function id(): string
    {
        return 'vonage';
    }

    public static function label(): string
    {
        return 'Vonage';
    }

    public function configured(): bool
    {
        return $this->setting('vonage_api_key') !== '' && $this->setting('vonage_api_secret') !== '';
    }

    public function lookup(string $number): ?string
    {
        $apiKey = $this->setting('vonage_api_key');
        $apiSecret = $this->setting('vonage_api_secret');
        if ($apiKey === '' || $apiSecret === '') {
            return null;
        }

        $digits = self::digits($number);
        if ($digits === '') {
            return null;
        }

        $url = 'https://api.nexmo.com/ni/advanced/json?' . http_build_query([
            'api_key'    => $apiKey,
            'api_secret' => $apiSecret,
            'number'     => $digits,
            'cnam'       => 'true',
        ]);
        $headers = [
            'Accept: application/json',
        ];

        try {
            [$code, $body] = $this->request('GET', $url, $headers, null, 1);
            if ($code !== 200 || $body === '') {
                return null;
            }

            $data = json_decode($body, true);
            if (!is_array($data) || ((int) ($data['status'] ?? -1)) !== 0) {
                return null;
            }

            $name = $data['caller_name'] ?? $data['caller_identity']['caller_name'] ?? null;
            if (is_string($name) && $name !== '') {
                return self::sanitizeName($name);
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
