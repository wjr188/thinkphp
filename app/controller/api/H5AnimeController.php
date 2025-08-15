<?php
// 文件路径: app/controller/api/H5AnimeController.php

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;

class H5AnimeController extends BaseController
{
    /**
     * 只查主分类
     * GET /api/anime/category/list?onlyMain=1
     */
    public function list()
    {
        $param = request()->param();
        $onlyMain = intval($param['onlyMain'] ?? 0);

        if ($onlyMain === 1) {
            $mainCategories = Db::name('anime_categories')
                ->where('parent_id', 0)
                ->where('status', 1)
                ->order('sort', 'asc')
                ->select()->toArray();
            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'mainCategories' => $mainCategories
                ]
            ]);
        }

        // 默认返回全部主+子分类（管理后台用，前端可选用，不分页）
        $mainCategories = Db::name('anime_categories')
            ->where('parent_id', 0)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->select()->toArray();
        $subCategories = Db::name('anime_categories')
            ->where('parent_id', '>', 0)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->select()->toArray();
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'mainCategories' => $mainCategories,
                'subCategories' => $subCategories
            ]
        ]);
    }

    /**
     * 某个主分类下所有子分类（分页）及每个子分类下动漫（每组前N条）
     * GET /api/anime/category/group?parentId=xxx&page=1&pageSize=2&limit=6
     */
    public function group()
    {
        $param = request()->param();
        $parentId = intval($param['parentId'] ?? 0);
        $page = max(1, intval($param['page'] ?? 1));
        $pageSize = intval($param['pageSize'] ?? 2); // 每页多少个子分类
        $limit = intval($param['limit'] ?? 6);       // 每组子分类下的动漫数量

        if ($parentId <= 0) {
            return json(['code' => 1, 'msg' => 'parentId 必填', 'data' => []]);
        }

        // 分页子分类
        $subCategoriesQuery = Db::name('anime_categories')
            ->where('parent_id', $parentId)
            ->where('status', 1);

        $total = $subCategoriesQuery->count();

        $subCategories = $subCategoriesQuery
            ->order('sort', 'asc')
            ->page($page, $pageSize)
            ->select()->toArray();

        // 每个子分类查 limit 条动漫
        foreach ($subCategories as &$sub) {
            $sub['animes'] = Db::name('anime_videos')
                ->where('category_id', $sub['id'])
                ->where('status', 1)
                ->order('id', 'desc')
                ->limit($limit)
                ->field('id,title,cover,coin,is_vip,parent_id,category_id,duration,views,collects,likes')
                ->select()->toArray();
        }
        unset($sub);

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'subCategories' => $subCategories,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize
            ]
        ]);
    }

    /**
     * 按子分类分页查动漫
     * GET /api/anime/category/sub/animes?subCategoryId=3&page=1&pageSize=15
     */
    public function subCategoryAnimes()
    {
        $param = request()->param();
        $subCategoryId = intval($param['subCategoryId'] ?? 0);
        $page = max(1, intval($param['page'] ?? 1));
        $pageSize = intval($param['pageSize'] ?? 15);

        if ($subCategoryId <= 0) {
            return json(['code' => 1, 'msg' => 'subCategoryId 必填', 'data' => []]);
        }

        $query = Db::name('anime_videos')
            ->where('category_id', $subCategoryId)
            ->where('status', 1);

        $total = $query->count();

        $list = $query
            ->order('id', 'desc')
            ->page($page, $pageSize)
            ->field('id,title,cover,coin,is_vip,parent_id,category_id,duration,views,collects,likes')
            ->select()
            ->toArray();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list'     => $list,
                'total'    => $total,
                'page'     => $page,
                'pageSize' => $pageSize,
            ]
        ]);
    }
    /**
 * 获取所有推荐分组及其下动漫（分页，每组前 N 条）
 * GET /api/anime/recommend/all?page=1&pageSize=5&limit=9
 */
