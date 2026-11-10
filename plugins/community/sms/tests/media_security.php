<?php
/** Run with PHP 8.2+ and curl; subprocess replaces only network functions, never the policy. */
if (($argv[1] ?? '') !== '--child') {
    passthru(escapeshellarg(PHP_BINARY) . ' -d disable_functions=dns_get_record,curl_init,curl_setopt_array,curl_exec,curl_getinfo '
        . escapeshellarg(__FILE__) . ' --child', $status);
    exit($status);
}
require dirname(__DIR__) . '/lib/Sms.php';
$requests = []; $responses = []; $addresses = ['93.184.215.14'];
if (($argv[1] ?? '') === '--child') {
function dns_get_record($host, $type) { global $addresses; return array_map(fn($ip) => [str_contains($ip, ':') ? 'ipv6' : 'ip' => $ip], $addresses); }
function curl_init($url) { return (object)['url' => $url, 'options' => [], 'response' => []]; }
function curl_setopt_array($ch, $options) { $ch->options = $options; return true; }
function curl_exec($ch) {
    global $requests, $responses;
    $requests[] = $ch;
    $ch->response = array_shift($responses) ?? ['code' => 200, 'chunks' => ['image']];
    foreach ($ch->response['chunks'] ?? [] as $chunk) {
        if (($ch->options[CURLOPT_WRITEFUNCTION])($ch, $chunk) !== strlen($chunk)) { return false; }
    }
    return true;
}
function curl_getinfo($ch, $key) {
    return match($key) {
        CURLINFO_RESPONSE_CODE => $ch->response['code'],
        CURLINFO_REDIRECT_URL => $ch->response['redirect'] ?? '',
        CURLINFO_CONTENT_TYPE => 'image/png',
    };
}
}
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function rejected(callable $fn): void {
    try { $fn(); } catch (RuntimeException $e) { return; }
    throw new LogicException('Unsafe download accepted');
}
$p = new SmsTwilio(['twilio_account_sid' => 'test', 'twilio_auth_token' => 'test']);
foreach (['https://127.0.0.1/a', 'https://10.0.0.1/a', 'https://224.0.0.1/a', 'https://169.254.169.254/a', 'https://[::1]/a',
    'https://[::ffff:127.0.0.1]/a', 'https://[fc00::1]/a', 'https://2130706433/a', 'http://example.com/a',
    'https://user:pass@example.com/a', 'https://example.com:8443/a', 'https://[2002:7f00:1::]/a'] as $url) {
    rejected(fn() => SmsMedia::download($p, $url, 10));
}
check($requests === [], 'Rejected URL connected');
$addresses = ['93.184.215.14', '127.0.0.1'];
rejected(fn() => SmsMedia::download($p, 'https://media.example.com/a', 10));
check($requests === [], 'Mixed public/private DNS connected');
$addresses = ['93.184.215.14'];
check(SmsMedia::download($p, 'https://api.twilio.com/a', 10) === ['image/png', 'image'], 'Valid download failed');
$o = $requests[0]->options;
check($o[CURLOPT_RESOLVE] === ['api.twilio.com:443:93.184.215.14'], 'DNS address not pinned');
check($o[CURLOPT_PROXY] === '' && !$o[CURLOPT_FOLLOWLOCATION] && $o[CURLOPT_SSL_VERIFYPEER] && $o[CURLOPT_SSL_VERIFYHOST] === 2, 'Unsafe curl options');
$responses = [['code' => 302, 'redirect' => 'https://127.0.0.1/private']];
$count = count($requests);
rejected(fn() => SmsMedia::download($p, 'https://api.twilio.com/a', 10));
check(count($requests) === $count + 1, 'Redirect reached internal server');
$responses = [['code' => 302, 'redirect' => 'https://cdn.example.com/a'], ['code' => 200, 'chunks' => ['ok']]];
SmsMedia::download($p, 'https://api.twilio.com/a', 10);
check(end($requests)->options[CURLOPT_HTTPHEADER] === [], 'Provider credentials leaked on redirect');
$responses = [['code' => 200, 'chunks' => ['12345678', '12345678']]];
rejected(fn() => SmsMedia::download($p, 'https://media.example.com/a', 10));
echo "OK: media URL, DNS, pinning, redirect, credential and streaming limits\n";
