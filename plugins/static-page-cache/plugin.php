<?php
declare(strict_types=1);

const SBLOG_STATIC_PAGE_CACHE_VERSION = '1.0.0';
const SBLOG_STATIC_PAGE_CACHE_FORMAT = 1;
const SBLOG_STATIC_PAGE_CACHE_MAX_BYTES = 5242880;
const SBLOG_STATIC_PAGE_CACHE_MAX_ENTRIES = 1000;
const SBLOG_STATIC_PAGE_CACHE_LOCK_SHARDS = 64;

function spc_cache_directory(): string
{
    return CACHE_DIR . '/static-page-cache';
}

function spc_disabled_file(): string
{
    return spc_cache_directory() . '/disabled';
}

function spc_register_translations(): void
{
    sblog_i18n_register('en', [
        '静态页面缓存' => 'Static page cache',
        '缓存设置' => 'Cache settings',
        '启用静态页面缓存' => 'Enable static page caching',
        '仅缓存匿名访客访问的已发布文章和独立页面。' => 'Cache published posts and standalone pages for anonymous visitors only.',
        '缓存有效期' => 'Cache lifetime',
        '15 分钟' => '15 minutes',
        '1 小时' => '1 hour',
        '6 小时' => '6 hours',
        '24 小时' => '24 hours',
        '保存缓存设置' => 'Save cache settings',
        '立即清空缓存' => 'Clear cache now',
        '缓存文件' => 'Cached pages',
        '占用空间' => 'Disk usage',
        '排除内容' => 'Excluded content',
        '当前没有手动排除的内容。' => 'No content is manually excluded.',
        '缓存设置已保存。' => 'Cache settings saved.',
        '静态页面缓存已清空。' => 'Static page cache cleared.',
        '缓存失效状态无法写入，已暂停缓存以避免返回过期页面。' => 'The invalidation state could not be saved, so caching was paused to avoid stale pages.',
        '跳过缓存' => 'Skip cache',
        '此内容始终动态生成，不写入静态页面缓存。' => 'Always render this content dynamically instead of writing it to the static page cache.',
        '密码保护和回复可见内容会自动绕过缓存。缓存命中时不会增加文章浏览量。' => 'Password-protected and reply-gated content automatically bypasses caching. Cache hits do not increment post views.',
        '缓存目录不可写，插件已暂停缓存。' => 'The cache directory is not writable, so caching is paused.',
        '请先在站点设置中配置准确的站点地址；其他域名的请求不会写入缓存。' => 'Configure the exact site URL in site settings first. Requests for other hosts are never cached.',
        '已启用' => 'Enabled',
        '已停用' => 'Disabled',
    ]);
    sblog_i18n_register('ru', [
        '静态页面缓存' => 'Кэш статических страниц',
        '缓存设置' => 'Настройки кэша',
        '启用静态页面缓存' => 'Включить кэширование статических страниц',
        '仅缓存匿名访客访问的已发布文章和独立页面。' => 'Кэшировать только опубликованные записи и отдельные страницы для анонимных посетителей.',
        '缓存有效期' => 'Срок хранения кэша',
        '15 分钟' => '15 минут',
        '1 小时' => '1 час',
        '6 小时' => '6 часов',
        '24 小时' => '24 часа',
        '保存缓存设置' => 'Сохранить настройки кэша',
        '立即清空缓存' => 'Очистить кэш',
        '缓存文件' => 'Страницы в кэше',
        '占用空间' => 'Использование диска',
        '排除内容' => 'Исключенное содержимое',
        '当前没有手动排除的内容。' => 'Нет содержимого, исключенного вручную.',
        '缓存设置已保存。' => 'Настройки кэша сохранены.',
        '静态页面缓存已清空。' => 'Кэш статических страниц очищен.',
        '缓存失效状态无法写入，已暂停缓存以避免返回过期页面。' => 'Не удалось сохранить состояние сброса кэша. Кэширование приостановлено, чтобы не показывать устаревшие страницы.',
        '跳过缓存' => 'Не кэшировать',
        '此内容始终动态生成，不写入静态页面缓存。' => 'Всегда формировать это содержимое динамически и не сохранять его в кэше.',
        '密码保护和回复可见内容会自动绕过缓存。缓存命中时不会增加文章浏览量。' => 'Защищенное паролем и скрытое до ответа содержимое автоматически обходит кэш. Попадания в кэш не увеличивают счетчик просмотров.',
        '缓存目录不可写，插件已暂停缓存。' => 'Каталог кэша недоступен для записи. Кэширование приостановлено.',
        '请先在站点设置中配置准确的站点地址；其他域名的请求不会写入缓存。' => 'Сначала укажите точный адрес сайта в настройках. Запросы к другим доменам не кэшируются.',
        '已启用' => 'Включено',
        '已停用' => 'Отключено',
    ]);
}

