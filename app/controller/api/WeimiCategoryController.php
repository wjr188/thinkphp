<?php
// 文件路径: E:\ThinkPHP6\app\controller\api\WeimiCategoryController.php
namespace app\controller\api;

use think\Request;
use think\facade\Db;

class WeimiCategoryController // 修正了这里，添加了类名
{
    /**
     * 获取微密圈分类列表 (包含博主、主分类、子分类)
     * 后端预期返回格式: { code: 0, data: { parents: [...], children: [...] } }
     * @param Request $request
     * @return \think\response\Json
     */
    public function list(Request $request)
    {
        try {
            // 获取所有分类数据
            $categories = Db::name('weimi_categories')->order('sort asc, id asc')->select()->toArray();
            // *** 确保你的数据库存在 'weimi_categories' 表 ***
            // 字段至少包含: id, name, parent_id, sort, status, create_time, update_time

            $parents = []; // 存储一级分类 (博主或主分类)
            $children = []; // 存储子分类

            foreach ($categories as $category) {
                if ($category['parent_id'] == 0) {
                    // 假设 parent_id 为 0 的是一级分类（博主/主分类）
                    $parents[] = $category;
                } else {
                    $children[] = $category;
                }
            }

            // 如果需要将子分类嵌套到父分类下，可以在前端处理，或者在这里构建树形结构
            // 为了同步图片管理页面的筛选下拉框，这里返回扁平化的父子列表可能更方便
            // 如果你的前端需要树形结构，可以修改这里的逻辑进行嵌套
            // 例如：
            // foreach ($parents as &$parent) {
            //     $parent['children'] = array_filter($children, fn($c) => $c['parent_id'] == $parent['id']);
            // }

            return successJson([
                'parents' => $parents,
                'children' => $children
            ]);

        } catch (\Exception $e) {
            return errorJson('获取微密圈分类列表失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 新增微密圈分类
     * 通用方法，根据parent_id判断是新增主分类还是子分类
     * @param Request $request POST数据包含 name, parent_id (0为父，非0为子), creator_id (如果需要关联博主)
     * @return \think\response\Json
     */
    public function add(Request $request)
    {
        $data = $request->post();

        if (empty($data['name'])) {
            return errorJson('分类名称不能为空');
        }
        $data['parent_id'] = $data['parent_id'] ?? 0; // 默认为0，即主分类
        $data['status'] = $data['status'] ?? 1; // 默认为启用

        // 如果分类需要关联博主ID
        // $data['creator_id'] = $data['creator_id'] ?? null;

        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');

        // 默认新建排到最后
        if (!isset($data['sort']) || !$data['sort']) {
            $maxSort = Db::name('weimi_categories')->where('parent_id', $data['parent_id'])->max('sort');
            $data['sort'] = $maxSort ? ($maxSort + 1) : 1;
        }
        
        $id = Db::name('weimi_categories')->insertGetId($data);
        return $id ? successJson(['id' => $id]) : errorJson('新增分类失败');
    }

    /**
     * 编辑微密圈分类
     * @param Request $request POST数据包含 id, name, parent_id, sort, status 等
     * @return \think\response\Json
     */
    public function update(Request $request)
    {
        $data = $request->post();
        if (empty($data['id'])) {
            return errorJson('缺少分类ID');
        }

        $data['update_time'] = date('Y-m-d H:i:s');

        // 如果tags字段是数组，可能需要json_encode存储
        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tags'] = json_encode($data['tags']);
        }

        $ret = Db::name('weimi_categories')->where('id', $data['id'])->update($data);
        return $ret !== false ? successJson() : errorJson('更新分类失败');
    }

    /**
     * 删除微密圈分类
     * @param Request $request POST数据包含 id
     * @return \think\response\Json
     */
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return errorJson('缺少分类ID');
        }

        // 检查是否有子分类或关联的图片，避免误删
        $hasChildren = Db::name('weimi_categories')->where('parent_id', $id)->count();
        if ($hasChildren > 0) {
            return errorJson('该分类下存在子分类，请先删除子分类');
        }
        // 假设微密圈图片表中的 category_id 关联的是这个分类的 ID
        $hasImages = Db::name('weimi_images')->where('category_id', $id)->count();
        if ($hasImages > 0) {
            return errorJson('该分类下存在关联图片，请先处理图片');
        }

        $ret = Db::name('weimi_categories')->where('id', $id)->delete();
        return $ret ? successJson() : errorJson('删除分类失败');
    }

    /**
     * 批量删除微密圈分类
     * @param Request $request POST数据包含 ids: []
     * @return \think\response\Json
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少分类ID或参数格式错误');
        }

        // 检查是否有子分类或关联图片，这里简化处理，实际应用中可能需要更复杂的逻辑
        $hasChildren = Db::name('weimi_categories')->whereIn('parent_id', $ids)->count();
        if ($hasChildren > 0) {
            return errorJson('选择的分类中存在父分类，请先删除其子分类');
        }
        $hasImages = Db::name('weimi_images')->whereIn('category_id', $ids)->count();
        if ($hasImages > 0) {
            return errorJson('选择的分类中存在关联图片，请先处理图片');
        }

        $count = Db::name('weimi_categories')->whereIn('id', $ids)->delete();
        return $count ? successJson(['count' => $count], "批量删除成功，共删除{$count}条") : errorJson('批量删除失败');
    }

    /**
     * 批量更新分类排序 (用于分类管理页面的拖拽或手动排序)
     * @param Request $request POST数据包含 list: [{ id: 1, sort: 10 }, ...]
     * @return \think\response\Json
     */
    public function batchUpdateSort(Request $request)
    {
        $list = $request->post('list', []);
        if (empty($list) || !is_array($list)) {
            return errorJson('参数错误，list为空或格式不正确');
        }

        Db::startTrans(); // 开启事务
        try {
            foreach ($list as $item) {
                if (isset($item['id']) && isset($item['sort'])) {
                    Db::name('weimi_categories')->where('id', $item['id'])->update([
                        'sort' => intval($item['sort']),
                        'update_time' => date('Y-m-d H:i:s')
                    ]);
                }
            }
            Db::commit(); // 提交事务
            return successJson([], '分类排序更新成功');
        } catch (\Exception $e) {
            Db::rollback(); // 回滚事务
            return errorJson('分类排序更新失败：' . $e->getMessage(), 500);
        }
    }

    // 辅助函数定义 (如果你的项目中没有全局定义的话)
    // 确保这些函数在你的项目中是可用的
}

// 辅助函数定义 (如果你的项目中没有全局定义的话)
if (!function_exists('successJson')) {
    function successJson($data = [], $message = '操作成功', $code = 0)
    {
        return json(['code' => $code, 'msg' => $message, 'data' => $data]);
    }
}

if (!function_exists('errorJson')) {
    function errorJson($message = '操作失败', $code = 1, $data = [])
    {
        return json(['code' => $code, 'msg' => $message, 'data' => $data]);
    }
}
