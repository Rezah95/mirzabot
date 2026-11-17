<?php
// Exercise the real shared renewal path without database or provider requests.
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$source = file_get_contents(dirname(__DIR__) . '/panels.php');
eval(substr($source, strpos($source, 'class ManagePanel')));
require dirname(__DIR__) . '/panels/BasePanelDriver.php';
class PanelRegistry {
    public static function driver($type, $manager) {
        return new class {
            public function extendService($vars) {
                $GLOBALS['published'] = $vars;
                return ['status' => true];
            }
        };
    }
}
function select($table, ...$args) {
    return match ($table) {
        'marzban_panel' => ['type' => 'marzban', 'name_panel' => 'test', 'inbounds' => '{}'],
        'product' => ['inbounds' => null],
        'invoice' => ['id_invoice' => 'i1', 'uuid' => null],
    };
}
function update(...$args) {}
function extendMethodKey($key) { return $key; }
function bulkCacheInvoiceExpiry(...$args) {}
function bulkRefreshInvoiceExpiry(...$args) {}
class RenewalManager extends ManagePanel {
    public $limit = 0;
    public $used = 250 * 1073741824;
    public $resets = 0;
    public $resetOk = true;
    function DataUser($panel, $username) {
        return ['status' => 'active', 'data_limit' => $this->limit,
            'used_traffic' => $this->used, 'expire' => time() + 10 * 86400];
    }
    function ResetUserDataUsage($username, $panel) {
        $this->resets++;
        if ($this->resetOk) { $this->used = 0; }
        return ['status' => $this->resetOk, 'msg' => 'fixture'];
    }
}
function expectRenewal($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
$pdo = null;
foreach (['resetTimeAddVolume', 'addTimeVolumeNextMonth'] as $method) {
    foreach ([0, 400 * 1073741824] as $oldLimit) {
        $manager = new RenewalManager();
        $manager->limit = $oldLimit;
        $published = null;
        $start = time();
        $result = $manager->extend($method, 100, 30, 'user', 'product', 'panel');
        expectRenewal($result['status'], 'Renewal failed');
        $remaining = $published['data_limit_new'] - $manager->used;
        expectRenewal($remaining == ($oldLimit == 0 ? 100 : 250) * 1073741824, 'Purchased quota or positive remainder lost');
        expectRenewal($manager->resets === ($oldLimit == 0 ? 1 : 0), 'Unexpected usage reset');
        $expectedDays = $method === 'resetTimeAddVolume' ? 30 : 40;
        expectRenewal(abs($published['time_new'] - ($start + $expectedDays * 86400)) <= 2, 'Time policy changed');
    }
    $manager = new RenewalManager();
    $manager->resetOk = false;
    $published = null;
    expectRenewal(!$manager->extend($method, 100, 30, 'user', 'product', 'panel')['status'] && $published === null,
        'Failed reset still published the finite quota');
    $manager = new RenewalManager();
    $manager->extend($method, 0, 30, 'user', 'product', 'panel');
    expectRenewal($published['data_limit_new'] == 0 && $manager->resets === 0, 'Unlimited renewal changed');
}
echo "PASS: unlimited-to-finite quota, finite carry-over, time policy, reset failure and unlimited renewal\n";

// Hiddify resets usage in the modification payload rather than resetUsage().
require dirname(__DIR__) . '/panels/Drivers/Hiddify.php';
class RenewalHiddifyDriver extends HiddifyPanelDriver {
    protected function applyModify($username, $name_panel, $data) { return $data; }
}
$driver = new RenewalHiddifyDriver(new RenewalManager());
foreach (['resetTimeAddVolume', 'addTimeVolumeNextMonth'] as $method) {
    $manager = new RenewalManager();
    $manager->extend($method, 100, 30, 'user', 'product', 'panel');
    $payload = $driver->extendService($published);
    expectRenewal($payload['current_usage_GB'] === '0' && $payload['usage_limit_GB'] == 100,
        'Hiddify retained unlimited usage');
}
echo "PASS: Hiddify reset payload\n";

// Carry-over must never turn a newly purchased unlimited plan into a finite one.
foreach ([0, 400 * 1073741824] as $oldLimit) {
    foreach ([0, 100] as $newLimit) {
        $manager = new RenewalManager();
        $manager->limit = $oldLimit;
        $manager->extend('addTimeConvertVolume', $newLimit, 30, 'user', 'product', 'panel');
        $expected = $newLimit === 0 ? 0 : ($oldLimit === 0 ? 100 : 250) * 1073741824;
        expectRenewal($published['data_limit_new'] == $expected, 'Carry-over changed unlimited or finite quota');
        expectRenewal($manager->resets === 1, 'Carry-over did not reset usage');
    }
}
echo "PASS: unlimited and finite carry-over renewals\n";
