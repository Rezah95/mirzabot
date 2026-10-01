<?php

function mirza_cron_jobs(): array
{
    return [
        ['job' => 'tronado', 'schedule' => '* * * * *', 'title' => 'تحویل پرداخت‌های تأییدشدهٔ ترونادو'],
        ['job' => 'croncard', 'schedule' => '*/1 * * * *', 'title' => 'تأیید خودکار رسید کارت به کارت'],
        ['job' => 'NoticationsService', 'schedule' => '*/1 * * * *', 'title' => 'ارسال اعلان‌های ربات'],
        ['job' => 'renewal_reminders', 'schedule' => '*/5 * * * *', 'title' => 'یادآوری تمدید سرویس‌های تمام‌شده'],
        ['job' => 'sendmessage', 'schedule' => '*/1 * * * *', 'title' => 'صف ارسال پیام همگانی'],
        ['job' => 'activeconfig', 'schedule' => '*/1 * * * *', 'title' => 'فعال‌سازی سرویس‌های خریداری‌شده'],
        ['job' => 'disableconfig', 'schedule' => '*/1 * * * *', 'title' => 'غیرفعال‌سازی سرویس‌های منقضی'],
        ['job' => 'iranpay1', 'schedule' => '*/1 * * * *', 'title' => 'پیگیری پرداخت‌های ایران‌پی'],
        ['job' => 'gift', 'schedule' => '*/2 * * * *', 'title' => 'پردازش کدهای هدیه'],
        ['job' => 'configtest', 'schedule' => '*/2 * * * *', 'title' => 'مدیریت سرویس‌های تست'],
        ['job' => 'plisio', 'schedule' => '*/3 * * * *', 'title' => 'پیگیری پرداخت‌های ارز دیجیتال'],
        ['job' => 'payment_expire', 'schedule' => '*/5 * * * *', 'title' => 'انقضای فاکتورهای پرداخت‌نشده'],
        ['job' => 'statusday', 'schedule' => '*/15 * * * *', 'title' => 'گزارش وضعیت روزانه'],
        ['job' => 'on_hold', 'schedule' => '*/15 * * * *', 'title' => 'سرویس‌های در حالت انتظار'],
        ['job' => 'uptime_node', 'schedule' => '*/15 * * * *', 'title' => 'پایش وضعیت نودها'],
        ['job' => 'uptime_panel', 'schedule' => '*/15 * * * *', 'title' => 'پایش وضعیت پنل‌ها'],
        ['job' => 'expireagent', 'schedule' => '*/30 * * * *', 'title' => 'انقضای اشتراک نمایندگان'],
        ['job' => 'backupbot', 'schedule' => '0 */5 * * *', 'title' => 'پشتیبان‌گیری ربات‌ساز'],
        ['job' => 'lottery', 'schedule' => '*/1 * * * *', 'title' => 'قرعه‌کشی و امتیازات'],
    ];
}

function mirza_cron_dispatcher_path(): string
{
    return __DIR__ . '/run.php';
}

function mirza_cron_stagger_seconds(string $seed = ''): int
{
    if ($seed === '') {
        $seed = dirname(__DIR__);
    }

    return (int) (sprintf('%u', crc32($seed)) % 20);
}

function mirza_cron_runtime_error(): ?string
{
    $missing = [];
    if (PHP_VERSION_ID < 80200) { $missing[] = 'PHP >= 8.2'; }
    if (!function_exists('mysqli_connect')) { $missing[] = 'mysqli'; }
    if (!extension_loaded('pdo_mysql')) { $missing[] = 'pdo_mysql'; }
    return $missing === [] ? null : 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ', ' . PHP_BINARY
        . ') missing: ' . implode(', ', $missing) . '. Enable MySQL extensions for this PHP CLI version.';
}

/** Probe CLI extensions instead of assuming the web PHP and /usr/bin/php match. */
function mirza_cron_php_binary(?array $candidates = null): string
{
    if ($candidates === null) {
        $versioned = array_filter(array_merge(glob(PHP_BINDIR . '/php[0-9]*') ?: [], glob('/usr/bin/php[0-9]*') ?: []),
            static fn($path) => preg_match('/^php[0-9]+\.[0-9]+$/', basename($path)) === 1);
        natsort($versioned);
        $candidates = array_merge(PHP_SAPI === 'cli' ? [PHP_BINARY] : [],
            [PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_BINDIR . '/php', '/usr/bin/php'],
            array_reverse($versioned));
    }
    foreach (array_unique($candidates) as $php) {
        if (!is_executable($php)) { continue; }
        if (PHP_SAPI === 'cli' && realpath($php) === realpath(PHP_BINARY) && mirza_cron_runtime_error() === null) {
            return realpath($php);
        }
        if (!function_exists('shell_exec')) { continue; }
        $probe = 'if (PHP_SAPI === "cli" && PHP_VERSION_ID >= 80200 && function_exists("mysqli_connect") && extension_loaded("pdo_mysql")) { echo "MIRZA_CRON_PHP_OK"; }';
        $output = @shell_exec(escapeshellarg($php) . ' -r ' . escapeshellarg($probe) . ' 2>/dev/null');
        if (trim((string) $output) === 'MIRZA_CRON_PHP_OK') { return realpath($php); }
    }
    throw new RuntimeException('No PHP CLI >= 8.2 with mysqli and pdo_mysql is available');
}

function mirza_cron_dispatcher_command(string $seed = ''): string
{
    $sleep = mirza_cron_stagger_seconds($seed);
    $php = mirza_cron_php_binary();
    $path = mirza_cron_dispatcher_path();

    return '* * * * * sleep ' . $sleep . '; ' . escapeshellarg($php) . ' ' . escapeshellarg($path) . ' >/dev/null 2>&1';
}

function mirza_cron_dispatcher_curl_command(string $baseUrl): string
{
    return '* * * * * curl -s ' . rtrim($baseUrl, '/') . '/cronbot/run.php > /dev/null 2>&1';
}
