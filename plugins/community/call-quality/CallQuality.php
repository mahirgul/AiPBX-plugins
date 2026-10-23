<?php
/**
 * Call quality from RTCP (see README.md).
 *
 * Every minute the worker reads the RTCP reports that arrived since the last
 * pass (pbx_signaling_events, written by the core's aipbx-signaling daemon),
 * adds them to one row per call leg, names the leg from its SIP INVITE (the
 * trunk it came over, or the extension), and raises or resolves one alert per
 * trunk/extension in the portal's alert bell. Nothing is ever written to the
 * PBX configuration.
 */
final class CallQuality
{
    public const TABLE = 'plg_call_quality_legs';

    /** Minutes a leg counts for an alert. */
    public const ALERT_MINUTES = 15;

    /** A leg is "fair" from this loss on. */
    public const FAIR_PCT = 1.0;

    private const PASS_SECONDS = 60;
    private const BATCH = 20000;
    private const NOTICE_PREFIX = 'call-quality:';

    // ------------------------------------------------------------ settings

    public static function poorPct(array $s): float
    {
        return max(1.0, min(50.0, (float) ($s['poor_loss_pct'] ?? 5)));
    }

    public static function alertLegs(array $s): int
    {
        return max(1, min(50, (int) ($s['alert_poor_legs'] ?? 3)));
    }

    public static function keepDays(array $s): int
    {
        return max(7, min(3650, (int) ($s['keep_days'] ?? 90)));
    }

    // ------------------------------------------------------------ the pass

    /** Worker hook: at most one pass a minute. */
    public static function tick(string $slug, array $settings): void
    {
        $last = PluginManager::kvGet($slug, 'state', 'pass');
        if (time() - (int) ($last['value']['at'] ?? 0) < self::PASS_SECONDS) {
            return;
        }
        $result = self::pass($slug, $settings);
        PluginManager::kvSet($slug, 'state', 'pass', ['at' => time()] + $result);
    }

