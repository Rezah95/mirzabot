<?php

// Exercise the real confirmation and panel renewal code without live services or config.php.
set_error_handler(static function ($severity, $message, $file, $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require_once dirname(__DIR__) . '/bulk_audience.php';
$textbotlang = require dirname(__DIR__) . '/lang/fa.php';

function expectRenewal(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function renewalSourceBetween(string $source, string $begin, string $end): string
{
    $start = strpos($source, $begin);
    expectRenewal($start !== false, 'Source start not found');
    $finish = strpos($source, $end, $start + strlen($begin));
    expectRenewal($finish !== false, 'Source end not found');
    return substr($source, $start, $finish - $start);
}
$source = file_get_contents(dirname(__DIR__) . '/index.php');
$confirmationSource = 'if' . substr(renewalSourceBetween($source,
    'elseif ($datain == "confirmserivce" || $datain == "confirmserdiscount")',
    "} elseif (preg_match('/changelink_"), strlen('elseif')) . '}';
$functions = file_get_contents(dirname(__DIR__) . '/function.php');
eval(renewalSourceBetween($functions, 'function deductBalance(', 'function claimPaymentPaid('));
$panelSource = file_get_contents(dirname(__DIR__) . '/panels.php');
eval('class RenewalPanel extends RenewalPanelTransport {' . renewalSourceBetween($panelSource,
    '    function extend(', '    function extra_volume(') . '}');

class RenewalPanelTransport
{
    public bool $succeed = true;
    public int $modifications = 0;
    public array $state = ['status' => 'active', 'data_limit' => 107374182400,
        'used_traffic' => 0, 'expire' => 0];

    public function DataUser($panel, $username): array { return $this->state; }
    public function ResetUserDataUsage($username, $panel): array { return ['status' => true]; }
    public function Modifyuser($username, $panel, $data): array
    {
        $this->modifications++;
        if (!$this->succeed) { return ['status' => false, 'msg' => 'Test panel failure']; }
        $this->state = array_replace($this->state, $data);
        return ['status' => true, 'msg' => 'successful'];
    }
}

class RenewalPDO extends PDO
{
    public bool $failExpiryCache = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RenewalStatement($query, $this);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        expectRenewal(str_starts_with($query, 'SHOW COLUMNS FROM invoice'), 'Unexpected schema query');
        return new RenewalStatement($query, $this);
    }
}
class RenewalStatement extends PDOStatement
{
    private int $affected = 0;
    public function __construct(private string $sql, private RenewalPDO $connection) {}
    public function execute(?array $params = null): bool
    {
        if (str_starts_with($this->sql, 'SELECT * FROM product')) { return true; }
        if (str_starts_with($this->sql, 'UPDATE user SET Balance = Balance -')) {
            [$amount, $id, $minBalance] = $params;
            $this->affected = $minBalance === null || $GLOBALS['storedUser']['Balance'] - $amount >= $minBalance ? 1 : 0;
            if ($this->affected) { $GLOBALS['storedUser']['Balance'] -= $amount; }
        } elseif (str_starts_with($this->sql, 'UPDATE user SET Balance = Balance +')) {
            $GLOBALS['storedUser']['Balance'] += $params[0];
        } elseif (str_starts_with($this->sql, 'INSERT IGNORE INTO service_other')) {
            $GLOBALS['renewalRecords'][] = $params;
        } elseif (str_starts_with($this->sql, 'UPDATE invoice SET expires_at')) {
            if ($this->connection->failExpiryCache) { throw new PDOException('Test cache failure'); }
            if (str_contains($this->sql, 'depleted_at = NULL')) {
                $GLOBALS['storedInvoice']['expires_at'] = null;
                $GLOBALS['storedInvoice']['depleted_at'] = null;
            } else {
                $GLOBALS['storedInvoice']['expires_at'] = $params[0];
            }
        } else {
            throw new RuntimeException('Unexpected query: ' . $this->sql);
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0): mixed
    {
        return str_starts_with($this->sql, 'SHOW COLUMNS') ? ['Field' => 'expires_at'] : $GLOBALS['storedProduct'];
    }
    public function rowCount(): int { return $this->affected; }
}
function select($table, $fields = '*', $field = null, $value = null, $mode = 'select')
{
    return match ($table) {
        'user' => $GLOBALS['storedUser'],
        'invoice' => $GLOBALS['storedInvoice'],
        'product' => $GLOBALS['storedProduct'],
        'marzban_panel' => $GLOBALS['storedPanel'],
        'shopSetting' => ['value' => '0'],
        default => throw new RuntimeException('Unexpected table: ' . $table),
    };
}
function update($table, $field, $value, $whereField, $whereValue): void
{
    expectRenewal($table === 'invoice', 'Unexpected update');
    $GLOBALS['storedInvoice'][$field] = $value;
}
function clearSelectCache($table): void {}
function extendMethodKey($method): string { return $method; }
function sendmessage($chatId, $text, $keyboard, $mode): void { $GLOBALS['messages'][] = $text; }
function Editmessagetext($chatId, $messageId, $text, $keyboard): void {}
function jdate($format): string { return '1405/07/03 12:00:00'; }

function renewalRequest(bool $panelSucceeds = true, bool $cacheFails = false): void
{
    global $pdo, $textbotlang, $confirmationSource, $ManagePanel;
    $pdo = new RenewalPDO();
    $pdo->failExpiryCache = $cacheFails;
    $GLOBALS['storedUser'] = ['id' => '123', 'agent' => 'f', 'Balance' => 4809200,
        'pricediscount' => 0, 'Processing_value_four' => '', 'Processing_value' => json_encode([
            'id_invoice' => 'invoice1', 'code_product' => 'product1',
            'data_limit' => 100, 'time' => 30, 'price_product' => 659000,
        ])];
    $GLOBALS['storedInvoice'] = ['id_invoice' => 'invoice1', 'id_user' => '123',
        'username' => 'dotin_1149605', 'name_product' => '100 گیگ یک ماهه',
        'Service_location' => 'Test panel', 'Status' => 'active', 'uuid' => null,
        'expires_at' => time() - 86400, 'depleted_at' => time() - 86400];
    $GLOBALS['storedProduct'] = ['code_product' => 'product1', 'name_product' => '100 گیگ یک ماهه',
        'price_product' => 659000, 'Volume_constraint' => 100, 'Service_time' => 30, 'inbounds' => null];
    $GLOBALS['storedPanel'] = ['name_panel' => 'Test panel', 'code_panel' => 'panel1',
        'type' => 'marzban', 'Methodextend' => 'resetVolumeTime', 'status_extend' => 'on_extend',
        'pricecustomvolume' => '{"f":0}', 'pricecustomtime' => '{"f":0}', 'inbounds' => '{}'];
    $GLOBALS['messages'] = $GLOBALS['renewalRecords'] = [];
    $ManagePanel = new RenewalPanel();
    $ManagePanel->succeed = $panelSucceeds;
    $user = $GLOBALS['storedUser'];
    $from_id = '123';
    $message_id = 456;
    $text_inline = 'Renewal invoice';
    $datain = 'confirmserivce';
    $username = 'test_user';
    $first_name = 'Test';
    $setting = ['scorestatus' => 0, 'Channel_Report' => ''];
    eval($confirmationSource);
}

renewalRequest();
expectRenewal($storedUser['Balance'] === 4150200, 'Incorrect renewal debit');
expectRenewal($ManagePanel->modifications === 1, 'Panel must be renewed once');
expectRenewal(count($renewalRecords) === 1 && $renewalRecords[0][7] === 'paid', 'Paid renewal was not recorded');
expectRenewal($renewalRecords[0][5] === 659000, 'Recorded price differs from invoice');
expectRenewal($storedInvoice['depleted_at'] === null && $storedInvoice['expires_at'] >= time() + 29 * 86400,
    'Renewal did not refresh cached expiry');
expectRenewal($messages === [sprintf($textbotlang['users']['extend']['success'],
    'dotin_1149605', '100 گیگ یک ماهه', '659,000')], 'Success message was not sent');

renewalRequest(false);
expectRenewal($storedUser['Balance'] === 4809200, 'Failed renewal debit was not refunded');
expectRenewal($renewalRecords === [], 'Failed renewal recorded as paid');
expectRenewal($messages === [$textbotlang['users']['extend']['errorSupport']], 'Failure message was not sent');

$oldLog = ini_get('error_log');
$testLog = tempnam(sys_get_temp_dir(), 'mirza-renewal-test-');
ini_set('error_log', $testLog);
try {
    renewalRequest(true, true);
    expectRenewal($storedUser['Balance'] === 4150200 && count($renewalRecords) === 1,
        'Cache failure interrupted a completed renewal');
    expectRenewal(count($messages) === 1 && str_contains($messages[0], 'dotin_1149605'),
        'Cache failure suppressed the success message');
    expectRenewal(str_contains(file_get_contents($testLog), 'Invoice expiry cache update failed'),
        'Cache failure was not logged');
} finally {
    ini_set('error_log', $oldLog);
    unlink($testLog);
}

echo "Renewal flow OK: confirmation, debit, panel update, history, success, refund, cache failure\n";
