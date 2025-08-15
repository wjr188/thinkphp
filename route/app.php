<?php
// 文件路径: E:\ThinkPHP6\route\app.php

use think\facade\Route;
use app\controller\api\LongHomeRecommendController;
// =========================================================
//                        统一加密网关路由
// =========================================================
// 会话密钥获取接口 - 握手获取会话密钥
Route::get('key', 'app\controller\api\GatewayController@getSessionKey');

// 仅加密入口 - 前端所有加密请求统一POST到/x，携带m(映射ID)+d(密文)
Route::post('x', 'app\controller\api\GatewayController@route')
    ->middleware(\app\middleware\ApiSecurity::class);
// =========================================================
//                        核心认证与用户接口
// =========================================================
Route::get('api/darknet/videos/h5-list', 'app\controller\api\DarknetVideoController@h5List');
Route::get('api/long/videos/h5-list', '\app\controller\api\LongVideoController@h5List');
Route::get('api/h5/long_video/category/:category_id', 'api.LongVideoController/categoryVideos'); 
Route::get('api/h5/video/detail', 'app\controller\api\LongVideoController@h5Detail');                                                             
// Login interface
Route::post('api/auth/login', 'api.AuthController/login');
Route::post('api/v1/auth/login', 'api.AuthController/login'); // Compatible with old versions

// User information interface
Route::get('api/v1/users/me', 'app\controller\api\AuthController@info');

// Get menu/route interface (frontend for dynamic navigation and permissions)
Route::get('api/v1/menus/routes', 'api.MenuController/getRoutes');
Route::get('api/menuList', 'api.MenuController/getRoutes'); // Compatible with old menu interface
// ================== 账号管理与权限接口补全 ==================
Route::delete('api/v1/auth/logout', 'app\controller\api\AuthController@logout');
// 权限树接口（用于账号权限勾选）
Route::get('api/permission/list', 'app\controller\api\AuthController@getAllPermissions');

// 账号管理接口（和前端 user.api.ts 对应）
Route::get('api/user/list', 'app\controller\api\AuthController@list');
Route::post('api/user/create', 'app\controller\api\AuthController@create');
Route::post('api/user/update', 'app\controller\api\AuthController@update');
Route::post('api/user/delete', 'app\controller\api\AuthController@delete');
Route::post('api/user/reset-password', 'app\controller\api\AuthController@resetPassword');
Route::post('api/user/change-password', 'app\controller\api\AuthController@changePassword');
Route::post('api/v1/user/login', 'app\controller\api\AuthController@login');
Route::get('api/v1/user/info', 'app\controller\api\AuthController@info');
Route::post('api/user/logout2', 'app\controller\api\AuthController@logout');
// =========================================================
//                        工具和系统日志接口
// =========================================================

// Access status statistics interface
Route::get('api/tool/run-visit-state', 'api.ToolController/runVisitState'); // Possible interface to trigger statistics
Route::get('api/tool/visit-state', 'api.ToolController/visitState'); // Get real-time access status

// Dashboard homepage data statistics trend interface
Route::get('api/v1/logs/visit-trend', 'api.LogsController/visitTrend');
Route::get('api/v1/logs/visit-stats', 'api.LogsController/visitStats');
Route::get('api/v1/logs/logCount', 'api.LogsController/logCount'); // Log count


// =========================================================
//                         系统配置接口
// =========================================================

Route::get('api/sys/config/app/info', 'api.SystemConfigController/appInfo');


// =========================================================
//                         测试接口 (按需保留)
// =========================================================

Route::get('api/test1', 'api.TestController/test1');
Route::get('api/test', 'api.TestController/test');


// =========================================================
//                         通用视频管理接口 (包含之前所有api/videos的路由)
// =========================================================

// Video upload interface
Route::post('api/v1/video/upload', '\app\controller\api\VideoController@upload'); // Explicitly specify controller

Route::group('api/videos', function () {
    Route::get('list', '\app\controller\api\VideoController@list');
    Route::post('add', '\app\controller\api\VideoController@addVideo'); // Corresponds to VideoController's addVideo
    Route::post('update', '\app\controller\api\VideoController@updateVideo'); // Corresponds to VideoController's updateVideo
    Route::post('batch-delete', '\app\controller\api\VideoController@batchDelete');
    Route::post('batch-set-vip', '\app\controller\api\VideoController@batchSetVip');
    Route::post('batch-set-duration', '\app\controller\api\VideoController@batchSetDuration');
    Route::post('batch-set-gold', '\app\controller\api\VideoController@batchSetGold');
    Route::get(':id', '\app\controller\api\VideoController@getVideoById'); // Get single video details
});


// =========================================================
//                         长视频管理接口
// =========================================================

// Long video upload interface
Route::post('api/v1/longvideo/upload', '\app\controller\api\LongVideoController@upload'); // Explicitly specify controller

Route::group('api/long/videos', function () {
    Route::get('canWatch', 'app\controller\api\LongVideoController@canWatch');
    Route::get('list',          'app\controller\api\LongVideoController@list');            // 获取列表
    Route::post('add',          'app\controller\api\LongVideoController@addVideo');             // 新增
    Route::post('update',       'app\controller\api\LongVideoController@updateVideo');          // 编辑
    Route::post('batch-delete', 'app\controller\api\LongVideoController@batchDelete');     // 批量删除
    Route::post('batch-set-vip',      'app\controller\api\LongVideoController@batchSetVip');      // 批量VIP
    Route::post('batch-set-duration', 'app\controller\api\LongVideoController@batchSetDuration'); // 批量试看时长
    Route::post('batch-set-gold',     'app\controller\api\LongVideoController@batchSetGold');     // 批量金币
    Route::post('batch-set-play',     'app\controller\api\LongVideoController@batchSetPlay');     // 批量设置播放数
    Route::post('batch-set-collect',  'app\controller\api\LongVideoController@batchSetCollect');  // 批量设置收藏数
    Route::post('batch-sort-asc', 'app\controller\api\LongVideoController@batchSortAsc');
    Route::post('play', 'app\controller\api\LongVideoController@play');
    Route::get(':id',           'app\controller\api\LongVideoController@detail');          // 视频详情（注意: 必须在最后，不然会冲突）
    

}); 


// =========================================================
//                         抖音视频管理接口 (指向 VideoController)
// =========================================================

// Unify frontend Store request path to /api/douyin/videos
Route::group('api/douyin/videos', function () {
    Route::get('list', '\app\controller\api\VideoController@list');
    Route::post('add', '\app\controller\api\VideoController@addVideo');
    Route::post('update', '\app\controller\api\VideoController@updateVideo');
    Route::post('batch-delete', '\app\controller\api\VideoController@batchDelete');
    Route::post('batch-set-vip', '\app\controller\api\VideoController@batchSetVip');
    Route::post('batch-set-duration', '\app\controller\api\VideoController@batchSetDuration');
    Route::post('batch-set-gold', '\app\controller\api\VideoController@batchSetGold');
    Route::get(':id', '\app\controller\api\VideoController@getVideoById'); // Ensure this parameter matches getVideoById method
    Route::post('batch-sort-asc', '\app\controller\api\VideoController@batchSortAsc');

});


// =========================================================
//                         抖音分类管理接口
// =========================================================

// Unify frontend Store request path to /api/douyin/categories
Route::group('douyin/categories', function () {
    Route::get('list', '\app\controller\api\DouyinCategoryController@list');
    Route::post('add-parent', '\app\controller\api\DouyinCategoryController@addParent');
    Route::post('add-child', '\app\controller\api\DouyinCategoryController@addChild');
    Route::post('update', '\app\controller\api\DouyinCategoryController@update');
    Route::post('delete', '\app\controller\api\DouyinCategoryController@delete');
    Route::post('batch-delete', '\app\controller\api\DouyinCategoryController@batchDelete');
    Route::post('batch-update-sort', '\app\controller\api\DouyinCategoryController@batchUpdateSort');
});



