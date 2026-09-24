<?php

function tronadoSetting(string $name, string $default = ''): string
{
    return trim((string) getPaySettingValue($name, $default));
}

function tronadoCredentialsReady(): bool
{
    return !in_array(tronadoSetting('tronado_api_key'), ['', '0'], true)
        && preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', tronadoSetting('tronado_wallet_address')) === 1
        && !in_array(tronadoSetting('tronado_ipn_signing_key'), ['', '0'], true);
}

function tronadoConfigured(): bool
{
    return tronadoSetting('tronado_status', 'offtronado') === 'ontronado'
        && tronadoCredentialsReady();
}

/** Send a JSON POST to the official Tronado API. The caller supplies the key only for order creation. */
function tronadoPost(string $url, array $body, string $apiKey = ''): array
{
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'x-api-key: ' . $apiKey;
    }
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize Tronado request');
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($response === false || $status !== 200) {
        throw new RuntimeException('Tronado request failed (HTTP ' . $status . ($error !== '' ? ', transport error' : '') . ')');
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid Tronado JSON response');
    }
    return $decoded;
}

/** @return array{payment_url:string,token:string,tron_amount:float,tron_price_toman:int,estimated_toman_amount:mixed} */
function tronadoCreateOrder(string $orderId, int $amountToman, string $domain, ?string $baseUrl = null): array
{
    if (!tronadoConfigured() || $amountToman <= 0 || !preg_match('/^[a-f0-9]{10}$/', $orderId)
        || !preg_match('/^[A-Za-z0-9.-]+$/', $domain) || !str_contains($domain, '.')) {
        throw new InvalidArgumentException('Tronado configuration or order is invalid');
    }
    $baseUrl = rtrim($baseUrl ?? 'https://bot.tronado.cloud', '/');
    if (!preg_match('~^https://bot\.tronado\.cloud$~', $baseUrl)
        && !preg_match('~^http://127\.0\.0\.1:[0-9]+$~', $baseUrl)) {
        throw new InvalidArgumentException('Invalid Tronado API URL');
    }
    $priceResponse = tronadoPost($baseUrl . '/Tron/GetPriceToToman', []);
    $tronPrice = filter_var($priceResponse['TronPriceToman'] ?? null, FILTER_VALIDATE_INT);
    if ($tronPrice === false || $tronPrice <= 0) {
        throw new RuntimeException('Invalid Tronado price');
    }
    $tronAmount = ceil(($amountToman / $tronPrice) * 1000000) / 1000000;
    $orderResponse = tronadoPost(
        $baseUrl . '/api/v5/GetOrderToken?wageFromBusinessPercentage=100',
        [
            'PaymentID' => $orderId,
            'WalletAddress' => tronadoSetting('tronado_wallet_address'),
            'TronAmount' => $tronAmount,
            'CallbackUrl' => 'https://' . $domain . '/payment/tronado.php',
        ],
        tronadoSetting('tronado_api_key')
    );
    $token = trim((string) ($orderResponse['Token'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{8,200}$/', $token) || !empty($orderResponse['ErrorMessage'])) {
        throw new RuntimeException('Tronado did not create the order');
    }
    $paymentUrl = 'https://t.me/tronado_robot/customerpayment?startapp=' . rawurlencode($token);
    $fullUrl = (string) ($orderResponse['FullPaymentUrl'] ?? '');
    $fullHost = strtolower((string) parse_url($fullUrl, PHP_URL_HOST));
    if (parse_url($fullUrl, PHP_URL_SCHEME) === 'https' && in_array($fullHost, ['t.me', 'telegram.me'], true)) {
        $paymentUrl = $fullUrl;
    }
    return [
        'payment_url' => $paymentUrl,
        'token' => $token,
        'tron_amount' => $tronAmount,
        'tron_price_toman' => $tronPrice,
        'estimated_toman_amount' => $orderResponse['EstimatedTomanAmount'] ?? null,
    ];
}

function tronadoVerifyCallbackSignature(string $rawBody, string $signature, string $signingKey): bool
{
    $signature = strtolower(trim($signature));
    if ($signingKey === '' || $signingKey === '0' || !preg_match('/^[a-f0-9]{128}$/', $signature)) {
        return false;
    }

    return hash_equals(hash_hmac('sha512', $rawBody, $signingKey), $signature);
}

function tronadoPaidCallbackMatchesOrder(array $callback, array $paymentReport): bool
{
    $metadata = json_decode((string) ($paymentReport['dec_not_confirmed'] ?? ''), true);
    if (!is_array($metadata)) {
        return false;
    }
    $amount = $callback['UserPaidTomanAmount'] ?? null;
    $received = $callback['TomanAmountWithoutWage'] ?? null;
    $deliveredTron = $callback['TronAmount'] ?? null;
    $amountMatches = is_scalar($amount) && ctype_digit((string) $amount)
        && (int) $amount >= max(1, (int) ($paymentReport['price'] ?? 0) - 5000)
        && is_scalar($received) && ctype_digit((string) $received) && (int) $received > 0
        && is_numeric($deliveredTron) && (float) $deliveredTron > 0;
    if (!$amountMatches) {
        return false;
    }
    if (($metadata['wage_from_business_percentage'] ?? null) === 100) {
        $expectedTron = $metadata['tron_amount'] ?? null;
        $wallet = $metadata['wallet'] ?? null;
        return is_numeric($expectedTron) && (float) $expectedTron > 0
            && is_string($wallet) && $wallet !== ''
            && hash_equals($wallet, (string) ($callback['Wallet'] ?? ''));
    }
    // Orders created by the earlier official Tronado implementation stored
    // only the provider response. Bind those in-flight invoices to its token
    // and the legacy destination wallet without accepting CubePay orders.
    $legacyToken = strtolower(str_replace('-', '', (string) ($metadata['Token'] ?? '')));
    $callbackToken = strtolower(str_replace('-', '', (string) ($callback['UniqueCode'] ?? '')));
    $legacyWallet = tronadoSetting('walletaddress');
    return strlen($legacyToken) >= 8 && hash_equals($legacyToken, $callbackToken)
        && preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $legacyWallet) === 1
        && hash_equals($legacyWallet, (string) ($callback['Wallet'] ?? ''));
}

/** @return 'new'|'duplicate'|'error' */
function tronadoRegisterCallback(string $paymentId, int $orderStatusId, string $rawBody): string
{
    global $pdo;

    try {
        $statement = $pdo->prepare(
            'INSERT INTO Tronado_callback (payment_id, payment_id_hash, order_status_id, raw_payload) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$paymentId, hash('sha256', $paymentId), $orderStatusId, $rawBody]);
        return 'new';
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) === 1062) {
            return 'duplicate';
        }
        error_log('Tronado callback storage failed: ' . $error->getMessage());
        return 'error';
    }
}
