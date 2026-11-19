<?php
/**
 * /apps/auto-dialer: the campaigns this user manages (chosen by whoever created them).
 * The same screen as the administrator's, without creating or deleting campaigns.
 * Available: $slug, $settings, $user, $csrf_token.
 */
$adManagerView = true;
require dirname(__DIR__) . '/admin/page.php';
