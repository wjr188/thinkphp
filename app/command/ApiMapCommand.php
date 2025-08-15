<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Config;

/**
 * API 映射表管理命令
 *
 * 用法：
 *  php think api:map --generate  # 生成新映射表
 *  php think api:map --validate  # 验证映射表
 *  php think api:map --show      # 显示映射表
 *  php think api:map --rotate    # 轮换映射表
 */
class ApiMapCommand extends Command
{
    protected function configure()
    {
        $this->setName('api:map')
            ->setDescription('API 映射表管理工具')
            ->addOption('generate', 'g', Option::VALUE_NONE, '生成新的映射表')
            ->addOption('validate', 'v', Option::VALUE_NONE, '验证映射表')
            ->addOption('show', 's', Option::VALUE_NONE, '显示当前映射表')
            ->addOption('rotate', 'r', Option::VALUE_NONE, '轮换映射表（重新生成映射ID）');
    }

    protected function execute(Input $input, Output $output)
    {
        if ($input->getOption('generate')) {
            return $this->generateMapping($output);
        }
        if ($input->getOption('validate')) {
            return $this->validateMapping($output);
        }
        if ($input->getOption('show')) {
            return $this->showMapping($output);
        }
        if ($input->getOption('rotate')) {
            return $this->rotateMapping($output);
        }

        $output->writeln('请指定操作参数：');
        $output->writeln('  --generate  生成新映射表');
        $output->writeln('  --validate  验证映射表');
        $output->writeln('  --show      显示映射表');
        $output->writeln('  --rotate    轮换映射表');
        return Command::INVALID;
    }

    // 生成新的映射表
    protected function generateMapping(Output $output): int
    {
        $output->writeln('正在生成新的 API 映射表...');

        $routes = $this->getRouteList();
        $mapping = [];
        foreach ($routes as $route) {
            $mapping[$route['name']] = $this->generateRandomId();
        }

        $content = $this->generateConfigContent($mapping);
        $configFile = config_path('api_map.php');
        file_put_contents($configFile, $content);

        $output->writeln('API 映射表生成完成！');
        $output->writeln('映射数量: ' . count($mapping));
        $output->writeln('配置文件: ' . $configFile);
        return Command::SUCCESS;
    }

    // 验证映射表
    protected function validateMapping(Output $output): int
    {
        $output->writeln('正在验证 API 映射表...');

        $config = Config::get('api_map');
        if (!$config) {
            $output->error('映射表配置不存在');
            return Command::FAILURE;
        }

        $errors = [];
        $warnings = [];

        // 检查重复映射
        $mappingIds = array_values($config);
        $duplicates = array_count_values($mappingIds);
        foreach ($duplicates as $id => $count) {
            if ($count > 1) {
                $errors[] = "重复的映射ID: {$id} (出现 {$count} 次)";
            }
        }

        // 检查映射ID格式与长度
        foreach ($config as $route => $id) {
            if (!preg_match('/^[a-f0-9]+$/', (string)$id)) {
                $errors[] = "无效的映射ID格式: {$route} => {$id}";
            }
            $len = strlen((string)$id);
            if ($len < 6 || $len > 10) {
                $warnings[] = "映射ID长度建议 6-10 位: {$route} => {$id}";
            }
        }

        if (empty($errors) && empty($warnings)) {
            $output->writeln('<info>映射表验证通过</info>');
        } else {
            if ($errors) {
                $output->writeln('<error>发现错误:</error>');
                foreach ($errors as $err) {
                    $output->writeln('  - ' . $err);
                }
            }
            if ($warnings) {
                $output->writeln('<comment>警告信息:</comment>');
                foreach ($warnings as $warn) {
                    $output->writeln('  - ' . $warn);
                }
            }
        }

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }

    // 显示当前映射表
    protected function showMapping(Output $output): int
    {
        $config = Config::get('api_map');
        if (!$config) {
            $output->error('映射表配置不存在');
            return Command::FAILURE;
        }

        $output->writeln('当前 API 映射表:');
        $output->writeln(str_repeat('-', 80));
        foreach ($config as $route => $id) {
            $output->writeln(sprintf('%-40s => %s', (string)$route, (string)$id));
        }
        $output->writeln(str_repeat('-', 80));
        $output->writeln('总计: ' . count($config) . ' 个映射');
        return Command::SUCCESS;
    }

