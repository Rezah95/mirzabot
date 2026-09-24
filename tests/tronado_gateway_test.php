<?php

// Run with: php tests/tronado_gateway_test.php. All HTTP requests stay on localhost.
if (PHP_SAPI === 'cli-server') {
    header('Content-Type: application/json');
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $body = json_decode(file_get_contents('php://input'), true);
    if ($path === '/Tron/GetPriceToToman' && !isset($_SERVER['HTTP_X_API_KEY'])) {
        echo json_encode(['TronPriceToman' => 70000]);
        return;
    }
    if ($path !== '/api/v5/GetOrderToken'
        || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || ($_SERVER['HTTP_X_API_KEY'] ?? '') !== 'test-api-key'
        || ($_GET['wageFromBusinessPercentage'] ?? '') !== '100'
        || ($body['PaymentID'] ?? '') !== '0123456789'
        || ($body['WalletAddress'] ?? '') !== 'T' . str_repeat('1', 33)
        || ($body['CallbackUrl'] ?? '') !== 'https://bot.example.com/payment/tronado.php'
        || abs(($body['TronAmount'] ?? 0) - 7.142858) > 0.0000001) {
        http_response_code(400);
        echo json_encode(['ErrorMessage' => 'Unexpected request contract']);
        return;
    }
    $fixture = json_decode(file_get_contents(getenv('TRONADO_TEST_FIXTURE')), true);
    http_response_code($fixture['status']);
    echo $fixture['body'];
    return;
}