// =========================================================
//                         抖音标签管理接口
// =========================================================

// Unify frontend Store request path to /api/douyin/tags
Route::group('api/douyin/tags', function () {
    Route::get('list', '\app\controller\api\DouyinTagController@list');
    Route::post('add', '\app\controller\api\DouyinTagController@add');
    Route::post('update', '\app\controller\api\DouyinTagController@update');
    Route::post('delete', '\app\controller\api\DouyinTagController@delete');
    Route::post('batch-delete', '\app\controller\api\DouyinTagController@batchDelete');
    Route::post('batch-disable', '\app\controller\api\DouyinTagController@batchDisable');
    Route::post('toggle-status', '\app\controller\api\DouyinTagController@toggleStatus');
    // Route::post('batch-update-sort', '\app\controller\api\DouyinTagController@batchUpdateSort'); // Removed: Corresponding controller method does not exist
});


// =========================================================
//                         长视频分类管理接口
// =========================================================

Route::group('api/long/categories', function () {
    Route::get('list', 'api.LongCategoryController/list');
    Route::post('add-parent', 'api.LongCategoryController/addParent');
    Route::post('add-child', 'api.LongCategoryController/addChild');
    Route::post('update', 'api.LongCategoryController/update');
    Route::post('delete', 'api.LongCategoryController/delete');
    Route::post('batch-delete', 'api.LongCategoryController/batchDelete');
    Route::post('update-child-tags', 'api.LongCategoryController/updateChildTags');
    Route::post('update-child-sort', 'api.LongCategoryController/updateChildSort');
    Route::post('update-parent-sort', 'api.LongCategoryController/updateParentSort');
    Route::post('batch-update-sort', 'api.LongCategoryController/batchUpdateSort');
});

// =========================================================
//                         长视频标签管理接口
// =========================================================

Route::group('api/longtags', function () {
    Route::get('list', '\app\controller\api\LongTagController@list');
    Route::post('add', '\app\controller\api\LongTagController@add');
    Route::post('update', '\app\controller\api\LongTagController@update');
    Route::post('delete', '\app\controller\api\LongTagController@delete');
    Route::post('batch-delete', '\app\controller\api\LongTagController@batchDelete');
    Route::post('batch-disable', '\app\controller\api\LongTagController@batchDisable');
    Route::post('toggle-status', '\app\controller\api\LongTagController@toggleStatus');
});


// =========================================================
//                         动漫分类管理接口
// =========================================================

Route::group('api/anime/categories', function () {
    Route::get('list', 'api.AnimeCategoryController/list');
    Route::post('add-parent', 'api.AnimeCategoryController/addParent');
    Route::post('add-child', 'api.AnimeCategoryController/addChild');
    Route::post('update', 'api.AnimeCategoryController/update');
    Route::post('delete', 'api.AnimeCategoryController/delete');
    Route::post('batch-delete', 'api.AnimeCategoryController/batchDelete');
    Route::post('batch-update-sort', 'api.AnimeCategoryController/batchUpdateSort');
});


// =========================================================
//                         动漫标签管理接口
// =========================================================

Route::group('api/anime/tags', function () {
    Route::get('list', 'api.AnimeTagController/list');
    Route::post('add', 'api.AnimeTagController@add');
    Route::post('update', 'api.AnimeTagController@update');
    Route::post('delete', 'api.AnimeTagController@delete');
    Route::post('batch-delete', 'api.AnimeTagController@batchDelete');
    Route::post('batch-disable', 'api.AnimeTagController@batchDisable');
    Route::post('toggle-status', 'api.AnimeTagController/toggleStatus');
    Route::post('batch-update-sort', 'api.AnimeTagController/batchUpdateSort');
});

// =========================================================
//                         动漫视频管理接口
// =========================================================
Route::group('api/anime/videos', function () {
    Route::get('list', 'api.AnimeVideoController/list');
    Route::get(':id', 'api.AnimeVideoController@getById');
    Route::post('add', 'api.AnimeVideoController/add');
    Route::post('update', 'api.AnimeVideoController/update');
    Route::post('delete', 'api.AnimeVideoController/delete');
    Route::post('batch-delete', 'api.AnimeVideoController/batchDelete');
    Route::post('batch-set-vip', 'api.AnimeVideoController/batchSetVip');
    Route::post('batch-set-preview', 'api.AnimeVideoController/batchSetPreview');
    Route::post('batch-set-gold', 'api.AnimeVideoController/batchSetGold');
    Route::post('batch-set-play', 'api.AnimeVideoController/batchSetPlay');   // 加上这一行！！！
    Route::post('batch-set-collect', 'api.AnimeVideoController/batchSetCollect');
    Route::post('batch-sort-asc', 'api.AnimeVideoController/batchSortAsc');
    Route::post('batch-set-likes', 'api.AnimeVideoController/batchSetLikes');

});

// =================== H5前台动漫分类查询接口 ===================
Route::group('api/anime/category', function () {
    Route::get('list', 'api.H5AnimeController/list');
    Route::get('group', 'api.H5AnimeController/group');
    Route::get('sub/animes', 'api.H5AnimeController/subCategoryAnimes');
    Route::get('tags', 'api.H5AnimeController/tags');
});

// =================== H5前台动漫推荐分组接口 ===================
Route::get('api/anime/recommend/all', 'api.H5AnimeController/allRecommendGroups');
Route::get('api/anime/recommend/group-animes', 'api.H5AnimeController/groupAnimes');

// =================== H5前台动漫视频列表接口 ===================
Route::get('api/h5/anime/videos/list', 'api.H5AnimeController/animeVideoList');

// =========================================================
//                         暗网视频管理接口
// =========================================================

// Note: Darknet module here maintains your original route prefix 'darknet-video', without 'api/'
Route::group('api/darknet/videos', function () {
    Route::get('list', 'api.DarknetVideoController/list');                 // 列表
    Route::post('add', 'api.DarknetVideoController/add');                  // 新增
    Route::post('update', 'api.DarknetVideoController/update');            // 编辑
    Route::post('delete', 'api.DarknetVideoController/delete');            // 删除单个
    Route::post('batch-delete', 'api.DarknetVideoController/batchDelete'); // 批量删除
    Route::post('batch-set-vip', 'api.DarknetVideoController/batchSetVip');           // 批量设VIP
    Route::post('batch-set-preview', 'api.DarknetVideoController/batchSetPreview');   // 批量设试看时长
    Route::post('batch-set-gold', 'api.DarknetVideoController/batchSetGold');         // 批量设金币
    Route::get(':id', 'api.DarknetVideoController/getById');                          // 详情（注意这里是 GET /api/darknet/videos/123）
});

// =========================================================
//                         暗网分类管理接口
// =========================================================

// Note: Darknet module here maintains your original route prefix 'darknet-category', without 'api/'
// 暗网分类管理接口
Route::group('api/darknet/categories', function () {
    Route::get('list', 'api.DarknetCategoryController/list');
    Route::post('add-parent', 'api.DarknetCategoryController/addParent');
    Route::post('add-child', 'api.DarknetCategoryController/addChild');
    Route::post('update', 'api.DarknetCategoryController/update');
    Route::post('delete', 'api.DarknetCategoryController/delete');
    Route::post('batch-delete', 'api.DarknetCategoryController/batchDelete');
    Route::post('batch-update-sort', 'api.DarknetCategoryController/batchUpdateSort');
    Route::post('update-child-tags', 'api.DarknetCategoryController/updateChildTags');
    Route::post('update-child-sort', 'api.DarknetCategoryController/updateChildSort');
});




