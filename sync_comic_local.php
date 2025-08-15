<?php
// ThinkPHP6脚本，直接php运行
use think\facade\Db;

// 如果不是TP6命令行模式，加下面这行引入框架（如有报错可注释）：
require_once __DIR__ . '/public/index.php';

$basePath = __DIR__ . '/public/upload/comic';

$comicDirs = scandir($basePath);

foreach ($comicDirs as $comicId) {
    if ($comicId === '.' || $comicId === '..') continue;
    $comicPath = $basePath . '/' . $comicId;
    if (!is_dir($comicPath)) continue;

    // --- 1. 封面查找 ---
    $coverPath = '';
    $files = scandir($comicPath);
    foreach ($files as $file) {
        // 只找目录下的jpg/png/jpeg文件作为封面
        if (preg_match('/\.(jpg|jpeg|png)$/i', $file) && is_file($comicPath . '/' . $file)) {
            $coverPath = "/upload/comic/{$comicId}/{$file}";
            break;
        }
    }

    // --- 2. 插入漫画主表 comic_manga ---
    $comic = Db::name('comic_manga')->where('id', $comicId)->find();
    if (!$comic) {
        Db::name('comic_manga')->insert([
            'id' => $comicId,
            'name' => '漫画' . $comicId,
            'cover' => $coverPath,
            'intro' => '',
            'category_id' => 0,
            'tags' => '',
            'is_vip' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    } else {
        // 封面更新逻辑（可选）
        if ($comic['cover'] != $coverPath && $coverPath) {
            Db::name('comic_manga')->where('id', $comicId)->update([
                'cover' => $coverPath,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // --- 3. 同步章节 ---
    foreach ($files as $chapterId) {
        if ($chapterId === '.' || $chapterId === '..') continue;
        $chapterPath = $comicPath . '/' . $chapterId;
        if (!is_dir($chapterPath)) continue;
        if (!ctype_digit($chapterId)) continue; // 只处理纯数字文件夹为章节

        // 插入章节，不指定id，让数据库自增
        $chapter = Db::name('comic_chapters')->where([
            'manga_id' => $comicId,
            'title' => "第{$chapterId}话"
        ])->find();
        if (!$chapter) {
            Db::name('comic_chapters')->insert([
                // 'id' => $chapterId, // 不指定id，数据库自增
                'manga_id' => $comicId,
                'title' => "第{$chapterId}话",
                'order_num' => intval($chapterId),
                'is_vip' => 0,
                'create_time' => date('Y-m-d H:i:s'),
                'sort' => intval($chapterId)
            ]);
        }

        // --- 4. 同步章节图片 ---
        $imgFiles = glob($chapterPath . '/*.{jpg,jpeg,png}', GLOB_BRACE);
        sort($imgFiles, SORT_NATURAL);
        foreach ($imgFiles as $sort => $imgFile) {
            $imgUrl = "/upload/comic/{$comicId}/{$chapterId}/" . basename($imgFile);
            $img = Db::name('comic_images')->where([
                'comic_id' => $comicId,
                'img_url' => $imgUrl
            ])->find();
            if (!$img) {
                Db::name('comic_images')->insert([
                    'comic_id' => $comicId,
                    'img_url' => $imgUrl,
                    'sort' => $sort + 1
                ]);
            }
        }
    }
}

echo "同步完成！\n";
