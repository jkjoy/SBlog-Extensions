<?php

declare(strict_types=1);

const STORE_ROOT = __DIR__ . '/..';
const STORE_MAX_FILES = 3000;
const STORE_MAX_BYTES = 134217728;
const STORE_ZIP_EPOCH = 315532800;

function store_fail(string $message): never
{
    throw new RuntimeException($message);
}

function store_json(string $path): array
{
    if (!is_file($path)) {
        store_fail('Missing JSON file: ' . $path);
    }
    try {
        $value = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        store_fail('Invalid JSON in ' . $path . ': ' . $exception->getMessage());
    }
    if (!is_array($value)) {
        store_fail('JSON root must be an object: ' . $path);
    }
    return $value;
}

function store_write_json(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json . "\n") === false) {
        store_fail('Unable to write ' . $path);
    }
}

function store_config(): array
{
    $config = store_json(STORE_ROOT . '/store.config.json');
    if (($config['schema'] ?? null) !== 1) {
        store_fail('store.config.json must use schema 1.');
    }
    $repository = trim((string)($config['repository'] ?? ''));
    $branch = trim((string)($config['catalog_branch'] ?? ''));
    $defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
    if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository)) {
        store_fail('store.config.json contains an invalid repository.');
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $branch)) {
        store_fail('store.config.json contains an invalid catalog branch.');
    }
    foreach (['requires', 'tested'] as $field) {
        store_assert_version((string)($defaults[$field] ?? ''), 'defaults.' . $field);
    }
    $retired = is_array($config['retired'] ?? null) ? array_values($config['retired']) : [];
    foreach ($retired as $key) {
        if (!is_string($key) || !preg_match('/^(theme|plugin):[a-z0-9][a-z0-9_-]*$/', $key)) {
            store_fail('Invalid retired extension key in store.config.json.');
        }
    }
    return $config + ['retired' => []];
}

function store_assert_version(string $version, string $field): void
{
    if (!preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version)) {
        store_fail($field . ' must be a semantic version, got: ' . $version);
    }
}

function store_assert_text(string $value, string $field, int $maxLength, bool $required = true): void
{
    if (($required && trim($value) === '') || mb_strlen($value, 'UTF-8') > $maxLength || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
        store_fail('Invalid ' . $field . '.');
    }
}

function store_assert_https_url(string $url, string $field, bool $required = false): void
{
    if ($url === '' && !$required) {
        return;
    }
    $parts = parse_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user']) || isset($parts['pass'])) {
        store_fail($field . ' must be an HTTPS URL.');
    }
}

function store_manifest_entry(string $type, string $slug, array $manifest, array $config): array
{
    $name = trim((string)($manifest['name'] ?? ''));
    $version = trim((string)($manifest['version'] ?? ''));
    $author = trim((string)($manifest['author'] ?? ''));
    $description = trim((string)($manifest['description'] ?? ''));
    $homepage = trim((string)($manifest['url'] ?? ''));
    store_assert_text($name, $type . ':' . $slug . '.name', 100);
    store_assert_version($version, $type . ':' . $slug . '.version');
    store_assert_text($author, $type . ':' . $slug . '.author', 100, false);
    store_assert_text($description, $type . ':' . $slug . '.description', 500, false);
    store_assert_https_url($homepage, $type . ':' . $slug . '.url');
    $defaults = $config['defaults'];
    $compatibility = [];
    foreach (['requires', 'tested'] as $field) {
        $compatibility[$field] = array_key_exists($field, $manifest)
            ? trim((string)$manifest[$field])
            : (string)$defaults[$field];
        store_assert_version($compatibility[$field], $type . ':' . $slug . '.' . $field);
    }
    return [
        'type' => $type,
        'slug' => $slug,
        'name' => $name,
        'version' => $version,
        'author' => $author,
        'description' => $description,
        'homepage' => $homepage,
        'requires' => $compatibility['requires'],
        'tested' => $compatibility['tested'],
    ];
}

function store_extension_files(string $directory): array
{
    $files = [];
    $bytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $prefixLength = strlen(rtrim($directory, '/\\')) + 1;
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            store_fail('Symbolic links are not allowed: ' . $item->getPathname());
        }
        if (!$item->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), $prefixLength));
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')) {
                store_fail('Unsafe extension path: ' . $relative);
            }
        }
        if (in_array(basename($relative), ['.DS_Store', 'Thumbs.db'], true)) {
            store_fail('Remove generated file before publishing: ' . $relative);
        }
        $bytes += (int)$item->getSize();
        if (count($files) + 1 > STORE_MAX_FILES || $bytes > STORE_MAX_BYTES) {
            store_fail('Extension exceeds package safety limits: ' . $directory);
        }
        $files[$relative] = $item->getPathname();
    }
    ksort($files, SORT_STRING);
    return $files;
}

