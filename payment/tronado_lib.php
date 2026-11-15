<?php

require_once __DIR__ . '/../report_events.php';

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

/** Same acceptance policy as before; a reason makes intermittent rejections diagnosable. */
function tronadoPaidCallbackMismatch(array $callback, array $paymentReport): ?string
{
    $metadata = json_decode((string) ($paymentReport['dec_not_confirmed'] ?? ''), true);
    if (!is_array($metadata)) { return 'invalid_order_metadata'; }
    $amount = $callback['UserPaidTomanAmount'] ?? null;
    $received = $callback['TomanAmountWithoutWage'] ?? null;
    $deliveredTron = $callback['TronAmount'] ?? null;
    if (!is_scalar($amount) || !ctype_digit((string) $amount)) { return 'invalid_paid_amount'; }
    if ((int) $amount < max(1, (int) ($paymentReport['price'] ?? 0) - 5000)) { return 'paid_amount_below_minimum'; }
    if (!is_scalar($received) || !ctype_digit((string) $received) || (int) $received <= 0) { return 'invalid_received_amount'; }
    if (!is_numeric($deliveredTron) || !((float) $deliveredTron > 0)) { return 'invalid_delivered_tron'; }
    if (($metadata['wage_from_business_percentage'] ?? null) === 100) {
        $expectedTron = $metadata['tron_amount'] ?? null;
        $wallet = $metadata['wallet'] ?? null;
        if (!is_numeric($expectedTron) || !((float) $expectedTron > 0)) { return 'invalid_expected_tron'; }
        if (!is_string($wallet) || $wallet === '') { return 'missing_order_wallet'; }
        return is_string($callback['Wallet'] ?? null) && hash_equals($wallet, $callback['Wallet']) ? null : 'wallet_mismatch';
    }
    // Preserve the earlier official Tronado order/token and wallet contract.
    $legacyToken = strtolower(str_replace('-', '', (string) ($metadata['Token'] ?? '')));
    $callbackToken = strtolower(str_replace('-', '', (string) ($callback['UniqueCode'] ?? '')));
    $legacyWallet = tronadoSetting('walletaddress');
    if (strlen($legacyToken) < 8 || !hash_equals($legacyToken, $callbackToken)) { return 'legacy_token_mismatch'; }
    if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $legacyWallet) !== 1) { return 'invalid_legacy_wallet'; }
    return is_string($callback['Wallet'] ?? null) && hash_equals($legacyWallet, $callback['Wallet']) ? null : 'wallet_mismatch';
}

function tronadoPaidCallbackMatchesOrder(array $callback, array $paymentReport): bool
{
    return tronadoPaidCallbackMismatch($callback, $paymentReport) === null;
}

