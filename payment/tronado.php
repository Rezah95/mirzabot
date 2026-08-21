<?php

ini_set('error_log', 'error_log');
foreach (['config.php', 'botapi.php', 'Marzban.php', 'function.php', 'panels.php', 'keyboard.php', 'jdf.php', 'text.php'] as $dependency) {
    $path = __DIR__ . '/../' . $dependency;
    if (file_exists($path)) {
        require_once $path;
    }
}
require_once __DIR__ . '/../vendor/autoload.php';

if (class_exists('ManagePanel')) {
    $ManagePanel = new ManagePanel();
}

function tronadoCallbackRespond($statusCode, array $payload = [])
{
    http_response_code($statusCode);
    if ($statusCode !== 204) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function tronadoCallbackSignatureHeader()
{
    $signature = (string) ($_SERVER['HTTP_X_TRONADO_SIG'] ?? '');
    if ($signature !== '' || !function_exists('getallheaders')) {
        return $signature;
    }

    foreach (getallheaders() as $name => $value) {
        if (strcasecmp((string) $name, 'X-Tronado-Sig') === 0) {
            return (string) $value;
        }
    }

    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    tronadoCallbackRespond(405, ['ok' => false, 'error' => 'POST is required']);
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 1024 * 1024) {
    tronadoCallbackRespond(400, ['ok' => false, 'error' => 'Invalid callback payload']);
}

$signingKey = tronadoSetting('tronado_ipn_signing_key');
if ($signingKey === '' || $signingKey === '0') {
    error_log('Tronado callback rejected because the IPN signing key is not configured');
    tronadoCallbackRespond(503, ['ok' => false, 'error' => 'IPN signing key is not configured']);
}

if (!tronadoVerifyCallbackSignature($rawBody, tronadoCallbackSignatureHeader(), $signingKey)) {
    error_log('Tronado callback rejected because its signature is invalid');
    tronadoCallbackRespond(401, ['ok' => false, 'error' => 'Invalid signature']);
}

$callback = json_decode($rawBody, true);
if (!is_array($callback) || json_last_error() !== JSON_ERROR_NONE) {
    tronadoCallbackRespond(400, ['ok' => false, 'error' => 'Invalid JSON']);
}

$paymentId = trim((string) ($callback['PaymentId'] ?? ''));
$orderStatusId = filter_var($callback['OrderStatusID'] ?? null, FILTER_VALIDATE_INT);
if ($paymentId === '' || strlen($paymentId) > 2000 || $orderStatusId === false || $orderStatusId === null) {
    tronadoCallbackRespond(400, ['ok' => false, 'error' => 'PaymentId and OrderStatusID are required']);
}

$callbackRegistration = tronadoRegisterCallback($paymentId, (int) $orderStatusId, $rawBody);
if ($callbackRegistration === 'error') {
    tronadoCallbackRespond(503, ['ok' => false, 'error' => 'Unable to persist callback']);
}
if ($callbackRegistration === 'duplicate') {
    tronadoCallbackRespond(204);
}

$paymentReport = select('Payment_report', '*', 'id_order', $paymentId, 'select');
if (!$paymentReport) {
    error_log('Tronado callback received for an unknown payment: ' . $paymentId);
    tronadoCallbackRespond(204);
}

// Currency Rial 2 is kept only for invoices created by the legacy implementation.
if (!in_array((string) ($paymentReport['Payment_Method'] ?? ''), ['Tronado', 'Currency Rial 2'], true)) {
    error_log('Tronado callback ignored for a non-Tronado order: ' . $paymentId);
    tronadoCallbackRespond(204);
}

update('Payment_report', 'dec_not_confirmed', $rawBody, 'id_order', $paymentId);

$isPaid = filter_var($callback['IsPaid'] ?? false, FILTER_VALIDATE_BOOLEAN);
if (!$isPaid && (int) $orderStatusId !== 30) {
    // Tronado sends a callback for every state transition. It is recorded above,
    // but only PaymentAccepted/IsPaid may fulfil the invoice.
    tronadoCallbackRespond(204);
}

if (!claimPaymentPaid($paymentId)) {
    tronadoCallbackRespond(204);
}

$textbotlang = languagechange();
try {
    DirectPayment($paymentId, '../images.jpg');
} catch (Throwable $directPaymentError) {
    error_log('Tronado DirectPayment failed for order ' . $paymentId . ': ' . $directPaymentError->getMessage());
    tronadoCallbackRespond(500, ['ok' => false, 'error' => 'Order fulfilment failed']);
}

$cashback = getPaySettingValue('chashbackiranpay2', '0');
$buyer = select('user', '*', 'id', $paymentReport['id_user'], 'select');
if (is_numeric($cashback) && (float) $cashback > 0 && $buyer) {
    $cashbackAmount = ((float) $paymentReport['price'] * (float) $cashback) / 100;
    update('user', 'Balance', (float) $buyer['Balance'] + $cashbackAmount, 'id', $buyer['id']);
    sendmessage(
        $buyer['id'],
        sprintf($textbotlang['paymentGateway']['giftReport'], $cashbackAmount),
        null,
        'HTML'
    );
}

$setting = select('setting', '*');
if (!empty($setting['Channel_Report'])) {
    $paymentReportTopic = select('topicid', 'idreport', 'report', 'paymentreport', 'select')['idreport'] ?? null;
    $reportText = sprintf(
        $textbotlang['paymentGateway']['reportTronado'],
        $buyer['username'] ?? $paymentReport['id_user'],
        $paymentReport['id_user'],
        number_format((float) $paymentReport['price'])
    );
    telegram('sendmessage', [
        'chat_id' => $setting['Channel_Report'],
        'message_thread_id' => $paymentReportTopic,
        'text' => $reportText,
        'parse_mode' => 'HTML',
    ]);
}

tronadoCallbackRespond(200, [
    'ok' => true,
    'payment_id' => $paymentId,
    'order_status_id' => (int) $orderStatusId,
    'paid' => true,
]);
