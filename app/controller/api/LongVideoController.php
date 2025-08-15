<?php
// 文件路径: app/controller/api/LongVideoController.php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use think\facade\Filesystem;
use Tuupola\Base62;


// 辅助函数定义 (如果你的项目中没有全局定义的话，请确保这些函数在你的项目中是可用的)
// 通常这些会在 app/BaseController 或公共函数文件里
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

class LongVideoController
{
    /**
     * 获取长视频列表
     * GET /api/long/videos/list
     * 支持关键词、分类等筛选
     */
    public function list(Request $request)
    {
        $params = $request->get();

        // 构建查询基础
        $query = Db::name('long_videos')
            ->alias('lv') // 为 long_videos 表设置别名
            // 确保 long_video_categories 表存在且结构正确
            ->leftJoin('long_video_categories lvc', 'lv.category_id = lvc.id') // 关联子分类表
            ->leftJoin('long_video_categories lvc_parent', 'lvc.parent_id = lvc_parent.id'); // 关联父分类表，用于获取父分类名称和ID

        // 关键词搜索: 标题、编号、标签
        if (!empty($params['keyword'])) {
            $keyword = '%' . $params['keyword'] . '%';
            // 搜索标题、ID、标签 (long_videos.tags 是 JSON 类型，使用 JSON_CONTAINS)
            $query->where(function ($q) use ($keyword, $params) {
                $q->where('lv.title', 'like', $keyword)
                    ->whereOr('lv.id', '=', is_numeric($params['keyword']) ? (int)$params['keyword'] : 0) // 尝试按ID搜索
                    ->whereOrRaw("JSON_CONTAINS(lv.tags, ?) = 1", [$params['keyword']]); // 搜索 JSON 标签
            });
        }
        
        // 主分类筛选 (通过关联的分类表进行筛选)
        if (isset($params['parent_id']) && $params['parent_id'] !== '') {
            $query->where('lvc.parent_id', '=', intval($params['parent_id']));
        }
        // 子分类筛选
        if (isset($params['category_id']) && $params['category_id'] !== '') {
            $query->where('lv.category_id', '=', intval($params['category_id']));
        }
        // 标签筛选 (前端传来的tags是数组，如果传入则需要判断视频的tags字段是否包含所有这些标签)
        if (!empty($params['tags']) && is_array($params['tags'])) {
            foreach ($params['tags'] as $tag) {
                $query->whereRaw("JSON_CONTAINS(lv.tags, ?) = 1", [json_encode($tag)]);
            }
        }
        // 状态筛选
        if (isset($params['status']) && $params['status'] !== '') {
            $dbStatus = null;
            // 注意：前端可能传 '已发布' 等字符串，也可能是 0/1
            if ($params['status'] === '已发布') {
                $dbStatus = 1;
            } elseif ($params['status'] === '草稿' || $params['status'] === 0) { // 假设 0 为草稿/禁用/审核中
                $dbStatus = 0;
            }
            if ($dbStatus !== null) {
                $query->where('lv.status', '=', $dbStatus);
            }
        }

        // 计算总数（在分页之前执行count）
        $total = $query->count();

        // 分页参数
        $page = max(1, intval($params['page'] ?? 1));
        $pageSize = max(1, intval($params['pageSize'] ?? 10));
        
        // 查询视频列表
        $list = $query->field([
                'lv.id', 'lv.title', 'lv.video_url', 'lv.cover_url', 'lv.duration',
                'lv.preview_duration', 'lv.is_vip', 'lv.gold_required', 'lv.category_id',
                'lv.tags', 'lv.status', 'lv.play_count', 'lv.collect_count', 'lv.like_count', // 添加 like_count 字段
                'lv.publish_time', 'lv.create_time', 'lv.update_time', 'lv.sort',
                'lvc.name' => 'categoryName', // 子分类名称
                'lvc.parent_id', // 子分类的父ID
                'lvc_parent.name' => 'parentName', // 父分类名称
            ])
            ->order('sort asc, id desc') // 默认按ID降序，可根据需要调整，或根据 sort 字段排序
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 数据格式化，匹配前端期望
        foreach ($list as &$v) {
            // 标签：从 JSON 字符串解码为 PHP 数组
            $v['tags'] = json_decode($v['tags'], true) ?: [];
            
            // VIP状态：数据库 TINYINT (0/1) -> 前端 boolean (true/false)
            $v['vip'] = (bool)$v['is_vip']; 
            
            // 金币：数据库 gold_required -> 前端 coin
            $v['coin'] = (int)$v['gold_required'];
            $v['goldCoins'] = $v['coin'];
            
            // 播放量和收藏量：确保为数字类型
            $v['play'] = (int)$v['play_count'];
            $v['collect'] = (int)$v['collect_count'];
            $v['like'] = (int)$v['like_count']; // 映射 like_count 到 like
            
            // 状态文本：数据库 TINYINT (1/0) -> 前端文字
            $v['status_text'] = $v['status'] === 1 ? '已发布' : '未发布'; // 根据你的需求，status 0/1 -> 文本

            // 试看时长：数据库 preview_duration -> 前端 preview
            $v['preview'] = $v['preview_duration']; // 字段名已经一致

            // 上传时间：数据库 publish_time -> 前端 upload_time
            $v['upload_time'] = $v['publish_time'];
            $v['url'] = $v['video_url']; // ★★★ 关键：加这一行 ★★★

            // 移除前端不需要的数据库原始字段，保留前端需要的转换后字段
            unset($v['is_vip']);
            unset($v['gold_required']);
            unset($v['play_count']);
            unset($v['collect_count']);
            unset($v['like_count']);
            unset($v['publish_time']);
           
        }

        return json([
            'code' => 0,
            'msg' => '获取列表成功', // 添加消息
            'data' => [
                'list' => $list,
                'total' => $total
            ]
        ]);
    }

    /**
     * 新增长视频
     * POST /api/long/videos/add
     */
    public function addVideo(Request $request)
    {
        // 1. 获取前端传来的数据
        $data = $request->post();

        // 2. 数据校验 (根据 long_videos 表字段，确保必填字段存在)
        if (empty($data['title']) || empty($data['url']) || empty($data['cover_url']) || empty($data['parent_id']) || empty($data['category_id'])) {
            return errorJson('标题、视频URL、封面URL、主分类、子分类必填');
        }

        // 获取当前最小的 sort 值，用于新视频置顶
        $minSort = Db::name('long_videos')->min('sort');
        $data['sort'] = is_null($minSort) ? 0 : $minSort - 1; // 确保新视频在最前面

        // 3. 数据处理与转换，与数据库字段对应
        // 标签：前端传来的是数组，后端需要 JSON 字符串存储
        $data['tags'] = isset($data['tags']) && is_array($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE) : '[]';

        // VIP状态：前端是 boolean (vip)，后端需要 TINYINT (0/1)
        $data['is_vip'] = isset($data['vip']) && $data['vip'] ? 1 : 0; 

        // 金币：前端发送的是 'coin' (或原生的 'gold')，后端数据库需要 'gold_required' 字段
        // 优先处理前端可能发送的 'gold' 字段，将其转换为 'coin' 以匹配原有逻辑
        if (isset($data['gold'])) {
            $data['coin'] = $data['gold'];
            unset($data['gold']); // 移除原始的 'gold' 字段，避免数据库报错
        }
        $data['gold_required'] = isset($data['coin']) ? intval($data['coin']) : 0; 

        // 视频URL：前端是 url，后端需要 video_url
        $data['video_url'] = isset($data['url']) ? (string)$data['url'] : ''; 
        // 封面图URL：前端是 cover_url，后端需要 cover_url （字段名一致）
        $data['cover_url'] = isset($data['cover_url']) ? (string)$data['cover_url'] : ''; 

        // 试看时长：前端是 preview_duration，后端需要 preview_duration （字段名一致）
        $data['preview_duration'] = isset($data['preview_duration']) ? (string)$data['preview_duration'] : '';

        // 播放量和收藏量（新增时通常为0，前端可能传入）
        $data['play_count'] = isset($data['play_count']) ? intval($data['play_count']) : 0;
        $data['collect_count'] = isset($data['collect_count']) ? intval($data['collect_count']) : 0;

        // 状态 (默认为已发布，前端可能传入 0 或 1)
        $data['status'] = isset($data['status']) ? intval($data['status']) : 1; // 1为已发布，0为未发布等

        // 时间戳
        $data['publish_time'] = isset($data['publish_time']) ? (string)$data['publish_time'] : date('Y-m-d H:i:s'); // 发布时间
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        
        // 移除前端多余字段或已转换的字段，避免插入数据库时出现 'fields not exists' 错误
        unset($data['id']); // 新增时不需要ID
        unset($data['vip']); // 已转为 is_vip
        unset($data['coin']); // 已转为 gold_required，所以此处的 coin 已经用过可以移除
        unset($data['url']); // 已转为 video_url
        // unset($data['cover_url']); // 不再 unset，因为前端直接使用此字段名
        // unset($data['preview_duration']); // 不再 unset
        unset($data['parentName']); 
        unset($data['categoryName']);
        unset($data['play']); // 前端 play 字段
        unset($data['collect']); // 前端 collect 字段
        unset($data['upload_time']); // 前端 upload_time 字段
        
        // 4. 插入数据库
        try {
            $id = Db::name('long_videos')->insertGetId($data);
            return $id ? successJson(['id' => $id], '新增视频成功') : errorJson('新增视频失败');
        } catch (\Exception $e) {
            // 记录详细错误信息，方便调试
            // 例如：Log::error('新增视频失败: ' . $e->getMessage() . ' 数据: ' . json_encode($data));
            return errorJson('新增视频失败: ' . $e->getMessage());
        }
    }

