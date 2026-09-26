<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

try {
    $official = store_official_extensions(true);
    $registry = store_registry_extensions();
    $duplicates = array_intersect_key($official, $registry);
    if ($duplicates !== []) {
        store_fail('Registry entries conflict with official extensions: ' . implode(', ', array_keys($duplicates)));
    }
    $themes = count(array_filter(array_keys($official), static fn(string $key): bool => str_starts_with($key, 'theme:')));
    $plugins = count($official) - $themes;
    echo sprintf("Validated %d themes, %d plugins, and %d external registry entries.\n", $themes, $plugins, count($registry));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Validation failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
