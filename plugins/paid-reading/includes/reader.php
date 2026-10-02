<?php
declare(strict_types=1);

function pr_reader_url(int $postId = 0, array $params = []): string
{
    if ($postId > 0) {
        $params = ['post_id' => $postId] + $params;
    }
    return pr_url('paid_reading_reader', $params);
}

/** Keep article destinations local and derived from published content. */
function pr_reader_post_id(mixed $value): int
{
    $id = pr_scalar($value);
    return preg_match('/^[1-9][0-9]{0,9}$/D', $id) ? (int)$id : 0;
}

function pr_reader_challenge(string $id): ?array
{
    $challenge = pr_email_challenge($id);
    return $challenge !== null && $challenge['consumed_at'] === null
        && (int)$challenge['expires_at'] > time() && (int)$challenge['attempts'] < 5
        ? $challenge : null;
}

function pr_render_reader(int $postId = 0, string $challengeId = ''): void
{
    $email = pr_verified_email();
    $challenge = $challengeId !== '' ? pr_reader_challenge($challengeId) : null;
    $mailReady = pr_recovery_mail_ready();
    $purchases = pr_reader_purchases();
    $post = $postId > 0 ? one('SELECT * FROM posts WHERE id = ?', [$postId]) : null;
    $postUrl = $post !== null && is_live_content($post) ? content_permalink($post) : '';
    $canRead = $postUrl !== '' && pr_can_read($postId);
    ob_start(); ?>
    <section class="paid-reading-page paid-reading-reader" aria-labelledby="paid-reading-reader-title">
      <div class="paid-reading-reader__card">
        <span class="paid-reading-wall__badge">付费阅读</span>
        <h1 id="paid-reading-reader-title"><?= $email !== '' ? '我的已购文章' : '验证邮箱，找回已购文章' ?></h1>
        <?php if ($email !== ''): ?>
          <p class="paid-reading-reader__identity">已验证邮箱：<strong><?= h($email) ?></strong></p>
          <p class="paid-reading-reader__hint">换设备或清除浏览器数据后，用这个邮箱验证即可恢复已购文章。</p>
        <?php else: ?>
          <p class="paid-reading-reader__hint">购买前验证邮箱，购买记录会保存到这个邮箱。以后在其他设备验证同一邮箱，就能再次阅读。</p>
          <?php if ($purchases): ?><p class="paid-reading-reader__notice">在当前浏览器验证邮箱，可将以前购买的文章绑定到邮箱。</p><?php endif; ?>
        <?php endif; ?>
        <?php if (!$mailReady): ?><p class="paid-reading-reader__notice" role="status">邮件服务暂时不可用，暂时无法获取新验证码。请稍后再试或联系站点管理员。</p><?php endif; ?>

        <?php if ($challenge !== null): ?>
          <form class="paid-reading-reader__form" method="post" action="<?= h(pr_url('paid_reading_verify_email')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="challenge" value="<?= h($challengeId) ?>">
            <?php if ($postId > 0): ?><input type="hidden" name="post_id" value="<?= $postId ?>"><?php endif; ?>
            <p class="paid-reading-reader__hint">验证码已发送至 <strong><?= h((string)$challenge['email']) ?></strong>，10 分钟内有效。</p>
            <label for="paid-reading-code">邮箱验证码</label>
            <input id="paid-reading-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" minlength="8" maxlength="8" placeholder="8 位数字" aria-describedby="paid-reading-code-hint" required autofocus>
            <small id="paid-reading-code-hint" class="paid-reading-reader__hint">没收到邮件时，请检查垃圾邮件文件夹。</small>
            <button class="paid-reading-button" type="submit">验证并恢复阅读</button>
            <a class="paid-reading-reader__link" href="<?= h(pr_reader_url($postId)) ?>">重新发送或更换邮箱</a>
          </form>
        <?php else: ?>
          <?php if ($challengeId !== ''): ?><p class="paid-reading-reader__notice" role="status">验证码已失效，请重新获取。</p><?php endif; ?>
          <?php if ($email !== ''): ?><details class="paid-reading-reader__switch"><summary>验证其他邮箱</summary><?php endif; ?>
          <form class="paid-reading-reader__form" method="post" action="<?= h(pr_url('paid_reading_send_code')) ?>">
            <?= csrf_field() ?>
            <?php if ($postId > 0): ?><input type="hidden" name="post_id" value="<?= $postId ?>"><?php endif; ?>
            <label for="paid-reading-email">购买使用的邮箱</label>
            <input id="paid-reading-email" name="email" type="email" autocomplete="email" maxlength="254" placeholder="you@example.com" required>
            <button class="paid-reading-button" type="submit"<?= !$mailReady ? ' disabled' : '' ?>>发送验证码</button>
          </form>
          <?php if ($email !== ''): ?></details><?php endif; ?>
        <?php endif; ?>
        <?php if ($email !== ''): ?>
          <form class="paid-reading-reader__forget" method="post" action="<?= h(pr_url('paid_reading_forget_reader')) ?>">
            <?= csrf_field() ?>
            <?php if ($postId > 0): ?><input type="hidden" name="post_id" value="<?= $postId ?>"><?php endif; ?>
            <p class="paid-reading-reader__hint">使用公共设备时，可退出本设备。已购文章仍保存在邮箱中。</p>
            <button class="paid-reading-button paid-reading-button--secondary" type="submit">退出本设备</button>
          </form>
        <?php endif; ?>

        <?php if ($postUrl !== ''): ?>
          <div class="paid-reading-reader__article">
            <p class="paid-reading-reader__hint"><?= h((string)$post['title']) ?></p>
            <a class="paid-reading-button <?= $email === '' ? 'paid-reading-button--secondary' : '' ?>" href="<?= h($postUrl) ?>"><?= $canRead ? '继续阅读' : ($email !== '' ? '返回文章购买' : '返回文章') ?></a>
          </div>
        <?php endif; ?>
      </div>

      <div class="paid-reading-reader__card">
        <h2>已购文章</h2>
        <?php if ($purchases): ?>
          <ul class="paid-reading-reader__purchases">
            <?php foreach ($purchases as $purchase): ?>
              <li>
                <div>
                  <?php if ((string)$purchase['url'] !== ''): ?><a class="paid-reading-reader__title" href="<?= h((string)$purchase['url']) ?>"><?= h((string)$purchase['title']) ?></a><?php else: ?><span class="paid-reading-reader__title"><?= h((string)$purchase['title']) ?></span><?php endif; ?>
                  <p class="paid-reading-reader__hint">订单号：<?= h((string)$purchase['order_no']) ?></p>
                </div>
                <?php if ((string)$purchase['url'] !== ''): ?><a class="paid-reading-button paid-reading-button--secondary" href="<?= h((string)$purchase['url']) ?>">阅读文章</a><?php else: ?><span class="paid-reading-reader__hint">文章暂不可用</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="paid-reading-reader__empty" role="status"><?= $email !== '' ? ($postUrl !== '' ? '邮箱已验证，可以返回文章购买。' : '此邮箱暂时没有已购文章。') : '验证购买时使用的邮箱后，这里会显示已购文章。' ?></p>
        <?php endif; ?>
      </div>
    </section>
    <?php
    render_layout('我的已购文章', (string)ob_get_clean(), ['description' => '验证邮箱并恢复已购文章阅读权限', 'robots' => 'noindex,nofollow']);
}

