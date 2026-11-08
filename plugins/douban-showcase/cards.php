<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

/** No cover image here: themes may extract article images into a gallery. */
function sblog_douban_subject_card(array $identity): string
{
    $type = $identity['type'];
    $label = sblog_douban_types()[$type];
    return '<figure class="media-embed media-embed--douban"><a class="sblog-douban-card" data-douban-subject data-subject-type="' . h($type)
        . '" data-subject-id="' . h($identity['id']) . '" data-subject-state="pending" data-subject-endpoint="'
        . h(sblog_douban_url('douban_showcase_subject')) . '" href="' . h($identity['url'])
        . '" target="_blank" rel="noopener noreferrer">'
        . '<span class="sblog-douban-card__cover" aria-hidden="true"><span class="sblog-douban-card__fallback">'
        . sblog_douban_icon($type) . '<span>' . h($label) . '</span></span></span>'
        . '<span class="sblog-douban-card__body"><span class="sblog-douban-card__eyebrow">豆瓣' . h($label) . '</span>'
        . '<span class="sblog-douban-card__title">' . h($label) . '条目 · ' . h($identity['id']) . '</span>'
        . '<span class="sblog-douban-card__original" hidden></span><span class="sblog-douban-card__rating" hidden></span>'
        . '<span class="sblog-douban-card__meta" hidden></span><span class="sblog-douban-card__summary" hidden></span>'
        . '<span class="sblog-douban-card__footer">查看豆瓣条目 →</span></span></a></figure>';
}

function sblog_douban_subject_response(array $input): array
{
    if (!sblog_douban_config()['parse_links']) {
        return [404, ['ok' => false, 'message' => '条目卡片解析未启用。']];
    }
    $type = sblog_douban_input($input, 'type');
    $id = sblog_douban_input($input, 'id');
    $identity = sblog_douban_subject_identity('https://' . $type . '.douban.com/subject/' . $id . '/');
    if ($identity === null || $identity['type'] !== $type || $identity['id'] !== $id) {
        return [400, ['ok' => false, 'message' => '无效的豆瓣条目。']];
    }
    $subject = sblog_douban_subject_data($type, $id);
    if ($subject === []) {
        return [502, ['ok' => false, 'message' => '暂时无法读取条目信息，请前往豆瓣查看。']];
    }
    $subject['cover_url'] = sblog_douban_cover_url($subject['cover_url']);
    return [200, ['ok' => true, 'subject' => $subject]];
}

function sblog_douban_render_subject(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        $status = 405;
        $payload = ['ok' => false, 'message' => '请使用 GET 请求。'];
    } else {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        [$status, $payload] = sblog_douban_subject_response($_GET);
    }
    http_response_code($status);
    header('Cache-Control: ' . ($status === 200 ? 'public, max-age=300' : 'no-store'));
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sblog_douban_card_assets_visible(array $context): bool
{
    return ($context['mode'] ?? 'public') === 'public' && sblog_douban_config()['parse_links'];
}

add_plugin_filter('media_embed_html', static function (mixed $html, array $context): mixed {
    $GLOBALS['sblog_douban_embed_hook_used'] = true;
    if ($html !== null) {
        return $html;
    }
    $identity = sblog_douban_subject_identity((string)($context['url'] ?? ''));
    if ($identity === null) {
        return $html;
    }
    return sblog_douban_config()['parse_links'] ? sblog_douban_subject_card($identity) : '';
}, 25);

add_theme_action('head', static function (array $context): string {
    return sblog_douban_card_assets_visible($context)
        ? '<link rel="stylesheet" href="' . h(plugin_asset_url('douban-showcase', 'assets/cards.css') . '?v=1.1.0') . '">'
        : '';
}, 30);

add_theme_action('body_close', static function (array $context): string {
    return sblog_douban_card_assets_visible($context)
        ? '<script defer src="' . h(plugin_asset_url('douban-showcase', 'assets/cards.js') . '?v=1.1.0') . '"></script>'
        : '';
}, 30);

/** Compatibility with cores that already emit Douban cards but lack the embed filter. */
add_plugin_filter('output_html', static function (string $html, array $context): string {
    if (!empty($GLOBALS['sblog_douban_embed_hook_used']) || str_starts_with((string)($context['action'] ?? ''), 'admin')
        || !str_contains($html, 'media-link-card--douban')) {
        return $html;
    }
    $enabled = sblog_douban_config()['parse_links'];
    return preg_replace_callback('~<aside\b[^>]*class="[^"]*\bmedia-link-card--douban\b[^"]*"[^>]*>.*?</aside>~s',
        static function (array $match) use ($enabled): string {
            if (!preg_match('~<a\b[^>]*href="([^"]+)"~', $match[0], $link)) {
                return $match[0];
            }
            $identity = sblog_douban_subject_identity(html_entity_decode($link[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($identity === null) {
                return $match[0];
            }
            return $enabled ? sblog_douban_subject_card($identity)
                : '<p><a href="' . h($identity['url']) . '" target="_blank" rel="noopener noreferrer">' . h($identity['url']) . '</a></p>';
        }, $html) ?? $html;
}, 30);
