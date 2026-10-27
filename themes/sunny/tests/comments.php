<?php

declare(strict_types=1);

// Run: php themes/sunny/tests/comments.php [--core=/path/to/SBlog/index.php]
// The optional core renderer is extracted without executing its bootstrap or database code.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

function sunny_test_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function sunny_test_same(mixed $expected, mixed $actual, string $message): void
{
    sunny_test_assert($expected === $actual, $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}
function add_theme_filter(string $name, callable $callback, int $priority = 10): void { $GLOBALS['sunny_test_filters'][$name][$priority][] = $callback; }
function add_theme_action(string $name, callable $callback, int $priority = 10): void { $GLOBALS['sunny_test_actions'][$name][$priority][] = $callback; }
function theme_filter(string $name, mixed $value, array $context = []): mixed
{
    if (empty($GLOBALS['sunny_test_enabled'])) { return $value; }
    $priorities = $GLOBALS['sunny_test_filters'][$name] ?? [];
    ksort($priorities);
    foreach ($priorities as $callbacks) { foreach ($callbacks as $callback) { $value = $callback($value, $context); } }
    return $value;
}
function sunny_test_action(string $name): string
{
    $result = '';
    $priorities = $GLOBALS['sunny_test_actions'][$name] ?? [];
    ksort($priorities);
    foreach ($priorities as $callbacks) { foreach ($callbacks as $callback) { $result .= (string)$callback([]); } }
    return $result;
}
function authenticated_comment_identity(): ?array { return $GLOBALS['sunny_test_authenticated'] ?? null; }
function gravatar_url(string $email, int $size = 72): string { return $GLOBALS['sunny_test_avatar'] ?? ('https://example.invalid/avatar/' . hash('sha256', strtolower(trim($email))) . '?s=' . $size); }
function public_comments_for_post(int $postId, int $limit = 100): array
{
    sunny_test_same(7, $postId, 'The theme must request comments for the current post.');
    return $GLOBALS['sunny_test_comments'];
}
function setting(string $name, string $default = ''): string { return (string)($GLOBALS['sunny_test_settings'][$name] ?? $default); }
function approved_comment_count(int $postId): int { return (int)($GLOBALS['sunny_test_total'] ?? count($GLOBALS['sunny_test_comments'])); }
function public_content_context(array $post): array { unset($post['content_password_hash']); return $post; }
function content_allows_comments(array $post): bool { return true; }
function is_live_content(array $post): bool { return true; }
function pull_comment_notice(int $postId): ?array { return $GLOBALS['sunny_test_notice'] ?? null; }
function approved_reply_target(int $postId, int $parentId): ?array
{
    foreach ($GLOBALS['sunny_test_comments'] as $comment) { if ((int)$comment['id'] === $parentId) { return $comment; } }
    return null;
}
function load_active_theme(): void {}
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function safe_link_url(string $url): string { return preg_match('#^https?://#', $url) ? $url : '#'; }
function sblog_t(string $message, array $parameters = []): string
{
    foreach ($parameters as $key => $value) { $message = str_replace('{' . $key . '}', (string)$value, $message); }
    return $message;
}
function sblog_tn(string $message, int $count, array $parameters = []): string { return sblog_t($message, ['count' => $count] + $parameters); }
function pretty_date(int $timestamp, bool $withTime = false): string { return '2026-10-27 10:00'; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="fixture-csrf-token">'; }
function comment_form_started_at(int $postId): int { return 1793066400; }
function url_for(string $action, array $parameters = []): string { return '/?a=' . $action; }
function plugin_filter(string $name, mixed $value, array $context = []): mixed
{
    $GLOBALS['sunny_test_plugin_calls'][] = [$name, (int)$context['comment']['id'], array_column($context['comments'], 'id')];
    return '<span data-fixture-hook="' . h($name) . '">Plugin</span>';
}

function sunny_test_reset(): void
{
    unset($GLOBALS['sunny_comment_layout'], $GLOBALS['sunny_test_avatar']);
    $_SESSION = [];
    $GLOBALS['sunny_test_enabled'] = true;
    $GLOBALS['sunny_test_authenticated'] = null;
    $GLOBALS['sunny_test_settings'] = ['comments_enabled' => '1'];
    $GLOBALS['sunny_test_total'] = 2;
    $GLOBALS['sunny_test_notice'] = null;
    $GLOBALS['sunny_test_plugin_calls'] = [];
    $GLOBALS['sunny_test_comments'] = [
        ['id' => 11, 'parent_id' => 0, 'user_id' => null, 'reply_to_name' => '', 'author_name' => 'Visitor <script>', 'author_email' => 'private-visitor@example.invalid', 'author_url' => 'https://example.invalid/visitor', 'content' => 'Private payload body marker <script>alert(1)</script>', 'ip_address' => '192.0.2.88', 'user_agent' => 'Private user agent marker', 'created_at' => 1793066400],
        ['id' => 12, 'parent_id' => 11, 'user_id' => 1, 'reply_to_name' => 'Visitor <script>', 'author_name' => 'Owner', 'author_email' => 'private-owner@example.invalid', 'author_url' => '', 'content' => 'Another payload body marker', 'ip_address' => '192.0.2.89', 'user_agent' => 'Another private agent', 'created_at' => 1793066500],
    ];
}
function sunny_test_labels(): array
{
    return ['title' => 'Comments', 'form_title' => 'Leave a reply', 'submit' => 'Submit', 'cancel_reply' => 'Cancel reply', 'cancel_reply_aria' => 'Cancel reply accessibly', 'empty' => 'No comments yet', 'closed' => 'Comments closed', 'extension_label' => 'Preserve me'];
}
function sunny_test_payload(string $html): array
{
    sunny_test_same(1, preg_match_all('#<script type="application/json" id="sunny-comments-data">(.*?)</script>#s', $html, $matches), 'There must be exactly one JSON payload.');
    $payload = json_decode($matches[1][0], true, 512, JSON_THROW_ON_ERROR);
    sunny_test_same(['total', 'avatar', 'comments'], array_keys($payload), 'Only layout data may be serialized.');
    foreach ($payload['comments'] as $comment) {
        sunny_test_same(['id', 'parent', 'owner'], array_keys($comment), 'Comment payload must contain only relationship data.');
        sunny_test_assert(is_int($comment['id']) && is_int($comment['parent']) && is_bool($comment['owner']), 'Relationship data must use normalized types.');
    }
    foreach (['private-visitor@example.invalid', 'private-owner@example.invalid', 'Private payload body marker', '192.0.2.88', 'Private user agent marker', 'Visitor <script>'] as $privateValue) {
        sunny_test_assert(!str_contains($html, $privateValue), 'Private comment fields must not enter layout JSON: ' . $privateValue);
    }
    return $payload;
}
function sunny_test_load_core(string $path): void
{
    $needed = array_fill_keys(['render_comments_section', 'render_comment_identity', 'render_comment_meta', 'comment_error_catalog'], true);
    $tokens = token_get_all((string)file_get_contents($path));
    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) { continue; }
        $name = $tokens[$index + 2][1] ?? '';
        if (!isset($needed[$name])) { continue; }
        $code = '';
        $depth = 0;
        $started = false;
        for (; $index < $count; $index++) {
            $token = $tokens[$index];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; $started = true; }
            elseif ($token === '}' && --$depth === 0 && $started) { break; }
        }
        eval($code);
        unset($needed[$name]);
    }
    sunny_test_same([], array_keys($needed), 'The supplied core must provide the actual comment renderer and hook helpers.');
}
function sunny_test_xpath(string $html): DOMXPath
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}
function sunny_test_form_signature(string $html): array
{
    $xpath = sunny_test_xpath($html);
    $signature = [];
    foreach ($xpath->query('//form | //form//input | //form//textarea | //form//label') as $node) {
        $attributes = [];
        foreach ($node->attributes as $attribute) { $attributes[$attribute->name] = $attribute->value; }
        ksort($attributes);
        $signature[] = [$node->nodeName, $attributes, $node->nodeName === 'textarea' ? $node->textContent : ''];
    }
    return $signature;
}

