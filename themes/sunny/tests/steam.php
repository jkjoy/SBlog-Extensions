<?php
declare(strict_types=1);

// Public integration checks with fake snapshots; no bootstrap, credentials or network.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

function steam_assert(bool $condition, string $message): void
{
    $GLOBALS['steam_assertions'] = (int)($GLOBALS['steam_assertions'] ?? 0) + 1;
    if (!$condition) { throw new RuntimeException($message); }
}
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sunny_icon(string $name, int $size = 20): string { return '<svg aria-hidden="true"></svg>'; }
function sunny_preview_url(string $url): string { return $url . '&theme_preview=sunny'; }

require dirname(__DIR__) . '/steam.php';
if (in_array('--without-plugin', $argv, true)) {
    $GLOBALS['sblog_loaded_plugins'] = ['steam-showcase'];
    steam_assert(sunny_steam_config() === null, 'Missing plugin functions must be harmless.');
    steam_assert(sunny_render_steam_sidebar() === '', 'Missing plugin functions must hide the card.');
    echo "PASS: optional Steam functions\n";
    exit;
}

function sblog_steam_config(): array { return $GLOBALS['steam_config']; }
function sblog_steam_snapshot(array $config): array { $GLOBALS['steam_calls']++; return $GLOBALS['steam_snapshot']; }
function sblog_steam_url(string $action = 'steam'): string { return '/blog/index.php?a=' . $action; }
require dirname(__DIR__, 3) . '/plugins/steam-showcase/views.php';

function steam_fixture(array $snapshot = [], array $config = []): void
{
    static $sequence = 0;
    $GLOBALS['sblog_loaded_plugins'] = ['steam-showcase'];
    $GLOBALS['steam_config'] = $config + ['api_key' => 'never-output-this-key', 'steam_id' => (string)(76561198000000000 + ++$sequence), 'page_title' => '游戏时光', 'game_limit' => 12, 'show_recent' => true, 'show_library' => true];
    $GLOBALS['steam_snapshot'] = array_replace_recursive([
        'status' => 'ok', 'updated_at' => 1793808000,
        'profile' => ['steamid' => '76561198000000001', 'name' => 'Steam 昵称 <script>', 'avatar' => 'https://avatars.steamstatic.com/fixture.jpg', 'personastate' => 1, 'visibility' => 3, 'current_game' => '', 'last_online' => 0],
        'stats' => ['game_count' => 984, 'total_minutes' => 12000, 'recent_minutes' => 60],
        'recent_games' => [], 'owned_games' => [], 'recent_state' => 'public', 'library_state' => 'public',
    ], $snapshot);
    $GLOBALS['steam_calls'] = 0;
}

try {
    steam_fixture();
    $GLOBALS['sblog_loaded_plugins'] = [];
    steam_assert(sunny_render_steam_sidebar() === '' && $GLOBALS['steam_calls'] === 0, 'Disabled plugin must hide the card without fetching.');
    foreach (['api_key', 'steam_id'] as $key) {
        steam_fixture([], [$key => '']);
        steam_assert(sunny_render_steam_sidebar() === '' && $GLOBALS['steam_calls'] === 0, 'Missing ' . $key . ' must not fetch or render.');
    }
    steam_fixture(['profile' => null, 'status' => 'error']);
    steam_assert(sunny_render_steam_sidebar() === '', 'No player data must hide the entire card.');
    steam_fixture();
    $html = sunny_render_steam_sidebar();
    steam_assert(str_contains($html, 'Steam 昵称 &lt;script&gt;') && !str_contains($html, 'Steam 昵称 <script>'), 'Use and escape the Steam nickname.');
    steam_assert(str_contains($html, 'https://avatars.steamstatic.com/fixture.jpg') && str_contains($html, '拥有游戏 984'), 'Avatar and count must come from Steam.');
    steam_assert(str_contains($html, 'sunny-steam-status--online') && str_contains($html, '>在线</span>'), 'Show Steam online state.');
    steam_assert(str_contains($html, '/blog/index.php?a=steam&amp;theme_preview=sunny'), 'Preserve the plugin route and preview context.');
    steam_assert(!str_contains($html, 'never-output-this-key') && !str_contains($html, '记录生活') && !str_contains($html, '文章 '), 'Do not expose credentials or fall back to blog stats.');
    sunny_render_steam_sidebar();
    steam_assert($GLOBALS['steam_calls'] === 1, 'Read the snapshot once per configuration per request.');
    steam_fixture(['profile' => ['avatar' => 'javascript:alert(1)', 'current_game' => '<img src=x onerror=alert(1)>'], 'stats' => ['game_count' => null]]);
    $html = sunny_render_steam_sidebar();
    steam_assert(str_contains($html, '拥有游戏 —') && !str_contains($html, '拥有游戏 0'), 'Private game counts remain unknown.');
    steam_assert(!str_contains($html, 'javascript:') && !str_contains($html, '<img src=x'), 'Reject unsafe images and escape current games.');
    steam_assert(str_contains($html, 'sunny-steam-status--playing'), 'Current game takes precedence over presence.');
    steam_fixture(['status' => 'stale']);
    steam_assert(str_contains(sunny_render_steam_sidebar(), '上次同步'), 'Cached state must identify its last synchronization.');
    steam_fixture(['profile' => ['personastate' => 0, 'visibility' => 1]]);
    steam_assert(str_contains(sunny_render_steam_sidebar(), '状态未公开'), 'Respect private presence.');
    steam_assert(sunny_steam_presence(['personastate' => 2]) === ['busy', '忙碌'], 'Busy state remains distinct.');
    steam_assert(sunny_steam_presence(['personastate' => 3]) === ['away', '离开'], 'Away state remains distinct.');

    steam_fixture(['library_state' => 'private', 'recent_state' => 'private']);
    $pluginHtml = sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']);
    $page = sunny_steam_page_content($pluginHtml);
    steam_assert(substr_count($page, 'class="sunny-steam-hero"') === 1, 'Group the plugin profile and stats once.');
    steam_assert(str_contains($page, '游戏收藏未公开') && str_contains($page, '游玩记录未公开'), 'Keep plugin privacy notices.');
    steam_assert(sunny_steam_page_content($page) === $page, 'Page styling must be idempotent.');
    steam_assert(sunny_steam_page_content('<p>Regular content</p>') === '<p>Regular content</p>', 'Leave ordinary content intact.');
    steam_fixture([], ['show_recent' => false, 'show_library' => false]);
    $page = sunny_steam_page_content(sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']));
    steam_assert(!str_contains($page, 'data-steam-library') && !str_contains($page, 'aria-label="最近在玩"'), 'Retain plugin section switches.');
    steam_fixture(['profile' => null, 'status' => 'unconfigured']);
    $page = sunny_steam_page_content(sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']));
    steam_assert(str_contains($page, 'Steam 展示即将上线') && !str_contains($page, 'sunny-steam-hero'), 'Keep the unconfigured page empty state.');
    echo 'PASS: ' . $GLOBALS['steam_assertions'] . " Sunny Steam checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
