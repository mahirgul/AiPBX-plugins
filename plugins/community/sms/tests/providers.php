<?php

declare(strict_types=1);

// php tests/providers.php: every provider's request, webhook reading and proof check, with the shapes
// from the providers' public documentation. No network, no database.
require dirname(__DIR__) . '/lib/Provider.php';
foreach (glob(dirname(__DIR__) . '/lib/providers/*.php') ?: [] as $f) {
    require $f;
}

$failed = 0;
$check = static function (bool $ok, string $what) use (&$failed): void {
    if (!$ok) {
        echo "FAIL {$what}\n";
        $failed++;
    }
};
$sent = [];
SmsProvider::$http = static function (string $method, string $url, array $headers, ?string $body) use (&$sent): array {
    $sent[] = compact('method', 'url', 'headers', 'body');
    $answers = [
        'twilio.com' => '{"sid":"SM1"}', 'nexmo.com' => '{"message_uuid":"v-1"}', 'plivo.com' => '{"message_uuid":["p-1"]}',
        'telnyx.com' => '{"data":{"id":"t-1"}}', 'bandwidth.com' => '{"id":"b-1"}', 'sinch.com' => '{"id":"s-1"}', 'voip.ms' => '{"status":"success","sms":"7"}',
        'bulkvs.com' => '{"status":"success","data":{"messageId":"bv-1"}}',
    ];
    foreach ($answers as $host => $answer) {
        if (str_contains((string) parse_url($url, PHP_URL_HOST), $host)) {
            return [str_contains($host, 'sinch') ? 201 : 200, $answer];
        }
    }
    return [404, ''];
};
$req = static fn(array $r): array => $r + ['url' => 'https://pbx.example.com/api/plugins/sms/x/key', 'method' => 'POST', 'headers' => [], 'body' => '', 'post' => [], 'query' => []];
$settings = [
    'twilio_account_sid' => 'AC1', 'twilio_auth_token' => 'tok', 'vonage_api_key' => 'k', 'vonage_api_secret' => 's', 'vonage_signature_secret' => 'sig',
    'plivo_auth_id' => 'MA1', 'plivo_auth_token' => 'ptok', 'telnyx_api_key' => 'KEY', 'bandwidth_account_id' => '99', 'bandwidth_application_id' => 'app',
    'bandwidth_username' => 'u', 'bandwidth_password' => 'p', 'sinch_service_plan_id' => 'plan', 'sinch_api_token' => 'st', 'sinch_region' => 'eu',
    'voipms_username' => 'me@example.com', 'voipms_api_password' => 'vp',
    'bulkvs_username' => 'buser', 'bulkvs_password' => 'bpass',
];

// --- e164 ---
$check(SmsProvider::e164('15551234567') === '+15551234567', 'e164 digits');
$check(SmsProvider::e164('5551234567') === '+15551234567', 'e164 10-digit US');
$check(SmsProvider::e164('+90 532 123 45 67') === '+905321234567', 'e164 spaces');

