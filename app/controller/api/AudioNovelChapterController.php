<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use app\BaseController;
    use Firebase\JWT\JWT;

class AudioNovelChapterController extends BaseController
{  

    // 同步章节数到 audio_novels 表
    private function syncChapterCount($novelId)
    {
        if (!$novelId) return;
        $count = Db::name('audio_novel_chapter')->where('novel_id', $novelId)->count();
        Db::name('audio_novels')->where('id', $novelId)->update(['chapter_count' => $count]);
    }

    // 获取章节列表
    // 获取章节列表
public function list(Request $request)
{
    $novelId = $request->get('novelId');
    $page = $request->get('page', 1);
    $pageSize = $request->get('pageSize', 10);
    $type = $request->get('type', 'h5'); // 默认为 h5

    if (!$novelId) {
        return json(['code' => 1, 'msg' => 'novelId为必填项']);
    }

    // 字段控制
    $fields = 'id,novel_id,title,chapter_order,is_vip,coin,duration,is_trial,trial_duration,cover_url,remark,publish_time,update_time';
    if ($type === 'admin') {
        $fields .= ',audio_url';
    }

    $query = Db::name('audio_novel_chapter')
        ->where('novel_id', $novelId)
        ->field($fields);

    $total = $query->count();
    $list = $query->order('chapter_order', 'asc')->page($page, $pageSize)->select()->toArray();

    foreach ($list as &$item) {
        if (isset($item['cover_url'])) {
            $item['cover_url'] = $this->fullImageUrl($item['cover_url']);
        }
    }
    unset($item);

    $novelInfo = Db::name('audio_novels')->where('id', $novelId)->find();
    $chapterCount = $novelInfo['chapter_count'] ?? $total;
    $serializationStatus = $novelInfo['serialization_status'] ?? 0;
    $cover = $this->fullImageUrl($novelInfo['cover'] ?? ($novelInfo['cover_url'] ?? ''));

    return json([
        'code' => 0,
        'msg' => 'success',
        'data' => [
            'list' => $list,
            'total' => $total,
            'novel' => [
                'id' => $novelId,
                'chapter_count' => $chapterCount,
                'serialization_status' => $serializationStatus,
                'title' => $novelInfo['title'] ?? '',
                'narrator' => $novelInfo['narrator'] ?? '',
                'cover' => $cover,
                'views' => $novelInfo['views'] ?? 0,
                'likes' => $novelInfo['likes'] ?? 0,
                'collects' => $novelInfo['collects'] ?? 0,
                'is_vip' => $novelInfo['is_vip'] ?? 0,
                'coin' => $novelInfo['coin'] ?? 0,
            ]
        ]
    ]);
}

    // 获取章节详情
    public function detail(Request $request)
{
    $id = $request->get('id');
    $type = $request->get('type', 'h5'); // 默认为 h5

    $fields = 'id,novel_id,title,chapter_order,is_vip,coin,duration';
    if ($type === 'admin') {
        $fields .= ',audio_url';
    }

    $chapter = Db::name('audio_novel_chapter')
        ->where('id', $id)
        ->field($fields)
        ->find();
    if (!$chapter) {
        return json(['code' => 1, 'msg' => '章节未找到']);
    }
    return json(['code' => 0, 'msg' => 'success', 'data' => $chapter]);
}

