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

function steam_game(int $appid, int $recentMinutes = 0): array
{
    return [
        'appid' => $appid, 'name' => '游戏 ' . $appid,
        'minutes_total' => 1200, 'minutes_2weeks' => $recentMinutes,
        'cover_url' => 'https://shared.fastly.steamstatic.com/store_item_assets/steam/apps/' . $appid . '/header.jpg',
        'icon_url' => '',
    ];
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

    $recentGame = steam_game(730, 90);
    $olderGame = steam_game(440);
    steam_fixture(['recent_games' => [$recentGame], 'owned_games' => [$recentGame, $olderGame]]);
    $page = sunny_steam_page_content(sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']));
    steam_assert(!str_contains($page, 'aria-label="最近在玩"') && substr_count($page, 'href="https://store.steampowered.com/app/730/"') === 1, 'Render a recent game once when it is already in the library.');
    steam_assert(str_contains($page, '<h2>游戏库</h2>') && str_contains($page, 'data-appid="730"') && str_contains($page, 'data-appid="440"'), 'Keep the complete library under its Sunny heading.');
    steam_assert(substr_count($page, 'class="sunny-steam-recent-badge"') === 1 && str_contains($page, 'title="近两周游玩 1.5 小时"'), 'Mark the recent library game with its public play time.');
    steam_assert((bool)preg_match('~<li[^>]*data-appid="440"[^>]*>(?:(?!</li>).)*</li>~s', $page, $olderCard) && !str_contains($olderCard[0], 'sunny-steam-recent-badge'), 'A game without recent play time must not receive a recent badge.');
    foreach (['data-steam-controls', 'data-steam-search', 'data-steam-sort', 'data-steam-games', 'data-steam-pagination', 'data-steam-result', 'data-steam-more', 'steam-showcase__footnote'] as $marker) {
        steam_assert(str_contains($page, $marker), 'Preserve library behavior and attribution: ' . $marker);
    }
    steam_assert((bool)preg_match('~<p class="steam-showcase__presence">(?:(?!</p>).)*<a class="steam-showcase__profile-link"~s', $page), 'Place the Steam profile link alongside the player presence.');
    steam_assert($GLOBALS['steam_calls'] === 0, 'Adapting plugin HTML must not request another Steam snapshot.');
    steam_assert(sunny_steam_page_content($page) === $page, 'Do not duplicate badges or the hero when adapting the page again.');

    steam_fixture(['recent_games' => [$recentGame], 'owned_games' => [$recentGame]], ['show_recent' => false]);
    $page = sunny_steam_page_content(sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']));
    steam_assert(!str_contains($page, 'aria-label="最近在玩"') && !str_contains($page, 'sunny-steam-recent-badge'), 'The recent-games switch must also hide recent badges.');
    steam_assert(str_contains($page, 'data-appid="730"'), 'Disabling recent games must preserve the library.');

    steam_fixture(['recent_games' => [$recentGame], 'owned_games' => [$recentGame]], ['show_library' => false]);
    $page = sunny_steam_page_content(sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']));
    steam_assert(str_contains($page, 'steam-showcase__games--recent') && str_contains($page, 'href="https://store.steampowered.com/app/730/"'), 'Keep recent games when the full library is disabled.');
    steam_assert(!str_contains($page, 'data-steam-library') && !str_contains($page, 'data-steam-pagination'), 'Respect the disabled library and its controls.');

    steam_fixture(['recent_games' => [$recentGame], 'library_state' => 'error']);
    $page = sunny_steam_page_content(sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']));
    steam_assert(str_contains($page, 'steam-showcase__games--recent') && str_contains($page, 'href="https://store.steampowered.com/app/730/"'), 'Keep available recent games if the library cannot be loaded.');
    steam_assert(str_contains($page, '游戏收藏暂时不可用'), 'Keep the library failure notice.');

    foreach (['private' => '游玩记录未公开', 'error' => '最近游玩暂时不可用'] as $state => $notice) {
        steam_fixture(['recent_state' => $state, 'owned_games' => [$recentGame]]);
        $page = sunny_steam_page_content(sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']));
        steam_assert(str_contains($page, $notice) && str_contains($page, 'data-appid="730"'), 'Preserve the recent ' . $state . ' notice and available library.');
        steam_assert(!str_contains($page, 'sunny-steam-recent-badge'), 'Do not imply public recent data for state ' . $state . '.');
    }

    steam_fixture(['owned_games' => [$olderGame]]);
    $page = sunny_steam_page_content(sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']));
    steam_assert(!str_contains($page, 'aria-label="最近在玩"') && !str_contains($page, 'sunny-steam-recent-badge') && str_contains($page, 'data-appid="440"'), 'Omit an empty recent section while preserving the library.');

    steam_fixture(['recent_games' => [$recentGame, steam_game(570, 30)], 'owned_games' => [$recentGame]]);
    $page = sunny_steam_page_content(sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']));
    steam_assert(str_contains($page, 'steam-showcase__games--recent') && str_contains($page, 'href="https://store.steampowered.com/app/570/"'), 'Keep a recent-only game, such as a free-weekend title, accessible.');
    steam_assert(str_contains($page, 'data-appid="730"') && !str_contains($page, 'data-appid="570"'), 'Do not add a recent-only title to the owned library.');

    $largeLibrary = [];
    for ($index = 0; $index < 1000; $index++) {
        $largeLibrary[] = steam_game(10000 + $index, $index === 0 || $index === 999 ? 120 : 0);
    }
    steam_fixture(['recent_games' => [$largeLibrary[0], $largeLibrary[999]], 'owned_games' => $largeLibrary]);
    $pluginHtml = sblog_steam_render($GLOBALS['steam_snapshot'], $GLOBALS['steam_config']);
    steam_assert(strlen($pluginHtml) > 1000000, 'Exercise a real plugin-rendered library larger than one megabyte.');
    $previousBacktrackLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1000000');
    $page = sunny_steam_page_content($pluginHtml);
    $transformError = preg_last_error();
    $transformErrorMessage = preg_last_error_msg();
    ini_set('pcre.backtrack_limit', (string)$previousBacktrackLimit);
    steam_assert($transformError === PREG_NO_ERROR, 'A 1000-game library must not exhaust PCRE: ' . $transformErrorMessage);
    preg_match_all('~data-appid="(\d+)"~', $page, $largeLibraryIds);
    steam_assert($largeLibraryIds[1] === array_map('strval', range(10000, 10999)), 'Keep every game and its order in a 1000-game library.');
    steam_assert(!str_contains($page, 'aria-label="最近在玩"') && substr_count($page, 'href="https://store.steampowered.com/app/10000/"') === 1 && substr_count($page, 'href="https://store.steampowered.com/app/10999/"') === 1, 'Remove duplicate recent games at both ends of a large library.');
    steam_assert(substr_count($page, 'class="sunny-steam-recent-badge"') === 2 && str_contains($page, 'data-steam-pagination') && str_contains($page, 'steam-showcase__footnote'), 'Complete recent badges and preserve library controls and attribution for a large library.');

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
