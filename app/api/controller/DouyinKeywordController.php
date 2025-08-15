<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use think\facade\Db;
use think\Request;
use think\Response;
use think\exception\ValidateException;

/**
 * 抖音关键词API控制器
 * @package app\api\controller
 */
class DouyinKeywordController extends BaseController
{
    /**
     * 获取关键词列表
     * @param Request $request
     * @return Response
     */
    public function index(Request $request): Response
    {
        try {
            $page = (int)$request->param('page', 1);
            $pageSize = (int)$request->param('pageSize', 10);
            $keyword = $request->param('keyword', '');
            $status = $request->param('status', '');
            $category = $request->param('category', '');

            $query = Db::name('douyin_keywords')
                ->where('delete_time', null)
                ->order('sort desc, heat desc, create_time desc');

            // 搜索条件
            if ($keyword !== '') {
                $query->where('keyword|display_label', 'like', '%' . $keyword . '%');
            }
            if ($status !== '') {
                $query->where('status', $status);
            }
            if ($category !== '') {
                $query->where('category', $category);
            }

            $total = $query->count();
            $list = $query->limit(($page - 1) * $pageSize, $pageSize)->select();

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'list' => $list,
                    'total' => $total,
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'pages' => ceil($total / $pageSize)
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '获取列表失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 获取启用的关键词
     * @param Request $request
     * @return Response
     */
    public function enabled(Request $request): Response
    {
        try {
            $limit = (int)$request->param('limit', 50);

            $list = Db::name('douyin_keywords')
                ->where('status', 1)
                ->where('delete_time', null)
                ->order('sort desc, heat desc')
                ->limit($limit)
                ->select();

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $list
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '获取启用关键词失败：' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * 随机获取一个关键词
     * @param Request $request
     * @return Response
     */
    public function random(Request $request): Response
    {
        try {
            $excludeIds = $request->param('excludeIds', '');
            $excludeArray = $excludeIds ? explode(',', $excludeIds) : [];

            $query = Db::name('douyin_keywords')
                ->where('status', 1)
                ->where('delete_time', null);

            if (!empty($excludeArray)) {
                $query->whereNotIn('id', $excludeArray);
            }

            $keywords = $query->select()->toArray();

            if (empty($keywords)) {
                return json([
                    'code' => 0,
                    'msg' => 'success',
                    'data' => null
                ]);
            }

            // 基于热度值的加权随机选择
            $totalWeight = array_sum(array_column($keywords, 'heat'));
            $randomValue = mt_rand(1, $totalWeight);

            $selectedKeyword = null;
            foreach ($keywords as $keyword) {
                $randomValue -= $keyword['heat'];
                if ($randomValue <= 0) {
                    $selectedKeyword = $keyword;
                    break;
                }
            }

            if ($selectedKeyword) {
                // 更新显示次数
                Db::name('douyin_keywords')
                    ->where('id', $selectedKeyword['id'])
                    ->inc('display_count');
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $selectedKeyword
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '获取随机关键词失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 添加关键词
     * @param Request $request
     * @return Response
     */
    public function save(Request $request): Response
    {
        try {
            $data = $request->only([
                'keyword', 'display_label', 'heat', 'sort', 
                'status', 'category', 'description'
            ]);
            
            // 验证数据
            $this->validate($data, [
                'keyword' => 'require|max:20',
                'display_label' => 'max:50',
                'heat' => 'integer|>=:0',
                'sort' => 'integer|>=:0',
                'status' => 'in:0,1',
                'category' => 'max:20'
            ], [
                'keyword.require' => '搜索关键词不能为空',
                'keyword.max' => '搜索关键词最多20个字符',
                'display_label.max' => '显示标签最多50个字符',
                'heat.integer' => '热度值必须为整数',
                'heat.>=' => '热度值不能小于0',
                'sort.integer' => '排序值必须为整数',
                'sort.>=' => '排序值不能小于0',
                'status.in' => '状态值不正确',
                'category.max' => '分类最多20个字符'
            ]);

            // 检查关键词是否已存在
            $exists = Db::name('douyin_keywords')
                ->where('keyword', $data['keyword'])
                ->where('delete_time', null)
                ->find();

            if ($exists) {
                return json([
                    'code' => 1,
                    'msg' => '该关键词已存在',
                    'data' => null
                ]);
            }

            $data['create_time'] = date('Y-m-d H:i:s');
            $data['update_time'] = date('Y-m-d H:i:s');
            
            $id = Db::name('douyin_keywords')->insertGetId($data);
            $data['id'] = $id;

            return json([
                'code' => 0,
                'msg' => '添加成功',
                'data' => $data
            ]);

        } catch (ValidateException $e) {
            return json([
                'code' => 1,
                'msg' => $e->getError(),
                'data' => null
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '添加失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 获取单个关键词信息
     * @param int $id
     * @return Response
     */
    public function read(int $id): Response
    {
        try {
            $keyword = Db::name('douyin_keywords')
                ->where('id', $id)
                ->where('delete_time', null)
                ->find();
            
            if (!$keyword) {
                return json([
                    'code' => 1,
                    'msg' => '关键词不存在',
                    'data' => null
                ]);
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $keyword
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '获取失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 更新关键词
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function update(Request $request, int $id): Response
    {
        try {
            $keyword = Db::name('douyin_keywords')
                ->where('id', $id)
                ->where('delete_time', null)
                ->find();

            if (!$keyword) {
                return json([
                    'code' => 1,
                    'msg' => '关键词不存在',
                    'data' => null
                ]);
            }

            $data = $request->only([
                'keyword', 'display_label', 'heat', 'sort', 
                'status', 'category', 'description'
            ]);

            // 验证数据
            $this->validate($data, [
                'keyword' => 'require|max:20',
                'display_label' => 'max:50',
                'heat' => 'integer|>=:0',
                'sort' => 'integer|>=:0',
                'status' => 'in:0,1',
                'category' => 'max:20'
            ]);

            // 检查关键词是否已存在（排除当前记录）
            if (isset($data['keyword'])) {
                $exists = Db::name('douyin_keywords')
                    ->where('keyword', $data['keyword'])
                    ->where('delete_time', null)
                    ->where('id', '<>', $id)
                    ->find();

                if ($exists) {
                    return json([
                        'code' => 1,
                        'msg' => '该关键词已存在',
                        'data' => null
                    ]);
                }
            }

            $data['update_time'] = date('Y-m-d H:i:s');
            Db::name('douyin_keywords')->where('id', $id)->update($data);

            $updatedKeyword = Db::name('douyin_keywords')->where('id', $id)->find();

            return json([
                'code' => 0,
                'msg' => '更新成功',
                'data' => $updatedKeyword
            ]);

        } catch (ValidateException $e) {
            return json([
                'code' => 1,
                'msg' => $e->getError(),
                'data' => null
            ]);
        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '更新失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 删除关键词
     * @param int $id
     * @return Response
     */
    public function delete(int $id): Response
    {
        try {
            $keyword = Db::name('douyin_keywords')
                ->where('id', $id)
                ->where('delete_time', null)
                ->find();

            if (!$keyword) {
                return json([
                    'code' => 1,
                    'msg' => '关键词不存在',
                    'data' => null
                ]);
            }

            // 软删除
            Db::name('douyin_keywords')
                ->where('id', $id)
                ->update(['delete_time' => date('Y-m-d H:i:s')]);

            return json([
                'code' => 0,
                'msg' => '删除成功',
                'data' => null
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '删除失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 批量更新状态
     * @param Request $request
     * @return Response
     */
    public function batchStatus(Request $request): Response
    {
        try {
            $ids = $request->param('ids', []);
            $status = $request->param('status');

            if (empty($ids) || !is_array($ids)) {
                return json([
                    'code' => 1,
                    'msg' => '请选择要操作的关键词',
                    'data' => null
                ]);
            }

            if (!in_array($status, [0, 1])) {
                return json([
                    'code' => 1,
                    'msg' => '状态参数错误',
                    'data' => null
                ]);
            }

            Db::name('douyin_keywords')
                ->whereIn('id', $ids)
                ->where('delete_time', null)
                ->update([
                    'status' => $status,
                    'update_time' => date('Y-m-d H:i:s')
                ]);

            return json([
                'code' => 0,
                'msg' => '批量操作成功',
                'data' => null
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '批量操作失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 批量删除
     * @param Request $request
     * @return Response
     */
    public function batchDelete(Request $request): Response
    {
        try {
            $ids = $request->param('ids', []);

            if (empty($ids) || !is_array($ids)) {
                return json([
                    'code' => 1,
                    'msg' => '请选择要删除的关键词',
                    'data' => null
                ]);
            }

            // 批量软删除
            Db::name('douyin_keywords')
                ->whereIn('id', $ids)
                ->where('delete_time', null)
                ->update(['delete_time' => date('Y-m-d H:i:s')]);

            return json([
                'code' => 0,
                'msg' => '批量删除成功',
                'data' => null
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '批量删除失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 更新排序
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function updateSort(Request $request, int $id): Response
    {
        try {
            $keyword = Db::name('douyin_keywords')
                ->where('id', $id)
                ->where('delete_time', null)
                ->find();

            if (!$keyword) {
                return json([
                    'code' => 1,
                    'msg' => '关键词不存在',
                    'data' => null
                ]);
            }

            $sort = $request->param('sort');
            Db::name('douyin_keywords')
                ->where('id', $id)
                ->update([
                    'sort' => $sort,
                    'update_time' => date('Y-m-d H:i:s')
                ]);

            $updatedKeyword = Db::name('douyin_keywords')->where('id', $id)->find();

            return json([
                'code' => 0,
                'msg' => '排序更新成功',
                'data' => $updatedKeyword
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '排序更新失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 记录显示次数
     * @param int $id
     * @return Response
     */
    public function recordDisplay(int $id): Response
    {
        try {
            $keyword = Db::name('douyin_keywords')
                ->where('id', $id)
                ->where('delete_time', null)
                ->find();

            if (!$keyword) {
                return json([
                    'code' => 1,
                    'msg' => '关键词不存在',
                    'data' => null
                ]);
            }

            Db::name('douyin_keywords')
                ->where('id', $id)
                ->inc('display_count');

            return json([
                'code' => 0,
                'msg' => '记录成功',
                'data' => null
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '记录失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 记录点击次数
     * @param int $id
     * @return Response
     */
    public function recordClick(int $id): Response
    {
        try {
            $keyword = Db::name('douyin_keywords')
                ->where('id', $id)
                ->where('delete_time', null)
                ->find();

            if (!$keyword) {
                return json([
                    'code' => 1,
                    'msg' => '关键词不存在',
                    'data' => null
                ]);
            }

            // 执行数据库更新 - 使用原生SQL语句确保更新成功
            $currentCount = (int)$keyword['click_count'];
            $newCount = $currentCount + 1;
            
            // 方法1: 使用update方式
            Db::name('douyin_keywords')
                ->where('id', $id)
                ->update(['click_count' => $newCount]);
            
            // 方法2: 使用原生SQL作为备用
            $sql = "UPDATE douyin_keywords SET click_count = click_count + 1 WHERE id = ? AND delete_time IS NULL";
            Db::execute($sql, [$id]);

            return json([
                'code' => 0,
                'msg' => '记录成功',
                'data' => null
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '记录失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 获取统计信息
     * @return Response
     */
    public function stats(): Response
    {
        try {
            $total = Db::name('douyin_keywords')
                ->where('delete_time', null)
                ->count();

            $enabled = Db::name('douyin_keywords')
                ->where('status', 1)
                ->where('delete_time', null)
                ->count();

            $avgHeat = Db::name('douyin_keywords')
                ->where('status', 1)
                ->where('delete_time', null)
                ->avg('heat') ?: 0;

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'total' => $total,
                    'enabled' => $enabled,
                    'avgHeat' => round($avgHeat),
                    'randomChance' => 40
                ]
            ]);

        } catch (\Exception $e) {
            return json([
                'code' => 1,
                'msg' => '获取统计失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }
}