    /** @return array{reports: int, legs: int, named: int, alerts: int} */
    public static function pass(string $slug, array $settings): array
    {
        $db = getDB();
        if (!self::signalingTable($db)) {
            return ['reports' => 0, 'legs' => 0, 'named' => 0, 'alerts' => 0];
        }
        $poor = self::poorPct($settings);

        // Where the last pass stopped. The first pass starts an hour back
        // instead of reading the whole history.
        $mark = PluginManager::kvGet($slug, 'state', 'watermark');
        $from = (int) ($mark['value']['id'] ?? 0);
        if ($from === 0) {
            $from = (int) $db->query("SELECT COALESCE(MIN(id), 1) - 1 FROM pbx_signaling_events WHERE ts >= NOW() - INTERVAL 1 HOUR")->fetchColumn();
        }
        $st = $db->prepare("SELECT id, ts, call_id, src, rtcp_fraction_lost, rtcp_jitter FROM pbx_signaling_events
            WHERE id > ? AND proto = 'rtcp' AND call_id <> '' ORDER BY id LIMIT " . self::BATCH);
        $st->execute([$from]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $from = (int) end($rows)['id'];
        } else {
            // Nothing new: move past the SIP rows too, so they are not read again.
            $from = max($from, (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM pbx_signaling_events')->fetchColumn());
        }

        $legs = self::aggregate($rows, self::ownHosts(), $poor);
        self::store($db, $legs, $poor);
        PluginManager::kvSet($slug, 'state', 'watermark', ['id' => $from]);

        $named = self::nameLegs($db);
        $alerts = self::alerts($db, $slug, self::alertLegs($settings));
        self::prune($db, $slug, self::keepDays($settings));
        return ['reports' => count($rows), 'legs' => count($legs), 'named' => $named, 'alerts' => $alerts];
    }

    /**
     * RTCP rows → per leg counters. A report sent BY this server describes
     * what it received ("in"); one sent to it, what the other side received.
     *
     * @param list<array> $rows
     * @param list<string> $own this server's addresses
     * @return array<string, array>
     */
    public static function aggregate(array $rows, array $own, float $poorPct): array
    {
        $legs = [];
        foreach ($rows as $r) {
            $id = (string) $r['call_id'];
            $legs[$id] ??= ['first_ts' => $r['ts'], 'last_ts' => $r['ts'], 'reports_in' => 0, 'reports_out' => 0,
                'bad_in' => 0, 'bad_out' => 0, 'loss_in_max' => 0.0, 'loss_out_max' => 0.0,
                'loss_in_sum' => 0.0, 'loss_out_sum' => 0.0, 'jitter_in_max' => 0, 'jitter_out_max' => 0, 'peer' => ''];
            $l = &$legs[$id];
            $l['first_ts'] = min($l['first_ts'], $r['ts']);
            $l['last_ts'] = max($l['last_ts'], $r['ts']);
            $srcHost = self::hostOf((string) $r['src']);
            $dir = in_array($srcHost, $own, true) ? 'in' : 'out';
            if ($dir === 'out' && $l['peer'] === '') {
                $l['peer'] = $srcHost;
            }
            // A report without the counter says nothing about loss; it is not a zero.
            if ($r['rtcp_fraction_lost'] === null) {
                unset($l);
                continue;
            }
            $loss = round((int) $r['rtcp_fraction_lost'] * 100 / 256, 2);
            $l['reports_' . $dir]++;
            $l['loss_' . $dir . '_sum'] += $loss;
            $l['loss_' . $dir . '_max'] = max($l['loss_' . $dir . '_max'], $loss);
            $l['bad_' . $dir] += $loss >= $poorPct ? 1 : 0;
            $l['jitter_' . $dir . '_max'] = max($l['jitter_' . $dir . '_max'], (int) $r['rtcp_jitter']);
            unset($l);
        }
        return $legs;
    }

    /** "poor": two bad reports in one direction; "fair": 1 % loss somewhere. */
    public static function level(array $l): string
    {
        if ($l['bad_in'] >= 2 || $l['bad_out'] >= 2) {
            return 'poor';
        }
        return max((float) $l['loss_in_max'], (float) $l['loss_out_max']) >= self::FAIR_PCT ? 'fair' : 'good';
    }

    private static function store(PDO $db, array $legs, float $poor): void
    {
        if (!$legs) {
            return;
        }
        $up = $db->prepare('INSERT INTO ' . self::TABLE . ' (call_id, first_ts, last_ts, peer, reports_in, reports_out, bad_in, bad_out,
                loss_in_max, loss_out_max, loss_in_sum, loss_out_sum, jitter_in_max, jitter_out_max)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE first_ts = LEAST(first_ts, VALUES(first_ts)), last_ts = GREATEST(last_ts, VALUES(last_ts)),
                peer = IF(peer = \'\', VALUES(peer), peer),
                reports_in = reports_in + VALUES(reports_in), reports_out = reports_out + VALUES(reports_out),
                bad_in = bad_in + VALUES(bad_in), bad_out = bad_out + VALUES(bad_out),
                loss_in_max = GREATEST(loss_in_max, VALUES(loss_in_max)), loss_out_max = GREATEST(loss_out_max, VALUES(loss_out_max)),
                loss_in_sum = loss_in_sum + VALUES(loss_in_sum), loss_out_sum = loss_out_sum + VALUES(loss_out_sum),
                jitter_in_max = GREATEST(jitter_in_max, VALUES(jitter_in_max)), jitter_out_max = GREATEST(jitter_out_max, VALUES(jitter_out_max))');
        $lvl = $db->prepare('UPDATE ' . self::TABLE . ' SET level = ? WHERE call_id = ?');
        $get = $db->prepare('SELECT bad_in, bad_out, loss_in_max, loss_out_max FROM ' . self::TABLE . ' WHERE call_id = ?');
        foreach ($legs as $id => $l) {
            $up->execute([mb_substr($id, 0, 128), $l['first_ts'], $l['last_ts'], mb_substr($l['peer'], 0, 64),
                $l['reports_in'], $l['reports_out'], $l['bad_in'], $l['bad_out'], $l['loss_in_max'], $l['loss_out_max'],
                $l['loss_in_sum'], $l['loss_out_sum'], $l['jitter_in_max'], $l['jitter_out_max']]);
            $get->execute([mb_substr($id, 0, 128)]);
            $lvl->execute([self::level($get->fetch(PDO::FETCH_ASSOC)), mb_substr($id, 0, 128)]);
        }
    }

    // ------------------------------------------------------------ naming

    /**
     * Names new legs from their SIP INVITE: the trunk whose address (or
     * match list) the other side of the INVITE is, else the extension in the
     * From or To user part. The media may come from other addresses than the
     * signalling, so the INVITE is the reliable witness.
     */
    private static function nameLegs(PDO $db): int
    {
        $ids = $db->query('SELECT call_id FROM ' . self::TABLE . " WHERE leg_type = '' AND first_ts >= NOW() - INTERVAL 1 DAY LIMIT 300")
            ->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            return 0;
        }
        $own = self::ownHosts();
        $trunks = self::trunkMatchers($db);
        $exts = array_flip($db->query("SELECT extension FROM sys_users WHERE extension IS NOT NULL AND extension <> ''")->fetchAll(PDO::FETCH_COLUMN));
        $inv = $db->prepare("SELECT src, dst, from_uri, to_uri FROM pbx_signaling_events WHERE call_id = ? AND proto = 'sip' AND method = 'INVITE' ORDER BY id LIMIT 1");
        $set = $db->prepare('UPDATE ' . self::TABLE . ' SET leg_type = ?, leg_name = ? WHERE call_id = ?');
        $named = 0;
        foreach ($ids as $id) {
            $inv->execute([$id]);
            $sip = $inv->fetch(PDO::FETCH_ASSOC);
            if (!$sip) {
                // Reports without signalling (capture started mid-call): give up after an hour.
                $old = $db->prepare('SELECT first_ts < NOW() - INTERVAL 1 HOUR FROM ' . self::TABLE . ' WHERE call_id = ?');
                $old->execute([$id]);
                if ((int) $old->fetchColumn() === 1) {
                    $set->execute(['unknown', '', $id]);
                }
                continue;
            }
            [$type, $name] = self::classify($sip, $own, $trunks, $exts);
            $set->execute([$type, mb_substr($name, 0, 64), $id]);
            $named++;
        }
        return $named;
    }

    /**
     * @param array{src: string, dst: string, from_uri: string, to_uri: string} $sip
     * @param list<string> $own
     * @param array<string, list<string>> $trunks trunk name => hosts/CIDRs
     * @param array<string, int> $exts extension => any
     * @return array{0: string, 1: string}
     */
    public static function classify(array $sip, array $own, array $trunks, array $exts): array
    {
        $src = self::hostOf($sip['src']);
        $remote = in_array($src, $own, true) ? self::hostOf($sip['dst']) : $src;
        foreach ($trunks as $name => $hosts) {
            foreach ($hosts as $h) {
                if ($h === $remote || self::inCidr($remote, $h)) {
                    return ['trunk', (string) $name];
                }
            }
        }
        foreach ([$sip['from_uri'], $sip['to_uri']] as $uri) {
            if (preg_match('/^(?:"[^"]*"\s*)?<?sips?:([^@;>]+)@/i', trim((string) $uri), $m) && isset($exts[$m[1]])) {
                return ['extension', $m[1]];
            }
        }
        return ['other', $remote];
    }

    /** @return array<string, list<string>> */
    private static function trunkMatchers(PDO $db): array
    {
        $out = [];
        foreach ($db->query('SELECT trunk_name, ip_address, match_hosts FROM pbx_trunks WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $hosts = [];
            foreach (preg_split('/[\s,]+/', trim($t['ip_address'] . ',' . $t['match_hosts'])) ?: [] as $h) {
                $h = preg_replace('/:\d+$/', '', trim($h));
                if ($h === '') {
                    continue;
                }
                // A host name: compare its addresses (looked up once per pass).
                $ips = filter_var(explode('/', $h)[0], FILTER_VALIDATE_IP) ? [$h] : (@gethostbynamel($h) ?: []);
                array_push($hosts, ...$ips);
            }
            $out[$t['trunk_name']] = array_values(array_unique($hosts));
        }
        return $out;
    }

    // ------------------------------------------------------------ alerts

    /**
     * One alert per trunk/extension with enough poor legs in the last
     * ALERT_MINUTES (and at least a fifth of its measured legs). Alerts of
     * the previous pass that no longer hold are resolved.
     */
    private static function alerts(PDO $db, string $slug, int $minPoor): int
    {
        if (!class_exists('NoticeService')) {
            return 0;   // AiPBX before the alert bell: the page still shows everything
        }
        $st = $db->query('SELECT leg_type, leg_name, COUNT(*) AS legs, SUM(level = \'poor\') AS poor,
                SUM(bad_in) AS bad_in, SUM(bad_out) AS bad_out, MAX(GREATEST(loss_in_max, loss_out_max)) AS worst
            FROM ' . self::TABLE . ' WHERE last_ts >= NOW() - INTERVAL ' . self::ALERT_MINUTES . " MINUTE AND leg_type IN ('trunk', 'extension')
            GROUP BY leg_type, leg_name");
        $open = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            if ((int) $g['poor'] < $minPoor || (int) $g['poor'] * 5 < (int) $g['legs']) {
                continue;
            }
            $key = self::NOTICE_PREFIX . $g['leg_type'] . ':' . $g['leg_name'];
            $open[$key] = true;
            NoticeService::raise($key, 'warn', self::alertTitle($g), self::alertMessage($g), '/plugins/' . $slug, $slug);
        }
        $before = (array) (PluginManager::kvGet($slug, 'state', 'alerts')['value'] ?? []);
        foreach (array_keys($before) as $key) {
            if (!isset($open[$key])) {
                NoticeService::resolve($key);
            }
        }
        PluginManager::kvSet($slug, 'state', 'alerts', $open);
        return count($open);
    }

    public static function alertTitle(array $g): string
    {
        return $g['leg_type'] === 'trunk'
            ? sprintf('Trunk %s is losing audio', $g['leg_name'])
            : sprintf('Extension %s is losing audio', $g['leg_name']);
    }

    /** Which way the audio is lost tells where to look. */
    public static function alertMessage(array $g): string
    {
        $in = (int) $g['bad_in'];
        $out = (int) $g['bad_out'];
        $where = $in > 2 * $out ? ($g['leg_type'] === 'trunk' ? 'mostly on the way from the carrier' : 'mostly on the way from the phone')
            : ($out > 2 * $in ? ($g['leg_type'] === 'trunk' ? 'mostly on the way to the carrier' : 'mostly on the way to the phone (Wi-Fi?)') : 'in both directions');
        return sprintf('%d of %d calls in %d minutes had poor audio, up to %s %% packet loss, %s.',
            (int) $g['poor'], (int) $g['legs'], self::ALERT_MINUTES, rtrim(rtrim(number_format((float) $g['worst'], 1, '.', ''), '0'), '.'), $where);
    }

    // ------------------------------------------------------------ helpers

    private static function prune(PDO $db, string $slug, int $days): void
    {
        $last = PluginManager::kvGet($slug, 'state', 'pruned');
        if (time() - (int) ($last['value']['at'] ?? 0) < 3600) {
            return;
        }
        $db->exec('DELETE FROM ' . self::TABLE . ' WHERE last_ts < NOW() - INTERVAL ' . $days . ' DAY');
        PluginManager::kvSet($slug, 'state', 'pruned', ['at' => time()]);
    }

    public static function signalingTable(PDO $db): bool
    {
        try {
            $db->query('SELECT rtcp_fraction_lost FROM pbx_signaling_events LIMIT 0');
            return true;
        } catch (\Throwable $e) {
            return false;   // AiPBX older than 1.50.2: no RTCP counters
        }
    }

    /** "10.0.0.5:5060", "[2001:db8::1]:5060" or a bare address → the address. */
    public static function hostOf(string $addr): string
    {
        $addr = trim($addr);
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $addr, $m)) {
            return $m[1];
        }
        return substr_count($addr, ':') === 1 ? explode(':', $addr)[0] : $addr;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/') || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $bits < 0 || $bits > 32) {
            return false;
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
    }

    /** @return list<string> this server's addresses */
    public static function ownHosts(): array
    {
        static $own = null;
        if ($own !== null) {
            return $own;
        }
        $own = ['127.0.0.1', '::1'];
        foreach (['server_ip', 'pjsip_external_ip'] as $k) {
            $v = trim((string) getSystemSetting($k, ''));
            if ($v !== '') {
                $own[] = $v;
            }
        }
        foreach (preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null'))) ?: [] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $own[] = $ip;
            }
        }
        return $own = array_values(array_unique($own));
    }

    // ------------------------------------------------------------ the page

    /** Capture state for the page: is anything arriving at all? */
    public static function captureState(PDO $db): array
    {
        $plugin = class_exists('PluginManager') && PluginManager::isEnabled('signaling-capture');
        $last = self::signalingTable($db)
            ? $db->query("SELECT MAX(ts) FROM pbx_signaling_events WHERE proto = 'rtcp' AND ts >= NOW() - INTERVAL 1 DAY")->fetchColumn()
            : null;
        return ['plugin' => $plugin, 'counters' => self::signalingTable($db), 'last_rtcp' => $last ?: null];
    }

    /** Per trunk/extension over the last $hours. */
    public static function summary(PDO $db, int $hours = 24): array
    {
        return $db->query('SELECT leg_type, leg_name, COUNT(*) AS legs, SUM(level = \'poor\') AS poor, SUM(level = \'fair\') AS fair,
                ROUND(SUM(loss_in_sum) / NULLIF(SUM(reports_in), 0), 2) AS avg_in, ROUND(SUM(loss_out_sum) / NULLIF(SUM(reports_out), 0), 2) AS avg_out,
                MAX(loss_in_max) AS max_in, MAX(loss_out_max) AS max_out, MAX(jitter_in_max) AS jit_in, MAX(jitter_out_max) AS jit_out
            FROM ' . self::TABLE . ' WHERE last_ts >= NOW() - INTERVAL ' . max(1, $hours) . " HOUR
            GROUP BY leg_type, leg_name ORDER BY SUM(level = 'poor') DESC, COUNT(*) DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Poor and fair legs, newest first. */
    public static function badLegs(PDO $db, int $hours = 24, int $limit = 50): array
    {
        return $db->query('SELECT * FROM ' . self::TABLE . ' WHERE last_ts >= NOW() - INTERVAL ' . max(1, $hours) . " HOUR
            AND level IN ('poor', 'fair') ORDER BY level = 'poor' DESC, last_ts DESC LIMIT " . max(1, min(500, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    }
}
