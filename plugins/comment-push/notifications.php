<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) { http_response_code(403); exit; }

function sblog_push_text(string $value, int $limit): string
{
    $text = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $text = trim((string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $text));
    // preg_split keeps UTF-8 boundaries even on servers without mbstring.
    $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($characters)) { return ''; }
    return count($characters) > $limit ? implode('', array_slice($characters, 0, $limit)) . '…' : $text;
}

function sblog_push_comment_text(array $post, array $comment, array $config): string
{
    $text = '新评论 · ' . sblog_push_text(setting('site_name', 'Simple PHP Blog'), 60)
        . "\n文章：" . sblog_push_text((string)$post['title'], 100)
        . "\n状态：" . ($comment['status'] === 'approved' ? '已发布' : '待审核')
        . "\n评论人：" . sblog_push_text((string)$comment['author_name'], 50)
        . "\n\n" . sblog_push_text((string)$comment['content'], 400);
    if ($config['include_link']) {
        $anchor = $comment['status'] === 'approved' ? '#comment-' . (int)$comment['id'] : '#comments';
        $text .= "\n\n" . absolute_url(content_permalink($post)) . $anchor;
    }
    return $text;
}

function sblog_push_deliver(string $channel, array $config, string $text, ?callable $transport = null): array
{
    try {
        match ($channel) {
            'qq' => sblog_push_qq_send($config, $text, $transport),
            'weixin' => sblog_push_weixin_send($config, $text, $transport),
            default => throw new RuntimeException('无效的通知渠道。'),
        };
        $result = ['ok' => true, 'message' => ($channel === 'qq' ? 'QQ' : '微信') . '接口已接受通知。', 'time' => time()];
    } catch (Throwable $exception) {
        // Only our fixed protocol errors are suitable for the admin page; PDO and other errors can expose internals.
        $message = get_class($exception) === RuntimeException::class ? $exception->getMessage() : '通知发送失败，请检查数据库和服务器环境。';
        $result = ['ok' => false, 'message' => $message, 'time' => time()];
    }
    try { sblog_push_write('result_' . $channel, $result); }
    catch (Throwable) { error_log('Comment push: unable to save notification result.'); }
    return $result;
}

function sblog_push_test(string $channel, ?callable $transport = null): array
{
    $config = sblog_push_config();
    $text = '评论通知连接测试' . "\n站点：" . sblog_push_text(setting('site_name', 'Simple PHP Blog'), 60)
        . "\n时间：" . date('Y-m-d H:i:s');
    if ($config['include_link']) { $text .= "\n" . absolute_url(url_for('home')); }
    return sblog_push_deliver($channel, $config, $text, $transport);
}

function sblog_push_comment_created(array $context, ?callable $transport = null): void
{
    try {
        $config = sblog_push_config();
        if (!$config['qq_enabled'] && !$config['weixin_enabled']) { return; }
        $comment = one('SELECT * FROM comments WHERE id = ? AND post_id = ?', [(int)($context['comment_id'] ?? 0), (int)($context['post_id'] ?? 0)]);
        if (!$comment || !in_array($comment['status'], ['pending', 'approved'], true)
            || !$config['notify_' . $comment['status']] || (!$config['notify_admin'] && (int)($comment['user_id'] ?? 0) > 0)) { return; }
        $post = one('SELECT * FROM posts WHERE id = ?', [(int)$comment['post_id']]);
        if (!$post) { return; }
        $text = sblog_push_comment_text($post, $comment, $config);
        foreach (['qq', 'weixin'] as $channel) {
            if ($config[$channel . '_enabled']) { sblog_push_deliver($channel, $config, $text, $transport); }
        }
    } catch (Throwable) {
        // Comment storage has already committed. Notification problems must never invalidate the comment.
        error_log('Comment push: unable to prepare notification.');
    }
}
