<?php
// app/controller/api/HomeRecommendController.php
namespace app\controller\api;

use app\BaseController;
use app\model\LongHomeRecommend;
use app\model\LongHomeRecommendVideo;
use app\model\LongVideo;

class HomeRecommendController extends BaseController
{
    // 1. 推荐分组列表（可用于首页推荐模块）
    public function groups()
    {
        $groups = LongHomeRecommend::order('sort', 'asc')->select();
        $list = [];
        foreach ($groups as $g) {
            $list[] = [
                'id'   => $g['id'],
                'name' => $g['name'],
                'sort' => $g['sort'],
                'category_id' => $g['category_id'],
                // 可按需加字段
            ];
        }
        return json([
            'code' => 200,
            'msg'  => 'ok',
            'data' => [
                'list' => $list,
                'total' => count($list)
            ]
        ]);
    }

    // 2. 推荐分组下的视频列表
    public function groupVideos($groupId)
    {
        $videoRecords = LongHomeRecommendVideo::where('recommend_id', $groupId)
            ->order('sort', 'asc')->select();
        $videoIds = [];
        foreach ($videoRecords as $vr) {
            $videoIds[] = $vr['video_id'];
        }
        $videos = [];
        if ($videoIds) {
            $videoMap = LongVideo::whereIn('id', $videoIds)
                ->field('id, title, cover_url, video_url, tags, duration, preview_duration')
                ->select()
                ->column(null, 'id');
            foreach ($videoIds as $vid) {
                if (isset($videoMap[$vid])) {
                    $item = $videoMap[$vid];
                    // 字段适配
                    $item['url'] = $item['video_url'];
                    $item['cover'] = $item['cover_url'];
                    $item['preview'] = $item['preview_duration'] ?? '';
                    $item['tags'] = is_array($item['tags']) ? $item['tags'] : (json_decode($item['tags'], true) ?: []);
                    unset($item['video_url'], $item['cover_url'], $item['preview_duration']);
                    $videos[] = $item;
                }
            }
        }
        return json([
            'code' => 200,
            'msg'  => 'ok',
            'data' => $videos
        ]);
    }

    // 3. 全部长视频列表（分页+分类可选）
    public function allVideos()
    {
        $page = input('get.page/d', 1);
        $pageSize = input('get.pageSize/d', 20);
        $categoryId = input('get.categoryId/d', 0);

        $query = LongVideo::field('id, title, cover_url, video_url, tags, duration, preview_duration');

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        $videos = $query
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        foreach ($videos as &$item) {
            $item['url'] = $item['video_url'];
            $item['cover'] = $item['cover_url'];
            $item['preview'] = $item['preview_duration'] ?? '';
            $item['tags'] = is_array($item['tags']) ? $item['tags'] : (json_decode($item['tags'], true) ?: []);
            unset($item['video_url'], $item['cover_url'], $item['preview_duration']);
        }
        unset($item);

        $total = $query->removeOption('page')->count(); // 获取筛选后总数

        return json([
            'code' => 200,
            'msg' => 'ok',
            'data' => [
                'list' => $videos,
                'total' => $total
            ]
        ]);
    }

    // 4. 视频详情
    public function videoDetail($id)
    {
        $video = LongVideo::where('id', $id)
            ->field('id, title, cover_url, video_url, tags, duration, preview_duration')
            ->find();
        if (!$video) {
            return json(['code' => 404, 'msg' => '未找到视频', 'data' => null]);
        }
        $video['url'] = $video['video_url'];
        $video['cover'] = $video['cover_url'];
        $video['preview'] = $video['preview_duration'] ?? '';
        $video['tags'] = is_array($video['tags']) ? $video['tags'] : (json_decode($video['tags'], true) ?: []);
        unset($video['video_url'], $video['cover_url'], $video['preview_duration']);
        return json(['code' => 200, 'msg' => 'ok', 'data' => $video]);
    }
}
