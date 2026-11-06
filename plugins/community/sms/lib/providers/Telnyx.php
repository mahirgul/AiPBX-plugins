<?php

declare(strict_types=1);

/**
 * Telnyx messaging. Webhook: the messaging profile's webhook URL, POST JSON (event message.received).
 * Proof (optional): telnyx-signature-ed25519 over "<telnyx-timestamp>|<body>", checked with the account's public key.
 */
final class SmsTelnyx extends SmsProvider
{
    public static function id(): string { return 'telnyx'; }

    public static function label(): string { return 'Telnyx'; }

    public function configured(): bool
    {
        return $this->setting('telnyx_api_key') !== '';
    }

    public function send(string $from, string $to, string $text, array $mediaUrls = []): string
    {
        $body = ['from' => $from, 'to' => $to, 'text' => $text];
        if ($mediaUrls !== []) {
            $body['media_urls'] = array_values($mediaUrls);
        }
        $answer = $this->call('POST', 'https://api.telnyx.com/v2/messages',
            ['Authorization: Bearer ' . $this->setting('telnyx_api_key'), 'Content-Type: application/json'], self::json($body));
        return (string) ($answer['data']['id'] ?? '');
    }

    public function inbound(array $request): array
    {
        $event = json_decode($request['body'], true);
        $m = $event['data']['payload'] ?? null;
        if (($event['data']['event_type'] ?? '') !== 'message.received' || !is_array($m)) {
            return [];
        }
        return [[
            'from' => self::e164((string) ($m['from']['phone_number'] ?? '')),
            'to' => self::e164((string) ($m['to'][0]['phone_number'] ?? '')),
            'text' => (string) ($m['text'] ?? ''),
            'media' => array_values(array_filter(array_map(static fn($x): string => (string) ($x['url'] ?? ''), (array) ($m['media'] ?? [])))),
            'id' => (string) ($m['id'] ?? ''),
        ]];
    }

    public function verify(array $request): bool
    {
        $key = base64_decode($this->setting('telnyx_public_key'), true);
        if ($this->setting('telnyx_public_key') === '') {
            return true;
        }
        $signature = base64_decode((string) ($request['headers']['telnyx-signature-ed25519'] ?? ''), true);
        $time = (string) ($request['headers']['telnyx-timestamp'] ?? '');
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $signature === false
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || !ctype_digit($time) || abs(time() - (int) $time) > 300) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($signature, $time . '|' . $request['body'], $key);
    }
}
