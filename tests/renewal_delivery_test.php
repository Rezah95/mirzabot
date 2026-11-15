<?php
// Real sending/formatting/monitor methods, with no config, network or production database.
set_error_handler(static function ($s, $m, $f, $l) { throw new ErrorException($m, 0, $s, $f, $l); });
function deliveryCheck($ok, $message): void { if (!$ok) { throw new RuntimeException($message); } }
$source = file_get_contents(dirname(__DIR__) . '/botapi.php');
eval(substr($source, strpos($source, 'function splitTelegramText('), strpos($source, 'function sendDocument(') - strpos($source, 'function splitTelegramText(')));
function isTelegramChatIdEmpty($id): bool { return empty($id); }
function telegram($method, $args, $token = null): array {
    $GLOBALS['requests'][] = [$method, $args, $token];
    return array_shift($GLOBALS['responses']);
}
$responses = [['ok' => false, 'error_code' => 400], ['ok' => true]]; $requests = [];
$result = sendmessage(101, str_repeat('x', 4200), null, 'HTML');
deliveryCheck(!$result['ok'] && count($requests) === 1, 'First chunk failure hidden by later success');
$responses = [['ok' => true], ['ok' => false, 'error_code' => 429]]; $requests = [];
$result = sendmessage(101, str_repeat('x', 4200), null, 'HTML');
deliveryCheck(!empty($result['partial_delivery']) && !isset($result['error_code']), 'Partial delivery permits full-message retry');
$textbotlang = require dirname(__DIR__) . '/lang/fa.php';
$source = file_get_contents(dirname(__DIR__) . '/function.php');
eval(substr($source, strpos($source, 'function formatBytes('), strpos($source, 'function generateUsername(') - strpos($source, 'function formatBytes(')));
foreach ([-250 * 1073741824, 0, NAN, INF] as $bytes) {
    deliveryCheck(str_starts_with(formatBytes($bytes), '0 '), 'Invalid/negative volume produced NAN');
}
deliveryCheck(str_starts_with(formatBytes(1073741824), '1 '), 'Positive bytes changed');
$source = file_get_contents(dirname(__DIR__) . '/cronbot/NoticationsService.php');
eval(substr($source, strpos($source, 'class ServiceMonitor'), strpos($source, '// Execute the volume monitoring') - strpos($source, 'class ServiceMonitor')));
$reflection = new ReflectionClass('ServiceMonitor'); $monitor = $reflection->newInstanceWithoutConstructor();
foreach (['textBotLang' => $textbotlang, 'setting' => ['volumewarn' => 5, 'daywarn' => 3, 'Channel_Report' => ''], 'text_Purchased_services' => 'services'] as $name => $value) {
    $reflection->getProperty($name)->setValue($monitor, $value);
}
function update(...$args): void { $GLOBALS['updates'][] = $args; }
$invoice = ['id_invoice' => 'i', 'id_user' => 101, 'bottype' => null, 'username' => 'sample', 'notifctions' => '{"time":false,"volume":false}'];
$user = ['status_cron' => 1]; $updates = [];
foreach (['checkVolumeThreshold', 'checkTimeExpiration'] as $method) {
    $data = ['status' => 'active', 'expire' => time() + 86400, 'data_limit' => 10 * 1073741824, 'used_traffic' => 6 * 1073741824];
    $responses = [['ok' => false, 'error_code' => 403]];
    $reflection->getMethod($method)->invoke($monitor, $invoice, $user, $data, 'sample');
    deliveryCheck($updates === [], 'Failed warning marked sent');
    $responses = [['ok' => true]];
    $reflection->getMethod($method)->invoke($monitor, $invoice, $user, $data, 'sample');
    deliveryCheck(count($updates) === 1, 'Confirmed warning not marked'); $updates = [];
}
require_once dirname(__DIR__) . '/renewal_delivery.php';
function select($table, ...$args): array { return $table === 'setting' ? ['Channel_Report' => -100123] : ['idreport' => 7]; }
function languagechange($path): array { return $GLOBALS['textbotlang']; }
$responses = [['ok' => true]]; $requests = [];
renewalDeliveryReport($invoice, ['ok' => true, 'result' => ['chat' => ['id' => 101], 'message_id' => 42]], 'volume');
deliveryCheck($requests[0][1]['chat_id'] === -100123 && $requests[0][1]['message_thread_id'] === 7 && str_contains($requests[0][1]['text'], '42'), 'Report routing/evidence missing');
renewalDeliveryReportSafely(static function () { throw new RuntimeException('report failure'); }, $invoice, ['ok' => true], 'time');
echo "Delivery checks passed: multipart failures, negative/unlimited volume, warning flags, report routing and report isolation\n";
