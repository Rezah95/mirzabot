<?php
// Real dispatcher and bulk-worker entrypoint, with no database or Telegram access.
function expectCron(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$root = sys_get_temp_dir() . '/mirza-cron-test-' . bin2hex(random_bytes(6));
mkdir($root . '/cronbot', 0700, true);
mkdir($root . '/storage');
$run = file_get_contents(dirname(__DIR__) . '/cronbot/run.php');
$run = str_replace("'/tmp/mirza-cron-slots'", var_export($root . '/host-slots', true), $run);
file_put_contents($root . '/cronbot/run.php', $run);
$helperSource = file_get_contents(dirname(__DIR__) . '/cronbot/jobs.php');
$helperSource = substr($helperSource, strpos($helperSource, 'function mirza_cron_runtime_error'));
$plan = static function (array $jobs) use ($root, $helperSource): void {
    $list = array_map(static fn($name) => ['job' => $name, 'schedule' => '* * * * *'], $jobs);
    file_put_contents($root . '/cronbot/jobs.php', '<?php function mirza_cron_jobs(): array { return ' . var_export($list, true) . '; }' . $helperSource);
};
$invoke = static function () use ($root): int {
    $process = proc_open([PHP_BINARY, $root . '/cronbot/run.php'], [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/output.log', 'a'], 2 => ['file', $root . '/output.log', 'a'],
    ], $pipes);
    expectCron(is_resource($process), 'Cannot start dispatcher');
    return proc_close($process);
};
$state = static fn() => json_decode(file_get_contents($root . '/storage/cron_status.json'), true, 512, JSON_THROW_ON_ERROR);
$lock = null;
try {
    foreach (['config', 'botapi', 'bulk_queue', 'bulk_credit'] as $file) { file_put_contents($root . '/' . $file . '.php', '<?php'); }
    file_put_contents($root . '/function.php', '<?php function languagechange() { return []; }');
    copy(dirname(__DIR__) . '/cronbot/sendmessage.php', $root . '/cronbot/sendmessage.php');
    file_put_contents($root . '/cronbot/later.php', '<?php file_put_contents(__DIR__ . "/later-ran", "yes");');
    $plan(['sendmessage', 'later']);
    $lock = fopen($root . '/cronbot/.bulk-worker.lock', 'c+');
    flock($lock, LOCK_EX);
    expectCron($invoke() === 0 && is_file($root . '/cronbot/later-ran'), 'Busy bulk worker stopped all later cron jobs');
    expectCron($state()['state'] === 'completed' && $state()['jobs']['later']['error'] === null, 'Completed state missing');
    flock($lock, LOCK_UN); fclose($lock); $lock = null;

    // Caught exceptions and CWD/log mutations cannot hide or prevent the following job.
    file_put_contents($root . '/cronbot/broken.php', '<?php chdir("/tmp"); ini_set("error_log", __DIR__ . "/wrong.log"); throw new RuntimeException("fixture failure");');
    $plan(['broken', 'later', 'missing']);
    expectCron($invoke() === 0, 'Caught job exception killed dispatcher');
    $s = $state();
    expectCron($s['jobs']['broken']['error'] === 'fixture failure' && $s['jobs']['later']['state'] === 'completed'
        && $s['jobs']['missing']['state'] === 'failed', 'Failure/missing job not recorded');
    expectCron(str_contains(file_get_contents($root . '/cronbot/error_log'), 'fixture failure'), 'Wrong error-log destination');

    // Observe a live state before the job returns, then simulate exit/die (not catchable).
    file_put_contents($root . '/cronbot/stop.php', <<<'PHP'
<?php
$status = json_decode(file_get_contents(__DIR__ . '/../storage/cron_status.json'), true);
if (($status['active_job'] ?? '') !== 'stop' || $status['jobs']['stop']['state'] !== 'running') {
    throw new RuntimeException('Running state was not published before the job');
}
exit;
PHP);
    $plan(['stop', 'later']);
    $invoke();
    $s = $state();
    expectCron($s['state'] === 'interrupted' && $s['jobs']['stop']['state'] === 'interrupted'
        && str_contains($s['error'], 'exited'), 'Silent exit did not record the active job');
    expectCron(str_contains(file_get_contents($root . '/cronbot/error_log'), 'interrupted at stop'), 'Silent exit not logged');

    // Lock contention must be visible and must not overwrite an active dispatcher's status.
    $before = file_get_contents($root . '/storage/cron_status.json');
    $lock = fopen($root . '/cronbot/.run.lock', 'c+'); flock($lock, LOCK_EX);
    expectCron($invoke() === 0 && file_get_contents($root . '/storage/cron_status.json') === $before, 'Busy invocation overwrote owner status');
    expectCron(str_contains(file_get_contents($root . '/cronbot/error_log'), 'previous dispatcher'), 'Lock contention is silent');
    flock($lock, LOCK_UN); fclose($lock); $lock = null;

    // Reproduce a CLI with no mysql extensions without touching system configuration.
    $plan(['later']);
    unlink($root . '/cronbot/later-ran');
    $process = proc_open([PHP_BINARY, '-n', $root . '/cronbot/run.php'], [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/output.log', 'a'], 2 => ['file', $root . '/output.log', 'a'],
    ], $pipes);
    expectCron(proc_close($process) === 1 && $state()['state'] === 'blocked'
        && str_contains($state()['error'], 'mysqli') && !is_file($root . '/cronbot/later-ran'), 'Missing CLI MySQL extensions were not diagnosed before jobs');

    // Failures writing health data must appear in the log.
    unlink($root . '/storage/cron_status.json'); rmdir($root . '/storage');
    file_put_contents($root . '/storage', 'not a directory');
    $plan(['later']);
    $invoke();
    expectCron(str_contains(file_get_contents($root . '/cronbot/error_log'), 'cannot create status directory'), 'Status write failure hidden');
    echo "Cron dispatcher OK: busy bulk lock, continuation, exceptions, live status, silent exit, lock contention, write failure\n";
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
