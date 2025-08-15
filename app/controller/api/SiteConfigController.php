<?php
namespace app\controller\api;

use think\facade\Db;
use think\facade\Log;
use think\Request;

class SiteConfigController
{
    /**
     * 获取所有配置
     */
    public function getAll()
    {
        try {
            Log::info('SiteConfigController.getAll called');

            $configs = Db::name('site_config')
                ->column('config_value', 'config_key');

            Log::info('SiteConfigController.getAll result: ' . json_encode($configs, JSON_UNESCAPED_UNICODE));

            return apiReturn($configs, '系统配置获取成功');
        } catch (\Exception $e) {
            Log::error('SiteConfigController.getAll error: ' . $e->getMessage());
            return apiReturn([], '系统错误', 500);
        }
    }

    /**
     * 更新配置（批量）
     */
    public function updateAll(Request $request)
{
    try {
        $data = $request->post();
        if (empty($data) || !is_array($data)) {
            return apiReturn([], '无效的参数，必须是非空对象', 400);
        }

        Db::startTrans();

        $updatedKeys = [];
        foreach ($data as $key => $value) {
            // 统一转字符串；对象/数组用 JSON 存
            $val = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);

            // 幂等：有则更新，无则插入（依赖 site_config.config_key 唯一索引）
            $sql = "INSERT INTO site_config (config_key, config_value, description, created_at, updated_at)
                    VALUES (:k, :v, '', NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                      config_value = VALUES(config_value),
                      updated_at   = VALUES(updated_at)";
            Db::execute($sql, ['k' => $key, 'v' => $val]);

            $updatedKeys[] = $key;
        }

        // 如果动了加群/合作相关配置，顺带刷新版本号（前端可用 ?v=version 强制更新）
        $touchKeys = ['group_enable','group_official_url','group_business_url','group_ad_url','tg_download_url','groups_json'];
        if (count(array_intersect($updatedKeys, $touchKeys)) > 0) {
            $ver = date('YmdHis');
            $sql = "INSERT INTO site_config (config_key, config_value, description, created_at, updated_at)
                    VALUES ('groups_version', :v, '群配置版本号', NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                      config_value = VALUES(config_value),
                      updated_at   = VALUES(updated_at)";
            Db::execute($sql, ['v' => $ver]);
            $updatedKeys[] = 'groups_version';
        }

        Db::commit();
        return apiReturn(['updated_keys' => $updatedKeys], '配置更新成功');
    } catch (\Throwable $e) {
        Db::rollback();
        Log::error('SiteConfigController.updateAll error: ' . $e->getMessage());
        return apiReturn([], '系统错误：' . $e->getMessage(), 500);
    }
}
public function getGroupLinks()
{
    try {
        // 一次性把所有可能用到的键取出来
        $keys = [
            'group_enable', 'groups_json', 'groups_version',
            'group_official_url', 'group_business_url', 'group_ad_url', 'tg_download_url',
        ];
        $cfg = Db::name('site_config')->whereIn('config_key', $keys)
                ->column('config_value', 'config_key');

        $enabled = (($cfg['group_enable'] ?? '1') === '1');
        $version = $cfg['groups_version'] ?? date('YmdHis');

        if (!$enabled) {
            return apiReturn([
                'enabled'  => 0,
                'version'  => $version,
                'sections' => []
            ], 'group links disabled');
        }

        // ✅ 方案A：优先使用后台自定义 JSON
        if (!empty($cfg['groups_json'])) {
            $json = json_decode($cfg['groups_json'], true);
            if (json_last_error() === JSON_ERROR_NONE
                && isset($json['sections']) && is_array($json['sections'])) {
                return apiReturn([
                    'enabled'  => 1,
                    'version'  => $version,
                    'sections' => $json['sections'],
                ], 'ok');
            }
        }

        // ✅ 方案B：兜底，用单链接拼装（只返回 URL，其他文案固定）
        $icon = '/icons/telegram.svg';
        $sections = [];

        if (!empty($cfg['group_official_url'])) {
            $sections[] = [
                'title'    => '官方交流群',
                'subtitle' => '一起看片一起分享心得',
                'items'    => [[
                    'icon'     => $icon,
                    'title'    => '官方福利群',
                    'subtitle' => '官方福利群',
                    'btnText'  => '立即加入',
                    'action'   => ['type' => 'link', 'value' => (string)$cfg['group_official_url']],
                ]],
            ];
        }

        if (!empty($cfg['group_business_url'])) {
            $sections[] = [
                'title' => '推广商务合作',
                'items' => [[
                    'icon'     => $icon,
                    'title'    => '商务合作',
                    'subtitle' => '推广商务合作洽谈',
                    'btnText'  => '立即联系',
                    'action'   => ['type' => 'link', 'value' => (string)$cfg['group_business_url']],
                ]],
            ];
        }

        if (!empty($cfg['group_ad_url'])) {
            $sections[] = [
                'title' => '广告商务合作',
                'items' => [[
                    'icon'     => $icon,
                    'title'    => '广告合作',
                    'subtitle' => '广告商务合作洽谈',
                    'btnText'  => '立即联系',
                    'action'   => ['type' => 'link', 'value' => (string)$cfg['group_ad_url']],
                ]],
            ];
        }

        // TG 下载工具始终给一个（没有就用默认）
        $sections[] = [
            'title' => '下载工具',
            'items' => [[
                'icon'     => $icon,
                'title'    => 'TG',
                'subtitle' => 'tg聊天 地址 https://telegram.org',
                'btnText'  => '立即下载',
                'action'   => ['type' => 'link', 'value' => (string)($cfg['tg_download_url'] ?? 'https://telegram.org')],
            ]],
        ];

        return apiReturn([
            'enabled'  => 1,
            'version'  => $version,
            'sections' => $sections,
        ], 'ok');

    } catch (\Throwable $e) {
        Log::error('SiteConfigController.getGroupLinks error: ' . $e->getMessage());
        return apiReturn([
            'enabled'  => 0,
            'version'  => date('YmdHis'),
            'sections' => []
        ], 'server error', 500);
    }
}

}
