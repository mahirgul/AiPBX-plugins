<?php

declare(strict_types=1);

/** The host's supported HTTP API; no controller files or installation-relative paths. */
final class AssistantApi
{
    /** @var null|callable(string, string, array, ?string): array{0:int,1:string} Controlled test transport. */
    public static $http = null;

    public static function execute(string $method, string $path, array $body = []): array
    {
        $method = strtoupper($method);
        if (!PluginManager::isEnabled('ai-assistant')) {
            return self::error(403, 'AI Assistant is disabled.');
        }
        if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)
            || !preg_match('~^/api/v1/[a-zA-Z0-9_/-]+(?:\?[^\x00-\x20\x7f#]*)?$~D', $path)) {
            return self::error(400, 'Only supported /api/v1 requests are permitted.');
        }
        $token = PluginManager::getSetting('ai-assistant', '_worker_api_token', '');
        if ($token === '') {
            return self::error(403, 'Re-enable AI Assistant to create its API token.');
        }
        $payload = in_array($method, ['POST', 'PATCH'], true) ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        if ($payload !== null) { $headers[] = 'Content-Type: application/json'; }
        $transport = self::$http ?? [self::class, 'curl'];
        [$status, $raw] = $transport($method, 'https://127.0.0.1' . $path, $headers, $payload);
        $response = json_decode($raw, true);
        if ($status === 0) { return self::error(502, 'The local PBX API could not be reached.'); }
        return ['status_code' => $status, 'response' => is_array($response) ? $response : ['raw' => $raw]];
    }

    private static function error(int $status, string $message): array
    {
        return ['status_code' => $status, 'response' => ['success' => false, 'error' => $message]];
    }

    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            // This fixed loopback address cannot match the portal's public certificate name.
            // Never use these options for administrator- or model-supplied remote hosts.
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $raw = curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), is_string($raw) ? $raw : ''];
    }
}
