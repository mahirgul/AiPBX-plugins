<?php
class AsteriskHelper {
    public static array $commands=[];
    public static function execCLI(string $cmd): array { self::$commands[]=$cmd; return ['success'=>true,'output'=>'']; }
    public static function reloadDialplan(): array { return ['success'=>true]; }
}
