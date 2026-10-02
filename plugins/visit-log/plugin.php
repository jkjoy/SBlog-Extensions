<?php

declare(strict_types=1);

const SBLOG_VISIT_LOG_VERSION = '1.0.0';
const SBLOG_VISIT_LOG_PAGE_SIZE = 50;

require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/tracking.php';
require_once __DIR__ . '/includes/admin.php';

add_plugin_action('plugins_loaded', 'sblog_visit_install', 30);
// Capture before the static page cache can serve HTML and exit at priority 1000.
add_plugin_action('request', 'sblog_visit_prepare_request', -1500);
add_plugin_action('request', 'sblog_visit_handle_admin_request', 2000);
add_plugin_filter('output_html', 'sblog_visit_admin_styles', 50);
