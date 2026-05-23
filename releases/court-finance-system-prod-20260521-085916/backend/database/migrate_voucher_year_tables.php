<?php
/**
 * Migrate historical yearly voucher tables into unified voucher tables.
 *
 * Run after finance_standard_patch.sql has created:
 * - fin_voucher
 * - fin_voucher_detail
 * - fin_voucher_aux_value
 *
 * This script is idempotent. Existing destination rows with the same primary id
 * are skipped, and historical yearly tables are kept as backup.
 */

$config = require __DIR__ . '/../app/database.php';

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['hostname'] === 'localhost' ? '127.0.0.1' : $config['hostname'],
    $config['hostport'],
    $config['database'],
    $config['charset']
);

$pdo = new PDO($dsn, $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function quotedTable($table)
{
    return '`' . str_replace('`', '``', $table) . '`';
}

function quotedColumn($column)
{
    return '`' . str_replace('`', '``', $column) . '`';
}

function tableExists(PDO $pdo, $table)
{
    $stmt = $pdo->prepare(
        'select count(*) from information_schema.tables where table_schema = database() and table_name = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function tableColumns(PDO $pdo, $table)
{
    $stmt = $pdo->query('show columns from ' . quotedTable($table));
    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $columns[$row['Field']] = true;
    }
    return $columns;
}

function migrateTable(PDO $pdo, $targetTable, $sourceTable, $headerTable, $fiscalYear, $primaryKey, array $syntheticColumns)
{
    if (!tableExists($pdo, $targetTable) || !tableExists($pdo, $sourceTable)) {
        return 0;
    }

    $targetColumns = tableColumns($pdo, $targetTable);
    $sourceColumns = tableColumns($pdo, $sourceTable);
    $headerColumns = tableColumns($pdo, $headerTable);
    $insertColumns = [];
    $selectColumns = [];

    foreach (array_keys($targetColumns) as $column) {
        if (isset($syntheticColumns[$column])) {
            $insertColumns[] = quotedColumn($column);
            $selectColumns[] = $syntheticColumns[$column];
            continue;
        }

        if (isset($sourceColumns[$column])) {
            $insertColumns[] = quotedColumn($column);
            $selectColumns[] = 's.' . quotedColumn($column);
            continue;
        }

        if (isset($headerColumns[$column])) {
            $insertColumns[] = quotedColumn($column);
            $selectColumns[] = 'h.' . quotedColumn($column);
        }
    }

    if (!isset($sourceColumns[$primaryKey])) {
        throw new RuntimeException($sourceTable . ' missing primary key column ' . $primaryKey);
    }

    $joinHeader = $sourceTable === $headerTable
        ? ''
        : ' join ' . quotedTable($headerTable) . ' h on h.voucher_id = s.voucher_id and h.account_set_id = s.account_set_id';
    $headerAlias = $sourceTable === $headerTable ? 's' : 'h';

    $sql = 'insert into ' . quotedTable($targetTable)
        . ' (' . implode(', ', $insertColumns) . ') '
        . 'select ' . implode(', ', $selectColumns) . ' '
        . 'from ' . quotedTable($sourceTable) . ' s'
        . $joinHeader . ' '
        . 'where not exists ('
        . 'select 1 from ' . quotedTable($targetTable) . ' t '
        . 'where t.' . quotedColumn($primaryKey) . ' = s.' . quotedColumn($primaryKey)
        . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->rowCount();
}

foreach (['fin_voucher', 'fin_voucher_detail', 'fin_voucher_aux_value'] as $requiredTable) {
    if (!tableExists($pdo, $requiredTable)) {
        throw new RuntimeException($requiredTable . ' does not exist. Run finance_standard_patch.sql first.');
    }
}

$tables = $pdo->query("show tables like 'fin_voucher\\_%'")->fetchAll(PDO::FETCH_COLUMN);
$years = [];
foreach ($tables as $table) {
    if (preg_match('/^fin_voucher_(\d{4})$/', $table, $matches)) {
        $years[(int)$matches[1]] = true;
    }
}

ksort($years);

if (empty($years)) {
    echo "No historical yearly voucher tables found.\n";
    exit(0);
}

foreach (array_keys($years) as $year) {
    $headerTable = 'fin_voucher_' . $year;
    $detailTable = 'fin_voucher_detail_' . $year;
    $auxTable = 'fin_voucher_aux_value_' . $year;

    $pdo->beginTransaction();
    try {
        $headerRows = migrateTable($pdo, 'fin_voucher', $headerTable, $headerTable, $year, 'voucher_id', [
            'fiscal_year' => (string)$year,
        ]);
        $detailRows = migrateTable($pdo, 'fin_voucher_detail', $detailTable, $headerTable, $year, 'detail_id', [
            'fiscal_year' => (string)$year,
            'period' => 'h.`period`',
        ]);
        $auxRows = migrateTable($pdo, 'fin_voucher_aux_value', $auxTable, $headerTable, $year, 'id', [
            'fiscal_year' => (string)$year,
            'period' => 'h.`period`',
        ]);
        $pdo->commit();
        echo sprintf(
            "Migrated %d: vouchers=%d, details=%d, aux_values=%d\n",
            $year,
            $headerRows,
            $detailRows,
            $auxRows
        );
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
