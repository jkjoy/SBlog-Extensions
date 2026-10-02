<?php

declare(strict_types=1);

function sblog_visit_render_pagination(array $report, array $filters): void
{
    $current = (int)$report['page'];
    $last = (int)$report['pages_count'];
    if ($last < 2) {
        return;
    }
    $pages = array_values(array_unique(array_merge([1, $last], range(max(1, $current - 2), min($last, $current + 2)))));
    sort($pages, SORT_NUMERIC);
    ?>
    <nav class="admin-pagination visit-log-pagination" aria-label="<?= h(sblog_t('访问日志分页')) ?>">
      <?php if ($current > 1): ?><a href="<?= h(sblog_visit_url('admin_visit_log', array_merge($filters, ['page' => $current - 1]))) ?>" rel="prev"><?= h(sblog_t('上一页')) ?></a><?php endif; ?>
      <?php $previous = 0; foreach ($pages as $page): ?>
        <?php if ($previous > 0 && $page > $previous + 1): ?><span class="visit-log-pagination__gap" aria-hidden="true">…</span><?php endif; ?>
        <?php if ($page === $current): ?><span aria-current="page"><?= $page ?></span><?php else: ?><a href="<?= h(sblog_visit_url('admin_visit_log', array_merge($filters, ['page' => $page]))) ?>" aria-label="<?= h(sblog_t('第 {page} 页', ['page' => $page])) ?>"><?= $page ?></a><?php endif; ?>
      <?php $previous = $page; endforeach; ?>
      <?php if ($current < $last): ?><a href="<?= h(sblog_visit_url('admin_visit_log', array_merge($filters, ['page' => $current + 1]))) ?>" rel="next"><?= h(sblog_t('下一页')) ?></a><?php endif; ?>
    </nav>
    <?php
}

function sblog_visit_render_ranking(array $rows, string $field, string $label): void
{
    if ($rows === []) {
        ?><p class="visit-log-empty visit-log-empty--compact"><?= h(sblog_t('暂无访问数据')) ?></p><?php
        return;
    }
    ?>
    <ol class="visit-log-ranking" aria-label="<?= h($label) ?>">
      <?php foreach ($rows as $index => $row): $name = (string)$row[$field]; ?>
        <li><span class="visit-log-ranking__number" aria-hidden="true"><?= $index + 1 ?></span><span class="visit-log-ranking__name" title="<?= h($name) ?>"><?= h($name !== '' ? $name : sblog_t('直接访问')) ?></span><strong><?= h(number_format((int)$row['views'])) ?><span class="visit-log-sr-only"> <?= h(sblog_t('次访问')) ?></span></strong></li>
      <?php endforeach; ?>
    </ol>
    <?php
}

