<?php
declare(strict_types=1);

const PR_CONTENT_SEPARATOR = '<!-- paid-reading -->';

function pr_reference(string $markdown): ?string
{
    return preg_match('/\[sblog-paid:([a-f0-9]{32})\]/', $markdown, $matches) === 1 ? $matches[1] : null;
}

function pr_record(string $reference): ?array
{
    return preg_match('/^[a-f0-9]{32}$/D', $reference) === 1
        ? one('SELECT * FROM sblog_paid_reading_content WHERE reference = ?', [$reference])
        : null;
}

function pr_source_markdown(string $markdown, int $postId): string
{
    $reference = pr_reference($markdown);
    if ($reference === null) {
        return $markdown;
    }
    $record = pr_record($reference);
    $existing = $postId > 0 ? one('SELECT content FROM posts WHERE id = ?', [$postId]) : null;
    if ($record === null || $existing === null || pr_reference((string)$existing['content']) !== $reference) {
        pr_error('付费内容引用无效，请重新打开文章编辑器。', 409);
    }
    if (trim($markdown) !== trim((string)$record['public_markdown'])) {
        pr_error('请在站点编辑器中修改付费文章正文。', 409);
    }
    return (string)$record['source_markdown'];
}

function pr_before_save(array $data, array $context): array
{
    // Core catches filter exceptions and otherwise persists the unfiltered body.
    // Handle every failure here so a failed private write cannot publish it.
    try {
        $postId = (int)($context['post_id'] ?? 0);
        $markdown = (string)($data['content'] ?? '');
        $existing = $postId > 0 ? one('SELECT content FROM posts WHERE id = ?', [$postId]) : null;
        $oldReference = $existing !== null ? pr_reference((string)$existing['content']) : null;
        $browserEditor = in_array((string)($GLOBALS['sblog_current_action'] ?? ''), ['write', 'edit'], true)
            && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
            && is_admin() && (string)($_POST['paid_reading_present'] ?? '') === '1';

        if (!$browserEditor) {
            if ($oldReference !== null && pr_reference($markdown) !== $oldReference) {
                pr_error('付费文章正文请在站点编辑器中修改，接口仅支持保留正文的更新。', 409);
            }
            if (pr_reference($markdown) !== null) {
                // Preserve metadata-only API updates and stale editor submissions.
                $record = pr_record((string)pr_reference($markdown));
                if ($record === null || $oldReference !== (string)$record['reference']
                    || trim($markdown) !== trim((string)$record['public_markdown'])) {
                    pr_error('付费内容引用无效，请重新打开文章编辑器。', 409);
                }
                $data['excerpt'] = derive_excerpt(str_replace('[sblog-paid:' . $record['reference'] . ']', '', $markdown));
            }
            return $data;
        }

        $enabled = (string)($_POST['paid_reading_enabled'] ?? '') === '1';
        $markdown = pr_source_markdown($markdown, $postId);
        if (!$enabled) {
            $data['content'] = str_replace(PR_CONTENT_SEPARATOR, '', $markdown);
            // An old public-only excerpt remains safe when deliberately publishing.
            return $data;
        }
        $price = pr_money_cents(trim((string)($_POST['paid_reading_price'] ?? '')));
        if ($price === null) {
            pr_error('阅读价格必须为 0.01 至 1000000.00 元，最多两位小数。', 422);
        }
        if (substr_count($markdown, PR_CONTENT_SEPARATOR) > 1) {
            pr_error('每篇文章只能设置一个付费阅读分隔符。', 422);
        }
        $parts = explode(PR_CONTENT_SEPARATOR, $markdown, 2);
        $public = count($parts) === 2 ? trim($parts[0]) : '';
        $paid = trim(count($parts) === 2 ? $parts[1] : $markdown);
        $inCode = false;
        $inReply = false;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $public)) as $line) {
            if (preg_match('/^```([\w-]+)?\s*$/', $line) === 1) {
                $inCode = !$inCode;
            } elseif (!$inCode && trim($line) === '[reply]') {
                $inReply = true;
            } elseif (!$inCode && trim($line) === '[/reply]') {
                $inReply = false;
            }
        }
        if ($inCode) {
            pr_error('请把付费分隔符放在代码块外，并闭合免费预览中的代码块。', 422);
        }
        if ($inReply) {
            pr_error('请把付费分隔符放在回复可见区域外。', 422);
        }
        if ($paid === '') {
            pr_error('付费阅读内容不能为空。', 422);
        }
        $reference = bin2hex(random_bytes(16));
        $stored = ($public !== '' ? $public . "\n\n" : '') . '[sblog-paid:' . $reference . ']';
        q('INSERT INTO sblog_paid_reading_content(reference, post_id, source_markdown, public_markdown, paid_markdown, price_cents, updated_at) VALUES(?,?,?,?,?,?,?)',
            [$reference, $postId > 0 ? $postId : null, $markdown, $stored, $paid, $price, time()]);
        $data['content'] = $stored;
        // Core derives the original excerpt before this hook. Never keep that
        // automatically generated excerpt or an arbitrary secret-bearing one.
        $data['excerpt'] = $public !== '' ? derive_excerpt($public) : '此内容为付费阅读。';
        return $data;
    } catch (Throwable $exception) {
        error_log('Paid reading content protection failed: ' . $exception->getMessage());
        pr_error('付费内容暂时无法安全保存，请稍后重试。', 503);
    }
}

