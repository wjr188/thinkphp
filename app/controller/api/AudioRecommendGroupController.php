<?php
namespace app\controller\api;

use think\Request;
use think\facade\Db;
use app\BaseController;

class AudioRecommendGroupController extends BaseController
{
    // 推荐分组列表
    public function list(Request $request)
    {
        $keyword = $request->get('keyword', '');
        $query = Db::name('audio_recommend_group');
        if ($keyword !== '') {
            $query->whereLike('name', "%$keyword%");
        }
        $list = $query->order('sort', 'asc')->select()->toArray();
        foreach ($list as &$group) {
            $group['novel_count'] = Db::name('audio_recommend_group_novel')->where('group_id', $group['id'])->count();
        }
        return json(['code' => 0, 'msg' => 'success', 'data' => ['list' => $list, 'total' => count($list)]]);
    }

    // 新增分组
    public function add(Request $request)
    {
        $data = $request->post();
        if (empty($data['name'])) {
            return json(['code' => 1, 'msg' => '分组名称为必填项']);
        }
        $data['sort'] = $data['sort'] ?? 1;
        $data['status'] = $data['status'] ?? 1;
        $data['type'] = $data['type'] ?? 'audio';
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        $id = Db::name('audio_recommend_group')->insertGetId($data);
        if ($id) {
            return json(['code' => 0, 'msg' => '分组添加成功']);
        }
        return json(['code' => 1, 'msg' => '分组添加失败']);
    }

