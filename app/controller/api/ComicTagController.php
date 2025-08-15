<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db;
use think\Validate;

/**
 * 漫画标签控制器
 * 负责漫画标签的增删改查及状态管理
 */
class ComicTagController extends BaseController
{
    /**
     * 获取漫画标签列表
     * 路由: GET api/comic/tag/list
     * @return \think\response\Json
     */
    public function list()
    {
        $param = Request::param();

        $keyword = $param['keyword'] ?? '';
        $status = $param['status'] ?? null;
        $page = (int)($param['page'] ?? 1);
        $pageSize = (int)($param['page_size'] ?? 10);

        // 构建查询条件
        $where = [];
        if (!empty($keyword)) {
            $where[] = ['name', 'like', '%' . $keyword . '%'];
        }
        if ($status !== '' && $status !== null) {
            $where[] = ['status', '=', (int)$status];
        }

        try {
            $query = Db::name('comic_tags')->where($where);

            // 获取总数
            $total = $query->count();

            // 分页查询数据
            $list = $query->page($page, $pageSize)
                          ->order('sort', 'asc')
                          ->select()
                          ->toArray();

            // 为每个标签统计内容数量
            foreach ($list as &$item) {
                $tagId = $item['id'];
                
                // 统计包含当前标签ID的漫画数量
                // 查询 tags 字段包含当前标签ID的记录数
                $contentCount = Db::name('comic_manga')
                    ->where('tags', 'like', '%' . $tagId . '%')
                    ->count();
                
                $item['content_count'] = $contentCount;
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    "list" => $list,
                    "total" => $total
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取列表失败：' . $e->getMessage()]);
        }
    }

    /**
     * 添加漫画标签
     * 路由: POST api/comic/tag/add
     * @return \think\response\Json
     */
    public function add()
    {
        $data = Request::param();

        // 验证数据
        $validate = new Validate([
            'name|标签名称' => 'require|max:50|unique:comic_tags',
            'sort|排序' => 'integer',
            'status|状态' => 'in:0,1',
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }

        try {
            $insertData = [
                'name' => $data['name'],
                'sort' => $data['sort'] ?? 0,
                'status' => $data['status'] ?? 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $result = Db::name('comic_tags')->insert($insertData);

            if ($result) {
                return json(['code' => 0, 'msg' => '新增标签成功']);
            } else {
                return json(['code' => 1, 'msg' => '新增标签失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    /**
     * 更新漫画标签
     * 路由: POST api/comic/tag/update
     * @return \think\response\Json
     */
    public function update()
    {
        $data = Request::param();

        // 验证数据
        $validate = new Validate([
            'id|标签ID' => 'require|integer',
            'name|标签名称' => 'require|max:50|unique:comic_tags,name,' . $data['id'] . ',id',
            'sort|排序' => 'integer',
            'status|状态' => 'in:0,1',
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }

        $tagId = (int)$data['id'];

        // 检查标签是否存在
        $tag = Db::name('comic_tags')->where('id', $tagId)->find();
        if (!$tag) {
            return json(['code' => 1, 'msg' => '标签未找到']);
        }

        try {
            $updateData = [
                'name' => $data['name'],
                'sort' => $data['sort'] ?? $tag['sort'],
                'status' => $data['status'] ?? $tag['status'],
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $result = Db::name('comic_tags')->where('id', $tagId)->update($updateData);

            if ($result !== false) {
                return json(['code' => 0, 'msg' => '更新标签成功']);
            } else {
                return json(['code' => 1, 'msg' => '更新标签失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    /**
     * 删除漫画标签
     * 路由: POST api/comic/tag/delete
     * @return \think\response\Json
     */
    public function delete()
    {
        $param = Request::param();
        $tagId = $param['id'] ?? null;

        if (empty($tagId)) {
            return json(['code' => 1, 'msg' => 'ID为必填项']);
        }

        // 检查标签是否存在
        $tag = Db::name('comic_tags')->where('id', $tagId)->find();
        if (!$tag) {
            return json(['code' => 1, 'msg' => '标签未找到']);
        }

        try {
            $result = Db::name('comic_tags')->where('id', $tagId)->delete();

            if ($result) {
                return json(['code' => 0, 'msg' => '删除标签成功']);
            } else {
                return json(['code' => 1, 'msg' => '删除标签失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    /**
     * 批量删除漫画标签
     * 路由: POST api/comic/tag/batchDelete
     * @return \think\response\Json
     */
    public function batchDelete()
    {
        $param = Request::param();
        $ids = $param['ids'] ?? [];

        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => 'ID列表为必填项且必须是数组']);
        }

        try {
            $result = Db::name('comic_tags')->whereIn('id', $ids)->delete();

            if ($result) {
                return json(['code' => 0, 'msg' => '批量删除成功']);
            } else {
                return json(['code' => 1, 'msg' => '批量删除失败或没有找到要删除的标签']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    /**
     * 切换漫画标签状态
     * 路由: POST api/comic/tag/toggleStatus
     * @return \think\response\Json
     */
    public function toggleStatus()
    {
        $param = Request::param();
        $tagId = $param['id'] ?? null;

        if (empty($tagId)) {
            return json(['code' => 1, 'msg' => 'ID为必填项']);
        }

        // 检查标签是否存在
        $tag = Db::name('comic_tags')->where('id', $tagId)->find();
        if (!$tag) {
            return json(['code' => 1, 'msg' => '标签未找到']);
        }

        try {
            $newStatus = ($tag['status'] === 1) ? 0 : 1;
            $updateData = [
                'status' => $newStatus,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            $result = Db::name('comic_tags')->where('id', $tagId)->update($updateData);

            if ($result !== false) {
                return json(['code' => 0, 'msg' => '状态切换成功']);
            } else {
                return json(['code' => 1, 'msg' => '状态切换失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    /**
     * 批量设置漫画标签状态
     * 路由: POST api/comic/tag/batchSetStatus
     * @return \think\response\Json
     */
    public function batchSetStatus()
    {
        $param = Request::param();
        $ids = $param['ids'] ?? [];
        $status = $param['status'] ?? null;

        if (empty($ids) || !is_array($ids) || !isset($status) || ($status !== 0 && $status !== 1)) {
            return json(['code' => 1, 'msg' => 'ID列表和有效状态为必填项']);
        }

        try {
            $updateData = [
                'status' => (int)$status,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            $result = Db::name('comic_tags')
                ->whereIn('id', $ids)
                ->update($updateData);

            if ($result !== false) {
                return json(['code' => 0, 'msg' => '批量设置状态成功']);
            } else {
                return json(['code' => 1, 'msg' => '批量设置状态失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }
}