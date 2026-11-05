<?php
declare(strict_types=1);

/** Steam remains optional: the theme never reads blog identity as a fallback. */
function sunny_steam_config(): ?array
{
    if (!in_array('steam-showcase', $GLOBALS['sblog_loaded_plugins'] ?? [], true)
        || !function_exists('sblog_steam_config') || !function_exists('sblog_steam_snapshot')
        || !function_exists('sblog_steam_view_image') || !function_exists('sblog_steam_view_profile_url')
        || !function_exists('sblog_steam_url')) {
        return null;
    }
    $config = sblog_steam_config();
    return trim((string)($config['api_key'] ?? '')) !== '' && trim((string)($config['steam_id'] ?? '')) !== ''
        ? $config : null;
}

function sunny_steam_data(): ?array
{
    $config = sunny_steam_config();
    if ($config === null) {
        return null;
    }
    // Reuse the plugin's cache and synchronize at most once per configuration in this request.
    static $snapshots = [];
    $key = hash('sha256', (string)$config['steam_id'] . ':' . (string)$config['api_key']);
    $snapshot = $snapshots[$key] ??= sblog_steam_snapshot($config);
    return is_array($snapshot['profile'] ?? null) ? $snapshot : null;
}

function sunny_steam_presence(array $profile): array
{
    if (trim((string)($profile['current_game'] ?? '')) !== '') {
        return ['playing', '正在游戏'];
    }
    $state = (int)($profile['personastate'] ?? 0);
    if ($state === 0 && (int)($profile['visibility'] ?? 3) < 3) {
        return ['offline', '状态未公开'];
    }
    return match ($state) {
        1 => ['online', '在线'],
        2 => ['busy', '忙碌'],
        3 => ['away', '离开'],
        4 => ['away', '暂离'],
        5 => ['online', '想交易'],
        6 => ['online', '想玩游戏'],
        default => ['offline', '离线'],
    };
}

function sunny_render_steam_sidebar(): string
{
    $snapshot = sunny_steam_data();
    if ($snapshot === null) {
        return '';
    }
    $profile = $snapshot['profile'];
    $name = trim((string)($profile['name'] ?? '')) ?: 'Steam 玩家';
    $avatar = sblog_steam_view_image((string)($profile['avatar'] ?? ''));
    $profileUrl = sblog_steam_view_profile_url($profile);
    $pageUrl = sunny_preview_url(sblog_steam_url());
    [$state, $stateLabel] = sunny_steam_presence($profile);
    $currentGame = trim((string)($profile['current_game'] ?? ''));
    $count = $snapshot['stats']['game_count'] ?? null;
    $updatedAt = max(0, (int)($snapshot['updated_at'] ?? 0));
    $cached = in_array((string)($snapshot['status'] ?? ''), ['stale', 'error'], true);
    ob_start();
    ?>
    <section class="sunny-panel sunny-steam-card" aria-label="Steam 玩家资料">
      <a class="sunny-steam-avatar" data-sunny-steam-avatar href="<?= h($profileUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= h('查看 ' . $name . ' 的 Steam 个人资料') ?>">
        <span aria-hidden="true"><?= sunny_icon('user', 32) ?></span><?php if ($avatar !== ''): ?><img src="<?= h($avatar) ?>" alt="" width="90" height="90" loading="lazy" decoding="async" referrerpolicy="no-referrer"><?php endif; ?>
      </a>
      <div class="sunny-steam-info">
        <a class="sunny-steam-name" href="<?= h($profileUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($name) ?></a>
        <div class="sunny-steam-details"><span class="sunny-steam-status sunny-steam-status--<?= h($state) ?>"><?= h($stateLabel) ?></span><a class="sunny-steam-count" href="<?= h($pageUrl) ?>">拥有游戏 <?= $count !== null ? h(number_format(max(0, (int)$count))) : '—' ?></a></div>
        <?php if ($currentGame !== ''): ?><p class="sunny-steam-current" title="<?= h($currentGame) ?>"><?= h($currentGame) ?></p><?php endif; ?>
        <?php if ($cached): ?><small class="sunny-steam-synced"><?= $updatedAt > 0 ? '上次同步 ' . h(date('m-d H:i', $updatedAt)) : '最近一次公开资料' ?></small><?php endif; ?>
      </div>
    </section>
    <?php
    return (string)ob_get_clean();
}

/** Keep the plugin's rendering, privacy notices and controls, grouping its public profile visually. */
function sunny_steam_page_content(string $content): string
{
    if (!str_contains($content, 'data-steam-showcase') || str_contains($content, 'sunny-steam-hero')) {
        return $content;
    }
    return preg_replace('~(<div class="steam-showcase__profile">.*?</dl>)~s', '<div class="sunny-steam-hero">$1</div>', $content, 1) ?? $content;
}
