<?php

$modelPath = __DIR__ . '/../app/finance/model/CaseFund.php';
$ddlPath = __DIR__ . '/../database/court_finance_ddl.sql';
$patchPath = __DIR__ . '/../database/finance_standard_patch.sql';
$accountSetPresetPath = __DIR__ . '/../database/account_sets_preset.sql';

$source = file_get_contents($modelPath);
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

if (
    strpos($source, "'CASE_FUND' => ['执行、调解款']") === false ||
    strpos($source, "'LITIGATION_FEE' => ['诉讼费预收']") === false
) {
    fwrite(STDERR, 'Subject config should only expose canonical payment business types' . PHP_EOL);
    exit(1);
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
