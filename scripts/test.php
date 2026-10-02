<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$temporary = sys_get_temp_dir() . '/sblog-extension-tools-' . bin2hex(random_bytes(6));
try {
    if (!mkdir($temporary, 0700, true)) {
        store_fail('Unable to create test directory.');
    }
    $crlf = $temporary . '/crlf.txt';
    $lf = $temporary . '/lf.txt';
    $binary = $temporary . '/binary.dat';
    file_put_contents($crlf, "first\r\nsecond\r\n");
    file_put_contents($lf, "first\nsecond\n");
    file_put_contents($binary, "\x00\r\n\xff");
    if (store_file_bytes($crlf) !== store_file_bytes($lf)) {
        store_fail('Text line-ending normalization is not deterministic.');
    }
    if (store_file_bytes($binary) !== "\x00\r\n\xff") {
        store_fail('Binary package content was modified.');
    }
    $manifest = [
        'name' => 'Sample Extension',
        'version' => '1.0.0',
        'author' => '',
        'description' => '',
        'url' => '',
    ];
    $config = ['defaults' => ['requires' => '1.13.6', 'tested' => '1.14.5']];
    $defaultEntry = store_manifest_entry('plugin', 'sample-plugin', $manifest, $config);
    if ($defaultEntry['requires'] !== '1.13.6' || $defaultEntry['tested'] !== '1.14.5') {
        store_fail('Manifest compatibility defaults were not applied.');
    }
    $overrideEntry = store_manifest_entry('theme', 'sample-theme', array_merge($manifest, [
        'requires' => '1.14.0-beta.1',
        'tested' => '1.15.0+build.2',
    ]), $config);
    if ($overrideEntry['requires'] !== '1.14.0-beta.1' || $overrideEntry['tested'] !== '1.15.0+build.2') {
        store_fail('Manifest compatibility overrides were not applied.');
    }
    foreach (['requires' => '1.14', 'tested' => 'latest'] as $field => $value) {
        $invalidManifest = $manifest;
        $invalidManifest[$field] = $value;
        $rejected = false;
        try {
            store_manifest_entry('plugin', 'invalid-plugin', $invalidManifest, $config);
        } catch (RuntimeException $exception) {
            $rejected = str_contains($exception->getMessage(), '.' . $field . ' must be a semantic version');
        }
        if (!$rejected) {
            store_fail('Invalid manifest ' . $field . ' was accepted.');
        }
    }
    $firstZip = $temporary . '/first.zip';
    $secondZip = $temporary . '/second.zip';
    store_package('sample', ['file.txt' => $crlf], $firstZip);
    store_package('sample', ['file.txt' => $lf], $secondZip);
    if (!hash_equals(hash_file('sha256', $firstZip), hash_file('sha256', $secondZip))) {
        store_fail('Equivalent text produced different packages.');
    }

    $commentEnhancerFiles = store_extension_files(STORE_ROOT . '/plugins/comment-enhancer');
    $commentEnhancerZip = $temporary . '/comment-enhancer.zip';
    store_package('comment-enhancer', $commentEnhancerFiles, $commentEnhancerZip);
    $archive = new ZipArchive();
    if ($archive->open($commentEnhancerZip) !== true) {
        store_fail('Unable to inspect the comment-enhancer package.');
    }
    try {
        $packageFiles = [];
        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            if (is_string($name)) {
                $packageFiles[$name] = true;
            }
        }
        foreach ([
            'comment-enhancer/vendor/maxmind-db-reader/LICENSE',
            'comment-enhancer/vendor/maxmind-db-reader/composer.json',
            'comment-enhancer/vendor/maxmind-db-reader/src/MaxMind/Db/Reader.php',
            'comment-enhancer/resources/geo/dbip-city-lite.mmdb.gz',
            'comment-enhancer/resources/geo/LICENSE.txt',
            'comment-enhancer/resources/geo/README.md',
            'comment-enhancer/resources/geo/provenance.json',
        ] as $requiredFile) {
            if (!isset($packageFiles[$requiredFile])) {
                store_fail('Comment enhancer package is missing ' . $requiredFile . '.');
            }
        }
        foreach (array_keys($packageFiles) as $name) {
            $normalized = strtolower($name);
            if (str_ends_with($normalized, '.mmdb')
                || (str_ends_with($normalized, '.mmdb.gz') && $name !== 'comment-enhancer/resources/geo/dbip-city-lite.mmdb.gz')
                || str_contains($normalized, '/fixtures/')) {
                store_fail('Comment enhancer package contains test data: ' . $name);
            }
        }
        if ((int)filesize($commentEnhancerZip) > 67108864) {
            store_fail('Comment enhancer package exceeds the site installer 64 MiB limit.');
        }
        $databaseStream = $archive->getStream('comment-enhancer/resources/geo/dbip-city-lite.mmdb.gz');
        if (!is_resource($databaseStream)) {
            store_fail('Unable to verify the packaged built-in database.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $databaseStream);
            $provenance = json_decode((string)file_get_contents(STORE_ROOT . '/plugins/comment-enhancer/resources/geo/provenance.json'), true);
            if (!hash_equals((string)($provenance['compressed_sha256'] ?? ''), hash_final($hash))) {
                store_fail('Packaged built-in database does not match its provenance.');
            }
        } finally {
            fclose($databaseStream);
        }
    } finally {
        $archive->close();
    }
    echo "Packaging self-test passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Self-test failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    store_remove_tree($temporary);
}

require __DIR__ . '/test-font-installer.php';
require __DIR__ . '/test-comment-enhancer.php';

$staticPageCacheCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test-static-page-cache.php');
passthru($staticPageCacheCommand, $staticPageCacheStatus);
if ($staticPageCacheStatus !== 0) {
    store_fail('Static page cache tests failed.');
}

$restApiCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test-rest-api-content-protection.php');
passthru($restApiCommand, $restApiStatus);
if ($restApiStatus !== 0) {
    store_fail('REST API content-protection tests failed.');
}

$integrationCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test-comment-enhancer-integration.php');
passthru($integrationCommand, $integrationStatus);
if ($integrationStatus !== 0) {
    store_fail('Comment enhancer integration tests failed.');
}

$builtinGeoCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test-comment-enhancer-builtin.php');
passthru($builtinGeoCommand, $builtinGeoStatus);
if ($builtinGeoStatus !== 0) {
    store_fail('Comment enhancer built-in database tests failed.');
}

$galleryCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test-gallery.php');
passthru($galleryCommand, $galleryStatus);
if ($galleryStatus !== 0) {
    store_fail('Gallery integration tests failed.');
}

$galleryMediaCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/test-gallery-media.php');
passthru($galleryMediaCommand, $galleryMediaStatus);
if ($galleryMediaStatus !== 0) {
    store_fail('Gallery thumbnail tests failed.');
}

foreach (['test-menu-manager.php', 'test-menu-manager-themes.php'] as $menuTest) {
    $menuCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $menuTest);
    passthru($menuCommand, $menuStatus);
    if ($menuStatus !== 0) {
        store_fail('Menu manager tests failed: ' . $menuTest);
    }
}

foreach (['test-paid-reading.php', 'test-paid-reading-payment.php', 'test-paid-reading-recovery.php'] as $paidReadingTest) {
    $paidReadingCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $paidReadingTest);
    passthru($paidReadingCommand, $paidReadingStatus);
    if ($paidReadingStatus !== 0) {
        store_fail('Paid reading tests failed: ' . $paidReadingTest);
    }
}
