<?php

// Exercise the actual legacy menu blocks without a database or Telegram requests.
set_error_handler(static function ($severity, $message, $file, $line): void {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$textbotlang = require dirname(__DIR__) . '/lang/fa.php';
$source = file_get_contents(dirname(__DIR__) . '/keyboard.php');
$gatewaySettings = [];
$sent = [];
$writes = [];
require_once dirname(__DIR__) . '/gateway_labels.php';
require_once dirname(__DIR__) . '/payment/tonpays_lib.php';

function getPaySettingValue($name, $default = '')
{
    global $gatewaySettings;
    return $gatewaySettings[$name] ?? $default;
}
function tetra_setting($name, $default = '') { return getPaySettingValue($name, $default); }
function uniquepay_setting($name, $default = '') { return getPaySettingValue($name, $default); }
function tronadoConfigured() { return false; }
function canUserUseZarinpalGateway($users, $paid) { return false; }
function abangatewayEndpoint() { return 'https://gateway.example.test'; }
function sendmessage(...$args) { global $sent; $sent[] = $args; }
function Editmessagetext(...$args) { global $sent; $sent[] = $args; }
function update($table, $column, $value, $keyColumn, $key)
{
    global $writes, $gatewaySettings;
    $writes[] = [$table, $column, $value, $keyColumn, $key];
    $gatewaySettings[$key] = $value;
}
function expectMenu(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function menuSourceBetween(string $source, string $begin, string $end): string
{
    $start = strpos($source, $begin);
    expectMenu($start !== false, 'Menu source start was not found');
    $finish = strpos($source, $end, $start + strlen($begin));
    expectMenu($finish !== false, 'Menu source end was not found');
    return substr($source, $start, $finish - $start);
}

$pdo = new class {
    function prepare($query) {
        return new class {
            function bindValue(...$args) {}
            function execute() {}
            function fetchColumn() { return 0; }
        };
    }
};
$users = ['cardpayment' => 1];
$from_id = 123;
$message_id = 456;
$customerMenu = menuSourceBetween($source, '$PaySettingcard =', '$keyboardhelpadmin =');
foreach ([
    [[], false],
    [['statusiranpay4' => 'oniranpay4'], false],
    [['statusiranpay4' => 'offiranpay4', 'apiiranpay4' => 'test-key'], false],
    [['statusiranpay4' => 'oniranpay4', 'apiiranpay4' => 'test-key'], true],
] as [$gatewaySettings, $expectAban]) {
    eval($customerMenu);
    $found = str_contains($step_payment, '"callback_data":"iranpay4"');
    expectMenu($found === $expectAban, 'Aban gateway visibility failed');
}
$gatewaySettings = ['tetraminatorstatus' => 'ontetraminator', 'tetraminator_label' => 'کارت به کارت'];
eval($customerMenu);
$rows = json_decode($step_payment, true)['inline_keyboard'];
expectMenu($rows[0][0]['callback_data'] === 'tetraminatorpay' && $rows[0][0]['text'] === 'کارت به کارت', 'Custom Tetraminator label changed');

foreach ([
    [[], false],
    [['tonpays_status' => 'ontonpays'], false],
    [['tonpays_api_key' => 'test-api-key'], false],
    [['tonpays_status' => 'ontonpays', 'tonpays_api_key' => 'test-api-key'], true],
] as [$gatewaySettings, $visible]) {
    eval($customerMenu);
    expectMenu(str_contains($step_payment, '"callback_data":"tonpays"') === $visible, 'TonPays visibility failed');
}
$gatewaySettings = ['Cartstatus' => 'oncard', 'Cartstatuspv' => 'oncardpv', 'CartDirect' => 'merchant',
    'tetraminatorstatus' => 'ontetraminator', 'tetraminator_label' => 'کارت به کارت',
    'tonpays_status' => 'ontonpays', 'tonpays_api_key' => 'test-api-key',
    'paymentstatussnotverify' => 'onverifypay', 'gateway_label_card' => 'واریز مستقیم',
    'gateway_label_tetraminator' => 'پرداخت ریالی دلخواه', 'gateway_label_tonpays' => 'تون پی',
    'gateway_label_paymentnotverify' => 'سایر پرداخت‌ها'];
eval($customerMenu);
$buttons = array_merge(...json_decode($step_payment, true)['inline_keyboard']);
expectMenu($buttons[0]['text'] === 'واریز مستقیم' && $buttons[0]['url'] === 'https://t.me/merchant', 'Card URL label failed');
$byCallback = array_column($buttons, 'text', 'callback_data');
expectMenu($byCallback['tetraminatorpay'] === 'پرداخت ریالی دلخواه' && $byCallback['tonpays'] === 'تون پی'
    && $byCallback['paymentnotverify'] === 'سایر پرداخت‌ها', 'Customer labels did not change');
expectMenu($byCallback['colselist'] === $textbotlang['keyboard']['closeList'], 'Non-gateway button changed');
$gatewaySettings['gateway_label_tetraminator'] = '';
expectMenu(gatewayUserLabel('tetraminator') === 'کارت به کارت', 'Reset lost the legacy custom name');
foreach (gatewayLabelCallbacks() as $key => $callback) {
    $gatewaySettings['gateway_label_' . $key] = 'نام جدید ' . $key;
    $markup = ['inline_keyboard' => [[['text' => 'old', 'callback_data' => $callback]]]];
    $new = gatewayApplyLabels($markup)['inline_keyboard'][0][0];
    expectMenu($new['text'] === 'نام جدید ' . $key && $new['callback_data'] === $callback, 'Label changed routing');
}
foreach (['', '   ', "نام\nدوم", "name\x00", str_repeat('الف', 30)] as $label) {
    expectMenu(!gatewayValidLabel($label), 'Invalid label accepted');
}
expectMenu(gatewayValidLabel('💳 پرداخت آسان'), 'Persian/emoji label rejected');

// Sort only the eligible payment buttons, preserving their labels, URLs and callbacks.
$gatewaySettings += ['nowpaymentstatus' => 'onnowpayment', 'statusnowpayment' => '1', 'digistatus' => 'ondigi',
    'statusSwapWallet' => 'onSwapinoBot', 'statustarnado' => 'onternado', 'statusiranpay4' => 'oniranpay4', 'apiiranpay4' => 'test-key',
    'statusaqayepardakht' => 'onaqayepardakht', 'variza_status' => 'onvariza', 'variza_api_token' => 'test-key',
    'variza_webhook_secret' => 'test-secret', 'statusstar' => '1'];
$gatewaySettings['gateway_display_order'] = '[]';
eval($customerMenu);
$originalRows = json_decode($step_payment, true)['inline_keyboard'];
$actualCallbacks = array_map(static fn($row) => $row[0]['callback_data'] ?? 'card-url', $originalRows);
expectMenu($actualCallbacks === ['card-url', 'tetraminatorpay', 'tonpays', 'plisio', 'nowpayment', 'digitaltron',
    'iranpay1', 'iranpay2', 'iranpay4', 'aqayepardakht', 'variza', 'paymentnotverify', 'startelegrams', 'colselist'], 'Default order changed');
$gatewaySettings['gateway_display_order'] = json_encode(array_reverse(gatewayDefaultOrder()));
eval($customerMenu);
$reversedRows = json_decode($step_payment, true)['inline_keyboard'];
$expectedRows = array_reverse(array_slice($originalRows, 0, -1));
$expectedRows[] = end($originalRows);
expectMenu($reversedRows === $expectedRows, 'Reordering changed payment payloads or the close button');
$gatewaySettings['tonpays_status'] = 'offtonpays';
$users['cardpayment'] = 0;
eval($customerMenu);
$hiddenRows = json_decode($step_payment, true)['inline_keyboard'];
$expectedRows = array_values(array_filter($expectedRows, static fn($row) => ($row[0]['callback_data'] ?? '') !== 'tonpays' && !isset($row[0]['url'])));
expectMenu($hiddenRows === $expectedRows, 'Sorting revealed a disabled or disallowed gateway');
$users['cardpayment'] = 1;
$gatewaySettings['Cartstatuspv'] = 'offcardpv';
eval($customerMenu);
$callbackRows = json_decode($step_payment, true)['inline_keyboard'];
expectMenu($callbackRows[count($callbackRows) - 2][0]['callback_data'] === 'cart_to_offline', 'Card callback was not moved');
foreach (['broken JSON', '{}', 'null', '123'] as $invalidOrder) {
    $gatewaySettings['gateway_display_order'] = $invalidOrder;
    expectMenu(gatewayDisplayOrder() === gatewayDefaultOrder(), 'Corrupt order did not use the default');
}
$gatewaySettings['gateway_display_order'] = '["tonpays","unknown","tonpays",null,[],"card"]';
$normal = gatewayDisplayOrder();
expectMenu(array_slice($normal, 0, 2) === ['tonpays', 'card'] && count($normal) === count(gatewayDefaultOrder())
    && count(array_unique($normal)) === count($normal), 'Partial/duplicate order lost a gateway');
$anchored = ['inline_keyboard' => [[['text' => 'help', 'url' => 'https://example.test/help']],
    [['text' => 'card', 'callback_data' => 'cart_to_offline']], [['text' => 'ton', 'callback_data' => 'tonpays']],
    [['text' => 'close', 'callback_data' => 'colselist']]]];
$sorted = gatewayApplyOrder($anchored);
expectMenu($sorted['inline_keyboard'][0] === $anchored['inline_keyboard'][0] && $sorted['inline_keyboard'][3] === $anchored['inline_keyboard'][3]
    && $sorted['inline_keyboard'][1] === $anchored['inline_keyboard'][2], 'Non-gateway rows moved');

$registry = menuSourceBetween($source, '$paymentGateways =', '$Exception_auto_cart_keyboard');
preg_match_all('/\x27keyboard\x27 => \$(\w+)/', $registry, $variables);
foreach ($variables[1] as $variable) {
    ${$variable} = '{"inline_keyboard":[]}';
}
eval($registry);
expectMenu(str_contains(paymentGatewaysKeyboard(), 'gatewayorder_list'), 'Order settings entry missing');
$admin = file_get_contents(dirname(__DIR__) . '/admin.php');
$branch = menuSourceBetween($admin, '} elseif ($text == $textbotlang[\'keyboard\'][\'financial\']', "\n} elseif (");
$text = $textbotlang['keyboard']['financial'];
$adminrulecheck = ['rule' => 'administrator'];
$gatewaySettings['statusSwapWallet'] = 'offnSolutions';
eval(preg_replace('/^\} elseif /', 'if ', $branch) . '}');
expectMenu(count($sent) === 1 && str_contains($sent[0][2], 'paygw-tetraminator'), 'Admin gateway list failed');
expectMenu($writes === [], 'Opening the menu changed gateway settings');

$branch = menuSourceBetween($admin, '} elseif (preg_match(\'/^editpayment-', "\n} elseif (");
$datain = 'editpayment-Cartstatus-oncard';
eval(preg_replace('/^\} elseif /', 'if ', $branch) . '}');
expectMenu(count($writes) === 1 && $gatewaySettings['Cartstatus'] === 'offcard', 'Legacy toggle failed');
expectMenu(count($sent) === 2 && str_contains($sent[1][3], 'paygw-tetraminator'), 'Legacy toggle lost custom gateways');
restore_error_handler();
echo "Gateway menu tests passed with warnings treated as errors\n";
