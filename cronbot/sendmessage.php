<?php
chdir(__DIR__);
date_default_timezone_set('Asia/Tehran');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../bulk_queue.php';
require_once __DIR__ . '/../bulk_credit.php';
$textbotlang = languagechange();
$workerLock = fopen(__DIR__ . '/.bulk-worker.lock', 'c+');
if ($workerLock === false || !flock($workerLock, LOCK_EX | LOCK_NB)) {
    exit;
}

function bulkSendToRecipient(array $info, string $userId): array
{
    $type = $info['type'] ?? '';
    if ($type === 'unpinmessage') {
        return unpinmessage($userId);
    }
    if ($type === 'forwardmessage') {
        return forwardMessage($info['id_admin'], $info['message'], $userId);
    }
    if (!in_array($type, ['sendmessage', 'xdaynotmessage'], true)) {
        return ['ok' => false, 'description' => 'Unknown bulk message type'];
    }
    $buttons = [
        'none' => null,
        'buy' => [['text' => $GLOBALS['textbotlang']['textbot']['sell'], 'callback_data' => 'buy']],
        'start' => [['text' => $GLOBALS['textbotlang']['keyboard']['start'], 'callback_data' => 'start']],
        'usertestbtn' => [['text' => $GLOBALS['textbotlang']['textbot']['userTest'], 'callback_data' => 'usertestbtn']],
        'helpbtn' => [['text' => $GLOBALS['textbotlang']['textbot']['help'], 'callback_data' => 'helpbtn']],
        'affiliatesbtn' => [['text' => $GLOBALS['textbotlang']['textbot']['affiliates'], 'callback_data' => 'affiliatesbtn']],
        'addbalance' => [['text' => $GLOBALS['textbotlang']['textbot']['addBalance'], 'callback_data' => 'Add_Balance']],
    ];
    $button = $info['btnmessage'] ?? 'none';
    if (!array_key_exists($button, $buttons)) {
        return ['ok' => false, 'description' => 'Unknown bulk message button'];
    }
    $keyboard = $buttons[$button] === null ? null : json_encode(['inline_keyboard' => [$buttons[$button]]]);
    return sendmessage($userId, $info['message'], $keyboard, 'HTML');
}