set_error_handler(static function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function getPaySettingValue($name, $default = '')
{
    return [
        'tronado_status' => 'ontronado',
        'tronado_api_key' => 'test-api-key',
        'tronado_ipn_signing_key' => 'test-ipn-key',
        'tronado_wallet_address' => 'T' . str_repeat('1', 33),
    ][$name] ?? $default;
}

function expectTronado(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require_once dirname(__DIR__) . '/payment/tronado_lib.php';

$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$fixturePath = tempnam(sys_get_temp_dir(), 'mirza-tronado-test-');
$oldFixture = getenv('TRONADO_TEST_FIXTURE');
putenv('TRONADO_TEST_FIXTURE=' . $fixturePath);
$server = proc_open([PHP_BINARY, '-S', $address, __FILE__], [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/dev/null', 'w'],
    2 => ['file', '/dev/null', 'w'],
], $pipes);

try {
    expectTronado(is_resource($server), 'Could not start local test server');
    $port = (int) substr(strrchr($address, ':'), 1);
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($connection !== false) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    expectTronado($ready, 'Local test server did not start');
    $request = static function ($body, int $status = 200) use ($fixturePath, $address): array {
        file_put_contents($fixturePath, json_encode([
            'status' => $status,
            'body' => is_array($body) ? json_encode($body) : $body,
        ]));
        return tronadoCreateOrder('0123456789', 500000, 'bot.example.com', 'http://' . $address);
    };
    $reject = static function ($body, string $expected, int $status = 200) use ($request): string {
        try {
            $request($body, $status);
        } catch (RuntimeException $error) {
            expectTronado(str_contains($error->getMessage(), $expected), 'Unexpected error: ' . $error->getMessage());
            return $error->getMessage();
        }
        throw new RuntimeException('Provider failure was accepted');
    };

    $token = '12345678-1234-1234-1234-123456789abc';
    $success = ['Token' => $token, 'ErrorMessage' => null, 'EstimatedTomanAmount' => '500000'];
    // Actual v5 response confirmed by Tronado support, including a nonempty success Message.
    $envelope = static fn(array $data): array => [
        'IsSuccessful' => true, 'Code' => 200, 'Message' => 'Order created', 'Data' => $data,
    ];
    $order = $request($envelope($success));
    expectTronado($order['token'] === $token && $order['estimated_toman_amount'] === '500000', 'Valid order changed');
    expectTronado($order['payment_url'] === 'https://t.me/tronado_robot/customerpayment?startapp=' . $token, 'Fallback payment URL');
    $url = 'https://t.me/tronado_robot/customerpayment?startapp=official_token';
    expectTronado($request($envelope($success + ['FullPaymentUrl' => $url]))['payment_url'] === $url, 'Nested official payment URL');
    expectTronado($request($envelope($success + ['FullPaymentUrl' => 'https://example.com/pay']))['payment_url'] === $order['payment_url'], 'Untrusted URL accepted');
    expectTronado($request($envelope($success) + ['Token' => 'wrong_root_token', 'FullPaymentUrl' => $url])['token'] === $token, 'Root token took precedence over Data.Token');
    expectTronado($request($envelope($success + ['FullPaymentUrl' => ['unexpected']]))['payment_url'] === $order['payment_url'], 'Malformed URL caused a warning');

    $reject(['Token' => $token, 'ErrorMessage' => 'Business is inactive'], 'Business is inactive');
    $reject(['ErrorMessage' => 'Invalid API key'], 'HTTP 401): Invalid API key', 401);
    $reject(['ErrorMessage' => '', 'Error' => 'Callback domain is not registered'], 'Callback domain is not registered');
    $reject($envelope(['Token' => null]), 'missing Token (fields: Token)');
    $reject($envelope(['Token' => ['unexpected']]), 'missing Token (fields: Token)');
    $reject(['success' => false, 'message' => 'Daily limit exceeded'], 'order rejected: Daily limit exceeded');
    $reject(['error' => ['message' => 'Business disabled']], 'order rejected: Business disabled');
    $reject(['data' => ['token' => $token], 'status' => false], 'IsSuccessful must be true');
    $reject($envelope(['Token' => '<invalid>']), 'invalid Token format');
    $reject(['IsSuccessful' => false, 'Message' => 'Business disabled', 'Data' => $success], 'order rejected: Business disabled');
    foreach ([false, null, 'false', 'true', 1, []] as $invalidSuccess) {
        $reject(['IsSuccessful' => $invalidSuccess, 'Data' => $success], 'IsSuccessful must be true');
    }
    $reject(['Data' => $success], 'IsSuccessful must be true');
    $reject($success, 'IsSuccessful must be true');
    foreach ([null, 'unexpected', false] as $invalidData) {
        $reject(['IsSuccessful' => true, 'Data' => $invalidData], 'invalid Data');
    }
    $reject(['IsSuccessful' => true], 'invalid Data');
    $reject($envelope(['Token' => $token, 'ErrorMessage' => 'Inner order error']), 'Inner order error');
    $reject('<html>Unavailable</html>', 'HTTP 503', 503);
    $reject('invalid JSON', 'Invalid Tronado JSON response');
    $reject(['ErrorMessage' => ['unexpected']], 'Unspecified provider error');

    $message = $reject([
        'Token' => $token,
        'ErrorMessage' => "Rejected test-api-key test-ipn-key $token\nhttps://example.com/pay?token=secret <b>details</b>",
    ], 'Rejected');
    foreach (['test-api-key', 'test-ipn-key', $token, 'token=secret', "\n", '<b>'] as $sensitive) {
        expectTronado(!str_contains($message, $sensitive), 'Unsafe error detail');
    }
    $message = $reject([
        'IsSuccessful' => false, 'Data' => $success,
        'Message' => "Rejected test-api-key test-ipn-key $token https://example.com/pay?token=secret",
    ], 'Rejected');
    foreach (['test-api-key', 'test-ipn-key', $token, 'token=secret'] as $sensitive) {
        expectTronado(!str_contains($message, $sensitive), 'Envelope error leaked credentials or token');
    }
    expectTronado(mb_strlen(tronadoResponseError(['ErrorMessage' => str_repeat('خطا ', 200)])) <= 400, 'Unbounded error detail');
    expectTronado(tronadoResponseFields(['api-key' => 'secret', 'Nested' => ['token' => $token], 'Status' => false]) === 'Nested(token),Status', 'Response shape leaked values');
    echo "Tronado gateway tests passed\n";
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    unlink($fixturePath);
    putenv($oldFixture === false ? 'TRONADO_TEST_FIXTURE' : 'TRONADO_TEST_FIXTURE=' . $oldFixture);
    restore_error_handler();
}
