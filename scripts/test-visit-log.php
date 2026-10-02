<?php
declare(strict_types=1);

define('PLUGINS_DIR', dirname(__DIR__) . '/plugins');
define('DB_FILE', ':memory:');
$GLOBALS['visit_test_db'] = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['visit_test_admin'] = null;
$GLOBALS['visit_test_actions'] = [];
$GLOBALS['visit_test_filters'] = [];

function db(): PDO { return $GLOBALS['visit_test_db']; }
function q(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return is_array($row) ? $row : null;
}
function all_rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function val(string $sql, array $params = []): mixed { return q($sql, $params)->fetchColumn(); }
function setting(string $name, string $default = ''): string { return $default; }
function add_plugin_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['visit_test_actions'][$hook][$priority][] = $callback;
}
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['visit_test_filters'][$hook][$priority][] = $callback;
}
function sblog_i18n_register(string $locale, array $messages): void {}
function sblog_t(string $message, array $params = []): string
{
    foreach ($params as $name => $value) {
        $message = str_replace('{' . $name . '}', (string)$value, $message);
    }
    return $message;
}
function sblog_tn(string $message, int $count, array $params = []): string
{
    return sblog_t($message, ['count' => $count] + $params);
}
function h(string|int|float|bool|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function str_sub_u(string $value, int $start, int $length): string
{
    return mb_substr($value, $start, $length, 'UTF-8');
}
function current_admin(): ?array { return $GLOBALS['visit_test_admin']; }
function is_admin(): bool { return current_admin() !== null; }
function require_admin(): void
{
    if (!is_admin()) {
        throw new RuntimeException('Admin authentication required.');
    }
}
function require_admin_post(string $fallbackUrl): void
{
    require_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('POST request required.');
    }
    if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Valid CSRF token required.');
    }
}
class VisitTestRedirect extends RuntimeException {}
function redirect_to(string $url, int $status = 302): never
{
    throw new VisitTestRedirect($url, $status);
}
function set_flash(string $type, string $message): void { $GLOBALS['visit_test_flash'] = [$type, $message]; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="test-token">'; }
function render_admin_sidebar(string $active): string { return '<nav>' . h($active) . '</nav>'; }
function render_admin_topbar(string $title): string { return '<header>' . h($title) . '</header>'; }
function render_layout(string $title, string $content, array $options = []): void
{
    echo '<!doctype html><html><head><title>' . h($title) . '</title></head><body class="theme-admin">' . $content . '</body></html>';
}
function script_url(): string { return '/index.php'; }
function plugin_asset_url(string $slug, string $path): string { return '/plugins/' . $slug . '/' . $path; }
function client_ip_address(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function visit_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function visit_test_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}
function visit_test_invalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}
function visit_test_denied(callable $callback, string $reason): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        visit_test_same($reason, $exception->getMessage(), 'Request failed for the wrong reason');
        return;
    }
    throw new RuntimeException('Restricted request was accepted.');
}
function visit_test_insert(array $values): void
{
    $row = array_merge([
        'visited_at' => time(), 'visitor_hash' => hash('sha256', 'visitor-a'),
        'ip' => '203.0.113.1', 'path' => '/index.php',
        'referrer' => '', 'referrer_host' => '', 'browser' => 'Chrome', 'os' => 'Windows',
        'device' => 'desktop', 'user_agent' => 'Test browser', 'is_bot' => 0,
        'status_code' => 200, 'duration_ms' => 12, 'action' => 'home',
    ], $values);
    $columns = array_keys($row);
    q('INSERT INTO sblog_visit_logs (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')', array_values($row));
}
function visit_test_config(array $values = []): void
{
    $post = array_merge([
        'enabled' => '1', 'record_bots' => '1', 'retention_days' => '30',
    ], $values);
    foreach (['enabled', 'record_bots'] as $checkbox) {
        if (($post[$checkbox] ?? null) === '0') {
            unset($post[$checkbox]);
        }
    }
    sblog_visit_save_config($post);
}
function visit_test_request(string $action = 'home', array $query = [], string $method = 'GET', string $agent = ''): void
{
    $_GET = array_merge(['a' => $action], $query);
    $_SERVER = [
        'REQUEST_METHOD' => $method, 'REQUEST_URI' => '/index.php?' . http_build_query($_GET),
        'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.10',
        'HTTP_USER_AGENT' => $agent !== '' ? $agent
            : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36',
        'HTTP_REFERER' => 'https://user:password@source.example/a?token=secret#fragment',
    ];
    sblog_visit_prepare_request(['action' => $action, 'request' => $_GET]);
}

