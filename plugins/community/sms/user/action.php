<?php
/**
 * Messages page forms (the core has checked CSRF): send a message, and "poll" for the page's script
 * to see whether something new arrived.
 */
require_once dirname(__DIR__) . '/lib/Sms.php';

return static function (string $slug, array $settings, array $user): array {
    $numbers = [];
    foreach (Sms::numbersFor($user) as $n) {
        $numbers[(int) $n['id']] = $n;
    }
    $action = (string) ($_POST['sms_action'] ?? '');
    if ($action === 'poll') {
        return ['json' => ['last' => Sms::lastId(array_keys($numbers))]];
    }
    if ($action !== 'send') {
        return [];
    }
    $number = $numbers[(int) ($_POST['number_id'] ?? 0)] ?? null;
    if ($number === null) {
        return ['error' => 'This number is not yours.'];
    }
    $files = [];
    $up = $_FILES['media'] ?? null;
    foreach (is_array($up['name'] ?? null) ? array_keys($up['name']) : [] as $i) {
        if ((int) $up['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ((int) $up['error'][$i] !== UPLOAD_ERR_OK) {
            return ['error' => 'A picture could not be uploaded (larger than the server allows?).'];
        }
        $files[] = ['name' => $up['name'][$i], 'tmp_name' => $up['tmp_name'][$i], 'size' => $up['size'][$i]];
    }
    try {
        Sms::send($number, (string) ($_POST['peer'] ?? ''), (string) ($_POST['text'] ?? ''), $files, (int) $user['id'], $settings);
        return ['message' => 'Sent.'];
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
};