    // 新增章节
    public function add(Request $request)
    {
        $data = $request->post();
        if (empty($data['novel_id']) || empty($data['title']) || empty($data['audio_url'])) {
            return json(['code' => 1, 'msg' => 'novel_id、标题、音频为必填项']);
        }
        $data['is_vip'] = isset($data['is_vip']) ? intval($data['is_vip']) : 0;
        $data['coin'] = isset($data['coin']) ? intval($data['coin']) : 0;

        $data['publish_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        $id = Db::name('audio_novel_chapter')->insertGetId($data);

        // 新增后同步章节数
        if ($id) {
            $this->syncChapterCount($data['novel_id']);
            return json(['code' => 0, 'msg' => '新增章节成功']);
        }
        return json(['code' => 1, 'msg' => '新增章节失败']);
    }

    // 更新章节
    public function update(Request $request)
    {
        $data = $request->post();
        if (empty($data['id']) || empty($data['title']) || empty($data['audio_url'])) {
            return json(['code' => 1, 'msg' => 'ID、标题、音频为必填项']);
        }
        $data['is_vip'] = isset($data['is_vip']) ? intval($data['is_vip']) : 0;
        $data['coin'] = isset($data['coin']) ? intval($data['coin']) : 0;

        $data['update_time'] = date('Y-m-d H:i:s');
        $res = Db::name('audio_novel_chapter')->where('id', $data['id'])->update($data);
        return $res !== false
            ? json(['code' => 0, 'msg' => '章节更新成功'])
            : json(['code' => 1, 'msg' => '章节更新失败']);
    }

    // 删除章节
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => 'ID为必填项']);
        }
        // 先查novel_id
        $chapter = Db::name('audio_novel_chapter')->where('id', $id)->find();
        $novelId = $chapter['novel_id'] ?? null;

        $res = Db::name('audio_novel_chapter')->where('id', $id)->delete();
        // 删除后同步章节数
        if ($res && $novelId) {
            $this->syncChapterCount($novelId);
            return json(['code' => 0, 'msg' => '章节删除成功']);
        }
        return json(['code' => 1, 'msg' => '章节删除失败']);
    }

    // 批量删除章节
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids');
        if (!$ids || !is_array($ids)) {
            return json(['code' => 1, 'msg' => 'ID列表为必填项']);
        }
        // 查影响到哪些小说
        $novelIds = Db::name('audio_novel_chapter')->whereIn('id', $ids)->distinct(true)->column('novel_id');
        $res = Db::name('audio_novel_chapter')->whereIn('id', $ids)->delete();
        // 批量同步章节数
        if ($res && $novelIds) {
            foreach ($novelIds as $novelId) {
                $this->syncChapterCount($novelId);
            }
            return json(['code' => 0, 'msg' => '批量删除章节成功']);
        }
        return json(['code' => 1, 'msg' => '批量删除章节失败']);
    }

    // 批量更新章节排序
    public function batchUpdateOrder(Request $request)
    {
        $list = $request->post('list');
        if (!$list || !is_array($list)) {
            return json(['code' => 1, 'msg' => '排序列表为必填项']);
        }
        foreach ($list as $item) {
            if (isset($item['id']) && isset($item['chapter_order'])) {
                Db::name('audio_novel_chapter')->where('id', $item['id'])->update([
                    'chapter_order' => $item['chapter_order'],
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            }
        }
        return json(['code' => 0, 'msg' => '章节排序更新成功']);
    }

    // 批量设为免费章节（is_vip=0, coin=0）
    public function setFree(Request $request)
    {
        $ids = $request->post('ids');
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        Db::name('audio_novel_chapter')->whereIn('id', $ids)->update([
            'is_vip' => 0,
            'coin' => 0,
            'update_time' => date('Y-m-d H:i:s')
        ]);

        return json(['code' => 0, 'msg' => '章节已设为免费']);
    }
// 获取章节播放地址（权限校验版）
public function play(Request $request)
{
    $chapterId = $request->post('chapter_id', $request->get('chapter_id'));

    // 获取并解析token
    $token = $request->header('Authorization') ?: $request->header('authorization');
    if (!$token) return json(['code' => 401, 'msg' => '缺少token']);
    if (stripos($token, 'Bearer ') === 0) $token = substr($token, 7);

    try {
        $decoded = (array)\Firebase\JWT\JWT::decode($token, $this->jwtKey, [$this->jwtAlg]);
        $uuid = $decoded['uuid'] ?? '';
        if (!$uuid) return json(['code' => 401, 'msg' => 'token无效']);
    } catch (\Exception $e) {
        return json(['code' => 401, 'msg' => 'Token解析失败: ' . $e->getMessage()]);
    }

    if (!$chapterId) return json(['code' => 1, 'msg' => 'chapter_id为必填项']);

    $chapter = Db::name('audio_novel_chapter')->where('id', $chapterId)->find();
    if (!$chapter) return json(['code' => 1, 'msg' => '章节不存在']);

    // 查询用户信息（uuid）
    $user = Db::name('users')->where('uuid', $uuid)->find();
    if (!$user) return json(['code' => 401, 'msg' => '用户不存在']);

    // ===== 新增：查VIP卡类型全免特权 =====
    $canViewVipAudio = 0;
    $canWatchCoinAudio = 0;
    if (!empty($user['vip_card_id'])) {
        $vipCardType = Db::name('vip_card_type')->where('id', $user['vip_card_id'])->find();
        if ($vipCardType) {
            $canViewVipAudio = intval($vipCardType['can_view_vip_video'] ?? 0);
            $canWatchCoinAudio = intval($vipCardType['can_watch_coin'] ?? 0);
        }
    }

    $isVip = intval($chapter['is_vip']);
    $coin = intval($chapter['coin']);

    // ============ 权限核心判断 =============
    // 1. VIP全免
    if ($isVip === 1 && $canViewVipAudio === 1) {
        // 允许播放，啥都不用查
    }
    // 2. 金币全免
    elseif ($isVip !== 1 && $coin > 0 && $canWatchCoinAudio === 1) {
        // 允许播放，啥都不用查
    }
    // 3. 免费章节
    elseif ($coin === 0) {
        // 允许播放
    }
    // 4. 已解锁
    else {
        $hasUnlock = Db::name('user_video_unlock')
            ->where([
                'user_id' => $uuid,  // 用 uuid
                'video_id' => $chapterId,
                'type' => 4
            ])
            ->where(function ($query) {
                $query->whereNull('expire_time')
                    ->whereOr('expire_time', '>', date('Y-m-d H:i:s'));
            })
            ->find();
        if (!$hasUnlock) {
            if ($isVip === 1) {
                return json(['code' => 1001, 'msg' => 'VIP专享，请先开通VIP']);
            } else {
                return json(['code' => 1002, 'msg' => '请先购买章节']);
            }
        }
    }

    // 到这里说明已允许播放
    return json([
        'code' => 0,
        'msg' => 'success',
        'data' => [
            'audio_url' => $chapter['audio_url']
        ]
    ]);
}
}
