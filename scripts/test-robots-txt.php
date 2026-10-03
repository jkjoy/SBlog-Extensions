<?php

declare(strict_types=1);

// Exercise the plugin's rules and admin lifecycle without a live SBlog install.
define('PLUGINS_DIR', dirname(__DIR__) . '/plugins');
$GLOBALS['robots_test_settings'] = [];
$GLOBALS['robots_test_base_path'] = '';
$GLOBALS['robots_test_admin'] = false;
$GLOBALS['robots_test_hooks'] = ['actions' => [], 'filters' => []];
$GLOBALS['robots_test_assertions'] = 0;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/index.php';
$_POST = [];

function setting(string $name, string $default = ''): string
{
    return (string)($GLOBALS['robots_test_settings'][$name] ?? $default);
}
function save_settings(array $values): void
{
    $GLOBALS['robots_test_last_save'] = $values;
    if (!empty($GLOBALS['robots_test_save_failure'])) {
        throw new RuntimeException('Simulated settings write failure.');
    }
    $GLOBALS['robots_test_settings'] = array_replace($GLOBALS['robots_test_settings'], $values);
    $GLOBALS['robots_test_save_count'] = ($GLOBALS['robots_test_save_count'] ?? 0) + 1;
}
function add_plugin_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['robots_test_hooks']['actions'][$hook][$priority][] = $callback;
}
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['robots_test_hooks']['filters'][$hook][$priority][] = $callback;
}
function sblog_i18n_register(string $locale, array $catalog): void {}
function sblog_t(string $message, array $parameters = []): string
{
    foreach ($parameters as $name => $value) {
        $message = str_replace('{' . $name . '}', (string)$value, $message);
    }
    return $message;
}
function h(string|int|float|bool|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function app_base_path(): string { return $GLOBALS['robots_test_base_path']; }
function app_path(string $path = ''): string
{
    return app_base_path() . '/' . ltrim($path, '/');
}
function script_url(): string { return app_path('/index.php'); }
function url_for(string $action, array $parameters = []): string
{
    return script_url() . '?' . http_build_query(['a' => $action] + $parameters);
}
function absolute_url(string $path): string
{
    return 'https://blog.example.test' . $path;
}
function plugin_asset_url(string $slug, string $path): string
{
    return app_path('/plugins/' . $slug . '/' . $path);
}
function is_admin(): bool { return $GLOBALS['robots_test_admin']; }
function current_admin(): ?array { return is_admin() ? ['id' => 1, 'username' => 'owner'] : null; }
class RobotsTestResponse extends RuntimeException
{
    public function __construct(public string $kind, public string $target = '', public int $status = 302)
    {
        parent::__construct($kind . ':' . $target);
    }
}
function require_admin(): void
{
    if (!is_admin()) {
        throw new RobotsTestResponse('auth', '', 403);
    }
}
function csrf_token(): string { return 'robots-test-csrf'; }
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}
function verify_csrf(): void
{
    if (($_POST['csrf_token'] ?? '') !== csrf_token()) {
        throw new RobotsTestResponse('csrf', '', 403);
    }
}
function require_admin_post(string $fallback): void
{
    require_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RobotsTestResponse('method', $fallback, 405);
    }
    verify_csrf();
}
function set_flash(string $type, string $message): void { $GLOBALS['robots_test_flash'] = [$type, $message]; }
function redirect_to(string $url, int $status = 302): never
{
    throw new RobotsTestResponse('redirect', $url, $status);
}
function render_admin_sidebar(string $active, array $summary = []): string { return '<aside>' . h($active) . '</aside>'; }
function render_admin_topbar(string $title, string $label = '', string $url = ''): string { return '<header>' . h($title) . '</header>'; }
function render_layout(string $title, string $content, array $options = []): never
{
    http_response_code((int)($options['status'] ?? 200));
    $GLOBALS['robots_test_rendered'] = ['title' => $title, 'content' => $content, 'options' => $options];
    throw new RobotsTestResponse('render', $title, 200);
}
function admin_icon(string $name): string { return '<span>' . h($name) . '</span>'; }
function plugin_output_buffer(string $content): string { return '<html>' . $content . '</html>'; }
function simple_error_page(string $title, string $message, int $status = 400): never
{
    throw new RobotsTestResponse('error', $message, $status);
}

