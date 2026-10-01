<?php

declare(strict_types=1);

$scenarios = [
    'database', 'fresh', 'legacy-off', 'legacy-online', 'legacy-upload',
    'local-builtin-version', 'off-builtin-version', 'online-version', 'uploaded-version',
    'extract-concurrent', 'missing-source', 'corrupt-source', 'directory-symlink', 'source-symlink',
    'restore-builtin', 'delete-upload', 'off-attribution',
];
$scenario = ($argv[1] ?? '') === '--scenario' ? (string)($argv[2] ?? '') : '';
if ($scenario === '') {
    foreach ($scenarios as $name) {
        $process = proc_open(
            [PHP_BINARY, __FILE__, '--scenario', $name],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start builtin database test: ' . $name);
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            fwrite(STDERR, 'Builtin database scenario failed: ' . $name . "\n" . $output . $error);
            exit(1);
        }
    }
    echo 'comment-enhancer builtin database tests passed (' . count($scenarios) . " scenarios)\n";
    exit;
}
if (!in_array($scenario, $scenarios, true) && $scenario !== 'extract-worker') {
    throw new RuntimeException('Unknown builtin database test scenario.');
}

$testRoot = sys_get_temp_dir() . '/sblog-comment-enhancer-builtin-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Unable to create builtin database test directory.');
}
define('PLUGINS_DIR', dirname(__DIR__) . '/plugins');
define('DATA_DIR', $scenario === 'extract-worker' ? (string)($argv[3] ?? '') : $testRoot . '/data');

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || !is_dir($path)) {
        if (is_link($path) || file_exists($path)) {
            @unlink($path);
        }
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
};
register_shutdown_function($removeTree, $testRoot);

$GLOBALS['sce_builtin_test_db'] = new PDO('sqlite::memory:');
db()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
db()->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function db(): PDO
{
    return $GLOBALS['sce_builtin_test_db'];
}

function q(string $sql, array $parameters = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}

function val(string $sql, array $parameters = []): mixed
{
    $value = q($sql, $parameters)->fetchColumn();
    return $value === false ? false : $value;
}

function one(string $sql, array $parameters = []): ?array
{
    $row = q($sql, $parameters)->fetch();
    return is_array($row) ? $row : null;
}

function all_rows(string $sql, array $parameters = []): array
{
    return q($sql, $parameters)->fetchAll();
}

function table_columns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $row) {
        $columns[(string)$row['name']] = true;
    }
    return $columns;
}

function sblog_i18n_locale(): string { return 'zh-CN'; }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function add_plugin_action(string $hook, callable $callback, int $priority = 10): void {}
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void {}
function add_theme_action(string $hook, callable $callback, int $priority = 10): void {}

function sce_builtin_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if ($scenario === 'extract-worker') {
    require (string)($argv[4] ?? '') . '/local-geo.php';
    $ready = sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE);
    sce_builtin_test_assert($ready !== '' && is_file($ready), 'Concurrent worker could not prepare the builtin database.');
    echo $ready;
    exit;
}

