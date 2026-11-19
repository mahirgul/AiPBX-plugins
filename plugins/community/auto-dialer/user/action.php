<?php
/**
 * /apps/auto-dialer forms (the core has checked CSRF): a campaign's manager may start or pause it,
 * add or reset numbers, read its database source again and download its results.
 */
require_once dirname(__DIR__) . '/AutoDialerService.php';

$adminAction = require dirname(__DIR__) . '/admin/action.php';

return static function (string $slug, array $settings, array $user) use ($adminAction): array {
    $action = (string)($_POST['autodial_action'] ?? '');
    if (!in_array($action, ['set_status', 'add_numbers', 'reset_numbers', 'import_db', 'export_csv'], true)) {
        return [];
    }
    if (!AutoDialerService::canManage((int)($_POST['campaign_id'] ?? 0), $user)) {
        return ['error' => t('auth.forbidden')];
    }
    return $adminAction($slug, $settings);
};
