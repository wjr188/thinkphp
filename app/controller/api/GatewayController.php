<?php
namespace app\controller\api;

use think\Request;
use think\facade\Config;
use think\facade\Cache;
use think\facade\Log;

class GatewayController
{
    /**
     * 获取会话密钥接口 - 握手获取会话密钥
     * GET /key
     */
    public function getSessionKey(Request $request)
    {
        try {
            $deviceId = $request->header('x-device-id');
            $authorization = $request->header('authorization');
            
            if (!$deviceId) {
                Log::warning('[KEY] Missing device ID');
                return json(['code' => 400, 'msg' => '缺少设备ID']);
            }
            
            // 生成会话密钥
            $sessionKey = $this->generateSessionKey($deviceId, $authorization);
            
            if (!$sessionKey) {
                Log::error('[KEY] Failed to generate session key', ['device' => $deviceId]);
                return json(['code' => 500, 'msg' => '密钥生成失败']);
            }
            
            Log::info('[KEY] Session key generated', [
                'device' => substr($deviceId, 0, 12),
                'kid' => $sessionKey['kid'],
                'ttl' => $sessionKey['ttl']
            ]);
            
            // 读取公钥文件
            $publicKeyPem = '';
            $keyFiles = [
                root_path() . 'keys/rsa_public_key.pem',
                root_path() . 'rsa_public.pem',
            ];
            foreach ($keyFiles as $keyFile) {
                if (file_exists($keyFile)) {
                    $publicKeyPem = file_get_contents($keyFile);
                    break;
                }
            }
            
            // 获取或生成用户密钥（同一设备保持一致）
            $userSecret = Cache::get("secret:{$deviceId}");
            if (!$userSecret) {
                // 只有缓存中没有时才生成新的用户密钥
                $userSecret = hash('sha256', $deviceId . time() . random_bytes(16));
                Cache::set("secret:{$deviceId}", $userSecret, 86400); // 24小时有效
            }
            
            return json([
                'code' => 0,
                'data' => [
                    'kid' => $sessionKey['kid'],
                    'key' => base64_encode($sessionKey['key']),
                    'iv' => base64_encode($sessionKey['iv']),
                    'ttl' => $sessionKey['ttl'],
                    'serverTime' => time(),
                    'publicKey' => $publicKeyPem,
                    'userSecret' => $userSecret
                ]
            ]);
            
        } catch (\Throwable $e) {
            Log::error('[KEY] Exception: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '服务器错误']);
        }
    }
    public function route(Request $request)
    {
        // 立即记录网关入口
        Log::info('[GW] GATEWAY ENTERED', [
            'uri' => $request->url(),
            'method' => $request->method(),
            'timestamp' => time()
        ]);
        
        // 入参与请求头采样日志（避免打印明文数据）
        $ip = $request->ip();
        $m = (string)($request->post('m') ?? $request->get('m') ?? '');
        $encData = (string)($request->post('d') ?? $request->get('d') ?? '');
        $ts = $request->header('x-timestamp');
        $nonce = $request->header('x-nonce');
        $sig = $request->header('x-signature');
        $devId = $request->header('x-device-id');
        $encKey = $request->header('x-enc-key');  // 旧模式
        $keyId = $request->header('x-key-id');    // 新模式

        Log::info('[GW] IN', [
            'ip' => $ip,
            'uri' => $request->url(),
            'method' => $request->method(),
            'm' => $m,
            'd_len' => strlen($encData),
            'enc_key_len' => $encKey ? strlen((string) $encKey) : 0,
            'key_id' => $keyId,
            'ts' => $ts,
            'nonce' => $nonce,
            'sig_fp' => $sig ? substr($sig, 0, 12) : null,
            'dev_fp' => $devId ? substr($devId, 0, 12) : null,
        ]);

        if (!$m || !$encData) {
            Log::warning('[GW] Missing params', ['m' => $m, 'd_len' => strlen($encData)]);
            return json(['code' => 400, 'msg' => '缺少必要参数']);
        }

        // 如果中间件已完成校验与解密，则直接使用，避免重复工作
        $usingMiddleware = isset($request->decParams) && isset($request->aesKey) && isset($request->aesIv);
        if ($usingMiddleware) {
            $params = $request->decParams;
            Log::info('[GW] Using params from middleware');
        } else {
            // 新增安全检查（当未走中间件时）
            $securityCheck = $this->performSecurityChecks($request, $ts, $nonce, $sig, $devId, $m, $encData);
            if ($securityCheck !== true) {
                return $securityCheck; // 返回错误响应
            }

            // 获取解密密钥 - 支持新旧两种模式
            $aesData = null;
            if ($keyId) {
                // 新模式：使用会话密钥
                $aesData = $this->getSessionKeyData($keyId);
                if (!$aesData) {
                    Log::warning('[GW] Session key not found or expired', ['kid' => $keyId]);
                    return json(['code' => 4011, 'msg' => '会话密钥已过期，请重新获取']);
                }
            } elseif ($encKey) {
                // 旧模式：兼容现有RSA解密
                $aesData = $this->parseAesKeyLegacy($encKey, $request->header('x-enc-mode', 'rsa'));
                if (!$aesData) {
                    Log::warning('[GW] Legacy key parse failed');
                    return json(['code' => 401, 'msg' => '密钥解析失败']);
                }
            } else {
                Log::warning('[GW] No encryption key provided');
                return json(['code' => 400, 'msg' => '缺少加密密钥']);
            }

            // 解密请求数据
            $params = $this->decryptRequestData($encData, $aesData['key'], $aesData['iv']);
            if ($params === null) {
                Log::warning('[GW] Request data decryption failed');
                return json(['code' => 4007, 'msg' => '请求数据解密失败']);
            }

            // 保存解密信息供响应加密使用
            $request->aesKey = $aesData['key'];
            $request->aesIv = $aesData['iv'];
            $request->decParams = $params;
        }

        $paramKeys = is_array($params) ? array_keys($params) : [];
        Log::info('[GW] Params ready', [
            'm' => $m,
            'keys' => $paramKeys,
            'count' => is_array($params) ? count($params) : 0,
        ]);
        
        // 额外调试：网关参数具体内容
        Log::info('[GW] PARAMS_JSON: ' . json_encode($params, JSON_UNESCAPED_UNICODE));

        // 优先从缓存获取映射表，缓存没有则从配置文件读取
        $map = Cache::get('api_map');
        $mapSource = 'cache';
        if (!$map) {
            $map = Config::get('api_map', []);
            Cache::set('api_map', $map, 3600);
            $mapSource = 'config';
        }
        Log::info('[GW] Map loaded', ['source' => $mapSource, 'count' => is_array($map) ? count($map) : 0]);

        if (!isset($map[$m])) {
            Log::warning('[GW] Mapping not found', ['m' => $m]);
            return json(['code' => 404, 'msg' => '接口不存在']);
        }

        // 调用真实业务方法
        [$class, $method] = $map[$m];
        try {
            // 关键修复：确保参数能被控制器方法正确接收
            if (is_array($params)) {
                // 同时提供 camelCase 与 snake_case 两种键名
                $expanded = $this->expandParamKeys($params);

                // 注入到 Request 的各参数源
                $request = $request
                    ->withGet(array_merge($request->get(), $expanded))
                    ->withPost(array_merge($request->post(), $expanded))
                    ->withRoute(array_merge($request->route(), $expanded));

                // 强制清空 param 缓存，并重置 mergeParam，让后续 param() 重新合并
                try {
                    $reflection = new \ReflectionClass($request);
                    if ($reflection->hasProperty('param')) {
                        $paramProperty = $reflection->getProperty('param');
                        $paramProperty->setAccessible(true);
                        $paramProperty->setValue($request, []);
                    }
                    if ($reflection->hasProperty('mergeParam')) {
                        $mergeFlag = $reflection->getProperty('mergeParam');
                        $mergeFlag->setAccessible(true);
                        $mergeFlag->setValue($request, false);
                    }
                } catch (\Exception $e) {
                    Log::warning('[GW] Failed to clear param cache: ' . $e->getMessage());
                }

                // 绑定更新后的 Request 到容器（门面 Request::param() 读取的是容器里的 request）
                app()->instance('request', $request);
                app()->instance(\think\Request::class, $request);
            }

            // 智能调用控制器方法（支持额外参数）
            $result = $this->invokeControllerMethod($class, $method, $request, $params);
            // 加密响应
            return $this->encryptResponse($result, $request);
        } catch (\Throwable $e) {
            Log::error('[GW] EX', [
                'm' => $m,
                'class' => $class ?? null,
                'method' => $method ?? null,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
            ]);
            return $this->encryptResponse(json(['code' => 500, 'msg' => '服务器错误']), $request);
        }
    }

    /**
     * 更新映射表缓存（用于动态更新映射表）
     */
    public function updateMap()
    {
        Cache::delete('api_map');
        Log::info('[GW] Map cache cleared by manual action');
        return json(['code' => 200, 'msg' => '映射表缓存已清除']);
    }

    /**
     * 列出当前设备的会话密钥
     * GET /key/list
     */
    public function listDeviceKeys(Request $request)
    {
        try {
            $deviceId = $request->header('x-device-id');
            if (!$deviceId) {
                return json(['code' => 400, 'msg' => '缺少设备ID']);
            }
            $deviceIndexKey = "device_keys:{$deviceId}";
            $kids = Cache::get($deviceIndexKey, []);
            $list = [];
            if (is_array($kids)) {
                foreach ($kids as $kid) {
                    $s = Cache::get("session_key:{$kid}");
                    if ($s) {
                        $list[] = [
                            'kid' => $kid,
                            'expireAt' => $s['expireAt'] ?? null,
                            'createdAt' => $s['createdAt'] ?? null,
                        ];
                    }
                }
            }
            return json(['code' => 0, 'data' => $list]);
        } catch (\Throwable $e) {
            Log::error('[KEY] list device keys error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '服务器错误']);
        }
    }

    /**
     * 撤销当前设备的全部会话密钥
     * POST /key/revoke
     */
    public function revokeDeviceKeys(Request $request)
    {
        try {
            $deviceId = $request->header('x-device-id');
            if (!$deviceId) {
                return json(['code' => 400, 'msg' => '缺少设备ID']);
            }
            $deviceIndexKey = "device_keys:{$deviceId}";
            $kids = Cache::get($deviceIndexKey, []);
            $revoked = 0;
            if (is_array($kids)) {
                foreach ($kids as $kid) {
                    if (Cache::delete("session_key:{$kid}")) {
                        $revoked++;
                    }
                }
            }
            Cache::delete($deviceIndexKey);
            Log::info('[KEY] Device keys revoked', ['device' => substr($deviceId, 0, 12), 'count' => $revoked]);
            return json(['code' => 0, 'msg' => 'OK', 'data' => ['revoked' => $revoked]]);
        } catch (\Throwable $e) {
            Log::error('[KEY] revoke device keys error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '服务器错误']);
        }
    }

    /**
     * 加密响应数据
     */
    private function encryptResponse($response, $request)
    {
        try {
            // 获取AES密钥（从中间件解密时保存的）
            $aesKey = $request->aesKey ?? null;
            $originalIv = $request->aesIv ?? null;
            
            if (!$aesKey || !$originalIv) {
                // 如果没有密钥，可能是中间件没有保存，尝试重新解析（兼容旧模式）
                $encKey = $request->header('x-enc-key');
                $encMode = strtolower((string)$request->header('x-enc-mode', 'rsa'));
                
                if (!$encKey) {
                    Log::warning('[GW] No encryption key for response');
                    return $response; // 返回明文
                }
                
                // 修正为使用旧模式解析方法
                $aesData = $this->parseAesKeyLegacy($encKey, $encMode);
                if (!$aesData) {
                    Log::warning('[GW] Failed to parse AES key for response');
                    return $response; // 返回明文
                }
                
                $aesKey = $aesData['key'];
                $originalIv = $aesData['iv'];
            }
            
            // 将响应转换为JSON字符串
            $responseData = '';
            if ($response instanceof \think\Response) {
                $responseData = $response->getContent();
            } elseif (is_array($response) || is_object($response)) {
                $responseData = json_encode($response, JSON_UNESCAPED_UNICODE);
            } else {
                $responseData = (string)$response;
            }
            
            // 为响应生成新的IV（更安全），或复用请求的IV（兼容性更好）
            // 选择方案：复用请求IV，确保前后端一致
            $responseIv = $originalIv;
            
            // AES加密响应数据
            $encryptedData = openssl_encrypt(
                $responseData,
                'AES-256-CBC',
                $aesKey,
                OPENSSL_RAW_DATA,
                $responseIv
            );
            
            if ($encryptedData === false) {
                Log::error('[GW] Response encryption failed');
                return $response; // 返回明文
            }
            
            $encryptedResponse = base64_encode($encryptedData);
            
            // 添加响应完整性校验（HMAC签名）
            $deviceId = $request->header('x-device-id');
            $secret = Cache::get("secret:{$deviceId}");
            if (!$secret) {
                $secret = 'test_secret_key_123456'; // 默认测试密钥
            }
            
            // 生成响应签名（包含加密数据和时间戳）
            $responseTimestamp = time();
            $signatureData = "{$responseTimestamp}\n{$encryptedResponse}";
            $responseSignature = hash_hmac('sha256', $signatureData, $secret);
            
            Log::info('[GW] Response encrypted', [
                'length' => strlen($encryptedResponse),
                'iv_reused' => 'true',
                'signature_added' => 'true'
            ]);
            
            // 返回加密的响应格式（包含完整性校验）
            return json([
                'encrypted' => true,
                'data' => $encryptedResponse,
                'timestamp' => $responseTimestamp,
                'signature' => $responseSignature
            ]);
            
        } catch (\Throwable $e) {
            Log::error('[GW] Response encryption error: ' . $e->getMessage());
            return $response; // 加密失败时返回明文
        }
    }
    
    /**
     * 解析AES密钥（兼容旧模式）
     */
    private function parseAesKeyLegacy($encKey, $encMode)
    {
        try {
            $aesJson = '';
            
            if ($encMode === 'plain') {
                $aesJson = base64_decode($encKey);
                if ($aesJson === false || $aesJson === '') {
                    return null;
                }
            } else {
                // RSA 模式
                $candidates = [
                    root_path() . 'keys/rsa_private_key.pem',
                    root_path() . 'rsa_private.pem',
                    root_path() . 'rsa_private_real.pem',
                ];
                
                $privateKeyPem = null;
                foreach ($candidates as $p) {
                    if (is_file($p) && filesize($p) > 0) {
                        $privateKeyPem = file_get_contents($p);
                        if ($privateKeyPem) { break; }
                    }
                }
                
                if (!$privateKeyPem) {
                    return null;
                }
                
                $priv = openssl_pkey_get_private($privateKeyPem);
                if ($priv === false) {
                    return null;
                }
                
                $decryptResult = openssl_private_decrypt(
                    base64_decode($encKey),
                    $aesJson,
                    $priv,
                    OPENSSL_PKCS1_PADDING
                );
                
                if (!$decryptResult) {
                    return null;
                }
            }
            
            $aesArr = json_decode($aesJson, true);
            if (!$aesArr || !isset($aesArr['key']) || !isset($aesArr['iv'])) {
                return null;
            }
            
            return [
                'key' => base64_decode($aesArr['key']),
                'iv' => base64_decode($aesArr['iv'])
            ];
            
        } catch (\Throwable $e) {
            Log::error('[GW] Parse AES key error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 生成会话密钥
     */
    private function generateSessionKey($deviceId, $authorization = null)
    {
        try {
            // 生成随机密钥和IV
            $key = random_bytes(32); // AES-256 需要32字节密钥
            $iv = random_bytes(16);  // AES-CBC 需要16字节IV
            
            // 生成kid（密钥标识）
            $kid = 'k_' . uniqid() . '_' . substr(hash('sha256', $deviceId . time()), 0, 8);
            
            // TTL设置（30分钟）
            $ttl = 1800;
            $expireAt = time() + $ttl;
            
            // 用户ID（如果有认证信息）
            $userId = null;
            if ($authorization && str_starts_with($authorization, 'Bearer ')) {
                // 这里可以解析JWT token获取用户ID
                // $userId = $this->parseUserId($authorization);
            }
            
            // 存储到Redis
            $sessionData = [
                'key' => $key,
                'iv' => $iv,
                'deviceId' => $deviceId,
                'userId' => $userId,
                'expireAt' => $expireAt,
                'createdAt' => time()
            ];
            
            $cacheKey = "session_key:{$kid}";
            Cache::set($cacheKey, $sessionData, $ttl);
            
            // 设备维度索引（用于撤销）
            $deviceIndexKey = "device_keys:{$deviceId}";
            $deviceKeys = Cache::get($deviceIndexKey, []);
            $deviceKeys[] = $kid;
            Cache::set($deviceIndexKey, array_unique($deviceKeys), $ttl);
            
            return [
                'kid' => $kid,
                'key' => $key,
                'iv' => $iv,
                'ttl' => $ttl
            ];
            
        } catch (\Throwable $e) {
            Log::error('[KEY] Generate session key error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取会话密钥数据
     */
    private function getSessionKeyData($kid)
    {
        try {
            $cacheKey = "session_key:{$kid}";
            $sessionData = Cache::get($cacheKey);
            
            if (!$sessionData) {
                return null;
            }
            
            // 检查是否过期
            if (time() > $sessionData['expireAt']) {
                Cache::delete($cacheKey);
                return null;
            }
            
            return [
                'key' => $sessionData['key'],
                'iv' => $sessionData['iv']
            ];
            
        } catch (\Throwable $e) {
            Log::error('[KEY] Get session key error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 执行安全检查
     */
    private function performSecurityChecks($request, $ts, $nonce, $sig, $devId, $m, $encData)
    {
        // 1. 时间戳校验
        if (!$ts || abs(time() - (int)$ts) > 300) {
            Log::warning('[GW] Timestamp skew', ['ts' => $ts, 'now' => time()]);
            return json(['code' => 4002, 'msg' => '请求时间戳异常']);
        }

        // 2. Nonce防重放（与中间件保持一致：按设备维度去重）
        if (!$nonce) {
            Log::warning('[GW] Missing nonce');
            return json(['code' => 400, 'msg' => '缺少随机数']);
        }
        
        $nonceKey = "nonce:{$devId}:{$nonce}"; // 变更：改为设备维度
        if (Cache::has($nonceKey)) {
            Log::warning('[GW] Nonce replay', ['nonce' => substr($nonce, 0, 16), 'device' => $devId ? substr($devId, 0, 12) : null]);
            return json(['code' => 4003, 'msg' => '重复请求']);
        }
        Cache::set($nonceKey, 1, 300);

        // 3. 签名校验
        if (!$sig || !$devId) {
            Log::warning('[GW] Missing signature or device ID');
            return json(['code' => 400, 'msg' => '缺少签名或设备ID']);
        }

        $secret = Cache::get("secret:{$devId}");
        if (!$secret) {
            $secret = 'test_secret_key_123456'; // 默认测试密钥
        }

        $stringToSign = "{$ts}\n{$nonce}\n{$m}\n{$encData}";
        $expectedSig = hash_hmac('sha256', $stringToSign, $secret);

        if (!hash_equals($expectedSig, $sig)) {
            Log::warning('[GW] Signature verification failed', [
                'device' => $devId ? substr($devId, 0, 12) : null,
                'expected' => substr($expectedSig, 0, 12),
                'received' => $sig ? substr($sig, 0, 12) : null
            ]);
            return json(['code' => 4006, 'msg' => '签名验证失败']);
        }

        // 4. 限流检查（简单实现）
        $rateLimitKey = "rate_limit:{$devId}:{$m}";
        $currentCount = Cache::get($rateLimitKey, 0);
        if ($currentCount >= 60) { // 每分钟60次
            Log::warning('[GW] Rate limit exceeded', ['device' => $devId ? substr($devId, 0, 12) : null, 'm' => $m]);
            return json(['code' => 429, 'msg' => '请求过于频繁']);
        }
        Cache::set($rateLimitKey, $currentCount + 1, 60);

        return true; // 所有检查通过
    }

    /**
     * 解密请求数据
     */
    private function decryptRequestData($encData, $key, $iv)
    {
        try {
            $rawData = openssl_decrypt(
                base64_decode($encData),
                'AES-256-CBC',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($rawData === false) {
                return null;
            }

            $params = json_decode($rawData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            return $params;
            
        } catch (\Throwable $e) {
            Log::error('[GW] Decrypt request data error: ' . $e->getMessage());
            return null;
        }
    }

    // 在类内新增键名转换与扩展工具，确保同时提供 camelCase 与 snake_case 两种参数键
    protected function toSnake(string $key): string
    {
        if (strpos($key, '_') !== false) return strtolower($key);
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
    }

    protected function toCamel(string $key): string
    {
        if (strpos($key, '_') === false) return $key;
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($key)))));
    }

    protected function expandParamKeys(array $params): array
    {
        $out = $params;
        foreach ($params as $k => $v) {
            $snake = $this->toSnake((string)$k);
            $camel = $this->toCamel((string)$k);
            $out[$snake] = $v;
            $out[$camel] = $v;
        }
        return $out;
    }

    /**
     * 智能调用控制器方法，支持额外参数
     */
    protected function invokeControllerMethod($class, $method, $request, $params = [])
    {
        try {
            // 使用反射获取方法参数信息
            $reflection = new \ReflectionMethod($class, $method);
            $parameters = $reflection->getParameters();
            
            // 如果方法没有参数，直接使用原有方式调用
            if (empty($parameters)) {
                return app()->invoke([$class, $method]);
            }
            
            // 如果只有一个 Request 参数，使用原有方式调用
            if (count($parameters) === 1) {
                $param = $parameters[0];
                $paramType = $param->getType();
                if ($paramType && $paramType->getName() === 'think\Request') {
                    return app()->invoke([$class, $method]);
                }
            }
            
            // 特殊处理：如果只有一个非Request参数，且参数数组只有一个值，直接匹配
            if (count($parameters) === 1 && is_array($params) && count($params) === 1) {
                $param = $parameters[0];
                $paramName = $param->getName();
                
                // 获取传入参数的第一个值
                $firstValue = reset($params);
                $firstKey = key($params);
                
                Log::debug("[GW] Single param matching", [
                    'method_param' => $paramName,
                    'input_key' => $firstKey,
                    'input_value' => $firstValue,
                    'all_params' => $params
                ]);
                
                // 创建控制器实例并调用方法
                $controller = app()->make($class);
                return call_user_func_array([$controller, $method], [$firstValue]);
            }
            
            // 准备方法调用参数
            $args = [];
            
            foreach ($parameters as $param) {
                $paramName = $param->getName();
                $paramType = $param->getType();
                
                // 如果参数是 Request 类型，直接传入 $request
                if ($paramType && $paramType->getName() === 'think\Request') {
                    $args[] = $request;
                    continue;
                }
                
                // 查找参数值（优先 snake_case，然后 camelCase）
                $paramValue = null;
                $snakeName = $this->toSnake($paramName);
                $camelName = $this->toCamel($paramName);
                
                if (is_array($params)) {
                    if (isset($params[$snakeName])) {
                        $paramValue = $params[$snakeName];
                    } elseif (isset($params[$camelName])) {
                        $paramValue = $params[$camelName];
                    } elseif (isset($params[$paramName])) {
                        $paramValue = $params[$paramName];
                    }
                }
                
                // 如果没找到参数值，使用默认值（如果有）
                if ($paramValue === null && $param->isDefaultValueAvailable()) {
                    $paramValue = $param->getDefaultValue();
                }
                
                // 如果仍然没有值且参数不可选，则报错
                if ($paramValue === null && !$param->isOptional()) {
                    Log::error("[GW] Missing required parameter: {$paramName} for {$class}::{$method}");
                    return json(['code' => 400, 'msg' => "缺少必需参数: {$paramName}"]);
                }
                
                $args[] = $paramValue;
            }
            
            // 创建控制器实例并调用方法
            $controller = app()->make($class);
            return call_user_func_array([$controller, $method], $args);
            
        } catch (\ReflectionException $e) {
            Log::error("[GW] Reflection error for {$class}::{$method}: " . $e->getMessage());
            // 降级到原来的调用方式
            return app()->invoke([$class, $method]);
        } catch (\Throwable $e) {
            Log::error("[GW] Method invoke error for {$class}::{$method}: " . $e->getMessage(), [
                'params' => $params,
                'error_type' => get_class($e),
                'error_trace' => $e->getTraceAsString()
            ]);
            // 降级到原来的调用方式
            return app()->invoke([$class, $method]);
        }
    }
}
