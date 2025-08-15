<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db;
use think\Validate;

class ComicCategoryController extends BaseController
{
    /**
     * 获取漫画分类列表
     * 路由: GET api/comic/category/list
     * @return \think\response\Json
     */
    public function list()
    {
        $param = Request::param();

        $keyword = $param['keyword'] ?? '';
        $parentId = (int)($param['parentId'] ?? 0);
        $status = (isset($param['status']) && in_array($param['status'], [0, 1])) ? (int)$param['status'] : null;
        $onlyMain = (int)($param['onlyMain'] ?? 0);

        $where = [];
        if ($keyword) {
            $where[] = ['name', 'like', '%' . $keyword . '%'];
        }
        
        if ($status !== null) {
            $where[] = ['status', '=', $status];
        }

        // 只返回主分类
        if ($onlyMain === 1) {
            $mainCategories = Db::name('comic_categories')
                ->where('parent_id', 0)
                ->where($where)
                ->order('sort', 'asc')
                ->select()
                ->toArray();

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'mainCategories' => $mainCategories
                ]
            ]);
        }

        // 返回子分类和漫画
        if ($parentId > 0) {
    // 1. 分页参数
    $page = max(1, intval($param['page'] ?? 1));
    $pageSize = intval($param['pageSize'] ?? 2); // 默认2，可前端调
    $limit = isset($param['limit']) && is_numeric($param['limit']) ? intval($param['limit']) : 9; // 每组子分类下漫画数量

    // 2. 查总数
    $subCategoriesQuery = Db::name('comic_categories')
        ->where('parent_id', $parentId)
        ->where('status', 1);

    $total = $subCategoriesQuery->count();

    // 3. 分页查子分类
    $subCategories = $subCategoriesQuery
        ->order('sort', 'asc')
        ->page($page, $pageSize)
        ->select()
        ->toArray();

    // 4. 查每个子分类下的漫画
    foreach ($subCategories as &$sub) {
        $sub['comics'] = Db::name('comic_manga')
            ->where('sub_category_id', $sub['id'])
            ->where('status', 1)
            ->where('is_shelf', 1)
            ->order('sort', 'desc')
            ->limit($limit)
            ->field('id,name,cover,coin,is_vip,is_serializing,chapter_count')
            ->select()
            ->toArray();

        foreach ($sub['comics'] as &$comic) {
            $this->fix_cover($comic);
        }
        unset($comic);
    }
    unset($sub);

    // 5. 返回数据
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
        // ========= 默认返回主分类+子分类（老逻辑） =========
        $mainCategoriesQuery = Db::name('comic_categories')
            ->where('parent_id', 0)
            ->where($where);

        $subCategoriesQuery = Db::name('comic_categories')
            ->where('parent_id', '>', 0)
            ->where($where);

        $mainCategories = $mainCategoriesQuery->order('sort', 'asc')->select()->toArray();
        $subCategories = $subCategoriesQuery->order('sort', 'asc')->select()->toArray();

        foreach ($mainCategories as &$item) {
            $categoryId = $item['id'];
            $comicCount = Db::name('comic_manga')
                ->where(function($query) use ($categoryId) {
                    $query->where('category_id', $categoryId)
                          ->whereOr('sub_category_id', $categoryId);
                })
                ->count();
            $item['comic_count'] = $comicCount;
        }

        foreach ($subCategories as &$item) {
            $categoryId = $item['id'];
            $comicCount = Db::name('comic_manga')
                ->where(function($query) use ($categoryId) {
                    $query->where('category_id', $categoryId)
                          ->whereOr('sub_category_id', $categoryId);
                })
                ->count();
            $item['comic_count'] = $comicCount;
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'mainCategories' => array_values($mainCategories),
                'subCategories' => array_values($subCategories)
            ]
        ]);
    }
