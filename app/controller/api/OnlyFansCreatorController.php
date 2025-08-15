<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use think\Validate;

class OnlyFansCreatorController
{
    /**
     * 获取博主列表
     */
    public function list(Request $request)
    {
        try {
            $param = $request->param();
            
            // 分页参数
            $page = max(1, (int)($param['page'] ?? 1));
            $pageSize = max(1, min(50, (int)($param['pageSize'] ?? 15)));
            
            // 筛选条件
            $where = [['status', '>=', 0]]; // 包括禁用的博主
            
            // 分类筛选
            if (!empty($param['category_id'])) {
                $where[] = ['category_id', '=', $param['category_id']];
            }
            
            // 关键词搜索
            if (!empty($param['keyword'])) {
                $where[] = ['name', 'like', '%' . $param['keyword'] . '%'];
            }
            
            // ✅ 国家筛选
            if (!empty($param['country'])) {
                $where[] = ['country', 'like', '%' . $param['country'] . '%'];
            }
            
            // 状态筛选
            if (isset($param['status']) && $param['status'] !== '') {
                $where[] = ['status', '=', $param['status']];
            }

            // 查询数据
            $query = Db::name('onlyfans_creators')->where($where);
            $total = $query->count();
            
            $list = $query
                ->field('id, name, avatar, category_id, intro, media_count, fans_count, country, height, cup_size, measurements, birth_date, visitor_count, like_count, video_count, sort, status, create_time, update_time')
                ->order('sort desc, create_time desc, id desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            // 获取分类信息
            if (!empty($list)) {
                $categoryIds = array_unique(array_column($list, 'category_id'));
                $categories = Db::name('onlyfans_categories')
                    ->whereIn('id', $categoryIds)
                    ->column('name', 'id');
            } else {
                $categories = [];
            }

            // 补全数据
            $domain = rtrim(request()->domain(), '/');
            foreach ($list as &$item) {
                // 补全头像URL
                if (!empty($item['avatar']) && !preg_match('/^https?:\/\//', $item['avatar'])) {
                    if ($item['avatar'][0] !== '/') {
                        $item['avatar'] = '/' . $item['avatar'];
                    }
                    $item['avatar'] = $domain . $item['avatar'];
                }
                
                // 补全分类名称
                $item['category_name'] = $categories[$item['category_id']] ?? '未分类';
                
                // ✅ 确保新字段存在并设置默认值
                $item['country'] = $item['country'] ?? '';
                $item['height'] = $item['height'] ?? 0;
                $item['cup_size'] = $item['cup_size'] ?? '';
                $item['measurements'] = $item['measurements'] ?? '';
                $item['birth_date'] = (empty($item['birth_date']) || $item['birth_date'] === '0000-00-00') ? '' : $item['birth_date'];
                $item['visitor_count'] = $item['visitor_count'] ?? 0;
                $item['like_count'] = $item['like_count'] ?? 0;
                $item['video_count'] = $item['video_count'] ?? 0;
                $item['media_count'] = $item['media_count'] ?? 0;
                $item['fans_count'] = $item['fans_count'] ?? 0;
            }
            unset($item);

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
            return json(['code' => 1, 'msg' => '获取博主列表失败：' . $e->getMessage()]);
        }
    }

    /**
     * 新增博主
     */
    // ========= 改后的 add =========
public function add(Request $request)
{
    $data = $request->post();

    $validate = new Validate([
        'name|博主名称' => 'require|max:50|unique:onlyfans_creators',
        'category_id|所属分类' => 'require|integer|gt:0',
        'avatar|头像' => 'max:500',
        'intro|简介' => 'max:1000',
        'country|国家' => 'max:50',
        'height|身高' => 'integer|between:140,220',
        'cup_size|罩杯' => 'max:10',
        'measurements|三围' => 'max:50|regex:/^\d+-\d+-\d+$/',
        'birth_date|生日' => 'dateFormat:Y-m-d',
        'visitor_count|访客数' => 'integer|egt:0',
        'like_count|点赞数' => 'integer|egt:0',
        'video_count|影片数量' => 'integer|egt:0',
        'fans_count|粉丝数' => 'integer|egt:0',
        'sort|排序' => 'integer|egt:0',
        'status|状态' => 'in:0,1'
    ], [
        'measurements.regex' => '三围格式错误，请输入如：90-60-90',
        'height.between' => '身高范围应在140-220cm之间',
        'birth_date.dateFormat' => '生日格式错误，请使用YYYY-MM-DD格式'
    ]);

    if (!$validate->check($data)) {
        return json(['code' => 1, 'msg' => $validate->getError()]);
    }

    try {
        // 分类校验
        $category = Db::name('onlyfans_categories')
            ->where('id', $data['category_id'])
            ->where('status', 1)
            ->find();
        if (!$category) return json(['code' => 1, 'msg' => '分类不存在或已禁用']);

        // 规范化 & 白名单
        $this->normalizeCreatorData($data);
        $data = $this->filterCreatorWritableFields($data);

        // 默认值
        $data['status']        = $data['status'] ?? 1;
        $data['sort']          = $data['sort'] ?? 0;
        $data['media_count']   = $data['media_count'] ?? 0;
        $data['create_time']   = date('Y-m-d H:i:s');
        $data['update_time']   = date('Y-m-d H:i:s');

        $id = Db::name('onlyfans_creators')->insertGetId($data);
        if (!$id) return json(['code' => 1, 'msg' => '新增博主失败']);

        return json(['code' => 0, 'msg' => '新增博主成功', 'data' => ['id' => $id]]);
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '新增博主失败：' . $e->getMessage()]);
    }
}


    /**
     * 更新博主
     */
    // ========= 改后的 update =========
