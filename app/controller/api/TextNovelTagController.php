<?php
declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\facade\Request;
use think\facade\Validate;

class TextNovelTagController extends BaseController
{
    // 标签列表
    public function list()
    {
        $params = Request::get();
        $keyword = trim($params['keyword'] ?? '');
        $status = $params['status'] ?? null;

        $query = Db::name('text_novel_tag');
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        if ($status !== '' && $status !== null) {
            $query->where('status', intval($status));
        }
        $query->order('sort', 'asc')->order('id', 'desc');
        $list = $query->select()->toArray();

        // 返回标签列表并包含is_vip和coin字段
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => ['list' => $list]
        ]);
    }

    // 新增标签
    public function add()
    {
        $data = Request::post();
        $validate = Validate::rule([
            'name' => 'require|max:60',
            'sort' => 'integer',
            'status' => 'in:0,1',
        ]);
        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }
        $insert = [
            'name' => $data['name'],
            'sort' => isset($data['sort']) ? intval($data['sort']) : 0,
            'status' => isset($data['status']) ? intval($data['status']) : 1,
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s')
        ];
        $id = Db::name('text_novel_tag')->insertGetId($insert);
        if ($id) {
            return json(['code' => 0, 'msg' => '新增标签成功', 'data' => ['id' => $id]]);
        }
        return json(['code' => 1, 'msg' => '新增标签失败']);
    }

    // 编辑/更新标签
    public function update()
    {
        $data = Request::post();
        if (empty($data['id'])) {
            return json(['code' => 1, 'msg' => '缺少标签ID']);
        }
        $validate = Validate::rule([
            'name' => 'require|max:60',
            'sort' => 'integer',
            'status' => 'in:0,1',
        ]);
        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }
        $update = [
            'name' => $data['name'],
            'sort' => isset($data['sort']) ? intval($data['sort']) : 0,
            'status' => isset($data['status']) ? intval($data['status']) : 1,
            'update_time' => date('Y-m-d H:i:s')
        ];
        $res = Db::name('text_novel_tag')->where('id', intval($data['id']))->update($update);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '更新标签成功']);
        }
        return json(['code' => 1, 'msg' => '更新标签失败']);
    }

    // 删除标签
    public function delete()
    {
        $id = intval(Request::post('id'));
        if (!$id) {
            return json(['code' => 1, 'msg' => '缺少标签ID']);
        }
        $res = Db::name('text_novel_tag')->where('id', $id)->delete();
        if ($res) {
            return json(['code' => 0, 'msg' => '删除标签成功']);
        }
        return json(['code' => 1, 'msg' => '删除标签失败']);
    }

    // 批量删除标签
    public function batchDelete()
    {
        $ids = Request::post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '缺少标签ID数组']);
        }
        $res = Db::name('text_novel_tag')->whereIn('id', $ids)->delete();
        if ($res) {
            return json(['code' => 0, 'msg' => '批量删除标签成功']);
        }
        return json(['code' => 1, 'msg' => '批量删除标签失败']);
    }

    // 切换标签状态（启用/禁用）
    public function toggleStatus()
    {
        $id = intval(Request::post('id'));
        if (!$id) {
            return json(['code' => 1, 'msg' => '缺少标签ID']);
        }
        $tag = Db::name('text_novel_tag')->where('id', $id)->find();
        if (!$tag) {
            return json(['code' => 1, 'msg' => '标签不存在']);
        }
        $newStatus = $tag['status'] == 1 ? 0 : 1;
        $res = Db::name('text_novel_tag')->where('id', $id)->update(['status' => $newStatus, 'update_time' => date('Y-m-d H:i:s')]);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '标签状态切换成功', 'data' => ['status' => $newStatus]]);
        }
        return json(['code' => 1, 'msg' => '标签状态切换失败']);
    }

    // 批量设置标签状态
    public function batchSetStatus()
    {
        $ids = Request::post('ids', []);
        $status = Request::post('status', null);
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '缺少标签ID数组']);
        }
        if ($status === null || !in_array($status, [0, 1, '0', '1'], true)) {
            return json(['code' => 1, 'msg' => '状态值错误']);
        }
        $res = Db::name('text_novel_tag')->whereIn('id', $ids)->update(['status' => intval($status), 'update_time' => date('Y-m-d H:i:s')]);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '批量设置标签状态成功']);
        }
        return json(['code' => 1, 'msg' => '批量设置标签状态失败']);
    }
}
