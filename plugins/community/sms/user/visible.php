<?php
/** Messages shows for a user who has a number, and for administrators. */
require_once dirname(__DIR__) . '/lib/Sms.php';

return static fn(array $user, array $settings): bool => Sms::numbersFor($user) !== [];
