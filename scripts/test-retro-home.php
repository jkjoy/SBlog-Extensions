<?php

declare(strict_types=1);

// SBLOG_CORE_ROOT=/path/to/Simple-PHP-Blog php scripts/test-retro-home.php [--keep]
// SBLOG_CORE_ROOT=/path/to/Simple-PHP-Blog php scripts/test-retro-home.php --prepare /tmp/sblog-retro-home-preview
// SBLOG_CORE_ROOT=/path/to/Simple-PHP-Blog php scripts/test-retro-home.php --sync /tmp/sblog-retro-home-preview
// The real core runs against a new database in an isolated temporary checkout.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

function retro_test_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function retro_test_copy(string $source, string $destination): void
{
    retro_test_assert(!is_link($source), 'Fixture sources cannot be symlinks.');
    if (is_dir($source)) {
        if (!is_dir($destination)) { mkdir($destination, 0700, true); }
        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry) {
            retro_test_copy($entry->getPathname(), $destination . '/' . $entry->getFilename());
        }
    } else {
        retro_test_assert(is_file($source), 'Missing fixture source: ' . $source);
        retro_test_assert(copy($source, $destination), 'Could not copy fixture source: ' . $source);
    }
}

function retro_test_core_root(): string
{
    static $root;
    if (is_string($root)) { return $root; }
    $configured = getenv('SBLOG_CORE_ROOT');
    retro_test_assert(is_string($configured) && trim($configured) !== '', 'Set SBLOG_CORE_ROOT to a readable Simple PHP Blog source directory.');
    $resolved = realpath($configured);
    retro_test_assert(is_string($resolved) && is_dir($resolved), 'SBLOG_CORE_ROOT is not a source directory.');
    foreach (['index.php', 'router.php', 'favicon.png', 'logo.png', 'assets'] as $source) {
        retro_test_assert(is_readable($resolved . '/' . $source), 'Missing or unreadable core source: ' . $source);
    }
    return $root = $resolved;
}

function retro_test_sync(string $directory): void
{
    retro_test_assert(!is_link($directory) && is_file($directory . '/.retro-home-test-fixture') && !is_link($directory . '/.retro-home-test-fixture')
        && file_get_contents($directory . '/.retro-home-test-fixture') === 'Isolated Retro Home HTTP fixture.', 'Refusing to sync an unmarked directory.');
    foreach (['index.php', 'router.php', 'favicon.png', 'logo.png', 'assets'] as $source) {
        retro_test_copy(retro_test_core_root() . '/' . $source, $directory . '/' . $source);
    }
    retro_test_copy(dirname(__DIR__) . '/themes/retro-home', $directory . '/themes/retro-home');
}

function retro_test_defaults(): array
{
    $schema = json_decode((string)file_get_contents(dirname(__DIR__) . '/themes/retro-home/settings.json'), true, 16, JSON_THROW_ON_ERROR);
    $defaults = [];
    foreach ($schema['sections'] as $section) {
        foreach ($section['fields'] as $field) { $defaults[$field['key']] = (string)($field['default'] ?? ''); }
    }
    return $defaults;
}

function retro_test_store(PDO $pdo, string $directory, string $key, string $value): void
{
    $pdo->prepare('INSERT OR REPLACE INTO settings(name,value) VALUES(?,?)')->execute([$key, $value]);
    if (is_file($directory . '/cache/settings.php')) { unlink($directory . '/cache/settings.php'); }
}

function retro_test_options(PDO $pdo, string $directory, array $values): void
{
    retro_test_store($pdo, $directory, 'theme_settings_retro-home', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function retro_test_load_schema(): void
{
    $needed = array_fill_keys(['table_columns', 'content_contains_image', 'import_legacy_local_media', 'ensure_comment_columns', 'ensure_schema', 'migrate_legacy_schema', 'default_settings'], true);
    $tokens = token_get_all((string)file_get_contents(retro_test_core_root() . '/index.php'));
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        $name = is_array($tokens[$j] ?? null) ? $tokens[$j][1] : '';
        if (!isset($needed[$name])) { continue; }
        $code = '';
        $depth = 0;
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
            elseif ($token === '}' && --$depth === 0) { break; }
        }
        eval($code);
        unset($needed[$name]);
    }
    retro_test_assert($needed === [], 'Missing core schema helpers.');
}

