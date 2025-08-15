<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use app\BaseController;

class AudioNovelController extends BaseController
{
    // 获取有声小说列表（多条件分页筛选，标签兼容、封面补全、前端友好）
    public function list(Request $request)
    {
        $params = $request->get();
        $page = max(1, intval($params['page'] ?? 1));
        $pageSize = max(1, intval($params['pageSize'] ?? 10));
        $keyword = trim($params['keyword'] ?? '');
        $categoryId = '';
if (isset($params['category_id']) && $params['category_id'] !== '') {
    $categoryId = $params['category_id'];
} elseif (isset($params['categoryId']) && $params['categoryId'] !== '') {
    $categoryId = $params['categoryId'];
}

        // 标签兼容
        $tag = $params['tagId'] ?? $params['tag'] ?? '';
$tag = trim(strval($tag));
    $serializationStatus = $params['serializationStatus'] ?? '';
    $shelfStatus = $params['shelfStatus'] ?? '';
    $visibility = $params['visibility'] ?? '';

        // ---------- 查询1：总数 ----------
        $query = Db::name('audio_novels');

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('title', "%$keyword%")
                  ->whereOr('narrator', 'like', "%$keyword%")
                  ->whereOr('author', 'like', "%$keyword%")
                  ->whereOr('id', intval($keyword));
            });
        }
        if ($categoryId !== '' && $categoryId != 0) {
            $query->where('category_id', intval($categoryId));
        }
        // 标签兼容处理
        if ($tag !== '' && $tag !== '0') {
    $query->whereRaw("JSON_CONTAINS(tags, ?)", [json_encode((int)$tag)]);
}

        if ($serializationStatus !== '') {
            $query->where('serialization_status', intval($serializationStatus));
        }
        if ($shelfStatus !== '') {
            $query->where('shelf_status', intval($shelfStatus));
        }
        if ($visibility !== '') {
            $query->where('visibility', intval($visibility));
        }
        $total = $query->count();

        // ---------- 查询2：数据 ----------
        $query = Db::name('audio_novels');
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('title', "%$keyword%")
                  ->whereOr('narrator', 'like', "%$keyword%")
                  ->whereOr('author', 'like', "%$keyword%")
                  ->whereOr('id', intval($keyword));
            });
        }
        if ($categoryId !== '' && $categoryId != 0) {
            $query->where('category_id', intval($categoryId));
        }
        if ($tag !== '' && $tag !== '0') {
    $query->whereRaw("JSON_CONTAINS(tags, ?)", [json_encode((int)$tag)]);
}

        if ($serializationStatus !== '') {
            $query->where('serialization_status', intval($serializationStatus));
        }
        if ($shelfStatus !== '') {
            $query->where('shelf_status', intval($shelfStatus));
        }
        if ($visibility !== '') {
            $query->where('visibility', intval($visibility));
        }

        // 排序逻辑
        // 排序逻辑兼容
$orderBy = $params['orderBy'] ?? $params['sort'] ?? '';
$orderDir = $params['orderDir'] ?? 'desc';
switch ($orderBy) {
    case 'views':
        $query->order('views', $orderDir);
        break;
    case 'collects':
        $query->order('collects', $orderDir);
        break;
    case 'newest':
    case 'create_time':
        $query->order('create_time', 'desc');
        break;
    default:
        $query->order('id', 'desc');
}

// === 在这里加 field() ！！！ ===
$query->field([
    'id','title','narrator','cover_url','category_id','tags','chapter_count',
    'serialization_status','shelf_status','visibility','is_vip','coin','sort',
    'views','likes','collects'
]);
        $list = $query->page($page, $pageSize)->select()->toArray();

        // 补全封面、标签数组化、字段默认值兜底
        $domain = rtrim(request()->domain(), '/');
        foreach ($list as &$item) {
    $item['tags'] = $item['tags'] ? json_decode($item['tags'], true) : [];
            if (!empty($item['cover_url']) && !preg_match('/^https?:\/\//', $item['cover_url'])) {
                if ($item['cover_url'][0] !== '/') $item['cover_url'] = '/' . $item['cover_url'];
                $item['cover_url'] = $domain . $item['cover_url'];
            }
            // 字段兜底
            $item['views'] = $item['views'] ?? 0;
             $item['likes'] = $item['likes'] ?? 0; 
            $item['collects'] = $item['collects'] ?? 0;
            $item['is_vip'] = $item['is_vip'] ?? 0;
            $item['coin'] = $item['coin'] ?? 0;
            // 统计每本小说的章节数（chapter_count）
    $item['chapter_count'] = $item['chapter_count'] ?? 0;
        }
