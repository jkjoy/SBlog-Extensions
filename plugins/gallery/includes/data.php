<?php
declare(strict_types=1);

const SBLOG_GALLERY_SCHEMA_VERSION = 1;
const SBLOG_GALLERY_DEFAULT_PER_PAGE = 24;
const SBLOG_GALLERY_MAX_BATCH_SIZE = 100;
const SBLOG_GALLERY_SCHEMA_MARKER = 'plugin_gallery_schema_version';
const SBLOG_GALLERY_ROUTE_MARKER = 'plugin_gallery_route_slug';

/**
 * Execute a write operation without committing a transaction owned by the caller.
 */
function gallery_transaction(callable $callback): mixed
{
    $database = db();
    $ownsTransaction = !$database->inTransaction();

    if ($ownsTransaction) {
        $database->exec('BEGIN IMMEDIATE');
    }

    try {
        $result = $callback($database);
        if ($ownsTransaction) {
            $database->commit();
        }
        return $result;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

function gallery_default_settings(): array
{
    return [
        'schema_version' => (string)SBLOG_GALLERY_SCHEMA_VERSION,
        'route_slug' => 'gallery',
        'title' => '图库',
        'description' => '',
        'per_page' => (string)SBLOG_GALLERY_DEFAULT_PER_PAGE,
    ];
}

function gallery_install(): void
{
    if (function_exists('setting')) {
        $cachedRoute = setting(SBLOG_GALLERY_ROUTE_MARKER, '');
        [, $cachedRouteErrors] = gallery_validate_route_slug($cachedRoute);
        $schemaTables = (int)val(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' "
            . "AND name IN ('sblog_gallery_settings','sblog_gallery_categories','sblog_gallery_items')"
        );
        if (setting(SBLOG_GALLERY_SCHEMA_MARKER, '') === (string)SBLOG_GALLERY_SCHEMA_VERSION
            && $cachedRouteErrors === []
            && $schemaTables === 3) {
            return;
        }
    }
    if (db()->inTransaction()) {
        throw new LogicException('Gallery installation cannot run inside an existing transaction.');
    }

    $routeSlug = gallery_transaction(static function (PDO $database): string {
        $database->exec(
            "CREATE TABLE IF NOT EXISTS sblog_gallery_settings(
                name TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT ''
            )"
        );
        $database->exec(
            "CREATE TABLE IF NOT EXISTS sblog_gallery_categories(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL COLLATE NOCASE UNIQUE,
                description TEXT NOT NULL DEFAULT '',
                cover_media_id INTEGER,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY(cover_media_id) REFERENCES media(id) ON DELETE SET NULL
            )"
        );
        $database->exec(
            "CREATE TABLE IF NOT EXISTS sblog_gallery_items(
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                media_id INTEGER NOT NULL UNIQUE,
                category_id INTEGER,
                title TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                sort_order INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT 'published' CHECK(status IN ('published', 'draft')),
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                FOREIGN KEY(media_id) REFERENCES media(id) ON DELETE CASCADE,
                FOREIGN KEY(category_id) REFERENCES sblog_gallery_categories(id) ON DELETE SET NULL
            )"
        );
        $database->exec(
            'CREATE INDEX IF NOT EXISTS idx_sblog_gallery_items_public '
            . 'ON sblog_gallery_items(status, sort_order, id)'
        );
        $database->exec(
            'CREATE INDEX IF NOT EXISTS idx_sblog_gallery_items_category '
            . 'ON sblog_gallery_items(category_id, status, sort_order, id)'
        );

        $insert = $database->prepare(
            'INSERT OR IGNORE INTO sblog_gallery_settings(name, value) VALUES(?, ?)'
        );
        foreach (gallery_default_settings() as $name => $value) {
            $insert->execute([$name, $value]);
        }

        $stored = $database->query(
            "SELECT value FROM sblog_gallery_settings WHERE name = 'schema_version'"
        )->fetchColumn();
        $version = is_scalar($stored) && preg_match('/^[0-9]+$/D', (string)$stored) === 1
            ? (int)$stored
            : 0;
        if ($version < SBLOG_GALLERY_SCHEMA_VERSION) {
            $statement = $database->prepare(
                'INSERT OR REPLACE INTO sblog_gallery_settings(name, value) VALUES(?, ?)'
            );
            $statement->execute(['schema_version', (string)SBLOG_GALLERY_SCHEMA_VERSION]);
        }

        $route = (string)$database->query(
            "SELECT value FROM sblog_gallery_settings WHERE name = 'route_slug'"
        )->fetchColumn();
        [, $routeErrors] = gallery_validate_route_slug($route);
        if ($routeErrors !== []) {
            $route = 'gallery';
            $statement = $database->prepare(
                'INSERT OR REPLACE INTO sblog_gallery_settings(name, value) VALUES(?, ?)'
            );
            $statement->execute(['route_slug', $route]);
        }

        if (function_exists('settings_cache')) {
            $statement = $database->prepare('INSERT OR REPLACE INTO settings(name, value) VALUES(?, ?)');
            $statement->execute([SBLOG_GALLERY_SCHEMA_MARKER, (string)SBLOG_GALLERY_SCHEMA_VERSION]);
            $statement->execute([SBLOG_GALLERY_ROUTE_MARKER, $route]);
        }
        return $route;
    });

    if (function_exists('settings_cache')) {
        settings_cache(true);
    } elseif (function_exists('save_settings')) {
        save_settings([
            SBLOG_GALLERY_SCHEMA_MARKER => (string)SBLOG_GALLERY_SCHEMA_VERSION,
            SBLOG_GALLERY_ROUTE_MARKER => $routeSlug,
        ]);
    }
    unset($GLOBALS['sblog_gallery_settings_cache']);
}