// =========================================================
//                         暗网标签管理接口
// =========================================================

// Note: Darknet module here maintains your original route prefix 'darknet-tag', without 'api/'
Route::group('api/darknet/tags', function () {
    Route::get('list', 'api.DarknetTagController/list');
    Route::post('add', 'api.DarknetTagController/add');
    Route::post('update', 'api.DarknetTagController/update');
    Route::post('delete', 'api.DarknetTagController/delete');
    Route::post('batch-delete', 'api.DarknetTagController@batchDelete');
    Route::post('batch-disable', 'api.DarknetTagController@batchDisable');
    Route::post('toggle-status', 'api.DarknetTagController/toggleStatus');
});


// =========================================================
//                         微密圈管理模块路由
// =========================================================

// ========== 微密圈图片管理接口 ==========
Route::group('api/weimi/images', function () {
    Route::get('list', '\app\controller\api\WeimiImageController@list');
    Route::post('add', '\app\controller\api\WeimiImageController@add');
    Route::post('update', '\app\controller\api\WeimiImageController@update');
    Route::post('batch-delete', '\app\controller\api\WeimiImageController@batchDelete');
    Route::post('update-sort', '\app\controller\api\WeimiImageController@updateSort'); // Image sorting interface
    Route::get(':id', '\app\controller\api\WeimiImageController@getById'); // Get single image details

    // Batch setting functions for image management page (if frontend has these buttons, corresponding implementation is needed)
    Route::post('batch-set-vip', '\app\controller\api\WeimiImageController@batchSetVip');
    Route::post('batch-set-gold', '\app\controller\api\WeimiImageController@batchSetGold');
    Route::post('batch-set-status', '\app\controller\api\WeimiImageController@batchSetStatus');
});

// ========== 微密圈分类管理接口 ==========
Route::group('api/weimi/categories', function () {
    Route::get('list', '\app\controller\api\WeimiCategoryController@list');
    Route::post('add', '\app\controller\api\WeimiCategoryController@add');
    Route::post('update', '\app\controller\api\WeimiCategoryController@update');
    Route::post('delete', '\app\controller\api\WeimiCategoryController@delete');
    Route::post('batch-delete', '\app\controller\api\WeimiCategoryController@batchDelete');
    Route::post('batch-update-sort', '\app\controller\api\WeimiCategoryController@batchUpdateSort'); // Category sorting interface
});

// ========== 微密圈标签管理接口 ==========
Route::group('api/weimi/tags', function () {
    Route::get('list', '\app\controller\api\WeimiTagController@list');
    Route::post('add', '\app\controller\api\WeimiTagController@add');
    Route::post('update', '\app\controller\api\WeimiTagController@update');
    Route::post('delete', '\app\controller\api\WeimiTagController@delete');
    Route::post('batch-delete', '\app\controller\api\WeimiTagController@batchDelete');
    Route::post('batch-disable', '\app\controller\api\WeimiTagController@batchDisable');
    Route::post('toggle-status', '\app\controller\api\WeimiTagController@toggleStatus');
});
Route::post('api/upload/image', '\app\controller\api\UploadController@image');
Route::post('api/upload/video', '\app\controller\api\UploadController@video');



// 推荐分组管理 (映射到 LongHomeRecommendController)
// 基础路径: /api/recommend/groups
// 推荐分组管理路由（修复点）
Route::group('api/recommend/groups', function () {
    // 确保所有路由指向正确的控制器方法
    Route::get('', 'app\controller\api\LongHomeRecommendController@getRecommendGroups');
    Route::post('', 'app\controller\api\LongHomeRecommendController@addRecommendGroup');
    Route::put(':id', 'app\controller\api\LongHomeRecommendController@updateRecommendGroup');
    Route::delete(':id', 'app\controller\api\LongHomeRecommendController@deleteRecommendGroup');
    Route::post('sort', 'app\controller\api\LongHomeRecommendController@saveGroupSort');
    Route::get(':groupId/videos', 'app\controller\api\LongHomeRecommendController@getVideosForRecommendGroup');
    Route::post(':groupId/videos', 'app\controller\api\LongHomeRecommendController@saveVideosForRecommendGroup');
});

// 以下保持原样不动
Route::get('api/long/videos', 'app\controller\api\LongHomeRecommendController@getAllVideosList');
Route::get('api/categories/parents', 'app\controller\api\LongHomeRecommendController@getAllParentCategories');
Route::get('api/categories/children', 'app\controller\api\LongHomeRecommendController@getAllChildCategories');
Route::get('api/long/home', 'api.LongHomeController/home');


Route::group('api', function () {
    // 获取列表
    Route::get('banner/list', '\app\controller\api\BannerController@list');
    // 新增
    Route::post('banner/add', '\app\controller\api\BannerController@add');
    // 更新
    Route::post('banner/update', '\app\controller\api\BannerController@update');
    // 删除
    Route::post('banner/delete', '\app\controller\api\BannerController@delete');
    // 文件上传
    Route::post('banner/upload', '\app\controller\api\BannerController@upload');
});

// routes/api.php 或 route/app.php 里添加
Route::group('api/h5/recommend', function () {
    Route::get('groups', 'app\controller\api\HomeRecommendController@groups');
    Route::get('groups/:groupId/videos', 'app\controller\api\HomeRecommendController@groupVideos');
});
Route::get('api/h5/long/videos', 'app\controller\api\HomeRecommendController@allVideos');
Route::get('api/h5/long/videos/:id', 'app\controller\api\HomeRecommendController@videoDetail');


Route::group('api/admin/user', function() {
    Route::get('stats',        '\app\controller\api\AdminUserController@stats');
    Route::get('coin-stats',   '\app\controller\api\AdminUserController@coinStats');
    Route::get('points-stats', '\app\controller\api\AdminUserController@pointsStats');
    Route::get('order-stats',  '\app\controller\api\AdminUserController@orderStats');
    Route::get('list',         '\app\controller\api\AdminUserController@list');
    Route::get('detail',       '\app\controller\api\AdminUserController@detail');
    Route::post('add',         '\app\controller\api\AdminUserController@add');
    Route::post('update',      '\app\controller\api\AdminUserController@update');
    Route::post('delete',      '\app\controller\api\AdminUserController@deleteOne');
    Route::post('batch-update','\app\controller\api\AdminUserController@batchUpdate');
    

});

// VIP卡片类型管理接口（后台）
Route::group('api/admin/member-card', function() {
    Route::get('/',            '\app\controller\api\AdminMemberCardController@index');
    Route::post('/',           '\app\controller\api\AdminMemberCardController@save');
    Route::put('/:id',         '\app\controller\api\AdminMemberCardController@update');
    Route::patch('/:id/status','\app\controller\api\AdminMemberCardController@toggleStatus');
    Route::delete('/:id',      '\app\controller\api\AdminMemberCardController@delete');
    Route::get('/all',         '\app\controller\api\AdminMemberCardController@all'); // 下拉接口
});

Route::get(
    'api/v1/content-vip-map',
    '\\app\\controller\\api\\ContentVipMapController@list'
  );


  // 金币套餐管理