public function allRecommendGroups()
{
    $param = request()->param();
    $page = max(1, intval($param['page'] ?? 1));
    $pageSize = max(1, intval($param['pageSize'] ?? 2));
    $limit = intval($param['limit'] ?? 9); // 每组下动漫数量

    // 取总数
    $total = Db::name('anime_recommend_group')->where('status', 1)->count();

    $groups = Db::name('anime_recommend_group')
        ->where('status', 1)
        ->order('sort', 'asc')
        ->page($page, $pageSize)
        ->select()
        ->toArray();

    if (empty($groups)) {
        return json(['code' => 0, 'msg' => 'success', 'data' => ['groups' => [], 'total' => 0]]);
    }

    $groupIds = array_column($groups, 'id');
    // 查所有分组下动漫
    $groupAnimes = Db::name('anime_recommend_video')
        ->alias('arv')
        ->leftJoin('anime_videos av', 'arv.video_id = av.id')
        ->whereIn('arv.group_id', $groupIds)
        ->field('arv.group_id, av.id, av.title as name, av.cover, av.views, av.coin, av.is_vip, av.duration, av.category_id, arv.sort')
        ->order('arv.sort', 'asc')
        ->select()
        ->toArray();

    // 补全封面域名
    $host = request()->domain();
    foreach ($groupAnimes as &$anime) {
        if (!empty($anime['cover']) && stripos($anime['cover'], 'http') !== 0) {
            $anime['cover'] = rtrim($host, '/') . '/' . ltrim($anime['cover'], '/');
        }
    }
    unset($anime);

    // 分组组装
    $groupAnimeMap = [];
    foreach ($groupAnimes as $anime) {
        $groupAnimeMap[$anime['group_id']][] = $anime;
    }

    $result = [];
foreach ($groups as $group) {
    $animesList = isset($groupAnimeMap[$group['id']]) ? $groupAnimeMap[$group['id']] : [];
    $result[] = [
        'id'         => $group['id'],
        'name'       => $group['name'],
        'sort'       => $group['sort'],
        'status'     => $group['status'],
        'remark'     => $group['remark'] ?? '',
        'created_at' => $group['create_time'],
        'updated_at' => $group['update_time'],
        'layout_type'=> $group['layout_type'] ?? 'type1', // 新增
        'icon'       => $group['icon'] ?? '',             // 新增
        // 最多 limit 个动漫
        'animes'     => array_slice($animesList, 0, $limit),
    ];
}
    return json([
        'code' => 0,
        'msg' => 'success',
        'data' => [
            'groups' => $result,
            'total' => $total
        ]
    ]);
}
/**
 * 查询某个推荐分组下的所有动漫（分页）
 * GET /api/anime/recommend/group-animes?groupId=1&page=1&pageSize=15
 */
public function groupAnimes()
{
    $param = request()->param();
    $groupId = intval($param['groupId'] ?? 0);
    $page = max(1, intval($param['page'] ?? 1));
    $pageSize = max(1, intval($param['pageSize'] ?? 15));
    if ($groupId <= 0) {
        return json(['code' => 1, 'msg' => 'groupId 必填', 'data' => []]);
    }
    $query = Db::name('anime_recommend_video')
        ->alias('arv')
        ->leftJoin('anime_videos av', 'arv.video_id = av.id')
        ->where('arv.group_id', $groupId)
        ->field('av.id, av.title as name, av.cover, av.views, av.coin, av.is_vip, av.duration, av.category_id, arv.sort')
        ->order('arv.sort', 'asc');

    $total = $query->count();
    $list = $query->page($page, $pageSize)->select()->toArray();

    // 补全封面域名
    $host = request()->domain();
    foreach ($list as &$anime) {
        if (!empty($anime['cover']) && stripos($anime['cover'], 'http') !== 0) {
            $anime['cover'] = rtrim($host, '/') . '/' . ltrim($anime['cover'], '/');
        }
    }
    unset($anime);

    return json([
        'code' => 0,
        'msg' => 'success',
        'data' => [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
        ]
    ]);
}
/**
 * 获取动漫标签（H5前台）
 * GET /api/anime/category/tags?keyword=xxx&group=xxx&status=1
 */
