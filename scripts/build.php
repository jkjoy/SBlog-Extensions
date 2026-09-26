<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

try {
    $options = getopt('', ['repository::', 'previous::', 'output::']);
    $config = store_config();
    $repository = trim((string)($options['repository'] ?? getenv('GITHUB_REPOSITORY') ?: $config['repository']));
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository)) {
        store_fail('Invalid --repository value.');
    }
    $outputName = trim((string)($options['output'] ?? 'build'));
    if (!preg_match('/^build(?:-[a-z0-9-]+)?$/', $outputName)) {
        store_fail('--output must be build or start with build-.');
    }
    $output = STORE_ROOT . '/' . $outputName;
    store_remove_tree($output);
    if (!mkdir($output . '/packages', 0755, true) && !is_dir($output . '/packages')) {
        store_fail('Unable to create build directory.');
    }

    $previousPath = isset($options['previous']) ? (string)$options['previous'] : null;
    $previousCatalog = store_previous_catalog($previousPath);
    $previous = store_catalog_map($previousCatalog);
    $official = store_official_extensions(false);
    $registry = store_registry_extensions();
    $duplicates = array_intersect_key($official, $registry);
    if ($duplicates !== []) {
        store_fail('Registry entries conflict with official extensions: ' . implode(', ', array_keys($duplicates)));
    }

    $entries = [];
    $releases = [];
    foreach ($official as $key => $source) {
        $entry = $source['entry'];
        $sourceHash = store_source_hash($source['files']);
        $old = $previous[$key] ?? null;
        $publish = $old === null;
        if (is_array($old)) {
            $comparison = version_compare((string)$entry['version'], (string)($old['version'] ?? '0'));
            if ($comparison < 0) {
                store_fail($key . ' version is older than the published catalog.');
            }
            if ($comparison === 0) {
                $oldSourceHash = strtolower(trim((string)($old['source_sha256'] ?? '')));
                if (!preg_match('/^[a-f0-9]{64}$/', $oldSourceHash)) {
                    store_fail($key . ' has no source digest in the published catalog; publish it from an empty catalog branch once.');
                }
                if (!hash_equals($oldSourceHash, $sourceHash)) {
                    store_fail($key . ' source changed without a version increase.');
                }
                store_assert_https_url((string)($old['download_url'] ?? ''), $key . '.download_url', true);
                if (!preg_match('/^[a-f0-9]{64}$/', (string)($old['sha256'] ?? ''))) {
                    store_fail($key . ' has an invalid published package hash.');
                }
            } else {
                $publish = true;
            }
        }

        if ($publish) {
            $version = (string)$entry['version'];
            $filename = $entry['type'] . '-' . $entry['slug'] . '-' . $version . '.zip';
            $tag = $entry['type'] . '-' . $entry['slug'] . '-v' . $version;
            $packagePath = $output . '/packages/' . $filename;
            store_package((string)$entry['slug'], $source['files'], $packagePath);
            $sha256 = hash_file('sha256', $packagePath);
            $downloadUrl = 'https://github.com/' . $repository . '/releases/download/' . rawurlencode($tag) . '/' . rawurlencode($filename);
            $releases[] = [
                'type' => $entry['type'],
                'slug' => $entry['slug'],
                'name' => $entry['name'],
                'version' => $version,
                'tag' => $tag,
                'filename' => $filename,
                'path' => 'packages/' . $filename,
                'download_url' => $downloadUrl,
                'sha256' => $sha256,
            ];
        } else {
            $downloadUrl = (string)$old['download_url'];
            $sha256 = (string)$old['sha256'];
        }
        $entries[$key] = $entry + [
            'download_url' => $downloadUrl,
            'sha256' => $sha256,
            'source_sha256' => $sourceHash,
        ];
    }

    foreach ($registry as $key => $entry) {
        $old = $previous[$key] ?? null;
        if (is_array($old)) {
            $comparison = version_compare((string)$entry['version'], (string)($old['version'] ?? '0'));
            if ($comparison < 0) {
                store_fail($key . ' registry version is older than the published catalog.');
            }
            if ($comparison === 0 && ((string)($old['sha256'] ?? '') !== $entry['sha256']
                || (string)($old['download_url'] ?? '') !== $entry['download_url'])) {
                store_fail($key . ' changed its package without a version increase.');
            }
        }
        $entries[$key] = $entry;
    }

    $retired = array_fill_keys($config['retired'], true);
    foreach (array_keys($previous) as $key) {
        if (!isset($entries[$key]) && !isset($retired[$key])) {
            store_fail($key . ' disappeared from the repository. Add it to retired before removing it.');
        }
    }

    ksort($entries, SORT_STRING);
    usort($releases, static fn(array $left, array $right): int => strcmp($left['type'] . ':' . $left['slug'], $right['type'] . ':' . $right['slug']));
    $catalogEntries = array_values($entries);
    $previousEntries = array_values(store_catalog_map($previousCatalog));
    usort($previousEntries, static fn(array $left, array $right): int => strcmp((string)$left['type'] . ':' . (string)$left['slug'], (string)$right['type'] . ':' . (string)$right['slug']));
    $catalogChanged = json_encode($catalogEntries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        !== json_encode($previousEntries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $updatedAt = $catalogChanged ? gmdate('c') : (string)($previousCatalog['updated_at'] ?? gmdate('c'));
    $catalog = [
        'schema' => 1,
        'updated_at' => $updatedAt,
        'extensions' => $catalogEntries,
    ];
    $plan = [
        'schema' => 1,
        'repository' => $repository,
        'catalog_branch' => (string)$config['catalog_branch'],
        'catalog_changed' => $catalogChanged,
        'release_count' => count($releases),
        'releases' => $releases,
    ];
    store_write_json($output . '/catalog.json', $catalog);
    store_write_json($output . '/release-plan.json', $plan);
    echo sprintf("Built %d catalog entries and %d release package(s) in %s.\n", count($entries), count($releases), $outputName);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Build failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