Route::group('api/coin-package', function() {
    Route::get('list',         '\app\controller\api\CoinPackageController@list');
    Route::post('add',         '\app\controller\api\CoinPackageController@add');
    Route::post('update',      '\app\controller\api\CoinPackageController@update');
    Route::post('delete',      '\app\controller\api\CoinPackageController@delete');
    Route::post('status',      '\app\controller\api\CoinPackageController@status'); // 上下架
});
Route::post('api/user/login',    'app\controller\api\UserController@login');
Route::post('api/user/register', 'app\controller\api\UserController@register');
Route::get('api/user/info',      'app\controller\api\UserController@info');
Route::post('api/user/auto-register', 'app\controller\api\UserController@autoRegister');
Route::post('api/user/autoRegister', 'app\controller\api\UserController@autoRegister');
Route::get('api/user/watch-count', 'app\controller\api\UserController@watchCount');
Route::post('api/user/claim-task', 'app\controller\api\UserController@claimTask');
Route::get('api/user/task-status', 'app\controller\api\UserController@taskStatus');
// 用户积分兑换
Route::group('api/user/points', function () {
    Route::get('list', 'app\controller\api\PointsExchangeController@list');
    Route::post('exchange', 'app\controller\api\PointsExchangeController@exchange');
});
Route::get('api/points/records', 'app\controller\api\PointsExchangeController@records');
Route::group('api/admin/points-exchange', function() {
    Route::get('list',          'app\controller\api\AdminPointsExchangeController@list');
    Route::post('add',          'app\controller\api\AdminPointsExchangeController@add');
    Route::put('update/:id',    'app\controller\api\AdminPointsExchangeController@update');
    Route::delete('delete/:id', 'app\controller\api\AdminPointsExchangeController@delete');
    Route::patch('toggle-status/:id', 'app\controller\api\AdminPointsExchangeController@toggleStatus');
    Route::get('records', 'app\controller\api\AdminPointsExchangeController@records'); // 兑换记录

});
Route::group('api/admin/points-log', function() {
    Route::get('list', 'app\controller\api\PointsLogController@list');
    Route::delete('delete/:id', 'app\controller\api\PointsLogController@delete');
});

// 判断是否还能试看（GET，url带参数）
Route::get('api/watch_record/canWatch', 'app\controller\api\WatchRecordController@canWatch');

// 记录试看（POST）
Route::post('api/watch_record/recordWatch', 'app\controller\api\WatchRecordController@recordWatch');
Route::post('api/coin/increase', '\app\controller\api\CoinController@increase');
Route::post('api/coin/decrease', '\app\controller\api\CoinController@decrease');


// 渠道管理相关路由
Route::group('api/channel', function () {
    Route::get('list', 'app\controller\api\ChannelManageController@list');
    Route::post('add', 'app\controller\api\ChannelManageController@add');
    Route::post('update', 'app\controller\api\ChannelManageController@update');
    Route::post('delete', 'app\controller\api\ChannelManageController@delete');

   
    // 渠道统计相关
    Route::get('stats', 'app\controller\api\ChannelStatsController@list');
Route::get('user-recharge-detail', 'app\controller\api\ChannelStatsController@userRechargeDetail');
Route::get('user-recharge-orders', 'app\controller\api\ChannelStatsController@userRechargeOrders');
});

// 漫画分类管理相关路由
Route::group('api/comic/category', function () {
    // Controller class: app\controller\api\ComicCategoryController
    Route::get('list', 'app\controller\api\ComicCategoryController@list');
    Route::post('add', 'app\controller\api\ComicCategoryController@add');
    Route::post('update', 'app\controller\api\ComicCategoryController@update');
    Route::post('delete', 'app\controller\api\ComicCategoryController@delete');
    Route::post('batchDelete', 'app\controller\api\ComicCategoryController@batchDelete');
    Route::post('toggleStatus', 'app\controller\api\ComicCategoryController@toggleStatus');
    Route::post('batchSetStatus', 'app\controller\api\ComicCategoryController@batchSetStatus');
    Route::get('sub-comics', 'app\controller\api\ComicCategoryController@subCategoryComics');
});

// 漫画标签管理相关路由
Route::group('api/comic/tag', function () {
    // Controller class: app\controller\api\ComicTagController
    Route::get('list', 'app\controller\api\ComicTagController@list');
    Route::post('add', 'app\controller\api\ComicTagController@add');
    Route::post('update', 'app\controller\api\ComicTagController@update');
    Route::post('delete', 'app\controller\api\ComicTagController@delete');
    Route::post('batchDelete', 'app\controller\api\ComicTagController@batchDelete');
    Route::post('toggleStatus', 'app\controller\api\ComicTagController@toggleStatus');
    Route::post('batchSetStatus', 'app\controller\api\ComicTagController@batchSetStatus');
});
Route::get('api/comic/chapter/list', 'app\controller\Api\ComicMangaController@chapterList');
Route::get('api/comic/manga/chapter/images', 'app\controller\Api\ComicMangaController@chapterImages');
Route::group('api/comic/chapter', function () {
 // 已存在
Route::get(':id', 'app\controller\Api\ComicMangaController@chapterDetail'); // 需要添加
    Route::post('add', 'app\controller\Api\ComicMangaController@chapterAdd'); // 需要添加
    Route::post('update', 'app\controller\Api\ComicMangaController@chapterUpdate'); // 需要添加
    Route::post('delete', 'app\controller\Api\ComicMangaController@chapterDelete'); // 需要添加
    Route::post('batchDelete', 'app\controller\Api\ComicMangaController@chapterBatchDelete'); // 需要添加
    Route::post('batchUpdateSort', 'app\controller\Api\ComicMangaController@chapterBatchUpdateSort'); // 需要添加
    Route::post('setAllVip', 'app\controller\Api\ComicMangaController@setAllChaptersVipByMangaId');
    Route::post('setAllCoin', 'app\controller\Api\ComicMangaController@setAllChaptersCoinByMangaId');
    Route::post('batchSetFree', 'app\controller\Api\ComicMangaController@batchSetChapterFree');
});
// 将动态路由 :id 放到最后
Route::group('api/comic/manga', function () {
    Route::get('list', 'app\controller\Api\ComicMangaController@list');
    Route::get('rankList', 'app\controller\Api\ComicMangaController@rankList');
    
    // ✅ 将这些静态路由放在动态路由前面
    Route::get('daily-updates', 'app\controller\Api\ComicMangaController@dailyUpdates');
    Route::get('weekly-updates', 'app\controller\Api\ComicMangaController@weeklyUpdates');
    Route::get('weekly-all-updates', 'app\controller\Api\ComicMangaController@weeklyAllUpdates');
    
    Route::post('add', 'app\controller\Api\ComicMangaController@add');
    Route::post('update', 'app\controller\Api\ComicMangaController@update');
    Route::post('delete', 'app\controller\Api\ComicMangaController@delete');
    Route::post('batchDelete', 'app\controller\Api\ComicMangaController@batchDelete');
    Route::post('batchSetSerializationStatus', 'app\controller\Api\ComicMangaController@batchSetSerializationStatus');
    Route::post('batchSetShelfStatus', 'app\controller\Api\ComicMangaController@batchSetShelfStatus');
    Route::post('batchSetVip', 'app\controller\Api\ComicMangaController@batchSetVip');
    Route::post('batchSetCoin', 'app\controller\Api\ComicMangaController@batchSetCoin');
    Route::post('batchSetUpdateDay', 'app\controller\Api\ComicMangaController@batchSetUpdateDay');
    
    // ⚠️ 动态路由必须放在最后，避免拦截上面的静态路由
    Route::get(':id', 'app\controller\Api\ComicMangaController@detail');
});

// 充值订单管理相关路由
Route::group('api/recharge/order', function () {
    // Controller class: app\controller\api\RechargeOrderController
    Route::get('list', 'app\controller\api\RechargeOrderController@getList'); // 列表查询
    Route::post('create', 'app\controller\api\RechargeOrderController@create'); // 新增订单
    Route::post('confirm', 'app\controller\api\RechargeOrderController@confirm'); // 确认到账
    Route::post('delete', 'app\controller\api\RechargeOrderController@delete');   // 删除订单
});

