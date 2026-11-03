<?php

declare(strict_types=1);

// Run with the actual core source; its bootstrap, settings, database and routes are never loaded.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

function gallery_assert(bool $condition, string $message): void
{
    $GLOBALS['gallery_assertions'] = (int)($GLOBALS['gallery_assertions'] ?? 0) + 1;
    if (!$condition) { throw new RuntimeException($message); }
}

function gallery_same(mixed $expected, mixed $actual, string $message): void
{
    gallery_assert($expected === $actual, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

function gallery_load_core(string $path): void
{
    $needed = array_fill_keys([
        'h', 'str_len_u', 'str_sub_u', 'str_lower_u', 'content_kind',
        'parse_reply_hidden_blocks', 'strip_reply_hidden_blocks', 'content_has_reply_hidden_blocks',
        'markdown_to_plain', 'derive_excerpt', 'public_content_description', 'public_content_context',
        'content_requires_password', 'content_password_is_unlocked', 'remember_content_password_unlock',
        'valid_http_url', 'safe_link_url', 'split_bare_url_suffix', 'asset_url', 'app_path', 'app_base_path',
    ], true);
    $tokens = token_get_all((string)file_get_contents($path));
    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) { continue; }
        $nameIndex = $index + 1;
        while ($nameIndex < $count && is_array($tokens[$nameIndex]) && $tokens[$nameIndex][0] === T_WHITESPACE) { $nameIndex++; }
        $name = is_array($tokens[$nameIndex] ?? null) ? $tokens[$nameIndex][1] : '';
        if (!isset($needed[$name])) { continue; }
        $code = '';
        $depth = 0;
        $started = false;
        for (; $index < $count; $index++) {
            $token = $tokens[$index];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $started = true;
            } elseif ($token === '}' && $started && --$depth === 0) { break; }
        }
        eval($code);
        unset($needed[$name]);
    }
    gallery_same([], array_keys($needed), 'The supplied core must provide the actual URL and public visibility helpers.');
}

function gallery_xpath(string $html): DOMXPath
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><main>' . $html . '</main>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}

function gallery_post(string $content, array $extra = []): array
{
    return $extra + ['id' => 7, 'kind' => 'post', 'title' => 'Gallery fixture', 'content' => $content, 'excerpt' => '', 'published_at' => 1793664000];
}

// Unrelated theme services use fixed in-memory values; core URL and visibility behavior above stays real.
function add_theme_filter(string $name, callable $callback, int $priority = 10): void {}
function add_theme_action(string $name, callable $callback, int $priority = 10): void {}
function is_admin(): bool { return (bool)($GLOBALS['gallery_admin'] ?? false); }
function setting(string $name, string $default = ''): string { return (string)($GLOBALS['gallery_settings'][$name] ?? $default); }
function one(string $sql, array $parameters = []): ?array
{
    $sql = trim((string)preg_replace('/\s+/', ' ', $sql));
    if (preg_match('/^SELECT nickname,\s*username FROM users WHERE id = \?$/i', $sql) && count($parameters) === 1) {
        $id = (int)$parameters[0];
    } elseif (preg_match('/^SELECT nickname,\s*username FROM users ORDER BY id ASC LIMIT 1$/i', $sql) && $parameters === []) {
        $id = 0;
    } else {
        throw new RuntimeException('Author fixtures accept only public nickname/username queries.');
    }
    $GLOBALS['gallery_author_queries'][$id] = (int)($GLOBALS['gallery_author_queries'][$id] ?? 0) + 1;
    return $GLOBALS['gallery_users'][$id === 0 ? 1 : $id] ?? null;
}
function all_rows(string $sql, array $parameters = []): array { return []; }
function tag_descriptors(array $post): array { return []; }
function content_permalink(array $post): string { return '/blog/archive/fixture'; }
function url_for(string $action, array $parameters = []): string { return '/blog/?a=' . $action; }
function theme_logo_url(): string { return '/blog/avatar.svg'; }
function public_date(int $timestamp): string { return '2026-11-03'; }
function friendly_public_date(int $timestamp): string { return '2026-11-03'; }
function sblog_t(string $message, array $parameters = []): string { return $message; }

