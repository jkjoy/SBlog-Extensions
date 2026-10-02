<?php
declare(strict_types=1);

const SBLOG_PAID_READING_VERSION = '1.1.1';

require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/recovery.php';
require_once __DIR__ . '/includes/reader.php';
require_once __DIR__ . '/includes/payment.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/public.php';
require_once __DIR__ . '/includes/admin.php';

add_plugin_action('plugins_loaded', 'pr_install', 10);
add_plugin_action('request', 'pr_protect_request', -1500);
add_plugin_action('request', 'pr_reader_request', -1450);
add_plugin_action('request', 'pr_public_request', -1400);
add_plugin_action('request', 'pr_admin_request', -1300);
add_plugin_action('request', 'pr_release_cache_guard', 1100);
add_plugin_action('post_saved', 'pr_post_saved', 10);
add_plugin_filter('post_data_before_save', 'pr_before_save', PHP_INT_MAX);
add_plugin_filter('editor_field_actions_html', 'pr_editor_control', 30);
add_plugin_filter('output_html', 'pr_filter_output', 30);
add_plugin_filter('output_html', 'pr_assets', 60);
