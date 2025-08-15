<?php
// 文件路径: app/controller/api/AnimeTagController.php

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;  // 改为 facade
use think\facade\Db;

class AnimeTagController extends BaseController
{
    // 列表 GET /api/anime/tags/list
    public function list()
    {
        $params = Request::param();  // 现在可以正常调用了
        $where = [];
        
        if (!empty($params['keyword'])) {
            // 修复多字段搜索语法
            $where[] = ['name', 'like', '%' . $params['keyword'] . '%'];
            // 如果需要同时搜索 alias，应该用 whereOr
        }
        
        if (!empty($params['group'])) {
            $where[] = ['group', '=', $params['group']];
        }
        
        // 修复状态判断逻辑
        if (isset($params['status']) && $params['status'] !== '' && $params['status'] !== null) {
            $where[] = ['status', '=', (int)$params['status']];
        }
        
        try {
            $list = Db::name('anime_tags')
                ->where($where)
                ->order('sort', 'asc')
                ->order('id', 'desc')
                ->select()
                ->toArray();
                
            return json(['code' => 0, 'data' => $list]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '查询失败：' . $e->getMessage()]);
        }
    }

    // 新增标签 POST /api/anime/tags/add
    public function add()
    {
        $data = Request::post();
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        $id = Db::name('anime_tags')->insertGetId($data);
        return json(['code'=>0, 'msg'=>'添加成功', 'id'=>$id]);
    }

    // 编辑标签 POST /api/anime/tags/update
    public function update()
    {
        $data = Request::post();
        $id = intval($data['id'] ?? 0);
        if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
        unset($data['create_time']); // 防止前端误传
        $data['update_time'] = date('Y-m-d H:i:s');
        Db::name('anime_tags')->where('id', $id)->update($data);
        return json(['code'=>0, 'msg'=>'编辑成功']);
    }

    // 删除标签 POST /api/anime/tags/delete
    public function delete()
    {
        $id = intval(Request::post('id'));
        if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
        Db::name('anime_tags')->where('id', $id)->delete();
        return json(['code'=>0, 'msg'=>'删除成功']);
    }

    // 批量删除标签 POST /api/anime/tags/batch-delete
    public function batchDelete()
    {
        $ids = Request::post('ids', []);
        if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
        Db::name('anime_tags')->whereIn('id', $ids)->delete();
        return json(['code'=>0, 'msg'=>'批量删除成功']);
    }

    // 批量禁用标签 POST /api/anime/tags/batch-disable
    public function batchDisable()
    {
        $ids = Request::post('ids', []);
        if (!is_array($ids) || empty($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
        Db::name('anime_tags')->whereIn('id', $ids)->update(['status'=>0]);
        return json(['code'=>0, 'msg'=>'批量禁用成功']);
    }

    // 启用/禁用切换 POST /api/anime/tags/toggle-status
    public function toggleStatus()
    {
        $id = intval(Request::post('id'));
        if (!$id) return json(['code'=>1, 'msg'=>'ID不能为空']);
        $row = Db::name('anime_tags')->where('id', $id)->find();
        if (!$row) return json(['code'=>1, 'msg'=>'标签不存在']);
        $newStatus = $row['status'] == 1 ? 0 : 1;
        Db::name('anime_tags')->where('id', $id)->update(['status'=>$newStatus]);
        return json(['code'=>0, 'msg'=>'状态切换成功']);
    }

    // 批量排序 POST /api/anime/tags/batch-update-sort
    public function batchUpdateSort()
    {
        $list = Request::post('list', []);
        if (empty($list) || !is_array($list)) return json(['code'=>1, 'msg'=>'参数错误']);
        foreach ($list as $item) {
            if (empty($item['id'])) continue;
            $sort = isset($item['sort']) ? intval($item['sort']) : 0;
            Db::name('anime_tags')->where('id', $item['id'])->update([
                'sort'=>$sort,
                'update_time'=>date('Y-m-d H:i:s')
            ]);
        }
        return json(['code'=>0, 'msg'=>'排序更新成功']);
    }
}
