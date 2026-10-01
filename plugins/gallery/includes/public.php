<?php
declare(strict_types=1);

function gallery_public_url(string $categorySlug = '', int $page = 1, bool $uncategorized = false): string
{
    $routeSlug = gallery_setting('route_slug', 'gallery');
    static $routeAvailability = [];
    if (!array_key_exists($routeSlug, $routeAvailability)) {
        $routeAvailability[$routeSlug] = gallery_route_conflicts($routeSlug) === [];
    }
    $url = use_pretty_url() && $routeAvailability[$routeSlug]
        ? app_path('/' . rawurlencode($routeSlug))
        : gallery_action_url('gallery');
    $params = [];
    if ($categorySlug !== '') {
        $params['category'] = $categorySlug;
    } elseif ($uncategorized) {
        $params['uncategorized'] = 1;
    }
    if ($page > 1) {
        $params['p'] = $page;
    }
    return $params === [] ? $url : url_with_query($url, $params);
}

function gallery_safe_media_url(string $url): string
{
    $safe = function_exists('safe_link_url') ? safe_link_url($url) : trim($url);
    if ($safe === '' || $safe === '#' || preg_match('#^(?:https?://|/)#i', $safe) !== 1) {
        return '';
    }
    return $safe;
}

function gallery_item_display_values(array $item): array
{
    $title = trim((string)($item['title'] ?? ''));
    if ($title === '') {
        $title = trim((string)($item['media_title'] ?? ''));
    }
    if ($title === '') {
        $title = trim(pathinfo((string)($item['original_name'] ?? ''), PATHINFO_FILENAME));
    }
    $description = trim((string)($item['description'] ?? ''));
    if ($description === '') {
        $description = trim((string)($item['caption'] ?? ''));
    }
    $alt = trim((string)($item['alt_text'] ?? ''));
    if ($alt === '') {
        $alt = $title;
    }
    return ['title' => $title, 'description' => $description, 'alt' => $alt];
}

function gallery_render_album_list(array $albums): string
{
    ob_start(); ?>
    <div class="sblog-gallery__albums" aria-label="<?= h(sblog_t('图库分类')) ?>">
      <?php foreach ($albums as $album):
          $uncategorized = !empty($album['uncategorized']);
          $name = $uncategorized ? sblog_t('未分类') : (string)$album['name'];
          $thumbnail = gallery_thumbnail_url($album['first_item']);
          $description = trim((string)($album['description'] ?? ''));
      ?>
        <a class="sblog-gallery__album" href="<?= h(gallery_public_url((string)$album['slug'], 1, $uncategorized)) ?>">
          <span class="sblog-gallery__media">
            <?php if ($thumbnail !== ''): ?><img class="sblog-gallery__image" src="<?= h($thumbnail) ?>" alt="" width="640" height="480" loading="lazy" decoding="async"><?php endif; ?>
            <span class="sblog-gallery__image-error"<?= $thumbnail !== '' ? ' hidden' : '' ?>><?= h(sblog_t('图片暂时无法加载')) ?></span>
          </span>
          <span class="sblog-gallery__album-info"><strong><?= h($name) ?></strong><span><?= h(sblog_t('{count} 张图片', ['count' => (int)$album['published_count']])) ?></span></span>
          <?php if ($description !== ''): ?><span class="sblog-gallery__description-text"><?= h($description) ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php return (string)ob_get_clean();
}

function gallery_render_public_pagination(array $result, string $categorySlug, bool $uncategorized = false): string
{
    $page = max(1, (int)($result['page'] ?? 1));
    $pages = max(1, (int)($result['pages'] ?? 1));
    if ($pages <= 1) {
        return '';
    }
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    ob_start(); ?>
    <nav class="sblog-gallery__pagination" aria-label="<?= h(sblog_t('分页')) ?>">
      <?php if ($page > 1): ?><a rel="prev" href="<?= h(gallery_public_url($categorySlug, $page - 1, $uncategorized)) ?>"><?= h(sblog_t('上一页')) ?></a><?php endif; ?>
      <?php for ($number = $start; $number <= $end; $number++): ?>
        <?php if ($number === $page): ?><span aria-current="page"><?= $number ?></span><?php else: ?><a href="<?= h(gallery_public_url($categorySlug, $number, $uncategorized)) ?>"><?= $number ?></a><?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $pages): ?><a rel="next" href="<?= h(gallery_public_url($categorySlug, $page + 1, $uncategorized)) ?>"><?= h(sblog_t('下一页')) ?></a><?php endif; ?>
    </nav>
    <?php return (string)ob_get_clean();
}

