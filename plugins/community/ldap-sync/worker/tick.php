<?php
/**
 * Called by aipbx-webhook-worker every few seconds; reads the directory once
 * per interval. The result (or the error) is kept for the plugin page.
 */
require_once dirname(__DIR__) . '/lib/LdapSync.php';

return static function (string $slug, array $settings): void {
    if (trim((string) ($settings['host'] ?? '')) === '') {
        return;
    }
    $db = getDB();
    $lock = "SHA2(CONCAT(DATABASE(), ':aipbx:ldap-sync'), 256)";
    if ((int) $db->query("SELECT GET_LOCK($lock, 0)")->fetchColumn() !== 1) {
        return;
    }
    try {
        $every = max(5, (int) ($settings['interval_minutes'] ?? 60)) * 60;
        $state = PluginManager::kvGet($slug, 'state', 'last_run')['value'] ?? [];
        $now = !empty(PluginManager::kvGet($slug, 'state', 'run_now')['value']);
        if (!$now && time() - (int) ($state['at'] ?? 0) < $every) {
            return;
        }
        PluginManager::kvDelete($slug, 'state', 'run_now');
        try {
            $result = LdapSync::run($db, $settings);
            PluginManager::kvSet($slug, 'state', 'last_run', ['at' => time(), 'ok' => true] + $result);
        } catch (Throwable $e) {
            PluginManager::kvSet($slug, 'state', 'last_run', ['at' => time(), 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK($lock)");
    }
};
