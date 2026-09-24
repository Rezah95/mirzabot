<?php

/** Stable gateway identifiers; labels must never participate in payment routing. */
function gatewayLabelCallbacks(): array
{
    return [
        'tronado' => 'tronadopay', 'tetraminator' => 'tetraminatorpay', 'zarinpal' => 'zarinpal',
        'tonpays' => 'tonpays', 'uniquepay' => 'uniquepay', 'card' => 'cart_to_offline',
        'plisio' => 'plisio', 'nowpayment' => 'nowpayment', 'iranpay1' => 'iranpay1',
        'iranpay2' => 'iranpay2', 'iranpay4' => 'iranpay4', 'iranpay3' => 'iranpay3',
        'aqayepardakht' => 'aqayepardakht', 'variza' => 'variza', 'digi' => 'digitaltron',
        'star' => 'startelegrams', 'paymentnotverify' => 'paymentnotverify',
    ];
}

function gatewayDefaultLabel(string $key): string
{
    global $textbotlang;
    $legacy = [
        'tronado' => '⚡ ترونادو', 'tonpays' => '💳 TonPays', 'iranpay2' => '💸 CubePay',
        'tetraminator' => (string) getPaySettingValue('tetraminator_label', '💎 تترامیناتور'),
        'uniquepay' => (string) getPaySettingValue('uniquepay_label', 'درگاه پرداخت یونیک‌پی'),
    ];
    $textKeys = ['card' => 'cartToCart', 'zarinpal' => 'zarinPal', 'plisio' => 'nowPayment',
        'nowpayment' => 'cryptoPayment', 'digi' => 'nowPaymentTron', 'iranpay1' => 'iranPay2',
        'iranpay4' => 'iranPay4', 'iranpay3' => 'iranPay1', 'aqayepardakht' => 'aqayePardakht',
        'variza' => 'variza', 'paymentnotverify' => 'paymentNotVerify', 'star' => 'starTelegram'];
    return $legacy[$key] ?? ($textbotlang['textbot'][$textKeys[$key] ?? ''] ?? $key);
}

function gatewayValidLabel(string $label): bool
{
    return preg_match('//u', $label) === 1 && trim($label) !== '' && mb_strlen($label, 'UTF-8') <= 64
        && !preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $label);
}

function gatewayUserLabel(string $key, ?string $fallback = null): string
{
    $fallback = $fallback ?? gatewayDefaultLabel($key);
    if (!isset(gatewayLabelCallbacks()[$key])) { return $fallback; }
    $label = trim((string) getPaySettingValue('gateway_label_' . $key, ''));
    return gatewayValidLabel($label) ? $label : $fallback;
}

function gatewayApplyLabels(array $markup): array
{
    $callbacks = array_flip(gatewayLabelCallbacks());
    foreach ($markup['inline_keyboard'] ?? [] as $row => $buttons) {
        foreach ($buttons as $column => $button) {
            $key = $callbacks[$button['callback_data'] ?? ''] ?? null;
            if ($key !== null) { $markup['inline_keyboard'][$row][$column]['text'] = gatewayUserLabel($key, $button['text']); }
        }
    }
    return $markup;
}

/** Match the original customer menu; newly introduced gateways are appended. */
function gatewayDefaultOrder(): array
{
    $keys = array_keys(gatewayLabelCallbacks());
    $legacy = ['card', 'tronado', 'tetraminator', 'zarinpal', 'tonpays', 'uniquepay', 'plisio', 'nowpayment',
        'digi', 'iranpay1', 'iranpay2', 'iranpay4', 'iranpay3', 'aqayepardakht', 'variza', 'paymentnotverify', 'star'];
    return array_values(array_unique(array_merge(array_intersect($legacy, $keys), $keys)));
}

function gatewayDisplayOrder(): array
{
    $stored = json_decode((string) getPaySettingValue('gateway_display_order', '[]'), true);
    $order = [];
    $known = gatewayLabelCallbacks();
    if (is_array($stored) && array_is_list($stored)) {
        foreach ($stored as $key) {
            if (is_string($key) && isset($known[$key]) && !in_array($key, $order, true)) { $order[] = $key; }
        }
    }
    return array_values(array_unique(array_merge($order, gatewayDefaultOrder())));
}

function gatewayPlaceInOrder(array $order, string $key, int $position): array
{
    $index = array_search($key, $order, true);
    if ($index === false || $position < 1 || $position > count($order)) {
        throw new InvalidArgumentException('Invalid gateway position');
    }
    array_splice($order, $index, 1);
    array_splice($order, $position - 1, 0, [$key]);
    return $order;
}

/** Reorder existing gateway rows only, after the per-user visibility checks. */
function gatewayApplyOrder(array $markup, string $cardUrl = ''): array
{
    $ranks = array_flip(gatewayDisplayOrder());
    $callbacks = array_flip(gatewayLabelCallbacks());
    $slots = $gateways = [];
    foreach ($markup['inline_keyboard'] ?? [] as $index => $row) {
        if (count($row) !== 1) { continue; }
        $button = $row[0];
        $key = $callbacks[$button['callback_data'] ?? ''] ?? null;
        if ($key === null && !isset($button['callback_data']) && $cardUrl !== '' && ($button['url'] ?? null) === $cardUrl) {
            $key = 'card';
        }
        if ($key !== null) {
            $slots[] = $index;
            $gateways[] = ['rank' => $ranks[$key], 'row' => $row];
        }
    }
    usort($gateways, static fn($a, $b) => $a['rank'] <=> $b['rank']);
    foreach ($slots as $index => $slot) { $markup['inline_keyboard'][$slot] = $gateways[$index]['row']; }
    return $markup;
}

/** Prepared upsert also supports databases created before these options existed. Never log credentials. */
function gatewaySaveSetting(PDO $pdo, string $key, string $value): void
{
    $allowed = array_merge(['tonpays_api_key', 'tonpays_min', 'tonpays_max', 'tonpays_status', 'gateway_display_order'],
        array_map(static fn($id) => 'gateway_label_' . $id, array_keys(gatewayLabelCallbacks())));
    if (!in_array($key, $allowed, true)) { throw new InvalidArgumentException('Unknown gateway setting'); }
    $pdo->prepare('INSERT INTO PaySetting (NamePay, ValuePay) VALUES (?, ?) ON DUPLICATE KEY UPDATE ValuePay = VALUES(ValuePay)')
        ->execute([$key, $value]);
    clearSelectCache('PaySetting');
}
