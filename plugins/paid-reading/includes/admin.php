<?php
declare(strict_types=1);

function pr_admin_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    if ($action === 'admin_paid_reading') {
        pr_render_admin();
        exit;
    }
    if ($action === 'save_paid_reading_settings') {
        pr_save_settings();
        exit;
    }
    if ($action !== 'revoke_paid_reading_order') {
        return;
    }
    $fallback = pr_url('admin_paid_reading', ['tab' => 'orders']);
    require_admin_post($fallback);
    try {
        $orderNo = $_POST['order_no'] ?? '';
        if (!is_string($orderNo) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $orderNo)) {
            throw new InvalidArgumentException(sblog_t('订单编号无效。'));
        }
        pr_revoke_order($orderNo);
        set_flash('success', sblog_t('阅读权限已撤销。退款请在支付平台单独处理。'));
    } catch (Throwable $exception) {
        if (!$exception instanceof InvalidArgumentException && !$exception instanceof DomainException) {
            error_log('Paid reading revoke failed: ' . $exception->getMessage());
        }
        set_flash('error', $exception instanceof InvalidArgumentException || $exception instanceof DomainException
            ? $exception->getMessage() : sblog_t('撤销失败，请稍后重试。'));
    }
    redirect_to($fallback, 303);
}

function pr_admin_settings_values(array $post, array $existing): array
{
    $values = [];
    $textKeys = [
        'default_price', 'order_ttl',
        'alipay_app_id', 'alipay_seller_id', 'alipay_private_key',
        'alipay_app_cert', 'alipay_public_cert', 'alipay_root_cert',
        'wechat_mch_id', 'wechat_app_id', 'wechat_private_key',
        'wechat_mch_cert', 'wechat_secret_key', 'wechat_public_cert',
    ];
    $secretKeys = ['alipay_private_key', 'wechat_private_key', 'wechat_secret_key'];
    foreach ($textKeys as $key) {
        if (!is_string($post[$key] ?? null)) {
            throw new InvalidArgumentException(sblog_t('设置表单不完整，请重新填写。'));
        }
        $value = trim($post[$key]);
        if (strlen($value) > 262144 || str_contains($value, "\0")) {
            throw new InvalidArgumentException(sblog_t('设置内容过长或包含无效字符。'));
        }
        $values[$key] = in_array($key, $secretKeys, true) && $value === ''
            ? (string)($existing[$key] ?? '') : $value;
    }
    foreach (['alipay_enabled', 'alipay_sandbox', 'wechat_enabled'] as $key) {
        if (isset($post[$key]) && $post[$key] !== '1') {
            throw new InvalidArgumentException(sblog_t('支付开关无效。'));
        }
        $values[$key] = isset($post[$key]) ? '1' : '0';
    }
    $price = pr_money_cents($values['default_price']);
    if ($price === null) {
        throw new InvalidArgumentException(sblog_t('默认价格必须大于 0，最多保留两位小数，且不超过 100 万元。'));
    }
    $values['default_price'] = pr_format_money($price);
    if (!preg_match('/^\d{1,4}$/D', $values['order_ttl'])
        || (int)$values['order_ttl'] < 5 || (int)$values['order_ttl'] > 120) {
        throw new InvalidArgumentException(sblog_t('订单有效期必须为 5 到 120 分钟。'));
    }
    $values['order_ttl'] = (string)(int)$values['order_ttl'];
    foreach (['alipay_app_id', 'alipay_seller_id', 'wechat_mch_id', 'wechat_app_id'] as $key) {
        if (strlen($values[$key]) > 128 || preg_match('/[\x00-\x20\x7F]/', $values[$key])) {
            throw new InvalidArgumentException(sblog_t('商户和应用标识不能包含空格，且不能超过 128 个字符。'));
        }
    }
    if ($values['alipay_enabled'] === '1' || $values['wechat_enabled'] === '1') {
        $baseUrl = pr_base_url();
        if (strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new InvalidArgumentException(sblog_t('请先在站点设置中填写可公网访问的 HTTPS 站点地址。'));
        }
    }
    foreach (['alipay', 'wechat'] as $channel) {
        if ($values[$channel . '_enabled'] !== '1') {
            continue;
        }
        try {
            // Use exactly the same credential checks as payment creation and callbacks.
            pr_payment_credentials($values, $channel);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
    }
    return $values;
}

function pr_save_settings(): void
{
    $fallback = pr_url('admin_paid_reading');
    require_admin_post($fallback);
    try {
        $values = pr_admin_settings_values($_POST, pr_settings());
        $prefixed = [];
        foreach ($values as $name => $value) {
            $prefixed['paid_reading_' . $name] = $value;
        }
        save_settings($prefixed);
        set_flash('success', sblog_t('付费阅读设置已保存。'));
    } catch (Throwable $exception) {
        if (!$exception instanceof InvalidArgumentException && !$exception instanceof DomainException) {
            error_log('Paid reading settings failed: ' . $exception->getMessage());
        }
        set_flash('error', $exception instanceof InvalidArgumentException || $exception instanceof DomainException
            ? $exception->getMessage() : sblog_t('设置保存失败，请检查站点地址、证书和服务器日志。'));
    }
    redirect_to($fallback, 303);
}

function pr_admin_field(array $settings, string $key, string $label, string $hint = '', bool $secret = false, bool $multiline = false): void
{
    $value = $secret ? '' : (string)($settings[$key] ?? '');
    $configured = $secret && (string)($settings[$key] ?? '') !== '';
    ?>
    <div class="field pr-admin__field">
      <label for="pr-<?= h($key) ?>"><?= h(sblog_t($label)) ?><?php if ($configured): ?> <span class="pr-badge pr-badge--paid"><?= h(sblog_t('已配置')) ?></span><?php endif; ?></label>
      <?php if ($multiline): ?><textarea id="pr-<?= h($key) ?>" name="<?= h($key) ?>" rows="<?= $secret ? 4 : 3 ?>" maxlength="262144" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="<?= $configured ? h(sblog_t('留空保留已保存的密钥')) : '' ?>"><?= h($value) ?></textarea><?php else: ?><input id="pr-<?= h($key) ?>" name="<?= h($key) ?>" type="<?= $secret ? 'password' : 'text' ?>" value="<?= h($value) ?>" maxlength="262144" autocomplete="<?= $secret ? 'new-password' : 'off' ?>" autocapitalize="off" spellcheck="false" placeholder="<?= $configured ? h(sblog_t('留空保留已保存的密钥')) : '' ?>"><?php endif; ?>
      <?php if ($hint !== ''): ?><small class="field-hint"><?= h(sblog_t($hint)) ?></small><?php endif; ?>
    </div>
    <?php
}

function pr_render_admin(): void
{
    require_admin();
    $tab = ($_GET['tab'] ?? '') === 'orders' ? 'orders' : 'settings';
    $settings = pr_settings();
    $pageValue = $_GET['page'] ?? 1;
    $page = max(1, is_scalar($pageValue) ? (int)$pageValue : 1);
    $orders = $tab === 'orders' ? pr_orders($page) : [];
    ob_start(); ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main">
        <?= render_admin_topbar(sblog_t('付费阅读')) ?>
        <div class="pr-admin admin-animate admin-animate--2">
          <nav class="pr-admin__tabs" aria-label="<?= h(sblog_t('付费阅读管理')) ?>"><a href="<?= h(pr_url('admin_paid_reading')) ?>"<?= $tab === 'settings' ? ' aria-current="page"' : '' ?>><?= h(sblog_t('支付设置')) ?></a><a href="<?= h(pr_url('admin_paid_reading', ['tab' => 'orders'])) ?>"<?= $tab === 'orders' ? ' aria-current="page"' : '' ?>><?= h(sblog_t('订单与权限')) ?></a></nav>
          <?php if ($tab === 'settings'): ?>
            <section class="panel pr-admin__setup"><div class="panel__header"><h2><?= h(sblog_t('收款准备')) ?></h2><p class="panel__meta"><?= h(sblog_t('支持支付宝电脑网站 / 手机网站支付和微信 H5 支付。付款确认后解锁当前文章。')) ?></p></div><div class="panel__body">
              <p class="field-hint"><?= h(sblog_t('插件已附带支付 SDK，需要 PHP 8.2 或更新版本。手动部署或恢复依赖时，可在插件目录执行：')) ?></p><pre class="pr-admin__command"><code>composer install --no-dev --prefer-dist</code></pre>
              <p class="field-hint"><?= h(sblog_t('站点地址必须在站点设置中配置为可公网访问的 HTTPS 地址，支付平台会向此地址发送付款通知。证书文件建议存放在网站公开目录之外。')) ?></p>
              <div class="pr-admin__readiness"><?php foreach (['alipay' => '支付宝', 'wechat' => '微信支付'] as $channel => $label): $ready = pr_payment_ready($settings, $channel); ?><span class="pr-badge <?= $ready ? 'pr-badge--paid' : 'pr-badge--pending' ?>"><?= h(sblog_t($label)) ?> · <?= h(sblog_t($ready ? '已就绪' : '待配置')) ?></span><?php endforeach; ?></div>
            </div></section>
            <form class="form-stack pr-admin__settings" method="post" action="<?= h(pr_url('save_paid_reading_settings')) ?>">
              <?= csrf_field() ?>
              <section class="panel"><div class="panel__header"><h2><?= h(sblog_t('阅读与订单')) ?></h2></div><div class="panel__body pr-admin__grid">
                <div class="field"><label for="pr-default-price"><?= h(sblog_t('默认价格（元）')) ?></label><input id="pr-default-price" name="default_price" type="number" min="0.01" max="1000000" step="0.01" value="<?= h((string)$settings['default_price']) ?>" required><small class="field-hint"><?= h(sblog_t('可在每篇文章的编辑器中单独调整。')) ?></small></div>
                <div class="field"><label for="pr-order-ttl"><?= h(sblog_t('订单有效期（分钟）')) ?></label><input id="pr-order-ttl" name="order_ttl" type="number" min="5" max="120" step="1" value="<?= h((string)$settings['order_ttl']) ?>" required><small class="field-hint"><?= h(sblog_t('过期的未支付订单需要重新创建。')) ?></small></div>
                <p class="field-hint pr-admin__full"><?= h(sblog_t('在正文中插入 <!-- paid-reading -->：之前是免费预览，之后是付费正文。未插入时整篇正文需要付费；摘要仅从免费预览生成。')) ?></p>
              </div></section>
              <section class="panel"><div class="panel__header"><h2><?= h(sblog_t('支付宝')) ?></h2><p class="panel__meta"><?= h(sblog_t('使用证书模式，需开通电脑网站支付和手机网站支付。')) ?></p></div><div class="panel__body form-stack">
                <label class="setting-option"><input type="checkbox" name="alipay_enabled" value="1"<?= $settings['alipay_enabled'] === '1' ? ' checked' : '' ?>><span><strong><?= h(sblog_t('启用支付宝')) ?></strong><small><?= h(sblog_t('填写商户信息和全部证书后启用。')) ?></small></span></label>
                <div class="pr-admin__grid"><?php pr_admin_field($settings, 'alipay_app_id', '应用 ID（App ID）'); pr_admin_field($settings, 'alipay_seller_id', '卖家 ID（Seller ID）'); ?></div>
                <?php pr_admin_field($settings, 'alipay_private_key', '应用私钥', '完整 PEM 或服务器绝对文件路径；留空保留已保存的值。', true, true); ?>
                <div class="pr-admin__grid"><?php pr_admin_field($settings, 'alipay_app_cert', '应用公钥证书', '完整 PEM 或服务器绝对文件路径。', false, true); pr_admin_field($settings, 'alipay_public_cert', '支付宝公钥证书', '完整 PEM 或服务器绝对文件路径。', false, true); pr_admin_field($settings, 'alipay_root_cert', '支付宝根证书', '完整 PEM 或服务器绝对文件路径。', false, true); ?></div>
                <label class="setting-option"><input type="checkbox" name="alipay_sandbox" value="1"<?= $settings['alipay_sandbox'] === '1' ? ' checked' : '' ?>><span><strong><?= h(sblog_t('使用支付宝沙箱')) ?></strong><small><?= h(sblog_t('用于联调，正式收款前请关闭。')) ?></small></span></label>
              </div></section>
              <section class="panel"><div class="panel__header"><h2><?= h(sblog_t('微信支付')) ?></h2><p class="panel__meta"><?= h(sblog_t('使用 API v3，需开通 H5 支付并在商户平台配置当前站点的支付域名。读者需在手机系统浏览器中支付。')) ?></p></div><div class="panel__body form-stack">
                <label class="setting-option"><input type="checkbox" name="wechat_enabled" value="1"<?= $settings['wechat_enabled'] === '1' ? ' checked' : '' ?>><span><strong><?= h(sblog_t('启用微信支付')) ?></strong><small><?= h(sblog_t('填写商户信息、API v3 密钥和平台证书后启用。')) ?></small></span></label>
                <div class="pr-admin__grid"><?php pr_admin_field($settings, 'wechat_mch_id', '商户号（Mch ID）'); pr_admin_field($settings, 'wechat_app_id', '应用 ID（App ID）'); ?></div>
                <?php pr_admin_field($settings, 'wechat_secret_key', 'API v3 密钥', '32 个字符；留空保留已保存的值。', true); pr_admin_field($settings, 'wechat_private_key', '商户私钥', '完整 PEM 或服务器绝对文件路径；留空保留已保存的值。', true, true); pr_admin_field($settings, 'wechat_mch_cert', '商户证书', '完整 PEM 或服务器绝对文件路径。', false, true); pr_admin_field($settings, 'wechat_public_cert', '微信平台证书 / 公钥映射', 'JSON 对象：证书序列号或 PUB_KEY_ID_ 开头的公钥 ID 对应 PEM 或绝对文件路径，例如 {"PUB_KEY_ID_...": "/secure/wechat-public.pem"}。支持同时配置多份。', false, true); ?>
              </div></section>
              <div class="action-row pr-admin__save"><button class="button" type="submit"><?= h(sblog_t('保存设置')) ?></button></div>
            </form>
          <?php else: ?>
            <section class="panel"><div class="panel__header"><h2><?= h(sblog_t('阅读订单')) ?> <span class="pr-admin__count"><?= (int)($orders['total'] ?? 0) ?></span></h2><p class="panel__meta"><?= h(sblog_t('撤销权限会立即停止该订单的阅读授权。退款需在支付平台处理。')) ?></p></div>
              <?php if (($orders['items'] ?? []) === []): ?><div class="panel__body pr-admin__empty"><strong><?= h(sblog_t('还没有阅读订单')) ?></strong><p><?= h(sblog_t('读者购买文章后，可在这里查看支付状态和管理权限。')) ?></p></div><?php else: ?>
                <div class="table-wrap pr-admin__orders"><table><thead><tr><th><?= h(sblog_t('订单 / 文章')) ?></th><th><?= h(sblog_t('金额 / 渠道')) ?></th><th><?= h(sblog_t('状态')) ?></th><th><?= h(sblog_t('创建 / 付款时间')) ?></th><th><?= h(sblog_t('权限')) ?></th></tr></thead><tbody><?php foreach ($orders['items'] as $order): $status = (string)$order['status']; $statusLabel = ['pending' => '待支付', 'paid' => '已支付', 'revoked' => '已撤销'][$status] ?? '未知'; ?>
                  <tr><td><strong><?= h((string)($order['title'] ?? '')) ?></strong><br><code><?= h((string)$order['order_no']) ?></code><small class="pr-admin__post-id"><?= h(sblog_t('文章')) ?> #<?= (int)$order['post_id'] ?></small></td><td><strong>¥<?= h(pr_format_money((int)$order['amount_cents'])) ?></strong><br><small><?= h(sblog_t($order['channel'] === 'alipay' ? '支付宝' : '微信支付')) ?></small></td><td><span class="pr-badge pr-badge--<?= h(in_array($status, ['pending', 'paid', 'revoked'], true) ? $status : 'revoked') ?>"><?= h(sblog_t($statusLabel)) ?></span></td><td><span><?= h(date('Y-m-d H:i', (int)$order['created_at'])) ?></span><br><small><?= (int)($order['paid_at'] ?? 0) > 0 ? h(date('Y-m-d H:i', (int)$order['paid_at'])) : '—' ?></small></td><td><?php if ($status === 'paid'): ?><form method="post" action="<?= h(pr_url('revoke_paid_reading_order')) ?>"><?= csrf_field() ?><input type="hidden" name="order_no" value="<?= h((string)$order['order_no']) ?>"><button class="button button--secondary button--compact" type="submit"><?= h(sblog_t('撤销权限')) ?></button></form><?php else: ?><span class="field-hint">—</span><?php endif; ?></td></tr>
                <?php endforeach; ?></tbody></table></div>
              <?php endif; ?>
              <?php $pages = max(1, (int)ceil((int)($orders['total'] ?? 0) / max(1, (int)($orders['per_page'] ?? 20)))); $currentPage = max(1, (int)($orders['page'] ?? $page)); if ($pages > 1): ?><nav class="admin-pagination pr-admin__pagination" aria-label="<?= h(sblog_t('订单分页')) ?>"><?php if ($currentPage > 1): ?><a href="<?= h(pr_url('admin_paid_reading', ['tab' => 'orders', 'page' => $currentPage - 1])) ?>"><?= h(sblog_t('上一页')) ?></a><?php endif; ?><span aria-current="page"><?= $currentPage ?> / <?= $pages ?></span><?php if ($currentPage < $pages): ?><a href="<?= h(pr_url('admin_paid_reading', ['tab' => 'orders', 'page' => $currentPage + 1])) ?>"><?= h(sblog_t('下一页')) ?></a><?php endif; ?></nav><?php endif; ?>
            </section>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    render_layout(sblog_t('付费阅读'), (string)ob_get_clean(), ['active' => 'plugins', 'wide' => true]);
}

function pr_editor_control(string $html, array $context): string
{
    if (($context['field'] ?? '') !== 'content') {
        return $html;
    }
    $postId = max(0, (int)($context['post_id'] ?? 0));
    $content = $postId > 0 ? pr_content_for_post($postId) : null;
    $enabled = $content !== null;
    $price = $content !== null ? pr_format_money((int)$content['price_cents']) : (string)pr_settings()['default_price'];
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $enabled = ($_POST['paid_reading_enabled'] ?? '') === '1';
        $price = is_scalar($_POST['paid_reading_price'] ?? null) ? (string)$_POST['paid_reading_price'] : $price;
    }
    ob_start(); ?>
    <span class="pr-editor-control"><input type="hidden" name="paid_reading_present" value="1"><span class="pr-editor-control__toggle"><input id="pr-post-enabled" type="checkbox" name="paid_reading_enabled" value="1" aria-label="<?= h(sblog_t('启用付费阅读')) ?>"<?= $enabled ? ' checked' : '' ?>> <span><?= h(sblog_t('付费阅读')) ?></span></span><span class="pr-editor-control__price">¥ <input type="number" name="paid_reading_price" min="0.01" max="1000000" step="0.01" value="<?= h($price) ?>" aria-label="<?= h(sblog_t('阅读价格（元）')) ?>"></span><small class="pr-editor-control__hint"><?= h(sblog_t('用 <!-- paid-reading --> 分隔免费预览与付费正文；未分隔则整篇付费。摘要仅使用免费预览。')) ?></small></span>
    <?php return $html . (string)ob_get_clean();
}
