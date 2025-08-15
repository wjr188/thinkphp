<?php
namespace app\controller\api;

use think\facade\Db;
use think\facade\Log;
use think\Request;

class AdminUserController
{
    public function stats()
    {
        try {
            $total   = Db::name('users')->where('is_deleted',0)->count();
            $normal  = Db::name('users')->where(['vip_status'=>1,'is_deleted'=>0])->count();
            $expired = Db::name('users')->where(['vip_status'=>0,'vip_expired'=>1,'is_deleted'=>0])->count();
            $coinSum = Db::name('users')->where('is_deleted',0)->sum('coin');
            $paid    = Db::name('orders')->where('status','paid')->count();
            $pending = Db::name('orders')->where('status','pending')->count();

            return apiReturn([
                'totalMembers'    => (int)$total,
                'normalMembers'   => (int)$normal,
                'expiredMembers'  => (int)$expired,
                'totalGoldCoins'  => (int)$coinSum,
                'paidOrders'      => (int)$paid,
                'pendingOrders'   => (int)$pending,
            ], '统计数据获取成功');
        } catch (\Exception $e) {
            Log::error('AdminUserController.stats error: ' . $e->getMessage());
            return apiReturn([], '系统出错', 500);
        }
    }

    // 会员列表
    public function list(Request $request)
    {
        $query = Db::name('users')
            ->alias('u')
            ->leftJoin('vip_card_type v', 'u.vip_card_id = v.id')
            ->field('u.id, u.uuid, u.account, u.nickname, u.mobile, u.email, u.avatar, u.coin, u.points, u.user_status, u.vip_status, u.vip_expired, u.vip_expire_time, u.remark, u.create_time, u.register_time, u.is_deleted, u.vip_card_id, u.invite_code, v.name as vip_card_name, v.id as vip_type_id, v.can_watch_coin, v.can_view_vip_video')
            ->where('u.is_deleted', 0);

        // 关键字模糊搜索
        $keyword = trim($request->get('keyword', ''));
        if ($keyword !== '') {
            $query->where(function($q) use ($keyword) {
                $q->whereLike('u.account', "%$keyword%")
                  ->whereOr('u.nickname', 'like', "%$keyword%")
                  ->whereOr('u.mobile', 'like', "%$keyword%")
                  ->whereOr('u.email', 'like', "%$keyword%")
                  ->whereOr('u.id', $keyword)      // 支持ID精确查找
                  ->whereOr('u.uuid', $keyword);   // 支持uuid精确查找
            });
        
        }

        // 会员卡类型筛选
        $no_card = $request->get('no_card');
        $vip_card_id = $request->get('vip_card_id');

        if ($no_card == 1) {
            // 只查未购卡用户
            $query->where(function($q){
                $q->whereNull('u.vip_card_id')->whereOr('u.vip_card_id', 0);
            });
        } elseif ($vip_card_id !== null && $vip_card_id !== '' && $vip_card_id != 0) {
            // 查指定会员卡
            $query->where('u.vip_card_id', (int)$vip_card_id);
        }

        // 会员状态筛选
        if ($request->has('memberStatus')) {
            $status = $request->get('memberStatus');
            if ($status === 'NORMAL') {
                $query->where('u.user_status', 1);
            } elseif ($status === 'DISABLED') {
                $query->where('u.user_status', 0);
            }
        }

        // 到期时间区间筛选
        $expire_start = $request->get('expire_start');
        $expire_end = $request->get('expire_end');
        if ($expire_start && $expire_end) {
            $query->whereBetween('u.vip_expire_time', [$expire_start, $expire_end]);
        }

        // 金币状态筛选
        if ($request->has('coin_status')) {
            $coin_status = $request->get('coin_status');
            if ($coin_status === 'HAS') {
                $query->where('u.coin', '>', 0);
            } elseif ($coin_status === 'ZERO') {
                $query->where('u.coin', '=', 0);
            }
        }

        // 分页前加排序，id倒序（新用户在前）
        $query->order('u.id', 'desc');

        // 分页
        $page = max(1, (int)$request->get('page', 1));
        $pageSize = min(100, (int)$request->get('pageSize', 10));
        $total = $query->count();
        $list = $query->page($page, $pageSize)->select()->toArray();

        // 查询系统配置
        $configMap = Db::name('site_config')->whereIn('config_key', [
            'free_long_video_daily',
            'free_dy_video_daily'
        ])->column('config_value', 'config_key');

        // 解析成 int
        $maxLongFromConfig = (int)($configMap['free_long_video_daily'] ?? 0);
        $maxDyFromConfig = (int)($configMap['free_dy_video_daily'] ?? 0);

        $mappedList = array_map(function($item) use ($maxLongFromConfig, $maxDyFromConfig) {
            $memberStatus = $item['user_status'] == 1 ? 'NORMAL' : 'DISABLED';

            // 查今日已使用
            $todayRecord = Db::name('user_daily_watch_count')
                ->where('uuid', $item['uuid'])
                ->where('date', date('Y-m-d'))
                ->find();

            $longUsed = $todayRecord['long_video_used'] ?? 0;
            $dyUsed = $todayRecord['dy_video_used'] ?? 0;

            // 默认最大次数来自系统配置
            $maxLong = $maxLongFromConfig;
            $maxDy = $maxDyFromConfig;

            // 如果有会员卡，覆盖配置
            if ($item['vip_card_id']) {
                $vipCard = Db::name('vip_card_type')->where('id', $item['vip_card_id'])->find();
                if ($vipCard) {
                    $maxLong = (int)($vipCard['max_long_watch'] ?? $maxLong);
                    $maxDy = (int)($vipCard['max_dy_watch'] ?? $maxDy);
                }
            }

            // 计算剩余
            $longLeft = max($maxLong - $longUsed, 0);
            $dyLeft   = max($maxDy - $dyUsed, 0);

            return [
                'id'               => $item['id'],
                'uuid'             => $item['uuid'],
                'account'          => $item['account'] ?? '',
                'nickname'         => $item['nickname'] ?? '',
                'mobile'           => $item['mobile'] ?? '',
                'email'            => $item['email'] ?? '',
                'vip_card_id'      => $item['vip_card_id'],
                'vip_card_name'    => $item['vip_card_name'] ?? '普通用户（未购卡）',
                'registrationTime' => $item['register_time'] ?? '-',
                'lastLoginTime'    => $item['last_login_time'] ?? '-',
                'points'           => $item['points'] ?? 0,
                'can_watch_coin'   => $item['can_watch_coin'] ?? 0,
                'can_view_vip_video' => $item['can_view_vip_video'] ?? 0,
                'expirationTime'   => $item['vip_expire_time'] ?? '-',
                'user_status'      => $item['user_status'], // 🟢 返回原始字段
                'memberStatus'     => $memberStatus,
                'goldCoins'        => $item['coin'] ?? 0,
                'remark'           => $item['remark'] ?? '',
                'invite_code'      => $item['invite_code'] ?? null,
                'watchCount'       => [
                    'long_video_used' => $longUsed,
                    'long_video_max'  => $maxLong,
                    'long_video_left' => $longLeft,
                    'dy_video_used'   => $dyUsed,
                    'dy_video_max'    => $maxDy,
                    'dy_video_left'   => $dyLeft,
                ],
            ];
        }, $list);

        return apiReturn([
            'list' => $mappedList,
            'total' => $total
        ], '会员列表获取成功');
    }

