<?php

declare(strict_types=1);

require_once __DIR__ . '/Provider.php';
require_once __DIR__ . '/Media.php';
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
        foreach (['plg_sms_messages', 'plg_sms_access', 'plg_sms_contacts'] as $table) {
            $db->prepare("DELETE FROM {$table} WHERE number_id = ?")->execute([$id]);
        }
        $db->prepare('DELETE FROM plg_sms_numbers WHERE id = ?')->execute([$id]);
    }

    // --- receiving ---

    /**
     * Stores the messages of a webhook request that the provider proved it sent. Returns how many
     * were new; a message for a number not listed here, or one seen before, is skipped.
     */
    public static function receive(SmsProvider $provider, array $request, array $settings = []): int
    {
        $db = getDB();
        $find = $db->prepare('SELECT id FROM plg_sms_numbers WHERE number = ? AND provider = ? FOR UPDATE');
        $seen = $db->prepare("SELECT 1 FROM plg_sms_messages WHERE number_id = ? AND provider_id = ? AND direction = 'in' LIMIT 1 FOR UPDATE");
        $new = 0;
        foreach ($provider->inbound($request) as $m) {
            // Serialize receipt for this destination using its existing InnoDB row. No DDL is
            // needed on upgrade, and concurrent retries cannot both pass the duplicate check.
            $db->beginTransaction();
            try {
                $find->execute([$m['to'], $provider::id()]);
                $numberId = (int) $find->fetchColumn();
                if ($numberId === 0 || $m['from'] === '') {
                    $db->rollBack();
                    continue;
                }
                $providerId = (string) $m['id'];
                if (mb_strlen($providerId) > 100) {
                    $providerId = 'sha256:' . hash('sha256', $providerId);
                }
                if ($providerId !== '') {
                    $seen->execute([$numberId, $providerId]);
                    if ($seen->fetchColumn()) {
                        $db->rollBack();
                        continue;
                    }
                }
                $db->prepare("INSERT INTO plg_sms_messages (number_id, peer, direction, body, status, provider_id) VALUES (?, ?, 'in', ?, 'received', ?)")
                    ->execute([$numberId, $m['from'], mb_substr($m['text'], 0, 5000), $providerId]);
                $messageId = (int) $db->lastInsertId();
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            foreach (array_slice($m['media'], 0, 10) as $url) {
                self::fetchMedia($provider, $messageId, $url);
            }
            if (($settings['email_notify'] ?? '1') === '1') {
                self::notify($numberId, $messageId, $m['from'], $m['text'], count($m['media']));
            }
            $new++;
        }
        return $new;
    }

    /**
     * E-mails the users of a number about a message that arrived, in the portal's mail look. One mail
     * per conversation in 10 minutes, so a quick exchange does not fill the inbox; a failed mail is
     * only logged.
     */
    private static function notify(int $numberId, int $messageId, string $from, string $text, int $media): void
    {
        try {
            $db = getDB();
            $recent = $db->prepare("SELECT COUNT(*) FROM plg_sms_messages WHERE number_id = ? AND peer = ? AND direction = 'in' AND id <> ? AND created_at > NOW() - INTERVAL 10 MINUTE");
            $recent->execute([$numberId, $from, $messageId]);
            if ((int) $recent->fetchColumn() > 0) {
                return;
            }
            // The core's mail service, next to PluginManager (src/core → src/services).
            if (!class_exists('MailTemplateService')) {
                require_once dirname((string) (new \ReflectionClass('PluginManager'))->getFileName(), 2) . '/services/MailTemplateService.php';
            }
            $num = $db->prepare('SELECT number, label FROM plg_sms_numbers WHERE id = ?');
            $num->execute([$numberId]);
            $n = $num->fetch(PDO::FETCH_ASSOC) ?: ['number' => '', 'label' => ''];
            $users = $db->prepare("SELECT u.email FROM plg_sms_access a JOIN sys_users u ON u.id = a.user_id WHERE a.number_id = ? AND u.email <> ''");
            $users->execute([$numberId]);
            $name = self::contacts($numberId)[$from] ?? '';
            $vars = [
                'peer' => $name !== '' ? "{$name} ({$from})" : $from,
                'number' => trim($n['number'] . ($n['label'] !== '' ? ' · ' . $n['label'] : '')),
                'text' => mb_strimwidth($text, 0, 500, '…') . ($media > 0 ? " [{$media} picture(s)]" : ''),
                'link' => self::baseUrl() . '/apps/sms?' . http_build_query(['n' => $numberId, 'peer' => $from]),
            ];
            $template = ['subject' => 'New message from {peer}',
                'body' => '<p><strong>{peer}</strong> wrote to {number}:</p><blockquote>{text}</blockquote><p><a href="{link}">Open the conversation</a></p>'];
            [$fromAddress, $fromName] = MailTemplateService::sender('portal');
            foreach ($users->fetchAll(PDO::FETCH_COLUMN) as $to) {
                $mail = MailTemplateService::render('sms', 'en', $vars, [], $template);
                [$headers, $body] = MailTemplateService::buildMime($mail, $fromAddress, $fromName);
                if (!@mail((string) $to, mb_encode_mimeheader($mail['subject'], 'UTF-8'), $body, $headers, '-f ' . $fromAddress)) {
                    error_log("sms plugin: mail to {$to} failed");
                }
            }
        } catch (\Throwable $e) {
            error_log('sms plugin: notify: ' . $e->getMessage());
        }
    }

    // --- contacts ---

    /** @return array<string, string> phone => name, for one number */
    public static function contacts(int $numberId): array
    {
        $q = getDB()->prepare('SELECT phone, name FROM plg_sms_contacts WHERE number_id = ? ORDER BY name');
        $q->execute([$numberId]);
        return $q->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    /** Names a peer for everyone on this number; an empty name removes it. */
    public static function saveContact(int $numberId, string $phone, string $name): void
    {
        $phone = SmsProvider::e164($phone);
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
        if (!preg_match('/^\+[1-9][0-9]{6,14}$/', $phone)) {
            throw new \InvalidArgumentException('Write the number in international form, e.g. +15551234567.');
        }
        $db = getDB();
        if ($name === '') {
            $db->prepare('DELETE FROM plg_sms_contacts WHERE number_id = ? AND phone = ?')->execute([$numberId, $phone]);
            return;
        }
        $db->prepare('INSERT INTO plg_sms_contacts (number_id, phone, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)')
            ->execute([$numberId, $phone, mb_substr($name, 0, 80)]);
    }

    /** Downloads a picture the provider links to; one that fails is noted in the text instead. */
    private static function fetchMedia(SmsProvider $provider, int $messageId, string $url): void
    {
        try {
            [$type, $data] = SmsMedia::download($provider, $url, self::MAX_MEDIA_BYTES);
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