unset($item);
        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $list,
                'total' => intval($total)
            ]
        ]);
    }
    // 获取详情
public function detail(Request $request, $id = null)
{
    $id = $id ?? $request->get('id');
    if (!$id) {
        return json(['code' => 1, 'msg' => 'ID为必填项']);
    }
    // 只查需要的字段！！！
    $novel = Db::name('audio_novels')
    ->field([
        'id','title','narrator','cover_url','category_id','tags',
        'chapter_count','serialization_status','shelf_status','visibility',
        'is_vip','coin','views','likes','collects','publish_time'
    ])
    ->where('id', $id)
    ->find();
    if ($novel) {
        // cover_url 格式补全
        $domain = rtrim(request()->domain(), '/');
        if (!empty($novel['cover_url']) && !preg_match('/^https?:\/\//', $novel['cover_url'])) {
            if ($novel['cover_url'][0] !== '/') $novel['cover_url'] = '/' . $novel['cover_url'];
            $novel['cover_url'] = $domain . $novel['cover_url'];
        }
        // 兜底转类型
        $novel['is_vip'] = intval($novel['is_vip'] ?? 0);
        $novel['coin'] = intval($novel['coin'] ?? 0);
        $novel['views'] = intval($novel['views'] ?? 0);
        $novel['likes'] = intval($novel['likes'] ?? 0);
        $novel['collects'] = intval($novel['collects'] ?? 0);
        $novel['sort'] = intval($novel['sort'] ?? 0);
        $novel['tags'] = $novel['tags'] ? json_decode($novel['tags'], true) : [];
        return json(['code' => 0, 'msg' => 'success', 'data' => $novel]);
    }
    return json(['code' => 1, 'msg' => '有声小说未找到']);
}

   // 新增
public function add(Request $request)
{
    $data = $request->post();

    // 保证tags为json数组字符串
if (isset($data['tags']) && is_array($data['tags'])) {
    $data['tags'] = json_encode(array_map('intval', $data['tags']));
}


    $data['publish_time'] = date('Y-m-d H:i:s');
    $data['update_time'] = date('Y-m-d H:i:s');
    $id = Db::name('audio_novels')->insertGetId($data);

    if ($id) {
        return json(['code' => 0, 'msg' => '新增成功']);
    }
    return json(['code' => 1, 'msg' => '新增失败']);
}

