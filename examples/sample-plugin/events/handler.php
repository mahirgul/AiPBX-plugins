<?php
/**
 * Event hook: called by aipbx-webhook-worker for every event listed under
 * hooks.events in plugin.json (here only call.ended), shortly after it happened.
 *
 * $envelope is the same JSON the outbound webhooks send:
 * ['id' => ..., 'event' => 'call.ended', 'occurred_at' => ..., 'data' => [...]].
 * $settings holds this plugin's settings, secrets already decrypted.
 *
 * Keep it short: the worker delivers events one after the other. An exception
 * is caught and written to the audit log; five in a row disable the plugin
 * (Safe Mode).
 */
return static function (string $eventName, array $envelope, array $settings): void {
    if (($settings['record_events'] ?? '1') !== '1') {
        return;
    }
    $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
    $summary = sprintf(
        '%s -> %s (%s)',
        (string)($data['src'] ?? '') ?: '-',
        (string)($data['dst'] ?? '') ?: '-',
        (string)($data['disposition'] ?? '') ?: '-'
    );

    $db = getDB();
    $db->prepare('INSERT INTO plg_sample_events (event_name, summary) VALUES (?, ?)')
        ->execute([$eventName, mb_substr($summary, 0, 255)]);
    // Keep the table small: only the last 50 rows.
    $db->exec('DELETE FROM plg_sample_events WHERE id <= (SELECT id FROM (SELECT id FROM plg_sample_events ORDER BY id DESC LIMIT 1 OFFSET 50) AS t)');
};
