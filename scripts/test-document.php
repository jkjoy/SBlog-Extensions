<?php

declare(strict_types=1);

// SBLOG_CORE_ROOT=/path/to/Simple-PHP-Blog php scripts/test-document.php [--keep]
// SBLOG_CORE_ROOT=/path/to/Simple-PHP-Blog php scripts/test-document.php --prepare /tmp/sblog-document-preview
// SBLOG_CORE_ROOT=/path/to/Simple-PHP-Blog php scripts/test-document.php --sync /tmp/sblog-document-preview
// SBLOG_CORE_ROOT=/path/to/Simple-PHP-Blog SBLOG_DOCUMENT_CORE_FILE=/tmp/released-index.php php scripts/test-document.php
// Uses a complete, isolated copy of the real HTTP runtime. Installed data is never loaded.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

function document_test_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function document_test_copy(string $from, string $to): void
{
    if (is_link($from)) { throw new RuntimeException('Fixture sources may not be symlinks: ' . $from); }
    if (is_dir($from)) {
        if (!is_dir($to)) { mkdir($to, 0700, true); }
        foreach (new FilesystemIterator($from, FilesystemIterator::SKIP_DOTS) as $entry) {
            document_test_copy($entry->getPathname(), $to . '/' . $entry->getFilename());
        }
    } elseif (is_file($from)) {
        if (!is_dir(dirname($to))) { mkdir(dirname($to), 0700, true); }
        document_test_assert(copy($from, $to), 'Could not copy fixture source: ' . $from);
    }
}

function document_test_core_root(): string
{
    static $root;
    if (is_string($root)) { return $root; }
    $configured = getenv('SBLOG_CORE_ROOT');
    document_test_assert(is_string($configured) && trim($configured) !== '', 'Set SBLOG_CORE_ROOT to a readable Simple PHP Blog source directory.');
    $resolved = realpath($configured);
    document_test_assert(is_string($resolved) && is_dir($resolved), 'SBLOG_CORE_ROOT is not a source directory.');
    foreach (['index.php', 'router.php', 'favicon.png', 'logo.png', 'assets'] as $source) {
        document_test_assert(is_readable($resolved . '/' . $source), 'Missing or unreadable core source: ' . $source);
    }
    return $root = $resolved;
}

function document_test_core_file(): string
{
    $file = getenv('SBLOG_DOCUMENT_CORE_FILE') ?: document_test_core_root() . '/index.php';
    document_test_assert(is_file($file) && is_readable($file), 'Core source is unavailable: ' . $file);
    return $file;
}

function document_test_sync(string $directory): void
{
    document_test_assert(!is_link($directory) && is_file($directory . '/.document-test-fixture') && !is_link($directory . '/.document-test-fixture')
        && file_get_contents($directory . '/.document-test-fixture') === 'Isolated Document theme test fixture.', 'Refusing to sync a directory without the fixture marker.');
    // Remove obsolete files only inside an explicitly marked test fixture.
    $oldPlugin = $directory . '/plugins/document-settings';
    if (is_dir($oldPlugin) && !is_link($oldPlugin)) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($oldPlugin, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($oldPlugin);
    }
    $oldSchema = $directory . '/themes/document/settings.php';
    if (is_file($oldSchema)) { unlink($oldSchema); }
    document_test_copy(document_test_core_file(), $directory . '/index.php');
    foreach (['router.php', 'favicon.png', 'logo.png', 'assets'] as $source) {
        document_test_copy(document_test_core_root() . '/' . $source, $directory . '/' . $source);
    }
    document_test_copy(dirname(__DIR__) . '/themes/document', $directory . '/themes/document');
}

function document_test_settings_defaults(): array
{
    $schema = json_decode((string)file_get_contents(dirname(__DIR__) . '/themes/document/settings.json'), true, 16, JSON_THROW_ON_ERROR);
    $values = [];
    foreach ($schema['sections'] as $section) {
        foreach ($section['fields'] as $field) { $values[$field['key']] = (string)($field['default'] ?? ''); }
    }
    return $values;
}