private function fix_cover(&$item)
{
    if (!empty($item['cover']) && !preg_match('/^https?:\/\//', $item['cover'])) {
        $domain = rtrim(request()->domain(), '/');
        if ($item['cover'][0] !== '/') {
            $item['cover'] = '/' . $item['cover'];
        }
        $item['cover'] = $domain . $item['cover'];
    }
}

    /**
     * 添加漫画分类
     * 路由: POST api/comic/category/add
     * @return \think\response\Json
     */
    public function add()
    {
        $data = Request::param();

        // 验证数据
        $validate = new Validate([
            'name|分类名称' => 'require|max:50|unique:comic_categories',
            'parent_id|父分类ID' => 'require|integer',
            'sort|排序' => 'integer',
            'status|状态' => 'in:0,1',
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError(), 'data' => (object)[]]);
        }

        try {
            // 检查父分类是否存在 (如果 parent_id > 0)
            if (isset($data['parent_id']) && $data['parent_id'] > 0) {
                $parentExists = Db::name('comic_categories')->where('id', $data['parent_id'])->find();
                if (!$parentExists) {
                    return json(['code' => 1, 'msg' => '父分类不存在', 'data' => (object)[]]);
                }
            }

            $insertData = [
    'name' => $data['name'],
    'parent_id' => $data['parent_id'],
    'sort' => $data['sort'] ?? 1,
    'status' => $data['status'] ?? 1,
    'remark' => $data['remark'] ?? '',
    'layout_type' => (isset($data['layout_type']) && $data['parent_id'] > 0) ? $data['layout_type'] : null,
    'icon' => $data['icon'] ?? null, // 新增图标
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s')
];


            $result = Db::name('comic_categories')->insert($insertData);

            if ($result) {
                return json(['code' => 0, 'msg' => '批量设置状态成功', 'data' => ['code' => 0, 'msg' => '批量设置状态成功']]);
            } else {
                return json(['code' => 1, 'msg' => '新增失败', 'data' => (object)[]]);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage(), 'data' => (object)[]]);
        }
    }

    /**
     * 更新漫画分类
     * 路由: POST api/comic/category/update
     * @return \think\response\Json
     */
    public function update()
    {
        $data = Request::param();

        // 验证数据
        $validate = new Validate([
            'id|分类ID' => 'require|integer',
            'name|分类名称' => 'require|max:50|unique:comic_categories,name,' . $data['id'] . ',id',
            'parent_id|父分类ID' => 'require|integer',
            'sort|排序' => 'integer',
            'status|状态' => 'in:0,1',
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError(), 'data' => (object)[]]);
        }

        $categoryId = (int)$data['id'];

        // 检查分类是否存在
        $category = Db::name('comic_categories')->where('id', $categoryId)->find();
        if (!$category) {
            return json(['code' => 1, 'msg' => '分类未找到', 'data' => (object)[]]);
        }

        // 检查父分类是否存在
        if (isset($data['parent_id']) && $data['parent_id'] > 0 && $data['parent_id'] !== $category['parent_id']) {
            $parentExists = Db::name('comic_categories')->where('id', $data['parent_id'])->find();
            if (!$parentExists) {
                return json(['code' => 1, 'msg' => '新的父分类不存在', 'data' => (object)[]]);
            }
        }

        // 防止将父分类设置为其自身的子分类
        if ($data['parent_id'] === $categoryId) {
            return json(['code' => 1, 'msg' => '不能将分类设置为其自身的父分类', 'data' => (object)[]]);
        }

        try {
            $updateData = [
    'name' => $data['name'],
    'parent_id' => $data['parent_id'],
    'sort' => $data['sort'] ?? $category['sort'],
    'status' => $data['status'] ?? $category['status'],
    'remark' => $data['remark'] ?? $category['remark'],
    'layout_type' => (isset($data['layout_type']) && $data['parent_id'] > 0) ? $data['layout_type'] : null,
    'icon' => array_key_exists('icon', $data) ? $data['icon'] : $category['icon'], // 支持 icon 编辑
    'updated_at' => date('Y-m-d H:i:s')
];


            $result = Db::name('comic_categories')->where('id', $categoryId)->update($updateData);

            if ($result !== false) {
                return json(['code' => 0, 'msg' => '批量设置状态成功', 'data' => ['code' => 0, 'msg' => '批量设置状态成功']]);
            } else {
                return json(['code' => 1, 'msg' => '更新失败或数据无变化', 'data' => (object)[]]);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage(), 'data' => (object)[]]);
        }
    }
    /**
     * 删除漫画分类
     * 路由: POST api/comic/category/delete
     * @return \think\response\Json
     */
    public function delete()
    {
        $param = Request::param(); //
        $categoryId = $param['id'] ?? null; //

        if (empty($categoryId)) {
            return json(['code' => 1, 'msg' => 'ID为必填项', 'data' => (object)[]]); //
        }

        // 检查分类是否存在
        $category = Db::name('comic_categories')->where('id', $categoryId)->find(); //
        if (!$category) {
            return json(['code' => 1, 'msg' => '分类未找到', 'data' => (object)[]]); //
        }

        // 如果是主分类，检查是否有子分类
        if ($category['parent_id'] === 0) { //
            $hasSubCategories = Db::name('comic_categories')->where('parent_id', $categoryId)->count(); //
            if ($hasSubCategories > 0) {
                return json(['code' => 1, 'msg' => '该主分类下存在子分类，请先删除子分类', 'data' => (object)[]]); //
            }
        }

        // TODO: 实际业务中，您可能还需要检查是否有漫画关联到此分类，如果有则不允许删除
        // $hasRelatedComics = Db::name('comics_to_categories')->where('category_id', $categoryId)->count();
        // if ($hasRelatedComics > 0) {
        //     return json(['code' => 1, 'msg' => '该分类下存在漫画，无法删除']);
        // }

        try {
            $result = Db::name('comic_categories')->where('id', $categoryId)->delete(); //

            if ($result) {
                return json(['code' => 0, 'msg' => '批量设置状态成功', 'data' => ['code'=>0, 'msg'=>'批量设置状态成功']]);
            } else {
                return json(['code' => 1, 'msg' => '删除失败', 'data' => (object)[]]); //
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage(), 'data' => (object)[]]); //
        }
    }

    /**
     * 批量删除漫画分类
     * 路由: POST api/comic/category/batchDelete
     * @return \think\response\Json
     */
    public function batchDelete()
    {
        $param = Request::param(); //
        $ids = $param['ids'] ?? []; //

        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => 'ID列表为必填项且必须是数组', 'data' => (object)[]]); //
        }

        // 检查是否有主分类在批量删除列表中且包含子分类
        foreach ($ids as $idToDelete) {
            $category = Db::name('comic_categories')->where('id', $idToDelete)->find(); //
            if ($category && $category['parent_id'] === 0) { // 是主分类
                $hasSubCategories = Db::name('comic_categories')->where('parent_id', $idToDelete)->count(); //
                if ($hasSubCategories > 0) {
                    return json(['code' => 1, 'msg' => '批量删除中包含的主分类下存在子分类，请先删除子分类', 'data' => (object)[]]); //
                }
            }
        }

        // TODO: 同样，这里可能也需要检查是否有漫画关联到这些分类

        try {
            $result = Db::name('comic_categories')->whereIn('id', $ids)->delete(); //

            if ($result) {
                return json(['code' => 0, 'msg' => '批量设置状态成功', 'data' => ['code'=>0, 'msg'=>'批量设置状态成功']]);
            } else {
                return json(['code' => 1, 'msg' => '批量删除失败或没有找到要删除的分类', 'data' => (object)[]]); //
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage(), 'data' => (object)[]]); //
        }
    }

    /**
     * 切换漫画分类状态
     * 路由: POST api/comic/category/toggleStatus
     * @return \think\response\Json
     */
    public function toggleStatus()
    {
        $param = Request::param(); //
        $categoryId = $param['id'] ?? null; //

        if (empty($categoryId)) {
            return json(['code' => 1, 'msg' => 'ID为必填项', 'data' => (object)[]]); //
        }

        // 检查分类是否存在
        $category = Db::name('comic_categories')->where('id', $categoryId)->find(); //
        if (!$category) {
            return json(['code' => 1, 'msg' => '分类未找到', 'data' => (object)[]]); //
        }

        try {
            $newStatus = ($category['status'] === 1) ? 0 : 1; //
            $updateData = [
                'status' => $newStatus, //
                'updated_at' => date('Y-m-d H:i:s') //
            ];
            $result = Db::name('comic_categories')->where('id', $categoryId)->update($updateData); //

            if ($result !== false) {
                return json(['code' => 0, 'msg' => '状态切换成功', 'data' => ['code'=>0, 'msg'=>'批量设置状态成功']]);
            } else {
                return json(['code' => 1, 'msg' => '状态切换失败或数据无变化', 'data' => (object)[]]); //
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage(), 'data' => (object)[]]); //
        }
    }

    /**
     * 批量设置漫画分类状态
     * 路由: POST api/comic/category/batchSetStatus
     * @return \think\response\Json
     */
    public function batchSetStatus()
    {
        $param = Request::param(); //
        $ids = $param['ids'] ?? []; //
        $status = $param['status'] ?? null; //

        if (empty($ids) || !is_array($ids) || !isset($status) || ($status !== 0 && $status !== 1)) {
            return json(['code' => 1, 'msg' => 'ID列表和有效状态为必填项', 'data' => (object)[]]); //
        }

        try {
            $updateData = [
                'status' => (int)$status, //
                'updated_at' => date('Y-m-d H:i:s') //
            ];
            $result = Db::name('comic_categories')
                ->whereIn('id', $ids) //
                ->update($updateData); //

            if ($result !== false) {
                return json(['code' => 0, 'msg' => '批量设置状态成功', 'data' => ['code'=>0, 'msg'=>'批量设置状态成功']]);
            } else {
                return json(['code' => 1, 'msg' => '批量设置状态失败或数据无变化', 'data' => (object)[]]); //
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage(), 'data' => (object)[]]); //
        }
    }
    /**
 * 根据子分类分页获取漫画列表
 * 路由: GET api/comic/category/sub/comics
 * 参数: subCategoryId, page, pageSize
 */
public function subCategoryComics()
{
    $param = Request::param();
    $subCategoryId = intval($param['subCategoryId'] ?? 0);
    $page = max(1, intval($param['page'] ?? 1));
    $pageSize = intval($param['pageSize'] ?? 15);

    if ($subCategoryId <= 0) {
        return json(['code' => 1, 'msg' => 'subCategoryId 必填', 'data' => []]);
    }

    $query = Db::name('comic_manga')
        ->where('sub_category_id', $subCategoryId)
        ->where('status', 1)
        ->where('is_shelf', 1);

    $total = $query->count();

    $list = $query
        ->order('sort', 'desc')
        ->page($page, $pageSize)
        ->field('id,name,cover,coin,is_vip,is_serializing,chapter_count')
        ->select()
        ->toArray();

    foreach ($list as &$comic) {
        $this->fix_cover($comic);
    }

    return json([
        'code' => 0,
        'msg' => 'success',
        'data' => [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ]
    ]);
}

}