<?php

declare(strict_types=1);

// CLI-only fixtures: never bootstrap index.php or open the installed database.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "These tests require the pdo_sqlite PHP extension.\n");
    exit(1);
}

$likesCoreFile = trim((string)getenv('SBLOG_CORE_FILE'));
if ($likesCoreFile === '') {
    $likesCoreFile = dirname(__DIR__, 3) . '/index.php';
}
$likesCoreSource = is_file($likesCoreFile) && is_readable($likesCoreFile) ? file_get_contents($likesCoreFile) : false;
if ($likesCoreSource === false) {
    fwrite(STDERR, "Could not read the SBlog core source. Set SBLOG_CORE_FILE to the full path of a SBlog 1.14.13+ index.php file. The tests only read its source and never bootstrap the installed site.\n");
    exit(1);
}

date_default_timezone_set('Asia/Shanghai');
define('PLUGINS_DIR', dirname(__DIR__, 2));
$likesWorker = ($argv[1] ?? '') === '--request-worker';
if ($likesWorker) {
    $likesFixture = realpath((string)($argv[2] ?? ''));
    $temporaryRoot = str_replace('\\', '/', (string)realpath(sys_get_temp_dir()));
    if ($likesFixture === false || !preg_match(
        '#^' . preg_quote(rtrim($temporaryRoot, '/'), '#') . '/sblog-article-likes-tests-[a-f0-9]{16}/fixture\.sqlite$#iD',
        str_replace('\\', '/', $likesFixture)
    )) {
        fwrite(STDERR, "Workers may only open the temporary test fixture.\n");
        exit(1);
    }
    // Keep the exact salt string used by the main process for client_ip_hash().
    $likesFixture = (string)$argv[2];
} else {
    $likesTestDirectory = sys_get_temp_dir() . '/sblog-article-likes-tests-' . bin2hex(random_bytes(8));
    if (!mkdir($likesTestDirectory, 0700)) {
        throw new RuntimeException('Could not create the temporary test directory.');
    }
    $likesFixture = $likesTestDirectory . '/fixture.sqlite';
}
define('DB_FILE', $likesFixture);

function db(): PDO
{
    return $GLOBALS['likes_test_db'];
}

function likes_test_connect(): void
{
    $GLOBALS['likes_test_db'] = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    db()->exec('PRAGMA foreign_keys = ON');
    db()->exec('PRAGMA busy_timeout = 5000');
}

function sblog_t(string $key, array $parameters = []): string
{
    return $key;
}

function script_url(): string
{
    return '/blog/index.php';
}

function url_with_query(string $url, array $parameters): string
{
    return $url . '?' . http_build_query($parameters);
}

function plugin_asset_url(string $slug, string $path): string
{
    return '/blog/plugins/' . $slug . '/' . ltrim($path, '/');
}

function add_plugin_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['likes_test_actions'][$hook][$priority][] = $callback;
}

function add_theme_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['likes_test_theme_actions'][$hook][$priority][] = $callback;
}

function spc_release_plan_lock(): void
{
    $GLOBALS['likes_test_cache_releases'] = ($GLOBALS['likes_test_cache_releases'] ?? 0) + 1;
}

function likes_test_action(string $hook, array $context): void
{
    $callbacks = $GLOBALS['likes_test_actions'][$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $priorityCallbacks) {
        foreach ($priorityCallbacks as $callback) {
            $callback($context);
        }
    }
}

function likes_test_theme_output(string $hook): string
{
    $callbacks = $GLOBALS['likes_test_theme_actions'][$hook] ?? [];
    ksort($callbacks);
    $html = '';
    foreach ($callbacks as $priorityCallbacks) {
        foreach ($priorityCallbacks as $callback) {
            $html .= (string)$callback([]);
        }
    }
    return $html;
}

