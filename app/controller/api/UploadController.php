<?php
// 文件路径: E:\ThinkPHP6\app\controller\api\UploadController.php

namespace app\controller\api;

use think\facade\Request;
use think\facade\Filesystem;
use think\response\Json;

class UploadController
{
    public function image(): Json
    {
        $file = Request::file('file');
        if (!$file) {
            return errorJson('未接收到文件');
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if (!in_array($file->getMime(), $allowedMimes)) {
            return errorJson('文件格式不正确，仅支持 JPG, PNG, GIF, WEBP');
        }
        if ($file->getSize() > $maxSize) {
            return errorJson('文件大小超出限制，最大' . ($maxSize / 1024 / 1024) . 'MB');
        }

        try {
            $savename = Filesystem::disk('public')->putFile('uploads/images', $file);
            if ($savename) {
                $fullUrl = Request::domain() . '/storage/' . $savename;
                return successJson(['url' => $fullUrl], '图片上传成功');
            } else {
                return errorJson('图片上传失败');
            }
        } catch (\Exception $e) {
            return errorJson('图片上传异常: ' . $e->getMessage());
        }
    }

    public function video(): Json
    {
        $file = Request::file('file');
        if (!$file) {
            return errorJson('未接收到文件');
        }

        $allowedMimes = [
            'video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v',
            'application/x-mpegURL',
            'application/octet-stream'
        ];
        $maxSize = 500 * 1024 * 1024; // 500MB

        if (!in_array($file->getMime(), $allowedMimes)) {
            return errorJson('文件格式不正确，仅支持 MP4, WEBM, MOV, M3U8 等视频格式');
        }
        if ($file->getSize() > $maxSize) {
            return errorJson('文件大小超出限制，最大' . ($maxSize / 1024 / 1024) . 'MB');
        }

        try {
            $savename = Filesystem::disk('public')->putFile('uploads/videos', $file);
            if ($savename) {
                $fullUrl = Request::domain() . '/storage/' . $savename;
                return successJson(['url' => $fullUrl], '视频上传成功');
            } else {
                return errorJson('视频上传失败');
            }
        } catch (\Exception $e) {
            return errorJson('视频上传异常: ' . $e->getMessage());
        }
    }
}

// 辅助函数定义 (请确保这些函数在你的项目中是可用的，如果它们是全局函数，则不会重复定义)
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
