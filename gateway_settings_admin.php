<?php

function gatewayNamesKeyboard(): string
{
    global $paymentGateways, $textbotlang;
    $rows = [];
    foreach (gatewayLabelCallbacks() as $key => $callback) {
        $provider = $paymentGateways[$key]['label'] ?? $textbotlang['textbot']['paymentNotVerify'];
        $rows[] = [['text' => $provider . ' ← ' . gatewayUserLabel($key), 'callback_data' => 'gatewayname_edit_' . $key]];
    }
    $rows[] = [['text' => 'بازگشت به درگاه‌ها', 'callback_data' => 'paygwlist']];
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function gatewayNameEditKeyboard(string $key): string
{
    return json_encode(['inline_keyboard' => [
        [['text' => 'بازگردانی نام اولیه', 'callback_data' => 'gatewayname_reset_' . $key]],
        [['text' => 'بازگشت به نام درگاه‌ها', 'callback_data' => 'gatewayname_list']],
    ]], JSON_UNESCAPED_UNICODE);
}

function gatewayOrderKeyboard(): string
{
    global $paymentGateways, $textbotlang;
    $order = gatewayDisplayOrder();
    $rows = [];
    foreach ($order as $index => $key) {
        $provider = $paymentGateways[$key]['label'] ?? $textbotlang['textbot']['paymentNotVerify'];
        $row = [['text' => ($index + 1) . '. ' . $provider . ' ← ' . gatewayUserLabel($key), 'callback_data' => 'gatewayorder_pick_' . $key]];
        if ($index > 0) { $row[] = ['text' => '⬆️', 'callback_data' => 'gatewayorder_up_' . $key]; }
        if ($index < count($order) - 1) { $row[] = ['text' => '⬇️', 'callback_data' => 'gatewayorder_down_' . $key]; }
        $rows[] = $row;
    }
    $rows[] = [['text' => 'بازگردانی ترتیب اولیه', 'callback_data' => 'gatewayorder_reset']];
    $rows[] = [['text' => 'بازگشت به درگاه‌ها', 'callback_data' => 'paygwlist']];
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function gatewayOrderPositionKeyboard(string $key): string
{
    $buttons = [];
    foreach (gatewayDisplayOrder() as $index => $id) {
        $buttons[] = ['text' => (string) ($index + 1), 'callback_data' => 'gatewayorder_place_' . $key . '_' . ($index + 1)];
    }
    $rows = array_chunk($buttons, 4);
    $rows[] = [['text' => 'بازگشت به ترتیب درگاه‌ها', 'callback_data' => 'gatewayorder_list']];
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function gatewayOrderAdminHandle(string $data): bool
{
    global $pdo, $from_id, $message_id;
    $order = null;
    if ($data === 'gatewayorder_reset') {
        gatewaySaveSetting($pdo, 'gateway_display_order', '[]');
    } elseif (preg_match('/^gatewayorder_(pick|up|down)_(\w+)$/', $data, $match) && isset(gatewayLabelCallbacks()[$match[2]])) {
        $key = $match[2];
        step('home', $from_id);
        if ($match[1] === 'pick') {
            $label = htmlspecialchars(gatewayUserLabel($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            Editmessagetext($from_id, $message_id, "جایگاه جدید <b>$label</b> را در ترتیب کلی انتخاب کنید.\n۱ بالاترین جایگاه است؛ درگاه‌های مخفی برای هر کاربر از فهرست او کنار گذاشته می‌شوند.", gatewayOrderPositionKeyboard($key));
            return true;
        }
        $current = gatewayDisplayOrder();
        $position = array_search($key, $current, true) + 1 + ($match[1] === 'up' ? -1 : 1);
        $order = gatewayPlaceInOrder($current, $key, max(1, min(count($current), $position)));
    } elseif (preg_match('/^gatewayorder_place_(\w+)_(\d{1,2})$/', $data, $match) && isset(gatewayLabelCallbacks()[$match[1]])) {
        $current = gatewayDisplayOrder();
        $position = (int) $match[2];
        if ($position >= 1 && $position <= count($current)) { $order = gatewayPlaceInOrder($current, $match[1], $position); }
    } elseif ($data !== 'gatewayorder_list') {
        return false;
    }
    if ($order !== null) { gatewaySaveSetting($pdo, 'gateway_display_order', json_encode($order, JSON_THROW_ON_ERROR)); }
    step('home', $from_id);
    Editmessagetext($from_id, $message_id,
        'با فلش‌ها جابه‌جا کنید یا روی نام درگاه بزنید و شمارهٔ جایگاه را انتخاب کنید. تغییرات فوراً ذخیره می‌شوند.'
        . "\nاین ترتیب شامل همهٔ درگاه‌هاست؛ هر کاربر فقط درگاه‌های مجاز و فعال خودش را می‌بیند.", gatewayOrderKeyboard());
    return true;
}

function paymentGatewayAdminHandle(string $data, string $text, array $user): bool
{
    global $pdo, $from_id, $message_id, $textbotlang;
    if ($data === 'tonpays_errors') {
        step('home', $from_id);
        $markup = json_encode(['inline_keyboard' => [
            [['text' => 'تازه‌سازی', 'callback_data' => 'tonpays_errors']],
            [['text' => 'تنظیمات TonPays', 'callback_data' => 'paygw-tonpays']],
        ]]);
        Editmessagetext($from_id, $message_id, htmlspecialchars(tonpaysRecentErrorsText(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $markup);
        return true;
    }
    if (gatewayOrderAdminHandle($data)) { return true; }
    if ($data === 'gatewayname_list') {
        step('home', $from_id);
        Editmessagetext($from_id, $message_id, 'درگاه موردنظر را برای تغییر نام نمایشی سمت کاربر انتخاب کنید.', gatewayNamesKeyboard());
        return true;
    }
    if (preg_match('/^gatewayname_(edit|reset)_(\w+)$/', $data, $match) && isset(gatewayLabelCallbacks()[$match[2]])) {
        $key = $match[2];
        if ($match[1] === 'reset') { gatewaySaveSetting($pdo, 'gateway_label_' . $key, ''); }
        step('gatewayname_input_' . $key, $from_id);
        $label = htmlspecialchars(gatewayUserLabel($key), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        Editmessagetext($from_id, $message_id, "نام فعلی سمت کاربر: <b>$label</b>\nنام جدید را در یک خط و حداکثر ۶۴ نویسه بفرستید.", gatewayNameEditKeyboard($key));
        return true;
    }
    if (preg_match('/^tonpays_set_(api_key|min|max)$/', $data, $match)) {
        $field = $match[1];
        step('tonpays_input_' . $field, $from_id);
        $prompt = $field === 'api_key'
            ? 'کلید API دریافتی از بخش «مستندات API» ربات TonPays را بفرستید.'
            : ($field === 'min' ? 'حداقل' : 'حداکثر') . ' مبلغ پرداخت TonPays را به تومان بفرستید. مقدار فعلی: '
                . number_format((int) getPaySettingValue('tonpays_' . $field, $field === 'min' ? '20000' : '1000000'));
        Editmessagetext($from_id, $message_id, $prompt, json_encode(['inline_keyboard' => [[['text' => 'بازگشت', 'callback_data' => 'paygw-tonpays']]]]));
        return true;
    }
    if ($data !== '' || in_array($text, array_merge(['panel', '/panel', '/start'], array_values($textbotlang['keyboard']), array_values($textbotlang['Admin']['btnKeyboard']), [
        $textbotlang['Admin']['panelAdmin'], $textbotlang['Admin']['backAdminBtn'], $textbotlang['Admin']['backMenuBtn'],
    ]), true)) { return false; }
    if (preg_match('/^gatewayname_input_(\w+)$/', $user['step'] ?? '', $match) && isset(gatewayLabelCallbacks()[$match[1]])) {
        $label = trim($text);
        if (!gatewayValidLabel($label)) {
            sendmessage($from_id, 'نام باید شامل ۱ تا ۶۴ نویسه و فقط یک خط باشد.', gatewayNameEditKeyboard($match[1]), 'HTML');
            return true;
        }
        gatewaySaveSetting($pdo, 'gateway_label_' . $match[1], $label);
        step('home', $from_id);
        sendmessage($from_id, 'نام نمایشی درگاه ذخیره شد.', gatewayNamesKeyboard(), 'HTML');
        return true;
    }
    if (preg_match('/^tonpays_input_(api_key|min|max)$/', $user['step'] ?? '', $match)) {
        $field = $match[1];
        $value = trim($text);
        $error = null;
        if ($field === 'api_key') {
            if ($value === '' || $value === '0' || strlen($value) > 512 || preg_match('/[\s\x00-\x1f\x7f]/', $value)) {
                $error = 'کلید API معتبر و بدون فاصله بفرستید.';
            }
        } else {
            $value = strtr($value, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
            $number = tonpaysPositiveInt($value);
            $other = (int) getPaySettingValue('tonpays_' . ($field === 'min' ? 'max' : 'min'), $field === 'min' ? '1000000' : '20000');
            if ($number === null || $number > 100000000 || ($field === 'min' ? $number > $other : $number < $other)) {
                $error = 'مبلغ صحیح و مثبت بفرستید (حداکثر ۱۰۰٬۰۰۰٬۰۰۰ تومان). حداقل نباید از حداکثر بیشتر باشد.';
            }
        }
        $back = json_encode(['inline_keyboard' => [[['text' => 'تنظیمات TonPays', 'callback_data' => 'paygw-tonpays']]]]);
        if ($error !== null) { sendmessage($from_id, $error, $back, 'HTML'); return true; }
        gatewaySaveSetting($pdo, 'tonpays_' . $field, $value);
        step('home', $from_id);
        sendmessage($from_id, 'تنظیم TonPays ذخیره شد.', $back, 'HTML');
        return true;
    }
    return false;
}
