<?php
declare(strict_types=1);

namespace app\controller\api;

use app\BaseController;
use think\facade\Db;
use think\facade\Request;
use think\facade\Validate;

class TextNovelController extends BaseController
{
    // 小说列表，支持分页、筛选
    public function list()
{
    $params = Request::get();
    $page = max(1, intval($params['page'] ?? 1));
    $pageSize = max(1, intval($params['pageSize'] ?? 10));
    $keyword = trim($params['keyword'] ?? '');
    $categoryId = $params['categoryId'] ?? '';
    // --------- 标签参数兼容处理 ----------
    $tag = $params['tagId'] ?? $params['tag'] ?? '';
    $tag = trim(strval($tag));
    $serializationStatus = $params['serializationStatus'] ?? '';
    $shelfStatus = $params['shelfStatus'] ?? '';
    $visibility = $params['visibility'] ?? '';

    // ---------- 查询1：总数 ----------
    $query = Db::name('text_novel')
        ->alias('n')
        ->leftJoin('text_novel_category c', 'n.category_id = c.id')
        ->field('n.*, c.name as category_name');

    if ($keyword !== '') {
        $query->where(function ($q) use ($keyword) {
            $q->whereLike('n.title', "%$keyword%")
              ->whereOr('n.author', 'like', "%$keyword%")
              ->whereOr('n.id', intval($keyword));
        });
    }
    if ($categoryId !== '' && $categoryId != 0) {
        $query->where('n.category_id', intval($categoryId));
    }
    // --------- 标签筛选（兼容0/空/无）----------
     // 过滤两边空格并逗号分隔
if ($tag !== '' && $tag !== '0') {
    // 精确匹配标签，使用 FIND_IN_SET
    $query->whereRaw("FIND_IN_SET(?, REPLACE(n.tags, ' ', '')) > 0", [$tag]);
}

    if ($serializationStatus !== '') {
        $query->where('n.serialization_status', intval($serializationStatus));
    }
    if ($shelfStatus !== '') {
        $query->where('n.shelf_status', intval($shelfStatus));
    }
    if ($visibility !== '') {
        $query->where('n.visibility', intval($visibility));
    }
    $total = $query->count();

    // ---------- 查询2：数据 ----------
    $query = Db::name('text_novel')
        ->alias('n')
        ->leftJoin('text_novel_category c', 'n.category_id = c.id')
        ->field('n.*, c.name as category_name');
    if ($keyword !== '') {
        $query->where(function ($q) use ($keyword) {
            $q->whereLike('n.title', "%$keyword%")
              ->whereOr('n.author', 'like', "%$keyword%")
              ->whereOr('n.id', intval($keyword));
        });
    }
    if ($categoryId !== '' && $categoryId != 0) {
        $query->where('n.category_id', intval($categoryId));
    }
    // --------- 标签筛选（兼容0/空/无）----------
     // 过滤两边空格并逗号分隔
if ($tag !== '' && $tag !== '0') {
    // 精确匹配标签，使用 FIND_IN_SET
    $query->whereRaw("FIND_IN_SET(?, REPLACE(n.tags, ' ', '')) > 0", [$tag]);
}

    if ($serializationStatus !== '') {
        $query->where('n.serialization_status', intval($serializationStatus));
    }
    if ($shelfStatus !== '') {
        $query->where('n.shelf_status', intval($shelfStatus));
    }
    if ($visibility !== '') {
        $query->where('n.visibility', intval($visibility));
    }
        // 排序逻辑（加在 where 后面，page前面）
    $orderBy = $params['orderBy'] ?? $params['sort'] ?? '';
$orderDir = $params['orderDir'] ?? 'desc';
switch ($orderBy) {
    case 'views':
        $query->order('n.views', $orderDir);
        break;
    case 'collects':
        $query->order('n.collects', $orderDir);
        break;
    case 'newest':      // 兼容 newest
    case 'create_time': // 前端传 newest、create_time 都能用
        $query->order('n.create_time', 'desc');
        break;
    default:
        $query->order('n.id', 'desc');
}

    $list = $query->page($page, $pageSize)->select()->toArray();


    // 格式化封面和标签
    $domain = rtrim(request()->domain(), '/');
    foreach ($list as &$item) {
        $item['tags'] = $item['tags'] ? explode(',', $item['tags']) : [];
        if (!empty($item['cover_url']) && !preg_match('/^https?:\/\//', $item['cover_url'])) {
            if ($item['cover_url'][0] !== '/') $item['cover_url'] = '/' . $item['cover_url'];
            $item['cover_url'] = $domain . $item['cover_url'];
        }
    }

    return json([
        'code' => 0,
        'msg' => 'success',
        'data' => ['list' => $list, 'total' => $total],
    ]);
}

    // 获取单本小说详情
    // 获取单本小说详情（支持 GET 传参）
public function read()
{
    $id = Request::get('id');   // ⭐这里才对
    if (!$id) return json(['code'=>1, 'msg'=>'缺少参数']);
    $novel = Db::name('text_novel')->find($id);
    if (!$novel) return json(['code'=>1, 'msg'=>'小说不存在']);
    $novel['tags'] = $novel['tags'] ? explode(',', $novel['tags']) : [];
    // 👇补全封面URL
    $domain = rtrim(request()->domain(), '/');
    if (!empty($novel['cover_url']) && !preg_match('/^https?:\/\//', $novel['cover_url'])) {
        if ($novel['cover_url'][0] !== '/') $novel['cover_url'] = '/' . $novel['cover_url'];
        $novel['cover_url'] = $domain . $novel['cover_url'];
    }
    return json(['code'=>0, 'data'=>$novel]);
}

