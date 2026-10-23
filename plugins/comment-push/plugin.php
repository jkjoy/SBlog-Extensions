<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) { http_response_code(403); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/qq.php';
require_once __DIR__ . '/weixin.php';
require_once __DIR__ . '/notifications.php';

add_plugin_action('comment_created', 'sblog_push_comment_created', 30);
add_plugin_action('request', static function (array $context): void {
    if (($context['action'] ?? '') === 'admin_comment_push') {
        require_once __DIR__ . '/admin.php';
        sblog_push_admin_request();
        exit;
    }
});
