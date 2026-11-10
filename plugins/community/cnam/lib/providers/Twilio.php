<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Provider.php';

/**
 * Twilio Lookup v2 (Caller Name) provider.
 * Query: GET https://lookups.twilio.com/v2/PhoneNumbers/{e164}?Fields=caller_name
 * Auth: HTTP Basic Auth (Account SID: Auth Token)
 * Response JSON:
 * {
 *   "phone_number": "+14159929960",
 *   "caller_name": {
 *     "caller_name": "TWILIO INC.",
 *     "caller_type": "BUSINESS",
 *     "error_code": null
 *   }
 * }
 */
final class CnamTwilio extends CnamProvider
{
    public static function id(): string
    {
        return 'twilio';
    }

    public static function label(): string
    {
        return 'Twilio';
    }

    public function configured(): bool
    {
        return $this->setting('twilio_account_sid') !== '' && $this->setting('twilio_auth_token') !== '';
    }

    public function lookup(string $number): ?string
    {
        $sid = $this->setting('twilio_account_sid');
        $token = $this->setting('twilio_auth_token');
        if ($sid === '' || $token === '') {
            return null;
        }

        $e164 = self::e164($number);
        if ($e164 === '') {
            return null;
        }

        $url = 'https://lookups.twilio.com/v2/PhoneNumbers/' . urlencode($e164) . '?Fields=caller_name';
        $headers = [
            'Authorization: Basic ' . base64_encode($sid . ':' . $token),
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

            $callerNameObj = $data['caller_name'] ?? null;
            if (is_array($callerNameObj) && !empty($callerNameObj['caller_name'])) {
                return self::sanitizeName((string) $callerNameObj['caller_name']);
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
