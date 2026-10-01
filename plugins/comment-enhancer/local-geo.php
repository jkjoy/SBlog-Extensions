<?php

declare(strict_types=1);

const SCE_LOCAL_GEO_MAX_BYTES = 134217728;
const SCE_BUILTIN_GEO_DATABASE = 'builtin';
const SCE_BUILTIN_GEO_VERSION = '2026-10';
const SCE_BUILTIN_GEO_SHA256 = '9e250f02722d1ad1780f88192f0e908af285478a33e76b88b349c9181ae9d0f5';
const SCE_BUILTIN_GEO_BYTES = 126998165;

function sce_load_mmdb_reader(): void
{
    if (class_exists(\MaxMind\Db\Reader::class, false)) {
        return;
    }

    $source = __DIR__ . '/vendor/maxmind-db-reader/src/MaxMind/Db';
    $files = [
        $source . '/Reader/InvalidDatabaseException.php',
        $source . '/Reader/Util.php',
        $source . '/Reader/Decoder.php',
        $source . '/Reader/Metadata.php',
        $source . '/Reader.php',
    ];
    foreach ($files as $file) {
        if (!is_file($file)) {
            throw new RuntimeException('The bundled MaxMind DB reader is incomplete.');
        }
        require_once $file;
    }
}

function sce_local_geo_directory(): string
{
    if (!defined('DATA_DIR')) {
        return '';
    }

    $dataDirectory = (string)constant('DATA_DIR');
    if ($dataDirectory === '') {
        return '';
    }

    return $dataDirectory
        . (preg_match('~[\\\\/]\z~', $dataDirectory) === 1 ? '' : DIRECTORY_SEPARATOR)
        . 'comment-enhancer';
}

function sce_local_geo_database_path(?string $basename = null): string
{
    if ($basename === null
        || preg_match('/\Ageoip-[a-f0-9]{32}\.mmdb\z/', $basename) !== 1) {
        return '';
    }

    $directory = sce_local_geo_directory();
    return $directory === '' ? '' : $directory . DIRECTORY_SEPARATOR . $basename;
}

function sce_local_geo_builtin_database_path(): string
{
    $directory = sce_local_geo_directory();
    return $directory === '' ? '' : $directory . '/builtin/dbip-city-lite-' . SCE_BUILTIN_GEO_VERSION . '.mmdb';
}

function sce_local_geo_builtin_source_path(): string
{
    return __DIR__ . '/resources/geo/dbip-city-lite.mmdb.gz';
}

function sce_local_geo_builtin_file_ready(string $path): bool
{
    if (!sce_local_geo_database_directory_safe($path, SCE_BUILTIN_GEO_DATABASE)
        || is_link($path) || !is_file($path) || !is_readable($path)) {
        return false;
    }
    clearstatcache(true, $path);
    return @filesize($path) === SCE_BUILTIN_GEO_BYTES;
}

