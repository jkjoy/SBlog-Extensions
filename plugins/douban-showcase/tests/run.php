<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Standalone offline tests: php plugins/douban-showcase/tests/run.php
$cacheDirectory = sys_get_temp_dir() . '/sblog-douban-tests-' . bin2hex(random_bytes(6));
mkdir($cacheDirectory, 0700, true);
define('CACHE_DIR', $cacheDirectory);
require dirname(__DIR__) . '/api.php';
$checks = 0;
function expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}
function fixture(string $name): string
{
    return (string)file_get_contents(__DIR__ . '/fixtures/douban-' . $name . '.html');
}
function response(string $html): array
{
    return ['ok' => true, 'body' => $html, 'message' => '', 'http_code' => 200];
}
try {
    $movie = fixture('movie');
    $music = fixture('music');
    $book = fixture('book');
    $currentBook = fixture('book-current');
    $parsed = sblog_douban_parse_page($movie, '123456');
    expect($parsed['status'] === 'ok' && count($parsed['items']) === 2, 'movie grid recognized');
    expect($parsed['profile']['id'] === '123456' && $parsed['profile']['url'] === 'https://www.douban.com/people/reader-demo/', 'numeric ID accepts canonical alias');
    expect($parsed['profile']['name'] === '测试用户', 'UTF-8 profile extracted');
    expect($parsed['items'][0]['title'] === '我不是药神', 'primary title excludes alternate titles and playable badge');
    expect($parsed['items'][0]['rating'] === 5 && $parsed['items'][0]['date'] === '2024-02-29', 'personal rating and date extracted');
    expect($parsed['items'][0]['comment'] === '测试短评 <script> & 引号 "', 'comment remains plain text for escaped rendering');
    expect($parsed['items'][0]['tags'] === ['剧情', '喜剧'], 'tags split and prefix removed');
    expect($parsed['total'] === 32 && $parsed['next_start'] === 15, 'official paginator offset used independently of fixture row count');
    $parsedMusic = sblog_douban_parse_page($music, '123456', 'music');
    expect($parsedMusic['status'] === 'ok' && $parsedMusic['items'][1]['title'] === "Ten Summoner's Tales", 'music entities decoded');
    expect($parsedMusic['profile']['avatar'] === 'https://img3.doubanio.com/icon/user_normal.jpg', 'music side profile avatar extracted');
    $parsedBook = sblog_douban_parse_page($book, '123456', 'book');
    expect($parsedBook['status'] === 'ok' && $parsedBook['items'][0]['cover_url'] === 'https://img1.doubanio.com/view/subject/s/public/s1111111.jpg', 'book contract and protocol-relative cover');
    $parsedCurrentBook = sblog_douban_parse_page($currentBook, '123456', 'book');
    expect($parsedCurrentBook['status'] === 'ok' && count($parsedCurrentBook['items']) === 2, 'current book interest-list recognized');
    expect($parsedCurrentBook['items'][0]['title'] === '告别薇安' && $parsedCurrentBook['items'][0]['rating'] === 5,
        'current book heading and personal rating extracted');
    expect($parsedCurrentBook['items'][0]['date'] === '2025-03-29' && $parsedCurrentBook['items'][1]['rating'] === 0,
        'current book date excludes state suffix and unrated records remain unrated');
    expect($parsedCurrentBook['items'][0]['intro'] === '安妮宝贝 / 中国社会科学出版社 / 2000-1 / 19.00'
        && $parsedCurrentBook['items'][0]['comment'] === '测试读书短评 <script>' && $parsedCurrentBook['items'][0]['tags'] === ['小说', '文学'],
        'current book publication metadata, plain comment, and tags extracted');
    expect($parsedCurrentBook['next_start'] === 15 && $parsedCurrentBook['total'] === 3, 'current book official pagination offset extracted');
    foreach (['wish' => '想读', 'do' => '在读'] as $bookStatus => $bookStatusLabel) {
        $modifiedBook = str_replace(['读过', '/collect'], [$bookStatusLabel, '/' . $bookStatus], $currentBook);
        $parsedState = sblog_douban_parse_page($modifiedBook, '123456', 'book', $bookStatus);
        expect($parsedState['status'] === 'ok' && $parsedState['items'][0]['date'] === '2025-03-29', 'current book ' . $bookStatus . ' dates and heading recognized');
    }
    expect(sblog_douban_parse_page(str_replace('https://book.douban.com/subject/1016523/', 'https://evil.example/subject/1016523/', $currentBook), '123456', 'book')['status'] === 'error',
        'current book headings reject external subject URLs');
    expect(sblog_douban_parse_page(str_replace('2025-03-29', '2025-02-29', $currentBook), '123456', 'book')['items'][0]['date'] === '',
        'current book invalid calendar dates are rejected');
    foreach (['wish' => ['想看的影视', '想读的书', '想听的音乐'], 'do' => ['在看的影视', '在读的书', '在听的音乐']] as $status => $titles) {
        foreach (['movie' => $movie, 'book' => $book, 'music' => $music] as $type => $html) {
            $index = array_search($type, ['movie', 'book', 'music'], true);
            $modified = str_replace(['看过的影视', '读过的书', '听过的音乐', '/collect'], [$titles[$index], $titles[$index], $titles[$index], '/' . $status], $html);
            expect(sblog_douban_parse_page($modified, '123456', $type, $status)['status'] === 'ok', $type . '/' . $status . ' recognized');
        }
    }
    foreach (['https://evil.example/cover.jpg', 'https://img3.doubanio.com.evil.example/x', 'https://img3.doubanio.com@evil.example/x', 'javascript:alert(1)', 'https://img3.doubanio.com:444/x', "https://img3.doubanio.com/x\n"] as $url) {
        expect(sblog_douban_image_url($url) === '', 'unsafe image rejected: ' . $url);
    }
    expect(sblog_douban_image_url('http://img3.doubanio.com/x.jpg') === 'https://img3.doubanio.com/x.jpg', 'legacy CDN URL upgraded to TLS');
    foreach (['', '../123', 'abc?x=1', '-abc', "abc\n", '中文', str_repeat('a', 65)] as $id) {
        expect(sblog_douban_list_url($id) === '', 'invalid ID rejected');
    }
    expect(sblog_douban_list_url('reader-demo', 'book', 'wish', 15) === 'https://book.douban.com/people/reader-demo/wish?start=15&sort=time&rating=all&filter=all&mode=grid', 'list URL fixed host and query');
    $challenge = '<html><head><title>豆瓣安全验证</title></head><body><form><input name="captcha-solution"></form></body></html>';
    expect(sblog_douban_parse_page($challenge, '123456')['status'] === 'error', 'captcha is not an empty list');
    expect(sblog_douban_parse_page('<html><title>Forbidden</title><body>403</body></html>', '123456')['status'] === 'error', 'blocked page rejected');
    $private = '<html><head><meta charset="utf-8"><title>豆瓣记录</title></head><body><div id="content"><div class="article">该用户的收藏列表不对外公开</div></div></body></html>';
    expect(sblog_douban_parse_page($private, '123456')['status'] === 'private', 'explicit private page identified');
    expect(sblog_douban_parse_page(str_replace('测试短评', '仅自己可见', $movie), '123456')['status'] === 'ok', 'a comment mentioning privacy does not erase public collection');
    $empty = '<html><head><meta charset="utf-8"></head><body><div id="db-usr-profile"><h1>测试用户看过的影视(0)</h1><a href="/people/reader-demo/">主页</a></div><div class="grid-view"></div></body></html>';
    expect(sblog_douban_parse_page($empty, '123456')['status'] === 'ok', 'explicit zero count is a public empty list');
    expect(sblog_douban_parse_page(str_replace('(0)', '(9)', $empty), '123456')['status'] === 'error', 'missing rows with positive total rejected');
    expect(sblog_douban_parse_page(str_replace('/people/reader-demo/collect?start=15', 'https://evil.example/people/reader-demo/collect?start=15', $movie), '123456')['status'] === 'error', 'external pagination link rejected');
    expect(sblog_douban_parse_page(str_replace('start=15', 'start=0', $movie), '123456')['status'] === 'error', 'nonadvancing paginator rejected');
    expect(sblog_douban_parse_page(str_replace('2024-02-29', '2023-02-29', $movie), '123456')['items'][0]['date'] === '', 'invalid calendar date removed');
    expect(sblog_douban_parse_page(str_replace('https://movie.douban.com/subject/26752088/', 'https://evil.example/subject/26752088/', $movie), '123456')['status'] === 'error', 'external subject URL rejected');

    $config = ['user_id' => '123456', 'max_pages' => 1, 'cache_minutes' => 360];
    $calls = [];
    $transport = static function (string $url) use (&$calls, $movie): array { $calls[] = $url; return response($movie); };
    $snapshot = sblog_douban_snapshot($config, 'movie', 'collect', false, $transport);
    expect($snapshot['status'] === 'ok' && $snapshot['truncated'] && count($snapshot['items']) === 2, 'configured limit explicitly produces truncated snapshot');
    expect(count($calls) === 1, 'page limit respected');
    expect(sblog_douban_snapshot($config, 'movie', 'collect', false, $transport) === $snapshot && count($calls) === 1, 'fresh cache avoids network');
    expect(sblog_douban_snapshot($config, 'movie', 'collect', true, $transport)['status'] === 'ok' && count($calls) === 2, 'force refresh bypasses success TTL');
    $failureCalls = 0;
    $failure = static function (string $url) use (&$failureCalls): array { $failureCalls++; return ['ok' => false, 'body' => '', 'http_code' => 403, 'message' => '豆瓣暂时拒绝访问。']; };
    $stale = sblog_douban_snapshot($config, 'movie', 'collect', true, $failure);
    expect($stale['status'] === 'stale' && $stale['items'] === $snapshot['items'] && $stale['updated_at'] === $snapshot['updated_at'], 'failure retains last successful data with stale label');
    expect(sblog_douban_snapshot($config, 'movie', 'collect', true, $failure)['status'] === 'stale' && $failureCalls === 1, 'failure cooldown also applies to force refresh');
    $paths = sblog_douban_cache_paths($config);
    $record = sblog_douban_read_cache($paths['data']);
    $record['snapshot']['updated_at'] = time() - 7 * 86400 - 10;
    sblog_douban_write_cache($paths['data'], $record);
    expect(sblog_douban_snapshot($config, 'movie', 'collect', true, $failure)['status'] === 'error', 'stale data expires within failure cooldown after seven days');
    $record['snapshot'] = $snapshot;
    $record['next_retry_at'] = 0;
    sblog_douban_write_cache($paths['data'], $record);
    $completeMovie = str_replace(['(32)', '/&nbsp;32'], ['(2)', '/&nbsp;2'], (string)preg_replace('/<div class="paginator">.*?<\/div>/s', '', $movie));
    $threePages = array_replace($config, ['max_pages' => 3]);
    expect(sblog_douban_snapshot($threePages, 'movie', 'collect', true, static fn(string $url): array => response($completeMovie))['status'] === 'ok', 'another page-limit variant caches public records');
    $privateSnapshot = sblog_douban_snapshot($config, 'movie', 'collect', true, static fn(string $url): array => response($private));
    expect($privateSnapshot['status'] === 'private' && $privateSnapshot['items'] === [] && $privateSnapshot['profile'] === null, 'explicit privacy clears last public data');
    expect(sblog_douban_read_cache($paths['data'])['snapshot']['items'] === [], 'private result persists without old public records');
    expect(sblog_douban_snapshot($config, 'movie', 'collect', false, $transport)['status'] === 'private' && count($calls) === 2, 'private result respects TTL');
    $oldVariant = sblog_douban_snapshot($threePages, 'movie', 'collect', false, $transport);
    expect($oldVariant['status'] === 'private' && $oldVariant['items'] === [] && count($calls) === 2, 'changing page limit cannot resurrect old public records after privacy');
    expect(sblog_douban_snapshot($threePages, 'music', 'collect', false, static fn(string $url): array => response($music))['status'] === 'ok', 'privacy in movies does not erase public music');
    $restored = sblog_douban_snapshot($threePages, 'movie', 'collect', true, static fn(string $url): array => response($completeMovie));
    expect($restored['status'] === 'ok' && count($restored['items']) === 2, 'fresh official public response may restore a previously private collection');
    $raceConfig = array_replace($config, ['user_id' => 'race-user']);
    $racePaths = sblog_douban_cache_paths($raceConfig);
    $raceSnapshot = sblog_douban_snapshot($raceConfig, 'movie', 'collect', true, static function (string $url) use ($completeMovie, $racePaths): array {
        sblog_douban_write_cache($racePaths['privacy'], ['version' => 1, 'observed_at' => time(), 'token' => bin2hex(random_bytes(16))]);
        return response($completeMovie);
    });
    expect($raceSnapshot['status'] === 'private' && $raceSnapshot['items'] === [], 'parallel privacy observation invalidates in-flight public fetch');
    $httpPrivateConfig = array_replace($config, ['user_id' => 'http-private']);
    expect(sblog_douban_snapshot($httpPrivateConfig, 'movie', 'collect', true,
        static fn(string $url): array => ['ok' => false, 'body' => $private, 'message' => '拒绝访问', 'http_code' => 403])['status'] === 'private', 'explicit private HTML is honored on HTTP 403');
    sblog_douban_clear_cache($config);
    expect(!is_file($paths['data']), 'clear removes account data');
    $lock = fopen($paths['lock'], 'c');
    flock($lock, LOCK_EX);
    expect(sblog_douban_snapshot($config, 'movie', 'collect', false, $transport)['status'] === 'error' && count($calls) === 2, 'parallel refresh lock prevents network');
    flock($lock, LOCK_UN);
    fclose($lock);
    $secondMovie = str_replace(['(32)', '/&nbsp;32', '26752088', '26635329', '<link rel="next"', '<a href="/people/reader-demo/collect?start=15'], ['(4)', '/&nbsp;4', '26752089', '26635330', '<link rel="disabled-next"', '<a data-disabled-href="/people/reader-demo/collect?start=15'], $movie);
    $twoPages = array_replace($config, ['max_pages' => 2]);
    $offsets = [];
    $snapshot = sblog_douban_snapshot($twoPages, 'movie', 'collect', true, static function (string $url) use (&$offsets, $movie, $secondMovie): array {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $offsets[] = (int)$query['start'];
        return response((int)$query['start'] === 0 ? str_replace(['(32)', '/&nbsp;32'], ['(4)', '/&nbsp;4'], $movie) : $secondMovie);
    });
    expect($snapshot['status'] === 'ok' && count($snapshot['items']) === 4 && !$snapshot['truncated'] && $offsets === [0, 15], 'multiple pages follow advertised offset and complete known total');
    $failedSnapshot = sblog_douban_snapshot(array_replace($config, ['user_id' => 'new-user', 'max_pages' => 2]), 'movie', 'collect', false,
        static function (string $url) use ($movie): array { return str_contains($url, 'start=0&') ? response($movie) : ['ok' => false, 'http_code' => 500, 'body' => '', 'message' => '暂时失败。']; });
    expect($failedSnapshot['status'] === 'stale' && count($failedSnapshot['items']) === 2 && $failedSnapshot['truncated']
        && str_contains($failedSnapshot['message'], '已获取部分记录'), 'first interrupted synchronization explicitly retains partial records');
    expect(sblog_douban_cache_paths($config, 'book') !== sblog_douban_cache_paths($config), 'type keys isolate caches');
    expect(sblog_douban_cache_paths($config, 'movie', 'wish') !== sblog_douban_cache_paths($config), 'status keys isolate caches');
    expect(sblog_douban_cache_paths($twoPages) !== sblog_douban_cache_paths($config), 'page limit keys isolate caches');
    file_put_contents($paths['data'], '{"version":1,"snapshot":{}}');
    expect(sblog_douban_read_cache($paths['data']) === null, 'corrupt cache rejected');
    expect(sblog_douban_snapshot(['user_id' => '../bad'], 'movie', 'collect', false, $transport)['status'] === 'unconfigured', 'bad config never fetches');
    echo 'PASS: ' . $checks . " Douban API checks\n";
} finally {
    foreach (glob($cacheDirectory . '/*') ?: [] as $path) {
        if (is_file($path)) { unlink($path); }
    }
    rmdir($cacheDirectory);
}
