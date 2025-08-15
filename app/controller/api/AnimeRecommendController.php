<?php
namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\facade\Request;

class AnimeRecommendController extends BaseController
{
    // 获取推荐分组列表
    public function list()
    {
        $params = Request::param();
        $keyword = $params['keyword'] ?? '';
        
        $query = Db::name('anime_recommend_group');
        
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
        'layout_type' => $params['layout_type'] ?? 'type1', // 新增
        'icon' => $params['icon'] ?? '', // 新增
        'create_time' => date('Y-m-d H:i:s'),
        'update_time' => date('Y-m-d H:i:s')
    ];

    $id = Db::name('anime_recommend_group')->insertGetId($data);

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
        'layout_type' => $params['layout_type'] ?? 'type1', // 新增
        'icon' => $params['icon'] ?? '', // 新增
        'update_time' => date('Y-m-d H:i:s')
    ];

    $result = Db::name('anime_recommend_group')
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
            Db::name('anime_recommend_group')->where('id', $id)->delete();
            // 删除关联的动漫
            Db::name('anime_recommend_video')->where('group_id', $id)->delete();
            
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
                Db::name('anime_recommend_group')
                    ->where('id', $item['id'])
                    ->update(['sort' => $item['sort'], 'update_time' => time()]);
            }
            return json(['code' => 200, 'msg' => '排序保存成功']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => '排序保存失败']);
        }
    }

    // 获取分组下的动漫列表
    public function groupAnimes()
    {
        $groupId = Request::param('id'); // 改为 id
        $animes = Db::name('anime_recommend_video')
            ->alias('arv')
            ->join('anime_videos av', 'av.id = arv.video_id')
            ->where('arv.group_id', $groupId)
            ->field('arv.id as recommend_id, arv.video_id, av.title, arv.sort, av.cover as cover_url')
            ->order('arv.sort', 'asc')
            ->select()
            ->toArray();

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => $animes
        ]);
    }

    // 保存分组下的动漫
    public function saveGroupAnimes()
    {
        $groupId = Request::param('id');
        $animes = Request::param('animes/a', []);

        Db::startTrans();
        try {
            Db::name('anime_recommend_video')->where('group_id', $groupId)->delete();

            $insertData = [];
            foreach ($animes as $anime) {
                if (empty($anime['video_id'])) continue;
                $insertData[] = [
                    'group_id' => $groupId,
                    'video_id' => $anime['video_id'],
                    'sort' => $anime['sort'] ?? 1,
                    'create_time' => time(),
                    'update_time' => time()
                ];
            }

            if (!empty($insertData)) {
                Db::name('anime_recommend_video')->insertAll($insertData);
            }

            Db::commit();
            return json(['code' => 200, 'msg' => '保存成功']);
        } catch (\Exception $e) {
            Db::rollback();
            // 记录日志
            file_put_contents(runtime_path() . 'anime_recommend_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            return json(['code' => 500, 'msg' => '保存失败']);
        }
    }

    // 获取所有可选的动漫列表
    public function allAnimes()
    {
        try {
            $params = Request::get([
                'keyword'      => '',
                'parentId/d'   => 0,
                'categoryId/d' => 0,
                'currentPage/d'=> 1,
                'pageSize/d'   => 10
            ]);

            $query = Db::name('anime_videos')
                ->alias('av')
                ->field('av.id, av.title, av.category_id, ac.name AS child_category_name, ap.name AS main_category_name')
                ->leftJoin('anime_categories ac', 'av.category_id = ac.id')
                ->leftJoin('anime_categories ap', 'ac.parent_id = ap.id');

            if (!empty($params['keyword'])) {
                $query->where('av.title', 'like', '%' . $params['keyword'] . '%');
            }
            if ($params['parentId'] > 0) {
                $query->where('ap.id', $params['parentId']);
            }
            if ($params['categoryId'] > 0) {
                $query->where('ac.id', $params['categoryId']);
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
                'msg'  => '获取动漫列表失败: ' . $e->getMessage(),
                'data' => [
                    'list' => [],
                    'total' => 0
                ]
            ]);
        }
    }

    // 获取所有父分类
    public function parents()
    {
        $list = Db::name('anime_categories')->where('parent_id', 0)->select()->toArray();
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
        $list = Db::name('anime_categories')->where('parent_id', '<>', 0)->select()->toArray();
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => count($list)
            ]
        ]);
    }
}