function sce_local_geo_prepare_builtin_database(): string
{
    $path = sce_local_geo_builtin_database_path();
    if ($path === '' || !sce_local_geo_database_directory_safe($path, SCE_BUILTIN_GEO_DATABASE)
        || is_link($path)) {
        return '';
    }
    if (sce_local_geo_builtin_file_ready($path)) {
        return $path;
    }

    try {
        return sce_with_local_geo_lock(static function () use ($path): string {
            if (!sce_local_geo_database_directory_safe($path, SCE_BUILTIN_GEO_DATABASE)
                || is_link($path) || (file_exists($path) && !is_file($path))) {
                return '';
            }
            if (sce_local_geo_builtin_file_ready($path)) {
                return $path;
            }

            $source = sce_local_geo_builtin_source_path();
            if (!function_exists('gzopen') || is_link(__DIR__ . '/resources')
                || is_link(dirname($source)) || is_link($source)
                || !is_file($source) || !is_readable($source)) {
                return '';
            }
            clearstatcache(true, $source);
            $sourceSize = @filesize($source);
            if (!is_int($sourceSize) || $sourceSize < 1 || $sourceSize > SCE_LOCAL_GEO_MAX_BYTES) {
                return '';
            }

            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0755) && !is_dir($directory)) {
                return '';
            }
            if (is_link($directory) || !is_writable($directory)) {
                return '';
            }
            $temporary = $directory . '/.dbip-extract-' . bin2hex(random_bytes(16)) . '.tmp';
            $input = null;
            $output = null;
            try {
                $input = @gzopen($source, 'rb');
                $output = @fopen($temporary, 'xb');
                if (!is_resource($input) || !is_resource($output)) {
                    return '';
                }
                $hash = hash_init('sha256');
                $size = 0;
                while (!gzeof($input)) {
                    $chunk = @gzread($input, 1048576);
                    if (!is_string($chunk) || ($chunk === '' && !gzeof($input))) {
                        return '';
                    }
                    $length = strlen($chunk);
                    $size += $length;
                    if ($size > SCE_BUILTIN_GEO_BYTES || $size > SCE_LOCAL_GEO_MAX_BYTES
                        || @fwrite($output, $chunk) !== $length) {
                        return '';
                    }
                    hash_update($hash, $chunk);
                }
                if ($size !== SCE_BUILTIN_GEO_BYTES
                    || !hash_equals(SCE_BUILTIN_GEO_SHA256, hash_final($hash))
                    || !@fflush($output)) {
                    return '';
                }
                fclose($output);
                $output = null;
                gzclose($input);
                $input = null;
                if (!sce_local_geo_database_directory_safe($path, SCE_BUILTIN_GEO_DATABASE)
                    || is_link($path) || !@rename($temporary, $path)) {
                    return '';
                }
                return sce_local_geo_builtin_file_ready($path) ? $path : '';
            } finally {
                if (is_resource($output)) {
                    fclose($output);
                }
                if (is_resource($input)) {
                    gzclose($input);
                }
                if (is_file($temporary) || is_link($temporary)) {
                    @unlink($temporary);
                }
            }
        });
    } catch (Throwable) {
        return '';
    }
}

function sce_local_geo_lookup_path(?string $basename = null): string
{
    return $basename === SCE_BUILTIN_GEO_DATABASE
        ? sce_local_geo_prepare_builtin_database()
        : sce_local_geo_database_path($basename);
}

function sce_local_geo_database_directory_safe(string $path, ?string $basename = null): bool
{
    if ($path === '' || is_link(dirname($path))) {
        return false;
    }
    return $basename !== SCE_BUILTIN_GEO_DATABASE || !is_link(sce_local_geo_directory());
}

function sce_local_geo_empty_status(string $basename = '', string $error = ''): array
{
    return [
        'valid' => false,
        'database_type' => '',
        'ip_version' => 0,
        'supports_ipv4' => false,
        'supports_ipv6' => false,
        'build_epoch' => 0,
        'size' => 0,
        'basename' => $basename,
        'error' => $error,
    ];
}

function sce_local_geo_reader_status(\MaxMind\Db\Reader $reader, int $size, string $basename = ''): array
{
    $status = sce_local_geo_empty_status($basename);
    $status['size'] = $size;
    $metadata = $reader->metadata();
    $databaseType = trim((string)$metadata->databaseType);
    $ipVersion = (int)$metadata->ipVersion;
    $status['database_type'] = $databaseType;
    $status['ip_version'] = $ipVersion;
    $status['supports_ipv4'] = $ipVersion === 4 || $ipVersion === 6;
    $status['supports_ipv6'] = $ipVersion === 6;
    $status['build_epoch'] = (int)$metadata->buildEpoch;

    if (stripos($databaseType, 'City') === false
        && stripos($databaseType, 'Country') === false) {
        $status['error'] = 'unsupported_database_type';
        return $status;
    }
    if ($ipVersion !== 4 && $ipVersion !== 6) {
        $status['error'] = 'unsupported_ip_version';
        return $status;
    }

    $status['valid'] = true;
    return $status;
}

