<?php
// 文件路径: config/database.php

return [
    // 这是关键：将默认使用的数据库连接配置改为 'mysql'
    'default' => 'mysql', 

    // 自动写入时间戳字段
    'auto_timestamp' => true,

    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',

    // 数据库连接配置信息
    'connections' => [
        // 保留你的 SQLite 配置，以防其他部分还在使用
        'sqlite' => [
            'type'     => 'sqlite',
            'database' => app()->getRootPath() . 'database' . DIRECTORY_SEPARATOR . 'database.sqlite',
            'prefix'   => '',
            'debug'    => true,
            'deploy'   => 0,
            // 之前你添加的这些 MySQL 相关的空键，可以保留也可以删除，但现在我们需要真正有效的 MySQL 配置
            'hostname' => '', 
            'hostport' => '',
            'username' => '',
            'password' => '',
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'params'   => [],
            'dsn'      => '',
            'auto_build_query' => false,
            'break_trans' => false,
            'rw_separate' => false,
            'master_num'  => 1,
            'slave_num'   => 0,
            'read_master' => false,
        ],

        // ！！！这是新添加的 MySQL 连接配置，非常重要！！！
        'mysql' => [
            // 数据库类型
            'type'        => 'mysql',
            // 服务器地址：通常是 '127.0.0.1' 或 'localhost'
            'hostname'    => '127.0.0.1', 
            // 数据库名：我们刚刚创建的数据库名称
            'database'    => 'audio_novel_db', 
            // 用户名：你的 MySQL root 用户名
            'username'    => 'root', 
            // 密码：你的 MySQL root 用户密码
            'password'    => 'wendage123', 
            // 端口：MySQL 默认端口
            'hostport'    => '3306',
            // 数据库编码
            'charset'     => 'utf8mb4',
            // 数据库排序规则
            'collation'   => 'utf8mb4_unicode_ci',
            // 是否开启调试模式（开发环境建议开启）
            'debug'       => true,
            // 持久化连接
            'pconnect'    => false,
            // 自动生成的时间戳字段名
            'auto_timestamp' => true,
            // 时间字段类型
            'datetime_format' => 'Y-m-d H:i:s',
            // 连接参数
            'params'      => [
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'"
            ],
            // 是否部署在同一个服务器（读写分离用）
            'deploy'      => 0,
            // 是否开启读写分离
            'rw_separate' => false,
            // 强制从主库读取
            'read_master' => false,
            // 表前缀
            'prefix'      => '',
        ],

        // 如果你还有其他数据库连接，可以在这里添加
    ],
];
