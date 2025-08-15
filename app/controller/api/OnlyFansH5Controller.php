<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;

class OnlyFansH5Controller
{
    /**
     * H5前台获取分类列表
     * GET /api/h5/onlyfans/categories
     */
    public function categories(Request $request)
    {
        try {
            $categories = Db::name('onlyfans_categories')
                ->where('status', 1)
                ->field('id, name, intro, icon, sort')
                ->order('sort asc, id asc')
                ->select()
                ->toArray();

            if (empty($categories)) {
                return json(['code' => 0, 'msg' => 'success', 'data' => []]);
            }

            // 统计每个分类下启用博主数量
            $counts = Db::name('onlyfans_creators')
                ->where('status', 1)
                ->group('category_id')
                ->column('COUNT(*) as cnt', 'category_id');

            foreach ($categories as &$c) {
                $c['icon'] = $this->fullUrl($c['icon'], $this->fullUrl('/static/images/default-category.png'));
                $c['creator_count'] = (int)($counts[$c['id']] ?? 0);
            }
            unset($c);

            return json(['code' => 0, 'msg' => 'success', 'data' => $categories]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取分类列表失败：' . $e->getMessage()]);
        }
    }

    /**
     * H5前台获取指定分类下的博主列表
     * GET /api/h5/onlyfans/creators/:categoryId
     */
    public function creators(Request $request)
    {
        $categoryId = $request->param('categoryId');
        // 默认第1页、每页15，强制上限15
        [$page, $pageSize] = $this->getPaging($request, 1, 15, 15);
        $keyword = $request->param('keyword', '');

        if (!$categoryId) {
            return json(['code' => 1, 'msg' => '分类ID不能为空']);
        }

        try {
            $where = [
                ['category_id', '=', $categoryId],
                ['status', '=', 1]
            ];
            if ($keyword !== '') {
                $where[] = ['name', 'like', '%' . $keyword . '%'];
            }

            $query = Db::name('onlyfans_creators')->where($where);
            $total = (clone $query)->count();

            $creators = $query
                ->field('id, name, avatar')
                ->order('sort desc, create_time desc, id desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            foreach ($creators as &$creator) {
                $creator['avatar'] = $this->fullUrl($creator['avatar'] ?? '', '/static/images/default-avatar.png');
            }
            unset($creator);

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'list' => $creators,
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'has_more' => ($page * $pageSize) < $total
                ]
            ]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取博主列表失败：' . $e->getMessage()]);
        }
    }

    /**
     * H5前台获取博主详情及其内容
     * GET /api/h5/onlyfans/creator/:id
     */
    public function creatorDetail(Request $request)
    {
        $creatorId   = $request->param('id');
        $contentType = $request->param('content_type', 'all'); // all, image, video
        // 默认第1页、每页15，强制上限15
        [$page, $pageSize] = $this->getPaging($request, 1, 15, 15);

        if (!$creatorId) {
            return json(['code' => 1, 'msg' => '博主ID不能为空']);
        }

        try {
            $creator = Db::name('onlyfans_creators')
                ->where('id', $creatorId)
                ->where('status', 1)
                ->find();

            if (!$creator) {
                return json(['code' => 1, 'msg' => '博主不存在或已下架']);
            }

            $creator['avatar'] = $this->fullUrl($creator['avatar'] ?? '', '/static/images/default-avatar.png');

            $category = Db::name('onlyfans_categories')
                ->where('id', $creator['category_id'])
                ->field('id, name')
                ->find();
            $creator['category_name'] = $category['name'] ?? '未分类';

            $mediaWhere = [
                ['creator_id', '=', $creatorId],
                ['status', '=', 1]
            ];
            if ($contentType === 'image') {
                $mediaWhere[] = ['type', '=', 'image'];
            } elseif ($contentType === 'video') {
                $mediaWhere[] = ['type', '=', 'video'];
            }

            $mediaQuery = Db::name('onlyfans_media')->where($mediaWhere);
            $total = (clone $mediaQuery)->count();

            $mediaList = $mediaQuery
                ->field('id, title, cover, type, is_vip, coin, view_count, like_count, favorite_count, create_time, duration')
                ->order('create_time desc, id desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            foreach ($mediaList as &$media) {
                $media['cover'] = $this->fullUrl($media['cover'] ?? '', '/static/images/default-cover.png');
                $media['vip'] = (bool)($media['is_vip'] ?? 0);
                $media['view_count']     = (int)($media['view_count'] ?? 0);
                $media['like_count']     = (int)($media['like_count'] ?? 0);
                $media['favorite_count'] = (int)($media['favorite_count'] ?? 0);
                if (($media['type'] ?? '') === 'video') {
        $media['duration'] = (int)($media['duration'] ?? 0);
    }
                unset($media['is_vip']);
            }
            unset($media);

            $stats = [
                'total_media' => Db::name('onlyfans_media')->where('creator_id', $creatorId)->where('status', 1)->count(),
                'image_count' => Db::name('onlyfans_media')->where('creator_id', $creatorId)->where('type', 'image')->where('status', 1)->count(),
                'video_count' => Db::name('onlyfans_media')->where('creator_id', $creatorId)->where('type', 'video')->where('status', 1)->count(),
            ];

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'creator' => [
                        'id' => $creator['id'],
                        'name' => $creator['name'],
                        'avatar' => $creator['avatar'],
                        'intro' => $creator['intro'] ?? '',
                        'media_count' => (int)($creator['media_count'] ?? 0),
                        'fans_count' => (int)($creator['fans_count'] ?? 0),
                        'category_name' => $creator['category_name']
                    ],
                    'stats' => $stats,
                    'media_list' => $mediaList,
                    'total' => $total,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'has_more' => ($page * $pageSize) < $total
                ]
            ]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取博主详情失败：' . $e->getMessage()]);
        }
    }

    /**
     * H5前台获取媒体内容详情
     * GET /api/h5/onlyfans/media/:id
     */
    public function mediaDetail(Request $request)
{
    $mediaId = $request->param('id');
    if (!$mediaId) {
        return json(['code' => 1, 'msg' => '内容ID不能为空']);
    }

    try {
        // 1) 基础数据
        $media = Db::name('onlyfans_media')
            ->where('id', $mediaId)
            ->where('status', 1)
            ->find();
        if (!$media) {
            return json(['code' => 1, 'msg' => '内容不存在或已下架']);
        }

        $creator = Db::name('onlyfans_creators')
            ->where('id', $media['creator_id'])
            ->where('status', 1)
            ->field('id, name, avatar, category_id')
            ->find();
        if (!$creator) {
            return json(['code' => 1, 'msg' => '博主不存在或已下架']);
        }

        $category = Db::name('onlyfans_categories')
            ->where('id', $creator['category_id'])
            ->field('id, name')
            ->find();

        // 2) 归一化与清洗
        $media['cover']           = $this->fullUrl($media['cover'], $this->fullUrl('/static/images/default-cover.png'));
        $creator['avatar']        = $this->fullUrl($creator['avatar'], $this->fullUrl('/static/images/default-avatar.png'));
        $media['vip']             = (bool)$media['is_vip'];
        $media['coin']            = (int)($media['coin'] ?? 0);
        $media['view_count']      = (int)($media['view_count'] ?? 0);
        $media['like_count']      = (int)($media['like_count'] ?? 0);
        $media['favorite_count']  = (int)($media['favorite_count'] ?? 0);
        unset($media['is_vip']); // 不暴露原字段
        // 不在此接口返回整组 images，改为分页接口 /api/h5/onlyfans/media/:id/images
        if (($media['type'] ?? '') === 'image') {
            // 仅返回总张数，便于前端展示
            $media['image_total'] = (int)Db::name('onlyfans_images')
                ->where('media_id', $mediaId)
                ->count();
        }
        if (($media['type'] ?? '') === 'video') {
            $media['video_url'] = $this->fullUrl($media['video_url'] ?? '', '');
            $media['duration']  = (int)($media['duration'] ?? 0);
            $media['file_size'] = (int)($media['file_size'] ?? 0);
        }

        // 3) tags 组装（由 tag_ids 转成 tags[]）
        $tagIdsStr = (string)($media['tag_ids'] ?? '');
        $ids = [];
        if ($tagIdsStr !== '') {
            $tagIdsStr = str_replace(['，','、',' '], ',', $tagIdsStr);
            preg_match_all('/\d+/', $tagIdsStr, $mm);
            $tmp = array_map('intval', $mm[0] ?? []);
            $seen = [];
            foreach ($tmp as $tid) {
                if (!isset($seen[$tid])) { $seen[$tid] = 1; $ids[] = $tid; }
            }
        }
        if (!empty($ids)) {
            $tagMap = Db::name('onlyfans_tags')->whereIn('id', $ids)->column('name','id');
            $media['tags'] = array_map(function($tid) use ($tagMap){
                return ['id' => $tid, 'name' => ($tagMap[$tid] ?? ('#'.$tid))];
            }, $ids);
        } else {
            $media['tags'] = [];
        }
        unset($media['tag_ids']); // 清理原始 tag_ids

        $media['creator']  = $creator;
        $media['category'] = $category;

        // 4) 统计 +1（放在最后，不影响返回）
        Db::name('onlyfans_media')->where('id', $mediaId)->inc('view_count', 1)->update();

        // 5) 推荐列表（同作者其它内容）
        $recommendList = Db::name('onlyfans_media')
            ->where('creator_id', $media['creator_id'])
            ->where('id', '<>', $mediaId)
            ->where('status', 1)
            ->field('id, title, cover, type, is_vip, coin, view_count, like_count, favorite_count')
            ->order('sort desc, create_time desc, id desc')
            ->limit(6)
            ->select()
            ->toArray();

        foreach ($recommendList as &$r) {
            $r['cover']           = $this->fullUrl($r['cover'], $this->fullUrl('/static/images/default-cover.png'));
            $r['vip']             = (bool)$r['is_vip'];
            $r['coin']            = (int)($r['coin'] ?? 0);
            $r['view_count']      = (int)($r['view_count'] ?? 0);
            $r['like_count']      = (int)($r['like_count'] ?? 0);
            $r['favorite_count']  = (int)($r['favorite_count'] ?? 0);
            unset($r['is_vip']);
        }
        unset($r);

        return json([
            'code' => 0,
            'msg'  => 'success',
            'data' => [
                'media'     => $media,
                'recommend' => $recommendList
            ]
        ]);
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '获取内容详情失败：' . $e->getMessage()]);
    }
}

    /**
     * H5前台搜索功能
     * GET /api/h5/onlyfans/search
     */
    public function search(Request $request)
    {
        $keyword = trim($request->param('keyword', ''));
        $type    = $request->param('type', 'all'); // all, creator, media
        // 默认第1页、每页15，强制上限15
        [$page, $pageSize] = $this->getPaging($request, 1, 15, 15);

        if ($keyword === '') {
            return json(['code' => 1, 'msg' => '请输入搜索关键词']);
        }

        try {
            $result = [];

            if ($type === 'all' || $type === 'creator') {
                $creatorQuery = Db::name('onlyfans_creators')
                    ->where('status', 1)
                    ->whereLike('name', "%{$keyword}%");

                $creatorTotal = (clone $creatorQuery)->count();

                $creators = $creatorQuery
                    ->field('id, name, avatar, intro, media_count, fans_count')
                    ->order('fans_count desc, media_count desc, id desc')
                    ->page($page, $pageSize)
                    ->select()
                    ->toArray();

                foreach ($creators as &$cr) {
                    $cr['avatar'] = $this->fullUrl($cr['avatar'], $this->fullUrl('/static/images/default-avatar.png'));
                    $cr['media_count'] = (int)($cr['media_count'] ?? 0);
                    $cr['fans_count']  = (int)($cr['fans_count'] ?? 0);
                }
                unset($cr);

                $result['creators'] = [
                    'list' => $creators,
                    'total' => $creatorTotal,
                    'has_more' => ($page * $pageSize) < $creatorTotal
                ];
            }

            if ($type === 'all' || $type === 'media') {
                $mediaQuery = Db::name('onlyfans_media')->alias('m')
                    ->leftJoin('onlyfans_creators c', 'm.creator_id = c.id')
                    ->where('m.status', 1)
                    ->where('c.status', 1)
                    ->whereLike('m.title', "%{$keyword}%");

                $mediaTotal = (clone $mediaQuery)->distinct(true)->count('m.id');

                $mediaList = $mediaQuery
                    ->field('m.id, m.title, m.cover, m.type, m.is_vip, m.coin, m.view_count, m.like_count, m.favorite_count, m.create_time, c.name as creator_name, c.avatar as creator_avatar, c.id as creator_id')
                    ->order('m.view_count desc, m.create_time desc, m.id desc')
                    ->page($page, $pageSize)
                    ->select()
                    ->toArray();

                foreach ($mediaList as &$m) {
                    $m['cover'] = $this->fullUrl($m['cover'], $this->fullUrl('/static/images/default-cover.png'));
                    $m['creator_avatar'] = $this->fullUrl($m['creator_avatar'], $this->fullUrl('/static/images/default-avatar.png'));
                    $m['vip'] = (bool)$m['is_vip'];
                    $m['view_count']     = (int)($m['view_count'] ?? 0);
                    $m['like_count']     = (int)($m['like_count'] ?? 0);
                    $m['favorite_count'] = (int)($m['favorite_count'] ?? 0);
                }
                unset($m);

                $result['media'] = [
                    'list' => $mediaList,
                    'total' => $mediaTotal,
                    'has_more' => ($page * $pageSize) < $mediaTotal
                ];
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'keyword' => $keyword,
                    'type' => $type,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'result' => $result
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '搜索失败：' . $e->getMessage()]);
        }
    }

    public function creatorProfile(Request $request)
    {
        $id = $request->param('id');
        if (!$id) return json(['code' => 1, 'msg' => '博主ID不能为空']);

        $creator = Db::name('onlyfans_creators')->where('id', $id)->where('status', 1)->find();
        if (!$creator) return json(['code' => 1, 'msg' => '博主不存在或已下架']);

        $creator['avatar'] = $this->fullUrl($creator['avatar'], '/static/images/default-avatar.png');

        $stats = [
            'total_media' => Db::name('onlyfans_media')->where('creator_id', $id)->where('status', 1)->count(),
            'image_count' => Db::name('onlyfans_media')->where('creator_id', $id)->where('type', 'image')->where('status', 1)->count(),
            'video_count' => Db::name('onlyfans_media')->where('creator_id', $id)->where('type', 'video')->where('status', 1)->count(),
        ];

        return json(['code' => 0, 'msg' => 'success', 'data' => compact('creator','stats')]);
    }

    /**
     * H5前台获取某博主的媒体（纯图片或纯视频）
     * GET /api/h5/onlyfans/creator/:id/media?type=image|video
     */
   public function creatorMedia(Request $request)
{
    // 默认第1页、每页15，强制上限15
    [$page, $pageSize] = $this->getPaging($request, 1, 15, 15);

    $id   = $request->param('id');
    $type = $request->param('type', 'image'); // image | video

    if (!$id) return json(['code' => 1, 'msg' => '博主ID不能为空']);
    if (!in_array($type, ['image','video'])) return json(['code' => 1, 'msg' => 'type错误']);

    $q = Db::name('onlyfans_media')
        ->where('creator_id', $id)
        ->where('status', 1)
        ->where('type', $type);

    $total = (clone $q)->count();

    // 一定要把 duration 与 tag_ids 查出来
    $list = $q->field('id,title,cover,type,is_vip,coin,view_count,like_count,favorite_count,create_time,tag_ids,duration')
        ->order('create_time desc, id desc')
        ->page($page, $pageSize)
        ->select()
        ->toArray();

    // 基础字段处理
    foreach ($list as &$m) {
        $m['cover'] = $this->fullUrl($m['cover'], '/static/images/default-cover.png');
        $m['vip']   = (bool)($m['is_vip'] ?? 0);
        $m['view_count']     = (int)($m['view_count'] ?? 0);
        $m['like_count']     = (int)($m['like_count'] ?? 0);
        $m['favorite_count'] = (int)($m['favorite_count'] ?? 0);
        if (($m['type'] ?? '') === 'video') {
            $m['duration'] = (int)($m['duration'] ?? 0);
        }
        unset($m['is_vip']);
    }
    unset($m); // 退出引用

    // ===== 批量把 tag_ids 转成 tags =====
    if (!empty($list)) {
        // 收集所有出现过的 tag_id
        $allIds = [];
        $split = function (?string $s): array {
            $s = (string)$s;
            if ($s === '') return [];
            $s = str_replace(['，','、',' '], ',', $s);
            preg_match_all('/\d+/', $s, $matches);
            $ids = array_map('intval', $matches[0] ?? []);
            // 去重保持顺序
            $seen = [];
            $result = [];
            foreach ($ids as $id) {
                if (!isset($seen[$id])) { $seen[$id] = true; $result[] = $id; }
            }
            return $result;
        };

        foreach ($list as $row) {
            $allIds = array_merge($allIds, $split($row['tag_ids'] ?? ''));
        }
        $allIds = array_values(array_unique($allIds));

        $tagMap = [];
        if (!empty($allIds)) {
            $tagMap = Db::name('onlyfans_tags')
                ->whereIn('id', $allIds)
                ->column('name', 'id'); // [id => name]
        }

        foreach ($list as &$m) {
            $ids  = $split($m['tag_ids'] ?? '');
            $tags = [];
            foreach ($ids as $tid) {
                $name  = $tagMap[$tid] ?? ('#'.$tid);
                $tags[] = ['id' => $tid, 'name' => $name];
            }
            $m['tags'] = $tags;
        }
        unset($m);
    }

    // ===== 关键点：为图片内容补充 image_total（一次聚合统计）=====
    if ($type === 'image' && !empty($list)) {
        $mediaIds = array_column($list, 'id');
        // 统计图片数量：SELECT media_id, COUNT(*) FROM onlyfans_images WHERE media_id IN (...) GROUP BY media_id
        $cntMap = Db::name('onlyfans_images')
            ->whereIn('media_id', $mediaIds)
            ->group('media_id')
            ->column('COUNT(*) AS cnt', 'media_id'); // [media_id => cnt]

        foreach ($list as &$m) {
            $m['image_total'] = (int)($cntMap[$m['id']] ?? 0);
        }
        unset($m);
    }

    return json([
        'code' => 0,
        'msg'  => 'success',
        'data' => [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
            'has_more'  => ($page * $pageSize) < $total,
        ]
    ]);
}

