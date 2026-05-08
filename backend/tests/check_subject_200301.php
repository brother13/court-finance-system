<?php
$host = '127.0.0.1';
$dbname = 'court-finance';
$user = 'root';
$pass = 'root';
$port = 3306;

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$accountSetId = '00000000-0000-0000-0000-000000000101';
$subjectCode = '200301';

echo "=== 期初余额 ===\n";
$stmt = $pdo->prepare("SELECT period, debit_amount, credit_amount FROM fin_opening_balance WHERE account_set_id = ? AND subject_code = ? AND del_flag = 0");
$stmt->execute([$accountSetId, $subjectCode]);
$opening = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($opening as $row) {
    echo "period=" . $row['period'] . " debit=" . $row['debit_amount'] . " credit=" . $row['credit_amount'] . "\n";
}
if (empty($opening)) echo "无期初记录\n";

echo "\n=== 统一凭证明细 ===\n";
$stmt = $pdo->prepare("SELECT fiscal_year, period, COUNT(*) as cnt FROM fin_voucher_detail WHERE account_set_id = ? AND subject_code = ? AND del_flag = 0 GROUP BY fiscal_year, period ORDER BY fiscal_year, period");
$stmt->execute([$accountSetId, $subjectCode]);
$voucherRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($voucherRows as $row) {
    echo "year=" . $row['fiscal_year'] . " period=" . $row['period'] . " count=" . $row['cnt'] . "\n";
}
if (empty($voucherRows)) echo "无凭证明细\n";

echo "\n=== 辅助核算配置 ===\n";
$stmt = $pdo->prepare("SELECT aux_type_code, required_flag FROM fin_subject_aux_config WHERE account_set_id = ? AND subject_code = ? AND del_flag = 0");
$stmt->execute([$accountSetId, $subjectCode]);
$configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($configs as $row) {
    echo "aux_type=" . $row['aux_type_code'] . " required=" . $row['required_flag'] . "\n";
}
if (empty($configs)) echo "无辅助核算配置\n";
