<?php
// E:\ThinkPHP6\app\controller\api\AdminMemberCardController.php
declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db;
use think\response\Json;

class AdminMemberCardController extends BaseController
{
    /**
     * 把金额规整为两位小数的“元”字符串（避免浮点精度问题）
     * - MySQL DECIMAL 取出一般是字符串，这里再次规整更稳妥
     * - 若启用 bcmath，优先使用
     */
    private function fmtYuan($v): string
    {
        $s = str_replace(',', '', (string)$v);
        if (function_exists('bcadd')) {
            return bcadd($s, '0', 2); // 保留 2 位小数
        }
        return number_format((float)$s, 2, '.', '');
    }

    /**
     * 列表分页（金额按“元字符串”返回）
     * GET /api/admin_member_card/index
     */
    public function index(): Json
    {
        $page     = Request::get('page/d', 1);
        $pageSize = Request::get('pageSize/d', 10);

        $query = Db::name('vip_card_type');
        $total = $query->count();
        $rows  = $query
            ->page($page, $pageSize)
            ->order('id', 'asc')
            ->field('id,tag,name,old_price,price,duration,duration_unit,desc,status,create_time,update_time,can_watch_coin,can_view_vip_video,benefit_keys_json')
            ->select()
            ->toArray();

        $list = array_map(function ($r) {
            return [
                'id'                 => $r['id'],
                'tag'                => $r['tag'],
                'name'               => $r['name'],
                'oldPrice'           => $this->fmtYuan($r['old_price']), // 元字符串
                'price'              => $this->fmtYuan($r['price']),     // 元字符串
                'duration'           => (int)$r['duration'],
                'duration_unit'      => $r['duration_unit'] ?? 'DAY',
                'desc'               => (string)$r['desc'],
                'status'             => $r['status'] ? 'ENABLED' : 'DISABLED',
                'createTime'         => $r['create_time'],
                'updateTime'         => $r['update_time'],
                'can_watch_coin'     => (int)($r['can_watch_coin'] ?? 0),
                'can_view_vip_video' => (int)($r['can_view_vip_video'] ?? 0),
                'benefitKeys'        => !empty($r['benefit_keys_json']) ? json_decode($r['benefit_keys_json'], true) : [],
            ];
        }, $rows);

        return json([
            'code' => 0,
            'msg'  => '获取VIP卡片列表成功',
            'data' => [
                'list'     => $list,
                'total'    => $total,
                'page'     => $page,
                'pageSize' => $pageSize,
            ],
        ]);
    }

    /**
     * 新增（金额用元，支持两位小数）
     * POST /api/admin_member_card/save
     */
    public function save(): Json
    {
        $data = Request::post();

        $validate = $this->validate($data, [
            'tag|顶部标签'        => 'require|max:50',
            'name|卡片名称'       => 'require|max:100',
            'oldPrice|原价(元)'   => 'require|regex:/^\d+(\.\d{1,2})?$/',
            'price|现价(元)'      => 'require|regex:/^\d+(\.\d{1,2})?$/',
            'duration|时长'       => 'require|integer|egt:-1',
            'desc|描述'           => 'max:255',
        ]);
        if ($validate !== true) {
            return json(['code' => 0, 'msg' => $validate]);
        }

        // 现价不得高于原价（按元比较，保留2位小数）
        if (bccomp((string)$data['price'], (string)$data['oldPrice'], 2) === 1) {
            return json(['code' => 0, 'msg' => '现价不能高于原价']);
        }

        $saveData = [
            'tag'                => $data['tag'],
            'name'               => $data['name'],
            'old_price'          => $this->fmtYuan($data['oldPrice']), // 元，DECIMAL(10,2)
            'price'              => $this->fmtYuan($data['price']),    // 元，DECIMAL(10,2)
            'duration'           => (int)$data['duration'],
            'duration_unit'      => $data['duration_unit'] ?? 'DAY',    // 默认 DAY
            'desc'               => $data['desc'] ?? '',
            'status'             => 1,
            'create_time'        => date('Y-m-d H:i:s'),
            'update_time'        => date('Y-m-d H:i:s'),
            'can_watch_coin'     => isset($data['can_watch_coin']) ? (int)$data['can_watch_coin'] : 0,
            'can_view_vip_video' => isset($data['can_view_vip_video']) ? (int)$data['can_view_vip_video'] : 0,
            'benefit_keys_json'  => (array_key_exists('benefitKeys', $data) && is_array($data['benefitKeys']))
                ? json_encode($data['benefitKeys'], JSON_UNESCAPED_UNICODE)
                : '[]',
        ];

        if (!empty($data['id'])) {
            Db::name('vip_card_type')->where('id', $data['id'])->update($saveData);
        } else {
            Db::name('vip_card_type')->insert($saveData);
        }

        return json(['code' => 0, 'msg' => '保存成功']);
    }

