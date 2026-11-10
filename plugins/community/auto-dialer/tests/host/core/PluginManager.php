<?php
/** Controlled host adapter. The real PluginManager is tested by AiPBX's package tests. */
class PluginManager {
    public static array $settings=[];
    public static function getSetting($slug,$key,$default=''): string {return self::$settings[$key]??$default;}
    public static function setSetting($slug,$key,$value,$secret=false): void {self::$settings[$key]=$value;}
    public static function applyPluginSchema($slug,$dir): array { return []; }
    public static function getAllSettings($slug,$mask=false): array { return []; }
}
