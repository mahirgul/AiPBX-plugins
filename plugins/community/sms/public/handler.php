<?php
/**
 * Public endpoints, no sign-in:
 *   /api/plugins/sms/<provider>/<key>   a provider's webhook; the key is the plugin's secret, and the
 *                                       provider's own signature is checked too where it has one.
 *   /api/plugins/sms/media/<id>/<token> a stored picture (providers fetch outgoing MMS from here).
 * Nothing here throws: the core turns five errors in a row into Safe Mode, and a stranger must not
 * be able to switch the plugin off with bad requests.
 */
require_once dirname(__DIR__) . '/lib/Sms.php';

return static function (string $subPath, array $settings): void {
    $parts = explode('/', trim($subPath, '/'));
    $answer = static function (int $code, string $type, string $body): void {
        http_response_code($code);
        header('Content-Type: ' . $type);
        echo $body;
    };
    try {
        if ($parts[0] === 'media' && count($parts) === 3 && ctype_digit($parts[1]) && preg_match('/^[a-f0-9]{32}$/', $parts[2])) {
            $q = getDB()->prepare('SELECT content_type, name, data, token FROM plg_sms_media WHERE id = ?');
            $q->execute([(int) $parts[1]]);
            $m = $q->fetch(PDO::FETCH_ASSOC);
            if (!$m || !hash_equals((string) $m['token'], $parts[2])) {
                $answer(404, 'text/plain', 'not found');
                return;
            }
            // Pictures, sound and video show in the page; anything else only downloads, and nothing runs.
            $inline = preg_match('#^(image/(png|jpeg|gif|webp|bmp)|audio/[a-z0-9.+-]+|video/[a-z0-9.+-]+)$#', (string) $m['content_type']) === 1;
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; sandbox");
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $m['name'] ?: 'file') . '"');
            $answer(200, $inline ? (string) $m['content_type'] : 'application/octet-stream', (string) $m['data']);
            return;
        }

        $provider = count($parts) === 2 ? Sms::provider($parts[0], $settings) : null;
        if ($provider === null || !hash_equals(Sms::webhookKey(), $parts[1])) {
            $answer(404, 'text/plain', 'not found');
            return;
        }
        $headers = [];
        foreach (function_exists('getallheaders') ? getallheaders() : [] as $name => $value) {
            $headers[strtolower((string) $name)] = (string) $value;
        }
        $request = [
            'url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'headers' => $headers,
            'body' => (string) file_get_contents('php://input'),
            'post' => $_POST,
            'query' => $_GET,
        ];
        if (!$provider->verify($request)) {
            $answer(403, 'text/plain', 'signature check failed');
            return;
        }
        Sms::receive($provider, $request);
        [$type, $body] = $provider->reply();
        $answer(200, $type, $body);
    } catch (\Throwable $e) {
        error_log('sms plugin: ' . $e->getMessage());
        $answer(500, 'text/plain', 'error');
    }
};
