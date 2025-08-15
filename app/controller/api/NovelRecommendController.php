<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db;
use think\Validate;

class NovelRecommendController extends BaseController
{
    protected $recommendGroupTable = 'text_novel_recommend_group';         // 推荐分组表
    protected $groupNovelsTable    = 'text_novel_recommend_group_novel';  // 分组-小说关联表
    protected $novelTable          = 'text_novel';                        // 小说表
    protected $categoryTable       = 'text_novel_category'; // 小说分类表

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

        // 联表统计每个分组下的小说数量
        $query->leftJoin($this->groupNovelsTable . ' gn', 'gn.group_id = rg.id')
              ->field([
                  'rg.*',
                  Db::raw('COUNT(gn.novel_id) AS novel_count')
              ])
              ->group('rg.id');

        // 单独查询总数
        $totalQuery = clone $query;
        $total = $totalQuery->count('rg.id');

        $list = $query->order('rg.sort', 'asc')
                      ->page($page, $pageSize)
                      ->select()
                      ->toArray();

        return $this->success(['list' => $list, 'total' => $total]);
    }

    // 新增推荐分组
    public function addGroup()
    {
        $data = Request::post(['name', 'sort', 'status', 'type', 'layout_type', 'icon']);

        $validate = new Validate([
            'name|分组名' => 'require|max:50|unique:' . $this->recommendGroupTable,
            'sort|排序值' => 'require|integer|min:1',
            'status|状态' => 'require|in:0,1',
            'type|类型' => 'require|in:text,audio'
        ]);

        if (!$validate->check($data)) {
            return $this->error($validate->getError());
        }

        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');

        $result = Db::table($this->recommendGroupTable)->insert($data);

        if ($result) {
            return $this->success([], '新增分组成功');
        } else {
            return $this->error('新增分组失败');
        }
    }

    // 更新推荐分组
    public function updateGroup($id)
    {
        $data = Request::put(['name', 'sort', 'status', 'type', 'layout_type', 'icon']);

        if (empty($data)) {
            return $this->error('没有可更新的数据');
        }

        $validate = new Validate([
            'name|分组名' => 'max:50|unique:' . $this->recommendGroupTable . ',name,' . $id,
            'sort|排序值' => 'integer|min:1',
            'status|状态' => 'in:0,1',
            'type|类型' => 'in:text,audio'
        ]);

        if (!$validate->check($data)) {
            return $this->error($validate->getError());
        }

        $data['update_time'] = date('Y-m-d H:i:s');

        $result = Db::table($this->recommendGroupTable)->where('id', $id)->update($data);

        if ($result !== false) {
            return $this->success([], '更新分组成功');
        } else {
            return $this->error('更新分组失败');
        }
    }

    // 删除推荐分组
    public function deleteGroup($id)
    {
        Db::startTrans();
        try {
            // 删除分组下的所有小说关联
            Db::table($this->groupNovelsTable)->where('group_id', $id)->delete();
            // 删除分组
            $result = Db::table($this->recommendGroupTable)->where('id', $id)->delete();

            if ($result) {
                Db::commit();
                return $this->success([], '删除分组成功');
            } else {
                Db::rollback();
                return $this->error('删除分组失败');
            }
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('删除分组异常: ' . $e->getMessage());
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
                    ->update(['sort' => $item['sort'], 'update_time' => date('Y-m-d H:i:s')]);
            }
            Db::commit();
            return $this->success([], '保存排序成功');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存排序失败: ' . $e->getMessage());
        }
    }

    // 获取分组下的小说
    public function getGroupNovels($groupId)
    {
        $groupId = intval($groupId);
        
        $list = Db::table($this->groupNovelsTable)
            ->alias('gn')
            ->leftJoin($this->novelTable . ' n', 'gn.novel_id = n.id')
            ->where('gn.group_id', $groupId)
            ->field([
                'gn.novel_id',
                'n.title',
                'n.cover_url',
                'gn.sort',
                'n.category_id',
                'n.author',
                'n.tags'
            ])
            ->order('gn.sort', 'asc')
            ->select()
            ->toArray();

        return $this->success(['list' => $list]);
    }

    // 保存分组下的小说
    public function saveGroupNovels($groupId)
    {
        $groupId = intval($groupId);
        $novels = Request::post('novels/a');
        if (!is_array($novels)) {
            return $this->error('小说数据格式不正确');
        }
        Db::startTrans();
        try {
            $novelIds = array_column($novels, 'novel_id');
            if (!empty($novelIds)) {
                // 移除这些小说在其他分组的关联
                Db::table($this->groupNovelsTable)->whereIn('novel_id', $novelIds)->delete();
            }
            // 清空本分组
            Db::table($this->groupNovelsTable)->where('group_id', $groupId)->delete();
            $insertData = [];
            foreach ($novels as $novel) {
                if (!isset($novel['novel_id']) || !isset($novel['sort'])) {
                    Db::rollback();
                    return $this->error('小说数据项缺少ID或排序值');
                }
                $insertData[] = [
                    'group_id' => $groupId,
                    'novel_id' => $novel['novel_id'],
                    'sort' => $novel['sort'],
                    'create_time' => date('Y-m-d H:i:s'),
                ];
            }
            if (!empty($insertData)) {
                Db::table($this->groupNovelsTable)->insertAll($insertData);
            }
            Db::commit();
            return $this->success([], '保存推荐小说成功');
        } catch (\Exception $e) {
            Db::rollback();
            return $this->error('保存推荐小说失败: ' . $e->getMessage());
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

    // 获取所有分类
    public function getAllCategories()
    {
        $list = Db::table($this->categoryTable)
            ->order('sort', 'asc')
            ->select()
            ->toArray();
        return $this->success(['list' => $list]);
    }

    // 获取所有小说列表 (带分页和筛选)
    public function getAllNovels()
    {
        $excludeNovelIds = Request::get('excludeNovelIds/a', []);
        $keyword = Request::get('keyword', '');
        $page = intval(Request::get('currentPage', 1));
        $pageSize = intval(Request::get('pageSize', 10));

        $alias = 'n';
        $query = Db::table($this->novelTable)->alias($alias);

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword, $alias) {
                $q->where($alias . '.id', $keyword)
                  ->whereOr($alias . '.title', 'like', '%' . $keyword . '%');
            });
        }

        // 获取所有已被分组选中的小说ID
        $groupedNovelIds = Db::table($this->groupNovelsTable)->column('novel_id');
        // 合并 excludeNovelIds 和所有已分组的
        $excludeIds = array_unique(array_merge($excludeNovelIds, $groupedNovelIds));
        if (!empty($excludeIds)) {
            $query->whereNotIn($alias . '.id', $excludeIds);
        }

        $query->leftJoin($this->categoryTable . ' c', 'c.id = ' . $alias . '.category_id')
              ->field([
                  $alias . '.*',
                  'c.name AS category_name',
              ]);

        $total = $query->count();
        $list = $query->order($alias . '.id', 'desc')
                      ->page($page, $pageSize)
                      ->select()
                      ->toArray();

        return $this->success(['list' => $list, 'total' => $total]);
    }
    // 获取所有分组及分组下的小说列表（结构如漫画 allWithComics）