    /**
     * 编辑长视频
     * POST /api/long/videos/update
     */
    public function updateVideo(Request $request)
    {
        $data = $request->post();
        $id = intval($data['id'] ?? 0);

        if (!$id) {
            return errorJson('视频ID不能为空');
        }
        // 校验必填字段 (根据 long_videos 表字段，确保必填字段存在)
        if (empty($data['title']) || empty($data['url']) || empty($data['cover_url']) || empty($data['parent_id']) || empty($data['category_id'])) {
            return errorJson('标题、视频URL、封面URL、主分类、子分类必填');
        }

        // 数据处理与转换，只将数据库需要的字段赋值给 $updateData
        $updateData = [];

        if (isset($data['title'])) $updateData['title'] = (string)$data['title'];
        if (isset($data['url'])) $updateData['video_url'] = (string)$data['url']; // 前端 url -> 后端 video_url
        if (isset($data['cover_url'])) $updateData['cover_url'] = (string)$data['cover_url']; // 前端 cover_url -> 后端 cover_url
        if (isset($data['category_id'])) $updateData['category_id'] = intval($data['category_id']); // 视频直接关联子分类
        
        // 保留 parent_id，并确保是整数类型
        if (isset($data['parent_id'])) $updateData['parent_id'] = intval($data['parent_id']);
        
        if (isset($data['tags'])) $updateData['tags'] = is_array($data['tags']) ? json_encode($data['tags'], JSON_UNESCAPED_UNICODE) : '[]';
        
        // 从前端发送的 vip 字段获取值，转换为 is_vip
        if (isset($data['vip'])) $updateData['is_vip'] = $data['vip'] ? 1 : 0; 
        
        // 从前端发送的 coin (或 gold) 字段获取值，转换为 gold_required
        // 优先处理前端可能发送的 'gold' 字段，将其转换为 'coin'
        if (isset($data['gold'])) {
            $data['coin'] = $data['gold'];
            // 注意：这里不需要 unset($data['gold'])，因为它不会直接插入数据库，
            // 而是通过 $updateData 赋值。但为了代码一致性，也可以unset。
        }
        if (isset($data['coin'])) $updateData['gold_required'] = intval($data['coin']); 
        
        if (isset($data['preview_duration'])) $updateData['preview_duration'] = (string)$data['preview_duration']; // 字段名一致
        if (isset($data['duration'])) $updateData['duration'] = (string)$data['duration']; // 字段名一致

        // 状态、播放量、收藏量等，按需处理
        if (isset($data['status'])) { // 如果前端可以修改状态
            $updateData['status'] = intval($data['status']); // 确保是整数 0 或 1
        }
        if (isset($data['play_count'])) $updateData['play_count'] = intval($data['play_count']);
        if (isset($data['collect_count'])) $updateData['collect_count'] = intval($data['collect_count']);
        if (isset($data['publish_time'])) $updateData['publish_time'] = (string)$data['publish_time']; 

        $updateData['update_time'] = date('Y-m-d H:i:s'); // 更新更新时间

        try {
            $ret = Db::name('long_videos')->where('id', $id)->update($updateData);
            // ThinkPHP update 返回影响行数，或者 false
            return $ret !== false ? successJson([], '编辑视频成功') : errorJson('编辑视频失败');
        } catch (\Exception $e) {
            return errorJson('编辑视频失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除长视频 (单个)
     * POST /api/long/videos/delete
     */
    public function delete(Request $request)
    {
        $id = intval($request->post('id', 0));
        if (!$id) return errorJson('ID不能为空');
        try {
            $count = Db::name('long_videos')->where('id', $id)->delete();
            return $count ? successJson([], '删除成功') : errorJson('删除失败或视频不存在');
        } catch (\Exception $e) {
            return errorJson('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量删除长视频
     * POST /api/long/videos/batch-delete
     */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (!is_array($ids) || empty($ids)) return errorJson('参数错误');
        
        try {
            $count = Db::name('long_videos')->whereIn('id', $ids)->delete();
            return successJson(['count' => $count], "批量删除成功，共删除{$count}条");
        } catch (\Exception $e) {
            return errorJson('批量删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量设置VIP状态
     * POST /api/long/videos/batch-set-vip
     */
    public function batchSetVip(Request $request)
    {
        $ids = $request->post('ids', []);
        $isVip = intval($request->post('is_vip', 0)); // 0 或 1
        if (!is_array($ids) || empty($ids)) return errorJson('参数错误');

        try {
            $count = Db::name('long_videos')->whereIn('id', $ids)->update(['is_vip' => $isVip, 'update_time' => date('Y-m-d H:i:s')]);
            return successJson(['count' => $count], "VIP设置成功，共设置{$count}条");
        } catch (\Exception $e) {
            return errorJson('VIP设置失败: ' . $e->getMessage());
        }
    }
/**
 * 批量设置点赞数
 * POST /api/long/videos/batch-set-like
 */
public function batchSetLike(Request $request)
{
    $ids = $request->post('ids', []);
    $likeCount = intval($request->post('like_count', 0));

    if (!is_array($ids) || empty($ids) || $likeCount < 0) {
        return errorJson('参数错误');
    }

    try {
        $count = Db::name('long_videos')->whereIn('id', $ids)->update([
            'like_count' => $likeCount,
            'update_time' => date('Y-m-d H:i:s')
        ]);
        return successJson(['count' => $count], "点赞数设置成功，共设置{$count}条");
    } catch (\Exception $e) {
        return errorJson('点赞数设置失败: ' . $e->getMessage());
    }
}
    /**
     * 批量设置试看时长
     * POST /api/long/videos/batch-set-duration
     */
    public function batchSetDuration(Request $request)
    {
        $ids = $request->post('ids', []);
        $duration = (string)$request->post('duration', ''); // 前端发送字段名为 duration
        if (!is_array($ids) || empty($ids) || empty($duration)) return errorJson('参数错误');

        try {
            $count = Db::name('long_videos')->whereIn('id', $ids)->update(['preview_duration' => $duration, 'update_time' => date('Y-m-d H:i:s')]);
            return successJson(['count' => $count], "试看时长设置成功，共设置{$count}条");
        } catch (\Exception $e) {
            return errorJson('试看时长设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量设置金币
     * POST /api/long/videos/batch-set-gold
     */
    public function batchSetGold(Request $request)
    {
        $ids = $request->post('ids', []);
        $gold = intval($request->post('gold', 0)); // 前端发送字段名为 gold
        if (!is_array($ids) || empty($ids) || $gold < 0) return errorJson('参数错误');

        try {
            $count = Db::name('long_videos')->whereIn('id', $ids)->update(['gold_required' => $gold, 'update_time' => date('Y-m-d H:i:s')]);
            return successJson(['count' => $count], "金币设置成功，共设置{$count}条");
        } catch (\Exception $e) {
            return errorJson('金币设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量置顶排序：让勾选的视频在各自子分类下升序（sort变最小）
     * POST /api/long/videos/batch-sort-asc
     */
    public function batchSortAsc(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return errorJson('未选择视频或参数格式错误');
        }

        // 查询所有选中视频的子分类，分组处理
        $videos = Db::name('long_videos')
            ->whereIn('id', $ids)
            ->field('id, category_id')
            ->select()
            ->toArray();

        // 子分类分组：[category_id => [id, id, ...]]
        $grouped = [];
        foreach ($videos as $v) {
            $grouped[$v['category_id']][] = $v['id'];
        }

        Db::startTrans();
        try {
            foreach ($grouped as $categoryId => $videoIds) {
                // 逆序，保证第一个选的最顶
                $videoIds = array_reverse($videoIds);

                // 查当前子分类内的最小 sort
                $minSort = Db::name('long_videos')
                    ->where('category_id', $categoryId)
                    ->min('sort');
                // 新起点，防止冲突
                $newSort = is_null($minSort) ? 0 : $minSort - 1;

                // 依次赋值，最先选的最顶
                foreach ($videoIds as $id) {
                    Db::name('long_videos')->where('id', $id)->update([
                        'sort' => $newSort,
                        'update_time' => date('Y-m-d H:i:s')
                    ]);
                    $newSort--;
                }
            }
            Db::commit();
            return successJson([], '置顶排序成功');
        } catch (\Exception $e) {
            Db::rollback();
            return errorJson('置顶排序失败: ' . $e->getMessage());
        }
    }

    /**
     * 文件上传接口 (示例，用于封面或视频文件上传)
     * POST /api/long/videos/upload
     * @param Request $request file: 文件字段名，例如 'file' 或 'video'
     * @return \think\response\Json
     */
    public function upload(Request $request)
    {
        // 根据你实际的上传组件 name 属性调整这里的文件字段名
        $file = $request->file('file'); // 假设前端上传文件的字段名是 'file'
        if (!$file) {
            return errorJson('未检测到上传文件');
        }

        try {
            // 上传到 public/long_videos_upload 目录下
            // 确保你的 ThinkPHP 配置中 public 磁盘已配置，且 storage 软链已建立 (php think storage:link)
            $savename = Filesystem::disk('public')->putFile('long_videos_upload', $file); 
            $url = $request->domain() . '/storage/' . $savename; // ThinkPHP默认的文件访问路径
            
            return successJson([
                'url' => $url,
                'filename' => $file->getOriginalName(),
                'file_size' => $file->getSize(),
            ], '上传成功');
        } catch (\Exception $e) {
            return errorJson('上传失败：' . $e->getMessage());
        }
    }

    /**
     * 获取单个长视频详情 (路由期望 `detail` 方法，而不是 `info`)
     * GET /api/long/videos/:id
     * @param int $id 视频ID (从路由参数中获取)
     * @return \think\response\Json
     */
    public function detail($id)
    {
        $id = intval($id);
        if (!$id) return errorJson('视频ID参数无效或视频不存在');

        // 查询主表数据
        $info = Db::name('long_videos')->where('id', $id)->find();
        if (!$info) return errorJson('视频不存在');

        // 查询埋点表的点赞数、收藏数和播放数
        $likeCount = Db::name('video_track')
            ->where('video_id', $id)
            ->where('action', 'like')
            ->count();

        $collectCount = Db::name('video_track')
            ->where('video_id', $id)
            ->where('action', 'collect')
            ->count();

        $playCount = Db::name('video_track')
            ->where('video_id', $id)
            ->where('action', 'view')
            ->count();

        // 合并主表和埋点表数据
        $result = [
            'id' => $info['id'],
            'title' => $info['title'],
            'cover_url' => $info['cover_url'],
            'duration' => $info['duration'],
            'preview_duration' => $info['preview_duration'],
            'vip' => (bool)$info['is_vip'],
            'coin' => (int)($info['gold_required'] ?? 0),
            'goldCoins' => (int)($info['gold_required'] ?? 0),
            'upload_time' => $info['publish_time'],
            'tags' => json_decode($info['tags'], true) ?: [],
            'play' => (int)$info['play_count'] + $playCount,
            'collect' => (int)$info['collect_count'] + $collectCount,
            'like' => (int)$info['like_count'] + $likeCount,
            'status' => (int)$info['status'],
            'parent_id' => $info['parent_id'] ?? '',
            'category_id' => $info['category_id'] ?? '',
            'url' => $info['video_url'], // ★★★ 加上这一行 ★★★
        ];

        return successJson($result, '获取成功');
    }

  /**
 * 判断还能不能看（支持 type+id，兼容长视频/暗网视频）
 * GET /api/long/videos/canWatch?userId=xxx&type=long&id=8
 */
public function canWatch(Request $request)
{
    $userId = $request->get('userId');
    $type = $request->get('type', 'long');
    $id = intval($request->get('id', 0));
    if (!$userId) {
        return errorJson('参数缺失');
    }

    // 1. 判断视频是否存在
    if ($type === 'darknet') {
        $video = Db::name('darknet_video')->where('id', $id)->find();
    } else {
        $video = Db::name('long_videos')->where('id', $id)->find();
    }
    if ($id && !$video) {
        return errorJson('视频不存在');
    }

    // 2. 读取配置
    $today = date('Y-m-d');
    $configs = Db::name('site_config')->column('config_value', 'config_key');
    $maxTimes = intval($configs['free_long_video_daily'] ?? 1);

    // 3. 查询用户今日已用次数
    $record = Db::name('user_daily_watch_count')
        ->where('uuid', $userId)
        ->where('date', $today)
        ->find();

    $used = $record ? intval($record['long_video_used']) : 0;
    $remaining = max(0, $maxTimes - $used);

    // 4. 返回
    return json([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'remaining' => $remaining
        ]
    ]);
}
public function play(Request $request)
{
    \think\facade\Log::info('Raw param='.json_encode($request->param()));

    // 支持 type+id，兼容老参数 video_id
    $type = $request->param('type', 'long');
    $videoId = intval($request->param('id', $request->param('video_id', 0)));
    $userId = $request->param('userId');

    $base62 = new \Tuupola\Base62();
    $encodedUserId = $base62->encode($userId);

    if (!$videoId || !$userId) {
        return errorJson('参数缺失');
    }

    // 查视频（兼容长视频/暗网视频/动漫/only圈）
    if ($type === 'darknet') {
        $video = Db::name('darknet_video')->where('id', $videoId)->find();
        if (!$video) return errorJson('视频不存在');
        $isVipVideo = isset($video['is_vip']) && intval($video['is_vip']) === 1;
        $isCoinVideo = isset($video['gold']) && intval($video['gold']) > 0;
        $isFreeVideo = !$isVipVideo && !$isCoinVideo;
        $videoUrl = $video['url'];
    } elseif ($type === 'anime') {
        $video = Db::name('anime_videos')->where('id', $videoId)->find();
        if (!$video) return errorJson('动漫不存在');
        $isVipVideo = isset($video['is_vip']) && intval($video['is_vip']) === 1;
        // 动漫金币字段可能是 coin 或 gold_required，兼容处理
        $coinVal = 0;
        if (isset($video['coin'])) {
            $coinVal = intval($video['coin']);
        } elseif (isset($video['gold_required'])) {
            $coinVal = intval($video['gold_required']);
        }
        $isCoinVideo = $coinVal > 0;
        $isFreeVideo = !$isVipVideo && !$isCoinVideo;
        // 动漫视频地址字段，假设叫 video_url，跟长视频保持一致
        $videoUrl = $video['video_url'] ?? '';
    } elseif ($type === 'star') {
        // ✅ only圈/OnlyFans：从 onlyfans_media 表读取
        $video = Db::name('onlyfans_media')->where('id', $videoId)->find();
        if (!$video) return errorJson('视频不存在');

        // 只允许播放视频类型，图集直接拦截
        if (($video['type'] ?? '') !== 'video') {
            return errorJson('该内容不是视频，无法播放');
        }

        $isVipVideo  = isset($video['is_vip']) && intval($video['is_vip']) === 1;
        $isCoinVideo = isset($video['coin']) && intval($video['coin']) > 0;
        $isFreeVideo = !$isVipVideo && !$isCoinVideo;
        $videoUrl    = $video['video_url'] ?? '';
    } else {
        $video = Db::name('long_videos')->where('id', $videoId)->find();
        if (!$video) return errorJson('视频不存在');
        $isVipVideo = isset($video['is_vip']) && intval($video['is_vip']) === 1;
        $isCoinVideo = isset($video['gold_required']) && intval($video['gold_required']) > 0;
        $isFreeVideo = !$isVipVideo && !$isCoinVideo;
        $videoUrl = $video['video_url'];
    }

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

    // 角标卡类型判定
    $isVipCard = $canViewVip === 1 && $canWatchCoin !== 1;
    $isCoinCard = $canWatchCoin === 1 && $canViewVip !== 1;
    $isSuperCard = $canViewVip === 1 && $canWatchCoin === 1;
    $isNormalUser = $canViewVip !== 1 && $canWatchCoin !== 1;

    // 是否已解锁且未过期
    $unlock = Db::name('user_video_unlock')
        ->where('user_id', $userId)
        ->where('video_id', $videoId)
        ->find();
    
    $unlocked = false;
    if ($unlock && (!isset($unlock['expire_time']) || strtotime($unlock['expire_time']) > time())) {
        $unlocked = true;
    }

    // 查剩余次数（普通用户/特殊场景用）
    $today = date('Y-m-d');
    $configs = Db::name('site_config')->column('config_value', 'config_key');
    $maxTimes = intval($configs['free_long_video_daily'] ?? 1);
    $record = Db::name('user_daily_watch_count')
        ->where('uuid', $userId)
        ->where('date', $today)
        ->find();
    $used = $record ? intval($record['long_video_used']) : 0;
    $remaining = max(0, $maxTimes - $used);

    if ($isFreeVideo) {
        return successJson([
            'url' => $videoUrl,
            'vip' => false,
            'unlocked' => true,
            'encoded_user' => $encodedUserId,
            'remaining' => $remaining,
            'type' => $type,
        ], '播放地址获取成功');
    }
    // 1. 至尊卡（VIP+金币权限都有）=> 任何VIP/金币视频都能直接看
    if ($isSuperCard) {
        return successJson([
            'url' => $videoUrl,
            'vip' => true,
            'unlocked' => true,
            'encoded_user' => $encodedUserId,
            'remaining' => $remaining,
            'type' => $type,
        ], '播放地址获取成功');
    }

    // 2. VIP卡，且进的是VIP视频
    if ($isVipCard && $isVipVideo) {
        return successJson([
            'url' => $videoUrl,
            'vip' => true,
            'unlocked' => true,
            'encoded_user' => $encodedUserId,
            'remaining' => $remaining,
            'type' => $type,
        ], '播放地址获取成功');
    }

    // 3. 金币卡，且进的是金币视频
    if ($isCoinCard && $isCoinVideo) {
        return successJson([
            'url' => $videoUrl,
            'vip' => false,
            'unlocked' => true,
            'encoded_user' => $encodedUserId,
            'remaining' => $remaining,
            'type' => $type,
        ], '播放地址获取成功');
    }

    // 4. 用户已购买/解锁该视频（未过期）
    if ($unlocked) {
        return successJson([
            'url' => $videoUrl,
            'vip' => $isVipVideo,
            'unlocked' => true,
            'encoded_user' => $encodedUserId,
            'remaining' => $remaining,
            'type' => $type,
        ], '播放地址获取成功');
    }

    // 5. 其它情况，需走免费次数（普通用户/部分卡用户遇到权限外视频）
    if ($remaining <= 0) {
        return errorJson('今日试看次数已用完', 403);
    }
    // 扣减次数
    if ($record) {
        Db::name('user_daily_watch_count')
            ->where('id', $record['id'])
            ->inc('long_video_used')
            ->update();
        $used++;
    } else {
        Db::name('user_daily_watch_count')->insert([
            'uuid' => $userId,
            'date' => $today,
            'long_video_used' => 1,
        ]);
        $used = 1;
    }
    $remaining = max(0, $maxTimes - $used);

    return successJson([
        'url' => $videoUrl,
        'vip' => $isVipVideo,
        'unlocked' => false,
        'encoded_user' => $encodedUserId,
        'remaining' => $remaining,
        'type' => $type,
    ], '播放地址获取成功');
}

    /**
     * 批量设置播放数
     * POST /api/long/videos/batch-set-play
     */
    public function batchSetPlay(Request $request)
    {
        $ids = $request->post('ids', []);
        $playCount = intval($request->post('play_count', 0));
        
        if (!is_array($ids) || empty($ids)) {
            return json([
                'code' => 1, 
                'msg' => '参数错误：视频ID列表不能为空',
                'data' => null
            ]);
        }
        
        if ($playCount < 0) {
            return json([
                'code' => 1, 
                'msg' => '参数错误：播放数不能为负数',
                'data' => null
            ]);
        }

        try {
            $count = Db::name('long_videos')
                ->whereIn('id', $ids)
                ->update([
                    'play_count' => $playCount,
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            
            if ($count !== false) {
                return json([
                    'code' => 0, 
                    'msg' => '批量设置播放数成功',
                    'data' => null
                ]);
            } else {
                return json([
                    'code' => 1, 
                    'msg' => '批量设置播放数失败',
                    'data' => null
                ]);
            }
        } catch (\Exception $e) {
            return json([
                'code' => 1, 
                'msg' => '批量设置播放数失败：' . $e->getMessage(),
                'data' => null
            ]);
        }
    }

    /**
     * 批量设置收藏数
     * POST /api/long/videos/batch-set-collect
     */
    public function batchSetCollect(Request $request)
    {
        $ids = $request->post('ids', []);
        $collectCount = intval($request->post('collect_count', 0));
        
        if (!is_array($ids) || empty($ids)) {
            return json([
                'code' => 1, 
                'msg' => '参数错误：视频ID列表不能为空',
                'data' => null
            ]);
        }
        
        if ($collectCount < 0) {
            return json([
                'code' => 1, 
                'msg' => '参数错误：收藏数不能为负数',
                'data' => null
            ]);
        }

        try {
            $count = Db::name('long_videos')
                ->whereIn('id', $ids)
                ->update([
                    'collect_count' => $collectCount,
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            
            if ($count !== false) {
                return json([
                    'code' => 0, 
                    'msg' => '批量设置收藏数成功',
                    'data' => null
                ]);
            } else {
                return json([
                    'code' => 1, 
                    'msg' => '批量设置收藏数失败',
                    'data' => null
                ]);
            }
        } catch (\Exception $e) {
            return json([
                'code' => 1, 
                'msg' => '批量设置收藏数失败：' . $e->getMessage(),
                'data' => null
            ]);
            
        }
    }
 
 
    public function h5List(Request $request)
    {
        $params = $request->get();
        $parentId = isset($params['parent_id']) ? intval($params['parent_id']) : 0;
        $page = max(1, intval($params['page'] ?? 1));
        $pageSize = max(1, intval($params['pageSize'] ?? 3)); // 每页3个子分类
    
        // 1. 查主分类（只取一个）
        $parent = Db::name('long_video_categories')
            ->where('id', $parentId)
            ->find();
        if (!$parent) {
            return json(['code' => 1, 'msg' => '主分类不存在', 'data' => []]);
        }
    
        // 2. 查子分类，分页，按sort排序
        $childrenQuery = Db::name('long_video_categories')
            ->where('parent_id', $parentId)
            ->order('sort asc, id asc');
        $total = $childrenQuery->count();
        $children = $childrenQuery->page($page, $pageSize)->select()->toArray();
    
        // 3. 每个子分类查6条视频，按sort排序
        foreach ($children as &$c) {
            $videos = Db::name('long_videos')
                ->where('status', 1)
                ->where('category_id', $c['id'])
                ->order('sort asc, id desc')
                ->limit(6)
                ->field([
                    'id', 'title', 'cover_url', 'duration', 'tags', 'sort',
                    'is_vip', 'gold_required', 'play_count', 'collect_count'
                ])
                ->select()
                ->toArray();
    
            foreach ($videos as &$v) {
                $v['tags'] = json_decode($v['tags'], true) ?: [];
                $v['vip'] = (bool)$v['is_vip'];
                $v['coin'] = (int)$v['gold_required'];
                $v['play'] = (int)$v['play_count'];
                $v['collect'] = (int)$v['collect_count'];
                unset($v['is_vip'], $v['gold_required'], $v['play_count'], $v['collect_count']);
            }
            unset($v);
    
            $c['videos'] = $videos;
        }
        unset($c);
    
        // 4. 返回结构
        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'categories' => $children,
                'total' => $total,
                'current_page' => $page,
                'total_pages' => ceil($total / $pageSize),
                'per_page' => $pageSize,
                'parent' => $parent
            ]
        ]);
    }
    /**
     * 获取某个子分类下的全部视频（支持分页）
     */
    public function categoryVideos(Request $request, $category_id)
    {
        $categoryId = intval($category_id);
        if (!$categoryId) {
            return json(['code' => 1, 'msg' => '子分类ID不能为空']);
        }

        $page = max(1, intval($request->get('page', 1)));
        $pageSize = max(1, intval($request->get('pageSize', 20)));
        $sort = $request->get('sort', '');

        $query = Db::name('long_videos')
            ->alias('lv')
            ->leftJoin('long_video_categories lvc', 'lv.category_id = lvc.id')
            ->where('lv.status', 1)
            ->where('lv.category_id', $categoryId);

        // ★★★ 根据sort参数动态排序 ★★★
        if ($sort === 'collect') {
            $query->order('collect_count desc, lv.id desc');
        } elseif ($sort === 'play') {
            $query->order('play_count desc, lv.id desc');
        } elseif ($sort === 'new') {
            $query->order('lv.id desc');
        } elseif ($sort === 'week') {
            $query->order('week_play desc, lv.id desc');
        } elseif ($sort === 'month') {
            $query->order('month_play desc, lv.id desc');
        } elseif ($sort === 'last_month') {
            $query->order('last_month_play desc, lv.id desc');
        } else {
            $query->order('sort asc, lv.id desc');
        }

        $total = $query->count();

        $videos = $query->field([
                'lv.id',
                'lv.title',
                'lv.cover_url',
                'lv.duration',
                'lv.play_count',
                'lv.collect_count',
                'lv.gold_required',
                'lv.is_vip',
                'lv.tags',
                'lv.sort',
            ])
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        foreach ($videos as &$v) {
            $v['tags'] = json_decode($v['tags'], true) ?: [];
            $v['vip'] = (bool)$v['is_vip'];
            $v['coin'] = (int)$v['gold_required'];
            $v['play'] = (int)$v['play_count'];
            $v['collect'] = (int)$v['collect_count'];
            unset($v['is_vip'], $v['gold_required'], $v['play_count'], $v['collect_count']);
        }

        return json([
            'code' => 0,
            'msg' => '获取子分类视频成功',
            'data' => [
                'list' => $videos,
                'total' => $total,
                'category_id' => $categoryId,
                'current_page' => $page,
                'total_pages' => ceil($total / $pageSize),
                'per_page' => $pageSize
            ]
        ]);
    }
 /**
     * H5专用：获取全部视频分页列表（不带视频地址）
     * GET /api/h5/long_videos/all
     */
    public function h5AllVideos(Request $request)
    {
        $type = $request->get('type', 'long'); // 新增
        $page = max(1, intval($request->get('page', 1)));
        $pageSize = max(1, intval($request->get('pageSize', 20)));
        $categoryId = intval($request->get('category_id', 0));
        $random = intval($request->get('random', 0));
        $keyword = trim($request->get('keyword', ''));
        $sort = $request->get('sort', '');
        $priceType = $request->get('priceType', '');
        $tag = $request->get('tag', '');
    
        if ($type === 'darknet') {
            $query = Db::name('darknet_video')->where('status', 1);
    
            if ($categoryId) $query->where('category_id', $categoryId);
            if ($keyword !== '') $query->where('title', 'like', "%{$keyword}%");
            if ($priceType === 'VIP') $query->where('is_vip', 1);
            elseif ($priceType === '金币') $query->where('gold', '>', 0);
            elseif ($priceType === '免费') $query->where('is_vip', 0)->where('gold', 0);
            if ($tag !== '') {
                if (is_array($tag)) {
                    foreach ($tag as $t) {
                        $query->whereRaw("JSON_CONTAINS(tags, ?) = 1", [json_encode($t)]);
                    }
                } else {
                    $query->whereRaw("JSON_CONTAINS(tags, ?) = 1", [json_encode($tag)]);
                }
            }
            if ($random) $query->orderRaw('RAND()');
            else {
                if ($sort === 'collect') $query->order('collect desc, id desc');
                elseif ($sort === 'play') $query->order('play desc, id desc');
                elseif ($sort === 'new') $query->order('id desc');
                else $query->order('sort asc, id desc');
            }
    
            $total = $query->count();
            $list = $query->field([
                'id', 'title', 'cover', 'preview', 'tags', 'sort', 'is_vip', 'gold', 'play', 'collect', 'duration'
            ])->page($page, $pageSize)->select()->toArray();
    
            foreach ($list as &$v) {
                $v['cover_url'] = $v['cover'];
                $v['preview_duration'] = $v['preview'];
                $v['vip'] = (bool)$v['is_vip'];
                $v['coin'] = (int)$v['gold'];
                $v['play'] = (int)$v['play'];
                $v['collect'] = (int)$v['collect'];
                $v['tags'] = is_string($v['tags']) ? json_decode($v['tags'], true) : ($v['tags'] ?: []);
                unset($v['is_vip'], $v['gold'], $v['cover'], $v['preview']);
            }
            unset($v);
    
        } else {
            $query = Db::name('long_videos')->where('status', 1);
    
            if ($categoryId) $query->where('category_id', $categoryId);
            if ($keyword !== '') $query->where('title', 'like', "%{$keyword}%");
            if ($priceType === 'VIP') $query->where('is_vip', 1);
            elseif ($priceType === '金币') $query->where('gold_required', '>', 0);
            elseif ($priceType === '免费') $query->where('is_vip', 0)->where('gold_required', 0);
            if ($tag !== '') {
                if (is_array($tag)) {
                    foreach ($tag as $t) {
                        $query->whereRaw("JSON_CONTAINS(tags, ?) = 1", [json_encode($t)]);
                    }
                } else {
                    $query->whereRaw("JSON_CONTAINS(tags, ?) = 1", [json_encode($tag)]);
                }
            }
            if ($random) $query->orderRaw('RAND()');
            else {
                if ($sort === 'collect') $query->order('collect_count desc, id desc');
                elseif ($sort === 'play') $query->order('play_count desc, id desc');
                elseif ($sort === 'new') $query->order('id desc');
                else $query->order('sort asc, id desc');
            }
    
            $total = $query->count();
            $list = $query->field([
                'id', 'title', 'cover_url', 'preview_duration', 'tags', 'sort', 'is_vip', 'gold_required', 'play_count', 'collect_count', 'duration'
            ])->page($page, $pageSize)->select()->toArray();
    
            foreach ($list as &$v) {
                $v['vip'] = (bool)$v['is_vip'];
                $v['coin'] = (int)$v['gold_required'];
                $v['play'] = (int)$v['play_count'];
                $v['collect'] = (int)$v['collect_count'];
                $v['tags'] = is_string($v['tags']) ? json_decode($v['tags'], true) : ($v['tags'] ?: []);
                unset($v['is_vip'], $v['gold_required'], $v['play_count'], $v['collect_count']);
            }
            unset($v);
        }
    
        return json([
            'code' => 0,
            'msg' => '获取全部视频成功',
            'data' => [
                'list' => $list,
                'total' => $total,
                'current_page' => $page,
                'total_pages' => ceil($total / $pageSize),
                'per_page' => $pageSize
            ]
        ]);
    }
   /**
 * H5专用：猜你喜欢推荐（支持 type+id，兼容长视频/暗网视频）
 * GET /api/h5/long_videos/guess_you_like?type=long&id=8&limit=8&page=1
 */
public function h5GuessYouLike(Request $request)
{
    $type  = $request->get('type', 'long');
    $id    = intval($request->get('id', $request->get('video_id', 0)));
    $page  = max(1, intval($request->get('page', 1)));
    $limit = max(1, intval($request->get('limit', 8)));
    $offset = ($page - 1) * $limit;

    if (!$id) {
        return json(['code' => 1, 'msg' => '缺少id', 'data' => []]);
    }

    if ($type === 'darknet') {
        // ---------- 原 darknet 分支，保持不变 ----------
        $video = Db::name('darknet_video')->where('id', $id)->find();
        if (!$video) {
            return json(['code' => 1, 'msg' => '视频不存在', 'data' => []]);
        }
        $categoryId = $video['category_id'];
        $parentId   = $video['parent_id'] ?? 0;
        $title      = $video['title'];

        $excludeIds = [$id];

        $query = Db::name('darknet_video')
            ->where('status', 1)
            ->where('category_id', $categoryId)
            ->where('id', '<>', $id)
            ->where('title', '<>', $title)
            ->order('play desc, collect desc, id desc')
            ->field([
                'id', 'title', 'cover', 'tags', 'is_vip', 'gold', 'play', 'collect', 'preview'
            ]);
        $mainList = $query->limit($offset, $limit)->select()->toArray();
        $excludeIds = array_merge($excludeIds, array_column($mainList, 'id'));

        $moreList = [];
        if (count($mainList) < $limit && $parentId) {
            $moreList = Db::name('darknet_video')
                ->where('status', 1)
                ->where('parent_id', $parentId)
                ->where('category_id', '<>', $categoryId)
                ->whereNotIn('id', $excludeIds)
                ->where('title', '<>', $title)
                ->order('play desc, collect desc, id desc')
                ->limit($limit - count($mainList))
                ->field([
                    'id', 'title', 'cover', 'tags', 'is_vip', 'gold', 'play', 'collect', 'preview'
                ])
                ->select()->toArray();
        }

        $list = array_merge($mainList, $moreList);
        foreach ($list as &$v) {
            $v['cover_url'] = $v['cover'];
            if (is_string($v['tags'])) {
                $tags = json_decode($v['tags'], true);
                if (!is_array($tags)) {
                    $tags = [$v['tags']];
                }
            } elseif (is_numeric($v['tags'])) {
                $tags = [strval($v['tags'])];
            } elseif (is_array($v['tags'])) {
                $tags = $v['tags'];
            } else {
                $tags = [];
            }
            $v['tags'] = $tags;
            $v['vip'] = (bool)$v['is_vip'];
            $v['coin'] = (int)$v['gold'];
            $v['play'] = (int)$v['play'];
            $v['collect'] = (int)$v['collect'];
            $v['preview_duration'] = $v['preview'];
            $v['type'] = 'darknet';
            unset($v['is_vip'], $v['gold'], $v['cover'], $v['preview']);
        }
        unset($v);

    } elseif ($type === 'anime') {
        // ---------- 原 anime 分支，保持不变 ----------
        $video = Db::name('anime_videos')->where('id', $id)->find();
        if (!$video) {
            return json(['code' => 1, 'msg' => '动漫不存在', 'data' => []]);
        }
        $categoryId = $video['category_id'];
        $parentId   = $video['parent_id'] ?? 0;
        $title      = $video['title'];

        $excludeIds = [$id];

        $query = Db::name('anime_videos')
            ->where('status', 1)
            ->where('category_id', $categoryId)
            ->where('id', '<>', $id)
            ->where('title', '<>', $title)
            ->order('views desc, collects desc, likes desc, id desc')
            ->field([
                'id', 'title', 'cover', 'duration', 'tags',
                'is_vip', 'coin', 'views', 'collects', 'likes'
            ]);
        $mainList = $query->limit($offset, $limit)->select()->toArray();
        $excludeIds = array_merge($excludeIds, array_column($mainList, 'id'));

        $moreList = [];
        if (count($mainList) < $limit && $parentId) {
            $moreList = Db::name('anime_videos')
                ->where('status', 1)
                ->where('parent_id', $parentId)
                ->where('category_id', '<>', $categoryId)
                ->whereNotIn('id', $excludeIds)
                ->where('title', '<>', $title)
                ->order('views desc, collects desc, likes desc, id desc')
                ->limit($limit - count($mainList))
                ->field([
                    'id', 'title', 'cover', 'duration', 'tags',
                    'is_vip', 'coin', 'views', 'collects', 'likes'
                ])
                ->select()->toArray();
        }
        $tagMap = Db::name('anime_tags')->column('name', 'id');

        $list = array_merge($mainList, $moreList);
        foreach ($list as &$v) {
            if (is_string($v['tags'])) {
                $tags = json_decode($v['tags'], true);
                if (!is_array($tags)) {
                    if (strpos($v['tags'], ',') !== false) {
                        $tags = explode(',', $v['tags']);
                    } else {
                        $tags = [$v['tags']];
                    }
                }
            } elseif (is_numeric($v['tags'])) {
                $tags = [strval($v['tags'])];
            } elseif (is_array($v['tags'])) {
                $tags = $v['tags'];
            } else {
                $tags = [];
            }

            $tagNames = [];
            foreach ($tags as $tagId) {
                $tid = intval($tagId);
                if (isset($tagMap[$tid])) $tagNames[] = $tagMap[$tid];
            }
            $v['tags'] = $tagNames;

            $v['vip'] = (bool)$v['is_vip'];
            $v['coin'] = (int)$v['coin'];
            $v['play'] = (int)($v['views'] ?? 0);
            $v['collect'] = (int)($v['collects'] ?? 0);
            $v['type'] = 'anime';
            $v['cover_url'] = $v['cover'];
            unset($v['is_vip'], $v['views'], $v['collects'], $v['likes'], $v['cover']);
        }
        unset($v);

    } elseif ($type === 'star') {
    // ---------- star(OnlyFans/only圈) 仅视频 ----------
    $media = Db::name('onlyfans_media')->where('id', $id)->find();
    if (!$media) {
        return json(['code' => 1, 'msg' => '内容不存在', 'data' => []]);
    }

    $creator   = Db::name('onlyfans_creators')->where('id', $media['creator_id'])->find();
    $creatorId = (int)($media['creator_id'] ?? 0);
    $categoryId = (int)($creator['category_id'] ?? 0);
    $title = $media['title'];

    $excludeIds = [$id];

    // 1) 同作者其它“视频”内容
    $mainList = Db::name('onlyfans_media')
        ->where('status', 1)
        ->where('type', 'video')                       // ✅ 只取视频
        ->where('creator_id', $creatorId)
        ->where('id', '<>', $id)
        ->where('title', '<>', $title)
        ->order('view_count desc, like_count desc, id desc')
        ->field('id,title,cover,type,is_vip,coin,view_count,like_count,favorite_count,tag_ids,video_url')
        ->limit($offset, $limit)
        ->select()
        ->toArray();
    $excludeIds = array_merge($excludeIds, array_column($mainList, 'id'));

    // 2) 不足再补：同分类下其它作者的“视频”
    $moreList = [];
    if (count($mainList) < $limit && $categoryId) {
        $moreList = Db::name('onlyfans_media')->alias('m')
            ->leftJoin('onlyfans_creators c', 'm.creator_id = c.id')
            ->where('m.status', 1)
            ->where('m.type', 'video')                 // ✅ 只取视频
            ->where('c.category_id', $categoryId)
            ->where('m.creator_id', '<>', $creatorId)
            ->whereNotIn('m.id', $excludeIds)
            ->where('m.title', '<>', $title)
            ->order('m.view_count desc, m.like_count desc, m.id desc')
            ->limit($limit - count($mainList))
            ->field('m.id,m.title,m.cover,m.type,m.is_vip,m.coin,m.view_count,m.like_count,m.favorite_count,m.tag_ids,m.video_url')
            ->select()
            ->toArray();
    }

    $list = array_merge($mainList, $moreList);

    // 批量解析 tag_ids -> 名称
    $allTagIds = [];
    $split = function (?string $s): array {
        $s = (string)$s;
        if ($s === '') return [];
        $s = str_replace(['，','、',' '], ',', $s);
        preg_match_all('/\d+/', $s, $m);
        $ids = array_map('intval', $m[0] ?? []);
        return array_values(array_unique($ids));
    };
    foreach ($list as $row) {
        $allTagIds = array_merge($allTagIds, $split($row['tag_ids'] ?? ''));
    }
    $allTagIds = array_values(array_unique($allTagIds));
    $tagMap = empty($allTagIds) ? [] :
        Db::name('onlyfans_tags')->whereIn('id', $allTagIds)->column('name','id');

    foreach ($list as &$v) {
        // tags 数组
        $ids  = $split($v['tag_ids'] ?? '');
        $tags = [];
        foreach ($ids as $tid) {
            $tags[] = $tagMap[$tid] ?? ('#'.$tid);
        }
        $v['tags'] = $tags;

        // 字段归一化
       $v['cover_url'] = $v['cover_url'] ?? $v['cover'] ?? '/static/images/default-cover.png';
        $v['vip']      = (bool)($v['is_vip'] ?? 0);
        $v['coin']     = (int)($v['coin'] ?? 0);
        $v['play']     = (int)($v['view_count'] ?? 0);
        $v['collect']  = (int)($v['favorite_count'] ?? 0);
        $v['like']     = (int)($v['like_count'] ?? 0);
        $v['type']     = 'star';       // 业务域
        $v['media_type'] = 'video';    // ✅ 固定为视频
        // 不返回真实视频地址
        unset($v['is_vip'], $v['view_count'], $v['favorite_count'], $v['like_count'], $v['cover'], $v['tag_ids'], $v['video_url'], $v['type']); 
        // 注意上面 unset($v['type']) 会把 m.type 去掉；如需保留业务域，可先用其他字段名保存
        $v['content_domain'] = 'star'; // 可选：用另一个字段保留业务域
    }
    unset($v);


    } else {
        // ---------- 原 long 分支，保持不变 ----------
        $video = Db::name('long_videos')->where('id', $id)->find();
        if (!$video) {
            return json(['code' => 1, 'msg' => '视频不存在', 'data' => []]);
        }
        $categoryId = $video['category_id'];
        $parentId   = $video['parent_id'] ?? 0;
        $title      = $video['title'];

        $excludeIds = [$id];

        $query = Db::name('long_videos')
            ->where('status', 1)
            ->where('category_id', $categoryId)
            ->where('id', '<>', $id)
            ->where('title', '<>', $title)
            ->order('play_count desc, collect_count desc, id desc')
            ->field([
                'id', 'title', 'cover_url', 'duration', 'tags',
                'is_vip', 'gold_required', 'play_count', 'collect_count', 'preview_duration'
            ]);
        $mainList = $query->limit($offset, $limit)->select()->toArray();
        $excludeIds = array_merge($excludeIds, array_column($mainList, 'id'));

        $moreList = [];
        if (count($mainList) < $limit && $parentId) {
            $moreList = Db::name('long_videos')
                ->alias('lv')
                ->leftJoin('long_video_categories lvc', 'lv.category_id = lvc.id')
                ->where('lv.status', 1)
                ->where('lvc.parent_id', $parentId)
                ->where('lv.category_id', '<>', $categoryId)
                ->whereNotIn('lv.id', $excludeIds)
                ->where('lv.title', '<>', $title)
                ->order('lv.play_count desc, lv.collect_count desc, lv.id desc')
                ->limit($limit - count($mainList))
                ->field([
                    'lv.id', 'lv.title', 'lv.cover_url', 'lv.duration', 'lv.tags',
                    'lv.is_vip', 'lv.gold_required', 'lv.play_count', 'lv.collect_count', 'lv.preview_duration'
                ])
                ->select()->toArray();
        }

        $list = array_merge($mainList, $moreList);
        foreach ($list as &$v) {
            // user_actions 统计
            $userLikeCount = Db::name('user_actions')
                ->where('content_id', $v['id'])
                ->where('content_type', 'long_video')
                ->where('action_type', 'like')
                ->count('DISTINCT user_id');
            
            $userCollectCount = Db::name('user_actions')
                ->where('content_id', $v['id'])
                ->where('content_type', 'long_video')
                ->where('action_type', 'collect')
                ->count('DISTINCT user_id');

            $v['tags'] = is_string($v['tags']) ? json_decode($v['tags'], true) : ($v['tags'] ?: []);
            $v['vip'] = (bool)$v['is_vip'];
            $v['coin'] = (int)$v['gold_required'];
            $v['play'] = (int)$v['play_count'];
            $v['collect'] = (int)$v['collect_count'] + $userCollectCount;
            $v['like'] = ($v['like_count'] ?? 0) + $userLikeCount;
            $v['type'] = 'long';
            unset($v['is_vip'], $v['gold_required'], $v['play_count'], $v['collect_count']);
        }
        unset($v);
    }

    return json([
        'code' => 0,
        'msg'  => '猜你喜欢推荐成功',
        'data' => [
            'list'         => $list,
            'total'        => count($list),
            'current_page' => $page,
            'per_page'     => $limit,
            'total_pages'  => 1 // 推荐一页即可
        ]
    ]);
}

/**
 * 统一行为埋点接口 - 同时记录统计和浏览记录
 * POST /api/h5/video/track
 * 参数:
 * - type: 视频类型（long/darknet/anime）
 * - id: 视频ID
 * - action: 行为类型 (view, collect, like)
 * - user_uuid: 用户UUID（用于浏览记录，可选）
 * 兼容老参数 video_id
 */
public function track(Request $request)
{
    $type     = $request->post('type', 'long');
    $videoId  = intval($request->post('id', $request->post('video_id', 0)));
    $action   = $request->post('action', '');          // view / collect / like
    $userUuid = $request->post('user_uuid', '');       // 浏览记录用

    if (!$videoId || !in_array($action, ['view', 'collect', 'like'])) {
        return json(['code' => 1, 'msg' => '参数错误']);
    }

    // ========= 新增：抖音只记录浏览记录，不做统计 =========
    if ($type === 'douyin') {
        // 仅校验视频存在
        $video = Db::name('douyin_videos')
            ->where('id', $videoId)
            ->where('status', 1)
            ->field('id,title,category_id')
            ->find();

        if (!$video) {
            return json(['code' => 1, 'msg' => '视频不存在']);
        }

        // 只处理浏览(view)行为；不写 video_track 统计
        if ($action === 'view' && !empty($userUuid)) {
            // 5分钟去重
            $browseExists = Db::name('user_browse_logs')
                ->where([
                    'user_uuid'  => $userUuid,
                    'type'       => 'douyin',
                    'content_id' => $videoId,
                ])
                ->whereTime('browse_time', '>=', date('Y-m-d H:i:00', time() - 300))
                ->find();

            if (!$browseExists) {
                Db::name('user_browse_logs')->insert([
                    'user_uuid'     => $userUuid,
                    'type'          => 'douyin',                     // ★ 记录为 douyin
                    'content_id'    => $videoId,
                    'category_id'   => $video['category_id'] ?? 0,
                    'content_title' => $video['title'] ?? '',
                    'browse_time'   => date('Y-m-d H:i:s'),
                    'source'        => 'track',
                    'extra'         => json_encode([
                        'ip'         => $request->ip(),
                        'user_agent' => $request->header('user-agent', ''),
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        // 其它 action（collect / like）对 douyin 不做统计，直接返回成功
        return json(['code' => 0, 'msg' => 'success']);
    }
    // ========= 抖音分支结束，其它类型保持原逻辑 =========

    // ========= 新增：only圈（star）只记录浏览记录，不做统计 =========
    if ($type === 'star') {
        // 仅校验内容存在
        $media = Db::name('onlyfans_media')
            ->where('id', $videoId)
            ->where('status', 1)
            ->field('id,title,creator_id')
            ->find();

        if (!$media) {
            return json(['code' => 1, 'msg' => '视频不存在']);
        }

        // 只处理浏览(view)行为；不写 video_track 统计
        if ($action === 'view' && !empty($userUuid)) {
            // 5分钟去重
            $browseExists = Db::name('user_browse_logs')
                ->where([
                    'user_uuid'  => $userUuid,
                    'type'       => 'star',
                    'content_id' => $videoId,
                ])
                ->whereTime('browse_time', '>=', date('Y-m-d H:i:00', time() - 300))
                ->find();

            if (!$browseExists) {
                // 取分类（从作者取 category_id）
                $creator = Db::name('onlyfans_creators')
                    ->where('id', $media['creator_id'] ?? 0)
                    ->field('category_id')
                    ->find();

                Db::name('user_browse_logs')->insert([
                    'user_uuid'     => $userUuid,
                    'type'          => 'star',                       // ★ 记录为 star
                    'content_id'    => $videoId,
                    'category_id'   => $creator['category_id'] ?? 0,
                    'content_title' => $media['title'] ?? '',
                    'browse_time'   => date('Y-m-d H:i:s'),
                    'source'        => 'track',
                    'extra'         => json_encode([
                        'ip'         => $request->ip(),
                        'user_agent' => $request->header('user-agent', ''),
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        // 其它 action（collect / like）对 star 不做统计，直接返回成功
        return json(['code' => 0, 'msg' => 'success']);
    }
    // ========= only圈分支结束，其它类型保持原逻辑 =========

    // ========= 新增：only圈图片（star_image）只记录浏览记录，不做统计 =========
    if ($type === 'star_image') {
        // 调试日志：记录接收到的参数
        file_put_contents(runtime_path() . 'star_image_debug.log', 
            date('c') . " star_image埋点: videoId={$videoId}, userUuid={$userUuid}, action={$action}\n", 
            FILE_APPEND);

        // 先查询表中所有记录，调试用
        $allImages = Db::name('onlyfans_media')->where('type', 'image')->limit(5)->select()->toArray();
        file_put_contents(runtime_path() . 'star_image_debug.log', 
            date('c') . " onlyfans_media表中图片记录: " . json_encode($allImages) . "\n", 
            FILE_APPEND);

        // 仅校验图片存在 - 查询 onlyfans_media 表中的图片
        $image = Db::name('onlyfans_media')
            ->where('id', $videoId)
            ->where('type', 'image')  // 只查图片类型
            ->field('id,title,cover,type')
            ->find();

        // 调试日志：记录查询结果
        file_put_contents(runtime_path() . 'star_image_debug.log', 
            date('c') . " onlyfans_media查询结果: " . json_encode($image) . "\n", 
            FILE_APPEND);

        if (!$image) {
            return json(['code' => 1, 'msg' => '图片不存在']);
        }

        // 只处理浏览(view)行为；不写 video_track 统计
        if ($action === 'view' && !empty($userUuid)) {
            // 5分钟去重 - 使用图片的真实ID
            $browseExists = Db::name('user_browse_logs')
                ->where([
                    'user_uuid'  => $userUuid,
                    'type'       => 'star_image',
                    'content_id' => $image['id'], // 使用图片的真实ID
                ])
                ->whereTime('browse_time', '>=', date('Y-m-d H:i:00', time() - 300))
                ->find();

            if (!$browseExists) {
                Db::name('user_browse_logs')->insert([
                    'user_uuid'     => $userUuid,
                    'type'          => 'star_image',                 // ★ 记录为 star_image
                    'content_id'    => $image['id'], // 使用图片的真实ID
                    'category_id'   => 0, // 图片暂无分类
                    'content_title' => $image['title'] ?? '', // 使用 title 字段
                    'browse_time'   => date('Y-m-d H:i:s'),
                    'source'        => 'track',
                    'extra'         => json_encode([
                        'ip'         => $request->ip(),
                        'user_agent' => $request->header('user-agent', ''),
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        // 其它 action（collect / like）对 star_image 不做统计，直接返回成功
        return json(['code' => 0, 'msg' => 'success']);
    }
    // ========= only圈图片分支结束，其它类型保持原逻辑 =========

    // 内容类型映射（保留原来）
    $contentTypeMap = [
        'long'   => 'long_video',
        'darknet'=> 'darknet',
        'anime'  => 'anime',
        'audio'  => 'audio',
        'comic'  => 'comic',
        'novel'  => 'novel'
    ];

    // 其余类型：原逻辑不变 —— 校验视频存在
    if ($type === 'darknet') {
        $video = Db::name('darknet_video')->where('id', $videoId)->find();
    } elseif ($type === 'anime') {
        $video = Db::name('anime_videos')->where('id', $videoId)->find();
    } elseif ($type === 'comic') {
        $video = Db::name('comic_manga')->where('id', $videoId)->find();
    } elseif ($type === 'audio') {
        $video = Db::name('audio_novels')->where('id', $videoId)->find();
    } elseif ($type === 'novel') {
        $video = Db::name('text_novel')->where('id', $videoId)->find();
    } else {
        $video = Db::name('long_videos')->where('id', $videoId)->find();
    }
    if (!$video) {
        return json(['code' => 1, 'msg' => '视频不存在']);
    }

    // 统计埋点（保留原逻辑）
    $userId = $request->middleware('auth.user_id', 0);
    $ip     = $request->ip();

    // 1) 统计表去重后写入（star 已经在上面提前 return，不会走到这里）
    $exists = Db::name('video_track')
        ->where([
            'video_id' => $videoId,
            'action'   => $action,
            'ip'       => $ip,
            'type'     => $type,
        ])
        ->whereTime('create_time', '>=', date('Y-m-d H:i:00', time() - 60))
        ->find();

    if (!$exists) {
        Db::name('video_track')->insert([
            'user_id'     => $userId,
            'video_id'    => $videoId,
            'action'      => $action,
            'ip'          => $ip,
            'type'        => $type,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }

    // 2) 浏览记录（仅 view 且有 user_uuid）
    if ($action === 'view' && !empty($userUuid)) {
        $contentType = $contentTypeMap[$type] ?? 'long_video';

        $browseExists = Db::name('user_browse_logs')
            ->where([
                'user_uuid'  => $userUuid,
                'type'       => $contentType,
                'content_id' => $videoId,
            ])
            ->whereTime('browse_time', '>=', date('Y-m-d H:i:00', time() - 300))
            ->find();

        if (!$browseExists) {
            Db::name('user_browse_logs')->insert([
                'user_uuid'     => $userUuid,
                'type'          => $contentType,
                'content_id'    => $videoId,
                'category_id'   => $video['category_id'] ?? 0,
                'content_title' => $video['title'] ?? '',
                'browse_time'   => date('Y-m-d H:i:s'),
                'source'        => 'track',
                'extra'         => json_encode([
                    'ip'         => $ip,
                    'user_agent' => $request->header('user-agent', ''),
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    return json(['code' => 0, 'msg' => 'success']);
}


/**
 * 榜单接口
 * GET /api/h5/video/rank
 * 参数:
 * - action: view/collect/like (行为类型)
 * - range: day/week/month/year (时间范围)
 */
public function rank(Request $request)
{
    $action = $request->get('action', 'view');
    $range = $request->get('range', 'day');
    $page = max(1, (int)$request->get('page', 1));
    $pageSize = max(10, (int)$request->get('pageSize', 30));
    $offset = ($page - 1) * $pageSize;

    // 计算时间起点
    switch ($range) {
        case 'day':
            $start = date('Y-m-d 00:00:00');
            break;
        case 'week':
            $start = date('Y-m-d 00:00:00', strtotime('this week Monday'));
            break;
        case 'month':
            $start = date('Y-m-01 00:00:00');
            break;
        case 'year':
            $start = date('Y-01-01 00:00:00');
            break;
        default:
            $start = date('Y-m-d 00:00:00');
    }

    // 查询符合条件的视频ID及计数，分页
    $subQuery = Db::name('video_track')
        ->field('video_id, COUNT(*) AS num')
        ->where('action', $action)
        ->where('type', 'long') // ★ 只查长视频的埋点
        ->where('create_time', '>=', $start)
        ->group('video_id')
        ->order('num', 'desc');

    // 生成子查询的 SQL
    $subQuerySql = $subQuery->buildSql();

    // 使用 Db::table() 执行子查询
    $totalRows = Db::table("({$subQuerySql}) AS t")->count();

    // 分页查询
    $pageRows = Db::table("({$subQuerySql}) AS t")->limit($offset, $pageSize)->select()->toArray();

    // 提取视频ID列表
    $videoIds = array_column($pageRows, 'video_id');
    if (empty($videoIds)) {
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [],
            'total' => 0,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    // 查询视频详情
    $videos = Db::name('long_videos')
        ->whereIn('id', $videoIds)
        ->field([
            'id AS video_id',
            'title',
            'cover_url AS cover', // 修改字段名称为前端期望的 cover
            'tags',
            'publish_time AS create_time', // 修改字段名称为 create_time
            'collect_count',
            'play_count',
            'gold_required AS coin', // 修改字段名称为 coin
            'is_vip',
            'duration'
        ])
        ->select()
        ->toArray();

    // 按 video_id 索引，方便合并
    $videosMap = [];
    foreach ($videos as $video) {
        $video['tags'] = json_decode($video['tags'], true) ?: []; // 解码标签
        $videosMap[$video['video_id']] = $video;
    }

    // 合并计数和详情，保持榜单顺序
    $result = [];
    foreach ($pageRows as $row) {
        $vid = $row['video_id'];
        if (isset($videosMap[$vid])) {
            $result[] = array_merge($videosMap[$vid], [
                'num' => $row['num'], // 统计数量
            ]);
        }
    }

    return json([
        'code' => 0,
        'msg' => 'success',
        'data' => $result,
        'total' => $totalRows,
        'page' => $page,
        'pageSize' => $pageSize,
    ]);
}
    /**
 * H5专用：限免专区视频列表
 * GET /api/h5/long_videos/limited_free
 * 只返回非VIP且金币为0的视频
 */
public function h5LimitedFree(Request $request)
{
    $page = max(1, intval($request->get('page', 1)));
    $pageSize = max(1, intval($request->get('pageSize', 20)));

    $query = Db::name('long_videos')
        ->where('status', 1)
        ->where('is_vip', 0)
        ->where('gold_required', 0);

    $total = $query->count();

    $list = $query->field([
            'id',
            'title',
            'cover_url AS cover',
            'duration',
            'tags',
            'category_id',
            'publish_time AS create_time',
            'play_count',
        ])
        ->order('sort asc, id desc')
        ->page($page, $pageSize)
        ->select()
        ->toArray();

    foreach ($list as &$v) {
        $v['tags'] = json_decode($v['tags'], true) ?: [];
        $v['play'] = (int)$v['play_count']; // 统一字段名
        unset($v['play_count']);
    }
    unset($v);

    return json([
        'code' => 0,
        'msg' => 'ok',
        'data' => [
            'list' => $list,
            'total' => $total,
            'current_page' => $page,
            'total_pages' => ceil($total / $pageSize),
            'per_page' => $pageSize
        ]
    ]);
}

/**
 * H5专用：获取单个视频详情（支持 type+id，兼容长视频/暗网视频）
 * GET /api/h5/video/detail?type=long&id=8
 * 兼容 /api/h5/long_videos/:id 旧路由
 */
public function h5Detail(Request $request, $id = 0)
{
    // 兼容新老参数
    $type   = $request->get('type/s', 'long');
    $id     = $request->get('id/d', $id); // 优先 query，其次路由
    $userId = $request->get('userId', '');
    if (!$id) return errorJson('视频ID参数无效或视频不存在');

    // 调试日志
    file_put_contents(
        runtime_path() . 'h5detail_debug.log',
        date('Y-m-d H:i:s') . " === h5Detail === type={$type} id={$id} userId={$userId}\n",
        FILE_APPEND
    );

    if ($type === 'darknet') {
        // ===== 暗网 =====
        $info = Db::name('darknet_video')->where('id', $id)->find();
        if (!$info) return errorJson('视频不存在');

        // tags 兼容处理
        $rawTags = $info['tags'];
        if (is_string($rawTags)) {
            $tags = json_decode($rawTags, true);
            if (!is_array($tags)) $tags = [$rawTags];
        } elseif (is_numeric($rawTags)) {
            $tags = [strval($rawTags)];
        } elseif (is_array($rawTags)) {
            $tags = $rawTags;
        } else {
            $tags = [];
        }

        // 用户真实行为（不走埋点）
        $userLikeCount = Db::name('user_actions')
            ->where('content_id', $id)->where('content_type', 'darknet')
            ->where('action_type', 'like')->count('DISTINCT user_id');
        $userCollectCount = Db::name('user_actions')
            ->where('content_id', $id)->where('content_type', 'darknet')
            ->where('action_type', 'collect')->count('DISTINCT user_id');

        $result = [
            'id'               => $info['id'],
            'title'            => $info['title'],
            'cover_url'        => $info['cover'],
            'duration'         => '',
            'preview_duration' => $info['preview'],
            'vip'              => (bool)$info['is_vip'],
            'coin'             => (int)($info['gold'] ?? 0),
            'goldCoins'        => (int)($info['gold'] ?? 0),
            'upload_time'      => $info['upload_time'],
            'tags'             => $tags,
            'play'             => (int)($info['play'] ?? 0),
            'collect'          => (int)($info['collect'] ?? 0) + $userCollectCount,
            'like'             => (int)($info['like'] ?? 0) + $userLikeCount,
            'status'           => (int)$info['status'],
            'parent_id'        => $info['parent_id'] ?? '',
            'category_id'      => $info['category_id'] ?? '',
            'type'             => 'darknet',
            // 不返回 url
        ];

        // 解锁状态
        $result['unlocked'] = false;
        if ($userId) {
            $unlock = Db::name('user_video_unlock')
                ->where('user_id', $userId)->where('video_id', $id)->find();
            if ($unlock && (!isset($unlock['expire_time']) || strtotime($unlock['expire_time']) > time())) {
                $result['unlocked'] = true;
            }
        }

        return successJson($result, '获取成功');

    } elseif ($type === 'anime') {
        // ===== 动漫 =====
        $info = Db::name('anime_videos')->where('id', $id)->find();
        if (!$info) return errorJson('动漫不存在');

        // tags: 支持 JSON/逗号分隔/单值
        $rawTags = $info['tags'];
        if ($rawTags && (substr($rawTags, 0, 1) === '[' || substr($rawTags, 0, 1) === '{')) {
            $tags = json_decode($rawTags, true);
            if (!is_array($tags)) $tags = [$rawTags];
        } else {
            $tags = is_string($rawTags) ? explode(',', $rawTags) : [];
            $tags = array_filter($tags, fn($t) => $t !== '');
        }
        // 映射成标签名
        $tagMap = Db::name('anime_tags')->column('name', 'id');
        $tagNames = [];
        foreach ($tags as $tagId) {
            $tid = intval($tagId);
            if (isset($tagMap[$tid])) $tagNames[] = $tagMap[$tid];
        }
        $tags = $tagNames;

        // 用户真实行为
        $userLikeCount = Db::name('user_actions')
            ->where('content_id', $id)->where('content_type', 'anime')
            ->where('action_type', 'like')->count('DISTINCT user_id');
        $userCollectCount = Db::name('user_actions')
            ->where('content_id', $id)->where('content_type', 'anime')
            ->where('action_type', 'collect')->count('DISTINCT user_id');

        $result = [
            'id'               => $info['id'],
            'title'            => $info['title'],
            'cover_url'        => $info['cover'],
            'duration'         => $info['duration'] ?? '',
            'preview_duration' => $info['preview_duration'] ?? '',
            'vip'              => (bool)$info['is_vip'],
            'coin'             => (int)($info['coin'] ?? 0),
            'goldCoins'        => (int)($info['coin'] ?? 0),
            'upload_time'      => $info['publish_time'] ?? '',
            'tags'             => $tags,
            'play'             => (int)($info['views'] ?? 0),
            'collect'          => (int)($info['collects'] ?? 0) + $userCollectCount,
            'like'             => (int)($info['likes'] ?? 0) + $userLikeCount,
            'status'           => (int)($info['status'] ?? 1),
            'parent_id'        => $info['parent_id'] ?? '',
            'category_id'      => $info['category_id'] ?? '',
            'type'             => 'anime',
            // 不返回 url
        ];

        $result['unlocked'] = false;
        if ($userId) {
            $unlock = Db::name('user_video_unlock')
                ->where('user_id', $userId)->where('video_id', $id)->find();
            if ($unlock && (!isset($unlock['expire_time']) || strtotime($unlock['expire_time']) > time())) {
                $result['unlocked'] = true;
            }
        }

        return successJson($result, '获取成功');

    } elseif ($type === 'star') {
        // ===== only圈（只支持视频）=====
        $media = Db::name('onlyfans_media')->where('id', $id)->where('status', 1)->find();
        if (!$media) return errorJson('内容不存在');

        // 仅允许视频
        if (strtolower((string)($media['type'] ?? '')) !== 'video') {
            return errorJson('仅支持视频内容', 1);
        }

        // 取作者分类
        $creator = Db::name('onlyfans_creators')
            ->where('id', $media['creator_id'] ?? 0)
            ->where('status', 1)
            ->field('id, category_id')
            ->find();

        // tag_ids -> tag 名称数组
        $split = function (?string $s): array {
            $s = (string)$s;
            if ($s === '') return [];
            $s = str_replace(['，','、',' '], ',', $s);
            preg_match_all('/\d+/', $s, $m);
            $ids = array_map('intval', $m[0] ?? []);
            $seen = []; $res = [];
            foreach ($ids as $tid) {
                if (!isset($seen[$tid])) { $seen[$tid] = 1; $res[] = $tid; }
            }
            return $res;
        };
        $tagIds = $split($media['tag_ids'] ?? '');
        $tagMap = empty($tagIds) ? [] :
            Db::name('onlyfans_tags')->whereIn('id', $tagIds)->column('name','id');
        $tags = [];
        foreach ($tagIds as $tid) {
            $tags[] = $tagMap[$tid] ?? ('#'.$tid);
        }

        // 输出（不返回 images，media_type 固定 video）
        $result = [
            'id'               => (int)$media['id'],
            'title'            => (string)($media['title'] ?? ''),
            'cover_url'        => (string)($media['cover'] ?? ''), // 这里不做补全，按表里存的给
            'duration'         => (string)($media['duration'] ?? ''), // 没有则空
            'preview_duration' => '',
            'vip'              => (bool)($media['is_vip'] ?? 0),
            'coin'             => (int)($media['coin'] ?? 0),
            'goldCoins'        => (int)($media['coin'] ?? 0),
            'upload_time'      => (string)($media['create_time'] ?? ''),
            'tags'             => $tags,
            'play'             => (int)($media['view_count'] ?? 0),
            'collect'          => (int)($media['favorite_count'] ?? 0),
            'like'             => (int)($media['like_count'] ?? 0),
            'status'           => (int)($media['status'] ?? 1),
            'parent_id'        => 0,
            'category_id'      => (int)($creator['category_id'] ?? 0),
            'type'             => 'star',
            'media_type'       => 'video', // ✅ 固定 video
        ];

        // 解锁状态（沿用同表）
        $result['unlocked'] = false;
        if ($userId) {
            $unlock = Db::name('user_video_unlock')
                ->where('user_id', $userId)->where('video_id', $id)->find();
            if ($unlock && (!isset($unlock['expire_time']) || strtotime($unlock['expire_time']) > time())) {
                $result['unlocked'] = true;
            }
        }

        return successJson($result, '获取成功');

    } else {
        // ===== 长视频 long =====
        $info = Db::name('long_videos')->where('id', $id)->find();
        if (!$info) return errorJson('视频不存在');

        $rawTags = $info['tags'];
        if (is_string($rawTags)) {
            $tags = json_decode($rawTags, true);
            if (!is_array($tags)) $tags = [$rawTags];
        } elseif (is_numeric($rawTags)) {
            $tags = [strval($rawTags)];
        } elseif (is_array($rawTags)) {
            $tags = $rawTags;
        } else {
            $tags = [];
        }

        // 用户真实行为（不走埋点）
        $userLikeCount = Db::name('user_actions')
            ->where('content_id', $id)->where('content_type', 'long_video')
            ->where('action_type', 'like')->count('DISTINCT user_id');
        $userCollectCount = Db::name('user_actions')
            ->where('content_id', $id)->where('content_type', 'long_video')
            ->where('action_type', 'collect')->count('DISTINCT user_id');

        $result = [
            'id'               => $info['id'],
            'title'            => $info['title'],
            'cover_url'        => $info['cover_url'],
            'duration'         => $info['duration'],
            'preview_duration' => $info['preview_duration'],
            'vip'              => (bool)$info['is_vip'],
            'coin'             => (int)($info['gold_required'] ?? 0),
            'goldCoins'        => (int)($info['gold_required'] ?? 0),
            'upload_time'      => $info['publish_time'],
            'tags'             => $tags,
            'play'             => (int)$info['play_count'],
            'collect'          => (int)$info['collect_count'] + $userCollectCount,
            'like'             => (int)($info['like_count'] ?? 0) + $userLikeCount,
            'status'           => (int)$info['status'],
            'parent_id'        => $info['parent_id'] ?? '',
            'category_id'      => $info['category_id'] ?? '',
            'type'             => 'long',
            // 不返回 url
        ];

        $result['unlocked'] = false;
        if ($userId) {
            $unlock = Db::name('user_video_unlock')
                ->where('user_id', $userId)->where('video_id', $id)->find();
            if ($unlock && (!isset($unlock['expire_time']) || strtotime($unlock['expire_time']) > time())) {
                $result['unlocked'] = true;
            }
        }

        return successJson($result, '获取成功');
    }
}

}