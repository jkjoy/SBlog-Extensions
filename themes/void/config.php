<?php

declare(strict_types=1);

// Changes take effect on the next request. Keep this file when upgrading.
return [
    'name' => '',                     // Empty: use the site's name.
    'index_title' => '',
    'index_subtitle' => '',           // Empty: use the site's tagline.
    'banner' => '',                   // Optional homepage/archive image URL.
    'banner_height' => 40,            // vh, when an image is configured.
    'mobile_banner_height' => 32,
    'index_style' => 'grid',          // grid / single
    'cover_style' => 1,               // 0: text, 1: image above, 2: image background
    'auto_cover' => true,             // First publicly visible article image.
    'default_cover' => '',            // Optional shared list cover: HTTPS URL or /uploads/image.jpg.
    'title_cover' => true,            // Generate local title artwork when no image is available.
    'post_banner' => true,
    'color_scheme' => 'auto',         // auto / light / dark
    'follow_system' => true,
    'dark_start' => 22,
    'dark_end' => 7,
    'font_size' => 3,                 // 1–5: 14 / 16 / 18 / 20 / 22 px
    'serif' => false,
    'mac_code' => true,
    'line_numbers' => true,
    'pjax' => true,
    'math' => true,                   // Load MathJax only on pages with formulas.
    'math_url' => 'https://cdn.jsdelivr.net/npm/mathjax@3.2.2/es5/tex-svg.js',
    'show_word_count' => true,
    'show_reading_time' => true,
    'show_likes' => true,
    'start_date' => '',               // Optional YYYY-MM-DD, for site uptime.
    'reward_image' => '',            // Optional donation QR image URL.
    'social' => [
        // ['name' => 'GitHub', 'icon' => 'github', 'url' => 'https://github.com/yourname'],
    ],
    'navigation' => [
        // ['name' => '更多', 'items' => [['title' => '示例', 'url' => 'https://example.com']]],
    ],
];
