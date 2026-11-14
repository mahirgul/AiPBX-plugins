<?php

declare(strict_types=1);

/**
 * Reads people from a company LDAP / Active Directory and keeps them in the
 * AiPBX shared phonebook (phonebook_contacts, source "ldap-sync"). The desk
 * phones see them through the core LDAP phonebook; rows of other sources are
 * never touched.
 */
final class LdapSync
{
    public const SOURCE = 'ldap-sync';
    public const FIELDS = ['display_name', 'first_name', 'last_name', 'company', 'phone_work', 'phone_mobile', 'phone_other', 'email'];
    private const LIMITS = ['display_name' => 150, 'first_name' => 80, 'last_name' => 80, 'company' => 120,
        'phone_work' => 40, 'phone_mobile' => 40, 'phone_other' => 40, 'email' => 191];

    /** Phonebook field => settings key of the LDAP attribute it is read from. */
    public const MAP = ['display_name' => 'attr_name', 'first_name' => 'attr_first', 'last_name' => 'attr_last',
        'company' => 'attr_company', 'phone_work' => 'attr_work', 'phone_mobile' => 'attr_mobile',
        'phone_other' => 'attr_other', 'email' => 'attr_mail'];

    /** @return array<string, string> phonebook field => lower-case LDAP attribute */
    public static function attributeMap(array $settings): array
    {
        $map = [];
        foreach (self::MAP as $field => $key) {
            $attr = strtolower(trim((string) ($settings[$key] ?? '')));
            if (preg_match('/^[a-z][a-z0-9-]{0,63}$/', $attr)) {
                $map[$field] = $attr;
            }
        }
        return $map;
    }

    /** Validated lower-case unique ID attribute, or '' when falling back to DN. */
    public static function idAttribute(array $settings): string
    {
        $attr = strtolower(trim((string) ($settings['attr_id'] ?? '')));
        if ($attr === '') {
            return '';
        }
        if (!preg_match('/^[a-z][a-z0-9-]{0,63}$/', $attr)) {
            throw new InvalidArgumentException('Invalid unique id attribute name.');
        }
        return $attr;
    }

