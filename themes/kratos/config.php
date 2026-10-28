<?php

declare(strict_types=1);

// Original Kratos defaults. Changes take effect on the next request.
return [
    'banner' => true,
    'banner_image' => '',          // Empty: bundled original background.jpg.
    'banner_title' => 'Kratos',
    'banner_description' => '专注于用户阅读体验的响应式博客主题',
    'background' => '#f5f5f5',
    'navigation_color' => '#24292e',
    'theme_color' => '#282a2c',
    'logo' => '',                  // Empty: display the configured blog name.
    'thumbnail' => true,
    'excerpt_length' => 260,
    'show_views' => true,
    'show_comments' => true,
    'show_likes' => true,
    'show_author' => true,
    'article_sidebar' => true,
    'lightbox' => true,
    'animate' => false,
    'fontawesome' => false,
    'sidebar' => ['about', 'posts', 'tags'],
    // Available widgets: about, posts, tags, comments, categories, search, toc.
    'sidebar_posts' => 6,
    'sidebar_tags' => 8,
    'sidebar_comments' => 5,
    'about_background' => '',
    'navigation' => [],            // Empty: home, archives, categories, tags, links, pages.
    // ['name' => 'GitHub', 'url' => 'https://github.com/yourname'],
    'socials' => [],               // Empty: use the first author's public social links.
    // ['icon' => 'github', 'name' => 'GitHub', 'url' => 'https://github.com/yourname'],
    'copyright' => '',             // Empty: original COPYRIGHT © year site name text.
];
