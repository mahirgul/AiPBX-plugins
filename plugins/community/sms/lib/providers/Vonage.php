<?php

declare(strict_types=1);

/**
 * Vonage Messages API (SMS everywhere, MMS in the US). Webhook: the application's Inbound URL, POST JSON.
 * Proof (optional): a signed webhook, a JWT (HS256, signature secret) in Authorization with payload_hash.
 */
final class SmsVonage extends SmsProvider
{
    public static function id(): string { return 'vonage'; }

    public static function label(): string { return 'Vonage'; }

    public function configured(): bool
    {
        return $this->setting('vonage_api_key') !== '' && $this->setting('vonage_api_secret') !== '';
    }

    public function send(string $from, string $to, string $text, array $mediaUrls = []): string
    {
        // Numbers without "+"; an MMS carries one picture, so each further one is its own message.
        $base = ['from' => ltrim($from, '+'), 'to' => ltrim($to, '+')];
        $bodies = $mediaUrls === []
            ? [$base + ['channel' => 'sms', 'message_type' => 'text', 'text' => $text]]
            : array_map(static fn(string $url, int $i): array => $base + ['channel' => 'mms', 'message_type' => 'image',
                'image' => array_filter(['url' => $url, 'caption' => $i === 0 ? $text : ''])], $mediaUrls, array_keys($mediaUrls));
        $ids = [];
        foreach ($bodies as $body) {
            $answer = $this->call('POST', 'https://api.nexmo.com/v1/messages',
                [self::basic($this->setting('vonage_api_key'), $this->setting('vonage_api_secret')), 'Content-Type: application/json', 'Accept: application/json'], self::json($body));
            $ids[] = (string) ($answer['message_uuid'] ?? '');
        }
        return implode(',', $ids);
    }

    public function inbound(array $request): array
    {
        $m = json_decode($request['body'], true);
        // Status callbacks carry "status" and no text or picture.
        if (!is_array($m) || !isset($m['from'], $m['to']) || isset($m['status'])) {
            return [];
        }
        $media = array_values(array_filter([(string) ($m['image']['url'] ?? '')]));
        if (!isset($m['text']) && $media === []) {
            return [];
        }
        return [['from' => self::e164((string) $m['from']), 'to' => self::e164((string) $m['to']), 'text' => (string) ($m['text'] ?? $m['image']['caption'] ?? ''), 'media' => $media, 'id' => (string) ($m['message_uuid'] ?? '')]];
    }

    public function verify(array $request): bool
    {
        $secret = $this->setting('vonage_signature_secret');
        if ($secret === '') {
            return true;
        }
        if (!preg_match('/^Bearer\s+([\w-]+)\.([\w-]+)\.([\w-]+)$/', (string) ($request['headers']['authorization'] ?? ''), $jwt)) {
            return false;
        }
        $b64 = static fn(string $s): string => (string) base64_decode(strtr($s, '-_', '+/'));
        $head = json_decode($b64($jwt[1]), true);
        $claims = json_decode($b64($jwt[2]), true);
        if (($head['alg'] ?? '') !== 'HS256' || !is_array($claims)
            || !hash_equals(hash_hmac('sha256', $jwt[1] . '.' . $jwt[2], $secret, true), $b64($jwt[3]))) {
            return false;
        }
        return !isset($claims['payload_hash']) || hash_equals((string) $claims['payload_hash'], hash('sha256', $request['body']));
    }
}