function sblog_visit_render_admin(): void
{
    require_admin();
    sblog_visit_cleanup();
    $config = sblog_visit_config();
    $filterError = '';
    try {
        $filters = sblog_visit_filters($_GET);
    } catch (InvalidArgumentException $exception) {
        $filterError = $exception->getMessage();
        $filters = sblog_visit_filters([]);
    }
    $report = sblog_visit_report($filters);
    $filters['page'] = (int)$report['page'];
    $selectedDate = new DateTimeImmutable($filters['date'], sblog_visit_timezone());
    $isToday = $filters['date'] === sblog_visit_date('Y-m-d');
    $maxHour = max(1, ...array_map('intval', $report['hours']));
    $summaryLabels = [
        'views' => sblog_t('访问量'),
        'visitors' => sblog_t('独立访客'),
        'ips' => sblog_t('独立 IP'),
        'bots' => sblog_t('机器人访问'),
    ];
    ob_start();
    ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main visit-log-admin">
        <?= render_admin_topbar(sblog_t('访问日志')) ?>

        <section class="panel admin-list-panel admin-animate admin-animate--2">
          <div class="panel__header visit-log-heading">
            <div><h2><?= h(sblog_t('每日访问概览')) ?></h2><p class="panel__meta"><?= h(sblog_t('按日志时区 {timezone} 统计', ['timezone' => $config['timezone']])) ?></p></div>
            <span class="visit-log-state<?= $config['enabled'] ? ' visit-log-state--enabled' : '' ?>"><span aria-hidden="true"></span><?= h(sblog_t($config['enabled'] ? '正在记录' : '已暂停记录')) ?></span>
          </div>
          <div class="panel__body">
            <?php if ($filterError !== ''): ?><p class="flash flash--error" role="alert"><?= h($filterError) ?> <?= h(sblog_t('已显示今天的默认结果。')) ?></p><?php endif; ?>
            <form class="visit-log-filters" method="get" action="<?= h(script_url()) ?>">
              <input type="hidden" name="a" value="admin_visit_log">
              <div class="field"><label for="visit-log-date"><?= h(sblog_t('日期')) ?></label><input id="visit-log-date" type="date" name="date" value="<?= h($filters['date']) ?>" required></div>
              <div class="field"><label for="visit-log-ip"><?= h(sblog_t('IP 地址')) ?></label><input id="visit-log-ip" name="ip" value="<?= h($filters['ip']) ?>" maxlength="45" placeholder="<?= h(sblog_t('精确匹配 IP')) ?>" autocomplete="off" spellcheck="false"></div>
              <div class="field"><label for="visit-log-path"><?= h(sblog_t('访问页面')) ?></label><input id="visit-log-path" name="path" value="<?= h($filters['path']) ?>" maxlength="1024" placeholder="<?= h(sblog_t('路径包含…')) ?>" autocomplete="off" spellcheck="false"></div>
              <div class="field"><label for="visit-log-kind"><?= h(sblog_t('访问类型')) ?></label><select id="visit-log-kind" name="kind"><option value="all"<?= $filters['kind'] === 'all' ? ' selected' : '' ?>><?= h(sblog_t('全部访问')) ?></option><option value="human"<?= $filters['kind'] === 'human' ? ' selected' : '' ?>><?= h(sblog_t('普通访客')) ?></option><option value="bot"<?= $filters['kind'] === 'bot' ? ' selected' : '' ?>><?= h(sblog_t('机器人')) ?></option></select></div>
              <div class="visit-log-filters__actions"><button class="button" type="submit"><?= h(sblog_t('筛选')) ?></button><a class="button button--ghost" href="<?= h(sblog_visit_url()) ?>"><?= h(sblog_t('重置')) ?></a></div>
            </form>
            <div class="visit-log-date-nav">
              <strong><time datetime="<?= h($filters['date']) ?>"><?= h($filters['date']) ?></time><?php if ($isToday): ?><span class="visit-log-today"><?= h(sblog_t('今天')) ?></span><?php endif; ?></strong>
              <div><a href="<?= h(sblog_visit_url('admin_visit_log', array_merge($filters, ['date' => $selectedDate->modify('-1 day')->format('Y-m-d'), 'page' => 1]))) ?>"><?= h(sblog_t('前一天')) ?></a><?php if (!$isToday): ?><a href="<?= h(sblog_visit_url('admin_visit_log', array_merge($filters, ['date' => sblog_visit_date('Y-m-d'), 'page' => 1]))) ?>"><?= h(sblog_t('今天')) ?></a><?php endif; ?><a href="<?= h(sblog_visit_url('admin_visit_log', array_merge($filters, ['date' => $selectedDate->modify('+1 day')->format('Y-m-d'), 'page' => 1]))) ?>"><?= h(sblog_t('后一天')) ?></a></div>
            </div>
            <dl class="visit-log-summary">
              <?php foreach ($summaryLabels as $key => $label): ?><div><dt><?= h($label) ?></dt><dd><?= h(number_format((int)$report['summary'][$key])) ?></dd></div><?php endforeach; ?>
            </dl>
            <p class="field-hint visit-log-summary-note"><?= h(sblog_t('所有统计均按当前筛选计算。独立访客按 IP 与浏览器标识估算，机器人依据浏览器标识识别。')) ?></p>
          </div>
        </section>

        <section class="panel admin-list-panel admin-animate admin-animate--3">
          <div class="panel__header"><h2><?= h(sblog_t('24 小时访问分布')) ?></h2><p class="panel__meta"><?= h(sblog_t('每小时访问量')) ?></p></div>
          <div class="panel__body">
            <figure class="visit-log-chart">
              <figcaption class="visit-log-sr-only"><?= h(sblog_t('{date} 的每小时访问量', ['date' => $filters['date']])) ?></figcaption>
              <div class="visit-log-chart__scroll" tabindex="0" aria-label="<?= h(sblog_t('每小时访问图，可横向滚动')) ?>">
                <ol class="visit-log-chart__bars">
                  <?php for ($hour = 0; $hour < 24; $hour++): $count = (int)($report['hours'][$hour] ?? 0); $height = $count > 0 ? max(2, (int)round($count * 100 / $maxHour)) : 0; $hourLabel = sprintf('%02d', $hour); ?>
                    <li title="<?= h($hourLabel . ':00–' . $hourLabel . ':59 · ' . number_format($count)) ?>"><span class="visit-log-sr-only"><?= h(sblog_t('{hour} 时：{count} 次访问', ['hour' => $hourLabel, 'count' => $count])) ?></span><span class="visit-log-chart__count" aria-hidden="true"><?= $count > 0 ? h(number_format($count)) : '' ?></span><span class="visit-log-chart__track" aria-hidden="true"><span style="height: <?= $height ?>%"></span></span><span class="visit-log-chart__hour" aria-hidden="true"><?= h($hourLabel) ?></span></li>
                  <?php endfor; ?>
                </ol>
              </div>
            </figure>
          </div>
        </section>

        <div class="visit-log-rankings">
          <section class="panel admin-list-panel"><div class="panel__header"><h2><?= h(sblog_t('热门页面')) ?></h2><p class="panel__meta"><?= h(sblog_t('按访问量排序')) ?></p></div><div class="panel__body"><?php sblog_visit_render_ranking($report['pages'], 'path', sblog_t('热门页面')); ?></div></section>
          <section class="panel admin-list-panel"><div class="panel__header"><h2><?= h(sblog_t('访问来源')) ?></h2><p class="panel__meta"><?= h(sblog_t('来源域名')) ?></p></div><div class="panel__body"><?php sblog_visit_render_ranking($report['sources'], 'referrer_host', sblog_t('访问来源')); ?></div></section>
        </div>

        <section class="panel admin-list-panel">
          <div class="panel__header"><h2><?= h(sblog_t('访问明细')) ?></h2><p class="panel__meta"><?= h(sblog_t('共 {total} 条 · 按时间倒序 · 每页 50 条', ['total' => number_format((int)$report['total'])])) ?></p></div>
          <?php if ($report['rows'] === []): ?>
            <div class="panel__body"><div class="visit-log-empty"><strong><?= h(sblog_t('当天没有符合条件的访问记录')) ?></strong><p><?= h(sblog_t('可调整筛选条件或选择其他日期。启用记录后，新的访问会显示在这里。')) ?></p></div></div>
          <?php else: ?>
            <div class="table-wrap visit-log-table-wrap"><table class="visit-log-table"><caption class="visit-log-sr-only"><?= h(sblog_t('{date} 的访问明细', ['date' => $filters['date']])) ?></caption><thead><tr><th scope="col"><?= h(sblog_t('时间')) ?></th><th scope="col"><?= h(sblog_t('访问页面')) ?></th><th scope="col"><?= h(sblog_t('IP 地址')) ?></th><th scope="col"><?= h(sblog_t('来源')) ?></th><th scope="col"><?= h(sblog_t('设备与浏览器')) ?></th><th scope="col"><?= h(sblog_t('状态')) ?></th><th scope="col"><?= h(sblog_t('响应耗时')) ?></th></tr></thead><tbody>
              <?php foreach ($report['rows'] as $row): $isBot = (int)$row['is_bot'] === 1; $status = (int)$row['status_code']; $device = (string)$row['device']; ?>
                <tr>
                  <td class="visit-log-time"><time datetime="<?= h(sblog_visit_date('c', (int)$row['visited_at'])) ?>" title="<?= h(sblog_visit_date('Y-m-d H:i:s T', (int)$row['visited_at'])) ?>"><?= h(sblog_visit_date('H:i:s', (int)$row['visited_at'])) ?></time><?php if ($isBot): ?><span class="visit-log-bot"><?= h(sblog_t('机器人')) ?></span><?php endif; ?></td>
                  <td><span class="visit-log-cell-text visit-log-path" title="<?= h((string)$row['path']) ?>"><?= h((string)$row['path']) ?></span></td>
                  <td class="visit-log-ip"><?= h((string)$row['ip'] !== '' ? (string)$row['ip'] : '—') ?></td>
                  <td><span class="visit-log-cell-text" title="<?= h((string)$row['referrer']) ?>"><?= h((string)$row['referrer_host'] !== '' ? (string)$row['referrer_host'] : sblog_t('直接访问')) ?></span><?php if ((string)$row['referrer'] !== ''): ?><details class="visit-log-details"><summary><?= h(sblog_t('查看来源')) ?></summary><p><?= h((string)$row['referrer']) ?></p></details><?php endif; ?></td>
                  <td><span><?= h(sblog_t(['desktop' => '桌面设备', 'mobile' => '手机', 'tablet' => '平板', 'bot' => '机器人', 'unknown' => '未知设备'][$device] ?? $device)) ?></span><small class="visit-log-device-meta"><?= h((string)$row['browser'] . ' · ' . (string)$row['os']) ?></small><?php if ((string)$row['user_agent'] !== ''): ?><details class="visit-log-details"><summary><?= h(sblog_t('浏览器标识')) ?></summary><p><?= h((string)$row['user_agent']) ?></p></details><?php endif; ?></td>
                  <td><span class="visit-log-status<?= $status >= 400 ? ' visit-log-status--error' : ($status >= 300 ? ' visit-log-status--redirect' : '') ?>"><?= $status > 0 ? $status : '—' ?></span></td>
                  <td class="visit-log-duration"><?= h(number_format(max(0, (int)$row['duration_ms']))) ?> <small>ms</small></td>
                </tr>
              <?php endforeach; ?>
            </tbody></table></div>
            <?php sblog_visit_render_pagination($report, $filters); ?>
          <?php endif; ?>
        </section>

        <section class="panel admin-list-panel">
          <div class="panel__header"><h2><?= h(sblog_t('记录设置')) ?></h2><p class="panel__meta"><?= h(sblog_t('自动清理超过保留期限的日志')) ?></p></div>
          <div class="panel__body"><form class="form-stack" method="post" action="<?= h(sblog_visit_url('save_visit_log_settings')) ?>">
            <?= csrf_field() ?>
            <label class="setting-option"><input type="checkbox" name="enabled" value="1"<?= $config['enabled'] ? ' checked' : '' ?>><span><strong><?= h(sblog_t('启用访问记录')) ?></strong><small><?= h(sblog_t('启用后记录新的页面访问；关闭后仍可查看已有日志。')) ?></small></span></label>
            <label class="setting-option"><input type="checkbox" name="record_bots" value="1"<?= $config['record_bots'] ? ' checked' : '' ?>><span><strong><?= h(sblog_t('记录机器人访问')) ?></strong><small><?= h(sblog_t('包括搜索引擎爬虫等自动访问，可在日志中单独筛选。')) ?></small></span></label>
            <div class="field visit-log-retention"><label for="visit-log-timezone"><?= h(sblog_t('日志时区')) ?></label><input id="visit-log-timezone" name="timezone" value="<?= h($config['timezone']) ?>" maxlength="64" placeholder="Asia/Shanghai" spellcheck="false" required><small class="field-hint"><?= h(sblog_t('日期、小时分布与访问时间均按此时区显示，例如 Asia/Shanghai。')) ?></small></div>
            <div class="field visit-log-retention"><label for="visit-log-retention"><?= h(sblog_t('日志保留期限')) ?></label><select id="visit-log-retention" name="retention_days"><?php foreach ([1, 7, 30, 90, 180, 365] as $days): ?><option value="<?= $days ?>"<?= (int)$config['retention_days'] === $days ? ' selected' : '' ?>><?= h(sblog_t('{days} 天', ['days' => $days])) ?></option><?php endforeach; ?></select><small class="field-hint"><?= h(sblog_t('缩短保留期限并保存后，超期记录会自动清理。')) ?></small></div>
            <div class="action-row"><button class="button" type="submit"><?= h(sblog_t('保存设置')) ?></button></div>
          </form></div>
        </section>
      </div>
    </div>
    <?php
    render_layout(sblog_t('访问日志'), (string)ob_get_clean(), ['active' => 'plugins', 'wide' => true]);
}

