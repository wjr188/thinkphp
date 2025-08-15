<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;

class UserBrowseLogController
{
    // GET /api/user/browse/logs
    public function logs(Request $request)
    {
        // 顶部日志，记录每次进来
        file_put_contents(runtime_path() . 'browselog.txt', date('c') . " logs run: " . json_encode($request->get()) . "\n", FILE_APPEND);

        $params = $request->get();
        $page = intval($params['page'] ?? 1);
        $pageSize = intval($params['page_size'] ?? 10);

        $query = Db::name('user_browse_logs')->alias('l');

        if (!empty($params['type'])) {
            $query->where('l.type', $params['type']);
        }
        if (!empty($params['user_uuid'])) {
            $query->where('l.user_uuid', 'like', '%'.$params['user_uuid'].'%');
        }
        if (!empty($params['category_id'])) {
            $query->where('c.id', $params['category_id']);
        }
        if (!empty($params['keyword'])) {
            $query->where('content_title', 'like', '%'.$params['keyword'].'%');
        }
        if (!empty($params['start_time']) && !empty($params['end_time'])) {
            $query->whereBetween('browse_time', [$params['start_time'], $params['end_time']]);
        }

        // 动态 join 内容表&分类表
        $type = (!empty($params['type'])) ? $params['type'] : 'long_video';

        // debug输出
        file_put_contents(runtime_path() . 'type_debug.log', date('c') . " type=" . ($type ?? 'null') . " " . json_encode($params) . "\n", FILE_APPEND);

        $contentJoin = [
            'text_novel' => ['text_novel', 'id'],           // 文字小说
            'audio_novel' => ['audio_novel', 'id'],         // 有声小说
            'long_video' => ['long_videos', 'id'],          // 长视频
            'darknet' => ['darknet_video', 'id'],           // 暗网
            'anime' => ['anime_videos', 'id'],              // 动漫
            'comic' => ['comics', 'id'],                    // 漫画
            'influencer' => ['influencer', 'id'],           // 博主
        ];
        $categoryJoin = [
            'text_novel' => ['text_novel_category', 'category_id'],      // 文字小说分类
            'audio_novel' => ['audio_novel_category', 'category_id'],    // 有声小说分类
            'long_video' => ['long_video_categories', 'category_id'],    // 长视频分类
            'darknet' => ['darknet_category', 'category_id'],            // 暗网分类
            'anime' => ['anime_categories', 'category_id'],              // 动漫分类
            'comic' => ['comic_categories', 'category_id'],              // 漫画分类
            'influencer' => ['influencer_group', 'category_id'],         // 博主分组
        ];

        if (!isset($contentJoin[$type]) || !isset($categoryJoin[$type])) {
            file_put_contents(runtime_path() . 'browselog.txt', date('c') . " type error: $type\n", FILE_APPEND);
            return json(['code' => 1, 'msg' => '不支持的内容类型: ' . $type], 400);
        }

        // 只有此时再解包，绝不会出错！
        [$contentTable, $contentKey] = $contentJoin[$type];
        [$categoryTable, $categoryKey] = $categoryJoin[$type];

        $query->leftJoin($contentTable.' v', 'l.content_id = v.id');
        $query->leftJoin($categoryTable.' c', 'v.category_id = c.id');
        $query->field('l.*, v.title as content_title, c.name as category_name');

        $total = $query->count();
        $list = $query->order('l.browse_time', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // extra 字段 json_decode
        foreach ($list as &$row) {
            if (isset($row['extra'])) {
                $row['extra'] = json_decode($row['extra'], true);
            }
        }

        return json([
            'code' => 0,
            'data' => [
                'list' => $list,
                'total' => $total,
            ]
        ]);
    }

    // 获取内容类型下拉
    public function typeList()
    {
        // 查询 user_browse_logs 表中实际存在的 type
        $types = Db::name('user_browse_logs')->distinct(true)->column('type');
        // 类型label映射
        $labels = [
            'text_novel' => '文字小说',
            'audio_novel' => '有声小说',
            'long_video' => '长视频',
            'darknet' => '暗网',
            'anime' => '动漫',
            'comic' => '漫画',
            'influencer' => '博主',
        ];
        $result = [];
        foreach ($types as $type) {
            $result[] = [
                'label' => $labels[$type] ?? $type,
                'value' => $type
            ];
        }
        return json($result);
    }

    // 获取分类列表
    public function categoryList(Request $request)
    {
        $type = $request->get('type', '');
        $tableMap = [
            'text_novel' => 'text_novel_category',          // 文字小说分类
            'audio_novel' => 'audio_novel_category',        // 有声小说分类
            'long_video' => 'long_video_categories',        // 长视频分类
            'darknet' => 'darknet_category',                // 暗网分类
            'anime' => 'anime_categories',                  // 动漫分类
            'comic' => 'comic_categories',                  // 漫画分类
            'influencer' => 'influencer_group',             // 博主分组
        ];
        if (empty($tableMap[$type])) return json([]);
        // 只查二级分类（parent_id > 0）
        $list = Db::name($tableMap[$type])
            ->where('parent_id', '>', 0)
            ->field('id,name')
            ->select()
            ->toArray();
        return json($list);
    }

    // H5前端专用：获取用户浏览记录 - 完全仿照收藏接口格式
    public function h5List(Request $request)
    {
        $params = $request->get();
        $page = intval($params['page'] ?? 1);
        $limit = intval($params['limit'] ?? 20); // 与收藏接口保持一致
        $type = $params['type'] ?? ''; // 内容类型筛选

        // H5端按用户UUID筛选（必填）
        $userUuid = $params['user_uuid'] ?? '';
        if (empty($userUuid)) {
            return json(['code' => 1, 'msg' => '用户UUID不能为空'], 400);
        }

        if ($page < 1) $page = 1;
        if ($limit < 1 || $limit > 50) $limit = 20;

        try {
            // 直接使用 JOIN 查询，一次性获取所有数据，完全仿照收藏接口
            $result = [];
            $total = 0;
            
            if (empty($type)) {
                // 查询所有类型的浏览记录，与收藏接口保持一致
                $allResults = [];
                $allTotal = 0;
                
                // long_video 类型
                $longQuery = Db::name('user_browse_logs')
                    ->alias('ubl')
                    ->join('long_videos lv', 'ubl.content_id = lv.id')
                    ->where('ubl.user_uuid', $userUuid)
                    ->where('ubl.type', 'long_video');
                    
                $longTotal = $longQuery->count();
                $longResults = $longQuery
                    ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                            lv.title, lv.cover_url as cover, lv.duration')
                    ->order('ubl.browse_time', 'desc')
                    ->select();
                
                // darknet 类型
                $darknetQuery = Db::name('user_browse_logs')
                    ->alias('ubl')
                    ->join('darknet_video dv', 'ubl.content_id = dv.id')
                    ->where('ubl.user_uuid', $userUuid)
                    ->where('ubl.type', 'darknet');
                    
                $darknetTotal = $darknetQuery->count();
                $darknetResults = $darknetQuery
                    ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                            dv.title, dv.cover, dv.duration')
                    ->order('ubl.browse_time', 'desc')
                    ->select();
                
                // anime 类型
                $animeQuery = Db::name('user_browse_logs')
                    ->alias('ubl')
                    ->join('anime_videos av', 'ubl.content_id = av.id')
                    ->where('ubl.user_uuid', $userUuid)
                    ->where('ubl.type', 'anime');
                    
                $animeTotal = $animeQuery->count();
                $animeResults = $animeQuery
                    ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                            av.title, av.cover, av.duration')
                    ->order('ubl.browse_time', 'desc')
                    ->select();
                
                // douyin 类型（保留，不并入视频聚合里）
                $douyinQuery = Db::name('user_browse_logs')
                    ->alias('ubl')
                    ->join('douyin_videos dv', 'ubl.content_id = dv.id')
                    ->where('ubl.user_uuid', $userUuid)
                    ->where('ubl.type', 'douyin');
                    
                $douyinTotal = $douyinQuery->count();
                $douyinResults = $douyinQuery
                    ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                            dv.title, dv.cover, dv.duration')
                    ->order('ubl.browse_time', 'desc')
                    ->select();
                
                // 只合并视频类型结果（long_video, darknet, anime）
                $allResults = array_merge(
                    $longResults ? $longResults->toArray() : [],
                    $darknetResults ? $darknetResults->toArray() : [],
                    $animeResults ? $animeResults->toArray() : []
                );
                
                $total = $longTotal + $darknetTotal + $animeTotal;
                
                // 按时间排序
                usort($allResults, function($a, $b) {
                    return strtotime($b['created_at']) - strtotime($a['created_at']);
                });
                
                // 分页
                $offset = ($page - 1) * $limit;
                $allResults = array_slice($allResults, $offset, $limit);
                
            } else {
                // 查询指定类型
                switch ($type) {
                    case 'long_video':
                        $query = Db::name('user_browse_logs')
                            ->alias('ubl')
                            ->join('long_videos lv', 'ubl.content_id = lv.id')
                            ->where('ubl.user_uuid', $userUuid)
                            ->where('ubl.type', 'long_video');
                        $total = $query->count();
                        $allResults = $query
                            ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                                    lv.title, lv.cover_url as cover, lv.duration')
                            ->order('ubl.browse_time', 'desc')
                            ->limit(($page - 1) * $limit, $limit)
                            ->select()
                            ->toArray();
                        break;
                        
                    case 'darknet':
                        $query = Db::name('user_browse_logs')
                            ->alias('ubl')
                            ->join('darknet_video dv', 'ubl.content_id = dv.id')
                            ->where('ubl.user_uuid', $userUuid)
                            ->where('ubl.type', 'darknet');
                        $total = $query->count();
                        $allResults = $query
                            ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                                    dv.title, dv.cover, dv.duration')
                            ->order('ubl.browse_time', 'desc')
                            ->limit(($page - 1) * $limit, $limit)
                            ->select()
                            ->toArray();
                        break;
                        
                    case 'comic':
                        $query = Db::name('user_browse_logs')
                            ->alias('ubl')
                            ->join('comic_manga cm', 'ubl.content_id = cm.id')
                            ->where('ubl.user_uuid', $userUuid)
                            ->where('ubl.type', 'comic');
                        $total = $query->count();
                        $allResults = $query
                            ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                                    cm.name as title, cm.cover, cm.chapter_count, cm.likes')
                            ->order('ubl.browse_time', 'desc')
                            ->limit(($page - 1) * $limit, $limit)
                            ->select()
                            ->toArray();
                        break;
                        
                    case 'anime':
                        $query = Db::name('user_browse_logs')
                            ->alias('ubl')
                            ->join('anime_videos av', 'ubl.content_id = av.id')
                            ->where('ubl.user_uuid', $userUuid)
                            ->where('ubl.type', 'anime');
                        $total = $query->count();
                        $allResults = $query
                            ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                                    av.title, av.cover, av.duration')
                            ->order('ubl.browse_time', 'desc')
                            ->limit(($page - 1) * $limit, $limit)
                            ->select()
                            ->toArray();
                        break;
                        
                    case 'novel':
                        $query = Db::name('user_browse_logs')
                            ->alias('ubl')
                            ->join('text_novel tn', 'ubl.content_id = tn.id')
                            ->where('ubl.user_uuid', $userUuid)
                            ->where('ubl.type', 'novel');
                        $total = $query->count();
                        $allResults = $query
                            ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                                    tn.title, tn.cover_url as cover, tn.chapter_count, tn.likes')
                            ->order('ubl.browse_time', 'desc')
                            ->limit(($page - 1) * $limit, $limit)
                            ->select()
                            ->toArray();
                        break;
                        
                    case 'douyin':
                        $query = Db::name('user_browse_logs')
                            ->alias('ubl')
                            ->join('douyin_videos dv', 'ubl.content_id = dv.id')
                            ->where('ubl.user_uuid', $userUuid)
                            ->where('ubl.type', 'douyin');
                        $total = $query->count();
                        $allResults = $query
                            ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                                    dv.title, dv.cover, dv.duration')
                            ->order('ubl.browse_time', 'desc')
                            ->limit(($page - 1) * $limit, $limit)
                            ->select()
                            ->toArray();
                        break;
                        
                    case 'audio':
                        $query = Db::name('user_browse_logs')
                            ->alias('ubl')
                            ->join('audio_novels an', 'ubl.content_id = an.id')
                            ->where('ubl.user_uuid', $userUuid)
                            ->where('ubl.type', 'audio');
                        $total = $query->count();
                        $allResults = $query
                            ->field('ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                                    an.title, an.cover_url as cover, an.chapter_count')
                            ->order('ubl.browse_time', 'desc')
                            ->limit(($page - 1) * $limit, $limit)
                            ->select()
                            ->toArray();
                        break;
                        
                    case 'star':
                        $query = Db::name('user_browse_logs')
                            ->alias('ubl')
                            // 修复表名：改为 onlyfans_media，并命名为 ofm
                            ->join('onlyfans_media ofm', 'ubl.content_id = ofm.id')
                            ->where('ubl.user_uuid', $userUuid)
                            ->where('ubl.type', 'star')
                            // 只取视频类型的数据
                            ->where('ofm.type', 'video');
                        $total = $query->count();
                        $allResults = $query
                            ->field("ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                                    ofm.title, ofm.cover, ofm.duration, ofm.like_count as likes")
                            ->order('ubl.browse_time', 'desc')
                            ->limit(($page - 1) * $limit, $limit)
                            ->select()
                            ->toArray();
                        break;
                        
                    case 'star_image':
                        $query = Db::name('user_browse_logs')
                            ->alias('ubl')
                            // 连接 onlyfans_media 表获取图片信息
                            ->join('onlyfans_media om', 'ubl.content_id = om.id')
                            ->where('ubl.user_uuid', $userUuid)
                            ->where('ubl.type', 'star_image')
                            ->where('om.type', 'image'); // 只取图片类型
                        $total = $query->count();
                        $allResults = $query
                            ->field("ubl.id, ubl.content_id, ubl.type as content_type, ubl.browse_time as created_at, 
                                    om.title, om.cover")
                            ->order('ubl.browse_time', 'desc')
                            ->limit(($page - 1) * $limit, $limit)
                            ->select()
                            ->toArray();
                        break;
                        
                    default:
                        $allResults = [];
                        $total = 0;
                }
            }
            
            // === NEW: 统一格式化 & 封面 URL 补全 ===
            foreach ($allResults as $item) {
                // 兼容 cover_url/cover 两种字段
                $rawCover = $item['cover'] ?? ($item['cover_url'] ?? '');
                $cover    = $this->absUrl($rawCover); // NEW

                $formattedItem = [
                    'id' => $item['id'],
                    'content_id' => $item['content_id'],
                    'content_type' => $item['content_type'],
                    'action_type' => 'browse', // 浏览记录标识
                    'created_at' => $item['created_at'], // 浏览时间
                ];

                // 根据内容类型格式化不同的数据结构
                if ($item['content_type'] === 'comic') {
                    $formattedItem['comic'] = [
                        'id' => $item['content_id'],
                        'title' => $item['title'] ?: '未知标题',
                        'cover' => $cover,                    // NEW
                        'chapter_count' => $item['chapter_count'] ?? 0,
                        'likes' => $item['likes'] ?? 0
                    ];
                } elseif ($item['content_type'] === 'novel') {
                    $formattedItem['novel'] = [
                        'id' => $item['content_id'],
                        'title' => $item['title'] ?: '未知标题',
                        'cover' => $cover,                    // NEW
                        'chapter_count' => $item['chapter_count'] ?? 0,
                        'likes' => $item['like_count'] ?? 0
                    ];
                } elseif ($item['content_type'] === 'audio') {
                    $formattedItem['audio'] = [
                        'id' => $item['content_id'],
                        'title' => $item['title'] ?: '未知标题',
                        'cover' => $cover,                    // NEW
                        'chapter_count' => $item['chapter_count'] ?? 0
                    ];
                } elseif ($item['content_type'] === 'star') {
                    $formattedItem['video'] = [
                        'id' => $item['content_id'],
                        'title' => $item['title'] ?: '未知标题',
                        'cover' => $cover,                    // NEW
                        'duration' => $this->formatDuration($item['duration'] ?? 0),
                        'likes' => $item['likes'] ?? 0
                    ];
                } elseif ($item['content_type'] === 'star_image') {
                    $formattedItem['image'] = [
                        'id' => $item['content_id'],
                        'title' => $item['title'] ?: '未知标题',
                        'cover' => $cover,                    // NEW
                        'url' => $cover                       // NEW
                    ];
                } else {
                    // 视频类型数据（long_video/darknet/anime/douyin…）
                    $formattedItem['video'] = [
                        'id' => $item['content_id'],
                        'title' => $item['title'] ?: '未知标题',
                        'cover' => $cover,                    // NEW
                        'duration' => $this->formatDuration($item['duration'] ?? 0),
                        'likes' => $item['likes'] ?? 0
                    ];
                }
                
                $result[] = $formattedItem;
            }

            return json([
                'code' => 0,
                'msg' => 'ok',
                'data' => [
                    'list' => $result,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'has_more' => ($page * $limit) < $total
                ]
            ]);

        } catch (\Exception $e) {
            // 记录错误日志
            file_put_contents(runtime_path() . 'browselog.txt', 
                date('c') . " h5List查询失败: " . $e->getMessage() . "\n", FILE_APPEND);
            return json(['code' => 1, 'msg' => '获取浏览记录失败：' . $e->getMessage()], 500);
        }
    }

    // 新增浏览记录
    public function add(Request $request)
    {
        $data = $request->post();
        $data['browse_time'] = date('Y-m-d H:i:s'); // 可选，记录时间
        Db::name('user_browse_logs')->insert($data);
        return json(['code' => 0, 'msg' => 'ok']);
    }
    
    // 删除浏览记录
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (empty($id)) {
            return json(['code' => 1, 'msg' => '记录ID不能为空'], 400);
        }
        
        try {
            $result = Db::name('user_browse_logs')->where('id', $id)->delete();
            if ($result > 0) {
                return json(['code' => 0, 'msg' => '删除成功']);
            } else {
                return json(['code' => 1, 'msg' => '记录不存在或已删除'], 404);
            }
        } catch (\Exception $e) {
            // 记录错误日志
            file_put_contents(runtime_path() . 'browselog.txt', 
                date('c') . " delete失败: " . $e->getMessage() . "\n", FILE_APPEND);
            return json(['code' => 1, 'msg' => '删除失败：' . $e->getMessage()], 500);
        }
    }

    // === NEW: 统一把相对路径补成绝对 URL（优先读取 .env 的 APP_URL） ===
    private function absUrl(?string $path): string
    {
        if (empty($path)) return '';
        if (preg_match('#^https?://#i', $path)) return $path;

        $base = rtrim(env('APP_URL', ''), '/');
        if ($base === '') {
            // 没配置 APP_URL 就用请求域名
            $scheme = request()->isSsl() ? 'https' : 'http';
            $host   = request()->host();
            $base   = $scheme . '://' . $host;
        }
        return $base . '/' . ltrim($path, '/');
    }
    
    /**
     * 格式化时长 - 仿照收藏接口
     */
    private function formatDuration($duration): string
    {
        if (empty($duration)) return '00:00';
        
        if (is_numeric($duration)) {
            $minutes = floor($duration / 60);
            $seconds = $duration % 60;
            return sprintf('%02d:%02d', $minutes, $seconds);
        }
        
        return (string)$duration;
    }
}