public function update(Request $request)
{
    // 用 param() 合并 GET/POST/路由参数
    $data = $request->param();

    // 先安全拿到 id，避免 Undefined array key
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        return json(['code' => 1, 'msg' => '缺少有效的博主ID']);
    }
    // 写回去，给校验用
    $data['id'] = $id;

    $validate = new Validate([
        'id|博主ID' => 'require|integer|gt:0',
        'name|博主名称' => 'require|max:50',
        'category_id|所属分类' => 'require|integer|gt:0',
        'avatar|头像' => 'max:500',
        'intro|简介' => 'max:1000',
        'country|国家' => 'max:50',
        'height|身高' => 'integer|between:140,220',
        'cup_size|罩杯' => 'max:10',
        'measurements|三围' => 'max:50|regex:/^\d+-\d+-\d+$/',
        'birth_date|生日' => 'dateFormat:Y-m-d',
        'visitor_count|访客数' => 'integer|egt:0',
        'like_count|点赞数' => 'integer|egt:0',
        'video_count|影片数量' => 'integer|egt:0',
        'fans_count|粉丝数' => 'integer|egt:0',
        'sort|排序' => 'integer|egt:0',
        'status|状态' => 'in:0,1'
    ], [
        'measurements.regex' => '三围格式错误，请输入如：90-60-90',
        'height.between' => '身高范围应在140-220cm之间',
        'birth_date.dateFormat' => '生日格式错误，请使用YYYY-MM-DD格式'
    ]);

    if (!$validate->check($data)) {
        return json(['code' => 1, 'msg' => $validate->getError()]);
    }

    try {
        // 是否存在
        $creator = Db::name('onlyfans_creators')->where('id', $id)->find();
        if (!$creator) return json(['code' => 1, 'msg' => '博主不存在']);

        // 名称唯一
        $exists = Db::name('onlyfans_creators')
            ->where('name', $data['name'])
            ->where('id', '<>', $id)
            ->find();
        if ($exists) return json(['code' => 1, 'msg' => '博主名称已存在']);

        // 分类校验
        $category = Db::name('onlyfans_categories')
            ->where('id', $data['category_id'])
            ->where('status', 1)
            ->find();
        if (!$category) return json(['code' => 1, 'msg' => '分类不存在或已禁用']);

        // 规范化 + 白名单过滤（去掉 category_name 等展示字段）
        $this->normalizeCreatorData($data);
        $data = $this->filterCreatorWritableFields($data);

        $data['update_time'] = date('Y-m-d H:i:s');
        unset($data['id']); // 别把主键当普通字段更新

        $result = Db::name('onlyfans_creators')->where('id', $id)->update($data);
        if ($result === false) return json(['code' => 1, 'msg' => '更新博主失败']);

        return json(['code' => 0, 'msg' => '更新博主成功']);
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '更新博主失败：' . $e->getMessage()]);
    }
}

    /**
     * 获取博主详情
     */
    public function detail(Request $request)
    {
        $id = $request->param('id');
        
        if (!$id) {
            return json(['code' => 1, 'msg' => '博主ID不能为空']);
        }

        try {
            $creator = Db::name('onlyfans_creators')
                ->where('id', $id)
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

            // 获取分类信息
            if ($creator['category_id']) {
                $category = Db::name('onlyfans_categories')
                    ->where('id', $creator['category_id'])
                    ->find();
                $creator['category_name'] = $category['name'] ?? '未分类';
            } else {
                $creator['category_name'] = '未分类';
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $creator
            ]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取博主详情失败：' . $e->getMessage()]);
        }
    }

    /**
     * 删除博主
     */
    public function delete(Request $request)
    {
        $id = $request->post('id');
        
        if (!$id) {
            return json(['code' => 1, 'msg' => '博主ID不能为空']);
        }

        try {
            Db::startTrans();

            $creator = Db::name('onlyfans_creators')->where('id', $id)->find();
            if (!$creator) {
                throw new \Exception('博主不存在');
            }

            // 检查是否有媒体内容
            $mediaCount = Db::name('onlyfans_media')
                ->where('creator_id', $id)
                ->count();
                
            if ($mediaCount > 0) {
                throw new \Exception("该博主下还有 {$mediaCount} 个媒体内容，请先处理相关内容");
            }

            $result = Db::name('onlyfans_creators')->where('id', $id)->delete();
            if (!$result) {
                throw new \Exception('删除失败');
            }

            Db::commit();
            return json(['code' => 0, 'msg' => '删除博主成功']);

        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * 批量删除博主
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '请选择要删除的博主']);
        }

        try {
            Db::startTrans();

            // 检查是否有媒体内容
            $mediaCount = Db::name('onlyfans_media')
                ->whereIn('creator_id', $ids)
                ->count();
                
            if ($mediaCount > 0) {
                throw new \Exception("选择的博主下还有 {$mediaCount} 个媒体内容，请先处理相关内容");
            }

            $count = Db::name('onlyfans_creators')->whereIn('id', $ids)->delete();
            if (!$count) {
                throw new \Exception('批量删除失败');
            }

            Db::commit();
            return json(['code' => 0, 'msg' => "批量删除成功，共删除 {$count} 个博主"]);

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
                    Db::name('onlyfans_creators')
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

    /**
     * 批量设置状态
     */
    public function batchSetStatus(Request $request)
    {
        $ids = $request->post('ids', []);
        $status = $request->post('status', 1);
        
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '请选择要设置的博主']);
        }

        try {
            $count = Db::name('onlyfans_creators')
                ->whereIn('id', $ids)
                ->update([
                    'status' => $status,
                    'update_time' => date('Y-m-d H:i:s')
                ]);

            $statusText = $status ? '启用' : '禁用';
            return json(['code' => 0, 'msg' => "批量{$statusText}成功，共设置 {$count} 个博主"]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '批量设置状态失败：' . $e->getMessage()]);
        }
    }

    /**
     * 批量设置分类
     */
    public function batchSetCategory(Request $request)
    {
        $ids = $request->post('ids', []);
        $categoryId = $request->post('category_id');
        
        if (empty($ids) || !is_array($ids) || !$categoryId) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        try {
            // 验证分类是否存在
            $category = Db::name('onlyfans_categories')
                ->where('id', $categoryId)
                ->where('status', 1)
                ->find();
                
            if (!$category) {
                return json(['code' => 1, 'msg' => '分类不存在或已禁用']);
            }

            $count = Db::name('onlyfans_creators')
                ->whereIn('id', $ids)
                ->update([
                    'category_id' => $categoryId,
                    'update_time' => date('Y-m-d H:i:s')
                ]);

            return json(['code' => 0, 'msg' => "批量设置分类成功，共设置 {$count} 个博主"]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '批量设置分类失败：' . $e->getMessage()]);
        }
    }

    /**
     * ✅ 新增：获取博主选项列表（用于下拉选择）
     */
    public function options(Request $request)
    {
        try {
            $list = Db::name('onlyfans_creators')
                ->where('status', 1)
                ->field('id as value, name as label')
                ->order('sort desc, id desc')
                ->select()
                ->toArray();

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $list
            ]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取博主选项失败：' . $e->getMessage()]);
        }
    }
    // 放到类里（OnlyFansCreatorController）任意位置
