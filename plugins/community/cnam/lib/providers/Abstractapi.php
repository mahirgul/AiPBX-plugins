<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Provider.php';

/**
 * AbstractAPI Phone Validation & Intelligence provider.
 * Query: GET https://phonevalidation.abstractapi.com/v1/?api_key=...&phone=...
 * Response JSON:
 * {
 *   "phone": "14154582468",
 *   "valid": true,
 *   "carrier": "Verizon USA",
 *   "registered_location": "San Francisco",
 *   "line_type": "Mobile"
 * }
 */
final class CnamAbstractapi extends CnamProvider
{
    public static function id(): string
    {
        return 'abstractapi';
    }

    public static function label(): string
    {
        return 'AbstractAPI';
    }

    public function configured(): bool
    {
        return $this->setting('abstractapi_api_key') !== '';
    }

    public function lookup(string $number): ?string
    {
        $key = $this->setting('abstractapi_api_key');
        if ($key === '') {
            return null;
        }

        $digits = self::digits($number);
        if ($digits === '') {
            return null;
        }

        $url = 'https://phonevalidation.abstractapi.com/v1/?' . http_build_query([
            'api_key' => $key,
            'phone'   => $digits,
        ]);

        try {
            [$code, $body] = $this->request('GET', $url, ['Accept: application/json'], null, 1);
            if ($code !== 200 || $body === '') {
                return null;
            }

            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['valid'])) {
                return null;
            }

            $carrier = trim((string) ($data['carrier'] ?? ''));
            $location = trim((string) ($data['registered_location'] ?? ''));

            if ($carrier !== '' && $location !== '') {
                return self::sanitizeName("{$carrier} ({$location})");
            }
            if ($carrier !== '') {
                return self::sanitizeName($carrier);
            }
            if ($location !== '') {
                return self::sanitizeName($location);
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
