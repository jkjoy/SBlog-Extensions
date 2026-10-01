<?php

declare(strict_types=1);

function sblog_menu_admin_url(): string
{
    return script_url() . '?a=admin_menus';
}

function sblog_menu_destinations(): array
{
    $groups = [sblog_t('内置页面') => [], sblog_t('独立页面') => [], sblog_t('分类') => []];
    foreach (sblog_menu_routes() as $route => $label) {
        $groups[sblog_t('内置页面')]['route:' . $route] = $label;
    }
    foreach (sblog_menu_pages() as $page) {
        $groups[sblog_t('独立页面')]['page:' . $page['id']] = (string)$page['title'];
    }
    foreach (sblog_menu_categories() as $category) {
        $groups[sblog_t('分类')]['category:' . $category['id']] = (string)$category['name'];
    }
    return $groups;
}

function sblog_menu_render_row(array $item, string $index, array $groups): void
{
    $prefix = 'items[' . $index . ']';
    $destination = $item['type'] === 'custom' ? 'custom' : $item['type'] . ':' . $item['reference'];
    $known = $destination === 'custom';
    foreach ($groups as $choices) {
        $known = $known || isset($choices[$destination]);
    }
    ?>
    <li class="menu-manager-row" data-menu-row>
      <input type="hidden" name="<?= h($prefix) ?>[id]" value="<?= h($item['id']) ?>">
      <div class="menu-manager-row__head"><span class="menu-manager-row__number" data-menu-number></span><strong><?= h(sblog_t('菜单项')) ?></strong><div class="menu-manager-row__actions">
        <button class="button button--ghost" type="submit" name="operation" value="up:<?= h($item['id']) ?>" data-menu-move="up" aria-label="<?= h(sblog_t('上移菜单项')) ?>">↑</button>
        <button class="button button--ghost" type="submit" name="operation" value="down:<?= h($item['id']) ?>" data-menu-move="down" aria-label="<?= h(sblog_t('下移菜单项')) ?>">↓</button>
        <button class="button button--ghost" type="submit" name="operation" value="remove:<?= h($item['id']) ?>" data-menu-remove><?= h(sblog_t('删除')) ?></button>
      </div></div>
      <div class="menu-manager-row__fields">
        <div class="field"><label for="menu-destination-<?= h($index) ?>"><?= h(sblog_t('链接目标')) ?></label><select id="menu-destination-<?= h($index) ?>" name="<?= h($prefix) ?>[destination]" data-menu-destination>
          <?php foreach ($groups as $group => $choices): ?><?php if ($choices !== []): ?><optgroup label="<?= h($group) ?>"><?php foreach ($choices as $value => $label): ?><option value="<?= h($value) ?>"<?= $destination === $value ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></optgroup><?php endif; ?><?php endforeach; ?>
          <?php if (!$known): ?><option value="<?= h($destination) ?>" selected><?= h(sblog_t('目标已删除或未发布')) ?></option><?php endif; ?>
          <option value="custom"<?= $destination === 'custom' ? ' selected' : '' ?>><?= h(sblog_t('自定义链接')) ?></option>
        </select></div>
        <div class="field"><label for="menu-label-<?= h($index) ?>"><?= h(sblog_t('显示名称')) ?></label><input id="menu-label-<?= h($index) ?>" name="<?= h($prefix) ?>[label]" value="<?= h($item['label']) ?>" maxlength="100" placeholder="<?= h(sblog_t('留空使用目标名称')) ?>" data-menu-label></div>
        <div class="field menu-manager-row__url" data-menu-url-field><label for="menu-url-<?= h($index) ?>"><?= h(sblog_t('自定义链接地址')) ?></label><input id="menu-url-<?= h($index) ?>" name="<?= h($prefix) ?>[url]" value="<?= h($item['url']) ?>" maxlength="2000" placeholder="https://example.com 或 /about" data-menu-url></div>
      </div>
      <div class="menu-manager-row__flags"><label><input type="checkbox" name="<?= h($prefix) ?>[enabled]" value="1"<?= $item['enabled'] ? ' checked' : '' ?>> <?= h(sblog_t('显示此项')) ?></label><label><input type="checkbox" name="<?= h($prefix) ?>[new_tab]" value="1"<?= $item['new_tab'] ? ' checked' : '' ?>> <?= h(sblog_t('新窗口打开')) ?></label><?php if (!$known): ?><span class="menu-manager-warning"><?= h(sblog_t('此项不会在前台显示，请选择其他目标。')) ?></span><?php endif; ?></div>
    </li>
    <?php
}

