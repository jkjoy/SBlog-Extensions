<?php

declare(strict_types=1);

add_theme_filter('body_class', static function (string $classes, array $context): string {
    return trim($classes . ' theme-starter');
});

add_theme_action('head', static function (array $context): string {
    $styleUrl = theme_asset_url('style.css') . '?theme=' . rawurlencode((string)($context['theme']['version'] ?? '1.0.2'));
    return '<meta name="theme-color" content="#080e14">' . "\n"
        . '<link rel="stylesheet" href="' . h($styleUrl) . '">' . "\n";
});
