<?php

declare(strict_types=1);

/**
 * One SMS provider: one small file in lib/providers/ that extends this class. A provider knows three
 * things: how to send a message, how to read the message its webhook brings, and how to tell that
 * the webhook really came from it. Everything else (storing, who sees what, the pages) is shared.
 *
 * A webhook request is passed as an array: url (as the provider called it), method, headers
 * (lower-case names), body (raw), post, query.
 */
abstract class SmsProvider
{
    /** Sends HTTP requests; tests replace it. fn(string $method, string $url, array $headers, ?string $body): array{0: int, 1: string} */
    public static $http = null;

    /** @param array<string, string> $settings the plugin's settings */
    public function __construct(protected array $settings)
    {
    }

    /** Lower-case id, also the webhook path: /api/plugins/sms/<id>/<key>. */
    abstract public static function id(): string;

    abstract public static function label(): string;

    /** True when the settings this provider needs for sending are filled. */
    abstract public function configured(): bool;

    /**
     * Sends a message, MMS when $mediaUrls is not empty (public links the provider fetches).
     * Returns the provider's message id; throws with the provider's answer on failure.
     *
     * @param list<string> $mediaUrls
     */
    abstract public function send(string $from, string $to, string $text, array $mediaUrls = []): string;

    /**
     * The messages a webhook request brings: from, to (E.164), text, media (links), id.
     * Delivery reports and other events give none.
     *
     * @return list<array{from: string, to: string, text: string, media: list<string>, id: string}>
     */
    abstract public function inbound(array $request): array;

    /**
     * Whether the request carries the provider's own proof (signature, password). The secret key in
     * the webhook address is checked before this; return true when the provider has no proof of its own.
     */
    abstract public function verify(array $request): bool;

    /** Headers that let the PBX download a media link of this provider (some need the account's sign-in). */
    public function mediaHeaders(string $url): array
    {
        return [];
    }

    /** What the provider wants as the answer to its webhook. */
    public function reply(): array
    {
        return ['text/plain; charset=utf-8', 'ok'];
    }

    protected function setting(string $key): string
    {
        return trim((string) ($this->settings[$key] ?? ''));
    }

    /** +15551234567 from what a provider writes (15551234567, +1 555..., 5551234567 for a 10-digit US number). */
    public static function e164(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if ($digits === '') {
            return '';
        }
        return '+' . (strlen($digits) === 10 && !str_starts_with(trim($number), '+') ? '1' . $digits : $digits);
    }

    /** Calls the provider; returns the decoded JSON answer, or throws with its error. */
    protected function call(string $method, string $url, array $headers, ?string $body): array
    {
        $http = self::$http ?? [self::class, 'curl'];
        [$code, $answer] = $http($method, $url, $headers, $body);
        $json = json_decode($answer, true);
        if ($code < 200 || $code >= 300) {
            // The providers' own words where they put them, else the answer as it came.
            $first = is_array($json) ? ($json['errors'][0] ?? $json['error'] ?? $json) : null;
            $why = is_array($first) ? ($first['detail'] ?? $first['title'] ?? $first['message'] ?? $first['description'] ?? $first['error_text'] ?? $first['text'] ?? null) : $first;
            $why = is_string($why) && $why !== '' ? $why : (is_array($json) ? json_encode($json, JSON_UNESCAPED_UNICODE) : trim($answer));
            throw new \RuntimeException(static::label() . " {$code}: " . mb_substr((string) $why, 0, 200));
        }
        return is_array($json) ? $json : [];
    }

    /** @return array{0: int, 1: string} */
    public static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $answer = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($answer === false) {
            throw new \RuntimeException('SMS provider: ' . $err);
        }
        return [$code, (string) $answer];
    }

    protected static function basic(string $user, string $password): string
    {
        return 'Authorization: Basic ' . base64_encode($user . ':' . $password);
    }

    protected static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
