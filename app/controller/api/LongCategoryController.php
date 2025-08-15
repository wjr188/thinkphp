<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;

class LongCategoryController
{
    // 获取长视频分类列表（主/子分类）
    public function list(Request $request)
    {
        // 1. 直接从数据库获取所有分类数据
        // 确保查询结果是唯一的，即便数据库中有脏数据，这里也应该进行处理
        // 使用 groupBy id 通常可以去重，但如果 id 重复是真实的，那说明数据库设计有问题
        // 更安全的做法是假设 id 是唯一的，如果数据库设计有问题，先去修复数据库。
        // 这里只是为了避免因数据库脏数据导致重复，前端去重才是更通用的做法。
        // 但如果 id 在数据库层面是唯一的，那这里不需要 groupBy
        $allCategories = Db::name('long_video_categories')
                            ->order('sort asc, id asc')
                            ->select()
                            ->toArray();

        $parents = [];
        $children = [];

        // 使用Map进行内存去重，防止数据库返回重复ID（虽然理想情况下数据库应该保证唯一）
        // 并且确保每个分类只有一个实例被处理
        $processedCategories = [];
        foreach ($allCategories as $category) {
            if (!isset($processedCategories[$category['id']])) {
                // 移除 tags 初始化
                // $category['tags'] = []; // 不需要 tags 字段

                $category['status'] = (int)$category['status'];

                if ($category['parent_id'] !== 0) {
                    $category['videoCount'] = Db::name('long_videos')->where('category_id', $category['id'])->count();
                } else {
                    $category['videoCount'] = 0;
                }

                $processedCategories[$category['id']] = $category;
            }
        }
        
        // 再次遍历处理后的分类，填充 parents 和 children
        foreach ($processedCategories as $category) {
            if ($category['parent_id'] === 0) {
                $parents[] = $category;
            } else {
                $children[] = $category;
            }
        }
        
        $onlyParents = $request->get('only_parents', 0);
        $parentId = $request->get('parent_id', 0);

        // 只返回主分类
        if ($onlyParents) {
            // 显式补充 icon 字段（如果没有就补空字符串）
            foreach ($parents as &$parent) {
                if (!isset($parent['icon'])) {
                    $parent['icon'] = '';
                }
            }
            unset($parent);
            return successJson([
                'parents' => $parents
            ]);
        }

        // 如果传了 parent_id，只返回该主分类下的子分类
        if ($parentId) {
            $childrenOfParent = [];
            foreach ($children as $child) {
                if ($child['parent_id'] == $parentId) {
                    $childrenOfParent[] = $child;
                }
            }
            return successJson([
                'children' => $childrenOfParent
            ]);
        }

        // 默认返回主分类和全部子分类
        foreach ($parents as &$parent) {
            if (!isset($parent['icon'])) {
                $parent['icon'] = '';
            }
        }
        unset($parent);

        foreach ($children as &$child) {
            if (!isset($child['icon'])) {
                $child['icon'] = '';
            }
        }
        unset($child);

        return successJson([
            'parents' => $parents,
            'children' => $children
        ]);
    }

    // 新增主分类
    public function addParent(Request $request)
    {
        $name = trim((string)$request->post('name', ''));
        $icon = trim((string)$request->post('icon', '')); // 新增

        if (empty($name)) {
            return errorJson('主分类名称必填'); 
        }
        
        $data = [
            'name'          => $name,
            'parent_id'     => 0,
            'sort'          => 1,
            'status'        => 1,
            'icon'          => $icon, // 新增
            'create_time'   => date('Y-m-d H:i:s'),
            'update_time'   => date('Y-m-d H:i:s'),
        ];
        
        try {
            // 检查同名主分类是否存在，避免业务上的重复
            $exists = Db::name('long_video_categories')->where('name', $name)->where('parent_id', 0)->find();
            if ($exists) {
                return errorJson('主分类名称已存在');
            }

            $id = Db::name('long_video_categories')->insertGetId($data);
            return $id ? successJson(['id'=>$id], '添加主分类成功') : errorJson('添加主分类失败');
        } catch (\Exception $e) {
            return errorJson('添加主分类失败: ' . $e->getMessage());
        }
    }

