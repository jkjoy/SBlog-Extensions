<?php
declare(strict_types=1);

const SBLOG_GALLERY_THUMBNAIL_WIDTHS = [320, 640, 960];
const SBLOG_GALLERY_THUMBNAIL_MAX_PIXELS = 24000000;
const SBLOG_GALLERY_THUMBNAIL_MAX_BYTES = 31457280;

function gallery_thumbnail_width(mixed $value): ?int
{
    if (!is_scalar($value) || preg_match('/^[0-9]+$/D', (string)$value) !== 1) {
        return null;
    }
    $width = (int)$value;
    return in_array($width, SBLOG_GALLERY_THUMBNAIL_WIDTHS, true) ? $width : null;
}

/** Resolve only real image files inside the local upload directory. Never fetch remote URLs. */
function gallery_local_thumbnail_source(array $media): ?array
{
    $relative = $media['local_path'] ?? '';
    if (!is_string($relative) || !defined('UPLOAD_DIR') || preg_match('/[\x00-\x1f\x7f]/', $relative)) {
        return null;
    }
    $relative = str_replace('\\', '/', trim($relative));
    if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, ':')
        || str_contains($relative, "\0") || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $relative)) {
        return null;
    }
    $root = realpath(UPLOAD_DIR);
    $candidate = function_exists('media_local_file') ? media_local_file($relative) : UPLOAD_DIR . '/' . $relative;
    $file = is_string($candidate) ? realpath($candidate) : false;
    if ($root === false || $file === false || !is_file($file) || !is_readable($file)
        || !str_starts_with($file, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return null;
    }
    $size = filesize($file);
    if ($size === false || $size < 1 || $size > SBLOG_GALLERY_THUMBNAIL_MAX_BYTES) {
        return null;
    }
    $info = @getimagesize($file);
    $decoders = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_GIF => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    if (!is_array($info) || !isset($decoders[$info[2]]) || (int)$info[0] < 1 || (int)$info[1] < 1
        || (int)$info[0] > 20000 || (int)$info[1] > 20000
        || (int)$info[0] * (int)$info[1] > SBLOG_GALLERY_THUMBNAIL_MAX_PIXELS) {
        return null;
    }
    // Preserve animated GIFs by using the original image rather than flattening its first frame.
    if ((int)$info[2] === IMAGETYPE_GIF) {
        return null;
    }
    return [
        'path' => $file,
        'width' => (int)$info[0],
        'height' => (int)$info[1],
        'type' => (int)$info[2],
        'decoder' => $decoders[$info[2]],
        'size' => $size,
        'mtime' => (int)filemtime($file),
    ];
}

function gallery_thumbnail_can_generate(array $source, int $width): bool
{
    if (!in_array($width, SBLOG_GALLERY_THUMBNAIL_WIDTHS, true)
        || !function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')
        || !function_exists('imagepng') || !function_exists((string)$source['decoder'])) {
        return false;
    }
    if ((int)($source['type'] ?? 0) === IMAGETYPE_JPEG && !function_exists('exif_read_data')) {
        // Original JPEGs retain browser orientation support when EXIF cannot be inspected here.
        return false;
    }
    $memoryLimit = trim((string)ini_get('memory_limit'));
    if ($memoryLimit === '-1' || $memoryLimit === '') {
        return true;
    }
    $limit = (int)$memoryLimit;
    $suffix = strtolower(substr($memoryLimit, -1));
    $limit *= match ($suffix) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
    // Allow room for decoding, EXIF rotation, a destination image, and GD's overhead.
    $required = (int)$source['width'] * (int)$source['height'] * 12 + $width * $width * 8 + 8388608;
    return $limit > 0 && memory_get_usage(true) + $required < $limit;
}

function gallery_thumbnail_url(array $item, int $width = 640): string
{
    $original = gallery_safe_media_url((string)($item['url'] ?? ''));
    $id = (int)($item['id'] ?? 0);
    $source = $id > 0 ? gallery_local_thumbnail_source($item) : null;
    if ($original === '' || $source === null || !gallery_thumbnail_can_generate($source, $width)
        || max($source['width'], $source['height']) <= $width) {
        return $original;
    }
    return gallery_action_url('gallery_thumbnail', ['id' => $id, 'w' => $width]);
}

