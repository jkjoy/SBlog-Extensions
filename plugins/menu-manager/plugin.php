<?php

declare(strict_types=1);

const SBLOG_MENU_MANAGER_VERSION = '1.0.0';
const SBLOG_MENU_MAX_ITEMS = 100;

require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/admin.php';

// Let the static page cache register its authenticated POST invalidation first.
add_plugin_action('request', 'sblog_menu_handle_request', 2000);
add_plugin_filter('output_html', 'sblog_menu_output_html', 30);
