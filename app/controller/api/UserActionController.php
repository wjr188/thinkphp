<?php
declare(strict_types=1);

namespace app\controller\api;

use think\Request;
use think\facade\Db;
use Firebase\JWT\JWT;

class UserActionController
{
    // 填你的JWT密钥
    private $jwtKey = 'MyAwesomeSuperKey2024!@#xBk'; // TODO: 改成你的密钥
    private $jwtAlg = 'HS256';

    // 统一 JWT 校验
    private function getUuidFromJwt($request)
    {
        $header = $request->header('Authorization') ?: $request->header('token');
        if (empty($header)) {
            return ['error' => json(['code' => 401, 'msg' => '未登录，请先登录'])];
        }
        $token = trim(str_ireplace('Bearer', '', $header));
        try {
            $decoded = (array)JWT::decode($token, $this->jwtKey, [$this->jwtAlg]);
            $uuid = $decoded['uuid'] ?? '';
            if (!$uuid) {
                return ['error' => json(['code' => 401, 'msg' => 'token无效'])];
            }
            return ['uuid' => $uuid];
        } catch (\Exception $e) {
            return ['error' => json(['code' => 401, 'msg' => 'token格式不正确: ' . $e->getMessage()])];
        }
    }

    // 获取表名和字段映射
    private function getTableConfig($type)
    {
        $config = [
            'douyin' => [
                'table' => 'douyin_videos',
                'like_field' => 'like_count',
                'collect_field' => 'collect_count'
            ],
            'long_video' => [
                'table' => 'long_videos',
                'like_field' => 'like_count',
                'collect_field' => 'collect_count'
            ],
            'darknet' => [
                'table' => 'darknet_video', // 修正表名为单数形式
                'like_field' => 'play',     // 使用 play 字段作为点赞计数
                'collect_field' => 'collect' // 使用 collect 字段作为收藏计数
            ],
            'comic' => [
                'table' => 'comic_chapters',
                'like_field' => 'like_count',
                'collect_field' => 'collect_count'
            ],
            'novel' => [
                'table' => 'text_novel_chapter',
                'like_field' => 'like_count',
                'collect_field' => 'collect_count'
            ],
            'audio_novel' => [
                'table' => 'audio_novel_chapter',
                'like_field' => 'like_count',
                'collect_field' => 'collect_count'
            ]
        ];
        
        return $config[$type] ?? null;
    }

    /**
     * 点赞接口
     * POST /api/h5/user/like
     * @param Request $request
     * @return \think\response\Json
     */
    public function like(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];

        $contentId = (int)$request->post('content_id', 0);
        $type = $request->post('type', ''); // douyin, long_video, comic, novel, audio_novel
        
        if ($contentId <= 0) return json(['code' => 1, 'msg' => '内容ID参数错误']);
        if (empty($type)) return json(['code' => 1, 'msg' => '类型参数缺失']);

        $tableConfig = $this->getTableConfig($type);
        if (!$tableConfig) return json(['code' => 1, 'msg' => '不支持的内容类型']);

        // 检查内容是否存在
        $content = Db::name($tableConfig['table'])->where('id', $contentId)->find();
        if (!$content) return json(['code' => 1, 'msg' => '内容不存在']);

        // 检查是否已经点赞过
        $exists = Db::name('user_actions')
            ->where('user_id', $uuid)
            ->where('content_id', $contentId)
            ->where('content_type', $type)
            ->where('action_type', 'like')
            ->find();

        if ($exists) {
            return json(['code' => 0, 'msg' => '已经点赞过了', 'data' => ['liked' => true]]);
        }

