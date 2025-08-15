<?php
namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\facade\Request;

class DarknetRecommendController extends BaseController
{
    // 获取推荐分组列表
    public function list()
    {
        $params = Request::param();
        $keyword = $params['keyword'] ?? '';

        $query = Db::name('darknet_recommend_group');

        if (!empty($keyword)) {
            $query->where('name', 'like', "%{$keyword}%");
        }

        $list = $query->order('sort', 'asc')->select()->toArray();

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => count($list)
            ]
        ]);
    }

    // 添加推荐分组
    public function add()
    {
        $params = Request::param();

        $data = [
            'name' => $params['name'],
            'sort' => $params['sort'] ?? 1,
            'icon' => $params['icon'] ?? '', // 新增
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s')
        ];

        $id = Db::name('darknet_recommend_group')->insertGetId($data);

        if ($id) {
            return json([
                'code' => 200,
                'msg' => '添加成功',
                'data' => ['id' => $id]
            ]);
        }

        return json(['code' => 500, 'msg' => '添加失败']);
    }

    // 更新推荐分组
    public function update()
    {
        $params = Request::param();

        $data = [
            'name' => $params['name'],
            'sort' => $params['sort'],
            'icon' => $params['icon'] ?? '', // 新增
            'update_time' => date('Y-m-d H:i:s')
        ];

        $result = Db::name('darknet_recommend_group')
            ->where('id', $params['id'])
            ->update($data);

        if ($result !== false) {
            return json(['code' => 200, 'msg' => '更新成功']);
        }

        return json(['code' => 500, 'msg' => '更新失败']);
    }

    // 删除推荐分组
    public function delete()
    {
        $id = Request::param('id');

        Db::startTrans();
        try {
            // 删除分组
            Db::name('darknet_recommend_group')->where('id', $id)->delete();
            // 删除关联的视频
            Db::name('darknet_recommend_video')->where('recommend_id', $id)->delete();

            Db::commit();
            return json(['code' => 200, 'msg' => '删除成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => '删除失败']);
        }
    }

    // 保存分组排序
    public function sort()
    {
        $sortedData = Request::param('sortedData/a', []);

        try {
            foreach ($sortedData as $item) {
                Db::name('darknet_recommend_group')
                    ->where('id', $item['id'])
                    ->update([
                        'sort' => $item['sort'],
                        'update_time' => time()
                    ]);
            }
            return json(['code' => 200, 'msg' => '排序保存成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '排序保存失败']);
        }
    }

    // 获取分组下的视频列表
    public function groupVideos($groupId)
    {
        $recommendId = $groupId;
        $videos = Db::name('darknet_recommend_video')
            ->alias('drv')
            ->join('darknet_video dv', 'dv.id = drv.video_id')
            ->where('drv.recommend_id', $recommendId)
            ->field('drv.id as recommend_id, drv.video_id, dv.title, drv.sort, dv.cover')
            ->order('drv.sort', 'asc')
            ->select()
            ->toArray();

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => $videos
        ]);
    }

    // 保存分组下的视频
    public function saveGroupVideos($groupId)
    {
        $recommendId = $groupId;
        $videos = Request::param('videos/a', []);

        Db::startTrans();
        try {
            Db::name('darknet_recommend_video')->where('recommend_id', $recommendId)->delete();

            $insertData = [];
            foreach ($videos as $video) {
                if (empty($video['video_id'])) continue;
                $insertData[] = [
                    'recommend_id' => $recommendId,
                    'video_id' => $video['video_id'],
                    'sort' => $video['sort'] ?? 1,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s')
                ];
            }

            if (!empty($insertData)) {
                Db::name('darknet_recommend_video')->insertAll($insertData);
            }

            Db::commit();
            return json(['code' => 200, 'msg' => '保存成功']);
        } catch (\Exception $e) {
            Db::rollback();
            file_put_contents(runtime_path() . 'darknet_recommend_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return json(['code' => 500, 'msg' => '保存失败']);
        }
    }

    // 获取所有可选的视频列表
    public function allVideos()
    {
        try {
            $params = Request::get([
                'keyword'      => '',
                'parentId/d'   => 0,
                'categoryId/d' => 0,
                'currentPage/d'=> 1,
                'pageSize/d'   => 10
            ]);

            $query = Db::name('darknet_video')
                ->alias('dv')
                ->field('dv.id, dv.title, dv.category_id, dc.name AS child_category_name, dp.name AS main_category_name')
                ->leftJoin('darknet_category dc', 'dv.category_id = dc.id')
                ->leftJoin('darknet_category dp', 'dc.parent_id = dp.id');

            if (!empty($params['keyword'])) {
                $query->where('dv.title', 'like', '%' . $params['keyword'] . '%');
            }
            if ($params['parentId'] > 0) {
                $query->where('dp.id', $params['parentId']);
            }
            if ($params['categoryId'] > 0) {
                $query->where('dc.id', $params['categoryId']);
            }

            $total = $query->count();
            $list = $query->page($params['currentPage'], $params['pageSize'])->select()->toArray();

            return json([
                'code' => 200,
                'msg'  => 'success',
                'data' => [
                    'list'  => $list,
                    'total' => $total
                ]
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg'  => '获取视频列表失败: ' . $e->getMessage(),
                'data' => [
                    'list' => [],
                    'total' => 0
                ]
            ]);
        }
    }

    // 获取所有主分类
    public function parents()
    {
        $list = Db::name('darknet_category')->where('parent_id', 0)->select()->toArray();
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => count($list)
            ]
        ]);
    }

    // 获取所有子分类
    public function children()
    {
        $list = Db::name('darknet_category')->where('parent_id', '<>', 0)->select()->toArray();
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => count($list)
            ]
        ]);
    }

    /**
     * H5首页推荐分组及分组下视频（darknet 版）
     * GET /api/h5/darknet/home?page=1&pageSize=3
     */
    public function h5Home()
    {
        try {
            $page = max(1, intval(Request::get('page', 1)));
            $pageSize = max(1, intval(Request::get('pageSize', 3)));

            // 1. 查总分组数
            $totalGroups = Db::name('darknet_recommend_group')->count();

            // 2. 查本页分组，按sort升序
            $groups = Db::name('darknet_recommend_group')
                ->order('sort', 'asc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            $result = [];
            foreach ($groups as $group) {
                // 3. 查分组下视频，按视频sort升序，只取前5个
                $videos = Db::name('darknet_recommend_video')
                    ->alias('drv')
                    ->where('drv.recommend_id', $group['id'])
                    ->join('darknet_video dv', 'drv.video_id = dv.id', 'LEFT')
                    ->field([
                        'dv.id',
                        'dv.cover',
                        'dv.title',
                        'dv.collect as collect_count',
                        'dv.play as play_count',
                        'dv.gold as coin',
                        'dv.is_vip',
                        'dv.upload_time as create_time',
                        'dv.tags',
                        'dv.preview',
                    ])
                    ->order('drv.sort', 'asc')
                    ->limit(5)
                    ->select()
                    ->toArray();

                // tags 字段转数组
                foreach ($videos as &$video) {
                    if (!empty($video['tags'])) {
                        $tags = $video['tags'];
                        if (is_string($tags)) {
                            $tagsArr = json_decode($tags, true);
                            if (is_array($tagsArr)) {
                                $video['tags'] = $tagsArr;
                            } else {
                                // 不是json，按逗号分割
                                $video['tags'] = array_filter(array_map('trim', explode(',', $tags)));
                            }
                        } elseif (is_array($tags)) {
                            $video['tags'] = $tags;
                        } else {
                            $video['tags'] = [$tags];
                        }
                    } else {
                        $video['tags'] = [];
                    }
                }
                unset($video);

                $result[] = [
                    'id' => $group['id'],
                    'name' => $group['name'],
                    'sort' => $group['sort'],
                    'icon' => $group['icon'] ?? '', // 新增这一行
                    'videos' => $videos
                ];
            }

            $totalPages = ceil($totalGroups / $pageSize);

            return json([
                'code' => 0,
                'msg' => '',
                'data' => [
                    'groups' => $result,
                    'total' => $totalGroups,
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'per_page' => $pageSize
                ]
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 500,
                'msg' => 'h5首页推荐分组失败: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }
/****
 * H5专用：获取某个推荐分组下的视频列表（支持分页、排序）
 * GET /api/h5/darknet/group/:groupId/videos?page=1&pageSize=20&sort=collect
 */
public function h5GroupVideos($groupId)
{
    $groupId = intval($groupId);
    if (!$groupId) {
        return json(['code' => 1, 'msg' => '分组ID不能为空']);
    }

    $page = max(1, intval(Request::get('page', 1)));
    $pageSize = max(1, intval(Request::get('pageSize', 20)));
    $sort = Request::get('sort', '');

    // 1. 查分组下所有视频ID
    $videoIds = Db::name('darknet_recommend_video')
        ->where('recommend_id', $groupId)
        ->order('sort asc, id asc')
        ->column('video_id');

    if (empty($videoIds)) {
        return json([
            'code' => 0,
            'msg' => '该分组下暂无视频',
            'data' => [
                'list' => [],
                'total' => 0,
                'current_page' => $page,
                'total_pages' => 0,
                'per_page' => $pageSize
            ]
        ]);
    }

    // 2. 查视频详情
    $query = Db::name('darknet_video')
        ->whereIn('id', $videoIds)
        ->where('status', 1);

    // 排序
    if ($sort === 'collect') {
        $query->order('collect desc, id desc');
    } elseif ($sort === 'play') {
        $query->order('play desc, id desc');
    } elseif ($sort === 'new') {
        $query->order('id desc');
    } else {
        $query->order('id desc');
    }

    $total = $query->count();

    $list = $query->field([
            'id',
            'title',
            'cover',
            'duration',
            'tags',
            'is_vip',
            'gold',
            'play',
            'collect',
            'preview',
            'upload_time'
        ])
        ->page($page, $pageSize)
        ->select()
        ->toArray();

    // 字段适配
    foreach ($list as &$v) {
        // tags 兼容
        if (is_string($v['tags'])) {
            $tags = json_decode($v['tags'], true);
            $v['tags'] = is_array($tags) ? $tags : [$v['tags']];
        } elseif (is_array($v['tags'])) {
            // ok
        } else {
            $v['tags'] = [];
        }
        $v['vip'] = (bool)$v['is_vip'];
        $v['coin'] = (int)$v['gold'];
        unset($v['is_vip'], $v['gold']);
    }
    unset($v);

    return json([
        'code' => 0,
        'msg' => '获取分组视频成功',
        'data' => [
            'list' => $list,
            'total' => $total,
            'current_page' => $page,
            'total_pages' => ceil($total / $pageSize),
            'per_page' => $pageSize
        ]
    ]);
}

/**
 * 批量获取所有分组下视频数量
 * GET /api/darknet/recommend/groups/video-counts
 */
public function groupVideoCounts()
{
    $groups = Db::name('darknet_recommend_group')->field('id')->select()->toArray();
    $counts = [];
    foreach ($groups as $g) {
        $counts[$g['id']] = Db::name('darknet_recommend_video')->where('recommend_id', $g['id'])->count();
    }
    return json([
        'code' => 0,
        'data' => [
            'counts' => $counts
        ]
    ]);
}

    // 获取所有已使用的视频ID
    public function allUsedVideoIds()
    {
        $ids = Db::name('darknet_recommend_video')->column('video_id');
        return json(['code' => 0, 'data' => ['usedVideoIds' => array_map('intval', $ids)]]);
    }

}
