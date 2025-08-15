<?php
// 控制器：app/controller/api/InfluencerController.php
namespace app\controller\api;

use think\Request;
use think\facade\Db;

class InfluencerController
{
    // 列表&筛选
    public function list(Request $request)
    {
        $page = max(1, intval($request->get('page', 1)));
        $pageSize = max(1, intval($request->get('pageSize', 10)));
        $where = [];

        if ($nickname = $request->get('nickname')) {
            $where[] = ['i.nickname', 'like', "%$nickname%"];
        }
        if ($country = $request->get('country')) {
            $where[] = ['i.country', '=', $country];
        }
        if ($status = $request->get('status')) {
            $where[] = ['i.status', '=', $status];
        }
        if ($tagId = $request->get('tagId')) {
            $where[] = [Db::raw("FIND_IN_SET('$tagId', i.tags)")];
        }
        // 这里加上分组筛选
        if ($groupId = $request->get('group_id')) {
            $where[] = ['i.group_id', '=', $groupId];
        }

        $query = Db::name('influencer')
            ->alias('i')
            ->leftJoin('influencer_group g', 'i.group_id = g.id')
            ->where($where)
            ->field('i.*, g.name as group_name');

        $total = $query->count();
        $list = $query->page($page, $pageSize)->order('i.id desc')->select();

        foreach ($list as &$item) {
            $item['album_count'] = Db::name('content_album')->where('influencer_id', $item['id'])->count();
            $albumIds = Db::name('content_album')->where('influencer_id', $item['id'])->column('id');
            $item['video_count'] = $albumIds ? Db::name('content_video')->whereIn('album_id', $albumIds)->count() : 0;
            $item['tags'] = $item['tags'] ? array_map('intval', explode(',', $item['tags'])) : [];
        }

        return json(['code' => 0, 'data' => [
            'list' => $list,
            'total' => $total
        ]]);
    }

    // 新增
    public function create(Request $request)
    {
        $data = $request->post();
        if (!empty($data['tags'])) {
            $data['tags'] = implode(',', $data['tags']);
        }
        $data['create_time'] = date('Y-m-d H:i:s');
        Db::name('influencer')->insert($data);
        return json(['code' => 0, 'msg' => 'success']);
    }

    // 编辑
    public function update(Request $request)
    {
        $data = $request->post();
        $id = $data['id'] ?? null;
        if (!$id) {
            return json(['code' => 1, 'msg' => '缺少id参数']);
        }
        if (!empty($data['tags'])) {
            $data['tags'] = implode(',', $data['tags']);
        }
        unset($data['id']);
        Db::name('influencer')->where('id', $id)->update($data);
        return json(['code' => 0, 'msg' => 'success']);
    }

    // 删除
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => '缺少id参数']);
        }
        Db::name('influencer')->where('id', $id)->delete();
        return json(['code' => 0, 'msg' => 'success']);
    }

    // 批量删除
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (!is_array($ids)) $ids = [];
        Db::name('influencer')->whereIn('id', $ids)->delete();
        return json(['code' => 0, 'msg' => 'success']);
    }

    // 国家选项
    public function countryOptions()
    {
        $arr = ['中国', '美国', '日本', '韩国', '英国', '法国', '德国'];
        return json(['code' => 0, 'data' => $arr]);
    }

    // 标签选项
    public function tagOptions()
    {
        $tags = Db::name('tag')->field('id,name')->select();
        return json(['code' => 0, 'data' => ['list'=>$tags]]);
    }

    // 分组选项
    public function groupOptions()
    {
        // 假设你的分组表叫 influencer_group，字段有 id 和 name
        $groups = Db::name('influencer_group')->field('id,name')->select();
        return json(['code' => 0, 'data' => ['list' => $groups]]);
    }
}
