<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

function sblog_lottery_admin_input(string $name): string
{
    $value = $_POST[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

function sblog_lottery_draw_message(string $reason): string
{
    return match ($reason) {
        'drawn' => '开奖完成，中奖名单已经固定并在文章中公示。',
        'already_drawn' => '此活动已经开奖，中奖名单不会再次抽取。',
        'not_due' => '活动尚未结束，请在结束时间之后开奖。',
        'not_found' => '活动不存在，请刷新页面后重试。',
        'inactive' => '活动未处于可开奖状态。请确认所属文章已公开发布、已经到达文章发布时间，并保存了对应抽奖模块。',
        default => '暂时无法开奖，请确认文章已发布、抽奖模块已保存且活动已经结束。',
    };
}

function sblog_lottery_admin_status(array $lottery): string
{
    if ((string)$lottery['status'] === 'drawn') {
        return '已开奖';
    }
    if (empty($lottery['enabled'])) {
        return '模块已移除，暂停开奖';
    }
    if ((string)($lottery['post_status'] ?? '') !== 'published' || (int)($lottery['post_published_at'] ?? 0) < 1
        || (int)$lottery['post_published_at'] > time()) {
        return '等待文章发布';
    }
    $now = time();
    if ($now < (int)$lottery['starts_at']) {
        return '未开始';
    }
    if ($now < (int)$lottery['ends_at']) {
        return '进行中';
    }
    return !empty($lottery['auto_draw']) ? '已结束，等待自动开奖' : '已结束，等待手动开奖';
}

function sblog_lottery_mail_label(string $status): string
{
    return match ($status) {
        'sent' => '邮件发送接口已接受',
        'sending' => '发送中／结果待确认',
        'failed' => '发送失败',
        'skipped' => '未启用邮件通知',
        default => '等待发送',
    };
}

function sblog_lottery_shell_quote(string $value): string
{
    return "'" . str_replace("'", "'\\''", $value) . "'";
}

function sblog_lottery_render_admin(array $errors = []): void
{
    require_admin();
    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
    }
    $settings = sblog_lottery_settings();
    $lotteries = sblog_lottery_list();
    $selectedId = is_string($_GET['id'] ?? null) ? trim($_GET['id']) : '';
    $selected = $selectedId !== '' ? sblog_lottery_get($selectedId) : null;
    if ($selected !== null && (int)$selected['post_id'] > 0) {
        foreach ($lotteries as $lottery) {
            if ((string)$lottery['id'] === $selectedId) {
                $selected = array_merge($selected, $lottery);
                break;
            }
        }
        $selectedPost = one('SELECT title, slug, status, published_at FROM posts WHERE id = ?', [(int)$selected['post_id']]);
        if ($selectedPost) {
            $selected['post_title'] = $selectedPost['title'];
            $selected['post_slug'] = $selectedPost['slug'];
            $selected['post_status'] = $selectedPost['status'];
            $selected['post_published_at'] = $selectedPost['published_at'];
        } else {
            $selected = null;
        }
    } else {
        $selected = null;
    }
    $mailReady = plugin_callbacks('sblog_plugin_filters', 'site_mail_send') !== [];
    $mailInstalled = is_file(PLUGINS_DIR . '/email-notifications/plugin.php');
    $workerUrl = absolute_url(sblog_lottery_url('lottery_tick'));
    $workerKey = (string)$settings['cron_key'];
    $linuxCommand = '* * * * * curl --fail --silent --show-error -X POST -H '
        . sblog_lottery_shell_quote('X-SBlog-Lottery-Key: ' . $workerKey) . ' '
        . sblog_lottery_shell_quote($workerUrl);
    $scriptPath = str_replace('/', DIRECTORY_SEPARATOR, PLUGINS_DIR . '/comment-lottery/cron-example.ps1');
    $windowsCommand = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' . $scriptPath
        . '" -WorkerUrl "' . $workerUrl . '" -WorkerKey "' . $workerKey . '"';
    $lastRunSource = match ((string)$settings['last_run_source']) {
        'cron', 'worker' => '定时任务',
        'visit', 'request', 'traffic' => '站点访问',
        'manual', 'admin' => '后台操作',
        default => (string)$settings['last_run_source'] ?: '尚未运行',
    };
    ob_start();
    ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main">
        <?= render_admin_topbar('评论抽奖', '撰写文章', url_for('write')) ?>
        <?php if ($errors): ?><div class="flash flash--error" role="alert"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div><?php endif; ?>
        <section class="panel admin-list-panel">
          <div class="panel__header"><h2>抽奖活动</h2><p class="panel__meta">在文章编辑器中点击「评论抽奖」，填写活动信息并插入模块，保存后即可在这里管理。所有活动时间均为北京时间（Asia/Shanghai）。</p></div>
          <div class="panel__body">
            <?php if ($lotteries): ?>
              <div class="table-wrap"><table class="admin-table">
                <thead><tr><th>活动 / 文章</th><th>起止时间</th><th>名额 / 规则</th><th>状态</th><th>操作</th></tr></thead>
                <tbody><?php foreach ($lotteries as $lottery): ?>
                  <tr>
                    <td><a href="<?= h(sblog_lottery_url('admin_lottery', ['id' => (string)$lottery['id']])) ?>"><?= h((string)$lottery['title']) ?></a><br><small><a href="<?= h(url_for('edit', ['id' => (int)$lottery['post_id']])) ?>"><?= h((string)$lottery['post_title']) ?></a></small></td>
                    <td><?= h(sblog_lottery_date((int)$lottery['starts_at'])) ?><br><?= h(sblog_lottery_date((int)$lottery['ends_at'])) ?></td>
                    <td><?= h((string)$lottery['winners_count']) ?> 条<br><small><?= !empty($lottery['unique_email']) ? '每个邮箱一次机会' : '每条评论一次机会' ?><?= !empty($lottery['roots_only']) ? ' · 仅顶层评论' : '' ?></small></td>
                    <td><?= h(sblog_lottery_admin_status($lottery)) ?><br><small><?= !empty($lottery['auto_draw']) ? '自动开奖' : '手动开奖' ?> · <?= !empty($lottery['notify']) ? '邮件通知' : '不发邮件' ?></small></td>
                    <td><a class="button button--secondary" href="<?= h(sblog_lottery_url('admin_lottery', ['id' => (string)$lottery['id']])) ?>">查看活动</a></td>
                  </tr>
                <?php endforeach; ?></tbody>
              </table></div>
              <p class="field-hint">显示最近 100 个已绑定文章的活动。未保存到文章的模块不会进入自动开奖。</p>
            <?php else: ?><p class="field-hint">暂无已保存的抽奖活动。前往文章编辑器，点击正文标签旁的「评论抽奖」按钮创建活动。</p><?php endif; ?>
          </div>
        </section>
        <?php if ($selected):
            $winners = sblog_lottery_winners((string)$selected['id']);
            $isDrawn = (string)$selected['status'] === 'drawn';
            $canDraw = !$isDrawn && !empty($selected['enabled'])
                && (string)($selected['post_status'] ?? '') === 'published'
                && (int)($selected['post_published_at'] ?? 0) > 0
                && (int)$selected['post_published_at'] <= time() && time() >= (int)$selected['ends_at'];
            $ambiguousMail = false;
            $retryableMail = false;
            foreach ($winners as $winner) {
                $ambiguousMail = $ambiguousMail || (string)$winner['mail_status'] === 'sending';
                $retryableMail = $retryableMail || in_array((string)$winner['mail_status'], ['pending', 'failed', 'sending'], true);
            }
        ?>
          <section class="panel admin-list-panel">
            <div class="panel__header"><h2><?= h((string)$selected['title']) ?></h2><p class="panel__meta"><?= h(sblog_lottery_admin_status($selected)) ?> · <a href="<?= h(url_for('edit', ['id' => (int)$selected['post_id']])) ?>">编辑所属文章</a></p></div>
            <div class="panel__body form-stack">
              <?php if ((string)$selected['description'] !== ''): ?><p><?= nl2br(h((string)$selected['description'])) ?></p><?php endif; ?>
              <p>活动时间：<?= h(sblog_lottery_date((int)$selected['starts_at'])) ?> 至 <?= h(sblog_lottery_date((int)$selected['ends_at'])) ?>。结束时间当刻及之后提交的评论不参与。</p>
              <p class="field-hint">只抽取开奖时已经审核通过、活动期间发布且邮箱有效的访客评论，管理员评论不参与。<?= !empty($selected['unique_email']) ? '同一邮箱仅保留最早一条符合条件的评论。' : '同一邮箱的多条评论可分别参与，也可能多次中奖。' ?><?= !empty($selected['roots_only']) ? '仅顶层评论参与。' : '顶层评论和回复均可参与。' ?></p>
              <?php if ($isDrawn): ?>
                <p>开奖时间：<?= h(sblog_lottery_date((int)$selected['drawn_at'])) ?> · 符合条件的候选评论：<?= h((string)$selected['candidate_count']) ?> 条 · 实际中奖：<?= count($winners) ?> 条。</p>
                <p class="field-hint">中奖名单已经固定。候选数不足时按实际人数开奖，没有候选评论时活动结束且没有中奖者。</p>
                <?php if ($winners): ?>
                  <div class="table-wrap"><table class="admin-table">
                    <thead><tr><th>中奖评论</th><th>中奖人 / 邮箱</th><th>邮件状态</th></tr></thead>
                    <tbody><?php foreach ($winners as $winner): ?>
                      <tr><td>#<?= h((string)$winner['comment_id']) ?><br><?= nl2br(h((string)$winner['content'])) ?></td><td><?= h((string)$winner['author_name']) ?><br><small><?= h((string)$winner['author_email']) ?></small></td><td><?= h(sblog_lottery_mail_label((string)$winner['mail_status'])) ?><br><small>已尝试 <?= h((string)$winner['mail_attempts']) ?> 次</small><?php if ((string)$winner['mail_error'] !== ''): ?><br><small><?= h((string)$winner['mail_error']) ?></small><?php endif; ?></td></tr>
                    <?php endforeach; ?></tbody>
                  </table></div>
                  <p class="field-hint">「邮件发送接口已接受」表示 SMTP 或 PHP mail 返回成功，实际投递请结合邮件服务商日志确认。发送中断的通知至少等待 10 分钟，确认原请求停止后才能手动重试。</p>
                  <?php if (!empty($selected['notify']) && $retryableMail): ?>
                    <form class="form-stack" method="post" action="<?= h(sblog_lottery_url('admin_lottery', ['id' => (string)$selected['id']])) ?>">
                      <?= csrf_field() ?><input type="hidden" name="operation" value="retry_mail"><input type="hidden" name="id" value="<?= h((string)$selected['id']) ?>">
                      <?php if ($ambiguousMail): ?><label class="setting-option"><input name="confirm_ambiguous" type="checkbox" value="1" required><span>我已确认原发送请求不再运行，并已检查邮件服务商日志；重试可能造成重复邮件。</span></label><?php endif; ?>
                      <div class="form-actions"><button class="button button--secondary" type="submit"<?= $mailReady ? '' : ' disabled' ?>>重试未成功的中奖通知</button></div>
                    </form>
                  <?php endif; ?>
                <?php else: ?><p>本次没有中奖评论。</p><?php endif; ?>
              <?php else: ?>
                <form method="post" action="<?= h(sblog_lottery_url('admin_lottery', ['id' => (string)$selected['id']])) ?>">
                  <?= csrf_field() ?><input type="hidden" name="operation" value="draw"><input type="hidden" name="id" value="<?= h((string)$selected['id']) ?>">
                  <button class="button" type="submit"<?= $canDraw ? '' : ' disabled' ?>>立即开奖</button>
                </form>
              <p class="field-hint">结束时间之后才能手动开奖。开奖后不能修改活动规则或重新抽取；需要举办新活动时请创建新的模块。</p>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
        <section class="panel admin-list-panel">
          <div class="panel__header"><h2>中奖邮件通知</h2></div>
          <div class="panel__body form-stack">
            <?php if ($mailReady): ?><p>已检测到站点邮件发送接口。中奖通知使用中奖评论留下的邮箱。</p>
            <?php elseif ($mailInstalled): ?><p class="flash flash--error">邮件通知插件尚未启用或未能加载，请先在插件管理中启用。</p>
            <?php else: ?><p class="flash flash--error">当前未安装邮件通知插件。请从扩展商店安装「邮件通知」，然后在插件管理中启用并设置 SMTP。</p><?php endif; ?>
            <p class="field-hint">在「邮件通知」设置中启用 SMTP，填写主机、端口、加密方式、账号、授权码及发件邮箱。常见配置是 SSL / 465 或 TLS / 587，以服务商要求为准。「通知收件邮箱」用于管理员的新评论提醒，不影响中奖邮件的收件人。</p>
            <p class="field-hint">邮件失败不会改变中奖名单。定时任务或站点访问会每隔至少 5 分钟重试失败邮件，最多自动尝试 5 次；超过限制可在活动详情中手动重试。发送过程中断造成的状态不确定，需要等待至少 10 分钟并先确认再手动重试。</p>
            <div class="form-actions"><a class="button button--secondary" href="<?= h(url_for('admin_plugins')) ?>">插件管理</a><?php if ($mailReady && $mailInstalled): ?><a class="button button--secondary" href="<?= h(url_for('admin_mail')) ?>">邮件设置</a><?php endif; ?></div>
          </div>
        </section>
        <section class="panel admin-list-panel">
          <div class="panel__header"><h2>到期自动开奖</h2><p class="panel__meta">启用活动的「自动开奖」，再设置每分钟运行一次的服务器任务，即可在没有访客时到期处理。</p></div>
          <div class="panel__body form-stack">
            <p>最近运行：<?= (int)$settings['last_run_at'] > 0 ? h(sblog_lottery_date((int)$settings['last_run_at'])) : '尚未运行' ?> · <?= h($lastRunSource) ?>。</p>
            <p class="field-hint">先在站点设置中填写实际可访问的站点地址，确认下方地址不是临时域名或 localhost。任务成功运行后的延迟通常不超过一个执行周期；PHP 不会常驻等待结束时间。</p>
            <div class="field"><label for="lottery-worker-url">任务地址（POST）</label><input id="lottery-worker-url" type="url" value="<?= h($workerUrl) ?>" readonly></div>
            <div class="field"><label for="lottery-worker-key">任务密钥</label><input id="lottery-worker-key" type="text" value="<?= h($workerKey) ?>" readonly autocomplete="off" spellcheck="false"><p class="field-hint">仅通过请求头 X-SBlog-Lottery-Key 传递密钥，不要放进 URL。请妥善保管服务器任务配置。</p></div>
            <div class="field"><label for="lottery-linux-cron">Linux / 宝塔计划任务</label><textarea id="lottery-linux-cron" rows="3" readonly spellcheck="false"><?= h($linuxCommand) ?></textarea><p class="field-hint">Linux 使用 crontab -e 添加整行。宝塔选择「Shell 脚本」、每分钟执行，脚本内容使用上面去掉开头五个星号后的 curl 命令。</p></div>
            <div class="field"><label for="lottery-windows-task">Windows 任务计划程序</label><textarea id="lottery-windows-task" rows="4" readonly spellcheck="false"><?= h($windowsCommand) ?></textarea><p class="field-hint">程序填 powershell.exe；参数填上面从 -NoProfile 开始的部分。每日触发，勾选每 1 分钟重复、持续 1 天，并选择「不启动新实例」。任务脚本需要在这台 Windows 主机上可用；这里仅提供命令，不会创建系统任务。</p></div>
            <p class="field-hint">每次最多处理 20 个到期活动及 20 封邮件，积压会在后续运行中继续处理。即使没有配置服务器任务，站点访问仍会补做已到期的自动开奖；没有访问时会延迟。未开启自动开奖的活动只接受后台手动开奖。</p>
            <form method="post" action="<?= h(sblog_lottery_url('admin_lottery')) ?>">
              <?= csrf_field() ?><input type="hidden" name="operation" value="rotate_key"><button class="button button--secondary" type="submit">更换任务密钥</button>
              <p class="field-hint">更换后旧密钥立即失效，请同步更新服务器任务中的密钥。</p>
            </form>
          </div>
        </section>
      </div>
    </div>
    <?php
    render_layout('评论抽奖', (string)ob_get_clean(), ['active' => 'plugins', 'wide' => true]);
}

function sblog_lottery_admin_request(): void
{
    require_admin();
    sblog_lottery_init();
    $errors = [];
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        sblog_lottery_tick();
    }
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        verify_csrf();
        $operation = sblog_lottery_admin_input('operation');
        $id = sblog_lottery_admin_input('id');
        $returnUrl = sblog_lottery_url('admin_lottery', $id !== '' ? ['id' => $id] : []);
        try {
            if ($operation === 'rotate_key') {
                sblog_lottery_rotate_key();
                set_flash('success', '任务密钥已更换，请更新服务器计划任务中的密钥。');
                redirect_to(sblog_lottery_url('admin_lottery'), 303);
            } elseif ($operation === 'draw') {
                $result = sblog_lottery_draw($id);
                if (!empty($result['drawn'])) {
                    sblog_lottery_notify($id, 20);
                }
                $message = sblog_lottery_draw_message((string)$result['reason']);
                if (!empty($result['drawn']) && (int)(sblog_lottery_get($id)['candidate_count'] ?? 0) === 0) {
                    $message = '活动已结束，没有符合条件的评论，未产生中奖者。';
                }
                set_flash(!empty($result['drawn']) ? 'success' : 'error', $message);
                redirect_to($returnUrl, 303);
            } elseif ($operation === 'retry_mail') {
                $lottery = $id !== '' ? sblog_lottery_get($id) : null;
                if (!$lottery || (int)$lottery['post_id'] < 1 || (string)$lottery['status'] !== 'drawn' || empty($lottery['notify'])) {
                    $errors[] = '请选择已开奖且启用了邮件通知的活动。';
                } elseif (plugin_callbacks('sblog_plugin_filters', 'site_mail_send') === []) {
                    $errors[] = '站点邮件发送接口尚未启用，请先安装并启用邮件通知插件。';
                } else {
                    $ambiguous = false;
                    foreach (sblog_lottery_winners($id) as $winner) {
                        $ambiguous = $ambiguous || (string)$winner['mail_status'] === 'sending';
                    }
                    if ($ambiguous && sblog_lottery_admin_input('confirm_ambiguous') !== '1') {
                        $errors[] = '请先确认原发送请求已停止并检查邮件服务商日志，再勾选确认后重试。';
                    } else {
                        $sent = sblog_lottery_notify($id, 20, true);
                        set_flash($sent > 0 ? 'success' : 'error', $sent > 0
                            ? '邮件发送接口已接受 ' . $sent . ' 封中奖通知。请查看活动详情中的邮件状态。'
                            : '本次没有邮件被发送接口接受，请查看活动详情和邮件配置；正在发送的邮件会继续保留原状态。');
                        redirect_to($returnUrl, 303);
                    }
                }
            } else {
                $errors[] = '无效的抽奖管理操作。';
            }
        } catch (Throwable $exception) {
            error_log('Comment lottery admin operation failed: ' . $exception->getMessage());
            $errors[] = '操作未能完成，请刷新查看活动状态并检查服务器日志。已经保存的中奖名单不会重新抽取。';
        }
    }
    sblog_lottery_render_admin($errors);
}
