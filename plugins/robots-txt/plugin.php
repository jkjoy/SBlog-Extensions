<?php
declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

const SBLOG_ROBOTS_TXT_VERSION = '1.0.0';
const SBLOG_ROBOTS_TXT_MAX_FIELD_BYTES = 16384;

function sblog_robots_defaults(): array
{
    $paths = ['/admin$', '/admin?', '/admin/', '/login$', '/login?', '/login/', '/data/', '/cache/', '/install.php', '/installer.php', '/update.php', '/*?a=admin', '/*?a=login'];
    return [
        'mode' => 'custom',
        'user_agents' => '*',
        'allow_paths' => '',
        'disallow_paths' => implode("\n", array_map('app_path', $paths)),
        'sitemap_enabled' => '1',
        'sitemap_urls' => '',
    ];
}

function sblog_robots_settings(): array
{
    $settings = sblog_robots_defaults();
    $stored = json_decode(setting('robots_txt_config', '{}'), true);
    foreach ($settings as $key => $default) {
        if (is_array($stored) && isset($stored[$key]) && is_string($stored[$key])) {
            $settings[$key] = $stored[$key];
        }
    }
    return $settings;
}

function sblog_robots_modes(): array
{
    return [
        'custom' => '自定义规则（推荐）',
        'allow_all' => '允许全部抓取',
        'disallow_all' => '禁止全部抓取',
    ];
}

function sblog_robots_lines(string $value): array
{
    return array_values(array_unique(array_filter(
        array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", $value))),
        static fn(string $line): bool => $line !== ''
    )));
}

function sblog_robots_valid_sitemap(string $url): bool
{
    $parts = parse_url($url);
    return strlen($url) <= 2048 && !preg_match('/[\x00-\x20\x7F#]/', $url)
        && filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts)
        && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
}

function sblog_robots_validate_settings(array $input): array
{
    $settings = sblog_robots_defaults();
    foreach ($settings as $key => $default) {
        $value = $input[$key] ?? ($key === 'sitemap_enabled' ? '0' : $default);
        if (!is_string($value) || strlen($value) > SBLOG_ROBOTS_TXT_MAX_FIELD_BYTES
            || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException(sblog_t('设置过长或包含无效字符。'));
        }
        $settings[$key] = trim($value);
    }
    if (!isset(sblog_robots_modes()[$settings['mode']]) || !in_array($settings['sitemap_enabled'], ['0', '1'], true)) {
        throw new InvalidArgumentException(sblog_t('请选择有效的抓取模式和 Sitemap 选项。'));
    }
    foreach (['user_agents', 'allow_paths', 'disallow_paths', 'sitemap_urls'] as $key) {
        $lines = sblog_robots_lines($settings[$key]);
        if (count($lines) > ($key === 'sitemap_urls' ? 20 : 100)) {
            throw new InvalidArgumentException(sblog_t('规则过多：每项最多 100 行，额外 Sitemap 最多 20 行。'));
        }
        foreach ($lines as $line) {
            if ($key === 'user_agents' && $line !== '*' && preg_match('/^[A-Za-z0-9._-]+$/D', $line) !== 1) {
                throw new InvalidArgumentException(sblog_t('User-agent 请填写 * 或爬虫名称，每行一个。'));
            }
            if (in_array($key, ['allow_paths', 'disallow_paths'], true)
                && (strlen($line) > 2048 || !str_starts_with($line, '/') || preg_match('/[\x00-\x20\x7F#]/', $line))) {
                throw new InvalidArgumentException(sblog_t('路径必须以 / 开头，不得包含空白或 #；支持 * 和 $。'));
            }
            if ($key === 'sitemap_urls' && !sblog_robots_valid_sitemap($line)) {
                throw new InvalidArgumentException(sblog_t('Sitemap 必须是完整的 HTTP 或 HTTPS 地址，且不能包含账号密码或片段。'));
            }
        }
        $settings[$key] = implode("\n", $lines);
    }
    if ($settings['user_agents'] === '') {
        throw new InvalidArgumentException(sblog_t('请至少填写一个 User-agent。'));
    }
    return $settings;
}

