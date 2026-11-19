<?php

declare(strict_types=1);

// Resolve core services through the host loader, independent of the plugin installation path.
$coreSrc = dirname((string)(new ReflectionClass('PluginManager'))->getFileName(), 2);
require_once $coreSrc . '/helpers.php';
require_once $coreSrc . '/asterisk_helper.php';
require_once $coreSrc . '/services/AsteriskSettingsService.php';
require_once $coreSrc . '/sync/DialplanDestinationBuilder.php';
require_once $coreSrc . '/services/WebhookService.php';

/**
 * Core service for the `auto-dialer` plugin.
 *
 * Uses `plg_autodial_campaigns` and `plg_autodial_numbers` tables created via `schema.sql`.
 */
final class AutoDialerService
{
    public const ALLOWED_DEST_TYPES = [
        'queue',
        'ivr',
        'extension',
        'ring_group',
        'announcement',
        'ai_app',
        'ai_agent',
    ];

    /** Destinations where AI talks with the person called: it should know who that is. */
    public const AI_DEST_TYPES = ['ai_app', 'ai_agent'];

    /**
     * Ensure the plugin's `plg_*` tables exist.
     */
    public static function ensureSchema(): void
    {
        if (class_exists('PluginManager')) {
            PluginManager::applyPluginSchema('auto-dialer', __DIR__);
        }
    }