function spc_ensure_cache_directory(): bool
{
    $directory = spc_cache_directory();
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }
    return is_writable($directory);
}

function spc_excluded_ids(): array
{
    $decoded = json_decode(setting('static_page_cache_excluded_ids', '[]'), true);
    if (!is_array($decoded)) {
        return [];
    }
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $decoded),
        static fn(int $id): bool => $id > 0
    )));
    sort($ids, SORT_NUMERIC);
    return array_slice($ids, 0, 5000);
}

function spc_settings(): array
{
    $ttl = (int)setting('static_page_cache_ttl', '3600');
    if (!in_array($ttl, [900, 3600, 21600, 86400], true)) {
        $ttl = 3600;
    }
    $generation = trim(setting('static_page_cache_generation', ''));
    return [
        'enabled' => setting('static_page_cache_enabled', '1') === '1'
            && preg_match('/^[a-f0-9]{32}$/D', $generation) === 1
            && !is_file(spc_disabled_file()),
        'ttl' => $ttl,
        'generation' => $generation,
        'excluded_ids' => spc_excluded_ids(),
    ];
}

function spc_install(): void
{
    spc_register_translations();
    if (!spc_ensure_cache_directory()) {
        @file_put_contents(spc_disabled_file(), (string)time(), LOCK_EX);
        return;
    }
    if (preg_match('/^[a-f0-9]{32}$/D', trim(setting('static_page_cache_generation', ''))) === 1) {
        return;
    }
    try {
        save_settings([
            'static_page_cache_enabled' => setting('static_page_cache_enabled', '1') === '0' ? '0' : '1',
            'static_page_cache_ttl' => '3600',
            'static_page_cache_generation' => bin2hex(random_bytes(16)),
            'static_page_cache_excluded_ids' => '[]',
        ]);
        @unlink(spc_disabled_file());
    } catch (Throwable $exception) {
        @file_put_contents(spc_disabled_file(), (string)time(), LOCK_EX);
        error_log('Static page cache setup failed: ' . $exception->getMessage());
    }
}

function spc_cache_paths(string $key): array
{
    $base = spc_cache_directory() . '/' . $key;
    $lockPrefix = preg_match('/^[a-f0-9]{64}$/D', $key) === 1
        ? substr($key, 0, 2)
        : substr(hash('sha256', $key), 0, 2);
    $lockShard = hexdec($lockPrefix) % SBLOG_STATIC_PAGE_CACHE_LOCK_SHARDS;
    return [
        'html' => $base . '.html',
        'meta' => $base . '.json',
        'lock' => spc_cache_directory() . '/generation-' . sprintf('%02x', $lockShard) . '.lock',
    ];
}

function spc_remove_cache_pair(string $key): void
{
    if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
        return;
    }
    $paths = spc_cache_paths($key);
    @unlink($paths['html']);
    @unlink($paths['meta']);
}

function spc_clear_cache_files(): int
{
    if (!spc_ensure_cache_directory()) {
        return 0;
    }
    $removed = 0;
    foreach (scandir(spc_cache_directory()) ?: [] as $name) {
        if (!preg_match('/^[a-f0-9]{64}\.(?:html|json)(?:\.[a-f0-9]{12}\.tmp)?$/D', $name)) {
            continue;
        }
        $path = spc_cache_directory() . '/' . $name;
        if (is_file($path) && @unlink($path)) {
            $removed++;
        }
    }
    return $removed;
}

function spc_rotate_generation(array $settings = []): bool
{
    $GLOBALS['sblog_static_page_cache_rotation_attempted'] = true;
    try {
        $settings['static_page_cache_generation'] = bin2hex(random_bytes(16));
        save_settings($settings);
        @unlink(spc_disabled_file());
        spc_clear_cache_files();
        return true;
    } catch (Throwable $exception) {
        spc_ensure_cache_directory();
        @file_put_contents(spc_disabled_file(), (string)time(), LOCK_EX);
        spc_clear_cache_files();
        error_log('Static page cache invalidation failed: ' . $exception->getMessage());
        return false;
    }
}

function spc_url_origin(string $url): string
{
    $parts = parse_url(trim($url));
    if (!is_array($parts)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user'])
        || isset($parts['pass'])) {
        return '';
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    $host = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
    $port = (int)($parts['port'] ?? 0);
    if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
        $port = 0;
    }
    return $scheme . '://' . $host . ($port > 0 ? ':' . $port : '');
}

