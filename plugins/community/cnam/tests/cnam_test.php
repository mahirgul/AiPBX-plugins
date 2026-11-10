<?php

declare(strict_types=1);

/**
 * Unit tests for CNAM plugin providers, sanitization, and lookup logic.
 * Run with: php plugins/community/cnam/tests/cnam_test.php
 */
require_once dirname(__DIR__) . '/lib/Cnam.php';

$asserts = 0;
$assert = static function (bool $condition, string $msg) use (&$asserts): void {
    $asserts++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
};

// 1. Sanitization tests
$assert(CnamProvider::sanitizeName(null) === null, 'null name returns null');
$assert(CnamProvider::sanitizeName('') === null, 'empty name returns null');
$assert(CnamProvider::sanitizeName('UNAVAILABLE') === null, 'UNAVAILABLE returns null');
$assert(CnamProvider::sanitizeName('unknown') === null, 'unknown returns null');
$assert(CnamProvider::sanitizeName('Anonymous') === null, 'Anonymous returns null');
$assert(CnamProvider::sanitizeName('  John Doe  ') === 'John Doe', 'whitespace trimmed');
$assert(CnamProvider::sanitizeName('Acme; "Corp"') === 'Acme Corp', 'special characters sanitized');

// 2. Number normalization tests
$assert(CnamProvider::digits('+1 (212) 555-1234') === '12125551234', 'digits extracted');
$assert(CnamProvider::e164('2125551234') === '+12125551234', '10-digit US number converted to E.164');
$assert(CnamProvider::e164('+442071838750') === '+442071838750', 'international number converted to E.164');

// 3. BulkVS Provider tests (Issue #159)
$bulkvs = new CnamBulkvs([
    'bulkvs_api_key' => 'secret_key_123',
    'bulkvs_endpoint' => 'https://cnam.bulkvs.com',
]);
$assert($bulkvs->configured(), 'BulkVS is configured when API key is provided');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    if (str_contains($url, '12125551234')) {
        return [200, json_encode([
            'status' => 'success',
            'code'   => 200,
            'did'    => '12125551234',
            'cnam'   => 'ACME SERVICES',
            'error'  => null,
        ])];
    }
    return [200, json_encode(['status' => 'success', 'code' => 200, 'cnam' => 'UNAVAILABLE'])];
};

$name = $bulkvs->lookup('2125551234');
$assert($name === 'ACME SERVICES', 'BulkVS resolves JSON name: ' . var_export($name, true));

$nameNull = $bulkvs->lookup('2125559999');
$assert($nameNull === null, 'BulkVS unavailable returns null');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    return [200, 'ACME PLAIN TEXT'];
};
$namePlain = $bulkvs->lookup('2125551234');
$assert($namePlain === 'ACME PLAIN TEXT', 'BulkVS resolves plain text response: ' . var_export($namePlain, true));

// 4. Telnyx Provider tests
$telnyx = new CnamTelnyx([
    'telnyx_api_key' => 'KEY0123456789',
]);
$assert($telnyx->configured(), 'Telnyx is configured when API key provided');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    $auth = in_array('Authorization: Bearer KEY0123456789', $headers, true);
    if ($auth && str_contains($url, 'v2/number_lookup/%2B13129457420')) {
        return [200, json_encode([
            'data' => [
                'record_type' => 'number_lookup',
                'phone_number' => '+13129457420',
                'caller_name' => [
                    'caller_name' => 'TELNYX LLC',
                    'error_code' => null,
                ],
            ],
        ])];
    }
    return [404, ''];
};
$telnyxName = $telnyx->lookup('+13129457420');
$assert($telnyxName === 'TELNYX LLC', 'Telnyx resolves caller name: ' . var_export($telnyxName, true));

// 5. Twilio Provider tests
$twilio = new CnamTwilio([
    'twilio_account_sid' => 'AC1234567890',
    'twilio_auth_token'  => 'auth_secret_token',
]);
$assert($twilio->configured(), 'Twilio is configured when credentials provided');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    $expectedAuth = 'Authorization: Basic ' . base64_encode('AC1234567890:auth_secret_token');
    $auth = in_array($expectedAuth, $headers, true);
    if ($auth && str_contains($url, 'v2/PhoneNumbers/%2B14159929960')) {
        return [200, json_encode([
            'phone_number' => '+14159929960',
            'caller_name' => [
                'caller_name' => 'TWILIO INC.',
                'caller_type' => 'BUSINESS',
                'error_code' => null,
            ],
        ])];
    }
    return [404, ''];
};
$twilioName = $twilio->lookup('+14159929960');
$assert($twilioName === 'TWILIO INC.', 'Twilio resolves caller name: ' . var_export($twilioName, true));

// 6. Vonage Provider tests
$vonage = new CnamVonage([
    'vonage_api_key'    => 'v_key_123',
    'vonage_api_secret' => 'v_sec_456',
]);
$assert($vonage->configured(), 'Vonage is configured when API key and secret provided');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    if (str_contains($url, 'api_key=v_key_123') && str_contains($url, 'number=12125551234')) {
        return [200, json_encode([
            'status' => 0,
            'caller_name' => 'VONAGE SERVICES',
        ])];
    }
    return [200, json_encode(['status' => 1])];
};
$vonageName = $vonage->lookup('12125551234');
$assert($vonageName === 'VONAGE SERVICES', 'Vonage resolves caller name: ' . var_export($vonageName, true));

