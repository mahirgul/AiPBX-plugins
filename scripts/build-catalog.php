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
// "generated" lets AiPBX refuse an older, once valid catalog offered again.
$json = json_encode(['generated' => time(), 'plugins' => $plugins], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents("{$out}/catalog.json", $json);

// catalog.json.sig: a detached Ed25519 signature of exactly these bytes. AiPBX (1.74+) holds the
// public key and installs nothing from a catalog without a valid one; the catalog in turn names
// the SHA-256 of every .zip. The key is the Actions secret CATALOG_SIGNING_KEY.
$key = base64_decode((string) getenv('CATALOG_SIGNING_KEY'), true);
if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    file_put_contents("{$out}/catalog.json.sig", base64_encode(sodium_crypto_sign_detached($json, $key)) . "\n");
    echo "signed, public key " . base64_encode(sodium_crypto_sign_publickey_from_secretkey($key)) . "\n";
} elseif (getenv('CATALOG_REQUIRE_SIGNATURE') === '1') {
    fwrite(STDERR, "CATALOG_SIGNING_KEY is missing or not an Ed25519 secret key: the catalog would be published unsigned\n");
    exit(1);
} else {
    @unlink("{$out}/catalog.json.sig");
    echo "not signed (no CATALOG_SIGNING_KEY)\n";
}