function spc_normalized_origin(): string
{
    $configured = spc_url_origin(setting('site_url'));
    if ($configured === '') {
        return '';
    }
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
    if ($host === '' || strlen($host) > 255 || preg_match('~[\x00-\x20\x7f/\\\\]~', $host)) {
        return '';
    }
    $scheme = str_starts_with($configured, 'https://') ? 'https' : 'http';
    $request = spc_url_origin($scheme . '://' . $host);
    return $request !== '' && hash_equals($configured, $request) ? $configured : '';
}

function spc_request_descriptor(string $action): ?array
{
    if (!in_array($action, ['post', 'page'], true)
        || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET'
        || isset($_SERVER['PHP_AUTH_USER'])
        || trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')) !== '') {
        return null;
    }

    $source = array_key_exists('slug', $_GET) ? 'slug' : (array_key_exists('id', $_GET) ? 'id' : '');
    if ($source === '' || (isset($_GET['slug']) && isset($_GET['id'])) || is_array($_GET[$source])) {
        return null;
    }
    $identifier = trim((string)$_GET[$source]);
    if ($identifier === '' || strlen($identifier) > 500) {
        return null;
    }
    $numericIdentifier = preg_match('/^[0-9]+$/D', $identifier) === 1;
    if (($source === 'id' && !$numericIdentifier)
        || ($numericIdentifier && ((int)$identifier < 1 || $identifier !== (string)(int)$identifier))) {
        return null;
    }
    $keys = array_keys($_GET);
    sort($keys, SORT_STRING);
    $allowed = ['a', $source];
    sort($allowed, SORT_STRING);
    if ($keys !== $allowed || (string)($_GET['a'] ?? '') !== $action) {
        return null;
    }
    $origin = spc_normalized_origin();
    if ($origin === '') {
        return null;
    }
    return [
        'action' => $action,
        'source' => $source,
        'identifier' => $identifier,
        'origin' => $origin,
    ];
}

function spc_session_is_public(): bool
{
    $allowed = ['csrf_token' => true, 'comment_forms' => true];
    foreach (array_keys($_SESSION) as $key) {
        if (!isset($allowed[(string)$key])) {
            return false;
        }
    }
    return (int)($_SESSION['admin_id'] ?? 0) < 1;
}

function spc_file_signature(string $path): array
{
    return is_file($path) ? [basename($path), (int)filesize($path), (int)filemtime($path)] : [basename($path), 0, 0];
}

