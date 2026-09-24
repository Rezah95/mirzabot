<?php

chdir(__DIR__);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
require_once __DIR__ . '/../renewal_reminders.php';

(static function () use ($pdo): void {
    $panel = new ManagePanel();
    $lang = languagechange(dirname(__DIR__));
    renewalReminderRun($pdo,
        static function (array $invoice) use ($panel): ?array {
            $setting = select('marzban_panel', '*', 'name_panel', $invoice['Service_location'], 'select');
            if (!$setting || $setting['status'] === 'disabled') { return null; }
            return $panel->DataUser($invoice['Service_location'], $invoice['username']) ?: null;
        },
        static function (array $invoice, string $message) use ($lang): array {
            $keyboard = json_encode(['inline_keyboard' => [[
                ['text' => $lang['keyboard']['renewService'], 'callback_data' => 'extend_' . $invoice['id_invoice']],
            ]]]);
            return sendmessage($invoice['id_user'], $message, $keyboard, 'HTML', $invoice['bottype'] ?: null);
        },
        $lang['common']['labels']['testServiceName']);
})();