if (in_array($scenario, ['extract-concurrent', 'missing-source', 'corrupt-source', 'directory-symlink', 'source-symlink'], true)) {
    $copyRoot = $testRoot . '/isolated-plugin';
    mkdir($copyRoot . '/resources/geo', 0700, true);
    copy(dirname(__DIR__) . '/plugins/comment-enhancer/local-geo.php', $copyRoot . '/local-geo.php');
    require $copyRoot . '/local-geo.php';
    $source = sce_local_geo_builtin_source_path();
    $actualSource = dirname(__DIR__) . '/plugins/comment-enhancer/resources/geo/dbip-city-lite.mmdb.gz';
    $path = sce_local_geo_builtin_database_path();
    if ($scenario === 'missing-source') {
        sce_builtin_test_assert(sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE) === '', 'Missing compressed source did not fail safely.');
    } elseif ($scenario === 'corrupt-source') {
        $stream = gzopen($source, 'wb');
        sce_builtin_test_assert(is_resource($stream), 'Unable to create corrupt gzip fixture.');
        $remaining = SCE_BUILTIN_GEO_BYTES;
        while ($remaining > 0) {
            $chunk = str_repeat("\0", min($remaining, 1048576));
            sce_builtin_test_assert(gzwrite($stream, $chunk) === strlen($chunk), 'Unable to finish corrupt gzip fixture.');
            $remaining -= strlen($chunk);
        }
        gzclose($stream);
        sce_builtin_test_assert(sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE) === '', 'Correct-size database with a bad hash was installed.');
        sce_builtin_test_assert(!is_file($path), 'Failed extraction left a database behind.');
        sce_builtin_test_assert((glob(dirname($path) . '/.dbip-extract-*') ?: []) === [], 'Failed extraction left temporary files behind.');
        unlink($source);
        file_put_contents($source, 'not a gzip database');
        sce_builtin_test_assert(sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE) === '', 'Corrupt gzip did not fail safely.');
    } elseif ($scenario === 'directory-symlink') {
        $outside = $testRoot . '/outside';
        mkdir($outside, 0700);
        sce_local_geo_ensure_directory();
        sce_builtin_test_assert(symlink($outside, dirname($path)), 'Unable to create database directory symlink fixture.');
        sce_builtin_test_assert(sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE) === '' && (scandir($outside) ?: []) === ['.', '..'], 'Extraction followed a directory symlink.');
        unlink(dirname($path));
        mkdir(dirname($path), 0700);
        sce_builtin_test_assert(symlink($actualSource, $path), 'Unable to create database file symlink fixture.');
        sce_builtin_test_assert(sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE) === '', 'Extraction followed a database file symlink.');
    } elseif ($scenario === 'source-symlink') {
        sce_builtin_test_assert(symlink($actualSource, $source), 'Unable to create source symlink fixture.');
        sce_builtin_test_assert(sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE) === '', 'Extraction followed a compressed source symlink.');
        unlink($source);
        rmdir(dirname($source));
        sce_builtin_test_assert(symlink(dirname($actualSource), dirname($source)), 'Unable to create source directory symlink fixture.');
        sce_builtin_test_assert(sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE) === '', 'Extraction followed a compressed source directory symlink.');
    } else {
        sce_builtin_test_assert(@link($actualSource, $source) || copy($actualSource, $source), 'Unable to install an isolated compressed source.');
        $workers = [];
        for ($index = 0; $index < 4; $index++) {
            $process = proc_open([PHP_BINARY, __FILE__, '--scenario', 'extract-worker', DATA_DIR, $copyRoot],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            sce_builtin_test_assert(is_resource($process), 'Unable to start concurrent extraction worker.');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            sce_builtin_test_assert(proc_close($process) === 0 && $output === $path, 'Concurrent extraction failed: ' . $error);
        }
        sce_builtin_test_assert(hash_file('sha256', $path) === SCE_BUILTIN_GEO_SHA256, 'Concurrent extraction produced an invalid database.');
        sce_builtin_test_assert((glob(dirname($path) . '/.dbip-extract-*') ?: []) === [], 'Concurrent extraction left temporary files behind.');
        $inode = fileinode($path);
        $mtime = filemtime($path);
        unlink($source);
        sce_builtin_test_assert(sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE) === $path
            && fileinode($path) === $inode && filemtime($path) === $mtime, 'Cached database was not reused after the source became unavailable.');
    }
    exit;
}

require dirname(__DIR__) . '/plugins/comment-enhancer/plugin.php';

