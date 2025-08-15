<?php
// 文件路径: E:\ThinkPHP6\app\controller\api\DouyinTagController.php
namespace app\controller\api;

use think\Request; // 统一使用 think\Request
use think\facade\Db;

// 注意: 如果 BaseController 中没有定义 successJson 和 errorJson，
// 需要确保这些辅助函数在全局可用，或者在此文件中重新定义。
// 这里假设它们在全局或 BaseController 中已定义。
// class DouyinTagController extends BaseController // 如果BaseController提供了通用的json返回，可以继承
class DouyinTagController // 如果没有BaseController，则不继承
{
    /**
     * 标签列表（支持关键词、分组、状态筛选及分页）
     * @param Request $request GET参数: page, pageSize, keyword, group, status
     * @return \think\response\Json
     */
    public function list(Request $request)
    {
        $page = $request->get('page', 1);
        $pageSize = $request->get('pageSize', 10);
        $keyword = $request->get('keyword', '');
        $group = $request->get('group', ''); // group 可以是空字符串
        $status = $request->get('status', ''); // status 可以是空字符串

        $query = Db::name('douyin_tags');

        if ($keyword) {
            $query->where(function($q) use ($keyword){
                $q->whereLike('name', "%$keyword%")->whereOrLike('alias', "%$keyword%");
            });
        }
        if ($group !== '') { // 只有当 group 不是空字符串时才作为筛选条件
            $query->where('group', $group);
        }
        if ($status !== '') { // 只有当 status 不是空字符串时才作为筛选条件
            $query->where('status', intval($status)); // 确保状态是整数
        }

        $total = $query->count(); // 获取总数
        $tags = $query->page($page, $pageSize) // 加入分页
                      ->order('sort asc, id asc')
                      ->select()
                      ->toArray();

        // 自动转int
        foreach ($tags as &$t) {
            $t['status'] = intval($t['status']);
            $t['sort'] = intval($t['sort']);
            $t['count'] = intval($t['count'] ?? 0);
        }
        // 返回包含 list 和 total 的数据结构以支持前端分页
        return successJson([
            'list' => $tags,
            'total' => $total
        ]);
    }

    /**
     * 新增标签
     * @param Request $request POST数据
     * @return \think\response\Json
     */
    public function add(Request $request)
    {
        $data = $request->post();
        if (empty($data['name'])) {
            return errorJson('标签名不能为空');
        }
        // 检查标签名是否已存在
        $existingTag = Db::name('douyin_tags')->where('name', $data['name'])->find();
        if ($existingTag) {
            return errorJson('标签名已存在');
        }

        $insert = [
            'name'          => $data['name'],
            'alias'         => $data['alias'] ?? '',
            'group'         => $data['group'] ?? '',
            'desc'          => $data['desc'] ?? '',
            'status'        => isset($data['status']) ? intval($data['status']) : 1,
            'sort'          => intval($data['sort'] ?? 0),
            'count'         => 0, // 新增标签内容数默认为0
            'create_time'   => date('Y-m-d H:i:s'),
            'update_time'   => date('Y-m-d H:i:s'),
        ];
        $id = Db::name('douyin_tags')->insertGetId($insert);
        return $id ? successJson(['id' => $id], '添加成功') : errorJson('添加失败');
    }

    /**
     * 编辑标签
     * @param Request $request POST数据
     * @return \think\response\Json
     */
    public function update(Request $request)
    {
        $data = $request->post();
        if (empty($data['id'])) {
            return errorJson('缺少ID');
        }
        if (empty($data['name'])) {
            return errorJson('标签名不能为空');
        }
        // 检查标签名是否与其他标签冲突（排除自身）
        $existingTag = Db::name('douyin_tags')
                         ->where('name', $data['name'])
                         ->where('id', '<>', $data['id'])
                         ->find();
        if ($existingTag) {
            return errorJson('标签名已存在');
        }

        $update = [
            'name'          => $data['name'],
            'alias'         => $data['alias'] ?? '',
            'group'         => $data['group'] ?? '',
            'desc'          => $data['desc'] ?? '',
            'status'        => isset($data['status']) ? intval($data['status']) : 1,
            'sort'          => intval($data['sort'] ?? 0),
            'update_time'   => date('Y-m-d H:i:s'),
        ];
        $ret = Db::name('douyin_tags')->where('id', $data['id'])->update($update);
        return $ret !== false ? successJson([], '编辑成功') : errorJson('编辑失败');
    }

