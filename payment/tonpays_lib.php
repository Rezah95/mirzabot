<?php

function tonpaysCredentialsReady(): bool
{
    $key = trim((string) getPaySettingValue('tonpays_api_key', ''));
    return $key !== '' && $key !== '0' && strlen($key) <= 512 && !preg_match('/[\s\x00-\x1f\x7f]/', $key);
}

function tonpaysConfigured(): bool
{
    return getPaySettingValue('tonpays_status', 'offtonpays') === 'ontonpays' && tonpaysCredentialsReady();
}

function tonpaysPositiveInt($value): ?int
{
    if (!is_int($value) && !(is_string($value) && ctype_digit($value))) { return null; }
    $number = filter_var($value, FILTER_VALIDATE_INT);
    return $number !== false && $number > 0 ? $number : null;
}

function tonpaysHttpsUrl($value): bool
{
    return is_string($value) && filter_var($value, FILTER_VALIDATE_URL)
        && parse_url($value, PHP_URL_SCHEME) === 'https'
        && parse_url($value, PHP_URL_USER) === null && parse_url($value, PHP_URL_PASS) === null;
}

function tonpaysRequest(string $method, string $path, ?array $payload = null, string $baseUrl = 'https://tonpays.online'): array
{
    if (!tonpaysCredentialsReady()) { throw new RuntimeException('TonPays API key is not configured'); }
    $ch = curl_init(rtrim($baseUrl, '/') . $path);
    $headers = ['Accept: application/json', 'X-API-Key: ' . trim((string) getPaySettingValue('tonpays_api_key'))];
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('TonPays API request failed (HTTP ' . $status . ')');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) { throw new RuntimeException('TonPays API returned invalid JSON'); }
    return $data;
}

function tonpaysCreateOrder(string $orderId, int $amount, string $buyerId, string $domain, string $baseUrl = 'https://tonpays.online'): array
{
    $callback = 'https://' . rtrim($domain, '/') . '/payment/tonpays.php';
    if (!preg_match('/^[a-f0-9]{10,20}$/', $orderId) || $amount < 1
        || !preg_match('/^[1-9][0-9]{0,19}$/', $buyerId) || !tonpaysHttpsUrl($callback)) {
        throw new InvalidArgumentException('Invalid TonPays order');
    }
    $data = tonpaysRequest('POST', '/api/v1/invoices/create', [
        'amount' => $amount, 'order_id' => $orderId, 'buyer_chat_id' => $buyerId, 'callback_url' => $callback,
    ], $baseUrl);
    if (!is_string($data['invoice_id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,200}$/', $data['invoice_id'])
        || ($data['order_id'] ?? null) !== $orderId || tonpaysPositiveInt($data['request_amount'] ?? null) !== $amount
        || tonpaysPositiveInt($data['final_amount'] ?? null) === null) {
        throw new RuntimeException('TonPays invoice does not match the requested order');
    }
    $url = $data['payment_url'] ?? $data['invoice_url'] ?? null;
    if (!tonpaysHttpsUrl($url)) { throw new RuntimeException('TonPays payment URL is invalid'); }
    return [
        'payment_url' => $url,
        'metadata' => ['invoice_id' => $data['invoice_id'], 'request_amount' => $amount,
            'final_amount' => tonpaysPositiveInt($data['final_amount'])],
    ];
}

function tonpaysCheckInvoice(string $invoiceId, string $baseUrl = 'https://tonpays.online'): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,200}$/', $invoiceId)) { throw new InvalidArgumentException('Invalid TonPays invoice ID'); }
    return tonpaysRequest('GET', '/api/v1/invoices/check/' . rawurlencode($invoiceId), null, $baseUrl);
}

