<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Provider.php';

/**
 * Telnyx Number Lookup (CNAM) provider.
 * Query: GET https://api.telnyx.com/v2/number_lookup/{e164}?type=caller-name
 * Auth: Authorization: Bearer <telnyx_api_key>
 * Response JSON:
 * {
 *   "data": {
 *     "record_type": "number_lookup",
 *     "phone_number": "+13129457420",
 *     "caller_name": {
 *       "caller_name": "TELNYX LLC",
 *       "error_code": null
 *     }
 *   }
 * }
 */
final class CnamTelnyx extends CnamProvider
{
    public static function id(): string
    {
        return 'telnyx';
    }

    public static function label(): string
    {
        return 'Telnyx';
    }

    public function configured(): bool
    {
        return $this->setting('telnyx_api_key') !== '';
    }

    public function lookup(string $number): ?string
    {
        $apiKey = $this->setting('telnyx_api_key');
        if ($apiKey === '') {
            return null;
        }

        $e164 = self::e164($number);
        if ($e164 === '') {
            return null;
        }

        $url = 'https://api.telnyx.com/v2/number_lookup/' . urlencode($e164) . '?type=caller-name';
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
        ];

        try {
            [$code, $body] = $this->request('GET', $url, $headers, null, 1);
            if ($code !== 200 || $body === '') {
                return null;
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                return null;
            }

            $callerNameObj = $data['data']['caller_name'] ?? null;
            if (is_array($callerNameObj) && !empty($callerNameObj['caller_name'])) {
                return self::sanitizeName((string) $callerNameObj['caller_name']);
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
