<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

/** @return array<int, array{count: int, liked: bool}> */
function article_likes_states(array $postIds): array
{
    $states = [];
    foreach ($postIds as $postId) {
        if (!is_int($postId) && !is_string($postId)) {
            continue;
        }
        $id = filter_var($postId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false) {
            $states[$id] = ['count' => 0, 'liked' => false];
        }
    }
    if ($states === []) {
        return [];
    }

    $visitor = client_ip_hash();
    foreach (array_chunk(array_keys($states), 500) as $ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = all_rows(
            'SELECT p.id, COUNT(l.ip_hash) AS like_count,
                    MAX(CASE WHEN l.ip_hash = ? THEN 1 ELSE 0 END) AS visitor_liked
             FROM posts p LEFT JOIN post_likes l ON l.post_id = p.id
             WHERE p.id IN (' . $placeholders . ')
               AND p.kind = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ?
             GROUP BY p.id',
            array_merge([$visitor], $ids, ['post', 'published', time()])
        );
        foreach ($rows as $row) {
            $states[(int)$row['id']] = ['count' => (int)$row['like_count'], 'liked' => (bool)$row['visitor_liked']];
        }
    }
    return $states;
}

function article_likes_button(int $postId, ?array $state = null): string
{
    if ($postId < 1) {
        return '';
    }
    $state ??= article_likes_states([$postId])[$postId];
    $liked = !empty($state['liked']);
    $count = max(0, (int)($state['count'] ?? 0));

    // A visitor's vote state and CSRF token must not enter a shared page cache.
    if (function_exists('spc_release_plan_lock')) {
        spc_release_plan_lock();
        unset($GLOBALS['sblog_static_page_cache_plan']);
    }
    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
        header('Vary: Cookie', false);
    }

    return '<button class="article-likes-button" type="button" data-article-like'
        . ' data-post-id="' . $postId . '"'
        . ' data-like-endpoint="' . h(url_with_query(script_url(), ['a' => 'article_likes_vote'])) . '"'
        . ' data-like-csrf="' . h(csrf_token()) . '"'
        . ' aria-pressed="' . ($liked ? 'true' : 'false') . '"'
        . ' aria-label="' . ($liked ? '已点赞这篇文章' : '点赞这篇文章') . '">'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"/></svg>'
        . '<span data-like-label>' . ($liked ? '已点赞' : '点赞') . '</span>'
        . '<b data-like-count>' . $count . '</b></button>';
}

function article_likes_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('Vary: Cookie', false);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function article_likes_handle_vote(): never
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        header('Allow: POST');
        article_likes_json(['ok' => false, 'error' => '请使用 POST 请求。'], 405);
    }
    $token = $_POST['csrf_token'] ?? null;
    $sessionToken = $_SESSION['csrf_token'] ?? null;
    if (!is_string($token) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        article_likes_json(['ok' => false, 'error' => '请求已失效，请刷新页面后重试。'], 422);
    }

    $rawId = $_POST['post_id'] ?? null;
    $postId = is_int($rawId) || is_string($rawId)
        ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    if ($postId === false) {
        article_likes_json(['ok' => false, 'error' => '文章不存在。'], 404);
    }
    try {
        $result = like_post_for_visitor($postId);
        if (empty($result['ok'])) {
            article_likes_json(['ok' => false, 'error' => '文章不存在。'], 404);
        }
        article_likes_json([
            'ok' => true,
            'post_id' => $postId,
            'count' => (int)$result['count'],
            'liked' => (bool)$result['liked'],
            'created' => (bool)$result['created'],
        ]);
    } catch (Throwable $exception) {
        error_log('Article likes vote failed: ' . $exception->getMessage());
        article_likes_json(['ok' => false, 'error' => '点赞暂时无法保存，请稍后重试。'], 500);
    }
}

add_plugin_action('request', static function (array $context): void {
    if (($context['action'] ?? '') === 'article_likes_vote') {
        article_likes_handle_vote();
    }
});

add_theme_action('head', static function (): string {
    return '<link rel="stylesheet" href="' . h(plugin_asset_url('article-likes', 'assets/article-likes.css')) . '?v=1.0.0">';
}, 30);

add_theme_action('body_close', static function (): string {
    return '<p class="article-likes-status" data-article-likes-status role="status" aria-live="polite" aria-atomic="true"></p>'
        . '<noscript><p class="article-likes-nojs">启用 JavaScript 后即可为文章点赞。</p></noscript>'
        . '<script src="' . h(plugin_asset_url('article-likes', 'assets/article-likes.js')) . '?v=1.0.0" defer></script>';
}, 30);