function gallery_render_lightbox(): string
{
    ob_start(); ?>
    <dialog id="sblog-gallery-lightbox" class="sblog-gallery-lightbox" data-sblog-gallery-lightbox aria-labelledby="sblog-gallery-lightbox-title" hidden>
      <div class="sblog-gallery-lightbox__dialog">
        <button class="sblog-gallery-lightbox__close" type="button" data-sblog-gallery-lightbox-close aria-label="<?= h(sblog_t('关闭')) ?>" title="<?= h(sblog_t('关闭')) ?>">&times;</button>
        <button class="sblog-gallery-lightbox__previous" type="button" data-sblog-gallery-lightbox-previous aria-label="<?= h(sblog_t('上一张')) ?>" title="<?= h(sblog_t('上一张')) ?>">&larr;</button>
        <figure>
          <div class="sblog-gallery-lightbox__media"><img class="sblog-gallery-lightbox__image" data-sblog-gallery-lightbox-image alt=""><span data-sblog-gallery-lightbox-error hidden><?= h(sblog_t('图片暂时无法加载')) ?></span></div>
          <figcaption class="sblog-gallery-lightbox__caption"><strong id="sblog-gallery-lightbox-title" data-sblog-gallery-lightbox-title></strong><p data-sblog-gallery-lightbox-description></p><a data-sblog-gallery-lightbox-original target="_blank" rel="noopener noreferrer"><?= h(sblog_t('打开原图')) ?></a></figcaption>
        </figure>
        <button class="sblog-gallery-lightbox__next" type="button" data-sblog-gallery-lightbox-next aria-label="<?= h(sblog_t('下一张')) ?>" title="<?= h(sblog_t('下一张')) ?>">&rarr;</button>
        <p class="sblog-gallery-lightbox__counter" data-sblog-gallery-lightbox-counter aria-live="polite"></p>
      </div>
    </dialog>
    <?php return (string)ob_get_clean();
}

function gallery_public_page_view(array $query): array
{
    $settings = gallery_settings();
    $title = trim((string)($settings['title'] ?? '')) ?: sblog_t('图库');
    $description = trim((string)($settings['description'] ?? ''));
    $categoryValue = $query['category'] ?? '';
    $categorySlug = trim(is_scalar($categoryValue) ? (string)$categoryValue : '');
    $uncategorized = $categorySlug === '' && ($query['uncategorized'] ?? null) === '1';
    $index = $categorySlug === '' && !$uncategorized;
    $pageValue = $query['p'] ?? $query['page'] ?? 1;
    $page = max(1, is_scalar($pageValue) ? (int)$pageValue : 1);
    $perPage = min(60, max(6, (int)($settings['per_page'] ?? 12)));
    $result = $index ? [] : gallery_public_items([
        'category_slug' => $categorySlug,
        'uncategorized' => $uncategorized,
        'page' => $page,
        'per_page' => $perPage,
    ]);
    $category = $result['category'] ?? null;
    $missingCategory = $categorySlug !== '' && !is_array($category);
    $pageTitle = is_array($category) ? (string)$category['name'] . ' · ' . $title
        : ($uncategorized ? sblog_t('未分类') . ' · ' . $title : $title);
    $pageDescription = is_array($category) && trim((string)($category['description'] ?? '')) !== ''
        ? trim((string)$category['description'])
        : $description;
    return [
        'title' => $pageTitle,
        'description' => $pageDescription,
        'category_slug' => $categorySlug,
        'uncategorized' => $uncategorized,
        'index' => $index,
        'missing_category' => $missingCategory,
        'result' => $result,
        'albums' => $index ? gallery_public_albums() : [],
    ];
}