function gallery_category_thumbnail_url(array $category): string
{
    $original = gallery_safe_media_url((string)($category['cover_url'] ?? ''));
    $source = gallery_local_thumbnail_source(['local_path' => $category['cover_local_path'] ?? '']);
    $categoryId = (int)($category['id'] ?? 0);
    if ($original === '' || $categoryId < 1 || $source === null || !gallery_thumbnail_can_generate($source, 320)
        || max($source['width'], $source['height']) <= 320) {
        return $original;
    }
    return gallery_action_url('gallery_thumbnail', ['category_id' => $categoryId, 'w' => 320]);
}

/** Recheck publication on every request, including when a cached thumbnail already exists. */
function gallery_thumbnail_public_item(int $itemId): ?array
{
    if ($itemId < 1) {
        return null;
    }
    return one(
        "SELECT gi.id, gi.media_id, m.url, m.local_path, m.mime_type, m.width, m.height
         FROM sblog_gallery_items gi JOIN media m ON m.id = gi.media_id
         WHERE gi.id = ? AND gi.status = 'published' AND m.is_image = 1",
        [$itemId]
    );
}

function gallery_thumbnail_public_category(int $categoryId): ?array
{
    if ($categoryId < 1) {
        return null;
    }
    $category = one(
        "SELECT c.id, c.cover_media_id AS media_id, m.url, m.local_path, m.mime_type, m.width, m.height
         FROM sblog_gallery_categories c JOIN media m ON m.id = c.cover_media_id
         WHERE c.id = ? AND m.is_image = 1 AND EXISTS (
             SELECT 1 FROM sblog_gallery_items gi JOIN media published_media ON published_media.id = gi.media_id
             WHERE gi.category_id = c.id AND gi.status = 'published' AND published_media.is_image = 1
         )",
        [$categoryId]
    );
    return $category !== null ? ['thumbnail_kind' => 'category'] + $category : null;
}

function gallery_thumbnail_request_target(array $query): ?array
{
    if (isset($query['id']) === isset($query['category_id'])) {
        return null;
    }
    $kind = isset($query['category_id']) ? 'category' : 'item';
    $value = $kind === 'category' ? $query['category_id'] : $query['id'];
    $width = gallery_thumbnail_width($query['w'] ?? 640);
    if (!is_scalar($value) || preg_match('/^[1-9][0-9]{0,17}$/D', (string)$value) !== 1 || $width === null) {
        return null;
    }
    return ['kind' => $kind, 'id' => (int)$value, 'width' => $width];
}

function gallery_thumbnail_cache_directory(): ?string
{
    if (!defined('CACHE_DIR')) {
        return null;
    }
    $directory = CACHE_DIR . '/gallery-thumbnails';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return null;
    }
    $cacheRoot = realpath(CACHE_DIR);
    $resolved = realpath($directory);
    if ($cacheRoot === false || $resolved === false
        || !str_starts_with($resolved, rtrim($cacheRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        || !is_writable($resolved)) {
        return null;
    }
    return $resolved;
}

function gallery_thumbnail_orient_image(GdImage $image, string $file): GdImage
{
    if (!function_exists('exif_read_data')) {
        return $image;
    }
    $exif = @exif_read_data($file, 'IFD0', true, false);
    $orientation = is_array($exif) ? (int)($exif['IFD0']['Orientation'] ?? 1) : 1;
    if (in_array($orientation, [2, 4, 5, 7], true)) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
    }
    $angle = match ($orientation) { 3, 4 => 180, 6, 7 => -90, 5, 8 => 90, default => 0 };
    if ($angle !== 0) {
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated instanceof GdImage) {
            imagedestroy($image);
            return $rotated;
        }
    }
    return $image;
}

