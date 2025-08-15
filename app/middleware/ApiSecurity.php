<?php
namespace app\middleware;

use think\facade\Cache;
use think\facade\Log;

class ApiSecurity
{
    public function handle($request, \Closure $next)
    {
        // 立即记录中间件入口
        Log::info('SEC: MIDDLEWARE ENTERED', [
            'uri' => $request->url(),
            'method' => $request->method(),
            'timestamp' => time()
        ]);
        
        try {
            $ts     = (int)$request->header('x-timestamp');
            $nonce  = $request->header('x-nonce');
            $sign   = $request->header('x-signature');
            $device = $request->header('x-device-id');
            $token  = $request->header('authorization');
            $encKey = $request->header('x-enc-key'); // 旧模式：前端 RSA 加密的 AES key+iv 或明文模式的 base64(JSON)
            $encMode= strtolower((string)$request->header('x-enc-mode', 'rsa'));
            $kid    = $request->header('x-key-id');  // 新模式：会话密钥标识

            // 调试：进入中间件时的安全头与分支
            Log::info('SEC: enter', [
                'kid' => (string)$kid,
                'hasEncKey' => $encKey ? true : false,
                'encMode' => $encMode,
                'ts' => $ts,
                'nonce' => (string)$nonce,
                'm' => (string)($request->post('m') ?? $request->get('m') ?? ''),
                'device' => (string)$device,
            ]);

            // 参数验证（支持两种密钥模式：kid 或 encKey 其一即可）
            if (!$ts || !$nonce || !$sign || !$device || (!$encKey && !$kid)) {
                return json(['code' => 400, 'msg' => '缺少必要的安全头']);
            }

            // 1. 校验时间
            if (abs(time() - $ts) > 300) {
                return json(['code' => 401, 'msg' => '签名过期']);
            }

            // 2. 防重放（设备维度）
            $nonceKey = "nonce:{$device}:{$nonce}";
            if (Cache::has($nonceKey)) {
                return json(['code' => 401, 'msg' => '重复请求']);
            }
            Cache::set($nonceKey, 1, 300);

            // 3. 准备 AES key/iv（会话密钥优先）
            $aesKey = null;
            $aesIv  = null;

            if ($kid) {
                $cacheKey = "session_key:{$kid}";
                $sessionData = Cache::get($cacheKey);
                if (!$sessionData || time() > ($sessionData['expireAt'] ?? 0)) {
                    if ($sessionData) { Cache::delete($cacheKey); }
                    return json(['code' => 4011, 'msg' => '会话密钥已过期，请重新获取']);
                }
                $aesKey = $sessionData['key'] ?? null;
                $aesIv  = $sessionData['iv'] ?? null;
                if (!$aesKey || !$aesIv) {
                    Log::warning('会话密钥缺少 key/iv', ['kid' => $kid]);
                    return json(['code' => 500, 'msg' => '服务器配置错误']);
                }
                // 调试：会话密钥分支
                Log::info('SEC: key session', [
                    'kid' => (string)$kid,
                    'hasKey' => $aesKey ? true : false,
                    'hasIv' => $aesIv ? true : false,
                ]);
            } else {
                // 旧模式解析
                $aesJson = '';
                if ($encMode === 'plain') {
                    $aesJson = base64_decode($encKey);
                    if ($aesJson === false || $aesJson === '') {
                        return json(['code' => 401, 'msg' => '明文密钥格式错误']);
                    }
                } else {
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
                        Log::error('RSA私钥文件不存在或为空，尝试路径: ' . implode(', ', $candidates));
                        return json(['code' => 500, 'msg' => '服务器配置错误']);
                    }

                    $priv = openssl_pkey_get_private($privateKeyPem);
                    if ($priv === false) {
                        Log::error('RSA私钥解析失败: ' . (function_exists('openssl_error_string') ? (openssl_error_string() ?: '未知错误') : '未知错误'));
                        return json(['code' => 500, 'msg' => '服务器私钥无效']);
                    }

                    $decryptResult = openssl_private_decrypt(
                        base64_decode($encKey),
                        $aesJson,
                        $priv,
                        OPENSSL_PKCS1_PADDING
                    );

                    if (!$decryptResult) {
                        Log::warning('密钥解密失败', [ 'device' => $device ]);
                        return json(['code' => 401, 'msg' => '密钥解密失败']);
                    }
                }

                $aesArr = json_decode($aesJson, true);
                if (!$aesArr || !isset($aesArr['key']) || !isset($aesArr['iv'])) {
                    return json(['code' => 401, 'msg' => '密钥格式错误']);
                }

                $aesKey = base64_decode($aesArr['key']);
                $aesIv  = base64_decode($aesArr['iv']);

                // 调试：RSA/明文密钥分支
                Log::info('SEC: key rsa/plain', [
                    'encMode' => $encMode,
                    'encKeyLen' => strlen((string)$encKey),
                    'hasKey' => $aesKey ? true : false,
                    'hasIv' => $aesIv ? true : false,
                ]);
            }

            // 4. 解密 d 参数
            $encData = $request->post('d') ?? $request->get('d');
            if (!$encData) {
                return json(['code' => 400, 'msg' => '缺少加密数据']);
            }

            $rawData = openssl_decrypt(
                base64_decode($encData),
                'AES-256-CBC',
                $aesKey,
                OPENSSL_RAW_DATA,
                $aesIv
            );

            if ($rawData === false) {
                return json(['code' => 401, 'msg' => '数据解密失败']);
            }

            // 调试：原始解密数据
            Log::info('SEC: raw decrypted data', [
                'm' => (string)$request->param('m'),
                'rawData' => $rawData,
                'rawLength' => strlen($rawData)
            ]);

            $params = json_decode($rawData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return json(['code' => 401, 'msg' => '数据格式错误']);
            }

            // 调试：解析后的完整结构
            Log::info('SEC: parsed json structure', [
                'm' => (string)$request->param('m'),
                'fullStructure' => $params,
                'hasDataField' => isset($params['data']),
                'dataFieldType' => isset($params['data']) ? gettype($params['data']) : 'none'
            ]);

            // 1) 解包：取出业务参数（data 字段内容）
            $req = is_array($params) ? $params : [];
            $biz = isset($req['data']) && is_array($req['data']) ? $req['data'] : $req;

            // 2) 可选：驼峰转下划线兼容
            $biz = $this->snakeKeys($biz);

            // 5. 验签（用短期 secret）
            $secret = Cache::get("secret:{$device}"); // 登录时存的 secret
            if (!$secret) {
                // 如果没有设备 secret，使用默认测试密钥（开发环境）
                $secret = 'test_secret_key_123456';
                Log::info("使用默认测试密钥进行签名验证，设备ID: {$device}");
            }

            $methodId = (string)($request->post('m') ?? $request->get('m') ?? '');
            $stringToSign = "{$ts}\n{$nonce}\n{$methodId}\n{$encData}";
            $expect = hash_hmac('sha256', $stringToSign, $secret);

            if (!hash_equals($expect, $sign)) {
                Log::warning('签名验证失败', [
                    'device' => $device,
                    'expected' => $expect,
                    'received' => $sign
                ]);
                return json(['code' => 401, 'msg' => '签名错误']);
            }

            // 调试：解密后参数快照 - 强化版
            Log::info('SEC: params decrypted DETAILED', [
                'm' => $methodId,
                'biz_full' => $biz,
                'biz_count' => count($biz),
                'parent_id_value' => $biz['parent_id'] ?? 'NOT_SET',
                'parent_id_type' => isset($biz['parent_id']) ? gettype($biz['parent_id']) : 'missing',
                'page_value' => $biz['page'] ?? 'NOT_SET',
                'keys' => array_keys($biz),
            ]);
            
            // 额外调试：直接输出到日志，确保能看到
            Log::info('SEC: BIZ_PARAMS_JSON: ' . json_encode($biz, JSON_UNESCAPED_UNICODE));

            // 6. 参数验证和强制要求（防止默认分支）
            if (in_array($methodId, ['cm1a2b'])) {  // cm1a2b 是你的分类列表接口ID
                if (empty($biz['parent_id']) && empty($biz['only_main'])) {
                    return json(['code'=>422,'msg'=>'parent_id or only_main required']);
                }
            }

            // 7. 简单限流（设备+方法，每分钟60次）
            $rateKey = "rate_limit:{$device}:{$methodId}";
            $curr = Cache::get($rateKey, 0);
            if ($curr >= 60) {
                Log::warning('限流触发', ['device' => $device, 'm' => $methodId]);
                return json(['code' => 429, 'msg' => '请求过于频繁']);
            }
            Cache::set($rateKey, $curr + 1, 60);

            // 8. 保存解密后的参数与密钥
            $request->decParams = $biz;
            $request->aesKey = $aesKey;
            $request->aesIv  = $aesIv;
            if (is_array($biz)) {
                $request = $request->withGet(array_merge($request->get(), $biz))
                                  ->withPost(array_merge($request->post(), $biz))
                                  ->withRoute(array_merge($request->route(), $biz));
                
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
                    Log::warning('SEC: Failed to reset param cache: ' . $e->getMessage());
                }
            }