    // 新增小说
    public function add()
    {
        $data = Request::post();
        $validate = Validate::rule([
            'title' => 'require',
            'category_id' => 'require|integer',
        ]);
        if (!$validate->check($data)) return json(['code'=>1, 'msg'=>$validate->getError()]);
        $data['tags'] = isset($data['tags']) && is_array($data['tags']) ? implode(',', $data['tags']) : '';
        $data['publish_time'] = $data['publish_time'] ?? date('Y-m-d H:i:s');
        $id = Db::name('text_novel')->insertGetId($data);
        return json(['code'=>0, 'msg'=>'添加成功', 'data'=>['id'=>$id]]);
    }

    // 更新小说
public function update()
{
    $data = Request::post();
    if (empty($data['id'])) return json(['code'=>1, 'msg'=>'缺少ID']);
    $data['tags'] = isset($data['tags']) && is_array($data['tags']) ? implode(',', $data['tags']) : '';
    
    Db::name('text_novel')->update($data);

    // 同步更新章节表VIP和金币
    $updateChapter = [];
    if (isset($data['is_vip'])) $updateChapter['is_vip'] = intval($data['is_vip']);
    if (isset($data['coin'])) $updateChapter['coin'] = intval($data['coin']);
    if ($updateChapter) {
        Db::name('text_novel_chapter')->where('novel_id', $data['id'])->update($updateChapter);
    }

    return json(['code'=>0, 'msg'=>'更新成功']);
}

    // 删除小说
    public function delete()
    {
        $id = Request::post('id');
        if (!$id) return json(['code'=>1, 'msg'=>'缺少ID']);
        Db::name('text_novel')->delete($id);
        // TODO: 同时删除章节表等关联数据
        return json(['code'=>0, 'msg'=>'删除成功']);
    }

    // 批量删除
    public function batchDelete()
    {
        $ids = Request::post('ids');
        if (empty($ids) || !is_array($ids)) return json(['code'=>1, 'msg'=>'参数错误']);
        Db::name('text_novel')->whereIn('id', $ids)->delete();
        // TODO: 同时删除章节表等关联数据
        return json(['code'=>0, 'msg'=>'批量删除成功']);
    }

    // 批量设置连载状态
    public function batchSetSerializationStatus()
    {
        $ids = Request::post('ids');
        $status = Request::post('status');
        if (!$ids || $status === null) return json(['code'=>1, 'msg'=>'参数错误']);
        Db::name('text_novel')->whereIn('id', $ids)->update(['serialization_status'=>intval($status)]);
        return json(['code'=>0, 'msg'=>'批量设置连载状态成功']);
    }

    // 批量设置上架状态
    public function batchSetShelfStatus()
    {
        $ids = Request::post('ids');
        $status = Request::post('status');
        if (!$ids || $status === null) return json(['code'=>1, 'msg'=>'参数错误']);
        Db::name('text_novel')->whereIn('id', $ids)->update(['shelf_status'=>intval($status)]);
        return json(['code'=>0, 'msg'=>'批量设置上架状态成功']);
    }

    // 批量设置可见性
    public function batchSetVisibility()
    {
        $ids = Request::post('ids');
        $visibility = Request::post('visibility');
        if (!$ids || $visibility === null) return json(['code'=>1, 'msg'=>'参数错误']);
        Db::name('text_novel')->whereIn('id', $ids)->update(['visibility'=>intval($visibility)]);
        return json(['code'=>0, 'msg'=>'批量设置可见性成功']);
    }
// 批量设置VIP（开启章节VIP付费）
public function batchSetVip()
{
    $ids = Request::post('ids'); // 小说id数组
    if (empty($ids) || !is_array($ids)) return json(['code'=>1, 'msg'=>'参数错误']);

    // 1. 小说主表全部设置为需要VIP
    Db::name('text_novel')->whereIn('id', $ids)->update(['is_vip' => 1]);  // 需要VIP

    // 2. 所有章节设置为需要VIP
    Db::name('text_novel_chapter')->whereIn('novel_id', $ids)->update(['is_vip' => 1]);

    return json(['code'=>0, 'msg'=>'批量设置章节为VIP成功']);
}

// 批量取消VIP（所有章节都免费）
public function batchCancelVip()
{
    $ids = Request::post('ids'); // 小说id数组
    if (empty($ids) || !is_array($ids)) return json(['code'=>1, 'msg'=>'参数错误']);

    // 1. 小说主表全部设置为免费
    Db::name('text_novel')->whereIn('id', $ids)->update(['is_vip' => 0]);  // 免费

    // 2. 所有章节设置为免费
    Db::name('text_novel_chapter')->whereIn('novel_id', $ids)->update(['is_vip' => 0]);

    return json(['code'=>0, 'msg'=>'批量取消章节VIP成功']);
}

// 批量设置金币
public function batchSetCoin()
{
    $ids = Request::post('ids'); // 小说id数组
    $coin = Request::post('coin'); // 金币数量
    if (empty($ids) || $coin === null) return json(['code'=>1, 'msg'=>'参数错误']);

    // 1. 更新小说主表，如果有对应字段
    Db::name('text_novel')->whereIn('id', $ids)->update(['coin' => intval($coin)]);

    // 2. 更新所有章节的金币
    Db::name('text_novel_chapter')->whereIn('novel_id', $ids)->update(['coin' => intval($coin)]);

    return json(['code'=>0, 'msg'=>'批量设置章节金币成功']);
}

}
