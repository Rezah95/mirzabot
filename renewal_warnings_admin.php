<?php

require_once __DIR__ . '/renewal_warnings.php';

function renewalWarningMenu(PDO $pdo, array $lang): array
{
    global $domainhosts;
    $s = renewalReminderSettings($pdo); $t = $lang['renewalOffers'];
    $text = $t['title'] . "\n\n" . strtr($t['summary'], ['{status}' => $s['warning_enabled'] ? $t['on'] : $t['off'],
        '{days}' => $s['warning_days'], '{gb}' => $s['warning_gb'], '{percent}' => $s['warning_percent'], '{valid_days}' => $s['warning_valid_days']])
        . "\n\n" . $t['editor_hint'];
    $stats = $pdo->query("SELECT COALESCE(SUM(status = 'sent'), 0) AS sent, COALESCE(SUM(status IN ('blocked', 'uncertain', 'sending')), 0) AS errors FROM renewal_warning_state")->fetch(PDO::FETCH_ASSOC);
    $text .= "\n\n" . strtr($t['stats'], ['{sent}' => $stats['sent'], '{errors}' => $stats['errors']]);
    $button = static fn($text, $action) => ['text' => $text, 'callback_data' => 'renewal_offer_' . $action];
    $rows = [[$button($s['warning_enabled'] ? $t['disable'] : $t['enable'], 'toggle')], [$button($t['preset'], 'preset')]];
    foreach (['days', 'gb', 'percent', 'valid_days'] as $field) {
        $label = $t[match ($field) { 'days' => 'days_button', 'gb' => 'gb_button', 'percent' => 'percent_button', default => 'valid_button' }];
        $rows[] = [$button(strtr($label, ['{value}' => $s['warning_' . $field]]), 'edit_' . $field)];
    }
    $rows[] = [$button($t['preview_time'], 'preview_time'), $button($t['preview_volume'], 'preview_volume')];
    if (!empty($domainhosts)) {
        $rows[] = [['text' => $t['edit_web'], 'url' => 'https://' . rtrim($domainhosts, '/') . '/panel/bottext.php?group=renewalOffers']];
    }
    $rows[] = [['text' => $t['back'], 'callback_data' => 'renewal_menu']];
    return [$text, json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE)];
}

function renewalWarningAdminHandle(string $datain, string $text, array $user): bool
{
    global $pdo, $from_id, $message_id, $textbotlang;
    $input = str_starts_with($user['step'] ?? '', 'renewal_offer_input_') && $datain === '';
    if (!str_starts_with($datain, 'renewal_offer_') && !$input) { return false; }
    $t = $textbotlang['renewalOffers'];
    $back = json_encode(['inline_keyboard' => [[['text' => $t['back'], 'callback_data' => 'renewal_offer_menu']]]]);
    if ($input && in_array($text, [$t['back'], $textbotlang['Admin']['backAdminBtn'], $textbotlang['Admin']['backMenuBtn']], true)) {
        step('home', $from_id);
        [$body, $keyboard] = renewalWarningMenu($pdo, $textbotlang);
        sendmessage($from_id, $body, $keyboard, 'HTML'); return true;
    }

    try {
        $changed = false;
        if ($datain === 'renewal_offer_toggle') {
            $s = renewalReminderSettings($pdo);
            renewalReminderSave($pdo, ['warning_enabled' => (int) !$s['warning_enabled']]); $changed = true;
        } elseif ($datain === 'renewal_offer_preset') {
            renewalReminderSave($pdo, ['warning_enabled' => 1, 'warning_days' => 3, 'warning_gb' => 5, 'warning_percent' => 10, 'warning_valid_days' => 7]);
            $changed = true;
        } elseif (preg_match('/^renewal_offer_edit_(days|gb|percent|valid_days)$/', $datain, $match)) {
            Editmessagetext($from_id, $message_id, $t['ask_' . $match[1]], $back);
            step('renewal_offer_input_' . $match[1], $from_id); return true;
        } elseif (preg_match('/^renewal_offer_preview_(time|volume)$/', $datain, $match)) {
            $s = renewalReminderSettings($pdo);
            $coupon = ['price' => $s['warning_percent'], 'codeDiscount' => 'SAMPLE', 'time' => time() + $s['warning_valid_days'] * 86400];
            $invoice = ['username' => 'example_user', 'name_product' => $t['sample_service']];
            $body = renewalWarningRender($textbotlang, $invoice, $match[1], ['{days}' => $s['warning_days'], '{remaining_gb}' => $s['warning_gb']], $coupon);
            Editmessagetext($from_id, $message_id, $t['preview_note'] . "\n\n" . $body, $back);
            step('home', $from_id); return true;
        } elseif ($input) {
            $field = substr($user['step'], strlen('renewal_offer_input_'));
            $value = strtr(trim($text), array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
            if (!in_array($field, ['days', 'gb', 'percent', 'valid_days'], true) || !preg_match('/^[0-9]{1,6}$/D', $value)) {
                throw new InvalidArgumentException('Expected integer');
            }
            renewalReminderSave($pdo, ['warning_' . $field => (int) $value]); $changed = true;
        } elseif ($datain !== 'renewal_offer_menu') { return true; }
        step('home', $from_id);
        [$body, $keyboard] = renewalWarningMenu($pdo, $textbotlang);
        if ($changed) { $body = $t['saved'] . "\n\n" . $body; }
        if ($datain === '') { sendmessage($from_id, $body, $keyboard, 'HTML'); }
        else { Editmessagetext($from_id, $message_id, $body, $keyboard); }
    } catch (InvalidArgumentException $e) { sendmessage($from_id, $t['invalid'], $back, 'HTML'); }
    catch (Throwable $e) {
        error_log('Renewal offer settings failed: ' . get_class($e));
        sendmessage($from_id, $t['error'], $back, 'HTML');
    }
    return true;
}