    /**
     * Return all campaigns with aggregated number counts.
     *
     * @return list<array<string, mixed>>
     */
    public static function listCampaigns(): array
    {
        self::ensureSchema();
        $db = getDB();
        $sql = "SELECT c.*,
                    COUNT(n.id) AS total_numbers,
                    SUM(CASE WHEN n.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN n.status IN ('dialing', 'answered') THEN 1 ELSE 0 END) AS dialing_count,
                    SUM(CASE WHEN n.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN n.status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN n.amd_status = 'HUMAN' THEN 1 ELSE 0 END) AS human_count,
                    SUM(CASE WHEN n.amd_status = 'MACHINE' THEN 1 ELSE 0 END) AS machine_count
                FROM plg_autodial_campaigns c
                LEFT JOIN plg_autodial_numbers n ON n.campaign_id = c.id
                GROUP BY c.id
                ORDER BY c.id DESC";
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCampaign(int $id): ?array
    {
        self::ensureSchema();
        $stmt = getDB()->prepare('SELECT * FROM plg_autodial_campaigns WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Create a new campaign and sync the dialplan immediately.
     *
     * @param array<string, mixed> $input
     */
    public static function createCampaign(array $input): int
    {
        self::ensureSchema();
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Campaign name is required.');
        }

        $destType = (string)($input['dest_type'] ?? 'queue');
        if (!in_array($destType, self::ALLOWED_DEST_TYPES, true)) {
            $destType = 'queue';
        }
        $destId = preg_replace('/[^a-zA-Z0-9_-]/', '', trim((string)($input['dest_id'] ?? '')));
        if ($destId === '') {
            throw new \InvalidArgumentException('Please select a target destination.');
        }

        $callerId = preg_replace('/[^0-9+]/', '', trim((string)($input['caller_id'] ?? '')));
        $maxConcurrent = max(1, min(50, (int)($input['max_concurrent'] ?? 2)));
        $queuePacing = !empty($input['queue_free_agent_pacing']) ? 1 : 0;
        $maxRetries = max(0, min(10, (int)($input['max_retries'] ?? 2)));
        $retryInterval = max(30, min(86400, (int)($input['retry_interval_sec'] ?? 300)));
        $amdEnabled = !empty($input['amd_enabled']) ? 1 : 0;
        $amdAction = (($input['amd_machine_action'] ?? 'hangup') === 'play_sound') ? 'play_sound' : 'hangup';
        $amdSound = preg_replace('/[^a-zA-Z0-9_\/-]/', '', trim((string)($input['amd_machine_sound'] ?? '')));

        self::ensureSchema();
        self::validateCampaignExt($input);

        $db = getDB();
        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }
        try {
            $stmt = $db->prepare(
                "INSERT INTO plg_autodial_campaigns
                 (name, status, caller_id, max_concurrent, queue_free_agent_pacing, max_retries, retry_interval_sec, amd_enabled, amd_machine_action, amd_machine_sound, dest_type, dest_id)
                 VALUES (?, 'paused', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                mb_substr($name, 0, 120),
                $callerId,
                $maxConcurrent,
                $queuePacing,
                $maxRetries,
                $retryInterval,
                $amdEnabled,
                $amdAction,
                $amdSound,
                $destType,
                $destId,
            ]);
            $campId = (int)$db->lastInsertId();

            self::saveCampaignExt($campId, $input, isset($input['_owner']) ? (int)$input['_owner'] : null);

            // Optional bulk numbers submitted during campaign creation
            if (!empty($input['numbers_bulk'])) {
                self::importNumbersFromText($campId, (string)$input['numbers_bulk']);
            }
            if ($ownTx && $db->inTransaction()) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        self::syncDialplan();
        return $campId;
    }

    public static function setCampaignStatus(int $campaignId, string $status): void
    {
        if (!in_array($status, ['running', 'paused', 'completed'], true)) {
            return;
        }
        $stmt = getDB()->prepare('UPDATE plg_autodial_campaigns SET status = ? WHERE id = ?');
        $stmt->execute([$status, $campaignId]);
        if ($status === 'running' && self::campaignExt($campaignId)['source_type'] === 'db') {
            self::importFromDb($campaignId);
        }
        self::syncDialplan();
    }

    public static function deleteCampaign(int $campaignId): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM plg_autodial_numbers WHERE campaign_id = ?')->execute([$campaignId]);
            $db->prepare('DELETE FROM plg_autodial_number_ext WHERE campaign_id = ?')->execute([$campaignId]);
            $db->prepare('DELETE FROM plg_autodial_campaign_ext WHERE campaign_id = ?')->execute([$campaignId]);
            $db->prepare('DELETE FROM plg_autodial_campaigns WHERE id = ?')->execute([$campaignId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        self::syncDialplan();
    }

    public static function resetCampaignNumbers(int $campaignId, bool $onlyFailed = true): int
    {
        $db = getDB();
        if ($onlyFailed) {
            $stmt = $db->prepare(
                "UPDATE plg_autodial_numbers
                 SET status = 'pending', attempts = 0, call_result = '', amd_status = '', amd_cause = '', next_attempt_at = NULL
                 WHERE campaign_id = ? AND status IN ('failed', 'dialing')"
            );
        } else {
            $stmt = $db->prepare(
                "UPDATE plg_autodial_numbers
                 SET status = 'pending', attempts = 0, call_result = '', amd_status = '', amd_cause = '', duration = 0, next_attempt_at = NULL
                 WHERE campaign_id = ?"
            );
        }
        $stmt->execute([$campaignId]);
        // A number called again reports its new result again.
        $db->prepare("UPDATE plg_autodial_number_ext e JOIN plg_autodial_numbers n ON n.id = e.number_id
                      SET e.reported = 0, e.report_error = '', e.agent = '' WHERE e.campaign_id = ? AND n.status = 'pending'")
           ->execute([$campaignId]);
        $db->prepare("UPDATE plg_autodial_campaigns SET status = 'paused' WHERE id = ?")->execute([$campaignId]);
        return $stmt->rowCount();
    }

    /**
     * Parse lines of `number[,contact_name[,notes]]` and add them; a number already in the
     * campaign is not added again.
     */
    public static function importNumbersFromText(int $campaignId, string $rawText): int
    {
        self::ensureSchema();
        $lines = preg_split('/\R/', $rawText) ?: [];
        $added = 0;
        $first = true;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = str_getcsv($line, str_contains($line, ';') && !str_contains($line, ',') ? ';' : ',', '"', '\\');
            $rawPhone = trim((string)($parts[0] ?? ''));
            // Skip CSV header row if present
            if ($first && preg_match('/^(phone|number|numara|telefon|tel)$/i', $rawPhone)) {
                $first = false;
                continue;
            }
            $first = false;
            if (self::addNumber($campaignId, $rawPhone, (string)($parts[1] ?? ''), (string)($parts[2] ?? ''))) {
                $added++;
            }
        }
        return $added;
    }

    /**
     * One number into a campaign. Its key is the external id, else the number: a key the
     * campaign already has is skipped (a database source read again adds only new rows).
     */
    public static function addNumber(int $campaignId, string $rawPhone, string $name = '', string $notes = '', string $externalId = ''): bool
    {
        $phone = preg_replace('/[^0-9+]/', '', $rawPhone) ?? '';
        if (strlen($phone) < 3 || strlen($phone) > 40) {
            return false;
        }
        $rawExternalId = trim($externalId);
        $externalId = mb_substr($rawExternalId, 0, 120);
        if ($rawExternalId !== '') {
            $key = mb_strlen($rawExternalId) <= 117
                ? 'id:' . $rawExternalId
                : 'ih:' . hash('sha256', $rawExternalId);
        } else {
            $key = 'tel:' . mb_substr($phone, 0, 116);
        }
        $db = getDB();
        $seen = $db->prepare('SELECT 1 FROM plg_autodial_number_ext WHERE campaign_id = ? AND ext_key = ?');
        $seen->execute([$campaignId, $key]);
        if ($seen->fetchColumn()) {
            return false;
        }
        if ($externalId === '') {
            // Numbers added before 1.3 have no key row.
            $old = $db->prepare('SELECT 1 FROM plg_autodial_numbers WHERE campaign_id = ? AND phone_number = ? LIMIT 1');
            $old->execute([$campaignId, $phone]);
            if ($old->fetchColumn()) {
                return false;
            }
        }
        $db->prepare("INSERT INTO plg_autodial_numbers (campaign_id, phone_number, contact_name, notes, status) VALUES (?, ?, ?, ?, 'pending')")
            ->execute([$campaignId, $phone, mb_substr(trim($name), 0, 120), mb_substr(trim($notes), 0, 255)]);
        $numId = (int)$db->lastInsertId();
        try {
            $db->prepare('INSERT INTO plg_autodial_number_ext (number_id, campaign_id, ext_key, external_id) VALUES (?, ?, ?, ?)')
                ->execute([$numId, $campaignId, $key, $externalId]);
        } catch (\PDOException $e) {
            // Added at the same moment by another run.
            $db->prepare('DELETE FROM plg_autodial_numbers WHERE id = ?')->execute([$numId]);
            return false;
        }
        return true;
    }

    /**
     * List numbers for a campaign.
     *
     * @return list<array<string, mixed>>
     */
    public static function listNumbers(int $campaignId, int $limit = 200): array
    {
        $limit = max(1, min(100000, $limit));
        $stmt = getDB()->prepare(
            "SELECT n.*, COALESCE(e.external_id, '') AS external_id, COALESCE(e.agent, '') AS agent,
                    COALESCE(e.report_error, '') AS report_error
             FROM plg_autodial_numbers n LEFT JOIN plg_autodial_number_ext e ON e.number_id = n.id
             WHERE n.campaign_id = ? ORDER BY n.id DESC LIMIT {$limit}"
        );
        $stmt->execute([$campaignId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count available (logged-in, unpaused, not-in-use, not-ringing) agents in a queue,
     * minus any callers currently waiting in that queue.
     */
    public static function countAvailableQueueAgents(string $queueIdOrName): int
    {
        $db = getDB();
        $stmt = is_numeric($queueIdOrName)
            ? $db->prepare('SELECT queue_name FROM pbx_queues WHERE id = ?')
            : $db->prepare('SELECT queue_name FROM pbx_queues WHERE queue_name = ?');
        $stmt->execute([$queueIdOrName]);
        $queueName = (string)($stmt->fetchColumn() ?: $queueIdOrName);
        $queueName = preg_replace('/[^a-zA-Z0-9_-]/', '', $queueName);
        if ($queueName === '') {
            return 0;
        }

        // In unit-test mode without live Asterisk, return 1 so test ticks can proceed
        if (getenv('AIPBX_NO_ASTERISK') === '1') {
            return 1;
        }

        $res = AsteriskHelper::execCLI("queue show {$queueName}");
        $output = (string)($res['output'] ?? '');
        if ($output === '') {
            return 0;
        }

        $waitingCallers = 0;
        $freeAgents = 0;
        foreach (explode("\n", $output) as $line) {
            $clean = trim((string)preg_replace("/\x1b\[[0-9;]*[a-zA-Z]/", '', $line));
            if ($clean === '') {
                continue;
            }
            if (preg_match('/^[a-zA-Z0-9_-]+\s+has\s+(\d+)\s+calls/i', $clean, $m)) {
                $waitingCallers = (int)$m[1];
                continue;
            }
            if (preg_match('/^\d+\.\s/', $clean)) {
                continue;
            }
            if (preg_match('/(?:PJSIP|Local)\/([0-9a-zA-Z_-]+)/i', $clean)) {
                $isPaused = (bool)preg_match('/\(paused\b/i', $clean);
                $isUnavailable = stripos($clean, '(Unavailable)') !== false || stripos($clean, '(Invalid)') !== false;
                $isBusy = (bool)preg_match('/\((In use|Busy|Ring\+Inuse|On Hold)\)/i', $clean);
                $isRinging = !$isBusy && stripos($clean, '(Ringing)') !== false;
                if (!$isPaused && !$isUnavailable && !$isBusy && !$isRinging) {
                    $freeAgents++;
                }
            }
        }

        return max(0, $freeAgents - $waitingCallers);
    }

    /**
     * Run one dialer cycle across all running campaigns.
     *
     * @param array<string, mixed> $settings
     * @return int Number of calls originated in this tick
     */
    public static function tickCampaigns(array $settings = []): int
    {
        self::ensureSchema();
        $db = getDB();
        self::refreshDueSources();

        // 1. Recover stuck 'dialing' numbers older than max(90s, dial_timeout_sec + 30s)
        $dialTimeoutSec = max(10, min(120, (int)($settings['dial_timeout_sec'] ?? 30)));
        $stuckTimeoutSec = max(90, $dialTimeoutSec + 30);
        $stuckRows = $db->query(
            "SELECT n.id, n.campaign_id, n.attempts, n.status, c.max_retries, c.retry_interval_sec
             FROM plg_autodial_numbers n
             JOIN plg_autodial_campaigns c ON c.id = n.campaign_id
             WHERE n.status IN ('dialing', 'answered')
               AND n.last_attempt_at IS NOT NULL
               AND n.last_attempt_at < DATE_SUB(NOW(), INTERVAL {$stuckTimeoutSec} SECOND)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $channels = $stuckRows !== [] ? AsteriskHelper::execCLI('core show channels concise') : [];
        foreach ($stuckRows as $st) {
            if (empty($channels['success']) || str_contains((string)($channels['output'] ?? ''), 'Local/dial_' . (int)$st['id'] . '_')) {
                continue; // Retain a live call's slot, or retain it when CLI is unavailable.
            }
            if ($st['status'] === 'answered') {
                $db->prepare("UPDATE plg_autodial_numbers SET status = 'completed' WHERE id = ? AND status = 'answered' AND attempts = ?")
                    ->execute([(int)$st['id'], (int)$st['attempts']]);
                continue;
            }
            self::markAttemptFailed(
                (int)$st['id'],
                (int)$st['attempts'],
                (int)$st['max_retries'],
                (int)$st['retry_interval_sec'],
                'NOANSWER'
            );
        }

        // 2. Process running campaigns
        $campaigns = $db->query("SELECT * FROM plg_autodial_campaigns WHERE status = 'running' ORDER BY id ASC")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $originatedTotal = 0;

        foreach ($campaigns as $camp) {
            $campId = (int)$camp['id'];
            $maxConc = max(1, (int)$camp['max_concurrent']);

            // Queue Free-Agent Pacing: cap concurrency to available idle agents in the target queue
            if ($camp['dest_type'] === 'queue' && !empty($camp['queue_free_agent_pacing'])) {
                $freeAgents = self::countAvailableQueueAgents((string)$camp['dest_id']);
                $maxConc = min($maxConc, $freeAgents);
                if ($maxConc <= 0) {
                    continue;
                }
            }

            $activeStmt = $db->prepare("SELECT COUNT(*) FROM plg_autodial_numbers WHERE campaign_id = ? AND status IN ('dialing', 'answered')");
            $activeStmt->execute([$campId]);
            $activeDialing = (int)$activeStmt->fetchColumn();

            $slots = $maxConc - $activeDialing;
            if ($slots <= 0) {
                continue;
            }

            $dueStmt = $db->prepare(
                "SELECT * FROM plg_autodial_numbers
                 WHERE campaign_id = ?
                   AND status = 'pending'
                   AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                 ORDER BY id ASC
                 LIMIT " . (int)$slots
            );
            $dueStmt->execute([$campId]);
            $dueNumbers = $dueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if (empty($dueNumbers)) {
                // Check if campaign has any remaining pending or dialing numbers at all
                $remStmt = $db->prepare("SELECT COUNT(*) FROM plg_autodial_numbers WHERE campaign_id = ? AND status IN ('pending', 'dialing', 'answered')");
                $remStmt->execute([$campId]);
                if ((int)$remStmt->fetchColumn() === 0) {
                    $db->prepare("UPDATE plg_autodial_campaigns SET status = 'completed' WHERE id = ?")->execute([$campId]);
                }
                continue;
            }

            foreach ($dueNumbers as $numRow) {
                $numId = (int)$numRow['id'];
                $phone = preg_replace('/[^0-9+]/', '', (string)$numRow['phone_number']);
                if ($phone === '') {
                    $db->prepare("UPDATE plg_autodial_numbers SET status = 'failed', call_result = 'INVALID' WHERE id = ?")->execute([$numId]);
                    continue;
                }

                // Atomically mark as dialing and increment attempts
                $upd = $db->prepare(
                    "UPDATE plg_autodial_numbers
                     SET status = 'dialing', attempts = attempts + 1, last_attempt_at = NOW(), uniqueid = '', call_result = '', amd_status = '', amd_cause = '', duration = 0
                     WHERE id = ? AND status = 'pending'"
                );
                $upd->execute([$numId]);
                if ($upd->rowCount() === 0) {
                    continue;
                }

                $attempt = (int)$numRow['attempts'] + 1;
                $ok = self::originate(
                    sprintf('Local/dial_%d_%s_%d_%d@aipbx-autodial-out/n', $numId, $phone, $campId, $attempt),
                    'aipbx-autodial-amd',
                    sprintf('route_%d_%d_%d_%s', $campId, $numId, $attempt, $phone)
                );
                if ($ok) {
                    $originatedTotal++;
                } else {
                    self::markAttemptFailed(
                        $numId,
                        (int)$numRow['attempts'] + 1,
                        (int)$camp['max_retries'],
                        (int)$camp['retry_interval_sec'],
                        'ORIGINATE_FAILED'
                    );
                }
            }
        }

        self::reportResults();
        return $originatedTotal;
    }

    /** Tests only: fn(string $channel, string $context, string $exten): bool in place of AMI. */
    public static ?\Closure $originator = null;

    /**
     * Starts one campaign call over AMI. The portal user may not run "channel originate" on the
     * Asterisk CLI (cli_permissions.conf), so the worker, which runs as www-data, could not dial.
     */
    private static function originate(string $channel, string $context, string $exten): bool
    {
        if (self::$originator !== null) {
            return (bool)(self::$originator)($channel, $context, $exten);
        }
        if (!defined('AMI_HOST') || !defined('AMI_USER') || !defined('AMI_PASS')) {
            return false;
        }
        $fp = @fsockopen((string)AMI_HOST, defined('AMI_PORT') ? (int)AMI_PORT : 5038, $errno, $errstr, 3);
        if (!$fp) {
            return false;
        }
        stream_set_timeout($fp, 5);
        $read = static function () use ($fp): string {
            $msg = '';
            while (($line = fgets($fp, 4096)) !== false) {
                if (trim($line) === '') {
                    if (str_contains($msg, 'Response:')) {
                        break;
                    }
                    $msg = '';
                    continue;
                }
                $msg .= $line;
            }
            return $msg;
        };
        fwrite($fp, "Action: Login\r\nUsername: " . AMI_USER . "\r\nSecret: " . AMI_PASS . "\r\nEvents: off\r\n\r\n");
        $ok = str_contains($read(), 'Response: Success');
        if ($ok) {
            fwrite($fp, "Action: Originate\r\nChannel: {$channel}\r\nContext: {$context}\r\nExten: {$exten}\r\n"
                . "Priority: 1\r\nTimeout: 45000\r\nAsync: true\r\n\r\n");
            $ok = str_contains($read(), 'Response: Success');
            fwrite($fp, "Action: Logoff\r\n\r\n");
        }
        fclose($fp);
        return $ok;
    }

    public static function markAttemptFailed(int $numId, int $attempts, int $maxRetries, int $retryIntervalSec, string $result): void
    {
        $db = getDB();
        // Total allowed tries = 1 initial + max_retries
        if ($attempts <= $maxRetries) {
            $stmt = $db->prepare(
                "UPDATE plg_autodial_numbers
                 SET status = 'pending',
                     call_result = ?,
                     next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE id = ? AND status = 'dialing' AND attempts = ?"
            );
            $stmt->execute([$result, max(30, $retryIntervalSec), $numId, $attempts]);
        } else {
            $stmt = $db->prepare(
                "UPDATE plg_autodial_numbers
                 SET status = 'failed',
                     call_result = ?,
                     next_attempt_at = NULL
                 WHERE id = ? AND status = 'dialing' AND attempts = ?"
            );
            $stmt->execute([$result, $numId, $attempts]);
        }
    }

    /**
     * Handle real-time dialplan callbacks from Asterisk (`/api/plugins/auto-dialer/callback`).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function handleCallback(array $params): array
    {
        self::ensureSchema();
        $numId = (int)($params['num_id'] ?? 0);
        $event = trim((string)($params['event'] ?? ''));
        if ($numId <= 0 || $event === '') {
            return ['success' => false, 'error' => 'Missing num_id or event'];
        }

        $db = getDB();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                "SELECT n.*, c.max_retries, c.retry_interval_sec, c.amd_machine_action
                 FROM plg_autodial_numbers n
                 JOIN plg_autodial_campaigns c ON c.id = n.campaign_id
                 WHERE n.id = ? FOR UPDATE"
            );
            $stmt->execute([$numId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['success' => false, 'error' => 'Number record not found'];
            }

            if ((int)($params['attempt'] ?? 0) !== (int)$row['attempts'] || (int)$row['attempts'] <= 0) {
                return ['success' => true, 'state' => 'stale_attempt'];
            }
            // What the AI understood (an AI application's request, "none", "_end"), stored with the
            // result: also when the other leg's hang-up has already finished the row.
            $ai = preg_match('/^[a-z0-9_-]{1,40}$/', (string)($params['ai'] ?? '')) ? (string)$params['ai'] : '';
            if ($event === 'hangup' && $ai !== '' && in_array($row['status'], ['answered', 'completed'], true)) {
                $db->prepare('UPDATE plg_autodial_numbers SET call_result = ? WHERE id = ?')
                   ->execute([substr('AI:' . ($ai === '_end' ? 'ended' : $ai), 0, 32), $numId]);
            }
            if (!in_array($row['status'], ['dialing', 'answered'], true)) {
                return ['success' => true, 'state' => 'already_finished'];
            }
            $uniqueId = substr(trim((string)($params['uniqueid'] ?? $row['uniqueid'] ?? '')), 0, 64);

            if ($event === 'dial_failed') {
                // Only mark failed if it wasn't already answered/completed
                if ($row['status'] === 'answered') {
                    return ['success' => true, 'state' => 'already_completed'];
                }
                $dialStatus = strtoupper(trim((string)($params['dialstatus'] ?? 'NOANSWER')));
                if ($dialStatus === 'ANSWER') {
                    return ['success' => true, 'state' => 'answered'];
                }
                self::markAttemptFailed(
                    $numId,
                    (int)$row['attempts'],
                    (int)$row['max_retries'],
                    (int)$row['retry_interval_sec'],
                    $dialStatus !== '' ? $dialStatus : 'NOANSWER'
                );
                return ['success' => true, 'state' => 'retry_or_failed', 'dialstatus' => $dialStatus];
            }

            if ($event === 'answered') {
                $amdStatus = strtoupper(trim((string)($params['amd_status'] ?? 'HUMAN')));
                $amdCause = substr(trim((string)($params['amd_cause'] ?? '')), 0, 64);
                $callResult = 'ANSWERED';
                if ($amdStatus === 'MACHINE') {
                    $callResult = ($row['amd_machine_action'] === 'play_sound') ? 'MACHINE_MSG' : 'MACHINE_HANGUP';
                }
                $upd = $db->prepare(
                    "UPDATE plg_autodial_numbers
                     SET status = 'answered',
                         call_result = ?,
                         amd_status = ?,
                         amd_cause = ?,
                         uniqueid = ?,
                         next_attempt_at = NULL
                     WHERE id = ?"
                );
                $upd->execute([$callResult, $amdStatus, $amdCause, $uniqueId, $numId]);
                return ['success' => true, 'state' => 'answered', 'amd_status' => $amdStatus];
            }

            if ($event === 'hangup') {
                // The outbound leg has no final AI or agent data. Keep the call
                // open until the answered leg supplies its result.
                if (($params['leg'] ?? '') === 'outbound') {
                    return ['success' => true, 'state' => 'outbound_closed'];
                }
                $duration = max(0, (int)($params['duration'] ?? 0));
                $upd = $db->prepare(
                    "UPDATE plg_autodial_numbers
                     SET status = CASE WHEN status = 'answered' THEN 'completed' ELSE status END,
                         duration = GREATEST(duration, ?),
                         uniqueid = CASE WHEN uniqueid = '' THEN ? ELSE uniqueid END
                     WHERE id = ?"
                );
                $upd->execute([$duration, $uniqueId, $numId]);
                $agent = preg_replace('/[^0-9A-Za-z_.-]/', '', (string)($params['agent'] ?? '')) ?? '';
                if ($agent !== '') {
                    $db->prepare("UPDATE plg_autodial_number_ext SET agent = ? WHERE number_id = ? AND agent = ''")
                       ->execute([substr($agent, 0, 40), $numId]);
                }
                return ['success' => true, 'state' => 'hangup_recorded'];
            }

            return ['success' => false, 'error' => 'Unknown event'];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        } finally {
            if ($db->inTransaction()) { $db->commit(); }
        }
    }

    /**
     * Build and write `/etc/asterisk/pbx/extensions_plugin_auto-dialer.conf`.
     */
    public static function syncDialplan(?array $settings = null): void
    {
        self::ensureSchema();
        if ($settings === null && class_exists('PluginManager')) {
            $settings = PluginManager::getAllSettings('auto-dialer', false);
        }
        $settings = $settings ?? [];

        $callbackToken = PluginManager::getSetting('auto-dialer', '_callback_token');
        if (!preg_match('/^[a-f0-9]{64}$/D', $callbackToken)) {
            $callbackToken = bin2hex(random_bytes(32));
            PluginManager::setSetting('auto-dialer', '_callback_token', $callbackToken, true);
        }

        $defaultCid = preg_replace('/[^0-9+]/', '', trim((string)($settings['default_caller_id'] ?? '')));
        $dialTimeout = max(10, min(120, (int)($settings['dial_timeout_sec'] ?? 30)));
        $amdArgs = AsteriskSettingsService::amdDialplanArgs();

        $campaigns = getDB()->query('SELECT * FROM plg_autodial_campaigns ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $lines = [
            '; Auto-generated by AiPBX Plugin: auto-dialer',
            '',
            '[aipbx-autodial-out]',
            'exten => _dial_X.,1,NoOp(AutoDialer Outbound Leg: ${EXTEN})',
            ' same => n,Set(__AUTODIAL_NUM_ID=${CUT(EXTEN,_,2)})',
            ' same => n,Set(__AUTODIAL_ATTEMPT=${CUT(EXTEN,_,5)})',
            ' same => n,Set(__AUTODIAL_PHONE=${CUT(EXTEN,_,3)})',
            ' same => n,Set(CHANNEL(hangup_handler_push)=aipbx-autodial-hangup,s,1(outbound))',
            ' same => n,Set(CDR(direction)=outbound)',
        ];
        if ($defaultCid !== '') {
            $lines[] = " same => n,Set(CALLERID(num)={$defaultCid})";
        }
        // The campaign's own caller ID on the leg the callee sees (dial_<num>_<phone>_<campaign>).
        foreach ($campaigns as $c) {
            $campCid = preg_replace('/[^0-9+]/', '', (string)($c['caller_id'] ?? ''));
            if ($campCid !== '') {
                $lines[] = ' same => n,ExecIf($["${CUT(EXTEN,_,4)}" = "' . (int)$c['id'] . '"]?Set(CALLERID(num)=' . $campCid . '))';
            }
        }
        $lines[] = " same => n,Dial(Local/\${AUTODIAL_PHONE}@from-internal-pbx/n,{$dialTimeout},g)";
        $lines[] = ' same => n,GotoIf($["${DIALSTATUS}" = "ANSWER"]?done)';
        $lines[] = " same => n,Set(CURLOPT(userpwd)=autodialer:{$callbackToken})";
        $lines[] = ' same => n,Set(AD_CB=${CURL(http://127.0.0.1/api/plugins/auto-dialer/callback?num_id=${AUTODIAL_NUM_ID}&attempt=${AUTODIAL_ATTEMPT}&event=dial_failed&dialstatus=${DIALSTATUS}&uniqueid=${UNIQUEID})})';
        $lines[] = ' same => n,Set(CURLOPT(userpwd)=)';
        $lines[] = ' same => n(done),Hangup()';
        $lines[] = '';

        $lines[] = '[aipbx-autodial-amd]';
        $lines[] = 'exten => _route_X.,1,NoOp(AutoDialer Answered Leg: ${EXTEN})';
        $lines[] = ' same => n,Set(__AUTODIAL_CAMP_ID=${CUT(EXTEN,_,2)})';
        $lines[] = ' same => n,Set(__AUTODIAL_NUM_ID=${CUT(EXTEN,_,3)})';
        $lines[] = ' same => n,Set(__AUTODIAL_ATTEMPT=${CUT(EXTEN,_,4)})';
        $lines[] = ' same => n,Set(__AUTODIAL_PHONE=${FILTER(0-9+,${CUT(EXTEN,_,5)})})';
        // This leg's hang-up reports too, with the AI result (AI_INTENT) when an AI application talked.
        $lines[] = ' same => n,Set(CHANNEL(hangup_handler_push)=aipbx-autodial-hangup,s,1(answered))';
        $lines[] = ' same => n,Goto(aipbx-autodial-camp-${AUTODIAL_CAMP_ID},s,1)';
        $lines[] = '';

        foreach ($campaigns as $c) {
            $cid = (int)$c['id'];
            $lines[] = "[aipbx-autodial-camp-{$cid}]";
            // Letters, digits, space and . _ + - only: anything else in a name would be read as dialplan.
            $campName = trim((string)preg_replace('/[^A-Za-z0-9 ._+-]/', '', toCleanAscii((string)$c['name'])));
            $lines[] = "exten => s,1,NoOp(AutoDialer Campaign #{$cid}: {$campName})";
            $lines[] = ' same => n,Answer()';
            if (!empty($c['caller_id'])) {
                $campCid = preg_replace('/[^0-9+]/', '', (string)$c['caller_id']);
                if ($campCid !== '') {
                    $lines[] = " same => n,Set(CALLERID(num)={$campCid})";
                }
            }

            if (!empty($c['amd_enabled'])) {
                $lines[] = " same => n,AMD({$amdArgs})";
                $lines[] = ' same => n,NoOp(AMD Result: status=${AMDSTATUS} cause=${AMDCAUSE})';
                $lines[] = " same => n,Set(CURLOPT(userpwd)=autodialer:{$callbackToken})";
                $lines[] = ' same => n,Set(AD_CB=${CURL(http://127.0.0.1/api/plugins/auto-dialer/callback?num_id=${AUTODIAL_NUM_ID}&attempt=${AUTODIAL_ATTEMPT}&event=answered&amd_status=${AMDSTATUS}&amd_cause=${URIENCODE(${AMDCAUSE})}&uniqueid=${UNIQUEID})})';
                $lines[] = ' same => n,Set(CURLOPT(userpwd)=)';
                $lines[] = ' same => n,GotoIf($["${AMDSTATUS}" = "MACHINE"]?machine:human)';

                // MACHINE branch
                $lines[] = ' same => n(machine),NoOp(AutoDialer AMD detected MACHINE)';
                if (($c['amd_machine_action'] ?? 'hangup') === 'play_sound' && !empty($c['amd_machine_sound'])) {
                    $snd = preg_replace('/[^a-zA-Z0-9_\/-]/', '', (string)$c['amd_machine_sound']);
                    $sndPath = str_starts_with($snd, 'custom/') ? $snd : 'custom/' . $snd;
                    $lines[] = ' same => n,WaitForSilence(1500,1,15)';
                    $lines[] = " same => n,Playback({$sndPath})";
                }
                $lines[] = ' same => n,Hangup()';

                // HUMAN / NOTSURE branch
                $lines[] = ' same => n(human),NoOp(AutoDialer AMD detected HUMAN -> routing to destination)';
            } else {
                $lines[] = " same => n,Set(CURLOPT(userpwd)=autodialer:{$callbackToken})";
                $lines[] = ' same => n,Set(AD_CB=${CURL(http://127.0.0.1/api/plugins/auto-dialer/callback?num_id=${AUTODIAL_NUM_ID}&attempt=${AUTODIAL_ATTEMPT}&event=answered&amd_status=BYPASSED&amd_cause=DISABLED&uniqueid=${UNIQUEID})})';
                $lines[] = ' same => n,Set(CURLOPT(userpwd)=)';
            }

            if (in_array((string)$c['dest_type'], self::AI_DEST_TYPES, true)) {
                // The AI application (its lookups) and the external agent (X-AiPBX-Caller) see the person called.
                $lines[] = ' same => n,ExecIf($["${AUTODIAL_PHONE}" != ""]?Set(CALLERID(num)=${AUTODIAL_PHONE}))';
            }
            $destLines = buildDestinationLines((string)$c['dest_type'], (string)$c['dest_id']);
            foreach (explode("\n", (string)$destLines) as $dl) {
                if (trim($dl) !== '') {
                    $lines[] = $dl;
                }
            }
            $lines[] = ' same => n,Hangup()';
            $lines[] = '';
        }

        $lines[] = '[aipbx-autodial-hangup]';
        // Who took the call: the queue member (Local/1001@...) or the extension dialled.
        $lines[] = 'exten => s,1,Set(AD_AG=${CUT(MEMBERINTERFACE,@,1)})';
        $lines[] = ' same => n,Set(AD_AG=${IF($["${AD_AG}" != ""]?${CUT(AD_AG,/,2)}:${DIALEDPEERNUMBER})})';
        $lines[] = ' same => n,Set(AD_AG=${CUT(AD_AG,@,1)})';
        // A ringed extension is "7410-sip/sip:7410@host": keep the extension, not "7410sipsip7410".
        $lines[] = ' same => n,Set(AD_AG=${CUT(AD_AG,/,1)})';
        $lines[] = ' same => n,Set(AD_AG=${CUT(AD_AG,-,1)})';
        $lines[] = " same => n,Set(CURLOPT(userpwd)=autodialer:{$callbackToken})";
        $lines[] = ' same => n,Set(AD_H=${CURL(http://127.0.0.1/api/plugins/auto-dialer/callback?num_id=${AUTODIAL_NUM_ID}&attempt=${AUTODIAL_ATTEMPT}&event=hangup&leg=${ARG1}&duration=${CDR(billsec)}&uniqueid=${UNIQUEID}&ai=${FILTER(a-z0-9_-,${AI_INTENT})}&agent=${FILTER(0-9A-Za-z_.-,${AD_AG})})})';
        $lines[] = ' same => n,Set(CURLOPT(userpwd)=)';
        $lines[] = ' same => n,Return()';

        $content = implode("\n", $lines) . "\n";
        if (function_exists('writeConfWithRollback')) {
            // Like every other sync: the previous file comes back if Asterisk rejects the new one.
            writeConfWithRollback('extensions_plugin_auto-dialer.conf', $content, function () {
                AsteriskHelper::assertReloadsOk([AsteriskHelper::reloadDialplan()], 'auto-dialer');
            }, 'auto-dialer');
        } elseif (function_exists('writePBXConf')) {
            writePBXConf('extensions_plugin_auto-dialer.conf', $content);
            AsteriskHelper::reloadDialplan();
        }
    }
    // ---- 1.3.0: number source, managers, results -------------------------------------

    /** Variables a write-back template may use (besides date and time). */
    public const RESULT_VARS = ['result', 'attempts', 'duration', 'amd', 'agent', 'called_at', 'campaign', 'phone', 'name', 'external_id'];
    public const SOURCE_TYPES = ['text', 'db'];
    /** Results delivered per worker tick; the rest follow on the next ones. */
    public const REPORT_BATCH = 100;

    /** PluginData (external databases for plugins) is in AiPBX 1.80 and later. */
    private static function hasPluginData(): bool
    {
        if (!class_exists('PluginData')) {
            $file = dirname((string)(new ReflectionClass('PluginManager'))->getFileName()) . '/PluginData.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
        return class_exists('PluginData');
    }

    /**
     * The campaign's 1.3 settings; defaults for a campaign made before (text, admin only).
     *
     * @return array<string, mixed>
     */
    public static function campaignExt(int $campaignId): array
    {
        self::ensureSchema();
        $st = getDB()->prepare('SELECT * FROM plg_autodial_campaign_ext WHERE campaign_id = ?');
        $st->execute([$campaignId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return $row + [
            'campaign_id' => $campaignId, 'owner_user_id' => null, 'manager_users' => '', 'manager_roles' => '',
            'source_type' => 'text', 'db_id' => null, 'query_sql' => '', 'refresh_min' => 0, 'last_refresh_at' => null,
            'last_error' => '', 'webhook' => 1, 'writeback_db_id' => null, 'writeback_sql' => '',
        ];
    }

    /**
     * Validates external source and write-back settings before writing campaign rows.
     *
     * @param array<string, mixed> $input
     */
    public static function validateCampaignExt(array $input): void
    {
        $source = in_array($input['source_type'] ?? 'text', self::SOURCE_TYPES, true) ? (string)($input['source_type'] ?? 'text') : 'text';
        $dbId = (int)($input['db_id'] ?? 0);
        $sql = trim((string)($input['query_sql'] ?? ''));
        if ($source === 'db') {
            if ($dbId <= 0 || !preg_match('/^(SELECT|WITH)\b/i', $sql)) {
                throw new \InvalidArgumentException('A database source needs a connection and a SELECT query (columns: phone, name, external_id, notes).');
            }
            if (!self::hasPluginData()) {
                throw new \InvalidArgumentException('Database sources need AiPBX 1.80 or later.');
            }
        }
        $wbDb = (int)($input['writeback_db_id'] ?? 0);
        $wbSql = trim((string)($input['writeback_sql'] ?? ''));
        if ($wbDb > 0 && $wbSql !== '') {
            if (!self::hasPluginData()) {
                throw new \InvalidArgumentException('Writing results back needs AiPBX 1.80 or later.');
            }
            $problem = PluginData::writeProblem($wbSql, self::RESULT_VARS);
            if ($problem !== null) {
                throw new \InvalidArgumentException($problem);
            }
        }
    }

    /**
     * Saves who manages the campaign, where its numbers come from and where results go.
     * Checked here: a database source needs a read query, a write-back one INSERT/UPDATE
     * on a connection that allows writing.
     *
     * @param array<string, mixed> $input
     */
    public static function saveCampaignExt(int $campaignId, array $input, ?int $ownerId = null): void
    {
        if (self::getCampaign($campaignId) === null) {
            throw new \InvalidArgumentException('Campaign not found.');
        }
        self::validateCampaignExt($input);
        $old = self::campaignExt($campaignId);
        $ids = static fn($v): string => implode(',', array_unique(array_filter(array_map('intval', (array)$v))));
        $roles = static fn($v): string => implode(',', array_unique(array_filter(array_map(
            static fn($r): string => preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$r)) ?? '', (array)$v))));
        $source = in_array($input['source_type'] ?? 'text', self::SOURCE_TYPES, true) ? (string)($input['source_type'] ?? 'text') : 'text';
        $dbId = (int)($input['db_id'] ?? 0);
        $sql = trim((string)($input['query_sql'] ?? ''));
        $wbDb = (int)($input['writeback_db_id'] ?? 0);
        $wbSql = trim((string)($input['writeback_sql'] ?? ''));
        if ($wbDb <= 0 || $wbSql === '') {
            [$wbDb, $wbSql] = [0, ''];
        }
        getDB()->prepare(
            'REPLACE INTO plg_autodial_campaign_ext
             (campaign_id, owner_user_id, manager_users, manager_roles, source_type, db_id, query_sql, refresh_min,
              last_refresh_at, last_error, webhook, writeback_db_id, writeback_sql)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $campaignId, $ownerId ?? $old['owner_user_id'], $ids($input['manager_users'] ?? []), $roles($input['manager_roles'] ?? []),
            $source, $source === 'db' ? $dbId : null, $source === 'db' ? $sql : null,
            $source === 'db' ? max(0, min(1440, (int)($input['refresh_min'] ?? 0))) : 0,
            $old['last_refresh_at'], (string)$old['last_error'], empty($input['webhook']) ? 0 : 1,
            $wbDb > 0 ? $wbDb : null, $wbSql !== '' ? $wbSql : null,
        ]);
    }

    /**
     * Whether this user may see and run the campaign: an administrator always; else its
     * creator, a user it names, or a user with a role it names.
     *
     * @param array<string, mixed> $user
     */
    public static function canManage(int $campaignId, array $user): bool
    {
        $roles = function_exists('userRoles') ? userRoles() : [(string)($user['role'] ?? '')];
        if (in_array('admin', $roles, true)) {
            return true;
        }
        $ext = self::campaignExt($campaignId);
        $uid = (int)($user['id'] ?? 0);
        return $uid > 0 && ((int)$ext['owner_user_id'] === $uid
            || in_array((string)$uid, explode(',', (string)$ext['manager_users']), true)
            || array_intersect($roles, array_filter(explode(',', (string)$ext['manager_roles']))) !== []);
    }

    /**
     * The campaigns this user manages (all of them for an administrator).
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public static function campaignsFor(array $user): array
    {
        return array_values(array_filter(self::listCampaigns(), static fn(array $c): bool => self::canManage((int)$c['id'], $user)));
    }

    /** Running campaigns whose database source is due again ("every N minutes"). */
    public static function refreshDueSources(): void
    {
        $rows = getDB()->query(
            "SELECT e.campaign_id FROM plg_autodial_campaign_ext e
             JOIN plg_autodial_campaigns c ON c.id = e.campaign_id
             WHERE c.status = 'running' AND e.source_type = 'db' AND e.refresh_min > 0
               AND (e.last_refresh_at IS NULL OR e.last_refresh_at < DATE_SUB(NOW(), INTERVAL e.refresh_min MINUTE))"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $id) {
            self::importFromDb((int)$id);
        }
    }

    /**
     * Reads the campaign's query and adds the rows it does not have yet.
     * Columns (any case): phone (or the first column), name, external_id, notes.
     * :campaign in the query is the campaign id.
     */
    public static function importFromDb(int $campaignId): int
    {
        $ext = self::campaignExt($campaignId);
        if ($ext['source_type'] !== 'db' || !self::hasPluginData()) {
            return 0;
        }
        $added = 0;
        $error = '';
        try {
            $rows = PluginData::query('auto-dialer', (int)$ext['db_id'], (string)$ext['query_sql'], ['campaign' => (string)$campaignId]);
            foreach ($rows as $row) {
                $row = array_change_key_case($row, CASE_LOWER);
                $phone = (string)($row['phone'] ?? $row['number'] ?? reset($row));
                if (self::addNumber($campaignId, $phone, (string)($row['name'] ?? ''), (string)($row['notes'] ?? ''), (string)($row['external_id'] ?? ''))) {
                    $added++;
                }
            }
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 255);
        }
        getDB()->prepare('UPDATE plg_autodial_campaign_ext SET last_refresh_at = NOW(), last_error = ? WHERE campaign_id = ?')
            ->execute([$error, $campaignId]);
        return $added;
    }

    /**
     * The result of a finished number as variables (webhook data, write-back template).
     *
     * @param array<string, mixed> $r numbers row joined with its ext row and campaign name
     * @return array<string, string>
     */
    public static function resultValues(array $r): array
    {
        return [
            'result' => (string)($r['call_result'] !== '' ? $r['call_result'] : strtoupper((string)$r['status'])),
            'attempts' => (string)(int)$r['attempts'],
            'duration' => (string)(int)$r['duration'],
            'amd' => (string)$r['amd_status'],
            'agent' => (string)$r['agent'],
            'called_at' => (string)($r['last_attempt_at'] ?? ''),
            'campaign' => (string)$r['campaign_id'],
            'phone' => (string)$r['phone_number'],
            'name' => (string)$r['contact_name'],
            'external_id' => (string)$r['external_id'],
        ];
    }

    /**
     * Delivers finished numbers once: the webhook event autodial.result and, when the
     * campaign has one, its write-back. A failed write-back is kept on the number and
     * shown on the page; it is not tried again on every tick. A worker stopped between
     * claiming and finishing leaves the row claimed (2): delivered at most once, never twice.
     */
    public static function reportResults(int $limit = self::REPORT_BATCH): int
    {
        $db = getDB();
        $rows = $db->query(
            "SELECT n.*, e.external_id, e.agent, c.name AS campaign_name, x.webhook, x.writeback_db_id, x.writeback_sql
             FROM plg_autodial_number_ext e
             JOIN plg_autodial_numbers n ON n.id = e.number_id
             JOIN plg_autodial_campaigns c ON c.id = e.campaign_id
             LEFT JOIN plg_autodial_campaign_ext x ON x.campaign_id = e.campaign_id
             WHERE e.reported = 0 AND n.status IN ('completed', 'failed')
             ORDER BY e.number_id ASC LIMIT " . max(1, $limit)
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Each row is claimed (reported = 2) before delivery: a second worker or the page's
        // "run now" running at the same moment skips it, so a result goes out at most once.
        $claim = $db->prepare('UPDATE plg_autodial_number_ext SET reported = 2 WHERE number_id = ? AND reported = 0');
        foreach ($rows as $r) {
            $claim->execute([(int)$r['id']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }
            $values = self::resultValues($r);
            $error = '';
            if ((int)($r['webhook'] ?? 1) === 1 && class_exists('WebhookService')) {
                WebhookService::emit('autodial.result', $values + ['campaign_name' => (string)$r['campaign_name'], 'number_id' => (int)$r['id']]);
            }
            if (!empty($r['writeback_db_id']) && (string)$r['writeback_sql'] !== '' && self::hasPluginData()) {
                try {
                    PluginData::write('auto-dialer', (int)$r['writeback_db_id'], (string)$r['writeback_sql'], $values);
                } catch (\Throwable $e) {
                    $error = mb_substr($e->getMessage(), 0, 255);
                }
            }
            $db->prepare('UPDATE plg_autodial_number_ext SET reported = 1, report_error = ? WHERE number_id = ?')->execute([$error, (int)$r['id']]);
        }
        return count($rows);
    }
}