function spc_dependency_signature(array $settings, array $descriptor): string
{
    $themeSlug = active_theme_slug();
    $theme = theme_manifest($themeSlug) ?? [];
    $files = [
        spc_file_signature(__DIR__ . '/plugin.php'),
        spc_file_signature(__DIR__ . '/plugin.json'),
        spc_file_signature(__DIR__ . '/assets/admin.css'),
        spc_file_signature(dirname(__DIR__, 2) . '/assets/index.css'),
        spc_file_signature(dirname(__DIR__, 2) . '/assets/index.js'),
    ];
    if ($themeSlug !== 'default') {
        foreach (['theme.json', 'functions.php', 'layout.php', 'style.css', 'script.js'] as $file) {
            $files[] = spc_file_signature(THEMES_DIR . '/' . $themeSlug . '/' . $file);
        }
    }
    $plugins = [];
    foreach (($GLOBALS['sblog_loaded_plugins'] ?? []) as $slug) {
        $slug = (string)$slug;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $slug)) {
            continue;
        }
        $manifest = plugin_manifest($slug) ?? [];
        $plugins[] = [
            $slug,
            (string)($manifest['version'] ?? ''),
            spc_file_signature(PLUGINS_DIR . '/' . $slug . '/plugin.php'),
        ];
    }
    $settingsHash = is_file(SETTINGS_CACHE_FILE) ? (string)hash_file('sha256', SETTINGS_CACHE_FILE) : '';
    $payload = json_encode([
        'format' => SBLOG_STATIC_PAGE_CACHE_FORMAT,
        'plugin' => SBLOG_STATIC_PAGE_CACHE_VERSION,
        'app' => APP_VERSION,
        'generation' => (string)$settings['generation'],
        'origin' => (string)$descriptor['origin'],
        'base_path' => app_base_path(),
        'locale' => sblog_i18n_locale(),
        'theme' => [$themeSlug, (string)($theme['version'] ?? '')],
        'plugins' => $plugins,
        'settings' => $settingsHash,
        'files' => $files,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return hash('sha256', is_string($payload) ? $payload : '');
}

function spc_cache_key(array $descriptor, string $dependency): string
{
    $payload = json_encode([
        'action' => (string)$descriptor['action'],
        'source' => (string)$descriptor['source'],
        'identifier' => (string)$descriptor['identifier'],
        'dependency' => $dependency,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return hash('sha256', is_string($payload) ? $payload : '');
}

function spc_read_cache(string $key, string $dependency): ?array
{
    $paths = spc_cache_paths($key);
    if (!is_file($paths['meta']) || !is_file($paths['html'])) {
        return null;
    }
    $meta = json_decode((string)@file_get_contents($paths['meta']), true);
    if (!is_array($meta)
        || (int)($meta['format'] ?? 0) !== SBLOG_STATIC_PAGE_CACHE_FORMAT
        || !hash_equals($key, (string)($meta['key'] ?? ''))
        || !hash_equals($dependency, (string)($meta['dependency'] ?? ''))
        || (int)($meta['expires_at'] ?? 0) <= time()) {
        spc_remove_cache_pair($key);
        return null;
    }
    $size = (int)@filesize($paths['html']);
    if ($size < 1 || $size > SBLOG_STATIC_PAGE_CACHE_MAX_BYTES || $size !== (int)($meta['bytes'] ?? -1)) {
        spc_remove_cache_pair($key);
        return null;
    }
    $html = @file_get_contents($paths['html']);
    if (!is_string($html) || !hash_equals((string)($meta['sha256'] ?? ''), hash('sha256', $html))) {
        spc_remove_cache_pair($key);
        return null;
    }
    return ['html' => $html, 'meta' => $meta];
}

function spc_release_plan_lock(): void
{
    $lock = $GLOBALS['sblog_static_page_cache_lock'] ?? null;
    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
    unset($GLOBALS['sblog_static_page_cache_lock']);
}

function spc_acquire_generation_lock(string $path): bool
{
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        return false;
    }
    for ($attempt = 0; $attempt < 25; $attempt++) {
        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            $GLOBALS['sblog_static_page_cache_lock'] = $handle;
            return true;
        }
        usleep(20000);
    }
    fclose($handle);
    return false;
}

function spc_end_core_output_buffer(): bool
{
    $status = ob_get_status();
    if (!is_array($status) || (string)($status['name'] ?? '') !== 'plugin_output_buffer') {
        return false;
    }
    return ob_end_clean();
}

function spc_serve_cache(array $entry): bool
{
    if (!spc_end_core_output_buffer()) {
        spc_release_plan_lock();
        spc_mark_bypass();
        return false;
    }
    $html = (string)$entry['html'];
    $meta = (array)$entry['meta'];
    $csrfMarker = (string)($meta['csrf_marker'] ?? '');
    $startedMarker = (string)($meta['started_marker'] ?? '');
    if ($csrfMarker !== '' && str_contains($html, $csrfMarker)) {
        $html = str_replace($csrfMarker, h(csrf_token()), $html);
    }
    if ($startedMarker !== '' && str_contains($html, $startedMarker)) {
        $html = str_replace($startedMarker, h((string)comment_form_started_at((int)$meta['post_id'])), $html);
    }

    spc_release_plan_lock();
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('Content-Language: ' . sblog_i18n_locale());
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Vary: Cookie', false);
        header('X-Content-Type-Options: nosniff');
        header('X-SBlog-Page-Cache: HIT');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    echo $html;
    exit;
}

function spc_find_public_content(array $descriptor): ?array
{
    $kind = (string)$descriptor['action'];
    $identifier = (string)$descriptor['identifier'];
    $row = one(
        'SELECT id, kind, slug, status, published_at, content, content_password_hash FROM posts WHERE slug = ? AND kind = ?',
        [$identifier, $kind]
    );
    if (!$row && is_ascii_digits($identifier)) {
        $row = one(
            'SELECT id, kind, slug, status, published_at, content, content_password_hash FROM posts WHERE id = ? AND kind = ?',
            [(int)$identifier, $kind]
        );
    }
    if (!$row || !is_live_content($row)) {
        return null;
    }
    return $row;
}

function spc_next_scheduled_publish_at(): int
{
    static $loaded = false;
    static $timestamp = 0;
    if ($loaded) {
        return $timestamp;
    }
    $loaded = true;
    $next = val('SELECT MIN(published_at) FROM posts WHERE status = ? AND published_at > ?', ['published', time()]);
    $timestamp = is_numeric($next) ? (int)$next : 0;
    return $timestamp;
}

function spc_mark_bypass(): void
{
    if (!headers_sent()) {
        header('X-SBlog-Page-Cache: BYPASS');
    }
}

function spc_prepare_public_request(string $action): void
{
    $settings = spc_settings();
    if (!$settings['enabled'] || !spc_session_is_public()) {
        return;
    }
    $descriptor = spc_request_descriptor($action);
    if ($descriptor === null) {
        return;
    }
    $dependency = spc_dependency_signature($settings, $descriptor);
    $key = spc_cache_key($descriptor, $dependency);
    $cached = spc_read_cache($key, $dependency);
    if ($cached !== null && !spc_serve_cache($cached)) {
        return;
    }

    $content = spc_find_public_content($descriptor);
    if ($content === null
        || in_array((int)$content['id'], $settings['excluded_ids'], true)
        || trim((string)$content['content_password_hash']) !== ''
        || content_has_reply_hidden_blocks((string)$content['content'])) {
        spc_mark_bypass();
        return;
    }

    $paths = spc_cache_paths($key);
    if (!spc_acquire_generation_lock($paths['lock'])) {
        spc_mark_bypass();
        return;
    }
    $cached = spc_read_cache($key, $dependency);
    if ($cached !== null && !spc_serve_cache($cached)) {
        return;
    }

    $expiresAt = time() + (int)$settings['ttl'];
    $nextPublish = spc_next_scheduled_publish_at();
    if ($nextPublish > time()) {
        $expiresAt = min($expiresAt, $nextPublish);
    }
    $GLOBALS['sblog_static_page_cache_plan'] = [
        'key' => $key,
        'paths' => $paths,
        'dependency' => $dependency,
        'descriptor' => $descriptor,
        'post_id' => (int)$content['id'],
        'expires_at' => $expiresAt,
    ];
}

function spc_atomic_write(string $path, string $contents): bool
{
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $written = @file_put_contents($temporary, $contents, LOCK_EX);
    if (!is_int($written) || $written !== strlen($contents)) {
        @unlink($temporary);
        return false;
    }
    @chmod($temporary, 0644);
    if (is_file($path) && !@unlink($path)) {
        @unlink($temporary);
        return false;
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }
    return true;
}

function spc_prepare_stored_html(string $html, int $postId): ?array
{
    if ($html === '' || strlen($html) > SBLOG_STATIC_PAGE_CACHE_MAX_BYTES
        || stripos($html, 'data-post-like') !== false
        || preg_match('/\bnonce\s*=\s*["\']/i', $html) === 1) {
        return null;
    }
    $csrfMarker = 'SBLOG_CACHE_CSRF_' . bin2hex(random_bytes(16));
    $startedMarker = 'SBLOG_CACHE_STARTED_' . bin2hex(random_bytes(16));
    $csrf = csrf_token();
    $stored = str_contains($html, $csrf) ? str_replace($csrf, $csrfMarker, $html) : $html;
    $stored = preg_replace(
        '~(<input\b[^>]*\bname="comment_started_at"[^>]*\bvalue=")[^"]*(")~i',
        '$1' . $startedMarker . '$2',
        $stored
    );
    if (!is_string($stored)) {
        return null;
    }

    preg_match_all('~<form\b(?=[^>]*\bmethod\s*=\s*(?:"post"|\'post\'|post))[^>]*>.*?</form\s*>~is', $stored, $forms);
    foreach ($forms[0] ?? [] as $form) {
        if (!str_contains((string)$form, $csrfMarker)) {
            return null;
        }
        if (str_contains((string)$form, 'name="comment_started_at"') && !str_contains((string)$form, $startedMarker)) {
            return null;
        }
    }
    if (preg_match('~<form\b(?=[^>]*\bmethod\s*=\s*(?:"post"|\'post\'|post))~i', $stored) === 1
        && ($forms[0] ?? []) === []) {
        return null;
    }
    return [
        'html' => $stored,
        'csrf_marker' => $csrfMarker,
        'started_marker' => $startedMarker,
        'post_id' => $postId,
    ];
}

function spc_prune_cache(string $dependency): void
{
    $entries = [];
    foreach (glob(spc_cache_directory() . '/*.json') ?: [] as $metaFile) {
        $key = basename($metaFile, '.json');
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            continue;
        }
        $meta = json_decode((string)@file_get_contents($metaFile), true);
        if (!is_array($meta) || (int)($meta['expires_at'] ?? 0) <= time()
            || !hash_equals($dependency, (string)($meta['dependency'] ?? ''))) {
            spc_remove_cache_pair($key);
            continue;
        }
        $entries[$key] = (int)($meta['created_at'] ?? 0);
    }
    if (count($entries) < SBLOG_STATIC_PAGE_CACHE_MAX_ENTRIES) {
        return;
    }
    asort($entries, SORT_NUMERIC);
    foreach (array_slice(array_keys($entries), 0, count($entries) - 900) as $key) {
        spc_remove_cache_pair((string)$key);
    }
}

