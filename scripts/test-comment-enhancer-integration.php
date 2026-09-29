<?php

declare(strict_types=1);

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sblog-comment-enhancer-integration-' . bin2hex(random_bytes(6));
if (!mkdir($testRoot, 0700, true) && !is_dir($testRoot)) {
    throw new RuntimeException('Unable to create the comment-enhancer integration test directory.');
}

define('PLUGINS_DIR', dirname(__DIR__) . '/plugins');
define('DATA_DIR', $testRoot . DIRECTORY_SEPARATOR . 'data');

$GLOBALS['sce_integration_db'] = new PDO('sqlite::memory:');
$GLOBALS['sce_integration_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['sce_integration_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function db(): PDO
{
    return $GLOBALS['sce_integration_db'];
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

function sblog_i18n_locale(): string
{
    return 'zh-CN';
}

function add_plugin_action(string $hook, callable $callback, int $priority = 10): void {}
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void {}
function add_theme_action(string $hook, callable $callback, int $priority = 10): void {}

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) {
        if (file_exists($path) || is_link($path)) {
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

db()->exec(
    "CREATE TABLE comment_enhancer_settings(
        name TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )"
);
db()->exec(
    "CREATE TABLE comment_enhancer_ip_cache(
        ip_hash TEXT PRIMARY KEY,
        location TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'failed',
        checked_at INTEGER NOT NULL DEFAULT 0,
        attempted_at INTEGER NOT NULL DEFAULT 0
    )"
);
$legacy = db()->prepare('INSERT INTO comment_enhancer_settings(name, value) VALUES(?, ?)');
foreach ([
    'schema_version' => '2',
    'cache_key_version' => '1',
    'cache_secret' => str_repeat('a', 64),
    'online_lookup' => '1',
] as $name => $value) {
    $legacy->execute([$name, $value]);
}
db()->exec("INSERT INTO comment_enhancer_ip_cache(ip_hash, location, status, checked_at, attempted_at) VALUES('legacy', '', 'failed', 0, 1)");
db()->exec("CREATE TABLE comments(id INTEGER PRIMARY KEY, ip_address TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'approved')");

require dirname(__DIR__) . '/plugins/comment-enhancer/plugin.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

sce_install();
$assert(val('SELECT value FROM comment_enhancer_settings WHERE name = ?', ['schema_version']) === '3', 'Schema was not upgraded to version 3.');
$assert(val('SELECT value FROM comment_enhancer_settings WHERE name = ?', ['cache_key_version']) === '2', 'Cache key version was not upgraded.');
$assert(val('SELECT value FROM comment_enhancer_settings WHERE name = ?', ['lookup_mode']) === 'online', 'Legacy online lookup setting was not migrated.');
$assert(val('SELECT value FROM comment_enhancer_settings WHERE name = ?', ['online_lookup']) === false, 'Legacy online lookup setting was not removed.');
$assert((int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === 0, 'Legacy location cache was not cleared.');
$generation = (string)val('SELECT value FROM comment_enhancer_settings WHERE name = ?', ['lookup_generation']);
$assert(preg_match('/^[a-f0-9]{32}$/', $generation) === 1, 'Lookup generation is invalid.');

$mappedKey = sce_cache_key_for_ip('::ffff:81.2.69.160');
$assert($mappedKey !== '' && $mappedKey === sce_cache_key_for_ip('81.2.69.160'), 'Mapped IPv4 cache keys are not canonical.');

$fixture = __DIR__ . '/fixtures/GeoIP2-City-Test.mmdb';
$fixtureHash = hash_file('sha256', $fixture);
$assert(is_string($fixtureHash), 'MMDB fixture hash failed.');
$database = 'geoip-' . substr($fixtureHash, 0, 32) . '.mmdb';
$databasePath = sce_local_geo_database_path($database);
$assert($databasePath !== '', 'Local database path is invalid.');
$assert(is_dir(dirname($databasePath)) || mkdir(dirname($databasePath), 0700, true), 'Local database directory could not be created.');
$assert(copy($fixture, $databasePath), 'MMDB fixture could not be installed.');

$oldGeneration = $generation;
sce_change_lookup_context('local', $database);
$localGeneration = (string)sce_settings()['lookup_generation'];
$assert($localGeneration !== $oldGeneration, 'Switching lookup source did not rotate the generation.');
$assert((int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === 0, 'Switching lookup source did not clear the cache.');
$assert(json_decode(sce_lookup_configured_ip('2001:218::'), true)['country_code'] === 'JP', 'Configured local IPv6 lookup failed.');

$GLOBALS['sce_location_memory'] = [];
$location = sce_location_for_ip('81.2.69.160', true);
$assert($location === '英国', 'Local lookup was not formatted for display.');
$cached = one('SELECT status, location FROM comment_enhancer_ip_cache WHERE ip_hash = ?', [sce_cache_key_for_ip('81.2.69.160')]);
$assert(($cached['status'] ?? '') === 'ok', 'Local lookup result was not cached.');
$assert((json_decode((string)($cached['location'] ?? ''), true)['country_code'] ?? '') === 'GB', 'Cached local lookup result is invalid.');

$localKey = sce_cache_key_for_ip('81.2.69.160');
sce_change_lookup_context('off', $database);
$assert(sce_cache_key_for_ip('81.2.69.160') === $localKey, 'Disabling lookup unexpectedly rotated cache keys.');
$assert((int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === 1, 'Disabling lookup unexpectedly cleared the cache.');
$GLOBALS['sce_location_memory'] = [];
$assert(sce_location_for_ip('81.2.69.160', true) === '英国', 'Disabled lookup did not preserve the cached location.');

$GLOBALS['sce_location_memory'] = [];
$before = (int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache');
$assert(sce_location_for_ip('8.8.8.8', true) === '未知地区', 'Disabled lookup did not return the unknown label.');
$assert((int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === $before, 'Disabled lookup created a cache entry.');

$pausedGeneration = (string)sce_settings()['lookup_generation'];
sce_change_lookup_context('off', '');
$assert((string)sce_settings()['lookup_generation'] === $pausedGeneration, 'Changing an inactive database rotated the generation.');
$assert((int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === $before, 'Changing an inactive database cleared cached locations.');
sce_clear_location_cache();
$assert((string)sce_settings()['lookup_generation'] !== $pausedGeneration, 'Clearing the cache did not rotate the generation.');
$assert((int)val('SELECT COUNT(*) FROM comment_enhancer_ip_cache') === 0, 'Clearing the cache did not remove cached locations.');
$assert(!sce_lookup_context_is_current('local', $pausedGeneration), 'An old lookup context remained current after clearing the cache.');

$invalidModeRejected = false;
try {
    sce_change_lookup_context('remote', $database);
} catch (DomainException) {
    $invalidModeRejected = true;
}
$assert($invalidModeRejected, 'Invalid lookup mode was accepted.');

echo "comment-enhancer integration tests passed" . PHP_EOL;