    /**
     * 编辑（金额用元，支持两位小数）
     * PUT /api/admin_member_card/update/:id
     */
    public function update(int $id): Json
    {
        $data = Request::put();
        unset($data['id']);

        $validate = $this->validate($data, [
            'tag|顶部标签'        => 'require|max:50',
            'name|卡片名称'       => 'require|max:100',
            'oldPrice|原价(元)'   => 'require|regex:/^\d+(\.\d{1,2})?$/',
            'price|现价(元)'      => 'require|regex:/^\d+(\.\d{1,2})?$/',
            'duration|时长'       => 'require|integer|egt:-1',
            'desc|描述'           => 'max:255',
        ]);
        if ($validate !== true) {
            return json(['code' => 0, 'msg' => $validate]);
        }

        if (bccomp((string)$data['price'], (string)$data['oldPrice'], 2) === 1) {
            return json(['code' => 0, 'msg' => '现价不能高于原价']);
        }

        $upd = [
            'tag'                => $data['tag'],
            'name'               => $data['name'],
            'old_price'          => $this->fmtYuan($data['oldPrice']),
            'price'              => $this->fmtYuan($data['price']),
            'duration'           => (int)$data['duration'],
            'duration_unit'      => $data['duration_unit'] ?? 'DAY', // 默认 DAY
            'desc'               => $data['desc'] ?? '',
            'update_time'        => date('Y-m-d H:i:s'),
            'can_view_vip_video' => (int)($data['can_view_vip_video'] ?? 0),
            'can_watch_coin'     => (int)($data['can_watch_coin'] ?? 0),
            'benefit_keys_json'  => (array_key_exists('benefitKeys', $data) && is_array($data['benefitKeys']))
                ? json_encode($data['benefitKeys'], JSON_UNESCAPED_UNICODE)
                : '[]',
        ];

        Db::name('vip_card_type')->where('id', $id)->update($upd);

        // 返回驼峰
        $resp = [
            'id'                 => $id,
            'tag'                => $upd['tag'],
            'name'               => $upd['name'],
            'oldPrice'           => $upd['old_price'],
            'price'              => $upd['price'],
            'duration'           => $upd['duration'],
            'duration_unit'      => $upd['duration_unit'],
            'desc'               => $upd['desc'],
            'updateTime'         => $upd['update_time'],
            'can_view_vip_video' => $upd['can_view_vip_video'],
            'can_watch_coin'     => $upd['can_watch_coin'],
            'benefitKeys'        => json_decode($upd['benefit_keys_json'], true),
        ];

        return json(['code' => 0, 'msg' => '更新VIP卡片成功', 'data' => $resp]);
    }

    /**
     * 启用/禁用
     * PATCH /api/admin_member_card/toggleStatus/:id
     */
    public function toggleStatus(int $id): Json
    {
        $post = Request::patch();
        $validate = $this->validate($post, [
            'status|状态' => 'require|in:ENABLED,DISABLED',
        ]);
        if ($validate !== true) {
            return json(['code' => 0, 'msg' => $validate]);
        }

        $new = $post['status'] === 'ENABLED' ? 1 : 0;
        Db::name('vip_card_type')->where('id', $id)->update([
            'status'      => $new,
            'update_time' => date('Y-m-d H:i:s'),
        ]);

        return json(['code' => 0, 'msg' => 'VIP卡片状态切换成功']);
    }

    /**
     * 删除
     * DELETE /api/admin_member_card/delete/:id
     */
    public function delete(int $id): Json
    {
        Db::name('vip_card_type')->where('id', $id)->delete();
        return json(['code' => 0, 'msg' => 'VIP卡片删除成功']);
    }

    /**
     * （H5 用）下拉全量：金额直接返回“元”的两位小数字符串
     * GET /api/admin_member_card/all
     */
    public function all(): Json
    {
        $rows = Db::name('vip_card_type')
            ->where('status', 1)
            ->order('id', 'asc')
            ->field('id,tag,name,old_price,price,duration,duration_unit,desc,can_watch_coin,can_view_vip_video,benefit_keys_json')
            ->select()
            ->toArray();

        $list = array_map(function ($r) {
            return [
                'id'                 => $r['id'],
                'tag'                => $r['tag'],
                'name'               => $r['name'],
                'oldPrice'           => $this->fmtYuan($r['old_price']),
                'price'              => $this->fmtYuan($r['price']),
                'duration'           => (int)$r['duration'],
                'duration_unit'      => $r['duration_unit'] ?? 'DAY',
                'desc'               => (string)$r['desc'],
                'can_watch_coin'     => (int)($r['can_watch_coin'] ?? 0),
                'can_view_vip_video' => (int)($r['can_view_vip_video'] ?? 0),
                'benefitKeys'        => !empty($r['benefit_keys_json']) ? json_decode($r['benefit_keys_json'], true) : [],
            ];
        }, $rows);

        return json([
            'code' => 0,
            'msg'  => '所有会员卡类型',
            'data' => $list,
        ]);
    }

    /**
     * 判断用户当前会员卡是否有对应的观看权限
     * @param int    $user_id 用户ID
     * @param string $type    权限类型：'vip' 或 'coin'
     */
    public function checkCardPermission($user_id, $type = 'vip'): bool
    {
        $user = Db::name('users')->where('id', $user_id)->find();
        if (!$user || !$user['vip_card_id']) {
            return false;
        }
        $vipCard = Db::name('vip_card_type')->where('id', $user['vip_card_id'])->find();
        if (!$vipCard) {
            return false;
        }

        if ($type === 'vip') {
            return (int)$vipCard['can_view_vip_video'] === 1;
        }
        if ($type === 'coin') {
            return (int)$vipCard['can_watch_coin'] === 1;
        }
        return false;
    }
}
