<?php

declare(strict_types=1);

/**
 * php scripts/build-catalog.php [out-dir]: every community plugin as <slug>.zip (its folder, tests
 * left out) and catalog.json listing them with version and SHA-256. CI publishes them on the release
 * "catalog"; AiPBX 1.72+ installs from there when an administrator turns the catalog on.
 */
$root = dirname(__DIR__);
$out = $argv[1] ?? $root . '/dist';
@mkdir($out, 0755, true);
$keys = ['slug', 'name', 'name_i18n', 'description', 'description_i18n', 'version', 'author', 'author_url', 'icon', 'requires_aipbx'];
$plugins = [];
foreach (glob($root . '/plugins/community/*/plugin.json') ?: [] as $manifestPath) {
    $dir = dirname($manifestPath);
    $m = json_decode((string) file_get_contents($manifestPath), true);
    $slug = (string) ($m['slug'] ?? '');
    if ($slug !== basename($dir) || !preg_match('/^[a-z0-9][a-z0-9-]{1,48}$/', $slug)) {
        fwrite(STDERR, "skip {$dir}: slug must match the folder name\n");
        exit(1);
    }
    $zipPath = "{$out}/{$slug}.zip";
    @unlink($zipPath);
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $names = [];
    foreach ($files as $file) {
        $rel = substr($file->getPathname(), strlen($dir) + 1);
        if (!str_starts_with($rel, 'tests/')) {
            $names[$rel] = $file->getPathname();
        }
    }
    ksort($names);
    foreach ($names as $rel => $path) {
        $zip->addFile($path, "{$slug}/{$rel}");
    }
    $zip->close();
    $plugins[] = array_intersect_key($m, array_flip($keys)) + ['sha256' => hash_file('sha256', $zipPath), 'size' => filesize($zipPath)];
    echo "{$slug} {$m['version']}\n";
}
file_put_contents("{$out}/catalog.json", json_encode(['plugins' => $plugins], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
