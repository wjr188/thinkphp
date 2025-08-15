<?php
declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\Request;
use Firebase\JWT\JWT;


class UserController extends BaseController
{

    // Management Backend Interface (no changes here)
    public function me()
    {
        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'userId'   => 1,
                'username' => 'admin',
                'nickname' => '管理员',
                'avatar'   => '',
                'roles'    => ['admin'],
                'perms'    => ['*'],               
            ]
        ]);
    }

    /**
     * H5 User Login
     * Authenticates a user and returns their detailed profile and VIP status.
     *
     * @param Request $request
     * @return \think\Response|\think\response\Json
     */
    public function login(Request $request)
{
    file_put_contents(
        runtime_path() . 'token_debug.log',
        date('Y-m-d H:i:s') . " login接口被访问\n",
        FILE_APPEND
    );

    $account  = $request->post('account');
    $password = $request->post('password');

    $user = Db::name('users')->where('account', $account)->find();

    if (!$user) {
        return json(['code' => 400, 'msg' => '账号不存在']);
    }
    if ($user['password'] !== md5($password)) {
        return json(['code' => 400, 'msg' => '密码错误']);
    }
    if ($user['user_status'] == 0) { // 检查用户状态
        return json(['code' => 403, 'msg' => '账号已被禁用']);
    }
$token = $this->generateToken($user['uuid'], 'login');


    $vip_status      = $user['vip_status'] ?? 0;
    $vip_expired     = $user['vip_expired'] ?? 1;
    $vip_expire_time = $user['vip_expire_time'] ?? null;
    $vip_card_id     = $user['vip_card_id'] ?? null;

    // 权限初始值
    $vip_card_name = '';
    $can_view_vip_video = 0;
    $can_watch_coin = 0;
    $duration = 0;
    $duration_unit = '';
    $vip_src = '';

    $isVipCard = false;
    if ($vip_card_id) {
        $vipCard = Db::name('vip_card_type')->where('id', $vip_card_id)->find();
        if ($vipCard) {
            $vip_card_name = $vipCard['name'] ?? '';
            $can_view_vip_video = $vipCard['can_view_vip_video'] ?? 0;
            $can_watch_coin = $vipCard['can_watch_coin'] ?? 0;
            $duration = $vipCard['duration'] ?? 0;
            $duration_unit = $vipCard['duration_unit'] ?? '';
            $vip_src = $vipCard['vip_src'] ?? '';
            if ($vipCard['can_view_vip_video'] == 1) {
                $isVipCard = true;
            }
        }
    }

    $vip_status = 0;  // 1=VIP, 0=不是VIP
    $vip_expired = 1; // 0=未过期, 1=已过期

    if ($isVipCard && $vip_expire_time && strtotime($vip_expire_time) > time()) {
        $vip_status = 1;
        $vip_expired = 0;
    }

    $memberStatus = ($vip_status == 1 && $vip_expired == 0) ? 'VIP会员' : '普通会员';

    $currentLoginTime = date('Y-m-d H:i:s');
    Db::name('users')->where('uuid', $user['uuid'])->update(['last_login_time' => $currentLoginTime]);

    // 查询当天观看记录
    $todayRecord = Db::name('user_daily_watch_count')
        ->where('uuid', $user['uuid'])
        ->where('date', date('Y-m-d'))
        ->find();
    $longUsed = $todayRecord['long_video_used'] ?? 0;
    $dyUsed = $todayRecord['dy_video_used'] ?? 0;

    // 查询VIP卡配额
    $maxLong = $vipCard['max_long_watch'] ?? 0;
    $maxDy = $vipCard['max_dy_watch'] ?? 0;

    return json([
        'code' => 0,
        'msg'  => '登录成功',
        'data' => [
            'uuid'                => $user['uuid'],
            'account'             => $user['account'],
            'nickname'            => $user['nickname'],
            'avatar'              => $user['avatar'] ?: '/images/666.webp',
            'goldCoins'           => $user['coin'] ?? 0,
            'points'              => (int)($user['points'] ?? 0),
            'mobile'              => $user['mobile'] ?? '',
            'email'               => $user['email'] ?? '',
            'vip_status'          => $vip_status,
            'vip_expired'         => $vip_expired,
            'vip_card_id'         => $vip_card_id,
            'vip_card_name'       => $vip_card_name,
            'can_view_vip_video'  => $can_view_vip_video,
            'can_watch_coin'      => $can_watch_coin,
            'memberStatus'        => $memberStatus,
            'vip_expire_time'     => $vip_expire_time,
            'duration'            => $duration,
            'duration_unit'       => $duration_unit,
            'vip_src'             => $vip_src,
            'registrationTime'    => $user['register_time'] ?? '',
            'lastLoginTime'       => $currentLoginTime,
            'remark'              => $user['remark'] ?? '',
            'token'               => $token,
            'inviteCode'          => $user['invite_code'] ?? '',
            'long_video_used'     => $longUsed,
            'long_video_max'      => $maxLong,
            'dy_video_used'       => $dyUsed,
            'dy_video_max'        => $maxDy,
            'user_status'         => $user['user_status'], // 新增字段
        ]
    ]);
}

    /**
     * H5 User Registration
     * Registers a new user with generated UUID and nickname, and initializes other fields.
     *
     * @param Request $request
     * @return \think\Response|\think\response\Json
     */
    public function register(Request $request)
{
    file_put_contents(
        runtime_path() . 'token_debug.log',
        date('Y-m-d H:i:s') . " register接口被访问\n",
        FILE_APPEND
    );

    $account  = $request->post('account', '');
    $password = $request->post('password', '');
    $uuid = $request->post('uuid', '');


        // 如果提供了 UUID，检查是否存在且未设置账号密码的用户
        // 如果提供了 UUID，检查是否存在且未设置账号密码的用户
if ($uuid) {
    $existUser = Db::name('users')->where('uuid', $uuid)->find();
    if ($existUser) {
        if ($existUser['account']) {
            return json(['code' => 400, 'msg' => '不能进行重复绑定']);
        }
        // 账号是否已被别的用户使用
        $accountExist = Db::name('users')->where('account', $account)->find();
        if ($accountExist) {
            return json(['code' => 400, 'msg' => '账号已存在，请换一个']);
        }
        // 只更新账号和密码，不新建用户
        Db::name('users')->where('uuid', $uuid)->update([
            'account' => $account,
            'password' => md5($password),
            'update_time' => date('Y-m-d H:i:s')
        ]);
        // 绑定后重新查一次，返回最新数据
        $user = Db::name('users')->where('uuid', $uuid)->find();
        // 校验通过之后
$token = $this->generateToken($user['uuid'], 'login');

        // ====== VIP相关信息封装（和 login 保持一致）======
        $vip_card_id = $user['vip_card_id'] ?? null;
        $vip_card_name = '';
        $can_view_vip_video = 0;
        $can_watch_coin = 0;
        $duration = 0;
        $duration_unit = '';
        $vip_src = '';
        $isVipCard = false;
        if ($vip_card_id) {
            $vipCard = Db::name('vip_card_type')->where('id', $vip_card_id)->find();
            if ($vipCard) {
                $vip_card_name = $vipCard['name'] ?? '';
                $can_view_vip_video = $vipCard['can_view_vip_video'] ?? 0;
                $can_watch_coin = $vipCard['can_watch_coin'] ?? 0;
                $duration = $vipCard['duration'] ?? 0;
                $duration_unit = $vipCard['duration_unit'] ?? '';
                $vip_src = $vipCard['vip_src'] ?? '';
                if ($vipCard['can_view_vip_video'] == 1) {
                    $isVipCard = true;
                }
            }
        }

        $vip_status = 0;  // 1=VIP, 0=不是VIP
        $vip_expired = 1; // 0=未过期, 1=已过期
        if ($isVipCard && $user['vip_expire_time'] && strtotime($user['vip_expire_time']) > time()) {
            $vip_status = 1;
            $vip_expired = 0;
        }
        $memberStatus = ($vip_status == 1 && $vip_expired == 0) ? 'VIP会员' : '普通会员';

        $currentLoginTime = date('Y-m-d H:i:s');
        Db::name('users')->where('uuid', $user['uuid'])->update(['last_login_time' => $currentLoginTime]);

        // 查询当天观看记录
        $todayRecord = Db::name('user_daily_watch_count')
            ->where('uuid', $user['uuid'])
            ->where('date', date('Y-m-d'))
            ->find();
        $longUsed = $todayRecord['long_video_used'] ?? 0;
        $dyUsed = $todayRecord['dy_video_used'] ?? 0;

        // 查询VIP卡配额
        $maxLong = $vipCard['max_long_watch'] ?? 0;
        $maxDy = $vipCard['max_dy_watch'] ?? 0;

        return json([
            'code' => 0,
            'msg'  => '绑定成功',
            'data' => [
                'uuid'                => $user['uuid'],
                'account'             => $user['account'],
                'nickname'            => $user['nickname'],
                'avatar'              => $user['avatar'] ?: '/images/666.webp',
                'goldCoins'           => $user['coin'] ?? 0,
                'points'              => (int)($user['points'] ?? 0),
                'mobile'              => $user['mobile'] ?? '',
                'email'               => $user['email'] ?? '',
                'vip_status'          => $vip_status,
                'vip_expired'         => $vip_expired,
                'vip_card_id'         => $vip_card_id,
                'vip_card_name'       => $vip_card_name,
                'can_view_vip_video'  => $can_view_vip_video,
                'can_watch_coin'      => $can_watch_coin,
                'memberStatus'        => $memberStatus,
                'vip_expire_time'     => $user['vip_expire_time'] ?? null,
                'duration'            => $duration,
                'duration_unit'       => $duration_unit,
                'vip_src'             => $vip_src,
                'registrationTime'    => $user['register_time'] ?? '',
                'lastLoginTime'       => $currentLoginTime,
                'remark'              => $user['remark'] ?? '',
                'token'               => $token,
                'inviteCode'          => $user['invite_code'] ?? '',
                'long_video_used'     => $longUsed,
                'long_video_max'      => $maxLong,
                'dy_video_used'       => $dyUsed,
                'dy_video_max'        => $maxDy
            ]
        ]);
    }
    // uuid不存在，禁止新建
    return json(['code' => 400, 'msg' => '非法操作']);
}


        // 如果没有 UUID 或用户不存在，走普通注册流程
        $account  = $request->post('account', '');
        $password = $request->post('password', '');
        $mobile   = $request->post('mobile', ''); // Optional new field from request
        $email    = $request->post('email', '');  // Optional new field from request

       // Basic validation
 if (!$account || !$password) {
        return json(['code' => 400, 'msg' => '账号和密码不能为空']);
    }
    if (!preg_match('/^[a-zA-Z0-9_.\-@]{6,}$/', $account)) {
        return json(['code' => 400, 'msg' => '账号必须6位及以上，仅限字母、数字、_ . - @']);
    }
    if (!preg_match('/^[a-zA-Z0-9_.\-@]{6,}$/', $password)) {
        return json(['code' => 400, 'msg' => '密码必须6位及以上，仅限字母、数字、_ . - @']);
    }
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return json(['code' => 400, 'msg' => '邮箱格式不正确']);
    }
    $exist = Db::name('users')->where('account', $account)->find();
    if ($exist) {
        return json(['code' => 400, 'msg' => '账号已存在']);
    }
    if (!empty($mobile)) {
        $existMobile = Db::name('users')->where('mobile', $mobile)->find();
        if ($existMobile) {
            return json(['code' => 400, 'msg' => '手机号已存在']);
        }
    }
    if (!empty($email)) {
        $existEmail = Db::name('users')->where('email', $email)->find();
        if ($existEmail) {
            return json(['code' => 400, 'msg' => '邮箱已存在']);
        }
    }

    $uuid     = $this->generateUniqueUuid(10);
    $nickname = $this->generateRandomNickname();
    $currentTime = date('Y-m-d H:i:s');
    $inviteCode = $this->generateUniqueInviteCode(6);

    $id = Db::name('users')->insertGetId([
        'uuid'              => $uuid,
        'account'           => $account,
        'password'          => md5($password),
        'nickname'          => $nickname,
        'avatar'            => '/images/666.webp',
        'coin'              => 0,
        'mobile'            => $mobile,
        'email'             => $email,
        'invite_code'       => $inviteCode,
        'vip_status'        => 0,
        'vip_expired'       => 1,
        'vip_card_id'       => null,
        'vip_expire_time'   => null,
        'register_time'     => $currentTime,
        'last_login_time'   => $currentTime,
        'remark'            => '',
        'create_time'       => $currentTime,
        'is_deleted'        => 0,
    ]);

    $token = $this->generateToken($uuid, 'login');

    // 默认注册无卡权限
    return json([
        'code' => 0,
        'msg'  => '注册成功',
        'data' => [
            'uuid'                => $uuid,
            'account'             => $account,
            'nickname'            => $nickname,
            'avatar'              => '/images/666.webp',
            'goldCoins'           => 0,
            'points'              => 0,
            'mobile'              => $mobile,
            'email'               => $email,
            'vip_status'          => 0,
            'vip_expired'         => 1,
            'vip_card_id'         => null,
            'vip_card_name'       => '',
            'can_view_vip_video'  => 0,
            'can_watch_coin'      => 0,
            'memberStatus'        => '普通会员',
            'vip_expire_time'     => '',
            'duration'            => 0,
            'duration_unit'       => '',
            'vip_src'             => '',
            'registrationTime'    => $currentTime,
            'lastLoginTime'       => $currentTime,
            'remark'              => '',
            'token'               => $token,
            'inviteCode'          => $inviteCode
        ],
    ]);
}

    /**
     * H5 User Information
     * Retrieves the detailed profile and VIP status of the logged-in user.
     * Requires a valid token in the Authorization header.
     *
     * @param Request $request
     * @return \think\Response|\think\response\Json
     */
    public function info(Request $request)
{
    file_put_contents(
        runtime_path() . 'token_debug.log',
        date('Y-m-d H:i:s') . " info接口被访问\n",
        FILE_APPEND
    );

    $check = $this->checkToken();
    if (isset($check['error'])) {
        return $check['error'];
    }
    $user = $check['user'];
    $mode = $check['mode'];

    if ($mode === 'guest') {
        $user['account'] = '';
    }

    $vip_status      = $user['vip_status'] ?? 0;
    $vip_expired     = $user['vip_expired'] ?? 1;
    $vip_expire_time = $user['vip_expire_time'] ?? '';
    $vip_card_id     = $user['vip_card_id'] ?? null;

    $vip_card_name = '';
    $can_view_vip_video = 0;
    $can_watch_coin = 0;
    $duration = 0;
    $duration_unit = '';
    $vip_src = '';
    $vipCard = null;

    if (!empty($vip_card_id)) {
        $vipCard = Db::name('vip_card_type')->where('id', $vip_card_id)->find();
        if ($vipCard) {
            $vip_card_name = $vipCard['name'] ?? '';
            $can_view_vip_video = $vipCard['can_view_vip_video'] ?? 0;
            $can_watch_coin = $vipCard['can_watch_coin'] ?? 0;
            $duration = $vipCard['duration'] ?? 0;
            $duration_unit = $vipCard['duration_unit'] ?? '';
            $vip_src = $vipCard['vip_src'] ?? '';
        }
    }

    $memberStatus = ($vip_status == 1 && $vip_expired == 0) ? 'VIP会员' : '普通会员';

    // 查询当天观看记录
    $todayRecord = Db::name('user_daily_watch_count')
        ->where('uuid', $user['uuid'])
        ->where('date', date('Y-m-d'))
        ->find();
    $longUsed = $todayRecord['long_video_used'] ?? 0;
    $dyUsed = $todayRecord['dy_video_used'] ?? 0;

    // 从系统配置表获取每日免费次数
    $configMap = Db::name('site_config')->whereIn('config_key', [
        'free_long_video_daily',
        'free_dy_video_daily'
    ])->column('config_value', 'config_key');

    $maxLongFromConfig = (int)($configMap['free_long_video_daily'] ?? 0);
    $maxDyFromConfig = (int)($configMap['free_dy_video_daily'] ?? 0);

    // 默认最大次数来自系统配置
    $maxLong = $maxLongFromConfig;
    $maxDy = $maxDyFromConfig;

    // 如果有VIP卡，覆盖配置
    if ($vipCard) {
        $maxLong = (int)($vipCard['max_long_watch'] ?? $maxLong);
        $maxDy = (int)($vipCard['max_dy_watch'] ?? $maxDy);
    }

    return json([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'uuid'                => $user['uuid'],
            'account'             => $user['account'],
            'nickname'            => $user['nickname'],
            'avatar'              => $user['avatar'] ?: '/images/666.webp',
            'goldCoins'           => $user['coin'] ?? 0,
            'points'              => (int)($user['points'] ?? 0),
            'mobile'              => $user['mobile'] ?? '',
            'email'               => $user['email'] ?? '',
            'vip_status'          => $vip_status,
            'vip_expired'         => $vip_expired,
            'vip_card_id'         => $vip_card_id,
            'vip_card_name'       => $vip_card_name,
            'can_view_vip_video'  => $can_view_vip_video,
            'can_watch_coin'      => $can_watch_coin,
            'memberStatus'        => $memberStatus,
            'vip_expire_time'     => $vip_expire_time,
            'duration'            => $duration,
            'duration_unit'       => $duration_unit,
            'vip_src'             => $vip_src,
            'registrationTime'    => $user['register_time'] ?? '',
            'lastLoginTime'       => $user['last_login_time'] ?? '',
            'remark'              => $user['remark'] ?? '',
            'inviteCode'          => $user['invite_code'] ?? '',
            'long_video_used'     => $longUsed,
            'long_video_max'      => $maxLong,
            'dy_video_used'       => $dyUsed,
            'dy_video_max'        => $maxDy,
            'user_status'         => $user['user_status'], // 新增字段
        ]
    ]);
}

    /**
     * 自动注册基础用户
     * @return \think\Response|\think\response\Json
     */
   public function autoRegister(\think\Request $request)
{
    try {
        $uuid = $request->post('uuid', '');

        if ($uuid) {
            // 如果有uuid，先去查用户
            $user = Db::name('users')->where('uuid', $uuid)->find();
            if ($user) {
                // 如果找到了，就返回老token
                $token = $this->generateToken($uuid, 'guest');

                return json([
                    'code' => 0,
                    'msg' => '恢复游客用户成功',
                    'data' => [
                        'uuid' => $uuid,
                        'nickname' => $user['nickname'],
                        'token' => $token
                    ]
                ]);
            }
        }

        // 没有uuid，或者uuid无效，创建新用户
        $uuid = $this->generateUniqueUuid(10);
        $nickname = $this->generateRandomNickname();
        $currentTime = date('Y-m-d H:i:s');
        $inviteCode = $this->generateUniqueInviteCode(6);

        Db::name('users')->insertGetId([
            'uuid'              => $uuid,
            'password'          => '',
            'nickname'          => $nickname,
            'avatar'            => '/images/666.webp',
            'coin'              => 0,
            'mobile'            => '',
            'email'             => '',
            'invite_code'       => $inviteCode,
            'vip_status'        => 0,
            'vip_expired'       => 1,
            'vip_card_id'       => null,
            'vip_expire_time'   => null,
            'register_time'     => $currentTime,
            'last_login_time'   => null,
            'remark'            => '自动创建的基础用户',
            'create_time'       => $currentTime,
            'is_deleted'        => 0,
            'status'            => 1,
            'user_status'       => 1, // 默认启用
        ]);

       $token = $this->generateToken($uuid, 'guest');


        return json([
            'code' => 0,
            'msg'  => '创建新游客用户成功',
            'data' => [
                'uuid'     => $uuid,
                'nickname' => $nickname,
                'token'    => $token,
                'user_status' => 1, // 新增字段
            ]
        ]);
    } catch (\Exception $e) {
        return json([
            'code' => 500,
            'msg'  => '创建用户失败：' . $e->getMessage()
        ]);
    }
}

