<?php
// Real DirectPayment and balance helpers; isolated in-memory database/panel doubles.
set_error_handler(static function ($level, $message, $file, $line) {
    if (error_reporting() & $level) { throw new ErrorException($message, 0, $level, $file, $line); }
    return false;
});
function checkPayment(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function clearSelectCache($table): void {}
function sendmessage(...$args): void {}
function telegram(...$args): void {}
function sendMessageService(...$args): void {}
function usernameMethodKey($value): string { return "numericIdRandom"; }
function jdate(...$args): string { return 'test date'; }
function update($table, $field, $value, $key, $id): void { $GLOBALS['rows'][$table][$field] = $value; }
function select($table, $fields, $key = null, $value = null, $mode = null) {
    if ($table === 'topicid') { return ['idreport' => 1]; }
    if ($table === 'admin') { return []; }
    if ($table === 'setting') { return ['Channel_Report' => '', 'scorestatus' => 0]; }
    if ($table === 'affiliates') { return ['status_commission' => 'off']; }
    if ($table === 'shopSetting') { return ['value' => '0']; }
    if ($table === 'invoice' && $key === 'id_payment') { return $GLOBALS['kind'] === 'buy' ? $GLOBALS['rows']['invoice'] : false; }
    if ($table === 'service_other' && $key === 'id_payment') { return in_array($GLOBALS['kind'], ['buy', 'topup']) ? false : $GLOBALS['rows']['service_other']; }
    return $GLOBALS['rows'][$table] ?? false;
}
function discountConsume(...$args): bool { $GLOBALS['consumed']++; return $GLOBALS['couponAllowed']; }
function discountRelease(...$args): void { $GLOBALS['released']++; }
class PaymentMergePDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new PaymentMergeStatement($query); }
}
class PaymentMergeStatement extends PDOStatement {
    private int $affected = 1;
    public function __construct(private string $sql) {}
    public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool { return true; }
    public function execute(?array $params = null): bool {
        if (str_starts_with($this->sql, 'UPDATE user SET Balance = Balance +')) { $GLOBALS['rows']['user']['Balance'] += $params[0]; }
        elseif (str_starts_with($this->sql, 'UPDATE user SET Balance = Balance -')) {
            $this->affected = $GLOBALS['rows']['user']['Balance'] >= $params[0] ? 1 : 0;
            if ($this->affected) { $GLOBALS['rows']['user']['Balance'] -= $params[0]; }
        } elseif (str_contains($this->sql, 'SET fulfillment_status')) { $GLOBALS['fulfillment'] = $params[0]; }
        elseif (str_starts_with($this->sql, 'UPDATE invoice SET Status')) { $GLOBALS['rows']['invoice']['Status'] = 'active'; }
        elseif (str_starts_with($this->sql, 'UPDATE user SET Processing_value')) { $GLOBALS['rows']['user']['Processing_value_four'] = '0'; }
        elseif (!str_starts_with($this->sql, 'SELECT * FROM product') && !str_starts_with($this->sql, 'SELECT * FROM invoice')) { throw new RuntimeException('Unexpected SQL: ' . $this->sql); }
        return true;
    }
    public function rowCount(): int { return $this->affected; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return ['code_product' => 'p', 'name_product' => 'Plan', 'Service_time' => 30, 'Volume_constraint' => 10, 'price_product' => 100]; }
}
class PaymentMergePanel {
    public bool $succeed = false;
    public int $calls = 0;
    public function __call($name, $args) { $this->calls++; return ['status' => $this->succeed, 'msg' => 'fixture'] + ($this->succeed ? ['username' => 'test', 'configs' => [], 'subscription_url' => 'fixture'] : []); }
}
$source = file_get_contents(dirname(__DIR__) . '/function.php');
$start = strpos($source, 'function deductBalance(');
$end = strpos($source, "\nfunction ", strpos($source, 'function DirectPayment('));
eval(substr($source, $start, $end - $start));
$textbotlang = require dirname(__DIR__) . '/lang/fa.php';
$pdo = new PaymentMergePDO(); $keyboard = null; $from_id = 1; $message_id = 0; $Confirm_pay = null;
$cases = 0;
foreach (['buy', 'extend_user', 'extra_user', 'extra_time_user'] as $kind) {
    foreach ([60, 100, 130] as $paid) {
        foreach (['failure', 'insufficient', 'coupon', 'success'] as $scenario) {
            if (($scenario === 'coupon' && !in_array($kind, ['buy', 'extend_user']))) { continue; }
            $initial = $scenario === 'insufficient' ? 0 : 40;
            $rows = [
                'Payment_report' => ['id_order' => 'pay', 'id_user' => 1, 'price' => $paid, 'Payment_Method' => 'test'],
                'user' => ['id' => 1, 'Balance' => $initial, 'agent' => 'f', 'username' => 'test', 'number' => '0', 'Processing_value_four' => 'dis_coupon'],
                'invoice' => ['id_invoice' => 'inv', 'id_user' => 1, 'Status' => 'unpaid', 'price_product' => 100, 'name_product' => 'Plan', 'Service_location' => 'panel', 'username' => 'test', 'Service_time' => 30, 'Volume' => 10],
                'service_other' => ['id' => 7, 'type' => $kind, 'status' => 'unpaid', 'price' => 100, 'username' => 'test', 'value' => json_encode(['code_product' => 'p', 'volume_value' => 10, 'day' => 5])],
                'marzban_panel' => ['name_panel' => 'panel', 'code_panel' => 'panel', 'Methodextend' => 'reset', 'config' => 'off', 'sublink' => 'off', 'type' => 'marzban', 'MethodUsername' => 'numericIdRandom'],
            ];
            $ManagePanel = new PaymentMergePanel(); $ManagePanel->succeed = $scenario === 'success';
            $consumed = $released = 0; $couponAllowed = $scenario !== 'coupon'; $fulfillment = null;
            $result = DirectPayment('pay');
            $success = $scenario === 'success';
            checkPayment($result === $success, "$kind/$paid/$scenario result");
            checkPayment($rows['user']['Balance'] === $initial + $paid - ($success ? 100 : 0), "$kind/$paid/$scenario balance");
            checkPayment($fulfillment === ($success ? 'fulfilled' : 'refunded'), "$kind/$paid/$scenario fulfillment");
            if ($initial < max(0, 100 - $paid)) { checkPayment($ManagePanel->calls === 0 && $consumed === 0, 'Insufficient funds reached provider'); }
            elseif ($scenario === 'coupon') { checkPayment($ManagePanel->calls === 0 && $consumed === 1, 'Rejected coupon reached provider'); }
            elseif (in_array($kind, ['buy', 'extend_user'])) { checkPayment($consumed === 1 && $released === ($success ? 0 : 1), 'Coupon consumption/release'); }
            if ($success) { checkPayment($kind === 'buy' ? $rows['invoice']['Status'] === 'active' : $rows['service_other']['status'] === 'paid', 'Wrong order updated'); }
            $cases++;
        }
    }
}
// Wallet top-up and a separately paid invoice whose linked service is already delivered.
foreach (['topup', 'buy'] as $kind) {
    $rows['user']['Balance'] = 40;
    $rows['Payment_report']['price'] = 60;
    $rows['invoice']['Status'] = 'active';
    $ManagePanel = new PaymentMergePanel();
    $consumed = $released = 0;
    $result = DirectPayment('pay');
    checkPayment($rows['user']['Balance'] === 100 && $ManagePanel->calls === 0 && $consumed === 0, 'Top-up/already-delivered settlement');
    checkPayment($result === ($kind === 'topup') && $fulfillment === ($kind === 'topup' ? 'fulfilled' : 'refunded'), 'Top-up/already-delivered result');
    $cases++;
}
echo "Direct payment merge: $cases cases passed (local doubles, no live services)\n";