/** Allowlisted evidence only: no wallet addresses, tokens, signatures or raw payload. */
function tronadoPaymentMatchEvidence(array $callback, array $order): array
{
    $metadata = json_decode((string) ($order['dec_not_confirmed'] ?? ''), true);
    $number = static fn($value) => is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    $wallet = ($metadata['wage_from_business_percentage'] ?? null) === 100
        ? ($metadata['wallet'] ?? null) : tronadoSetting('walletaddress');
    return [
        'invoice_toman' => $number($order['price'] ?? null),
        'minimum_paid_toman' => max(1, (int) ($order['price'] ?? 0) - 5000),
        'user_paid_toman' => $number($callback['UserPaidTomanAmount'] ?? null),
        'received_toman' => $number($callback['TomanAmountWithoutWage'] ?? null),
        'delivered_tron' => $number($callback['TronAmount'] ?? null),
        'expected_tron' => $number($metadata['tron_amount'] ?? null),
        'wallet_matches' => is_string($wallet) && $wallet !== '' && is_string($callback['Wallet'] ?? null)
            ? hash_equals($wallet, $callback['Wallet']) : null,
    ];
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


/** Old invoices may already have been credited manually during the callback outage. */
function tronadoAutomaticDeliveryAllowed(array $order): bool
{
    $metadata = json_decode((string) ($order['dec_not_confirmed'] ?? ''), true);
    return is_array($metadata) && ($metadata['automatic_delivery'] ?? null) === 2;
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
            return [404, ['ok' => false, 'payment_id' => $paymentId, 'paid' => $accepted, 'error' => 'Unknown or ambiguous payment']];
        }
        $order = $orders[0];
        $mismatch = $accepted ? tronadoPaidCallbackMismatch($callback, $order) : null;
        if ($mismatch !== null) {
            $pdo->rollBack();
            error_log('Tronado callback mismatch: ' . json_encode(['payment_id' => $paymentId, 'reason' => $mismatch]
                + tronadoPaymentMatchEvidence($callback, $order), JSON_INVALID_UTF8_SUBSTITUTE));
            return [409, ['ok' => false, 'payment_id' => $paymentId,
                'error' => 'Amount or wallet does not match order', 'reason' => $mismatch]];
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
        if ($order['payment_Status'] !== 'reject' && in_array($fulfillment, ['', 'queued', 'review'], true)
            && !tronadoAutomaticDeliveryAllowed($order)) {
            $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid', fulfillment_status = 'review', fulfillment_updated_at = NOW() WHERE id = ?")
                ->execute([$order['id']]);
            $pdo->commit();
            return [200, ['ok' => true, 'payment_id' => $paymentId, 'paid' => true, 'fulfillment' => 'review']];
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
        return [503, ['ok' => false, 'payment_id' => $paymentId, 'reason' => 'storage_error', 'error' => 'Unable to persist callback']];
    }
}

/** Called after sending the HTTP reply; invalid signatures/probes never create channel noise. */
function tronadoQueueCallbackReport(int $status, array $body, array $callback = []): void
{
    if (empty($body['payment_id']) || !in_array($status, [200, 404, 409, 503], true)) { return; }
    $event = !empty($body['cancelled']) ? 'payment_cancelled'
        : (($body['fulfillment'] ?? '') === 'review' ? 'payment_review'
            : ($status === 409 ? (isset($body['reason']) ? 'payment_mismatch' : 'payment_review')
                : ($status === 503 ? 'callback_storage_failed' : ($status === 404 && !empty($body['paid']) ? 'payment_review' : null))));
    if ($event === null) { return; }
    if (!empty($body['cancelled'])) { error_log('Tronado paid order cancelled; manual reconciliation required: ' . $body['payment_id']); }
    try {
        $order = select('Payment_report', '*', 'id_order', $body['payment_id'], 'select') ?: [];
        $details = $event === 'payment_mismatch' ? json_encode(tronadoPaymentMatchEvidence($callback, $order)) : '';
        reportEventQueue($event, ['gateway' => 'Tronado', 'order' => $body['payment_id'], 'user' => $order['id_user'] ?? '-',
            'reason' => $body['reason'] ?? ($event === 'payment_cancelled' ? 'cancelled' : 'requires_reconciliation'), 'details' => $details],
            $body['payment_id'] . ':' . ($body['reason'] ?? ''));
    } catch (Throwable $e) { error_log('Tronado callback report queue failed: ' . get_class($e)); }
}

/** Only a queued order can be claimed. Interrupted/failed deliveries are never blindly replayed. */
function tronadoFulfillQueued(PDO $pdo, string $paymentId, callable $deliver, ?callable $afterDelivery = null): bool
{
    // Check stored metadata before claiming, including queues left by older releases.
    $query = $pdo->prepare('SELECT * FROM Payment_report WHERE id_order = ?');
    $query->execute([$paymentId]);
    $candidate = $query->fetch(PDO::FETCH_ASSOC);
    if (!$candidate || $candidate['Payment_Method'] !== 'Tronado') { return false; }
    if (!tronadoAutomaticDeliveryAllowed($candidate)) {
        $pdo->prepare("UPDATE Payment_report SET fulfillment_status = 'review', fulfillment_updated_at = NOW()
            WHERE id_order = ? AND Payment_Method = 'Tronado' AND payment_Status = 'paid' AND fulfillment_status = 'queued'")
            ->execute([$paymentId]);
        clearSelectCache('Payment_report');
        error_log('Tronado historical payment requires manual reconciliation: ' . $paymentId);
        reportEventQueue('payment_review', ['gateway' => 'Tronado', 'order' => $paymentId, 'user' => $candidate['id_user'], 'reason' => 'requires_reconciliation'], $paymentId . ':');
        return false;
    }
    $claim = $pdo->prepare("UPDATE Payment_report SET fulfillment_status = 'processing', fulfillment_updated_at = NOW()
        WHERE id_order = ? AND Payment_Method = 'Tronado' AND payment_Status = 'paid' AND fulfillment_status = 'queued'");
    $claim->execute([$paymentId]);
    if ($claim->rowCount() !== 1) { return false; }
    clearSelectCache('Payment_report');
    error_log('Tronado delivery started: ' . $paymentId);
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
        reportEventQueue('payment_failed', ['gateway' => 'Tronado', 'order' => $paymentId, 'user' => $candidate['id_user'], 'reason' => get_class($error)], $paymentId);
        return false;
    }
    error_log('Tronado delivery result: ' . $paymentId . ' ' . $status);
    if ($status === 'refunded') { reportEventQueue('payment_refunded', ['gateway' => 'Tronado', 'order' => $paymentId, 'user' => $candidate['id_user'], 'reason' => 'refunded'], $paymentId, 'paymentreport'); }
    if ($delivered !== false && $afterDelivery !== null) {
        try { $afterDelivery($order); }
        catch (Throwable $error) {
            error_log('Tronado post-payment notification failed for ' . $paymentId . ': ' . $error->getMessage());
            reportEventQueue('payment_notice_failed', ['gateway' => 'Tronado', 'order' => $paymentId, 'user' => $order['id_user'], 'reason' => get_class($error)], $paymentId);
        }
    }
    return true;
}

/** Read-only admin diagnostics: never expose raw signed payloads or credentials. */
function tronadoDiagnostics(PDO $pdo): string
{
    $lines = ['وضعیت ترونادو', 'PHP: ' . PHP_VERSION . ' / ' . PHP_SAPI];
    $last = $pdo->query('SELECT MAX(created_at) FROM Tronado_callback')->fetchColumn();
    $lines[] = 'آخرین کال‌بک معتبر ذخیره‌شده: ' . ($last ?: 'ثبت نشده');
    $rows = $pdo->query("SELECT p.id_order, p.payment_Status, p.fulfillment_status,
        (SELECT COUNT(*) FROM Tronado_callback c WHERE c.payment_id_hash = SHA2(p.id_order, 256)) AS callbacks
        FROM Payment_report p WHERE p.Payment_Method = 'Tronado' ORDER BY p.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $lines[] = $row['id_order'] . ' | ' . $row['payment_Status'] . ' | '
            . ($row['fulfillment_status'] ?: 'بدون تحویل') . ' | کال‌بک: ' . $row['callbacks'];
    }
    if (!$rows) { $lines[] = 'سفارشی ثبت نشده است.'; }
    $lines[] = 'queued: در صف | processing: در حال پردازش | fulfilled: انجام شد | refunded: بازگشت به کیف پول | failed: خطا | review: بررسی دستی';
    $lines[] = 'فاکتورهای قبل از این اصلاح خودکار اجرا نمی‌شوند؛ ابتدا شارژ دستی قبلی را تطبیق دهید.';
    $lines[] = 'لاگ دریافت و تحویل: payment/error_log';
    return implode("\n", $lines);
}
