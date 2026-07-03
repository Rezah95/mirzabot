<?php

ini_set('error_log', 'error_log');
foreach (['config.php','botapi.php','Marzban.php','function.php','panels.php','keyboard.php','jdf.php','text.php'] as $tmf) {
    if (file_exists(__DIR__ . '/../' . $tmf)) require_once __DIR__ . '/../' . $tmf;
}
require_once __DIR__ . '/tetraminator_lib.php';
if (class_exists('ManagePanel')) { $ManagePanel = new ManagePanel(); }

$order_id = $_GET['order_id'] ?? '';
$token    = $_GET['token'] ?? '';

if ($order_id === '' || $token === '') { 
    http_response_code(400); echo 'Bad Request'; exit; 
}

$apikey = tetra_setting('tetraminator_apikey', '');
$valid_token = hash_hmac('sha256', $order_id, $apikey);

if (!hash_equals($valid_token, $token)) {
    http_response_code(403); echo 'Invalid security token'; exit;
}

$Payment_report = select("Payment_report", "*", "id_order", $order_id, "select");
if (!$Payment_report) { http_response_code(404); echo 'Order not found'; exit; }

if ($Payment_report['payment_Status'] !== 'paid') {
    if (function_exists('languagechange')) { $textbotlang = languagechange(); }
    DirectPayment($order_id);                        
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



