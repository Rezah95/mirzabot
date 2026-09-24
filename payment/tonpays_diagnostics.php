<?php

final class TonpaysFailure extends RuntimeException
{
    public array $diagnostics;

    public function __construct(string $message, array $diagnostics = [])
    {
        parent::__construct($message);
        $this->diagnostics = $diagnostics;
    }
}

function tonpaysSafeDiagnostic(string $text): string
{
    try { $key = function_exists('getPaySettingValue') ? trim((string) getPaySettingValue('tonpays_api_key', '')) : ''; }
    catch (Throwable $e) { $key = ''; }
    if ($key !== '' && $key !== '0') {
        $text = str_replace([$key, rawurlencode($key), base64_encode($key)], '[redacted]', $text);
    }
    $text = preg_replace('~https?://[^\s<>"\x27]+~i', '[url]', $text);
    $text = preg_replace('/\b(?:Bearer\s+|(?:api[_ -]?key|token|secret|authorization|password)["\x27]?\s*[:=]\s*["\x27]?)[^\s,;"\x27<>]+/i', '[credential]', $text);
    $text = preg_replace('/\b[0-9]{9,}\b|\b[A-Za-z0-9_-]{32,}\b/', '[redacted]', $text);
    $text = preg_replace('/[\x00-\x20\x7f]+/', ' ', strip_tags($text));
    return mb_substr(trim($text), 0, 600, 'UTF-8');
}

/** Read error fields only. FastAPI input/ctx and payment/customer/card fields are excluded. */
function tonpaysResponseError(array $response, int $depth = 0): string
{
    if ($depth > 3) { return ''; }
    $parts = [];
    if (array_is_list($response)) {
        foreach (array_slice($response, 0, 3) as $item) {
            if (is_array($item)) { $parts[] = tonpaysResponseError($item, $depth + 1); }
        }
    } else {
        if (is_array($response['loc'] ?? null)) {
            $location = array_filter($response['loc'], static fn($v) => is_string($v) && in_array($v,
                ['body', 'header', 'path', 'query', 'amount', 'order_id', 'callback_url', 'buyer_chat_id', 'invoice_id'], true));
            if ($location) { $parts[] = implode('.', $location); }
        }
        foreach (['code', 'error_code', 'detail', 'message', 'msg', 'error', 'description', 'ErrorMessage'] as $field) {
            $value = $response[$field] ?? null;
            if (is_string($value) || is_int($value)) { $parts[] = tonpaysSafeDiagnostic((string) $value); }
            elseif (is_array($value)) { $parts[] = tonpaysResponseError($value, $depth + 1); }
        }
    }
    return tonpaysSafeDiagnostic(implode('; ', array_unique(array_filter($parts))));
}

function tonpaysResponseFields(array $response): string
{
    $fields = [];
    foreach (array_slice($response, 0, 20, true) as $key => $value) {
        if (is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $key)) {
            $fields[] = $key . ':' . get_debug_type($value);
        }
    }
    return implode(',', $fields);
}

function tonpaysLogPath(): string
{
    // Test processes can isolate their logs without loading the production configuration.
    return PHP_SAPI === 'cli' && defined('TONPAYS_TEST_LOG_FILE')
        ? TONPAYS_TEST_LOG_FILE : dirname(__DIR__) . '/storage/tonpays.log';
}

function tonpaysLog(string $event, array $context = [], ?Throwable $error = null): string
{
    if ($error instanceof TonpaysFailure) { $context = array_merge($error->diagnostics, $context); }
    if ($error !== null) {
        $context += ['reason' => $error->getMessage(), 'exception' => get_class($error),
            'file' => basename($error->getFile()), 'line' => $error->getLine()];
    }
    $reference = 'TP-' . bin2hex(random_bytes(6));
    $entry = ['time' => gmdate('c'), 'reference' => $reference, 'event' => tonpaysSafeDiagnostic($event)];
    foreach (['stage', 'order_id', 'http_status', 'curl_errno', 'curl_error', 'content_type', 'reason', 'response_fields',
        'response_bytes', 'json_error', 'fields', 'exception', 'file', 'line', 'configured', 'amount', 'minimum', 'maximum', 'telegram_code'] as $field) {
        $value = $context[$field] ?? null;
        if (is_bool($value) || is_int($value)) { $entry[$field] = $value; }
        elseif (is_string($value)) {
            $entry[$field] = $field === 'order_id' && preg_match('/^[a-f0-9]{10,20}$/', $value) ? $value : tonpaysSafeDiagnostic($value);
        }
    }
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    if (@file_put_contents(tonpaysLogPath(), $line, FILE_APPEND | LOCK_EX) === false) {
        error_log('TonPays diagnostic log is not writable; ' . trim($line));
    }
    return $reference;
}

function tonpaysRecentErrorsText(): string
{
    $path = tonpaysLogPath();
    if (!is_file($path)) {
        return is_writable(dirname($path))
            ? 'هنوز خطایی در لاگ جدید TonPays ثبت نشده است. بعد از تلاش مجدد برای پرداخت، این صفحه را تازه کنید.'
            : 'پوشهٔ storage برای ثبت لاگ قابل نوشتن نیست. دسترسی پوشه و error_log سرور را بررسی کنید.';
    }
    $file = @fopen($path, 'rb');
    if ($file === false) { return 'خواندن storage/tonpays.log ممکن نیست؛ دسترسی فایل را روی سرور بررسی کنید.'; }
    $size = fstat($file)['size'];
    if ($size > 65536) { fseek($file, -65536, SEEK_END); fgets($file); }
    $tail = stream_get_contents($file);
    fclose($file);
    $entries = [];
    foreach (array_reverse(explode("\n", trim($tail))) as $line) {
        $entry = json_decode($line, true);
        if (!is_array($entry)) { continue; }
        $parts = [];
        foreach (['time', 'reference', 'event', 'stage', 'order_id', 'http_status', 'curl_errno', 'curl_error', 'reason', 'fields', 'response_fields'] as $key) {
            if (isset($entry[$key]) && is_scalar($entry[$key])) {
                $value = (string) $entry[$key];
                $identifier = ($key === 'reference' && preg_match('/^TP-[a-f0-9]{12}$/', $value))
                    || ($key === 'order_id' && preg_match('/^[a-f0-9]{10,20}$/', $value));
                $parts[] = $key . ': ' . ($identifier ? $value : tonpaysSafeDiagnostic($value));
            }
        }
        $entries[] = mb_substr(implode("\n", $parts), 0, 620, 'UTF-8');
        if (count($entries) === 4) { break; }
    }
    return "آخرین خطاهای TonPays (زمان UTC):\n\n" . ($entries ? implode("\n\n", $entries) : 'خطای قابل خواندن وجود ندارد.')
        . "\n\nمسیر روی سرور: storage/tonpays.log";
}
