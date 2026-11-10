<?php

declare(strict_types=1);

require_once __DIR__ . '/Provider.php';
require_once __DIR__ . '/providers/Bulkvs.php';
require_once __DIR__ . '/providers/Opencnam.php';
require_once __DIR__ . '/providers/Telnyx.php';
require_once __DIR__ . '/providers/Twilio.php';
require_once __DIR__ . '/providers/Vonage.php';
require_once __DIR__ . '/providers/Signalwire.php';
require_once __DIR__ . '/providers/Numverify.php';
require_once __DIR__ . '/providers/Abstractapi.php';
require_once __DIR__ . '/providers/Custom.php';

final class Cnam
{
    /**
     * Instantiates provider by id.
     *
     * @param array<string, mixed> $settings
     */
    public static function provider(string $id, array $settings): ?CnamProvider
    {
        return match ($id) {
            'bulkvs'      => new CnamBulkvs($settings),
            'opencnam'    => new CnamOpencnam($settings),
            'telnyx'      => new CnamTelnyx($settings),
            'twilio'      => new CnamTwilio($settings),
            'vonage'      => new CnamVonage($settings),
            'signalwire'  => new CnamSignalwire($settings),
            'numverify'   => new CnamNumverify($settings),
            'abstractapi' => new CnamAbstractapi($settings),
            'custom'      => new CnamCustom($settings),
            default       => null,
        };
    }

    /**
     * Checks local PBX extensions, phonebook, or SMS contacts for this number.
     */
    public static function lookupLocal(PDO $db, string $number): ?string
    {
        $digits = CnamProvider::digits($number);
        if ($digits === '') {
            return null;
        }

        // 1. Extensions table
        try {
            $stmt = $db->prepare(
                'SELECT full_name FROM sys_users
                 WHERE is_active = 1 AND (extension = ? OR cid_external = ? OR cid_external = ?)
                 LIMIT 1'
            );
            $stmt->execute([$digits, $digits, CnamProvider::e164($number)]);
            $name = $stmt->fetchColumn();
            if (is_string($name) && trim($name) !== '') {
                return CnamProvider::sanitizeName($name);
            }
        } catch (\Throwable $ignore) {
        }

        // 3. SMS contacts table (if SMS plugin is installed)
        try {
            $stmt = $db->prepare('SELECT name FROM plg_sms_contacts WHERE phone = ? LIMIT 1');
            $stmt->execute([CnamProvider::e164($number)]);
            $name = $stmt->fetchColumn();
            if (is_string($name) && trim($name) !== '') {
                return CnamProvider::sanitizeName($name);
            }
        } catch (\Throwable $ignore) {
        }

        return null;
    }

    /**
     * Retrieves valid cached name from `plg_cnam_cache`.
     */
    public static function getCache(PDO $db, string $number): ?string
    {
        try {
            $stmt = $db->prepare(
                'SELECT caller_name FROM plg_cnam_cache
                 WHERE caller_number = ? AND expires_at > NOW()
                 LIMIT 1'
            );
            $stmt->execute([$number]);
            $name = $stmt->fetchColumn();
            if (is_string($name) && $name !== '') {
                // Increment lookups count for cache stats
                $db->prepare('UPDATE plg_cnam_cache SET lookups_count = lookups_count + 1 WHERE caller_number = ?')
                   ->execute([$number]);
                return $name;
            }
        } catch (\Throwable $ignore) {
        }
        return null;
    }

