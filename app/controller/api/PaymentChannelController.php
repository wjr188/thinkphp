<?php
declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db;

/**
 * 支付通道管理控制器（含金额区间/指定面额过滤 & H5 精简接口）
 */
class PaymentChannelController extends BaseController
{
    protected string $table = 'payment_channel';

    /**
     * 支付通道列表（分页、筛选、统计）
     * GET /api/payment_channel/list
     */
    public function list()
    {
        $params = Request::get([
            'page'       => 1,
            'pageSize'   => 10,
            'name'       => '',
            'code'       => '',
            'type'       => '',
            'status'     => '',
            'start_time' => '',
            'end_time'   => '',
        ]);

        $page     = (int) $params['page'];
        $pageSize = (int) $params['pageSize'];

        $query = Db::table($this->table);

        if ($params['name'] !== '')  $query->whereLike('name', '%' . $params['name'] . '%');
        if ($params['code'] !== '')  $query->whereLike('code', '%' . $params['code'] . '%');
        if ($params['type'] !== '')  $query->where('type', $params['type']);
        if ($params['status'] !== '' && $params['status'] !== null) {
            $query->where('status', (int) $params['status']);
        }

        // 列表时间筛选（按创建时间）
        $range = null;
        if ($params['start_time'] && $params['end_time']) {
            $range = [$params['start_time'] . ' 00:00:00', $params['end_time'] . ' 23:59:59'];
            $query->whereBetween('create_time', $range);
        }

        $total = $query->count();

        $list = $query->order('sort', 'asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        if (!$list) {
            return $this->success(['list' => [], 'total' => 0]);
        }

        // -------- 统计汇总（避免 N+1）--------
        $ids = array_column($list, 'id');

        // 累计收款
        $sumTotal = Db::table('recharge_orders')
            ->whereIn('channel_id', $ids)
            ->group('channel_id')
            ->column('SUM(amount) AS s', 'channel_id'); // [channel_id => sum]

        // 区间收款（按支付时间）
        $sumRange = [];
        if ($range) {
            $sumRange = Db::table('recharge_orders')
                ->whereIn('channel_id', $ids)
                ->whereBetween('pay_time', $range)
                ->group('channel_id')
                ->column('SUM(amount) AS s', 'channel_id');
        }

        foreach ($list as &$item) {
            $id = $item['id'];
            $item['total_amount'] = number_format((float)($sumTotal[$id] ?? 0), 2, '.', '');
            $item['today_amount'] = $range
                ? number_format((float)($sumRange[$id] ?? 0), 2, '.', '')
                : $item['total_amount'];
        }

        return $this->success(['list' => $list, 'total' => $total]);
    }

    /**
     * 新增支付通道
     * POST /api/payment_channel/create
     */
    public function create()
    {
        $data = Request::only([
            'code',
            'name', 'type', 'api_url', 'link_url', 'appid', 'mchid', 'api_key',
            'pay_notify_url', 'pay_method', 'sort', 'status', 'remark',
            // 金额限制
            'min_amount', 'max_amount', 'allow_amounts',
        ]);

        // 规范金额字段
        $data['min_amount']    = $this->toNullableDecimal($data['min_amount'] ?? null);
        $data['max_amount']    = $this->toNullableDecimal($data['max_amount'] ?? null);
        $data['allow_amounts'] = $this->normalizeAllowAmountsString($data['allow_amounts'] ?? '');

        // 校验区间
        if ($data['min_amount'] !== null && $data['max_amount'] !== null) {
            if (bccomp($data['min_amount'], $data['max_amount'], 2) === 1) {
                return $this->error('最小金额不能大于最大金额');
            }
        }

        // code 唯一
        if (isset($data['code']) && $data['code'] !== '' &&
            Db::table($this->table)->where('code', $data['code'])->find()
        ) {
            return $this->error('支付标识/方式码已存在，请更换');
        }

        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');

        $id = Db::table($this->table)->insertGetId($data);

        return $id
            ? $this->success(['id' => $id], '新增成功')
            : $this->error('新增失败');
    }

    /**
     * 更新支付通道
     * PUT /api/payment_channel/update/:id
     */
    public function update($id)
    {
        $data = Request::only([
            'code',
            'name', 'type', 'api_url', 'link_url', 'appid', 'mchid', 'api_key',
            'pay_notify_url', 'pay_method', 'sort', 'status', 'remark',
            // 金额限制
            'min_amount', 'max_amount', 'allow_amounts',
        ]);

        // code 唯一（排除自身）
        if (isset($data['code']) && $data['code'] !== '' &&
            Db::table($this->table)->where('code', $data['code'])->where('id', '<>', $id)->find()
        ) {
            return $this->error('支付标识/方式码已存在，请更换');
        }

        // 规范金额字段
        if (array_key_exists('min_amount', $data)) {
            $data['min_amount'] = $this->toNullableDecimal($data['min_amount']);
        }
        if (array_key_exists('max_amount', $data)) {
            $data['max_amount'] = $this->toNullableDecimal($data['max_amount']);
        }
        if (array_key_exists('allow_amounts', $data)) {
            $data['allow_amounts'] = $this->normalizeAllowAmountsString($data['allow_amounts'] ?? '');
        }

        // 校验区间
        if (isset($data['min_amount'], $data['max_amount']) &&
            $data['min_amount'] !== null && $data['max_amount'] !== null
        ) {
            if (bccomp($data['min_amount'], $data['max_amount'], 2) === 1) {
                return $this->error('最小金额不能大于最大金额');
            }
        }

        $data['update_time'] = date('Y-m-d H:i:s');

        $res = Db::table($this->table)->where('id', $id)->update($data);

        return $res !== false
            ? $this->success([], '更新成功')
            : $this->error('更新失败');
    }

