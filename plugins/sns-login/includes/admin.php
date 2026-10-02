<?php
declare(strict_types=1);

function sns_admin_string(mixed $value, string $label, int $limit = 512): string
{
    if (!is_string($value) || strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException($label . '格式无效或长度超出限制。');
    }
    return trim($value);
}

function sns_admin_checkbox(array $row, string $key): bool
{
    if (isset($row[$key]) && $row[$key] !== '1') {
        throw new InvalidArgumentException('登录服务开关格式无效。');
    }
    return ($row[$key] ?? '') === '1';
}

function sns_admin_post_config(array $post): array
{
    if (($post['config_complete'] ?? null) !== '1' || !is_array($post['providers'] ?? null)) {
        throw new InvalidArgumentException('表单不完整，请刷新页面后重新保存。');
    }
    $existing = sns_config();
    $config = [];
    foreach (sns_providers() as $slug => $provider) {
        $row = $post['providers'][$slug] ?? null;
        if (!is_array($row)) {
            throw new InvalidArgumentException('登录服务配置不完整，请刷新页面后重新保存。');
        }
        $name = (string)($provider['name'] ?? $slug);
        $clientId = sns_admin_string($row['client_id'] ?? null, $name . ' Client ID');
        $secret = sns_admin_string($row['client_secret'] ?? null, $name . ' Client Secret', 2048);
        if (preg_match('/\s/', $clientId . $secret)) {
            throw new InvalidArgumentException($name . '：应用凭据不能包含空白字符。');
        }
        $enabled = sns_admin_checkbox($row, 'enabled');
        $clear = sns_admin_checkbox($row, 'clear_secret');
        if ($clear && $secret !== '') {
            throw new InvalidArgumentException($name . '：不能同时填写新密钥并清除密钥。');
        }
        if ($secret === '' && !$clear) {
            $secret = (string)($existing[$slug]['client_secret'] ?? '');
        }
        if ($enabled && ($clientId === '' || $secret === '')) {
            throw new InvalidArgumentException($name . '：启用前请填写 Client ID 和 Client Secret。');
        }
        $config[$slug] = ['enabled' => $enabled, 'client_id' => $clientId, 'client_secret' => $secret];
    }
    return $config;
}

