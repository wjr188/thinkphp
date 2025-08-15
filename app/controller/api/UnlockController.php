<?php
declare(strict_types=1);

namespace app\controller\api;

use think\Request;
use think\facade\Db;
use Firebase\JWT\JWT;

class UnlockController
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
        // 这里不用 Key
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

    // 查询某漫画用户已解锁的章节ID列表
    public function unlockedChapters(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
        $userId = $user['uuid'];

        $comicId = (int)$request->get('comic_id', 0);
        if ($comicId <= 0) {
            return json(['code' => 1, 'msg' => '缺少漫画ID']);
        }

        $chapterIds = Db::name('user_video_unlock')
            ->alias('u')
            ->leftJoin('comic_chapters c', 'u.video_id = c.id')
            ->where('u.user_id', $userId)
            ->where('u.type', 2)
            ->where('c.manga_id', $comicId)
            ->column('u.video_id');
// === 新增VIP/金币全免判断 ===
    $canViewVipVideo = 0;
    $canWatchCoin = 0;
    if (!empty($user['vip_card_id'])) {
        $vipCardType = Db::name('vip_card_type')->where('id', $user['vip_card_id'])->find();
        if ($vipCardType) {
            $canViewVipVideo = intval($vipCardType['can_view_vip_video'] ?? 0);
            $canWatchCoin    = intval($vipCardType['can_watch_coin'] ?? 0);
        }
    }

    return json([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'unlocked' => array_values($chapterIds),
            'can_view_vip_video' => $canViewVipVideo,
            'can_watch_coin'     => $canWatchCoin,
        ]
    ]);
}

    // 解锁单个长视频，扣金币
    public function longVideo(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
        $userId = $user['uuid'];

        $videoId = (int)$request->post('video_id', 0);
        if ($videoId <= 0) return json(['code' => 1, 'msg' => '参数错误']);

        $exists = Db::name('user_video_unlock')
            ->where('user_id', $userId)
            ->where('video_id', $videoId)
            ->where('expire_time', '>', date('Y-m-d H:i:s'))
            ->find();
        if ($exists) return json(['code' => 0, 'msg' => '已解锁，无需重复购买']);

        $video = Db::name('long_videos')->where('id', $videoId)->find();
        if (!$video) return json(['code' => 1, 'msg' => '视频不存在']);

        $needCoin = (int)$video['gold_required'];
        if ($needCoin <= 0) return json(['code' => 0, 'msg' => '该视频不需要金币']);
        if ($user['coin'] < $needCoin) return json(['code' => 2, 'msg' => '金币不足']);

        try {
            Db::startTrans();
            Db::name('users')->where('uuid', $userId)->dec('coin', $needCoin)->update();
            Db::name('user_video_unlock')->insert([
                'user_id' => $userId,
                'video_id' => $videoId,
                'unlock_time' => date('Y-m-d H:i:s'),
                'expire_time' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'type' => 1,
            ]);
            Db::name('user_coin_log')->insert([
                'uuid' => $userId,
                'coin' => -$needCoin,
                'type' => 1,
                'scene' => '解锁长视频',
                'video_id' => $videoId,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '解锁失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '解锁成功']);
    }

    // 解锁漫画章节
    public function comicChapter(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
        $userId = $user['uuid'];

        $chapterId = (int)$request->post('chapter_id', 0);
        if ($chapterId <= 0) return json(['code' => 1, 'msg' => '参数错误']);

        $exists = Db::name('user_video_unlock')
            ->where('user_id', $userId)
            ->where('video_id', $chapterId)
            ->find();
        if ($exists) return json(['code' => 0, 'msg' => '已解锁，无需重复购买']);

        $chapter = Db::name('comic_chapters')->where('id', $chapterId)->find();
        if (!$chapter) return json(['code' => 1, 'msg' => '章节不存在']);
        $needCoin = (int)$chapter['coin'];
        if ($needCoin <= 0) return json(['code' => 0, 'msg' => '该章节免费，无需购买']);
        if ($user['coin'] < $needCoin) return json(['code' => 2, 'msg' => '金币不足']);

        try {
            Db::startTrans();
            Db::name('users')->where('uuid', $userId)->dec('coin', $needCoin)->update();
            Db::name('user_video_unlock')->insert([
                'user_id' => $userId,
                'video_id' => $chapterId,
                'unlock_time' => date('Y-m-d H:i:s'),
                'expire_time' => null,
                'type' => 2,
            ]);
            Db::name('user_coin_log')->insert([
                'uuid' => $userId,
                'coin' => -$needCoin,
                'type' => 2,
                'scene' => '解锁漫画章节',
                'video_id' => $chapterId,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '解锁失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '解锁成功']);
    }

    // 解锁小说章节
    public function novelChapter(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
        $userId = $user['uuid'];

        $chapterId = (int)$request->post('chapter_id', 0);
        if ($chapterId <= 0) return json(['code' => 1, 'msg' => '参数错误']);

        $exists = Db::name('user_video_unlock')
            ->where('user_id', $userId)
            ->where('video_id', $chapterId)
            ->where('type', 3)
            ->find();
        if ($exists) return json(['code' => 0, 'msg' => '已解锁，无需重复购买']);

        $chapter = Db::name('text_novel_chapter')->where('id', $chapterId)->find();
        if (!$chapter) return json(['code' => 1, 'msg' => '章节不存在']);
        $needCoin = (int)$chapter['coin'];
        if ($needCoin <= 0) return json(['code' => 0, 'msg' => '该章节免费，无需购买']);
        if ($user['coin'] < $needCoin) return json(['code' => 2, 'msg' => '金币不足']);

        try {
            Db::startTrans();
            Db::name('users')->where('uuid', $userId)->dec('coin', $needCoin)->update();
            Db::name('user_video_unlock')->insert([
                'user_id' => $userId,
                'video_id' => $chapterId,
                'unlock_time' => date('Y-m-d H:i:s'),
                'expire_time' => null,
                'type' => 3,
            ]);
            Db::name('user_coin_log')->insert([
                'uuid' => $userId,
                'coin' => -$needCoin,
                'type' => 3,
                'scene' => '解锁小说章节',
                'video_id' => $chapterId,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '解锁失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '解锁成功']);
    }

    // 查询某小说用户已解锁的章节ID列表
    public function unlockedNovelChapters(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
        $userId = $user['uuid'];

        $novelId = (int)$request->get('novel_id', 0);
        if ($novelId <= 0) {
            return json(['code' => 1, 'msg' => '缺少小说ID']);
        }
        $chapterIds = Db::name('user_video_unlock')
            ->alias('u')
            ->leftJoin('text_novel_chapter c', 'u.video_id = c.id')
            ->where('u.user_id', $userId)
            ->where('u.type', 3)
            ->where('c.novel_id', $novelId)
            ->column('u.video_id');
         // === 新增VIP/金币全免判断 ===
    $canViewVipVideo = 0;
    $canWatchCoin = 0;
    if (!empty($user['vip_card_id'])) {
        $vipCardType = Db::name('vip_card_type')->where('id', $user['vip_card_id'])->find();
        if ($vipCardType) {
            $canViewVipVideo = intval($vipCardType['can_view_vip_video'] ?? 0);
            $canWatchCoin    = intval($vipCardType['can_watch_coin'] ?? 0);
        }
    }

    return json([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'unlocked' => array_values($chapterIds),
            'can_view_vip_video' => $canViewVipVideo,
            'can_watch_coin'     => $canWatchCoin,
        ]
    ]);
}

    // 整部解锁漫画
    public function comicWhole(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
        $userId = $user['uuid'];

        $comicId = (int)$request->post('comic_id', 0);
        if ($comicId <= 0) return json(['code' => 1, 'msg' => '缺少漫画ID']);

        $allPayChapters = Db::name('comic_chapters')
            ->where('manga_id', $comicId)
            ->where('coin', '>', 0)
            ->field('id,coin')
            ->select()
            ->toArray();
        if (!$allPayChapters) return json(['code' => 1, 'msg' => '本漫画无收费章节，无需解锁']);

        $unlocked = Db::name('user_video_unlock')
            ->where('user_id', $userId)
            ->where('type', 2)
            ->whereIn('video_id', array_column($allPayChapters, 'id'))
            ->column('video_id');
        $unlocked = array_map('strval', $unlocked);

        $unlockChapters = [];
        $unlockCoinSum = 0;
        foreach ($allPayChapters as $row) {
            $id = $row['id'];
            $coin = $row['coin'];
            if (!in_array((string)$id, $unlocked)) {
                $unlockChapters[] = $id;
                $unlockCoinSum += intval($coin);
            }
        }
        if (!$unlockChapters) return json(['code' => 0, 'msg' => '已全部解锁，无需重复购买']);
        $payCoin = (int)round($unlockCoinSum * 0.8);

        if ($user['coin'] < $payCoin) return json(['code' => 2, 'msg' => '金币不足']);

        try {
            Db::startTrans();
            Db::name('users')->where('uuid', $userId)->dec('coin', $payCoin)->update();

            $now = date('Y-m-d H:i:s');
            $rows = [];
            foreach ($unlockChapters as $cid) {
                $rows[] = [
                    'user_id' => $userId,
                    'video_id' => $cid,
                    'unlock_time' => $now,
                    'expire_time' => null,
                    'type' => 2
                ];
            }
            if ($rows) Db::name('user_video_unlock')->insertAll($rows);

            Db::name('user_coin_log')->insert([
                'uuid' => $userId,
                'coin' => -$payCoin,
                'type' => 2,
                'scene' => '整部解锁漫画',
                'video_id' => $comicId,
                'create_time' => $now,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '解锁失败：' . $e->getMessage()]);
        }
        return json([
            'code'        => 0,
            'msg'         => '整部解锁成功',
            'pay_coin'    => $payCoin,
            'origin_coin' => $unlockCoinSum,
            'save_coin'   => $unlockCoinSum - $payCoin,
            'discount'    => '8折',
            'unlocked'    => count($unlockChapters),
        ]);
    }

    // 整本解锁小说
    public function novelWhole(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
        $userId = $user['uuid'];

        $novelId = (int)$request->post('novel_id', 0);
        if ($novelId <= 0) return json(['code' => 1, 'msg' => '缺少小说ID']);

        $allPayChapters = Db::name('text_novel_chapter')
            ->where('novel_id', $novelId)
            ->where('coin', '>', 0)
            ->field('id,coin')
            ->select()
            ->toArray();
        if (!$allPayChapters) return json(['code' => 1, 'msg' => '本小说无收费章节，无需解锁']);

        $unlocked = Db::name('user_video_unlock')
            ->where('user_id', $userId)
            ->where('type', 3)
            ->whereIn('video_id', array_column($allPayChapters, 'id'))
            ->column('video_id');
        $unlocked = array_map('strval', $unlocked);

        $unlockChapters = [];
        $unlockCoinSum = 0;
        foreach ($allPayChapters as $row) {
            $id = $row['id'];
            $coin = $row['coin'];
            if (!in_array((string)$id, $unlocked)) {
                $unlockChapters[] = $id;
                $unlockCoinSum += intval($coin);
            }
        }
        if (!$unlockChapters) return json(['code' => 0, 'msg' => '已全部解锁，无需重复购买']);
        $payCoin = (int)round($unlockCoinSum * 0.8);

        if ($user['coin'] < $payCoin) return json(['code' => 2, 'msg' => '金币不足']);

        try {
            Db::startTrans();
            Db::name('users')->where('uuid', $userId)->dec('coin', $payCoin)->update();

            $now = date('Y-m-d H:i:s');
            $rows = [];
            foreach ($unlockChapters as $cid) {
                $rows[] = [
                    'user_id' => $userId,
                    'video_id' => $cid,
                    'unlock_time' => $now,
                    'expire_time' => null,
                    'type' => 3
                ];
            }
            if ($rows) Db::name('user_video_unlock')->insertAll($rows);

            Db::name('user_coin_log')->insert([
                'uuid' => $userId,
                'coin' => -$payCoin,
                'type' => 3,
                'scene' => '整本解锁小说',
                'video_id' => $novelId,
                'create_time' => $now,
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '解锁失败：' . $e->getMessage()]);
        }
        return json([
            'code'        => 0,
            'msg'         => '整本解锁成功',
            'pay_coin'    => $payCoin,
            'origin_coin' => $unlockCoinSum,
            'save_coin'   => $unlockCoinSum - $payCoin,
            'discount'    => '8折',
            'unlocked'    => count($unlockChapters),
        ]);
    }
    // 解锁有声小说章节
public function audioNovelChapter(Request $request)
{
    $uuidResult = $this->getUuidFromJwt($request);
    if (isset($uuidResult['error'])) return $uuidResult['error'];
    $uuid = $uuidResult['uuid'];
    $user = Db::name('users')->where('uuid', $uuid)->find();
    if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
    $userId = $user['uuid'];

    $chapterId = (int)$request->post('chapter_id', 0);
    if ($chapterId <= 0) return json(['code' => 1, 'msg' => '参数错误']);

    $exists = Db::name('user_video_unlock')
        ->where('user_id', $userId)
        ->where('video_id', $chapterId)
        ->where('type', 4)
        ->find();
    if ($exists) return json(['code' => 0, 'msg' => '已解锁，无需重复购买']);

    $chapter = Db::name('audio_novel_chapter')->where('id', $chapterId)->find();
    if (!$chapter) return json(['code' => 1, 'msg' => '章节不存在']);
    $needCoin = (int)($chapter['coin'] ?? 0);
    if ($needCoin <= 0) return json(['code' => 0, 'msg' => '该章节免费，无需购买']);
    if ($user['coin'] < $needCoin) return json(['code' => 2, 'msg' => '金币不足']);

    try {
        Db::startTrans();
        Db::name('users')->where('uuid', $userId)->dec('coin', $needCoin)->update();
        Db::name('user_video_unlock')->insert([
            'user_id'     => $userId,
            'video_id'    => $chapterId,
            'unlock_time' => date('Y-m-d H:i:s'),
            'expire_time' => null,
            'type'        => 4, // 有声小说
        ]);
        Db::name('user_coin_log')->insert([
            'uuid'        => $userId,
            'coin'        => -$needCoin,
            'type'        => 4,
            'scene'       => '解锁有声小说章节',
            'video_id'    => $chapterId,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
        Db::commit();
    } catch (\Exception $e) {
        Db::rollback();
        return json(['code' => 1, 'msg' => '解锁失败：' . $e->getMessage()]);
    }
    return json(['code' => 0, 'msg' => '解锁成功']);
}
// 查询某有声小说用户已解锁的章节ID列表
public function unlockedAudioNovelChapters(Request $request)
{
    $uuidResult = $this->getUuidFromJwt($request);
    if (isset($uuidResult['error'])) return $uuidResult['error'];
    $uuid = $uuidResult['uuid'];
    $user = Db::name('users')->where('uuid', $uuid)->find();
    if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
    $userId = $user['uuid'];

    $audioNovelId = (int)$request->get('audio_novel_id', 0);
    if ($audioNovelId <= 0) {
        return json(['code' => 1, 'msg' => '缺少有声小说ID']);
    }
    $chapterIds = Db::name('user_video_unlock')
        ->alias('u')
        ->leftJoin('audio_novel_chapter c', 'u.video_id = c.id')
        ->where('u.user_id', $userId)
        ->where('u.type', 4)
        ->where('c.novel_id', $audioNovelId)
        ->column('u.video_id');

    // === 关键：动态查VIP全免、金币全免 ===
    $canViewVipVideo = 0;
    $canWatchCoin = 0;
    // 如果用户有VIP卡，查卡类型表
    if (!empty($user['vip_card_id'])) {
        $vipCardType = Db::name('vip_card_type')->where('id', $user['vip_card_id'])->find();
        if ($vipCardType) {
            $canViewVipVideo = intval($vipCardType['can_view_vip_video'] ?? 0);
            $canWatchCoin    = intval($vipCardType['can_watch_coin'] ?? 0);
        }
    }
    // 你可以在这里加别的全免活动逻辑，比如运营特权、运营活动、特定白名单都可以

    return json([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'unlocked' => array_values($chapterIds),
            'can_view_vip_video' => $canViewVipVideo,
            'can_watch_coin'     => $canWatchCoin,
        ]
    ]);
}
/**
 * 解锁动漫视频，扣金币
 * POST /api/h5/unlock/anime_video
 */
public function animeVideo(Request $request)
{
    $uuidResult = $this->getUuidFromJwt($request);
    if (isset($uuidResult['error'])) return $uuidResult['error'];
    $uuid = $uuidResult['uuid'];
    $user = Db::name('users')->where('uuid', $uuid)->find();
    if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
    $userId = $user['uuid'];

    $videoId = (int)$request->post('video_id', 0);
    if ($videoId <= 0) return json(['code' => 1, 'msg' => '参数错误']);

    // 查是否已解锁且未过期
    $exists = Db::name('user_video_unlock')
        ->where('user_id', $userId)
        ->where('video_id', $videoId)
        ->where('type', 5) // 动漫视频 type=5
        ->where('expire_time', '>', date('Y-m-d H:i:s'))
        ->find();
    if ($exists) return json(['code' => 0, 'msg' => '已解锁，无需重复购买']);

    // 查询动漫视频
    $video = Db::name('anime_videos')->where('id', $videoId)->find();
    if (!$video) return json(['code' => 1, 'msg' => '动漫视频不存在']);

    // 需要金币
    $needCoin = 0;
    if (isset($video['gold_required'])) {
        $needCoin = (int)$video['gold_required'];
    } elseif (isset($video['coin'])) {
        $needCoin = (int)$video['coin'];
    }

    if ($needCoin <= 0) return json(['code' => 0, 'msg' => '该视频不需要金币']);
    if ($user['coin'] < $needCoin) return json(['code' => 2, 'msg' => '金币不足']);

    try {
        Db::startTrans();
        Db::name('users')->where('uuid', $userId)->dec('coin', $needCoin)->update();
        Db::name('user_video_unlock')->insert([
            'user_id' => $userId,
            'video_id' => $videoId,
            'unlock_time' => date('Y-m-d H:i:s'),
            'expire_time' => date('Y-m-d H:i:s', strtotime('+7 days')), // 可改为 null 视需求
            'type' => 5, // 动漫视频 type=5
        ]);
        Db::name('user_coin_log')->insert([
            'uuid' => $userId,
            'coin' => -$needCoin,
            'type' => 5,
            'scene' => '解锁动漫视频',
            'video_id' => $videoId,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
        Db::commit();
    } catch (\Exception $e) {
        Db::rollback();
        return json(['code' => 1, 'msg' => '解锁失败：' . $e->getMessage()]);
    }
    return json(['code' => 0, 'msg' => '解锁成功']);
}

    /**
     * POST /api/h5/unlock/douyin_video
     * 解锁抖音短视频，扣金币
     */
    public function douyinVideo(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
        $userId = $user['uuid'];

        $videoId = (int)$request->post('video_id', 0);
        if ($videoId <= 0) return json(['code' => 1, 'msg' => '参数错误']);

        $exists = Db::name('user_video_unlock')
            ->where('user_id', $userId)
            ->where('video_id', $videoId)
            ->where('expire_time', '>', date('Y-m-d H:i:s'))
            ->where('type', 7) // 抖音视频 type=7
            ->find();
        if ($exists) return json(['code' => 0, 'msg' => '已解锁，无需重复购买']);

        $video = Db::name('douyin_videos')->where('id', $videoId)->find();
        if (!$video) return json(['code' => 1, 'msg' => '视频不存在']);
        $needCoin = (int)$video['gold'];
        if ($needCoin <= 0) return json(['code' => 0, 'msg' => '该视频不需要金币']);
        if ($user['coin'] < $needCoin) return json(['code' => 2, 'msg' => '金币不足']);

        try {
            Db::startTrans();
            Db::name('users')->where('uuid', $userId)->dec('coin', $needCoin)->update();
            Db::name('user_video_unlock')->insert([
                'user_id' => $userId,
                'video_id' => $videoId,
                'unlock_time' => date('Y-m-d H:i:s'),
                'expire_time' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'type' => 7, // 抖音视频 type=7
            ]);
            Db::name('user_coin_log')->insert([
                'uuid' => $userId,
                'coin' => -$needCoin,
                'type' => 7,
                'scene' => '解锁短视频',
                'video_id' => $videoId,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '解锁失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '解锁成功']);
    }

    /**
     * POST /api/h5/unlock/darknet_video
     * 解锁暗网视频，扣金币
     */
    public function darknetVideo(Request $request)
    {
        $uuidResult = $this->getUuidFromJwt($request);
        if (isset($uuidResult['error'])) return $uuidResult['error'];
        $uuid = $uuidResult['uuid'];
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
        $userId = $user['uuid'];

        $videoId = (int)$request->post('video_id', 0);
        if ($videoId <= 0) return json(['code' => 1, 'msg' => '参数错误']);

        $exists = Db::name('user_video_unlock')
            ->where('user_id', $userId)
            ->where('video_id', $videoId)
            ->where('type', 6) // 暗网视频 type=6
            ->where('expire_time', '>', date('Y-m-d H:i:s'))
            ->find();
        if ($exists) return json(['code' => 0, 'msg' => '已解锁，无需重复购买']);

        $video = Db::name('darknet_video')->where('id', $videoId)->find();
        if (!$video) return json(['code' => 1, 'msg' => '视频不存在']);
        $needCoin = (int)$video['gold'];
        if ($needCoin <= 0) return json(['code' => 0, 'msg' => '该视频不需要金币']);
        if ($user['coin'] < $needCoin) return json(['code' => 2, 'msg' => '金币不足']);

        try {
            Db::startTrans();
            Db::name('users')->where('uuid', $userId)->dec('coin', $needCoin)->update();
            Db::name('user_video_unlock')->insert([
                'user_id' => $userId,
                'video_id' => $videoId,
                'unlock_time' => date('Y-m-d H:i:s'),
                'expire_time' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'type' => 6, // 暗网视频 type=6
            ]);
            Db::name('user_coin_log')->insert([
                'uuid' => $userId,
                'coin' => -$needCoin,
                'type' => 6,
                'scene' => '解锁暗网视频',
                'video_id' => $videoId,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '解锁失败：' . $e->getMessage()]);
        }
        return json(['code' => 0, 'msg' => '解锁成功']);
    }
    /**
 * 解锁 Star/OnlyFans 视频，扣金币
 * POST /api/h5/unlock/star_video
 */
public function starVideo(Request $request)
{
    // 独立类型，避免与动漫(5)等冲突
    $STAR_UNLOCK_TYPE = 8;

    // 1) 鉴权
    $uuidResult = $this->getUuidFromJwt($request);
    if (isset($uuidResult['error'])) return $uuidResult['error'];
    $uuid = $uuidResult['uuid'];
    $user = Db::name('users')->where('uuid', $uuid)->find();
    if (!$user) return json(['code' => 401, 'msg' => '登录已失效，请重新登录']);
    $userId = $user['uuid'];

    // 2) 取参
    $videoId = (int)$request->post('video_id', 0);
    if ($videoId <= 0) return json(['code' => 1, 'msg' => '参数错误']);

    // 3) 是否已解锁
    $exists = Db::name('user_video_unlock')
        ->where('user_id', $userId)
        ->where('video_id', $videoId)
        ->where('type', $STAR_UNLOCK_TYPE)
        ->where('expire_time', '>', date('Y-m-d H:i:s'))
        ->find();
    if ($exists) return json(['code' => 0, 'msg' => '已解锁，无需重复购买']);

    // 4) 查 Star 媒体（只允许视频）
    $media = Db::name('onlyfans_media')
        ->where('id', $videoId)
        ->where('status', 1)
        ->where('type', 'video')
        ->find();
    if (!$media) return json(['code' => 1, 'msg' => '视频不存在或不是视频类型']);

    $needCoin = (int)($media['coin'] ?? 0);
    if ($needCoin <= 0) return json(['code' => 0, 'msg' => '该视频不需要金币']);
    if ($user['coin'] < $needCoin) return json(['code' => 2, 'msg' => '金币不足']);

    // 5) 扣币 + 记录解锁 + 记账
    try {
        Db::startTrans();

        Db::name('users')
            ->where('uuid', $userId)
            ->dec('coin', $needCoin)
            ->update();

        Db::name('user_video_unlock')->insert([
            'user_id'     => $userId,
            'video_id'    => $videoId,
            'unlock_time' => date('Y-m-d H:i:s'),
            'expire_time' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'type'        => $STAR_UNLOCK_TYPE, // 8
        ]);

        Db::name('user_coin_log')->insert([
            'uuid'        => $userId,
            'coin'        => -$needCoin,
            'type'        => $STAR_UNLOCK_TYPE, // 8
            'scene'       => '解锁Star视频',
            'video_id'    => $videoId,
            'create_time' => date('Y-m-d H:i:s'),
        ]);

        Db::commit();
    } catch (\Exception $e) {
        Db::rollback();
        return json(['code' => 1, 'msg' => '解锁失败：' . $e->getMessage()]);
    }

    return json(['code' => 0, 'msg' => '解锁成功']);
}

}