// 获取域名/渠道列表的独立接口
Route::get('api/recharge/domains_channels', 'app\controller\api\RechargeOrderController@getDomainsAndChannels');


Route::group('comic/recommend/groups', function () {
    Route::get('/', 'app\controller\api\ComicRecommendController@getGroups'); // 获取列表
    Route::post('/', 'app\controller\api\ComicRecommendController@addGroup'); // 新增
    Route::put('/:id', 'app\controller\api\ComicRecommendController@updateGroup'); // 更新
    Route::delete('/:id', 'app\controller\api\ComicRecommendController@deleteGroup'); // 删除
    Route::post('/sort', 'app\controller\api\ComicRecommendController@sortGroups'); // 保存排序
});
Route::get('api/comic-recommend/group/list', 'app\controller\api\ComicRecommendController@getGroups');
Route::get('api/comic-recommend/group/comics/:groupId', 'app\controller\api\ComicRecommendController@getGroupComics');
Route::get('api/comic-recommend/group/allWithComics', 'app\controller\api\ComicRecommendController@allGroupsWithComics');



// 推荐分组下的漫画管理
Route::group('comic/recommend/groups/:groupId/comics', function () {
    Route::get('/', 'app\controller\api\ComicRecommendController@getGroupComics'); // 获取分组下漫画
    Route::post('/', 'app\controller\api\ComicRecommendController@saveGroupComics'); // 保存分组下漫画
});

// 漫画分类
Route::group('comic/categories', function () {
    Route::get('/parents', 'app\controller\api\ComicRecommendController@getParentCategories'); // 获取主分类
    Route::get('/children', 'app\controller\api\ComicRecommendController@getChildCategories'); // 获取子分类
});

// 所有漫画列表
Route::get('comic/list', 'app\controller\api\ComicRecommendController@getAllComics');

Route::group('api/recommend/anime-groups', function () {
    Route::get('/',        'app\controller\api\AnimeRecommendController@list');         // 获取分组列表
    Route::post('/',       'app\controller\api\AnimeRecommendController@add');          // 添加分组
    Route::put('/:id',     'app\controller\api\AnimeRecommendController@update');       // 更新分组
    Route::delete('/:id',  'app\controller\api\AnimeRecommendController@delete');       // 删除分组
    Route::post('sort',    'app\controller\api\AnimeRecommendController@sort');         // 保存排序

    // 分组下动漫管理
    Route::get('/:id/animes',  'app\controller\api\AnimeRecommendController@groupAnimes');      // 获取分组下动漫
    Route::post('/:id/animes', 'app\controller\api\AnimeRecommendController@saveGroupAnimes');  // 保存分组下动漫
});

// 分类管理
Route::get('api/anime-categories/parents',  'app\controller\api\AnimeRecommendController@parents');
Route::get('api/anime-categories/children', 'app\controller\api\AnimeRecommendController@children');

// 动漫列表
Route::get('api/anime/videos', 'app\controller\api\AnimeRecommendController@allAnimes');


// 暗网推荐分组管理
Route::group('api/darknet/recommend/groups', function () {
    Route::get('/', 'app\controller\api\DarknetRecommendController@list'); // 获取分组列表
    Route::post('/', 'app\controller\api\DarknetRecommendController@add'); // 新增分组
    Route::put('/:id', 'app\controller\api\DarknetRecommendController@update'); // 更新分组
    Route::delete('/:id', 'app\controller\api\DarknetRecommendController@delete'); // 删除分组
    Route::post('/sort', 'app\controller\api\DarknetRecommendController@sort'); // 保存分组排序
});

// 推荐分组下的视频管理
Route::group('api/darknet/recommend/groups/:groupId/videos', function () {
    Route::get('/', 'app\controller\api\DarknetRecommendController@groupVideos'); // 获取分组下视频
    Route::post('/', 'app\controller\api\DarknetRecommendController@saveGroupVideos'); // 保存分组下视频
});

// 暗网视频分类
Route::group('api/darknet/categories', function () {
    Route::get('/parents', 'app\controller\api\DarknetRecommendController@parents'); // 获取主分类
    Route::get('/children', 'app\controller\api\DarknetRecommendController@children'); // 获取子分类
});

// 暗网全部视频列表（可选视频列表）
Route::get('api/darknet/videos', 'app\controller\api\DarknetRecommendController@allVideos');

//文字小说分类控制器
Route::group('api/text_novel_category', function () {
    Route::get('list',      'app\controller\api\TextNovelCategoryController@list');
    Route::post('add',      'app\controller\api\TextNovelCategoryController@add');
    Route::post('update',   'app\controller\api\TextNovelCategoryController@update');
    Route::post('delete',   'app\controller\api\TextNovelCategoryController@delete');
    Route::post('batchDelete',    'app\controller\api\TextNovelCategoryController@batchDelete');
    Route::post('toggleStatus',   'app\controller\api\TextNovelCategoryController@toggleStatus');
    Route::post('batchSetStatus', 'app\controller\api\TextNovelCategoryController@batchSetStatus');
});
// 文字小说标签管理
Route::group('api/text_novel_tag', function () {
    Route::get('list', 'app\controller\api\TextNovelTagController@list');
    Route::post('add', 'app\controller\api\TextNovelTagController@add');
    Route::post('update', 'app\controller\api\TextNovelTagController@update');
    Route::post('delete', 'app\controller\api\TextNovelTagController@delete');
    Route::post('batchDelete', 'app\controller\api\TextNovelTagController@batchDelete');
    Route::post('toggleStatus', 'app\controller\api\TextNovelTagController@toggleStatus');
    Route::post('batchSetStatus', 'app\controller\api\TextNovelTagController@batchSetStatus');
});
// 文字小说管理
Route::group('api/text_novel', function () {
    Route::get('list', 'app\controller\api\TextNovelController@list');
    Route::get(':id', 'app\controller\api\TextNovelController@read'); // 获取单条
    Route::post('add', 'app\controller\api\TextNovelController@add');
    Route::post('update', 'app\controller\api\TextNovelController@update');
    Route::post('delete', 'app\controller\api\TextNovelController@delete');
    Route::post('batchDelete', 'app\controller\api\TextNovelController@batchDelete');
    Route::post('batchSetSerializationStatus', 'app\controller\api\TextNovelController@batchSetSerializationStatus');
    Route::post('batchSetShelfStatus', 'app\controller\api\TextNovelController@batchSetShelfStatus');
    Route::post('batchSetVisibility', 'app\controller\api\TextNovelController@batchSetVisibility');
    Route::post('batchSetVip', 'app\controller\api\TextNovelController@batchSetVip');
    Route::post('batchSetCoin', 'app\controller\api\TextNovelController@batchSetCoin');
    Route::post('batchCancelVip', 'app\controller\api\TextNovelController@batchCancelVip');
});
// 文字小说章节管理
Route::group('api/text_novel_chapter', function () {
    Route::get('list',          'app\controller\api\TextNovelChapterController@list');
    Route::get(':id',           'app\controller\api\TextNovelChapterController@read');
    Route::post('add',          'app\controller\api\TextNovelChapterController@add');
    Route::post('update',       'app\controller\api\TextNovelChapterController@update');
    Route::post('delete',       'app\controller\api\TextNovelChapterController@delete');
    Route::post('batchDelete',  'app\controller\api\TextNovelChapterController@batchDelete');
    Route::post('batchUpdateOrder', 'app\controller\api\TextNovelChapterController@batchUpdateOrder');
    Route::post('setFree',      'app\controller\api\TextNovelChapterController@setFree');
});
// 博主管理路由（严格对齐你的格式）
Route::group('api/influencer', function () {
    Route::get('list',         'app\controller\api\InfluencerController@list');
    Route::post('add',         'app\controller\api\InfluencerController@create');
    Route::post('update',      'app\controller\api\InfluencerController@update');
    Route::post('delete',      'app\controller\api\InfluencerController@delete');
    Route::post('batchDelete', 'app\controller\api\InfluencerController@batchDelete');
    Route::get('countryOptions', 'app\controller\api\InfluencerController@countryOptions');
    Route::get('tagOptions',     'app\controller\api\InfluencerController@tagOptions');
});
Route::group('api/content', function () {
    // 专辑管理
    Route::get('album/list',        'app\controller\api\ContentController@albumList');
    Route::get('album/:id',         'app\controller\api\ContentController@albumRead');
    Route::post('album/add',        'app\controller\api\ContentController@albumAdd');
    Route::post('album/update',     'app\controller\api\ContentController@albumUpdate');
    Route::post('album/delete',     'app\controller\api\ContentController@albumDelete');
    Route::post('album/batchDelete','app\controller\api\ContentController@albumBatchDelete');

    // 视频/图片管理
    Route::get('video/list',        'app\controller\api\ContentController@videoList');
    Route::get('video/:id',         'app\controller\api\ContentController@videoRead');
    Route::post('video/add',        'app\controller\api\ContentController@videoAdd');
    Route::post('video/update',     'app\controller\api\ContentController@videoUpdate');
    Route::post('video/delete',     'app\controller\api\ContentController@videoDelete');
    Route::post('video/batchDelete','app\controller\api\ContentController@videoBatchDelete');

    // 下拉选项
    Route::get('option/influencer', 'app\controller\api\ContentController@optionInfluencer');
    Route::get('option/album',      'app\controller\api\ContentController@optionAlbum');
    Route::get('option/tag',        'app\controller\api\ContentController@optionTag');

    // 视频/图片批量设置VIP
    Route::post('video/batchSetVIP', 'app\controller\api\ContentController@videoBatchSetVIP');
    // 视频/图片批量设置金币
    Route::post('video/batchSetCoin', 'app\controller\api\ContentController@videoBatchSetCoin');
    // 专辑设置VIP（同步内容）
    Route::post('album/setVIP', 'app\controller\api\ContentController@albumSetVIP');
    // 专辑设置金币（同步内容）
    Route::post('album/setCoin', 'app\controller\api\ContentController@albumSetCoin');
});

