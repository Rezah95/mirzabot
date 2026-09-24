<?php
/* TetrAminator library for Mirzabot */
if (!function_exists('tetra_setting')) {
    function tetra_setting($name, $default = '') {
        $r = select("PaySetting", "ValuePay", "NamePay", $name, "select");
        return ($r && isset($r['ValuePay']) && $r['ValuePay'] !== null) ? $r['ValuePay'] : $default;
    }
}
if (!function_exists('tetraminatorCreateOrder')) {
    function tetraminatorCreateOrder($id_user, $amount) {
        global $pdo;
        $order_id = 'tm' . bin2hex(random_bytes(6));
        $time = date('Y/m/d H:i:s');
        $price = (int)$amount;
        $status = 'Unpaid';
        $method = 'Tetraminator';
        $invoice = 'tetraminatorwallet';
        
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$id_user, $order_id, $time, $price, $status, $method, $invoice]);
        return $order_id;
    }
}
if (!function_exists('createPayTetraminator')) {
    function createPayTetraminator($price, $order_id) {
        global $domainhosts;
        $base   = rtrim(tetra_setting('tetraminator_baseurl', 'https://api.tetraminator.com/v1'), '/');
        $apikey = tetra_setting('tetraminator_apikey', '');
        
        $cb = trim((string)$domainhosts);
        if (!preg_match('~^https?://~i', $cb)) { $cb = 'https://' . $cb; }
        
        $secure_sig = hash_hmac('sha256', (string)$order_id, $apikey);
        $cb = rtrim($cb, '/') . '/payment/tetraminator.php?order_id=' . urlencode((string)$order_id) . '&token=' . $secure_sig;

        $payload = [
            'price'        => (int)$price,
            'callback_url' => $cb
        ];

        $ch = curl_init($base . '/invoice/create');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'TetrAminator-Mirzabot-Gateway/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-KEY: ' . $apikey],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) return ['success' => false, 'detail' => 'ارتباط با سرور برقرار نشد: ' . $err];
        
        $data = json_decode($res, true);
        if (!is_array($data)) {
            $raw_safe = mb_substr(strip_tags(trim($res)), 0, 150);
            return ['success' => false, 'detail' => "پاسخ نامعتبر کد $http_code. خروجی خام: " . ($raw_safe ?: "خالی")];
        }

        if ($http_code === 201 && ($data['status'] ?? null) === true
            && !empty($data['payment_link']) && !empty($data['pay_id'])) {
            return ['success' => true, 'data' => ['payment_url' => $data['payment_link'], 'pay_id' => (string) $data['pay_id']]];
        }
        
        $error_msg = $data['message'] ?? $data['error'] ?? $data['detail'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);
        return ['success' => false, 'detail' => 'خطای وب‌سرویس درگاه: ' . $error_msg];
    }
}

if (!function_exists('tetraminatorInquire')) {
    function tetraminatorInquire($pay_id) {
        $base = rtrim(tetra_setting('tetraminator_baseurl', 'https://api.tetraminator.com/v1'), '/');
        $apikey = trim((string) tetra_setting('tetraminator_apikey', ''));
        if ($apikey === '' || $pay_id === '') {
            return ['ok' => false, 'detail' => 'Missing API key or pay_id'];
        }
        $ch = curl_init($base . '/payment/inquiry/' . rawurlencode((string) $pay_id));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['X-API-KEY: ' . $apikey],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response) || $httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'detail' => 'Inquiry request failed'];
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'detail' => 'Invalid inquiry response'];
        }
        $paid = ($data['status'] ?? null) === true && ($data['payment_status'] ?? null) === 'paid'
            && (string) ($data['pay_id'] ?? '') === (string) $pay_id;
        return ['ok' => true, 'paid' => $paid, 'data' => $data];
    }
}