/**
 * 获取用户观看次数（长视频 / 抖音）
 * @param Request $request
 * @return \think\response\Json
 */
public function watchCount(Request $request)
{
    // 校验token
    $check = $this->checkToken();
    if (isset($check['error'])) {
        return $check['error'];
    }
    $user = $check['user'];
    $uuid = $user['uuid'];

// 查询当天观看记录
$todayRecord = Db::name('user_daily_watch_count')
    ->where('uuid', $user['uuid'])
    ->where('date', date('Y-m-d'))
    ->find();

$longUsed = $todayRecord['long_video_used'] ?? 0;
$dyUsed = $todayRecord['dy_video_used'] ?? 0;

// 查询VIP卡配额
$maxLong = 0;
$maxDy = 0;

if (!empty($user['vip_card_id'])) {
    $vipCard = Db::name('vip_card_type')->where('id', $user['vip_card_id'])->find();
    if ($vipCard) {
        $maxLong = $vipCard['max_long_watch'] ?? 0;
        $maxDy = $vipCard['max_dy_watch'] ?? 0;
    }
}

// 返回结果
return json([
    'code' => 0,
    'msg'  => 'ok',
    'data' => [
        'long_video_used' => $longUsed,
        'long_video_max'  => $maxLong,
        'dy_video_used'   => $dyUsed,
        'dy_video_max'    => $maxDy,
    ]
]);

}

    // ==== Auxiliary methods ====

    /**
     * Generates a random nickname.
     *
     * @return string
     */
    private function generateRandomNickname(): string
    {
        $phrases = [
            '强人锁男','夜袭寡妇村','小马过河','风吹裤裆冷',
            '一拳打爆','少女毁灭者','地狱空荡荡','人间太疯狂',
            '温柔一刀','人狠话不多','狗都不理','天涯浪子',
            '风中追风','黑夜传说','飞天小裤头','月下独酌',
            '舔狗日记','一枪一个','冷面杀手','野性难驯',
            '心跳如狗','裤裆藏雷','菊花残满地伤'
        ];
        $phrase = $phrases[array_rand($phrases)];
        $number = random_int(1000, 9999);
        return $phrase . $number;
    }

    /**
     * Generates a unique alphanumeric UUID.
     * Ensures the generated UUID does not already exist in the 'users' table.
     *
     * @param int $length The desired length of the UUID.
     * @return string The unique UUID.
     */
    private function generateUniqueUuid(int $length = 10): string
    {
        do {
            $uuid = $this->generateRandomString($length);
            $exists = Db::name('users')->where('uuid', $uuid)->find();
        } while ($exists);
        return $uuid;
    }
