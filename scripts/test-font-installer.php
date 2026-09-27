<?php

declare(strict_types=1);

$fontTestRoot = sys_get_temp_dir() . '/sblog-font-installer-test-' . bin2hex(random_bytes(6));
if (!mkdir($fontTestRoot, 0700, true)) {
    throw new RuntimeException('Could not create font installer test directory.');
}

define('PLUGINS_DIR', dirname(__DIR__) . '/plugins');
define('UPLOAD_DIR', $fontTestRoot . '/uploads');
$GLOBALS['font_test_db'] = new PDO('sqlite::memory:');
$GLOBALS['font_test_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function db(): PDO { return $GLOBALS['font_test_db']; }
function all_rows(string $sql, array $params = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function add_plugin_action(string $hook, callable $callback): void {}
function add_plugin_filter(string $hook, callable $callback): void {}
function asset_url(string $path): string { return '/' . ltrim($path, '/'); }
function plugin_asset_url(string $slug, string $path): string { return '/plugins/' . $slug . '/' . $path; }
function h(string|int|float|bool|null $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function sblog_t(string $message): string { return $message; }
function sblog_tn(string $message, int $count): string { return str_replace('{count}', (string)$count, $message); }
function str_sub_u(string $value, int $start, int $length): string { return mb_substr($value, $start, $length, 'UTF-8'); }
function require_admin(): void {}
function script_url(): string { return '/index.php'; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="test">'; }
function render_admin_sidebar(string $active): string { return '<nav>' . h($active) . '</nav>'; }
function render_admin_topbar(string $title): string { return '<header>' . h($title) . '</header>'; }
function render_layout(string $title, string $content, array $options): void
{
    echo '<!doctype html><html><head></head><body class="theme-admin">' . $content . '</body></html>';
}
function font_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require dirname(__DIR__) . '/plugins/font-installer/plugin.php';

try {
    $realFont = dirname(__DIR__) . '/themes/timellow/assets/fonts/lxgw.woff2';
    font_test_assert(sblog_font_installer_detect_format($realFont, 'sample.woff2') === 'woff2', 'Valid WOFF2 was rejected.');
    font_test_assert(sblog_font_installer_detect_format($realFont, 'sample.ttf') === '', 'Mismatched extension was accepted.');

    $invalid = $fontTestRoot . '/invalid.woff2';
    file_put_contents($invalid, 'wOF2' . str_repeat("\0", 44));
    font_test_assert(sblog_font_installer_detect_format($invalid, 'invalid.woff2') === '', 'Truncated WOFF2 was accepted.');
    $fake = $fontTestRoot . '/fake.ttf';
    file_put_contents($fake, "\x00\x01\x00\x00" . str_repeat("\0", 8));
    font_test_assert(sblog_font_installer_detect_format($fake, 'fake.ttf') === '', 'Invalid TTF table directory was accepted.');
    foreach ([UPLOAD_ERR_NO_FILE => '请选择', UPLOAD_ERR_PARTIAL => '上传失败'] as $error => $expected) {
        try {
            sblog_font_installer_upload(['error' => $error]);
            throw new RuntimeException('Upload error was accepted.');
        } catch (DomainException $exception) {
            font_test_assert(str_contains($exception->getMessage(), $expected), 'Upload error message is misleading.');
        }
    }

    $directory = UPLOAD_DIR . '/font-installer';
    mkdir($directory, 0700, true);
    $id = str_repeat('a', 24);
    $file = $directory . '/' . $id . '.woff2';
    copy($realFont, $file);
    sblog_font_installer_ensure_table();
    db()->prepare('INSERT INTO font_installer_fonts(id, name, format, bytes, created_at, active) VALUES(?, ?, ?, ?, ?, 1)')
        ->execute([$id, '测试字体', 'woff2', filesize($file), time()]);

    $active = sblog_font_installer_active();
    font_test_assert($active !== null && $active['id'] === $id, 'Active font was not loaded.');
    $public = '<!doctype html><html><head></head><body class="theme-public"></body></html>';
    $styled = sblog_font_installer_output_html($public, ['action' => 'home']);
    font_test_assert(str_contains($styled, 'id="sblog-font-installer"'), 'Public CSS was not injected.');
    font_test_assert(str_contains($styled, '/uploads/font-installer/' . $id . '.woff2'), 'Public font URL is wrong.');
    font_test_assert(strpos($styled, 'sblog-font-installer') < strpos($styled, '</head>'), 'Font CSS is outside head.');
    font_test_assert(sblog_font_installer_output_html('<html><head></head><body class="theme-admin"></body></html>', ['action' => 'admin'])
        === '<html><head></head><body class="theme-admin"></body></html>', 'Admin font was changed.');

    ob_start();
    sblog_font_installer_render_settings();
    $settingsPage = (string)ob_get_clean();
    font_test_assert(str_contains($settingsPage, 'font-installer-file'), 'Upload control is missing.');
    font_test_assert(str_contains($settingsPage, '恢复主题默认字体'), 'Reset control is missing.');
    font_test_assert(str_contains($settingsPage, '删除'), 'Delete control is missing.');
    $adminStyled = sblog_font_installer_output_html($settingsPage, ['action' => 'admin_font_installer']);
    font_test_assert(str_contains($adminStyled, '/plugins/font-installer/assets/admin.css'), 'Admin stylesheet is missing.');

    sblog_font_installer_select('');
    font_test_assert(sblog_font_installer_active() === null && is_file($file), 'Reset removed the uploaded file.');
    font_test_assert(sblog_font_installer_output_html($public, ['action' => 'home']) === $public, 'Reset did not restore theme font.');
    sblog_font_installer_select($id);
    font_test_assert(sblog_font_installer_active() !== null, 'Stored font could not be reapplied.');
    unlink($file);
    font_test_assert(sblog_font_installer_active() === null, 'Missing file did not restore theme font.');
    ob_start();
    sblog_font_installer_render_settings();
    $missingPage = (string)ob_get_clean();
    font_test_assert(str_contains($missingPage, '文件丢失'), 'Missing file status is not shown.');
    copy($realFont, $file);
    sblog_font_installer_delete($id);
    font_test_assert(!is_file($file) && sblog_font_installer_fonts() === [], 'Delete did not remove the font and record.');

    echo "Font installer self-test passed.\n";
} finally {
    foreach ([$fontTestRoot . '/invalid.woff2', $fontTestRoot . '/fake.ttf', UPLOAD_DIR . '/font-installer/' . str_repeat('a', 24) . '.woff2'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir(UPLOAD_DIR . '/font-installer')) { rmdir(UPLOAD_DIR . '/font-installer'); }
    if (is_dir(UPLOAD_DIR)) { rmdir(UPLOAD_DIR); }
    rmdir($fontTestRoot);
}
