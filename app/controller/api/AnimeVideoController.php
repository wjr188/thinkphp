<?php
namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\Request;

class AnimeVideoController extends BaseController
{
    // 列表 GET /api/anime/videos/list
    public function list(Request $request)
    {
        $param = $request->get();
        $keyword = $param['keyword'] ?? '';
        $parentId = $param['parentId'] ?? '';
        $categoryId = $param['categoryId'] ?? '';
         $page = max(1, intval($param['page'] ?? $param['currentPage'] ?? 1));
    $pageSize = max(1, intval($param['pageSize'] ?? $param['pagesize'] ?? $param['limit'] ?? 10));

        $query = Db::name('anime_videos');

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('id', 'like', '%' . $keyword . '%')
                  ->orWhere('title', 'like', '%' . $keyword . '%');
            });
        }
        if (!empty($parentId)) {
            $query->where('parent_id', (int)$parentId);
        }
        if (!empty($categoryId)) {
            $query->where('category_id', (int)$categoryId);
        }

        $total = $query->count();
        $list = $query
        ->order('id', 'desc')
        ->page($page, $pageSize)
        ->select()
        ->toArray();
         // ==== 这里加：tags id转name ====
    $tagMap = Db::name('anime_tags')->column('name', 'id');
    foreach ($list as &$row) {
        $tagIds = array_filter(explode(',', $row['tags'] ?? ''));
        $row['tag_ids'] = $tagIds;
        $row['tag_names'] = [];
        foreach ($tagIds as $tid) {
            if (isset($tagMap[$tid])) {
                $row['tag_names'][] = $tagMap[$tid];
            }
        }
    }
    unset($row);

        return json([
            'code' => 0,
            'data' => [
                'list' => $list ?: [],
                'total' => $total
            ]
        ]);
    }

    // 单条详情 GET /api/anime/videos/:id
    public function getById($id)
    {
        $info = Db::name('anime_videos')->where('id', $id)->find();
        if ($info) {
        $tagMap = Db::name('anime_tags')->column('name', 'id');
        $tagIds = array_filter(explode(',', $info['tags'] ?? ''));
        $info['tag_ids'] = $tagIds;
        $info['tag_names'] = [];
        foreach ($tagIds as $tid) {
            if (isset($tagMap[$tid])) {
                $info['tag_names'][] = $tagMap[$tid];
            }
        }
    }
        return json(['code'=>0, 'data'=>$info]);
    }
// 新增 POST /api/anime/videos/add
public function add(Request $request)
{
    $data = $request->post();
    $data['create_time'] = date('Y-m-d H:i:s');
    $data['update_time'] = date('Y-m-d H:i:s');
    // 兼容金币/vip字段（前端传 gold/coin/is_vip/vip）
    if (isset($data['vip'])) {
        $data['is_vip'] = $data['vip'] ? 1 : 0;
        unset($data['vip']);
    }
    if (isset($data['tags']) && is_array($data['tags'])) {
        $data['tags'] = implode(',', $data['tags']);
    }
    // 自动识别视频时长
    if (!empty($data['video_url'])) {
        $duration = $this->getVideoDuration($data['video_url']);
        if ($duration > 0) {
            $data['duration'] = $duration;
        }
    }
    $id = Db::name('anime_videos')->insertGetId($data);
    return json(['code'=>0, 'msg'=>'添加成功', 'id'=>$id]);
}

// 编辑 POST /api/anime/videos/update
public function update(Request $request)
{
    $data = $request->post();
    $id = intval($data['id'] ?? 0);
    if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
    $data['update_time'] = date('Y-m-d H:i:s');
    // 兼容金币/vip字段
    if (isset($data['vip'])) {
        $data['is_vip'] = $data['vip'] ? 1 : 0;
        unset($data['vip']);
    }
    if (isset($data['tags']) && is_array($data['tags'])) {
        $data['tags'] = implode(',', $data['tags']);
    }
    // 自动识别视频时长
    if (!empty($data['video_url'])) {
        $duration = $this->getVideoDuration($data['video_url']);
        if ($duration > 0) {
            $data['duration'] = $duration;
        }
    }
    Db::name('anime_videos')->where('id', $id)->update($data);
    return json(['code'=>0, 'msg'=>'编辑成功']);
}

/**
 * 通过 ffprobe 获取视频时长（秒）
 */
private function getVideoDuration($videoUrl)
{
    // 注意 Windows 或 Linux 都建议写成这样
    $cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"$videoUrl\"";
    $output = shell_exec($cmd);
    if ($output !== false && is_numeric(trim($output))) {
        return intval(floatval(trim($output)));
    }
    return 0;
}

    // 删除（单条） POST /api/anime/videos/delete
    public function delete(Request $request)
    {
        $id = intval($request->post('id'));
        if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
        Db::name('anime_videos')->where('id', $id)->delete();
        return json(['code'=>0, 'msg'=>'删除成功']);
    }

    // 批量删除 POST /api/anime/videos/batch-delete
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
        Db::name('anime_videos')->whereIn('id', $ids)->delete();
        return json(['code'=>0, 'msg'=>'批量删除成功']);
    }

    // 批量设置VIP POST /api/anime/videos/batch-set-vip
    public function batchSetVip(Request $request)
    {
        $ids = $request->post('ids', []);
        $is_vip = $request->post('is_vip', 1);
        if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
        Db::name('anime_videos')->whereIn('id', $ids)->update(['is_vip' => $is_vip ? 1 : 0]);
        return json(['code'=>0, 'msg'=>'VIP设置成功']);
    }

    // 批量设置试看时长 POST /api/anime/videos/batch-set-preview
    public function batchSetPreview(Request $request)
    {
        $ids = $request->post('ids', []);
        $preview = $request->post('preview', '');
        if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
        Db::name('anime_videos')->whereIn('id', $ids)->update(['preview' => $preview]);
        return json(['code'=>0, 'msg'=>'试看时长设置成功']);
    }

    // 批量设置金币 POST /api/anime/videos/batch-set-gold
    public function batchSetGold(Request $request)
{
    $ids = $request->post('ids', []);
    $coin = $request->post('coin', 0);  // 改成 coin
    if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
    Db::name('anime_videos')->whereIn('id', $ids)->update(['coin' => intval($coin)]); // 修改字段为 coin
    return json(['code'=>0, 'msg'=>'金币设置成功']);
}

public function batchSetPlay(Request $request)
{
    $ids = $request->post('ids', []);
    $views = $request->post('views', 0); // 前端传 views
    if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
    Db::name('anime_videos')->whereIn('id', $ids)->update(['views' => intval($views)]);
    return json(['code'=>0, 'msg'=>'播放数设置成功']);
}

    // 批量设置收藏数 POST /api/anime/videos/batch-set-collect
public function batchSetCollect(Request $request)
{
    $ids = $request->post('ids', []);
    $collects = $request->post('collects', 0); // 改为 collects
    if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
    Db::name('anime_videos')->whereIn('id', $ids)->update(['collects' => intval($collects)]); // 改为 collects
    return json(['code'=>0, 'msg'=>'收藏数设置成功']);
}
// 批量设置点赞数 POST /api/anime/videos/batch-set-likes
public function batchSetLikes(Request $request)
{
    $ids = $request->post('ids', []);
    $likes = $request->post('likes', 0); // 前端传 likes
    if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
    Db::name('anime_videos')->whereIn('id', $ids)->update(['likes' => intval($likes)]);
    return json(['code'=>0, 'msg'=>'点赞数设置成功']);
}

}