    // 新增会员
    public function add(Request $request)
    {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        file_put_contents(
            root_path() . 'runtime/member_postdata.log',
            json_encode([
                'time' => date('Y-m-d H:i:s'),
                'raw' => file_get_contents('php://input'),
                'parsed_post' => $data,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL,
            FILE_APPEND
        );

        // 引入随机uuid生成方法（可复制自UserController或写成全局函数）
        function generateUniqueUuid($length = 10) {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $uuid = '';
            for ($i = 0; $i < $length; $i++) {
                $uuid .= $chars[random_int(0, strlen($chars) - 1)];
            }
            // 保证唯一
            while (Db::name('users')->where('uuid', $uuid)->find()) {
                $uuid .= $chars[random_int(0, strlen($chars) - 1)];
            }
            return $uuid;
        }

        try {
            // 只认 vip_card_id，普通用户传 null 或不传
            $insertData = [
                'uuid' => generateUniqueUuid(10), // 这里改为随机哈希
                'account' => $data['account'] ?? '',
                'password' => (isset($data['password']) && $data['password'] !== '') ? safePassword($data['password']) : '',
                'nickname' => $data['nickname'] ?? '未命名用户',
                'mobile' => $data['mobile'] ?? '',
                'email' => $data['email'] ?? '',
                'avatar' => $data['avatar'] ?? '',
                'coin' => $data['coin'] ?? $data['goldCoins'] ?? 0,
                'points' => $data['points'] ?? 0,
                'vip_status' => $data['vip_status'] ?? 0,
                'vip_expired' => $data['vip_expired'] ?? 0,
                'vip_expire_time' => $data['vip_expire_time'] ?? $data['expirationTime'] ?? null,
                'remark' => $data['remark'] ?? '',
                'create_time' => date('Y-m-d H:i:s'),
                'register_time' => date('Y-m-d H:i:s'),
                'is_deleted' => 0,
                'vip_card_id' => isset($data['vip_card_id']) ? (int)$data['vip_card_id'] : null,
                // 自动赋值 vip_status
                'vip_status' => (isset($data['vip_card_id']) && $data['vip_card_id']) ? 1 : 0,
            ];

            // 自动计算有效期
            $vip_card_id = $insertData['vip_card_id'];
            if ($vip_card_id) {
                $vipCard = Db::name('vip_card_type')->where('id', $vip_card_id)->find();
                if ($vipCard) {
                    $start = date('Y-m-d H:i:s');
                    switch (strtoupper($vipCard['duration_unit'])) {
                        case 'DAY':
                            $expire = date('Y-m-d H:i:s', strtotime("+{$vipCard['duration']} days"));
                            break;
                        case 'MONTH':
                            $expire = date('Y-m-d H:i:s', strtotime("+{$vipCard['duration']} months"));
                            break;
                        case 'YEAR':
                            $expire = date('Y-m-d H:i:s', strtotime("+{$vipCard['duration']} years"));
                            break;
                        default: // 永久卡
                            $expire = '2099-12-31 23:59:59';
                    }
                    $insertData['vip_expire_time'] = $expire;
                }
            }

            // 密码调试日志
            file_put_contents(
                root_path() . 'runtime/member_password_debug.log',
                json_encode([
                    'input' => $data['password'] ?? null,
                    'final' => $insertData['password'] ?? null,
                    'time' => date('Y-m-d H:i:s')
                ], JSON_UNESCAPED_UNICODE) . PHP_EOL,
                FILE_APPEND
            );

            Db::name('users')->insert($insertData);

            return apiReturn([
                'uuid'    => $insertData['uuid'],
                'account' => $insertData['account'],
                'nickname'=> $insertData['nickname'],
                'mobile'  => $insertData['mobile'],
                'email'   => $insertData['email'],
                'vip_card_id' => $insertData['vip_card_id'],
                'goldCoins'   => $insertData['coin'],
            ], '新增会员成功');
        } catch (\Exception $e) {
            Log::error('添加会员错误: '.$e->getMessage().' 数据:'.json_encode($data));
            return apiReturn([], '系统错误: '.$e->getMessage(), 500);
        }
    }

    // 编辑会员
    public function update(Request $request)
    {
        try {
            $data = $request->post();
            file_put_contents(
                root_path() . 'runtime/member_update_debug.log',
                json_encode(['step' => '收到原始参数', 'data' => $data, 'time' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE) . PHP_EOL,
                FILE_APPEND
            );
            $id = $data['id'] ?? null;
            Log::info("更新会员数据: " . json_encode($data, JSON_UNESCAPED_UNICODE));
            if (!$id) {
                return apiReturn([], '无效的用户ID', 400);
            }

            // 兼容 goldCoins
            if (isset($data['goldCoins']) && !isset($data['coin'])) {
                $data['coin'] = $data['goldCoins'];
            }

            $allowFields = [
                'account', 'nickname', 'mobile', 'email', 'avatar',
                'coin', 'points', 'vip_status', 'vip_expired', 'vip_expire_time',
                'remark', 'vip_card_id', 'password', 'user_status' // 新增字段
            ];
            $updateData = [];
            foreach ($allowFields as $field) {
                if (array_key_exists($field, $data)) {
                    if ($field === 'vip_card_id') {
                        $updateData[$field] = (int)$data[$field];
                    } elseif ($field === 'password') {
                        if ($data[$field] !== '') {
                            $updateData[$field] = safePassword($data[$field]);
                        }
                    } elseif ($field === 'avatar') {
                        // 如果是空字符串就跳过更新头像
                        if ($data[$field] === '') {
                            continue;
                        } else {
                            $updateData[$field] = $data[$field];
                        }
                    } else {
                        $updateData[$field] = $data[$field];
                    }
                }
            }

            // 确保状态字段能被更新（防止被遗漏）
            if (isset($data['user_status'])) {
                $updateData['user_status'] = (int)$data['user_status'];
            }

            // 自动更新 vip_status
            if (isset($updateData['vip_card_id'])) {
                $updateData['vip_status'] = $updateData['vip_card_id'] ? 1 : 0;
            }

            $updateData['update_time'] = date('Y-m-d H:i:s');

            // 日志
            file_put_contents(
                root_path() . 'runtime/member_update_debug.log',
                json_encode(['id' => $id, 'updateData' => $updateData, 'time' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE) . PHP_EOL,
                FILE_APPEND
            );

            Db::name('users')->where('id', $id)->update($updateData);
            return apiReturn([], '会员更新成功');
        } catch (\Exception $e) {
            Log::error('更新会员错误: ' . $e->getMessage());
            return apiReturn([], '系统错误', 500);
        }
    }

    // 会员详情
    public function detail(Request $request)
    {
        $id = (int)$request->get('id');
        $user = Db::name('users')
    ->alias('u')
    ->leftJoin('vip_card_type v', 'u.vip_card_id = v.id')
    ->field('u.*, v.name as vip_card_name, v.id as vip_card_id, v.can_watch_coin, v.can_view_vip_video')
    ->where('u.id', $id)
    ->find();


        if (!$user) return apiReturn([], '用户不存在', 404);

        // 会员状态判断
        $memberStatus = 'DISABLED';
        if ($user['vip_status'] == 1 && $user['vip_expired'] == 0) {
            $memberStatus = 'NORMAL';
        } elseif ($user['vip_status'] == 0 && $user['vip_expired'] == 1) {
            $memberStatus = 'EXPIRED';
        }
        return apiReturn([
    'id'               => $user['id'],
    'account'          => $user['account'],
    'nickname'         => $user['nickname'],
    'mobile'           => $user['mobile'],
    'email'            => $user['email'],
    'avatar'           => $user['avatar'] ?? '', 
    'vip_card_id'      => $user['vip_card_id'],
    'vip_card_name'    => $user['vip_card_name'] ?? '普通用户（未购卡）',
    'registrationTime' => $user['register_time'] ?? '-',
    'lastLoginTime'    => $user['last_login_time'] ?? '-',
    'expirationTime'   => $user['vip_expire_time'] ?? '-',
    'user_status'      => $user['user_status'], // 🟢 必须加上这一行
    'memberStatus'     => $memberStatus,
    'goldCoins'        => $user['coin'] ?? 0,
    'can_watch_coin'      => $user['can_watch_coin'] ?? 0,
'can_view_vip_video'  => $user['can_view_vip_video'] ?? 0,

    'points'           => $user['points'] ?? 0,
    'remark'           => $user['remark'] ?? '',
], '详情获取成功');

// 🟢 这里补上一个结束大括号！！！
}

    public function getVipCardTypes()
    {
        try {
            $list = Db::name('vip_card_type')
                ->field('id, name, desc, duration, duration_unit, status')
                ->where('status', 1)
                ->select()
                ->toArray();
            $response_data = [];
            foreach ($list as $item) {
                $response_data[] = [
                    'id'            => $item['id'],
                    'name'          => $item['name'],
                    'desc'          => $item['desc'] ?? '',
                    'duration'      => $item['duration'] ?? null,
                    'duration_unit' => $item['duration_unit'] ?? null,
                    'status'        => $item['status'] ?? null,
                ];
            }
            return apiReturn(['data' => $response_data], '会员卡类型获取成功');
        } catch (\Exception $e) {
            Log::error('AdminUserController.getVipCardTypes error: ' . $e->getMessage());
            return apiReturn(new \stdClass(), '系统出错', 500);
        }
    }

    public function batchUpdate(Request $request)
    {
        try {
            $ids = $request->post('ids/a');
            $action = $request->post('action');
            
            if (empty($ids) || !is_array($ids)) {
                throw new \Exception('无效的用户ID列表');
            }
            if (empty($action)) {
                throw new \Exception('无效的操作类型');
            }

            $data = [];
            if ($action === 'enable') {
                $data['user_status'] = 1;
            } elseif ($action === 'disable') {
                $data['user_status'] = 0;
            } elseif ($action === 'delete') {
                $data['is_deleted'] = 1;
            } else {
                throw new \Exception('无效的操作类型');
            }
            $data['update_time'] = date('Y-m-d H:i:s');
            $result = Db::name('users')->whereIn('id', $ids)->update($data);
            if ($result === false) {
                throw new \Exception('数据库更新失败');
            }
            return apiReturn(new \stdClass(), '批量操作成功');
        } catch (\Exception $e) {
            Log::error('AdminUserController.batchUpdate error: ' . $e->getMessage());
            return apiReturn(new \stdClass(), '系统出错: ' . $e->getMessage(), 500);
        }
    }

    public function deleteOne(Request $request)
    {
        try {
            $id = $request->post('id');
            if (empty($id)) {
                return apiReturn(new \stdClass(), '无效的用户ID', 400);
            }
            $result = Db::name('users')->delete($id);
            if ($result === false) {
                throw new \Exception('数据库更新失败');
            }
            return apiReturn(new \stdClass(), '会员已删除');
        } catch (\Exception $e) {
            Log::error('AdminUserController.deleteOne error: ' . $e->getMessage());
            return apiReturn(new \stdClass(), '系统出错', 500);
        }
    }

    public function coinStats()
    {
        try {
            $sum = Db::name('users')->sum('coin');
            return apiReturn(['coin_sum'=>(int)$sum], '金币统计获取成功');
        } catch (\Exception $e) {
            Log::error('AdminUserController.coinStats error: ' . $e->getMessage());
            return apiReturn([], '系统出错', 500);
        }
    }
public function pointsStats()
{
    try {
        $sum = Db::name('users')->sum('points');
        return apiReturn(['points_sum'=>(int)$sum], '积分统计获取成功');
    } catch (\Exception $e) {
        Log::error('AdminUserController.pointsStats error: ' . $e->getMessage());
        return apiReturn([], '系统出错', 500);
    }
}

    public function orderStats()
    {
        try {
            $paid    = Db::name('orders')->where('status','paid')->count();
            $pending = Db::name('orders')->where('status','pending')->count();
            return apiReturn([
                'paid'=>(int)$paid,
                'pending'=>(int)$pending
            ], '订单统计获取成功');
        } catch (\Exception $e) {
            Log::error('AdminUserController.orderStats error: ' . $e->getMessage());
            return apiReturn([], '系统出错', 500);
        }
    }
}

function safePassword($pwd) {
    return (preg_match('/^[a-f0-9]{32}$/i', $pwd)) ? $pwd : md5($pwd);
}