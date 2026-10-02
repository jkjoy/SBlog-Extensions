<?php
declare(strict_types=1);

function sns_no_store(): void
{
    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Vary: Cookie', false);
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-SBlog-Page-Cache: BYPASS');
    }
}

function sns_input(array $input, string $key, int $limit = 2048, string $default = ''): string
{
    if (!array_key_exists($key, $input)) {
        return $default;
    }
    if (!is_string($input[$key]) || strlen($input[$key]) > $limit || preg_match('/[\x00-\x1F\x7F]/', $input[$key])) {
        throw new SnsLoginException(sblog_t('授权请求参数无效。'));
    }
    return $input[$key];
}

function sns_check_rate(): void
{
    if (function_exists('login_rate_state') && (int)(login_rate_state()['count'] ?? 0) >= 5) {
        throw new SnsLoginException(sblog_t('登录尝试过多，请 15 分钟后再试。'));
    }
}

function sns_start(): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new SnsLoginException(sblog_t('请从登录或绑定页面重新发起授权。'));
    }
    verify_csrf();
    $provider = sns_input($_POST, 'provider', 64);
    $purpose = sns_input($_POST, 'purpose', 16, 'login');
    $descriptor = sns_providers()[$provider] ?? null;
    if ($descriptor === null) {
        throw new SnsLoginException(sblog_t('不支持的第三方登录平台。'));
    }
    $adminId = 0;
    if ($purpose === 'bind') {
        require_admin();
        sns_verify_password(sns_input($_POST, 'password', 4096));
        $adminId = (int)current_admin()['id'];
    } elseif ($purpose === 'login') {
        if (current_admin() !== null) {
            throw new SnsLoginException(sblog_t('你已登录后台，请先退出当前账号。'));
        }
        sns_check_rate();
    } else {
        throw new SnsLoginException(sblog_t('授权用途无效。'));
    }
    $config = sns_provider_config($provider);
    $pending = sns_pending_create($provider, $purpose, $config, $adminId);
    return sns_authorization_url($descriptor, $config, $pending['redirect_uri'], $pending['state'], $pending['verifier']);
}

function sns_complete(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new SnsLoginException(sblog_t('授权回调必须使用 GET 请求。'));
    }
    $provider = sns_input($_GET, 'provider', 64);
    $pending = sns_pending_take($provider, sns_input($_GET, 'state', 64));
    // Validate and consume state even when the provider reports a denied grant.
    if (isset($_GET['error'])) {
        throw new SnsLoginException(sblog_t('你已取消授权，或平台拒绝了此次登录。'));
    }
    $code = sns_input($_GET, 'code', 4096);
    if ($code === '') {
        throw new SnsLoginException(sblog_t('第三方平台没有返回授权码。'));
    }
    $purpose = (string)$pending['purpose'];
    if ($purpose === 'bind') {
        $admin = current_admin();
        $row = $admin === null ? null : one('SELECT password_hash FROM users WHERE id = ?', [(int)$admin['id']]);
        if ($admin === null || (int)$admin['id'] !== (int)$pending['admin_id'] || $row === null
            || !hash_equals((string)($pending['admin_password_fingerprint'] ?? ''), hash('sha256', (string)$row['password_hash']))) {
            throw new SnsLoginException(sblog_t('绑定期间本地登录状态已改变，请重新登录后绑定。'));
        }
    } else {
        if (current_admin() !== null) {
            throw new SnsLoginException(sblog_t('你已登录后台，请先退出当前账号。'));
        }
        sns_check_rate();
    }
    $config = sns_provider_config($provider);
    $descriptor = sns_providers()[$provider] ?? null;
    if ($descriptor === null || !$config['enabled']) {
        throw new SnsLoginException(sblog_t('该平台已停用，请使用本地密码登录。'));
    }
    $identity = sns_fetch_identity($descriptor, $config, $pending['redirect_uri'], $code, $pending['verifier']);
    $subject = sns_subject($identity);
    if ($purpose === 'bind') {
        sns_bind_identity($provider, $config, $identity, (int)$pending['admin_id']);
        return ['url' => sns_url('admin_sns_login'), 'message' => sblog_t('第三方账号已绑定。')];
    }
    $binding = sns_find_binding($provider, $config, $subject);
    if ($binding === null) {
        if (function_exists('login_rate_state')) {
            login_rate_state(true);
        }
        throw new SnsLoginException(sblog_t('此第三方账号尚未绑定。请先使用本地密码登录，再到插件设置中绑定。'));
    }
    q('UPDATE sblog_sns_bindings SET last_used_at = ? WHERE id = ?', [time(), (int)$binding['id']]);
    if (session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)) {
        throw new SnsLoginException(sblog_t('无法建立安全登录会话，请重新登录。'));
    }
    unset($_SESSION['csrf_token'], $_SESSION['sns_login_pending']);
    $now = time();
    $_SESSION['admin_id'] = (int)$binding['user_id'];
    $_SESSION['admin_authenticated_at'] = $now;
    $_SESSION['admin_last_seen_at'] = $now;
    $_SESSION['admin_password_fingerprint'] = hash('sha256', (string)$binding['password_hash']);
    $_SESSION['sns_login_binding_id'] = (int)$binding['id'];
    $_SESSION['sns_login_config_hash'] = sns_config_fingerprint($provider, $config);
    if (function_exists('update_admin_presence')) {
        update_admin_presence((int)$binding['user_id']);
    }
    if (function_exists('login_rate_state')) {
        login_rate_state(false, true);
    }
    return ['url' => url_for('admin'), 'message' => sblog_t('已登录后台。')];
}

