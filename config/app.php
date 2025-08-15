<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 应用映射（自动多应用模式有效）
    'app_map'          => [],
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => [],

    // 异常页面的模板文件
    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'   => true,         // ← 这一行一定要 true
    // 开启调试模式
    'app_debug'        => true,         // ← 这一行一定要 true
];
return [
    // ... 其他配置 ...

    // 服务
    'services'         => [
        // ThinkPHP 自身的服务
        // \app\service\SomeService::class, // 如果你有其他自定义服务，可能类似这样
        // ...

        // !!! 关键修改：添加这一行来注册数据库迁移服务 !!!
        \think\migration\Service::class,
    ],

    // ... 其他配置 ...
];