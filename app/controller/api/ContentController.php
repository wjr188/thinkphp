<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\Request;

class ContentController extends BaseController
{
    // ----------- 专辑 -----------
    public function albumList(Request $request)
    {
        $page = (int)$request->param('page', 1);
        $pageSize = (int)$request->param('pageSize', 10);
        $influencer_id = $request->param('influencer_id');
        $where = [];
        if ($influencer_id) $where[] = ['influencer_id', '=', $influencer_id];
        $query = Db::name('content_album')->where($where);
        $total = $query->count();
        $list = $query->order('id desc')->page($page, $pageSize)->select();
        return json(['code'=>0, 'msg'=>'success', 'data'=>['list'=>$list, 'total'=>$total]]);
    }
    public function albumCreate(Request $request)
    {
        $data = $request->post();
        $data['create_time'] = date('Y-m-d H:i:s');
        $id = Db::name('content_album')->insertGetId($data);
        return json(['code'=>0, 'msg'=>'success', 'data'=>['id'=>$id]]);
    }
    public function albumUpdate(Request $request, $id)
    {
        $data = $request->post();
        Db::name('content_album')->where('id', $id)->update($data);
        return json(['code'=>0, 'msg'=>'success']);
    }
    public function albumDelete(Request $request, $id)
    {
        Db::name('content_album')->where('id', $id)->delete();
        // 删除专辑下视频
        Db::name('content_video')->where('album_id', $id)->delete();
        return json(['code'=>0, 'msg'=>'success']);
    }
    public function albumBatchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        Db::name('content_album')->whereIn('id', $ids)->delete();
        Db::name('content_video')->whereIn('album_id', $ids)->delete();
        return json(['code'=>0, 'msg'=>'success']);
    }

    // ----------- 视频/图片 -----------
    public function videoList(Request $request)
    {
        $page = (int)$request->param('page', 1);
        $pageSize = (int)$request->param('pageSize', 10);
        $where = [];
        if ($request->param('influencer_id')) $where[] = ['influencer_id', '=', $request->param('influencer_id')];
        if ($request->param('album_id')) $where[] = ['album_id', '=', $request->param('album_id')];
        if ($request->param('tag_id')) $where[] = ['FIND_IN_SET(:tagid, tag_ids)'];
        if ($request->param('status')) $where[] = ['status', '=', $request->param('status')];
        if ($request->param('type')) $where[] = ['type', '=', $request->param('type')];
        $query = Db::name('content_video')->where($where);
        if ($request->param('tag_id')) $query = $query->bind(['tagid' => $request->param('tag_id')]);
        $total = $query->count();
        $list = $query->order('id desc')->page($page, $pageSize)->select();
        return json(['code'=>0, 'msg'=>'success', 'data'=>['list'=>$list, 'total'=>$total]]);
    }
    public function videoCreate(Request $request)
    {
        $data = $request->post();
        $data['create_time'] = date('Y-m-d H:i:s');
        $id = Db::name('content_video')->insertGetId($data);
        // 更新album视频数
        if (!empty($data['album_id'])) {
            $count = Db::name('content_video')->where('album_id', $data['album_id'])->count();
            Db::name('content_album')->where('id', $data['album_id'])->update(['video_count' => $count]);
        }
        return json(['code'=>0, 'msg'=>'success', 'data'=>['id'=>$id]]);
    }
    public function videoUpdate(Request $request)
    {
        $data = $request->post();
        $id = $data['id'] ?? null;
        if (!$id) {
            return json(['code'=>1, 'msg'=>'缺少id参数']);
        }
        unset($data['id']);
        Db::name('content_video')->where('id', $id)->update($data);
        // 更新album视频数
        if (!empty($data['album_id'])) {
            $count = Db::name('content_video')->where('album_id', $data['album_id'])->count();
            Db::name('content_album')->where('id', $data['album_id'])->update(['video_count' => $count]);
        }
        return json(['code'=>0, 'msg'=>'success']);
    }
    public function videoDelete(Request $request, $id)
    {
        // 先查album_id
        $album_id = Db::name('content_video')->where('id', $id)->value('album_id');
        Db::name('content_video')->where('id', $id)->delete();
        if ($album_id) {
            $count = Db::name('content_video')->where('album_id', $album_id)->count();
            Db::name('content_album')->where('id', $album_id)->update(['video_count' => $count]);
        }
        return json(['code'=>0, 'msg'=>'success']);
    }
    public function videoBatchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        // 先查album_id列表
        $album_ids = Db::name('content_video')->whereIn('id', $ids)->column('album_id');
        Db::name('content_video')->whereIn('id', $ids)->delete();
        foreach (array_unique($album_ids) as $album_id) {
            if ($album_id) {
                $count = Db::name('content_video')->where('album_id', $album_id)->count();
                Db::name('content_album')->where('id', $album_id)->update(['video_count' => $count]);
            }
        }
        return json(['code'=>0, 'msg'=>'success']);
    }

    // ----------- 选项 -----------
    public function influencerOptions()
    {
        $list = Db::name('influencer')->field('id, nickname')->select();
        return json(['code'=>0, 'msg'=>'success', 'data'=>$list]);
    }
    public function albumOptions()
    {
        $list = Db::name('content_album')->field('id, title, influencer_id')->select();
        return json(['code'=>0, 'msg'=>'success', 'data'=>$list]);
    }
    public function tagOptions()
    {
        $list = Db::name('content_tag')->field('id, name')->select();
        return json(['code'=>0, 'msg'=>'success', 'data'=>['list'=>$list]]);
    }
    public function optionInfluencer()
    {
        // 返回一个空数组或实际需要的博主选项
        $list = Db::name('influencer')->field('id, nickname')->select();
        return json(['code' => 0, 'msg' => 'success', 'data' => $list]);
    }
    public function optionAlbum()
    {
        $list = Db::name('content_album')->field('id, title, influencer_id')->select();
        return json(['code'=>0, 'msg'=>'success', 'data'=>$list]);
    }
    public function optionTag()
    {
        $list = Db::name('content_tag')->field('id, name')->select();
        return json(['code'=>0, 'msg'=>'success', 'data'=>['list'=>$list]]);
    }

    // ----------- 兼容旧前端接口 -----------
    public function videoAdd(Request $request)
    {
        // 兼容旧前端，直接复用 videoCreate 逻辑
        return $this->videoCreate($request);
    }
    public function albumAdd(Request $request)
    {
        // 兼容旧前端，直接复用 albumCreate 逻辑
        return $this->albumCreate($request);
    }

    // ----------- 新增批量设置VIP/金币接口 -----------
    // 批量设置视频/图片VIP
    public function videoBatchSetVIP(Request $request)
    {
        $ids = $request->post('ids', []);
        $is_vip = $request->post('is_vip', 0);
        if (!$ids) return json(['code'=>1, 'msg'=>'缺少ids']);
        Db::name('content_video')->whereIn('id', $ids)->update(['is_vip' => $is_vip]);
        return json(['code'=>0, 'msg'=>'success']);
    }

    // 批量设置视频/图片金币
    public function videoBatchSetCoin(Request $request)
    {
        $ids = $request->post('ids', []);
        $coin = $request->post('coin', 0);
        if (!$ids) return json(['code'=>1, 'msg'=>'缺少ids']);
        Db::name('content_video')->whereIn('id', $ids)->update(['coin' => $coin]);
        return json(['code'=>0, 'msg'=>'success']);
    }

    // 专辑设为VIP（同步内容）
    public function albumSetVIP(Request $request)
    {
        $album_id = $request->post('album_id');
        $is_vip = $request->post('is_vip', 0);
        if (!$album_id) return json(['code'=>1, 'msg'=>'缺少album_id']);
        Db::name('content_album')->where('id', $album_id)->update(['is_vip' => $is_vip]);
        Db::name('content_video')->where('album_id', $album_id)->update(['is_vip' => $is_vip]);
        return json(['code'=>0, 'msg'=>'success']);
    }

    // 专辑设置金币（同步内容）
    public function albumSetCoin(Request $request)
    {
        $album_id = $request->post('album_id');
        $coin = $request->post('coin', 0);
        if (!$album_id) return json(['code'=>1, 'msg'=>'缺少album_id']);
        Db::name('content_album')->where('id', $album_id)->update(['coin' => $coin]);
        Db::name('content_video')->where('album_id', $album_id)->update(['coin' => $coin]);
        return json(['code'=>0, 'msg'=>'success']);
    }
}
