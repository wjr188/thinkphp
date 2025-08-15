<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * RSA密钥对生成命令
 * php think rsa:generate
 */
class GenerateRsaKeys extends Command
{
    protected function configure()
    {
        $this->setName('rsa:generate')
            ->setDescription('生成RSA公私钥对');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('正在生成RSA密钥对...');

        if (!function_exists('openssl_pkey_new')) {
            $output->error('未检测到 OpenSSL 扩展，请在 php.ini 启用 extension=openssl');
            return 1; // failure
        }

        // 生成RSA密钥对
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // 创建密钥资源
        $res = @openssl_pkey_new($config);
        if (!$res) {
            $output->error('RSA密钥生成失败');

            // 输出 OpenSSL 详细错误
            while ($err = openssl_error_string()) {
                $output->writeln('OpenSSL: ' . $err);
            }

            // Windows 常见问题：未设置 OPENSSL_CONF
            if (stripos(PHP_OS, 'WIN') === 0) {
                $conf = getenv('OPENSSL_CONF') ?: '(未设置)';
                $output->writeln('当前 OPENSSL_CONF: ' . $conf);
                $phpDir = dirname(PHP_BINARY);
                $guess1 = $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
                $guess2 = $phpDir . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
                $output->writeln('可尝试设置（示例）:');
                $output->writeln('  setx OPENSSL_CONF "' . $guess1 . '"');
                $output->writeln('或确保存在有效的 openssl.cnf 并指向它');
            }

            return 1; // failure
        }

        // 导出私钥
        openssl_pkey_export($res, $privateKey);

        // 导出公钥
        $details = openssl_pkey_get_details($res);
        $publicKey = $details['key'] ?? '';

        // 创建密钥存储目录
        $keyDir = root_path('keys');
        if (!is_dir($keyDir)) {
            @mkdir($keyDir, 0755, true);
        }
        $keyDir = rtrim($keyDir, "\\/") . DIRECTORY_SEPARATOR;

        // 保存私钥
        $privateKeyFile = $keyDir . 'rsa_private_key.pem';
        file_put_contents($privateKeyFile, $privateKey);
        // Windows 无 chmod 语义，这里忽略权限设定

        // 保存公钥
        $publicKeyFile = $keyDir . 'rsa_public_key.pem';
        file_put_contents($publicKeyFile, $publicKey);

        $output->writeln('RSA密钥对生成成功！');
        $output->writeln('私钥路径: ' . $privateKeyFile);
        $output->writeln('公钥路径: ' . $publicKeyFile);
        
        $output->writeln('');
        $output->writeln('公钥内容（用于前端）:');
        $output->writeln($publicKey);
        
        $output->writeln('');
        $output->writeln('请将公钥更新到前端SDK中，私钥路径更新到中间件配置中。');

        return 0; // success
    }
}