if ($scenario === 'database') {
    $path = sce_local_geo_builtin_database_path();
    sce_builtin_test_assert(!is_file($path), 'Builtin database was extracted before first use.');
    sce_builtin_test_assert(sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE) === $path, 'First-use extraction did not prepare the database.');
    $source = sce_local_geo_builtin_source_path();
    $provenancePath = dirname($source) . '/provenance.json';
    $provenance = json_decode((string)file_get_contents($provenancePath), true, 16, JSON_THROW_ON_ERROR);
    sce_builtin_test_assert(($provenance['release'] ?? '') === SCE_BUILTIN_GEO_VERSION, 'Builtin database release and version differ.');
    sce_builtin_test_assert(($provenance['file'] ?? '') === 'dbip-city-lite.mmdb'
        && ($provenance['compressed_file'] ?? '') === basename($source), 'Provenance points to another database file.');
    sce_builtin_test_assert(($provenance['license'] ?? '') === 'CC-BY-4.0', 'Builtin database license is missing.');
    sce_builtin_test_assert(($provenance['attribution_url'] ?? '') === 'https://db-ip.com/', 'DB-IP attribution is missing.');
    sce_builtin_test_assert(filesize($path) === ($provenance['size_bytes'] ?? 0), 'Builtin database size differs from provenance.');
    sce_builtin_test_assert(filesize($source) === ($provenance['compressed_size_bytes'] ?? 0), 'Compressed database size differs from provenance.');
    sce_builtin_test_assert(hash_file('sha256', $source) === ($provenance['compressed_sha256'] ?? ''), 'Compressed database checksum differs from provenance.');
    $digest = hash_file('sha256', $path);
    $expectedDigest = (string)($provenance['sha256'] ?? '');
    sce_builtin_test_assert(preg_match('/\A[a-f0-9]{64}\z/', $expectedDigest) === 1
        && is_string($digest) && hash_equals($expectedDigest, $digest), 'Builtin database checksum differs from provenance.');
    sce_builtin_test_assert($expectedDigest === SCE_BUILTIN_GEO_SHA256 && filesize($path) === SCE_BUILTIN_GEO_BYTES, 'Extraction integrity constants differ from provenance.');
    $inode = fileinode($path);
    sce_builtin_test_assert(sce_local_geo_lookup_path(SCE_BUILTIN_GEO_DATABASE) === $path && fileinode($path) === $inode, 'Repeated lookup replaced the extracted database.');
    $status = sce_local_geo_database_status(SCE_BUILTIN_GEO_DATABASE);
    sce_builtin_test_assert($status['valid'] === true, 'Builtin database cannot be read.');
    sce_builtin_test_assert($status['supports_ipv4'] === true && $status['supports_ipv6'] === true, 'Builtin database must support both IP versions.');
    sce_builtin_test_assert(stripos($status['database_type'], 'City') !== false, 'Builtin database does not contain city records.');

    foreach ([
        '8.8.8.8' => ['US', 'California'],
        '2001:4860:4860::8888' => ['CA', 'Quebec'],
        '223.5.5.5' => ['CN', 'Zhejiang'],
        '2400:3200::1' => ['CN', 'Zhejiang'],
    ] as $ip => [$country, $region]) {
        $record = json_decode(sce_lookup_local_ip($ip, SCE_BUILTIN_GEO_DATABASE), true, 8, JSON_THROW_ON_ERROR);
        sce_builtin_test_assert(($record['country_code'] ?? '') === $country && ($record['region'] ?? '') === $region,
            'Builtin database sample does not match its published record: ' . $ip);
        if ($country === 'CN') {
            sce_builtin_test_assert(sce_format_geo_location($record, 'zh-CN') === '中国 · 浙江', 'Chinese province is not shown.');
        }
    }
    sce_builtin_test_assert(sce_lookup_local_ip('::ffff:223.5.5.5', SCE_BUILTIN_GEO_DATABASE)
        === sce_lookup_local_ip('223.5.5.5', SCE_BUILTIN_GEO_DATABASE), 'Mapped IPv4 lookup differs from IPv4.');
    sce_builtin_test_assert(sce_local_geo_database_path(SCE_BUILTIN_GEO_DATABASE) === '', 'Builtin database entered the writable upload path.');
    sce_builtin_test_assert(sce_local_geo_lookup_path('../resources/geo/dbip-city-lite.mmdb') === '', 'Traversal path was accepted.');
    sce_builtin_test_assert(sce_delete_local_geo_database(SCE_BUILTIN_GEO_DATABASE) === false && is_file($path), 'Builtin database can be deleted.');

    $directory = sce_local_geo_ensure_directory();
    $selected = 'geoip-' . str_repeat('a', 32) . '.mmdb';
    $stale = 'geoip-' . str_repeat('b', 32) . '.mmdb';
    file_put_contents(sce_local_geo_database_path($selected), 'selected-upload');
    file_put_contents(sce_local_geo_database_path($stale), 'stale-upload');
    file_put_contents($directory . '/other.mmdb', 'unmanaged');
    sce_builtin_test_assert(sce_cleanup_local_geo_databases($selected) === ['removed' => 1, 'failed' => []], 'Cleanup did not preserve the selected upload.');
    sce_builtin_test_assert(is_file(sce_local_geo_database_path($selected)) && is_file($path), 'Cleanup removed a selected or builtin database.');
    $linkName = 'geoip-' . str_repeat('c', 32) . '.mmdb';
    $link = sce_local_geo_database_path($linkName);
    if (@symlink($path, $link)) {
        sce_builtin_test_assert(sce_delete_local_geo_database($linkName) === false, 'Managed symlink was treated as an uploaded database.');
        sce_builtin_test_assert(sce_local_geo_database_status($linkName)['valid'] === false, 'Managed symlink database was accepted.');
    }
    sce_builtin_test_assert(sce_cleanup_local_geo_databases(SCE_BUILTIN_GEO_DATABASE) === ['removed' => 1, 'failed' => []], 'Builtin cleanup removed an unexpected file.');
    sce_builtin_test_assert(is_file($path) && is_file($directory . '/other.mmdb'), 'Cleanup touched a builtin or unmanaged database.');
    if (is_link($link)) {
        sce_builtin_test_assert(is_file($path), 'Symlink cleanup damaged the builtin database.');
    }
    exit;
}

