<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Provider.php';

/**
 * SignalWire Phone Number Lookup (CNAM) provider.
 * Query: GET https://{space}.signalwire.com/api/relay/rest/lookup/phone_number/{e164}?include=cnam
 * Auth: HTTP Basic Auth (Project ID : API Token)
 */
final class CnamSignalwire extends CnamProvider
{
    public static function id(): string
    {
        return 'signalwire';
    }

    public static function label(): string
    {
        return 'SignalWire';
    }

    public function configured(): bool
    {
        return $this->setting('signalwire_space') !== ''
            && $this->setting('signalwire_project_id') !== ''
            && $this->setting('signalwire_token') !== '';
    }

    public function lookup(string $number): ?string
    {
        $space = trim($this->setting('signalwire_space'));
        $projectId = trim($this->setting('signalwire_project_id'));
        $token = trim($this->setting('signalwire_token'));

        if ($space === '' || $projectId === '' || $token === '') {
            return null;
        }

        $space = preg_replace('#^https?://#i', '', rtrim($space, '/'));
        if (!str_contains($space, '.')) {
            $space .= '.signalwire.com';
        }

        $e164 = self::e164($number);
        if ($e164 === '') {
            return null;
        }

        $url = 'https://' . $space . '/api/relay/rest/lookup/phone_number/' . urlencode($e164) . '?include=cnam';
        $headers = [
            'Authorization: Basic ' . base64_encode($projectId . ':' . $token),
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

            $name = $data['cnam']['caller_id'] ?? $data['cnam']['name'] ?? $data['cnam']['caller_name'] ?? $data['caller_name'] ?? null;
            if (is_string($name) && $name !== '') {
                return self::sanitizeName($name);
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
