<?php
declare(strict_types=1);

namespace app\controller\api;

use think\facade\Db;

class PopupConfigController
{
    /**
     * 获取弹窗配置
     * GET /api/popup_config?popup_type=home
     */
    public function getConfig()
    {
        $popupType = request()->get('popup_type', 'home');

        // 查询指定类型、启用状态
        $rows = Db::name('popup_config')
            ->where('popup_type', $popupType)
            ->where('status', 1)
            ->order('sort_order')
            ->select();

       $configs = [];
foreach ($rows as $row) {
    $configs[] = [
        'id' => $row['id'],
        'popup_type' => $row['popup_type'],
        'key' => $row['config_key'],
        'value' => $row['config_value'] ? json_decode($row['config_value'], true) : [],
        'sort_order' => $row['sort_order'],
    ];
}

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => $configs
        ]);
    }

    /**
     * 保存配置（单条）
     * POST /api/popup_config/save
     * body: { id, config_value }
     */
    public function saveConfig()
    {
        $id = request()->post('id');
        $configValue = request()->post('config_value');

        if (!$id) {
            return json(['code' => 1, 'msg' => '缺少ID']);
        }

        $affected = Db::name('popup_config')
            ->where('id', $id)
            ->update([
                'config_value' => json_encode($configValue, JSON_UNESCAPED_UNICODE),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

        if ($affected !== false) {
            return json(['code' => 0, 'msg' => '保存成功']);
        } else {
            return json(['code' => 1, 'msg' => '保存失败']);
        }
    }
}
