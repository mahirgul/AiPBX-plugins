<?php

declare(strict_types=1);

/**
 * VoIP.ms (US and Canadian numbers). Webhook: the DID's "SMS URL Callback", which fills placeholders
 * in the URL the admin page gives. VoIP.ms signs nothing: the secret key in the URL is the proof.
 * Method and placeholder names follow VoIP.ms's API pages as known; not tried live.
 */
final class SmsVoipms extends SmsProvider
{
    public static function id(): string { return 'voipms'; }

    public static function label(): string { return 'VoIP.ms'; }

    /** Query part of the callback URL: VoIP.ms puts the values in place of the braces. */
    public const CALLBACK_QUERY = '?from={FROM}&to={TO}&message={MESSAGE}&id={ID}&media={MEDIA}';

    public function configured(): bool
    {
        return $this->setting('voipms_username') !== '' && $this->setting('voipms_api_password') !== '';
    }

    public function send(string $from, string $to, string $text, array $mediaUrls = []): string
    {
        // VoIP.ms takes 10-digit North American numbers.
        $local = static fn(string $n): string => substr(preg_replace('/\D+/', '', $n) ?? '', -10);
        $query = ['api_username' => $this->setting('voipms_username'), 'api_password' => $this->setting('voipms_api_password'),
            'method' => $mediaUrls === [] ? 'sendSMS' : 'sendMMS', 'did' => $local($from), 'dst' => $local($to), 'message' => $text];
        foreach (array_slice(array_values($mediaUrls), 0, 3) as $i => $url) {
            $query['media' . ($i + 1)] = $url;
        }
        $answer = $this->call('GET', 'https://voip.ms/api/v1/rest.php?' . http_build_query($query), [], null);
        if (($answer['status'] ?? '') !== 'success') {
            throw new \RuntimeException('VoIP.ms: ' . (string) ($answer['status'] ?? 'no answer'));
        }
        return (string) ($answer['sms'] ?? $answer['mms'] ?? '');
    }

    public function inbound(array $request): array
    {
        $q = $request['query'] + $request['post'];
        if (!isset($q['from'], $q['to'])) {
            return [];
        }
        $media = array_values(array_filter(array_map('trim', explode(',', (string) ($q['media'] ?? ''))), static fn(string $u): bool => str_starts_with($u, 'https://')));
        return [['from' => self::e164((string) $q['from']), 'to' => self::e164((string) $q['to']), 'text' => (string) ($q['message'] ?? ''), 'media' => $media, 'id' => (string) ($q['id'] ?? '')]];
    }

    public function verify(array $request): bool
    {
        return true;
    }
}
