<?php

// Uses only a dedicated temporary MySQL instance; never loads the bot's config.php.
set_error_handler(static function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function expectCallback(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function clearSelectCache($table): void {}
function languagechange() { return $GLOBALS['textbotlang']; }
function getPaySettingValue($key, $default = '') { return $default; }
function sendmessage(...$args): void { $GLOBALS['sentMessages'][] = $args[1]; }
function select($table, $fields = '*', $field = null, $value = null, $mode = 'select')
{
    global $pdo;
    if ($table === 'topicid') { return ['idreport' => 1]; }
    if ($table === 'admin') { return []; }
    if ($table === 'setting') { return ['Channel_Report' => '']; }
    expectCallback(in_array($table, ['user', 'Payment_report'], true), 'Unexpected table');
    expectCallback(in_array($field, ['id', 'id_order'], true), 'Unexpected lookup');
    $q = $pdo->prepare("SELECT * FROM `$table` WHERE `$field` = ?");
    $q->execute([$value]);
    return $q->fetch(PDO::FETCH_ASSOC);
}
function update($table, $field, $value, $whereField, $whereValue): void
{
    global $pdo;
    expectCallback($table === 'Payment_report' && $field === 'payment_Status' && $whereField === 'id_order', 'Unexpected write');
    $pdo->prepare('UPDATE Payment_report SET payment_Status = ? WHERE id_order = ?')->execute([$value, $whereValue]);
}
require_once dirname(__DIR__) . '/payment/tronado_lib.php';
$textbotlang = require dirname(__DIR__) . '/lang/fa.php';
$source = file_get_contents(dirname(__DIR__) . '/function.php');
$start = strpos($source, 'function addBalance(');
$end = strpos($source, 'function plisio(', $start);
expectCallback($start !== false && $end !== false, 'Fulfillment code not found');
eval(substr($source, $start, $end - $start));

$socket = getenv('TRONADO_TEST_SOCKET');
expectCallback(is_string($socket) && str_starts_with($socket, '/tmp/mirza-tronado-test.')
    && is_file(dirname($socket) . '/mysql.pid'), 'Set TRONADO_TEST_SOCKET to an isolated test MySQL socket');
$pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$db = 'tronado_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$oldLog = ini_get('error_log');
$log = tempnam(sys_get_temp_dir(), 'mirza-tronado-log-');
ini_set('error_log', $log);
$server = null;
$webRoot = sys_get_temp_dir() . '/mirza-tronado-http-' . bin2hex(random_bytes(6));
try {
    $pdo->exec("USE $db");
    foreach (['Payment_report', 'Tronado_callback'] as $table) {
        $definition = require dirname(__DIR__) . '/db/tables/' . $table . '.php';
        $pdo->exec("CREATE TABLE `$table` (" . $definition['create'] . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    $pdo->exec("CREATE TABLE user (id INT PRIMARY KEY, Balance BIGINT NOT NULL, username VARCHAR(100),
        Processing_value TEXT, Processing_value_one TEXT, Processing_value_tow TEXT, Processing_value_four TEXT)");
    $pdo->exec("INSERT INTO user VALUES (123, 1000, 'tester', '0', '0', '0', '0')");
    $callback = ['PaymentId' => '0123456789', 'OrderStatusID' => 30, 'IsPaid' => true,
        'Wallet' => 'T' . str_repeat('1', 33), 'TronAmount' => 7.1,
        'UserPaidTomanAmount' => 500000, 'TomanAmountWithoutWage' => 450000];
    $metadata = ['automatic_delivery' => 2, 'wage_from_business_percentage' => 100, 'tron_amount' => 7.142858, 'wallet' => $callback['Wallet']];
    $reset = static function () use ($pdo, $callback, $metadata): void {
        $pdo->exec('DELETE FROM Tronado_callback');
        $pdo->exec('DELETE FROM Payment_report');
        $pdo->exec('UPDATE user SET Balance = 1000');
        $pdo->prepare("INSERT INTO Payment_report (id_user, id_order, price, Payment_Method, payment_Status, dec_not_confirmed, id_invoice)
            VALUES (123, ?, 500000, 'Tronado', 'Unpaid', ?, 'none')")
            ->execute([$callback['PaymentId'], json_encode($metadata)]);
        $GLOBALS['sentMessages'] = [];
    };
    $receive = static function (?array $payload = null, ?string $signature = null, string $key = 'test-ipn-key') use ($pdo, $callback): array {
        $raw = json_encode($payload ?? $callback);
        return tronadoReceiveCallback($pdo, $raw, $signature ?? hash_hmac('sha512', $raw, 'test-ipn-key'), $key);
    };
    $state = static fn() => $pdo->query('SELECT fulfillment_status FROM Payment_report')->fetchColumn();
    $balance = static fn() => (int) $pdo->query('SELECT Balance FROM user WHERE id = 123')->fetchColumn();
    $deliveries = $notifications = 0;
    $deliver = static function (array $order) use (&$deliveries): bool {
        $deliveries++;
        return DirectPayment($order['id_order']);
    };
    $after = static function ($order) use (&$notifications): void { $notifications++; };
    $work = static fn() => tronadoFulfillQueued($pdo, $callback['PaymentId'], $deliver, $after);

    $reset();
    expectCallback($receive(null, 'invalid')[0] === 401 && $receive(null, null, '')[0] === 503, 'Bad signature/key accepted');
    expectCallback(tronadoReceiveCallback($pdo, '{', hash_hmac('sha512', '{', 'test-ipn-key'), 'test-ipn-key')[0] === 400, 'Malformed JSON accepted');
    foreach ([['PaymentId' => []], ['OrderStatusID' => []], ['PaymentId' => '']] as $bad) {
        expectCallback($receive(array_replace($callback, $bad))[0] === 400, 'Malformed event accepted');
    }
    foreach ([['Wallet' => 'wrong'], ['Wallet' => []], ['UserPaidTomanAmount' => 490000], ['TronAmount' => 0]] as $bad) {
        expectCallback($receive(array_replace($callback, $bad))[0] === 409, 'Payment mismatch accepted');
    }
    expectCallback((int) $pdo->query('SELECT COUNT(*) FROM Tronado_callback')->fetchColumn() === 0, 'Rejected event persisted');
    $pdo->exec("UPDATE Payment_report SET Payment_Method = 'Currency Rial 2'");
    expectCallback($receive()[0] === 404, 'CubePay order accepted by Tronado');
    $reset();
    $pdo->exec('RENAME TABLE Tronado_callback TO callback_unavailable');
    expectCallback($receive()[0] === 503 && $state() === null, 'Storage outage acknowledged as success');
    $pdo->exec('RENAME TABLE callback_unavailable TO Tronado_callback');

    $pdo->exec("CREATE TRIGGER reject_queue BEFORE UPDATE ON Payment_report FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test queue storage failure'");
    expectCallback($receive()[0] === 503
        && (int) $pdo->query('SELECT COUNT(*) FROM Tronado_callback')->fetchColumn() === 0 && $state() === null,
        'Event was committed without its recoverable queue entry');
    $pdo->exec('DROP TRIGGER reject_queue');

    expectCallback($receive()[0] === 200 && $state() === 'queued' && $balance() === 1000,
        'Acceptance must persist a queue before any wallet/Telegram work');
    expectCallback($receive()[0] === 200 && $work() && !$work(), 'Duplicate delivery was not claimed once');
    expectCallback($deliveries === 1 && $notifications === 1 && $balance() === 501000 && $state() === 'fulfilled',
        'Incorrect delivery, notification, or wallet balance');
    expectCallback(count($sentMessages) === 1, 'User payment confirmation missing or duplicated');
    expectCallback($receive()[0] === 200 && !$work() && $balance() === 501000, 'Redelivery credited twice');
    expectCallback($receive(array_replace($callback, ['OrderStatusID' => 27]))[0] === 200 && !$work(), 'Different paid event credited twice');

    $reset();
    expectCallback($receive(array_replace($callback, ['OrderStatusID' => 20, 'IsPaid' => false]))[0] === 200
        && $state() === null && !$work(), 'Unpaid transition granted credit');
    // Legacy crash after inserting an event but before claiming its invoice must be recoverable.
    tronadoRegisterCallback($callback['PaymentId'], 30, json_encode($callback), $pdo);
    expectCallback($receive()[0] === 200 && $state() === 'queued' && $work(), 'Old unclaimed duplicate was discarded');

    $reset();
    $receive();
    $duringDelivery = static function (array $order) use ($receive, $work, $deliver): bool {
        expectCallback($receive()[0] === 200 && !$work(), 'Concurrent worker processed a reserved order');
        return $deliver($order);
    };
    expectCallback(tronadoFulfillQueued($pdo, $callback['PaymentId'], $duringDelivery) && $balance() === 501000,
        'Concurrent callback duplicated credit');

    $reset();
    $receive();
    $failed = static function (array $order) use ($pdo): void {
        $pdo->exec('UPDATE user SET Balance = Balance + 500000');
        throw new RuntimeException('Simulated failure after side effect');
    };
    expectCallback(!tronadoFulfillQueued($pdo, $callback['PaymentId'], $failed) && $state() === 'failed', 'Partial failure was not recorded');
    expectCallback($receive()[0] === 409 && !$work() && $balance() === 501000, 'Ambiguous failure retried credit');
    $reset();
    $pdo->exec("UPDATE Payment_report SET payment_Status = 'paid'");
    expectCallback($receive()[0] === 409 && !$work(), 'Legacy paid order without fulfillment proof credited again');

    $reset();
    $receive();
    expectCallback(tronadoFulfillQueued($pdo, $callback['PaymentId'], static fn($order) => false)
        && $state() === 'refunded' && $receive()[0] === 200 && !$work(), 'Refunded delivery repeated');
    $reset();
    $receive();
    expectCallback(tronadoFulfillQueued($pdo, $callback['PaymentId'], $deliver,
        static function ($order): void { throw new RuntimeException('Test notification failure'); })
        && $state() === 'fulfilled' && $receive()[0] === 200 && !$work(), 'Notification failure invalidated payment');
    $reset();
    $receive();
    $cancel = array_replace($callback, ['OrderStatusID' => 200, 'IsPaid' => false]);
    expectCallback($receive($cancel)[1]['cancelled'] === true && !$work() && $balance() === 1000,
        'Cancelled queued payment was delivered');
    expectCallback($receive($cancel)[1]['cancelled'] === false, 'Cancellation reported twice');

    $reset();
    $receive($cancel);
    expectCallback($receive()[0] === 409 && !$work() && $balance() === 1000,
        'Delayed paid event delivered an already cancelled order');

    // Historical invoices may have been manually credited: never replay them after upgrade.
    foreach (['Unpaid', 'paid'] as $previousStatus) {
        $reset();
        $oldMetadata = $metadata;
        unset($oldMetadata['automatic_delivery']);
        $pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = ?, payment_Status = ?, fulfillment_status = ?")
            ->execute([json_encode($oldMetadata), $previousStatus, $previousStatus === 'paid' ? 'queued' : null]);
        $pdo->exec('UPDATE user SET Balance = 501000'); // The administrator already compensated this order.
        expectCallback($receive()[1]['fulfillment'] === 'review' && !$work() && $balance() === 501000,
            'A historical payment was credited a second time');
        expectCallback($receive()[0] === 200 && $state() === 'review', 'Historical duplicate not acknowledged');
    }
    $reset();
    $pdo->prepare("UPDATE Payment_report SET dec_not_confirmed = ?, payment_Status = 'paid', fulfillment_status = 'queued'")
        ->execute([json_encode($oldMetadata)]);
    expectCallback(!$work() && $state() === 'review' && $balance() === 1000, 'Old cron queue was replayed');
    $diagnostics = tronadoDiagnostics($pdo);
    expectCallback(str_contains($diagnostics, $callback['PaymentId']) && str_contains($diagnostics, 'review')
        && !str_contains($diagnostics, $callback['Wallet']), 'Diagnostics omitted state or leaked payload');

    // Run the actual HTTP endpoint on non-FPM hosting; only bootstrap dependencies are fixtures.
    $reset();
    mkdir($webRoot . '/payment', 0700, true);
    copy(dirname(__DIR__) . '/payment/tronado.php', $webRoot . '/payment/tronado.php');
    copy(dirname(__DIR__) . '/payment/tronado_lib.php', $webRoot . '/payment/tronado_lib.php');
    file_put_contents($webRoot . '/config.php', '<?php $pdo = new PDO(' . var_export('mysql:unix_socket=' . $socket . ';dbname=' . $db, true)
        . ', "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);');
    copy(dirname(__DIR__) . '/payment/tronado_delivery.php', $webRoot . '/payment/tronado_delivery.php');
    file_put_contents($webRoot . '/panels.php', '<?php class ManagePanel {}');
    file_put_contents($webRoot . '/jdf.php', '<?php');
    file_put_contents($webRoot . '/keyboard.php', '<?php $keyboard = null;');
    file_put_contents($webRoot . '/botapi.php', '<?php');
    $bootstrap = <<<'PHP'
<?php
function clearSelectCache($table) {}
function getPaySettingValue($key, $default = '') { return $key === 'tronado_ipn_signing_key' ? 'test-ipn-key' : $default; }
function languagechange() { return require LANGUAGE_FILE; }
function select($table, $fields = '*', $field = null, $value = null, $mode = 'select') {
    global $pdo;
    if ($table === 'topicid') { return ['idreport' => 1]; }
    if ($table === 'admin') { return []; }
    if ($table === 'setting') { return ['Channel_Report' => 'test-channel']; }
    $q = $pdo->prepare("SELECT * FROM `$table` WHERE `$field` = ?");
    $q->execute([$value]);
    return $q->fetch(PDO::FETCH_ASSOC);
}
function update($table, $field, $value, $whereField, $whereValue) {
    global $pdo;
    $pdo->prepare("UPDATE `$table` SET `$field` = ? WHERE `$whereField` = ?")->execute([$value, $whereValue]);
}
function sendmessage(...$args) { usleep(2500000); }
function telegram($method, $args) {
    file_put_contents(__DIR__ . '/reports.jsonl', json_encode($args) . "\n", FILE_APPEND);
    return ['ok' => true];
}
PHP;
    $bootstrap = str_replace('LANGUAGE_FILE', var_export(dirname(__DIR__) . '/lang/fa.php', true), $bootstrap);
    file_put_contents($webRoot . '/function.php', $bootstrap . "\n" . substr($source, $start, $end - $start));
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', $webRoot], [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'a'],
    ], $pipes);
    expectCallback(is_resource($server), 'Could not start test HTTP server');
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection) { fclose($connection); break; }
        usleep(100000);
    }
    $http = static function (string $method, string $signature) use ($address, $callback): array {
        $curl = curl_init('http://' . $address . '/payment/tronado.php');
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Tronado-Sig: ' . $signature],
            CURLOPT_POSTFIELDS => json_encode($callback)]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $time = curl_getinfo($curl, CURLINFO_TOTAL_TIME);
        curl_close($curl);
        return [$status, json_decode($body, true), $time];
    };
    expectCallback($http('GET', '')[0] === 405 && $http('POST', 'invalid')[0] === 401, 'HTTP method/authentication checks failed');
    $result = $http('POST', hash_hmac('sha512', json_encode($callback), 'test-ipn-key'));
    expectCallback($result[0] === 200 && $result[1]['fulfillment'] === 'queued' && $result[2] < 2, 'HTTP acceptance waited for fulfillment');
    // The HTTP client has its 200 response while the worker is still sending a slow notification.
    for ($attempt = 0; $attempt < 60 && !is_file($webRoot . '/reports.jsonl'); $attempt++) { usleep(100000); }
    expectCallback($balance() === 501000 && $state() === 'fulfilled' && is_file($webRoot . '/reports.jsonl'),
        'Non-FPM callback failed to deliver and report without cron');
    $reports = file($webRoot . '/reports.jsonl');
    expectCallback(count($reports) === 1 && str_contains($reports[0], 'Tronado'), 'Tronado report missing');
    expectCallback($http('POST', hash_hmac('sha512', json_encode($callback), 'test-ipn-key'))[0] === 200
        && $balance() === 501000 && count(file($webRoot . '/reports.jsonl')) === 1, 'Duplicate HTTP callback replayed payment/report');
    $httpLog = file_get_contents($webRoot . '/payment/error_log');
    expectCallback(str_contains($httpLog, 'Tronado callback received') && str_contains($httpLog, 'Tronado callback result')
        && str_contains($httpLog, 'Tronado delivery result'), 'Callback or delivery diagnostics missing');
    // Actual recovery job must bootstrap in global scope and restore dispatcher process state.
    $reset();
    $receive();
    unlink($webRoot . '/reports.jsonl');
    mkdir($webRoot . '/cronbot');
    copy(dirname(__DIR__) . '/cronbot/tronado.php', $webRoot . '/cronbot/tronado.php');
    $cronCode = 'chdir("/tmp"); $beforeLog = ini_get("error_log"); require '
        . var_export($webRoot . '/cronbot/tronado.php', true)
        . '; if (getcwd() !== "/tmp" || ini_get("error_log") !== $beforeLog) { exit(1); }';
    $cron = proc_open([PHP_BINARY, '-r', $cronCode], [0 => ['file', '/dev/null', 'r'],
        1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
    expectCallback(is_resource($cron) && proc_close($cron) === 0 && $balance() === 501000
        && $state() === 'fulfilled' && is_file($webRoot . '/reports.jsonl'), 'Cron recovery/context failed');

    // Exercise the real delivery adapter with bootstrap fixtures, including its PDO scope.
    copy(dirname(__DIR__) . '/payment/tronado_delivery.php', $webRoot . '/payment/tronado_delivery.php');
    unlink($webRoot . '/payment/tronado_lib.php');
    symlink(dirname(__DIR__) . '/payment/tronado_lib.php', $webRoot . '/payment/tronado_lib.php');
    file_put_contents($webRoot . '/panels.php', '<?php class ManagePanel {}');
    file_put_contents($webRoot . '/jdf.php', '<?php');
    file_put_contents($webRoot . '/keyboard.php', '<?php expectCallback($pdo instanceof PDO && $from_id === "123", "Missing fulfillment context"); $keyboard = null;');
    require $webRoot . '/payment/tronado_delivery.php';
    $reset();
    $receive();
    expectCallback(tronadoDeliverPayment($pdo, $callback['PaymentId']) && $balance() === 501000,
        'Delivery adapter could not initialize the real wallet flow');
    $reset();
    $receive();
    expectCallback(tronadoDeliverPayment($pdo, $callback['PaymentId']) && $balance() === 501000,
        'Second delivery failed while reusing bootstrap dependencies');
    foreach (['fa', 'en', 'ru', 'zh'] as $lang) {
        $texts = require dirname(__DIR__) . '/lang/' . $lang . '.php';
        expectCallback(str_contains($texts['paymentGateway']['reportTronado'], 'Tronado')
            && !str_contains($texts['paymentGateway']['reportTronado'], 'CubePay')
            && str_contains($texts['paymentGateway']['reportCubePay'], 'CubePay'), 'Gateway report labels mixed: ' . $lang);
    }
    echo "Tronado callback OK: signed HTTP, durable acceptance, real wallet fulfillment, retries, concurrency, failures, labels\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (is_dir($webRoot)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($webRoot);
    }
    $pdo->exec("DROP DATABASE $db");
    ini_set('error_log', $oldLog);
    unlink($log);
}
