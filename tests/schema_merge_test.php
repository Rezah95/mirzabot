<?php
// Schema checks run only against an explicitly isolated MySQL socket.
require_once dirname(__DIR__) . '/db/Schema.php';
function assertSqlIdentifier($name): void { if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) { throw new RuntimeException('Invalid identifier'); } }
function schemaCheck($ok, $message): void { if (!$ok) { throw new RuntimeException($message); } }
$source = file_get_contents(dirname(__DIR__) . '/function.php');
$start = strpos($source, 'function ensureTableUtf8mb4(');
$end = strpos($source, 'function ensureCardNumberTableSupportsUnicode(', $start);
eval(substr($source, $start, $end - $start));
$socket = getenv('MERGE_TEST_SOCKET');
schemaCheck(is_string($socket) && str_starts_with($socket, '/tmp/mirza-audience-test.') && is_file(dirname($socket) . '/mysql.pid'), 'An isolated test MySQL is required');
$pdo = new PDO('mysql:unix_socket=' . $socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (['fresh', 'legacy'] as $scenario) {
    $db = 'merge_schema_' . bin2hex(random_bytes(5));
    $pdo->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $pdo->exec("USE $db");
        if ($scenario === 'legacy') {
            $legacy = require dirname(__DIR__) . '/db/tables/Payment_report.php';
            $body = str_replace(['id_order varchar(80)', 'uniq_payment_order (id_order)'], ['id_order varchar(200)', 'uniq_payment_order (id_order(191))'], $legacy['create']);
            $pdo->exec('CREATE TABLE Payment_report (' . $body . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec("INSERT INTO Payment_report (id,id_order) VALUES (1, 'legacy')");
        }
        for ($i = 0; $i < 2; $i++) {
            $schema = new Schema($pdo);
            foreach (['user', 'Payment_report', 'invoice', 'service_other', 'Giftcodeconsumed', 'DiscountSell', 'Discount'] as $table) {
                $schema->apply($table, require dirname(__DIR__) . '/db/tables/' . $table . '.php');
            }
            foreach (['013_id_payment_link', '013_unique_payment_orders', '014_id_payment_legacy_backfill'] as $migration) {
                (require dirname(__DIR__) . '/db/migrations/' . $migration . '.php')($pdo, $schema);
            }
            $schema->applyIndexes(require dirname(__DIR__) . '/db/indexes.php');
        }
        $pdo->exec("INSERT INTO Payment_report (id,id_order) VALUES (2, 'linked')");
        $pdo->exec("INSERT INTO invoice (id_invoice,id_payment,notifctions) VALUES ('inv', 'linked', '{}')");
        $pdo->exec("DELETE FROM Payment_report WHERE id_order = 'linked'");
        schemaCheck($pdo->query("SELECT id_payment FROM invoice WHERE id_invoice = 'inv'")->fetchColumn() === null, 'Foreign-key delete did not preserve invoice');
        $pdo->exec("INSERT INTO Payment_report (id,id_order) VALUES (3,'unique')");
        $rejected = false;
        try { $pdo->exec("INSERT INTO Payment_report (id,id_order) VALUES (4,'unique')"); } catch (PDOException $e) { $rejected = $e->getCode() === '23000'; }
        schemaCheck($rejected, 'Duplicate payment accepted');
        if ($scenario === 'legacy') { schemaCheck($pdo->query("SELECT id_order FROM Payment_report WHERE id=1")->fetchColumn() === 'legacy', 'Legacy data lost'); }
    } finally { $pdo->exec("DROP DATABASE $db"); }
}
echo "Merge schema: fresh/legacy, repeated migrations, unique orders and foreign keys passed\n";
