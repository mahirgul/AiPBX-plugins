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

// Mock HTTP for BulkVS JSON response
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
$assert($nameNull === null, 'BulkVS unavailable returns null: ' . var_export($nameNull, true));

// Mock HTTP for BulkVS Plain Text response
CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    return [200, 'ACME PLAIN TEXT'];
};
$namePlain = $bulkvs->lookup('2125551234');
$assert($namePlain === 'ACME PLAIN TEXT', 'BulkVS resolves plain text response: ' . var_export($namePlain, true));

// 4. OpenCNAM Provider tests
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

// 5. Custom URL Provider tests
$custom = new CnamCustom([
    'custom_url' => 'https://crm.local/cnam.php?num={number}',
]);
$assert($custom->configured(), 'Custom URL is configured');

CnamProvider::$http = static function (string $method, string $url, array $headers, ?string $body, int $timeout): array {
    return [200, json_encode(['caller_name' => 'CRM CLIENT'])];
};
$customName = $custom->lookup('12125551234');
$assert($customName === 'CRM CLIENT', 'Custom URL resolves caller name: ' . var_export($customName, true));

// 6. Provider Factory test
$assert(Cnam::provider('bulkvs', []) instanceof CnamBulkvs, 'Factory creates BulkVS');
$assert(Cnam::provider('opencnam', []) instanceof CnamOpencnam, 'Factory creates OpenCNAM');
$assert(Cnam::provider('custom', []) instanceof CnamCustom, 'Factory creates Custom');
$assert(Cnam::provider('unknown', []) === null, 'Factory returns null for unknown provider');

echo "\nAll {$asserts} CNAM provider & logic tests passed successfully!\n";
