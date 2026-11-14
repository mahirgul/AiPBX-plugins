<?php
/**
 * "Test the connection" reads the directory without changing the phonebook;
 * "Read now" asks the worker to run within a few seconds.
 */
require_once dirname(__DIR__) . '/lib/LdapSync.php';

return static function (string $slug, array $settings): array {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['ldap_sync_action'])) {
        return [];
    }
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return ['error' => t('common.invalid_csrf')];
    }
    if ($_POST['ldap_sync_action'] === 'test') {
        try {
            $found = LdapSync::fetch($settings, 2000, true);
            $first = array_slice(array_column($found, 'display_name'), 0, 5);
            return ['message' => sprintf('Connected. %s contact(s) with a number found%s.', (count($found) >= 2000 ? 'at least ' : '') . count($found),
                $first ? ', e.g. ' . implode(', ', $first) : '')];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
    if ($_POST['ldap_sync_action'] === 'run_now') {
        PluginManager::kvSet($slug, 'state', 'run_now', true);
        return ['message' => 'The directory is read within a few seconds; reload this page to see the result.'];
    }
    return ['error' => 'Unknown action.'];
};
