<?php
// 文件路径: app/controller/api/OnlyFansCategoryController.php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use think\Validate;

class OnlyFansCategoryController
{
    /**
     * 获取分类列表 (一级分类和对应的博主)
     * 返回格式: { 
     *   code: 0, 
     *   data: { 
     *     categories: [...],  // 一级分类列表
     *     creators: [...]     // 博主列表(可按分类筛选)
     *   } 
     * }
     */
    public function list(Request $request)
    {
        try {
            // 获取所有一级分类
            $categories = Db::name('onlyfans_categories')
                ->where('status', 1)
                ->field('id, name, sort, status') // 只查询需要的字段，去掉 intro
                ->order('sort asc, id asc')
                ->select()
                ->toArray();

            // 获取筛选参数
            $categoryId = $request->param('category_id', '');
            $keyword = $request->param('keyword', '');
            
            // 构建博主查询条件
            $creatorWhere = [['status', '=', 1]];
            
            if (!empty($categoryId)) {
                $creatorWhere[] = ['category_id', '=', $categoryId];
            }
            
            if (!empty($keyword)) {
                $creatorWhere[] = ['name', 'like', '%' . $keyword . '%'];
            }

            // 获取博主列表
            $creators = Db::name('onlyfans_creators')
                ->where($creatorWhere)
                ->field('id, name, avatar, category_id, intro, media_count, fans_count, create_time')
                ->order('sort desc, id desc')
                ->select()
                ->toArray();

            // 补全头像URL
            $domain = rtrim(request()->domain(), '/');
            foreach ($creators as &$creator) {
                if (!empty($creator['avatar']) && !preg_match('/^https?:\/\//', $creator['avatar'])) {
                    if ($creator['avatar'][0] !== '/') {
                        $creator['avatar'] = '/' . $creator['avatar'];
                    }
                    $creator['avatar'] = $domain . $creator['avatar'];
                }
                
                // 补充统计信息
                $creator['media_count'] = $creator['media_count'] ?? 0;
                $creator['fans_count'] = $creator['fans_count'] ?? 0;
            }
            unset($creator);

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'categories' => $categories,
                    'creators' => $creators
                ]
            ]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取分类列表失败：' . $e->getMessage()]);
        }
    }

    /**
     * 获取博主详情及其内容列表
     */
    public function creatorDetail(Request $request)
    {
        $creatorId = $request->param('creator_id');
        $contentType = $request->param('content_type', 'all'); // all, image, video
        $page = max(1, (int)$request->param('page', 1));
        $pageSize = max(1, min(50, (int)$request->param('page_size', 12)));

        if (empty($creatorId)) {
            return json(['code' => 1, 'msg' => '博主ID不能为空']);
        }

        try {
            // 获取博主信息
            $creator = Db::name('onlyfans_creators')
                ->where('id', $creatorId)
                ->where('status', 1)
                ->find();

            if (!$creator) {
                return json(['code' => 1, 'msg' => '博主不存在']);
            }

            // 补全头像URL
            $domain = rtrim(request()->domain(), '/');
            if (!empty($creator['avatar']) && !preg_match('/^https?:\/\//', $creator['avatar'])) {
                if ($creator['avatar'][0] !== '/') {
                    $creator['avatar'] = '/' . $creator['avatar'];
                }
                $creator['avatar'] = $domain . $creator['avatar'];
            }

            // 构建内容查询条件
            $mediaWhere = [
                ['creator_id', '=', $creatorId],
                ['status', '=', 1]
            ];

            if ($contentType === 'image') {
                $mediaWhere[] = ['type', '=', 'image'];
            } elseif ($contentType === 'video') {
                $mediaWhere[] = ['type', '=', 'video'];
            }

            // 获取内容列表
            $mediaQuery = Db::name('onlyfans_media')->where($mediaWhere);
            $total = $mediaQuery->count();
            
            $mediaList = $mediaQuery
                ->field('id, title, cover, type, is_vip, coin, view_count, like_count, create_time')
                ->order('create_time desc, id desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            // 补全封面URL
            foreach ($mediaList as &$media) {
                if (!empty($media['cover']) && !preg_match('/^https?:\/\//', $media['cover'])) {
                    if ($media['cover'][0] !== '/') {
                        $media['cover'] = '/' . $media['cover'];
                    }
                    $media['cover'] = $domain . $media['cover'];
                }
            }
            unset($media);

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'creator' => $creator,
                    'media_list' => $mediaList,
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize
                ]
            ]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取博主详情失败：' . $e->getMessage()]);
        }
    }

    /**
     * 新增一级分类
     */
    public function add(Request $request)
    {
        $data = $request->post();

        $validate = new Validate([
            'name|分类名称' => 'require|max:50|unique:onlyfans_categories',
            'intro|分类简介' => 'max:200',
            'sort|排序' => 'integer|egt:0',
            'status|状态' => 'in:0,1'
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }

        try {
            // 设置默认值
            if (!isset($data['sort']) || !$data['sort']) {
                $maxSort = Db::name('onlyfans_categories')->max('sort');
                $data['sort'] = $maxSort ? ($maxSort + 1) : 1;
            }

            $data['status'] = $data['status'] ?? 1;
            $data['create_time'] = date('Y-m-d H:i:s');
            $data['update_time'] = date('Y-m-d H:i:s');

            $id = Db::name('onlyfans_categories')->insertGetId($data);
            
            if (!$id) {
                return json(['code' => 1, 'msg' => '新增分类失败']);
            }

            return json(['code' => 0, 'msg' => '新增分类成功', 'data' => ['id' => $id]]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '新增分类失败：' . $e->getMessage()]);
        }
    }

    /**
     * 更新分类
     */
    public function update(Request $request)
    {
        $data = $request->post();

        $validate = new Validate([
            'id|分类ID' => 'require|integer|gt:0',
            'name|分类名称' => 'require|max:50',
            'intro|分类简介' => 'max:200',
            'sort|排序' => 'integer|egt:0',
            'status|状态' => 'in:0,1'
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }

        try {
            // 检查分类是否存在
            $category = Db::name('onlyfans_categories')->where('id', $data['id'])->find();
            if (!$category) {
                return json(['code' => 1, 'msg' => '分类不存在']);
            }

            // 检查名称唯一性
            $exists = Db::name('onlyfans_categories')
                ->where('name', $data['name'])
                ->where('id', '<>', $data['id'])
                ->find();
            
            if ($exists) {
                return json(['code' => 1, 'msg' => '分类名称已存在']);
            }

            $data['update_time'] = date('Y-m-d H:i:s');
            
            $result = Db::name('onlyfans_categories')
                ->where('id', $data['id'])
                ->update($data);

            if ($result === false) {
                return json(['code' => 1, 'msg' => '更新分类失败']);
            }

            return json(['code' => 0, 'msg' => '更新分类成功']);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '更新分类失败：' . $e->getMessage()]);
        }
    }

    /**
     * 删除分类
     */
    public function delete(Request $request)
    {
        $id = $request->post('id');
        
        if (!$id) {
            return json(['code' => 1, 'msg' => '分类ID不能为空']);
        }

        try {
            // 检查分类是否存在
            $category = Db::name('onlyfans_categories')->where('id', $id)->find();
            if (!$category) {
                return json(['code' => 1, 'msg' => '分类不存在']);
            }

            // 检查是否有博主关联此分类
            $hasCreators = Db::name('onlyfans_creators')
                ->where('category_id', $id)
                ->count();
            
            if ($hasCreators > 0) {
                return json(['code' => 1, 'msg' => '该分类下存在博主，请先处理相关博主']);
            }

            $result = Db::name('onlyfans_categories')
                ->where('id', $id)
                ->delete();

            if (!$result) {
                return json(['code' => 1, 'msg' => '删除分类失败']);
            }

            return json(['code' => 0, 'msg' => '删除分类成功']);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '删除分类失败：' . $e->getMessage()]);
        }
    }

    /**
     * 批量删除分类
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        try {
            Db::startTrans();

            // 检查是否有博主关联这些分类
            $hasCreators = Db::name('onlyfans_creators')
                ->whereIn('category_id', $ids)
                ->count();
            
            if ($hasCreators > 0) {
                throw new \Exception('选择的分类中存在关联博主，请先处理相关博主');
            }

            $count = Db::name('onlyfans_categories')
                ->whereIn('id', $ids)
                ->delete();

            if (!$count) {
                throw new \Exception('批量删除失败');
            }

            Db::commit();
            return json(['code' => 0, 'msg' => "批量删除成功，共删除{$count}条"]);

        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 批量更新排序
     */
    public function batchUpdateSort(Request $request)
    {
        $list = $request->post('list', []);
        
        if (empty($list) || !is_array($list)) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        try {
            Db::startTrans();
            
            foreach ($list as $item) {
                if (isset($item['id']) && isset($item['sort'])) {
                    Db::name('onlyfans_categories')
                        ->where('id', $item['id'])
                        ->update([
                            'sort' => intval($item['sort']),
                            'update_time' => date('Y-m-d H:i:s')
                        ]);
                }
            }
            
            Db::commit();
            return json(['code' => 0, 'msg' => '排序更新成功']);
            
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '排序更新失败：' . $e->getMessage()]);
        }
    }
}
