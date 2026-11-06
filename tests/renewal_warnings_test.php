<?php
// Thresholds, persistence, real coupon restrictions and admin navigation; isolated MySQL only.
set_error_handler(static function ($level, $message, $file, $line) { if (error_reporting() & $level) { throw new ErrorException($message, 0, $level, $file, $line); } return false; });
require_once dirname(__DIR__) . '/renewal_warnings_admin.php';
require_once dirname(__DIR__) . '/db/Schema.php';
function offerCheck($ok, $message): void { if (!$ok) { throw new RuntimeException($message); } }
function assertSqlIdentifier($name): void { offerCheck((bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name), 'Bad identifier'); }
function clearSelectCache($table): void {}
function sendmessage(...$args): void { $GLOBALS['messages'][] = $args[1]; $GLOBALS['lastKeyboard'] = $args[2]; }
function Editmessagetext(...$args): void { offerCheck(isset(json_decode($args[3], true)['inline_keyboard']), 'Invalid inline keyboard'); $GLOBALS['messages'][] = $args[2]; $GLOBALS['lastKeyboard'] = $args[3]; }
function step($value, $id): void { $GLOBALS['adminUser']['step'] = $value; }
$source = file_get_contents(dirname(__DIR__) . '/function.php');
$a = strpos($source, 'function ensureTableUtf8mb4('); $b = strpos($source, 'function ensureCardNumberTableSupportsUnicode(', $a);
eval(substr($source, $a, $b - $a));
$textbotlang = require dirname(__DIR__) . '/lang/fa.php';
$base = time(); $gb = 1073741824;
$settings = ['warning_enabled' => 1, 'warning_days' => 3, 'warning_gb' => 5, 'warning_percent' => 10, 'warning_valid_days' => 7];
$fresh = ['status' => 'active', 'expire' => $base + 3 * 86400, 'data_limit' => 10 * $gb, 'used_traffic' => 5 * $gb];
offerCheck(count(renewalWarningTriggers($settings, $fresh, $base)) === 2, 'Inclusive thresholds');
offerCheck(renewalWarningTriggers($settings, array_replace($fresh, ['expire' => $base + 3 * 86400 + 1, 'used_traffic' => 5 * $gb - 1]), $base) === [], 'Outside thresholds');
foreach ([['status' => 'on_hold'], ['status' => 'expired'], ['expire' => $base], ['used_traffic' => 10 * $gb]] as $change) {
    offerCheck(renewalWarningTriggers($settings, array_replace($fresh, $change), $base) === [], 'Ended/unstarted service received offer');
}
offerCheck(renewalWarningTriggers(array_replace($settings, ['warning_enabled' => 0]), $fresh, $base) === [], 'Disabled warning');
offerCheck(renewalWarningTriggers($settings, array_replace($fresh, ['expire' => 0, 'data_limit' => 0]), $base) === [], 'Unlimited service');
$socket = getenv('WARNING_TEST_SOCKET');
if (!$socket) { echo "Renewal warning threshold checks passed; SQL skipped (set WARNING_TEST_SOCKET)\n"; exit; }
offerCheck(str_starts_with($socket, '/tmp/mirza-warning-test.') && is_file(dirname($socket) . '/mysql.pid'), 'Isolated MySQL required');
$pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db = 'warning_test_' . bin2hex(random_bytes(5)); $pdo->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $pdo->exec("USE $db"); $schema = new Schema($pdo);
    $legacy = require dirname(__DIR__) . '/db/tables/renewal_reminder_settings.php';
    $legacy['create'] = preg_replace('/^.*warning_.*\n/m', '', $legacy['create']); unset($legacy['columns']);
    $schema->apply('renewal_reminder_settings', $legacy);
    $pdo->exec("UPDATE renewal_reminder_settings SET message_template='custom فارسی 😀', discount_value=25, enabled=1");
    for ($i = 0; $i < 2; $i++) {
        foreach (['renewal_reminder_settings', 'renewal_warning_state', 'DiscountSell', 'Giftcodeconsumed', 'Discount', 'service_other'] as $table) {
            $schema->apply($table, require dirname(__DIR__) . '/db/tables/' . $table . '.php');
        }
    }
    $s = renewalReminderSettings($pdo);
    offerCheck($s['message_template'] === 'custom فارسی 😀' && (int) $s['discount_value'] === 25 && (int) $s['enabled'] === 1 && (int) $s['warning_enabled'] === 0, 'Upgrade changed existing settings');
    $from_id = 101; $message_id = 2; $messages = []; $adminUser = ['step' => 'home'];
    renewalWarningAdminHandle('renewal_offer_preset', '', $adminUser);
    $s = renewalReminderSettings($pdo);
    offerCheck((int) $s['warning_enabled'] === 1 && (int) $s['warning_percent'] === 10 && (int) $s['discount_value'] === 25, 'Preset changed after-expiry discount');
    renewalWarningAdminHandle('renewal_offer_edit_days', '', $adminUser);
    offerCheck($adminUser['step'] === 'renewal_offer_input_days', 'Edit flow');
    renewalWarningAdminHandle('', '۳', $adminUser);
    offerCheck((int) renewalReminderSettings($pdo)['warning_days'] === 3 && $adminUser['step'] === 'home', 'Persian input/save');
    foreach (['renewal_offer_preview_time', 'renewal_offer_preview_volume'] as $action) {
        renewalWarningAdminHandle($action, '', $adminUser);
        offerCheck(str_contains(end($messages), 'SAMPLE'), 'Preview missing example');
    }
    offerCheck((int) $pdo->query('SELECT COUNT(*) FROM DiscountSell')->fetchColumn() === 0, 'Preview created redeemable discount');
    $domainhosts = 'bot.example.test';
    [$menu, $keyboard] = renewalWarningMenu($pdo, $textbotlang);
    $buttons = array_merge(...json_decode($keyboard, true)['inline_keyboard']);
    offerCheck(count(array_filter($buttons, static fn($b) => ($b['url'] ?? '') === 'https://bot.example.test/panel/bottext.php?group=renewalOffers')) === 1, 'Missing direct text-editor link');
    renewalWarningAdminHandle('renewal_offer_edit_percent', '', $adminUser);
    renewalWarningAdminHandle('', '101', $adminUser);
    offerCheck((int) renewalReminderSettings($pdo)['warning_percent'] === 10 && $adminUser['step'] === 'renewal_offer_input_percent', 'Invalid percentage saved or retry lost');
    renewalWarningAdminHandle('', $textbotlang['renewalOffers']['back'], $adminUser);
    offerCheck($adminUser['step'] === 'home', 'Back trapped the admin in input');
    renewalReminderSave($pdo, ['warning_days' => 0]);
    $invalid = false;
    try { renewalReminderSave($pdo, ['warning_gb' => 0]); } catch (InvalidArgumentException $e) { $invalid = true; }
    offerCheck($invalid && (int) renewalReminderSettings($pdo)['warning_gb'] === 5, 'Enabled offers accepted no triggers');
    renewalWarningAdminHandle('renewal_offer_preset', '', $adminUser);
    $s = renewalReminderSettings($pdo);
    $user = ['id' => '101', 'User_Status' => 'Active', 'status_cron' => 1];
    $invoice = ['id_invoice' => 'i', 'id_user' => '101', 'Status' => 'active', 'username' => 'sample<&', 'name_product' => 'Plan فارسی 😀'];
    $sent = [];
    $send = static function ($invoice, $message) use (&$sent) { $sent[] = $message; return ['ok' => true]; };
    $notify = static function ($data = null, $sender = null, $now = null, $owner = null) use (&$s, &$invoice, &$fresh, &$user, $pdo, $send, &$textbotlang, $base) {
        return renewalWarningNotify($pdo, $s, $invoice, $owner ?? $user, $data ?? $fresh, $sender ?? $send, $textbotlang, $now ?? $base);
    };
    offerCheck(count($notify()) === 2 && count($sent) === 2 && str_contains($sent[0], 'sample&lt;&amp;') && str_contains($sent[0], '<code>'), 'Threshold delivery/HTML escaping');
    $notify(); offerCheck(count($sent) === 2, 'Duplicate send');
    $coupons = $pdo->query('SELECT * FROM DiscountSell')->fetchAll(PDO::FETCH_ASSOC);
    offerCheck(count($coupons) === 2, 'Duplicate coupons');
    foreach ($coupons as $coupon) {
        offerCheck(discountIsEligible($pdo, $coupon, '101', 'f', 'another-panel', 'another-product', 'extend', 'Test service'), 'Renewal on another service rejected');
        offerCheck(!discountIsEligible($pdo, $coupon, '102', 'f', 'panel', 'product', 'extend', 'Test service'), 'Other user accepted');
        offerCheck(!discountIsEligible($pdo, $coupon, '101', 'f', 'panel', 'product', 'buy', 'Test service'), 'New purchase accepted');
        $rid = bin2hex(random_bytes(16));
        offerCheck(discountConsume($pdo, $rid, $coupon['codeDiscount'], '101', 'f', 'panel', 'product', 'extend', 'Test service'), 'Coupon could not be consumed');
        offerCheck(!discountConsume($pdo, bin2hex(random_bytes(16)), $coupon['codeDiscount'], '101', 'f', 'panel', 'product', 'extend', 'Test service'), 'Coupon consumed twice');
    }
    // A renewal with unchanged unlimited expiry/quota still starts a new warning cycle.
    $fresh['expire'] = 0; $invoice['id_invoice'] = 'unlimited';
    $notify(); $count = count($sent);
    $pdo->exec("INSERT INTO service_other (id_user,username,value,time,price,type,status,output) VALUES ('101','sample<&','{}','2026/11/06 00:00:00','100','extend_user','paid','{}')");
    $notify(); offerCheck(count($sent) === $count + 1, 'Unlimited-time renewal failed to rearm warning');
    $invoice['id_invoice'] = 'rate-limit';
    $notify(null, static fn() => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 60]]);
    $before = (int) $pdo->query('SELECT COUNT(*) FROM DiscountSell')->fetchColumn(); $count = count($sent);
    $notify(null, null, $base + 59); offerCheck(count($sent) === $count, '429 backoff ignored');
    $notify(null, null, $base + 60);
    offerCheck(count($sent) === $count + 1 && (int) $pdo->query('SELECT COUNT(*) FROM DiscountSell')->fetchColumn() === $before, 'Retry created a second coupon');
    $invoice['id_invoice'] = 'uncertain'; $notify(null, static fn() => []); $count = count($sent); $notify();
    offerCheck(count($sent) === $count, 'Ambiguous message replayed');
    $invoice['id_invoice'] = 'blocked'; $notify(null, static fn() => ['ok' => false, 'error_code' => 403]); $count = count($sent); $notify();
    offerCheck(count($sent) === $count, 'Blocked delivery repeated');
    foreach ([['User_Status' => 'block'], ['status_cron' => 0], ['id' => '102']] as $change) {
        $invoice['id_invoice'] = bin2hex(random_bytes(4));
        offerCheck($notify(null, null, null, array_replace($user, $change)) === [], 'Ineligible user notified');
    }
    // Same override merge used by runtime; web editor must expose the full new root.
    $override = ['renewalOffers' => ['volume' => 'سفارشی 😀 {username} {remaining_gb}']];
    $custom = array_replace_recursive($textbotlang, $override);
    $rendered = renewalWarningRender($custom, $invoice, 'volume', ['{remaining_gb}' => '5'], $coupons[0]);
    offerCheck(str_contains($rendered, 'سفارشی 😀') && str_contains($rendered, 'sample&lt;&amp;'), 'Override lost');
    $web = file_get_contents(dirname(__DIR__) . '/panel/bottext.php');
    offerCheck(!str_contains($web, "'renewalOffers'"), 'New root unexpectedly excluded from web editor');
    foreach (['fa', 'en', 'ru', 'zh'] as $code) {
        $l = require dirname(__DIR__) . '/lang/' . $code . '.php';
        offerCheck(array_keys($l['renewalOffers']) === array_keys($textbotlang['renewalOffers']), 'Locale key mismatch');
        offerCheck(isset($l['panel']['bottextGroups']['renewalOffers']), 'Missing web group label');
    }
    echo "Renewal offers: MySQL migration, both thresholds, deduplication, renewal cycles, personal renewal-only coupons, retries, admin flow and localization passed\n";
} finally { $pdo->exec("DROP DATABASE $db"); }