/**
 * Generates a unique invite code.
 * Ensures no duplicate in the users table.
 *
 * @param int $length
 * @return string
 */
private function generateUniqueInviteCode(int $length = 6): string
{
    do {
        $code = $this->generateRandomString($length);
        $exists = Db::name('users')->where('invite_code', $code)->find();
    } while ($exists);
    return $code;
}
    /**
     * Generates a random alphanumeric string of a specified length.
     *
     * @param int $length The desired length of the string.
     * @return string The random string.
     */
    private function generateRandomString(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str   = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * Checks and validates the bearer token from the Authorization header.
     *
     * @return array Returns ['user' => $user_data] on success, or ['error' => json_response] on failure.
     */
    private function checkToken($allowGuest = true)
{
    $authHeader = request()->header('authorization');
    if (!$authHeader || !preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        // 如果允许游客
        if ($allowGuest) {
            // 自动分配游客身份（可选：自动生成游客token并注册到数据库）
            // 这里直接走 autoRegister 流程（你有 autoRegister 方法）
            $uuid = request()->post('uuid', '') ?: request()->get('uuid', '');
            if ($uuid) {
                $user = Db::name('users')->where('uuid', $uuid)->find();
                if ($user) {
                    return ['user' => $user, 'mode' => 'guest'];
                }
            }
            // 否则创建一个新的游客
            $nickname = $this->generateRandomNickname();
            $uuid = $this->generateUniqueUuid(10);
            $currentTime = date('Y-m-d H:i:s');
            Db::name('users')->insertGetId([
                'uuid' => $uuid,
                'password' => '',
                'nickname' => $nickname,
                'avatar' => '/images/666.webp',
                'coin' => 0,
                'mobile' => '',
                'email' => '',
                'invite_code' => $this->generateUniqueInviteCode(6),
                'vip_status' => 0,
                'vip_expired' => 1,
                'vip_card_id' => null,
                'vip_expire_time' => null,
                'register_time' => $currentTime,
                'last_login_time' => null,
                'remark' => '自动创建的游客',
                'create_time' => $currentTime,
                'is_deleted' => 0,
                'status' => 1,
                'user_status' => 1,
            ]);
            $user = Db::name('users')->where('uuid', $uuid)->find();
            return ['user' => $user, 'mode' => 'guest'];
        }
        // 不允许游客才返回 401
        return ['error' => json(['code'=>401, 'msg'=>'未登录或token缺失'])];
    }

    // 有token逻辑...
    $token = trim($matches[1]);
    try {
        $decoded = (array)JWT::decode($token, $this->jwtKey, [$this->jwtAlg]);
        $uuid = $decoded['uuid'] ?? '';
        $mode = $decoded['mode'] ?? 'login';
        if (!$uuid) {
            return ['error' => json(['code'=>401, 'msg'=>'token格式错误'])];
        }
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user) {
            return ['error' => json(['code'=>401, 'msg'=>'用户不存在'])];
        }
        if ($user['user_status'] == 0) {
            return ['error' => json(['code'=>403, 'msg'=>'账号已被禁用'])];
        }
        return ['user' => $user, 'mode' => $mode];
    } catch (\Firebase\JWT\ExpiredException $e) {
        return ['error' => json(['code'=>401, 'msg'=>'登录已过期，请重新登录'])];
    } catch (\Exception $e) {
        return ['error' => json(['code'=>401, 'msg'=>'token无效：' . $e->getMessage()])];
    }
}

