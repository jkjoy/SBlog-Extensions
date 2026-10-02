<?php

declare(strict_types=1);

function sblog_visit_install(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS sblog_visit_log_settings (
        name TEXT PRIMARY KEY, value TEXT NOT NULL
    )');
    db()->exec("CREATE TABLE IF NOT EXISTS sblog_visit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visited_at INTEGER NOT NULL,
        visitor_hash TEXT NOT NULL,
        ip TEXT NOT NULL DEFAULT '',
        path TEXT NOT NULL,
        referrer TEXT NOT NULL DEFAULT '',
        referrer_host TEXT NOT NULL DEFAULT '',
        browser TEXT NOT NULL,
        os TEXT NOT NULL,
        device TEXT NOT NULL,
        user_agent TEXT NOT NULL DEFAULT '',
        is_bot INTEGER NOT NULL DEFAULT 0,
        status_code INTEGER NOT NULL,
        duration_ms INTEGER NOT NULL,
        action TEXT NOT NULL
    )");
    db()->exec('CREATE INDEX IF NOT EXISTS sblog_visit_logs_time ON sblog_visit_logs(visited_at, id)');
    q('INSERT OR IGNORE INTO sblog_visit_log_settings(name, value) VALUES(?, ?)', [
        'visitor_salt', bin2hex(random_bytes(32)),
    ]);
}

function sblog_visit_setting(string $name, string $default = ''): string
{
    $row = one('SELECT value FROM sblog_visit_log_settings WHERE name = ?', [$name]);
    return $row === null ? $default : (string)$row['value'];
}

function sblog_visit_config(): array
{
    $retention = (int)sblog_visit_setting('retention_days', '30');
    return [
        'enabled' => sblog_visit_setting('enabled', '1') === '1',
        'retention_days' => in_array($retention, [1, 7, 30, 90, 180, 365], true) ? $retention : 30,
        'record_bots' => sblog_visit_setting('record_bots', '1') === '1',
        'timezone' => sblog_visit_timezone()->getName(),
    ];
}

function sblog_visit_timezone(): DateTimeZone
{
    $name = sblog_visit_setting('timezone', date_default_timezone_get());
    try {
        return new DateTimeZone($name);
    } catch (Throwable) {
        return new DateTimeZone(date_default_timezone_get());
    }
}

function sblog_visit_date(string $format, ?int $timestamp = null): string
{
    return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone(sblog_visit_timezone())->format($format);
}

function sblog_visit_save_config(array $post): void
{
    foreach (['enabled', 'record_bots'] as $key) {
        if (isset($post[$key]) && $post[$key] !== '1') {
            throw new InvalidArgumentException(sblog_t('记录开关格式无效。'));
        }
    }
    $retention = $post['retention_days'] ?? null;
    if (!is_string($retention) || !in_array($retention, ['1', '7', '30', '90', '180', '365'], true)) {
        throw new InvalidArgumentException(sblog_t('请选择有效的日志保留天数。'));
    }
    $timezone = $post['timezone'] ?? sblog_visit_timezone()->getName();
    if (!is_string($timezone) || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
        throw new InvalidArgumentException(sblog_t('请输入有效的统计时区，例如 Asia/Shanghai。'));
    }
    $values = [
        'enabled' => ($post['enabled'] ?? '') === '1' ? '1' : '0',
        'record_bots' => ($post['record_bots'] ?? '') === '1' ? '1' : '0',
        'retention_days' => $retention,
        'timezone' => $timezone,
        'last_cleanup' => '',
    ];
    db()->beginTransaction();
    try {
        foreach ($values as $key => $value) {
            q('INSERT OR REPLACE INTO sblog_visit_log_settings(name, value) VALUES(?, ?)', [$key, $value]);
        }
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

function sblog_visit_day_range(string $date): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $date, sblog_visit_timezone());
    if ($start === false || $start->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException(sblog_t('请选择有效的日期。'));
    }
    return [$start->getTimestamp(), $start->modify('+1 day')->getTimestamp()];
}

function sblog_visit_cleanup(?int $now = null): void
{
    $now ??= time();
    $timezone = sblog_visit_timezone();
    $today = (new DateTimeImmutable('@' . $now))->setTimezone($timezone)->format('Y-m-d');
    $marker = $today . ':' . $timezone->getName();
    if (sblog_visit_setting('last_cleanup') === $marker) {
        return;
    }
    $retention = sblog_visit_config()['retention_days'];
    $oldest = (new DateTimeImmutable('@' . $now))
        ->setTimezone($timezone)
        ->setTime(0, 0)->modify('-' . ($retention - 1) . ' days')->getTimestamp();
    db()->beginTransaction();
    try {
        q('DELETE FROM sblog_visit_logs WHERE visited_at < ?', [$oldest]);
        q('INSERT OR REPLACE INTO sblog_visit_log_settings(name, value) VALUES(?, ?)', ['last_cleanup', $marker]);
        db()->commit();
    } catch (Throwable $exception) {
        db()->rollBack();
        throw $exception;
    }
}