function spc_store_output(string $html, array $context): string
{
    $plan = $GLOBALS['sblog_static_page_cache_plan'] ?? null;
    if (!is_array($plan) || (string)($context['action'] ?? '') !== (string)$plan['descriptor']['action']) {
        return $html;
    }
    unset($GLOBALS['sblog_static_page_cache_plan']);
    try {
        if (http_response_code() !== 200) {
            spc_mark_bypass();
            return $html;
        }
        $prepared = spc_prepare_stored_html($html, (int)$plan['post_id']);
        if ($prepared === null) {
            spc_mark_bypass();
            return $html;
        }
        spc_prune_cache((string)$plan['dependency']);
        $stored = (string)$prepared['html'];
        $meta = json_encode([
            'format' => SBLOG_STATIC_PAGE_CACHE_FORMAT,
            'key' => (string)$plan['key'],
            'dependency' => (string)$plan['dependency'],
            'action' => (string)$plan['descriptor']['action'],
            'identifier' => (string)$plan['descriptor']['identifier'],
            'post_id' => (int)$plan['post_id'],
            'created_at' => time(),
            'expires_at' => (int)$plan['expires_at'],
            'bytes' => strlen($stored),
            'sha256' => hash('sha256', $stored),
            'csrf_marker' => (string)$prepared['csrf_marker'],
            'started_marker' => (string)$prepared['started_marker'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($meta)
            || !spc_atomic_write((string)$plan['paths']['html'], $stored)
            || !spc_atomic_write((string)$plan['paths']['meta'], $meta)) {
            spc_remove_cache_pair((string)$plan['key']);
            spc_mark_bypass();
            return $html;
        }
        if (!headers_sent()) {
            header('Cache-Control: private, no-store, max-age=0');
            header('Vary: Cookie', false);
            header('X-SBlog-Page-Cache: MISS');
        }
        return $html;
    } catch (Throwable $exception) {
        spc_remove_cache_pair((string)$plan['key']);
        spc_mark_bypass();
        error_log('Static page cache write failed: ' . $exception->getMessage());
        return $html;
    } finally {
        spc_release_plan_lock();
    }
}

function spc_editor_control(string $html, array $context): string
{
    if ((string)($context['field'] ?? '') !== 'slug') {
        return $html;
    }
    $postId = (int)($context['post_id'] ?? 0);
    $editingRequest = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
        && in_array((string)($GLOBALS['sblog_current_action'] ?? ''), ['write', 'edit'], true);
    $excluded = $editingRequest
        ? (string)($_POST['static_page_cache_exclude'] ?? '') === '1'
        : in_array($postId, spc_excluded_ids(), true);
    return $html
        . '<label class="static-page-cache-editor-toggle" title="' . h(sblog_t('此内容始终动态生成，不写入静态页面缓存。')) . '">'
        . '<input name="static_page_cache_exclude" type="checkbox" value="1"' . ($excluded ? ' checked' : '') . '>'
        . '<span>' . h(sblog_t('跳过缓存')) . '</span></label>';
}

function spc_post_saved(array $context): void
{
    $postId = (int)($context['post_id'] ?? 0);
    $action = (string)($GLOBALS['sblog_current_action'] ?? '');
    $values = [];
    if ($postId > 0 && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
        && in_array($action, ['write', 'edit'], true)) {
        $ids = spc_excluded_ids();
        $excluded = (string)($_POST['static_page_cache_exclude'] ?? '') === '1';
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $postId));
        if ($excluded) {
            $ids[] = $postId;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $encoded = json_encode($ids, JSON_UNESCAPED_SLASHES);
        $values['static_page_cache_excluded_ids'] = is_string($encoded) ? $encoded : '[]';
    }
    spc_rotate_generation($values);
}

function spc_valid_csrf_request(): bool
{
    $posted = (string)($_POST['csrf_token'] ?? '');
    $stored = (string)($_SESSION['csrf_token'] ?? '');
    return $posted !== '' && $stored !== '' && hash_equals($stored, $posted);
}

function spc_rotate_generation_on_shutdown(): void
{
    register_shutdown_function(static function (): void {
        spc_rotate_generation();
    });
}

function spc_effective_request_method(): string
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $override = strtoupper(trim((string)($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_REQUEST['_method'] ?? '')));
    return in_array($override, ['PUT', 'PATCH', 'DELETE'], true) ? $override : $method;
}

