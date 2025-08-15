<?php
namespace app\controller\api;

use app\BaseController;
use think\Request;
use think\facade\Db;

class InfluencerGroupController extends BaseController
{
    // 分组列表
    public function list()
    {
        $list = Db::name('influencer_group')->order('id desc')->select()->toArray();
        $total = count($list);
        return json(['code'=>0, 'data'=>['list'=>$list, 'total'=>$total]]);
    }

    // 新增分组
    public function add(Request $request)
    {
        $data = $request->post();
        if (empty($data['name'])) {
            return json(['code'=>1, 'msg'=>'分组名不能为空']);
        }
        $id = Db::name('influencer_group')->insertGetId(['name'=>$data['name']]);
        return json(['code'=>0, 'msg'=>'success', 'id'=>$id]);
    }

    // 编辑分组
    public function update($id, Request $request)
    {
        $data = $request->post();
        if (empty($data['name'])) {
            return json(['code'=>1, 'msg'=>'分组名不能为空']);
        }
        Db::name('influencer_group')->where('id', $id)->update(['name'=>$data['name']]);
        return json(['code'=>0, 'msg'=>'success']);
    }

    // 删除分组
    public function delete($id)
    {
        Db::name('influencer_group')->where('id', $id)->delete();
        // 这里你也可以把该分组下的 influencer 的 group_id 置空
        Db::name('influencer')->where('group_id', $id)->update(['group_id'=>null]);
        return json(['code'=>0, 'msg'=>'success']);
    }
}
