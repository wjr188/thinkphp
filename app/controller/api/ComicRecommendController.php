<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db;
use think\Validate;
use think\facade\Log;

class ComicRecommendController extends BaseController
{
    protected $recommendGroupTable = 'comic_recommend_group';         // 推荐分组表
    protected $groupComicsTable    = 'comic_recommend_group_comic';  // 分组-漫画关联表
    protected $comicTable          = 'comic_manga';                  // 漫画表
    protected $categoryTable       = 'comic_categories';             // 分类表

    // ---- 操作日志 ----
    protected function opLog($action, $data = [])
    {
        $adminId = Request::middleware('admin_id') ?? 0;
        $adminName = Request::middleware('admin_name') ?? '';
        Db::table('recommend_op_log')->insert([
            'admin_id'   => $adminId,
            'admin_name' => $adminName,
            'action'     => $action,
            'data'       => json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // 获取推荐分组列表
    public function getGroups()
    {
        $keyword = Request::get('keyword', '');
        $page = Request::get('page', 1);
        $pageSize = Request::get('pageSize', 10);

        $query = Db::table($this->recommendGroupTable)->alias('rg');
        if (!empty($keyword)) {
            $query->where('rg.name', 'like', '%' . $keyword . '%');
        }
        $query->leftJoin($this->groupComicsTable . ' gc', 'gc.group_id = rg.id')
              ->field([
                  'rg.*',
                  Db::raw('COUNT(gc.comic_id) AS comic_count')
              ])
              ->group('rg.id');
        $total = Db::table($this->recommendGroupTable)
            ->alias('rg')
            ->when(!empty($keyword), function($q) use ($keyword) {
                $q->where('rg.name', 'like', '%' . $keyword . '%');
            })
            ->count();
        $list = $query->order('rg.sort', 'asc')
                      ->page($page, $pageSize)
                      ->select()
                      ->toArray();
        return $this->success(['list' => $list, 'total' => $total]);
    }

    // 新增推荐分组
    public function addGroup()
    {
        $data = Request::post(['name', 'sort', 'layout_type', 'icon']);

        $validate = new Validate([
            'name|分组名' => 'require|max:50|unique:' . $this->recommendGroupTable,
            'sort|排序值' => 'require|integer|min:1',
        ]);
        if (!$validate->check($data)) {
            return $this->error($validate->getError());
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['is_protected'] = 0; // 新增分组默认非保护

        $result = Db::table($this->recommendGroupTable)->insert($data);
        if ($result) {
            $this->opLog('add_group', $data);
            return $this->success([], '新增分组成功');
        } else {
            return $this->error('新增分组失败');
        }
    }

    // 更新推荐分组
    public function updateGroup($id)
    {
       $data = Request::put(['name', 'sort', 'is_protected', 'layout_type', 'icon']);

        if (empty($data)) {
            return $this->error('没有可更新的数据');
        }
        $validate = new Validate([
    'name|分组名' => 'require|max:50|unique:' . $this->recommendGroupTable,
    'sort|排序值' => 'require|integer|min:1',
    'layout_type|布局类型' => 'max:16',
    'icon|分组图标' => 'max:128',
]);

        if (!$validate->check($data)) {
            return $this->error($validate->getError());
        }
        $data['updated_at'] = date('Y-m-d H:i:s');

        $result = Db::table($this->recommendGroupTable)->where('id', $id)->update($data);
        if ($result !== false) {
            $this->opLog('update_group', array_merge(['id'=>$id], $data));
            return $this->success([], '更新分组成功');
        } else {
            return $this->error('更新分组失败');
        }
    }

    // 删除推荐分组（带保护字段校验）
    public function deleteGroup($id)
    {
        $group = Db::table($this->recommendGroupTable)->where('id', $id)->find();
        if ($group && isset($group['is_protected']) && $group['is_protected']) {
            return $this->error('该分组为保护分组，不能删除');
        }
        Db::startTrans();
        try {
            Db::table($this->groupComicsTable)->where('group_id', $id)->delete();
            $result = Db::table($this->recommendGroupTable)->where('id', $id)->delete();
            if ($result) {
                Db::commit();
                $this->opLog('delete_group', ['id'=>$id]);
                return $this->success([], '删除分组成功');
            } else {
                Db::rollback();
                return $this->error('删除分组失败');
            }
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('删除分组异常: '.$e->getMessage());
            return $this->error('删除分组异常');
        }
    }

    // 保存分组排序
    public function sortGroups()
    {
        $data = Request::post('data/a');
        if (empty($data) || !is_array($data)) {
            return $this->error('排序数据格式不正确');
        }
        Db::startTrans();
        try {
            foreach ($data as $item) {
                if (!isset($item['id']) || !isset($item['sort'])) {
                    Db::rollback();
                    return $this->error('排序数据项缺少ID或排序值');
                }
                Db::table($this->recommendGroupTable)
                    ->where('id', $item['id'])
                    ->update(['sort' => $item['sort'], 'updated_at' => date('Y-m-d H:i:s')]);
            }
            Db::commit();
            $this->opLog('sort_groups', $data);
            return $this->success([], '保存排序成功');
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('保存排序失败: '.$e->getMessage());
            return $this->error('保存排序失败');
        }
    }

    // 获取所有未分组漫画（左侧可选池）
    public function getUnGroupedComics()
    {
        $groupedComicIds = Db::table($this->groupComicsTable)->column('comic_id');
        $query = Db::table($this->comicTable);
        if (!empty($groupedComicIds)) {
            $query->whereNotIn('id', $groupedComicIds);
        }
        $list = $query->order('id', 'desc')->select()->toArray();
        return $this->success(['list' => $list]);
    }

    // 获取分组下的漫画（不用中间表，直接查主表）
    public function getGroupComics($groupId)
{
    $groupId = intval($groupId);
    $page = intval(Request::get('page', 1));
    $pageSize = intval(Request::get('pageSize', 15));

    // 不要直接 count() + page() 复用同一个query，否则结果不对
    $countQuery = Db::table($this->groupComicsTable)->where('group_id', $groupId);
    $total = $countQuery->count();

    $query = Db::table($this->groupComicsTable)
        ->alias('gc')
        ->leftJoin($this->comicTable . ' c', 'gc.comic_id = c.id')
        ->where('gc.group_id', $groupId)
        ->field([
            'gc.comic_id',
            'c.name',
            'c.cover',
            'gc.sort',
            'c.category_id',
            'c.sub_category_id',
            'c.is_vip',
            'c.coin',
            'c.is_serializing',
            'c.is_shelf',
            'c.status',
            'c.created_at',
            'c.updated_at',
             'c.chapter_count'
        ])
        ->order('gc.sort', 'asc')
        ->page($page, $pageSize);

    $list = $query->select()->toArray();

// 统一补全图片地址
$domain = request()->domain();
foreach ($list as &$item) {
    if ($item['cover'] && !preg_match('/^https?:\/\//', $item['cover'])) {
        $item['cover'] = $domain . $item['cover'];
    }
}
unset($item);

return $this->success([
    'list' => $list,
    'total' => $total,
    'page' => $page,
    'pageSize' => $pageSize
]);

}

    // 保存分组下的漫画（保证每个漫画只属于一个分组，分批写入，写日志）
    public function saveGroupComics($groupId)
    {
        $groupId = intval($groupId);
        $comics = Request::post('comics/a');
        if (!is_array($comics)) {
            return $this->error('漫画数据格式不正确');
        }
        Db::startTrans();
        try {
            $comicIds = array_column($comics, 'comic_id');
            if (!empty($comicIds)) {
                Db::table($this->groupComicsTable)->whereIn('comic_id', $comicIds)->delete();
            }
            Db::table($this->groupComicsTable)->where('group_id', $groupId)->delete();
            $insertData = [];
            foreach ($comics as $comic) {
                if (!isset($comic['comic_id']) || !isset($comic['sort'])) {
                    Db::rollback();
                    return $this->error('漫画数据项缺少ID或排序值');
                }
                $insertData[] = [
                    'group_id' => $groupId,
                    'comic_id' => $comic['comic_id'],
                    'sort' => $comic['sort'],
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
            // --- 分批写入防止超长 ---
            if (!empty($insertData)) {
                foreach (array_chunk($insertData, 500) as $piece) {
                    Db::table($this->groupComicsTable)->insertAll($piece);
                }
            }
            Db::commit();
            $this->opLog('save_group_comics', ['group_id' => $groupId, 'comics' => $comicIds]);
            return $this->success([], '保存推荐漫画成功');
        } catch (\Exception $e) {
            Db::rollback();
            Log::error('保存推荐漫画失败: '.$e->getMessage());
            return $this->error('保存推荐漫画失败');
        }
    }

    // 获取所有主分类
    public function getParentCategories()
    {
        $list = Db::table($this->categoryTable)
            ->where('parent_id', 0)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
        return $this->success(['list' => $list]);
    }

    // 获取所有子分类
    public function getChildCategories()
    {
        $list = Db::table($this->categoryTable)
            ->where('parent_id', '>', 0)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
        return $this->success(['list' => $list]);
    }

    // 获取所有漫画列表 (带分页和筛选)
    public function getAllComics()
    {
        $excludeComicIds = Request::get('excludeComicIds/a', []);
        $keyword = Request::get('keyword', '');
        $parentId = Request::get('parentId');
        $categoryId = Request::get('categoryId');
        $page = intval(Request::get('currentPage', 1));
        $pageSize = intval(Request::get('pageSize', 10));
        $alias = 'c';
        $query = Db::table($this->comicTable)->alias($alias);

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword, $alias) {
                $q->where($alias . '.id', $keyword)
                  ->whereOr($alias . '.name', 'like', '%' . $keyword . '%');
            });
        }
        $groupedComicIds = Db::table($this->groupComicsTable)->column('comic_id');
        $excludeIds = array_unique(array_merge($excludeComicIds, $groupedComicIds));
        if (!empty($excludeIds)) {
            $query->whereNotIn($alias . '.id', $excludeIds);
        }
        if ($parentId !== null && $parentId !== '' && intval($parentId) > 0) {
            $query->where($alias . '.category_id', intval($parentId));
        }
        if ($categoryId !== null && $categoryId !== '' && intval($categoryId) > 0) {
            $query->where($alias . '.sub_category_id', intval($categoryId));
        }
        $query->leftJoin($this->categoryTable . ' mc', 'mc.id = ' . $alias . '.category_id')
              ->leftJoin($this->categoryTable . ' cc', 'cc.id = ' . $alias . '.sub_category_id')
              ->field([
                  $alias . '.*',
                  'mc.name AS main_category_name',
                  'cc.name AS child_category_name',
              ]);
        $total = $query->count();
        $list = $query->order($alias . '.id', 'desc')
                      ->page($page, $pageSize)
                      ->select()
                      ->toArray();
        return $this->success(['list' => $list, 'total' => $total]);
    }
    // 推荐分组及分组下所有漫画（批量）
public function allGroupsWithComics()
{
    // 新增分页参数
    $page = intval(Request::get('page', 1));
    $pageSize = intval(Request::get('pageSize', 2)); // 默认2组（你前端 batchSize 用几这里就默认几）

    // 查总数，给前端做 noMore
    $total = Db::table($this->recommendGroupTable)->count();

    // 按分页取分组
    $groups = Db::table($this->recommendGroupTable)
        ->order('sort', 'asc')
        ->page($page, $pageSize)
        ->select()
        ->toArray();

    if (!$groups) {
        return $this->success(['groups' => [], 'total' => $total]);
    }

    // 只查本次分页分组的关联
    $groupIds = array_column($groups, 'id');
    $groupComicRows = Db::table($this->groupComicsTable)
        ->whereIn('group_id', $groupIds)
        ->order('sort', 'asc')
        ->select()
        ->toArray();

    // 查所有关联的漫画
    $comicIds = array_column($groupComicRows, 'comic_id');
    if ($comicIds) {
        $comicMap = Db::table($this->comicTable)
            ->whereIn('id', $comicIds)
            ->field('id,name,cover,coin,is_vip,is_serializing,chapter_count')
            ->select()
            ->toArray();
        $comicMap = array_column($comicMap, null, 'id');
    } else {
        $comicMap = [];
    }

    // 组装每个分组下的漫画
    $groupComicList = [];
    foreach ($groupComicRows as $row) {
        $gid = $row['group_id'];
        $cid = $row['comic_id'];
        $comic = isset($comicMap[$cid]) ? $comicMap[$cid] : null;
        if ($comic) {
            $groupComicList[$gid][] = $comic;
        }
    }

    // 补全图片地址，限制每组9条
    $domain = request()->domain();
    foreach ($groups as &$group) {
        $allComics = $groupComicList[$group['id']] ?? [];
        foreach ($allComics as &$comic) {
            if ($comic['cover'] && !preg_match('/^https?:\/\//', $comic['cover'])) {
                $comic['cover'] = $domain . (strpos($comic['cover'], '/') === 0 ? '' : '/') . $comic['cover'];
            }
        }
        unset($comic);
        $group['comics'] = array_slice($allComics, 0, 9);
    }
    unset($group);

    return $this->success([
        'groups' => $groups,
        'total' => $total,   // ★★★ 一定要带，前端才能做 nomore
    ]);
}

}