require dirname(__DIR__) . '/functions.php';

try {
    sunny_test_reset();
    sunny_test_same('', sunny_test_action('body_close'), 'Pages without a rendered comment section must not emit comment data.');
    $labels = sunny_test_labels();
    $actualLabels = theme_filter('comments_labels', $labels, ['post' => ['id' => 7], 'total' => 2, 'accepting' => true]);
    foreach (['form_title', 'cancel_reply_aria', 'empty', 'closed', 'extension_label'] as $key) { sunny_test_same($labels[$key], $actualLabels[$key], 'Unchanged labels and extension keys must survive.'); }
    sunny_test_same('评论：', $actualLabels['title'], 'Sunny comment heading.');
    sunny_test_same('发表', $actualLabels['submit'], 'Sunny submit label.');
    sunny_test_same('取消', $actualLabels['cancel_reply'], 'Sunny cancel label.');
    $payload = sunny_test_payload(sunny_test_action('body_close'));
    sunny_test_same([['id' => 11, 'parent' => 0, 'owner' => false], ['id' => 12, 'parent' => 11, 'owner' => true]], $payload['comments'], 'Thread relationships and owner identity must match the public records.');
    sunny_test_same('', $payload['avatar'], 'First-time anonymous visitors have no email-based avatar.');

    foreach (['remembered', 'authenticated'] as $identityMode) {
        sunny_test_reset();
        $_SESSION['comment_identity'] = ['author_email' => 'remembered@example.invalid'];
        if ($identityMode === 'authenticated') { $GLOBALS['sunny_test_authenticated'] = ['author_email' => 'authenticated@example.invalid']; }
        theme_filter('comments_labels', $labels, ['post' => ['id' => 7], 'total' => 137, 'accepting' => true]);
        $payload = sunny_test_payload(sunny_test_action('body_close'));
        sunny_test_same(137, $payload['total'], 'Total must preserve counts beyond the visible window.');
        sunny_test_same(gravatar_url($identityMode . '@example.invalid', 96), $payload['avatar'], 'Authenticated identity must take precedence over the remembered visitor.');
    }
    sunny_test_reset();
    $_SESSION['comment_identity'] = ['author_email' => 'visitor@example.invalid'];
    $GLOBALS['sunny_test_avatar'] = 'https://example.invalid/</script><script>alert("avatar")</script>?a=1&b=2';
    theme_filter('comments_labels', $labels, ['post' => ['id' => 7], 'total' => 2]);
    $jsonHtml = sunny_test_action('body_close');
    sunny_test_same(1, substr_count($jsonHtml, '</script>'), 'Avatar values must not terminate the JSON script element.');
    sunny_test_same($GLOBALS['sunny_test_avatar'], sunny_test_payload($jsonHtml)['avatar'], 'Safe JSON encoding must preserve the avatar value.');
    echo "Sunny comment labels and private layout payload: passed.\n";

    $options = getopt('', ['core:']);
    $corePath = (string)($options['core'] ?? '');
    if ($corePath === '') {
        echo "Core integration skipped; pass --core=/path/to/SBlog/index.php to test real form and comment states.\n";
        exit(0);
    }
    sunny_test_assert(is_file($corePath), 'Core index.php was not found: ' . $corePath);
    sunny_test_assert(class_exists(DOMDocument::class), 'Core integration requires PHP DOM.');
    sunny_test_load_core($corePath);
    foreach (['guest', 'admin', 'empty', 'closed', 'closed-empty', 'error', 'reply', 'notice', 'limited'] as $scenario) {
        sunny_test_reset();
        if (in_array($scenario, ['empty', 'closed-empty'], true)) { $GLOBALS['sunny_test_comments'] = []; $GLOBALS['sunny_test_total'] = 0; }
        if (in_array($scenario, ['closed', 'closed-empty'], true)) { $GLOBALS['sunny_test_settings']['comments_enabled'] = '0'; }
        if ($scenario === 'admin') { $GLOBALS['sunny_test_authenticated'] = ['user_id' => 1, 'author_name' => 'Owner', 'author_email' => 'owner@example.invalid', 'author_url' => '']; }
        if ($scenario === 'notice') { $GLOBALS['sunny_test_notice'] = ['type' => 'success', 'message' => 'Awaiting moderation']; }
        if ($scenario === 'limited') { $GLOBALS['sunny_test_total'] = 137; }
        $form = in_array($scenario, ['reply', 'error'], true) ? ['parent_id' => 11, 'content' => 'Saved draft', 'author_email' => 'bad-email'] : [];
        $errors = $scenario === 'error' ? ['请填写有效的邮箱地址。'] : [];
        $GLOBALS['sunny_test_enabled'] = false;
        $baseline = render_comments_section(['id' => 7], $form, $errors);
        $GLOBALS['sunny_test_plugin_calls'] = [];
        $GLOBALS['sunny_test_enabled'] = true;
        $html = render_comments_section(['id' => 7], $form, $errors);
        $jsonHtml = sunny_test_action('body_close');
        sunny_test_same(sunny_test_form_signature($baseline), sunny_test_form_signature($html), $scenario . ': theme registration must preserve the core form and all field attributes.');
        if ($scenario === 'closed-empty') {
            sunny_test_same('', trim($html), 'Closed content without comments must remain absent.');
            sunny_test_same('', $jsonHtml, 'An absent section must not emit layout data.');
            continue;
        }
        $payload = sunny_test_payload($jsonHtml);
        sunny_test_same($GLOBALS['sunny_test_total'], $payload['total'], $scenario . ': total count.');
        sunny_test_same(count($GLOBALS['sunny_test_comments']), count($payload['comments']), $scenario . ': visible records.');
        $xpath = sunny_test_xpath($html);
        $hasForm = $scenario !== 'closed';
        sunny_test_same($hasForm ? 1 : 0, $xpath->query('//form[@id="comment-form"]')->length, $scenario . ': accepting/closed form state.');
        if ($hasForm) {
            foreach (['csrf_token', 'post_id', 'parent_id', 'comment_started_at', 'company'] as $name) { sunny_test_same(1, $xpath->query('//form//input[@name="' . $name . '"]')->length, $scenario . ': core field ' . $name); }
            sunny_test_same(1, $xpath->query('//form//textarea[@id="comment-content" and @name="content" and @required]')->length, $scenario . ': content control.');
            sunny_test_same($scenario === 'admin' ? 0 : 1, $xpath->query('//input[@name="author_email"]')->length, $scenario . ': authenticated identity state.');
        }
        if ($scenario === 'error') { sunny_test_same(1, $xpath->query('//input[@name="author_email" and @aria-invalid="true" and @aria-describedby="comment-errors"]')->length, 'Errors must retain invalid-field associations.'); }
        if ($scenario === 'reply') { sunny_test_same(1, $xpath->query('//input[@data-comment-parent-id and @value="11"]')->length, 'Server-selected reply must survive.'); }
        if ($scenario === 'empty') { sunny_test_same(1, $xpath->query('//*[@data-comment-state="empty"]')->length, 'Empty state must survive.'); }
        if ($scenario === 'closed') { sunny_test_same(1, $xpath->query('//*[@data-comment-state="closed"]')->length, 'Closed state must survive.'); }
        if ($scenario === 'notice') { sunny_test_same(1, $xpath->query('//*[@role="status" and contains(., "Awaiting moderation")]')->length, 'Moderation notice must remain live.'); }
        $ids = array_column($GLOBALS['sunny_test_comments'], 'id');
        sunny_test_same(count($ids) * 2, count($GLOBALS['sunny_test_plugin_calls']), $scenario . ': both plugin hooks run for each visible comment.');
        foreach ($GLOBALS['sunny_test_plugin_calls'] as [$hook, $id, $contextIds]) { sunny_test_same($ids, $contextIds, $scenario . ': hooks must receive the full visible comment list.'); }
        sunny_test_same(count($ids), $xpath->query('//*[@data-fixture-hook="comment_identity_html"]')->length, $scenario . ': identity hook output remains.');
        sunny_test_same(count($ids), $xpath->query('//*[@data-fixture-hook="comment_meta_html"]')->length, $scenario . ': metadata hook output remains.');
    }
    echo "Sunny real-core comment integration (9 states): passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Sunny comment test failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
