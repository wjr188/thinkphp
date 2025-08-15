<?php
namespace app\controller\api;

use think\Request;
use app\BaseController;
use app\model\DarknetVideo as VideoModel;
use think\facade\Db;

class DarknetVideoController extends BaseController
{
    // 列表 GET /api/darknet/videos/list
    public function list(Request $request)
    {
        $params = $request->get();
        $page = max(1, intval($params['page'] ?? 1));
        $pageSize = max(1, intval($params['pageSize'] ?? 10));
        $where = [];
        if (!empty($params['keyword'])) {
            $where[] = ['title', 'like', "%{$params['keyword']}%"];
        }
        if (!empty($params['parent_id'])) {
            $where[] = ['parent_id', '=', $params['parent_id']];
        }
        if (!empty($params['category_id'])) {
            $where[] = ['category_id', '=', $params['category_id']];
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $where[] = ['status', '=', $params['status']];
        }

        // 标签筛选（支持多标签，模糊匹配）
        if (!empty($params['tags']) && is_array($params['tags'])) {
            foreach ($params['tags'] as $tag) {
                $where[] = ['tags', 'like', "%{$tag}%"];
            }
        }

        // 新增：过滤已选视频ID
        if (!empty($params['exclude_ids'])) {
            $excludeIds = is_array($params['exclude_ids']) ? $params['exclude_ids'] : explode(',', $params['exclude_ids']);
            $where[] = ['id', 'not in', $excludeIds];
        }

        // 先过滤，再分页
        $query = VideoModel::where($where)->order('id desc');
        $total = $query->count();
        $list = $query->page($page, $pageSize)->select()->toArray();

        // 字段适配
        foreach ($list as &$v) {
            $v['tags'] = isset($v['tags']) ? explode(',', $v['tags']) : [];
            $v['is_vip'] = isset($v['is_vip']) ? intval($v['is_vip']) : 0;
            $v['gold'] = isset($v['gold']) ? intval($v['gold']) : 0;
        }
        unset($v);

        return json(['code'=>0, 'data'=>[
            'list' => $list,
            'total' => $total
        ]]);
    }

    // 新增 POST /api/darknet/videos/add
    public function add(Request $request)
    {
        $data = $request->post();

        // 适配字段
        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tags'] = json_encode($data['tags'], JSON_UNESCAPED_UNICODE); // 改成和长视频一样
        }
        if (isset($data['is_vip'])) {
            $data['is_vip'] = $data['is_vip'] ? 1 : 0;
        }
        if (isset($data['gold'])) {
            $data['gold'] = intval($data['gold']);
        }