// 7. SignalWire Provider tests
$signalwire = new CnamSignalwire([
    'signalwire_space'      => 'example.signalwire.com',
    'signalwire_project_id' => 'proj_abc',
    'signalwire_token'      => 'token_xyz',
]);
$assert($signalwire->configured(), 'SignalWire is configured when space, project and token provided');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    if (str_contains($url, 'example.signalwire.com/api/relay/rest/lookup/phone_number/%2B12125551234')) {
        return [200, json_encode([
            'cnam' => ['caller_id' => 'SIGNALWIRE CLIENT'],
        ])];
    }
    return [404, ''];
};
$swName = $signalwire->lookup('+12125551234');
$assert($swName === 'SIGNALWIRE CLIENT', 'SignalWire resolves caller name: ' . var_export($swName, true));

// Official response field: https://signalwire.com/docs/apis/rest/phone-number-lookup/lookup-phone-number
CnamProvider::$http = static fn(): array => [200, '{"cnam":{"caller_id":null}}'];
$assert($signalwire->lookup('+12125551234') === null, 'SignalWire absent caller ID returns no name');
CnamProvider::$http = static fn(): array => [200, '{"cnam":{"caller_id":"UNKNOWN"}}'];
$assert($signalwire->lookup('+12125551234') === null, 'SignalWire generic caller ID is ignored');

// 8. NumVerify Provider tests
$numverify = new CnamNumverify([
    'numverify_access_key' => 'access_key_999',
]);
$assert($numverify->configured(), 'NumVerify is configured when access key provided');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    if (str_contains($url, 'access_key=access_key_999') && str_contains($url, 'number=14158586273')) {
        return [200, json_encode([
            'valid'    => true,
            'carrier'  => 'AT&T Mobility LLC',
            'location' => 'Novato',
        ])];
    }
    return [200, json_encode(['valid' => false])];
};
$nvName = $numverify->lookup('14158586273');
$assert($nvName === 'AT&T Mobility LLC (Novato)', 'NumVerify resolves carrier + location: ' . var_export($nvName, true));

// 9. AbstractAPI Provider tests
$abstractapi = new CnamAbstractapi([
    'abstractapi_api_key' => 'abs_key_555',
]);
$assert($abstractapi->configured(), 'AbstractAPI is configured when API key provided');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    if (str_contains($url, 'api_key=abs_key_555') && str_contains($url, 'phone=14154582468')) {
        return [200, json_encode([
            'valid'               => true,
            'carrier'             => 'Verizon USA',
            'registered_location' => 'San Francisco',
        ])];
    }
    return [200, json_encode(['valid' => false])];
};
$absName = $abstractapi->lookup('14154582468');
$assert($absName === 'Verizon USA (San Francisco)', 'AbstractAPI resolves carrier + location: ' . var_export($absName, true));

// 10. OpenCNAM Provider tests
$opencnam = new CnamOpencnam([
    'opencnam_account_sid' => 'ACtest123',
    'opencnam_auth_token'  => 'auth_token_xyz',
]);
$assert($opencnam->configured(), 'OpenCNAM is configured when credentials provided');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    return [200, json_encode([
        'name'   => 'OPENCNAM CALLER',
        'number' => '+16505550100',
        'price'  => 0.0039,
    ])];
};
$ocName = $opencnam->lookup('+16505550100');
$assert($ocName === 'OPENCNAM CALLER', 'OpenCNAM resolves caller name: ' . var_export($ocName, true));

// 11. Custom URL Provider tests
$custom = new CnamCustom([
    'custom_url' => 'https://crm.local/cnam.php?num={number}',
]);
$assert($custom->configured(), 'Custom URL is configured');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    return [200, json_encode(['caller_name' => 'CRM CLIENT'])];
};
$customName = $custom->lookup('12125551234');
$assert($customName === 'CRM CLIENT', 'Custom URL resolves caller name: ' . var_export($customName, true));

// 12. Provider Factory tests for all registered providers
$assert(Cnam::provider('bulkvs', []) instanceof CnamBulkvs, 'Factory creates BulkVS');
$assert(Cnam::provider('telnyx', []) instanceof CnamTelnyx, 'Factory creates Telnyx');
$assert(Cnam::provider('twilio', []) instanceof CnamTwilio, 'Factory creates Twilio');
$assert(Cnam::provider('vonage', []) instanceof CnamVonage, 'Factory creates Vonage');
$assert(Cnam::provider('signalwire', []) instanceof CnamSignalwire, 'Factory creates SignalWire');
$assert(Cnam::provider('numverify', []) instanceof CnamNumverify, 'Factory creates NumVerify');
$assert(Cnam::provider('abstractapi', []) instanceof CnamAbstractapi, 'Factory creates AbstractAPI');
$assert(Cnam::provider('opencnam', []) instanceof CnamOpencnam, 'Factory creates OpenCNAM');
$assert(Cnam::provider('custom', []) instanceof CnamCustom, 'Factory creates Custom');
$assert(Cnam::provider('unknown', []) === null, 'Factory returns null for unknown provider');

echo "\nAll {$asserts} CNAM provider & logic tests passed successfully!\n";