private function normalizeCreatorData(array &$data): void
{
    // 去空格
    foreach (['name','avatar','intro','country','cup_size','measurements','birth_date'] as $k) {
        if (isset($data[$k])) $data[$k] = trim((string)$data[$k]);
    }

    // 空字符串 -> NULL 的字段
    foreach (['birth_date','avatar','intro','country','cup_size','measurements'] as $k) {
        if (!isset($data[$k]) || $data[$k] === '') $data[$k] = null;
    }

    // 数值字段：空字符串按 0/NULL 处理
    foreach (['height','visitor_count','like_count','video_count','fans_count','sort'] as $k) {
        if (!isset($data[$k]) || $data[$k] === '') {
            // height 允许 NULL；计数/排序给 0
            $data[$k] = ($k === 'height') ? null : 0;
        } else {
            $data[$k] = (int)$data[$k];
        }
    }

    // birth_date 做一次格式化（防止传 2025/08/08 这种）
    if (!empty($data['birth_date'])) {
        $ts = strtotime($data['birth_date']);
        $data['birth_date'] = $ts ? date('Y-m-d', $ts) : null;
    }
}
// 仅新增：写库字段白名单
private function filterCreatorWritableFields(array $data): array
{
    // 这里写的是 onlyfans_creators 表里真正允许写入的字段
    $allow = [
        'name','category_id','avatar','intro',
        'country','height','cup_size','measurements','birth_date',
        'visitor_count','like_count','video_count','fans_count','media_count',
        'sort','status'
    ];
    return array_intersect_key($data, array_flip($allow));
}

}