// Extract the real service and SQL helpers without executing the core bootstrap.
function likes_test_load_core(string $source, array $names): void
{
    $tokens = token_get_all($source);
    $loaded = [];
    for ($index = 0, $length = count($tokens); $index < $length; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
            continue;
        }
        $nameIndex = $index + 1;
        while (is_array($tokens[$nameIndex] ?? null) && $tokens[$nameIndex][0] === T_WHITESPACE) {
            $nameIndex++;
        }
        $name = $tokens[$nameIndex] ?? null;
        if (!is_array($name) || $name[0] !== T_STRING || !in_array($name[1], $names, true)) {
            continue;
        }
        $code = '';
        $depth = 0;
        $started = false;
        for (; $index < $length; $index++) {
            $token = $tokens[$index];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $started = true;
                $depth++;
            } elseif ($token === '}' && --$depth === 0 && $started) {
                break;
            }
        }
        if (!$started || $depth !== 0) {
            throw new RuntimeException('Could not extract core function ' . $name[1]);
        }
        eval($code);
        $loaded[] = $name[1];
    }
    $missing = array_diff($names, $loaded);
    if ($missing !== []) {
        throw new RuntimeException('Missing core functions: ' . implode(', ', $missing));
    }
}

likes_test_load_core($likesCoreSource, [
    'h', 'q', 'one', 'all_rows', 'val', 'csrf_token', 'post_like_count',
    'visitor_liked_post', 'like_post_for_visitor', 'client_ip_hash', 'client_ip_address',
]);
likes_test_connect();
$_SESSION = ['csrf_token' => 'article-likes-test-token'];
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
require dirname(__DIR__) . '/plugin.php';