// OnlyFansH5Controller.php
public function mediaImages(Request $request)
{
    $mediaId = $request->param('id');
    if (!$mediaId) return json(['code' => 1, 'msg' => '内容ID不能为空']);

    // 与前端一致：默认每页12，上限60
    [$page, $pageSize] = $this->getPaging($request, 1, 12, 60);

    // 取父级内容的计数
    $meta = Db::name('onlyfans_media')
        ->where('id', $mediaId)
        ->where('status', 1)
        ->field('like_count, favorite_count, view_count')
        ->find();

    $q = Db::name('onlyfans_images')
        ->where('media_id', $mediaId)
        ->order('sort asc, id asc');

    $total = (clone $q)->count();

    // 只返回存在的列：id、url
    $rows = $q->field('id,url')
        ->page($page, $pageSize)
        ->select()
        ->toArray();

    foreach ($rows as &$r) {
        $r['url'] = $this->fullUrl($r['url'], '/static/images/default-image.png');
    }
    unset($r);

    return json([
        'code' => 0,
        'msg'  => 'success',
        'data' => [
            'list'          => $rows,   // [{id,url}]
            'total'         => $total,
            'page'          => $page,
            'page_size'     => $pageSize,
            'has_more'      => ($page * $pageSize) < $total,

            // ✅ 新增：父内容的计数
            'like_count'     => (int)($meta['like_count'] ?? 0),
            'favorite_count' => (int)($meta['favorite_count'] ?? 0),
            // 'view_count'   => (int)($meta['view_count'] ?? 0), // 需要就解开
        ]
    ]);
}

    // ---------------- 工具方法 ----------------

    // 统一补全为绝对 URL，空则给默认图
    private function fullUrl(?string $path, string $default = ''): string
    {
        $path = trim((string)$path);
        if ($path === '') return $default;
        if (preg_match('/^https?:\/\//i', $path)) return $path;
        if ($path[0] !== '/') $path = '/'.$path;
        $domain = rtrim(request()->domain(), '/');
        return $domain.$path;
    }

    /**
     * 统一分页参数解析
     * @param int $defaultPage  默认页码
     * @param int $defaultSize  默认每页条数
     * @param int $maxSize      最大每页条数（用于限流，强制卡上限）
     */
    private function getPaging(Request $request, int $defaultPage = 1, int $defaultSize = 15, int $maxSize = 15): array
    {
        $page = (int)$request->param('page', $defaultPage);
        $page = $page > 0 ? $page : 1;

        // 兼容 page_size / pageSize
        $sizeParam = $request->param('page_size', $request->param('pageSize', $defaultSize));
        $size = (int)$sizeParam;
        if ($size <= 0) $size = $defaultSize;

        // 强制不超过上限
        $size = min($size, max(1, $maxSize));

        return [$page, $size];
    }
}
