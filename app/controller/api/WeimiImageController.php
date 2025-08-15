<?php
// 文件路径: E:\ThinkPHP6\app\controller\api\WeimiImageController.php
namespace app\controller\api;

use think\Request;
use think\facade\Db; // 假设你使用了ThinkPHP的Db门面

class WeimiImageController
{
    /**
     * 获取微密圈图片列表
     * 支持分页、关键词搜索、分类筛选、标签筛选、排序
     * @param Request $request
     * @return \think\response\Json
     */
    public function list(Request $request)
    {
        try {
            $page = $request->param('page', 1);
            $pageSize = $request->param('pageSize', 10);
            $keyword = $request->param('keyword', ''); // 标题/编号/标签
            $categoryId = $request->param('categoryId', ''); // 所属分类ID
            $tagId = $request->param('tagId', '');         // 标签ID
            $orderBy = $request->param('orderBy', 'sort'); // 排序字段，默认为 'sort'
            $orderDirection = $request->param('orderDirection', 'asc'); // 排序方向，默认为 'asc'

            // 构建查询
            $query = Db::name('weimi_images'); // *** 确保你的数据库存在 'weimi_images' 表 ***

            if (!empty($keyword)) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('title', 'like', '%' . $keyword . '%')
                      ->when(is_numeric($keyword), function ($q) use ($keyword) {
                          $q->orWhere('id', $keyword); // 如果是数字，也按ID搜索
                      })
                      ->orWhere('tags', 'like', '%' . $keyword . '%'); // 假设标签是逗号分隔字符串或JSON字符串
                });
            }
            if (!empty($categoryId)) {
                $query->where('category_id', $categoryId);
            }
            if (!empty($tagId)) {
                // 如果tags是JSON字符串存储，需要更复杂的查询，这里简化为like
                $query->where('tag_ids', 'like', '%"' . $tagId . '"%');
            }

            // 处理排序
            $validOrderBy = ['id', 'sort', 'create_time', 'update_time']; // 允许排序的字段
            if (in_array($orderBy, $validOrderBy)) {
                $query->order($orderBy, $orderDirection);
            } else {
                $query->order('sort', 'asc')->order('id', 'desc'); // 默认排序
            }

            // 获取数据
            $list = $query->paginate([
                'list_rows' => $pageSize,
                'page' => $page,
            ]);

            // 格式化数据以适应前端，例如tags从JSON字符串转为数组，添加category_name等
            $items = $list->items();
            $categoryNames = []; // 缓存分类名称，避免重复查询
            $tagNames = []; // 缓存标签名称

            // 批量获取所有涉及的分类和标签，减少DB查询
            $categoryIds = array_unique(array_column($items, 'category_id'));
            if (!empty($categoryIds)) {
                $categories = Db::name('weimi_categories')->whereIn('id', $categoryIds)->column('name', 'id');
                $categoryNames = $categories; // Map category ID to name
            }

            // 获取所有标签，前端可能需要显示标签名称
            $allTagIdsInImages = [];
            foreach ($items as $item) {
                if (isset($item['tag_ids']) && !empty($item['tag_ids'])) {
                    $decodedTags = json_decode($item['tag_ids'], true);
                    if (is_array($decodedTags)) {
                        $allTagIdsInImages = array_merge($allTagIdsInImages, $decodedTags);
                    }
                }
            }
            $allTagIdsInImages = array_unique($allTagIdsInImages);
            if (!empty($allTagIdsInImages)) {
                $tags = Db::name('weimi_tags')->whereIn('id', $allTagIdsInImages)->column('name', 'id');
                $tagNames = $tags; // Map tag ID to name
            }

            foreach ($items as &$item) {
                // 假设 'cover' 字段是相对路径，需要拼接域名
                if (isset($item['cover']) && !empty($item['cover'])) {
                    $item['cover'] = request()->domain() . '/upload/' . $item['cover'];
                }

                // tags 字段适配为数组，并尝试转换为名称（如果需要）
                if (isset($item['tag_ids']) && !empty($item['tag_ids'])) {
                    $decodedTags = json_decode($item['tag_ids'], true);
                    if (is_array($decodedTags)) {
                        $item['tags'] = array_values(array_intersect_key($tagNames, array_flip($decodedTags))); // 转换为标签名称数组
                    } else {
                        $item['tags'] = [];
                    }
                } else {
                    $item['tags'] = [];
                }

                // 适配博主/主分类/子分类名称
                $item['author_name'] = $categoryNames[$item['category_id']] ?? '--'; // 假设 category_id 直接关联博主或主分类
                // 如果你有独立的 'author_id' 字段和 'authors' 表，这里需要修改
                // $item['parentName'] = ...;
                // $item['categoryName'] = ...; // 如果 category_id 关联的是子分类

                // vip适配成布尔值（如有需要，前端可以自动适配）
                $item['vip'] = isset($item['is_vip']) && $item['is_vip'] == 1 ? true : false;
                // gold和coin字段适配
                $item['coin'] = $item['gold'] ?? 0;
            }

            return successJson([
                'list' => $items,
                'total' => $list->total(),
            ]);

        } catch (\Exception $e) {
            // 生产环境下不直接暴露详细错误信息
            return errorJson('获取微密圈图片列表失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 新增微密圈图片专辑
     * @param Request $request
     * @return \think\response\Json
     */
    public function add(Request $request)
    {
        $data = $request->post();
        // 处理tags字段，如果前端发送的是标签名数组，后端需要转换为ID数组或JSON字符串
        if (isset($data['tags']) && is_array($data['tags'])) {
            // 假设前端发送的是标签名，你需要查询标签表获取ID
            // 或者前端直接发送tag ID数组，那就直接json_encode
            // 为了简化，这里假设前端发送的是标签ID数组
            $data['tag_ids'] = json_encode($data['tags']);
        } else {
            $data['tag_ids'] = '[]';
        }

        // 处理封面图片 URL，只保存相对路径
        if (isset($data['cover_url']) && strpos($data['cover_url'], request()->domain()) === 0) {
            $data['cover'] = str_replace(request()->domain() . '/upload/', '', $data['cover_url']);
        } else {
            $data['cover'] = ''; // 或者处理上传的图片文件
        }

        $data['is_vip'] = isset($data['vip']) && $data['vip'] ? 1 : 0; // VIP布尔值转整数
        $data['gold'] = $data['coin'] ?? 0; // coin转gold
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');

        // 移除前端不需要的字段，或数据库中不存在的字段
        unset($data['vip'], $data['coin'], $data['images'], $data['cover_url']);

        $id = Db::name('weimi_images')->insertGetId($data); // *** 确保操作 'weimi_images' 表 ***
        return $id ? successJson(['id' => $id]) : errorJson('新增图片专辑失败');
    }

    /**
     * 编辑微密圈图片专辑
     * @param Request $request
     * @return \think\response\Json
     */
    public function update(Request $request)
    {
        $data = $request->post();
        if (empty($data['id'])) {
            return errorJson('缺少图片专辑ID');
        }

        // 处理tags字段
        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tag_ids'] = json_encode($data['tags']);
        } else {
            $data['tag_ids'] = '[]';
        }

        // 处理封面图片 URL，只保存相对路径
        if (isset($data['cover_url']) && strpos($data['cover_url'], request()->domain()) === 0) {
            $data['cover'] = str_replace(request()->domain() . '/upload/', '', $data['cover_url']);
        } else {
            // 如果 cover_url 不是完整的 URL，可能需要特别处理或不更新
            // $data['cover'] = $data['cover_url'];
        }

        $data['is_vip'] = isset($data['vip']) && $data['vip'] ? 1 : 0;
        $data['gold'] = $data['coin'] ?? 0;
        $data['update_time'] = date('Y-m-d H:i:s');

        unset($data['vip'], $data['coin'], $data['images'], $data['cover_url']); // 移除前端不需要的字段

        $ret = Db::name('weimi_images')->where('id', $data['id'])->update($data);
        return $ret !== false ? successJson() : errorJson('更新图片专辑失败');
    }

    /**
     * 批量删除微密圈图片专辑
     * @param Request $request
     * @return \think\response\Json
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少图片专辑ID或参数格式错误');
        }
        $count = Db::name('weimi_images')->whereIn('id', $ids)->delete();
        return $count ? successJson(['count' => $count], "批量删除成功，共删除{$count}条") : errorJson('批量删除失败');
    }

    /**
     * 更新图片排序 (核心排序功能接口)
     * @param Request $request POST数据包含 list: [{ id: 1, sort: 10 }, ...]
     * @return \think\response\Json
     */
    public function updateSort(Request $request)
    {
        $list = $request->post('list', []);
        if (empty($list) || !is_array($list)) {
            return errorJson('参数错误，list为空或格式不正确');
        }

        Db::startTrans(); // 开启事务
        try {
            foreach ($list as $item) {
                if (isset($item['id']) && isset($item['sort'])) {
                    Db::name('weimi_images')->where('id', $item['id'])->update([
                        'sort' => intval($item['sort']),
                        'update_time' => date('Y-m-d H:i:s')
                    ]);
                }
            }
            Db::commit(); // 提交事务
            return successJson([], '图片排序更新成功');
        } catch (\Exception $e) {
            Db::rollback(); // 回滚事务
            return errorJson('图片排序更新失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 获取单个图片专辑详情
     * @param int $id 图片专辑ID (通过路由参数传递，例如 /api/weimi/images/1)
     * @return \think\response\Json
     */
    public function getById(int $id)
    {
        $data = Db::name('weimi_images')->where('id', $id)->find();
        if (!$data) {
            return errorJson('未找到该图片专辑');
        }
        // 格式化数据以适应前端，例如tags从JSON字符串转为数组
        if (isset($data['tag_ids']) && !empty($data['tag_ids'])) {
            $data['tags'] = json_decode($data['tag_ids'], true);
        } else {
            $data['tags'] = [];
        }

        // 拼接完整封面URL
        if (isset($data['cover']) && !empty($data['cover'])) {
            $data['cover_url'] = request()->domain() . '/upload/' . $data['cover'];
        } else {
            $data['cover_url'] = '';
        }

        $data['vip'] = isset($data['is_vip']) && $data['is_vip'] == 1 ? true : false;
        $data['coin'] = $data['gold'] ?? 0;

        return successJson($data);
    }


    // 以下是图片管理页面可能需要的批量设置功能，如果你的前端有这些按钮，请实现：
    /**
     * 批量设置VIP
     * @param Request $request
     * @return \think\response\Json
     */
    public function batchSetVip(Request $request)
    {
        $ids = $request->post('ids', []);
        $isVip = $request->post('is_vip', 1); // 1为VIP，0为非VIP
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少图片专辑ID或参数格式错误');
        }
        $count = Db::name('weimi_images')->whereIn('id', $ids)->update(['is_vip' => $isVip, 'update_time' => date('Y-m-d H:i:s')]);
        return $count ? successJson(['count' => $count], "批量设置VIP成功，共设置{$count}条") : errorJson('批量设置VIP失败');
    }

    /**
     * 批量设置金币
     * @param Request $request
     * @return \think\response\Json
     */
    public function batchSetGold(Request $request)
    {
        $ids = $request->post('ids', []);
        $gold = $request->post('gold', 0);
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少图片专辑ID或参数格式错误');
        }
        $count = Db::name('weimi_images')->whereIn('id', $ids)->update(['gold' => $gold, 'update_time' => date('Y-m-d H:i:s')]);
        return $count ? successJson(['count' => $count], "批量设置金币成功，共设置{$count}条") : errorJson('批量设置金币失败');
    }

    /**
     * 批量设置图片专辑状态 (上架/下架)
     * @param Request $request
     * @return \think\response\Json
     */
    public function batchSetStatus(Request $request)
    {
        $ids = $request->post('ids', []);
        $status = $request->post('status', 1); // 1为上架，0为下架
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少图片专辑ID或参数格式错误');
        }
        $count = Db::name('weimi_images')->whereIn('id', $ids)->update(['status' => $status, 'update_time' => date('Y-m-d H:i:s')]);
        return $count ? successJson(['count' => $count], "批量设置状态成功，共设置{$count}条") : errorJson('批量设置状态失败');
    }


    // 辅助函数，如果你的项目中有定义的话，可以移除这里的重复定义
    // 假设这些函数在公共文件中或BaseController中已全局定义
    // 如果没有，请确保你的项目中有 these helpers or integrate response logic directly
    // 例如：你可以定义一个 trait 或一个基础控制器来包含这些方法
    // function successJson / errorJson
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
