<?php

require_once __DIR__ . '/tronado_lib.php';

/** Read-only official API: provider order ID and our PaymentID use different endpoints. */
function tronadoInspectStatus(string $id, bool $byPaymentId, ?callable $request = null): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $id)) { throw new InvalidArgumentException('Invalid order ID'); }
    $apiKey = tronadoSetting('tronado_api_key');
    if ($apiKey === '' || $apiKey === '0') { throw new RuntimeException('Tronado API key is not configured'); }
    $request ??= 'tronadoPost';
    $response = $request('https://bot.tronado.cloud/Order/' . ($byPaymentId ? 'GetStatusByPaymentID' : 'GetStatus'), ['Id' => $id], $apiKey);
    $paymentId = $response['PaymentID'] ?? null;
    if (!is_string($paymentId) || $paymentId === '' || strlen($paymentId) > 500
        || ($byPaymentId && !hash_equals($id, $paymentId))) {
        throw new RuntimeException('Provider returned a missing or mismatched PaymentID');
    }
    return $response;
}

/** This path deliberately has no calls to acceptance, fulfillment or balance updates. */
function tronadoInspectOrder(PDO $pdo, array $status): array
{
    $paymentId = $status['PaymentID'] ?? null;
    if (!is_string($paymentId) || $paymentId === '' || strlen($paymentId) > 500) {
        throw new InvalidArgumentException('Invalid provider PaymentID');
    }
    $query = $pdo->prepare('SELECT * FROM Payment_report WHERE id_order = ? LIMIT 2');
    $query->execute([$paymentId]);
    $orders = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($orders) !== 1 || $orders[0]['Payment_Method'] !== 'Tronado') {
        throw new RuntimeException('Unknown or ambiguous local Tronado payment');
    }
    $order = $orders[0];
    $query = $pdo->prepare('SELECT order_status_id, created_at FROM Tronado_callback WHERE payment_id_hash = ? ORDER BY id');
    $query->execute([hash('sha256', $paymentId)]);
    // The documented status response omits v5 Toman amounts. Never synthesize those or accept it as an IPN.
    $missing = array_values(array_filter(['UserPaidTomanAmount', 'TomanAmountWithoutWage'],
        static fn($key) => !array_key_exists($key, $status)));
    return [
        'read_only' => true, 'payment_id' => $paymentId,
        'provider_status' => is_numeric($status['OrderStatusID'] ?? null) ? (int) $status['OrderStatusID'] : null,
        'provider_is_paid' => filter_var($status['IsPaid'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'local_payment_status' => $order['payment_Status'],
        'local_fulfillment_status' => $order['fulfillment_status'] ?? null,
        'automatic_delivery_allowed' => tronadoAutomaticDeliveryAllowed($order),
        'evidence' => tronadoPaymentMatchEvidence($status, $order),
        'missing_callback_fields' => $missing,
        'callback_match_reason' => $missing ? 'status_response_is_not_a_complete_callback' : tronadoPaidCallbackMismatch($status, $order),
        'stored_callbacks' => $query->fetchAll(PDO::FETCH_ASSOC),
    ];
}
