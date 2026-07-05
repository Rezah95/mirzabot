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

$verify = checkPayUniquePay($order_id);
if (empty($verify['success'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $verify['detail'] ?? 'verify failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($verify['paid']) && $Payment_report['payment_Status'] !== 'paid') {
    if (function_exists('languagechange')) {
        $textbotlang = languagechange();
    }
    update("Payment_report", "dec_not_confirmed", json_encode($verify['invoice'] ?? $verify['data'], JSON_UNESCAPED_UNICODE), "id_order", $order_id);
    DirectPayment($order_id);

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
