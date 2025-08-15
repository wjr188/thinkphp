<?php
namespace app\controller\api;

use app\BaseController;
use think\Request;
use think\facade\Db;

class DarknetCategoryController extends BaseController
{
    /**
     * 获取暗网分类列表
     * GET /api/darknet/categories/list
     * 返回：{ code, data: { parents: [], children: [] } }
     */
    public function list()
    {
        // 查询所有主分类
        $parents = Db::name('darknet_category')
            ->where('parent_id', 0)
            ->order('sort asc,id asc')
            ->select()
            ->toArray();

        // 查询所有子分类
        $children = Db::name('darknet_category')
            ->where('parent_id', '>', 0)
            ->order('sort asc,id asc')
            ->select()
            ->toArray();

        // 构建主分类名称映射
        $parentNameMap = [];
        foreach ($parents as $p) {
            $parentNameMap[$p['id']] = $p['name'];
        }

        // 主分类标签处理
        foreach ($parents as &$p) {
            $p['tags'] = (isset($p['tags']) && $p['tags']!=='') ? explode(',', $p['tags']) : [];
            $p['videoCount'] = 0; // 主分类视频数为0，前端可自行统计所有子分类总和
        }
        unset($p);

        // 子分类标签处理、视频数量统计、parentName组装
        foreach ($children as &$c) {
            $c['tags'] = (isset($c['tags']) && $c['tags']!=='') ? explode(',', $c['tags']) : [];
            // 统计该子分类下视频数量
            $c['videoCount'] = Db::name('darknet_video')->where('category_id', $c['id'])->count();
            // 动态组装所属主分类名称
            $c['parentName'] = isset($parentNameMap[$c['parent_id']]) ? $parentNameMap[$c['parent_id']] : '--';
        }
        unset($c);

        return json([
            'code' => 0,
            'data' => [
                'parents' => $parents,
                'children' => $children
            ]
        ]);
    }

