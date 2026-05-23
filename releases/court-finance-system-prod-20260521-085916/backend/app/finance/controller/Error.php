<?php

namespace app\finance\controller;

use think\Controller;

class Error extends Controller
{
    public function _empty($name = '')
    {
        return [
            'code' => 0,
            'message' => "您访问的操作【{$name}】并不存在",
            'data' => '',
            'time' => getNowTime(),
        ];
    }
}