            // 调试：参数注入后的请求快照（只摘关键字段） - 强化版
            $after = [
                'parent_id' => $request->param('parent_id'),
                'parent_id_get' => $request->get('parent_id'),
                'parent_id_post' => $request->post('parent_id'),
                'only_main' => $request->param('only_main'),
                'page' => $request->param('page'),
                'page_size' => $request->param('page_size'),
                'limit' => $request->param('limit'),
                'sub_category_id' => $request->param('sub_category_id'),
            ];
            Log::info('SEC: params injected DETAILED', [ 
                'm' => $methodId, 
                'after' => $after,
                'request_all_get' => $request->get(),
                'request_all_post' => $request->post()
            ]);
            
            // 额外调试：直接输出到日志，确保能看到
            Log::info('SEC: AFTER_INJECT_JSON: ' . json_encode($after, JSON_UNESCAPED_UNICODE));

            return $next($request);

        } catch (\Exception $e) {
            Log::error('API安全中间件异常: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => '安全验证异常']);
        }
    }

    /**
     * 驼峰转下划线，兼容参数名
     */
    private function snakeKeys($array)
    {
        if (!is_array($array)) {
            return $array;
        }
        
        $result = [];
        foreach ($array as $key => $value) {
            $snakeKey = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
            $result[$snakeKey] = is_array($value) ? $this->snakeKeys($value) : $value;
            // 保留原键名兼容
            if ($snakeKey !== $key) {
                $result[$key] = is_array($value) ? $this->snakeKeys($value) : $value;
            }
        }
        return $result;
    }
}
