<?php
/**
 * Admin action hook: runs on every request to /plugins/sample-plugin (GET and
 * POST), before admin/page.php is rendered and after the core has saved the
 * settings form. The core checks CSRF only for its own settings form, so check
 * it here for your own forms. Return ['message' => ...] or ['error' => ...]
 * to show a notice, or [] for nothing. A download can send its own headers
 * and exit here, before any HTML.
 */
return static function (string $slug, array $settings): array {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['sample_action'])) {
        return [];
    }
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        return ['error' => t('common.invalid_csrf')];
    }

    if ($_POST['sample_action'] === 'clear_events') {
        $deleted = getDB()->exec('DELETE FROM plg_sample_events');
        return ['message' => sprintf('%d recorded event(s) deleted.', (int)$deleted)];
    }
    if ($_POST['sample_action'] === 'reset_tick') {
        PluginManager::kvDelete($slug, 'state', 'last_tick');
        return ['message' => 'Worker counter reset.'];
    }
    return ['error' => 'Unknown action.'];
};
