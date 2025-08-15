<?php
namespace app\controller\Api;

use app\BaseController;
use think\facade\Db;
use think\facade\Request;

class SearchController extends BaseController
{
    /**
     * 获取热门搜索关键词（前台用）
     * @param string $type 支持 comic/novel/video/audio/anime/all
     * @param int $limit 取多少个，默认10
     */
    public function hotKeywords()
    {
        $type = Request::param('type', 'all'); // 默认all类型
        $limit = (int)Request::param('limit', 10);

        $where = [['status', '=', 1]];
        if ($type !== 'all') {
            $where[] = ['type', 'in', [$type, 'all']]; // type=all 也能被用到
        }

        $list = Db::name('search_hot_keywords')
            ->where($where)
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->limit($limit)
            ->field('id,keyword,type,status,sort,heat')
            ->select()
            ->toArray();

        return json(['code' => 0, 'msg' => 'success', 'data' => $list]);
    }

    /**
     * 后台管理接口 - 获取列表
     * 支持分页和类型筛选
     */
    public function hotKeywordList()
    {
        $type = Request::param('type', 'all');
        $query = Db::name('search_hot_keywords');

        if ($type !== 'all') {
            $query->where('type', $type);
        }

        $list = $query->order('sort', 'desc')
                      ->order('id', 'asc')
                      ->select()
                      ->toArray();

        return json(['code' => 0, 'msg' => 'success', 'data' => ['list' => $list]]);
    }

    /**
     * 新增关键词
     */
    public function addHotKeyword()
    {
        $data = Request::post();

        if (empty($data['keyword']) || empty($data['type'])) {
            return json(['code' => 1, 'msg' => '关键词和类型不能为空']);
        }

        $data['status'] = $data['status'] ?? 1;
        $data['sort'] = $data['sort'] ?? 0;
        $data['heat'] = $data['heat'] ?? 0; // 默认热度为0

        $res = Db::name('search_hot_keywords')->insert($data);
        if ($res) {
            return json(['code' => 0, 'msg' => '添加成功']);
        } else {
            return json(['code' => 1, 'msg' => '添加失败']);
        }
    }

    /**
     * 更新关键词
     */
    public function updateHotKeyword()
    {
        $data = Request::post();
        if (empty($data['id'])) {
            return json(['code' => 1, 'msg' => '缺少关键词ID']);
        }
        $id = $data['id'];
        unset($data['id']);

        $res = Db::name('search_hot_keywords')->where('id', $id)->update($data);
        if ($res !== false) {
            return json(['code' => 0, 'msg' => '更新成功']);
        } else {
            return json(['code' => 1, 'msg' => '更新失败']);
        }
    }

    /**
     * 删除关键词
     */
    public function deleteHotKeyword()
    {
        $id = Request::post('id');
        if (empty($id)) {
            return json(['code' => 1, 'msg' => '缺少关键词ID']);
        }
        $res = Db::name('search_hot_keywords')->where('id', $id)->delete();
        if ($res) {
            return json(['code' => 0, 'msg' => '删除成功']);
        } else {
            return json(['code' => 1, 'msg' => '删除失败']);
        }
    }

    /**
     * 批量排序
     * 接收数据格式示例：
     * {
     *   list: [
     *     { id: 1, sort: 10 },
     *     { id: 2, sort: 20 }
     *   ]
     * }
     */
    public function sortHotKeyword()
    {
        $list = Request::post('list/a'); // 解析为数组
        if (empty($list) || !is_array($list)) {
            return json(['code' => 1, 'msg' => '排序列表不能为空']);
        }

        Db::startTrans();
        try {
            foreach ($list as $item) {
                if (isset($item['id']) && isset($item['sort'])) {
                    Db::name('search_hot_keywords')->where('id', $item['id'])->update(['sort' => $item['sort']]);
                }
            }
            Db::commit();
            return json(['code' => 0, 'msg' => '排序成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 1, 'msg' => '排序失败：' . $e->getMessage()]);
        }
    }
}