function document_test_store_settings(PDO $pdo, string $directory, ?array $values): void
{
    if ($values === null) {
        $pdo->exec("DELETE FROM settings WHERE name='theme_settings_document'");
    } else {
        $pdo->prepare('INSERT OR REPLACE INTO settings(name,value) VALUES(?,?)')->execute(['theme_settings_document', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }
    if (is_file($directory . '/cache/settings.php')) { unlink($directory . '/cache/settings.php'); }
}

function document_test_schema_functions(): void
{
    $needed = array_fill_keys(['table_columns', 'content_contains_image', 'import_legacy_local_media', 'ensure_comment_columns', 'ensure_schema', 'migrate_legacy_schema', 'default_settings'], true);
    $tokens = token_get_all((string)file_get_contents(document_test_core_file()));
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
    document_test_assert($needed === [], 'Could not locate core schema functions.');
}

function document_test_prepare(string $directory): PDO
{
    document_test_assert(!file_exists($directory), 'Fixture directory already exists: ' . $directory);
    mkdir($directory, 0700, true);
    file_put_contents($directory . '/.document-test-fixture', 'Isolated Document theme test fixture.');
    foreach (['data', 'cache', 'uploads', 'themes', 'plugins', 'sessions'] as $child) { mkdir($directory . '/' . $child, 0700); }
    document_test_sync($directory);
    define('DATABASE_MAINTENANCE_VERSION', 1);
    define('UPLOAD_DIR', $directory . '/uploads');
    document_test_schema_functions();
    $databaseName = 'blog-' . bin2hex(random_bytes(8)) . '.sqlite';
    file_put_contents($directory . '/data/config.php', '<?php return ' . var_export(['db_file' => $databaseName], true) . ';');
    file_put_contents($directory . '/data/install.lock', 'fixture');
    $pdo = new PDO('sqlite:' . $directory . '/data/' . $databaseName, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    ensure_schema($pdo);
    $now = time();
    $settings = array_replace(default_settings(), [
        'site_name' => '友人笔记', 'site_tagline' => '温故而知新，记录每一次探索。',
        'site_description' => '关于编程、设计与生活的学习笔记。',
        'active_theme' => 'document', 'active_plugins' => '["document-fixture"]',
        'pretty_url' => '1', 'comments_notify' => '0', 'posts_per_page' => '6',
        'theme_settings_document' => json_encode(array_replace(document_test_settings_defaults(), ['author_name' => '友人', 'author_description' => '记录、分享，做更好的自己。', 'announcement' => '欢迎来到我的笔记本，一起记录值得记住的事。']), JSON_UNESCAPED_UNICODE),
        'document_options' => json_encode(['navbar_title' => 'LEGACY_SHOULD_NOT_LOAD', 'primary_color' => '#ff00ff', 'sidebar_left' => false, 'sidebar_right' => false], JSON_UNESCAPED_UNICODE),
    ]);
    $insertSetting = $pdo->prepare('INSERT OR REPLACE INTO settings(name,value) VALUES(?,?)');
    foreach ($settings as $name => $value) { $insertSetting->execute([$name, $value]); }
    $pdo->prepare('INSERT INTO users(username,password_hash,nickname,email,created_at) VALUES(?,?,?,?,?)')->execute(['document-admin', password_hash('Document-fixture-2026', PASSWORD_DEFAULT), '友人', 'writer@example.test', $now]);
    $pdo->prepare('INSERT INTO categories(name,slug,description,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?)')->execute(['编程笔记', 'coding', '在实践中理解技术。', 1, $now, $now]);
    $coding = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO categories(name,slug,description,sort_order,created_at,updated_at) VALUES(?,?,?,?,?,?)')->execute(['日常随笔', 'life', '记录生活里的小小发现。', 2, $now, $now]);
    $life = (int)$pdo->lastInsertId();
    $body = "## 从一个简单的问题开始\n\n写代码时，我们常常需要回到最基本的概念。把问题拆成可以验证的小步骤，答案也会慢慢清晰。\n\n### 数据与边界\n\n一份好的笔记应该包含背景、方法，以及最后得到的结论。下次遇到相似的问题时，就能少走一些弯路。\n\n```php\n<?php\nfunction remember(string \$thought): string {\n    return '记录：' . \$thought;\n}\necho remember('今天学到的新东西');\n```\n\n## 在实践中学习\n\n> 真正理解一件事，往往从亲手尝试开始。\n\n- 保持好奇，提出具体的问题。\n- 留下可复现的示例。\n- 把结论写给未来的自己。\n\n![山间的光](/themes/document/assets/images/bg.jpg)\n\n### 一点总结\n\n不必急于一次完成所有事情。持续记录与回顾，本身就是成长的一部分。\n\n" . str_repeat("每天留出一点时间回顾自己的实践，把模糊的想法写成清楚的文字。记录让经验得以保存，分享让知识产生更多价值。\n\n", 12);
    $rows = [
        ['php-notes', 'PHP 与 SQLite：从小工具开始的实践', $body, $coding, ['PHP', '学习笔记'], '', '', 'post', 'published'],
        ['document-design', '为阅读而设计：一个清晰的知识库', "## 把结构留给内容\n\n阅读体验来自恰当的留白、清晰的层级和方便的导航。\n\n" . $body, $coding, ['设计', '学习笔记'], '', '', 'post', 'published'],
        ['slow-life', '慢一点，把生活里的光留住', "## 城市之外\n\n周末去山里走走，暂时放下屏幕，让思绪跟着脚步慢下来。\n\n![周末](/themes/document/assets/images/bg.jpg)\n\n## 回到日常\n\n平凡的日子里也藏着值得记录的瞬间。", $life, ['随笔'], '', '', 'post', 'published'],
        ['protected-note', '密码保护的学习资料', "## PASSWORD_SECRET_SENTINEL\n\n![秘密](/password-secret-thumbnail.png)\n\nPASSWORD_SECRET_SENTINEL", $coding, ['学习笔记'], 'PASSWORD_EXCERPT_SENTINEL', password_hash('fixture-secret', PASSWORD_DEFAULT), 'post', 'published'],
        ['reply-note', '互动笔记：完成练习后再看答案', "## 练习说明\n\n这是任何访客都可以阅读的说明。\n\n[reply]\n## REPLY_SECRET_SENTINEL\n\n![答案](/reply-secret-thumbnail.png)\n\nREPLY_SECRET_SENTINEL\n[/reply]\n\n## 继续交流\n\n欢迎留下你的想法。", $coding, ['PHP'], 'REPLY_EXCERPT_SENTINEL', '', 'post', 'published'],
        ['reading-list', '最近读到的几篇好文章', "## 阅读与摘记\n\n把新知识和自己的经验连接起来，记录那些让人停下来思考的句子。\n\n## 下次再读\n\n随着实践增加，同一篇文章也会带来新的理解。", $life, ['随笔'], '', '', 'post', 'published'],
        ['escaping', '内容安全：<边界> 与转义', '## 输入与输出\n\n始终记住数据所处的上下文。', $coding, ['PHP'], '', '', 'post', 'published'],
        ['older-note', '给未来的自己：坚持写笔记', '## 为什么记录\n\n为了下次遇到问题时，可以找到曾经的答案。', $life, ['随笔'], '', '', 'post', 'published'],
        ['future-note', 'FUTURE_TITLE_SENTINEL', 'FUTURE_BODY_SENTINEL', $coding, ['PHP'], '', '', 'post', 'published'],
        ['draft-note', 'DRAFT_TITLE_SENTINEL', 'DRAFT_BODY_SENTINEL', $coding, ['PHP'], '', '', 'post', 'draft'],
        ['about', '关于', "## 你好，我是友人\n\n这是我的学习笔记与生活记录。喜欢编程、阅读，也喜欢把复杂的事情解释清楚。\n\n## 联系我\n\n欢迎通过评论交流。", null, [], '', '', 'page', 'published'],
    ];
    $insertPost = $pdo->prepare('INSERT INTO posts(author_id,category_id,slug,title,excerpt,content,content_password_hash,kind,tags,views,is_pinned,allow_comments,status,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $i => [$slug, $title, $content, $category, $tags, $excerpt, $password, $kind, $status]) {
        $published = $slug === 'future-note' ? $now + 86400 : $now - ($i + 1) * 86400;
        $insertPost->execute([1, $category, $slug, $title, $excerpt, $content, $password, $kind, json_encode($tags, JSON_UNESCAPED_UNICODE), 120 - $i * 7, 0, 1, $status, $published, $published, $published]);
    }
    $insertTag = $pdo->prepare('INSERT INTO tag_meta(label,slug,updated_at) VALUES(?,?,?)');
    foreach (['PHP' => 'php', '学习笔记' => 'notes', '设计' => 'design', '随笔' => 'life'] as $label => $slug) { $insertTag->execute([$label, $slug, $now]); }
    $pdo->prepare('INSERT INTO comments(post_id,author_name,author_email,content,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([1, '认真读者', 'reader@example.test', '清晰的笔记总能帮助我们在下次遇到问题时更快找到答案，感谢分享！', 'approved', $now - 1800, $now - 1800]);
    $pdo->prepare('INSERT INTO comments(post_id,user_id,parent_id,reply_to_name,author_name,author_email,content,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([1, 1, 1, '认真读者', '友人', 'writer@example.test', '谢谢，也欢迎分享你的实践经验。', 'approved', $now - 900, $now - 900]);
    $pdo->prepare('INSERT INTO links(name,url,description,created_at,updated_at) VALUES(?,?,?,?,?)')->execute(['Document 原主题', 'https://github.com/friend-nicen/theme-document', '友人a丶的文档风格博客主题。', $now, $now]);
    mkdir($directory . '/plugins/document-fixture');
    file_put_contents($directory . '/plugins/document-fixture/plugin.json', '{"name":"Fixture comment hooks","version":"1.0.0"}');
    file_put_contents($directory . '/plugins/document-fixture/plugin.php', <<<'PHP'
<?php
add_plugin_filter('comment_identity_html', static fn(string $html): string => $html . '<span data-fixture-comment-identity>身份扩展</span>');
add_plugin_filter('comment_meta_html', static fn(string $html): string => $html . '<span data-fixture-comment-meta>评论扩展</span>');
PHP);
    return $pdo;
}

function document_test_remove(string $directory): void
{
    document_test_assert(!is_link($directory) && is_file($directory . '/.document-test-fixture') && !is_link($directory . '/.document-test-fixture')
        && file_get_contents($directory . '/.document-test-fixture') === 'Isolated Document theme test fixture.', 'Refusing to delete a directory without the fixture marker.');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($directory);
}

function document_test_request(string $base, string $path, ?array $post = null, ?string $cookies = null): array
{
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) { curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]); }
    if ($cookies !== null) { curl_setopt_array($curl, [CURLOPT_COOKIEJAR => $cookies, CURLOPT_COOKIEFILE => $cookies]); }
    $response = curl_exec($curl);
    document_test_assert(is_string($response), 'HTTP request failed: ' . curl_error($curl));
    $headerSize = (int)curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
    unset($curl); // CurlHandle cleanup also flushes its cookie jar on current PHP.
    document_test_assert(!preg_match('/(?:Fatal error|Warning|Deprecated|Parse error):/', $result['body']), 'PHP diagnostics leaked into response: ' . $path);
    return $result;
}

function document_test_csrf(string $html): string
{
    document_test_assert(preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match) === 1, 'Missing CSRF token.');
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function document_test_list_titles(string $html): array
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $titles = [];
    foreach ($xpath->query('//section[contains(concat(" ", normalize-space(@class), " "), " document-post-list ")]//h2/a') as $title) { $titles[] = trim($title->textContent); }
    return $titles;
}

function document_test_public(array $response, int $status = 200): void
{
    document_test_assert($response['status'] === $status, 'Expected HTTP ' . $status . ', received ' . $response['status']);
    document_test_assert(str_contains($response['body'], 'themes/document/style.css'), 'Document theme layout was not used.');
    foreach (['PASSWORD_SECRET_SENTINEL', 'PASSWORD_EXCERPT_SENTINEL', 'password-secret-thumbnail.png', 'REPLY_SECRET_SENTINEL', 'REPLY_EXCERPT_SENTINEL', 'reply-secret-thumbnail.png', 'DRAFT_TITLE_SENTINEL', 'DRAFT_BODY_SENTINEL', 'FUTURE_TITLE_SENTINEL', 'FUTURE_BODY_SENTINEL'] as $secret) {
        document_test_assert(!str_contains($response['body'], $secret), 'Private content leaked into public HTML: ' . $secret);
    }
}

document_test_core_root();
$mode = $argv[1] ?? '';
if ($mode === '--sync') {
    document_test_sync((string)($argv[2] ?? ''));
    fwrite(STDOUT, "Fixture sources synchronized.\n");
    exit;
}
$fixture = $mode === '--prepare' ? (string)($argv[2] ?? '') : sys_get_temp_dir() . '/sblog-document-tests-' . bin2hex(random_bytes(6));
document_test_assert($fixture !== '', 'Provide a new temporary fixture directory.');
$pdo = document_test_prepare($fixture);
if ($mode === '--prepare') {
    fwrite(STDOUT, json_encode(['directory' => $fixture, 'command' => 'php -d session.save_path=' . escapeshellarg($fixture . '/sessions') . ' -S 127.0.0.1:8765 -t ' . escapeshellarg($fixture) . ' ' . escapeshellarg($fixture . '/router.php'), 'username' => 'document-admin', 'password' => 'Document-fixture-2026'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    exit;
}

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
document_test_assert(is_resource($socket), 'Cannot allocate local test port: ' . $error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$log = $fixture . '/server.log';
$server = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'session.save_path=' . $fixture . '/sessions', '-S', $address, '-t', $fixture, $fixture . '/router.php'], [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']], $pipes, $fixture);
document_test_assert(is_resource($server), 'Could not start isolated HTTP runtime.');
$failures = 0;
$checks = 0;
try {
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if (is_resource($connection)) { fclose($connection); $ready = true; break; }
        usleep(50000);
    }
    document_test_assert($ready, 'Test HTTP server did not become ready.');
    $tests = [];
    $tests['native schema provides all 41 fields without companion settings code'] = static function () use ($fixture): void {
        $schema = json_decode((string)file_get_contents($fixture . '/themes/document/settings.json'), true, 16, JSON_THROW_ON_ERROR);
        document_test_assert(count($schema['sections'] ?? []) === 5, 'Document must provide five native settings sections.');
        $keys = [];
        foreach ($schema['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                document_test_assert(!isset($keys[$field['key']]), 'Duplicate setting key: ' . $field['key']);
                document_test_assert(in_array($field['type'], ['text', 'textarea', 'url', 'color', 'checkbox', 'select', 'date'], true), 'Unsupported native setting type: ' . $field['type']);
                document_test_assert(is_string($field['default']), 'Native defaults must be strings: ' . $field['key']);
                if ($field['type'] === 'checkbox') { document_test_assert(in_array($field['default'], ['0', '1'], true), 'Checkbox default must be 0 or 1.'); }
                $keys[$field['key']] = true;
            }
        }
        document_test_assert(count($keys) === 41, 'Expected all 41 Document settings.');
        document_test_assert(!file_exists($fixture . '/themes/document/settings.php'), 'Legacy PHP settings schema is still required.');
        document_test_assert(!file_exists($fixture . '/plugins/document-settings'), 'Companion settings plugin is still required.');
        foreach (['functions.php', 'layout.php'] as $source) {
            $code = (string)file_get_contents($fixture . '/themes/document/' . $source);
            document_test_assert(!str_contains($code, 'document_options') && !str_contains($code, "'/settings.php'"), 'Theme still depends on legacy settings: ' . $source);
        }
    };
    $tests['public routes retain Document layout, status, and content gates'] = static function () use ($base): void {
        foreach (['/', '/?p=2', '/archive/php-notes', '/archive/protected-note', '/archive/reply-note', '/category/coding', '/tag/php', '/archives', '/tags', '/categories', '/links', '/about'] as $path) { document_test_public(document_test_request($base, $path)); }
        foreach (['/archive/missing', '/category/missing', '/a-page-that-does-not-exist', '/?p=999'] as $path) { document_test_public(document_test_request($base, $path), 404); }
    };
    $tests['detail uses real Markdown, comments, and plugin comment hooks'] = static function () use ($base): void {
        $response = document_test_request($base, '/archive/php-notes');
        foreach (['从一个简单的问题开始', '<pre', '山间的光', '认真读者', 'data-fixture-comment-identity', 'data-fixture-comment-meta', 'name="csrf_token"'] as $expected) { document_test_assert(str_contains($response['body'], $expected), 'Detail is missing ' . $expected); }
        $locked = document_test_request($base, '/archive/protected-note');
        document_test_assert(str_contains($locked['body'], 'content-gate--password'), 'Password gate is missing.');
        document_test_assert(str_contains(strtolower($locked['headers']), 'private, no-store'), 'Protected content lost private cache headers.');
        $reply = document_test_request($base, '/archive/reply-note');
        document_test_assert(str_contains($reply['body'], 'content-gate--reply'), 'Reply content gate is missing.');
    };
    $tests['search matches public content and excludes secret-only matches'] = static function () use ($base): void {
        $response = document_test_request($base, '/?q=' . rawurlencode('从小工具开始'));
        document_test_public($response);
        document_test_assert(document_test_list_titles($response['body']) === ['PHP 与 SQLite：从小工具开始的实践'], 'Public search did not return exactly its matching post.');
        $response = document_test_request($base, '/?q=PASSWORD_SECRET');
        document_test_public($response);
        document_test_assert(document_test_list_titles($response['body']) === [], 'Search used password-protected body text.');
        $response = document_test_request($base, '/?q=REPLY_SECRET');
        document_test_public($response);
        document_test_assert(document_test_list_titles($response['body']) === [], 'Search used reply-hidden body text.');
    };
    $tests['private theme and settings sources are inaccessible'] = static function () use ($base): void {
        foreach (['/themes/document/settings.json', '/themes/document/settings.php', '/themes/document/functions.php', '/themes/document/theme.json', '/data/config.php'] as $path) { document_test_assert(document_test_request($base, $path)['status'] === 404, 'Private source is publicly accessible: ' . $path); }
        document_test_assert(document_test_request($base, '/themes/document/style.css')['status'] === 200, 'Theme stylesheet is unavailable.');
    };
    $tests['native values, checkbox strings, and schema defaults ignore legacy configuration'] = static function () use ($base, $fixture, $pdo): void {
        $initial = json_decode((string)$pdo->query("SELECT value FROM settings WHERE name='theme_settings_document'")->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
        try {
            $values = array_replace(document_test_settings_defaults(), ['navbar_title' => 'NATIVE_TITLE_SENTINEL', 'primary_color' => '#2468ac', 'show_title' => '1', 'show_search' => '1', 'sidebar_left' => '1', 'sidebar_right' => '1']);
            document_test_store_settings($pdo, $fixture, $values);
            $html = document_test_request($base, '/')['body'];
            foreach (['NATIVE_TITLE_SENTINEL', '--document-primary:#2468ac', 'class="document-search"', 'data-left="1" data-right="1"'] as $expected) { document_test_assert(str_contains($html, $expected), 'Native setting did not render: ' . $expected); }
            document_test_assert(!str_contains($html, 'LEGACY_SHOULD_NOT_LOAD') && !str_contains($html, '#ff00ff'), 'Legacy settings leaked into native configuration.');
            $values = array_replace($values, ['show_title' => '0', 'show_search' => '0', 'sidebar_left' => '0', 'sidebar_right' => '0']);
            document_test_store_settings($pdo, $fixture, $values);
            $html = document_test_request($base, '/')['body'];
            document_test_assert(!str_contains($html, '<span class="title">') && !str_contains($html, 'class="document-search"'), 'Checkbox string 0 did not hide title and search.');
            document_test_assert(str_contains($html, 'data-left="0" data-right="0"') && !str_contains($html, '<aside class="sidebar-'), 'Checkbox string 0 did not hide sidebars.');
            // A missing field and a missing native record must both use schema defaults.
            document_test_store_settings($pdo, $fixture, ['author_name' => '部分原生配置']);
            $partial = document_test_request($base, '/')['body'];
            document_test_store_settings($pdo, $fixture, null);
            $missing = document_test_request($base, '/')['body'];
            foreach ([$partial, $missing] as $html) {
                document_test_assert(str_contains($html, '--document-primary:' . document_test_settings_defaults()['primary_color']), 'Missing native color did not use its schema default.');
                document_test_assert(str_contains($html, 'data-left="1" data-right="1"'), 'Missing native checkbox did not use its schema default.');
                document_test_assert(!str_contains($html, 'LEGACY_SHOULD_NOT_LOAD') && !str_contains($html, '#ff00ff'), 'Missing native setting fell back to legacy configuration.');
            }
        } finally { document_test_store_settings($pdo, $fixture, $initial); }
    };
    $tests['settings require authentication and CSRF, persist once valid, reject invalid writes'] = static function () use ($base, $fixture, $pdo): void {
        $path = '/index.php?a=admin_theme_settings&theme=document';
        document_test_assert(document_test_request($base, $path)['status'] === 302, 'Anonymous visitor can access theme settings.');
        $cookies = $fixture . '/admin-cookies.txt';
        $login = document_test_request($base, '/login', null, $cookies);
        $authenticated = document_test_request($base, '/login', ['csrf_token' => document_test_csrf($login['body']), 'username' => 'document-admin', 'password' => 'Document-fixture-2026'], $cookies);
        document_test_assert($authenticated['status'] === 302, 'Fixture login failed.');
        $settings = document_test_request($base, $path, null, $cookies);
        document_test_assert($settings['status'] === 200 && str_contains($settings['body'], 'name="settings[primary_color]"'), 'Authenticated native settings form did not render.');
        foreach (array_keys(document_test_settings_defaults()) as $key) { document_test_assert(str_contains($settings['body'], 'name="settings[' . $key . ']"'), 'Native settings form omitted ' . $key); }
        $before = (string)$pdo->query("SELECT value FROM settings WHERE name='theme_settings_document'")->fetchColumn();
        $legacy = (string)$pdo->query("SELECT value FROM settings WHERE name='document_options'")->fetchColumn();
        $bad = document_test_request($base, $path, ['settings' => ['primary_color' => '#123456']], $cookies);
        document_test_assert($bad['status'] === 422, 'Missing CSRF must be rejected.');
        document_test_assert($before === (string)$pdo->query("SELECT value FROM settings WHERE name='theme_settings_document'")->fetchColumn(), 'Rejected CSRF changed settings.');
        $csrf = document_test_csrf($settings['body']);
        $values = array_replace(document_test_settings_defaults(), ['primary_color' => '#123456', 'navbar_title' => '持久化设置验证', 'sidebar_left' => '1', 'sidebar_right' => '1', 'show_search' => '1', 'show_title' => '1']);
        $valid = document_test_request($base, $path, ['csrf_token' => $csrf, 'settings' => $values], $cookies);
        document_test_assert($valid['status'] === 303, 'Valid settings save did not redirect.');
        $stored = (string)$pdo->query("SELECT value FROM settings WHERE name='theme_settings_document'")->fetchColumn();
        document_test_assert((json_decode($stored, true)['primary_color'] ?? '') === '#123456', 'Saved color did not persist.');
        $public = document_test_request($base, '/');
        document_test_assert(str_contains($public['body'], '#123456') && str_contains($public['body'], '持久化设置验证'), 'Saved settings did not affect anonymous public output.');
        $invalid = document_test_request($base, $path, ['csrf_token' => $csrf, 'settings' => array_replace($values, ['primary_color' => 'red;}</style><script>alert(1)</script>', 'avatar' => 'javascript:alert(1)', 'navbar_title' => 'MUST_NOT_PARTIALLY_SAVE'])], $cookies);
        document_test_assert($invalid['status'] === 422 && str_contains($invalid['body'], 'id="theme-setting-primary_color-error"') && str_contains($invalid['body'], 'id="theme-setting-avatar-error"'), 'Invalid native settings did not render validation errors.');
        document_test_assert($stored === (string)$pdo->query("SELECT value FROM settings WHERE name='theme_settings_document'")->fetchColumn(), 'Invalid settings changed persisted configuration.');
        document_test_assert($legacy === (string)$pdo->query("SELECT value FROM settings WHERE name='document_options'")->fetchColumn(), 'Native settings modified legacy configuration.');
    };
    foreach ($tests as $name => $test) {
        $checks++;
        try { $test(); fwrite(STDOUT, 'PASS ' . $name . "\n"); }
        catch (Throwable $exception) { $failures++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . "\n"); }
    }
    $serverLog = (string)file_get_contents($log);
    if (preg_match('/(?:PHP (?:Fatal error|Warning|Deprecated|Parse error)|Theme (?:layout|action|filter) failed|Plugin .*failed)/', $serverLog)) {
        $failures++;
        fwrite(STDERR, "FAIL isolated server reported runtime diagnostics:\n" . $serverLog);
    }
} catch (Throwable $exception) {
    $failures++;
    fwrite(STDERR, $exception->getMessage() . "\n");
} finally {
    proc_terminate($server);
    if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }
    proc_close($server);
    if ($mode === '--keep' || $failures > 0) { fwrite(STDOUT, 'Fixture retained: ' . $fixture . "\n"); }
    else { document_test_remove($fixture); }
}
fwrite(STDOUT, sprintf("%d Document HTTP test groups, %d failures. Installed blog data was not loaded or modified.\n", $checks, $failures));
exit($failures === 0 ? 0 : 1);
