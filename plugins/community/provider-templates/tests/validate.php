<?php

declare(strict_types=1);

// php tests/validate.php: checks every template, and that the checks themselves refuse bad ones.
require dirname(__DIR__) . '/lib/Templates.php';

$failed = 0;
$check = static function (bool $ok, string $what) use (&$failed): void {
    if (!$ok) {
        echo "FAIL {$what}\n";
        $failed++;
    }
};

foreach (glob(dirname(__DIR__) . '/templates/*.json') ?: [] as $path) {
    $problems = ProviderTemplates::problems(json_decode((string) file_get_contents($path), true));
    $check($problems === [], basename($path) . ': ' . implode('; ', $problems));
    $check(preg_match('/^_?[a-z0-9-]{1,60}\.json$/D', basename($path)) === 1, basename($path) . ': file name must be lowercase letters, digits and -');
}

$good = json_decode((string) file_get_contents(dirname(__DIR__) . '/templates/_example.json'), true);
$bad = [
    'custom parameters' => ['trunk' => ['custom_pjsip_params' => 'outbound_proxy=sip:attacker.example']],
    'line break in a value' => ['trunk' => ['codecs' => "ulaw\nallow=all"]],
    'line break in the host' => ['host' => "sip.example.com\nbad=1"],
    'password' => ['auth_password' => 'secret'],
    'unknown key' => ['extra' => 1],
    'plain http documentation' => ['docs_url' => 'http://example.com'],
    'script in the name' => ['name' => '<script>x</script>'],
    'unknown source' => ['source' => 'a zip I found'],
    'bad tested' => ['tested' => ['by' => 'x']],
    'proxy with an extra option' => ['trunk' => ['outbound_proxy' => "sip:proxy.example.com\nallow=all"]],
    'number instead of text' => ['trunk' => ['port' => 5060]],
];
foreach ($bad as $what => $over) {
    $check(ProviderTemplates::problems(array_replace($good, $over)) !== [], "must be refused: {$what}");
}

$data = ProviderTemplates::trunkData(
    ['name' => 'X', 'send_e164' => true, 'trunk' => ['codecs' => 'ulaw', 'custom_pjsip_params' => 'x=1']],
    ['trunk_name' => 't', 'host' => 'h.example.com', 'country_code' => '+1', 'auth_username' => 'u', 'auth_password' => 'p', 'cid_prepend' => '+1', 'cid_keep_last' => '10'],
);
$check(!isset($data['custom_pjsip_params']), 'trunkData must drop fields that are not allowed');
$check($data['carrier_e164_country'] === '1', 'trunkData must keep digits of the country code');
$check($data['cid_keep_last'] === 10 && $data['codecs'] === 'ulaw', 'trunkData must carry the values');

if ($failed > 0) {
    echo "{$failed} problem(s)\n";
    exit(1);
}
echo "OK\n";