function sblog_robots_content(array $settings): string
{
    $lines = [];
    // Global presets always apply to every crawler, even if a custom agent list is saved.
    $agents = $settings['mode'] === 'custom' ? sblog_robots_lines($settings['user_agents']) : ['*'];
    foreach ($agents as $agent) {
        $lines[] = 'User-agent: ' . $agent;
    }
    if ($settings['mode'] === 'disallow_all') {
        $lines[] = 'Disallow: /';
    } elseif ($settings['mode'] === 'allow_all') {
        $lines[] = 'Disallow:';
    } else {
        $disallowed = sblog_robots_lines($settings['disallow_paths']);
        foreach ($disallowed as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        if ($disallowed === []) {
            $lines[] = 'Disallow:';
        }
        foreach (sblog_robots_lines($settings['allow_paths']) as $path) {
            $lines[] = 'Allow: ' . $path;
        }
    }
    $sitemaps = sblog_robots_lines($settings['sitemap_urls']);
    if ($settings['sitemap_enabled'] === '1') {
        $automatic = absolute_url(url_for('sitemap'));
        if (sblog_robots_valid_sitemap($automatic)) {
            array_unshift($sitemaps, $automatic);
        }
    }
    if ($sitemaps !== []) {
        $lines[] = '';
        foreach (array_unique($sitemaps) as $url) {
            $lines[] = 'Sitemap: ' . $url;
        }
    }
    return implode("\n", $lines) . "\n";
}

function sblog_robots_route_action(string $action, array $context): string
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return is_string($path) && in_array($path, ['/robots.txt', app_path('/robots.txt')], true)
        ? 'robots_txt' : $action;
}

function sblog_robots_serve(): void
{
    $status = ob_get_status();
    if (is_array($status) && ($status['name'] ?? '') === 'plugin_output_buffer') {
        ob_end_clean();
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        echo "Method Not Allowed\n";
        return;
    }
    http_response_code(200);
    if (($_GET['download'] ?? '') === '1') {
        header('Content-Disposition: attachment; filename="robots.txt"');
    }
    if ($method === 'GET') {
        echo sblog_robots_content(sblog_robots_settings());
    }
}

