<?php
declare(strict_types=1);

$temporary = sys_get_temp_dir() . '/sblog-gallery-media-' . bin2hex(random_bytes(6));
mkdir($temporary . '/uploads/2026', 0700, true);
mkdir($temporary . '/cache', 0700, true);
define('UPLOAD_DIR', $temporary . '/uploads');
define('CACHE_DIR', $temporary . '/cache');
$GLOBALS['gallery_media_test_db'] = new PDO('sqlite::memory:');
$GLOBALS['gallery_media_test_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['gallery_media_test_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function one(string $sql, array $parameters = []): ?array
{
    $statement = $GLOBALS['gallery_media_test_db']->prepare($sql);
    $statement->execute($parameters);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function media_local_file(string $relative): string
{
    // Deliberately omit confinement here so the plugin must also verify the resolved file itself.
    return UPLOAD_DIR . '/' . $relative;
}

function gallery_action_url(string $action, array $parameters = []): string
{
    return '/index.php?' . http_build_query(['a' => $action] + $parameters);
}

function gallery_setting(string $name, string $default = ''): string { return $default; }
function gallery_route_conflicts(string $slug): array { return []; }
function use_pretty_url(): bool { return false; }
function url_with_query(string $url, array $parameters): string { return $url . '&' . http_build_query($parameters); }
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function sblog_t(string $value): string { return $value; }

function safe_link_url(string $url): string
{
    return preg_match('#^(?:https?://|/)#i', $url) === 1 && !preg_match('/[\x00-\x1f\x7f]/', $url) ? $url : '#';
}

function gallery_media_test_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function gallery_media_test_remove(string $path): void
{
    if (is_link($path) || !is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') { gallery_media_test_remove($path . '/' . $entry); }
    }
    @rmdir($path);
}

require dirname(__DIR__) . '/plugins/gallery/includes/media.php';
require dirname(__DIR__) . '/plugins/gallery/includes/public.php';

try {
    $smallPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jW6kAAAAASUVORK5CYII=');
    file_put_contents(UPLOAD_DIR . '/2026/small.png', $smallPng);
    file_put_contents($temporary . '/outside.png', $smallPng);
    file_put_contents(UPLOAD_DIR . '/2026/invalid.png', 'This is not an image');
    file_put_contents(UPLOAD_DIR . '/2026/animated.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
    symlink($temporary . '/outside.png', UPLOAD_DIR . '/2026/escape.png');
    $local = ['local_path' => '2026/small.png'];
    gallery_media_test_assert(gallery_local_thumbnail_source($local) !== null, 'Local uploaded image was rejected');
    foreach (['../outside.png', '/outside.png', '2026/escape.png', '2026/invalid.png', "2026/small.png\0", 'https://example.com/image.png', '2026/missing.png'] as $path) {
        gallery_media_test_assert(gallery_local_thumbnail_source(['local_path' => $path]) === null, 'Unsafe or invalid thumbnail source accepted: ' . $path);
    }
    gallery_media_test_assert(gallery_local_thumbnail_source(['url' => 'https://example.com/image.png', 'local_path' => '']) === null, 'Remote-only image was used as a local thumbnail source');
    gallery_media_test_assert(gallery_local_thumbnail_source(['local_path' => '2026/animated.gif']) === null, 'GIF was flattened into a static thumbnail');
    gallery_media_test_assert(gallery_thumbnail_width('640') === 640, 'A supported width was rejected');
    foreach (['640x', '100000', '1', '400', [], null] as $width) {
        gallery_media_test_assert(gallery_thumbnail_width($width) === null, 'Invalid width accepted');
    }
    gallery_media_test_assert(gallery_thumbnail_request_target(['category_id' => '1', 'w' => 320])['kind'] === 'category', 'Category thumbnail request was not recognized');
    foreach ([[], ['id' => 1, 'category_id' => 1], ['id' => []], ['category_id' => '1oops'], ['id' => 1, 'w' => 99999]] as $query) {
        gallery_media_test_assert(gallery_thumbnail_request_target($query) === null, 'Invalid thumbnail request was accepted');
    }
    // getimagesize reads the PNG dimensions before decoding, so an excessive pixel budget is rejected early.
    $oversizeHeader = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NNCCCCC', 12000, 12000, 8, 2, 0, 0, 0) . pack('N', 0);
    file_put_contents(UPLOAD_DIR . '/2026/oversize.png', $oversizeHeader);
    gallery_media_test_assert(gallery_local_thumbnail_source(['local_path' => '2026/oversize.png']) === null, 'Excessive pixel budget was accepted');

    $database = $GLOBALS['gallery_media_test_db'];
    $database->exec("CREATE TABLE media(id INTEGER PRIMARY KEY, url TEXT, local_path TEXT, mime_type TEXT, width INTEGER, height INTEGER, is_image INTEGER)");
    $database->exec("CREATE TABLE sblog_gallery_items(id INTEGER PRIMARY KEY, media_id INTEGER, status TEXT, category_id INTEGER)");
    $database->exec("CREATE TABLE sblog_gallery_categories(id INTEGER PRIMARY KEY, cover_media_id INTEGER)");
    $database->exec("INSERT INTO media VALUES(1, '/uploads/2026/small.png', '2026/small.png', 'image/png', 1, 1, 1), (2, '/uploads/2026/invalid.png', '2026/invalid.png', 'text/plain', 0, 0, 0), (3, '/uploads/2026/small.png', '2026/small.png', 'image/png', 1, 1, 1)");
    $database->exec("INSERT INTO sblog_gallery_items VALUES(1, 1, 'published', 1), (2, 3, 'draft', 2), (3, 2, 'published', 3)");
    $database->exec("INSERT INTO sblog_gallery_categories VALUES(1, 1), (2, 3), (3, 2), (4, 1)");
    gallery_media_test_assert(gallery_thumbnail_public_item(1) !== null, 'Published image is not accessible');
    foreach ([0, 2, 3, 999] as $itemId) {
        gallery_media_test_assert(gallery_thumbnail_public_item($itemId) === null, 'Nonpublic or non-image item was accessible');
    }
    gallery_media_test_assert(gallery_thumbnail_public_category(1) !== null, 'Public category cover is not accessible');
    foreach ([0, 2, 3, 4, 999] as $categoryId) {
        gallery_media_test_assert(gallery_thumbnail_public_category($categoryId) === null, 'Nonpublic category or invalid cover was accessible');
    }
    gallery_media_test_assert(gallery_thumbnail_url(['id' => 1, 'url' => 'https://example.com/image.png', 'local_path' => '']) === 'https://example.com/image.png', 'Remote image did not fall back to its original URL');
    gallery_media_test_assert(gallery_thumbnail_url(['id' => 1, 'url' => '/uploads/2026/small.png'] + $local) === '/uploads/2026/small.png', 'Small original image was needlessly upscaled');
    $filters = gallery_render_category_filters([
        ['name' => '<Travel>', 'slug' => 'travel', 'cover_url' => '/uploads/cover.png', 'published_count' => 2],
        ['name' => 'Unsafe', 'slug' => 'unsafe', 'cover_url' => 'javascript:alert(1)', 'published_count' => 1],
    ], 'travel');
    gallery_media_test_assert(str_contains($filters, 'src="/uploads/cover.png"') && str_contains($filters, '&lt;Travel&gt;'), 'Category cover or escaped name is missing');
    gallery_media_test_assert(!str_contains($filters, 'javascript:') && substr_count($filters, '<img') === 1, 'Unsafe category cover URL was rendered');
    gallery_media_test_assert(str_contains($filters, 'aria-current="page"'), 'Selected category is not identified');

    if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
        $image = imagecreatetruecolor(1200, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 220, 45, 60));
        imagepng($image, UPLOAD_DIR . '/2026/large.png');
        imagedestroy($image);
        $database->exec("UPDATE media SET local_path = '2026/large.png', url = '/uploads/2026/large.png', width = 1200, height = 800 WHERE id = 1");
        $item = gallery_thumbnail_public_item(1);
        $source = gallery_local_thumbnail_source($item);
        gallery_media_test_assert($source !== null, 'Large local image was rejected');
        $thumbnail = gallery_generate_thumbnail($item, $source, 640);
        gallery_media_test_assert($thumbnail !== null && is_file($thumbnail['path']), 'Thumbnail was not generated');
        $dimensions = getimagesize($thumbnail['path']);
        gallery_media_test_assert($dimensions[0] === 640 && $dimensions[1] === 427, 'Thumbnail dimensions or aspect ratio are wrong');
        gallery_media_test_assert(gallery_thumbnail_url($item) === '/index.php?a=gallery_thumbnail&id=1&w=640', 'Grid does not use the thumbnail endpoint');
        $reused = gallery_generate_thumbnail($item, $source, 640);
        gallery_media_test_assert($reused['path'] === $thumbnail['path'], 'Repeated generation did not reuse its cache');
        file_put_contents($thumbnail['path'], 'Corrupted cache');
        clearstatcache();
        $repaired = gallery_generate_thumbnail($item, $source, 640);
        gallery_media_test_assert($repaired !== null && is_array(getimagesize($repaired['path'])), 'Corrupt thumbnail cache was not rebuilt');
        gallery_media_test_assert(!gallery_thumbnail_can_generate(array_replace($source, ['decoder' => 'gallery_decoder_unavailable']), 640), 'Unavailable decoder did not fall back safely');
        $cover = gallery_thumbnail_public_category(1);
        $coverThumbnail = gallery_generate_thumbnail($cover, $source, 640);
        gallery_media_test_assert($coverThumbnail !== null && $coverThumbnail['path'] !== $thumbnail['path'], 'Item and category thumbnail cache keys overlap');
        gallery_media_test_assert(gallery_category_thumbnail_url(['id' => 1, 'cover_url' => $item['url'], 'cover_local_path' => $item['local_path']]) === '/index.php?a=gallery_thumbnail&category_id=1&w=320', 'Category cover still uses its full original image');
        touch(UPLOAD_DIR . '/2026/large.png', time() + 2);
        clearstatcache();
        $changed = gallery_generate_thumbnail($item, gallery_local_thumbnail_source($item), 640);
        gallery_media_test_assert($changed !== null && $changed['path'] !== $thumbnail['path'], 'Changed source reused an outdated thumbnail');
        if (function_exists('imagejpeg') && function_exists('exif_read_data')) {
            $jpeg = imagecreatetruecolor(1200, 800);
            imagejpeg($jpeg, UPLOAD_DIR . '/2026/rotated.jpg');
            imagedestroy($jpeg);
            $jpegBytes = file_get_contents(UPLOAD_DIR . '/2026/rotated.jpg');
            $exif = "Exif\0\0II\x2a\0" . pack('V', 8) . pack('v', 1)
                . pack('vvVv', 0x0112, 3, 1, 6) . "\0\0" . pack('V', 0);
            file_put_contents(UPLOAD_DIR . '/2026/rotated.jpg', substr($jpegBytes, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpegBytes, 2));
            $rotatedSource = gallery_local_thumbnail_source(['local_path' => '2026/rotated.jpg']);
            $rotated = gallery_generate_thumbnail($item, $rotatedSource, 640);
            $rotatedDimensions = $rotated !== null ? getimagesize($rotated['path']) : null;
            gallery_media_test_assert(is_array($rotatedDimensions) && $rotatedDimensions[0] === 427 && $rotatedDimensions[1] === 640, 'JPEG EXIF orientation was lost');

            // Distinct pixel values detect mirrored rotations that dimension-only checks cannot see.
            $orientationExpectations = [
                1 => [[1, 2, 3], [4, 5, 6]],
                2 => [[3, 2, 1], [6, 5, 4]],
                3 => [[6, 5, 4], [3, 2, 1]],
                4 => [[4, 5, 6], [1, 2, 3]],
                5 => [[1, 4], [2, 5], [3, 6]],
                6 => [[4, 1], [5, 2], [6, 3]],
                7 => [[6, 3], [5, 2], [4, 1]],
                8 => [[3, 6], [2, 5], [1, 4]],
            ];
            foreach ($orientationExpectations as $orientation => $expectedPixels) {
                $orientationFile = UPLOAD_DIR . '/2026/orientation-' . $orientation . '.jpg';
                $orientationExif = "Exif\0\0II\x2a\0" . pack('V', 8) . pack('v', 1)
                    . pack('vvVv', 0x0112, 3, 1, $orientation) . "\0\0" . pack('V', 0);
                file_put_contents($orientationFile, substr($jpegBytes, 0, 2) . "\xff\xe1"
                    . pack('n', strlen($orientationExif) + 2) . $orientationExif . substr($jpegBytes, 2));
                $markers = imagecreatetruecolor(3, 2);
                for ($pixel = 0; $pixel < 6; $pixel++) {
                    imagesetpixel($markers, $pixel % 3, intdiv($pixel, 3), $pixel + 1);
                }
                $orientedMarkers = gallery_thumbnail_orient_image($markers, $orientationFile);
                $actualPixels = [];
                for ($y = 0; $y < imagesy($orientedMarkers); $y++) {
                    $row = [];
                    for ($x = 0; $x < imagesx($orientedMarkers); $x++) {
                        $row[] = imagecolorat($orientedMarkers, $x, $y);
                    }
                    $actualPixels[] = $row;
                }
                imagedestroy($orientedMarkers);
                gallery_media_test_assert($actualPixels === $expectedPixels,
                    'JPEG EXIF orientation ' . $orientation . ' produced incorrect pixel positions');
            }
        }
        $database->exec("UPDATE sblog_gallery_items SET status = 'draft' WHERE id = 1");
        gallery_media_test_assert(gallery_thumbnail_public_item(1) === null, 'Cached thumbnail remained accessible after its item became a draft');
        gallery_media_test_assert(gallery_thumbnail_public_category(1) === null, 'Category cover remained accessible after its last item became a draft');
        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '32M');
        gallery_media_test_assert(!gallery_thumbnail_can_generate(array_replace($source, ['width' => 6000, 'height' => 4000]), 640), 'Insufficient memory budget was accepted');
        ini_set('memory_limit', (string)$previousLimit);
    } else {
        echo "GD is unavailable; image generation checks skipped (access and path checks passed).\n";
    }
    echo "Gallery media tests passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Gallery media tests failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    gallery_media_test_remove($temporary);
}
