<?php

// Reject probes before loading the bot or opening its database.
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST is required']);
    exit;
}
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
if ($status >= 400) {
    error_log('Tronado callback rejected: ' . json_encode(['http_status' => $status] + $body));
}
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
http_response_code($status);
echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// The committed queue survives a stopped PHP process. On non-FPM hosting cron delivers it.
if ($status === 200 && ($body['fulfillment'] ?? '') === 'queued' && function_exists('fastcgi_finish_request')) {
    ignore_user_abort(true);
    fastcgi_finish_request();
    require_once __DIR__ . '/tronado_delivery.php';
    tronadoDeliverPayment($pdo, $body['payment_id']);
}
