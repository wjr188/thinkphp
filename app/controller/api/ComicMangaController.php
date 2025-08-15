<?php
declare (strict_types = 1);

namespace app\controller\Api;

use app\BaseController;
use think\facade\Request;
use think\facade\Db;
use think\Validate;
    use think\facade\Cache;

class ComicMangaController extends BaseController
{   
    public function list()
{
    $param = Request::param();

    $where = [];
    if (!empty($param['keyword'])) {
        $where[] = ['name', 'like', '%' . $param['keyword'] . '%'];
    }
    if (isset($param['category_id']) && $param['category_id'] !== '') {
        $where[] = ['category_id', '=', (int)$param['category_id']];
    }
    if (isset($param['sub_category_id']) && $param['sub_category_id'] !== '') {
        $where[] = ['sub_category_id', '=', (int)$param['sub_category_id']];
    }
    if (isset($param['tag_id']) && $param['tag_id'] !== '') {
        $tagId = (int)$param['tag_id'];
        $where[] = ['tags', 'like', '%'.$tagId.'%'];
    }
    if (isset($param['status']) && $param['status'] !== '') {
        $where[] = ['status', '=', (int)$param['status']];
    }
    if (isset($param['is_serializing']) && $param['is_serializing'] !== '') {
        $where[] = ['is_serializing', '=', (int)$param['is_serializing']];
    }
    if (isset($param['is_shelf']) && $param['is_shelf'] !== '') {
        $where[] = ['is_shelf', '=', (int)$param['is_shelf']];
    }
    if (isset($param['is_vip']) && $param['is_vip'] !== '') {
        $where[] = ['is_vip', '=', (int)$param['is_vip']];
    }
    
    // ✅ 添加缺失的 coin 参数处理
    if (isset($param['coin']) && $param['coin'] !== '') {
        $where[] = ['coin', '=', (int)$param['coin']];
    }
    $page = (int)($param['page'] ?? 1);
$pageSize = (int)($param['page_size'] ?? $param['pageSize'] ?? 10);
// 支持 page_size 和 pageSize 两种写法


    try {
        $query = Db::name('comic_manga')->where($where);
        $total = $query->count();

        // 只查 chapter_count 字段
        // 排序处理，根据前端传入sort
$sort = $param['sort'] ?? 'default';
switch ($sort) {
    case 'views':
        $orderBy = ['views' => 'desc', 'id' => 'desc'];
        break;
    case 'likes':
        $orderBy = ['likes' => 'desc', 'id' => 'desc'];
        break;
    case 'collects':
        $orderBy = ['collects' => 'desc', 'id' => 'desc'];
        break;
    case 'newest':
        $orderBy = ['created_at' => 'desc', 'id' => 'desc'];
        break;
    default:
        $orderBy = ['sort' => 'desc', 'id' => 'desc'];
}

$list = Db::name('comic_manga')
    ->where($where)
    ->order($orderBy)
    ->page($page, $pageSize)
    ->select()
    ->toArray();

$domain = rtrim(request()->domain(), '/');
foreach ($list as &$item) {
    if (is_array($item['tags'])) {
        $item['tags'] = implode(',', $item['tags']);
    }
    // 只补全不是 http(s) 的封面
    if (!empty($item['cover']) && !preg_match('/^https?:\/\//', $item['cover'])) {
        // 保证有 /
        if ($item['cover'][0] !== '/') {
            $item['cover'] = '/' . $item['cover'];
        }
        $item['cover'] = $domain . $item['cover'];
    }
    if (!isset($item['chapter_count'])) {
        $item['chapter_count'] = 0;
    }
}
unset($item);


    return json([
        'code' => 0,
        'msg' => 'success',
        'data' => [
            'list' => $list,
            'total' => $total
        ]
    ]);
} catch (\Exception $e) {
    return json(['code' => 1, 'msg' => '获取漫画列表失败：' . $e->getMessage()]);
}
}
   public function detail($id)
{
    try {
        $manga = Db::name('comic_manga')->where('id', (int)$id)->find();
        if (!$manga) {
            return json(['code' => 1, 'msg' => '漫画未找到']);
        }

        // 补全封面
        $domain = rtrim(request()->domain(), '/');
        if (!empty($manga['cover']) && !preg_match('/^https?:\/\//', $manga['cover'])) {
            if ($manga['cover'][0] !== '/') {
                $manga['cover'] = '/' . $manga['cover'];
            }
            $manga['cover'] = $domain . $manga['cover'];
        }

        // 处理tags: "1,2,3" => ["茫茫人海","完全","666"]
        $tagNames = [];
        if (!empty($manga['tags'])) {
            $tagIds = explode(',', $manga['tags']);
            $tagIds = array_filter($tagIds);
            if (!empty($tagIds)) {
                // 查id=>name
                $tagMap = Db::name('comic_tags')
                    ->whereIn('id', $tagIds)
                    ->column('name', 'id');

                // 生成按原顺序的名字
                foreach ($tagIds as $tid) {
                    if (isset($tagMap[$tid])) {
                        $tagNames[] = $tagMap[$tid];
                    }
                }
            }
        }

        // 替换tags字段为名字数组
        $manga['tags'] = $tagNames;

        return json(['code' => 0, 'msg' => 'success', 'data' => $manga]);

    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '获取漫画详情失败：' . $e->getMessage()]);
    }
}

    public function add()
    {
        $data = Request::param();

        $validate = new Validate([
            'name|漫画名称' => 'require|max:100|unique:comic_manga',
            'author|作者' => 'max:50',
            'cover|封面图片' => 'url',
            'intro|简介描述' => 'max:65535',
            'category_id|主分类ID' => 'require|integer',
            'sub_category_id|子分类ID' => 'integer',
            'tags|标签ID集合' => 'array',
            'is_vip|是否VIP' => 'in:0,1',
            'coin|金币数量' => 'integer|min:0',
            'views|阅读量' => 'integer|min:0',
    'likes|点赞量' => 'integer|min:0',
    'collects|收藏量' => 'integer|min:0',
            'is_serializing|连载状态' => 'in:0,1',
            'is_shelf|上架状态' => 'in:0,1',
            'sort|排序' => 'integer',
            'status|状态' => 'in:0,1',
            'update_day|更新星期' => 'integer|between:1,5', // 只允许 1-5（周一到周五）
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }

        try {
            $tagsValue = null;
            if (isset($data['tags'])) {
                if (is_array($data['tags'])) {
                    $tagsValue = implode(',', $data['tags']);
                } else {
                    $tagsValue = (string)$data['tags'];
                }
            }

            $insertData = [
                'name' => $data['name'],
                'cover' => $data['cover'] ?? null,
                'author' => $data['author'] ?? null,
                'intro' => $data['intro'] ?? null,
                'category_id' => (int)$data['category_id'],
                'sub_category_id' => (int)($data['sub_category_id'] ?? 0),
                'tags' => $tagsValue,
                'is_vip' => (int)($data['is_vip'] ?? 0),
                'coin' => (int)($data['coin'] ?? 0),
                'views' => (int)($data['views'] ?? 0),
    'likes' => (int)($data['likes'] ?? 0),
    'collects' => (int)($data['collects'] ?? 0),
                'is_serializing' => (int)($data['is_serializing'] ?? 1),
                'is_shelf' => (int)($data['is_shelf'] ?? 1),
                'sort' => (int)($data['sort'] ?? 0),
                'status' => (int)($data['status'] ?? 1),
                'remark' => $data['remark'] ?? null,
                'update_day' => (int)($data['update_day'] ?? 0), // 默认 0 表示不固定更新
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $result = Db::name('comic_manga')->insert($insertData);

            if ($result) {
                return json(['code' => 0, 'msg' => '新增漫画成功']);
            } else {
                return json(['code' => 1, 'msg' => '新增漫画失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    public function update()
    {
        $data = Request::param();

        $validate = new Validate([
            'id|主键ID' => 'require|integer',
            'name|漫画名称' => 'require|max:100|unique:comic_manga,name,' . $data['id'] . ',id',
            'author|作者' => 'max:50',
            'cover|封面图片' => 'url',
            'intro|简介描述' => 'max:65535',
            'category_id|主分类ID' => 'require|integer',
            'sub_category_id|子分类ID' => 'integer',
            'tags|标签ID集合' => '',
            'is_vip|是否VIP' => 'in:0,1',
            'coin|金币数量' => 'integer|min:0',
             'views|阅读量' => 'integer|min:0',
    'likes|点赞量' => 'integer|min:0',
    'collects|收藏量' => 'integer|min:0',
            'is_serializing|连载状态' => 'in:0,1',
            'is_shelf|上架状态' => 'in:0,1',
            'sort|排序' => 'integer',
            'status|状态' => 'in:0,1',
            'update_day|更新星期' => 'integer|between:0,5', // 允许 0-5（0=不固定，1-5=周一到周五）
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }

        $mangaId = (int)$data['id'];
        $manga = Db::name('comic_manga')->where('id', $mangaId)->find();
        if (!$manga) {
            return json(['code' => 1, 'msg' => '漫画未找到']);
        }

        try {
    $tagsValue = null;
    if (isset($data['tags'])) {
        if (is_array($data['tags'])) {
            $tagsValue = implode(',', $data['tags']);
        } else {
            $tagsValue = (string)$data['tags'];
        }
    }

    Db::startTrans();

    $updateData = [
        'name' => $data['name'],
        'cover' => $data['cover'] ?? null,
        'author' => $data['author'] ?? null,
        'intro' => $data['intro'] ?? null,
        'category_id' => (int)$data['category_id'],
        'sub_category_id' => (int)($data['sub_category_id'] ?? 0),
        'tags' => $tagsValue,
        'is_vip' => (int)($data['is_vip'] ?? 0),
        'coin' => (int)($data['coin'] ?? 0),
        'views' => (int)($data['views'] ?? 0),
    'likes' => (int)($data['likes'] ?? 0),
    'collects' => (int)($data['collects'] ?? 0),
        'is_serializing' => (int)($data['is_serializing'] ?? 1),
        'is_shelf' => (int)($data['is_shelf'] ?? 1),
        'sort' => (int)($data['sort'] ?? 0),
        'status' => (int)($data['status'] ?? 1),
        'remark' => $data['remark'] ?? null,
        'update_day' => (int)($data['update_day'] ?? 0),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // 1. 更新漫画表
    $result = Db::name('comic_manga')->where('id', $mangaId)->update($updateData);

    // 2. 同步更新所有章节表
    Db::name('comic_chapters')
        ->where('manga_id', $mangaId)
        ->update([
            'is_vip' => (int)($data['is_vip'] ?? 0),
            'coin' => (int)($data['coin'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

    Db::commit();

    return json(['code' => 0, 'msg' => '更新漫画成功，并同步更新所有章节']);
} catch (\Exception $e) {
    Db::rollback();
    return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
}

    }

    public function delete()
    {
        $param = Request::param();
        $mangaId = $param['id'] ?? null;

        if (empty($mangaId)) {
            return json(['code' => 1, 'msg' => 'ID为必填项']);
        }

        $manga = Db::name('comic_manga')->where('id', (int)$mangaId)->find();
        if (!$manga) {
            return json(['code' => 1, 'msg' => '漫画未找到']);
        }

        try {
            $result = Db::name('comic_manga')->where('id', (int)$mangaId)->delete();
            
            if ($result) {
                return json(['code' => 0, 'msg' => '删除漫画成功']);
            } else {
                return json(['code' => 1, 'msg' => '删除漫画失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }
 /**
 * 批量设置更新星期（新增方法）
 */
public function batchSetUpdateDay()
{
    $param = Request::param();
    $ids = $param['ids'] ?? [];
    $updateDay = $param['update_day'] ?? null;

    if (empty($ids) || !is_array($ids) || !isset($updateDay) || !is_numeric($updateDay)) {
        return json(['code' => 1, 'msg' => 'ID列表和有效更新星期为必填项']);
    }

    $updateDayInt = (int)$updateDay;
    if ($updateDayInt < 0 || $updateDayInt > 5) {
        return json(['code' => 1, 'msg' => '更新星期只能是0-5（0=不固定，1-5=周一到周五）']);
    }
    try {
        $updateData = [
            'update_day' => $updateDayInt,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $result = Db::name('comic_manga')->whereIn('id', $ids)->update($updateData);
        
        if ($result !== false) {
            $dayNames = ['不固定', '周一', '周二', '周三', '周四', '周五'];
            return json(['code' => 0, 'msg' => "批量设置更新星期为{$dayNames[$updateDayInt]}成功"]);
        } else {
            return json(['code' => 1, 'msg' => '批量设置更新星期失败']);
        }
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
    }
}
    public function batchDelete()
    {
        $param = Request::param();
        $ids = $param['ids'] ?? [];

        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => 'ID列表为必填项且必须是数组']);
        }

        try {
            $result = Db::name('comic_manga')->whereIn('id', $ids)->delete();
            
            if ($result) {
                return json(['code' => 0, 'msg' => '批量删除成功']);
            } else {
                return json(['code' => 1, 'msg' => '批量删除失败或没有找到要删除的漫画']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    public function batchSetSerializationStatus()
    {
        $param = Request::param();
        $ids = $param['ids'] ?? [];
        $status = $param['status'] ?? null;

        if (empty($ids) || !is_array($ids) || !isset($status) || ($status !== 0 && $status !== 1)) {
            return json(['code' => 1, 'msg' => 'ID列表和有效状态为必填项']);
        }

        try {
            $updateData = ['is_serializing' => (int)$status, 'updated_at' => date('Y-m-d H:i:s')];
            $result = Db::name('comic_manga')->whereIn('id', $ids)->update($updateData);
            
            if ($result !== false) {
                return json(['code' => 0, 'msg' => '批量设置连载状态成功']);
            } else {
                return json(['code' => 1, 'msg' => '批量设置连载状态失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    public function batchSetShelfStatus()
    {
        $param = Request::param();
        $ids = $param['ids'] ?? [];
        $status = $param['status'] ?? null;

        if (empty($ids) || !is_array($ids) || !isset($status) || ($status !== 0 && $status !== 1)) {
            return json(['code' => 1, 'msg' => 'ID列表和有效状态为必填项']);
        }

        try {
            $updateData = ['is_shelf' => (int)$status, 'updated_at' => date('Y-m-d H:i:s')];
            $result = Db::name('comic_manga')->whereIn('id', $ids)->update($updateData);
            
            if ($result !== false) {
                return json(['code' => 0, 'msg' => '批量设置上架状态成功']);
            } else {
                return json(['code' => 1, 'msg' => '批量设置上架状态失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    public function batchSetVip()
    {
        $param = Request::param();
        $ids = $param['ids'] ?? [];
        $isVip = $param['is_vip'] ?? null;

        if (empty($ids) || !is_array($ids) || !isset($isVip) || ($isVip !== 0 && $isVip !== 1)) {
            return json(['code' => 1, 'msg' => 'ID列表和有效VIP状态为必填项']);
        }

        try {
            $updateData = ['is_vip' => (int)$isVip, 'updated_at' => date('Y-m-d H:i:s')];
            $result = Db::name('comic_manga')->whereIn('id', $ids)->update($updateData);
            
            if ($result !== false) {
                return json(['code' => 0, 'msg' => '批量设置VIP状态成功']);
            } else {
                return json(['code' => 1, 'msg' => '批量设置VIP状态失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    public function batchSetCoin()
{
    $param = Request::param();
    $ids = $param['ids'] ?? [];
    $coin = $param['coin'] ?? null;

    if (empty($ids) || !is_array($ids) || !isset($coin) || !is_numeric($coin) || (int)$coin < 0) {
        return json(['code' => 1, 'msg' => 'ID列表和有效金币数量为必填项']);
    }

    Db::startTrans();
    try {
        // 先改漫画表
        $updateData = ['coin' => (int)$coin, 'updated_at' => date('Y-m-d H:i:s')];
        $result = Db::name('comic_manga')->whereIn('id', $ids)->update($updateData);

        // 再改对应漫画下所有章节
        Db::name('comic_chapters')
            ->whereIn('manga_id', $ids)
            ->update($updateData);

        Db::commit();
        return json(['code' => 0, 'msg' => '批量设置金币成功，已同步更新所有章节']);
    } catch (\Exception $e) {
        Db::rollback();
        return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
    }
}



public function chapterList()
{
    $param = Request::param();
    $mangaId = $param['manga_id'] ?? 0;
    $page = (int)($param['page'] ?? 1);
$pageSize = (int)($param['page_size'] ?? $param['pageSize'] ?? 10);
// 支持 page_size 和 pageSize 两种写法


    if (!$mangaId) {
        return json(['code' => 1, 'msg' => '漫画ID必填']);
    }

    $where = [['manga_id', '=', $mangaId]];

    $query = Db::name('comic_chapters')->where($where);
    $total = $query->count();
    $list = $query->page($page, $pageSize)
                  ->order('order_num', 'asc')
                  ->select()
                  ->toArray();

    $domain = rtrim(request()->domain(), '/');

    foreach ($list as &$chapter) {
        $covers = Db::name('comic_images')
            ->where('chapter_id', $chapter['id'])
            ->order('sort', 'asc')
            ->limit(5)
            ->column('img_url');

        $cover = '';
        if (count($covers) >= 5) {
            $cover = $covers[4];
        } elseif (count($covers) > 0) {
            $cover = $covers[0];
        }

        if ($cover) {
            if (!preg_match('/^https?:\/\//', $cover)) {
                if ($cover[0] !== '/') $cover = '/' . $cover;
                $cover = $domain . $cover;
            }
            $chapter['cover'] = $cover;
        } else {
            $chapter['cover'] = '';
        }
    }
    unset($chapter);

    return json([
        'code' => 0,
        'msg' => 'success',
        'data' => [
            'list' => $list,
            'total' => $total
        ]
    ]);
}

    public function chapterListByMangaId($mangaId)
    {
        $list = Db::name('comic_chapters')
            ->where('manga_id', (int)$mangaId)
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'chapters' => $list
            ]
        ]);
    }

   public function chapterImages()
{
    $chapterId = Request::param('chapter_id');
    if (empty($chapterId)) {
        return json(['code' => 1, 'msg' => '章节ID必填']);
    }

    // 先查章节
    $chapter = Db::name('comic_chapters')->where('id', (int)$chapterId)->find();
    if (!$chapter) {
        return json(['code' => 1, 'msg' => '章节未找到']);
    }

    // 权限校验
    $needVip = intval($chapter['is_vip'] ?? 0);
    $needCoin = intval($chapter['coin'] ?? 0);

    // 校验用户登录
    $user = $this->getLoginUser();
    if (!$user) {
        return json(['code' => 401, 'msg' => '请先登录']);
    }

    // 查询用户VIP相关权益
    $canViewVipVideo = 0;
    $canWatchCoin = 0;
    if (!empty($user['vip_card_id'])) {
        $vipCardType = Db::name('vip_card_type')->where('id', $user['vip_card_id'])->find();
        if ($vipCardType) {
            $canViewVipVideo = intval($vipCardType['can_view_vip_video'] ?? 0);
            $canWatchCoin    = intval($vipCardType['can_watch_coin'] ?? 0);
        }
    }

    // 查是否已解锁
    $isUnlocked = Db::name('user_video_unlock')
        ->where('user_id', $user['uuid'])
        ->where('video_id', $chapterId)
        ->where('type', 2) // type=2=漫画章节
        ->find();

    // 权限判定
    if ($needVip === 1 && $canViewVipVideo) {
        // VIP章节且有VIP全免
    } elseif ($needCoin > 0 && $canWatchCoin) {
        // 金币章节且有金币全免
    } elseif ($isUnlocked) {
        // 已解锁
    } elseif ($needVip === 1) {
        // VIP章节但无VIP
        return json(['code' => 403, 'msg' => '需要VIP才能阅读']);
    } elseif ($needCoin > 0) {
        // 金币章节但未解锁
        return json(['code' => 403, 'msg' => '该章节需要购买，请先解锁']);
    }
    // 免费章节或已解锁/全免都能看

    // 查图片表
    $images = Db::name('comic_images')
        ->where('chapter_id', (int)$chapterId)
        ->order('sort', 'asc')
        ->column('img_url');

    if (empty($images)) {
        return json(['code' => 1, 'msg' => '该章节没有图片']);
    }

    $domain = rtrim(request()->domain(), '/');
    foreach ($images as &$img) {
        if (!preg_match('/^https?:\/\//', $img)) {
            $img = $domain . $img;
        }
    }

    return json([
        'code' => 0,
        'msg'  => 'ok',
        'data' => [
            'images' => $images
        ]
    ]);
}

    public function chapterDetail($id)
    {
        try {
            $chapter = Db::name('comic_chapters')->where('id', (int)$id)->find();
            if (!$chapter) {
                return json(['code' => 1, 'msg' => '章节未找到']);
            }
            return json(['code' => 0, 'msg' => 'success', 'data' => $chapter]);
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '获取章节详情失败：' . $e->getMessage()]);
        }
    }

    public function chapterAdd()
{
    $data = Request::param();

    $validate = new Validate([
        'manga_id|漫画ID' => 'require|integer',
        'title|章节标题' => 'require|max:100',
        'order_num|章节序号' => 'require|integer',
        'is_vip|是否VIP' => 'in:0,1',
        'coin|金币数量' => 'integer|min:0',
        'status|状态' => 'in:0,1',
    ]);

    if (!$validate->check($data)) {
        return json(['code' => 1, 'msg' => $validate->getError()]);
    }

    try {
        $insertData = [
            'manga_id' => (int)$data['manga_id'],
            'title' => $data['title'],
            'order_num' => (int)$data['order_num'],
            'is_vip' => (int)($data['is_vip'] ?? 0),
            'coin' => (int)($data['coin'] ?? 0),
            'status' => (int)($data['status'] ?? 1),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $result = Db::name('comic_chapters')->insert($insertData);

        if ($result) {
            // 新增章节成功后，漫画表 chapter_count +1
            Db::name('comic_manga')
                ->where('id', $insertData['manga_id'])
                ->inc('chapter_count')
                ->update();

            return json(['code' => 0, 'msg' => '新增章节成功']);
        } else {
            return json(['code' => 1, 'msg' => '新增章节失败']);
        }
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
    }
}


    public function chapterUpdate()
    {
        $data = Request::param();

        $validate = new Validate([
            'id|章节ID' => 'require|integer',
            'manga_id|漫画ID' => 'require|integer',
            'title|章节标题' => 'require|max:100',
            'order_num|章节序号' => 'require|integer',
            'is_vip|是否VIP' => 'in:0,1',
            'coin|金币数量' => 'integer|min:0',
            'status|状态' => 'in:0,1',
        ]);

        if (!$validate->check($data)) {
            return json(['code' => 1, 'msg' => $validate->getError()]);
        }

        $chapterId = (int)$data['id'];
        $chapter = Db::name('comic_chapters')->where('id', $chapterId)->find();
        if (!$chapter) {
            return json(['code' => 1, 'msg' => '章节未找到']);
        }

        try {
            $updateData = [
                'manga_id' => (int)$data['manga_id'],
                'title' => $data['title'],
                'order_num' => (int)$data['order_num'],
                'is_vip' => (int)($data['is_vip'] ?? 0),
                'coin' => (int)($data['coin'] ?? 0),
                'status' => (int)($data['status'] ?? 1),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $result = Db::name('comic_chapters')->where('id', $chapterId)->update($updateData);

            if ($result !== false) {
                return json(['code' => 0, 'msg' => '更新章节成功']);
            } else {
                return json(['code' => 1, 'msg' => '更新章节失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    public function chapterDelete()
{
    $param = Request::param();
    $chapterId = $param['id'] ?? null;

    if (empty($chapterId)) {
        return json(['code' => 1, 'msg' => 'ID为必填项']);
    }

    $chapter = Db::name('comic_chapters')->where('id', (int)$chapterId)->find();
    if (!$chapter) {
        return json(['code' => 1, 'msg' => '章节未找到']);
    }

    try {
        $result = Db::name('comic_chapters')->where('id', (int)$chapterId)->delete();

        if ($result) {
            // 重算该漫画的章节数
            $mangaId = $chapter['manga_id'];
            $newCount = Db::name('comic_chapters')->where('manga_id', $mangaId)->count();
            Db::name('comic_manga')->where('id', $mangaId)->update(['chapter_count' => $newCount]);
            return json(['code' => 0, 'msg' => '删除章节成功']);
        } else {
            return json(['code' => 1, 'msg' => '删除章节失败']);
        }
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
    }
}

    public function chapterBatchDelete()
{
    $param = Request::param();
    $ids = $param['ids'] ?? [];

    if (empty($ids) || !is_array($ids)) {
        return json(['code' => 1, 'msg' => 'ID列表为必填项且必须是数组']);
    }

    // 查出所有漫画id，去重
    $chapterList = Db::name('comic_chapters')->whereIn('id', $ids)->column('manga_id');
    $mangaIds = array_unique($chapterList);

    try {
        $result = Db::name('comic_chapters')->whereIn('id', $ids)->delete();

        if ($result) {
            // 对每个漫画id都重算
            foreach ($mangaIds as $mangaId) {
                $count = Db::name('comic_chapters')->where('manga_id', $mangaId)->count();
                Db::name('comic_manga')->where('id', $mangaId)->update(['chapter_count' => $count]);
            }
            return json(['code' => 0, 'msg' => '批量删除章节成功']);
        } else {
            return json(['code' => 1, 'msg' => '批量删除章节失败或没有找到要删除的章节']);
        }
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
    }
}


    public function chapterBatchUpdateSort()
    {
        $param = Request::param();
        $sortData = $param['sort_data'] ?? [];

        if (empty($sortData) || !is_array($sortData)) {
            return json(['code' => 1, 'msg' => '排序数据为必填项且必须是数组']);
        }

        try {
            Db::startTrans();
            
            foreach ($sortData as $item) {
                if (!isset($item['id']) || !isset($item['order_num'])) {
                    continue;
                }
                
                Db::name('comic_chapters')
                    ->where('id', (int)$item['id'])
                    ->update([
                        'order_num' => (int)$item['order_num'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }
            
            Db::commit();
            return json(['code' => 0, 'msg' => '批量排序成功']);
            
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    public function setAllChaptersVipByMangaId()
    {
        $param = Request::param();
        $mangaId = $param['manga_id'] ?? null;
        $isVip = $param['is_vip'] ?? null;

        if (empty($mangaId) || !isset($isVip) || ($isVip !== 0 && $isVip !== 1)) {
            return json(['code' => 1, 'msg' => '漫画ID和有效VIP状态为必填项']);
        }

        try {
            $updateData = ['is_vip' => (int)$isVip, 'updated_at' => date('Y-m-d H:i:s')];
            $result = Db::name('comic_chapters')->where('manga_id', (int)$mangaId)->update($updateData);

            if ($result !== false) {
                return json(['code' => 0, 'msg' => '设置所有章节VIP状态成功']);
            } else {
                return json(['code' => 1, 'msg' => '设置所有章节VIP状态失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    public function setAllChaptersCoinByMangaId()
    {
        $param = Request::param();
        $mangaId = $param['manga_id'] ?? null;
        $coin = $param['coin'] ?? null;

        if (empty($mangaId) || !isset($coin) || !is_numeric($coin) || (int)$coin < 0) {
            return json(['code' => 1, 'msg' => '漫画ID和有效金币数量为必填项']);
        }

        try {
            $updateData = ['coin' => (int)$coin, 'updated_at' => date('Y-m-d H:i:s')];
            $result = Db::name('comic_chapters')->where('manga_id', (int)$mangaId)->update($updateData);

            if ($result !== false) {
                return json(['code' => 0, 'msg' => '设置所有章节金币成功']);
            } else {
                return json(['code' => 1, 'msg' => '设置所有章节金币失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }

    public function batchSetChapterFree()
    {
        $param = Request::param();
        $ids = $param['ids'] ?? [];

        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 1, 'msg' => 'ID列表为必填项且必须是数组']);
        }

        try {
            $updateData = [
                'is_vip' => 0,
                'coin' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            $result = Db::name('comic_chapters')->whereIn('id', $ids)->update($updateData);

            if ($result !== false) {
                return json(['code' => 0, 'msg' => '批量设置章节免费成功']);
            } else {
                return json(['code' => 1, 'msg' => '批量设置章节免费失败']);
            }
        } catch (\Exception $e) {
            return json(['code' => 1, 'msg' => '服务器错误：' . $e->getMessage()]);
        }
    }
    /**
 * 漫画排行榜
 * @param string $action 排行类型 view/like/collect
 * @param string $range 排行区间 day/week/month/year
 * @param int $page
 * @param int $pageSize
 */
public function rankList()
{
    $param = request()->param();

    // 排行类型: view(人气榜)、like(点赞榜)、collect(收藏榜)
    $action = $param['action'] ?? 'view';
    if (!in_array($action, ['view', 'like', 'collect'])) {
        return json(['code' => 1, 'msg' => '非法的榜单类型']);
    }

    // 排行时间区间: day, week, month, year
    $range = $param['range'] ?? 'day';
    $now = time();
    switch ($range) {
        case 'day':
            $startTime = strtotime(date('Y-m-d 00:00:00', $now));
            break;
        case 'week':
            $startTime = strtotime('this week', $now);
            break;
        case 'month':
            $startTime = strtotime(date('Y-m-01 00:00:00', $now));
            break;
        case 'year':
            $startTime = strtotime(date('Y-01-01 00:00:00', $now));
            break;
        default:
            $startTime = strtotime(date('Y-m-d 00:00:00', $now));
    }

    $page = max(1, intval($param['page'] ?? 1));
    $pageSize = max(1, intval($param['pageSize'] ?? 10));

    // 聚合统计榜单
    $trackTable = 'video_track';
    $mangaTable = 'comic_manga';

    // 1. 统计topN漫画id
    $list = \think\facade\Db::name($trackTable)
        ->field('video_id, COUNT(*) as num')
        ->where('type', 'comic')
        ->where('action', $action)
        ->where('create_time', '>=', date('Y-m-d H:i:s', $startTime))
        ->group('video_id')
        ->order('num', 'desc')
        ->limit(($page-1)*$pageSize, $pageSize)
        ->select()
        ->toArray();

    if (!$list) {
        return json(['code' => 0, 'msg' => 'success', 'data' => ['list' => [], 'total' => 0]]);
    }

    // 2. 拿到所有id
    $ids = array_column($list, 'video_id');
    // 3. 一次性查出基础信息
    $mangas = \think\facade\Db::name($mangaTable)
        ->whereIn('id', $ids)
        ->select()
        ->toArray();

    // === 修复：防止 $mangas 不是数组 ===
    if (empty($mangas) || !is_array($mangas)) {
        $mangas = [];
    }

    // === 修复：批量查所有用到的tag id => name ===
    $allTagIds = [];
    foreach ($mangas as $row) {
        if (!empty($row['tags'])) {
            $tids = is_string($row['tags']) ? explode(',', $row['tags']) : $row['tags'];
            // 确保 $tids 是数组
            if (is_array($tids)) {
                $allTagIds = array_merge($allTagIds, $tids);
            }
        }
    }
    $allTagIds = array_unique(array_filter($allTagIds));
    $tagMap = [];
    if ($allTagIds) {
        $tagMap = \think\facade\Db::name('comic_tags')->whereIn('id', $allTagIds)->column('name', 'id');
    }

    // 获取域名用于补全封面URL
    $domain = rtrim(request()->domain(), '/');

    // 处理 tags 字段为名字
    $mangaMap = [];
    foreach ($mangas as $row) {
        // 修复：确保 tags 字段存在且正确处理
        $tids = [];
        if (!empty($row['tags'])) {
            if (is_string($row['tags'])) {
                $tids = explode(',', $row['tags']);
            } elseif (is_array($row['tags'])) {
                $tids = $row['tags'];
            }
        }
        
        // 确保 $tids 是数组
        if (!is_array($tids)) {
            $tids = [];
        }
        
        $names = [];
        foreach ($tids as $tid) {
            if (isset($tagMap[$tid])) {
                $names[] = $tagMap[$tid];
            }
        }
        $row['tags'] = $names;

        // 补全封面URL
        if (!empty($row['cover']) && !preg_match('/^https?:\/\//', $row['cover'])) {
            // 保证有 /
            if ($row['cover'][0] !== '/') {
                $row['cover'] = '/' . $row['cover'];
            }
            $row['cover'] = $domain . $row['cover'];
        }

        $mangaMap[$row['id']] = $row;
    }

    // 4. 组装榜单数据
    $result = [];
    foreach ($list as $item) {
        $manga = $mangaMap[$item['video_id']] ?? null;
        if (!$manga) continue;
        $result[] = [
            'id'    => $manga['id'],
            'title' => $manga['name'],
            'cover' => $manga['cover'],
            'tags'  => $manga['tags'],
            'views' => intval($manga['views'] ?? 0),
            'likes' => intval($manga['likes'] ?? 0),
            'collects' => intval($manga['collects'] ?? 0),
            'num'   => intval($item['num']), //当前榜单统计数
        ];
    }

    // 5. 榜单总数（用于分页）
    $total = \think\facade\Db::name($trackTable)
        ->where('type', 'comic')
        ->where('action', $action)
        ->where('create_time', '>=', date('Y-m-d H:i:s', $startTime))
        ->group('video_id')
        ->count();

    return json([
        'code' => 0,
        'msg' => 'success',
        'data' => [
            'list' => $result,
            'total' => $total
        ]
    ]);
}
/**
 * 获取最新更新的漫画（只显示连载中）
 * 移除今日逻辑，只保留最新连载中的漫画
 */
public function dailyUpdates()
{
    $param = Request::param();
    
    $page = (int)($param['page'] ?? 1);
    $pageSize = (int)($param['page_size'] ?? $param['pageSize'] ?? 15);
    
    try {
        // 🔥 只显示连载中的漫画：添加 is_serializing = 1 条件
        $query = Db::name('comic_manga')
            ->where('status', 1) // 正常状态
            ->where('is_shelf', 1) // 上架状态
            ->where('is_serializing', 1) // 🔥 只显示连载中的漫画
            ->order('updated_at', 'desc') // 按更新时间倒序
            ->order('id', 'desc'); // 相同更新时间时按ID倒序
            
        $total = $query->count();
        $list = $query->page($page, $pageSize)
                     ->select()
                     ->toArray();
                     
        // 处理返回数据
        $domain = rtrim(request()->domain(), '/');
        foreach ($list as &$item) {
            // 处理tags
            if (is_array($item['tags'])) {
                $item['tags'] = implode(',', $item['tags']);
            }
            // 补全封面URL
            if (!empty($item['cover']) && !preg_match('/^https?:\/\//', $item['cover'])) {
                if ($item['cover'][0] !== '/') {
                    $item['cover'] = '/' . $item['cover'];
                }
                $item['cover'] = $domain . $item['cover'];
            }
            if (!isset($item['chapter_count'])) {
                $item['chapter_count'] = 0;
            }
            
            // 显示真实的更新时间和信息
            $item['update_date'] = date('Y-m-d', strtotime($item['updated_at']));
            $item['chapter_info'] = "第{$item['chapter_count']}话";
            $item['last_update_date'] = date('Y-m-d', strtotime($item['updated_at']));
        }
        unset($item);
        
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total
            ]
        ]);
        
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '获取最新更新失败：' . $e->getMessage()]);
    }
}
/**
 * 按指定星期获取漫画（简化版）
 * 只支持周一到周五（1-5）
 */
public function weeklyUpdates()
{
    $param = Request::param();
    
    $updateDay = (int)($param['update_day'] ?? 1); // 1-5 对应周一到周五
    $page = (int)($param['page'] ?? 1);
    $pageSize = (int)($param['page_size'] ?? $param['pageSize'] ?? 15);
    
    // 验证只允许工作日
    if ($updateDay < 1 || $updateDay > 5) {
        return json(['code' => 1, 'msg' => '只支持周一到周五的查询']);
    }
    
    try {
        // 直接查询指定星期的漫画
        $query = Db::name('comic_manga')
            ->where('update_day', $updateDay)
            ->where('status', 1)
            ->where('is_shelf', 1);
            
        $total = $query->count();
        $list = $query->order('sort', 'desc')
                     ->order('id', 'desc')
                     ->page($page, $pageSize)
                     ->select()
                     ->toArray();
                     
        // 处理返回数据
        $domain = rtrim(request()->domain(), '/');
        foreach ($list as &$item) {
            // 处理tags
            if (is_array($item['tags'])) {
                $item['tags'] = implode(',', $item['tags']);
            }
            // 补全封面URL
            if (!empty($item['cover']) && !preg_match('/^https?:\/\//', $item['cover'])) {
                if ($item['cover'][0] !== '/') {
                    $item['cover'] = '/' . $item['cover'];
                }
                $item['cover'] = $domain . $item['cover'];
            }
            if (!isset($item['chapter_count'])) {
                $item['chapter_count'] = 0;
            }
            
            // 添加模拟的更新信息
            $weekdays = ['', '周一', '周二', '周三', '周四', '周五'];
            $item['update_date'] = date('Y-m-d');
            $item['chapter_info'] = "第{$item['chapter_count']}话";
            $item['last_update_date'] = date('Y-m-d');
        }
        unset($item);
        
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total
            ]
        ]);
        
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '获取周更新漫画失败：' . $e->getMessage()]);
    }
}

/**
 * 获取本周所有更新的漫画（新增方法）
 * 汇总周一到周五的所有漫画
 */
public function weeklyAllUpdates()
{
    $param = Request::param();
    
    $page = (int)($param['page'] ?? 1);
    $pageSize = (int)($param['page_size'] ?? $param['pageSize'] ?? 15);
    
    try {
        // 查询所有工作日的漫画（1-5）
        $query = Db::name('comic_manga')
            ->whereIn('update_day', [1, 2, 3, 4, 5])
            ->where('status', 1)
            ->where('is_shelf', 1);
            
        $total = $query->count();
        $list = $query->order('update_day', 'asc') // 按更新日排序
                     ->order('sort', 'desc')
                     ->order('id', 'desc')
                     ->page($page, $pageSize)
                     ->select()
                     ->toArray();
                     
        // 处理返回数据
        $domain = rtrim(request()->domain(), '/');
        $weekdays = ['', '周一', '周二', '周三', '周四', '周五'];
        
        foreach ($list as &$item) {
            // 处理tags
            if (is_array($item['tags'])) {
                $item['tags'] = implode(',', $item['tags']);
            }
            // 补全封面URL
            if (!empty($item['cover']) && !preg_match('/^https?:\/\//', $item['cover'])) {
                if ($item['cover'][0] !== '/') {
                    $item['cover'] = '/' . $item['cover'];
                }
                $item['cover'] = $domain . $item['cover'];
            }
            if (!isset($item['chapter_count'])) {
                $item['chapter_count'] = 0;
            }
            
            // 添加更新信息，包含星期几
            $updateDayName = $weekdays[$item['update_day']] ?? '未知';
            $item['update_date'] = date('Y-m-d');
            $item['chapter_info'] = "第{$item['chapter_count']}话";
            $item['update_day_name'] = $updateDayName; // 额外字段，显示更新星期
        }
        unset($item);
        
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => $total
            ]
        ]);
        
    } catch (\Exception $e) {
        return json(['code' => 1, 'msg' => '获取本周更新失败：' . $e->getMessage()]);
    }
}
}