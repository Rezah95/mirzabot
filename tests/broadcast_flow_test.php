<?php

// Run the real broadcast branches across webhook requests without Telegram or a database.
set_error_handler(static function ($severity, $message, $file, $line): void {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require_once dirname(__DIR__) . '/bulk_audience.php';
$textbotlang = require dirname(__DIR__) . '/lang/fa.php';

function expectBroadcast(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function broadcastSourceBetween(string $source, string $begin, string $end): string
{
    $start = strpos($source, $begin);
    expectBroadcast($start !== false, 'Source start not found');
    $finish = strpos($source, $end, $start + strlen($begin));
    expectBroadcast($finish !== false, 'Source end not found');
    return substr($source, $start, $finish - $start);
}
$source = file_get_contents(dirname(__DIR__) . '/admin.php');
$broadcastSource = 'if' . substr(broadcastSourceBetween($source,
    'elseif ($datain == "systemsms")',
    "} elseif (preg_match('/sendmessageuser_"), strlen('elseif')) . '}';
$creditSource = 'if' . substr(broadcastSourceBetween($source,
    "elseif (preg_match('/typebalanceall_",
    "} elseif (preg_match('/^bulkcredit_confirm_"), strlen('elseif')) . '}';
$functions = file_get_contents(dirname(__DIR__) . '/function.php');
eval(broadcastSourceBetween($functions, 'function savedata(', 'function addFieldToTable('));

function select(...$args) { return $GLOBALS['storedUser']; }
function update($table, $field, $value, $whereField, $whereValue): void
{
    expectBroadcast($table === 'user' && $field === 'Processing_value', 'Unexpected write');
    $GLOBALS['storedUser'][$field] = $value;
}
function step($step, $fromId): void { $GLOBALS['storedUser']['step'] = $step; }
function sendmessage($chatId, $text, $keyboard, $parseMode): void
{
    $GLOBALS['sent'][] = ['text' => $text, 'keyboard' => json_decode($keyboard ?? 'null', true)];
}
function Editmessagetext($chatId, $messageId, $text, $keyboard): void
{
    $markup = json_decode($keyboard, true, 512, JSON_THROW_ON_ERROR);
    expectBroadcast(isset($markup['inline_keyboard']) && !isset($markup['keyboard']),
        'editMessageText requires an inline keyboard');
    sendmessage($chatId, $text, $keyboard, 'HTML');
}
function deletemessage(...$args): void {}
function bulkQueueStart(...$args): string {
    $GLOBALS['queued'] = $args;
    return str_repeat('a', 20);
}
function bulkCreditPrepare(...$args): string {
    $GLOBALS['creditPrepared'] = $args;
    return str_repeat('b', 24);
}

$pdo = new class extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_starts_with($query, 'SELECT COUNT(*) FROM (SELECT u.id') || str_starts_with($query, 'SELECT u.id FROM user')) {
            $GLOBALS['audienceSql'] = $query;
            return new class extends PDOStatement {
                public function execute(?array $params = null): bool { $GLOBALS['audienceParams'] = $params; return true; }
                public function fetchColumn(int $column = 0): mixed { return 2; }
                public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return ['101', '102']; }
            };
        }
        expectBroadcast(str_starts_with($query, 'SELECT * FROM marzban_panel'), 'Unexpected query');
        return new class extends PDOStatement {
            private bool $fetched = false;
            public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR,
                int $maxLength = 0, mixed $driverOptions = null): bool { return true; }
            public function execute(?array $params = null): bool { return true; }
            public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT,
                int $offset = 0): mixed
            {
                if ($this->fetched) { return false; }
                $this->fetched = true;
                return ['name_panel' => 'Test panel', 'code_panel' => 'test_panel'];
            }
        };
    }
};
function broadcastRequest(string $datain, string $text = ''): void
{
    global $broadcastSource, $creditSource, $textbotlang, $pdo, $from_id;
    $from_id = 123;
    $message_id = 456;
    $user = $GLOBALS['storedUser'];
    $backadmin = $keyboardadmin = json_encode(['keyboard' => [[['text' => 'Back']]]]);
    $GLOBALS['sent'] = [];
    if (str_starts_with($datain, 'typebalanceall_') || str_starts_with($datain, 'typecustomer_')
        || in_array($user['step'], ['bulk_credit_purchase_days', 'getmeesagestatus'], true)) {
        eval($creditSource);
    } else {
        eval($broadcastSource);
    }
}
function broadcastData(): array { return json_decode($GLOBALS['storedUser']['Processing_value'], true); }
function expectBroadcastPrompt(string $key): void
{
    global $textbotlang, $sent;
    expectBroadcast(count($sent) === 1 && $sent[0]['text'] === $textbotlang['Admin']['messageBulk'][$key],
        'Expected prompt: ' . $key);
}

