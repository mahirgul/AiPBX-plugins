<?php
/**
 * Call quality from RTCP — the reports `res_hep_rtcp` already sends.
 *
 * This service never touches audio. It reads the RTCP counters the capture
 * daemon stores (packet loss and jitter), groups them per Call-ID and answers
 * one question: is a call losing media, sustained enough to tell somebody?
 *
 * Thresholds are deliberately simple and stated everywhere they are used:
 *   - worst report at 5 % loss or more, twice in the window -> poor;
 *   - any report above 1 %                                -> fair;
 *   - otherwise                                            -> good.
 * `fraction_lost` is RFC 3550's 8-bit fixed point (255 = 100 %), so 13 is
 * ~5 %. Jitter is `ia_jitter` in RTP timestamp units; the sample rate is not
 * in the report, so it is reported as-is and never converted to milliseconds
 * or a made-up MOS score.
 *
 * What it does NOT do is change anything. Loss seen in RTCP is often on our own
 * uplink, so moving a trunk to the back of a route automatically can blame the
 * wrong carrier on a live system. This package records the evidence, names the
 * route that would change and leaves the change to an administrator on the
 * Outbound routes page (see `routeOpportunities()`).
 *
 * Everything a person sees goes through the portal: the plugin page
 * (`/plugins/call-quality`) with the evidence table and the per-call trail, and
 * — only when an address is configured — one e-mail per problem per cooldown
 * window. It never writes `sys_audit_log`, `pbx_notices`, routes or dialplans.
 *
 * Quality sensing needs the built-in capture: when Asterisk's `hep.conf` sends
 * HEP to a remote collector there are no local RTCP rows to read, and
 * `CaptureState` says so instead of reporting every line healthy.
 */

require_once __DIR__ . '/CaptureState.php';

final class CallQualityService
{
    public const WINDOW_MIN = 15;
    /** Two reports at this loss make a call poor, not one spike. */
    public const POOR_PCT = 5.0;
    public const FAIR_PCT = 1.0;
    /** A pass looks at the last few minutes, not the whole window. */
    public const ALERT_WINDOW_MIN = 10;
    /** `worker/tick.php` is polled every two seconds; a pass runs this often. */
    public const PASS_MINUTES = 5;
    /** Five seconds of tolerance, so a 2 s poll lands on 5 min, not 6. */
    private const DUE_AFTER = 295;
    private const MAX_ALERTS = 5;
    /** Where this package's evidence says it comes from. */
    public const SOURCE = 'call-quality';
    /** The plugin's own table, created from `schema.sql` in the plugin root. */
    private const EVIDENCE_TABLE = 'plg_call_quality_evidence';
    /** Plugin key-value collection holding the pass clock and alert cooldowns. */
    private const STATE_COLLECTION = 'state';

    /* ------------------------------------------------------------ measuring */

    /** @return array<int, array<string, mixed>> raw RTCP rows, oldest first */
    public static function rows(int $minutes = self::WINDOW_MIN): array
    {
        $minutes = max(1, min(1440, $minutes));
        // NOW() is the database's clock, which is how the daemon writes ts.
        // A PHP date would compare two timezones (the portal runs in local
        // time, MariaDB in UTC on many installs) and silently see no rows.
        try {
            $st = getDB()->prepare(
                "SELECT call_id, ts, src, dst, rtcp_ssrc, rtcp_lost,
                        rtcp_fraction_lost, rtcp_jitter
                   FROM pbx_signaling_events
                  WHERE proto = 'rtcp'
                    AND ts >= (NOW() - INTERVAL {$minutes} MINUTE)
                  ORDER BY ts ASC"
            );
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // A missing migration or a database hiccup is "no measurement",
            // never a failed page or a false alarm.
            return [];
        }
    }