function sblog_menu_render_admin(): void
{
    require_admin();
    $config = sblog_menu_config();
    $hasDraft = isset($_SESSION['menu_manager_draft']) && is_array($_SESSION['menu_manager_draft']);
    if ($hasDraft) {
        $config = $_SESSION['menu_manager_draft'];
        unset($_SESSION['menu_manager_draft']);
    }
    $groups = sblog_menu_destinations();
    // Seed existing published navigation pages only on the first setup.
    if (!$hasDraft && setting('menu_manager_config') === '') {
        foreach (fetch_nav_pages() as $page) {
            $config['items'][] = sblog_menu_new_item('page', (string)$page['id']);
        }
    }
    ob_start();
    ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main">
        <?= render_admin_topbar(sblog_t('菜单管理')) ?>
        <section class="panel admin-list-panel admin-animate admin-animate--2" data-menu-manager>
          <div class="panel__header"><h2><?= h(sblog_t('统一主导航')) ?></h2><p class="panel__meta"><?= h(sblog_t('一次配置，应用到所有官方主题的桌面与移动菜单。切换主题后继续保留。')) ?></p></div>
          <div class="panel__body">
            <form class="form-stack" method="post" action="<?= h(script_url() . '?a=save_menus') ?>" data-menu-form data-menu-limit="100">
              <?= csrf_field() ?>
              <input type="hidden" name="items_present" value="1">
              <div class="menu-manager-mode"><label><input type="checkbox" name="managed" value="1"<?= $config['managed'] ? ' checked' : '' ?>> <strong><?= h(sblog_t('接管主题主菜单')) ?></strong></label><p class="field-hint"><?= h(sblog_t('勾选并保存后使用以下菜单；取消勾选可恢复主题默认导航，同时保留配置。')) ?></p></div>
              <p class="field-hint"><?= h(sblog_t('使用上移、下移调整顺序。独立页面和分类会自动跟随名称与地址变化；未发布或已删除的目标自动隐藏。')) ?></p>
              <ol class="menu-manager-list" data-menu-list><?php foreach ($config['items'] as $index => $item): ?><?php sblog_menu_render_row($item, (string)$index, $groups); ?><?php endforeach; ?></ol>
              <p class="menu-manager-empty" data-menu-empty<?= $config['items'] !== [] ? ' hidden' : '' ?>><?= h(sblog_t('还没有菜单项。接管后，空菜单会隐藏主导航链接。')) ?></p>
              <div class="menu-manager-add"><label for="menu-new-destination"><?= h(sblog_t('添加到菜单')) ?></label><select id="menu-new-destination" name="new_destination" data-menu-new-destination><?php foreach ($groups as $group => $choices): ?><?php if ($choices !== []): ?><optgroup label="<?= h($group) ?>"><?php foreach ($choices as $value => $label): ?><option value="<?= h($value) ?>"><?= h($label) ?></option><?php endforeach; ?></optgroup><?php endif; ?><?php endforeach; ?><option value="custom"><?= h(sblog_t('自定义链接')) ?></option></select><button class="button button--ghost" type="submit" name="operation" value="add" data-menu-add><?= h(sblog_t('添加菜单项')) ?></button></div>
              <p class="field-hint" data-menu-status role="status" aria-live="polite"></p>
              <div class="action-row menu-manager-save"><button class="button" type="submit" name="operation" value="save"><?= h(sblog_t('保存菜单')) ?></button><button class="button button--ghost" type="submit" name="operation" value="reset" formnovalidate><?= h(sblog_t('恢复主题默认菜单')) ?></button><span class="field-hint" data-menu-unsaved hidden><?= h(sblog_t('有未保存的修改')) ?></span></div>
              <noscript><p class="field-hint"><?= h(sblog_t('未启用 JavaScript，添加、删除和排序会提交并保存当前表单。添加自定义链接时先填写已有菜单，再编辑新增项。')) ?></p></noscript>
              <input type="hidden" name="items_complete" value="1">
            </form>
            <template data-menu-template><?php sblog_menu_render_row(sblog_menu_new_item(), '__INDEX__', $groups); ?></template>
          </div>
        </section>
      </div>
    </div>
    <?php
    render_layout(sblog_t('菜单管理'), (string)ob_get_clean(), ['active' => 'plugins', 'wide' => true]);
}

