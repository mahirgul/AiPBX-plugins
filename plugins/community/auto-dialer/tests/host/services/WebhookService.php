<?php
// Test host stand-in: records what the plugin would send.
final class WebhookService
{
    /** @var list<array{0: string, 1: array}> */
    public static array $sent = [];

    public static function emit(string $event, array $data): void
    {
        self::$sent[] = [$event, $data];
    }
}