// 获取所有分组及分组下的小说列表（分页）
public function allWithNovels()
{
    // ★ 新增分页参数
    $page = max(1, intval(Request::get('page', 1)));
    $pageSize = max(1, intval(Request::get('pageSize', 2)));

    // ★ 查总分组数
    $total = Db::table($this->recommendGroupTable)
        ->where('status', 1)
        ->count();

    // ★ 分页查分组
    $groups = Db::table($this->recommendGroupTable)
        ->where('status', 1)
        ->order('sort', 'asc')
        ->page($page, $pageSize)
        ->select()
        ->toArray();

    if (empty($groups)) {
        return $this->success(['groups' => [], 'total' => $total]);
    }

    $groupIds = array_column($groups, 'id');

    // 查出所有分组下的小说
    $groupNovels = Db::table($this->groupNovelsTable)
        ->alias('gn')
        ->leftJoin($this->novelTable . ' n', 'gn.novel_id = n.id')
        ->whereIn('gn.group_id', $groupIds)
        ->field([
            'gn.group_id',
            'n.id',
            'n.title as name',
            'n.cover_url as cover',
            'n.coin',
            'n.is_vip',
            'n.serialization_status',
            'n.chapter_count',
            'n.description',
            'n.views'
        ])
        ->order('gn.sort', 'asc')
        ->select()
        ->toArray();

    // 统一补全域名
    $host = Request::domain();
foreach ($groupNovels as &$novel) {
    if (!empty($novel['cover']) && stripos($novel['cover'], 'http') !== 0) {
        $novel['cover'] = rtrim($host, '/') . '/' . ltrim($novel['cover'], '/');
    }
}
unset($novel);

    // 分组组装
    $groupNovelMap = [];
    foreach ($groupNovels as $novel) {
        $groupNovelMap[$novel['group_id']][] = $novel;
    }

    $result = [];
    foreach ($groups as $group) {
        $novelsList = isset($groupNovelMap[$group['id']]) ? $groupNovelMap[$group['id']] : [];
        $result[] = [
            'id' => $group['id'],
            'name' => $group['name'],
            'sort' => $group['sort'],
            'status' => $group['status'],
            'remark' => $group['remark'] ?? '',
            'created_at' => $group['create_time'],
            'updated_at' => $group['update_time'],
            'is_protected' => $group['is_protected'] ?? 0,
            'layout_type' => $group['layout_type'] ?? 'type1',
            'icon' => $group['icon'] ?? '',
            // novels字段：只取前9条
            'novels' => array_slice($novelsList, 0, 9),
        ];
    }

    // ★ 返回结构增加 total
    return $this->success([
        'groups' => $result,
        'total' => $total
    ]);
}

// 获取推荐分组下的所有小说（分页版）
public function getGroupNovelsPaginated($groupId)
{
    // 获取页码和每页条数
    $page = intval(Request::get('page', 1));        // 当前页码
    $pageSize = intval(Request::get('pageSize', 15)); // 每页条数

    $groupId = intval($groupId);

    // 查询分组下的所有小说，分页加载
    $novels = Db::table($this->groupNovelsTable)
        ->alias('gn')
        ->leftJoin($this->novelTable . ' n', 'gn.novel_id = n.id')
        ->where('gn.group_id', $groupId)
       ->field([
    'n.id as id',   // 直接映射成 id
    'n.title as name',  
    'n.cover_url as cover',
    'n.coin',
    'n.is_vip',
    'n.serialization_status as is_serializing',
    'n.chapter_count'
])
        ->order('gn.sort', 'asc')
        ->page($page, $pageSize)  // 实现分页
        ->select()
        ->toArray();

    // 获取域名
    $host = Request::domain();
foreach ($novels as &$novel) {
    if (!empty($novel['cover']) && stripos($novel['cover'], 'http') !== 0) {
        $novel['cover'] = rtrim($host, '/') . '/' . ltrim($novel['cover'], '/');
    }
}
unset($novel);

    // 获取总数
    $total = Db::table($this->groupNovelsTable)
        ->where('group_id', $groupId)
        ->count();

    // 返回数据
    return $this->success(['list' => $novels, 'total' => $total]);
}

}