// 博主标签
Route::group('api/tag', function () {
    Route::get('list',        'app\controller\api\TagController@list');
    Route::get(':id',         'app\controller\api\TagController@read');   // 获取单条
    Route::post('add',        'app\controller\api\TagController@add');
    Route::post('update',     'app\controller\api\TagController@update');
    Route::post('delete',     'app\controller\api\TagController@delete');
    Route::post('batchDelete','app\controller\api\TagController@batchDelete');
});
// 博主分组
Route::group('api/influencer/group', function () {
    Route::get('list',     'app\controller\api\InfluencerGroupController@list');       // 获取分组列表
    Route::post('add',     'app\controller\api\InfluencerGroupController@add');        // 新增分组
    Route::post('update/:id', 'app\controller\api\InfluencerGroupController@update');  // 更新分组
    Route::post('delete/:id', 'app\controller\api\InfluencerGroupController@delete');  // 删除分组
});
// 小说推荐分组管理
Route::group('api/novel/recommend/groups', function () {
    Route::get('/', 'app\controller\api\NovelRecommendController@getGroups');     // 获取列表
    Route::post('/', 'app\controller\api\NovelRecommendController@addGroup');    // 新增
    Route::put('/:id', 'app\controller\api\NovelRecommendController@updateGroup'); // 更新
    Route::delete('/:id', 'app\controller\api\NovelRecommendController@deleteGroup'); // 删除
    Route::post('/sort', 'app\controller\api\NovelRecommendController@sortGroups'); // 保存排序
});

// 推荐分组下的小说管理
Route::group('api/novel/recommend/groups/:groupId/novels', function () {
    Route::get('/', 'app\controller\api\NovelRecommendController@getGroupNovels');  // 获取分组小说
    Route::post('/', 'app\controller\api\NovelRecommendController@saveGroupNovels'); // 保存分组小说
});
// 新增这个接口（推荐页前端用的）
Route::get('api/novel-recommend/group/allWithNovels', 'app\controller\api\NovelRecommendController@allWithNovels');
Route::get('api/novel-recommend/group/:groupId/novels', 'app\controller\api\NovelRecommendController@getGroupNovelsPaginated');
// 小说分类
Route::group('api/novel/categories', function () {
    Route::get('/', 'app\controller\api\NovelRecommendController@getAllCategories'); // 新增
    Route::get('/parents', 'app\controller\api\NovelRecommendController@getParentCategories');
    Route::get('/children', 'app\controller\api\NovelRecommendController@getChildCategories');
});

// 所有小说列表
Route::get('api/novel/list', 'app\controller\api\NovelRecommendController@getAllNovels');
//有声小说
Route::group('api/audio_novel', function () {
    Route::get('list', 'app\controller\api\AudioNovelController@list');
    Route::get('detail', 'app\controller\api\AudioNovelController@detail'); // 新增这一行
    Route::get(':id', 'app\controller\api\AudioNovelController@detail');
    Route::post('add', 'app\controller\api\AudioNovelController@add'); // 新增
    Route::post('update', 'app\controller\api\AudioNovelController@update'); // 更新
    Route::post('delete', 'app\controller\api\AudioNovelController@delete'); // 删除
    Route::post('batchDelete', 'app\controller\api\AudioNovelController@batchDelete'); // 批量删除
    Route::post('batchSetSerializationStatus', 'app\controller\api\AudioNovelController@batchSetSerializationStatus'); // 批量设置连载状态
    Route::post('batchSetShelfStatus', 'app\controller\api\AudioNovelController@batchSetShelfStatus'); // 批量设置上架状态
    Route::post('batchSetVisibility', 'app\controller\api\AudioNovelController@batchSetVisibility'); // 批量设置可见性
    Route::post('batchSetVip', 'app\controller\api\AudioNovelController@batchSetVip'); // 批量设置VIP
    Route::post('batchSetCoin', 'app\controller\api\AudioNovelController@batchSetCoin'); // 批量设置每集金币
    Route::post('batchCancelVip', 'app\controller\api\AudioNovelController@batchCancelVip'); // 批量取消VIP
    Route::post('batchSetNarrator', 'app\controller\api\AudioNovelController@batchSetNarrator'); // 批量设置演播人
});
//有声小说分类
Route::group('api/audio_novel_category', function () {
    Route::get('list',      'app\controller\api\AudioNovelCategoryController@list');
    Route::post('add',      'app\controller\api\AudioNovelCategoryController@add');
    Route::post('update',   'app\controller\api\AudioNovelCategoryController@update');
    Route::post('delete',   'app\controller\api\AudioNovelCategoryController@delete');
    Route::post('batchDelete',    'app\controller\api\AudioNovelCategoryController@batchDelete');
    Route::post('batchSetStatus', 'app\controller\api\AudioNovelCategoryController@batchSetStatus');
});
// 有声小说章节
Route::group('api/audio_novel_chapter', function () {
    Route::get('list',      'app\controller\api\AudioNovelChapterController@list');
    Route::get(':id',       'app\controller\api\AudioNovelChapterController@detail');
    Route::post('add',      'app\controller\api\AudioNovelChapterController@add');
    Route::post('update',   'app\controller\api\AudioNovelChapterController@update');
    Route::post('delete',   'app\controller\api\AudioNovelChapterController@delete');
    Route::post('batchDelete',    'app\controller\api\AudioNovelChapterController@batchDelete');
    Route::post('batchUpdateOrder', 'app\controller\api\AudioNovelChapterController@batchUpdateOrder');
    Route::post('setFree',  'app\controller\api\AudioNovelChapterController@setFree');
    // === 这里新加一行 ===
    Route::post('play',     'app\controller\api\AudioNovelChapterController@play');
});

