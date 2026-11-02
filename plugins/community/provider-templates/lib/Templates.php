<?php

declare(strict_types=1);

/**
 * Provider templates are data, not code, and anyone can propose one. So only the trunk fields listed
 * here can be set, each with its own check, and nothing can carry free text into Asterisk's
 * configuration. In particular custom_pjsip_params cannot be set.
 */
final class ProviderTemplates
{
    public const SCHEMA = 1;

    private const YES_NO = '/^(yes|no)$/D';
    private const FLAG = '/^[01]$/D';

    /** Trunk field => what its value must look like. */
    public const TRUNK_FIELDS = [
        'transport' => '/^(udp|tcp|tls)$/D',
        'port' => '/^[1-9][0-9]{0,4}$/D',
        'codecs' => '/^[a-z0-9_]+(,[a-z0-9_]+)*$/D',
        'dtmf_mode' => '/^(rfc4733|inband|info|auto)$/D',
        'outbound_proxy' => '/^sip:[A-Za-z0-9.-]{1,253}(:[0-9]{1,5})?(\\\\;lr)?$/D',
        'registration_enabled' => self::FLAG,
        'registration_expiration' => '/^[0-9]{2,5}$/D',
        'qualify_frequency' => '/^[0-9]{1,4}$/D',
        'direct_media' => self::YES_NO,
        'rtp_symmetric' => self::YES_NO,
        'rewrite_contact' => self::YES_NO,
        'force_rport' => self::YES_NO,
        'timers' => '/^(yes|no|required|always)$/D',
        'prack_100rel' => '/^(yes|no|required)$/D',
        'send_pai' => self::FLAG,
        'send_rpid' => self::FLAG,
        't38_support' => self::FLAG,
        'carrier_strip_prefix' => '/^[0-9+*#]{1,30}$/D',
        'carrier_add_prefix' => '/^[0-9+*#]{1,30}$/D',
    ];

    /**
     * What is wrong with a template; empty when it can be used.
     *
     * @param mixed $t decoded JSON
     * @return list<string>
     */
    public static function problems($t): array
    {
        if (!is_array($t)) {
            return ['not a JSON object'];
        }
        $errors = [];
        $allowed = ['schema', 'name', 'country', 'docs_url', 'source', 'host', 'host_hint', 'needs_login', 'send_e164', 'notes', 'tested', 'trunk'];
        foreach (array_diff(array_keys($t), $allowed) as $unknown) {
            $errors[] = "unknown key: {$unknown}";
        }
        if (($t['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = 'schema must be ' . self::SCHEMA;
        }
        if (!is_string($t['name'] ?? null) || !preg_match('/^[\p{L}\p{N} .&+\/-]{2,60}$/uD', $t['name'])) {
            $errors[] = 'name is missing or has characters that are not allowed';
        }
        if (!is_string($t['country'] ?? null) || !preg_match('/^([A-Z]{2}|global)$/D', $t['country'])) {
            $errors[] = 'country must be a two-letter code or "global"';
        }
        if (!is_string($t['docs_url'] ?? null) || !preg_match('#^https://[^\s<>"\']+$#D', $t['docs_url'])) {
            $errors[] = 'docs_url must be an https address';
        }
        if (!is_string($t['source'] ?? null) || !in_array($t['source'], ['provider documentation', 'pbx vendor guide', 'working installation'], true)) {
            $errors[] = 'source must be "provider documentation", "pbx vendor guide" or "working installation"';
        }
        $host = $t['host'] ?? '';
        if (!is_string($host) || ($host !== '' && !self::isHost($host))) {
            $errors[] = 'host must be a host name or IP address';
        }
        foreach (['host_hint', 'notes'] as $text) {
            if (isset($t[$text]) && (!is_string($t[$text]) || mb_strlen($t[$text]) > 500)) {
                $errors[] = "{$text} must be text of at most 500 characters";
            }
        }
        foreach (['needs_login', 'send_e164'] as $flag) {
            if (!is_bool($t[$flag] ?? null)) {
                $errors[] = "{$flag} must be true or false";
            }
        }
        if (array_key_exists('tested', $t) && $t['tested'] !== null) {
            $tested = $t['tested'];
            if (!is_array($tested)
                || !is_string($tested['by'] ?? null) || !preg_match('/^[A-Za-z0-9-]{1,39}$/D', $tested['by'])
                || !is_string($tested['date'] ?? null) || !preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D', $tested['date'])
                || !is_string($tested['aipbx'] ?? null) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $tested['aipbx'])) {
                $errors[] = 'tested must be null or have by (GitHub name), date (YYYY-MM) and aipbx (version)';
            }
        }
        if (!is_array($t['trunk'] ?? null)) {
            $errors[] = 'trunk must be an object';
            return $errors;
        }
        foreach ($t['trunk'] as $field => $value) {
            $rule = self::TRUNK_FIELDS[$field] ?? null;
            if ($rule === null) {
                $errors[] = "trunk field is not allowed: {$field}";
            } elseif (!is_string($value) || !preg_match($rule, $value)) {
                $errors[] = "trunk field has a bad value: {$field}";
            }
        }
        return $errors;
    }

    public static function isHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/D', $host) === 1;
    }

    /**
     * Usable templates of a folder, by file name without .json. Files starting with _ are examples.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function load(string $dir): array
    {
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $id = basename($path, '.json');
            if ($id[0] === '_' || !preg_match('/^[a-z0-9-]{1,60}$/D', $id)) {
                continue;
            }
            $t = json_decode((string) file_get_contents($path), true);
            if (self::problems($t) === []) {
                $out[$id] = $t;
            }
        }
        uasort($out, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * What TrunkService::saveTrunk() receives. What the administrator enters always wins over the
     * template, and only the template's checked fields are copied.
     *
     * @param array<string, mixed> $t
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function trunkData(array $t, array $input): array
    {
        $data = [];
        foreach ($t['trunk'] as $field => $value) {
            if (isset(self::TRUNK_FIELDS[$field])) {
                $data[$field] = $value;
            }
        }
        $data['trunk_id'] = 0;
        $data['trunk_name'] = trim((string) ($input['trunk_name'] ?? ''));
        $data['title'] = $t['name'];
        $data['ip_address'] = trim((string) ($input['host'] ?? ''));
        $data['auth_username'] = trim((string) ($input['auth_username'] ?? ''));
        $data['auth_password'] = (string) ($input['auth_password'] ?? '');
        $data['is_active'] = 1;
        $domain = trim((string) ($input['from_domain'] ?? ''));
        if ($domain !== '' && self::isHost($domain)) {
            $data['from_domain'] = $domain;
        }
        if (!empty($t['send_e164'])) {
            $data['carrier_e164_country'] = preg_replace('/[^0-9]/', '', (string) ($input['country_code'] ?? ''));
        }
        $keep = trim((string) ($input['cid_prepend'] ?? ''));
        if ($keep !== '') {
            $data['cid_prepend'] = $keep;
            $data['cid_keep_last'] = (int) ($input['cid_keep_last'] ?? 0);
        }
        return $data;
    }
}
