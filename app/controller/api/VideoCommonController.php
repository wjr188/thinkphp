<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use Tuupola\Base62;

class VideoCommonController
{
    /**
     * 判断还能不能看
     * GET /api/long/videos/canWatch?userId=xxx
     */
    public function canWatch(Request $request)
    {
        $userId = $request->get('userId');
        if (!$userId) {
            return json(['code' => 1, 'msg' => '参数缺失']);
        }
        $today = date('Y-m-d');
        $configs = Db::name('site_config')->column('config_value', 'config_key');
        $maxTimes = intval($configs['free_long_video_daily'] ?? 1);

        $record = Db::name('user_daily_watch_count')
            ->where('uuid', $userId)
            ->where('date', $today)
            ->find();

        $used = $record ? intval($record['long_video_used']) : 0;
        $remaining = max(0, $maxTimes - $used);

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'remaining' => $remaining
            ]
        ]);
    }

    /**
     * H5专用：获取单个长视频详情（不返回视频地址）
     * GET /api/h5/long_videos/:id
     */
    public function h5Detail($id)
    {
        $id = intval($id);
        if (!$id) return json(['code' => 1, 'msg' => '视频ID参数无效或视频不存在']);

        $info = Db::name('long_videos')->where('id', $id)->find();
        if (!$info) return json(['code' => 1, 'msg' => '视频不存在']);

        $likeCount = Db::name('video_track')->where('video_id', $id)->where('action', 'like')->count();
        $collectCount = Db::name('video_track')->where('video_id', $id)->where('action', 'collect')->count();
        $playCount = Db::name('video_track')->where('video_id', $id)->where('action', 'view')->count();

        $result = [
            'id' => $info['id'],
            'title' => $info['title'],
            'cover_url' => $info['cover_url'],
            'duration' => $info['duration'],
            'preview_duration' => $info['preview_duration'],
            'vip' => (bool)$info['is_vip'],
            'coin' => (int)($info['gold_required'] ?? 0),
            'goldCoins' => (int)($info['gold_required'] ?? 0),
            'upload_time' => $info['publish_time'],
            'tags' => json_decode($info['tags'], true) ?: [],
            'play' => (int)$info['play_count'] + $playCount,
            'collect' => (int)$info['collect_count'] + $collectCount,
            'like' => (int)$info['like_count'] + $likeCount,
            'status' => (int)$info['status'],
            'parent_id' => $info['parent_id'] ?? '',
            'category_id' => $info['category_id'] ?? '',
        ];

        return json(['code' => 0, 'msg' => '获取成功', 'data' => $result]);
    }

    /**
     * H5专用：获取单个长视频详情（返回视频地址）
     * GET /api/h5/long_video_with_url/:id
     */
    public function h5DetailWithUrl($id)
    {
        $id = intval($id);
        if (!$id) return json(['code' => 1, 'msg' => '视频ID参数无效或视频不存在']);

        $info = Db::name('long_videos')->where('id', $id)->find();
        if (!$info) return json(['code' => 1, 'msg' => '视频不存在']);

        $likeCount = Db::name('video_track')->where('video_id', $id)->where('action', 'like')->count();
        $collectCount = Db::name('video_track')->where('video_id', $id)->where('action', 'collect')->count();
        $playCount = Db::name('video_track')->where('video_id', $id)->where('action', 'view')->count();

        $videoUrl = '';
        if ($info['is_vip']) {
            $userId = getCurrentUserId();
            $isVip = Db::name('users')->where('id', $userId)->value('is_vip');
            if (!$isVip) {
                return json(['code' => 1, 'msg' => '该视频为VIP专属，请购买VIP后观看']);
            }
            $videoUrl = $this->getVideoUrl($id);
        }

        $result = [
            'id' => $info['id'],
            'title' => $info['title'],
            'cover_url' => $info['cover_url'],
            'duration' => $info['duration'],
            'preview_duration' => $info['preview_duration'],
            'vip' => (bool)$info['is_vip'],
            'coin' => (int)($info['gold_required'] ?? 0),
            'goldCoins' => (int)($info['gold_required'] ?? 0),
            'upload_time' => $info['publish_time'],
            'tags' => json_decode($info['tags'], true) ?: [],
            'play' => (int)$info['play_count'] + $playCount,
            'collect' => (int)$info['collect_count'] + $collectCount,
            'like' => (int)$info['like_count'] + $likeCount,
            'status' => (int)$info['status'],
            'parent_id' => $info['parent_id'] ?? '',
            'category_id' => $info['category_id'] ?? '',
            'video_url' => $videoUrl,
        ];

        return json(['code' => 0, 'msg' => '获取成功', 'data' => $result]);
    }

    /**
     * 统一行为埋点接口
     * POST /api/h5/video/track
     */
    public function track(Request $request)
    {
        $videoId = $request->post('video_id/d', 0);
        $action  = $request->post('action/s', '');

        if (!$videoId || !in_array($action, ['view', 'collect', 'like'])) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        $userId = $request->middleware('auth.user_id', 0);
        $ip = $request->ip();

        $exists = Db::name('video_track')
            ->where([
                'video_id' => $videoId,
                'action'   => $action,
                'ip'       => $ip,
            ])
            ->whereTime('create_time', '>=', date('Y-m-d H:i:00', time() - 60))
            ->find();

        if (!$exists) {
            Db::name('video_track')->insert([
                'user_id'    => $userId,
                'video_id'   => $videoId,
                'action'     => $action,
                'ip'         => $ip,
                'create_time'=> date('Y-m-d H:i:s'),
            ]);
        }

        return json(['code' => 0, 'msg' => 'success']);
    }

    /**
     * H5专用：猜你喜欢推荐
     * GET /api/h5/long_videos/guess_you_like
     */
    public function h5GuessYouLike(Request $request)
    {
        $videoId = intval($request->get('video_id'));
        $page = max(1, intval($request->get('page', 1)));
        $limit = max(1, intval($request->get('limit', 8)));
        $offset = ($page - 1) * $limit;

        if (!$videoId) {
            return json(['code' => 1, 'msg' => '缺少video_id', 'data' => []]);
        }

        $video = Db::name('long_videos')->where('id', $videoId)->find();
        if (!$video) {
            return json(['code' => 1, 'msg' => '视频不存在', 'data' => []]);
        }
        $categoryId = $video['category_id'];
        $parentId = $video['parent_id'] ?? 0;
        $title = $video['title'];

        $excludeIds = [$videoId];

        $query = Db::name('long_videos')
            ->where('status', 1)
            ->where('category_id', $categoryId)
            ->where('id', '<>', $videoId)
            ->where('title', '<>', $title)
            ->order('play_count desc, collect_count desc, id desc')
            ->field([
                'id', 'title', 'cover_url', 'duration', 'tags',
                'is_vip', 'gold_required', 'play_count', 'collect_count'
            ]);
        $mainList = $query->limit($offset, $limit)->select()->toArray();
        $excludeIds = array_merge($excludeIds, array_column($mainList, 'id'));

        $moreList = [];
        if (count($mainList) < $limit && $parentId) {
            $moreList = Db::name('long_videos')
                ->alias('lv')
                ->leftJoin('long_video_categories lvc', 'lv.category_id = lvc.id')
                ->where('lv.status', 1)
                ->where('lvc.parent_id', $parentId)
                ->where('lv.category_id', '<>', $categoryId)
                ->whereNotIn('lv.id', $excludeIds)
                ->where('lv.title', '<>', $title)
                ->order('lv.play_count desc, lv.collect_count desc, lv.id desc')
                ->limit($limit - count($mainList))
                ->field([
                    'lv.id', 'lv.title', 'lv.cover_url', 'lv.duration', 'lv.tags',
                    'lv.is_vip', 'lv.gold_required', 'lv.play_count', 'lv.collect_count'
                ])
                ->select()->toArray();
        }

        $list = array_merge($mainList, $moreList);
        foreach ($list as &$v) {
            $v['tags'] = is_string($v['tags']) ? json_decode($v['tags'], true) : ($v['tags'] ?: []);
            $v['vip'] = (bool)$v['is_vip'];
            $v['coin'] = (int)$v['gold_required'];
            $v['play'] = (int)$v['play_count'];
            $v['collect'] = (int)$v['collect_count'];
            unset($v['is_vip'], $v['gold_required'], $v['play_count'], $v['collect_count']);
        }
        unset($v);

        return json([
            'code' => 0,
            'msg' => '猜你喜欢推荐成功',
            'data' => [
                'list' => $list,
                'total' => count($list),
                'current_page' => $page,
                'per_page' => $limit,
                'total_pages' => 1
            ]
        ]);
    }

    /**
     * 播放接口
     * POST /api/long/videos/play
     */
    public function play(Request $request)
    {
        $videoId = intval($request->param('video_id'));
        $userId = $request->param('userId');
        $base62 = new Base62();
        $encodedUserId = $base62->encode($userId);

        if (!$videoId || !$userId) {
            return json(['code' => 1, 'msg' => '参数缺失']);
        }

        $video = Db::name('long_videos')->where('id', $videoId)->find();
        if (!$video) {
            return json(['code' => 1, 'msg' => '视频不存在']);
        }

        $user = Db::name('users')->where('uuid', $userId)->find();
        $canViewVip = 0;
        $canWatchCoin = 0;
        if ($user && $user['vip_card_id']) {
            $vipCard = Db::name('vip_card_type')->where('id', $user['vip_card_id'])->find();
            if ($vipCard) {
                $canViewVip = intval($vipCard['can_view_vip_video'] ?? 0);
                $canWatchCoin = intval($vipCard['can_watch_coin'] ?? 0);
            }
        }

        $isVipCard = $canViewVip === 1 && $canWatchCoin !== 1;
        $isCoinCard = $canWatchCoin === 1 && $canViewVip !== 1;
        $isSuperCard = $canViewVip === 1 && $canWatchCoin === 1;

        $isVipVideo = isset($video['is_vip']) && intval($video['is_vip']) === 1;
        $isCoinVideo = isset($video['gold_required']) && intval($video['gold_required']) > 0;
        $isFreeVideo = !$isVipVideo && !$isCoinVideo;

        $unlocked = Db::name('user_video_unlock')
            ->where('user_id', $userId)
            ->where('video_id', $videoId)
            ->find() ? true : false;

        $today = date('Y-m-d');
        $configs = Db::name('site_config')->column('config_value', 'config_key');
        $maxTimes = intval($configs['free_long_video_daily'] ?? 1);
        $record = Db::name('user_daily_watch_count')
            ->where('uuid', $userId)
            ->where('date', $today)
            ->find();
        $used = $record ? intval($record['long_video_used']) : 0;
        $remaining = max(0, $maxTimes - $used);

        if ($isFreeVideo) {
            return json([
                'code' => 0,
                'msg' => '播放地址获取成功',
                'data' => [
                    'url' => $video['video_url'],
                    'vip' => false,
                    'unlocked' => true,
                    'encoded_user' => $encodedUserId,
                    'remaining' => $remaining,
                ]
            ]);
        }
        if ($isSuperCard) {
            return json([
                'code' => 0,
                'msg' => '播放地址获取成功',
                'data' => [
                    'url' => $video['video_url'],
                    'vip' => true,
                    'unlocked' => true,
                    'encoded_user' => $encodedUserId,
                    'remaining' => $remaining,
                ]
            ]);
        }
        if ($isVipCard && $isVipVideo) {
            return json([
                'code' => 0,
                'msg' => '播放地址获取成功',
                'data' => [
                    'url' => $video['video_url'],
                    'vip' => true,
                    'unlocked' => true,
                    'encoded_user' => $encodedUserId,
                    'remaining' => $remaining,
                ]
            ]);
        }
        if ($isCoinCard && $isCoinVideo) {
            return json([
                'code' => 0,
                'msg' => '播放地址获取成功',
                'data' => [
                    'url' => $video['video_url'],
                    'vip' => false,
                    'unlocked' => true,
                    'encoded_user' => $encodedUserId,
                    'remaining' => $remaining,
                ]
            ]);
        }
        if ($unlocked) {
            return json([
                'code' => 0,
                'msg' => '播放地址获取成功',
                'data' => [
                    'url' => $video['video_url'],
                    'vip' => $isVipVideo,
                    'unlocked' => true,
                    'encoded_user' => $encodedUserId,
                    'remaining' => $remaining,
                ]
            ]);
        }
        if ($remaining <= 0) {
            return json(['code' => 403, 'msg' => '今日试看次数已用完']);
        }
        if ($record) {
            Db::name('user_daily_watch_count')
                ->where('id', $record['id'])
                ->inc('long_video_used')
                ->update();
            $used++;
        } else {
            Db::name('user_daily_watch_count')->insert([
                'uuid' => $userId,
                'date' => $today,
                'long_video_used' => 1,
            ]);
            $used = 1;
        }
        $remaining = max(0, $maxTimes - $used);

        return json([
            'code' => 0,
            'msg' => '播放地址获取成功',
            'data' => [
                'url' => $video['video_url'],
                'vip' => $isVipVideo,
                'unlocked' => false,
                'encoded_user' => $encodedUserId,
                'remaining' => $remaining,
            ]
        ]);
    }

    /**
     * 获取视频播放地址
     */
    private function getVideoUrl($videoId)
    {
        // 这里只是示例，实际应用中可能需要根据业务逻辑生成播放地址
        return 'https://video.example.com/' . $videoId;
    }
}