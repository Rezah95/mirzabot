<?php

set_error_handler(static function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require_once dirname(__DIR__) . '/renewal_reminders.php';
require_once dirname(__DIR__) . '/db/Schema.php';

function expectReminder(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}
function assertSqlIdentifier($name, $allowStar = false): void
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) { throw new RuntimeException('Invalid identifier'); }
}
function sendmessage($id, $text, $keyboard, $mode): void { $GLOBALS['uiMessages'][] = $text; }
function Editmessagetext($id, $messageId, $text, $keyboard): void
{
    expectReminder(isset(json_decode($keyboard, true)['inline_keyboard']), 'Admin edit uses non-inline keyboard');
    $GLOBALS['uiMessages'][] = $text;
}
function step($step, $id): void { $GLOBALS['adminUser']['step'] = $step; }
function savedata($type, $key, $value): void
{
    $data = $type === 'clear' ? [] : json_decode($GLOBALS['adminUser']['Processing_value'], true);
    $data[$key] = $value;
    $GLOBALS['adminUser']['Processing_value'] = json_encode($data);
}
function editFlowMessage($text, $keyboard): void { Editmessagetext(1, 2, $text, $keyboard); }
function discountPanelsKeyboard(): string { return '{"inline_keyboard":[]}'; }
function discountProductsKeyboard($panel): string { return discountPanelsKeyboard(); }
function discountCodesMenu(): array { return ['', discountPanelsKeyboard()]; }

function runDiscountAdmin(string $datain, string $text = ''): void
{
    global $pdo, $textbotlang;
    $from_id = 101;
    $message_id = 1;
    $adminrulecheck = ['rule' => 'administrator'];
    $user = $GLOBALS['adminUser'];
    $discountCodeFlowKeyboard = discountPanelsKeyboard();
    $source = file_get_contents(dirname(__DIR__) . '/admin.php');
    $start = strpos($source, '} elseif ($datain == "discountcode_create"');
    $end = strpos($source, '} elseif ($text == $textbotlang[\'keyboard\'][\'manageDiscountCode\']', $start);
    eval('if (false) {' . substr($source, $start, $end - $start) . '}');
}

$legacy = ['price' => '25'];
expectReminder(discountPrice($legacy, 100000) === 75000, 'Legacy percent price changed');
expectReminder(discountPrice(['discount_mode' => 'fixed', 'price' => 30000], 100000) === 70000, 'Fixed discount calculation');
expectReminder(discountPrice(['discount_mode' => 'fixed', 'price' => 30000], 10000) === 0, 'Fixed discount below zero');
expectReminder(discountPrice(['price' => 100], 10000) === 0, '100 percent discount');
foreach ([['price' => 101], ['price' => 0, 'discount_mode' => 'fixed'], ['price' => 100000001, 'discount_mode' => 'fixed'],
    ['price' => 10, 'discount_mode' => 'bogus']] as $invalid) {
    expectReminder(!discountValueValid($invalid), 'Invalid discount accepted');
}
$settings = ['interval_days' => 3, 'max_sends' => 3];
$base = time();
expectReminder(renewalReminderNextDue($settings, $base, []) === $base + 3 * 86400, 'First reminder is not day 3');
expectReminder(renewalReminderNextDue($settings, $base, ['sent_count' => 1, 'last_sent_at' => $base + 3 * 86400]) === $base + 6 * 86400, 'Second reminder is not day 6');
expectReminder(renewalReminderNextDue($settings, $base, ['sent_count' => 2, 'last_sent_at' => $base + 6 * 86400]) === $base + 9 * 86400, 'Third reminder is not day 9');
expectReminder(renewalReminderNextDue($settings, $base, ['sent_count' => 3]) === null, 'Reminder limit exceeded');
expectReminder(renewalReminderNextDue($settings, $base, ['sent_count' => 1, 'last_sent_at' => $base + 8 * 86400]) === $base + 11 * 86400, 'Downtime causes burst delivery');
expectReminder(renewalReminderExpiry(['expires_at' => $base, 'depleted_at' => $base - 86400]) === $base - 86400, 'Earlier volume exhaustion ignored');
expectReminder(renewalReminderObservedExpiry(['depleted_at' => $base], ['status' => 'active', 'expire' => $base + 86400, 'data_limit' => 100, 'used_traffic' => 1], $base + 1) === null, 'Renewed volume is treated as expired');
expectReminder(renewalReminderObservedExpiry([], ['status' => 'on_hold', 'expire' => -10], $base) === null, 'Unstarted service is expired');
echo "Discount and reminder unit checks passed\n";

