<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db;
use think\Validate; // 用于数据验证

class ChannelManageController extends BaseController
{
    /**
     * 获取渠道列表
     * 对应前端: getChannelList
     * 路由: Route::get('api/channel/list', 'app\controller\api\ChannelManageController@list');
     * @return \think\response\Json
     */
    public function list()
    {
        $param = Request::param();

        $channelName = $param['channel_name'] ?? '';
        $channelDomain = $param['channel_domain'] ?? '';
        $status = $param['status'] ?? null; // null 表示不筛选状态
        $page = (int)($param['page'] ?? 1);
        $pageSize = (int)($param['page_size'] ?? 10);

        $where = [];
        if (!empty($channelName)) {
            $where[] = ['channel_name', 'like', '%' . $channelName . '%'];
        }
        if (!empty($channelDomain)) {
            $where[] = ['channel_domain', 'like', '%' . $channelDomain . '%'];
        }
        if ($status !== null && ($status === 0 || $status === 1)) { // 确保是0或1
            $where[] = ['status', '=', $status];
        }

        // 假设您有一个 'channels' 表来存储渠道信息
        $list = Db::name('channels')
                    ->where($where)
                    ->page($page, $pageSize)
                    ->order('create_time', 'desc') // 通常按创建时间倒序
                    ->select()
                    ->toArray();

        $total = Db::name('channels')
                    ->where($where)
                    ->count();

        return json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total,
            ],
        ]);
    }

    /**
     * 添加新渠道
     * 对应前端: addChannel
     * 路由: Route::post('api/channel/add', 'app\controller\api\ChannelManageController@add');
     * @return \think\response\Json
     */
    public function add()
    {
        $data = Request::param();

        // 验证数据
        $validate = new Validate([
            'channel_name|渠道名称' => 'require|max:50|unique:channels', // unique:channels 确保渠道名唯一
            'channel_domain|渠道域名' => 'require|url|unique:channels', // url 验证URL格式，unique:channels 确保域名唯一
            'status|状态' => 'in:0,1',
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 400, 'message' => $validate->getError()]);
        }

        try {
            $data['create_time'] = date('Y-m-d H:i:s');
            $data['update_time'] = date('Y-m-d H:i:s');
            // 可以自动生成 channel_id，例如使用 UUID 或自增ID
            // 如果您的表是自增ID，不需要手动设置 channel_id

            $result = Db::name('channels')->insert($data);

            if ($result) {
                return json(['code' => 200, 'message' => '渠道添加成功']);
            } else {
                return json(['code' => 500, 'message' => '渠道添加失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'message' => '服务器错误：' . $e->getMessage()]);
        }
    }

    /**
     * 更新渠道信息
     * 对应前端: updateChannel
     * 路由: Route::post('api/channel/update', 'app\controller\api\ChannelManageController@update');
     * @return \think\response\Json
     */
    public function update()
    {
        $data = Request::param();

        // 验证数据
        $validate = new Validate([
            'channel_id|渠道ID' => 'require', // 更新必须有ID
            'channel_name|渠道名称' => 'max:50|unique:channels,channel_name,'.$data['channel_id'].',channel_id', // 排除自身ID
            'channel_domain|渠道域名' => 'url|unique:channels,channel_domain,'.$data['channel_id'].',channel_id', // 排除自身ID
            'status|状态' => 'in:0,1',
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 400, 'message' => $validate->getError()]);
        }

        // 确保 channel_id 存在且有效
        $channelId = $data['channel_id'];
        $channel = Db::name('channels')->where('channel_id', $channelId)->find();
        if (!$channel) {
            return json(['code' => 404, 'message' => '渠道不存在']);
        }

        try {
            unset($data['channel_id']); // 移除ID，因为它在where条件中使用
            $data['update_time'] = date('Y-m-d H:i:s');

            $result = Db::name('channels')->where('channel_id', $channelId)->update($data);

            if ($result !== false) { // update 返回 0 表示没有更新但操作成功
                return json(['code' => 200, 'message' => '渠道更新成功']);
            } else {
                return json(['code' => 500, 'message' => '渠道更新失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'message' => '服务器错误：' . $e->getMessage()]);
        }
    }

    /**
     * 删除渠道
     * 对应前端: deleteChannel
     * 路由: Route::post('api/channel/delete', 'app\controller\api\ChannelManageController@delete');
     * @return \think\response\Json
     */
    public function delete()
    {
        $param = Request::param();
        $channelId = $param['channel_id'] ?? null;

        if (empty($channelId)) {
            return json(['code' => 400, 'message' => '缺少渠道ID参数']);
        }

        // 检查渠道是否存在
        $channel = Db::name('channels')->where('channel_id', $channelId)->find();
        if (!$channel) {
            return json(['code' => 404, 'message' => '渠道不存在']);
        }

        // 实际业务中可能需要检查该渠道是否有绑定的用户或数据，如果有则不允许删除
        // 例如：
        // $hasRelatedData = Db::name('user_recharge_details')->where('channel_id', $channelId)->count();
        // if ($hasRelatedData > 0) {
        //     return json(['code' => 403, 'message' => '该渠道下有相关数据，无法删除！']);
        // }

        try {
            $result = Db::name('channels')->where('channel_id', $channelId)->delete();

            if ($result) {
                return json(['code' => 200, 'message' => '渠道删除成功']);
            } else {
                return json(['code' => 500, 'message' => '渠道删除失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'message' => '服务器错误：' . $e->getMessage()]);
        }
    }
}