    // 新增子分类
    public function addChild(Request $request)
    {
        $name = trim((string)$request->post('name', ''));
        $parentId = intval($request->post('parent_id', 0));
        $sort = intval($request->post('sort', 1));
        $status = intval($request->post('status', 1));
        $icon = trim((string)$request->post('icon', '')); // 新增

        if (empty($name)) {
            return errorJson('子分类名称必填');
        }
        if ($parentId === 0) {
            return errorJson('请选择主分类');
        }

        $data = [
            'name'          => $name,
            'parent_id'     => $parentId,
            'sort'          => $sort,
            'status'        => $status,
            'icon'          => $icon, // 新增
            'create_time'   => date('Y-m-d H:i:s'),
            'update_time'   => date('Y-m-d H:i:s'),
        ];

        try {
            // 检查同一主分类下是否存在同名子分类，避免业务上的重复
            $exists = Db::name('long_video_categories')->where('name', $name)->where('parent_id', $parentId)->find();
            if ($exists) {
                return errorJson('该主分类下已存在同名子分类');
            }

            $id = Db::name('long_video_categories')->insertGetId($data);
            return $id ? successJson(['id'=>$id], '添加子分类成功') : errorJson('添加子分类失败');
        } catch (\Exception $e) {
            return errorJson('添加子分类失败: ' . $e->getMessage());
        }
    }

    // 编辑分类
    public function update(Request $request)
    {
        $id = intval($request->post('id', 0));
        $name = trim((string)$request->post('name', ''));
        $parentId = intval($request->post('parent_id', -1));
        $sort = intval($request->post('sort', 0));
        $status = intval($request->post('status', 1));
        $icon = trim((string)$request->post('icon', '')); // 新增

        if (!$id) return errorJson('ID不能为空');
        if (empty($name)) return errorJson('分类名称必填');

        $updateData = [
            'name'          => $name,
            'sort'          => $sort,
            'status'        => $status,
            'icon'          => $icon, // 新增
            'update_time'   => date('Y-m-d H:i:s'),
        ];

        if ($parentId !== -1) {
            $updateData['parent_id'] = $parentId;
        }

        try {
            $existingCategory = Db::name('long_video_categories')->where('id', $id)->find();
            if (!$existingCategory) {
                return errorJson('分类不存在');
            }
            $currentParentId = $existingCategory['parent_id'];

            $exists = Db::name('long_video_categories')
                        ->where('name', $name)
                        ->where('parent_id', $currentParentId)
                        ->where('id', '<>', $id)
                        ->find();
            if ($exists) {
                return errorJson('同级分类下已存在同名分类');
            }

            $ret = Db::name('long_video_categories')->where('id', $id)->update($updateData);
            return $ret !== false ? successJson([], '编辑成功') : errorJson('编辑失败');
        } catch (\Exception $e) {
            return errorJson('编辑失败: ' . $e->getMessage());
        }
    }

    // 删除分类 (同时删除其子分类)
    public function delete(Request $request)
    {
        $id = intval($request->post('id', 0));
        if (!$id) return errorJson('ID不能为空');
        try {
            // 删除分类自身
            Db::name('long_video_categories')->where('id', $id)->delete();
            // 删除其所有子分类（如果是主分类）
            Db::name('long_video_categories')->where('parent_id', $id)->delete();
            // TODO: 关联删除视频
            // Db::name('long_videos')->where('category_id', $id)->delete();
            // 对于被删除子分类的视频也需要删除，这需要更复杂的逻辑，例如先查出所有要删除的分类ID

            return successJson([], '删除成功');
        } catch (\Exception $e) {
            return errorJson('删除失败: ' . $e->getMessage());
        }
    }

    // 批量删除 (同时删除其子分类)
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (!is_array($ids) || empty($ids)) return errorJson('参数错误');
        