public function tags()
{
    $param = request()->param();
    $where = [];

    if (!empty($param['keyword'])) {
        $where[] = ['name', 'like', '%' . $param['keyword'] . '%'];
        // 如果还需要 alias 字段 or 查询，按需拼接
        // $where[] = ['alias', 'like', '%' . $param['keyword'] . '%'];
    }

    if (!empty($param['group'])) {
        $where[] = ['group', '=', $param['group']];
    }

    if (isset($param['status']) && $param['status'] !== '' && $param['status'] !== null) {
        $where[] = ['status', '=', (int)$param['status']];
    } else {
        // 默认只查启用标签（前台可以省略status=1）
        $where[] = ['status', '=', 1];
    }

    $list = Db::name('anime_tags')
    ->where($where)
    ->order('sort', 'asc')
    ->order('id', 'desc')
    ->select()
    ->toArray();

return json([
    'code' => 0,
    'msg'  => 'success',
    'data' => [
        'list' => $list,
        'total' => count($list)
    ]
]);
}
/**
 * 动漫视频列表（多条件筛选 + 分页 + 多种排序方式）
 * GET /api/h5/anime/videos/list
 * 支持参数：
 *   - keyword         关键字（支持id或标题模糊查）
 *   - parentId        主分类ID
 *   - categoryId      子分类ID
 *   - is_vip          是否VIP（0/1）
 *   - coin            金币筛选（传0查免费，传1查有金币）
 *   - status          状态
 *   - sort            排序方式（默认id倒序，views-播放量，likes-点赞，collects-收藏，newest-最新）
 *   - page/pageSize/limit
 */
public function animeVideoList()
{
    $param = request()->param();
    // 兼容各种分页参数名
    $page = max(1, intval($param['page'] ?? $param['currentPage'] ?? 1));
    $pageSize = max(1, intval($param['pageSize'] ?? $param['pagesize'] ?? $param['limit'] ?? 10));

    $query = Db::name('anime_videos');
    // 多条件筛选
    if (!empty($param['keyword'])) {
        $keyword = $param['keyword'];
        $query->where(function ($q) use ($keyword) {
            $q->whereLike('id', "%$keyword%")
              ->whereOr('title', 'like', "%$keyword%");
        });
    }
    if (!empty($param['parentId'])) {
        $query->where('parent_id', intval($param['parentId']));
    }
    if (!empty($param['categoryId'])) {
        $query->where('category_id', intval($param['categoryId']));
    }
    if (!empty($param['tagId'])) {
    $tagId = (string)$param['tagId'];
    $query->whereRaw('FIND_IN_SET(?, tags)', [$tagId]);
}
    if (isset($param['is_vip']) && $param['is_vip'] !== '') {
        $query->where('is_vip', intval($param['is_vip']));
    }
    if (isset($param['coin']) && $param['coin'] !== '') {
        // coin=0 查免费，coin=1 查有金币
        $coin = intval($param['coin']);
        $coin === 0
            ? $query->where('coin', 0)
            : $query->where('coin', '>', 0);
    }
    if (isset($param['status']) && $param['status'] !== '') {
        $query->where('status', intval($param['status']));
    }

    // 排序支持
    $sort = $param['sort'] ?? 'default';
    switch ($sort) {
        case 'views':
            $orderBy = ['views' => 'desc', 'id' => 'desc'];
            break;
        case 'likes':
            $orderBy = ['likes' => 'desc', 'id' => 'desc'];
            break;
        case 'collects':
            $orderBy = ['collects' => 'desc', 'id' => 'desc'];
            break;
        case 'newest':
            $orderBy = ['create_time' => 'desc', 'id' => 'desc'];
            break;
        default:
            $orderBy = ['id' => 'desc'];
    }

    // 总数
    $total = $query->count();

    // 列表
    $list = $query
        ->order($orderBy)
        ->page($page, $pageSize)
        ->field('id,title,cover,coin,is_vip,parent_id,category_id,duration,views,collects,likes,status,create_time')
        ->select()
        ->toArray();

    // 封面补全
    $domain = rtrim(request()->domain(), '/');
    foreach ($list as &$item) {
        if (!empty($item['cover']) && stripos($item['cover'], 'http') !== 0) {
            $item['cover'] = $domain . '/' . ltrim($item['cover'], '/');
        }
    }
    unset($item);

    return json([
        'code' => 0,
        'msg'  => 'success',
        'data' => [
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
        ]
    ]);
}

}
