<?php
namespace app\controller\api;

use app\BaseController;
use think\facade\Request;

class NoticeController extends BaseController
{
    public function myPage()
    {
        // 获取分页参数和 isRead 参数
        $pageNum = Request::get('pageNum', 1);
        $pageSize = Request::get('pageSize', 5);
        $isRead = Request::get('isRead', 0); // 0 未读, 1 已读

        // 模拟数据
        $list = [];
        for ($i = 0; $i < $pageSize; $i++) {
            $list[] = [
                'id' => ($pageNum - 1) * $pageSize + $i + 1,
                'title' => '这是模拟通知标题 ' . (($pageNum - 1) * $pageSize + $i + 1),
                'content' => '这是模拟通知内容。当前页 ' . $pageNum . ', 每页 ' . $pageSize . ', 是否已读 ' . $isRead,
                'createTime' => date('Y-m-d H:i:s', strtotime('-' . rand(1, 10) . ' days')),
                'isRead' => (int)$isRead,
            ];
        }

        return json([
            'code' => 0,
            'msg' => '获取通知成功',
            'data' => [
                'list' => $list,
                'total' => 100, // 模拟总数
                'pageNum' => (int)$pageNum,
                'pageSize' => (int)$pageSize,
            ]
        ]);
    }
}