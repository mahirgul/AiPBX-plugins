<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Provider.php';

/**
 * OpenCNAM v3 API provider.
 * Query: https://api.opencnam.com/v3/phone/+16505550100?format=json&account_sid=...&auth_token=...
 * Response: {"name":"MY CALLER ID","number":"+16505550100","price":0.0039}
 */
final class CnamOpencnam extends CnamProvider
{
    public static function id(): string
    {
        return 'opencnam';
    }

    public static function label(): string
    {
        return 'OpenCNAM';
    }

    public function configured(): bool
    {
        return $this->setting('opencnam_account_sid') !== '' && $this->setting('opencnam_auth_token') !== '';
    }

    public function lookup(string $number): ?string
    {
        $sid = $this->setting('opencnam_account_sid');
        $token = $this->setting('opencnam_auth_token');
        if ($sid === '' || $token === '') {
            return null;
        }

        $e164 = self::e164($number);
        if ($e164 === '') {
            return null;
        }

        $url = 'https://api.opencnam.com/v3/phone/' . urlencode($e164) . '?' . http_build_query([
            'format' => 'json',
            'account_sid' => $sid,
            'auth_token' => $token,
        ]);

        try {
            [$code, $body] = $this->request('GET', $url, [], null, 1);
            if ($code !== 200 || $body === '') {
                return null;
            }

            $data = json_decode($body, true);
            if (is_array($data) && !empty($data['name'])) {
                return self::sanitizeName((string) $data['name']);
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
