<?php
declare(strict_types=1);

function gallery_public_url(string $categorySlug = '', int $page = 1): string
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

function gallery_render_category_filters(array $categories, string $categorySlug): string
{
    if ($categories === []) {
        return '';
    }
    ob_start(); ?>
    <nav class="sblog-gallery__filters" aria-label="<?= h(sblog_t('图库分类')) ?>">
      <a class="sblog-gallery__filter" href="<?= h(gallery_public_url()) ?>"<?= $categorySlug === '' ? ' aria-current="page"' : '' ?>><?= h(sblog_t('全部')) ?></a>
      <?php foreach ($categories as $category): $coverUrl = gallery_category_thumbnail_url($category); ?>
        <a class="sblog-gallery__filter<?= $coverUrl !== '' ? ' sblog-gallery__filter--with-cover' : '' ?>" href="<?= h(gallery_public_url((string)$category['slug'])) ?>"<?= $categorySlug === (string)$category['slug'] ? ' aria-current="page"' : '' ?>>
          <?php if ($coverUrl !== ''): ?><img class="sblog-gallery__filter-cover" src="<?= h($coverUrl) ?>" alt="" width="36" height="36" loading="lazy" decoding="async"><?php endif; ?>
          <?= h((string)$category['name']) ?><span><?= (int)($category['published_count'] ?? $category['item_count'] ?? 0) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php return (string)ob_get_clean();
}

function gallery_render_public_pagination(array $result, string $categorySlug): string
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
      <?php if ($page > 1): ?><a rel="prev" href="<?= h(gallery_public_url($categorySlug, $page - 1)) ?>"><?= h(sblog_t('上一页')) ?></a><?php endif; ?>
      <?php for ($number = $start; $number <= $end; $number++): ?>
        <?php if ($number === $page): ?><span aria-current="page"><?= $number ?></span><?php else: ?><a href="<?= h(gallery_public_url($categorySlug, $number)) ?>"><?= $number ?></a><?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $pages): ?><a rel="next" href="<?= h(gallery_public_url($categorySlug, $page + 1)) ?>"><?= h(sblog_t('下一页')) ?></a><?php endif; ?>
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

function gallery_render_public_page(): never
{
    $settings = gallery_settings();
    $title = trim((string)($settings['title'] ?? '')) ?: sblog_t('图库');
    $description = trim((string)($settings['description'] ?? ''));
    $categoryValue = $_GET['category'] ?? '';
    $categorySlug = trim(is_scalar($categoryValue) ? (string)$categoryValue : '');
    $pageValue = $_GET['p'] ?? $_GET['page'] ?? 1;
    $page = max(1, is_scalar($pageValue) ? (int)$pageValue : 1);
    $perPage = min(60, max(6, (int)($settings['per_page'] ?? 12)));
    $result = gallery_public_items([
        'category_slug' => $categorySlug,
        'page' => $page,
        'per_page' => $perPage,
    ]);
    $categories = gallery_categories(false);
    $category = $result['category'] ?? null;
    $missingCategory = $categorySlug !== '' && !is_array($category);
    $pageTitle = is_array($category) ? (string)$category['name'] . ' · ' . $title : $title;
    $pageDescription = is_array($category) && trim((string)($category['description'] ?? '')) !== ''
        ? trim((string)$category['description'])
        : $description;

    ob_start(); ?>
    <section id="sblog-gallery" class="sblog-gallery" aria-labelledby="sblog-gallery-title">
      <header class="sblog-gallery__header">
        <h1 id="sblog-gallery-title"><?= h($pageTitle) ?></h1>
        <?php if ($pageDescription !== ''): ?><p class="sblog-gallery__description"><?= nl2br(h($pageDescription)) ?></p><?php endif; ?>
      </header>
      <?= gallery_render_category_filters($categories, $categorySlug) ?>

      <?php if ($missingCategory): ?>
        <div class="sblog-gallery__status" role="status"><h2><?= h(sblog_t('找不到图库分类')) ?></h2><p><a href="<?= h(gallery_public_url()) ?>"><?= h(sblog_t('查看全部图片')) ?></a></p></div>
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
        <?= gallery_render_public_pagination($result, $categorySlug) ?>
      <?php else: ?>
        <div class="sblog-gallery__status" role="status"><p><?= h($categorySlug === '' ? sblog_t('图库中还没有公开图片。') : sblog_t('此分类中还没有公开图片。')) ?></p></div>
      <?php endif; ?>
    </section>
    <?= gallery_render_lightbox() ?>
    <?php
    render_layout($pageTitle, (string)ob_get_clean(), [
        'mode' => 'public',
        'active' => 'gallery',
        'description' => $pageDescription !== '' ? $pageDescription : $title,
        'status' => $missingCategory ? 404 : 200,
    ]);
    exit;
}
