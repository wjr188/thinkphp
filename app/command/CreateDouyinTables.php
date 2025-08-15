<?php
    // 文件路径: app/command/CreateDouyinTables.php
    // 请确保这个文件里面只有这些代码，替换掉你现在看到的所有内容！
    // 务必使用UTF-8无BOM编码保存！

    namespace app\command;

    use think\console\Command;
    use think\console\Input;
    use think\console\Output;
    use think\facade\Db; // 导入 Db 门面，用于数据库操作

    class CreateDouyinTables extends Command
    {
        protected function configure()
        {
            // 配置命令名称和描述
            $this->setName('create:douyin:tables') // 定义命令名称
                 ->setDescription('Create douyin_categories, douyin_videos, and douyin_tags tables.');
        }

        protected function execute(Input $input, Output $output)
        {
            $output->info('Starting to create Douyin related tables...');

            try {
                // 创建 douyin_categories 表
                $sqlCategories = "
                CREATE TABLE IF NOT EXISTS `douyin_categories` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '分类名称',
                    `parent_id` INTEGER NOT NULL DEFAULT 0 COMMENT '父分类ID (0为顶级分类)',
                    `sort` INTEGER NOT NULL DEFAULT 0 COMMENT '排序值 (越小越靠前)',
                    `status` INTEGER NOT NULL DEFAULT 1 COMMENT '状态 (1:启用, 0:禁用)',
                    `create_time` DATETIME NOT NULL COMMENT '创建时间',
                    `update_time` DATETIME NOT NULL COMMENT '更新时间'
                );";
                Db::execute($sqlCategories);
                $output->info('Table `douyin_categories` created successfully or already exists.');

                // 创建 douyin_tags 表
                $sqlTags = "
                CREATE TABLE IF NOT EXISTS `douyin_tags` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '标签名称',
                    `sort` INTEGER NOT NULL DEFAULT 0 COMMENT '排序值 (越小越靠前)',
                    `status` INTEGER NOT NULL DEFAULT 1 COMMENT '状态 (1:启用, 0:禁用)',
                    `create_time` DATETIME NOT NULL COMMENT '创建时间',
                    `update_time` DATETIME NOT NULL COMMENT '更新时间'
                );";
                Db::execute($sqlTags);
                $output->info('Table `douyin_tags` created successfully or already exists.');

                // 创建 douyin_videos 表
                $sqlVideos = "
                CREATE TABLE IF NOT EXISTS `douyin_videos` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `category_id` INTEGER NOT NULL DEFAULT 0 COMMENT '分类ID',
                    `tag_ids` TEXT NOT NULL DEFAULT '' COMMENT '标签ID，逗号分隔',
                    `title` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '视频标题',
                    `description` TEXT NOT NULL DEFAULT '' COMMENT '视频描述',
                    `video_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '视频URL',
                    `cover_url` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '封面图片URL',
                    `duration` INTEGER NOT NULL DEFAULT 0 COMMENT '视频时长（秒）',
                    `views` INTEGER NOT NULL DEFAULT 0 COMMENT '播放量',
                    `likes` INTEGER NOT NULL DEFAULT 0 COMMENT '点赞量',
                    `comments` INTEGER NOT NULL DEFAULT 0 COMMENT '评论量',
                    `shares` INTEGER NOT NULL DEFAULT 0 COMMENT '分享量',
                    `author_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '作者名称',
                    `author_avatar` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '作者头像URL',
                    `publish_time` DATETIME NOT NULL COMMENT '发布时间',
                    `status` INTEGER NOT NULL DEFAULT 1 COMMENT '状态 (1:正常, 0:禁用)',
                    `create_time` DATETIME NOT NULL COMMENT '创建时间',
                    `update_time` DATETIME NOT NULL COMMENT '更新时间'
                );";
                Db::execute($sqlVideos);
                $output->info('Table `douyin_videos` created successfully or already exists.');

                $output->info('All Douyin tables creation process completed.');

            } catch (\Exception $e) {
                $output->error('An error occurred during table creation: ' . $e->getMessage());
                return self::CODE_ERROR; // 返回错误码
            }

            return self::CODE_SUCCESS; // 返回成功码
        }
    }
    