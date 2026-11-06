<?php

declare(strict_types=1);

/**
 * Twilio Programmable Messaging. Webhook: the number's "A message comes in" URL, HTTP POST.
 * Proof: X-Twilio-Signature, HMAC-SHA1 of the URL followed by the sorted form fields, keyed with the Auth Token.
 */
final class SmsTwilio extends SmsProvider
{
    public static function id(): string { return 'twilio'; }

    public static function label(): string { return 'Twilio'; }

    public function configured(): bool
    {
        return $this->setting('twilio_account_sid') !== '' && $this->setting('twilio_auth_token') !== '';
    }

    public function send(string $from, string $to, string $text, array $mediaUrls = []): string
    {
        // MediaUrl repeats once per link, so the form is built by hand.
        $form = http_build_query(['From' => $from, 'To' => $to, 'Body' => $text]);
        foreach ($mediaUrls as $url) {
            $form .= '&MediaUrl=' . rawurlencode($url);
        }
        $sid = $this->setting('twilio_account_sid');
        $answer = $this->call('POST', "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",
            [self::basic($sid, $this->setting('twilio_auth_token')), 'Content-Type: application/x-www-form-urlencoded'], $form);
        return (string) ($answer['sid'] ?? '');
    }

    public function inbound(array $request): array
    {
        $p = $request['post'];
        // Delivery reports carry MessageStatus and no Body.
        if (!isset($p['From'], $p['To']) || (!isset($p['Body']) && (int) ($p['NumMedia'] ?? 0) === 0)) {
            return [];
        }
        $media = [];
        for ($i = 0; $i < min(10, (int) ($p['NumMedia'] ?? 0)); $i++) {
            if (!empty($p["MediaUrl{$i}"])) {
                $media[] = (string) $p["MediaUrl{$i}"];
            }
        }
        return [['from' => self::e164((string) $p['From']), 'to' => self::e164((string) $p['To']), 'text' => (string) ($p['Body'] ?? ''), 'media' => $media, 'id' => (string) ($p['MessageSid'] ?? '')]];
    }

    public function verify(array $request): bool
    {
        $token = $this->setting('twilio_auth_token');
        $given = (string) ($request['headers']['x-twilio-signature'] ?? '');
        if ($token === '' || $given === '') {
            return false;
        }
        $post = $request['post'];
        ksort($post, SORT_STRING);
        $data = $request['url'];
        foreach ($post as $k => $v) {
            $data .= $k . (is_array($v) ? implode('', $v) : $v);
        }
        return hash_equals(base64_encode(hash_hmac('sha1', $data, $token, true)), $given);
    }

    public function mediaHeaders(string $url): array
    {
        // Twilio media may require the account's sign-in ("HTTP Basic Authentication for media").
        return parse_url($url, PHP_URL_HOST) === 'api.twilio.com'
            ? [self::basic($this->setting('twilio_account_sid'), $this->setting('twilio_auth_token'))] : [];
    }

    public function reply(): array
    {
        return ['text/xml; charset=utf-8', '<?xml version="1.0" encoding="UTF-8"?><Response></Response>'];
    }
}
