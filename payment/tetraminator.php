<?php

ini_set('error_log', 'error_log');
foreach (['config.php','botapi.php','Marzban.php','function.php','panels.php','keyboard.php','jdf.php','text.php'] as $tmf) {
    if (file_exists(__DIR__ . '/../' . $tmf)) require_once __DIR__ . '/../' . $tmf;
}
require_once __DIR__ . '/tetraminator_lib.php';
if (class_exists('ManagePanel')) { $ManagePanel = new ManagePanel(); }

$order_id = trim((string) ($_GET['order_id'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));

if ($order_id === '' || $token === '') { 
    http_response_code(400); echo 'Bad Request'; exit; 
}

$apikey = tetra_setting('tetraminator_apikey', '');
if ($apikey === '' || $apikey === '0') {
    http_response_code(503); echo 'Gateway is not configured'; exit;
}
$valid_token = hash_hmac('sha256', $order_id, $apikey);

if (!hash_equals($valid_token, $token)) {
    http_response_code(403); echo 'Invalid security token'; exit;
}

$Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
if (!$Payment_report) { http_response_code(404); echo 'Order not found'; exit; }
if ($Payment_report['Payment_Method'] !== 'Tetraminator') {
    http_response_code(404); echo 'Order not found'; exit;
}
if ($Payment_report['payment_Status'] === 'paid') {
    if (in_array($Payment_report['fulfillment_status'] ?? null, ['fulfilled', 'refunded'], true)) {
        http_response_code(200); echo json_encode(['ok' => true, 'fulfillment' => $Payment_report['fulfillment_status']]); exit;
    }
    if (in_array($Payment_report['fulfillment_status'] ?? null, ['processing', 'failed'], true)) {
        http_response_code(503); echo 'Payment requires reconciliation'; exit;
    }
}
$payId = trim((string) ($Payment_report['dec_not_confirmed'] ?? ''));
if ($payId === '') {
    http_response_code(409); echo 'Payment identifier is missing'; exit;
}
$inquiry = tetraminatorInquire($payId);
if (empty($inquiry['ok'])) {
    http_response_code(502); echo 'Unable to verify payment'; exit;
}
if (empty($inquiry['paid'])) {
    http_response_code(402); echo 'Payment is not confirmed'; exit;
}
$paidAmount = $inquiry['data']['amount'] ?? null;
if (!is_scalar($paidAmount) || !ctype_digit((string) $paidAmount)
    || (int) $paidAmount !== (int) $Payment_report['price']) {
    error_log('Tetraminator amount mismatch for ' . $order_id);
    http_response_code(409); echo 'Payment amount does not match order'; exit;
}

if (claimPaymentPaid($order_id)) {
    if (function_exists('languagechange')) { $textbotlang = languagechange(); }
    try {
        $delivered = DirectPayment($order_id, '../images.jpg');
    } catch (Throwable $deliveryError) {
        markPaymentFulfillment($order_id, 'failed');
        error_log('Tetraminator delivery failed for ' . $order_id . ': ' . $deliveryError->getMessage());
        http_response_code(500); echo 'Delivery failed'; exit;
    }
    if ($delivered === false) {
        http_response_code(200); echo json_encode(['ok' => true, 'fulfillment' => 'refunded']); exit;
    }
    if (function_exists('telegram')) {
        $setting = select("setting", "*");
        if (!empty($setting['Channel_Report'])) {
            $u = select("user", "*", "id", $Payment_report['id_user'], "select");
            $txt = "💎 <b>پرداخت موفق تترامیناتور</b>\n👤 کاربر: " . ($u['username'] ?? $Payment_report['id_user']) .
                   "\n💰 مبلغ: " . number_format((int)$Payment_report['price']) . " تومان\n🆔 سفارش: " . $order_id;
            telegram('sendmessage', ['chat_id' => $setting['Channel_Report'], 'text' => $txt, 'parse_mode' => 'HTML']);
        }
    }
}
http_response_code(200);
echo json_encode(['ok' => true]);