$uploadDatabase = 'geoip-' . str_repeat('d', 32) . '.mmdb';
$uploadPath = sce_local_geo_database_path($uploadDatabase);
if (in_array($scenario, ['legacy-upload', 'uploaded-version', 'restore-builtin', 'delete-upload', 'off-attribution'], true)) {
    sce_local_geo_ensure_directory();
    sce_builtin_test_assert(copy(__DIR__ . '/fixtures/GeoIP2-City-Test.mmdb', $uploadPath), 'Uploaded database fixture could not be installed.');
}

$freshScenarios = ['fresh', 'restore-builtin', 'delete-upload', 'off-attribution'];
if (!in_array($scenario, $freshScenarios, true)) {
    db()->exec("CREATE TABLE comment_enhancer_settings(name TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
    db()->exec("CREATE TABLE comment_enhancer_ip_cache(ip_hash TEXT PRIMARY KEY, location TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'failed', checked_at INTEGER NOT NULL DEFAULT 0, attempted_at INTEGER NOT NULL DEFAULT 0)");
    $mode = match ($scenario) {
        'legacy-off', 'off-builtin-version' => 'off',
        'legacy-online', 'online-version' => 'online',
        default => 'local',
    };
    $database = match ($scenario) {
        'legacy-off', 'legacy-online' => '',
        'legacy-upload', 'uploaded-version' => $uploadDatabase,
        default => SCE_BUILTIN_GEO_DATABASE,
    };
    $values = [
        'schema_version' => str_starts_with($scenario, 'legacy-') ? '3' : SCE_SCHEMA_VERSION,
        'cache_key_version' => '2',
        'cache_secret' => str_repeat('a', 64),
        'lookup_generation' => str_repeat('b', 32),
        'lookup_mode' => $mode,
        'local_geo_database' => $database,
        'backfill_cursor' => '123',
        'last_cache_prune' => (string)time(),
    ];
    if (!str_starts_with($scenario, 'legacy-')) {
        $values['builtin_geo_version'] = '2026-09';
        $values['cache_source_database'] = $mode === 'online' ? '' : $database;
    }
    foreach ($values as $name => $value) {
        q('INSERT INTO comment_enhancer_settings(name, value) VALUES(?, ?)', [$name, $value]);
    }
    q('INSERT INTO comment_enhancer_ip_cache(ip_hash, location, status, checked_at, attempted_at) VALUES(?, ?, ?, ?, ?)',
        ['old-record', '{"country_code":"CN","country":"China","region":"Zhejiang"}', 'ok', time(), time()]);
}

sce_install();
$settings = sce_settings();
if ($scenario === 'off-attribution') {
    sce_builtin_test_assert(sce_location_for_ip('223.5.5.5', true) === '中国 · 浙江', 'Builtin location could not be cached before disabling lookup.');
    $generation = (string)sce_settings()['lookup_generation'];
    sce_change_lookup_context('off', SCE_BUILTIN_GEO_DATABASE);
    sce_change_lookup_context('off', $uploadDatabase);
    sce_builtin_test_assert(sce_lookup_mode() === 'off' && (string)sce_settings()['lookup_generation'] === $generation
        && (string)sce_settings()['cache_source_database'] === SCE_BUILTIN_GEO_DATABASE, 'Changing a disabled selection lost the builtin cache source.');
    $html = sce_render_comment_meta('', ['comment' => ['ip_address' => '223.5.5.5']]);
    sce_builtin_test_assert(str_contains($html, '中国 · 浙江') && str_contains($html, 'href="https://db-ip.com/"'), 'Disabled lookup did not credit the builtin cached location.');
    sce_select_builtin_geo_database();
    sce_builtin_test_assert(sce_lookup_mode() === 'off' && (string)sce_settings()['lookup_generation'] === $generation
        && (int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === 1, 'Restoring builtin changed the disabled mode or cleared its cache.');
    exit;
}
if ($scenario === 'restore-builtin' || $scenario === 'delete-upload') {
    sce_change_lookup_context('local', $uploadDatabase);
    sce_builtin_test_assert(sce_location_for_ip('81.2.69.160', true) === '英国', 'Uploaded database result could not be cached.');
    $generation = (string)sce_settings()['lookup_generation'];
    $uploadedHtml = sce_render_comment_meta('', ['comment' => ['ip_address' => '81.2.69.160']]);
    sce_builtin_test_assert(!str_contains($uploadedHtml, 'https://db-ip.com/'), 'Uploaded database was attributed to DB-IP.');
    if ($scenario === 'restore-builtin') {
        sce_select_builtin_geo_database();
        sce_builtin_test_assert(is_file($uploadPath), 'Selecting builtin deleted the uploaded database.');
    } else {
        sce_builtin_test_assert(sce_remove_selected_local_geo_database() && !is_file($uploadPath), 'Uploaded database was not removed.');
    }
    sce_builtin_test_assert(sce_lookup_mode() === 'local' && (string)sce_settings()['local_geo_database'] === SCE_BUILTIN_GEO_DATABASE,
        'Restoring builtin or deleting an upload disabled local lookup.');
    sce_builtin_test_assert((string)sce_settings()['lookup_generation'] !== $generation
        && (int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === 0, 'Changing the active database retained uploaded lookup results.');
    sce_builtin_test_assert(sce_location_for_ip('223.5.5.5', true) === '中国 · 浙江', 'Restored builtin lookup failed.');
    $builtinHtml = sce_render_comment_meta('', ['comment' => ['ip_address' => '223.5.5.5']]);
    sce_builtin_test_assert(str_contains($builtinHtml, 'href="https://db-ip.com/"'), 'Restored builtin result is missing visible attribution.');
    if ($scenario === 'delete-upload') {
        $rejected = false;
        try {
            sce_remove_selected_local_geo_database();
        } catch (DomainException $exception) {
            $rejected = $exception->getMessage() === 'database_builtin_protected';
        }
        sce_builtin_test_assert($rejected && is_file(sce_local_geo_builtin_database_path()) && sce_lookup_mode() === 'local', 'Direct deletion did not protect the builtin database.');
    } else {
        sce_change_lookup_context('online', $uploadDatabase);
        $generation = (string)sce_settings()['lookup_generation'];
        sce_store_location(sce_cache_key_for_ip('8.8.8.8'), '{"country_code":"US","country":"United States","region":"California"}', 'ok', time(), time());
        sce_select_builtin_geo_database();
        sce_builtin_test_assert(sce_lookup_mode() === 'online' && (string)sce_settings()['lookup_generation'] === $generation
            && (string)sce_settings()['cache_source_database'] === '' && (int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === 1,
            'Selecting builtin changed online mode, cache, or source.');
    }
    exit;
}
if ($scenario === 'fresh') {
    sce_builtin_test_assert(sce_lookup_mode() === 'local', 'Fresh install did not enable local lookup.');
    sce_builtin_test_assert($settings['local_geo_database'] === SCE_BUILTIN_GEO_DATABASE, 'Fresh install did not select the builtin database.');
    db()->exec("CREATE TABLE comments(id INTEGER PRIMARY KEY, ip_address TEXT NOT NULL DEFAULT '')");
    q('INSERT INTO comments(id, ip_address) VALUES(1, ?)', ['223.5.5.5']);
    sce_cache_new_comment_location(['comment_id' => 1]);
    sce_builtin_test_assert(sce_location_for_ip('223.5.5.5', false) === '中国 · 浙江', 'Fresh comment cannot show a Chinese province.');
    sce_builtin_test_assert((int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === 1, 'Fresh local lookup was not cached.');
    $before = (int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache');
    sce_builtin_test_assert(sce_location_for_ip('8.8.4.4', false) === '未知地区', 'Public cached-only display returned an unexpected value.');
    sce_builtin_test_assert((int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === $before, 'Public cached-only display performed a lookup.');
    $html = sce_render_comment_meta('', ['comment' => ['ip_address' => '223.5.5.5']]);
    sce_builtin_test_assert(str_contains($html, '中国 · 浙江') && str_contains($html, 'href="https://db-ip.com/"')
        && str_contains($html, 'IP Geolocation by DB-IP'), 'Public comment lacks a builtin location or visible DB-IP attribution.');
} elseif (str_starts_with($scenario, 'legacy-')) {
    sce_builtin_test_assert(sce_lookup_mode() === $mode, 'Upgrade changed the previously selected lookup mode.');
    if ($scenario === 'legacy-upload') {
        sce_builtin_test_assert($settings['local_geo_database'] === $uploadDatabase && is_file($uploadPath), 'Upgrade replaced or removed an uploaded database.');
        $record = json_decode(sce_lookup_configured_ip('2001:218::'), true, 8, JSON_THROW_ON_ERROR);
        sce_builtin_test_assert(($record['country_code'] ?? '') === 'JP', 'Upgrade no longer uses the uploaded database.');
        sce_location_for_ip('81.2.69.160', true);
        $html = sce_render_comment_meta('', ['comment' => ['ip_address' => '81.2.69.160']]);
        sce_builtin_test_assert(!str_contains($html, 'https://db-ip.com/'), 'Uploaded database result was attributed to DB-IP.');
    } elseif ($scenario === 'legacy-online') {
        sce_store_location(sce_cache_key_for_ip('8.8.8.8'), '{"country_code":"US","country":"United States","region":"California"}', 'ok', time(), time());
        $html = sce_render_comment_meta('', ['comment' => ['ip_address' => '8.8.8.8']]);
        sce_builtin_test_assert(str_contains($html, '美国') && !str_contains($html, 'https://db-ip.com/'), 'Online result was attributed to DB-IP.');
    }
} else {
    $usesBuiltinCache = in_array($scenario, ['local-builtin-version', 'off-builtin-version'], true);
    $generation = (string)$settings['lookup_generation'];
    sce_builtin_test_assert(sce_lookup_mode() === $mode && $settings['local_geo_database'] === $database, 'Database update changed the lookup mode or selection.');
    sce_builtin_test_assert(($settings['builtin_geo_version'] ?? '') === SCE_BUILTIN_GEO_VERSION, 'Builtin version stamp was not updated.');
    sce_builtin_test_assert(($generation !== str_repeat('b', 32)) === $usesBuiltinCache, 'Database update rotated the wrong lookup generation.');
    sce_builtin_test_assert((int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === ($usesBuiltinCache ? 0 : 1), 'Database update invalidated the wrong cache.');
    if ($usesBuiltinCache) {
        sce_builtin_test_assert((string)$settings['backfill_cursor'] === (string)PHP_INT_MAX, 'Database update did not reset historical backfill.');
        sce_builtin_test_assert(!sce_store_lookup_result_if_current('local', str_repeat('b', 32), 'stale-write', '', time(), false), 'Old lookup generation can repopulate the cache.');
    } else {
        sce_builtin_test_assert((string)$settings['backfill_cursor'] === '123', 'Unrelated database update reset historical backfill.');
    }
    sce_sync_builtin_geo_version();
    sce_builtin_test_assert((string)sce_settings()['lookup_generation'] === $generation, 'Repeated version synchronization invalidated the cache again.');
    sce_builtin_test_assert((int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === ($usesBuiltinCache ? 0 : 1), 'Repeated version synchronization modified the cache.');
}
