<?php

$schemaPath = __DIR__ . '/../database/2026-08-03-finance-async-job.sql';

function asyncJobSchemaFail($message)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function asyncJobSchemaAssertContains($source, $needle, $message)
{
    if (strpos($source, $needle) === false) {
        asyncJobSchemaFail($message . '; missing: ' . $needle);
    }
}

function asyncJobSchemaTableBlock($source, $table)
{
    $pattern = '/create table if not exists ' . preg_quote($table, '/') . '\\s*\\((.*?)\\n\\);/s';
    if (!preg_match($pattern, $source, $matches)) {
        asyncJobSchemaFail('Missing idempotent table definition: ' . $table);
    }
    return $matches[1];
}

if (!is_file($schemaPath)) {
    asyncJobSchemaFail('Missing async job schema patch: ' . $schemaPath);
}

$schema = strtolower(file_get_contents($schemaPath));

foreach (['fin_async_job', 'fin_async_job_item', 'fin_case_fund_import_stage'] as $table) {
    $block = asyncJobSchemaTableBlock($schema, $table);
    foreach ([
        'account_set_id varchar(36) not null',
        'fiscal_year int not null',
        'period varchar(7) not null',
        'created_by varchar(36)',
        'created_time datetime',
        'updated_by varchar(36)',
        'updated_time datetime',
        'del_flag int not null default 0',
        'version int not null default 0',
    ] as $requiredColumn) {
        asyncJobSchemaAssertContains(
            $block,
            $requiredColumn,
            $table . ' should retain account-set, period, audit and soft-delete fields'
        );
    }
}

$job = asyncJobSchemaTableBlock($schema, 'fin_async_job');
foreach ([
    'job_id varchar(36) primary key',
    'client_request_id varchar(64) not null',
    'payload_json longtext',
    'result_json longtext',
    'source_file_name varchar(255)',
    'source_file_path varchar(1000)',
    'source_file_hash char(64)',
    'progress_percent decimal(5,2) not null default 0',
    'worker_token varchar(100)',
    'heartbeat_time datetime',
    'unique key uk_async_job_request (account_set_id, job_type, client_request_id)',
    'key idx_async_job_queue (status, del_flag, queued_time, job_id)',
    'key idx_async_job_heartbeat (status, del_flag, heartbeat_time)',
] as $requiredJobDefinition) {
    asyncJobSchemaAssertContains($job, $requiredJobDefinition, 'Async job table is missing a queue or idempotency invariant');
}

foreach (['queued', 'running', 'succeeded', 'partial_failed', 'failed'] as $status) {
    asyncJobSchemaAssertContains($schema, $status, 'Schema documentation should declare supported job status: ' . $status);
}

$item = asyncJobSchemaTableBlock($schema, 'fin_async_job_item');
foreach ([
    'item_id varchar(36) primary key',
    'item_no int not null',
    'source_type varchar(50) not null',
    'source_id varchar(36) not null',
    'amount decimal(18,2) not null default 0',
    'voucher_id varchar(36)',
    'error_code varchar(100)',
    'error_message varchar(2000)',
    'unique key uk_async_job_item_source (job_id, source_type, source_id)',
    'key idx_async_job_item_queue (job_id, status, del_flag, item_no, item_id)',
] as $requiredItemDefinition) {
    asyncJobSchemaAssertContains($item, $requiredItemDefinition, 'Async job item table is missing a source-freezing or result invariant');
}

$stage = asyncJobSchemaTableBlock($schema, 'fin_case_fund_import_stage');
foreach ([
    'stage_id varchar(36) primary key',
    'source_row_no int not null',
    'source_fingerprint varchar(64) not null default',
    'raw_json longtext',
    'normalized_json longtext',
    'validation_status varchar(20) not null default',
    'validation_errors_json longtext',
    'persistence_status varchar(20) not null default',
    'unique key uk_case_fund_import_stage_row (job_id, source_row_no)',
    'key idx_case_fund_import_stage_work (job_id, validation_status, persistence_status, del_flag, source_row_no)',
] as $requiredStageDefinition) {
    asyncJobSchemaAssertContains($stage, $requiredStageDefinition, 'Case-fund import stage is missing row-level validation evidence');
}

if (strpos($schema, ' enum(') !== false) {
    asyncJobSchemaFail('Async job schema should use portable varchar status fields instead of MySQL enum');
}

echo 'Finance async job schema test passed' . PHP_EOL;
