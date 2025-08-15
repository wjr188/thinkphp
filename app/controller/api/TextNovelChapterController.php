<?php
declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\facade\Request;

class TextNovelChapterController extends BaseController
{
    // 章节列表（分页）
    public function list()
    {
        $params = Request::get();
        $novelId = intval($params['novelId'] ?? 0);
        $page = max(1, intval($params['page'] ?? 1));
        $pageSize = max(1, intval($params['pageSize'] ?? 10));

        if (!$novelId) {
            return json(['code'=>1, 'msg'=>'缺少小说ID']);
        }

        $query = Db::name('text_novel_chapter')->where('novel_id', $novelId);
        $total = $query->count();

        $list = $query->field([
                'id',
                'novel_id',
                'title',
                'chapter_order as order_num',
                'is_vip',      // 修改了：原 is_paid 改为 is_vip
                'coin'          // 修改了：原 coin_required 改为 coin
            ])
            ->order('chapter_order asc,id asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 统计字数和补 cover 字段
        foreach ($list as &$item) {
            // 如果表中没有 content 字段，这行可以不要
            $item['word_count'] = isset($item['content']) ? mb_strlen(strip_tags($item['content'])) : 0;
            $item['cover'] = '';
        }
        unset($item);

        return json(['code'=>0, 'msg'=>'success', 'data'=>[
            'list' => $list,
            'total' => $total,
        ]]);
    }

    // 章节详情
    public function read($id = null)
{
    if ($id === null) {
        $id = $this->request->param('id');
    }
    if (!$id) {
        return $this->error('缺少章节ID');
    }

    $chapter = Db::name('text_novel_chapter')->find($id);
    if (!$chapter) {
        return $this->error('章节不存在');
    }

    // 权限校验
    $needVip = intval($chapter['is_vip'] ?? 0);
    $needCoin = intval($chapter['coin'] ?? 0);

    // 获取当前登录用户
    $user = $this->getLoginUser();
    if (!$user) {
        return $this->error('请先登录', 401);
    }

    // 查询VIP权限
    $canViewVipVideo = 0;
    $canWatchCoin = 0;
    if (!empty($user['vip_card_id'])) {
        $vipCardType = Db::name('vip_card_type')->where('id', $user['vip_card_id'])->find();
        if ($vipCardType) {
            $canViewVipVideo = intval($vipCardType['can_view_vip_video'] ?? 0);
            $canWatchCoin = intval($vipCardType['can_watch_coin'] ?? 0);
        }
    }

    // 查询是否已解锁
    $isUnlocked = Db::name('user_video_unlock')
        ->where('user_id', $user['uuid'])
        ->where('video_id', $id)
        ->where('type', 3) // 小说类型
        ->find();

    if ($needVip === 1 && !$canViewVipVideo && !$isUnlocked) {
        return $this->error('需要VIP才能阅读', 403);
    }
    if ($needCoin > 0 && !$canWatchCoin && !$isUnlocked) {
        return $this->error('该章节需要购买，请先解锁', 403);
    }

    // 通过权限，返回章节内容
    $chapter['word_count'] = mb_strlen(strip_tags($chapter['content'] ?? ''));

    return $this->success($chapter);
}

    // 新增章节
    public function add()
    {
        $data = Request::post();
        if (empty($data['novel_id']) || empty($data['title']) || empty($data['chapter_order']) || empty($data['content'])) {
            return json(['code'=>1, 'msg'=>'必填项缺失']);
        }

        $save = [
            'novel_id'      => intval($data['novel_id']),
            'title'         => trim($data['title']),
            'chapter_order' => intval($data['chapter_order']),
            'content'       => $data['content'],
            'is_vip'        => intval($data['is_vip'] ?? 0),        // 修改了：原 is_paid 改为 is_vip
            'coin'          => intval($data['coin'] ?? 0),          // 修改了：原 coin_required 改为 coin
            'publish_time'  => date('Y-m-d H:i:s'),
        ];
        $id = Db::name('text_novel_chapter')->insertGetId($save);

        // ⭐ 新增章节后同步小说的章节数
        if ($id && !empty($save['novel_id'])) {
            $novelId = $save['novel_id'];
            $count = Db::name('text_novel_chapter')->where('novel_id', $novelId)->count();
            Db::name('text_novel')->where('id', $novelId)->update(['chapter_count' => $count]);
        }

        return json(['code'=>0, 'msg'=>'success', 'data'=>['id'=>$id]]);
    }

    // 更新章节
    public function update()
    {
        $data = Request::post();
        if (empty($data['id'])) {
            return json(['code'=>1, 'msg'=>'缺少章节ID']);
        }

        $chapter = Db::name('text_novel_chapter')->find($data['id']);
        if (!$chapter) return json(['code'=>1, 'msg'=>'章节不存在']);

        $save = [
            'title'         => trim($data['title']),
            'chapter_order' => intval($data['chapter_order']),
            'content'       => $data['content'],
            'is_vip'        => intval($data['is_vip'] ?? 0),        // 修改了：原 is_paid 改为 is_vip
            'coin'          => intval($data['coin'] ?? 0),          // 修改了：原 coin_required 改为 coin
        ];
        Db::name('text_novel_chapter')->where('id', $data['id'])->update($save);

        // ⚠️ 正常章节更新不用同步章节数（只有 novel_id 变更才需要，极少见）
        return json(['code'=>0, 'msg'=>'success']);
    }

    // 删除章节
    public function delete()
    {
        $id = intval(Request::post('id'));
        if (!$id) return json(['code'=>1, 'msg'=>'缺少ID']);

        // 查找所属小说
        $chapter = Db::name('text_novel_chapter')->find($id);
        if (!$chapter) return json(['code'=>1, 'msg'=>'章节不存在']);
        $novelId = $chapter['novel_id'];

        Db::name('text_novel_chapter')->where('id', $id)->delete();

        // ⭐ 删除章节后同步小说的章节数
        $count = Db::name('text_novel_chapter')->where('novel_id', $novelId)->count();
        Db::name('text_novel')->where('id', $novelId)->update(['chapter_count' => $count]);

        return json(['code'=>0, 'msg'=>'success']);
    }
// 设为免费（章节）
public function setFree()
{
    $ids = Request::post('ids'); // 章节ID数组
    if (empty($ids) || !is_array($ids)) {
        return json(['code' => 1, 'msg' => '参数错误']);
    }

    // 更新章节表，设置 is_vip=0，coin=0 表示免费
    Db::name('text_novel_chapter')->whereIn('id', $ids)->update([
        'is_vip' => 0,
        'coin' => 0,
    ]);

    return json(['code' => 0, 'msg' => '章节已设为免费']);
}

    // 批量删除
    public function batchDelete()
    {
        $ids = Request::post('ids');
        if (empty($ids) || !is_array($ids)) return json(['code'=>1, 'msg'=>'缺少参数']);

        // 先查涉及到的所有 novel_id
        $novelIds = Db::name('text_novel_chapter')->whereIn('id', $ids)->distinct(true)->column('novel_id');

        Db::name('text_novel_chapter')->whereIn('id', $ids)->delete();

        // ⭐ 批量同步所有受影响小说的章节数
        foreach ($novelIds as $novelId) {
            $count = Db::name('text_novel_chapter')->where('novel_id', $novelId)->count();
            Db::name('text_novel')->where('id', $novelId)->update(['chapter_count' => $count]);
        }

        return json(['code'=>0, 'msg'=>'success']);
    }

    // 批量更新排序
    public function batchUpdateOrder()
    {
        $chapters = Request::post('chapters');
        if (empty($chapters) || !is_array($chapters)) return json(['code'=>1, 'msg'=>'参数错误']);
        foreach ($chapters as $item) {
            if (!empty($item['id']) && isset($item['chapter_order'])) {
                Db::name('text_novel_chapter')->where('id', intval($item['id']))->update(['chapter_order'=>intval($item['chapter_order'])]);
            }
        }
        return json(['code'=>0, 'msg'=>'success']);
    }
}