try {
    $options = getopt('', ['core:']);
    $corePath = (string)($options['core'] ?? '');
    gallery_assert($corePath !== '' && is_file($corePath), 'Pass --core=/path/to/SBlog/index.php to run against real core helpers.');
    gallery_assert(class_exists(DOMDocument::class), 'Gallery rendering checks require PHP DOM.');
    $_SERVER['SCRIPT_NAME'] = '/blog/index.php';
    $_GET = [];
    $_SESSION = [];
    $GLOBALS['gallery_settings'] = ['site_name' => 'Fixture site title'];
    $GLOBALS['gallery_users'] = [
        1 => ['nickname' => 'Owner nickname', 'username' => 'owner-login'],
        2 => ['nickname' => '  Writer <script>alert("author")</script> & "name"  ', 'username' => 'second-login'],
        3 => ['nickname' => " \t\n", 'username' => 'writer-login'],
        4 => ['nickname' => '', 'username' => ''],
    ];
    $GLOBALS['gallery_author_queries'] = [];
    gallery_load_core($corePath);
    require dirname(__DIR__) . '/functions.php';

    gallery_same([], sunny_post_images(gallery_post('Text without images.')), 'Text-only posts have no gallery.');
    gallery_same('', sunny_post_cover(gallery_post('Text without images.')), 'Text-only sidebar covers stay absent.');
    gallery_same('', sunny_render_post_gallery([], 'No photos'), 'An absent gallery must not leave empty markup.');
    for ($total = 1; $total <= 10; $total++) {
        $urls = array_map(static fn(int $index): string => 'https://images.example.invalid/photo-' . $index . '.webp', range(1, $total));
        $post = gallery_post(implode("\n", array_map(static fn(string $url): string => '![Photo](' . $url . ')', $urls)));
        gallery_same($urls, sunny_post_images($post), $total . ' images: preserve every original for the viewer.');
        gallery_same($urls[0], sunny_post_cover($post), $total . ' images: existing sidebar cover API returns the first image.');
        $xpath = gallery_xpath(sunny_render_post_gallery(sunny_post_images($post), $post['title']));
        gallery_same(1, $xpath->query('//*[@data-sunny-gallery and @data-count="' . min(9, $total) . '"]')->length, $total . ' images: choose the matching mosaic.');
        gallery_same(min(9, $total), $xpath->query('//*[@data-sunny-gallery]//img')->length, $total . ' images: load no more than nine thumbnails.');
        gallery_same($total, $xpath->query('//*[@data-sunny-gallery]//a[@data-sunny-gallery-image]')->length, $total . ' images: viewer retains all original links.');
        gallery_same(max(0, $total - 9), $xpath->query('//*[@data-sunny-gallery]//a[@hidden and @data-sunny-gallery-image and not(img)]')->length, $total . ' images: overflow originals have no eager image element.');
        $actual = [];
        foreach ($xpath->query('//*[@data-sunny-gallery]//a[@data-sunny-gallery-image]') as $anchor) { $actual[] = $anchor->getAttribute('href'); }
        gallery_same($urls, $actual, $total . ' images: original href order matches the article.');
    }

    $mixed = <<<'MARKDOWN'
<img src="https://images.example.invalid/html-first.jpg">
![Markdown](https://images.example.invalid/markdown-second.webp "Optional title")
https://images.example.invalid/bare-third.png?width=800&height=600.
<img src=/uploads/fourth.gif>
![Relative](uploads/fifth.avif?width=400)
![Parentheses](https://images.example.invalid/sixth(1).jpg)
![Angled](<https://images.example.invalid/seventh.webp?width=900&amp;height=500>)
MARKDOWN;
    $expected = [
        'https://images.example.invalid/html-first.jpg',
        'https://images.example.invalid/markdown-second.webp',
        'https://images.example.invalid/bare-third.png?width=800&height=600',
        '/uploads/fourth.gif',
        '/blog/uploads/fifth.avif?width=400',
        'https://images.example.invalid/sixth(1).jpg',
        'https://images.example.invalid/seventh.webp?width=900&height=500',
    ];
    gallery_same($expected, sunny_post_images(gallery_post($mixed)), 'HTML, Markdown and bare image URLs share document order and normalize URLs.');
    $coverPost = gallery_post($mixed, ['cover_url' => 'javascript:alert(1)', 'cover' => '//images.example.invalid/cover.webp', 'thumbnail' => 'https://images.example.invalid/not-selected.jpg']);
    gallery_same(array_merge(['https://images.example.invalid/cover.webp'], $expected), sunny_post_images($coverPost), 'Use the first valid cover field before article images.');
    gallery_same(
        ['https://images.example.invalid/photo.jpg/thumb.webp?size=2'],
        sunny_post_images(gallery_post("https://images.example.invalid/photo.jpg.html\nhttps://images.example.invalid/photo.jpg/thumb.webp?size=2")),
        'Bare links must match the complete image URL rather than truncating at an earlier extension.'
    );
    $duplicate = gallery_post("![Same](https://images.example.invalid/a.webp?a=1&amp;b=2)\n<img src='https://images.example.invalid/a.webp?a=1&b=2'>\nhttps://images.example.invalid/a.webp?a=1&b=2", ['cover_url' => 'https://images.example.invalid/a.webp?a=1&b=2']);
    gallery_same(['https://images.example.invalid/a.webp?a=1&b=2'], sunny_post_images($duplicate), 'Deduplicate normalized covers and mixed image syntaxes.');

    $unsafe = <<<'MARKDOWN'
![script](javascript:alert(1))
<img src="data:image/png;base64,AAAA">
<img src='vbscript:msgbox(1)'>
![file](file:///private/photo.png)
![path](../private/photo.png)
<img src="https://user:secret@images.example.invalid/private.jpg">
<img src="/\external.example.invalid/escape.jpg">
![fragment](#photo.jpg)
![valid](/uploads/allowed.webp)
MARKDOWN;
    gallery_same(['/uploads/allowed.webp'], sunny_post_images(gallery_post($unsafe)), 'Unsafe schemes, credentials and escaping paths cannot enter a gallery.');

    $excluded = <<<'MARKDOWN'
```markdown
![Fenced](https://images.example.invalid/secret-fence.jpg)
```
~~~html
<img src="https://images.example.invalid/secret-tilde.jpg">
~~~
`![Inline](https://images.example.invalid/secret-inline.jpg)`
<!-- <img src="https://images.example.invalid/secret-comment.jpg"> -->
<pre><img src="https://images.example.invalid/secret-pre.jpg"></pre>
<code>https://images.example.invalid/secret-code.jpg</code>
<script>"https://images.example.invalid/secret-script.jpg"</script>
<style>.fixture { background: url(https://images.example.invalid/secret-style.jpg); }</style>
![Visible](https://images.example.invalid/visible.jpg)
MARKDOWN;
    gallery_same(['https://images.example.invalid/visible.jpg'], sunny_post_images(gallery_post($excluded)), 'Code examples, HTML comments and executable elements are not article photos.');
    gallery_same([], sunny_post_images(gallery_post("```\nhttps://images.example.invalid/unclosed.jpg")), 'An unclosed fenced example must not produce a photo.');

    $hiddenPost = gallery_post("![Public](https://images.example.invalid/public.jpg)\n[reply]\n![Private](https://images.example.invalid/reply-secret.jpg)\n[/reply]\n<img src='/uploads/public-after.webp'>");
    $expectedPublic = ['https://images.example.invalid/public.jpg', '/uploads/public-after.webp'];
    gallery_same($expectedPublic, sunny_post_images($hiddenPost), 'Reply-only image URLs remain outside public listing galleries.');
    gallery_same($expectedPublic, sunny_post_images(public_content_context($hiddenPost)), 'Already-public core listing rows keep the same photos.');
    gallery_assert(!str_contains(sunny_render_card($hiddenPost), 'reply-secret.jpg'), 'Reply-only original URLs must not leak through card markup.');

    $passwordPost = gallery_post('![Private](https://images.example.invalid/password-secret.jpg)', ['content_password_hash' => 'fixture-password-hash', 'cover_url' => 'https://images.example.invalid/password-cover.jpg']);
    foreach (['locked', 'unlocked', 'admin'] as $state) {
        $_SESSION = [];
        $GLOBALS['gallery_admin'] = $state === 'admin';
        if ($state === 'unlocked') {
            remember_content_password_unlock($passwordPost);
            gallery_assert(content_password_is_unlocked($passwordPost), 'Fixture uses the actual core password-unlock state.');
        }
        gallery_same([], sunny_post_images($passwordPost), $state . ': password-protected article photos stay out of public listings.');
        gallery_same('', sunny_post_cover($passwordPost), $state . ': password-protected sidebar covers stay absent.');
        gallery_assert(!str_contains(sunny_render_card($passwordPost), 'password-secret.jpg'), $state . ': protected body URL must not leak through card markup.');
        gallery_assert(!str_contains(sunny_render_card($passwordPost), 'password-cover.jpg'), $state . ': protected cover URL must not leak through card markup.');
    }
    $GLOBALS['gallery_admin'] = false;

    $escapedUrl = 'https://images.example.invalid/escaped.webp?a=1&b=2';
    $escapedHtml = sunny_render_post_gallery([$escapedUrl], '"><script>alert("title")</script>');
    $escapedXpath = gallery_xpath($escapedHtml);
    gallery_same(0, $escapedXpath->query('//script | //*[@onerror or @onclick]')->length, 'Article titles cannot inject gallery markup or handlers.');
    gallery_same($escapedUrl, $escapedXpath->query('//*[@data-sunny-gallery-image]')->item(0)->getAttribute('href'), 'Escaped href preserves the original query.');
    gallery_same($escapedUrl, $escapedXpath->query('//*[@data-sunny-gallery]//img')->item(0)->getAttribute('src'), 'Escaped thumbnail preserves the original query.');
    gallery_assert(str_contains($escapedHtml, '?a=1&amp;b=2'), 'URL ampersands are encoded in HTML attributes.');

    $many = array_map(static fn(int $index): string => 'https://images.example.invalid/overflow-' . $index . '.jpg', range(1, 12));
    $manyXpath = gallery_xpath(sunny_render_post_gallery($many, 'Twelve photos'));
    gallery_same(9, $manyXpath->query('//*[@data-sunny-gallery]//img')->length, 'Large galleries still load nine thumbnails.');
    gallery_same(3, $manyXpath->query('//*[@data-sunny-gallery]//a[@hidden and not(img)]')->length, 'All three overflow originals remain available to the viewer.');
    gallery_same('+3', trim($manyXpath->query('//*[@data-sunny-gallery]//a[9]/span')->item(0)?->textContent ?? ''), 'The ninth image shows the remaining photo count.');

    gallery_same('Owner nickname', sunny_author_name(), 'The sidebar owner uses the profile nickname instead of the site title.');
    $writerName = 'Writer <script>alert("author")</script> & "name"';
    gallery_same($writerName, sunny_author_name(2), 'A different article author uses their own trimmed nickname.');
    gallery_same('writer-login', sunny_author_name(3), 'An empty nickname falls back to that author\'s username.');
    gallery_same('Owner nickname', sunny_author_name(99), 'A deleted or unknown author falls back to the site owner.');
    gallery_same('Owner nickname', sunny_author_name(4), 'An author without either public name falls back to the site owner.');
    gallery_same($writerName, sunny_author_name(2), 'Repeated author lookups preserve the same display name.');
    $authorHtml = sunny_render_card(gallery_post('Author card fixture.', ['author_id' => 2]));
    $authorXpath = gallery_xpath($authorHtml);
    gallery_same($writerName, $authorXpath->query('//a[@class="sunny-post-author-name"]')->item(0)?->textContent, 'Cards display the article author profile instead of the owner or site title.');
    gallery_same($writerName, $authorXpath->query('//a[@class="sunny-post-avatar"]')->item(0)?->getAttribute('aria-label'), 'The avatar accessible label uses the same author name.');
    gallery_same(0, $authorXpath->query('//script')->length, 'Author nicknames cannot inject script markup into cards.');
    gallery_assert(str_contains($authorHtml, '&lt;script&gt;alert(&quot;author&quot;)&lt;/script&gt;'), 'Author names are escaped in generated card markup.');
    gallery_same(1, $GLOBALS['gallery_author_queries'][2] ?? 0, 'Repeated lookups and card rendering query each author only once per request.');
    gallery_same(1, $GLOBALS['gallery_author_queries'][0] ?? 0, 'Owner fallbacks reuse one cached public profile query.');

    echo 'Sunny gallery extraction, public visibility, authors and markup: ' . $GLOBALS['gallery_assertions'] . " assertions passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Sunny gallery test failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
