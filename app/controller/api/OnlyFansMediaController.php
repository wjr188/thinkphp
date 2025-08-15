<?php
// File path: app/controller/api/OnlyFansMediaController.php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use think\Validate;

class OnlyFansMediaController
{
    /** 允许写入的字段白名单（包含视频相关字段 + 收藏数 + 标签） */
    private array $allowFields = [
        'title','creator_id','type','cover','is_vip','coin','sort','status',
        'view_count','like_count','favorite_count','create_time','update_time',
        // 视频/描述字段
        'video_url','duration','file_size','description',
        // ⭐ 标签字段
        'tag_ids',
    ];

    /** 入参规范化为表字段，并做类型/兼容处理 */
    private function normalizePayload(array $data): array
    {
        // 兼容 cover_url -> cover
        if (!empty($data['cover_url']) && empty($data['cover'])) {
            $data['cover'] = $data['cover_url'];
            unset($data['cover_url']);
        }

        // 兼容 media_type（video | image_set） -> type（video | image）
        if (isset($data['media_type']) && $data['media_type'] !== '') {
            $data['type'] = ($data['media_type'] === 'image_set') ? 'image' : 'video';
            unset($data['media_type']);
        }

        // 布尔/数字归一
        if (isset($data['is_vip']))          $data['is_vip'] = (int)$data['is_vip'];
        if (isset($data['status']))          $data['status'] = (int)$data['status'];
        if (isset($data['coin']))            $data['coin'] = (int)$data['coin'];
        if (isset($data['sort']))            $data['sort'] = (int)$data['sort'];
        if (isset($data['duration']))        $data['duration'] = (int)$data['duration'];
        if (isset($data['view_count']))      $data['view_count'] = (int)$data['view_count'];
        if (isset($data['like_count']))      $data['like_count'] = (int)$data['like_count'];
        if (isset($data['favorite_count']))  $data['favorite_count'] = (int)$data['favorite_count'];

        // 前端视频表单“文件大小(MB)” -> 统一存 B（字节）
        if (isset($data['file_size'])) {
            $data['file_size'] = (int) round(floatval($data['file_size']) * 1024 * 1024);
        }

        // ⭐ 处理标签：允许传数组或逗号串，统一为“启用标签ID”的逗号串
        if (array_key_exists('tag_ids', $data)) {
            $data['tag_ids'] = $this->normalizeTagIds($data['tag_ids']);
        }

        // 白名单过滤，避免 “fields not exists”
        $data = array_intersect_key($data, array_flip($this->allowFields));
        return $data;
    }

    /** 把 tag_ids（数组或逗号字符串）转为：仅包含启用标签ID 的逗号串 */
    private function normalizeTagIds($tagIds): string
    {
        if ($tagIds === null || $tagIds === '') return '';

        // 统一成数组
        if (is_string($tagIds)) {
            $ids = array_map('trim', explode(',', $tagIds));
        } elseif (is_array($tagIds)) {
            $ids = $tagIds;
        } else {
            return '';
        }

        // 过滤成正整数
        $ids = array_values(array_filter(array_map(function($v){
            $n = intval($v);
            return $n > 0 ? $n : null;
        }, $ids)));

        if (empty($ids)) return '';

        // 只保留存在且启用的标签
        $valid = Db::name('onlyfans_tags')
            ->whereIn('id', $ids)
            ->where('status', 1)
            ->column('id');

        if (empty($valid)) return '';

        $valid = array_unique(array_map('intval', $valid));
        sort($valid);
        return implode(',', $valid);
    }

