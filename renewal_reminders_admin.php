<?php

require_once __DIR__ . '/renewal_reminders.php';

function renewalReminderMenu(PDO $pdo): array
{
    $s = renewalReminderSettings($pdo);
    $status = (int) $s['enabled'] ? '✅ فعال' : '❌ غیرفعال';
    $discount = (int) $s['discount_send'] ? 'در پیام شمارهٔ ' . $s['discount_send'] : 'غیرفعال';
    $value = discountValueLabel(['price' => $s['discount_value'], 'discount_mode' => $s['discount_mode']]);
    $text = "⏰ یادآوری تمدید — $status\n\n"
        . "برای هر سرویس جداگانه، هر {$s['interval_days']} روز، حداکثر {$s['max_sends']} پیام.\n"
        . "اولین پیام {$s['interval_days']} روز پس از پایان زمان یا حجم سرویس ارسال می‌شود.\n"
        . "با تمدید همان سرویس، یادآوری آن متوقف می‌شود.\n"
        . ((int) $s['include_existing'] ? "سرویس‌های قبلاً تمام‌شده هم مشمول ارسال هستند.\n\n"
            : "این تنظیم برای سرویس‌هایی اعمال می‌شود که از زمان اولین فعال‌سازی تمام می‌شوند.\n\n")
        . "🎁 تخفیف خودکار: $discount\nمقدار: $value\nاعتبار: {$s['discount_valid_days']} روز\n"
        . "کد اختصاصی همان کاربر، یک‌بارمصرف و قابل استفاده برای تمدید است.\n"
        . "تغییر تنظیمات، تعداد پیام‌های قبلی را صفر نمی‌کند.";
    $stats = $pdo->query("SELECT COALESCE(SUM(confirmed_count), 0) AS sent,
        COALESCE(SUM(status IN ('blocked', 'uncertain', 'sending')), 0) AS errors FROM renewal_reminder_state")->fetch(PDO::FETCH_ASSOC);
    $text .= "\n\nارسال موفق: {$stats['sent']} | سرویس با خطای ارسال: {$stats['errors']}";
    $keyboard = ['inline_keyboard' => [
        [['text' => (int) $s['enabled'] ? 'غیرفعال کردن' : 'فعال کردن', 'callback_data' => 'renewal_enabled_' . ((int) $s['enabled'] ? '0' : '1')]],
        [['text' => 'فاصلهٔ ارسال (روز)', 'callback_data' => 'renewal_edit_interval_days'], ['text' => 'تعداد پیام‌ها', 'callback_data' => 'renewal_edit_max_sends']],
        [['text' => 'سرویس‌های قبلی: ' . ((int) $s['include_existing'] ? 'بله' : 'خیر'), 'callback_data' => 'renewal_existing_' . ((int) $s['include_existing'] ? '0' : '1')]],
        [['text' => '✏️ متن پیام', 'callback_data' => 'renewal_edit_message_template'], ['text' => '👁 پیش‌نمایش', 'callback_data' => 'renewal_preview']],
        [['text' => 'نوبت ارسال تخفیف', 'callback_data' => 'renewal_edit_discount_send']],
        [['text' => 'تخفیف درصدی', 'callback_data' => 'renewal_edit_discount_percent'], ['text' => 'تخفیف ثابت', 'callback_data' => 'renewal_edit_discount_fixed']],
        [['text' => 'اعتبار کد (روز)', 'callback_data' => 'renewal_edit_discount_valid_days']],
        [['text' => 'بازگشت', 'callback_data' => 'systemsms']],
    ]];
    return [$text, json_encode($keyboard)];
}

function renewalReminderAdminHandle(string $datain, string $text, array $user): bool
{
    global $pdo, $from_id, $message_id, $textbotlang;
    $isInput = str_starts_with($user['step'] ?? '', 'renewal_input_') && $datain === '';
    if ($isInput && in_array($text, [$textbotlang['Admin']['backAdminBtn'], $textbotlang['Admin']['backMenuBtn']], true)) {
        step('home', $from_id);
        return false;
    }
    if (!str_starts_with($datain, 'renewal_') && !$isInput) { return false; }
    $back = json_encode(['inline_keyboard' => [[['text' => 'بازگشت به یادآوری تمدید', 'callback_data' => 'renewal_menu']]]]);
    try {
        if (preg_match('/^renewal_enabled_([01])$/', $datain, $match)) {
            renewalReminderSave($pdo, ['enabled' => (int) $match[1]]);
        } elseif (preg_match('/^renewal_existing_([01])$/', $datain, $match)) {
            renewalReminderSave($pdo, ['include_existing' => (int) $match[1]]);
        } elseif ($datain === 'renewal_preview') {
            $s = renewalReminderSettings($pdo);
            $now = time();
            $number = (int) $s['discount_send'] ?: 1;
            $coupon = (int) $s['discount_send'] ? ['codeDiscount' => 'samplecode', 'price' => $s['discount_value'],
                'discount_mode' => $s['discount_mode'], 'time' => $now + (int) $s['discount_valid_days'] * 86400] : null;
            $invoice = ['username' => 'example_user', 'name_product' => 'سرویس نمونه', 'expires_at' => $now - $number * (int) $s['interval_days'] * 86400];
            sendmessage($from_id, "پیش‌نمایش — کد نمونه قابل استفاده نیست:\n\n" . renewalReminderRender($s, $invoice, $number, $now, $coupon), $back, 'HTML');
            step('home', $from_id);
            return true;
        } elseif (str_starts_with($datain, 'renewal_edit_')) {
            $field = substr($datain, strlen('renewal_edit_'));
            $prompts = [
                'interval_days' => 'فاصلهٔ پیام‌ها را به روز بفرستید (۱ تا ۳۶۵). مثال: 3',
                'max_sends' => 'حداکثر تعداد پیام برای هر دورهٔ انقضای سرویس را بفرستید (۱ تا ۳۰). مثال: 3',
                'message_template' => "متن پیام را بفرستید (حداکثر ۲۵۰۰ نویسه، متن ساده).\nمتغیرها:\n{username} نام کاربری سرویس\n{service} نام محصول\n{days} روزهای گذشته از پایان سرویس\n{send_number} شمارهٔ پیام\n{discount} مشخصات کد تخفیف\nاگر متغیر تخفیف را نگذارید، در نوبت انتخابی مشخصات کد به انتهای پیام اضافه می‌شود.",
                'discount_send' => 'کد تخفیف در پیام چندم ارسال شود؟ عددی از ۱ تا تعداد پیام‌ها بفرستید؛ صفر یعنی غیرفعال.',
                'discount_percent' => 'درصد تخفیف خودکار را بفرستید (۱ تا ۱۰۰).',
                'discount_fixed' => 'مبلغ ثابت تخفیف خودکار را به تومان بفرستید (۱ تا ۱۰۰٬۰۰۰٬۰۰۰).',
                'discount_valid_days' => 'مدت اعتبار کد تخفیف را به روز بفرستید (۱ تا ۳۶۵).',
            ];
            if (!isset($prompts[$field])) { return true; }
            Editmessagetext($from_id, $message_id, $prompts[$field], $back);
            step('renewal_input_' . $field, $from_id);
            return true;
        } elseif ($isInput) {
            $field = substr($user['step'], strlen('renewal_input_'));
            if ($field === 'message_template') {
                $changes = [$field => $text];
            } else {
                $value = filter_var($text, FILTER_VALIDATE_INT);
                if ($value === false) { throw new InvalidArgumentException('Expected integer'); }
                $changes = in_array($field, ['discount_percent', 'discount_fixed'], true)
                    ? ['discount_mode' => substr($field, strlen('discount_')), 'discount_value' => $value]
                    : [$field => $value];
            }
            renewalReminderSave($pdo, $changes);
        } elseif ($datain !== 'renewal_menu') {
            return true;
        }
        step('home', $from_id);
        [$menuText, $keyboard] = renewalReminderMenu($pdo);
        if ($datain === '') { sendmessage($from_id, $menuText, $keyboard, 'HTML'); }
        else { Editmessagetext($from_id, $message_id, $menuText, $keyboard); }
    } catch (InvalidArgumentException $e) {
        sendmessage($from_id, 'مقدار نامعتبر است. نوبت تخفیف باید کمتر یا مساوی تعداد پیام‌ها باشد؛ برای حذف تخفیف، نوبت را صفر کنید.', $back, 'HTML');
    } catch (Throwable $e) {
        error_log('Reminder settings failed: ' . $e->getMessage());
        sendmessage($from_id, 'ذخیره یا خواندن تنظیمات انجام نشد. وضعیت آپدیت دیتابیس و لاگ خطا را بررسی کنید.', $back, 'HTML');
    }
    return true;
}