        try {
            // 收集所有需要删除的ID，包括被选中的主分类的子分类
            $allIdsToDelete = $ids;
            $childCategoriesToDelete = Db::name('long_video_categories')->whereIn('parent_id', $ids)->column('id');
            $allIdsToDelete = array_merge($allIdsToDelete, $childCategoriesToDelete);
            $allIdsToDelete = array_unique(array_map('intval', $allIdsToDelete)); // 去重并确保是整数

            if (empty($allIdsToDelete)) {
                return successJson([], '没有需要删除的分类');
            }

            Db::name('long_video_categories')->whereIn('id', $allIdsToDelete)->delete();
            // TODO: 关联删除视频
            // Db::name('long_videos')->whereIn('category_id', $allIdsToDelete)->delete();

            return successJson([], '批量删除成功');
        } catch (\Exception $e) {
            return errorJson('批量删除失败: ' . $e->getMessage());
        }
    }

    // 更新子分类标签
    // 注意：如果 long_video_categories 表没有 tags 字段，此方法需要移除或表结构需要添加 tags 字段
    public function updateChildTags(Request $request)
    {
        $id = intval($request->post('id', 0));
        $tags = $request->post('tags', []); // 从前端接收数组

        if (!$id) return errorJson('ID不能为空');
        if (!is_array($tags)) $tags = []; // 确保 $tags 是数组

        // 请务必确认你的 long_video_categories 表是否有 tags 字段
        // 如果没有，你需要修改数据库表结构，或者此方法就没有实际意义
        // 假设 long_video_categories 表有 tags 字段 (TEXT 或 JSON 类型)
        $categoryExists = Db::name('long_video_categories')->where('id', $id)->find();
        if (!$categoryExists) {
            return errorJson('分类不存在');
        }
        
        // 只有子分类允许更新 tags (parent_id !== 0)
        if ($categoryExists['parent_id'] === 0) {
            return errorJson('主分类不能设置标签');
        }

        try {
            Db::name('long_video_categories')->where('id', $id)->update([
                'tags' => json_encode($tags, JSON_UNESCAPED_UNICODE), 
                'update_time'=>date('Y-m-d H:i:s')
            ]);
            return successJson([], '标签更新成功');
        } catch (\Exception $e) {
            // 如果表没有 tags 字段，会在这里抛出异常，提示 unknown column
            return errorJson('标签更新失败，请检查数据库表是否包含 tags 字段: ' . $e->getMessage());
        }
    }

    // 更新子分类排序
    public function updateChildSort(Request $request)
    {
        $id = intval($request->post('id', 0));
        $sort = intval($request->post('sort', 0));
        if (!$id) return errorJson('ID不能为空');
        
        try {
            $ret = Db::name('long_video_categories')->where('id', $id)->where('parent_id', '<>', 0)->update([ // 确保是子分类
                'sort'=>$sort, 
                'update_time'=>date('Y-m-d H:i:s')
            ]);
            return $ret !== false ? successJson([], '子分类排序更新成功') : errorJson('子分类排序更新失败');
        } catch (\Exception $e) {
            return errorJson('子分类排序更新失败: ' . $e->getMessage());
        }
    }

    // 更新主分类排序
    public function updateParentSort(Request $request)
    {
        $id = intval($request->post('id', 0));
        $sort = intval($request->post('sort', 0));
        if (!$id) return errorJson('ID不能为空');
        
        try {
            $ret = Db::name('long_video_categories')->where('id', $id)->where('parent_id', 0)->update([
                'sort'=>$sort, 
                'update_time'=>date('Y-m-d H:i:s')
            ]);
            return $ret !== false ? successJson([], '主分类排序更新成功') : errorJson('主分类排序更新失败');
        } catch (\Exception $e) {
            return errorJson('主分类排序更新失败: ' . $e->getMessage());
        }
    }

    // 批量排序
    public function batchUpdateSort(Request $request)
    {
        $list = $request->post('list', []);
        if (empty($list) || !is_array($list)) return errorJson('参数错误');
        
        try {
            Db::startTrans(); // 开启事务
            foreach ($list as $item) {
                $id = intval($item['id'] ?? 0);
                if (!$id) continue;
                $sort = isset($item['sort']) ? intval($item['sort']) : 1;
                Db::name('long_video_categories')->where('id', $id)->update([
                    'sort'=>$sort,
                    'update_time'=>date('Y-m-d H:i:s')
                ]);
            }
            Db::commit(); // 提交事务
            return successJson([], '批量排序保存成功');
        } catch (\Exception $e) {
            Db::rollback(); // 回滚事务
            return errorJson('批量排序保存失败: ' . $e->getMessage());
        }
    }
}

// 成功/失败辅助函数 (如果你的项目中没有全局定义的话，请确保这些函数在你的项目中是可用的)
if (!function_exists('successJson')) {
    function successJson($data = [], $message = '操作成功', $code = 0)
    {
        return json(['code'=>$code, 'msg'=>$message, 'data' => $data]);
    }
}
if (!function_exists('errorJson')) {
    function errorJson($message = '操作失败', $code = 1, $data = [])
    {
        return json(['code'=>$code, 'msg'=>$message, 'data' => $data]);
    }
}