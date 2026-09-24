<?php

function bulkCreditEnsureTables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS bulk_credit_batch (
        id CHAR(24) PRIMARY KEY,
        admin_id VARCHAR(200) NOT NULL,
        amount INT UNSIGNED NOT NULL,
        recipient_count INT UNSIGNED NOT NULL,
        notify TINYINT(1) NOT NULL DEFAULT 0,
        notification_status VARCHAR(20) NOT NULL DEFAULT 'none',
        admin_message_id VARCHAR(100) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at BIGINT UNSIGNED NOT NULL,
        applied_at BIGINT UNSIGNED NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bulk_credit_recipient (
        batch_id CHAR(24) NOT NULL,
        user_id VARCHAR(500) NOT NULL,
        PRIMARY KEY (batch_id, user_id),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['notification_status' => "VARCHAR(20) NOT NULL DEFAULT 'none'", 'admin_message_id' => 'VARCHAR(100) NULL'] as $column => $type) {
        if ($pdo->query("SHOW COLUMNS FROM bulk_credit_batch LIKE '$column'")->fetch() === false) {
            $pdo->exec("ALTER TABLE bulk_credit_batch ADD $column $type");
        }
    }
    $ready = true;
}

function bulkCreditPrepare(PDO $pdo, string $adminId, int $amount, array $userIds, bool $notify): string
{
    if ($amount < 1 || $amount > 100000000 || !$userIds) {
        throw new InvalidArgumentException('Invalid bulk credit request');
    }
    bulkCreditEnsureTables($pdo);
    $ids = array_values(array_unique(array_map('strval', $userIds)));
    $id = bin2hex(random_bytes(12));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO bulk_credit_batch (id, admin_id, amount, recipient_count, notify, notification_status, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $adminId, $amount, count($ids), (int) $notify, $notify ? 'pending' : 'none', 'pending', time()]);
        foreach (array_chunk($ids, 400) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?)'));
            $values = [];
            foreach ($chunk as $userId) {
                $values[] = $id;
                $values[] = $userId;
            }
            $pdo->prepare('INSERT INTO bulk_credit_recipient (batch_id, user_id) VALUES ' . $placeholders)
                ->execute($values);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $id;
}

function bulkCreditApply(PDO $pdo, string $id, string $adminId): array
{
    bulkCreditEnsureTables($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM bulk_credit_batch WHERE id = ? AND admin_id = ? FOR UPDATE');
        $stmt->execute([$id, $adminId]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch || $batch['status'] !== 'pending' || (int) $batch['created_at'] < time() - 900) {
            $pdo->rollBack();
            return [];
        }
        $amount = (int) $batch['amount'];
        $update = $pdo->prepare('UPDATE user u INNER JOIN bulk_credit_recipient r ON r.user_id = u.id SET u.Balance = u.Balance + ? WHERE r.batch_id = ? AND u.Balance <= ?');
        $update->execute([$amount, $id, 2147483647 - $amount]);
        if ($update->rowCount() !== (int) $batch['recipient_count']) {
            throw new RuntimeException('Recipients changed or a balance would overflow');
        }
        $pdo->prepare("UPDATE bulk_credit_batch SET status = 'applied', applied_at = ? WHERE id = ?")
            ->execute([time(), $id]);
        $pdo->commit();
        if (function_exists('clearSelectCache')) {
            clearSelectCache('user');
        }
        return $batch;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function bulkCreditCancel(PDO $pdo, string $id, string $adminId): bool
{
    bulkCreditEnsureTables($pdo);
    $stmt = $pdo->prepare("UPDATE bulk_credit_batch SET status = 'cancelled' WHERE id = ? AND admin_id = ? AND status = 'pending'");
    $stmt->execute([$id, $adminId]);
    return $stmt->rowCount() === 1;
}

function bulkCreditRecipients(PDO $pdo, string $id): array
{
    $stmt = $pdo->prepare('SELECT user_id FROM bulk_credit_recipient WHERE batch_id = ?');
    $stmt->execute([$id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function bulkCreditSetMessage(PDO $pdo, string $id, string $adminId, string $messageId): void
{
    $pdo->prepare('UPDATE bulk_credit_batch SET admin_message_id = ? WHERE id = ? AND admin_id = ? AND status = ?')
        ->execute([$messageId, $id, $adminId, 'pending']);
}

function bulkCreditScheduleNotification(PDO $pdo, string $id, string $notice): bool
{
    require_once __DIR__ . '/bulk_queue.php';
    $stmt = $pdo->prepare("SELECT * FROM bulk_credit_batch WHERE id = ? AND status = 'applied' AND notify = 1 AND notification_status = 'pending'");
    $stmt->execute([$id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$batch) {
        return false;
    }
    $jobId = bulkQueueStart('credit:' . $id, bulkCreditRecipients($pdo, $id), [
        'id_admin' => $batch['admin_id'],
        'id_message' => $batch['admin_message_id'],
        'type' => 'sendmessage',
        'message' => $notice,
        'pingmessage' => 'no',
        'btnmessage' => 'start',
    ]);
    if ($jobId === null) {
        $existing = bulkQueueSnapshot('credit:' . $id);
        if ($existing === null) {
            return false;
        }
    }
    $pdo->prepare("UPDATE bulk_credit_batch SET notification_status = 'queued' WHERE id = ? AND notification_status = 'pending'")
        ->execute([$id]);
    return true;
}