function retro_test_prepare(string $directory): PDO
{
    retro_test_assert($directory !== '' && !file_exists($directory), 'Provide a new temporary fixture directory.');
    mkdir($directory, 0700, true);
    file_put_contents($directory . '/.retro-home-test-fixture', 'Isolated Retro Home HTTP fixture.');
    foreach (['data', 'cache', 'uploads', 'themes', 'plugins', 'sessions'] as $child) { mkdir($directory . '/' . $child, 0700); }
    retro_test_sync($directory);
    define('DATABASE_MAINTENANCE_VERSION', 1);
    define('UPLOAD_DIR', $directory . '/uploads');
    retro_test_load_schema();
    $database = 'blog-' . bin2hex(random_bytes(8)) . '.sqlite';
    file_put_contents($directory . '/data/config.php', '<?php return ' . var_export(['db_file' => $database], true) . ';');
    file_put_contents($directory . '/data/install.lock', 'fixture');
    $pdo = new PDO('sqlite:' . $directory . '/data/' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    ensure_schema($pdo);
    $now = time();
    $options = array_replace(retro_test_defaults(), [
        'profile_name' => '阿禾 / Ahe', 'profile_location' => '南方，一座常下雨的城市',
        'profile_doing' => '写字、做点小网页、收集旧磁带', 'profile_likes' => '长信、纸质书、深夜的电台、慢一点的互联网',
        'contact_email' => 'hello@example.test', 'since_date' => '2026-08-12',
    ]);
    $settings = array_replace(default_settings(), [
        'site_name' => '阿禾的小屋', 'site_tagline' => 'A QUIET CORNER ON THE WEB',
        'site_description' => '一些慢慢写下来的文字，和一台总也关不掉的旧电脑。',
        'active_theme' => 'retro-home', 'active_plugins' => '["retro-fixture"]',
        'pretty_url' => '1', 'posts_per_page' => '4', 'comments_notify' => '0',
        'theme_settings_retro-home' => json_encode($options, JSON_UNESCAPED_UNICODE),
    ]);
    foreach ($settings as $key => $value) { retro_test_store($pdo, $directory, $key, $value); }
    $pdo->prepare('INSERT INTO users(username,password_hash,nickname,email,created_at) VALUES(?,?,?,?,?)')->execute(['retro-admin', password_hash('Retro-fixture-2026', PASSWORD_DEFAULT), '阿禾', 'writer@example.test', $now]);
    $pdo->prepare('INSERT INTO categories(name,slug,description,created_at,updated_at) VALUES(?,?,?,?,?)')->execute(['日常随笔', 'life', '写给未来的自己。', $now, $now]);
    $body = "## 给小站添一点旧时光\n\n总觉得，个人主页应该有一点自己的脾气。它可以是一张摆在窗边的桌子，也可以是一本慢慢写满的笔记。\n\n> 网页应该是安静的，像一张摆在窗边的桌子。\n\n### 留下一些可以记住的东西\n\n- 写下今天读到的一句话。\n- 给朋友留一封长信。\n- 偶尔停下来，听一会儿雨。\n\n```php\n<?php\necho '你好，陌生人。';\n```\n\n| 日子 | 小事 |\n| --- | --- |\n| 周末 | 整理旧磁带 |\n| 雨天 | 写一点文字 |\n\n![像素软盘](/logo.png)\n\n慢一点也没关系。希望你在这里找到一小会儿安静。";
    $rows = [
        ['little-site', '给小站换了一个复古的模样', $body, '', '', 'post', 'published'],
        ['link-list', '整理了一份老式个人主页的链接清单', "## 常去的地方\n\n喜欢那些有人认真照料的小网站。\n\n- [Internet Archive](https://archive.org)\n- [Neocities](https://neocities.org)", '', '', 'post', 'published'],
        ['rainy-day', '秋天到了，写一篇关于雨天的短文', "## 窗外有雨\n\n雨声把城市的边缘变得柔和。我泡了一杯茶，给很久没有联系的朋友写信。", '', '', 'post', 'published'],
        ['first-day', '本站开张，先摆上一张桌子和一盏灯', "## 欢迎光临\n\n这里没有太多东西，只有一些普通日子里的记录。随便坐，慢慢看。", '', '', 'post', 'published'],
        ['protected-note', '上了锁的旧日记', 'PASSWORD_SECRET_SENTINEL ![](/password-secret-image.png)', 'PASSWORD_EXCERPT_SENTINEL', password_hash('fixture-secret', PASSWORD_DEFAULT), 'post', 'published'],
        ['reply-note', '给愿意留言的朋友的一封信', "## 一封长信\n\n这是任何访客都能阅读的开头。\n\n[reply]\nREPLY_SECRET_SENTINEL ![](/reply-secret-image.png)\n[/reply]\n\n谢谢你来过。", 'REPLY_EXCERPT_SENTINEL', '', 'post', 'published'],
        ['escaping', '记住 <边界> 与 & 符号', "## 原样记录\n\n文字应当保持它本来的样子。", '', '', 'post', 'published'],
        ['older', '深夜电台与一盒旧磁带', '## 慢慢听\n\n有些歌，适合留到深夜。', '', '', 'post', 'published'],
        ['future', 'FUTURE_TITLE_SENTINEL', 'FUTURE_BODY_SENTINEL', '', '', 'post', 'published'],
        ['draft', 'DRAFT_TITLE_SENTINEL', 'DRAFT_BODY_SENTINEL', '', '', 'post', 'draft'],
        ['about', '关于小屋', "## 你好，我是阿禾\n\n喜欢长信、纸质书和慢一点的互联网。\n\n## 留言簿\n\n你也有主页？在下面留个地址吧。", '', '', 'page', 'published'],
    ];
    $insert = $pdo->prepare('INSERT INTO posts(author_id,category_id,slug,title,content,excerpt,content_password_hash,kind,tags,views,allow_comments,status,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $i => [$slug, $title, $content, $excerpt, $password, $kind, $status]) {
        $published = $slug === 'future' ? $now + 86400 : $now - ($i + 1) * 86400 * 3;
        $insert->execute([1, $kind === 'post' ? 1 : null, $slug, $title, $content, $excerpt, $password, $kind, $kind === 'post' ? '["随笔"]' : '[]', $i < 8 ? 160 + $i : 99999, 1, $status, $published, $published, $published]);
    }
    $pdo->prepare('INSERT INTO tag_meta(label,slug,updated_at) VALUES(?,?,?)')->execute(['随笔', 'notes', $now]);
    $pdo->prepare('INSERT INTO comments(post_id,author_name,author_email,content,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([1, '路过的朋友', 'reader@example.test', '喜欢这样安静的小站，像小时候收藏的网页。', 'approved', $now - 1800, $now - 1800]);
    $insertLink = $pdo->prepare('INSERT INTO links(name,url,description,created_at,updated_at) VALUES(?,?,?,?,?)');
    foreach ([['Internet Archive', 'https://archive.org', '把快要消失的网页收好'], ['Neocities', 'https://neocities.org', '还有很多人亲手写着自己的主页'], ['Wikipedia', 'https://www.wikipedia.org', '一逛就是一下午']] as [$name, $url, $description]) {
        $insertLink->execute([$name, $url, $description, $now, $now]);
    }
    mkdir($directory . '/plugins/retro-fixture');
    file_put_contents($directory . '/plugins/retro-fixture/plugin.json', '{"name":"Fixture hooks","version":"1.0.0"}');
    file_put_contents($directory . '/plugins/retro-fixture/plugin.php', <<<'PHP'
<?php
add_plugin_filter('comment_identity_html', static fn(string $html): string => $html . '<span data-fixture-comment-identity>邻居</span>');
add_plugin_filter('comment_meta_html', static fn(string $html): string => $html . '<span data-fixture-comment-meta>常来坐坐</span>');
foreach (['head', 'body_open', 'header_before', 'header_after', 'content_before', 'content_after', 'footer_before', 'footer_after', 'body_close'] as $hook) {
    add_theme_action($hook, static fn(): string => '<!-- fixture-hook-' . $hook . ' -->');
}
PHP);
    return $pdo;
}

function retro_test_remove(string $directory): void
{
    retro_test_assert(!is_link($directory) && is_file($directory . '/.retro-home-test-fixture') && !is_link($directory . '/.retro-home-test-fixture')
        && file_get_contents($directory . '/.retro-home-test-fixture') === 'Isolated Retro Home HTTP fixture.', 'Refusing to remove an unmarked directory.');
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($directory);
}

function retro_test_request(string $base, string $path, ?array $post = null, ?string $cookies = null): array
{
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) { curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]); }
    if ($cookies !== null) { curl_setopt_array($curl, [CURLOPT_COOKIEJAR => $cookies, CURLOPT_COOKIEFILE => $cookies]); }
    $response = curl_exec($curl);
    retro_test_assert(is_string($response), 'HTTP request failed: ' . curl_error($curl));
    $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
    unset($curl);
    retro_test_assert(!preg_match('/(?:Fatal error|Warning|Deprecated|Parse error):/', $result['body']), 'PHP diagnostics leaked: ' . $path);
    return $result;
}

