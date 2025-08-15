<?php
// 文件路径: E:\ThinkPHP6\app\controller\api\WeimiTagController.php
namespace app\controller\api;

use think\Request;
use think\facade\Db;

class WeimiTagController
{
    /**
     * 获取微密圈标签列表
     * 支持关键词搜索、按博主ID、主分类ID、子分类ID筛选、状态筛选
     * @param Request $request
     * @return \think\response\Json
     */
    public function list(Request $request)
    {
        try {
            $keyword = $request->param('keyword', ''); // 标签名/别名/拼音
            $authorId = $request->param('author', '');   // 所属博主ID
            $parentId = $request->param('parent_id', ''); // 主分类ID
            $childId = $request->param('child_id', '');  // 子分类ID
            $status = $request->param('status', '');     // 状态 (1启用, 0禁用)
            
            $query = Db::name('weimi_tags'); // *** 确保你的数据库存在 'weimi_tags' 表 ***
            // 字段至少包含: id, name, author_id, parent_id, child_id, status, sort, create_time, update_time

            if (!empty($keyword)) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('name', 'like', '%' . $keyword . '%')
                      ->orWhere('alias', 'like', '%' . $keyword . '%') // 假设有 alias 字段
                      ->orWhere('pinyin', 'like', '%' . $keyword . '%'); // 假设有 pinyin 字段
                });
            }
            if (!empty($authorId)) {
                $query->where('author_id', $authorId);
            }
            if (!empty($parentId)) {
                $query->where('parent_id', $parentId);
            }
            if (!empty($childId)) {
                $query->where('child_id', $childId);
            }
            if ($status !== '') {
                $query->where('status', intval($status));
            }

            $tags = $query->order('sort asc, id desc')->select()->toArray();

            // 如果前端需要显示博主/主分类/子分类名称，需要关联查询或预加载数据
            // 这里为了简化，假设前端会根据ID自行查找名称，或者后端后续加入关联查询
            $authorNames = [];
            $parentCategoryNames = [];
            $childCategoryNames = [];

            // 批量获取相关分类名称
            $relatedCategoryIds = array_unique(array_merge(
                array_column($tags, 'author_id'),
                array_column($tags, 'parent_id'),
                array_column($tags, 'child_id')
            ));
            $relatedCategoryIds = array_filter($relatedCategoryIds); // 过滤掉空值

            if (!empty($relatedCategoryIds)) {
                $relatedCategories = Db::name('weimi_categories')->whereIn('id', $relatedCategoryIds)->select()->toArray();
                foreach ($relatedCategories as $cat) {
                    // 假设 author_id 对应一级分类 (博主)
                    // parent_id 对应主分类 (也是一级分类)
                    // child_id 对应子分类
                    // 你可能需要更精确的逻辑来区分这些ID对应的实际分类类型
                    if ($cat['parent_id'] == 0) { // 假定 parent_id 为 0 的是一级分类
                        $authorNames[$cat['id']] = $cat['name'];
                        $parentCategoryNames[$cat['id']] = $cat['name'];
                    } else { // 否则是子分类
                        $childCategoryNames[$cat['id']] = $cat['name'];
                    }
                }
            }
            
            foreach ($tags as &$tag) {
                $tag['author_name'] = $authorNames[$tag['author_id']] ?? '--';
                $tag['parent_name'] = $parentCategoryNames[$tag['parent_id']] ?? '--';
                $tag['child_name'] = $childCategoryNames[$tag['child_id']] ?? '--';
            }

            return successJson($tags);

        } catch (\Exception $e) {
            return errorJson('获取微密圈标签列表失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 新增微密圈标签
     * @param Request $request POST数据包含 name, author_id, parent_id, child_id, status, sort
     * @return \think\response\Json
     */
    public function add(Request $request)
    {
        $data = $request->post();

        if (empty($data['name'])) {
            return errorJson('标签名不能为空');
        }
        if (empty($data['author_id'])) {
            return errorJson('请选择所属博主');
        }
        if (empty($data['parent_id'])) {
            return errorJson('请选择主分类');
        }
        if (empty($data['child_id'])) {
            return errorJson('请选择子分类');
        }

        $data['status'] = $data['status'] ?? 1; // 默认为启用
        $data['sort'] = $data['sort'] ?? 0; // 默认为0
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');

        $id = Db::name('weimi_tags')->insertGetId($data); // *** 确保操作 'weimi_tags' 表 ***
        return $id ? successJson(['id' => $id]) : errorJson('新增标签失败');
    }

    /**
     * 编辑微密圈标签
     * @param Request $request POST数据包含 id, name, author_id, parent_id, child_id, status, sort
     * @return \think\response\Json
     */
    public function update(Request $request)
    {
        $data = $request->post();
        if (empty($data['id'])) {
            return errorJson('缺少标签ID');
        }

        $data['update_time'] = date('Y-m-d H:i:s');

        $ret = Db::name('weimi_tags')->where('id', $data['id'])->update($data);
        return $ret !== false ? successJson() : errorJson('更新标签失败');
    }

    /**
     * 删除微密圈标签
     * @param Request $request POST数据包含 id
     * @return \think\response\Json
     */
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return errorJson('缺少标签ID');
        }

        // 检查是否有图片关联此标签
        // 如果 weimi_images 表的 tags 字段是 JSON 数组，查询会复杂一些
        // 示例：查询包含某个标签ID的图片
        $hasImages = Db::name('weimi_images')->where('tag_ids', 'like', '%"'.$id.'"%')->count();
        if ($hasImages > 0) {
            return errorJson('该标签下存在关联图片，请先处理图片');
        }

        $ret = Db::name('weimi_tags')->where('id', $id)->delete();
        return $ret ? successJson() : errorJson('删除标签失败');
    }

    /**
     * 批量删除微密圈标签
     * @param Request $request POST数据包含 ids: []
     * @return \think\response\Json
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少标签ID或参数格式错误');
        }

        // 检查是否有图片关联这些标签 (简化处理)
        foreach ($ids as $id) {
            $hasImages = Db::name('weimi_images')->where('tag_ids', 'like', '%"'.$id.'"%')->count();
            if ($hasImages > 0) {
                return errorJson('选择的标签中存在关联图片，请先处理图片');
            }
        }

        $count = Db::name('weimi_tags')->whereIn('id', $ids)->delete();
        return $count ? successJson(['count' => $count], "批量删除成功，共删除{$count}条") : errorJson('批量删除失败');
    }

    /**
     * 批量禁用微密圈标签
     * @param Request $request POST数据包含 ids: []
     * @return \think\response\Json
     */
    public function batchDisable(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少标签ID或参数格式错误');
        }
        $count = Db::name('weimi_tags')->whereIn('id', $ids)->update(['status' => 0, 'update_time' => date('Y-m-d H:i:s')]);
        return $count ? successJson(['count' => $count], "批量禁用成功，共禁用{$count}条") : errorJson('批量禁用失败');
    }

    /**
     * 切换标签状态 (单个启用/禁用)
     * @param Request $request POST数据包含 id
     * @return \think\response\Json
     */
    public function toggleStatus(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return errorJson('缺少标签ID');
        }
        $tag = Db::name('weimi_tags')->where('id', $id)->find();
        if (!$tag) {
            return errorJson('标签不存在');
        }
        $newStatus = $tag['status'] == 1 ? 0 : 1;
        $ret = Db::name('weimi_tags')->where('id', $id)->update(['status' => $newStatus, 'update_time' => date('Y-m-d H:i:s')]);
        return $ret !== false ? successJson(['status' => $newStatus]) : errorJson('切换状态失败');
    }

    // 辅助函数定义 (如果你的项目中没有全局定义的话)
    // 确保这些函数在你的项目中是可用的
}

// 辅助函数定义 (如果你的项目中没有全局定义的话)
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
