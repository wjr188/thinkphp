<?php
namespace app\model;

use think\Model;

class LongHomeRecommend extends Model
{
    // 设置当前模型对应的完整数据表名称
    protected $name = 'long_home_recommend'; // 对应 long_home_recommend 表

    // 开启自动写入时间戳字段
    // 根据你的数据库字段类型来选择：
    // 如果是 INT(11) 存储 Unix 时间戳，则保持 protected $autoWriteTimestamp = true;
    // 如果是 DATETIME 存储 'YYYY-MM-DD HH:MM:SS' 格式，则设置为 protected $autoWriteTimestamp = 'datetime';
    protected $autoWriteTimestamp = 'datetime'; // 假设你的数据库 time 字段是 DATETIME 类型
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 重要：如果 long_home_recommend 表不再有 category_id 字段，
    // 或者即使有但你不再希望它作为业务主线，就移除此关联。
    // 因为现在 long_home_recommend 是独立的推荐分组，不再强依赖 LongVideoCategory。
    // public function category()
    // {
    //     return $this->hasOne(LongVideoCategory::class, 'id', 'category_id');
    // }

    // 移除所有原有的、基于 category_id 的静态方法，例如：
    // getRecommendedList()
    // addRecommend()
    // removeRecommend()
    // updateSortOrder()
    // reorderSort()
    // 这些业务逻辑现在都将由 Controller 直接调用 Model 的基本 ORM 方法完成。
}