function sce_local_geo_inspect_file(string $path, string $basename = ''): array
{
    $status = sce_local_geo_empty_status($basename);
    if ($path === '' || is_link($path) || !is_file($path)) {
        $status['error'] = 'database_not_found';
        return $status;
    }
    if (!is_readable($path)) {
        $status['error'] = 'database_not_readable';
        return $status;
    }

    clearstatcache(true, $path);
    $size = @filesize($path);
    if (!is_int($size) || $size < 1) {
        $status['error'] = 'database_empty';
        return $status;
    }
    $status['size'] = $size;
    if ($size > SCE_LOCAL_GEO_MAX_BYTES) {
        $status['error'] = 'database_too_large';
        return $status;
    }

    $reader = null;
    try {
        sce_load_mmdb_reader();
        $reader = new \MaxMind\Db\Reader($path);
        return sce_local_geo_reader_status($reader, $size, $basename);
    } catch (Throwable) {
        $status['error'] = 'invalid_database';
        return $status;
    } finally {
        if ($reader instanceof \MaxMind\Db\Reader) {
            try {
                $reader->close();
            } catch (Throwable) {
            }
        }
    }
}

function sce_local_geo_database_status(?string $basename = null): array
{
    if ($basename === null || $basename === '') {
        return sce_local_geo_empty_status('', 'database_not_selected');
    }

    $path = sce_local_geo_lookup_path($basename);
    if ($path === '') {
        if ($basename === SCE_BUILTIN_GEO_DATABASE) {
            return sce_local_geo_empty_status($basename, 'database_not_found');
        }
        return sce_local_geo_empty_status('', 'invalid_database_name');
    }

    if (!sce_local_geo_database_directory_safe($path, $basename)) {
        return sce_local_geo_empty_status($basename, 'unsafe_database_directory');
    }

    return sce_local_geo_inspect_file($path, $basename);
}

function sce_local_geo_record_name(mixed $value): string
{
    if (!is_array($value)) {
        return '';
    }
    $names = $value['names'] ?? null;
    if (!is_array($names)) {
        return '';
    }

    foreach (['en', 'zh-CN', 'zh'] as $language) {
        if (is_string($names[$language] ?? null) && trim($names[$language]) !== '') {
            return $names[$language];
        }
    }
    foreach ($names as $name) {
        if (is_string($name) && trim($name) !== '') {
            return $name;
        }
    }

    return '';
}

function sce_local_geo_record_cache_value(array $record): string
{
    if (!function_exists('sce_geo_cache_value')) {
        return '';
    }

    $country = is_array($record['country'] ?? null) ? $record['country'] : [];
    if ($country === [] && is_array($record['registered_country'] ?? null)) {
        $country = $record['registered_country'];
    }
    $subdivisions = is_array($record['subdivisions'] ?? null) ? $record['subdivisions'] : [];
    $subdivision = is_array($subdivisions[0] ?? null) ? $subdivisions[0] : [];

    return sce_geo_cache_value([
        'success' => true,
        'country_code' => is_string($country['iso_code'] ?? null) ? $country['iso_code'] : '',
        'country' => sce_local_geo_record_name($country),
        'region' => sce_local_geo_record_name($subdivision),
    ]);
}

