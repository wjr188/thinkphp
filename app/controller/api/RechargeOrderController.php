<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db;

/**
 * 充值订单管理控制器
 */
class RechargeOrderController extends BaseController
{
    protected $rechargeOrderTable = 'recharge_orders';
    protected $userTable = 'users';

    /**
     * 获取充值订单列表
     * GET /api/recharge/order/list
     */
    public function getList()
    {
        $params = Request::get([
            'start_time',
            'end_time',
            'domain',
            'channel',
            'type',
            'status',
            'keyword',
            'page' => 1,
            'pageSize' => 10,
        ]);

        $page = intval($params['page']);
        $pageSize = intval($params['pageSize']);

        $query = Db::table($this->rechargeOrderTable)
            ->alias('o')
            ->leftJoin($this->userTable . ' u', 'o.user_uuid = u.uuid')
            // join VIP卡表
            ->leftJoin('vip_card_type v', "o.product_type = 'vip' AND o.product_id = v.id")
            // join 金币套餐表
            ->leftJoin('coin_package c', "o.product_type = 'coin' AND o.product_id = c.id")
            ->field([
                'o.order_id AS order_no',
                'o.user_uuid',
                'u.nickname AS username',
                'u.register_time',
                'o.amount',
                'o.pay_time',
                'u.domain',
                'u.channel_domain AS channel',
                Db::raw("CASE WHEN DATE(o.pay_time) = DATE(u.register_time) THEN '首充' ELSE '复充' END AS type"),
                'o.status',
                'o.product_type',
                'o.product_id', // 只保留这个
                'v.name AS vip_card_name',
                'v.duration AS vip_card_duration',
                'v.duration_unit AS vip_card_unit',
                'c.name AS coin_package_name',
                'c.amount AS coin_amount',
                'c.gift_coins',
            ]);

        // 充值日期筛选
        if (!empty($params['start_time']) && !empty($params['end_time'])) {
            $query->whereBetween('o.pay_time', [$params['start_time'] . ' 00:00:00', $params['end_time'] . ' 23:59:59']);
        }
        // 域名筛选
        if (!empty($params['domain'])) {
            $domains = explode(',', $params['domain']);
            $query->whereIn('u.domain', $domains);
        }

        // 渠道筛选
        if (!empty($params['channel'])) {
            $channels = explode(',', $params['channel']);
            $query->whereIn('u.channel_domain', $channels);
        }
        // 充值类型筛选
        if (!empty($params['type']) && $params['type'] !== '全部') {
            if ($params['type'] === '首充') {
                $query->whereRaw('DATE(o.pay_time) = DATE(u.register_time)');
            } elseif ($params['type'] === '复充') {
                $query->whereRaw('DATE(o.pay_time) != DATE(u.register_time)');
            }
        }
        // 订单状态筛选
        if ($params['status'] !== '' && $params['status'] !== null && $params['status'] !== '全部') {
            $query->where('o.status', $params['status']);
        } else {
            // 默认排除已删除状态的订单
            $query->where('o.status', '<>', -1);
        }
        // 搜索条件
        if (!empty($params['keyword'])) {
            $query->where(function ($q) use ($params) {
                $q->where('u.uuid', $params['keyword'])
                  ->oRwhere('u.nickname', 'like', '%' . $params['keyword'] . '%');
            });
        }

        $total = $query->count();
        $list = $query->order('o.pay_time', 'desc')
                      ->page($page, $pageSize)
                      ->select()
                      ->toArray();

        return $this->success([
            'list' => $list,
            'total' => $total,
        ]);
    }

    /**
     * 确认充值订单到账
     * POST /api/recharge/order/confirm
     */
    public function confirm()
    {
        $orderId = Request::post('order_id');
        if (empty($orderId)) {
            return $this->error('订单ID不能为空');
        }
        $order = Db::table($this->rechargeOrderTable)->where('order_id', $orderId)->find();
        if (!$order) {
            return $this->error('订单不存在');
        }
        if ($order['status'] == 2 || $order['status'] == -1) { // 2=已确认，-1=已删除
            return $this->error('当前订单状态不允许确认到账');
        }
        try {
            $result = Db::table($this->rechargeOrderTable)
                        ->where('order_id', $orderId)
                        ->update(['status' => 2, 'update_time' => date('Y-m-d H:i:s')]);
            if ($result) {
                $order = Db::table($this->rechargeOrderTable)->where('order_id', $orderId)->find();
                if ($order) {
                    $this->syncUserVipInfo($order['user_uuid'], $order['product_type'], $order['product_id']);
                }
                return $this->success([], '订单确认成功');
            } else {
                return $this->error('订单确认失败或状态无变化');
            }
        } catch (\Exception $e) {
            return $this->error('订单确认异常: ' . $e->getMessage());
        }
    }

    /**
     * 删除充值订单
     * POST /api/recharge/order/delete
     */
    public function delete()
    {
        $orderId = Request::post('order_id');
        if (empty($orderId)) {
            return $this->error('订单ID不能为空');
        }
        $order = Db::table($this->rechargeOrderTable)->where('order_id', $orderId)->find();
        if (!$order) {
            return $this->error('订单不存在');
        }
        if ($order['status'] == -1) {
            return $this->error('订单已处于删除状态，无需重复操作');
        }
        try {
            $result = Db::table($this->rechargeOrderTable)
                        ->where('order_id', $orderId)
                        ->update(['status' => -1, 'update_time' => date('Y-m-d H:i:s')]);
            if ($result) {
                return $this->success([], '订单删除成功');
            } else {
                return $this->error('订单删除失败或状态无变化');
            }
        } catch (\Exception $e) {
            return $this->error('订单删除异常: ' . $e->getMessage());
        }
    }

