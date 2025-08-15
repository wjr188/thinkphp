<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\Request;

class TagController extends BaseController
{
    // 列表
    public function list(Request $request)
    {
        $page = (int)$request->param('page', 1);
        $pageSize = (int)$request->param('pageSize', 10);
        $name = trim($request->param('name', ''));
        $where = [];
        if ($name) $where[] = ['name', 'like', '%' . $name . '%'];
        $query = Db::name('content_tag')->where($where);
        $total = $query->count();
        $list = $query->order('id desc')->page($page, $pageSize)->select();
        return json(['code'=>0, 'msg'=>'success', 'data'=>['list'=>$list, 'total'=>$total]]);
    }

    // 新增
    public function create(Request $request)
    {
        $data = $request->post();
        $id = Db::name('content_tag')->insertGetId($data);
        return json(['code'=>0, 'msg'=>'success', 'data'=>['id'=>$id]]);
    }

    // 修改
    public function update(Request $request, $id)
    {
        $data = $request->post();
        Db::name('content_tag')->where('id', $id)->update($data);
        return json(['code'=>0, 'msg'=>'success']);
    }

    // 删除
    public function delete(Request $request, $id)
    {
        Db::name('content_tag')->where('id', $id)->delete();
        return json(['code'=>0, 'msg'=>'success']);
    }

    // 批量删除
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        Db::name('content_tag')->whereIn('id', $ids)->delete();
        return json(['code'=>0, 'msg'=>'success']);
    }

    // 新增（兼容旧接口）
    public function add(Request $request)
    {
        return $this->create($request);
    }
}
