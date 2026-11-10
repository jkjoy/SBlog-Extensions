<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

sblog_i18n_register('en', [
    'plugin.multimedia-parser.name' => 'Multimedia Parser',
    'plugin.multimedia-parser.description' => 'Embed standalone Bilibili and YouTube video links and NetEase Cloud Music tracks, playlists, and albums as players.',
    '网易云音乐' => 'NetEase Cloud Music',
    '哔哩哔哩视频' => 'Bilibili video',
    'YouTube 视频' => 'YouTube video',
    '支持 Markdown；将网易云音乐、哔哩哔哩或 YouTube 链接单独放在一段可自动解析。' => 'Supports Markdown. NetEase Music, Bilibili, and YouTube links on their own line are embedded automatically.',
]);
sblog_i18n_register('ru', [
    'plugin.multimedia-parser.name' => 'Мультимедийные ссылки',
    'plugin.multimedia-parser.description' => 'Встраивает отдельные ссылки на видео Bilibili и YouTube, а также треки, плейлисты и альбомы NetEase Cloud Music.',
    '网易云音乐' => 'NetEase Cloud Music',
    '哔哩哔哩视频' => 'Видео Bilibili',
    'YouTube 视频' => 'Видео YouTube',
    '支持 Markdown；将网易云音乐、哔哩哔哩或 YouTube 链接单独放在一段可自动解析。' => 'Поддерживается Markdown. Ссылки NetEase Music, Bilibili и YouTube в отдельной строке встраиваются автоматически.',
]);

function sblog_multimedia_host_matches(string $host, string $domain): bool
{
    return $host === $domain || str_ends_with($host, '.' . $domain);
}

function sblog_multimedia_iframe_html(string $provider, string $src, string $kind = 'video', int $height = 0): string
{
    $class = $kind === 'audio' ? 'media-embed media-embed--audio' : 'media-embed media-embed--video';
    $style = $height > 0 ? ' style="--media-height:' . $height . 'px"' : '';
    $allow = $kind === 'video'
        ? ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen'
        : '';

    return '<figure class="' . $class . '" data-multimedia-parser' . $style . '><iframe src="' . h($src) . '" title="' . h($provider)
        . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin"' . $allow . '></iframe></figure>';
}

function sblog_multimedia_embed_html(string $url): string
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    $path = (string)($parts['path'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);

    if (sblog_multimedia_host_matches($host, 'music.163.com')) {
        $route = trim($path, '/');
        $fragment = (string)($parts['fragment'] ?? '');
        if (str_contains($fragment, '?')) {
            [$fragmentRoute, $fragmentQuery] = explode('?', $fragment, 2);
            $route = trim($fragmentRoute, '/');
            parse_str($fragmentQuery, $query);
        }
        $types = [
            'song' => ['type' => 2, 'height' => 86],
            'playlist' => ['type' => 0, 'height' => 450],
            'album' => ['type' => 1, 'height' => 450],
        ];
        $id = $query['id'] ?? '';
        if (isset($types[$route]) && is_string($id) && preg_match('/^[0-9]+$/D', $id)) {
            $config = $types[$route];
            $src = 'https://music.163.com/outchain/player?type=' . $config['type'] . '&id=' . rawurlencode($id)
                . '&auto=0&height=' . ($config['height'] - 20);
            return sblog_multimedia_iframe_html(sblog_t('网易云音乐'), $src, 'audio', $config['height']);
        }
    }

    if (sblog_multimedia_host_matches($host, 'bilibili.com')
        && preg_match('#/(?:video/)?(BV[0-9A-Za-z]+|av(\d+))#i', $path, $matches)) {
        if (isset($query['p']) && !is_string($query['p'])) {
            return '';
        }
        $parameter = stripos($matches[1], 'BV') === 0
            ? 'bvid=' . rawurlencode($matches[1])
            : 'aid=' . rawurlencode($matches[2]);
        $page = isset($query['p']) && preg_match('/^[0-9]+$/D', $query['p']) ? '&page=' . (int)$query['p'] : '';
        return sblog_multimedia_iframe_html(
            sblog_t('哔哩哔哩视频'),
            'https://player.bilibili.com/player.html?' . $parameter . $page . '&high_quality=1&danmaku=0&as_wide=1'
        );
    }

    if (sblog_multimedia_host_matches($host, 'youtube.com') || $host === 'youtu.be') {
        $videoId = '';
        if ($host === 'youtu.be') {
            $videoId = trim($path, '/');
        } elseif (isset($query['v'])) {
            if (!is_string($query['v'])) {
                return '';
            }
            $videoId = $query['v'];
        } elseif (preg_match('#/(?:embed|shorts|live)/([^/?]+)#', $path, $matches)) {
            $videoId = $matches[1];
        }
        if (preg_match('/^[0-9A-Za-z_-]{6,15}$/D', $videoId)) {
            return sblog_multimedia_iframe_html(
                sblog_t('YouTube 视频'),
                'https://www.youtube-nocookie.com/embed/' . rawurlencode($videoId)
            );
        }
    }
    return '';
}

add_plugin_filter('media_embed_html', static function (mixed $html, array $context): mixed {
    if ($html !== null || !is_string($context['url'] ?? null)) {
        return $html;
    }
    $embed = sblog_multimedia_embed_html($context['url']);
    return $embed !== '' ? $embed : $html;
});

add_plugin_filter('editor_content_hint', static function (string $hint, array $context): string {
    return sblog_t('支持 Markdown；将网易云音乐、哔哩哔哩或 YouTube 链接单独放在一段可自动解析。');
});

function sblog_multimedia_stylesheet_html(): string
{
    return '<link rel="stylesheet" data-multimedia-parser-style href="'
        . h(plugin_asset_url('multimedia-parser', 'assets/style.css') . '?v=1.0.0') . '">';
}

add_theme_action('head', static function (array $context): string {
    return ($context['mode'] ?? 'public') === 'public' ? sblog_multimedia_stylesheet_html() : '';
}, 30);

add_plugin_filter('output_html', static function (string $html, array $context): string {
    $headers = trim((string)($context['content_type'] ?? ''));
    $contentType = '';
    if (preg_match('~(?:^|;\s*)content-type:\s*([^;]+)~i', $headers, $match)) {
        $contentType = strtolower(trim($match[1]));
    } elseif (preg_match('~^[a-z0-9!#$&^_.+-]+/[a-z0-9!#$&^_.+-]+~i', $headers, $match)) {
        $contentType = strtolower($match[0]);
    }
    if (($contentType !== '' && $contentType !== 'text/html') || stripos($html, '</head>') === false
        || str_contains($html, 'data-multimedia-parser-style')
        || !preg_match('~<figure\b[^>]*\bdata-multimedia-parser(?:[=\s>])~i', $html)) {
        return $html;
    }
    $style = sblog_multimedia_stylesheet_html();
    return preg_replace_callback('~</head>~i', static fn(): string => $style . "\n</head>", $html, 1) ?? $html;
}, 30);
