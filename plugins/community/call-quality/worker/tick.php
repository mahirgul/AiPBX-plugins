<?php
/**
 * Background worker hook for `call-quality`.
 *
 * `web/bin/webhook_worker.php` (aipbx-webhook-worker.service) polls every two
 * seconds and calls this for every enabled plugin whose manifest declares
 * `"hooks": {"worker": true}`. The tick is a poll, not a schedule: the
 * five-minute clock lives in `CallQualityService::scheduled()`, which claims a
 * pass, runs it and remembers the outcome for the page.
 *
 * It never throws and never prints. An exception here is caught by the core
 * loader and counted against the plugin — five in a row put it in Safe Mode —
 * so the service fences its own failures, and alert mail goes out through the
 * portal's transport rather than to the journal.
 */
require_once dirname(__DIR__) . '/CaptureState.php';
require_once dirname(__DIR__) . '/CallQualityService.php';

return static function (string $slug, array $settings): void {
    CallQualityService::scheduled($slug, $settings);
};
