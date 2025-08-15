<?php
// 文件路径: E:\ThinkPHP6\app\controller\api\VideoController.php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use think\facade\Filesystem; // 用于文件上传

class VideoController
{
    /**
     * 获取抖音视频列表
     * @param Request $request GET参数: page, pageSize, keyword, parent_id, category_id, tags
     * @return \think\response\Json
     */
    public function list(Request $request)
    {
        $page = $request->get('page', 1);
        $pageSize = $request->get('pageSize', 10);
        $keyword = $request->get('keyword', '');
        $parentId = $request->get('parent_id', '');
        $categoryId = $request->get('category_id', '');
        $tags = $request->get('tags', '');
        $order = $request->get('order', 'sort asc, id asc');
        $query = Db::name('douyin_videos');

        // 关键词搜索: 标题、编号、标签
        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('title', 'like', '%' . $keyword . '%')
                    ->whereOr('id', '=', $keyword)
                    ->whereOr('tags', 'like', '%' . $keyword . '%'); // 标签模糊匹配
            });
        }
        // 主分类筛选
        if (!empty($parentId)) {
            $query->where('parent_id', (int)$parentId);
        }
        // 子分类筛选
        if (!empty($categoryId)) {
            $query->where('category_id', (int)$categoryId);
        }
        // 标签筛选 (支持多个标签，用 AND 关系)
        if (!empty($tags)) {
            $tagsArray = is_array($tags) ? $tags : explode(',', $tags);
            foreach ($tagsArray as $tag) {
                if (!empty($tag)) {
                    // 如果 douyin_videos.tags 字段存储的是 JSON 数组字符串
                    $query->whereRaw("JSON_CONTAINS(tags, '\"{$tag}\"')");
                    // 如果 douyin_videos.tags 存储的是逗号分隔字符串，则用:
                    // $query->whereRaw("FIND_IN_SET('{$tag}', tags)");
                }
            }
        }

        $total = $query->count();
        $list = $query->page($page, $pageSize)
                        ->order($order) // 默认按上传时间倒序和ID倒序
                        ->select()
                        ->toArray();

        // 数据适配：tags 从 JSON 字符串转数组，is_vip 从 0/1 转 boolean，gold 转 coin，status 0/1 转文本
        foreach ($list as &$item) {
            $item['tags'] = json_decode($item['tags'] ?? '[]', true); // 假设标签存JSON字符串
            $item['vip'] = (bool)$item['is_vip']; // 将 is_vip 1/0 转换为 boolean
            $item['coin'] = $item['gold']; // 将 gold 转换为 coin
            $item['status'] = $item['status'] == 1 ? '已发布' : '未发布'; // 假设 status 1为发布，0为未发布

            // **适配 preview 字段，从 preview_duration 转换**
            $item['preview'] = $item['preview_duration'] ?? ''; // 前端期望 preview 字段

            // **新增：适配 play 和 collect 字段名，因为前端接口定义为 play/collect，但数据库是 play_count/collect_count**
            $item['play'] = $item['play_count'] ?? 0;
            $item['collect'] = $item['collect_count'] ?? 0;

            // 补充主分类和子分类名称，方便前端显示 (需要查询分类表)
            $parentCategory = Db::name('douyin_categories')->where('id', $item['parent_id'])->find();
            $childCategory = Db::name('douyin_categories')->where('id', $item['category_id'])->find();
            $item['parentName'] = $parentCategory['name'] ?? '--';
            $item['categoryName'] = $childCategory['name'] ?? '--';
        }

        return successJson([
            'list' => $list,
            'total' => $total
        ]);
    }

    /**
     * 新增抖音视频
     * @param Request $request POST数据
     * @return \think\response\Json
     */
    public function addVideo(Request $request) // 对应前端 Store 的 addVideo
    {
        $data = $request->post();
        // 获取当前最小的 sort 值
        $minSort = Db::name('douyin_videos')->min('sort');
        $data['sort'] = is_null($minSort) ? 0 : $minSort - 1;

        // 验证必填字段 (前端应该已做基本验证，后端也需再次验证)
        if (empty($data['title']) || empty($data['url']) || empty($data['parent_id']) || empty($data['category_id'])) {
            return errorJson('标题、视频URL地址、主分类、子分类必填');
        }

        // 数据转换：tags 数组转 JSON 字符串
        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tags'] = json_encode($data['tags']);
        } else {
            $data['tags'] = '[]';
        }
        // is_vip 布尔值转 0/1
        $data['is_vip'] = isset($data['vip']) && $data['vip'] ? 1 : 0;
        // gold 从 coin 转换
        $data['gold'] = $data['coin'] ?? 0;

        // 【核心修改】处理 `preview` 字段到 `preview_duration` 字段的映射
        if (isset($data['preview'])) {
            $data['preview_duration'] = $data['preview']; // 将前端传来的 preview 赋值给数据库字段 preview_duration
            unset($data['preview']); // 移除 data 中名为 preview 的字段，避免数据库报错
        }

        // 移除前端可能发送的 'm3u8' 字段，确保只存储 'url'
        unset($data['m3u8']);
        // 移除前端特有字段
        unset($data['vip'], $data['coin']);

        // 自动补充其它字段
        $data['status'] = $data['status'] ?? 1; // 假设默认启用 (1:已发布)
        $data['play_count'] = $data['play_count'] ?? 0; // **确保与数据库 play_count 字段一致**
        $data['collect_count'] = $data['collect_count'] ?? 0; // **确保与数据库 collect_count 字段一致**
        $data['upload_time'] = date('Y-m-d H:i:s');
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');

        // **重要：这里 $data 数组中的键名必须与数据库 douyin_videos 表的字段名完全一致**
        // 前端应该发送 cover_url 和 preview_duration，这里会直接使用它们
        $id = Db::name('douyin_videos')->insertGetId($data);
        return $id ? successJson(['id' => $id], '添加成功') : errorJson('添加失败');
    }

    /**
     * 更新单个抖音视频
     * @param Request $request POST数据包含 id
     * @return \think\response\Json
     */
    public function updateVideo(Request $request) // 对应前端 Store 的 updateVideo
    {
        $data = $request->post();
        if (empty($data['id'])) {
            return errorJson('缺少视频ID');
        }
        // 验证必填字段
        if (empty($data['title']) || empty($data['url']) || empty($data['parent_id']) || empty($data['category_id'])) {
            return errorJson('标题、视频URL地址、主分类、子分类必填');
        }

        // 数据转换：tags 数组转 JSON 字符串
        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tags'] = json_encode($data['tags']);
        } else if (isset($data['tags'])) { // 如果 tags 存在但不是数组 (可能为空字符串)，则设为默认 JSON
            $data['tags'] = '[]';
        }
        // is_vip 布尔值转 0/1
        $data['is_vip'] = isset($data['vip']) && $data['vip'] ? 1 : 0;
        // gold 从 coin 转换
        $data['gold'] = $data['coin'] ?? 0;

        // 【核心修改】处理 `preview` 字段到 `preview_duration` 字段的映射
        if (isset($data['preview'])) {
            $data['preview_duration'] = $data['preview']; // 将前端传来的 preview 赋值给数据库字段 preview_duration
            unset($data['preview']); // 移除 data 中名为 preview 的字段，避免数据库报错
        }

        // 移除前端可能发送的 'm3u8' 字段，确保只更新 'url'
        unset($data['m3u8']);
        // 移除前端特有字段
        unset($data['vip'], $data['coin']);

        // **重要：这里 $data 数组中的键名必须与数据库 douyin_videos 表的字段名完全一致**
        $data['update_time'] = date('Y-m-d H:i:s');

        $ret = Db::name('douyin_videos')->where('id', $data['id'])->update($data);
        if ($ret !== false) {
            $video = Db::name('douyin_videos')->find($data['id']);
            return successJson($video, '更新成功');
        } else {
            return errorJson('更新失败');
        }
    }

    /**
     * 获取单个抖音视频详情
     * @param int $id 视频ID
     * @return \think\response\Json
     */
    public function getVideoById(int $id) // 对应前端 Store 的 fetchVideoDetail
    {
        $data = Db::name('douyin_videos')->find($id);
        if (!$data) {
            return errorJson('未找到该视频');
        }
        // 数据适配：tags 从 JSON 字符串转数组，is_vip 从 0/1 转 boolean，gold 转 coin
        $data['tags'] = json_decode($data['tags'] ?? '[]', true);
        $data['vip'] = (bool)$data['is_vip'];
        $data['coin'] = $data['gold'];

        // **适配 preview 字段，从 preview_duration 转换**
        $data['preview'] = $data['preview_duration'] ?? ''; // 前端期望 preview 字段

        // **新增：适配 play 和 collect 字段名，因为前端接口定义为 play/collect，但数据库是 play_count/collect_count**
        $data['play'] = $data['play_count'] ?? 0;
        $data['collect'] = $data['collect_count'] ?? 0;

        // 这里 $data 数组中已经包含了数据库中所有的字段，包括 'url' 和 'm3u8'
        // 前端会收到这两个字段。如果前端播放器需要兼容，由前端处理选择 'url' 或 'm3u8'

        return successJson($data);
    }

    /**
     * 批量删除抖音视频
     * @param Request $request POST数据包含 ids: []
     * @return \think\response\Json
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return errorJson('缺少视频ID或参数格式错误');
        }

        $count = Db::name('douyin_videos')->whereIn('id', $ids)->delete();
        return $count ? successJson(['count' => $count], "删除成功，共删除{$count}条") : errorJson('删除失败');
    }

    /**
     * 批量设置VIP状态
     * @param Request $request POST数据包含 ids: [], is_vip: 0/1
     * @return \think\response\Json
     */
    public function batchSetVip(Request $request)
    {
        $ids = $request->post('ids', []);
        $isVip = $request->post('is_vip');

        if (empty($ids) || !is_array($ids)) {
            return errorJson('未选择视频或参数格式错误');
        }
        $isVipValue = (bool)$isVip ? 1 : 0; // 确保是 0 或 1

        $count = Db::name('douyin_videos')->whereIn('id', $ids)->update(['is_vip' => $isVipValue, 'update_time' => date('Y-m-d H:i:s')]);
        return $count !== false ? successJson(['count' => $count], "设置VIP成功，共设置{$count}条") : errorJson('设置VIP失败');
    }

    /**
     * 批量设置试看时长
     * @param Request $request POST数据包含 ids: [], preview_duration: '15秒'
     * @return \think\response\Json
     */
    public function batchSetDuration(Request $request)
    {
        $ids = $request->post('ids', []);
        // **关键修改：从前端接收的字段名改为 preview_duration，与前端 Store 和数据库字段名一致**
        // 注意：这里您已经在 `batchSetDuration` 函数中使用了 `preview_duration`。
        // 如果前端在调用此API时传的是 `preview` 而不是 `preview_duration`，则需要做映射。
        // 但根据您提供的代码，此处已是 `preview_duration`。
        $previewDuration = $request->post('preview_duration');

        if (empty($ids) || !is_array($ids)) {
            return errorJson('未选择视频或参数格式错误');
        }
        if (empty($previewDuration)) {
            return errorJson('试看时长不能为空');
        }

        // **关键修改：更新数据库时，字段名也改为 preview_duration，与数据库字段名完全一致**
        $count = Db::name('douyin_videos')->whereIn('id', $ids)->update(['preview_duration' => $previewDuration, 'update_time' => date('Y-m-d H:i:s')]);
        return $count !== false ? successJson(['count' => $count], "设置试看时长成功，共设置{$count}条") : errorJson('设置试看时长失败');
    }

    /**
     * 批量置顶排序：让勾选的视频在各自子分类下升序（sort变最小）
     * @param Request $request POST数据包含 ids: []
     * @return \think\response\Json
     */
    public function batchSortAsc(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return errorJson('未选择视频或参数格式错误');
        }

        // 查询所有选中视频的子分类，分组处理
        $videos = Db::name('douyin_videos')
            ->whereIn('id', $ids)
            ->field('id, category_id')
            ->select()
            ->toArray();

        // 子分类分组：[category_id => [id, id, ...]]
        $grouped = [];
        foreach ($videos as $v) {
            $grouped[$v['category_id']][] = $v['id'];
        }

        foreach ($grouped as $categoryId => $videoIds) {
            // 逆序，保证第一个选的最顶
            $videoIds = array_reverse($videoIds);

            // 查当前子分类内的最小 sort
            $minSort = Db::name('douyin_videos')
                ->where('category_id', $categoryId)
                ->min('sort');
            // 新起点，防止冲突
            $newSort = is_null($minSort) ? 0 : $minSort - 1;

            // 依次赋值，最先选的最顶
            foreach ($videoIds as $id) {
                Db::name('douyin_videos')->where('id', $id)->update([
                    'sort' => $newSort,
                    'update_time' => date('Y-m-d H:i:s')
                ]);
                $newSort--;
            }
        }

        return successJson([], '置顶排序成功');
    }

    /**
     * 批量设置金币
     * @param Request $request POST数据包含 ids: [], gold: int
     * @return \think\response\Json
     */
    public function batchSetGold(Request $request)
    {
        $ids = $request->post('ids', []);
        $gold = (int)$request->post('gold', 0);
        if (empty($ids) || !is_array($ids)) {
            return errorJson('未选择视频或参数格式错误');
        }
        if ($gold < 0) {
            return errorJson('金币数量不能小于0');
        }

        $count = Db::name('douyin_videos')->whereIn('id', $ids)->update(['gold' => $gold, 'update_time' => date('Y-m-d H:i:s')]);
        return $count !== false ? successJson(['count' => $count], "金币设置成功，共设置{$count}条") : errorJson('金币设置失败');
    }

    /**
     * 文件上传接口 (示例，可根据实际需求调整)
     * 注意：前端 `douyin-manage.vue` 的上传组件目前没有调用此接口。
     * 如果你需要文件上传，请确认 `douyin-manage.vue` 中 `el-upload` 的 `action` 属性指向此路由。
     * 路由参考：`Route::post('api/douyin/videos/upload', '\app\controller\api\VideoController@upload');`
     * @param Request $request
     * @return \think\response\Json
     */
    public function upload(Request $request)
    {
        $file = $request->file('file');
        if (!$file) {
            return errorJson('未检测到上传文件');
        }

        try {
            $savename = Filesystem::disk('public')->putFile('douyin_videos_upload', $file); // 上传到 public/douyin_videos_upload
            $url = $request->domain() . '/storage/' . $savename; // ThinkPHP默认的文件访问路径

            return successJson([
                'url' => $url,
                'filename' => $file->getOriginalName(),
            ], '上传成功');
        } catch (\Exception $e) {
            return errorJson('上传失败：' . $e->getMessage());
        }
    }

 /**
 * H5端获取抖音视频列表
 * @param Request $request
 * @return \think\response\Json
 */