    /**
     * 删除单个标签
     * @param Request $request POST数据
     * @return \think\response\Json
     */
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (empty($id)) {
            return errorJson('缺少ID');
        }
        // 检查是否有内容关联此标签 (假设douyin_videos表有tags字段)
        // 注意：这里需要检查douyin_videos的tags字段，可能为JSON或逗号分隔字符串
        // 示例如下，如果tags是JSON，你需要做json_contains
        // 如果是逗号分隔，你需要做 LIKE '%,"tag_name",%' 或 FIND_IN_SET
        $relatedCount = Db::name('douyin_videos')
                        ->where(function($query) use ($id) {
                            // 假设标签名存储在 douyin_tags 中
                            $tagName = Db::name('douyin_tags')->where('id', $id)->value('name');
                            if ($tagName) {
                                // 假设 douyin_videos.tags 字段存储的是 JSON 数组字符串
                                $query->whereRaw("JSON_CONTAINS(tags, '\"{$tagName}\"')");
                                // 如果是逗号分隔字符串，则:
                                // $query->whereRaw("FIND_IN_SET('{$tagName}', tags)");
                            }
                        })
                        ->count();
        if ($relatedCount > 0) {
            return errorJson('该标签下存在关联内容，请先处理内容');
        }


        $ret = Db::name('douyin_tags')->where('id', $id)->delete();
        return $ret ? successJson([], '删除成功') : errorJson('删除失败');
    }

    /**
     * 批量删除标签
     * @param Request $request POST数据
     * @return \think\response\Json
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids');
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少ids');
        }

        // 检查是否有内容关联这些标签
        $relatedTags = Db::name('douyin_tags')->whereIn('id', $ids)->column('name');
        if (!empty($relatedTags)) {
            $hasRelatedContent = false;
            foreach ($relatedTags as $tagName) {
                $count = Db::name('douyin_videos')
                           ->whereRaw("JSON_CONTAINS(tags, '\"{$tagName}\"')") // 假设 douyin_videos.tags 是 JSON 数组
                           // 如果是逗号分隔字符串: ->whereRaw("FIND_IN_SET('{$tagName}', tags)")
                           ->count();
                if ($count > 0) {
                    $hasRelatedContent = true;
                    break;
                }
            }
            if ($hasRelatedContent) {
                return errorJson('选择的标签中存在关联内容，请先处理内容');
            }
        }
        
        $count = Db::name('douyin_tags')->whereIn('id', $ids)->delete();
        return $count ? successJson(['count' => $count], '批量删除成功') : errorJson('批量删除失败');
    }

    /**
     * 批量禁用标签 (或批量启用)
     * @param Request $request POST数据
     * @return \think\response\Json
     */
    public function batchDisable(Request $request)
    {
        $ids = $request->post('ids');
        $status = $request->post('status', 0); // 批量禁用默认 status 为 0
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少ids');
        }
        // 前端 batchDisableTags 调用时没有传 status，所以这里默认禁用
        // 如果需要批量启用，前端可以调用另一个接口或在参数中明确传 status = 1

        $count = Db::name('douyin_tags')->whereIn('id', $ids)->update(['status' => intval($status), 'update_time' => date('Y-m-d H:i:s')]);
        return $count !== false ? successJson(['count' => $count], '批量操作成功') : errorJson('批量操作失败');
    }

    /**
     * 状态切换（单个标签启用/禁用）
     * @param Request $request POST数据
     * @return \think\response\Json
     */
    public function toggleStatus(Request $request)
    {
        $id = $request->post('id');
        if (empty($id)) {
            return errorJson('缺少ID');
        }
        $row = Db::name('douyin_tags')->where('id', $id)->find();
        if (!$row) {
            return errorJson('未找到该标签');
        }
        $newStatus = $row['status'] == 1 ? 0 : 1; // 切换状态
        $ret = Db::name('douyin_tags')->where('id', $id)->update(['status' => $newStatus, 'update_time' => date('Y-m-d H:i:s')]);
        return $ret !== false ? successJson(['status' => $newStatus], '状态切换成功') : errorJson('状态切换失败');
    }

    /**
     * 获取所有标签（发现页用，不分页）
     * @param Request $request
     * @return \think\response\Json
     */
    public function all(Request $request)
    {
        $tags = Db::name('douyin_tags')
            ->where('status', 1) // 只取启用标签
            ->order('sort asc, id asc')
            ->column('name'); // 只返回标签名数组

        return successJson(['list' => $tags]);
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
