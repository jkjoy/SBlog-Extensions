<?php
declare(strict_types=1);

const SBLOG_GALLERY_VERSION = '1.0.1';
const SBLOG_GALLERY_SLUG = 'gallery';

require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/public.php';

function gallery_register_translations(): void
{
    sblog_i18n_register('en', [
        '图库' => 'Gallery',
        '图库管理' => 'Gallery',
        '图片、分类与公开页面' => 'Images, categories, and public page',
        '图片' => 'Images',
        '分类' => 'Categories',
        '设置' => 'Settings',
        '添加图片' => 'Add images',
        '从媒体库选择' => 'Choose from media library',
        '上传新图片' => 'Upload new images',
        '搜索图片' => 'Search images',
        '加入图库' => 'Add to gallery',
        '从图库移除' => 'Remove from gallery',
        '还没有图库图片。' => 'There are no gallery images yet.',
        '从媒体库选择已有图片，或上传新图片。' => 'Choose existing images from the media library or upload new ones.',
        '图库设置已保存。' => 'Gallery settings saved.',
        '分类已保存。' => 'Category saved.',
        '分类已删除，原分类中的图片已改为未分类。' => 'Category deleted. Its images are now uncategorized.',
        '图片信息已保存。' => 'Image details saved.',
        '图片已从图库移除，媒体库中的原文件仍然保留。' => 'Image removed from the gallery. The original media file was kept.',
        '所选图片已加入图库。' => 'Selected images added to the gallery.',
        '已加入 {added} 张图片，{invalid} 张图片无效或已被删除。' => 'Added {added} images; {invalid} images are invalid or were deleted.',
        '所选图片已经在图库中。' => 'The selected images are already in the gallery.',
        '没有可加入图库的图片。' => 'There are no images to add to the gallery.',
        '图库页面' => 'Gallery page',
        '页面标题' => 'Page title',
        '页面描述' => 'Page description',
        '路由 Slug' => 'Route slug',
        '每页图片数' => 'Images per page',
        '保存设置' => 'Save settings',
        '新建分类' => 'New category',
        '分类封面' => 'Category cover',
        '选择封面' => 'Choose cover',
        '清除封面' => 'Clear cover',
        '选择封面后，保存分类即可生效。' => 'Save the category to apply the selected cover.',
        '选择分类封面' => 'Choose category cover',
        '从媒体库选择一张图片作为封面。' => 'Choose an image from the media library as the cover.',
        '设为封面' => 'Use as cover',
        '分类名称' => 'Category name',
        '分类描述' => 'Category description',
        '排序' => 'Order',
        '发布' => 'Published',
        '草稿' => 'Draft',
        '未分类' => 'Uncategorized',
        '全部分类' => 'All categories',
        '全部状态' => 'All statuses',
        '保存图片' => 'Save image',
        '上一页' => 'Previous page',
        '下一页' => 'Next page',
        '关闭' => 'Close',
        '上一张' => 'Previous image',
        '下一张' => 'Next image',
        '打开原图' => 'Open full image',
        '图片暂时无法加载' => 'Image could not be loaded',
        '查看全部图片' => 'View all images',
        '此分类中还没有公开图片。' => 'There are no published images in this category.',
        '分类保存失败，请稍后重试。' => 'Could not save the category. Try again shortly.',
        '分类删除失败，请稍后重试。' => 'Could not delete the category. Try again shortly.',
        '加入分类' => 'Add to category',
        '仅支持单段小写字母、数字和连字符。' => 'Use one path segment containing lowercase letters, numbers, and hyphens only.',
        '留空时根据分类名称自动生成。' => 'Leave blank to generate it from the category name.',
        '媒体库加载失败，请稍后重试。' => 'Could not load the media library. Try again shortly.',
        '每页图片数必须在 6 到 60 之间。' => 'Images per page must be between 6 and 60.',
        '删除此分类？分类中的图片会保留并改为未分类。' => 'Delete this category? Its images will be kept and made uncategorized.',
        '删除分类' => 'Delete category',
        '图库分类' => 'Gallery categories',
        '图库管理页面加载失败，请检查服务器日志。' => 'Could not load gallery management. Check the server log.',
        '图库管理暂时无法访问' => 'Gallery management is unavailable',
        '图库加载失败，请稍后重试。' => 'Could not load the gallery. Try again shortly.',
        '图库设置保存失败，请稍后重试。' => 'Could not save gallery settings. Try again shortly.',
        '图库图片' => 'Gallery images',
        '图库暂时无法访问' => 'Gallery unavailable',
        '图库中还没有公开图片。' => 'There are no published gallery images yet.',
        '图片加入图库失败，请稍后重试。' => 'Could not add the images to the gallery. Try again shortly.',
        '图片信息保存失败，请稍后重试。' => 'Could not save the image details. Try again shortly.',
        '图片信息无效或内容过长。' => 'The image details are invalid or too long.',
        '图片移除失败，请稍后重试。' => 'Could not remove the image. Try again shortly.',
        '选择或拖入图片' => 'Choose or drop images',
        '页面标题不能为空，且不能超过 120 个字符。' => 'The page title is required and cannot exceed 120 characters.',
        '页面描述不能超过 1000 个字符。' => 'The page description cannot exceed 1,000 characters.',
        '已选择 0 张' => '0 images selected',
        '张图片' => 'images',
        '找不到分类。' => 'Category not found.',
        '找不到图库分类' => 'Gallery category not found',
        '找不到图库图片。' => 'Gallery image not found.',
        '支持 JPEG、PNG、GIF 和 WebP，每个最大 30M。' => 'JPEG, PNG, GIF, and WebP up to 30 MB each.',
        '只从图库移除此图片？媒体库中的原文件会保留。' => 'Remove this image from the gallery? The original media file will be kept.',
        '一次最多只能添加 100 张图片。' => 'You can add up to 100 images at a time.',
    ]);

    sblog_i18n_register_client('en', [
        'gallery_selected_count' => '{count} images selected',
        'gallery_none_selected' => 'No images selected',
        'gallery_untitled_image' => 'Untitled image',
        'gallery_deselect_image' => 'Deselect: {title}',
        'gallery_select_image' => 'Select: {title}',
        'gallery_image_unavailable' => 'Image could not be loaded',
        'gallery_no_matching_media' => 'No matching images found.',
        'gallery_media_empty' => 'There are no usable images in the media library.',
        'gallery_clear_search' => 'Clear search',
        'gallery_image_already_added' => '{title}, already in gallery',
        'gallery_added' => 'Added',
        'gallery_retry' => 'Retry',
        'gallery_previous_page' => 'Previous page',
        'gallery_go_to_page' => 'Go to page {page}',
        'gallery_next_page' => 'Next page',
        'gallery_media_endpoint_missing' => 'The media library endpoint is unavailable. Refresh the page and try again.',
        'gallery_loading_media' => 'Loading media library...',
        'gallery_load_media_failed' => 'Could not load the media library. Try again.',
        'gallery_media_total' => '{count} images in the media library',
        'gallery_no_available_media' => 'There are no images available to add.',
        'gallery_selection_limit' => 'You can select up to {count} images at a time.',
        'gallery_add_endpoint_missing' => 'The gallery endpoint is unavailable. Refresh the page and try again.',
        'gallery_add_failed' => 'Could not add the images to the gallery. Try again.',
        'gallery_add_success' => 'Added {count} images to the gallery.',
        'gallery_add_partial_failed' => 'Confirmed {count} images in the gallery, but {invalid} could not be added. Refresh the media library and try again.',
        'gallery_add_invalid_media' => '{count} images are no longer available and could not be added. Refresh the media library and try again.',
        'gallery_images_already_added' => 'The selected images are already in the gallery.',
        'gallery_waiting_to_upload' => 'Waiting to upload',
        'gallery_image_type_required' => 'Choose JPEG, PNG, GIF, or WebP images.',
        'gallery_file_too_large' => 'Image exceeds 30 MB',
        'gallery_upload_endpoint_missing' => 'The upload endpoint is unavailable. Refresh the page and try again.',
        'gallery_uploading' => 'Uploading...',
        'gallery_upload_failed' => 'Could not upload the image. Try again.',
        'gallery_upload_invalid' => 'The upload response did not contain a usable image.',
        'gallery_uploaded_and_added' => 'Uploaded and added to gallery',
        'gallery_uploaded_add_failed' => 'The image was uploaded to the media library but could not be added to the gallery.',
        'gallery_retry_add' => 'Retry adding to gallery',
        'gallery_retrying_add' => 'Adding to gallery...',
        'gallery_retry_add_success' => 'Image added to the gallery.',
        'gallery_upload_success' => 'Uploaded and added {count} images.',
        'gallery_upload_in_progress' => 'Images are uploading. Wait for the queue to finish.',
        'gallery_add_in_progress' => 'Images are being added to the gallery. Wait for the operation to finish.',
        'gallery_adding_images' => 'Adding images to gallery...',
        'gallery_image_number' => 'Image {count}',
    ]);
}