// --- Twilio ---
$p = new SmsTwilio($settings);
$check($p->send('+15550001111', '+15552223333', 'hi', ['https://a/1', 'https://a/2']) === 'SM1', 'twilio id');
$last = end($sent);
$check($last['url'] === 'https://api.twilio.com/2010-04-01/Accounts/AC1/Messages.json', 'twilio url');
$check(str_contains($last['body'], 'MediaUrl=https%3A%2F%2Fa%2F1&MediaUrl=https%3A%2F%2Fa%2F2') && str_contains($last['body'], 'From=%2B15550001111'), 'twilio form');
$post = ['MessageSid' => 'SM9', 'From' => '+15552223333', 'To' => '+15550001111', 'Body' => 'yo', 'NumMedia' => '1', 'MediaUrl0' => 'https://api.twilio.com/m/1'];
$in = $p->inbound($req(['post' => $post]));
$check(count($in) === 1 && $in[0]['text'] === 'yo' && $in[0]['media'] === ['https://api.twilio.com/m/1'] && $in[0]['id'] === 'SM9', 'twilio inbound');
$check($p->inbound($req(['post' => ['MessageSid' => 'SM9', 'MessageStatus' => 'delivered', 'From' => '+1', 'To' => '+2']])) === [], 'twilio status ignored');
// Twilio's documented example: URL + sorted fields, HMAC-SHA1 with the auth token, base64.
$url = 'https://mycompany.com/myapp.php?foo=1&bar=2';
$tp = ['CallSid' => 'CA1234567890ABCDE', 'Caller' => '+14158675310', 'Digits' => '1234', 'From' => '+14158675310', 'To' => '+18005551212'];
$tw = new SmsTwilio(['twilio_auth_token' => '12345'] + $settings);
$check($tw->verify($req(['url' => $url, 'post' => $tp, 'headers' => ['x-twilio-signature' => 'GvWf1cFY/Q7PnoempGyD5oXAezc=']])), 'twilio documented signature');
$check(!$tw->verify($req(['url' => $url, 'post' => ['Digits' => '9999'] + $tp, 'headers' => ['x-twilio-signature' => 'GvWf1cFY/Q7PnoempGyD5oXAezc=']])), 'twilio changed field refused');

// --- Vonage ---
$p = new SmsVonage($settings);
$p->send('+15550001111', '+15552223333', 'hi');
$last = end($sent);
$b = json_decode($last['body'], true);
$check($last['url'] === 'https://api.nexmo.com/v1/messages' && $b['channel'] === 'sms' && $b['from'] === '15550001111' && $b['text'] === 'hi', 'vonage sms');
$check($p->send('+15550001111', '+15552223333', 'cap', ['https://a/1', 'https://a/2']) === 'v-1,v-1', 'vonage mms one per picture');
$b = json_decode(end($sent)['body'], true);
$check($b['channel'] === 'mms' && $b['image']['url'] === 'https://a/2' && !isset($b['image']['caption']), 'vonage mms body');
$body = '{"channel":"sms","message_uuid":"u1","to":"15550001111","from":"15552223333","timestamp":"2025-02-03T12:14:25Z","text":"Hello","sms":{"num_messages":"1"}}';
$in = $p->inbound($req(['body' => $body]));
$check($in[0]['from'] === '+15552223333' && $in[0]['to'] === '+15550001111' && $in[0]['text'] === 'Hello', 'vonage inbound');
$check($p->inbound($req(['body' => '{"message_uuid":"u1","to":"1","from":"2","status":"delivered"}'])) === [], 'vonage status ignored');
$b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$jwt = static function (array $claims, string $secret) use ($b64): string {
    $hp = $b64('{"alg":"HS256","typ":"JWT"}') . '.' . $b64((string) json_encode($claims));
    return $hp . '.' . $b64(hash_hmac('sha256', $hp, $secret, true));
};
$good = $jwt(['iat' => time(), 'iss' => 'Vonage', 'payload_hash' => hash('sha256', $body)], 'sig');
$check($p->verify($req(['body' => $body, 'headers' => ['authorization' => 'Bearer ' . $good]])), 'vonage jwt');
$check(!$p->verify($req(['body' => $body . ' ', 'headers' => ['authorization' => 'Bearer ' . $good]])), 'vonage changed body refused');
$check(!$p->verify($req(['body' => $body, 'headers' => ['authorization' => 'Bearer ' . $jwt(['iat' => 1], 'other')]])), 'vonage wrong secret refused');

