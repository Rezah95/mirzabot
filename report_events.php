<?php

/** Critical business incidents only. Queueing must never throw into a payment or cron. */
function reportEventQueue(string $event, array $context, ?string $key = null, string $topic = 'errorreport', ?PDO $connection = null): bool
{
    global $pdo;
    try {
        $connection ??= $pdo ?? null;
        if (!$connection instanceof PDO || !function_exists('select')) { return false; }
        if (!in_array($event, ['payment_creation_failed', 'payment_success', 'payment_mismatch', 'payment_review', 'payment_cancelled', 'payment_failed', 'payment_refunded',
            'payment_notice_failed', 'callback_storage_failed', 'cron_failed', 'renewal_failed', 'notification_failed',
            'service_monitor_failed', 'service_removal_failed'], true)) { throw new InvalidArgumentException('Invalid report event'); }
        if (!in_array($topic, ['errorreport', 'paymentreport', 'reportcron'], true)) { throw new InvalidArgumentException('Invalid report topic'); }
        $setting = select('setting', '*');
        if (empty($setting['Channel_Report'])) { return false; }
        $safe = [];
        foreach (['gateway', 'order', 'user', 'service', 'reason', 'details', 'job'] as $field) {
            if (isset($context[$field]) && is_scalar($context[$field])) {
                $safe[$field] = mb_substr((string) $context[$field], 0, $field === 'details' ? 1000 : 200, 'UTF-8');
            }
        }
        $json = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $id = hash('sha256', $event . ':' . ($key ?? $json));
        $connection->prepare('INSERT IGNORE INTO report_event_outbox (id, event, topic, context, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $event, $topic, $json, time()]);
        return true;
    } catch (Throwable $e) {
        error_log('Report queue failed: event=' . $event . ' ' . get_class($e));
        return false;
    }
}

function reportEventRender(array $lang, array $row): string
{
    $text = $lang['reportEvents'];
    $context = json_decode($row['context'], true) ?: [];
    $timestamp = (new DateTimeImmutable('@' . (int) $row['created_at']))->setTimezone(new DateTimeZone('Asia/Tehran'));
    $values = ['{event}' => $text['events'][$row['event']], '{time}' => $timestamp->format('Y/m/d H:i:s') . ' Asia/Tehran'];
    foreach (['gateway', 'order', 'user', 'service', 'reason', 'details', 'job'] as $key) {
        $values['{' . $key . '}'] = (string) ($context[$key] ?? '-');
    }
    $values['{reason}'] = $text['reasons'][$context['reason'] ?? ''] ?? $values['{reason}'];
    $lines = [];
    foreach ($context as $key => $value) {
        if (isset($text['fields'][$key]) && $value !== '') { $lines[] = $text['fields'][$key] . ': ' . $values['{' . $key . '}']; }
    }
    $values['{context}'] = implode("\n", $lines);
    $plain = strtr($text['template'], $values);
    // At most 3600 UTF-16 code units, even when every character is an emoji.
    return htmlspecialchars(mb_substr($plain, 0, 1800, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** The lock prevents concurrent senders. A transport timeout may duplicate a report, never a payment. */
function reportEventsRun(PDO $pdo, array $setting, array $lang, callable $send, ?int $now = null): int
{
    if (empty($setting['Channel_Report'])) { return 0; }
    $now ??= time();
    $lock = 'report_events_' . sha1((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
    $q = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $q->execute([$lock]);
    if ((int) $q->fetchColumn() !== 1) { return 0; }
    $sent = 0;
    try {
        $q = $pdo->prepare('SELECT * FROM report_event_outbox WHERE sent_at IS NULL AND next_attempt_at <= ? ORDER BY created_at, id LIMIT 10');
        $q->execute([$now]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $topics = $pdo->query('SELECT report, idreport FROM topicid')->fetchAll(PDO::FETCH_KEY_PAIR);
        $deadline = microtime(true) + 20;
        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) { break; }
            $args = ['chat_id' => $setting['Channel_Report'], 'text' => reportEventRender($lang, $row), 'parse_mode' => 'HTML'];
            if ((int) ($topics[$row['topic']] ?? 0) > 0) { $args['message_thread_id'] = (int) $topics[$row['topic']]; }
            try {
                $result = $send($args);
                if ((int) ($result['error_code'] ?? 0) === 400 && isset($args['message_thread_id'])
                    && preg_match('/thread.*not found|topic_closed|message_thread_id/i', (string) ($result['description'] ?? ''))) {
                    unset($args['message_thread_id']);
                    $result = $send($args); // A removed/closed topic must not hide the incident from the channel.
                }
            } catch (Throwable $e) { $result = []; }
            if (($result['ok'] ?? false) === true) {
                $pdo->prepare('UPDATE report_event_outbox SET sent_at = ?, message_id = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?')
                    ->execute([$now, $result['result']['message_id'] ?? null, $row['id']]);
                $sent++;
            } else {
                $code = (int) ($result['error_code'] ?? 0);
                $delay = max(60, (int) ($result['parameters']['retry_after'] ?? min(3600, 60 * (2 ** min(6, (int) $row['attempts'])))));
                $pdo->prepare('UPDATE report_event_outbox SET next_attempt_at = ?, attempts = attempts + 1, last_error = ? WHERE id = ?')
                    ->execute([$now + $delay, $code, $row['id']]);
                error_log('Report delivery failed: event=' . $row['event'] . ' code=' . $code);
                if ($code === 429) { break; }
            }
        }
    } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
    return $sent;
}
