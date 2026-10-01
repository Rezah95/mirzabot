<?php

declare(strict_types=1);

$cronbotDir = __DIR__;
chdir($cronbotDir);
$cronLogFile = $cronbotDir . '/error_log';
ini_set('error_log', $cronLogFile);
date_default_timezone_set('Asia/Tehran');
require_once $cronbotDir . '/jobs.php';

$lockFh = @fopen($cronbotDir . '/.run.lock', 'c+');
if ($lockFh === false) {
    error_log('mirza cron: cannot open .run.lock; check cron user and directory/file ownership');
    exit(1);
}
if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    error_log('mirza cron: previous dispatcher is still running; this invocation was skipped');
    fclose($lockFh);
    exit(0);
}

$cronStatusFile = dirname($cronbotDir) . '/storage/cron_status.json';
$cronStatus = json_decode((string) @file_get_contents($cronStatusFile), true);
$cronStatus = is_array($cronStatus) ? $cronStatus : [];
$cronStatus['dispatcher'] = time();
$cronStatus['state'] = 'running';
$cronStatus['error'] = null;
$cronStatus['active_job'] = null;
mirza_cron_save_status($cronStatusFile, $cronStatus);
$cronRuntimeError = mirza_cron_runtime_error();
if ($cronRuntimeError !== null) {
    $cronStatus['state'] = 'blocked';
    $cronStatus['error'] = $cronRuntimeError;
    mirza_cron_save_status($cronStatusFile, $cronStatus);
    error_log('mirza cron: ' . $cronRuntimeError);
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(1);
}
$slotFh = mirza_cron_try_host_slot(3, 2);
if ($slotFh === null) {
    $cronStatus['state'] = 'blocked';
    $cronStatus['error'] = 'No host slot available; check running dispatchers and /tmp/mirza-cron-slots permissions';
    mirza_cron_save_status($cronStatusFile, $cronStatus);
    error_log('mirza cron: ' . $cronStatus['error']);
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    exit(1);
}

// exit/die and fatal errors bypass finally. Record the last active job on shutdown too.
$cronCompleted = false;
register_shutdown_function(static function () use (&$cronCompleted, &$cronStatus, $cronStatusFile, $cronLogFile): void {
    if ($cronCompleted) { return; }
    ini_set('error_log', $cronLogFile);
    $last = error_get_last();
    $fatal = $last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    $error = $fatal ? $last['message'] : 'Worker exited before returning to dispatcher';
    $cronStatus['state'] = 'interrupted';
    $cronStatus['error'] = $error;
    if ($cronStatus['active_job'] !== null) {
        $cronStatus['jobs'][$cronStatus['active_job']]['state'] = 'interrupted';
        $cronStatus['jobs'][$cronStatus['active_job']]['error'] = $error;
    }
    mirza_cron_save_status($cronStatusFile, $cronStatus);
    error_log('mirza cron interrupted at ' . ($cronStatus['active_job'] ?? 'startup') . ': ' . $error);
});

// Evaluate every schedule against the same tick, even when an earlier job is slow.
$cronTick = new DateTimeImmutable('now');
try {
    foreach (mirza_cron_jobs() as $job) {
        if (!mirza_cron_is_due($job['schedule'], $cronTick)) { continue; }
        $script = $cronbotDir . '/' . $job['job'] . '.php';
        $cronStatus['active_job'] = $job['job'];
        $cronStatus['jobs'][$job['job']] = ['time' => time(), 'state' => 'running', 'error' => null];
        mirza_cron_save_status($cronStatusFile, $cronStatus);
        $jobError = null;
        $jobState = 'completed';
        try {
            chdir($cronbotDir);
            ini_set('error_log', $cronLogFile);
            if (!is_file($script)) { throw new RuntimeException('Cron script missing: ' . $job['job']); }
            // lottery.php already checks scorestatus; keep that check inside the job error boundary.
            include $script;
        } catch (Throwable $e) {
            $jobError = $e->getMessage();
            $jobState = 'failed';
        } finally {
            chdir($cronbotDir);
            ini_set('error_log', $cronLogFile);
        }
        if ($jobError !== null) { error_log('mirza cron: ' . $job['job'] . ': ' . $jobError); }
        $cronStatus['jobs'][$job['job']] = ['time' => time(), 'state' => $jobState, 'error' => $jobError];
        $cronStatus['active_job'] = null;
        $cronStatus['dispatcher'] = time();
        mirza_cron_save_status($cronStatusFile, $cronStatus);
    }
    $cronStatus['state'] = 'completed';
    $cronStatus['dispatcher'] = time();
    mirza_cron_save_status($cronStatusFile, $cronStatus);
    $cronCompleted = true;
} finally {
    mirza_cron_release_host_slot($slotFh);
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
}

