<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require_once __DIR__ . '/jobs.php';
if (($error = mirza_cron_runtime_error()) !== null) { fwrite(STDERR, $error . PHP_EOL); exit(1); }
require_once dirname(__DIR__) . '/function.php';
if (!activecron()) { fwrite(STDERR, "Cron registration failed; previous schedule preserved. Check error_log.\n"); exit(1); }
fwrite(STDOUT, "Cron dispatcher registered with a verified PHP CLI.\n");
