<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

const SBLOG_FONT_INSTALLER_MAX_BYTES = 31457280;

function sblog_font_installer_formats(): array
{
    return [
        'woff2' => ['signature' => 'wOF2', 'css' => 'woff2'],
        'woff' => ['signature' => 'wOFF', 'css' => 'woff'],
        'ttf' => ['signature' => "\x00\x01\x00\x00", 'css' => 'truetype'],
        'otf' => ['signature' => 'OTTO', 'css' => 'opentype'],
    ];
}

function sblog_font_installer_detect_format(string $path, string $name): string
{
    $extension = strtolower(pathinfo(str_replace('\\', '/', $name), PATHINFO_EXTENSION));
    $format = sblog_font_installer_formats()[$extension] ?? null;
    $header = @file_get_contents($path, false, null, 0, 48);
    $bytes = @filesize($path);
    if (!is_array($format) || !is_string($header) || !is_int($bytes)
        || substr($header, 0, 4) !== $format['signature']) {
        return '';
    }
    if ($extension === 'woff' || $extension === 'woff2') {
        $minimum = $extension === 'woff2' ? 48 : 44;
        if (strlen($header) < $minimum || $bytes < $minimum) {
            return '';
        }
        $declaredBytes = unpack('N', substr($header, 8, 4))[1];
        $tables = unpack('n', substr($header, 12, 2))[1];
        return $declaredBytes === $bytes && $tables > 0 ? $extension : '';
    }
    if (strlen($header) < 12) {
        return '';
    }
    $tables = unpack('n', substr($header, 4, 2))[1];
    return $tables > 0 && $bytes >= 12 + 16 * $tables ? $extension : '';
}

function sblog_font_installer_ensure_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    db()->exec(
        "CREATE TABLE IF NOT EXISTS font_installer_fonts (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            format TEXT NOT NULL,
            bytes INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            active INTEGER NOT NULL DEFAULT 0
        )"
    );
    $done = true;
}

function sblog_font_installer_fonts(): array
{
    sblog_font_installer_ensure_table();
    return all_rows('SELECT id, name, format, bytes, created_at, active FROM font_installer_fonts ORDER BY created_at DESC, rowid DESC');
}

