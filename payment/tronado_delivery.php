<?php

require_once __DIR__ . '/tronado_lib.php';

/** Shared by the post-response FPM worker and the cron recovery worker. */
function tronadoDeliverPayment(PDO $pdo, string $paymentId): bool
{
    return tronadoFulfillQueued($pdo, $paymentId, static function (array $order) use ($pdo) {
        global $from_id, $message_id, $textbotlang, $ManagePanel, $keyboard, $Confirm_pay, $keyboardextendfnished;
        $from_id = $order['id_user'];
        $message_id = 0;
        require_once dirname(__DIR__) . '/panels.php';
        ini_set('error_log', __DIR__ . '/error_log');
        require_once dirname(__DIR__) . '/jdf.php';
        // Load the shared fulfillment keyboard in the authenticated payer context.
        require_once dirname(__DIR__) . '/keyboard.php';
        $textbotlang = languagechange();
        $ManagePanel = new ManagePanel();
        return DirectPayment($order['id_order'], dirname(__DIR__) . '/images.jpg');
    }, static function (array $order): void {
        global $textbotlang;
        $cashback = getPaySettingValue('tronado_cashback', '0');
        $buyer = select('user', '*', 'id', $order['id_user'], 'select');
        if (is_numeric($cashback) && (float) $cashback > 0 && $buyer) {
            $amount = ((float) $order['price'] * (float) $cashback) / 100;
            addBalance($buyer['id'], $amount);
            sendmessage($buyer['id'], sprintf($textbotlang['paymentGateway']['giftReport'], $amount), null, 'HTML');
        }
        $setting = select('setting', '*');
        if (!empty($setting['Channel_Report'])) {
            $topic = select('topicid', 'idreport', 'report', 'paymentreport', 'select')['idreport'] ?? null;
            $report = telegram('sendmessage', [
                'chat_id' => $setting['Channel_Report'],
                'message_thread_id' => $topic,
                'text' => sprintf($textbotlang['paymentGateway']['reportTronado'],
                    htmlspecialchars((string) ($buyer['username'] ?? $order['id_user']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $order['id_user'], number_format((float) $order['price'])),
                'parse_mode' => 'HTML',
            ]);
            if (empty($report['ok'])) {
                error_log('Tronado report failed for ' . $order['id_order'] . '; Telegram code=' . (int) ($report['error_code'] ?? 0));
            }
        }
    });
}