function gallery_settings(): array
{
    if (is_array($GLOBALS['sblog_gallery_settings_cache'] ?? null)) {
        return $GLOBALS['sblog_gallery_settings_cache'];
    }
    $settings = gallery_default_settings();
    $cachedRoute = function_exists('setting') ? setting(SBLOG_GALLERY_ROUTE_MARKER, '') : '';
    [, $cachedRouteErrors] = gallery_validate_route_slug($cachedRoute);
    if ($cachedRouteErrors === []) {
        $settings['route_slug'] = $cachedRoute;
    }
    foreach (all_rows('SELECT name, value FROM sblog_gallery_settings') as $row) {
        $name = (string)($row['name'] ?? '');
        if (array_key_exists($name, $settings)) {
            if ($name === 'route_slug' && $cachedRouteErrors === []) {
                continue;
            }
            $settings[$name] = (string)($row['value'] ?? '');
        }
    }
    return $GLOBALS['sblog_gallery_settings_cache'] = $settings;
}

function gallery_setting(string $name, string $default = ''): string
{
    $settings = gallery_settings();
    return array_key_exists($name, $settings) ? (string)$settings[$name] : $default;
}

function gallery_save_settings_values(array $values): void
{
    if (db()->inTransaction()) {
        throw new LogicException('Gallery settings cannot be saved inside an existing transaction.');
    }
    $currentSettings = gallery_settings();
    $save = [];

    if (array_key_exists('title', $values)) {
        $title = trim(is_scalar($values['title']) ? (string)$values['title'] : '');
        if ($title === '' || str_sub_u($title, 0, 121) !== $title) {
            throw new InvalidArgumentException('图库标题不能为空，且不能超过 120 个字符。');
        }
        $save['title'] = $title;
    }

    if (array_key_exists('description', $values)) {
        $description = trim(is_scalar($values['description']) ? (string)$values['description'] : '');
        if (str_sub_u($description, 0, 1001) !== $description) {
            throw new InvalidArgumentException('图库描述不能超过 1000 个字符。');
        }
        $save['description'] = $description;
    }

    if (array_key_exists('route_slug', $values)) {
        [$routeSlug, $errors] = gallery_validate_route_slug(
            is_scalar($values['route_slug']) ? (string)$values['route_slug'] : ''
        );
        $errors = array_merge($errors, gallery_route_conflicts($routeSlug));
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', array_values(array_unique($errors))));
        }
        $save['route_slug'] = $routeSlug;
    }

    if (array_key_exists('per_page', $values)) {
        $raw = is_scalar($values['per_page']) ? trim((string)$values['per_page']) : '';
        if (preg_match('/^[0-9]+$/D', $raw) !== 1 || (int)$raw < 6 || (int)$raw > 60) {
            throw new InvalidArgumentException('每页图片数量必须介于 6 到 60 之间。');
        }
        $save['per_page'] = (string)(int)$raw;
    }

    if ($save === []) {
        return;
    }

    gallery_transaction(static function (PDO $database) use ($save): void {
        $statement = $database->prepare(
            'INSERT OR REPLACE INTO sblog_gallery_settings(name, value) VALUES(?, ?)'
        );
        foreach ($save as $name => $value) {
            $statement->execute([$name, $value]);
        }
        if (isset($save['route_slug']) && function_exists('settings_cache')) {
            $statement = $database->prepare('INSERT OR REPLACE INTO settings(name, value) VALUES(?, ?)');
            $statement->execute([SBLOG_GALLERY_ROUTE_MARKER, $save['route_slug']]);
        }
    });
    if (isset($save['route_slug'])) {
        if (function_exists('settings_cache')) {
            settings_cache(true);
        } elseif (function_exists('save_settings')) {
            save_settings([SBLOG_GALLERY_ROUTE_MARKER => $save['route_slug']]);
        }
    }
    $GLOBALS['sblog_gallery_settings_cache'] = array_replace($currentSettings, $save);
}

