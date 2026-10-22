<?php
/**
 * Public endpoint: needs "public_endpoints": true in plugin.json and serves
 * /api/plugins/sample-plugin/<subPath>. There is NO sign-in on this URL:
 * anyone who can reach the portal can call it. Check the caller yourself
 * (a token in a setting, a signature, or loopback only as below) and never
 * return data that is not meant to be public. Loopback only is not enough when
 * a reverse proxy on the same host forwards outside requests: use a token then.
 *
 *   curl -k https://<pbx>/api/plugins/sample-plugin/ping
 *   curl http://127.0.0.1/api/plugins/sample-plugin/status   (on the PBX itself)
 *
 * Asterisk can call it from the dialplan with CURL(http://127.0.0.1/api/plugins/...);
 * plain HTTP is allowed only from 127.0.0.1, everyone else is sent to HTTPS.
 */
return static function (string $subPath, array $settings): void {
    $slug = basename(dirname(__DIR__)); // the plugin's folder name is its slug
    header('Content-Type: application/json; charset=utf-8');

    if ($subPath === 'ping') {
        echo json_encode(['ok' => true, 'greeting' => (string)($settings['greeting'] ?? '')]);
        return;
    }

    if ($subPath === 'status') {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'loopback only']);
            return;
        }
        $last = PluginManager::kvGet($slug, 'state', 'last_tick');
        echo json_encode(['ok' => true, 'last_tick' => $last['value'] ?? null]);
        return;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unknown path']);
};
