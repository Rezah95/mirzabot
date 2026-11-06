<?php
// Execute the actual early webhook handlers without config, Telegram or a database.
set_error_handler(static function ($level, $message, $file, $line) { throw new ErrorException($message, 0, $level, $file, $line); });
function starCheck($ok, $message): void { if (!$ok) { throw new RuntimeException($message); } }
function select($table, ...$args) {
    return match ($table) {
        'Payment_report' => $GLOBALS['order'], 'PaySetting' => ['ValuePay' => 10],
        'user' => ['id' => 1, 'username' => 'test', 'Balance' => 0], 'topicid' => ['idreport' => 1],
    };
}
function telegram($method, $args): void { $GLOBALS['calls'][] = [$method, $args]; }
function update($table, $field, $value, ...$args): void { $GLOBALS['order'][$field] = $value; }
function claimPaymentPaid($id): bool { $GLOBALS['claims']++; return $GLOBALS['claim']; }
function DirectPayment($id): bool { $GLOBALS['deliveries']++; if ($GLOBALS['throws']) { throw new RuntimeException('fixture'); } return $GLOBALS['delivered']; }
function markPaymentFulfillment($id, $status): void { $GLOBALS['state'] = $status; }
function addBalance($id, $amount): void { $GLOBALS['cashback'] += $amount; }
function sendmessage(...$args): void {}
$source = file_get_contents(dirname(__DIR__) . '/index.php');
$start = strpos($source, "if (isset(\$update['pre_checkout_query'])) {");
$end = strpos($source, '#-------------Variable----------#', $start);
starCheck($start !== false && $end !== false, 'Early handler missing');
$handler = substr($source, $start, $end - $start);
$run = static function ($update) use ($handler) { $setting = ['Channel_Report' => '']; $keyboard = null; $textbotlang = require dirname(__DIR__) . '/lang/fa.php'; eval($handler); };
$cases = 0;
foreach (['checkout', 'success'] as $event) {
    foreach (['valid', 'currency', 'amount', 'owner', 'method', 'missing_amount', 'claimed', 'refund', 'exception'] as $scenario) {
        $order = ['id_order' => 'p', 'id_user' => 1, 'Payment_Method' => 'Star Telegram', 'payment_Status' => 'Unpaid', 'price' => 100, 'dec_not_confirmed' => json_encode(['star_amount' => 5])];
        $payload = ['invoice_payload' => 'p', 'currency' => 'XTR', 'total_amount' => 5, 'telegram_payment_charge_id' => 'charge'];
        if ($scenario === 'currency') { $payload['currency'] = 'USD'; }
        if ($scenario === 'amount') { $payload['total_amount'] = 6; }
        if ($scenario === 'method') { $order['Payment_Method'] = 'other'; }
        if ($scenario === 'missing_amount') { $order['dec_not_confirmed'] = '{}'; }
        $uid = $scenario === 'owner' ? 2 : 1;
        $claims = $deliveries = $cashback = 0; $calls = []; $state = null;
        $claim = $scenario !== 'claimed'; $delivered = $scenario !== 'refund'; $throws = $scenario === 'exception';
        $valid = !in_array($scenario, ['currency', 'amount', 'owner', 'method', 'missing_amount']);
        $update = $event === 'checkout' ? ['pre_checkout_query' => $payload + ['id' => 'q', 'from' => ['id' => $uid]]] : ['message' => ['from' => ['id' => $uid], 'successful_payment' => $payload]];
        $run($update);
        if ($event === 'checkout') {
            starCheck(count($calls) === 1 && $calls[0][0] === 'answerPreCheckoutQuery' && $calls[0][1]['ok'] === $valid, 'Checkout validation');
            starCheck($claims === 0 && $deliveries === 0, 'Checkout delivered early');
        } else {
            starCheck($claims === ($valid ? 1 : 0), 'Invalid payment claimed');
            starCheck($deliveries === ($valid && $claim ? 1 : 0), 'Duplicate/invalid delivery');
            starCheck($cashback === ($valid && $claim && $delivered && !$throws ? 10 : 0), 'Cashback on failed delivery');
            if ($throws && $valid && $claim) { starCheck($state === 'failed', 'Missing failure state'); }
        }
        $cases++;
    }
}
echo "Stars merge: $cases webhook cases passed without live Telegram\n";