/**
 * @return array{0:string,1:array<int,string>}
 */
function gallery_validate_route_slug(string $input): array
{
    $input = trim($input);
    $errors = [];

    if ($input === '') {
        return ['', ['图库访问路径不能为空。']];
    }
    if (str_contains($input, '/') || str_contains($input, '\\')) {
        $errors[] = '图库访问路径只能包含一个路径段。';
    }

    $slug = $input;
    if (strlen($slug) > 64) {
        $errors[] = '图库访问路径不能超过 64 个字符。';
    }
    if (slugify($slug) !== $slug || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
        $errors[] = '图库访问路径只能使用小写英文字母、数字和连字符。';
    }

    return [$slug, $errors];
}

function gallery_route_conflicts(string $slug): array
{
    [$slug, $errors] = gallery_validate_route_slug($slug);
    if ($errors !== []) {
        return $errors;
    }

    $reserved = [
        'admin', 'archive', 'archives', 'assets', 'cache', 'categories', 'category', 'data',
        'edit', 'forgot-password', 'index-php', 'install-php', 'links', 'login', 'logout',
        'page', 'pages', 'plugins', 'post', 'reset-password', 'rss-xml', 'sitemap-xml',
        'tag', 'tags', 'themes', 'uploads', 'wp-json', 'write',
    ];
    if (in_array($slug, $reserved, true)) {
        $errors[] = '该路径为 SBlog 系统保留路径。';
    }

    $page = one(
        'SELECT id, title FROM posts WHERE kind = ? AND slug = ? COLLATE NOCASE LIMIT 1',
        ['page', $slug]
    );
    if ($page !== null) {
        $title = trim((string)($page['title'] ?? ''));
        $errors[] = $title === ''
            ? '该路径已被独立页面占用。'
            : '该路径已被独立页面“' . $title . '”占用。';
    }

    return array_values(array_unique($errors));
}

