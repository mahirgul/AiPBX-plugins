<?php
/**
 * Loopback Public Endpoint Handler (`/api/plugins/auto-dialer/callback`)
 * Invoked by Asterisk `CURL(...)` during outbound campaign calls to report
 * dial failures, AMD results (`HUMAN`/`MACHINE`/`NOTSURE`), and call duration.
 */
require_once dirname(__DIR__) . '/AutoDialerService.php';

return static function (string $subPath, array $settings): void {
    header('Content-Type: application/json; charset=utf-8');

    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Loopback access only']);
        return;
    }

    $expected = PluginManager::getSetting('auto-dialer', '_callback_token');
    // CURLOPT(userpwd) is a scalar option and can be cleared; httpheader accumulates in Asterisk.
    $provided = (string)($_SERVER['HTTP_X_AUTODIALER_TOKEN'] ?? ''); // Legacy/custom client compatibility.
    if (($_SERVER['PHP_AUTH_USER'] ?? '') === 'autodialer') {
        $provided = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
    } elseif (preg_match('/^Basic\s+(.+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $auth)) {
        $credentials = base64_decode($auth[1], true);
        if (is_string($credentials) && str_starts_with($credentials, 'autodialer:')) {
            $provided = substr($credentials, 11);
        }
    }
    if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid callback token']);
        return;
    }

    $params = array_merge($_GET, $_POST);
    $res = AutoDialerService::handleCallback($params);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
};
