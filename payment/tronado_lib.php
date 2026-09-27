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

/** Keep the provider's error useful in logs without recording credentials or payment links. */
function tronadoResponseError(array $response): string
{
    $value = null;
    foreach (['ErrorMessage', 'errorMessage', 'Error', 'error', 'Message', 'message', 'Detail', 'detail', 'title'] as $field) {
        $candidate = $response[$field] ?? null;
        if (is_array($candidate)) {
            $candidate = $candidate['Message'] ?? $candidate['message'] ?? $candidate['Description'] ?? $candidate['description'] ?? null;
        }
        if ((is_string($candidate) || is_numeric($candidate)) && trim((string) $candidate) !== '') {
            $value = $candidate;
            break;
        }
    }
    if ($value === null) {
        return '';
    }
    $message = (string) $value;
    $secrets = [tronadoSetting('tronado_api_key'), tronadoSetting('tronado_ipn_signing_key')];
    if (is_string($response['Token'] ?? null)) {
        $secrets[] = $response['Token'];
    }
    if (is_array($response['Data'] ?? null) && is_string($response['Data']['Token'] ?? null)) {
        $secrets[] = $response['Data']['Token'];
    }
    foreach ($secrets as $secret) {
        if ($secret !== '' && $secret !== '0') {
            $message = str_replace($secret, '[redacted]', $message);
        }
    }
    $message = preg_replace('~https?://[^\s<>"\x27]+~i', '[url]', $message);
    $message = preg_replace('/[\x00-\x20\x7f]+/', ' ', strip_tags($message));
    return mb_substr(trim($message), 0, 400, 'UTF-8');
}

/** Report only JSON field names when the API returns no usable error message. */
function tronadoResponseFields(array $response): string
{
    $fields = [];
    foreach ($response as $name => $value) {
        if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $name)) {
            continue;
        }
        if (is_array($value)) {
            $nested = [];
            foreach (array_keys($value) as $child) {
                if (is_string($child) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $child)) {
                    $nested[] = $child;
                }
                if (count($nested) === 6) {
                    break;
                }
            }
            if ($nested !== []) {
                $name .= '(' . implode(',', $nested) . ')';
            }
        }
        $fields[] = $name;
        if (count($fields) === 12) {
            break;
        }
    }
    return $fields !== [] ? implode(',', $fields) : 'none';
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
    if ($response === false) {
        throw new RuntimeException('Tronado request failed (HTTP ' . $status . ($error !== '' ? ', transport error' : '') . ')');
    }
    $decoded = json_decode($response, true);
    $endpoint = parse_url($url, PHP_URL_PATH);
    $detail = is_array($decoded) ? tronadoResponseError($decoded) : '';
    if ($status !== 200) {
        throw new RuntimeException('Tronado request failed (' . $endpoint . ', HTTP ' . $status . ')' . ($detail !== '' ? ': ' . $detail : ''));
    }
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid Tronado JSON response (' . $endpoint . ')');
    }
    if (!empty($decoded['ErrorMessage']) || !empty($decoded['Error'])) {
        throw new RuntimeException('Tronado API error (' . $endpoint . '): ' . ($detail !== '' ? $detail : 'Unspecified provider error'));
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
    // GetOrderToken v5 wraps the payment data in an IsSuccessful/Code/Message/Data envelope.
    if (($orderResponse['IsSuccessful'] ?? null) !== true) {
        $reason = tronadoResponseError($orderResponse);
        throw new RuntimeException('Tronado order rejected: ' . ($reason !== ''
            ? $reason
            : 'IsSuccessful must be true (fields: ' . tronadoResponseFields($orderResponse) . ')'));
    }
    if (!is_array($orderResponse['Data'] ?? null)) {
        throw new RuntimeException('Tronado order response contains invalid Data');
    }
    $orderResponse = $orderResponse['Data'];
    if (!empty($orderResponse['ErrorMessage']) || !empty($orderResponse['Error'])) {
        $reason = tronadoResponseError($orderResponse);
        throw new RuntimeException('Tronado order rejected: ' . ($reason !== '' ? $reason : 'Unspecified provider error'));
    }
    $token = is_string($orderResponse['Token'] ?? null) ? trim($orderResponse['Token']) : '';
    if ($token === '') {
        $reason = tronadoResponseError($orderResponse);
        throw new RuntimeException($reason !== ''
            ? 'Tronado order rejected: ' . $reason
            : 'Tronado order response is missing Token (fields: ' . tronadoResponseFields($orderResponse) . ')');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{8,200}$/', $token)) {
        throw new RuntimeException('Tronado order response contains an invalid Token format');
    }
    $paymentUrl = 'https://t.me/tronado_robot/customerpayment?startapp=' . rawurlencode($token);
    $fullUrl = is_string($orderResponse['FullPaymentUrl'] ?? null) ? $orderResponse['FullPaymentUrl'] : '';
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
            && is_string($callback['Wallet'] ?? null) && hash_equals($wallet, $callback['Wallet']);
    }
    // Orders created by the earlier official Tronado implementation stored
    // only the provider response. Bind those in-flight invoices to its token
    // and the legacy destination wallet without accepting CubePay orders.
    $legacyToken = strtolower(str_replace('-', '', (string) ($metadata['Token'] ?? '')));
    $callbackToken = strtolower(str_replace('-', '', (string) ($callback['UniqueCode'] ?? '')));
    $legacyWallet = tronadoSetting('walletaddress');
    return strlen($legacyToken) >= 8 && hash_equals($legacyToken, $callbackToken)
        && preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $legacyWallet) === 1
        && is_string($callback['Wallet'] ?? null) && hash_equals($legacyWallet, $callback['Wallet']);
}

