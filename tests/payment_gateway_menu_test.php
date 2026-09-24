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

$registry = menuSourceBetween($source, '$paymentGateways =', '$Exception_auto_cart_keyboard');
preg_match_all('/\x27keyboard\x27 => \$(\w+)/', $registry, $variables);
foreach ($variables[1] as $variable) {
    ${$variable} = '{"inline_keyboard":[]}';
}
eval($registry);
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
