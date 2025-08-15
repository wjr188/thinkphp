<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;

class LogsController extends BaseController
{
    /**
     * 处理 /api/v1/logs/visit-stats 请求
     */
    public function visitStats()
    {
        // 推荐：返回模拟统计数据，页面不会空
        return json([
            'code' => 0,
            'msg' => 'LogsController: visitStats success',
            'data' => [
                'pv' => 1024,   // 总访问量
                'uv' => 123,    // 独立访客
                'ip' => 99,     // IP数
            ]
        ]);
    }

    /**
     * 处理 /api/v1/logs/visit-trend 请求
     */
    public function visitTrend()
    {
        $startDate = Request::get('startDate', date('Y-m-01'));
        $endDate = Request::get('endDate', date('Y-m-d'));

        $data = [
            'trendData' => [],
            'totalVisits' => rand(1000, 5000),
            'uniqueVisitors' => rand(500, 2000),
        ];

        $currentDate = strtotime($startDate);
        $endTimestamp = strtotime($endDate);
        while ($currentDate <= $endTimestamp) {
            $data['trendData'][date('Y-m-d', $currentDate)] = rand(50, 300);
            $currentDate = strtotime('+1 day', $currentDate);
        }

        return json(['code' => 0, 'msg' => 'LogsController: visitTrend success', 'data' => $data]);
    }

    /**
     * 处理 /api/v1/api/logCount 请求 (如果前端请求这个)
     */
    public function logCount()
    {
        return json(['code' => 0, 'msg' => 'LogsController: logCount placeholder', 'data' => ['count' => 0]]);
    }
}
