<?php
namespace app\model; // <-- 确保命名空间与你的文件路径一致

use think\Model;

class LongVideoCategory extends Model
{
    protected $name = 'long_video_categories'; // 对应 long_video_categories 表

    protected $autoWriteTimestamp = 'timestamp';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 如果你有其他关联或方法，可以在这里定义

    /**
     * 获取指定ID的分类
     * @param int $id
     * @return \think\Model|null
     */
    public static function getCategoryById(int $id)
    {
        return self::find($id);
    }
}