<?php
// 文件路径: app/controller/api/AnimeCategoryController.php

namespace app\controller\api;

use app\BaseController;
use think\Request;
use think\facade\Db;

class AnimeCategoryController extends BaseController
{
    // 获取主/子分类 GET /api/anime/categories/list
    public function list()
    {
        $parents = Db::name('anime_categories')->where('parent_id', 0)->order('sort')->select()->toArray();
        $children = Db::name('anime_categories')->where('parent_id', '>', 0)->order('sort')->select()->toArray();
        
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'parents' => $parents,   // 主分类数组
                'children' => $children, // 子分类数组
            ]
        ]);
    }

    // 新增主分类 POST /api/anime/categories/add-parent
    public function addParent(Request $request)
{
    $data = $request->post();
    if (empty($data['name'])) return json(['code'=>1, 'msg'=>'主分类名不能为空']);
    $ins = [
        'name' => $data['name'],
        'parent_id' => 0,
        'sort' => intval($data['sort'] ?? 1),
        'status' => isset($data['status']) ? intval($data['status']) : 1,
        'create_time' => date('Y-m-d H:i:s'),
        'update_time' => date('Y-m-d H:i:s'),
        // 不包含 layout_type 和 icon
    ];
    $id = Db::name('anime_categories')->insertGetId($ins);
    return json(['code'=>0, 'msg'=>'添加主分类成功', 'id'=>$id]);
}

    // 新增子分类 POST /api/anime/categories/add-child
    public function addChild(Request $request)
{
    $data = $request->post();
    if (empty($data['name'])) return json(['code'=>1, 'msg'=>'子分类名不能为空']);
    $parent_id = intval($data['parent_id'] ?? 0);
    if (!$parent_id) return json(['code'=>1, 'msg'=>'请选择所属主分类']);
    $ins = [
        'name' => $data['name'],
        'parent_id' => $parent_id,
        'sort' => intval($data['sort'] ?? 1),
        'status' => isset($data['status']) ? intval($data['status']) : 1,
        'layout_type' => $data['layout_type'] ?? 'type2',
        'icon' => $data['icon'] ?? '',
        'create_time' => date('Y-m-d H:i:s'),
        'update_time' => date('Y-m-d H:i:s'),
    ];
    $id = Db::name('anime_categories')->insertGetId($ins);
    return json(['code'=>0, 'msg'=>'添加子分类成功', 'id'=>$id]);
}

    // 编辑分类（主/子分类共用）POST /api/anime/categories/update
    public function update(Request $request)
{
    $data = $request->post();
    $id = intval($data['id'] ?? 0);
    if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
    unset($data['create_time']);
    $data['update_time'] = date('Y-m-d H:i:s');

    // 取出分类信息，判断是否主分类
    $category = Db::name('anime_categories')->where('id', $id)->find();
    if (!$category) return json(['code'=>1, 'msg'=>'分类不存在']);

    if ($category['parent_id'] == 0) {
        // 主分类，移除 layout_type 和 icon 字段，防止更新
        unset($data['layout_type']);
        unset($data['icon']);
    }

    // 只允许更新数据库表中存在的字段
    $allowedFields = ['name', 'parent_id', 'sort', 'status', 'layout_type', 'icon', 'update_time', 'tags'];
    $updateData = [];
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateData[$field] = $data[$field];
        }
    }

    Db::name('anime_categories')->where('id', $id)->update($updateData);
    return json(['code'=>0, 'msg'=>'编辑成功']);
}

    // 删除分类（主/子分类共用）POST /api/anime/categories/delete
    public function delete(Request $request)
    {
        $id = intval($request->post('id'));
        if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
        Db::name('anime_categories')->where('id', $id)->delete();
        Db::name('anime_categories')->where('parent_id', $id)->delete(); // 删除所有子
        return json(['code'=>0, 'msg'=>'删除成功']);
    }

    // 批量删除 POST /api/anime/categories/batch-delete
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
        Db::name('anime_categories')->whereIn('id', $ids)->delete();
        Db::name('anime_categories')->whereIn('parent_id', $ids)->delete();
        return json(['code'=>0, 'msg'=>'批量删除成功']);
    }

    // 批量排序 POST /api/anime/categories/batch-update-sort
    public function batchUpdateSort(Request $request)
    {
        $list = $request->post('list', []);
        if (empty($list) || !is_array($list)) return json(['code'=>1, 'msg'=>'参数错误']);
        foreach ($list as $item) {
            if (empty($item['id'])) continue;
            $sort = isset($item['sort']) ? intval($item['sort']) : 1;
            Db::name('anime_categories')->where('id', $item['id'])->update([
                'sort'=>$sort,
                'update_time'=>date('Y-m-d H:i:s')
            ]);
        }
        return json(['code'=>0, 'msg'=>'排序更新成功']);
    }  
}
