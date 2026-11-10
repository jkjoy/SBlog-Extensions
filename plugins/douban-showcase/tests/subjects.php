<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Standalone offline tests: php plugins/douban-showcase/tests/subjects.php
$cacheDirectory = sys_get_temp_dir() . '/sblog-douban-subject-tests-' . bin2hex(random_bytes(6));
mkdir($cacheDirectory, 0700, true);
define('CACHE_DIR', $cacheDirectory);
function sblog_douban_config(): array { return ['cache_minutes' => 360]; }
require dirname(__DIR__) . '/api.php';
require dirname(__DIR__) . '/subjects.php';
$checks = 0;
function expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}
function response(string $body): array { return ['ok' => true, 'body' => $body, 'http_code' => 200]; }
function encoded(array $data): string { return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function record(array $data, int $updatedAt, int $retryAt = 0): array
{
    return ['version' => 1, 'updated_at' => $updatedAt, 'checked_at' => time(), 'next_retry_at' => $retryAt, 'data' => $data];
}
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    $identity = ['type' => 'movie', 'id' => '1292052', 'url' => 'https://movie.douban.com/subject/1292052/'];
    foreach (['https://movie.douban.com/subject/1292052/', 'http://MOVIE.douban.com/subject/1292052?from=search#item',
        'https://m.douban.com/movie/subject/1292052/', 'https://www.douban.com/movie/subject/1292052/'] as $url) {
        expect(sblog_douban_subject_identity($url) === $identity, 'official desktop/mobile URL canonicalized');
    }
    foreach (['', 'https://evil.example/subject/1292052/', 'https://movie.douban.com.evil.example/subject/1292052/',
        'https://movie.douban.com@evil.example/subject/1292052/', 'https://user@movie.douban.com/subject/1292052/',
        'https://movie.douban.com:443/subject/1292052/', 'file:///subject/1292052/', '//movie.douban.com/subject/1292052/',
        'https://movie.douban.com/subject/../1292052/', 'https://movie.douban.com/subject/%31/',
        'https://movie.douban.com/subject/0/', 'https://movie.douban.com/subject/0129/',
        'https://movie.douban.com/subject/1234567890123456789/', 'https://movie.douban.com/people/1292052/',
        'https://movie.douban.com/subject/1292052/extra', "https://movie.douban.com/subject/1292052/\n",
        'https://movie.douban.com\@evil.example/subject/1292052/'] as $url) {
        expect(sblog_douban_subject_identity($url) === null, 'unsafe or unsupported URL rejected');
    }

    $movie = ['id' => '1292052', 'title' => '肖申克的救赎', 'original_title' => 'The Shawshank Redemption', 'year' => '1994',
        'rating' => ['value' => 9.7, 'count' => 3061234], 'genres' => ['剧情', '犯罪'],
        'directors' => [['name' => '弗兰克·德拉邦特']], 'actors' => [['name' => '蒂姆·罗宾斯'], ['name' => '摩根·弗里曼']],
        'intro' => "希望让人自由。\n  这是一段简介。", 'pic' => ['large' => 'https://img3.doubanio.com/view/photo/s_ratio_poster/public/p480747492.jpg']];
    $parsed = sblog_douban_subject_parse(encoded($movie), 'movie', '1292052');
    expect(array_intersect_key($parsed, $identity) === $identity, 'movie API produces canonical identity');
    expect($parsed['title'] === '肖申克的救赎' && $parsed['original_title'] === 'The Shawshank Redemption', 'movie titles extracted');
    expect($parsed['rating'] === '9.7' && $parsed['rating_count'] === 3061234, 'rating and vote count extracted');
    expect($parsed['meta'] === ['1994', '剧情', '犯罪', '导演：弗兰克·德拉邦特', '主演：蒂姆·罗宾斯 / 摩根·弗里曼'], 'year, genres, credits normalized');
    expect($parsed['summary'] === '希望让人自由。 这是一段简介。' && str_starts_with($parsed['cover_url'], 'https://img3.doubanio.com/'), 'summary and official cover extracted');
    $book = sblog_douban_subject_parse(encoded(['id' => 1000001, 'title' => '山海经', 'author' => ['佚名'],
        'publisher' => ['name' => '测试出版社'], 'pubdate' => ['2024-01'], 'rating' => ['value' => 8, 'count' => 70],
        'pic' => ['normal' => '//img1.doubanio.com/view/subject/s/public/s1111111.jpg']]), 'book', '1000001');
    expect($book['year'] === '2024' && $book['rating'] === '8.0' && $book['meta'] === ['2024', '作者：佚名', '出版社：测试出版社'], 'book publication data supported');
    expect($book['cover_url'] === 'https://img1.doubanio.com/view/subject/s/public/s1111111.jpg', 'protocol-relative cover normalized');
    $music = sblog_douban_subject_parse(encoded(['id' => '1000002', 'title' => '音乐专辑', 'singer' => ['歌手甲', '歌手乙'],
        'publisher' => ['唱片公司'], 'pubdate' => ['2020-03-01'], 'rating' => ['value' => 0, 'count' => 0]]), 'music', '1000002');
    expect($music['rating'] === '' && $music['meta'] === ['2020', '表演者：歌手甲 / 歌手乙', '发行：唱片公司'], 'unrated music and performers supported');
    foreach ([array_replace($movie, ['id' => '999']), array_replace($movie, ['id' => ['1292052']]), ['error' => 'blocked'], []] as $bad) {
        expect(sblog_douban_subject_parse(encoded($bad), 'movie', '1292052') === [], 'invalid JSON payload or mismatched ID rejected');
    }
    $untrusted = sblog_douban_subject_parse(encoded(array_replace($movie, ['title' => '<b>安全标题</b>',
        'intro' => '<img src=x onerror=alert(1)>简介', 'pic' => ['large' => 'https://evil.example/cover.jpg'],
        'rating' => ['value' => 99, 'count' => -1]])), 'movie', '1292052');
    expect($untrusted['title'] === '安全标题' && $untrusted['summary'] === '简介' && $untrusted['cover_url'] === '', 'remote text is plain and external covers are rejected');
    expect($untrusted['rating'] === '' && $untrusted['rating_count'] === 0, 'invalid ratings excluded');
    $summaryFallback = sblog_douban_subject_normalize(array_replace($movie, ['intro' => ' <br> ', 'summary' => ' <b>备用简介</b> ', 'description' => '其他简介']), 'movie', '1292052');
    expect($summaryFallback['summary'] === '备用简介', 'empty cleaned intro does not hide a usable summary');
    expect(sblog_douban_subject_normalize(array_replace($movie, ['intro' => [], 'summary' => '', 'description' => '备用描述']), 'movie', '1292052')['summary'] === '备用描述', 'malformed or empty earlier descriptions do not hide later text');
    $officialCover = $movie['pic']['large'];
    foreach ([['cover_url' => '', 'image' => '', 'pic' => ['large' => '', 'normal' => $officialCover]],
        ['cover_url' => 'https://evil.example/cover.jpg', 'image' => $officialCover],
        ['cover_url' => 'https://img3.doubanio.com/icon/user.jpg', 'image' => $officialCover],
        ['cover_url' => $officialCover . '?redirect=1', 'image' => $officialCover],
        ['image' => ['url' => 'javascript:alert(1)'], 'pic' => ['url' => $officialCover]],
        ['image' => [['url' => $officialCover]], 'pic' => []],
        ['image' => ['contentUrl' => $officialCover], 'pic' => []]] as $images) {
        expect(sblog_douban_subject_normalize(array_replace($movie, $images), 'movie', '1292052')['cover_url'] === $officialCover,
            'first usable official cover wins after empty, invalid, or object candidates');
    }
    expect(sblog_douban_subject_normalize(array_replace($movie, ['pic' => ['large' => $officialCover . '?x=1']]), 'movie', '1292052')['cover_url'] === '', 'unproxyable CDN image queries are not cached as usable covers');

    $ld = ['@context' => 'https://schema.org', '@type' => 'Movie', 'name' => '页面电影', 'alternateName' => 'Page Movie',
        'url' => $identity['url'], 'datePublished' => '2025-10-01', 'genre' => ['剧情'],
        'director' => [['@type' => 'Person', 'name' => '导演甲']], 'actor' => [['name' => '主演乙']],
        'image' => 'https://img9.doubanio.com/view/photo/s_ratio_poster/public/p123.jpg',
        'description' => 'JSON-LD 简介', 'aggregateRating' => ['ratingValue' => '8.6', 'ratingCount' => '12345']];
    $html = '<html><head><meta charset="utf-8"><title>页面电影 (豆瓣)</title><script type="application/ld+json">'
        . encoded(['@graph' => [['@type' => 'Organization', 'name' => '豆瓣'], $ld]]) . '</script></head><body></body></html>';
    $fromHtml = sblog_douban_subject_parse($html, 'movie', '1292052');
    expect($fromHtml['title'] === '页面电影' && $fromHtml['original_title'] === 'Page Movie' && $fromHtml['year'] === '2025', 'JSON-LD graph parsed');
    expect($fromHtml['rating'] === '8.6' && $fromHtml['rating_count'] === 12345 && $fromHtml['summary'] === 'JSON-LD 简介', 'JSON-LD aggregate rating and summary parsed');
    expect(sblog_douban_subject_parse(str_replace(encoded(['@graph' => [['@type' => 'Organization', 'name' => '豆瓣'], $ld]]), encoded([$ld]), $html), 'movie', '1292052')['title'] === '页面电影', 'JSON-LD top-level list supported on PHP 8.0');
    $fallbackHtml = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>页面图书 (豆瓣)</title>
<meta property="og:title" content="页面图书"><meta property="og:url" content="https://book.douban.com/subject/1000001/">
<meta property="og:image" content="https://img1.doubanio.com/view/subject/s/public/s123.jpg">
<meta property="og:description" content="OG 简介"></head><body>
<h1><span property="v:itemreviewed">页面图书</span><span class="year">(2022)</span></h1>
<strong property="v:average">8.9</strong><span property="v:votes">123</span>
<div id="info">作者: 测试作者 / 出版社: 测试出版社</div><span property="v:summary">正文简介 &amp; 测试</span>
</body></html>
HTML;
    $fallback = sblog_douban_subject_parse($fallbackHtml, 'book', '1000001');
    expect($fallback['title'] === '页面图书' && $fallback['year'] === '2022' && $fallback['rating'] === '8.9' && $fallback['rating_count'] === 123, 'OG/visible HTML fallback parsed');
    expect($fallback['summary'] === '正文简介 & 测试' && in_array('作者: 测试作者 / 出版社: 测试出版社', $fallback['meta'], true), 'visible metadata and HTML entities retained as text');
    $mainpicFallback = str_replace(['https://img1.doubanio.com/view/subject/s/public/s123.jpg', '</body>'],
        ['https://evil.example/cover.jpg', '<div id="mainpic"><img src="' . $officialCover . '"></div></body>'], $fallbackHtml);
    expect(sblog_douban_subject_parse($mainpicFallback, 'book', '1000001')['cover_url'] === $officialCover, 'invalid OG image does not hide a valid mainpic cover');
    $expandedSummary = str_replace('</body>', '<div id="link-report"><span class="all hidden">完整的展开简介</span></div></body>', $fallbackHtml);
    expect(sblog_douban_subject_parse($expandedSummary, 'book', '1000001')['summary'] === '完整的展开简介', 'expanded synopsis takes priority over its collapsed preview');
    $bookSummary = str_replace(['正文简介 &amp; 测试', 'content="OG 简介"', '</body>'],
        ['', 'content=""', '<div id="link-report"><div class="intro"><p>图书内容简介</p></div></div></body>'], $fallbackHtml);
    expect(sblog_douban_subject_parse($bookSummary, 'book', '1000001')['summary'] === '图书内容简介', 'book intro supplies the synopsis without movie RDF attributes');
    expect(sblog_douban_subject_parse(str_replace('https://book.douban.com/subject/1000001/', 'https://evil.example/subject/1000001/', $fallbackHtml), 'book', '1000001') === [], 'mismatched OG identity rejected');
    expect(sblog_douban_subject_parse(str_replace($identity['url'], 'https://movie.douban.com/subject/999/', $html), 'movie', '1292052') === [], 'mismatched JSON-LD identity rejected');
    expect(sblog_douban_subject_parse($html, 'book', '1000001') === [], 'JSON-LD type and identity cannot cross work types');
    expect(sblog_douban_subject_parse(str_replace(encoded(['@graph' => [['@type' => 'Organization', 'name' => '豆瓣'], $ld]]), encoded(array_replace($ld, ['@type' => [['unexpected']]])), $html), 'movie', '1292052') === [], 'malformed JSON-LD types are ignored without warnings');
    expect(sblog_douban_subject_parse(encoded(array_replace($movie, ['pic' => 'invalid', 'genres' => [['name' => ['invalid']]], 'directors' => 123])), 'movie', '1292052')['title'] === $movie['title'], 'malformed optional API metadata does not break parsing');
    expect(sblog_douban_subject_parse(str_replace('</body>', '<form><input name="captcha-solution"></form></body>', $html), 'movie', '1292052') === [], 'challenge form rejected even with old metadata');
    expect(sblog_douban_subject_parse(str_replace('页面电影 (豆瓣)', '豆瓣安全验证', $html), 'movie', '1292052') === [], 'challenge title rejected');
    expect(sblog_douban_subject_parse('<html><title>Forbidden</title></html>', 'movie', '1292052') === [], 'blocked page rejected');
    expect(sblog_douban_subject_parse(str_repeat('x', 2 * 1024 * 1024 + 1), 'movie', '1292052') === [], 'oversized response rejected');

    $calls = [];
    $transport = static function (string $url, float $timeout) use (&$calls, $movie): array {
        $calls[] = [$url, $timeout]; return response(encoded($movie));
    };
    expect(sblog_douban_subject_data('movie', '1292052', $transport) === $parsed && count($calls) === 1, 'API response cached');
    expect($calls[0][0] === 'https://m.douban.com/rexxar/api/v2/movie/1292052?ck=&for_mobile=1' && $calls[0][1] > 0 && $calls[0][1] <= 3, 'fixed API URL and bounded timeout used');
    expect(sblog_douban_subject_data('movie', '1292052', $transport) === $parsed && count($calls) === 1, 'fresh cache avoids network');
    $fallbackCalls = [];
    expect(sblog_douban_subject_data('book', '1000001', static function (string $url, float $timeout) use (&$fallbackCalls, $fallbackHtml): array {
        $fallbackCalls[] = [$url, $timeout];
        return str_contains($url, '/rexxar/') ? ['ok' => false, 'body' => '', 'http_code' => 403] : response($fallbackHtml);
    }) === $fallback && count($fallbackCalls) === 2, 'HTML fallback succeeds after API denial');
    expect($fallbackCalls[1][0] === 'https://book.douban.com/subject/1000001/' && $fallbackCalls[1][1] > 0 && $fallbackCalls[1][1] <= 6, 'fallback uses official work URL within total budget');
    foreach (['both' => ['intro' => '', 'pic' => []], 'summary' => ['intro' => ''], 'cover' => ['pic' => []]] as $missing => $fields) {
        $id = ['both' => '9100001', 'summary' => '9100002', 'cover' => '9100003'][$missing];
        $partial = array_replace($movie, ['id' => $id], $fields);
        $richHtml = str_replace('1292052', $id, $html);
        $enrichmentCalls = [];
        $enriched = sblog_douban_subject_data('movie', $id, static function (string $url, float $timeout) use (&$enrichmentCalls, $partial, $richHtml): array {
            $enrichmentCalls[] = [$url, $timeout];
            return response(str_contains($url, '/rexxar/') ? encoded($partial) : $richHtml);
        });
        expect(count($enrichmentCalls) === 2 && $enriched['cover_url'] !== '' && $enriched['summary'] !== '', 'partial API missing ' . $missing . ' is enriched from HTML');
        expect($enriched['title'] === $movie['title'] && $enriched['rating'] === '9.7' && $enriched['meta'] === $parsed['meta'], 'HTML enrichment keeps existing API titles, scores, and metadata');
        expect($enriched['summary'] === ($missing === 'cover' ? $parsed['summary'] : 'JSON-LD 简介')
            && $enriched['cover_url'] === ($missing === 'summary' ? $officialCover : $ld['image']), 'HTML enrichment fills only missing visual fields');
        expect($enrichmentCalls[1][0] === 'https://movie.douban.com/subject/' . $id . '/' && $enrichmentCalls[1][1] > 0 && $enrichmentCalls[1][1] <= 6, 'partial enrichment stays within the fixed URL and timeout budget');
        expect(sblog_douban_subject_data('movie', $id, static function (): array { throw new RuntimeException('fresh complete data should not request'); }) === $enriched, 'completed enrichment observes the normal success TTL');
    }
    $oldPartialId = '9100004';
    $oldPartial = sblog_douban_subject_normalize(['id' => $oldPartialId, 'title' => '旧的残缺卡片'], 'movie', $oldPartialId);
    sblog_douban_write_cache(sblog_douban_subject_cache_path('movie', $oldPartialId), record($oldPartial, time()));
    $repairCalls = 0;
    $repaired = sblog_douban_subject_data('movie', $oldPartialId, static function () use (&$repairCalls, $movie, $oldPartialId): array {
        $repairCalls++; return response(encoded(array_replace($movie, ['id' => $oldPartialId])));
    });
    expect($repairCalls === 1 && $repaired['cover_url'] === $officialCover && $repaired['summary'] === $parsed['summary'], 'existing fresh partial cache immediately gets one repair attempt');
    $partialId = '9100005';
    $partialCalls = 0;
    $partialTransport = static function (string $url) use (&$partialCalls, $partialId): array {
        $partialCalls++;
        return str_contains($url, '/rexxar/') ? response(encoded(['id' => $partialId, 'title' => '暂缺资料的电影'])) : ['ok' => false];
    };
    $partialData = sblog_douban_subject_data('movie', $partialId, $partialTransport);
    $partialPath = sblog_douban_subject_cache_path('movie', $partialId);
    $partialRecord = sblog_douban_subject_read_cache($partialPath, 'movie', $partialId);
    expect($partialCalls === 2 && $partialData['title'] === '暂缺资料的电影' && $partialData['summary'] === '' && $partialData['cover_url'] === '', 'failed enrichment keeps usable partial API data');
    expect($partialRecord['next_retry_at'] >= time() + 295 && $partialRecord['next_retry_at'] <= time() + 300, 'partial success gets a five-minute enrichment cooldown');
    expect(sblog_douban_subject_data('movie', $partialId, $partialTransport) === $partialData && $partialCalls === 2, 'partial cooldown prevents repeated network requests');
    $partialRecord['next_retry_at'] = time() - 1;
    sblog_douban_write_cache($partialPath, $partialRecord);
    $repairedPartial = sblog_douban_subject_data('movie', $partialId, static function () use (&$partialCalls, $movie, $partialId): array {
        $partialCalls++; return response(encoded(array_replace($movie, ['id' => $partialId])));
    });
    expect($partialCalls === 3 && $repairedPartial['summary'] === $parsed['summary'], 'partial data is repaired when cooldown expires inside the normal success TTL');
    $richId = '9100006';
    $richPath = sblog_douban_subject_cache_path('movie', $richId);
    $oldRich = sblog_douban_subject_normalize(array_replace($movie, ['id' => $richId]), 'movie', $richId);
    sblog_douban_write_cache($richPath, record($oldRich, time() - 8 * 3600));
    $downgradeCalls = 0;
    $preserved = sblog_douban_subject_data('movie', $richId, static function (string $url) use (&$downgradeCalls, $richId): array {
        $downgradeCalls++;
        return str_contains($url, '/rexxar/') ? response(encoded(['id' => $richId, 'title' => '最新标题', 'rating' => ['value' => 9.8]])) : ['ok' => false];
    });
    expect($downgradeCalls === 2 && $preserved['title'] === '最新标题' && $preserved['rating'] === '9.8'
        && $preserved['summary'] === $oldRich['summary'] && $preserved['cover_url'] === $oldRich['cover_url'], 'partial refresh retains richer cached fields without hiding fresh API values');
    $preservedRecord = sblog_douban_subject_read_cache($richPath, 'movie', $richId);
    expect($preservedRecord['next_retry_at'] > time(), 'preserved rich visuals still permit a later enrichment retry');
    $preservedRecord['next_retry_at'] = time() - 1;
    sblog_douban_write_cache($richPath, $preservedRecord);
    $refreshedRich = sblog_douban_subject_data('movie', $richId, static function () use (&$downgradeCalls, $movie, $richId): array {
        $downgradeCalls++; return response(encoded(array_replace($movie, ['id' => $richId, 'intro' => '后来补全的新简介'])));
    });
    expect($downgradeCalls === 3 && $refreshedRich['summary'] === '后来补全的新简介', 'cooldown expiry retries even when stale visual fields made the cached card look complete');
    $expiredRichId = '9100007';
    $expiredRich = sblog_douban_subject_normalize(array_replace($movie, ['id' => $expiredRichId]), 'movie', $expiredRichId);
    sblog_douban_write_cache(sblog_douban_subject_cache_path('movie', $expiredRichId), record($expiredRich, time() - 7 * 86400 - 10));
    $withoutExpired = sblog_douban_subject_data('movie', $expiredRichId, static fn(): array => response(encoded(['id' => $expiredRichId, 'title' => '新标题'])));
    expect($withoutExpired['title'] === '新标题' && $withoutExpired['summary'] === '' && $withoutExpired['cover_url'] === '', 'expired cached visual fields cannot enrich a new partial result');
    $beforeInvalid = count($calls);
    foreach ([['invalid', '1'], ['movie', '../1'], ['movie', '1/?query='], ['movie.douban.com/subject/1/?foo=', '1'], ['movie', '0']] as [$type, $id]) {
        expect(sblog_douban_subject_data($type, $id, $transport) === [], 'invalid type/id rejected before network');
    }
    expect(count($calls) === $beforeInvalid, 'invalid input performs no requests');

    $failureCalls = 0;
    $failure = static function (string $url, float $timeout) use (&$failureCalls): array { $failureCalls++; throw new RuntimeException('unavailable'); };
    $moviePath = sblog_douban_subject_cache_path('movie', '1292052');
    $staleTime = time() - 8 * 3600;
    sblog_douban_write_cache($moviePath, record($parsed, $staleTime));
    expect(sblog_douban_subject_data('movie', '1292052', $failure) === $parsed && $failureCalls === 2, 'upstream failures preserve last success');
    expect(sblog_douban_subject_read_cache($moviePath, 'movie', '1292052')['updated_at'] === $staleTime, 'failed refresh preserves original success time');
    expect(sblog_douban_subject_data('movie', '1292052', $failure) === $parsed && $failureCalls === 2, 'failure cooldown prevents repeated requests');
    sblog_douban_write_cache($moviePath, record($parsed, time() - 7 * 86400 - 10, time() + 200));
    expect(sblog_douban_subject_data('movie', '1292052', $failure) === [] && $failureCalls === 2, 'old stale data expires during cooldown');
    sblog_douban_write_cache($moviePath, record($parsed, time() - 7 * 86400 - 10));
    expect(sblog_douban_subject_data('movie', '1292052', $failure) === [] && $failureCalls === 4, 'expired stale data removed after failed refresh');
    expect(sblog_douban_subject_read_cache($moviePath, 'movie', '1292052')['data'] === [], 'empty failed record persisted');
    expect(sblog_douban_subject_data('movie', '1292052', $failure) === [] && $failureCalls === 4, 'empty failure also observes cooldown');
    expect(sblog_douban_subject_data('music', '1000002', $failure) === [] && $failureCalls === 6, 'first failure safely returns empty data');

    sblog_douban_write_cache($moviePath, record($parsed, $staleTime));
    $lock = fopen($moviePath . '.lock', 'c');
    flock($lock, LOCK_EX);
    try { expect(sblog_douban_subject_data('movie', '1292052', $failure) === $parsed && $failureCalls === 6, 'parallel refresh lock returns stale without another request'); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    $corruptRecords = [record(array_replace($parsed, ['cover_url' => 'https://evil.example/x.jpg']), time()),
        record(array_replace($parsed, ['cover_url' => 'https://img3.doubanio.com/icon/user.jpg']), time()),
        record(array_replace($parsed, ['cover_url' => $officialCover . '?x=1']), time()),
        record(array_replace($parsed, ['id' => '999']), time()), record(array_replace($parsed, ['rating' => '999']), time()),
        record(array_replace($parsed, ['meta' => ['bad' => 'associative']]), time()),
        array_replace(record($parsed, time()), ['next_retry_at' => time() + 2 * 86400]), ['version' => 1, 'data' => []]];
    foreach ($corruptRecords as $bad) {
        file_put_contents($moviePath, encoded($bad));
        expect(sblog_douban_subject_read_cache($moviePath, 'movie', '1292052') === null, 'corrupt cache record rejected');
    }
    file_put_contents($moviePath, '{bad json');
    expect(sblog_douban_subject_read_cache($moviePath, 'movie', '1292052') === null, 'invalid JSON cache rejected');
    foreach (['http://movie.douban.com/subject/1292052/', 'https://evil.example/subject/1292052/',
        'https://m.douban.com/rexxar/api/v2/movie/1292052?extra=1', 'https://movie.douban.com/subject/1292052/?redirect=x'] as $url) {
        expect(sblog_douban_subject_request($url, .1)['ok'] === false, 'transport rejects every nonconstructed URL before cURL');
    }
    echo 'PASS: ' . $checks . " Douban subject checks\n";
} finally {
    restore_error_handler();
    foreach (glob($cacheDirectory . '/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
    rmdir($cacheDirectory);
}
