<?php

require_once __DIR__ . '/discount_rules.php';

function renewalReminderValidate(array $settings): void
{
    foreach (['enabled' => [0, 1], 'include_existing' => [0, 1], 'interval_days' => [1, 365], 'max_sends' => [1, 30],
        'discount_send' => [0, (int) ($settings['max_sends'] ?? 0)], 'discount_valid_days' => [1, 365]] as $key => [$min, $max]) {
        $value = filter_var($settings[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Invalid reminder setting: ' . $key);
        }
    }
    if (!discountValueValid(['price' => $settings['discount_value'] ?? null, 'discount_mode' => $settings['discount_mode'] ?? ''])
        || !is_string($settings['message_template'] ?? null)
        || trim($settings['message_template']) === ''
        || mb_strlen($settings['message_template'], 'UTF-8') > 2500) {
        throw new InvalidArgumentException('Invalid reminder message or discount');
    }
}

function renewalReminderSettings(PDO $pdo): array
{
    $settings = $pdo->query('SELECT * FROM renewal_reminder_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        throw new RuntimeException('Reminder settings are missing; run database migration');
    }
    renewalReminderValidate($settings);
    return $settings;
}

function renewalReminderSave(PDO $pdo, array $changes, ?int $now = null): void
{
    $allowed = ['enabled', 'include_existing', 'interval_days', 'max_sends', 'message_template', 'discount_send',
        'discount_mode', 'discount_value', 'discount_valid_days'];
    if (!$changes || array_diff(array_keys($changes), $allowed)) {
        throw new InvalidArgumentException('Unknown reminder setting');
    }
    $pdo->beginTransaction();
    try {
        $current = $pdo->query('SELECT * FROM renewal_reminder_settings WHERE id = 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if (!$current) { throw new RuntimeException('Missing reminder settings'); }
        $settings = array_replace($current, $changes);
        renewalReminderValidate($settings);
        if ((int) $settings['enabled'] === 1 && (int) $current['started_at'] === 0) {
            $changes['started_at'] = $now ?? time();
        }
        $fields = implode(', ', array_map(static fn($key) => "$key = ?", array_keys($changes)));
        $pdo->prepare("UPDATE renewal_reminder_settings SET $fields WHERE id = 1")->execute(array_values($changes));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

function renewalReminderExpiry(array $invoice): ?int
{
    $ends = array_filter([(int) ($invoice['expires_at'] ?? 0), (int) ($invoice['depleted_at'] ?? 0)], static fn($n) => $n > 0);
    return $ends ? min($ends) : null;
}

function renewalReminderNextDue(array $settings, int $expiredAt, array $state): ?int
{
    if ((int) ($state['sent_count'] ?? 0) >= (int) $settings['max_sends']) { return null; }
    $interval = (int) $settings['interval_days'] * 86400;
    return max($expiredAt + ((int) ($state['sent_count'] ?? 0) + 1) * $interval,
        (int) ($state['last_sent_at'] ?? 0) + $interval, (int) ($state['next_attempt_at'] ?? 0));
}

/** Null means the panel did not confirm an ended, renewable service. */
function renewalReminderObservedExpiry(array $invoice, array $fresh, int $now): ?int
{
    if (!in_array($fresh['status'] ?? '', ['active', 'expired', 'limited', 'Unknown'], true)) { return null; }
    $expires = $fresh['expire'] ?? null;
    $expires = is_numeric($expires) ? (int) $expires : (is_string($expires) ? strtotime($expires) : false);
    $limit = $fresh['data_limit'] ?? null;
    $used = $fresh['used_traffic'] ?? null;
    $limited = ($fresh['status'] ?? '') === 'limited'
        || (is_numeric($limit) && is_numeric($used) && (float) $limit > 0 && (float) $used >= (float) $limit);
    return renewalReminderExpiry([
        'expires_at' => $expires !== false && $expires > 0 && $expires <= $now ? $expires : null,
        'depleted_at' => $limited ? ((int) ($invoice['depleted_at'] ?? 0) ?: $now) : null,
    ]);
}

function renewalReminderRender(array $settings, array $invoice, int $number, int $now, ?array $coupon = null): string
{
    $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $discount = $coupon ? "🎁 کد تخفیف اختصاصی تمدید: <code>" . $escape($coupon['codeDiscount']) . "</code>\n"
        . 'تخفیف: ' . $escape(discountValueLabel($coupon)) . "\n"
        . 'اعتبار کد: ' . $escape(date('Y/m/d H:i', (int) $coupon['time'])) . "\nفقط برای شما، یک‌بارمصرف." : '';
    $template = $settings['message_template'];
    if ($coupon && !str_contains($template, '{discount}')) { $template .= "\n\n{discount}"; }
    return strtr($escape($template), [
        '{username}' => $escape($invoice['username']), '{service}' => $escape($invoice['name_product']),
        '{days}' => (string) max(0, (int) floor(($now - renewalReminderExpiry($invoice)) / 86400)),
        '{send_number}' => (string) $number, '{discount}' => $discount,
    ]);
}

function renewalReminderCoupon(PDO $pdo, array $settings, array $invoice, array $state, int $number, int $now): ?array
{
    if ((int) $settings['discount_send'] === 0 || $number !== (int) $settings['discount_send']) { return null; }
    if ($state['coupon_code'] !== null) {
        $coupon = discountCodeRow($pdo, $state['coupon_code']);
        if (!$coupon) { throw new RuntimeException('Reminder discount was removed'); }
        return $coupon;
    }
    $coupon = [
        'codeDiscount' => 'rn' . bin2hex(random_bytes(12)), 'price' => (int) $settings['discount_value'],
        'discount_mode' => $settings['discount_mode'], 'target_user_id' => (string) $invoice['id_user'],
        'limitDiscount' => 1, 'usedDiscount' => 0, 'agent' => 'allusers', 'usefirst' => 0, 'useuser' => 1,
        'code_panel' => '/all', 'code_product' => 'all', 'time' => $now + (int) $settings['discount_valid_days'] * 86400,
        'type' => 'extend',
    ];
    $pdo->beginTransaction();
    try {
        if (!discountCreate($pdo, $coupon)) { throw new RuntimeException('Reminder discount collision'); }
        $pdo->prepare('UPDATE renewal_reminder_state SET coupon_code = ? WHERE id = ?')->execute([$coupon['codeDiscount'], $state['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $coupon;
}

/** Callbacks allow exercising real scheduling and persistence without sending messages. */
function renewalReminderRun(PDO $pdo, callable $readPanel, callable $send, string $testName, ?int $now = null): int
{
    $now ??= time();
    $lock = 'renewal_reminders_' . sha1((string) $pdo->query('SELECT DATABASE()')->fetchColumn());
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([$lock]);
    if ((int) $stmt->fetchColumn() !== 1) { return 0; }
    $sent = 0;
    try {
        $settings = renewalReminderSettings($pdo);
        if (!(int) $settings['enabled']) { return 0; }
        $interval = (int) $settings['interval_days'] * 86400;
        $expiry = '(CASE WHEN i.expires_at > 0 AND i.depleted_at > 0 THEN LEAST(i.expires_at, i.depleted_at)
            WHEN i.expires_at > 0 THEN i.expires_at WHEN i.depleted_at > 0 THEN i.depleted_at ELSE NULL END)';
        $query = $pdo->prepare("SELECT i.*, s.id AS reminder_id FROM invoice i
            JOIN user u ON u.id = i.id_user
            LEFT JOIN renewal_reminder_state s ON s.invoice_id = i.id_invoice AND s.user_id = i.id_user AND s.expired_at = $expiry
            WHERE i.Status IN ('active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold')
            AND i.name_product <> ? AND u.User_Status = 'Active' AND COALESCE(u.status_cron, 1) <> 0
            AND (? = 1 OR $expiry >= ?) AND COALESCE(s.sent_count, 0) < ?
            AND COALESCE(s.status, 'active') IN ('active', 'sending', 'uncertain')
            AND GREATEST($expiry + (COALESCE(s.sent_count, 0) + 1) * ?, COALESCE(s.last_sent_at, 0) + ?, COALESCE(s.next_attempt_at, 0)) <= ?
            ORDER BY COALESCE(s.next_attempt_at, $expiry), i.id_invoice LIMIT 25");
        $query->execute([$testName, (int) $settings['include_existing'], (int) $settings['started_at'], (int) $settings['max_sends'], $interval, $interval, $now]);
        $deadline = microtime(true) + 45;
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
            if (microtime(true) >= $deadline) { break; }
            $expiredAt = renewalReminderExpiry($invoice);
            $id = hash('sha256', json_encode([$invoice['id_invoice'], $invoice['id_user'], $expiredAt]));
            $pdo->prepare('INSERT IGNORE INTO renewal_reminder_state (id, invoice_id, user_id, expired_at) VALUES (?, ?, ?, ?)')
                ->execute([$id, $invoice['id_invoice'], $invoice['id_user'], $expiredAt]);
            try {
                $fresh = $readPanel($invoice);
                if (!is_array($fresh) || renewalReminderObservedExpiry($invoice, $fresh, $now) !== $expiredAt) {
                    // A later pass can retry after the service monitor has refreshed the invoice.
                    $pdo->prepare('UPDATE renewal_reminder_state SET next_attempt_at = ? WHERE id = ?')->execute([$now + 3600, $id]);
                    continue;
                }
                // Recheck ownership, renewal and settings after the panel request.
                $current = $pdo->prepare("SELECT i.* FROM invoice i JOIN user u ON u.id = i.id_user
                    WHERE i.id_invoice = ? AND u.User_Status = 'Active' AND COALESCE(u.status_cron, 1) <> 0");
                $current->execute([$invoice['id_invoice']]);
                $latest = $current->fetch(PDO::FETCH_ASSOC);
                $settings = renewalReminderSettings($pdo);
                if (!(int) $settings['enabled']) { break; }
                if (!$latest || $latest['id_user'] !== $invoice['id_user'] || renewalReminderExpiry($latest) !== $expiredAt
                    || !in_array($latest['Status'], ['active', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold'], true)) { continue; }
                $stateQuery = $pdo->prepare('SELECT * FROM renewal_reminder_state WHERE id = ?');
                $stateQuery->execute([$id]);
                $state = $stateQuery->fetch(PDO::FETCH_ASSOC);
                $due = renewalReminderNextDue($settings, $expiredAt, $state);
                if ($due === null || $due > $now) { continue; }
                $number = (int) $state['sent_count'] + 1;
                $coupon = renewalReminderCoupon($pdo, $settings, $invoice, $state, $number, $now);
                $message = renewalReminderRender($settings, $invoice, $number, $now, $coupon);
                // Reserve the occurrence before the HTTP request. Never replay an ambiguous delivery.
                $pdo->prepare("UPDATE renewal_reminder_state SET sent_count = ?, last_sent_at = ?, status = 'sending' WHERE id = ?")
                    ->execute([$number, $now, $id]);
                $result = $send($invoice, $message);
                if (($result['ok'] ?? false) === true) {
                    $pdo->prepare("UPDATE renewal_reminder_state SET confirmed_count = confirmed_count + 1, status = 'active', next_attempt_at = 0 WHERE id = ?")->execute([$id]);
                    $sent++;
                } elseif ((int) ($result['error_code'] ?? 0) === 429) {
                    $retry = max(60, (int) ($result['parameters']['retry_after'] ?? 600));
                    $pdo->prepare("UPDATE renewal_reminder_state SET sent_count = ?, last_sent_at = ?, next_attempt_at = ?, status = 'active' WHERE id = ?")
                        ->execute([$state['sent_count'], $state['last_sent_at'], $now + $retry, $id]);
                    break;
                } else {
                    $status = in_array((int) ($result['error_code'] ?? 0), [400, 401, 403], true) ? 'blocked' : 'uncertain';
                    $pdo->prepare('UPDATE renewal_reminder_state SET status = ? WHERE id = ?')->execute([$status, $id]);
                    error_log('Renewal reminder delivery failed: ' . $id . ' code=' . (int) ($result['error_code'] ?? 0));
                }
            } catch (Throwable $e) {
                $pdo->prepare('UPDATE renewal_reminder_state SET next_attempt_at = ? WHERE id = ?')->execute([$now + 600, $id]);
                error_log('Renewal reminder failed: ' . $id . ' ' . get_class($e));
            }
        }
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
    return $sent;
}
