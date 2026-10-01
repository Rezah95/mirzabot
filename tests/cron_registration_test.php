<?php
// Test the actual registration helpers against a simulated crontab executable.
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
function expectRegistration(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function isShellExecAvailable() { return $GLOBALS['shellAvailable']; }
function getCrontabBinary() { return '/usr/bin/crontab'; }
function runShellCommand($command) {
    global $storedCron, $readError, $writeFails, $writes;
    if (str_contains($command, 'MIRZA_CRON_READ_STATUS')) {
        return $readError ?: ($storedCron === '' ? "no crontab for www-data\nMIRZA_CRON_READ_STATUS:1\n"
            : $storedCron . "\nMIRZA_CRON_READ_STATUS:0\n");
    }
    expectRegistration(!str_contains($command, 'grep -v') && !str_contains($command, ' -l'), 'Destructive pre-delete used');
    expectRegistration(preg_match("~^'/usr/bin/crontab' '([^']+)'~", $command, $match) === 1, 'Unexpected crontab command');
    $writes++;
    if ($writeFails) { return ''; }
    $storedCron = file_get_contents($match[1]);
    return "MIRZA_CRON_OK\n";
}
require dirname(__DIR__) . '/cronbot/jobs.php';
$source = file_get_contents(dirname(__DIR__) . '/function.php');
$start = strpos($source, 'function addCronIfNotExists(');
$end = strpos($source, 'function createInvoice(', $start);
// eval uses this test's __DIR__, so replace only the helper's absolute include/root expression.
$helpers = str_replace('__DIR__', var_export(dirname(__DIR__), true), substr($source, $start, $end - $start));
eval($helpers);
$log = tempnam(sys_get_temp_dir(), 'mirza-cron-registration-');
ini_set('error_log', $log);
try {
    $fakePhp = tempnam(sys_get_temp_dir(), 'mirza-php-without-mysql-');
    file_put_contents($fakePhp, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' -n "$@"' . "\n");
    chmod($fakePhp, 0700);
    try {
        expectRegistration(mirza_cron_php_binary([$fakePhp, PHP_BINARY]) === realpath(PHP_BINARY), 'Selector chose PHP without MySQL');
        $rejected = false;
        try { mirza_cron_php_binary([$fakePhp]); } catch (RuntimeException $expected) { $rejected = true; }
        expectRegistration($rejected, 'Selector invented a working CLI when none exists');
    } finally { unlink($fakePhp); }
    $domainhosts = 'bot.example.test'; $shellAvailable = true; $readError = ''; $writeFails = false; $writes = 0;
    $unrelated = "# keep this comment\nMAILTO=ops@example.test\n0 1 * * * /usr/local/bin/other-backup";
    $storedCron = $unrelated . "\n* * * * * curl https://bot.example.test/cronbot/gift.php\n";
    expectRegistration(activecron() && $writes === 1, 'Migration did not install atomically');
    expectRegistration(str_contains($storedCron, $unrelated) && !str_contains($storedCron, 'gift.php')
        && str_contains($storedCron, 'cronbot/run.php'), 'Migration lost unrelated entries or retained duplicate workers');
    expectRegistration(activecron() && $writes === 1, 'Repeated registration rewrote a correct crontab');
    $storedCron = $unrelated . "\n* * * * * curl https://bot.example.test/cronbot/gift.php\n";
    $before = $storedCron; $writeFails = true;
    expectRegistration(!activecron() && $storedCron === $before, 'Failed install erased the previous schedule');
    $writeFails = false; $readError = "permission denied\nMIRZA_CRON_READ_STATUS:1\n"; $beforeWrites = $writes;
    expectRegistration(!activecron() && $writes === $beforeWrites && $storedCron === $before, 'Read error overwrote crontab');
    $readError = ''; $storedCron = '';
    expectRegistration(activecron() && str_contains($storedCron, 'run.php'), 'First registration failed');
    $shellAvailable = false;
    expectRegistration(!activecron(), 'Unavailable shell reported success');
    $domainhosts = '';
    expectRegistration(!activecron(), 'Missing domain reported success');
    echo "Cron registration OK: atomic replace, idempotence, preserved unrelated entries, failed read/write, first install\n";
} finally { unlink($log); }