function gallery_categories(bool $includeEmpty = true): array
{
    $sql = "SELECT c.*,
                   cover.url AS cover_url,
                   cover.title AS cover_title,
                   cover.alt_text AS cover_alt_text,
                   cover.width AS cover_width,
                   cover.height AS cover_height,
                   COUNT(gi.id) AS item_count,
                   COALESCE(SUM(CASE WHEN gi.status = 'published' THEN 1 ELSE 0 END), 0) AS published_count
            FROM sblog_gallery_categories c
            LEFT JOIN media cover ON cover.id = c.cover_media_id AND cover.is_image = 1
            LEFT JOIN sblog_gallery_items gi ON gi.category_id = c.id
            GROUP BY c.id";
    if (!$includeEmpty) {
        $sql .= " HAVING SUM(CASE WHEN gi.status = 'published' THEN 1 ELSE 0 END) > 0";
    }
    $sql .= ' ORDER BY c.sort_order ASC, c.id ASC';
    return all_rows($sql);
}

function gallery_unique_category_slug(string $seed, ?int $excludeId = null): string
{
    $candidate = trim(str_sub_u(slugify($seed), 0, 100), '-');
    $base = preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $candidate) === 1
        ? $candidate
        : '';
    if ($base === '') {
        $base = 'gallery-category';
    }
    $slug = $base;
    $index = 2;

    while (true) {
        $existing = $excludeId !== null
            ? one(
                'SELECT id FROM sblog_gallery_categories WHERE slug = ? COLLATE NOCASE AND id <> ?',
                [$slug, $excludeId]
            )
            : one(
                'SELECT id FROM sblog_gallery_categories WHERE slug = ? COLLATE NOCASE',
                [$slug]
            );
        if ($existing === null) {
            return $slug;
        }

        $suffix = '-' . $index++;
        $slug = rtrim(str_sub_u($base, 0, max(1, 100 - strlen($suffix))), '-') . $suffix;
    }
}

/**
 * @return array{0:array<string,mixed>,1:array<int,string>}
 */
function gallery_validate_category(array $input, ?array $existing = null): array
{
    $name = trim(is_scalar($input['name'] ?? null) ? (string)$input['name'] : '');
    $description = trim(is_scalar($input['description'] ?? null) ? (string)$input['description'] : '');
    $slugInput = trim(is_scalar($input['slug'] ?? null) ? (string)$input['slug'] : '');
    $sortRaw = is_scalar($input['sort_order'] ?? null) ? trim((string)$input['sort_order']) : '0';
    $coverRaw = $input['cover_media_id'] ?? null;
    $errors = [];

    if ($name === '') {
        $errors[] = '分类名称不能为空。';
    } elseif (str_sub_u($name, 0, 101) !== $name) {
        $errors[] = '分类名称不能超过 100 个字符。';
    }
    if (str_sub_u($description, 0, 2001) !== $description) {
        $errors[] = '分类描述不能超过 2000 个字符。';
    }
    if ($sortRaw !== '' && preg_match('/^-?[0-9]+$/D', $sortRaw) !== 1) {
        $errors[] = '分类排序必须是整数。';
    }
    $sortOrder = preg_match('/^-?[0-9]+$/D', $sortRaw) === 1 ? (int)$sortRaw : 0;
    $sortOrder = max(-999999, min(999999, $sortOrder));

    $coverMediaId = null;
    if ($coverRaw !== null && $coverRaw !== '') {
        $coverString = is_scalar($coverRaw) ? trim((string)$coverRaw) : '';
        if (preg_match('/^[0-9]+$/D', $coverString) !== 1 || (int)$coverString < 1) {
            $errors[] = '分类封面图片无效。';
        } else {
            $coverMediaId = (int)$coverString;
            if (one('SELECT id FROM media WHERE id = ? AND is_image = 1', [$coverMediaId]) === null) {
                $errors[] = '分类封面图片不存在或不是图片。';
            }
        }
    }

    $excludeId = isset($existing['id']) ? (int)$existing['id'] : null;
    if ($slugInput === '') {
        $slug = gallery_unique_category_slug($name, $excludeId);
    } else {
        $slug = $slugInput;
        if (str_sub_u($slug, 0, 101) !== $slug
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            $errors[] = '分类 Slug 只能使用小写英文字母、数字和连字符，且不能超过 100 个字符。';
        } else {
            $duplicate = $excludeId !== null
                ? one(
                    'SELECT id FROM sblog_gallery_categories WHERE slug = ? COLLATE NOCASE AND id <> ?',
                    [$slug, $excludeId]
                )
                : one(
                    'SELECT id FROM sblog_gallery_categories WHERE slug = ? COLLATE NOCASE',
                    [$slug]
                );
            if ($duplicate !== null) {
                $errors[] = '分类 Slug 已被使用。';
            }
        }
    }

    return [[
        'name' => $name,
        'slug' => $slug,
        'description' => $description,
        'cover_media_id' => $coverMediaId,
        'sort_order' => $sortOrder,
    ], $errors];
}

