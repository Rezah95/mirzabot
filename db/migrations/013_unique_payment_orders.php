<?php

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('Payment_report')) {
        return;
    }

    $duplicate = $pdo->query(
        'SELECT LEFT(id_order, 191) AS order_prefix FROM Payment_report '
        . 'WHERE id_order IS NOT NULL GROUP BY order_prefix HAVING COUNT(*) > 1 LIMIT 1'
    )->fetchColumn();
    if ($duplicate !== false) {
        error_log('Payment_report has duplicate order IDs or prefixes; unique index requires manual reconciliation');
        return;
    }

    $index = $pdo->query("SHOW INDEX FROM Payment_report WHERE Key_name = 'uniq_payment_order'")->fetch();
    if (!$index) {
        $pdo->exec('ALTER TABLE Payment_report ADD UNIQUE KEY uniq_payment_order (id_order(191))');
    }
};