function pr_post_saved(array $context): void
{
    $postId = (int)($context['post_id'] ?? 0);
    $reference = pr_reference((string)($context['data']['content'] ?? ''));
    if ($postId > 0 && $reference !== null) {
        q('UPDATE sblog_paid_reading_content SET post_id = ? WHERE reference = ? AND (post_id IS NULL OR post_id = ?)',
            [$postId, $reference, $postId]);
    }
}

function pr_current_detail_post(string $action): ?array
{
    if (!in_array($action, ['post', 'page'], true)) {
        return null;
    }
    $identifier = $_GET['slug'] ?? $_GET['id'] ?? '';
    if (!is_scalar($identifier) || trim((string)$identifier) === '') {
        return null;
    }
    return fetch_content_by_identifier($action === 'page' ? 'page' : 'post', trim((string)$identifier), is_admin());
}

function pr_protect_request(array $context): void
{
    try {
        $post = pr_current_detail_post((string)($context['action'] ?? ''));
        if ($post === null || pr_reference((string)$post['content']) === null) {
            return;
        }
        pr_buyer_hash(true);
        pr_no_store();
        // The static cache checks public session keys before reading any file.
        // A temporary key bypasses both old cached pages and cache writes.
        $_SESSION['paid_reading_dynamic'] = true;
        $GLOBALS['paid_reading_cache_guard'] = true;
        $GLOBALS['paid_reading_detail_post'] = $post;
        if (function_exists('spc_mark_bypass')) {
            spc_mark_bypass();
        }
    } catch (Throwable $exception) {
        error_log('Paid reading request protection failed: ' . $exception->getMessage());
        pr_error('付费内容暂时不可用。', 503);
    }
}

function pr_release_cache_guard(array $context = []): void
{
    if (!empty($GLOBALS['paid_reading_cache_guard'])) {
        unset($_SESSION['paid_reading_dynamic'], $GLOBALS['paid_reading_cache_guard']);
    }
}

function pr_restore_editor_source(string $html): string
{
    return (string)preg_replace_callback('~(<textarea\b(?=[^>]*\bid="content")(?=[^>]*\bname="content")[^>]*>)(.*?)(</textarea>)~s',
        static function (array $matches): string {
            $markdown = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $reference = pr_reference($markdown);
            if ($reference === null) {
                return $matches[0];
            }
            $record = pr_record($reference);
            // Preserve submitted validation-error text if it has been modified.
            if ($record === null || trim($markdown) !== trim((string)$record['public_markdown'])) {
                return $matches[0];
            }
            return $matches[1] . h((string)$record['source_markdown']) . $matches[3];
        }, $html);
}

function pr_filter_rest_item(array $item): array
{
    if (isset($item['previous']) && is_array($item['previous'])) {
        $item['previous'] = pr_filter_rest_item($item['previous']);
    }
    if (!isset($item['id'], $item['content']) || !is_array($item['content'])) {
        return $item;
    }
    $reference = pr_reference((string)($item['content']['raw'] ?? $item['content']['rendered'] ?? ''));
    if ($reference === null) {
        return $item;
    }
    $record = pr_content_for_post((int)$item['id']);
    if ($record === null || (string)$record['reference'] !== $reference) {
        return $item;
    }
    $item['content']['protected'] = true;
    $item['excerpt']['protected'] = true;
    $item['content']['rendered'] = preg_replace('/\[sblog-paid:[a-f0-9]{32}\]/', '此内容为付费阅读，请访问文章页面解锁。', (string)($item['content']['rendered'] ?? ''));
    pr_no_store();
    return $item;
}

function pr_filter_output(string $html, array $context): string
{
    pr_release_cache_guard();
    $action = (string)($context['action'] ?? '');
    if (in_array($action, ['write', 'edit'], true) && is_admin()) {
        pr_no_store();
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
            ? pr_restore_editor_source($html) : $html;
    }
    if ($action === 'sblog_rest_api' && str_contains($html, '[sblog-paid:')) {
        $payload = json_decode($html, true);
        if (!is_array($payload)) {
            return $html;
        }
        $payload = array_is_list($payload)
            ? array_map('pr_filter_rest_item', $payload)
            : pr_filter_rest_item($payload);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($encoded) ? $encoded : $html;
    }
    if (!str_contains($html, '[sblog-paid:')) {
        return $html;
    }
    $post = $GLOBALS['paid_reading_detail_post'] ?? null;
    return (string)preg_replace_callback('~<p>\s*\[sblog-paid:([a-f0-9]{32})\]\s*</p>~',
        static function (array $matches) use ($post): string {
            if (!is_array($post) || pr_reference((string)$post['content']) !== $matches[1]) {
                return '<p class="paid-reading-notice">此内容为付费阅读，请进入文章页面查看。</p>';
            }
            $record = pr_record($matches[1]);
            if ($record === null) {
                return '<p class="paid-reading-notice">付费内容暂时不可用。</p>';
            }
            if (is_admin() || pr_can_read((int)$post['id'])) {
                $privatePost = $post;
                $privatePost['content'] = (string)$record['paid_markdown'];
                $link = !is_admin() ? '<p class="paid-reading-reader-link"><a href="'
                    . h(pr_reader_url((int)$post['id'])) . '">我的已购文章 · 绑定或恢复购买邮箱</a></p>' : '';
                return '<section class="paid-reading-unlocked">' . render_content_html($privatePost) . '</section>' . $link;
            }
            return pr_paywall($post, $record);
        }, $html);
}
