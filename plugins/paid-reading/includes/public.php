<?php
declare(strict_types=1);

function pr_paywall(array $post, array $record): string
{
    $settings = pr_settings();
    $channels = [];
    $email = pr_verified_email();
    foreach (['alipay' => '支付宝', 'wechat' => '微信 H5'] as $channel => $label) {
        if (pr_base_url() !== '' && pr_recovery_mail_ready() && pr_payment_ready($settings, $channel)) {
            $channels[$channel] = $label;
        }
    }
    // This function runs inside the core output-buffer handler. Starting a
    // nested output buffer there is fatal in PHP, so build a returned string.
    $html = '<aside class="paid-reading-wall" aria-label="付费阅读">'
        . '<span class="paid-reading-wall__badge">付费阅读</span>'
        . '<h2 class="paid-reading-wall__title">解锁完整内容</h2>'
        . '<p class="paid-reading-wall__price"><small>¥</small>' . h(pr_format_money((int)$record['price_cents'])) . '</p>'
        . '<p class="paid-reading-wall__hint">一次购买，邮箱验证后可在其他设备继续阅读。</p>';
    if ($channels && is_live_content($post) && $email !== '') {
        $html .= '<p class="paid-reading-wall__hint">购买邮箱：' . h($email) . '</p>';
        $html .= '<form class="paid-reading-wall__actions" method="post" action="' . h(pr_url('paid_reading_create')) . '">'
            . csrf_field() . '<input type="hidden" name="post_id" value="' . (int)$post['id'] . '">';
        foreach ($channels as $channel => $label) {
            $html .= '<button class="paid-reading-button paid-reading-channel" type="submit" name="channel" value="'
                . h($channel) . '">' . h($label) . '购买</button>';
        }
        $html .= '</form>';
    } elseif ($channels && is_live_content($post)) {
        $html .= '<a class="paid-reading-button" href="' . h(pr_reader_url((int)$post['id'])) . '">验证邮箱并购买</a>';
    } else {
        $html .= '<p class="paid-reading-wall__status" role="status">支付暂未开放，请稍后再来。</p>';
    }
    return $html . '<a class="paid-reading-reader-link" href="' . h(pr_reader_url((int)$post['id'])) . '">已购买？恢复阅读权限</a>'
        . '<small class="paid-reading-wall__hint">微信 H5 支付请在手机系统浏览器中打开。</small></aside>';
}

function pr_order_state(array $order): string
{
    if ($order['status'] === 'pending' && (int)$order['expires_at'] < time()) {
        return 'expired';
    }
    return (string)$order['status'];
}

function pr_order_post_url(array $order): string
{
    $post = one('SELECT * FROM posts WHERE id = ?', [(int)$order['post_id']]);
    return $post && is_live_content($post) ? content_permalink($post) : '';
}

function pr_render_return(array $order): void
{
    $state = pr_order_state($order);
    $postUrl = pr_order_post_url($order);
    $labels = ['paid' => '支付成功，内容已解锁', 'pending' => '正在等待支付确认', 'expired' => '订单支付窗口已结束', 'revoked' => '此订单的阅读权限已撤销'];
    ob_start(); ?>
    <section class="paid-reading-checkout"
      <?php if ($state === 'pending'): ?>data-paid-reading-status="<?= h(pr_url('paid_reading_status', ['order' => $order['order_no']])) ?>"<?php endif; ?>>
      <span class="paid-reading-wall__badge">付费阅读</span>
      <h1 class="paid-reading-checkout__title"><?= h($labels[$state] ?? '订单状态未知') ?></h1>
      <p><?= h((string)$order['title']) ?></p>
      <p class="paid-reading-checkout__price">¥<?= h(pr_format_money((int)$order['amount_cents'])) ?></p>
      <p class="paid-reading-checkout__status" role="status" aria-live="polite">
        <?= h($state === 'pending' ? '支付平台确认后会自动刷新。页面跳转本身不会解锁内容。' : ($state === 'expired' ? '如已付款，支付通知到达后仍会解锁，请稍后刷新。' : '')) ?>
      </p>
      <p class="paid-reading-checkout__hint">订单号：<?= h((string)$order['order_no']) ?></p>
      <div class="paid-reading-checkout__actions">
        <?php if ($postUrl !== ''): ?><a class="paid-reading-button" href="<?= h($postUrl) ?>"><?= $state === 'paid' ? '阅读文章' : '返回文章' ?></a><?php endif; ?>
        <a class="paid-reading-button paid-reading-button--secondary" href="<?= h(pr_url('paid_reading_return', ['order' => $order['order_no']])) ?>">刷新订单状态</a>
      </div>
      <?php if ((string)$order['buyer_email'] !== ''): ?>
      <p class="paid-reading-checkout__hint">购买邮箱：<?= h((string)$order['buyer_email']) ?>。换设备后，通过此邮箱验证即可恢复阅读。</p>
      <?php else: ?>
      <p class="paid-reading-checkout__hint">这笔订单尚未绑定邮箱，请在当前浏览器验证邮箱，保留跨设备找回方式。</p>
      <?php endif; ?>
      <a class="paid-reading-reader-link" href="<?= h(pr_reader_url((int)$order['post_id'])) ?>"><?= (string)$order['buyer_email'] === '' ? '绑定购买邮箱' : '查看我的已购文章' ?></a>
      <small class="paid-reading-checkout__hint">订单问题请向站点管理员提供订单号。</small>
    </section>
    <?php
    render_layout('付费阅读订单', (string)ob_get_clean(), ['description' => '付费阅读订单', 'robots' => 'noindex,nofollow']);
}