function sce_lookup_local_ip(string $ip, ?string $basename = null): string
{
    $canonicalIp = function_exists('sce_canonical_ip') ? sce_canonical_ip($ip) : trim($ip);
    $packed = @inet_pton($canonicalIp);
    if (!is_string($packed)) {
        return '';
    }
    if (strlen($packed) === 16
        && substr($packed, 0, 10) === str_repeat("\0", 10)
        && substr($packed, 10, 2) === "\xff\xff") {
        $packed = substr($packed, 12);
    }
    $normalizedIp = @inet_ntop($packed);
    if (!is_string($normalizedIp)) {
        return '';
    }

    $path = sce_local_geo_lookup_path($basename);
    if (!sce_local_geo_database_directory_safe($path, $basename)
        || is_link($path) || !is_file($path) || !is_readable($path)) {
        return '';
    }
    clearstatcache(true, $path);
    $size = @filesize($path);
    if (!is_int($size) || $size < 1 || $size > SCE_LOCAL_GEO_MAX_BYTES) {
        return '';
    }

    $reader = null;
    try {
        sce_load_mmdb_reader();
        $reader = new \MaxMind\Db\Reader($path);
        $status = sce_local_geo_reader_status($reader, $size, (string)$basename);
        if (!$status['valid'] || (strlen($packed) === 16 && !$status['supports_ipv6'])) {
            return '';
        }
        $record = $reader->get($normalizedIp);
        return is_array($record) ? sce_local_geo_record_cache_value($record) : '';
    } catch (Throwable) {
        return '';
    } finally {
        if ($reader instanceof \MaxMind\Db\Reader) {
            try {
                $reader->close();
            } catch (Throwable) {
            }
        }
    }
}

function sce_local_geo_upload_error(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'upload_server_limit',
        UPLOAD_ERR_NO_FILE => 'upload_missing',
        default => 'upload_failed',
    };
}

function sce_local_geo_ensure_directory(): string
{
    $directory = sce_local_geo_directory();
    if ($directory === '') {
        throw new DomainException('data_directory_unavailable');
    }
    if (is_link($directory)) {
        throw new DomainException('data_directory_unsafe');
    }
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new DomainException('data_directory_create_failed');
    }
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new DomainException('data_directory_not_writable');
    }

    return $directory;
}

function sce_with_local_geo_lock(callable $callback): mixed
{
    if ((int)($GLOBALS['sce_local_geo_lock_depth'] ?? 0) > 0) {
        return $callback();
    }
    $directory = sce_local_geo_ensure_directory();
    $lockPath = $directory . DIRECTORY_SEPARATOR . '.database-operation.lock';
    $stream = @fopen($lockPath, 'c+b');
    if ($stream === false) {
        throw new DomainException('database_lock_failed');
    }
    try {
        if (!flock($stream, LOCK_EX)) {
            throw new DomainException('database_lock_failed');
        }
        $GLOBALS['sce_local_geo_lock_depth'] = 1;
        return $callback();
    } finally {
        unset($GLOBALS['sce_local_geo_lock_depth']);
        @flock($stream, LOCK_UN);
        fclose($stream);
    }
}

function sce_with_optional_local_geo_lock(callable $callback): mixed
{
    if ((int)($GLOBALS['sce_local_geo_lock_depth'] ?? 0) > 0) {
        return $callback();
    }
    try {
        $directory = sce_local_geo_ensure_directory();
    } catch (DomainException $exception) {
        if (!in_array($exception->getMessage(), [
            'data_directory_unavailable',
            'data_directory_unsafe',
            'data_directory_create_failed',
            'data_directory_not_writable',
        ], true)) {
            throw $exception;
        }
        return $callback();
    }
    $stream = @fopen($directory . DIRECTORY_SEPARATOR . '.database-operation.lock', 'c+b');
    if ($stream === false) {
        return $callback();
    }
    try {
        if (!flock($stream, LOCK_EX)) {
            return $callback();
        }
        $GLOBALS['sce_local_geo_lock_depth'] = 1;
        return $callback();
    } finally {
        unset($GLOBALS['sce_local_geo_lock_depth']);
        @flock($stream, LOCK_UN);
        fclose($stream);
    }
}