function spc_finish_rest_mutation_invalidation(?int $status = null): void
{
    if (empty($GLOBALS['sblog_static_page_cache_rest_mutation_pending'])) {
        return;
    }
    unset($GLOBALS['sblog_static_page_cache_rest_mutation_pending']);
    if (!empty($GLOBALS['sblog_static_page_cache_rotation_attempted'])) {
        return;
    }
    $status ??= http_response_code();
    if ($status >= 200 && $status < 300) {
        spc_rotate_generation();
    }
}

function spc_watch_rest_mutation(array $context): void
{
    if ((string)($context['action'] ?? '') !== 'sblog_rest_api'
        || !in_array(spc_effective_request_method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
        || !empty($GLOBALS['sblog_static_page_cache_rest_mutation_pending'])) {
        return;
    }
    $GLOBALS['sblog_static_page_cache_rest_mutation_pending'] = true;
    register_shutdown_function('spc_finish_rest_mutation_invalidation');
}

function spc_cache_stats(): array
{
    $count = 0;
    $bytes = 0;
    foreach (glob(spc_cache_directory() . '/*.html') ?: [] as $file) {
        if (!preg_match('/^[a-f0-9]{64}\.html$/D', basename($file))) {
            continue;
        }
        $count++;
        $bytes += max(0, (int)@filesize($file));
    }
    return ['count' => $count, 'bytes' => $bytes];
}

function spc_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return number_format($bytes / 1048576, 1) . ' MB';
}

