<?php
declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db;

class TextNovelCategoryController extends BaseController
{
    protected $table = 'text_novel_category';

    /**
     * 获取分类列表（主/子分开返回）
     */
    public function list()
    {
        $keyword  = Request::param('keyword', '');
        $parentId = Request::param('parentId', '');
        $status   = Request::param('status', '');

        $query = Db::name($this->table);

        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        if ($parentId !== '' && $parentId !== null) {
            $query->where('parent_id', intval($parentId));
        }
        if ($status !== '') {
            $query->where('status', intval($status));
        }

        $categories = $query->order('sort asc, id asc')->select()->toArray();

        $mainCategories = [];
        $subCategories = [];
        foreach ($categories as $cat) {
            if ($cat['parent_id'] == 0) {
                $mainCategories[] = $cat;
            } else {
                $subCategories[] = $cat;
            }
        }

        return json([
            'code' => 0,
            'msg'  => 'success',
            'data' => [
                'mainCategories' => $mainCategories,
                'subCategories'  => $subCategories,
            ]
        ]);
    }

    /**
     * 新增分类
     */
    public function add()
    {
        $data = Request::only(['name', 'parent_id', 'sort', 'status']);
        if (empty($data['name'])) {
            return json(['code' => 1, 'msg' => '分类名称不能为空']);
        }
        $data['parent_id'] = isset($data['parent_id']) ? intval($data['parent_id']) : 0;
        $data['sort'] = isset($data['sort']) ? intval($data['sort']) : 0;
        $data['status'] = isset($data['status']) ? intval($data['status']) : 1;
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');

        $id = Db::name($this->table)->insertGetId($data);
        if ($id) {
            return json(['code' => 0, 'msg' => '新增成功', 'data' => ['id' => $id]]);
        }
        return json(['code' => 1, 'msg' => '新增失败']);
    }

    /**
     * 编辑分类
     */
    public function update()
    {
        $data = Request::only(['id', 'name', 'parent_id', 'sort', 'status']);
        if (empty($data['id'])) {
            return json(['code' => 1, 'msg' => '缺少ID']);
        }
        if (isset($data['name']) && $data['name'] === '') {
            return json(['code' => 1, 'msg' => '分类名称不能为空']);
        }
        $data['parent_id'] = isset($data['parent_id']) ? intval($data['parent_id']) : 0;
        if (isset($data['sort'])) {
            $data['sort'] = intval($data['sort']);
        }
        if (isset($data['status'])) {
            $data['status'] = intval($data['status']);
        }
        $data['update_time'] = date('Y-m-d H:i:s');

        $ok = Db::name($this->table)->where('id', intval($data['id']))->update($data);
        if ($ok !== false) {
            return json(['code' => 0, 'msg' => '保存成功']);
        }
        return json(['code' => 1, 'msg' => '保存失败']);
    }

    /**
     * 删除分类（单个）
     * 同时删除其所有子分类
     */
    public function delete()
    {
        $id = Request::param('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => '缺少ID']);
        }
        Db::startTrans();
        try {
            // 删除自身和所有 parent_id = $id 的子分类
            Db::name($this->table)->where('id', $id)->delete();
            Db::name($this->table)->where('parent_id', $id)->delete();
            Db::commit();
            return json(['code' => 0, 'msg' => '删除成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '删除失败:' . $e->getMessage()]);
        }
    }

    /**
     * 批量删除分类（含子分类）
     */
    public function batchDelete()
    {
        $ids = Request::param('ids');
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        Db::startTrans();
        try {
            Db::name($this->table)->whereIn('id', $ids)->delete();
            Db::name($this->table)->whereIn('parent_id', $ids)->delete();
            Db::commit();
            return json(['code' => 0, 'msg' => '批量删除成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '批量删除失败:' . $e->getMessage()]);
        }
    }

    /**
     * 启用/禁用（单条）
     */
    public function toggleStatus()
    {
        $id = Request::param('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => '缺少ID']);
        }
        $cur = Db::name($this->table)->where('id', $id)->find();
        if (!$cur) {
            return json(['code' => 1, 'msg' => '该分类不存在']);
        }
        $newStatus = $cur['status'] == 1 ? 0 : 1;
        $ok = Db::name($this->table)->where('id', $id)->update(['status' => $newStatus, 'update_time' => date('Y-m-d H:i:s')]);
        if ($ok !== false) {
            return json(['code' => 0, 'msg' => '切换成功']);
        }
        return json(['code' => 1, 'msg' => '切换失败']);
    }

    /**
     * 批量设置状态
     */
    public function batchSetStatus()
    {
        $ids = Request::param('ids');
        $status = Request::param('status', 1);
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }
        $ok = Db::name($this->table)
            ->whereIn('id', $ids)
            ->update(['status' => intval($status), 'update_time' => date('Y-m-d H:i:s')]);
        if ($ok !== false) {
            return json(['code' => 0, 'msg' => '批量设置成功']);
        }
        return json(['code' => 1, 'msg' => '批量设置失败']);
    }
}