function sns_handle_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    if ($action === 'login') {
        sns_no_store();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && current_admin() === null) {
            // The core password login establishes its own session on this request.
            unset($_SESSION['sns_login_binding_id'], $_SESSION['sns_login_config_hash']);
        }
        return;
    }
    if (!in_array($action, ['admin_sns_login', 'save_sns_login_settings', 'unlink_sns_login', 'sns_login_start', 'sns_login_callback'], true)) {
        return;
    }
    sns_no_store();
    if (sns_handle_admin_request($action)) {
        return;
    }
    $destination = url_for('login');
    try {
        if ($action === 'sns_login_start') {
            $destination = sns_start();
        } else {
            $result = sns_complete();
            $destination = $result['url'];
            set_flash('success', $result['message']);
        }
    } catch (SnsLoginException $exception) {
        set_flash('error', $exception->getMessage());
        $destination = current_admin() !== null ? sns_url('admin_sns_login') : url_for('login');
    } catch (Throwable $exception) {
        // Do not put tokens, authorization codes, secrets or upstream response bodies into logs or HTML.
        error_log('SNS login failed (' . get_class($exception) . ').');
        set_flash('error', sblog_t('第三方授权暂时无法完成，请检查平台设置，或使用本地密码登录。'));
        $destination = current_admin() !== null ? sns_url('admin_sns_login') : url_for('login');
    }
    // No caller-supplied return URL: only a provider authorization URL or a fixed local route.
    redirect_to($destination, 303);
}

function sns_login_buttons(): string
{
    $buttons = '';
    $config = sns_config();
    foreach (sns_providers() as $slug => $descriptor) {
        $item = $config[$slug] ?? [];
        if (empty($item['enabled']) || empty($item['client_id']) || empty($item['client_secret'])) {
            continue;
        }
        $buttons .= '<form method="post" action="' . h(sns_url('sns_login_start')) . '">'
            . csrf_field() . '<input type="hidden" name="purpose" value="login">'
            . '<input type="hidden" name="provider" value="' . h($slug) . '">'
            . '<button class="button button--secondary sns-login__button" type="submit">'
            . h(sblog_t('使用 {provider} 登录', ['provider' => $descriptor['name']])) . '</button></form>';
    }
    if ($buttons === '') {
        return '';
    }
    return '<div class="sns-login" data-sns-login><p class="sns-login__label">' . h(sblog_t('第三方登录')) . '</p>'
        . '<div class="sns-login__buttons">' . $buttons . '</div><p class="field-hint">'
        . h(sblog_t('仅限已绑定的后台账号。')) . '</p></div>';
}

function sns_output_html(string $html, array $context): string
{
    $action = (string)($context['action'] ?? '');
    // The core passes all headers here; PHP may add its default Content-Type only after this filter.
    $headerContext = (string)($context['content_type'] ?? '');
    $explicitType = '';
    if (preg_match('/(?:^|;\s*)Content-Type:\s*([^;]+)/i', $headerContext, $match)) {
        $explicitType = trim(strtolower($match[1]));
    } elseif (preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+#i', $headerContext, $match)) {
        $explicitType = strtolower($match[0]);
    }
    if (!in_array($action, ['login', 'admin_sns_login'], true) || ($explicitType !== '' && $explicitType !== 'text/html')) {
        return $html;
    }
    if ($action === 'login' && !str_contains($html, 'data-sns-login')) {
        $buttons = sns_login_buttons();
        // Inject after the original password form, avoiding nested forms and translated text anchors.
        $anchor = strpos($html, 'id="username"');
        $formEnd = $anchor === false ? false : strpos($html, '</form>', $anchor);
        if ($buttons !== '' && $formEnd !== false) {
            $html = substr_replace($html, $buttons, $formEnd + strlen('</form>'), 0);
        }
    }
    if (!str_contains($html, 'data-sns-login-style')) {
        $style = '<link rel="stylesheet" data-sns-login-style href="' . h(plugin_asset_url('sns-login', 'assets/sns-login.css')) . '">';
        $html = str_replace('</head>', $style . '</head>', $html);
    }
    return $html;
}