function bulkProcessQueue(string $slot, int $budget): int
{
    global $textbotlang, $pdo;
    try {
        $snapshot = bulkQueueSnapshot($slot);
    } catch (Throwable $e) {
        error_log('Bulk queue read failed: ' . $e->getMessage());
        return 0;
    }
    if ($snapshot === null) {
        return 0;
    }
    [$users, $info] = $snapshot;
    $originalInfo = $info;
    $jobId = $info['job_id'] ?? '';
    if (!$users) {
        if ($jobId !== '') {
            bulkQueueCancel($slot, $jobId);
        } else {
            bulkQueueCancelLegacy($slot);
        }
        if (isset($info['id_admin'], $info['id_message'])) {
            if ($info['id_message'] !== null) {
                Editmessagetext($info['id_admin'], $info['id_message'], sprintf($textbotlang['Admin']['messageBulk']['finishedStats'], $info['sent'] ?? 0, $info['failed'] ?? 0), json_encode(['inline_keyboard' => []]));
            }
            sendmessage($info['id_admin'], $textbotlang['Admin']['messageBulk']['done'], null, 'HTML');
        }
        if (preg_match('/^credit:([a-f0-9]{24})$/', $slot, $creditMatch)) {
            $pdo->prepare("UPDATE bulk_credit_batch SET notification_status = 'done' WHERE id = ? AND notification_status = 'queued'")
                ->execute([$creditMatch[1]]);
        }
        return 0;
    }
    if (isset($info['id_admin'], $info['id_message']) && $info['id_message'] !== null) {
        $cancelData = $jobId !== '' && $slot === 'message' ? 'cancel_sendmessage_' . $jobId : null;
        $keyboard = $cancelData ? json_encode(['inline_keyboard' => [[['text' => $textbotlang['keyboard']['cancelOperation'], 'callback_data' => $cancelData]]]]) : json_encode(['inline_keyboard' => []]);
        Editmessagetext($info['id_admin'], $info['id_message'], sprintf($textbotlang['Admin']['messageBulk']['progress'], count($users)), $keyboard);
    }
    $attempted = 0;
    $scanned = 0;
    $scanLimit = count($users);
    while ($attempted < $budget && $scanned < $scanLimit && $users) {
        $item = array_shift($users);
        $scanned++;
        if (!isset($item['id'])) {
            $info['failed'] = ($info['failed'] ?? 0) + 1;
            continue;
        }
        if ((int) ($item['next_at'] ?? 0) > time()) {
            $users[] = $item;
            continue;
        }
        $current = bulkQueueSnapshot($slot);
        if ($current === null || ($current[1]['job_id'] ?? '') !== $jobId) {
            return $attempted;
        }
        $userId = (string) $item['id'];
        $response = bulkSendToRecipient($info, $userId);
        $attempted++;
        if (!empty($response['ok'])) {
            $info['sent'] = ($info['sent'] ?? 0) + 1;
            if (($info['pingmessage'] ?? '') === 'yes' && isset($response['result']['message_id'])) {
                pinmessage($userId, $response['result']['message_id']);
            }
            continue;
        }
        $code = (int) ($response['error_code'] ?? 0);
        $attempts = (int) ($item['attempts'] ?? 0) + 1;
        $retryable = $code === 429 || $code >= 500 || $code === 0;
        if ($retryable && $attempts < 4) {
            $item['attempts'] = $attempts;
            $item['next_at'] = time() + max(5, (int) ($response['parameters']['retry_after'] ?? min(300, 10 * $attempts)));
            $users[] = $item;
            continue;
        }
        $info['failed'] = ($info['failed'] ?? 0) + 1;
        $logPath = __DIR__ . '/bulk-failures.jsonl';
        file_put_contents($logPath, json_encode(['job_id' => $jobId, 'user_id' => $userId, 'code' => $code, 'error' => $response['description'] ?? 'unknown'], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
    try {
        if ($jobId !== '') {
            bulkQueueReplace($slot, $jobId, $users, $info);
        } else {
            [$usersPath, $infoPath] = bulkQueuePaths($slot);
            bulkQueueLocked($slot, static function () use ($usersPath, $infoPath, $users, $info, $originalInfo) {
                if (is_file($usersPath) && is_file($infoPath)) {
                    $current = json_decode((string) file_get_contents($infoPath), true);
                    if (is_array($current) && empty($current['job_id']) && $current == $originalInfo) {
                        bulkQueueAtomicWrite($usersPath, json_encode($users, JSON_THROW_ON_ERROR));
                        bulkQueueAtomicWrite($infoPath, json_encode($info, JSON_THROW_ON_ERROR));
                    }
                }
            });
        }
    } catch (Throwable $e) {
        error_log('Bulk queue save failed: ' . $e->getMessage());
    }
    return $attempted;
}

try {
    bulkCreditEnsureTables($pdo);
    $pending = $pdo->query("SELECT id, amount FROM bulk_credit_batch WHERE status = 'applied' AND notify = 1 AND notification_status = 'pending' ORDER BY created_at LIMIT 5")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pending as $batch) {
        bulkCreditScheduleNotification($pdo, $batch['id'], sprintf($textbotlang['users']['Balance']['giftFromManagement'], $batch['amount']));
    }
} catch (Throwable $e) {
    error_log('Bulk credit notification scheduling failed: ' . $e->getMessage());
}

$budget = 20;
$creditFiles = glob(__DIR__ . '/credit-*.info') ?: [];
sort($creditFiles);
if ($creditFiles) {
    $budget -= bulkProcessQueue('message', 10);
    foreach ($creditFiles as $file) {
        if ($budget <= 0) {
            break;
        }
        if (preg_match('/credit-([a-f0-9]{24})\.info$/', $file, $match)) {
            $budget -= bulkProcessQueue('credit:' . $match[1], min(10, $budget));
        }
    }
} else {
    bulkProcessQueue('message', $budget);
}