function sblog_font_installer_file(array $font): string
{
    $id = (string)($font['id'] ?? '');
    $format = (string)($font['format'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/', $id) || !isset(sblog_font_installer_formats()[$format])) {
        return '';
    }
    return UPLOAD_DIR . '/font-installer/' . $id . '.' . $format;
}

function sblog_font_installer_url(array $font): string
{
    $file = sblog_font_installer_file($font);
    if ($file === '' || !is_file($file) || is_link($file)) {
        return '';
    }
    return asset_url('uploads/font-installer/' . basename($file));
}

function sblog_font_installer_active(): ?array
{
    sblog_font_installer_ensure_table();
    $rows = all_rows('SELECT id, name, format, bytes, created_at, active FROM font_installer_fonts WHERE active = 1 LIMIT 1');
    $font = $rows[0] ?? null;
    return is_array($font) && sblog_font_installer_url($font) !== '' ? $font : null;
}

function sblog_font_installer_css_url(string $url): string
{
    return '"' . str_replace(['\\', '"', '<', "\n", "\r"], ['\\\\', '\\"', '\\3c ', '', ''], $url) . '"';
}

function sblog_font_installer_face(array $font, string $family): string
{
    $url = sblog_font_installer_url($font);
    $format = sblog_font_installer_formats()[(string)$font['format']]['css'] ?? '';
    if ($url === '' || $format === '') {
        return '';
    }
    return '@font-face{font-family:"' . $family . '";src:url(' . sblog_font_installer_css_url($url) . ') format("' . $format . '");font-style:normal;font-weight:400;font-display:swap;}';
}

function sblog_font_installer_public_css(array $font): string
{
    $face = sblog_font_installer_face($font, 'SBlog Custom Font');
    if ($face === '') {
        return '';
    }
    $family = '"SBlog Custom Font",Arial,"PingFang SC",sans-serif';
    return $face
        . ':root{--font-sans:' . $family . ';--font-serif:' . $family . ';}'
        . 'body.theme-public{--site-font-family:' . $family . ' !important;font-family:' . $family . ' !important;}'
        . 'body.theme-public :is(h1,h2,h3,h4,h5,h6,p,blockquote,figcaption,li,dt,dd,th,td,a,button,input,textarea,select,label,summary,strong,em,small){font-family:' . $family . ' !important;}';
}

function sblog_font_installer_output_html(string $html, array $context): string
{
    $headEnd = stripos($html, '</head>');
    if ($headEnd === false) {
        return $html;
    }
    if ((string)($context['action'] ?? '') === 'admin_font_installer') {
        $cssFile = __DIR__ . '/assets/admin.css';
        $href = plugin_asset_url('font-installer', 'assets/admin.css') . '?v=' . filemtime($cssFile);
        $head = '<link rel="stylesheet" href="' . h($href) . '">';
        $active = sblog_font_installer_active();
        if ($active !== null) {
            $head .= '<style>' . sblog_font_installer_face($active, 'SBlog Font Preview') . '</style>';
        }
        return substr_replace($html, $head, $headEnd, 0);
    }
    if (!str_contains($html, 'theme-public')) {
        return $html;
    }
    $active = sblog_font_installer_active();
    if ($active === null) {
        return $html;
    }
    $css = sblog_font_installer_public_css($active);
    return substr_replace($html, '<style id="sblog-font-installer">' . $css . '</style>', $headEnd, 0);
}

function sblog_font_installer_upload(array $upload): void
{
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sblog_t('字体文件超过服务器上传限制。'),
            UPLOAD_ERR_NO_FILE => sblog_t('请选择要上传的字体文件。'),
            default => sblog_t('字体上传失败，请重试或检查服务器上传配置。'),
        };
        throw new DomainException($message);
    }
    $source = $upload['tmp_name'] ?? null;
    $original = $upload['name'] ?? null;
    if (!is_string($source) || !is_string($original) || !is_uploaded_file($source)) {
        throw new DomainException(sblog_t('上传文件无效。'));
    }
    $bytes = filesize($source);
    if ($bytes === false || $bytes < 4 || $bytes > SBLOG_FONT_INSTALLER_MAX_BYTES) {
        throw new DomainException(sblog_t('字体文件不能超过 30 MB。'));
    }
    $format = sblog_font_installer_detect_format($source, $original);
    if ($format === '') {
        throw new DomainException(sblog_t('仅支持格式匹配的 WOFF2、WOFF、TTF 或 OTF 字体。'));
    }
    $name = pathinfo(str_replace('\\', '/', $original), PATHINFO_FILENAME);
    $name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
    $name = str_sub_u($name, 0, 80);
    if ($name === '') {
        $name = sblog_t('未命名字体');
    }
    $directory = UPLOAD_DIR . '/font-installer';
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0755, true))) {
        throw new DomainException(sblog_t('无法创建字体存储目录。'));
    }
    $id = bin2hex(random_bytes(12));
    $target = $directory . '/' . $id . '.' . $format;
    if (!move_uploaded_file($source, $target)) {
        throw new DomainException(sblog_t('保存字体文件失败。'));
    }
    try {
        sblog_font_installer_ensure_table();
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->exec('UPDATE font_installer_fonts SET active = 0 WHERE active = 1');
        $statement = $pdo->prepare('INSERT INTO font_installer_fonts(id, name, format, bytes, created_at, active) VALUES(?, ?, ?, ?, ?, 1)');
        $statement->execute([$id, $name, $format, $bytes, time()]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!unlink($target)) {
            error_log('Font installer could not remove an unregistered font: ' . $target);
        }
        throw $exception;
    }
}