    /**
     * 新增主分类
     * POST /api/darknet/categories/add-parent
     * @body { name, sort, status, tags[] }
     */
    public function addParent(Request $request)
    {
        $data = $request->post();

        // 基本校验
        if (empty($data['name'])) {
            return json(['code'=>1, 'msg'=>'分类名称不能为空']);
        }
        $data['parent_id'] = 0;
        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tags'] = implode(',', $data['tags']);
        }
        $data['sort'] = intval($data['sort'] ?? 1);
        $data['status'] = intval($data['status'] ?? 1);
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        $id = Db::name('darknet_category')->insertGetId($data);
        if (!$id) {
            return json(['code'=>1, 'msg'=>'添加失败']);
        }
        return json(['code'=>0, 'msg'=>'添加成功', 'id'=>$id]);
    }

    /**
     * 新增子分类
     * POST /api/darknet/categories/add-child
     * @body { name, parent_id, sort, status, tags[] }
     */
    public function addChild(Request $request)
    {
        $data = $request->post();

        if (empty($data['name'])) {
            return json(['code'=>1, 'msg'=>'分类名称不能为空']);
        }
        $data['parent_id'] = intval($data['parent_id'] ?? 0);
        if ($data['parent_id'] === 0) {
            return json(['code'=>1, 'msg'=>'parent_id不能为空']);
        }
        // 判父分类是否存在（防止插孤儿节点）
        $parent = Db::name('darknet_category')->where('id', $data['parent_id'])->find();
        if (!$parent) {
            return json(['code'=>1, 'msg'=>'父分类不存在']);
        }

        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tags'] = implode(',', $data['tags']);
        }
        $data['sort'] = intval($data['sort'] ?? 1);
        $data['status'] = intval($data['status'] ?? 1);
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        $id = Db::name('darknet_category')->insertGetId($data);
        if (!$id) {
            return json(['code'=>1, 'msg'=>'添加失败']);
        }
        return json(['code'=>0, 'msg'=>'添加成功', 'id'=>$id]);
    }

    /**
     * 编辑分类（主/子分类都走）
     * POST /api/darknet/categories/update
     * @body { id, ...fields }
     */
    public function update(Request $request)
    {
        $data = $request->post();
        $id = intval($data['id'] ?? 0);
        if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
        unset($data['id']);

        // 过滤掉前端展示字段
        unset($data['isParent'], $data['videoCount'], $data['parentName']);

        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tags'] = implode(',', $data['tags']);
        }
        $data['update_time'] = date('Y-m-d H:i:s');
        $ok = Db::name('darknet_category')->where('id', $id)->update($data);
        if ($ok === false) {
            return json(['code'=>1, 'msg'=>'编辑失败']);
        }
        return json(['code'=>0, 'msg'=>'编辑成功']);
    }

    /**
     * 删除分类
     * POST /api/darknet/categories/delete
     * @body { id }
     */
    public function delete(Request $request)
    {
        $id = intval($request->post('id'));
        if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
        // 检查是否存在
        $exists = Db::name('darknet_category')->where('id', $id)->find();
        if (!$exists) return json(['code'=>1, 'msg'=>'该分类不存在']);
        Db::name('darknet_category')->where('id', $id)->delete();
        Db::name('darknet_category')->where('parent_id', $id)->delete();
        return json(['code'=>0, 'msg'=>'删除成功']);
    }

    /**
     * 批量删除
     * POST /api/darknet/categories/batch-delete
     * @body { ids[] }
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
        // 过滤不存在的id
        $realIds = Db::name('darknet_category')->whereIn('id', $ids)->column('id');
        if (empty($realIds)) return json(['code'=>1, 'msg'=>'要删除的分类不存在']);
        Db::name('darknet_category')->whereIn('id', $realIds)->delete();
        Db::name('darknet_category')->whereIn('parent_id', $realIds)->delete();
        return json(['code'=>0, 'msg'=>'批量删除成功']);
    }

    /**
     * 批量排序
     * POST /api/darknet/categories/batch-update-sort
     * @body { list: [{id, sort}] }
     */
    public function batchUpdateSort(Request $request)
    {
        $list = $request->post('list', []);
        if (empty($list) || !is_array($list)) return json(['code'=>1, 'msg'=>'参数错误']);
        foreach ($list as $item) {
            if (empty($item['id'])) continue;
            $sort = isset($item['sort']) ? intval($item['sort']) : 1;
            Db::name('darknet_category')->where('id', $item['id'])->update([
                'sort'=>$sort,
                'update_time'=>date('Y-m-d H:i:s')
            ]);
        }
        return json(['code'=>0, 'msg'=>'排序更新成功']);
    }

    /**
     * 更新子分类标签
     * POST /api/darknet/categories/update-child-tags
     * @body { id, tags[] }
     */
    public function updateChildTags(Request $request)
    {
        $id = $request->post('id');
        $tags = $request->post('tags', []);
        if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
        $tagsStr = is_array($tags) ? implode(',', $tags) : $tags;
        $ok = Db::name('darknet_category')->where('id', $id)->update(['tags' => $tagsStr, 'update_time'=>date('Y-m-d H:i:s')]);
        if ($ok === false) {
            return json(['code'=>1, 'msg'=>'标签更新失败']);
        }
        return json(['code'=>0, 'msg'=>'标签更新成功']);
    }

    /**
     * 单个保存排序
     * POST /api/darknet/categories/update-child-sort
     * @body { id, sort }
     */
    public function updateChildSort(Request $request)
    {
        $id = $request->post('id');
        $sort = $request->post('sort');
        if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
        $ok = Db::name('darknet_category')->where('id', $id)->update([
            'sort' => intval($sort),
            'update_time' => date('Y-m-d H:i:s')
        ]);
        if ($ok === false) {
            return json(['code'=>1, 'msg'=>'排序保存失败']);
        }
        return json(['code'=>0, 'msg'=>'排序保存成功']);
    }

    /**
     * 获取主分类列表
     * GET /api/darknet/categories/parents
     * 返回：{ code, data: { parents: [] } }
     */
    public function h5List(Request $request)
    {
        $onlyParents = $request->get('only_parents/d', 0);

        $query = Db::name('darknet_category')->order('sort asc, id asc');
        if ($onlyParents) {
            $query->where('parent_id', 0);
        }
        $list = $query->select()->toArray();

        // 字段适配
        foreach ($list as &$item) {
            $item['status'] = (int)($item['status'] ?? 1);
            $item['videoCount'] = (int)($item['videoCount'] ?? 0);
            $item['is_recommend'] = (int)($item['is_recommend'] ?? 0);
            $item['recommend_sort'] = (int)($item['recommend_sort'] ?? 0);
        }
        unset($item);

        return json([
            'code' => 0,
            'msg' => '操作成功',
            'data' => [
                'list' => $list,
                'total' => count($list)
            ]
        ]);
    }
}