    /**
     * 获取媒体内容列表（图片合集/视频）
     * 架构：一级分类 → 博主 → 内容
     */
    public function list(Request $request)
    {
        try {
            $p = $request->param();

            // 分页：同时兼容 page_size 与 pageSize
            $page = max(1, (int)($p['page'] ?? 1));
            $pageSize = max(1, min(100, (int)($p['page_size'] ?? ($p['pageSize'] ?? 15))));

            // 基础查询 + 关联 creators 以便按分类筛选/取博主名
            $q = Db::name('onlyfans_media')->alias('m')
                ->leftJoin('onlyfans_creators c', 'c.id = m.creator_id');

            // 状态（管理端需要能看全部，所以只有传了才过滤）
            if (isset($p['status']) && $p['status'] !== '') {
                $q->where('m.status', (int)$p['status']);
            }

            // 博主筛选
            if (!empty($p['creator_id'])) {
                $q->where('m.creator_id', (int)$p['creator_id']);
            }

            // 分类筛选（join creators）
            if (!empty($p['category_id'])) {
                $q->where('c.category_id', (int)$p['category_id']);
            }

            // 内容类型：前端 media_type=video|image_set，库里 type=video|image
            if (isset($p['media_type']) && $p['media_type'] !== '') {
                $q->where('m.type', $p['media_type'] === 'image_set' ? 'image' : 'video');
            } elseif (!empty($p['type'])) {
                $q->where('m.type', $p['type']); // 兼容老参数
            }

            // 关键词
            if (!empty($p['keyword'])) {
                $q->whereLike('m.title', '%' . $p['keyword'] . '%');
            }

            // VIP
            if (isset($p['is_vip']) && $p['is_vip'] !== '') {
                $q->where('m.is_vip', (int)$p['is_vip']);
            }

            // ❌ 不做标签筛选（按你的要求移除）
            // if (!empty($p['tag_id'])) { ... }

            // 统计总数（join 时用 distinct）
            $total = (clone $q)->distinct(true)->count('m.id');

            // 列表字段：包含 tag_ids 字段
            $list = $q->field("
                m.id, m.title, m.cover, m.type, m.creator_id, m.is_vip, m.coin,
                m.view_count, m.like_count, m.favorite_count, m.sort, m.create_time, m.update_time, m.status,
                m.video_url, m.duration, m.file_size, m.description, m.tag_ids,
                (SELECT COUNT(*) FROM onlyfans_images i WHERE i.media_id = m.id) AS image_count,
                (SELECT COALESCE(SUM(i.size),0) FROM onlyfans_images i WHERE i.media_id = m.id) AS total_size,
                c.name as creator_name, c.avatar as creator_avatar
            ")
            ->order('m.sort desc, m.create_time desc, m.id desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

            // 补全字段，兼容前端
            $domain = rtrim($request->domain(), '/');
            foreach ($list as &$it) {
                // 绝对封面地址 -> cover_url
                $cover = (string)($it['cover'] ?? '');
                if ($cover !== '' && !preg_match('/^https?:\/\//i', $cover)) {
                    if ($cover[0] !== '/') $cover = '/' . $cover;
                    $cover = $domain . $cover;
                }
                $it['cover_url'] = $cover;

                // media_type：管理端用 video / image_set
                $it['media_type'] = ($it['type'] === 'image') ? 'image_set' : 'video';

                // 友好字段
                $it['vip']             = (bool)($it['is_vip'] ?? 0);
                $it['coin']            = (int)($it['coin'] ?? 0);
                $it['view_count']      = (int)($it['view_count'] ?? 0);
                $it['like_count']      = (int)($it['like_count'] ?? 0);
                $it['favorite_count']  = (int)($it['favorite_count'] ?? 0);

                // ✅ 仅视频返回 MB 便于前端展示；图片集仍用字节(total_size)
                $it['file_size_mb'] = ($it['type'] === 'video')
                    ? round(((int)($it['file_size'] ?? 0)) / 1048576, 2)
                    : 0;
            }
            unset($it);

            // 映射标签信息（组装 tags / tags_text）
            if (!empty($list)) {
                $list = $this->mapTagsToNames($list);
            }

            return json([
                'code' => 0,
                'msg'  => 'success',
                'data' => [
                    'list'      => $list,
                    'total'     => $total,
                    'page'      => $page,
                    'page_size' => $pageSize,
                ]
            ]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取媒体列表失败：' . $e->getMessage()]);
        }
    }

    /** 新增媒体内容（支持 tag_ids） */
    public function add(Request $request)
    {
        $raw = $request->post();

        // 和表结构一致的校验：title 100、cover 255（tag_ids 可选，后续 normalize 处理）
        $validate = new Validate([
            'title|标题'              => 'require|max:100',
            'creator_id|博主'         => 'require|integer|gt:0',
            'type|内容类型'            => 'require|in:image,video',
            'cover|封面'              => 'max:255',
            'is_vip|VIP设置'          => 'in:0,1',
            'coin|金币'               => 'integer|egt:0',
            'sort|排序'               => 'integer|egt:0',
            'video_url|视频URL'       => 'max:500',
            'duration|时长'           => 'integer|egt:0',
            'file_size|大小(MB)'      => 'number|egt:0',
            'description|描述'        => 'max:1000',
            'view_count|观看数'       => 'integer|egt:0',
            'like_count|点赞数'       => 'integer|egt:0',
            'favorite_count|收藏数'   => 'integer|egt:0',
        ]);

        if (!$validate->check($raw)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }

        try {
            // 验证博主是否存在
            $creator = Db::name('onlyfans_creators')
                ->where('id', $raw['creator_id'])
                ->where('status', 1)
                ->find();
            if (!$creator) {
                return json(['code' => 1, 'msg' => '博主不存在或已禁用']);
            }

            // 规范化 & 白名单
            $data = $this->normalizePayload($raw);

            // 默认值
            $data['is_vip']         = $data['is_vip']         ?? 0;
            $data['coin']           = $data['coin']           ?? 0;
            $data['sort']           = $data['sort']           ?? 0;
            $data['view_count']     = $data['view_count']     ?? 0;
            $data['like_count']     = $data['like_count']     ?? 0;
            $data['favorite_count'] = $data['favorite_count'] ?? 0;
            $data['status']         = $data['status']         ?? 1;
            $data['create_time']    = date('Y-m-d H:i:s');
            $data['update_time']    = date('Y-m-d H:i:s');
// 自动识别视频时长
if (($data['type'] ?? '') === 'video' && !empty($data['video_url'])) {
    if (empty($data['duration']) || (int)$data['duration'] <= 0) {
        $dur = $this->getVideoDuration($data['video_url']);
        if ($dur > 0) {
            $data['duration'] = $dur;
        }
    }
}

            $id = Db::name('onlyfans_media')->insertGetId($data);
            if (!$id) {
                return json(['code' => 1, 'msg' => '新增媒体内容失败']);
            }

            // 更新博主统计
            Db::name('onlyfans_creators')
                ->where('id', $data['creator_id'])
                ->inc('media_count', 1)
                ->update();

            return json(['code' => 0, 'msg' => '新增媒体内容成功', 'data' => ['id' => $id]]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '新增媒体内容失败：' . $e->getMessage()]);
        }
    }

    /** 更新媒体内容（支持 tag_ids） */
    public function update(Request $request)
{
    $raw = $request->post();

    $validate = new Validate([
        'id|内容ID'               => 'require|integer|gt:0',
        'title|标题'              => 'require|max:100',
        'creator_id|博主'         => 'require|integer|gt:0',
        'type|内容类型'            => 'require|in:image,video',
        'cover|封面'              => 'max:255',
        'is_vip|VIP设置'          => 'in:0,1',
        'coin|金币'               => 'integer|egt:0',
        'sort|排序'               => 'integer|egt:0',
        'video_url|视频URL'       => 'max:500',
        'duration|时长'           => 'integer|egt:0',
        'file_size|大小(MB)'      => 'number|egt:0',
        'description|描述'        => 'max:1000',
        'view_count|观看数'       => 'integer|egt:0',
        'like_count|点赞数'       => 'integer|egt:0',
        'favorite_count|收藏数'   => 'integer|egt:0',
    ]);

    if (!$validate->check($raw)) {
        return json(['code' => 1, 'msg' => $validate->getError()]);
    }

    try {
        // 检查内容是否存在
        $media = Db::name('onlyfans_media')->where('id', $raw['id'])->find();
        if (!$media) {
            return json(['code' => 1, 'msg' => '媒体内容不存在']);
        }

        // 验证博主是否存在
        $creator = Db::name('onlyfans_creators')
            ->where('id', $raw['creator_id'])
            ->where('status', 1)
            ->find();
        if (!$creator) {
            return json(['code' => 1, 'msg' => '博主不存在或已禁用']);
        }

        // 规范化 & 白名单
        $data = $this->normalizePayload($raw);
        unset($data['id']);
        $data['update_time'] = date('Y-m-d H:i:s');

        // 自动识别视频时长（兼容未传 type、以及 video_url 变化的情况）
        $isVideo = (($data['type'] ?? $media['type'] ?? '') === 'video');
        if ($isVideo) {
            $newUrl   = $data['video_url'] ?? null;
            $oldUrl   = $media['video_url'] ?? null;
            $needCalc = false;

            // 1) 显式传了 video_url 且与旧值不同 → 强制重算
            if (!empty($newUrl) && $newUrl !== $oldUrl) {
                $needCalc = true;
            }

            // 2) 未强制重算，但传入 duration 为空/<=0 → 重算
            if (!$needCalc && (!isset($data['duration']) || (int)$data['duration'] <= 0) && !empty($newUrl)) {
                $needCalc = true;
            }

            if ($needCalc && !empty($newUrl)) {
                $dur = $this->getVideoDuration($newUrl);
                if ($dur > 0) {
                    $data['duration'] = $dur;
                }
            }
        }

        $result = Db::name('onlyfans_media')
            ->where('id', $media['id'])
            ->update($data);

        if ($result === false) {
            return json(['code' => 1, 'msg' => '更新媒体内容失败']);
        }

        return json(['code' => 0, 'msg' => '更新媒体内容成功']);
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '更新媒体内容失败：' . $e->getMessage()]);
    }
}


