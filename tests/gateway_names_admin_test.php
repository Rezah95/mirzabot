<?php
// php tests/gateway_names_admin_test.php — no database, Telegram or gateway requests.
require __DIR__ . '/payment_gateway_menu_test.php';
require dirname(__DIR__) . '/gateway_settings_admin.php';
set_error_handler(static function ($severity, $message, $file, $line): void {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function clearSelectCache($table): void {}
function step($value, $id): void { global $user; $user['step'] = $value; }
$pdo = new class extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        expectMenu(str_starts_with($query, 'INSERT INTO PaySetting'), 'Unexpected database write');
        return new class extends PDOStatement {
            public function execute(?array $params = null): bool {
                global $gatewaySettings;
                [$key, $value] = $params;
                $gatewaySettings[$key] = $value;
                return true;
            }
        };
    }
};
$user = ['step' => 'home'];
$gatewaySettings = [];
$sent = [];

// Labels can coincide with an existing admin button or another gateway label.
foreach (array_keys(gatewayLabelCallbacks()) as $key) {
    foreach (['کارت به کارت', '💳 رسید دستی'] as $label) {
        expectMenu(paymentGatewayAdminHandle('gatewayname_edit_' . $key, '', $user), 'Edit callback ignored');
        expectMenu($user['step'] === 'gatewayname_input_' . $key, 'Input step not saved');
        expectMenu(str_contains(end($sent)[2], 'نام جدید'), 'Edit prompt missing');
        $before = count($sent);
        expectMenu(paymentGatewayAdminHandle('', $label, $user), 'Label input ignored: ' . $key . ' / ' . $label);
        expectMenu($user['step'] === 'home' && gatewayUserLabel($key) === $label, 'Label was not saved');
        expectMenu(count($sent) === $before + 1 && end($sent)[1] === 'نام نمایشی درگاه ذخیره شد.', 'Save confirmation missing');
    }
}
paymentGatewayAdminHandle('gatewayname_edit_card', '', $user);
paymentGatewayAdminHandle('', 'واریز دستی', $user);
expectMenu(gatewayUserLabel('card') === 'واریز دستی', 'Original card gateway cannot be renamed');
foreach (['card', 'tonpays'] as $key) {
    paymentGatewayAdminHandle('gatewayname_edit_' . $key, '', $user);
    paymentGatewayAdminHandle('', 'کارت به کارت', $user);
}
$buttons = gatewayApplyLabels(['inline_keyboard' => [
    [['text' => 'old', 'callback_data' => 'cart_to_offline']],
    [['text' => 'old', 'callback_data' => 'tonpays']],
]])['inline_keyboard'];
expectMenu($buttons[0][0]['text'] === $buttons[1][0]['text']
    && $buttons[0][0]['callback_data'] === 'cart_to_offline'
    && $buttons[1][0]['callback_data'] === 'tonpays', 'Duplicate labels changed payment routing');

paymentGatewayAdminHandle('gatewayname_edit_card', '', $user);
foreach (['', str_repeat('ا', 65), "دو\nخط"] as $invalid) {
    $before = count($sent);
    expectMenu(paymentGatewayAdminHandle('', $invalid, $user), 'Invalid input not handled');
    expectMenu($user['step'] === 'gatewayname_input_card' && gatewayUserLabel('card') === 'کارت به کارت', 'Invalid label changed saved name or step');
    expectMenu(count($sent) === $before + 1, 'Invalid input had no response');
}
foreach (['/start', '/panel', 'panel', $textbotlang['Admin']['backAdminBtn'], $textbotlang['Admin']['backMenuBtn']] as $navigation) {
    expectMenu(!paymentGatewayAdminHandle('', $navigation, $user), 'Navigation captured as a label');
}
expectMenu(!paymentGatewayAdminHandle('paygwlist', '', $user), 'Gateway navigation captured as a label');
expectMenu(gatewayUserLabel('card') === 'کارت به کارت', 'Navigation overwrote label');
paymentGatewayAdminHandle('gatewayname_reset_card', '', $user);
expectMenu(gatewayUserLabel('card') === gatewayDefaultLabel('card'), 'Reset did not restore default label');
expectMenu($user['step'] === 'gatewayname_input_card', 'Reset did not retain edit step');
$user['step'] = 'home';
expectMenu(!paymentGatewayAdminHandle('', 'کارت به کارت', $user), 'Idle admin button consumed');
$user['step'] = 'tonpays_input_api_key';
expectMenu(!paymentGatewayAdminHandle('', 'کارت به کارت', $user), 'Admin button saved as API key');
restore_error_handler();
echo "Gateway name admin tests passed with warnings treated as errors\n";