function store_official_extensions(bool $lintPhp = false): array
{
    $config = store_config();
    $extensions = [];
    foreach (['theme' => 'themes', 'plugin' => 'plugins'] as $type => $folder) {
        $root = STORE_ROOT . '/' . $folder;
        if (!is_dir($root)) {
            store_fail('Missing extension directory: ' . $folder);
        }
        $directories = glob($root . '/*', GLOB_ONLYDIR) ?: [];
        sort($directories, SORT_STRING);
        foreach ($directories as $directory) {
            $slug = basename($directory);
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
                store_fail('Invalid extension directory name: ' . $slug);
            }
            $manifestName = $type === 'theme' ? 'theme.json' : 'plugin.json';
            $manifestPath = $directory . '/' . $manifestName;
            $entry = store_manifest_entry($type, $slug, store_json($manifestPath), $config);
            if ($type === 'plugin' && !is_file($directory . '/plugin.php')) {
                store_fail('Plugin entry point is missing: ' . $slug . '/plugin.php');
            }
            $files = store_extension_files($directory);
            if ($lintPhp) {
                foreach ($files as $relative => $path) {
                    if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'php') {
                        continue;
                    }
                    $output = [];
                    $status = 0;
                    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
                    if ($status !== 0) {
                        store_fail('PHP lint failed for ' . $folder . '/' . $slug . '/' . $relative . ': ' . implode("\n", $output));
                    }
                }
            }
            $key = $type . ':' . $slug;
            if (isset($extensions[$key])) {
                store_fail('Duplicate extension key: ' . $key);
            }
            $extensions[$key] = [
                'entry' => $entry,
                'directory' => $directory,
                'files' => $files,
            ];
        }
    }
    ksort($extensions, SORT_STRING);
    return $extensions;
}

function store_registry_extensions(): array
{
    $extensions = [];
    $files = glob(STORE_ROOT . '/registry/*.json') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        $entry = store_json($file);
        $type = strtolower(trim((string)($entry['type'] ?? '')));
        $slug = strtolower(trim((string)($entry['slug'] ?? '')));
        if (!in_array($type, ['theme', 'plugin'], true) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            store_fail('Invalid registry type or slug in ' . basename($file));
        }
        foreach (['name' => 100, 'author' => 100, 'description' => 500] as $field => $limit) {
            store_assert_text(trim((string)($entry[$field] ?? '')), basename($file) . '.' . $field, $limit, $field === 'name');
        }
        foreach (['version', 'requires', 'tested'] as $field) {
            store_assert_version(trim((string)($entry[$field] ?? '')), basename($file) . '.' . $field);
        }
        store_assert_https_url(trim((string)($entry['homepage'] ?? '')), basename($file) . '.homepage');
        store_assert_https_url(trim((string)($entry['download_url'] ?? '')), basename($file) . '.download_url', true);
        $sha256 = strtolower(trim((string)($entry['sha256'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            store_fail('Invalid SHA-256 in ' . basename($file));
        }
        $key = $type . ':' . $slug;
        $extensions[$key] = [
            'type' => $type,
            'slug' => $slug,
            'name' => trim((string)$entry['name']),
            'version' => trim((string)$entry['version']),
            'author' => trim((string)($entry['author'] ?? '')),
            'description' => trim((string)($entry['description'] ?? '')),
            'homepage' => trim((string)($entry['homepage'] ?? '')),
            'requires' => trim((string)$entry['requires']),
            'tested' => trim((string)$entry['tested']),
            'download_url' => trim((string)$entry['download_url']),
            'sha256' => $sha256,
        ];
    }
    ksort($extensions, SORT_STRING);
    return $extensions;
}

function store_source_hash(array $files): string
{
    $context = hash_init('sha256');
    foreach ($files as $relative => $path) {
        hash_update($context, $relative . "\0" . hash('sha256', store_file_bytes($path)) . "\0");
    }
    return hash_final($context);
}

function store_file_bytes(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        store_fail('Unable to read package file: ' . $path);
    }
    if (!str_contains($contents, "\0") && mb_check_encoding($contents, 'UTF-8')) {
        return str_replace(["\r\n", "\r"], "\n", $contents);
    }
    return $contents;
}

function store_package(string $slug, array $files, string $target): void
{
    if (!class_exists(ZipArchive::class)) {
        store_fail('The PHP zip extension is required.');
    }
    $zip = new ZipArchive();
    if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        store_fail('Unable to create package: ' . $target);
    }
    $zip->addEmptyDir($slug);
    if (method_exists($zip, 'setMtimeName')) {
        $zip->setMtimeName($slug . '/', STORE_ZIP_EPOCH);
    }
    foreach ($files as $relative => $path) {
        $name = $slug . '/' . $relative;
        if (!$zip->addFromString($name, store_file_bytes($path))) {
            $zip->close();
            store_fail('Unable to add package file: ' . $name);
        }
        if (method_exists($zip, 'setMtimeName')) {
            $zip->setMtimeName($name, STORE_ZIP_EPOCH);
        }
        if (method_exists($zip, 'setExternalAttributesName')) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        }
    }
    if (!$zip->close()) {
        store_fail('Unable to finalize package: ' . $target);
    }
}

function store_previous_catalog(?string $path): array
{
    if ($path === null || $path === '' || !is_file($path) || filesize($path) === 0) {
        return ['schema' => 1, 'updated_at' => '', 'extensions' => []];
    }
    $catalog = store_json($path);
    if (($catalog['schema'] ?? null) !== 1 || !is_array($catalog['extensions'] ?? null)) {
        store_fail('Previous catalog must use schema 1.');
    }
    return $catalog;
}

function store_catalog_map(array $catalog): array
{
    $defaults = is_array($catalog['package_defaults'] ?? null) ? $catalog['package_defaults'] : [];
    $map = [];
    foreach ($catalog['extensions'] ?? [] as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $type = (string)($entry['type'] ?? '');
        $slug = (string)($entry['slug'] ?? '');
        if (!in_array($type, ['theme', 'plugin'], true) || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            continue;
        }
        $entry['download_url'] = (string)($entry['download_url'] ?? $defaults['download_url'] ?? '');
        $entry['sha256'] = strtolower((string)($entry['sha256'] ?? $defaults['sha256'] ?? ''));
        $map[$type . ':' . $slug] = $entry;
    }
    return $map;
}

function store_remove_tree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}
