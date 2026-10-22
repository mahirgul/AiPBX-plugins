<?php
/**
 * Worker hook: needs "worker": true in plugin.json. aipbx-webhook-worker calls it
 * every few seconds (about 2 s when idle), so a plugin that wants a slower pace
 * keeps its own clock, here in the key-value store (sys_plugin_kv).
 *
 * Do the real work in small steps; a slow tick delays webhooks and the other
 * plugins' workers.
 */
return static function (string $slug, array $settings): void {
    $every = max(10, (int)($settings['tick_seconds'] ?? 60));
    $last = PluginManager::kvGet($slug, 'state', 'last_tick');
    $lastAt = (int)($last['value']['at'] ?? 0);
    if (time() - $lastAt < $every) {
        return;
    }
    $count = (int)($last['value']['count'] ?? 0) + 1;
    PluginManager::kvSet($slug, 'state', 'last_tick', ['at' => time(), 'count' => $count]);
};
