<?php
// 文件路径: E:\ThinkPHP6\app\controller\api\MenuController.php

// 修正命名空间，确保与实际文件路径 app\controller\api 匹配
namespace app\controller\api; 

use app\BaseController;
use think\facade\Request;

class MenuController extends BaseController
{
    public function getRoutes()
    {
        // 这是模拟的菜单数据。实际项目中需要从数据库或其他地方获取并处理。
        $menus = [
            [
                'path' => '/dashboard',
                'component' => 'Layout', // 前端基础布局组件
                'redirect' => '/dashboard/index',
                'meta' => [
                    'title' => 'Dashboard',
                    'icon' => 'dashboard',
                    'alwaysShow' => false
                ],
                'children' => [
                    [
                        'path' => 'index',
                        'component' => 'dashboard/index', // 对应前端 src/views/dashboard/index.vue
                        'name' => 'Dashboard',
                        'meta' => ['title' => 'Dashboard', 'icon' => 'dashboard', 'affix' => true]
                    ]
                ]
            ],
            [
                'path' => '/example',
                'component' => 'Layout',
                'redirect' => '/example/table',
                'meta' => [
                    'title' => 'Example',
                    'icon' => 'example'
                ],
                'children' => [
                    [
                        'path' => 'table',
                        'component' => 'example/table/index', // 对应前端 src/views/example/table/index.vue (假设有目录结构)
                        'name' => 'Table',
                        'meta' => ['title' => 'Table', 'icon' => 'table']
                    ],
                    [
                        'path' => 'tree',
                        'component' => 'example/tree/index', // 对应前端 src/views/example/tree/index.vue
                        'name' => 'Tree',
                        'meta' => ['title' => 'Tree', 'icon' => 'tree']
                    ]
                ]
            ],
            // ... 你可以根据你的需要添加更多菜单项，确保 'component' 字段正确对应前端实际的 Vue 文件路径
            // 特别注意：'component' 中的值是相对路径，例如 'dashboard/index' 对应 src/views/dashboard/index.vue
            // 如果是嵌套路由，顶层组件通常是 'Layout'，子组件才是具体的页面组件
        ];

        return json([
            'code' => 0, // 必须是数字 0，与前端 ResultEnum.SUCCESS 匹配
            'msg'  => '获取菜单成功',
            'data' => $menus,
        ]);
    }
}