function spc_excluded_posts(): array
{
    $ids = spc_excluded_ids();
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return all_rows(
        "SELECT id, kind, title FROM posts WHERE id IN ({$placeholders}) ORDER BY title COLLATE NOCASE ASC, id ASC",
        $ids
    );
}

function spc_render_settings_page(): void
{
    require_admin();
    $settings = spc_settings();
    $stats = spc_cache_stats();
    $excluded = spc_excluded_posts();
    $saveUrl = script_url() . '?a=save_static_page_cache';
    $clearUrl = script_url() . '?a=clear_static_page_cache';
    $sidebar = render_admin_sidebar('plugins');
    ob_start(); ?>
    <div class="admin-shell">
      <?= $sidebar ?>
      <div class="admin-main">
        <?= render_admin_topbar(sblog_t('静态页面缓存')) ?>
        <section class="panel admin-list-panel admin-animate admin-animate--2">
          <div class="panel__header"><h2><?= h(sblog_t('缓存设置')) ?></h2></div>
          <div class="panel__body">
            <div class="static-page-cache-summary" aria-label="<?= h(sblog_t('静态页面缓存')) ?>">
              <div><span><?= h(sblog_t('缓存设置')) ?></span><strong><?= h(sblog_t($settings['enabled'] ? '已启用' : '已停用')) ?></strong></div>
              <div><span><?= h(sblog_t('缓存文件')) ?></span><strong><?= h((string)$stats['count']) ?></strong></div>
              <div><span><?= h(sblog_t('占用空间')) ?></span><strong><?= h(spc_format_bytes((int)$stats['bytes'])) ?></strong></div>
            </div>
            <form class="form-stack" method="post" action="<?= h($saveUrl) ?>">
              <?= csrf_field() ?>
              <label class="setting-option" for="static_page_cache_enabled">
                <input id="static_page_cache_enabled" name="enabled" type="checkbox" value="1"<?= $settings['enabled'] ? ' checked' : '' ?>>
                <span><strong><?= h(sblog_t('启用静态页面缓存')) ?></strong><small><?= h(sblog_t('仅缓存匿名访客访问的已发布文章和独立页面。')) ?></small></span>
              </label>
              <div class="field">
                <label for="static_page_cache_ttl"><?= h(sblog_t('缓存有效期')) ?></label>
                <select id="static_page_cache_ttl" name="ttl">
                  <?php foreach ([900 => '15 分钟', 3600 => '1 小时', 21600 => '6 小时', 86400 => '24 小时'] as $seconds => $label): ?>
                    <option value="<?= h((string)$seconds) ?>"<?= (int)$settings['ttl'] === $seconds ? ' selected' : '' ?>><?= h(sblog_t($label)) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="field-hint"><?= h(sblog_t('密码保护和回复可见内容会自动绕过缓存。缓存命中时不会增加文章浏览量。')) ?></p>
                <p class="field-hint"><?= h(sblog_t('请先在站点设置中配置准确的站点地址；其他域名的请求不会写入缓存。')) ?></p>
              </div>
              <div class="action-row"><button class="button" type="submit"><?= h(sblog_t('保存缓存设置')) ?></button></div>
            </form>
            <form method="post" action="<?= h($clearUrl) ?>">
              <?= csrf_field() ?>
              <button class="button button--secondary" type="submit"><?= h(sblog_t('立即清空缓存')) ?></button>
            </form>
          </div>
        </section>
        <section class="panel admin-list-panel admin-animate admin-animate--3">
          <div class="panel__header"><h2><?= h(sblog_t('排除内容')) ?></h2><span class="panel__meta"><?= h((string)count($excluded)) ?></span></div>
          <div class="panel__body">
            <?php if ($excluded): ?>
              <ul class="static-page-cache-exclusions">
                <?php foreach ($excluded as $post): ?>
                  <li><a href="<?= h(url_for('edit', ['id' => (int)$post['id']])) ?>"><?= h((string)$post['title']) ?></a></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="field-hint"><?= h(sblog_t('当前没有手动排除的内容。')) ?></p>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
    <?php
    render_layout(sblog_t('静态页面缓存'), (string)ob_get_clean(), [
        'active' => 'plugins',
        'wide' => true,
        'description' => sblog_t('静态页面缓存'),
    ]);
}

