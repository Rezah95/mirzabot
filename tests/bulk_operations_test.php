<?php

require_once dirname(__DIR__) . '/bulk_audience.php';
require_once dirname(__DIR__) . '/bulk_queue.php';

function expectBulk(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expectBulk(bulkParseDayRange('7-30') === [7, 30], 'ASCII day range');
expectBulk(bulkParseDayRange('۷-۳۰') === [7, 30], 'Persian day range');
expectBulk(bulkParseDayRange('٧–٣٠') === [7, 30], 'Arabic day range');
expectBulk(bulkParseDayRange('30-7') === null, 'Reversed day range');
expectBulk(bulkParseDayRange('7-3651') === null, 'Out-of-range days');

[$sql, $params] = bulkAudienceQuery([
    'kind' => 'expired_unrenewed', 'agent' => 'n', 'days_from' => 7, 'days_to' => 30,
], true, 10000000);
expectBulk($params[':expired_after'] === 10000000 - 31 * 86400, 'Older boundary');
expectBulk($params[':expired_before'] === 10000000 - 7 * 86400, 'Newer boundary');
expectBulk(str_contains($sql, 'depleted_at') && str_contains($sql, 'active_invoice'), 'Expired and renewed audience');

$slot = 'credit:' . bin2hex(random_bytes(12));
[$usersPath, $infoPath] = bulkQueuePaths($slot);
try {
    $job = bulkQueueStart($slot, ['101', '102'], ['type' => 'sendmessage']);
    expectBulk(is_string($job), 'Queue creation');
    expectBulk(!bulkQueueCancel($slot, str_repeat('0', 20)), 'Stale cancel must not stop a job');
    [$users, $info] = bulkQueueSnapshot($slot);
    expectBulk(count($users) === 2 && $info['job_id'] === $job, 'Queue snapshot');
    array_shift($users);
    expectBulk(bulkQueueReplace($slot, $job, $users, $info), 'Queue progress');
    expectBulk(count(bulkQueueSnapshot($slot)[0]) === 1, 'Queue progress persisted');
    expectBulk(bulkQueueCancel($slot, $job), 'Matching cancel');
} finally {
    @unlink($usersPath);
    @unlink($infoPath);
}

echo "bulk operations OK\n";