function sce_install_local_geo_database(array $upload, ?string $oldBasename = null): array
{
    $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!is_int($uploadError)) {
        throw new DomainException('upload_invalid');
    }
    $error = $uploadError;
    if ($error !== UPLOAD_ERR_OK) {
        throw new DomainException(sce_local_geo_upload_error($error));
    }

    $source = $upload['tmp_name'] ?? null;
    $originalName = $upload['name'] ?? null;
    if (!is_string($source) || !is_string($originalName) || !is_uploaded_file($source)) {
        throw new DomainException('upload_invalid');
    }
    $extension = strtolower(pathinfo(str_replace('\\', '/', $originalName), PATHINFO_EXTENSION));
    if ($extension !== 'mmdb') {
        throw new DomainException('upload_extension_invalid');
    }

    clearstatcache(true, $source);
    $size = @filesize($source);
    if (!is_int($size) || $size < 1 || $size > SCE_LOCAL_GEO_MAX_BYTES) {
        throw new DomainException('upload_size_invalid');
    }

    $directory = sce_local_geo_ensure_directory();
    $temporary = $directory . DIRECTORY_SEPARATOR . '.geoip-upload-' . bin2hex(random_bytes(16)) . '.tmp';
    if (!move_uploaded_file($source, $temporary)) {
        throw new DomainException('upload_store_failed');
    }

    try {
        $temporaryStatus = sce_local_geo_inspect_file($temporary);
        if (!$temporaryStatus['valid']) {
            throw new DomainException('upload_database_unsupported');
        }
        $hash = @hash_file('sha256', $temporary);
        if (!is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            throw new DomainException('upload_hash_failed');
        }

        $basename = 'geoip-' . bin2hex(random_bytes(16)) . '.mmdb';
        $target = sce_local_geo_database_path($basename);
        if ($target === '') {
            throw new DomainException('database_destination_invalid');
        }

        $installedNew = false;
        if (file_exists($target) || is_link($target)) {
            $targetHash = !is_link($target) && is_file($target) ? @hash_file('sha256', $target) : false;
            $targetStatus = sce_local_geo_database_status($basename);
            if (!is_string($targetHash) || !hash_equals($hash, $targetHash) || !$targetStatus['valid']) {
                throw new DomainException('database_conflict');
            }
            if (!@unlink($temporary)) {
                throw new DomainException('temporary_cleanup_failed');
            }
        } elseif (!@rename($temporary, $target)) {
            throw new DomainException('database_install_failed');
        } else {
            $installedNew = true;
        }

        $status = sce_local_geo_database_status($basename);
        if (!$status['valid']) {
            if ($installedNew) {
                @unlink($target);
            }
            throw new DomainException('database_verify_failed');
        }

        $oldPath = sce_local_geo_database_path($oldBasename);
        return [
            'basename' => $basename,
            'status' => $status,
            'error' => '',
            'old_basename' => $oldPath !== '' ? (string)$oldBasename : '',
            'old_path' => $oldPath,
            'new_path' => $target,
        ];
    } finally {
        if (is_file($temporary) || is_link($temporary)) {
            @unlink($temporary);
        }
    }
}

function sce_delete_local_geo_database(?string $basename = null): bool
{
    $path = sce_local_geo_database_path($basename);
    return $path !== ''
        && !is_link(dirname($path))
        && !is_link($path)
        && is_file($path)
        && @unlink($path);
}

function sce_local_geo_database_files(): array
{
    $directory = sce_local_geo_directory();
    if ($directory === '' || is_link($directory) || !is_dir($directory)) {
        return [];
    }

    $databases = [];
    foreach (scandir($directory) ?: [] as $basename) {
        if (preg_match('/\Ageoip-[a-f0-9]{32}\.mmdb\z/', $basename) !== 1) {
            continue;
        }
        $path = sce_local_geo_database_path($basename);
        if ($path !== '' && !is_link($path) && is_file($path)) {
            $databases[] = $basename;
        }
    }
    sort($databases, SORT_STRING);
    return $databases;
}

function sce_cleanup_local_geo_databases(?string $keepBasename = null): array
{
    $failed = [];
    $removed = 0;
    foreach (sce_local_geo_database_files() as $basename) {
        if ($basename === $keepBasename) {
            continue;
        }
        if (sce_delete_local_geo_database($basename)) {
            $removed++;
        } else {
            $failed[] = $basename;
        }
    }
    return ['removed' => $removed, 'failed' => $failed];
}
