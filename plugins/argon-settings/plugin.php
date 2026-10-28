<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

/** The theme remains usable without this plugin; both share the argon_options setting. */
function argon_settings_schema(): array
{
    return [
        'primary_color' => ['section' => 'appearance', 'label' => '主题颜色', 'type' => 'color', 'default' => '#5e72e4'],
        'background_color' => ['section' => 'appearance', 'label' => '页面背景颜色', 'type' => 'color', 'default' => '#f4f5f7'],
        'darkmode' => ['section' => 'appearance', 'label' => '默认显示模式', 'type' => 'select', 'default' => 'auto', 'options' => ['auto' => '跟随系统', 'light' => '浅色', 'dark' => '深色']],
        'font_family' => ['section' => 'appearance', 'label' => '字体风格', 'type' => 'select', 'default' => 'sans', 'options' => ['sans' => '无衬线字体（原版默认）', 'serif' => '衬线字体']],
        'card_shadow' => ['section' => 'appearance', 'label' => '卡片阴影', 'type' => 'select', 'default' => 'small', 'options' => ['small' => '浅阴影（原版默认）', 'big' => '深阴影']],
        'immersion_color' => ['section' => 'appearance', 'label' => '沉浸式主题色', 'type' => 'checkbox', 'default' => false],
        'amoled_dark' => ['section' => 'appearance', 'label' => '深色模式使用纯黑背景', 'type' => 'checkbox', 'default' => false],
        'card_radius' => ['section' => 'appearance', 'label' => '卡片圆角（像素）', 'type' => 'number', 'default' => 4, 'min' => 0, 'max' => 24],
        'navbar_title' => ['section' => 'appearance', 'label' => '导航栏标题', 'type' => 'text', 'default' => '', 'limit' => 100, 'hint' => '留空使用站点名称。'],
        'banner_title' => ['section' => 'banner', 'label' => '横幅标题', 'type' => 'text', 'default' => '', 'limit' => 200, 'hint' => '留空使用站点名称。'],
        'banner_subtitle' => ['section' => 'banner', 'label' => '横幅副标题', 'type' => 'text', 'default' => '', 'limit' => 500, 'hint' => '留空使用站点简介。'],
        'banner_background' => ['section' => 'banner', 'label' => '横幅背景图片', 'type' => 'image', 'default' => '', 'hint' => '完整 HTTP(S) 图片地址或以 / 开头的站内路径；留空使用原版渐变。'],
        'banner_height' => ['section' => 'banner', 'label' => '横幅高度（像素）', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 900, 'hint' => '0 使用原版 71.8vh，随视口高度变化；自定义高度范围为 200–900 像素。'],
        'banner_shape' => ['section' => 'banner', 'label' => '显示横幅几何装饰', 'type' => 'checkbox', 'default' => true],
        'sidebar_left' => ['section' => 'sidebar', 'label' => '显示左侧栏', 'type' => 'checkbox', 'default' => true],
        'sidebar_right' => ['section' => 'sidebar', 'label' => '显示右侧栏', 'type' => 'checkbox', 'default' => false],
        'avatar' => ['section' => 'sidebar', 'label' => '作者头像', 'type' => 'image', 'default' => '', 'hint' => '完整 HTTP(S) 图片地址或以 / 开头的站内路径。'],
        'author_name' => ['section' => 'sidebar', 'label' => '作者昵称', 'type' => 'text', 'default' => '', 'limit' => 100],
        'author_description' => ['section' => 'sidebar', 'label' => '作者简介', 'type' => 'textarea', 'default' => '', 'limit' => 1000],
        'announcement' => ['section' => 'sidebar', 'label' => '公告', 'type' => 'textarea', 'default' => '', 'limit' => 3000, 'hint' => '纯文本，支持换行；留空隐藏公告。'],
        'social_github' => ['section' => 'sidebar', 'label' => 'GitHub 地址', 'type' => 'url', 'default' => ''],
        'social_email' => ['section' => 'sidebar', 'label' => '联系邮箱', 'type' => 'email', 'default' => ''],
        'social_rss' => ['section' => 'sidebar', 'label' => '显示 RSS 链接', 'type' => 'checkbox', 'default' => true],
        'show_excerpt' => ['section' => 'posts', 'label' => '显示文章摘要', 'type' => 'checkbox', 'default' => true],
        'excerpt_length' => ['section' => 'posts', 'label' => '摘要长度（字符）', 'type' => 'number', 'default' => 120, 'min' => 40, 'max' => 500],
        'show_reading_time' => ['section' => 'posts', 'label' => '显示预计阅读时间', 'type' => 'checkbox', 'default' => true],
        'show_post_navigation' => ['section' => 'posts', 'label' => '显示上一篇 / 下一篇', 'type' => 'checkbox', 'default' => true],
        'show_toc' => ['section' => 'posts', 'label' => '显示文章目录', 'type' => 'checkbox', 'default' => true],
        'footer_text' => ['section' => 'footer', 'label' => '页脚文字', 'type' => 'textarea', 'default' => '', 'limit' => 2000, 'hint' => '纯文本，支持换行。'],
        'footer_since' => ['section' => 'footer', 'label' => '建站年份', 'type' => 'number', 'default' => (int)date('Y'), 'min' => 1970, 'max' => 2100],
        'custom_css' => ['section' => 'footer', 'label' => '自定义 CSS', 'type' => 'css', 'default' => '', 'limit' => 30000, 'hint' => '仅填写 CSS 规则，无需 <style> 标签。'],
    ];
}

