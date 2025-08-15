<?php
declare (strict_types = 1);

namespace app;

use think\App;
use think\exception\ValidateException;
use think\Validate;
use think\Response;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * JWT密钥
     * @var string
     */
    protected $jwtKey = 'MyAwesomeSuperKey2024!@#xBk';

    /**
     * JWT算法（数组，适配 Firebase/JWT 5.x）
     * @var array
     */
    protected $jwtAlg = 'HS256';
    protected $jwtAlgArr = ['HS256'];

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;
        $this->initialize();
    }

    // 初始化（可选复写）
    protected function initialize()
    {}

    /**
     * 验证数据
     */
    protected function validate(array $data, string|array $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

    /**
     * 返回成功响应
     */
    protected function success($data = [], string $msg = 'success', int $code = 0): Response
    {
        $result = [
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ];
        return json($result);
    }

    /**
     * 返回失败响应
     */
    protected function error(string $msg = 'error', int $code = 1, $data = []): Response
    {
        $result = [
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ];
        return json($result);
    }

    /**
     * 补全图片URL为带域名的绝对路径
     */
    protected function fullImageUrl($path): string
    {
        if (empty($path)) return '';
        if (stripos($path, 'http') === 0) return $path;
        $host = $this->request->domain();
        return $host . $path;
    }

    /**
     * 获取当前登录用户（JWT 5.x 专用，需 composer 安装 firebase/php-jwt 5.x）
     * @return array|false
     */
    protected function getLoginUser()
{   
    // 1. 优先 header token/jwt
    $token = $this->request->header('Authorization') ?: $this->request->header('token');
    // --- Bearer 规范处理 ---
    if ($token && strpos($token, 'Bearer ') === 0) {
        $token = substr($token, 7);
    }
    if ($token) {
        try {
            $jwtData = \Firebase\JWT\JWT::decode($token, $this->jwtKey, $this->jwtAlgArr);
            $jwtArr = (array)$jwtData;
            $uuid = $jwtArr['uuid'] ?? null;
            if ($uuid) {
                $user = \think\facade\Db::name('users')->where('uuid', $uuid)->find();
                if ($user && $user['user_status'] == 1) {
                    return $user;
                }
            }
        } catch (\Exception $e) {
            // jwt 解码失败，继续走uuid判断
        }
    }
    // 2. 支持 uuid 游客直传
    $uuid = $this->request->post('uuid') ?: $this->request->get('uuid', '');
    if ($uuid) {
        $user = \think\facade\Db::name('users')->where('uuid', $uuid)->find();
        if ($user && $user['user_status'] == 1) {
            return $user;
        }
    }
    return false;
}

}
