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
function bulkQueueStart(...$args): void { throw new RuntimeException('Unexpected broadcast start'); }

$pdo = new class extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
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
    global $broadcastSource, $textbotlang, $pdo, $from_id;
    $from_id = 123;
    $message_id = 456;
    $user = $GLOBALS['storedUser'];
    $backadmin = $keyboardadmin = json_encode(['keyboard' => [[['text' => 'Back']]]]);
    $GLOBALS['sent'] = [];
    eval($broadcastSource);
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
    foreach (['all', 'nonecustomer', 'customer', 'expired_unrenewed'] as $kind) {
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
                }
                expectBroadcastPrompt('askPin');
                broadcastRequest('typepinmessage-no');
                if ($service !== 'forwardmessage') {
                    expectBroadcastPrompt('askButton');
                    broadcastRequest('btntypemessage-none');
                }
                if ($service === 'xdaynotmessage') {
                    expectBroadcastPrompt('askInactiveDays');
                    broadcastRequest('', '7');
                }
                expectBroadcastPrompt('askText');
                expectBroadcast($GLOBALS['storedUser']['step'] === 'gettextSystemMessage', 'Wrong final step');
                $criteria = bulkBroadcastCriteria($GLOBALS['pdo'], broadcastData());
                expectBroadcast($criteria['kind'] === $kind && $criteria['agent'] === $agent,
                    'Selected audience changed');
                if ($service === 'xdaynotmessage') {
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
restore_error_handler();
if ($failures) {
    throw new RuntimeException(implode("\n", $failures));
}
echo "Broadcast flow: $checks scenarios passed without Telegram or database access\n";
