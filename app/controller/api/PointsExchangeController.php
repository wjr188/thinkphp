<?php
declare(strict_types=1);

namespace app\controller\api;

use think\facade\Db;
use think\Request;
use app\BaseController;

class PointsExchangeController extends BaseController
{
    /**
     * 获取可兑换商品列表
     * GET /api/points/list
     */
    public function list()
    {
        $list = Db::name('points_exchange')
            ->where('status', 1)
            ->field('id, name, type, value, cost, description, icon')
            ->order('sort desc, id asc')
            ->select();

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => $list
        ]);
    }

    /**
     * 执行兑换
     * POST /api/points/exchange
     */
   public function exchange(Request $request)
{
    $check = $this->checkToken();
    if (isset($check['error'])) {
        return $check['error'];
    }
    $user = $check['user'];

    $id = $request->post('id/d');
    if (!$id) {
        return json(['code' => 400, 'msg' => '请选择要兑换的商品']);
    }

    // 查询兑换项目
    $item = Db::name('points_exchange')->where('id', $id)->find();
    if (!$item || !$item['status']) {
        return json(['code' => 400, 'msg' => '兑换项目不存在']);
    }

    if ($user['points'] < $item['cost']) {
        return json(['code' => 400, 'msg' => '积分不足']);
    }

    // 开始事务
    Db::startTrans();
    try {
        // 先查旧积分
        $oldPoints = Db::name('users')->where('uuid', $user['uuid'])->value('points');

        // 再扣除积分
        Db::name('users')
            ->where('uuid', $user['uuid'])
            ->dec('points', $item['cost'])
            ->update();

        // 再查最新积分
        $newBalance = $oldPoints - $item['cost'];

        // 写入积分流水
        Db::name('user_points_log')->insert([
            'uuid'        => $user['uuid'],
            'type'        => 'exchange',
            'points'      => -$item['cost'],
            'balance'     => $newBalance,
            'remark'      => "兑换「{$item['name']}」",
            'create_time' => date('Y-m-d H:i:s')
        ]);

        // 写入兑换记录
        Db::name('user_points_exchange_log')->insert([
            'uuid'          => $user['uuid'],
            'exchange_id'   => $item['id'],
            'exchange_name' => $item['name'],
            'cost'          => $item['cost'],
            'status'        => 1,
            'remark'        => "兑换「{$item['name']}」",
            'create_time'   => date('Y-m-d H:i:s'),
        ]);

        // 发放物品
        if ($item['type'] === 'vip') {
            $vipCard = Db::name('vip_card_type')->where('id', $item['value'])->find();
            if (!$vipCard) {
                Db::rollback();
                return json(['code' => 400, 'msg' => '兑换的会员卡不存在']);
            }

            $expire = ($vipCard['duration'] == -1)
                ? '2099-12-31 23:59:59'
                : date('Y-m-d H:i:s', strtotime('+' . intval($vipCard['duration']) . ' days'));

            Db::name('users')
                ->where('uuid', $user['uuid'])
                ->update([
                    'vip_status'      => 1,
                    'vip_expired'     => 0,
                    'vip_expire_time' => $expire,
                    'vip_card_id'     => $vipCard['id'],
                ]);
        }

        if ($item['type'] === 'coin') {
            Db::name('users')
                ->where('uuid', $user['uuid'])
                ->inc('coin', intval($item['value']))
                ->update();
        }

        Db::commit();
        return json(['code' => 0, 'msg' => '兑换成功']);
    } catch (\Throwable $e) {
        Db::rollback();
        return json(['code' => 500, 'msg' => '兑换失败：' . $e->getMessage()]);
    }
}

    /**
     * 查询我的兑换记录
     * GET /api/points/records
     */
    public function records(Request $request)
    {
        $check = $this->checkToken();
        if (isset($check['error'])) {
            return $check['error'];
        }
        $user = $check['user'];

        $page = (int)$request->get('page', 1);
$pageSize = (int)$request->get('pageSize', 20);


        $query = Db::name('user_points_exchange_log')
            ->where('uuid', $user['uuid'])
            ->order('id desc');

        $total = $query->count();
        $list = $query->page($page, $pageSize)->select();

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'list'  => $list,
                'total' => $total
            ]
        ]);
    }

    /**
     * 校验Token (复用逻辑)
     */
    private function checkToken()
    {
        $authHeader = request()->header('authorization');
        if (!$authHeader || !preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            return ['error' => json(['code' => 401, 'msg' => '未登录或token缺失'])];
        }
        $token = $matches[1];
        $token = strtr($token, ' ', '+');
        $token = strtr($token, '-_', '+/');
        $decoded = base64_decode($token);
        if (strpos($decoded, '|') === false) {
            return ['error' => json(['code' => 401, 'msg' => 'token格式错误'])];
        }
        list($uuid, $mode, $sign) = explode('|', $decoded);
        $user = Db::name('users')->where('uuid', $uuid)->find();
        if (!$user || md5($uuid . 'your_salt') !== $sign) {
            return ['error' => json(['code' => 401, 'msg' => 'token无效或已过期'])];
        }
        return ['user' => $user, 'mode' => $mode];
    }
}
