<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;

class DouyinCategoryController
{
    // 分类列表
    public function list(Request $request)
    {
        $categories = Db::name('douyin_categories')->order('sort asc,id asc')->select()->toArray();
        $parents = [];
        $children = [];
        foreach ($categories as $category) {
            $category['status'] = (int)$category['status'];
            $category['tags'] = json_decode($category['tags'] ?? '[]', true);

            // 统计该分类下视频数量（仅对子分类统计，主分类为0）
            if ($category['parent_id'] > 0) {
                $category['videoCount'] = Db::name('douyin_videos')->where('category_id', $category['id'])->count();
            } else {
                $category['videoCount'] = 0;
            }

            if ($category['parent_id'] == 0) {
                $parents[] = $category;
            } else {
                $children[] = $category;
            }
        }
        return successJson(['parents' => $parents, 'children' => $children], '获取抖音分类列表成功');
    }

    // 新增主分类
    public function addParent(Request $request)
    {
        $data = $request->post();
        if (empty($data['name'])) return errorJson('分类名称不能为空');
        $data['parent_id'] = 0;
        $data['icon'] = $data['icon'] ?? ''; // ★补充
        $data['tags'] = isset($data['tags']) && is_array($data['tags']) ? json_encode($data['tags']) : '[]';
        $data['sort'] = intval($data['sort'] ?? 0);
        $data['status'] = intval($data['status'] ?? 1);
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        $id = Db::name('douyin_categories')->insertGetId($data);
        return $id ? successJson(['id'=>$id], '新增主分类成功') : errorJson('新增主分类失败');
    }

    // 新增子分类
    public function addChild(Request $request)
    {
        $data = $request->post();
        if (empty($data['name'])) return errorJson('分类名称不能为空');
        $data['parent_id'] = intval($data['parent_id'] ?? 0);
        if ($data['parent_id'] === 0) return errorJson('parent_id不能为空');
        $data['icon'] = $data['icon'] ?? ''; // ★补充
        $data['tags'] = isset($data['tags']) && is_array($data['tags']) ? json_encode($data['tags']) : '[]';
        $data['sort'] = intval($data['sort'] ?? 0);
        $data['status'] = intval($data['status'] ?? 1);
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        $id = Db::name('douyin_categories')->insertGetId($data);
        return $id ? successJson(['id'=>$id], '新增子分类成功') : errorJson('新增子分类失败');
    }

    // 编辑分类
    public function update(Request $request)
    {
        $data = $request->post();
        if (empty($data['id'])) return errorJson('缺少分类ID');
        // 只保留数据库实际存在的字段
        $fields = ['id', 'name', 'parent_id', 'sort', 'status', 'tags', 'icon', 'update_time'];
        $updateData = [];
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        $updateData['update_time'] = date('Y-m-d H:i:s');
        unset($updateData['create_time']);
        $ret = Db::name('douyin_categories')->where('id', $data['id'])->update($updateData);
        return $ret !== false ? successJson([], '编辑成功') : errorJson('编辑失败');
    }

    // 删除分类
    public function delete(Request $request)
    {
        $id = intval($request->post('id'));
        if (!$id) return errorJson('ID不能为空');
        Db::name('douyin_categories')->where('id', $id)->delete();
        Db::name('douyin_categories')->where('parent_id', $id)->delete();
        return successJson([], '删除成功');
    }

    // 批量删除
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (!is_array($ids) || empty($ids)) return errorJson('参数错误');
        Db::name('douyin_categories')->whereIn('id', $ids)->delete();
        Db::name('douyin_categories')->whereIn('parent_id', $ids)->delete();
        return successJson([], '批量删除成功');
    }

    // 批量排序（已加事务优化）
    public function batchUpdateSort(Request $request)
    {
        $list = $request->post('list', []);
        if (empty($list) || !is_array($list)) return errorJson('参数错误');

        // 开启事务，避免批量失败导致脏数据
        Db::startTrans();
        try {
            foreach ($list as $item) {
                if (empty($item['id'])) continue;
                $sort = isset($item['sort']) ? intval($item['sort']) : 1;
                Db::name('douyin_categories')->where('id', $item['id'])->update([
                    'sort' => $sort,
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            }
            Db::commit();
            return successJson([], '批量排序保存成功');
        } catch (\Exception $e) {
            Db::rollback();
            return errorJson('批量排序失败：' . $e->getMessage());
        }
    }
}

// 辅助方法
if (!function_exists('successJson')) {
    function successJson($data = [], $msg = '操作成功', $code = 0)
    {
        return json(['code'=>$code, 'msg'=>$msg, 'data'=>$data]);
    }
}
if (!function_exists('errorJson')) {
    function errorJson($msg = '操作失败', $code = 1, $data = [])
    {
        return json(['code'=>$code, 'msg'=>$msg, 'data'=>$data]);
    }
}
