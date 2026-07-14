<?php

if (!function_exists('input')) {
    function input($key = '', $default = null) { return $default; }
}
if (!function_exists('config')) {
    function config($key = '') {
        if ($key === 'default_account_set_id') return '00000000-0000-0000-0000-000000000101';
        if ($key === 'default_year') return '2026';
        return null;
    }
}
if (!function_exists('getNowTime')) {
    function getNowTime() { return '2026-07-15 10:00:00'; }
}

require_once __DIR__ . '/support/CaseFundTestBootstrap.php';

use app\finance\model\CaseFund;

class CaseFundVoucherPersistenceFactoryProbe extends CaseFund
{
    public function persistenceService() { return $this->voucherPersistenceService(); }
    public function paymentService() { return $this->paymentVoucherGenerationService(); }
    public function refundService() { return $this->refundVoucherGenerationService(); }
    public function unknownPaymentService() { return $this->unknownPaymentVoucherGenerationService(); }
    public function distributionService() { return $this->distributionVoucherGenerationService(); }
}

function voucherPersistenceFactoryAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$probe = new CaseFundVoucherPersistenceFactoryProbe();
$persistence = $probe->persistenceService();
voucherPersistenceFactoryAssertSame(
    $persistence,
    $probe->persistenceService(),
    'CaseFund should cache one voucher persistence service instance'
);

$services = [
    $probe->paymentService(),
    $probe->refundService(),
    $probe->unknownPaymentService(),
    $probe->distributionService(),
];
foreach ($services as $service) {
    $property = new ReflectionProperty(get_class($service), 'persistence');
    $property->setAccessible(true);
    voucherPersistenceFactoryAssertSame(
        $persistence,
        $property->getValue($service),
        'All case-fund voucher generators should share the cached persistence service'
    );
}

echo 'Case fund voucher persistence factory test passed' . PHP_EOL;