if ($likesWorker) {
    $request = json_decode(base64_decode((string)($argv[3] ?? ''), true) ?: '', true, 512, JSON_THROW_ON_ERROR);
    $_POST = $request['post'] ?? [];
    $_SESSION = $request['session'] ?? ['csrf_token' => 'article-likes-test-token'];
    $_SERVER['REQUEST_METHOD'] = $request['method'] ?? 'POST';
    $_SERVER['REMOTE_ADDR'] = $request['ip'] ?? '203.0.113.8';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $request['forwarded'] ?? '';
    if (($request['read_only'] ?? false) === true) {
        db()->exec('PRAGMA query_only = ON');
    }
    ob_start();
    register_shutdown_function(static function (): void {
        $body = ob_get_clean();
        echo json_encode(['status' => http_response_code() ?: 200, 'body' => $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });
    likes_test_action('request', ['action' => $request['action'] ?? 'article_likes_vote']);
    echo 'not-handled';
    exit;
}

$likesAssertions = 0;
function likes_expect(bool $condition, string $message): void
{
    $GLOBALS['likesAssertions']++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function likes_request(array $changes = []): array
{
    $request = array_replace([
        'post' => ['csrf_token' => 'article-likes-test-token', 'post_id' => '1'],
        'session' => ['csrf_token' => 'article-likes-test-token'],
        'method' => 'POST',
        'ip' => '203.0.113.8',
    ], $changes);
    $command = [PHP_BINARY, __FILE__, '--request-worker', DB_FILE, base64_encode(json_encode($request, JSON_THROW_ON_ERROR))];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the request worker.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('Request worker failed: ' . $stderr . $stdout);
    }
    try {
        $response = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $response['json'] = $response['body'] === 'not-handled' ? null : json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Request did not return valid JSON: ' . $stdout . $stderr, 0, $exception);
    }
    return $response;
}

function likes_expect_error(array $response, int $status, string $message): void
{
    likes_expect($response['status'] === $status, $message . ': HTTP ' . $status);
    likes_expect(($response['json']['ok'] ?? null) === false, $message . ': ok=false');
    likes_expect(is_string($response['json']['error'] ?? null) && $response['json']['error'] !== '', $message . ': error message');
}

try {
    db()->exec("CREATE TABLE posts (
        id INTEGER PRIMARY KEY, kind TEXT NOT NULL, status TEXT NOT NULL,
        published_at INTEGER NOT NULL, content_password_hash TEXT NOT NULL DEFAULT ''
    )");
    if (!preg_match('/"(CREATE TABLE IF NOT EXISTS post_likes\([\s\S]+?\) WITHOUT ROWID)"/', $likesCoreSource, $schema)) {
        throw new RuntimeException('Could not extract the real post_likes schema.');
    }
    db()->exec($schema[1]);
    foreach ([
        [1, 'post', 'published', time() - 60, ''],
        [2, 'post', 'published', time() - 60, ''],
        [3, 'post', 'draft', time() - 60, ''],
        [4, 'post', 'private', time() - 60, ''],
        [5, 'post', 'published', time() + 86400, ''],
        [6, 'post', 'published', 0, ''],
        [7, 'page', 'published', time() - 60, ''],
        [8, 'post', 'trash', time() - 60, ''],
        [9, 'post', 'published', time() - 60, 'secret'],
    ] as $post) {
        q('INSERT INTO posts(id, kind, status, published_at, content_password_hash) VALUES(?,?,?,?,?)', $post);
    }
    $seedHashes = [];
    foreach (['198.51.100.51', '198.51.100.52'] as $address) {
        $_SERVER['REMOTE_ADDR'] = $address;
        $seedHashes[] = client_ip_hash();
        q('INSERT INTO post_likes(post_id, ip_hash, created_at) VALUES(?,?,?)', [1, client_ip_hash(), 1700000000]);
    }
    // Existing votes on hidden content must not become public counts or flags.
    q('INSERT INTO post_likes(post_id, ip_hash, created_at) VALUES(?,?,?)', [3, client_ip_hash(), 1700000000]);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
    likes_expect(post_like_count(1) === 2 && !visitor_liked_post(1), 'Core seed rows and visitor state are available');
    $states = article_likes_states([1, '1', 2, 3, 4, 5, 6, 7, 8, 999, 0, -1]);
    likes_expect($states[1] === ['count' => 2, 'liked' => false], 'Batch states preserve existing core likes');
    foreach ([2, 3, 4, 5, 6, 7, 8, 999] as $postId) {
        likes_expect($states[$postId] === ['count' => 0, 'liked' => false], 'Missing or hidden article has a default state: ' . $postId);
    }
    likes_expect(!array_key_exists(0, $states) && !array_key_exists(-1, $states) && count($states) === 9, 'Batch states normalize duplicate and invalid IDs');
    likes_expect(article_likes_states([]) === [], 'Empty batch has no state');
    likes_expect(article_likes_states([false, true, 1.5, [], '1 OR 1=1']) === [], 'Non-integer and SQL-shaped batch IDs are ignored');
    $chunked = article_likes_states(array_merge(range(1000, 1499), [1, 2]));
    likes_expect(count($chunked) === 502 && $chunked[1] === ['count' => 2, 'liked' => false] && $chunked[2] === ['count' => 0, 'liked' => false], 'Batch queries include IDs after the 500-ID chunk boundary');
    echo "PASS batch state, visibility, and legacy counts\n";

    $response = likes_request(['action' => 'home']);
    likes_expect($response['body'] === 'not-handled', 'Request hook leaves other actions alone');
    likes_expect_error(likes_request(['method' => 'GET', 'post' => []]), 405, 'GET requests are rejected before CSRF validation');
    foreach ([[], ['post_id' => '1'], ['post_id' => '1', 'csrf_token' => 'incorrect'], ['post_id' => '1', 'csrf_token' => ['article-likes-test-token']]] as $post) {
        likes_expect_error(likes_request(['post' => $post]), 422, 'Invalid or missing CSRF is rejected');
    }
    likes_expect_error(likes_request(['session' => []]), 422, 'Missing session token is rejected');
    likes_expect_error(likes_request(['session' => ['csrf_token' => '']]), 422, 'Empty session token is rejected');
    likes_expect(post_like_count(1) === 2, 'Method and CSRF errors do not write votes');
    echo "PASS request hook, method, and CSRF checks\n";

    foreach ([null, 0, -1, '1abc', '1.5', '999999999999999999999999', ['1'], 3, 4, 5, 6, 7, 8, 999] as $postId) {
        $post = ['csrf_token' => 'article-likes-test-token'];
        if ($postId !== null) {
            $post['post_id'] = $postId;
        }
        likes_expect_error(likes_request(['post' => $post]), 404, 'Unavailable or invalid article is rejected: ' . json_encode($postId));
    }
    likes_expect(post_like_count(1) === 2 && (int)val('SELECT COUNT(*) FROM post_likes') === 3, 'Rejected article requests do not write votes');
    echo "PASS invalid IDs and unavailable content\n";

    $response = likes_request();
    likes_expect($response['status'] === 200, 'First like succeeds');
    foreach (['ok' => true, 'post_id' => 1, 'count' => 3, 'liked' => true, 'created' => true] as $key => $expected) {
        likes_expect(($response['json'][$key] ?? null) === $expected, 'First like returns the expected ' . $key);
    }
    // Every request uses a separate process and PDO connection.
    $response = likes_request();
    likes_expect($response['status'] === 200 && $response['json']['count'] === 3 && $response['json']['created'] === false && $response['json']['liked'] === true, 'A later request from the same IP is deduplicated');
    $response = likes_request(['forwarded' => '192.0.2.199']);
    likes_expect($response['json']['count'] === 3 && $response['json']['created'] === false, 'A forged forwarded IP does not bypass the core visitor identity');
    $response = likes_request(['ip' => '203.0.113.9']);
    likes_expect($response['json']['count'] === 4 && $response['json']['created'] === true, 'A different visitor adds one vote');
    likes_test_connect();
    likes_expect(post_like_count(1) === 4 && visitor_liked_post(1), 'Core sees persisted plugin votes through a fresh PDO connection');
    likes_expect(article_likes_states([1, 2])[1] === ['count' => 4, 'liked' => true], 'Batch flags reflect the current visitor after voting');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    likes_expect(article_likes_states([1])[1] === ['count' => 4, 'liked' => false], 'Batch flags do not leak another visitor state');
    foreach ($seedHashes as $hash) {
        likes_expect((int)val('SELECT created_at FROM post_likes WHERE post_id = 1 AND ip_hash = ?', [$hash]) === 1700000000, 'Original core vote rows remain intact');
    }
    echo "PASS persistent votes, IP deduplication, and core interoperability\n";

    $response = likes_request(['post' => ['csrf_token' => 'article-likes-test-token', 'post_id' => '9']]);
    likes_expect($response['status'] === 200 && $response['json']['count'] === 1, 'Published password content keeps the core like policy');
    $response = likes_request(['read_only' => true, 'ip' => '203.0.113.11']);
    likes_expect_error($response, 500, 'Database write errors are returned as JSON');
    likes_expect(!preg_match('/SQLSTATE|PDOException|readonly|index\.php|fixture\.sqlite/i', $response['body']), 'Database error details are not exposed');
    likes_expect(post_like_count(1) === 4, 'Failed storage request does not alter the count');
    echo "PASS password policy and storage failure handling\n";

    $_SESSION['csrf_token'] = 'token"<&';
    $GLOBALS['sblog_static_page_cache_plan'] = ['path' => 'must-not-cache-visitor-token'];
    $button = article_likes_button(1, ['count' => 42, 'liked' => true]);
    foreach ([
        'class="article-likes-button', 'type="button"', 'data-article-like', 'data-post-id="1"',
        'data-like-endpoint="/blog/index.php?a=article_likes_vote"', 'data-like-csrf="token&quot;&lt;&amp;"',
        'aria-pressed="true"', 'aria-label="已点赞这篇文章"', 'data-like-label>已点赞</span>', 'data-like-count>42</b>',
    ] as $fragment) {
        likes_expect(str_contains($button, $fragment), 'Liked button includes ' . $fragment);
    }
    likes_expect(!isset($GLOBALS['sblog_static_page_cache_plan']) && ($GLOBALS['likes_test_cache_releases'] ?? 0) === 1, 'Rendering a visitor token disables the shared page cache plan and releases its lock');
    likes_expect(str_contains($button, '<svg') && str_contains($button, 'aria-hidden="true"') && str_contains($button, 'focusable="false"'), 'Heart icon is decorative');
    $button = article_likes_button(1);
    likes_expect(str_contains($button, 'aria-pressed="false"') && str_contains($button, 'data-like-count>4</b>'), 'Button can retrieve its current persisted state');
    likes_expect(article_likes_button(0) === '' && article_likes_button(-1) === '', 'Invalid IDs do not render a button');
    $head = likes_test_theme_output('head');
    $footer = likes_test_theme_output('body_close');
    likes_expect(str_contains($head, '/blog/plugins/article-likes/assets/article-likes.css'), 'Head hook includes the stylesheet');
    likes_expect(str_contains($footer, '/blog/plugins/article-likes/assets/article-likes.js'), 'Body hook includes the script');
    echo "PASS button attributes, escaping, and asset hooks\n";
    echo 'All ' . $likesAssertions . " assertions passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    $likesFailed = true;
} finally {
    unset($GLOBALS['likes_test_db']);
    foreach (glob($likesTestDirectory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($likesTestDirectory);
}
exit(isset($likesFailed) ? 1 : 0);