//有声小说标签
Route::group('api/audio_novel_tag', function () {
    Route::get('list',      'app\controller\api\AudioNovelTagController@list');
    Route::post('add',      'app\controller\api\AudioNovelTagController@add');
    Route::post('update',   'app\controller\api\AudioNovelTagController@update');
    Route::post('delete',   'app\controller\api\AudioNovelTagController@delete');
    Route::post('batchDelete',    'app\controller\api\AudioNovelTagController@batchDelete');
    Route::post('batchSetStatus', 'app\controller\api\AudioNovelTagController@batchSetStatus');
});

// 有声小说推荐分组及分组小说
Route::group('api/audio/recommend', function () {
    // 一定要先写精确匹配的（audiosPaginated最前！）
    Route::get('groups/:groupId/audiosPaginated', 'app\controller\api\AudioRecommendGroupController@getGroupAudiosPaginated');
    Route::get('groups/:groupId/novels', 'app\controller\api\AudioRecommendGroupController@novelList');
    Route::post('groups/:groupId/novels', 'app\controller\api\AudioRecommendGroupController@saveNovels');

    // 其它通用groups路由放后面！
    Route::get('groups', 'app\controller\api\AudioRecommendGroupController@list');
    Route::post('groups', 'app\controller\api\AudioRecommendGroupController@add');
    Route::put('groups/:id', 'app\controller\api\AudioRecommendGroupController@update');
    Route::delete('groups/:id', 'app\controller\api\AudioRecommendGroupController@delete');
    Route::post('groups/sort', 'app\controller\api\AudioRecommendGroupController@saveSort');
    Route::get('allWithAudios', 'app\controller\api\AudioRecommendGroupController@allWithAudios');
});
Route::group('api', function () {

    // ===== 弹窗配置 =====
    Route::get('popup_config', 'api.PopupConfigController/getConfig');
    Route::post('popup_config/save', 'api.PopupConfigController/saveConfig');

    // ===== 支付通道管理（后台用）=====
    Route::get('payment_channel/list', 'api.PaymentChannelController/list');
    Route::post('payment_channel/create', 'api.PaymentChannelController/create');
    Route::put('payment_channel/update/:id', 'api.PaymentChannelController/update')->pattern(['id' => '\d+']);
    Route::delete('payment_channel/delete/:id', 'api.PaymentChannelController/delete')->pattern(['id' => '\d+']);
    Route::put('payment_channel/status/:id', 'api.PaymentChannelController/status')->pattern(['id' => '\d+']);
    Route::get('payment_channels/list_enabled', 'api.PaymentChannelController/listEnabled');

    // ===== 支付通道（H5 前台用的精简接口）=====
    Route::get('payment_channels/h5', 'api.PaymentChannelController/listForH5');

    // （可选）给 PUT/DELETE 跨域预检放行
    Route::options('payment_channel/<any?>', fn() => response('', 204))->pattern(['any' => '.*']);
    Route::options('payment_channels/<any?>', fn() => response('', 204))->pattern(['any' => '.*']);
});
// =========================================================
//                         系统配置管理接口
// =========================================================

Route::group('api/site-config', function () {
    Route::get('all', '\app\controller\api\SiteConfigController@getAll');
    Route::post('update', '\app\controller\api\SiteConfigController@updateAll');
    Route::get('group-links', '\app\controller\api\SiteConfigController@getGroupLinks');
});
//浏览记录
Route::get('api/content/type-list', 'app\controller\api\UserBrowseLogController@typeList');
Route::get('api/content/category-list', 'app\controller\api\UserBrowseLogController@categoryList');
Route::get('api/user/browse/logs', 'app\controller\api\UserBrowseLogController@logs');
Route::post('api/user/browse/add', 'app\controller\api\UserBrowseLogController@add');
Route::get('api/h5/browse/logs', 'app\controller\api\UserBrowseLogController@h5List');
Route::get('api/h5/user/browse_history', 'app\controller\api\UserBrowseLogController@h5List');
Route::post('api/h5/unlock/comic_chapter', 'api.UnlockController/comicChapter');
Route::get('api/h5/unlock/unlocked_chapters', 'api.UnlockController/unlockedChapters');
Route::post('api/h5/unlock/novel_chapter', 'api.UnlockController/novelChapter');
Route::get('api/h5/unlock/unlocked_novel_chapters', 'api.UnlockController/unlockedNovelChapters');
Route::post('api/h5/unlock/comic_whole', 'api.UnlockController/comicWhole');
Route::post('api/h5/unlock/novel_whole', 'api.UnlockController/novelWhole');
Route::post('api/h5/unlock/anime_video', 'api.UnlockController/animeVideo');
Route::post('api/h5/unlock/star_video', 'api.UnlockController/starVideo');

// 有声小说解锁相关
Route::post('api/h5/unlock/audio_novel_chapter', 'api.UnlockController/audioNovelChapter');
Route::get('api/h5/unlock/unlocked_audio_novel_chapters', 'api.UnlockController/unlockedAudioNovelChapters');


// 热门搜索关键词接口（所有大类共用一个接口）
Route::get('api/search/hot_keywords', 'app\controller\api\SearchController@hotKeywords');
// 热门搜索关键词后台管理（管理后台用！）
Route::group('api/search/hot_keyword', function () {
    Route::get('list',    'app\controller\api\SearchController@hotKeywordList');   // 列表
    Route::post('add',    'app\controller\api\SearchController@addHotKeyword');    // 新增
    Route::post('update', 'app\controller\api\SearchController@updateHotKeyword'); // 更新
    Route::post('delete', 'app\controller\api\SearchController@deleteHotKeyword'); // 删除
    Route::post('sort',   'app\controller\api\SearchController@sortHotKeyword');   // 排序(批量)
});

// =========================================================
//                     抖音关键词管理接口
// =========================================================

// 抖音关键词管理 (基础CRUD)
Route::group('api/douyin/keywords', function () {
    Route::get('', '\app\api\controller\DouyinKeywordController@index');                    // 获取列表
    Route::get('enabled', '\app\api\controller\DouyinKeywordController@enabled');           // 获取启用的关键词
    Route::get('random', '\app\api\controller\DouyinKeywordController@random');             // 随机获取关键词
    Route::get('stats', '\app\api\controller\DouyinKeywordController@stats');               // 统计信息
    Route::post('', '\app\api\controller\DouyinKeywordController@save');                    // 添加关键词
    Route::get(':id', '\app\api\controller\DouyinKeywordController@read');                  // 获取单个关键词
    Route::put(':id', '\app\api\controller\DouyinKeywordController@update');                // 更新关键词
    Route::delete(':id', '\app\api\controller\DouyinKeywordController@delete');             // 删除关键词
    Route::put(':id/sort', '\app\api\controller\DouyinKeywordController@updateSort');       // 更新排序
    Route::post(':id/display', '\app\api\controller\DouyinKeywordController@recordDisplay'); // 记录显示
    Route::post(':id/click', '\app\api\controller\DouyinKeywordController@recordClick');     // 记录点击
});

