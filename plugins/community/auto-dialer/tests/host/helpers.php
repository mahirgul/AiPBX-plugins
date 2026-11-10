<?php
function getDB(): PDO { return $GLOBALS['testDB']; }
function toCleanAscii(string $s): string { return $s; }
function writePBXConf($file,$content): void { $GLOBALS['generatedDialplan']=$content; }