/**
 * 从site_config表获取某个奖励类型对应的积分
 * @param string $type
 * @return int
 */
private function getPointsFromConfig(string $type): int
{
    $keyMap = [
        'login'        => 'point_daily_login',
        'invite'       => 'point_invite_user',
        'bind_mobile'  => 'point_bind_mobile',
        'bind_email'   => 'point_bind_email',
        'vip'          => 'point_buy_vip',
        'buy_coin'     => 'point_buy_gold'
    ];

    $configKey = $keyMap[$type] ?? null;
    if (!$configKey) {
        return 0;
    }

    $value = Db::name('site_config')
        ->where('config_key', $configKey)
        ->value('config_value');

    return intval($value);
}

/**
 * 给用户增加积分并记录日志
 * @param string $uuid
 * @param string $type 奖励类型 login/invite/vip/buy_coin/bind_mobile/bind_email
 * @param string $remark
 * @param bool $onlyOnce 是否同类型只能发一次
 * @return bool
 */
private function addUserPoints(string $uuid, string $type, string $remark = '', bool $onlyOnce = false): bool
{
    if ($onlyOnce) {
        $exists = Db::name('user_points_log')
            ->where('uuid', $uuid)
            ->where('type', $type)
            ->find();
        if ($exists) {
            return false;
        }
    }

    // 从配置获取积分
    $points = $this->getPointsFromConfig($type);
    if ($points <= 0) {
        return false;
    }

    // 累加积分
    Db::name('users')
        ->where('uuid', $uuid)
        ->inc('points', $points)
        ->update();

    // 查询最新积分余额
    $newBalance = Db::name('users')->where('uuid', $uuid)->value('points');

    // 写入日志
    Db::name('user_points_log')->insert([
        'uuid'        => $uuid,
        'type'        => $type,
        'points'      => $points,
        'balance'     => $newBalance,   // ⭐⭐⭐ 写入变动后余额
        'remark'      => $remark,
        'create_time' => date('Y-m-d H:i:s')
    ]);

    return true;
}

