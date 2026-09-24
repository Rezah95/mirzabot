<?php

ini_set('error_log', 'error_log');
foreach (['config.php', 'botapi.php', 'Marzban.php', 'function.php', 'panels.php', 'keyboard.php', 'jdf.php', 'text.php'] as $upf) {
    if (file_exists(__DIR__ . '/../' . $upf)) {
        require_once __DIR__ . '/../' . $upf;
    }
}
require_once __DIR__ . '/uniquepay_lib.php';
if (class_exists('ManagePanel')) {
    $ManagePanel = new ManagePanel();
}

$order_id = $_GET['order_id'] ?? ($_POST['hashId'] ?? ($_POST['order_id'] ?? ''));
if ($order_id === '') {
    http_response_code(400);
    echo 'Bad Request';
    exit;
}

$Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
if (!$Payment_report) {
    http_response_code(404);
    echo 'Order not found';
    exit;
}
if ($Payment_report['Payment_Method'] !== 'UniquePay') {
    http_response_code(404);
    echo 'Order not found';
    exit;
}
if ($Payment_report['payment_Status'] === 'paid') {
    if (in_array($Payment_report['fulfillment_status'] ?? null, ['fulfilled', 'refunded'], true)) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'paid' => true, 'fulfillment' => $Payment_report['fulfillment_status']]);
        exit;
    }
    if (in_array($Payment_report['fulfillment_status'] ?? null, ['processing', 'failed'], true)) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Payment requires reconciliation']);
        exit;
    }
}

$verify = checkPayUniquePay($order_id);
if (empty($verify['success'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $verify['detail'] ?? 'verify failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$createdInvoice = json_decode((string) ($Payment_report['dec_not_confirmed'] ?? ''), true);
$expectedRefId = $createdInvoice['refId'] ?? null;
$verifiedInvoice = $verify['invoice'] ?? [];
if (!is_array($createdInvoice) || !is_string($expectedRefId) || $expectedRefId === ''
    || (string) ($createdInvoice['hashId'] ?? '') !== (string) $order_id
    || (string) ($verifiedInvoice['id'] ?? '') !== $expectedRefId
    || !isset($verifiedInvoice['amount']) || !ctype_digit((string) $verifiedInvoice['amount'])
    || (int) $verifiedInvoice['amount'] !== (int) $Payment_report['price']) {
    error_log('UniquePay invoice mismatch for order ' . $order_id);
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Invoice does not match order']);
    exit;
}

if (!empty($verify['paid']) && claimPaymentPaid($order_id)) {
    if (function_exists('languagechange')) {
        $textbotlang = languagechange();
    }
    $createdInvoice['verification'] = $verifiedInvoice;
    update("Payment_report", "dec_not_confirmed", json_encode($createdInvoice, JSON_UNESCAPED_UNICODE), "id_order", $order_id);
    try {
        $delivered = DirectPayment($order_id, '../images.jpg');
    } catch (Throwable $deliveryError) {
        markPaymentFulfillment($order_id, 'failed');
        error_log('UniquePay delivery failed for ' . $order_id . ': ' . $deliveryError->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'delivery failed']);
        exit;
    }
    if ($delivered === false) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'paid' => true, 'fulfillment' => 'refunded']);
        exit;
    }

    if (function_exists('telegram')) {
        $setting = select("setting", "*");
        if (!empty($setting['Channel_Report'])) {
            $paymentreports = select("topicid", "idreport", "report", "paymentreport", "select")['idreport'];
            $invoice = $verify['invoice'] ?? [];
            $statusText = !empty($verify['data']['status']) ? 'true' : 'false';
            $invoiceId = $invoice['id'] ?? '-';
            $invoiceAmount = isset($invoice['amount']) ? number_format((int) $invoice['amount']) : '-';
            $invoiceCurrency = $invoice['currency'] ?? '-';
            $invoiceFee = isset($invoice['fee']) ? number_format((int) $invoice['fee']) : '-';
            $invoiceIsPaid = !empty($invoice['isPaid']) ? 'true' : 'false';
            $txt = "💳 <b>پرداخت موفق یونیک‌پی</b>\n" .
                   "status: <code>{$statusText}</code>\n" .
                   "invoice.id: <code>{$invoiceId}</code>\n" .
                   "invoice.amount: <code>{$invoiceAmount}</code>\n" .
                   "invoice.currency: <code>{$invoiceCurrency}</code>\n" .
                   "invoice.fee: <code>{$invoiceFee}</code>\n" .
                   "invoice.isPaid: <code>{$invoiceIsPaid}</code>";
            telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $paymentreports,
                'text' => $txt,
                'parse_mode' => 'HTML'
            ]);
        }
    }
}

http_response_code(200);
echo json_encode(['ok' => true, 'paid' => !empty($verify['paid'])], JSON_UNESCAPED_UNICODE);
