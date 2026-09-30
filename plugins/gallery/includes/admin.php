<?php
declare(strict_types=1);

function gallery_action_url(string $action, array $params = []): string
{
    return script_url() . '?' . http_build_query(array_merge(['a' => $action], $params));
}

function gallery_admin_url(array $params = []): string
{
    return gallery_action_url('admin_gallery', $params);
}

function gallery_wants_json(): bool
{
    return strcasecmp((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'XMLHttpRequest') === 0
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function gallery_request_ids(mixed $values, int $limit = 100): array
{
    $values = is_array($values)
        ? array_values(array_filter($values, 'is_scalar'))
        : (is_scalar($values) ? [$values] : []);
    return positive_int_ids($values, $limit);
}

function gallery_redirect_with_flash(string $type, string $message, array $params = []): never
{
    set_flash($type, $message);
    redirect_to(gallery_admin_url($params), 303);
}

function gallery_operation_error(Throwable $exception, string $fallback): string
{
    if ($exception instanceof InvalidArgumentException || $exception instanceof DomainException) {
        return trim($exception->getMessage()) ?: $fallback;
    }
    error_log('Gallery operation failed: ' . $exception->getMessage());
    return $fallback;
}

function gallery_emit_change(string $operation, array $itemIds = [], array $categoryIds = []): void
{
    plugin_action('gallery_changed', [
        'operation' => $operation,
        'item_ids' => array_values(array_map('intval', $itemIds)),
        'category_ids' => array_values(array_map('intval', $categoryIds)),
    ]);
}

function gallery_handle_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    switch ($action) {
        case 'gallery':
            try {
                gallery_render_public_page();
            } catch (Throwable $exception) {
                error_log('Gallery public page failed: ' . $exception->getMessage());
                simple_error_page(
                    sblog_t('图库暂时无法访问'),
                    sblog_t('图库加载失败，请稍后重试。'),
                    500
                );
            }
            return;

        case 'admin_gallery':
            try {
                gallery_render_admin_page();
            } catch (Throwable $exception) {
                error_log('Gallery admin page failed: ' . $exception->getMessage());
                simple_error_page(
                    sblog_t('图库管理暂时无法访问'),
                    sblog_t('图库管理页面加载失败，请检查服务器日志。'),
                    500
                );
            }
            return;

        case 'gallery_media_search':
            require_admin();
            gallery_handle_media_search();
            return;

        case 'gallery_add_items':
            gallery_handle_add_items();
            return;

        case 'gallery_update_item':
            gallery_handle_update_item();
            return;

        case 'gallery_remove_items':
            gallery_handle_remove_items();
            return;

        case 'save_gallery_category':
            gallery_handle_save_category();
            return;

        case 'delete_gallery_category':
            gallery_handle_delete_category();
            return;

        case 'save_gallery_settings':
            gallery_handle_save_settings();
            return;
    }
}

function gallery_handle_media_search(): never
{
    try {
        $queryValue = $_GET['q'] ?? '';
        $pageValue = $_GET['page'] ?? 1;
        $result = gallery_media_search([
            'q' => trim(is_scalar($queryValue) ? (string)$queryValue : ''),
            'page' => max(1, is_scalar($pageValue) ? (int)$pageValue : 1),
            'per_page' => 24,
        ]);
        foreach ($result['items'] as &$item) {
            $item['url'] = gallery_safe_media_url((string)($item['url'] ?? ''));
        }
        unset($item);
        json_response(['ok' => true] + $result);
    } catch (Throwable $exception) {
        json_response([
            'ok' => false,
            'error' => gallery_operation_error($exception, sblog_t('媒体库加载失败，请稍后重试。')),
        ], 500);
    }
}

function gallery_handle_add_items(): never
{
    $fallback = gallery_admin_url(['tab' => 'images']);
    require_admin_post($fallback);
    $mediaIds = gallery_request_ids($_POST['media_ids'] ?? [], SBLOG_GALLERY_MAX_BATCH_SIZE + 1);
    $categoryValue = $_POST['category_id'] ?? 0;
    $categoryId = max(0, is_scalar($categoryValue) ? (int)$categoryValue : 0);

    try {
        if (count($mediaIds) > SBLOG_GALLERY_MAX_BATCH_SIZE) {
            throw new InvalidArgumentException(sblog_t('一次最多只能添加 100 张图片。'));
        }
        $result = gallery_add_media_items($mediaIds, $categoryId > 0 ? $categoryId : null);
        gallery_emit_change('items_added', $result['item_ids'] ?? [], $categoryId > 0 ? [$categoryId] : []);
        if (gallery_wants_json()) {
            json_response([
                'ok' => true,
                'added' => (int)($result['added'] ?? 0),
                'existing' => (int)($result['existing'] ?? 0),
                'invalid' => (int)($result['invalid'] ?? 0),
                'message' => sblog_t('所选图片已加入图库。'),
            ]);
        }
        gallery_redirect_with_flash('success', sblog_t('所选图片已加入图库。'), ['tab' => 'images']);
    } catch (Throwable $exception) {
        $message = gallery_operation_error($exception, sblog_t('图片加入图库失败，请稍后重试。'));
        if (gallery_wants_json()) {
            json_response(['ok' => false, 'error' => $message], $exception instanceof InvalidArgumentException ? 422 : 500);
        }
        gallery_redirect_with_flash('error', $message, ['tab' => 'images']);
    }
}

function gallery_handle_update_item(): never
{
    $fallback = gallery_admin_url(['tab' => 'images']);
    require_admin_post($fallback);
    $idValue = $_POST['id'] ?? 0;
    $titleValue = $_POST['title'] ?? '';
    $descriptionValue = $_POST['description'] ?? '';
    $categoryValue = $_POST['category_id'] ?? 0;
    $sortValue = $_POST['sort_order'] ?? 0;
    $id = max(0, is_scalar($idValue) ? (int)$idValue : 0);
    $input = [
        'title' => trim(is_scalar($titleValue) ? (string)$titleValue : ''),
        'description' => trim(is_scalar($descriptionValue) ? (string)$descriptionValue : ''),
        'category_id' => max(0, is_scalar($categoryValue) ? (int)$categoryValue : 0) ?: null,
        'sort_order' => is_scalar($sortValue) ? (int)$sortValue : 0,
        'status' => isset($_POST['is_published']) ? 'published' : 'draft',
    ];

    if ($id < 1 || str_sub_u($input['title'], 0, 256) !== $input['title'] || str_sub_u($input['description'], 0, 2001) !== $input['description']) {
        gallery_redirect_with_flash('error', sblog_t('图片信息无效或内容过长。'), ['tab' => 'images']);
    }
    try {
        if (!gallery_update_item_record($id, $input)) {
            throw new DomainException(sblog_t('找不到图库图片。'));
        }
        gallery_emit_change('item_updated', [$id], $input['category_id'] ? [(int)$input['category_id']] : []);
        gallery_redirect_with_flash('success', sblog_t('图片信息已保存。'), ['tab' => 'images']);
    } catch (Throwable $exception) {
        gallery_redirect_with_flash('error', gallery_operation_error(
            $exception,
            sblog_t('图片信息保存失败，请稍后重试。')
        ), ['tab' => 'images']);
    }
}

function gallery_handle_remove_items(): never
{
    $fallback = gallery_admin_url(['tab' => 'images']);
    require_admin_post($fallback);
    $ids = gallery_request_ids($_POST['item_ids'] ?? $_POST['id'] ?? [], 100);
    try {
        $removed = gallery_remove_items($ids);
        if ($removed > 0) {
            gallery_emit_change('items_removed', $ids);
            gallery_redirect_with_flash('success', sblog_t('图片已从图库移除，媒体库中的原文件仍然保留。'), ['tab' => 'images']);
        }
        gallery_redirect_with_flash('error', sblog_t('找不到图库图片。'), ['tab' => 'images']);
    } catch (Throwable $exception) {
        gallery_redirect_with_flash('error', gallery_operation_error(
            $exception,
            sblog_t('图片移除失败，请稍后重试。')
        ), ['tab' => 'images']);
    }
}

function gallery_handle_save_category(): never
{
    $fallback = gallery_admin_url(['tab' => 'categories']);
    require_admin_post($fallback);
    try {
        $idValue = $_POST['id'] ?? 0;
        $id = max(0, is_scalar($idValue) ? (int)$idValue : 0);
        $existing = $id > 0 ? one('SELECT * FROM sblog_gallery_categories WHERE id = ?', [$id]) : null;
        if ($id > 0 && $existing === null) {
            throw new DomainException(sblog_t('找不到分类。'));
        }
        [$data, $errors] = gallery_validate_category($_POST, $existing);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        $savedId = gallery_save_category_record($data, $id > 0 ? $id : null);
        gallery_emit_change($id > 0 ? 'category_updated' : 'category_created', [], [$savedId]);
        gallery_redirect_with_flash('success', sblog_t('分类已保存。'), ['tab' => 'categories']);
    } catch (Throwable $exception) {
        gallery_redirect_with_flash('error', gallery_operation_error(
            $exception,
            sblog_t('分类保存失败，请稍后重试。')
        ), ['tab' => 'categories']);
    }
}

function gallery_handle_delete_category(): never
{
    $fallback = gallery_admin_url(['tab' => 'categories']);
    require_admin_post($fallback);
    try {
        $idValue = $_POST['id'] ?? 0;
        $id = max(0, is_scalar($idValue) ? (int)$idValue : 0);
        if ($id > 0 && gallery_delete_category_record($id)) {
            gallery_emit_change('category_deleted', [], [$id]);
            gallery_redirect_with_flash('success', sblog_t('分类已删除，原分类中的图片已改为未分类。'), ['tab' => 'categories']);
        }
        gallery_redirect_with_flash('error', sblog_t('找不到分类。'), ['tab' => 'categories']);
    } catch (Throwable $exception) {
        gallery_redirect_with_flash('error', gallery_operation_error(
            $exception,
            sblog_t('分类删除失败，请稍后重试。')
        ), ['tab' => 'categories']);
    }
}

function gallery_handle_save_settings(): never
{
    $fallback = gallery_admin_url(['tab' => 'settings']);
    require_admin_post($fallback);
    try {
        $routeValue = $_POST['route_slug'] ?? '';
        $titleValue = $_POST['title'] ?? '';
        $descriptionValue = $_POST['description'] ?? '';
        $perPageValue = $_POST['per_page'] ?? 12;
        [$routeSlug, $routeErrors] = gallery_validate_route_slug(is_scalar($routeValue) ? (string)$routeValue : '');
        $title = trim(is_scalar($titleValue) ? (string)$titleValue : '');
        $description = trim(is_scalar($descriptionValue) ? (string)$descriptionValue : '');
        $perPage = is_scalar($perPageValue) ? (int)$perPageValue : 0;
        $errors = $routeErrors;
        if ($title === '' || str_sub_u($title, 0, 121) !== $title) {
            $errors[] = sblog_t('页面标题不能为空，且不能超过 120 个字符。');
        }
        if (str_sub_u($description, 0, 1001) !== $description) {
            $errors[] = sblog_t('页面描述不能超过 1000 个字符。');
        }
        if ($perPage < 6 || $perPage > 60) {
            $errors[] = sblog_t('每页图片数必须在 6 到 60 之间。');
        }
        $errors = array_merge($errors, gallery_route_conflicts($routeSlug));
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', array_unique($errors)));
        }
        gallery_save_settings_values([
            'route_slug' => $routeSlug,
            'title' => $title,
            'description' => $description,
            'per_page' => (string)$perPage,
        ]);
        gallery_emit_change('settings_updated');
        gallery_redirect_with_flash('success', sblog_t('图库设置已保存。'), ['tab' => 'settings']);
    } catch (Throwable $exception) {
        gallery_redirect_with_flash('error', gallery_operation_error(
            $exception,
            sblog_t('图库设置保存失败，请稍后重试。')
        ), ['tab' => 'settings']);
    }
}

