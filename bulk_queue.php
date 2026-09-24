<?php

function bulkQueuePaths(string $slot): array
{
    if ($slot === 'message') {
        return [__DIR__ . '/cronbot/users.json', __DIR__ . '/cronbot/info', __DIR__ . '/cronbot/.message.lock'];
    }
    if (preg_match('/^credit:([a-f0-9]{24})$/', $slot, $matches)) {
        $base = __DIR__ . '/cronbot/credit-' . $matches[1];
        return [$base . '.json', $base . '.info', __DIR__ . '/cronbot/.credit.lock'];
    }
    throw new InvalidArgumentException('Invalid queue slot');
}

function bulkQueueLocked(string $slot, callable $callback)
{
    [, , $lockPath] = bulkQueuePaths($slot);
    $handle = fopen($lockPath, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Cannot open bulk queue lock');
    }
    try {
        flock($handle, LOCK_EX);
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function bulkQueueAtomicWrite(string $path, string $content): void
{
    $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temp, $content) === false || !rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Cannot write bulk queue');
    }
}

function bulkQueueSnapshot(string $slot): ?array
{
    [$usersPath, $infoPath] = bulkQueuePaths($slot);
    return bulkQueueLocked($slot, static function () use ($usersPath, $infoPath) {
        if (!is_file($usersPath) || !is_file($infoPath)) {
            return null;
        }
        $users = json_decode((string) file_get_contents($usersPath), true);
        $info = json_decode((string) file_get_contents($infoPath), true);
        if (!is_array($users) || !is_array($info)) {
            throw new RuntimeException('Invalid bulk queue data');
        }
        if (isset($users['job_id'])) {
            if (($info['job_id'] ?? '') !== $users['job_id'] || !isset($users['users']) || !is_array($users['users'])) {
                throw new RuntimeException('Bulk queue files do not match');
            }
            $users = $users['users'];
        } elseif (!empty($info['job_id'])) {
            throw new RuntimeException('Bulk queue files do not match');
        }
        return [$users, $info];
    });
}

function bulkQueueStart(string $slot, array $userIds, array $info): ?string
{
    if (!$userIds) {
        return null;
    }
    [$usersPath, $infoPath] = bulkQueuePaths($slot);
    return bulkQueueLocked($slot, static function () use ($usersPath, $infoPath, $userIds, $info) {
        if (is_file($usersPath) && is_file($infoPath)) {
            $existing = json_decode((string) file_get_contents($usersPath), true);
            if (!is_array($existing) || count($existing['users'] ?? $existing) > 0) {
                return null;
            }
        }
        $jobId = $info['job_id'] ?? bin2hex(random_bytes(10));
        if (!preg_match('/^[a-f0-9]{20}$/', $jobId)) {
            throw new InvalidArgumentException('Invalid bulk job ID');
        }
        $info['job_id'] = $jobId;
        $info['sent'] = 0;
        $info['failed'] = 0;
        $info['total'] = count($userIds);
        $users = array_map(static fn($id) => ['id' => (string) $id, 'attempts' => 0, 'next_at' => 0], $userIds);
        try {
            bulkQueueAtomicWrite($usersPath, json_encode(['job_id' => $jobId, 'users' => $users], JSON_THROW_ON_ERROR));
            bulkQueueAtomicWrite($infoPath, json_encode($info, JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            @unlink($usersPath);
            @unlink($infoPath);
            throw $e;
        }
        return $jobId;
    });
}

function bulkQueueCancel(string $slot, string $jobId): bool
{
    [$usersPath, $infoPath] = bulkQueuePaths($slot);
    return bulkQueueLocked($slot, static function () use ($usersPath, $infoPath, $jobId) {
        $info = is_file($infoPath) ? json_decode((string) file_get_contents($infoPath), true) : null;
        if (!is_array($info) || ($info['job_id'] ?? '') !== $jobId) {
            return false;
        }
        @unlink($usersPath);
        @unlink($infoPath);
        return true;
    });
}

function bulkQueueCancelLegacy(string $slot): bool
{
    [$usersPath, $infoPath] = bulkQueuePaths($slot);
    return bulkQueueLocked($slot, static function () use ($usersPath, $infoPath) {
        $info = is_file($infoPath) ? json_decode((string) file_get_contents($infoPath), true) : null;
        if (!is_array($info) || !empty($info['job_id'])) {
            return false;
        }
        @unlink($usersPath);
        @unlink($infoPath);
        return true;
    });
}

function bulkQueueReplace(string $slot, string $jobId, array $users, array $info): bool
{
    [$usersPath, $infoPath] = bulkQueuePaths($slot);
    return bulkQueueLocked($slot, static function () use ($usersPath, $infoPath, $jobId, $users, $info) {
        $current = is_file($infoPath) ? json_decode((string) file_get_contents($infoPath), true) : null;
        if (!is_array($current) || ($current['job_id'] ?? '') !== $jobId) {
            return false;
        }
        bulkQueueAtomicWrite($usersPath, json_encode(['job_id' => $jobId, 'users' => $users], JSON_THROW_ON_ERROR));
        bulkQueueAtomicWrite($infoPath, json_encode($info, JSON_THROW_ON_ERROR));
        return true;
    });
}