function gallery_generate_thumbnail(array $item, array $source, int $width): ?array
{
    if (!gallery_thumbnail_can_generate($source, $width)) {
        return null;
    }
    $directory = gallery_thumbnail_cache_directory();
    if ($directory === null) {
        return null;
    }
    $useWebp = function_exists('imagewebp');
    $extension = $useWebp ? 'webp' : 'png';
    $key = hash('sha256', implode('|', [
        '2', $item['thumbnail_kind'] ?? 'item', (int)$item['id'], (int)$item['media_id'], $source['path'], $source['size'], $source['mtime'], $width, $extension,
    ]));
    $target = $directory . '/' . $key . '.' . $extension;
    if (is_file($target) && !is_link($target) && (int)filesize($target) > 0) {
        $cachedInfo = @getimagesize($target);
        if (is_array($cachedInfo) && (int)$cachedInfo[2] === ($useWebp ? IMAGETYPE_WEBP : IMAGETYPE_PNG)
            && (int)$cachedInfo[0] > 0 && (int)$cachedInfo[1] > 0 && max((int)$cachedInfo[0], (int)$cachedInfo[1]) <= $width) {
            return ['path' => $target, 'type' => $useWebp ? 'image/webp' : 'image/png'];
        }
        @unlink($target);
    }
    $original = null;
    $thumbnail = null;
    $temporary = null;
    try {
        $original = @call_user_func((string)$source['decoder'], $source['path']);
        if (!$original instanceof GdImage) {
            return null;
        }
        if ($source['type'] === IMAGETYPE_JPEG) {
            $original = gallery_thumbnail_orient_image($original, $source['path']);
        }
        $sourceWidth = imagesx($original);
        $sourceHeight = imagesy($original);
        $scale = min(1, $width / max($sourceWidth, $sourceHeight));
        $thumbnail = imagecreatetruecolor(max(1, (int)round($sourceWidth * $scale)), max(1, (int)round($sourceHeight * $scale)));
        if (!$thumbnail instanceof GdImage) {
            return null;
        }
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        imagefill($thumbnail, 0, 0, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
        if (!imagecopyresampled($thumbnail, $original, 0, 0, 0, 0, imagesx($thumbnail), imagesy($thumbnail), $sourceWidth, $sourceHeight)) {
            return null;
        }
        $temporary = tempnam($directory, 'thumb-');
        if ($temporary === false) {
            $temporary = null;
            return null;
        }
        $written = $useWebp ? imagewebp($thumbnail, $temporary, 82) : imagepng($thumbnail, $temporary, 6);
        if (!$written || (int)filesize($temporary) < 1 || !@rename($temporary, $target)) {
            return null;
        }
        $temporary = null;
        @chmod($target, 0600);
        gallery_prune_thumbnail_cache($directory);
        return ['path' => $target, 'type' => $useWebp ? 'image/webp' : 'image/png'];
    } catch (Throwable $exception) {
        error_log('Gallery thumbnail failed: ' . $exception->getMessage());
        return null;
    } finally {
        if ($original instanceof GdImage) { imagedestroy($original); }
        if ($thumbnail instanceof GdImage) { imagedestroy($thumbnail); }
        if (is_string($temporary) && is_file($temporary)) { @unlink($temporary); }
    }
}

function gallery_prune_thumbnail_cache(string $directory): void
{
    $files = array_merge(glob($directory . '/*.webp') ?: [], glob($directory . '/*.png') ?: []);
    $bytes = 0;
    foreach ($files as $file) {
        if (!is_link($file)) { $bytes += (int)filesize($file); }
    }
    if (count($files) <= 1000 && $bytes <= 134217728) {
        return;
    }
    usort($files, static fn(string $left, string $right): int => (int)filemtime($left) <=> (int)filemtime($right));
    $entries = count($files);
    foreach ($files as $file) {
        if ($entries <= 1000 && $bytes <= 134217728) { break; }
        if (!is_link($file) && preg_match('/^[a-f0-9]{64}\.(?:webp|png)$/D', basename($file))) {
            $size = (int)filesize($file);
            @unlink($file);
            if (!is_file($file)) { $entries--; $bytes -= $size; }
        }
    }
}

function gallery_handle_thumbnail_request(): never
{
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
    $request = gallery_thumbnail_request_target($_GET);
    if ($request === null) {
        http_response_code(400);
        exit;
    }
    $item = $request['kind'] === 'category'
        ? gallery_thumbnail_public_category($request['id'])
        : gallery_thumbnail_public_item($request['id']);
    if ($item === null) {
        http_response_code(404);
        exit;
    }
    $source = gallery_local_thumbnail_source($item);
    $thumbnail = $source !== null ? gallery_generate_thumbnail($item, $source, $request['width']) : null;
    if ($thumbnail === null) {
        $url = gallery_safe_media_url((string)$item['url']);
        if ($url !== '') {
            header('Location: ' . $url, true, 302);
        } else {
            http_response_code(404);
        }
        exit;
    }
    header('Content-Type: ' . $thumbnail['type']);
    header('Content-Length: ' . (int)filesize($thumbnail['path']));
    if ($method !== 'HEAD') {
        readfile($thumbnail['path']);
    }
    exit;
}
