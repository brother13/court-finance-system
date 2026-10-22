<?php
function input($key = '', $default = null) { return $default; }
function config($key = '') { return $key === 'default_year' ? '2026' : 'set-a'; }
function getNowTime() { return '2026-10-16 12:00:00'; }
require_once __DIR__ . '/../app/finance/model/Common.php';
require_once __DIR__ . '/../app/finance/model/Voucher.php';

class PersonSearchQuery {
    private $rows;
    private $conditions = [];
    private $limit = null;
    public function __construct($rows) { $this->rows = $rows; }
    public function where($field, $op = null, $value = null) {
        if ($field instanceof Closure) {
            $nested = new self($this->rows);
            $field($nested);
            $this->conditions[] = function ($row) use ($nested) { return $nested->matches($row); };
        } elseif (is_array($field)) {
            foreach ($field as $name => $condition) {
                $this->conditions[] = function ($row) use ($name, $condition) { return self::test($row[$name] ?? null, $condition); };
            }
        } else {
            if (is_array($op)) throw new Exception('TP5 compound conditions must use an associative where array');
            $this->conditions[] = function ($row) use ($field, $op, $value) {
                foreach (explode('|', $field) as $name) if (self::test($row[$name] ?? null, [$op, $value])) return true;
                return false;
            };
        }
        return $this;
    }
    private static function test($value, $condition) {
        if (!is_array($condition)) return $value == $condition;
        if (is_array($condition[0])) {
            $logic = is_string(end($condition)) ? array_pop($condition) : 'and';
            $results = array_map(function ($item) use ($value) { return self::test($value, $item); }, $condition);
            return strtolower($logic) === 'or' ? in_array(true, $results, true) : !in_array(false, $results, true);
        }
        [$op, $expected] = $condition;
        switch ($op) {
            case 'like': return $value !== null && strpos((string)$value, trim($expected, '%')) !== false;
            case 'in': return in_array($value, $expected, true);
            case '>=': return $value >= $expected;
            case '<=': return $value <= $expected;
        }
        throw new Exception('Unknown test operator ' . $op);
    }
    public function matches($row) { foreach ($this->conditions as $condition) if (!$condition($row)) return false; return true; }
    public function order($order) { return $this; }
    public function field($fields) { return $this; }
    public function page($page, $size) { $this->limit = [(max(1, (int)$page) - 1) * $size, $size]; return $this; }
    public function select() {
        $rows = array_values(array_filter($this->rows, [$this, 'matches']));
        return $this->limit === null ? $rows : array_slice($rows, $this->limit[0], $this->limit[1]);
    }
    public function column($field) { return array_column($this->select(), $field); }
    public function count() { return count($this->select()); }
}
class PersonSearchProbe extends \app\finance\model\Voucher {
    public static $tables = [];
    public $scope = 'ALL';
    public function __construct() { parent::__construct(); $this->userid = 'user-a'; }
    public static function getdb($table = '') { return new PersonSearchQuery(self::$tables[$table]); }
    protected function currentViewScope() { return $this->scope; }
    protected function requirePermission($code) { return null; }
    protected function collectVoucherExportRows($vouchers, $range) { return $vouchers; }
}
function personCheck($condition, $message) { if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); } }
PersonSearchProbe::$tables['sys_user'] = [
    ['user_id'=>'user-a', 'real_name'=>'张会计', 'username'=>'zhang', 'status'=>1],
    ['user_id'=>'user-b', 'real_name'=>'李审核', 'username'=>'li', 'status'=>0],
    ['user_id'=>'user-c', 'real_name'=>'张会计', 'username'=>'zhang2', 'status'=>1],
];
$base = ['account_set_id'=>'set-a', 'fiscal_year'=>2026, 'period'=>'2026-08', 'del_flag'=>0, 'voucher_date'=>'2026-08-07', 'voucher_no'=>33, 'prepared_by'=>'user-a', 'audit_by'=>'user-b', 'posted_by'=>'user-b'];
PersonSearchProbe::$tables['fin_voucher'] = [
    $base + ['voucher_id'=>'v1'],
    array_merge($base, ['voucher_id'=>'v2', 'prepared_by'=>'user-c']),
    array_merge($base, ['voucher_id'=>'v3', 'prepared_by'=>'legacy-张会计', 'audit_by'=>null, 'posted_by'=>null]),
    array_merge($base, ['voucher_id'=>'other-set', 'account_set_id'=>'set-b']),
    array_merge($base, ['voucher_id'=>'deleted', 'del_flag'=>1]),
];
$probe = new PersonSearchProbe();
$params = ['period'=>'2026-08', 'pagesize'=>1];
foreach ([['prepared_by','张会计',3], ['prepared_by','张',3], ['prepared_by','zhang',2], ['prepared_by','user-a',1], ['audit_by','李审核',2], ['audit_by','审核',2], ['audit_by','li',2], ['posted_by','李审核',2], ['audit_by','找不到的人',0], ['prepared_by','  ',3]] as [$field,$keyword,$count]) {
    $result = $probe->getList($params + [$field=>$keyword]);
    personCheck($result['data']['total'] === $count, 'Incorrect name/ID/login matching for ' . $field . ':' . $keyword);
    personCheck(count($result['data']['items']) === min(1, $count), 'Filtered total and page rows must agree');
}
$result = $probe->getList($params + ['prepared_by'=>'user-c','audit_by'=>'李审核']);
personCheck($result['data']['total'] === 1 && $result['data']['items'][0]['voucher_id'] === 'v2', 'Preparer and reviewer filters must intersect');
$probe->scope = 'SELF';
personCheck($probe->getList($params + ['prepared_by'=>'张会计'])['data']['total'] === 1, 'Matching names must retain SELF isolation');
personCheck($probe->getList($params + ['prepared_by'=>'user-c'])['data']['total'] === 0, 'SELF must honor a conflicting name filter instead of overwriting it');
personCheck($probe->getList($params + ['audit_by'=>'李审核'])['data']['total'] === 1, 'Reviewer name must retain SELF isolation');
personCheck($probe->exportData($params + ['audit_by'=>'李审核'])['data']['voucher_count'] === 1, 'Export must reuse personnel filters and SELF scope');
echo 'Voucher person search test passed' . PHP_EOL;
