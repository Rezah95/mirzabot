<?php

// Never expose a credentialed inspection endpoint over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['provider-order:', 'payment-id:', 'help']);
if (isset($options['help']) || count($options) !== 1
    || (!isset($options['provider-order']) && !isset($options['payment-id']))) {
    fwrite(STDERR, "Usage: php tools/tronado_diagnose.php --provider-order 3181762\n"
        . "   or: php tools/tronado_diagnose.php --payment-id YOUR_INTERNAL_PAYMENT_ID\n"
        . "Read-only inspection; no balance updates or payment delivery.\n");
    exit(isset($options['help']) ? 0 : 2);
}
$id = $options['payment-id'] ?? $options['provider-order'];
if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $id)) {
    fwrite(STDERR, "Invalid order ID\n"); exit(2);
}
chdir(dirname(__DIR__));
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../payment/tronado_inspection.php';
try {
    $status = tronadoInspectStatus($id, isset($options['payment-id']));
    echo json_encode(tronadoInspectOrder($pdo, $status), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    // Provider/DB exception text can contain secrets or SQL: keep raw errors out of terminal output.
    fwrite(STDERR, "Inspection failed (" . get_class($e) . "). Check API credentials, order ID and database availability.\n");
    exit(1);
}