function spc_handle_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    if ($action === 'admin_static_page_cache') {
        spc_render_settings_page();
        exit;
    }
    if ($action === 'save_static_page_cache') {
        require_admin_post(script_url() . '?a=admin_static_page_cache');
        $ttl = (int)($_POST['ttl'] ?? 3600);
        if (!in_array($ttl, [900, 3600, 21600, 86400], true)) {
            $ttl = 3600;
        }
        $ok = spc_rotate_generation([
            'static_page_cache_enabled' => (string)($_POST['enabled'] ?? '') === '1' ? '1' : '0',
            'static_page_cache_ttl' => (string)$ttl,
        ]);
        set_flash($ok ? 'success' : 'error', sblog_t($ok
            ? '缓存设置已保存。'
            : '缓存失效状态无法写入，已暂停缓存以避免返回过期页面。'));
        redirect_to(script_url() . '?a=admin_static_page_cache', 303);
    }
    if ($action === 'clear_static_page_cache') {
        require_admin_post(script_url() . '?a=admin_static_page_cache');
        $ok = spc_rotate_generation();
        set_flash($ok ? 'success' : 'error', sblog_t($ok
            ? '静态页面缓存已清空。'
            : '缓存失效状态无法写入，已暂停缓存以避免返回过期页面。'));
        redirect_to(script_url() . '?a=admin_static_page_cache', 303);
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'POST' && spc_valid_csrf_request()) {
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        if ($adminId > 0 && !in_array($action, ['write', 'edit', 'save_static_page_cache', 'clear_static_page_cache', 'mark_comments_read'], true)) {
            spc_rotate_generation_on_shutdown();
        } elseif ($action === 'like_post') {
            spc_rotate_generation_on_shutdown();
        }
    }

    spc_prepare_public_request($action);
}

function spc_inject_admin_styles(string $html, array $context): string
{
    if (!in_array((string)($context['action'] ?? ''), ['write', 'edit', 'admin_static_page_cache'], true)
        || !str_contains($html, '</head>')) {
        return $html;
    }
    $url = plugin_asset_url('static-page-cache', 'assets/admin.css');
    if ($url === '') {
        return $html;
    }
    $link = '<link rel="stylesheet" href="' . h($url) . '?v=' . rawurlencode(SBLOG_STATIC_PAGE_CACHE_VERSION) . '">' . "\n";
    return str_replace('</head>', $link . '</head>', $html);
}

add_plugin_action('plugins_loaded', 'spc_install', 20);
add_plugin_action('request', 'spc_watch_rest_mutation', -2000);
add_plugin_action('request', 'spc_handle_request', 1000);
add_plugin_action('post_saved', 'spc_post_saved', 20);
add_plugin_action('comment_created', static function (): void { spc_rotate_generation(); }, 20);
add_plugin_action('comment_status_changed', static function (): void { spc_rotate_generation(); }, 20);
add_plugin_action('plugin_status_changed', static function (): void { spc_rotate_generation(); }, 20);
add_plugin_filter('editor_field_actions_html', 'spc_editor_control', 50);
add_plugin_filter('output_html', 'spc_inject_admin_styles', 50);
add_plugin_filter('output_html', 'spc_store_output', PHP_INT_MAX);