function sblog_visit_handle_admin_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    if ($action === 'admin_visit_log') {
        require_admin();
        try {
            sblog_visit_render_admin();
        } catch (Throwable $exception) {
            error_log('Visit log admin failed: ' . $exception->getMessage());
            simple_error_page(sblog_t('访问日志暂时无法访问'), sblog_t('请检查服务器日志后重试。'), 500);
        }
        exit;
    }
    if ($action !== 'save_visit_log_settings') {
        return;
    }
    require_admin_post(sblog_visit_url());
    try {
        sblog_visit_save_config($_POST);
        sblog_visit_cleanup();
        set_flash('success', sblog_t('访问日志设置已保存。'));
    } catch (Throwable $exception) {
        if (!$exception instanceof InvalidArgumentException) {
            error_log('Visit log settings save failed: ' . $exception->getMessage());
        }
        set_flash('error', $exception instanceof InvalidArgumentException ? $exception->getMessage() : sblog_t('访问日志设置保存失败，请稍后重试。'));
    }
    redirect_to(sblog_visit_url(), 303);
}

function sblog_visit_admin_styles(mixed $html, array $context = []): mixed
{
    if (!is_string($html) || $html === '' || (string)($context['action'] ?? $GLOBALS['sblog_current_action'] ?? '') !== 'admin_visit_log'
        || str_contains($html, 'data-sblog-visit-log-style')) {
        return $html;
    }
    $headEnd = strripos($html, '</head>');
    if ($headEnd !== false) {
        $url = plugin_asset_url('visit-log', 'assets/admin.css') . '?v=' . rawurlencode(SBLOG_VISIT_LOG_VERSION);
        $html = substr_replace($html, '<link rel="stylesheet" href="' . h($url) . '" data-sblog-visit-log-style>' . "\n", $headEnd, 0);
    }
    return $html;
}
