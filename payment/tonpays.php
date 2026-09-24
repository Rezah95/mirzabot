<?php

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
$raw = file_get_contents('php://input', false, null, 0, 65537);
$callback = is_string($raw) && strlen($raw) <= 65536 ? json_decode($raw, true) : null;
if (!is_array($callback)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'botapi.php';
require_once 'function.php';
$expectedKey = trim((string) getPaySettingValue('tonpays_api_key', ''));
$providedKey = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($expectedKey === '' || $expectedKey === '0' || !hash_equals($expectedKey, $providedKey)) {
    http_response_code($expectedKey === '' || $expectedKey === '0' ? 503 : 401);
    echo json_encode(['ok' => false]);
    exit;
}
// Build the shared fulfillment keyboards in the paying user's language.
$from_id = null;
$message_id = 0;
if (is_string($callback['order_id'] ?? null) && preg_match('/^[a-f0-9]{10,20}$/', $callback['order_id'])) {
    $query = $pdo->prepare("SELECT id_user FROM Payment_report WHERE id_order = ? AND Payment_Method = 'TonPays'");
    $query->execute([$callback['order_id']]);
    $from_id = $query->fetchColumn() ?: null;
}
require_once 'panels.php';
require_once 'jdf.php';
require_once 'keyboard.php';
require_once __DIR__ . '/tonpays_lib.php';
$ManagePanel = new ManagePanel();
[$status, $body] = tonpaysProcessCallback($pdo, $callback,
    $providedKey, $expectedKey,
    'tonpaysCheckInvoice',
    static function (array $order) {
        global $from_id, $textbotlang;
        $from_id = $order['id_user'];
        $textbotlang = languagechange();
        return DirectPayment($order['id_order'], 'images.jpg');
    });
http_response_code($status);
echo json_encode($body);
