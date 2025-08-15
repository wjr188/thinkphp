<?php
declare(strict_types=1);

namespace app\controller\api;

use think\Request;
use think\facade\Db;

class WatchRecordController
{
    // 每天只允许试看一部
    protected $maxFreeCount = 1;

    /**
     * 判断设备/用户今天还能否试看
     * GET参数: uuid
     */
    public function canWatch(Request $request)
    {
        $uuid = $request->get('uuid');
        $today = date('Y-m-d');

        if (!$uuid) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        $record = Db::name('device_watch_records')
            ->where('uuid', $uuid)
            ->where('last_watch_time', $today)
            ->find();

        $watchCount = $record['watch_count'] ?? 0;
        if ($watchCount >= $this->maxFreeCount) {
            return json([
                'code' => 1,
                'msg' => '试看次数已用完',
                'remaining' => 0
            ]);
        }

        $remaining = $this->maxFreeCount - $watchCount;
        return json([
            'code' => 0,
            'msg' => '允许试看',
            'remaining' => $remaining
        ]);
    }

    /**
     * 记录设备/用户观看行为
     * POST参数: uuid
     */
    public function recordWatch(Request $request)
    {
        $uuid = $request->post('uuid');
        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');

        if (!$uuid) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        $record = Db::name('device_watch_records')
            ->where('uuid', $uuid)
            ->where('last_watch_time', $today)
            ->find();

        $watchCount = $record['watch_count'] ?? 0;
        $maxFreeCount = $this->maxFreeCount;

        if ($watchCount >= $maxFreeCount) {
            return json([
                'code' => 1,
                'msg' => '试看次数已用完',
                'remaining' => 0
            ]);
        }

        if ($record) {
            $res = Db::name('device_watch_records')->where('id', $record['id'])->update([
                'watch_count' => $record['watch_count'] + 1,
                'updated_at' => $now,
            ]);
            file_put_contents(
                runtime_path() . 'watch_debug.log',
                date('Y-m-d H:i:s') . " update result: {$res}\n",
                FILE_APPEND
            );
        } else {
            try {
                $res = Db::name('device_watch_records')->insert([
                    'uuid' => $uuid,
                    'watch_count' => 1,
                    'last_watch_time' => $today,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'video_id' => 0,
                ]);
                file_put_contents(
                    runtime_path() . 'watch_debug.log',
                    date('Y-m-d H:i:s') . " insert result: {$res}\n",
                    FILE_APPEND
                );
            } catch (\Exception $e) {
                file_put_contents(
                    runtime_path() . 'watch_debug.log',
                    date('Y-m-d H:i:s') . " insert error: {$e->getMessage()}\n",
                    FILE_APPEND
                );
            }
        }
        file_put_contents(
            runtime_path() . 'watch_debug.log',
            date('Y-m-d H:i:s') . " recordWatch uuid={$uuid}\n",
            FILE_APPEND
        );

        $remaining = max(0, $maxFreeCount - $watchCount - 1);
        return json(['code' => 0, 'msg' => '记录成功', 'remaining' => $remaining]);
    }
}
