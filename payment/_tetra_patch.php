<?php
$MIRZA = getenv("MIRZA");
function patch_file($path, $applies) {
    $orig = file_get_contents($path);
    $content = $orig; $changed = false; $report = [];
    foreach ($applies as $name => $fn) {
        [$c, $did, $msg] = $fn($content);
        $content = $c; $report[] = ($did ? "✓ " : "• ") . $msg;
        if ($did) $changed = true;
    }
    if (!$changed) { return [true, $report]; }
    file_put_contents($path, $content);
    return [true, $report];
}

function tm_apply($path, $key, $anchors, $block, $before, $oldpat, $label, $hint, $critical = true) {
    if (!is_file($path)) return !$critical;
    $orig = file_get_contents($path);
    $c = $orig;
    $c = preg_replace('/\/\* ' . preg_quote($key, '/') . '_START \*\/.*?\/\* ' . preg_quote($key, '/') . '_END \*\/\n?/s', '', $c) ?? $c;
    if ($oldpat) { $c = preg_replace($oldpat, '', $c) ?? $c; }
    
    $found = null;
    foreach ($anchors as $a) { if (strpos($c, $a) !== false) { $found = $a; break; } }
    if ($found === null) return !$critical;
    
    $wrapped = "/* {$key}_START */\n" . $block . "\n/* {$key}_END */";
    $rep = $before ? ($wrapped . "\n" . $found) : ($found . "\n" . $wrapped);
    $c = str_replace($found, $rep, $c);
    if ($c === $orig) { echo "  • $label: بدون تغییر\n"; return true; }
    
    file_put_contents($path, $c);
    echo "  ✓ $label: اعمال شد\n"; return true;
}

patch_file("$MIRZA/index.php", [
    "fix_nameconfig" => function ($c) {
        $c_new = preg_replace('/\$userdate\[[\'"]nameconfig[\'"]\]\s*,\s*\$user\[[\'"]affiliates[\'"]\]/', '($userdate[\'nameconfig\'] ?? \'\'), $user[\'affiliates\']', $c, -1, $count);
        if ($count > 0 && $c_new !== null) { return [$c_new, true, "index.php: رفع باگ سیستم خرید"]; }
        return [$c, false, "index.php: نیازی به رفع باگ نداشت"];
    }
]);

patch_file("$MIRZA/function.php", [
    "require_lib" => function ($c) {
        if (strpos($c, "tetraminator_lib.php") !== false) return [$c, false, "function.php: کتابخانه متصل بود"];
        $c .= "\n\n/* TETRAMINATOR */ require_once __DIR__ . \x27/payment/tetraminator_lib.php\x27;\n";
        return [$c, true, "function.php: اتصال کتابخانه درگاه اضافه شد"];
    },
]);

