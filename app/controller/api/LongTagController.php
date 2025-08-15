<?php
// 文件路径: E:\ThinkPHP6\app\controller\api\LongTagController.php
namespace app\controller\api;

use think\Request;
use think\facade\Db;

// 确保 successJson 和 errorJson 辅助函数在全局可用
// class LongTagController extends BaseController // 如果有BaseController，可以继承
class LongTagController
{
    /**
     * 标签列表（支持关键词、分组、状态等筛选，带分页）
     * @param Request $request GET参数: page, pageSize, keyword, group, status
     * @return \think\response\Json
     */
    public function list(Request $request)
    {
        $page = max(1, intval($request->param('page', 1)));
        $pageSize = intval($request->param('pageSize', 10));
        $keyword = $request->param('keyword', '');
        $group = $request->param('group', '');
        $status = $request->param('status', '');
        $type = $request->param('type', 'long'); // 新增type参数，默认long

        // 根据type选择表
        $table = $type === 'darknet' ? 'darknet_tag' : 'long_video_tags';

        $query = Db::name($table);

        // 构建查询条件
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', '%' . $keyword . '%')
                  ->whereOr('alias', 'like', '%' . $keyword . '%');
            });
        }
        if ($group !== '') {
            $query->where('group', '=', $group);
        }
        if ($status !== '') {
            $query->where('status', '=', intval($status));
        }

        $total = $query->count();
        $list = $query->order('sort asc, id desc')
                       ->page($page, $pageSize)
                       ->select()
                       ->toArray();

        // 统计内容数
        foreach ($list as &$item) {
            $tagName = $item['name'] ?? '';
            if ($tagName !== '' && $tagName !== null) {
                $videoTable = $type === 'darknet' ? 'darknet_video' : 'long_videos';
                $item['count'] = Db::name($videoTable)
                    ->whereRaw("tags IS NOT NULL AND tags <> '' AND JSON_VALID(tags)")
                    ->whereRaw("JSON_CONTAINS(tags, ?) = 1", [json_encode($tagName, JSON_UNESCAPED_UNICODE)])
                    ->count();
            } else {
                $item['count'] = 0;
            }
            $item['status'] = (int)$item['status'];
            // 字段统一，只保留这些
            $item = [
                'id' => $item['id'],
                'name' => $item['name'],
                'sort' => $item['sort'],
                'status' => $item['status'],
                'count' => $item['count'],
                'create_time' => $item['create_time'],
                'update_time' => $item['update_time'],
            ];
        }
        unset($item);

        return successJson([
            'list'  => $list,
            'total' => $total
        ]);
    }

    /**
     * 新增标签
     * @param Request $request POST数据: name, alias, group, desc, status, sort
     * @return \think\response\Json
     */
    public function add(Request $request)
    {
        $data = $request->only(['name', 'alias', 'group', 'desc', 'status', 'sort']);

        if (empty($data['name'])) {
            return errorJson('标签名必填');
        }
        $data['name'] = trim($data['name']);

        // 检查标签名是否已存在
        // 修正表名：long_tags -> long_video_tags
        $exists = Db::name('long_video_tags')->where('name', $data['name'])->find();
        if ($exists) {
            return errorJson('该标签名已存在');
        }

        $data['status'] = isset($data['status']) ? intval($data['status']) : 1; // 默认启用
        $data['sort'] = intval($data['sort'] ?? 0); // 默认排序为0
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');

        // 修正表名：long_tags -> long_video_tags
        $id = Db::name('long_video_tags')->insertGetId($data);
        return $id ? successJson(['id' => $id], '新增成功') : errorJson('添加失败');
    }

    /**
     * 编辑标签
     * @param Request $request POST数据: id, name, alias, group, desc, status, sort
     * @return \think\response\Json
     */
    public function update(Request $request)
    {
        $data = $request->only(['id', 'name', 'alias', 'group', 'desc', 'status', 'sort']);

        if (empty($data['id']) || empty($data['name'])) {
            return errorJson('ID或标签名必填');
        }
        $data['name'] = trim($data['name']);

        // 检查标签名是否已存在 (排除自身)
        // 修正表名：long_tags -> long_video_tags
        $exists = Db::name('long_video_tags')
                     ->where('name', $data['name'])
                     ->where('id', '<>', $data['id'])
                     ->find();
        if ($exists) {
            return errorJson('该标签名已存在');
        }

        $data['status'] = isset($data['status']) ? intval($data['status']) : 1;
        $data['sort'] = intval($data['sort'] ?? 0);
        $data['update_time'] = date('Y-m-d H:i:s');

        // 修正表名：long_tags -> long_video_tags
        $ret = Db::name('long_video_tags')->where('id', $data['id'])->update($data);
        return $ret !== false ? successJson([], '更新成功') : errorJson('更新失败');
    }

    /**
     * 删除标签 (单个)
     * @param Request $request POST数据: id
     * @return \think\response\Json
     */
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (empty($id)) {
            return errorJson('缺少ID');
        }
        
        // 检查是否有内容关联此标签，并给出提示或选择是否删除关联
        // 这里只是删除标签本身，不会自动删除或修改关联的视频。
        // 如果需要级联操作，需要额外实现。
        // 修正表名：long_tags -> long_video_tags
        $ret = Db::name('long_video_tags')->where('id', $id)->delete();
        return $ret ? successJson([], '删除成功') : errorJson('删除失败');
    }

    /**
     * 批量删除标签
     * @param Request $request POST数据: ids[]
     * @return \think\response\Json
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少ID或参数格式错误');
        }
        
        // 修正表名：long_tags -> long_video_tags
        $count = Db::name('long_video_tags')->whereIn('id', $ids)->delete();
        return $count ? successJson(['count' => $count], "批量删除成功，共删除{$count}条") : errorJson('批量删除失败');
    }

    /**
     * 批量禁用标签
     * @param Request $request POST数据: ids[], status (0:禁用, 1:启用，默认为0)
     * @return \think\response\Json
     */
    public function batchDisable(Request $request)
    {
        $ids = $request->post('ids', []);
        $status = intval($request->post('status', 0)); // 默认为禁用 (0)
        
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少ID或参数格式错误');
        }
        
        // 修正表名：long_tags -> long_video_tags
        $count = Db::name('long_video_tags')->whereIn('id', $ids)->update([
            'status' => $status,
            'update_time' => date('Y-m-d H:i:s')
        ]);
        return $count !== false ? successJson(['count' => $count], "批量操作成功，共更新{$count}条") : errorJson('批量操作失败');
    }

    /**
     * 启用/禁用标签 (单个)
     * @param Request $request POST数据: id, [status]
     * @return \think\response\Json
     */
    public function toggleStatus(Request $request)
    {
        $id = $request->post('id');
        $explicitStatus = $request->post('status'); // 可能传入明确的状态值
        
        if (empty($id)) {
            return errorJson('缺少ID');
        }

        // 修正表名：long_tags -> long_video_tags
        $tag = Db::name('long_video_tags')->where('id', $id)->find();
        if (!$tag) {
            return errorJson('标签不存在');
        }

        $newStatus = ($tag['status'] == 1) ? 0 : 1; // 默认切换状态
        if (isset($explicitStatus) && is_numeric($explicitStatus)) {
            $newStatus = intval($explicitStatus); // 如果传入了明确的状态，则使用它
        }
        
        // 修正表名：long_tags -> long_video_tags
        $ret = Db::name('long_video_tags')->where('id', $id)->update([
            'status' => $newStatus,
            'update_time' => date('Y-m-d H:i:s')
        ]);
        return $ret !== false ? successJson(['newStatus' => $newStatus], '状态切换成功') : errorJson('状态切换失败');
    }

    /**
     * 标签详情（单条）
     * @param Request $request GET参数: id
     * @return \think\response\Json
     */
    public function info(Request $request)
    {
        $id = $request->get('id');
        if (empty($id)) {
            return errorJson('缺少ID');
        }
        // 修正表名：long_tags -> long_video_tags
        $info = Db::name('long_video_tags')->where('id', $id)->find();
        if (!$info) {
            return errorJson('标签不存在');
        }
        // 确保状态是整数
        $info['status'] = (int)$info['status'];
        return successJson($info);
    }
}

// 辅助函数定义 (如果你的项目中没有全局定义的话，请确保这些函数在你的项目中是可用的)
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