function pr_reader_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    if (!in_array($action, ['paid_reading_reader', 'paid_reading_send_code', 'paid_reading_verify_email', 'paid_reading_forget_reader'], true)) {
        return;
    }
    pr_no_store();
    if (!headers_sent()) {
        header('X-Robots-Tag: noindex, noarchive');
        header('Referrer-Policy: no-referrer');
    }
    $postId = pr_reader_post_id($action === 'paid_reading_reader' ? ($_GET['post_id'] ?? '') : ($_POST['post_id'] ?? ''));
    $challengeId = '';
    try {
        if ($action === 'paid_reading_reader') {
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
                pr_error('请使用浏览器打开已购文章页面。', 405);
            }
            pr_buyer_hash(true);
            pr_render_reader($postId, pr_scalar($_GET['challenge'] ?? ''));
            exit;
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            pr_error('请从邮箱验证页面提交。', 405);
        }
        verify_csrf();
        if ($action === 'paid_reading_forget_reader') {
            pr_forget_reader();
            set_flash('success', '已退出本设备。再次验证购买邮箱即可恢复阅读。');
            redirect_to(pr_reader_url($postId), 303);
        }
        pr_buyer_hash(true);
        if ($action === 'paid_reading_send_code') {
            $challengeId = pr_request_email_code(pr_scalar($_POST['email'] ?? ''));
            set_flash('success', '验证码已发送，请查看邮箱。');
            redirect_to(pr_reader_url($postId, ['challenge' => $challengeId]), 303);
        }
        $challengeId = pr_scalar($_POST['challenge'] ?? '');
        pr_verify_email_code($challengeId, pr_scalar($_POST['code'] ?? ''));
        set_flash('success', '邮箱验证成功，已恢复可用的购买记录。');
        redirect_to(pr_reader_url($postId), 303);
    } catch (DomainException $exception) {
        set_flash('error', $exception->getMessage());
        $params = $challengeId !== '' && pr_reader_challenge($challengeId) !== null ? ['challenge' => $challengeId] : [];
        redirect_to(pr_reader_url($postId, $params), 303);
    } catch (Throwable $exception) {
        error_log('Paid reading email request failed (' . get_class($exception) . ').');
        pr_error('邮箱验证暂时不可用，请稍后重试或联系站点管理员。', 503);
    }
}
