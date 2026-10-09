<?php

declare(strict_types=1);

// Never load index.php, the installed database, an SMTP client, or external services.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "These tests require the pdo_sqlite extension.\n");
    exit(1);
}

date_default_timezone_set('Asia/Shanghai');
define('PLUGINS_DIR', dirname(__DIR__, 2));
$lotteryRaceWorker = ($argv[1] ?? '') === '--race-worker';
$lotteryTestDatabase = ':memory:';
if ($lotteryRaceWorker) {
    $lotteryTestDatabase = (string)($argv[2] ?? '');
    $tempRoot = str_replace('\\', '/', (string)realpath(sys_get_temp_dir()));
    $workerPath = str_replace('\\', '/', (string)realpath($lotteryTestDatabase));
    if (!preg_match('#^' . preg_quote(rtrim($tempRoot, '/'), '#') . '/sblog-comment-lottery-race-[a-f0-9]{16}/fixture\.sqlite$#iD', $workerPath)
        || !preg_match('/^[a-f0-9]{32}$/D', (string)($argv[3] ?? '')) || !in_array($argv[4] ?? '', ['1', '2'], true)) {
        fwrite(STDERR, "Race workers may only open the temporary test fixture.\n");
        exit(1);
    }
}
$GLOBALS['lottery_test_db'] = new PDO('sqlite:' . $lotteryTestDatabase, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
db()->exec('PRAGMA busy_timeout=5000');
$GLOBALS['lottery_test_mail'] = [];
$GLOBALS['lottery_test_mail_ok'] = true;

function db(): PDO { return $GLOBALS['lottery_test_db']; }
function q(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function one(string $sql, array $params = []): ?array { return q($sql, $params)->fetch() ?: null; }
function all_rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function val(string $sql, array $params = []): mixed { return q($sql, $params)->fetchColumn(); }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function str_len_u(string $value): int { return preg_match_all('/./us', $value); }
function str_sub_u(string $value, int $start, ?int $length = null): string
{
    preg_match_all('/./us', $value, $matches);
    return implode('', array_slice($matches[0], $start, $length));
}
function script_url(): string { return '/blog/index.php'; }
function url_with_query(string $url, array $params): string { return $url . '?' . http_build_query($params); }
function url_for(string $action = 'home', array $params = []): string
{
    return '/blog/index.php?' . http_build_query(['a' => $action] + $params);
}
function absolute_url(string $url): string { return 'https://blog.test' . $url; }
function content_permalink(array $post): string { return '/blog/posts/' . rawurlencode((string)$post['slug']); }
function is_admin(): bool { return false; }
function fetch_post_by_identifier(string $identifier, bool $allowPreview = false): ?array
{
    $post = one("SELECT * FROM posts WHERE kind='post' AND (slug=? OR id=?)", [$identifier, ctype_digit($identifier) ? (int)$identifier : 0]);
    if (!$post || (!$allowPreview && ($post['status'] !== 'published' || (int)$post['published_at'] <= 0 || (int)$post['published_at'] > time()))) {
        return null;
    }
    return $post;
}
function derive_excerpt(string $content, int $length = 140): string
{
    // Plain-text approximation is sufficient to verify that modules never enter an excerpt.
    $plain = preg_replace('/[#*`>]/u', '', strip_tags($content)) ?? $content;
    $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    return str_len_u($plain) <= $length ? $plain : rtrim(str_sub_u($plain, 0, $length)) . '…';
}
function setting(string $key, string $default = ''): string
{
    return ['site_name' => 'Lottery test blog', 'site_url' => 'https://blog.test', 'mail_enabled' => '1'][$key] ?? $default;
}
function send_site_mail(string $recipient, string $subject, string $body): bool
{
    $GLOBALS['lottery_test_mail'][] = compact('recipient', 'subject', 'body');
    if (($GLOBALS['lottery_test_mail_throw'] ?? false) === true) {
        throw new RuntimeException('Simulated transport failure');
    }
    return $GLOBALS['lottery_test_mail_ok'];
}
function plugin_callbacks(string $kind, string $hook): array { return []; }
function add_plugin_action(string $hook, callable $callback, int $priority = 10): void {}
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void {}
function add_theme_action(string $hook, callable $callback, int $priority = 10): void {}

if (!$lotteryRaceWorker) {
db()->exec("CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER, kind TEXT NOT NULL DEFAULT 'post',
    title TEXT NOT NULL, slug TEXT NOT NULL, content TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'published', published_at INTEGER NOT NULL DEFAULT 0
)");
db()->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, role TEXT)");
db()->exec("CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, user_id INTEGER,
    parent_id INTEGER, author_name TEXT NOT NULL, author_email TEXT NOT NULL,
    content TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'approved', created_at INTEGER NOT NULL
)");
}

require_once dirname(__DIR__) . '/engine.php';
sblog_lottery_init();

if ($lotteryRaceWorker) {
    $workerDirectory = dirname($lotteryTestDatabase);
    db()->sqliteCreateFunction('lottery_test_hold_lock', static function (): int { usleep(200000); return 1; }, 0);
    file_put_contents($workerDirectory . '/ready-' . $argv[4], 'ready');
    $deadline = microtime(true) + 10;
    while (!is_file($workerDirectory . '/go')) {
        if (microtime(true) >= $deadline) { fwrite(STDERR, "Race barrier timed out.\n"); exit(1); }
        clearstatcache();
        usleep(10000);
    }
    try {
        fwrite(STDOUT, json_encode(sblog_lottery_draw((string)$argv[3]), JSON_THROW_ON_ERROR));
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
}
require_once dirname(__DIR__) . '/views.php';

function lottery_test_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function lottery_test_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}
function lottery_test_reject(callable $callback, string $message): void
{
    try { $callback(); }
    catch (InvalidArgumentException | RuntimeException $exception) { return; }
    throw new RuntimeException($message);
}
function lottery_test_reset(): void
{
    foreach (['plugin_comment_lottery_winners', 'plugin_comment_lotteries', 'comments', 'posts', 'users'] as $table) {
        q('DELETE FROM ' . $table);
    }
    $GLOBALS['lottery_test_mail'] = [];
    $GLOBALS['lottery_test_mail_ok'] = true;
    $GLOBALS['lottery_test_mail_throw'] = false;
    $GLOBALS['sblog_current_action'] = '';
    $_GET = [];
}
function lottery_test_post(array $override = []): int
{
    $row = array_replace([
        'kind' => 'post', 'title' => 'Test article', 'slug' => bin2hex(random_bytes(4)),
        'content' => 'Article content', 'status' => 'published', 'published_at' => time() - 86400,
    ], $override);
    q('INSERT INTO posts(kind,title,slug,content,status,published_at) VALUES(?,?,?,?,?,?)', array_values($row));
    return (int)db()->lastInsertId();
}
function lottery_test_input(int $postId, array $override = []): array
{
    $start = intdiv(time(), 60) * 60 - 7200;
    return array_replace([
        'post_id' => (string)$postId, 'title' => 'Comment lottery', 'description' => 'Prize details',
        'starts_at' => date('Y-m-d\TH:i', $start), 'ends_at' => date('Y-m-d\TH:i', $start + 3600),
        'winners_count' => '3', 'unique_email' => '1', 'auto_draw' => '1', 'notify' => '0', 'roots_only' => '0',
    ], $override);
}
function lottery_test_bind(int $postId, string $content): void
{
    q('UPDATE posts SET content=? WHERE id=?', [$content, $postId]);
    sblog_lottery_sync_post(['post_id' => $postId, 'data' => ['content' => $content, 'kind' => 'post']]);
}
function lottery_test_create(array $override = [], ?int $postId = null, bool $bind = true): array
{
    $postId ??= lottery_test_post();
    $lottery = sblog_lottery_save(lottery_test_input($postId, $override));
    if ($bind) {
        lottery_test_bind($postId, 'Article text' . "\n\n" . '[comment-lottery id="' . $lottery['id'] . '"]' . "\n");
    }
    return sblog_lottery_get($lottery['id']);
}
function lottery_test_comment(int $postId, int $createdAt, array $override = []): int
{
    $row = array_replace([
        'post_id' => $postId, 'user_id' => null, 'parent_id' => null,
        'author_name' => 'Reader', 'author_email' => 'reader-' . bin2hex(random_bytes(4)) . '@example.test',
        'content' => 'Comment body', 'status' => 'approved', 'created_at' => $createdAt,
    ], $override);
    q('INSERT INTO comments(post_id,user_id,parent_id,author_name,author_email,content,status,created_at) VALUES(?,?,?,?,?,?,?,?)', array_values($row));
    return (int)db()->lastInsertId();
}
function lottery_test_winner_ids(string $id): array
{
    $ids = array_map(static fn(array $winner): int => (int)$winner['comment_id'], sblog_lottery_winners($id));
    sort($ids);
    return $ids;
}
function lottery_test_buffered_output(string $html): string
{
    $initialLevel = ob_get_level();
    ob_start();
    ob_start(static fn(string $response): string => sblog_lottery_output($response, ['action' => 'post']));
    try {
        echo $html;
        ob_end_flush();
        return (string)ob_get_clean();
    } finally {
        while (ob_get_level() > $initialLevel) { ob_end_clean(); }
    }
}

$tests = [];
$tests['configuration validates dates, count, scalar inputs and chronological order'] = static function (): void {
    $postId = lottery_test_post();
    foreach ([
        ['starts_at' => '2026-02-30T12:00'], ['starts_at' => '2026-01-01T25:00'],
        ['starts_at' => 'not-a-date'], ['ends_at' => []], ['title' => []], ['post_id' => []],
        ['description' => []], ['id' => []], ['unique_email' => []], ['auto_draw' => 'yes'],
        ['winners_count' => '0'], ['winners_count' => '101'], ['winners_count' => '1.5'],
        ['winners_count' => []], ['starts_at' => '2030-01-02T00:00', 'ends_at' => '2030-01-01T00:00'],
        ['starts_at' => '2030-01-01T00:00', 'ends_at' => '2030-01-01T00:00'],
    ] as $invalid) {
        lottery_test_reject(static fn() => sblog_lottery_save(lottery_test_input($postId, $invalid)), 'Invalid input was accepted: ' . json_encode($invalid));
    }
    lottery_test_same(0, (int)val('SELECT COUNT(*) FROM plugin_comment_lotteries'), 'Rejected input must not create lotteries.');
};
$tests['saved configuration is pending until its marker is saved in the article'] = static function (): void {
    $lottery = lottery_test_create([], null, false);
    lottery_test_assert((bool)preg_match('/^[a-f0-9]{32}$/D', $lottery['id']), 'Lottery token must be an opaque 32-character hex value.');
    lottery_test_same('pending', $lottery['status'], 'A new lottery must be pending.');
    lottery_test_same(0, (int)$lottery['enabled'], 'An unsaved article marker must not activate a lottery.');
    sblog_lottery_tick(time(), 'test');
    lottery_test_same([], lottery_test_winner_ids($lottery['id']), 'Unsaved lotteries must not draw.');
};
$tests['a new article can bind its unsaved lottery after the first core save'] = static function (): void {
    $input = lottery_test_input(0, [
        'starts_at' => date('Y-m-d\TH:i', time() + 3600),
        'ends_at' => date('Y-m-d\TH:i', time() + 7200),
    ]);
    $lottery = sblog_lottery_save($input);
    lottery_test_same(0, (int)($lottery['post_id'] ?? 0), 'Creating a module before a new article exists must not invent a binding.');
    $postId = lottery_test_post(['status' => 'draft']);
    lottery_test_bind($postId, '[comment-lottery id="' . $lottery['id'] . '"]');
    lottery_test_same($postId, (int)sblog_lottery_get($lottery['id'])['post_id'], 'The first article save must bind the new module.');
    $edited = sblog_lottery_save(array_replace($input, ['id' => $lottery['id'], 'post_id' => (string)$postId, 'title' => 'Edited before start']));
    lottery_test_same('Edited before start', $edited['title'], 'A draft activity must remain editable.');
    lottery_test_same($lottery['id'], $edited['id'], 'Editing must retain the module token.');
    lottery_test_same(1, (int)val('SELECT COUNT(*) FROM plugin_comment_lotteries'), 'Editing must not create a second lottery.');
};
$tests['datetime-local uses Asia Shanghai even when the host default timezone differs'] = static function (): void {
    date_default_timezone_set('UTC');
    try {
        $lottery = lottery_test_create(['starts_at' => '2030-01-01T12:00', 'ends_at' => '2030-01-01T13:00']);
        $expected = (new DateTimeImmutable('2030-01-01 12:00', new DateTimeZone('Asia/Shanghai')))->getTimestamp();
        lottery_test_same($expected, (int)$lottery['starts_at'], 'Editor dates must consistently use the advertised timezone.');
    } finally { date_default_timezone_set('Asia/Shanghai'); }
};
$tests['only the first standalone module is active and copied tokens cannot rebind'] = static function (): void {
    $postId = lottery_test_post();
    $first = lottery_test_create([], $postId, false);
    $second = lottery_test_create([], $postId, false);
    lottery_test_bind($postId, '[comment-lottery id="' . $first['id'] . '"]' . "\n\n" . '[comment-lottery id="' . $second['id'] . '"]');
    lottery_test_same(1, (int)sblog_lottery_get($first['id'])['enabled'], 'The first module must activate.');
    lottery_test_same(0, (int)sblog_lottery_get($second['id'])['enabled'], 'A second module in the same article must remain inactive.');
    $otherPost = lottery_test_post();
    lottery_test_bind($otherPost, '[comment-lottery id="' . $first['id'] . '"]');
    lottery_test_same($postId, (int)sblog_lottery_get($first['id'])['post_id'], 'A copied marker must never move an existing draw to another post.');
};
$tests['inline markers and fenced code examples cannot activate a lottery'] = static function (): void {
    foreach (['inline', 'fenced'] as $mode) {
        $postId = lottery_test_post();
        $lottery = lottery_test_create([], $postId, false);
        $marker = '[comment-lottery id="' . $lottery['id'] . '"]';
        $content = $mode === 'inline' ? 'An example: ' . $marker : "```text\n" . $marker . "\n```";
        lottery_test_bind($postId, $content);
        lottery_test_same(0, (int)sblog_lottery_get($lottery['id'])['enabled'], 'An inert marker activated: ' . $mode);
    }
};
$tests['time bounds and guest approval filters exclude ineligible comments'] = static function (): void {
    $lottery = lottery_test_create(['winners_count' => '100']);
    $postId = (int)$lottery['post_id']; $start = (int)$lottery['starts_at']; $end = (int)$lottery['ends_at'];
    $expected = [lottery_test_comment($postId, $start), lottery_test_comment($postId, $end - 1, ['user_id' => 0])];
    lottery_test_comment($postId, $start - 1);
    lottery_test_comment($postId, $end);
    lottery_test_comment($postId, $start + 1, ['status' => 'pending']);
    lottery_test_comment($postId, $start + 1, ['status' => 'spam']);
    lottery_test_comment($postId, $start + 1, ['user_id' => 1]);
    lottery_test_comment($postId, $start + 1, ['author_email' => 'invalid-email']);
    lottery_test_comment(lottery_test_post(), $start + 1);
    $result = sblog_lottery_draw($lottery['id'], $end);
    lottery_test_assert($result['drawn'], 'A draw must run exactly at its end time.');
    sort($expected);
    lottery_test_same($expected, lottery_test_winner_ids($lottery['id']), 'Only approved guests with valid emails in the start-inclusive/end-exclusive window qualify.');
};
$tests['email dedup uses the first chronological comment and folds case and whitespace'] = static function (): void {
    $lottery = lottery_test_create(['winners_count' => '100']);
    $postId = (int)$lottery['post_id']; $start = (int)$lottery['starts_at'];
    $late = lottery_test_comment($postId, $start + 20, ['author_email' => 'Reader@example.test']);
    $first = lottery_test_comment($postId, $start + 10, ['author_email' => 'reader@example.test']);
    lottery_test_comment($postId, $start + 30, ['author_email' => ' reader@example.test ']);
    $other = lottery_test_comment($postId, $start + 10, ['author_email' => 'other@example.test']);
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    $expected = [$first, $other]; sort($expected);
    lottery_test_same($expected, lottery_test_winner_ids($lottery['id']), 'An email must receive a single entry using its earliest eligible comment.');
    lottery_test_assert(!in_array($late, lottery_test_winner_ids($lottery['id']), true), 'Insertion order must not override comment time.');
};
$tests['per-comment mode allows multiple eligible comments from the same email'] = static function (): void {
    $lottery = lottery_test_create(['winners_count' => '100', 'unique_email' => '0']);
    $expected = [];
    for ($i = 0; $i < 3; $i++) {
        $expected[] = lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at'] + $i, ['author_email' => 'same@example.test']);
    }
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    lottery_test_same($expected, lottery_test_winner_ids($lottery['id']), 'Disabling dedup should preserve every eligible comment.');
};
$tests['roots-only mode excludes replies while normal mode includes them'] = static function (): void {
    foreach (['1', '0'] as $rootsOnly) {
        $lottery = lottery_test_create(['winners_count' => '100', 'roots_only' => $rootsOnly]);
        $root = lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at']);
        $reply = lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at'] + 1, ['parent_id' => $root]);
        sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
        lottery_test_same($rootsOnly === '1' ? [$root] : [$root, $reply], lottery_test_winner_ids($lottery['id']), 'Reply participation must follow roots_only.');
    }
};
$tests['random selection returns the requested number of distinct eligible comments'] = static function (): void {
    $lottery = lottery_test_create(['winners_count' => '5']);
    $candidates = [];
    for ($i = 0; $i < 40; $i++) { $candidates[] = lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at'] + $i); }
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    $winners = lottery_test_winner_ids($lottery['id']);
    lottery_test_same(5, count($winners), 'Draw must choose the configured winner count when enough comments exist.');
    lottery_test_same(5, count(array_unique($winners)), 'A comment cannot win twice in one draw.');
    lottery_test_same([], array_values(array_diff($winners, $candidates)), 'Every winner must belong to the eligible set.');
};
$tests['winner persistence and finalization roll back together on database failure'] = static function (): void {
    $lottery = lottery_test_create(['winners_count' => '3']);
    for ($i = 0; $i < 3; $i++) { lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at'] + $i); }
    db()->exec("CREATE TEMP TRIGGER lottery_test_insert_failure BEFORE INSERT ON plugin_comment_lottery_winners
        WHEN NEW.position=2 BEGIN SELECT RAISE(ABORT,'Simulated database failure'); END");
    try {
        lottery_test_reject(static fn() => sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']), 'A simulated persistence failure was not reported.');
        lottery_test_same([], lottery_test_winner_ids($lottery['id']), 'A failed transaction must leave no partial winner rows.');
        lottery_test_same('pending', sblog_lottery_get($lottery['id'])['status'], 'A failed transaction must leave the activity pending.');
    } finally { db()->exec('DROP TRIGGER lottery_test_insert_failure'); }
    lottery_test_assert(sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at'])['drawn'], 'The failed transaction must release its lock for a later retry.');
    lottery_test_same(3, count(lottery_test_winner_ids($lottery['id'])), 'A later retry must persist one complete result.');
};
$tests['no eligible comments still finalizes and later comments cannot trigger redraw'] = static function (): void {
    $lottery = lottery_test_create();
    $result = sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    lottery_test_assert($result['drawn'], 'An empty draw must be finalized.');
    lottery_test_same('drawn', sblog_lottery_get($lottery['id'])['status'], 'An empty result must have final drawn status.');
    lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at']);
    lottery_test_assert(!sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at'] + 1)['drawn'], 'Finalized empty draws must not run again.');
    lottery_test_same([], lottery_test_winner_ids($lottery['id']), 'A finalized empty result must remain empty.');
};
$tests['manual drawing refuses premature, draft, future publication and missing-marker posts'] = static function (): void {
    foreach (['early', 'draft', 'future', 'removed'] as $mode) {
        $lottery = lottery_test_create(); $postId = (int)$lottery['post_id'];
        lottery_test_comment($postId, (int)$lottery['starts_at']);
        $drawAt = (int)$lottery['ends_at'];
        if ($mode === 'early') { $drawAt--; }
        if ($mode === 'draft') { q("UPDATE posts SET status='draft' WHERE id=?", [$postId]); }
        if ($mode === 'future') { q('UPDATE posts SET published_at=? WHERE id=?', [$drawAt + 600, $postId]); }
        if ($mode === 'removed') { q("UPDATE posts SET content='Module removed' WHERE id=?", [$postId]); }
        lottery_test_assert(!sblog_lottery_draw($lottery['id'], $drawAt)['drawn'], 'Unsafe draw ran for state: ' . $mode);
        lottery_test_same([], lottery_test_winner_ids($lottery['id']), 'Refused draws must not persist winners.');
    }
};
$tests['automatic tick draws only enabled due automatic lotteries on published posts'] = static function (): void {
    $eligible = lottery_test_create();
    $manual = lottery_test_create(['auto_draw' => '0']);
    $unbound = lottery_test_create([], null, false);
    $draft = lottery_test_create();
    q("UPDATE posts SET status='draft' WHERE id=?", [(int)$draft['post_id']]);
    $future = lottery_test_create(['starts_at' => date('Y-m-d\TH:i', time() + 3600), 'ends_at' => date('Y-m-d\TH:i', time() + 7200)]);
    foreach ([$eligible, $manual, $unbound, $draft, $future] as $lottery) {
        lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at']);
    }
    sblog_lottery_tick(time(), 'test');
    lottery_test_same(1, count(lottery_test_winner_ids($eligible['id'])), 'The due automatic draw was missed.');
    foreach ([$manual, $unbound, $draft, $future] as $lottery) {
        lottery_test_same([], lottery_test_winner_ids($lottery['id']), 'Automatic tick included a disabled or unavailable lottery.');
    }
};
$tests['removed modules cannot permanently block a later automatic draw'] = static function (): void {
    for ($i = 0; $i < 21; $i++) {
        $stale = lottery_test_create();
        q("UPDATE posts SET content='Module removed' WHERE id=?", [(int)$stale['post_id']]);
        q('UPDATE plugin_comment_lotteries SET ends_at=ends_at-60 WHERE id=?', [$stale['id']]);
    }
    $live = lottery_test_create();
    lottery_test_comment((int)$live['post_id'], (int)$live['starts_at']);
    // A bounded scheduler can drain stale items in batches; allow three ticks.
    for ($i = 0; $i < 3; $i++) { sblog_lottery_tick(time(), 'test'); }
    lottery_test_same(1, count(lottery_test_winner_ids($live['id'])), 'Stale oldest rows repeatedly consumed the entire scheduler batch.');
};
$tests['repeated draws and ticks preserve the same persisted result'] = static function (): void {
    $lottery = lottery_test_create(['winners_count' => '2']);
    for ($i = 0; $i < 10; $i++) { lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at'] + $i); }
    $first = sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    lottery_test_assert($first['drawn'], 'Initial draw must succeed.');
    $snapshot = sblog_lottery_winners($lottery['id']);
    for ($i = 0; $i < 3; $i++) {
        lottery_test_assert(!sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at'] + $i + 1)['drawn'], 'A completed lottery redrew.');
        sblog_lottery_tick(time(), 'test');
    }
    lottery_test_same($snapshot, sblog_lottery_winners($lottery['id']), 'Repeated callers must preserve the complete winner snapshot.');
};
$tests['two overlapping PHP processes finalize exactly one complete draw'] = static function (): void {
    lottery_test_assert(function_exists('proc_open'), 'The concurrency test requires proc_open.');
    $lottery = lottery_test_create(['winners_count' => '5']);
    for ($i = 0; $i < 30; $i++) { lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at'] + $i); }
    $directory = rtrim(sys_get_temp_dir(), '/\\') . '/sblog-comment-lottery-race-' . bin2hex(random_bytes(8));
    lottery_test_assert(mkdir($directory, 0700), 'Could not create a scratch database directory.');
    $databaseFile = $directory . '/fixture.sqlite';
    $processes = []; $pipes = []; $raceDatabase = null;
    try {
        q('VACUUM INTO ?', [$databaseFile]);
        $raceDatabase = new PDO('sqlite:' . $databaseFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Deliberately hold the first process's write transaction while the other begins.
        $raceDatabase->exec("CREATE TRIGGER lottery_test_overlap BEFORE INSERT ON plugin_comment_lottery_winners
            WHEN NEW.position=1 BEGIN SELECT lottery_test_hold_lock(); END");
        $raceDatabase = null;
        $extensionDirectory = (string)ini_get('extension_dir');
        if (!is_dir($extensionDirectory)) { $extensionDirectory = dirname(PHP_BINARY) . '/ext'; }
        // Windows commonly builds PDO into PHP; Linux commonly loads it as a shared module.
        // Probe a clean CLI process so workers load only missing modules, in dependency order.
        $probe = proc_open([PHP_BINARY, '-n', '-r', 'echo json_encode([extension_loaded("PDO"), extension_loaded("pdo_sqlite")]);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $probePipes, null, null, ['bypass_shell' => true]);
        lottery_test_assert(is_resource($probe), 'Could not probe the PHP worker extensions.');
        fclose($probePipes[0]);
        $probeOutput = stream_get_contents($probePipes[1]);
        $probeError = stream_get_contents($probePipes[2]);
        fclose($probePipes[1]); fclose($probePipes[2]);
        lottery_test_same(0, proc_close($probe), 'PHP worker extension probe failed: ' . $probeError);
        $builtinExtensions = json_decode($probeOutput, true, 512, JSON_THROW_ON_ERROR);
        $workerCommand = [PHP_BINARY, '-n', '-d', 'extension_dir=' . $extensionDirectory];
        if (!$builtinExtensions[0]) { array_push($workerCommand, '-d', 'extension=pdo'); }
        if (!$builtinExtensions[1]) { array_push($workerCommand, '-d', 'extension=pdo_sqlite'); }
        for ($worker = 1; $worker <= 2; $worker++) {
            $command = array_merge($workerCommand, [__FILE__, '--race-worker', $databaseFile, $lottery['id'], (string)$worker]);
            $processes[$worker] = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$worker], null, null, ['bypass_shell' => true]);
            lottery_test_assert(is_resource($processes[$worker]), 'Could not launch a PHP race worker.');
            fclose($pipes[$worker][0]);
        }
        $deadline = microtime(true) + 10;
        while (!is_file($directory . '/ready-1') || !is_file($directory . '/ready-2')) {
            $statuses = array_map('proc_get_status', $processes);
            $exited = array_filter($statuses, static fn(array $status): bool => !$status['running']);
            if ($exited !== [] || microtime(true) >= $deadline) {
                $diagnostics = [];
                foreach ($processes as $worker => $process) {
                    stream_set_blocking($pipes[$worker][1], false);
                    stream_set_blocking($pipes[$worker][2], false);
                    $diagnostics[] = 'Worker ' . $worker . ': ' . ($statuses[$worker]['running'] ? 'running' : 'exit ' . $statuses[$worker]['exitcode'])
                        . "\nSTDOUT: " . stream_get_contents($pipes[$worker][1]) . "\nSTDERR: " . stream_get_contents($pipes[$worker][2]);
                }
                throw new RuntimeException(($exited !== [] ? 'A concurrent worker exited before the start barrier.' : 'Concurrent workers did not reach the start barrier.')
                    . "\n" . implode("\n", $diagnostics));
            }
            clearstatcache();
            usleep(10000);
        }
        file_put_contents($directory . '/go', 'go');
        $results = [];
        foreach ($processes as $worker => $process) {
            $stdout = stream_get_contents($pipes[$worker][1]);
            $stderr = stream_get_contents($pipes[$worker][2]);
            fclose($pipes[$worker][1]); fclose($pipes[$worker][2]);
            lottery_test_same(0, proc_close($process), 'A race worker failed: ' . $stderr);
            $processes[$worker] = null;
            $results[] = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        }
        lottery_test_same(1, count(array_filter($results, static fn(array $result): bool => $result['drawn'])), 'Exactly one competing process must perform the draw.');
        $raceDatabase = new PDO('sqlite:' . $databaseFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $statement = $raceDatabase->prepare('SELECT status FROM plugin_comment_lotteries WHERE id=?');
        $statement->execute([$lottery['id']]);
        lottery_test_same('drawn', $statement->fetchColumn(), 'The shared activity must be finalized.');
        $statement = $raceDatabase->prepare('SELECT COUNT(*),COUNT(DISTINCT comment_id) FROM plugin_comment_lottery_winners WHERE lottery_id=?');
        $statement->execute([$lottery['id']]);
        lottery_test_same([5, 5], array_map('intval', $statement->fetch(PDO::FETCH_NUM)), 'Concurrent callers must persist five distinct winners with no partial or duplicate rows.');
        $statement = null;
    } finally {
        foreach ($processes as $worker => $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                foreach ($pipes[$worker] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                proc_close($process);
            }
        }
        $raceDatabase = null;
        foreach (['ready-1', 'ready-2', 'go', 'fixture.sqlite', 'fixture.sqlite-journal', 'fixture.sqlite-wal', 'fixture.sqlite-shm'] as $file) {
            if (is_file($directory . '/' . $file)) { unlink($directory . '/' . $file); }
        }
        rmdir($directory);
    }
};
$tests['active and drawn lotteries cannot change participation rules'] = static function (): void {
    $lottery = lottery_test_create();
    $input = lottery_test_input((int)$lottery['post_id'], ['id' => $lottery['id'], 'winners_count' => '10']);
    lottery_test_reject(static fn() => sblog_lottery_save($input), 'Started lotteries must not change the winner count.');
    lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at']);
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    lottery_test_reject(static fn() => sblog_lottery_save($input), 'Drawn lotteries must be immutable.');
    lottery_test_same(3, (int)sblog_lottery_get($lottery['id'])['winners_count'], 'Rejected edits must preserve the configured count.');
};
$tests['winner snapshots survive edits and deletion of the original comment'] = static function (): void {
    $lottery = lottery_test_create(['notify' => '1', 'winners_count' => '1']);
    $commentId = lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at'], [
        'author_name' => 'Original reader', 'author_email' => 'original@example.test', 'content' => 'Original winning comment',
    ]);
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    $snapshot = sblog_lottery_winners($lottery['id']);
    q("UPDATE comments SET author_name='Changed',author_email='changed@example.test',content='Changed' WHERE id=?", [$commentId]);
    q('DELETE FROM comments WHERE id=?', [$commentId]);
    lottery_test_same($snapshot, sblog_lottery_winners($lottery['id']), 'The published winning result must retain its original snapshot.');
    sblog_lottery_notify($lottery['id']);
    lottery_test_same('original@example.test', $GLOBALS['lottery_test_mail'][0]['recipient'], 'Notifications must use the original winning address.');
    lottery_test_assert(str_contains($GLOBALS['lottery_test_mail'][0]['body'], 'Original winning comment'), 'The notice must include the original winning comment.');
};
$tests['notification failure preserves winners and successful retry sends once'] = static function (): void {
    $lottery = lottery_test_create(['notify' => '1', 'winners_count' => '1']);
    lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at']);
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    $ids = lottery_test_winner_ids($lottery['id']);
    $GLOBALS['lottery_test_mail_ok'] = false;
    sblog_lottery_notify($lottery['id']);
    lottery_test_same(1, count($GLOBALS['lottery_test_mail']), 'The fake failing transport must be invoked once.');
    lottery_test_same($ids, lottery_test_winner_ids($lottery['id']), 'Mail failure must not affect the draw result.');
    $GLOBALS['lottery_test_mail_ok'] = true;
    sblog_lottery_notify($lottery['id'], 20, true);
    lottery_test_same(2, count($GLOBALS['lottery_test_mail']), 'Explicit retry must attempt the failed winner.');
    sblog_lottery_notify($lottery['id'], 20, true);
    sblog_lottery_tick(time(), 'test');
    lottery_test_same(2, count($GLOBALS['lottery_test_mail']), 'A successful notice must never be resent.');
    lottery_test_same($ids, lottery_test_winner_ids($lottery['id']), 'Retries must preserve winners.');
};
$tests['notification batches honor their limit and disabled notifications send nothing'] = static function (): void {
    $lottery = lottery_test_create(['notify' => '1', 'winners_count' => '3']);
    for ($i = 0; $i < 3; $i++) { lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at'] + $i); }
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    sblog_lottery_notify($lottery['id'], 1);
    lottery_test_same(1, count($GLOBALS['lottery_test_mail']), 'Notification batch limit must be honored.');
    sblog_lottery_notify($lottery['id'], 20);
    lottery_test_same(3, count($GLOBALS['lottery_test_mail']), 'Remaining notices must be sent once in later batches.');
    $disabled = lottery_test_create(['notify' => '0', 'winners_count' => '1']);
    lottery_test_comment((int)$disabled['post_id'], (int)$disabled['starts_at']);
    sblog_lottery_draw($disabled['id'], (int)$disabled['ends_at']);
    sblog_lottery_notify($disabled['id'], 20, true);
    lottery_test_same(3, count($GLOBALS['lottery_test_mail']), 'Disabling notifications must prevent all mail calls.');
};
$tests['automatic mail retry honors cooldown and stops after five attempts'] = static function (): void {
    $lottery = lottery_test_create(['notify' => '1', 'winners_count' => '1']);
    lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at']);
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    $GLOBALS['lottery_test_mail_ok'] = false;
    sblog_lottery_notify($lottery['id']);
    sblog_lottery_notify($lottery['id']);
    lottery_test_same(1, count($GLOBALS['lottery_test_mail']), 'A failure must wait for the retry cooldown.');
    for ($attempt = 2; $attempt <= 5; $attempt++) {
        q('UPDATE plugin_comment_lottery_winners SET mail_attempted_at=? WHERE lottery_id=?', [time() - 301, $lottery['id']]);
        sblog_lottery_notify($lottery['id']);
    }
    lottery_test_same(5, count($GLOBALS['lottery_test_mail']), 'Automatic sending should make at most five attempts.');
    q('UPDATE plugin_comment_lottery_winners SET mail_attempted_at=? WHERE lottery_id=?', [time() - 301, $lottery['id']]);
    sblog_lottery_notify($lottery['id']);
    lottery_test_same(5, count($GLOBALS['lottery_test_mail']), 'Exhausted automatic retries must stop.');
    $GLOBALS['lottery_test_mail_ok'] = true;
    sblog_lottery_notify($lottery['id'], 20, true);
    lottery_test_same(6, count($GLOBALS['lottery_test_mail']), 'An administrator may explicitly retry an exhausted failed notice.');
};
$tests['ambiguous sending notices are not automatically resent'] = static function (): void {
    $lottery = lottery_test_create(['notify' => '1', 'winners_count' => '1']);
    lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at']);
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    q("UPDATE plugin_comment_lottery_winners SET mail_status='sending',mail_attempts=1,mail_attempted_at=? WHERE lottery_id=?", [time() - 3600, $lottery['id']]);
    sblog_lottery_notify($lottery['id']);
    sblog_lottery_tick(time(), 'test');
    lottery_test_same([], $GLOBALS['lottery_test_mail'], 'An interrupted send may already have reached SMTP and must not be automatically duplicated.');
};
$tests['public modules render inside an output callback and escape winner data without exposing email'] = static function (): void {
    $lottery = lottery_test_create(['winners_count' => '1']);
    $name = '<script>alert("winner-name")</script>';
    $body = '<img src=x onerror=alert("winner-body")> Winning comment';
    $email = 'private-winner@example.test';
    lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at'], [
        'author_name' => $name, 'author_email' => $email, 'content' => $body,
    ]);
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    $source = '<html><body><p>[comment-lottery id=&quot;' . $lottery['id'] . '&quot;]</p></body></html>';
    $post = one('SELECT * FROM posts WHERE id=?', [(int)$lottery['post_id']]);
    $GLOBALS['sblog_current_action'] = 'post';
    foreach ([['slug' => $post['slug']], ['id' => (string)$post['id']]] as $query) {
        $_GET = $query;
        // This exact buffering context caught the former fatal nested-buffer renderer.
        $html = lottery_test_buffered_output($source);
        lottery_test_assert(str_contains($html, '<section class="sblog-lottery"'), 'The public module was not rendered inside the output handler.');
        lottery_test_assert(!str_contains($html, '[comment-lottery'), 'The inserted shortcode must be replaced.');
        lottery_test_assert(str_contains($html, h($name)) && str_contains($html, h($body)), 'Winner names and comment text must be rendered as escaped text.');
        lottery_test_assert(!str_contains($html, $name) && !str_contains($html, $body), 'Raw winner markup reached the response.');
        lottery_test_assert(!str_contains($html, $email), 'The public result must never expose the winning email address.');
    }
};
$tests['locked article gates and withdrawn winning comments keep private content hidden'] = static function (): void {
    $lottery = lottery_test_create(['winners_count' => '1']);
    $name = 'Winner private identity'; $body = 'Winning comment private content';
    $commentId = lottery_test_comment((int)$lottery['post_id'], (int)$lottery['starts_at'], ['author_name' => $name, 'content' => $body]);
    sblog_lottery_draw($lottery['id'], (int)$lottery['ends_at']);
    $GLOBALS['sblog_current_action'] = 'post';
    $_GET = ['id' => (string)$lottery['post_id']];
    $gate = '<html><body><section class="password-gate">输入密码后查看文章</section></body></html>';
    lottery_test_same($gate, lottery_test_buffered_output($gate), 'The module must not be inserted across a locked article gate.');
    $source = '<html><body><p>[comment-lottery id=&quot;' . $lottery['id'] . '&quot;]</p></body></html>';
    foreach (['pending', 'deleted'] as $state) {
        if ($state === 'deleted') { q('DELETE FROM comments WHERE id=?', [$commentId]); }
        else { q("UPDATE comments SET status='pending' WHERE id=?", [$commentId]); }
        $html = lottery_test_buffered_output($source);
        lottery_test_assert(str_contains($html, '该评论已撤下'), 'A withdrawn winning comment must show its withdrawn status.');
        lottery_test_assert(!str_contains($html, $name) && !str_contains($html, $body), 'Withdrawn winning identity or comment text must not remain public.');
        lottery_test_same(1, count(sblog_lottery_winners($lottery['id'])), 'Withdrawing a comment must preserve the draw audit record.');
    }
};
$tests['module-first articles get readable summaries while custom excerpts are preserved'] = static function (): void {
    $lottery = lottery_test_create();
    $marker = '[comment-lottery id="' . $lottery['id'] . '"]';
    $context = ['kind' => 'post', 'content' => $marker . "\n\n" . 'Visible article summary.'];
    $fields = sblog_lottery_excerpt(['excerpt' => '', 'title' => 'Original title'], $context);
    lottery_test_same('Visible article summary.', $fields['excerpt'], 'An automatically derived summary must omit the lottery module.');
    lottery_test_same('Original title', $fields['title'], 'The excerpt filter must preserve unrelated fields.');
    $custom = ['excerpt' => 'A manually written summary.', 'title' => 'Original title'];
    lottery_test_same($custom, sblog_lottery_excerpt($custom, $context), 'A supplied custom excerpt must survive unchanged.');
    $moduleOnly = sblog_lottery_excerpt(['excerpt' => ''], ['kind' => 'post', 'content' => $marker]);
    lottery_test_assert($moduleOnly['excerpt'] !== '' && !str_contains($moduleOnly['excerpt'], '[comment-lottery'), 'A module-only article needs a readable fallback summary.');
};

$failed = 0;
foreach ($tests as $name => $test) {
    lottery_test_reset();
    try { $test(); fwrite(STDOUT, '[PASS] ' . $name . "\n"); }
    catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[FAIL] ' . $name . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n");
    }
}
fwrite($failed ? STDERR : STDOUT, count($tests) . ' tests, ' . $failed . " failures.\n");
exit($failed ? 1 : 0);