function retro_test_xpath(string $html): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}

function retro_test_titles(string $html): array
{
    $titles = [];
    foreach (retro_test_xpath($html)->query('//ul[contains(concat(" ", normalize-space(@class), " "), " log ")]/li/a') as $node) { $titles[] = trim($node->textContent); }
    return $titles;
}

function retro_test_public(array $response, int $status = 200): void
{
    retro_test_assert($response['status'] === $status, 'Expected HTTP ' . $status . ', received ' . $response['status']);
    retro_test_assert(str_contains($response['body'], 'themes/retro-home/style.css'), 'Retro Home stylesheet was not loaded.');
    retro_test_assert(str_contains($response['body'], 'class="masthead"'), 'Retro Home layout fell back to the core layout.');
    foreach (['PASSWORD_SECRET_SENTINEL', 'PASSWORD_EXCERPT_SENTINEL', 'password-secret-image.png', 'REPLY_SECRET_SENTINEL', 'REPLY_EXCERPT_SENTINEL', 'reply-secret-image.png', 'DRAFT_TITLE_SENTINEL', 'DRAFT_BODY_SENTINEL', 'FUTURE_TITLE_SENTINEL', 'FUTURE_BODY_SENTINEL'] as $secret) {
        retro_test_assert(!str_contains($response['body'], $secret), 'Private content leaked: ' . $secret);
    }
}

