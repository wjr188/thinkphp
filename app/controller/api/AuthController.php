<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use think\Request;
use think\facade\Db;

// ====== JWT 简易实现 ======
function create_jwt($payload, $key = 'your_secret_key') {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [];
    $segments[] = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    $segments[] = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $signing_input = implode('.', $segments);
    $signature = hash_hmac('sha256', $signing_input, $key, true);
    $segments[] = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    return implode('.', $segments);
}

function verify_jwt($jwt, $key = 'your_secret_key') {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    list($header64, $payload64, $signature64) = $parts;
    $signing_input = $header64 . '.' . $payload64;
    $signature = base64_decode(strtr($signature64, '-_', '+/'));
    $expected = hash_hmac('sha256', $signing_input, $key, true);
    if (!hash_equals($expected, $signature)) return false;
    $payload = json_decode(base64_decode(strtr($payload64, '-_', '+/')), true);
    if (isset($payload['exp']) && $payload['exp'] < time()) return false;
    return $payload;
}
// ====== JWT 简易实现 END ======

class AuthController extends BaseController
{
    // 登录
    public function login(Request $request) {
        // 兼容 account 和 username
        $account = $request->post('account', $request->post('username', ''));
        $password = $request->post('password', '');

        if ($account === 'admin' && $password === '123456') {
            // 获取所有权限点
            $allPermissions = [];
            foreach ($this->getAllPermissions()->getData()['data'] as $group) {
                if (isset($group['children'])) {
                    foreach ($group['children'] as $item) {
                        $allPermissions[] = $item['value'];
                    }
                } elseif (isset($group['value'])) {
                    $allPermissions[] = $group['value'];
                }
            }
            // 生成token
            $payload = [
                'userId' => 1,
                'exp' => time() + 86400
            ];
            $accessToken = create_jwt($payload);
            return json([
                'code' => 0,
                'msg'  => '登录成功',
                'data' => [
                    'accessToken'  => $accessToken,
                    'refreshToken' => 'test_refresh_token',
                    'userInfo'     => [
                        'id'       => 1,
                        'account'  => 'admin',
                        'nickname' => '超级管理员',
                        'role'     => 'admin',
                        'status'   => '正常',
                        'last_login_time' => null,
                        'permissions' => $allPermissions // 这里给全量权限
                    ]
                ]
            ]);
        }

        $user = Db::name('admin_users')->where('username', $account)->find();
        if (!$user || !password_verify($password, $user['password'])) {
            return json(['code' => 1, 'msg' => '账号或密码错误']);
        }
        // 获取所有权限点
        $allPermissions = [];
        foreach ($this->getAllPermissions()->getData()['data'] as $group) {
            if (isset($group['children'])) {
                foreach ($group['children'] as $item) {
                    $allPermissions[] = $item['value'];
                }
            } elseif (isset($group['value'])) {
                $allPermissions[] = $group['value'];
            }
        }
        // 生成token
        $payload = [
            'userId' => $user['id'],
            'exp' => time() + 86400
        ];
        $accessToken = create_jwt($payload);

        return json([
            'code' => 0,
            'msg'  => '登录成功',
            'data' => [
                'accessToken'  => $accessToken,
                'refreshToken' => 'test_refresh_token',
                'userInfo'     => [
                    'id'       => $user['id'],
                    'account'  => $user['username'],
                    'nickname' => $user['nickname'],
                    'role'     => $user['role'],
                    'status'   => $user['status'] == 1 ? '正常' : '禁用',
                    'last_login_time' => $user['last_login_time'],
                    'permissions' => json_decode($user['permissions'] ?? '[]', true)
                ]
            ]
        ]);
    }