    // 轮换映射表
    protected function rotateMapping(Output $output): int
    {
        $output->writeln('正在轮换 API 映射表...');

        $config = Config::get('api_map');
        if (!$config) {
            $output->error('映射表配置不存在');
            return Command::FAILURE;
        }

        $newMapping = [];
        foreach ($config as $route => $_old) {
            $newMapping[$route] = $this->generateRandomId();
        }

        $content = $this->generateConfigContent($newMapping);
        $configFile = config_path('api_map.php');
        $backupFile = config_path('api_map.backup.' . date('YmdHis') . '.php');
        @copy($configFile, $backupFile);
        file_put_contents($configFile, $content);

        $output->writeln('映射表轮换完成！');
        $output->writeln('备份文件: ' . $backupFile);
        $output->writeln('新映射数量: ' . count($newMapping));
        return Command::SUCCESS;
    }

    // 路由清单（按业务域汇总）
    protected function getRouteList(): array
    {
        return [
            ['name' => 'onlyfans_categories'],
            ['name' => 'onlyfans_creators'],
            ['name' => 'onlyfans_creator_detail'],
            ['name' => 'onlyfans_creator_profile'],
            ['name' => 'onlyfans_creator_media'],
            ['name' => 'onlyfans_media_images'],
            ['name' => 'onlyfans_media_detail'],
            ['name' => 'onlyfans_search'],
            ['name' => 'onlyfans_media_by_tag'],
            ['name' => 'long_video_h5_list'],
            ['name' => 'long_video_category'],
            ['name' => 'long_video_h5_detail'],
            ['name' => 'long_video_all'],
            ['name' => 'long_video_guess_you_like'],
            ['name' => 'long_video_track'],
            ['name' => 'long_video_rank'],
            ['name' => 'long_video_limited_free'],
            ['name' => 'douyin_video_h5_list'],
            ['name' => 'douyin_video_detail'],
            ['name' => 'douyin_video_play'],
            ['name' => 'douyin_video_discover'],
            ['name' => 'douyin_video_h5_detail'],
            ['name' => 'douyin_video_search'],
            ['name' => 'darknet_video_h5_list'],
            ['name' => 'darknet_video_category'],
            ['name' => 'darknet_home'],
            ['name' => 'darknet_group_videos'],
            ['name' => 'darknet_categories_list'],
            ['name' => 'anime_category_list'],
            ['name' => 'anime_category_group'],
            ['name' => 'anime_sub_animes'],
            ['name' => 'anime_tags'],
            ['name' => 'anime_recommend_all'],
            ['name' => 'anime_recommend_groups'],
            ['name' => 'anime_video_list'],
            ['name' => 'recommend_groups'],
            ['name' => 'recommend_group_videos'],
            ['name' => 'recommend_all_videos'],
            ['name' => 'recommend_video_detail'],
            ['name' => 'h5_long_home'],
            ['name' => 'h5_long_video_detail'], 
            ['name' => 'h5_long_group_videos'],
            ['name' => 'long_home'],
            ['name' => 'long_home_detail'],
            ['name' => 'long_home_group_videos'],
            ['name' => 'unlock_comic_chapter'],
            ['name' => 'unlock_unlocked_chapters'],
            ['name' => 'unlock_novel_chapter'],
            ['name' => 'unlock_unlocked_novel_chapters'],
            ['name' => 'unlock_comic_whole'],
            ['name' => 'unlock_novel_whole'],
            ['name' => 'unlock_anime_video'],
            ['name' => 'unlock_star_video'],
            ['name' => 'unlock_audio_novel_chapter'],
            ['name' => 'unlock_unlocked_audio_novel_chapters'],
            ['name' => 'unlock_douyin_video'],
            ['name' => 'user_like'],
            ['name' => 'user_unlike'],
            ['name' => 'user_collect'],
            ['name' => 'user_uncollect'],
            ['name' => 'user_action_status'],
            ['name' => 'user_batch_action_status'],
            ['name' => 'user_collections'],
            ['name' => 'user_browse_history'],
            ['name' => 'douyin_tag_all'],
        ];
    }

    // 生成随机映射ID（6-9位 16进制）
    protected function generateRandomId(): string
    {
        $len = random_int(6, 9);
        $chars = '0123456789abcdef';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, 15)];
        }
        return $out;
    }

    // 生成配置文件内容
    protected function generateConfigContent(array $mapping): string
    {
        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * API 映射表配置\n";
        $content .= " * 生成时间: " . date('Y-m-d H:i:s') . "\n";
        $content .= " * 映射数量: " . count($mapping) . "\n";
        $content .= " * 注意: 此文件由系统自动生成，请勿手动修改\n";
        $content .= " */\n\n";
        $content .= "return [\n";
        foreach ($mapping as $route => $id) {
            $route = addslashes((string)$route);
            $id = addslashes((string)$id);
            $content .= "    '{$route}' => '{$id}',\n";
        }
        $content .= "];\n";
        return $content;
    }
}
