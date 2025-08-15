<?php
namespace app\controller\api;

use think\Request;
use app\BaseController;
use app\model\DarknetTag as TagModel;

class DarknetTagController extends BaseController
{
    // 列表
    public function list(Request $request)
    {
        $params = $request->get();
        $where = [];
        if (!empty($params['keyword'])) {
            $where[] = ['name|alias', 'like', "%{$params['keyword']}%"];
        }
        if (!empty($params['group'])) {
            $where[] = ['group', '=', $params['group']];
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $where[] = ['status', '=', $params['status']];
        }
        $list = TagModel::where($where)->order('sort asc, id desc')->select()->toArray();
        return json(['code'=>0, 'data'=>$list]);
    }

    // 新增
    public function add(Request $request)
    {
        $data = $request->post();
        $tag = TagModel::create($data);
        return json(['code'=>0, 'msg'=>'添加成功', 'data'=>$tag]);
    }

    // 编辑
    public function update(Request $request)
    {
        $data = $request->post();
        $id = $data['id'] ?? 0;
        unset($data['id']);
        TagModel::update($data, ['id' => $id]);
        return json(['code'=>0, 'msg'=>'编辑成功']);
    }

    // 删除
    public function delete(Request $request)
    {
        $id = $request->post('id');
        TagModel::destroy($id);
        return json(['code'=>0, 'msg'=>'删除成功']);
    }

    // 批量删除
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids');
        TagModel::destroy($ids);
        return json(['code'=>0, 'msg'=>'批量删除成功']);
    }

    // 批量禁用
    public function batchDisable(Request $request)
    {
        $ids = $request->post('ids');
        TagModel::whereIn('id', $ids)->update(['status' => 0]);
        return json(['code'=>0, 'msg'=>'批量禁用成功']);
    }

    // 启用/禁用（单个切换）
    public function toggleStatus(Request $request)
    {
        $id = $request->post('id');
        $tag = TagModel::find($id);
        if (!$tag) return json(['code'=>1, 'msg'=>'标签不存在']);
        $tag->status = $tag->status ? 0 : 1;
        $tag->save();
        return json(['code'=>0, 'msg'=>'切换成功']);
    }
}
