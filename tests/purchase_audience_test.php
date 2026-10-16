<?php

// Real recipient SQL; only an isolated MySQL instance is accepted.
require_once dirname(__DIR__) . '/bulk_audience.php';
function expectPurchase(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
foreach (['30' => 30, '۳۰' => 30, '٣٠' => 30, ' 7 ' => 7, '3650' => 3650] as $input => $expected) {
    expectPurchase(bulkParsePurchaseDays($input) === $expected, 'Day parsing');
}
foreach ([null, [], true, 1.5, '0', '-1', '3651', '30-60', '3.5', '1e2', ''] as $input) {
    expectPurchase(bulkParsePurchaseDays($input) === null, 'Invalid day accepted');
    try {
        bulkAudienceQuery(['kind' => 'single_purchase_old', 'purchase_days' => $input]);
        throw new RuntimeException('Invalid criteria accepted');
    } catch (InvalidArgumentException $expected) {}
}
$socket = getenv('AUDIENCE_TEST_SOCKET');
if (!$socket) { echo "Purchase day validation passed; SQL skipped (set AUDIENCE_TEST_SOCKET)\n"; exit; }
expectPurchase(str_starts_with($socket, '/tmp/mirza-audience-test.') && is_file(dirname($socket) . '/mysql.pid'), 'Isolated test MySQL required');
$pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
]);
$db = 'audience_test_' . bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE $db");
try {
    $pdo->exec('CREATE TABLE user (id VARCHAR(200) PRIMARY KEY, Balance INT NOT NULL, agent VARCHAR(100), User_Status VARCHAR(100), last_message_time VARCHAR(200))');
    $pdo->exec('CREATE TABLE invoice (id_user VARCHAR(200), Status VARCHAR(100), name_product VARCHAR(200), time_sell VARCHAR(200))');
    $pdo->exec('CREATE TABLE service_other (id_user VARCHAR(200), type VARCHAR(100), status VARCHAR(100), time VARCHAR(200))');
    $now = time();
    $cutoff = $now - 30 * 86400;
    $addUser = $pdo->prepare('INSERT INTO user VALUES (?, ?, ?, ?, ?)');
    for ($id = 1; $id <= 24; $id++) {
        $addUser->execute([(string) $id, $id === 2 ? 100 : ($id === 3 ? -100 : 0), $id === 14 ? 'n' : 'f', $id === 15 ? 'block' : 'Active', $now]);
    }
    $add = $pdo->prepare('INSERT INTO invoice VALUES (?, ?, ?, ?)');
    foreach ([
        [4, 'unpaid', 'Plan', $cutoff - 1], [5, 'Unsuccessful', 'Plan', $cutoff - 1],
        [6, 'active', 'سرویس تست', $cutoff - 1], [7, 'active', 'Plan', $cutoff - 86400],
        [8, 'active', 'Plan', $cutoff], [9, 'active', 'Plan', $cutoff + 1],
        [10, 'active', 'Plan', $cutoff - 86400], [10, 'active', 'Plan', $cutoff + 1],
        [11, 'active', 'Plan', $cutoff - 86400], [12, 'active', 'Plan', $cutoff - 86400],
        [13, 'removebyadmin', 'Plan', $cutoff - 86400], [14, 'end_of_volume', 'Plan', $cutoff - 86400],
        [15, 'active', 'Plan', $cutoff - 86400], [16, 'active', 'Test service', $cutoff - 1],
        [16, 'active', 'Тестовый сервис', $cutoff - 1], [16, 'active', '测试服务', $cutoff - 1],
        [17, 'waiting', 'Plan', $cutoff - 1], [18, 'reject', 'Plan', $cutoff - 1],
        [19, 'active', 'Plan', 'unknown'], [20, 'active', 'Plan', 0],
        [22, 'active', 'Plan', $cutoff - 86400], [22, 'unpaid', 'Plan', $now],
        [23, 'active', 'Plan', $cutoff - 86400], [23, 'active', 'سرویس تست', $now],
    ] as $row) { $add->execute($row); }
    $renew = $pdo->prepare('INSERT INTO service_other VALUES (?, ?, ?, ?)');
    foreach ([[11, 'extend_user', 'paid'], [12, 'extend_user', 'unpaid'],
        [21, 'extend_user', 'paid'], [22, 'extend_user_by_admin', 'paid'],
        [23, 'buyvolume', 'paid'], [24, 'extend_user', 'paid']] as [$id, $type, $status]) {
        $renew->execute([$id, $type, $status, $id === 24 ? 'unknown' : date('Y/m/d H:i:s', $cutoff - 86400)]);
    }
    $run = static function (array $criteria, bool $active = true) use ($pdo, $now): array {
        [$sql, $params] = bulkAudienceQuery($criteria, $active, $now);
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)); sort($ids); return $ids;
    };
    $empty = ['kind' => 'no_purchase_empty_wallet', 'agent' => 'all'];
    expectPurchase($run($empty) === [1, 4, 5, 6, 16, 17, 18], 'Zero-balance nonbuyer selection');
    $single = ['kind' => 'single_purchase_old', 'agent' => 'all', 'purchase_days' => 30];
    expectPurchase($run($single) === [7, 12, 13, 14, 21, 22, 23], 'Single purchase count, strict cutoff, trial or renewal selection');
    expectPurchase($run($single, false) === [7, 12, 13, 14, 15, 21, 22, 23], 'Bulk-credit active policy changed');
    expectPurchase($run(array_replace($single, ['agent' => 'n'])) === [14], 'User group ignored');
    expectPurchase($run($single + ['inactive_before' => $now - 1]) === [], 'Explicit inactivity ignored');
    expectPurchase(bulkAudienceCount($pdo, $empty) === count(bulkAudienceIds($pdo, $empty)), 'Preview and send disagree');
    // Re-select at confirmation: a newly purchased service must remove a previewed nonbuyer.
    $add->execute([1, 'active', 'Plan', $now]);
    expectPurchase(!in_array('1', bulkAudienceIds($pdo, $empty), true), 'Confirmation used stale recipients');
    echo "Purchase audience SQL: wallet, purchase/renewal counts, trials, failed orders, boundary, groups and re-selection passed\n";
} finally { $pdo->exec("DROP DATABASE $db"); }
