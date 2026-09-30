<?php

declare(strict_types=1);

$GLOBALS['gallery_test_db'] = new PDO('sqlite::memory:');
$GLOBALS['gallery_test_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['gallery_test_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['gallery_test_db']->exec('PRAGMA foreign_keys=ON');
$GLOBALS['gallery_test_settings'] = [];

function setting(string $name, string $default = ''): string
{
    return (string)($GLOBALS['gallery_test_settings'][$name] ?? $default);
}

function save_settings(array $values): void
{
    foreach ($values as $name => $value) {
        $GLOBALS['gallery_test_settings'][(string)$name] = (string)$value;
    }
}

function settings_cache(bool $refresh = false): array
{
    $settings = [];
    foreach (all_rows('SELECT name, value FROM settings') as $row) {
        $settings[(string)$row['name']] = (string)$row['value'];
    }
    $GLOBALS['gallery_test_settings'] = $settings;
    return $settings;
}

function safe_link_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return '#';
    }
    if (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }
    return str_starts_with($url, '/') || str_starts_with($url, '#') ? $url : '#';
}

function db(): PDO
{
    return $GLOBALS['gallery_test_db'];
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

function str_len_u(string $value): int
{
    return mb_strlen($value, 'UTF-8');
}

function str_sub_u(string $value, int $start, int $length): string
{
    return mb_substr($value, $start, $length, 'UTF-8');
}

function slugify(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'post';
}

function gallery_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function gallery_test_same(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function gallery_test_expect_exception(string $class, callable $callback, string $message): void
{
    $caught = null;
    try {
        $callback();
    } catch (Throwable $exception) {
        $caught = $exception;
    }
    gallery_test_assert($caught instanceof $class, $message);
}

function gallery_test_insert_media(array $values): int
{
    $defaults = [
        'original_name' => 'image.jpg',
        'title' => '',
        'alt_text' => '',
        'caption' => '',
        'url' => '/uploads/image.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
        'is_image' => 1,
        'width' => 1200,
        'height' => 800,
        'created_at' => time(),
        'updated_at' => time(),
    ];
    $media = array_merge($defaults, $values);
    q(
        'INSERT INTO media'
        . '(original_name, title, alt_text, caption, url, mime_type, file_size, is_image, width, height, created_at, updated_at) '
        . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
        [
            $media['original_name'], $media['title'], $media['alt_text'], $media['caption'],
            $media['url'], $media['mime_type'], $media['file_size'], $media['is_image'],
            $media['width'], $media['height'], $media['created_at'], $media['updated_at'],
        ]
    );
    return (int)db()->lastInsertId();
}

db()->exec(
    "CREATE TABLE settings(
        name TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )"
);
db()->exec(
    "CREATE TABLE posts(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL
    )"
);
db()->exec(
    "CREATE TABLE media(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_name TEXT NOT NULL,
        title TEXT NOT NULL DEFAULT '',
        alt_text TEXT NOT NULL DEFAULT '',
        caption TEXT NOT NULL DEFAULT '',
        url TEXT NOT NULL,
        mime_type TEXT NOT NULL,
        file_size INTEGER NOT NULL DEFAULT 0,
        is_image INTEGER NOT NULL DEFAULT 0,
        width INTEGER NOT NULL DEFAULT 0,
        height INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )"
);

require dirname(__DIR__) . '/plugins/gallery/includes/data.php';
require dirname(__DIR__) . '/plugins/gallery/includes/public.php';