function pr_public_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    if (!in_array($action, ['paid_reading_create', 'paid_reading_notify', 'paid_reading_return', 'paid_reading_status'], true)) {
        return;
    }
    pr_no_store();
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, noarchive');
    }
    try {
        if ($action === 'paid_reading_notify') {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                pr_error('Method not allowed.', 405);
            }
            $channel = pr_scalar($_GET['channel'] ?? '');
            if (!in_array($channel, ['alipay', 'wechat'], true)) {
                pr_error('Invalid payment channel.', 400);
            }
            $payment = pr_payment_callback($channel, pr_settings());
            if (!pr_settle_order($channel, $payment)) {
                pr_error('Payment validation failed.', 400);
            }
            pr_payment_success($channel);
            exit;
        }
        if ($action === 'paid_reading_create') {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
                pr_error('请从文章页面创建订单。', 405);
            }
            verify_csrf();
            $id = pr_scalar($_POST['post_id'] ?? '');
            $post = preg_match('/^[1-9][0-9]{0,9}$/D', $id) ? one('SELECT * FROM posts WHERE id = ?', [(int)$id]) : null;
            $record = $post ? pr_content_for_post((int)$post['id']) : null;
            if ($post === null || $record === null) {
                pr_error('付费文章不存在。', 404);
            }
            if (pr_can_read((int)$post['id'])) {
                redirect_to(content_permalink($post), 303);
            }
            if (pr_verified_email() === '') {
                set_flash('error', '请先验证购买邮箱，以便以后换设备继续阅读。');
                redirect_to(pr_reader_url((int)$post['id']), 303);
            }
            if (!pr_recovery_mail_ready()) {
                throw new DomainException('站点邮箱服务暂未就绪，暂时无法创建新购买。');
            }
            $order = pr_create_order($post, $record, pr_scalar($_POST['channel'] ?? ''));
            $result = pr_payment_create($order, pr_settings());
            if (($result['kind'] ?? '') === 'redirect') {
                redirect_to((string)$result['url'], 303);
            }
            if (($result['kind'] ?? '') !== 'html' || !is_string($result['html'] ?? null)) {
                throw new RuntimeException('Invalid gateway response.');
            }
            header('Content-Type: text/html; charset=UTF-8');
            echo $result['html'];
            exit;
        }
        $order = pr_owned_order(pr_scalar($_GET['order'] ?? ''));
        if ($order === null) {
            pr_error('订单不存在，或当前浏览器没有查看权限。', 404);
        }
        // A browser return is only a receipt page. Only the verified asynchronous
        // notification can transition pending -> paid.
        if ($action === 'paid_reading_status') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => pr_order_state($order)], JSON_THROW_ON_ERROR);
            exit;
        }
        pr_render_return($order);
        exit;
    } catch (DomainException $exception) {
        pr_error($exception->getMessage(), 422);
    } catch (Throwable $exception) {
        // Gateways can include credential-bearing request dumps in exceptions.
        error_log('Paid reading request failed (' . get_class($exception) . ').');
        pr_error($action === 'paid_reading_notify' ? 'Payment verification unavailable.' : '支付暂时不可用，请稍后重试或联系站点管理员。', 503);
    }
}

function pr_assets(string $html, array $context): string
{
    if (!str_contains($html, '</head>') || (!str_contains($html, 'paid-reading-')
        && !in_array((string)($context['action'] ?? ''), ['admin_paid_reading', 'write', 'edit'], true))) {
        return $html;
    }
    $css = plugin_asset_url('paid-reading', 'assets/paid-reading.css');
    $js = plugin_asset_url('paid-reading', 'assets/paid-reading.js');
    $assets = $css !== '' ? '<link rel="stylesheet" href="' . h($css . '?v=' . SBLOG_PAID_READING_VERSION) . '">' : '';
    if ($js !== '' && str_contains($html, 'data-paid-reading-status=')) {
        $assets .= '<script defer src="' . h($js . '?v=' . SBLOG_PAID_READING_VERSION) . '"></script>';
    }
    return str_replace('</head>', $assets . "\n</head>", $html);
}