$socket = getenv('REMINDER_TEST_SOCKET');
if (!$socket) { echo "Database checks skipped: set REMINDER_TEST_SOCKET to an isolated test MySQL socket\n"; exit; }
expectReminder(str_starts_with($socket, '/tmp/mirza-reminder-test.') && is_file(dirname($socket) . '/mysql.pid'), 'Use an isolated temporary MySQL server');
$pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db = 'mirza_reminder_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE $db");
try {
    $schema = new Schema($pdo);
    $definition = require dirname(__DIR__) . '/db/tables/DiscountSell.php';
    $legacyDefinition = $definition;
    $legacyDefinition['create'] = preg_replace('/^.*(?:discount_mode|target_user_id).*\n/m', '', $definition['create']);
    $schema->apply('DiscountSell', $legacyDefinition);
    $pdo->exec("INSERT INTO DiscountSell (codeDiscount, price, limitDiscount, agent, usefirst, useuser, code_product, code_panel, time, type, usedDiscount)
        VALUES ('legacy', '25', '10', 'allusers', '0', '1', 'all', '/all', '0', 'all', '2')");
    $schema->apply('DiscountSell', $definition);
    foreach (['invoice', 'Giftcodeconsumed', 'Discount', 'renewal_reminder_settings', 'renewal_reminder_state'] as $table) {
        $schema->apply($table, require dirname(__DIR__) . '/db/tables/' . $table . '.php');
    }
    $pdo->exec("CREATE TABLE user (id VARCHAR(200) PRIMARY KEY, User_Status VARCHAR(20) DEFAULT 'Active', status_cron INT DEFAULT 1, Balance BIGINT DEFAULT 100000)");
    $pdo->exec("INSERT INTO user (id) VALUES ('101'), ('102'), ('103')");
    $row = discountCodeRow($pdo, 'legacy');
    expectReminder($row['discount_mode'] === 'percent' && $row['usedDiscount'] === '2', 'Migration lost legacy discount data');
    expectReminder(discountPrice($row, 100000) === 75000, 'Migrated legacy code mispriced');

    $insert = $pdo->prepare("INSERT INTO invoice (id_invoice, id_user, username, name_product, Service_location, expires_at, depleted_at, Status, notifctions)
        VALUES (?, ?, ?, ?, 'test_panel', ?, ?, 'active', '{}')");
    $insert->execute(['ended', '101', 'ended_user', 'Paid product', $base, null]);
    $insert->execute(['still_active', '101', 'active_user', 'Paid product', $base + 90 * 86400, null]);
    $insert->execute(['old', '101', 'old_user', 'Paid product', $base - 86400, null]);
    $insert->execute(['test', '101', 'test_user', 'Test service', $base, null]);
    $messages = [];
    $read = static fn($invoice) => ['status' => $invoice['depleted_at'] ? 'limited' : 'expired', 'expire' => $invoice['expires_at'], 'data_limit' => 100, 'used_traffic' => $invoice['depleted_at'] ? 100 : 1];
    $send = static function ($invoice, $message) use (&$messages): array {
        $messages[] = [$invoice['id_invoice'], $message];
        return ['ok' => true];
    };
    $run = static fn(int $now) => renewalReminderRun($pdo, $read, $send, 'Test service', $now);
    expectReminder($run($base + 3 * 86400) === 0, 'Disabled worker sends messages');
    renewalReminderSave($pdo, ['enabled' => 1, 'discount_send' => 2, 'discount_mode' => 'fixed', 'discount_value' => 20000], $base);
    expectReminder($run($base + 3 * 86400 - 1) === 0, 'Reminder sent early');
    expectReminder($run($base + 3 * 86400) === 1, 'Day 3 send or another active service blocks delivery');
    expectReminder(count($messages) === 1 && $messages[0][0] === 'ended' && !str_contains($messages[0][1], 'کد تخفیف'), 'Wrong recipient or early coupon');
    expectReminder($run($base + 3 * 86400) === 0, 'Duplicate cron delivered twice');
    expectReminder($run($base + 6 * 86400) === 1, 'Day 6 send');
    $coupon = $pdo->query("SELECT * FROM DiscountSell WHERE target_user_id = '101'")->fetch(PDO::FETCH_ASSOC);
    expectReminder($coupon && $coupon['discount_mode'] === 'fixed' && $coupon['type'] === 'extend', 'Wrong automatic coupon');
    expectReminder(str_contains($messages[1][1], $coupon['codeDiscount']) && str_contains($messages[1][1], '20,000 تومان'), 'Coupon not included on selected send');
    expectReminder(!discountIsEligible($pdo, $coupon, '102', 'f', 'panel', 'product', 'extend', 'Test service'), 'Personal coupon reusable by another user');
    expectReminder(!discountIsEligible($pdo, $coupon, '101', 'f', 'panel', 'product', 'buy', 'Test service'), 'Renewal coupon accepted for purchase');
    $redeem = bin2hex(random_bytes(16));
    $charge = static function () use ($pdo): bool { return $pdo->exec("UPDATE user SET Balance = Balance - 80000 WHERE id = '101' AND Balance >= 80000") === 1; };
    expectReminder(discountConsume($pdo, $redeem, $coupon['codeDiscount'], '101', 'f', 'panel', 'product', 'extend', 'Test service', null, $charge), 'Coupon redemption failed');
    expectReminder((int) $pdo->query("SELECT Balance FROM user WHERE id = '101'")->fetchColumn() === 20000, 'Wrong wallet charge');
    expectReminder(!discountConsume($pdo, bin2hex(random_bytes(16)), $coupon['codeDiscount'], '101', 'f', 'panel', 'product', 'extend', 'Test service'), 'One-use coupon consumed twice');
    discountRelease($pdo, $redeem);
    expectReminder(discountIsEligible($pdo, discountCodeRow($pdo, $coupon['codeDiscount']), '101', 'f', 'panel', 'product', 'extend', 'Test service'), 'Release failed');
    expectReminder($run($base + 9 * 86400) === 1 && $run($base + 12 * 86400) === 0, 'Three-message maximum failed');
    expectReminder(count($messages) === 3, 'Expected exactly 3 messages in 9 days');

    // A new expiry cycle on the same invoice must start again after renewal.
    $pdo->exec("UPDATE invoice SET expires_at = " . ($base + 20 * 86400) . " WHERE id_invoice = 'ended'");
    expectReminder($run($base + 23 * 86400) === 1, 'New renewal cycle did not restart reminders');
    $pdo->exec("UPDATE invoice SET expires_at = " . ($base + 60 * 86400) . " WHERE id_invoice = 'ended'");
    expectReminder($run($base + 26 * 86400) === 0, 'Reminder after renewal');

    // Volume can end before the service expiry; fresh panel data wins over stale cache.
    $insert->execute(['volume', '102', 'volume_user', 'Paid product', $base + 90 * 86400, $base]);
    $freshActive = static fn($invoice) => ['status' => 'active', 'expire' => $base + 90 * 86400, 'data_limit' => 100, 'used_traffic' => 1];
    expectReminder(renewalReminderRun($pdo, $freshActive, $send, 'Test service', $base + 3 * 86400) === 0, 'Stale exhausted-volume cache caused delivery');
    expectReminder($run($base + 3 * 86400 + 3600) === 1, 'Exhausted volume not reminded');
    $pdo->exec("UPDATE invoice SET depleted_at = NULL WHERE id_invoice = 'volume'");
    expectReminder($run($base + 6 * 86400 + 3600) === 0, 'Volume top-up did not stop reminders');

    $insert->execute(['retry', '103', 'retry_user', 'Paid product', $base, null]);
    $rateLimit = static fn() => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 120]];
    expectReminder(renewalReminderRun($pdo, $read, $rateLimit, 'Test service', $base + 3 * 86400) === 0, 'Rate-limited delivery marked successful');
    expectReminder($run($base + 3 * 86400 + 119) === 0 && $run($base + 3 * 86400 + 120) === 1, 'Retry timing failed');
    $uncertain = static fn() => ['ok' => false];
    renewalReminderRun($pdo, $read, $uncertain, 'Test service', $base + 6 * 86400 + 120);
    expectReminder($run($base + 6 * 86400 + 121) === 0, 'Ambiguous delivery replayed');
    expectReminder((int) $pdo->query("SELECT COUNT(*) FROM DiscountSell WHERE target_user_id = '103'")->fetchColumn() === 1, 'Coupon duplicated on retry');
    $pdo->exec("UPDATE invoice SET Status = 'cancelled' WHERE id_invoice = 'retry'");
    expectReminder($run($base + 9 * 86400 + 120) === 0, 'Cancelled service notified');

    // Concurrent dispatchers must share the database lock.
    $second = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $db, 'root', '');
    $lockName = 'renewal_reminders_' . sha1($db);
    $second->prepare('SELECT GET_LOCK(?, 0)')->execute([$lockName]);
    expectReminder($run($base + 3 * 86400) === 0, 'Concurrent worker ignored lock');
    $second->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    $second = null;
    renewalReminderSave($pdo, ['include_existing' => 1]);
    expectReminder($run($base + 3 * 86400) === 1 && end($messages)[0] === 'old', 'Existing expired services option failed');
    expectReminder($run($base + 3 * 86400) === 0, 'Existing service got catch-up burst');
    renewalReminderSave($pdo, ['include_existing' => 0]);

    renewalReminderSave($pdo, ['enabled' => 0]);
    foreach (['renewal_reminder_settings', 'renewal_reminder_state', 'DiscountSell'] as $table) {
        $schema->apply($table, require dirname(__DIR__) . '/db/tables/' . $table . '.php');
    }
    $saved = renewalReminderSettings($pdo);
    expectReminder((int) $saved['enabled'] === 0 && (int) $saved['discount_send'] === 2 && (int) $saved['started_at'] === $base, 'Repeated migration reset settings');
    expectReminder((int) $pdo->query("SELECT COUNT(*) FROM renewal_reminder_state WHERE confirmed_count > 0")->fetchColumn() > 0, 'Repeated migration removed delivery state');

    // Exercise the actual bot admin branches, with Telegram replaced by a recorder.
    $textbotlang = require dirname(__DIR__) . '/lang/fa.php';
    $adminUser = ['step' => 'home', 'Processing_value' => '{}'];
    $uiMessages = [];
    foreach (['fixed' => 40000, 'percent' => 20] as $mode => $value) {
        runDiscountAdmin('discountcode_create');
        runDiscountAdmin('', 'admin' . $mode);
        expectReminder($adminUser['step'] === 'get_discount_mode', 'Missing discount mode selection');
        runDiscountAdmin('discountmode_' . $mode);
        runDiscountAdmin('', (string) $value);
        runDiscountAdmin('', '1');
        runDiscountAdmin('discountagent_allusers');
        runDiscountAdmin('', '0');
        runDiscountAdmin('discountlimitbuy_0');
        runDiscountAdmin('discounttype_all');
        runDiscountAdmin('', '1');
        runDiscountAdmin('discountpanel_all');
        runDiscountAdmin('discountproduct_all');
        $made = discountCodeRow($pdo, 'admin' . $mode);
        expectReminder($made && $made['discount_mode'] === $mode && (int) $made['price'] === $value, 'Bot discount wizard failed');
        expectReminder(str_contains(end($uiMessages), discountValueLabel($made)), 'Wrong discount label in success message');
    }
    require_once dirname(__DIR__) . '/renewal_reminders_admin.php';
    $from_id = 101;
    $message_id = 1;
    expectReminder(renewalReminderAdminHandle('renewal_menu', '', $adminUser), 'Reminder menu not handled');
    renewalReminderAdminHandle('renewal_edit_interval_days', '', $adminUser);
    renewalReminderAdminHandle('', '5', $adminUser);
    expectReminder((int) renewalReminderSettings($pdo)['interval_days'] === 5, 'Reminder interval edit failed');
    renewalReminderAdminHandle('renewal_edit_max_sends', '', $adminUser);
    renewalReminderAdminHandle('', '1', $adminUser);
    expectReminder((int) renewalReminderSettings($pdo)['max_sends'] === 3, 'Discount send greater than max accepted');
    renewalReminderAdminHandle('renewal_edit_message_template', '', $adminUser);
    renewalReminderAdminHandle('', 'سلام {username} — {service} — {days} — {send_number}', $adminUser);
    $codesBefore = (int) $pdo->query('SELECT COUNT(*) FROM DiscountSell')->fetchColumn();
    renewalReminderAdminHandle('renewal_preview', '', $adminUser);
    expectReminder((int) $pdo->query('SELECT COUNT(*) FROM DiscountSell')->fetchColumn() === $codesBefore, 'Preview created a real coupon');
    expectReminder(str_contains(end($uiMessages), 'samplecode'), 'Discount not appended to custom template');
    $render = renewalReminderRender(renewalReminderSettings($pdo), ['username' => '<bad>', 'name_product' => '&test', 'expires_at' => $base], 1, $base);
    expectReminder(str_contains($render, '&lt;bad&gt;') && !str_contains($render, '<bad>'), 'Reminder HTML escaping failed');
    echo "Isolated MySQL integration checks passed: migration, 3/6/9 days, renewal, volume, retries, personal fixed coupons\n";
} finally {
    $pdo->exec("DROP DATABASE $db");
}
