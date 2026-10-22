<?php
/**
 * Action handler for `/plugins/call-quality`.
 *
 * The core plugin loader `require`s this file on every render of the detail
 * page, so it must be inert unless it is a POST of one of its own forms with a
 * valid CSRF token.
 *
 * Two actions, both read-only towards the PBX:
 *   - `run`       one pass now, ignoring the five-minute clock (the same pass
 *                 the worker runs, with the same evidence and mail rules);
 *   - `test_mail` one short message to the configured address, so an
 *                 administrator can prove mail works before a real incident.
 */
require_once dirname(__DIR__) . '/CaptureState.php';
require_once dirname(__DIR__) . '/CallQualityService.php';

return static function (string $slug, array $settings): array {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return [];
    }
    if (!isset($_POST['call_quality_action'])) {
        return [];
    }
    if (function_exists('verifyCSRFToken') && !verifyCSRFToken((string) ($_POST['csrf_token'] ?? ''))) {
        return ['error' => 'The form expired. Reload the page and try again.'];
    }

    $action = (string) $_POST['call_quality_action'];

    if ($action === 'run') {
        $result = CallQualityService::runNow($settings, $slug);
        if (isset($result['error'])) {
            return ['error' => 'The pass failed: ' . (string) $result['error']];
        }
        $parts = [sprintf(
            'One pass: capture %s, %d call(s) scored, %d poor in the last %d minutes, %d new evidence row(s), %d e-mail(s).',
            (string) $result['capture'],
            (int) $result['measured'],
            (int) $result['poor'],
            CallQualityService::ALERT_WINDOW_MIN,
            (int) $result['evidence'],
            (int) $result['mails']
        )];
        foreach ((array) ($result['skipped'] ?? []) as $skipped) {
            $parts[] = 'skipped: ' . $skipped;
        }
        foreach (array_slice((array) ($result['notes'] ?? []), 0, 5) as $note) {
            $parts[] = (string) $note;
        }
        return ['message' => implode(' ', $parts)];
    }

    if ($action === 'test_mail') {
        $to = CallQualityService::notifyAddress($settings);
        if ($to === '') {
            return ['error' => 'Set a valid e-mail address in the settings first.'];
        }
        $link = '';
        try {
            $link = rtrim(\ForgotPasswordService::portalUrl(), '/') . '/plugins/' . $slug;
        } catch (\Throwable $e) {
        }
        $res = CallQualityService::sendMail(
            $to,
            'Call Quality: test message',
            "This is a test message from the Call Quality plugin.\n\n"
            . "It proves the portal's mail transport works and that this address is reachable. "
            . "Quality alerts will use the same path.\n\n" . $link,
            '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1e293b;">'
            . '<p style="margin:0 0 14px 0;font-weight:600;">Call Quality: test message</p>'
            . '<p style="margin:0 0 14px 0;">This is a test message from the Call Quality plugin. It proves the portal\'s mail transport works and that this address is reachable. Quality alerts will use the same path.</p>'
            . ($link !== '' ? '<p style="margin:0;"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>' : '')
            . '</div>'
        );
        return $res['sent']
            ? ['message' => 'Test message sent to ' . $to . '.']
            : ['error' => 'Mail failed: ' . (string) $res['error']];
    }

    return [];
};
