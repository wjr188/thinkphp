<?php
namespace app\controller\api;

use think\facade\Db;
use think\facade\Log;

class ContentVipMapController
{
    public function list()
    {
        try {
            $rows = Db::name('content_vip_map')
                      ->field('content_type, content_id, vip_card_type_id')
                      ->select()
                      ->toArray();

            $out = [];
            foreach ($rows as $r) {
                $type = $r['content_type'];
                $out[$type] = $out[$type] ?? [];
                $out[$type][ (string)$r['content_id'] ] = (int)$r['vip_card_type_id'];
            }

            return apiReturn($out);
        } catch (\Exception $e) {
            Log::error('ContentVipMapController.list error: '.$e->getMessage());
            return apiReturn([], '系统出错', 500);
        }
    }
}
