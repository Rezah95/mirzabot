<?php

ini_set('error_log', __DIR__ . '/error_log');
ini_set('display_errors', '0');

// Reject probes before loading the bot or opening its database.
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST is required']);
    exit;
}
error_log('Tronado callback received; sapi=' . PHP_SAPI);
$rawBody = file_get_contents('php://input', false, null, 0, 1024 * 1024 + 1);
chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'botapi.php';
require_once 'function.php';
require_once __DIR__ . '/tronado_lib.php';
ini_set('error_log', __DIR__ . '/error_log');

$signature = (string) ($_SERVER['HTTP_X_TRONADO_SIG'] ?? '');
if ($signature === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'X-Tronado-Sig') === 0) {
            $signature = (string) $value;
            break;
        }
    }
}
[$status, $body] = tronadoReceiveCallback($pdo, is_string($rawBody) ? $rawBody : '',
    $signature, tronadoSetting('tronado_ipn_signing_key'));
error_log('Tronado callback result: ' . json_encode(['http_status' => $status] + $body));
if (!empty($body['cancelled'])) {
    error_log('Tronado paid order cancelled; manual reconciliation required: ' . $body['payment_id']);
    $reportSetting = select('setting', '*');
    if (!empty($reportSetting['Channel_Report'])) {
        telegram('sendmessage', [
            'chat_id' => $reportSetting['Channel_Report'],
            'text' => '⚠️ Tronado order ' . $body['payment_id'] . ' was cancelled after acceptance. Reconcile the service and wallet manually.',
        ]);
    }
}
// Close the response before panel/Telegram work on FPM, LiteSpeed and Apache.
// Content-Length lets the provider finish reading even on hosts without finish_request.
$reply = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
ignore_user_abort(true);
http_response_code($status);
header('Content-Length: ' . strlen($reply));
header('Connection: close');
echo $reply;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
} else {
    while (ob_get_level() > 0) {
        if (!@ob_end_flush()) { break; }
    }
    flush();
}

// Always attempt delivery; cron is recovery, not a prerequisite on non-FPM hosts.
if ($status === 200 && ($body['fulfillment'] ?? '') === 'queued') {
    require_once __DIR__ . '/tronado_delivery.php';
    tronadoDeliverPayment($pdo, $body['payment_id']);
}
