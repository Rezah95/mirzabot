<?php
// Exercise the real web text-editor POST and runtime override loader in a disposable tree.
function checkOfferText($ok, $message): void { if (!$ok) { throw new RuntimeException($message); } }
$root = sys_get_temp_dir() . '/mirza-offer-text-' . bin2hex(random_bytes(6));
mkdir($root . '/panel/inc', 0700, true); mkdir($root . '/lang');
try {
    copy(dirname(__DIR__) . '/panel/bottext.php', $root . '/panel/bottext.php');
    foreach (['fa', 'en', 'ru', 'zh'] as $code) { copy(dirname(__DIR__) . '/lang/' . $code . '.php', $root . '/lang/' . $code . '.php'); }
    file_put_contents($root . '/panel/inc/icons.php', '<?php');
    file_put_contents($root . '/panel/inc/config.php', '<?php $textbotlang = require dirname(__DIR__, 2) . "/lang/fa.php"; function require_auth() {} function csrf_check_post() { file_put_contents(dirname(__DIR__, 2) . "/csrf", "yes"); } function flash(...$args) {}');
    $original = file_get_contents(dirname(__DIR__) . '/function.php');
    $start = strpos($original, 'function bottext_apply_overrides(');
    $end = strpos($original, 'function extendMethodKeys(', $start);
    $loader = '<?php function customEmojiLabels($value) {} function select(...$args) { return []; } ' . substr($original, $start, $end - $start);
    file_put_contents($root . '/loader.php', $loader);
    $customTime = "سلام 😀\r\n{username} — {days} روز باقی مانده";
    $customCoupon = '🎁 کد ویژه {code} با {percent}٪ تخفیف؛ اعتبار {valid_until}';
    $runner = '<?php $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["QUERY_STRING"] = "lang=fa&group=renewalOffers"; $_GET = ["lang" => "fa", "group" => "renewalOffers"]; $_POST = ["texts" => ' . var_export(['renewalOffers.time' => $customTime, 'renewalOffers.coupon' => $customCoupon], true) . ']; require __DIR__ . "/panel/bottext.php";';
    file_put_contents($root . '/save.php', $runner);
    $p = proc_open([PHP_BINARY, $root . '/save.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    checkOfferText(proc_close($p) === 0, 'Editor save failed: ' . $output);
    checkOfferText(is_file($root . '/csrf'), 'Editor skipped CSRF hook');
    require $root . '/loader.php';
    $lang = require $root . '/lang/fa.php'; bottext_apply_overrides($lang, 'fa');
    checkOfferText($lang['renewalOffers']['time'] === str_replace("\r\n", "\n", $customTime), 'Persian/newline/emoji round-trip failed');
    checkOfferText($lang['renewalOffers']['coupon'] === $customCoupon, 'Coupon edit missing');
    require_once dirname(__DIR__) . '/renewal_warnings.php';
    $rendered = renewalWarningRender($lang, ['username' => 'user<&', 'name_product' => 'Plan'], 'time', ['{days}' => '3'], ['price' => 10, 'codeDiscount' => 'rnexample', 'time' => time() + 86400]);
    checkOfferText(str_contains($rendered, 'سلام 😀') && str_contains($rendered, 'user&lt;&amp;') && str_contains($rendered, '<code>rnexample</code>'), 'Custom text did not reach rendered notification');
    echo "Renewal offer text editor: actual POST/save, runtime load, Persian/emoji/newlines and safe rendering passed\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
