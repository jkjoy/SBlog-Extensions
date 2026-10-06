<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

function sblog_steam_admin(array $config, array $errors = []): void
{
    require_admin();
    ob_start();
    ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main">
        <?= render_admin_topbar('Steam 游戏展示', '查看展示页', sblog_steam_url()) ?>
        <section class="panel admin-list-panel">
          <div class="panel__header">
            <h2>连接 Steam</h2>
            <p class="panel__meta">展示玩家资料、过去两周的游戏记录与游戏库。</p>
          </div>
          <div class="panel__body">
            <?php if ($errors): ?>
              <div class="flash flash--error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <form class="form-stack" method="post" action="<?= h(sblog_steam_url('admin_steam')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="operation" value="save">
              <div class="field">
                <label for="steam_id">SteamID64</label>
                <input id="steam_id" name="steam_id" type="text" inputmode="numeric" maxlength="17" pattern="7656119[0-9]{10}" value="<?= h($config['steam_id']) ?>" placeholder="76561198000000000" aria-describedby="steam-id-hint">
                <p class="field-hint" id="steam-id-hint">17 位数字。可在 Steam 账号详情或官方 Web API 返回的 steamid 字段中获取。</p>
              </div>
              <div class="field">
                <label for="steam_api_key">Steam Web API Key</label>
                <input id="steam_api_key" name="api_key" type="password" maxlength="32" pattern="[a-fA-F0-9]{32}" autocomplete="new-password" value="" placeholder="<?= $config['api_key'] !== '' ? '已保存；留空保留现有密钥' : '填写 32 位 Web API Key' ?>" aria-describedby="steam-key-hint">
                <p class="field-hint" id="steam-key-hint">在 <a href="https://steamcommunity.com/dev/apikey" target="_blank" rel="noopener noreferrer">Steam 官方页面申请密钥</a>。密钥仅保存在服务器，不会发送给访客。</p>
                <?php if ($config['api_key'] !== ''): ?><label class="setting-option"><input name="clear_api_key" type="checkbox" value="1"><span>清除已保存的密钥</span></label><?php endif; ?>
              </div>
              <div class="field">
                <label for="steam_page_title">展示页标题</label>
                <input id="steam_page_title" name="page_title" value="<?= h($config['page_title']) ?>" maxlength="60" required>
              </div>
              <div class="field">
                <label for="steam_game_limit">每次展示游戏数</label>
                <input id="steam_game_limit" name="game_limit" type="number" min="1" max="24" value="<?= h($config['game_limit']) ?>" required>
                <p class="field-hint">游戏库可搜索、排序，并加载更多游戏。</p>
              </div>
              <div class="field">
                <label for="steam_cache_minutes">缓存时间（分钟）</label>
                <input id="steam_cache_minutes" name="cache_minutes" type="number" min="5" max="1440" value="<?= h($config['cache_minutes']) ?>" required>
                <p class="field-hint">默认 15 分钟。缓存过期后的首次访问自动同步，接口故障时使用上次结果；在线状态以最近一次同步为准。</p>
              </div>
              <fieldset class="field settings-field">
                <legend>展示选项</legend>
                <div class="settings-option-list">
                  <?php foreach (['show_recent' => '显示最近游玩', 'show_library' => '显示游戏库', 'home_widget' => '在首页文章列表下方显示 Steam 卡片', 'show_nav' => '在默认主题导航中显示展示页入口'] as $field => $label): ?>
                    <label class="setting-option"><input name="<?= h($field) ?>" type="checkbox" value="1"<?= $config[$field] ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
              <div class="field">
                <p class="field-hint">请在 Steam「编辑个人资料 → 隐私设置」中公开个人资料和游戏详情，并关闭「始终将我的总游戏时间保密」。隐藏的记录无法通过公开 API 获取。</p>
                <p class="field-hint">展示页地址：<a href="<?= h(sblog_steam_url()) ?>"><?= h(sblog_steam_url()) ?></a>。自定义主题可手动添加这个链接。</p>
              </div>
              <div class="form-actions"><button class="button" type="submit">保存设置</button><a class="button button--secondary" href="<?= h(url_for('admin_plugins')) ?>">返回插件管理</a></div>
            </form>
          </div>
        </section>
        <section class="panel">
          <div class="panel__header"><h2>数据同步</h2><p class="panel__meta">保存连接信息后，可手动测试 API 并更新缓存。</p></div>
          <div class="panel__body">
            <form method="post" action="<?= h(sblog_steam_url('admin_steam')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="operation" value="refresh">
              <button class="button button--secondary" type="submit"<?= $config['api_key'] === '' || $config['steam_id'] === '' ? ' disabled' : '' ?>>立即同步 Steam 数据</button>
            </form>
          </div>
        </section>
      </div>
    </div>
    <?php
    render_layout('Steam 游戏展示', (string)ob_get_clean(), ['active' => 'plugins', 'wide' => true]);
}

function sblog_steam_admin_request(): void
{
    require_admin();
    $config = sblog_steam_config();
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();
        $operation = sblog_steam_input($_POST, 'operation');
        if ($operation === 'save') {
            $validated = sblog_steam_validate_config($_POST, $config);
            $errors = $validated['errors'];
            if (!$errors) {
                try {
                    sblog_steam_save_config($validated['config']);
                    set_flash('success', 'Steam 设置已保存。');
                    redirect_to(sblog_steam_url('admin_steam'), 303);
                } catch (Throwable) {
                    $errors[] = '无法保存 Steam 设置，请检查数据库是否可写。';
                }
            }
            $config = $validated['config'];
        } elseif ($operation === 'refresh') {
            $snapshot = sblog_steam_snapshot($config, true);
            if ($snapshot['status'] === 'ok') {
                set_flash('success', 'Steam 数据已同步。' . ($snapshot['library_state'] === 'private' ? '游戏详情未公开，当前仅展示可获取的资料。' : ''));
            } else {
                set_flash('error', (string)$snapshot['message']);
            }
            redirect_to(sblog_steam_url('admin_steam'), 303);
        } else {
            $errors[] = '无效的操作。';
        }
    }
    sblog_steam_admin($config, $errors);
}
