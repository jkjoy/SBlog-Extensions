<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

function sblog_lottery_init(): void
{
    static $initialized = null;
    $database = db();
    if ($initialized === $database) {
        return;
    }
    $database->exec("CREATE TABLE IF NOT EXISTS plugin_comment_lotteries (
        id TEXT PRIMARY KEY,
        post_id INTEGER REFERENCES posts(id) ON DELETE CASCADE,
        title TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        starts_at INTEGER NOT NULL,
        ends_at INTEGER NOT NULL,
        winners_count INTEGER NOT NULL,
        unique_email INTEGER NOT NULL DEFAULT 1,
        auto_draw INTEGER NOT NULL DEFAULT 1,
        notify INTEGER NOT NULL DEFAULT 1,
        roots_only INTEGER NOT NULL DEFAULT 0,
        enabled INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'pending',
        candidate_count INTEGER NOT NULL DEFAULT 0,
        drawn_at INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )");
    $database->exec('CREATE INDEX IF NOT EXISTS plugin_comment_lottery_due ON plugin_comment_lotteries(status, enabled, auto_draw, ends_at)');
    $database->exec("CREATE TABLE IF NOT EXISTS plugin_comment_lottery_winners (
        lottery_id TEXT NOT NULL REFERENCES plugin_comment_lotteries(id) ON DELETE CASCADE,
        comment_id INTEGER NOT NULL,
        position INTEGER NOT NULL,
        author_name TEXT NOT NULL,
        author_email TEXT NOT NULL,
        content TEXT NOT NULL,
        mail_status TEXT NOT NULL DEFAULT 'pending',
        mail_attempts INTEGER NOT NULL DEFAULT 0,
        mail_attempted_at INTEGER NOT NULL DEFAULT 0,
        mail_sent_at INTEGER NOT NULL DEFAULT 0,
        mail_error TEXT NOT NULL DEFAULT '',
        PRIMARY KEY(lottery_id, comment_id)
    )");
    $database->exec("CREATE TABLE IF NOT EXISTS plugin_comment_lottery_settings (
        id INTEGER PRIMARY KEY CHECK(id = 1),
        cron_key TEXT NOT NULL,
        last_run_at INTEGER NOT NULL DEFAULT 0,
        last_run_source TEXT NOT NULL DEFAULT ''
    )");
    $statement = $database->prepare('INSERT OR IGNORE INTO plugin_comment_lottery_settings(id, cron_key) VALUES(1, ?)');
    $statement->execute([bin2hex(random_bytes(32))]);
    $initialized = $database;
}

function sblog_lottery_input(array $input, string $key): string
{
    return isset($input[$key]) && is_scalar($input[$key]) ? trim((string)$input[$key]) : '';
}

function sblog_lottery_date(int $timestamp, string $format = 'Y-m-d H:i'): string
{
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format($format);
}

function sblog_lottery_parse_date(string $value): int
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Shanghai'));
    if (!$date || $date->format('Y-m-d\TH:i') !== $value || $date->getTimestamp() <= 0) {
        throw new InvalidArgumentException('请填写有效的开始与结束时间（北京时间）。');
    }
    return $date->getTimestamp();
}

function sblog_lottery_url(string $action = 'admin_lottery', array $params = []): string
{
    return url_with_query(script_url(), array_merge($params, ['a' => $action]));
}

function sblog_lottery_shortcode(string $id): string
{
    return '[comment-lottery id="' . $id . '"]';
}

// Only standalone markers outside fenced code are active modules.
function sblog_lottery_tokens(string $content): array
{
    $tokens = [];
    $inCode = false;
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $content)) as $line) {
        if (preg_match('/^```[\w-]*\s*$/', $line)) {
            $inCode = !$inCode;
            continue;
        }
        if (!$inCode && preg_match('/^\s*\[comment-lottery id="([a-f0-9]{32})"\]\s*$/D', $line, $matches)) {
            $tokens[] = $matches[1];
        }
    }
    return array_values(array_unique($tokens));
}