function gallery_render_admin_pagination(array $result, array $query): string
{
    $page = max(1, (int)($result['page'] ?? 1));
    $pages = max(1, (int)($result['pages'] ?? 1));
    if ($pages <= 1) {
        return '';
    }
    ob_start(); ?>
    <nav class="admin-pagination" aria-label="<?= h(sblog_t('分页')) ?>">
      <?php if ($page > 1): ?><a href="<?= h(gallery_admin_url(array_merge($query, ['page' => $page - 1]))) ?>"><?= h(sblog_t('上一页')) ?></a><?php endif; ?>
      <span aria-current="page"><?= h((string)$page) ?> / <?= h((string)$pages) ?></span>
      <?php if ($page < $pages): ?><a href="<?= h(gallery_admin_url(array_merge($query, ['page' => $page + 1]))) ?>"><?= h(sblog_t('下一页')) ?></a><?php endif; ?>
    </nav>
    <?php return (string)ob_get_clean();
}

function gallery_render_admin_page(): never
{
    require_admin();
    $tabValue = $_GET['tab'] ?? 'images';
    $tab = is_scalar($tabValue) ? (string)$tabValue : 'images';
    if (!in_array($tab, ['images', 'categories', 'settings'], true)) {
        $tab = 'images';
    }
    $categories = gallery_categories(true);
    $settings = gallery_settings();
    $queryValue = $_GET['q'] ?? '';
    $categoryValue = $_GET['category_id'] ?? 0;
    $statusValue = $_GET['status'] ?? '';
    $pageValue = $_GET['page'] ?? 1;
    $status = is_scalar($statusValue) ? (string)$statusValue : '';
    $query = [
        'tab' => 'images',
        'q' => trim(is_scalar($queryValue) ? (string)$queryValue : ''),
        'category_id' => max(0, is_scalar($categoryValue) ? (int)$categoryValue : 0),
        'status' => in_array($status, ['published', 'draft'], true) ? $status : '',
        'page' => max(1, is_scalar($pageValue) ? (int)$pageValue : 1),
        'per_page' => 18,
    ];
    $galleryItems = $tab === 'images' ? gallery_admin_items($query) : ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
    $tabs = [
        'images' => sblog_t('图片'),
        'categories' => sblog_t('分类'),
        'settings' => sblog_t('设置'),
    ];

    ob_start(); ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('gallery') ?>
      <div class="admin-main">
        <?= render_admin_topbar(sblog_t('图库管理')) ?>
        <div class="media-library sblog-gallery-admin admin-animate admin-animate--2"
             data-sblog-gallery-admin
             data-media-url="<?= h(gallery_action_url('gallery_media_search')) ?>"
             data-add-url="<?= h(gallery_action_url('gallery_add_items')) ?>"
             data-upload-url="<?= h(url_for('upload_attachment')) ?>"
             data-selection-limit="<?= SBLOG_GALLERY_MAX_BATCH_SIZE ?>"
             data-refresh-on-add="1"
             data-csrf="<?= h(csrf_token()) ?>">
          <nav class="sblog-gallery-admin__tabs" aria-label="<?= h(sblog_t('图库管理')) ?>">
            <?php foreach ($tabs as $key => $label): ?>
              <a href="<?= h(gallery_admin_url(['tab' => $key])) ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
          </nav>

          <?php if ($tab === 'images'): ?>
            <div class="sblog-gallery-admin__toolbar">
              <div><strong><?= h(sblog_t('图库图片')) ?></strong><span><?= h(sblog_tn('{count} 项', (int)($galleryItems['total'] ?? 0))) ?></span></div>
              <button class="button" type="button" data-sblog-gallery-open><?= admin_icon('media') ?><span><?= h(sblog_t('添加图片')) ?></span></button>
            </div>
            <form class="media-library-toolbar" method="get" action="<?= h(script_url()) ?>">
              <input type="hidden" name="a" value="admin_gallery"><input type="hidden" name="tab" value="images">
              <label class="media-library-search"><span class="sr-only"><?= h(sblog_t('搜索图片')) ?></span><input name="q" type="search" value="<?= h((string)$query['q']) ?>" placeholder="<?= h(sblog_t('搜索图片')) ?>"></label>
              <label class="media-library-filter"><span class="sr-only"><?= h(sblog_t('分类')) ?></span><select name="category_id"><option value="0"><?= h(sblog_t('全部分类')) ?></option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>"<?= (int)$query['category_id'] === (int)$category['id'] ? ' selected' : '' ?>><?= h((string)$category['name']) ?></option><?php endforeach; ?></select></label>
              <label class="media-library-filter"><span class="sr-only"><?= h(sblog_t('状态')) ?></span><select name="status"><option value=""><?= h(sblog_t('全部状态')) ?></option><option value="published"<?= $query['status'] === 'published' ? ' selected' : '' ?>><?= h(sblog_t('发布')) ?></option><option value="draft"<?= $query['status'] === 'draft' ? ' selected' : '' ?>><?= h(sblog_t('草稿')) ?></option></select></label>
              <button class="button button--secondary" type="submit"><?= h(sblog_t('筛选')) ?></button>
            </form>

            <?php if (!empty($galleryItems['items'])): ?>
              <div class="sblog-gallery-admin__items">
                <?php foreach ($galleryItems['items'] as $item):
                    $displayTitle = trim((string)$item['title']) ?: trim((string)($item['media_title'] ?? '')) ?: (string)($item['original_name'] ?? '');
                    $alt = trim((string)($item['alt_text'] ?? '')) ?: $displayTitle;
                    $mediaUrl = gallery_safe_media_url((string)($item['url'] ?? ''));
                ?>
                  <article class="sblog-gallery-admin__item">
                    <?php if ($mediaUrl !== ''): ?><a class="sblog-gallery-admin__preview" href="<?= h($mediaUrl) ?>" target="_blank" rel="noopener noreferrer"><img src="<?= h($mediaUrl) ?>" alt="<?= h($alt) ?>" loading="lazy"<?= (int)($item['width'] ?? 0) > 0 ? ' width="' . (int)$item['width'] . '"' : '' ?><?= (int)($item['height'] ?? 0) > 0 ? ' height="' . (int)$item['height'] . '"' : '' ?>></a><?php else: ?><div class="sblog-gallery-admin__preview sblog-gallery-admin__preview--unavailable"><span><?= h(sblog_t('图片暂时无法加载')) ?></span></div><?php endif; ?>
                    <form class="form-stack sblog-gallery-admin__item-form" method="post" action="<?= h(gallery_action_url('gallery_update_item')) ?>">
                      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                      <div class="field"><label for="gallery-title-<?= (int)$item['id'] ?>"><?= h(sblog_t('标题')) ?></label><input id="gallery-title-<?= (int)$item['id'] ?>" name="title" maxlength="255" value="<?= h((string)$item['title']) ?>" placeholder="<?= h($displayTitle) ?>"></div>
                      <div class="field"><label for="gallery-description-<?= (int)$item['id'] ?>"><?= h(sblog_t('描述')) ?></label><textarea id="gallery-description-<?= (int)$item['id'] ?>" name="description" rows="3" maxlength="2000"><?= h((string)$item['description']) ?></textarea></div>
                      <div class="field-grid">
                        <div class="field"><label for="gallery-category-<?= (int)$item['id'] ?>"><?= h(sblog_t('分类')) ?></label><select id="gallery-category-<?= (int)$item['id'] ?>" name="category_id"><option value="0"><?= h(sblog_t('未分类')) ?></option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>"<?= (int)($item['category_id'] ?? 0) === (int)$category['id'] ? ' selected' : '' ?>><?= h((string)$category['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label for="gallery-order-<?= (int)$item['id'] ?>"><?= h(sblog_t('排序')) ?></label><input id="gallery-order-<?= (int)$item['id'] ?>" name="sort_order" type="number" min="-999999" max="999999" value="<?= (int)$item['sort_order'] ?>"></div>
                      </div>
                      <label class="sblog-gallery-admin__switch"><input type="checkbox" name="is_published" value="1"<?= (string)$item['status'] === 'published' ? ' checked' : '' ?>><span><?= h(sblog_t('发布')) ?></span></label>
                      <div class="action-row"><button class="button button--secondary" type="submit"><?= h(sblog_t('保存图片')) ?></button></div>
                    </form>
                    <form class="sblog-gallery-admin__remove" method="post" action="<?= h(gallery_action_url('gallery_remove_items')) ?>" onsubmit="return confirm(<?= h(json_encode(sblog_t('只从图库移除此图片？媒体库中的原文件会保留。'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>);">
                      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="button button--danger" type="submit"><?= h(sblog_t('从图库移除')) ?></button>
                    </form>
                  </article>
                <?php endforeach; ?>
              </div>
              <?= gallery_render_admin_pagination($galleryItems, array_filter($query, static fn(mixed $value): bool => $value !== '' && $value !== 0)) ?>
            <?php else: ?>
              <div class="empty-state sblog-gallery-admin__empty"><p><strong><?= h(sblog_t('还没有图库图片。')) ?></strong></p><p><?= h(sblog_t('从媒体库选择已有图片，或上传新图片。')) ?></p><button class="button" type="button" data-sblog-gallery-open><?= h(sblog_t('添加图片')) ?></button></div>
            <?php endif; ?>

            <?= gallery_render_picker_dialog($categories) ?>
          <?php elseif ($tab === 'categories'): ?>
            <?= gallery_render_categories_panel($categories) ?>
          <?php else: ?>
            <?= gallery_render_settings_panel($settings) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    render_layout(sblog_t('图库管理'), (string)ob_get_clean(), [
        'active' => 'gallery',
        'wide' => true,
        'description' => sblog_t('图片、分类与公开页面'),
    ]);
    exit;
}

