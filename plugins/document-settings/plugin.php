<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) { http_response_code(403); exit; }

$documentSchemaFile = THEMES_DIR . '/document/settings.php';
if (is_file($documentSchemaFile)) { require_once $documentSchemaFile; }
unset($documentSchemaFile);

function document_settings_available(): bool
{
    return is_file(THEMES_DIR . '/document/theme.json')
        && function_exists('document_settings_schema')
        && function_exists('document_settings_validate')
        && function_exists('document_settings_values');
}

function document_settings_url(): string
{
    return script_url() . '?a=admin_document_settings';
}

function document_settings_field(string $key, array $field, mixed $value): void
{
    $id = 'document-' . $key;
    $name = 'document[' . $key . ']';
    $type = $field['type'];
    ?>
    <div class="field">
      <?php if ($type === 'checkbox'): ?>
        <label class="setting-option" for="<?= h($id) ?>"><input id="<?= h($id) ?>" form="document-settings-form" type="checkbox" name="<?= h($name) ?>" value="1"<?= $value ? ' checked' : '' ?>><span><?= h($field['label']) ?></span></label>
      <?php else: ?>
        <label for="<?= h($id) ?>"><?= h($field['label']) ?></label>
        <?php if (in_array($type, ['textarea', 'css'], true)): ?>
          <textarea id="<?= h($id) ?>" form="document-settings-form" name="<?= h($name) ?>" rows="<?= $type === 'css' ? 10 : 3 ?>" maxlength="<?= (int)$field['limit'] ?>"<?= $type === 'css' ? ' spellcheck="false" style="font-family:monospace"' : '' ?>><?= h((string)$value) ?></textarea>
        <?php elseif ($type === 'select'): ?>
          <select id="<?= h($id) ?>" form="document-settings-form" name="<?= h($name) ?>"><?php foreach ($field['options'] as $option => $label): ?><option value="<?= h($option) ?>"<?= (string)$value === (string)$option ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
        <?php else: ?>
          <input id="<?= h($id) ?>" form="document-settings-form" name="<?= h($name) ?>" type="<?= h($type === 'image' ? 'text' : $type) ?>" value="<?= h((string)$value) ?>"<?php if ($type === 'number'): ?> min="<?= (int)$field['min'] ?>" max="<?= (int)$field['max'] ?>" step="1" required<?php elseif ($type === 'color'): ?> required<?php elseif (isset($field['limit'])): ?> maxlength="<?= (int)$field['limit'] ?>"<?php elseif (in_array($type, ['image', 'url'], true)): ?> maxlength="2048"<?php elseif ($type === 'email'): ?> maxlength="254"<?php endif; ?>>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($field['hint'])): ?><p class="field-hint"><?= h($field['hint']) ?></p><?php endif; ?>
    </div>
    <?php
}

function document_settings_page(): void
{
    require_admin();
    ob_start();
    echo render_admin_content_start('Document 主题设置', render_admin_sidebar('plugins'));
    if (!document_settings_available()) {
        ?>
        <section class="panel"><div class="panel__header"><h2>请先安装 Document 主题</h2></div><div class="panel__body"><p>未找到完整的 Document 主题文件。安装主题后即可在此配置；数据库中的已有配置仍然保留。</p><a class="button" href="<?= h(url_for('admin_themes')) ?>">主题管理</a></div></section>
        <?php
        render_admin_content_end('Document 主题设置', 'plugins', '配置 Document 主题。');
        return;
    }
    $values = document_settings_values();
    if (isset($_SESSION['document_settings_form']) && is_array($_SESSION['document_settings_form'])) {
        $values = array_replace($values, $_SESSION['document_settings_form']);
        unset($_SESSION['document_settings_form']);
    }
    $sections = [
        'appearance' => ['基础与外观', '设置原版主题配色、阅读模式与顶部导航。'],
        'home' => ['首页与列表', '设置文章列表、缩略图、摘要与首页横幅。'],
        'sidebar' => ['侧栏与作者', '设置分类导航、作者资料、公告与最新文章。'],
        'reading' => ['文章与阅读', '设置目录、相邻文章、版权信息与赞赏图片。'],
        'footer' => ['页脚与样式', '设置页脚文字、备案信息与自定义 CSS。'],
    ];
    ?>
      <form id="document-settings-form" method="post" action="<?= h(document_settings_url()) ?>"><?= csrf_field() ?><input type="hidden" name="operation" value="save"></form>
      <?php foreach ($sections as $section => [$label, $hint]): ?>
      <section class="panel">
        <div class="panel__header"><h2><?= h($label) ?></h2></div>
        <div class="panel__body">
          <p class="panel__meta"><?= h($hint) ?></p>
          <div class="form-stack">
            <?php foreach (document_settings_schema() as $key => $field): ?>
              <?php if ($field['section'] === $section) { document_settings_field($key, $field, $values[$key]); } ?>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
      <?php endforeach; ?>
      <div class="form-actions"><button class="button" type="submit" form="document-settings-form">保存全部设置</button><a class="button button--secondary" href="<?= h(url_for('admin_themes')) ?>">主题管理</a><a class="button button--secondary" href="<?= h(url_for('home')) ?>" target="_blank" rel="noopener">查看博客</a></div>
    <?php
    render_admin_content_end('Document 主题设置', 'plugins', '配置 Document 主题的外观与内容显示。');
}

add_plugin_action('request', static function (array $context): void {
    if (($context['action'] ?? '') !== 'admin_document_settings') { return; }
    require_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();
        if (!document_settings_available()) {
            set_flash('error', '请先安装完整的 Document 主题。');
            redirect_to(document_settings_url(), 303);
        }
        if (($_POST['operation'] ?? '') !== 'save' || !is_array($_POST['document'] ?? null)) {
            set_flash('error', '设置表单无效，请刷新页面后重试。');
            redirect_to(document_settings_url(), 303);
        }
        [$values, $errors] = document_settings_validate($_POST['document']);
        if ($errors) {
            // Keep valid submitted fields across redirect without saving a partial configuration.
            $_SESSION['document_settings_form'] = $values;
            set_flash('error', implode(' ', $errors));
            redirect_to(document_settings_url(), 303);
        }
        save_settings(['document_options' => json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        unset($_SESSION['document_settings_form']);
        set_flash('success', 'Document 主题设置已保存。');
        redirect_to(document_settings_url(), 303);
    }
    document_settings_page();
    exit;
});
