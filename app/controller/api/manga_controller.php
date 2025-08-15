<?php
// File path: 例如 public/api/manga_controller.php 或者 /path/to/your/backend/manga_controller.php

// 设置响应头，允许跨域访问，并指定返回JSON格式
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// 处理OPTIONS预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/**
 * 模拟的响应数据结构
 */
class Response {
    public $code;
    public $msg;
    public $data;

    public function __construct($code, $msg, $data = null) {
        $this->code = $code;
        $this->msg = $msg;
        $this->data = $data;
    }

    public static function success($data = null, $msg = "success") {
        return new Response(0, $msg, $data);
    }

    public static function fail($msg = "fail", $code = 1) {
        return new Response($code, $msg, null);
    }

    public function toJson() {
        return json_encode($this);
    }
}

/**
 * 模拟的漫画数据存储（实际项目中会使用数据库）
 * 每次请求时重新加载，所以数据不会持久化。
 * 如果需要持久化，可以考虑写入文件或连接数据库。
 */
function getMockMangaDb() {
    // 模拟的漫画列表
    return [
        (object)[
            "id" => 1,
            "title" => "奇幻冒险之旅",
            "description" => "在一个充满魔法的世界...",
            "main_category_id" => 1,
            "sub_category_id" => 10,
            "tags" => ["奇幻", "冒险"],
            "cover_url" => "https://placehold.co/100x150/FF6347/FFFFFF?text=Manga1",
            "chapter_count" => 5,
            "serialization_status" => 1, // 1:连载中, 0:已完结
            "shelf_status" => 1,         // 1:上架, 0:下架
            "is_vip" => 1,               // 1:VIP, 0:非VIP
            "coin" => 10,
            "publish_time" => "2023-01-01 10:00:00",
            "update_time" => "2023-01-01 10:00:00"
        ],
        (object)[
            "id" => 2,
            "title" => "校园恋爱日常",
            "description" => "高中生的甜蜜生活...",
            "main_category_id" => 2,
            "sub_category_id" => 20,
            "tags" => ["校园", "恋爱"],
            "cover_url" => "https://placehold.co/100x150/4682B4/FFFFFF?text=Manga2",
            "chapter_count" => 12,
            "serialization_status" => 1,
            "shelf_status" => 1,
            "is_vip" => 0,
            "coin" => 0,
            "publish_time" => "2023-02-15 12:30:00",
            "update_time" => "2023-02-15 12:30:00"
        ],
        (object)[
            "id" => 3,
            "title" => "科幻未来探索",
            "description" => "AI与人类的共存...",
            "main_category_id" => 1,
            "sub_category_id" => 10,
            "tags" => ["科幻", "未来"],
            "cover_url" => "https://placehold.co/100x150/6A5ACD/FFFFFF?text=Manga3",
            "chapter_count" => 8,
            "serialization_status" => 0,
            "shelf_status" => 1,
            "is_vip" => 1,
            "coin" => 5,
            "publish_time" => "2023-03-20 14:00:00",
            "update_time" => "2023-03-20 14:00:00"
        ],
    ];
}

/**
 * 模拟的章节数据存储
 */
function getMockChapterDb() {
    return [
        (object)[
            "id" => 101,
            "manga_id" => 1,
            "title" => "第一章：新的开始",
            "chapter_order" => 1,
            "page_count" => 2,
            "publish_time" => "2023-01-01 10:00:00",
            "pages" => [
                (object)["url" => "https://placehold.co/800x1200/FF0000/FFFFFF?text=M1C1P1"],
                (object)["url" => "https://placehold.co/800x1200/00FF00/FFFFFF?text=M1C1P2"]
            ]
        ],
        (object)[
            "id" => 102,
            "manga_id" => 1,
            "title" => "第二章：迷雾森林",
            "chapter_order" => 2,
            "page_count" => 2,
            "publish_time" => "2023-01-05 11:00:00",
            "pages" => [
                (object)["url" => "https://placehold.co/800x1200/0000FF/FFFFFF?text=M1C2P1"],
                (object)["url" => "https://placehold.co/800x1200/FFFF00/FFFFFF?text=M1C2P2"]
            ]
        ],
        (object)[
            "id" => 201,
            "manga_id" => 2,
            "title" => "第一话：偶遇",
            "chapter_order" => 1,
            "page_count" => 1,
            "publish_time" => "2023-02-15 12:30:00",
            "pages" => [
                (object)["url" => "https://placehold.co/800x1200/FF00FF/FFFFFF?text=M2C1P1"]
            ]
        ]
    ];
}

