<?php
// E:\ThinkPHP6\app\controller\api\ToolController.php

namespace app\controller\api; // 确保是小写api

use app\BaseController;
use think\facade\Request;

class ToolController extends BaseController
{
    // 对应 'api/tool/run-visit-state'
    public function runVisitState()
    {
        return json(['code' => 0, 'msg' => 'ToolController: runVisitState placeholder', 'data' => []]);
    }

    // 对应 'api/tool/visit-state' (来自 Dashboard)
    public function visitState()
    {
        return json(['code' => 0, 'msg' => 'ToolController: visitState placeholder', 'data' => []]);
    }
}