/** Publish complete JSON on each transition; a failed write must not disappear silently. */
function mirza_cron_save_status(string $path, array $status): bool
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        error_log('mirza cron: cannot create status directory: ' . $directory);
        return false;
    }
    $temporary = @tempnam($directory, '.cron-status-');
    if ($temporary === false) {
        error_log('mirza cron: cannot create status file; check storage permissions');
        return false;
    }
    $json = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $saved = $json !== false && @file_put_contents($temporary, $json) !== false && @rename($temporary, $path);
    if (!$saved) {
        @unlink($temporary);
        error_log('mirza cron: cannot write status: ' . $path);
    }
    return $saved;
}

/** @return resource|null */
function mirza_cron_try_host_slot(int $maxSlots, int $waitSeconds)
{
    $dir = '/tmp/mirza-cron-slots';
    if ((!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) || !is_writable($dir)) {
        return null;
    }

    $deadline = microtime(true) + $waitSeconds;
    do {
        for ($i = 0; $i < $maxSlots; $i++) {
            $path = $dir . '/slot-' . $i . '.lock';
            $fh = @fopen($path, 'c+');
            if ($fh === false) {
                continue;
            }
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                return $fh;
            }
            fclose($fh);
        }
        usleep(200000);
    } while (microtime(true) < $deadline);

    return null;
}

/** @param resource|null $slotFh */
function mirza_cron_release_host_slot($slotFh): void
{
    if ($slotFh === null || !is_resource($slotFh)) {
        return;
    }
    flock($slotFh, LOCK_UN);
    fclose($slotFh);
}

function mirza_cron_is_due(string $expression, ?DateTimeInterface $now = null): bool
{
    $now = $now ?? new DateTimeImmutable('now');
    $parts = preg_split('/\s+/', trim($expression));
    if ($parts === false || count($parts) !== 5) {
        return false;
    }

    [$minute, $hour, $day, $month, $weekday] = $parts;
    $values = [
        (int) $now->format('i'),
        (int) $now->format('G'),
        (int) $now->format('j'),
        (int) $now->format('n'),
        (int) $now->format('w'),
    ];
    $fields = [$minute, $hour, $day, $month, $weekday];
    $mins = [0, 0, 1, 1, 0];

    for ($i = 0; $i < 5; $i++) {
        if (!mirza_cron_field_matches($fields[$i], $values[$i], $mins[$i])) {
            return false;
        }
    }

    return true;
}

function mirza_cron_field_matches(string $field, int $value, int $min): bool
{
    foreach (explode(',', $field) as $piece) {
        $piece = trim($piece);
        if ($piece === '') {
            continue;
        }

        $step = 1;
        if (strpos($piece, '/') !== false) {
            [$piece, $stepRaw] = explode('/', $piece, 2);
            $step = max(1, (int) $stepRaw);
        }

        if ($piece === '*' || $piece === '') {
            if ($step === 1 || ($value - $min) % $step === 0) {
                return true;
            }
            continue;
        }

        if (strpos($piece, '-') !== false) {
            [$start, $end] = array_map('intval', explode('-', $piece, 2));
            if ($value >= $start && $value <= $end && ($step === 1 || ($value - $start) % $step === 0)) {
                return true;
            }
            continue;
        }

        $exact = (int) $piece;
        if ($value === $exact && ($step === 1 || ($value - $min) % $step === 0)) {
            return true;
        }
    }

    return false;
}