/** @return 'new'|'duplicate'|'error' */
function tronadoRegisterCallback(string $paymentId, int $orderStatusId, string $rawBody, ?PDO $connection = null): string
{
    global $pdo;
    $connection ??= $pdo;

    try {
        $statement = $connection->prepare(
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


/** Authenticate and persist payment acceptance before doing any network work. */
function tronadoReceiveCallback(PDO $pdo, string $rawBody, string $signature, string $signingKey): array
{
    if ($rawBody === '' || strlen($rawBody) > 1024 * 1024) {
        return [400, ['ok' => false, 'error' => 'Invalid callback payload']];
    }
    if ($signingKey === '' || $signingKey === '0') {
        return [503, ['ok' => false, 'error' => 'IPN signing key is not configured']];
    }
    if (!tronadoVerifyCallbackSignature($rawBody, $signature, $signingKey)) {
        return [401, ['ok' => false, 'error' => 'Invalid signature']];
    }
    $callback = json_decode($rawBody, true);
    if (!is_array($callback)) {
        return [400, ['ok' => false, 'error' => 'Invalid JSON']];
    }
    $paymentId = is_string($callback['PaymentId'] ?? null) ? trim($callback['PaymentId']) : '';
    $orderStatus = filter_var($callback['OrderStatusID'] ?? null, FILTER_VALIDATE_INT);
    if ($paymentId === '' || strlen($paymentId) > 500 || $orderStatus === false || $orderStatus === null) {
        return [400, ['ok' => false, 'error' => 'PaymentId and OrderStatusID are required']];
    }
    $accepted = !in_array($orderStatus, [40, 200], true)
        && (filter_var($callback['IsPaid'] ?? false, FILTER_VALIDATE_BOOLEAN) || $orderStatus === 30);
    try {
        $pdo->beginTransaction();
        $query = $pdo->prepare('SELECT * FROM Payment_report WHERE id_order = ? LIMIT 2 FOR UPDATE');
        $query->execute([$paymentId]);
        $orders = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($orders) !== 1 || $orders[0]['Payment_Method'] !== 'Tronado') {
            $pdo->rollBack();
            return [404, ['ok' => false, 'error' => 'Unknown or ambiguous payment']];
        }
        $order = $orders[0];
        if ($accepted && !tronadoPaidCallbackMatchesOrder($callback, $order)) {
            $pdo->rollBack();
            return [409, ['ok' => false, 'error' => 'Amount or wallet does not match order']];
        }
        $registration = tronadoRegisterCallback($paymentId, $orderStatus, $rawBody, $pdo);
        if ($registration === 'error') {
            throw new RuntimeException('Unable to persist callback');
        }
        $fulfillment = $order['fulfillment_status'] ?? '';
        if (!$accepted) {
            // Never deliver a queued order after a cancellation. Existing delivery needs review.
            $cancelled = $orderStatus === 200 && $order['payment_Status'] === 'paid';
            if ($cancelled && $fulfillment === 'queued') {
                $pdo->prepare("UPDATE Payment_report SET fulfillment_status = 'failed', fulfillment_updated_at = NOW() WHERE id = ?")
                    ->execute([$order['id']]);
            }
            $pdo->commit();
            return [200, ['ok' => true, 'payment_id' => $paymentId,
                'cancelled' => $cancelled && $registration === 'new']];
        }
        $cancelledEvent = $pdo->prepare('SELECT 1 FROM Tronado_callback WHERE payment_id_hash = ? AND order_status_id = 200');
        $cancelledEvent->execute([hash('sha256', $paymentId)]);
        if ($cancelledEvent->fetchColumn() !== false) {
            $pdo->commit();
            return [409, ['ok' => false, 'payment_id' => $paymentId, 'error' => 'Cancelled payment requires reconciliation']];
        }
        if ($order['payment_Status'] === 'reject' || $fulfillment === 'failed'
            || ($order['payment_Status'] === 'paid' && !in_array($fulfillment, ['queued', 'processing', 'fulfilled', 'refunded'], true))) {
            $pdo->commit();
            return [409, ['ok' => false, 'payment_id' => $paymentId, 'error' => 'Payment requires reconciliation']];
        }
        if ($order['payment_Status'] !== 'paid' && $fulfillment === '') {
            // The event and its recoverable queue entry commit together, including on redelivery.
            $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid', fulfillment_status = 'queued', fulfillment_updated_at = NOW() WHERE id = ?")
                ->execute([$order['id']]);
            $fulfillment = 'queued';
        }
        if (!in_array($fulfillment, ['queued', 'processing', 'fulfilled', 'refunded'], true)) {
            $pdo->rollBack();
            return [409, ['ok' => false, 'error' => 'Unexpected fulfillment state']];
        }
        $pdo->commit();
        return [200, ['ok' => true, 'payment_id' => $paymentId, 'paid' => true, 'fulfillment' => $fulfillment]];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Tronado callback persistence failed: ' . $error->getMessage());
        return [503, ['ok' => false, 'error' => 'Unable to persist callback']];
    }
}

/** Only a queued order can be claimed. Interrupted/failed deliveries are never blindly replayed. */
function tronadoFulfillQueued(PDO $pdo, string $paymentId, callable $deliver, ?callable $afterDelivery = null): bool
{
    $claim = $pdo->prepare("UPDATE Payment_report SET fulfillment_status = 'processing', fulfillment_updated_at = NOW()
        WHERE id_order = ? AND Payment_Method = 'Tronado' AND payment_Status = 'paid' AND fulfillment_status = 'queued'");
    $claim->execute([$paymentId]);
    if ($claim->rowCount() !== 1) { return false; }
    clearSelectCache('Payment_report');
    try {
        $query = $pdo->prepare('SELECT * FROM Payment_report WHERE id_order = ?');
        $query->execute([$paymentId]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        $delivered = $deliver($order);
        $status = $delivered === false ? 'refunded' : 'fulfilled';
        $pdo->prepare('UPDATE Payment_report SET fulfillment_status = ?, fulfillment_updated_at = NOW() WHERE id_order = ?')
            ->execute([$status, $paymentId]);
        clearSelectCache('Payment_report');
    } catch (Throwable $error) {
        // DirectPayment may already have completed before a later notification throws.
        $pdo->prepare("UPDATE Payment_report SET fulfillment_status = 'failed', fulfillment_updated_at = NOW()
            WHERE id_order = ? AND fulfillment_status = 'processing'")->execute([$paymentId]);
        clearSelectCache('Payment_report');
        error_log('Tronado fulfillment failed for ' . $paymentId . ': ' . $error->getMessage());
        return false;
    }
    if ($delivered !== false && $afterDelivery !== null) {
        try { $afterDelivery($order); }
        catch (Throwable $error) {
            error_log('Tronado post-payment notification failed for ' . $paymentId . ': ' . $error->getMessage());
        }
    }
    return true;
}
