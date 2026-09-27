<?php

chdir(__DIR__);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../payment/tronado_delivery.php';

(static function () use ($pdo): void {
    $pending = $pdo->query("SELECT id_order FROM Payment_report
        WHERE Payment_Method = 'Tronado' AND payment_Status = 'paid' AND fulfillment_status = 'queued'
        ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($pending as $paymentId) {
        tronadoDeliverPayment($pdo, (string) $paymentId);
    }
})();
