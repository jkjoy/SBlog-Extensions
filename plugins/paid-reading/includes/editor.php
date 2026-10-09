<?php
declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

function pr_editor_values(array $context): array
{
    $action = (string)($context['action'] ?? $GLOBALS['sblog_current_action'] ?? '');
    $postIdValue = $context['post_id'] ?? ($action === 'edit' ? ($_GET['id'] ?? $_POST['id'] ?? 0) : 0);
    $postId = max(0, (int)pr_scalar($postIdValue));
    $record = $postId > 0 ? pr_content_for_post($postId) : null;
    $enabled = $record !== null;
    $price = $record !== null ? pr_format_money((int)$record['price_cents']) : (string)pr_settings()['default_price'];
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $enabled = ($_POST['paid_reading_enabled'] ?? '') === '1';
        $price = is_scalar($_POST['paid_reading_price'] ?? null) ? (string)$_POST['paid_reading_price'] : $price;
    }
    return ['enabled' => $enabled, 'price' => $price];
}

function pr_editor_toolbar(string $html, array $context): string
{
    if (!is_admin() || !in_array((string)($context['action'] ?? ''), ['write', 'edit'], true)) {
        return $html;
    }
    $toolbarStart = strpos($html, '<div class="markdown-toolbar"');
    $toolbarEnd = $toolbarStart !== false ? strpos($html, '</div>', $toolbarStart) : false;
    if ($toolbarEnd === false
        || str_contains(substr($html, $toolbarStart, $toolbarEnd - $toolbarStart), 'data-pr-editor-open')) {
        return $html;
    }
    $values = pr_editor_values($context);
    $label = h(sblog_t('付费阅读设置'));
    $controls = '<button class="markdown-toolbar__button pr-editor-open" type="button" data-pr-editor-open title="' . $label
        . '" aria-label="' . $label . '" aria-haspopup="dialog" aria-controls="pr-editor-dialog" aria-pressed="'
        . ($values['enabled'] ? 'true' : 'false') . '"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
        . '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V6a4 4 0 0 1 8 0v4"/><circle cx="12" cy="15" r="1"/><path d="M12 16v2"/></svg></button>'
        . '<input id="pr-editor-present" type="hidden" name="paid_reading_present" value="1">'
        . '<input id="pr-editor-enabled-value" type="hidden" name="paid_reading_enabled" value="' . ($values['enabled'] ? '1' : '0') . '">'
        . '<input id="pr-editor-price-value" type="hidden" name="paid_reading_price" value="' . h($values['price']) . '">';
    return substr_replace($html, $controls, $toolbarEnd, 0);
}

function pr_editor_modal(string $html, array $context): string
{
    if (!is_admin()) {
        return $html;
    }
    $values = pr_editor_values($context);
    ob_start(); ?>
    <link rel="stylesheet" href="<?= h(plugin_asset_url('paid-reading', 'assets/editor.css') . '?v=' . SBLOG_PAID_READING_VERSION) ?>">
    <dialog class="pr-editor-dialog" id="pr-editor-dialog" aria-labelledby="pr-editor-title" aria-describedby="pr-editor-save-hint">
      <div class="pr-editor-dialog__header">
        <h2 id="pr-editor-title"><?= h(sblog_t('付费阅读设置')) ?></h2>
        <button class="button button--secondary" type="button" data-pr-editor-close aria-label="<?= h(sblog_t('关闭付费阅读设置')) ?>"><?= h(sblog_t('关闭')) ?></button>
      </div>
      <div class="form-stack" data-pr-editor-controls>
        <label class="setting-option" for="pr-editor-enabled">
          <input id="pr-editor-enabled" type="checkbox"<?= $values['enabled'] ? ' checked' : '' ?>>
          <span><strong><?= h(sblog_t('启用付费阅读')) ?></strong><small><?= h(sblog_t('保存文章或页面后，读者需付款才能阅读付费正文。关闭后正文恢复公开阅读。')) ?></small></span>
        </label>
        <div class="field">
          <label for="pr-editor-price"><?= h(sblog_t('阅读价格（元）')) ?></label>
          <input id="pr-editor-price" type="number" min="0.01" max="1000000" step="0.01" value="<?= h($values['price']) ?>" required<?= !$values['enabled'] ? ' disabled' : '' ?> aria-describedby="pr-editor-price-hint pr-editor-error">
          <p class="field-hint" id="pr-editor-price-hint"><?= h(sblog_t('人民币 0.01 至 1000000.00 元，最多两位小数。')) ?></p>
        </div>
        <p class="pr-editor-dialog__error" id="pr-editor-error" data-pr-editor-error role="alert" aria-live="assertive" hidden></p>
        <p class="field-hint pr-editor-dialog__preview-hint"><?= h(sblog_t('用 <!-- paid-reading --> 分隔免费预览与付费正文；未分隔则整篇付费。摘要仅使用免费预览。')) ?></p>
        <p class="field-hint" id="pr-editor-save-hint"><?= h(sblog_t('点击「应用设置」只更新当前编辑器，请随后保存文章或页面使设置生效。取消或关闭窗口不会应用更改。')) ?></p>
        <div class="pr-editor-dialog__actions"><button class="button button--secondary" type="button" data-pr-editor-close><?= h(sblog_t('取消')) ?></button><button class="button" type="button" data-pr-editor-apply><?= h(sblog_t('应用设置')) ?></button></div>
      </div>
    </dialog>
    <script defer src="<?= h(plugin_asset_url('paid-reading', 'assets/editor.js') . '?v=' . SBLOG_PAID_READING_VERSION) ?>"></script>
    <?php return $html . (string)ob_get_clean();
}
