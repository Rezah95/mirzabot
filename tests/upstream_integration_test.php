<?php
// Exercise actual driver and callback lookup code without provider requests.
set_error_handler(static function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
function expectUpstream($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class ManagePanel {}
require dirname(__DIR__) . '/panels/BasePanelDriver.php';
require dirname(__DIR__) . '/panels/Drivers/XuiSingle.php';
function get_clinets(...$args) { return $GLOBALS['clientResponse']; }
function updateClient($panel, $username, $data) { $GLOBALS['payload'] = $data; return ['status' => 200, 'body' => '{"success":true}']; }
function attach_service(...$args) {}
$client = ['totalGB' => 123, 'expiryTime' => 456, 'limitIp' => 2, 'limitHwid' => 3, 'flow' => 'flow', 'comment' => 'note', 'tgId' => 42, 'group' => 'premium', 'reset' => 0];
$clientResponse = ['body' => json_encode(['obj' => ['client' => $client]])];
$driver = new XuiSinglePanelDriver(new ManagePanel());
$result = $driver->modify(['username' => 'fixture', 'config' => ['totalGB' => 999, 'enable' => false], 'Get_Data_Panel' => ['inbounds' => '[1]']]);
expectUpstream($result['status'] && $payload['totalGB'] === 999 && $payload['enable'] === false, 'XUI changes lost');
foreach (['expiryTime', 'limitIp', 'limitHwid', 'flow', 'comment', 'tgId', 'group', 'reset'] as $key) {
    expectUpstream($payload[$key] === $client[$key], 'XUI metadata lost: ' . $key);
}
$clientResponse = ['body' => '{}'];
expectUpstream(!$driver->modify(['username' => 'fixture', 'config' => [], 'Get_Data_Panel' => []])['status'], 'Missing XUI client accepted');

class LookupFixture {
    public $rows;
    public $query;
    public $slug;
    function prepare($query) { $this->query = $query; return $this; }
    function execute($params) { $this->slug = $params[0]; }
    function fetch($mode) {
        expectUpstream(str_contains($this->query, "Payment_Method = 'variza'"), 'Gateway filter missing');
        $field = str_contains($this->query, 'WHERE id_invoice') ? 'id_invoice' : 'dec_not_confirmed';
        foreach ($this->rows as $row) { if ($row['Payment_Method'] === 'variza' && $row[$field] === $this->slug) { return $row; } }
        return false;
    }
}
$source = file_get_contents(dirname(__DIR__) . '/payment/variza_webhook.php');
$start = strpos($source, '// Variza stores slug');
$lookup = substr($source, $start, strpos($source, '// Already settled', $start) - $start);
$slug = 'shared-slug';
foreach (['dec_not_confirmed', 'id_invoice'] as $field) {
    $pdo = new LookupFixture();
    $pdo->rows = [
        ['Payment_Method' => 'other', 'dec_not_confirmed' => $slug, 'id_invoice' => $slug, 'id_order' => 'wrong'],
        ['Payment_Method' => 'variza', 'dec_not_confirmed' => 'different', 'id_invoice' => 'different', 'id_order' => 'right'],
    ];
    $pdo->rows[1][$field] = $slug;
    eval($lookup);
    expectUpstream($payment['id_order'] === 'right', 'Variza selected another gateway');
}
echo "PASS: XUI preserves metadata and Variza isolates primary/fallback lookup by gateway\n";
