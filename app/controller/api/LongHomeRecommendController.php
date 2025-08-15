<?php
// 文件路径：E:\ThinkPHP6\app\controller\api\LongHomeRecommendController.php
namespace app\controller\api;

use app\BaseController;
use app\model\LongHomeRecommend; // Assuming this model now represents the "recommend groups" directly
use app\model\LongVideoCategory;
use app\model\LongHomeRecommendVideo;
use app\model\LongVideo;
use think\facade\Request;
use think\facade\Db;
use think\facade\Log;

class LongHomeRecommendController extends BaseController
{
    /**
     * 统一成功返回
     * @param string $msg 真正的业务消息
     * @param array $data 返回的数据
     * @param int $code 成功时 code 为 200 (ResultEnum.SUCCESS)
     * @return \think\response\Json
     */
    protected function successJson($msg = 'success', $data = [], $code = 200) // Changed code to 200 for consistency with ResultEnum.SUCCESS
    {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data
        ]);
    }

    /**
     * 统一失败返回
     * @param string $msg 真正的业务错误消息
     * @param int $code 失败时 code 为非 200，例如 400
     * @param array $data
     * @return \think\response\Json
     */
    protected function errorJson($msg = 'error', $code = 400, $data = [])
    {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data
        ]);
    }

    // =========================================================================
    // Recommendation Group Management (long_home_recommend table)
    // Corresponds to frontend: src/api/recommend.ts -> getRecommendGroups, addRecommendGroup, etc.
    // Frontend `RecommendGroup` type has `id`, `name`, `sort`.
    // ASSUMPTION: `long_home_recommend` table now has `id`, `name`, `sort` columns directly.
    // =========================================================================

    /**
     * 获取推荐分组列表
     * GET /api/recommend/groups
     * @param string keyword 关键词 (分组名)
     * @param int currentPage 当前页码
     * @param int pageSize 每页数量
     * @return \think\response\Json
     */
    public function getRecommendGroups()
    {
        try {
            $param = Request::get([
                'keyword'     => '',
                'currentPage' => 1,
                'pageSize'    => 10
            ]);

            $query = LongHomeRecommend::field('id, name, sort, icon')
                ->order('sort', 'asc');

            if (!empty($param['keyword'])) {
                $query->where('name', 'like', '%' . $param['keyword'] . '%');
            }

            $list = $query->page($param['currentPage'], $param['pageSize'])->select()->toArray();
            $total = $query->count();

            // 一次查出所有分组下的视频（只查ID、排序、标题）
            $groupIds = array_column($list, 'id');
            $videosMap = [];
            if ($groupIds) {
                $allVideos = Db::name('long_home_recommend_video')
                    ->alias('lhrv')
                    ->whereIn('lhrv.recommend_id', $groupIds)
                    ->join('long_videos lv', 'lhrv.video_id = lv.id', 'LEFT')
                    ->field('lhrv.recommend_id, lhrv.video_id, lhrv.sort, lv.title')
                    ->order('lhrv.recommend_id asc, lhrv.sort asc')
                    ->select()
                    ->toArray();
                foreach ($allVideos as $v) {
                    $videosMap[$v['recommend_id']][] = [
                        'video_id' => $v['video_id'],
                        'sort'     => $v['sort'],
                        'title'    => $v['title'],
                    ];
                }
            }

            // 合并到分组
            foreach ($list as &$g) {
                $g['videos'] = $videosMap[$g['id']] ?? [];
            }
            unset($g);

            $data = [
                'list'  => $list,
                'total' => $total,
            ];

            return $this->successJson('获取推荐分组列表成功', $data, 200);

        } catch (\Exception $e) {
            Log::error('获取推荐分组列表失败: ' . $e->getMessage());
            return $this->errorJson('获取推荐分组列表失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 添加新的推荐分组
     * POST /api/recommend/groups
     * @param string name 分组名称
     * @param int sort 排序值
     * @return \think\response\Json
     */
    public function addRecommendGroup()
    {
        $name = Request::post('name');
        $sort = Request::post('sort/d');
        $icon = Request::post('icon', '');

        if (empty($name)) {
            return $this->errorJson('分组名称不能为空', 400);
        }
        if (empty($sort)) {
            $maxSort = LongHomeRecommend::max('sort');
            $sort = ($maxSort ?? 0) + 1;
        }

        try {
            $existingGroup = LongHomeRecommend::where('name', $name)->find();
            if ($existingGroup) {
                return $this->errorJson('分组名称已存在', 409);
            }

            $group = LongHomeRecommend::create([
                'name' => $name,
                'sort' => $sort,
                'icon' => $icon, // 新增
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);

            return $this->successJson('分组添加成功', $group->toArray());
        } catch (\Exception $e) {
            Log::error('添加推荐分组失败: ' . $e->getMessage());
            return $this->errorJson('添加推荐分组失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 更新推荐分组
     * PUT /api/recommend/groups/:id
     * @param int id 推荐分组ID
     * @param string name 分组名称 (可选)
     * @param int sort 排序值 (可选)
     * @return \think\response\Json
     */
    public function updateRecommendGroup($id)
    {
        $name = Request::put('name');
        $sort = Request::put('sort/d');
        $icon = Request::put('icon', '');
        $category_id = Request::put('category_id/d'); // 接收 category_id 参数

        if (empty($id)) {
            return $this->errorJson('分组ID缺失', 400);
        }

        try {
            $group = LongHomeRecommend::find($id);
            if (!$group) {
                return $this->errorJson('分组不存在', 404);
            }

            $updateData = [];
            if (isset($name) && $name !== $group->name) {
                // Check for duplicate name if name is being changed
                $existingGroup = LongHomeRecommend::where('name', $name)->where('id', '<>', $id)->find();
                if ($existingGroup) {
                    return $this->errorJson('分组名称已存在', 409); // Conflict
                }
                $updateData['name'] = $name;
            }
            if (isset($sort)) {
                $updateData['sort'] = $sort;
            }
            if (isset($icon)) {
                $updateData['icon'] = $icon;
            }
            // 处理 category_id 更新
            if (isset($category_id) && $category_id != $group->category_id) {
                // 验证分类是否存在
                $categoryExists = LongVideoCategory::where('id', $category_id)->count();
                if ($categoryExists === 0) {
                    return $this->errorJson('所选分类不存在', 400);
                }
                $updateData['category_id'] = $category_id;
            }

            if (empty($updateData)) {
                return $this->errorJson('没有提供更新数据', 400);
            }

            $updateData['update_time'] = date('Y-m-d H:i:s');
            $group->save($updateData);

            return $this->successJson('分组更新成功');
        } catch (\Exception $e) {
            Log::error('更新推荐分组失败: ' . $e->getMessage());
            return $this->errorJson('更新推荐分组失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 删除推荐分组
     * DELETE /api/recommend/groups/:id
     * @param int id 推荐分组ID
     * @return \think\response\Json
     */
    public function deleteRecommendGroup($id)
    {
        if (empty($id)) {
            return $this->errorJson('分组ID缺失', 400);
        }

        Db::startTrans();
        try {
            $group = LongHomeRecommend::find($id);
            if (!$group) {
                Db::rollback();
                return $this->errorJson('分组不存在', 404);
            }

            // Also delete associated videos in LongHomeRecommendVideo table
            LongHomeRecommendVideo::where('recommend_id', $id)->delete();

            $group->delete();

            Db::commit();
            return $this->successJson('分组删除成功');
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('删除推荐分组失败: ' . $e->getMessage());
            return $this->errorJson('删除推荐分组失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 保存分组排序
     * POST /api/recommend/groups/sort
     * @param array data 格式：[['id' => 1, 'sort' => 1], ['id' => 2, 'sort' => 2]]
     * @return \think\response\Json
     */
    public function saveGroupSort()
    {
        $sortData = Request::post('data/a'); // Frontend sends 'data' as array

        if (!is_array($sortData) || empty($sortData)) {
            return $this->errorJson('排序数据参数缺失或格式不正确', 400);
        }

        Db::startTrans();
        try {
            foreach ($sortData as $item) {
                if (!isset($item['id']) || !isset($item['sort']) || !is_numeric($item['id']) || !is_numeric($item['sort'])) {
                    Db::rollback();
                    return $this->errorJson('排序数据项格式不正确，每项需包含 id 和 sort 字段且为数字', 400);
                }
                LongHomeRecommend::where('id', $item['id'])->update(['sort' => $item['sort'], 'update_time' => date('Y-m-d H:i:s')]);
            }
            Db::commit();
            return $this->successJson('分组排序保存成功');
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('保存分组排序失败: ' . $e->getMessage());
            return $this->errorJson('保存分组排序失败: ' . $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // Video Management for a Specific Recommendation Group (long_home_recommend_video)
    // Corresponds to frontend: src/api/recommend.ts -> getVideosForRecommendGroup, saveVideosForRecommendGroup
    // =========================================================================

    /**
     * 获取指定推荐分组下的所有视频列表
     * GET /api/recommend/groups/:groupId/videos
     * @param int groupId long_home_recommend 表的ID
     * @return \think\response\Json
     */
    public function getVideosForRecommendGroup($groupId)
    {
        if (empty($groupId)) {
            return $this->errorJson('分组ID缺失', 400);
        }

        try {
            // Check if the group exists
            $groupExists = LongHomeRecommend::where('id', $groupId)->count();
            if ($groupExists === 0) {
                return $this->errorJson('推荐分组不存在', 404);
            }

            // Query long_home_recommend_video table, and LEFT JOIN long_videos table to get video title
            $videoList = Db::name('long_home_recommend_video')
                ->alias('lhrv')
                ->where('lhrv.recommend_id', $groupId)
                ->join('long_videos lv', 'lhrv.video_id = lv.id', 'LEFT')
                ->field('lhrv.video_id, lhrv.sort, lv.title') // Assuming long_videos table has title field
                ->order('lhrv.sort', 'asc') // Order by sort field
                ->select()
                ->toArray();

            // Filter out videos with empty titles (i.e., lv.id did not exist)
            $filteredList = array_filter($videoList, function($item) {
                return !empty($item['title']);
            });

            // Re-index and re-assign sort for continuous order in case of filtered items
            $finalList = [];
            foreach ($filteredList as $index => $video) {
                $video['sort'] = $index + 1; // Reassign sequential order
                $finalList[] = $video;
            }

            // The frontend expects { list: T[], total: number } for paginated/list responses.
            // Even if not paginated here, consistent structure is good.
            $data = [
                'list' => $finalList,
                'total' => count($finalList),
            ];

            return $this->successJson('获取推荐视频列表成功', $data);

        } catch (\Exception $e) {
            Log::error('获取推荐视频列表失败: ' . $e->getMessage());
            return $this->errorJson('获取推荐视频列表失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 保存指定推荐分组下的视频列表及顺序
     * POST /api/recommend/groups/:groupId/videos
     * Receives groupId from URL segment, and video ID + sort from POST body.
     * Clears old associations, then inserts new videos and their order into `long_home_recommend_video`.
     * @param int groupId long_home_recommend 表的ID
     * @param array videos 格式：[['video_id' => 101, 'sort' => 1], ['video_id' => 102, 'sort' => 2]]
     * @return \think\response\Json
     */
    public function saveVideosForRecommendGroup($groupId)
    {
        $videosData = Request::post('videos/a');

        if (empty($groupId)) {
            return $this->errorJson('分组ID缺失', 400);
        }

        if (!is_array($videosData)) {
            return $this->errorJson('视频数据参数格式不正确，应为数组', 400);
        }

        $groupExists = LongHomeRecommend::where('id', $groupId)->count();
        if ($groupExists === 0) {
            return $this->errorJson('推荐分组不存在', 404);
        }

        $insertData = [];
        foreach ($videosData as $item) {
            if (
                !isset($item['video_id']) || !isset($item['sort']) ||
                !is_numeric($item['video_id']) || !is_numeric($item['sort'])
            ) {
                return $this->errorJson('每项需包含 video_id、sort 且为数字', 400);
            }
            $videoExists = LongVideo::where('id', $item['video_id'])->count();
            if ($videoExists === 0) {
                return $this->errorJson("视频ID {$item['video_id']} 不存在", 400);
            }

            $insertData[] = [
                'recommend_id' => $groupId,
                'video_id'     => $item['video_id'],
                'sort'         => $item['sort'],
                'create_time'  => date('Y-m-d H:i:s'),
                'update_time'  => date('Y-m-d H:i:s'),
            ];
        }

        Db::startTrans();
        try {
            LongHomeRecommendVideo::where('recommend_id', $groupId)->delete();
            if (!empty($insertData)) {
                LongHomeRecommendVideo::insertAll($insertData);
            }
            Db::commit();
            return $this->successJson('推荐视频保存成功');
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('保存推荐视频失败: ' . $e->getMessage());
            return $this->errorJson('保存推荐视频失败: ' . $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // General Video List and Category List APIs
    // Corresponds to frontend: src/api/video.ts
    // =========================================================================

    /**
     * 获取所有视频列表 (用于视频管理弹窗的左侧面板)
     * GET /api/long/videos
     * 新增 excludeIds 参数，分页前过滤已选视频
     */
    public function getAllVideosList()
    {
        try {
            $param = Request::get([
                'keyword'      => '',
                'parentId/d'   => 0,
                'categoryId/d' => 0,
                'currentPage/d'=> 1,
                'pageSize/d'   => 10,
                'excludeIds/a' => [], // 新增，前端传已选视频ID数组
            ]);

            $query = Db::name('long_videos')
                       ->alias('lv')
                       ->field('lv.id, lv.title, lv.category_id, lvc_child.name AS child_category_name, lvc_parent.name AS main_category_name')
                       ->leftJoin('long_video_categories lvc_child', 'lv.category_id = lvc_child.id')
                       ->leftJoin('long_video_categories lvc_parent', 'lvc_child.parent_id = lvc_parent.id')
                       ->where('lv.status', 1);

            if (!empty($param['keyword'])) {
                $query->where('lv.title', 'like', '%' . $param['keyword'] . '%');
            }
            if ($param['parentId'] > 0) {
                $query->where('lvc_parent.id', $param['parentId']);
            }
            if ($param['categoryId'] > 0) {
                $query->where('lvc_child.id', $param['categoryId']);
            }
            if (!empty($param['excludeIds'])) {
                $query->whereNotIn('lv.id', $param['excludeIds']);
            }

            // 先查总数
            $total = $query->count();

            // 再查分页数据
            $list = $query->page($param['currentPage'], $param['pageSize'])
                          ->select()
                          ->toArray();

            $data = [
                'list' => $list,
                'total' => $total,
            ];

            return $this->successJson('获取所有视频列表成功', $data);

        } catch (\Exception $e) {
            Log::error('获取所有视频列表失败: ' . $e->getMessage());
            return $this->errorJson('获取所有视频列表失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 获取所有父级视频分类 (用于筛选器的下拉选项)
     * GET /api/categories/parents
     * @return \think\response\Json
     */
    public function getAllParentCategories()
    {
        try {
            $parentCategories = LongVideoCategory::where('parent_id', 0)->field('id,name')->select()->toArray();
            $data = [
                'list'  => $parentCategories,
                'total' => count($parentCategories),
            ];
            return $this->successJson('获取主分类成功', $data);
        } catch (\Exception $e) {
            Log::error('获取主分类失败: ' . $e->getMessage());
            return $this->errorJson('获取主分类失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 获取所有子级视频分类
     * GET /api/categories/children
     * @return \think\response\Json
     */
    public function getAllChildCategories()
    {
        try {
            $childCategories = LongVideoCategory::where('parent_id', '>', 0)->field('id,name,parent_id')->select()->toArray();
            $data = [
                'list'  => $childCategories,
                'total' => count($childCategories),
            ];
            return $this->successJson('获取子分类成功', $data);
        } catch (\Exception $e) {
            Log::error('获取子分类失败: ' . $e->getMessage());
            return $this->errorJson('获取子分类失败: ' . $e->getMessage(), 500);
        }
    }

    /**
 * H5首页推荐分组及分组下视频（只返回基础信息）
 * GET /api/h5/long/home
 */
public function h5Home()
{
    try {
        $page = max(1, intval(Request::get('page', 1)));
        $pageSize = max(1, intval(Request::get('pageSize', 3))); // 每页分组数，默认3

        // 1. 查总分组数
        $totalGroups = LongHomeRecommend::count();

        // 2. 查本页分组，按sort升序
        $groups = LongHomeRecommend::order('sort', 'asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $result = [];
        foreach ($groups as $group) {
            // 3. 查分组下视频，按视频sort升序，只取前5个
            $videos = Db::name('long_home_recommend_video')
                ->alias('lhrv')
                ->where('lhrv.recommend_id', $group['id'])
                ->join('long_videos lv', 'lhrv.video_id = lv.id', 'LEFT')
                ->field([
                    'lv.id',
                    'lv.cover_url as cover',
                    'lv.title',
                    'lv.collect_count',
                    'lv.play_count',
                    'lv.gold_required as coin',
                    'lv.is_vip',
                    'lv.create_time',
                    'lv.duration',
                    'lv.tags',
                ])
                ->order('lhrv.sort', 'asc')
                ->limit(5)
                ->select()
                ->toArray();

            foreach ($videos as &$video) {
                if (!empty($video['tags'])) {
                    $tags = $video['tags'];
                    if (is_string($tags)) {
                        $tagsArr = json_decode($tags, true);
                        $video['tags'] = is_array($tagsArr) ? $tagsArr : [];
                    } elseif (is_array($tags)) {
                        $video['tags'] = $tags;
                    }
                } else {
                    $video['tags'] = [];
                }
                unset($video['tag']);
            }
            unset($video);

            $result[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'sort' => $group['sort'],
                'icon' => $group['icon'] ?? '',
                'videos' => $videos
            ];
        }

        // 分页信息
        $totalPages = ceil($totalGroups / $pageSize);

        return $this->successJson('', [
            'groups' => $result,
            'total' => $totalGroups,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $pageSize
        ], 0);
    } catch (\Exception $e) {
        \think\facade\Log::error('h5首页推荐分组失败: ' . $e->getMessage());
        return $this->errorJson('h5首页推荐分组失败: ' . $e->getMessage(), 500);
    }
}

/**
 * H5长视频详情接口
 * GET /api/h5/long/videos/:id
 */
public function h5Detail($id)
{
    try {
        $video = \app\model\LongVideo::where('id', $id)
            ->field('id, title, cover_url as cover, play_count, collect_count, tags, is_vip, gold_required, duration')
            ->find();
        if (!$video) {
            return $this->errorJson('视频不存在', 404);
        }
        // tags 字段转为数组
        if (!empty($video['tags'])) {
            $tags = $video['tags'];
            if (is_string($tags)) {
                $tagsArr = json_decode($tags, true);
                if (is_array($tagsArr)) {
                    $video['tags'] = $tagsArr;
                }
            } elseif (is_array($tags)) {
                $video['tags'] = $tags;
            }
        } else {
            $video['tags'] = [];
        }
        // 字段兼容
        $video['vip'] = $video['is_vip'];
        $video['coin'] = $video['gold_required'];

        return $this->successJson('', $video, 0);
    } catch (\Exception $e) {
        \think\facade\Log::error('h5视频详情失败: ' . $e->getMessage());
        return $this->errorJson('h5视频详情失败: ' . $e->getMessage(), 500);
    }
}

/**
 * H5前台：获取推荐分组下所有视频（前台专用，和后台隔离）
 * GET /api/h5/long/group/:groupId/videos
 */
public function h5GroupVideos($groupId)
{
    if (empty($groupId)) {
        return $this->errorJson('分组ID缺失', 400);
    }

    try {
        $pageSize = max(1, intval(Request::get('pageSize', 5)));
        $random = intval(Request::get('random', 0));
        $sort = Request::get('sort', 'collect');

        // 排序字段
        $sortField = 'lv.collect_count';
        if ($sort === 'play') {
            $sortField = 'lv.play_count';
        } elseif ($sort === 'new') {
            $sortField = 'lv.create_time';
        }

        // 查全部ID并排序
        $allIds = Db::name('long_home_recommend_video')
            ->alias('lhrv')
            ->join('long_videos lv', 'lhrv.video_id = lv.id', 'LEFT')
            ->where('lhrv.recommend_id', $groupId)
            ->order($sortField, 'desc')
            ->column('lhrv.video_id');

        $total = count($allIds);

        if ($random && $total > $pageSize) {
            shuffle($allIds);
            $ids = array_slice($allIds, 0, $pageSize);
        } else {
            $ids = array_slice($allIds, 0, $pageSize);
        }

        if (empty($ids)) {
            $videoList = [];
        } else {
            $videoList = Db::name('long_home_recommend_video')
                ->alias('lhrv')
                ->where('lhrv.recommend_id', $groupId)
                ->whereIn('lhrv.video_id', $ids)
                ->join('long_videos lv', 'lhrv.video_id = lv.id', 'LEFT')
                ->field([
                    'lv.id',
                    'lv.title',
                    'lv.cover_url as cover',
                    'lv.play_count',
                    'lv.collect_count',
                    'lv.tags',
                    'lv.is_vip',
                    'lv.gold_required as coin',
                    'lv.duration'
                ])
                ->orderRaw("field(lv.id," . implode(',', $ids) . ")")
                ->select()
                ->toArray();
        }

        // 处理 tags 字段为数组
        foreach ($videoList as &$video) {
            if (!empty($video['tags'])) {
                $tags = $video['tags'];
                if (is_string($tags)) {
                    $tagsArr = json_decode($tags, true);
                    $video['tags'] = is_array($tagsArr) ? $tagsArr : [];
                } elseif (is_array($tags)) {
                    $video['tags'] = $tags;
                }
            } else {
                $video['tags'] = [];
            }
        }
        unset($video);

        $data = [
            'list' => array_values($videoList),
            'total' => $total,
        ];

        return $this->successJson('ok', $data, 0);

    } catch (\Exception $e) {
        Log::error('h5获取推荐分组视频失败: ' . $e->getMessage());
        return $this->errorJson('h5获取推荐分组视频失败: ' . $e->getMessage(), 500);
    }
}
}