function gallery_save_category_record(array $input, ?int $categoryId = null): int
{
    return gallery_transaction(static function () use ($input, $categoryId): int {
        $existing = null;
        if ($categoryId !== null) {
            if ($categoryId < 1) {
                throw new InvalidArgumentException('分类编号无效。');
            }
            $existing = one('SELECT * FROM sblog_gallery_categories WHERE id = ?', [$categoryId]);
            if ($existing === null) {
                throw new RuntimeException('找不到图库分类。');
            }
        }

        [$data, $errors] = gallery_validate_category($input, $existing);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $now = time();
        if ($existing !== null) {
            q(
                'UPDATE sblog_gallery_categories '
                . 'SET name = ?, slug = ?, description = ?, cover_media_id = ?, sort_order = ?, updated_at = ? '
                . 'WHERE id = ?',
                [
                    $data['name'], $data['slug'], $data['description'], $data['cover_media_id'],
                    $data['sort_order'], $now, $categoryId,
                ]
            );
            return (int)$categoryId;
        }

        q(
            'INSERT INTO sblog_gallery_categories'
            . '(name, slug, description, cover_media_id, sort_order, created_at, updated_at) '
            . 'VALUES(?,?,?,?,?,?,?)',
            [
                $data['name'], $data['slug'], $data['description'], $data['cover_media_id'],
                $data['sort_order'], $now, $now,
            ]
        );
        return (int)db()->lastInsertId();
    });
}

function gallery_delete_category_record(int $categoryId): bool
{
    if ($categoryId < 1) {
        return false;
    }
    return gallery_transaction(static function () use ($categoryId): bool {
        return q('DELETE FROM sblog_gallery_categories WHERE id = ?', [$categoryId])->rowCount() > 0;
    });
}

/**
 * @return array{0:array<int,int>,1:int}
 */
function gallery_normalize_ids(array $values, int $limit = 500): array
{
    $ids = [];
    $seen = [];
    $invalid = 0;

    foreach ($values as $value) {
        $raw = is_scalar($value) ? trim((string)$value) : '';
        if (preg_match('/^[0-9]+$/D', $raw) !== 1 || (int)$raw < 1) {
            $invalid++;
            continue;
        }
        $id = (int)$raw;
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        if (count($ids) >= $limit) {
            $invalid++;
            continue;
        }
        $ids[] = $id;
    }

    return [$ids, $invalid];
}

