<?php

declare(strict_types=1);

/**
 * Handles admin form submissions on /plugins/cnam.
 */
require_once dirname(__DIR__) . '/lib/Cnam.php';

return static function (string $slug, array $settings): array {
    $action = (string) ($_POST['cnam_action'] ?? '');
    if ($action === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [];
    }
    if (!verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        return ['error' => t('common.invalid_csrf', 'Invalid CSRF token')];
    }

    $db = getDB();

    try {
        switch ($action) {
            case 'clear_cache':
                $count = Cnam::clearAllCache($db);
                return ['message' => sprintf(t('cnam.cache_cleared', 'Cache cleared (%d entries removed).'), $count)];

            case 'purge_expired':
                $count = Cnam::purgeExpired($db);
                return ['message' => sprintf(t('cnam.expired_purged', 'Expired cache entries purged (%d removed).'), $count)];

            case 'test_lookup':
                $testNumber = trim((string) ($_POST['test_number'] ?? ''));
                if ($testNumber === '') {
                    return ['error' => t('cnam.err_number_empty', 'Please enter a phone number to test.')];
                }
                $res = Cnam::resolve($db, $settings, $testNumber);
                if ($res['name'] !== null) {
                    return [
                        'message' => sprintf(
                            t('cnam.lookup_success', 'Lookup result for "%s": "%s" (source: %s, latency: %d ms)'),
                            htmlspecialchars($testNumber, ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars($res['name'], ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars($res['source'], ENT_QUOTES, 'UTF-8'),
                            $res['latency_ms']
                        ),
                    ];
                }
                return [
                    'message' => sprintf(
                        t('cnam.lookup_empty', 'No caller name found for "%s" (source: %s, latency: %d ms).'),
                        htmlspecialchars($testNumber, ENT_QUOTES, 'UTF-8'),
                        htmlspecialchars($res['source'], ENT_QUOTES, 'UTF-8'),
                        $res['latency_ms']
                    ),
                ];
        }
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }

    return [];
};