    /**
     * Groups rows per Call-ID and keeps the worst report block. Pure, so the
     * arithmetic is tested without a database.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    public static function aggregate(array $rows): array
    {
        $calls = [];
        foreach ($rows as $row) {
            $id = trim((string) ($row['call_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (!isset($calls[$id])) {
                $calls[$id] = [
                    'call_id' => $id,
                    'reports' => 0,
                    'worst_fraction' => 0,
                    'poor_reports' => 0,
                    'jitter' => 0,
                    'lost' => 0,
                    'first_ts' => (string) ($row['ts'] ?? ''),
                    'last_ts' => (string) ($row['ts'] ?? ''),
                    'peers' => [],
                ];
            }
            $c =& $calls[$id];
            $c['reports']++;
            $fraction = max(0, min(255, (int) ($row['rtcp_fraction_lost'] ?? 0)));
            $c['worst_fraction'] = max((int) $c['worst_fraction'], $fraction);
            if ($fraction * 100 / 256 >= self::POOR_PCT) {
                $c['poor_reports']++;
            }
            $c['jitter'] = max((int) $c['jitter'], (int) ($row['rtcp_jitter'] ?? 0));
            $c['lost'] = max((int) $c['lost'], (int) ($row['rtcp_lost'] ?? 0));
            $c['last_ts'] = (string) ($row['ts'] ?? $c['last_ts']);
            foreach (['src', 'dst'] as $side) {
                $host = self::hostOf((string) ($row[$side] ?? ''));
                if ($host !== '') {
                    $c['peers'][$host] = true;
                }
            }
            unset($c);
        }
        foreach ($calls as &$call) {
            $call['peers'] = array_keys($call['peers']);
        }
        unset($call);
        return $calls;
    }

    /**
     * One call's verdict. Pure.
     *
     * @param array<string, mixed> $call
     * @return array{level:string, loss_pct:float, reports:int, poor_reports:int,
     *               jitter:int, lost:int, reasons:array<int,string>}
     */
    public static function verdict(array $call): array
    {
        $fraction = max(0, min(255, (int) ($call['worst_fraction'] ?? 0)));
        $loss = round($fraction * 100 / 256, 2);
        $reports = (int) ($call['reports'] ?? 0);
        $poorReports = (int) ($call['poor_reports'] ?? 0);
        if ($poorReports >= 2 && $reports >= 2) {
            $level = 'poor';
        } elseif ($loss >= self::FAIR_PCT || $poorReports > 0) {
            // A single report at 5 %+ is a spike, not sustained loss, but it
            // still deserves a look; below 1 % is background noise.
            $level = 'fair';
        } else {
            $level = 'good';
        }
        $reasons = [];
        if ($fraction > 0) {
            $reasons[] = sprintf('packet loss %.2f%% (worst report)', $loss);
        }
        if ($poorReports > 0) {
            $reasons[] = sprintf('%d of %d RTCP reports were at or above %.0f%% loss', $poorReports, $reports, self::POOR_PCT);
        }
        if ((int) ($call['jitter'] ?? 0) > 0) {
            $reasons[] = sprintf('jitter %d RTP timestamp units', (int) $call['jitter']);
        }
        return [
            'level' => $level,
            'loss_pct' => $loss,
            'reports' => $reports,
            'poor_reports' => $poorReports,
            'jitter' => (int) ($call['jitter'] ?? 0),
            'lost' => (int) ($call['lost'] ?? 0),
            'reasons' => $reasons,
        ];
    }

    /** @return array<int, array<string, mixed>> calls with a `verdict` key, worst first */
    public static function active(int $minutes = self::WINDOW_MIN): array
    {
        $out = [];
        $map = self::trunkMap();
        foreach (self::aggregate(self::rows($minutes)) as $call) {
            $call['verdict'] = self::verdict($call);
            $call['trunk'] = self::trunkFor($call['peers'], $map);
            $out[] = $call;
        }
        usort($out, fn($a, $b) => [$a['verdict']['level'] === 'poor', $a['verdict']['loss_pct']]
            <=> [$b['verdict']['level'] === 'poor', $b['verdict']['loss_pct']]);
        return $out;
    }

    /** @return array<int, array<string, mixed>> only the poor calls */
    public static function degraded(int $minutes = self::WINDOW_MIN): array
    {
        return array_values(array_filter(
            self::active($minutes),
            fn($c) => $c['verdict']['level'] === 'poor'
        ));
    }

    /** @return array{measured:int, poor:int, fair:int, good:int, worst:?array<string, mixed>} */
    public static function summary(int $minutes = self::WINDOW_MIN): array
    {
        $calls = self::active($minutes);
        $count = ['poor' => 0, 'fair' => 0, 'good' => 0];
        foreach ($calls as $call) {
            $count[$call['verdict']['level']]++;
        }
        return [
            'measured' => count($calls),
            'poor' => $count['poor'],
            'fair' => $count['fair'],
            'good' => $count['good'],
            'worst' => $calls[0] ?? null,
        ];
    }

    /** Quality sensing is only possible where local RTCP rows exist. */
    public static function available(): bool
    {
        return CaptureState::local();
    }

