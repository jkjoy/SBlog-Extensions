<?php

declare(strict_types=1);

// Leave text values empty to use the blog's settings. Changes apply on the next request.
return [
    'head_title' => '',
    'description' => '',
    'author_name' => '',             // Empty: use each post's author.
    'author_homepage' => '',
    'show_footer' => false,          // The original YinYang footer is empty.
    'socials' => [
        // When empty, show archives, tags, links, navigation pages and RSS.
        // ['name' => 'GitHub', 'link' => 'https://github.com/yourname'],
        // ['name' => 'About', 'link' => '/about'],
    ],
];