function gallery_add_media_items(array $mediaIds, ?int $categoryId = null): array
{
    [$ids, $invalidInput] = gallery_normalize_ids($mediaIds);
    if ($ids === []) {
        return ['added' => 0, 'existing' => 0, 'invalid' => $invalidInput, 'item_ids' => []];
    }

    return gallery_transaction(static function () use ($ids, $invalidInput, $categoryId): array {
        if ($categoryId !== null && $categoryId > 0
            && one('SELECT id FROM sblog_gallery_categories WHERE id = ?', [$categoryId]) === null) {
            throw new InvalidArgumentException('所选图库分类不存在。');
        }
        $categoryId = $categoryId !== null && $categoryId > 0 ? $categoryId : null;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $validRows = all_rows(
            'SELECT id FROM media WHERE is_image = 1 AND id IN (' . $placeholders . ')',
            $ids
        );
        $validMap = [];
        foreach ($validRows as $row) {
            $validMap[(int)$row['id']] = true;
        }
        $validIds = array_values(array_filter($ids, static fn(int $id): bool => isset($validMap[$id])));
        $invalid = $invalidInput + count($ids) - count($validIds);
        if ($validIds === []) {
            return ['added' => 0, 'existing' => 0, 'invalid' => $invalid, 'item_ids' => []];
        }

        $validPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $existingRows = all_rows(
            'SELECT id, media_id FROM sblog_gallery_items WHERE media_id IN (' . $validPlaceholders . ')',
            $validIds
        );
        $existingMap = [];
        foreach ($existingRows as $row) {
            $existingMap[(int)$row['media_id']] = (int)$row['id'];
        }

        $sortOrder = max(0, (int)(val('SELECT MAX(sort_order) FROM sblog_gallery_items') ?: 0));
        $insert = db()->prepare(
            "INSERT OR IGNORE INTO sblog_gallery_items
             (media_id, category_id, title, description, sort_order, status, created_at, updated_at)
             VALUES(?,?,?,?,?,'published',?,?)"
        );
        $now = time();
        $itemIds = [];
        $existing = 0;

        foreach ($validIds as $mediaId) {
            if (isset($existingMap[$mediaId])) {
                $existing++;
                continue;
            }
            $sortOrder++;
            $insert->execute([$mediaId, $categoryId, '', '', $sortOrder, $now, $now]);
            if ($insert->rowCount() > 0) {
                $itemIds[] = (int)db()->lastInsertId();
            } else {
                $existing++;
            }
        }

        return [
            'added' => count($itemIds),
            'existing' => $existing,
            'invalid' => $invalid,
            'item_ids' => $itemIds,
        ];
    });
}

function gallery_update_item_record(int $itemId, array $input): bool
{
    if ($itemId < 1) {
        return false;
    }

    return gallery_transaction(static function () use ($itemId, $input): bool {
        if (one('SELECT id FROM sblog_gallery_items WHERE id = ?', [$itemId]) === null) {
            return false;
        }

        $assignments = [];
        $params = [];

        if (array_key_exists('category_id', $input)) {
            $raw = $input['category_id'];
            if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
                $categoryId = null;
            } else {
                $text = is_scalar($raw) ? trim((string)$raw) : '';
                if (preg_match('/^[0-9]+$/D', $text) !== 1 || (int)$text < 1) {
                    throw new InvalidArgumentException('所选图库分类无效。');
                }
                $categoryId = (int)$text;
                if (one('SELECT id FROM sblog_gallery_categories WHERE id = ?', [$categoryId]) === null) {
                    throw new InvalidArgumentException('所选图库分类不存在。');
                }
            }
            $assignments[] = 'category_id = ?';
            $params[] = $categoryId;
        }

        if (array_key_exists('title', $input)) {
            $title = trim(is_scalar($input['title']) ? (string)$input['title'] : '');
            if (str_sub_u($title, 0, 256) !== $title) {
                throw new InvalidArgumentException('图片标题不能超过 255 个字符。');
            }
            $assignments[] = 'title = ?';
            $params[] = $title;
        }

        if (array_key_exists('description', $input)) {
            $description = trim(is_scalar($input['description']) ? (string)$input['description'] : '');
            if (str_sub_u($description, 0, 5001) !== $description) {
                throw new InvalidArgumentException('图片描述不能超过 5000 个字符。');
            }
            $assignments[] = 'description = ?';
            $params[] = $description;
        }

        if (array_key_exists('sort_order', $input)) {
            $raw = is_scalar($input['sort_order']) ? trim((string)$input['sort_order']) : '';
            if (preg_match('/^-?[0-9]+$/D', $raw) !== 1) {
                throw new InvalidArgumentException('图片排序必须是整数。');
            }
            $assignments[] = 'sort_order = ?';
            $params[] = max(-999999, min(999999, (int)$raw));
        }

        if (array_key_exists('status', $input)) {
            $status = is_scalar($input['status']) ? (string)$input['status'] : '';
            if (!in_array($status, ['published', 'draft'], true)) {
                throw new InvalidArgumentException('图片状态无效。');
            }
            $assignments[] = 'status = ?';
            $params[] = $status;
        }

        if ($assignments === []) {
            return true;
        }
        $assignments[] = 'updated_at = ?';
        $params[] = time();
        $params[] = $itemId;
        q(
            'UPDATE sblog_gallery_items SET ' . implode(', ', $assignments) . ' WHERE id = ?',
            $params
        );
        return true;
    });
}

