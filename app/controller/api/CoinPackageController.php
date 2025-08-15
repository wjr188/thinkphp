<?php
namespace app\controller\api;

use think\facade\Db;
use think\Request;

if (!function_exists('successJson')) {
    function successJson($data = [], $msg = 'success', $code = 0) {
        return json([
            'code' => $code,
            'message' => $msg,
            'data' => $data,
        ]);
    }
}
if (!function_exists('errorJson')) {
    function errorJson($msg = 'error', $code = 1, $data = []) {
        return json([
            'code' => $code,
            'message' => $msg,
            'data' => $data,
        ]);
    }
}

class CoinPackageController
{
    /**
     * 套餐列表
     * GET /api/coin-package/list
     */
    public function list(Request $request)
    {
        $where = [];
        // 支持按状态查询
        if ($status = $request->get('status', '')) {
            $where[] = ['status', '=', intval($status)];
        }
        $list = Db::name('coin_package')->where($where)->order('sort desc, id desc')->select()->toArray();
        return successJson(['list' => $list, 'total' => count($list)], '查询成功');
    }

    /**
     * 新增套餐
     * POST /api/coin-package/add
     */
    public function add(Request $request)
    {
        $data = $request->post();
        unset($data['id']); // 防止主键冲突
        if (empty($data['amount']) || empty($data['price'])) {
            return errorJson('金币数量和售价必填');
        }
        $data['create_time'] = $data['update_time'] = date('Y-m-d H:i:s');
        $id = Db::name('coin_package')->insertGetId($data);
        return $id ? successJson(['id' => $id], '新增成功') : errorJson('新增失败');
    }

    /**
     * 编辑套餐
     * POST /api/coin-package/update
     */
    public function update(Request $request)
    {
        $data = $request->post();
        if (empty($data['id'])) return errorJson('缺少ID');
        $id = $data['id'];
        unset($data['id']);
        $data['update_time'] = date('Y-m-d H:i:s');
        $res = Db::name('coin_package')->where('id', $id)->update($data);
        return $res !== false ? successJson([], '修改成功') : errorJson('修改失败');
    }

    /**
     * 批量/单个删除
     * POST /api/coin-package/delete
     * 支持 ids: [] 也支持 id
     */
    public function delete(Request $request)
    {
        $ids = $request->post('ids', []);
        $id  = $request->post('id', 0);
        if (!empty($id)) $ids[] = $id;
        if (empty($ids)) return errorJson('参数错误');
        $res = Db::name('coin_package')->whereIn('id', $ids)->delete();
        return $res ? successJson([], '删除成功') : errorJson('删除失败');
    }

    /**
     * 批量上下架
     * POST /api/coin-package/status
     * 参数: ids:[], status:1/0
     */
    public function status(Request $request)
    {
        $ids = $request->post('ids', []);
        $status = intval($request->post('status', 1));
        if (!is_array($ids) || empty($ids)) return errorJson('参数错误');
        $res = Db::name('coin_package')->whereIn('id', $ids)->update(['status' => $status, 'update_time' => date('Y-m-d H:i:s')]);
        return $res !== false ? successJson([], ($status ? '上架' : '下架') . '成功') : errorJson('操作失败');
    }
}
