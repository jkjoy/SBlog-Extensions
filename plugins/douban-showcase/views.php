<?php
declare(strict_types=1);

function sblog_douban_icon(string $name): string
{
    $paths = [
        'movie' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 4v16M17 4v16M3 9h4m-4 6h4m10-6h4m-4 6h4"/>',
        'book' => '<path d="M12 6c-3-2-6-2-9-1v14c3-1 6-1 9 1 3-2 6-2 9-1V5c-3-1-6-1-9 1Zm0 0v14"/>',
        'music' => '<path d="M9 18V5l11-2v13M9 8l11-2"/><ellipse cx="6" cy="18" rx="3" ry="3"/><ellipse cx="17" cy="16" rx="3" ry="3"/>',
        'arrow' => '<path d="M7 17 17 7M7 7h10v10"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
        'person' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['book']) . '</svg>';
}

/** Keep external navigation on Douban, even when a cached field is malformed. */
function sblog_douban_view_url(string $value): string
{
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
        return '';
    }
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
        return '';
    }
    $host = strtolower((string)($parts['host'] ?? ''));
    return in_array($host, ['douban.com', 'www.douban.com', 'movie.douban.com', 'book.douban.com', 'music.douban.com'], true) ? $value : '';
}

function sblog_douban_view_profile_url(array $profile, string $userId): string
{
    $url = sblog_douban_view_url((string)($profile['url'] ?? ''));
    if ($url !== '') {
        return $url;
    }
    $id = trim((string)($profile['id'] ?? $userId));
    return preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $id) && $id !== '.' && $id !== '..'
        ? 'https://www.douban.com/people/' . rawurlencode($id) . '/'
        : '';
}

function sblog_douban_view_item(array $item, string $type, bool $compact = false): string
{
    $title = trim((string)($item['title'] ?? '')) ?: '未命名条目';
    $url = sblog_douban_view_url((string)($item['url'] ?? ''));
    $id = (string)($item['id'] ?? '');
    if ($url === '' && preg_match('/^\d+$/D', $id)) {
        $url = 'https://' . $type . '.douban.com/subject/' . $id . '/';
    }
    $cover = sblog_douban_image_url((string)($item['cover_url'] ?? ''));
    $rating = max(0, min(5, (int)($item['rating'] ?? 0)));
    $date = trim((string)($item['date'] ?? ''));
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $dateParts) || !checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1])) {
        $date = '';
    }
    $intro = trim((string)($item['intro'] ?? ''));
    $comment = trim((string)($item['comment'] ?? ''));
    $tags = array_values(array_filter(array_map(static fn($tag): string => is_scalar($tag) ? trim((string)$tag) : '', (array)($item['tags'] ?? [])), static fn(string $tag): bool => $tag !== ''));
    $searchText = implode(' ', [$title, $intro, $comment, implode(' ', $tags)]);
    $coverLabel = ['movie' => '电影', 'book' => '图书', 'music' => '音乐'][$type] ?? '记录';
    ob_start();
    ?>
    <li class="douban-showcase__item"<?= $compact ? '' : ' data-douban-item data-search="' . h($searchText) . '" tabindex="-1"' ?>>
      <?php if ($url !== ''): ?><a class="douban-showcase__cover" href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= h('在豆瓣查看《' . $title . '》') ?>"><?php else: ?><span class="douban-showcase__cover"><?php endif; ?>
        <span class="douban-showcase__cover-fallback"><?= sblog_douban_icon($type) ?><span><?= h($coverLabel) ?></span></span>
        <?php if ($cover !== ''): ?><img src="<?= h($cover) ?>" alt="" width="150" height="220" loading="lazy" decoding="async" referrerpolicy="no-referrer"><?php endif; ?>
      <?php if ($url !== ''): ?></a><?php else: ?></span><?php endif; ?>
      <div class="douban-showcase__item-body">
        <h3 class="douban-showcase__item-title"><?php if ($url !== ''): ?><a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h($title) ?></a><?php else: ?><?= h($title) ?><?php endif; ?></h3>
        <div class="douban-showcase__item-meta">
          <?php if ($rating > 0): ?><span class="douban-showcase__rating" role="img" aria-label="<?= h('我的评分：' . $rating . ' 星，满分 5 星') ?>"><span aria-hidden="true"><?= h(str_repeat('★', $rating)) ?><span class="douban-showcase__rating-empty"><?= h(str_repeat('☆', 5 - $rating)) ?></span></span></span><?php else: ?><span class="douban-showcase__unrated">未评分</span><?php endif; ?>
          <?php if ($date !== ''): ?><time datetime="<?= h($date) ?>" title="<?= h('标记于 ' . $date) ?>"><?= h($date) ?></time><?php endif; ?>
        </div>
        <?php if (!$compact && $intro !== ''): ?><p class="douban-showcase__intro"><?= h($intro) ?></p><?php endif; ?>
        <?php if ($comment !== ''): ?><p class="douban-showcase__comment"><?= h($comment) ?></p><?php endif; ?>
        <?php if (!$compact && $tags !== []): ?><ul class="douban-showcase__tags" aria-label="我的标签"><?php foreach ($tags as $tag): ?><li><?= h($tag) ?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
    </li>
    <?php
    return (string)ob_get_clean();
}