    /**
     * 获取所有注册/充值用到的域名和渠道列表
     * GET /api/recharge/domains_channels
     */
    public function getDomainsAndChannels()
    {
        $domains = Db::table($this->userTable)
            ->whereNotNull('domain')
            ->where('domain', '<>', '')
            ->distinct(true)
            ->column('domain');
        $channels = Db::table($this->userTable)
            ->whereNotNull('channel_domain')
            ->where('channel_domain', '<>', '')
            ->distinct(true)
            ->column('channel_domain');
        return $this->success([
            'domains' => array_values($domains),
            'channels' => array_values($channels),
        ]);
    }

    /**
     * 支付平台异步回调通知
     * POST /api/payment/callback
     */
    public function paymentCallback()
    {
        $params = Request::param();

        // 记录所有回调参数日志，便于排查
        file_put_contents(runtime_path().'pay_notify.log', json_encode($params, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);

        // 1. 验签（上线前必须实现！）
        // if (!$this->checkSign($params)) {
        //     return $this->failResponse('fail'); // 支付宝需返回'fail'
        // }

        // 2. 查询订单号
        $orderId = $params['order_id'] ?? $params['out_trade_no'] ?? '';
        if (!$orderId) {
            return $this->failResponse('缺少订单号');
        }

        // 3. 查找订单
        $order = Db::table($this->rechargeOrderTable)->where('order_id', $orderId)->find();
        if (!$order) {
            return $this->failResponse('订单不存在');
        }

        // 4. 金额校验（强烈建议）
        if (isset($params['amount']) && bccomp($order['amount'], $params['amount'], 2) !== 0) {
            return $this->failResponse('金额不符');
        }

        // 5. 判断订单状态，避免重复处理
        if ($order['status'] == 1 || $order['status'] == 2) { // 1=已支付，2=已确认
            return $this->successResponse('订单已处理', $params);
        }

        // 6. 更新订单状态
        Db::table($this->rechargeOrderTable)
            ->where('order_id', $orderId)
            ->update([
                'status' => 1,
                'pay_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);

        // 7. 同步用户VIP/金币信息
        $this->syncUserVipInfo($order['user_uuid'], $order['product_type'], $order['product_id']);

        // 8. 返回第三方平台要求的响应内容
        return $this->successResponse('success', $params);
    }

    /**
     * 支付宝/微信回调响应兼容
     */
    protected function successResponse($msg = 'success', $params = [])
    {
        // 可根据参数或User-Agent判断平台
        if (isset($params['trade_status']) || isset($params['alipay_trade_no'])) {
            // 支付宝
            return 'success';
        }
        if (isset($params['return_code']) || isset($params['transaction_id'])) {
            // 微信
            return '<xml><return_code><![CDATA[SUCCESS]]></return_code></xml>';
        }
        // 默认返回支付宝格式
        return 'success';
    }

    protected function failResponse($msg = 'fail')
    {
        // 默认支付宝格式
        return 'fail';
    }

    /**
     * 创建充值订单
     * POST /api/recharge/order/create
     */
    public function create()
    {
        $data = Request::only([
            'order_id',
            'user_uuid',
            'amount',
            'product_type',
            'product_id',
            'status',
            'channel_id'
        ]);
        if (empty($data['order_id']) || empty($data['user_uuid']) || empty($data['amount']) || empty($data['product_type']) || empty($data['product_id'])) {
            return $this->error('参数不完整');
        }

        $now = date('Y-m-d H:i:s');
        $data['create_time'] = $now;
        $data['update_time'] = $now;

        // 新建即到账
        $data['pay_time'] = $now;
        $data['status'] = 2; // 2=已确认到账
        $data['channel_id'] = $data['channel_id'] ?? 0;

        try {
            $res = Db::table($this->rechargeOrderTable)->insert($data);
            if ($res) {
                // 新建即同步会员信息
                $this->syncUserVipInfo($data['user_uuid'], $data['product_type'], $data['product_id']);
                return $this->success([], '订单创建并自动到账成功');
            } else {
                return $this->error('订单创建失败');
            }
        } catch (\Exception $e) {
            return $this->error('订单创建异常: ' . $e->getMessage());
        }
    }

    protected function syncUserVipInfo($userUuid, $productType, $productId)
    {
        // 查用户
        $user = Db::table($this->userTable)->where('uuid', $userUuid)->find();
        if (!$user) return false;

        if ($productType === 'vip') {
            // 查VIP卡
            $vipCard = Db::table('vip_card_type')->where('id', $productId)->find();
            if (!$vipCard) return false;

            // 计算到期时间
            $now = date('Y-m-d H:i:s');
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
                default:
                    $expire = '2099-12-31 23:59:59';
            }

            // 更新用户VIP信息
            $updateData = [
                'vip_card_id'     => $productId,
                'vip_status'      => 1,
                'vip_expired'     => 0,
                'vip_expire_time' => $expire,
                'update_time'     => $now,
            ];
            Db::table($this->userTable)->where('uuid', $userUuid)->update($updateData);

        } elseif ($productType === 'coin') {
            // 查金币套餐
            $coinPackage = Db::table('coin_package')->where('id', $productId)->find();
            if (!$coinPackage) return false;

            $newCoin = $user['coin'] + $coinPackage['amount'] + ($coinPackage['gift_coins'] ?? 0);
            Db::table($this->userTable)->where('uuid', $userUuid)->update([
                'coin' => $newCoin,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }
        return true;
    }
}