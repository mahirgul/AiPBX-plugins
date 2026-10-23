<?php
/**
 * Worker hook: aipbx-webhook-worker calls it every few seconds; the plugin
 * keeps its own one-minute clock (CallQuality::tick).
 */
require_once dirname(__DIR__) . '/CallQuality.php';

return static function (string $slug, array $settings): void {
    CallQuality::tick($slug, $settings);
};