public function h5List(Request $request)
{
    $pageSize = $request->get('pageSize', 10);
    $lastId = $request->get('last_id', 0);
    $userId = $request->get('userId', '');
    $tag = $request->get('tag', '');

    // ★补充随机种子定义
    $seed = microtime(true) . '_' . $userId;

    // 获取所有视频ID（加标签筛选）
    $query = Db::name('douyin_videos')->where('status', 1);
    if ($tag) {
        $query->whereRaw("JSON_CONTAINS(tags, '\"{$tag}\"')");
    }
    $allIds = $query->column('id');
    $total = count($allIds);

    // 种子洗牌算法
    function seededShuffle($array, $seed) {
        mt_srand(crc32($seed));
        $shuffled = $array;
        for ($i = count($shuffled) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $shuffled[$i];
            $shuffled[$i] = $shuffled[$j];
            $shuffled[$j] = $tmp;
        }
        mt_srand(); // 恢复全局随机状态
        return $shuffled;
    }
    $shuffledIds = seededShuffle($allIds, $seed);

    // 游标分页
    $start = 0;
    if ($lastId) {
        $idx = array_search($lastId, $shuffledIds);
        $start = ($idx !== false) ? $idx + 1 : 0;
    }
    $ids = array_slice($shuffledIds, $start, $pageSize);

    // 查询详情
    $list = [];
    if ($ids) {
        $list = Db::name('douyin_videos')->whereIn('id', $ids)->select()->toArray();
    }

    // 获取当前用户已解锁视频ID
    $unlockedIds = [];
    $userLikedIds = [];
    $userCollectedIds = [];
    if ($userId) {
        $now = date('Y-m-d H:i:s');
        $unlockedIds = Db::name('user_video_unlock')
            ->where('user_id', $userId)
            ->where('type', 7)
            ->where(function($q) use ($now) {
                $q->whereNull('expire_time')->whereOr('expire_time', '>', $now);
            })
            ->column('video_id');
            
        // 获取用户点赞的视频ID
        $userLikedIds = Db::name('user_actions')
            ->where('user_id', $userId)
            ->where('content_type', 'douyin')
            ->where('action_type', 'like')
            ->column('content_id');
            
        // 获取用户收藏的视频ID
        $userCollectedIds = Db::name('user_actions')
            ->where('user_id', $userId)
            ->where('content_type', 'douyin')
            ->where('action_type', 'collect')
            ->column('content_id');
    }

    foreach ($list as &$item) {
        $item['tags'] = json_decode($item['tags'] ?? '[]', true);
        $item['vip'] = (bool)$item['is_vip'];
        $item['coin'] = $item['gold'];
        $item['like'] = $item['like_count'] ?? 0;
        $item['collect'] = $item['collect_count'] ?? 0;
        $item['cover_url'] = $item['cover'] ?? '';
        $item['title'] = $item['title'] ?? '';
        $item['category_id'] = $item['category_id'] ?? 0;
        $category = Db::name('douyin_categories')->where('id', $item['category_id'])->find();
        $item['category_name'] = $category['name'] ?? '';
        $parentCategory = Db::name('douyin_categories')->where('id', $category['parent_id'] ?? 0)->find();
        $item['parent_id'] = $category['parent_id'] ?? 0;
        $item['parent_icon'] = $parentCategory['icon'] ?? '';
        $item['parent_name'] = $parentCategory['name'] ?? '';
        $item['unlocked'] = in_array($item['id'], $unlockedIds);
        
        // 添加用户的点赞收藏状态
        $item['liked'] = in_array($item['id'], $userLikedIds);
        $item['collected'] = in_array($item['id'], $userCollectedIds);
        $item['like_count'] = $item['like'];
        $item['collect_count'] = $item['collect'];
        
        $item = [
            'id' => $item['id'],
            'title' => $item['title'],
            'cover_url' => $item['cover_url'],
            'vip' => $item['vip'],
            'coin' => $item['coin'],
            'tags' => $item['tags'],
            'category_id' => $item['category_id'],
            'category_name' => $item['category_name'],
            'parent_id' => $item['parent_id'],
            'parent_name' => $item['parent_name'],
            'parent_icon' => $item['parent_icon'],
            'like' => $item['like'],
            'collect' => $item['collect'],
            'unlocked' => $item['unlocked'],
            // 添加用户状态字段
            'liked' => $item['liked'],
            'collected' => $item['collected'],
            'like_count' => $item['like_count'],
            'collect_count' => $item['collect_count'],
        ];
    }

    $lastIdReturn = count($ids) ? $ids[count($ids) - 1] : 0;

    return successJson([
        'list' => $list,
        'last_id' => $lastIdReturn
    ]);
}
    /**
     * 批量设置点赞
     * @param Request $request POST数据包含 ids: [], likes: int
     * @return \think\response\Json
     */
    public function batchSetLikes(Request $request)
    {
        $ids = $request->post('ids', []);
        $likes = (int)$request->post('likes', 0);

        if (empty($ids) || !is_array($ids)) {
            return errorJson('未选择视频或参数格式错误');
        }
        if ($likes < 0) {
            return errorJson('点赞数量不能小于0');
        }

        $count = Db::name('douyin_videos')->whereIn('id', $ids)->update(['like_count' => $likes, 'update_time' => date('Y-m-d H:i:s')]);
        return $count !== false ? successJson(['count' => $count], "点赞设置成功，共设置{$count}条") : errorJson('点赞设置失败');
    }

    /**
     * 批量设置收藏
     * @param Request $request POST数据包含 ids: [], collect: int
     * @return \think\response\Json
     */
    public function batchSetCollect(Request $request)
    {
        $ids = $request->post('ids', []);
        $collect = (int)$request->post('collect', 0);

        if (empty($ids) || !is_array($ids)) {
            return errorJson('未选择视频或参数格式错误');
        }
        if ($collect < 0) {
            return errorJson('收藏数量不能小于0');
        }

        $count = Db::name('douyin_videos')->whereIn('id', $ids)->update(['collect_count' => $collect, 'update_time' => date('Y-m-d H:i:s')]);
        return $count !== false ? successJson(['count' => $count], "收藏设置成功，共设置{$count}条") : errorJson('收藏设置失败');
    }

    /**
     * 批量设置播放数
     * @param Request $request POST数据包含 ids: [], play: int
     * @return \think\response\Json
     */
    public function batchSetPlay(Request $request)
    {
        $ids = $request->post('ids', []);
        $play = (int)$request->post('play', 0);

        if (empty($ids) || !is_array($ids)) {
            return errorJson('未选择视频或参数格式错误');
        }
        if ($play < 0) {
            return errorJson('播放数量不能小于0');
        }

        $count = Db::name('douyin_videos')->whereIn('id', $ids)->update(['play_count' => $play, 'update_time' => date('Y-m-d H:i:s')]);
        return $count !== false ? successJson(['count' => $count], "播放数设置成功，共设置{$count}条") : errorJson('播放数设置失败');
    }

    public function play(Request $request)
    {
        \think\facade\Log::info('Douyin Play Param='.json_encode($request->param()));

        // 基础参数
        $videoId = intval($request->param('id', 0));
        $userId = $request->param('userId');
        if (!$videoId || !$userId) {
            return errorJson('参数缺失');
        }

        // 查视频
        $video = Db::name('douyin_videos')->where('id', $videoId)->find();
        if (!$video) return errorJson('视频不存在');

        // 视频权限字段
        $isVipVideo = isset($video['is_vip']) && intval($video['is_vip']) === 1;
        $isCoinVideo = isset($video['gold']) && intval($video['gold']) > 0;
        $videoUrl = $video['url'];

        // 查用户
        $user = Db::name('users')->where('uuid', $userId)->find();
        $canViewVip = 0;
        $canWatchCoin = 0;
        if ($user && $user['vip_card_id']) {
            $vipCard = Db::name('vip_card_type')->where('id', $user['vip_card_id'])->find();
            if ($vipCard) {
                $canViewVip = intval($vipCard['can_view_vip_video'] ?? 0);
                $canWatchCoin = intval($vipCard['can_watch_coin'] ?? 0);
            }
        }

        $isVipCard = $canViewVip === 1 && $canWatchCoin !== 1;
        $isCoinCard = $canWatchCoin === 1 && $canViewVip !== 1;
        $isSuperCard = $canViewVip === 1 && $canWatchCoin === 1;
        $isNormalUser = $canViewVip !== 1 && $canWatchCoin !== 1;

        // 是否已解锁且未过期
        $unlock = Db::name('user_video_unlock')
            ->where('user_id', $userId)
            ->where('video_id', $videoId)
            ->where('type', 7)
            ->find();
        $unlocked = false;
        if ($unlock && (!isset($unlock['expire_time']) || strtotime($unlock['expire_time']) > time())) {
            $unlocked = true;
        }

        // 查剩余次数（普通用户/特殊场景用）
        $today = date('Y-m-d');
        $configs = Db::name('site_config')->column('config_value', 'config_key');
        $maxTimes = intval($configs['free_dy_video_daily'] ?? 10);
        $record = Db::name('user_daily_watch_count')
            ->where('uuid', $userId)
            ->where('date', $today)
            ->find();
        $used = $record ? intval($record['dy_video_used']) : 0;
        $remaining = max(0, $maxTimes - $used);

        // 免费视频所有人可看
        $isFreeVideo = !$isVipVideo && !$isCoinVideo;
        if ($isFreeVideo) {
            return successJson([
                'id' => $videoId,
                'canPlay' => 1,
                'playUrl' => $videoUrl,
                'isVip' => 0,
                'isCoin' => 0,
                'msg' => '',
                'unlocked' => true,
                'remaining' => $remaining,
                'playCount' => intval($video['play']),
                'collectCount' => intval($video['collect']),
            ], '播放地址获取成功');
        }

        // 至尊卡（VIP+金币权限都有） => 任何VIP/金币视频都能直接看
        if ($isSuperCard) {
            // 至尊卡，所有视频都能看
            return successJson([
                'id' => $videoId,
                'canPlay' => 1,
                'playUrl' => $videoUrl,
                'isVip' => $isVipVideo,
                'isCoin' => $isCoinVideo,
                'msg' => '',
                'unlocked' => true,
                'remaining' => $remaining,
                'playCount' => intval($video['play']),
                'collectCount' => intval($video['collect']),
            ], '播放地址获取成功');
        }
        if ($isVipCard && $isVipVideo) {
            // 普通VIP卡，只能看VIP视频
            return successJson([
                'id' => $videoId,
                'canPlay' => 1,
                'playUrl' => $videoUrl,
                'isVip' => 1,
                'isCoin' => 0,
                'msg' => '',
                'unlocked' => true,
                'remaining' => $remaining,
                'playCount' => intval($video['play']),
                'collectCount' => intval($video['collect']),
            ], '播放地址获取成功');
        }
        if ($isCoinCard && $isCoinVideo) {
            // 金币卡，只能看金币视频
            return successJson([
                'id' => $videoId,
                'canPlay' => 1,
                'playUrl' => $videoUrl,
                'isVip' => 0,
                'isCoin' => 1,
                'msg' => '',
                'unlocked' => true,
                'remaining' => $remaining,
                'playCount' => intval($video['play']),
                'collectCount' => intval($video['collect']),
            ], '播放地址获取成功');
        }

        // 用户已购买/解锁该视频（未过期）
        if ($unlocked) {
            return successJson([
                'id' => $videoId,
                'canPlay' => 1,
                'playUrl' => $videoUrl,
                'isVip' => $isVipVideo,
                'isCoin' => $isCoinVideo,
                'msg' => '',
                'unlocked' => true,
                'remaining' => $remaining,
                'playCount' => intval($video['play']),
                'collectCount' => intval($video['collect']),
            ], '播放地址获取成功');
        }

        // 其它情况，需走免费次数（普通用户/部分卡用户遇到权限外视频）
        if ($remaining <= 0) {
            return errorJson('今日可免费观看次数已用完', 403);
        }
        // 扣减次数
        if ($record) {
            Db::name('user_daily_watch_count')
                ->where('id', $record['id'])
                ->inc('dy_video_used')
                ->update();
            $used++;
        } else {
            Db::name('user_daily_watch_count')->insert([
                'uuid' => $userId,
                'date' => $today,
                'dy_video_used' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $used = 1;
        }
        $remaining = max(0, $maxTimes - $used);

        return successJson([
            'id' => $videoId,
            'canPlay' => 1,
            'playUrl' => $videoUrl,
            'isVip' => $isVipVideo,
            'isCoin' => $isCoinVideo,
            'msg' => '',
            'unlocked' => false,
            'remaining' => $remaining,
            'playCount' => intval($video['play']),
            'collectCount' => intval($video['collect']),
        ], '播放地址获取成功');
    }

    /**
     * H5发现页专用接口：分页拉取视频列表
     * 路由建议：GET /api/h5/douyin/discover
     */
    public function h5DiscoverList(Request $request)
    {
        $page = $request->get('page', 1);
        $pageSize = $request->get('pageSize', 20);
        $tag = $request->get('tag', '');
        $category = $request->get('category', '最新');

        $query = Db::name('douyin_videos')->where('status', 1);

        // 只有标签分类才用 tag 筛选
        if ($category !== '最新' && $category !== '最热' && $tag) {
            $query->whereRaw("JSON_CONTAINS(tags, '\"{$tag}\"')");
        }

        // 最新：按上传时间倒序
        if ($category === '最新') {
            $query->order('upload_time desc, id desc');
        }
        // 最热：按播放量倒序
        else if ($category === '最热') {
            $query->order('play_count desc, id desc');
        }
        // 其它标签分类：按上传时间倒序
        else {
            $query->order('upload_time desc, id desc');
        }

        $total = $query->count();
        $list = $query->page($page, $pageSize)
            ->select()
            ->toArray();

        $result = [];
        foreach ($list as $item) {
            $result[] = [
                'id' => $item['id'],
                'title' => $item['title'],
                'cover_url' => $item['cover'] ?? '',
                'tags' => json_decode($item['tags'] ?? '[]', true),
                'play' => $item['play_count'] ?? 0,
                'vip' => (bool)$item['is_vip'],
                'coin' => $item['gold'] ?? 0,
            ];
        }

        return successJson([
            'list' => $result,
            'total' => $total
        ]);
    }

    /**
     * 获取单个抖音视频详情
     * 路由建议：GET /api/h5/douyin/video/detail?id=xxx&userId=xxx
     */
    public function h5VideoDetail(Request $request)
    {
        $id = $request->get('id');
        $userId = $request->get('userId', '');
        
        if (!$id) return errorJson('缺少视频ID');
        
        $data = Db::name('douyin_videos')->find($id);
        if (!$data || $data['status'] != 1) return errorJson('未找到该视频');
        
        // 获取用户的点赞收藏状态
        $liked = false;
        $collected = false;
        $unlocked = false;
        
        if ($userId) {
            // 检查点赞状态
            $likeRecord = Db::name('user_actions')
                ->where('user_id', $userId)
                ->where('content_id', $id)
                ->where('content_type', 'douyin')
                ->where('action_type', 'like')
                ->find();
            $liked = !empty($likeRecord);
            
            // 检查收藏状态
            $collectRecord = Db::name('user_actions')
                ->where('user_id', $userId)
                ->where('content_id', $id)
                ->where('content_type', 'douyin')
                ->where('action_type', 'collect')
                ->find();
            $collected = !empty($collectRecord);
            
            // 检查解锁状态
            $unlockRecord = Db::name('user_video_unlock')
                ->where('user_id', $userId)
                ->where('video_id', $id)
                ->where('type', 7)
                ->find();
            if ($unlockRecord && (!isset($unlockRecord['expire_time']) || strtotime($unlockRecord['expire_time']) > time())) {
                $unlocked = true;
            }
        }
        
        // 获取分类信息
        $category = Db::name('douyin_categories')->where('id', $data['category_id'])->find();
        $parentCategory = Db::name('douyin_categories')->where('id', $category['parent_id'] ?? 0)->find();
        
        // 数据适配，返回指定的字段格式
        $result = [
            'id' => $data['id'],
            'title' => $data['title'],
            'cover_url' => $data['cover'] ?? '',
            'vip' => (bool)$data['is_vip'],
            'coin' => $data['gold'] ?? 0,
            'tags' => json_decode($data['tags'] ?? '[]', true),
            'category_id' => $data['category_id'] ?? 0,
            'category_name' => $category['name'] ?? '', // 博主名字
            'parent_id' => $category['parent_id'] ?? 0,
            'parent_name' => $parentCategory['name'] ?? '',
            'parent_icon' => $parentCategory['icon'] ?? '', // 使用父分类的图标作为博主头像
            'like' => $data['like_count'] ?? 0,
            'collect' => $data['collect_count'] ?? 0,
            'unlocked' => $unlocked,
            'liked' => $liked,
            'collected' => $collected,
            'like_count' => $data['like_count'] ?? 0,
            'collect_count' => $data['collect_count'] ?? 0
        ];
        
        return successJson($result);
    }

    /**
     * H5搜索视频接口
     * @param Request $request GET参数: keyword, page, limit
     * @return \think\response\Json
     */
    public function searchVideos(Request $request)
    {
        $keyword = $request->get('keyword', '');
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 20);
        
        if (empty($keyword)) {
            return errorJson('搜索关键词不能为空');
        }

        $query = Db::name('douyin_videos')
            ->alias('dv')
            ->leftJoin('douyin_categories dc', 'dv.category_id = dc.id')
            ->where('dv.status', 1); // 只查询已发布的视频

        // 关键词搜索: 标题、标签模糊匹配
        $query->where(function ($q) use ($keyword) {
            $q->where('dv.title', 'like', '%' . $keyword . '%')
              ->whereOr('dv.tags', 'like', '%' . $keyword . '%');
        });

        // 获取总数
        $total = $query->count();

        // 分页查询
        $list = $query->field([
                'dv.id',
                'dv.title',
                'dv.cover as cover_url', // 封面图
                'dv.is_vip as vip',      // VIP标识
                'dv.gold as coin',       // 金币
                'dv.duration',           // 时长
                'dv.play_count as views', // 播放量
                'dc.name as author'      // 子分类名作为博主名
            ])
            ->page($page, $limit)
            ->order('dv.upload_time desc, dv.id desc')
            ->select()
            ->toArray();

        // 数据格式化
        foreach ($list as &$item) {
            $item['vip'] = (bool)$item['vip']; // 转为boolean
            $item['views'] = (int)$item['views']; // 确保为数字
            $item['coin'] = (int)$item['coin'];
            $item['author'] = $item['author'] ?: '未知分类'; // 默认值
        }

        return successJson([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit
        ]);
    }
}

// 辅助函数定义 (如果你的项目中没有全局定义的话，请确保这些函数在你的项目中是可用的)
if (!function_exists('successJson')) {
    function successJson($data = [], $message = '操作成功', $code = 0)
    {
        return json(['code' => $code, 'msg' => $message, 'data' => $data]);
    }
}

if (!function_exists('errorJson')) {
    function errorJson($message = '操作失败', $code = 1, $data = [])
    {
        return json(['code' => $code, 'msg' => $message, 'data' => $data]);
    }
}