function gallery_render_public_content(array $view): string
{
    $result = $view['result'];
    ob_start(); ?>
    <section id="sblog-gallery" class="sblog-gallery" aria-labelledby="sblog-gallery-title">
      <header class="sblog-gallery__header">
        <?php if (!$view['index']): ?><a class="sblog-gallery__back" href="<?= h(gallery_public_url()) ?>">&larr; <?= h(sblog_t('返回分类列表')) ?></a><?php endif; ?>
        <h1 id="sblog-gallery-title"><?= h($view['title']) ?></h1>
        <?php if ($view['description'] !== ''): ?><p class="sblog-gallery__description"><?= nl2br(h($view['description'])) ?></p><?php endif; ?>
      </header>

      <?php if ($view['index'] && $view['albums'] !== []): ?>
        <?= gallery_render_album_list($view['albums']) ?>
      <?php elseif ($view['missing_category']): ?>
        <div class="sblog-gallery__status" role="status"><h2><?= h(sblog_t('找不到图库分类')) ?></h2><p><a href="<?= h(gallery_public_url()) ?>"><?= h(sblog_t('返回分类列表')) ?></a></p></div>
      <?php elseif (!empty($result['items'])): ?>
        <div class="sblog-gallery__grid">
          <?php foreach ($result['items'] as $item): $display = gallery_item_display_values($item); $mediaUrl = gallery_safe_media_url((string)($item['url'] ?? '')); $thumbnailUrl = gallery_thumbnail_url($item); ?>
            <article class="sblog-gallery__item">
              <?php if ($mediaUrl !== ''): ?><a class="sblog-gallery__open" href="<?= h($mediaUrl) ?>" data-sblog-gallery-open-item data-title="<?= h($display['title']) ?>" data-description="<?= h($display['description']) ?>"><?php else: ?><span class="sblog-gallery__open sblog-gallery__open--unavailable"><?php endif; ?>
                <span class="sblog-gallery__media">
                  <?php if ($mediaUrl !== ''): ?><img class="sblog-gallery__image" src="<?= h($thumbnailUrl) ?>" alt="<?= h($display['alt']) ?>" loading="lazy" decoding="async"<?= (int)($item['width'] ?? 0) > 0 ? ' width="' . (int)$item['width'] . '"' : '' ?><?= (int)($item['height'] ?? 0) > 0 ? ' height="' . (int)$item['height'] . '"' : '' ?>><?php endif; ?>
                  <span class="sblog-gallery__image-error"<?= $mediaUrl !== '' ? ' hidden' : '' ?>><?= h(sblog_t('图片暂时无法加载')) ?></span>
                </span>
              <?= $mediaUrl !== '' ? '</a>' : '</span>' ?>
              <?php if ($display['title'] !== '' || $display['description'] !== ''): ?><div class="sblog-gallery__caption"><?php if ($display['title'] !== ''): ?><h2 class="sblog-gallery__title"><?= h($display['title']) ?></h2><?php endif; ?><?php if ($display['description'] !== ''): ?><p class="sblog-gallery__description-text"><?= nl2br(h($display['description'])) ?></p><?php endif; ?></div><?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
        <?= gallery_render_public_pagination($result, $view['category_slug'], $view['uncategorized']) ?>
      <?php else: ?>
        <div class="sblog-gallery__status" role="status"><p><?= h($view['index'] ? sblog_t('图库中还没有公开图片。') : sblog_t('此分类中还没有公开图片。')) ?></p></div>
      <?php endif; ?>
    </section>
    <?= !$view['index'] && !empty($result['items']) ? gallery_render_lightbox() : '' ?>
    <?php
    return (string)ob_get_clean();
}

function gallery_render_public_page(): never
{
    $view = gallery_public_page_view($_GET);
    render_layout($view['title'], gallery_render_public_content($view), [
        'mode' => 'public',
        'active' => 'gallery',
        'description' => $view['description'] !== '' ? $view['description'] : $view['title'],
        'status' => $view['missing_category'] ? 404 : 200,
    ]);
    exit;
}