function gallery_remove_items(array $itemIds): int
{
    [$ids] = gallery_normalize_ids($itemIds);
    if ($ids === []) {
        return 0;
    }
    return gallery_transaction(static function () use ($ids): int {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return q(
            'DELETE FROM sblog_gallery_items WHERE id IN (' . $placeholders . ')',
            $ids
        )->rowCount();
    });
}

/**
 * @return array{0:int,1:int}
 */
function gallery_page_input(mixed $page, mixed $perPage, int $defaultPerPage, int $maximum): array
{
    $pageValue = is_scalar($page) && preg_match('/^[0-9]+$/D', trim((string)$page)) === 1
        ? (int)$page
        : 1;
    $perPageValue = is_scalar($perPage) && preg_match('/^[0-9]+$/D', trim((string)$perPage)) === 1
        ? (int)$perPage
        : $defaultPerPage;
    return [max(1, $pageValue), max(1, min($maximum, $perPageValue))];
}

function gallery_like_pattern(string $term): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
}

function gallery_admin_items(array $filters = []): array
{
    [$page, $perPage] = gallery_page_input(
        $filters['page'] ?? 1,
        $filters['per_page'] ?? SBLOG_GALLERY_DEFAULT_PER_PAGE,
        SBLOG_GALLERY_DEFAULT_PER_PAGE,
        100
    );
    $where = ['m.is_image = 1'];
    $params = [];
    $search = trim(is_scalar($filters['q'] ?? null) ? (string)$filters['q'] : '');
    if ($search !== '') {
        $where[] = "(gi.title LIKE ? ESCAPE '\\' OR gi.description LIKE ? ESCAPE '\\' "
            . "OR m.title LIKE ? ESCAPE '\\' OR m.original_name LIKE ? ESCAPE '\\' "
            . "OR m.caption LIKE ? ESCAPE '\\')";
        $pattern = gallery_like_pattern(str_sub_u($search, 0, 200));
        array_push($params, $pattern, $pattern, $pattern, $pattern, $pattern);
    }
    $categoryRaw = $filters['category_id'] ?? null;
    if (is_scalar($categoryRaw) && preg_match('/^[0-9]+$/D', trim((string)$categoryRaw)) === 1
        && (int)$categoryRaw > 0) {
        $where[] = 'gi.category_id = ?';
        $params[] = (int)$categoryRaw;
    }
    $status = is_scalar($filters['status'] ?? null) ? (string)$filters['status'] : '';
    if (in_array($status, ['published', 'draft'], true)) {
        $where[] = 'gi.status = ?';
        $params[] = $status;
    }

    $from = ' FROM sblog_gallery_items gi '
        . 'JOIN media m ON m.id = gi.media_id '
        . 'LEFT JOIN sblog_gallery_categories c ON c.id = gi.category_id ';
    $condition = ' WHERE ' . implode(' AND ', $where);
    $total = (int)val('SELECT COUNT(*)' . $from . $condition, $params);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $items = all_rows(
        "SELECT gi.*, m.original_name, m.title AS media_title, m.alt_text, m.caption,
                m.url, m.mime_type, m.file_size, m.width, m.height,
                c.name AS category_name, c.slug AS category_slug,
                COALESCE(NULLIF(gi.title, ''), NULLIF(m.title, ''), m.original_name) AS display_title"
        . $from . $condition
        . ' ORDER BY gi.sort_order ASC, gi.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $params
    );

    return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total];
}