try {
    $rollbackError = new RuntimeException('Gallery rollback regression');
    $caughtRollbackError = null;
    try {
        gallery_transaction(static function () use ($rollbackError): void {
            q('INSERT INTO settings(name, value) VALUES(?, ?)', ['gallery_test_rollback', 'pending']);
            gallery_transaction(static function (): void {
                q('INSERT INTO settings(name, value) VALUES(?, ?)', ['gallery_test_nested', 'pending']);
            });
            throw $rollbackError;
        });
    } catch (Throwable $exception) {
        $caughtRollbackError = $exception;
    }
    gallery_test_assert($caughtRollbackError === $rollbackError, 'Rollback replaced the original transaction error');
    gallery_test_same(
        0,
        (int)val("SELECT COUNT(*) FROM settings WHERE name IN ('gallery_test_rollback', 'gallery_test_nested')"),
        'Failed gallery transaction retained writes'
    );
    gallery_test_same(false, gallery_in_transaction(), 'Failed gallery transaction remained active');

    $transactionResult = gallery_transaction(static function (): string {
        q('INSERT INTO settings(name, value) VALUES(?, ?)', ['gallery_test_commit', 'committed']);
        return 'transaction result';
    });
    gallery_test_same('transaction result', $transactionResult, 'Gallery transaction lost the callback result');
    gallery_test_same('committed', val("SELECT value FROM settings WHERE name = 'gallery_test_commit'"), 'Gallery transaction did not commit');

    db()->beginTransaction();
    gallery_transaction(static function (): void {
        q('INSERT INTO settings(name, value) VALUES(?, ?)', ['gallery_test_caller', 'pending']);
    });
    gallery_test_same(true, db()->inTransaction(), 'Gallery transaction committed a transaction owned by its caller');
    db()->rollBack();
    gallery_test_same(false, val("SELECT value FROM settings WHERE name = 'gallery_test_caller'"), 'Caller rollback did not remove gallery writes');

    $mediaOne = gallery_test_insert_media([
        'original_name' => 'one.jpg',
        'title' => 'Media One',
        'alt_text' => 'Alt One',
        'caption' => 'Caption One',
        'url' => '/uploads/one.jpg',
        'created_at' => 101,
        'updated_at' => 101,
    ]);
    $nonImage = gallery_test_insert_media([
        'original_name' => 'notes.txt',
        'title' => 'Notes',
        'url' => '/uploads/notes.txt',
        'mime_type' => 'text/plain',
        'is_image' => 0,
        'width' => 0,
        'height' => 0,
        'created_at' => 102,
        'updated_at' => 102,
    ]);
    $mediaThree = gallery_test_insert_media([
        'original_name' => 'three.jpg',
        'caption' => 'Caption Three',
        'url' => '/uploads/three.jpg',
        'created_at' => 103,
        'updated_at' => 103,
    ]);
    $mediaCascade = gallery_test_insert_media([
        'original_name' => 'cascade.jpg',
        'title' => 'Cascade',
        'url' => '/uploads/cascade.jpg',
        'created_at' => 104,
        'updated_at' => 104,
    ]);
    $mediaFive = gallery_test_insert_media([
        'original_name' => 'five.jpg',
        'title' => 'Media Five',
        'alt_text' => 'Alt Five',
        'caption' => 'Caption Five',
        'url' => '/uploads/five.jpg',
        'created_at' => 105,
        'updated_at' => 105,
    ]);
    $mediaSix = gallery_test_insert_media([
        'original_name' => 'six.jpg',
        'title' => 'Media Six',
        'url' => '/uploads/six.jpg',
        'created_at' => 106,
        'updated_at' => 106,
    ]);
    $mediaSeven = gallery_test_insert_media([
        'original_name' => 'seven.jpg',
        'caption' => 'Caption Seven',
        'url' => '/uploads/seven.jpg',
        'created_at' => 107,
        'updated_at' => 107,
    ]);
    $mediaUnused = gallery_test_insert_media([
        'original_name' => 'unused.jpg',
        'title' => 'Unused',
        'url' => '/uploads/unused.jpg',
        'created_at' => 108,
        'updated_at' => 108,
    ]);

    gallery_install();
    gallery_test_same('1', gallery_setting('schema_version'), 'Gallery schema version was not installed');
    gallery_test_same('1', setting(SBLOG_GALLERY_SCHEMA_MARKER), 'Gallery schema fast-path marker was not saved');
    gallery_test_same('gallery', setting(SBLOG_GALLERY_ROUTE_MARKER), 'Gallery route fast-path marker was not saved');
    gallery_test_same('gallery', gallery_setting('route_slug'), 'Default gallery route is wrong');
    gallery_test_assert(gallery_route_conflicts('wp-json') !== [], 'REST API route was not reserved');
    gallery_test_same('', gallery_safe_media_url('javascript:alert(1)'), 'Unsafe media URL was accepted');
    gallery_test_same('/uploads/image.jpg', gallery_safe_media_url('/uploads/image.jpg'), 'Local media URL was rejected');

    db()->exec('DROP TABLE sblog_gallery_items');
    db()->exec('DROP TABLE sblog_gallery_categories');
    db()->exec('DROP TABLE sblog_gallery_settings');
    unset($GLOBALS['sblog_gallery_settings_cache']);
    gallery_install();
    gallery_test_same(
        3,
        (int)val(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' "
            . "AND name IN ('sblog_gallery_settings','sblog_gallery_categories','sblog_gallery_items')"
        ),
        'Schema markers hid missing gallery tables'
    );

    gallery_save_settings_values(['title' => 'Portfolio', 'route_slug' => 'photos']);
    gallery_test_same('photos', gallery_setting('route_slug'), 'Saved gallery route was not immediately visible');
    gallery_test_same('photos', setting(SBLOG_GALLERY_ROUTE_MARKER), 'Saved gallery route did not refresh the core cache');
    unset($GLOBALS['sblog_gallery_settings_cache']);
    settings_cache(true);
    gallery_test_same('photos', gallery_setting('route_slug'), 'Saved gallery route did not survive a cache reload');
    gallery_save_settings_values(['route_slug' => 'gallery']);

    db()->beginTransaction();
    gallery_test_expect_exception(
        LogicException::class,
        static fn() => gallery_save_settings_values(['route_slug' => 'rolled-back-route']),
        'Gallery settings were allowed inside an external transaction'
    );
    db()->rollBack();
    gallery_test_same('gallery', gallery_setting('route_slug'), 'Rejected nested settings changed the request cache');
    gallery_test_same('gallery', setting(SBLOG_GALLERY_ROUTE_MARKER), 'Rejected nested settings changed the core cache');

    gallery_transaction(static function (): void {
        gallery_test_expect_exception(
            LogicException::class,
            static fn() => gallery_save_settings_values(['route_slug' => 'uncommitted-route']),
            'Gallery settings were allowed inside a gallery-owned transaction'
        );
    });
    gallery_test_same('gallery', gallery_setting('route_slug'), 'Rejected gallery-owned settings changed the request cache');
    gallery_test_same('gallery', setting(SBLOG_GALLERY_ROUTE_MARKER), 'Rejected gallery-owned settings changed the core cache');

    q("UPDATE sblog_gallery_settings SET value = 'Bad/Route' WHERE name = 'route_slug'");
    q('INSERT OR REPLACE INTO settings(name, value) VALUES(?, ?)', [SBLOG_GALLERY_ROUTE_MARKER, 'Bad/Route']);
    settings_cache(true);
    $GLOBALS['sblog_gallery_settings_cache'] = array_replace(gallery_settings(), ['route_slug' => 'Bad/Route']);
    gallery_install();
    gallery_test_same('gallery', gallery_setting('route_slug'), 'Install repair left a stale request cache');
    gallery_test_same('gallery', setting(SBLOG_GALLERY_ROUTE_MARKER), 'Install repair left a stale core route cache');

    $travelId = gallery_save_category_record([
        'name' => 'Travel',
        'slug' => 'travel',
        'description' => 'Travel photographs',
        'sort_order' => '10',
    ]);
    $firstAdd = gallery_add_media_items([$mediaOne], $travelId);
    gallery_test_same(1, $firstAdd['added'], 'Initial gallery item was not added');

    gallery_install();
    gallery_test_same('Portfolio', gallery_setting('title'), 'Repeated install replaced saved settings');
    gallery_test_same(1, (int)val('SELECT COUNT(*) FROM sblog_gallery_categories'), 'Repeated install changed categories');
    gallery_test_same(1, (int)val('SELECT COUNT(*) FROM sblog_gallery_items'), 'Repeated install changed gallery items');

    [, $emptyCategoryErrors] = gallery_validate_category(['name' => '', 'slug' => '']);
    gallery_test_assert($emptyCategoryErrors !== [], 'An empty category name passed validation');
    gallery_test_expect_exception(
        InvalidArgumentException::class,
        static fn() => gallery_save_category_record(['name' => '', 'slug' => '']),
        'Saving an invalid category did not fail'
    );

    [, $duplicateSlugErrors] = gallery_validate_category([
        'name' => 'Duplicate',
        'slug' => 'travel',
    ]);
    gallery_test_assert($duplicateSlugErrors !== [], 'An explicit duplicate category slug passed validation');
    gallery_test_expect_exception(
        InvalidArgumentException::class,
        static fn() => gallery_save_category_record(['name' => 'Duplicate', 'slug' => 'travel']),
        'Saving an explicit duplicate category slug did not fail'
    );

    $draftCategoryId = gallery_save_category_record(['name' => 'Travel', 'slug' => '']);
    gallery_test_same(
        'travel-2',
        (string)val('SELECT slug FROM sblog_gallery_categories WHERE id = ?', [$draftCategoryId]),
        'An automatic category slug was not made unique'
    );
    $localizedCategoryId = gallery_save_category_record(['name' => '旅行影像', 'slug' => '']);
    gallery_test_same(
        'gallery-category',
        (string)val('SELECT slug FROM sblog_gallery_categories WHERE id = ?', [$localizedCategoryId]),
        'A non-ASCII category name did not receive a stable ASCII slug'
    );

    $mixedAdd = gallery_add_media_items([$mediaThree, $nonImage, 999999, 'bad', $mediaThree], $travelId);
    gallery_test_same(1, $mixedAdd['added'], 'A valid image was not added from a mixed selection');
    gallery_test_same(0, $mixedAdd['existing'], 'A duplicate input was counted as an existing gallery item');
    gallery_test_same(3, $mixedAdd['invalid'], 'Invalid or non-image media was not counted correctly');
    gallery_test_same(1, count($mixedAdd['item_ids']), 'The add result returned the wrong new item IDs');
    gallery_test_same(0, (int)val('SELECT COUNT(*) FROM sblog_gallery_items WHERE media_id = ?', [$nonImage]), 'A non-image entered the gallery');

    $repeatAdd = gallery_add_media_items([$mediaThree], $travelId);
    gallery_test_same(0, $repeatAdd['added'], 'An existing image was inserted twice');
    gallery_test_same(1, $repeatAdd['existing'], 'An existing image was not reported');
    gallery_test_same(1, (int)val('SELECT COUNT(*) FROM sblog_gallery_items WHERE media_id = ?', [$mediaThree]), 'Gallery media uniqueness was not preserved');

    $removedItemId = (int)$mixedAdd['item_ids'][0];
    gallery_test_same(1, gallery_remove_items([$removedItemId, $removedItemId, 'bad']), 'Gallery item removal returned the wrong count');
    gallery_test_same(1, (int)val('SELECT COUNT(*) FROM media WHERE id = ?', [$mediaThree]), 'Removing a gallery item deleted its media record');
    gallery_test_same(0, (int)val('SELECT COUNT(*) FROM sblog_gallery_items WHERE media_id = ?', [$mediaThree]), 'Removed gallery relation still exists');

    $draftAdd = gallery_add_media_items([$mediaThree], $draftCategoryId);
    $draftItemId = (int)$draftAdd['item_ids'][0];
    gallery_update_item_record($draftItemId, ['status' => 'draft', 'sort_order' => '0']);

    $cascadeAdd = gallery_add_media_items([$mediaCascade], $travelId);
    $cascadeItemId = (int)$cascadeAdd['item_ids'][0];
    q('DELETE FROM media WHERE id = ?', [$mediaCascade]);
    gallery_test_same(0, (int)val('SELECT COUNT(*) FROM sblog_gallery_items WHERE id = ?', [$cascadeItemId]), 'Deleting media did not cascade to the gallery item');

    $orderedAdd = gallery_add_media_items([$mediaFive, $mediaSix], $travelId);
    gallery_test_same(2, $orderedAdd['added'], 'Published ordering fixtures were not added');
    $lastAdd = gallery_add_media_items([$mediaSeven]);
    gallery_test_same(1, $lastAdd['added'], 'Uncategorized fixture was not added');

    $itemOne = (int)val('SELECT id FROM sblog_gallery_items WHERE media_id = ?', [$mediaOne]);
    $itemFive = (int)val('SELECT id FROM sblog_gallery_items WHERE media_id = ?', [$mediaFive]);
    $itemSix = (int)val('SELECT id FROM sblog_gallery_items WHERE media_id = ?', [$mediaSix]);
    $itemSeven = (int)val('SELECT id FROM sblog_gallery_items WHERE media_id = ?', [$mediaSeven]);
    gallery_update_item_record($itemOne, ['sort_order' => '20', 'status' => 'published']);
    gallery_update_item_record($itemFive, [
        'title' => 'Override Five',
        'description' => 'Override description',
        'sort_order' => '10',
        'status' => 'published',
    ]);
    gallery_update_item_record($itemSix, ['sort_order' => '10', 'status' => 'published']);
    gallery_update_item_record($itemSeven, ['sort_order' => '30', 'status' => 'published']);

    $publicCategories = gallery_categories(false);
    gallery_test_same([$travelId], array_map(static fn(array $row): int => (int)$row['id'], $publicCategories), 'A category containing only drafts appeared publicly');

    $draftResult = gallery_admin_items(['status' => 'draft', 'per_page' => 20]);
    gallery_test_same(1, $draftResult['total'], 'Admin draft filtering returned the wrong total');
    gallery_test_same($mediaThree, (int)$draftResult['items'][0]['media_id'], 'Admin draft filtering returned the wrong item');

    $pageOne = gallery_public_items(['page' => 1, 'per_page' => 2]);
    gallery_test_same(4, $pageOne['total'], 'Draft items leaked into the public total');
    gallery_test_same(2, $pageOne['pages'], 'Public page count is wrong');
    gallery_test_same(
        [$mediaSix, $mediaFive],
        array_map(static fn(array $row): int => (int)$row['media_id'], $pageOne['items']),
        'Public items are not stably ordered by sort order and descending item ID'
    );
    $pageTwo = gallery_public_items(['page' => 2, 'per_page' => 2]);
    gallery_test_same(
        [$mediaOne, $mediaSeven],
        array_map(static fn(array $row): int => (int)$row['media_id'], $pageTwo['items']),
        'The second public page contains the wrong items'
    );
    $clampedPage = gallery_public_items(['page' => 99, 'per_page' => 2]);
    gallery_test_same(2, $clampedPage['page'], 'An out-of-range public page was not clamped');

    $travelResult = gallery_public_items(['category_slug' => 'travel', 'per_page' => 60]);
    gallery_test_same(3, $travelResult['total'], 'Public category filtering returned the wrong total');
    gallery_test_assert(is_array($travelResult['category']), 'A valid public category was not returned');
    $missingCategory = gallery_public_items(['category_slug' => 'missing', 'per_page' => 60]);
    gallery_test_same(true, $missingCategory['category_requested'], 'Missing category request was not recorded');
    gallery_test_same(0, $missingCategory['total'], 'A missing category returned public items');

    $allPublic = gallery_public_items(['per_page' => 60]);
    $publicByMedia = [];
    foreach ($allPublic['items'] as $item) {
        $publicByMedia[(int)$item['media_id']] = $item;
    }
    gallery_test_same('Media One', (string)$publicByMedia[$mediaOne]['display_title'], 'Media title fallback is wrong');
    gallery_test_same('Override Five', (string)$publicByMedia[$mediaFive]['display_title'], 'Gallery title override was ignored');
    gallery_test_same('seven.jpg', (string)$publicByMedia[$mediaSeven]['display_title'], 'Original filename fallback is wrong');

    gallery_test_same(
        ['title' => 'Media One', 'description' => 'Caption One', 'alt' => 'Alt One'],
        gallery_item_display_values($publicByMedia[$mediaOne]),
        'Public media metadata fallback is wrong'
    );
    gallery_test_same(
        ['title' => 'Override Five', 'description' => 'Override description', 'alt' => 'Alt Five'],
        gallery_item_display_values($publicByMedia[$mediaFive]),
        'Public gallery overrides are wrong'
    );
    gallery_test_same(
        ['title' => 'seven', 'description' => 'Caption Seven', 'alt' => 'seven'],
        gallery_item_display_values($publicByMedia[$mediaSeven]),
        'Public filename, caption, and alt fallbacks are wrong'
    );

    $mediaSearch = gallery_media_search(['per_page' => 60]);
    $searchById = [];
    foreach ($mediaSearch['items'] as $item) {
        $searchById[(int)$item['id']] = $item;
    }
    gallery_test_assert(!isset($searchById[$nonImage]), 'Media picker included a non-image');
    gallery_test_same(true, $searchById[$mediaOne]['added'], 'Media picker did not mark an added image');
    gallery_test_same(false, $searchById[$mediaUnused]['added'], 'Media picker marked an unused image as added');

    echo "Gallery integration tests passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Gallery integration tests failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
