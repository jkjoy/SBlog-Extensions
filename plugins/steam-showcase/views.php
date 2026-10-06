<?php
declare(strict_types=1);

/** Format Steam's minute-based playtime without suggesting unavailable data is zero. */
function sblog_steam_hours(int $minutes): string
{
    $hours = max(0, $minutes) / 60;
    return number_format($hours, $hours > 0 && $hours < 100 ? 1 : 0);
}

function sblog_steam_icon(string $name): string
{
    $paths = [
        'gamepad' => '<path d="M6 12h4m-2-2v4m7-2h.01M18 10h.01"/><path d="M6.5 7h11a3 3 0 0 1 3 2.6l1 7.3a2 2 0 0 1-3.4 1.7L15 16H9l-3.1 2.6a2 2 0 0 1-3.4-1.7l1-7.3A3 3 0 0 1 6.5 7Z"/>',
        'arrow' => '<path d="M7 17 17 7M7 7h10v10"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['gamepad']) . '</svg>';
}

function sblog_steam_view_image(string $url): string
{
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        return '';
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowed = ['avatars.steamstatic.com', 'avatars.fastly.steamstatic.com', 'avatars.akamai.steamstatic.com', 'avatars.steamusercontent.com', 'cdn.cloudflare.steamstatic.com', 'cdn.akamai.steamstatic.com', 'cdn.fastly.steamstatic.com', 'shared.cloudflare.steamstatic.com', 'shared.akamai.steamstatic.com', 'shared.fastly.steamstatic.com', 'steamcdn-a.akamaihd.net', 'media.steampowered.com'];
    return in_array($host, $allowed, true) ? $url : '';
}

function sblog_steam_view_profile_url(array $profile): string
{
    $steamid = (string)($profile['steamid'] ?? '');
    if (preg_match('/^\d{17}$/D', $steamid)) {
        return 'https://steamcommunity.com/profiles/' . $steamid . '/';
    }
    $url = (string)($profile['profile_url'] ?? '');
    $parts = parse_url($url);
    if (is_array($parts) && ($parts['scheme'] ?? '') === 'https' && strtolower((string)($parts['host'] ?? '')) === 'steamcommunity.com' && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && preg_match('~^/(?:id/[A-Za-z0-9_-]+|profiles/\d{17})/?$~D', (string)($parts['path'] ?? ''))) {
        return 'https://steamcommunity.com' . $parts['path'];
    }
    return 'https://steamcommunity.com/';
}

function sblog_steam_view_game(array $game, bool $recent = false): string
{
    $appid = max(0, (int)($game['appid'] ?? 0));
    $name = trim((string)($game['name'] ?? '')) ?: '未命名游戏';
    $cover = sblog_steam_view_image((string)($game['cover_url'] ?? ''));
    $minutes = max(0, (int)($game['minutes_total'] ?? 0));
    $recentMinutes = max(0, (int)($game['minutes_2weeks'] ?? 0));
    $storeUrl = 'https://store.steampowered.com/app/' . $appid . '/';
    ob_start();
    ?>
    <li class="steam-showcase__game<?= $recent ? ' steam-showcase__game--recent' : '' ?>"<?= $recent ? '' : ' data-steam-game data-name="' . h($name) . '" data-minutes="' . h($minutes) . '" data-recent="' . h($recentMinutes) . '" data-appid="' . h($appid) . '"' ?>>
      <a class="steam-showcase__game-link" href="<?= h($storeUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= h($name . '，在 Steam 商店查看') ?>">
        <span class="steam-showcase__cover">
          <span class="steam-showcase__cover-fallback"><?= sblog_steam_icon('gamepad') ?><span>STEAM</span></span>
          <?php if ($cover !== ''): ?><img src="<?= h($cover) ?>" alt="" loading="lazy" decoding="async" width="460" height="215" referrerpolicy="no-referrer"><?php endif; ?>
        </span>
        <span class="steam-showcase__game-body">
          <span class="steam-showcase__game-name"><?= h($name) ?></span>
          <span class="steam-showcase__game-time"><?php if ($recent): ?><span>两周内 <strong><?= h(sblog_steam_hours($recentMinutes)) ?></strong> 小时</span><span>总计 <?= h(sblog_steam_hours($minutes)) ?> 小时</span><?php else: ?><span><strong><?= h(sblog_steam_hours($minutes)) ?></strong> 小时</span><?= sblog_steam_icon('arrow') ?><?php endif; ?></span>
        </span>
      </a>
    </li>
    <?php
    return (string)ob_get_clean();
}

function sblog_steam_render(array $snapshot, array $config, bool $compact = false): string
{
    $title = trim((string)($config['page_title'] ?? '游戏时光')) ?: '游戏时光';
    $profile = is_array($snapshot['profile'] ?? null) ? $snapshot['profile'] : null;
    $status = (string)($snapshot['status'] ?? 'unconfigured');
    $stats = is_array($snapshot['stats'] ?? null) ? $snapshot['stats'] : [];
    $recent = array_values(array_filter((array)($snapshot['recent_games'] ?? []), 'is_array'));
    $owned = array_values(array_filter((array)($snapshot['owned_games'] ?? []), 'is_array'));
    $limit = max(1, min(24, (int)($config['game_limit'] ?? 12)));
    $recentState = (string)($snapshot['recent_state'] ?? 'public');
    $libraryState = (string)($snapshot['library_state'] ?? 'public');
    $libraryLabel = match ($libraryState) {
        'private' => '隐私保护',
        'error' => '暂时不可用',
        default => number_format(count($owned)) . ' 款公开游戏',
    };
    $showRecent = !array_key_exists('show_recent', $config) || (bool)$config['show_recent'];
    $showLibrary = !array_key_exists('show_library', $config) || (bool)$config['show_library'];
    $updatedAt = max(0, (int)($snapshot['updated_at'] ?? 0));
    $heading = $compact ? 'h2' : 'h1';
    $name = $profile ? (trim((string)($profile['name'] ?? '')) ?: 'Steam 玩家') : '';
    $avatar = $profile ? sblog_steam_view_image((string)($profile['avatar'] ?? '')) : '';
    $currentGame = $profile ? trim((string)($profile['current_game'] ?? '')) : '';
    $state = $profile ? (int)($profile['personastate'] ?? 0) : 0;
    $stateNames = [0 => '离线', 1 => '在线', 2 => '忙碌', 3 => '离开', 4 => '暂离', 5 => '想交易', 6 => '想玩游戏'];
    $stateText = $currentGame !== '' ? '正在游戏' : ($stateNames[$state] ?? '离线');
    if ($profile && (int)($profile['visibility'] ?? 3) < 3 && $state === 0 && $currentGame === '') {
        $stateText = '状态未公开';
    }
    $stateClass = $currentGame !== '' ? 'playing' : ($state === 0 ? 'offline' : 'online');
    $recentShown = array_slice($recent, 0, $compact ? 3 : min(6, $limit));
    $compactUsesRecent = $showRecent && $recentState !== 'private' && $recentShown !== [];
    $compactGames = $compactUsesRecent ? $recentShown : ($showLibrary && $libraryState !== 'private' ? array_slice($owned, 0, 3) : []);
    ob_start();
    ?>
    <section class="steam-showcase<?= $compact ? ' steam-showcase--compact' : '' ?>" data-steam-showcase aria-label="<?= h($title) ?>">
      <div class="steam-showcase__title-row">
        <div><p class="steam-showcase__eyebrow"><?= sblog_steam_icon('gamepad') ?><span>STEAM · PLAY LOG</span></p><<?= $heading ?> class="steam-showcase__title"><?= h($title) ?></<?= $heading ?>></div>
        <?php if ($compact): ?><a class="steam-showcase__text-link" href="<?= h(sblog_steam_url()) ?>">全部游戏 <?= sblog_steam_icon('arrow') ?></a><?php endif; ?>
      </div>
      <?php if (!$profile): ?>
        <div class="steam-showcase__empty steam-showcase__empty--main"><?= sblog_steam_icon('gamepad') ?><p class="steam-showcase__empty-title"><?= $status === 'unconfigured' ? 'Steam 展示即将上线' : '暂时无法读取 Steam 数据' ?></p><p><?= $status === 'unconfigured' ? '博主配置 Steam 账号后，这里会展示最近游玩和游戏收藏。' : h((string)($snapshot['message'] ?? 'Steam 服务暂时没有响应，请稍后再来看看。')) ?></p></div>
      <?php else: ?>
        <?php if ($status === 'stale' || $status === 'error'): ?><p class="steam-showcase__notice"><?= sblog_steam_icon('clock') ?><span><?= h((string)($snapshot['message'] ?? ($status === 'stale' ? 'Steam 暂时无法同步，以下为最近一次公开数据。' : '部分 Steam 数据暂时无法读取，已公开的资料仍可查看。'))) ?></span></p><?php endif; ?>
        <div class="steam-showcase__profile">
          <a class="steam-showcase__avatar" href="<?= h(sblog_steam_view_profile_url($profile)) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= h('查看 ' . $name . ' 的 Steam 个人资料') ?>">
            <span><?= sblog_steam_icon('gamepad') ?></span><?php if ($avatar !== ''): ?><img src="<?= h($avatar) ?>" alt="" width="80" height="80" decoding="async" referrerpolicy="no-referrer"><?php endif; ?>
          </a>
          <div class="steam-showcase__identity"><p class="steam-showcase__name"><?= h($name) ?></p><p class="steam-showcase__presence"><span class="steam-showcase__status steam-showcase__status--<?= h($stateClass) ?>"><span class="steam-showcase__dot"></span><?= h($stateText) ?></span><?php if ($currentGame !== ''): ?><span class="steam-showcase__current-game"><?= h($currentGame) ?></span><?php elseif ($state === 0 && (int)($profile['last_online'] ?? 0) > 0): ?><span class="steam-showcase__last-online">最近上线 <?= h(date('m-d', (int)$profile['last_online'])) ?></span><?php endif; ?></p></div>
          <a class="steam-showcase__profile-link" href="<?= h(sblog_steam_view_profile_url($profile)) ?>" target="_blank" rel="noopener noreferrer">Steam 主页 <?= sblog_steam_icon('arrow') ?></a>
        </div>
        <dl class="steam-showcase__stats">
          <div><dt>游戏收藏</dt><dd><?= isset($stats['game_count']) ? h(number_format(max(0, (int)$stats['game_count']))) : '—' ?><span>款</span></dd></div>
          <div><dt>累计游玩</dt><dd><?= isset($stats['total_minutes']) ? h(sblog_steam_hours((int)$stats['total_minutes'])) : '—' ?><span>小时</span></dd></div>
          <div><dt>最近两周</dt><dd><?= isset($stats['recent_minutes']) ? h(sblog_steam_hours((int)$stats['recent_minutes'])) : '—' ?><span>小时</span></dd></div>
        </dl>
        <?php if ($compact): ?>
          <?php if ($compactGames !== []): ?><ul class="steam-showcase__games steam-showcase__games--recent"><?php foreach ($compactGames as $game): ?><?= sblog_steam_view_game($game, $compactUsesRecent) ?><?php endforeach; ?></ul><?php elseif ($showRecent || $showLibrary): ?><p class="steam-showcase__compact-empty"><?= $libraryState === 'private' || $recentState === 'private' ? '游戏详情未公开，去 Steam 主页看看吧。' : '最近还没有可展示的游戏记录。' ?></p><?php endif; ?>
        <?php else: ?>
          <?php if ($showRecent): ?>
            <section class="steam-showcase__section" aria-label="最近在玩"><div class="steam-showcase__section-heading"><h2>最近在玩</h2><span>过去 14 天</span></div>
              <?php if ($recentState === 'private'): ?><div class="steam-showcase__empty"><?= sblog_steam_icon('lock') ?><p class="steam-showcase__empty-title">游玩记录未公开</p><p>玩家设置了游戏详情隐私，暂时无法查看最近的游玩记录。</p></div><?php elseif ($recentState === 'error' && $recentShown === []): ?><div class="steam-showcase__empty"><p class="steam-showcase__empty-title">最近游玩暂时不可用</p><p>Steam 暂时无法提供最近游玩记录，稍后再来看看。</p></div><?php elseif ($recentShown === []): ?><div class="steam-showcase__empty"><p>最近两周没有公开的游玩记录。</p></div><?php else: ?><ul class="steam-showcase__games steam-showcase__games--recent"><?php foreach ($recentShown as $game): ?><?= sblog_steam_view_game($game, true) ?><?php endforeach; ?></ul><?php endif; ?>
            </section>
          <?php endif; ?>
          <?php if ($showLibrary): ?>
            <section class="steam-showcase__section" data-steam-library data-page-size="<?= h($limit) ?>" aria-label="游戏收藏"><div class="steam-showcase__section-heading"><h2>游戏收藏</h2><span><?= h($libraryLabel) ?></span></div>
              <?php if ($libraryState === 'private'): ?><div class="steam-showcase__empty"><?= sblog_steam_icon('lock') ?><p class="steam-showcase__empty-title">游戏收藏未公开</p><p>仅展示玩家公开的数据，游戏详情需要在 Steam 隐私设置中设为公开。</p></div><?php elseif ($libraryState === 'error' && $owned === []): ?><div class="steam-showcase__empty"><p class="steam-showcase__empty-title">游戏收藏暂时不可用</p><p>Steam 暂时无法提供游戏列表，已公开的个人资料仍可正常查看。</p></div><?php elseif ($owned === []): ?><div class="steam-showcase__empty"><p>还没有可展示的游戏收藏。</p></div><?php else: ?>
                <div class="steam-showcase__controls" data-steam-controls hidden><label class="steam-showcase__search"><?= sblog_steam_icon('search') ?><span class="steam-showcase__sr-only">搜索游戏名称</span><input type="search" data-steam-search placeholder="找一款游戏…" autocomplete="off" maxlength="100"></label><label class="steam-showcase__sort"><span class="steam-showcase__sr-only">游戏排序</span><select data-steam-sort aria-label="游戏排序"><option value="minutes">游玩时长</option><option value="recent">最近游玩</option><option value="name">游戏名称</option></select></label></div>
                <ul class="steam-showcase__games" data-steam-games><?php foreach ($owned as $game): ?><?= sblog_steam_view_game($game) ?><?php endforeach; ?></ul>
                <p class="steam-showcase__empty" data-steam-no-results hidden>没有找到这款游戏，试试其他名称。</p>
                <div class="steam-showcase__library-footer" data-steam-pagination hidden><p data-steam-result role="status" aria-live="polite" aria-atomic="true"></p><button type="button" class="steam-showcase__more" data-steam-more>再看一些 <span aria-hidden="true">↓</span></button></div>
              <?php endif; ?>
            </section>
          <?php endif; ?>
        <?php endif; ?>
        <p class="steam-showcase__footnote"><span>来自 Steam 的公开资料<?php if ($updatedAt > 0): ?> · <time datetime="<?= h(gmdate('Y-m-d\TH:i:s\Z', $updatedAt)) ?>"><?= $status === 'stale' ? '上次同步' : '更新于' ?> <?= h(date('m-d H:i', $updatedAt)) ?></time><?php endif; ?></span><?php if (!$compact): ?><span>仅统计已公开的游玩时长</span><?php endif; ?></p>
      <?php endif; ?>
    </section>
    <?php
    return (string)ob_get_clean();
}