        $video = VideoModel::create($data);
        return json(['code'=>0, 'msg'=>'添加成功', 'id'=>$video->id]);
    }

    // 编辑 POST /api/darknet/videos/update
    public function update(Request $request)
    {
        $data = $request->post();
        $id = $data['id'] ?? 0;
        unset($data['id']);

        // 适配字段
        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tags'] = json_encode($data['tags'], JSON_UNESCAPED_UNICODE); // 改成和长视频一样
        }
        if (isset($data['is_vip'])) {
            $data['is_vip'] = $data['is_vip'] ? 1 : 0;
        }
        if (isset($data['gold'])) {
            $data['gold'] = intval($data['gold']);
        }

        VideoModel::update($data, ['id' => $id]);
        return json(['code'=>0, 'msg'=>'保存成功']);
    }

    // 删除 POST /api/darknet/videos/delete
    public function delete(Request $request)
    {
        $id = $request->post('id');
        VideoModel::destroy($id);
        return json(['code'=>0, 'msg'=>'删除成功']);
    }

    // 批量删除 POST /api/darknet/videos/batch-delete
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids');
        VideoModel::destroy($ids);
        return json(['code'=>0, 'msg'=>'批量删除成功']);
    }

    // 批量设置VIP POST /api/darknet/videos/batch-set-vip
    public function batchSetVip(Request $request)
    {
        $ids = $request->post('ids');
        $is_vip = $request->post('is_vip', 1);
        VideoModel::whereIn('id', $ids)->update(['is_vip' => $is_vip ? 1 : 0]);
        return json(['code'=>0, 'msg'=>'VIP设置成功']);
    }

    

    // 批量设置金币 POST /api/darknet/videos/batch-set-gold
    public function batchSetGold(Request $request)
    {
        $ids = $request->post('ids');
        $gold = $request->post('gold');
        VideoModel::whereIn('id', $ids)->update(['gold' => intval($gold)]);
        return json(['code'=>0, 'msg'=>'金币设置成功']);
    }

    // 批量设置播放数 POST /api/darknet/videos/batch-set-play
    public function batchSetPlay(Request $request)
    {
        $ids = $request->post('ids');
        $playCount = intval($request->post('play_count', 0));
        VideoModel::whereIn('id', $ids)->update(['play' => $playCount]);
        return json(['code'=>0, 'msg'=>'播放数设置成功']);
    }

    // 批量设置收藏数 POST /api/darknet/videos/batch-set-collect
    public function batchSetCollect(Request $request)
    {
        $ids = $request->post('ids');
        $collectCount = intval($request->post('collect_count', 0));
        VideoModel::whereIn('id', $ids)->update(['collect' => $collectCount]);
        return json(['code'=>0, 'msg'=>'收藏数设置成功']);
    }

    // 批量设置点赞数 POST /api/darknet/videos/batch-set-like
    public function batchSetLike(Request $request)
    {
        $ids = $request->post('ids');
        $likeCount = intval($request->post('like_count', 0));
        VideoModel::whereIn('id', $ids)->update(['like' => $likeCount]);
        return json(['code'=>0, 'msg'=>'点赞数设置成功']);
    }

    // 获取单个视频详情 GET /api/darknet/videos/:id
    public function getById($id)
    {
        $data = VideoModel::find($id);
        if (!$data) return json(['code'=>1, 'msg'=>'未找到该视频']);

        $data = $data->toArray();
        $data['tags'] = isset($data['tags']) ? explode(',', $data['tags']) : [];
        $data['is_vip'] = isset($data['is_vip']) ? intval($data['is_vip']) : 0;
        $data['gold'] = isset($data['gold']) ? intval($data['gold']) : 0;

        return json(['code'=>0, 'data'=>$data]);
    }

    // H5专用列表 GET /api/darknet/videos/h5-list
    public function h5List(Request $request)
    {
        $params = $request->get();
        $parentId = isset($params['parent_id']) ? intval($params['parent_id']) : 0;
        $page = max(1, intval($params['page'] ?? 1));
        $pageSize = max(1, intval($params['pageSize'] ?? 3)); // 每页3个子分类

        // 1. 查主分类
        $parent = Db::name('darknet_category')
            ->where('id', $parentId)
            ->find();
        if (!$parent) {
            return json(['code' => 1, 'msg' => '主分类不存在', 'data' => []]);
        }

        // 2. 查子分类，分页，按sort排序
        $childrenQuery = Db::name('darknet_category')
            ->where('parent_id', $parentId)
            ->order('sort asc, id asc');
        $total = $childrenQuery->count();
        $children = $childrenQuery->page($page, $pageSize)->select()->toArray();

        // 3. 每个子分类查6条视频，按sort排序
        foreach ($children as &$c) {
            $videos = Db::name('darknet_video')
                ->where('status', 1)
                ->where('category_id', $c['id'])
                ->order('sort asc, id desc')
                ->limit(6)
                ->field([
                    'id', 'title', 'cover', 'duration', 'tags', 'sort',
                    'is_vip', 'gold', 'play', 'collect'
                ])
                ->select()
                ->toArray();

            foreach ($videos as &$v) {
                // 兼容 tags 为 JSON 字符串或逗号分隔字符串
                if (is_string($v['tags'])) {
                    if (preg_match('/^\[.*\]$/', $v['tags'])) {
                        $v['tags'] = json_decode($v['tags'], true) ?: [];
                    } else {
                        $v['tags'] = explode(',', $v['tags']);
                    }
                }
                // 递归处理 ["xxx"] 这种情况
                while (
                    is_array($v['tags']) &&
                    count($v['tags']) === 1 &&
                    is_string($v['tags'][0]) &&
                    preg_match('/^\[.*\]$/', $v['tags'][0])
                ) {
                    $v['tags'] = json_decode($v['tags'][0], true) ?: [];
                }
                $v['vip'] = (bool)$v['is_vip'];
                $v['coin'] = (int)$v['gold'];
                $v['play'] = (int)$v['play'];
                $v['collect'] = (int)$v['collect'];
                unset($v['is_vip'], $v['gold']);
            }
            unset($v);

            $c['videos'] = $videos;
        }
        unset($c);

        file_put_contents(runtime_path() . 'children.log', json_encode($children) . "\n", FILE_APPEND);

        // 4. 返回结构
        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'categories' => $children,
                'total' => $total,
                'current_page' => $page,
                'total_pages' => ceil($total / $pageSize),
                'per_page' => $pageSize,
                'parent' => $parent
            ]
        ]);
    }
