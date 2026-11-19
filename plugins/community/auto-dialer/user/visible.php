<?php
/** Campaigns shows for a user who manages at least one campaign (administrators use /plugins/auto-dialer). */
require_once dirname(__DIR__) . '/AutoDialerService.php';

return static fn(array $user, array $settings): bool => AutoDialerService::campaignsFor($user) !== [];