$visitTestTimezone = date_default_timezone_get();
$visitTestGet = $_GET;
$visitTestPost = $_POST;
$visitTestServer = $_SERVER;
$visitTestSession = $_SESSION ?? null;
$_SESSION = [];
require dirname(__DIR__) . '/plugins/visit-log/plugin.php';

if (in_array('--cache-hit', $argv ?? [], true)) {
    sblog_visit_install();
    ob_start();
    visit_test_request('post', ['id' => '42']);
    // A cache hit removes the core HTML buffer and exits inside another request hook.
    ob_end_clean();
    http_response_code(200);
    register_shutdown_function(static function (): void {
        echo "\nVISIT-RESULT:" . json_encode(one('SELECT COUNT(*) AS visits, MAX(status_code) AS status FROM sblog_visit_logs'));
    });
    echo 'CACHED-PAGE';
    exit;
}

try {
    date_default_timezone_set('Asia/Shanghai');
    sblog_visit_install();
    $config = sblog_visit_config();
    visit_test_same(true, $config['enabled'], 'Tracking must be enabled initially');
    visit_test_same(true, $config['record_bots'], 'Bot recording must be enabled initially');
    visit_test_same(30, $config['retention_days'], 'Default retention must be 30 days');
    visit_test_assert((bool)val("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sblog_visit_logs'"), 'Log table was not installed.');

    $date = '2026-10-02';
    [$start, $end] = sblog_visit_day_range($date);
    visit_test_same(strtotime($date . ' 00:00:00'), $start, 'Day must start at local midnight');
    visit_test_same(strtotime('2026-10-03 00:00:00'), $end, 'Day must end at next local midnight');
    visit_test_same(date('Y-m-d'), sblog_visit_filters([])['date'], 'Empty report must default to today');
    foreach ([
        ['date' => '2026-02-30'], ['date' => '2026-2-03'], ['date' => ['2026-10-02']],
        ['kind' => 'unsupported'], ['kind' => ['bot']], ['ip' => 'not-an-ip'], ['ip' => ['203.0.113.1']],
        ['path' => ['index.php']],
        ['page' => '0'], ['page' => '-1'], ['page' => ['1']],
    ] as $query) {
        visit_test_invalid(static fn() => sblog_visit_filters($query), 'Malformed filters were accepted: ' . json_encode($query));
    }
    visit_test_invalid(static fn() => sblog_visit_day_range('2026-02-30'), 'Impossible day was accepted.');

    visit_test_insert(['visited_at' => $start - 1, 'path' => '/previous-day']);
    visit_test_insert(['visited_at' => $start, 'path' => '/alpha', 'referrer_host' => 'source.example']);
    visit_test_insert(['visited_at' => $start + 8 * 3600, 'path' => '/alpha', 'referrer_host' => 'source.example']);
    visit_test_insert(['visited_at' => $start + 12 * 3600, 'path' => '/beta', 'visitor_hash' => hash('sha256', 'visitor-b'), 'ip' => '203.0.113.2']);
    visit_test_insert(['visited_at' => $end - 1, 'path' => '/bot', 'visitor_hash' => hash('sha256', 'bot'), 'ip' => '203.0.113.3', 'is_bot' => 1]);
    visit_test_insert(['visited_at' => $end, 'path' => '/next-day']);
    $report = sblog_visit_report(sblog_visit_filters(['date' => $date]));
    visit_test_same(4, $report['summary']['views'], 'Report included records outside the selected date');
    visit_test_same(3, $report['summary']['visitors'], 'Repeated visitor inflated UV');
    visit_test_same(3, $report['summary']['ips'], 'Distinct IP count is wrong');
    visit_test_same(1, $report['summary']['bots'], 'Bot count is wrong');
    visit_test_same(24, count($report['hours']), 'Hourly report must contain all 24 hours');
    visit_test_same(4, array_sum($report['hours']), 'Hourly total differs from PV');
    foreach ([0, 8, 12, 23] as $hour) {
        visit_test_same(1, $report['hours'][$hour], 'Visit assigned to the wrong local hour');
    }
    visit_test_same(2, array_column($report['pages'], 'views', 'path')['/alpha'], 'Page aggregation is wrong');
    visit_test_same(2, array_column($report['sources'], 'views', 'referrer_host')['source.example'], 'Source aggregation is wrong');
    $humans = sblog_visit_report(sblog_visit_filters(['date' => $date, 'kind' => 'human']));
    visit_test_same(3, $humans['total'], 'Human filter included bots');
    visit_test_same(0, $humans['summary']['bots'], 'Filtered summary included bots');
    visit_test_same(1, sblog_visit_report(sblog_visit_filters(['date' => $date, 'kind' => 'bot']))['total'], 'Bot filter is wrong');
    visit_test_same(2, sblog_visit_report(sblog_visit_filters(['date' => $date, 'ip' => '203.0.113.1']))['total'], 'Exact IP filter is wrong');
    visit_test_same(2, sblog_visit_report(sblog_visit_filters(['date' => $date, 'path' => 'alpha']))['total'], 'Path substring filter is wrong');
    visit_test_same(0, sblog_visit_report(sblog_visit_filters(['date' => $date, 'path' => "% OR 1=1 --"]))['total'], 'Path filter changed SQL semantics');
    visit_test_insert(['visited_at' => $start + 3600, 'visitor_hash' => hash('sha256', 'unknown-ip'), 'ip' => '']);
    $unknownIp = sblog_visit_report(sblog_visit_filters(['date' => $date]));
    visit_test_same(4, $unknownIp['summary']['visitors'], 'Unknown-IP visit was lost from visitor count');
    visit_test_same(3, $unknownIp['summary']['ips'], 'Unknown IP must not count as a distinct IP');

    q('DELETE FROM sblog_visit_logs');
    for ($index = 0; $index < 53; $index++) {
        visit_test_insert(['visited_at' => $start + $index, 'path' => '/page-' . $index]);
    }
    $firstPage = sblog_visit_report(sblog_visit_filters(['date' => $date]));
    $lastPage = sblog_visit_report(sblog_visit_filters(['date' => $date, 'page' => '999']));
    visit_test_same(50, count($firstPage['rows']), 'Detail page must be bounded to 50 visits');
    visit_test_same(2, $lastPage['page'], 'Out-of-range detail page was not clamped');
    visit_test_same(2, $lastPage['pages_count'], 'Detail page count is wrong');
    visit_test_same(3, count($lastPage['rows']), 'Final page returned the wrong row count');
    visit_test_same('/page-52', $firstPage['rows'][0]['path'], 'Newest visits must appear first');
    visit_test_same(53, $lastPage['total'], 'Pagination changed total count');

    $safePath = sblog_visit_request_path([
        'REQUEST_URI' => '/index.php?a=post&id=42&password=secret&token=secret#fragment',
        'SCRIPT_NAME' => '/index.php',
    ], ['a' => 'post', 'id' => '42', 'password' => 'secret', 'token' => 'secret', 'csrf_token' => 'secret']);
    parse_str((string)parse_url($safePath, PHP_URL_QUERY), $safeQuery);
    visit_test_same(['a' => 'post', 'id' => '42'], $safeQuery, 'Sensitive URL parameters were retained');
    visit_test_assert(!str_contains($safePath, 'secret') && !str_contains($safePath, '#'), 'Sensitive URL text survived sanitization.');
    $malformedPath = sblog_visit_request_path(['REQUEST_URI' => '/index.php'], ['a' => 'post', 'id' => ['42'], 'token' => 'secret']);
    visit_test_assert(!str_contains($malformedPath, 'secret') && !str_contains($malformedPath, '%5B'), 'Array URL parameters were persisted.');
    [$referrer, $host] = sblog_visit_referrer('https://user:password@Source.Example:8443/a?token=secret#fragment');
    visit_test_same('source.example', $host, 'Referrer host was not normalized');
    visit_test_assert(!str_contains($referrer, 'password') && !str_contains($referrer, '?') && !str_contains($referrer, '#'), 'Referrer retained credentials or parameters.');
    visit_test_same(['', ''], sblog_visit_referrer('javascript:alert(1)'), 'Executable referrer was accepted');
    visit_test_same(['', ''], sblog_visit_referrer("https://example.test/\r\nInjected: value"), 'Control characters in referrer were accepted');
    $desktop = sblog_visit_agent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36');
    $mobile = sblog_visit_agent('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1');
    $bot = sblog_visit_agent('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
    visit_test_assert(str_contains($desktop[0], 'Chrome'), 'Chrome browser was not recognized.');
    visit_test_assert(str_contains($desktop[1], 'Windows'), 'Windows was not recognized.');
    visit_test_assert($desktop[2] !== $mobile[2], 'Mobile and desktop devices were not distinguished.');
    visit_test_same(false, (bool)$desktop[3], 'Normal browser was treated as a bot');
    visit_test_same(true, (bool)$bot[3], 'Known crawler was not recognized');

    q('DELETE FROM sblog_visit_logs');
    visit_test_config();
    visit_test_request('gallery', ['category' => 'travel', 'token' => 'secret']);
    visit_test_assert(!empty($GLOBALS['sblog_visit_pending']), 'Gallery public page was excluded.');
    visit_test_assert(str_contains($GLOBALS['sblog_visit_pending']['path'], 'category=travel'), 'Gallery category was missing from the logged path.');
    visit_test_assert(!str_contains($GLOBALS['sblog_visit_pending']['path'], 'secret'), 'Gallery path retained a secret.');
    unset($GLOBALS['sblog_visit_pending']);
    foreach (['admin', 'login', 'rss', 'sitemap', 'rest_api', 'douban_cover'] as $action) {
        visit_test_request($action);
        visit_test_assert(empty($GLOBALS['sblog_visit_pending']), 'Excluded action prepared a visit: ' . $action);
    }
    visit_test_request('home', [], 'POST');
    visit_test_assert(empty($GLOBALS['sblog_visit_pending']), 'POST request prepared a visit.');
    $GLOBALS['visit_test_admin'] = ['id' => 1, 'username' => 'admin'];
    visit_test_request();
    visit_test_assert(empty($GLOBALS['sblog_visit_pending']), 'Administrator public visit was recorded.');
    $GLOBALS['visit_test_admin'] = null;
    visit_test_request('post', ['id' => '42', 'preview' => '1']);
    visit_test_assert(empty($GLOBALS['sblog_visit_pending']), 'Preview request prepared a visit.');
    visit_test_config(['record_bots' => '0']);
    visit_test_request('home', [], 'GET', 'Googlebot/2.1');
    visit_test_assert(empty($GLOBALS['sblog_visit_pending']), 'Bot recording switch was ignored.');
    visit_test_config(['enabled' => '0']);
    visit_test_request();
    visit_test_assert(empty($GLOBALS['sblog_visit_pending']), 'Disabled tracking prepared a visit.');
    visit_test_config();
    visit_test_request('post', ['id' => '999', 'token' => 'secret']);
    visit_test_assert(!empty($GLOBALS['sblog_visit_pending']), 'Public GET was not prepared.');
    http_response_code(404);
    sblog_visit_finish_request();
    $recorded = one('SELECT * FROM sblog_visit_logs ORDER BY id DESC LIMIT 1');
    visit_test_assert($recorded !== null, 'Finished request did not write a visit.');
    visit_test_same(404, (int)$recorded['status_code'], 'Final response status was lost');
    visit_test_same('203.0.113.9', $recorded['ip'], 'Untrusted forwarded IP was used');
    visit_test_assert((float)$recorded['duration_ms'] >= 0, 'Response duration was negative.');
    visit_test_assert(!str_contains($recorded['path'], 'secret') && !str_contains($recorded['referrer'], 'secret'), 'Stored request leaked a secret.');
    sblog_visit_finish_request();
    visit_test_same(1, (int)val('SELECT COUNT(*) FROM sblog_visit_logs'), 'Finishing the same request logged it twice');
    http_response_code(200);
    $cacheOutput = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --cache-hit');
    visit_test_assert(is_string($cacheOutput) && str_contains($cacheOutput, 'CACHED-PAGE'), 'Cache-hit exit child did not return its page.');
    preg_match('/VISIT-RESULT:(\{[^\r\n]*\})/', $cacheOutput, $cacheMatch);
    $cacheResult = json_decode($cacheMatch[1] ?? '', true);
    visit_test_same(1, (int)($cacheResult['visits'] ?? 0), 'Request-hook exit lost a cache-hit visit');
    visit_test_same(200, (int)($cacheResult['status'] ?? 0), 'Cache-hit exit stored an incorrect status');

    visit_test_invalid(static fn() => sblog_visit_save_config(['retention_days' => '2']), 'Unsupported retention was accepted.');
    sblog_visit_save_config(['retention_days' => '7']);
    visit_test_same(false, sblog_visit_config()['enabled'], 'Missing enabled checkbox must disable tracking');
    visit_test_same(false, sblog_visit_config()['record_bots'], 'Missing bot checkbox must disable bot recording');
    visit_test_config(['retention_days' => '7']);
    q('DELETE FROM sblog_visit_logs');
    $cleanupNow = strtotime('2026-10-02 12:00:00');
    $cutoff = strtotime('2026-09-26 00:00:00');
    visit_test_insert(['visited_at' => $cutoff - 1, 'path' => '/expired']);
    visit_test_insert(['visited_at' => $cutoff, 'path' => '/retained-boundary']);
    visit_test_insert(['visited_at' => $cleanupNow, 'path' => '/today']);
    q("INSERT OR REPLACE INTO sblog_visit_log_settings(name, value) VALUES ('last_cleanup', '0')");
    sblog_visit_cleanup($cleanupNow);
    visit_test_same(['/retained-boundary', '/today'], array_column(all_rows('SELECT path FROM sblog_visit_logs ORDER BY visited_at'), 'path'), 'Retention must include today and six preceding calendar days');

    date_default_timezone_set('America/New_York');
    visit_test_config(['timezone' => 'America/New_York']);
    [$springStart, $springEnd] = sblog_visit_day_range('2026-03-08');
    [$fallStart, $fallEnd] = sblog_visit_day_range('2026-11-01');
    visit_test_same(23 * 3600, $springEnd - $springStart, 'Spring DST day must contain 23 hours');
    visit_test_same(25 * 3600, $fallEnd - $fallStart, 'Fall DST day must contain 25 hours');
    visit_test_invalid(static fn() => sblog_visit_save_config(['retention_days' => '7', 'timezone' => 'Invalid/Zone']), 'Unknown report timezone was accepted.');
    visit_test_config(['timezone' => 'Asia/Shanghai']);
    date_default_timezone_set('UTC');
    [$configuredStart, $configuredEnd] = sblog_visit_day_range('2026-10-02');
    visit_test_same(strtotime('2026-10-01 16:00:00 UTC'), $configuredStart, 'Configured timezone did not override PHP timezone');
    visit_test_same(strtotime('2026-10-02 16:00:00 UTC'), $configuredEnd, 'Configured timezone gave the wrong day end');
    visit_test_same('2026-10-02', sblog_visit_date('Y-m-d', $configuredStart), 'Display date ignored configured timezone');

    $GLOBALS['visit_test_admin'] = null;
    visit_test_denied(static fn() => sblog_visit_render_admin(), 'Admin authentication required.');
    visit_test_denied(static fn() => sblog_visit_handle_admin_request(['action' => 'save_visit_log_settings']), 'Admin authentication required.');
    $GLOBALS['visit_test_admin'] = ['id' => 1, 'username' => 'admin'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION['csrf_token'] = 'test-token';
    $_POST = ['retention_days' => '1', 'csrf_token' => 'wrong'];
    $beforeDeniedSave = sblog_visit_config();
    visit_test_denied(static fn() => sblog_visit_handle_admin_request(['action' => 'save_visit_log_settings']), 'Valid CSRF token required.');
    visit_test_same($beforeDeniedSave, sblog_visit_config(), 'CSRF failure changed plugin settings');
    $_POST = ['enabled' => '1', 'record_bots' => '1', 'retention_days' => '30', 'timezone' => 'Asia/Shanghai', 'csrf_token' => 'test-token'];
    try {
        sblog_visit_handle_admin_request(['action' => 'save_visit_log_settings']);
        throw new RuntimeException('Settings save did not redirect.');
    } catch (VisitTestRedirect $redirect) {
        visit_test_same(303, $redirect->getCode(), 'Settings save must use POST/redirect/GET');
    }
    visit_test_same('success', $GLOBALS['visit_test_flash'][0], 'Valid settings save failed');
    $unsafeText = '<img src=x onerror=alert(1)>';
    visit_test_insert(['path' => '/page?' . $unsafeText, 'user_agent' => $unsafeText, 'referrer' => 'https://source.example/' . $unsafeText, 'referrer_host' => 'source.example']);
    $_GET = ['date' => sblog_visit_date('Y-m-d')];
    ob_start();
    sblog_visit_render_admin();
    $adminHtml = (string)ob_get_clean();
    visit_test_assert(!str_contains($adminHtml, $unsafeText), 'Stored request text was rendered without escaping.');
    visit_test_assert(str_contains($adminHtml, h($unsafeText)), 'Stored request text was not present in escaped detail output.');
    visit_test_assert(str_contains($adminHtml, 'visit-log-chart') && str_contains($adminHtml, 'visit-log-table'), 'Admin overview or visit detail table was missing.');

    echo "Visit log self-test passed.\n";
} finally {
    unset($GLOBALS['sblog_visit_pending']);
    date_default_timezone_set($visitTestTimezone);
    $_GET = $visitTestGet;
    $_POST = $visitTestPost;
    $_SERVER = $visitTestServer;
    if ($visitTestSession === null) {
        unset($_SESSION);
    } else {
        $_SESSION = $visitTestSession;
    }
}