function tonpaysInvoiceMatchesOrder(array $verified, array $order, array $metadata): bool
{
    return ($verified['invoice_id'] ?? null) === ($metadata['invoice_id'] ?? null)
        && ($verified['order_id'] ?? null) === $order['id_order']
        && tonpaysPositiveInt($order['price']) !== null
        && tonpaysPositiveInt($verified['request_amount'] ?? null) === tonpaysPositiveInt($order['price'])
        && tonpaysPositiveInt($metadata['request_amount'] ?? null) === tonpaysPositiveInt($order['price'])
        && tonpaysPositiveInt($verified['final_amount'] ?? null) !== null
        && tonpaysPositiveInt($verified['final_amount']) === tonpaysPositiveInt($metadata['final_amount'] ?? null)
        && is_bool($verified['paid'] ?? null) && is_string($verified['status'] ?? null);
}

/** Verify first, then atomically reserve delivery. The callback's paid flag never credits a wallet. */
function tonpaysProcessCallback(PDO $pdo, array $callback, string $providedKey, string $expectedKey, callable $check, callable $deliver): array
{
    if ($expectedKey === '' || $expectedKey === '0') { return [503, ['ok' => false]]; }
    if (!hash_equals($expectedKey, $providedKey)) { return [401, ['ok' => false]]; }
    $orderId = $callback['order_id'] ?? null;
    $invoiceId = $callback['invoice_id'] ?? null;
    if (!is_string($orderId) || !preg_match('/^[a-f0-9]{10,20}$/', $orderId)
        || !is_string($invoiceId) || !preg_match('/^[A-Za-z0-9_-]{1,200}$/', $invoiceId)) {
        return [400, ['ok' => false]];
    }
    $query = $pdo->prepare('SELECT * FROM Payment_report WHERE id_order = ?');
    $query->execute([$orderId]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1 || $rows[0]['Payment_Method'] !== 'TonPays') { return [404, ['ok' => false]]; }
    $order = $rows[0];
    $metadata = json_decode((string) ($order['dec_not_confirmed'] ?? ''), true);
    if (!is_array($metadata) || !is_string($metadata['invoice_id'] ?? null)) { return [503, ['ok' => false]]; }
    if ($metadata['invoice_id'] !== $invoiceId) { return [409, ['ok' => false]]; }
    if ($order['payment_Status'] === 'paid') {
        return in_array($order['fulfillment_status'] ?? null, ['fulfilled', 'refunded'], true)
            ? [200, ['ok' => true, 'paid' => true]] : [503, ['ok' => false, 'error' => 'Payment requires reconciliation']];
    }
    try {
        $verified = $check($invoiceId);
    } catch (Throwable $e) {
        error_log('TonPays inquiry failed for order ' . $orderId);
        return [502, ['ok' => false]];
    }
    if (!is_array($verified) || !tonpaysInvoiceMatchesOrder($verified, $order, $metadata)) {
        error_log('TonPays invoice mismatch for order ' . $orderId);
        return [409, ['ok' => false]];
    }
    if ($verified['paid'] !== true) { return [200, ['ok' => true, 'paid' => false]]; }
    $claim = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid', fulfillment_status = 'processing', fulfillment_updated_at = NOW()
        WHERE id_order = ? AND Payment_Method = 'TonPays' AND payment_Status NOT IN ('paid', 'reject')
        AND (fulfillment_status IS NULL OR fulfillment_status = '')");
    $claim->execute([$orderId]);
    if ($claim->rowCount() !== 1) { return [503, ['ok' => false, 'error' => 'Payment requires reconciliation']]; }
    if (function_exists('clearSelectCache')) { clearSelectCache('Payment_report'); }
    try {
        $delivered = $deliver($order);
        $status = $delivered === false ? 'refunded' : 'fulfilled';
        $pdo->prepare('UPDATE Payment_report SET fulfillment_status = ?, fulfillment_updated_at = NOW() WHERE id_order = ?')->execute([$status, $orderId]);
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE Payment_report SET fulfillment_status = 'failed', fulfillment_updated_at = NOW() WHERE id_order = ?")->execute([$orderId]);
        error_log('TonPays delivery failed for order ' . $orderId);
        return [500, ['ok' => false, 'error' => 'Payment requires reconciliation']];
    }
    return [200, ['ok' => true, 'paid' => true]];
}