    /**
     * One directory entry (as ldap_get_entries gives it: lower-case keys,
     * 'count' + values, 'dn') to a phonebook row; null when it has no name or
     * no number.
     *
     * @return array<string, string>|null
     */
    public static function rowFromEntry(array $entry, array $map, string $idAttr = ''): ?array
    {
        $first = static function (string $attr) use ($entry): string {
            $v = $entry[$attr] ?? null;
            return is_array($v) ? (string) ($v[0] ?? '') : (is_string($v) ? $v : '');
        };
        $row = [];
        foreach (self::FIELDS as $f) {
            $v = isset($map[$f]) ? $first($map[$f]) : '';
            $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $v) ?? '');
            if (str_starts_with($f, 'phone_')) {
                $v = trim(preg_replace('/[^0-9+*# ]/', '', $v) ?? '');
            }
            $row[$f] = mb_substr($v, 0, self::LIMITS[$f]);
        }
        if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $row['email'] = '';
        }
        if ($row['display_name'] === '') {
            $row['display_name'] = trim($row['first_name'] . ' ' . $row['last_name']) ?: $row['company'];
        }
        if ($row['display_name'] === '' || ($row['phone_work'] === '' && $row['phone_mobile'] === '' && $row['phone_other'] === '')) {
            return null;
        }
        $id = $idAttr !== '' ? $first($idAttr) : '';
        if ($id !== '' && !mb_check_encoding($id, 'UTF-8')) {
            $id = bin2hex($id); // objectGUID is binary
        }
        $id = $id !== '' ? $id : strtolower((string) ($entry['dn'] ?? ''));
        if ($id === '') {
            return null;
        }
        $row['external_id'] = mb_substr($id, 0, 191);
        return $row;
    }

    /**
     * What to change so the phonebook holds exactly $incoming.
     *
     * @param array<string, array<string, string>> $existing external_id => row (with id)
     * @param array<string, array<string, string>> $incoming external_id => row
     * @return array{insert: list<array>, update: list<array>, delete: list<int>}
     */
    public static function plan(array $existing, array $incoming): array
    {
        $plan = ['insert' => [], 'update' => [], 'delete' => []];
        foreach ($incoming as $ext => $row) {
            $old = $existing[$ext] ?? null;
            if ($old === null) {
                $plan['insert'][] = $row;
                continue;
            }
            foreach (self::FIELDS as $f) {
                if ((string) ($old[$f] ?? '') !== $row[$f]) {
                    $plan['update'][] = $row + ['id' => (int) $old['id']];
                    break;
                }
            }
        }
        foreach ($existing as $ext => $old) {
            if (!isset($incoming[(string) $ext])) {
                $plan['delete'][] = (int) $old['id'];
            }
        }
        return $plan;
    }

    /** @return array{insert: int, update: int, delete: int} */
    public static function apply(PDO $db, array $plan): array
    {
        $cols = self::FIELDS;
        $ins = $db->prepare('INSERT INTO phonebook_contacts (source, external_id, ' . implode(', ', $cols) . ') VALUES (?, ?'
            . str_repeat(', ?', count($cols)) . ')');
        $upd = $db->prepare('UPDATE phonebook_contacts SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols))
            . ' WHERE id = ? AND source = ?');
        $del = $db->prepare('DELETE FROM phonebook_contacts WHERE id = ? AND source = ?');
        $db->beginTransaction();
        try {
            // Delete before insert so case-only external_id changes do not hit
            // MySQL's case-insensitive UNIQUE(source, external_id).
            foreach ($plan['delete'] as $id) {
                $del->execute([$id, self::SOURCE]);
            }
            foreach ($plan['update'] as $r) {
                $upd->execute([...array_map(fn($c) => $r[$c], $cols), $r['id'], self::SOURCE]);
            }
            foreach ($plan['insert'] as $r) {
                $ins->execute([self::SOURCE, $r['external_id'], ...array_map(fn($c) => $r[$c], $cols)]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['insert' => count($plan['insert']), 'update' => count($plan['update']), 'delete' => count($plan['delete'])];
    }

    /** @return array<string, array<string, string>> external_id => row */
    public static function existing(PDO $db): array
    {
        $st = $db->prepare('SELECT id, external_id, ' . implode(', ', self::FIELDS) . ' FROM phonebook_contacts WHERE source = ?');
        $st->execute([self::SOURCE]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['external_id']] = $r;
        }
        return $out;
    }

    // ---- the directory ----

    /** @return \LDAP\Connection */
    public static function connect(array $s)
    {
        if (!function_exists('ldap_connect')) {
            throw new RuntimeException('PHP LDAP support (php-ldap) is not installed on this PBX.');
        }
        $host = trim((string) ($s['host'] ?? ''));
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
            throw new RuntimeException('Enter the directory server address.');
        }
        $security = (string) ($s['security'] ?? 'ldaps');
        $port = (int) ($s['port'] ?? 0) ?: ($security === 'ldaps' ? 636 : 389);
        // Certificate check: on unless turned off for a test directory.
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, empty($s['skip_cert_check']) ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER);
        $conn = ldap_connect(($security === 'ldaps' ? 'ldaps://' : 'ldap://') . $host . ':' . $port);
        if (!$conn) {
            throw new RuntimeException('The directory address is not valid.');
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 10);
        ldap_set_option($conn, LDAP_OPT_TIMELIMIT, 60);
        try {
            if ($security === 'starttls' && !@ldap_start_tls($conn)) {
                throw new RuntimeException('StartTLS failed: ' . ldap_error($conn));
            }
            $dn = (string) ($s['bind_dn'] ?? '');
            if (!@ldap_bind($conn, $dn !== '' ? $dn : null, $dn !== '' ? (string) ($s['bind_password'] ?? '') : null)) {
                throw new RuntimeException('Sign-in to the directory failed: ' . ldap_error($conn));
            }
        } catch (Throwable $e) {
            @ldap_unbind($conn);
            throw $e;
        }
        return $conn;
    }

    /**
     * Every matching entry, read in pages (Active Directory gives 1000 at most per answer).
     * More than \$max is an error unless \$preview (the connection test shows the first ones).
     *
     * @return array<string, array<string, string>> external_id => row
     */
    public static function fetch(array $s, int $max = 20000, bool $preview = false): array
    {
        $map = self::attributeMap($s);
        $idAttr = self::idAttribute($s);
        $conn = self::connect($s);
        $attrs = array_values(array_unique(array_filter([...array_values($map), $idAttr])));
        $base = (string) ($s['base_dn'] ?? '');
        $filter = trim((string) ($s['filter'] ?? '')) ?: '(objectClass=person)';
        $out = [];
        $cookie = '';
        try {
            do {
                $controls = [['oid' => LDAP_CONTROL_PAGEDRESULTS, 'value' => ['size' => 500, 'cookie' => $cookie]]];
                $res = @ldap_search($conn, $base, $filter, $attrs, 0, 0, 0, LDAP_DEREF_NEVER, $controls);
                if ($res === false) {
                    throw new RuntimeException('Search failed: ' . ldap_error($conn));
                }
                $entries = ldap_get_entries($conn, $res);
                for ($i = 0; $i < (int) ($entries['count'] ?? 0); $i++) {
                    $row = self::rowFromEntry($entries[$i], $map, $idAttr);
                    if ($row !== null) {
                        $out[$row['external_id']] = $row;
                    }
                }
                $ctrl = [];
                ldap_parse_result($conn, $res, $code, $mdn, $msg, $refs, $ctrl);
                if ($code !== 0) {
                    // A part of the list (size or time limit): removing the rest would be wrong.
                    throw new RuntimeException('The directory gave only a part of the list (' . ldap_err2str((int) $code)
                        . '). Nothing was changed; raise its limit for this user or narrow the filter.');
                }
                $cookie = (string) ($ctrl[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '');
            } while ($cookie !== '' && count($out) < $max);
            if ($cookie !== '' && !$preview) {
                throw new RuntimeException("The directory has more than {$max} contacts with a number. Nothing was changed; narrow the filter or the search base.");
            }
        } finally {
            @ldap_unbind($conn);
        }
        return $out;
    }

    /**
     * One sync. A directory that answers with nobody leaves the phonebook as it
     * is: a wrong filter must not empty it.
     *
     * @return array{insert: int, update: int, delete: int, found: int}
     */
    public static function run(PDO $db, array $settings): array
    {
        $incoming = self::fetch($settings);
        $existing = self::existing($db);
        if ($incoming === [] && $existing !== []) {
            throw new RuntimeException('The directory returned no contacts; the phonebook was left as it is. Check the base DN and the filter.');
        }
        return self::apply($db, self::plan($existing, $incoming)) + ['found' => count($incoming)];
    }
}