function sblog_douban_render(array $snapshot, array $config, string $type = 'movie', string $status = 'collect', bool $compact = false): string
{
    $types = sblog_douban_types();
    $type = array_key_exists($type, $types) ? $type : 'movie';
    $statuses = sblog_douban_statuses($type);
    $status = array_key_exists($status, $statuses) ? $status : 'collect';
    $title = trim((string)($config['page_title'] ?? '豆瓣记录')) ?: '豆瓣记录';
    $userId = trim((string)($config['user_id'] ?? ''));
    $state = (string)($snapshot['status'] ?? 'unconfigured');
    if (!in_array($state, ['unconfigured', 'ok', 'stale', 'error', 'private'], true)) {
        $state = 'error';
    }
    $messages = [
        'unconfigured' => '博主配置豆瓣 ID 后，这里会展示公开的电影、图书和音乐记录。',
        'stale' => '豆瓣暂时无法同步，以下展示最近一次同步的公开记录。',
        'error' => '豆瓣暂时无法提供记录，请稍后再来看看。',
        'private' => '该用户的这份记录未公开，暂时无法展示。',
    ];
    $message = trim((string)($snapshot['message'] ?? '')) ?: ($messages[$state] ?? '');
    $profile = is_array($snapshot['profile'] ?? null) ? $snapshot['profile'] : [];
    $name = trim((string)($profile['name'] ?? '')) ?: '豆瓣用户';
    $avatar = sblog_douban_image_url((string)($profile['avatar'] ?? ''));
    $profileUrl = sblog_douban_view_profile_url($profile, $userId);
    $listUrl = sblog_douban_view_url(sblog_douban_list_url($userId, $type, $status));
    $items = array_values(array_filter((array)($snapshot['items'] ?? []), 'is_array'));
    if ($state === 'private' || $state === 'unconfigured') {
        $items = [];
    }
    $synced = count($items);
    $shown = $compact ? array_slice($items, 0, 4) : $items;
    $total = isset($snapshot['total']) && is_numeric($snapshot['total']) ? max(0, (int)$snapshot['total']) : null;
    $updatedAt = max(0, (int)($snapshot['updated_at'] ?? 0));
    $truncated = !empty($snapshot['truncated']);
    $pageSize = max(1, min(60, (int)($config['page_size'] ?? 12)));
    $heading = $compact ? 'h2' : 'h1';
    $listLabel = $statuses[$status] . $types[$type];
    $stateTitles = ['unconfigured' => '豆瓣记录即将上线', 'private' => '这份豆瓣记录未公开', 'error' => '豆瓣记录暂时不可用'];
    ob_start();
    ?>
    <section class="douban-showcase<?= $compact ? ' douban-showcase--compact' : '' ?>" data-douban-showcase aria-label="<?= h($title) ?>">
      <div class="douban-showcase__title-row">
        <div><p class="douban-showcase__eyebrow"><?= sblog_douban_icon($type) ?><span>豆瓣 · 生活记录</span></p><<?= $heading ?> class="douban-showcase__title"><?= h($title) ?></<?= $heading ?>></div>
        <?php if ($compact): ?><a class="douban-showcase__text-link" href="<?= h(sblog_douban_url('douban', ['type' => $type, 'status' => $status])) ?>">全部记录 <?= sblog_douban_icon('arrow') ?></a><?php endif; ?>
      </div>
      <?php if ($state !== 'unconfigured' && ($profile !== [] || $userId !== '')): ?>
        <div class="douban-showcase__profile">
          <?php if ($profileUrl !== ''): ?><a class="douban-showcase__avatar" href="<?= h($profileUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= h('查看 ' . $name . ' 的豆瓣主页') ?>"><?php else: ?><span class="douban-showcase__avatar"><?php endif; ?>
            <span><?= sblog_douban_icon('person') ?></span><?php if ($avatar !== ''): ?><img src="<?= h($avatar) ?>" alt="" width="64" height="64" loading="lazy" decoding="async" referrerpolicy="no-referrer"><?php endif; ?>
          <?php if ($profileUrl !== ''): ?></a><?php else: ?></span><?php endif; ?>
          <div class="douban-showcase__identity"><p class="douban-showcase__name"><?= h($name) ?></p><p class="douban-showcase__profile-meta">我的电影、图书与音乐<?php if ($userId !== ''): ?><span> · <?= h($userId) ?></span><?php endif; ?></p></div>
          <?php if ($profileUrl !== ''): ?><a class="douban-showcase__profile-link" href="<?= h($profileUrl) ?>" target="_blank" rel="noopener noreferrer">豆瓣主页 <?= sblog_douban_icon('arrow') ?></a><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if (!$compact && $state !== 'unconfigured'): ?>
        <nav class="douban-showcase__types" aria-label="记录分类"><?php foreach ($types as $key => $label): ?><a href="<?= h(sblog_douban_url('douban', ['type' => $key, 'status' => $status])) ?>"<?= $type === $key ? ' aria-current="page"' : '' ?>><?= sblog_douban_icon($key) ?><span><?= h($label) ?></span></a><?php endforeach; ?></nav>
        <nav class="douban-showcase__statuses" aria-label="<?= h($types[$type] . '记录状态') ?>"><?php foreach ($statuses as $key => $label): ?><a href="<?= h(sblog_douban_url('douban', ['type' => $type, 'status' => $key])) ?>"<?= $status === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a><?php endforeach; ?></nav>
      <?php endif; ?>
      <?php if ($state === 'stale' || ($state === 'error' && $items !== [])): ?><p class="douban-showcase__notice" role="status"><?= sblog_douban_icon('clock') ?><span><?= h($message) ?></span></p><?php endif; ?>
      <?php if (isset($stateTitles[$state]) && $items === []): ?>
        <div class="douban-showcase__empty douban-showcase__empty--main"><?= sblog_douban_icon($state === 'private' ? 'lock' : $type) ?><p class="douban-showcase__empty-title"><?= h($stateTitles[$state]) ?></p><p><?= h($message) ?></p><?php if ($listUrl !== '' && $state !== 'unconfigured'): ?><a class="douban-showcase__text-link" href="<?= h($listUrl) ?>" target="_blank" rel="noopener noreferrer">在豆瓣查看 <?= sblog_douban_icon('arrow') ?></a><?php endif; ?></div>
      <?php else: ?>
        <div class="douban-showcase__list"<?= $compact ? '' : ' data-douban-list data-page-size="' . h($pageSize) . '"' ?> aria-label="<?= h($listLabel) ?>">
          <div class="douban-showcase__list-heading"><h2><?= h($listLabel) ?></h2><p>已同步 <?= h(number_format($synced)) ?> 条<?php if ($total !== null): ?> <span>/ 共 <?= h(number_format($total)) ?> 条</span><?php endif; ?></p></div>
          <?php if ($items === []): ?><div class="douban-showcase__empty"><?= sblog_douban_icon($type) ?><p class="douban-showcase__empty-title">还没有<?= h($listLabel) ?>记录</p><p>这里会展示该用户在豆瓣公开标记的记录。</p></div><?php else: ?>
            <?php if (!$compact): ?><div class="douban-showcase__controls" data-douban-controls hidden><label class="douban-showcase__search"><?= sblog_douban_icon('search') ?><span class="douban-showcase__sr-only">搜索已同步的标题、短评或标签</span><input type="search" data-douban-search placeholder="搜索标题、短评或标签…" autocomplete="off" maxlength="120"></label><span class="douban-showcase__search-hint">搜索已同步记录</span></div><?php endif; ?>
            <ul class="douban-showcase__items<?= $type === 'music' ? ' douban-showcase__items--music' : '' ?>"<?= $compact ? '' : ' data-douban-items' ?>><?php foreach ($shown as $item): ?><?= sblog_douban_view_item($item, $type, $compact) ?><?php endforeach; ?></ul>
            <?php if (!$compact): ?><div class="douban-showcase__empty" data-douban-no-results hidden><p class="douban-showcase__empty-title">没有找到匹配的记录</p><p>试试其他标题、短评或标签。</p></div><div class="douban-showcase__pagination" data-douban-pagination hidden><p data-douban-result role="status" aria-live="polite" aria-atomic="true"></p><button class="douban-showcase__more" type="button" data-douban-more>加载更多 <span aria-hidden="true">↓</span></button></div><?php endif; ?>
          <?php endif; ?>
        </div>
        <?php if ($truncated): ?><p class="douban-showcase__truncated">本次已同步 <?= h(number_format($synced)) ?> 条记录。<?php if ($listUrl !== ''): ?><a href="<?= h($listUrl) ?>" target="_blank" rel="noopener noreferrer">前往豆瓣查看其余记录 <?= sblog_douban_icon('arrow') ?></a><?php else: ?>其余记录可前往豆瓣查看。<?php endif; ?></p><?php endif; ?>
      <?php endif; ?>
      <?php if ($state !== 'unconfigured'): ?><div class="douban-showcase__footnote"><p>来自豆瓣的公开记录<?php if ($updatedAt > 0): ?> · <time datetime="<?= h(gmdate('Y-m-d\TH:i:s\Z', $updatedAt)) ?>"><?= $state === 'stale' ? '上次同步' : '同步于' ?> <?= h(date('Y-m-d H:i', $updatedAt)) ?></time><?php endif; ?></p><?php if (!$compact && $listUrl !== ''): ?><a class="douban-showcase__text-link" href="<?= h($listUrl) ?>" target="_blank" rel="noopener noreferrer">在豆瓣查看 <?= sblog_douban_icon('arrow') ?></a><?php endif; ?></div><?php endif; ?>
    </section>
    <?php
    return (string)ob_get_clean();
}
