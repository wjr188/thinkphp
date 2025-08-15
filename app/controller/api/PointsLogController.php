<?php
declare(strict_types=1);

namespace app\controller\api;

use think\Request;
use think\facade\Db;
use app\BaseController;

/**
 * 后台 - 积分流水管理
 */
class PointsLogController extends BaseController
{
    /**
     * 积分流水列表（支持分页、筛选）
     * GET /api/points/logs
     */
    public function list(Request $request)
    {
        // TODO: 如果只给管理员用，这里要做checkAdmin()或token验证

        $page     = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, (int)$request->get('pageSize', 20));

        $where = [];

        // 按用户UUID筛选
        if ($uuid = $request->get('uuid')) {
            $where[] = ['uuid', '=', $uuid];
        }

        // 按类型筛选
        if ($type = $request->get('type')) {
            $where[] = ['type', '=', $type];
        }

        // 按时间区间筛选
        if ($startTime = $request->get('start_time')) {
            $where[] = ['create_time', '>=', $startTime];
        }
        if ($endTime = $request->get('end_time')) {
            $where[] = ['create_time', '<=', $endTime];
        }

        $query = Db::name('user_points_log')
            ->where($where)
            ->order('id desc');

        $total = $query->count();
        $list  = $query->page($page, $pageSize)->select();

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'list'  => $list,
                'total' => $total
            ]
        ]);
    }

    /**
     * 删除一条流水
     * DELETE /api/points/logs/{id}
     */
    public function delete($id)
    {
        // TODO: 如果需要，添加管理员验证
        $deleted = Db::name('user_points_log')->where('id', $id)->delete();

        if ($deleted) {
            return json(['code' => 0, 'msg' => '删除成功']);
        } else {
            return json(['code' => 404, 'msg' => '记录不存在']);
        }
    }
}
