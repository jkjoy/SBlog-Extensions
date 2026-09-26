<?php

declare(strict_types=1);

add_theme_filter('body_class', static fn(string $classes): string => trim($classes . ' theme-terminal'));

add_theme_filter('comments_labels', static fn(array $labels): array => array_merge($labels, [
    'title' => 'comments.log',
    'form_title' => 'new-comment',
    'submit' => '[' . sblog_t('提交评论') . ']',
    'cancel_reply' => '[' . sblog_t('取消回复') . ']',
    'empty' => '// ' . sblog_t('暂无评论'),
    'closed' => '// ' . sblog_t('评论已关闭'),
]));

add_theme_action('head', static fn(array $context): string => '<meta name="theme-color" content="#0a0f0a">' . "\n");