function sblog_font_installer_select(string $id): void
{
    sblog_font_installer_ensure_table();
    if ($id !== '') {
        $rows = all_rows('SELECT id, format FROM font_installer_fonts WHERE id = ?', [$id]);
        if (!isset($rows[0]) || sblog_font_installer_url($rows[0]) === '') {
            throw new DomainException(sblog_t('所选字体不存在或文件已丢失。'));
        }
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE font_installer_fonts SET active = 0 WHERE active = 1');
        if ($id !== '') {
            $statement = $pdo->prepare('UPDATE font_installer_fonts SET active = 1 WHERE id = ?');
            $statement->execute([$id]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function sblog_font_installer_delete(string $id): void
{
    sblog_font_installer_ensure_table();
    $rows = all_rows('SELECT id, format FROM font_installer_fonts WHERE id = ?', [$id]);
    $font = $rows[0] ?? null;
    if (!is_array($font)) {
        throw new DomainException(sblog_t('所选字体不存在。'));
    }
    $file = sblog_font_installer_file($font);
    if ($file === '' || is_link($file) || (is_file($file) && !unlink($file))) {
        throw new DomainException(sblog_t('无法删除服务器上的字体文件。'));
    }
    $statement = db()->prepare('DELETE FROM font_installer_fonts WHERE id = ?');
    $statement->execute([$id]);
}

function sblog_font_installer_render_settings(): void
{
    require_admin();
    $fonts = sblog_font_installer_fonts();
    $active = sblog_font_installer_active();
    $action = script_url() . '?a=font_installer_action';
    ob_start();
    ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main">
        <?= render_admin_topbar(sblog_t('字体安装器')) ?>
        <section class="panel admin-list-panel font-installer-panel">
          <div class="panel__header"><h2><?= h(sblog_t('当前网站字体')) ?></h2><span class="status-badge <?= $active ? 'status-badge--published' : 'status-badge--draft' ?>"><?= h($active ? sblog_t('自定义字体已启用') : sblog_t('使用主题默认字体')) ?></span></div>
          <div class="panel__body">
            <div class="font-installer-preview<?= $active ? ' is-custom' : '' ?>">
              <p class="font-installer-preview__name"><?= h($active ? (string)$active['name'] : sblog_t('主题默认字体')) ?></p>
              <p class="font-installer-preview__sample" lang="zh-CN">字有温度，文章自有节奏。</p>
              <p class="font-installer-preview__latin" lang="en">The quick brown fox jumps over the lazy dog. 0123456789</p>
            </div>
            <?php if ($active): ?>
              <form class="font-installer-reset" method="post" action="<?= h($action) ?>">
                <?= csrf_field() ?><input type="hidden" name="operation" value="default">
                <button class="button button--secondary" type="submit"><?= h(sblog_t('恢复主题默认字体')) ?></button>
              </form>
            <?php endif; ?>
          </div>
        </section>
        <section class="panel admin-list-panel font-installer-panel">
          <div class="panel__header"><h2><?= h(sblog_t('上传字体')) ?></h2></div>
          <div class="panel__body">
            <form class="font-installer-upload" method="post" action="<?= h($action) ?>" enctype="multipart/form-data">
              <?= csrf_field() ?><input type="hidden" name="operation" value="upload">
              <div class="field"><label for="font-installer-file"><?= h(sblog_t('字体文件')) ?></label><input id="font-installer-file" type="file" name="font" accept=".woff2,.woff,.ttf,.otf" required><p class="field-hint"><?= h(sblog_t('支持 WOFF2、WOFF、TTF、OTF，最大 30 MB。上传后立即应用于所有前台主题；代码和图标字体保持不变。')) ?></p></div>
              <button class="button" type="submit"><?= h(sblog_t('上传并应用')) ?></button>
            </form>
          </div>
        </section>
        <section class="panel admin-list-panel font-installer-panel">
          <div class="panel__header"><h2><?= h(sblog_t('已上传字体')) ?></h2><span class="panel__meta"><?= h(sblog_tn('{count} 个字体', count($fonts))) ?></span></div>
          <div class="panel__body panel__body--flush">
            <?php if (!$fonts): ?>
              <div class="empty-state empty-state--inside"><p><?= h(sblog_t('还没有上传字体。')) ?></p></div>
            <?php else: ?>
              <div class="font-installer-list">
                <?php foreach ($fonts as $font): ?>
                  <?php $available = sblog_font_installer_url($font) !== ''; ?>
                  <div class="font-installer-row">
                    <div class="font-installer-row__main">
                      <strong><?= h((string)$font['name']) ?></strong>
                      <span><?= h(strtoupper((string)$font['format'])) ?> · <?= h(number_format((int)$font['bytes'] / 1048576, 2)) ?> MB</span>
                      <?php if (!$available): ?><span class="status-badge status-badge--draft"><?= h(sblog_t('文件丢失')) ?></span><?php elseif ((int)$font['active'] === 1): ?><span class="status-badge status-badge--published"><?= h(sblog_t('正在使用')) ?></span><?php endif; ?>
                    </div>
                    <div class="font-installer-row__actions">
                      <?php if ((int)$font['active'] !== 1 && $available): ?>
                        <form method="post" action="<?= h($action) ?>"><?= csrf_field() ?><input type="hidden" name="operation" value="apply"><input type="hidden" name="id" value="<?= h((string)$font['id']) ?>"><button class="button button--secondary" type="submit"><?= h(sblog_t('应用')) ?></button></form>
                      <?php endif; ?>
                      <form method="post" action="<?= h($action) ?>" onsubmit="return confirm(<?= h(json_encode(sblog_t('确定删除这个字体吗？服务器上的字体文件也会删除。'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>);">
                        <?= csrf_field() ?><input type="hidden" name="operation" value="delete"><input type="hidden" name="id" value="<?= h((string)$font['id']) ?>"><button class="button button--danger" type="submit"><?= h(sblog_t('删除')) ?></button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>
    <?php
    render_layout(sblog_t('字体安装器'), (string)ob_get_clean(), [
        'active' => 'plugins',
        'wide' => true,
        'description' => sblog_t('管理网站自定义字体'),
    ]);
}

function sblog_font_installer_handle_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    if ($action === 'admin_font_installer') {
        sblog_font_installer_render_settings();
        exit;
    }
    if ($action !== 'font_installer_action') {
        return;
    }
    $returnUrl = script_url() . '?a=admin_font_installer';
    require_admin_post($returnUrl);
    try {
        $operation = (string)($_POST['operation'] ?? '');
        if ($operation === 'upload') {
            sblog_font_installer_upload(is_array($_FILES['font'] ?? null) ? $_FILES['font'] : []);
            $message = sblog_t('字体已上传并应用。');
        } elseif ($operation === 'apply') {
            $id = (string)($_POST['id'] ?? '');
            if ($id === '') {
                throw new DomainException(sblog_t('请选择要应用的字体。'));
            }
            sblog_font_installer_select($id);
            $message = sblog_t('网站字体已切换。');
        } elseif ($operation === 'default') {
            sblog_font_installer_select('');
            $message = sblog_t('已恢复主题默认字体。');
        } elseif ($operation === 'delete') {
            sblog_font_installer_delete((string)($_POST['id'] ?? ''));
            $message = sblog_t('字体及服务器文件已删除。');
        } else {
            throw new DomainException(sblog_t('无效的字体操作。'));
        }
        set_flash('success', $message);
    } catch (DomainException $exception) {
        set_flash('error', $exception->getMessage());
    } catch (Throwable $exception) {
        error_log('Font installer operation failed: ' . $exception->getMessage());
        set_flash('error', sblog_t('字体操作失败，请检查服务器日志。'));
    }
    redirect_to($returnUrl);
}

add_plugin_action('request', 'sblog_font_installer_handle_request');
add_plugin_filter('output_html', 'sblog_font_installer_output_html');