$failures = [];
$checks = 0;
function broadcastCase(string $name, callable $test): void
{
    global $failures, $checks;
    $GLOBALS['storedUser'] = ['step' => 'home', 'Processing_value' => '0'];
    try { $test(); $checks++; }
    catch (Throwable $e) { $failures[] = $name . ': ' . $e->getMessage(); }
}
foreach (['xdaynotmessage', 'sendmessage', 'forwardmessage'] as $service) {
    foreach (['all', 'nonecustomer', 'customer', 'expired_unrenewed', 'no_purchase_empty_wallet', 'single_purchase_old'] as $kind) {
        foreach (['all', 'f', 'n', 'n2'] as $agent) {
            broadcastCase("$service/$kind/$agent", static function () use ($service, $kind, $agent): void {
                broadcastRequest('typeservice-' . $service);
                broadcastRequest('typeusermessage-' . $kind);
                broadcastRequest('typeagent-' . $agent);
                expectBroadcast(broadcastData()['agent'] === $agent, 'Group was not saved');
                if ($kind === 'customer') {
                    expectBroadcastPrompt('askPanelUsers');
                    broadcastRequest('locationmessage_all');
                } elseif ($kind === 'expired_unrenewed') {
                    expectBroadcastPrompt('askExpiredRange');
                    broadcastRequest('', '7-30');
                } elseif ($kind === 'single_purchase_old') {
                    expectBroadcastPrompt('askPurchaseDays');
                    broadcastRequest('', '۳۰');
                }
                expectBroadcastPrompt('askPin');
                broadcastRequest('typepinmessage-no');
                if ($service !== 'forwardmessage') {
                    expectBroadcastPrompt('askButton');
                    broadcastRequest('btntypemessage-none');
                }
                if ($service === 'xdaynotmessage' && !in_array($kind, ['expired_unrenewed', 'single_purchase_old'], true)) {
                    expectBroadcastPrompt('askInactiveDays');
                    broadcastRequest('', '7');
                }
                expectBroadcastPrompt('askText');
                expectBroadcast($GLOBALS['storedUser']['step'] === 'gettextSystemMessage', 'Wrong final step');
                $criteria = bulkBroadcastCriteria($GLOBALS['pdo'], broadcastData());
                expectBroadcast($criteria['kind'] === $kind && $criteria['agent'] === $agent,
                    'Selected audience changed');
                if ($kind === 'expired_unrenewed') {
                    expectBroadcast(!isset($criteria['inactive_before']), 'Expiry audience has an inactivity filter');
                    expectBroadcast($criteria['days_from'] === 7 && $criteria['days_to'] === 30,
                        'Expiry day range lost');
                } elseif ($kind === 'single_purchase_old') {
                    expectBroadcast(!isset($criteria['inactive_before']) && $criteria['purchase_days'] === 30, 'Purchase days lost or mixed with inactivity');
                } elseif ($service === 'xdaynotmessage') {
                    expectBroadcast(abs($criteria['inactive_before'] - (time() - 7 * 86400)) <= 1,
                        'Inactive day filter lost');
                }
            });
        }
    }
}
foreach (['typeservice-xdaynotmessage', 'typeusermessage-nonecustomer'] as $backAction) {
    broadcastCase('Return from expiry: ' . $backAction, static function () use ($backAction): void {
        $GLOBALS['storedUser'] = ['step' => 'bulk_expired_days', 'Processing_value' => json_encode([
            'typeservice' => 'xdaynotmessage', 'typeusermessage' => 'expired_unrenewed', 'agent' => 'all',
        ])];
        broadcastRequest($backAction);
        if (str_starts_with($backAction, 'typeservice-')) {
            broadcastRequest('typeusermessage-nonecustomer');
        }
        broadcastRequest('typeagent-f');
        expectBroadcastPrompt('askPin');
        broadcastRequest('typepinmessage-no');
        expectBroadcastPrompt('askButton');
        broadcastRequest('btntypemessage-none');
        expectBroadcastPrompt('askInactiveDays');
    });
}
foreach ([null, '7', 'invalid'] as $oldInactiveDays) {
    broadcastCase('Expiry ignores old inactivity days: ' . ($oldInactiveDays ?? 'missing'),
        static function () use ($oldInactiveDays): void {
            $criteria = bulkBroadcastCriteria($GLOBALS['pdo'], [
                'typeservice' => 'xdaynotmessage', 'typeusermessage' => 'expired_unrenewed', 'agent' => 'f',
                'days_from' => 7, 'days_to' => 15, 'daynoyuse' => $oldInactiveDays,
            ]);
            [$sql, $params] = bulkAudienceQuery($criteria, true, 10000000);
            expectBroadcast(!str_contains($sql, 'last_message_time') && !isset($params[':inactive_before']),
                'Expiry recipients were restricted by inactivity');
            expectBroadcast($params[':expired_after'] === 10000000 - 16 * 86400
                && $params[':expired_before'] === 10000000 - 7 * 86400, 'Expiry bounds changed');
        });
}
broadcastCase('Expiry range back button and invalid range', static function (): void {
    broadcastRequest('typeservice-xdaynotmessage');
    broadcastRequest('typeusermessage-expired_unrenewed');
    broadcastRequest('typeagent-all');
    $backAction = $GLOBALS['sent'][0]['keyboard']['inline_keyboard'][0][0]['callback_data'];
    broadcastRequest($backAction);
    broadcastRequest('typeagent-n2');
    expectBroadcastPrompt('askExpiredRange');
    broadcastRequest('', '30-7');
    expectBroadcastPrompt('invalidExpiredRange');
    expectBroadcast($GLOBALS['storedUser']['step'] === 'bulk_expired_days', 'Invalid range lost input step');
    broadcastRequest('', '۷-۳۰');
    expectBroadcastPrompt('askPin');
    expectBroadcast(broadcastData()['agent'] === 'n2' && broadcastData()['days_from'] === 7
        && broadcastData()['days_to'] === 30, 'Range or updated group was lost');
});
broadcastCase('Purchase threshold validation and back navigation', static function (): void {
    broadcastRequest('typeservice-sendmessage');
    broadcastRequest('typeusermessage-single_purchase_old');
    broadcastRequest('typeagent-all');
    foreach (['0', '-1', '3651', '7-30', '1.5', 'garbage'] as $input) {
        broadcastRequest('', $input);
        expectBroadcastPrompt('invalidPurchaseDays');
        expectBroadcast($GLOBALS['storedUser']['step'] === 'bulk_purchase_days', 'Invalid days changed step');
    }
    broadcastRequest('typeusermessage-no_purchase_empty_wallet');
    broadcastRequest('typeagent-f');
    expectBroadcastPrompt('askPin');
    expectBroadcast(broadcastData()['typeusermessage'] === 'no_purchase_empty_wallet', 'Back action lost audience');
});
foreach (['no_purchase_empty_wallet', 'single_purchase_old'] as $kind) {
    foreach (['sendmessage', 'forwardmessage'] as $service) {
        broadcastCase("Preview and queue $kind/$service", static function () use ($kind, $service): void {
            broadcastRequest('typeservice-' . $service);
            expectBroadcast(str_contains(json_encode($GLOBALS['sent']), 'typeusermessage-' . $kind), 'Audience missing from menu');
            broadcastRequest('typeusermessage-' . $kind);
            broadcastRequest('typeagent-all');
            if ($kind === 'single_purchase_old') { broadcastRequest('', '30'); }
            broadcastRequest('typepinmessage-no');
            if ($service !== 'forwardmessage') { broadcastRequest('btntypemessage-none'); }
            broadcastRequest('', 'Offer');
            expectBroadcast(str_contains($GLOBALS['sent'][0]['text'], 'تعداد مخاطبان: 2'), 'Preview count missing');
            if ($kind === 'single_purchase_old') {
                expectBroadcast(str_contains($GLOBALS['sent'][0]['text'], '30'), 'Threshold missing from preview');
            }
            $previewSql = $GLOBALS['audienceSql'];
            broadcastRequest('startaction_' . broadcastData()['preview_token']);
            expectBroadcast($GLOBALS['queued'][1] === ['101', '102'], 'Queue recipients lost');
            expectBroadcast(str_contains($previewSql, $GLOBALS['audienceSql']), 'Preview and queue queries differ');
        });
    }
    broadcastCase('Credit audience ' . $kind, static function () use ($kind): void {
        $GLOBALS['storedUser']['Processing_value'] = json_encode(['price' => '1000']);
        broadcastRequest('typebalanceall_n');
        expectBroadcast(str_contains(json_encode($GLOBALS['sent']), 'typecustomer_' . $kind), 'Credit menu missing audience');
        broadcastRequest('typecustomer_' . $kind);
        if ($kind === 'single_purchase_old') {
            expectBroadcastPrompt('askPurchaseDays');
            broadcastRequest('', '0');
            expectBroadcastPrompt('invalidPurchaseDays');
            broadcastRequest('', '٣٠');
        }
        expectBroadcast($GLOBALS['storedUser']['step'] === 'getmeesagestatus', 'Credit notification step missing');
        broadcastRequest('', '1');
        expectBroadcast($GLOBALS['creditPrepared'][2] === 1000 && $GLOBALS['creditPrepared'][3] === ['101', '102'], 'Credit preview recipients or amount lost');
        expectBroadcast($GLOBALS['audienceParams'][':audience_agent'] === 'n', 'Credit group lost');
        expectBroadcast(!str_contains($GLOBALS['audienceSql'], 'User_Status'), 'Existing credit status policy changed');
        if ($kind === 'single_purchase_old') {
            expectBroadcast(isset($GLOBALS['audienceParams'][':purchase_cutoff']), 'Credit cutoff lost');
        }
    });
}
restore_error_handler();
if ($failures) {
    throw new RuntimeException(implode("\n", $failures));
}
echo "Broadcast flow: $checks scenarios passed without Telegram or database access\n";