function gallery_route_action(mixed $action, array $context = []): mixed
{
    if (!is_string($action)) {
        return $action;
    }
    if ($action === 'gallery') {
        $_GET['a'] = 'gallery';
        $_REQUEST['a'] = 'gallery';
        return 'gallery';
    }
    if ($action !== 'page') {
        return $action;
    }

    $slugValue = $_GET['slug'] ?? '';
    $slug = trim(is_scalar($slugValue) ? (string)$slugValue : '');
    $routeSlug = setting(SBLOG_GALLERY_ROUTE_MARKER, 'gallery');
    [, $routeErrors] = gallery_validate_route_slug($routeSlug);
    if ($routeErrors !== []) {
        $routeSlug = 'gallery';
    }
    if ($slug === '' || !hash_equals($routeSlug, $slug) || gallery_route_conflicts($routeSlug) !== []) {
        return $action;
    }

    $_GET['a'] = 'gallery';
    $_REQUEST['a'] = 'gallery';
    return 'gallery';
}

function gallery_sidebar_link(mixed $links, array $context = []): mixed
{
    if (!is_array($links)) {
        return $links;
    }
    $active = (string)($context['active'] ?? '');
    $entry = [
        'label' => sblog_t('图库管理'),
        'icon' => 'media',
        'note' => sblog_t('图片、分类与公开页面'),
        'href' => gallery_admin_url(),
        'active' => $active === 'gallery',
    ];

    $position = count($links);
    foreach ($links as $index => $link) {
        if ((string)($link['label'] ?? '') === '媒体库') {
            $position = $index + 1;
            break;
        }
    }
    array_splice($links, $position, 0, [$entry]);
    return $links;
}

