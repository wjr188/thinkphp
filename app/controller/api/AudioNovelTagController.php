<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use app\BaseController;

class AudioNovelTagController extends BaseController
{
    // 标签列表
    public function list(Request $request)
    {
        $keyword = $request->get('keyword', '');
        $status = $request->get('status', '');

        $query = Db::name('audio_novel_tag');
        if ($keyword !== '') {
            $query->whereLike('name', "%$keyword%");
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        $query->order('sort', 'asc')->order('id', 'asc');
        $list = $query->select()->toArray();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => count($list),
            ]
        ]);
    }

    // 新增
    public function add(Request $request)
    {
        $data = $request->post();
        if (empty($data['name'])) {
            return json(['code' => 1, 'msg' => '标签名称为必填项']);
        }
        $data['sort'] = $data['sort'] ?? 0;
        $data['status'] = $data['status'] ?? 1;
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        $id = Db::name('audio_novel_tag')->insertGetId($data);
        if ($id) {
            return json(['code' => 0, 'msg' => '新增成功']);
        }
        return json(['code' => 1, 'msg' => '新增失败']);
    }

    // 更新
    public function update(Request $request)
    {
        $data = $request->post();
        if (empty($data['id']) || empty($data['name'])) {
            return json(['code' => 1, 'msg' => 'ID和标签名称为必填项']);
        }
        $data['update_time'] = date('Y-m-d H:i:s');
        $res = Db::name('audio_novel_tag')->where('id', $data['id'])->update($data);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '保存成功']);
        }
        return json(['code' => 1, 'msg' => '保存失败']);
    }

    // 删除
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => 'ID为必填项']);
        }
        $res = Db::name('audio_novel_tag')->where('id', $id)->delete();
        if ($res) {
            return json(['code' => 0, 'msg' => '删除成功']);
        }
        return json(['code' => 1, 'msg' => '删除失败']);
    }

    // 批量删除
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids');
        if (!$ids || !is_array($ids)) {
            return json(['code' => 1, 'msg' => 'ID列表为必填项']);
        }
        $res = Db::name('audio_novel_tag')->whereIn('id', $ids)->delete();
        if ($res) {
            return json(['code' => 0, 'msg' => '批量删除成功']);
        }
        return json(['code' => 1, 'msg' => '批量删除失败']);
    }

    // 批量设置状态
    public function batchSetStatus(Request $request)
    {
        $ids = $request->post('ids');
        $status = $request->post('status');
        if (!$ids || !is_array($ids) || $status === null) {
            return json(['code' => 1, 'msg' => 'ID列表和状态为必填项']);
        }
        $res = Db::name('audio_novel_tag')->whereIn('id', $ids)->update(['status' => $status, 'update_time' => date('Y-m-d H:i:s')]);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '批量设置状态成功']);
        }
        return json(['code' => 1, 'msg' => '批量设置状态失败']);
    }
}