// 抖音关键词批量操作
Route::group('api/douyin/keywords/batch', function () {
    Route::post('status', '\app\api\controller\DouyinKeywordController@batchStatus');        // 批量更新状态
    Route::post('delete', '\app\api\controller\DouyinKeywordController@batchDelete');        // 批量删除
});
Route::get('api/h5/long/home', 'app\controller\api\LongHomeRecommendController@h5Home');
Route::get('api/h5/long/videos/:id', 'app\controller\api\LongHomeRecommendController@h5Detail');
Route::get('api/h5/long/group/:groupId/videos', 'api.LongHomeRecommendController/h5GroupVideos');
Route::get('api/h5/long_videos/all', 'api.LongVideoController/h5AllVideos');
Route::get('api/h5/long_videos/guess_you_like', 'api.LongVideoController/h5GuessYouLike');
Route::get('api/h5/douyin/videos', '\app\controller\api\VideoController@h5List');
Route::post('api/h5/video/track', 'app\controller\api\LongVideoController@track'); // 行为埋点
Route::get('api/h5/video/rank', 'app\controller\api\LongVideoController@rank');    // 榜单接口
Route::get('api/h5/long_videos/limited_free', 'api.LongVideoController/h5LimitedFree');//免费
Route::get('api/h5/darknet/group/:groupId/videos', 'api.DarknetRecommendController/h5GroupVideos');
Route::get('api/h5/long_videos/:id', 'api.LongVideoController/h5Detail');
Route::get('api/h5/darknet/home', 'app\controller\api\DarknetRecommendController@h5Home');
Route::get('api/h5/darknet/categories/list', 'api.DarknetCategoryController/h5List');
Route::get('api/h5/darknet/category/:category_id/videos', 'api.DarknetVideoController/categoryVideos');
Route::get('api/h5/douyin/videos/:id', '\app\controller\api\VideoController@getVideoById');
Route::post('api/h5/douyin/play', '\app\controller\api\VideoController@play');
Route::post('api/h5/unlock/douyin_video', 'api.UnlockController/douyinVideo');
Route::get('api/h5/douyin/tag/all', 'api.DouyinTagController/all');
Route::get('api/h5/douyin/discover', 'app\controller\api\VideoController@h5DiscoverList');
Route::get('api/h5/douyin/video/detail', 'api.VideoController/h5VideoDetail');

// =========================================================
//                         用户操作接口 (点赞、收藏)
// =========================================================

Route::group('api/h5/user', function () {
    Route::post('like', '\app\controller\api\UserActionController@like');                    // 点赞
    Route::post('unlike', '\app\controller\api\UserActionController@unlike');                // 取消点赞
    Route::post('collect', '\app\controller\api\UserActionController@collect');              // 收藏
    Route::post('uncollect', '\app\controller\api\UserActionController@uncollect');          // 取消收藏
    Route::get('action_status', '\app\controller\api\UserActionController@getActionStatus'); // 获取用户操作状态
    Route::post('batch_action_status', '\app\controller\api\UserActionController@batchActionStatus'); // 批量获取操作状态
});
Route::get('api/h5/douyin/search', '\app\controller\api\VideoController@searchVideos');

/* ========== OnlyFans 分类管理接口（一级分类）========== */
Route::group('api/onlyfans/categories', function () {
    Route::get('list',            'list');               // 获取分类和博主列表
    Route::get('detail/:id',      'detail');             // 获取分类详情
    Route::get('statistics',      'statistics');         // 获取统计信息
    Route::post('add',            'add');                // 新增分类
    Route::post('update',         'update');             // 更新分类
    Route::post('delete',         'delete');             // 删除分类
    Route::post('batch-delete',   'batchDelete');        // 批量删除
    Route::post('batch-update-sort','batchUpdateSort');  // 批量排序
})->prefix('app\controller\api\OnlyFansCategoryController@')
  ->pattern(['id' => '\d+']);

/* ========== OnlyFans 博主管理接口 ========== */
Route::group('api/onlyfans/creators', function () {
    Route::get('list',              'list');                  // 博主列表
    Route::get('detail/:id',        'detail');                // ✅ 建议指向 CreatorController@detail
    Route::post('add',              'add');                   // 新增博主
    Route::post('update',           'update');                // 更新博主
    Route::post('delete',           'delete');                // 删除博主
    Route::post('batch-delete',     'batchDelete');           // 批量删除
    Route::post('batch-update-sort','batchUpdateSort');       // 批量排序
    Route::post('batch-set-status', 'batchSetStatus');        // 批量设置状态
    Route::post('batch-set-category','batchSetCategory');     // 批量设置分类
})->prefix('app\controller\api\OnlyFansCreatorController@')
  ->pattern(['id' => '\d+']);

/* ========== OnlyFans 标签管理接口 ========== */
Route::group('api/onlyfans/tag', function () {
    Route::get('list',              'list');           // 标签列表
    Route::get('options',           'options');        // 标签选项（供下拉选择）
    Route::post('add',              'add');            // 新增标签
    Route::post('update',           'update');         // 更新标签
    Route::post('delete',           'delete');         // 删除标签
    Route::post('batch-delete',     'batchDelete');    // 批量删除
    Route::post('toggle-status',    'toggleStatus');   // 切换状态
})->prefix('app\controller\api\OnlyFansTagController@');

/* ========== OnlyFans 内容管理接口中添加标签相关路由 ========== */
Route::group('api/onlyfans/media', function () {
    Route::get('list',              'list');             // 内容列表
    Route::post('add',              'add');              // 新增内容
    Route::post('update',           'update');           // 更新内容
    Route::post('delete',           'delete');           // 删除内容
    Route::post('batch-delete',     'batchDelete');      // 批量删除
    Route::post('update-sort',      'updateSort');       // 更新排序

    // 批量设置
    Route::post('batch-set-vip',    'batchSetVip');
    Route::post('batch-set-gold',   'batchSetGold');
    Route::post('batch-set-status', 'batchSetStatus');

    // 标签相关接口 - 新增这两行
    Route::post('set-tags',         'setTags');          // 设置视频标签
    Route::get('tags/:id',          'getTags');          // 获取视频标签

    // 图片子表（前端"添加图片URL"会调这里）
    Route::get('images',            'getImagesByMediaId');  // ?media_id=xx
    Route::post('images/save',      'saveImageUrls');
    Route::post('images/delete',    'deleteImage');

    // 动态 :id 放最后且限制为数字，避免与上面 images 冲突
    Route::get(':id',               'getById')->pattern(['id' => '\d+']);
})->prefix('app\controller\api\OnlyFansMediaController@');

/* ========== H5 前台 OnlyFans 接口 ========== */
Route::group('api/h5/onlyfans', function () {
    Route::get('categories',            'categories');         // 分类列表
    Route::get('creators/:categoryId',  'creators');           // 按分类的博主列表

    // 拆分后的博主资料 & 媒体列表
    Route::get('creator/:id/profile',   'creatorProfile');     // 博主资料
    Route::get('creator/:id/media',     'creatorMedia');       // ?type=image|video&page=1

    // 旧的合并详情（兼容）
    Route::get('creator/:id',           'creatorDetail');
    Route::get('media/:id/images',        'mediaImages');
    Route::get('media/:id',             'mediaDetail');        // 内容详情
    Route::get('search',                'search');             // 搜索

    // 新增标签筛选接口
    Route::get('media/by-tag',          'mediaByTag');         // 根据标签筛选内容
})->prefix('app\controller\api\OnlyFansH5Controller@')
  ->pattern([
      'id'         => '\d+',
      'categoryId' => '\d+',
  ]);