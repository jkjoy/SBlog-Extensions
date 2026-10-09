<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/editor.php';
require_once __DIR__ . '/views.php';

function sblog_lottery_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function sblog_lottery_editor_request(): never
{
    require_admin();
    try {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST') {
            verify_csrf();
            $lottery = sblog_lottery_save($_POST);
        } elseif ($method === 'GET') {
            $lottery = sblog_lottery_get(sblog_lottery_input($_GET, 'id'));
            if (!$lottery) {
                sblog_lottery_json(['ok' => false, 'error' => '抽奖活动不存在，请重新插入。'], 404);
            }
        } else {
            header('Allow: GET, POST');
            sblog_lottery_json(['ok' => false, 'error' => '不支持的请求方式。'], 405);
        }
        $lottery['locked'] = sblog_lottery_locked($lottery);
        sblog_lottery_json(['ok' => true, 'lottery' => $lottery, 'shortcode' => sblog_lottery_shortcode($lottery['id'])]);
    } catch (InvalidArgumentException $exception) {
        sblog_lottery_json(['ok' => false, 'error' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        error_log('Comment lottery editor failed: ' . $exception->getMessage());
        sblog_lottery_json(['ok' => false, 'error' => '抽奖设置无法保存，请检查服务器日志和数据库权限。'], 500);
    }
}

function sblog_lottery_worker_request(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        sblog_lottery_json(['ok' => false, 'error' => '请使用 POST 请求。'], 405);
    }
    $key = trim((string)($_SERVER['HTTP_X_SBLOG_LOTTERY_KEY'] ?? ''));
    if ($key === '' || !hash_equals(sblog_lottery_settings()['cron_key'], $key)) {
        sblog_lottery_json(['ok' => false, 'error' => '定时任务密钥无效。'], 403);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    try {
        sblog_lottery_json(['ok' => true] + sblog_lottery_tick(null, 'cron'));
    } catch (Throwable $exception) {
        error_log('Comment lottery worker failed: ' . $exception->getMessage());
        sblog_lottery_json(['ok' => false, 'error' => '开奖任务执行失败，请检查服务器日志。'], 500);
    }
}

add_plugin_action('plugins_loaded', static function (): void {
    sblog_lottery_init();
}, 30);

add_plugin_action('request', static function (array $context): void {
    $action = (string)($context['action'] ?? '');
    if ($action === 'lottery_tick') {
        sblog_lottery_worker_request();
    }
    if ($action === 'lottery_editor') {
        sblog_lottery_editor_request();
    }
    if ($action === 'admin_lottery') {
        require_once __DIR__ . '/admin.php';
        sblog_lottery_admin_request();
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        sblog_lottery_tick();
    }
    sblog_lottery_bypass_cache();
}, 5);

add_plugin_action('post_saved', 'sblog_lottery_sync_post', 30);
add_plugin_filter('editor_field_actions_html', 'sblog_lottery_editor_actions', 30);
add_plugin_filter('editor_after_form_html', 'sblog_lottery_editor_modal', 30);
add_plugin_filter('post_fields_before_defaults', 'sblog_lottery_excerpt', 30);
add_plugin_filter('comment_identity_html', 'sblog_lottery_badge', 30);
add_plugin_filter('output_html', 'sblog_lottery_output', 100);
add_theme_action('head', static function (): string {
    $post = sblog_lottery_current_post();
    return $post && sblog_lottery_for_post((int)$post['id'])
        ? '<link rel="stylesheet" href="' . h(plugin_asset_url('comment-lottery', 'assets/style.css')) . '">' : '';
}, 30);
