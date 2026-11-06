<?php

declare(strict_types=1);

/**
 * Plivo messaging. Webhook: the Plivo application's Message URL, method POST.
 * Proof: X-Plivo-Signature-V2, base64 HMAC-SHA256 of the URL followed by X-Plivo-Signature-V2-Nonce, keyed with the Auth Token.
 */
final class SmsPlivo extends SmsProvider
{
    public static function id(): string { return 'plivo'; }

    public static function label(): string { return 'Plivo'; }

    public function configured(): bool
    {
        return $this->setting('plivo_auth_id') !== '' && $this->setting('plivo_auth_token') !== '';
    }

    public function send(string $from, string $to, string $text, array $mediaUrls = []): string
    {
        $body = ['src' => ltrim($from, '+'), 'dst' => ltrim($to, '+'), 'text' => $text];
        if ($mediaUrls !== []) {
            $body += ['type' => 'mms', 'media_urls' => array_values($mediaUrls)];
        }
        $id = $this->setting('plivo_auth_id');
        $answer = $this->call('POST', "https://api.plivo.com/v1/Account/{$id}/Message/",
            [self::basic($id, $this->setting('plivo_auth_token')), 'Content-Type: application/json'], self::json($body));
        return (string) ($answer['message_uuid'][0] ?? '');
    }

    public function inbound(array $request): array
    {
        $p = $request['post'];
        if (!isset($p['From'], $p['To'], $p['MessageUUID'])) {
            return [];
        }
        $media = [];
        for ($i = 0; $i < 10 && !empty($p["Media{$i}"]); $i++) {
            $media[] = (string) $p["Media{$i}"];
        }
        return [['from' => self::e164((string) $p['From']), 'to' => self::e164((string) $p['To']), 'text' => (string) ($p['Text'] ?? ''), 'media' => $media, 'id' => (string) $p['MessageUUID']]];
    }

    public function verify(array $request): bool
    {
        $token = $this->setting('plivo_auth_token');
        $given = (string) ($request['headers']['x-plivo-signature-v2'] ?? '');
        $nonce = (string) ($request['headers']['x-plivo-signature-v2-nonce'] ?? '');
        if ($token === '' || $given === '' || $nonce === '') {
            return false;
        }
        return hash_equals(base64_encode(hash_hmac('sha256', $request['url'] . $nonce, $token, true)), $given);
    }
}