$menuAnchor = "['text' => \$textbotlang['keyboard']['zarinPalGateway'], 'callback_data' => \"zarinpal\"],\n            ],";
$menuBlock = "            [\n                ['text' => '⚙️', 'callback_data' => \"tmsettings\"],\n                ['text' => ((function_exists('tetra_setting')&&tetra_setting('tetraminatorstatus','offtetraminator')=='ontetraminator')?'🟢 تترامیناتور':'🔴 تترامیناتور'), 'callback_data' => \"tmtoggle\"],\n                ['text' => '💎 تترامیناتور', 'callback_data' => \"tmsettings\"],\n            ],";
$handlersAnchors = ["} elseif (\$text == \$textbotlang['keyboard']['financial'] && \$adminrulecheck['rule'] == \"administrator\") {", "} elseif (\$text == \$textbotlang['keyboard']['financial'] && \$adminrulecheck['rule'] == 'administrator') {", "} elseif (\$text == \$textbotlang['keyboard']['financial']) {"];
$handlers = <<<'PHP'
} elseif ($text == "/tetraminator" || $datain == "tmcharge") {
    if (tetra_setting('tetraminatorstatus','offtetraminator') != 'ontetraminator') { sendmessage($from_id, "درگاه تترامیناتور غیرفعال است.", null, 'HTML'); return; }
    step('tmamount', $from_id);
    sendmessage($from_id, "💎 <b>شارژ کیف پول با تترامیناتور</b>\nمبلغ موردنظر را به «تومان» وارد کنید:", null, 'HTML');
} elseif ($user['step'] == "tmamount") {
    $amount = (int) preg_replace('/\D/', '', $text);
    $min = (int) tetra_setting('tetraminator_min','50000'); $max = (int) tetra_setting('tetraminator_max','100000000');
    if ($amount < $min || $amount > $max) { sendmessage($from_id, "مبلغ باید بین ".number_format($min)." و ".number_format($max)." تومان باشد.", null, 'HTML'); return; }
    step('home', $from_id);
    $tm_order = tetraminatorCreateOrder($from_id, $amount);
    $tm_res = createPayTetraminator($amount, $tm_order);
    if (!empty($tm_res['success']) && !empty($tm_res['data']['payment_url'])) {
        $tm_kb = json_encode(['inline_keyboard'=>[[['text'=>'💳 پرداخت فاکتور','url'=>$tm_res['data']['payment_url']]]]]);
        sendmessage($from_id, "✅ <b>فاکتور پرداخت ایجاد شد</b>\n\n💰 مبلغ: <b>".number_format($amount)." تومان</b>\n\n🕊 پس از پرداخت حساب شما خودکار شارژ می‌شود.", $tm_kb, 'HTML');
    } else { sendmessage($from_id, "❌ ".($tm_res['detail'] ?? 'خطا در ساخت فاکتور'), null, 'HTML'); }
} elseif ($datain == "tmtoggle" && $adminrulecheck['rule'] == "administrator") {
    $cur = tetra_setting('tetraminatorstatus','offtetraminator'); $new = $cur=='ontetraminator'?'offtetraminator':'ontetraminator';
    update("PaySetting","ValuePay",$new,"NamePay","tetraminatorstatus");
    sendmessage($from_id, "وضعیت درگاه تترامیناتور: ".($new=='ontetraminator'?"فعال ✅":"غیرفعال 🔴"), null, 'HTML');
} elseif ($datain == "tmsettings" && $adminrulecheck['rule'] == "administrator") {
    $tm_cfg = "💎 <b>تنظیمات درگاه تترامیناتور</b>\n──────────\nوضعیت: ".(tetra_setting('tetraminatorstatus')=='ontetraminator'?"فعال ✅":"غیرفعال 🔴")."\nآدرس: <code>".tetra_setting('tetraminator_baseurl')."</code>\nکلید API: <code>".substr(tetra_setting('tetraminator_apikey'),0,10)."...</code>\nحداقل/حداکثر: ".number_format((int)tetra_setting('tetraminator_min'))." / ".number_format((int)tetra_setting('tetraminator_max'));
    $tm_kb = json_encode(['inline_keyboard'=>[[['text'=>'تغییر وضعیت','callback_data'=>'tmtoggle']],[['text'=>'ویرایش کلید API','callback_data'=>'tmset_key'],['text'=>'ویرایش آدرس','callback_data'=>'tmset_base']],]]);
    sendmessage($from_id, $tm_cfg, $tm_kb, 'HTML');
} elseif ($datain == "tmset_key" && $adminrulecheck['rule'] == "administrator") { step('tmsetkey', $from_id); sendmessage($from_id, "کلید API جدید تترامیناتور را ارسال کنید:", null, 'HTML');
} elseif ($user['step'] == "tmsetkey") { update("PaySetting","ValuePay",trim($text),"NamePay","tetraminator_apikey"); step('home',$from_id); sendmessage($from_id, "کلید API ذخیره شد ✅", null, 'HTML');
} elseif ($datain == "tmset_base" && $adminrulecheck['rule'] == "administrator") { step('tmsetbase', $from_id); sendmessage($from_id, "آدرس پایه‌ی API را ارسال کنید:", null, 'HTML');
} elseif ($user['step'] == "tmsetbase") { update("PaySetting","ValuePay",rtrim(trim($text),'/'),"NamePay","tetraminator_baseurl"); step('home',$from_id); sendmessage($from_id, "آدرس شد ✅", null, 'HTML');
PHP;
tm_apply("$MIRZA/admin.php", "TETRA_MENU", [$menuAnchor], $menuBlock, false, '/\n[ \t]*\[ \/\* TETRA_MENU \*\/.*?\n[ \t]*\],/s', "admin.php: افزودن دکمه مدیریت درگاه", false);
tm_apply("$MIRZA/admin.php", "TETRA_HANDLERS", $handlersAnchors, $handlers, true, '/\} elseif \(\$text == "\/tetraminator" \|\| \$datain == "tmcharge"\) \{ \/\* TETRA_HANDLERS \*\/.*?\n(?=[ \t]*\} elseif \(\$text == \$textbotlang\[\x27keyboard\x27\]\[\x27financial\x27\])/s', "admin.php: افزودن هندلر منوی ادمین", true);

$kbInsert = "if (function_exists('tetra_setting') && tetra_setting('tetraminatorstatus','offtetraminator') == \"ontetraminator\") { \$step_payment['inline_keyboard'][] = [['text' => tetra_setting('tetraminator_label','درگاه پرداخت ریالی'), 'callback_data' => \"tetraminatorpay\"]]; }";
$kbAnchors = ["\$step_payment['inline_keyboard'][] = [\n    ['text' => \$textbotlang['keyboard']['closeList'], 'callback_data' => \"colselist\"]\n];", "\$step_payment = json_encode(\$step_payment);"];
tm_apply("$MIRZA/keyboard.php", "TETRA_KB", $kbAnchors, $kbInsert, true, '/if \(function_exists\(\x27tetra_setting\x27\)[^\n]*\/\* TETRA_KB \*\/.*?\n\}\n/s', "keyboard.php: افزودن دکمه در کیبورد تراکنش‌ها", true);

$ixHandler = <<<'PHP'
} elseif ($datain == "tetraminatorpay") {
    if (!function_exists('createPayTetraminator')) { sendmessage($from_id, "درگاه در دسترس نیست.", null, 'HTML'); return; }
    $mainbalance = (int) tetra_setting('tetraminator_min', '50000');
    $maxbalance  = (int) tetra_setting('tetraminator_max', '100000000');
    if ($user['Processing_value'] < $mainbalance || $user['Processing_value'] > $maxbalance) {
        $msgErr = isset($textbotlang['extracted']['index_php']['depositAmountRange']) ? strtr($textbotlang['extracted']['index_php']['depositAmountRange'], ['{mainbalance}' => number_format($mainbalance), '{maxbalance}' => number_format($maxbalance)]) : "مبلغ باید بین ".number_format($mainbalance)." و ".number_format($maxbalance)." تومان باشد.";
        sendmessage($from_id, $msgErr, null, 'HTML'); return;
    }
    deletemessage($from_id, $message_id);
    sendmessage($from_id, (isset($textbotlang['users']['Balance']['linkpayments']) ? $textbotlang['users']['Balance']['linkpayments'] : "در حال انتقال به درگاه پرداخت..."), $keyboard, 'HTML');
    
    $randomString = bin2hex(random_bytes(5));
    $tm_res = createPayTetraminator($user['Processing_value'], $randomString);
    if (empty($tm_res['success']) || empty($tm_res['data']['payment_url'])) {
        sendmessage($from_id, (isset($textbotlang['users']['Balance']['errorLinkPayment']) ? $textbotlang['users']['Balance']['errorLinkPayment'] : "خطا در ایجاد فاکتور."), $keyboard, 'HTML');
        step('home', $from_id);
        if (!empty($setting['Channel_Report'])) { telegram('sendmessage', ['chat_id' => $setting['Channel_Report'], 'text' => "🔴 <b>Tetraminator Error:</b>\n<code>" . print_r($tm_res['detail'] ?? $tm_res, true) . "</code>", 'parse_mode' => "HTML"]); }
        return;
    }
    
    $invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
    $dateacc = date('Y/m/d H:i:s');
    $u_val = (int)$user['Processing_value'];
    $u_status = "Unpaid";
    $u_method = "Tetraminator";
    
    $stmt = $connect->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice) VALUES (?,?,?,?,?,?,?)");
