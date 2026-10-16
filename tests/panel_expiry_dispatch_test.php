<?php
// Isolated dispatcher regression: no configuration, database or provider calls.
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$source = file_get_contents(dirname(__DIR__) . '/panels.php');
eval(substr($source, strpos($source, 'class ManagePanel')));
require dirname(__DIR__) . '/panels/BasePanelDriver.php';
class PanelRegistry {
    public static $result;
    public static function driver($type, $manager) {
        return new class {
            public function __call($name, $args) { return PanelRegistry::$result; }
        };
    }
}
function select($table, ...$args) {
    global $panelType;
    return match ($table) {
        'marzban_panel' => ['type' => $panelType, 'name_panel' => 'test', 'subvip' => 'off', 'version_panel' => '2', 'inbounds' => '{}'],
        'invoice' => ['id_invoice' => 'i1', 'notifctions' => '{"time":true,"volume":true}', 'uuid' => null],
        'product' => ['inbounds' => null],
    };
}
function update(...$args) {}
function extendMethodKey($key) { return $key; }
function bulkCacheInvoiceExpiry($pdo, $sql, $params) { $GLOBALS['writes'][] = [$sql, $params]; }
function bulkRefreshInvoiceExpiry($pdo, $id, $read) { $GLOBALS['refreshes']++; $read(); }
class TestPanelManager extends ManagePanel {
    function DataUser($name_panel, $username) {
        return ['status' => 'active', 'data_limit' => 1024, 'expire' => 2000000000];
    }
}
function check($value) { if (!$value) throw new RuntimeException('Regression check failed'); }
$pdo = null;
$textbotlang = ['users' => ['customSellVolume' => ['btnVolume' => 'custom']]];
$manager = new TestPanelManager;
$count = 0;
foreach (['marzban', 'WGDashboard'] as $panelType) {
    foreach ([true, false, BasePanelDriver::UNSUPPORTED] as $status) {
        PanelRegistry::$result = $status === BasePanelDriver::UNSUPPORTED ? $status : ['status' => $status];
        foreach (['extend', 'extra_volume', 'extra_time'] as $method) {
            $writes = []; $refreshes = 0;
            $result = $method === 'extend'
                ? $manager->extend('addTimeVolumeNextMonth', 1, 0, 'user', 'p1', 'test')
                : $manager->$method('user', 'test', 0);
            check($result['status'] === ($status === true));
            check(count($writes) === ($status === true ? 1 : 0));
            check($refreshes === ($status === true && $panelType !== 'WGDashboard' && $method !== 'extra_volume' ? 1 : 0));
            if ($status === true && $panelType === 'WGDashboard' && $method !== 'extra_volume') check($writes[0][1][0] === 0);
            $count++;
        }
    }
    foreach (['successful', 'Unsuccessful', BasePanelDriver::UNSUPPORTED] as $status) {
        $writes = []; $refreshes = 0;
        PanelRegistry::$result = $status === BasePanelDriver::UNSUPPORTED ? $status : ['status' => $status];
        $result = $manager->createUser('test', 'usertest', 'user', ['expire' => 0, 'data_limit' => 0, 'from_id' => 1, 'username' => 'user', 'type' => 'test']);
        check(count($writes) === ($status === 'successful' ? 1 : 0));
        if ($writes) check($writes[0][1][0] === 0);
        check($result['status'] === ($status === 'successful' ? 'successful' : 'Unsuccessful'));
        $count++;
    }
}
echo "PASS: $count dispatcher cases\n";
