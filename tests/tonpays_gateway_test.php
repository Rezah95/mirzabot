<?php
// php tests/tonpays_gateway_test.php — no real gateway or bot requests.
if (PHP_SAPI === 'cli-server') {
    header('Content-Type: application/json');
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $body = json_decode(file_get_contents('php://input'), true);
    $valid = ($_SERVER['HTTP_X_API_KEY'] ?? '') === 'test-tonpays-key';
    if ($path === '/api/v1/invoices/create') {
        $valid = $valid && $_SERVER['REQUEST_METHOD'] === 'POST'
            && ($body['order_id'] ?? '') === '0123456789abcdef0123' && ($body['amount'] ?? 0) === 50000
            && (string) ($body['buyer_chat_id'] ?? '') === '123456789'
            && ($body['callback_url'] ?? '') === 'https://bot.example.com/payment/tonpays.php';
    } else {
        $valid = $valid && $path === '/api/v1/invoices/check/TP-ABC123XYZ0' && $_SERVER['REQUEST_METHOD'] === 'GET';
    }
    if (!$valid) { http_response_code(400); echo '{}'; return; }
    $fixture = json_decode(file_get_contents(getenv('TONPAYS_TEST_FIXTURE')), true);
    http_response_code($fixture['status']);
    echo $fixture['body'];
    return;
}
set_error_handler(static function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function getPaySettingValue($key, $default = '') { return ['tonpays_api_key' => 'test-tonpays-key'][$key] ?? $default; }
function expectTonpays(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
require_once dirname(__DIR__) . '/payment/tonpays_lib.php';
$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$fixturePath = tempnam(sys_get_temp_dir(), 'mirza-tonpays-http-');
$oldFixture = getenv('TONPAYS_TEST_FIXTURE');
putenv('TONPAYS_TEST_FIXTURE=' . $fixturePath);
$server = proc_open([PHP_BINARY, '-S', $address, __FILE__], [0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
try {
    expectTonpays(is_resource($server), 'Test HTTP server did not start');
    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection !== false) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    expectTonpays($ready, 'Test HTTP server is not ready');
    $fixture = static function ($body, int $status = 201) use ($fixturePath): void {
        file_put_contents($fixturePath, json_encode(['status' => $status, 'body' => is_array($body) ? json_encode($body) : $body]));
    };
    $create = static fn() => tonpaysCreateOrder('0123456789abcdef0123', 50000, '123456789', 'bot.example.com', 'http://' . $address);
    $success = ['invoice_id' => 'TP-ABC123XYZ0', 'order_id' => '0123456789abcdef0123',
        'request_amount' => 50000, 'final_amount' => 50037, 'status' => 'pending',
        'payment_url' => 'https://tonpays.online/pay/example', 'invoice_url' => 'https://t.me/tonpays_bot?start=example'];
    $fixture($success);
    $created = $create();
    expectTonpays($created['payment_url'] === $success['payment_url'] && $created['metadata']['request_amount'] === 50000
        && $created['metadata']['final_amount'] === 50037, 'Creation contract/amount changed');
    $fixture(array_replace($success, ['payment_url' => null]));
    expectTonpays($create()['payment_url'] === $success['invoice_url'], 'Missing web checkout must use bot URL');
    foreach ([['order_id' => 'other'], ['request_amount' => 50001], ['request_amount' => '50000.5'],
        ['invoice_id' => '../bad'], ['invoice_id' => []], ['final_amount' => 0],
        ['payment_url' => 'http://unsafe.example'], ['payment_url' => 'https://user:pass@example.com/'],
        ['payment_url' => ['bad']]] as $bad) {
        $fixture(array_replace($success, $bad));
        try { $create(); throw new LogicException('Malformed invoice accepted'); } catch (RuntimeException $expected) {}
    }
    foreach ([[401, '{"detail":"test-tonpays-key"}'], [503, '<html>down</html>'], [200, 'not json']] as [$status, $body]) {
        $fixture($body, $status);
        try { $create(); throw new LogicException('Provider failure accepted'); } catch (RuntimeException $e) {
            expectTonpays(!str_contains($e->getMessage(), 'test-tonpays-key'), 'Credential leaked');
        }
    }
    $paid = array_intersect_key($success, array_flip(['invoice_id', 'order_id', 'request_amount', 'final_amount', 'status'])) + ['paid' => true];
    $paid['status'] = 'completed';
    $fixture($paid, 200);
    $verified = tonpaysCheckInvoice('TP-ABC123XYZ0', 'http://' . $address);
    expectTonpays($verified === $paid, 'Inquiry endpoint or response changed');
    $order = ['id_order' => $paid['order_id'], 'price' => '50000'];
    expectTonpays(tonpaysInvoiceMatchesOrder($verified, $order, $created['metadata']), 'Valid invoice rejected');
    foreach ([['order_id' => null], ['invoice_id' => 'TP-other'], ['request_amount' => 50037], ['final_amount' => 50000], ['paid' => 'true'], ['paid' => 1]] as $bad) {
        expectTonpays(!tonpaysInvoiceMatchesOrder(array_replace($verified, $bad), $order, $created['metadata']), 'Mismatched verification accepted');
    }
    echo "TonPays HTTP contract and validation tests passed\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    unlink($fixturePath);
    putenv($oldFixture === false ? 'TONPAYS_TEST_FIXTURE' : 'TONPAYS_TEST_FIXTURE=' . $oldFixture);
    restore_error_handler();
}
