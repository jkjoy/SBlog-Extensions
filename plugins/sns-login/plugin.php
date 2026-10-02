<?php
declare(strict_types=1);

const SBLOG_SNS_LOGIN_VERSION = '1.0.0';
const SBLOG_SNS_STATE_TTL = 600;
const SBLOG_SNS_MAX_PENDING = 8;

require_once __DIR__ . '/includes/providers.php';
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/flow.php';

add_plugin_action('plugins_loaded', 'sns_bootstrap', -1000);
add_plugin_action('request', 'sns_handle_request', -1000);
add_plugin_filter('output_html', 'sns_output_html', 40);
