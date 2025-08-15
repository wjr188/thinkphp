<?php
/**
 * API 接口映射表配置
 * 用于防爬虫的统一网关路由映射
 */

return [
    // 长视频分类接口
    'b2c3d4' => ['app\controller\api\LongCategoryController', 'list'],
    'b3c4d5' => ['app\controller\api\LongVideoController', 'categoryVideos'],
    
    // 长视频相关接口
    'g8h9i0' => ['app\controller\api\LongVideoController', 'h5List'],
    'h9i0j1' => ['app\controller\api\LongVideoController', 'h5Detail'],
    'i0j1k2' => ['app\controller\api\LongVideoController', 'play'],
    'j1k2l3' => ['app\controller\api\LongVideoController', 'h5AllVideos'], // 修复：all -> h5AllVideos
    'k2l3m4' => ['app\controller\api\LongVideoController', 'h5GuessYouLike'], // 修复：guessYouLike -> h5GuessYouLike
    'l3m4n5' => ['app\controller\api\LongVideoController', 'track'],
    'm4n5o6' => ['app\controller\api\LongVideoController', 'rank'],
    'n5o6p7' => ['app\controller\api\LongVideoController', 'h5LimitedFree'],
    'lt1g2h' => ['app\controller\api\LongTagController', 'list'],  // 长视频标签列表
    
    // 动漫相关接口 - 修正映射到H5AnimeController
    'o6p7q8' => ['app\controller\api\H5AnimeController', 'list'],
    'p7q8r9' => ['app\controller\api\H5AnimeController', 'group'], 
    'q8r9s0' => ['app\controller\api\H5AnimeController', 'subCategoryAnimes'],
    'r9s0t1' => ['app\controller\api\H5AnimeController', 'animeVideoList'],
    's0t1u2' => ['app\controller\api\H5AnimeController', 'allRecommendGroups'],
    't1u2v3' => ['app\controller\api\H5AnimeController', 'groupAnimes'],
    'u2v3w4' => ['app\controller\api\H5AnimeController', 'tags'],
    
    // H5长视频相关接口
    'c3d4e5' => ['app\controller\api\LongHomeRecommendController', 'h5Home'],
    'd4e5f6' => ['app\controller\api\LongHomeRecommendController', 'h5Detail'],
    'e5f6g7' => ['app\controller\api\LongHomeRecommendController', 'h5GroupVideos'],
    
    // 广告相关接口
    'f7g8h9' => ['app\controller\api\BannerController', 'list'],

    // 搜索相关接口（前台）
    's1h2k3' => ['app\controller\api\SearchController', 'hotKeywords'],
    
    // 弹窗配置接口
    'p1c2f3' => ['app\controller\api\PopupConfigController', 'getConfig'],
    
    // 有声小说模块 - 修正映射
    'a1b2c3' => ['app\controller\api\AudioNovelCategoryController', 'list'],
    'a2b3c4' => ['app\controller\api\AudioNovelController', 'list'], 
    'a3b4c5' => ['app\controller\api\AudioNovelController', 'detail'],
    'a4b5c6' => ['app\controller\api\AudioNovelChapterController', 'list'],
    'a5b6c7' => ['app\controller\api\AudioNovelChapterController', 'detail'], 
    'a6b7c8' => ['app\controller\api\AudioNovelChapterController', 'play'],
    'a7b8c9' => ['app\controller\api\AudioNovelTagController', 'list'],
    'a8b9c1' => ['app\controller\api\AudioRecommendGroupController', 'allWithAudios'],
    'a9b1c2' => ['app\controller\api\AudioRecommendGroupController', 'getGroupAudiosPaginated'],
    
    // 文字小说模块 (H5前端)
    'tn1a2b' => ['app\controller\api\TextNovelCategoryController', 'list'],    // 分类列表
    'tn2b3c' => ['app\controller\api\TextNovelController', 'list'],           // 小说列表  
    'tn3c4d' => ['app\controller\api\TextNovelController', 'read'],           // 小说详情
    'tn4d5e' => ['app\controller\api\TextNovelChapterController', 'list'],    // 章节列表
    'tn5e6f' => ['app\controller\api\TextNovelChapterController', 'read'],    // 章节详情
    'tn6f7g' => ['app\controller\api\NovelRecommendController', 'allWithNovels'], // 推荐组
    'tn7g8h' => ['app\controller\api\NovelRecommendController', 'getGroupNovelsPaginated'], // 组内小说
    'tn8h9i' => ['app\controller\api\TextNovelTagController', 'list'],        // 标签列表
    
    'b1c2d3' => ['app\controller\api\UserBrowseLogController', 'h5List'],      // browse_history_list
    'b9c8d7' => ['app\controller\api\UserBrowseLogController', 'allTypes'],  // browse_history_all_types
    'b4c5d6' => ['app\controller\api\UserBrowseLogController', 'add'],       // browse_history_add
    'b7d8e9' => ['app\controller\api\UserBrowseLogController', 'delete'],    // browse_history_delete
    
    // 金币套餐模块
    'c1o2i3' => ['app\controller\api\CoinPackageController', 'list'],
    'c2o3i4' => ['app\controller\api\CoinPackageController', 'add'],
    'c3o4i5' => ['app\controller\api\CoinPackageController', 'update'],
    'c4o5i6' => ['app\controller\api\CoinPackageController', 'delete'],
    'c5o6i7' => ['app\controller\api\CoinPackageController', 'status'],
    
    // 用户基础接口
    'u1a2b3' => ['app\controller\api\UserController', 'login'],
    'u2a3b4' => ['app\controller\api\UserController', 'register'], 
    'u3a4b5' => ['app\controller\api\UserController', 'info'],
    'u4a5b6' => ['app\controller\api\UserController', 'autoRegister'],
    'u5a6b7' => ['app\controller\api\UserController', 'taskStatus'],
    'u6a7b8' => ['app\controller\api\UserController', 'claimTask'],
    'u7a8b9' => ['app\controller\api\LongVideoController', 'canWatch'],
    
    // 积分兑换相关接口
    'pe1l2t' => ['app\controller\api\PointsExchangeController', 'list'],         // 积分兑换列表
    'pe2e3g' => ['app\controller\api\PointsExchangeController', 'exchange'],     // 积分兑换
    'pe3r4d' => ['app\controller\api\PointsExchangeController', 'records'],      // 兑换记录
    
    // VIP卡片管理接口
    'vip1l2t' => ['app\controller\api\AdminMemberCardController', 'index'],       // VIP卡片列表
    'vip2s3v' => ['app\controller\api\AdminMemberCardController', 'save'],        // 新增VIP卡片
    'vip3u4p' => ['app\controller\api\AdminMemberCardController', 'update'],      // 更新VIP卡片
    'vip4t5s' => ['app\controller\api\AdminMemberCardController', 'toggleStatus'],// 切换状态
    'vip5d6l' => ['app\controller\api\AdminMemberCardController', 'delete'],      // 删除VIP卡片
    'vip6a7l' => ['app\controller\api\AdminMemberCardController', 'all'],         // 获取所有VIP卡片
    
    // 漫画分类管理
    'cm1a2b' => ['app\controller\api\ComicCategoryController', 'list'],
    'cm2b3c' => ['app\controller\api\ComicCategoryController', 'add'],
    'cm3c4d' => ['app\controller\api\ComicCategoryController', 'update'],
    'cm4d5e' => ['app\controller\api\ComicCategoryController', 'delete'],
    'cm5e6f' => ['app\controller\api\ComicCategoryController', 'batchDelete'],
    'cm6f7g' => ['app\controller\api\ComicCategoryController', 'toggleStatus'],
    'cm7g8h' => ['app\controller\api\ComicCategoryController', 'batchSetStatus'],
    
    // 漫画内容管理
    'cm8h9i' => ['app\controller\Api\ComicMangaController', 'detail'],
    'cm9i0j' => ['app\controller\Api\ComicMangaController', 'chapterList'],
    'cm0j1k' => ['app\controller\Api\ComicMangaController', 'chapterDetail'],
    'cm1k2l' => ['app\controller\Api\ComicMangaController', 'chapterImages'],
    'cm2l3m' => ['app\controller\Api\ComicMangaController', 'list'],
    
    // 漫画推荐分组
    'cm3m4n' => ['app\controller\api\ComicRecommendController', 'groups'],
    'cm4n5o' => ['app\controller\api\ComicRecommendController', 'addGroup'],
    'cm5o6p' => ['app\controller\api\ComicRecommendController', 'updateGroup'],
    'cm6p7q' => ['app\controller\api\ComicRecommendController', 'deleteGroup'],
    'cm7q8r' => ['app\controller\api\ComicRecommendController', 'sortGroups'],
    'cm8r9s' => ['app\controller\api\ComicRecommendController', 'groupComics'],
    'cm9s0t' => ['app\controller\api\ComicRecommendController', 'saveGroupComics'],
    
    // 漫画推荐池
    'cm0t1u' => ['app\controller\api\ComicRecommendController', 'ungroupedComics'],
    'cm1u2v' => ['app\controller\Api\ComicMangaController', 'list'],
    'cm2v3w' => ['app\controller\api\ComicRecommendController', 'mainCategories'],
    'cm3w4x' => ['app\controller\api\ComicRecommendController', 'childCategories'],
    'cm4x5y' => ['app\controller\api\ComicRecommendController', 'allGroupsWithComics'],
    'cm5y6z' => ['app\controller\api\ComicCategoryController', 'subCategoryComics'],
    
    // 漫画标签和榜单
    'cm6z7a' => ['app\controller\api\ComicTagController', 'list'],
    'cm7a8b' => ['app\controller\Api\ComicMangaController', 'rankList'],
    'cm8b9c' => ['app\controller\Api\ComicMangaController', 'dailyUpdates'],
    'cm9c0d' => ['app\controller\Api\ComicMangaController', 'weeklyUpdates'],
    'cm0d1e' => ['app\controller\Api\ComicMangaController', 'weeklyAllUpdates'],
    
    // 抖音视频相关接口 - 根据实际路由配置
    'dv1h2l' => ['app\controller\api\VideoController', 'h5List'],
    'dv3p4y' => ['app\controller\api\VideoController', 'play'],
    'dt7a8l' => ['app\controller\api\DouyinTagController', 'all'],
    'dv4d5c' => ['app\controller\api\VideoController', 'h5DiscoverList'],
    'dv5h6d' => ['app\controller\api\VideoController', 'h5VideoDetail'],
    'dv6s7h' => ['app\controller\api\VideoController', 'searchVideos'],
    
    // 抖音关键词相关接口
    'dk1e2n' => ['app\api\controller\DouyinKeywordController', 'enabled'],
    'dk2r3d' => ['app\api\controller\DouyinKeywordController', 'random'],
    'dk3c4k' => ['app\api\controller\DouyinKeywordController', 'recordClick'],
    'dk4d5p' => ['app\api\controller\DouyinKeywordController', 'recordDisplay'],
    'dk5l6t' => ['app\api\controller\DouyinKeywordController', 'index'],  // 获取关键词列表
    
    // 用户行为接口
    'ua1l2k' => ['app\controller\api\UserActionController', 'like'],
    'ua2u3k' => ['app\controller\api\UserActionController', 'unlike'],
    'ua3c4t' => ['app\controller\api\UserActionController', 'collect'],
    'ua4u5t' => ['app\controller\api\UserActionController', 'uncollect'],
    'ua5a6s' => ['app\controller\api\UserActionController', 'getActionStatus'],
    'ua6b7s' => ['app\controller\api\UserActionController', 'batchActionStatus'],
    'ua7c8s' => ['app\controller\api\UserActionController', 'getCollections'],
    
    // 暗网视频相关接口
    'dn1h2m' => ['app\controller\api\DarknetRecommendController', 'h5Home'],
    'dn2g3v' => ['app\controller\api\DarknetRecommendController', 'h5GroupVideos'],
    'dn3c4l' => ['app\controller\api\DarknetCategoryController', 'h5List'],
    'dn4v5h' => ['app\controller\api\DarknetVideoController', 'h5List'],
    'dn5c6v' => ['app\controller\api\DarknetVideoController', 'categoryVideos'],
    
    // 解锁系统相关接口
    'ul1l2v' => ['app\controller\api\UnlockController', 'longVideo'],           // 解锁长视频
    'ul2d3v' => ['app\controller\api\UnlockController', 'darknetVideo'],        // 解锁暗网视频
    'ul3a4v' => ['app\controller\api\UnlockController', 'animeVideo'],          // 解锁动漫视频
    'ul4s5v' => ['app\controller\api\UnlockController', 'starVideo'],           // 解锁星际视频
    'ul5d6v' => ['app\controller\api\UnlockController', 'douyinVideo'],         // 解锁短视频
    'ul6c7h' => ['app\controller\api\UnlockController', 'comicChapter'],        // 解锁漫画章节
    'ul7c8w' => ['app\controller\api\UnlockController', 'comicWhole'],          // 整本解锁漫画
    'ul8n9h' => ['app\controller\api\UnlockController', 'novelChapter'],        // 解锁小说章节
    'ul9n0w' => ['app\controller\api\UnlockController', 'novelWhole'],          // 整本解锁小说
    'ul0a1h' => ['app\controller\api\UnlockController', 'audioNovelChapter'],   // 解锁有声小说章节
    'ul1u2c' => ['app\controller\api\UnlockController', 'unlockedChapters'],    // 获取已解锁漫画章节
    'ul2u3c' => ['app\controller\api\UnlockController', 'unlockedNovelChapters'], // 获取已解锁小说章节
    'ul3u4c' => ['app\controller\api\UnlockController', 'unlockedAudioNovelChapters'], // 获取已解锁有声小说章节
     
    // OnlyFans H5前台接口
    'of1c2a' => ['app\controller\api\OnlyFansH5Controller', 'categories'],         // 获取分类列表
    'of2c3b' => ['app\controller\api\OnlyFansH5Controller', 'creators'],           // 获取分类下博主列表
    'of3c4d' => ['app\controller\api\OnlyFansH5Controller', 'creatorDetail'],     // 获取博主详情及内容
    'of4c5p' => ['app\controller\api\OnlyFansH5Controller', 'creatorProfile'],    // 获取博主资料
    'of5c6m' => ['app\controller\api\OnlyFansH5Controller', 'creatorMedia'],      // 获取博主媒体
    'of6m7d' => ['app\controller\api\OnlyFansH5Controller', 'mediaDetail'],       // 获取媒体详情
    'of7m8i' => ['app\controller\api\OnlyFansH5Controller', 'mediaImages'],       // 获取图集图片分页
    'of8s9r' => ['app\controller\api\OnlyFansH5Controller', 'search'],            // 搜索功能
    
];

