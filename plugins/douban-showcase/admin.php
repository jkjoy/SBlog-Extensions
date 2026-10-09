<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

function sblog_douban_admin(array $config, array $errors = []): void
{
    require_admin();
    ob_start();
    ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main">
        <?= render_admin_topbar('豆瓣记录展示', '查看展示页', sblog_douban_url()) ?>
        <section class="panel admin-list-panel">
          <div class="panel__header"><h2>连接豆瓣</h2><p class="panel__meta">输入用户 ID，展示公开的电影、图书和音乐记录。</p></div>
          <div class="panel__body">
            <?php if ($errors): ?><div class="flash flash--error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form class="form-stack" method="post" action="<?= h(sblog_douban_url('admin_douban')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="operation" value="save">
              <div class="field">
                <label for="douban_user_id">豆瓣用户 ID</label>
                <input id="douban_user_id" name="user_id" type="text" maxlength="160" value="<?= h($config['user_id']) ?>" placeholder="例如 1000001 或 ahbei" aria-describedby="douban-id-hint" autocomplete="off">
                <p class="field-hint" id="douban-id-hint">个人主页 https://www.douban.com/people/<strong>用户ID</strong>/ 中的数字或自定义 ID，也可以粘贴完整主页地址。无需 API Key 或登录 Cookie。留空可清除账号。</p>
              </div>
              <div class="field"><label for="douban_page_title">展示页标题</label><input id="douban_page_title" name="page_title" maxlength="60" value="<?= h($config['page_title']) ?>" required></div>
              <div class="field">
                <label for="douban_page_size">每批展示条数</label>
                <input id="douban_page_size" name="page_size" type="number" min="1" max="48" value="<?= h($config['page_size']) ?>" required>
                <p class="field-hint">访客可搜索已同步的记录，点击「加载更多」继续浏览。</p>
              </div>
              <div class="field">
                <label for="douban_max_pages">每个列表同步页数</label>
                <input id="douban_max_pages" name="max_pages" type="number" min="1" max="10" value="<?= h($config['max_pages']) ?>" required>
                <p class="field-hint">默认同步最近 3 页，豆瓣通常每页 15 条。电影、图书、音乐与各记录状态分别同步；剩余记录可通过展示页链接在豆瓣查看。</p>
              </div>
              <div class="field">
                <label for="douban_cache_minutes">缓存时间（分钟）</label>
                <input id="douban_cache_minutes" name="cache_minutes" type="number" min="5" max="1440" value="<?= h($config['cache_minutes']) ?>" required>
                <p class="field-hint">默认 360 分钟。首次浏览及缓存过期后自动同步，失败时显示上次可用记录与同步时间。</p>
              </div>
              <fieldset class="field settings-field">
                <legend>展示选项</legend>
                <div class="settings-option-list">
                  <?php foreach (['show_nav' => '在默认主题导航中显示展示页入口', 'home_widget' => '在首页文章列表下方显示最近看过的电影'] as $field => $label): ?>
                    <label class="setting-option"><input name="<?= h($field) ?>" type="checkbox" value="1"<?= $config[$field] ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
              <p class="field-hint">展示页地址：<a href="<?= h(sblog_douban_url()) ?>"><?= h(sblog_douban_url()) ?></a>。自定义主题可手动添加此链接，并保留 head、body_close 与 content_after 主题钩子。</p>
              <div class="form-actions"><button class="button" type="submit">保存设置</button><a class="button button--secondary" href="<?= h(url_for('admin_plugins')) ?>">返回插件管理</a></div>
            </form>
          </div>
        </section>
        <section class="panel">
          <div class="panel__header"><h2>同步公开记录</h2><p class="panel__meta">保存用户 ID 后，可选择一个列表立即同步。</p></div>
          <div class="panel__body">
            <form class="form-stack" method="post" action="<?= h(sblog_douban_url('admin_douban')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="operation" value="refresh">
              <div class="field"><label for="douban_sync_type">记录类型</label><select id="douban_sync_type" name="type"><?php foreach (sblog_douban_types() as $type => $label): ?><option value="<?= h($type) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
              <div class="field"><label for="douban_sync_status">记录状态</label><select id="douban_sync_status" name="status"><option value="collect">看过 / 读过 / 听过</option><option value="wish">想看 / 想读 / 想听</option><option value="do">在看 / 在读 / 在听</option></select></div>
              <div class="form-actions"><button class="button button--secondary" type="submit"<?= $config['user_id'] === '' ? ' disabled' : '' ?>>立即同步</button></div>
            </form>
            <p class="field-hint">只能获取对外公开的记录。豆瓣可能限制服务器访问；遇到登录验证、验证码或访问频率限制时，请稍后重试。</p>
          </div>
        </section>
      </div>
    </div>
    <?php
    render_layout('豆瓣记录展示', (string)ob_get_clean(), ['active' => 'plugins', 'wide' => true]);
}

function sblog_douban_admin_request(): void
{
    require_admin();
    $config = sblog_douban_config();
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();
        $operation = sblog_douban_input($_POST, 'operation');
        if ($operation === 'save') {
            $validated = sblog_douban_validate_config($_POST, $config);
            $errors = $validated['errors'];
            $config = $validated['config'];
            if (!$errors) {
                try {
                    sblog_douban_save_config($config);
                } catch (Throwable) {
                    $errors[] = '无法保存豆瓣设置，请检查数据库是否可写。';
                }
                if (!$errors) {
                    set_flash('success', '豆瓣设置已保存，浏览展示页时会自动获取公开记录。');
                    redirect_to(sblog_douban_url('admin_douban'), 303);
                }
            }
        } elseif ($operation === 'refresh') {
            $type = sblog_douban_input($_POST, 'type');
            $status = sblog_douban_input($_POST, 'status');
            if (!array_key_exists($type, sblog_douban_types()) || !in_array($status, ['collect', 'wish', 'do'], true)) {
                $errors[] = '请选择有效的记录类型和状态。';
            } else {
                $snapshot = sblog_douban_snapshot($config, $type, $status, true);
                $label = sblog_douban_types()[$type] . ' · ' . sblog_douban_statuses($type)[$status];
                $success = $snapshot['status'] === 'ok';
                set_flash($success ? 'success' : 'error', $success
                    ? $label . '已同步，共 ' . count($snapshot['items']) . ' 条。' . ($snapshot['truncated'] ? '展示页可前往豆瓣查看剩余记录。' : '')
                    : (string)$snapshot['message']);
                redirect_to(sblog_douban_url('admin_douban'), 303);
            }
        } else {
            $errors[] = '无效的操作。';
        }
    }
    sblog_douban_admin($config, $errors);
}
