<?php
declare(strict_types=1);

namespace app\controller\api;

use think\facade\Db;
use think\Request;
use app\BaseController;

/**
 * 积分兑换后台管理
 */
class AdminPointsExchangeController extends BaseController
{
    public function list()
    {
        $list = Db::name('points_exchange')
            ->order('sort desc, id asc')
            ->select();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $list]);
    }

    public function add(Request $request)
    {
        $data = $request->post([
            'name',
            'cost',
            'type',
            'value',
            'card_id',
            'icon',           // ⭐⭐⭐ 新增 icon
            'sort' => 0,
            'status' => 1,
            'description'
        ]);

        if (empty($data['name']) || empty($data['cost']) || empty($data['type']) || empty($data['value'])) {
            return json(['code' => 400, 'msg' => '请填写完整信息']);
        }

        Db::name('points_exchange')->insert($data);

        return json(['code' => 0, 'msg' => '新增成功']);
    }

    public function update($id, Request $request)
    {
        $data = $request->put([
            'name',
            'cost',
            'type',
            'value',
            'card_id',
            'icon',           // ⭐⭐⭐ 新增 icon
            'sort',
            'status',
            'description'
        ]);

        Db::name('points_exchange')->where('id', $id)->update($data);

        return json(['code' => 0, 'msg' => '更新成功']);
    }

    public function delete($id)
    {
        Db::name('points_exchange')->where('id', $id)->delete();

        return json(['code' => 0, 'msg' => '删除成功']);
    }

    public function toggleStatus($id)
    {
        $item = Db::name('points_exchange')->where('id', $id)->find();
        if (!$item) {
            return json(['code' => 404, 'msg' => '兑换项目不存在']);
        }

        $newStatus = $item['status'] ? 0 : 1;
        Db::name('points_exchange')->where('id', $id)->update(['status' => $newStatus]);

        return json(['code' => 0, 'msg' => '状态已切换']);
    }

    /**
     * 兑换记录列表（支持分页/按用户/商品/类型筛选）
     * GET /api/admin/points-exchange/records
     */
    public function records(Request $request)
    {
        // 强制类型转换，给默认值，保证参数有效
        $page     = (int)$request->get('page', 1);
        $pageSize = (int)$request->get('pageSize', 20);

        if ($page < 1) $page = 1;
        if ($pageSize < 1) $pageSize = 20;

        $where = [];
        // 按用户uuid筛选
        if ($uuid = $request->get('uuid')) {
            $where['uuid'] = $uuid;
        }
        // 按商品id筛选
        if ($exchangeId = $request->get('exchange_id')) {
            $where['exchange_id'] = $exchangeId;
        }
        // 按类型筛选（如有 type 字段）
        if ($type = $request->get('type')) {
            $where['type'] = $type;
        }

        $query = Db::name('user_points_exchange_log')
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
}