/**
 * 领取积分任务
 * @param Request $request
 * @return \think\Response|\think\response\Json
 */
public function claimTask(Request $request)
{
    $check = $this->checkToken();
    if (isset($check['error'])) {
        return $check['error'];
    }
    $user = $check['user'];
    $uuid = $user['uuid'];

    $type = $request->post('type'); // 前端传 'login'、'invite'、'vip'、'buy_coin'、'bind_mobile'、'bind_email'
    if (!$type) {
        return json(['code' => 400, 'msg' => '参数错误']);
    }

    if ($type === 'login') {
        // 每日登录按天判断
        $today = date('Y-m-d');
        $already = Db::name('user_points_log')
            ->where('uuid', $uuid)
            ->where('type', 'login')
            ->whereLike('create_time', "$today%")
            ->find();
        if ($already) {
            return json(['code' => 400, 'msg' => '今日已领取过']);
        }
        $ok = $this->addUserPoints($uuid, 'login', '每日登录领取');
        if ($ok) {
            return json(['code' => 0, 'msg' => '领取成功']);
        } else {
            return json(['code' => 500, 'msg' => '领取失败']);
        }
    }

    // 绑定任务只能领取一次
    if (in_array($type, ['bind_mobile', 'bind_email'])) {
        $ok = $this->addUserPoints($uuid, $type, '任务领取', true);
        if ($ok) {
            return json(['code' => 0, 'msg' => '领取成功']);
        } else {
            return json(['code' => 400, 'msg' => '无法领取，可能已领取或配置未设置积分']);
        }
    }

    // 其他（邀请、购买vip、购买金币）无限领取
    $ok = $this->addUserPoints($uuid, $type, '任务领取', false);
    if ($ok) {
        return json(['code' => 0, 'msg' => '领取成功']);
    } else {
        return json(['code' => 500, 'msg' => '领取失败，可能未配置积分']);
    }
}

