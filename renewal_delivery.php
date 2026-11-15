<?php

require_once __DIR__ . '/report_events.php';

/** Persist Telegram evidence, never tokens, message bodies or coupon codes. */
function renewalDeliveryRecord(PDO $pdo, string $table, string $id, array $result, int $now): void
{
    if (!in_array($table, ['renewal_warning_state', 'renewal_reminder_state'], true)) {
        throw new InvalidArgumentException('Invalid renewal delivery table');
    }
    $pdo->prepare("UPDATE $table SET delivery_at = ?, delivery_chat_id = ?, delivery_message_id = ?, delivery_error_code = ? WHERE id = ?")
        ->execute([$now, $result['result']['chat']['id'] ?? null, $result['result']['message_id'] ?? null,
            ($result['ok'] ?? false) === true ? null : (int) ($result['error_code'] ?? 0), $id]);
}

/** A failed admin report must never turn a successful user send into a retry. */
function renewalDeliveryReportSafely(?callable $report, array $invoice, array $result, string $kind): void
{
    if ($report === null) { return; }
    try { $report($invoice, $result, $kind); }
    catch (Throwable $e) { error_log('Renewal delivery report failed: invoice=' . $invoice['id_invoice'] . ' ' . get_class($e)); }
}

function renewalDeliveryReport(array $invoice, array $result, string $kind): void
{
    $setting = select('setting', '*', null, null, 'select');
    if (empty($setting['Channel_Report'])) { return; }
    $topic = select('topicid', 'idreport', 'report', 'reportcron', 'select');
    $lang = languagechange(__DIR__);
    $values = ['{invoice}' => $invoice['id_invoice'], '{username}' => $invoice['username'],
        '{user}' => $invoice['id_user'], '{kind}' => $lang['renewalOffers']['kind_' . $kind],
        '{chat}' => $result['result']['chat']['id'] ?? $invoice['id_user'],
        '{message}' => $result['result']['message_id'] ?? '-', '{time}' => date('Y/m/d H:i:s')];
    $text = strtr(htmlspecialchars($lang['renewalOffers']['delivery_report'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        array_map(static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $values));
    $report = telegram('sendmessage', ['chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $topic['idreport'] ?? null, 'text' => $text, 'parse_mode' => 'HTML']);
    if (($report['ok'] ?? false) !== true) {
        error_log('Renewal delivery report failed: invoice=' . $invoice['id_invoice'] . ' code=' . (int) ($report['error_code'] ?? 0));
    }
}