// 获取请求方法和路径
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// 移除基础路径前缀，这里假设你的API路径是 /api/comic/manga/...
// 你需要根据实际部署的URL路径调整这里
$basePath = '/api/comic/manga';
if (strpos($path, $basePath) === 0) {
    $endpoint = substr($path, strlen($basePath));
} else {
    // 如果路径不匹配基础路径，可能需要返回错误或处理
    echo Response::fail("Invalid API endpoint")->toJson();
    exit();
}


// 解析JSON请求体
$input = file_get_contents('php://input');
$requestData = json_decode($input);

// 获取GET请求参数
$queryParams = $_GET;

// 获取模拟数据库
$mangaDb = getMockMangaDb();
$chapterDb = getMockChapterDb();


// --- API 路由处理 ---
switch ($method) {
    case 'GET':
        if ($endpoint === '/list') {
            // GET /api/comic/manga/list
            $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
            $pageSize = isset($queryParams['pageSize']) ? (int)$queryParams['pageSize'] : 10;
            $keyword = isset($queryParams['keyword']) ? $queryParams['keyword'] : '';
            $mainCategoryId = isset($queryParams['mainCategoryId']) ? (int)$queryParams['mainCategoryId'] : null;
            $subCategoryId = isset($queryParams['subCategoryId']) ? (int)$queryParams['subCategoryId'] : null;
            $tag = isset($queryParams['tag']) ? $queryParams['tag'] : '';
            $serializationStatus = isset($queryParams['serializationStatus']) ? (int)$queryParams['serializationStatus'] : null;
            $shelfStatus = isset($queryParams['shelfStatus']) ? (int)$queryParams['shelfStatus'] : null;

            $filteredList = array_filter($mangaDb, function($manga) use ($keyword, $mainCategoryId, $subCategoryId, $tag, $serializationStatus, $shelfStatus) {
                $matches = true;
                if (!empty($keyword)) {
                    $matches = (strpos($manga->title, $keyword) !== false) ||
                               (strpos((string)$manga->id, $keyword) !== false) ||
                               (in_array($keyword, $manga->tags));
                }
                if ($mainCategoryId !== null) {
                    $matches = $matches && ($manga->main_category_id == $mainCategoryId);
                }
                if ($subCategoryId !== null) {
                    $matches = $matches && ($manga->sub_category_id == $subCategoryId);
                }
                if (!empty($tag)) {
                    $matches = $matches && (in_array($tag, $manga->tags));
                }
                if ($serializationStatus !== null) {
                    $matches = $matches && ($manga->serialization_status == $serializationStatus);
                }
                if ($shelfStatus !== null) {
                    $matches = $matches && ($manga->shelf_status == $shelfStatus);
                }
                return $matches;
            });

            // 模拟分页
            $total = count($filteredList);
            $offset = ($page - 1) * $pageSize;
            $paginatedList = array_slice($filteredList, $offset, $pageSize);

            echo Response::success([
                "list" => array_values($paginatedList), // 重置数组索引
                "total" => $total
            ])->toJson();
        } else if (preg_match('/^\/(\d+)$/', $endpoint, $matches)) {
            // GET /api/comic/manga/{id}
            $mangaId = (int)$matches[1];
            $manga = null;
            foreach ($mangaDb as $m) {
                if ($m->id === $mangaId) {
                    $manga = $m;
                    break;
                }
            }
            if ($manga) {
                echo Response::success($manga)->toJson();
            } else {
                echo Response::fail("漫画未找到")->toJson();
            }
        } else if (preg_match('/^\/chapters\/(\d+)$/', $endpoint, $matches)) {
            // GET /api/comic/manga/chapters/{mangaId}
            $mangaId = (int)$matches[1];
            $chapters = array_values(array_filter($chapterDb, function($chapter) use ($mangaId) {
                return $chapter->manga_id === $mangaId;
            }));
            echo Response::success(["chapters" => $chapters])->toJson();
        } else {
            echo Response::fail("API endpoint not found for GET")->toJson();
        }
        break;

    case 'POST':
        if ($endpoint === '/add') {
            // POST /api/comic/manga/add
            // 简单的数据校验
            if (!isset($requestData->title) || !isset($requestData->main_category_id)) {
                echo Response::fail("标题和主分类为必填项")->toJson();
                exit();
            }

            $newId = 1;
            if (!empty($mangaDb)) {
                $newId = max(array_map(function($m) { return $m->id; }, $mangaDb)) + 1;
            }

            $newManga = (object)[
                "id" => $newId,
                "title" => $requestData->title,
                "description" => isset($requestData->description) ? $requestData->description : '',
                "main_category_id" => (int)$requestData->main_category_id,
                "sub_category_id" => isset($requestData->sub_category_id) ? (int)$requestData->sub_category_id : null,
                "tags" => isset($requestData->tags) ? $requestData->tags : [],
                "cover_url" => isset($requestData->cover_url) ? $requestData->cover_url : '',
                "chapter_count" => 0, // 新增时章节数为0
                "serialization_status" => isset($requestData->serialization_status) ? (int)$requestData->serialization_status : 1,
                "shelf_status" => isset($requestData->shelf_status) ? (int)$requestData->shelf_status : 1,
                "is_vip" => isset($requestData->is_vip) ? (int)$requestData->is_vip : 0,
                "coin" => isset($requestData->coin) ? (int)$requestData->coin : 0,
                "publish_time" => date('Y-m-d H:i:s'),
                "update_time" => date('Y-m-d H:i:s')
            ];
            // 实际：将新漫画保存到数据库
            // 模拟：添加到内存数组
            $mangaDb[] = $newManga; // 注意：这里修改的是局部变量 $mangaDb，实际需要写入数据库

            echo Response::success("新增成功")->toJson();

        } else if ($endpoint === '/update') {
            // POST /api/comic/manga/update
            if (!isset($requestData->id) || !isset($requestData->title) || !isset($requestData->main_category_id)) {
                echo Response::fail("ID、标题和主分类为必填项")->toJson();
                exit();
            }

            $mangaId = (int)$requestData->id;
            $found = false;
            foreach ($mangaDb as $key => $manga) {
                if ($manga->id === $mangaId) {
                    $mangaDb[$key]->title = $requestData->title;
                    $mangaDb[$key]->description = isset($requestData->description) ? $requestData->description : '';
                    $mangaDb[$key]->main_category_id = (int)$requestData->main_category_id;
                    $mangaDb[$key]->sub_category_id = isset($requestData->sub_category_id) ? (int)$requestData->sub_category_id : null;
                    $mangaDb[$key]->tags = isset($requestData->tags) ? $requestData->tags : [];
                    $mangaDb[$key]->cover_url = isset($requestData->cover_url) ? $requestData->cover_url : '';
                    $mangaDb[$key]->serialization_status = isset($requestData->serialization_status) ? (int)$requestData->serialization_status : 1;
                    $mangaDb[$key]->shelf_status = isset($requestData->shelf_status) ? (int)$requestData->shelf_status : 1;
                    $mangaDb[$key]->is_vip = isset($requestData->is_vip) ? (int)$requestData->is_vip : 0;
                    $mangaDb[$key]->coin = isset($requestData->coin) ? (int)$requestData->coin : 0;
                    $mangaDb[$key]->update_time = date('Y-m-d H:i:s');
                    $found = true;
                    break;
                }
            }

            if ($found) {
                echo Response::success("更新成功")->toJson();
            } else {
                echo Response::fail("漫画未找到")->toJson();
            }

        } else if ($endpoint === '/delete') {
            // POST /api/comic/manga/delete
            if (!isset($requestData->id)) {
                echo Response::fail("ID为必填项")->toJson();
                exit();
            }
            $mangaId = (int)$requestData->id;
            $initialCount = count($mangaDb);
            $mangaDb = array_filter($mangaDb, function($m) use ($mangaId) {
                return $m->id !== $mangaId;
            });
            if (count($mangaDb) < $initialCount) {
                echo Response::success("删除成功")->toJson();
            } else {
                echo Response::fail("漫画未找到")->toJson();
            }

        } else if ($endpoint === '/batchDelete') {
            // POST /api/comic/manga/batchDelete
            if (!isset($requestData->ids) || !is_array($requestData->ids)) {
                echo Response::fail("ID列表为必填项且必须是数组")->toJson();
                exit();
            }
            $initialCount = count($mangaDb);
            $mangaDb = array_filter($mangaDb, function($m) use ($requestData) {
                return !in_array($m->id, $requestData->ids);
            });
            if (count($mangaDb) < $initialCount) {
                echo Response::success("批量删除成功")->toJson();
            } else {
                echo Response::fail("没有找到要删除的漫画")->toJson();
            }

        } else if ($endpoint === '/batchSetSerializationStatus') {
            // POST /api/comic/manga/batchSetSerializationStatus
            if (!isset($requestData->ids) || !is_array($requestData->ids) || !isset($requestData->status)) {
                echo Response::fail("ID列表和状态为必填项")->toJson();
                exit();
            }
            foreach ($mangaDb as $key => $manga) {
                if (in_array($manga->id, $requestData->ids)) {
                    $mangaDb[$key]->serialization_status = (int)$requestData->status;
                }
            }
            echo Response::success("批量设置连载状态成功")->toJson();

        } else if ($endpoint === '/batchSetShelfStatus') {
            // POST /api/comic/manga/batchSetShelfStatus
            if (!isset($requestData->ids) || !is_array($requestData->ids) || !isset($requestData->status)) {
                echo Response::fail("ID列表和状态为必填项")->toJson();
                exit();
            }
            foreach ($mangaDb as $key => $manga) {
                if (in_array($manga->id, $requestData->ids)) {
                    $mangaDb[$key]->shelf_status = (int)$requestData->status;
                }
            }
            echo Response::success("批量设置上架状态成功")->toJson();

        } else if ($endpoint === '/batchSetVip') {
            // POST /api/comic/manga/batchSetVip
            if (!isset($requestData->ids) || !is_array($requestData->ids) || !isset($requestData->is_vip)) {
                echo Response::fail("ID列表和VIP状态为必填项")->toJson();
                exit();
            }
            foreach ($mangaDb as $key => $manga) {
                if (in_array($manga->id, $requestData->ids)) {
                    $mangaDb[$key]->is_vip = (int)$requestData->is_vip;
                }
            }
            echo Response::success("批量设置VIP状态成功")->toJson();

        } else if ($endpoint === '/batchSetCoin') {
            // POST /api/comic/manga/batchSetCoin
            if (!isset($requestData->ids) || !is_array($requestData->ids) || !isset($requestData->coin)) {
                echo Response::fail("ID列表和金币数量为必填项")->toJson();
                exit();
            }
            foreach ($mangaDb as $key => $manga) {
                if (in_array($manga->id, $requestData->ids)) {
                    $mangaDb[$key]->coin = (int)$requestData->coin;
                }
            }
            echo Response::success("批量设置金币成功")->toJson();
        }
        else {
            echo Response::fail("API endpoint not found for POST")->toJson();
        }
        break;

    default:
        echo Response::fail("Method not allowed")->toJson();
        break;
}

?>
