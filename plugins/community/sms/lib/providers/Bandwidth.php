<?php

declare(strict_types=1);

/**
 * Bandwidth Messaging API v2. Webhook: the messaging application's callback URL, POST JSON (a list of
 * events; message-received brings a message). Bandwidth signs nothing: the secret key in the URL is the proof.
 * Send fields follow Bandwidth's public reference (from, to[], text, applicationId, media[]); not tried live.
 */
final class SmsBandwidth extends SmsProvider
{
    public static function id(): string { return 'bandwidth'; }

    public static function label(): string { return 'Bandwidth'; }

    public function configured(): bool
    {
        foreach (['bandwidth_account_id', 'bandwidth_application_id', 'bandwidth_username', 'bandwidth_password'] as $key) {
            if ($this->setting($key) === '') {
                return false;
            }
        }
        return true;
    }

    public function send(string $from, string $to, string $text, array $mediaUrls = []): string
    {
        $body = ['from' => $from, 'to' => [$to], 'text' => $text, 'applicationId' => $this->setting('bandwidth_application_id')];
        if ($mediaUrls !== []) {
            $body['media'] = array_values($mediaUrls);
        }
        $account = rawurlencode($this->setting('bandwidth_account_id'));
        $answer = $this->call('POST', "https://messaging.bandwidth.com/api/v2/users/{$account}/messages",
            [self::basic($this->setting('bandwidth_username'), $this->setting('bandwidth_password')), 'Content-Type: application/json'], self::json($body));
        return (string) ($answer['id'] ?? '');
    }

    public function inbound(array $request): array
    {
        $out = [];
        foreach ((array) json_decode($request['body'], true) as $event) {
            $m = $event['message'] ?? null;
            if (($event['type'] ?? '') !== 'message-received' || !is_array($m)) {
                continue;
            }
            $out[] = [
                'from' => self::e164((string) ($m['from'] ?? '')),
                'to' => self::e164((string) ($event['to'] ?? $m['to'][0] ?? '')),
                'text' => (string) ($m['text'] ?? ''),
                // Bandwidth also lists the SMIL part of an MMS; it is not something to show.
                'media' => array_values(array_filter((array) ($m['media'] ?? []), static fn($u): bool => is_string($u) && !str_ends_with(strtolower($u), '.smil'))),
                'id' => (string) ($m['id'] ?? ''),
            ];
        }
        return $out;
    }

    public function verify(array $request): bool
    {
        return true;
    }

    public function mediaHeaders(string $url): array
    {
        return parse_url($url, PHP_URL_HOST) === 'messaging.bandwidth.com'
            ? [self::basic($this->setting('bandwidth_username'), $this->setting('bandwidth_password'))] : [];
    }
}