function gallery_render_picker_dialog(array $categories): string
{
    ob_start(); ?>
    <dialog class="sblog-gallery-admin__dialog" data-sblog-gallery-dialog aria-labelledby="sblog-gallery-picker-title" hidden>
      <div class="sblog-gallery-admin__dialog-shell">
        <header class="sblog-gallery-admin__dialog-header"><div><h2 id="sblog-gallery-picker-title"><?= h(sblog_t('添加图片')) ?></h2><p><?= h(sblog_t('从媒体库选择已有图片，或上传新图片。')) ?></p></div><button class="admin-icon-btn" type="button" data-sblog-gallery-close aria-label="<?= h(sblog_t('关闭')) ?>" title="<?= h(sblog_t('关闭')) ?>"><?= admin_icon('close') ?></button></header>
        <div class="sblog-gallery-admin__picker-tabs" role="tablist" aria-label="<?= h(sblog_t('添加图片')) ?>">
          <button type="button" role="tab" aria-selected="true" data-sblog-gallery-picker-tab="library"><?= h(sblog_t('从媒体库选择')) ?></button>
          <button type="button" role="tab" aria-selected="false" data-sblog-gallery-picker-tab="upload"><?= h(sblog_t('上传新图片')) ?></button>
        </div>
        <section data-sblog-gallery-picker-panel="library">
          <form class="sblog-gallery-admin__picker-search" data-sblog-gallery-search><label class="sr-only" for="sblog-gallery-media-search"><?= h(sblog_t('搜索图片')) ?></label><input id="sblog-gallery-media-search" type="search" name="q" placeholder="<?= h(sblog_t('搜索图片')) ?>"><button class="button button--secondary" type="submit"><?= h(sblog_t('搜索')) ?></button></form>
          <p class="sblog-gallery-admin__picker-status" data-sblog-gallery-picker-status aria-live="polite"></p>
          <div class="sblog-gallery-admin__picker-results" data-sblog-gallery-results aria-busy="false"></div>
          <div data-sblog-gallery-pagination></div>
        </section>
        <section data-sblog-gallery-picker-panel="upload" hidden>
          <div class="attachment-uploader">
            <input id="sblogGalleryUpload" class="attachment-input" type="file" name="attachments[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple data-sblog-gallery-upload>
            <label class="attachment-drop" for="sblogGalleryUpload"><span class="attachment-drop__title"><?= h(sblog_t('选择或拖入图片')) ?></span><span class="attachment-drop__hint"><?= h(sblog_t('支持 JPEG、PNG、GIF 和 WebP，每个最大 30M。')) ?></span></label>
            <div class="attachment-list" data-sblog-gallery-upload-status aria-live="polite"></div>
          </div>
        </section>
        <footer class="sblog-gallery-admin__dialog-footer">
          <label><span><?= h(sblog_t('加入分类')) ?></span><select data-sblog-gallery-category><option value="0"><?= h(sblog_t('未分类')) ?></option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>"><?= h((string)$category['name']) ?></option><?php endforeach; ?></select></label>
          <span data-sblog-gallery-selected-count aria-live="polite"><?= h(sblog_t('已选择 0 张')) ?></span>
          <button class="button button--secondary" type="button" data-sblog-gallery-close><?= h(sblog_t('取消')) ?></button>
          <button class="button" type="button" data-sblog-gallery-add disabled><?= h(sblog_t('加入图库')) ?></button>
        </footer>
      </div>
    </dialog>
    <?php return (string)ob_get_clean();
}