// --- Plivo ---
$p = new SmsPlivo($settings);
$check($p->send('+15550001111', '+15552223333', 'hi', ['https://a/1']) === 'p-1', 'plivo id');
$last = end($sent);
$b = json_decode($last['body'], true);
$check($last['url'] === 'https://api.plivo.com/v1/Account/MA1/Message/' && $b['src'] === '15550001111' && $b['type'] === 'mms' && $b['media_urls'] === ['https://a/1'], 'plivo send');
$in = $p->inbound($req(['post' => ['From' => '15552223333', 'To' => '15550001111', 'Text' => 'yo', 'Type' => 'mms', 'MessageUUID' => 'm1', 'Media0' => 'https://media.plivo.com/1']]));
$check($in[0]['to'] === '+15550001111' && $in[0]['media'] === ['https://media.plivo.com/1'], 'plivo inbound');
$sig = base64_encode(hash_hmac('sha256', 'https://pbx.example.com/api/plugins/sms/x/key' . 'n123', 'ptok', true));
$check($p->verify($req(['headers' => ['x-plivo-signature-v2' => $sig, 'x-plivo-signature-v2-nonce' => 'n123']])), 'plivo signature');
$check(!$p->verify($req(['headers' => ['x-plivo-signature-v2' => $sig, 'x-plivo-signature-v2-nonce' => 'n124']])), 'plivo wrong nonce refused');

// --- Telnyx ---
$keys = sodium_crypto_sign_keypair();
$p = new SmsTelnyx(['telnyx_public_key' => base64_encode(sodium_crypto_sign_publickey($keys))] + $settings);
$check($p->send('+15550001111', '+15552223333', 'hi', ['https://a/1']) === 't-1', 'telnyx id');
$last = end($sent);
$check(in_array('Authorization: Bearer KEY', $last['headers'], true) && json_decode($last['body'], true)['media_urls'] === ['https://a/1'], 'telnyx send');
$body = '{"data":{"event_type":"message.received","id":"e1","payload":{"id":"m1","from":{"phone_number":"+15552223333"},"to":[{"phone_number":"+15550001111"}],"text":"yo","media":[{"url":"https://telnyx/m.jpg","content_type":"image/jpeg"}]}}}';
$in = $p->inbound($req(['body' => $body]));
$check($in[0]['id'] === 'm1' && $in[0]['media'] === ['https://telnyx/m.jpg'] && $in[0]['to'] === '+15550001111', 'telnyx inbound');
$check($p->inbound($req(['body' => '{"data":{"event_type":"message.sent","payload":{}}}'])) === [], 'telnyx other events ignored');
$t = (string) time();
$sig = base64_encode(sodium_crypto_sign_detached($t . '|' . $body, sodium_crypto_sign_secretkey($keys)));
$check($p->verify($req(['body' => $body, 'headers' => ['telnyx-signature-ed25519' => $sig, 'telnyx-timestamp' => $t]])), 'telnyx signature');
$check(!$p->verify($req(['body' => $body, 'headers' => ['telnyx-signature-ed25519' => $sig, 'telnyx-timestamp' => (string) (time() - 600)]])), 'telnyx old timestamp refused');

// --- Bandwidth ---
$p = new SmsBandwidth($settings);
$check($p->send('+15550001111', '+15552223333', 'hi', ['https://a/1']) === 'b-1', 'bandwidth id');
$last = end($sent);
$b = json_decode($last['body'], true);
$check($last['url'] === 'https://messaging.bandwidth.com/api/v2/users/99/messages' && $b['to'] === ['+15552223333'] && $b['applicationId'] === 'app' && $b['media'] === ['https://a/1'], 'bandwidth send');
$body = '[{"type":"message-received","time":"2024-01-01T00:00:00Z","to":"+15550001111","message":{"id":"b9","from":"+15552223333","to":["+15550001111"],"text":"yo","media":["https://messaging.bandwidth.com/api/v2/users/99/media/a.jpg","https://messaging.bandwidth.com/api/v2/users/99/media/a.smil"]}}]';
$in = $p->inbound($req(['body' => $body]));
$check(count($in) === 1 && $in[0]['media'] === ['https://messaging.bandwidth.com/api/v2/users/99/media/a.jpg'], 'bandwidth inbound without smil');
$check($p->mediaHeaders('https://messaging.bandwidth.com/x') !== [] && $p->mediaHeaders('https://elsewhere/x') === [], 'bandwidth media sign-in only to its host');