function sblog_lottery_get(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
        return null;
    }
    sblog_lottery_init();
    $statement = db()->prepare('SELECT * FROM plugin_comment_lotteries WHERE id = ?');
    $statement->execute([$id]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sblog_lottery_for_post(int $postId): ?array
{
    sblog_lottery_init();
    $statement = db()->prepare('SELECT * FROM plugin_comment_lotteries WHERE post_id = ? AND enabled = 1 ORDER BY created_at DESC LIMIT 1');
    $statement->execute([$postId]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sblog_lottery_post(array $lottery): ?array
{
    $statement = db()->prepare('SELECT * FROM posts WHERE id = ?');
    $statement->execute([(int)($lottery['post_id'] ?? 0)]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sblog_lottery_live(array $lottery, int $now): bool
{
    $post = sblog_lottery_post($lottery);
    return $post !== null && ($post['kind'] ?? 'post') === 'post' && $post['status'] === 'published'
        && (int)$post['published_at'] > 0 && (int)$post['published_at'] <= $now
        && (int)$lottery['enabled'] === 1
        && in_array($lottery['id'], sblog_lottery_tokens((string)$post['content']), true);
}

function sblog_lottery_locked(array $lottery): bool
{
    if ($lottery['status'] === 'drawn') {
        return true;
    }
    $post = sblog_lottery_post($lottery);
    return $post !== null && $post['status'] === 'published' && (int)$post['published_at'] <= time()
        && time() >= (int)$lottery['starts_at'];
}

function sblog_lottery_save(array $input): array
{
    sblog_lottery_init();
    foreach (['id', 'post_id', 'title', 'description', 'starts_at', 'ends_at', 'winners_count', 'unique_email', 'auto_draw', 'notify', 'roots_only'] as $field) {
        if (isset($input[$field]) && !is_scalar($input[$field])) {
            throw new InvalidArgumentException('抽奖字段格式无效，请重新填写。');
        }
    }
    $title = sblog_lottery_input($input, 'title');
    $description = sblog_lottery_input($input, 'description');
    $count = sblog_lottery_input($input, 'winners_count');
    $postIdValue = sblog_lottery_input($input, 'post_id');
    if ($title === '' || str_len_u($title) > 80 || preg_match('/[\r\n]/', $title)) {
        throw new InvalidArgumentException('抽奖标题应为 1–80 个字符，不能包含换行。');
    }
    if (str_len_u($description) > 1000) {
        throw new InvalidArgumentException('奖品和活动说明最多 1000 个字符。');
    }
    if (!preg_match('/^[0-9]{1,3}$/D', $count) || (int)$count < 1 || (int)$count > 100) {
        throw new InvalidArgumentException('中奖名额应为 1–100 之间的整数。');
    }
    if (!preg_match('/^[0-9]{1,10}$/D', $postIdValue)) {
        throw new InvalidArgumentException('无效的文章编号。');
    }
    $startsAt = sblog_lottery_parse_date(sblog_lottery_input($input, 'starts_at'));
    $endsAt = sblog_lottery_parse_date(sblog_lottery_input($input, 'ends_at'));
    if ($endsAt <= $startsAt) {
        throw new InvalidArgumentException('结束时间必须晚于开始时间。');
    }
    $postId = (int)$postIdValue;
    if ($postId > 0) {
        $post = sblog_lottery_post(['post_id' => $postId]);
        if (!$post || ($post['kind'] ?? 'post') !== 'post') {
            throw new InvalidArgumentException('评论抽奖只能添加到文章。');
        }
    }
    $id = sblog_lottery_input($input, 'id');
    $existing = $id !== '' ? sblog_lottery_get($id) : null;
    if ($id !== '' && !$existing) {
        throw new InvalidArgumentException('抽奖活动不存在，请重新插入。');
    }
    $values = [$title, $description, $startsAt, $endsAt, (int)$count];
    foreach (['unique_email', 'auto_draw', 'notify', 'roots_only'] as $field) {
        $value = sblog_lottery_input($input, $field);
        if (!in_array($value, ['0', '1'], true)) {
            throw new InvalidArgumentException('请选择有效的抽奖选项。');
        }
        $values[] = (int)$value;
    }
    $database = db();
    $database->exec('BEGIN IMMEDIATE');
    try {
        if ($existing) {
            $existing = sblog_lottery_get($id);
            if ((int)($existing['post_id'] ?? 0) > 0 && (int)$existing['post_id'] !== $postId) {
                throw new InvalidArgumentException('这个抽奖属于另一篇文章，请新建抽奖。');
            }
            if (sblog_lottery_locked($existing)) {
                throw new InvalidArgumentException('活动已开始或已开奖，抽奖规则已锁定。');
            }
            $statement = $database->prepare('UPDATE plugin_comment_lotteries SET title=?, description=?, starts_at=?, ends_at=?, winners_count=?, unique_email=?, auto_draw=?, notify=?, roots_only=?, updated_at=? WHERE id=?');
            $statement->execute(array_merge($values, [time(), $id]));
        } else {
            $id = bin2hex(random_bytes(16));
            $statement = $database->prepare('INSERT INTO plugin_comment_lotteries(id,title,description,starts_at,ends_at,winners_count,unique_email,auto_draw,notify,roots_only,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute(array_merge([$id], $values, [time(), time()]));
        }
        $database->exec('COMMIT');
    } catch (Throwable $exception) {
        $database->exec('ROLLBACK');
        throw $exception;
    }
    sblog_lottery_invalidate_cache();
    return sblog_lottery_get($id);
}

function sblog_lottery_sync_post(array $context): void
{
    sblog_lottery_init();
    $postId = (int)($context['post_id'] ?? 0);
    $post = sblog_lottery_post(['post_id' => $postId]);
    if (!$post) {
        return;
    }
    $tokens = ($post['kind'] ?? 'post') === 'post' ? sblog_lottery_tokens((string)$post['content']) : [];
    $database = db();
    $database->exec('BEGIN IMMEDIATE');
    try {
        $statement = $database->prepare('UPDATE plugin_comment_lotteries SET enabled=0 WHERE post_id=?');
        $statement->execute([$postId]);
        foreach ($tokens as $token) {
            $lottery = sblog_lottery_get($token);
            if (!$lottery || ((int)($lottery['post_id'] ?? 0) > 0 && (int)$lottery['post_id'] !== $postId)) {
                continue;
            }
            $statement = $database->prepare('UPDATE plugin_comment_lotteries SET post_id=?, enabled=1, updated_at=? WHERE id=?');
            $statement->execute([$postId, time(), $token]);
            break; // One active lottery per article.
        }
        $database->exec('COMMIT');
    } catch (Throwable $exception) {
        $database->exec('ROLLBACK');
        throw $exception;
    }
    sblog_lottery_invalidate_cache();
}

function sblog_lottery_candidates(array $lottery): array
{
    $statement = db()->prepare("SELECT id, author_name, author_email, content FROM comments
        WHERE post_id=? AND status='approved' AND (user_id IS NULL OR user_id=0)
        AND created_at>=? AND created_at<?"
        . ((int)$lottery['roots_only'] === 1 ? ' AND (parent_id IS NULL OR parent_id=0)' : '')
        . ' ORDER BY created_at ASC, id ASC');
    $statement->execute([(int)$lottery['post_id'], (int)$lottery['starts_at'], (int)$lottery['ends_at']]);
    $candidates = [];
    $seen = [];
    while ($comment = $statement->fetch(PDO::FETCH_ASSOC)) {
        $email = strtolower(trim((string)$comment['author_email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || ((int)$lottery['unique_email'] === 1 && isset($seen[$email]))) {
            continue;
        }
        $seen[$email] = true;
        $comment['author_email'] = $email;
        $candidates[] = $comment;
    }
    return $candidates;
}

function sblog_lottery_draw(string $id, ?int $now = null): array
{
    sblog_lottery_init();
    $now ??= time();
    $database = db();
    $database->exec('BEGIN IMMEDIATE');
    try {
        $lottery = sblog_lottery_get($id);
        $reason = !$lottery ? 'not_found' : ($lottery['status'] === 'drawn' ? 'already_drawn'
            : (!sblog_lottery_live($lottery, $now) ? 'inactive' : ($now < (int)$lottery['ends_at'] ? 'not_due' : 'drawn')));
        if ($reason !== 'drawn') {
            $database->exec('COMMIT');
            return ['drawn' => false, 'reason' => $reason];
        }
        $candidates = sblog_lottery_candidates($lottery);
        $candidateCount = count($candidates);
        $winnerCount = min((int)$lottery['winners_count'], $candidateCount);
        $statement = $database->prepare('INSERT INTO plugin_comment_lottery_winners(lottery_id,comment_id,position,author_name,author_email,content,mail_status) VALUES(?,?,?,?,?,?,?)');
        // Partial Fisher–Yates uses a cryptographically secure, uniform random source.
        for ($position = 0; $position < $winnerCount; $position++) {
            $chosen = random_int($position, $candidateCount - 1);
            [$candidates[$position], $candidates[$chosen]] = [$candidates[$chosen], $candidates[$position]];
            $winner = $candidates[$position];
            $statement->execute([$id, (int)$winner['id'], $position + 1, $winner['author_name'], $winner['author_email'], $winner['content'], (int)$lottery['notify'] === 1 ? 'pending' : 'skipped']);
        }
        $statement = $database->prepare("UPDATE plugin_comment_lotteries SET status='drawn',candidate_count=?,drawn_at=?,updated_at=? WHERE id=?");
        $statement->execute([$candidateCount, $now, $now, $id]);
        $database->exec('COMMIT');
    } catch (Throwable $exception) {
        $database->exec('ROLLBACK');
        throw $exception;
    }
    sblog_lottery_invalidate_cache();
    return ['drawn' => true, 'reason' => 'drawn'];
}

function sblog_lottery_winners(string $id): array
{
    sblog_lottery_init();
    $statement = db()->prepare('SELECT * FROM plugin_comment_lottery_winners WHERE lottery_id=? ORDER BY position ASC');
    $statement->execute([$id]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function sblog_lottery_notify(?string $id = null, int $limit = 20, bool $retry = false): int
{
    sblog_lottery_init();
    $now = time();
    $params = $retry ? [$now - 600] : [$now - 300];
    $condition = $retry ? "(w.mail_status IN ('pending','failed') OR (w.mail_status='sending' AND w.mail_attempted_at<=?))"
        : "(w.mail_status='pending' OR (w.mail_status='failed' AND w.mail_attempts<5 AND w.mail_attempted_at<=?))";
    $sql = "SELECT w.*,l.title,l.description,l.post_id,p.slug,p.kind FROM plugin_comment_lottery_winners w
        JOIN plugin_comment_lotteries l ON l.id=w.lottery_id JOIN posts p ON p.id=l.post_id
        WHERE l.status='drawn' AND l.notify=1 AND l.enabled=1 AND p.status='published' AND p.published_at<=? AND " . $condition;
    array_unshift($params, $now);
    if ($id !== null) {
        $sql .= ' AND w.lottery_id=?';
        $params[] = $id;
    }
    $sql .= ' ORDER BY l.drawn_at,w.position LIMIT ' . max(1, min(100, $limit));
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $sent = 0;
    foreach ($rows as $winner) {
        $lottery = sblog_lottery_get($winner['lottery_id']);
        if (!$lottery || !sblog_lottery_live($lottery, $now)) {
            continue;
        }
        // Atomic claim prevents concurrent cron/visit requests from sending the same notice.
        $claim = db()->prepare("UPDATE plugin_comment_lottery_winners SET mail_status='sending',mail_attempts=mail_attempts+1,mail_attempted_at=?,mail_error='' WHERE lottery_id=? AND comment_id=? AND mail_status=? AND mail_attempts=? AND mail_attempted_at=?");
        $claim->execute([$now, $winner['lottery_id'], $winner['comment_id'], $winner['mail_status'], $winner['mail_attempts'], $winner['mail_attempted_at']]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        $ok = false;
        $error = '';
        try {
            $url = absolute_url(content_permalink(['kind' => $winner['kind'], 'slug' => $winner['slug']])) . '#lottery-' . $winner['lottery_id'];
            $body = $winner['author_name'] . "，你好！\n\n恭喜你的评论在「" . $winner['title'] . "」中中奖。\n\n"
                . ($winner['description'] !== '' ? $winner['description'] . "\n\n" : '')
                . '中奖评论：' . $winner['content'] . "\n\n中奖公示：" . $url . "\n\n请按活动说明联系博主领取奖品。";
            $ok = send_site_mail($winner['author_email'], '【中奖通知】' . $winner['title'], $body);
            if (!$ok) {
                $error = '邮件接口未确认发送，请检查邮件通知插件及 SMTP 配置。';
            }
        } catch (Throwable $exception) {
            error_log('Comment lottery mail failed: ' . $exception->getMessage());
            $error = '邮件发送异常，请检查服务器日志和 SMTP 配置。';
        }
        $update = db()->prepare('UPDATE plugin_comment_lottery_winners SET mail_status=?,mail_sent_at=?,mail_error=? WHERE lottery_id=? AND comment_id=? AND mail_status=\'sending\' AND mail_attempted_at=?');
        $update->execute([$ok ? 'sent' : 'failed', $ok ? $now : 0, $error, $winner['lottery_id'], $winner['comment_id'], $now]);
        if ($ok) {
            $sent++;
        }
    }
    return $sent;
}

function sblog_lottery_settings(): array
{
    sblog_lottery_init();
    return db()->query('SELECT * FROM plugin_comment_lottery_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
}

function sblog_lottery_rotate_key(): void
{
    sblog_lottery_init();
    $statement = db()->prepare('UPDATE plugin_comment_lottery_settings SET cron_key=? WHERE id=1');
    $statement->execute([bin2hex(random_bytes(32))]);
}

function sblog_lottery_tick(?int $now = null, string $source = 'visit'): array
{
    sblog_lottery_init();
    $now ??= time();
    $statement = db()->prepare("SELECT l.id FROM plugin_comment_lotteries l JOIN posts p ON p.id=l.post_id
        WHERE l.status='pending' AND l.enabled=1 AND l.auto_draw=1 AND l.ends_at<=?
        AND p.kind='post' AND p.status='published' AND p.published_at>0 AND p.published_at<=?
        ORDER BY l.ends_at LIMIT 20");
    $statement->execute([$now, $now]);
    $drawn = 0;
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $result = sblog_lottery_draw((string)$id, $now);
        if ($result['drawn']) {
            $drawn++;
        } elseif ($result['reason'] === 'inactive') {
            // Removed markers must leave the bounded queue so they cannot block later activities.
            $disable = db()->prepare("UPDATE plugin_comment_lotteries SET enabled=0 WHERE id=? AND status='pending'");
            $disable->execute([$id]);
        }
    }
    $sent = sblog_lottery_notify(null, 20);
    if ($source === 'cron' || $drawn > 0 || $sent > 0) {
        $statement = db()->prepare('UPDATE plugin_comment_lottery_settings SET last_run_at=?,last_run_source=? WHERE id=1');
        $statement->execute([$now, $source]);
    }
    return ['drawn' => $drawn, 'sent' => $sent, 'checked_at' => $now];
}

function sblog_lottery_list(): array
{
    sblog_lottery_init();
    return db()->query('SELECT l.*,p.title AS post_title,p.slug AS post_slug,p.status AS post_status,p.published_at AS post_published_at FROM plugin_comment_lotteries l JOIN posts p ON p.id=l.post_id ORDER BY l.created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
}

function sblog_lottery_invalidate_cache(): void
{
    if (function_exists('spc_rotate_generation')) {
        spc_rotate_generation();
    }
}
