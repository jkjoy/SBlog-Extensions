<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

function sblog_lottery_editor_actions(string $html, array $context): string
{
    if (($context['field'] ?? '') !== 'content') {
        return $html;
    }

    return $html . '<button class="markdown-toolbar__button sblog-lottery-editor-open" type="button" data-lottery-open title="评论抽奖" aria-label="评论抽奖" aria-haspopup="dialog" aria-controls="sblog-lottery-editor"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><circle cx="12" cy="13" r="8"/><circle cx="12" cy="13" r="2"/><path d="M12 5v6m0 4v6M5.1 9l5.2 3m3.4 2 5.2 3M5.1 17l5.2-3m3.4-2 5.2-3"/><path d="M10 2h4l-2 4Z" fill="currentColor"/></svg></button>';
}

function sblog_lottery_editor_modal(string $html, array $context): string
{
    $postId = max(0, (int)($context['post_id'] ?? 0));
    $existing = $postId > 0 ? sblog_lottery_for_post($postId) : null;
    ob_start();
    ?>
    <link rel="stylesheet" href="<?= h(plugin_asset_url('comment-lottery', 'assets/style.css')) ?>">
    <dialog class="sblog-lottery-editor" id="sblog-lottery-editor" aria-labelledby="lottery-editor-title" aria-describedby="lottery-editor-hint" data-post-id="<?= h((string)$postId) ?>" data-existing-id="<?= h((string)($existing['id'] ?? '')) ?>">
      <div class="sblog-lottery-editor__header">
        <div><h2 id="lottery-editor-title">评论抽奖</h2><p id="lottery-editor-hint">从本篇文章在活动时间内通过审核的评论中随机抽取中奖评论。</p></div>
        <button class="button button--secondary" type="button" data-lottery-close aria-label="关闭抽奖设置">关闭</button>
      </div>
      <form class="form-stack" data-lottery-form action="<?= h(sblog_lottery_url('lottery_editor')) ?>" method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="id" value="">
        <input type="hidden" name="post_id" value="<?= h((string)$postId) ?>">
        <p class="sblog-lottery-editor__error" data-lottery-error role="alert" aria-live="assertive" hidden></p>
        <p class="sblog-lottery-editor__notice" data-lottery-locked hidden>抽奖已开始或已开奖，活动设置已锁定。可在插件管理中查看开奖和通知情况。</p>
        <fieldset class="sblog-lottery-editor__fields" data-lottery-fields>
          <legend class="sr-only">抽奖设置</legend>
          <div class="field"><label for="lottery-title">活动标题</label><input id="lottery-title" name="title" type="text" maxlength="80" required autocomplete="off" value="评论抽奖"></div>
          <div class="field"><label for="lottery-description">奖品与参与规则</label><textarea id="lottery-description" name="description" rows="3" maxlength="1000" placeholder="例如：抽取 3 位读者，各赠送一本图书。请填写可接收邮件的邮箱。"></textarea></div>
          <div class="field-grid">
            <div class="field"><label for="lottery-starts">开始时间</label><input id="lottery-starts" name="starts_at" type="datetime-local" required></div>
            <div class="field"><label for="lottery-ends">结束时间</label><input id="lottery-ends" name="ends_at" type="datetime-local" required></div>
          </div>
          <p class="field-hint">以上时间统一使用北京时间（Asia/Shanghai，UTC+8）。结束时间之前提交的合格评论可参与。</p>
          <div class="field"><label for="lottery-count">中奖评论数量</label><input id="lottery-count" name="winners_count" type="number" min="1" max="100" value="1" required><p class="field-hint">填写 1–100；合格评论不足时，按实际数量开奖。</p></div>
          <fieldset class="field settings-field">
            <legend>参与和开奖设置</legend>
            <div class="settings-option-list">
              <label class="setting-option"><input name="unique_email" type="checkbox" value="1" checked><span><strong>相同邮箱只参与一次</strong><small>同一邮箱多次评论保留一条，避免重复中奖。</small></span></label>
              <label class="setting-option"><input name="roots_only" type="checkbox" value="1"><span><strong>只抽取主评论</strong><small>默认包含回复；勾选后只抽取主评论。</small></span></label>
              <label class="setting-option"><input name="auto_draw" type="checkbox" value="1" checked><span><strong>结束后自动开奖</strong><small>到期后访问网站会触发；设置服务器定时任务可在无人访问时自动执行。</small></span></label>
              <label class="setting-option"><input name="notify" type="checkbox" value="1" checked><span><strong>邮件通知中奖人</strong><small>使用已启用的邮件通知插件及其 SMTP 配置。</small></span></label>
            </div>
          </fieldset>
        </fieldset>
        <p class="field-hint sblog-lottery-editor__save-hint">插入后请保存或发布文章，活动才会绑定到文章并生效。再次点击「评论抽奖」可修改未开始的活动；删除正文中的抽奖短代码可移除模块。开奖结果会在模块内公示，邮箱不会公开。<a href="<?= h(sblog_lottery_url('admin_lottery')) ?>" target="_blank" rel="noopener">查看抽奖管理与自动开奖设置</a>。</p>
        <div class="sblog-lottery-editor__actions"><button class="button button--secondary" type="button" data-lottery-close>取消</button><button class="button" type="submit" data-lottery-submit>插入抽奖</button></div>
      </form>
    </dialog>
    <script defer src="<?= h(plugin_asset_url('comment-lottery', 'assets/editor.js')) ?>"></script>
    <?php
    return $html . (string)ob_get_clean();
}