    /**
     * Are the RTCP counters on the signaling stream?
     *
     * The capture daemon started storing `rtcp_fraction_lost`, `rtcp_lost`,
     * `rtcp_jitter` and `rtcp_ssrc` on `pbx_signaling_events` in AiPBX 1.50.2.
     * On an older portal the table exists without them, and a pass that scored
     * the resulting empty set would call every line healthy — the one answer
     * this package must never invent. So the columns are looked for, and the
     * page and the pass both say "this build does not store them yet".
     */
    public static function countersAvailable(): bool
    {
        try {
            $st = getDB()->query("SHOW COLUMNS FROM pbx_signaling_events LIKE 'rtcp_fraction_lost'");
            return (bool) $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The peer host out of "1.2.3.4:5060" or "[2001:db8::1]:5060". */
    public static function hostOf(string $addr): string
    {
        $addr = trim($addr);
        if ($addr === '') {
            return '';
        }
        if ($addr[0] === '[') {
            $end = strpos($addr, ']');
            return $end === false ? $addr : substr($addr, 1, $end - 1);
        }
        $pos = strrpos($addr, ':');
        if ($pos !== false && substr_count($addr, ':') === 1) {
            return substr($addr, 0, $pos);
        }
        return $addr;
    }

    /**
     * The trunk whose configured address is one of these peers, or null.
     *
     * @param array<int, string> $peers
     * @param array<string, array<string, mixed>>|null $map ip address => trunk row
     * @return array<string, mixed>|null
     */
    public static function trunkFor(array $peers, ?array $map = null): ?array
    {
        $map = $map ?? self::trunkMap();
        foreach ($peers as $peer) {
            if (isset($map[$peer])) {
                return $map[$peer];
            }
        }
        return null;
    }

    /** @return array<string, array<string, mixed>> ip address => trunk row */
    public static function trunkMap(): array
    {
        try {
            $rows = getDB()->query(
                'SELECT id, trunk_name, title, ip_address FROM pbx_trunks WHERE is_active = 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $ip = trim((string) ($row['ip_address'] ?? ''));
            if ($ip !== '') {
                $map[$ip] = $row;
            }
        }
        return $map;
    }

    /* ------------------------------------------------------------- evidence */

    /**
     * The raw RTCP rows behind one call, bounded to what a ticket needs.
     * A long call can have hundreds of reports; the first four and the last
     * four tell the story (onset, peak, recovery).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function proofFor(string $callId, int $minutes = self::ALERT_WINDOW_MIN): array
    {
        $minutes = max(1, min(1440, $minutes));
        try {
            $st = getDB()->prepare(
                "SELECT ts, src, dst, rtcp_ssrc, rtcp_lost, rtcp_fraction_lost, rtcp_jitter
                   FROM pbx_signaling_events
                  WHERE proto = 'rtcp' AND call_id = ?
                    AND ts >= (NOW() - INTERVAL {$minutes} MINUTE)
                  ORDER BY ts ASC"
            );
            $st->execute([$callId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        if (count($rows) <= 8) {
            return $rows;
        }
        return array_merge(array_slice($rows, 0, 4), array_slice($rows, -4));
    }

    /**
     * Copies the proof of one incident into durable storage. Returns the
     * evidence id, or null when it could not be stored — which never stops the
     * alert: the live rows are still there until retention prunes them.
     *
     * @param array<string, mixed> $call
     */
    public static function recordEvidence(array $call, string $action = 'none',
                                          string $detail = '', string $source = 'worker'): ?int
    {
        try {
            // One incident per call and first report, whatever a later pass
            // decided. An earlier "no action" row is upgraded in place rather
            // than duplicated.
            $key = substr(hash(
                'sha256',
                (string) ($call['call_id'] ?? '') . '|' . (string) ($call['first_ts'] ?? '')
            ), 0, 64);
            $db = getDB();
            $table = self::EVIDENCE_TABLE;
            $find = $db->prepare("SELECT id, action FROM {$table} WHERE dedupe_key = ? LIMIT 1");
            $find->execute([$key]);
            $existing = $find->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing)) {
                if ((string) ($existing['action'] ?? 'none') === 'none' && $action !== 'none') {
                    $db->prepare("UPDATE {$table} SET action = ?, action_detail = ?, evidence_json = ? WHERE id = ?")
                        ->execute([
                            mb_substr($action, 0, 32),
                            mb_substr($detail, 0, 500),
                            self::encodeProof($call),
                            (int) $existing['id'],
                        ]);
                }
                return (int) $existing['id'];
            }
            $verdict = self::verdict($call);
            $db->prepare(
                "INSERT INTO {$table}
                    (created_at, call_id, trunk_name, peer, level, loss_pct, jitter,
                     reports, first_ts, last_ts, evidence_json, action, action_detail, dedupe_key)
                 VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                mb_substr((string) ($call['call_id'] ?? ''), 0, 128),
                mb_substr((string) ($call['trunk']['trunk_name'] ?? ''), 0, 50),
                mb_substr((string) ($call['peers'][0] ?? ''), 0, 64),
                $verdict['level'],
                $verdict['loss_pct'],
                (int) $call['jitter'],
                (int) $call['reports'],
                (string) ($call['first_ts'] ?? '') !== '' ? (string) $call['first_ts'] : null,
                (string) ($call['last_ts'] ?? '') !== '' ? (string) $call['last_ts'] : null,
                self::encodeProof($call),
                mb_substr($action, 0, 32),
                mb_substr($detail, 0, 500),
                $key,
            ]);
            return (int) $db->lastInsertId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param array<string, mixed> $call */
    private static function encodeProof(array $call): string
    {
        $verdict = self::verdict($call);
        $proof = [
            'source' => "res_hep_rtcp -> aipbx-signaling -> pbx_signaling_events (proto='rtcp')",
            'window_minutes' => self::ALERT_WINDOW_MIN,
            'thresholds' => [
                'poor_pct' => self::POOR_PCT,
                'fair_pct' => self::FAIR_PCT,
                'min_poor_reports' => 2,
            ],
            'call' => [
                'call_id' => (string) ($call['call_id'] ?? ''),
                'reports' => (int) ($call['reports'] ?? 0),
                'worst_fraction' => (int) ($call['worst_fraction'] ?? 0),
                'loss_pct' => $verdict['loss_pct'],
                'jitter' => (int) ($call['jitter'] ?? 0),
                'first_ts' => (string) ($call['first_ts'] ?? ''),
                'last_ts' => (string) ($call['last_ts'] ?? ''),
                'peers' => array_values((array) ($call['peers'] ?? [])),
            ],
            'samples' => self::proofFor((string) ($call['call_id'] ?? '')),
            'trunk' => !empty($call['trunk']) ? [
                'trunk_name' => (string) ($call['trunk']['trunk_name'] ?? ''),
                'ip_address' => (string) ($call['trunk']['ip_address'] ?? ''),
            ] : null,
        ];
        return (string) json_encode($proof, JSON_UNESCAPED_SLASHES);
    }

    /** Records that an alert e-mail for this evidence actually left the server. */
    public static function markNotified(int $evidenceId): void
    {
        try {
            getDB()->prepare('UPDATE ' . self::EVIDENCE_TABLE . ' SET notified_at = NOW() WHERE id = ?')
                ->execute([$evidenceId]);
        } catch (\Throwable $e) {
        }
    }

    /** @return array<int, array<string, mixed>> newest evidence first */
    public static function recentEvidence(int $hours = 24, int $limit = 10): array
    {
        $hours = max(1, min(8760, $hours));
        $limit = max(1, min(100, $limit));
        try {
            $st = getDB()->prepare(
                'SELECT * FROM ' . self::EVIDENCE_TABLE . "
                  WHERE created_at >= (NOW() - INTERVAL {$hours} HOUR)
                  ORDER BY id DESC LIMIT {$limit}"
            );
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<string, mixed>|null */
    public static function evidenceById(int $id): ?array
    {
        try {
            $st = getDB()->prepare('SELECT * FROM ' . self::EVIDENCE_TABLE . ' WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The trail a VoIP engineer pastes on a ticket: what was measured, from
     * which source, over which window, and what was (not) changed.
     */
    public static function evidenceTrail(int $id): string
    {
        $row = self::evidenceById($id);
        if ($row === null) {
            return '';
        }
        $proof = json_decode((string) ($row['evidence_json'] ?? ''), true);
        $proof = is_array($proof) ? $proof : [];
        $call = (array) ($proof['call'] ?? []);
        $th = (array) ($proof['thresholds'] ?? []);
        $trunk = $proof['trunk'] ?? null;
        $lines = [
            sprintf('Evidence #%d — %s quality, recorded %s (server time)', (int) $row['id'],
                (string) $row['level'], (string) $row['created_at']),
            'Call: ' . (string) $row['call_id'],
            sprintf(
                'Measured: %d RTCP report(s), %s to %s; worst loss %.2f%% (fraction %d/256); jitter %d RTP timestamp units',
                (int) $row['reports'],
                (string) ($row['first_ts'] ?? '?'),
                (string) ($row['last_ts'] ?? '?'),
                (float) $row['loss_pct'],
                (int) ($call['worst_fraction'] ?? 0),
                (int) $row['jitter']
            ),
            'Peers: ' . implode(', ', (array) ($call['peers'] ?? [$row['peer']])),
            $trunk
                ? 'Trunk: ' . (string) ($trunk['trunk_name'] ?? '') . ' (pbx_trunks.ip_address = ' . (string) ($trunk['ip_address'] ?? '') . ')'
                : 'Trunk: no configured trunk matched the media peer.',
            'Samples:',
        ];
        foreach ((array) ($proof['samples'] ?? []) as $sample) {
            $lines[] = sprintf(
                '  %s  %s -> %s  SSRC=%s  fraction_lost=%s  lost=%s  jitter=%s',
                (string) ($sample['ts'] ?? ''),
                (string) ($sample['src'] ?? ''),
                (string) ($sample['dst'] ?? ''),
                (string) ($sample['rtcp_ssrc'] ?? ''),
                (string) ($sample['rtcp_fraction_lost'] ?? '?'),
                (string) ($sample['rtcp_lost'] ?? '?'),
                (string) ($sample['rtcp_jitter'] ?? '?')
            );
        }
        $lines[] = sprintf(
            'Decision: two reports at %.0f%% loss or more -> poor (fair from %.0f%%, window %d min).',
            (float) ($th['poor_pct'] ?? self::POOR_PCT),
            (float) ($th['fair_pct'] ?? self::FAIR_PCT),
            (int) ($proof['window_minutes'] ?? self::ALERT_WINDOW_MIN)
        );
        $lines[] = 'Finding: ' . ((string) ($row['action_detail'] ?? '') !== ''
            ? (string) $row['action_detail']
            : 'none — recorded for the trail only.');
        $lines[] = 'Alert e-mail: ' . (!empty($row['notified_at'])
            ? 'sent ' . (string) $row['notified_at']
            : 'not sent (no address set, or inside the repeat window).');
        $lines[] = 'Nothing was changed on this PBX: routes, dialplan and Asterisk configuration are untouched by this plugin.';
        $lines[] = 'Source: ' . (string) ($proof['source'] ?? 'pbx_signaling_events');
        return implode("\n", $lines);
    }

    /**
     * How long per-call evidence is kept, from this plugin's own setting.
     *
     * @param array<string, mixed> $settings the plugin settings the loader passes
     */
    public static function evidenceDays(array $settings = []): int
    {
        return max(7, min(3650, (int) ($settings['evidence_days'] ?? 180)));
    }

    /** @param array<string, mixed> $settings */
    private static function pruneEvidence(array $settings): void
    {
        $days = self::evidenceDays($settings);
        try {
            getDB()->exec('DELETE FROM ' . self::EVIDENCE_TABLE
                . " WHERE created_at < (NOW() - INTERVAL {$days} DAY)");
        } catch (\Throwable $e) {
            // Pruning is housekeeping; never fail a pass for it.
        }
    }

    /* ---------------------------------------------------------- suggestion */

    /**
     * The outbound routes where this trunk is in front of another trunk — the
     * only change that would help new calls, if a person decides it should.
     * Reads `pbx_outbound_routes` and returns the before/after chains; it never
     * writes them.
     *
     * @return array<int, array{route_id:int, route_name:string, before:array<int,string>, after:array<int,string>}>
     */
    public static function routeOpportunities(string $trunkName): array
    {
        $trunkName = trim($trunkName);
        if ($trunkName === '') {
            return [];
        }
        try {
            $routes = getDB()->query(
                'SELECT id, route_name, trunks_json FROM pbx_outbound_routes WHERE is_active = 1'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($routes as $route) {
            $chain = json_decode((string) ($route['trunks_json'] ?? ''), true);
            if (!is_array($chain) || count($chain) < 2) {
                continue;
            }
            $names = array_map(fn($t) => trim((string) ($t['trunk_name'] ?? '')), $chain);
            $pos = array_search($trunkName, $names, true);
            if ($pos === false || $pos === count($names) - 1) {
                continue;
            }
            $after = $names;
            $item = array_splice($after, (int) $pos, 1);
            $after[] = $item[0];
            $out[] = [
                'route_id' => (int) $route['id'],
                'route_name' => (string) (($route['route_name'] ?? '') !== '' ? $route['route_name'] : 'route ' . $route['id']),
                'before' => $names,
                'after' => $after,
            ];
        }
        return $out;
    }

    /**
     * One sentence an administrator can act on, or an honest "there is nothing
     * to reorder". Written for the page, the evidence row and the mail.
     *
     * @param array<int, array{route_id:int, route_name:string, before:array<int,string>, after:array<int,string>}> $routes
     */
    public static function suggestionText(string $trunkName, array $routes): string
    {
        if ($routes === []) {
            return sprintf(
                'No active outbound route has %s in front of another trunk, so there is nothing to reorder. '
                . 'Talk to the carrier, or add a backup trunk to the route if the loss is really theirs.',
                $trunkName
            );
        }
        $parts = [];
        foreach ($routes as $route) {
            $parts[] = sprintf(
                'move %s to the end of "%s" (currently %s)',
                $trunkName,
                $route['route_name'],
                implode(', ', $route['before'])
            );
        }
        return 'If you decide the trunk is at fault: ' . implode('; ', $parts)
            . '. Nothing was changed — apply it on Outbound routes (/outbound-routes) for new calls only.';
    }

    /* --------------------------------------------------------------- alerts */

    /** The configured recipient list, or '' when there is none or it is invalid. */
    public static function notifyAddress(array $settings = []): string
    {
        $raw = trim((string) ($settings['notify_email'] ?? ''));
        if ($raw === '') {
            return '';
        }
        $valid = [];
        foreach (preg_split('/[,;]+/', $raw) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $valid[] = $candidate;
            }
        }
        return implode(', ', array_unique($valid));
    }

    /** Repeat window for one alert key, from the plugin setting. */
    private static function cooldownHours(array $settings): int
    {
        return max(1, min(168, (int) ($settings['alert_cooldown_hours'] ?? 6)));
    }

    /** @param array<string, mixed> $settings */
    private static function mailLocalPeers(array $settings): bool
    {
        $value = strtolower(trim((string) ($settings['mail_local_peers'] ?? '0')));
        return in_array($value, ['1', 'yes', 'true', 'on'], true);
    }

    /**
     * One alert per key per cooldown window. The clock is in the plugin's
     * key-value store, not in the evidence table: a suppressed alert writes no
     * evidence row, so there would be nothing there to measure a window with.
     */
    private static function alertDue(string $slug, string $key, int $hours): bool
    {
        $state = self::state($slug, 'alert:' . $key);
        $last = (int) ($state['ts'] ?? 0);
        return (time() - $last) >= $hours * 3600;
    }

    private static function markAlert(string $slug, string $key): void
    {
        if (!class_exists('PluginManager')) {
            return;
        }
        try {
            PluginManager::kvSet($slug, self::STATE_COLLECTION, 'alert:' . $key, ['ts' => time()]);
        } catch (\Throwable $e) {
        }
    }

    /** Portal URL for a link inside a mail, independent of the request. */
    private static function portalUrl(): string
    {
        try {
            if (class_exists('ForgotPasswordService')) {
                return rtrim(\ForgotPasswordService::portalUrl(), '/');
            }
        } catch (\Throwable $e) {
        }
        return '';
    }

    /**
     * Sends one alert through the portal's own mail path: its From address,
     * its MIME builder and postfix. Deliberately not a core template key —
     * those are core-owned and a plugin cannot add one — so the message is
     * composed here and only the transport and sender are reused.
     *
     * @return array{sent:bool, error:string}
     */
    public static function sendMail(string $to, string $subject, string $text, string $html): array
    {
        if ($to === '' || !filter_var(explode(',', $to)[0], FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'error' => 'No valid recipient address is configured.'];
        }
        try {
            if (class_exists('MailTemplateService') && method_exists('MailTemplateService', 'buildMime')) {
                [$fromAddress, $fromName] = \MailTemplateService::sender('portal');
                [$headers, $body] = \MailTemplateService::buildMime(
                    ['subject' => $subject, 'text' => $text, 'html' => $html],
                    $fromAddress,
                    $fromName
                );
                $ok = @mail($to, self::encodeHeader($subject), $body, $headers, '-f ' . $fromAddress);
            } else {
                // Older portal: no MIME builder, still the configured sender.
                $fromAddress = (string) getSystemSetting('mail_from_address', 'no-reply@localhost');
                $headers = 'From: ' . $fromAddress . "\r\n"
                    . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
                $ok = @mail($to, self::encodeHeader($subject), $text, $headers, '-f ' . $fromAddress);
            }
        } catch (\Throwable $e) {
            return ['sent' => false, 'error' => mb_substr($e->getMessage(), 0, 200)];
        }
        if (!$ok) {
            $err = error_get_last()['message'] ?? 'the mail transport refused the message';
            return ['sent' => false, 'error' => mb_substr((string) $err, 0, 200)];
        }
        return ['sent' => true, 'error' => ''];
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    /** @param array<int, string> $lines */
    private static function mailHtml(string $headline, array $lines, string $link): string
    {
        $e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $body = '<p style="margin:0 0 14px 0;font-weight:600;">' . $e($headline) . '</p><ul style="margin:0 0 14px 18px;padding:0;">';
        foreach ($lines as $line) {
            $body .= '<li style="margin:0 0 6px 0;">' . $e($line) . '</li>';
        }
        $body .= '</ul><p style="margin:0 0 6px 0;font-size:13px;color:#475569;">'
            . 'Nothing was changed on the PBX. The evidence and the full RTCP trail are on the Call Quality page.</p>';
        if ($link !== '') {
            $body .= '<p style="margin:0;"><a href="' . $e($link) . '">' . $e($link) . '</a></p>';
        }
        return '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1e293b;">'
            . $body . '</div>';
    }

    /* ----------------------------------------------------------------- pass */

    /** Has five minutes passed since the last pass? */
    public static function due(string $slug): bool
    {
        $state = self::state($slug, 'last_pass');
        return (time() - (int) ($state['ts'] ?? 0)) >= self::DUE_AFTER;
    }

    /**
     * What the last pass did, for the page. Shape: `at`, `state`, plus the
     * result keys of `tick()`.
     *
     * @return array<string, mixed>
     */
    public static function lastPass(string $slug): array
    {
        return self::state($slug, 'last_pass');
    }

    /** @return array<string, mixed> */
    private static function state(string $slug, string $key): array
    {
        if (!class_exists('PluginManager')) {
            return [];
        }
        try {
            $row = PluginManager::kvGet($slug, self::STATE_COLLECTION, $key);
        } catch (\Throwable $e) {
            return [];
        }
        $value = $row['value'] ?? null;
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $result */
    private static function rememberPass(string $slug, array $result): void
    {
        if (!class_exists('PluginManager')) {
            return;
        }
        try {
            PluginManager::kvSet($slug, self::STATE_COLLECTION, 'last_pass', ['ts' => time()] + $result);
        } catch (\Throwable $e) {
        }
    }

    /**
     * The entry point `worker/tick.php` calls. The worker is polled every two
     * seconds, so this is the five-minute clock: claim the minute, run one pass,
     * remember what it did — and never throw, because a throwing hook puts the
     * whole plugin into Safe Mode after five polls.
     *
     * @param array<string, mixed> $settings
     */
    public static function scheduled(string $slug, array $settings): void
    {
        try {
            if (!self::due($slug)) {
                return;
            }
            // Claim first: a pass that fatals must not restart on the next poll.
            self::rememberPass($slug, ['state' => 'running']);
            $result = self::tick($settings, $slug, 'worker');
            self::rememberPass($slug, ['state' => 'done'] + $result);
        } catch (\Throwable $e) {
            self::rememberPass($slug, [
                'state' => 'error',
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * One pass: record what is degraded, suggest the route change a person
     * could make, and mail the administrator at most once per cooldown window.
     * Silent when capture is not local or nothing is degraded.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function tick(array $settings = [], string $slug = 'call-quality', string $source = 'worker'): array
    {
        $out = [
            'at' => date('Y-m-d H:i:s'),
            'capture' => 'unknown',
            'measured' => 0,
            'poor' => 0,
            'evidence' => 0,
            'mails' => 0,
            'skipped' => [],
            'notes' => [],
        ];
        try {
            $capture = CaptureState::snapshot();
            $out['capture'] = $capture['mode'];
            self::pruneEvidence($settings);

            if (!$capture['local']) {
                // No local rows: saying nothing is right. Scoring an empty set
                // would report every line healthy, which is the one answer this
                // package must never invent.
                $out['skipped'][] = 'capture_not_local';
                return $out;
            }
            if (!self::countersAvailable()) {
                // Same reason: the daemon of this build does not store the
                // counters yet, so there is nothing to score and no verdict to
                // give. The page explains which release adds them.
                $out['skipped'][] = 'counters_missing';
                return $out;
            }

            $active = self::active(self::ALERT_WINDOW_MIN);
            $out['measured'] = count($active);
            $calls = array_values(array_filter($active, fn($c) => $c['verdict']['level'] === 'poor'));
            $out['poor'] = count($calls);
            if ($calls === []) {
                return $out;
            }

            $map = self::trunkMap();
            $byTrunk = [];
            $others = [];
            foreach ($calls as $call) {
                $trunk = self::trunkFor($call['peers'], $map);
                if ($trunk !== null) {
                    $name = (string) $trunk['trunk_name'];
                    $byTrunk[$name]['trunk'] = $trunk;
                    $byTrunk[$name]['calls'][] = $call;
                } else {
                    $others[] = $call;
                }
            }

            $to = self::notifyAddress($settings);
            $cooldown = self::cooldownHours($settings);
            $link = self::portalUrl() . '/plugins/' . $slug;

            // Two trunks losing packets at once is not a trunk problem: moving
            // either one would just swap the order and punish a carrier that is
            // not at fault. That is a human decision (or the LAN).
            if (count($byTrunk) > 1) {
                $names = array_keys($byTrunk);
                $detail = 'Sustained loss on ' . implode(', ', $names)
                    . ' at the same time; no single trunk is implicated. Check the uplink and both carriers.';
                foreach ($byTrunk as $group) {
                    foreach ($group['calls'] as $call) {
                        if (self::recordEvidence($call, 'multiple_trunks', $detail, $source) !== null) {
                            $out['evidence']++;
                        }
                    }
                }
                $out['notes'][] = 'multiple_trunks: ' . implode(', ', $names);
                if ($to !== '' && self::alertDue($slug, 'wide', $cooldown)) {
                    $res = self::sendMail(
                        $to,
                        sprintf('Call quality: packet loss on %d trunks at once', count($names)),
                        "Calls are losing media on " . implode(', ', $names) . " at the same time.\n\n"
                        . "No change was made and none is suggested: moving either trunk would blame a carrier that may not be at fault.\n"
                        . "Check the uplink and both carriers.\n\n" . $link,
                        self::mailHtml(
                            sprintf('Packet loss on %s at the same time', implode(', ', $names)),
                            [
                                'Calls are losing media on ' . implode(', ', $names) . ' simultaneously.',
                                'No route change is suggested: a shared uplink is the likelier cause.',
                                'Check the uplink and both carriers.',
                            ],
                            $link
                        )
                    );
                    if ($res['sent']) {
                        $out['mails']++;
                        self::markAlert($slug, 'wide');
                    } else {
                        $out['notes'][] = 'mail_failed: ' . $res['error'];
                    }
                }
                $byTrunk = [];
            }

            foreach ($byTrunk as $name => $group) {
                $routes = self::routeOpportunities($name);
                $detail = self::suggestionText($name, $routes);
                $worst = $group['calls'][0];
                $ids = [];
                foreach ($group['calls'] as $call) {
                    $id = self::recordEvidence($call, 'suggested', $detail, $source);
                    if ($id !== null) {
                        $ids[] = $id;
                        $out['evidence']++;
                    }
                }
                $out['notes'][] = sprintf(
                    '%s: %d poor call(s), worst %.2f%% loss, %s',
                    $name,
                    count($group['calls']),
                    (float) $worst['verdict']['loss_pct'],
                    $routes === [] ? 'no route to reorder' : count($routes) . ' route(s) to reorder'
                );
                if ($to === '') {
                    continue;
                }
                if (!self::alertDue($slug, 'trunk:' . $name, $cooldown)) {
                    $out['notes'][] = 'held: ' . $name . ' (inside ' . $cooldown . ' h repeat window)';
                    continue;
                }
                if ($out['mails'] >= self::MAX_ALERTS) {
                    $out['skipped'][] = 'alert_cap';
                    continue;
                }
                $lines = [
                    sprintf('%d poor call(s) in the last %d minutes, worst %.2f%% packet loss (%d RTCP report(s)).',
                        count($group['calls']), self::ALERT_WINDOW_MIN,
                        (float) $worst['verdict']['loss_pct'], (int) $worst['verdict']['reports']),
                    'Trunk: ' . $name
                        . (!empty($group['trunk']['ip_address']) ? ' (' . (string) $group['trunk']['ip_address'] . ')' : ''),
                    $detail,
                ];
                $res = self::sendMail(
                    $to,
                    sprintf('Call quality: %s is losing media (%.2f%% loss)', $name, (float) $worst['verdict']['loss_pct']),
                    "Sustained packet loss on " . $name . ".\n\n" . implode("\n", $lines) . "\n\n" . $link,
                    self::mailHtml(sprintf('Sustained packet loss on %s', $name), $lines, $link)
                );
                if ($res['sent']) {
                    $out['mails']++;
                    self::markAlert($slug, 'trunk:' . $name);
                    foreach ($ids as $id) {
                        self::markNotified($id);
                    }
                } else {
                    $out['notes'][] = 'mail_failed: ' . $res['error'];
                }
            }

            $mailLocals = self::mailLocalPeers($settings) && $to !== '';
            foreach (array_slice($others, 0, self::MAX_ALERTS) as $call) {
                $peer = (string) ($call['peers'][0] ?? 'unknown');
                $id = self::recordEvidence(
                    $call,
                    'local_peer',
                    'The media peer is not a configured trunk, so no route change applies. Check the phone, its Wi-Fi or the local switch.',
                    $source
                );
                if ($id !== null) {
                    $out['evidence']++;
                }
                $out['notes'][] = sprintf('local peer %s: %.2f%% loss', $peer, (float) $call['verdict']['loss_pct']);
                if (!$mailLocals || !self::alertDue($slug, 'peer:' . $peer, $cooldown)) {
                    continue;
                }
                if ($out['mails'] >= self::MAX_ALERTS) {
                    $out['skipped'][] = 'alert_cap';
                    continue;
                }
                $lines = [
                    sprintf('Call %s is losing media (%.2f%% packet loss) against %s.',
                        mb_substr((string) $call['call_id'], 0, 32),
                        (float) $call['verdict']['loss_pct'],
                        implode(', ', $call['peers'])),
                    'The peer is not a configured trunk, so there is no route to change: check the phone, its Wi-Fi or the local switch.',
                ];
                $res = self::sendMail(
                    $to,
                    sprintf('Call quality: a phone peer is losing media (%.2f%% loss)', (float) $call['verdict']['loss_pct']),
                    implode("\n", $lines) . "\n\n" . $link,
                    self::mailHtml('Call quality problem on a phone or LAN peer', $lines, $link)
                );
                if ($res['sent']) {
                    $out['mails']++;
                    self::markAlert($slug, 'peer:' . $peer);
                    if ($id !== null) {
                        self::markNotified($id);
                    }
                } else {
                    $out['notes'][] = 'mail_failed: ' . $res['error'];
                }
            }
        } catch (\Throwable $e) {
            $out['error'] = mb_substr($e->getMessage(), 0, 200);
        }
        return $out;
    }

    /**
     * A pass run on demand from the page: ignores the five-minute clock, keeps
     * the alert cooldown (a human clicking Run must not mail twice), and
     * records itself as the last pass so the worker does not repeat it.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public static function runNow(array $settings, string $slug): array
    {
        $result = self::tick($settings, $slug, 'manual');
        self::rememberPass($slug, ['state' => 'done', 'manual' => true] + $result);
        return $result;
    }
}