function gallery_render_categories_panel(array $categories): string
{
    ob_start(); ?>
    <div class="sblog-gallery-admin__category-layout">
      <section class="panel">
        <div class="panel__header"><h2><?= h(sblog_t('新建分类')) ?></h2></div>
        <div class="panel__body"><form class="form-stack" method="post" action="<?= h(gallery_action_url('save_gallery_category')) ?>"><?= csrf_field() ?>
          <div class="field"><label for="gallery-new-category-name"><?= h(sblog_t('分类名称')) ?></label><input id="gallery-new-category-name" name="name" maxlength="100" required></div>
          <div class="field"><label for="gallery-new-category-slug"><?= h(sblog_t('Slug')) ?></label><input id="gallery-new-category-slug" name="slug" maxlength="100" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"><p class="field-hint"><?= h(sblog_t('留空时根据分类名称自动生成。')) ?></p></div>
          <div class="field"><label for="gallery-new-category-description"><?= h(sblog_t('分类描述')) ?></label><textarea id="gallery-new-category-description" name="description" rows="4" maxlength="1000"></textarea></div>
          <div class="field"><label for="gallery-new-category-order"><?= h(sblog_t('排序')) ?></label><input id="gallery-new-category-order" name="sort_order" type="number" min="-999999" max="999999" value="0"></div>
          <div class="action-row"><button class="button" type="submit"><?= h(sblog_t('新建分类')) ?></button></div>
        </form></div>
      </section>
      <section class="sblog-gallery-admin__category-list" aria-label="<?= h(sblog_t('分类')) ?>">
        <?php if ($categories): foreach ($categories as $category): ?>
          <article class="panel sblog-gallery-admin__category-item">
            <div class="panel__header"><h2><?= h((string)$category['name']) ?></h2><p class="panel__meta"><?= h((string)($category['item_count'] ?? 0)) ?> <?= h(sblog_t('张图片')) ?></p></div>
            <div class="panel__body">
              <form class="form-stack" method="post" action="<?= h(gallery_action_url('save_gallery_category')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$category['id'] ?>">
                <div class="field-grid"><div class="field"><label for="gallery-category-name-<?= (int)$category['id'] ?>"><?= h(sblog_t('分类名称')) ?></label><input id="gallery-category-name-<?= (int)$category['id'] ?>" name="name" maxlength="100" value="<?= h((string)$category['name']) ?>" required></div><div class="field"><label for="gallery-category-slug-<?= (int)$category['id'] ?>"><?= h(sblog_t('Slug')) ?></label><input id="gallery-category-slug-<?= (int)$category['id'] ?>" name="slug" maxlength="100" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="<?= h((string)$category['slug']) ?>" required></div></div>
                <div class="field"><label for="gallery-category-description-<?= (int)$category['id'] ?>"><?= h(sblog_t('分类描述')) ?></label><textarea id="gallery-category-description-<?= (int)$category['id'] ?>" name="description" rows="3" maxlength="1000"><?= h((string)$category['description']) ?></textarea></div>
                <div class="field"><label for="gallery-category-order-<?= (int)$category['id'] ?>"><?= h(sblog_t('排序')) ?></label><input id="gallery-category-order-<?= (int)$category['id'] ?>" name="sort_order" type="number" min="-999999" max="999999" value="<?= (int)$category['sort_order'] ?>"></div>
                <div class="action-row"><button class="button button--secondary" type="submit"><?= h(sblog_t('保存修改')) ?></button></div>
              </form>
              <form method="post" action="<?= h(gallery_action_url('delete_gallery_category')) ?>" onsubmit="return confirm(<?= h(json_encode(sblog_t('删除此分类？分类中的图片会保留并改为未分类。'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>);"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$category['id'] ?>"><button class="button button--danger" type="submit"><?= h(sblog_t('删除分类')) ?></button></form>
            </div>
          </article>
        <?php endforeach; else: ?><div class="empty-state"><p><?= h(sblog_t('还没有分类。')) ?></p></div><?php endif; ?>
      </section>
    </div>
    <?php return (string)ob_get_clean();
}