function sblog_visit_filters(array $query): array
{
    $filters = ['date' => sblog_visit_date('Y-m-d'), 'ip' => '', 'path' => '', 'kind' => 'all', 'page' => 1];
    foreach (['date' => 10, 'ip' => 45, 'path' => 1024, 'kind' => 5, 'page' => 9] as $key => $limit) {
        if (!isset($query[$key])) {
            continue;
        }
        if (!is_string($query[$key]) || strlen($query[$key]) > $limit
            || preg_match('/[\x00-\x1F\x7F]/', $query[$key])) {
            throw new InvalidArgumentException(sblog_t('筛选条件格式无效。'));
        }
        $filters[$key] = trim($query[$key]);
    }
    sblog_visit_day_range($filters['date']);
    if ($filters['ip'] !== '') {
        if (!filter_var($filters['ip'], FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException(sblog_t('请输入有效的 IP 地址。'));
        }
        $filters['ip'] = (string)inet_ntop((string)inet_pton($filters['ip']));
    }
    if (!in_array($filters['kind'], ['all', 'human', 'bot'], true)
        || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$filters['page'])) {
        throw new InvalidArgumentException(sblog_t('访客类型或页码无效。'));
    }
    $filters['page'] = (int)$filters['page'];
    return $filters;
}

function sblog_visit_report(array $filters): array
{
    [$start, $end] = sblog_visit_day_range($filters['date']);
    $where = 'visited_at >= ? AND visited_at < ?';
    $params = [$start, $end];
    if ($filters['ip'] !== '') {
        $where .= ' AND ip = ?';
        $params[] = $filters['ip'];
    }
    if ($filters['path'] !== '') {
        $where .= " AND path LIKE ? ESCAPE '\\'";
        $params[] = '%' . strtr($filters['path'], ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }
    if ($filters['kind'] !== 'all') {
        $where .= ' AND is_bot = ?';
        $params[] = $filters['kind'] === 'bot' ? 1 : 0;
    }
    $summary = one("SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visitors,
        COUNT(DISTINCT NULLIF(ip, '')) AS ips, COALESCE(SUM(is_bot), 0) AS bots
        FROM sblog_visit_logs WHERE {$where}", $params);
    $summary = array_map('intval', $summary ?? ['views' => 0, 'visitors' => 0, 'ips' => 0, 'bots' => 0]);
    $hours = array_fill(0, 24, 0);
    $timezone = sblog_visit_timezone();
    // Minute buckets keep hour grouping correct for non-hour offsets and DST changes.
    foreach (all_rows("SELECT CAST(visited_at / 60 AS INTEGER) AS minute, COUNT(*) AS views
        FROM sblog_visit_logs WHERE {$where} GROUP BY minute", $params) as $row) {
        $hour = (new DateTimeImmutable('@' . ((int)$row['minute'] * 60)))->setTimezone($timezone)->format('G');
        $hours[(int)$hour] += (int)$row['views'];
    }
    $total = $summary['views'];
    $pagesCount = max(1, (int)ceil($total / SBLOG_VISIT_LOG_PAGE_SIZE));
    $page = max(1, min((int)$filters['page'], $pagesCount));
    $offset = ($page - 1) * SBLOG_VISIT_LOG_PAGE_SIZE;
    return [
        'summary' => $summary, 'hours' => $hours, 'total' => $total,
        'page' => $page, 'pages_count' => $pagesCount,
        'pages' => all_rows("SELECT path, COUNT(*) AS views FROM sblog_visit_logs WHERE {$where}
            GROUP BY path ORDER BY views DESC, path ASC LIMIT 5", $params),
        'sources' => all_rows("SELECT referrer_host, COUNT(*) AS views FROM sblog_visit_logs WHERE {$where}
            GROUP BY referrer_host ORDER BY views DESC, referrer_host ASC LIMIT 5", $params),
        'rows' => all_rows("SELECT * FROM sblog_visit_logs WHERE {$where}
            ORDER BY visited_at DESC, id DESC LIMIT " . SBLOG_VISIT_LOG_PAGE_SIZE . " OFFSET {$offset}", $params),
    ];
}

function sblog_visit_url(string $action = 'admin_visit_log', array $params = []): string
{
    return script_url() . '?' . http_build_query(['a' => $action] + $params, '', '&', PHP_QUERY_RFC3986);
}
