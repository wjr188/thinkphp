<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;

class TestController extends BaseController
{
    /**
     * @api /api/test1
     */
    public function test1()
    {
        return json(['code' => 0, 'msg' => 'TestController: test1 placeholder', 'data' => []]);
    }

    // 对应 `api/test` (可能在 Dashboard)
    public function test()
    {
        return json(['code' => 0, 'msg' => 'TestController: test placeholder', 'data' => []]);
    }
}