/** Returns only schema keys and rejects malformed or out-of-range submitted values. */
function argon_settings_validate(array $input): array
{
    $values = [];
    $errors = [];
    foreach (argon_settings_schema() as $key => $field) {
        $value = $input[$key] ?? ($field['type'] === 'checkbox' ? false : $field['default']);
        $invalid = false;
        if (!is_scalar($value) && $value !== null) {
            $invalid = true;
            $value = $field['default'];
        }
        if ($field['type'] === 'checkbox') {
            $invalid = $invalid || !in_array($value, [true, false, 0, 1, '0', '1', 'on'], true);
            $value = in_array($value, [true, 1, '1', 'on'], true);
        } else {
            $value = trim((string)$value);
            switch ($field['type']) {
                case 'color':
                    $invalid = $invalid || preg_match('/^#[0-9a-f]{6}$/iD', $value) !== 1;
                    $value = strtolower($value);
                    break;
                case 'select':
                    $invalid = $invalid || !array_key_exists($value, $field['options']);
                    break;
                case 'number':
                    $invalid = $invalid || preg_match('/^\d+$/D', $value) !== 1
                        || (int)$value < $field['min'] || (int)$value > $field['max'];
                    if ($key === 'banner_height' && (int)$value !== 0 && (int)$value < 200) {
                        $invalid = true;
                    }
                    $value = (int)$value;
                    break;
                case 'image':
                case 'url':
                    $local = $field['type'] === 'image' && str_starts_with($value, '/') && !str_starts_with($value, '//');
                    $invalid = $invalid || strlen($value) > 2048
                        || ($value !== '' && (!$local && !valid_http_url($value)))
                        || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1;
                    break;
                case 'email':
                    $invalid = $invalid || strlen($value) > 254 || ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false);
                    break;
                case 'css':
                    $invalid = $invalid || strlen($value) > $field['limit'] || str_contains($value, "\0");
                    // CSS is inserted in a style element by the theme, never as markup.
                    $invalid = $invalid || preg_match('/<\s*\/?\s*style\b/i', $value) === 1;
                    break;
                default:
                    $value = trim(strip_tags($value));
                    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
                    $invalid = $invalid || $length > $field['limit'] || str_contains($value, "\0");
            }
        }
        if ($invalid) {
            $errors[] = $field['label'] . '的格式或范围不正确。';
            $value = $field['default'];
        }
        $values[$key] = $value;
    }
    return [$values, $errors];
}

function argon_settings_values(): array
{
    $defaults = array_map(static fn(array $field): mixed => $field['default'], argon_settings_schema());
    $stored = json_decode(setting('argon_options', '{}'), true);
    [$values] = argon_settings_validate(array_replace($defaults, is_array($stored) ? $stored : []));
    return $values;
}

function argon_settings_url(): string
{
    return script_url() . '?a=admin_argon_settings';
}