function gallery_inject_assets(mixed $html, array $context = []): mixed
{
    if (!is_string($html) || $html === '') {
        return $html;
    }
    $action = (string)($context['action'] ?? $GLOBALS['sblog_current_action'] ?? '');
    $isAdmin = $action === 'admin_gallery';
    $isPublic = $action === 'gallery';
    if (!$isAdmin && !$isPublic) {
        return $html;
    }

    $asset = $isAdmin ? 'admin' : 'public';
    $styleUrl = plugin_asset_url(SBLOG_GALLERY_SLUG, 'assets/' . $asset . '.css');
    $scriptUrl = plugin_asset_url(SBLOG_GALLERY_SLUG, 'assets/' . $asset . '.js');
    $version = rawurlencode(SBLOG_GALLERY_VERSION);
    $style = '<link rel="stylesheet" href="' . h($styleUrl) . '?v=' . $version . '" data-sblog-gallery-asset="style">';
    $script = '<script src="' . h($scriptUrl) . '?v=' . $version . '" defer data-sblog-gallery-asset="script"></script>';

    if (!str_contains($html, 'data-sblog-gallery-asset="style"')) {
        $headEnd = strripos($html, '</head>');
        if ($headEnd !== false) {
            $html = substr_replace($html, $style . "\n", $headEnd, 0);
        }
    }
    if (!str_contains($html, 'data-sblog-gallery-asset="script"')) {
        $bodyEnd = strripos($html, '</body>');
        if ($bodyEnd !== false) {
            $html = substr_replace($html, $script . "\n", $bodyEnd, 0);
        }
    }
    return $html;
}

gallery_register_translations();
add_plugin_action('plugins_loaded', 'gallery_install', 20);
add_plugin_action('request', 'gallery_handle_request', 2000);
add_plugin_filter('route_action', 'gallery_route_action', 20);
add_plugin_filter('admin_sidebar_links', 'gallery_sidebar_link', 20);
add_plugin_filter('output_html', 'gallery_inject_assets', 20);