/**
 * 获取某个子分类下的全部视频（分页+排序）
 * GET /api/h5/long_video/category/:category_id
 */
public function categoryVideos(Request $request, $category_id)
{
    $page = max(1, intval($request->get('page', 1)));
    $pageSize = max(1, intval($request->get('pageSize', 20)));
    $sort = $request->get('sort', 'id desc');
    $random = intval($request->get('random', 0)); // 新增

    // 支持多种排序
    $sortMap = [
        'collect' => 'collect desc',
        'play' => 'play desc',
        'sort' => 'sort asc, id desc',
        'id' => 'id desc'
    ];
    $order = $sortMap[$sort] ?? $sortMap['id'];

    $query = Db::name('darknet_video')
        ->where('status', 1)
        ->where('category_id', $category_id);

    $total = $query->count();

    // 随机换一批
    if ($random) {
        $list = $query
            ->orderRaw('rand()')
            ->limit($pageSize)
            ->field([
                'id', 'title', 'cover as cover_url', 'duration', 'tags', 'sort',
                'is_vip', 'gold', 'play', 'collect'
            ])
            ->select()
            ->toArray();
        $page = 1;
    } else {
        $list = $query
            ->order($order)
            ->page($page, $pageSize)
            ->field([
                'id', 'title', 'cover as cover_url', 'duration', 'tags', 'sort',
                'is_vip', 'gold', 'play', 'collect'
            ])
            ->select()
            ->toArray();
    }

    foreach ($list as &$v) {
        // 兼容 tags 为 JSON 字符串或逗号分隔字符串
        if (is_string($v['tags'])) {
            if (preg_match('/^\[.*\]$/', $v['tags'])) {
                $v['tags'] = json_decode($v['tags'], true) ?: [];
            } else {
                $v['tags'] = explode(',', $v['tags']);
            }
        }
        // 递归处理 ["xxx"] 这种情况
        while (
            is_array($v['tags']) &&
            count($v['tags']) === 1 &&
            is_string($v['tags'][0]) &&
            preg_match('/^\[.*\]$/', $v['tags'][0])
        ) {
            $v['tags'] = json_decode($v['tags'][0], true) ?: [];
        }
        $v['vip'] = (bool)$v['is_vip'];
        $v['coin'] = (int)$v['gold'];
        $v['play'] = (int)$v['play'];
        $v['collect'] = (int)$v['collect'];
        unset($v['is_vip'], $v['gold']);
    }
    unset($v);

    return json([
        'code' => 0,
        'msg' => '获取子分类视频成功',
        'data' => [
            'list' => $list,
            'total' => $total,
            'category_id' => intval($category_id),
            'current_page' => $page,
            'total_pages' => ceil($total / $pageSize),
            'per_page' => $pageSize
        ]
    ]);
}


}
