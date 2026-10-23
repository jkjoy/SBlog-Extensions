<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) { http_response_code(403); exit; }

function sblog_push_admin(array $config, array $errors = []): void
{
    require_admin();
    header('Cache-Control: no-store');
    $weixin = sblog_push_weixin_status();
    $qqWebhook = sblog_push_qq_webhook_status();
    $qqBinding = is_array($qqWebhook['binding'] ?? null) ? $qqWebhook['binding'] : [];
    $qqSavedType = sblog_push_config()['qq_target_type'];
    $qqCanBind = !empty($qqWebhook['available']) && !empty($qqWebhook['configured']) && in_array($qqSavedType, ['c2c', 'group'], true);
    $qrUrl = (string)($weixin['qrcode_url'] ?? '');
    $candidates = is_array($weixin['candidates'] ?? null) ? $weixin['candidates'] : [];
    $results = ['qq' => sblog_push_read('result_qq'), 'weixin' => sblog_push_read('result_weixin')];
    ob_start();
    ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main">
        <?= render_admin_topbar('QQ / 微信评论推送', '插件管理', url_for('admin_plugins')) ?>
        <?php if ($errors): ?>
          <div class="flash flash--error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <section class="panel admin-list-panel">
          <div class="panel__header"><h2>通知设置</h2><p class="panel__meta">新评论提交后，通过已启用的渠道通知站长。</p></div>
          <div class="panel__body">
            <form class="form-stack" method="post" action="<?= h(sblog_push_admin_url()) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="operation" value="save">
              <fieldset class="field settings-field">
                <legend>官方 QQ 机器人</legend>
                <label class="setting-option"><input name="qq_enabled" type="checkbox" value="1"<?= $config['qq_enabled'] ? ' checked' : '' ?>><span>启用 QQ 评论通知</span></label>
                <p class="field-hint">在 <a href="https://q.qq.com/" target="_blank" rel="noopener noreferrer">QQ 开放平台</a>创建机器人，填写正式环境凭证和接收 ID。</p>
              </fieldset>
              <div class="field">
                <label for="push-qq-app-id">QQ AppID</label>
                <input id="push-qq-app-id" name="qq_app_id" inputmode="numeric" maxlength="32" pattern="[0-9]{1,32}" value="<?= h($config['qq_app_id']) ?>" autocomplete="off">
              </div>
              <div class="field">
                <label for="push-qq-secret">QQ AppSecret</label>
                <input id="push-qq-secret" name="qq_app_secret" type="password" maxlength="256" autocomplete="new-password" value="" placeholder="<?= $config['qq_app_secret'] !== '' ? '已保存；留空保留现有密钥' : '填写机器人 AppSecret' ?>">
                <?php if ($config['qq_app_secret'] !== ''): ?><label class="setting-option"><input name="clear_qq_app_secret" type="checkbox" value="1"><span>清除已保存的 AppSecret</span></label><?php endif; ?>
              </div>
              <div class="field">
                <label for="push-qq-target-type">QQ 接收类型</label>
                <select id="push-qq-target-type" name="qq_target_type">
                  <?php foreach (['c2c' => '私聊（用户 OpenID）', 'group' => 'QQ群（群 OpenID）', 'channel' => 'QQ频道（子频道 ID）'] as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $config['qq_target_type'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="push-qq-target-id">QQ 接收 ID</label>
                <input id="push-qq-target-id" name="qq_target_id" maxlength="128" pattern="[A-Za-z0-9_-]{1,128}" value="<?= h($config['qq_target_id']) ?>" autocomplete="off" aria-describedby="push-qq-target-hint">
                <p class="field-hint" id="push-qq-target-hint">私聊填写机器人事件中的用户 OpenID，群聊填写群 OpenID；可在下方通过官方 Webhook 绑定，不能直接填写 QQ 号或群号。子频道填写频道接口返回的子频道 ID。</p>
                <p class="field-hint">接收人须先添加机器人好友，或将机器人加入目标群，并允许主动消息。平台会限制主动消息权限和频率；频道推送还需其他程序维持机器人 WebSocket 在线。</p>
              </div>
              <fieldset class="field settings-field">
                <legend>微信</legend>
                <label class="setting-option"><input name="weixin_enabled" type="checkbox" value="1"<?= $config['weixin_enabled'] ? ' checked' : '' ?>><span>启用微信评论通知</span></label>
                <p class="field-hint">使用从 OpenClaw 微信通道移植的 iLink 接口。请在下方扫码登录，再向微信中的机器人发送一条消息并同步接收人。</p>
              </fieldset>
              <div class="field">
                <label for="push-weixin-user">微信接收人</label>
                <input id="push-weixin-user" type="text" value="<?= h($config['weixin_user_id']) ?>" readonly placeholder="尚未绑定" aria-describedby="push-weixin-user-hint">
                <p class="field-hint" id="push-weixin-user-hint">接收人从微信消息同步并绑定。会话上下文过期时，请重新发消息并同步。</p>
              </div>
              <fieldset class="field settings-field">
                <legend>推送内容</legend>
                <div class="settings-option-list">
                  <?php foreach (['notify_pending' => '通知待审核的新评论', 'notify_approved' => '通知直接通过审核的新评论', 'notify_admin' => '同时通知管理员自己发表的评论', 'include_link' => '在通知中附上文章链接'] as $field => $label): ?>
                    <label class="setting-option"><input name="<?= h($field) ?>" type="checkbox" value="1"<?= $config[$field] ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
                  <?php endforeach; ?>
                </div>
                <p class="field-hint">QQ 通知中的文章域名可能需要在开放平台配置白名单；若平台拒绝链接，可取消附加链接后重试。</p>
              </fieldset>
              <div class="form-actions"><button class="button" type="submit">保存设置</button><a class="button button--secondary" href="<?= h(url_for('admin_plugins')) ?>">返回插件管理</a></div>
            </form>
          </div>
        </section>
        <section class="panel">
          <div class="panel__header"><h2>QQ Webhook 与接收人绑定</h2><p class="panel__meta">接入官方消息事件后，通过一次性绑定码自动填写用户或群 OpenID。</p></div>
          <div class="panel__body form-stack">
            <div class="field">
              <label for="push-qq-webhook-url">官方事件回调地址</label>
              <input id="push-qq-webhook-url" type="text" value="<?= h($qqWebhook['url'] ?? '') ?>" readonly spellcheck="false" autocomplete="off" aria-describedby="push-qq-webhook-url-hint">
              <p class="field-hint" id="push-qq-webhook-url-hint">选中并复制此地址到 QQ 开放平台。地址须为公网可访问的 HTTPS，允许平台请求直接到达，不受登录页或防火墙验证拦截，并保持本插件启用。</p>
            </div>
            <ol>
              <li>在上方填写 AppID、AppSecret，选择「私聊」或「QQ群」并保存；尚无接收 ID 时先不勾选「启用 QQ 评论通知」。</li>
              <li>进入 <a href="https://q.qq.com/" target="_blank" rel="noopener noreferrer">QQ 开放平台</a>的机器人管理，在事件订阅中填写回调地址并完成验证；私聊订阅单聊消息事件 <code>C2C_MESSAGE_CREATE</code>，群聊订阅群 @ 消息事件 <code>GROUP_AT_MESSAGE_CREATE</code>。</li>
              <li>生成绑定码，在需要接收通知的 QQ 私聊中发送下方命令；若选择群聊，将机器人加入目标群并 @机器人发送命令。</li>
              <li>发送后刷新本页，确认上方「QQ 接收 ID」已填写，再启用 QQ 评论通知、保存并发送测试通知。</li>
            </ol>
            <?php if (empty($qqWebhook['available'])): ?>
              <p class="field-hint">当前 PHP 未启用 sodium 扩展，无法验证官方 Webhook 签名。请启用 sodium 后接入回调；手动填写接收 ID 的发送功能不依赖此扩展。</p>
            <?php elseif (empty($qqWebhook['configured'])): ?>
              <p class="field-hint">请先保存 AppID 和 AppSecret，再验证回调、生成绑定码。</p>
            <?php elseif ($qqSavedType === 'channel'): ?>
              <p class="field-hint">当前已保存的类型为 QQ 频道，请手动填写子频道 ID。自动绑定支持私聊和 QQ 群，修改类型后须先保存。</p>
            <?php endif; ?>
            <?php if ($qqBinding): ?>
              <div class="field">
                <label for="push-qq-binding-command">一次性绑定命令</label>
                <input id="push-qq-binding-command" type="text" value="<?= h('绑定通知 ' . ($qqBinding['code'] ?? '')) ?>" readonly spellcheck="false" autocomplete="off" aria-describedby="push-qq-binding-hint">
                <p class="field-hint" id="push-qq-binding-hint">绑定<?= ($qqBinding['target_type'] ?? '') === 'group' ? '目标 QQ 群（须 @机器人）' : '当前 QQ 私聊用户' ?>；有效期至 <?= h(date('Y-m-d H:i:s', (int)($qqBinding['expires_at'] ?? 0))) ?>。请仅在目标会话中发送，匹配成功后绑定码立即失效。</p>
              </div>
            <?php endif; ?>
            <div class="form-actions">
              <form method="post" action="<?= h(sblog_push_admin_url()) ?>">
                <?= csrf_field() ?><input type="hidden" name="operation" value="qq_binding_start">
                <button class="button button--secondary" type="submit"<?= $qqCanBind ? '' : ' disabled' ?>><?= $qqBinding ? '重新生成绑定码' : '生成绑定码' ?></button>
              </form>
              <?php if ($qqBinding): ?>
                <form method="post" action="<?= h(sblog_push_admin_url()) ?>">
                  <?= csrf_field() ?><input type="hidden" name="operation" value="qq_binding_cancel">
                  <button class="button button--secondary" type="submit">取消本次绑定</button>
                </form>
              <?php endif; ?>
              <a class="button button--secondary" href="<?= h(sblog_push_admin_url()) ?>">刷新绑定状态</a>
            </div>
            <p class="field-hint">回调验证：<?= !empty($qqWebhook['last_challenge_at']) ? '最近成功于 ' . h(date('Y-m-d H:i:s', (int)$qqWebhook['last_challenge_at'])) : '尚未收到成功验证' ?>；已验证消息事件：<?= !empty($qqWebhook['last_event_at']) ? '最近收到于 ' . h(date('Y-m-d H:i:s', (int)$qqWebhook['last_event_at'])) : '尚未收到' ?>。</p>
            <p class="field-hint">绑定码有效期 10 分钟，仅按已保存的接收类型匹配；绑定只填写接收 ID，不会自动启用通知。<a href="https://bot.q.qq.com/wiki/develop/api-v2/dev-prepare/event-emit/webhook.html" target="_blank" rel="noopener noreferrer">查看官方 Webhook 文档</a>。</p>
          </div>
        </section>
        <section class="panel">
          <div class="panel__header"><h2>微信连接</h2><p class="panel__meta">扫码登录、确认登录与同步接收人均由你手动操作。</p></div>
          <div class="panel__body form-stack">
            <p>登录状态：<strong><?= !empty($weixin['expired']) ? '登录已失效，请重新扫码' : (!empty($weixin['logged_in']) ? '已登录' : '尚未登录') ?></strong><?= !empty($weixin['logged_in']) && empty($weixin['expired']) ? '；接收会话' . (!empty($weixin['has_context']) ? '已就绪' : '尚未同步') : '' ?>。</p>
            <?php if (!empty($weixin['logged_in']) && empty($weixin['expired']) && empty($weixin['has_context'])): ?>
              <p class="field-hint">尚未取得会话上下文时仍可尝试推送，微信可能拒绝发送。若通知失败，请先给机器人发送一条消息，再同步接收人。</p>
            <?php endif; ?>
            <?php if ($qrUrl !== ''): ?>
              <div class="field">
                <div data-push-qr="<?= h($qrUrl) ?>" aria-live="polite">正在生成登录二维码……</div>
                <p class="field-hint">使用微信扫描二维码，并在手机上确认登录。完成后点击「检查登录状态」。二维码仅在本机生成。</p>
                <p class="field-hint"><a href="<?= h($qrUrl) ?>" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">打开微信官方登录链接</a></p>
                <noscript><p class="field-hint">启用 JavaScript 后可显示二维码，或使用上方官方登录链接。</p></noscript>
              </div>
            <?php endif; ?>
            <div class="form-actions">
              <form method="post" action="<?= h(sblog_push_admin_url()) ?>">
                <?= csrf_field() ?><input type="hidden" name="operation" value="weixin_start">
                <button class="button button--secondary" type="submit"><?= $qrUrl !== '' ? '重新获取二维码' : '获取登录二维码' ?></button>
              </form>
              <?php if (!empty($weixin['login_pending'])): ?>
                <form class="form-stack" method="post" action="<?= h(sblog_push_admin_url()) ?>">
                  <?= csrf_field() ?><input type="hidden" name="operation" value="weixin_check">
                  <?php if (!empty($weixin['need_verifycode'])): ?>
                    <div class="field"><label for="push-weixin-code">手机验证码</label><input id="push-weixin-code" name="verify_code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" pattern="[0-9]{1,12}" required></div>
                  <?php endif; ?>
                  <button class="button button--secondary" type="submit">检查登录状态</button>
                </form>
              <?php endif; ?>
              <?php if (!empty($weixin['logged_in'])): ?>
                <form method="post" action="<?= h(sblog_push_admin_url()) ?>">
                  <?= csrf_field() ?><input type="hidden" name="operation" value="weixin_sync">
                  <button class="button button--secondary" type="submit"<?= !empty($weixin['expired']) ? ' disabled' : '' ?>>同步微信接收人</button>
                </form>
                <form method="post" action="<?= h(sblog_push_admin_url()) ?>">
                  <?= csrf_field() ?><input type="hidden" name="operation" value="weixin_disconnect">
                  <button class="button button--danger" type="submit">退出微信登录</button>
                </form>
              <?php endif; ?>
            </div>
            <?php if ($candidates): ?>
              <form class="form-stack" method="post" action="<?= h(sblog_push_admin_url()) ?>">
                <?= csrf_field() ?><input type="hidden" name="operation" value="weixin_bind">
                <div class="field">
                  <label for="push-weixin-candidate">选择刚刚发送消息的微信接收人</label>
                  <select id="push-weixin-candidate" name="weixin_user_id" required>
                    <option value="">请选择接收人</option>
                    <?php foreach ($candidates as $candidate): ?>
                      <option value="<?= h($candidate['user_id'] ?? '') ?>"><?= h($candidate['user_id'] ?? '') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <p class="field-hint">仅可绑定本次登录实际收到过消息的用户。确认是自己的接收 ID 后再绑定。</p>
                </div>
                <div class="form-actions"><button class="button button--secondary" type="submit">绑定选中的接收人</button></div>
              </form>
            <?php endif; ?>
            <p class="field-hint">首次连接顺序：获取二维码 → 微信扫码并确认 → 检查登录状态 → 给机器人发消息 → 同步接收人 → 保存启用微信通知。退出登录会清除本地凭证和接收人，并停用微信通知。</p>
          </div>
        </section>
        <section class="panel">
          <div class="panel__header"><h2>测试与最近结果</h2><p class="panel__meta">测试会立即发送一条消息，使用已保存的设置；修改配置后请先保存。</p></div>
          <div class="panel__body form-stack">
            <p class="field-hint">发送成功表示平台已受理，请在 QQ / 微信中核对实际送达。</p>
            <?php foreach (['qq' => 'QQ', 'weixin' => '微信'] as $channel => $label): $result = $results[$channel]; ?>
              <div class="field">
                <form method="post" action="<?= h(sblog_push_admin_url()) ?>">
                  <?= csrf_field() ?><input type="hidden" name="operation" value="test_<?= h($channel) ?>">
                  <button class="button button--secondary" type="submit">发送<?= h($label) ?>测试通知</button>
                </form>
                <?php if ($result): ?>
                  <p class="field-hint"><?= h($label) ?>最近结果：<?= !empty($result['ok']) ? '已受理' : '失败' ?> · <?= h(is_numeric($result['time'] ?? null) ? date('Y-m-d H:i:s', (int)$result['time']) : ($result['time'] ?? '')) ?><?= !empty($result['message']) ? ' · ' . h($result['message']) : '' ?></p>
                <?php else: ?>
                  <p class="field-hint"><?= h($label) ?>尚无发送记录。</p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>
    </div>
    <?php if ($qrUrl !== ''): ?>
      <script defer src="<?= h(plugin_asset_url('comment-push', 'assets/vendor/qrcode.js') . '?v=2.0.4') ?>"></script>
      <script defer src="<?= h(plugin_asset_url('comment-push', 'assets/vendor/qrcode_UTF8.js') . '?v=2.0.4') ?>"></script>
      <script defer src="<?= h(plugin_asset_url('comment-push', 'assets/admin.js') . '?v=1.0.0') ?>"></script>
    <?php endif; ?>
    <?php
    render_layout('QQ / 微信评论推送', (string)ob_get_clean(), ['active' => 'plugins', 'wide' => true]);
}

function sblog_push_admin_request(): void
{
    require_admin();
    header('Cache-Control: no-store');
    $config = sblog_push_config();
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();
        $operation = sblog_push_input($_POST, 'operation');
        if ($operation === 'save') {
            $validated = sblog_push_validate($_POST, $config);
            $config = $validated['config'];
            $errors = $validated['errors'];
            if (!$errors) {
                try {
                    sblog_push_save_config($config);
                } catch (Throwable) {
                    $errors[] = '无法保存推送设置，请检查数据库是否可写。';
                }
                if (!$errors) {
                    set_flash('success', '评论推送设置已保存。');
                    redirect_to(sblog_push_admin_url(), 303);
                }
            }
        } elseif (in_array($operation, ['test_qq', 'test_weixin', 'qq_binding_start', 'qq_binding_cancel', 'weixin_start', 'weixin_check', 'weixin_sync', 'weixin_bind', 'weixin_disconnect'], true)) {
            try {
                if ($operation === 'test_qq' || $operation === 'test_weixin') {
                    $result = sblog_push_test(substr($operation, 5));
                    $type = !empty($result['ok']) ? 'success' : 'error';
                    $message = (string)$result['message'];
                } elseif ($operation === 'qq_binding_start') {
                    sblog_push_qq_binding_start();
                    $type = 'success';
                    $message = 'QQ 一次性绑定码已生成，请在 10 分钟内向目标会话发送绑定命令。';
                } elseif ($operation === 'qq_binding_cancel') {
                    sblog_push_qq_binding_cancel();
                    $type = 'success';
                    $message = '本次 QQ 绑定已取消，现有接收 ID 保持不变。';
                } elseif ($operation === 'weixin_start') {
                    $result = sblog_push_weixin_start();
                    $type = 'success';
                    $message = (string)$result['message'];
                } elseif ($operation === 'weixin_check') {
                    $result = sblog_push_weixin_check(sblog_push_input($_POST, 'verify_code'));
                    $type = in_array($result['status'], ['expired', 'verify_code_blocked'], true) ? 'error' : 'success';
                    $message = (string)$result['message'];
                } elseif ($operation === 'weixin_sync') {
                    $result = sblog_push_weixin_sync();
                    $type = 'success';
                    $message = (string)$result['message'];
                } elseif ($operation === 'weixin_bind') {
                    sblog_push_weixin_bind(sblog_push_input($_POST, 'weixin_user_id'));
                    $type = 'success';
                    $message = '微信接收人已绑定。请保存启用微信通知。';
                } else {
                    sblog_push_weixin_disconnect();
                    $type = 'success';
                    $message = '微信已退出登录，微信通知已停用。';
                }
                set_flash($type, $message);
            } catch (Throwable $exception) {
                set_flash('error', $exception instanceof RuntimeException && !($exception instanceof PDOException)
                    ? $exception->getMessage() : '操作失败，请检查服务器配置和数据库是否可写。');
            }
            redirect_to(sblog_push_admin_url(), 303);
        } else {
            $errors[] = '无效的操作。';
        }
    }
    sblog_push_admin($config, $errors);
}