// 更新
public function update(Request $request)
{
    $data = $request->post();

    if (empty($data['id'])) {
        return json(['code' => 1, 'msg' => 'ID为必填项']);
    }

    // 保证tags为逗号分隔字符串
    // 保证tags为json数组字符串
if (isset($data['tags']) && is_array($data['tags'])) {
    $data['tags'] = json_encode(array_map('intval', $data['tags']));
}


    $data['update_time'] = date('Y-m-d H:i:s');
    $res = Db::name('audio_novels')->where('id', $data['id'])->update($data);

    if ($res !== false) {
        return json(['code' => 0, 'msg' => '更新成功']);
    }
    return json(['code' => 1, 'msg' => '更新失败']);
}

    // 删除
    public function delete(Request $request)
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 1, 'msg' => 'ID为必填项']);
        }
        $res = Db::name('audio_novels')->where('id', $id)->delete();
        if ($res) {
            return json(['code' => 0, 'msg' => '删除成功']);
        }
        return json(['code' => 1, 'msg' => '删除失败']);
    }

    // 批量删除
    public function batchDelete(Request $request)
    {
        $ids = $request->post('ids');
        if (!$ids || !is_array($ids)) {
            return json(['code' => 1, 'msg' => 'ID列表为必填项且必须是数组']);
        }
        $res = Db::name('audio_novels')->whereIn('id', $ids)->delete();
        if ($res) {
            return json(['code' => 0, 'msg' => '批量删除成功']);
        }
        return json(['code' => 1, 'msg' => '批量删除失败']);
    }

    // 批量设置连载状态
    public function batchSetSerializationStatus(Request $request)
    {
        $ids = $request->post('ids');
        $status = $request->post('status');
        if (!$ids || !is_array($ids) || $status === null) {
            return json(['code' => 1, 'msg' => 'ID列表和状态为必填项']);
        }
        $res = Db::name('audio_novels')->whereIn('id', $ids)->update(['serialization_status' => $status, 'update_time' => date('Y-m-d H:i:s')]);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '批量设置连载状态成功']);
        }
        return json(['code' => 1, 'msg' => '批量设置连载状态失败']);
    }

    // 批量设置上架状态
    public function batchSetShelfStatus(Request $request)
    {
        $ids = $request->post('ids');
        $status = $request->post('status');
        if (!$ids || !is_array($ids) || $status === null) {
            return json(['code' => 1, 'msg' => 'ID列表和状态为必填项']);
        }
        $res = Db::name('audio_novels')->whereIn('id', $ids)->update(['shelf_status' => $status, 'update_time' => date('Y-m-d H:i:s')]);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '批量设置上架状态成功']);
        }
        return json(['code' => 1, 'msg' => '批量设置上架状态失败']);
    }

    // 批量设置可见性
    public function batchSetVisibility(Request $request)
    {
        $ids = $request->post('ids');
        $visibility = $request->post('visibility');
        if (!$ids || !is_array($ids) || $visibility === null) {
            return json(['code' => 1, 'msg' => 'ID列表和可见性状态为必填项']);
        }
        $res = Db::name('audio_novels')->whereIn('id', $ids)->update(['visibility' => $visibility, 'update_time' => date('Y-m-d H:i:s')]);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '批量设置可见性成功']);
        }
        return json(['code' => 1, 'msg' => '批量设置可见性失败']);
    }

   // 批量设置VIP（所有选中小说和其所有章节都设置为VIP）
    public function batchSetVip(Request $request)
    {
        $ids = $request->post('ids');
        $isVip = $request->post('is_vip');
        if (!$ids || !is_array($ids) || $isVip === null) {
            return json(['code' => 1, 'msg' => 'ID列表和VIP状态为必填项']);
        }

        // 1. 主表同步
        Db::name('audio_novels')->whereIn('id', $ids)->update([
            'is_vip' => $isVip,
            'update_time' => date('Y-m-d H:i:s')
        ]);
        // 2. 章节表同步
        Db::name('audio_novel_chapter')->whereIn('novel_id', $ids)->update([
            'is_vip' => $isVip,
            'update_time' => date('Y-m-d H:i:s')
        ]);
        return json(['code' => 0, 'msg' => '批量设置VIP成功']);
    }

    // 批量取消VIP（所有选中小说和章节都设置为免费）
    public function batchCancelVip(Request $request)
    {
        $ids = $request->post('ids');
        if (!$ids || !is_array($ids)) {
            return json(['code' => 1, 'msg' => 'ID列表为必填项']);
        }

        // 1. 主表同步
        Db::name('audio_novels')->whereIn('id', $ids)->update([
            'is_vip' => 0,
            'update_time' => date('Y-m-d H:i:s')
        ]);
        // 2. 章节表同步
        Db::name('audio_novel_chapter')->whereIn('novel_id', $ids)->update([
            'is_vip' => 0,
            'update_time' => date('Y-m-d H:i:s')
        ]);
        return json(['code' => 0, 'msg' => '批量取消VIP成功']);
    }

    // 批量设置金币（所有选中小说和其章节都同步金币）
    public function batchSetCoin(Request $request)
    {
        $ids = $request->post('ids');
        $coin = $request->post('coin');
        if (!$ids || !is_array($ids) || $coin === null) {
            return json(['code' => 1, 'msg' => 'ID列表和金币为必填项']);
        }

        // 1. 主表同步
        Db::name('audio_novels')->whereIn('id', $ids)->update([
            'coin' => intval($coin),
            'update_time' => date('Y-m-d H:i:s')
        ]);
        // 2. 章节表同步
        Db::name('audio_novel_chapter')->whereIn('novel_id', $ids)->update([
            'coin' => intval($coin),
            'update_time' => date('Y-m-d H:i:s')
        ]);
        return json(['code' => 0, 'msg' => '批量设置金币成功']);
    }

    // 批量设置演播人
    public function batchSetNarrator(Request $request)
    {
        $ids = $request->post('ids');
        $narrator = $request->post('narrator');
        if (!$ids || !is_array($ids) || !$narrator) {
            return json(['code' => 1, 'msg' => 'ID列表和演播人为必填项']);
        }
        $res = Db::name('audio_novels')->whereIn('id', $ids)->update(['narrator' => $narrator, 'update_time' => date('Y-m-d H:i:s')]);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '批量设置演播人成功']);
        }
        return json(['code' => 1, 'msg' => '批量设置演播人失败']);
    }
}