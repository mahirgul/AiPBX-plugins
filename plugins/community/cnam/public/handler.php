<?php

declare(strict_types=1);

/**
 * Public CNAM Lookup endpoint.
 * Called by Asterisk via CURL() in [sub-cnam-lookup]:
 *   GET /api/plugins/cnam/lookup?num=<phone_number>
 * Or directly:
 *   GET /api/plugins/cnam/lookup/<phone_number>
 *
 * Plain text response is returned for Asterisk dialplan compatibility,
 * or JSON if requested via format=json or Accept: application/json.
 */
require_once dirname(__DIR__) . '/lib/Cnam.php';

return static function (string $subPath, array $settings): void {
    // Do not trust forwarded headers; only Asterisk on this host may use this endpoint.
    $token = PluginManager::getSetting('cnam', '_lookup_token');
    // CURLOPT(userpwd) is a scalar option and can be cleared; httpheader accumulates in Asterisk.
    $provided = (string)($_SERVER['HTTP_X_CNAM_TOKEN'] ?? ''); // Legacy/custom client compatibility.
    if (($_SERVER['PHP_AUTH_USER'] ?? '') === 'cnam') {
        $provided = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
    } elseif (preg_match('/^Basic\s+(.+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $auth)) {
        $credentials = base64_decode($auth[1], true);
        if (is_string($credentials) && str_starts_with($credentials, 'cnam:')) {
            $provided = substr($credentials, 5);
        }
    }
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        || !preg_match('/^[a-f0-9]{64}$/D', $token) || !hash_equals($token, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        return;
    }
    $parts = explode('/', trim($subPath, '/'));
    $action = $parts[0] ?? '';

    if ($action !== 'lookup' && $action !== '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '';
        return;
    }

    $rawNumber = (string) ($parts[1] ?? $_GET['num'] ?? $_GET['n'] ?? $_GET['number'] ?? '');
    $isJson = (isset($_GET['format']) && $_GET['format'] === 'json')
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    try {
        $db = getDB();
        $result = Cnam::resolve($db, $settings, $rawNumber);

        if ($isJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'    => true,
                'number'     => $rawNumber,
                'name'       => $result['name'],
                'source'     => $result['source'],
                'latency_ms' => $result['latency_ms'],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo (string) ($result['name'] ?? '');
    } catch (\Throwable $e) {
        error_log('CNAM lookup error: ' . $e->getMessage());
        if ($isJson) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'CNAM lookup failed']);
            return;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo '';
    }
};
