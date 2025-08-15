<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db; // 假设您使用 ThinkPHP 的 Db 类进行数据库操作

class ChannelStatsController extends BaseController
{
    /**
     * 获取渠道每日效果统计数据
     * 对应前端: getChannelStatsList
     * 路由: Route::get('api/channelStats/list', 'app\controller\api\ChannelStatsController@list');
     * @return \think\response\Json
     */
    public function list()
    {
        $param = Request::param();

        // 获取并处理查询参数
        $channelId = $param['channel_id'] ?? '';
        $channelName = $param['channel_name'] ?? '';
        $channelDomain = $param['channel_domain'] ?? '';
        $statisticDateStart = $param['statistic_date_start'] ?? '';
        $statisticDateEnd = $param['statistic_date_end'] ?? '';
        $investmentAmount = $param['investment_amount'] ?? '';
        $page = (int)($param['page'] ?? 1);
        $pageSize = (int)($param['page_size'] ?? 10);

        // 构建查询条件
        $where = [];
        if (!empty($channelId)) {
            $where[] = ['channel_id', '=', $channelId];
        }
        if (!empty($channelName)) {
            $where[] = ['channel_name', 'like', '%' . $channelName . '%'];
        }
        if (!empty($channelDomain)) {
            $where[] = ['channel_domain', 'like', '%' . $channelDomain . '%'];
        }
        if (!empty($statisticDateStart) && !empty($statisticDateEnd)) {
            $where[] = ['statistic_date', 'between time', [$statisticDateStart, $statisticDateEnd]];
        }
        if (!empty($investmentAmount)) {
            // 根据实际需求处理投资金额的查询逻辑，例如大于等于
            $where[] = ['investment_amount', '>=', $investmentAmount];
        }

        // 打印 where、param、当前数据库
        file_put_contents(
            runtime_path() . 'db_debug.log',
            "where: " . json_encode($where) . "\nparam: " . json_encode($param) . "\ndb: " . json_encode(Db::query('select database() as db')) . "\n",
            FILE_APPEND
        );

        // 打印 SQL
        Db::listen(function($sql, $time, $explain){
            file_put_contents(
                runtime_path() . 'db_debug.log',
                "sql: " . $sql . "\n",
                FILE_APPEND
            );
        });

        // 假设您有一个 'channel_daily_stats' 表
        // 查询列表数据
        $list = Db::name('channel_daily_stats')
                    ->where($where)
                    ->page($page, $pageSize)
                    ->select()
                    ->toArray();

        // 批量查出所有 channel_id 对应的 channel_domain
        $channelIds = array_column($list, 'channel_id');
        $channelDomains = [];
        if ($channelIds) {
            $channelDomains = Db::name('channels')
                ->whereIn('channel_id', $channelIds)
                ->column('channel_domain', 'channel_id');
        }

        // 补全每条数据的 channel_domain 字段
        foreach ($list as &$item) {
            if (empty($item['channel_domain']) && !empty($item['channel_id'])) {
                $item['channel_domain'] = $channelDomains[$item['channel_id']] ?? '';
            }
        }
        unset($item);

        // 查询总数
        $total = Db::name('channel_daily_stats')
                    ->where($where)
                    ->count();

        // 计算汇总数据 (这里只是一个示例，实际情况可能需要更复杂的聚合查询)
        $summary = Db::name('channel_daily_stats')
                      ->where($where)
                      ->field('SUM(registered_users) as total_registered_users,
                                SUM(first_recharge_amount) as total_first_recharge_amount,
                                SUM(repeat_recharge_amount) as total_repeat_recharge_amount')
                      ->find();

        $totalRecharge = ($summary['total_first_recharge_amount'] ?? 0) + ($summary['total_repeat_recharge_amount'] ?? 0);
        $totalInvestment = Db::name('channel_daily_stats')
                               ->where($where)
                               ->sum('investment_amount');
        $roi = ($totalInvestment > 0) ? round(($totalRecharge / $totalInvestment) * 100, 2) : 0;


        $data = [
            'list' => $list,
            'total' => $total,
            'summary' => [
                'total_registered_users' => $summary['total_registered_users'] ?? 0,
                'total_first_recharge_amount' => (float)($summary['total_first_recharge_amount'] ?? 0),
                'total_repeat_recharge_amount' => (float)($summary['total_repeat_recharge_amount'] ?? 0),
                'total_recharge' => (float)$totalRecharge,
                'total_roi' => (float)$roi,
            ]
        ];

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * 获取用户充值明细数据
     * 对应前端: getUserRechargeDetail
     * 路由: Route::get('api/channelStats/userRechargeDetail', 'app\controller\api\ChannelStatsController@userRechargeDetail');
     * @return \think\response\Json
     */
    public function userRechargeDetail()
    {
        $param = Request::param();

        $channelDomain = $param['channel_domain'] ?? '';
        $statisticDate = $param['statistic_date'] ?? '';
        $userId = $param['user_id'] ?? '';
        $nickname = $param['nickname'] ?? '';
        $rechargeType = $param['recharge_type'] ?? ''; // 'first' 或 'repeat'
        $page = (int)($param['page'] ?? 1);
        $pageSize = (int)($param['page_size'] ?? 10);

        if (empty($channelDomain) || empty($statisticDate)) {
            return json([
                'code' => 400,
                'message' => '缺少渠道域名或统计日期参数',
                'data' => []
            ]);
        }

        $where = [
            ['channel_domain', '=', $channelDomain],
            ['register_time', 'between time', [$statisticDate . ' 00:00:00', $statisticDate . ' 23:59:59']],
        ];
        if (!empty($userId)) {
            $where[] = ['uuid', '=', $userId];
        }
        if (!empty($nickname)) {
            $where[] = ['nickname', 'like', "%$nickname%"];
        }

        // 查询用户明细
        $query = Db::name('users')->where($where);

        // 先查出当前页用户
        $userList = $query->field('uuid, nickname, register_time, channel_domain')->page($page, $pageSize)->select()->toArray();
        $userIds = array_column($userList, 'uuid');

        // 生成用户注册日期map
        $userRegisterDateMap = [];
        foreach ($userList as $u) {
            $userRegisterDateMap[$u['uuid']] = substr($u['register_time'], 0, 10);
        }

        // 查询这些用户注册当天及以后所有充值订单
        $orders = [];
        if ($userIds) {
            $orders = Db::name('recharge_orders')
                ->whereIn('user_uuid', $userIds)
                ->where('status', '已支付')
                // 不限定 pay_time，只要该用户的所有充值都查出来
                ->field('user_uuid, amount, pay_time')
                ->order('pay_time', 'asc')
                ->select()
                ->toArray();
        }

        // 统计每个用户的首充、复充、总充值
        $orderMap = [];
        foreach ($orders as $order) {
            $uid = $order['user_uuid'];
            if (!isset($orderMap[$uid])) {
                $orderMap[$uid] = [
                    'first_recharge_amount' => 0,
                    'repeat_recharge_amount' => 0,
                    'total_recharge_amount' => 0,
                ];
            }
            $orderMap[$uid]['total_recharge_amount'] += (float)$order['amount'];
            $registerDate = $userRegisterDateMap[$uid] ?? '';
            $payDate = substr($order['pay_time'], 0, 10);
            if ($payDate === $registerDate) {
                $orderMap[$uid]['first_recharge_amount'] += (float)$order['amount'];
            } else {
                $orderMap[$uid]['repeat_recharge_amount'] += (float)$order['amount'];
            }
        }

        // 合并到用户列表并补全所有前端需要的字段
        foreach ($userList as &$user) {
            $uid = $user['uuid'];
            $user['first_recharge_amount'] = $orderMap[$uid]['first_recharge_amount'] ?? 0;
            $user['repeat_recharge_amount'] = $orderMap[$uid]['repeat_recharge_amount'] ?? 0;
            $user['total_recharge_amount'] = $orderMap[$uid]['total_recharge_amount'] ?? 0;

            // 新增：补全所有表格字段
            $user['channel_domain'] = $user['channel_domain'] ?? '';
            $user['channel_name'] = '';
            $user['first_recharge_time'] = '';
            $user['repeat_recharge_count'] = 0;
            $user['last_recharge_time'] = '';
            $user['recharge_type'] = '';
        }
        unset($user);

        // 充值类型筛选（首充/复充）
        if ($rechargeType === 'first') {
            $userList = array_filter($userList, function($u) {
                return $u['first_recharge_amount'] > 0;
            });
        } elseif ($rechargeType === 'repeat') {
            $userList = array_filter($userList, function($u) {
                return $u['repeat_recharge_amount'] > 0;
            });
        }

        // 重新分页（因为array_filter后数量可能变少）
        $userList = array_values($userList);
        $total = count($userList);
        $userList = array_slice($userList, 0, $pageSize);

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'list' => $userList,
                'total' => $total,
            ],
        ]);
    }

    /**
     * 获取用户充值订单数据
     * 对应前端: getUserRechargeOrders
     * 路由: Route::get('api/channelStats/userRechargeOrders', 'app\controller\api\ChannelStatsController@userRechargeOrders');
     * @return \think\response\Json
     */
    public function userRechargeOrders()
    {
        $param = Request::param();

        $userId = $param['user_id'] ?? null;
        $statisticDate = $param['statistic_date'] ?? '';
        $page = (int)($param['page'] ?? 1);
        $pageSize = (int)($param['page_size'] ?? 10);

        if (empty($userId)) {
            return json([
                'code' => 400,
                'message' => '缺少用户ID参数',
                'data' => []
            ]);
        }

        $where = [
            ['user_uuid', '=', $userId],
        ];
        if (!empty($statisticDate)) {
            $where[] = ['pay_time', 'between time', [$statisticDate . ' 00:00:00', $statisticDate . ' 23:59:59']];
        }

        $list = Db::name('recharge_orders')
            ->where($where)
            ->order('pay_time', 'desc')
            ->page($page, $pageSize)
            ->field('order_id, amount, pay_time, status as pay_status, remark')
            ->select()
            ->toArray();

        $total = Db::name('recharge_orders')->where($where)->count();

        // 状态转中文
        foreach ($list as &$row) {
            if ($row['pay_status'] === '已支付' || $row['pay_status'] === 'paid') {
                $row['pay_status'] = '已支付';
            } else {
                $row['pay_status'] = '未支付';
            }
        }

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total,
            ],
        ]);
    }
}