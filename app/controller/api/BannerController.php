<?php
// 文件路径: app/controller/api/BannerController.php
// 请确保此文件确实位于项目根目录下的 app/controller/api/ 路径中

namespace app\controller\api; // 确保此命名空间与文件实际路径完全匹配

use app\BaseController;
use think\Request;
use think\facade\Db;
use think\facade\Filesystem;

// 辅助函数定义 (如果你的项目中没有全局定义的话，请确保这些函数在你的项目中是可用的)
// 通常这些会在 app/BaseController 或公共函数文件里
if (!function_exists('successJson')) {
    function successJson($data = [], $message = '操作成功', $code = 0)
    {
        return json(['code' => $code, 'msg' => $message, 'data' => $data]);
    }
}

if (!function_exists('errorJson')) {
    function errorJson($message = '操作失败', $code = 1, $data = [])
    {
        return json(['code' => $code, 'msg' => $message, 'data' => $data]);
    }
}

class BannerController extends BaseController
{
    /**
     * 获取轮播广告列表
     * GET /banner/list (实际路由前缀由路由文件控制，此处仅表示对应功能)
     * 支持分页
     */
    public function list(Request $request)
    {
        $params = $request->get();

        $page = max(1, intval($params['page'] ?? 1));
        $pageSize = max(1, intval($params['pageSize'] ?? 10));

        // 构建查询基础
        $query = Db::name('banners'); // 假设广告表名为 'banners'

        // 可选：根据标题筛选
        if (!empty($params['title'])) {
            $query->where('title', 'like', '%' . $params['title'] . '%');
        }

        // 可选：根据状态筛选
        if (isset($params['status']) && $params['status'] !== '') {
            $query->where('status', '=', intval($params['status']));
        }

        // 计算总数（在分页之前执行count）
        $total = $query->count();

        // 查询广告列表
        $list = $query->order('sort_order asc, id desc') // 默认按排序号升序，ID降序
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return successJson([
            'list' => $list,
            'total' => $total
        ], '获取列表成功');
    }

    /**
     * 新增轮播广告
     * POST /banner/add (实际路由前缀由路由文件控制)
     */
    public function add(Request $request)
    {
        $data = $request->post();

        // 数据校验
        if (empty($data['title']) || empty($data['link'])) {
            return errorJson('广告标题和跳转链接必填');
        }
        
        // image_url 不再是必填，但如果有提供则需要校验其格式或合理性
        if (isset($data['image_url']) && $data['image_url'] !== '' && !filter_var($data['image_url'], FILTER_VALIDATE_URL)) {
             return errorJson('图片地址格式不正确');
        }

        // 默认值
        $insertData = [
            'image_url'   => $data['image_url'] ?? '',
            'title'       => $data['title'],
            'link'        => $data['link'],
            'sort_order'  => intval($data['sort_order'] ?? 1), // 默认排序1
            'status'      => intval($data['status'] ?? 1),     // 默认启用
            'create_time' => date('Y-m-d H:i:s'),
            'update_time' => date('Y-m-d H:i:s'),
        ];

        try {
            $id = Db::name('banners')->insertGetId($insertData);
            return $id ? successJson(['id' => $id], '新增广告成功') : errorJson('新增广告失败');
        } catch (\Exception $e) {
            return errorJson('新增广告失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新轮播广告
     * POST /banner/update (实际路由前缀由路由文件控制)
     */
    public function update(Request $request)
    {
        $data = $request->post();
        $id = intval($data['id'] ?? 0);

        if (!$id) {
            return errorJson('广告ID不能为空');
        }

        // 校验必填字段（根据实际需求调整，这里只校验了ID）
        if (isset($data['title']) && empty($data['title'])) {
             return errorJson('广告标题不能为空');
        }
        if (isset($data['link']) && empty($data['link'])) {
             return errorJson('跳转链接不能为空');
        }

        // image_url 字段的处理，如果提供了，校验其格式
        if (isset($data['image_url']) && $data['image_url'] !== '' && !filter_var($data['image_url'], FILTER_VALIDATE_URL)) {
             return errorJson('图片地址格式不正确');
        }


        $updateData = [];
        if (isset($data['image_url'])) $updateData['image_url'] = (string)$data['image_url'];
        if (isset($data['title'])) $updateData['title'] = (string)$data['title'];
        if (isset($data['link'])) $updateData['link'] = (string)$data['link'];
        if (isset($data['sort_order'])) $updateData['sort_order'] = intval($data['sort_order']);
        if (isset($data['status'])) $updateData['status'] = intval($data['status']);
        
        $updateData['update_time'] = date('Y-m-d H:i:s');

        try {
            $ret = Db::name('banners')->where('id', $id)->update($updateData);
            return $ret !== false ? successJson([], '更新广告成功') : errorJson('更新广告失败或数据无变化');
        } catch (\Exception $e) {
            return errorJson('更新广告失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除轮播广告
     * POST /banner/delete (实际路由前缀由路由文件控制)
     */
    public function delete(Request $request)
    {
        $id = intval($request->post('id', 0));
        if (!$id) return errorJson('广告ID不能为空');

        try {
            $count = Db::name('banners')->where('id', $id)->delete();
            return $count ? successJson([], '删除成功') : errorJson('删除失败或广告不存在');
        } catch (\Exception $e) {
            return errorJson('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 文件上传接口 (用于广告图片上传)
     * POST /banner/upload (实际路由前缀由路由文件控制)
     * @param Request $request file: 文件字段名，例如 'file'
     * @return \think\response\Json
     */
    public function upload(Request $request)
    {
        // 假设前端上传文件的字段名是 'file'
        $file = $request->file('file'); 
        if (!$file) {
            return errorJson('未检测到上传文件');
        }

        try {
            // 上传到 public/banner_upload 目录下
            // 确保你的 ThinkPHP 配置中 public 磁盘已配置，且 storage 软链已建立 (php think storage:link)
            $savename = Filesystem::disk('public')->putFile('banner_upload', $file); 
            $url = $request->domain() . '/storage/' . $savename; // ThinkPHP默认的文件访问路径
            
            return successJson([
                'url' => $url,
                'filename' => $file->getOriginalName(),
                'file_size' => $file->getSize(),
            ], '上传成功');
        } catch (\Exception $e) {
            return errorJson('上传失败：' . $e->getMessage());
        }
    }
}
