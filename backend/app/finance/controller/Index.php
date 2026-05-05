<?php

namespace app\finance\controller;

use app\finance\model\Aux;
use app\finance\model\Auth;
use app\finance\model\AccountSet;
use app\finance\model\Book;
use app\finance\model\CaseFund;
use app\finance\model\Log;
use app\finance\model\Opening;
use app\finance\model\Permission;
use app\finance\model\Period;
use app\finance\model\Role;
use app\finance\model\Subject;
use app\finance\model\User;
use app\finance\model\Voucher;
use think\Controller;

class Index extends Controller
{
    const CODE_SUCCESS = 20000;
    const CODE_ERROR = 0;

    protected function _rt()
    {
        return [
            'code' => self::CODE_ERROR,
            'action' => input('param.action', '/sys/info'),
            'message' => '',
            'time' => getNowTime(),
            'page' => input('param.page', 1),
            'pagesize' => input('param.pagesize', 100),
            'total' => 0,
            'data' => '',
        ];
    }

    public function index()
    {
        $rt = $this->_rt();
        $action = input('param.action', '/sys/info');
        $actionArr = explode('/', $action);

        if (count($actionArr) < 3) {
            $rt['message'] = "操作【{$action}】不合法！";
            return $rt;
        }

        $param = input('param.');
        $postdata = isset($param['data']) ? $param['data'] : [];
        if (is_string($postdata)) {
            $decoded = json_decode($postdata, true);
            $postdata = is_array($decoded) ? $decoded : [];
        }

        $data = [];
        $logModel = new Log();
        $logModel->recordRequest($actionArr, $postdata);

        switch ($actionArr[1]) {
            case 'auth':
                $model = new Auth();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'accountSet':
                $model = new AccountSet();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'subject':
                $model = new Subject();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'aux':
                $model = new Aux();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'voucher':
                $model = new Voucher();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'book':
                $model = new Book();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'caseFund':
                $model = new CaseFund();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'period':
                $model = new Period();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'opening':
                $model = new Opening();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'user':
                $model = new User();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'role':
                $model = new Role();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'permission':
                $model = new Permission();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'log':
                $model = new Log();
                $data = $model->index($actionArr[2], $postdata);
                break;
            case 'sys':
                $data = [
                    'code' => self::CODE_SUCCESS,
                    'message' => 'OK',
                    'data' => [
                        'name' => '法院专项账务记账系统',
                        'version' => '0.1.0',
                        'php' => PHP_VERSION,
                    ],
                ];
                break;
            default:
                $rt['message'] = "操作【{$action}】不合法！";
                return $rt;
        }

        $rt['code'] = isset($data['code']) ? $data['code'] : self::CODE_SUCCESS;
        $rt['message'] = isset($data['message']) ? $data['message'] : $rt['message'];
        $rt['data'] = isset($data['data']) ? $data['data'] : '';
        if (isset($data['total'])) {
            $rt['total'] = $data['total'];
        }
        return $rt;
    }

    public function _empty($name = '')
    {
        $rt = $this->_rt();
        $rt['message'] = "您访问的操作【{$name}】并不存在";
        return $rt;
    }
}
