<?php
// app/common.php

if (!function_exists('apiReturn')) {
    /**
     * 标准API响应
     */
    function apiReturn($data = [], $msg = '成功', $code = 0) {
        return json([
            'code' => $code,
            'msg'  => $msg,   // 统一用 msg
            'data' => $data,
        ]);
    }
}

/**
 * 成功响应
 * @param mixed $data 返回数据
 * @param string $msg 提示信息
 * @param int $code 状态码
 * @return \think\response\Json
 */
function success($data = [], string $msg = 'ok', int $code = 200)
{
    return json([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ]);
}

/**
 * 错误响应
 * @param string $msg 错误信息
 * @param int $code 错误码
 * @param mixed $data 额外数据
 * @return \think\response\Json
 */
function error(string $msg = 'error', int $code = 400, $data = [])
{
    return json([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ]);
}
