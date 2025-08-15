<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;

class CoinController
{
    // 增加金币
    public function increase(Request $request)
    {
        $userId = $request->post('user_id');
        $amount = intval($request->post('amount'));
        if (!$userId || $amount <= 0) return json(['code'=>400, 'msg'=>'参数错误']);
        $res = Db::name('users')->where('uuid', $userId)->inc('coin', $amount)->update();
        if ($res === false) return json(['code'=>500, 'msg'=>'增加金币失败']);
        $user = Db::name('users')->where('uuid', $userId)->find();
        return json(['code'=>0, 'msg'=>'ok', 'data'=>['goldCoins'=>(int)$user['coin']]]);
    }

    // 扣减金币
    public function decrease(Request $request)
    {
        $userId = $request->post('user_id');
        $amount = intval($request->post('amount'));
        $videoId = $request->post('video_id');

        if (!$userId || $amount <= 0 || !$videoId) return json(['code'=>400, 'msg'=>'参数错误']);

        // 查找用户
        $user = Db::name('users')->where('uuid', $userId)->find();
        if (!$user) return json(['code'=>404, 'msg'=>'用户不存在']);

        // 查找视频
        $video = Db::name('long_videos')->where('id', $videoId)->find();
        if (!$video || empty($video['gold_required']) || $video['gold_required'] <= 0) {
            return json(['code'=>404, 'msg'=>'视频不存在或不是金币视频']);
        }

        // 判断金币是否足够
        if ($user['coin'] < $video['gold_required']) {
            return json(['code'=>402, 'msg'=>'金币不足']);
        }

        // 判断是否已解锁
        $unlocked = Db::name('user_video_unlock')->where(['user_id'=>$userId, 'video_id'=>$videoId])->find();
        if ($unlocked) {
            return json(['code'=>0, 'msg'=>'已解锁', 'data'=>['goldCoins'=>(int)$user['coin'], 'unlocked'=>true]]);
        }

        // 扣减金币
        $res = Db::name('users')->where('uuid', $userId)->dec('coin', $video['gold_required'])->update();
        if ($res === false) return json(['code'=>500, 'msg'=>'扣减金币失败']);

        // 写入解锁关系
        Db::name('user_video_unlock')->insert([
            'user_id' => $userId,
            'video_id' => $videoId,
            'unlock_time' => date('Y-m-d H:i:s')
        ]);

        $user = Db::name('users')->where('uuid', $userId)->find();
        return json(['code'=>0, 'msg'=>'解锁成功', 'data'=>['goldCoins'=>(int)$user['coin'], 'unlocked'=>true]]);
    }
}