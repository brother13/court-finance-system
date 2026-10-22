<?php
function input($key = '', $default = null) { return $default; }
function config($key = '') { return $key === 'default_year' ? '2026' : 'set-a'; }
function getNowTime() { return '2026-10-15 12:00:00'; }
require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Voucher.php';

class ExportQuery {
    private $table;
    private $conditions = [];
    public function __construct($table) { $this->table = $table; }
    public function where($where) { $this->conditions = array_merge($this->conditions, $where); return $this; }
    public function field($field) { return $this; }
    public function order($order) { return $this; }
    public function page($page, $size) { throw new Exception('Export must not use pagination'); }
    public function select() {
        return array_values(array_filter(ExportProbe::$tables[$this->table], function ($row) {
            foreach ($this->conditions as $key => $condition) {
                $value = $row[$key] ?? null;
                if (!is_array($condition)) { if ($value != $condition) return false; continue; }
                $clauses = is_array($condition[0]) ? $condition : [$condition];
                foreach ($clauses as [$operator, $expected]) {
                    if ($operator === 'in' && !in_array($value, $expected, true)) return false;
                    if ($operator === '>=' && $value < $expected) return false;
                    if ($operator === '<=' && $value > $expected) return false;
                    if ($operator === 'like' && strpos($value, trim($expected, '%')) === false) return false;
                }
            }
            return true;
        }));
    }
}
class ExportProbe extends \app\finance\model\Voucher {
    public static $tables = [];
    public $deny = '';
    public $scope = 'ALL';
    public function __construct() { parent::__construct(); $this->userid = 'user-a'; }
    public static function getdb($table = '') { return new ExportQuery($table); }
    protected function requirePermission($permission) { return $permission === $this->deny ? $this->error('Denied') : null; }
    protected function currentViewScope() { return $this->scope; }
    protected function fillUserDisplayNames(&$rows) { foreach ($rows as &$row) $row['prepared_by_name'] = $row['prepared_by']; }
}
function checkExport($condition, $message) { if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); } }
$base = ['account_set_id' => 'set-a', 'fiscal_year' => 2026, 'period' => '2026-08', 'del_flag' => 0];
$header = $base + ['voucher_id' => 'v1', 'voucher_date' => '2026-08-07', 'voucher_no' => 33, 'voucher_word' => '记', 'status' => 'SUBMITTED', 'prepared_by' => 'user-a'];
ExportProbe::$tables['fin_voucher'] = [$header,
    array_merge($header, ['voucher_id' => 'v2', 'voucher_no' => 34]),
    array_merge($header, ['voucher_id' => 'other-user', 'prepared_by' => 'user-b']),
    array_merge($header, ['voucher_id' => 'other-set', 'account_set_id' => 'set-b']),
    array_merge($header, ['voucher_id' => 'deleted', 'del_flag' => 1]),
    array_merge($header, ['voucher_id' => 'other-period', 'period' => '2026-09']),
];
ExportProbe::$tables['fin_subject'] = [$base + ['subject_code' => '100201', 'subject_name' => '银行存款']];
ExportProbe::$tables['fin_voucher_detail'] = [];
foreach (ExportProbe::$tables['fin_voucher'] as $item) {
    for ($line = 1; $line <= 2; $line++) {
        ExportProbe::$tables['fin_voucher_detail'][] = array_merge($base, [
            'voucher_id' => $item['voucher_id'], 'detail_id' => $item['voucher_id'] . '-' . $line,
            'account_set_id' => $item['account_set_id'], 'del_flag' => $item['del_flag'], 'period' => $item['period'],
            'line_no' => $line, 'subject_code' => '100201', 'summary' => '=SUM(A1:A2)',
            'aux_desc' => '案号：测试；收据号：000123', 'debit_amount' => $line === 1 ? '10.30' : '0',
            'credit_amount' => $line === 2 ? '10.30' : '0',
        ]);
    }
}
$probe = new ExportProbe();
$params = ['period_start' => '2026-08', 'period_end' => '2026-08', 'page' => 1, 'pagesize' => 1];
$result = $probe->exportData($params);
checkExport($result['code'] === 20000 && count($result['data']['items']) === 6, 'Export must include all matching vouchers beyond page size, and exclude other accounts/deleted/periods');
checkExport($result['data']['voucher_count'] === 3, 'Voucher count should reflect matched headers');
$row = $result['data']['items'][0];
checkExport($row['subject_code'] === '100201' && $row['voucher_no'] === '33', 'Identifier fields must be strings');
checkExport($row['debit_amount'] === '10.30' && $row['credit_amount'] === '0.00', 'Export must retain exact monetary values');
checkExport($row['aux_desc'] === '案号：测试；收据号：000123', 'Export must include auxiliary values');
$probe->scope = 'SELF';
checkExport(count($probe->exportData($params)['data']['items']) === 4, 'SELF scope must exclude other preparers');
checkExport(count($probe->exportData($params + ['voucher_id' => 'v2'])['data']['items']) === 2, 'Exact voucher filters must apply');
checkExport($probe->exportData($params + ['status' => 'AUDITED'])['data']['items'] === [], 'Empty filtered exports must be empty');
foreach (['voucher:export', 'voucher:view'] as $permission) {
    $probe->deny = $permission;
    checkExport($probe->exportData($params)['code'] === 0, 'Export must require ' . $permission);
}
echo 'Voucher export test passed' . PHP_EOL;
