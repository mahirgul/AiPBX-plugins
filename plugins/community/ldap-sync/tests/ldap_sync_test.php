<?php

declare(strict_types=1);

/**
 * Unit tests for the directory → phonebook mapping and the sync plan.
 * Run with: php plugins/community/ldap-sync/tests/ldap_sync_test.php
 */
require_once dirname(__DIR__) . '/lib/LdapSync.php';

$asserts = 0;
$assert = static function (bool $condition, string $msg) use (&$asserts): void {
    $asserts++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
};

$defaults = [];
foreach (json_decode((string) file_get_contents(dirname(__DIR__) . '/plugin.json'), true)['settings_schema'] as $f) {
    $defaults[$f['key']] = (string) $f['default'];
}
$map = LdapSync::attributeMap($defaults);
$assert(count($map) === 8 && $map['phone_work'] === 'telephonenumber', 'default attributes are mapped, lower-case');
$assert(!isset(LdapSync::attributeMap(['attr_name' => 'cn) (x'])['display_name']), 'an attribute name with filter characters is refused');
$assert(LdapSync::idAttribute($defaults) === 'objectguid', 'default id attribute is lower-case');
$idRejected = false;
try {
    LdapSync::idAttribute(['attr_id' => 'objectGUID)(']);
} catch (InvalidArgumentException) {
    $idRejected = true;
}
$assert($idRejected, 'an id attribute name with invalid characters is refused');

$entry = static fn(array $a, string $dn = 'CN=Ayse Yilmaz,OU=Staff,DC=example,DC=local'): array =>
    array_map(static fn($v) => is_array($v) ? $v : ['count' => 1, 0 => $v], $a) + ['dn' => $dn];

$row = LdapSync::rowFromEntry($entry(['displayname' => 'Ayşe Yılmaz', 'telephonenumber' => '+90 (212) 555-00-01',
    'mail' => 'ayse@example.com', 'company' => 'Acme', 'objectguid' => "\x01\x02\xff\xfe"]), $map, 'objectguid');
$assert($row !== null && $row['display_name'] === 'Ayşe Yılmaz', 'a person with a number becomes a contact');
$assert($row['phone_work'] === '+90 212 5550001', 'the number keeps digits, + and spaces only');
$assert($row['external_id'] === '0102fffe', 'a binary objectGUID is kept as hex');

$row = LdapSync::rowFromEntry($entry(['givenname' => 'Kemal', 'sn' => 'Kaya', 'mobile' => '0532 111 22 33', 'mail' => 'not-a-mail']), $map, 'objectguid');
$assert($row['display_name'] === 'Kemal Kaya', 'no display name: first and last name');
$assert($row['email'] === '', 'a broken e-mail address is dropped, not the contact');
$assert($row['external_id'] === 'cn=ayse yilmaz,ou=staff,dc=example,dc=local', 'no id attribute: the DN');

$assert(LdapSync::rowFromEntry($entry(['displayname' => 'No Number']), $map) === null, 'nobody without a number');
$assert(LdapSync::rowFromEntry($entry(['telephonenumber' => '1234']), $map) === null, 'nobody without a name');

$a = LdapSync::rowFromEntry($entry(['displayname' => 'A', 'telephonenumber' => '1'], 'cn=a'), $map);
$b = LdapSync::rowFromEntry($entry(['displayname' => 'B', 'telephonenumber' => '2'], 'cn=b'), $map);
$c = LdapSync::rowFromEntry($entry(['displayname' => 'C', 'telephonenumber' => '3'], 'cn=c'), $map);
$existing = ['cn=a' => $a + ['id' => 1], 'cn=b' => $b + ['id' => 2], 'cn=gone' => $a + ['id' => 3, 'external_id' => 'cn=gone']];
$b2 = ['phone_work' => '22'] + $b;
$plan = LdapSync::plan($existing, ['cn=a' => $a, 'cn=b' => $b2, 'cn=c' => $c]);
$assert(count($plan['insert']) === 1 && $plan['insert'][0]['display_name'] === 'C', 'a new person is added');
$assert(count($plan['update']) === 1 && $plan['update'][0]['id'] === 2 && $plan['update'][0]['phone_work'] === '22', 'a changed number is updated');
$assert($plan['delete'] === [3], 'a person who left the directory is removed');
$assert(LdapSync::plan($existing, ['cn=a' => $a]) === ['insert' => [], 'update' => [], 'delete' => [2, 3]], 'unchanged people are left alone');

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE phonebook_contacts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source TEXT NOT NULL,
        external_id TEXT COLLATE NOCASE,
        display_name TEXT NOT NULL DEFAULT "",
        first_name TEXT NOT NULL DEFAULT "",
        last_name TEXT NOT NULL DEFAULT "",
        company TEXT NOT NULL DEFAULT "",
        phone_work TEXT NOT NULL DEFAULT "",
        phone_mobile TEXT NOT NULL DEFAULT "",
        phone_other TEXT NOT NULL DEFAULT "",
        email TEXT NOT NULL DEFAULT "",
        UNIQUE(source, external_id)
    )');
    $pdo->exec("INSERT INTO phonebook_contacts (source, external_id, display_name, phone_work) VALUES ('ldap-sync', 'UserA', 'User A', '100')");
    $existingDb = LdapSync::existing($pdo);
    $renamed = LdapSync::rowFromEntry($entry(['displayname' => 'User A', 'telephonenumber' => '100', 'samaccountname' => 'usera']), $map, 'samaccountname');
    $counts = LdapSync::apply($pdo, LdapSync::plan($existingDb, ['usera' => $renamed]));
    $assert($counts === ['insert' => 1, 'update' => 0, 'delete' => 1], 'apply deletes before inserting on case-only external_id change');
    $after = LdapSync::existing($pdo);
    $assert(isset($after['usera']) && count($after) === 1, 'case-only external_id replacement leaves one row');
}

echo "\n{$asserts} assertions passed\n";