    /**
     * 删除支付通道
     * DELETE /api/payment_channel/delete/:id
     */
    public function delete($id)
    {
        $res = Db::table($this->table)->where('id', $id)->delete();

        return $res
            ? $this->success([], '删除成功')
            : $this->error('删除失败');
    }

    /**
     * 切换支付通道状态
     * PUT /api/payment_channel/status/:id
     */
    public function status($id)
    {
        $status = (int) Request::post('status');

        $res = Db::table($this->table)
            ->where('id', $id)
            ->update(['status' => $status, 'update_time' => date('Y-m-d H:i:s')]);

        return $res !== false
            ? $this->success([], '状态切换成功')
            : $this->error('状态切换失败');
    }

    /**
     * （后台用）获取启用的通道（可选 amount 金额过滤）
     * GET /api/payment_channels/list_enabled?amount=100
     */
    public function listEnabled()
    {
        $amountParam = Request::get('amount', '');
        $amount = $this->toNullableDecimal($amountParam);

        $rows = Db::table($this->table)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        if ($amount !== null) {
            $rows = array_values(array_filter($rows, fn($row) => $this->isAmountAllowed($row, $amount)));
        }

        return $this->success(['list' => $rows]);
    }

    /**
     * （H5用）获取启用的通道（仅公开字段 + 规范化 type，支持金额过滤）
     * GET /api/payment_channels/h5?amount=100
     *
     * 返回：id / name / code / type（alipay|wechat|manual）
     */
    public function listForH5()
    {
        $amountParam = Request::get('amount', '');
        $amount = $this->toNullableDecimal($amountParam);

        // 取完整行用于过滤
        $rows = Db::table($this->table)
            ->where('status', 1)
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        if ($amount !== null) {
            $rows = array_values(array_filter($rows, fn($row) => $this->isAmountAllowed($row, $amount)));
        }

        // 裁剪字段并规范化 type
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'   => $r['id'],
                'name' => $r['name'],
                'code' => $r['code'],
                'type' => $this->normalizeType($r['type'] ?? ''),
            ];
        }

        return $this->success(['list' => $out]);
    }

    /* ==================== 工具方法 ==================== */

    /**
     * 统一渠道类型：兼容中文/大小写/别名，输出 alipay | wechat | manual
     */
    private function normalizeType(?string $t): string
    {
        $t = strtolower((string) $t);

        if ($t === '') return 'manual';

        // 支付宝
        if (str_contains($t, 'ali') || str_contains($t, '支付宝') || $t === 'alipay') {
            return 'alipay';
        }
        // 微信
        if (str_contains($t, 'wx') || str_contains($t, 'wechat') || str_contains($t, '微信')) {
            return 'wechat';
        }
        // 兜底（人工/其它）
        return 'manual';
    }

    /**
     * 金额是否允许：
     * 1) 若存在 allow_amounts（逗号分隔），仅当金额在列表里才允许（忽略区间）
     * 2) 否则按 [min_amount, max_amount] 闭区间判断（边界包含）
     * 所有比较统一两位小数
     */
    private function isAmountAllowed(array $row, string $amount): bool
    {
        // 指定面额优先
        if (!empty($row['allow_amounts'])) {
            $allowed = $this->parseAllowAmounts($row['allow_amounts']); // ['50.00','88.00',...]
            return in_array($amount, $allowed, true);
        }

        // 区间判断（边界包含）
        $min = isset($row['min_amount']) && $row['min_amount'] !== ''
            ? $this->normalizeDecimal((string)$row['min_amount'])
            : null;

        $max = isset($row['max_amount']) && $row['max_amount'] !== ''
            ? $this->normalizeDecimal((string)$row['max_amount'])
            : null;

        if ($min !== null && bccomp($amount, $min, 2) < 0) return false;
        if ($max !== null && bccomp($amount, $max, 2) > 0) return false;

        return true;
    }

    /**
     * 解析 allow_amounts 字符串 -> 规范化金额数组（两位小数）
     */
    private function parseAllowAmounts(string $s): array
    {
        // 支持中英文逗号、分号、顿号、换行
        $s = str_replace(['，', '、', ';', '；', "\r", "\n", "\t"], ',', $s);
        $parts = array_filter(array_map('trim', explode(',', $s)), fn($v) => $v !== '');
        $out = [];
        foreach ($parts as $p) {
            $norm = $this->toNullableDecimal($p);
            if ($norm !== null) {
                $out[] = $norm; // 两位小数字符串
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * 标准化金额为两位小数字符串；非法/空则返回 null
     */
    private function toNullableDecimal($v): ?string
    {
        if ($v === null) return null;
        if ($v === '')   return null;
        if (!is_numeric($v)) return null;
        return $this->normalizeDecimal((string)$v);
    }

    /**
     * 两位小数字符串
     */
    private function normalizeDecimal(string $v): string
    {
        return number_format((float)$v, 2, '.', '');
    }

    /**
     * 规范 allow_amounts 存库字符串（把中文分隔符替换为英文逗号，清洗并去重）
     * 返回 null 则表示不限制固定面额
     */
    private function normalizeAllowAmountsString(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;
        $arr = $this->parseAllowAmounts($s);
        return $arr ? implode(',', $arr) : null;
    }
}
