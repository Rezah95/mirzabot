<?php

ini_set('error_log', 'error_log');
foreach (['config.php', 'botapi.php', 'Marzban.php', 'function.php', 'panels.php', 'keyboard.php', 'jdf.php', 'text.php'] as $dependency) {
    $path = __DIR__ . '/../' . $dependency;
    if (file_exists($path)) {
        require_once $path;
    }
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/tronado_lib.php';

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

$paymentReport = select('Payment_report', '*', 'id_order', $paymentId, 'select');
if (!$paymentReport) {
    error_log('Tronado callback received for an unknown payment: ' . $paymentId);
    tronadoCallbackRespond(404, ['ok' => false, 'error' => 'Unknown payment']);
}

if (($paymentReport['Payment_Method'] ?? '') !== 'Tronado') {
    error_log('Tronado callback ignored for a non-Tronado order: ' . $paymentId);
    tronadoCallbackRespond(404, ['ok' => false, 'error' => 'Unknown payment']);
}

$isPaid = filter_var($callback['IsPaid'] ?? false, FILTER_VALIDATE_BOOLEAN);
$paymentAccepted = !in_array((int) $orderStatusId, [40, 200], true)
    && ($isPaid || (int) $orderStatusId === 30);
if ($paymentAccepted) {
    // With 100% business-paid fees the customer's payment approximates the
    // invoice; delivered TRX is lower after fees. Allow the provider's stated
    // few-thousand-toman rounding variance, but reject material underpayment.
    if (!tronadoPaidCallbackMatchesOrder($callback, $paymentReport)) {
        error_log('Tronado callback amount mismatch for ' . $paymentId);
        tronadoCallbackRespond(409, ['ok' => false, 'error' => 'Amount does not match order']);
    }
}

$callbackRegistration = tronadoRegisterCallback($paymentId, (int) $orderStatusId, $rawBody);
if ($callbackRegistration === 'error') {
    tronadoCallbackRespond(503, ['ok' => false, 'error' => 'Unable to persist callback']);
}
if ($callbackRegistration === 'duplicate') {
    $current = select('Payment_report', '*', 'id_order', $paymentId, 'select');
    if (($current['fulfillment_status'] ?? null) === 'failed'
        || ($current['fulfillment_status'] ?? null) === 'processing') {
        tronadoCallbackRespond(503, ['ok' => false, 'error' => 'Payment requires reconciliation']);
    }
    tronadoCallbackRespond(204);
}

if (!$paymentAccepted) {
    // Tronado sends a callback for every state transition. It is recorded above,
    // but only PaymentAccepted/IsPaid may fulfil the invoice.
    if ((int) $orderStatusId === 200 && ($paymentReport['payment_Status'] ?? '') === 'paid') {
        error_log('Tronado previously paid order was cancelled and needs manual reconciliation: ' . $paymentId);
        $reportSetting = select('setting', '*');
        if (!empty($reportSetting['Channel_Report'])) {
            telegram('sendmessage', [
                'chat_id' => $reportSetting['Channel_Report'],
                'text' => '⚠️ Tronado order ' . $paymentId . ' was cancelled after fulfilment. Reconcile the service and wallet manually.',
            ]);
        }
    }
    tronadoCallbackRespond(204);
}

if (!claimPaymentPaid($paymentId)) {
    $current = select('Payment_report', '*', 'id_order', $paymentId, 'select');
    if (($current['fulfillment_status'] ?? null) === 'failed'
        || ($current['fulfillment_status'] ?? null) === 'processing') {
        tronadoCallbackRespond(503, ['ok' => false, 'error' => 'Payment requires reconciliation']);
    }
    tronadoCallbackRespond(204);
}

$textbotlang = languagechange();
try {
    $delivered = DirectPayment($paymentId, '../images.jpg');
} catch (Throwable $directPaymentError) {
    markPaymentFulfillment($paymentId, 'failed');
    error_log('Tronado DirectPayment failed for order ' . $paymentId . ': ' . $directPaymentError->getMessage());
    tronadoCallbackRespond(500, ['ok' => false, 'error' => 'Order fulfilment failed']);
}
if ($delivered === false) {
    tronadoCallbackRespond(200, ['ok' => true, 'payment_id' => $paymentId, 'fulfillment' => 'refunded']);
}

$cashback = getPaySettingValue('tronado_cashback', '0');
$buyer = select('user', '*', 'id', $paymentReport['id_user'], 'select');
if (is_numeric($cashback) && (float) $cashback > 0 && $buyer) {
    $cashbackAmount = ((float) $paymentReport['price'] * (float) $cashback) / 100;
    addBalance($buyer['id'], $cashbackAmount);
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
