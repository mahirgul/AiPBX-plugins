<?php

declare(strict_types=1);

require_once __DIR__ . '/Provider.php';
foreach (glob(__DIR__ . '/providers/*.php') ?: [] as $providerFile) {
    require_once $providerFile;
}

/**
 * What every provider shares: numbers and who may use them, storing messages and their pictures,
 * sending and receiving. A conversation is one of our numbers with one peer.
 */
final class Sms
{
    public const SLUG = 'sms';
    public const MAX_MEDIA = 3;
    public const MAX_MEDIA_BYTES = 5 * 1024 * 1024;

    /** @return array<string, class-string<SmsProvider>> id => class, in the order shown */
    public static function providers(): array
    {
        $out = [];
        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, SmsProvider::class)) {
                $out[$class::id()] = $class;
            }
        }
        ksort($out);
        return $out;
    }

    public static function provider(string $id, array $settings): ?SmsProvider
    {
        $class = self::providers()[$id] ?? null;
        return $class === null ? null : new $class($settings);
    }

    /** The secret part of every webhook address; made once. */
    public static function webhookKey(): string
    {
        $row = PluginManager::kvGet(self::SLUG, 'state', 'webhook_key');
        $key = (string) ($row['value'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
            $key = bin2hex(random_bytes(16));
            PluginManager::kvSet(self::SLUG, 'state', 'webhook_key', $key);
        }
        return $key;
    }

    /** https://<portal> as providers reach it: the portal's domain, else the address this request came to. */
    public static function baseUrl(): string
    {
        $host = function_exists('portalEnv') ? trim((string) portalEnv('PORTAL_DOMAIN', '')) : '';
        return 'https://' . ($host !== '' ? $host : (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }

    public static function webhookUrl(string $provider): string
    {
        return self::baseUrl() . '/api/plugins/' . self::SLUG . '/' . $provider . '/' . self::webhookKey();
    }

    // --- numbers and access ---

    /** @return list<array<string, mixed>> every number, with user_ids */
    public static function numbers(): array
    {
        $rows = getDB()->query('SELECT n.*, GROUP_CONCAT(a.user_id) AS user_ids FROM plg_sms_numbers n
            LEFT JOIN plg_sms_access a ON a.number_id = n.id GROUP BY n.id ORDER BY n.number')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['user_ids'] = array_map('intval', array_filter(explode(',', (string) $r['user_ids'])));
        }
        return $rows;
    }

    /** The numbers this user reads: those given to them; an administrator reads all. */
    public static function numbersFor(array $user): array
    {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        return array_values(array_filter(self::numbers(), static fn(array $n): bool => $isAdmin || in_array((int) ($user['id'] ?? 0), $n['user_ids'], true)));
    }

    public static function saveNumber(int $id, string $number, string $provider, string $label, array $userIds): void
    {
        $number = SmsProvider::e164($number);
        if (!preg_match('/^\+[1-9][0-9]{6,14}$/', $number)) {
            throw new \InvalidArgumentException('The number must be in international form, e.g. +15551234567.');
        }
        if (!isset(self::providers()[$provider])) {
            throw new \InvalidArgumentException('Unknown provider.');
        }
        $db = getDB();
        if ($id === 0) {
            // The same number again changes the one listed.
            $same = $db->prepare('SELECT id FROM plg_sms_numbers WHERE number = ?');
            $same->execute([$number]);
            $id = (int) $same->fetchColumn();
        }
        $db->beginTransaction();
        try {
            if ($id > 0) {
                $db->prepare('UPDATE plg_sms_numbers SET number = ?, provider = ?, label = ? WHERE id = ?')->execute([$number, $provider, mb_substr($label, 0, 60), $id]);
            } else {
                $db->prepare('INSERT INTO plg_sms_numbers (number, provider, label) VALUES (?, ?, ?)')->execute([$number, $provider, mb_substr($label, 0, 60)]);
                $id = (int) $db->lastInsertId();
            }
            $db->prepare('DELETE FROM plg_sms_access WHERE number_id = ?')->execute([$id]);
            $add = $db->prepare('INSERT INTO plg_sms_access (number_id, user_id) SELECT ?, id FROM sys_users WHERE id = ?');
            foreach (array_unique(array_map('intval', $userIds)) as $uid) {
                $add->execute([$id, $uid]);
            }
            $db->commit();
        } catch (\PDOException $e) {
            $db->rollBack();
            throw new \InvalidArgumentException(str_contains($e->getMessage(), 'Duplicate') ? 'This number is already listed.' : $e->getMessage());
        }
    }

    /** A number and its messages go together. */
    public static function deleteNumber(int $id): void
    {
        $db = getDB();
        $db->prepare('DELETE m FROM plg_sms_media m JOIN plg_sms_messages s ON s.id = m.message_id WHERE s.number_id = ?')->execute([$id]);
        foreach (['plg_sms_messages', 'plg_sms_access'] as $table) {
            $db->prepare("DELETE FROM {$table} WHERE number_id = ?")->execute([$id]);
        }
        $db->prepare('DELETE FROM plg_sms_numbers WHERE id = ?')->execute([$id]);
    }

    // --- receiving ---

    /**
     * Stores the messages of a webhook request that the provider proved it sent. Returns how many
     * were new; a message for a number not listed here, or one seen before, is skipped.
     */
    public static function receive(SmsProvider $provider, array $request): int
    {
        $db = getDB();
        $find = $db->prepare('SELECT id FROM plg_sms_numbers WHERE number = ? AND provider = ?');
        $seen = $db->prepare("SELECT 1 FROM plg_sms_messages WHERE provider_id = ? AND direction = 'in' LIMIT 1");
        $new = 0;
        foreach ($provider->inbound($request) as $m) {
            $find->execute([$m['to'], $provider::id()]);
            $numberId = (int) $find->fetchColumn();
            if ($numberId === 0 || $m['from'] === '') {
                continue;
            }
            if ($m['id'] !== '') {
                $seen->execute([$m['id']]);
                if ($seen->fetchColumn()) {
                    continue; // providers retry; keep one copy
                }
            }
            $db->prepare("INSERT INTO plg_sms_messages (number_id, peer, direction, body, status, provider_id) VALUES (?, ?, 'in', ?, 'received', ?)")
                ->execute([$numberId, $m['from'], mb_substr($m['text'], 0, 5000), mb_substr($m['id'], 0, 100)]);
            $messageId = (int) $db->lastInsertId();
            foreach (array_slice($m['media'], 0, 10) as $url) {
                self::fetchMedia($provider, $messageId, $url);
            }
            $new++;
        }
        return $new;
    }

    /** Downloads a picture the provider links to; one that fails is noted in the text instead. */
    private static function fetchMedia(SmsProvider $provider, int $messageId, string $url): void
    {
        try {
            if (!str_starts_with($url, 'https://')) {
                throw new \RuntimeException('not https');
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => $provider->mediaHeaders($url), CURLOPT_MAXFILESIZE => self::MAX_MEDIA_BYTES]);
            $data = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            if (!is_string($data) || $code !== 200 || strlen($data) > self::MAX_MEDIA_BYTES) {
                throw new \RuntimeException("HTTP {$code}");
            }
            self::storeMedia($messageId, $type ?: 'application/octet-stream', basename((string) parse_url($url, PHP_URL_PATH)), $data);
        } catch (\Throwable $e) {
            getDB()->prepare("UPDATE plg_sms_messages SET error = ? WHERE id = ?")->execute([mb_substr('Attachment not fetched: ' . $e->getMessage(), 0, 255), $messageId]);
        }
    }

    private static function storeMedia(int $messageId, string $type, string $name, string $data): void
    {
        getDB()->prepare('INSERT INTO plg_sms_media (message_id, content_type, name, data, token) VALUES (?, ?, ?, ?, ?)')
            ->execute([$messageId, mb_substr(strtolower(trim(explode(';', $type)[0])), 0, 100), mb_substr($name, 0, 120), $data, bin2hex(random_bytes(16))]);
    }

    /** The public link of a stored picture: the provider fetches it for an outgoing MMS, the pages show it. */
    public static function mediaUrl(array $media, bool $absolute = false): string
    {
        return ($absolute ? self::baseUrl() : '') . '/api/plugins/' . self::SLUG . '/media/' . (int) $media['id'] . '/' . $media['token'];
    }

    // --- sending ---

    /**
     * Sends a message from one of our numbers, with pictures from an upload ($_FILES-style list of
     * name, type, tmp_name, size). The message is stored first, so a failure stays visible with its reason.
     */
    public static function send(array $number, string $peer, string $text, array $files, int $userId, array $settings): int
    {
        $peer = SmsProvider::e164($peer);
        $text = trim($text);
        if (!preg_match('/^\+[1-9][0-9]{6,14}$/', $peer)) {
            throw new \InvalidArgumentException('Write the number in international form, e.g. +15551234567.');
        }
        if ($text === '' && $files === []) {
            throw new \InvalidArgumentException('Write a message or add a picture.');
        }
        if (count($files) > self::MAX_MEDIA) {
            throw new \InvalidArgumentException('At most ' . self::MAX_MEDIA . ' pictures per message.');
        }
        $provider = self::provider((string) $number['provider'], $settings);
        if ($provider === null || !$provider->configured()) {
            throw new \InvalidArgumentException('The provider of this number is not set up on the plugin page.');
        }
        foreach ($files as $f) {
            if ((int) $f['size'] > self::MAX_MEDIA_BYTES || !is_uploaded_file((string) $f['tmp_name'])) {
                throw new \InvalidArgumentException('A picture is larger than 5 MB.');
            }
        }
        $db = getDB();
        $db->prepare("INSERT INTO plg_sms_messages (number_id, peer, direction, body, status, user_id, is_read) VALUES (?, ?, 'out', ?, 'sending', ?, 1)")
            ->execute([(int) $number['id'], $peer, mb_substr($text, 0, 5000), $userId]);
        $messageId = (int) $db->lastInsertId();
        foreach ($files as $f) {
            $type = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $f['tmp_name']) ?: 'application/octet-stream';
            self::storeMedia($messageId, $type, basename((string) $f['name']), (string) file_get_contents((string) $f['tmp_name']));
        }
        $media = self::media([$messageId])[$messageId] ?? [];
        try {
            $id = $provider->send((string) $number['number'], $peer, $text, array_map(static fn(array $m): string => self::mediaUrl($m, true), $media));
            $db->prepare("UPDATE plg_sms_messages SET status = 'sent', provider_id = ? WHERE id = ?")->execute([mb_substr($id, 0, 100), $messageId]);
        } catch (\Throwable $e) {
            $db->prepare("UPDATE plg_sms_messages SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 255), $messageId]);
            throw new \RuntimeException('Not sent: ' . $e->getMessage());
        }
        return $messageId;
    }

    // --- reading ---

    /** Conversations of these numbers, newest first: number_id, peer, last text, time, unread count. */
    public static function threads(array $numberIds): array
    {
        if ($numberIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $numberIds));
        return getDB()->query("SELECT m.number_id, m.peer, m.body, m.direction, m.created_at, t.unread
            FROM (SELECT number_id, peer, MAX(id) AS last_id, SUM(direction = 'in' AND is_read = 0) AS unread
                  FROM plg_sms_messages WHERE number_id IN ({$in}) GROUP BY number_id, peer) t
            JOIN plg_sms_messages m ON m.id = t.last_id ORDER BY m.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The messages of one conversation, oldest first, with their pictures; marks them read. */
    public static function thread(int $numberId, string $peer): array
    {
        $db = getDB();
        $q = $db->prepare('SELECT * FROM (SELECT * FROM plg_sms_messages WHERE number_id = ? AND peer = ? ORDER BY id DESC LIMIT 300) x ORDER BY id');
        $q->execute([$numberId, $peer]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $media = self::media(array_map(static fn(array $r): int => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r['media'] = $media[(int) $r['id']] ?? [];
        }
        $db->prepare("UPDATE plg_sms_messages SET is_read = 1 WHERE number_id = ? AND peer = ? AND direction = 'in' AND is_read = 0")->execute([$numberId, $peer]);
        return $rows;
    }

    /** @return array<int, list<array<string, mixed>>> message id => its pictures (without the data) */
    public static function media(array $messageIds): array
    {
        $ids = implode(',', array_map('intval', $messageIds));
        if ($ids === '') {
            return [];
        }
        $out = [];
        foreach (getDB()->query("SELECT id, message_id, content_type, name, token FROM plg_sms_media WHERE message_id IN ({$ids}) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $out[(int) $m['message_id']][] = $m;
        }
        return $out;
    }

    /** The newest message id of these numbers, for the page to see something new arrived. */
    public static function lastId(array $numberIds): int
    {
        if ($numberIds === []) {
            return 0;
        }
        return (int) getDB()->query('SELECT MAX(id) FROM plg_sms_messages WHERE number_id IN (' . implode(',', array_map('intval', $numberIds)) . ')')->fetchColumn();
    }
}
