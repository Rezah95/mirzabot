<?php
// Requires an isolated temporary MySQL instance. Never reads the bot's config.php.
set_error_handler(static function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function checkTonpays(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function assertSqlIdentifier($name, $allowStar = false): void { checkTonpays((bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name), 'Bad identifier'); }
function clearSelectCache($table): void {}
function getPaySettingValue($key, $default = '') {
    global $pdo;
    $q = $pdo->prepare('SELECT ValuePay FROM PaySetting WHERE NamePay = ?'); $q->execute([$key]);
    $value = $q->fetchColumn(); return $value === false ? $default : $value;
}
function step($step, $id): void { $GLOBALS['adminUser']['step'] = $step; }
function sendmessage($id, $text, $keyboard, $mode): void { $GLOBALS['messages'][] = $text; }
function Editmessagetext($id, $messageId, $text, $keyboard): void {
    checkTonpays(is_array(json_decode($keyboard, true)['inline_keyboard'] ?? null), 'Non-inline admin keyboard');
    $GLOBALS['messages'][] = $text;
}
require_once dirname(__DIR__) . '/payment/tonpays_lib.php';
require_once dirname(__DIR__) . '/gateway_labels.php';
require_once dirname(__DIR__) . '/gateway_settings_admin.php';
require_once dirname(__DIR__) . '/db/Schema.php';
$socket = getenv('TONPAYS_TEST_SOCKET');
if (!$socket) { echo "Set TONPAYS_TEST_SOCKET to an isolated test MySQL socket\n"; exit(1); }
checkTonpays(str_starts_with($socket, '/tmp/mirza-tonpays-test.') && is_file(dirname($socket) . '/mysql.pid'), 'Refusing a non-test database');
define('TONPAYS_TEST_LOG_FILE', tempnam(sys_get_temp_dir(), 'mirza-tonpays-callback-log-'));
$pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db = 'tonpays_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $pdo->exec("USE $db");
    $textbotlang = require dirname(__DIR__) . '/lang/fa.php';
    $schema = new Schema($pdo, ['textbotlang' => $textbotlang]);
    $definition = require dirname(__DIR__) . '/db/tables/PaySetting.php';
    $schema->apply('PaySetting', $definition);
    $schema->apply('Payment_report', require dirname(__DIR__) . '/db/tables/Payment_report.php');
    $pdo->exec('CREATE TABLE test_wallet (id INT PRIMARY KEY, balance BIGINT NOT NULL)');
    $pdo->exec('INSERT INTO test_wallet VALUES (123, 0)');
    $pdo->exec("UPDATE PaySetting SET ValuePay = 'کارت به کارت' WHERE NamePay = 'tetraminator_label'");
    gatewaySaveSetting($pdo, 'tonpays_api_key', 'test-tonpays-key');
    gatewaySaveSetting($pdo, 'gateway_label_tetraminator', 'دلخواه');
    $schema->apply('PaySetting', $definition);
    checkTonpays(getPaySettingValue('tonpays_api_key') === 'test-tonpays-key' && gatewayUserLabel('tetraminator') === 'دلخواه', 'Repeated migration erased settings');
    $schema->apply('PaySetting', $definition);
    checkTonpays(getPaySettingValue('tetraminator_label') === 'کارت به کارت' && getPaySettingValue('tonpays_status') === 'offtonpays', 'Legacy name/default status changed');
    $verified = ['invoice_id' => 'TP-ABC123XYZ0', 'order_id' => '0123456789abcdef0123',
        'request_amount' => 50000, 'final_amount' => 50037, 'status' => 'completed', 'paid' => true];
    $metadata = array_intersect_key($verified, array_flip(['invoice_id', 'request_amount', 'final_amount']));
    $callback = ['invoice_id' => $verified['invoice_id'], 'order_id' => $verified['order_id'], 'paid' => true];
    $pdo->prepare('INSERT INTO Payment_report (id_user,id_order,price,Payment_Method,payment_Status,dec_not_confirmed) VALUES (?,?,?,?,?,?)')
        ->execute(['123', $verified['order_id'], 50000, 'TonPays', 'Unpaid', json_encode($metadata)]);
    $deliveries = 0;
    $deliver = static function ($order) use ($pdo, &$deliveries): bool {
        $deliveries++;
        $pdo->prepare('UPDATE test_wallet SET balance = balance + ? WHERE id = ?')->execute([$order['price'], $order['id_user']]);
        return true;
    };
    $run = static function ($body = null, $key = 'test-tonpays-key', $inquiry = null, $delivery = null) use ($pdo, &$verified, $callback, $deliver): array {
        return tonpaysProcessCallback($pdo, $body ?? $callback, $key, 'test-tonpays-key',
            $inquiry ?? static fn($id) => $verified, $delivery ?? $deliver);
    };
    checkTonpays($run(null, 'wrong')[0] === 401, 'Unauthenticated callback accepted');
    checkTonpays($run(array_replace($callback, ['invoice_id' => 'TP-other']))[0] === 409, 'Wrong invoice accepted');
    checkTonpays($run(array_replace($callback, ['order_id' => 'ffffffffffffffffffff']))[0] === 404, 'Unknown order accepted');
    checkTonpays($run(['order_id' => []])[0] === 400, 'Malformed callback accepted');
    checkTonpays($run(null, 'test-tonpays-key', static function () { throw new RuntimeException('offline'); })[0] === 502, 'Inquiry outage accepted');
    foreach ([['request_amount' => 50037], ['final_amount' => 50000], ['order_id' => 'ffffffffffffffffffff'], ['paid' => 'true']] as $bad) {
        checkTonpays($run(null, 'test-tonpays-key', static fn() => array_replace($verified, $bad))[0] === 409, 'Bad verified invoice accepted');
    }
    $verified['paid'] = false;
    checkTonpays($run()[1]['paid'] === false && $deliveries === 0, 'Callback spoofed payment status');
    $verified['paid'] = true;
    $concurrent = null;
    $duringDelivery = static function ($order) use ($run, $deliver, &$concurrent): bool {
        $concurrent = $run();
        return $deliver($order);
    };
    checkTonpays($run(null, 'test-tonpays-key', null, $duringDelivery)[0] === 200 && $concurrent[0] === 503, 'Concurrent callback was not reserved');
    checkTonpays($run()[0] === 200 && $deliveries === 1 && (int) $pdo->query('SELECT balance FROM test_wallet')->fetchColumn() === 50000, 'Duplicate payment or wrong credited amount');
    // Two callbacks can both read Unpaid before either claims the order.
    $pdo->exec("UPDATE Payment_report SET payment_Status = 'Unpaid', fulfillment_status = NULL");
    $pdo->exec('UPDATE test_wallet SET balance = 0');
    $deliveries = 0;
    $racingInquiry = static function () use ($run, &$verified): array {
        checkTonpays($run()[0] === 200, 'Competing callback did not complete');
        return $verified;
    };
    checkTonpays($run(null, 'test-tonpays-key', $racingInquiry)[0] === 503 && $deliveries === 1
        && (int) $pdo->query('SELECT balance FROM test_wallet')->fetchColumn() === 50000, 'Stale reader credited again');
    $pdo->exec("UPDATE Payment_report SET payment_Status = 'Unpaid', fulfillment_status = NULL");
    $failed = static function ($order) use ($deliver): void { $deliver($order); throw new RuntimeException('After side effect'); };
    checkTonpays($run(null, 'test-tonpays-key', null, $failed)[0] === 500 && $run()[0] === 503 && $deliveries === 2, 'Failed delivery was automatically repeated');
    $pdo->exec("UPDATE Payment_report SET payment_Status = 'Unpaid', fulfillment_status = NULL");
    checkTonpays($run(null, 'test-tonpays-key', null, static fn() => false)[0] === 200 && $run()[0] === 200, 'Refunded fulfillment repeated');
    $pdo->exec("UPDATE Payment_report SET Payment_Method = 'Tronado'");
    checkTonpays($run()[0] === 404, 'Other gateway order accepted');
    $log = file_get_contents(TONPAYS_TEST_LOG_FILE);
    checkTonpays(str_contains($log, 'inquiry_failed') && str_contains($log, 'offline')
        && str_contains($log, 'delivery_failed') && str_contains($log, 'After side effect'), 'Callback errors lost their causes');

    $from_id = 123; $message_id = 1; $adminUser = ['step' => 'home']; $messages = []; $paymentGateways = [];
    checkTonpays(paymentGatewayAdminHandle('tonpays_errors', '', $adminUser), 'Diagnostics menu unhandled');
    checkTonpays(str_contains(end($messages), 'delivery_failed') && !str_contains(end($messages), 'test-tonpays-key'), 'Admin cannot read safe diagnostics');
    checkTonpays(paymentGatewayAdminHandle('gatewayname_edit_tetraminator', '', $adminUser), 'Rename option unhandled');
    checkTonpays($adminUser['step'] === 'gatewayname_input_tetraminator', 'Rename input state missing');
    paymentGatewayAdminHandle('', "نام\nنام", $adminUser);
    checkTonpays(gatewayUserLabel('tetraminator') === 'دلخواه', 'Invalid name was saved');
    paymentGatewayAdminHandle('', '💎 پرداخت آسان', $adminUser);
    checkTonpays($adminUser['step'] === 'home' && gatewayUserLabel('tetraminator') === '💎 پرداخت آسان', 'Name did not persist');
    paymentGatewayAdminHandle('gatewayname_reset_tetraminator', '', $adminUser);
    checkTonpays(gatewayUserLabel('tetraminator') === 'کارت به کارت', 'Reset erased the old name');
    paymentGatewayAdminHandle('gatewayname_list', '', $adminUser);
    checkTonpays($adminUser['step'] === 'home', 'Back left stale rename state');
    paymentGatewayAdminHandle('gatewayname_edit_tetraminator', '', $adminUser);
    checkTonpays(!paymentGatewayAdminHandle('', $textbotlang['keyboard']['financial'], $adminUser)
        && gatewayUserLabel('tetraminator') === 'کارت به کارت', 'Navigation text replaced the label');
    checkTonpays(!paymentGatewayAdminHandle('gatewayname_edit_invalid', '', $adminUser), 'Invalid gateway accepted');
    paymentGatewayAdminHandle('tonpays_set_api_key', '', $adminUser);
    paymentGatewayAdminHandle('', 'replacement-test-key', $adminUser);
    checkTonpays(getPaySettingValue('tonpays_api_key') === 'replacement-test-key' && !str_contains(implode(' ', $messages), 'replacement-test-key'), 'API key not saved or echoed');
    paymentGatewayAdminHandle('tonpays_set_min', '', $adminUser);
    paymentGatewayAdminHandle('', '۵۰۰۰۰', $adminUser);
    checkTonpays(getPaySettingValue('tonpays_min') === '50000', 'Persian amount not saved');
    paymentGatewayAdminHandle('tonpays_set_max', '', $adminUser);
    paymentGatewayAdminHandle('', '49999', $adminUser);
    checkTonpays(getPaySettingValue('tonpays_max') === '1000000' && $adminUser['step'] === 'tonpays_input_max', 'Invalid bounds saved');
    paymentGatewayAdminHandle('gatewayorder_list', '', $adminUser);
    checkTonpays($adminUser['step'] === 'home', 'Order menu kept stale API/label input');
    paymentGatewayAdminHandle('gatewayorder_pick_tonpays', '', $adminUser);
    $positionButtons = array_merge(...json_decode(gatewayOrderPositionKeyboard('tonpays'), true)['inline_keyboard']);
    checkTonpays($positionButtons[0]['callback_data'] === 'gatewayorder_place_tonpays_1', 'Position chooser not linked');
    paymentGatewayAdminHandle('gatewayorder_place_tonpays_1', '', $adminUser);
    checkTonpays(gatewayDisplayOrder()[0] === 'tonpays', 'Direct placement not persisted');
    paymentGatewayAdminHandle('gatewayorder_down_tonpays', '', $adminUser);
    checkTonpays(gatewayDisplayOrder()[1] === 'tonpays', 'Move down not persisted');
    paymentGatewayAdminHandle('gatewayorder_up_tonpays', '', $adminUser);
    paymentGatewayAdminHandle('gatewayorder_up_tonpays', '', $adminUser);
    checkTonpays(gatewayDisplayOrder()[0] === 'tonpays', 'Moving the first gateway up broke the order');
    $savedOrder = getPaySettingValue('gateway_display_order');
    $schema->apply('PaySetting', $definition);
    checkTonpays(getPaySettingValue('gateway_display_order') === $savedOrder, 'Migration erased the order');
    paymentGatewayAdminHandle('gatewayorder_place_tonpays_99', '', $adminUser);
    checkTonpays(!paymentGatewayAdminHandle('gatewayorder_up_unknown', '', $adminUser)
        && getPaySettingValue('gateway_display_order') === $savedOrder, 'Invalid move changed the order');
    $last = count(gatewayDefaultOrder());
    paymentGatewayAdminHandle('gatewayorder_place_tonpays_' . $last, '', $adminUser);
    paymentGatewayAdminHandle('gatewayorder_down_tonpays', '', $adminUser);
    checkTonpays(gatewayDisplayOrder()[$last - 1] === 'tonpays', 'Last position failed');
    foreach (json_decode(gatewayOrderKeyboard(), true)['inline_keyboard'] as $row) {
        foreach ($row as $button) { checkTonpays(strlen($button['callback_data']) <= 64, 'Callback exceeds Telegram limit'); }
    }
    paymentGatewayAdminHandle('gatewayorder_reset', '', $adminUser);
    checkTonpays(gatewayDisplayOrder() === gatewayDefaultOrder() && gatewayUserLabel('tetraminator') === 'کارت به کارت'
        && getPaySettingValue('tonpays_api_key') === 'replacement-test-key', 'Reset affected gateway names or credentials');
    // Exercise the actual creation route: failures before the HTTP call must also be logged.
    gatewaySaveSetting($pdo, 'tonpays_status', 'ontonpays');
    $indexSource = file_get_contents(dirname(__DIR__) . '/index.php');
    $start = strpos($indexSource, "} elseif (\$datain === 'tonpays') {");
    $end = strpos($indexSource, "} elseif (\$datain === 'tronadopay') {", $start);
    $creationRoute = 'if (false) {' . substr($indexSource, $start, $end - $start) . '}';
    $datain = 'tonpays'; $from_id = -1; $keyboard = null; $domainhosts = 'bot.example.com';
    $user = ['Processing_value' => '50000', 'Processing_value_tow' => 'addbalance', 'Processing_value_one' => '0'];
    eval($creationRoute); // Invalid Telegram ID fails locally, with no gateway request.
    checkTonpays(str_contains(end($messages), 'TP-') && $pdo->query("SELECT payment_Status FROM Payment_report WHERE id_user = '-1'")->fetchColumn() === 'reject', 'Creation error was not reported or rejected');
    $pdo->exec('RENAME TABLE Payment_report TO saved_payment_report');
    try { eval($creationRoute); } finally { $pdo->exec('RENAME TABLE saved_payment_report TO Payment_report'); }
    $entries = array_map(static fn($line) => json_decode($line, true), array_filter(explode("\n", file_get_contents(TONPAYS_TEST_LOG_FILE))));
    $lastError = end($entries);
    checkTonpays($lastError['event'] === 'order_creation_failed' && $lastError['stage'] === 'save_payment_record'
        && $lastError['exception'] === 'PDOException' && str_contains(end($messages), $lastError['reference']), 'Database creation failure has no traceable log');
    echo "TonPays MySQL callback, duplicate delivery, migration preservation, admin settings and gateway order tests passed\n";
} finally {
    $pdo->exec("DROP DATABASE $db");
    unlink(TONPAYS_TEST_LOG_FILE);
    restore_error_handler();
}
