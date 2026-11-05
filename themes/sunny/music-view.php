<?php
declare(strict_types=1);

function sunny_render_music_sidebar(array $snapshot, array $config): string
{
    $track = $snapshot['tracks'][0] ?? null;
    $cover = (string)($track['cover'] ?? '');
    ob_start();
    ?>
    <section class="sunny-panel sunny-music-card<?= !$track ? ' sunny-music-card--empty' : '' ?>" data-sunny-music-card aria-label="<?= h($config['title']) ?>">
      <?php if ($track): ?>
      <button class="sunny-music-card-cover" type="button" data-music-toggle aria-label="播放音乐" title="播放音乐">
        <span aria-hidden="true"><?= sunny_icon('music', 48) ?></span><img data-music-cover<?= $cover !== '' ? ' src="' . h($cover) . '"' : ' hidden' ?> width="180" height="180" alt="歌曲封面" loading="lazy" decoding="async" referrerpolicy="no-referrer">
        <span class="sunny-music-card-overlay" data-music-toggle-icon aria-hidden="true"><?= sunny_icon('play', 32) ?></span>
      </button>
      <div class="sunny-music-card-info"><strong data-music-name><?= h($track['name']) ?></strong><span data-music-artist><?= h($track['artist']) ?></span><button class="sunny-music-card-open" type="button" data-music-open aria-controls="sunny-music-dialog" aria-label="<?= h('打开' . $config['title'] . '播放列表') ?>" title="<?= h($config['title']) ?>"><?= sunny_icon('music', 16) ?></button></div>
      <?php else: ?>
      <h2 class="sunny-label"><?= sunny_icon('music', 18) ?> <?= h($config['title']) ?></h2><p class="sunny-music-notice">歌单暂时无法读取</p><a href="<?= h($snapshot['url']) ?>" target="_blank" rel="noopener noreferrer">在网易云查看歌单 ↗</a>
      <?php endif; ?>
      <noscript><p><a href="<?= h($snapshot['url']) ?>" target="_blank" rel="noopener noreferrer">在网易云收听歌单 ↗</a></p></noscript>
    </section>
    <?php
    return (string)ob_get_clean();
}

function sunny_render_music_player(array $snapshot, array $config): string
{
    if ($snapshot['tracks'] === []) {
        return '';
    }
    $first = $snapshot['tracks'][0];
    $payload = ['id' => $config['id'], 'name' => $snapshot['name'], 'url' => $snapshot['url'], 'tracks' => $snapshot['tracks'], 'mode' => $config['mode'], 'volume' => $config['volume']];
    ob_start();
    ?>
    <audio id="sunny-music-audio" preload="none"></audio>
    <dialog class="sunny-music-dialog" id="sunny-music-dialog" aria-labelledby="sunny-music-title">
      <header class="sunny-music-heading"><h2 id="sunny-music-title"><?= h($config['title']) ?></h2><button class="sunny-icon-button" type="button" data-music-close aria-label="收起播放列表" title="收起播放列表"><?= sunny_icon('x') ?></button></header>
      <div class="sunny-music-now"><span class="sunny-music-now-cover" aria-hidden="true"><?= sunny_icon('music', 40) ?><img data-music-cover<?= $first['cover'] !== '' ? ' src="' . h($first['cover']) . '"' : ' hidden' ?> width="80" height="80" alt="" referrerpolicy="no-referrer"></span><div><strong data-music-name><?= h($first['name']) ?></strong><span data-music-artist><?= h($first['artist']) ?></span><div class="sunny-music-time"><span data-music-current-time>00:00</span> / <span data-music-duration>00:00</span></div></div></div>
      <input class="sunny-music-progress" data-music-progress type="range" value="0" min="0" max="100" step="0.1" aria-label="播放进度" disabled>
      <div class="sunny-music-controls"><button type="button" data-music-prev aria-label="上一首" title="上一首"><?= sunny_icon('skip-back') ?></button><button type="button" data-music-toggle aria-label="播放音乐" title="播放音乐"><span data-music-toggle-icon aria-hidden="true"><?= sunny_icon('play', 24) ?></span></button><button type="button" data-music-next aria-label="下一首" title="下一首"><?= sunny_icon('skip-forward') ?></button><button type="button" data-music-stop aria-label="停止播放" title="停止播放"><?= sunny_icon('stop') ?></button><button type="button" data-music-mode>列表循环</button></div>
      <label class="sunny-music-volume">音量 <input data-music-volume type="range" min="0" max="100" value="<?= (int)round($config['volume'] * 100) ?>" aria-label="音量"></label>
      <p class="sunny-music-notice" data-music-notice role="status"><?= $snapshot['status'] === 'stale' ? '暂时无法同步，以下为上次读取的歌单。' : '' ?></p>
      <ol class="sunny-music-list" data-music-list>
        <?php foreach ($snapshot['tracks'] as $index => $track): ?>
        <li><button type="button" data-music-track="<?= $index ?>"<?= $index === 0 ? ' aria-current="true"' : '' ?>><span class="sunny-music-track-cover" aria-hidden="true"><?= sunny_icon('music', 20) ?><?php if ($track['cover'] !== ''): ?><img src="<?= h($track['cover']) ?>" width="40" height="40" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"><?php endif; ?></span><span><strong><?= h($track['name']) ?></strong><small><?= h($track['artist']) ?></small></span></button></li>
        <?php endforeach; ?>
      </ol>
      <p class="sunny-music-source"><a href="<?= h($snapshot['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($snapshot['name']) ?> · 网易云音乐 ↗</a></p>
    </dialog>
    <aside class="sunny-music-dock" aria-label="正在收听" hidden><button type="button" data-music-open aria-label="打开音乐播放列表"><span class="sunny-music-dock-cover" aria-hidden="true"><?= sunny_icon('music', 20) ?><img data-music-cover<?= $first['cover'] !== '' ? ' src="' . h($first['cover']) . '"' : ' hidden' ?> width="32" height="32" alt="" referrerpolicy="no-referrer"></span><span data-music-name><?= h($first['name']) ?></span></button><button type="button" data-music-toggle aria-label="播放音乐"><span data-music-toggle-icon aria-hidden="true"><?= sunny_icon('play', 18) ?></span></button><button type="button" data-music-stop aria-label="停止播放"><?= sunny_icon('x', 16) ?></button></aside>
    <script id="sunny-music-data" type="application/json"><?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    <?php
    return (string)ob_get_clean();
}
