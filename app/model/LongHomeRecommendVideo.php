<?php
// 文件路径：E:\ThinkPHP6\app\model\LongHomeRecommendVideo.php
namespace app\model;

use think\Model;
use think\facade\Db; // 引入 Db facade 用于事务和复杂查询
use app\model\LongVideo; // 确保引入 LongVideo 模型，用于关联查询

class LongHomeRecommendVideo extends Model
{
    // 设置当前模型对应的完整数据表名称
    protected $name = 'long_home_recommend_video';

    // 设置主键名
    protected $pk = 'id';

    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = true;
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 定义与 LongVideo 模型的关联
     * 一个 LongHomeRecommendVideo 记录属于（belongsTo）一个 LongVideo
     * 关联关系：long_home_recommend_video.video_id => long_videos.id
     */
    public function video()
    {
        // belongsTo(关联模型, 外键, 主键)
        // 外键：当前模型的字段，用于关联的键（默认为 video_id）
        // 主键：关联模型的字段，用于被关联的键（默认为 id）
        return $this->belongsTo(LongVideo::class, 'video_id', 'id');
    }

    /**
     * 根据 recommend_id 获取推荐子分类下的所有视频列表
     * 使用 with('video') 关联查询视频基础信息
     *
     * @param int $recommendId long_home_recommend 表的 id
     * @return array 返回视频列表，每项包含本表数据和嵌套的 'video' 对象
     */
    public static function getVideoListByRecommendId(int $recommendId): array
    {
        if (empty($recommendId)) {
            return [];
        }

        try {
            $videoList = self::where('recommend_id', $recommendId)
                // 使用 with('video') 预加载 video 关联，并在闭包中指定只查询 video 表的特定字段
                ->with(['video' => function($query){
                    // 根据 long_videos 表的实际字段，选择您需要的视频信息
                    // 例如：'id', 'title', 'cover_url', 'duration', 'status' 等
                    $query->field('id, title, cover_url');
                }])
                ->order('sort', 'asc') // 按照排序字段升序
                ->select()
                ->toArray();

            // 过滤掉 'video' 关系为空的项（即关联的视频不存在或已被删除），并重新整理 sort 字段
            $finalList = [];
            foreach ($videoList as $index => $item) {
                // 确保关联的视频存在且标题不为空（title是视频的基础信息，不能为空）
                if (!empty($item['video']) && !empty($item['video']['title'])) {
                    // 如果需要将 video 的某些字段扁平化到当前层级，可以这样做：
                    $item['video_title'] = $item['video']['title'];
                    $item['video_cover_url'] = $item['video']['cover_url'] ?? ''; // 如果封面图可能为空

                    // 如果前端不需要完整的 video 对象，可以 unset 掉
                    // unset($item['video']);

                    $item['sort'] = $index + 1; // 重新赋值连续的排序值，确保前端拖拽排序时逻辑一致
                    $finalList[] = $item;
                }
            }

            return $finalList;

        } catch (\Exception $e) {
            trace('获取推荐视频列表失败: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * 新增一个推荐视频关联
     *
     * @param int $recommendId long_home_recommend 表的 id
     * @param int $videoId 视频ID
     * @param int $sort 排序值
     * @return array 包含 code 和 msg
     */
    public static function addRecommendVideo(int $recommendId, int $videoId, int $sort): array
    {
        if (empty($recommendId) || empty($videoId)) {
            return ['code' => 0, 'msg' => '参数缺失'];
        }

        // 检查是否已存在相同的 recommend_id 和 video_id 组合
        $exists = self::where('recommend_id', $recommendId)
                      ->where('video_id', $videoId)
                      ->find();
        if ($exists) {
            return ['code' => 0, 'msg' => '该视频已存在于此推荐分类下'];
        }

        // 检查 video_id 是否有效，即 long_videos 表中是否存在该视频
        // 通过 LongVideo 模型检查，确保视频ID的有效性
        $videoExists = LongVideo::where('id', $videoId)->count();
        if ($videoExists === 0) {
            return ['code' => 0, 'msg' => '视频ID不存在或无效'];
        }

        try {
            $data = [
                'recommend_id' => $recommendId,
                'video_id' => $videoId,
                'sort' => $sort,
                // create_time 和 update_time 由模型自动写入
            ];
            self::create($data); // 使用 create 方法新增数据，会自动填充时间戳

            return ['code' => 1, 'msg' => '新增推荐视频成功'];
        } catch (\Exception $e) {
            trace('新增推荐视频失败: ' . $e->getMessage(), 'error');
            return ['code' => 0, 'msg' => '新增推荐视频失败: ' . $e->getMessage()];
        }
    }

    /**
     * 批量保存推荐视频列表（先清空旧数据，再批量插入新数据）
     * 此方法内部处理事务，保证原子性
     *
     * @param int $recommendId long_home_recommend 表的 id
     * @param array $videoList 视频数据列表，格式：[['video_id' => 1, 'sort' => 1], ...]
     * @return array 包含 code 和 msg
     */
    public static function batchSaveRecommendVideos(int $recommendId, array $videoList): array
    {
        if (empty($recommendId)) {
            return ['code' => 0, 'msg' => 'recommend_id 参数缺失'];
        }

        $insertData = [];
        foreach ($videoList as $item) {
            if (!isset($item['video_id']) || !isset($item['sort']) || !is_numeric($item['video_id']) || !is_numeric($item['sort'])) {
                return ['code' => 0, 'msg' => '视频数据项格式不正确，每项需包含 video_id 和 sort 字段且为数字'];
            }
            // 校验 video_id 是否有效，避免插入不存在的视频ID
            $videoExists = LongVideo::where('id', $item['video_id'])->count();
            if ($videoExists === 0) {
                return ['code' => 0, 'msg' => "视频ID {$item['video_id']} 不存在或无效"];
            }

            $insertData[] = [
                'recommend_id' => $recommendId,
                'video_id'     => $item['video_id'],
                'sort'         => (int)$item['sort'], // 确保 sort 为整数
                'create_time'  => time(), // 手动添加时间戳以确保批量插入时生效
                'update_time'  => time(), // 手动添加时间戳以确保批量插入时生效
            ];
        }

        Db::startTrans(); // 开启事务
        try {
            // 1. 清空该 recommend_id 下的所有旧视频关联
            self::where('recommend_id', $recommendId)->delete();

            // 2. 批量插入新的视频关联
            if (!empty($insertData)) {
                self::insertAll($insertData); // 批量插入
            }

            Db::commit(); // 提交事务
            return ['code' => 1, 'msg' => '推荐视频列表保存成功'];

        } catch (\Exception $e) {
            Db::rollback(); // 回滚事务
            trace('批量保存推荐视频失败: ' . $e->getMessage(), 'error');
            return ['code' => 0, 'msg' => '批量保存推荐视频失败: ' . $e->getMessage()];
        }
    }

    /**
     * 删除一个推荐视频关联 (通过本表的主键ID)
     *
     * @param int $id long_home_recommend_video 表的主键ID
     * @return array 包含 code 和 msg
     */
    public static function deleteRecommendVideo(int $id): array
    {
        if (empty($id)) {
            return ['code' => 0, 'msg' => 'ID 参数缺失'];
        }

        try {
            $result = self::destroy($id); // 使用 destroy 方法删除

            if ($result) {
                return ['code' => 1, 'msg' => '删除推荐视频成功'];
            } else {
                return ['code' => 0, 'msg' => '推荐视频不存在或删除失败'];
            }
        } catch (\Exception $e) {
            trace('删除推荐视频失败: ' . $e->getMessage(), 'error');
            return ['code' => 0, 'msg' => '删除推荐视频失败: ' . $e->getMessage()];
        }
    }

    // 您可以根据需要添加其他方法，例如：
    // public static function updateRecommendVideoSort(int $id, int $newSort): array { ... }
}