<?php
// Isolated MySQL only. No config.php, provider calls or Telegram network requests.
set_error_handler(static function ($s, $m, $f, $l) { if (error_reporting() & $s) { throw new ErrorException($m, 0, $s, $f, $l); } return false; });
require_once dirname(__DIR__) . '/report_events.php';
require_once dirname(__DIR__) . '/payment/tronado_lib.php';
require_once dirname(__DIR__) . '/db/Schema.php';
function reportCheck($ok, $message): void { if (!$ok) { throw new RuntimeException($message); } }
function assertSqlIdentifier($name): void { reportCheck((bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name), 'Bad identifier'); }
function clearSelectCache($table): void {}
function getPaySettingValue($name, $default = '') { return $default; }
$channel = '-100123';
function select($table, $fields = '*', $field = null, $value = null, $mode = 'select') {
    global $pdo, $channel;
    if ($table === 'setting') { return ['Channel_Report' => $channel]; }
    reportCheck($table === 'Payment_report' && $field === 'id_order', 'Unexpected lookup');
    $q = $pdo->prepare('SELECT * FROM Payment_report WHERE id_order = ?'); $q->execute([$value]);
    return $q->fetch(PDO::FETCH_ASSOC);
}
$lang = require dirname(__DIR__) . '/lang/fa.php';
foreach (['en', 'ru', 'zh'] as $locale) {
    $l = require dirname(__DIR__) . '/lang/' . $locale . '.php';
    foreach (['events', 'fields', 'reasons'] as $part) { reportCheck(array_keys($l['reportEvents'][$part]) === array_keys($lang['reportEvents'][$part]), 'Locale keys differ'); }
    reportCheck(isset($l['panel']['bottextGroups']['reportEvents']), 'Web editor label missing');
}
$socket = getenv('REPORT_TEST_SOCKET');
reportCheck(is_string($socket) && str_starts_with($socket, '/tmp/mirza-report-test.') && is_file(dirname($socket) . '/mysql.pid'), 'Set REPORT_TEST_SOCKET to an isolated MySQL socket');
$pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db = 'report_test_' . bin2hex(random_bytes(5)); $pdo->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $pdo->exec("USE $db"); $schema = new Schema($pdo);
    foreach (['report_event_outbox', 'topicid', 'Payment_report'] as $table) { $schema->apply($table, require dirname(__DIR__) . '/db/tables/' . $table . '.php'); }
    $pdo->exec("UPDATE topicid SET idreport='9' WHERE report='errorreport'");
    $pdo->exec("UPDATE topicid SET idreport='12' WHERE report='paymentreport'");
    $pdo->exec('CREATE TABLE user (id INT PRIMARY KEY, Balance BIGINT NOT NULL)'); $pdo->exec('INSERT INTO user VALUES (101, 1000)');
    $pdo->exec("INSERT INTO Payment_report (id_user,id_order,price,Payment_Method,payment_Status) VALUES ('101','abc123',500000,'Tronado','paid')");
    $count = static fn() => (int) $pdo->query('SELECT COUNT(*) FROM report_event_outbox')->fetchColumn();
    $setting = ['Channel_Report' => $channel]; $now = time() + 2; $requests = [];
    $accept = static function ($args) use (&$requests) { $requests[] = $args; return ['ok' => true, 'result' => ['message_id' => 42]]; };
    reportCheck(reportEventQueue('payment_failed', ['gateway' => 'Tronado', 'order' => 'abc123', 'service' => '<bad&>', 'reason' => 'failed', 'api_key' => 'must-not-persist'], 'abc123'), 'Queue failed');
    reportEventQueue('payment_failed', ['order' => 'abc123'], 'abc123');
    reportCheck($count() === 1 && !str_contains($pdo->query('SELECT context FROM report_event_outbox')->fetchColumn(), 'must-not-persist'), 'Deduplication/redaction failed');
    $throttle = static fn() => ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 120]];
    reportCheck(reportEventsRun($pdo, $setting, $lang, $throttle, $now) === 0, 'Failed report marked successful');
    reportCheck(reportEventsRun($pdo, $setting, $lang, $accept, $now + 119) === 0 && !$requests, 'Retry delay ignored');
    reportCheck(reportEventsRun($pdo, $setting, $lang, $accept, $now + 120) === 1 && $requests[0]['message_thread_id'] === 9
        && str_contains($requests[0]['text'], '&lt;bad&amp;&gt;'), 'Retry, error topic or escaping failed');
    reportEventQueue('payment_failed', ['order' => 'abc123'], 'abc123');
    reportCheck(reportEventsRun($pdo, $setting, $lang, $accept, $now + 121) === 0, 'Delivered report repeated');
    reportEventQueue('payment_refunded', ['order' => 'abc123'], 'abc123', 'paymentreport');
    reportCheck(reportEventsRun($pdo, $setting, $lang, $accept, $now + 121) === 1 && end($requests)['message_thread_id'] === 12, 'Financial topic missing');
    $channel = ''; reportCheck(!reportEventQueue('payment_review', ['order' => 'disabled']) && $count() === 2, 'Disabled report channel ignored'); $channel = '-100123';
    reportEventQueue('payment_review', ['order' => 'closed-topic']); $calls = [];
    $fallback = static function ($args) use (&$calls) { $calls[] = $args; return count($calls) === 1 ? ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message thread not found'] : ['ok' => true]; };
    reportCheck(reportEventsRun($pdo, $setting, $lang, $fallback, $now + 122) === 1 && count($calls) === 2 && !isset($calls[1]['message_thread_id']), 'Deleted topic lost report');
    reportEventQueue('cron_failed', ['job' => 'sample', 'reason' => 'RuntimeException'], 'sample:hour');
    $lockName = 'report_events_' . sha1($db); $second = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $db, 'root', '');
    $second->query("SELECT GET_LOCK('$lockName',0)");
    reportCheck(reportEventsRun($pdo, $setting, $lang, $accept, $now + 123) === 0, 'Concurrent workers bypassed lock');
    $second->query("SELECT RELEASE_LOCK('$lockName')");
    reportCheck(reportEventsRun($pdo, $setting, $lang, static function () { throw new RuntimeException('network'); }, $now + 123) === 0, 'Transport exception escaped');
    reportCheck(reportEventsRun($pdo, $setting, $lang, $accept, $now + 183) === 1, 'Transport error lost pending report');
    // Real callback classification: unknown probes do not create incidents; signed mismatches and cancellations do.
    $body = ['payment_id' => 'abc123', 'reason' => 'paid_amount_below_minimum'];
    tronadoQueueCallbackReport(401, $body); reportCheck($count() === 4, 'Unauthorized probe produced channel noise');
    tronadoQueueCallbackReport(409, $body, ['UserPaidTomanAmount' => 490000]);
    tronadoQueueCallbackReport(409, $body, ['UserPaidTomanAmount' => 490000]);
    tronadoQueueCallbackReport(200, ['payment_id' => 'abc123', 'cancelled' => true]);
    reportCheck($count() === 6, 'Callback mismatch/cancellation not queued once');
    // Exercise the shared marker used by card, Stars and hosted gateways, without changing wallet balance.
    $source = file_get_contents(dirname(__DIR__) . '/function.php');
    $a = strpos($source, 'function markPaymentFulfillment('); $b = strpos($source, 'function DirectPayment(', $a); eval(substr($source, $a, $b - $a));
    markPaymentFulfillment('abc123', 'failed'); markPaymentFulfillment('abc123', 'refunded');
    reportCheck($count() === 6 && (int) $pdo->query('SELECT Balance FROM user')->fetchColumn() === 1000, 'Shared reporting duplicated events or changed balance');
    $schema->apply('report_event_outbox', require dirname(__DIR__) . '/db/tables/report_event_outbox.php');
    reportCheck($count() === 6, 'Repeated migration removed reports');
    $pdo->exec('RENAME TABLE report_event_outbox TO outbox_unavailable');
    markPaymentFulfillment('abc123', 'failed');
    reportCheck($pdo->query('SELECT fulfillment_status FROM Payment_report')->fetchColumn() === 'failed'
        && (int) $pdo->query('SELECT Balance FROM user')->fetchColumn() === 1000, 'Report storage outage affected financial state');
    echo "Report events OK: durable deduplication, retries, topics, lock, localization, callback classification and financial isolation\n";
} finally { $pdo->exec("DROP DATABASE $db"); }
