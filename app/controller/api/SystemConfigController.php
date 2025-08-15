<?php
// E:\ThinkPHP6\app\controller\api\SystemConfigController.php

namespace app\controller\api; // 确保是小写api

use app\BaseController;
use think\facade\Request;

class SystemConfigController extends BaseController
{
    // 对应 'api/sys/config/app/info'
    public function appInfo()
    {
        // 这里提供一些模拟的系统信息，前端可能需要这些字段
        return json(['code' => 0, 'msg' => 'SystemConfigController: appInfo placeholder', 'data' => [
            'appName' => 'My Admin System',
            'version' => '1.0.0',
            'copyright' => 'Your Company © 2025'
        ]]);
    }
}