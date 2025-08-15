<?php
// File path: app/controller/api/OnlyFansTagController.php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use think\Validate;

class OnlyFansTagController
{
    /**
     * 获取标签列表
     * GET /api/onlyfans/tag/list
     */
    public function list(Request $request)
    {
        try {
            $keyword = $request->param('keyword', '');
            $status = $request->param('status', '');
            $page = max(1, (int)$request->param('page', 1));
            $pageSize = max(1, min(50, (int)$request->param('page_size', 20)));

            $query = Db::name('onlyfans_tags');

            // 关键词搜索
            if (!empty($keyword)) {
                $query->where('name', 'like', '%' . $keyword . '%');
            }

            // 状态筛选
            if ($status !== '') {
                $query->where('status', (int)$status);
            }

            // 获取总数
            $total = (clone $query)->count();

            // 获取列表 - 去掉color和use_count字段
            $list = $query
                ->field('id, name, status, sort, create_time, update_time')
                ->order('sort desc, id desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'list' => $list,
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize
                ]
            ]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取标签列表失败：' . $e->getMessage()]);
        }
    }

    /**
     * 新增标签
     * POST /api/onlyfans/tag/add
     */
    public function add(Request $request)
    {
        $data = $request->post();

        // 验证规则 - 去掉color相关验证
        $validate = new Validate([
            'name|标签名称' => 'require|max:50|unique:onlyfans_tags',
            'sort|排序' => 'integer|egt:0',
            'status|状态' => 'in:0,1'
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }

        try {
            // 去掉color和use_count字段
            $insertData = [
                'name' => trim($data['name']),
                'status' => $data['status'] ?? 1,
                'sort' => $data['sort'] ?? 0,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s')
            ];

            $id = Db::name('onlyfans_tags')->insertGetId($insertData);
            return $id ? json(['code' => 0, 'msg' => '标签添加成功', 'data' => ['id' => $id]])
                      : json(['code' => 1, 'msg' => '标签添加失败']);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '标签添加失败：' . $e->getMessage()]);
        }
    }

    /**
     * 更新标签
     * POST /api/onlyfans/tag/update
     */
    public function update(Request $request)
    {
        $data = $request->post();

        // 验证规则 - 去掉color相关验证
        $validate = new Validate([
            'id|标签ID' => 'require|integer|gt:0',
            'name|标签名称' => 'require|max:50',
            'sort|排序' => 'integer|egt:0',
            'status|状态' => 'in:0,1'
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }

        try {
            // 检查标签是否存在
            $tag = Db::name('onlyfans_tags')->where('id', $data['id'])->find();
            if (!$tag) {
                return json(['code' => 1, 'msg' => '标签不存在']);
            }

            // 检查名称是否重复（排除自己）
            $exists = Db::name('onlyfans_tags')
                ->where('name', trim($data['name']))
                ->where('id', '<>', $data['id'])
                ->find();
            if ($exists) {
                return json(['code' => 1, 'msg' => '标签名称已存在']);
            }

            // 去掉color字段处理
            $updateData = [
                'name' => trim($data['name']),
                'status' => $data['status'] ?? $tag['status'],
                'sort' => $data['sort'] ?? $tag['sort'],
                'update_time' => date('Y-m-d H:i:s')
            ];

            $result = Db::name('onlyfans_tags')->where('id', $data['id'])->update($updateData);
            
            return $result !== false ? json(['code' => 0, 'msg' => '标签更新成功'])
                                     : json(['code' => 1, 'msg' => '标签更新失败']);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '标签更新失败：' . $e->getMessage()]);
        }
    }

    /**
     * 获取所有启用的标签（供视频编辑时选择）
     * GET /api/onlyfans/tag/options
     */
    public function options(Request $request)
    {
        try {
            // 去掉color字段
            $tags = Db::name('onlyfans_tags')
                ->where('status', 1)
                ->field('id, name')
                ->order('sort desc, id desc')
                ->select()
                ->toArray();

            return json(['code' => 0, 'msg' => 'success', 'data' => $tags]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取标签选项失败：' . $e->getMessage()]);
        }
    }

    /**
     * 删除标签
     * POST /api/onlyfans/tag/delete
     */
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => '标签ID不能为空']);
        }

        try {
            // 检查是否有视频正在使用此标签
            $usedCount = Db::name('onlyfans_media')
                ->where('type', 'video')
                ->where('tag_ids', 'like', '%' . $id . '%')
                ->count();

            if ($usedCount > 0) {
                return json(['code' => 1, 'msg' => "该标签正在被 {$usedCount} 个视频使用，无法删除"]);
            }

            $result = Db::name('onlyfans_tags')->where('id', $id)->delete();
            return $result ? json(['code' => 0, 'msg' => '标签删除成功'])
                           : json(['code' => 1, 'msg' => '标签删除失败或不存在']);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '标签删除失败：' . $e->getMessage()]);
        }
    }

    /**
     * 切换标签状态
     * POST /api/onlyfans/tag/toggle-status
     */
    public function toggleStatus(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => '标签ID不能为空']);
        }

        try {
            $tag = Db::name('onlyfans_tags')->where('id', $id)->find();
            if (!$tag) {
                return json(['code' => 1, 'msg' => '标签不存在']);
            }

            $newStatus = $tag['status'] == 1 ? 0 : 1;
            $result = Db::name('onlyfans_tags')
                ->where('id', $id)
                ->update([
                    'status' => $newStatus,
                    'update_time' => date('Y-m-d H:i:s')
                ]);

            if ($result !== false) {
                $statusText = $newStatus ? '启用' : '禁用';
                return json(['code' => 0, 'msg' => "标签{$statusText}成功", 'data' => ['status' => $newStatus]]);
            } else {
                return json(['code' => 1, 'msg' => '状态切换失败']);
            }

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '状态切换失败：' . $e->getMessage()]);
        }
    }

    /**
     * 批量删除标签
     * POST /api/onlyfans/tag/batch-delete
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '请选择要删除的标签']);
        }

        try {
            // 检查是否有视频正在使用这些标签
            $usedCount = 0;
            foreach ($ids as $id) {
                $count = Db::name('onlyfans_media')
                    ->where('type', 'video')
                    ->where('tag_ids', 'like', '%' . $id . '%')
                    ->count();
                $usedCount += $count;
            }

            if ($usedCount > 0) {
                return json(['code' => 1, 'msg' => "选中的标签正在被 {$usedCount} 个视频使用，无法删除"]);
            }

            $count = Db::name('onlyfans_tags')->whereIn('id', $ids)->delete();
            return $count > 0 ? json(['code' => 0, 'msg' => "批量删除成功，共删除 {$count} 个标签"])
                              : json(['code' => 1, 'msg' => '批量删除失败']);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '批量删除失败：' . $e->getMessage()]);
        }
    }
}
