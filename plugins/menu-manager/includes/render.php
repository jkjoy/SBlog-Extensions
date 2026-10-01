<?php

declare(strict_types=1);

function sblog_menu_render(array $context = [], array $options = []): string
{
    $html = '';
    $itemTag = in_array($options['item_tag'] ?? '', ['li', 'div'], true) ? $options['item_tag'] : '';
    $labelTag = ($options['label_tag'] ?? '') === 'span' ? 'span' : '';
    foreach (sblog_menu_items($context) as $item) {
        $url = isset($options['url']) && is_callable($options['url']) ? ($options['url'])($item['url'], $item) : $item['url'];
        $linkClass = $options['link_class'] ?? '';
        $linkClass = !is_string($linkClass) && is_callable($linkClass) ? $linkClass($item) : $linkClass;
        $linkClass = trim((string)$linkClass . ($item['active'] ? ' ' . ($options['active_class'] ?? '') : ''));
        if ($itemTag !== '') {
            $itemClass = trim((string)($options['item_class'] ?? '') . ($item['active'] ? ' ' . ($options['active_item_class'] ?? '') : ''));
            $html .= '<' . $itemTag . ($itemClass !== '' ? ' class="' . h($itemClass) . '"' : '') . '>';
        }
        $html .= '<a href="' . h((string)$url) . '"' . ($linkClass !== '' ? ' class="' . h($linkClass) . '"' : '')
            . ($item['active'] ? ' aria-current="page"' : '')
            . ($item['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : '') . '>';
        if (isset($options['icon']) && is_callable($options['icon'])) {
            $html .= (string)($options['icon'])($item);
        }
        $label = h((string)($options['label_prefix'] ?? '') . $item['label'] . (string)($options['label_suffix'] ?? ''));
        $html .= ($labelTag !== '' ? '<span>' . $label . '</span>' : $label) . '</a>';
        if ($itemTag !== '') {
            $html .= '</' . $itemTag . '>';
        }
        $html .= "\n";
    }
    return $html;
}

function sblog_menu_output_html(string $html, array $context): string
{
    $action = (string)($context['action'] ?? '');
    if ($action === 'admin_menus' && str_contains($html, 'data-menu-manager')) {
        $css = plugin_asset_url('menu-manager', 'assets/admin.css');
        $js = plugin_asset_url('menu-manager', 'assets/admin.js');
        $html = str_replace('</head>', '<link rel="stylesheet" href="' . h($css) . '?v=' . SBLOG_MENU_MANAGER_VERSION . '">' . "\n</head>", $html);
        return str_replace('</body>', '<script src="' . h($js) . '?v=' . SBLOG_MENU_MANAGER_VERSION . '" defer></script>' . "\n</body>", $html);
    }
    // Default and Starter use the core layout, which has no navigation hook yet.
    // Restrict this compatibility path to its public header, leaving body content intact.
    if (!preg_match('/<body\b[^>]*class="[^"]*\btheme-public\b/', $html)
        || !sblog_menu_is_managed() || !str_contains($html, 'class="text-header"')
        || (function_exists('active_theme_file') && active_theme_file('layout.php') !== '')) {
        return $html;
    }
    $active = in_array($action, ['home', 'archives', 'tags', 'categories', 'links'], true) ? $action : '';
    if ($action === 'page') {
        $identifier = $_GET['slug'] ?? $_GET['id'] ?? '';
        $identifier = is_scalar($identifier) ? trim((string)$identifier) : '';
        $page = function_exists('fetch_page_by_identifier') ? fetch_page_by_identifier($identifier, false) : null;
        $active = 'page:' . (string)($page['slug'] ?? $identifier);
    }
    $replacement = sblog_menu_render(['active' => $active]);
    if (current_admin()) {
        $replacement .= '<a href="' . h(url_for('admin')) . '">' . h(sblog_t('管理')) . '</a>';
    }
    return preg_replace_callback(
        '/(<header\b[^>]*class="text-header"[^>]*>[\s\S]*?<nav\b[^>]*class="text-nav"[^>]*>)[\s\S]*?(<\/nav>)/',
        static fn(array $matches): string => $matches[1] . $replacement . $matches[2],
        $html, 1
    ) ?? $html;
}
