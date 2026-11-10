<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Provider.php';

/**
 * NumVerify / APILayer Phone Number Validation & Intelligence provider.
 * Query: GET https://apilayer.net/api/validate?access_key=...&number=...
 * Response JSON:
 * {
 *   "valid": true,
 *   "carrier": "AT&T Mobility LLC",
 *   "location": "Novato",
 *   "line_type": "mobile"
 * }
 */
final class CnamNumverify extends CnamProvider
{
    public static function id(): string
    {
        return 'numverify';
    }

    public static function label(): string
    {
        return 'NumVerify (APILayer)';
    }

    public function configured(): bool
    {
        return $this->setting('numverify_access_key') !== '';
    }

    public function lookup(string $number): ?string
    {
        $key = $this->setting('numverify_access_key');
        if ($key === '') {
            return null;
        }

        $digits = self::digits($number);
        if ($digits === '') {
            return null;
        }

        $url = 'https://apilayer.net/api/validate?' . http_build_query([
            'access_key' => $key,
            'number'     => $digits,
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
            $location = trim((string) ($data['location'] ?? ''));

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