// --- Sinch ---
$p = new SmsSinch($settings);
$check($p->send('+15550001111', '+15552223333', 'hi') === 's-1', 'sinch id');
$last = end($sent);
$b = json_decode($last['body'], true);
$check($last['url'] === 'https://eu.sms.api.sinch.com/xms/v1/plan/batches' && $b['to'] === ['15552223333'] && $b['body'] === 'hi', 'sinch send');
$p->send('+15550001111', '+15552223333', 'cap', ['https://a/1']);
$b = json_decode(end($sent)['body'], true);
$check($b['type'] === 'mt_media' && $b['body']['url'] === 'https://a/1' && $b['body']['message'] === 'cap', 'sinch mms');
$in = $p->inbound($req(['body' => '{"type":"mo_text","id":"s9","from":"15552223333","to":"15550001111","body":"yo","received_at":"2024-01-01T00:00:00Z"}']));
$check($in[0]['text'] === 'yo' && $in[0]['from'] === '+15552223333', 'sinch inbound');

// --- VoIP.ms ---
$p = new SmsVoipms($settings);
$check($p->send('+15550001111', '+15552223333', 'hi') === '7', 'voipms id');
parse_str((string) parse_url(end($sent)['url'], PHP_URL_QUERY), $q);
$check($q['method'] === 'sendSMS' && $q['did'] === '5550001111' && $q['dst'] === '5552223333', 'voipms send');
$p->send('+15550001111', '+15552223333', 'hi', ['https://a/1']);
parse_str((string) parse_url(end($sent)['url'], PHP_URL_QUERY), $q);
$check($q['method'] === 'sendMMS' && $q['media1'] === 'https://a/1', 'voipms mms');
$in = $p->inbound($req(['query' => ['from' => '5552223333', 'to' => '5550001111', 'message' => 'yo', 'id' => '12', 'media' => 'https://voip.ms/m/1.jpg']]));
$check($in[0]['from'] === '+15552223333' && $in[0]['media'] === ['https://voip.ms/m/1.jpg'], 'voipms inbound');

// --- BulkVS ---
$p = new SmsBulkvs($settings);
$check($p->send('+15550001111', '+15552223333', 'hi') === 'bv-1', 'bulkvs id');
$b = json_decode(end($sent)['body'], true);
$check($b['From'] === '15550001111' && $b['To'] === ['15552223333'] && $b['Message'] === 'hi', 'bulkvs send');
$p->send('+15550001111', '+15552223333', 'hi', ['https://a/1']);
$b = json_decode(end($sent)['body'], true);
$check($b['MediaUrls'] === ['https://a/1'], 'bulkvs mms');
$in = $p->inbound($req(['body' => '{"From":"15552223333","To":"15550001111","Message":"yo","MediaUrls":["https://bulkvs.com/m/1.jpg"],"MessageId":"bv-9"}']));
$check($in[0]['from'] === '+15552223333' && $in[0]['to'] === '+15550001111' && $in[0]['text'] === 'yo' && $in[0]['media'] === ['https://bulkvs.com/m/1.jpg'] && $in[0]['id'] === 'bv-9', 'bulkvs inbound');

// A failing provider answer becomes an error with its text.
SmsProvider::$http = static fn(): array => [401, '{"message":"bad key"}'];
try {
    (new SmsTelnyx($settings))->send('+1', '+2', 'x');
    $check(false, 'error answer throws');
} catch (\RuntimeException $e) {
    $check(str_contains($e->getMessage(), 'Telnyx 401') && str_contains($e->getMessage(), 'bad key'), 'error text');
}

echo $failed === 0 ? "OK\n" : "{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
