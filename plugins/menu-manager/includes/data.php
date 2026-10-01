<?php

declare(strict_types=1);

function sblog_menu_routes(): array
{
    return [
        'home' => sblog_t('首页'),
        'archives' => sblog_t('归档'),
        'tags' => sblog_t('标签'),
        'categories' => sblog_t('分类'),
        'links' => sblog_t('友链'),
        'rss' => 'RSS',
        'sitemap' => 'Sitemap',
    ];
}

function sblog_menu_defaults(): array
{
    $items = [];
    foreach (['home', 'archives', 'tags', 'links'] as $route) {
        $items[] = sblog_menu_new_item('route', $route);
    }
    return ['version' => 1, 'managed' => false, 'items' => $items];
}

function sblog_menu_new_item(string $type = 'route', string $reference = 'home'): array
{
    return [
        'id' => bin2hex(random_bytes(8)), 'type' => $type, 'reference' => $reference,
        'label' => '', 'url' => '', 'enabled' => true, 'new_tab' => false,
    ];
}

function sblog_menu_safe_url(string $url): bool
{
    if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)
        || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url)) {
        return false;
    }
    if (str_starts_with($url, '#')) {
        return true;
    }
    if (str_starts_with($url, '/')) {
        // Encoded slashes must not turn a local path into a protocol-relative URL.
        return !str_starts_with(rawurldecode($url), '//');
    }
    $parts = parse_url($url);
    return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts)
        && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
}

function sblog_menu_validate(array $config): array
{
    if (($config['version'] ?? null) !== 1 || !is_bool($config['managed'] ?? null)
        || !is_array($config['items'] ?? null) || count($config['items']) > SBLOG_MENU_MAX_ITEMS) {
        throw new InvalidArgumentException(sblog_t('菜单配置无效，一份菜单最多包含 100 项。'));
    }
    $items = [];
    $ids = [];
    foreach ($config['items'] as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException(sblog_t('菜单项格式无效。'));
        }
        foreach (['id', 'type', 'reference', 'label', 'url'] as $key) {
            if (!is_string($item[$key] ?? null)) {
                throw new InvalidArgumentException(sblog_t('菜单项格式无效。'));
            }
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $item['id']) || isset($ids[$item['id']])
            || !is_bool($item['enabled'] ?? null) || !is_bool($item['new_tab'] ?? null)) {
            throw new InvalidArgumentException(sblog_t('菜单项标识或开关无效。'));
        }
        $ids[$item['id']] = true;
        $label = trim($item['label']);
        if (str_len_u($label) > 100 || preg_match('/[\x00-\x1F\x7F]/', $label)) {
            throw new InvalidArgumentException(sblog_t('菜单名称最多 100 个字符，且不能包含控制字符。'));
        }
        $type = $item['type'];
        $reference = $item['reference'];
        $url = trim($item['url']);
        if ($type === 'route') {
            if (!isset(sblog_menu_routes()[$reference])) {
                throw new InvalidArgumentException(sblog_t('请选择有效的内置页面。'));
            }
        } elseif (in_array($type, ['page', 'category'], true)) {
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $reference)) {
                throw new InvalidArgumentException(sblog_t('请选择有效的页面或分类。'));
            }
        } elseif ($type === 'custom') {
            if ($label === '' || !sblog_menu_safe_url($url)) {
                throw new InvalidArgumentException(sblog_t('自定义链接需要名称和有效的 HTTP(S) 地址、站内绝对路径或锚点。'));
            }
            $reference = '';
        } else {
            throw new InvalidArgumentException(sblog_t('菜单项类型无效。'));
        }
        $items[] = [
            'id' => $item['id'], 'type' => $type, 'reference' => $reference,
            'label' => $label, 'url' => $type === 'custom' ? $url : '',
            'enabled' => $item['enabled'], 'new_tab' => $item['new_tab'],
        ];
    }
    return ['version' => 1, 'managed' => $config['managed'], 'items' => $items];
}

function sblog_menu_config(): array
{
    try {
        $raw = setting('menu_manager_config');
        if ($raw !== '') {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                return sblog_menu_validate($decoded);
            }
        }
    } catch (Throwable $exception) {
        error_log('Menu manager configuration failed: ' . $exception->getMessage());
    }
    return sblog_menu_defaults();
}

function sblog_menu_is_managed(): bool
{
    return sblog_menu_config()['managed'];
}

function sblog_menu_save(array $config): void
{
    $config = sblog_menu_validate($config);
    save_settings(['menu_manager_config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    plugin_action('menu_changed', ['managed' => $config['managed']]);
}

function sblog_menu_pages(): array
{
    return all_rows(
        'SELECT id, slug, title, kind FROM posts WHERE kind = ? AND status = ? AND published_at <= ? ORDER BY title COLLATE NOCASE, id',
        ['page', 'published', time()]
    );
}

function sblog_menu_categories(): array
{
    return all_rows('SELECT id, slug, name FROM categories ORDER BY sort_order ASC, id ASC');
}

function sblog_menu_items(array $context = []): array
{
    $config = sblog_menu_config();
    if (!$config['managed']) {
        return [];
    }
    $pages = $categories = [];
    $types = array_column($config['items'], 'type');
    if (in_array('page', $types, true)) {
        $pages = array_column(sblog_menu_pages(), null, 'id');
    }
    if (in_array('category', $types, true)) {
        $categories = array_column(sblog_menu_categories(), null, 'id');
    }
    $active = (string)($context['active'] ?? '');
    $action = is_scalar($_GET['a'] ?? null) ? (string)$_GET['a'] : '';
    $slug = is_scalar($_GET['slug'] ?? null) ? (string)$_GET['slug'] : '';
    $isSearch = !empty($context['is_search'])
        || (isset($_GET['s']) && is_scalar($_GET['s']) && trim((string)$_GET['s']) !== '');
    $items = [];
    foreach ($config['items'] as $item) {
        if (!$item['enabled']) {
            continue;
        }
        $label = $item['label'];
        $route = '';
        $current = false;
        if ($item['type'] === 'route') {
            $route = $item['reference'];
            $url = url_for($route);
            $label = $label !== '' ? $label : sblog_menu_routes()[$route];
            $current = $route === $active && !$isSearch;
            if ($route === 'home' && in_array($action, ['category', 'tag', 'post', 'page'], true)) {
                $current = false;
            }
        } elseif ($item['type'] === 'page') {
            $page = $pages[(int)$item['reference']] ?? null;
            if (!$page) {
                continue;
            }
            $url = content_permalink($page);
            $label = $label !== '' ? $label : (string)$page['title'];
            $current = $active === 'page:' . $page['slug'];
        } elseif ($item['type'] === 'category') {
            $category = $categories[(int)$item['reference']] ?? null;
            if (!$category) {
                continue;
            }
            $url = url_for('category', ['slug' => (string)$category['slug']]);
            $label = $label !== '' ? $label : (string)$category['name'];
            $current = $action === 'category' && $slug === (string)$category['slug'];
        } else {
            $url = $item['url'];
        }
        $items[] = array_merge($item, [
            'label' => $label, 'url' => $url, 'active' => $current, 'route' => $route,
            'target' => $item['new_tab'] ? '_blank' : '_self',
        ]);
    }
    return $items;
}