function gallery_media_search(array $filters = []): array
{
    [$page, $perPage] = gallery_page_input(
        $filters['page'] ?? 1,
        $filters['per_page'] ?? SBLOG_GALLERY_DEFAULT_PER_PAGE,
        SBLOG_GALLERY_DEFAULT_PER_PAGE,
        60
    );
    $where = ['m.is_image = 1'];
    $params = [];
    $search = trim(is_scalar($filters['q'] ?? null) ? (string)$filters['q'] : '');
    if ($search !== '') {
        $where[] = "(m.title LIKE ? ESCAPE '\\' OR m.original_name LIKE ? ESCAPE '\\' "
            . "OR m.alt_text LIKE ? ESCAPE '\\' OR m.caption LIKE ? ESCAPE '\\')";
        $pattern = gallery_like_pattern(str_sub_u($search, 0, 200));
        array_push($params, $pattern, $pattern, $pattern, $pattern);
    }
    $from = ' FROM media m LEFT JOIN sblog_gallery_items gi ON gi.media_id = m.id ';
    $condition = ' WHERE ' . implode(' AND ', $where);
    $total = (int)val('SELECT COUNT(*)' . $from . $condition, $params);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $items = all_rows(
        "SELECT m.id, m.original_name, m.title, m.alt_text, m.caption, m.url,
                m.mime_type, m.file_size, m.width, m.height, m.created_at,
                gi.id AS gallery_item_id, gi.category_id AS gallery_category_id,
                gi.status AS gallery_status,
                CASE WHEN gi.id IS NULL THEN 0 ELSE 1 END AS added"
        . $from . $condition
        . ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $params
    );
    foreach ($items as &$item) {
        $item['added'] = !empty($item['added']);
    }
    unset($item);

    return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total];
}

function gallery_public_items(array $filters = []): array
{
    $configuredPerPage = (int)gallery_setting('per_page', (string)SBLOG_GALLERY_DEFAULT_PER_PAGE);
    $configuredPerPage = max(6, min(60, $configuredPerPage));
    [$page, $perPage] = gallery_page_input(
        $filters['page'] ?? 1,
        $filters['per_page'] ?? $configuredPerPage,
        $configuredPerPage,
        60
    );
    $categorySlug = trim(is_scalar($filters['category_slug'] ?? null)
        ? (string)$filters['category_slug']
        : '');
    $categoryRequested = $categorySlug !== '';
    $category = null;

    if ($categoryRequested) {
        $category = one(
            'SELECT * FROM sblog_gallery_categories WHERE slug = ? COLLATE NOCASE LIMIT 1',
            [str_sub_u($categorySlug, 0, 100)]
        );
        if ($category === null) {
            return [
                'items' => [], 'page' => $page, 'pages' => 1, 'total' => 0,
                'category' => null, 'category_requested' => true,
            ];
        }
    }

    $where = ["gi.status = 'published'", 'm.is_image = 1'];
    $params = [];
    if ($category !== null) {
        $where[] = 'gi.category_id = ?';
        $params[] = (int)$category['id'];
    }
    $from = ' FROM sblog_gallery_items gi '
        . 'JOIN media m ON m.id = gi.media_id '
        . 'LEFT JOIN sblog_gallery_categories c ON c.id = gi.category_id ';
    $condition = ' WHERE ' . implode(' AND ', $where);
    $total = (int)val('SELECT COUNT(*)' . $from . $condition, $params);
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $items = all_rows(
        "SELECT gi.id, gi.media_id, gi.category_id, gi.title, gi.description,
                gi.sort_order, gi.created_at, gi.updated_at,
                m.original_name, m.title AS media_title, m.alt_text, m.caption,
                m.url, m.width, m.height,
                c.name AS category_name, c.slug AS category_slug,
                COALESCE(NULLIF(gi.title, ''), NULLIF(m.title, ''), m.original_name) AS display_title"
        . $from . $condition
        . ' ORDER BY gi.sort_order ASC, gi.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $params
    );

    return [
        'items' => $items,
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
        'category' => $category,
        'category_requested' => $categoryRequested,
    ];
}