function robots_test_assert(bool $condition, string $message): void
{
    $GLOBALS['robots_test_assertions']++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function robots_test_same(mixed $expected, mixed $actual, string $message): void
{
    robots_test_assert($expected === $actual, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function robots_test_invalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        robots_test_assert(true, $message);
        return;
    }
    throw new RuntimeException($message);
}
function robots_test_response(callable $callback): RobotsTestResponse
{
    try {
        $callback();
    } catch (RobotsTestResponse $response) {
        return $response;
    }
    throw new RuntimeException('Expected a terminating response.');
}
function robots_test_lines(mixed $value): array
{
    return is_array($value) ? $value : explode("\n", (string)$value);
}
function robots_test_blocked(array $settings, string $path): bool
{
    foreach (robots_test_lines($settings['disallow_paths']) as $rule) {
        $anchor = str_ends_with($rule, '$');
        $pattern = preg_quote($anchor ? substr($rule, 0, -1) : $rule, '~');
        // Robots rules match a path prefix; * spans bytes and a final $ anchors
        // the end. A plain regex equality check would miss this regression.
        $pattern = str_replace('\\*', '.*', $pattern);
        if ($rule !== '' && preg_match('~\\A' . $pattern . ($anchor ? '\\z' : '') . '~', $path) === 1) {
            return true;
        }
    }
    return false;
}

require dirname(__DIR__) . '/plugins/robots-txt/plugin.php';

try {
    robots_test_assert(in_array('sblog_robots_route_action', $GLOBALS['robots_test_hooks']['filters']['route_action'][10] ?? [], true), 'The natural robots path was not connected to core routing.');
    robots_test_assert(in_array('sblog_robots_handle_request', $GLOBALS['robots_test_hooks']['actions']['request'][-1000] ?? [], true), 'The plain-text endpoint did not run before other request plugins.');
    $defaults = sblog_robots_defaults();
    robots_test_same('custom', $defaults['mode'], 'The plugin should begin with the recommended custom rules.');
    robots_test_same(['*'], robots_test_lines($defaults['user_agents']), 'Default rules did not apply to every crawler.');
    $content = sblog_robots_content($defaults);
    robots_test_assert(str_starts_with($content, "User-agent: *\n"), 'The file did not start with a user-agent directive.');
    foreach (['/data/', '/cache/', '/install.php', '/installer.php', '/update.php'] as $path) {
        robots_test_assert(str_contains($content, 'Disallow: ' . $path . "\n"), 'Missing default private path: ' . $path);
    }
    robots_test_assert(str_contains($content, "Sitemap: https://blog.example.test/index.php?a=sitemap\n"), 'The built-in sitemap did not use the core canonical URL.');
    robots_test_assert(!str_contains($content, "\r") && str_ends_with($content, "\n"), 'The output was not a newline-terminated UTF-8 text file.');

    $GLOBALS['robots_test_base_path'] = '/blog';
    $subdirectory = sblog_robots_content(sblog_robots_defaults());
    robots_test_assert(str_contains($subdirectory, "Disallow: /blog/data/\n"), 'Private rules ignored the installation subdirectory.');
    robots_test_assert(str_contains($subdirectory, "Sitemap: https://blog.example.test/blog/index.php?a=sitemap\n"), 'The sitemap ignored the installation subdirectory.');
    robots_test_assert(!str_contains($subdirectory, "Disallow: /data/\n"), 'Subdirectory defaults accidentally blocked another root application.');
    foreach (['', '/blog'] as $basePath) {
        $GLOBALS['robots_test_base_path'] = $basePath;
        $defaultRules = sblog_robots_defaults();
        foreach (['/admin-guide', '/login-help'] as $publicPath) {
            robots_test_assert(!robots_test_blocked($defaultRules, $basePath . $publicPath), 'A default prefix blocked a public page: ' . $basePath . $publicPath);
        }
        foreach (['/admin', '/admin/posts', '/admin?tab=overview', '/login', '/login?next=/archive/example', '/index.php?a=admin_posts', '/index.php?a=login'] as $privatePath) {
            robots_test_assert(robots_test_blocked($defaultRules, $basePath . $privatePath), 'A real backend or login URL escaped the default crawler rules: ' . $basePath . $privatePath);
        }
    }

    $post = [
        'mode' => 'custom',
        'user_agents' => " Googlebot\r\nBingbot\nGooglebot\n\n",
        'disallow_paths' => " /private/\r\n/private/\n/search?query=*\n",
        'allow_paths' => " /private/public/\r\n/private/public/\n",
        'sitemap_enabled' => '1',
        'sitemap_urls' => " https://maps.example.test/news.xml\r\nhttps://maps.example.test/news.xml\nhttp://maps.example.test/images.xml\n",
    ];
    $normalized = sblog_robots_validate_settings($post);
    robots_test_same(['Googlebot', 'Bingbot'], robots_test_lines($normalized['user_agents']), 'Crawler lines were not trimmed and deduplicated.');
    robots_test_same(['/private/', '/search?query=*'], robots_test_lines($normalized['disallow_paths']), 'Disallow lines were not trimmed and deduplicated.');
    robots_test_same(['/private/public/'], robots_test_lines($normalized['allow_paths']), 'Allow lines were not trimmed and deduplicated.');
    robots_test_same(['https://maps.example.test/news.xml', 'http://maps.example.test/images.xml'], robots_test_lines($normalized['sitemap_urls']), 'Additional sitemap lines were not normalized.');
    $expected = "User-agent: Googlebot\nUser-agent: Bingbot\nDisallow: /private/\nDisallow: /search?query=*\nAllow: /private/public/\n\nSitemap: https://blog.example.test/blog/index.php?a=sitemap\nSitemap: https://maps.example.test/news.xml\nSitemap: http://maps.example.test/images.xml\n";
    robots_test_same($expected, sblog_robots_content($normalized), 'Custom rules did not preserve a single agent group or directive order.');

    foreach (['allow_all' => "Disallow:\n", 'disallow_all' => "Disallow: /\n"] as $mode => $rule) {
        $modeSettings = sblog_robots_validate_settings(array_replace($post, ['mode' => $mode]));
        $modeContent = sblog_robots_content($modeSettings);
        robots_test_assert(str_contains($modeContent, $rule), $mode . ' did not emit its policy rule.');
        robots_test_assert(!str_contains($modeContent, 'Allow: '), $mode . ' leaked a custom allow exception.');
        robots_test_assert(!str_contains($modeContent, 'Disallow: /private/'), $mode . ' leaked custom blocking rules.');
        robots_test_assert(str_contains($modeContent, 'Sitemap: https://maps.example.test/news.xml'), $mode . ' suppressed the independent sitemap configuration.');
    }
    $withoutAutomatic = $post;
    unset($withoutAutomatic['sitemap_enabled']);
    $withoutAutomaticContent = sblog_robots_content(sblog_robots_validate_settings($withoutAutomatic));
    robots_test_assert(!str_contains($withoutAutomaticContent, '?a=sitemap'), 'An unchecked automatic sitemap remained enabled.');
    robots_test_assert(str_contains($withoutAutomaticContent, 'Sitemap: https://maps.example.test/news.xml'), 'Unchecking the built-in sitemap removed custom sitemap URLs.');
    $withoutSitemaps = array_replace($withoutAutomatic, ['sitemap_urls' => '']);
    robots_test_assert(!str_contains(sblog_robots_content(sblog_robots_validate_settings($withoutSitemaps)), 'Sitemap:'), 'An empty disabled sitemap configuration still emitted a directive.');

    foreach (['mode', 'user_agents', 'allow_paths', 'disallow_paths', 'sitemap_enabled', 'sitemap_urls'] as $field) {
        robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, [$field => ['nested']])), 'An array was accepted for ' . $field . '.');
    }
    foreach (['other', '', 'CUSTOM'] as $mode) {
        robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, ['mode' => $mode])), 'An invalid policy mode was accepted.');
    }
    foreach (['2', 'true', 'on'] as $checkbox) {
        robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, ['sitemap_enabled' => $checkbox])), 'A malformed sitemap checkbox was accepted.');
    }
    foreach (['Google bot', 'Googlebot:evil', 'Bot/1.0', "Bot\0Injected", '#bot'] as $agent) {
        robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, ['user_agents' => $agent])), 'An invalid user-agent token was accepted.');
    }
    foreach (['relative/', 'https://example.test/path', '/private path/', '/private#fragment', "/private\tpath", "/private\0path"] as $path) {
        foreach (['allow_paths', 'disallow_paths'] as $field) {
            robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, [$field => $path])), 'An unsafe crawler path was accepted for ' . $field . '.');
        }
    }
    foreach (['ftp://maps.example.test/a.xml', '//maps.example.test/a.xml', 'https://user:password@maps.example.test/a.xml', 'https://maps.example.test/a.xml#fragment', "https://maps.example.test/a\0.xml", 'https://maps.example.test/a b.xml'] as $url) {
        robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, ['sitemap_urls' => $url])), 'An unsafe sitemap address was accepted.');
    }
    foreach (['user_agents', 'allow_paths', 'disallow_paths', 'sitemap_urls'] as $field) {
        robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, [$field => str_repeat('a', 131073)])), 'Unbounded text was accepted for ' . $field . '.');
    }
    robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, ['user_agents' => "\n\r\n "])), 'An empty crawler group was accepted.');
    robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, ['allow_paths' => "/bad-\xFF-path"])), 'Invalid UTF-8 was accepted.');
    $tooManyRules = implode("\n", array_map(static fn(int $index): string => '/path-' . $index, range(1, 101)));
    robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, ['disallow_paths' => $tooManyRules])), 'More than 100 distinct crawler paths were accepted.');
    $tooManySitemaps = implode("\n", array_map(static fn(int $index): string => 'https://maps.example.test/map-' . $index . '.xml', range(1, 21)));
    robots_test_invalid(static fn() => sblog_robots_validate_settings(array_replace($post, ['sitemap_urls' => $tooManySitemaps])), 'More than 20 distinct sitemap URLs were accepted.');

    foreach (['/robots.txt', '/blog/robots.txt', '/blog/robots.txt?download=1'] as $uri) {
        $_SERVER['REQUEST_URI'] = $uri;
        robots_test_same('robots_txt', sblog_robots_route_action('home', []), 'The robots endpoint was not recognized: ' . $uri);
    }
    foreach (['/blog/robots.txt/extra', '/other/robots.txt', '/blog/robots.txt.bak', '/blog/index.php?next=/robots.txt', '/blog/ROBOTs.txt'] as $uri) {
        $_SERVER['REQUEST_URI'] = $uri;
        robots_test_same('home', sblog_robots_route_action('home', []), 'An unrelated path was taken over: ' . $uri);
    }
    $_SERVER['REQUEST_URI'] = '/blog/index.php?a=robots_txt';
    robots_test_same('robots_txt', sblog_robots_route_action('robots_txt', []), 'The direct query action was rejected.');
    $_SERVER['REQUEST_URI'] = '/blog/index.php';
    robots_test_same('home', sblog_robots_route_action('home', ['path' => '/robots.txt']), 'Routing trusted an unrelated context path over the request URI.');

    $renderDenied = robots_test_response(static fn() => sblog_robots_render_settings());
    robots_test_same('auth', $renderDenied->kind, 'An anonymous visitor rendered crawler settings.');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $post + ['csrf_token' => csrf_token()];
    $saveDenied = robots_test_response(static fn() => sblog_robots_handle_request(['action' => 'save_robots_txt']));
    robots_test_same('auth', $saveDenied->kind, 'An anonymous visitor saved crawler settings.');
    robots_test_same([], $GLOBALS['robots_test_settings'], 'An unauthorized request changed settings.');

    $GLOBALS['robots_test_admin'] = true;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $methodDenied = robots_test_response(static fn() => sblog_robots_handle_request(['action' => 'save_robots_txt']));
    robots_test_same('method', $methodDenied->kind, 'Saving accepted a GET request.');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = 'wrong-csrf';
    $csrfDenied = robots_test_response(static fn() => sblog_robots_handle_request(['action' => 'save_robots_txt']));
    robots_test_same('csrf', $csrfDenied->kind, 'Saving accepted an invalid CSRF token.');
    robots_test_same([], $GLOBALS['robots_test_settings'], 'A rejected save partially changed settings.');

    $_POST = $post + ['csrf_token' => csrf_token()];
    $saved = robots_test_response(static fn() => sblog_robots_handle_request(['action' => 'save_robots_txt']));
    robots_test_same('redirect', $saved->kind, 'Saving did not redirect back to settings.');
    robots_test_same(303, $saved->status, 'Saving did not use POST/redirect/GET.');
    robots_test_same(url_for('admin_robots_txt'), $saved->target, 'Saving redirected outside its settings page.');
    robots_test_same($normalized, sblog_robots_settings(), 'Saved normalized rules did not round-trip.');
    robots_test_same('success', $GLOBALS['robots_test_flash'][0], 'A successful save did not report success.');
    robots_test_same(['robots_txt_config'], array_keys($GLOBALS['robots_test_last_save']), 'Saving split the configuration across multiple settings writes.');
    robots_test_same($normalized, json_decode($GLOBALS['robots_test_last_save']['robots_txt_config'], true, 512, JSON_THROW_ON_ERROR), 'The saved snapshot did not contain the complete normalized configuration.');
    $beforeFailedSave = $GLOBALS['robots_test_settings'];
    $GLOBALS['robots_test_save_failure'] = true;
    $_POST['mode'] = 'disallow_all';
    $previousErrorLog = ini_set('error_log', '/dev/null');
    try {
        $failedSave = robots_test_response(static fn() => sblog_robots_handle_request(['action' => 'save_robots_txt']));
    } finally {
        $GLOBALS['robots_test_save_failure'] = false;
        if ($previousErrorLog !== false) {
            ini_set('error_log', $previousErrorLog);
        }
    }
    robots_test_same('redirect', $failedSave->kind, 'A storage failure did not return to the settings page.');
    robots_test_same('error', $GLOBALS['robots_test_flash'][0], 'A storage failure did not report an error.');
    robots_test_same($beforeFailedSave, $GLOBALS['robots_test_settings'], 'A failed settings write changed the published snapshot.');
    robots_test_same($normalized, sblog_robots_settings(), 'A failed settings write changed the active crawler policy.');
    $_POST = $post + ['csrf_token' => csrf_token()];
    $beforeInvalid = $GLOBALS['robots_test_settings'];
    $_POST['disallow_paths'] = 'https://malformed.example.test';
    $invalidSave = robots_test_response(static fn() => sblog_robots_handle_request(['action' => 'save_robots_txt']));
    robots_test_same('render', $invalidSave->kind, 'A validation error did not retain the settings form.');
    robots_test_same(422, http_response_code(), 'A validation error did not return an unprocessable form response.');
    robots_test_same($beforeInvalid, $GLOBALS['robots_test_settings'], 'Validation failed after partially persisting settings.');
    robots_test_assert(str_contains($GLOBALS['robots_test_rendered']['content'], 'https://malformed.example.test'), 'A rejected form discarded the submitted input.');
    robots_test_assert(str_contains($GLOBALS['robots_test_rendered']['content'], 'role="alert"'), 'A rejected form omitted its validation error.');

    // A valid crawler path can contain markup; the admin textarea and preview
    // must escape it even though the plain-text robots output should retain it.
    $_POST = array_replace($post, ['allow_paths' => '/</textarea><script>alert(1)</script>', 'csrf_token' => csrf_token()]);
    robots_test_response(static fn() => sblog_robots_handle_request(['action' => 'save_robots_txt']));
    $rendered = robots_test_response(static fn() => sblog_robots_render_settings());
    robots_test_same('render', $rendered->kind, 'The settings page did not render for an administrator.');
    robots_test_same(200, http_response_code(), 'A valid settings render retained the previous validation error status.');
    $html = $GLOBALS['robots_test_rendered']['content'];
    robots_test_assert(!str_contains($html, '</textarea><script>alert(1)</script>'), 'Stored crawler markup escaped a textarea or preview.');
    robots_test_assert(str_contains($html, '&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;'), 'Saved crawler text disappeared instead of being safely escaped.');
    robots_test_assert(str_contains($html, 'name="csrf_token"'), 'The settings form omitted its CSRF field.');
    robots_test_assert(str_contains($html, 'save_robots_txt'), 'The settings form did not post to the plugin action.');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    sblog_robots_serve();
    $served = (string)ob_get_clean();
    robots_test_same(200, http_response_code(), 'The public robots endpoint did not return success.');
    robots_test_same(sblog_robots_content(sblog_robots_settings()), $served, 'The public endpoint did not serve the saved rules as text.');
    robots_test_assert(str_contains($served, '/</textarea><script>alert(1)</script>'), 'Plain-text serving incorrectly escaped crawler paths as HTML.');
    ob_start();
    $outerLevel = ob_get_level();
    ob_start('plugin_output_buffer');
    echo 'Discard this core buffer content.';
    sblog_robots_serve();
    robots_test_same($outerLevel, ob_get_level(), 'Plain-text serving left the core HTML output filter enabled.');
    robots_test_same($served, (string)ob_get_clean(), 'Plain-text serving leaked buffered HTML from the core.');
    ob_start();
    $unrelatedLevel = ob_get_level();
    sblog_robots_serve();
    robots_test_same($unrelatedLevel, ob_get_level(), 'Plain-text serving unexpectedly closed an unrelated output buffer.');
    robots_test_same($served, (string)ob_get_clean(), 'An unrelated capture buffer did not retain the robots output.');
    $_SERVER['REQUEST_METHOD'] = 'HEAD';
    ob_start();
    sblog_robots_serve();
    robots_test_same('', (string)ob_get_clean(), 'A HEAD response included a body.');
    robots_test_same(200, http_response_code(), 'A HEAD request did not return success.');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    sblog_robots_serve();
    robots_test_same("Method Not Allowed\n", (string)ob_get_clean(), 'A write request served crawler rules.');
    robots_test_same(405, http_response_code(), 'A write request did not return method-not-allowed.');

    echo 'Robots.txt tests passed (' . $GLOBALS['robots_test_assertions'] . " assertions).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Robots.txt tests failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
