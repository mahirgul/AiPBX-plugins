<?php
/**
 * Administrator forms on /plugins/sms: a number with its provider and users, deleting one, and a new
 * secret webhook key.
 */
require_once dirname(__DIR__) . '/lib/Sms.php';

return static function (string $slug, array $settings): array {
    $action = (string) ($_POST['sms_action'] ?? '');
    if ($action === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [];
    }
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        return ['error' => t('common.invalid_csrf')];
    }
    try {
        switch ($action) {
            case 'save_number':
                Sms::saveNumber((int) ($_POST['number_id'] ?? 0), (string) ($_POST['number'] ?? ''), (string) ($_POST['provider'] ?? ''),
                    trim((string) ($_POST['label'] ?? '')), (array) ($_POST['user_ids'] ?? []));
                return ['message' => 'Number saved.'];
            case 'delete_number':
                Sms::deleteNumber((int) ($_POST['number_id'] ?? 0));
                return ['message' => 'Number and its messages deleted.'];
            case 'new_key':
                PluginManager::kvDelete(Sms::SLUG, 'state', 'webhook_key');
                Sms::webhookKey();
                return ['message' => 'New webhook key made. Give the new addresses to your providers; the old ones no longer work.'];
        }
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    return [];
};
