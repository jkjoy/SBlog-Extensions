<?php
declare(strict_types=1);

function rest_api_test_fail(string $message): never
{
    fwrite(STDERR, "REST API content-protection test failed: {$message}\n");
    exit(1);
}

function content_kind(array $row): string
{
    return (string)($row['kind'] ?? 'post');
}

function content_requires_password(array $row): bool
{
    return content_kind($row) === 'post' && trim((string)($row['content_password_hash'] ?? '')) !== '';
}

function content_has_reply_hidden_blocks(string $markdown): bool
{
    return preg_match('/^\[reply\]$.*?^\[\/reply\]$/ms', $markdown) === 1;
}

function public_content_context(array $row): array
{
    if (content_requires_password($row)) {
        $row['content'] = '';
        $row['excerpt'] = '此文章受密码保护。';
    } elseif (content_has_reply_hidden_blocks((string)($row['content'] ?? ''))) {
        $row['content'] = trim((string)preg_replace('/^\[reply\]$.*?^\[\/reply\]$/ms', '', (string)$row['content']));
        $row['excerpt'] = '公开段落';
    }
    unset($row['content_password_hash']);
    return $row;
}

function str_lower_u(string $value): string
{
    return strtolower($value);
}

function post_tags(array $post): array
{
    return [];
}

function content_permalink(array $post): string
{
    return '/archive/' . (int)$post['id'];
}

