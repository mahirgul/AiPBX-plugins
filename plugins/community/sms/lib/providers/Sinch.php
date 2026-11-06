<?php

declare(strict_types=1);

/**
 * Sinch SMS REST API. Webhook: the service plan's callback URL, POST JSON (mo_text, mo_media).
 * Sinch signs callbacks only when its account manager sets it up: the secret key in the URL is the proof.
 * MMS (mt_media) works in the US, Canada and Australia when Sinch has turned it on, one picture per message.
 */
final class SmsSinch extends SmsProvider
{
    private const REGIONS = ['us', 'eu', 'au', 'br', 'ca'];

    public static function id(): string { return 'sinch'; }

    public static function label(): string { return 'Sinch'; }

    public function configured(): bool
    {
        return $this->setting('sinch_service_plan_id') !== '' && $this->setting('sinch_api_token') !== '';
    }

    public function send(string $from, string $to, string $text, array $mediaUrls = []): string
    {
        $region = in_array($this->setting('sinch_region'), self::REGIONS, true) ? $this->setting('sinch_region') : 'us';
        $plan = rawurlencode($this->setting('sinch_service_plan_id'));
        $base = ['from' => ltrim($from, '+'), 'to' => [ltrim($to, '+')]];
        $bodies = $mediaUrls === []
            ? [$base + ['body' => $text]]
            : array_map(static fn(string $url, int $i): array => $base + ['type' => 'mt_media', 'body' => array_filter(['url' => $url, 'message' => $i === 0 ? $text : ''])], $mediaUrls, array_keys($mediaUrls));
        $ids = [];
        foreach ($bodies as $body) {
            $answer = $this->call('POST', "https://{$region}.sms.api.sinch.com/xms/v1/{$plan}/batches",
                ['Authorization: Bearer ' . $this->setting('sinch_api_token'), 'Content-Type: application/json'], self::json($body));
            $ids[] = (string) ($answer['id'] ?? '');
        }
        return implode(',', $ids);
    }

    public function inbound(array $request): array
    {
        $m = json_decode($request['body'], true);
        $type = (string) ($m['type'] ?? '');
        if (!is_array($m) || !in_array($type, ['mo_text', 'mo_media'], true)) {
            return [];
        }
        $body = $m['body'] ?? '';
        $text = is_array($body) ? (string) ($body['message'] ?? '') : (string) $body;
        $media = is_array($body) ? array_filter([(string) ($body['url'] ?? ''), ...array_map(static fn($x): string => is_array($x) ? (string) ($x['url'] ?? '') : '', (array) ($body['media'] ?? []))]) : [];
        return [['from' => self::e164((string) ($m['from'] ?? '')), 'to' => self::e164((string) ($m['to'] ?? '')), 'text' => $text, 'media' => array_values(array_unique($media)), 'id' => (string) ($m['id'] ?? '')]];
    }

    public function verify(array $request): bool
    {
        return true;
    }
}
