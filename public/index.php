<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// DEBUG: 路由文件检测
file_put_contents(__DIR__ . '/../route/debug.log', date("Y-m-d H:i:s")." 路由已加载\n", FILE_APPEND);

use think\App;

// 添加跨域请求头
header('Access-Control-Allow-Origin: *'); // 允许所有域名的请求
header('Access-Control-Allow-Headers: *'); // 允许所有请求头
header('Access-Control-Allow-Methods: *'); // 允许所有方法（GET, POST, PUT, DELETE, OPTIONS等）

// 处理图片静态文件访问
if (strpos($_SERVER['REQUEST_URI'], '/upload/comic/') !== false) {
    // 移除查询参数
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $imagePath = __DIR__ . $requestUri;
    
    if (file_exists($imagePath)) {
        $mimeType = mime_content_type($imagePath);
        header('Content-Type: ' . $mimeType);
        header('Cache-Control: public, max-age=31536000'); // 添加缓存头
        readfile($imagePath);
        exit;
    } else {
        http_response_code(404);
        echo '图片不存在: ' . $imagePath; // 调试信息
        exit;
    }
}

// [ 应用入口文件 ]
require __DIR__ . '/../vendor/autoload.php';

// 执行HTTP应用并响应
$http = (new App())->http;

$response = $http->run();

$response->send();

$http->end($response);

