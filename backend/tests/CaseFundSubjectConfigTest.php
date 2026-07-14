<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$policyPath = __DIR__ . '/../app/finance/service/casefund/policy/CaseFundBusinessPolicy.php';
$ddlPath = __DIR__ . '/../database/court_finance_ddl.sql';
$patchPath = __DIR__ . '/../database/finance_standard_patch.sql';
$accountSetPresetPath = __DIR__ . '/../database/account_sets_preset.sql';

$source = file_get_contents($modelPath);
$policySource = file_get_contents($policyPath);
$ddl = file_get_contents($ddlPath);
$patch = file_get_contents($patchPath);
$accountSetPreset = file_get_contents($accountSetPresetPath);

foreach (['subjectConfigList', 'subjectConfigSave'] as $action) {
    if (strpos($source, "case '{$action}'") === false) {
        fwrite(STDERR, "CaseFund should route {$action}" . PHP_EOL);
        exit(1);
    }
}

if (strpos($ddl, 'create table fin_case_fund_subject_config') === false) {
    fwrite(STDERR, 'DDL should create fin_case_fund_subject_config' . PHP_EOL);
    exit(1);
}

if (strpos($ddl, 'biz_type varchar(50) not null') === false || strpos($ddl, 'voucher_biz_type varchar(30) not null') === false) {
    fwrite(STDERR, 'Subject config should bind account set business type and voucher business type' . PHP_EOL);
    exit(1);
}

if (strpos($ddl, 'business_item_type varchar(100) not null') === false) {
    fwrite(STDERR, 'Subject config should bind payment business type or refund out type' . PHP_EOL);
    exit(1);
}

if (strpos($ddl, 'generate_voucher_by_day_flag tinyint not null default 1') === false) {
    fwrite(STDERR, 'Account set DDL should default daily voucher generation on' . PHP_EOL);
    exit(1);
}

if (strpos($source, 'allowedSubjectConfigItems') === false || strpos($source, 'validateVoucherSubjectCode') === false) {
    fwrite(STDERR, 'CaseFund should validate allowed config item and voucher subjects' . PHP_EOL);
    exit(1);
}

if (strpos($source, 'subjectConfigPaymentBusinessTypes') === false) {
    fwrite(STDERR, 'Subject config payment types should be separated from import aliases' . PHP_EOL);
    exit(1);
}

foreach ([
    "'执行、调节款'",
    "'执行、调节款发放'",
] as $deprecatedAlias) {
    if (strpos($source . "\n" . $policySource, $deprecatedAlias) !== false) {
        fwrite(STDERR, 'Old typo compatibility alias should not appear in case fund subject config code: ' . $deprecatedAlias . PHP_EOL);
        exit(1);
    }
}

if (strpos($policySource, "'UNKNOWN_PAYMENT'") === false || strpos($policySource, "['不明款']") === false) {
    fwrite(STDERR, 'Subject config should expose unknown payment config item' . PHP_EOL);
    exit(1);
}

if (strpos($policySource, "'DISTRIBUTION'") === false || strpos($policySource, 'allowedDistributionInvoiceTypes') === false) {
    fwrite(STDERR, 'Subject config should expose distribution config items' . PHP_EOL);
    exit(1);
}

if (
    strpos($policySource, "'CASE_FUND' => ['缴费登记']") === false ||
    strpos($policySource, "'LITIGATION_FEE' => ['缴费登记']") === false
) {
    fwrite(STDERR, 'Payment subject config should only expose the payment-register default config item' . PHP_EOL);
    exit(1);
}

foreach ([
    'voucherSubjectConfigKey',
    "'诉讼费直接结算' => '缴费登记'",
    "'诉讼费预收' => '缴费登记'",
    "'诉讼费收款' => '缴费登记'",
    "'执行、调解款' => '缴费登记'",
    "'出账查询'",
    "'诉讼费退费' => '出账查询'",
    "'执行、调解款发放' => '出账查询'",
] as $needle) {
    if (strpos($policySource, $needle) === false) {
        fwrite(STDERR, 'Business import types should map to voucher default subject config items: ' . $needle . PHP_EOL);
        exit(1);
    }
}

if (
    strpos($source, 'generate_voucher_by_day_flag') === false ||
    strpos($source, 'subjectConfigAccountSetFlag') === false ||
    strpos($source, 'saveSubjectConfigAccountSetFlag') === false
) {
    fwrite(STDERR, 'CaseFund should read and save the account-set daily voucher flag' . PHP_EOL);
    exit(1);
}

if (strpos($source, "logAudit('CASE_FUND_SUBJECT_CONFIG'") === false) {
    fwrite(STDERR, 'Subject config saves should write audit log' . PHP_EOL);
    exit(1);
}

if (strpos($patch, 'create table if not exists fin_case_fund_subject_config') === false) {
    fwrite(STDERR, 'Finance standard patch should create subject config table idempotently' . PHP_EOL);
    exit(1);
}

if (strpos($patch, "add column generate_voucher_by_day_flag tinyint not null default 1") === false) {
    fwrite(STDERR, 'Finance standard patch should add daily voucher flag idempotently' . PHP_EOL);
    exit(1);
}

if (
    strpos($patch, "'00000000-0000-0000-0000-000000000102', '100201', '诉讼费专户'") === false ||
    strpos($patch, "'00000000-0000-0000-0000-000000000102', '220301', '预收诉讼费'") === false
) {
    fwrite(STDERR, 'Finance standard patch should seed usable litigation fee subjects' . PHP_EOL);
    exit(1);
}

if (strpos($accountSetPreset, 'generate_voucher_by_day_flag') === false) {
    fwrite(STDERR, 'Account set preset should include daily voucher flag' . PHP_EOL);
    exit(1);
}

echo 'Case fund subject config test passed' . PHP_EOL;