function sns_render_admin(): void
{
    require_admin();
    $admin = current_admin();
    $providers = sns_providers();
    $config = sns_config();
    $bindings = sns_bindings((int)$admin['id']);
    $callbacks = [];
    $callbackError = '';
    foreach ($providers as $slug => $provider) {
        try {
            $callbacks[$slug] = sns_callback_url((string)$slug);
        } catch (Throwable) {
            $callbackError = '请先在站点设置中填写有效的 HTTPS 站点地址，再配置第三方登录回调。';
            $callbacks[$slug] = '';
        }
    }

    ob_start();
    ?>
    <div class="admin-shell sns-login-admin">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main">
        <?= render_admin_topbar(sblog_t('第三方登录')) ?>
        <section class="panel admin-list-panel admin-animate admin-animate--2">
          <div class="panel__header"><h2>登录服务</h2><p class="panel__meta">配置后，为当前管理员绑定第三方账号，即可在登录页使用对应服务登录。</p></div>
          <div class="panel__body">
            <p class="sns-login-notice">第三方账号需先完成绑定。请保留原用户名和密码，以便解绑或找回账号。</p>
            <?php if ($callbackError !== ''): ?><div class="flash flash--error" role="alert"><?= h($callbackError) ?></div><?php endif; ?>
            <form class="form-stack" method="post" action="<?= h(sns_url('save_sns_login_settings')) ?>">
              <?= csrf_field() ?>
              <div class="sns-login-providers">
                <?php foreach ($providers as $slug => $provider): ?>
                  <?php $settings = $config[$slug] ?? ['enabled' => false, 'client_id' => '', 'client_secret' => '']; ?>
                  <?php $name = (string)($provider['name'] ?? $slug); $prefix = 'providers[' . $slug . ']'; ?>
                  <fieldset class="sns-login-provider">
                    <legend><?= h($name) ?></legend>
                    <label class="sns-login-toggle"><input type="checkbox" name="<?= h($prefix) ?>[enabled]" value="1"<?= $settings['enabled'] ? ' checked' : '' ?>> 启用 <?= h($name) ?> 登录</label>
                    <div class="field"><label for="sns-client-<?= h($slug) ?>">Client ID<?= $slug === 'qq' ? ' / App ID' : '' ?></label><input id="sns-client-<?= h($slug) ?>" name="<?= h($prefix) ?>[client_id]" value="<?= h((string)$settings['client_id']) ?>" maxlength="512" autocomplete="off" spellcheck="false"></div>
                    <div class="field"><label for="sns-secret-<?= h($slug) ?>">Client Secret<?= $slug === 'qq' ? ' / App Key' : '' ?></label><input id="sns-secret-<?= h($slug) ?>" name="<?= h($prefix) ?>[client_secret]" type="password" value="" maxlength="2048" autocomplete="new-password" placeholder="<?= $settings['client_secret'] !== '' ? '已保存密钥；留空保留原值' : '填写应用密钥' ?>"><p class="field-hint">密钥仅保存在服务器，保存后不会回显。</p><label class="sns-login-toggle sns-login-toggle--muted"><input type="checkbox" name="<?= h($prefix) ?>[clear_secret]" value="1"> 清除已保存的密钥（请同时取消启用）</label></div>
                    <div class="field"><label for="sns-callback-<?= h($slug) ?>">授权回调地址</label><input id="sns-callback-<?= h($slug) ?>" value="<?= h($callbacks[$slug]) ?>" readonly autocomplete="off" spellcheck="false"><p class="field-hint">将完整地址填入服务商的回调设置，包括查询参数。更换应用后，请重新绑定账号。</p></div>
                  </fieldset>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="config_complete" value="1">
              <div class="action-row"><button class="button" type="submit">保存登录服务</button></div>
            </form>
          </div>
        </section>

        <section class="panel admin-list-panel admin-animate admin-animate--3">
          <div class="panel__header"><h2>我的账号绑定</h2><p class="panel__meta">绑定只属于当前管理员。绑定和解绑均需确认当前后台密码。</p></div>
          <div class="panel__body">
            <?php if ($bindings === []): ?><p class="field-hint">尚未绑定第三方账号。保存登录服务配置后，可在下方完成绑定。</p><?php endif; ?>
            <?php if ($bindings !== []): ?>
              <ul class="sns-login-bindings">
                <?php foreach ($bindings as $binding): ?>
                  <?php $slug = (string)$binding['provider']; $name = (string)($providers[$slug]['name'] ?? $slug); ?>
                  <?php $isCurrent = isset($config[$slug]) && hash_equals((string)$config[$slug]['client_id'], (string)$binding['client_id']); ?>
                  <li class="sns-login-binding">
                    <div class="sns-login-binding__identity"><strong><?= h($name) ?></strong><span><?= h((string)$binding['name']) ?></span><span class="sns-login-app-status<?= $isCurrent ? '' : ' sns-login-app-status--old' ?>"><?= $isCurrent ? '当前应用' : '旧应用 / 应用已移除' ?></span><small>绑定于 <?= h(date('Y-m-d H:i', (int)$binding['created_at'])) ?><?php if ((int)$binding['last_used_at'] > 0): ?> · 最近登录 <?= h(date('Y-m-d H:i', (int)$binding['last_used_at'])) ?><?php endif; ?></small></div>
                    <form class="sns-login-binding__form" method="post" action="<?= h(sns_url('unlink_sns_login')) ?>">
                      <?= csrf_field() ?><input type="hidden" name="binding_id" value="<?= (int)$binding['id'] ?>">
                      <div class="field"><label for="sns-unlink-password-<?= (int)$binding['id'] ?>">当前后台密码</label><input id="sns-unlink-password-<?= (int)$binding['id'] ?>" name="password" type="password" autocomplete="current-password" maxlength="4096" required></div>
                      <button class="button button--secondary" type="submit">解除绑定</button>
                    </form>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <div class="sns-login-bind-actions">
              <?php foreach ($providers as $slug => $provider): ?>
                <?php $settings = $config[$slug] ?? []; ?>
                <?php if (empty($settings['enabled']) || empty($settings['client_id']) || empty($settings['client_secret']) || $callbacks[$slug] === '') { continue; } ?>
                <?php $alreadyBound = false; foreach ($bindings as $binding) { if ($binding['provider'] === $slug && hash_equals((string)$settings['client_id'], (string)$binding['client_id'])) { $alreadyBound = true; break; } } ?>
                <?php if ($alreadyBound) { continue; } ?>
                <form class="sns-login-bind-form" method="post" action="<?= h(sns_url('sns_login_start')) ?>">
                  <?= csrf_field() ?><input type="hidden" name="provider" value="<?= h($slug) ?>"><input type="hidden" name="purpose" value="bind">
                  <strong>绑定 <?= h((string)($provider['name'] ?? $slug)) ?></strong>
                  <div class="field"><label for="sns-bind-password-<?= h($slug) ?>">当前后台密码</label><input id="sns-bind-password-<?= h($slug) ?>" name="password" type="password" autocomplete="current-password" maxlength="4096" required></div>
                  <button class="button" type="submit">验证密码并前往授权</button>
                </form>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      </div>
    </div>
    <?php
    render_layout(sblog_t('第三方登录'), (string)ob_get_clean(), ['active' => 'plugins', 'wide' => true, 'description' => '第三方登录与管理员账号绑定']);
}

function sns_handle_admin_request(string $action): bool
{
    if (!in_array($action, ['admin_sns_login', 'save_sns_login_settings', 'unlink_sns_login'], true)) {
        return false;
    }
    $url = sns_url('admin_sns_login');
    if ($action === 'admin_sns_login') {
        require_admin();
        sns_render_admin();
        exit;
    }
    require_admin_post($url);
    try {
        if ($action === 'save_sns_login_settings') {
            sns_save_config(sns_admin_post_config($_POST));
            set_flash('success', '第三方登录服务已保存。');
        } else {
            $id = sns_admin_string($_POST['binding_id'] ?? null, '账号绑定编号', 20);
            if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id) || (string)(int)$id !== $id) {
                throw new InvalidArgumentException('账号绑定编号无效。');
            }
            $password = $_POST['password'] ?? null;
            if (!is_string($password) || strlen($password) > 4096) {
                throw new InvalidArgumentException('当前后台密码格式无效。');
            }
            sns_verify_password($password);
            sns_unlink((int)$id, (int)current_admin()['id']);
            set_flash('success', '第三方账号绑定已解除。');
        }
    } catch (InvalidArgumentException | DomainException | SnsLoginException $exception) {
        set_flash('error', $exception->getMessage());
    } catch (Throwable) {
        error_log('SNS login admin operation failed.');
        set_flash('error', '操作失败，请稍后重试。');
    }
    redirect_to($url, 303);
    exit;
}