    // 更新分组
    public function update(Request $request, $id)
    {
        $data = $request->put();
        if (empty($id) || empty($data['name'])) {
            return json(['code' => 1, 'msg' => 'ID和分组名称为必填项']);
        }
        $data['update_time'] = date('Y-m-d H:i:s');
        $res = Db::name('audio_recommend_group')->where('id', $id)->update($data);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '分组更新成功']);
        }
        return json(['code' => 1, 'msg' => '分组更新失败']);
    }

    // 删除分组
    public function delete($id)
    {
        if (!$id) {
            return json(['code' => 1, 'msg' => 'ID为必填项']);
        }
        Db::name('audio_recommend_group_novel')->where('group_id', $id)->delete();
        $res = Db::name('audio_recommend_group')->where('id', $id)->delete();
        if ($res) {
            return json(['code' => 0, 'msg' => '分组删除成功']);
        }
        return json(['code' => 1, 'msg' => '分组删除失败']);
    }

    // 保存分组排序
    public function saveSort(Request $request)
    {
        $list = $request->post();
        if (!$list || !is_array($list)) {
            return json(['code' => 1, 'msg' => '排序数据为必填项']);
        }
        foreach ($list as $item) {
            if (isset($item['id']) && isset($item['sort'])) {
                Db::name('audio_recommend_group')->where('id', $item['id'])->update([
                    'sort' => $item['sort'],
                    'update_time' => date('Y-m-d H:i:s')
                ]);
            }
        }
        return json(['code' => 0, 'msg' => '排序保存成功']);
    }

    // 获取分组下有声小说列表
    public function novelList($groupId)
    {
        try {
            file_put_contents(runtime_path() . 'novel_list.log', "novelList called, groupId=$groupId\n", FILE_APPEND);
            $novelIds = Db::name('audio_recommend_group_novel')
                ->where('group_id', $groupId)
                ->order('sort', 'asc')
                ->column('audio_novel_id');
            if (empty($novelIds)) {
                return json(['code' => 0, 'msg' => 'success', 'data' => []]);
            }
            $novels = Db::name('audio_novels')
                ->whereIn('id', $novelIds)
                ->field('id as audio_novel_id, title, cover_url, sort')
                ->select()
                ->toArray();
            // 只返回存在的小说
            $novels = array_column($novels, null, 'audio_novel_id');
            $result = [];
            foreach ($novelIds as $idx => $nid) {
                if (isset($novels[$nid])) {
                    $novels[$nid]['sort'] = $idx + 1;
                    $result[] = $novels[$nid];
                }
            }
            return json(['code' => 0, 'msg' => 'success', 'data' => $result]);
        } catch (\Throwable $e) {
            file_put_contents(runtime_path() . 'novel_list_error.log', $e->getMessage() . "\n" . $e->getTraceAsString(), FILE_APPEND);
            return json(['code'=>500, 'msg'=>'服务器内部错误: ' . $e->getMessage()]);
        }
    }

    // 保存分组下有声小说
    public function saveNovels(Request $request, $groupId)
    {
        file_put_contents(runtime_path() . 'save_novels.log', date('Y-m-d H:i:s') . " saveNovels called, groupId=$groupId\n", FILE_APPEND);
        $audioNovels = $request->post('audio_novels');
        if (!is_array($audioNovels)) {
            return json(['code' => 1, 'msg' => 'audio_novels为必填项']);
        }

        // 1. 查找所有其它分组已选的 audio_novel_id
        $otherGroupIds = Db::name('audio_recommend_group_novel')
            ->where('group_id', '<>', $groupId)
            ->column('audio_novel_id');

        $conflictIds = [];
        foreach ($audioNovels as $item) {
            if (in_array($item['audio_novel_id'], $otherGroupIds)) {
                $conflictIds[] = $item['audio_novel_id'];
            }
        }
        if (!empty($conflictIds)) {
            // 你可以返回冲突的小说ID，也可以查出title返回
            $titles = Db::name('audio_novels')->whereIn('id', $conflictIds)->column('title');
            return json([
                'code' => 1,
                'msg' => '以下有声小说已被其他分组选中，不能重复添加: ' . implode('、', $titles)
            ]);
        }

        // 2. 先删后插
        Db::name('audio_recommend_group_novel')->where('group_id', $groupId)->delete();
        foreach ($audioNovels as $item) {
            if (!isset($item['audio_novel_id'])) continue;
            Db::name('audio_recommend_group_novel')->insert([
                'group_id' => $groupId,
                'audio_novel_id' => $item['audio_novel_id'],
                'sort' => $item['sort'] ?? 0,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        }
        return json(['code' => 0, 'msg' => '有声小说保存成功']);
    }
        // 获取所有分组及分组下的有声小说列表（分页）
    public function allWithAudios(Request $request)
    {
        $page = max(1, intval($request->get('page', 1)));
        $pageSize = max(1, intval($request->get('pageSize', 2)));

        $total = Db::name('audio_recommend_group')
            ->where('status', 1)
            ->count();

        $groups = Db::name('audio_recommend_group')
            ->where('status', 1)
            ->order('sort', 'asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        if (empty($groups)) {
            return json(['code'=>0, 'msg'=>'success', 'data'=>['groups'=>[], 'total'=>$total]]);
        }

        $groupIds = array_column($groups, 'id');
        // 查所有分组下的有声小说
        $groupAudios = Db::name('audio_recommend_group_novel')
            ->alias('gn')
            ->leftJoin('audio_novels n', 'gn.audio_novel_id = n.id')
            ->whereIn('gn.group_id', $groupIds)
            ->field([
                'gn.group_id',
                'n.id',
                'n.title as name',
                'n.cover_url as cover',
                'n.author',
                'n.tags',
                'n.views',
                'n.shelf_status',
                'n.chapter_count',         // 补上
        'n.serialization_status'   // 补上
            ])
            ->order('gn.sort', 'asc')
            ->select()
            ->toArray();

        // 封面补全
$host = $request->domain();
foreach ($groupAudios as &$audio) {
    if (!empty($audio['cover']) && stripos($audio['cover'], 'http') !== 0) {
        $audio['cover'] = rtrim($host, '/') . '/' . ltrim($audio['cover'], '/');
    }
    // 兼容 is_serializing
    if (isset($audio['serialization_status']) && !isset($audio['is_serializing'])) {
        $audio['is_serializing'] = $audio['serialization_status'];
    }
}
unset($audio);

        // 分组组装
        $groupAudioMap = [];
        foreach ($groupAudios as $audio) {
            $groupAudioMap[$audio['group_id']][] = $audio;
        }

        $result = [];
        foreach ($groups as $group) {
            $audiosList = isset($groupAudioMap[$group['id']]) ? $groupAudioMap[$group['id']] : [];
            $result[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'sort' => $group['sort'],
                'status' => $group['status'],
                'remark' => $group['remark'] ?? '',
                'created_at' => $group['create_time'],
                'updated_at' => $group['update_time'],
                'icon' => $group['icon'] ?? '',
                'layout_type' => $group['layout_type'] ?? '',
                // audios 字段：只取前 9 条
                'audios' => array_slice($audiosList, 0, 9),
            ];
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'groups' => $result,
                'total' => $total
            ]
        ]);
    }
    // 获取指定分组下有声小说（分页版）
    public function getGroupAudiosPaginated(Request $request, $groupId)
    {   
        $page = intval($request->get('page', 1));
        $pageSize = intval($request->get('pageSize', 15));
        $groupId = intval($groupId);

        $audios = Db::name('audio_recommend_group_novel')
            ->alias('gn')
            ->leftJoin('audio_novels n', 'gn.audio_novel_id = n.id')
            ->where('gn.group_id', $groupId)
            ->field([
                'n.id as id',
        'n.title as name',
        'n.cover_url as cover',
        'n.views',
        'n.shelf_status',
        'n.is_vip',
        'n.coin',
        'n.chapter_count',
        'n.serialization_status',
        'gn.sort'
            ])
            ->order('gn.sort', 'asc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        // 封面补全
        $host = $request->domain();
foreach ($audios as &$audio) {
    if (!empty($audio['cover']) && stripos($audio['cover'], 'http') !== 0) {
        $audio['cover'] = rtrim($host, '/') . '/' . ltrim($audio['cover'], '/');
    }
    if (isset($audio['serialization_status']) && !isset($audio['is_serializing'])) {
        $audio['is_serializing'] = $audio['serialization_status'];
    }
}
unset($audio);

        $total = Db::name('audio_recommend_group_novel')
            ->where('group_id', $groupId)
            ->count();

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'list' => $audios,
                'total' => $total
            ]
        ]);
    }

}