function sblog_robots_render_settings(?array $submitted = null, string $error = ''): void
{
    require_admin();
    $settings = $submitted ?? sblog_robots_settings();
    $endpoint = script_url() . '?a=robots_txt';
    $robotsUrl = absolute_url(app_path('/robots.txt'));
    $preview = sblog_robots_content(sblog_robots_settings());
    ob_start(); ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main">
        <?= render_admin_topbar(sblog_t('robots.txt 生成器')) ?>
        <section class="panel admin-list-panel admin-animate admin-animate--2">
          <div class="panel__header"><h2><?= h(sblog_t('抓取规则设置')) ?></h2><p class="panel__meta"><?= h(sblog_t('保存后即时生效。')) ?></p></div>
          <div class="panel__body">
            <?php if ($error !== ''): ?><p role="alert" class="field-hint"><?= h($error) ?></p><?php endif; ?>
            <form class="form-stack" method="post" action="<?= h(script_url() . '?a=save_robots_txt') ?>">
              <?= csrf_field() ?>
              <div class="field"><label for="robots_mode"><?= h(sblog_t('抓取模式')) ?></label><select id="robots_mode" name="mode"><?php foreach (sblog_robots_modes() as $value => $label): ?><option value="<?= h($value) ?>"<?= $settings['mode'] === $value ? ' selected' : '' ?>><?= h(sblog_t($label)) ?></option><?php endforeach; ?></select><p class="field-hint"><?= h(sblog_t('全部允许或全部禁止模式对所有爬虫生效，并忽略下面的 User-agent 和路径规则。')) ?></p></div>
              <div class="field"><label for="robots_agents">User-agent</label><textarea id="robots_agents" name="user_agents" rows="3" maxlength="16384" spellcheck="false"><?= h($settings['user_agents']) ?></textarea><p class="field-hint"><?= h(sblog_t('每行一个爬虫名称，* 表示所有爬虫；填写的爬虫共用一组规则。')) ?></p></div>
              <div class="field-grid">
                <div class="field"><label for="robots_disallow"><?= h(sblog_t('禁止抓取路径（Disallow）')) ?></label><textarea id="robots_disallow" name="disallow_paths" rows="9" maxlength="16384" spellcheck="false"><?= h($settings['disallow_paths']) ?></textarea></div>
                <div class="field"><label for="robots_allow"><?= h(sblog_t('允许抓取路径（Allow）')) ?></label><textarea id="robots_allow" name="allow_paths" rows="9" maxlength="16384" spellcheck="false"><?= h($settings['allow_paths']) ?></textarea></div>
              </div>
              <p class="field-hint"><?= h(sblog_t('路径每行一个，以域名根目录的 / 开头，支持 * 通配符和 $ 结束符；留空表示没有对应规则。子目录站点请保留路径前缀。')) ?></p>
              <label class="setting-option"><input name="sitemap_enabled" type="checkbox" value="1"<?= $settings['sitemap_enabled'] === '1' ? ' checked' : '' ?>><span><strong><?= h(sblog_t('自动添加本站 Sitemap')) ?></strong><small><?= h(absolute_url(url_for('sitemap'))) ?></small></span></label>
              <div class="field"><label for="robots_sitemaps"><?= h(sblog_t('额外 Sitemap 地址')) ?></label><textarea id="robots_sitemaps" name="sitemap_urls" rows="3" maxlength="16384" spellcheck="false"><?= h($settings['sitemap_urls']) ?></textarea><p class="field-hint"><?= h(sblog_t('每行一个完整的 HTTP 或 HTTPS 地址，最多 20 个。')) ?></p></div>
              <div class="action-row"><button class="button" type="submit"><?= h(sblog_t('保存 robots.txt 设置')) ?></button></div>
            </form>
          </div>
        </section>
        <section class="panel admin-list-panel admin-animate admin-animate--3">
          <div class="panel__header"><h2><?= h(sblog_t('当前生效内容')) ?></h2></div>
          <div class="panel__body form-stack">
            <div class="field"><label for="robots_preview">robots.txt</label><textarea id="robots_preview" rows="14" readonly spellcheck="false"><?= h($preview) ?></textarea></div>
            <div class="action-row"><a class="button button--secondary" href="<?= h($robotsUrl) ?>" target="_blank" rel="noopener"><?= h(sblog_t('访问 robots.txt')) ?></a><a class="button button--secondary" href="<?= h($endpoint) ?>" target="_blank" rel="noopener"><?= h(sblog_t('预览纯文本')) ?></a><a class="button button--secondary" href="<?= h($endpoint . '&download=1') ?>"><?= h(sblog_t('下载 robots.txt')) ?></a></div>
            <?php if (app_base_path() !== ''): ?><p class="field-hint"><?= h(sblog_t('本站安装在子目录。搜索引擎只读取域名根目录 /robots.txt，请下载后部署到域名根目录，或将根路径转发到本插件的纯文本入口。')) ?></p><?php endif; ?>
            <?php if (is_file(dirname(PLUGINS_DIR) . '/robots.txt')): ?><p class="field-hint"><?= h(sblog_t('站点目录已有实体 robots.txt，服务器会优先返回该文件；请备份并移除它，或用下载内容更新它。')) ?></p><?php endif; ?>
          </div>
        </section>
      </div>
    </div>
    <?php
    render_layout(sblog_t('robots.txt 生成器'), (string)ob_get_clean(), [
        'active' => 'plugins', 'wide' => true, 'description' => sblog_t('抓取规则设置'),
        'status' => $error !== '' ? 422 : 200,
    ]);
}

function sblog_robots_handle_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    if ($action === 'robots_txt') {
        sblog_robots_serve();
        exit;
    }
    if ($action === 'admin_robots_txt') {
        sblog_robots_render_settings();
        exit;
    }
    if ($action !== 'save_robots_txt') {
        return;
    }
    $settingsUrl = script_url() . '?a=admin_robots_txt';
    require_admin_post($settingsUrl);
    try {
        $settings = sblog_robots_validate_settings($_POST);
    } catch (InvalidArgumentException $exception) {
        $submitted = sblog_robots_settings();
        foreach ($submitted as $key => $value) {
            if (isset($_POST[$key]) && is_string($_POST[$key])) {
                $submitted[$key] = substr($_POST[$key], 0, SBLOG_ROBOTS_TXT_MAX_FIELD_BYTES);
            }
        }
        $submitted['sitemap_enabled'] = ($_POST['sitemap_enabled'] ?? '') === '1' ? '1' : '0';
        http_response_code(422);
        sblog_robots_render_settings($submitted, $exception->getMessage());
        exit;
    }
    try {
        // One SQLite write publishes the whole ruleset, avoiding partially saved policies.
        save_settings(['robots_txt_config' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        set_flash('success', sblog_t('robots.txt 设置已保存。'));
    } catch (Throwable $exception) {
        error_log('Robots.txt settings save failed: ' . $exception->getMessage());
        set_flash('error', sblog_t('robots.txt 设置保存失败，请重试。'));
    }
    redirect_to($settingsUrl, 303);
}

add_plugin_filter('route_action', 'sblog_robots_route_action');
add_plugin_action('request', 'sblog_robots_handle_request', -1000);