function argon_settings_field(string $key, array $field, mixed $value): void
{
    $id = 'argon-' . $key;
    $name = 'argon[' . $key . ']';
    $type = $field['type'];
    ?>
    <div class="field">
      <?php if ($type === 'checkbox'): ?>
        <label class="checkbox" for="<?= h($id) ?>"><input id="<?= h($id) ?>" type="checkbox" name="<?= h($name) ?>" value="1"<?= $value ? ' checked' : '' ?>> <?= h($field['label']) ?></label>
      <?php else: ?>
        <label for="<?= h($id) ?>"><?= h($field['label']) ?></label>
        <?php if (in_array($type, ['textarea', 'css'], true)): ?>
          <textarea id="<?= h($id) ?>" name="<?= h($name) ?>" rows="<?= $type === 'css' ? 10 : 3 ?>" maxlength="<?= (int)$field['limit'] ?>"<?= $type === 'css' ? ' spellcheck="false" style="font-family:monospace"' : '' ?>><?= h((string)$value) ?></textarea>
        <?php elseif ($type === 'select'): ?>
          <select id="<?= h($id) ?>" name="<?= h($name) ?>"><?php foreach ($field['options'] as $option => $label): ?><option value="<?= h($option) ?>"<?= $value === $option ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
        <?php else: ?>
          <input id="<?= h($id) ?>" name="<?= h($name) ?>" type="<?= h($type === 'image' ? 'text' : $type) ?>" value="<?= h((string)$value) ?>"<?php if ($type === 'number'): ?> min="<?= (int)$field['min'] ?>" max="<?= (int)$field['max'] ?>" step="1" required<?php elseif (isset($field['limit'])): ?> maxlength="<?= (int)$field['limit'] ?>"<?php endif; ?>>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($field['hint'])): ?><p class="field-hint"><?= h($field['hint']) ?></p><?php endif; ?>
    </div>
    <?php
}

function argon_settings_page(): void
{
    require_admin();
    $values = argon_settings_values();
    if (isset($_SESSION['argon_settings_form']) && is_array($_SESSION['argon_settings_form'])) {
        $values = $_SESSION['argon_settings_form'];
        unset($_SESSION['argon_settings_form']);
    }
    $sections = [
        'appearance' => ['外观', '主题颜色、显示模式和卡片外观。'],
        'banner' => ['横幅', '首页横幅标题、背景与装饰。'],
        'sidebar' => ['侧栏与作者', '侧栏、个人资料、公告与社交链接。'],
        'posts' => ['文章', '列表摘要、阅读时间、文章目录与相邻文章。'],
        'footer' => ['页脚与自定义样式', '页脚文字、建站时间和自定义 CSS。'],
    ];
    ob_start();
    echo render_admin_content_start('Argon 主题设置', render_admin_sidebar('plugins'));
    ?>
      <form id="argon-settings-form" method="post" action="<?= h(argon_settings_url()) ?>"><?= csrf_field() ?><input type="hidden" name="operation" value="save"></form>
      <?php foreach ($sections as $section => [$label, $hint]): ?>
      <section class="panel">
        <div class="panel__header"><h2><?= h($label) ?></h2></div>
        <div class="panel__body">
          <p class="panel__meta"><?= h($hint) ?></p>
          <div class="form-stack">
          <?php foreach (argon_settings_schema() as $key => $field):
              if ($field['section'] !== $section) { continue; }
              // Fields in separate panels share one form so automatic tabs retain unsaved values.
              ob_start();
              argon_settings_field($key, $field, $values[$key]);
              $html = (string)ob_get_clean();
              echo preg_replace('/<(input|select|textarea)\b/', '<$1 form="argon-settings-form"', $html);
          endforeach; ?>
          </div>
        </div>
      </section>
      <?php endforeach; ?>
      <div class="form-actions"><button class="button" type="submit" form="argon-settings-form">保存设置</button><a class="button button--secondary" href="<?= h(url_for('admin_themes')) ?>">主题管理</a><a class="button button--secondary" href="<?= h(url_for('home')) ?>" target="_blank" rel="noopener">查看博客</a></div>
    <?php
    render_admin_content_end('Argon 主题设置', 'plugins', '配置 Argon 主题的外观与内容显示。');
}

add_plugin_action('request', static function (array $context): void {
    if (($context['action'] ?? '') !== 'admin_argon_settings') {
        return;
    }
    require_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verify_csrf();
        if (($_POST['operation'] ?? '') !== 'save' || !is_array($_POST['argon'] ?? null)) {
            set_flash('error', '设置表单无效，请刷新页面后重试。');
            redirect_to(argon_settings_url(), 303);
        }
        [$values, $errors] = argon_settings_validate($_POST['argon']);
        if ($errors) {
            $_SESSION['argon_settings_form'] = $values;
            set_flash('error', implode(' ', $errors));
            redirect_to(argon_settings_url(), 303);
        }
        save_settings(['argon_options' => json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        unset($_SESSION['argon_settings_form']);
        set_flash('success', 'Argon 主题设置已保存。');
        redirect_to(argon_settings_url(), 303);
    }
    argon_settings_page();
    exit;
});