function retro_test_csrf(string $html): string
{
    retro_test_assert(preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match) === 1, 'Missing CSRF token.');
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

retro_test_core_root();
$mode = $argv[1] ?? '';
if ($mode === '--sync') {
    retro_test_sync((string)($argv[2] ?? ''));
    fwrite(STDOUT, "Fixture sources synchronized.\n");
    exit;
}
$fixture = $mode === '--prepare' ? (string)($argv[2] ?? '') : sys_get_temp_dir() . '/sblog-retro-home-tests-' . bin2hex(random_bytes(6));
$pdo = retro_test_prepare($fixture);
if ($mode === '--prepare') {
    fwrite(STDOUT, json_encode(['directory' => $fixture, 'command' => 'php -d session.save_path=' . escapeshellarg($fixture . '/sessions') . ' -S 127.0.0.1:8766 -t ' . escapeshellarg($fixture) . ' ' . escapeshellarg($fixture . '/router.php'), 'username' => 'retro-admin', 'password' => 'Retro-fixture-2026'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    exit;
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
retro_test_assert(is_resource($socket), 'Could not allocate test port: ' . $error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$log = $fixture . '/server.log';
$server = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'session.save_path=' . $fixture . '/sessions', '-S', $address, '-t', $fixture, $fixture . '/router.php'], [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, $fixture);
retro_test_assert(is_resource($server), 'Could not start test server.');
$failures = 0;
$checks = 0;
try {
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if (is_resource($connection)) { fclose($connection); $ready = true; break; }
        usleep(50000);
    }
    retro_test_assert($ready, 'Test server did not become ready.');
    $tests = [];
    $tests['home uses real posts, stable pagination, and real links'] = static function () use ($base): void {
        $first = retro_test_request($base, '/');
        $second = retro_test_request($base, '/?p=2');
        retro_test_public($first);
        retro_test_public($second);
        retro_test_assert(retro_test_titles($first['body']) === ['给小站换了一个复古的模样', '整理了一份老式个人主页的链接清单', '秋天到了，写一篇关于雨天的短文', '本站开张，先摆上一张桌子和一盏灯'], 'First-page log does not match published order/page size.');
        retro_test_assert(retro_test_titles($second['body']) === ['上了锁的旧日记', '给愿意留言的朋友的一封信', '记住 <边界> 与 & 符号', '深夜电台与一盒旧磁带'], 'Second-page log omitted or duplicated posts.');
        foreach (['https://archive.org', 'https://neocities.org', 'https://www.wikipedia.org'] as $url) { retro_test_assert(str_contains($first['body'], $url), 'Home omitted stored link: ' . $url); }
        retro_test_assert(str_contains($first['body'], '/page/2') && str_contains($second['body'], 'rel="prev"'), 'Pagination links missing.');
        retro_test_assert(str_contains($second['body'], '&lt;边界&gt;'), 'Post title is not escaped.');
    };
    $tests['reading counter excludes drafts, future posts, and standalone pages'] = static function () use ($base, $pdo): void {
        $html = retro_test_request($base, '/')['body'];
        $counters = retro_test_xpath($html)->query('//*[contains(concat(" ",normalize-space(@class)," ")," counter ")]');
        retro_test_assert($counters->length === 1, 'Missing reading counter.');
        $expected = (int)$pdo->query("SELECT SUM(views) FROM posts WHERE kind='post' AND status='published' AND published_at <= " . time())->fetchColumn();
        retro_test_assert((int)preg_replace('/\s+/', '', $counters->item(0)->textContent) === $expected, 'Counter includes private content or fabricated visits.');
        retro_test_assert(str_contains($html, '文章阅读'), 'Counter must accurately describe article reads.');
    };
    $tests['all public routes retain layout, status, and private content boundaries'] = static function () use ($base): void {
        foreach (['/archive/little-site', '/archive/protected-note', '/archive/reply-note', '/category/life', '/tag/notes', '/archives', '/tags', '/categories', '/links', '/about'] as $path) { retro_test_public(retro_test_request($base, $path)); }
        foreach (['/archive/missing', '/category/missing', '/missing-page', '/?p=999'] as $path) { retro_test_public(retro_test_request($base, $path), 404); }
    };
    $tests['details keep Markdown, comment plugins, and both content gates'] = static function () use ($base): void {
        $html = retro_test_request($base, '/archive/little-site')['body'];
        foreach (['给小站添一点旧时光', '<pre', '<table', '路过的朋友', 'data-fixture-comment-identity', 'data-fixture-comment-meta', 'name="csrf_token"'] as $expected) { retro_test_assert(str_contains($html, $expected), 'Detail omitted ' . $expected); }
        foreach (['head', 'body_open', 'header_before', 'header_after', 'content_before', 'content_after', 'footer_before', 'footer_after', 'body_close'] as $hook) { retro_test_assert(str_contains($html, 'fixture-hook-' . $hook), 'Missing layout hook: ' . $hook); }
        $locked = retro_test_request($base, '/archive/protected-note');
        retro_test_assert(str_contains($locked['body'], 'content-gate--password'), 'Password form was lost.');
        retro_test_assert(str_contains(strtolower($locked['headers']), 'private, no-store'), 'Password page lost private cache headers.');
        retro_test_assert(str_contains(retro_test_request($base, '/archive/reply-note')['body'], 'content-gate--reply'), 'Reply gate was lost.');
    };
    $tests['native settings escape text, reject unsafe email output, and hide optional sections'] = static function () use ($base, $fixture, $pdo): void {
        $initial = json_decode((string)$pdo->query("SELECT value FROM settings WHERE name='theme_settings_retro-home'")->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
        try {
            $values = array_replace($initial, ['welcome_title' => 'NATIVE <script>alert(1)</script>', 'profile_name' => 'PROFILE <&>', 'contact_email' => 'bad@example.test" onclick="alert(1)']);
            retro_test_options($pdo, $fixture, $values);
            $html = retro_test_request($base, '/')['body'];
            retro_test_assert(str_contains($html, 'NATIVE &lt;script&gt;') && str_contains($html, 'PROFILE &lt;&amp;&gt;'), 'Native text is missing or unescaped.');
            retro_test_assert(!str_contains($html, '<script>alert(1)</script>') && !str_contains($html, 'mailto:bad@'), 'Unsafe configured text became active markup.');
            foreach (['show_profile', 'show_links', 'show_clock', 'show_counter', 'show_badges'] as $key) { $values[$key] = '0'; }
            retro_test_options($pdo, $fixture, $values);
            $xpath = retro_test_xpath(retro_test_request($base, '/')['body']);
            retro_test_assert($xpath->query('//table[contains(@class,"profile-table")] | //*[@id="retro-clock"] | //*[contains(concat(" ",normalize-space(@class)," ")," counter ")] | //*[contains(concat(" ",normalize-space(@class)," ")," badges ")]')->length === 0, 'A disabled section is still visible.');
            retro_test_assert($xpath->query('//main//a[@href="https://archive.org"]')->length === 0, 'Disabled home links are still visible.');
        } finally { retro_test_options($pdo, $fixture, $initial); }
    };
    $tests['native settings are available through authenticated core forms'] = static function () use ($base, $fixture, $pdo): void {
        $path = '/index.php?a=admin_theme_settings&theme=retro-home';
        retro_test_assert(retro_test_request($base, $path)['status'] === 302, 'Anonymous settings access was allowed.');
        $cookies = $fixture . '/admin-cookies.txt';
        $login = retro_test_request($base, '/login', null, $cookies);
        retro_test_assert(retro_test_request($base, '/login', ['csrf_token' => retro_test_csrf($login['body']), 'username' => 'retro-admin', 'password' => 'Retro-fixture-2026'], $cookies)['status'] === 302, 'Fixture login failed.');
        $form = retro_test_request($base, $path, null, $cookies);
        retro_test_assert($form['status'] === 200, 'Settings form did not load.');
        foreach (array_keys(retro_test_defaults()) as $key) { retro_test_assert(str_contains($form['body'], 'name="settings[' . $key . ']"'), 'Core form omitted setting: ' . $key); }
        $initial = (string)$pdo->query("SELECT value FROM settings WHERE name='theme_settings_retro-home'")->fetchColumn();
        try {
            $values = array_replace(retro_test_defaults(), ['welcome_title' => '保存设置验证']);
            retro_test_assert(retro_test_request($base, $path, ['settings' => $values], $cookies)['status'] === 422, 'Missing CSRF accepted.');
            retro_test_assert(retro_test_request($base, $path, ['csrf_token' => retro_test_csrf($form['body']), 'settings' => $values], $cookies)['status'] === 303, 'Valid setting save failed.');
            retro_test_assert(str_contains(retro_test_request($base, '/')['body'], '保存设置验证'), 'Saved setting did not reach public output.');
            $saved = (string)$pdo->query("SELECT value FROM settings WHERE name='theme_settings_retro-home'")->fetchColumn();
            $values['since_date'] = '2026-02-31';
            retro_test_assert(retro_test_request($base, $path, ['csrf_token' => retro_test_csrf($form['body']), 'settings' => $values], $cookies)['status'] === 422, 'Invalid date accepted.');
            retro_test_assert($saved === (string)$pdo->query("SELECT value FROM settings WHERE name='theme_settings_retro-home'")->fetchColumn(), 'Rejected save changed settings.');
        } finally { retro_test_store($pdo, $fixture, 'theme_settings_retro-home', $initial); }
    };
    $tests['theme assets load while PHP and settings sources stay private'] = static function () use ($base): void {
        foreach (['style.css', 'assets/theme.js'] as $path) { retro_test_assert(retro_test_request($base, '/themes/retro-home/' . $path)['status'] === 200, 'Missing asset: ' . $path); }
        foreach (['settings.json', 'functions.php', 'layout.php', 'theme.json'] as $path) { retro_test_assert(retro_test_request($base, '/themes/retro-home/' . $path)['status'] === 404, 'Private source was exposed: ' . $path); }
    };
    $tests['administrator previews retain internal navigation without affecting external links'] = static function () use ($base, $fixture, $pdo): void {
        $cookies = $fixture . '/preview-cookies.txt';
        $login = retro_test_request($base, '/login', null, $cookies);
        retro_test_assert(retro_test_request($base, '/login', ['csrf_token' => retro_test_csrf($login['body']), 'username' => 'retro-admin', 'password' => 'Retro-fixture-2026'], $cookies)['status'] === 302, 'Preview fixture login failed.');
        retro_test_store($pdo, $fixture, 'active_theme', 'default');
        try {
            $anonymous = retro_test_request($base, '/?theme_preview=retro-home');
            retro_test_assert(!str_contains($anonymous['body'], 'themes/retro-home/style.css'), 'Visitors can enable an administrator-only preview.');
            $preview = retro_test_request($base, '/?theme_preview=retro-home', null, $cookies);
            retro_test_public($preview);
            $xpath = retro_test_xpath($preview['body']);
            foreach ($xpath->query('//ul[contains(@class,"log")]/li/a') as $link) { retro_test_assert(str_contains($link->getAttribute('href'), 'theme_preview=retro-home'), 'Article link loses theme preview.'); }
            retro_test_assert($xpath->query('//a[@href="https://archive.org"]')->length > 0, 'External link changed during preview.');
            $articleLink = $xpath->query('//ul[contains(@class,"log")]/li/a')->item(0)->getAttribute('href');
            retro_test_public(retro_test_request($base, $articleLink, null, $cookies));
            foreach ($xpath->query('//a[contains(@href,"#")]') as $link) {
                $url = $link->getAttribute('href');
                if (!str_contains($url, 'theme_preview=')) { continue; }
                retro_test_assert(strpos($url, 'theme_preview=') < strpos($url, '#'), 'Preview query was appended after the fragment.');
            }
        } finally { retro_test_store($pdo, $fixture, 'active_theme', 'retro-home'); }
    };
    $tests['an empty blog renders a useful home and zero reading count'] = static function () use ($base, $pdo): void {
        $pdo->exec("UPDATE posts SET status='fixture-hidden' WHERE status='published'");
        try {
            $response = retro_test_request($base, '/');
            retro_test_public($response);
            retro_test_assert(retro_test_titles($response['body']) === [] && str_contains($response['body'], '还没有已发布的文章'), 'Empty home does not explain the absence of posts.');
            $counter = retro_test_xpath($response['body'])->query('//*[contains(concat(" ",normalize-space(@class)," ")," counter ")]')->item(0);
            retro_test_assert($counter !== null && (int)preg_replace('/\s+/', '', $counter->textContent) === 0, 'Empty blog reports fabricated reads.');
        } finally { $pdo->exec("UPDATE posts SET status='published' WHERE status='fixture-hidden'"); }
    };
    foreach ($tests as $name => $test) {
        $checks++;
        try { $test(); fwrite(STDOUT, 'PASS ' . $name . "\n"); }
        catch (Throwable $exception) { $failures++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . "\n"); }
    }
    $serverLog = (string)file_get_contents($log);
    if (preg_match('/(?:PHP (?:Fatal error|Warning|Deprecated|Parse error)|Theme (?:layout|action|filter) failed|Plugin .*failed)/', $serverLog)) {
        $failures++;
        fwrite(STDERR, "FAIL runtime diagnostics:\n" . $serverLog);
    }
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    proc_terminate($server);
    if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }
    proc_close($server);
    if ($mode === '--keep' || $failures > 0) { fwrite(STDOUT, 'Fixture retained: ' . $fixture . "\n"); }
    else { retro_test_remove($fixture); }
}
fwrite(STDOUT, sprintf("%d Retro Home HTTP test groups, %d failures. Installed blog data was not loaded or modified.\n", $checks, $failures));
exit($failures === 0 ? 0 : 1);