/**
 * 获取当前用户任务领取状态
 * @return \think\response\Json
 */
public function taskStatus()
{
    $check = $this->checkToken();
    if (isset($check['error'])) {
        return $check['error'];
    }
    $user = $check['user'];
    $uuid = $user['uuid'];

    $today = date('Y-m-d');

    // 1. 每日登录 (每天只能一次)
    $loginToday = Db::name('user_points_log')
        ->where('uuid', $uuid)
        ->where('type', 'login')
        ->whereLike('create_time', "$today%")
        ->find();

    // 2. 绑定手机号 (只能一次)
    $bindMobileOnce = Db::name('user_points_log')
        ->where('uuid', $uuid)
        ->where('type', 'bind_mobile')
        ->find();

    // 3. 绑定邮箱 (只能一次)
    $bindEmailOnce = Db::name('user_points_log')
        ->where('uuid', $uuid)
        ->where('type', 'bind_email')
        ->find();

    // 从配置表获取所有分值
    $points = [
        'login'        => $this->getPointsFromConfig('login'),
        'invite'       => $this->getPointsFromConfig('invite'),
        'vip'          => $this->getPointsFromConfig('vip'),
        'buy_coin'     => $this->getPointsFromConfig('buy_coin'),
        'bind_mobile'  => $this->getPointsFromConfig('bind_mobile'),
        'bind_email'   => $this->getPointsFromConfig('bind_email'),
    ];

    return json([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'status' => [
                'login'        => $loginToday ? 'done' : 'pending',
                'bind_mobile'  => $bindMobileOnce ? 'done' : 'pending',
                'bind_email'   => $bindEmailOnce ? 'done' : 'pending',
                'invite'       => 'pending', // 永远可领取
                'vip'          => 'pending', // 永远可领取
                'buy_coin'     => 'pending', // 永远可领取
            ],
            'points' => $points,
            'my_points' => (int)$user['points']
        ]
    ]);
}
/**
 * 生成 JWT Token
 * @param string $uuid
 * @param string $mode 'login'（正式）或 'guest'（游客）
 * @return string
 */
private function generateToken($uuid, $mode = 'login')
{
    // 判断有效期
    if ($mode === 'guest') {
        $exp = time() + 3600 * 24 * 30; // 游客30天
    } else {
        $exp = time() + 3600 * 24 * 7;  // 正式7天
    }

    $payload = [
        'uuid' => $uuid,
        'mode' => $mode,
        'iat'  => time(),
        'exp'  => $exp
    ];
    return JWT::encode($payload, $this->jwtKey, $this->jwtAlg);
}
}