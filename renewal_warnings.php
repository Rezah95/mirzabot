<?php

require_once __DIR__ . '/renewal_reminders.php';

/** Fresh panel data only: an expired, depleted, unlimited or unstarted service is not a warning. */
function renewalWarningTriggers(array $settings, array $fresh, int $now): array
{
    if (empty($settings['warning_enabled']) || !in_array($fresh['status'] ?? '', ['active', 'Unknown'], true)) { return []; }
    $expiry = $fresh['expire'] ?? 0;
    $expiry = is_numeric($expiry) ? (int) $expiry : (is_string($expiry) ? (strtotime($expiry) ?: 0) : 0);
    $limit = $fresh['data_limit'] ?? null;
    $used = $fresh['used_traffic'] ?? null;
    $remaining = is_numeric($limit) && is_numeric($used) && $limit > 0 ? (float) $limit - (float) $used : null;
    if (($expiry > 0 && $expiry <= $now) || ($remaining !== null && $remaining <= 0)) { return []; }
    $triggers = [];
    if ((int) $settings['warning_days'] > 0 && $expiry > $now && $expiry - $now <= (int) $settings['warning_days'] * 86400) {
        $triggers['time'] = ['{days}' => (string) ceil(($expiry - $now) / 86400)];
    }
    if ((int) $settings['warning_gb'] > 0 && $remaining !== null && $remaining <= (int) $settings['warning_gb'] * 1073741824) {
        $triggers['volume'] = ['{remaining_gb}' => rtrim(rtrim(number_format($remaining / 1073741824, 2, '.', ''), '0'), '.')];
    }
    return $triggers;
}

function renewalWarningRender(array $lang, array $invoice, string $kind, array $values, array $coupon): string
{
    $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $values += ['{username}' => $invoice['username'], '{service}' => $invoice['name_product'],
        '{percent}' => $coupon['price'], '{code}' => $coupon['codeDiscount'], '{valid_until}' => date('Y/m/d H:i', (int) $coupon['time'])];
    $escaped = array_map($escape, $values);
    $escaped['{code}'] = '<code>' . $escape($coupon['codeDiscount']) . '</code>';
    return strtr($escape($lang['renewalOffers'][$kind]), $escaped) . "\n\n"
        . strtr($escape($lang['renewalOffers']['coupon']), $escaped);
}

/** Each threshold sends once per observed expiry/quota cycle; 429 retries reuse the coupon. */
function renewalWarningNotify(PDO $pdo, array $settings, array $invoice, array $user, array $fresh, callable $send, array $lang, ?int $now = null): array
{
    $now ??= time();
    if (($user['User_Status'] ?? '') !== 'Active' || (int) ($user['status_cron'] ?? 1) === 0
        || (string) ($user['id'] ?? '') !== (string) $invoice['id_user']
        || !in_array($invoice['Status'] ?? '', ['active', 'sendedwarn', 'send_on_hold'], true)
        || in_array($invoice['name_product'], ['سرویس تست', 'Test service', 'Тестовый сервис', '测试服务'], true)) { return []; }
    $triggers = renewalWarningTriggers($settings, $fresh, $now);
    if (!$triggers) { return []; }
    // Paid history also distinguishes unlimited-time renewals that keep the same quota.
    $cycleQuery = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM service_other WHERE id_user = ? AND username = ? AND status = 'paid' AND type IN ('extend_user', 'extend_user_by_admin', 'extra_user', 'extra_time_user')");
    $cycleQuery->execute([$invoice['id_user'], $invoice['username']]);
    $cycle = (string) $cycleQuery->fetchColumn();
    $handled = [];
    foreach ($triggers as $kind => $values) {
        $id = hash('sha256', json_encode([$invoice['id_invoice'], $invoice['id_user'], $kind,
            (string) ($fresh['expire'] ?? 0), (string) (float) ($fresh['data_limit'] ?? 0), $cycle]));
        $lock = 'renewal_offer_' . substr($id, 0, 40);
        $query = $pdo->prepare('SELECT GET_LOCK(?, 0)'); $query->execute([$lock]);
        if ((int) $query->fetchColumn() !== 1) { continue; }
        try {
            $pdo->prepare('INSERT IGNORE INTO renewal_warning_state (id, invoice_id, user_id, kind, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $invoice['id_invoice'], $invoice['id_user'], $kind, $now]);
            $query = $pdo->prepare('SELECT * FROM renewal_warning_state WHERE id = ?'); $query->execute([$id]);
            $state = $query->fetch(PDO::FETCH_ASSOC);
            if ($state['status'] !== 'ready') {
                if (in_array($state['status'], ['sent', 'sending', 'uncertain'], true)) { $handled[] = $kind; }
                continue;
            }
            if ((int) $state['next_attempt_at'] > $now) { continue; }
            $coupon = $state['coupon_code'] ? discountCodeRow($pdo, $state['coupon_code']) : null;
            if (!$coupon || ((int) $coupon['time'] > 0 && (int) $coupon['time'] <= $now)) {
                $coupon = renewalReminderCouponData(['discount_value' => $settings['warning_percent'], 'discount_mode' => 'percent',
                    'discount_valid_days' => $settings['warning_valid_days']], $invoice, $now);
                $pdo->beginTransaction();
                try {
                    if (!discountCreate($pdo, $coupon)) { throw new RuntimeException('Warning coupon collision'); }
                    $pdo->prepare('UPDATE renewal_warning_state SET coupon_code = ? WHERE id = ?')->execute([$coupon['codeDiscount'], $id]);
                    $pdo->commit();
                } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
            }
            $message = renewalWarningRender($lang, $invoice, $kind, $values, $coupon);
            $pdo->prepare("UPDATE renewal_warning_state SET status = 'sending' WHERE id = ?")->execute([$id]);
            try { $result = $send($invoice, $message); }
            catch (Throwable $e) { $result = []; } // Unknown delivery must not be replayed.
            $status = ($result['ok'] ?? false) === true ? 'sent'
                : ((int) ($result['error_code'] ?? 0) === 429 ? 'ready'
                    : (in_array((int) ($result['error_code'] ?? 0), [400, 401, 403], true) ? 'blocked' : 'uncertain'));
            $retry = $status === 'ready' ? $now + max(60, (int) ($result['parameters']['retry_after'] ?? 600)) : 0;
            $pdo->prepare('UPDATE renewal_warning_state SET status = ?, next_attempt_at = ? WHERE id = ?')->execute([$status, $retry, $id]);
            if (in_array($status, ['sent', 'uncertain'], true)) { $handled[] = $kind; }
        } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
    }
    return $handled;
}