    /** 删除媒体内容（同时删掉图片表记录） */
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => '内容ID不能为空']);
        }

        try {
            Db::startTrans();

            $media = Db::name('onlyfans_media')->where('id', $id)->find();
            if (!$media) {
                throw new \Exception('媒体内容不存在');
            }

            // 先删图片（onlyfans_images）
            Db::name('onlyfans_images')->where('media_id', $id)->delete();

            // 再删主表
            $result = Db::name('onlyfans_media')->where('id', $id)->delete();
            if (!$result) {
                throw new \Exception('删除失败');
            }

            // 更新博主的媒体统计
            Db::name('onlyfans_creators')
                ->where('id', $media['creator_id'])
                ->dec('media_count', 1)
                ->update();

            Db::commit();
            return json(['code' => 0, 'msg' => '删除媒体内容成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /** 批量删除（同时删掉图片表记录） */
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '请选择要删除的内容']);
        }

        try {
            Db::startTrans();

            // 先把要删的媒体查出来（用于统计每个博主减多少）
            $mediaList = Db::name('onlyfans_media')
                ->whereIn('id', $ids)
                ->field('id, creator_id')
                ->select()
                ->toArray();

            if (empty($mediaList)) {
                throw new \Exception('没有找到要删除的内容');
            }

            // 先删图片（onlyfans_images）
            Db::name('onlyfans_images')->whereIn('media_id', $ids)->delete();

            // 再删主表
            $count = Db::name('onlyfans_media')->whereIn('id', $ids)->delete();
            if (!$count) {
                throw new \Exception('批量删除失败');
            }

            // 更新博主的媒体统计
            $creatorCounts = [];
            foreach ($mediaList as $media) {
                $creatorCounts[$media['creator_id']] = ($creatorCounts[$media['creator_id']] ?? 0) + 1;
            }
            foreach ($creatorCounts as $creatorId => $deleteCount) {
                Db::name('onlyfans_creators')
                    ->where('id', $creatorId)
                    ->dec('media_count', $deleteCount)
                    ->update();
            }

            Db::commit();
            return json(['code' => 0, 'msg' => "批量删除成功，共删除 {$count} 个内容"]);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /** 获取单个媒体内容详情（返回 tags） */
    public function getById(Request $request)
    {
        $id = $request->param('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => '内容ID不能为空']);
        }

        try {
            $media = Db::name('onlyfans_media')->where('id', $id)->find();
            if (!$media) {
                return json(['code' => 1, 'msg' => '媒体内容不存在']);
            }

            // 获取博主信息
            $creator = Db::name('onlyfans_creators')->where('id', $media['creator_id'])->find();
            if ($creator) {
                $media['creator_name'] = $creator['name'];
                $media['creator_avatar'] = $creator['avatar'];
            }

            // 补全封面URL + media_type + 计数归一 + file_size_mb
            $domain = rtrim($request->domain(), '/');
            if (!empty($media['cover']) && !preg_match('/^https?:\/\//', $media['cover'])) {
                if ($media['cover'][0] !== '/') {
                    $media['cover'] = '/' . $media['cover'];
                }
                $media['cover'] = $domain . $media['cover'];
            }
            $media['cover_url']      = $media['cover'];
            $media['media_type']     = ($media['type'] === 'image') ? 'image_set' : 'video';
            $media['vip']            = (bool)$media['is_vip'];
            $media['view_count']     = (int)($media['view_count'] ?? 0);
            $media['like_count']     = (int)($media['like_count'] ?? 0);
            $media['favorite_count'] = (int)($media['favorite_count'] ?? 0);
            $media['coin']           = (int)($media['coin'] ?? 0);
            $media['file_size_mb']   = ($media['type'] === 'video')
                ? round(((int)($media['file_size'] ?? 0)) / 1048576, 2)
                : 0;

            // 获取标签信息
            $tags = [];
            if (!empty($media['tag_ids'])) {
                $tagIds = explode(',', $media['tag_ids']);
                $tags = Db::name('onlyfans_tags')
                    ->whereIn('id', $tagIds)
                    ->where('status', 1)
                    ->field('id, name')
                    ->select()
                    ->toArray();
            }
            $media['tags'] = $tags;
            $media['tags_text'] = implode(', ', array_column($tags, 'name'));

            return json([
                'code' => 0,
                'msg'  => 'success',
                'data' => $media
            ]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取媒体详情失败：' . $e->getMessage()]);
        }
    }

    /** 批量设置VIP */
    public function batchSetVip(Request $request)
    {
        $ids = $request->post('ids', []);
        $isVip = $request->post('is_vip', 1);

        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '请选择要设置的内容']);
        }

        try {
            $count = Db::name('onlyfans_media')
                ->whereIn('id', $ids)
                ->update([
                    'is_vip' => (int)$isVip,
                    'update_time' => date('Y-m-d H:i:s')
                ]);

            $vipText = $isVip ? 'VIP' : '普通';
            return json(['code' => 0, 'msg' => "批量设置{$vipText}成功，共设置 {$count} 个内容"]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '批量设置VIP失败：' . $e->getMessage()]);
        }
    }

    /** 批量设置金币 */
    public function batchSetGold(Request $request)
    {
        $ids = $request->post('ids', []);
        $coin = (int)$request->post('coin', 0);

        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '请选择要设置的内容']);
        }

        try {
            $count = Db::name('onlyfans_media')
                ->whereIn('id', $ids)
                ->update([
                    'coin' => $coin,
                    'update_time' => date('Y-m-d H:i:s')
                ]);

            return json(['code' => 0, 'msg' => "批量设置金币成功，共设置 {$count} 个内容"]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '批量设置金币失败：' . $e->getMessage()]);
        }
    }

    /** 批量设置状态 */
    public function batchSetStatus(Request $request)
    {
        $ids = $request->post('ids', []);
        $status = (int)$request->post('status', 1);

        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => '请选择要设置的内容']);
        }

        try {
            $count = Db::name('onlyfans_media')
                ->whereIn('id', $ids)
                ->update([
                    'status' => $status,
                    'update_time' => date('Y-m-d H:i:s')
                ]);

            $statusText = $status ? '启用' : '禁用';
            return json(['code' => 0, 'msg' => "批量{$statusText}成功，共设置 {$count} 个内容"]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '批量设置状态失败：' . $e->getMessage()]);
        }
    }

    /** 更新排序 */
    public function updateSort(Request $request)
    {
        $list = $request->post('list', []);
        if (empty($list) || !is_array($list)) {
            return json(['code' => 1, 'msg' => '参数错误']);
        }

        try {
            Db::startTrans();

            foreach ($list as $item) {
                if (isset($item['id']) && isset($item['sort'])) {
                    Db::name('onlyfans_media')
                        ->where('id', $item['id'])
                        ->update([
                            'sort' => intval($item['sort']),
                            'update_time' => date('Y-m-d H:i:s')
                        ]);
                }
            }

            Db::commit();
            return json(['code' => 0, 'msg' => '排序更新成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '排序更新失败：' . $e->getMessage()]);
        }
    }

    /** 获取图片列表：?media_id=xx */
    public function getImagesByMediaId(Request $request)
    {
        $mediaId = (int)$request->param('media_id', 0);
        if ($mediaId <= 0) return json(['code' => 1, 'msg' => 'media_id 必填']);

        try {
            $list = Db::name('onlyfans_images')
                ->where('media_id', $mediaId)
                ->order('sort asc, id asc')
                ->select()
                ->toArray();

            return json(['code' => 0, 'msg' => 'success', 'data' => ['list' => $list]]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取图片失败：' . $e->getMessage()]);
        }
    }

    /** 覆盖保存图片URL（先删后插） */
    public function saveImageUrls(Request $request)
    {
        $mediaId = (int)$request->post('media_id', 0);
        $urls    = $request->post('urls', []);

        if ($mediaId <= 0) return json(['code' => 1, 'msg' => 'media_id 必填']);
        if (!is_array($urls) || count($urls) === 0) {
            return json(['code' => 1, 'msg' => 'urls 不能为空']);
        }

        // 校验媒体存在且为图片集
        $media = Db::name('onlyfans_media')->where('id', $mediaId)->find();
        if (!$media) return json(['code' => 1, 'msg' => '媒体不存在']);
        if (($media['type'] ?? '') !== 'image') {
            return json(['code' => 1, 'msg' => '该媒体不是图片集类型']);
        }

        try {
            Db::startTrans();

            Db::name('onlyfans_images')->where('media_id', $mediaId)->delete();

            $now  = date('Y-m-d H:i:s');
            $rows = [];
            $sort = 1;
            foreach ($urls as $u) {
                $u = trim((string)$u);
                if ($u === '') continue;
                $rows[] = [
                    'media_id'    => $mediaId,
                    'url'         => $u,
                    'name'        => '',
                    'size'        => 0,
                    'sort'        => $sort++,
                    'create_time' => $now,
                ];
            }
            if ($rows) Db::name('onlyfans_images')->insertAll($rows);

            Db::commit();
            return json(['code' => 0, 'msg' => '保存成功', 'data' => ['count' => count($rows)]]);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '保存失败：' . $e->getMessage()]);
        }
    }

    /** 删除单张图片 */
    public function deleteImage(Request $request)
    {
        $id = (int)$request->post('id', 0);
        if ($id <= 0) return json(['code' => 1, 'msg' => 'id 必填']);

        try {
            $ok = Db::name('onlyfans_images')->where('id', $id)->delete();
            if (!$ok) return json(['code' => 1, 'msg' => '删除失败或图片不存在']);
            return json(['code' => 0, 'msg' => '删除成功']);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '删除失败：' . $e->getMessage()]);
        }
    }

    /**
     * 获取视频列表时映射标签名称
     * 在list方法中调用此方法处理标签显示
     */
    private function mapTagsToNames($mediaList)
{
    // 收集所有视频的标签ID
    $allTagIds = [];
    foreach ($mediaList as $media) {
        if (($media['type'] ?? '') === 'video' && !empty($media['tag_ids'])) {
            $tagIds = explode(',', (string)$media['tag_ids']);
            $allTagIds = array_merge($allTagIds, $tagIds);
        }
    }

    // 清洗 + 去重（替换原来的 array_filter(array_unique($allTagIds))）
    $allTagIds = array_values(array_filter(array_unique(array_map(function($v){
        $n = intval(trim((string)$v));
        return $n > 0 ? $n : null;
    }, $allTagIds))));

    // 没标签直接补空字段返回
    if (empty($allTagIds)) {
        foreach ($mediaList as &$media) {
            $media['tags'] = [];
            $media['tags_text'] = '';
        }
        return $mediaList;
    }

    // 批量查标签
    $tagsMap = Db::name('onlyfans_tags')
        ->whereIn('id', $allTagIds)
        ->where('status', 1)
        ->column('name', 'id'); // [id => name]

    // 映射回每条媒体
    foreach ($mediaList as &$media) {
        $media['tags'] = [];
        $media['tags_text'] = '';

        if (($media['type'] ?? '') === 'video' && !empty($media['tag_ids'])) {
            // 单条也清洗一遍
            $tagIds = array_values(array_filter(array_unique(array_map(function($v){
                $n = intval(trim((string)$v));
                return $n > 0 ? $n : null;
            }, explode(',', (string)$media['tag_ids'])))));

            $tagNames = [];
            $tagObjects = [];
            foreach ($tagIds as $tid) {
                if (isset($tagsMap[$tid])) {
                    $tagNames[]   = $tagsMap[$tid];
                    $tagObjects[] = ['id' => (int)$tid, 'name' => $tagsMap[$tid]];
                }
            }
            $media['tags'] = $tagObjects;
            $media['tags_text'] = implode(', ', $tagNames);
        }
    }

    return $mediaList;
}

    /**
     * 为视频设置标签
     * POST /api/onlyfans/media/set-tags
     * 说明：你也可以直接在 add/update 里传 tag_ids，这个接口用于“单独设置标签”的场景
     */
    public function setTags(Request $request)
    {
        $videoId = $request->post('video_id');
        $tagIds = $request->post('tag_ids', []); // 标签ID数组

        if (!$videoId) {
            return json(['code' => 1, 'msg' => '视频ID不能为空']);
        }

        try {
            // 检查视频是否存在且为视频类型
            $video = Db::name('onlyfans_media')
                ->where('id', $videoId)
                ->where('type', 'video')
                ->find();

            if (!$video) {
                return json(['code' => 1, 'msg' => '视频不存在或不是视频类型']);
            }

            // 复用规范化逻辑
            $tagIdsStr = $this->normalizeTagIds($tagIds);

            // 更新视频的标签信息
            $result = Db::name('onlyfans_media')
                ->where('id', $videoId)
                ->update([
                    'tag_ids' => $tagIdsStr,
                    'update_time' => date('Y-m-d H:i:s')
                ]);

            return $result !== false ? json(['code' => 0, 'msg' => '标签设置成功'])
                                     : json(['code' => 1, 'msg' => '标签设置失败']);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '标签设置失败：' . $e->getMessage()]);
        }
    }

    /**
     * 获取视频的标签 - 返回完整标签信息
     * GET /api/onlyfans/media/tags/:id
     */
    public function getTags(Request $request)
    {
        $videoId = $request->param('id');
        if (!$videoId) {
            return json(['code' => 1, 'msg' => '视频ID不能为空']);
        }

        try {
            $video = Db::name('onlyfans_media')
                ->where('id', $videoId)
                ->where('type', 'video')
                ->field('tag_ids')
                ->find();

            if (!$video) {
                return json(['code' => 1, 'msg' => '视频不存在']);
            }

            $tags = [];
            if (!empty($video['tag_ids'])) {
                $tagIds = explode(',', $video['tag_ids']);
                $tags = Db::name('onlyfans_tags')
                    ->whereIn('id', $tagIds)
                    ->where('status', 1)
                    ->field('id, name')
                    ->select()
                    ->toArray();
            }

            return json(['code' => 0, 'msg' => 'success', 'data' => $tags]);

        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取视频标签失败：' . $e->getMessage()]);
        }
    }
    /**
 * 通过 ffprobe 获取视频时长（单位：秒）
 * - 优先用 format=duration；若拿不到再用 -i 兜底
 * - 请确保服务器已安装 ffprobe 并且 PHP 未禁用 shell_exec
 */
private function getVideoDuration(string $videoUrl): int
{
    $arg = escapeshellarg($videoUrl);

    // 尝试 1：直接读 format.duration
    $cmd1 = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 $arg";
    $out = @shell_exec($cmd1);
    $val = is_string($out) ? trim($out) : '';

    // 拿不到再兜底一次
    if ($val === '' || !is_numeric($val) || (float)$val <= 0) {
        $cmd2 = "ffprobe -v error -i $arg -show_entries format=duration -of default=noprint_wrappers=1:nokey=1";
        $out = @shell_exec($cmd2);
        $val = is_string($out) ? trim($out) : '';
    }

    if ($val !== '' && is_numeric($val)) {
        return (int) round((float) $val);
    }
    return 0;
}

}
