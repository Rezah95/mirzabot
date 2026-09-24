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

function paymentGatewayAdminHandle(string $data, string $text, array $user): bool
{
    global $pdo, $from_id, $message_id, $textbotlang;
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