        try {
            Db::startTrans();
            
            // 添加点赞记录
            Db::name('user_actions')->insert([
                'user_id' => $uuid,
                'content_id' => $contentId,
                'content_type' => $type,
                'action_type' => 'like',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // 更新内容表的点赞数
            Db::name($tableConfig['table'])
                ->where('id', $contentId)
                ->inc($tableConfig['like_field'], 1)
                ->update();

            Db::commit();

            // 获取最新的点赞数
            $newLikeCount = Db::name($tableConfig['table'])
                ->where('id', $contentId)
                ->value($tableConfig['like_field']);

            return json([
                'code' => 0,
                'msg' => '点赞成功',
                'data' => [
                    'liked' => true,
                    'like_count' => (int)$newLikeCount
                ]
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '点赞失败：' . $e->getMessage()]);
        }
    }

    /**
     * 取消点赞接口
     * POST /api/h5/user/unlike
     * @param Request $request
     * @return \think\response\Json
     */
    public function unlike(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];

        $contentId = (int)$request->post('content_id', 0);
        $type = $request->post('type', '');
        
        if ($contentId <= 0) return json(['code' => 1, 'msg' => '内容ID参数错误']);
        if (empty($type)) return json(['code' => 1, 'msg' => '类型参数缺失']);

        $tableConfig = $this->getTableConfig($type);
        if (!$tableConfig) return json(['code' => 1, 'msg' => '不支持的内容类型']);

        // 检查是否已经点赞过
        $exists = Db::name('user_actions')
            ->where('user_id', $uuid)
            ->where('content_id', $contentId)
            ->where('content_type', $type)
            ->where('action_type', 'like')
            ->find();

        if (!$exists) {
            return json(['code' => 0, 'msg' => '还未点赞', 'data' => ['liked' => false]]);
        }

        try {
            Db::startTrans();
            
            // 删除点赞记录
            Db::name('user_actions')
                ->where('user_id', $uuid)
                ->where('content_id', $contentId)
                ->where('content_type', $type)
                ->where('action_type', 'like')
                ->delete();

            // 更新内容表的点赞数
            Db::name($tableConfig['table'])
                ->where('id', $contentId)
                ->dec($tableConfig['like_field'], 1)
                ->update();

            Db::commit();

            // 获取最新的点赞数
            $newLikeCount = Db::name($tableConfig['table'])
                ->where('id', $contentId)
                ->value($tableConfig['like_field']);

            return json([
                'code' => 0,
                'msg' => '取消点赞成功',
                'data' => [
                    'liked' => false,
                    'like_count' => max(0, (int)$newLikeCount) // 防止负数
                ]
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '取消点赞失败：' . $e->getMessage()]);
        }
    }

    /**
     * 收藏接口
     * POST /api/h5/user/collect
     * @param Request $request
     * @return \think\response\Json
     */
    public function collect(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];

        $contentId = (int)$request->post('content_id', 0);
        $type = $request->post('type', '');
        
        if ($contentId <= 0) return json(['code' => 1, 'msg' => '内容ID参数错误']);
        if (empty($type)) return json(['code' => 1, 'msg' => '类型参数缺失']);

        $tableConfig = $this->getTableConfig($type);
        if (!$tableConfig) return json(['code' => 1, 'msg' => '不支持的内容类型']);

        // 检查内容是否存在
        $content = Db::name($tableConfig['table'])->where('id', $contentId)->find();
        if (!$content) return json(['code' => 1, 'msg' => '内容不存在']);

        // 检查是否已经收藏过
        $exists = Db::name('user_actions')
            ->where('user_id', $uuid)
            ->where('content_id', $contentId)
            ->where('content_type', $type)
            ->where('action_type', 'collect')
            ->find();

        if ($exists) {
            return json(['code' => 0, 'msg' => '已经收藏过了', 'data' => ['collected' => true]]);
        }