function gallery_render_settings_panel(array $settings): string
{
    $route = (string)($settings['route_slug'] ?? 'gallery');
    ob_start(); ?>
    <section class="panel sblog-gallery-admin__settings">
      <div class="panel__header"><h2><?= h(sblog_t('图库页面')) ?></h2><p class="panel__meta"><a href="<?= h(gallery_public_url()) ?>" target="_blank" rel="noopener noreferrer"><?= h(gallery_public_url()) ?></a></p></div>
      <div class="panel__body"><form class="form-stack" method="post" action="<?= h(gallery_action_url('save_gallery_settings')) ?>"><?= csrf_field() ?>
        <div class="field"><label for="gallery-page-title"><?= h(sblog_t('页面标题')) ?></label><input id="gallery-page-title" name="title" maxlength="120" value="<?= h((string)($settings['title'] ?? sblog_t('图库'))) ?>" required></div>
        <div class="field"><label for="gallery-page-description"><?= h(sblog_t('页面描述')) ?></label><textarea id="gallery-page-description" name="description" rows="4" maxlength="1000"><?= h((string)($settings['description'] ?? '')) ?></textarea></div>
        <div class="field-grid">
          <div class="field"><label for="gallery-route-slug"><?= h(sblog_t('路由 Slug')) ?></label><input id="gallery-route-slug" name="route_slug" maxlength="80" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="<?= h($route) ?>" required><p class="field-hint"><?= h(sblog_t('仅支持单段小写字母、数字和连字符。')) ?></p></div>
          <div class="field"><label for="gallery-per-page"><?= h(sblog_t('每页图片数')) ?></label><input id="gallery-per-page" name="per_page" type="number" min="6" max="60" value="<?= h((string)($settings['per_page'] ?? '12')) ?>" required></div>
        </div>
        <div class="action-row"><button class="button" type="submit"><?= h(sblog_t('保存设置')) ?></button></div>
      </form></div>
    </section>
    <?php return (string)ob_get_clean();
}
