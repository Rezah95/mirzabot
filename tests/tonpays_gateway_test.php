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
define('TONPAYS_TEST_LOG_FILE', tempnam(sys_get_temp_dir(), 'mirza-tonpays-log-'));
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
    $fixture($success + ['web_invoice_url' => 'https://tonpays.online/web/current']);
    expectTonpays($create()['payment_url'] === 'https://tonpays.online/web/current', 'Current web_invoice_url field ignored');
    $currentSchema = $success;
    unset($currentSchema['payment_url']);
    $fixture($currentSchema + ['web_invoice_url' => 'https://tonpays.online/web/current']);
    expectTonpays($create()['payment_url'] === 'https://tonpays.online/web/current', 'Current schema without payment_url rejected');
    $fixture(array_replace($success, ['payment_url' => '', 'web_invoice_url' => null]));
    expectTonpays($create()['payment_url'] === $success['invoice_url'], 'Empty optional checkout must use bot URL');
    foreach ([['order_id' => 'other'], ['request_amount' => 50001], ['request_amount' => '50000.5'],
        ['invoice_id' => '../bad'], ['invoice_id' => []], ['final_amount' => 0],
        ['payment_url' => 'http://unsafe.example'], ['payment_url' => 'https://user:pass@example.com/'],
        ['payment_url' => ['bad']], ['web_invoice_url' => 'javascript:bad'] ] as $bad) {
        $fixture(array_replace($success, $bad));
        try { $create(); throw new LogicException('Malformed invoice accepted'); } catch (RuntimeException $expected) {}
    }
    foreach ([[401, '{"detail":"test-tonpays-key"}'], [503, '<html>down</html>'], [200, 'not json']] as [$status, $body]) {
        $fixture($body, $status);
        try { $create(); throw new LogicException('Provider failure accepted'); } catch (RuntimeException $e) {
            expectTonpays(!str_contains($e->getMessage(), 'test-tonpays-key'), 'Credential leaked');
        }
    }
    $failure = static function () use ($create): TonpaysFailure {
        try { $create(); } catch (TonpaysFailure $error) { return $error; }
        throw new LogicException('Expected a diagnostic failure');
    };
    $fixture(['success' => false, 'error' => ['code' => 'BUYER_IS_MERCHANT',
        'message' => 'buyer_chat_id cannot be the merchant Telegram id']], 400);
    $merchantError = $failure();
    expectTonpays($merchantError->diagnostics['provider_code'] === 'BUYER_IS_MERCHANT', 'Structured merchant restriction lost');
    expectTonpays(str_contains(tonpaysCustomerErrorMessage($merchantError, 'generic'), 'حساب تلگرام دیگر'), 'Merchant sees no actionable message');
    expectTonpays(tonpaysCustomerErrorMessage(new RuntimeException('BUYER_IS_MERCHANT'), 'generic') === 'generic', 'Arbitrary exception exposed to customer');
    expectTonpays(tonpaysResponseErrorCode(['error' => ['code' => []]]) === '', 'Malformed provider code accepted');
    $fixture(['detail' => 'INVALID_API_KEY: test-tonpays-key'], 401);
    $error = $failure();
    expectTonpays(str_contains($error->getMessage(), 'INVALID_API_KEY') && $error->diagnostics['http_status'] === 401, 'Provider error detail lost');
    expectTonpays(str_contains(tonpaysResponseError(['detail' => 'Minimum amount is 500000 Toman']), '500000'), 'Useful amount limit was redacted');
    $reference = tonpaysLog('order_creation_failed', ['stage' => 'create_invoice', 'order_id' => '0123456789abcdef0123',
        'api_key' => 'must-not-be-logged', 'payment_url' => 'must-not-be-logged'], $error);
    $entry = json_decode(trim(file_get_contents(TONPAYS_TEST_LOG_FILE)), true);
    expectTonpays($entry['reference'] === $reference && $entry['http_status'] === 401 && $entry['stage'] === 'create_invoice'
        && str_contains($entry['reason'], 'INVALID_API_KEY'), 'Diagnostic file lacks actionable context');
    $fixture(['detail' => [['loc' => ['body', 'buyer_chat_id'], 'msg' => 'Buyer 123456789 is invalid', 'type' => 'value_error',
        'input' => 'must-not-be-logged', 'ctx' => ['token' => 'must-not-be-logged']]]], 422);
    $error = $failure();
    tonpaysLog('order_creation_failed', [], $error);
    expectTonpays(str_contains($error->getMessage(), 'body.buyer_chat_id') && str_contains($error->getMessage(), 'invalid'), 'Validation field or message lost');
    $fixture('<html>must-not-be-logged</html>', 502);
    tonpaysLog('order_creation_failed', [], $failure());
    $fixture('invalid json', 200);
    $error = $failure();
    expectTonpays(isset($error->diagnostics['json_error']), 'JSON decoding error absent');
    tonpaysLog('invalid_json', [], $error);
    $unavailable = stream_socket_server('tcp://127.0.0.1:0');
    $unavailableAddress = stream_socket_get_name($unavailable, false);
    fclose($unavailable);
    try {
        tonpaysCreateOrder('0123456789abcdef0123', 50000, '123456789', 'bot.example.com', 'http://' . $unavailableAddress);
        throw new LogicException('Unavailable server accepted');
    } catch (TonpaysFailure $error) {
        expectTonpays($error->diagnostics['http_status'] === 0 && $error->diagnostics['curl_errno'] > 0
            && $error->diagnostics['curl_error'] !== '', 'Network error discarded before curl_close');
        tonpaysLog('network_error', [], $error);
    }
    tonpaysLog('redaction_test', ['reason' => "test-tonpays-key\nBearer abc-secret https://example.test/pay?token=secret <b>error</b>",
        'response_fields' => str_repeat('field,', 1000)]);
    $log = file_get_contents(TONPAYS_TEST_LOG_FILE);
    foreach (['test-tonpays-key', 'abc-secret', 'token=secret', 'Buyer 123456789', '<b>', 'must-not-be-logged'] as $sensitive) {
        expectTonpays(!str_contains($log, $sensitive), 'Diagnostic log leaked input, credentials or a payment link');
    }
    foreach (array_filter(explode("\n", $log)) as $line) { expectTonpays(is_array(json_decode($line, true)), 'Log contains a forged or malformed line'); }
    $preview = tonpaysRecentErrorsText();
    expectTonpays(mb_strlen($preview) < 3500 && str_contains($preview, 'network_error') && !str_contains($preview, 'test-tonpays-key'), 'Admin log preview is unsafe or too large');
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
    echo "TonPays HTTP contract, current checkout URL, diagnostics and redaction tests passed\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    unlink($fixturePath);
    unlink(TONPAYS_TEST_LOG_FILE);
    putenv($oldFixture === false ? 'TONPAYS_TEST_FIXTURE' : 'TONPAYS_TEST_FIXTURE=' . $oldFixture);
    restore_error_handler();
}