    // 获取账号列表
    public function list(Request $request) {
        $page = max(1, intval($request->get('page', 1)));
        $pageSize = max(1, intval($request->get('pageSize', 10)));
        $keyword = trim($request->get('keyword', ''));
        $query = Db::name('admin_users');
        if ($keyword) {
            $query->whereLike('username|nickname', "%$keyword%");
        }
        $total = $query->count();
        $list = $query->order('id desc')->page($page, $pageSize)->select()->toArray();
        foreach ($list as &$item) {
            $item['account'] = $item['username'];
            $item['status'] = $item['status'] == 1 ? '正常' : '禁用';
            $item['permissions'] = json_decode($item['permissions'] ?? '[]', true);
        }
        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'list' => $list,
                'total' => $total
            ]
        ]);
    }

    // 新建账号
    public function create(Request $request) {
        $data = $request->post();

        // 自动补全一级权限
        $all = $data['permissions'] ?? [];
        foreach ($all as $perm) {
            if (strpos($perm, ':') !== false) {
                $main = explode(':', $perm)[0];
                if (!in_array($main, $all)) {
                    $all[] = $main;
                }
            }
        }

        $insert = [
            'username' => $data['account'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'nickname' => $data['nickname'],
            'role'     => $data['role'],
            'status'   => 1,
            'permissions' => json_encode($all),
            'last_login_time' => null
        ];
        if (Db::name('admin_users')->where('username', $data['account'])->find()) {
            return json(['code' => 1, 'msg' => '账号已存在']);
        }
        Db::name('admin_users')->insert($insert);
        return json(['code' => 0, 'msg' => '创建成功']);
    }

    // 编辑账号
    public function update(Request $request) {
        $data = $request->post();

        // 自动补全一级权限
        $all = $data['permissions'] ?? [];
        foreach ($all as $perm) {
            if (strpos($perm, ':') !== false) {
                $main = explode(':', $perm)[0];
                if (!in_array($main, $all)) {
                    $all[] = $main;
                }
            }
        }

        $update = [
            'nickname' => $data['nickname'],
            'role'     => $data['role'] ?? 'viewer',
            'status'   => isset($data['status']) ? ($data['status'] == '正常' ? 1 : 0) : 1,
            'permissions' => json_encode($all)
        ];
        Db::name('admin_users')->where('id', $data['id'])->update($update);
        return json(['code' => 0, 'msg' => '编辑成功']);
    }

    // 删除账号
    public function delete(Request $request) {
        $id = $request->post('id');
        Db::name('admin_users')->where('id', $id)->delete();
        return json(['code' => 0, 'msg' => '删除成功']);
    }

    // 重置账号密码
    public function resetPassword(Request $request) {
        $id = $request->post('id');
        $password = $request->post('password');
        Db::name('admin_users')->where('id', $id)->update([
            'password' => password_hash($password, PASSWORD_DEFAULT)
        ]);
        return json(['code' => 0, 'msg' => '重置密码成功']);
    }

    // 修改自己密码
    public function changePassword(Request $request) {
        $id = $request->userId ?? 0; // 你可以根据登录token获取
        $old = $request->post('old_password');
        $new = $request->post('new_password');
        $user = Db::name('admin_users')->where('id', $id)->find();
        if (!$user || !password_verify($old, $user['password'])) {
            return json(['code' => 1, 'msg' => '原密码错误']);
        }
        Db::name('admin_users')->where('id', $id)->update([
            'password' => password_hash($new, PASSWORD_DEFAULT)
        ]);
        return json(['code' => 0, 'msg' => '修改密码成功']);
    }

    // 获取所有权限点（菜单/按钮权限树）
    public function getAllPermissions() {
        $data = [
            [
                'label' => '会员与支付',
                'value' => 'member',
                'children' => [
                    ['label' => '会员管理', 'value' => 'member:manage'],
                    ['label' => 'VIP配置', 'value' => 'vip:config'],
                    ['label' => '充值记录', 'value' => 'recharge:record'],
                    ['label' => '金币管理', 'value' => 'coin:manage'],
                    ['label' => '支付通道列表', 'value' => 'pay:channelList'],
                ]
            ],
            [
                'label' => '渠道总管理',
                'value' => 'channel',
                'children' => [
                    ['label' => '渠道汇总统计', 'value' => 'channel:summary'],
                    ['label' => '渠道管理', 'value' => 'channel:manage'],
                ]
            ],
            [
                'label' => '系统设置',
                'value' => 'system',
                'children' => [
                    ['label' => '站点信息', 'value' => 'system:siteInfo'],
                    ['label' => '权限设置', 'value' => 'system:auth',
                        'children' => [
                            ['label' => '新建账号', 'value' => 'system:auth:create'],
                            ['label' => '编辑账号', 'value' => 'system:auth:update'],
                            ['label' => '删除账号', 'value' => 'system:auth:delete'],
                        ]
                    ],
                    ['label' => '配置项', 'value' => 'system:config'],
                ]
            ],
            [
                'label' => '浏览记录',
                'value' => 'browse:record',
            ],
            [
                'label' => '博主',
                'value' => 'blogger',
                'children' => [
                    ['label' => '博主管理', 'value' => 'blogger:manage'],
                    ['label' => '内容管理', 'value' => 'blogger:content'],
                    ['label' => '博主分组管理', 'value' => 'blogger:group'],
                    ['label' => '标签管理', 'value' => 'blogger:tag'],
                ]
            ],
            [
                'label' => '广告管理',
                'value' => 'ad',
                'children' => [
                    ['label' => '轮播广告', 'value' => 'ad:carousel'],
                    ['label' => '插屏广告', 'value' => 'ad:insert'],
                    ['label' => '跳转广告', 'value' => 'ad:jump'],
                ]
            ],
            [
                'label' => '文字小说',
                'value' => 'textnovel',
                'children' => [
                    ['label' => '文字小说管理', 'value' => 'textnovel:manage'],
                    ['label' => '文字小说分类', 'value' => 'textnovel:category'],
                    ['label' => '文字小说标签', 'value' => 'textnovel:tag'],
                    ['label' => '文字推荐', 'value' => 'textnovel:recommend'],
                ]
            ],
            [
                'label' => '有声小说',
                'value' => 'audio',
                'children' => [
                    ['label' => '有声小说管理', 'value' => 'audio:manage'],
                    ['label' => '有声小说分类', 'value' => 'audio:category'],
                    ['label' => '有声小说标签', 'value' => 'audio:tag'],
                    ['label' => '有声小说推荐', 'value' => 'audio:recommend'],
                ]
            ],
            [
                'label' => '暗网',
                'value' => 'darknet',
                'children' => [
                    ['label' => '暗网管理', 'value' => 'darknet:manage'],
                    ['label' => '暗网分类', 'value' => 'darknet:category'],
                    ['label' => '暗网标签', 'value' => 'darknet:tag'],
                    ['label' => '暗网推荐', 'value' => 'darknet:recommend'],
                ]
            ],
            [
                'label' => '漫画',
                'value' => 'comic',
                'children' => [
                    ['label' => '国漫管理', 'value' => 'comic:manage'],
                    ['label' => '国漫分类', 'value' => 'comic:category'],
                    ['label' => '国漫标签', 'value' => 'comic:tag'],
                    ['label' => '漫画推荐', 'value' => 'comic:recommend'],
                ]
            ],
            [
                'label' => '长视频',
                'value' => 'video',
                'children' => [
                    ['label' => '视频管理', 'value' => 'video:manage'],
                    ['label' => '视频分类', 'value' => 'video:category'],
                    ['label' => '视频标签', 'value' => 'video:tag'],
                    ['label' => '首页推荐', 'value' => 'video:recommend'],
                ]
            ],
            [
                'label' => '抖音',
                'value' => 'douyin',
                'children' => [
                    ['label' => '抖音管理', 'value' => 'douyin:manage'],
                    ['label' => '抖音分类', 'value' => 'douyin:category'],
                    ['label' => '抖音标签', 'value' => 'douyin:tag'],
                ]
            ],
            [
                'label' => '动漫',
                'value' => 'anime',
                'children' => [
                    ['label' => '动漫管理', 'value' => 'anime:manage'],
                    ['label' => '动漫分类', 'value' => 'anime:category'],
                    ['label' => '动漫标签', 'value' => 'anime:tag'],
                    ['label' => '动漫推荐', 'value' => 'anime:recommend'],
                ]
            ],
        ];
        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => $data
        ]);
    }

    // 获取用户信息
    public function info(Request $request) {
        $auth = $request->header('Authorization');
        if (!$auth) {
            return json(['code' => 401, 'msg' => '未登录']);
        }
        $token = str_replace('Bearer ', '', $auth);
        $payload = verify_jwt($token);
        if (!$payload) {
            return json(['code' => 401, 'msg' => 'token无效或已过期']);
        }
        $userId = $payload['userId'];
        if ($userId === 1) {
            // 超级管理员
            $allPermissions = [];
            foreach ($this->getAllPermissions()->getData()['data'] as $group) {
                if (isset($group['children'])) {
                    foreach ($group['children'] as $item) {
                        $allPermissions[] = $item['value'];
                    }
                } elseif (isset($group['value'])) {
                    $allPermissions[] = $group['value'];
                }
            }
            return json([
                'code' => 0,
                'msg'  => 'ok',
                'data' => [
                    'id' => 1,
                    'account' => 'admin',
                    'nickname' => '超级管理员',
                    'role' => 'admin',
                    'status' => '正常',
                    'last_login_time' => null,
                    'permissions' => $allPermissions
                ]
            ]);
        } else {
            $user = Db::name('admin_users')->where('id', $userId)->find();
            if (!$user) {
                return json(['code' => 401, 'msg' => '账号已被删除或不存在']);
            }
            if ($user['status'] != 1) {
                return json(['code' => 401, 'msg' => '账号已被禁用']);
            }
            return json([
                'code' => 0,
                'msg'  => 'ok',
                'data' => [
                    'id' => $user['id'],
                    'account' => $user['username'],
                    'nickname' => $user['nickname'],
                    'role' => $user['role'],
                    'status' => $user['status'] == 1 ? '正常' : '禁用',
                    'last_login_time' => $user['last_login_time'],
                    'permissions' => json_decode($user['permissions'] ?? '[]', true)
                ]
            ]);
        }
    }

    // 退出
    public function logout(\think\Request $request)
    {
        // 这里只需返回成功，前端会自行清理token
        return json(['code' => 0, 'msg' => '退出成功']);
    }
}