function absolute_url(string $url): string
{
    return 'https://example.test' . $url;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function markdown_to_html(string $markdown): string
{
    return $markdown === '' ? '' : '<p>' . h($markdown) . '</p>';
}

function markdown_to_plain(string $markdown): string
{
    return trim(strip_tags($markdown));
}

function content_allows_comments(array $post): bool
{
    return (int)($post['allow_comments'] ?? 0) === 1;
}

function sblog_rest_api_url(string $route = '/'): string
{
    return 'https://example.test/wp-json/' . ltrim($route, '/');
}

function sblog_rest_api_bool(mixed $value, bool $default = false): bool
{
    return $value === null || $value === '' ? $default : (bool)filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function sblog_rest_api_param(string $name, mixed $default = null): mixed
{
    return $GLOBALS['rest_api_test_params'][$name] ?? $default;
}

function sblog_rest_api_int_list(mixed $value): array
{
    $parts = is_array($value) ? $value : preg_split('/\s*,\s*/', trim((string)$value));
    return is_array($parts)
        ? array_values(array_unique(array_filter(array_map('intval', $parts), static fn(int $id): bool => $id > 0)))
        : [];
}

function sblog_rest_api_pagination(): array
{
    $page = (int)($GLOBALS['rest_api_test_page'] ?? 1);
    $perPage = (int)($GLOBALS['rest_api_test_per_page'] ?? 10);
    return [$page, $perPage, ($page - 1) * $perPage];
}

function sblog_rest_api_user(bool $required = false): ?array
{
    return null;
}

function all_rows(string $sql, array $params = []): array
{
    if (!isset($GLOBALS['rest_api_test_rows'])) {
        return [];
    }
    preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $sql, $matches);
    $limit = isset($matches[1]) ? (int)$matches[1] : count($GLOBALS['rest_api_test_rows']);
    $offset = isset($matches[2]) ? (int)$matches[2] : 0;
    return array_slice($GLOBALS['rest_api_test_rows'], $offset, $limit);
}

function val(string $sql, array $params = []): mixed
{
    return 0;
}

function sblog_rest_api_collection(mixed $items, int $total, int $page, int $perPage, string $route): never
{
    $GLOBALS['rest_api_test_collection'] = compact('items', 'total', 'page', 'perPage', 'route');
    throw new RuntimeException('REST collection captured.');
}

require dirname(__DIR__) . '/plugins/rest-api/includes/resources.php';

$sqlite = new PDO('sqlite::memory:');
$sqlite->exec('CREATE TABLE search_fixture(title TEXT, excerpt TEXT, content TEXT)');
$sqlite->exec("INSERT INTO search_fixture VALUES ('100% public', '', '')");
$searchStatement = $sqlite->prepare(
    "SELECT COUNT(*) FROM search_fixture p WHERE (p.title LIKE ? ESCAPE '\\' OR p.excerpt LIKE ? ESCAPE '\\' OR p.content LIKE ? ESCAPE '\\')"
);
$searchStatement->execute(['%100\%%', '%100\%%', '%100\%%']);
if ((int)$searchStatement->fetchColumn() !== 1) {
    rest_api_test_fail('SQLite search prefilter did not treat escaped wildcards literally.');
}

$base = [
    'id' => 7,
    'author_id' => 1,
    'kind' => 'post',
    'post_format' => 'text',
    'category_id' => 0,
    'slug' => 'protected-post',
    'title' => '公开标题',
    'tags' => '[]',
    'status' => 'published',
    'published_at' => time() - 60,
    'updated_at' => time() - 30,
    'is_pinned' => 0,
    'allow_comments' => 1,
];

$passwordPost = $base + [
    'content' => 'password-secret-needle',
    'excerpt' => 'password-secret-summary',
    'content_password_hash' => 'hash',
];
$publicPassword = sblog_rest_api_prepare_content($passwordPost, false);
if (!$publicPassword['content']['protected'] || !$publicPassword['excerpt']['protected']) {
    rest_api_test_fail('password protection was not exposed through protected flags.');
}
if (str_contains(json_encode($publicPassword, JSON_UNESCAPED_UNICODE) ?: '', 'password-secret')) {
    rest_api_test_fail('view context exposed password-protected content or excerpt.');
}
if (sblog_rest_api_content_matches_search($passwordPost, 'password-secret-needle', false)) {
    rest_api_test_fail('view search matched password-protected content.');
}
if (!sblog_rest_api_content_matches_search($passwordPost, 'password-secret-needle', true)) {
    rest_api_test_fail('edit search could not match original password-protected content.');
}
$editPassword = sblog_rest_api_prepare_content($passwordPost, true);
if (($editPassword['content']['raw'] ?? '') !== 'password-secret-needle'
    || !str_contains((string)$editPassword['content']['rendered'], 'password-secret-needle')) {
    rest_api_test_fail('edit context did not retain original password-protected content.');
}

$replyPost = $base + [
    'content' => "公开段落\n[reply]\nreply-secret-needle\n[/reply]\n公开结尾",
    'excerpt' => 'reply-secret-summary',
    'content_password_hash' => '',
];
$publicReply = sblog_rest_api_prepare_content($replyPost, false);
$encodedReply = json_encode($publicReply, JSON_UNESCAPED_UNICODE) ?: '';
if (!$publicReply['content']['protected'] || str_contains($encodedReply, 'reply-secret')) {
    rest_api_test_fail('view context exposed reply-hidden content or excerpt.');
}
if (!str_contains($encodedReply, '公开段落') || !str_contains($encodedReply, '公开结尾')) {
    rest_api_test_fail('view context removed public sections around reply-hidden content.');
}
if (sblog_rest_api_content_matches_search($replyPost, 'reply-secret-needle', false)) {
    rest_api_test_fail('view search matched reply-hidden content.');
}
if (!sblog_rest_api_content_matches_search($replyPost, '公开结尾', false)) {
    rest_api_test_fail('view search could not match public content around a hidden block.');
}

$publicPost = $base + [
    'content' => 'fully public body',
    'excerpt' => 'fully public summary',
    'content_password_hash' => '',
];
$preparedPublic = sblog_rest_api_prepare_content($publicPost, false);
if ($preparedPublic['content']['protected'] || !sblog_rest_api_content_matches_search($publicPost, 'public body', false)) {
    rest_api_test_fail('ordinary public content behavior changed.');
}

$fixtureRows = [];
for ($index = 0; $index < 101; $index++) {
    $fixtureRows[] = array_merge($base, [
        'id' => 1000 + $index,
        'content' => $index === 0
            ? "公开前缀\n[reply]\nhidden-needle\n[/reply]"
            : ($index === 100 ? 'visible needle' : 'no match'),
        'excerpt' => '',
        'content_password_hash' => '',
        'published_at' => time() - 1000 - $index,
    ]);
}
$GLOBALS['rest_api_test_rows'] = $fixtureRows;
$GLOBALS['rest_api_test_params'] = [
    'search' => 'needle',
    'context' => 'view',
    'status' => 'publish',
    'order' => 'desc',
    'orderby' => 'date',
];
$GLOBALS['rest_api_test_page'] = 1;
$GLOBALS['rest_api_test_per_page'] = 1;
try {
    sblog_rest_api_content_collection('post', false);
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'REST collection captured.') {
        throw $exception;
    }
}
$collection = $GLOBALS['rest_api_test_collection'] ?? null;
if (!is_array($collection) || (int)$collection['total'] !== 1 || count($collection['items']) !== 1
    || (int)$collection['items'][0]['id'] !== 1100) {
    rest_api_test_fail('batched public search did not exclude hidden matches or preserve pagination.');
}

echo "REST API content-protection tests passed.\n";
