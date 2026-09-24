<?php
/* UniquePay library for Mirzabot */
if (!function_exists('uniquepay_setting')) {
    function uniquepay_setting($name, $default = '') {
        $r = select("PaySetting", "ValuePay", "NamePay", $name, "select");
        return ($r && isset($r['ValuePay']) && $r['ValuePay'] !== null) ? $r['ValuePay'] : $default;
    }
}

if (!function_exists('uniquepay_http_post')) {
    function uniquepay_http_post($endpoint, array $payload) {
        $base = rtrim(uniquepay_setting('uniquepay_baseurl', 'https://uniquepay.top'), '/');
        $token = trim((string) uniquepay_setting('uniquepay_token', ''));
        if ($token === '' || $token === '0') {
            return ['success' => false, 'detail' => 'توکن بیزینس یونیک‌پی تنظیم نشده است.'];
        }

        $ch = curl_init($base . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'UniquePay-Mirzabot-Gateway/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query($payload),
        ]);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false) {
            return ['success' => false, 'detail' => 'ارتباط با سرور یونیک‌پی برقرار نشد: ' . $err];
        }

        $data = json_decode($res, true);
        if (!is_array($data)) {
            $raw_safe = mb_substr(strip_tags(trim($res)), 0, 180);
            return ['success' => false, 'detail' => "پاسخ نامعتبر یونیک‌پی با کد $http_code. خروجی خام: " . ($raw_safe ?: 'خالی')];
        }

        return ['success' => true, 'data' => $data, 'http_code' => $http_code];
    }
}

if (!function_exists('createPayUniquePay')) {
    function createPayUniquePay($price, $order_id) {
        global $domainhosts;
        $cb = trim((string) $domainhosts);
        if (!preg_match('~^https?://~i', $cb)) {
            $cb = 'https://' . $cb;
        }
        $cb = rtrim($cb, '/') . '/payment/uniquepay.php?order_id=' . urlencode((string) $order_id);

        $payload = [
            'hashId' => (string) $order_id,
            'amount' => (int) $price,
            'redirectUrl' => $cb,
            'callbackUrl' => $cb,
        ];

        $result = uniquepay_http_post('/api/create-invoice', $payload);
        if (empty($result['success'])) {
            return $result;
        }

        $data = $result['data'];
        if (($result['http_code'] ?? 0) >= 200 && ($result['http_code'] ?? 0) < 300
            && ($data['status'] ?? null) === true && (int) ($data['code'] ?? 0) === 200
            && (string) ($data['hashId'] ?? '') === (string) $order_id
            && !empty($data['paymentLink']) && !empty($data['refId'])) {
            return [
                'success' => true,
                'data' => [
                    'payment_url' => $data['paymentLink'],
                    'ref_id' => $data['refId'],
                    'raw' => $data,
                ],
            ];
        }

        $error_msg = $data['message'] ?? $data['error'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);
        return ['success' => false, 'detail' => 'خطای وب‌سرویس یونیک‌پی: ' . $error_msg, 'data' => $data];
    }
}

if (!function_exists('checkPayUniquePay')) {
    function checkPayUniquePay($order_id) {
        $result = uniquepay_http_post('/api/check-invoice', ['hashId' => (string) $order_id]);
        if (empty($result['success'])) {
            return $result;
        }

        $data = $result['data'];
        if (($result['http_code'] ?? 0) >= 200 && ($result['http_code'] ?? 0) < 300
            && ($data['status'] ?? null) === true && (int) ($data['code'] ?? 0) === 200
            && isset($data['invoice']) && is_array($data['invoice'])) {
            return [
                'success' => true,
                'paid' => ($data['invoice']['isPaid'] ?? null) === true
                    && ($data['invoice']['isVerified'] ?? null) === true
                    && ($data['invoice']['status'] ?? null) === 'paid',
                'invoice' => $data['invoice'],
                'data' => $data,
            ];
        }

        $error_msg = $data['message'] ?? $data['error'] ?? json_encode($data, JSON_UNESCAPED_UNICODE);
        return ['success' => false, 'paid' => false, 'detail' => 'خطای بررسی یونیک‌پی: ' . $error_msg, 'data' => $data];
    }
}