function sblog_menu_post_config(array $post): array
{
    // If PHP truncates a large request at max_input_vars, do not save a partial menu.
    if (($post['items_complete'] ?? null) !== '1' || ($post['items_present'] ?? null) !== '1') {
        throw new InvalidArgumentException(sblog_t('菜单表单不完整，请减少菜单项或提高服务器 max_input_vars 后重试。'));
    }
    $rows = $post['items'] ?? [];
    if (!is_array($rows) || count($rows) > SBLOG_MENU_MAX_ITEMS) {
        throw new InvalidArgumentException(sblog_t('菜单配置无效，一份菜单最多包含 100 项。'));
    }
    if (isset($post['managed']) && $post['managed'] !== '1') {
        throw new InvalidArgumentException(sblog_t('菜单开关无效。'));
    }
    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException(sblog_t('菜单项格式无效。'));
        }
        foreach (['id', 'destination', 'label', 'url'] as $field) {
            if (!is_string($row[$field] ?? null)) {
                throw new InvalidArgumentException(sblog_t('菜单项格式无效。'));
            }
        }
        foreach (['enabled', 'new_tab'] as $field) {
            if (isset($row[$field]) && $row[$field] !== '1') {
                throw new InvalidArgumentException(sblog_t('菜单开关无效。'));
            }
        }
        [$type, $reference] = array_pad(explode(':', $row['destination'], 2), 2, '');
        $items[] = [
            'id' => $row['id'], 'type' => $type, 'reference' => $reference,
            'label' => $row['label'], 'url' => $row['url'],
            'enabled' => ($row['enabled'] ?? '') === '1', 'new_tab' => ($row['new_tab'] ?? '') === '1',
        ];
    }
    return ['version' => 1, 'managed' => ($post['managed'] ?? '') === '1', 'items' => $items];
}

function sblog_menu_handle_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    if ($action === 'admin_menus') {
        require_admin();
        try {
            sblog_menu_render_admin();
        } catch (Throwable $exception) {
            error_log('Menu manager admin failed: ' . $exception->getMessage());
            simple_error_page(sblog_t('菜单管理暂时无法访问'), sblog_t('请检查服务器日志后重试。'), 500);
        }
        exit;
    }
    if ($action !== 'save_menus') {
        return;
    }
    require_admin_post(sblog_menu_admin_url());
    $draft = null;
    try {
        $operation = $_POST['operation'] ?? 'save';
        if (!is_string($operation)) {
            throw new InvalidArgumentException(sblog_t('菜单操作无效。'));
        }
        if ($operation === 'reset') {
            $config = sblog_menu_config();
            $config['managed'] = false;
        } else {
            $config = sblog_menu_post_config($_POST);
            $draft = $config;
            if ($operation === 'add') {
                $destination = $_POST['new_destination'] ?? '';
                if (!is_string($destination)) {
                    throw new InvalidArgumentException(sblog_t('请选择有效的菜单目标。'));
                }
                [$type, $reference] = array_pad(explode(':', $destination, 2), 2, '');
                // Without JS, create an editable route row rather than save an incomplete custom URL.
                $config['items'][] = $type === 'custom' ? sblog_menu_new_item() : sblog_menu_new_item($type, $reference);
            } elseif ($operation !== 'save') {
                [$command, $id] = array_pad(explode(':', $operation, 2), 2, '');
                $index = array_search($id, array_column($config['items'], 'id'), true);
                if (!in_array($command, ['remove', 'up', 'down'], true) || $index === false) {
                    throw new InvalidArgumentException(sblog_t('菜单操作无效。'));
                }
                if ($command === 'remove') {
                    array_splice($config['items'], $index, 1);
                } else {
                    $next = $index + ($command === 'up' ? -1 : 1);
                    if (isset($config['items'][$next])) {
                        [$config['items'][$index], $config['items'][$next]] = [$config['items'][$next], $config['items'][$index]];
                    }
                }
            }
        }
        sblog_menu_save($config);
        unset($_SESSION['menu_manager_draft']);
        set_flash('success', sblog_t($config['managed'] ? '菜单已保存，所有主题的主导航已更新。' : '已使用主题默认菜单，统一配置已保留。'));
    } catch (Throwable $exception) {
        if ($draft !== null) {
            $_SESSION['menu_manager_draft'] = $draft;
        }
        if (!$exception instanceof InvalidArgumentException) {
            error_log('Menu manager save failed: ' . $exception->getMessage());
        }
        set_flash('error', $exception instanceof InvalidArgumentException ? $exception->getMessage() : sblog_t('菜单保存失败，请稍后重试。'));
    }
    redirect_to(sblog_menu_admin_url(), 303);
}