    /**
     * Stores or updates cache record in `plg_cnam_cache`.
     */
    public static function setCache(PDO $db, string $number, string $name, string $source, int $cacheDays): void
    {
        if ($cacheDays <= 0 || $name === '') {
            return;
        }
        try {
            $days = max(1, min(365, $cacheDays));
            $stmt = $db->prepare(
                'INSERT INTO plg_cnam_cache (caller_number, caller_name, source, lookups_count, created_at, expires_at)
                 VALUES (?, ?, ?, 1, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))
                 ON DUPLICATE KEY UPDATE
                    caller_name = VALUES(caller_name),
                    source = VALUES(source),
                    lookups_count = lookups_count + 1,
                    expires_at = VALUES(expires_at)'
            );
            $stmt->execute([$number, $name, $source, $days]);
        } catch (\Throwable $ignore) {
        }
    }

    /**
     * Purges expired cache entries.
     */
    public static function purgeExpired(PDO $db): int
    {
        try {
            $stmt = $db->query('DELETE FROM plg_cnam_cache WHERE expires_at <= NOW()');
            return $stmt ? $stmt->rowCount() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Clears all cache entries.
     */
    public static function clearAllCache(PDO $db): int
    {
        try {
            $stmt = $db->query('DELETE FROM plg_cnam_cache');
            return $stmt ? $stmt->rowCount() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Returns cache usage metrics.
     *
     * @return array{total: int, active: int, total_hits: int}
     */
    public static function getStats(PDO $db): array
    {
        $stats = ['total' => 0, 'active' => 0, 'total_hits' => 0];
        try {
            $row = $db->query(
                'SELECT COUNT(*) AS total,
                        SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) AS active,
                        SUM(lookups_count) AS total_hits
                 FROM plg_cnam_cache'
            )->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $stats['total'] = (int) ($row['total'] ?? 0);
                $stats['active'] = (int) ($row['active'] ?? 0);
                $stats['total_hits'] = (int) ($row['total_hits'] ?? 0);
            }
        } catch (\Throwable $ignore) {
        }
        return $stats;
    }

    /**
     * Full CNAM resolution pipeline: Local -> Cache -> Provider.
     *
     * @param array<string, mixed> $settings
     * @return array{name: ?string, source: string, latency_ms: int}
     */
    public static function resolve(PDO $db, array $settings, string $rawNumber): array
    {
        $start = hrtime(true);
        $number = trim($rawNumber);
        if (!preg_match('/^\+?[0-9]{3,15}$/D', $number)) {
            return ['name' => null, 'source' => 'none', 'latency_ms' => 0];
        }
        if ($number === '') {
            return ['name' => null, 'source' => 'none', 'latency_ms' => 0];
        }

        // 1. Local Phonebook / Extension
        if (!empty($settings['check_phonebook'])) {
            $localName = self::lookupLocal($db, $number);
            if ($localName !== null) {
                $ms = (int) ((hrtime(true) - $start) / 1e6);
                return ['name' => $localName, 'source' => 'phonebook', 'latency_ms' => $ms];
            }
        }

        // 2. Cache
        $cacheDays = isset($settings['cache_days']) ? (int) $settings['cache_days'] : 30;
        if ($cacheDays > 0) {
            $cached = self::getCache($db, $number);
            if ($cached !== null) {
                $ms = (int) ((hrtime(true) - $start) / 1e6);
                return ['name' => $cached, 'source' => 'cache', 'latency_ms' => $ms];
            }
        }

        // 3. Provider Lookup
        $providerId = (string) ($settings['provider'] ?? 'bulkvs');
        if ($providerId === 'phonebook_only') {
            $ms = (int) ((hrtime(true) - $start) / 1e6);
            return ['name' => null, 'source' => 'phonebook_only', 'latency_ms' => $ms];
        }

        $p = self::provider($providerId, $settings);
        if ($p !== null && $p->configured()) {
            $name = $p->lookup($number);
            if ($name !== null) {
                if ($cacheDays > 0) {
                    self::setCache($db, $number, $name, $providerId, $cacheDays);
                }
                $ms = (int) ((hrtime(true) - $start) / 1e6);
                return ['name' => $name, 'source' => $providerId, 'latency_ms' => $ms];
            }
        }

        $ms = (int) ((hrtime(true) - $start) / 1e6);
        return ['name' => null, 'source' => $providerId, 'latency_ms' => $ms];
    }
}
