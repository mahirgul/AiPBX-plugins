<?php

declare(strict_types=1);

/**
 * BulkVS messaging (US and Canadian numbers).
 * Webhook: BulkVS Portal → Messaging → Messaging Webhooks (HTTP POST JSON).
 * Outbound API: https://portal.bulkvs.com/api/v1.0/messageSend with HTTP Basic Auth.
 * BulkVS signs nothing: the secret key in the webhook URL is the proof.
 */
final class SmsBulkvs extends SmsProvider
{
    public static function id(): string { return 'bulkvs'; }

    public static function label(): string { return 'BulkVS'; }

    public function configured(): bool
    {
        return $this->setting('bulkvs_username') !== '' && $this->setting('bulkvs_password') !== '';
    }

    public function send(string $from, string $to, string $text, array $mediaUrls = []): string
    {
        // BulkVS accepts 10 or 11-digit numbers (1XXXXXXXXXX).
        $format = static function (string $n): string {
            $digits = preg_replace('/\D+/', '', $n) ?? '';
            return strlen($digits) === 10 ? '1' . $digits : $digits;
        };

        $body = [
            'From' => $format($from),
            'To' => [$format($to)],
            'Message' => $text,
        ];
        if ($mediaUrls !== []) {
            $body['MediaUrls'] = array_slice(array_values($mediaUrls), 0, 3);
        }

        $headers = [
            self::basic($this->setting('bulkvs_username'), $this->setting('bulkvs_password')),
            'Content-Type: application/json',
        ];

        $answer = $this->call('POST', 'https://portal.bulkvs.com/api/v1.0/messageSend', $headers, self::json($body));
        return (string) ($answer['data']['messageId'] ?? $answer['messageId'] ?? $answer['id'] ?? 'bulkvs-' . bin2hex(random_bytes(6)));
    }

    public function inbound(array $request): array
    {
        $raw = json_decode($request['body'], true);
        $data = is_array($raw) ? $raw : ($request['post'] + $request['query']);

        $from = (string) ($data['From'] ?? $data['from'] ?? $data['callerid'] ?? '');
        $to = (string) ($data['To'] ?? $data['to'] ?? $data['did'] ?? '');
        if ($from === '' || $to === '') {
            return [];
        }

        $text = (string) ($data['Message'] ?? $data['message'] ?? $data['text'] ?? '');
        $rawMedia = $data['MediaUrls'] ?? $data['mediaUrls'] ?? $data['media_urls'] ?? $data['media'] ?? [];
        if (is_string($rawMedia)) {
            $rawMedia = explode(',', $rawMedia);
        }
        $media = array_values(array_filter(
            array_map('trim', (array) $rawMedia),
            static fn(string $u): bool => str_starts_with($u, 'https://') || str_starts_with($u, 'http://')
        ));

        $id = (string) ($data['MessageId'] ?? $data['messageId'] ?? $data['id'] ?? '');

        return [[
            'from' => self::e164($from),
            'to' => self::e164($to),
            'text' => $text,
            'media' => $media,
            'id' => $id,
        ]];
    }

    public function verify(array $request): bool
    {
        return true;
    }
}
