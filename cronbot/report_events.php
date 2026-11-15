<?php
$reportPreviousDirectory = getcwd();
$reportPreviousLog = ini_get('error_log');
try {
    chdir(dirname(__DIR__));
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../botapi.php';
    require_once __DIR__ . '/../function.php';
    require_once __DIR__ . '/../report_events.php';
    (static function () use ($pdo): void {
        reportEventsRun($pdo, select('setting', '*'), languagechange(dirname(__DIR__)),
            static fn(array $args) => telegram('sendmessage', $args));
    })();
} finally {
    chdir($reportPreviousDirectory);
    ini_set('error_log', $reportPreviousLog);
}