        try {
            Db::startTrans();
            
            // 添加收藏记录
            Db::name('user_actions')->insert([
                'user_id' => $uuid,
                'content_id' => $contentId,
                'content_type' => $type,
                'action_type' => 'collect',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // 更新内容表的收藏数
            Db::name($tableConfig['table'])
                ->where('id', $contentId)
                ->inc($tableConfig['collect_field'], 1)
                ->update();

            Db::commit();

            // 获取最新的收藏数
            $newCollectCount = Db::name($tableConfig['table'])
                ->where('id', $contentId)
                ->value($tableConfig['collect_field']);

            return json([
                'code' => 0,
                'msg' => '收藏成功',
                'data' => [
                    'collected' => true,
                    'collect_count' => (int)$newCollectCount
                ]
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '收藏失败：' . $e->getMessage()]);
        }
    }

    /**
     * 取消收藏接口
     * POST /api/h5/user/uncollect
     * @param Request $request
     * @return \think\response\Json
     */
    public function uncollect(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];

        $contentId = (int)$request->post('content_id', 0);
        $type = $request->post('type', '');
        
        if ($contentId <= 0) return json(['code' => 1, 'msg' => '内容ID参数错误']);
        if (empty($type)) return json(['code' => 1, 'msg' => '类型参数缺失']);

        $tableConfig = $this->getTableConfig($type);
        if (!$tableConfig) return json(['code' => 1, 'msg' => '不支持的内容类型']);

        // 检查是否已经收藏过
        $exists = Db::name('user_actions')
            ->where('user_id', $uuid)
            ->where('content_id', $contentId)
            ->where('content_type', $type)
            ->where('action_type', 'collect')
            ->find();

        if (!$exists) {
            return json(['code' => 0, 'msg' => '还未收藏', 'data' => ['collected' => false]]);
        }

        try {
            Db::startTrans();
            
            // 删除收藏记录
            Db::name('user_actions')
                ->where('user_id', $uuid)
                ->where('content_id', $contentId)
                ->where('content_type', $type)
                ->where('action_type', 'collect')
                ->delete();

            // 更新内容表的收藏数
            Db::name($tableConfig['table'])
                ->where('id', $contentId)
                ->dec($tableConfig['collect_field'], 1)
                ->update();

            Db::commit();

            // 获取最新的收藏数
            $newCollectCount = Db::name($tableConfig['table'])
                ->where('id', $contentId)
                ->value($tableConfig['collect_field']);

            return json([
                'code' => 0,
                'msg' => '取消收藏成功',
                'data' => [
                    'collected' => false,
                    'collect_count' => max(0, (int)$newCollectCount) // 防止负数
                ]
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '取消收藏失败：' . $e->getMessage()]);
        }
    }

    /**
     * 获取用户对内容的操作状态（点赞、收藏）
     * GET /api/h5/user/action_status
     * @param Request $request
     * @return \think\response\Json
     */
    public function getActionStatus(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];

        $contentId = (int)$request->get('content_id', 0);
        $type = $request->get('type', '');
        
        if ($contentId <= 0) return json(['code' => 1, 'msg' => '内容ID参数错误']);
        if (empty($type)) return json(['code' => 1, 'msg' => '类型参数缺失']);

        // 检查用户是否点赞过
        $liked = Db::name('user_actions')
            ->where('user_id', $uuid)
            ->where('content_id', $contentId)
            ->where('content_type', $type)
            ->where('action_type', 'like')
            ->find();

        // 检查用户是否收藏过
        $collected = Db::name('user_actions')
            ->where('user_id', $uuid)
            ->where('content_id', $contentId)
            ->where('content_type', $type)
            ->where('action_type', 'collect')
            ->find();

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'liked' => (bool)$liked,
                'collected' => (bool)$collected
            ]
        ]);
    }

    /**
     * 批量获取用户对多个内容的操作状态
     * POST /api/h5/user/batch_action_status
     * @param Request $request
     * @return \think\response\Json
     */
    public function batchActionStatus(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];

        $contentIds = $request->post('content_ids', []);
        $type = $request->post('type', '');
        
        if (empty($contentIds) || !is_array($contentIds)) {
            return json(['code' => 1, 'msg' => '内容ID列表参数错误']);
        }
        if (empty($type)) return json(['code' => 1, 'msg' => '类型参数缺失']);

        // 获取用户的所有操作记录
        $actions = Db::name('user_actions')
            ->where('user_id', $uuid)
            ->whereIn('content_id', $contentIds)
            ->where('content_type', $type)
            ->whereIn('action_type', ['like', 'collect'])
            ->select()
            ->toArray();

        // 整理数据结构
        $result = [];
        foreach ($contentIds as $contentId) {
            $result[$contentId] = [
                'liked' => false,
                'collected' => false
            ];
        }

        foreach ($actions as $action) {
            $contentId = $action['content_id'];
            $actionType = $action['action_type'];
            
            if (isset($result[$contentId])) {
                if ($actionType === 'like') {
                    $result[$contentId]['liked'] = true;
                } elseif ($actionType === 'collect') {
                    $result[$contentId]['collected'] = true;
                }
            }
        }

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => $result
        ]);
    }
}
